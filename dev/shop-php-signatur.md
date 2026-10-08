# Shop: PHP-Dateien des Pakets signiert an HugoCMS übertragen

Stand 2026-10-08. Status: **Entwurf** — betrifft OpensourceERP und HugoCMS
(`/home/worker/Projekte/hugocms-2026`, eigene Richtlinien: `CLAUDE.md` dort).
Nimmt E9 aus `dev/shop-hugocms-trennung.md` zurück, sobald umgesetzt.

## Ziel

„Shop-Benutzerschnittstelle installieren“ überträgt alle Dateien des Pakets,
auch Weiterleiter (`oserp-shop/static/shop-api/index.php`) und 404-Seite
(`oserp-shop/static/not_found.php`). Von Hand bleibt nur, was keine Übertragung
leisten kann: PHP für die Webseite einschalten, die 404 an `not_found.php`
geben, den öffentlichen Schlüssel einmal in HugoCMS eintragen.

## Warum eine Signatur

HugoCMS schreibt heute kein PHP (`ShopSync::ACCEPT`, `allowedPath()` →
`SHOP-FILETYPE-NOT-ALLOWED`). Wer den Schlüssel der Shop-Anbindung hat, soll
nicht beliebigen Code auf dem Webserver ausführen können. Mit Signatur reicht
dieser Schlüssel nicht mehr: PHP wird nur angenommen, wenn es von dem
OpensourceERP signiert ist, dessen öffentlichen Schlüssel ein Administrator in
HugoCMS eingetragen hat.

Verfahren: Ed25519 über die Sodium-Erweiterung von PHP
(`sodium_crypto_sign_detached`, `sodium_crypto_sign_verify_detached`) — Teil
von PHP, keine Composer-Abhängigkeit. Fehlt Sodium auf einem Hosting, bleibt
es beim heutigen Weg (von Hand).

## HugoCMS

### Mount-Datei, Sektion `[shop]`

| Schlüssel | Inhalt |
| --- | --- |
| `signing_key` | öffentlicher Ed25519-Schlüssel von OpensourceERP, Base64 (neu) |

Gesetzt nur über die Oberfläche durch einen Administrator
(`requireShopKeyAdmin`), nie über die Shop-Befehle — sonst könnte der
Schlüssel der Anbindung sich seinen eigenen Prüfschlüssel setzen. Bleibt beim
Schlüsselwechsel erhalten (`shopSectionRest()`).

### `ShopSync`

- Neue Konstante `SIGNED_PHP` mit genau den zwei Pfaden — **im Code, nicht
  in der Konfiguration**: welche PHP-Dateien es überhaupt geben darf,
  entscheidet HugoCMS, nicht die Lieferung.
- Konstruktor bekommt den öffentlichen Schlüssel (oder `null`).
- `allowedPath()`: `.php` nur für einen Pfad aus `SIGNED_PHP` und nur mit
  hinterlegtem Schlüssel; sonst wie heute `SHOP-FILETYPE-NOT-ALLOWED`.
- `manifest()`: Einträge für diese Pfade brauchen `signature` (Base64). Geprüft
  wird über die Nachricht `"hugocms-shop-php\n<pfad>\n<sha256>"` — Pfad und
  Prüfsumme gebunden, eine Signatur taugt nicht für eine andere Datei oder
  einen anderen Ort. Fehlt sie oder stimmt sie nicht:
  `SHOP-SIGNATURE-INVALID`. `upload()` prüft wie heute die Prüfsumme — damit
  ist auch der Inhalt gedeckt.
- Mount und FileService der Anbindung bekommen `php` zusätzlich zu `ACCEPT`;
  die Grenze zieht `allowedPath()`. Der Mount der Redakteure bleibt
  unverändert, dort schreibt niemand PHP.
- Löschen (Übernahme): wie heute nur, was die vorige Lieferung enthielt.

### `Connector`

- `cmdShopBuildStatus` meldet zusätzlich `signedPhp`: `paths` (die zwei Pfade)
  und `ready` (Schlüssel hinterlegt und Sodium vorhanden). OpensourceERP
  entscheidet daran, ob es die PHP-Dateien signiert mitschickt.
- Neue Befehle für Administratoren: öffentlichen Schlüssel setzen und
  entfernen (wie `shopkeycreate`/`shopkeydelete`), mit Prüfung auf gültiges
  Ed25519-Format (32 Bytes).
- Protokoll (`logger`): Schlüssel gesetzt/entfernt, signierte PHP-Datei
  übernommen, Signatur abgewiesen.

### Oberfläche

In den Einstellungen der Shop-Anbindung ein Feld „Signaturschlüssel von
OpensourceERP“ (einfügen, entfernen, Kurzanzeige). Texte in
`frontend/src/i18n/de.js` und `en.js`, Fehlercodes übersetzt der Client.

### Prüfen

`php -l` für jede geänderte Datei, `npm run --prefix frontend build`,
Wegwerf-Skript gegen den Autoloader: gültige Signatur angenommen; falscher
Pfad, falsche Prüfsumme, fehlende Signatur, fremder Schlüssel abgewiesen;
andere `.php` weiter abgewiesen.

## OpensourceERP

- **Schlüsselpaar** einmal je Installation, erzeugt mit einem
  Kommandozeilen-Werkzeug (`tools/shop-signing-key.php`). Der private
  Schlüssel liegt als Datei außerhalb des Docroots und außerhalb der
  Datenbank (Ort: Entscheidung S1). Der öffentliche Schlüssel steht in der
  Kanalkarte zum Kopieren.
- **Abgleich** (`shopHugoCmsSync`): meldet HugoCMS `signedPhp.ready`, gehen
  die beiden Dateien mit `signature` ins Verzeichnis. Sonst wie heute nicht.
- **Meldung** im Lauf: Die Prüfung über die Webseite
  (`shopWebsiteManualFilesCheck`) bleibt. Ist `signedPhp.ready` nicht gesetzt,
  zusätzlich der Hinweis, wie es einzurichten ist.
- **Dokumentation**: `dev/shop-betrieb.md`, `dev/shop-hugocms-trennung.md`
  (E9 abgelöst).

## Grenzen

- Wer den OSERP-Server übernimmt, kann signieren und damit PHP auf den
  Webserver bringen — der Shop wäre dann aber ohnehin verloren.
- PHP auf der Webseite und die 404-Weiterleitung im Webserver bleiben
  Einrichtung von Hand.

## Entscheidungen (offen)

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| S1 | Wo liegt der private Schlüssel? | Datei `backend/config/shop-signing.key` (wie `settings.ini` nicht im Repository, Rechte 0600); der Pfad ist fest, kein Eintrag in `settings.ini` nötig |
| S2 | Ein Schlüsselpaar je Installation oder je Mandant? | je Installation — ein OSERP-Server, eine Identität |
| S3 | E9 zurücknehmen? | ja, mit diesem Verfahren |
