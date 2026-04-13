#!/usr/bin/env python3
"""
ONVIF-Kamera-Erkennung im Netzwerk.

Findet automatisch alle ONVIF-kompatiblen IP-Kameras und gibt deren
RTSP-Stream-URLs zurueck. Kann als Standalone-Skript oder als Modul
verwendet werden.

Nutzung:
    # Standalone: zeigt alle gefundenen Kameras
    python3 discover_cameras.py

    # Als Modul:
    from discover_cameras import discover_cameras
    cameras = discover_cameras(timeout=5)
"""

import re
import socket
import struct
import sys
import time
import uuid
from urllib.parse import urlparse

# --- WS-Discovery (ONVIF) ---------------------------------------------------
# ONVIF-Kameras antworten auf WS-Discovery Multicast-Probes.
# Das ist das gleiche Protokoll das Xeoma, ONVIF Device Manager usw. nutzen.

MULTICAST_GROUP = '239.255.255.250'
MULTICAST_PORT = 3702
WS_DISCOVERY_TIMEOUT = 5

PROBE_TEMPLATE = """<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope
    xmlns:soap="http://www.w3.org/2003/05/soap-envelope"
    xmlns:wsa="http://schemas.xmlsoap.org/ws/2004/08/addressing"
    xmlns:wsd="http://schemas.xmlsoap.org/ws/2005/04/discovery"
    xmlns:tdn="http://www.onvif.org/ver10/network/wsdl">
    <soap:Header>
        <wsa:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</wsa:Action>
        <wsa:MessageID>urn:uuid:{msg_id}</wsa:MessageID>
        <wsa:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</wsa:To>
    </soap:Header>
    <soap:Body>
        <wsd:Probe>
            <wsd:Types>tdn:NetworkVideoTransmitter</wsd:Types>
        </wsd:Probe>
    </soap:Body>
</soap:Envelope>"""


def _send_probe(timeout=WS_DISCOVERY_TIMEOUT):
    """WS-Discovery Multicast-Probe senden und Antworten sammeln."""
    msg_id = str(uuid.uuid4())
    probe = PROBE_TEMPLATE.format(msg_id=msg_id).encode('utf-8')

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 2)
    sock.settimeout(timeout)

    sock.sendto(probe, (MULTICAST_GROUP, MULTICAST_PORT))

    responses = []
    end_time = time.time() + timeout

    while time.time() < end_time:
        try:
            data, addr = sock.recvfrom(65535)
            responses.append((data.decode('utf-8', errors='ignore'), addr[0]))
        except socket.timeout:
            break
        except Exception:
            continue

    sock.close()
    return responses


def _parse_xaddrs(xml_response):
    """XAddrs (Service-URLs) aus WS-Discovery Antwort extrahieren."""
    # Einfacher Regex-Parser (kein lxml noetig)
    match = re.search(r'<[^>]*XAddrs[^>]*>([^<]+)<', xml_response)
    if match:
        return match.group(1).strip().split()
    return []


def _parse_scopes(xml_response):
    """Scopes (Metadaten) aus WS-Discovery Antwort extrahieren."""
    match = re.search(r'<[^>]*Scopes[^>]*>([^<]+)<', xml_response)
    if match:
        scopes = match.group(1).strip().split()
        info = {}
        for scope in scopes:
            if '/name/' in scope:
                info['name'] = scope.split('/name/')[-1].replace('%20', ' ')
            elif '/hardware/' in scope:
                info['hardware'] = scope.split('/hardware/')[-1].replace('%20', ' ')
            elif '/location/' in scope:
                info['location'] = scope.split('/location/')[-1].replace('%20', ' ')
        return info
    return {}


def _get_rtsp_url_via_onvif(device_url, username='admin', password='admin'):
    """
    RTSP Stream-URL ueber ONVIF GetStreamUri abfragen.
    Versucht es mit onvif-zeep (wenn installiert), sonst Fallback auf Standard-URLs.
    """
    try:
        from onvif import ONVIFCamera
        parsed = urlparse(device_url)
        host = parsed.hostname
        port = parsed.port or 80

        cam = ONVIFCamera(host, port, username, password)
        media_service = cam.create_media_service()
        profiles = media_service.GetProfiles()

        if profiles:
            stream_setup = {
                'Stream': 'RTP-Unicast',
                'Transport': {'Protocol': 'RTSP'}
            }
            uri_response = media_service.GetStreamUri({
                'StreamSetup': stream_setup,
                'ProfileToken': profiles[0].token
            })
            return uri_response.Uri
    except ImportError:
        pass
    except Exception:
        pass

    return None


def _guess_rtsp_urls(ip):
    """
    Typische RTSP-URLs fuer gaengige Kameramarken generieren.
    Werden der Reihe nach getestet.
    """
    return [
        f"rtsp://{ip}:554/stream1",
        f"rtsp://{ip}:554/Streaming/Channels/101",      # Hikvision
        f"rtsp://{ip}:554/cam/realmonitor?channel=1&subtype=0",  # Dahua
        f"rtsp://{ip}:554/live/ch00_0",                  # Reolink
        f"rtsp://{ip}:554/h264Preview_01_main",          # Amcrest
        f"rtsp://{ip}:554/onvif1",                       # ONVIF generic
        f"rtsp://{ip}:554/videoMain",
        f"rtsp://{ip}:554/1",
    ]


def _test_rtsp_url(url, timeout=5):
    """Testet ob ein RTSP-Stream erreichbar ist."""
    try:
        import cv2
        cap = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
        cap.set(cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, timeout * 1000)
        if cap.isOpened():
            ret, _ = cap.read()
            cap.release()
            return ret
        cap.release()
    except Exception:
        pass
    return False


def discover_cameras(timeout=5, username='admin', password='admin', test_streams=False):
    """
    Alle ONVIF-Kameras im Netzwerk finden.

    Returns:
        Liste von dicts:
        [{
            'ip': '192.168.1.100',
            'name': 'Kamera Lager',
            'hardware': 'DS-2CD2346G2',
            'onvif_url': 'http://192.168.1.100/onvif/device_service',
            'rtsp_url': 'rtsp://192.168.1.100:554/stream1',
            'rtsp_candidates': [...],
        }, ...]
    """
    print(f"Suche ONVIF-Kameras im Netzwerk (Timeout: {timeout}s)...")
    responses = _send_probe(timeout=timeout)
    print(f"{len(responses)} Antwort(en) empfangen.")

    seen_ips = set()
    cameras = []

    for xml, ip in responses:
        if ip in seen_ips:
            continue
        seen_ips.add(ip)

        xaddrs = _parse_xaddrs(xml)
        scopes = _parse_scopes(xml)

        cam = {
            'ip': ip,
            'name': scopes.get('name', f'Kamera {ip}'),
            'hardware': scopes.get('hardware', ''),
            'location': scopes.get('location', ''),
            'onvif_url': xaddrs[0] if xaddrs else f'http://{ip}/onvif/device_service',
            'rtsp_url': None,
            'rtsp_candidates': _guess_rtsp_urls(ip),
        }

        # RTSP-URL ueber ONVIF abfragen
        if xaddrs:
            rtsp = _get_rtsp_url_via_onvif(xaddrs[0], username, password)
            if rtsp:
                cam['rtsp_url'] = rtsp

        # Wenn kein ONVIF-RTSP: Standard-URLs testen (optional)
        if not cam['rtsp_url'] and test_streams:
            for url in cam['rtsp_candidates'][:3]:  # Max. 3 testen
                if _test_rtsp_url(url, timeout=3):
                    cam['rtsp_url'] = url
                    break

        cameras.append(cam)

    return cameras


# --- Standalone ---------------------------------------------------------------

if __name__ == '__main__':
    cams = discover_cameras(
        timeout=5,
        test_streams='--test' in sys.argv
    )

    if not cams:
        print("\nKeine Kameras gefunden.")
        print("Moegliche Gruende:")
        print("  - Keine ONVIF-Kameras im Netzwerk")
        print("  - Multicast geblockt (Firewall)")
        print("  - Kameras in anderem Subnetz")
        sys.exit(0)

    print(f"\n{'='*60}")
    print(f"{len(cams)} Kamera(s) gefunden:")
    print(f"{'='*60}")

    for cam in cams:
        print(f"\n  IP:       {cam['ip']}")
        print(f"  Name:     {cam['name']}")
        if cam['hardware']:
            print(f"  Hardware: {cam['hardware']}")
        print(f"  ONVIF:    {cam['onvif_url']}")
        if cam['rtsp_url']:
            print(f"  RTSP:     {cam['rtsp_url']}")
        else:
            print(f"  RTSP:     (nicht ermittelt — testen mit --test)")
            print(f"  Kandidaten: {cam['rtsp_candidates'][:3]}")
