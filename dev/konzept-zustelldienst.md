# Konzept: Zentraler Zustelldienst („Kunden erreichen“)

Stand: 01.11.2026, Entwurf zur Freigabe. Nichts davon ist umgesetzt, außer dem Kern im Rundschreiben.

## Ausgangslage

Schriftliche Benachrichtigungen an Kunden sind heute an WhatsApp gebunden: HU-Erinnerung, Terminbestätigung,
Belegversand, Chat-Antworten rufen direkt die WhatsApp-Funktionen auf. Fehlt WhatsApp beim Kunden oder ist das
24-Stunden-Fenster zu, bleibt die Nachricht liegen oder kostet eine Vorlage, obwohl Telegram oder E-Mail da wären.

Im Rundschreiben gibt es seit 31.10.2026 bereits die Logik, die hier systemweit gelten soll:

- Messenger in Reihenfolge (WhatsApp → Telegram → SMS), je Kunde der erste verfügbare.
- Gelernter Kanal je Kunde (`customer_ext.reach_channel`, `reach_failed`): der zuletzt erfolgreiche Weg zuerst,
  der zuletzt gescheiterte nachrangig, eine nie bestätigte WhatsApp-Nummer zuletzt.
- Ausweichkette beim Scheitern: WhatsApp kaputt → Telegram → SMS, ohne Zutun des Mitarbeiters.
- E-Mail immer zusätzlich (oder als Rückfall), Brief als letzter Ausweg.
- Mehrere Kanäle gleichzeitig (Messenger + E-Mail + Brief).

## Ziel

Ein Aufruf, keine Kanalentscheidung im Fachcode:

```php
notify($db, ['cv_src' => 'C', 'cv_id' => 1126], [
    'text'      => 'Ihr Auto ist fertig …',      // für Messenger/SMS
    'subject'   => 'Ihr Auto ist fertig',         // E-Mail/Brief
    'html'      => '<p>…</p>',                    // optional für E-Mail
    'purpose'   => 'order_done',                  // HU-Erinnerung, Terminbestätigung, Beleg …
    'record'    => ['type' => 'invoice', 'id' => 4711],   // optional: Beleg als Anhang/Verknüpfung
    'channels'  => 'auto',                        // auto | ['telegram','email'] | …
]);
```

Der Dienst entscheidet: Welche Messenger sind beim Kunden verfügbar, welcher hat zuletzt funktioniert, ist das
WhatsApp-Fenster offen, darf eine Vorlage Geld kosten (Firmenregel je Zweck), geht E-Mail zusätzlich, muss ein
Brief raus. Er sendet, weicht beim Scheitern aus, protokolliert jeden Zweig und lernt den Kanal.

## Bausteine

1. **`backend/api/lib/notify.php`** – aus `mailings.php` herausgelöst und verallgemeinert:
   `mailingInfoCtes()` → `notifyReachInfo()` (Erreichbarkeit je Kunde, eine SQL),
   `mailingResolveChannels()` → `notifyPlan()` (Kanalwahl inkl. Lernen und Kosten),
   `mailingSendVia()`/`mailingNextMessenger()`/`mailingLearnReach()` → `notifySend()` mit Ausweichkette.
   Das Rundschreiben wird der erste Nutzer des Dienstes (Stapel), alle anderen Stellen rufen ihn für einen Kunden auf.
2. **Firmenregeln je Zweck** (defaults_oserp, Tab CRM → „Kunden erreichen“): Für jeden Zweck (Terminbestätigung,
   HU-Erinnerung, Auftrag fertig, Beleg, Rundschreiben, Chat) die Vorgabe: Messenger-Reihenfolge, Vorlage erlaubt
   (kostenpflichtig) ja/nein, E-Mail immer/als Rückfall/aus, Brief aus/Rückfall, Anruf ja/nein, Zeitfenster.
3. **Kundenwunsch** (Kundenmaske → Reiter Kommunikation): „Bitte nur per E-Mail“, „kein WhatsApp“, bevorzugter
   Kanal – schlägt die Firmenregel; dazu sichtbar: gelernter Kanal, letzte Zustellungen je Weg, Telegram-Verknüpfung.
4. **Protokoll `notifications_oserp`**: je Zustellung Zweck, Kunde, Kanal, Ziel, Status, Fehler, Referenz, Beleg.
   Erscheint in der Kontakthistorie des Kunden (heute nur WhatsApp und E-Mail dort) und speist das Lernen.
5. **Kanäle**: WhatsApp (vorhanden), Telegram (vorhanden, Verknüpfung per Bot), SMS (seven.io, seit 01.11.2026 im
   Rundschreiben, Zugang fehlt noch), E-Mail (vorhanden), Brief (vorhanden über Postmodul),
   **automatischer Anruf** (neu, siehe unten).
6. **Oberfläche**: Kanäle treten in den Hintergrund. Statt „per WhatsApp senden“ gibt es einen Knopf „Kunden
   informieren“ mit Vorschau „geht per Telegram + E-Mail“ und der Möglichkeit, Wege ab- oder dazuzuwählen.
   Ergebnis als Chips in der Kontakthistorie und als Hinweis in der Info-Leiste, wenn etwas scheitert.

## Automatischer Anruf

Für Kunden ohne jeden schriftlichen Weg oder für dringende Dinge (Auto fertig, Termin heute):
Text-zu-Sprache-Anruf mit kurzer Ansage und Tastenquittung („Drücken Sie 1, wenn Sie den Termin bestätigen“).
Umsetzbar über die vorhandene Telefonie, wenn sie Ausgangsrufe mit Ansagen erlaubt (Asterisk/FritzBox-Anbindung
prüfen), sonst über einen Dienst wie sipgate.io oder Twilio Voice (Kosten je Minute). Vorschlag: zweite Stufe,
nach dem Zustelldienst, da Telefonie-Anbindung und Datenschutz (Ansage nur mit Nummernkennung der Firma) zu klären sind.

## Migration

Reihenfolge mit geringstem Risiko: (1) Dienst aus dem Rundschreiben herauslösen, Rundschreiben darauf umstellen,
(2) Protokolltabelle und Kontakthistorie, (3) Terminbestätigung und „Auto fertig“ auf den Dienst, (4) HU-Erinnerung
und Belegversand, (5) Kundenwunsch in der Kundenmaske, (6) Anruf. Jede Stufe einzeln testbar gegen ap_dev.

## Aufwand (grob)

Stufe 1 bis 2: zwei bis drei Arbeitstage. Stufe 3 bis 5: je nach Zahl der Aufrufstellen (heute etwa zehn direkte
WhatsApp-Aufrufer) ein bis zwei Tage je Stufe. Anruf: abhängig von der Telefonie, frühestens nach Klärung.
