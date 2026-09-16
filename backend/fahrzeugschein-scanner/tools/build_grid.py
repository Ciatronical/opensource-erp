#!/usr/bin/env python3
"""
Referenzraster aus einem scharfen, entzerrten Formularbild ableiten (nur Geometrie).

Ergebnis reference/grid.json:
  rows[panel]   : y-Positionen der Zeilenlinien (Referenzkoordinaten)
  vsegs[panel]  : senkrechte Trennlinien (x, Zeilenindex)
  fields[name]  : panel, row (Index der Linie ueber dem Feld), rows (mehrzeilig), x0, x1

Aufruf: tools/build_grid.py --image reference/zb1_bootstrap.jpg --layout reference/layout.json --out reference/grid.json
"""
import argparse
import json
import os
import sys

import cv2
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from fsscanner import grid  # noqa: E402
from fsscanner.layout import Layout  # noqa: E402
from fsscanner.assign import layout_panel_index  # noqa: E402


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--image", required=True)
    ap.add_argument("--layout", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--grid-panels", default="1,2", help="Panels mit Zeilenraster")
    args = ap.parse_args()

    lay = Layout(args.layout)
    img = cv2.imread(args.image)
    ink = grid.binarize(img)
    hor, ver = grid.line_masks(ink)
    grid_panels = [int(v) for v in args.grid_panels.split(",")]
    rows, vsegs = {}, {}
    for pi in grid_panels:
        rect = lay.panels[pi]
        lines = grid.track_h_lines(hor, rect)
        lines = [l for l in lines if l["len"] >= 0.5 * (rect[2] - rect[0])]
        ys = [round(l["y_mean"], 1) for l in lines]
        # Rahmenlinie oben liegt am Bildrand und wird oft nicht verfolgt: virtuell ergaenzen
        pitch = float(np.median(np.diff(ys)))
        if ys[0] - pitch > 0:
            ys.insert(0, round(ys[0] - pitch, 1))
        rows[str(pi)] = ys
        # Linienanfang/-ende = Panelrahmen (fuer den seitlichen Versatz zur Laufzeit)
        longs = [l for l in lines if l["len"] >= 0.6 * (rect[2] - rect[0])]
        frames = frames if "frames" in dir() else {}
        frames[str(pi)] = [round(float(np.median([l["x_start"] for l in longs])), 1),
                           round(float(np.median([l["x_end"] for l in longs])), 1)]
        segs = grid.v_segments(ver, rect, min_len=44)
        out = []
        for sg in segs:
            cy = (sg["y_start"] + sg["y_end"]) / 2
            # Zeilenindex: Linie oberhalb der Segmentmitte
            above = [i for i, y in enumerate(ys) if y <= cy]
            if not above:
                continue
            out.append([round(sg["x_mean"], 1), above[-1]])
        vsegs[str(pi)] = out
        print(f"Panel {pi}: {len(ys)} Zeilenlinien, {len(out)} Trennlinien; Abstand ~{np.median(np.diff(ys)):.1f}")

    fields = {}
    for name, spec in lay.items():
        box = spec["box"]
        pi = layout_panel_index(lay, box)
        if pi not in grid_panels and not spec.get("multiline"):
            continue
        # mehrzeilig (Feld 22) reicht ueber Panels: Zeilen aus dem Panel, in dem es beginnt
        p_use = pi if pi in grid_panels else grid_panels[0]
        ys = rows[str(p_use)]
        if spec.get("multiline"):
            r0 = max([i for i, y in enumerate(ys) if y <= box[1] + 10] or [0])
            r1 = max([i for i, y in enumerate(ys) if y <= box[3] - 10] or [r0])
            fields[name] = {"panel": p_use, "row": r0, "rows": list(range(r0, r1 + 1)), "x0": box[0], "x1": box[2],
                            "multiline": True}
        else:
            cy = (box[1] + box[3]) / 2
            above = [i for i, y in enumerate(ys) if y <= cy]
            if not above or above[-1] + 1 >= len(ys):
                print("  ohne Zeile:", name)
                continue
            fields[name] = {"panel": pi, "row": above[-1], "x0": box[0], "x1": box[2], "multiline": False}
    json.dump({"rows": rows, "vsegs": vsegs, "frames": frames, "fields": fields, "pitch": float(np.median(np.diff(rows[str(grid_panels[0])])))},
              open(args.out, "w"), indent=1)
    print(len(fields), "Felder ->", args.out)


if __name__ == "__main__":
    main()
