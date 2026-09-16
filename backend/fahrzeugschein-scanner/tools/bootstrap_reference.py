#!/usr/bin/env python3
"""
Bootstrap der Referenzvorlage aus EINEM flachen, vollstaendigen Foto.

Entzerrt das Foto anhand der gedruckten Linien des Formulars (aeusserste lange
waagerechte/senkrechte Linien -> Viereck -> Perspektivkorrektur) und speichert
das Ergebnis als reference/zb1_bootstrap.jpg. Wird nur einmal gebraucht; danach
registriert learn_layout.py alle weiteren Fotos per SIFT gegen diese Vorlage.

Aufruf: tools/bootstrap_reference.py <original.jpg> [--rotate 0|90|180|270] [--width 2400]
"""
import argparse
import os
import sys

import cv2
import numpy as np

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from fsscanner.imageio import load_file, limit_size  # noqa: E402


def detect_segments(gray, min_frac):
    """Lange waagerechte/senkrechte Linien: Binarisierung -> morphologisches Oeffnen
    mit langem Kernel (laesst nur Linien uebrig) -> HoughLinesP."""
    h, w = gray.shape
    bw = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, 31, 8)
    out = []
    for kern, min_len in (((max(15, w // 50), 1), min_frac * w), ((1, max(15, h // 50)), min_frac * h)):
        k = cv2.getStructuringElement(cv2.MORPH_RECT, kern)
        lines_img = cv2.morphologyEx(bw, cv2.MORPH_OPEN, k)
        segs = cv2.HoughLinesP(lines_img, 1, np.pi / 360, 60, minLineLength=int(min_len), maxLineGap=int(0.02 * w))
        if segs is not None:
            out.append(segs.reshape(-1, 4).astype(np.float64))
    return np.vstack(out) if out else np.zeros((0, 4))


def split_hv(segs, min_len):
    dx = segs[:, 2] - segs[:, 0]
    dy = segs[:, 3] - segs[:, 1]
    length = np.hypot(dx, dy)
    ang = np.degrees(np.arctan2(dy, dx)) % 180
    long_ = length >= min_len
    horiz = segs[long_ & ((ang < 15) | (ang > 165))]
    vert = segs[long_ & (ang > 75) & (ang < 105)]
    return horiz, vert


def seg_to_line(s):
    p1 = np.array([s[0], s[1], 1.0])
    p2 = np.array([s[2], s[3], 1.0])
    return np.cross(p1, p2)


def extreme_lines(horiz, vert):
    """Aeusserste lange Linien: oben/unten (nach mittlerem y), links/rechts (nach x).
    Nur Linien mit typischer Laenge (0.7..1.4 x Median) — schliesst Fremdkanten
    (Tastatur, Tischkante) aus, die deutlich laenger sind als die Formularlinien."""
    def typical(segs):
        ln = np.hypot(segs[:, 2] - segs[:, 0], segs[:, 3] - segs[:, 1])
        med = np.median(ln)
        keep = (ln >= 0.7 * med) & (ln <= 1.4 * med)
        return segs[keep] if keep.sum() >= 2 else segs
    horiz, vert = typical(horiz), typical(vert)
    hy = (horiz[:, 1] + horiz[:, 3]) / 2
    vx = (vert[:, 0] + vert[:, 2]) / 2
    top = horiz[np.argmin(hy)]
    bottom = horiz[np.argmax(hy)]
    left = vert[np.argmin(vx)]
    right = vert[np.argmax(vx)]
    return top, bottom, left, right


def intersect(l1, l2):
    p = np.cross(l1, l2)
    return p[:2] / p[2]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("image")
    ap.add_argument("--rotate", type=int, default=0)
    ap.add_argument("--width", type=int, default=2400, help="Zielbreite des entzerrten Vierecks")
    ap.add_argument("--min-len", type=float, default=0.15, help="Mindestlaenge langer Linien (Anteil der Bildbreite)")
    ap.add_argument("--margins", default="0.05,0.05,0.05,0.05",
                    help="Rand oben,rechts,unten,links als Anteil der Viereckhoehe bzw. -breite")
    ap.add_argument("--out", default=os.path.join(os.path.dirname(__file__), "..", "reference", "zb1_bootstrap.jpg"))
    ap.add_argument("--debug", default=None, help="Debugbild mit Linien")
    args = ap.parse_args()

    img = load_file(args.image)
    rot = {90: cv2.ROTATE_90_CLOCKWISE, 180: cv2.ROTATE_180, 270: cv2.ROTATE_90_COUNTERCLOCKWISE}
    if args.rotate in rot:
        img = cv2.rotate(img, rot[args.rotate])

    work = limit_size(img, 2000)
    sc = img.shape[1] / work.shape[1]
    gray = cv2.cvtColor(work, cv2.COLOR_BGR2GRAY)
    segs = detect_segments(gray, args.min_len)
    horiz, vert = split_hv(segs, args.min_len * min(work.shape[:2]))
    print(f"Segmente: {len(segs)}, lang waagerecht: {len(horiz)}, lang senkrecht: {len(vert)}")
    if len(horiz) < 2 or len(vert) < 2:
        sys.exit("Zu wenige lange Linien gefunden — anderes Foto oder --min-len senken")

    top, bottom, left, right = extreme_lines(horiz, vert)
    lt, lb, ll, lr = (seg_to_line(s) for s in (top, bottom, left, right))
    quad = np.array([intersect(lt, ll), intersect(lt, lr), intersect(lb, lr), intersect(lb, ll)], np.float32)
    quad *= sc  # zurueck in Originalkoordinaten
    print("Viereck (Original-Pixel):", quad.round(1).tolist())

    wtop = np.linalg.norm(quad[1] - quad[0]); wbot = np.linalg.norm(quad[2] - quad[3])
    hl = np.linalg.norm(quad[3] - quad[0]); hr = np.linalg.norm(quad[2] - quad[1])
    aspect = ((hl + hr) / 2) / ((wtop + wbot) / 2)
    W = args.width
    H = int(round(W * aspect))
    dst = np.array([[0, 0], [W, 0], [W, H], [0, H]], np.float32)
    M = cv2.getPerspectiveTransform(quad, dst)

    # Rand um das Viereck mitnehmen, damit die aeusseren Felder nicht abgeschnitten werden
    mt, mr, mb, ml = (float(v) for v in args.margins.split(","))
    mt, mb = int(mt * H), int(mb * H); ml, mr = int(ml * W), int(mr * W)
    T = np.array([[1, 0, ml], [0, 1, mt], [0, 0, 1]], np.float64)
    warped = cv2.warpPerspective(img, T @ M, (W + ml + mr, H + mt + mb), flags=cv2.INTER_CUBIC,
                                 borderMode=cv2.BORDER_REPLICATE)
    os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
    cv2.imwrite(args.out, warped, [cv2.IMWRITE_JPEG_QUALITY, 95])
    print("gespeichert:", args.out, warped.shape)

    if args.debug:
        dbg = work.copy()
        for s in horiz: cv2.line(dbg, (int(s[0]), int(s[1])), (int(s[2]), int(s[3])), (0, 0, 255), 2)
        for s in vert: cv2.line(dbg, (int(s[0]), int(s[1])), (int(s[2]), int(s[3])), (255, 0, 0), 2)
        q = (quad / sc).astype(int)
        cv2.polylines(dbg, [q.reshape(-1, 1, 2)], True, (0, 255, 0), 3)
        cv2.imwrite(args.debug, dbg)


if __name__ == "__main__":
    main()
