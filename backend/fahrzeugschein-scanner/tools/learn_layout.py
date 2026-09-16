#!/usr/bin/env python3
"""
Lernt das Feldlayout (Boxen in Referenzkoordinaten) aus den vorhandenen Scans.

Datengrundlage: Fahrzeugordner mit original.jpg + .crops/crop_<feld>.jpg, wie sie
der bisherige Scanner abgelegt hat (backend/data/<db>/fahrzeuge/<c_id>/[fahrzeugschein/]),
plus die Textwerte aus fs_scans_lxcars (CSV, s. tools/evaluate.py) — damit leere
Felder ("-") nicht als Vorlage dienen.

Ablauf:
  1. Jedes Foto per SIFT auf die Referenzvorlage registrieren und entzerren (Cache).
  2. Je Dokument den Massstab Crop -> Referenz bestimmen (die alten Crops sind je
     Dokument unterschiedlich gross): Multi-Scale-Matching kurzer, markanter Felder.
  3. Grobes Layout aus den besten Dokumenten: Ganzbildsuche, dichtester Cluster.
  4. Alle Dokumente: jeden Crop nur im Fenster um die grobe Box suchen.
  5. Aggregation je Feld: Cluster um den Modus, links/oben/unten = Median,
     rechts = 95. Perzentil (laengste Texte), Nachbarn derselben Zeile ohne Ueberlappung.

Aufruf:
  tools/learn_layout.py --reference reference/zb1_bootstrap.jpg \
      --data /home/work/opensource-erp/backend/data/ap_rebuild --gt ground_truth.csv \
      --out reference/layout.json --cache <dir> --panels 824,1786
"""
import argparse
import csv
import glob
import json
import os
import re
import sys
import time

import cv2
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from fsscanner.imageio import load_file  # noqa: E402
from fsscanner.register import Registrar, RegistrationError  # noqa: E402

MATCH_SCALE = 0.5          # Template-Matching auf halber Referenzaufloesung
SCALE_FIELDS = ["hsn", "registrationNumber", "field_3", "ez", "p1", "field_14_1", "field_10", "field_2_2"]
MIN_SCORE = 0.45
CLUSTER_R = 30             # px (halbe Aufloesung) fuer Cluster um den Modus
GT_COLS = {"registrationNumber": "registrationnumber"}


def find_docs(roots):
    docs = []
    for root in roots:
        for d in sorted(glob.glob(os.path.join(root, "fahrzeuge", "*"))):
            cid = os.path.basename(d)
            if not cid.isdigit():
                continue
            for sub in (os.path.join(d, "fahrzeugschein"), d):
                orig = os.path.join(sub, "original.jpg")
                crops = os.path.join(sub, ".crops")
                if os.path.isfile(orig) and os.path.isdir(crops) and glob.glob(os.path.join(crops, "crop_*.jpg")):
                    docs.append({"id": f"{os.path.basename(root)}_{cid}", "cid": cid, "orig": orig, "crops": crops})
                    break
    return docs


def load_gt(path):
    gt = {}
    if not path:
        return gt
    for r in csv.DictReader(open(path, encoding="utf-8")):
        gt[r["c_id"]] = r
    return gt


def gt_text(gt, cid, field):
    row = gt.get(cid)
    if row is None:
        return None
    return row.get(GT_COLS.get(field, field.lower()), "") or ""


def usable(text):
    """None = unbekannt (kein GT) -> verwenden; sonst mind. 2 Buchstaben/Ziffern."""
    if text is None:
        return True
    return len(re.sub(r"[^0-9A-Za-zÄÖÜäöüß]", "", text)) >= 2


def load_crops(cropdir):
    out = {}
    for p in glob.glob(os.path.join(cropdir, "crop_*.jpg")):
        name = os.path.basename(p)[5:-4]
        im = cv2.imread(p, cv2.IMREAD_GRAYSCALE)
        if im is not None and im.shape[0] >= 8 and im.shape[1] >= 8:
            out[name] = im
    return out


def prep(gray):
    return cv2.GaussianBlur(gray, (0, 0), 1.0)


def match(doc, tmpl, window=None):
    ox, oy = 0, 0
    region = doc
    if window is not None:
        x0, y0, x1, y1 = [int(v) for v in window]
        x0, y0 = max(0, x0), max(0, y0)
        x1, y1 = min(doc.shape[1], x1), min(doc.shape[0], y1)
        if x1 - x0 < tmpl.shape[1] + 2 or y1 - y0 < tmpl.shape[0] + 2:
            return None
        region = doc[y0:y1, x0:x1]
        ox, oy = x0, y0
    if tmpl.shape[0] >= region.shape[0] or tmpl.shape[1] >= region.shape[1]:
        return None
    res = cv2.matchTemplate(region, tmpl, cv2.TM_CCOEFF_NORMED)
    _, mx, _, loc = cv2.minMaxLoc(res)
    return (float(mx), ox + loc[0], oy + loc[1], ox + loc[0] + tmpl.shape[1], oy + loc[1] + tmpl.shape[0])


def scaled(tmpl, s):
    w, h = max(4, int(round(tmpl.shape[1] * s))), max(4, int(round(tmpl.shape[0] * s)))
    return cv2.resize(tmpl, (w, h), interpolation=cv2.INTER_AREA if s < 1 else cv2.INTER_CUBIC)


def register_all(reg, docs, cache):
    os.makedirs(cache, exist_ok=True)
    ok = []
    t0 = time.time()
    for i, d in enumerate(docs):
        cpath = os.path.join(cache, d["id"] + ".png")
        meta = os.path.join(cache, d["id"] + ".json")
        if os.path.isfile(cpath) and os.path.isfile(meta):
            d["warped"] = cpath
            d["inliers"] = json.load(open(meta))["inliers"]
            ok.append(d)
            continue
        try:
            img = load_file(d["orig"])
            r = reg.register(img)
        except (RegistrationError, ValueError) as e:
            print(f"  [{i+1}/{len(docs)}] {d['id']}: {e}")
            continue
        warped = reg.warp_document_panels(img, r, scale=MATCH_SCALE)
        cv2.imwrite(cpath, cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY))
        json.dump({"inliers": r["inliers"], "H": r["H"].tolist(), "panel_H": [h.tolist() for h in r["panel_H"]],
                   "panel_inliers": r["panel_inliers"], "orig": d["orig"]}, open(meta, "w"))
        d["warped"] = cpath
        d["inliers"] = r["inliers"]
        ok.append(d)
        if (i + 1) % 10 == 0:
            print(f"  [{i+1}/{len(docs)}] registriert, {len(ok)} ok, {time.time()-t0:.0f}s", flush=True)
    return ok


def doc_scale(doc, crops, gt, cid, scales, windows=None):
    """Bester gemeinsamer Massstab fuer die Crops dieses Dokuments."""
    fields = [f for f in SCALE_FIELDS if f in crops and usable(gt_text(gt, cid, f))]
    if len(fields) < 3:
        return None, 0.0
    best = (0.0, None)
    for s in scales:
        tot, n = 0.0, 0
        for f in fields:
            w = None
            if windows and f in windows:
                w = windows[f]
            m = match(doc, prep(scaled(crops[f], s)), w)
            if m:
                tot += m[0]; n += 1
        if n and tot / n > best[0]:
            best = (tot / n, s)
    return best[1], best[0]


def mode_cluster(boxes, r=CLUSTER_R):
    """Dichtester Cluster (nach x0,y0): Indizes der Boxen um den Modus."""
    if not boxes:
        return []
    a = np.array([[b[1], b[2]] for b in boxes], float)
    d = np.linalg.norm(a[:, None, :] - a[None, :, :], axis=2)
    counts = (d < r).sum(axis=1)
    best = int(np.argmax(counts))
    return [i for i in range(len(boxes)) if d[best, i] < r]


def collect(docs, gt, coarse, win_frac=(0.08, 0.04), min_score=MIN_SCORE, log_every=25):
    boxes = {}
    ref_w = ref_h = None
    for i, d in enumerate(docs):
        doc = prep(cv2.imread(d["warped"], cv2.IMREAD_GRAYSCALE))
        if ref_w is None:
            ref_h, ref_w = doc.shape
        crops = load_crops(d["crops"])
        windows = {}
        if coarse:
            wx, wy = win_frac[0] * ref_w, win_frac[1] * ref_h
            windows = {f: (b[0] - wx, b[1] - wy, b[2] + wx, b[3] + wy) for f, b in coarse.items()}
        s = d.get("scale")
        if s is None:
            s, sc = doc_scale(doc, crops, gt, d["cid"], np.arange(0.84, 1.13, 0.01), windows or None)
            d["scale"], d["scale_score"] = s, sc
        if s is None or d["scale_score"] < 0.55:
            continue
        for f, im in crops.items():
            if not usable(gt_text(gt, d["cid"], f)):
                continue
            tmpl = prep(scaled(im, s))
            m = match(doc, tmpl, windows.get(f))
            if m and m[0] >= min_score:
                boxes.setdefault(f, []).append(m)
        if (i + 1) % log_every == 0:
            print(f"  [{i+1}/{len(docs)}] Boxen gesammelt", flush=True)
    return boxes


def aggregate(boxes, min_n=3):
    layout = {}
    for f, lst in boxes.items():
        idx = mode_cluster(lst)
        if len(idx) < min_n:
            continue
        a = np.array([lst[i][1:] for i in idx], float)
        layout[f] = {"box": [float(np.median(a[:, 0])), float(np.median(a[:, 1])),
                             float(np.percentile(a[:, 2], 95)), float(np.median(a[:, 3]))],
                     "n": len(idx), "n_all": len(lst), "median_h": float(np.median(a[:, 3] - a[:, 1]))}
    return layout


def resolve_neighbors(layout, gap, line_h):
    names = list(layout)
    for a in names:
        ax0, ay0, ax1, ay1 = layout[a]["box"]
        acy = (ay0 + ay1) / 2
        for b in names:
            if a == b:
                continue
            bx0, by0, bx1, by1 = layout[b]["box"]
            bcy = (by0 + by1) / 2
            same_row = abs(acy - bcy) < 0.6 * line_h or (min(ay1, by1) - max(ay0, by0)) > 0.5 * min(ay1 - ay0, by1 - by0)
            if same_row and bx0 > ax0 + 10 and ax1 > bx0 - gap:
                layout[a]["box"][2] = max(ax0 + 10, bx0 - gap)
    return layout


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--reference", required=True)
    ap.add_argument("--data", action="append", required=True)
    ap.add_argument("--gt", default="", help="CSV mit fs_scans_lxcars-Werten je c_id (s. evaluate.py)")
    ap.add_argument("--out", required=True)
    ap.add_argument("--cache", required=True)
    ap.add_argument("--max", type=int, default=0)
    ap.add_argument("--good", type=int, default=40, help="Anzahl bester Dokumente fuers grobe Layout")
    ap.add_argument("--panels", default="", help="Panelgrenzen x (Referenz-Pixel), z.B. 824,1786")
    ap.add_argument("--debug", default=None, help="Bild mit eingezeichnetem Layout")
    args = ap.parse_args()

    ref = cv2.imread(args.reference)
    panels = []
    if args.panels:
        xs = [0] + [int(v) for v in args.panels.split(",")] + [ref.shape[1]]
        panels = [[xs[i], 0, xs[i + 1], ref.shape[0]] for i in range(len(xs) - 1)]
    reg = Registrar(ref, panels=panels)
    docs = find_docs(args.data)
    if args.max:
        docs = docs[:args.max]
    gt = load_gt(args.gt)
    print(f"{len(docs)} Dokumente mit Original + Crops, Ground-Truth fuer {sum(1 for d in docs if d['cid'] in gt)}")

    print("== Registrierung ==")
    ok = register_all(reg, docs, args.cache)
    print(f"{len(ok)}/{len(docs)} registriert")
    ok.sort(key=lambda d: -d["inliers"])
    good = ok[:args.good]

    print("== Grobes Layout (Ganzbildsuche) ==")
    coarse_boxes = aggregate(collect(good, gt, None, min_score=0.5, log_every=10), min_n=3)
    coarse = {f: v["box"] for f, v in coarse_boxes.items()}
    print(f"  {len(coarse)} Felder grob; Massstaebe: " +
          ", ".join(f"{d['cid']}={d.get('scale')}" for d in good[:8]))

    print("== Alle Dokumente (Fenstersuche) ==")
    boxes = collect(ok, gt, coarse)
    layout = aggregate(boxes)
    ref_h, ref_w = ref.shape[:2]
    line_h = float(np.median([v["median_h"] for v in layout.values() if not v["median_h"] > 60]))
    pad = 0.004 * ref_w * MATCH_SCALE
    for f, v in layout.items():
        v["box"] = [v["box"][0] - pad, v["box"][1] - pad, v["box"][2] + 3 * pad, v["box"][3] + pad]
    layout = resolve_neighbors(layout, gap=0.015 * ref_w * MATCH_SCALE, line_h=line_h)

    fields = {}
    for f, v in sorted(layout.items()):
        b = [round(c / MATCH_SCALE, 1) for c in v["box"]]
        fields[f] = {"box": b, "n": v["n"], "multiline": bool(v["median_h"] > 1.7 * line_h)}
    out = {"reference": {"width": ref_w, "height": ref_h}, "panels": panels,
           "line_height": round(line_h / MATCH_SCALE, 1), "fields": fields}
    json.dump(out, open(args.out, "w"), indent=1, ensure_ascii=False)
    print(f"{len(fields)} Felder -> {args.out}  (Zeilenhoehe {out['line_height']})")
    for f, v in fields.items():
        print(f"  {f:20s} n={v['n']:3d}/{layout[f]['n_all']:3d} {'ML' if v['multiline'] else '  '} {v['box']}")

    if args.debug:
        dbg = ref.copy()
        for f, v in fields.items():
            x0, y0, x1, y1 = [int(c) for c in v["box"]]
            cv2.rectangle(dbg, (x0, y0), (x1, y1), (0, 0, 255), 2)
            cv2.putText(dbg, f, (x0, max(12, y0 - 3)), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 0, 0), 1)
        cv2.imwrite(args.debug, dbg)


if __name__ == "__main__":
    main()
