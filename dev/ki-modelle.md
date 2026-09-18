# Cloud-KI: Modellwahl der Assistenten

Welches Claude-Modell ein KI-Assistent benutzt, ist einstellbar — je Mandant in
der Firmenkonfiguration und, bei den Assistenten mit Prompt, zusätzlich vom
Benutzer für seine Sitzung. Diese Seite beschreibt, wo die Modelle stehen, wie
sie aufgelöst werden und was beim Aufnehmen eines neuen Modells zu prüfen ist.

Die lokale KI (Ollama) hat damit nichts zu tun, sie hat ihren eigenen Schlüssel
`llm_model` — siehe `lokale-ki-ollama.md`.

## Überblick

| Baustein | Ort |
|---|---|
| Positivliste der Modelle, Registry der Assistenten, Auflösung | `backend/api/lib/ai_model.php` |
| Gleiche Liste für die Oberfläche | `src/core/constants/aiModels.js` |
| Einstellung je Mandant | Tabelle `defaults_oserp`, ein Schlüssel je Assistent |
| Einstellung je Sitzung | `src/core/stores/ai-model.store.js` (sessionStorage) |
| Auswahl in der Firmenkonfiguration | Tab „KI und Gesundheit", `src/core/views/config/tabs/ai-health.tab.vue` |
| Auswahl am Prompt | `src/core/components/ai-model-button.vue` |

Die beiden Listen — Backend und Frontend — müssen zusammenpassen. Das Backend
prüft jede Modellangabe gegen seine eigene Liste und verwirft Unbekanntes; ein
nur im Frontend ergänztes Modell liesse sich also auswählen, würde aber nie
benutzt.

## Assistenten

| Assistent | Schlüssel in `defaults_oserp` | Vorgabe | Prompt |
|---|---|---|---|
| Fahrzeug-Chat | `car_chat_ai_model` | Haiku 4.5 | ja |
| Weroni | `weroni_ai_model` | Haiku 4.5 | ja |
| Verkaufstext | `sales_text_ai_model` | Haiku 4.5 | ja |
| Positionsvorschläge | `ai_positions_ai_model` | Haiku 4.5 | ja |
| Dokument-Chat (Dateimanager) | `filemanager_ai_model` | Haiku 4.5 | ja |
| Belegerkennung | `accounting_ai_model` | Opus 5 | nein |
| Visitenkarten-Scan | `business_card_ai_model` | Haiku 4.5 | nein |
| Telefonsuche | `phone_search_ai_model` | Sonnet 5 | nein |
| Anruf-Auswertung | `call_transcript_ai_model` | Haiku 4.5 | nein |
| Liquiditätsprognose | `banking_ai_model` | Opus 5 | nein |

„Prompt" heisst: Der Assistent hat eine Benutzereingabe, an der die Modellwahl
sitzt. Die Wahl dort gilt nur für die Sitzung im Browser, wird im sessionStorage
gehalten und erreicht die Datenbank nie.

## Auflösung

`resolveAiModel($config, $assistent, $override)` entscheidet in dieser
Reihenfolge:

1. Wahl am Prompt (Parameter `ai_model` im Request)
2. Einstellung des Mandanten aus `defaults_oserp`
3. Vorgabe aus der Registry

Werte ausserhalb der Positivliste werden verworfen und mit `DLOG_WRN`
protokolliert, statt an die API zu gehen. So löst eine veraltete Modell-ID in
der Datenbank keinen HTTP-Fehler aus, sondern fällt still auf die Vorgabe
zurück. Die Funktion arbeitet auf bereits geladenen Konfigurationswerten — die
aufrufende API-Funktion nimmt den Schlüssel über `aiModelConfigKey()` in ihr
vorhandenes SELECT auf und spart sich eine zweite Abfrage.

## Verträglichkeit einzelner Modelle

Nicht jedes Modell verträgt jeden Aufruf. Die Registry hat dafür je Assistent
ein Feld `unsupported`; was dort steht, ist für diesen Assistenten weder
auswählbar noch über die Datenbank erzwingbar.

### Claude Fable 5.1 und die Liquiditätsprognose

Die Liquiditätsprognose (`backend/api/banking/banking_ai.php`) erzwingt den
Aufruf ihres Werkzeugs:

```php
'tool_choice' => ['type' => 'any'],
```

Damit bekommt sie die Prognose als geprüftes JSON zurück statt als Fliesstext.
**Claude Fable 5.1 lehnt einen erzwungenen Werkzeugaufruf mit HTTP 400 ab** —
`tool_choice` `any` und `tool` gibt es dort nicht mehr. Deshalb steht bei
`banking` in beiden Registries `unsupported: ['claude-fable-5-1']`.

Wer Fable auch dort benutzen will, muss den Aufruf umbauen: `tool_choice` auf
`auto` setzen und im Prompt ausdrücklich auf das Werkzeug hinweisen, wahlweise
mit `strict: true` am Werkzeug oder mit strukturierter Ausgabe
(`output_config.format`). Das ändert allerdings das Verhalten der heute
genutzten Modelle mit: Ein Werkzeugaufruf ist dann nicht mehr garantiert, und
der Code muss den Fall abfangen, dass die Antwort keinen `tool_use`-Block
enthält.

### Weroni ist nicht betroffen

Weroni benutzt ebenfalls Werkzeuge, aber ohne Zwang: Die Antwort des Modells
wird als `assistant`-Nachricht angehängt und mit `tool_result` beantwortet
(`backend/api/weroni/weroni.php`). Das ist der reguläre Werkzeug-Ablauf, kein
Prefill — Fable verträgt ihn.

### Was sonst noch unverträglich wäre

Diese Dinge sind in den Anthropic-Aufrufen des Projekts **nicht** enthalten,
verhindern aber Fable, sobald sie jemand ergänzt:

- `temperature`, `top_p`, `top_k` — auf Fable 5.1 und der Opus-5-Familie
  entfernt, ein Aufruf damit endet mit HTTP 400. Das einzige `temperature` im
  Quelltext gehört zur lokalen Ollama-Anbindung (`backend/api/lib/llm.php`) und
  ist davon nicht berührt.
- Assistant-Prefill, also eine `assistant`-Nachricht als letzter Eintrag in
  `messages`.
- `thinking` mit `budget_tokens`. Fable denkt immer; der Parameter gehört
  weggelassen.

Ausserdem: Fable kann eine Anfrage aus Sicherheitsgründen ablehnen. Die Antwort
kommt dann mit HTTP 200 und `stop_reason: "refusal"` ohne Inhalt. Die
Assistenten werten das derzeit nicht eigens aus — beim Benutzer käme eine leere
Antwort an.

## Ein neues Modell aufnehmen

1. Modell-ID in `aiModelChoices()` in `backend/api/lib/ai_model.php` eintragen.
2. Gleiche ID mit Anzeigename in `AI_MODELS` in
   `src/core/constants/aiModels.js` eintragen.
3. Kurzbeschreibung unter `aiModels.hints.<name>` in **allen** Sprachdateien
   unter `src/core/locales/` ergänzen.
4. Verträglichkeit prüfen — die Punkte aus dem Abschnitt oben. Passt das Modell
   für einen Assistenten nicht, gehört es in dessen `unsupported`, in beiden
   Registries, mit Begründung als Kommentar.
5. Modell-IDs tragen **kein** Datums-Suffix. `claude-haiku-4-5-20251001` und
   Ähnliches liefert HTTP 404.

Modelle der Anthropic-API, Stand September 2026, Preise je Million Token
Eingabe/Ausgabe:

| Modell | ID | Preis |
|---|---|---|
| Claude Fable 5.1 | `claude-fable-5-1` | 10 / 50 USD |
| Claude Opus 5 | `claude-opus-5` | 5 / 25 USD |
| Claude Sonnet 5 | `claude-sonnet-5` | 2 / 10 USD |
| Claude Haiku 4.5 | `claude-haiku-4-5` | 1 / 5 USD |

## API-Schlüssel

Der Schlüssel steht als `anthropic_api_key` in `defaults_oserp`, also je
Mandant, und wird im Tab „KI und Gesundheit" gepflegt. Die Liquiditätsprognose
bevorzugt die Umgebungsvariable `ANTHROPIC_API_KEY`, falls gesetzt; alle
übrigen Assistenten lesen ausschliesslich aus der Datenbank. Die Transkription
der Anruf-Auswertung braucht zusätzlich `openai_api_key` (Whisper).

Zwei Punkte, die man kennen sollte: Der Schlüssel liegt unverschlüsselt in der
Tabelle, und `getDefaults` hält ihn nicht zurück — er geht bei jedem Öffnen der
Firmenkonfiguration an den Browser. Wer die Firmenkonfiguration öffnen darf,
kann ihn auslesen. Für die drei Shop-Geheimnisse gibt es in
`backend/api/oserp_config/defaults.php` bereits das Muster, einen Wert
zurückzuhalten und stattdessen nur „ist hinterlegt" zu melden.
