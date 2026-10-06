#!/usr/bin/env python3
"""
Sprechertext (sprechertext.md) szenenweise mit XTTS v2 vertonen.

    synth.py --speaker-wav stimme.wav --out audio/        # geklonte Stimme
    synth.py --speaker "Claribel Dervla" --out audio-demo/ # eingebaute Stimme (Platzhalter)

Je Szene "## NN Titel" entsteht audio/NN.wav (24 kHz, mono). Die Stimme wird aus
einer sauberen Aufnahme von 10 bis 30 Sekunden geklont: keine Musik, kein Hall,
ein Sprecher. Läuft auf der CPU, rechnet etwa in Echtzeit.
"""
import argparse
import os
import pathlib
import re
import sys

os.environ.setdefault('COQUI_TOS_AGREED', '1')

parser = argparse.ArgumentParser()
parser.add_argument('--text', default='/home/work/opensource-erp/promotion/vorlageneditor-video/sprechertext.md')
parser.add_argument('--out', default='audio')
parser.add_argument('--speaker-wav', help='Referenzaufnahme der zu klonenden Stimme')
parser.add_argument('--speaker', default='Claribel Dervla', help='eingebaute XTTS-Stimme, wenn keine Referenz vorliegt')
parser.add_argument('--scenes', help='nur diese Szenen, z. B. 1,2,5')
parser.add_argument('--language', default='de')
args = parser.parse_args()

from TTS.api import TTS  # noqa: E402  (nach den Umgebungsvariablen)

text = pathlib.Path(args.text).read_text(encoding='utf-8')
scenes = []
for part in re.split(r'^## ', text, flags=re.M)[1:]:
    head, _, body = part.partition('\n')
    m = re.match(r'(\d+)\s', head)
    if not m:
        continue
    body = ' '.join(line.strip() for line in body.strip().splitlines() if line.strip())
    # Typografische Anführungszeichen und Sonderzeichen, die XTTS sonst vorliest oder verschluckt
    body = body.replace('„', '"').replace('“', '"').replace('”', '"').replace('–', '-')
    body = body.replace('Strg Z', 'Steuerung Z').replace('Strg', 'Steuerung')
    body = body.replace('DIN 5008', 'DIN fünftausendacht').replace('2 plus', 'zwei plus')
    body = body.replace('invoice.tex', 'invoice punkt tex').replace('OpensourceERP', 'Open Source E R P')
    scenes.append((int(m.group(1)), body))

wanted = {int(x) for x in args.scenes.split(',')} if args.scenes else None
out = pathlib.Path(args.out)
out.mkdir(parents=True, exist_ok=True)

tts = TTS('tts_models/multilingual/multi-dataset/xtts_v2')
for n, body in scenes:
    if wanted and n not in wanted:
        continue
    target = out / f'{n:02d}.wav'
    print(f'Szene {n:02d}: {len(body)} Zeichen -> {target}', flush=True)
    kwargs = {'text': body, 'language': args.language, 'file_path': str(target), 'split_sentences': True}
    if args.speaker_wav:
        kwargs['speaker_wav'] = args.speaker_wav
    else:
        kwargs['speaker'] = args.speaker
    tts.tts_to_file(**kwargs)
print('fertig', file=sys.stderr)
