"""
Eigenes CRNN-Erkennungsmodell (ONNX), trainiert auf OCR-B-Zeilen der Zulassungs-
bescheinigung (ocr_train/train_crnn.py). Eingabe: Graubild, Hoehe 32, Tinte hell.
"""
import json
import os

import cv2
import numpy as np


class CrnnReader:
    def __init__(self, model_path=None, threads=4):
        import onnxruntime as ort
        path = model_path or os.path.join(os.path.dirname(__file__), "..", "reference", "crnn.onnx")
        meta = json.load(open(path + ".json", encoding="utf-8"))
        self.chars = meta["chars"]
        self.height = int(meta.get("height", 32))
        so = ort.SessionOptions()
        so.intra_op_num_threads = threads
        so.inter_op_num_threads = 1
        self.sess = ort.InferenceSession(path, so, providers=["CPUExecutionProvider"])
        self.input_name = self.sess.get_inputs()[0].name

    @staticmethod
    def tight(img, margin=3):
        """Auf den Tintenbereich zuschneiden (so sahen die Trainingsausschnitte aus):
        hellster Kanal, lokaler Hintergrund, Otsu, kleine Oeffnung gegen Guilloche."""
        gray = np.max(img, axis=2) if img.ndim == 3 else img
        bg = cv2.morphologyEx(gray, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (25, 25)))
        diff = cv2.subtract(bg, gray)
        thr, _ = cv2.threshold(diff, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        ink = (diff >= max(20, min(thr, 70))).astype(np.uint8)
        ink = cv2.morphologyEx(ink, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
        cols = np.flatnonzero(ink.sum(axis=0) >= 2)
        rows = np.flatnonzero(ink.sum(axis=1) >= 2)
        if len(cols) < 4 or len(rows) < 6:
            return img
        h, w = gray.shape[:2]
        y0, y1 = max(0, rows[0] - margin), min(h, rows[-1] + 1 + margin)
        x0, x1 = max(0, cols[0] - margin), min(w, cols[-1] + 1 + margin)
        if y1 - y0 < 8 or x1 - x0 < 8:
            return img
        return img[y0:y1, x0:x1]

    def _prep(self, img):
        img = self.tight(img)
        gray = np.max(img, axis=2) if img.ndim == 3 else img
        h, w = gray.shape[:2]
        nw = max(8, int(round(w * self.height / max(1, h))))
        g = cv2.resize(gray, (nw, self.height), interpolation=cv2.INTER_AREA if nw < w else cv2.INTER_CUBIC)
        return (255 - g.astype(np.float32)) / 255.0

    def recognize(self, imgs):
        """imgs: Liste von Bildern (BGR oder grau). Liefert Liste (text, konfidenz)."""
        out = []
        for img in imgs:
            if img is None or img.size == 0:
                out.append(("", 0.0))
                continue
            x = self._prep(img)
            w = int(np.ceil(x.shape[1] / 4) * 4)
            pad = np.zeros((1, 1, self.height, w), np.float32)
            pad[0, 0, :, :x.shape[1]] = x
            logits = self.sess.run(None, {self.input_name: pad})[0][0]   # T x C
            # Softmax + Greedy-CTC
            m = logits.max(axis=1, keepdims=True)
            p = np.exp(logits - m)
            p /= p.sum(axis=1, keepdims=True)
            ids = p.argmax(axis=1)
            text, confs, prev = [], [], 0
            for t, i in enumerate(ids):
                if i != prev and i != 0:
                    text.append(self.chars[i - 1])
                    confs.append(float(p[t, i]))
                prev = i
            out.append(("".join(text), float(np.mean(confs)) if confs else 0.0))
        return out
