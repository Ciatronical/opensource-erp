#!/usr/bin/env python3
"""
Synthetische Trainingszeilen aus der Glyphenbank (echte OCR-B-Zeichen aus Fotos).

Texte: FIN, HSN/TSN, Daten, Kennzeichen-aehnliche Codes, Zahlen, Namen/Strassen
(aus der Ground-Truth-Wortliste), Typgenehmigungen (e1*2007/46*...). Jede Zeile wird
aus zufaelligen Exemplaren der Zeichen zusammengesetzt, mit Jitter in Abstand,
Hoehe und Helligkeit, Unschaerfe, Rauschen und leichter Neigung.
"""
import random

import cv2
import numpy as np

H = 32


class Synth:
    def __init__(self, bank_path, words=None):
        z = np.load(bank_path)
        self.bank = {chr(int(k[2:], 16)): z[k] for k in z.files}
        self.words = [w for w in (words or []) if all(ch in self.bank for ch in w)]
        self.chars = sorted(self.bank)
        self.rng = random.Random(1)

    # ---- Textgeneratoren ----------------------------------------------------
    def _alnum(self, n, letters="ABCDEFGHJKLMNPRSTUVWXYZ", digits="0123456789"):
        return "".join(self.rng.choice(letters + digits) for _ in range(n))

    def text(self):
        r = self.rng.random()
        if r < 0.18:   # FIN
            wmi = self.rng.choice(["WVW", "WV2", "WBA", "WDB", "WAU", "VF3", "VF7", "ZFA", "TMB", "W0L", "WF0", "1HD", "JTD", "KMH", "YV1", "SAL", "VSS", "WMW", "VNK", "LRW"])
            return wmi + self._alnum(14)
        if r < 0.26:   # HSN / TSN / Pruefziffer
            return self.rng.choice([("%04d" % self.rng.randrange(10000)), self._alnum(3) + "%05d" % self.rng.randrange(100000), self._alnum(3) + "%03d" % self.rng.randrange(1000) + self.rng.choice("0123456789")])
        if r < 0.36:   # Daten
            return self.rng.choice(["%02d.%02d.%04d" % (self.rng.randrange(1, 29), self.rng.randrange(1, 13), self.rng.randrange(1985, 2027)),
                                    "%02d.%02d" % (self.rng.randrange(1, 13), self.rng.randrange(20, 32)),
                                    "%02d.%02d.%02d" % (self.rng.randrange(1, 29), self.rng.randrange(1, 13), self.rng.randrange(0, 30))])
        if r < 0.50:   # Zahlen / Bereiche / Leistung / Reifen
            return self.rng.choice(["%d" % self.rng.randrange(0, 5000), "%d-%d" % (self.rng.randrange(800, 2500), self.rng.randrange(800, 2500)),
                                    "%d /%d" % (self.rng.randrange(30, 400), self.rng.randrange(1500, 7000)),
                                    "%d/%dR%d %d%s" % (self.rng.randrange(145, 315, 10), self.rng.randrange(30, 80, 5), self.rng.randrange(13, 22), self.rng.randrange(75, 120), self.rng.choice(["H", "V", "T", "W", "Y"])),
                                    "%d,%d" % (self.rng.randrange(0, 9), self.rng.randrange(0, 99)), "%04d" % self.rng.randrange(0, 40)])
        if r < 0.62:   # Typgenehmigung / Codes
            return self.rng.choice(["e%d*%d/%d*%04d*%02d" % (self.rng.randrange(1, 25), self.rng.choice([2001, 2007]), self.rng.choice([116, 46]), self.rng.randrange(0, 9999), self.rng.randrange(0, 40)),
                                    "%s%s" % (self.rng.choice(["M1", "N1", "L3e", "N2", "O2", "L1e"]), ""), self._alnum(self.rng.randrange(2, 9)),
                                    "%d/%d/EG" % (self.rng.randrange(70, 2010), self.rng.randrange(1, 99)), "EURO%d" % self.rng.randrange(1, 7)])
        if r < 0.95 and self.words:   # Namen, Strassen, Modelle
            n = self.rng.randrange(1, 4)
            return " ".join(self.rng.choice(self.words) for _ in range(n))[:34]
        return "".join(self.rng.choice(self.chars) for _ in range(self.rng.randrange(2, 14)))

    # ---- Rendering ------------------------------------------------------------
    def render(self, text):
        parts = []
        gap = self.rng.randrange(-1, 3)
        for ch in text:
            if ch not in self.bank:
                ch = " "
            g = self.bank[ch][self.rng.randrange(len(self.bank[ch]))]
            # leichte Groessenvariation je Zeichen
            w = 17 + self.rng.randrange(-2, 3)
            g = cv2.resize(g, (w, H), interpolation=cv2.INTER_LINEAR)
            if gap > 0:
                fill = int(np.median(g[:, :2])) if g.size else 200
                g = cv2.copyMakeBorder(g, 0, 0, 0, gap, cv2.BORDER_CONSTANT, value=fill)
            parts.append(g)
        line = np.hstack(parts) if parts else np.full((H, 20), 200, np.uint8)
        pad_l, pad_r = self.rng.randrange(2, 14), self.rng.randrange(2, 14)
        line = cv2.copyMakeBorder(line, 3, 3, pad_l, pad_r, cv2.BORDER_REPLICATE)
        # Augmentierung: Helligkeit/Kontrast, Unschaerfe, Rauschen, Neigung, Skalierung
        img = line.astype(np.float32)
        img = img * self.rng.uniform(0.75, 1.15) + self.rng.uniform(-25, 25)
        if self.rng.random() < 0.5:
            img = cv2.GaussianBlur(img, (0, 0), self.rng.uniform(0.3, 1.2))
        img = img + np.random.normal(0, self.rng.uniform(1, 8), img.shape)
        if self.rng.random() < 0.5:
            sh = self.rng.uniform(-0.12, 0.12)
            M = np.float32([[1, sh, -sh * img.shape[0] / 2], [0, 1, 0]])
            img = cv2.warpAffine(img, M, (img.shape[1], img.shape[0]), borderMode=cv2.BORDER_REPLICATE)
        if self.rng.random() < 0.4:  # vorher kleiner gewesen (Handyfoto) -> Aufloesungsverlust
            f = self.rng.uniform(0.55, 0.95)
            small = cv2.resize(img, None, fx=f, fy=f, interpolation=cv2.INTER_AREA)
            img = cv2.resize(small, (img.shape[1], img.shape[0]), interpolation=cv2.INTER_LINEAR)
        img = np.clip(img, 0, 255).astype(np.uint8)
        return cv2.resize(img, (max(8, int(img.shape[1] * H / img.shape[0])), H), interpolation=cv2.INTER_AREA)
