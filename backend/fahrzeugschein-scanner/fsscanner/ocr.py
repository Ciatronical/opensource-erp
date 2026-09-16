"""
Texterkennung je Feldausschnitt mit RapidOCR (PP-OCRv5, Latin-Modell, ONNX/CPU).

Es wird bewusst NUR der Erkennungsschritt benutzt (keine Textdetektion): Die
Ausschnitte sind dank Registrierung + Layout bereits bekannt. Das ist ~20x schneller
und auf den kleinen Feldern auch treffsicherer als Detektion + Erkennung.

Mehrzeilige Felder (z. B. Feld 22 Bemerkungen, Anschrift) werden per horizontalem
Projektionsprofil in Zeilen zerlegt; die Schrift (OCR-B) hat klare Zeilenabstaende.
"""
import logging
import os

import cv2
import numpy as np

logging.getLogger("RapidOCR").setLevel(logging.WARNING)

from rapidocr import RapidOCR, LangRec, ModelType, OCRVersion  # noqa: E402
from rapidocr.ch_ppocr_rec.typings import TextRecInput  # noqa: E402

from .postprocess import matches_format  # noqa: E402


class FieldOcr:
    def __init__(self, target_height: int = 48, batch: int = 16, threads: int = 0):
        params = {
            "Rec.lang_type": LangRec.LATIN,
            "Rec.model_type": ModelType.MOBILE,
            "Rec.ocr_version": OCRVersion.PPOCRV5,
            "Rec.rec_batch_num": batch,
        }
        if threads > 0:
            # ONNX-Threads begrenzen: mit -1 (alle Kerne) bremsen sich Dienste gegenseitig aus
            params["EngineConfig.onnxruntime.intra_op_num_threads"] = threads
            params["EngineConfig.onnxruntime.inter_op_num_threads"] = 1
        self.ocr = RapidOCR(params=params)
        # Zweites Erkennungsmodell (Englisch): auf Codes/Zahlen/FIN treffsicherer (83,6 % vs
        # 77,6 % im Vergleich), kennt aber keine Umlaute -> nur fuer Felder ohne Umlaute
        params_en = dict(params)
        params_en["Rec.lang_type"] = LangRec.EN
        self.ocr_en = RapidOCR(params=params_en)
        self.target_height = target_height
        # Eigenes CRNN (auf OCR-B trainiert), falls vorhanden: dritte Stimme fuer Codes/Zahlen
        self.crnn = None
        crnn_path = os.environ.get("FSSCANNER_CRNN") or os.path.join(os.path.dirname(__file__), "..", "reference", "crnn.onnx")
        if os.path.isfile(crnn_path) and os.environ.get("FSSCANNER_USE_CRNN", "1") == "1":
            try:
                from .crnn import CrnnReader
                self.crnn = CrnnReader(crnn_path, threads=max(1, threads // 2) if threads else 4)
            except Exception as e:  # noqa: BLE001
                logging.getLogger(__name__).warning("CRNN nicht geladen: %s", e)

    # ---- Vorverarbeitung -------------------------------------------------
    @staticmethod
    def _norm_line(img, max_width: int = 2400):
        """Zeile auf Erkennungshoehe (~64 px) bringen; hoechstens 3x hochskalieren und
        die Breite deckeln — sonst kosten Rausch-Streifen (8 px hoch, 9000 px breit) Sekunden."""
        h, w = img.shape[:2]
        if h < 6 or w < 6:
            return None
        s = min(3.0, 64.0 / h)
        if s * w > max_width:
            s = max_width / w
        if abs(s - 1.0) > 0.01:
            img = cv2.resize(img, None, fx=s, fy=s, interpolation=cv2.INTER_CUBIC if s > 1 else cv2.INTER_AREA)
        return cv2.copyMakeBorder(img, 6, 6, 10, 10, cv2.BORDER_REPLICATE)

    @staticmethod
    def split_lines(img, line_h: float = 38.0, max_lines: int = 8):
        """Zerlegt einen mehrzeiligen Ausschnitt in Zeilenbilder (Projektionsprofil).
        Tinte = deutlich dunkler als der lokale Hintergrund (Guilloche ist hell)."""
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY) if img.ndim == 3 else img
        bg = cv2.morphologyEx(gray, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (21, 21)))
        ink = ((bg.astype(np.int16) - gray.astype(np.int16)) > 40).astype(np.uint8) * 255
        # Guilloche (1 px feine Linien) entfernen, OCR-B-Striche (~4 px) bleiben
        ink = cv2.morphologyEx(ink, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
        # gedruckte Zeilenlinien entfernen: laengere waagerechte Striche als jede Glyphe,
        # Kernel kurz genug, dass auch leicht gewellte Linien (nach Entzerrung) erfasst werden
        klen = max(40, int(img.shape[1] * 0.03))
        rules = cv2.morphologyEx(ink, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (klen, 1)))
        rules = cv2.dilate(rules, cv2.getStructuringElement(cv2.MORPH_RECT, (1, 7)))
        ink[rules > 0] = 0
        prof = (ink > 0).sum(axis=1).astype(float)
        H = len(prof)
        if prof.max() <= 0:
            return []
        # Schwelle relativ zu den Textzeilen: Zwischenraeume enthalten Linienreste/Guilloche
        thr = max(2.0, 0.3 * float(np.percentile(prof, 95)))
        on = prof > thr
        # kleine Luecken (<= 3 px) innerhalb einer Zeile schliessen
        runs = []
        y = 0
        while y < H:
            if not on[y]:
                y += 1
                continue
            y0 = y
            while y < H and (on[y] or (y + 3 < H and on[y + 1:y + 4].any())):
                y += 1
            runs.append((y0, y))
        if not runs:
            return []
        out = []
        for y0, y1 in runs:
            h = y1 - y0
            if h < 0.45 * line_h:
                continue  # Kruemel (Unterstreichung, Rauschen)
            # Zeile muss auch genug Tinte haben (keine Guilloche-Streifen)
            if prof[y0:y1].sum() < 0.15 * line_h * img.shape[1] * 0.05:
                continue
            k = max(1, int(round(h / line_h)))  # verklebte Zeilen gleichmaessig teilen
            step = h / k
            for i in range(k):
                a, b = int(y0 + i * step), int(y0 + (i + 1) * step)
                pad = int(0.2 * (b - a))
                out.append(img[max(0, a - pad):min(H, b + pad)])
            if len(out) >= max_lines:
                break
        return out[:max_lines]

    def read_multiline(self, img, line_h: float):
        """Detektion + Erkennung; Wortboxen nach Zeilen gruppieren (y-Naehe), links->rechts."""
        h, w = img.shape[:2]
        # Detektor arbeitet intern mit min. 736 px kurzer Seite -> Ausschnitt nicht groesser als noetig
        s = min(1.0, 1400.0 / max(w, 1))
        work = cv2.resize(img, None, fx=s, fy=s, interpolation=cv2.INTER_AREA) if s < 1 else img
        out = self.ocr(work, use_det=True, use_cls=False, use_rec=True)
        if not out.txts:
            return ("", 0.0)
        words = []
        for box, txt, sc in zip(out.boxes, out.txts, out.scores):
            if not txt or not txt.strip() or sc < 0.3:
                continue
            pts = np.array(box, dtype=float)
            cy = float(pts[:, 1].mean()) / s
            x0 = float(pts[:, 0].min()) / s
            words.append((cy, x0, txt.strip(), float(sc)))
        if not words:
            return ("", 0.0)
        words.sort()
        lines, cur, cur_y = [], [], None
        for cy, x0, txt, sc in words:
            if cur_y is None or abs(cy - cur_y) < 0.5 * line_h:
                cur.append((x0, txt, sc))
                cur_y = cy if cur_y is None else (cur_y + cy) / 2
            else:
                lines.append(cur)
                cur, cur_y = [(x0, txt, sc)], cy
        lines.append(cur)
        texts, scores = [], []
        for ln in lines:
            ln.sort()
            texts.append(" ".join(t for _, t, _ in ln))
            scores.extend(sc for _, _, sc in ln)
        return ("\n".join(texts), float(np.mean(scores)))

    # Felder mit Umlauten/Freitext -> Latin-Modell; alles andere (Codes, Zahlen, FIN) -> Englisch
    LATIN_FIELDS = {"d1", "d3", "name1", "name2", "firstname", "address1", "address2", "field_2", "field_5_1",
                    "field_5_2", "field_14", "p3", "field_22", "r", "creation_city", "document_id", "field_4"}

    # Felder, deren kleiner Bezeichner ("2.1", "P.1", "14.1") direkt am Wert klebt
    # Nur Felder, bei denen der Schnitt in der Messung half (HSN +9, Feld 4 +12, Pruefziffer +7,
    # 14.1 +3); bei G, P.2/P.4, TSN und P.1 schadete er
    TRIM_FIELDS = {"hsn", "field_3", "field_4", "field_14_1"}

    def _model_for(self, key):
        name = key[0] if isinstance(key, tuple) else key
        return self.ocr if name in self.LATIN_FIELDS else self.ocr_en

    @staticmethod
    def trim_label(img, min_frac=0.68, max_pos=0.4):
        """Kleinen Feldbezeichner ("2.1", "P.1") links vom Wert abschneiden: Bezeichnerschrift ist
        etwa halb so hoch wie OCR-B. Schneidet nur, wenn links ein deutlich niedrigerer Bereich
        liegt und der Wertanfang im linken Teil (max_pos) des Ausschnitts beginnt."""
        from . import grid as _g
        h, w = img.shape[:2]
        if w < 30 or h < 12:
            return img
        ink = _g.binarize(img)
        ink = cv2.morphologyEx(ink, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
        # 1) Rechte Rahmenlinie des Bezeichnerkaestchens im linken Teil: dahinter schneiden
        vk_full = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(10, int(0.7 * h))))
        vcols = np.flatnonzero(cv2.morphologyEx(ink, cv2.MORPH_OPEN, vk_full).sum(axis=0) > 0)
        vcols = vcols[vcols < max_pos * w]
        colsum = (ink > 0).sum(axis=0)
        # echte Rahmenlinie: dahinter folgt eine Luecke (Buchstabenstriche haben rechts weiter Tinte)
        good = [c for c in vcols if c + 8 < w and colsum[c + 3:c + 8].max() <= 0.12 * h]
        if good:
            cut = int(max(good)) + 3
            if cut < w - 20:
                return img[:, cut:]
        # 2) sonst: Hoehenkriterium (Bezeichnerschrift ist deutlich niedriger)
        # Kaestchenlinien (Rahmen des Bezeichners) entfernen, sonst wirkt der kleine Bezeichner "hoch"
        vk = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(10, int(0.55 * h))))
        ink[cv2.dilate(cv2.morphologyEx(ink, cv2.MORPH_OPEN, vk), np.ones((1, 3), np.uint8)) > 0] = 0
        hk = cv2.getStructuringElement(cv2.MORPH_RECT, (max(20, int(0.3 * w)), 1))
        ink[cv2.dilate(cv2.morphologyEx(ink, cv2.MORPH_OPEN, hk), np.ones((3, 1), np.uint8)) > 0] = 0
        heights = np.zeros(w, float)
        for x in range(w):
            r = np.flatnonzero(ink[:, x] > 0)
            if len(r):
                heights[x] = r[-1] - r[0] + 1
        if heights.max() <= 0:
            return img
        k = 4
        hm = np.array([heights[max(0, i - k):i + k + 1].max() for i in range(w)])
        tall = hm >= min_frac * hm.max()
        first = int(np.argmax(tall)) if tall.any() else 0
        if first < 6 or first > max_pos * w:
            return img
        left = hm[:max(1, first - 4)]
        if left.max() > 0.75 * hm.max() or (left > 0).sum() < 4:
            return img  # links nichts Kleines -> nicht schneiden
        return img[:, max(0, first - 5):]

    # ---- Erkennung ---------------------------------------------------------
    def recognize(self, items, line_h: float = 38.0):
        """items: Liste von (key, img, multiline). Liefert dict key -> (text, score).
        line_h: erwartete Hoehe einer Textzeile in den Ausschnitten (Pixel)."""
        batch_imgs, batch_keys, batch_raw = [], [], []
        results = {}
        for key, img, multiline in items:
            if img is None or img.size == 0:
                continue
            if multiline:
                # Mehrzeilige Felder (Bemerkungen): Textdetektion auf dem Ausschnitt liefert
                # die Zeilen zuverlaessiger als ein Projektionsprofil (Guilloche, Linien).
                results[key] = self.read_multiline(img, line_h)
                continue
            name = key[0] if isinstance(key, tuple) else key
            if name in self.TRIM_FIELDS:
                img = self.trim_label(img)
            n = self._norm_line(img)
            if n is not None:
                batch_imgs.append(n)
                batch_keys.append((key, 0))
                batch_raw.append(img)  # eigenes CRNN bekommt den Rohausschnitt (so trainiert)
        if not batch_imgs:
            return results
        # Je Modell ein Batch: Latin fuer Text (Umlaute), Englisch fuer Codes/Zahlen; dort stimmt
        # das eigene OCR-B-Modell (Rohausschnitt) mit ab und fuehrt, solange es sicher ist.
        per_key = {}
        for model in (self.ocr, self.ocr_en):
            idx = [i for i, (key, _) in enumerate(batch_keys) if self._model_for(key) is model]
            if not idx:
                continue
            out = model.text_rec(TextRecInput(img=[batch_imgs[i] for i in idx]))
            merged = [((t or ""), float(sc or 0.0)) for t, sc in zip(out.txts, out.scores)]
            if self.crnn is not None and model is self.ocr:
                # Textfelder: Latin-Modell und eigenes Modell stimmen ab, hoechste Konfidenz gewinnt
                # (auf Validierungsausschnitten 92 % statt 88 %)
                alt = self.crnn.recognize([batch_raw[i] for i in idx])
                for j, ((t1, s1), (t2, s2)) in enumerate(zip(merged, alt)):
                    if t2 and (not t1 or s2 > s1):
                        merged[j] = (t2, s2)
            if self.crnn is not None and model is self.ocr_en:
                alt = self.crnn.recognize([batch_raw[i] for i in idx])
                for j, ((t1, s1), (t2, s2)) in enumerate(zip(merged, alt)):
                    name = batch_keys[idx[j]][0]
                    name = name[0] if isinstance(name, tuple) else name
                    if name == "registrationNumber" and t1:
                        continue  # Kennzeichenschrift: PP-OCR fuehrt (eigenes Modell kennt sie kaum)
                    same = t2 and t1 and t2.replace(" ", "") == t1.replace(" ", "")
                    if same:
                        merged[j] = (t2, max(s1, s2))
                        continue
                    # festes Feldformat entscheidet: der Kandidat, der es erfuellt, gewinnt
                    ok1 = bool(t1) and matches_format(name, t1)
                    ok2 = bool(t2) and matches_format(name, t2)
                    if ok2 and not ok1:
                        merged[j] = (t2, s2)
                    elif ok1 and not ok2:
                        pass
                    elif ok1 and ok2 and len(t1.replace(" ", "")) >= len(t2.replace(" ", "")) + 2:
                        pass  # PP-OCR sah mehr Zeichen (Bezeichner mit im Kasten) -> seine Bereinigung ist verlaesslicher
                    elif t2 and ((s2 >= 0.85 and s1 < s2 + 0.05) or not t1):
                        merged[j] = (t2, s2)
            for i, (txt, sc) in zip(idx, merged):
                key, li = batch_keys[i]
                per_key.setdefault(key, []).append((li, txt, sc))
        for key, lst in per_key.items():
            lst.sort()
            texts = [t for _, t, s in lst if t.strip() and s >= 0.3]
            scores = [s for _, t, s in lst if t.strip()]
            results[key] = ("\n".join(texts).strip(), float(np.mean(scores)) if scores else 0.0)
        return results
        out = self.ocr.text_rec(TextRecInput(img=batch_imgs))
        per_key = {}
        for (key, li), txt, sc in zip(batch_keys, out.txts, out.scores):
            per_key.setdefault(key, []).append((li, txt or "", float(sc or 0.0)))
        for key, lst in per_key.items():
            lst.sort()
            texts = [t for _, t, s in lst if t.strip() and s >= 0.3]
            scores = [s for _, t, s in lst if t.strip()]
            results[key] = ("\n".join(texts).strip(), float(np.mean(scores)) if scores else 0.0)
        return results
