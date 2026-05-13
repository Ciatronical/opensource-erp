#!/usr/bin/env python3
"""
ANPR-Service fuer LxCars.

Liest Konfiguration direkt aus der Datenbank (settings.ini -> auth DB -> company DB).
Verarbeitet RTSP-Streams, erkennt Kennzeichen, schreibt Erkennungen in die DB
und steuert optional Aktoren (Tore, Schranken) via TCP/HTTP/Modbus.

Nutzung:
    ./venv/bin/python anpr_service.py
"""

import base64
import configparser
import json
import os
import socket
import subprocess
import sys
import threading
import time
import re
import signal
from http.server import HTTPServer, BaseHTTPRequestHandler
import cv2
import numpy as np
import psycopg2
import psycopg2.extras
from paddleocr import PaddleOCR


# --- Pfade --------------------------------------------------------------------

# settings.ini relativ zum Skript finden
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BACKEND_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, '..', '..'))
SETTINGS_INI = os.path.join(BACKEND_DIR, 'config', 'settings.ini')

# RTSP ueber TCP erzwingen (zuverlaessiger als UDP, weniger Artefakte)
os.environ['OPENCV_FFMPEG_CAPTURE_OPTIONS'] = 'rtsp_transport;tcp'

# --- Konfiguration -----------------------------------------------------------

PLATE_PATTERN = re.compile(
    r'^[A-ZÄÖÜ]{1,3}\s?[-–]?\s?[A-ZÄÖÜ]{1,2}\s?\d{1,4}\s?[EH]?$'
)

DEFAULT_POLL_INTERVAL = 60  # Config alle 60s neu laden

MERGE_X_THRESHOLD = 50
MERGE_Y_THRESHOLD = 15

MAX_SNAPSHOTS = 3            # Snapshots pro Erkennung: weit, mittel, nah
MIN_MOVEMENT_PX = 20         # Mindest-Pixelbewegung des Box-Mittelpunkts
DIRECTION_SIZE_RATIO = 1.20  # 20% Größenwachstum = Annäherung (war 1.15)

# Felder, bei deren Änderung ein Worker-Neustart ausgelöst wird
_CONFIG_WATCH_KEYS = [
    'rtsp_url', 'frame_interval', 'min_confidence', 'min_detections',
    'cooldown_minutes', 'action_type', 'actuator_id',
    'actuator_protocol', 'actuator_host', 'actuator_port',
    'actuator_command_open', 'actuator_command_partial',
    'gate_height_mode', 'calibration_gate_height_cm',
    'calibration_gate_top_y', 'calibration_gate_bottom_y',
    # Bildbereich-Filter: Änderung erfordert ebenfalls Worker-Neustart
    'excluded_cells', 'grid_size', 'ignore_right_pct', 'ignore_left_pct',
    'direction_required', 'save_snapshots', 'min_plate_height_px',
]


def _config_changed(old, new):
    """True wenn sich ein beobachtetes Konfigurationsfeld geändert hat."""
    return any(str(old.get(k)) != str(new.get(k)) for k in _CONFIG_WATCH_KEYS)


def _edit_distance(a, b):
    """Levenshtein-Distanz zwischen zwei Strings."""
    if len(a) < len(b):
        a, b = b, a
    row = list(range(len(b) + 1))
    for ca in a:
        new_row = [row[0] + 1]
        for j, cb in enumerate(b):
            new_row.append(min(new_row[-1] + 1, row[j + 1] + 1, row[j] + (ca != cb)))
        row = new_row
    return row[-1]


def _recently_reported_similar(track_key, reported, cooldown, now, max_dist=2):
    """True wenn ein aehnliches Kennzeichen (Edit-Distanz <= max_dist) im Cooldown ist."""
    for key, ts in reported.items():
        if key == track_key:
            continue
        if now - ts >= cooldown:
            continue
        if _edit_distance(track_key, key) <= max_dist:
            return True
    return False


# --- DB-Verbindung aus settings.ini -------------------------------------------

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


def is_anpr_enabled(company_conn):
    """Prueft ob ANPR fuer diese Company aktiviert ist."""
    with company_conn.cursor() as cur:
        # Pruefe ob Tabelle existiert (LxCars nicht ueberall aktiv)
        cur.execute(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables "
            "WHERE table_name = 'anpr_cameras_lxcars')"
        )
        if not cur.fetchone()[0]:
            return False

        cur.execute(
            "SELECT value FROM defaults_oserp WHERE key = 'anpr_enabled'"
        )
        row = cur.fetchone()
        return row and row[0] in ('1', 't', 'true')


# --- Bildverarbeitung --------------------------------------------------------

def preprocess_frame(frame):
    """Kontrast per CLAHE erhoehen."""
    lab = cv2.cvtColor(frame, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    l = clahe.apply(l)
    return cv2.cvtColor(cv2.merge([l, a, b]), cv2.COLOR_LAB2BGR)


def normalize_plate(text):
    """Erkannten Text zu Kennzeichen normalisieren."""
    text = text.upper().strip()
    text = text.replace('|', 'I').replace('§', 'S').replace('€', 'E')
    text = text.replace('.', '-').replace('·', '-')
    text = re.sub(r'[^A-ZÄÖÜ0-9\- ]', '', text).strip()

    if '-' not in text:
        m = re.match(r'^([A-ZÄÖÜ]{1,3})([A-ZÄÖÜ]{1,2})(\d{1,4}[EH]?)$', text)
        if m:
            text = f"{m.group(1)}-{m.group(2)} {m.group(3)}"
        m = re.match(r'^([A-ZÄÖÜ]{1,3})\s+([A-ZÄÖÜ]{1,2})\s+(\d{1,4}[EH]?)$', text)
        if m:
            text = f"{m.group(1)}-{m.group(2)} {m.group(3)}"

    text = re.sub(r'([A-ZÄÖÜ])(\d)', r'\1 \2', text)
    m = re.match(r'^([A-ZÄÖÜ]{1,3}\s?[-–]?\s?[A-ZÄÖÜ]{1,2}\s?)(.*)', text)
    if m:
        text = m.group(1) + m.group(2).replace('O', '0')
    return text


def is_german_plate(text):
    return PLATE_PATTERN.match(text) is not None


def box_area(box):
    pts = np.array(box)
    n = len(pts)
    area = 0.0
    for i in range(n):
        j = (i + 1) % n
        area += pts[i][0] * pts[j][1] - pts[j][0] * pts[i][1]
    return abs(area) / 2.0


def box_left_x(box):
    return min(p[0] for p in box)


def box_right_x(box):
    return max(p[0] for p in box)


def box_center_y(box):
    return np.mean([p[1] for p in box])


def box_height(box):
    ys = [p[1] for p in box]
    return max(ys) - min(ys)


def merge_nearby_texts(lines):
    if not lines:
        return lines
    sorted_lines = sorted(lines, key=lambda l: box_left_x(l[0]))
    merged = [sorted_lines[0]]
    for current in sorted_lines[1:]:
        last_box, last_info = merged[-1]
        curr_box, curr_info = current
        y_diff = abs(box_center_y(last_box) - box_center_y(curr_box))
        x_gap = box_left_x(curr_box) - box_right_x(last_box)
        if y_diff < MERGE_Y_THRESHOLD and x_gap < MERGE_X_THRESHOLD:
            all_pts = list(last_box) + list(curr_box)
            xs = [p[0] for p in all_pts]
            ys = [p[1] for p in all_pts]
            new_box = [[min(xs), min(ys)], [max(xs), min(ys)],
                       [max(xs), max(ys)], [min(xs), max(ys)]]
            merged[-1] = (new_box, (last_info[0] + ' ' + curr_info[0], min(last_info[1], curr_info[1])))
        else:
            merged.append(current)
    return merged


# --- Aktor-Steuerung ---------------------------------------------------------

class ActuatorController:
    """Steuert Aktoren (Tore, Schranken) via TCP/HTTP/Modbus."""

    @staticmethod
    def send_command(actuator_config, command, height_cm=None):
        if not command:
            return False

        protocol = actuator_config.get('actuator_protocol', 'tcp')
        host = actuator_config.get('actuator_host', '')
        port = int(actuator_config.get('actuator_port', 502))

        if height_cm is not None:
            command = command.replace('{height}', str(height_cm))

        try:
            if protocol == 'http':
                return ActuatorController._send_http(host, port, command)
            else:
                return ActuatorController._send_tcp(host, port, command)
        except Exception as e:
            print(f"[AKTOR] Fehler bei {host}:{port}: {e}")
            return False

    @staticmethod
    def _send_tcp(host, port, command):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            s.settimeout(5)
            s.connect((host, port))
            if all(c in '0123456789abcdefABCDEF ' for c in command.strip()):
                data = bytes.fromhex(command.replace(' ', ''))
            else:
                data = command.encode('utf-8')
            s.sendall(data)
            print(f"[AKTOR] TCP {host}:{port} <- {command}")
            return True

    @staticmethod
    def _send_http(host, port, command):
        import requests
        url = f"http://{host}:{port}/{command.lstrip('/')}"
        resp = requests.get(url, timeout=5)
        print(f"[AKTOR] HTTP {url} -> {resp.status_code}")
        return resp.status_code == 200

    @staticmethod
    def calculate_vehicle_height_cm(cam_config, vehicle_top_y, vehicle_bottom_y):
        """Berechnet die reale Fahrzeughöhe in cm anhand der Tor-Kalibrierung.

        Die Kamera sieht das Tor (bekannte Höhe in cm) und das Fahrzeug.
        Aus dem Verhältnis der Pixel-Höhen ergibt sich die reale Fahrzeughöhe.

        Kalibrierungswerte:
        - calibration_gate_height_cm: Reale Torhöhe (z.B. 300cm)
        - calibration_gate_top_y: Y-Pixel der Toroberkante
        - calibration_gate_bottom_y: Y-Pixel der Torunterkante
        """
        gate_height_cm = int(cam_config.get('calibration_gate_height_cm') or 0)
        gate_top_y = cam_config.get('calibration_gate_top_y')
        gate_bottom_y = cam_config.get('calibration_gate_bottom_y')

        if not gate_height_cm or gate_top_y is None or gate_bottom_y is None:
            return None

        gate_top_y = int(gate_top_y)
        gate_bottom_y = int(gate_bottom_y)
        gate_height_px = abs(gate_bottom_y - gate_top_y)

        if gate_height_px == 0:
            return None

        # cm pro Pixel
        cm_per_px = gate_height_cm / gate_height_px

        # Fahrzeughöhe in Pixeln
        vehicle_height_px = abs(vehicle_bottom_y - vehicle_top_y)

        return int(vehicle_height_px * cm_per_px)

    @staticmethod
    def open_gate(cam_config, vehicle_top_y=None, vehicle_bottom_y=None,
                  vehicle_height_px=None, frame_height=None):
        gate_mode = cam_config.get('gate_height_mode', 'full')
        max_h = int(cam_config.get('actuator_max_height_cm') or 300)
        buffer = int(cam_config.get('actuator_height_buffer_cm') or 30)

        if gate_mode == 'vehicle_height':
            vehicle_cm = None

            # Methode 1: Kalibrierte Berechnung über Tor-Referenz (genau)
            if vehicle_top_y is not None and vehicle_bottom_y is not None:
                vehicle_cm = ActuatorController.calculate_vehicle_height_cm(
                    cam_config, vehicle_top_y, vehicle_bottom_y)
                if vehicle_cm:
                    print(f"[AKTOR] Kalibrierte Höhe: ~{vehicle_cm}cm")

            # Methode 2: Fallback auf Frame-Verhältnis (grob)
            if vehicle_cm is None and vehicle_height_px and frame_height:
                ratio = vehicle_height_px / frame_height
                vehicle_cm = int(ratio * max_h)
                print(f"[AKTOR] Geschätzte Höhe (Fallback): ~{vehicle_cm}cm")

            if vehicle_cm:
                open_height = min(vehicle_cm + buffer, max_h)
                cmd = cam_config.get('actuator_command_partial', '')
                if cmd:
                    print(f"[AKTOR] Teilöffnung: {open_height}cm ({vehicle_cm}cm + {buffer}cm Puffer)")
                    return ActuatorController.send_command(cam_config, cmd, height_cm=open_height)

        # Fallback: Voll öffnen
        cmd = cam_config.get('actuator_command_open', '')
        if cmd:
            print(f"[AKTOR] Volle Öffnung")
            return ActuatorController.send_command(cam_config, cmd)
        return False


# --- Kamera-Worker ------------------------------------------------------------

class CameraWorker(threading.Thread):
    """Verarbeitet den Stream einer einzelnen Kamera."""

    def __init__(self, config, ocr, db_params):
        super().__init__(daemon=True)
        self.config = config
        self.ocr = ocr
        self.db_params = db_params
        self.running = True
        self.size_history = {}
        self.detection_frames = {}  # track_key -> [(area, frame), ...]

        self.cam_id = int(config.get('id', 0))
        self.name_str = config.get('name', f'Kamera #{self.cam_id}')
        self.rtsp_url = config.get('rtsp_url', '')
        self.interval = float(config.get('frame_interval') or 0.5)
        self.min_conf = float(config.get('min_confidence') or 0.60)
        self.min_det = int(config.get('min_detections') or 3)

        self.reported = {}  # track_key -> timestamp

        # Erkannte Boxen fuer MJPEG-Overlay (vom Erkennungs-Thread geschrieben)
        self._detected_boxes = []  # [(box, plate_text, confidence), ...]
        self._source_size = (0, 0)  # (width, height) des Kamera-Frames

    def run(self):
        print(f"[{self.name_str}] Starte Stream: {self.rtsp_url}")
        while self.running:
            try:
                self._process_stream()
            except Exception as e:
                print(f"[{self.name_str}] Fehler: {e}")
            if self.running:
                print(f"[{self.name_str}] Reconnect in 5s...")
                time.sleep(5)

    def stop(self):
        self.running = False

    def _process_stream(self):
        cap = cv2.VideoCapture(self.rtsp_url, cv2.CAP_FFMPEG)
        cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        if not cap.isOpened():
            print(f"[{self.name_str}] Stream konnte nicht geoeffnet werden")
            return

        last_process = 0
        while self.running:
            ret, frame = cap.read()
            if not ret:
                break

            # Kamera-Aufloesung merken (fuer MJPEG-Overlay-Skalierung)
            if self._source_size == (0, 0):
                self._source_size = (frame.shape[1], frame.shape[0])
                print(f"[{self.name_str}] Aufloesung: {frame.shape[1]}x{frame.shape[0]}")

            now = time.time()
            if now - last_process < self.interval:
                continue
            last_process = now

            enhanced = preprocess_frame(frame)
            plates = self._recognize(enhanced)
            if not plates:
                plates = self._recognize(frame)

            # Erkannte Boxen fuer MJPEG-Overlay aktualisieren
            self._detected_boxes = [
                (p['box'], p['plate'], p['confidence']) for p in plates
            ]

            for p in plates:
                track_key = re.sub(r'[\s\-]', '', p['plate'])
                hist = self.size_history.get(track_key, [])
                detection_count = len(hist)

                # Frame sammeln (max. MAX_SNAPSHOTS*3; _save_snapshots wählt 3 repräsentative aus)
                current_area = box_area(p['box'])
                if track_key not in self.detection_frames:
                    self.detection_frames[track_key] = []
                self.detection_frames[track_key].append((current_area, frame.copy()))
                if len(self.detection_frames[track_key]) > MAX_SNAPSHOTS * 3:
                    self.detection_frames[track_key].pop(0)

                dir_required = self.config.get('direction_required', True)
                direction = p.get('direction')
                movement_ok = self._is_moving(hist) if dir_required else True
                dir_ok = (not dir_required) or direction == 'in'

                # Debug-Ausgabe pro Erkennung
                if hist:
                    xs_d = [h[2] for h in hist]
                    ys_d = [h[3] for h in hist]
                    pos_delta = max(max(xs_d) - min(xs_d), max(ys_d) - min(ys_d)) if len(xs_d) > 1 else 0
                    areas_d = [h[1] for h in hist]
                    size_ratio = (max(areas_d) / min(areas_d)) if len(areas_d) >= 2 and min(areas_d) > 0 else 1.0
                    status = 'MELDEN' if (detection_count >= self.min_det and dir_ok and movement_ok) else \
                             'Standfahrzeug' if not movement_ok else \
                             f'warte(dir={direction})'
                    print(f"[{self.name_str}] DBG {track_key}: "
                          f"n={detection_count} h={p.get('plate_height_px', 0)}px "
                          f"area={int(current_area)} dir={direction} "
                          f"moving={movement_ok} pos_Δ={int(pos_delta)}px "
                          f"size_ratio={size_ratio:.2f} → {status}")

                if detection_count >= self.min_det and dir_ok and movement_ok:
                    cooldown = int(self.config.get('cooldown_minutes') or 5) * 60
                    last_report = self.reported.get(track_key, 0)
                    if now - last_report < cooldown:
                        continue
                    # Fuzzy-Check: aehnliches Kennzeichen (Edit-Distanz <= 2) kuezer zurueck
                    if _recently_reported_similar(track_key, self.reported, cooldown, now):
                        print(f"[{self.name_str}]     Ähnliches Kennzeichen kürzlich gemeldet → ignoriert")
                        continue

                    self.reported[track_key] = now
                    saved_frames = self.detection_frames.pop(track_key, [])
                    self.size_history[track_key] = []
                    self._report_detection(p, saved_frames)

                elif detection_count >= self.min_det:
                    if not movement_ok:
                        # Standfahrzeug: Frames nicht weiter ansammeln
                        if detection_count == self.min_det:
                            areas_log = [int(h[1]) for h in hist]
                            print(f"[{self.name_str}] {track_key}: Standfahrzeug erkannt "
                                  f"(n={detection_count}, Größen: {areas_log}) → ignoriert")
                        self.detection_frames.pop(track_key, None)
                    elif direction == 'out':
                        # Fahrzeug fährt weg → History aufräumen damit späteres Einfahren
                        # nicht durch alte 'out'-Messungen blockiert wird
                        print(f"[{self.name_str}] {track_key}: fährt weg → History zurückgesetzt")
                        self.size_history[track_key] = []
                        self.detection_frames.pop(track_key, None)
                    elif direction is None and detection_count == self.min_det:
                        areas_log = [int(h[1]) for h in hist]
                        print(f"[{self.name_str}] {track_key}: {detection_count} Erkennungen, "
                              f"noch kein Bewegungstrend (Größen: {areas_log})")

            # Speicher aufraemen: detection_frames fuer inaktive Kennzeichen entfernen
            for key in list(self.detection_frames):
                if not self.size_history.get(key):
                    del self.detection_frames[key]

        cap.release()

    def _recognize(self, frame):
        result = self.ocr.ocr(frame, cls=True)
        detections = []
        if not result or not result[0]:
            return detections

        lines = [(line[0], line[1]) for line in result[0]]
        merged = merge_nearby_texts(lines)

        frame_h, frame_w = frame.shape[:2]
        ignore_right = int(self.config.get('ignore_right_pct') or 0) / 100
        ignore_left  = int(self.config.get('ignore_left_pct')  or 0) / 100
        grid_size_pct = max(1, int(self.config.get('grid_size') or 10))
        try:
            excluded_cells = json.loads(self.config.get('excluded_cells') or '[]')
        except Exception:
            excluded_cells = []

        for box, (text, conf) in merged:
            if conf < self.min_conf:
                continue
            cx = np.mean([p[0] for p in box])
            cy = np.mean([p[1] for p in box])
            # Randausblendung (Prozent)
            if ignore_right and cx > frame_w * (1 - ignore_right):
                continue
            if ignore_left and cx < frame_w * ignore_left:
                continue
            # Raster-Ausblendung (geklickte Zellen)
            if excluded_cells:
                cell_col = int((cx / frame_w) * (100 / grid_size_pct))
                cell_row = int((cy / frame_h) * (100 / grid_size_pct))
                if [cell_row, cell_col] in excluded_cells:
                    continue
            normalized = normalize_plate(text)
            if not is_german_plate(normalized):
                continue

            # Kennzeichen-Mindestgröße prüfen (zu weit weg = zu klein = ignorieren)
            plate_h_px = int(box_height(box))
            min_plate_h = int(self.config.get('min_plate_height_px') or 0)
            if min_plate_h and plate_h_px < min_plate_h:
                continue

            track_key = re.sub(r'[\s\-]', '', normalized)
            area = box_area(box)
            now_ts = time.time()
            if track_key not in self.size_history:
                self.size_history[track_key] = []
            # Eintraege aelter als 20s verwerfen (verhindert Fehlmeldungen durch statische Elemente)
            # Format: (timestamp, area, center_x, center_y)
            self.size_history[track_key] = [
                h for h in self.size_history[track_key]
                if now_ts - h[0] < 20
            ]
            self.size_history[track_key].append((now_ts, area, float(cx), float(cy)))
            if len(self.size_history[track_key]) > 10:
                self.size_history[track_key].pop(0)

            direction = self._detect_direction(self.size_history[track_key])

            # Fahrzeughöhe schätzen: Kennzeichen ist auf ~50cm Höhe montiert.
            # Fahrzeugoberkante ≈ Kennzeichen-Oberkante minus geschätzte Höhe darüber.
            # Bei seitlicher Kamera: Kennzeichen-Bounding-Box-Höhe ≈ 11cm real.
            # Daraus den cm/px-Faktor ableiten, dann Fahrzeughöhe = Kennzeichen-Y + ~100cm darüber.
            plate_top_y = min(p[1] for p in box)
            plate_bottom_y = max(p[1] for p in box)
            plate_height_px = plate_bottom_y - plate_top_y

            # Deutsches Kennzeichen ist genormt 11cm hoch
            if plate_height_px > 0:
                local_cm_per_px = 11.0 / plate_height_px
                # Typischer PKW: ~140cm, Kennzeichen auf ~50cm → ~90cm über Kennzeichen
                estimated_top_y = int(plate_top_y - (90.0 / local_cm_per_px))
                estimated_vehicle_height_px = plate_bottom_y - estimated_top_y
            else:
                estimated_top_y = plate_top_y
                estimated_vehicle_height_px = int(box_height(box) * 5)

            detections.append({
                'plate': normalized,
                'confidence': conf,
                'box': box,
                'direction': direction,
                'cx': float(cx),
                'cy': float(cy),
                'plate_height_px': plate_h_px,
                'vehicle_height_px': estimated_vehicle_height_px,
                'vehicle_top_y': max(0, estimated_top_y),
                'vehicle_bottom_y': plate_bottom_y,
            })

        return detections

    def _detect_direction(self, history):
        # Mindestens 4 Messpunkte für Richtungsentscheidung.
        # History-Format: [(ts, area, cx, cy), ...]
        # DIRECTION_SIZE_RATIO=1.20 (20%) filtert OCR-Box-Jitter von Standfahrzeugen
        # zuverlässiger als der alte 15%-Wert.
        if len(history) < 4:
            return None
        areas = [h[1] for h in history]
        first_avg = np.mean(areas[:2])
        last_avg = np.mean(areas[-2:])
        if first_avg == 0:
            return None
        ratio = last_avg / first_avg
        if ratio >= DIRECTION_SIZE_RATIO:
            return 'in'
        elif ratio <= 1 / DIRECTION_SIZE_RATIO:
            return 'out'
        return None

    def _is_moving(self, history):
        """True wenn das Fahrzeug sich erkennbar bewegt (kein Standfahrzeug).

        Prüft Positionsänderung des Box-Mittelpunkts UND Größenwachstum.
        Ein gerades Auffahren auf die Kamera hat kaum Positionsänderung,
        aber deutliches Größenwachstum — beides wird akzeptiert.
        """
        if len(history) < 2:
            return False
        xs = [h[2] for h in history]
        ys = [h[3] for h in history]
        pos_delta = max(max(xs) - min(xs), max(ys) - min(ys))
        if pos_delta >= MIN_MOVEMENT_PX:
            return True
        # Frontalansatz: Größe nimmt signifikant zu (≥ DIRECTION_SIZE_RATIO)
        if len(history) >= 4:
            areas = [h[1] for h in history]
            first_avg = np.mean(areas[:2])
            last_avg = np.mean(areas[-2:])
            if first_avg > 0 and last_avg / first_avg >= DIRECTION_SIZE_RATIO:
                return True
        return False

    def _save_snapshots(self, frames, plate_info, detection_id):
        """MAX_SNAPSHOTS repräsentative Frames als nummerierte JPEGs speichern.

        Aus den gesammelten Frames werden MAX_SNAPSHOTS gleichmäßig verteilte
        ausgewählt (weit → mittel → nah), sortiert nach Bounding-Box-Fläche.
        """
        snapshot_dir = os.path.join(
            BACKEND_DIR, 'data', self.db_params['database'], 'anpr-snapshots'
        )
        os.makedirs(snapshot_dir, exist_ok=True)

        # Aufsteigend sortieren: kleinstes Fahrzeug (weit) → groesstes (nah)
        sorted_frames = sorted(frames, key=lambda x: x[0])

        # MAX_SNAPSHOTS gleichmäßig verteilt auswählen
        n = len(sorted_frames)
        if n > MAX_SNAPSHOTS:
            indices = [int(round(i * (n - 1) / (MAX_SNAPSHOTS - 1))) for i in range(MAX_SNAPSHOTS)]
            sorted_frames = [sorted_frames[i] for i in indices]

        box = plate_info.get('box')
        for i, (_, frame) in enumerate(sorted_frames, start=1):
            annotated = frame.copy()
            if box:
                pts = np.array(box, dtype=np.int32)
                cv2.polylines(annotated, [pts], True, (0, 220, 0), 3)
                label = f"{plate_info['plate']}  {plate_info['confidence']:.0%}"
                x = int(pts[:, 0].min())
                y = max(int(pts[:, 1].min()) - 12, 24)
                (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 1.2, 2)
                cv2.rectangle(annotated, (x, y - th - 6), (x + tw + 6, y + 6), (0, 0, 0), -1)
                cv2.putText(annotated, label, (x + 3, y),
                            cv2.FONT_HERSHEY_SIMPLEX, 1.2, (0, 220, 0), 2)

            h, w = annotated.shape[:2]
            if w > 1280:
                scale = 1280 / w
                annotated = cv2.resize(annotated, (int(w * scale), int(h * scale)))

            path = os.path.join(snapshot_dir, f"{detection_id}_{i}.jpg")
            cv2.imwrite(path, annotated, [cv2.IMWRITE_JPEG_QUALITY, 85])

        print(f"[{self.name_str}]     {len(sorted_frames)} Snapshot(s) gespeichert (ID {detection_id})")

    def _report_detection(self, plate_info, frames):
        """Erkennung direkt in die DB schreiben und Snapshots speichern."""
        kennzeichen = plate_info['plate']
        # Fuer frame_shape: groesstes Frame (naehestes Auto) verwenden
        best_frame = max(frames, key=lambda x: x[0])[1] if frames else None
        frame_shape = best_frame.shape if best_frame is not None else (0, 0, 0)
        print(f"[{self.name_str}] >>> MELDUNG: {kennzeichen} "
              f"(conf: {plate_info['confidence']:.1%}, dir: {plate_info['direction']})")

        try:
            conn = psycopg2.connect(**self.db_params)
            conn.autocommit = True
            with conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
                # Kennzeichen normieren: ohne Leerzeichen (für c_ln in DB)
                kennzeichen_nospace = kennzeichen.replace(' ', '')
                # Kennzeichen normieren: ohne Leerzeichen UND Bindestriche (für Vergleiche)
                kennzeichen_normalized = re.sub(r'[\s\-]', '', kennzeichen).upper()

                # Blacklist prüfen (normiert: ohne Leerzeichen und Bindestriche)
                cur.execute(
                    "SELECT value FROM defaults_oserp WHERE key = 'anpr_blacklist'"
                )
                bl_row = cur.fetchone()
                if bl_row and bl_row['value']:
                    blacklist = [re.sub(r'[\s\-]', '', p).upper()
                                 for p in bl_row['value'].split(',') if p.strip()]
                    if kennzeichen_normalized in blacklist:
                        print(f"[{self.name_str}]     Blacklist → ignoriert")
                        conn.close()
                        return

                # Fahrzeug suchen: Leerzeichen UND Bindestriche auf beiden Seiten
                # normieren, damit "MOL-CM 50E", "MOLCM50E" und "MOL-CM50E"
                # alle dasselbe Fahrzeug finden.
                cur.execute(
                    "SELECT c.c_id, c.c_ow AS customer_id, cv.name AS customer_name "
                    "FROM cars_lxcars c "
                    "LEFT JOIN customer cv ON c.c_ow = cv.id "
                    "WHERE REPLACE(REPLACE(UPPER(c.c_ln), ' ', ''), '-', '') = %s",
                    (kennzeichen_normalized,)
                )
                car = cur.fetchone()
                c_id = car['c_id'] if car else None
                customer_id = car['customer_id'] if car else None

                # Offenen Auftrag pruefen
                has_open_order = False
                if c_id:
                    cur.execute(
                        "SELECT 1 FROM oe "
                        "JOIN oe_ext ON oe.id = oe_ext.oe_id "
                        "WHERE oe_ext.c_id = %s AND oe.closed IS NOT TRUE "
                        "LIMIT 1",
                        (c_id,)
                    )
                    has_open_order = cur.fetchone() is not None

                # Aktion bestimmen
                action_taken = 'none'
                if not has_open_order:
                    action_type = self.config.get('action_type', 'infobar')
                    if action_type in ('infobar', 'both'):
                        action_taken = 'infobar'
                    if action_type in ('actuator', 'both') and self.config.get('actuator_id'):
                        action_taken = 'both' if action_taken == 'infobar' else 'gate_open'

                # Erkennung speichern (SSE-Trigger feuert automatisch!)
                cur.execute(
                    "INSERT INTO anpr_detections_lxcars "
                    "(camera_id, c_ln, c_id, customer_id, direction, confidence, "
                    " vehicle_height_px, frame_width, frame_height, action_taken, dismissed) "
                    "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) RETURNING id",
                    (
                        self.cam_id, kennzeichen_nospace, c_id, customer_id,
                        plate_info['direction'] or 'in',
                        plate_info['confidence'],
                        plate_info.get('vehicle_height_px'),
                        frame_shape[1], frame_shape[0],
                        action_taken,
                        action_taken == 'none',
                    )
                )
                detection_id = cur.fetchone()[0]
                save_snap = self.config.get('save_snapshots', True)
                if frames and save_snap not in (False, 'f', '0', 'false', 0):
                    self._save_snapshots(frames, plate_info, detection_id)

                status = f"known={bool(car)}, open_order={has_open_order}, action={action_taken}"
                if car and car['customer_name']:
                    status += f", kunde={car['customer_name']}"
                print(f"[{self.name_str}]     DB -> {status}")

                # Aktor ansteuern
                if action_taken in ('gate_open', 'both'):
                    ActuatorController.open_gate(
                        self.config,
                        vehicle_top_y=plate_info.get('vehicle_top_y'),
                        vehicle_bottom_y=plate_info.get('vehicle_bottom_y'),
                        vehicle_height_px=plate_info.get('vehicle_height_px'),
                        frame_height=frame_shape[0]
                    )

            conn.close()

        except Exception as e:
            print(f"[{self.name_str}]     DB-Fehler: {e}")


# --- MJPEG-Server -------------------------------------------------------------

PREVIEW_W = 854
PREVIEW_H = 480
PREVIEW_FPS = 10
PREVIEW_FRAME_SIZE = PREVIEW_W * PREVIEW_H * 3


class MjpegHandler(BaseHTTPRequestHandler):
    """HTTP-Handler fuer MJPEG-Streams der Kameras.

    Nutzt FFmpeg als Subprocess fuer zuverlaessiges RTSP-Decoding
    (korrekte Keyframe-Behandlung, kein Buffer-Stau, TCP-Transport).
    """

    def log_message(self, format, *args):
        # Keine Access-Logs
        pass

    def do_GET(self):
        # /stream/<camera_id>
        if self.path.startswith('/stream/'):
            try:
                cam_id = int(self.path.split('/')[2])
            except (IndexError, ValueError):
                self.send_error(400, 'Kamera-ID fehlt')
                return
            self._stream_camera(cam_id)
        elif self.path == '/cameras':
            self._list_cameras()
        else:
            self.send_error(404)

    def _find_worker(self, cam_id):
        for w in self.server.anpr_workers.values():
            if w.cam_id == cam_id:
                return w
        return None

    def _list_cameras(self):
        """Liefert JSON-Liste aller aktiven Kameras mit ID und Name."""
        cams = []
        for worker in self.server.anpr_workers.values():
            cams.append({
                'id': worker.cam_id,
                'name': worker.name_str,
            })
        data = json.dumps(cams).encode('utf-8')
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    @staticmethod
    def _draw_overlay(frame, boxes, source_size):
        """Zeichnet erkannte Kennzeichen-Boxen auf den Vorschau-Frame.
        Skaliert die Box-Koordinaten von Kamera-Aufloesung auf Vorschau-Groesse."""
        if not boxes or source_size == (0, 0):
            return frame

        sx = PREVIEW_W / source_size[0]
        sy = PREVIEW_H / source_size[1]

        for box, plate_text, conf in boxes:
            pts = np.array([[int(p[0] * sx), int(p[1] * sy)] for p in box], dtype=np.int32)
            cv2.polylines(frame, [pts], True, (0, 255, 0), 2)
            label = f"{plate_text} ({conf:.0%})"
            x, y = pts[0][0], pts[0][1] - 8
            (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.6, 2)
            cv2.rectangle(frame, (x, y - th - 4), (x + tw + 4, y + 4), (0, 0, 0), -1)
            cv2.putText(frame, label, (x + 2, y),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 0), 2)
        return frame

    def _stream_camera(self, cam_id):
        """MJPEG-Stream via FFmpeg-Subprocess (zuverlaessiges RTSP-Decoding)."""
        worker = self._find_worker(cam_id)
        if not worker:
            self.send_error(404, f'Kamera {cam_id} nicht gefunden')
            return

        # FFmpeg: RTSP -> rawvideo (BGR24) auf feste Vorschau-Groesse skaliert
        cmd = [
            'ffmpeg',
            '-rtsp_transport', 'tcp',
            '-fflags', '+discardcorrupt+nobuffer',
            '-flags', 'low_delay',
            '-i', worker.rtsp_url,
            '-f', 'rawvideo',
            '-pix_fmt', 'bgr24',
            '-s', f'{PREVIEW_W}x{PREVIEW_H}',
            '-r', str(PREVIEW_FPS),
            '-an',
            'pipe:1',
        ]

        proc = None
        try:
            proc = subprocess.Popen(
                cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)

            self.send_response(200)
            self.send_header('Content-Type',
                             'multipart/x-mixed-replace; boundary=frame')
            self.send_header('Cache-Control', 'no-cache, no-store')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()

            while worker.running:
                raw = proc.stdout.read(PREVIEW_FRAME_SIZE)
                if len(raw) != PREVIEW_FRAME_SIZE:
                    break

                frame = np.frombuffer(raw, dtype=np.uint8).reshape(
                    (PREVIEW_H, PREVIEW_W, 3))

                # Erkennungs-Overlay vom Worker zeichnen
                frame = self._draw_overlay(
                    frame, worker._detected_boxes, worker._source_size)

                _, jpeg = cv2.imencode(
                    '.jpg', frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
                jpeg_bytes = jpeg.tobytes()

                self.wfile.write(b'--frame\r\n')
                self.wfile.write(b'Content-Type: image/jpeg\r\n')
                self.wfile.write(
                    f'Content-Length: {len(jpeg_bytes)}\r\n'.encode())
                self.wfile.write(b'\r\n')
                self.wfile.write(jpeg_bytes)
                self.wfile.write(b'\r\n')

        except (BrokenPipeError, ConnectionResetError):
            pass
        finally:
            if proc:
                proc.kill()
                proc.wait()


class MjpegServer(threading.Thread):
    """HTTP-Server fuer MJPEG-Kamera-Streams."""

    def __init__(self, port, workers):
        super().__init__(daemon=True)
        self.port = port
        self.workers = workers
        self.httpd = None

    def run(self):
        self.httpd = HTTPServer(('0.0.0.0', self.port), MjpegHandler)
        self.httpd.anpr_workers = self.workers
        print(f"[MJPEG] Server gestartet auf Port {self.port}")
        self.httpd.serve_forever()

    def stop(self):
        if self.httpd:
            self.httpd.shutdown()

    def update_workers(self, workers):
        """Worker-Referenz aktualisieren (bei Config-Reload)."""
        if self.httpd:
            self.httpd.anpr_workers = workers


# --- Hauptservice ------------------------------------------------------------

class AnprService:
    """Hauptservice: Liest settings.ini, laedt Config aus DB, startet Kamera-Worker."""

    def __init__(self):
        self.workers = {}
        self.running = True
        self.mjpeg_server = None

        # settings.ini lesen
        print(f"Lese {SETTINGS_INI}...")
        self.auth_params = read_settings_ini()
        print(f"Auth-DB: {self.auth_params['user']}@{self.auth_params['host']}:{self.auth_params['port']}/{self.auth_params['dbname']}")

        # --- PaddleOCR initialisieren ---
        #
        # PaddleOCR kann auf verschiedener Hardware laufen:
        #
        #   1. CPU (Standard, immer verfuegbar):
        #      PaddleOCR(use_gpu=False)
        #      → ~200-400ms pro Frame auf einem i5
        #
        #   2. Intel iGPU via OpenVINO (empfohlen, kostenlos):
        #      PaddleOCR(use_gpu=False, use_openvino=True)
        #      → ~80-150ms pro Frame (~3x schneller)
        #      → Nutzt die eingebaute Intel UHD/Iris GPU
        #      → Voraussetzung: pip install openvino
        #
        #   3. NVIDIA GPU via CUDA (teuer, fuer grosse Installationen):
        #      PaddleOCR(use_gpu=True)
        #      → ~20-50ms pro Frame (~10x schneller)
        #      → Voraussetzung: NVIDIA GPU + CUDA + cuDNN installiert
        #
        # Die Erkennung ist automatisch:
        # - OpenVINO installiert? → iGPU nutzen
        # - Nicht installiert?    → CPU-Fallback
        #
        # Der Google Coral USB Stick kann PaddleOCR NICHT beschleunigen,
        # weil Coral nur TensorFlow-Lite-Modelle ausfuehren kann.
        # PaddleOCR basiert auf dem PaddlePaddle-Framework.
        #
        # Zum Beschleunigen auf dem bestehenden i5:
        #   pip install openvino
        # Das reicht — PaddleOCR erkennt OpenVINO automatisch.

        # Pruefen ob OpenVINO verfuegbar ist
        _openvino_available = False
        try:
            import openvino
            _openvino_available = True
            print(f"[ANPR] OpenVINO {openvino.__version__} erkannt → PaddleOCR nutzt Intel iGPU")
        except ImportError:
            print("[ANPR] OpenVINO nicht installiert → PaddleOCR laeuft auf CPU")
            print("[ANPR]   Tipp: 'pip install openvino' fuer ~3x schnellere Kennzeichenerkennung")

        print("PaddleOCR wird geladen...")

        # --- Alter Code (nur CPU) ---
        # self.ocr = PaddleOCR(
        #     use_angle_cls=True, lang='en',
        #     show_log=False, use_gpu=False,
        # )

        # --- Neuer Code: automatische Hardware-Erkennung ---
        # use_gpu=False:     Keine NVIDIA GPU noetig
        # use_openvino=True: Nutzt Intel iGPU falls OpenVINO installiert ist.
        #                    Falls OpenVINO NICHT installiert ist, ignoriert
        #                    PaddleOCR den Parameter und laeuft auf CPU weiter.
        #                    Es gibt also keinen Fehler wenn OpenVINO fehlt.
        self.ocr = PaddleOCR(
            use_angle_cls=True,
            lang='en',
            show_log=False,
            use_gpu=False,            # Kein CUDA/NVIDIA noetig
            use_openvino=_openvino_available,  # Intel iGPU wenn verfuegbar
        )

        if _openvino_available:
            print("PaddleOCR bereit (Intel iGPU via OpenVINO).\n")
        else:
            print("PaddleOCR bereit (CPU-Modus).\n")

    def _get_mjpeg_port(self):
        """MJPEG-Port aus der DB lesen (anpr_service_port + 1, default 8766)."""
        try:
            auth_conn = psycopg2.connect(**self.auth_params)
            companies = get_company_databases(auth_conn)
            auth_conn.close()
            for client in companies:
                try:
                    conn = connect_company_db(client)
                    with conn.cursor() as cur:
                        cur.execute(
                            "SELECT value FROM defaults_oserp "
                            "WHERE key = 'anpr_mjpeg_port'"
                        )
                        row = cur.fetchone()
                        if row and row[0]:
                            conn.close()
                            return int(row[0])
                        # Fallback: service_port + 1
                        cur.execute(
                            "SELECT value FROM defaults_oserp "
                            "WHERE key = 'anpr_service_port'"
                        )
                        row = cur.fetchone()
                        conn.close()
                        if row and row[0]:
                            return int(row[0]) + 1
                except Exception:
                    pass
        except Exception:
            pass
        return 8766

    def start(self):
        signal.signal(signal.SIGINT, self._shutdown)
        signal.signal(signal.SIGTERM, self._shutdown)

        # MJPEG-Server starten
        mjpeg_port = self._get_mjpeg_port()
        self.mjpeg_server = MjpegServer(mjpeg_port, self.workers)
        self.mjpeg_server.start()

        print("ANPR-Service gestartet.")

        while self.running:
            self._update_config()
            # Worker-Referenz im MJPEG-Server aktualisieren
            if self.mjpeg_server:
                self.mjpeg_server.update_workers(self.workers)
            for _ in range(DEFAULT_POLL_INTERVAL):
                if not self.running:
                    break
                time.sleep(1)

        self._stop_all_workers()
        if self.mjpeg_server:
            self.mjpeg_server.stop()
        print("ANPR-Service beendet.")

    def _shutdown(self, signum, frame):
        print("\nShutdown Signal empfangen...")
        self.running = False

    def _update_config(self):
        """Laedt Kamera-Config aus allen Company-DBs."""
        try:
            auth_conn = psycopg2.connect(**self.auth_params)
            companies = get_company_databases(auth_conn)
            auth_conn.close()

            active_ids = set()

            for client in companies:
                try:
                    comp_conn = connect_company_db(client)

                    if not is_anpr_enabled(comp_conn):
                        comp_conn.close()
                        continue

                    # DB-Params fuer Worker merken
                    db_params = {
                        'host': client['dbhost'] or 'localhost',
                        'port': int(client['dbport'] or 5432),
                        'database': client['dbname'],
                        'user': client['dbuser'],
                        'password': client['dbpasswd'],
                    }

                    # Aktive Kameras laden
                    with comp_conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
                        cur.execute(
                            "SELECT c.*, "
                            "  a.name AS actuator_name, a.type AS actuator_type, "
                            "  a.protocol AS actuator_protocol, a.host AS actuator_host, "
                            "  a.port AS actuator_port, "
                            "  a.command_open AS actuator_command_open, "
                            "  a.command_close AS actuator_command_close, "
                            "  a.command_partial AS actuator_command_partial, "
                            "  a.max_height_cm AS actuator_max_height_cm, "
                            "  a.height_buffer_cm AS actuator_height_buffer_cm, "
                            "  a.timeout_seconds AS actuator_timeout_seconds "
                            "FROM anpr_cameras_lxcars c "
                            "LEFT JOIN anpr_actuators_lxcars a ON c.actuator_id = a.id "
                            "WHERE c.enabled = true "
                            "ORDER BY c.id"
                        )
                        cameras = [dict(row) for row in cur.fetchall()]

                    comp_conn.close()

                    for cam in cameras:
                        # Eindeutiger Key: company_id + cam_id
                        worker_key = f"{client['id']}_{cam['id']}"
                        active_ids.add(worker_key)

                        existing = self.workers.get(worker_key)

                        if existing is None:
                            # Neue Kamera
                            worker = CameraWorker(cam, self.ocr, db_params)
                            worker.start()
                            self.workers[worker_key] = worker
                            print(f"[CONFIG] Worker gestartet: {client['name']} / {cam.get('name', cam['id'])}")

                        elif not existing.is_alive():
                            # Worker abgestuerzt → neu starten
                            print(f"[CONFIG] Worker neu starten (abgestuerzt): {cam.get('name', cam['id'])}")
                            worker = CameraWorker(cam, self.ocr, db_params)
                            worker.start()
                            self.workers[worker_key] = worker

                        elif _config_changed(existing.config, cam):
                            # Config geaendert → Worker neu starten
                            changed = [k for k in _CONFIG_WATCH_KEYS
                                       if str(existing.config.get(k)) != str(cam.get(k))]
                            print(f"[CONFIG] Config geaendert ({', '.join(changed)}), "
                                  f"Worker neu starten: {cam.get('name', cam['id'])}")
                            existing.stop()
                            worker = CameraWorker(cam, self.ocr, db_params)
                            worker.start()
                            self.workers[worker_key] = worker

                except Exception as e:
                    print(f"[CONFIG] Fehler bei Company '{client.get('name', '?')}': {e}")

            # Nicht mehr aktive Worker stoppen
            for key in list(self.workers.keys()):
                if key not in active_ids:
                    print(f"[CONFIG] Worker gestoppt: {key}")
                    self.workers[key].stop()
                    del self.workers[key]

            if self.workers:
                print(f"[CONFIG] {len(self.workers)} aktive Kamera(s)")

        except Exception as e:
            print(f"[CONFIG] Fehler beim Laden: {e}")

    def _stop_all_workers(self):
        for worker in self.workers.values():
            worker.stop()
        self.workers.clear()


# --- Main ---------------------------------------------------------------------

def main():
    service = AnprService()
    service.start()


if __name__ == '__main__':
    main()
