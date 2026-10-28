# Drittkomponenten und Lizenzen

Stand: 28.10.2026. OSERP selbst steht unter der GNU Affero General Public License
v3 oder später (siehe `LICENSE`). Diese Liste nennt alle Softwarekomponenten
Dritter, die OSERP einbindet, mitliefert oder beim Betrieb voraussetzt.

Pflege: Bei jeder Änderung an `package.json`, `portal-ui/package.json`,
`backend/sse/package.json`, `backend/portal-runner/package.json`,
`backend/composer.json`, einer `requirements.txt` oder an den Paketlisten in
`install/install.sh` bzw. `docker/` ist diese Datei nachzuziehen.

Prüfung der npm-Lizenzen (alle Pakete inkl. transitiver):

```bash
node -e 'const fs=require("fs");const c={};for(const f of require("child_process").execSync("find node_modules portal-ui/node_modules backend/sse/node_modules -maxdepth 4 -name package.json").toString().trim().split("\n")){try{const p=JSON.parse(fs.readFileSync(f));if(!p.name||!p.version)continue;let l=p.license||"?";if(typeof l==="object")l=l.type;c[l]=(c[l]||0)+1}catch(e){}}console.log(c)'
```

Prüfung der Composer-Lizenzen:

```bash
cd backend && composer licenses
```

## Lizenzverträglichkeit mit AGPL-3.0

Alle unten genannten Lizenzen sind mit der AGPL-3.0 verträglich. Zwei
Komponenten stehen selbst unter AGPL-3.0 (ultralytics, nodriver); sie sind der
Grund, warum OSERP als Ganzes unter AGPL-3.0 steht. kivitendo (GPL-2.0-or-later)
ist über die „or later“-Klausel verträglich. LGPL-Komponenten werden nur
dynamisch bzw. als getrennte Prozesse genutzt.

## Frontend (npm, Hauptprojekt)

| Paket | Version | Lizenz |
|---|---|---|
| vue | 3.5 | MIT |
| vue-router | 5.4 | MIT |
| pinia | 4.1 | MIT |
| vue-i18n | 11.4 | MIT |
| vuetify | 4.3 | MIT |
| vite | 8.3 | MIT |
| @vitejs/plugin-vue | 6.0 | MIT |
| vite-plugin-vuetify | 2.1 | MIT |
| vite-plugin-vue-devtools | 8.2 | MIT |
| @tiptap/starter-kit, extension-link, extension-table, extension-underline, pm, vue-3 | 3.32 | MIT |
| @codemirror/commands, language, state, view | 6.x | MIT |
| @lezer/highlight | 1.2 | MIT |
| @fullcalendar/vue3, @fullcalendar/rrule | 7.1 | MIT |
| rrule | 2.8 | BSD-3-Clause |
| temporal-polyfill | 1.0 | MIT |
| axios | 1.20 | MIT |
| bootstrap | 5.3 | MIT |
| chart.js | 4.6 | MIT |
| vue-chartjs | 5.3 | MIT |
| marked | 18.1 | MIT |
| sweetalert2 | 11.26 | MIT |
| libphonenumber-js | 1.13 | MIT |
| vue-draggable-plus | 0.6 | MIT |
| vue3-emoji-picker | 1.1 | MIT |
| vuefinder | 4.7 | MIT |
| @mdi/font (Material Design Icons) | 7.4 | Apache-2.0 |
| xlsx (SheetJS Community Edition, Bezug vom SheetJS-CDN) | 0.20 | Apache-2.0 |

Transitive Pakete (ca. 425): MIT, ISC, BSD-2/3-Clause, Apache-2.0, 0BSD.
Abweichend nur lightningcss und lightningcss-linux-x64-gnu (MPL-2.0, Build-Werkzeug)
sowie caniuse-lite (CC-BY-4.0, Datentabelle für Browser-Unterstützung).

## Portal-Widgets (`portal-ui`)

| Paket | Version | Lizenz |
|---|---|---|
| lit | 3.3 | BSD-3-Clause |
| esbuild | 0.24 | MIT |
| tailwindcss | 3.4 | MIT |

Das gebaute Bündel unter `backend/templates-default/portal/standard/kit/assets/portal-ui/`
enthält Lit samt dessen Lizenzkopfzeilen (BSD-3-Clause); diese dürfen beim Bauen
nicht entfernt werden.

## SSE-Server (`backend/sse`)

| Paket | Version | Lizenz |
|---|---|---|
| pg (node-postgres) | 8.20 | MIT |

## Portal-Runner (`backend/portal-runner`)

| Paket | Version | Lizenz |
|---|---|---|
| puppeteer | 24 | Apache-2.0 |
| @puppeteer/replay | 3.1 | Apache-2.0 |

Puppeteer lädt beim Installieren eine Chrome-for-Testing-Binärdatei (Google,
proprietär, BSD-lizenzierter Chromium-Kern).

## Backend PHP (Composer, `backend/composer.json`)

| Paket | Version | Lizenz |
|---|---|---|
| horstoeko/zugferd | 1.0 | MIT |
| horstoeko/mimedb | 1.0 | MIT |
| horstoeko/stringmanagement | 1.0 | MIT |
| nemiah/php-fints | 3.7 | MIT |
| jms/serializer | 3.32 | MIT |
| jms/metadata | 2.9 | MIT |
| goetas-webservices/xsd2php-runtime | 0.2 | MIT |
| symfony/validator, yaml, finder, process | 6.4 | MIT |
| symfony/polyfill-ctype, polyfill-mbstring, polyfill-php83 | 1.36 | MIT |
| symfony/deprecation-contracts, translation-contracts | 3.6 | MIT |
| doctrine/instantiator | 2.0 | MIT |
| doctrine/lexer | 3.0 | MIT |
| phpstan/phpdoc-parser | 2.3 | MIT |
| psr/log | 3.0 | MIT |
| setasign/fpdf | 1.8 | MIT |
| setasign/fpdi | 2.6 | MIT |
| smalot/pdfparser | 2.12 | LGPL-3.0 |

Zusätzlich liegen FPDF 1.86 und FPDI 2.6.4 als Kopie unter `backend/lib/`
(beide MIT, Lizenzdateien liegen bei); genutzt von `backend/api/lxcars/carreg.php`.

## Python-Dienste

### Whisper-Transkription (`backend/whisper`)

| Paket | Lizenz |
|---|---|
| faster-whisper | MIT |
| CTranslate2 (transitiv) | MIT |
| requests | Apache-2.0 |
| Whisper-Modelle (Systran/OpenAI) | MIT |

### Fahrzeugscheinscanner (`backend/fahrzeugschein-scanner`)

| Paket | Lizenz |
|---|---|
| rapidocr | Apache-2.0 |
| onnxruntime | MIT |
| opencv-python-headless | Apache-2.0 |
| numpy | BSD-3-Clause |
| PP-OCRv5-Modelle (PaddleOCR) | Apache-2.0 |
| `reference/crnn.onnx` | eigenes, selbst trainiertes Modell (AGPL-3.0 wie OSERP) |

### Kennzeichenerkennung (`backend/services/plate-recognition`)

| Paket | Lizenz |
|---|---|
| paddlepaddle | Apache-2.0 |
| paddleocr | Apache-2.0 |
| opencv-python-headless | Apache-2.0 |
| numpy | BSD-3-Clause |
| Pillow | MIT-CMU |
| psycopg2-binary | LGPL-3.0 mit Ausnahme |
| requests | Apache-2.0 |

### Kameradienst (`backend/services/camera-monitor`)

| Paket | Lizenz |
|---|---|
| ultralytics (YOLOv8) inkl. `yolov8n.pt` | **AGPL-3.0** |
| wsdiscovery | LGPL-3.0 |
| onvif-zeep | MIT |
| opencv-python-headless | Apache-2.0 |
| numpy | BSD-3-Clause |
| psycopg2-binary | LGPL-3.0 mit Ausnahme |
| pycoral, tflite-runtime (optional) | Apache-2.0 |
| openvino (optional) | Apache-2.0 |

### Kleinanzeigen-Dienst (`backend/services/kleinanzeigen`)

| Paket | Lizenz |
|---|---|
| nodriver | **AGPL-3.0** |
| aiohttp | Apache-2.0 AND MIT |
| Google Chrome (Laufzeitvoraussetzung) | proprietär (Google-Nutzungsbedingungen) |

## Druckvorlagen (`backend/templates-default`)

| Vorlagensatz | Herkunft | Lizenz |
|---|---|---|
| `marei` | Vorlagensatz „marei“ von Marei Peischl aus dem kivitendo-Repository (`templates/print/marei`) | GPL-2.0-or-later (Copyright 2019–2022 Marei Peischl) |
| `mersiha` | Abwandlung des Satzes `marei` | GPL-2.0-or-later |

Die Copyright-Zeilen in den `.tex`-Dateien dieser Sätze müssen erhalten bleiben.

## System- und Infrastrukturkomponenten

Werden vom Installer (`install/install.sh`) bzw. den Docker-Images bezogen und
nicht mitgeliefert.

| Komponente | Lizenz | Nutzung |
|---|---|---|
| PostgreSQL 16 | PostgreSQL License | Datenbank |
| PHP 8.3 | PHP License 3.01 | Backend-Laufzeit |
| Node.js 25 | MIT | Build, SSE-Server, Portal-Runner |
| Apache httpd | Apache-2.0 | Webserver (Installer) |
| Composer | MIT | PHP-Paketverwaltung |
| Hugo | Apache-2.0 | Generator für Webseite, Shop und Kundenportal |
| TeX Live, KOMA-Script, latexmk | LPPL (überwiegend), GPL (latexmk) | Druck (externer Prozess) |
| poppler-utils (pdftoppm, pdftotext, pdfinfo, pdfunite) | GPL-2.0 / GPL-3.0 | externer Prozess |
| qrencode | LGPL-2.1 | GiroCode auf Rechnungen (externer Prozess) |
| ffmpeg | LGPL-2.1+ bzw. GPL je nach Build | Kameradienst |
| CUPS | Apache-2.0 | Druck |
| certbot | Apache-2.0 | TLS-Zertifikate |
| Ollama (optional) | MIT | lokale Sprachmodelle |
| go2rtc (optional) | MIT | Kamera-Streams |
| Asterisk (optional) | GPL-2.0 | Telefonie |
| borgbackup | BSD-3-Clause | Sicherung |
| miniupnpc | BSD-3-Clause | Portfreigabe |
| Perl | Artistic / GPL | kivitendo-Basis |
| kivitendo (optional, externer Installer) | GPL-2.0-or-later | Datenbankkompatibilität; `backend/upstall/crm/auth_schema.sql` bildet das Auth-Schema von kivitendo 4.0 nach |
| Docker-Images: node:25-alpine, php:8.3-fpm-bookworm, postgres:16-alpine, certbot/certbot | siehe jeweilige Komponente | Test/Demo |

## Externe Dienste

Keine Softwarekomponenten, aber Nutzungsbedingungen der Anbieter gelten:
Anthropic API, Meta Graph API (WhatsApp), Telegram Bot API, Brevo, SumUp, eBay,
DHL, LetterXpress, PayPal, Stripe, Nominatim/OpenStreetMap (Nutzungsrichtlinie
und ODbL-Namensnennung), date.nager.at (Feiertage), labelary.com (ZPL-Vorschau).
