#!/usr/bin/env python3
"""
Kennzeichenerkennung Prototyp mit PaddleOCR.

Nutzung:
    # Einzelnes Bild testen:
    ./venv/bin/python detect_plate.py --image /pfad/zum/bild.jpg

    # Video oder RTSP-Stream testen:
    ./venv/bin/python detect_plate.py --video /pfad/zum/video.mp4
    ./venv/bin/python detect_plate.py --video rtsp://user:pass@192.168.1.100:554/stream

    # Webcam testen:
    ./venv/bin/python detect_plate.py --video 0
"""

import argparse
import json
import sys
import time
import re
import cv2
import numpy as np
from PIL import Image, ImageOps
from paddleocr import PaddleOCR


# --- Konfiguration -----------------------------------------------------------

# Deutsches Kennzeichen: 1-3 Buchstaben, Bindestrich, 1-2 Buchstaben, Leerzeichen, 1-4 Ziffern
# Beispiele: HH-AB 1234, B-XY 789, M-A 1, SHA-KM 42
# Flexibel: Bindestrich und Leerzeichen optional (OCR verliert sie oft)
PLATE_PATTERN = re.compile(
    r'^[A-ZÄÖÜ]{1,3}\s?[-–]?\s?[A-ZÄÖÜ]{1,2}\s?\d{1,4}\s?[EH]?$'
)

# Mindest-Confidence fuer eine gueltige Erkennung
MIN_CONFIDENCE = 0.60

# Fuer Richtungserkennung: Wie viele Frames zurueck vergleichen?
DIRECTION_HISTORY = 5

# Mindest-Groessenaenderung (Faktor) fuer Richtungsentscheidung
DIRECTION_THRESHOLD = 1.15  # 15% groesser = Einfahrt

# Maximaler horizontaler Abstand (px) um benachbarte Textbloecke zusammenzufuehren
MERGE_X_THRESHOLD = 50

# Maximaler vertikaler Abstand (px) um benachbarte Textbloecke zusammenzufuehren
MERGE_Y_THRESHOLD = 15


# --- Hilfsfunktionen ---------------------------------------------------------

def load_image_with_exif(image_path):
    """Bild laden und EXIF-Rotation automatisch korrigieren."""
    pil_img = Image.open(image_path)
    pil_img = ImageOps.exif_transpose(pil_img)
    # PIL (RGB) -> OpenCV (BGR)
    frame = cv2.cvtColor(np.array(pil_img), cv2.COLOR_RGB2BGR)
    return frame


def preprocess_frame(frame):
    """Bild fuer bessere Kennzeichenerkennung vorverarbeiten."""
    # Kontrast per CLAHE erhoehen (adaptive Histogramm-Equalisierung)
    lab = cv2.cvtColor(frame, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    l = clahe.apply(l)
    enhanced = cv2.merge([l, a, b])
    return cv2.cvtColor(enhanced, cv2.COLOR_LAB2BGR)


def normalize_plate(text):
    """Erkannten Text zu einem Kennzeichen normalisieren."""
    text = text.upper().strip()
    # Haeufige OCR-Fehler korrigieren
    text = text.replace('|', 'I')
    text = text.replace('§', 'S')
    text = text.replace('€', 'E')
    text = text.replace('.', '-')
    text = text.replace('·', '-')
    # Nur relevante Zeichen behalten
    text = re.sub(r'[^A-ZÄÖÜ0-9\- ]', '', text)
    text = text.strip()
    # Bindestrich einfuegen wenn keiner da: "MOLID115" -> "MOL-ID 115"
    if '-' not in text:
        # Ohne Leerzeichen: "MOLID115"
        m = re.match(r'^([A-ZÄÖÜ]{1,3})([A-ZÄÖÜ]{1,2})(\d{1,4}[EH]?)$', text)
        if m:
            text = f"{m.group(1)}-{m.group(2)} {m.group(3)}"
        # Mit Leerzeichen: "MOL HA 856"
        m = re.match(r'^([A-ZÄÖÜ]{1,3})\s+([A-ZÄÖÜ]{1,2})\s+(\d{1,4}[EH]?)$', text)
        if m:
            text = f"{m.group(1)}-{m.group(2)} {m.group(3)}"
    # Leerzeichen vor Ziffern einfuegen wenn keins da
    text = re.sub(r'([A-ZÄÖÜ])(\d)', r'\1 \2', text)
    # Im Zahlenteil O->0 korrigieren
    m = re.match(r'^([A-ZÄÖÜ]{1,3}\s?[-–]?\s?[A-ZÄÖÜ]{1,2}\s?)(.*)', text)
    if m:
        prefix = m.group(1)
        suffix = m.group(2).replace('O', '0')
        text = prefix + suffix
    return text


def is_german_plate(text):
    """Pruefen ob der Text wie ein deutsches Kennzeichen aussieht."""
    return PLATE_PATTERN.match(text) is not None


def box_center_y(box):
    """Vertikale Mitte einer Bounding Box."""
    return np.mean([p[1] for p in box])


def box_right_x(box):
    """Rechter Rand einer Bounding Box."""
    return max(p[0] for p in box)


def box_left_x(box):
    """Linker Rand einer Bounding Box."""
    return min(p[0] for p in box)


def merge_boxes(box1, box2):
    """Zwei Bounding Boxes zu einer zusammenfuehren."""
    all_pts = list(box1) + list(box2)
    xs = [p[0] for p in all_pts]
    ys = [p[1] for p in all_pts]
    return [
        [min(xs), min(ys)],
        [max(xs), min(ys)],
        [max(xs), max(ys)],
        [min(xs), max(ys)],
    ]


def merge_nearby_texts(lines):
    """
    Benachbarte Textbloecke auf gleicher Hoehe zusammenfuehren.
    PaddleOCR erkennt 'HH-AB' und '1234' oft als separate Bloecke.
    """
    if not lines:
        return lines

    # Nach x-Position sortieren
    sorted_lines = sorted(lines, key=lambda l: box_left_x(l[0]))
    merged = [sorted_lines[0]]

    for current in sorted_lines[1:]:
        last = merged[-1]
        last_box, last_text_info = last
        curr_box, curr_text_info = current

        # Pruefen ob auf gleicher Hoehe und nah beieinander
        y_diff = abs(box_center_y(last_box) - box_center_y(curr_box))
        x_gap = box_left_x(curr_box) - box_right_x(last_box)

        if y_diff < MERGE_Y_THRESHOLD and x_gap < MERGE_X_THRESHOLD:
            # Zusammenfuehren
            new_box = merge_boxes(last_box, curr_box)
            new_text = last_text_info[0] + ' ' + curr_text_info[0]
            new_conf = min(last_text_info[1], curr_text_info[1])
            merged[-1] = (new_box, (new_text, new_conf))
        else:
            merged.append(current)

    return merged


def box_area(box):
    """Flaeche einer Bounding Box berechnen (4 Punkte)."""
    pts = np.array(box)
    n = len(pts)
    area = 0.0
    for i in range(n):
        j = (i + 1) % n
        area += pts[i][0] * pts[j][1]
        area -= pts[j][0] * pts[i][1]
    return abs(area) / 2.0


def detect_direction(history):
    """
    Anhand der Groessenhistorie die Fahrtrichtung bestimmen.
    Returns: 'in', 'out', oder None (unklar).
    """
    if len(history) < 3:
        return None

    first_avg = np.mean(history[:2])
    last_avg = np.mean(history[-2:])

    if first_avg == 0:
        return None

    ratio = last_avg / first_avg

    if ratio >= DIRECTION_THRESHOLD:
        return 'in'
    elif ratio <= (1 / DIRECTION_THRESHOLD):
        return 'out'
    return None


def draw_results(frame, detections):
    """Erkannte Kennzeichen ins Bild zeichnen."""
    for det in detections:
        box = np.array(det['box']).astype(int)
        text = det['plate']
        conf = det['confidence']
        direction = det.get('direction', '')

        color = (0, 255, 0) if det.get('is_plate') else (0, 165, 255)
        cv2.polylines(frame, [box], True, color, 2)

        label = f"{text} ({conf:.0%})"
        if direction:
            label += f" [{direction}]"
        x, y = box[0]
        cv2.putText(frame, label, (x, max(y - 10, 20)),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.7, color, 2)

    return frame


# --- Hauptklasse -------------------------------------------------------------

class PlateRecognizer:
    def __init__(self):
        print("PaddleOCR wird geladen...")
        self.ocr = PaddleOCR(
            use_angle_cls=True,
            lang='en',
            show_log=False,
            use_gpu=False,
        )
        print("PaddleOCR bereit.\n")
        self.size_history = {}

    def recognize(self, frame):
        """
        Erkennt Kennzeichen in einem einzelnen Frame.
        Returns: Liste von Dicts mit 'plate', 'confidence', 'box', 'is_plate', 'direction'.
        """
        result = self.ocr.ocr(frame, cls=True)
        detections = []

        if not result or not result[0]:
            return detections

        # Benachbarte Textbloecke zusammenfuehren (z.B. 'HH-AB' + '1234')
        lines = [(line[0], line[1]) for line in result[0]]
        merged_lines = merge_nearby_texts(lines)

        for box, (text, conf) in merged_lines:
            if conf < MIN_CONFIDENCE:
                continue

            normalized = normalize_plate(text)
            is_plate = is_german_plate(normalized)

            det = {
                'plate': normalized,
                'confidence': conf,
                'box': box,
                'is_plate': is_plate,
                'raw_text': text,
                'direction': None,
            }

            if is_plate:
                area = box_area(box)
                # Kennzeichen-Key ohne Leerzeichen/Bindestrich fuer konsistentes Tracking
                track_key = re.sub(r'[\s\-]', '', normalized)
                if track_key not in self.size_history:
                    self.size_history[track_key] = []
                self.size_history[track_key].append(area)
                if len(self.size_history[track_key]) > DIRECTION_HISTORY:
                    self.size_history[track_key].pop(0)
                det['direction'] = detect_direction(self.size_history[track_key])

            detections.append(det)

        return detections


# --- Bild-Modus --------------------------------------------------------------

def process_image(image_path):
    """Ein einzelnes Bild verarbeiten."""
    print(f"Lade Bild: {image_path}")

    try:
        frame = load_image_with_exif(image_path)
    except Exception as e:
        print(f"FEHLER: Bild konnte nicht geladen werden: {image_path} ({e})")
        sys.exit(1)

    print(f"Bildgroesse: {frame.shape[1]}x{frame.shape[0]}px (EXIF-korrigiert)")
    enhanced = preprocess_frame(frame)

    recognizer = PlateRecognizer()
    # Erst mit Originalfarben, dann mit verbessertem Kontrast
    detections = recognizer.recognize(enhanced)
    if not any(d['is_plate'] for d in detections):
        print("Kein Treffer mit Kontrastverstärkung, versuche Original...")
        detections = recognizer.recognize(frame)

    print(f"\n{'='*60}")
    print(f"Ergebnis: {len(detections)} Textbereiche erkannt")
    print(f"{'='*60}\n")

    plates_found = False
    for det in detections:
        marker = ">>> KENNZEICHEN" if det['is_plate'] else "    Text"
        print(f"{marker}: {det['plate']}")
        print(f"    Confidence: {det['confidence']:.1%}")
        print(f"    Rohtext:    {det['raw_text']}")
        print(f"    Box-Flaeche: {box_area(det['box']):.0f}px²")
        print()
        if det['is_plate']:
            plates_found = True

    if not plates_found:
        print("Kein deutsches Kennzeichen erkannt.")
        print("Erkannte Texte (evtl. kein Kennzeichen-Format):")
        for det in detections:
            print(f"  - '{det['raw_text']}' (conf: {det['confidence']:.1%})")

    # Ergebnis-Bild speichern
    output_path = image_path.rsplit('.', 1)[0] + '_result.jpg'
    result_frame = draw_results(frame.copy(), detections)
    cv2.imwrite(output_path, result_frame)
    print(f"\nErgebnis-Bild gespeichert: {output_path}")


# --- Video/Stream-Modus ------------------------------------------------------

def process_video(source):
    """Video oder RTSP-Stream verarbeiten."""
    if source.isdigit():
        source = int(source)

    print(f"Oeffne Video-Quelle: {source}")
    cap = cv2.VideoCapture(source)

    if not cap.isOpened():
        print(f"FEHLER: Video-Quelle konnte nicht geoeffnet werden: {source}")
        sys.exit(1)

    fps = cap.get(cv2.CAP_PROP_FPS) or 30
    total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    is_live = total_frames <= 0  # RTSP/Webcam hat keinen Frame-Count

    # Bei Dateien: alle 0.5s einen Frame (basierend auf FPS, nicht Echtzeit)
    # Bei Live-Streams: Echtzeit-Interval
    frame_skip = max(1, int(fps * 0.5))  # Alle 0.5s Video-Zeit

    print(f"FPS: {fps:.1f}, Frames: {total_frames}, Skip: jeder {frame_skip}. Frame")

    recognizer = PlateRecognizer()
    frame_count = 0
    last_process_time = 0

    print("Verarbeite Stream... (Strg+C zum Beenden)\n")

    try:
        while True:
            ret, frame = cap.read()
            if not ret:
                print("Stream beendet.")
                break

            frame_count += 1

            # Frame-basiertes Skipping fuer Dateien, Zeit-basiert fuer Live
            if is_live:
                current_time = time.time()
                if current_time - last_process_time < 0.5:
                    continue
                last_process_time = current_time
            else:
                if frame_count % frame_skip != 0:
                    continue

            enhanced = preprocess_frame(frame)
            detections = recognizer.recognize(enhanced)
            # Fallback auf Original wenn nichts gefunden
            if not any(d['is_plate'] for d in detections):
                detections = recognizer.recognize(frame)

            plates = [d for d in detections if d['is_plate']]
            if plates:
                for p in plates:
                    direction_str = f" [{p['direction'].upper()}]" if p['direction'] else ""
                    area = box_area(p['box'])
                    print(f"[Frame {frame_count:5d}] {p['plate']} "
                          f"(conf: {p['confidence']:.1%}, "
                          f"flaeche: {area:.0f}px²"
                          f"{direction_str})")

            # Bild mit Annotationen anzeigen (wenn Display vorhanden)
            try:
                result_frame = draw_results(frame.copy(), detections)
                cv2.imshow('Kennzeichenerkennung', result_frame)
                if cv2.waitKey(1) & 0xFF == ord('q'):
                    break
            except cv2.error:
                pass  # Kein Display vorhanden (headless)

    except KeyboardInterrupt:
        print("\nAbgebrochen.")

    finally:
        cap.release()
        try:
            cv2.destroyAllWindows()
        except cv2.error:
            pass

    # Zusammenfassung
    print(f"\n{'='*60}")
    print("Erkannte Kennzeichen und Richtung:")
    for plate, history in recognizer.size_history.items():
        direction = detect_direction(history)
        direction_str = direction.upper() if direction else "UNKLAR"
        print(f"  {plate}: {direction_str} (Messungen: {len(history)})")


# --- Main ---------------------------------------------------------------------

def process_json(image_path, is_video=False):
    """Erkennung durchführen und Ergebnis als JSON ausgeben (für PHP-API)."""
    recognizer = PlateRecognizer()
    all_plates = []

    if is_video:
        cap = cv2.VideoCapture(image_path)
        if not cap.isOpened():
            print(json.dumps([]))
            return

        fps = cap.get(cv2.CAP_PROP_FPS) or 30
        frame_skip = max(1, int(fps * 0.5))
        frame_count = 0

        while True:
            ret, frame = cap.read()
            if not ret:
                break
            frame_count += 1
            if frame_count % frame_skip != 0:
                continue

            enhanced = preprocess_frame(frame)
            detections = recognizer.recognize(enhanced)
            if not any(d['is_plate'] for d in detections):
                detections = recognizer.recognize(frame)

            for d in detections:
                if d['is_plate']:
                    all_plates.append({
                        'plate': d['plate'],
                        'confidence': round(d['confidence'], 4),
                        'direction': d['direction'],
                        'is_plate': True,
                    })
        cap.release()
    else:
        try:
            frame = load_image_with_exif(image_path)
        except Exception:
            print(json.dumps([]))
            return

        enhanced = preprocess_frame(frame)
        detections = recognizer.recognize(enhanced)
        if not any(d['is_plate'] for d in detections):
            detections = recognizer.recognize(frame)

        for d in detections:
            all_plates.append({
                'plate': d['plate'],
                'confidence': round(d['confidence'], 4),
                'direction': d['direction'],
                'is_plate': d['is_plate'],
            })

    # Deduplizieren: Pro Kennzeichen das beste Ergebnis,
    # dabei Richtung aus dem letzten Eintrag übernehmen (Richtung steht erst nach 3+ Frames fest)
    seen = {}
    for p in all_plates:
        key = re.sub(r'[\s\-]', '', p['plate'])
        if key not in seen:
            seen[key] = p
        else:
            if p['confidence'] > seen[key]['confidence']:
                direction = seen[key]['direction'] or p['direction']
                seen[key] = p
                seen[key]['direction'] = direction
            if p['direction'] and not seen[key]['direction']:
                seen[key]['direction'] = p['direction']

    print(json.dumps(list(seen.values())))


def main():
    parser = argparse.ArgumentParser(
        description='Kennzeichenerkennung Prototyp mit PaddleOCR'
    )
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--image', '-i', help='Pfad zu einem Testbild')
    group.add_argument('--video', '-v',
                       help='Pfad zu Video, RTSP-URL, oder Webcam-Index (0)')
    parser.add_argument('--json', action='store_true',
                        help='Ergebnis als JSON ausgeben (für API)')

    args = parser.parse_args()

    if args.json:
        source = args.image or args.video
        is_video = args.video is not None
        process_json(source, is_video)
    elif args.image:
        process_image(args.image)
    else:
        process_video(args.video)


if __name__ == '__main__':
    main()
