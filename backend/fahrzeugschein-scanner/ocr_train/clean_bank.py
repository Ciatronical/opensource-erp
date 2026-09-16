#!/usr/bin/env python3
"""Glyphenbank bereinigen: je Zeichen nur Exemplare nahe am Medoid (dominantes Cluster)."""
import argparse
import numpy as np
import cv2

ap = argparse.ArgumentParser()
ap.add_argument("--bank", required=True)
ap.add_argument("--out", required=True)
ap.add_argument("--keep", type=float, default=0.6, help="Anteil der naechsten Exemplare am Medoid")
args = ap.parse_args()
z = np.load(args.bank)
out = {}
for k in z.files:
    g = z[k].astype(np.float32)
    n = len(g)
    if n < 3:
        out[k] = z[k]
        continue
    # Kontrast normieren (Tinte dunkel = hoch)
    v = 255 - g.reshape(n, -1)
    v = (v - v.mean(axis=1, keepdims=True)) / (v.std(axis=1, keepdims=True) + 1e-6)
    d = np.linalg.norm(v[:, None, :] - v[None, :, :], axis=2)
    medoid = int(np.argmin(d.sum(axis=1)))
    order = np.argsort(d[medoid])
    keep = order[:max(3, int(args.keep * n))]
    out[k] = z[k][keep]
print({chr(int(k[2:], 16)): len(v) for k, v in sorted(out.items())})
np.savez_compressed(args.out, **out)
