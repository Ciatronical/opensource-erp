"""
Nachbearbeitung der OCR-Rohtexte je Feld und Ableitung der Zusatzfelder, die
die bisherige API mitlieferte (Maker, Model, PowerKw, Ccm, Fuel, FuelCode ...).

Bewusst konservativ: nur Zeichenklassen erzwingen, wo das Formular sie garantiert
(FIN ohne I/O/Q, HSN vierstellig numerisch, Datumsfelder), sonst nur trimmen.
"""
import re

VIN_MAP = str.maketrans({"I": "1", "O": "0", "Q": "0", " ": "", "-": ""})
DASHES = "–—−"


def _clean(s: str) -> str:
    s = (s or "").replace(" ", " ")
    for d in DASHES:
        s = s.replace(d, "-")
    return re.sub(r"[ \t]+", " ", s).strip()


def _is_blank(s: str) -> bool:
    return s.strip(" -._") == ""


def _digits(s: str, allow: str = "") -> str:
    return re.sub(r"[^0-9%s]" % re.escape(allow), "", s)


def _upper_alnum(s: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", s.upper())


def _plate(s: str) -> str:
    """Kennzeichen: Grossbuchstaben, in der Zifferngruppe Buchstaben-Verwechslungen korrigieren."""
    v = re.sub(r"[^A-ZÄÖÜ0-9 ()\-]", "", s.upper()).strip()
    m = re.match(r"^([A-ZÄÖÜ]{1,3})\s?([A-Z]{1,2})\s?([0-9IOSBZ]{1,4})\s?([EH]?)(.*)$", v)
    if m:
        digits = m.group(3).translate(str.maketrans("IOSBZ", "10582"))
        v = f"{m.group(1)} {m.group(2)}{digits}" + (" " + m.group(4) if m.group(4) else "") + m.group(5)
    return v.strip()


def _date_like(s: str) -> str:
    """'23.05.2013', '02.27', '02/27' -> Punkte, nur Ziffern/Punkte; ein eingebettetes
    Datum (TT.MM.JJ[JJ] oder MM.JJ[JJ]) wird herausgeloest, mitgelesene Bezeichner fallen weg."""
    s = s.replace("/", ".").replace(",", ".").replace(" ", "")
    s = re.sub(r"[^0-9.]", "", s)
    s = re.sub(r"\.{2,}", ".", s).strip(".")
    m = re.search(r"\d{1,2}\.\d{2}\.(?:19[6-9]\d|20[0-3]\d)|\d{1,2}\.\d{2}\.\d{2}|\d{1,2}\.(?:19[6-9]\d|20[0-3]\d)|\d{1,2}\.\d{2}", s)
    return m.group(0) if m else s


# Haeufige Herstellerkuerzel (WMI, erste 3 Zeichen der FIN) auf dem deutschen Markt.
# Dient nur zur Korrektur typischer OCR-Verwechslungen (E<->F, Z<->2, S<->5, B<->8, D<->0).
WMI = set("""
WVW WV1 WV2 WV3 WVG WAU WA1 WUA WBA WBS WBY WMW WDB WDC WDD WDF W1K W1N W1V W1T WME WP0 WP1 W0L W0V W0S
WF0 WF1 WMA WMX WEB WKK W09 WJM WSM WKE WDW
VF1 VF3 VF7 VF6 VR1 VR3 VR7 VNK VSS VXK VNE VN1 VFA VLE
ZFA ZAR ZCF ZFF ZAM ZHW ZLA ZDF ZA9 ZAP ZD4 ZDM ZFC
TMB TMA TMK TRU TSM TW1 TYA
SAL SAJ SB1 SJN SHS SUP SCC SDB SFD SKF
KMH KNA KNE KNM KL1 KLA KPT U5Y U6Y
JTD JT1 JTM JTE JHM JMZ JN1 JF1 JM1 JS1 JYA JH2 JMB JSA JTH JTN JN6 JS3 JKA JKB JHL
1HD 1FA 1FM 1FT 1G1 1GC 1C4 1C3 1N4 2HG 2HK 3VW 3FA 4JG 5YJ 1J4 1GY 4T1 5N1 1HG 5TD 2T1 3N1 1FD 1GT 5UX 4S4
LRW LVY LSJ LFV LZW LGX LBV LJ1 L6T LVS LZY LSG LMG
MMB MM0 NLH NMT NLA NM0 MAJ MA1 MA3 MAL MRH MHR MNT MPA
YV1 YV4 YS3 XLR XTA XL9 X9L UU1 9BW 9BD 93Y SXK VS6 VS7 VSK VV9
WSA WPO W0N WBE WMZ WRA WZ1 SMT ZBN ZAC MNB MDH KMY
""".split())
_CONF = {"E": "F", "F": "E", "Z": "2", "2": "Z", "S": "5", "5": "S", "B": "8", "8": "B", "D": "0", "0": "D",
         "G": "6", "6": "G", "T": "7", "7": "T", "1": "I", "I": "1", "O": "0", "Q": "0", "N": "W", "W": "N",
         "V": "Y", "Y": "V", "U": "V", "M": "W"}
VW_GROUP = {"WVW", "WV1", "WV2", "WV3", "WVG", "WAU", "WA1", "WUA", "TMB", "TMA", "TMK", "TRU", "VSS", "WP0", "WP1"}


def _fix_wmi(v: str) -> str:
    """WMI nicht bekannt -> ein Zeichen per Verwechslungstabelle ersetzen, wenn dadurch
    genau ein bekanntes WMI entsteht."""
    if len(v) < 3 or v[:3] in WMI:
        return v
    hits = set()
    for i in range(3):
        alt = _CONF.get(v[i])
        if alt:
            cand = v[:i] + alt + v[i + 1:3]
            if cand in WMI:
                hits.add(cand)
    if len(hits) == 1:
        return hits.pop() + v[3:]
    return v


def _vin(s: str) -> str:
    v = _upper_alnum(s.translate(VIN_MAP))
    # Feldbezeichner "E" links davor mitgelesen -> auf die letzten 17 Zeichen kuerzen
    if len(v) > 17:
        v = v[-17:]
    v = _fix_wmi(v)
    # VW-Konzern (EU): Positionen 4-6 sind "ZZZ" — OCR liest gern "2" statt "Z"
    if len(v) == 17 and v[:3] in VW_GROUP and set(v[3:6]) <= {"Z", "2"}:
        v = v[:3] + "ZZZ" + v[6:]
    return v


def _hsn(s: str) -> str:
    d = _digits(s)
    # Bezeichner "2.1" wird bei knappem Ausschnitt mitgelesen ("21" + HSN) -> letzte 4 Ziffern
    return d[-4:] if len(d) > 4 else d


_TSN_DIGITS = str.maketrans({"O": "0", "I": "1", "Q": "0", "D": "0", "B": "8", "S": "5", "Z": "2"})


def _tsn(s: str) -> str:
    """Feld 2.2 = 3 Zeichen Typschluessel + 5 Ziffern (+ Rest). Mitgelesener Bezeichner
    "2.2" links abschneiden, in den Ziffernpositionen 4-8 Buchstaben-Verwechslungen korrigieren."""
    v = _upper_alnum(s)
    if len(v) > 8:
        for k in range(0, 4):
            cand = v[k:]
            if 8 <= len(cand) <= 9 and re.match(r"^[A-Z0-9]{3}[0-9OIQDBSZ]{5}", cand):
                v = cand
                break
    if len(v) >= 8 and re.match(r"^[A-Z0-9]{3}", v):
        v = v[:3] + v[3:8].translate(_TSN_DIGITS) + v[8:]
    return v


FIELD_RULES = {
    "vin": _vin,
    "hsn": _hsn,
    "field_2_2": _tsn,
    "field_3": lambda s: _upper_alnum(s)[-1:],
    "ez": _date_like,
    "hu": _date_like,
    "creation_date": _date_like,
    "registrationNumber": lambda s: _plate(s),
    "field_10": lambda s: _digits(s),
    "p1": lambda s: _digits(s),
    "field_14_1": lambda s: _upper_alnum(s),
    "j": lambda s: _upper_alnum(s),
    "field_4": lambda s: _upper_alnum(s),
    "s1": lambda s: _digits(s),
    "s2": lambda s: _digits(s),
    "g": lambda s: _digits(s, "-"),
    "field_11": lambda s: s.strip(),
}


def _p2p4(s: str) -> str:
    """'128 /3500' -> kW und Nenndrehzahl; OCR liest '/' gern als '1' oder '7' ('50 160000', '76000')."""
    nums = re.findall(r"\d+(?:[.,]\d+)?", s)
    if not nums:
        return s.strip()
    nums = [n for n in nums if len(n) >= 2 or n == nums[0]]
    kw = nums[0]
    rpm = nums[1] if len(nums) > 1 else ""
    if len(rpm) >= 5 and rpm[0] in "17":
        rpm = rpm[1:]
    if len(kw) >= 5 and kw[0] in "17":
        # Drehzahl mit Schraegstrich als Ziffer erkannt: "76000" -> "/6000"
        return "/" + kw[1:]
    return (kw + (" /" + rpm if rpm else "")).strip()


def _tyre(s: str) -> str:
    """Reifenangaben: 'RI6' -> 'R16', 'RO' -> 'R0' u. ae. (Buchstaben statt Ziffern nach R)."""
    s = re.sub(r"R([IlO0-9]{2})", lambda m: "R" + m.group(1).replace("I", "1").replace("l", "1").replace("O", "0"), s)
    return s.strip()


FIELD_RULES["p2_p4"] = _p2p4
for _f in ("field_15_1", "field_15_2", "field_15_3"):
    FIELD_RULES[_f] = _tyre


# Gedruckte Feldbezeichner (werden von der Detektion manchmal mit dem Wert verklebt)
LABELS = {
    "ez": ["B"], "j": ["J"], "vin": ["E"], "field_3": ["3"], "field_4": ["4"], "hsn": ["2.1", "21"],
    "field_2_2": ["2.2", "22"], "d1": ["D.1", "D1"], "d2_1": ["D.2", "D2"], "d2_2": ["D.2"], "d2_3": ["D.2"],
    "d2_4": ["D.2"], "d3": ["D.3", "D3"], "field_2": ["2"], "field_5_1": ["5"], "field_5_2": ["5"],
    "v9": ["V.9", "V9"], "field_14": ["14"], "p3": ["P.3", "P3"], "field_10": ["10"],
    "field_14_1": ["14.1", "141"], "p1": ["P.1", "P1"], "l": ["L"], "field_9": ["9"], "p2_p4": ["P.2", "P.4", "P2"],
    "t": ["T"], "field_18": ["18"], "field_19": ["19"], "field_20": ["20"], "g": ["G"], "field_12": ["12"],
    "field_13": ["13"], "q": ["Q"], "v7": ["V.7", "V7"], "f1": ["F.1", "F1"], "f2": ["F.2", "F2"],
    "field_7_1": ["7.1"], "field_7_2": ["7.2"], "field_7_3": ["7.3"], "field_8_1": ["8.1"], "field_8_2": ["8.2"],
    "field_8_3": ["8.3"], "u1": ["U.1", "U1"], "u2": ["U.2", "U2"], "u3": ["U.3", "U3"], "o1": ["O.1", "O1"],
    "o2": ["O.2", "O2"], "s1": ["S.1", "S1"], "s2": ["S.2", "S2"], "field_15_1": ["15.1"], "field_15_2": ["15.2"],
    "field_15_3": ["15.3"], "r": ["R"], "field_11": ["11"], "k": ["K"], "field_6": ["6"], "field_17": ["17"],
    "field_16": ["16"], "field_21": ["21"], "field_22": ["22"], "hu": ["X"], "registrationNumber": ["A"],
    "name1": ["C.1.1"], "firstname": ["C.1.2"], "address1": ["C.1.3"], "document_id": ["Nr.", "Nr"],
}


def _strip_label(name: str, t: str) -> str:
    """Fuehrenden Feldbezeichner entfernen (nur wenn danach noch Text bleibt)."""
    for lab in LABELS.get(name, []):
        m = re.match(r"^\s*" + re.escape(lab) + r"[\]\.\s:|]*", t, re.IGNORECASE)
        if m and m.end() > 0 and t[m.end():].strip():
            # Sicherheitsnetz: bei rein numerischen Werten nur abtrennen, wenn ein Trenner folgte
            rest = t[m.end():]
            if lab.replace(".", "").isdigit() and t[len(lab):len(lab) + 1].isdigit():
                continue
            return rest.strip()
    return t


# Zahlenfelder, bei denen ein vorangestellter Kurzbezeichner ("E1 ", "8.2 ", "4 ", "P ")
# von der Detektion mitgenommen sein kann: generisch abziehen, wenn ein Leerzeichen folgt
NUMERIC_FIELDS = {"f1", "f2", "g", "u1", "u2", "u3", "o1", "o2", "s1", "s2", "field_7_1", "field_7_2",
                  "field_7_3", "field_8_1", "field_8_2", "field_8_3", "field_14_1", "p2_p4", "field_18",
                  "field_19", "field_20", "field_12", "field_13", "q", "t", "l", "field_9", "v7", "p1",
                  "field_10", "hsn", "field_3", "field_17", "field_16", "field_11", "field_15_3"}
_LABEL_TOKEN = re.compile(r"^(?:[A-Za-z]\.?\s?\d(?:\.\d)?|\d(?:\.\d)?|[A-Za-z])[\]\.:]?\s+(?=\S)")
# Bezeichner ohne Leerzeichen verklebt ("E21190", "u.176"): Buchstabe + Ziffer vor >= 3 Ziffern
_LABEL_GLUED = re.compile(r"^[A-Za-z]\.?\d\.?(?=\d{3,}$)")


_TRAIL_LABEL = re.compile(r"\s+(?:[A-Za-z]\.?\d(?:\.\d)?|[A-Za-z]|\d{1,2}(?:\.\d)?)[\]\.:]?$")


def _strip_generic_label(name: str, t: str) -> str:
    if name not in NUMERIC_FIELDS:
        return t
    m = _LABEL_TOKEN.match(t)
    if m and t[m.end():].strip():
        t = t[m.end():].strip()
    m = _LABEL_GLUED.match(t)
    if m:
        t = t[m.end():]
    # nachgestellter Bezeichner des rechten Nachbarfelds ("4405-4505 19", "1190 E2")
    m = _TRAIL_LABEL.search(t)
    if m and t[:m.start()].strip() and name != "p2_p4":
        t = t[:m.start()].strip()
    # Zahlenfelder sind einzelne Token: bei Resten ("52 1840", "$2 420") das laengste behalten
    if name != "p2_p4" and " " in t:
        toks = [x for x in t.split() if x]
        if toks:
            t = max(toks, key=len)
    return t


def clean_fields(raw: dict) -> dict:
    """raw: name -> (text, score). Liefert name -> bereinigter Text ('' bei leer/'-')."""
    out = {}
    for name, (text, score) in raw.items():
        t = _clean(text)
        t = _strip_label(name, t)
        t = _strip_generic_label(name, t)
        if _is_blank(t):
            out[name] = ""
            continue
        rule = FIELD_RULES.get(name)
        if rule:
            t = rule(t)
        out[name] = t
    return out


def derive(fields: dict) -> dict:
    """Zusatzfelder wie bei fahrzeugschein-scanner.de (werden in fs_scans_lxcars gespeichert)."""
    d = {}
    d["Maker"] = fields.get("d1", "")
    d["Model"] = fields.get("d3", "")
    d["Ccm"] = _digits(fields.get("p1", ""))
    # P.2/P.4: "120 /3800" -> kW 120, Nenndrehzahl 3800
    p2 = fields.get("p2_p4", "")
    m = re.match(r"\s*([0-9]+(?:[.,][0-9]+)?)", p2)
    d["PowerKw"] = m.group(1).replace(",", ".") if m else ""
    try:
        kw = float(d["PowerKw"]) if d["PowerKw"] else None
        d["PowerHpKw"] = str(int(round(kw * 1.35962))) if kw is not None else ""
    except ValueError:
        d["PowerHpKw"] = ""
    d["Fuel"] = fields.get("p3", "")
    d["FuelCode"] = fields.get("field_10", "")
    d["ez_string"] = fields.get("ez", "")
    d["tsn"] = fields.get("field_2_2", "")[:3]
    d["vsn"] = fields.get("field_2_2", "")[3:]
    return d


# Feste Formate fuer die Abstimmung zwischen den Erkennungsmodellen
FORMATS = {
    "hsn": re.compile(r"^\d{4}$"),
    "field_3": re.compile(r"^[A-Z0-9]$"),
    "j": re.compile(r"^(?:[LMNOT][1-9][a-z]?|\d{2})$"),
    "ez": re.compile(r"^\d{2}\.\d{2}\.(?:\d{2}|\d{4})$"),
    "hu": re.compile(r"^\d{2}\.(?:\d{2}|\d{4})$"),
    "field_2_2": re.compile(r"^[A-Z0-9]{3}\d{5}[A-Z0-9-]?$"),
    "vin": re.compile(r"^[A-HJ-NPR-Z0-9]{17}$"),
    "p1": re.compile(r"^\d{2,5}$"),
    "field_10": re.compile(r"^\d{4}$"),
}


def _j(s: str) -> str:
    v = re.sub(r"[^A-Za-z0-9]", "", s)
    m = re.search(r"([LMNOT])([1-9IlO])([a-z]?)$", v)
    if m:
        d = m.group(2).replace("I", "1").replace("l", "1").replace("O", "0")
        return m.group(1) + d + m.group(3)
    return v


def matches_format(name: str, value: str) -> bool:
    """Erfuellt der bereinigte Wert das feste Format des Feldes? (None-Felder: immer True)"""
    rx = FORMATS.get(name)
    if rx is None:
        return True
    cleaned = clean_fields({name: (value, 1.0)})[name]
    return bool(rx.match(cleaned))


PLATE_RE = re.compile(r"^[A-ZÄÖÜ]{1,3} ?[A-Z]{1,2} ?\d{1,4}\s?[EH]?(\s*\(.*\))?$")
HU_RE = re.compile(r"^\d{2}\.(\d{2}|\d{4})$")


def looks_like(name: str, value: str) -> bool:
    """Passt der Wert zum erwarteten Muster des Feldes? (fuer Kasten-vs-Layout-Entscheidung)"""
    v = (value or "").strip()
    if name == "registrationNumber":
        return bool(PLATE_RE.match(v.upper()))
    if name == "hu":
        return bool(HU_RE.match(v))
    return bool(v)


def plausibility(fields: dict) -> list:
    """Hinweise fuer den Anwender (keine Blockade)."""
    notes = []
    vin = fields.get("vin", "")
    if vin and len(vin) != 17:
        notes.append(f"FIN hat {len(vin)} statt 17 Zeichen")
    hsn = fields.get("hsn", "")
    if hsn and len(hsn) != 4:
        notes.append("HSN nicht vierstellig")
    return notes


FIELD_RULES["j"] = _j
