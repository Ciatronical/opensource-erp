<?php
// backend/api/portal/lib/mail.php
//
// Mailversand des Portals über die SMTP-Klasse von OpensourceERP.
//
// Die Zugangsdaten stehen in denselben email_*-Einstellungen wie im übrigen
// ERP — das Portal bekommt keine zweite SMTP-Konfiguration. Absender ist die
// Firmenadresse; nur Empfänger und Text sind portal-eigen.
//
// Die Vorlagen liegen unter templates/ als PHP-Dateien, damit sie sich ohne
// Codeänderung anpassen lassen. Erweiterungen melden ihr eigenes Verzeichnis
// an (portalMailTemplateDirAdd) — der Shop seine Rechnungs- und
// Widerrufsmails.

/**
 * Baut den SMTP-Client aus den Firmeneinstellungen
 *
 * Bewusst nicht über _createSmtpClient() aus email/email.php: diese Datei
 * bringt die Mail-Aktionen des ERP mit, die im öffentlichen Zugang nichts zu
 * suchen haben. Gelesen werden dieselben Einstellungen.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array{client: SmtpClient, from: string, from_name: string}
 * @throws ApiError EMAIL_NOT_CONFIGURED
 */
function portalMailer($db): array {
    $zeilen = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'email\\_%' ESCAPE '\\'", []);
    $cfg = [];
    foreach ($zeilen as $zeile) {
        $cfg[$zeile['key']] = $zeile['value'];
    }

    $host     = $cfg['email_smtp_host'] ?? '';
    $benutzer = $cfg['email_username']  ?? '';
    $kennwort = $cfg['email_password']  ?? '';

    if ('' === $host || '' === $benutzer || '' === $kennwort) {
        throw new ApiError(
            'EMAIL_NOT_CONFIGURED',
            'Der Mailversand ist nicht eingerichtet (Einstellungen -> E-Mail)'
        );
    }

    return [
        'client' => new SmtpClient(
            $host,
            (int)($cfg['email_smtp_port'] ?? 465),
            $cfg['email_smtp_encryption'] ?? 'ssl',
            $benutzer,
            $kennwort
        ),
        'from'      => $cfg['email_from']      ?? $benutzer,
        'from_name' => $cfg['email_from_name'] ?? '',
    ];
}

/**
 * Verzeichnisse mit Mailvorlagen — zuletzt angemeldete zuerst
 *
 * @param string|null $neu Verzeichnis, das dazukommt
 * @return string[]
 */
function portalMailTemplateDirs(?string $neu = null): array {
    static $verzeichnisse = [__DIR__.'/../templates'];
    if (null !== $neu && !in_array($neu, $verzeichnisse, true)) {
        array_unshift($verzeichnisse, $neu);
    }
    return $verzeichnisse;
}

/**
 * Meldet ein Vorlagenverzeichnis einer Erweiterung an
 *
 * @param string $verzeichnis Pfad
 * @return void
 */
function portalMailTemplateDirAdd(string $verzeichnis): void {
    portalMailTemplateDirs($verzeichnis);
}

/**
 * Füllt eine Mailvorlage
 *
 * @param string $vorlage Dateiname ohne Endung, etwa 'contact.de'
 * @param array $werte Variablen der Vorlage
 * @return string HTML
 * @throws ApiError MAIL_TEMPLATE_NOT_FOUND
 */
function portalMailTemplate(string $vorlage, array $werte): string {
    $datei = null;
    foreach (portalMailTemplateDirs() as $verzeichnis) {
        $kandidat = $verzeichnis.'/'.basename($vorlage).'.php';
        if (file_exists($kandidat)) {
            $datei = $kandidat;
            break;
        }
    }
    if (null === $datei) {
        throw new ApiError('MAIL_TEMPLATE_NOT_FOUND', 'Mailvorlage '.$vorlage.' fehlt');
    }

    extract($werte, EXTR_SKIP);
    ob_start();
    include $datei;
    return (string)ob_get_clean();
}

/**
 * Verschickt eine Anfrage aus dem Kontaktformular an den Betreiber
 *
 * Absender bleibt die Firmenadresse: sonst verwerfen die meisten Empfänger
 * die Mail, weil der Absender nicht zur versendenden Domain passt. Die
 * Adresse des Kunden steht im Text.
 *
 * Empfänger ist contact_mail_to der Webseite, sonst die Absenderadresse der
 * Firma.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite der Anfrage
 * @param array $daten name, email, phone, term (Nachricht)
 * @return bool true wenn versendet
 * @throws ApiError EMAIL_NOT_CONFIGURED, MISSING_MESSAGE, INVALID_EMAIL
 */
function portalSendContactMail($db, int $website, array $daten): bool {
    $nachricht = trim((string)($daten['term'] ?? ''));
    $absender  = trim((string)($daten['email'] ?? ''));

    if ('' === $nachricht) {
        throw new ApiError('MISSING_MESSAGE', 'Die Nachricht ist leer');
    }
    if (!filter_var($absender, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('INVALID_EMAIL', 'Die Absenderadresse ist unbrauchbar');
    }

    $mailer = portalMailer($db);
    $empfaenger = websiteValue($db, $website, 'contact_mail_to', $mailer['from']);

    $html = portalMailTemplate('contact.de', [
        'name'      => (string)($daten['name'] ?? ''),
        'email'     => $absender,
        'phone'     => (string)($daten['phone'] ?? ''),
        'nachricht' => $nachricht,
        'webseite'  => websiteValue($db, $website, 'name'),
    ]);

    try {
        $mailer['client']->send(
            $mailer['from'], $mailer['from_name'],
            [$empfaenger], 'Anfrage über das Kontaktformular', $html
        );
        return true;
    } catch (Exception $e) {
        writeLog('[PORTAL] Kontaktmail fehlgeschlagen: '.$e->getMessage(), true, DLOG_ERR);
        return false;
    }
}
