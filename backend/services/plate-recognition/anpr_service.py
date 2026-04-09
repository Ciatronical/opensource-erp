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
import sys
import threading
import time
import re
import signal
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

# --- Konfiguration -----------------------------------------------------------

PLATE_PATTERN = re.compile(
    r'^[A-ZÄÖÜ]{1,3}\s?[-–]?\s?[A-ZÄÖÜ]{1,2}\s?\d{1,4}\s?[EH]?$'
)

DEFAULT_POLL_INTERVAL = 60  # Config alle 60s neu laden

MERGE_X_THRESHOLD = 50
MERGE_Y_THRESHOLD = 15


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

        self.cam_id = int(config.get('id', 0))
        self.name_str = config.get('name', f'Kamera #{self.cam_id}')
        self.rtsp_url = config.get('rtsp_url', '')
        self.interval = float(config.get('frame_interval') or 0.5)
        self.min_conf = float(config.get('min_confidence') or 0.60)
        self.min_det = int(config.get('min_detections') or 3)

        self.reported = {}  # track_key -> timestamp

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
        cap = cv2.VideoCapture(self.rtsp_url)
        if not cap.isOpened():
            print(f"[{self.name_str}] Stream konnte nicht geoeffnet werden")
            return

        last_process = 0
        while self.running:
            ret, frame = cap.read()
            if not ret:
                break

            now = time.time()
            if now - last_process < self.interval:
                continue
            last_process = now

            enhanced = preprocess_frame(frame)
            plates = self._recognize(enhanced)
            if not plates:
                plates = self._recognize(frame)

            for p in plates:
                track_key = re.sub(r'[\s\-]', '', p['plate'])
                detection_count = len(self.size_history.get(track_key, []))

                if detection_count >= self.min_det and p.get('direction') == 'in':
                    cooldown = int(self.config.get('cooldown_minutes') or 5) * 60
                    last_report = self.reported.get(track_key, 0)
                    if now - last_report < cooldown:
                        continue

                    self.reported[track_key] = now
                    self._report_detection(p, frame.shape)

        cap.release()

    def _recognize(self, frame):
        result = self.ocr.ocr(frame, cls=True)
        detections = []
        if not result or not result[0]:
            return detections

        lines = [(line[0], line[1]) for line in result[0]]
        merged = merge_nearby_texts(lines)

        for box, (text, conf) in merged:
            if conf < self.min_conf:
                continue
            normalized = normalize_plate(text)
            if not is_german_plate(normalized):
                continue

            track_key = re.sub(r'[\s\-]', '', normalized)
            area = box_area(box)
            if track_key not in self.size_history:
                self.size_history[track_key] = []
            self.size_history[track_key].append(area)
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
                'vehicle_height_px': estimated_vehicle_height_px,
                'vehicle_top_y': max(0, estimated_top_y),
                'vehicle_bottom_y': plate_bottom_y,
            })

        return detections

    def _detect_direction(self, history):
        if len(history) < 3:
            return None
        first_avg = np.mean(history[:2])
        last_avg = np.mean(history[-2:])
        if first_avg == 0:
            return None
        ratio = last_avg / first_avg
        if ratio >= 1.15:
            return 'in'
        elif ratio <= 1 / 1.15:
            return 'out'
        return None

    def _report_detection(self, plate_info, frame_shape):
        """Erkennung direkt in die DB schreiben."""
        kennzeichen = plate_info['plate']
        print(f"[{self.name_str}] >>> MELDUNG: {kennzeichen} "
              f"(conf: {plate_info['confidence']:.1%}, dir: {plate_info['direction']})")

        try:
            conn = psycopg2.connect(**self.db_params)
            conn.autocommit = True
            with conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
                # Kennzeichen ohne Leerzeichen (so wie in der DB)
                kennzeichen_nospace = kennzeichen.replace(' ', '')

                # Blacklist prüfen (ohne Leerzeichen vergleichen)
                cur.execute(
                    "SELECT value FROM defaults_oserp WHERE key = 'anpr_blacklist'"
                )
                bl_row = cur.fetchone()
                if bl_row and bl_row['value']:
                    blacklist = [p.strip().replace(' ', '').upper()
                                 for p in bl_row['value'].split(',') if p.strip()]
                    if kennzeichen_nospace.upper() in blacklist:
                        print(f"[{self.name_str}]     Blacklist → ignoriert")
                        conn.close()
                        return

                # Fahrzeug suchen (ohne Leerzeichen, wie in der DB)
                cur.execute(
                    "SELECT c.c_id, c.c_ow AS customer_id, cv.name AS customer_name "
                    "FROM cars_lxcars c "
                    "LEFT JOIN customer cv ON c.c_ow = cv.id "
                    "WHERE REPLACE(c.c_ln, ' ', '') = %s",
                    (kennzeichen_nospace,)
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
                    "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
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


# --- Hauptservice ------------------------------------------------------------

class AnprService:
    """Hauptservice: Liest settings.ini, laedt Config aus DB, startet Kamera-Worker."""

    def __init__(self):
        self.workers = {}
        self.running = True

        # settings.ini lesen
        print(f"Lese {SETTINGS_INI}...")
        self.auth_params = read_settings_ini()
        print(f"Auth-DB: {self.auth_params['user']}@{self.auth_params['host']}:{self.auth_params['port']}/{self.auth_params['dbname']}")

        print("PaddleOCR wird geladen...")
        self.ocr = PaddleOCR(
            use_angle_cls=True, lang='en',
            show_log=False, use_gpu=False,
        )
        print("PaddleOCR bereit.\n")

    def start(self):
        signal.signal(signal.SIGINT, self._shutdown)
        signal.signal(signal.SIGTERM, self._shutdown)

        print("ANPR-Service gestartet.")

        while self.running:
            self._update_config()
            for _ in range(DEFAULT_POLL_INTERVAL):
                if not self.running:
                    break
                time.sleep(1)

        self._stop_all_workers()
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

                        if worker_key not in self.workers:
                            worker = CameraWorker(cam, self.ocr, db_params)
                            worker.start()
                            self.workers[worker_key] = worker
                            print(f"[CONFIG] Worker gestartet: {client['name']} / {cam.get('name', cam['id'])}")

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
