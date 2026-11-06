---
title: Telegram
summary: Kundenchat über den Firmen-Bot (Antworten auf SMS, Fotos, Dokumente), Sprachnotizen und Anschlagtafel
group: core
category: Kommunikation
order: 52
status: stable
---

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

## Kundenchat (Kontakt → Telegram)

### Für den Kunden

1. Der Kunde öffnet `t.me/EuerBot` – der Link steht unter jeder SMS (Antwort-Hinweis, siehe [SMS](sms.md)) und gehört auf Rechnung, Website usw.
2. Er tippt **Start** und teilt mit einem Knopfdruck seine **Telefonnummer**. Der Bot findet ihn darüber im Kunden- bzw. Lieferantenstamm (auch Zusatznummern und Ansprechpersonen) und verknüpft ihn.
3. Ab jetzt schreibt er einfach – Text, **Fotos**, **Dokumente**, **Sprachnachrichten** oder seinen **Standort**.

Schreibt jemand, ohne seine Nummer geteilt zu haben, kommt die Nachricht trotzdem an; der Bot bittet ihn höchstens einmal am Tag um die Nummer. Passt die geteilte Nummer zu keinem Kunden, bleibt sie im Chat stehen.

### Für den Mitarbeiter

- **Kontakt → Telegram**: alle Chats mit letzter Nachricht und Zähler für ungelesene; unbekannte Absender sind orange markiert und lassen sich mit **Zuordnen** einem Kunden oder Lieferanten zuweisen – samt allen bisherigen Nachrichten.
- **Reiter Telegram** in der Kundenmaske: Verlauf und Antwort. Ist der Kunde noch nicht verknüpft, steht dort der Bot-Link zum Kopieren.
- Fotos erscheinen als Vorschau, Dokumente und Sprachnachrichten lassen sich öffnen; Sprachnachrichten von Kunden werden per Whisper transkribiert.
- **Antworten** mit Datei (Upload oder aus der Kundenablage): Bilder gehen als Foto, alles andere als Dokument – direkt in Telegram, ohne Link. Scheitert der Versand (z. B. Bot blockiert), steht der Grund an der Nachricht.
- Eingehende Nachrichten und gescheiterte Antworten erscheinen in der **Info-Leiste** und in der **Kontakthistorie** (Filter Telegram). Rundschreiben per Telegram stehen ebenfalls im Verlauf.

### Mitarbeiter und Kunden am selben Bot

Sprachnachrichten von Mitarbeitern bleiben **Sprachnotizen für die Anschlagtafel**. Als Mitarbeiter-Chat gilt, wer in `telegram_chat_map` steht oder schon Sprachnotizen geschickt hat – auch wenn derselbe Chat als Kunde verknüpft ist. Textnachrichten von Mitarbeitern ohne Kundenverknüpfung ignoriert der Bot wie bisher.

## Unterschiede zu WhatsApp

| | WhatsApp | Telegram |
|---|---------|----------|
| Kosten | Meta Business API (kostenpflichtig) | Kostenlos |
| Erstkontakt | Nur per genehmigter Vorlage | Bot muss vom Kunden zuerst gestartet werden |
| 24-Stunden-Fenster | Ja — nach 24h nur noch Vorlagen | Nein — jederzeit schreibbar |
| Vorlagen-Genehmigung | Meta-Review erforderlich | Nicht nötig |
| Medien | Komplexes Token-basiertes Download-System | Direkter Datei-Download über Bot-API |
| Telefonnummer nötig | Ja | Nein — Telegram nutzt Chat-IDs |

## Technik

- Tabelle `telegram_messages` (Upstall crm): Chat-ID, Richtung, Kunde/Lieferant, Art (text, photo, document, voice, …), Text bzw. Transkript, Datei (`data/<db>/telegram/<Jahr-Monat>/` bzw. Kundenablage `telegram/`), Status, Quelle (`chat`, `mailing`), Mitarbeiter. Trigger `notify_telegram_message` meldet per SSE an Kontakthistorie und Info-Leiste.
- Eingang: `backend/webhook/telegram.php`; Kern `backend/api/telegram/telegram_lib.php` (Versand, Bot-Name), API `backend/api/telegram/telegram.php`.
- Frontend: `src/core/views/telegram/`, `src/core/components/telegram/telegram-chat.vue`, Reiter `telegram.tab.vue`.

## Anschlagtafel und Tafel-Verwaltung

Sprachnotizen an den Bot werden per Whisper transkribiert und erscheinen live auf der **Anschlagtafel** (TV). In der **Tafel-Verwaltung** (PC-Variante) lassen sich die Einträge pflegen:

- **Hinzufügen** — Notiz eintippen, Absender wählen, Enter
- **Bearbeiten** — auf den Text klicken (oder den Stift), korrigieren, Enter speichert, Esc bricht ab. Gedacht vor allem für falsch verstandene Sprachnotizen; eine fehlgeschlagene Transkription gilt nach der Korrektur als erledigt
- **Löschen** und **Umsortieren** per Drag & Drop

Alle Änderungen erscheinen sofort auf der Anschlagtafel und in anderen geöffneten Tafel-Verwaltungen.
