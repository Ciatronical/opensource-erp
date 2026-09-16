"""
Zuordnung detektierter Textkaesten zu Layoutfeldern.

Statt Ausschnitte blind aus dem Layout zu schneiden, liefert die Textdetektion die
tatsaechlichen Textzeilen im entzerrten Dokument. Je Panel wird die Verschiebung
(dx, dy) gewaehlt, bei der die meisten Kastenmittelpunkte in (vertikal verengte)
Layoutfelder fallen — das faengt Restfehler der Entzerrung von bis zu einer Zeile ab.
Danach bekommt jedes Feld die Kaesten, deren Mittelpunkt in seinem Bereich liegt.
"""
import os

import numpy as np

# Felder, deren Bezeichner in einem kleinen Kaestchen unmittelbar links vom Wert steht
ADJ_LABEL_FIELDS = {"hsn", "field_2_2", "field_3", "field_4", "p1", "field_14_1", "field_10", "field_9", "l", "t",
                    "f1", "f2", "u1", "u2", "u3", "o1", "o2", "s1", "s2", "field_7_1", "field_7_2", "field_7_3",
                    "field_8_1", "field_8_2", "field_8_3", "field_12", "field_13", "q", "v7", "field_17", "field_16",
                    "field_11", "field_15_3", "field_18", "field_19", "field_20", "g", "p2_p4", "field_15_1",
                    "field_15_2", "k", "r"}


def det_to_rects(boxes):
    """Vier-Punkt-Kaesten -> achsenparallele Rechtecke [x0, y0, x1, y1]."""
    rects = []
    for b in boxes:
        pts = np.asarray(b, dtype=float).reshape(-1, 2)
        rects.append([float(pts[:, 0].min()), float(pts[:, 1].min()),
                      float(pts[:, 0].max()), float(pts[:, 1].max())])
    return rects


class Assigner:
    def __init__(self, layout, shrink: float = 0.6, grid=None):
        self.layout = layout
        self.pitch = layout.row_pitch
        self.fields = []
        for name, spec in layout.items():
            x0, y0, x1, y1 = spec["box"]
            cy = (y0 + y1) / 2
            # Feldmitte aus dem Referenzraster (zwischen Zeilenlinie i und i+1) — genauer als
            # die aus alten Ausschnitten gelernten Boxen, deren Mitte bei einigen Feldern
            # eine halbe Zeile daneben liegt
            if grid is not None and name in grid.fields and not grid.fields[name].get("multiline"):
                gf = grid.fields[name]
                ys = grid.rows.get(gf["panel"])
                if ys is not None and gf["row"] + 1 < len(ys):
                    cy = (ys[gf["row"]] + ys[gf["row"] + 1]) / 2
                    y0, y1 = cy - 0.6 * self.pitch, cy + 0.6 * self.pitch
            multiline = bool(spec.get("multiline"))
            # zum Zaehlen/Zuordnen vertikal verengt (ausser mehrzeilig)
            sy0, sy1 = (y0, y1) if multiline else (cy - shrink * self.pitch / 2, cy + shrink * self.pitch / 2)
            self.fields.append({"name": name, "box": [x0, y0, x1, y1], "score_box": [x0, sy0, x1, sy1],
                                "cy": cy, "multiline": multiline,
                                "panel": layout_panel_index(layout, [x0, y0, x1, y1])})
        # Wertebereich bis zum naechsten Feld derselben Zeile bzw. bis zum Panelrand ausdehnen:
        # Textkaesten duerfen nicht in eine Luecke zwischen zwei Feldern fallen
        for f in self.fields:
            if f["multiline"] or f["panel"] is None:
                continue
            same_row = [g for g in self.fields if g is not f and not g["multiline"] and g["panel"] == f["panel"]
                        and abs(g["cy"] - f["cy"]) <= 0.5 * self.pitch and g["box"][0] > f["box"][0] + 20]
            if same_row:
                f["box"][2] = max(f["box"][2], min(g["box"][0] for g in same_row) - 8)
            else:
                f["box"][2] = max(f["box"][2], self.panels_right(f["panel"]) - 10)

    def panels_right(self, panel):
        return self.layout.panels[panel][2]

    def _inside(self, box, cx, cy):
        return box[0] <= cx <= box[2] and box[1] <= cy <= box[3]

    def _panel_fields(self, panel):
        """Felder des Panels plus mehrzeilige Felder, die in das Panel hineinreichen (Feld 22)."""
        out = []
        for f in self.fields:
            if f["panel"] == panel:
                out.append(f)
            elif f["multiline"] and panel is not None and panel < len(self.layout.panels):
                px0, _, px1, _ = self.layout.panels[panel]
                if f["box"][0] < px1 and f["box"][2] > px0:
                    out.append(f)
        return out

    @staticmethod
    def _cluster_1d(values, tol):
        vals = sorted(values)
        groups = []
        for v in vals:
            if groups and v - groups[-1][-1] < tol:
                groups[-1].append(v)
            else:
                groups.append([v])
        return [float(np.mean(g)) for g in groups]

    def row_fit(self, rects, panel, min_width=55, scales=np.arange(0.94, 1.061, 0.01), tol=12.0):
        """Lineare Zeilenanpassung y_layout = a * y_det + b: erkannte Textzeilen (Cluster der
        Kastenmittelpunkte) gegen die Layoutzeilen des Panels; RANSAC-artig ueber alle
        Anker-Paare, bestes (a, b) = meiste Layoutzeilen mit einer Textzeile in Toleranz.
        Faengt Versatz UND Streckung eines Panels ab (gebogenes Papier)."""
        fields = [f for f in self._panel_fields(panel) if f["panel"] == panel and not f["multiline"]]
        lay = self._cluster_1d([f["cy"] for f in fields], 0.35 * self.pitch)
        det = self._cluster_1d([(r[1] + r[3]) / 2 for r in rects if r[2] - r[0] >= min_width], 0.4 * self.pitch)
        if len(lay) < 2 or len(det) < 2:
            return 1.0, 0.0, 0
        lay_a, det_a = np.array(lay), np.array(det)
        # Plausibilitaet: Versatz in Panelmitte hoechstens max_off, Streckung hoechstens 5 %
        px0, py0, px1, py1 = self.layout.panels[panel]
        pc = (py0 + py1) / 2
        max_off = 45.0
        best = (0, 1.0, 0.0, 1e9)  # (n, a, b, Kosten)
        for a in scales:
            for d in det_a:
                for l in lay_a:
                    b = l - a * d
                    off = (a - 1) * pc + b
                    if abs(off) > max_off:
                        continue
                    y = a * det_a + b
                    n = int(sum(1 for lv in lay_a if np.min(np.abs(y - lv)) <= tol))
                    cost = abs(off) + abs(a - 1) * 400
                    # mehr Treffer gewinnt; bei fast gleich vielen die kleinste Korrektur
                    if n > best[0] + 1 or (n >= best[0] - 0 and n >= best[0] and cost < best[3]) or (n == best[0] + 1 and cost < best[3] + 20):
                        best = (n, float(a), float(b), cost)
        return best[1], best[2], best[0]

    def col_fit(self, rects, panel, min_width=55, tol=16.0, max_off=130.0):
        """Seitlicher Versatz dx je Panel: linke Kanten breiter Textkaesten gegen die
        Spaltenanfaenge (x0) der Layoutfelder. Spalten liegen >200 px auseinander,
        daher ist der Versatz bis +-130 px eindeutig."""
        fields = [f for f in self._panel_fields(panel) if f["panel"] == panel and not f["multiline"]]
        cols = self._cluster_1d([f["box"][0] + 12 for f in fields], 25)   # Textanfang ~ x0 + Rand
        lefts = self._cluster_1d([r[0] for r in rects if r[2] - r[0] >= min_width], 12)
        if len(cols) < 1 or len(lefts) < 1:
            return 0.0, 0
        best = (0, 0.0)
        for l in lefts:
            for c in cols:
                dx = c - l
                if abs(dx) > max_off:
                    continue
                n = sum(1 for lv in lefts if min(abs(lv + dx - cv) for cv in cols) <= tol)
                if n > best[0] or (n == best[0] and abs(dx) < abs(best[1])):
                    best = (n, float(dx))
        return best[1], best[0]

    def col_fit_rows(self, fitted, panel, min_width=55, max_off=320, sigma=12.0):
        """Seitliche Anpassung bei bekannten Zeilen: x_layout = a * x + b. Linke Kanten
        breiter Textkaesten gegen die Spaltenanfaenge (x0 + Rand) der Felder derselben
        Zeile, weiche Bewertung. Streckung a in 0,7..1,3 (geknicktes Panel wird von der
        globalen Homographie falsch skaliert), Versatz bis +-max_off.
        Liefert (a, b, Score) oder (None, None, 0)."""
        fields = [f for f in self._panel_fields(panel) if f["panel"] == panel and not f["multiline"]]
        px0 = self.layout.panels[panel][0]
        items = []
        for r in fitted:
            if r[2] - r[0] < min_width:
                continue
            cy = (r[1] + r[3]) / 2
            cols = [f["box"][0] + 12 for f in fields if abs(cy - f["cy"]) <= 0.5 * self.pitch]
            if cols:
                items.append((r[0], np.array(cols)))
        if len(items) < 3:
            return None, None, 0.0
        best = (None, None, 0.0)
        for a in np.arange(0.7, 1.31, 0.02):
            for b in range(-max_off, max_off + 1, 3):
                sc = 0.0
                for left, cols in items:
                    # Streckung um den Panelanfang, damit b den Versatz am linken Rand meint
                    x = a * (left - px0) + px0 + b
                    d = np.min(np.abs(x - cols))
                    sc += np.exp(-(d * d) / (2 * sigma * sigma))
                if sc > best[2] + 1e-9:
                    best = (float(a), float(b), sc)
        if best[2] < max(3.0, 0.4 * len(items)):
            return None, None, best[2]
        return best

    def panel_shift(self, rects, panel, dx_range=12, dy_range=40, step=2, min_width=55):
        """(dx, dy), das die Zahl der Kastenmittelpunkte in Layoutfeldern des Panels maximiert.
        Schmale Kaesten (Feldbezeichner wie "D.1") zaehlen nicht, sonst gewinnt eine
        Rechtsverschiebung, die Bezeichner in die Wertefelder schiebt."""
        fields = self._panel_fields(panel)
        cents = [((r[0] + r[2]) / 2, (r[1] + r[3]) / 2) for r in rects if r[2] - r[0] >= min_width]
        if not fields or not cents:
            return 0.0, 0.0, 0
        best = (0, 0.0, 0.0)
        for dy in range(-dy_range, dy_range + 1, step):
            for dx in range(-dx_range, dx_range + 1, step):
                n = 0
                for cx, cy in cents:
                    x, y = cx + dx, cy + dy
                    for f in fields:
                        if self._inside(f["score_box"], x, y):
                            n += 1
                            break
                # bei Gleichstand kleinste Verschiebung
                if n > best[0] or (n == best[0] and abs(dx) + abs(dy) < abs(best[1]) + abs(best[2])):
                    best = (n, float(dx), float(dy))
        return best[1], best[2], best[0]

    def assign(self, rects, panel_index_of, row_maps=None):
        """rects: Rechtecke in Referenzkoordinaten. Liefert (feld -> Liste von Rechtecken, shifts).
        row_maps: optional {panel: (Linien-Mapping, ref_ys, n)} aus dem Raster — dann werden
        die y-Koordinaten stueckweise ueber die verfolgten Zeilenlinien abgebildet."""
        from .gridmode import GridReader
        by_panel = {}
        for r in rects:
            by_panel.setdefault(panel_index_of(r), []).append(r)
        shifts = {}
        out = {}
        for panel, prects in by_panel.items():
            if panel is None:
                continue
            dx_rows = None
            if row_maps and panel in row_maps:
                mapping, ref_ys, nrows = row_maps[panel][:3]
                dx_rows = row_maps[panel][3] if len(row_maps[panel]) > 3 else None
                a, b = 1.0, 0.0
                fitted = []
                for r in prects:
                    cx = (r[0] + r[2]) / 2
                    y0 = GridReader.y_to_ref(mapping, ref_ys, cx, r[1])
                    y1 = GridReader.y_to_ref(mapping, ref_ys, cx, r[3])
                    fitted.append([r[0], y0, r[2], y1])
            else:
                a, b, nrows = self.row_fit(prects, panel)
                # y linear anpassen (Versatz + Streckung), seitlich nur kleine Suche +-12 px
                fitted = [[r[0], a * r[1] + b, r[2], a * r[3] + b] for r in prects]
            ax, bx, sc = (None, None, 0.0)
            if row_maps and panel in row_maps:
                ax, bx, sc = self.col_fit_rows(fitted, panel)
            if ax is not None:
                px0 = self.layout.panels[panel][0]
                fitted = [[ax * (r[0] - px0) + px0 + bx, r[1], ax * (r[2] - px0) + px0 + bx, r[3]] for r in fitted]
                dx, n = 0.0, -int(sc)   # negativ = Spaltenanpassung (a, b eingerechnet), Betrag = Score
                shifts[str(panel) + "_ab"] = (round(ax, 3), round(bx, 1))
            else:
                dx, _, n = self.panel_shift(fitted, panel, dy_range=0)
            shifts[panel] = (round(dx, 1), round(a, 3), round(b, 1), nrows, n)
            fields = self._panel_fields(panel)
            row_tol0 = (0.45 if (row_maps and panel in row_maps) else 0.7) * self.pitch
            # Zeilen mit >= 2 Feldern: kleine Kaesten (Bezeichner) zaehlen; stimmt ihre Zahl mit
            # der Feldzahl ueberein, gilt die Reihenfolge — jeder Wertekasten gehoert zum
            # naechsten Bezeichner links von ihm (robust gegen Versatz und Streckung)
            forced = {}
            rows = {}
            for f in fields:
                if f["multiline"]:
                    continue
                key = round(f["cy"] / (0.5 * self.pitch))
                rows.setdefault(key, []).append(f)
            for key, rf in rows.items():
                if len(rf) < 2:
                    continue
                rf = sorted(rf, key=lambda f: f["box"][0])
                cyr = rf[0]["cy"]
                inrow = [(i, fr0) for i, fr0 in enumerate(fitted) if abs((fr0[1] + fr0[3]) / 2 - cyr) <= row_tol0]
                small = [(i, fr0) for i, fr0 in inrow if (fr0[2] - fr0[0]) < 70]
                # Bezeichnerkaestchen jedes Feldes: kleiner Kasten unmittelbar links vom Feldanfang
                # (Bezeichner sind 35-45 px breit und enden ~10 px vor dem Wert)
                anchors = []
                for f in rf:
                    cand = [(i, fr0) for i, fr0 in small if f["box"][0] - 60 <= (fr0[0] + fr0[2]) / 2 <= f["box"][0] + 8]
                    if len(cand) == 1:
                        anchors.append((cand[0][1][0], f, cand[0][0]))
                if len(anchors) < 2:
                    continue
                anchors.sort(key=lambda a: a[0])
                for _, _, i in anchors:
                    forced[i] = None  # Bezeichner selbst nie zuordnen
                # Wertekaesten: zum naechsten gefundenen Bezeichner links davon, aber nur, wenn kein
                # weiteres Feld ohne gefundenen Bezeichner dazwischen liegt (sonst Bereichsregel)
                for i, fr0 in inrow:
                    if (fr0[2] - fr0[0]) < 50 or i in forced:
                        continue
                    left = [a for a in anchors if a[0] < fr0[0] + 4]
                    if not left:
                        continue
                    ax0, f_anchor, ai = left[-1]
                    anchor_right = fitted[ai][2]
                    # Werte stehen direkt hinter ihrem Bezeichner: mehr als 120 px Abstand -> nicht zwingen
                    if fr0[0] - anchor_right > 120:
                        continue
                    between = [g for g in rf if g["box"][0] > f_anchor["box"][0] + 20 and g["box"][0] + 12 < fr0[0] + 4
                               and not any(a[1] is g for a in anchors)]
                    if between:
                        continue
                    forced[i] = f_anchor
            for idx, (r, fr0) in enumerate(zip(prects, fitted)):
                fr = [fr0[0] + dx, fr0[1], fr0[2] + dx, fr0[3]]
                if idx in forced:
                    f = forced[idx]
                    if f is not None:
                        out.setdefault(f["name"], []).append((r, fr, 0.0))
                    continue
                cx, cy = (fr[0] + fr[2]) / 2, (fr[1] + fr[3]) / 2
                # Kandidaten: Felder der Zeile, die der Kasten deutlich ueberdeckt (>= 60 px oder
                # >= 60 % der Kastenbreite). Mehrere -> an den Feldgrenzen teilen
                # ("02.26 Strausberg" -> HU + Ort; "(Monat und Jahr) 11.21" -> nur HU).
                bw = fr[2] - fr[0]
                # Zeilentoleranz: mit verfolgten Zeilenlinien sind die y-Werte genau -> eng
                row_tol = (0.45 if (row_maps and panel in row_maps) else 0.7) * self.pitch
                hits = []
                for f in fields:
                    bb = f["box"]
                    if f["multiline"]:
                        if bb[1] <= cy <= bb[3] and bw >= 40 and bb[0] + 10 <= cx <= bb[2]:
                            hits.append((f, 0.0))
                        continue
                    if abs(cy - f["cy"]) > row_tol:
                        continue
                    ov = min(fr[2], bb[2]) - max(fr[0], bb[0] + 8)
                    if ov >= 60 or ov >= 0.6 * bw:
                        hits.append((f, abs(cy - f["cy"])))
                # linke Kante direkt an einem Spaltenanfang (Werte stehen linksbuendig hinter dem
                # Bezeichner) -> dieses Feld gewinnt, auch wenn Bereichsgrenzen ungenau sind
                near_start = [f for f in fields if not f["multiline"] and abs(cy - f["cy"]) <= row_tol
                              and abs(fr[0] - (f["box"][0] + 12)) <= 45]
                if near_start and not any(h[0] is near_start[0] for h in hits):
                    hits = [(near_start[0], abs(cy - near_start[0]["cy"]))]
                if not hits:
                    continue
                multi = [h for h in hits if h[0]["multiline"]]
                if multi and len(hits) == len(multi):
                    out.setdefault(multi[0][0]["name"], []).append((r, fr, 0.0))
                    continue
                hits = [h for h in hits if not h[0]["multiline"]]
                # schmaler Kasten am rechten Rand eines breiten Feldes = Bezeichner des Nachbarn
                if len(hits) == 1 and bw < 45:
                    bb = hits[0][0]["box"]
                    if cx > bb[2] - 45 and bb[2] - bb[0] > 150:
                        continue
                hits.sort(key=lambda h: h[0]["box"][0])

                def to_photo(x_layout):
                    if ax is not None:
                        return (x_layout - bx - px0) / ax + px0
                    return x_layout - dx

                for i, (f, _) in enumerate(hits):
                    bb = f["box"]
                    # Feldanfang: Kennzeichen liegt in einem gedruckten Rahmen (innen bleiben),
                    # HU-Text beginnt direkt hinter dem Bezeichner (etwas weiter links)
                    lm = {"registrationNumber": 6.0, "hu": -12.0}.get(f["name"], -4.0)
                    # Stueckgrenzen im Layoutrahmen
                    lx = fr[0] if i == 0 else max(fr[0], bb[0] + lm)
                    rx = fr[2] if i == len(hits) - 1 else min(fr[2], hits[i + 1][0]["box"][0] - 6)
                    # linke Kante: reicht der Kasten weit in den Bezeichner hinein (> 40 px links
                    # vom Feldanfang), am Feldanfang abschneiden; beginnt er nahe am Feldanfang,
                    # etwas nach links erweitern (Detektor schneidet erste Zeichen knapp)
                    # (Bezeichnerkaestchen sind 35-45 px breit; erst ab 90 px Ueberhang ist sicher
                    # Bezeichnertext im Kasten — kleinere Abweichungen sind Ungenauigkeit der Anpassung)
                    # Felder mit direkt anliegendem Bezeichnerkaestchen ("2.1", "P.1"): schon bei
                    # kleinem Ueberhang am Feldanfang schneiden, sonst liest die OCR den Bezeichner mit
                    thresh = 90
                    if lx < bb[0] + lm - thresh:
                        lx = bb[0] + lm - 8
                    elif lx < bb[0] + lm + 30:
                        lx = min(lx, max(bb[0] + lm - 8, lx - 16))  # nur nach links erweitern
                    if rx - lx < 14:
                        continue
                    piece = [to_photo(lx), r[1], to_photo(rx), r[3]]
                    if os.environ.get("FSS_DEBUG") == f["name"]:
                        print("DBG", f["name"], "r", [round(v) for v in r], "fr", [round(v) for v in fr], "bb", [round(v) for v in bb],
                              "lx", round(lx), "rx", round(rx), "ax", ax, "bx", bx, "dx", dx, "px0", px0 if ax is not None else None, "piece", [round(v) for v in piece])
                    out.setdefault(f["name"], []).append((piece, [lx, fr[1], rx, fr[3]], 0.0))
        return out, shifts

    def lines(self, items, single_line: bool, field_cy: float):
        """items: Liste (rect, rect_verschoben). In Zeilen gruppieren (oben->unten,
        links->rechts) anhand der verschobenen Koordinaten — so passen Teile aus
        verschiedenen Panels (Feld 22) zusammen. Liefert Zeilen von Original-Rechtecken.
        Einzeilige Felder: nur die Zeile, die der Feldmitte am naechsten liegt."""
        if not single_line and len(self.layout.panels) > 1:
            # panelweise gruppieren, dann Zeile i links + Zeile i rechts (gleiche Druckzeile)
            per_panel = {}
            for it in items:
                per_panel.setdefault(layout_panel_index(self.layout, it[0]), []).append(it)
            if len(per_panel) > 1:
                cols = [self._group_rows(v) for _, v in sorted(per_panel.items(), key=lambda kv: (kv[0] is None, kv[0]))]
                n = max(len(c) for c in cols)
                merged = []
                for i in range(n):
                    row = []
                    for c in cols:
                        if i < len(c):
                            row.extend(c[i])
                    merged.append(row)
                return merged
        return self._group_rows(items, single_line, field_cy)

    def _group_rows(self, items, single_line=False, field_cy=0.0):
        rs = sorted(items, key=lambda it: ((it[1][1] + it[1][3]) / 2, it[1][0]))
        groups = []
        for it in rs:
            sr = it[1]
            cy = (sr[1] + sr[3]) / 2
            if groups and abs(cy - groups[-1]["cy"]) < 0.5 * self.pitch:
                g = groups[-1]
                g["items"].append(it)
                g["cy"] = (g["cy"] * (len(g["items"]) - 1) + cy) / len(g["items"])
            else:
                groups.append({"cy": cy, "items": [it]})
        for g in groups:
            g["items"].sort(key=lambda it: it[1][0])
        if single_line and len(groups) > 1:
            groups = [min(groups, key=lambda g: abs(g["cy"] - field_cy))]
        # Zeilen von (Rechteck, Ueberhang)
        return [[(it[0], it[2] if len(it) > 2 else 0.0) for it in g["items"]] for g in groups]


def layout_panel_index(layout, box):
    cx = (box[0] + box[2]) / 2
    cy = (box[1] + box[3]) / 2
    for i, (x0, y0, x1, y1) in enumerate(layout.panels):
        if x0 <= cx < x1 and y0 <= cy < y1:
            return i
    return None
