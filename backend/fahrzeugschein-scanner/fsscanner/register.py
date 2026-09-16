"""
Registrierung eines Fotos auf die Referenzvorlage (SIFT + RANSAC-Homographie).

Die Vorlage ist ein entzerrtes, "leeres" Formular (Median vieler registrierter Scans).
Gedruckte Beschriftungen, Rahmen und Guillochen liefern tausende stabile Merkmale;
die variablen Eintragungen werden durch RANSAC als Ausreisser verworfen. SIFT ist
rotations- und skaleninvariant, das Foto darf also beliebig gedreht sein.

Falten: Die Zulassungsbescheinigung ist zweimal gefaltet (drei Panels). Liegt sie
nicht ganz plan, passt EINE Homographie nicht ueberall. Deshalb wird pro Panel aus
den dort liegenden Inliern eine eigene Homographie verfeinert (Fallback: global).
"""
import cv2
import numpy as np

# Referenzkoordinaten -> Foto: H_ref2img; wir speichern immer H (Foto -> Referenz).


class RegistrationError(Exception):
    pass


class Registrar:
    def __init__(self, reference_bgr: np.ndarray, panels=None, work_side: int = 1500,
                 ref_side: int = 1400, refine_scale: float = 0.5, min_inliers: int = 40,
                 panel_min_inliers: int = 10, exclude_boxes=None):
        self.ref = reference_bgr
        self.ref_h, self.ref_w = reference_bgr.shape[:2]
        self.work_side = work_side
        self.min_inliers = min_inliers
        self.panel_min_inliers = panel_min_inliers
        # Panels als [x0, y0, x1, y1] in Referenzkoordinaten (None -> nur global)
        self.panels = panels or []
        # Kein Merkmalslimit: mit Limit gewinnen die kontrastreichen Guillochen-Ecken,
        # die zwischen Fotos nicht wiederfindbar sind — die gedruckten Texte/Rahmen sind
        # die stabilen Merkmale. Leichte Weichzeichnung unterdrueckt die Guilloche.
        self.sift = cv2.SIFT_create(nfeatures=0, contrastThreshold=0.02)
        ref_gray, self.ref_scale = self._prep(cv2.cvtColor(reference_bgr, cv2.COLOR_BGR2GRAY), ref_side)
        self.ref_kp, self.ref_desc = self.sift.detectAndCompute(ref_gray, None)
        # Ankerpatches fuer die Panel-Verfeinerung: kleine Ausschnitte des Vordrucks
        # (Feldbezeichner, Linienkreuzungen) ausserhalb der Wertefelder. Sie werden per
        # normierter Kreuzkorrelation im grob entzerrten Foto gesucht — robust gegen
        # Belichtung und fremde Eintragungen, anders als SIFT/LK auf den kleinen Glyphen.
        self.refine_scale = refine_scale
        self.anchors = self._build_anchors(exclude_boxes or [])
        # Feinere SIFT-Merkmale (kleine Feldbezeichner) fuer die Panel-Verfeinerung
        self.sift_fine = cv2.SIFT_create(nfeatures=0, contrastThreshold=0.01)
        fine_gray, self.fine_scale = self._prep(cv2.cvtColor(reference_bgr, cv2.COLOR_BGR2GRAY), 1800)
        self.fine_kp, self.fine_desc = self.sift_fine.detectAndCompute(fine_gray, None)
        self.fine_matcher = cv2.FlannBasedMatcher(dict(algorithm=1, trees=5), dict(checks=64))
        self.fine_matcher.add([self.fine_desc])
        self.fine_matcher.train()
        # Linienprofile der Vorlage je Panel (Raster: waagerechte Zeilenlinien, senkrechte
        # Spaltentrenner) fuer die Ausrichtung per Kreuzkorrelation — robust auch dort,
        # wo die Beschriftungen zu klein/blass fuer Ankerpatches sind (rechtes Panel).
        if self.ref_desc is None or len(self.ref_kp) < 200:
            raise RegistrationError("Referenzvorlage liefert zu wenige Merkmale")
        index_params = dict(algorithm=1, trees=5)  # FLANN KD-Tree
        self.matcher = cv2.FlannBasedMatcher(index_params, dict(checks=64))
        self.matcher.add([self.ref_desc])
        self.matcher.train()

    @staticmethod
    def _prep(gray, side):
        """Gleiche Vorverarbeitung fuer Vorlage und Foto: verkleinern, CLAHE, Blur."""
        scale = min(1.0, side / max(gray.shape))
        if scale < 1.0:
            gray = cv2.resize(gray, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)
        gray = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(gray)
        gray = cv2.GaussianBlur(gray, (0, 0), 1.0)
        return gray, scale

    def register(self, img_bgr: np.ndarray):
        """Liefert dict mit H (Foto->Referenz, volle Aufloesung), inliers, panel_H."""
        h, w = img_bgr.shape[:2]
        gray, scale = self._prep(cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY), self.work_side)
        kp, desc = self.sift.detectAndCompute(gray, None)
        if desc is None or len(kp) < 50:
            raise RegistrationError("Zu wenige Merkmale im Foto")

        knn = self.matcher.knnMatch(desc, k=2)
        good = [m[0] for m in knn if len(m) == 2 and m[0].distance < 0.8 * m[1].distance]
        if len(good) < self.min_inliers:
            raise RegistrationError(f"Zu wenige Uebereinstimmungen mit der Vorlage ({len(good)})")

        src = np.float32([kp[m.queryIdx].pt for m in good]) / scale  # volle Aufloesung
        dst = np.float32([self.ref_kp[m.trainIdx].pt for m in good]) / self.ref_scale  # volle Referenz
        H, mask = cv2.findHomography(src, dst, cv2.USAC_MAGSAC, 6.0, maxIters=5000, confidence=0.999)
        if H is None:
            raise RegistrationError("Keine Homographie gefunden")
        inl = mask.ravel().astype(bool)
        n_inl = int(inl.sum())
        if n_inl < self.min_inliers:
            raise RegistrationError(f"Zu wenige Inlier ({n_inl}) — kein Fahrzeugschein erkannt?")

        # Plausibilitaet: Homographie darf nicht entarten
        if not self._sane(H, w, h):
            raise RegistrationError("Unplausible Homographie")

        panel_H, panel_inl = self._refine_panels(img_bgr, H)

        return {"H": H, "inliers": n_inl, "matches": len(good), "panel_H": panel_H,
                "panel_inliers": panel_inl}

    def _build_anchors(self, exclude_boxes, patch=(36, 26), per_panel=70, min_dist=22):
        """Je Panel bis zu per_panel Ankerpatches (Breite x Hoehe in refine_scale-Pixeln)
        an markanten Ecken des Vordrucks, nicht in Wertefeldern."""
        rs = self.refine_scale
        gray = cv2.cvtColor(self.ref, cv2.COLOR_BGR2GRAY)
        g = cv2.resize(gray, None, fx=rs, fy=rs, interpolation=cv2.INTER_AREA)
        g = cv2.GaussianBlur(g, (0, 0), 0.8)
        self.anchor_ref = g
        pw, ph = patch
        anchors = []
        for pi, (x0, y0, x1, y1) in enumerate(self.panels):
            mask = np.zeros(g.shape, np.uint8)
            mask[int(y0 * rs) + ph:int(y1 * rs) - ph, int(x0 * rs) + pw:int(x1 * rs) - pw] = 255
            # Wertefelder leicht verkleinert ausschliessen: die schmalen Bezeichnerspalten
            # ("D.1", "15.2") und Linienkreuzungen dazwischen bleiben als Anker erhalten
            for (bx0, by0, bx1, by1) in exclude_boxes:
                mask[int(by0 * rs) + 3:int(by1 * rs) - 3, int(bx0 * rs) + 4:int(bx1 * rs) - 4] = 0
            pts = cv2.goodFeaturesToTrack(g, per_panel, 0.01, min_dist, mask=mask, blockSize=5)
            if pts is None:
                continue
            for (cx, cy) in pts.reshape(-1, 2):
                ax, ay = int(cx - pw / 2), int(cy - ph / 2)
                tmpl = g[ay:ay + ph, ax:ax + pw]
                if tmpl.shape != (ph, pw) or tmpl.std() < 8:
                    continue
                anchors.append({"panel": pi, "x": ax, "y": ay, "tmpl": tmpl})
        return anchors

    def snap_box_to_rules(self, img_bgr, H, box, pitch, scale=0.5, tol=0.45):
        """Feld in einem Rasterpanel vertikal auf das Linienpaar ueber/unter seiner Zeile
        einrasten. Suchfenster < halbe Zeilenhoehe -> keine Verwechslung mit Nachbarzeilen.
        Liefert die (ggf. verschobene) Box."""
        x0, y0, x1, y1 = box
        h = y1 - y0
        ext = 0.6 * h
        crop = self.warp_box(img_bgr, H, [x0, y0 - ext, x1, y1 + ext], scale=scale)
        if crop is None or crop.shape[0] < 10:
            return box
        g = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        bg = cv2.morphologyEx(g, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (11, 11)))
        ink = ((bg.astype(np.int16) - g.astype(np.int16)) > 18).astype(np.uint8)
        klen = max(15, int(crop.shape[1] * 0.35))
        rules = cv2.morphologyEx(ink, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (klen, 1)))
        prof = rules.sum(axis=1).astype(np.float32) / max(1, crop.shape[1])
        if prof.max() < 0.25:
            return box

        def strongest(center, win):
            a, b = int(max(0, center - win)), int(min(len(prof), center + win))
            if b <= a:
                return None
            seg = prof[a:b]
            i = int(np.argmax(seg))
            return (a + i) if seg[i] >= 0.25 else None

        # Erwartung: Linien liegen etwa an Boxoberkante und -unterkante (Text fuellt die Zeile)
        exp_top = ext * scale
        exp_bot = (ext + h) * scale
        win = tol * pitch * scale
        top = strongest(exp_top, win)
        bot = strongest(exp_bot, win)
        if top is not None and bot is not None:
            dist = (bot - top) / scale
            if not (0.7 * pitch <= dist <= 1.35 * pitch):
                # eine der beiden Linien ist fremd -> die naeher an der Erwartung nehmen
                if abs(top - exp_top) <= abs(bot - exp_bot):
                    bot = None
                else:
                    top = None
        if top is not None and bot is not None:
            shift = ((top + bot) / 2 - (exp_top + exp_bot) / 2) / scale
        elif top is not None:
            shift = (top - exp_top) / scale
        elif bot is not None:
            shift = (bot - exp_bot) / scale
        else:
            return box
        return [x0, y0 + shift, x1, y1 + shift]

    def _refine_panels(self, img_bgr, H, panel_min=20):
        """Zweiter SIFT-Abgleich auf dem bereits (global) entzerrten Bild: Merkmale duerfen
        nur wenig verschoben sein (Toleranz), dadurch fallen Fehlzuordnungen an den vielen
        gleichartigen Kaestchen weg und pro Panel bleiben genug Inlier fuer eine eigene
        Homographie (Falten, gewoelbtes Papier). Fallback: globale Homographie."""
        if not self.panels:
            return [], []
        warped = self.warp_document(img_bgr, H, scale=self.fine_scale)
        gray, _ = self._prep(cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY), 10 ** 9)
        kp, desc = self.sift_fine.detectAndCompute(gray, None)
        if desc is None or len(kp) < 50:
            return [H] * len(self.panels), [0] * len(self.panels)
        knn = self.fine_matcher.knnMatch(desc, k=2)
        good = [m[0] for m in knn if len(m) == 2 and m[0].distance < 0.85 * m[1].distance]
        if not good:
            return [H] * len(self.panels), [0] * len(self.panels)
        pw = np.float32([kp[m.queryIdx].pt for m in good]) / self.fine_scale
        pr = np.float32([self.fine_kp[m.trainIdx].pt for m in good]) / self.fine_scale
        near = np.linalg.norm(pw - pr, axis=1) < 0.025 * self.ref_w
        pw, pr = pw[near], pr[near]
        out, counts = [], []
        for (x0, y0, x1, y1) in self.panels:
            sel = (pr[:, 0] >= x0) & (pr[:, 0] < x1) & (pr[:, 1] >= y0) & (pr[:, 1] < y1)
            Hp, n = None, 0
            if sel.sum() >= panel_min:
                Hr, mp = cv2.findHomography(pw[sel], pr[sel], cv2.RANSAC, 3.0)
                if Hr is not None:
                    n = int(mp.sum())
                    cand = Hr @ H
                    if n >= panel_min and self._sane(cand, img_bgr.shape[1], img_bgr.shape[0]):
                        Hp = cand
            out.append(Hp if Hp is not None else H)
            counts.append(n)
        return out, counts

    @staticmethod
    def refine_crop(crop, text_h, pad_frac=0.25):
        """Ausschnitt lokal an den gedruckten Linien ausrichten: Spaltentrenner links/rechts
        abschneiden (sonst liest die OCR den Feldbezeichner mit) und die Textzeile waehlen,
        die der Boxmitte am naechsten liegt (sonst blutet die Nachbarzeile hinein).
        Toleriert so Registrierungsfehler von +-halber Zeile ohne globale Korrektur."""
        h, w = crop.shape[:2]
        if h < 12 or w < 12:
            return crop
        gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if crop.ndim == 3 else crop
        bg = cv2.morphologyEx(gray, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_RECT, (15, 15)))
        ink = ((bg.astype(np.int16) - gray.astype(np.int16)) > 35).astype(np.uint8)
        # senkrechte Trennlinien: lange vertikale Striche
        vk = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(8, int(0.7 * h))))
        vl = cv2.morphologyEx(ink, cv2.MORPH_OPEN, vk).sum(axis=0)
        xs = np.flatnonzero(vl >= 0.7 * h)
        x0, x1 = 0, w
        left = xs[xs < 0.3 * w]
        right = xs[xs > 0.7 * w]
        if len(left):
            x0 = int(left.max()) + 3
        if len(right):
            x1 = int(right.min()) - 2
        if x1 - x0 < 8:
            x0, x1 = 0, w
        # Textzeile: Tinte ohne Guilloche (3x3-Oeffnen) und ohne waagerechte Linien
        ink2 = cv2.morphologyEx(ink[:, x0:x1], cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
        hk = cv2.getStructuringElement(cv2.MORPH_RECT, (max(20, int(0.35 * (x1 - x0))), 1))
        rules = cv2.dilate(cv2.morphologyEx(ink2, cv2.MORPH_OPEN, hk), np.ones((5, 1), np.uint8))
        ink2[rules > 0] = 0
        prof = ink2.sum(axis=1).astype(float)
        thr = max(1.0, 0.08 * prof.max()) if prof.max() > 0 else None
        y0, y1 = 0, h
        if thr is not None:
            on = prof > thr
            runs, y = [], 0
            while y < h:
                if not on[y]:
                    y += 1
                    continue
                a = y
                while y < h and (on[y] or (y + 2 < h and on[y + 1:y + 3].any())):
                    y += 1
                if y - a >= 0.3 * text_h:
                    runs.append((a, y))
            if runs:
                c = h / 2
                a, b = min(runs, key=lambda r: abs((r[0] + r[1]) / 2 - c))
                if abs((a + b) / 2 - c) < 0.8 * text_h:
                    pad = int(pad_frac * (b - a))
                    y0, y1 = max(0, a - pad), min(h, b + pad)
        return crop[y0:y1, x0:x1]

    def warp_document_panels(self, img_bgr, reg, scale: float = 1.0):
        """Wie warp_document, aber jedes Panel mit seiner verfeinerten Homographie."""
        out = self.warp_document(img_bgr, reg["H"], scale)
        for (x0, y0, x1, y1), Hp in zip(self.panels, reg.get("panel_H", [])):
            if Hp is reg["H"]:
                continue
            part = self.warp_document(img_bgr, Hp, scale)
            sx0, sy0 = int(x0 * scale), int(y0 * scale)
            sx1, sy1 = int(x1 * scale), int(y1 * scale)
            out[sy0:sy1, sx0:sx1] = part[sy0:sy1, sx0:sx1]
        return out

    def _sane(self, H, w, h):
        """Bild-Ecken muessen ein konvexes, nicht entartetes Viereck ergeben."""
        corners = np.float32([[0, 0], [w, 0], [w, h], [0, h]]).reshape(-1, 1, 2)
        try:
            proj = cv2.perspectiveTransform(corners, H).reshape(-1, 2)
        except cv2.error:
            return False
        if not np.all(np.isfinite(proj)):
            return False
        area = cv2.contourArea(proj.astype(np.float32))
        if area < 0.05 * self.ref_w * self.ref_h or area > 50 * self.ref_w * self.ref_h:
            return False
        return cv2.isContourConvex(proj.astype(np.float32))

    def warp_document(self, img_bgr, H, scale: float = 1.0):
        """Ganzes Dokument in Referenzkoordinaten (fuer document_img / Median-Vorlage)."""
        S = np.diag([scale, scale, 1.0])
        return cv2.warpPerspective(img_bgr, S @ H, (int(self.ref_w * scale), int(self.ref_h * scale)),
                                   flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_CONSTANT, borderValue=(255, 255, 255))

    def warp_box(self, img_bgr, H, box, scale: float = 1.0, pad: int = 0):
        """Ausschnitt [x0,y0,x1,y1] (Referenzkoordinaten) direkt aus dem Originalfoto
        holen — schaerfer als Ausschneiden aus dem verkleinerten Gesamtbild."""
        x0, y0, x1, y1 = box
        x0 -= pad; y0 -= pad; x1 += pad; y1 += pad
        bw, bh = int(round((x1 - x0) * scale)), int(round((y1 - y0) * scale))
        if bw < 2 or bh < 2:
            return None
        # Referenz -> Ausschnitt: verschieben + skalieren
        T = np.array([[scale, 0, -x0 * scale], [0, scale, -y0 * scale], [0, 0, 1.0]])
        return cv2.warpPerspective(img_bgr, T @ H, (bw, bh), flags=cv2.INTER_CUBIC,
                                   borderMode=cv2.BORDER_REPLICATE)

    def panel_index(self, box):
        """Index des Panels, in dem der Boxmittelpunkt liegt (oder None)."""
        cx = (box[0] + box[2]) / 2; cy = (box[1] + box[3]) / 2
        for i, (x0, y0, x1, y1) in enumerate(self.panels):
            if x0 <= cx < x1 and y0 <= cy < y1:
                return i
        return None
