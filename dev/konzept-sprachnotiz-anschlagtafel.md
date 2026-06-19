# Konzept: Sprachnotiz vom Smartphone → Anschlagtafel-Bildschirm

**Stand:** 2026-06-17 — Konzeptphase, Umsetzung folgt.

## Ziel

Ein Mitarbeiter spricht unterwegs per Smartphone eine kurze Notiz ein. Diese
erscheint sofort als Text auf einem Bildschirm in der Firma (eigene
„Anschlagtafel"-Seite). Bedienung muss extrem einfach sein: **ein Knopf zum
Sprechen, kein Browser, kein Login bei jeder Nutzung.**

## Gesamtfluss

```
Smartphone (1 Button)
   → Sprachnachricht
      → OSERP-Endpunkt (backend/api/)
         → Transkription (Whisper, server-seitig)
            → speichern (DB)
               → SSE-Event (Port 3001)
                  → Anschlagtafel-Vue-Seite zeigt Text live an
```

## Festgelegte Entscheidungen

- **Transkription:** server-seitig. Wunsch: **selbst gehostetes Whisper**
  (kostenlos), statt der bisher genutzten Cloud-API.
- **Anzeige-Ziel:** **eigene „Anschlagtafel"-Seite** (neue Vue-View), nicht in
  die Kalender-Tagesansicht integriert.
- **Übertragung vom Handy — abhängig von der Plattform des Kunden:**
  - **iPhone → Telegram-Bot.** Sprachnachricht (Hold-to-talk), kein Login, kein
    Browser. Keine App zu bauen, keine Apple-Signierung/App-Store-Hürde. Audio
    wird server-seitig per Whisper transkribiert.
  - **Android → kleine native App.** Ein großer Sprechen-Knopf, nutzt die
    On-Device-Spracherkennung (gratis, offline), sendet nur Text + Geräte-Token.
    APK lässt sich direkt aufspielen (kein Play-Store nötig).
- **Anzeige-Transport:** vorhandener **SSE-Echtzeit-Server auf Port 3001**.

## Offen (morgen klären)

- **Smartphone-Plattform des Kunden** — wird vom User nachgereicht. Bestimmt den
  Übertragungsweg: iPhone → Telegram, Android → kleine native App (siehe oben).

## Plattform-Hintergrund (iPhone vs. Android)

- On-Device-Spracherkennung gratis auf **beiden**: Android (Gboard-Engine),
  iOS (`SFSpeechRecognizer`, `requiresOnDeviceRecognition`, ab iOS 13, Deutsch
  unterstützt, Sprachpaket einmal laden).
- Unterschied liegt in der **App-Verteilung**, nicht in der Erkennung:
  - Android: APK direkt aufspielbar.
  - iPhone: nur App Store **oder** Xcode-Signierung; freie Signatur läuft nach
    7 Tagen ab, sonst Apple-Developer-Account (99 $/Jahr). → Darum für iPhone
    der Telegram-Weg statt eigener App.

## Transkription kostenlos — die Optionen (Hintergrund)

**A) On-Device auf dem Handy (gratis, offline)**
- Android: eingebaute On-Device-Spracherkennung (wie Gboard-Diktat).
- iOS: eingebaute Diktierfunktion, ebenfalls on-device.
- Es würde dann nur fertiger **Text** gesendet → winziger POST, keine Serverlast.
- Nachteil: Qualität bei Fachbegriffen/Namen schwächer; nur über native
  Plattform-API zuverlässig (nicht über Web Speech API im Browser).

**B) Selbst gehostetes Whisper am Server (gewählt)**
- `whisper.cpp` oder `faster-whisper`, lokal, kostenlos (nur Hardware).
- Qualität deutlich besser, v.a. Deutsch + Fachbegriffe (`medium`/`large`).
- Kostet etwas CPU/GPU + wenige Sekunden pro Aufnahme.
- Handy sendet Audiodatei, Server transkribiert.
- Hinweis: im Repo existiert bereits `dev/install-nerd-dictation.sh` — vor
  Umsetzung prüfen, ob das wiederverwendbar ist.

**Stolperstein:** Web Speech API im Browser ist NICHT zuverlässig on-device
(Android-Chrome sendet Audio an Google). Echtes On-Device nur via native App.

## „Ein Button, kein Browser, kein Login" — Lösungsansatz

- **Telegram-Bot** (gewählter Weg): Sprachnachricht = Hold-to-talk, der Login
  steckt bereits im Messenger, kein Browser. Voice-Note → Bot-Webhook → OSERP
  lädt die Audiodatei → Whisper transkribiert → DB → SSE.
- **„Kein Login":** Zuordnung Telegram-Chat-ID ↔ Mitarbeiter wird einmalig
  eingerichtet (z.B. Start-Code), danach kein Anmelden mehr.

## Umsetzungs-Bausteine — ✅ ALLE ERLEDIGT

Einrichtungs-/Betriebsanleitung: `dev/telegram-sprachnotiz-setup.md`.

1. **Telegram-Bot anbinden:** ✅ `backend/webhook/telegram.php` (erreichbar unter
   `/webhook/telegram.php`). Kein Session-Check; ordnet die Nachricht per
   `X-Telegram-Bot-Api-Secret-Token` dem Mandanten zu, lädt die Voice-Datei
   (OGG/Opus) herunter, ruft Whisper, speichert, sendet optional Bestätigung.
2. **Whisper lokal:** ✅ `faster-whisper`-Dienst unter `backend/whisper/`
   (`127.0.0.1:3002`, Modell `large-v3-turbo`/CPU, systemd-User-Service
   `oserp-whisper`). Doku: `backend/whisper/README.md`. (`nerd-dictation` nutzt
   VOSK — andere Engine, nicht verwendet.)
3. **Speichern:** ✅ Tabelle `voice_notes` in
   `backend/upstall/crm/company_schema.sql` (kein direktes DDL, kein ALTER).
   API zum Lesen/Ausblenden: `backend/api/voicenotes/`.
4. **SSE-Event feuern:** ✅ DB-Trigger `voice_notes_notify` →
   `pg_notify('voicenote_change', …)`; `sse-server.js` lauscht darauf und sendet
   es als Named Event `voicenote_change`.
5. **Anschlagtafel-Vue-View:** ✅ `src/core/views/anschlagtafel/`, Route
   `/anschlagtafel`. Vollbild, neueste oben, live über SSE, Polling-Fallback,
   vue-i18n (de/en + Route in allen 21 Sprachen), echte Umlaute.

**Noch auszuführen vor Produktivnutzung:** Schema-Rollout von `voice_notes` auf
die Firmen-DB(s) über den normalen Update-Mechanismus (aus `company_schema.sql`),
und die `telegram_*`-Konfigschlüssel je Mandant setzen (siehe Setup-Doku).

## Wiederverwendbare vorhandene Bausteine

- SSE-Echtzeit-Server Port 3001 (`sse-server.js`).
- KI-Pipeline-Muster aus der Eingangsrechnungs-/Beleg-Verarbeitung.
- `dev/whatsapp-setup.md` als Referenz für eine ähnliche Messenger-Anbindung.



Meine Empfehlung
Wenn dir „kostenlos, ein Button, kein Browser" wirklich wichtig ist:

Kleine native Android-App mit genau einem großen Sprechen-Knopf, die die On-Device-Spracherkennung nutzt und nur den fertigen Text + Geräte-Token an OSERP postet. Null laufende Kosten, keine Serverlast, offline-fähig, und auf dem Bildschirm landet der Text wie besprochen live über SSE.

Whisper am Server würde ich nur nehmen, wenn dir die Erkennungsqualität bei Namen/Fachbegriffen wichtiger ist als der Mehraufwand — dann aber die App trotzdem nativ, nur dass sie Audio statt Text schickt.

Eine Sache entscheidet den Rest: