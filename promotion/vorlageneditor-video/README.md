# Video: Vorlageneditor

Erklärvideo zum Vorlageneditor — Bildschirmaufnahme des echten Systems mit
gesprochenem Text. Der Ablauf ist vollständig skriptgesteuert und lässt sich
nach jeder Änderung am Editor neu aufnehmen.

| Datei | Zweck |
|-------|-------|
| `sprechertext.md` | Sprechertext, eine Szene je `## NN`-Überschrift |
| `tools/cdp.mjs` | Chrome-DevTools-Treiber ohne Abhängigkeiten (Maus, Tastatur, Screenshots, Aufnahme, sichtbarer Mauszeiger) |
| `tools/record.mjs` | nimmt die 15 Szenen im echten System auf (`rec/sNN/*.jpg`) |
| `tools/test.mjs` | interaktiver Regressionstest des Design-Modus (23 Prüfungen) |
| `tools/test2.mjs` | Regressionstest Quelltext-Modus und freies Platzieren von Abschnitten (20 Prüfungen) |
| `tools/reset-demo.sh` | setzt den Demo-Vorlagensatz `templates/demo-editor` zurück |
| `tools/synth.py` | vertont den Sprechertext mit XTTS v2 (geklonte oder eingebaute Stimme) |
| `tools/assemble.sh` | schneidet Szenen und Tonspuren zum fertigen MP4 |

## Ablauf

1. Sitzung: `tools/record.mjs` und `tools/test.mjs` erwarten in `session.txt` die
   Sitzungs-ID eines Administrators (Zeile in `auth.session_oserp`), das Cookie
   `opensource_erp` wird damit gesetzt. Die Aufnahme läuft gegen `https://localhost`.
2. Demo-Vorlagensatz anlegen: Kopie von `backend/templates/schoenert` nach
   `backend/templates/demo-editor` (macht `reset-demo.sh`).
3. Aufnehmen: `node tools/record.mjs` — ohne Tonspuren wird die Szenendauer aus
   dem Sprechertext geschätzt.
4. Vertonen: `tools/.tts/venv/bin/python tools/synth.py --speaker-wav stimme.wav --out audio`
   (`tools/.tts/venv` entsteht mit `python3 -m venv` und
   `pip install coqui-tts[codec] "transformers<5" torch torchaudio --extra-index-url https://download.pytorch.org/whl/cpu`).
   Die Referenzaufnahme: 10–30 Sekunden, ein Sprecher, ohne Musik.
5. Schneiden: `tools/assemble.sh vorlageneditor.mp4 audio` — ist eine Tonspur
   länger als die Szene, steht das letzte Bild; ist sie kürzer, folgt Stille.
