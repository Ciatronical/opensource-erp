#!/usr/bin/env python3
"""
Glyphenbank aus echten Ausschnitten: OCR-B ist eine Festbreitenschrift, daher belegt
Zeichen i eines Ausschnitts mit n Zeichen genau den Bereich [i*w/n, (i+1)*w/n].
Die Textbreite wird vorher per Tintenprofil bestimmt (Rand abziehen), Leerzeichen
zaehlen als Zeichen. Ergebnis: je Zeichen viele echte Beispielbilder (Hoehe 32).

Aufruf: ocr_train/glyph_bank.py --pairs train_pairs.json --out glyphs.npz
"""
import argparse
import json
import os
import sys

import cv2
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from fsscanner import grid  # noqa: E402

H = 32


def text_span(ink):
    cols = np.flatnonzero((ink > 0).sum(axis=0) >= 1)
    rows = np.flatnonzero((ink > 0).sum(axis=1) >= 1)
    if len(cols) < 3 or len(rows) < 6:
        return None
    return int(cols[0]), int(rows[0]), int(cols[-1]) + 1, int(rows[-1]) + 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pairs", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--max-per-char", type=int, default=400)
    args = ap.parse_args()
    pairs = json.load(open(args.pairs))
    bank = {}
    used = 0
    for p, text in pairs:
        fld = os.path.basename(p)[5:-4]
        if fld == "registrationNumber":
            continue  # Kennzeichen: andere Schrift, andere Bank
        text = text.replace(" ", " ")
        n = len(text)
        if n < 2:
            continue
        img = cv2.imread(p)
        if img is None or img.shape[0] < 16:
            continue
        gray = np.max(img, axis=2)
        ink = grid.binarize(img)
        ink = cv2.morphologyEx(ink, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
        span = text_span(ink)
        if span is None:
            continue
        x0, y0, x1, y1 = span
        w = x1 - x0
        cw = w / n
        # Plausibilitaet: Zeichenbreite ~0.6..1.0 x Zeichenhoehe (OCR-B)
        ch = y1 - y0
        if not (0.45 * ch <= cw <= 1.1 * ch):
            continue
        used += 1
        # normieren: Hoehe H, Breite proportional
        s = H / max(1, ch)
        line = cv2.resize(gray[y0:y1, x0:x1], (max(1, int(round(w * s))), H), interpolation=cv2.INTER_CUBIC)
        cw_s = line.shape[1] / n
        for i, c in enumerate(text):
            a, b = int(round(i * cw_s)), int(round((i + 1) * cw_s))
            g = line[:, a:b]
            if g.shape[1] < 4:
                continue
            g = cv2.resize(g, (20, H), interpolation=cv2.INTER_AREA)
            lst = bank.setdefault(c, [])
            if len(lst) < args.max_per_char:
                lst.append(g)
    print("Ausschnitte verwendet:", used, "Zeichen:", len(bank))
    print({c: len(v) for c, v in sorted(bank.items())})
    np.savez_compressed(args.out, **{("c_%04x" % ord(c)): np.stack(v) for c, v in bank.items()})


if __name__ == "__main__":
    main()
