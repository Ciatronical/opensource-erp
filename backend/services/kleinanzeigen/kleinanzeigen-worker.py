#!/usr/bin/env python3
"""
Kleinanzeigen-Dienst: Pseudo-API über einen Headless-Browser
=============================================================

Kleinanzeigen.de hat keine Schnittstelle. Dieser Dienst steuert einen echten
Chrome (nodriver, Chrome DevTools Protocol, ohne WebDriver-Spuren) mit dem
eigenen Konto des Betreibers und bietet OpensourceERP eine kleine JSON-API:

    POST /session/status   {account, email}                 Angemeldet?
    POST /session/login    {account, email, password}       Anmelden (Auth0, zwei Schritte)
    POST /session/code     {account, code}                  Bestätigungscode (E-Mail/SMS) nachreichen
    POST /session/manual   {account, email}                 Anmeldeseite im sichtbaren Browser öffnen, Benutzer meldet sich selbst an
    POST /remote/view|open|click|type|key|scroll             Fernsteuerung: Bild des Browsers (jpeg), Klick {x,y}, Text {text},
                                                             Taste {key}, Rollen {dy}, Seite öffnen {url} — jede Antwort bringt das Bild mit
    POST /session/close    {account}                        Browser schließen
    POST /ads/list         {account}                        Meine Anzeigen (JSON-Endpunkt der Webseite)
    POST /ads/publish      {account, ad}                    Anzeige neu einstellen oder (mit ad.id) ändern
    POST /ads/delete       {account, id}                    Anzeige löschen
    POST /ads/extend       {account, id}                    Anzeige verlängern (kurz vor Ablauf)
    POST /messages/list    {account, limit}                 Nachrichten: Unterhaltungen
    POST /messages/read    {account, id}                    Nachrichten einer Unterhaltung
    POST /messages/reply   {account, id, text}              Antworten
    POST /debug/page       {account, url}                   Seite laden, Text und HTML zurückgeben
    POST /debug/screenshot {account}                        Bildschirmfoto (PNG, base64)
    GET  /health                                            Lebenszeichen

Jede Anfrage trägt den Kopf  X-KA-Token: <KA_TOKEN>.  "account" ist eine
Kennung je Konto (etwa "<datenbank>-<kanal>"); je Kennung läuft ein Browser mit
eigenem Profil, die Sitzung (Cookies) bleibt über Neustarts erhalten — nach dem
ersten Anmelden ist in der Regel nie wieder ein Login nötig.

Die Abläufe (Login über login.kleinanzeigen.de, Formular, Paketwahl, Löschen
über m-anzeigen-loeschen.json, Anzeigenliste über m-meine-anzeigen-verwalten.json)
folgen dem Stand der Webseite im Oktober 2026, wie ihn auch das Open-Source-
Projekt kleinanzeigen-bot dokumentiert. Ändert Kleinanzeigen die Seite,
helfen /debug/page und /debug/screenshot beim Nachziehen der Selektoren.

Konfiguration über Umgebungsvariablen:
    KA_PORT          Port (Standard 3007), nur 127.0.0.1
    KA_TOKEN         Pflicht: Shared-Secret, auch in der Firmenkonfiguration eintragen
    KA_PROFILE_DIR   Basisverzeichnis der Browserprofile (Standard ~/.local/share/oserp-kleinanzeigen)
    KA_CHROME        Pfad zum Chrome/Chromium (Standard: google-chrome aus PATH)
    KA_HEADLESS      1 (Standard) oder 0 für einen sichtbaren Browser (Einrichtung, Captcha)
    KA_ROOT_URL      https://www.kleinanzeigen.de
    KA_IDLE_MINUTES  Browser nach so vielen Minuten ohne Anfrage schließen (Standard 30, 0 = nie)
"""

import asyncio
import base64
import json
import logging
import os
import random
import re
import shutil
import sys
import time
import traceback
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any
from urllib.parse import parse_qs, urlparse

from aiohttp import web

try:
    import nodriver as uc
except ImportError:  # pragma: no cover
    print("nodriver fehlt: ./.venv/bin/pip install -r requirements.txt", file=sys.stderr)
    raise

VERSION = "1.0.0"
PORT = int(os.environ.get("KA_PORT", "3007"))
TOKEN = os.environ.get("KA_TOKEN", "")
PROFILE_BASE = Path(os.environ.get("KA_PROFILE_DIR", str(Path.home() / ".local/share/oserp-kleinanzeigen")))
CHROME = os.environ.get("KA_CHROME", "") or shutil.which("google-chrome") or shutil.which("chromium") or shutil.which("chromium-browser") or ""
HEADLESS = os.environ.get("KA_HEADLESS", "1") not in ("0", "false", "no")
ROOT_URL = os.environ.get("KA_ROOT_URL", "https://www.kleinanzeigen.de").rstrip("/")
IDLE_MINUTES = int(os.environ.get("KA_IDLE_MINUTES", "30"))
# Kleinanzeigen sperrt IPv6-Bereiche großzügig („IP-Bereich vorübergehend
# gesperrt“). Mit KA_IPV4=1 (Vorgabe) löst der Dienst die Kleinanzeigen-Hosts
# selbst auf IPv4 auf und gibt sie Chrome als feste Zuordnung mit — die Seite
# wird dann über die IPv4-Adresse des Anschlusses besucht.
IPV4_ONLY = os.environ.get("KA_IPV4", "1") not in ("0", "false", "no")
IPV4_HOSTS = ("www.kleinanzeigen.de", "login.kleinanzeigen.de", "kleinanzeigen.de", "api.kleinanzeigen.de",
              "img.kleinanzeigen.de", "static.kleinanzeigen.de", "mobile.kleinanzeigen.de", "gateway.kleinanzeigen.de")
PROXY = os.environ.get("KA_PROXY", "")  # etwa socks5://127.0.0.1:1080 — Ausweg bei gesperrtem Anschluss

LOG = logging.getLogger("kleinanzeigen")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

# Elemente, an denen die Webseite eine angemeldete Sitzung zeigt
LOGGED_IN_SELECTORS = ('[data-testid="logged-in-user"]', ".mr-medium", "#user-email")
LOGGED_OUT_SELECTOR = 'a[href*="einloggen"]'
AUTH0_SUBMIT = "button[type='submit'][data-action-button-primary='true']:not([disabled])"
CAPTCHA_IFRAMES = (
    "iframe[src*='google.com/recaptcha']", "iframe[src*='recaptcha.net']", "iframe[src*='hcaptcha.com']",
    "iframe[src*='challenges.cloudflare.com']", "iframe[src*='arkoselabs.com']", "iframe[src*='funcaptcha.com']",
)
CODE_INPUT_SELECTORS = ("input[autocomplete='one-time-code']", "input[name='code']", "input[id*='code']")
SUBMIT_LABELS = ("Anzeige aufgeben", "Änderungen speichern", "Anzeige speichern")
PRICE_TYPE_INDEX = {"FIXED": 0, "NEGOTIABLE": 1, "GIVE_AWAY": 2, "fixed": 0, "negotiable": 1, "giveaway": 2}
SHIPPING_LABELS = {"SHIPPING": "Versand möglich", "PICKUP": "Nur Abholung", "both": "Versand möglich",
                   "shipping": "Versand möglich", "pickup": "Nur Abholung"}
# Zustand: Werte der Webseite (API-Werte) und, was OSERP schickt
CONDITION_MAP = {"new": "new", "like_new": "like_new", "very_good": "like_new", "good": "ok", "ok": "alright",
                 "alright": "alright", "defective": "defect", "defect": "defect",
                 "neu": "new", "wie_neu": "like_new", "sehr_gut": "like_new", "gut": "ok", "in_ordnung": "alright", "defekt": "defect"}


def ipv4_rules() -> list[str]:
    """Feste IPv4-Zuordnung der Kleinanzeigen-Hosts für Chrome (bei jedem Browserstart neu aufgelöst)"""
    import socket
    rules = []
    for host in IPV4_HOSTS:
        try:
            addrs = sorted({a[4][0] for a in socket.getaddrinfo(host, 443, socket.AF_INET)})
        except OSError:
            continue
        if addrs:
            rules.append(f"MAP {host} {addrs[0]}")
    if rules:
        LOG.info("IPv4-Zuordnung: %s", "; ".join(rules))
    return rules


class WorkerError(Exception):
    """Fehler mit Code für die API-Antwort"""

    def __init__(self, code: str, message: str, **extra: Any) -> None:
        super().__init__(message)
        self.code = code
        self.extra = extra


# ── Browser-Sitzung je Konto ──


@dataclass
class Session:
    account: str
    browser: Any = None
    page: Any = None
    lock: asyncio.Lock = field(default_factory=asyncio.Lock)
    last_used: float = field(default_factory=time.time)
    email: str = ""

    @property
    def profile_dir(self) -> Path:
        safe = re.sub(r"[^A-Za-z0-9_.-]+", "_", self.account) or "default"
        return PROFILE_BASE / safe

    async def ensure_browser(self) -> None:
        self.last_used = time.time()
        if self.browser is not None and not getattr(self.browser, "stopped", False):
            return
        self.profile_dir.mkdir(parents=True, exist_ok=True)
        resolver_rules = ["MAP connect.facebook.net 127.0.0.1", "MAP securepubads.g.doubleclick.net 127.0.0.1",
                          "MAP www.googletagmanager.com 127.0.0.1"]
        if IPV4_ONLY:
            resolver_rules += ipv4_rules()
        args = [
            "--disable-crash-reporter", "--disable-domain-reliability", "--disable-sync", "--no-experiments",
            "--disable-search-engine-choice-screen", "--use-mock-keychain",
            "--window-size=1400,1000",
            "--host-resolver-rules=" + ", ".join(resolver_rules),
        ]
        if PROXY:
            args.append("--proxy-server=" + PROXY)
        cfg = uc.Config(
            user_data_dir=str(self.profile_dir),
            headless=HEADLESS,
            browser_executable_path=CHROME or None,
            browser_args=args,
            lang="de-DE",
        )
        LOG.info("[%s] Browser starten (headless=%s, profil=%s)", self.account, HEADLESS, self.profile_dir)
        self.browser = await uc.start(cfg)
        self.page = None

    async def close(self) -> None:
        if self.browser is not None:
            try:
                self.browser.stop()
            except Exception:  # noqa: BLE001
                pass
        self.browser = None
        self.page = None

    # ── Seitenhilfen ──

    async def open(self, url: str, *, reload: bool = False) -> Any:
        await self.ensure_browser()
        if self.page is not None and not reload and self.page.url == url:
            return self.page
        self.page = await self.browser.get(url)
        await self.wait_ready()
        return self.page

    async def wait_ready(self, timeout: float = 15) -> None:
        """Wartet, bis das Dokument geladen ist — bei Weiterleitungen (Login
        über Auth0) kann der Ausführungskontext zwischendurch fehlen, dann
        wird kurz gewartet und erneut gefragt; nach timeout geht es weiter."""
        deadline = time.time() + timeout
        fehler = 0
        while time.time() < deadline:
            try:
                state = await self.page.evaluate("document.readyState")
                if state in ("interactive", "complete"):
                    return
                fehler = 0
            except Exception:  # noqa: BLE001
                fehler += 1
                if fehler >= 20:
                    LOG.warning("[%s] readyState nicht abfragbar — weiter ohne Warten", self.account)
                    return
            await asyncio.sleep(0.25)
        LOG.debug("[%s] readyState nach %.0fs nicht fertig — weiter", self.account, timeout)

    async def js(self, code: str) -> Any:
        """Führt JavaScript aus; das Ergebnis kommt als JSON-Text zurück, damit
        Objekte und Listen zuverlässig ankommen (RemoteObjects sind unhandlich)."""
        wrapped = f"(async () => {{ const __r = await (async () => {{ {code} }})(); return JSON.stringify(__r === undefined ? null : __r); }})()"
        result = await self.page.evaluate(wrapped, await_promise=True, return_by_value=True)
        if isinstance(result, uc.cdp.runtime.ExceptionDetails):
            detail = getattr(getattr(result, "exception", None), "description", None) or getattr(result, "text", "") or "JavaScript-Fehler"
            raise WorkerError("JS_ERROR", str(detail)[:500])
        if isinstance(result, str):
            try:
                return json.loads(result)
            except json.JSONDecodeError:
                return result
        value = getattr(result, "value", result)
        if isinstance(value, str):
            try:
                return json.loads(value)
            except json.JSONDecodeError:
                return value
        return value

    async def url(self) -> str:
        try:
            return str(await self.page.evaluate("window.location.href"))
        except Exception:  # noqa: BLE001
            return str(getattr(self.page, "url", "") or "")

    async def probe(self, selector: str, timeout: float = 0) -> Any:
        """Element per CSS, None wenn nicht da (optional mit Wartezeit)"""
        deadline = time.time() + timeout
        while True:
            try:
                elem = await self.page.query_selector(selector)
            except Exception:  # noqa: BLE001
                elem = None
            if elem is not None or time.time() >= deadline:
                return elem
            await asyncio.sleep(0.3)

    async def find(self, selector: str, timeout: float = 10, what: str = "") -> Any:
        elem = await self.probe(selector, timeout)
        if elem is None:
            raise WorkerError("ELEMENT_NOT_FOUND", f"Element nicht gefunden: {what or selector}")
        return elem

    async def probe_text(self, text: str, timeout: float = 0) -> Any:
        deadline = time.time() + timeout
        while True:
            try:
                elem = await self.page.find(text, best_match=True, timeout=0.5)
            except Exception:  # noqa: BLE001
                elem = None
            if elem is not None or time.time() >= deadline:
                return elem
            await asyncio.sleep(0.3)

    # ── Menschliches Verhalten ──
    #
    # Kein Schritt gleicht dem vorigen: die Maus fährt auf einem leicht
    # gebogenen Weg mit wechselnder Geschwindigkeit, trifft nicht die Mitte,
    # zögert vor dem Klick; getippt wird mit Tipprhythmus — schnelle
    # Buchstabenfolgen, Denkpausen, gelegentlich ein Vertipper mit Korrektur.

    _maus = (200.0, 200.0)  # zuletzt bekannte Mausposition

    async def human_move(self, x: float, y: float) -> None:
        sx, sy = self._maus
        dist = ((x - sx) ** 2 + (y - sy) ** 2) ** 0.5
        schritte = max(6, min(40, int(dist / random.uniform(12, 28))))
        # Kontrollpunkt für eine leichte Kurve, mal links, mal rechts vom Weg
        bogen = random.uniform(-0.25, 0.25) * dist
        cx = (sx + x) / 2 + bogen * ((y - sy) / dist if dist else 0)
        cy = (sy + y) / 2 - bogen * ((x - sx) / dist if dist else 0)
        for i in range(1, schritte + 1):
            t = i / schritte
            # langsam anfahren, schnell in der Mitte, abbremsen am Ziel
            u = t * t * (3 - 2 * t)
            px = (1 - u) ** 2 * sx + 2 * (1 - u) * u * cx + u ** 2 * x + random.uniform(-1.2, 1.2)
            py = (1 - u) ** 2 * sy + 2 * (1 - u) * u * cy + u ** 2 * y + random.uniform(-1.2, 1.2)
            await self.page.send(uc.cdp.input_.dispatch_mouse_event("mouseMoved", x=px, y=py))
            await asyncio.sleep(random.uniform(0.004, 0.022) * (1.6 if i > schritte - 3 else 1))
        self._maus = (x, y)

    async def human_click(self, x: float, y: float) -> None:
        # nicht exakt den Punkt treffen, kurz verharren, dann drücken
        x += random.uniform(-3, 3)
        y += random.uniform(-2, 2)
        await self.human_move(x, y)
        await asyncio.sleep(random.uniform(0.08, 0.35))
        await self.page.send(uc.cdp.input_.dispatch_mouse_event("mousePressed", x=x, y=y, button=uc.cdp.input_.MouseButton("left"), buttons=1, click_count=1))
        await asyncio.sleep(random.uniform(0.04, 0.14))
        await self.page.send(uc.cdp.input_.dispatch_mouse_event("mouseReleased", x=x, y=y, button=uc.cdp.input_.MouseButton("left"), buttons=1, click_count=1))
        await asyncio.sleep(random.uniform(0.25, 0.7))

    async def human_type(self, text: str) -> None:
        """Tippt in das fokussierte Feld — Zeichen für Zeichen, mit Rhythmus"""
        text = str(text)
        i = 0
        while i < len(text):
            ch = text[i]
            # gelegentlich ein Vertipper, der sofort korrigiert wird (nur bei Buchstaben)
            if ch.isalpha() and random.random() < 0.035:
                falsch = random.choice("asdfghjkl")
                await self.page.send(uc.cdp.input_.dispatch_key_event("char", text=falsch))
                await asyncio.sleep(random.uniform(0.15, 0.4))
                await self.page.send(uc.cdp.input_.dispatch_key_event("keyDown", key="Backspace", code="Backspace", windows_virtual_key_code=8))
                await self.page.send(uc.cdp.input_.dispatch_key_event("keyUp", key="Backspace", code="Backspace", windows_virtual_key_code=8))
                await asyncio.sleep(random.uniform(0.12, 0.3))
            await self.page.send(uc.cdp.input_.dispatch_key_event("char", text=ch))
            i += 1
            if ch in "@._-":
                pause = random.uniform(0.18, 0.45)      # Sonderzeichen: kurz suchen
            elif random.random() < 0.08:
                pause = random.uniform(0.35, 0.9)       # Denkpause
            else:
                pause = random.gauss(0.11, 0.035)       # normaler Rhythmus
            await asyncio.sleep(max(0.03, pause))
        await asyncio.sleep(random.uniform(0.2, 0.6))

    async def element_center(self, elem: Any) -> tuple[float, float] | None:
        try:
            pos = await elem.get_position()
            if pos is None:
                return None
            return (pos.left + pos.width / 2, pos.top + pos.height / 2)
        except Exception:  # noqa: BLE001
            return None

    async def click(self, selector: str, timeout: float = 10, what: str = "") -> None:
        elem = await self.find(selector, timeout, what)
        try:
            await elem.scroll_into_view()
        except Exception:  # noqa: BLE001
            pass
        mitte = await self.element_center(elem)
        if mitte is None:
            await elem.click()
        else:
            await self.human_click(*mitte)
        await self.pause()

    async def type_into(self, selector: str, text: str, timeout: float = 10, what: str = "") -> None:
        elem = await self.find(selector, timeout, what)
        try:
            await elem.scroll_into_view()
        except Exception:  # noqa: BLE001
            pass
        mitte = await self.element_center(elem)
        if mitte is None:
            await elem.apply("(e) => e.focus()")
        else:
            await self.human_click(*mitte)
        # vorhandenen Inhalt markieren und löschen, wie ein Mensch mit Strg+A
        await elem.apply("(e) => { e.focus(); e.select && e.select(); }")
        await asyncio.sleep(random.uniform(0.1, 0.25))
        await self.page.send(uc.cdp.input_.dispatch_key_event("keyDown", key="Backspace", code="Backspace", windows_virtual_key_code=8))
        await self.page.send(uc.cdp.input_.dispatch_key_event("keyUp", key="Backspace", code="Backspace", windows_virtual_key_code=8))
        await self.human_type(text)
        await self.pause()

    async def set_value(self, element_id: str, value: str) -> None:
        """Wert eines React-Feldes über den nativen Setter (löst onChange aus)"""
        await self.find(f"#{element_id}", 10, element_id)
        ok = await self.js(f"""
            const el = document.getElementById({json.dumps(element_id)});
            if (!el) return false;
            const proto = el.tagName.toLowerCase() === 'textarea' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, {json.dumps(value)});
            el.dispatchEvent(new Event('input', {{bubbles: true}}));
            el.dispatchEvent(new Event('change', {{bubbles: true}}));
            return true;""")
        if not ok:
            raise WorkerError("ELEMENT_NOT_FOUND", f"Feld nicht gefunden: {element_id}")
        await self.pause(200, 400)

    async def pause(self, min_ms: int = 400, max_ms: int = 900) -> None:
        await asyncio.sleep(random.uniform(min_ms, max_ms) / 1000)

    async def accept_gdpr(self) -> None:
        elem = await self.probe("#gdpr-banner-accept", 2)
        if elem is not None:
            await elem.click()
            await self.pause()

    async def has_captcha(self) -> bool:
        for sel in CAPTCHA_IFRAMES:
            if await self.probe(sel) is not None:
                return True
        return False

    async def screenshot_b64(self) -> str:
        try:
            data = await self.page.save_screenshot(as_base64=True, format="png")
            return str(data or "")
        except Exception:  # noqa: BLE001
            return ""

    async def fetch_json(self, url: str, method: str = "GET", headers: dict | None = None) -> dict:
        """fetch() aus der Seite heraus — mit Cookies und CSRF der Sitzung"""
        result = await self.js(f"""
            const r = await fetch({json.dumps(url)}, {{method: {json.dumps(method)}, redirect: 'follow', headers: {json.dumps(headers or {})}}});
            const text = await r.text();
            return {{status: r.status, text}};""")
        if not isinstance(result, dict):
            raise WorkerError("FETCH_FAILED", f"Keine Antwort von {url}")
        return result

    async def csrf_token(self) -> str:
        token = await self.js("""
            const meta = document.querySelector('meta[name="_csrf"]')?.getAttribute('content');
            if (meta && meta.trim()) return meta;
            for (const el of document.querySelectorAll('[data-initialprops]')) {
                try { const t = JSON.parse(el.getAttribute('data-initialprops'))?.webContext?.csrfToken; if (t) return t; } catch (e) {}
            }
            return null;""")
        if not isinstance(token, str) or not token.strip():
            raise WorkerError("NO_CSRF", "CSRF-Token der Sitzung nicht gefunden")
        return token


SESSIONS: dict[str, Session] = {}


def session_for(account: str) -> Session:
    if account not in SESSIONS:
        SESSIONS[account] = Session(account=account)
    return SESSIONS[account]


# ── Anmeldung ──


async def is_logged_in(s: Session, email: str) -> bool:
    for sel in LOGGED_IN_SELECTORS:
        elem = await s.probe(sel, 2)
        if elem is None:
            continue
        try:
            text = await elem.apply("(e) => (e.textContent || '')")
        except Exception:  # noqa: BLE001
            text = ""
        if email and email.lower() in str(text).lower():
            return True
    return False


async def login_state(s: Session, email: str) -> dict:
    """Wo steht die Anmeldung gerade? ok, code_required, captcha, logged_out"""
    url = await s.url()
    if await is_logged_in(s, email):
        return {"state": "ok", "url": url}
    for sel in CODE_INPUT_SELECTORS:
        if await s.probe(sel) is not None:
            hint = await s.js("return (document.body?.innerText || '').slice(0, 600)")
            kind = "sms" if re.search(r"Telefonnummer|SMS", str(hint)) else "email"
            return {"state": "code_required", "kind": kind, "url": url}
    if await s.probe_text("Um dein Konto zu schützen haben wir dir eine E-Mail geschickt") is not None:
        return {"state": "code_required", "kind": "email", "url": url}
    if await s.has_captcha() or await s.probe("[data-captcha-provider], .cf-turnstile, .g-recaptcha") is not None:
        return {"state": "captcha", "url": url}
    if await s.probe_text("IP-Bereich vorübergehend gesperrt") is not None:
        return {"state": "blocked", "url": url, "message": "Kleinanzeigen hat den IP-Bereich vorübergehend gesperrt"}
    return {"state": "logged_out", "url": url}


async def do_status(s: Session, email: str) -> dict:
    await s.open(ROOT_URL + "/", reload=True)
    await s.accept_gdpr()
    state = await login_state(s, email)
    state["logged_in"] = state["state"] == "ok"
    s.email = email or s.email
    return state


async def do_login(s: Session, email: str, password: str) -> dict:
    s.email = email
    await s.open(ROOT_URL + "/", reload=True)
    await s.accept_gdpr()
    if await is_logged_in(s, email):
        return {"state": "ok", "message": "bereits angemeldet"}

    # Auth0 in zwei Schritten: E-Mail, dann Passwort
    await s.open(ROOT_URL + "/m-einloggen-sso.html", reload=True)
    deadline = time.time() + 20
    while time.time() < deadline:
        url = await s.url()
        if "login.kleinanzeigen.de" in url or "/u/login" in url:
            break
        await asyncio.sleep(0.5)
    else:
        if await is_logged_in(s, email):
            return {"state": "ok"}
        raise WorkerError("LOGIN_PAGE", "Die Anmeldeseite (Auth0) wurde nicht erreicht", url=await s.url(), screenshot=await s.screenshot_b64())

    await s.type_into("#username", email, 15, "E-Mail-Feld")
    await s.click(AUTH0_SUBMIT, 10, "Weiter")

    # Captcha auf der E-Mail-Seite (Turnstile) wartet meist selbst auf sein Token
    deadline = time.time() + 25
    while time.time() < deadline and "/u/login/password" not in await s.url():
        token_ready = await s.js("return (document.querySelector(\"input[name='captcha']\")?.value?.length || 0) > 0")
        if token_ready:
            btn = await s.probe(AUTH0_SUBMIT)
            if btn is not None:
                await btn.click()
        await asyncio.sleep(1)
    if "/u/login/password" not in await s.url():
        state = await login_state(s, email)
        if state["state"] == "captcha":
            return {"state": "captcha", "message": "Captcha auf der Anmeldeseite — bitte im sichtbaren Browser lösen (KA_HEADLESS=0)", "screenshot": await s.screenshot_b64()}
        raise WorkerError("LOGIN_EMAIL_STEP", "Nach der E-Mail kam keine Passwortseite", url=await s.url(), screenshot=await s.screenshot_b64())

    await s.type_into("input[type='password']", password, 15, "Passwortfeld")
    if await s.has_captcha():
        return {"state": "captcha", "message": "Captcha vor dem Passwort — bitte im sichtbaren Browser lösen", "screenshot": await s.screenshot_b64()}
    await s.click(AUTH0_SUBMIT, 10, "Anmelden")

    # Ergebnis abwarten: zurück auf kleinanzeigen.de, Code-Abfrage oder Fehler
    deadline = time.time() + 30
    while time.time() < deadline:
        state = await login_state(s, email)
        if state["state"] in ("ok", "code_required", "captcha", "blocked"):
            break
        url = await s.url()
        if "/u/login/password" in url:
            error = await s.js("""
                for (const sel of ["[role='alert']", ".ulp-input-error-message", "#error-element-password"]) {
                    const el = document.querySelector(sel); if (el && el.innerText.trim()) return el.innerText.trim();
                }
                return null;""")
            if error:
                return {"state": "failed", "message": str(error)}
        await asyncio.sleep(1)
    else:
        state = await login_state(s, email)

    if state["state"] == "ok":
        await s.open(ROOT_URL + "/", reload=True)
        await s.accept_gdpr()
        return {"state": "ok"}
    if state["state"] == "code_required":
        return {"state": "code_required", "kind": state.get("kind", "email"),
                "message": "Kleinanzeigen verlangt einen Bestätigungscode"}
    if state["state"] in ("captcha", "blocked"):
        return {"state": state["state"], "message": state.get("message", "Sicherheitsabfrage"), "screenshot": await s.screenshot_b64()}
    raise WorkerError("LOGIN_UNCONFIRMED", "Anmeldung nicht bestätigt", url=await s.url(), screenshot=await s.screenshot_b64())


async def do_manual_login(s: Session, email: str) -> dict:
    """Öffnet die Anmeldeseite im (sichtbaren) Browser und rührt nichts an —
    der Benutzer meldet sich selbst an, auch durch Captcha oder Code hindurch.
    Danach bestätigt /session/status die Sitzung; das Profil behält sie."""
    s.email = email or s.email
    await s.open(ROOT_URL + "/", reload=True)
    await s.accept_gdpr()
    if await is_logged_in(s, s.email):
        return {"state": "ok", "message": "bereits angemeldet"}
    await s.open(ROOT_URL + "/m-einloggen-sso.html", reload=True)
    try:
        await s.page.activate()
    except Exception:  # noqa: BLE001
        pass
    return {"state": "manual", "headless": HEADLESS, "url": await s.url(),
            "message": "Anmeldeseite geöffnet — bitte im Browserfenster anmelden" if not HEADLESS
                       else "Der Dienst läuft unsichtbar (KA_HEADLESS=1) — für die Anmeldung von Hand KA_HEADLESS=0 und DISPLAY setzen"}


# ── Fernsteuerung: Bild des Browsers, Klicks und Tasten aus dem ERP ──
#
# Der Benutzer sitzt nicht am Server. Die Kanalkarte zeigt ihm das Bild des
# Browsers, Klicks und Eingaben gehen als CDP-Ereignisse an die Seite — so
# meldet er sich selbst an, auch durch Captcha oder Code hindurch.

REMOTE_KEYS = {
    "Enter": ("Enter", 13, "\r"), "Tab": ("Tab", 9, None), "Backspace": ("Backspace", 8, None),
    "Escape": ("Escape", 27, None), "Delete": ("Delete", 46, None),
    "ArrowDown": ("ArrowDown", 40, None), "ArrowUp": ("ArrowUp", 38, None),
    "ArrowLeft": ("ArrowLeft", 37, None), "ArrowRight": ("ArrowRight", 39, None),
}


async def quick_logged_in(s: Session, email: str) -> bool:
    """Angemeldet? Eine DOM-Abfrage ohne Wartezeit — für jedes Bild der Fernsteuerung."""
    if not email:
        return False
    try:
        return bool(await s.js(f"""
            const mail = {json.dumps(email.lower())};
            for (const sel of {json.dumps(list(LOGGED_IN_SELECTORS))}) {{
                const el = document.querySelector(sel);
                if (el && (el.textContent || '').toLowerCase().includes(mail)) return true;
            }}
            return false;"""))
    except WorkerError:
        return False


async def do_remote(s: Session, email: str, op: str, body: dict) -> dict:
    await s.ensure_browser()
    if s.page is None or op == "open":
        url = str(body.get("url") or "") or (ROOT_URL + "/m-einloggen-sso.html")
        await s.open(url, reload=True)
        await asyncio.sleep(1.5)
        await s.accept_gdpr()
    elif op == "click":
        x, y = float(body.get("x") or 0), float(body.get("y") or 0)
        await s.human_click(x, y)
        await asyncio.sleep(0.5)
    elif op == "type":
        await s.human_type(str(body.get("text") or ""))
    elif op == "fill":
        # Zugangsdaten in das passende Feld der Anmeldeseite: Feld suchen,
        # anklicken, tippen — der Wert kommt aus OSERP (E-Mail oder Passwort)
        feld = str(body.get("field") or "email")
        selector = {"email": "#username, input[name='username'], input[type='email']",
                    "password": "input[type='password']", "code": "input[autocomplete='one-time-code'], input[name='code']"}.get(feld, feld)
        elem = None
        for sel in selector.split(", "):
            elem = await s.probe(sel, 1)
            if elem is not None:
                break
        if elem is None:
            raise WorkerError("FIELD_NOT_FOUND", f"Kein Feld für {feld} auf dieser Seite")
        await s.type_into(sel, str(body.get("text") or ""), 5, feld)
        if body.get("enter"):
            await asyncio.sleep(random.uniform(0.3, 0.8))
            await s.page.send(uc.cdp.input_.dispatch_key_event("keyDown", key="Enter", code="Enter", windows_virtual_key_code=13, text="\r"))
            await s.page.send(uc.cdp.input_.dispatch_key_event("keyUp", key="Enter", code="Enter", windows_virtual_key_code=13))
            await asyncio.sleep(1.5)
    elif op == "key":
        name = str(body.get("key") or "Enter")
        key, vk, text = REMOTE_KEYS.get(name, REMOTE_KEYS["Enter"])
        await s.page.send(uc.cdp.input_.dispatch_key_event("keyDown", key=key, code=key, windows_virtual_key_code=vk, native_virtual_key_code=vk, text=text))
        await s.page.send(uc.cdp.input_.dispatch_key_event("keyUp", key=key, code=key, windows_virtual_key_code=vk, native_virtual_key_code=vk))
        await asyncio.sleep(1.0)
    elif op == "scroll":
        await s.js(f"window.scrollBy(0, {int(body.get('dy') or 400)}); return true")
        await asyncio.sleep(0.4)
    elif op == "view":
        pass
    else:
        raise WorkerError("REMOTE_OP", "Unbekannte Aktion: " + op)

    masse = await s.js("return {w: window.innerWidth, h: window.innerHeight, title: document.title}") or {}
    try:
        bild = await s.page.save_screenshot(as_base64=True, format="jpeg")
    except Exception:  # noqa: BLE001
        bild = ""
    return {"png": "", "jpeg": str(bild or ""), "url": await s.url(), "title": str(masse.get("title", "")),
            "width": int(masse.get("w") or 1400), "height": int(masse.get("h") or 1000),
            "logged_in": await quick_logged_in(s, email or s.email)}


async def do_code(s: Session, code: str) -> dict:
    await s.ensure_browser()
    if s.page is None:
        raise WorkerError("NO_LOGIN_FLOW", "Es läuft keine Anmeldung")
    field_ = None
    for sel in CODE_INPUT_SELECTORS:
        field_ = await s.probe(sel, 2)
        if field_ is not None:
            await s.type_into(sel, code, 5, "Code-Feld")
            break
    if field_ is None:
        raise WorkerError("NO_CODE_FIELD", "Kein Feld für den Bestätigungscode auf der Seite", screenshot=await s.screenshot_b64())
    btn = await s.probe(AUTH0_SUBMIT, 2) or await s.probe("button[type='submit']", 2)
    if btn is not None:
        await btn.click()
    else:
        await field_.send_keys("\n")
    deadline = time.time() + 30
    while time.time() < deadline:
        if await is_logged_in(s, s.email):
            await s.open(ROOT_URL + "/", reload=True)
            await s.accept_gdpr()
            return {"state": "ok"}
        # „Dieses Gerät merken“ oder ähnliche Zwischenseiten: weiter klicken
        cont = await s.probe(AUTH0_SUBMIT)
        if cont is not None and "/u/" in await s.url():
            try:
                await cont.click()
            except Exception:  # noqa: BLE001
                pass
        await asyncio.sleep(1)
    state = await login_state(s, s.email)
    if state["state"] == "ok":
        return {"state": "ok"}
    return {"state": "failed", "message": "Code nicht angenommen oder Anmeldung nicht bestätigt", "detail": state, "screenshot": await s.screenshot_b64()}


async def require_login(s: Session, email: str) -> None:
    await s.ensure_browser()
    if s.page is None or "kleinanzeigen.de" not in await s.url():
        await s.open(ROOT_URL + "/", reload=True)
        await s.accept_gdpr()
    if not await is_logged_in(s, email):
        await s.open(ROOT_URL + "/", reload=True)
        await s.accept_gdpr()
        if not await is_logged_in(s, email):
            raise WorkerError("NOT_LOGGED_IN", "Nicht angemeldet — bitte zuerst /session/login")


# ── Meine Anzeigen ──


async def do_list_ads(s: Session, email: str) -> dict:
    await require_login(s, email)
    ads: list[dict] = []
    page = 1
    while page <= 100:
        resp = await s.fetch_json(f"{ROOT_URL}/m-meine-anzeigen-verwalten.json?sort=DEFAULT&pageNum={page}")
        if resp.get("status") != 200:
            raise WorkerError("ADS_LIST_FAILED", f"Anzeigenliste: HTTP {resp.get('status')}")
        try:
            data = json.loads(resp.get("text") or "{}")
        except json.JSONDecodeError as ex:
            raise WorkerError("ADS_LIST_FAILED", "Anzeigenliste: keine JSON-Antwort (Sitzung abgelaufen?)") from ex
        for entry in data.get("ads", []) or []:
            if isinstance(entry, dict) and "id" in entry:
                ads.append(entry)
        paging = data.get("paging") or {}
        try:
            current = int(paging.get("pageNum") or page)
            last = int(paging.get("last") or current)
            nxt = int(paging.get("next") or 0)
        except (TypeError, ValueError):
            break
        if current >= last or nxt <= current or not data.get("ads"):
            break
        page = nxt
    return {"ads": ads, "count": len(ads)}


async def do_delete_ad(s: Session, email: str, ad_id: str) -> dict:
    await require_login(s, email)
    await s.open(f"{ROOT_URL}/m-meine-anzeigen.html", reload=True)
    await s.accept_gdpr()
    token = await s.csrf_token()
    resp = await s.fetch_json(f"{ROOT_URL}/m-anzeigen-loeschen.json?ids={int(ad_id)}", "POST", {"x-csrf-token": token})
    status = int(resp.get("status") or 0)
    if status not in (200, 404):
        raise WorkerError("DELETE_FAILED", f"Löschen: HTTP {status}")
    return {"deleted": status == 200, "status": status}


async def do_extend_ad(s: Session, email: str, ad_id: str) -> dict:
    await require_login(s, email)
    await s.open(f"{ROOT_URL}/m-meine-anzeigen.html?pageNumber=1&keyword=", reload=True)
    await s.accept_gdpr()
    await asyncio.sleep(2)
    clicked = False
    for _ in range(10):
        clicked = await s.js(f"""
            const li = document.querySelector('li[data-adid="{int(ad_id)}"]');
            if (!li) return false;
            const btn = [...li.querySelectorAll('button')].find(b => (b.textContent || '').includes('Verlängern'));
            if (!btn) return false;
            btn.click(); return true;""")
        if clicked:
            break
        nxt = await s.probe('button[aria-label="Nächste"]:not([disabled])')
        if nxt is None:
            break
        await nxt.click()
        await asyncio.sleep(2.5)
    if not clicked:
        raise WorkerError("EXTEND_BUTTON_MISSING", "Kein „Verlängern“ für diese Anzeige (nur in den letzten 8 Tagen vor Ablauf)")
    await s.pause(800, 1500)
    close = await s.probe('button[aria-label="Schließen"]', 3)
    if close is not None:
        await close.click()
    return {"extended": True}


# ── Anzeige einstellen / ändern ──


async def _set_category(s: Session, labels: list[str], numeric_path: str) -> None:
    """Kategorie: erst die automatische Erkennung anstoßen, dann wenn gewünscht
    über den Dialog (Beschriftungen) oder die alten cat_<id>-Links."""
    desc = await s.probe("#ad-description", 10)
    if desc is not None:
        await desc.click()
        await s.pause()
    if not labels and not numeric_path:
        return
    link = await s.probe('a[aria-describedby="ad-category-path"]', 3) or await s.probe_text("Wähle deine Kategorie", 2) or await s.probe_text("Kategorie", 2)
    if link is None:
        LOG.warning("Kategorie-Link nicht gefunden — automatische Erkennung bleibt")
        return
    await link.click()
    await s.pause(800, 1400)

    dialog = await s.js("""
        for (const sel of ['dialog[open]', '[role="dialog"]:not([aria-hidden="true"])']) {
            const d = document.querySelector(sel);
            if (d && /Kategorie wählen|Choose category/.test(d.innerText || '')) return sel;
        }
        return null;""")
    if dialog and labels:
        for depth, label in enumerate(labels):
            ok = await s.js(f"""
                const dlg = document.querySelector({json.dumps(dialog)});
                const norm = t => (t || '').toLowerCase().replace(/ä/g,'ae').replace(/ö/g,'oe').replace(/ü/g,'ue').replace(/ß/g,'ss').replace(/[^a-z0-9]+/g,' ').trim();
                const want = norm({json.dumps(label)});
                let buttons = [...dlg.querySelectorAll('li > button')];
                if ({depth} === 0 && buttons.length === 1 && /alle kategorien|all categories/.test(norm(buttons[0].textContent))) {{
                    buttons[0].click(); await new Promise(r => setTimeout(r, 700));
                    buttons = [...dlg.querySelectorAll('li > button')];
                }}
                const exact = buttons.filter(b => norm(b.textContent) === want);
                const loose = buttons.filter(b => norm(b.textContent).includes(want) || want.includes(norm(b.textContent)));
                const hit = exact[0] || (loose.length === 1 ? loose[0] : null);
                if (!hit) return {{ok: false, offered: buttons.map(b => b.textContent.trim())}};
                hit.click(); return {{ok: true}};""")
            if not isinstance(ok, dict) or not ok.get("ok"):
                offered = ", ".join(str(x) for x in (ok.get("offered", []) if isinstance(ok, dict) else [])[:30]) or "?"
                raise WorkerError("CATEGORY", f"Kategorie „{label}“ nicht im Dialog gefunden. Angeboten: {offered}")
            await s.pause(500, 900)
        applied = await s.js(f"""
            const dlg = document.querySelector({json.dumps(dialog)});
            const btn = [...dlg.querySelectorAll('button')].find(b => ['Übernehmen', 'Apply'].includes((b.textContent || '').trim()));
            if (!btn) return false; btn.click(); return true;""")
        if not applied:
            raise WorkerError("CATEGORY", "Kategorie-Dialog hat keinen Übernehmen-Knopf")
        await s.pause(800, 1400)
        return

    if numeric_path:
        for segment in [p for p in numeric_path.split("/") if p]:
            elem = await s.probe(f"#cat_{segment}", 6)
            if elem is None:
                raise WorkerError("CATEGORY", f"Kategorie-Eintrag cat_{segment} nicht gefunden")
            await elem.click()
            await s.pause()
        weiter = await s.probe_text("Weiter", 5)
        if weiter is not None:
            await weiter.click()
            await s.pause(800, 1400)
    # Vorschlagsliste der neuen Seite, wenn der Pfad nicht aufgelöst wurde
    picker = await s.probe("#ad-category-picker", 2)
    if picker is not None:
        chosen = await s.js(f"""
            const wanted = {json.dumps([p for p in (numeric_path or '').split('/') if p][::-1])};
            const radios = [...document.querySelectorAll("#ad-category-picker input[type='radio'][name='category-suggestions']")];
            for (const w of wanted) {{ const r = radios.find(x => x.value === w); if (r) {{ const l = document.querySelector(`#ad-category-picker label[for='${{r.id}}']`); (l || r).click(); return true; }} }}
            if (radios.length) {{ const l = document.querySelector(`#ad-category-picker label[for='${{radios[0].id}}']`); (l || radios[0]).click(); return true; }}
            return false;""")
        LOG.info("Kategorie-Vorschlag gewählt: %s", chosen)


async def _set_condition(s: Session, condition: str) -> None:
    value = CONDITION_MAP.get(condition, condition)
    if not value:
        return
    trigger = await s.js("""
        const labels = document.querySelectorAll('label[for*=".condition"]');
        for (const lbl of labels) {
            let a = lbl.parentElement;
            for (let d = 0; d < 5 && a; d++, a = a.parentElement) {
                const btn = a.querySelector('button[aria-haspopup="dialog"], button[aria-haspopup="true"]');
                if (btn) { btn.setAttribute('data-oserp-condition', '1'); return {found: true, id: btn.id || ''}; }
            }
        }
        return {found: false};""")
    if isinstance(trigger, dict) and trigger.get("found") and "shipping" not in str(trigger.get("id", "")).lower():
        btn = await s.probe("button[data-oserp-condition='1']", 3)
        if btn is not None:
            await btn.click()
            await s.pause(600, 1000)
            picked = await s.js(f"""
                for (const sel of ['dialog[open]', '[role="dialog"]:not([aria-hidden="true"])']) {{
                    const d = document.querySelector(sel); if (!d) continue;
                    for (const v of {json.dumps([value, condition])}) {{
                        const r = d.querySelector(`input[type="radio"][value="${{v}}"]`);
                        if (r) {{ const l = r.id ? d.querySelector(`label[for="${{r.id}}"]`) : null; (l || r).click(); return true; }}
                    }}
                }}
                return false;""")
            ok_btn = await s.probe_text("Bestätigen", 3)
            if ok_btn is not None:
                await ok_btn.click()
                await s.pause()
            if picked:
                return
    # Rückfall: Combobox/Select für den Zustand
    done = await s.js(f"""
        const el = document.querySelector("[id$='.condition'], [name*='condition']");
        if (!el) return 'missing';
        if (el.tagName === 'SELECT') {{ el.value = {json.dumps(value)}; el.dispatchEvent(new Event('change', {{bubbles: true}})); return 'select'; }}
        return 'other';""")
    LOG.info("Zustand gesetzt über: %s", done)


async def _select_button_combobox(s: Session, elem_id: str, wanted_text: str) -> bool:
    result = await s.js(f"""
        const btn = document.getElementById({json.dumps(elem_id)});
        if (!btn) return false;
        for (const t of ['pointerdown', 'mousedown', 'mouseup', 'click']) btn.dispatchEvent(new (t.startsWith('pointer') ? PointerEvent : MouseEvent)(t, {{bubbles: true, cancelable: true}}));
        const deadline = Date.now() + 4000;
        const norm = t => (t || '').replace(/\\s+/g, ' ').trim().toLowerCase();
        while (Date.now() < deadline) {{
            await new Promise(r => setTimeout(r, 50));
            let box = document.getElementById({json.dumps(elem_id + '-menu')}) || btn.parentElement?.querySelector('[role="listbox"]')
                || (btn.getAttribute('aria-controls') ? document.getElementById(btn.getAttribute('aria-controls')) : null);
            if (!box) {{ for (const c of document.querySelectorAll('[role="listbox"],[role="menu"]')) {{ const r = c.getBoundingClientRect(); if (r.width > 0 && r.height > 0) {{ box = c; break; }} }} }}
            if (!box) continue;
            const opts = [...box.querySelectorAll('[role="option"]')];
            if (!opts.length) continue;
            const hit = opts.find(o => norm(o.textContent) === norm({json.dumps(wanted_text)})) || opts.find(o => norm(o.textContent).includes(norm({json.dumps(wanted_text)})));
            if (!hit) return false;
            hit.click(); return true;
        }}
        return false;""")
    return bool(result)


async def _set_shipping(s: Session, shipping: str) -> None:
    label = SHIPPING_LABELS.get(shipping, "Versand möglich")
    combo = await s.js("""
        for (const sel of ['button[role="combobox"][id="versand"]', 'button[role="combobox"][id$=".versand"]', 'button[role="combobox"][aria-labelledby$="versand-selected-option"]']) {
            const b = document.querySelector(sel); if (b) return b.id;
        }
        return null;""")
    if combo:
        if await _select_button_combobox(s, str(combo), label):
            return
        LOG.warning("Versand-Combobox: „%s“ nicht wählbar", label)
    if label == "Nur Abholung":
        radio = await s.probe("#ad-shipping-enabled-no", 3)
    else:
        radio = await s.probe("#ad-shipping-enabled-yes", 3)
    if radio is not None:
        await radio.click()
        await s.pause()


async def _set_price(s: Session, price: Any, price_type: str) -> None:
    idx = PRICE_TYPE_INDEX.get(price_type, 0)
    btn = await s.probe("#ad-price-type", 5)
    if btn is not None:
        await btn.click()
        await s.pause(300, 600)
        opt = await s.probe(f"#ad-price-type-menu-option-{idx}", 4)
        if opt is not None:
            await opt.click()
            await s.pause()
    if idx != 2 and price not in (None, ""):
        await s.set_value("ad-price-amount", str(int(round(float(price)))))


async def _upload_images(s: Session, images: list[str]) -> None:
    # vorhandene Bilder entfernen (Ändern): wir kennen nicht, welche sich geändert haben
    for _ in range(30):
        btn = await s.probe("button[aria-label='Bild entfernen']")
        if btn is None:
            break
        await btn.click()
        await s.pause(300, 500)
    if not images:
        return
    existing = [p for p in images if os.path.isfile(p)]
    if not existing:
        LOG.warning("Keine der Bilddateien existiert: %s", images)
        return
    baseline = await s.js("return [...document.querySelectorAll(\"input[name^='adImages'][name$='.url']\")].filter(i => (i.value || '').trim()).length")
    for path in existing:
        inp = await s.find("input[type=file]", 10, "Bild-Upload")
        await inp.send_file(path)
        await s.pause(600, 1000)
    deadline = time.time() + 90
    while time.time() < deadline:
        count = await s.js("return [...document.querySelectorAll(\"input[name^='adImages'][name$='.url']\")].filter(i => (i.value || '').trim()).length")
        if int(count or 0) - int(baseline or 0) >= len(existing):
            return
        await asyncio.sleep(1)
    raise WorkerError("IMAGE_UPLOAD", "Nicht alle Bilder wurden verarbeitet")


async def _form_errors(s: Session) -> list[str]:
    result = await s.js("""
        const form = document.getElementById('ad-title')?.closest('form'); if (!form) return [];
        const visible = e => e && e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
        const out = new Set();
        for (const f of form.querySelectorAll('[aria-invalid="true"], input:invalid, select:invalid, textarea:invalid')) {
            if (!visible(f)) continue;
            const refs = (f.getAttribute('aria-errormessage') || f.getAttribute('aria-describedby') || '').trim().split(/\\s+/).filter(Boolean);
            const msgs = refs.map(id => document.getElementById(id)).filter(e => e && visible(e)).map(e => e.innerText.trim()).filter(Boolean);
            const label = form.querySelector(`label[for="${f.id}"]`)?.innerText?.trim() || f.name || f.id;
            const m = msgs.join(' ') || f.validationMessage || '';
            if (m) out.add(label ? label + ': ' + m : m);
        }
        for (const e of form.querySelectorAll('[id$="-error"]')) if (visible(e) && e.innerText.trim()) out.add(e.innerText.trim());
        return [...out];""")
    return [str(x) for x in result] if isinstance(result, list) else []


async def _submit_form(s: Session, title: str) -> dict:
    """Absenden mit Paketwahl (kostenloses Basis-Paket) und Hinweis-Dialogen"""
    await s.set_value("ad-title", title)
    await s.pause()

    def script(action: str) -> str:
        return f"""
            if (location.pathname.startsWith('/p-anzeigentypauswahl/')) return 'package';
            const visible = e => e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden' && !e.matches(':disabled');
            const scope = document.getElementById('ad-title')?.closest('form') || document;
            const buttons = [...scope.querySelectorAll('button')].filter(visible);
            let submit = null;
            for (const l of {json.dumps(list(SUBMIT_LABELS))}) {{ submit = buttons.find(b => b.textContent.trim().includes(l)); if (submit) break; }}
            if (submit) {{ if ({json.dumps(action)} === 'inspect') return 'submit'; if ({json.dumps(action)} !== 'submit') return false; submit.click(); return true; }}
            const next = buttons.find(b => b.textContent.replace(/\\s+/g, ' ').trim() === 'Nächster Schritt');
            if (next) {{ if ({json.dumps(action)} === 'inspect') return 'next'; if ({json.dumps(action)} !== 'next') return false; next.click(); return true; }}
            return false;"""

    state = None
    for _ in range(20):
        state = await s.js(script("inspect"))
        if state:
            break
        await asyncio.sleep(0.5)
    if not state:
        errors = await _form_errors(s)
        raise WorkerError("SUBMIT_BUTTON", "Kein Absenden-Knopf gefunden" + (": " + "; ".join(errors) if errors else ""))

    if state == "next":
        await s.js(script("next"))
        await s.pause(1500, 2500)
        hint = await s.probe_text("Ohne Bild veröffentlichen", 3)
        if hint is not None:
            await hint.click()
            await s.pause()
    if state in ("next", "package"):
        # Paketwahl: nur das kostenlose Basis-Paket
        deadline = time.time() + 30
        while time.time() < deadline:
            result = await s.js("""
                if (!location.pathname.startsWith('/p-anzeigentypauswahl/')) return {state: 'waiting'};
                const visible = e => e && e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
                const text = e => (e?.innerText || '').replace(/\\s+/g, ' ').trim();
                const radios = [...document.querySelectorAll('button[role="radio"]')].filter(visible);
                const basis = radios.find(r => text(r) === 'Basis Paket');
                if (!basis) return {state: 'waiting', offered: radios.map(text)};
                let card = basis;
                while (card.parentElement && card.parentElement !== document.body && !radios.some(r => r !== basis && card.parentElement.contains(r))) card = card.parentElement;
                const free = [...card.querySelectorAll('*')].some(e => visible(e) && text(e) === 'Kostenlos') && !/\\d[\\d.,]*\\s*(€|EUR)/i.test(text(card));
                if (!free) return {state: 'unsafe', card: text(card).slice(0, 200)};
                if (basis.getAttribute('aria-checked') !== 'true') { basis.click(); return {state: 'selected'}; }
                const btn = [...document.querySelectorAll('button')].filter(e => visible(e) && !e.disabled && text(e) === 'Anzeige aufgeben');
                if (btn.length !== 1) return {state: 'waiting'};
                btn[0].click(); return {state: 'submitted'};""")
            if isinstance(result, dict):
                if result.get("state") == "submitted":
                    break
                if result.get("state") == "unsafe":
                    raise WorkerError("PACKAGE", "Das Basis-Paket ist nicht kostenlos — nicht automatisch eingestellt: " + str(result.get("card", "")))
            await asyncio.sleep(1)
        else:
            raise WorkerError("PACKAGE", "Paketwahl nicht abgeschlossen")
    else:
        await s.js(script("submit"))
    await s.pause(1000, 1800)

    errors = await _form_errors(s)
    if errors:
        raise WorkerError("FORM_VALIDATION", "Kleinanzeigen lehnt das Formular ab: " + "; ".join(errors))

    # Hinweise nach dem Absenden
    for _ in range(3):
        upsell = await s.probe_text("Effektiver verkaufen", 2)
        if upsell is not None:
            for lbl in ("Ohne Top-Anzeige weiter", "Ohne Hochschieben weiter"):
                b = await s.probe_text(lbl, 2)
                if b is not None:
                    await b.click()
                    await s.pause()
                    break
        imprint = await s.probe("#imprint-guidance-submit", 1)
        if imprint is not None:
            await imprint.click()
            await s.pause()
        hint = await s.probe_text("Ohne Bild veröffentlichen", 1)
        if hint is not None:
            await hint.click()
            await s.pause()
        payment = await s.probe("#myftr-shppngcrt-frm", 1)
        if payment is not None:
            raise WorkerError("PAYMENT_REQUIRED", "Kleinanzeigen verlangt eine Zahlung (kostenpflichtige Anzeige) — nicht automatisch eingestellt")

    # Bestätigung: adId in der Adresse oder „Geschafft!“
    deadline = time.time() + 60
    while time.time() < deadline:
        url = await s.url()
        if "p-anzeige-aufgeben-bestaetigung.html?adId=" in url:
            ad_id = parse_qs(urlparse(url).query).get("adId", [""])[0]
            if ad_id:
                return {"id": ad_id}
        done = await s.js("""
            const t = (document.body?.innerText || '').replace(/\\s+/g, ' ');
            return t.includes('Geschafft!') && [...document.querySelectorAll('a, button')].some(e => (e.innerText || '').includes('Zu meinen Anzeigen'));""")
        if done:
            return {"id": ""}
        errors = await _form_errors(s)
        if errors:
            raise WorkerError("FORM_VALIDATION", "Kleinanzeigen lehnt das Formular ab: " + "; ".join(errors))
        await asyncio.sleep(1)
    # Verweis im Dokument (schnelle Weiterleitung)
    found = await s.js("""
        const m = (document.referrer || '').match(/adId=(\\d+)/) || [...document.querySelectorAll('script')].map(s => s.textContent).join('\\n').match(/p-anzeige-aufgeben-bestaetigung\\.html\\?adId=(\\d+)/);
        return m ? m[1] : null;""")
    if found:
        return {"id": str(found)}
    raise WorkerError("SUBMIT_UNCONFIRMED", "Einstellen nicht bestätigt — bitte „Meine Anzeigen“ prüfen", url=await s.url(), screenshot=await s.screenshot_b64())


async def do_publish(s: Session, email: str, ad: dict) -> dict:
    await require_login(s, email)
    ad_id = str(ad.get("id") or "").strip()
    before = {str(a.get("id")) for a in (await do_list_ads(s, email))["ads"]} if not ad_id else set()

    if ad_id:
        # Bearbeiten über die Übersicht (bewahrt den Formular-Kontext der Sitzung)
        await s.open(f"{ROOT_URL}/m-meine-anzeigen.html?pageNumber=1&keyword=", reload=True)
        await s.accept_gdpr()
        await asyncio.sleep(2)
        opened = False
        for _ in range(10):
            link = await s.probe(f'a[href="/p-anzeige-bearbeiten.html?adId={ad_id}"]', 3)
            if link is not None:
                await link.click()
                opened = True
                break
            nxt = await s.probe('button[aria-label="Nächste"]:not([disabled])')
            if nxt is None:
                break
            await nxt.click()
            await asyncio.sleep(2.5)
        if not opened:
            await s.open(f"{ROOT_URL}/p-anzeige-bearbeiten.html?adId={ad_id}", reload=True)
        await s.wait_ready()
    else:
        await s.open(f"{ROOT_URL}/p-anzeige-aufgeben-schritt2.html", reload=True)
    await s.accept_gdpr()
    await s.find("#ad-title", 20, "Anzeigenformular")

    labels = [str(x) for x in (ad.get("category_labels") or []) if str(x).strip()]
    await _set_category(s, labels, str(ad.get("category") or ""))
    await s.pause()
    if ad.get("condition"):
        try:
            await _set_condition(s, str(ad["condition"]))
        except WorkerError as ex:
            LOG.warning("Zustand nicht gesetzt: %s", ex)
    await _set_shipping(s, str(ad.get("shipping") or "both"))
    await _set_price(s, ad.get("price"), str(ad.get("price_type") or "fixed"))
    await s.set_value("ad-description", str(ad.get("description") or ""))
    if ad.get("zipcode"):
        try:
            await s.type_into("#ad-zip-code", str(ad["zipcode"]), 5, "PLZ")
            await s.pause(1000, 1800)
        except WorkerError as ex:
            LOG.warning("PLZ nicht gesetzt: %s", ex)
    if ad.get("contact_name"):
        try:
            ro = await s.js("const e = document.getElementById('ad-name'); return e ? e.readOnly : true")
            if not ro:
                await s.set_value("ad-name", str(ad["contact_name"]))
        except WorkerError:
            pass
    await _upload_images(s, [str(p) for p in (ad.get("images") or [])])
    if await s.has_captcha():
        raise WorkerError("CAPTCHA", "Captcha im Formular — bitte im sichtbaren Browser lösen", screenshot=await s.screenshot_b64())

    result = await _submit_form(s, str(ad.get("title") or ""))
    new_id = result.get("id") or ad_id
    if not new_id:
        # Kennung über die Anzeigenliste nachschlagen (Titelvergleich)
        for delay in (0, 2, 4):
            await asyncio.sleep(delay)
            current = (await do_list_ads(s, email))["ads"]
            cands = [str(a.get("id")) for a in current if str(a.get("title") or "") == str(ad.get("title") or "") and str(a.get("id")) not in before]
            if len(cands) == 1:
                new_id = cands[0]
                break
    if not new_id:
        raise WorkerError("ID_UNKNOWN", "Anzeige wohl eingestellt, Nummer aber nicht ermittelbar — bitte „Meine Anzeigen“ prüfen")
    return {"id": new_id, "url": f"{ROOT_URL}/s-anzeige/{new_id}"}


# ── Nachrichten ──
#
# Die Nachrichtenseite ist eine React-Anwendung; der Dienst sammelt die
# Unterhaltungen aus den eingebetteten Seitendaten und aus den Links der
# Seite. Ändert sich der Aufbau, liefert /debug/page die Rohdaten.


async def do_messages_list(s: Session, email: str, limit: int) -> dict:
    await require_login(s, email)
    await s.open(f"{ROOT_URL}/m-nachrichten.html", reload=True)
    await s.accept_gdpr()
    await asyncio.sleep(3)
    data = await s.js("""
        const out = []; const seen = new Set();
        const push = c => { if (c.id && !seen.has(c.id)) { seen.add(c.id); out.push(c); } };
        // 1) eingebettete Seitendaten
        for (const el of document.querySelectorAll('[data-initialprops], script[type="application/json"]')) {
            let raw = el.getAttribute('data-initialprops') || el.textContent; if (!raw) continue;
            let obj; try { obj = JSON.parse(raw); } catch (e) { continue; }
            const walk = (o, depth) => {
                if (!o || depth > 8) return;
                if (Array.isArray(o)) { o.forEach(x => walk(x, depth + 1)); return; }
                if (typeof o !== 'object') return;
                const id = o.conversationId || o.id;
                if (id && (o.adTitle || o.textShortTrimmed || o.lastMessage || o.counterPartName || o.role)) {
                    push({ id: String(id), ad_id: String(o.adId || o.adID || ''), ad_title: o.adTitle || '',
                           counterpart: o.counterPartName || o.buyerName || o.sellerName || '', last_message: o.textShortTrimmed || o.lastMessage?.text || o.lastMessageText || '',
                           unread: !!(o.unread || o.unreadMessagesCount || o.numUnreadMessages), updated: o.lastMessageDate || o.lastMessageTime || o.updated || '' });
                }
                for (const k of Object.keys(o)) walk(o[k], depth + 1);
            };
            walk(obj, 0);
        }
        // 2) Links und Listeneinträge der Seite
        for (const a of document.querySelectorAll('a[href*="conversationId="], [data-conversationid], [data-conversation-id]')) {
            const href = a.getAttribute('href') || '';
            const m = href.match(/conversationId=([^&]+)/);
            const id = (m && m[1]) || a.getAttribute('data-conversationid') || a.getAttribute('data-conversation-id');
            const text = (a.innerText || '').replace(/\\s+/g, ' ').trim();
            push({ id: String(id), ad_id: '', ad_title: '', counterpart: '', last_message: text.slice(0, 300), unread: /ungelesen|unread/i.test(a.className + ' ' + (a.getAttribute('aria-label') || '')), updated: '' });
        }
        return out;""")
    conversations = data if isinstance(data, list) else []
    return {"conversations": conversations[: max(1, int(limit or 50))], "count": len(conversations)}


async def do_messages_read(s: Session, email: str, conv_id: str) -> dict:
    await require_login(s, email)
    await s.open(f"{ROOT_URL}/m-nachrichten.html?conversationId={conv_id}", reload=True)
    await s.accept_gdpr()
    await asyncio.sleep(3)
    data = await s.js("""
        const msgs = [];
        for (const el of document.querySelectorAll('[data-initialprops], script[type="application/json"]')) {
            let raw = el.getAttribute('data-initialprops') || el.textContent; if (!raw) continue;
            let obj; try { obj = JSON.parse(raw); } catch (e) { continue; }
            const walk = (o, depth) => {
                if (!o || depth > 10) return;
                if (Array.isArray(o)) { o.forEach(x => walk(x, depth + 1)); return; }
                if (typeof o !== 'object') return;
                if ((o.textShort || o.text || o.message) && (o.boundness || o.messageType || o.senderId !== undefined || o.sentAt || o.receivedDate)) {
                    const dir = String(o.boundness || o.direction || '').toUpperCase();
                    msgs.push({ from: dir === 'OUTBOUND' ? 'me' : dir === 'INBOUND' ? 'them' : (o.isOwn ? 'me' : 'them'),
                                text: o.textShort || o.text || o.message, time: o.receivedDate || o.sentAt || o.date || '' });
                }
                for (const k of Object.keys(o)) walk(o[k], depth + 1);
            };
            walk(obj, 0);
        }
        if (!msgs.length) {
            for (const el of document.querySelectorAll('[class*="message" i], [class*="Message" i]')) {
                const t = (el.innerText || '').replace(/\\s+/g, ' ').trim(); if (!t || t.length > 2000) continue;
                const cls = el.className + '';
                msgs.push({ from: /out|own|mine|sent/i.test(cls) ? 'me' : 'them', text: t, time: '' });
            }
        }
        return { messages: msgs, title: document.title, text: (document.body?.innerText || '').slice(0, 4000) };""")
    return data if isinstance(data, dict) else {"messages": [], "text": ""}


async def do_messages_reply(s: Session, email: str, conv_id: str, text: str) -> dict:
    await require_login(s, email)
    await s.open(f"{ROOT_URL}/m-nachrichten.html?conversationId={conv_id}", reload=True)
    await s.accept_gdpr()
    await asyncio.sleep(3)
    box = await s.probe("textarea", 10)
    if box is None:
        raise WorkerError("NO_REPLY_BOX", "Kein Eingabefeld in der Unterhaltung gefunden", screenshot=await s.screenshot_b64())
    await box.click()
    await box.apply("(e) => { const s = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set; s.call(e, ''); e.dispatchEvent(new Event('input', {bubbles: true})); }")
    for ch in text:
        await box.send_keys(ch)
        await asyncio.sleep(random.uniform(0.01, 0.05))
    await s.pause()
    sent = await s.js("""
        const visible = e => e.getClientRects().length > 0 && !e.disabled;
        const btn = [...document.querySelectorAll('button')].filter(visible).find(b => /^(senden|nachricht senden|antworten)$/i.test((b.innerText || '').trim()) || (b.getAttribute('aria-label') || '').toLowerCase().includes('senden') || b.type === 'submit');
        if (!btn) return false; btn.click(); return true;""")
    if not sent:
        await box.send_keys("\n")
    await s.pause(1500, 2500)
    return {"sent": True}


# ── HTTP ──


def json_error(code: str, message: str, status: int = 400, **extra: Any) -> web.Response:
    return web.json_response({"ok": False, "error": code, "message": message, **extra}, status=status)


async def handle(request: web.Request) -> web.Response:
    if TOKEN and request.headers.get("X-KA-Token", "") != TOKEN:
        return json_error("UNAUTHORIZED", "Token fehlt oder falsch", 401)
    path = request.path
    if request.method == "GET" and path == "/health":
        return web.json_response({"ok": True, "version": VERSION, "headless": HEADLESS, "chrome": CHROME,
                                  "sessions": [{"account": k, "browser": v.browser is not None, "email": v.email} for k, v in SESSIONS.items()]})
    if request.method != "POST":
        return json_error("METHOD", "Nur POST", 405)
    try:
        body = await request.json()
    except Exception:  # noqa: BLE001
        body = {}
    account = str(body.get("account") or "default")
    email = str(body.get("email") or "")
    s = session_for(account)
    if not email and s.email:
        email = s.email
    started = time.time()
    async with s.lock:
        try:
            if path == "/session/status":
                result = await do_status(s, email)
            elif path == "/session/login":
                result = await do_login(s, email, str(body.get("password") or ""))
            elif path == "/session/code":
                result = await do_code(s, str(body.get("code") or "").strip())
            elif path == "/session/manual":
                result = await do_manual_login(s, email)
            elif path.startswith("/remote/"):
                result = await do_remote(s, email, path[len("/remote/"):], body)
            elif path == "/session/close":
                await s.close()
                result = {"closed": True}
            elif path == "/ads/list":
                result = await do_list_ads(s, email)
            elif path == "/ads/publish":
                result = await do_publish(s, email, dict(body.get("ad") or {}))
            elif path == "/ads/delete":
                result = await do_delete_ad(s, email, str(body.get("id") or ""))
            elif path == "/ads/extend":
                result = await do_extend_ad(s, email, str(body.get("id") or ""))
            elif path == "/messages/list":
                result = await do_messages_list(s, email, int(body.get("limit") or 50))
            elif path == "/messages/read":
                result = await do_messages_read(s, email, str(body.get("id") or ""))
            elif path == "/messages/reply":
                result = await do_messages_reply(s, email, str(body.get("id") or ""), str(body.get("text") or ""))
            elif path == "/debug/page":
                await s.open(str(body.get("url") or ROOT_URL + "/"), reload=True)
                await asyncio.sleep(2)
                result = await s.js("return {url: location.href, title: document.title, text: (document.body?.innerText || '').slice(0, 20000), html: document.documentElement.outerHTML.slice(0, 300000)}")
            elif path == "/debug/eval":
                await s.ensure_browser()
                if s.page is None:
                    await s.open(ROOT_URL + "/")
                result = {"value": await s.js(str(body.get("code") or "return null"))}
            elif path == "/debug/screenshot":
                await s.ensure_browser()
                if s.page is None:
                    await s.open(ROOT_URL + "/")
                result = {"png": await s.screenshot_b64(), "url": await s.url()}
            else:
                return json_error("NOT_FOUND", "Unbekannter Pfad " + path, 404)
        except WorkerError as ex:
            LOG.warning("[%s] %s: %s (%s)", account, path, ex.code, ex)
            return json_error(ex.code, str(ex), 422, **ex.extra)
        except Exception as ex:  # noqa: BLE001
            LOG.error("[%s] %s: %s\n%s", account, path, ex, traceback.format_exc())
            shot = ""
            try:
                shot = await s.screenshot_b64()
            except Exception:  # noqa: BLE001
                pass
            return json_error("INTERNAL", f"{type(ex).__name__}: {ex}", 500, screenshot=shot)
    LOG.info("[%s] %s in %.1fs", account, path, time.time() - started)
    return web.json_response({"ok": True, **(result if isinstance(result, dict) else {"result": result})})


async def idle_reaper() -> None:
    while True:
        await asyncio.sleep(60)
        if IDLE_MINUTES <= 0:
            continue
        for s in list(SESSIONS.values()):
            if s.browser is not None and time.time() - s.last_used > IDLE_MINUTES * 60 and not s.lock.locked():
                LOG.info("[%s] Browser nach Leerlauf schließen", s.account)
                await s.close()


async def main() -> None:
    if not TOKEN:
        LOG.warning("KA_TOKEN ist leer — der Dienst nimmt jede Anfrage von 127.0.0.1 an. Für den Betrieb ein Token setzen.")
    if not CHROME:
        LOG.warning("Kein Chrome gefunden — KA_CHROME setzen")
    PROFILE_BASE.mkdir(parents=True, exist_ok=True)
    app = web.Application(client_max_size=64 * 1024 * 1024)
    app.router.add_route("*", "/{tail:.*}", handle)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, "127.0.0.1", PORT)
    await site.start()
    LOG.info("Kleinanzeigen-Dienst %s auf 127.0.0.1:%s (headless=%s, chrome=%s)", VERSION, PORT, HEADLESS, CHROME or "?")
    asyncio.create_task(idle_reaper())
    while True:
        await asyncio.sleep(3600)


if __name__ == "__main__":
    try:
        uc.loop().run_until_complete(main())
    except KeyboardInterrupt:
        pass
