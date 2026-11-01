---
title: Rundschreiben
summary: Eine Nachricht an bis zu 300 Kunden und Lieferanten – per Telegram, WhatsApp oder E-Mail, kostenbewusst und in Etappen, mit gespeicherten Verteilern
group: core
category: Kommunikation
order: 46
status: stable
since: 2026-10-30
keywords: [Rundschreiben, Serienbrief, Newsletter, Verteiler, Telegram, WhatsApp, E-Mail, Weihnachtsfeier, Neujahrsgrüße, Etappen]
---

# Rundschreiben

Unter **Kontakt → Rundschreiben** geht eine Nachricht an viele Kunden oder Lieferanten auf einmal – die Einladung zur Weihnachtsfeier, Neujahrsgrüße, geänderte Öffnungszeiten. Gesendet wird über **WhatsApp**, **Telegram**, **E-Mail** und, wenn ein Postdienst eingerichtet ist, per **E-Brief**: Als Nachrichtenkanal gilt der erste Messenger in der gewählten Reihenfolge (Vorgabe WhatsApp vor Telegram vor SMS), der beim Empfänger verfügbar ist. OSERP **lernt** je Kunde, worüber er zuletzt erreicht wurde (`customer_ext.reach_channel`), und nimmt diesen Weg zuerst; ein zuletzt gescheiterter Weg kommt nachrangig, eine noch nie bestätigte WhatsApp-Nummer ganz zuletzt. **Scheitert ein Messenger beim Versand, springt der nächste verfügbare ein** (Ausweichkette WhatsApp → Telegram → SMS in der gewählten Reihenfolge); E-Mail geht nach Wahl **immer zusätzlich** an alle mit Adresse (Vorgabe), nur als Rückfall ohne Messenger, oder gar nicht. Empfängerlisten lassen sich als **Verteiler** speichern und wiederverwenden, jedes Rundschreiben dient als Vorlage für das nächste.

Außerhalb des kostenlosen 24-Stunden-Fensters geht WhatsApp per Meta-Vorlage (kostenpflichtig, Vorgabe: die Chat-Vorlage der Firma); wer das nicht will, wählt „Nicht per WhatsApp senden“. Zwei Dinge sind fest eingebaut: **keine unbemerkten WhatsApp-Kosten** (die Planung zeigt Anzahl und Schätzung vor dem Start) und **keine Sperre** durch Meta, Telegram oder den Mailanbieter. Deshalb ist ein Rundschreiben auf **300 Empfänger** begrenzt und läuft in Etappen.

## So entsteht ein Rundschreiben

1. **Nachricht**: Titel (intern), Betreff (nur E-Mail) und Text. Bausteine werden per Klick eingefügt und beim Versand je Empfänger gefüllt: `{{anrede}}` (z. B. „Sehr geehrte Frau Müller“ – aus Ansprechperson oder Kundenanrede, in der Sprache des Kunden), `{{name}}`, `{{kundennummer}}`, `{{firma}}`, `{{mitarbeiter}}`. Die Vorschau zeigt den fertigen Text für einen echten Empfänger. Für E-Mail kann zusätzlich eine gestaltete Fassung (Überschriften, Fett, Listen) hinterlegt werden; Telegram und WhatsApp erhalten immer den einfachen Text.
2. **Empfänger**: per Suche (Name, Nummer, Ort, E-Mail, Telefon), über **Vorschlagen** (Kunde/Lieferant, Kundentyp, Umsatz in den letzten N Monaten, Ort, erreichbarer Kanal – mit Trefferzahl vor dem Übernehmen) oder aus einem **Verteiler**. Die Suche reiht Treffer nach Kundennummer und Namensanfang vor Ort, E-Mail und Rufnummer; der Vorschlag liefert erreichbare Kunden zuerst, damit die Obergrenze nicht mit Kunden ohne Kanal gefüllt wird. Gespeicherte **Verteiler** liegen als An/Aus-Chips direkt über der Liste: anschalten holt die Mitglieder herein, abschalten nimmt genau diese wieder heraus (einzeln hinzugefügte bleiben). **Dynamische Verteiler** entstehen im Vorschlagsdialog aus den Kriterien („Dynamisch speichern“): Mitglieder werden beim Anschalten und beim Versandstart neu ermittelt, neue Kunden kommen also von selbst dazu. Läuft ein Versand, ist die Liste eingefroren und sagt das mit dem Knopf „Pausieren und bearbeiten“. Unter dem Namen stehen Kundennummer, Ort, WhatsApp-Nummer und E-Mail-Adresse. Zu jedem Empfänger stehen die erreichbaren Kanäle als Symbole: Telegram, WhatsApp (grün = kostenloses Fenster offen, orange = nur per Vorlage), E-Mail. Die Liste lässt sich als Verteiler speichern oder einen bestehenden aktualisieren.
3. **Versand**: Reihenfolge der Kanäle (ein- und ausschaltbar, verschiebbar), Verhalten bei WhatsApp ohne offenes Fenster, Etappen und Zeitfenster. Die Planung darunter zeigt, wie viele Empfänger worüber erreicht werden, wer übersprungen wird (kein Kanal, gleiches Ziel) und was es voraussichtlich kostet und dauert.
4. **Starten**: Der Bestätigungsdialog fasst alles zusammen. Die erste Etappe geht sofort los (im Zeitfenster), der Rest läuft im Hintergrund weiter – auch bei geschlossener Seite. Die Seite zeigt den Fortschritt je Empfänger (gesendet, fehlgeschlagen mit Grund, übersprungen) und erlaubt **Pausieren**, **Fortsetzen** und **Abbrechen**; fehlgeschlagene lassen sich später erneut versuchen.

Gespeichert wird automatisch, 1,5 Sekunden nach der letzten Änderung. Während des Versands ist nur noch der Text änderbar (gilt für die restlichen Etappen).

## Kosten vermeiden

| Kanal | Kosten | Voraussetzung beim Empfänger |
|-------|--------|------------------------------|
| **Telegram** | keine | Der Kunde hat dem Firmen-Bot einmal seine Rufnummer geteilt (siehe unten) |
| **WhatsApp (Freitext)** | keine | Der Kunde hat in den letzten 24 Stunden an die Firma geschrieben (offenes Service-Fenster) |
| **WhatsApp (Vorlage)** | je Nachricht, von Meta berechnet | Mobilnummer vorhanden, kein offenes Fenster; genehmigte Vorlage mit zwei Platzhaltern (vorbelegt, abwählbar) – der Baustein „Anrede“ entfällt, weil die Vorlage selbst grüßt |
| **SMS** | je SMS, vom Dienst berechnet | seven.io-Zugang (Firmenkonfiguration → CRM → Rundschreiben: API-Schlüssel, Absender) und Mobilnummer; als letzter Messenger-Ausweg |
| **E-Mail** | keine | gültige E-Mail-Adresse (Stammsatz, Zusatzadressen oder Ansprechperson); je nach Einstellung zusätzlich zum Messenger, nur als Rückfall oder aus |
| **Brief (E-Brief)** | je Brief, vom Postdienst berechnet | Postdienst eingerichtet (Firmenkonfiguration → CRM → Briefversand) und vollständige Anschrift; Einstellung „Nicht per Brief“ (Vorgabe), „nur wer sonst nicht erreichbar ist“ oder „immer zusätzlich“. Jeder Brief wird als kivitendo-Brief beim Kunden angelegt und über das Postmodul eingeliefert; Kosten je Brief unter CRM → Rundschreiben |

Standard ist **„Nicht per WhatsApp senden“** für Empfänger ohne offenes Fenster – sie werden über den nächsten Kanal erreicht. Wer bewusst zahlen will, wählt eine Vorlage (Konvention wie im Chat: erster Platzhalter Name, zweiter der Text; Meta erlaubt dort keine Zeilenumbrüche). Mit dem Wert **Kosten je WhatsApp-Vorlagennachricht** (Firmenkonfiguration → CRM → Rundschreiben) zeigt die Planung den voraussichtlichen Betrag.

Als WhatsApp-Ziel gilt nie eine Festnetznummer: bevorzugt eine Nummer, über die schon WhatsApp lief, dann als mobil bezeichnete Zusatznummern, dann Nummern, die nach Mobilfunk aussehen.

## Nicht gesperrt werden

- **Etappen**: je Etappe N Nachrichten (Vorgabe 20), danach eine Pause (Vorgabe 10 Minuten), dazwischen ein zufälliger Abstand von 1,5 bis 4 Sekunden je Nachricht.
- **Zeitfenster**: Versand nur zwischen zwei Uhrzeiten (Vorgabe 9 bis 20 Uhr) – nichts geht nachts raus.
- **Ein Empfänger, eine Nachricht**: gleiche Ziele (eine Nummer bei zwei Kunden) werden nur einmal bedient.
- **Notbremse**: nach fünf Fehlern in Folge pausiert das Rundschreiben von selbst und zeigt den Grund; Telegram-Drosselungen (429) werden abgewartet.
- **Höchstens 300 Empfänger** je Rundschreiben und je Verteiler.

## Telegram: Kunden verbinden

Telegram kennt keine Rufnummern, nur Chat-IDs. Der Kunde öffnet `t.me/<Bot-Benutzername>` (Link auf Rechnung, Website, Signatur), drückt **Start** und bekommt vom Bot die Bitte, seine **Telefonnummer zu teilen** – ein Knopf, nichts zu tippen. Die Nummer wird gegen Kunden und Lieferanten geprüft (Stammsatz, Zusatznummern, Ansprechpersonen), die Chat-ID in `customer_ext.telegram_chat_id` bzw. `vendor_ext.telegram_chat_id` gespeichert und der Kunde bestätigt. Ab dann erreichen ihn Rundschreiben kostenlos per Telegram. Voraussetzung: Telegram ist eingeschaltet und der Bot-Token hinterlegt (Firmenkonfiguration → CRM → Telegram), der Webhook `backend/webhook/telegram.php` ist eingerichtet (siehe [Telegram](telegram.md)). Den Bot-Benutzernamen für den Hinweis „t.me/…“ ermittelt OSERP beim ersten Aufruf der Übersicht selbst bei Telegram und trägt ihn in die Konfiguration ein.

## Hintergrund

Die Etappen holt `backend/cli/mailings.php` – alle fünf Minuten per Cron (siehe `install/README.md`). Solange die Rundschreiben-Seite offen ist, stößt sie fällige Etappen zusätzlich selbst an; der Cron sorgt dafür, dass es ohne Browser weitergeht. Jede Etappe wird atomar beansprucht, Cron und Seite kommen sich nicht in die Quere.

Ist ein Rundschreiben abgeschlossen oder wegen Fehlern pausiert, meldet es sich in der **Info-Leiste** der Navbar (mit Toast, sobald es passiert; wegklickbar wie Anrufe oder E-Mails) und führt per Klick zum Rundschreiben. In der Übersicht zeigt die Spalte **Kanäle** je Weg die Zahl der gesendeten Nachrichten; Hover zeigt gesendet/fehlgeschlagen/wartend/übersprungen, Klick öffnet die Aufschlüsselung mit Sprung in die danach gefilterte Empfängerliste.

Was gesendet wurde, erscheint in der **Kontakthistorie** des Kunden: WhatsApp über `whatsapp_messages`, E-Mails über das E-Mail-Journal. Telegram-Sendungen stehen im Rundschreiben selbst.

**Rechte**: wie bei den Briefen `sales_letter_edit` oder `purchase_letter_edit`.

Tabellen: `mailings`, `mailing_recipients`, `mailing_lists`, `mailing_list_members` (Schema `backend/upstall/crm/company_schema.sql`); API `backend/api/mailings/`; Oberfläche `src/core/views/mailings/`.
