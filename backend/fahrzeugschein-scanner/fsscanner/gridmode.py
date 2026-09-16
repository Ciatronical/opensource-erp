"""
Rasterbasiertes Lesen der Feldpanels (klassische Formularerkennung).

Ablauf je Panel:
  1. Zeilenlinien im entzerrten Dokument verfolgen (grid.track_h_lines).
  2. Den Referenzzeilen zuordnen: y_det ~ a * y_ref + b (RANSAC ueber Linienpaare,
     |Versatz| < halber Zeilenabstand -> eindeutig), fehlende Linien interpolieren.
  3. Seitlicher Versatz aus den senkrechten Trennlinien der Kaestchen.
  4. Jede Feldzelle liegt zwischen zwei (gekruemmten) Linien: entlang der Kurven
     geradeziehen, Textbereich per Tintenmaske eingrenzen, erkennen.
"""
import json
import os

import cv2
import numpy as np

from . import grid


class GridReader:
    def __init__(self, layout, grid_path=None):
        self.layout = layout
        path = grid_path or os.path.join(os.path.dirname(__file__), "..", "reference", "grid.json")
        g = json.load(open(path))
        self.rows = {int(k): np.array(v, float) for k, v in g["rows"].items()}
        self.vsegs = {int(k): v for k, v in g["vsegs"].items()}
        self.fields = g["fields"]
        self.pitch = float(g["pitch"])
        self.frames = {int(k): v for k, v in g.get("frames", {}).items()}
        self.panels = layout.panels
        # Referenz-Trennlinien je (Panel, Zeile) und die Rahmenlinie (x mit den meisten Zeilen)
        self.ref_seps = {}
        self.ref_frame = {}
        for pi, segs in self.vsegs.items():
            xs = {}
            for x, row in segs:
                self.ref_seps.setdefault((pi, row), []).append(x)
                key = round(x / 8) * 8
                xs[key] = xs.get(key, 0) + 1
            if xs:
                self.ref_frame[pi] = float(max(xs, key=xs.get))

    # ---- Linien zuordnen -------------------------------------------------
    def _sep_score(self, panel, a, b, det_lines, det_vsegs, tol=14.0):
        """Wie viele Referenz-Trennlinien (Kaestchenraender je Zeile) finden bei dieser
        Zeilenzuordnung eine senkrechte Linie im Foto? Zeilen-Fingerabdruck gegen
        die Periodik des Rasters (Versatz um eine Zeile)."""
        if not det_vsegs:
            return 0
        det_ys = np.array([l["y_mean"] for l in det_lines], float)
        score = 0
        for (pi, row), xs in self.ref_seps.items():
            if pi != panel:
                continue
            y_top = a * self.rows[panel][row] + b
            y_bot = y_top + a * self.pitch
            for x in xs:
                for sg in det_vsegs:
                    if abs(sg["x_mean"] - x) <= 30 and sg["y_start"] <= y_top + 15 and sg["y_end"] >= y_bot - 15:
                        score += 1
                        break
        return score

    def _match_rows(self, ref_ys, det_lines, panel_rect, tol=11.0, panel=None, det_vsegs=None):
        det_ys = np.array([l["y_mean"] for l in det_lines], float)
        n_ref = len(ref_ys)
        if len(det_ys) < 3:
            return None
        pc = (panel_rect[1] + panel_rect[3]) / 2
        # Kandidaten: Versatz bis +-1,5 Zeilen (Registrierung kann eine Zeile daneben liegen)
        cands = []
        for a in np.arange(0.98, 1.021, 0.005):
            for d in det_ys:
                for r in ref_ys:
                    b = d - a * r
                    off = (a - 1) * pc + b
                    if abs(off) > 1.5 * self.pitch:
                        continue
                    pred = a * ref_ys + b
                    n = int(sum(1 for pv in pred if np.min(np.abs(det_ys - pv)) <= tol))
                    cands.append((n, float(a), float(b), abs(off) + abs(a - 1) * 300))
        if not cands:
            return None
        n_max = max(c[0] for c in cands)
        good = [c for c in cands if c[0] >= n_max - 1]
        # Bei periodischer Mehrdeutigkeit entscheidet der Trennlinien-Fingerabdruck,
        # danach der kleinste Versatz
        if panel is not None and det_vsegs:
            scored = []
            for c in good:
                scored.append((self._sep_score(panel, c[1], c[2], det_lines, det_vsegs), -c[3], c))
            scored.sort(reverse=True)
            best = scored[0][2]
        else:
            best = min(good, key=lambda c: c[3])
        n, a, b, _ = best
        if n < 3:
            return None
        mapping = []
        pred = a * ref_ys + b
        for i in range(n_ref):
            j = int(np.argmin(np.abs(det_ys - pred[i])))
            mapping.append(det_lines[j] if abs(det_ys[j] - pred[i]) <= tol else None)
        # fehlende Linien: aus der naechsten gefundenen Linie verschieben (gleiche Kruemmung)
        for i in range(n_ref):
            if mapping[i] is None:
                cands = [k for k in range(n_ref) if mapping[k] is not None]
                k = min(cands, key=lambda k: abs(k - i))
                src = mapping[k]
                mapping[i] = {"xs": src["xs"], "ys": src["ys"] + (pred[i] - pred[k]), "synth": True}
        return mapping, a, b, n

    def _dx(self, panel, mapping, det_vsegs):
        """Seitlicher Versatz des Panels: zuerst grob ueber die lange Rahmenlinie
        (viele Zeilen hoch, eindeutig bis +-200 px), dann fein ueber die Trennlinien."""
        coarse = 0.0
        fx = self.ref_frame.get(panel)
        if fx is not None:
            longs = [sg for sg in det_vsegs if sg["len"] >= 4 * self.pitch and abs(sg["x_mean"] - fx) <= 200]
            if longs:
                coarse = float(min(longs, key=lambda sg: abs(sg["x_mean"] - fx))["x_mean"] - fx)
        diffs = []
        for x_ref, row in self.vsegs.get(panel, []):
            if row >= len(mapping) - 1:
                continue
            y_top = grid.y_at(mapping[row], x_ref)
            y_bot = grid.y_at(mapping[row + 1], x_ref)
            for sg in det_vsegs:
                if sg["y_start"] <= y_top + 12 and sg["y_end"] >= y_bot - 12 and abs(sg["x_mean"] - x_ref - coarse) <= 25:
                    diffs.append(sg["x_mean"] - x_ref)
        if len(diffs) < 3:
            return coarse
        h, edges = np.histogram(diffs, bins=np.arange(-45, 46, 6))
        k = int(np.argmax(h))
        sel = [d for d in diffs if edges[k] <= d < edges[k + 1] + 6]
        return float(np.median(sel)) if sel else float(np.median(diffs))

    # ---- Zelle ausschneiden ----------------------------------------------
    @staticmethod
    def dewarp_cell(img, top, bot, x0, x1, scale=1.0, pad_y=3):
        """Bereich zwischen zwei Linienkurven [x0, x1] geradeziehen (remap).
        img liegt in scale-facher Referenzaufloesung."""
        x0, x1 = int(x0), int(x1)
        if x1 - x0 < 6:
            return None
        xs = np.arange(x0, x1, dtype=np.float32)
        yt = np.array([grid.y_at(top, x) for x in xs], np.float32) + pad_y
        yb = np.array([grid.y_at(bot, x) for x in xs], np.float32) - pad_y
        h = int(round(float(np.median(yb - yt)) * scale))
        w = int(round((x1 - x0) * scale))
        if h < 6 or w < 6:
            return None
        t = np.linspace(0, 1, h, dtype=np.float32)[:, None]
        map_y = (yt[None, :] * (1 - t) + yb[None, :] * t) * scale
        map_x = np.repeat(xs[None, :] * scale, h, axis=0)
        if w != x1 - x0:  # auf Zielbreite interpolieren
            cols = np.linspace(0, x1 - x0 - 1, w)
            map_x = np.array([np.interp(cols, np.arange(x1 - x0), row) for row in map_x], np.float32)
            map_y = np.array([np.interp(cols, np.arange(x1 - x0), row) for row in map_y], np.float32)
        return cv2.remap(img, map_x.astype(np.float32), map_y.astype(np.float32), cv2.INTER_CUBIC,
                         borderMode=cv2.BORDER_REPLICATE)

    @staticmethod
    def text_extent(cell_bgr, margin=3):
        """Textbereich in der Zelle (Tinte ohne Rasterlinienreste); None wenn leer."""
        ink = grid.binarize(cell_bgr)
        h, w = ink.shape
        ink[:margin, :] = 0; ink[h - margin:, :] = 0; ink[:, :2] = 0; ink[:, w - 2:] = 0
        # duenne waagerechte Reste (Linien) und senkrechte Trennlinien entfernen
        hk = cv2.getStructuringElement(cv2.MORPH_RECT, (max(20, w // 4), 1))
        ink[cv2.morphologyEx(ink, cv2.MORPH_OPEN, hk) > 0] = 0
        vk = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(20, int(0.8 * h))))
        ink[cv2.morphologyEx(ink, cv2.MORPH_OPEN, vk) > 0] = 0
        ink = cv2.morphologyEx(ink, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
        cols = np.flatnonzero((ink > 0).sum(axis=0) >= 2)
        rows = np.flatnonzero((ink > 0).sum(axis=1) >= 2)
        if len(cols) < 4 or len(rows) < 6:
            return None
        return int(cols[0]), int(rows[0]), int(cols[-1]) + 1, int(rows[-1]) + 1

    def _match_rows_seq(self, ref_ys, det_lines, panel_rect, panel, det_vsegs):
        """Robuste Zuordnung bei ungleichmaessiger Streckung: lineare Loesung nur als Anker
        (Linie mit kleinstem Fehler), von dort Linie fuer Linie nach oben/unten laufen und
        Abstaende in Vielfachen des Zeilenabstands zaehlen."""
        base = self._match_rows(ref_ys, det_lines, panel_rect, panel=panel, det_vsegs=det_vsegs)
        if base is None:
            return None
        mapping0, a, b, n0 = base
        det = sorted(det_lines, key=lambda l: l["y_mean"])
        det_ys = np.array([l["y_mean"] for l in det], float)
        pred = a * ref_ys + b
        # Anker: Referenzzeile, deren Vorhersage am besten zu einer Linie passt
        errs = [np.min(np.abs(det_ys - pv)) for pv in pred]
        i0 = int(np.argmin(errs))
        j0 = int(np.argmin(np.abs(det_ys - pred[i0])))
        pitch = a * self.pitch
        n_ref = len(ref_ys)
        assign = {i0: j0}
        # nach unten
        i, j = i0, j0
        while i + 1 < n_ref and j + 1 < len(det_ys):
            gap = det_ys[j + 1] - det_ys[j]
            k = int(round(gap / pitch))
            if k < 1:
                j += 1; continue          # Doppellinie / Stoerung
            if i + k >= n_ref:
                break
            if abs(gap - k * pitch) <= 0.3 * pitch:
                i, j = i + k, j + 1
                assign[i] = j
            else:
                break
        # nach oben
        i, j = i0, j0
        while i - 1 >= 0 and j - 1 >= 0:
            gap = det_ys[j] - det_ys[j - 1]
            k = int(round(gap / pitch))
            if k < 1:
                j -= 1; continue
            if i - k < 0:
                break
            if abs(gap - k * pitch) <= 0.3 * pitch:
                i, j = i - k, j - 1
                assign[i] = j
            else:
                break
        mapping = [None] * n_ref
        for i, j in assign.items():
            mapping[i] = det[j]
        n = len(assign)
        # Luecken: naechste zugeordnete Linie um Vielfache des Abstands verschieben
        for i in range(n_ref):
            if mapping[i] is None:
                cands = [k for k in assign]
                k = min(cands, key=lambda k: abs(k - i))
                src = mapping[k]
                mapping[i] = {"xs": src["xs"], "ys": src["ys"] + (i - k) * pitch, "synth": True}
        return mapping, a, b, max(n, n0 if n0 > n else n)

    def dx_from_line_ends(self, panel, lines, max_off=200):
        """Seitlicher Versatz aus den Enden der Zeilenlinien (= Panelrahmen)."""
        fr = self.frames.get(panel)
        if not fr:
            return None
        rect = self.panels[panel]
        longs = [l for l in lines if l["len"] >= 0.6 * (rect[2] - rect[0])]
        if len(longs) < 4:
            return None
        starts = np.array([l["x_start"] for l in longs])
        ends = np.array([l["x_end"] for l in longs])
        d_start = float(np.median(starts)) - fr[0]
        d_end = float(np.median(ends)) - fr[1]
        # Panelrand am Bildrand abgeschnitten -> nur die plausible Seite verwenden
        cands = [d for d in (d_start, d_end) if abs(d) <= max_off]
        if not cands:
            return None
        if len(cands) == 2 and abs(d_start - d_end) > 40:
            # Breite passt nicht (Streckung/Abschnitt): die Seite mit mehr Linienenden im Bild
            return d_start if abs(d_start) <= abs(d_end) else d_end
        return float(np.mean(cands))

    def dx_from_rows(self, panel, mapping, det_vsegs, max_off=170):
        """Seitlicher Versatz aus den Kaestchen-Trennlinien je Zeile (Zeilen sind bekannt):
        Histogramm der Differenzen det - ref ueber alle Zeilen, Modus."""
        diffs = []
        for (pi, row), xs in self.ref_seps.items():
            if pi != panel or row + 1 >= len(mapping):
                continue
            for x in xs:
                y_top = grid.y_at(mapping[row], x)
                y_bot = grid.y_at(mapping[row + 1], x)
                for sg in det_vsegs:
                    if sg["y_start"] <= y_top + 14 and sg["y_end"] >= y_bot - 14 and abs(sg["x_mean"] - x) <= max_off:
                        diffs.append(sg["x_mean"] - x)
        if len(diffs) < 4:
            return None
        h, edges = np.histogram(diffs, bins=np.arange(-max_off, max_off + 1, 8))
        k = int(np.argmax(h))
        if h[k] < 4:
            return None
        sel = [d for d in diffs if edges[k] - 8 <= d < edges[k + 1] + 8]
        return float(np.median(sel))

    def row_maps(self, warped, min_frac=0.75):
        """Nur Zeilenlinien: {panel: (mapping, ref_ys)} fuer Panels, in denen die Zuordnung
        sicher ist (>= min_frac der Referenzlinien gefunden). Fuer den Detektionsmodus."""
        ink = grid.binarize(warped)
        hor, ver = grid.line_masks(ink)
        out = {}
        for pi, ref_ys in self.rows.items():
            rect = self.panels[pi]
            lines = grid.track_h_lines(hor, rect)
            lines = [l for l in lines if l["len"] >= 0.35 * (rect[2] - rect[0])]
            vs = grid.v_segments(ver, rect, min_len=44)
            m = self._match_rows_seq(ref_ys, lines, rect, pi, vs)
            if m is None:
                continue
            mapping, a, b, n = m
            if n >= min_frac * len(ref_ys):
                dx = self.dx_from_line_ends(pi, lines)
                out[pi] = (mapping, ref_ys, n, dx)
        return out

    @staticmethod
    def y_to_ref(mapping, ref_ys, x, y):
        """y im Foto -> y in Referenzkoordinaten, stueckweise linear zwischen den Linien."""
        ys = [grid.y_at(l, x) for l in mapping]
        for i in range(len(ys) - 1):
            if ys[i] <= y < ys[i + 1]:
                t = (y - ys[i]) / max(1e-6, ys[i + 1] - ys[i])
                return ref_ys[i] + t * (ref_ys[i + 1] - ref_ys[i])
        if y < ys[0]:
            return ref_ys[0] - (ys[0] - y)
        return ref_ys[-1] + (y - ys[-1])

    # ---- Hauptablauf -------------------------------------------------------
    def read(self, warped, warped_hi, hi_scale, ocr, debug=None):
        """warped: Dokument in Referenzaufloesung, warped_hi: hi_scale-fach fuer OCR.
        Liefert (texts: name -> (text, score), crops: name -> Bild, meta)."""
        ink = grid.binarize(warped)
        hor, ver = grid.line_masks(ink)
        meta = {}
        mappings = {}
        dxs = {}
        for pi, ref_ys in self.rows.items():
            rect = self.panels[pi]
            lines = grid.track_h_lines(hor, rect)
            lines = [l for l in lines if l["len"] >= 0.35 * (rect[2] - rect[0])]
            vs = grid.v_segments(ver, rect, min_len=44)
            m = self._match_rows(ref_ys, lines, rect, panel=pi, det_vsegs=vs)
            if m is None:
                meta[pi] = {"lines": len(lines), "matched": 0}
                continue
            mapping, a, b, n = m
            dx = self._dx(pi, mapping, vs)
            mappings[pi] = (mapping, vs)
            dxs[pi] = dx
            meta[pi] = {"lines": len(lines), "matched": n, "a": round(a, 3), "b": round(b, 1), "dx": round(dx, 1)}

        items, crops = [], {}
        for name, f in self.fields.items():
            pi = f["panel"]
            if pi not in mappings:
                continue
            mapping, vs = mappings[pi]
            dx = dxs[pi]
            rows_idx = f["rows"] if f.get("multiline") else [f["row"]]
            for li, ri in enumerate(rows_idx):
                if ri + 1 >= len(mapping):
                    continue
                top, bot = mapping[ri], mapping[ri + 1]
                # mehrzeilig ueber Panels: je Panel eigener Abschnitt (Faltkante)
                spans = [(f["x0"] + dx, f["x1"] + dx)]
                if f.get("multiline"):
                    spans = []
                    for pj, prect in enumerate(self.panels):
                        if pj not in mappings:
                            continue
                        sx0, sx1 = max(f["x0"], prect[0]), min(f["x1"], prect[2])
                        if sx1 - sx0 > 40:
                            spans.append((sx0 + dxs[pj], sx1 + dxs[pj], pj))
                for si, span in enumerate(spans):
                    sx0, sx1 = span[0], span[1]
                    pj = span[2] if len(span) > 2 else pi
                    mp = mappings[pj][0]
                    if ri + 1 >= len(mp):
                        continue
                    top, bot = mp[ri], mp[ri + 1]
                    vsj = mappings[pj][1]
                    dxj = dxs[pj]
                    y_t, y_b = grid.y_at(top, sx0), grid.y_at(bot, sx0)
                    near = [sg["x_mean"] for sg in vsj if sg["y_start"] <= y_t + 14 and sg["y_end"] >= y_b - 14]
                    prect = self.panels[pj]
                    if not f.get("multiline"):
                        # Zelle = zwischen Trennlinien: links der Rand des Bezeichnerkaestchens,
                        # rechts das naechste Kaestchen (oder der Panelrand)
                        rs = sorted(self.ref_seps.get((pj, ri), []))
                        lref = [x for x in rs if x <= f["x0"] + 15]
                        rref = [x for x in rs if x > f["x0"] + 30]
                        lx = (max(lref) if lref else f["x0"] - 2) + dxj
                        rx = (min(rref) if rref else prect[2] - 10) + dxj
                        lsnap = [x for x in near if abs(x - lx) <= 20]
                        rsnap = [x for x in near if abs(x - rx) <= 20]
                        if lsnap:
                            lx = min(lsnap, key=lambda x: abs(x - lx))
                        if rsnap:
                            rx = min(rsnap, key=lambda x: abs(x - rx))
                        sx0, sx1 = lx + 5, rx - 4
                    else:
                        left = [x for x in near if abs(x - sx0) <= 28]
                        if left:
                            sx0 = max(left) + 4
                    if debug is not None:
                        debug.append((name, sx0, sx1, top, bot))
                    cell = self.dewarp_cell(warped_hi, top, bot, sx0, sx1, scale=hi_scale)
                    if cell is None:
                        continue
                    ext = self.text_extent(cell)
                    if ext is None:
                        continue
                    ex0, ey0, ex1, ey1 = ext
                    p = 6
                    crop = cell[max(0, ey0 - p):min(cell.shape[0], ey1 + p), max(0, ex0 - p):min(cell.shape[1], ex1 + p)]
                    items.append(((name, li, si), crop, False))
                    if not f.get("multiline"):
                        crops[name] = crop
        raw = ocr.recognize(items)
        texts = {}
        for name, f in self.fields.items():
            parts = sorted([(k[1], k[2], v) for k, v in raw.items() if k[0] == name])
            if not parts:
                continue
            lines_txt, scs = [], []
            for li in sorted(set(p[0] for p in parts)):
                words = [v[0].strip() for (l, s_, v) in parts if l == li and v[0].strip()]
                scs.extend(v[1] for (l, s_, v) in parts if l == li)
                if words:
                    lines_txt.append(" ".join(words))
            texts[name] = ("\n".join(lines_txt), float(np.mean(scs)) if scs else 0.0)
        return texts, crops, meta
