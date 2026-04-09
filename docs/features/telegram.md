# Telegram — Bot-Messaging

OpensourceERP kann über einen Telegram-Bot Nachrichten an Kunden und Lieferanten senden und empfangen. Im Gegensatz zu WhatsApp ist die Telegram-Bot-API komplett kostenlos und benötigt keinen Drittanbieter.

## Voraussetzungen

- **Telegram-Bot** (kostenlos über [@BotFather](https://t.me/BotFather) erstellt)
- **Bot-Token** (wird bei der Bot-Erstellung ausgegeben)
- **Öffentlich erreichbare URL** für den Webhook (wie bei WhatsApp)

## Bot erstellen (Schritt für Schritt)

1. Öffne Telegram und suche nach **@BotFather**
2. Sende `/newbot`
3. Wähle einen **Anzeigenamen** (z.B. "Meine Firma")
4. Wähle einen **Benutzernamen** (z.B. `MeineFirmaBot` — muss auf "Bot" enden)
5. BotFather gibt dir einen **Token** aus — diesen in der Konfiguration eintragen

## Bot-Profil einrichten

Ein Bot ohne Profil wirkt unprofessionell. Folgende Einstellungen über @BotFather machen den Bot vorzeigbar:

| Befehl | Was es tut | Beispiel |
|--------|-----------|---------|
| `/setuserpic` | Profilbild setzen | Firmenlogo hochladen |
| `/setdescription` | Beschreibung im Bot-Profil | "Ihr direkter Draht zum Autohaus Müller — Termine, Fragen, Dokumente" |
| `/setabouttext` | Kurztext in der Bot-Info | "Kundenservice Autohaus Müller" |

**Tipp:** Verwende euer Firmenlogo als Profilbild. Der Kunde sieht das Bild in seiner Chat-Liste — es sollte sofort erkennbar sein, mit wem er schreibt.

## Bot-Link verbreiten

Der Bot bringt nur etwas, wenn Kunden ihn finden. Der Link `t.me/EuerBot` sollte überall dort stehen, wo Kunden mit euch in Kontakt treten:

- **Rechnungen / Angebote** — im Fußbereich neben Telefon und E-Mail
- **E-Mail-Signatur** — z.B. "Auch per Telegram erreichbar: t.me/AutohausMuellerBot"
- **Website** — Kontaktseite, ggf. als Chat-Button
- **Visitenkarten** — als QR-Code (Telegram generiert QR-Codes für Bot-Links)
- **Wartezimmer / Empfang** — Aufsteller mit QR-Code: "Schreiben Sie uns per Telegram"
- **Google Business Profil** — als zusätzlichen Kontaktweg eintragen

## Konfiguration

Unter **Einstellungen > CRM > Telegram**:

| Feld | Beschreibung |
|------|-------------|
| Telegram aktiviert | Telegram-Messaging ein-/ausschalten |
| Bot-Token | API-Token vom BotFather (Format: `123456789:ABCdefGHI...`) |
| Bot-Benutzername | Benutzername ohne `@` (z.B. `MeineFirmaBot`) |
| Webhook-Secret | Selbstgewähltes Geheimwort zur Absicherung des Webhooks |

## Wie es funktioniert

### Für den Kunden

1. Kunde erhält einen Link: `t.me/MeineFirmaBot` (z.B. auf Rechnung, Website, E-Mail-Signatur)
2. Kunde öffnet den Link in Telegram und klickt **"Start"**
3. Ab jetzt können beide Seiten Nachrichten austauschen

### Für den Mitarbeiter

Telegram-Nachrichten erscheinen im selben **Nachrichten-Tab** wie WhatsApp-Nachrichten. Jede Nachricht ist mit einem Kanal-Icon gekennzeichnet:
- **WhatsApp**: grünes WhatsApp-Icon
- **Telegram**: blaues Telegram-Icon

Der Mitarbeiter kann über ein Dropdown wählen, über welchen Kanal gesendet wird.

### Eingehende Nachrichten

- Werden per Webhook empfangen (wie bei WhatsApp)
- Erscheinen in Echtzeit in der Oberfläche (SSE)
- Werden als blauer Chip in der **Infoleiste** angezeigt
- Werden automatisch dem Kunden/Lieferanten zugeordnet (über die Telegram-Chat-ID)

## Unterschiede zu WhatsApp

| | WhatsApp | Telegram |
|---|---------|----------|
| Kosten | Meta Business API (kostenpflichtig) | Kostenlos |
| Erstkontakt | Nur per genehmigter Vorlage | Bot muss vom Kunden zuerst gestartet werden |
| 24-Stunden-Fenster | Ja — nach 24h nur noch Vorlagen | Nein — jederzeit schreibbar |
| Vorlagen-Genehmigung | Meta-Review erforderlich | Nicht nötig |
| Medien | Komplexes Token-basiertes Download-System | Direkter Datei-Download über Bot-API |
| Telefonnummer nötig | Ja | Nein — Telegram nutzt Chat-IDs |

## Kontakt-Zuordnung

Kunden/Lieferanten werden über die **Telegram-Chat-ID** zugeordnet, nicht über die Telefonnummer. Beim ersten Kontakt wird die Chat-ID automatisch in den Kontaktdaten (`customer_ext` / `vendor_ext`) gespeichert.

In der Kontaktansicht:
- Hat der Kontakt **nur WhatsApp**: grünes WhatsApp-Icon
- Hat der Kontakt **nur Telegram**: blaues Telegram-Icon
- Hat der Kontakt **beides**: beide Icons nebeneinander
