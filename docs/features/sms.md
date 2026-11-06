---
title: SMS
summary: Kurznachrichten an Kunden und Lieferanten über seven.io – Gespräche, Belege als Link, Antworten und automatische Ausweich-SMS, wenn eine WhatsApp nicht ankommt
group: core
category: Kommunikation
order: 52
status: stable
since: 2026-11-05
external: true
keywords: [SMS, seven.io, sms77, Kurznachricht, Beleg per SMS, Angebot per SMS, Ausweich-SMS, WhatsApp-Ausweiche, Zustellbericht]
---

# SMS

SMS ist neben WhatsApp, Telegram und E-Mail ein vollwertiger Kanal: eigene Seite unter **Kontakt → SMS**, Reiter **SMS** in der Kundenmaske, Einträge in der **Kontakthistorie**, Versand von **Belegen als Link** aus der Faktura und Meldungen in der **Info-Leiste**. Versendet wird über **seven.io** (ehemals sms77); jede SMS kostet beim Anbieter Geld (je 160 Zeichen ein Teil).

## Gespräche (Kontakt → SMS)

- Links alle Gespräche, je Rufnummer mit letzter Nachricht, Uhrzeit und Zähler für ungelesene Antworten; Suche nach Name, Nummer und Text.
- Rechts der Verlauf wie in einer Chat-App: eigene SMS rechts mit Mitarbeiter und Zustellstatus (wird gesendet, gesendet, zugestellt, fehlgeschlagen mit Grund), Antworten links. Links im Text sind anklickbar.
- **Neue SMS**: Kunde oder Lieferant suchen (Mobilnummern stehen vorn) oder eine beliebige Nummer eingeben, dann im Chat schreiben.
- Der **SMS-Knopf** an jeder Telefonnummer (Kontaktkarte, Anrufliste, Faktura) öffnet das Gespräch mit dieser Nummer, in der Kundenmaske direkt den Reiter SMS.
- Das Guthaben bei seven.io steht im Seitenkopf.

## Schreiben

- Der Text ist **frei änderbar**. Unter dem Eingabefeld stehen Zeichenzahl, Anzahl der SMS-Teile und die voraussichtlichen Kosten (Kosten je SMS aus der Firmenkonfiguration).
- **Sonderzeichen**: Typografische Anführungszeichen („ “), Gedankenstriche (–) oder Auslassungspunkte (…) gehören nicht zum SMS-Zeichensatz. Eine SMS fasst dann nur 70 statt 160 Zeichen und wird schnell zwei- bis dreimal so teuer. Ein Klick auf **Sonderzeichen ersetzen** tauscht sie gegen gleichwertige einfache Zeichen.
- **Dateien** (hochladen oder aus der Kundenablage) gehen als **Link** mit: Der Empfänger öffnet die Datei im Browser. Der Platzhalter `{link}` bestimmt, wo der Link im Text steht; fehlt er, kommt der Link ans Ende.
- Hat der Empfänger den Link geöffnet, zeigt der Verlauf **„geöffnet“** mit Zeitpunkt.

## Belege per SMS (Faktura)

In der Aktionsleiste jedes Belegs steht neben WhatsApp der Knopf **SMS** (nur wenn SMS eingerichtet ist). Der Dialog schlägt die Mobilnummer des Kunden vor und einen Text, zum Beispiel:

> Guten Tag Herr Müller, hier Ihr Angebot Nr. 4711 über 1.234,56 €: {link} Viele Grüße, Autoprofis GmbH

Text und Nummer lassen sich ändern. Das PDF wird mit der gewählten Druckvorlage erzeugt, im Ordner des Kunden unter `sms/` abgelegt und ist über den Link **90 Tage** abrufbar (einstellbar). Die SMS erscheint in der **Versandanzeige** des Belegs (Zähler am Knopf, Verlauf mit Zustellstatus) und als Symbol in der Belegliste.

## WhatsApp kommt nicht an → SMS

Kann eine WhatsApp nicht zugestellt werden, geht **dieselbe Nachricht automatisch per SMS**:

- **sofort**, wenn der Versand scheitert: WhatsApp nicht eingerichtet, keine passende Vorlage, Meta lehnt ab;
- **nachträglich**, wenn Meta die WhatsApp später als nicht zustellbar meldet (z. B. Nummer ohne WhatsApp). Pro WhatsApp entsteht höchstens eine Ausweich-SMS.

Das gilt für den WhatsApp-Chat, Belege aus der Faktura (dann mit Link auf das PDF), Standort- und Adressnachrichten, Vorlagen sowie Termin- und HU-Erinnerungen. Rundschreiben haben ihre eigene Ausweichkette und sind ausgenommen.

Der Benutzer erfährt es sofort per Hinweis („WhatsApp nicht möglich – als SMS gesendet“, mit Grund). Spätere Ausweich-SMS meldet die **Info-Leiste** samt Hinweis; scheitert auch die SMS, wird das ebenfalls gemeldet. Im Verlauf sind Ausweich-SMS mit **„statt WhatsApp“** markiert, der Grund steht im Tooltip. Abschaltbar ist die Ausweiche in der Firmenkonfiguration.

## Antworten der Kunden

Mit dem Firmennamen als Absender sieht der Kunde sofort, von wem die SMS kommt – antworten kann er darauf aber nicht. Deshalb steht unter jeder SMS der **Antwort-Hinweis**, zum Beispiel:

> Antwort per Telegram: t.me/AutoprofisBot

Der Kunde tippt den Link an, startet den Bot und teilt seine Nummer; seine Nachrichten landen im [Telegram-Kundenchat](telegram.md). Der Hinweis zählt bei Zeichenzahl und Kosten mit und wird unter dem Eingabefeld angezeigt.

Wer Antworten per SMS haben möchte, mietet bei seven.io eine Eingangsnummer, trägt sie als Absender ein, leert den Antwort-Hinweis und richtet den Eingang ein (siehe unten).

## Kontakthistorie und Info-Leiste

- In der Kontakthistorie der CRM-Ansicht stehen SMS mit eigenem Filter neben Anrufen, WhatsApp, Briefen und E-Mails; Ausweich-SMS sind orange, nicht zugestellte rot. Die Liste aktualisiert sich live.
- Eingehende SMS erscheinen in der Info-Leiste; ein Klick öffnet den Reiter SMS des Kunden bzw. das Gespräch.
- SMS aus Rundschreiben landen ebenfalls im Verlauf.

## Einrichtung

Firmenkonfiguration → CRM → **SMS**:

| Feld | Bedeutung |
|------|-----------|
| API-Schlüssel (seven.io) | Zugang; leer = kein SMS-Versand |
| Absender | bis 11 Zeichen (z. B. Firmenname) oder eine Rufnummer. Auf einen Firmennamen kann man nicht antworten – dafür gibt es den Antwort-Hinweis. Keine Festnetznummer eintragen: Antworten darauf gehen verloren |
| Antwort-Hinweis | wird an jede SMS angehängt, Vorgabe „Antwort per Telegram: {telegram}“; `{telegram}` wird zu `t.me/<Bot>`, ohne eingerichteten Bot entfällt der Hinweis. Leer = kein Hinweis (z. B. mit gemieteter Eingangsnummer als Absender) |
| Kosten je SMS | nur für die Kostenanzeige |
| Ausweich-SMS | WhatsApp nicht zustellbar → SMS (Vorgabe an) |
| Beleglinks gültig (Tage) | Vorgabe 90 |
| Signierschlüssel (optional) | prüft zusätzlich die Signatur eingehender Webhooks |

Darunter zeigt die Karte **SMS-Versand und Eingang** Guthaben und den Verbrauch des Monats (gesendet, Kosten, statt WhatsApp, empfangen). **Eingang einrichten** meldet bei seven.io einen Webhook für **eingehende SMS** und **Zustellberichte** an. Ohne ihn bleibt der Status bei „gesendet“ und Antworten kommen nicht an. Die öffentliche https-Adresse des Servers wird dabei gespeichert und auch für die Beleglinks verwendet.

## Technik

- Tabelle `sms_messages` (Upstall crm): Richtung, Rufnummer, Kunde/Lieferant (über `cv_by_phone()` aus Haupt-, Zusatz- und Ansprechpersonen-Nummern), Text, Teile, Preis, Status, Quelle (`chat`, `faktura`, `mailing`, `fallback`), Mitarbeiter, gescheiterte WhatsApp (`whatsapp_message_id`, eindeutig), Datei und Link-Schlüssel mit Ablauf und erstem Abruf. Trigger `notify_sms_message` meldet per SSE an Kontakthistorie (`contact_history_change`) und Info-Leiste (`faktura_change`, `table = sms_messages`).
- Backend: `backend/api/sms/sms_lib.php` (Kern: Versand, Ausweiche, Links), `backend/api/sms/sms.php` (API), `backend/webhook/sms.php` (Eingang und Zustellberichte, Mandant und Geheimnis in der Adresse), `backend/webhook/beleg.php` (Abruf der Datei per Link). Die WhatsApp-Sendeaktionen laufen über `_waWithSmsFallback()` in `backend/api/whatsapp/whatsapp.php`, die nachträgliche Ausweiche sitzt im Meta-Webhook.
- Frontend: `src/core/views/sms/` (Seite), `src/core/components/sms/sms-chat.vue` (Verlauf und Eingabe, auch im Reiter), `src/core/utils/smsText.js` (Zeichensatz und Teile), Hinweis auf Ausweich-SMS zentral in `src/main.js`.
