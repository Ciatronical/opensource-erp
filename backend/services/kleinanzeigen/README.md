# Kleinanzeigen-Dienst (Headless-Chrome als Pseudo-API)

Kleinanzeigen.de hat keine Schnittstelle. Dieser Dienst steuert einen echten
Chrome über das DevTools-Protokoll (`nodriver`, keine WebDriver-Spuren) mit dem
**eigenen Konto des Betreibers** und bietet OpensourceERP eine kleine JSON-API:
anmelden, Anzeigen einstellen/ändern/löschen/verlängern, „Meine Anzeigen“
lesen, Nachrichten holen und beantworten.

Die Abläufe folgen dem Stand der Webseite im Oktober 2026 (Auth0-Login in zwei
Schritten, Anzeigenformular `p-anzeige-aufgeben-schritt2.html`, Paketwahl
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
Dann **Shop → Verkaufskanäle → Kleinanzeigen**: E-Mail und Passwort des
Kleinanzeigen-Kontos hinterlegen und im Bereich „Kleinanzeigen-Dienst“ auf
**Anmelden** klicken. Verlangt Kleinanzeigen einen Code per E-Mail, holt OSERP
ihn aus dem Postfach des E-Mail-Moduls; per SMS fragt die Karte den Code ab.
Die Anmeldung bleibt im Browserprofil erhalten — danach läuft alles ohne
Zutun.

## Betrieb

| Was | Wer |
|---|---|
| Artikel im Board „Jetzt bei Kleinanzeigen einstellen“ | Dienst, sofort |
| Preis/Text geändert, Artikel abgewählt | Aufträge der Warteschlange (Läufer `tools/shop-publish.php`) stellen um bzw. löschen |
| Abgleich mit „Meine Anzeigen“, Nachrichten holen | Läufer mit `--reconcile-payments` (Cron), Aufträge `sync_ads`, `import_leads` |
| Antworten | Board → Anfragen → „Antwort bei Kleinanzeigen senden“ |

Der Browser wird nach `KA_IDLE_MINUTES` ohne Anfrage geschlossen und beim
nächsten Aufruf neu gestartet (das Profil bleibt).

## Anmeldung im Fenster (empfohlen für den ersten Login)

Läuft der Dienst mit `KA_HEADLESS=0` und `DISPLAY`/`XAUTHORITY` (siehe Unit),
öffnet **Im Fenster anmelden** in der Kanalkarte die Anmeldeseite in einem
sichtbaren Chrome-Fenster auf dem Bildschirm. Dort meldet sich der Benutzer
selbst an, auch durch Captcha oder Bestätigungscode hindurch; die Karte
erkennt die fertige Sitzung von selbst. Danach arbeitet der Dienst mit
dieser Sitzung weiter. Kleinanzeigen sperrt gelegentlich ganze IP-Bereiche
(„IP-Bereich vorübergehend gesperrt“) — der Dienst besucht die Seite deshalb
über IPv4 (`KA_IPV4=1`) und pausiert automatische Anmeldungen nach einer
Sperre für zwei Stunden; `KA_PROXY` (etwa `socks5://127.0.0.1:1080`) leitet
den Browser bei Bedarf über einen anderen Anschluss.

## Captcha

Auf der Anmeldeseite kann ein Captcha erscheinen (meist nur einmal je Rechner).
Dann den Dienst einmal **sichtbar** starten — auf einem Rechner mit Display:

```bash
KA_HEADLESS=0 KA_TOKEN=<token> ./.venv/bin/python kleinanzeigen-worker.py
```

und in der Kanalkarte „Anmelden“ drücken; das Captcha im Fenster lösen. Ohne
Display geht das über einen VNC-Zugang oder indem das Browserprofil
(`~/.local/share/oserp-kleinanzeigen/<datenbank>-<kanal>`) von einem anderen
Rechner kopiert wird, auf dem die Anmeldung gelungen ist.

## Konfiguration (Umgebungsvariablen)

| Variable | Bedeutung |
|---|---|
| `KA_PORT` | Port, nur 127.0.0.1 (Standard 3007) |
| `KA_TOKEN` | Pflicht: Shared-Secret, Kopf `X-KA-Token` |
| `KA_PROFILE_DIR` | Basisverzeichnis der Browserprofile (Standard `~/.local/share/oserp-kleinanzeigen`) |
| `KA_CHROME` | Pfad zum Chrome/Chromium (Standard: `google-chrome` aus PATH) |
| `KA_HEADLESS` | `1` unsichtbar (Standard), `0` sichtbar |
| `KA_ROOT_URL` | `https://www.kleinanzeigen.de` |
| `KA_IDLE_MINUTES` | Browser nach Leerlauf schließen (Standard 30, 0 = nie) |

## API

Alle Aufrufe `POST` mit JSON, Kopf `X-KA-Token`. `account` ist die Kennung des
Kontos (OSERP: `<datenbank>-<kanal>`), `email` die Konto-Adresse.

| Pfad | Nutzdaten | Antwort |
|---|---|---|
| `GET /health` | – | `{ok, version, headless, chrome, sessions}` |
| `/session/status` | `account, email` | `{state: ok/logged_out/code_required/captcha/blocked, logged_in}` |
| `/session/login` | `account, email, password` | `{state: ok/code_required (kind)/captcha/failed, message, screenshot}` |
| `/session/code` | `account, code` | `{state: ok/failed}` |
| `/session/close` | `account` | `{closed}` |
| `/ads/list` | `account` | `{ads: [{id, title, state, endDate, …}], count}` |
| `/ads/publish` | `account, ad: {id?, title, description, price, price_type FIXED/NEGOTIABLE/GIVE_AWAY, shipping SHIPPING/PICKUP, condition, category "210/223", category_labels ["Auto, Rad & Boot", "Autoteile & Reifen"], zipcode, contact_name, images: [Pfade]}` | `{id, url}` |
| `/ads/delete` | `account, id` | `{deleted, status}` |
| `/ads/extend` | `account, id` | `{extended}` |
| `/messages/list` | `account, limit` | `{conversations: [{id, ad_id, ad_title, counterpart, last_message, unread}]}` |
| `/messages/read` | `account, id` | `{messages: [{from, text, time}], text}` |
| `/messages/reply` | `account, id, text` | `{sent}` |
| `/debug/page` | `account, url` | `{url, title, text, html}` |
| `/debug/screenshot` | `account` | `{png (base64), url}` |

Fehler: HTTP 422/500 mit `{ok: false, error: CODE, message, screenshot?}`.
Codes: `NOT_LOGGED_IN`, `LOGIN_PAGE`, `LOGIN_EMAIL_STEP`, `LOGIN_UNCONFIRMED`,
`NO_CODE_FIELD`, `CAPTCHA`, `CATEGORY`, `FORM_VALIDATION`, `IMAGE_UPLOAD`,
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
