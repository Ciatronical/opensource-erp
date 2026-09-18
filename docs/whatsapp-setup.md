# WhatsApp Business API einrichten (Meta Cloud API)

Anleitung für die Anbindung eines OSERP-Mandanten an die **Meta WhatsApp Cloud API**
(Senden, Empfangen, Templates, automatische Erinnerungen).

Beispielwerte in diesem Dokument stammen von der Installation **lack.spdns.de**
(Firma „Autolackiererei Schönert GmbH“, DB `lack_db_skr03`).

Was die Anbindung im ERP kann (Kunden-Chat, Infoleiste, Belegversand), steht in
`docs/features/whatsapp.md`. Hier geht es nur um die Einrichtung.

---

## 0. Überblick — was zusammenspielt

| Baustein | Ort | Zweck |
| --- | --- | --- |
| Meta Business Portfolio | business.facebook.com | Firmenkonto, Business-Verifizierung |
| WhatsApp Business Account (WABA) | Meta Business Suite | hängt am Portfolio, hält die Nummer |
| Meta-App (Typ „Business“) | developers.facebook.com | liefert Access Token + Webhook |
| Webhook | `https://<host>/webhook/whatsapp.php` | Empfang eingehender Nachrichten |
| Firmenkonfiguration | OSERP → Konfiguration → CRM | Token/IDs in `defaults_oserp` |
| Templates | OSERP → Konfiguration → CRM → Vorlagen | Meta-genehmigte Nachrichtenvorlagen |
| Cron | `backend/cli/whatsapp-reminders.php` | Termin- und HU-Erinnerungen |

Der Webhook ist **mandantenfähig**: Meta ruft immer dieselbe URL, OSERP sucht die
passende Firmen-DB über `whatsapp_verify_token` (GET-Verify) bzw. über die
`whatsapp_phone_number_id` in der Nachricht (POST). Mehrere Firmen auf einem Server
brauchen also unterschiedliche Verify-Token und unterschiedliche Nummern.

---

## 1. Voraussetzungen prüfen (Server)

**a) Webhook muss von außen PHP ausliefern, nicht die Vue-`index.html`:**

```bash
curl -sk "https://lack.spdns.de/webhook/whatsapp.php?hub.mode=subscribe&hub.verify_token=x&hub.challenge=PING"
```

- Erwartet: `{"error":"Verification failed"}` (Token noch nicht gesetzt) oder `PING` (Token passt).
- Kommt `<!doctype html>`, fehlt im aktiven Apache-vHost der `/webhook`-Alias — der
  DocumentRoot hat `FallbackResource /index.html` und verschluckt den Aufruf.

```apache
Alias /webhook /home/work/opensource-erp/backend/webhook
<Directory /home/work/opensource-erp/backend/webhook>
    Require all granted
    Options -Indexes
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php-fpm.sock|fcgi://localhost/"
    </FilesMatch>
</Directory>
```

Den FPM-Socket **nicht** auf eine PHP-Version festnageln — der Installer setzt
`OSERP_PHP_FPM` passend zur installierten Version.

**b) Gültiges TLS-Zertifikat.** Meta akzeptiert keine selbstsignierten Zertifikate.

```bash
openssl s_client -connect lack.spdns.de:443 -servername lack.spdns.de </dev/null 2>/dev/null \
  | openssl x509 -noout -subject -issuer -dates
```

**c) Schema vorhanden.** Die Tabellen `whatsapp_messages`, `whatsapp_templates` und
`whatsapp_reminder_log` kommen aus `backend/upstall/crm/company_schema.sql`. Fehlen sie,
in OSERP das Datenbank-Update laufen lassen (Konfiguration → Update).

---

## 2. Meta-Seite einrichten (von Null)

### 2.0 Direkte Einstiegs-URLs

Die Meta-Oberfläche wird häufig umgebaut, die Direktlinks sind stabiler als der Menüweg.
`<APP_ID>` ist die Zahl, die nach dem Anlegen der App in deren URL steht.

| Zweck | URL |
| --- | --- |
| App anlegen | <https://developers.facebook.com/apps/creation/> |
| Eigene Apps | <https://developers.facebook.com/apps/> |
| API-Setup der App (Nummer, IDs, Test-Token) | `https://developers.facebook.com/apps/<APP_ID>/whatsapp-business/wa-dev-console/` |
| Webhook-Konfiguration der App | `https://developers.facebook.com/apps/<APP_ID>/whatsapp-business/wa-settings/` |
| WhatsApp Manager — Nummern | <https://business.facebook.com/wa/manage/phone-numbers/> |
| WhatsApp Manager — Konten/WABA | <https://business.facebook.com/wa/manage/accounts/> |
| Systembenutzer (permanenter Token) | <https://business.facebook.com/latest/settings/system_users> |
| Business-Verifizierung | <https://business.facebook.com/settings/security> |
| Abrechnung/Zahlungsmethode | <https://business.facebook.com/billing_hub/payment_settings> |

### 2.1 Business Portfolio anlegen

1. <https://business.facebook.com> → Portfolio der Firma anlegen (echter Firmenname,
   Anschrift und Website müssen zur Impressumslage passen — das wird geprüft).
2. **Business-Verifizierung** starten (Einstellungen → Unternehmensinfo → Verifizierung).
   Nötig sind Handelsregisterauszug/Gewerbeanmeldung und ein Nachweis der Firmenadresse.
   Ohne Verifizierung läuft der Betrieb nur eingeschränkt: max. 2 Nummern und
   250 vom Unternehmen gestartete Konversationen pro 24 h.

### 2.2 Telefonnummer klären — vorab entscheiden

Die Nummer darf nicht in der normalen WhatsApp-App aktiv sein. Drei Wege:

- **Neue Nummer** (eigene SIM/Festnetz, muss SMS oder Anruf empfangen können) — sauberster Weg.
- **Bestehende WhatsApp-Business-App-Nummer übernehmen**: Nummer vorher in der App
  löschen (Konto löschen), danach in der Cloud API registrieren. Der Chatverlauf ist weg.
- **Coexistence**: Nummer bleibt in der WhatsApp-Business-App *und* wird zusätzlich an
  die Cloud API angebunden. Für die meisten europäischen Länder verfügbar. Für einen
  Handwerksbetrieb meist die beste Variante, weil das Handy weiter normal benutzbar bleibt.

> Vorher mit dem Kunden klären, welche Nummer künftig die Firmennummer für WhatsApp ist.
> Ein Wechsel später bedeutet: neue Verifizierung, neue `phone_number_id`, Templates bleiben.

### 2.3 App anlegen und WhatsApp hinzufügen

Meta fragt beim Anlegen **nicht mehr nach einem App-Typ**, sondern nach einem
*Anwendungsfall*. Der App-Typ „Business“ aus älteren Anleitungen existiert nicht mehr.

1. <https://developers.facebook.com/apps/creation/> → Anwendungsfall
   **„Mit Kunden über WhatsApp in Kontakt treten“** (engl. *Connect with customers
   through WhatsApp*) wählen. Wer die Auswahl nicht sieht: <https://developers.facebook.com/apps/>
   → Schaltfläche **App erstellen**.
2. App-Namen und Kontakt-E-Mail eintragen und die App dem Portfolio aus 2.1 zuordnen
   (Feld *Business-Portfolio*) — nicht überspringen, sonst fehlt später die Zuordnung
   zum WABA.
3. Im linken Menü **WhatsApp → API-Setup**: als erstes das Business-Portfolio der Firma
   auswählen. Damit legt Meta den **WABA (WhatsApp Business Account)** automatisch an —
   das WhatsApp-Konto der Firma, an dem Nummer, Templates und Abrechnung hängen. Er muss
   nicht separat erstellt werden und ist später im WhatsApp Manager sichtbar
   (<https://business.facebook.com/wa/manage/accounts/>). Dann über „Telefonnummer
   hinzufügen“ die Firmennummer eintragen und per SMS oder Sprachanruf verifizieren.
4. Auf derselben Seite ablesen und notieren:
   - **Phone Number ID** (lange Zahl, **nicht** die Telefonnummer)
   - **WhatsApp Business Account ID** (WABA-ID)

Nummern lassen sich alternativ im WhatsApp Manager verwalten:
<https://business.facebook.com/wa/manage/phone-numbers/>

Wer die IDs im App-Dashboard nicht findet: Im WhatsApp Manager steht die WABA-ID in der
URL als `asset_id` (`…/whatsapp_manager/overview?business_id=…&asset_id=<WABA-ID>`), die
Phone Number ID in der Detailansicht der Nummer unter *Telefonnummern*. **Nicht** die
Meta-App-ID eintragen — Template-Einreichungen laufen dann in `Unsupported post request.
Object with ID '<App-ID>' does not exist`.

### 2.4 Permanenten Access Token erzeugen

Der Token aus dem API-Setup gilt nur 24 Stunden — für den Dauerbetrieb einen
System-User-Token verwenden:

1. business.facebook.com → Einstellungen → **Systembenutzer** → neuer Systembenutzer.
   Die Rolle „Employee“ reicht, sobald App und WABA mit Vollzugriff zugewiesen sind;
   „Administrator“ ist nicht nötig.
2. **Assets zuweisen**: die App *und* den WABA, jeweils mit Vollzugriff.
3. **Token generieren** → App auswählen → Ablauf **„Nie“** → Berechtigungen:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
4. Token sofort kopieren — er wird nur einmal angezeigt.

### 2.5 Webhook eintragen

App → *WhatsApp → Konfiguration → Webhook → Bearbeiten*:

- **Callback-URL**: `https://lack.spdns.de/webhook/whatsapp.php`
- **Verify-Token**: frei gewählte Zeichenkette, z. B. per `openssl rand -hex 16` erzeugt.
  Genau derselbe Wert muss vorher in OSERP stehen (Schritt 3), sonst antwortet der
  Webhook mit 403 und Meta lehnt ab.
- Nach „Verifizieren und speichern“ das Feld **`messages`** abonnieren (Häkchen unter
  *Webhook-Felder*). Ohne dieses Abo kommen keine eingehenden Nachrichten an.
- Das Feld *Client-Zertifikat* leer lassen — optionales mTLS, wird nicht gebraucht.
- **App veröffentlichen** (Dashboard-Schalter „Entwicklung → Live“ bzw. Link „Deine App
  veröffentlichen“ im Hinweiskasten). Im Entwicklungsmodus liefert Meta **keine
  Produktionsdaten** an den Webhook — das Verifizieren klappt, danach bleibt er stumm.

### 2.6 Zahlungsmethode

Meta Business Suite → Abrechnung → Zahlungsmethode hinterlegen. Ohne sie versendet
die API nach dem Freikontingent nicht mehr. Fehlt sie oder ist sie fehlerhaft, meldet
der `health_status` des WABA den Fehler **141006** und `can_send_message = BLOCKED` —
dann gehen auch genehmigte Vorlagen nicht raus. Prüfen lässt sich das per Graph API:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://graph.facebook.com/v21.0/<WABA-ID>?fields=health_status,business_verification_status"
```

`business_verification_status = pending_submission` heißt: Verifizierung (2.1) noch
nicht einmal gestartet; Fehler **141010** im Health-Status ist dieselbe Ursache.

**Kosten (Stand 2026):** Abgerechnet wird pro zugestellter Template-Nachricht nach
Kategorie und Zielland; Service-Antworten im 24-Stunden-Fenster sind derzeit frei.
**Ab 1. Oktober 2026 berechnet Meta auch Service- und Utility-Nachrichten innerhalb des
24-Stunden-Fensters** — für den Kunden vorher einplanen. Utility liegt aktuell im
niedrigen Cent-Bereich pro Nachricht, Marketing deutlich darüber.

---

## 2.7 Mehrere Firmen mit einem Facebook-Konto

Ein persönliches Facebook-Konto kann Administrator in mehreren Business-Portfolios sein —
mehrere betreute Firmen sind also kein Problem. **Jede Firma bekommt aber ihr eigenes
Portfolio.** Ein WABA gehört immer zu genau einem Portfolio und lässt sich später nur
mit erheblichem Aufwand umhängen.

Warum nicht alles in ein Portfolio:

- Die Business-Verifizierung hängt am Portfolio und läuft über die Dokumente *einer*
  Firma. Ein fremder WABA im eigenen Portfolio sendet unter der Verifizierung dieser Firma.
- Qualitätsprobleme und Sperren treffen das **ganze Portfolio**. Beschwerden über die
  Nachrichten der einen Firma legen sonst auch die andere lahm.
- Die Zahlungsmethode hängt am Portfolio — sonst zahlt der Portfolio-Inhaber die
  Nachrichten des anderen Betriebs.
- Endet die Betreuung, gibt man ein eigenes Portfolio einfach ab.

Grenzen: ohne weitere Verifizierung erlaubt Meta 2 WABAs je Portfolio, nach der
Verifizierung bis zu 20 WABAs und 20 Nummern. Ein privates Konto darf anfangs nur wenige
Portfolios anlegen; wird das Anlegen blockiert, legt der Kunde das Portfolio mit seinem
eigenen Facebook-Konto an und lädt den Betreuer als Administrator ein.

**Was pro Firma neu angelegt werden muss und nichts wiederverwendet:**

| Objekt | Wiederverwendbar? |
| --- | --- |
| Facebook-Login des Betreuers | ja |
| Business-Portfolio | nein — eigenes je Firma |
| Business-Verifizierung | nein — mit den Dokumenten der jeweiligen Firma |
| Meta-App | nein, sobald die Installationen auf verschiedenen Servern laufen: eine App hat genau **eine** Webhook-Callback-URL |
| WABA + Telefonnummer | nein |
| System-User + Access Token | nein — Token gilt für App und WABA der jeweiligen Firma |
| Templates | nein — Templates gehören zum WABA und müssen je Firma neu eingereicht werden |
| Zahlungsmethode | nein |

Mehrere Firmen auf **demselben** Server (also derselben Webhook-URL) können sich dagegen
eine App teilen. Die Zuordnung eingehender Nachrichten läuft über die
`whatsapp_phone_number_id` — jede Firmen-DB braucht also ihre **eigene** Nummer bzw. ID,
sonst landen Nachrichten beim falschen Mandanten. Der Verify-Token spielt dabei keine
Rolle: Er wird nur einmalig beim Einrichten geprüft, und der Webhook akzeptiert ihn,
sobald *irgendeine* Firmen-DB ihn kennt.

## 3. OSERP konfigurieren

**Konfiguration → CRM → Abschnitt „WhatsApp Business API“:**

| Feld | Wert |
| --- | --- |
| Access Token | System-User-Token aus 2.4 |
| Phone Number ID | aus 2.3 |
| Business Account ID | WABA-ID aus 2.3 |
| Webhook Verify Token | identisch zu 2.5 |
| Landesvorwahl | `49` (ohne `+`, wird beim Normalisieren von `0…`-Nummern vorangestellt) |

Zusätzlich im Abschnitt darüber:

- **Standardnachricht** — Text für den Direktversand aus der Telefon-Aktionsleiste.

Die Werte landen in `defaults_oserp` der Firmen-DB. Danach den Verify-Test aus
Schritt 1a wiederholen: mit dem echten Token muss `PING` zurückkommen.

**Firmenprofil bei Meta** (WhatsApp Manager → Telefonnummern → Nummer → Profil):
Profilbild JPEG/PNG, quadratisch, 192×192 bis 640×640 Pixel, max. 5 MB — WhatsApp zeigt
es als Kreis, Logo also mittig platzieren. Dazu Adresse, Öffnungszeiten, Website und
Kategorie (z. B. „Autowerkstatt“). Das sehen Kunden im Chat-Kopf.

---

## 4. Templates einrichten

Außerhalb des 24-Stunden-Fensters darf nur mit von Meta genehmigten Vorlagen
geschrieben werden. Ablauf in OSERP (Konfiguration → CRM → „Vorlagen“):

1. **Standardvorlagen laden** — legt die von OSERP genutzten Vorlagen als `draft` an:
   `allgemeine_info` (Chat), `dokument_senden`, `chat_dokument`, `chat_bild`,
   `hu_erinnerung`, `termin_erinnerung`, `termin_bestaetigung`, `adresse_senden_v2`.
2. Texte anpassen (Firmenname, Ansprache) und **Beispielwerte** für jeden Platzhalter
   `{{1}}`, `{{2}}` … eintragen — fehlen sie, lehnt Meta die Prüfung ab.
3. Je Vorlage **an Meta senden**. Kategorie `UTILITY` wählen, solange es um Termine,
   Belege und Rückfragen geht; `MARKETING` ist teurer und wird strenger geprüft.
4. Genehmigung abwarten, dann **von Meta synchronisieren** — holt Status,
   `meta_template_id` und die **tatsächliche Kategorie** (Graph API
   `/{WABA-ID}/message_templates`). Meta nennt bis zu 24 Stunden Prüfdauer, bei
   frischen Konten sind 24–48 Stunden normal. Die Beispielvorlage `hello_world` ist
   sofort grün, weil sie von Meta vorab genehmigt ist — kein Maßstab.
5. **Zuordnungen** im Abschnitt „Vorlagen-Zuordnung“ setzen: Chat, Chat-Dokument,
   Chat-Bild, Faktura, HU, Terminerinnerung, Terminbestätigung, Adresse. Erst damit
   weiß OSERP, welche Vorlage die jeweilige Funktion benutzt.

Vorlagen mit Header-Typ `DOCUMENT`/`IMAGE` (`dokument_senden`, `chat_dokument`,
`chat_bild`) brauchen bei der Einreichung eine Beispieldatei — die lädt OSERP
automatisch hoch (`_uploadSampleMediaForTemplate`).

**Meta stuft Kategorien eigenmächtig um.** Wird eine als `UTILITY` eingereichte Vorlage
von Metas Klassifizierer als Werbung gelesen, legt Meta sie stillschweigend als
`MARKETING` an — teurer, strengere Prüfung. OSERP merkt sich die eingereichte Kategorie
(`submitted_category`) und zeigt in der Vorlagenliste den Chip „Von Meta umgestuft“,
sobald der Sync eine Abweichung bringt. Auslöser sind allgemein gehaltene Texte wie
„wir möchten Sie über Folgendes informieren“; `UTILITY` verlangt einen Bezug zu einem
konkreten Vorgang („zu Ihrem Auftrag {{2}} …“, „für Ihren Termin am {{2}} …“).

Solange eine Vorlage `PENDING` ist, lässt sie sich bei Meta weder bearbeiten noch mit
demselben Namen neu einreichen (Fehler „In dieser Sprache sind bereits Inhalte
vorhanden“ bzw. „Vorlagenkategorie stimmt nicht überein“). Umformulieren geht so:
Vorlage in OSERP löschen (löscht sie auch bei Meta), neu anlegen, einreichen.

Weitere Ablehnungsgründe aus der Praxis: Variable am Anfang oder Ende des Textes
(„Vorangestellte oder nachgestellte Parameter sind nicht zulässig“), fehlende
Beispielwerte, Grußformel doppelt in Body und Footer.

---

## 5. Automatische Erinnerungen (optional)

1. Konfiguration → CRM → „WhatsApp-Erinnerungen“: aktivieren und Vorlaufzeit in
   Stunden setzen. HU-Erinnerungen zusätzlich unter LxCars aktivieren.
2. Cron für den Mandanten-Durchlauf (siehe `install/README.md`):

```cron
*/15 * * * * cd /home/work/opensource-erp && php backend/cli/whatsapp-reminders.php >> log/whatsapp-reminders.log 2>&1
```

Versendetes wird in `whatsapp_reminder_log` protokolliert, damit nichts doppelt rausgeht.

---

## 6. Funktionstest

1. **Empfang:** vom Handy eine Nachricht an die Firmennummer schicken → sie muss in
   OSERP unter *WhatsApp* erscheinen (Live über SSE, Trigger `notify_whatsapp_message`).
   Bleibt sie aus: Apache-Log prüfen und in der Meta-App unter *Webhook* nachsehen,
   ob `messages` abonniert ist. Landet sie in der DB (`whatsapp_messages`), aber nicht
   live in der Oberfläche: SSE-Dienst neu starten (`install/README.md`, Abschnitt 4b).
2. **Antwort im Fenster:** direkt im Chat antworten — freier Text, kein Template nötig.
3. **Template:** einem Kunden ohne offenes Fenster über eine genehmigte Vorlage schreiben.
4. **Beleg:** aus der Faktura ein PDF per WhatsApp senden.

---

## 7. Typische Fehler

| Symptom | Ursache |
| --- | --- |
| Verify liefert `<!doctype html>` | `/webhook`-Alias fehlt im aktiven vHost (FallbackResource greift) |
| Verify liefert 403 | `whatsapp_verify_token` in OSERP ≠ Token im Meta-Dashboard, oder noch leer |
| Senden geht, Empfang nicht | Webhook-Feld `messages` nicht abonniert, App noch im Entwicklungsmodus (nicht veröffentlicht), oder `whatsapp_phone_number_id` passt nicht zur sendenden Nummer |
| Nach 24 h keine Sendungen mehr | temporärer Token statt System-User-Token („Nie“ ablaufend) |
| `WHATSAPP_NOT_CONFIGURED` | Access Token oder WABA-ID leer in `defaults_oserp` |
| Template bleibt `pending`/`rejected` | Beispielwerte fehlen oder Kategorie falsch (Werbetext als `UTILITY`) |
| Nummer lässt sich nicht registrieren | Nummer noch in der WhatsApp-(Business-)App aktiv → dort löschen oder Coexistence nutzen |
| `Object with ID '<App-ID>' does not exist` beim Einreichen | Meta-App-ID statt WABA-ID im Feld „Business Account ID“ |
| Vorlage genehmigt, Versand trotzdem blockiert | Zahlungsmethode fehlt/fehlerhaft (Health-Status 141006) oder Business-Verifizierung nicht gestartet (141010) |
| Vorlage steht auf MARKETING statt UTILITY | Meta hat umgestuft — Text an konkreten Vorgang binden, löschen und neu einreichen |
| Kein WhatsApp-Tab beim Kunden | Kunde hat keine Telefonnummer, oder Schema fehlt (Datenbank-Update) |
| Infoleiste zeigt keine neuen Nachrichten | SSE-Dienst neu starten, Browser-Konsole auf Fehler prüfen |

---

## 8. Datenschutz

Eingehende Nachrichten samt Medien landen in der Firmen-DB bzw. unter `backend/data/<db>/`.
Für die Kundenkommunikation per WhatsApp gehören ein Hinweis in der Datenschutzerklärung
und eine dokumentierte Löschregel dazu — die Löschfunktionen liegen in OSERP unter
*Datenschutz*.
