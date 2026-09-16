"""
Rasterbasierte Feldsuche (klassische Formularerkennung, ohne KI).

1. Hintergrund entfernen: hellster Farbkanal (farbige Guilloche wird hell, schwarze
   Schrift und dunkle Rasterlinien bleiben) -> lokaler Hintergrund per Closing ->
   Differenz -> Otsu. Ergebnis: Tintenmaske (Schrift + Linien).
2. Rasterlinien extrahieren: lange waagerechte/senkrechte Laeufe (morphologisches
   Oeffnen), Zusammenhangskomponenten, als Polylinien verfolgt (Woelbung!).
3. Die Zeilenlinien eines Panels werden der Referenz nach Reihenfolge und Abstand
   zugeordnet; jedes Feld liegt zwischen zwei Zeilenlinien und zwei Spaltenlinien.
   Der Ausschnitt wird entlang der gekruemmten Linien geradegezogen.
"""
import cv2
import numpy as np

# connectedComponentsWithStats stuerzt zusammen mit geladener ONNX Runtime ab
# (OpenMP-Konflikt) -> Komponenten ueber Konturen, OpenCV-Threads begrenzen
cv2.setNumThreads(1)


def _components(mask):
    """Liste (x, y, w, h, konturmaske-funktion) je Zusammenhangskomponente."""
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    out = []
    for c in contours:
        x, y, w, h = cv2.boundingRect(c)
        out.append((x, y, w, h, c))
    return out


def binarize(bgr, weak=False):
    """Tintenmaske (255 = Tinte) ohne Guilloche-Hintergrund.
    weak=True: niedrigere Schwelle fuer blasse Rasterlinien (nur fuer Linienextraktion)."""
    mx = np.max(bgr, axis=2) if bgr.ndim == 3 else bgr
    bg = cv2.morphologyEx(mx, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (25, 25)))
    diff = cv2.subtract(bg, mx)
    thr, _ = cv2.threshold(diff, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    thr = max(20, min(thr, 70))
    if weak:
        thr = max(12, int(0.45 * thr))
    return (diff >= thr).astype(np.uint8) * 255


def _line_kernel(length, angle_deg, vertical=False):
    """Linienfoermiges Strukturelement mit Neigung (fuer gewoelbte/schraege Linien)."""
    a = np.deg2rad(angle_deg)
    if vertical:
        dx, dy = np.sin(a) * length / 2, np.cos(a) * length / 2
    else:
        dx, dy = np.cos(a) * length / 2, np.sin(a) * length / 2
    w = int(abs(dx) * 2) + 3
    h = int(abs(dy) * 2) + 3
    k = np.zeros((h, w), np.uint8)
    c = (w // 2, h // 2)
    cv2.line(k, (int(c[0] - dx), int(c[1] - dy)), (int(c[0] + dx), int(c[1] + dy)), 1, 1)
    return k


def line_masks(ink, hlen=50, vlen=46, angles=(-5, -3, -1.5, 0, 1.5, 3, 5)):
    """Waagerechte/senkrechte Linienmasken, neigungstolerant: Oeffnen mit gedrehten
    Linienkernen, Ergebnisse vereinigt. Vorher leicht verdicken, damit duenne,
    gewellte Linien nicht zerfallen."""
    thick = cv2.dilate(ink, np.ones((3, 3), np.uint8))
    hor = np.zeros_like(ink)
    ver = np.zeros_like(ink)
    for ang in angles:
        hor |= cv2.morphologyEx(thick, cv2.MORPH_OPEN, _line_kernel(hlen, ang))
        ver |= cv2.morphologyEx(thick, cv2.MORPH_OPEN, _line_kernel(vlen, ang, vertical=True))
    hor = cv2.morphologyEx(hor, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (25, 3)))
    ver = cv2.morphologyEx(ver, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (3, 25)))
    return hor, ver


def h_lines(hor, rect, min_len, step=40):
    x0, y0, x1, y1 = [int(v) for v in rect]
    """Waagerechte Linien im Bereich als Polylinien: Liste von dicts
    {xs: [...], ys: [...], x_start, x_end, y_mean}."""
    region = np.ascontiguousarray(hor[y0:y1, x0:x1])
    lines = []
    for x, y, w, h, c in _components(region):
        if w < min_len or h > 70:
            continue
        comp = np.zeros(region.shape, np.uint8)
        cv2.drawContours(comp, [c], -1, 255, -1)
        comp = (comp > 0) & (region > 0)
        xs, ys = [], []
        for cx in range(x, x + w, step):
            band = comp[:, cx:min(cx + step, x + w)]
            rows = np.nonzero(band.any(axis=1))[0]
            if len(rows):
                xs.append(x0 + cx + min(step, x + w - cx) / 2)
                ys.append(y0 + float(rows.mean()))
        if len(xs) >= 2:
            lines.append({"xs": np.array(xs), "ys": np.array(ys), "x_start": x0 + x, "x_end": x0 + x + w,
                          "y_mean": float(np.mean(ys)), "len": int(w)})
    return lines


def merge_h_lines(lines, gap_y=10, gap_x=80):
    """Segmente derselben Zeilenlinie (durch Schrift unterbrochen) zusammenfuehren."""
    lines = sorted(lines, key=lambda l: l["y_mean"])
    merged = []
    for l in lines:
        placed = False
        for m in merged:
            # gleiche Hoehe (an der Nahtstelle) und keine x-Ueberlappung
            if l["x_start"] >= m["x_end"] - 15 or l["x_end"] <= m["x_start"] + 15:
                ya = np.interp(l["x_start"], m["xs"], m["ys"]) if l["x_start"] >= m["x_end"] - 15 else np.interp(l["x_end"], m["xs"], m["ys"])
                yb = l["ys"][0] if l["x_start"] >= m["x_end"] - 15 else l["ys"][-1]
                if abs(ya - yb) <= gap_y and (min(l["x_start"], m["x_start"]) - 0) >= 0 and (
                        l["x_start"] - m["x_end"] <= gap_x or m["x_start"] - l["x_end"] <= gap_x):
                    xs = np.concatenate([m["xs"], l["xs"]]); ys = np.concatenate([m["ys"], l["ys"]])
                    o = np.argsort(xs)
                    m["xs"], m["ys"] = xs[o], ys[o]
                    m["x_start"] = min(m["x_start"], l["x_start"]); m["x_end"] = max(m["x_end"], l["x_end"])
                    m["y_mean"] = float(np.mean(m["ys"])); m["len"] = int(m["x_end"] - m["x_start"])
                    placed = True
                    break
        if not placed:
            merged.append(dict(l))
    return sorted(merged, key=lambda l: l["y_mean"])


def v_segments(ver, rect, min_len):
    x0, y0, x1, y1 = [int(v) for v in rect]
    """Senkrechte Segmente: {x_mean, y_start, y_end, xs(y)}."""
    region = np.ascontiguousarray(ver[y0:y1, x0:x1])
    segs = []
    for x, y, w, h, c in _components(region):
        if h < min_len or w > 30 or (h < 90 and w > 12):
            continue  # Textstriche sind kurz und breit, Trennlinien lang und duenn
        segs.append({"x_mean": x0 + x + w / 2.0, "y_start": y0 + y, "y_end": y0 + y + h, "len": int(h)})
    return sorted(segs, key=lambda s_: s_["x_mean"])


def y_at(line, x):
    return float(np.interp(x, line["xs"], line["ys"]))


def track_h_lines(hor, rect, band=32, min_frac=0.45, link_tol=7, min_len=200):
    """Waagerechte Linien durch Verfolgung: je Spaltenband das Vertikalprofil der
    Linienmaske -> Spitzen (Linienkreuzungen) -> Spitzen benachbarter Baender zu
    Polylinien verketten. Immun gegen Komponenten, die ueber Textstriche verschmelzen."""
    x0, y0, x1, y1 = [int(v) for v in rect]
    region = hor[y0:y1, x0:x1]
    active = []   # laufende Linien: {"xs": [], "ys": [], "last": y}
    done = []
    for bx in range(0, region.shape[1], band):
        sub = region[:, bx:bx + band]
        bw = sub.shape[1]
        prof = (sub > 0).sum(axis=1).astype(np.float32)
        on = prof >= min_frac * bw
        # Laeufe zusammenhaengender "on"-Zeilen -> Linienmitte
        peaks = []
        y = 0
        H = len(on)
        while y < H:
            if not on[y]:
                y += 1
                continue
            a = y
            while y < H and on[y]:
                y += 1
            if y - a <= 14:  # Linien sind duenn; dickere Laeufe sind Text/Flaechen
                peaks.append((a + y - 1) / 2.0)
        xc = x0 + bx + bw / 2.0
        used = set()
        next_active = []
        for ln in active:
            best, bd = None, link_tol + 1
            for i, py in enumerate(peaks):
                d = abs(py - ln["last"])
                if i not in used and d < bd:
                    best, bd = i, d
            if best is not None:
                used.add(best)
                ln["xs"].append(xc); ln["ys"].append(y0 + peaks[best]); ln["last"] = peaks[best]; ln["miss"] = 0
                next_active.append(ln)
            else:
                ln["miss"] = ln.get("miss", 0) + 1
                if ln["miss"] <= 2:      # kurze Luecke (Schrift kreuzt) ueberbruecken
                    next_active.append(ln)
                else:
                    done.append(ln)
        for i, py in enumerate(peaks):
            if i not in used:
                next_active.append({"xs": [xc], "ys": [y0 + py], "last": py, "miss": 0})
        active = next_active
    done.extend(active)
    lines = []
    for ln in done:
        xs, ys = np.array(ln["xs"]), np.array(ln["ys"])
        if len(xs) < 2 or xs[-1] - xs[0] < min_len:
            continue
        lines.append({"xs": xs, "ys": ys, "x_start": float(xs[0] - band / 2), "x_end": float(xs[-1] + band / 2),
                      "y_mean": float(ys.mean()), "len": float(xs[-1] - xs[0] + band)})
    return sorted(lines, key=lambda l: l["y_mean"])
