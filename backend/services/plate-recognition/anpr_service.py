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
from datetime import datetime
import json
import os
import socket
import subprocess
import sys
import threading
import time

# PaddleOCR/OpenVINO ist nicht thread-safe: gleichzeitige Aufrufe aus
# verschiedenen Worker-Threads (z.B. beim Config-Reload) führen zu
# "could not execute a primitive". Dieser Lock serialisiert alle OCR-Aufrufe.
_ocr_lock = threading.Lock()
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

# --- Kennzeichen-Präfixe (Quelle: Kraftfahrt-Bundesamt / kennzeichen.js) -----
# Wird fuer die Plaketten-O-Korrektur benoetigt: MOLOTV62 → MOL-TV 62
# Das runde 'O' (TÜV/Prüfplakette) sitzt zwischen Gebietskennzeichen und
# Erkennungsbuchstaben und wird vom OCR gelegentlich als Buchstabe gelesen.

_KZ_PREFIXE: frozenset = frozenset({
    'A','AA','AB','ABG','AC','AE','AIC','AK','AM','AN','ANA','ANG','ANK','AÖ',
    'AP','APD','ARN','ART','AS','ASL','ASZ','AT','AU','AUR','AW','AZ','AZE',
    'B','BA','BAD','BAR','BB','BBG','BBL','BC','BD','BED','BEL','BER','BGL',
    'BI','BIR','BIT','BIW','BL','BLK','BM','BN','BNA','BO','BÖ','BOR','BOT',
    'BP','BRA','BRB','BRG','BS','BSK','BT','BTF','BÜS','BÜZ','BW','BWL','BYL','BZ',
    'C','CA','CB','CE','CHA','CLP','CO','COC','COE','CUX','CW',
    'D','DA','DAH','DAN','DAU','DB','DBR','DD','DE','DEG','DEL','DGF','DH',
    'DL','DLG','DM','DN','DO','DON','DS','DU','DÜW','DW','DZ',
    'E','EA','EB','EBE','ED','EE','EF','EH','EI','EIC','EIL','EIS','EL','EM',
    'EMD','EMS','EN','ER','ERB','ERH','ES','ESA','ESW','EU','EW',
    'F','FB','FD','FDS','FF','FFB','FG','FI','FL','FLÖ','FN','FO','FOR','FR',
    'FRG','FRI','FRW','FS','FT','FTL','FÜ','FW',
    'G','GA','GAP','GC','GDB','GE','GER','GF','GG','GHA','GHC','GI','GL','GM',
    'GMN','GNT','GÖ','GP','GR','GRH','GRM','GRS','GRZ','GS','GT','GTH','GÜ',
    'GUB','GVM','GW','GZ',
    'H','HA','HAL','HAM','HAS','HB','HBN','HBS','HC','HD','HDH','HDL','HE',
    'HEF','HEI','HEL','HER','HET','HF','HG','HGN','HGW','HH','HHM','HI','HIG',
    'HL','HM','HN','HO','HOL','HOM','HOT','HP','HR','HRO','HS','HSK','HST',
    'HU','HV','HVL','HWI','HX','HY','HZ',
    'IGB','IK','IL','IN','IZ',
    'J','JB','JE','JL',
    'K','KA','KB','KC','KE','KEH','KF','KG','KH','KI','KIB','KL','KLE','KLZ',
    'KM','KN','KO','KÖT','KR','KS','KT','KU','KÜN','KUS','KW','KY','KYF',
    'L','LA','LAU','LB','LBS','LBZ','LC','LD','LDK','LDS','LER','LEV','LG',
    'LI','LIB','LIF','LIP','LL','LM','LN','LÖ','LÖB','LOS','LSA','LSN','LSZ',
    'LU','LUK','LWL',
    'M','MA','MAB','MB','MC','MD','ME','MEI','MEK','MER','MG','MGN','MH','MHL',
    'MI','MIL','MK','MKK','ML','MM','MN','MOL','MOS','MQ','MR','MS','MSP',
    'MST','MTK','MÜ','MÜR','MVL','MW','MYK','MZ','MZG',
    'N','NAU','NB','ND','NDH','NE','NEA','NEB','NES','NEW','NF','NH','NI','NK',
    'NL','NM','NMB','NMS','NOH','NOL','NOM','NP','NR','NRW','NU','NVP','NW',
    'NWM','NY','NZ',
    'OA','OAL','OB','OBG','OC','OD','OE','OF','OG','OH','OHA','OHV','OHZ',
    'OK','OL','OPR','OR','OS','OSL','OVL','OVP','OZ',
    'P','PA','PAF','PAN','PB','PCH','PE','PER','PF','PI','PIR','PK','PL','PLÖ',
    'PM','PN','PR','PS','PW','PZ',
    'QFT','QLB',
    'R','RA','RC','RD','RDG','RE','REG','RG','RH','RIE','RL','RM','RN','RO',
    'ROS','ROW','RP','RPL','RS','RSL','RT','RU','RÜD','RÜG','RV','RW','RZ',
    'S','SAD','SAL','SAW','SB','SBG','SBK','SC','SCZ','SDH','SDL','SDT','SE',
    'SEB','SEE','SFA','SFB','SFT','SG','SGH','SH','SHA','SHG','SHK','SHL',
    'SI','SIG','SIM','SK','SL','SLF','SLN','SLS','SLZ','SM','SN','SO','SOK',
    'SÖM','SON','SP','SPB','SPN','SR','SRB','SRO','ST','STA','STB','STD','STL',
    'SU','SÜW','SW','SZ','SZB',
    'TBB','TET','TF','TG','THL','THW','TIR','TO','TÖL','TP','TR','TS','TÜ','TUT',
    'UE','UEM','UER','UH','UL','UM','UN',
    'V','VB','VEC','VER','VIE','VK','VS',
    'W','WAF','WAK','WB','WBS','WDA','WE','WEN','WES','WF','WHV','WI','WIL',
    'WIS','WK','WL','WLG','WM','WMS','WN','WND','WO','WOB','WR','WRN','WSF',
    'WST','WSW','WT','WTM','WÜ','WUG','WUN','WUR','WW','WZL',
    'X','Y',
    'Z','ZE','ZI','ZP','ZR','ZS','ZW','ZZ',
})

# Absteigend nach Länge sortiert — längster Präfix zuerst prüfen (MOL vor MO vor M)
_KZ_PREFIXE_SORTED = sorted(_KZ_PREFIXE, key=len, reverse=True)


def _fix_plakette(text):
    """Entfernt das OCR-erkannte Plaketten-Symbol zwischen Gebietskennzeichen
    und Erkennungsbuchstaben.

    Logik: Nach einem gültigen KFZ-Präfix kommen auf echten Kennzeichen immer
    Buchstaben (A-Z). Jeder andere Charakter an dieser Stelle ist zwingend eine
    Plakette oder ein Artefakt:
      - 'O' / '0'  → einzelne runde Plakette (frontal)
      - '8'        → zwei übereinander gestapelte Plaketten (Heck-Aufnahme:
                     Prüfplakette + Umweltplakette ergeben zusammen eine '8')
      - andere Ziffern/Sonderzeichen → sonstige Artefakte

    Beispiele:
      MOLOTV62  → MOL-TV 62   (O = Prüfplakette, frontal)
      MOL8TJ100 → MOL-TJ 100  (8 = zwei Plaketten übereinander, Heck)
      BIOFR234  → BI-FR 234   (O nach BI, kein gültiger Präfix BIO)
      B8AB1234  → B-AB 1234   (8 nach B)
      BO8FR234  → BO-FR 234   (8 nach BO)

    Gibt None zurück wenn keine sichere Korrektur möglich.
    """
    clean = re.sub(r'[\s\-]', '', text.upper())
    for prefix in _KZ_PREFIXE_SORTED:
        if not clean.startswith(prefix):
            continue
        rest = clean[len(prefix):]
        if not rest:
            continue

        suspect = rest[0]

        # Erkennungsbuchstaben beginnen immer mit A-Z (inkl. Umlaute).
        # Ist der erste Charakter nach dem Präfix kein Buchstabe → sicher Plakette.
        if suspect in 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ':
            # Buchstabe: nur 'O' kann Plakette sein — aber nur wenn prefix+O
            # kein eigenständiger gültiger Präfix ist (z.B. BO, KO, CO, DO...).
            if suspect != 'O':
                continue
            if (prefix + 'O') in _KZ_PREFIXE:
                continue  # 'O' ist Teil des Präfixes, nicht Plakette

        # Plaketten-Zeichen entfernen, Rest validieren
        rest_after = rest[1:]
        rest_spaced = re.sub(r'([A-ZÄÖÜ])(\d)', r'\1 \2', rest_after)
        m = re.match(r'^([A-ZÄÖÜ]{1,2})\s?(\d{1,4}[EH]?)$', rest_spaced)
        if not m:
            continue
        candidate = f"{prefix}-{m.group(1)} {m.group(2)}"
        if is_german_plate(candidate):
            return candidate
    return None


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

# Felder, bei deren Änderung ein Worker-Neustart ausgelöst wird.
# Nur Felder die in __init__ einmalig gelesen werden (rtsp_url, frame_interval etc.).
# Werte die der Worker bei jeder Erkennung frisch aus self.config liest (min_detections,
# min_confidence, cooldown_minutes) brauchen keinen Neustart — nur config-Update.
_CONFIG_WATCH_KEYS = [
    # Nur Settings die wirklich einen Worker-Neustart erfordern:
    # RTSP-Stream und Frame-Rate (kontrollieren die Stream-Verbindung)
    'rtsp_url', 'frame_interval',
    # Aktor-Verbindung (TCP/HTTP/Modbus – Verbindungsdaten)
    'actuator_id', 'actuator_protocol', 'actuator_host', 'actuator_port',
    'actuator_command_open', 'actuator_command_partial',
    # Kalibrierung (wird beim Worker-Start einmalig eingelesen)
    'gate_height_mode', 'calibration_gate_height_cm',
    'calibration_gate_top_y', 'calibration_gate_bottom_y',
]
# Diese Settings werden im laufenden Worker sofort aus self.config gelesen
# (kein Neustart nötig — werden im else-Branch live aktualisiert):
# direction_required, save_snapshots, min_plate_height_px, motion_size_pct,
# excluded_cells, grid_size, ignore_right_pct, ignore_left_pct,
# min_detections, min_confidence, cooldown_minutes, action_type
_CONFIG_LIVE_KEYS = [
    'direction_required', 'direction_filter', 'save_snapshots', 'min_plate_height_px',
    'motion_size_pct', 'excluded_cells', 'grid_size',
    'ignore_right_pct', 'ignore_left_pct',
    'min_detections', 'min_confidence', 'cooldown_minutes', 'action_type',
    'actuator_name', 'actuator_type', 'actuator_max_height_cm',
    'actuator_height_buffer_cm', 'actuator_timeout_seconds',
    'actuator_command_close',
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

_CLAHE = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))


def preprocess_frame(frame):
    """Kontrast per CLAHE erhoehen."""
    lab = cv2.cvtColor(frame, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    l = _CLAHE.apply(l)
    return cv2.cvtColor(cv2.merge([l, a, b]), cv2.COLOR_LAB2BGR)


# ---------------------------------------------------------------------------
# Positionsabhängige OCR-Korrekturtabellen
# Buchstabenfeld (nach Präfix, 1-2 Zeichen): Ziffer wurde als Buchstabe erkannt
_OCR_DIGIT_AS_LETTER = {'0': 'O', '1': 'I', '2': 'Z', '5': 'S', '6': 'G', '8': 'B'}
# Ziffernfeld (nach Erkennungsbuchstaben, 1-4 Zeichen): Buchstabe als Ziffer erkannt
_OCR_LETTER_AS_DIGIT = {
    'O': '0', 'Q': '0', 'D': '0',
    'I': '1', 'L': '1',
    'Z': '2',
    'S': '5',
    'G': '6',
    'B': '8',
}
# Verbotene Erkennungsbuchstaben-Kombinationen (KBA-Regelwerk)
_FORBIDDEN_RECOG = frozenset({'SA', 'SS', 'HJ', 'NS', 'SD'})


def _parse_recognition(rest):
    """Zerlegt den Teil nach dem Präfix in Erkennungsbuchstaben + -ziffern.

    Wendet positionsabhängige OCR-Korrekturen an:
    - Im Buchstabenfeld (1-2 Zeichen): Ziffern die wie Buchstaben aussehen korrigieren
    - Im Ziffernfeld (1-4 Zeichen): Buchstaben die wie Ziffern aussehen korrigieren
    - Optionaler E/H-Suffix am Ende

    Gibt "XY 1234[E|H]" zurück oder None wenn nicht parsierbar.
    """
    if not rest:
        return None

    # E/H-Suffix am Ende erkennen und abtrennen
    suffix = ''
    if len(rest) > 2 and rest[-1] in 'EH':
        candidate_without = rest[:-1]
        # Nur echtes Suffix wenn davor mindestens eine Ziffer kommt
        last_before = candidate_without[-1] if candidate_without else ''
        if last_before.isdigit() or last_before in _OCR_LETTER_AS_DIGIT:
            suffix = rest[-1]
            rest = candidate_without

    # Erkennungsbuchstaben: 1-2 Zeichen (Buchstaben oder OCR-Ziffern die Buchstaben sind)
    # Erkennungsziffern: 1-4 Zeichen (Ziffern oder OCR-Buchstaben die Ziffern sind)
    # Wir probieren n_letters=2 vor n_letters=1 (greedy auf Buchstaben)
    for n_letters in [2, 1]:
        if len(rest) <= n_letters:
            continue
        letter_raw = rest[:n_letters]
        digit_raw  = rest[n_letters:]

        if len(digit_raw) < 1 or len(digit_raw) > 4:
            continue

        # Buchstabenfeld: korrigiere Ziffern die Buchstaben sein sollen
        letters = ''
        for c in letter_raw:
            if c in 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ':
                letters += c
            elif c in _OCR_DIGIT_AS_LETTER:
                letters += _OCR_DIGIT_AS_LETTER[c]
            else:
                letters = None
                break
        if not letters:
            continue

        # Verbotene Erkennungskombinationen (KBA)
        if letters in _FORBIDDEN_RECOG:
            continue

        # Ziffernfeld: korrigiere Buchstaben die Ziffern sein sollen
        digits = ''
        for c in digit_raw:
            if c.isdigit():
                digits += c
            elif c in _OCR_LETTER_AS_DIGIT:
                digits += _OCR_LETTER_AS_DIGIT[c]
            else:
                digits = None
                break
        if not digits:
            continue

        return f"{letters} {digits}{suffix}"

    return None


def _parse_plate_structural(raw):
    """Strukturbasierter Kennzeichen-Parser mit vollständiger Regelanwendung.

    Zwei Pfade:
    A) Bindestrich vorhanden → Präfix steht links, Erkennungsteil rechts.
       Kein Plaketten-Check nötig (Sticker sitzt VOR dem Bindestrich).
    B) Kein Bindestrich → Präfixliste bestimmt die Trennstelle;
       Plakettenzeichen zwischen Präfix und Erkennungsteil werden entfernt.

    Positionsabhängige OCR-Korrektur in beiden Pfaden:
    - Buchstabenfeld (1-2 Zeichen): Ziffern→Buchstaben (0→O, 1→I, 5→S, 6→G, 8→B)
    - Ziffernfeld   (1-4 Zeichen): Buchstaben→Ziffern (O→0, I→1, S→5, G→6, B→8)
    """
    raw_upper = raw.upper().strip()

    # --- Pfad A: Bindestrich als expliziter Separator ---
    if '-' in raw_upper or '–' in raw_upper:
        parts = re.split(r'[-–]', raw_upper, maxsplit=1)
        pre  = re.sub(r'[\s]', '', parts[0])
        post = re.sub(r'[\s]', '', parts[1]) if len(parts) > 1 else ''
        if pre in _KZ_PREFIXE and post:
            recog = _parse_recognition(post)
            if recog:
                candidate = f"{pre}-{recog}"
                if is_german_plate(candidate):
                    return candidate

    # --- Pfad B: Kein Separator → strukturelle Analyse mit Plakettenerkennung ---
    clean = re.sub(r'[\s\-–]', '', raw_upper)

    for prefix in _KZ_PREFIXE_SORTED:
        if not clean.startswith(prefix):
            continue

        rest = clean[len(prefix):]
        if not rest:
            continue

        # Versuch 1: direkt ohne Plaketten-Skip
        recog = _parse_recognition(rest)
        if recog:
            candidate = f"{prefix}-{recog}"
            if is_german_plate(candidate):
                return candidate

        # Versuch 2: ersten Charakter als Plakette überspringen.
        # Die Plakette kann sein:
        #   - Ziffer/Symbol (0, 8, ...) → immer Skip-Kandidat
        #   - 'O' wenn prefix+O kein gültiger Präfix → Skip
        #   - Buchstabe (B, D, Q, ...) wenn Versuch 1 scheiterte → OCR liest
        #     gestapelte Plaketten als buchstabenähnliche Form → Skip falls
        #     prefix+Buchstabe kein gültiger Präfix (verhindert Falsch-Positive
        #     wie BO, KO, CO wo der Buchstabe echtes Gebietskennzeichen ist)
        if len(rest) > 1:
            first = rest[0]
            # Kein Skip wenn prefix+first selbst gültiger Präfix ist (z.B. B+O=BO)
            if (prefix + first) not in _KZ_PREFIXE:
                recog2 = _parse_recognition(rest[1:])
                if recog2:
                    candidate2 = f"{prefix}-{recog2}"
                    if is_german_plate(candidate2):
                        return candidate2

    return None


def normalize_plate(text):
    """Erkannten OCR-Text zu normiertem deutschen Kennzeichen aufbereiten.

    Pipeline:
    1. Basis-Zeichenbereinigung
    2. Strukturbasierter Parser mit KBA-Präfixliste + positionsabhängiger OCR-Korrektur
    3. Regex-Fallback für Fälle ohne erkennbaren Präfix
    4. Finale O→0-Korrektur im Ziffernfeld
    """
    text = text.upper().strip()
    text = text.replace('|', 'I').replace('§', 'S').replace('€', 'E')
    text = text.replace('.', '-').replace('·', '-')
    text = re.sub(r'[^A-ZÄÖÜ0-9\- ]', '', text).strip()

    # Strukturbasierter Parser (primär)
    result = _parse_plate_structural(text)
    if result:
        return result

    # Regex-Fallback (für Kennzeichen ohne erkennbaren KBA-Präfix oder Edge-Cases)
    clean = re.sub(r'[\s\-]', '', text)
    m = re.match(r'^([A-ZÄÖÜ]{1,3})([A-ZÄÖÜ]{1,2})(\d{1,4}[EH]?)$', clean)
    if m:
        text = f"{m.group(1)}-{m.group(2)} {m.group(3)}"
    else:
        m = re.match(r'^([A-ZÄÖÜ]{1,3})\s+([A-ZÄÖÜ]{1,2})\s+(\d{1,4}[EH]?)$', text)
        if m:
            text = f"{m.group(1)}-{m.group(2)} {m.group(3)}"

    # Leerzeichen zwischen Buchstaben und Ziffern sicherstellen
    text = re.sub(r'([A-ZÄÖÜ])(\d)', r'\1 \2', text)

    # O→0 im Ziffernfeld (Fallback-Pfad)
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


# --- Frame-Reader-Thread ------------------------------------------------------

class FrameReader(threading.Thread):
    """Liest RTSP-Frames kontinuierlich im Hintergrund und behält nur den neuesten.

    Löst das TCP-Buffer-Stau-Problem: Während PaddleOCR verarbeitet, blockiert
    der Haupt-Thread für 0,5–2 Sekunden. Ohne diesen Thread stauen sich in dieser
    Zeit Dutzende Frames im OS-TCP-Buffer (Recv-Q). Der nächste cap.read() gibt
    dann einen Frame zurück, der Sekunden in der Vergangenheit liegt – das Fahrzeug
    ist schon längst vorbei.

    Mit FrameReader liest ein dedizierter Thread alle Frames sofort (verwirft
    sofort das Vorherige), der Verarbeitungs-Thread holt immer den aktuellen Frame.
    """

    def __init__(self, rtsp_url, max_width=1280):
        super().__init__(daemon=True)
        self.rtsp_url = rtsp_url
        self.max_width = max_width
        self._frame = None
        self._ts = 0.0
        self._opened = False
        self._lock = threading.Lock()
        self.running = True

    def run(self):
        cap = cv2.VideoCapture(self.rtsp_url, cv2.CAP_FFMPEG)
        cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        self._opened = cap.isOpened()
        while self.running and self._opened:
            ret, frame = cap.read()
            if not ret:
                self._opened = False
                break
            # Hochauflösende Streams (4MP+) direkt nach dem Dekodieren runterskalieren.
            # Reduziert RAM-Verbrauch und YOLO-Inferenzzeit drastisch; für ANPR reicht 1280px.
            if self.max_width and frame.shape[1] > self.max_width:
                scale = self.max_width / frame.shape[1]
                frame = cv2.resize(frame, (self.max_width, int(frame.shape[0] * scale)),
                                   interpolation=cv2.INTER_LINEAR)
            with self._lock:
                self._frame = frame
                self._ts = time.time()
            # ~30fps-Cap: verhindert Burst-Verarbeitung aus dem OpenCV-internen Frame-Buffer.
            # cap.read() blockiert NICHT zuverlässig auf neue Netzwerk-Frames — ohne Sleep
            # werden gepufferte Frames im Burst verarbeitet, was den CPU-Verbrauch verdoppelt.
            time.sleep(0.033)
        cap.release()

    def get_latest(self):
        """Gibt (frame_copy, timestamp) des neuesten Frames zurück, oder (None, 0.0)."""
        with self._lock:
            if self._frame is None:
                return None, 0.0
            return self._frame.copy(), self._ts

    @property
    def stream_ok(self):
        return self._opened and self.is_alive()


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
        self.detection_frames = {}   # track_key -> [(area, frame, ts, box), ...]
        self.detection_plates = {}   # track_key -> [(normalized_plate, confidence), ...]

        self.cam_id = int(config.get('id', 0))
        self.name_str = config.get('name', f'Kamera #{self.cam_id}')
        self.rtsp_url = config.get('rtsp_url', '')
        self.interval = float(config.get('frame_interval') or 0.5)
        self.min_conf = float(config.get('min_confidence') or 0.60)
        self.min_det = int(config.get('min_detections') or 3)

        self.reported = {}  # track_key -> timestamp

        # Debug-Snapshots: Near-miss-Frames bei abgelehnten Erkennungen speichern
        self.debug_snapshots = str(config.get('_debug_snapshots', '')).lower() in ('1', 't', 'true')
        self._debug_dedup = {}  # (text_norm, reason) -> last_save_ts

        # Erkannte Boxen fuer MJPEG-Overlay (vom Erkennungs-Thread geschrieben)
        self._detected_boxes = []  # [(box, plate_text, confidence), ...]
        self._source_size = (0, 0)  # (width, height) des Kamera-Frames

        # Health-Tracking: Zähler seit letztem Heartbeat
        self._stats = {'frames': 0, 'detections': 0, 'skipped': 0}
        self._last_heartbeat = time.time()

    def _write_health(self, event, message=None, frames=None, detections=None, skipped=None):
        """Health-Ereignis in die DB schreiben (non-blocking, ignoriert DB-Fehler)."""
        try:
            conn = psycopg2.connect(**self.db_params)
            conn.autocommit = True
            with conn.cursor() as cur:
                cur.execute(
                    "INSERT INTO anpr_health_lxcars "
                    "(camera_id, event, message, frames, detections, skipped) "
                    "VALUES (%s, %s, %s, %s, %s, %s)",
                    (self.cam_id, event, message, frames, detections, skipped)
                )
                if event == 'heartbeat':
                    cur.execute(
                        "DELETE FROM anpr_health_lxcars WHERE ts < now() - interval '7 days'"
                    )
            conn.close()
        except Exception as e:
            print(f"[{self.name_str}] Health-Log Fehler: {e}")

    def run(self):
        self._write_health('start', message=self.rtsp_url[:100])
        print(f"[{self.name_str}] Starte Stream: {self.rtsp_url}")
        while self.running:
            try:
                self._process_stream()
            except Exception as e:
                msg = str(e)[:200]
                print(f"[{self.name_str}] Fehler: {e}")
                self._write_health('error', message=msg)
            if self.running:
                print(f"[{self.name_str}] Reconnect in 5s...")
                self._write_health('reconnect')
                time.sleep(5)

    def stop(self):
        self.running = False

    def _process_stream(self):
        reader = FrameReader(self.rtsp_url)
        reader.start()

        # Warten bis der erste Frame ankommt (max. 15s)
        deadline = time.time() + 15
        while time.time() < deadline:
            if not reader.is_alive() or not self.running:
                break
            frame, _ = reader.get_latest()
            if frame is not None:
                break
            time.sleep(0.2)

        frame, _ = reader.get_latest()
        if frame is None:
            print(f"[{self.name_str}] Stream konnte nicht geöffnet werden")
            self._write_health('error', message='Stream konnte nicht geöffnet werden')
            reader.running = False
            return

        # Kamera-Auflösung beim ersten Frame merken
        self._source_size = (frame.shape[1], frame.shape[0])
        print(f"[{self.name_str}] Auflösung: {frame.shape[1]}x{frame.shape[0]}")

        last_process = 0.0
        while self.running:
            if not reader.stream_ok:
                break

            frame, frame_ts = reader.get_latest()
            if frame is None:
                time.sleep(0.1)
                continue

            now = time.time()
            if now - last_process < self.interval:
                time.sleep(min(0.05, self.interval / 4))
                continue

            # Stream-Stall erkennen: Frame älter als 10s
            if frame_ts > 0 and now - frame_ts > 10:
                print(f"[{self.name_str}] Stream-Stall: Frame {now - frame_ts:.1f}s alt → Reconnect")
                break

            last_process = now

            self._stats['frames'] += 1

            # Heartbeat alle 60s
            if now - self._last_heartbeat >= DEFAULT_POLL_INTERVAL:
                self._write_health(
                    'heartbeat',
                    frames=self._stats['frames'],
                    detections=self._stats['detections'],
                    skipped=self._stats['skipped'],
                )
                self._stats = {'frames': 0, 'detections': 0, 'skipped': 0}
                self._last_heartbeat = now

            enhanced = preprocess_frame(frame)
            plates = self._recognize(enhanced)
            if not plates:
                plates = self._recognize(frame)

            # Erkannte Boxen fuer MJPEG-Overlay aktualisieren
            self._detected_boxes = [
                (p['box'], p['plate'], p['confidence']) for p in plates
            ]

            for p in plates:
                raw_key = re.sub(r'[\s\-]', '', p['plate'])

                # Fuzzy-Merge: OCR liest dasselbe Kennzeichen oft leicht unterschiedlich
                # (O↔0, I↔1, H↔M, etc.). Wenn ein ähnlicher Key (Edit-Distanz <= 1)
                # mit frischen Messwerten (<20s) existiert, diesen Key weiternutzen
                # statt einen neuen Zähler anzulegen.
                track_key = raw_key
                for existing_key, hist_list in self.size_history.items():
                    if existing_key == raw_key:
                        break
                    if not hist_list:
                        continue
                    if now - hist_list[-1][0] > 20:
                        continue
                    if _edit_distance(raw_key, existing_key) <= 1:
                        track_key = existing_key
                        if raw_key != existing_key:
                            print(f"[{self.name_str}] Fuzzy-Merge: {raw_key} → {existing_key}")
                        break

                hist = self.size_history.get(track_key, [])
                detection_count = len(hist)

                # Frame + Plate-Reading sammeln für späteres Multi-Frame-Voting
                current_area = box_area(p['box'])
                if track_key not in self.detection_frames:
                    self.detection_frames[track_key] = []
                    self.detection_plates[track_key]  = []
                self.detection_frames[track_key].append((current_area, frame.copy(), now, p['box']))
                self.detection_plates[track_key].append((p['plate'], p['confidence']))
                if len(self.detection_frames[track_key]) > MAX_SNAPSHOTS * 3:
                    self.detection_frames[track_key].pop(0)
                    self.detection_plates[track_key].pop(0)

                # direction_filter steuert den Richtungsfilter:
                # 'off'        → kein Filter (dir=None/in/out alle akzeptiert)
                # 'moving'     → Richtung muss erkennbar sein (dir='in' oder 'out', nicht None)
                #                → erkennt Ein- UND Ausfahrten, blockiert seitlich vorbeifahrende
                # 'approaching'→ nur wenn Kennzeichen wächst (dir='in')
                #                → nur Fahrzeuge die auf die Kamera zufahren
                # Legacy: direction_required=True entspricht 'approaching'
                direction_filter = self.config.get('direction_filter') or (
                    'approaching' if self.config.get('direction_required', True) else 'off'
                )
                direction = p.get('direction')
                if direction_filter == 'off':
                    dir_ok = True
                    movement_ok = self._is_moving(hist)
                elif direction_filter == 'moving':
                    dir_ok = direction in ('in', 'out')
                    movement_ok = True
                else:  # 'approaching'
                    dir_ok = direction == 'in'
                    movement_ok = self._is_moving(hist)
                min_det = int(self.config.get('min_detections') or 3)
                # Bei bestätigter Richtung reichen 2 Erkennungen — Fahrzeuge sind
                # oft nur 1s sichtbar (≈2 OCR-Zyklen bei 0.4-0.5s pro Aufruf).
                # Gilt für 'in' UND 'out' damit auch Ausfahrten erkannt werden.
                # min_det wird dennoch respektiert wenn er ≤ 2 gesetzt ist.
                effective_min_det = min(2, min_det) if dir_ok and direction in ('in', 'out') else min_det

                # Debug-Ausgabe pro Erkennung
                if hist:
                    xs_d = [h[2] for h in hist]
                    ys_d = [h[3] for h in hist]
                    pos_delta = max(max(xs_d) - min(xs_d), max(ys_d) - min(ys_d)) if len(xs_d) > 1 else 0
                    areas_d = [h[1] for h in hist]
                    size_ratio = (max(areas_d) / min(areas_d)) if len(areas_d) >= 2 and min(areas_d) > 0 else 1.0
                    eff_note = f'→eff={effective_min_det}' if effective_min_det != min_det else ''
                    status = 'MELDEN' if (detection_count >= effective_min_det and dir_ok and movement_ok) else \
                             'Standfahrzeug' if not movement_ok else \
                             f'warte(dir={direction})'
                    threshold = self._size_ratio()
                    print(f"[{self.name_str}] DBG {track_key}: "
                          f"n={detection_count}(min={min_det}{eff_note}) h={p.get('plate_height_px', 0)}px "
                          f"area={int(current_area)} dir={direction} filter={direction_filter} "
                          f"moving={movement_ok} pos_Δ={int(pos_delta)}px "
                          f"size_ratio={size_ratio:.2f}(>{threshold:.2f}) → {status}")

                if detection_count >= effective_min_det and dir_ok and movement_ok:
                    cooldown = int(self.config.get('cooldown_minutes') or 5) * 60
                    last_report = self.reported.get(track_key, 0)
                    if now - last_report < cooldown:
                        # Ausfahrt: Cooldown zurücksetzen damit Rückeinfahrt sofort erkannt wird
                        if direction == 'out':
                            print(f"[{self.name_str}] {track_key}: Ausfahrt erkannt → Cooldown zurückgesetzt")
                            del self.reported[track_key]
                            self.size_history[track_key] = []
                            self.detection_frames.pop(track_key, None)
                            self.detection_plates.pop(track_key, None)
                        else:
                            self._stats['skipped'] += 1
                        continue
                    # Fuzzy-Check: aehnliches Kennzeichen (Edit-Distanz <= 2) kuezer zurueck
                    if _recently_reported_similar(track_key, self.reported, cooldown, now):
                        print(f"[{self.name_str}]     Ähnliches Kennzeichen kürzlich gemeldet → ignoriert")
                        self._stats['skipped'] += 1
                        continue

                    self.reported[track_key] = now
                    saved_frames  = self.detection_frames.pop(track_key, [])
                    saved_readings = self.detection_plates.pop(track_key, [])
                    self.size_history[track_key] = []
                    self._report_detection(p, saved_frames, saved_readings)

                elif detection_count >= min_det:
                    if not movement_ok:
                        # Standfahrzeug: Frames nicht weiter ansammeln
                        if detection_count == min_det:
                            areas_log = [int(h[1]) for h in hist]
                            print(f"[{self.name_str}] {track_key}: Standfahrzeug erkannt "
                                  f"(n={detection_count}, Größen: {areas_log}) → ignoriert")
                        self.detection_frames.pop(track_key, None)
                        self.detection_plates.pop(track_key, None)
                    elif direction == 'out':
                        # Fahrzeug fährt weg → History aufräumen damit späteres Einfahren
                        # nicht durch alte 'out'-Messungen blockiert wird
                        print(f"[{self.name_str}] {track_key}: fährt weg → History zurückgesetzt")
                        self.size_history[track_key] = []
                        self.detection_frames.pop(track_key, None)
                        self.detection_plates.pop(track_key, None)
                    elif direction is None and detection_count == min_det:
                        areas_log = [int(h[1]) for h in hist]
                        print(f"[{self.name_str}] {track_key}: {detection_count} Erkennungen, "
                              f"noch kein Bewegungstrend (Größen: {areas_log})")

            # Speicher aufräumen: inaktive Kennzeichen entfernen
            for key in list(self.detection_frames):
                if not self.size_history.get(key):
                    del self.detection_frames[key]
                    self.detection_plates.pop(key, None)

        reader.running = False

    def _recognize(self, frame):
        with _ocr_lock:
            result = self.ocr.ocr(frame, cls=True)
        detections = []
        if not result or not result[0]:
            return detections

        lines = [(line[0], line[1]) for line in result[0]]

        # Plakette-Filter: Die runde Prüfplakette auf dem Kennzeichen ist deutlich
        # kleiner als die echten Buchstaben. PaddleOCR erkennt sie als 'O' und fügt
        # sie in den Kennzeichentext ein (z.B. "MOLOTV62").
        # Lösung: Boxen deren Höhe unter 60% der medianen Boxhöhe liegt, auf '-'
        # reduzieren. Das merge_nearby_texts-Regex ignoriert '-' sowieso, und
        # normalize_plate hat damit nichts zu tun.
        if lines:
            heights = [box_height(b) for b, _ in lines]
            median_h = sorted(heights)[len(heights) // 2]
            filtered = []
            for box, (text, conf) in lines:
                h = box_height(box)
                if h < median_h * 0.60 and len(text.strip()) <= 2:
                    # Zu kleine Box → wahrscheinlich Plakette oder Sonderzeichen, ignorieren
                    pass
                else:
                    filtered.append((box, (text, conf)))
            lines = filtered if filtered else lines

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
                # Near-miss speichern: Confidence ok, aber kein gültiges Kennzeichen.
                # Nur wenn der Text plate-ähnlich ist (4–12 Zeichen, Buchstaben + Ziffern),
                # um triviale Fehlerkennungen (Straßenschilder, Karosserieschriften) zu filtern.
                raw_stripped = re.sub(r'\s', '', text)
                if (4 <= len(raw_stripped) <= 12
                        and re.search(r'[A-Z]', text.upper())
                        and re.search(r'\d', text)):
                    self._save_debug_snapshot(frame, box, text, normalized, conf, 'format')
                continue

            # Kennzeichen-Mindestgröße prüfen (zu weit weg = zu klein = ignorieren)
            plate_h_px = int(box_height(box))
            min_plate_h = int(self.config.get('min_plate_height_px') or 0)
            if min_plate_h and plate_h_px < min_plate_h:
                self._save_debug_snapshot(frame, box, text, normalized, conf, 'klein')
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

    def _size_ratio(self):
        """Liest den konfigurierten Bewegungs-Schwellenwert als Faktor (z.B. 1.20 für 20%)."""
        pct = int(self.config.get('motion_size_pct') or 20)
        return 1.0 + max(5, min(100, pct)) / 100.0

    def _detect_direction(self, history):
        """Richtungserkennung anhand des Flächenwachstums des Kennzeichens.

        Ab 2 Messpunkten: strengerer Schwellwert (1.5× statt konfiguriert) damit
        kurz sichtbare Fahrzeuge (1-2 OCR-Zyklen) trotzdem erkannt werden.
        Ab 3 Messpunkten: konfigurierter Schwellwert (Standard 1.20×).
        Ab 4 Messpunkten: Mittelwert des ersten/letzten Zweier-Blocks.
        """
        if len(history) < 2:
            return None
        areas = [h[1] for h in history]
        if len(areas) == 2:
            # Nur 2 Punkte: deutlich strengerer Schwellwert (Fläche muss sich
            # mindestens verdoppelt haben bzw. halbiert für 'out')
            ratio = areas[1] / areas[0] if areas[0] > 0 else 1.0
            if ratio >= 1.5:
                return 'in'
            elif ratio <= 1.0 / 1.5:
                return 'out'
            return None
        split = 1 if len(areas) < 4 else 2
        first_avg = np.mean(areas[:split])
        last_avg = np.mean(areas[-split:])
        if first_avg == 0:
            return None
        ratio = last_avg / first_avg
        threshold = self._size_ratio()
        if ratio >= threshold:
            return 'in'
        elif ratio <= 1 / threshold:
            return 'out'
        return None

    def _is_moving(self, history):
        """True wenn das Fahrzeug sich erkennbar bewegt (kein Standfahrzeug).

        Prüft Positionsänderung des Box-Mittelpunkts UND Größenwachstum.
        Ein gerades Auffahren auf die Kamera hat kaum Positionsänderung,
        aber deutliches Größenwachstum — beides wird akzeptiert.
        Ab 3 Messpunkten kann der Größentrend berechnet werden.
        """
        if len(history) < 2:
            return False
        xs = [h[2] for h in history]
        ys = [h[3] for h in history]
        pos_delta = max(max(xs) - min(xs), max(ys) - min(ys))
        if pos_delta >= MIN_MOVEMENT_PX:
            return True
        if len(history) >= 2:
            areas = [h[1] for h in history]
            if len(areas) == 2:
                # Strengerer Schwellwert bei nur 2 Punkten (1.5× statt konfiguriert)
                ratio = areas[1] / areas[0] if areas[0] > 0 else 1.0
                if ratio >= 1.5 or ratio <= 1.0 / 1.5:
                    return True
            else:
                split = 1 if len(areas) < 4 else 2
                first_avg = np.mean(areas[:split])
                last_avg = np.mean(areas[-split:])
                if first_avg > 0 and last_avg / first_avg >= self._size_ratio():
                    return True
        return False

    def _save_debug_snapshot(self, frame, box, text_raw, text_norm, conf, reason):
        """Near-miss-Frame fuer Debugging speichern.

        Wird aufgerufen wenn ein Kandidat erkannt aber abgelehnt wurde:
        - 'format': Confidence ok, aber kein gueltiges deutsches Kennzeichen
        - 'klein':  Gueltiges Kennzeichen, aber Bildhoehe unter Mindestgroesse
        Dedup: gleicher (text_norm, reason) maximal einmal pro 30 Sekunden.
        """
        if not self.debug_snapshots:
            return
        now = time.time()
        dedup_key = (text_norm, reason)
        if now - self._debug_dedup.get(dedup_key, 0) < 30:
            return
        self._debug_dedup[dedup_key] = now
        self._debug_dedup = {k: v for k, v in self._debug_dedup.items() if now - v < 300}

        try:
            snapshot_dir = os.path.join(
                BACKEND_DIR, 'data', self.db_params['database'], 'anpr-debug-snapshots'
            )
            os.makedirs(snapshot_dir, exist_ok=True)
            try:
                os.chmod(snapshot_dir, 0o2775)  # rwxrwsr-x → www-data kann Dateien löschen
            except OSError:
                pass

            annotated = frame.copy()
            if box:
                pts = np.array(box, dtype=np.int32)
                cv2.polylines(annotated, [pts], True, (0, 120, 255), 3)
                x = int(pts[:, 0].min())
                y = max(int(pts[:, 1].min()) - 12, 28)
                label = f"{text_raw}  {conf:.0%}  [{reason}]"
                if text_norm and text_norm != text_raw:
                    label = f"{text_raw} → {text_norm}  {conf:.0%}  [{reason}]"
                (tw, th), _ = cv2.getTextSize(label, cv2.FONT_HERSHEY_SIMPLEX, 0.9, 2)
                cv2.rectangle(annotated, (x, y - th - 6), (x + tw + 8, y + 6), (0, 0, 0), -1)
                cv2.putText(annotated, label, (x + 4, y),
                            cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0, 160, 255), 2)

            h, w = annotated.shape[:2]
            if w > 1280:
                scale = 1280 / w
                annotated = cv2.resize(annotated, (int(w * scale), int(h * scale)))
                h, w = annotated.shape[:2]

            ts_str = datetime.now().strftime('%d.%m.%Y  %H:%M:%S')
            fnt = cv2.FONT_HERSHEY_SIMPLEX
            (tw2, th2), _ = cv2.getTextSize(ts_str, fnt, 0.65, 2)
            tx, ty = 10, h - 10
            cv2.rectangle(annotated, (tx - 4, ty - th2 - 6), (tx + tw2 + 4, ty + 4), (0, 0, 0), -1)
            cv2.putText(annotated, ts_str, (tx, ty), fnt, 0.65, (255, 255, 255), 2)

            ts_file = datetime.now().strftime('%Y%m%d_%H%M%S_%f')
            safe_norm = re.sub(r'[^A-Z0-9]', '', text_norm.upper())[:15]
            fname = f"{self.cam_id}_{ts_file}_{reason}_{safe_norm}.jpg"
            fpath = os.path.join(snapshot_dir, fname)
            cv2.imwrite(fpath, annotated, [cv2.IMWRITE_JPEG_QUALITY, 85])
            try:
                os.chmod(fpath, 0o664)  # rw-rw-r-- → www-data kann Datei löschen
            except OSError:
                pass

            # Maximal 500 Dateien behalten (älteste löschen)
            files = sorted(f for f in os.listdir(snapshot_dir) if f.endswith('.jpg'))
            if len(files) > 500:
                for old in files[:len(files) - 500]:
                    try:
                        os.unlink(os.path.join(snapshot_dir, old))
                    except OSError:
                        pass
        except Exception as e:
            print(f"[{self.name_str}] Debug-Snapshot Fehler: {e}")

    def _save_snapshots(self, frames, plate_info, detection_id):
        """MAX_SNAPSHOTS repräsentative Frames als nummerierte JPEGs speichern.

        Aus den gesammelten Frames werden MAX_SNAPSHOTS gleichmäßig verteilte
        ausgewählt (weit → mittel → nah), sortiert nach Bounding-Box-Fläche.
        """
        snapshot_dir = os.path.join(
            BACKEND_DIR, 'data', self.db_params['database'], 'anpr-snapshots'
        )
        os.makedirs(snapshot_dir, exist_ok=True)

        # Chronologisch sortieren nach Aufnahme-Zeitstempel (Index 2).
        # Früher: Sortierung nach Fläche (klein→groß) war falsch für ausfahrende
        # Fahrzeuge — dort nimmt die Fläche über die Zeit ab, was die Reihenfolge umkehrte.
        sorted_frames = sorted(frames, key=lambda x: x[2] if len(x) > 2 else x[0])

        # MAX_SNAPSHOTS gleichmäßig verteilt auswählen
        n = len(sorted_frames)
        if n > MAX_SNAPSHOTS:
            indices = [int(round(i * (n - 1) / (MAX_SNAPSHOTS - 1))) for i in range(MAX_SNAPSHOTS)]
            sorted_frames = [sorted_frames[i] for i in indices]

        for i, entry in enumerate(sorted_frames, start=1):
            # Format: (area, frame, ts, box) — box ist frame-spezifisch
            _, frame = entry[0], entry[1]
            frame_ts = entry[2] if len(entry) > 2 else None
            frame_box = entry[3] if len(entry) > 3 else plate_info.get('box')
            annotated = frame.copy()

            if frame_box:
                pts = np.array(frame_box, dtype=np.int32)
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
                h, w = annotated.shape[:2]

            # Timestamp unten links einblenden
            if frame_ts:
                ts_str = datetime.fromtimestamp(frame_ts).strftime('%d.%m.%Y  %H:%M:%S')
                font = cv2.FONT_HERSHEY_SIMPLEX
                fscale, fthick = 0.65, 2
                (tw2, th2), _ = cv2.getTextSize(ts_str, font, fscale, fthick)
                tx, ty = 10, h - 10
                cv2.rectangle(annotated, (tx - 4, ty - th2 - 6), (tx + tw2 + 4, ty + 4), (0, 0, 0), -1)
                cv2.putText(annotated, ts_str, (tx, ty), font, fscale, (255, 255, 255), fthick)

            path = os.path.join(snapshot_dir, f"{detection_id}_{i}.jpg")
            cv2.imwrite(path, annotated, [cv2.IMWRITE_JPEG_QUALITY, 85])

        print(f"[{self.name_str}]     {len(sorted_frames)} Snapshot(s) gespeichert (ID {detection_id})")

    @staticmethod
    def _vote_best_plate(readings):
        """Multi-Frame-Mehrheitsentscheid über das wahrscheinlichste Kennzeichen.

        Strategie:
        1. Alle Readings normieren (ohne Separator), nach Häufigkeit clustern
        2. Zentrum des häufigsten Clusters bestimmen (geringste Edit-Summe)
        3. Zeichen-genaues Majority-Voting an jeder Position
        4. Confidence-gewichtete Auswahl bei Gleichstand

        Beispiel: ['MOLTJ100', 'MOLTJ10O', 'MOLTK100'] (3 Frames)
          Position 6: '1' 2×, '1' → bleibt '1'
          Position 7: '0' 2×, 'O' 1× → '0'
          Position 4: 'J' 2×, 'K' 1× → 'J'
          → 'MOLTJ100' → 'MOL-TJ 100'
        """
        if not readings:
            return None
        if len(readings) == 1:
            return normalize_plate(readings[0][0])

        # Normalisiere alle Readings (ohne Separatoren) mit Confidence
        norm_readings = []
        for plate, conf in readings:
            n = re.sub(r'[\s\-]', '', plate.upper())
            norm_readings.append((n, conf))

        # Häufigstes normalisiertes Kennzeichen als Anker
        from collections import Counter
        counts = Counter(n for n, _ in norm_readings)
        anchor = counts.most_common(1)[0][0]

        # Nahe Readings (Edit-Distanz ≤ 2 zum Anker) als Voting-Basis
        close = [(n, c) for n, c in norm_readings if _edit_distance(n, anchor) <= 2]
        if not close:
            close = norm_readings

        # Wenn alle einig → direkt normalisieren
        if len(set(n for n, _ in close)) == 1:
            return normalize_plate(close[0][0])

        # Zeichen-genaues Voting (nur möglich wenn alle gleiche Länge)
        lengths = [len(n) for n, _ in close]
        if len(set(lengths)) == 1:
            voted = ''
            for i in range(lengths[0]):
                char_votes: dict = {}
                for n, conf in close:
                    c = n[i]
                    char_votes[c] = char_votes.get(c, 0.0) + conf  # confidence-gewichtet
                voted += max(char_votes, key=char_votes.get)
            # Gewählten String normalisieren (positionskorrektur anwenden)
            return normalize_plate(voted)

        # Fallback: Anker normalisieren
        return normalize_plate(anchor)

    def _fuzzy_car_lookup(self, cur, kz_norm):
        """Sucht ein Fahrzeug in der DB mit Toleranz für typische OCR-Verwechslungen.

        Strategie (in Reihenfolge steigender Aufwände):
        1. Gezielte Einzel-Substitutionen: H↔W, I↔1, 0↔O, S↔5, B↔8 im gesamten String
        2. Edit-Distanz ≤ 1 gegen alle Kennzeichen mit gleichem Präfix (1-3 Zeichen)
           → begrenzt den Suchraum, verhindert Falsch-Positive über Präfixgrenzen

        Gibt (row, correction_string) oder (None, None) zurück.
        """
        # Phase 1: Bekannte paarweise OCR-Verwechslungen
        swap_pairs = [
            # Einzelne Zeichen-Swaps die häufig vorkommen
            [('H', 'W'), ('W', 'H')],          # H↔W (visuell ähnlich)
            [('I', '1'), ('1', 'I')],           # I↔1
            [('O', '0'), ('0', 'O')],           # O↔0 (im gesamten String)
            [('S', '5'), ('5', 'S')],           # S↔5
            [('B', '8'), ('8', 'B')],           # B↔8
            [('G', '6'), ('6', 'G')],           # G↔6
            [('Z', '2'), ('2', 'Z')],           # Z↔2
        ]
        tested = {kz_norm}
        for pair in swap_pairs:
            for old, new in pair:
                if old in kz_norm:
                    alt = kz_norm.replace(old, new)
                    if alt not in tested:
                        tested.add(alt)
                        cur.execute(
                            "SELECT c.c_id, c.c_ow AS customer_id, cv.name AS customer_name "
                            "FROM cars_lxcars c LEFT JOIN customer cv ON c.c_ow = cv.id "
                            "WHERE REPLACE(REPLACE(UPPER(c.c_ln), ' ', ''), '-', '') = %s",
                            (alt,)
                        )
                        row = cur.fetchone()
                        if row:
                            return row, alt

        # Phase 2: Edit-Distanz ≤ 1 gegen Kennzeichen mit gleichem Präfix
        # Präfix = erste 1-3 Buchstaben (entspricht dem Gebietskennzeichen)
        prefix3 = re.sub(r'[^A-ZÄÖÜ]', '', kz_norm)[:3]
        if not prefix3:
            return None, None

        # Alle Kennzeichen laden die mit demselben Prefix beginnen
        cur.execute(
            "SELECT c.c_id, c.c_ow AS customer_id, cv.name AS customer_name, "
            "REPLACE(REPLACE(UPPER(c.c_ln), ' ', ''), '-', '') AS kz "
            "FROM cars_lxcars c LEFT JOIN customer cv ON c.c_ow = cv.id "
            "WHERE UPPER(c.c_ln) LIKE %s",
            (prefix3[:2] + '%',)   # mind. 2 Anfangsbuchstaben als Filter
        )
        candidates = cur.fetchall()

        best_row, best_kz, best_dist = None, None, 2  # max Distanz = 1
        for row in candidates:
            db_kz = row['kz']
            if db_kz == kz_norm:
                continue  # exakter Match wurde schon gefunden
            dist = _edit_distance(kz_norm, db_kz)
            if dist < best_dist:
                best_dist = dist
                best_row = row
                best_kz = db_kz

        if best_row:
            return best_row, best_kz
        return None, None

    def _report_detection(self, plate_info, frames, readings=None):
        """Erkennung direkt in die DB schreiben und Snapshots speichern.

        readings: Liste von (normalized_plate, confidence) aller gesammelten Frames.
        Wird für Multi-Frame-Voting genutzt um das wahrscheinlichste Kennzeichen
        aus allen Frames zu ermitteln statt nur den letzten Frame zu verwenden.
        """
        # Multi-Frame-Voting: alle gesammelten Lesungen gemeinsam auswerten
        if readings and len(readings) > 1:
            voted = self._vote_best_plate(readings)
            if voted and is_german_plate(voted):
                if voted != plate_info['plate']:
                    print(f"[{self.name_str}]     Voting: {plate_info['plate']} → {voted} "
                          f"(aus {len(readings)} Frames)")
                plate_info = dict(plate_info)
                plate_info['plate'] = voted
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

                # Blacklist + open_order_skip-Setting in einer Abfrage laden
                cur.execute(
                    "SELECT key, value FROM defaults_oserp "
                    "WHERE key IN ('anpr_blacklist', 'anpr_open_order_skip')"
                )
                settings = {r['key']: r['value'] for r in cur.fetchall()}

                bl_val = settings.get('anpr_blacklist', '')
                if bl_val:
                    blacklist = [re.sub(r'[\s\-]', '', p).upper()
                                 for p in bl_val.split(',') if p.strip()]
                    if kennzeichen_normalized in blacklist:
                        print(f"[{self.name_str}]     Blacklist → ignoriert")
                        conn.close()
                        return

                open_order_skip = settings.get('anpr_open_order_skip', '0') in ('1', 't', 'true')

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

                # Fallback: OCR-Verwechslungen systematisch auflösen.
                # Zuerst gezielte Einzel-Varianten, dann Fuzzy-Abgleich gegen DB-Prefix-Gruppe.
                if not car:
                    car, correction = self._fuzzy_car_lookup(cur, kennzeichen_normalized)
                    if car:
                        print(f"[{self.name_str}]     Fuzzy-Korrektur: {kennzeichen_normalized} → {correction}")

                c_id = car['c_id'] if car else None
                customer_id = car['customer_id'] if car else None

                # Offenen Auftrag pruefen (nur wenn skip aktiviert)
                has_open_order = False
                if c_id and open_order_skip:
                    cur.execute(
                        "SELECT 1 FROM oe "
                        "JOIN oe_ext ON oe.id = oe_ext.oe_id "
                        "WHERE oe_ext.c_id = %s AND oe.closed IS NOT TRUE "
                        "LIMIT 1",
                        (c_id,)
                    )
                    has_open_order = cur.fetchone() is not None

                # Aktion bestimmen
                action_type = self.config.get('action_type', 'infobar')
                action_taken = 'none'
                if not has_open_order:
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
                self._stats['detections'] += 1
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
        self._dead_databases = set()  # DBs die nicht existieren — dauerhaft überspringen

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
                dbname = client.get('dbname', '')
                if dbname in self._dead_databases:
                    continue
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

                    # Aktive Kameras + globales Debug-Snapshots-Flag laden
                    with comp_conn.cursor(cursor_factory=psycopg2.extras.DictCursor) as cur:
                        cur.execute(
                            "SELECT value FROM defaults_oserp "
                            "WHERE key = 'anpr_debug_snapshots'"
                        )
                        row = cur.fetchone()
                        debug_snapshots = row and str(row['value']).lower() in ('1', 't', 'true')

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
                        for cam in cameras:
                            cam['_debug_snapshots'] = debug_snapshots

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
                            # Worker abgestuerzt → neu starten (kein join nötig, Thread ist bereits tot)
                            print(f"[CONFIG] Worker neu starten (abgestuerzt): {cam.get('name', cam['id'])}")
                            worker = CameraWorker(cam, self.ocr, db_params)
                            worker.start()
                            self.workers[worker_key] = worker

                        elif _config_changed(existing.config, cam):
                            # Config geaendert → Worker neu starten.
                            # join() MUSS vor dem Start des neuen Workers stehen:
                            # PaddleOCR/OpenVINO ist nicht thread-safe — laufen alter
                            # und neuer Worker gleichzeitig, crasht oneDNN mit
                            # "could not execute a primitive".
                            changed = [f"{k}: {existing.config.get(k)!r}→{cam.get(k)!r}"
                                       for k in _CONFIG_WATCH_KEYS
                                       if str(existing.config.get(k)) != str(cam.get(k))]
                            print(f"[CONFIG] Neustart nötig ({', '.join(changed)}): "
                                  f"{cam.get('name', cam['id'])}")
                            existing.stop()
                            existing.join(timeout=20)
                            if existing.is_alive():
                                # Thread hängt — neuen Worker nicht starten, sonst laufen
                                # zwei gleichzeitig und crashen PaddleOCR/OpenVINO.
                                print(f"[CONFIG] Warnung: Worker '{cam.get('name', cam['id'])}' "
                                      f"ließ sich nicht stoppen — überspringe Neustart bis nächsten Poll")
                                continue
                            worker = CameraWorker(cam, self.ocr, db_params)
                            worker.start()
                            self.workers[worker_key] = worker

                        else:
                            # Live-Settings sofort übernehmen (kein Worker-Neustart nötig)
                            existing.debug_snapshots = debug_snapshots
                            for k in _CONFIG_LIVE_KEYS:
                                if k in cam:
                                    existing.config[k] = cam[k]

                except Exception as e:
                    err = str(e)
                    if 'does not exist' in err:
                        self._dead_databases.add(dbname)
                        print(f"[CONFIG] DB '{dbname}' existiert nicht — wird übersprungen")
                    else:
                        print(f"[CONFIG] Fehler bei Company '{client.get('name', '?')}': {e}")

            # Nicht mehr aktive Worker stoppen
            for key in list(self.workers.keys()):
                if key not in active_ids:
                    print(f"[CONFIG] Worker gestoppt: {key}")
                    self.workers[key].stop()
                    self.workers[key].join(timeout=20)
                    del self.workers[key]

            if self.workers:
                print(f"[CONFIG] {len(self.workers)} aktive Kamera(s)")

        except Exception as e:
            print(f"[CONFIG] Fehler beim Laden: {e}")

    def _stop_all_workers(self):
        workers = list(self.workers.values())
        for worker in workers:
            worker.stop()
        for worker in workers:
            worker.join(timeout=20)
        self.workers.clear()


# --- Main ---------------------------------------------------------------------

def main():
    service = AnprService()
    service.start()


if __name__ == '__main__':
    main()
