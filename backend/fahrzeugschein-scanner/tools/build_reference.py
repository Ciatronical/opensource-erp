#!/usr/bin/env python3
"""
Baut die saubere Referenzvorlage: pixelweiser Median vieler registrierter Scans.

Der gedruckte Vordruck (Rahmen, Beschriftungen, Guilloche) ist in allen Scans an
derselben Stelle und bleibt scharf erhalten; die Eintragungen (Namen, FIN ...)
stehen ueberall woanders und verschwinden im Median. Ergebnis: ein "leeres"
Formular ohne personenbezogene Daten — geeignet fuers Repository und als
Registrierungsziel (weniger Fehlzuordnungen als eine ausgefuellte Vorlage).

Liest die Homographien aus dem Cache von learn_layout.py (<cache>/<id>.json).

Aufruf:
  tools/build_reference.py --reference reference/zb1_bootstrap.jpg --cache <dir> \
      --out reference/zb1_reference.jpg [--max 80]
"""
import argparse
import glob
import json
import os
import sys

import cv2
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from fsscanner.imageio import load_file  # noqa: E402
from fsscanner.register import Registrar  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--reference", required=True, help="Vorlage, in deren Koordinaten registriert wurde")
    ap.add_argument("--cache", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--max", type=int, default=80, help="Anzahl bester Dokumente (nach Inliern)")
    ap.add_argument("--panels", default="", help="Panelgrenzen x, z.B. 824,1786 (wie beim Lernen)")
    args = ap.parse_args()

    ref = cv2.imread(args.reference)
    H_ref, W_ref = ref.shape[:2]
    panels = []
    if args.panels:
        xs = [0] + [int(v) for v in args.panels.split(",")] + [W_ref]
        panels = [[xs[i], 0, xs[i + 1], H_ref] for i in range(len(xs) - 1)]
    reg = Registrar.__new__(Registrar)  # nur warp-Funktionen noetig, keine SIFT-Initialisierung
    reg.ref_w, reg.ref_h, reg.panels = W_ref, H_ref, panels

    metas = []
    for p in glob.glob(os.path.join(args.cache, "*.json")):
        m = json.load(open(p))
        if os.path.isfile(m.get("orig", "")):
            metas.append(m)
    metas.sort(key=lambda m: -m["inliers"])
    metas = metas[:args.max]
    print(f"{len(metas)} Dokumente, Inlier {metas[-1]['inliers']}..{metas[0]['inliers']}")

    stack = np.empty((len(metas), H_ref, W_ref, 3), np.uint8)
    for i, m in enumerate(metas):
        img = load_file(m["orig"])
        r = {"H": np.array(m["H"]), "panel_H": [np.array(h) for h in m.get("panel_H", [])]}
        # Panel-Homographien, die identisch zur globalen sind, als "gleich" markieren
        r["panel_H"] = [r["H"] if np.allclose(h, r["H"]) else h for h in r["panel_H"]]
        w = reg.warp_document_panels(img, r, scale=1.0) if panels else reg.warp_document(img, r["H"], 1.0)
        # Helligkeit angleichen (Fotos unterschiedlich belichtet): auf gemeinsamen Mittelwert normieren
        lab = cv2.cvtColor(w, cv2.COLOR_BGR2LAB).astype(np.float32)
        lab[..., 0] = np.clip(lab[..., 0] - lab[..., 0].mean() + 200.0, 0, 255)
        stack[i] = cv2.cvtColor(lab.astype(np.uint8), cv2.COLOR_LAB2BGR)
        if (i + 1) % 10 == 0:
            print(f"  {i+1}/{len(metas)}", flush=True)

    print("Median ...", flush=True)
    out = np.empty((H_ref, W_ref, 3), np.uint8)
    step = 100
    for y in range(0, H_ref, step):
        out[y:y + step] = np.median(stack[:, y:y + step], axis=0).astype(np.uint8)
    cv2.imwrite(args.out, out, [cv2.IMWRITE_JPEG_QUALITY, 95])
    print("gespeichert:", args.out, out.shape)


if __name__ == "__main__":
    main()


def composite(sharp_path, median_path, layout_path, out_path, extra_boxes=()):
    """Sharfer Vordruck (Bootstrap-Foto) ausserhalb der Wertefelder, Median (anonym)
    innerhalb: Beschriftungen/Linien bleiben scharf, keine Kundendaten mehr im Bild."""
    import json
    sharp = cv2.imread(sharp_path)
    med = cv2.imread(median_path)
    lay = json.load(open(layout_path))
    out = sharp.copy()
    mask = np.zeros(sharp.shape[:2], np.uint8)
    boxes = [v["box"] for v in lay["fields"].values()] + list(extra_boxes)
    for (x0, y0, x1, y1) in boxes:
        cv2.rectangle(mask, (int(x0) - 2, int(y0) - 2), (int(x1) + 2, int(y1) + 2), 255, -1)
    # weiche Kante, damit keine harten Uebergaenge entstehen
    soft = cv2.GaussianBlur(mask, (0, 0), 3).astype(np.float32)[..., None] / 255.0
    out = (sharp * (1 - soft) + med * soft).astype(np.uint8)
    cv2.imwrite(out_path, out, [cv2.IMWRITE_JPEG_QUALITY, 95])
    return out
