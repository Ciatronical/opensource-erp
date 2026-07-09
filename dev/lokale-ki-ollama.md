# Lokale KI (selbst gehostet) – Ollama + 14B-Modell

Selbst gehosteter LLM-Dienst für OSERP und Thunderbird. Läuft komplett lokal,
keine Cloud, keine Daten verlassen den Rechner.

## Überblick

| Baustein | Wert |
|---|---|
| Server | Ollama (OpenAI-kompatibel) |
| Endpunkt | `http://127.0.0.1:11434` (nativ `/api/...`, OpenAI `/v1/chat/completions`) |
| Modell (Standard) | `qwen3:30b-a3b-instruct-2507-q4_K_M` (MoE, ~19 GB, ~3B aktiv/Token → schnell + gutes Deutsch) |
| Modell (Qualität/Batch) | `qwen2.5:14b` (Q4_K_M, ~9 GB) – langsamer, weiter verfügbar |
| Dienst | systemd-User-Unit `oserp-ollama` |
| Startskript | `/home/work/ollama-serve.sh` (wählt automatisch iGPU oder CPU) |
| Modell-Store | `~/.ollama/models` (von beiden Builds gemeinsam genutzt) |

## Zwei Builds, ein Dienst

Auf diesem Rechner (Intel Core Ultra 5 125U, Meteor Lake, **keine dedizierte GPU**)
läuft der Dienst über **Upstream-Ollama mit Vulkan** (`/home/work/ollama-cpu`), gesteuert
von `/home/work/ollama-serve.sh`. Die iGPU wird per `OLLAMA_VULKAN=1` + `OLLAMA_IGPU_ENABLE=1`
genutzt (Upstream-Ollama ignoriert integrierte GPUs sonst). Fehlt die GPU, fällt dasselbe
Binary sauber auf CPU zurück – kein harter Abbruch.

Voraussetzungen (bereits installiert): Intel-GPU-Runtime + Vulkan-Treiber
(`intel-opencl-icd libze-intel-gpu1 libze1`, `mesa-vulkan-drivers`), Benutzer in
`render`/`video`. Kontrolle: `vulkaninfo --summary | grep deviceName` → „Intel(R) Graphics (MTL)".

Der frühere **IPEX-LLM/SYCL-Build** liegt noch unter
`/home/work/ollama-ipex/ollama-ipex-llm-2.2.0-ubuntu`, wird aber nicht mehr verwendet:
Er ist **genauso schnell wie Vulkan** (siehe Benchmark), aber fragiler (Hart-Absturz ohne Treiber).

### Falls die iGPU-Runtime neu aufgesetzt werden muss (sudo + Re-Login)

```bash
sudo apt install -y intel-opencl-icd libze-intel-gpu1 libze1 mesa-vulkan-drivers vulkan-tools clinfo
sudo usermod -aG render,video work      # NICHT $USER – in einer root-Shell wäre das root!
sudo loginctl enable-linger work
# danach als 'work' ab-/anmelden, dann:
systemctl --user restart oserp-ollama
journalctl --user -u oserp-ollama -n 20 | grep -i vulkan   # "type=iGPU ... Intel(R) Graphics (MTL)"
```

## Dienst bedienen

```bash
systemctl --user status oserp-ollama        # Status
systemctl --user restart oserp-ollama        # Neustart
journalctl --user -u oserp-ollama -f         # Live-Log
/home/work/ollama-cpu/bin/ollama list        # installierte Modelle
```

Weiteres Modell ziehen (Beispiel):

```bash
OLLAMA_HOST=127.0.0.1:11434 /home/work/ollama-cpu/bin/ollama pull qwen2.5:7b
```

## OSERP-Anbindung

Konfiguration in **CRM-Einstellungen → LxCars → „Lokale KI (selbst gehostet)"**:

- **Lokaler LLM-Endpunkt** (`llm_url`) – leer = `http://127.0.0.1:11434`
- **Lokales LLM-Modell** (`llm_model`) – leer = `qwen3:30b-a3b-instruct-2507-q4_K_M`

Im Backend gibt es den wiederverwendbaren Helper `backend/api/lib/llm.php`:

```php
require_once __DIR__.'/../lib/llm.php';

$antwort = oserpLlmChat([
    ['role' => 'system', 'content' => 'Du bist ein ERP-Assistent. Antworte knapp auf Deutsch.'],
    ['role' => 'user',   'content' => 'Formuliere eine höfliche Zahlungserinnerung für Rechnung 2024-118.'],
], ['temperature' => 0.2, 'max_tokens' => 300]);
```

`oserpLlmChat()` liest Endpunkt/Modell aus `defaults_oserp` (Fallback auf den lokalen
Dienst) und spricht den OpenAI-kompatiblen Endpunkt an. Damit lässt sich pro Feature
zwischen lokalem Modell und Cloud (Claude) umschalten – die bestehenden Claude-Aufrufe
(`api/lxcars/*`, `api/ebay/*`) bleiben unberührt.

**Sinnvolle Einsatzzwecke im ERP:** Zahlungserinnerungen/E-Mail-Entwürfe formulieren,
Verkaufstexte (Fahrzeuge), Zusammenfassungen von Sprachnotizen/Anrufen, Kategorisierung.
Für datenschutzkritische Inhalte (Kundendaten) ist das lokale Modell die bessere Wahl,
weil nichts das Haus verlässt.

## Grenzen dieser Hardware (gemessen)

Benchmark auf diesem Rechner (125U-iGPU, qwen2.5, Q4, gleiche Zahlungserinnerung-Aufgabe):

| Modell | CPU | iGPU SYCL (IPEX) | iGPU Vulkan | Deutsch |
|---|---|---|---|---|
| 14B | 2,3 | 2,2 | 2,3 tok/s | sehr gut |
| 7B  | –   | 2,7 | –          | mittel |
| 3B  | –   | –   | 6,3 tok/s  | schwach |

- **Drei Backends (CPU, SYCL, Vulkan) liefern bei 14B dasselbe Tempo** → der Engpass ist
  eindeutig die **Speicherbandbreite**, nicht das Backend. Belegt auch dadurch, dass die
  (rechenlastige) Prompt-Verarbeitung schnell ist (~40 tok/s), nur die Token-Generierung
  langsam – dabei müssen pro Token alle ~9 GB Gewichte durch den DDR5.
- **Modellgröße ist der einzige echte Tempo-Hebel:** 3B ist ~3× schneller (6,3 tok/s,
  interaktiv), aber sein Deutsch ist zu holprig. 7B lohnt nicht. **Entscheidung: 14B bleibt
  Standard** (Qualität vor Tempo) – gut für Entwürfe/Batch, nicht für Live-Chat-Gefühl.
- **Zukunftsoption für schnell UND gut:** ein **MoE-Modell** (z. B. `qwen3:30b-a3b`) aktiviert
  pro Token nur ~3B Parameter → Tempo wie ein 3B, Qualität wie ein großes Modell. Braucht
  ~18 GB Download + RAM (neben dem Dev-Stack eng). Bei Bedarf testen.
- Alternativ echtes Tempo nur mit einer Maschine mit dedizierter GPU (Endpunkt in `llm_url`).
- **Coral USB / Intel-NPU helfen nicht** für LLMs. Coral bleibt für Frigate/Kennzeichen nützlich.

## Thunderbird

Add-on (z. B. **ThunderAI**) auf denselben Endpunkt zeigen lassen:
Anbieter „Ollama" bzw. OpenAI-kompatibel, Base-URL `http://127.0.0.1:11434/v1`,
Modell `qwen3:30b-a3b-instruct-2507-q4_K_M`, API-Key beliebig/leer.
Fertige deutsche Custom-Prompts: siehe `dev/thunderai-prompts.md`.
