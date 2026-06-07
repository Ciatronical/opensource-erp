#!/usr/bin/env python3
"""
Kamera-Monitor-Service fuer OpensourceERP.

Liest RTSP-Streams direkt, erkennt Bewegung und Objekte (YOLO/Coral/OpenVINO),
schreibt Events in die DB und benachrichtigt das ERP via pg_notify.
Live-Streams werden von go2rtc bereitgestellt (separater Dienst).

Konfiguration aus der Datenbank (settings.ini -> auth DB -> company DB).
Kameras, Zonen und Regeln aus den Tabellen camera, camera_zone, camera_rule.

Nutzung:
    python3 camera_monitor.py
    # oder mit venv:
    ./venv/bin/python camera_monitor.py

Benoetigt:
    pip install opencv-python-headless numpy psycopg2-binary ultralytics
"""

import base64
import configparser
import json
import os
import signal
import sys
import threading
import time
import uuid
from datetime import datetime, timedelta
from pathlib import Path

import cv2
import numpy as np
import psycopg2
import psycopg2.extras

# --- Pfade -------------------------------------------------------------------

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BACKEND_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', '..'))
SETTINGS_INI = os.path.join(BACKEND_DIR, 'config', 'settings.ini')
# Medien liegen ausserhalb des DocumentRoots (nicht in public/), damit sie
# NICHT ungeschuetzt per HTTP erreichbar sind. Auslieferung nur ueber den
# authentifizierten Endpoint backend/api/camera/media.php.
SNAPSHOT_DIR = os.path.join(BACKEND_DIR, 'data', 'camera-snapshots')
CLIP_DIR = os.path.join(BACKEND_DIR, 'data', 'camera-clips')

# RTSP ueber TCP (zuverlaessiger)
os.environ['OPENCV_FFMPEG_CAPTURE_OPTIONS'] = 'rtsp_transport;tcp'

# FFmpeg h264-Dekodierungswarnungen aus dem Log entfernen.
# OpenCV schreibt sie direkt auf C-Level fd 2 (stderr) — Python-Logging kann
# das nicht abfangen. Wir leiten fd 2 nach /dev/null um, sichern aber vorher
# Python-Exceptions auf stdout (damit sie im Journal sichtbar bleiben).
import sys as _sys
_sys.stderr = _sys.stdout          # Python-Tracebacks → stdout → Journal
_devnull = os.open(os.devnull, os.O_WRONLY)
os.dup2(_devnull, 2)               # C-Level fd 2 → /dev/null
os.close(_devnull)
del _sys, _devnull

# --- Globaler Zustand --------------------------------------------------------

running = True
detector = None  # YOLO-Modell (lazy init)
DETECTOR_LOCK = threading.Lock()

# YOLO-Labels die wir erkennen wollen (COCO-Klassen)
DETECT_LABELS = {
    0: 'person', 1: 'bicycle', 2: 'car', 3: 'motorcycle',
    5: 'bus', 7: 'truck', 14: 'bird', 15: 'cat', 16: 'dog', 17: 'horse',
}

# --- Konfiguration -----------------------------------------------------------

# Wie oft Konfig aus der DB neu geladen wird (Sekunden)
CONFIG_RELOAD_INTERVAL = 60

# Mindest-Score fuer Objekterkennung
DEFAULT_MIN_SCORE = 0.45

# Frames pro Sekunde die analysiert werden (mehr = CPU-Last)
ANALYSIS_FPS = 2

# Bewegungsschwelle (Prozent der Pixel die sich geaendert haben muessen)
MOTION_THRESHOLD_PERCENT = 1.5

# Minimale Objektgroesse in Pixeln
MIN_OBJECT_AREA = 2000

# Video-Aufnahme: Sekunden vor und nach Bewegung
PRE_RECORD_SECONDS = 3
POST_RECORD_SECONDS = 5

# Maximale Clip-Laenge (Sekunden)
MAX_CLIP_DURATION = 120

# Clip-Format
CLIP_CODEC = 'mp4v'
CLIP_EXT = '.mp4'


# --- DB-Verbindung aus settings.ini ------------------------------------------

def decrypt_password(encrypted_str):
    """Entschluesselt das Passwort aus settings.ini (XOR mit 'k', Base64)."""
    key = ord('k')
    decoded = base64.b64decode(encrypted_str)
    return bytes([b ^ key for b in decoded]).decode('utf-8')


def read_settings_ini():
    """Liest die DB-Verbindungsdaten aus settings.ini."""
    if not os.path.exists(SETTINGS_INI):
        print(f"FEHLER: settings.ini nicht gefunden: {SETTINGS_INI}")
        sys.exit(1)
    config = configparser.ConfigParser()
    config.read(SETTINGS_INI)
    return {
        'host': config.get('database', 'host').strip('"'),
        'port': int(config.get('database', 'port').strip('"')),
        'dbname': config.get('database', 'auth_db').strip('"'),
        'user': config.get('database', 'auth_user').strip('"'),
        'password': decrypt_password(config.get('database', 'auth_pass').strip('"')),
    }


def get_company_databases(auth_conn):
    """Laedt alle Company-DB-Verbindungen aus auth.clients."""
    with auth_conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
        cur.execute(
            "SELECT id, name, dbhost, dbport, dbname, dbuser, dbpasswd "
            "FROM auth.clients ORDER BY id"
        )
        return [dict(row) for row in cur.fetchall()]


def connect_company_db(client):
    """Verbindet sich mit einer Company-DB."""
    return psycopg2.connect(
        host=client['dbhost'] or 'localhost',
        port=int(client['dbport'] or 5432),
        database=client['dbname'],
        user=client['dbuser'],
        password=client['dbpasswd'],
    )


def is_camera_enabled(company_conn):
    """Prueft ob Kamera-Feature fuer diese Company aktiviert ist."""
    with company_conn.cursor() as cur:
        # Pruefe ob Tabelle existiert
        cur.execute(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables "
            "WHERE table_name = 'camera')"
        )
        if not cur.fetchone()[0]:
            return False
        cur.execute(
            "SELECT value FROM defaults_oserp WHERE key = 'features'"
        )
        row = cur.fetchone()
        if not row:
            return False
        return 'camera' in row[0]


# --- Snapshot-/Clip-Verwaltung -----------------------------------------------

def ensure_snapshot_dir():
    """Snapshot-Verzeichnis erstellen."""
    Path(SNAPSHOT_DIR).mkdir(parents=True, exist_ok=True)


def ensure_clip_dir():
    """Clip-Verzeichnis erstellen."""
    Path(CLIP_DIR).mkdir(parents=True, exist_ok=True)


def _is_frame_corrupt(frame):
    """
    Einfache Korruptionspruefung: ein stark beschaedigter H264-Frame
    zeigt oft blockweise identische Bereiche (Freeze-Frame-Effekt) oder
    extrem viele einfarbige 8x8-Bloecke. Wir prufen die Varianz.
    """
    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    # Laplacian-Varianz: sehr niedriger Wert = unschaerfer/artefaktreicher Frame
    var = cv2.Laplacian(gray, cv2.CV_64F).var()
    return var < 5.0  # Schwellwert; echte Bilder haben typisch > 50


def save_snapshot(frame, event_id):
    """Frame als JPEG speichern (max 1280px breit). Gibt URL-Pfad zurueck."""
    ensure_snapshot_dir()
    filename = f"{event_id}.jpg"
    filepath = os.path.join(SNAPSHOT_DIR, filename)
    h, w = frame.shape[:2]
    if w > 1280:
        scale = 1280 / w
        frame = cv2.resize(frame, (1280, int(h * scale)), interpolation=cv2.INTER_AREA)
    cv2.imwrite(filepath, frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
    return f"/camera-snapshots/{filename}"


class RingBuffer:
    """
    Ring-Buffer fuer Pre-Recording.
    Speichert die letzten N Sekunden an Frames im Speicher,
    damit bei Bewegungsbeginn die Aufnahme rueckwirkend starten kann.
    """

    def __init__(self, max_seconds=PRE_RECORD_SECONDS, fps=ANALYSIS_FPS):
        self.max_frames = max(1, int(max_seconds * fps))
        self.frames = []

    def add(self, frame):
        """Frame hinzufuegen (aelteste werden verworfen)."""
        self.frames.append(frame.copy())
        if len(self.frames) > self.max_frames:
            self.frames.pop(0)

    def get_all(self):
        """Alle gespeicherten Frames zurueckgeben."""
        return list(self.frames)

    def clear(self):
        self.frames.clear()


class ClipRecorder:
    """
    Nimmt einen Video-Clip auf solange Bewegung erkannt wird.
    Schreibt Pre-Buffer + Live-Frames + Post-Buffer in eine MP4-Datei.
    """

    def __init__(self, event_id, frame_size, fps=ANALYSIS_FPS, post_record_seconds=POST_RECORD_SECONDS):
        self.event_id = event_id
        self.fps = fps
        self.frame_size = frame_size  # (width, height)
        self.writer = None
        self.filepath = None
        self.url_path = None
        self.frame_count = 0
        self.max_frames = int(MAX_CLIP_DURATION * fps)
        self.post_motion_frames = 0
        self.post_motion_limit = int(post_record_seconds * fps)
        self.motion_ended = False
        self.finished = False

        self._init_writer()

    def _init_writer(self):
        ensure_clip_dir()
        filename = f"{self.event_id}{CLIP_EXT}"
        self.filepath = os.path.join(CLIP_DIR, filename)
        self.url_path = f"/camera-clips/{filename}"
        fourcc = cv2.VideoWriter_fourcc(*CLIP_CODEC)
        self.writer = cv2.VideoWriter(
            self.filepath, fourcc, self.fps,
            self.frame_size
        )

    def write_frame(self, frame):
        """Frame in den Clip schreiben. Gibt False zurueck wenn fertig."""
        if self.finished or self.writer is None:
            return False

        # Frame auf Zielgroesse bringen (falls noetig)
        h, w = frame.shape[:2]
        if (w, h) != self.frame_size:
            frame = cv2.resize(frame, self.frame_size)

        self.writer.write(frame)
        self.frame_count += 1

        # Max-Laenge erreicht?
        if self.frame_count >= self.max_frames:
            self.finish()
            return False

        # Post-Motion: noch einige Frames nach Bewegungsende aufnehmen
        if self.motion_ended:
            self.post_motion_frames += 1
            if self.post_motion_frames >= self.post_motion_limit:
                self.finish()
                return False

        return True

    def write_pre_buffer(self, frames):
        """Frames aus dem Pre-Record-Buffer schreiben."""
        for frame in frames:
            self.write_frame(frame)

    def signal_motion_end(self):
        """Bewegung ist vorbei — noch Post-Buffer aufnehmen, dann beenden."""
        if not self.motion_ended:
            self.motion_ended = True
            self.post_motion_frames = 0

    def signal_motion_resume(self):
        """Bewegung wieder erkannt — Post-Buffer abbrechen."""
        self.motion_ended = False
        self.post_motion_frames = 0

    def finish(self):
        """Aufnahme beenden, dann per ffmpeg zu browser-kompatiblem H.264 konvertieren."""
        if self.finished:
            return
        self.finished = True
        if self.writer:
            self.writer.release()
            self.writer = None
        duration = self.frame_count / max(1, self.fps)
        print(f"[CLIP] {self.event_id}: {duration:.1f}s ({self.frame_count} Frames) -> {self.filepath}")
        if self.frame_count > 0:
            threading.Thread(target=self._convert_h264, daemon=True).start()

    def _convert_h264(self):
        """mp4v -> H.264 (browser-kompatibel, moov atom vorne für Streaming)."""
        import subprocess, shutil
        if not shutil.which('ffmpeg'):
            return
        tmp = self.filepath + '.h264.mp4'
        try:
            result = subprocess.run([
                'ffmpeg', '-y', '-i', self.filepath,
                '-c:v', 'libx264', '-preset', 'fast', '-crf', '23',
                '-pix_fmt', 'yuv420p',   # Kompatibel mit allen Browsern
                '-movflags', '+faststart',
                tmp,
            ], capture_output=True, timeout=120)
            if result.returncode == 0 and os.path.getsize(tmp) > 0:
                os.replace(tmp, self.filepath)
            else:
                if os.path.exists(tmp):
                    os.unlink(tmp)
        except Exception as e:
            print(f"[CLIP] ffmpeg-Konvertierung fehlgeschlagen: {e}")
            if os.path.exists(tmp):
                os.unlink(tmp)

    @property
    def clip_url(self):
        return self.url_path if self.frame_count > 0 else None


# --- Objekterkennung mit automatischer Hardware-Erkennung --------------------
#
# Kaskade:
#   1. Google Coral USB TPU   (~100 Inferenzen/s, ~30€ USB-Stick)
#   2. Intel iGPU / OpenVINO  (~40 Inferenzen/s, kostenlos, in jedem Intel-Chip)
#   3. CPU-Fallback            (~10 Inferenzen/s)
#
# Keine Konfiguration noetig — wird automatisch erkannt.

# Erkannter Backend-Typ (wird beim ersten Aufruf gesetzt)
_detection_backend = None  # 'coral', 'openvino', 'yolo_cpu', False

# Coral-spezifisch
_coral_interpreter = None
_coral_labels = None


def _is_coral_usb_connected():
    """
    Prueft ob ein Google Coral USB physisch eingesteckt ist (per lsusb).
    Funktioniert OHNE installiertes pycoral.
    Coral USB Vendor:Product IDs: 1a6e:089a (Global Unichip) oder 18d1:9302 (Google)
    """
    try:
        import subprocess
        result = subprocess.run(['lsusb'], capture_output=True, text=True, timeout=5)
        for line in result.stdout.splitlines():
            if '1a6e:089a' in line or '18d1:9302' in line or 'Global Unichip' in line or 'Google Inc.' in line:
                return True
    except Exception:
        pass
    return False


def _detect_coral_available():
    """Prueft ob ein Google Coral USB Accelerator angeschlossen und nutzbar ist."""
    coral_plugged_in = _is_coral_usb_connected()

    try:
        from pycoral.utils.edgetpu import list_edge_tpus
        devices = list_edge_tpus()
        if devices:
            print(f"[HW] Google Coral erkannt: {len(devices)} TPU(s)")
            return True
    except ImportError:
        if coral_plugged_in:
            print("[HW] ⚠ Google Coral USB erkannt, aber pycoral ist NICHT installiert!")
            print("[HW]   → pip install pycoral tflite-runtime")
            print("[HW]   Falle zurueck auf naechstes Backend...")
        return False
    except Exception:
        pass

    if coral_plugged_in:
        print("[HW] ⚠ Google Coral USB erkannt, aber konnte nicht initialisiert werden.")
        print("[HW]   → pip install pycoral tflite-runtime")

    return False


def _detect_openvino_available():
    """Prueft ob OpenVINO installiert und eine Intel-GPU verfuegbar ist."""
    try:
        import openvino
        print(f"[HW] OpenVINO {openvino.__version__} erkannt")
        return True
    except ImportError:
        pass
    return False


def _init_coral():
    """Google Coral TPU initialisieren."""
    global _coral_interpreter, _coral_labels

    from pycoral.utils.edgetpu import make_interpreter
    from pycoral.utils.dataset import read_label_file

    # Coral braucht ein TFLite-Modell (nicht das YOLO .pt Format)
    # EfficientDet-Lite oder SSD MobileNet — vorcompiliert fuer Coral
    model_path = os.path.join(SCRIPT_DIR, 'ssd_mobilenet_v2_coco_quant_postprocess_edgetpu.tflite')
    labels_path = os.path.join(SCRIPT_DIR, 'coco_labels.txt')

    if not os.path.exists(model_path):
        print("[CORAL] Modell nicht gefunden — lade herunter...")
        _download_coral_model(model_path, labels_path)

    _coral_interpreter = make_interpreter(model_path)
    _coral_interpreter.allocate_tensors()

    if os.path.exists(labels_path):
        _coral_labels = read_label_file(labels_path)
    else:
        _coral_labels = {}

    print("[CORAL] Modell geladen, TPU bereit.")


def _download_coral_model(model_path, labels_path):
    """Coral-Modell und Labels herunterladen (einmalig, ~4 MB)."""
    import urllib.request

    model_url = (
        'https://github.com/google-coral/test_data/raw/master/'
        'ssd_mobilenet_v2_coco_quant_postprocess_edgetpu.tflite'
    )
    labels_url = (
        'https://github.com/google-coral/test_data/raw/master/'
        'coco_labels.txt'
    )

    print(f"[CORAL] Lade Modell: {model_url}")
    urllib.request.urlretrieve(model_url, model_path)
    print(f"[CORAL] Lade Labels: {labels_url}")
    urllib.request.urlretrieve(labels_url, labels_path)
    print("[CORAL] Download abgeschlossen.")


def _detect_with_coral(frame, min_score=DEFAULT_MIN_SCORE):
    """Objekterkennung mit Google Coral TPU."""
    from pycoral.adapters.detect import get_objects
    from pycoral.adapters.common import input_size

    # Input-Groesse des Modells
    inf_w, inf_h = input_size(_coral_interpreter)
    frame_h, frame_w = frame.shape[:2]

    # Frame auf Modell-Groesse skalieren
    resized = cv2.resize(frame, (inf_w, inf_h))
    # BGR -> RGB
    rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB)

    # Inferenz
    from pycoral.adapters.common import set_input
    set_input(_coral_interpreter, rgb)
    _coral_interpreter.invoke()

    objects = get_objects(_coral_interpreter, score_threshold=min_score)

    # COCO-Labels auf unsere Labels mappen
    coco_to_label = {
        0: 'person', 1: 'bicycle', 2: 'car', 3: 'motorcycle',
        5: 'bus', 7: 'truck', 14: 'bird', 15: 'cat', 16: 'dog', 17: 'horse',
    }

    detections = []
    for obj in objects:
        label = coco_to_label.get(obj.id)
        if label is None:
            # Auch Label-Name pruefen (Coral liefert manchmal Text-Labels)
            name = _coral_labels.get(obj.id, '').lower()
            for cid, lbl in coco_to_label.items():
                if lbl == name:
                    label = lbl
                    break
            if label is None:
                continue

        score = float(obj.score)

        # Bounding-Box auf Original-Frame-Groesse umrechnen
        bbox = obj.bbox
        x1 = int(bbox.xmin * frame_w / inf_w)
        y1 = int(bbox.ymin * frame_h / inf_h)
        x2 = int(bbox.xmax * frame_w / inf_w)
        y2 = int(bbox.ymax * frame_h / inf_h)

        area = (x2 - x1) * (y2 - y1)
        if area < MIN_OBJECT_AREA:
            continue

        detections.append({
            'label': label,
            'score': round(score, 3),
            'box': (x1, y1, x2, y2),
        })

    return detections


def _init_yolo(use_openvino=False):
    """YOLO-Modell initialisieren (OpenVINO oder CPU)."""
    global detector

    from ultralytics import YOLO

    if use_openvino:
        # OpenVINO: YOLO exportiert automatisch ins OpenVINO-Format
        model_path = os.path.join(SCRIPT_DIR, 'yolov8n.pt')
        openvino_path = os.path.join(SCRIPT_DIR, 'yolov8n_openvino_model')

        if not os.path.exists(model_path):
            print("[YOLO] Lade yolov8n.pt (erstes Mal, ~6 MB)...")
            model = YOLO('yolov8n.pt')
        else:
            model = YOLO(model_path)

        if not os.path.exists(openvino_path):
            print("[OPENVINO] Exportiere YOLO -> OpenVINO (einmalig)...")
            model.export(format='openvino')

        detector = YOLO(openvino_path)
        print("[OPENVINO] YOLO auf Intel iGPU geladen.")
    else:
        model_path = os.path.join(SCRIPT_DIR, 'yolov8n.pt')
        if not os.path.exists(model_path):
            print("[YOLO] Lade yolov8n.pt (erstes Mal, ~6 MB)...")
            detector = YOLO('yolov8n.pt')
        else:
            detector = YOLO(model_path)
        print("[YOLO] Modell geladen (CPU-Modus).")

    # Warmup: einmal mit Dummy-Frame aufrufen damit PyTorch alle internen
    # Strukturen initialisiert bevor Worker-Threads parallel darauf zugreifen.
    _dummy = np.zeros((480, 640, 3), dtype=np.uint8)
    detector(_dummy, verbose=False)
    print("[YOLO] Warmup abgeschlossen.")


def _detect_with_yolo(frame, min_score=DEFAULT_MIN_SCORE):
    """Objekterkennung mit YOLO (OpenVINO oder CPU)."""
    results = detector(frame, verbose=False, conf=min_score)
    detections = []
    for r in results:
        for box in r.boxes:
            cls_id = int(box.cls[0])
            label = DETECT_LABELS.get(cls_id)
            if label is None:
                continue
            score = float(box.conf[0])
            x1, y1, x2, y2 = box.xyxy[0].tolist()
            area = (x2 - x1) * (y2 - y1)
            if area < MIN_OBJECT_AREA:
                continue
            detections.append({
                'label': label,
                'score': round(score, 3),
                'box': (int(x1), int(y1), int(x2), int(y2)),
            })
    return detections


def _init_detection_backend():
    """
    Automatische Hardware-Erkennung und Initialisierung.
    Wird einmal beim ersten detect_objects()-Aufruf ausgefuehrt.
    """
    global _detection_backend

    print("[HW] Suche beste verfuegbare KI-Hardware...")

    # 1. Google Coral USB TPU
    if _detect_coral_available():
        try:
            _init_coral()
            _detection_backend = 'coral'
            print("[HW] ✓ Backend: Google Coral USB TPU (~100 Inf./s)")
            return
        except Exception as e:
            print(f"[HW] Coral-Init fehlgeschlagen: {e}")

    # 2. Intel OpenVINO (iGPU)
    if _detect_openvino_available():
        try:
            _init_yolo(use_openvino=True)
            _detection_backend = 'openvino'
            print("[HW] ✓ Backend: Intel iGPU via OpenVINO (~40 Inf./s)")
            return
        except Exception as e:
            print(f"[HW] OpenVINO-Init fehlgeschlagen: {e}")

    # 3. CPU-Fallback (YOLO direkt)
    try:
        _init_yolo(use_openvino=False)
        _detection_backend = 'yolo_cpu'
        print("[HW] ✓ Backend: CPU (~10 Inf./s)")
        return
    except ImportError:
        print("[HW] ultralytics nicht installiert.")
    except Exception as e:
        print(f"[HW] YOLO-Init fehlgeschlagen: {e}")

    _detection_backend = False
    print("[HW] ✗ Kein KI-Backend verfuegbar — nur Bewegungserkennung aktiv.")


def detect_objects(frame, min_score=DEFAULT_MIN_SCORE):
    """
    Objekte im Frame erkennen.
    Waehlt automatisch das beste verfuegbare Backend.
    Gibt Liste von dicts zurueck: [{'label': 'person', 'score': 0.87, 'box': (x1,y1,x2,y2)}, ...]
    """
    global _detection_backend

    # Lazy-Init (einmalig, Thread-safe)
    if _detection_backend is None:
        with DETECTOR_LOCK:
            if _detection_backend is None:
                _init_detection_backend()

    if _detection_backend == 'coral':
        return _detect_with_coral(frame, min_score)
    elif _detection_backend in ('openvino', 'yolo_cpu'):
        return _detect_with_yolo(frame, min_score)
    else:
        return []


# --- Bewegungserkennung -------------------------------------------------------

class MotionDetector:
    """
    Einfache Bewegungserkennung per Frame-Differenz.
    Arbeitet auf verkleinertem Graustufenbild fuer Performance.
    """

    def __init__(self, threshold_percent=MOTION_THRESHOLD_PERCENT):
        self.prev_gray = None
        self.threshold_percent = threshold_percent

    def detect(self, frame):
        """
        Gibt True zurueck wenn Bewegung erkannt wurde.
        Gibt zusaetzlich die Bounding-Boxes der Bewegungsbereiche zurueck.
        """
        small = cv2.resize(frame, (320, 240))
        gray = cv2.cvtColor(small, cv2.COLOR_BGR2GRAY)
        gray = cv2.GaussianBlur(gray, (21, 21), 0)

        if self.prev_gray is None:
            self.prev_gray = gray
            return False, []

        delta = cv2.absdiff(self.prev_gray, gray)
        self.prev_gray = gray

        thresh = cv2.threshold(delta, 25, 255, cv2.THRESH_BINARY)[1]
        thresh = cv2.dilate(thresh, None, iterations=2)

        total_pixels = thresh.shape[0] * thresh.shape[1]
        motion_pixels = cv2.countNonZero(thresh)
        motion_percent = (motion_pixels / total_pixels) * 100

        if motion_percent < self.threshold_percent:
            return False, []

        contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        boxes = []
        scale_x = frame.shape[1] / 320
        scale_y = frame.shape[0] / 240
        for c in contours:
            if cv2.contourArea(c) < 200:
                continue
            x, y, w, h = cv2.boundingRect(c)
            boxes.append((
                int(x * scale_x), int(y * scale_y),
                int((x + w) * scale_x), int((y + h) * scale_y)
            ))
        return True, boxes


# --- Zonen-Pruefung ----------------------------------------------------------

def point_in_polygon(px, py, polygon):
    """Punkt-in-Polygon Test (Ray-Casting)."""
    n = len(polygon)
    inside = False
    j = n - 1
    for i in range(n):
        xi, yi = polygon[i]
        xj, yj = polygon[j]
        if ((yi > py) != (yj > py)) and (px < (xj - xi) * (py - yi) / (yj - yi) + xi):
            inside = not inside
        j = i
    return inside


def box_center(box):
    """Mittelpunkt einer Bounding-Box."""
    x1, y1, x2, y2 = box
    return ((x1 + x2) / 2, (y1 + y2) / 2)


def detection_in_zone(detection_box, zone_coords, frame_w, frame_h):
    """
    Prueft ob der Mittelpunkt einer Erkennung in der Zone liegt.
    Zone-Koordinaten sind normalisiert (0.0-1.0).
    """
    cx, cy = box_center(detection_box)
    # Normalisieren
    nx, ny = cx / frame_w, cy / frame_h
    return point_in_polygon(nx, ny, zone_coords)


# --- Kamera-Worker -----------------------------------------------------------

class CameraWorker(threading.Thread):
    """
    Thread pro Kamera: liest RTSP-Stream, erkennt Bewegung + Objekte,
    nimmt Video-Clips auf, schreibt Events in die DB.

    Aufnahme-Logik:
    1. Ring-Buffer speichert staendig die letzten PRE_RECORD_SECONDS Frames
    2. Bei Bewegung + Objekterkennung: neues Event + Clip-Aufnahme starten
       → Pre-Buffer wird in den Clip geschrieben (rueckwirkend)
       → Solange Bewegung erkannt wird: weiter aufnehmen
    3. Bewegung endet: noch POST_RECORD_SECONDS weiter aufnehmen, dann Clip beenden
    4. Clip-URL wird in der DB gespeichert
    """

    def __init__(self, camera_config, company_conn_params, zones, rules):
        super().__init__(daemon=True)
        self.cam = camera_config
        self.conn_params = company_conn_params
        self.zones = zones
        self.rules = rules
        self.name = f"cam-{self.cam['cam_key']}"
        self.motion_threshold  = float(self.cam.get('motion_threshold') or MOTION_THRESHOLD_PERCENT)
        self.cam_min_score     = float(self.cam.get('min_score') or DEFAULT_MIN_SCORE)
        self.record_clips      = bool(self.cam.get('record_clips', True))
        self.cam_fps           = int(self.cam.get('analysis_fps') or ANALYSIS_FPS)
        self.pre_record_secs   = int(self.cam.get('pre_record_secs') or PRE_RECORD_SECONDS)
        self.post_record_secs  = int(self.cam.get('post_record_secs') or POST_RECORD_SECONDS)
        self.motion = MotionDetector(self.motion_threshold)
        self.ring_buffer = RingBuffer(max_seconds=self.pre_record_secs, fps=self.cam_fps)
        self._db_conn = None  # Persistente DB-Verbindung
        self.record_fps = 15.0  # Wird nach Stream-Connect aktualisiert
        self.active_events = {}   # label -> {event_id, started_at, last_seen, recorder, ...}
        self.rule_cooldowns = {}  # rule_id -> last_triggered datetime
        self.frame_size = None    # (width, height) — wird beim ersten Frame gesetzt

    def run(self):
        cam_name = self.cam['cam_key']
        stream_url = self.cam.get('stream_url', '')

        if not stream_url:
            print(f"[{cam_name}] Keine Stream-URL konfiguriert — ueberspringe.")
            return

        print(f"[{cam_name}] Starte Stream: {stream_url}")
        frame_interval = 1.0 / self.cam_fps
        consecutive_errors = 0

        while running:
            cap = cv2.VideoCapture(stream_url, cv2.CAP_FFMPEG)
            if not cap.isOpened():
                consecutive_errors += 1
                wait = min(30, 5 * consecutive_errors)
                print(f"[{cam_name}] Stream nicht erreichbar — Retry in {wait}s")
                time.sleep(wait)
                continue

            consecutive_errors = 0

            # Echte Kamera-FPS ermitteln (fuer korrekten VideoWriter)
            cam_real_fps = cap.get(cv2.CAP_PROP_FPS)
            if not (5 <= cam_real_fps <= 60):
                cam_real_fps = 15.0
            self.record_fps = cam_real_fps

            # Ring-Buffer mit echter FPS neu initialisieren
            self.ring_buffer = RingBuffer(max_seconds=self.pre_record_secs, fps=cam_real_fps)

            print(f"[{cam_name}] Stream verbunden. ({cam_real_fps:.0f} fps)")

            last_analysis = 0
            frame_count = 0

            while running and cap.isOpened():
                ret, frame = cap.read()
                if not ret:
                    print(f"[{cam_name}] Frame-Fehler — reconnect...")
                    break

                frame_count += 1

                # Frame-Groesse beim ersten Frame merken
                if self.frame_size is None:
                    h, w = frame.shape[:2]
                    self.frame_size = (w, h)

                # Immer in Ring-Buffer schreiben (fuer Pre-Recording)
                self.ring_buffer.add(frame)

                # Laufende Aufnahmen mit echtem FPS fuettern
                for ev in self.active_events.values():
                    recorder = ev.get('recorder')
                    if recorder and not recorder.finished:
                        recorder.write_frame(frame)

                now = time.time()
                if now - last_analysis < frame_interval:
                    continue
                last_analysis = now

                try:
                    self._analyze_frame(frame, cam_name)
                except Exception as e:
                    print(f"[{cam_name}] Analyse-Fehler: {e}")

            # Stream unterbrochen: alle aktiven Recorder beenden
            self._finish_all_recorders()
            cap.release()
            if running:
                time.sleep(2)

    def _analyze_frame(self, frame, cam_name):
        """Frame analysieren: Bewegung -> Objekterkennung -> Events + Aufnahme."""
        has_motion, motion_boxes = self.motion.detect(frame)

        if not has_motion:
            # Bewegung vorbei: aktive Recorder in Post-Motion-Phase setzen
            for ev in self.active_events.values():
                recorder = ev.get('recorder')
                if recorder and not recorder.finished:
                    recorder.signal_motion_end()
            # Abgelaufene Events beenden
            self._end_stale_events()
            return

        # Objekterkennung (nur wenn Bewegung)
        detections = detect_objects(frame, self.cam_min_score)

        # Auch ohne Objekterkennung: Bewegung in laufende Recorder melden
        for ev in self.active_events.values():
            recorder = ev.get('recorder')
            if recorder and not recorder.finished and recorder.motion_ended:
                recorder.signal_motion_resume()

        if not detections:
            return

        frame_h, frame_w = frame.shape[:2]

        for det in detections:
            label = det['label']
            score = det['score']
            box = det['box']

            # Zonen pruefen
            matched_zones = []
            for zone in self.zones:
                if zone.get('coordinates') and detection_in_zone(box, zone['coordinates'], frame_w, frame_h):
                    matched_zones.append(zone['zone_key'])

            event_key = f"{label}"
            now = datetime.now()

            if event_key in self.active_events:
                # Bestehendes Event aktualisieren
                ev = self.active_events[event_key]
                ev['last_seen'] = now
                ev['score'] = max(ev['score'], score)
                ev['zones'] = list(set(ev['zones'] + matched_zones))
                self._update_event(ev)

                # Recorder: Bewegung laeuft weiter
                recorder = ev.get('recorder')
                if recorder and not recorder.finished and recorder.motion_ended:
                    recorder.signal_motion_resume()

            else:
                # Neues Event + Aufnahme starten
                event_id = str(uuid.uuid4())[:12]
                # Korrupten Frame vermeiden: letzten sauberen Frame aus Ring-Buffer nehmen
                snap_frame = frame
                if _is_frame_corrupt(frame):
                    clean = [f for f in reversed(self.ring_buffer.get_all()) if not _is_frame_corrupt(f)]
                    if clean:
                        snap_frame = clean[0]
                snapshot_url = save_snapshot(snap_frame, event_id)

                # Clip-Recorder starten mit Pre-Buffer (nur wenn record_clips aktiv)
                recorder = None
                if self.record_clips and self.frame_size:
                    recorder = ClipRecorder(event_id, self.frame_size, self.record_fps,
                                            post_record_seconds=self.post_record_secs)
                    pre_frames = self.ring_buffer.get_all()
                    recorder.write_pre_buffer(pre_frames)

                ev = {
                    'event_id': event_id,
                    'camera_id': self.cam.get('id'),
                    'camera_name': cam_name,
                    'label': label,
                    'zones': matched_zones,
                    'score': score,
                    'snapshot_url': snapshot_url,
                    'clip_url': '',
                    'started_at': now,
                    'last_seen': now,
                    'recorder': recorder,
                }
                self.active_events[event_key] = ev
                self._insert_event(ev)
                self._check_rules(ev)

    def _db(self):
        """Persistente DB-Verbindung — reconnect bei Bedarf."""
        try:
            if self._db_conn is None or self._db_conn.closed:
                self._db_conn = psycopg2.connect(**self.conn_params)
                self._db_conn.autocommit = True
        except Exception as e:
            print(f"[{self.name}] DB-Reconnect Fehler: {e}")
            self._db_conn = None
        return self._db_conn

    def _insert_event(self, ev):
        """Neues Event in die DB schreiben + pg_notify."""
        conn = self._db()
        if not conn:
            return
        try:
            with conn.cursor() as cur:
                cur.execute("""
                    INSERT INTO camera_event
                        (event_id, camera_id, camera_name, label, zones, score,
                         snapshot_url, started_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT (event_id) DO NOTHING
                """, (
                    ev['event_id'], ev['camera_id'], ev['camera_name'],
                    ev['label'], ev['zones'], ev['score'],
                    ev['snapshot_url'], ev['started_at'],
                ))
                payload = json.dumps({
                    'type': 'camera_event',
                    'event': 'new',
                    'camera': ev['camera_name'],
                    'label': ev['label'],
                    'zones': ev['zones'],
                    'score': ev['score'],
                    'event_id': ev['event_id'],
                })
                cur.execute("SELECT pg_notify('camera_event', %s)", (payload,))
            print(f"[{ev['camera_name']}] Event: {ev['label']} ({ev['score']:.0%})")
        except Exception as e:
            print(f"[{ev['camera_name']}] DB-Insert Fehler: {e}")
            self._db_conn = None

    def _update_event(self, ev):
        """Event in der DB aktualisieren (Score, Zonen)."""
        conn = self._db()
        if not conn:
            return
        try:
            with conn.cursor() as cur:
                cur.execute("""
                    UPDATE camera_event SET
                        score = GREATEST(score, %s),
                        zones = %s
                    WHERE event_id = %s
                """, (ev['score'], ev['zones'], ev['event_id']))
        except Exception as e:
            print(f"[{ev['camera_name']}] DB-Update Fehler: {e}")
            self._db_conn = None

    def _end_stale_events(self):
        """Events beenden die > 10s nicht mehr gesehen wurden."""
        now = datetime.now()
        stale_keys = []
        for key, ev in self.active_events.items():
            recorder = ev.get('recorder')

            # Recorder fertig? → Event beenden
            if recorder and recorder.finished:
                stale_keys.append(key)
                self._finalize_event(ev, now)
                continue

            # Kein Recorder oder zu lange nicht gesehen → Event beenden
            if (now - ev['last_seen']).total_seconds() > 10:
                stale_keys.append(key)
                if recorder and not recorder.finished:
                    recorder.finish()
                self._finalize_event(ev, now)

        for key in stale_keys:
            del self.active_events[key]

    def _finalize_event(self, ev, end_time):
        """Event in der DB abschliessen (Endzeit + Clip-URL)."""
        recorder = ev.get('recorder')
        clip_url = recorder.clip_url if recorder else None

        conn = self._db()
        if not conn:
            return
        try:
            with conn.cursor() as cur:
                cur.execute("""
                    UPDATE camera_event SET
                        ended_at = %s,
                        clip_url = CASE WHEN %s IS NOT NULL THEN %s ELSE clip_url END
                    WHERE event_id = %s
                """, (end_time, clip_url, clip_url, ev['event_id']))
        except Exception as e:
            print(f"[{ev['camera_name']}] DB-Finalize Fehler: {e}")
            self._db_conn = None

    def _finish_all_recorders(self):
        """Alle aktiven Recorder beenden (z.B. bei Stream-Abbruch)."""
        now = datetime.now()
        for key, ev in list(self.active_events.items()):
            recorder = ev.get('recorder')
            if recorder and not recorder.finished:
                recorder.finish()
            self._finalize_event(ev, now)
        self.active_events.clear()

    def _check_rules(self, ev):
        """Regeln pruefen und Aktionen ausloesen."""
        now = datetime.now()
        current_time = now.strftime('%H:%M:%S')
        current_dow = now.weekday()  # 0=Mo, ... 6=So
        # Umrechnung auf JS-Konvention: 0=So, 1=Mo, ..., 6=Sa
        js_dow = (current_dow + 1) % 7

        for rule in self.rules:
            # Cooldown pruefen
            rule_id = rule['id']
            cooldown = rule.get('cooldown_seconds', 300)
            last = self.rule_cooldowns.get(rule_id)
            if last and (now - last).total_seconds() < cooldown:
                continue

            # Kamera pruefen
            if rule.get('camera_id') and rule['camera_id'] != ev.get('camera_id'):
                continue

            # Label pruefen
            rule_labels = rule.get('labels', [])
            if rule_labels and ev['label'] not in rule_labels:
                continue

            # Zone pruefen
            if rule.get('zone_key'):
                if rule['zone_key'] not in ev.get('zones', []):
                    continue

            # Score pruefen
            if ev['score'] < rule.get('min_score', 0.7):
                continue

            # Zeitfenster pruefen
            time_from = rule.get('time_from')
            time_to = rule.get('time_to')
            if time_from and time_to:
                tf = str(time_from)
                tt = str(time_to)
                if tf <= tt:
                    if current_time < tf or current_time > tt:
                        continue
                else:
                    if current_time < tf and current_time > tt:
                        continue

            # Wochentag pruefen
            rule_days = rule.get('days_of_week', [])
            if rule_days and str(js_dow) not in [str(d) for d in rule_days]:
                continue

            # Regel matched!
            print(f"[REGEL] '{rule['name']}' ausgeloest: {ev['label']} an {ev['camera_name']}")
            self.rule_cooldowns[rule_id] = now

            action = rule.get('action', 'notify')
            self._execute_action(action, rule, ev)

    def _execute_action(self, action, rule, ev):
        """Regelaktion ausfuehren."""
        config = rule.get('action_config', {})
        if isinstance(config, str):
            try:
                config = json.loads(config)
            except:
                config = {}

        zones_str = ', '.join(ev.get('zones', []))
        message = config.get('message', f"Kamera {ev['camera_name']}: {ev['label']} erkannt")
        if zones_str:
            message += f" in Zone {zones_str}"

        if action == 'notify':
            conn = self._db()
            if conn:
                try:
                    payload = json.dumps({
                        'type': 'camera_alert',
                        'rule': rule['name'],
                        'camera': ev['camera_name'],
                        'label': ev['label'],
                        'zones': ev.get('zones', []),
                        'message': message,
                        'snapshot_url': ev.get('snapshot_url', ''),
                    })
                    with conn.cursor() as cur:
                        cur.execute("SELECT pg_notify('camera_event', %s)", (payload,))
                except Exception as e:
                    print(f"[REGEL] Notify-Fehler: {e}")
                    self._db_conn = None

        elif action == 'log':
            print(f"[LOG] {message}")

        # WhatsApp und Email werden ueber PHP-Webhook gehandhabt
        # (da die WhatsApp-API-Credentials in PHP verwaltet werden)


# --- Konfiguration aus DB laden -----------------------------------------------

def load_cameras(conn):
    """Aktive Kameras mit Erkennungs-Einstellungen laden."""
    with conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
        cur.execute("""
            SELECT id, name, cam_key, stream_url, location, active, sort_order,
                COALESCE(motion_threshold, 1.5)  AS motion_threshold,
                COALESCE(min_score, 0.45)        AS min_score,
                COALESCE(record_clips, true)      AS record_clips,
                COALESCE(analysis_fps, 2)         AS analysis_fps,
                COALESCE(pre_record_secs, 3)      AS pre_record_secs,
                COALESCE(post_record_secs, 5)     AS post_record_secs
            FROM camera WHERE active ORDER BY sort_order, name
        """)
        return [dict(row) for row in cur.fetchall()]


def load_zones(conn, camera_id):
    """Zonen einer Kamera laden (mit Polygon-Koordinaten falls gesetzt)."""
    with conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
        try:
            cur.execute(
                "SELECT id, name, zone_key, color, coordinates FROM camera_zone WHERE camera_id = %s",
                (camera_id,)
            )
        except Exception:
            cur.execute("SELECT id, name, zone_key, color FROM camera_zone WHERE camera_id = %s", (camera_id,))
        zones = []
        for row in cur.fetchall():
            zone = dict(row)
            # coordinates: [[x,y], ...] normalisiert (0.0-1.0) oder None
            coords = zone.get('coordinates')
            if coords and isinstance(coords, str):
                try:
                    import json as _json
                    coords = _json.loads(coords)
                except Exception:
                    coords = None
            zone['coordinates'] = coords if (coords and len(coords) >= 3) else None
            zones.append(zone)
        return zones


def load_rules(conn):
    """Aktive Regeln laden."""
    with conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
        cur.execute("""
            SELECT r.*, z.zone_key
            FROM camera_rule r
            LEFT JOIN camera_zone z ON z.id = r.zone_id
            WHERE r.active
        """)
        rules = []
        for row in cur.fetchall():
            rule = dict(row)
            # PostgreSQL-Arrays zu Python-Listen
            if isinstance(rule.get('labels'), str):
                rule['labels'] = [x.strip('" ') for x in rule['labels'].strip('{}').split(',') if x.strip()]
            if isinstance(rule.get('days_of_week'), str):
                rule['days_of_week'] = [x.strip() for x in rule['days_of_week'].strip('{}').split(',') if x.strip()]
            if isinstance(rule.get('action_config'), str):
                try:
                    rule['action_config'] = json.loads(rule['action_config'])
                except:
                    rule['action_config'] = {}
            rules.append(rule)
        return rules


# --- Hauptprogramm ------------------------------------------------------------

def monitor_company(client, auth_settings):
    """Kamera-Monitoring fuer eine Company starten."""
    try:
        conn = connect_company_db(client)
        conn.autocommit = True
    except Exception as e:
        print(f"[{client['name']}] DB-Verbindungsfehler: {e}")
        return []

    if not is_camera_enabled(conn):
        conn.close()
        return []

    cameras = load_cameras(conn)
    if not cameras:
        print(f"[{client['name']}] Keine aktiven Kameras.")
        conn.close()
        return []

    rules = load_rules(conn)
    print(f"[{client['name']}] {len(cameras)} Kamera(s), {len(rules)} Regel(n)")

    # Connection-Params fuer Worker-Threads
    conn_params = {
        'host': client['dbhost'] or 'localhost',
        'port': int(client['dbport'] or 5432),
        'database': client['dbname'],
        'user': client['dbuser'],
        'password': client['dbpasswd'],
    }

    workers = []
    for cam in cameras:
        zones = load_zones(conn, cam['id'])
        worker = CameraWorker(cam, conn_params, zones, rules)
        worker.start()
        workers.append(worker)

    conn.close()
    return workers


def main():
    global running

    print("=" * 60)
    print("OpensourceERP Kamera-Monitor")
    print("=" * 60)

    # Graceful Shutdown
    def signal_handler(sig, frame):
        global running
        print("\nBeende...")
        running = False

    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    # DB-Verbindung
    settings = read_settings_ini()
    print(f"Auth-DB: {settings['host']}:{settings['port']}/{settings['dbname']}")

    try:
        auth_conn = psycopg2.connect(**settings)
        auth_conn.autocommit = True
    except Exception as e:
        print(f"FEHLER: Auth-DB nicht erreichbar: {e}")
        sys.exit(1)

    # Alle Companies pruefen
    companies = get_company_databases(auth_conn)
    all_workers = []

    for client in companies:
        workers = monitor_company(client, settings)
        all_workers.extend(workers)

    auth_conn.close()

    if not all_workers:
        print("Keine Kameras gefunden. Warte 60s und versuche erneut...")
        for _ in range(60):
            if not running:
                break
            time.sleep(1)
        sys.exit(0)

    print(f"\n{len(all_workers)} Kamera-Worker gestartet. Druecke Ctrl+C zum Beenden.\n")

    # Hauptthread wartet
    while running:
        time.sleep(1)

    print("Alle Worker beendet.")


if __name__ == '__main__':
    main()
