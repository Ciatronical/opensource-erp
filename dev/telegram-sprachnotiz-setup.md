# Sprachnotiz → Anschlagtafel: Einrichtung

End-to-End-Funktion: Ein Mitarbeiter spricht per **Telegram** eine Sprachnachricht
ein → der Server transkribiert sie lokal mit **Whisper** → der Text erscheint
**live** auf der **Anschlagtafel** (Firmenbildschirm).

Konzept: `dev/konzept-sprachnotiz-anschlagtafel.md`

```
iPhone/Android (Telegram, Hold-to-talk)
  → https://melissa.spdns.de/webhook/telegram.php   (backend/webhook/telegram.php)
     → Whisper-Dienst 127.0.0.1:3002                 (backend/whisper/, siehe dessen README)
        → INSERT voice_notes  → Trigger pg_notify('voicenote_change')
           → SSE-Server 3001                          (backend/sse/sse-server.js)
              → Anschlagtafel-View                    (/anschlagtafel)
```

---

## Bestandteile (bereits im Code)

| Baustein | Ort |
|---|---|
| Whisper-Transkriptionsdienst | `backend/whisper/` (eigenes README) |
| DB-Tabelle `voice_notes` + Trigger | `backend/upstall/crm/company_schema.sql` |
| Telegram-Webhook | `backend/webhook/telegram.php` |
| API (Liste/Ausblenden) | `backend/api/voicenotes/` |
| SSE-Kanal `voicenote_change` | `backend/sse/sse-server.js` |
| Anschlagtafel-View | `src/core/views/anschlagtafel/`, Route `/anschlagtafel` |

---

## 1. Telegram-Bot anlegen

1. In Telegram **@BotFather** öffnen → `/newbot` → Namen vergeben.
2. BotFather liefert den **Bot-Token** (`123456:ABC-...`).
3. Ein beliebiges, langes **Webhook-Secret** ausdenken (z.B. 32 Zufallszeichen).

## 2. Konfiguration in der Firmen-DB (`defaults_oserp`)

Diese Schlüssel pro Mandant setzen (über die Konfig-Oberfläche oder per
`defaults_oserp`-Pflege — **kein** direktes Schema-DDL nötig):

| key | Wert | Pflicht |
|---|---|---|
| `telegram_bot_token` | Bot-Token von BotFather | ja |
| `telegram_webhook_secret` | das ausgedachte Secret | ja |
| `telegram_chat_map` | JSON `{"<chat_id>": "Mitarbeitername"}` | optional |
| `telegram_confirm_reply` | `1` (Standard) = Bestätigung zurücksenden, `0` = aus | optional |
| `whisper_url` | Standard `http://127.0.0.1:3002` | optional |
| `whisper_token` | nur falls der Whisper-Dienst `WHISPER_TOKEN` nutzt | optional |

> Der Webhook ordnet eine eingehende Nachricht **anhand des Secrets** dem
> richtigen Mandanten zu (Telegram sendet es als Header
> `X-Telegram-Bot-Api-Secret-Token`). Ohne passendes Secret wird die Nachricht
> verworfen.

## 3. Webhook bei Telegram registrieren (einmalig)

```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/setWebhook" \
     -d url="https://melissa.spdns.de/webhook/telegram.php" \
     -d secret_token="<WEBHOOK_SECRET>"
```

Prüfen:

```bash
curl "https://api.telegram.org/bot<BOT_TOKEN>/getWebhookInfo"
```

`url` muss gesetzt und `pending_update_count` niedrig sein.

## 4. Mitarbeiter-Zuordnung („kein Login")

- Jeder Mitarbeiter startet den Bot einmal (`/start`) und sendet eine Nachricht.
- Die **Chat-ID** steht danach in `voice_notes.telegram_chat_id` (oder via
  `getUpdates`). Diese ID → Name in `telegram_chat_map` eintragen, dann erscheint
  künftig der echte Name statt des Telegram-Profilnamens.

## 5. Anschlagtafel anzeigen

- Im Browser des Firmenbildschirms einmalig anmelden und `/anschlagtafel`
  öffnen (Vollbild). Die Seite hält die SSE-Verbindung und zeigt neue Notizen
  oben, live. Fällt SSE aus, wird alle 30 s nachgeladen.
- Eine Notiz per Häkchen ausblenden (Soft-Delete, `hidden=true`).

---

## Test ohne echtes Telegram

- **Whisper:** `curl -s --data-binary @audio.ogg http://127.0.0.1:3002/transcribe`
- **DB/Trigger:** INSERT in `voice_notes` löst `pg_notify('voicenote_change')` aus
  (mit `LISTEN voicenote_change;` in psql sichtbar).
- **Webhook:** POST eines simulierten Telegram-Updates mit korrektem
  `X-Telegram-Bot-Api-Secret-Token`-Header an `…/webhook/telegram.php`.

## Voraussetzungen / Betrieb

- Whisper-Dienst muss laufen: `systemctl --user status oserp-whisper`
  (siehe `backend/whisper/README.md`).
- SSE-Dienst muss laufen: `systemctl --user status oserp-sse`.
- Schema-Rollout: `voice_notes` kommt über den normalen Update-Mechanismus aus
  `company_schema.sql` auf alle Firmen-DBs.
