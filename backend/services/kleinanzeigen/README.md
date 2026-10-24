# Kleinanzeigen-Dienst (Headless-Chrome als Pseudo-API)

Kleinanzeigen.de hat keine Schnittstelle. Dieser Dienst steuert einen echten
Chrome über das DevTools-Protokoll (`nodriver`, keine WebDriver-Spuren) mit dem
**eigenen Konto des Betreibers** und bietet OpensourceERP eine kleine JSON-API:
anmelden, Anzeigen einstellen/ändern/löschen/verlängern, „Meine Anzeigen“
lesen, Nachrichten holen und beantworten.

Die Abläufe folgen dem Stand der Webseite im Oktober 2026 (Anzeigenformular `p-anzeige-aufgeben-schritt2.html`, Paketwahl
„Basis Paket“, Löschen über `m-anzeigen-loeschen.json`, Anzeigenliste über
`m-meine-anzeigen-verwalten.json`) — derselbe Weg, den das Open-Source-Projekt
[kleinanzeigen-bot](https://github.com/Second-Hand-Friends/kleinanzeigen-bot)
dokumentiert. Ändert Kleinanzeigen die Seite, helfen `/debug/page` und
`/debug/screenshot` beim Nachziehen der Selektoren.

> **Hinweis:** Automatisiertes Einstellen widerspricht den Nutzungsbedingungen
> von Kleinanzeigen. Der Dienst arbeitet wie ein Mensch am Browser (ein Konto,
> ein Profil, Pausen zwischen den Schritten) und nur mit dem eigenen Konto —
> das Risiko einer Kontosperre bleibt beim Betreiber.

## Installation

```bash
sudo apt install -y python3 python3-venv
# Google Chrome muss installiert sein (google-chrome im PATH) — oder KA_CHROME setzen
cd backend/services/kleinanzeigen && ./install.sh
```

Dienst einrichten (Pfade, User und `KA_TOKEN` in der Unit anpassen):

```bash
sudo cp install/oserp-kleinanzeigen.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now oserp-kleinanzeigen
journalctl -u oserp-kleinanzeigen -f
```

Oder als Benutzer-Dienst ohne sudo: Unit nach `~/.config/systemd/user/`,
`systemctl --user enable --now oserp-kleinanzeigen`, `loginctl enable-linger`.

In OpensourceERP: **Firmenkonfiguration → Shop → Kleinanzeigen-Dienst**
einschalten, Adresse (`http://127.0.0.1:3007`) und dasselbe Token eintragen.
Dann **Shop → Verkaufskanäle → Kleinanzeigen**: die E-Mail des
Kleinanzeigen-Kontos hinterlegen und die Anmeldung übernehmen (unten).

## Anmeldung: der Benutzer meldet sich selbst an

Der Dienst meldet sich **nie mit Passwort an** — kein Auth0-Ablauf, kein
Bestätigungscode, keine Fernsteuerung. Der Benutzer meldet sich in seinem
eigenen Browser bei kleinanzeigen.de an, wie immer. Danach übergibt er die
**Anmelde-IDs** — die Cookies der Seite, mindestens `refresh_token` und
`access_token` — in der Kanalkarte unter **Anmelde-IDs übernehmen**:
Entwicklerwerkzeuge (F12) → Anwendung/Speicher → Cookies →
`https://www.kleinanzeigen.de`, Zeilen kopieren und einfügen. Die Karte
versteht die kopierte Tabelle, `name=wert`-Zeilen, `cookies.txt` und den
JSON-Export einer Cookie-Erweiterung.

`/session/restore` setzt die IDs in das Browserprofil des Dienstes und prüft
die Anmeldung. Angemeldet, hebt OSERP alle Cookies der Sitzung als Geheimnis
`login_cookies` im Kanal auf und spielt sie nach einem Profilverlust von
selbst wieder ein; der Dienst sichert sie zusätzlich beim Schließen in
`<profil>/oserp-session.json` (nur für den Dienstbenutzer lesbar), damit
Sitzungs-Cookies einen Neustart überleben. Laufen die IDs ab, zeigt die
Karte „Nicht angemeldet“ — dann im Browser neu anmelden und die IDs erneut
übernehmen.

Der Dienst tritt dabei nicht anders auf als er ist: keine veränderte
Browserkennung, keine Tarnung. Kleinanzeigen sperrt gelegentlich ganze
IP-Bereiche („IP-Bereich vorübergehend gesperrt“) — der Dienst besucht die
Seite deshalb über IPv4 (`KA_IPV4=1`); `KA_PROXY` (etwa
`socks5://127.0.0.1:1080`) leitet den Browser bei Bedarf über einen anderen
Anschluss.

## Betrieb

| Was | Wer |
|---|---|
| Artikel im Board „Jetzt bei Kleinanzeigen einstellen“ | Dienst, sofort |
| Preis/Text geändert, Artikel abgewählt | Aufträge der Warteschlange (Läufer `tools/shop-publish.php`) stellen um bzw. löschen |
| Abgleich mit „Meine Anzeigen“, Nachrichten holen | Läufer mit `--reconcile-payments` (Cron), Aufträge `sync_ads`, `import_leads` |
| Antworten | Board → Anfragen → „Antwort bei Kleinanzeigen senden“ |

Der Browser wird nach `KA_IDLE_MINUTES` ohne Anfrage geschlossen und beim
nächsten Aufruf neu gestartet (das Profil bleibt).

## Captcha

Erscheint im Anzeigenformular ein Captcha, bricht der Dienst ab (`CAPTCHA`,
mit Bildschirmfoto). Dann den Dienst einmal **sichtbar** starten — auf einem
Rechner mit Display:

```bash
KA_HEADLESS=0 KA_TOKEN=<token> ./.venv/bin/python kleinanzeigen-worker.py
```

und das Captcha im Fenster lösen; die Sitzung bleibt im Profil.

## Konfiguration (Umgebungsvariablen)

| Variable | Bedeutung |
|---|---|
| `KA_PORT` | Port, nur 127.0.0.1 (Standard 3007) |
| `KA_TOKEN` | Pflicht: Shared-Secret, Kopf `X-KA-Token` |
| `KA_PROFILE_DIR` | Basisverzeichnis der Browserprofile (Standard `~/.local/share/oserp-kleinanzeigen`) |
| `KA_CHROME` | Pfad zum Chrome/Chromium (Standard: `google-chrome` aus PATH) |
| `KA_HEADLESS` | `1` unsichtbar (Standard), `0` sichtbar (Fehlersuche, Captcha) |
| `KA_IPV4` | `1` (Standard): Kleinanzeigen-Hosts über IPv4 besuchen |
| `KA_PROXY` | Proxy des Browsers, etwa `socks5://127.0.0.1:1080` |
| `KA_ROOT_URL` | `https://www.kleinanzeigen.de` |
| `KA_IDLE_MINUTES` | Browser nach Leerlauf schließen (Standard 30, 0 = nie) |

## API

Alle Aufrufe `POST` mit JSON, Kopf `X-KA-Token`. `account` ist die Kennung des
Kontos (OSERP: `<datenbank>-<kanal>`), `email` die Konto-Adresse.

| Pfad | Nutzdaten | Antwort |
|---|---|---|
| `GET /health` | – | `{ok, version, headless, chrome, sessions}` |
| `/session/status` | `account, email` | `{state: ok/logged_out/captcha/blocked, logged_in, ids: [{name, domain, expires}]}` |
| `/session/restore` | `account, email, cookies: [{name, value, domain?, path?, expires?, httpOnly?, secure?, sameSite?}]` | wie status, dazu `set` (gesetzte Cookies) und — angemeldet — `cookies` (alle der Sitzung) |
| `/session/close` | `account` | `{closed}` |
| `/ads/list` | `account` | `{ads: [{id, title, state, endDate, …}], count}` |
| `/categories` | `account` | `{categories: [{path, parent, name, level, category_id, is_leaf, sort}], count}` — Kategorie-Dialog des Formulars komplett abgelaufen (einige Minuten), Nummern der Ebenen 1–2 von `s-kategorien.html` |
| `/ads/publish` | `account, ad: {id?, title, description, price, price_type FIXED/NEGOTIABLE/GIVE_AWAY, shipping SHIPPING/PICKUP, condition, category_labels ["Auto, Rad & Boot", "Autoteile & Reifen", "Weitere Autoteile"] (Pfad bis zum Blatt), zipcode, contact_name, images: [Pfade]}` | `{id, url}` |
| `/ads/delete` | `account, id` | `{deleted, status}` |
| `/ads/extend` | `account, id` | `{extended}` |
| `/messages/list` | `account, limit` | `{conversations: [{id, ad_id, ad_title, counterpart, last_message, unread}]}` |
| `/messages/read` | `account, id` | `{messages: [{from, text, time}], text}` |
| `/messages/reply` | `account, id, text` | `{sent}` |
| `/debug/page` | `account, url` | `{url, title, text, html}` |
| `/debug/eval` | `account, code` | `{value}` — JavaScript in der aktuellen Seite |
| `/debug/screenshot` | `account` | `{png (base64), url}` |

Fehler: HTTP 422/500 mit `{ok: false, error: CODE, message, screenshot?}`.
Codes: `NOT_LOGGED_IN`, `NO_COOKIES`, `CAPTCHA`, `CATEGORY`, `CATEGORY_NOT_LEAF`, `FORM_VALIDATION`, `IMAGE_UPLOAD`,
`PACKAGE`, `PAYMENT_REQUIRED`, `SUBMIT_UNCONFIRMED`, `ID_UNKNOWN`,
`DELETE_FAILED`, `EXTEND_BUTTON_MISSING`, `ADS_LIST_FAILED`, `NO_REPLY_BOX`,
`ELEMENT_NOT_FOUND`, `JS_ERROR`, `UNAUTHORIZED`.

## Nachrichten

Die Nachrichtenseite ist eine React-Anwendung ohne dokumentierte API. Der
Dienst sammelt die Unterhaltungen aus den eingebetteten Seitendaten
(`data-initialprops`, JSON-Skripte) und aus den Links der Seite; Antworten
tippt er in das Eingabefeld der Unterhaltung. Das ist der Teil, der am
ehesten nachgezogen werden muss — `/debug/page` mit
`https://www.kleinanzeigen.de/m-nachrichten.html` zeigt die Rohdaten. Fällt
der Abruf aus, bleibt der Weg über die Benachrichtigungs-Mails (IMAP) im
Kanal erhalten.
