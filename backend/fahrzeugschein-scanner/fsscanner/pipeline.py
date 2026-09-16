"""
Gesamtablauf: Bild -> Registrierung -> Ausschnitte -> OCR -> Nachbearbeitung -> Ergebnis.

Ergebnisformat entspricht der bisherigen API (fahrzeugschein-scanner.de /generic-json):
{
  "data": { "<feld>": "<text>", ..., "<feld>_img": "<base64 jpeg>", "document_img": "<base64>" },
  "country_code": "de",
  "meta": { "inliers": 812, "elapsed": 3.1, "engine": "local", "notes": [...] }
}
"""
import os
import time

import cv2
import numpy as np

from .assign import Assigner, det_to_rects, layout_panel_index
from .gridmode import GridReader
from .imageio import load_image, to_jpeg_b64
from .layout import Layout, DEFAULT_DIR
from .ocr import FieldOcr
from .postprocess import clean_fields, derive, plausibility, looks_like
from .register import Registrar, RegistrationError
from . import grid as _grid


def trim_small_label(crop, min_frac=0.62):
    """Kleine Bezeichnerschrift links vom Wert abschneiden (z. B. "(Monat und Jahr)" vor der HU):
    die Wertziffern (OCR-B) sind deutlich hoeher als der Bezeichner. Spaltenweise Tintenhoehe,
    erste Spalte mit >= min_frac der maximalen Hoehe = Wertanfang."""
    if crop is None or crop.shape[1] < 20:
        return crop
    ink = _grid.binarize(crop)
    ink = cv2.morphologyEx(ink, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
    rows_on = [np.flatnonzero(ink[:, x] > 0) for x in range(ink.shape[1])]
    heights = np.array([(r[-1] - r[0] + 1) if len(r) else 0 for r in rows_on], float)
    if heights.max() <= 0:
        return crop
    # gleitendes Maximum, damit duenne Striche (1, l) nicht als Bezeichner gelten
    k = 5
    hm = np.array([heights[max(0, i - k):i + k + 1].max() for i in range(len(heights))])
    tall = np.flatnonzero(hm >= min_frac * hm.max())
    if len(tall) == 0:
        return crop
    x0 = max(0, int(tall[0]) - 6)
    if x0 < 8:
        return crop
    return crop[:, x0:]


class Scanner:
    def __init__(self, reference_path=None, layout_path=None, ocr_scale=None, crop_jpeg_quality=80):
        self.layout = Layout(layout_path)
        ref_path = reference_path or os.environ.get("FSSCANNER_REFERENCE") or os.path.join(DEFAULT_DIR, "zb1_reference.jpg")
        ref = cv2.imread(ref_path)
        if ref is None:
            raise RuntimeError(f"Referenzvorlage fehlt: {ref_path}")
        if ref.shape[1] != self.layout.width or ref.shape[0] != self.layout.height:
            raise RuntimeError("Referenzvorlage passt nicht zum Layout (Groesse)")
        self.registrar = Registrar(ref, panels=self.layout.panels,
                                   exclude_boxes=[spec["box"] for _, spec in self.layout.items()])
        self.ocr = FieldOcr(threads=int(os.environ.get('FSSCANNER_THREADS', '6')))
        # Zeilenhoehe fuer OCR auf ~64 px bringen
        self.ocr_scale = ocr_scale or max(1.0, 64.0 / self.layout.line_height)
        self.crop_jpeg_quality = crop_jpeg_quality
        self.assigner = Assigner(self.layout)
        # Detektionsmodus (Standard): Textkaesten im entzerrten Dokument finden und den
        # Feldern zuordnen; "layout" = alter Modus mit blinden Layout-Ausschnitten
        # "detect" (Standard): Textdetektion + Zuordnung, Zeilen ueber verfolgte Rasterlinien
        # "grid": rein rasterbasiertes Lesen der Feldpanels (experimentell)
        # "layout": alter Modus mit blinden Layout-Ausschnitten
        self.mode = os.environ.get("FSSCANNER_MODE", "detect")
        self.grid_reader = GridReader(self.layout) if self.mode in ("grid", "detect") else None
        self.assigner = Assigner(self.layout, grid=self.grid_reader)
        self.use_row_lines = os.environ.get("FSSCANNER_ROWLINES", "1") == "1"
        # Lokale Ausschnittkorrektur (Spaltentrenner/Textzeile) abschaltbar fuer Messungen
        self.refine_crops = os.environ.get("FSSCANNER_REFINE_CROP", "0") == "1"

    def scan_bytes(self, data: bytes, is_pdf: bool = False, with_images: bool = True) -> dict:
        img = load_image(data, is_pdf)
        return self.scan_image(img, with_images=with_images)

    @staticmethod
    def _mask_other_boxes(crop, r, rects, scale, pad, min_overlap=0.5):
        """Fremde Textkaesten (Bezeichner wie "(Monat und Jahr)"), die in den Ausschnitt ragen,
        mit der Hintergrundfarbe uebermalen — sie verwirren die Erkennung des Werts."""
        h, w = crop.shape[:2]
        bg = tuple(int(v) for v in np.median(crop.reshape(-1, 3), axis=0))
        out = crop
        for o in rects:
            if o is r:
                continue
            # Ueberlappung in Referenzkoordinaten
            ix0, iy0 = max(o[0], r[0] - pad), max(o[1], r[1] - pad)
            ix1, iy1 = min(o[2], r[2] + pad), min(o[3], r[3] + pad)
            if ix1 - ix0 <= 2 or iy1 - iy0 <= 2:
                continue
            # der eigene Wertekasten selbst (fast deckungsgleich) bleibt
            if (ix1 - ix0) * (iy1 - iy0) >= min_overlap * (r[2] - r[0]) * (r[3] - r[1]):
                continue
            cx0 = int((ix0 - (r[0] - pad)) * scale); cx1 = int((ix1 - (r[0] - pad)) * scale)
            cy0 = int((iy0 - (r[1] - pad)) * scale); cy1 = int((iy1 - (r[1] - pad)) * scale)
            cx0, cy0 = max(0, cx0), max(0, cy0); cx1, cy1 = min(w, cx1), min(h, cy1)
            if cx1 > cx0 and cy1 > cy0:
                if out is crop:
                    out = crop.copy()
                out[cy0:cy1, cx0:cx1] = bg
        return out

    def scan_image(self, img: np.ndarray, with_images: bool = True) -> dict:
        if self.mode == "layout":
            return self.scan_image_layout(img, with_images)
        t0 = time.time()
        reg = self.registrar.register(img)
        t_reg = time.time() - t0

        # 1) Textdetektion auf dem entzerrten Dokument (Referenzkoordinaten)
        t1 = time.time()
        warped = self.registrar.warp_document_panels(img, reg, scale=1.0)
        det = self.ocr.ocr(warped, use_det=True, use_rec=False, use_cls=False)
        rects = det_to_rects(det.boxes) if det.boxes is not None else []
        t_det = time.time() - t1

        # 2) Kaesten den Feldern zuordnen; Zeilen ueber die verfolgten Rasterlinien (Woelbung)
        row_maps = None
        if self.grid_reader is not None and self.use_row_lines and self.mode == "detect":
            row_maps = self.grid_reader.row_maps(warped)
        assigned, shifts = self.assigner.assign(rects, lambda r: layout_panel_index(self.layout, r), row_maps)

        # 3) Nur die zugeordneten Kaesten lesen (scharf aus dem Originalfoto gewarpt)
        items = []
        line_map = {}
        crops_display = {}
        for name, spec in self.layout.items():
            rs = assigned.get(name)
            if name == "p2_p4":
                # kW und Drehzahl sind so schmal wie die Bezeichner daneben; die Kastenzuordnung
                # vertauscht sie -> ganze Zelle aus dem Layout lesen und "kW /Drehzahl" parsen
                rs = None
            pi = layout_panel_index(self.layout, spec["box"])
            H = reg["panel_H"][pi] if (pi is not None and reg["panel_H"]) else reg["H"]
            if not rs:
                # Kein Kasten gefunden (Detektor uebersieht z. B. das gerahmte Kennzeichen):
                # Rueckfall auf den Layout-Ausschnitt wie im alten Modus
                if not spec.get("multiline"):
                    b = list(spec["box"]); b[0] -= 14
                    crop = self.registrar.warp_box(img, H, b, scale=self.ocr_scale, pad=2)
                    if crop is not None:
                        items.append(((name, 0, 0), crop, False))
                        line_map[name] = [[(list(spec["box"]), 0.0)]]
                        if with_images:
                            crops_display[name] = self.registrar.warp_box(img, H, spec["box"], scale=1.0, pad=2)
                continue
            cy = (spec["box"][1] + spec["box"][3]) / 2
            lines = self.assigner.lines(rs, not spec.get("multiline"), cy)
            line_map[name] = lines
            if name in ("registrationNumber", "hu"):
                # gerahmte/kleine Felder: zusaetzlich den Layoutausschnitt lesen, spaeter
                # den Wert nehmen, der dem Muster entspricht
                b = list(spec["box"]); b[0] -= (14 if name != "hu" else 4)
                crop = self.registrar.warp_box(img, H, b, scale=self.ocr_scale, pad=2)
                if crop is not None:
                    items.append(((name, "alt", 0), crop, False))
            for li, line in enumerate(lines):
                for wi, (r, _) in enumerate(line):
                    if name in ("hu", "creation_date"):
                        # Wert steht rechts neben dem Bezeichnerkasten derselben Zeile ("X Naechste HU");
                        # dort schneiden und keinen vertikalen Rand (Bezeichnerzeile darunter bleibt draussen)
                        rr = list(r)
                        yc = (r[1] + r[3]) / 2
                        lab = [o for o in rects if o is not r and o[2] <= spec["box"][0] + 60 and o[0] < spec["box"][0]
                               and min(o[3], r[3]) - max(o[1], r[1]) >= 0.5 * (r[3] - r[1])]
                        if lab:
                            rr[0] = max(r[0] - 8, max(o[2] for o in lab) - 8)
                        crop = self.registrar.warp_box(img, H, [rr[0], r[1], r[2], r[3]], scale=self.ocr_scale, pad=2)
                    else:
                        crop = self.registrar.warp_box(img, H, r, scale=self.ocr_scale, pad=8)
                    if crop is not None:
                        items.append(((name, li, wi), crop, False))
            if with_images:
                allr = [r for line in lines for r, _ in line]
                u = [min(r[0] for r in allr), min(r[1] for r in allr), max(r[2] for r in allr), max(r[3] for r in allr)]
                crops_display[name] = self.registrar.warp_box(img, H, u, scale=1.0, pad=6)

        t2 = time.time()
        raw = self.ocr.recognize(items)
        t_ocr = time.time() - t2

        # 4) Woerter je Zeile, Zeilen je Feld zusammensetzen
        texts, scores = {}, {}
        for name, lines in line_map.items():
            out_lines, sc = [], []
            for li, line in enumerate(lines):
                words = []
                for wi, (r, overhang) in enumerate(line):
                    t, s_ = raw.get((name, li, wi), ("", 0.0))
                    t = t.strip()
                    # Bezeichner mitgelesen: Ueberhang links vom Wertebereich in Zeichen umrechnen
                    # (OCR-B ist monospace) und vorne abschneiden
                    if t:
                        words.append(t)
                        sc.append(s_)
                if words:
                    out_lines.append(" ".join(words))
            texts[name] = ("\n".join(out_lines), float(np.mean(sc)) if sc else 0.0)
        for name, _ in self.layout.items():
            texts.setdefault(name, ("", 0.0))

        grid_meta = {str(k): v[2] for k, v in (row_maps or {}).items()} or None
        if self.grid_reader is not None and self.mode == "grid":
            t3 = time.time()
            hi_scale = 1.4
            warped_hi = self.registrar.warp_document_panels(img, reg, scale=hi_scale)
            gtexts, gcrops, grid_meta = self.grid_reader.read(warped, warped_hi, hi_scale, self.ocr)
            # Rasterfelder ersetzen die Detektionsergebnisse der Feldpanels
            for name in self.grid_reader.fields:
                if name in gtexts:
                    texts[name] = gtexts[name]
                    if name in gcrops:
                        crops_display[name] = gcrops[name]
                elif self.grid_reader.fields[name]["panel"] in (grid_meta or {}) and grid_meta[self.grid_reader.fields[name]["panel"]].get("matched", 0) >= 3:
                    texts[name] = ("", 0.0)  # Zelle gefunden, aber leer
            grid_meta["elapsed"] = round(time.time() - t3, 2)

        fields = clean_fields(texts)
        # Kennzeichen/HU: Kasten-Ergebnis gegen Layout-Ergebnis abwaegen
        for name in ("registrationNumber", "hu"):
            alt = raw.get((name, "alt", 0))
            if alt is None:
                continue
            alt_clean = clean_fields({name: alt})[name]
            cur = fields.get(name, "")
            if not looks_like(name, cur) and looks_like(name, alt_clean):
                fields[name] = alt_clean
                pi = layout_panel_index(self.layout, self.layout.box(name))
                Hn = reg["panel_H"][pi] if (pi is not None and reg["panel_H"]) else reg["H"]
                if with_images:
                    crops_display[name] = self.registrar.warp_box(img, Hn, self.layout.box(name), scale=1.0, pad=2)
        fields.update(derive(fields))
        scores = {n: round(s_, 3) for n, (_, s_) in texts.items()}

        data = dict(fields)
        if with_images:
            for name, crop in crops_display.items():
                if crop is not None and fields.get(name, "") != "":
                    data[name + "_img"] = to_jpeg_b64(crop, self.crop_jpeg_quality)
            doc = self.registrar.warp_document(img, reg["H"], scale=0.6)
            data["document_img"] = to_jpeg_b64(doc, 82)

        return {
            "data": data,
            "country_code": "de",
            "meta": {
                "engine": "local",
                "mode": self.mode,
                "grid": grid_meta,
                "inliers": reg["inliers"],
                "matches": reg["matches"],
                "panel_inliers": reg.get("panel_inliers"),
                "panel_shifts": {str(k): v for k, v in shifts.items()},
                "det_boxes": len(rects),
                "elapsed": round(time.time() - t0, 2),
                "elapsed_register": round(t_reg, 2),
                "elapsed_detect": round(t_det, 2),
                "elapsed_ocr": round(t_ocr, 2),
                "scores": scores,
                "notes": plausibility(fields),
            },
        }

    def scan_image_layout(self, img: np.ndarray, with_images: bool = True) -> dict:
        t0 = time.time()
        reg = self.registrar.register(img)
        t_reg = time.time() - t0

        items = []
        crops_display = {}
        snapped = {}
        for name, spec in self.layout.items():
            box = list(spec["box"])
            pi = self.registrar.panel_index(box)
            H = reg["panel_H"][pi] if (pi is not None and reg["panel_H"]) else reg["H"]
            # Rasterpanels (Feldpanels mit Zeilenlinien): Box auf das Linienpaar der Zeile einrasten
            if pi is not None and pi in self.layout.rule_panels and not spec.get("multiline"):
                box = self.registrar.snap_box_to_rules(img, H, box, self.layout.row_pitch)
                snapped[name] = round(box[1] - spec["box"][1], 1)
            crop_ocr = self.registrar.warp_box(img, H, box, scale=self.ocr_scale, pad=2)
            if self.refine_crops and not spec.get("multiline") and crop_ocr is not None:
                crop_ocr = self.registrar.refine_crop(crop_ocr, text_h=0.72 * self.layout.row_pitch * self.ocr_scale)
            items.append((name, crop_ocr, bool(spec.get("multiline"))))
            if with_images:
                crops_display[name] = self.registrar.warp_box(img, H, box, scale=1.0, pad=2)

        t1 = time.time()
        raw = self.ocr.recognize(items, line_h=0.72 * self.layout.row_pitch * self.ocr_scale)
        t_ocr = time.time() - t1

        fields = clean_fields({n: raw.get(n, ("", 0.0)) for n, _, _ in items})
        fields.update(derive(fields))
        scores = {n: round(s, 3) for n, (_, s) in raw.items()}

        data = dict(fields)
        if with_images:
            for name, crop in crops_display.items():
                if crop is not None and fields.get(name, "") != "":
                    data[name + "_img"] = to_jpeg_b64(crop, self.crop_jpeg_quality)
            doc = self.registrar.warp_document(img, reg["H"], scale=0.6)
            data["document_img"] = to_jpeg_b64(doc, 82)

        return {
            "data": data,
            "country_code": "de",
            "meta": {
                "engine": "local",
                "inliers": reg["inliers"],
                "matches": reg["matches"],
                "elapsed": round(time.time() - t0, 2),
                "elapsed_register": round(t_reg, 2),
                "elapsed_ocr": round(t_ocr, 2),
                "scores": scores,
                "panel_inliers": reg.get("panel_inliers"),
                "snapped": snapped,
                "notes": plausibility(fields),
            },
        }
