<?php
// backend/api/email/autoconfig.php
//
// Servereinstellungen eines Postfachs automatisch ermitteln — so wie es
// Thunderbird beim Anlegen eines Kontos macht. Reihenfolge der Quellen:
//
//   1. autoconfig.<domain>/mail/config-v1.1.xml           (Anbieter-eigene Datei)
//   2. <domain>/.well-known/autoconfig/mail/config-v1.1.xml
//   3. ISPDB von Mozilla (autoconfig.thunderbird.net)      (bekannte Anbieter)
//   4. MX-Record → Domain des Mailanbieters → dessen autoconfig/ISPDB
//   5. SRV-Records nach RFC 6186 (_imaps._tcp, _submission._tcp, ...)
//   6. Raten: imap./mail./smtp.<domain> auf den üblichen Ports anklopfen
//
// Ergebnis sind Host, Port und Verschlüsselung für IMAP und SMTP in genau
// der Form, die ImapClient/SmtpClient erwarten (ssl | starttls | none).

const EMAIL_AUTOCONFIG_HTTP_TIMEOUT = 6;   // Sekunden je HTTP-Abfrage
const EMAIL_AUTOCONFIG_PROBE_TIMEOUT = 3;  // Sekunden je Port-Test beim Raten

/**
 * HTTP-GET mit kurzem Timeout; liefert den Body oder null
 */
function _autoconfigHttpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => EMAIL_AUTOCONFIG_HTTP_TIMEOUT,
        CURLOPT_TIMEOUT        => EMAIL_AUTOCONFIG_HTTP_TIMEOUT,
        CURLOPT_USERAGENT      => 'OpensourceERP-Autoconfig/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200 || trim($body) === '') return null;
    return $body;
}

/**
 * socketType aus der Autoconfig-XML auf unsere Werte abbilden
 */
function _autoconfigEncryption(string $socketType): string {
    $socketType = strtoupper(trim($socketType));
    if ($socketType === 'SSL') return 'ssl';
    if ($socketType === 'STARTTLS') return 'starttls';
    return 'none';
}

/**
 * Platzhalter in Benutzernamen der Autoconfig-XML ersetzen
 */
function _autoconfigUsername(string $template, string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return str_replace(
        ['%EMAILADDRESS%', '%EMAILLOCALPART%', '%EMAILDOMAIN%'],
        [$email, $local, $domain],
        trim($template)
    );
}

/**
 * Autoconfig-XML (Mozilla-Format config-v1.1) auswerten
 *
 * Nimmt pro Richtung den ersten Server mit SSL, sonst STARTTLS, sonst
 * den ersten überhaupt. Liefert null, wenn kein IMAP-Server drinsteht.
 */
function _autoconfigParseXml(string $xml, string $email): ?array {
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_clear_errors();
    if (!$doc || !isset($doc->emailProvider)) return null;

    $provider = $doc->emailProvider;
    $rank = ['ssl' => 0, 'starttls' => 1, 'none' => 2];

    $pick = function($servers, string $type) use ($rank, $email): ?array {
        $best = null;
        foreach ($servers as $srv) {
            if ((string)$srv['type'] !== $type) continue;
            $host = trim((string)$srv->hostname);
            if ($host === '') continue;
            $entry = [
                'host'       => $host,
                'port'       => (int)$srv->port,
                'encryption' => _autoconfigEncryption((string)$srv->socketType),
                'username'   => _autoconfigUsername((string)$srv->username, $email),
            ];
            if ($best === null || $rank[$entry['encryption']] < $rank[$best['encryption']]) {
                $best = $entry;
            }
        }
        return $best;
    };

    $imap = $pick($provider->incomingServer, 'imap');
    $smtp = $pick($provider->outgoingServer, 'smtp');
    if (!$imap) return null;

    return [
        'provider' => trim((string)$provider->displayName),
        'imap'     => $imap,
        'smtp'     => $smtp,
    ];
}

/**
 * Die drei XML-Quellen einer Domain der Reihe nach abfragen
 */
function _autoconfigFromDomain(string $domain, string $email, string $sourcePrefix = ''): ?array {
    $urls = [
        'autoconfig' => 'https://autoconfig.' . $domain . '/mail/config-v1.1.xml?emailaddress=' . rawurlencode($email),
        'well-known' => 'https://' . $domain . '/.well-known/autoconfig/mail/config-v1.1.xml',
        'ispdb'      => 'https://autoconfig.thunderbird.net/v1.1/' . rawurlencode($domain),
    ];
    foreach ($urls as $source => $url) {
        $xml = _autoconfigHttpGet($url);
        if ($xml === null) continue;
        $result = _autoconfigParseXml($xml, $email);
        if ($result) {
            $result['source'] = $sourcePrefix . $source;
            return $result;
        }
    }
    return null;
}

/**
 * Ersten SRV-Record (nach Priorität) eines Dienstes lesen
 */
function _autoconfigSrv(string $service, string $domain): ?array {
    $records = @dns_get_record('_' . $service . '._tcp.' . $domain, DNS_SRV) ?: [];
    $records = array_filter($records, fn($r) => !empty($r['target']) && $r['target'] !== '.');
    if (!$records) return null;
    usort($records, fn($a, $b) => ($a['pri'] <=> $b['pri']) ?: ($b['weight'] <=> $a['weight']));
    $r = $records[0];
    return ['host' => $r['target'], 'port' => (int)$r['port']];
}

/**
 * Servereinstellungen aus SRV-Records nach RFC 6186
 */
function _autoconfigFromSrv(string $domain, string $email): ?array {
    $imap = null;
    if ($r = _autoconfigSrv('imaps', $domain)) {
        $imap = $r + ['encryption' => 'ssl'];
    } elseif ($r = _autoconfigSrv('imap', $domain)) {
        $imap = $r + ['encryption' => 'starttls'];
    }
    if (!$imap) return null;

    $smtp = null;
    if ($r = _autoconfigSrv('submissions', $domain)) {
        $smtp = $r + ['encryption' => 'ssl'];
    } elseif ($r = _autoconfigSrv('submission', $domain)) {
        $smtp = $r + ['encryption' => $r['port'] === 465 ? 'ssl' : 'starttls'];
    }

    $imap['username'] = $email;
    if ($smtp) $smtp['username'] = $email;
    return ['provider' => '', 'imap' => $imap, 'smtp' => $smtp, 'source' => 'srv'];
}

/**
 * Registrierbare Domain eines Hostnamens (mail.example.co.uk → example.co.uk)
 *
 * Ohne Public-Suffix-Liste: die üblichen zweistufigen Endungen werden
 * berücksichtigt, alles andere auf die letzten zwei Labels gekürzt.
 */
function _autoconfigBaseDomain(string $host): string {
    $labels = explode('.', strtolower(rtrim($host, '.')));
    $n = count($labels);
    if ($n <= 2) return implode('.', $labels);
    $secondLevel = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ltd', 'plc', 'me'];
    if (in_array($labels[$n - 2], $secondLevel, true) && strlen($labels[$n - 1]) === 2) {
        return implode('.', array_slice($labels, -3));
    }
    return implode('.', array_slice($labels, -2));
}

/**
 * Domain des Mailanbieters über den MX-Record finden und dort nachsehen
 */
function _autoconfigFromMx(string $domain, string $email): ?array {
    $records = @dns_get_record($domain, DNS_MX) ?: [];
    if (!$records) return null;
    usort($records, fn($a, $b) => $a['pri'] <=> $b['pri']);

    $tried = [];
    foreach ($records as $mx) {
        $mxDomain = _autoconfigBaseDomain($mx['target'] ?? '');
        if ($mxDomain === '' || $mxDomain === $domain || isset($tried[$mxDomain])) continue;
        $tried[$mxDomain] = true;
        if ($result = _autoconfigFromDomain($mxDomain, $email, 'mx-')) {
            return $result;
        }
    }
    return null;
}

/**
 * Prüft, ob auf host:port ein Dienst antwortet (reiner TCP-Verbindungstest)
 */
function _autoconfigPortOpen(string $host, int $port, bool $ssl): bool {
    $address = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client($address, $errno, $errstr, EMAIL_AUTOCONFIG_PROBE_TIMEOUT,
        STREAM_CLIENT_CONNECT, $context);
    if (!$fp) return false;
    fclose($fp);
    return true;
}

/**
 * Letzter Ausweg: gängige Hostnamen und Ports durchprobieren
 */
function _autoconfigGuess(string $domain, string $email): ?array {
    $imap = null;
    foreach (['imap.' . $domain, 'mail.' . $domain, $domain] as $host) {
        if (!@dns_get_record($host, DNS_A) && !@dns_get_record($host, DNS_AAAA)) continue;
        if (_autoconfigPortOpen($host, 993, true)) {
            $imap = ['host' => $host, 'port' => 993, 'encryption' => 'ssl'];
            break;
        }
        if (_autoconfigPortOpen($host, 143, false)) {
            $imap = ['host' => $host, 'port' => 143, 'encryption' => 'starttls'];
            break;
        }
    }
    if (!$imap) return null;

    $smtp = null;
    foreach (['smtp.' . $domain, 'mail.' . $domain, $domain] as $host) {
        if (!@dns_get_record($host, DNS_A) && !@dns_get_record($host, DNS_AAAA)) continue;
        if (_autoconfigPortOpen($host, 465, true)) {
            $smtp = ['host' => $host, 'port' => 465, 'encryption' => 'ssl'];
            break;
        }
        if (_autoconfigPortOpen($host, 587, false)) {
            $smtp = ['host' => $host, 'port' => 587, 'encryption' => 'starttls'];
            break;
        }
    }

    $imap['username'] = $email;
    if ($smtp) $smtp['username'] = $email;
    return ['provider' => '', 'imap' => $imap, 'smtp' => $smtp, 'source' => 'guess'];
}

/**
 * Servereinstellungen eines Postfachs automatisch ermitteln (wie Thunderbird)
 *
 * Gibt IMAP- und SMTP-Server samt Port, Verschlüsselung und Benutzername
 * zurück; gespeichert wird nichts — das macht die Firmenkonfiguration.
 *
 * @param string $data['email'] E-Mail-Adresse des Postfachs
 * @testdata {"email": "info@example.com"}
 */
function emailAutoconfig($data) {
    $email = strtolower(trim($data['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        resultInfo(false, 'EMAIL_INVALID', 'Bitte eine gültige E-Mail-Adresse angeben');
        return;
    }
    $domain = substr($email, strrpos($email, '@') + 1);

    $result = _autoconfigFromDomain($domain, $email)
        ?? _autoconfigFromMx($domain, $email)
        ?? _autoconfigFromSrv($domain, $email)
        ?? _autoconfigGuess($domain, $email);

    if (!$result) {
        resultInfo(false, 'EMAIL_AUTOCONFIG_NOT_FOUND',
            'Für ' . $domain . ' wurden keine Servereinstellungen gefunden. Bitte IMAP- und SMTP-Server manuell eintragen.');
        return;
    }

    resultInfo(true, '', [
        'email'    => $email,
        'domain'   => $domain,
        'provider' => $result['provider'],
        'source'   => $result['source'],
        'imap'     => $result['imap'],
        'smtp'     => $result['smtp'],
    ]);
}
