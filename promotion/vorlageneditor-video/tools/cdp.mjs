// Kleiner Chrome-DevTools-Treiber ohne Abhängigkeiten (Node >= 22: globales WebSocket)
// Steuert ein headless Chrome: Maus, Tastatur, Screenshots, Cookies, Bildschirmaufnahme.
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const CURSOR_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="26" height="30" viewBox="0 0 26 30"><path d="M3 2 L3 24 L8.5 19 L12 27 L16 25.5 L12.5 18 L20 18 Z" fill="#111" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>`;

export class Browser {
    constructor({ port = 9333, width = 1920, height = 1080, profile = '/tmp/cdp-profile' } = {}) {
        this.port = port; this.width = width; this.height = height; this.profile = profile;
        this.id = 0; this.pending = new Map(); this.listeners = new Map();
        this.mouse = { x: 0, y: 0 };
    }

    async launch(url = 'about:blank') {
        this.proc = spawn('google-chrome', [
            '--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
            `--remote-debugging-port=${this.port}`, `--user-data-dir=${this.profile}`,
            `--window-size=${this.width},${this.height}`, '--ignore-certificate-errors',
            '--force-device-scale-factor=1', '--lang=de-DE', url,
        ], { stdio: 'ignore' });
        for (let i = 0; i < 100; i++) {
            try {
                const list = await (await fetch(`http://127.0.0.1:${this.port}/json`)).json();
                const page = list.find(t => t.type === 'page');
                if (page) { await this.connect(page.webSocketDebuggerUrl); return; }
            } catch { /* noch nicht bereit */ }
            await sleep(200);
        }
        throw new Error('Chrome nicht erreichbar');
    }

    connect(wsUrl) {
        return new Promise((resolve, reject) => {
            this.ws = new WebSocket(wsUrl);
            this.ws.onopen = async () => {
                await this.send('Page.enable'); await this.send('Runtime.enable'); await this.send('Network.enable');
                await this.send('Emulation.setDeviceMetricsOverride', { width: this.width, height: this.height, deviceScaleFactor: 1, mobile: false });
                resolve();
            };
            this.ws.onerror = reject;
            this.ws.onmessage = (ev) => {
                const msg = JSON.parse(ev.data);
                if (msg.id && this.pending.has(msg.id)) {
                    const { resolve, reject } = this.pending.get(msg.id); this.pending.delete(msg.id);
                    msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
                } else if (msg.method && this.listeners.has(msg.method)) {
                    for (const fn of this.listeners.get(msg.method)) fn(msg.params);
                }
            };
        });
    }

    on(method, fn) { if (!this.listeners.has(method)) this.listeners.set(method, []); this.listeners.get(method).push(fn); }

    send(method, params = {}) {
        const id = ++this.id;
        return new Promise((resolve, reject) => { this.pending.set(id, { resolve, reject }); this.ws.send(JSON.stringify({ id, method, params })); });
    }

    async setCookie(name, value, domain = 'localhost') {
        await this.send('Network.setCookie', { name, value, domain, path: '/', secure: true, sameSite: 'Strict' });
    }

    async goto(url) {
        await this.send('Page.navigate', { url });
        await sleep(600);
    }

    async eval(expression) {
        const r = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || 'eval failed');
        return r.result.value;
    }

    /** Sichtbarer Mauszeiger für Aufnahmen (Screenshots zeigen keinen Systemcursor) */
    async showCursor() {
        await this.eval(`(() => { let c = document.getElementById('cdp-cursor'); if (!c) { c = document.createElement('div'); c.id = 'cdp-cursor';
            c.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;width:26px;height:30px;transition:none;';
            c.innerHTML = ${JSON.stringify(CURSOR_SVG)}; document.body.appendChild(c); } })()`);
        await this.moveCursorEl();
    }

    async moveCursorEl() {
        await this.eval(`(() => { const c = document.getElementById('cdp-cursor'); if (c) { c.style.left = '${this.mouse.x - 3}px'; c.style.top = '${this.mouse.y - 2}px'; } })()`);
    }

    async clickEffect() {
        await this.eval(`(() => { const d = document.createElement('div'); d.style.cssText = 'position:fixed;left:${this.mouse.x - 14}px;top:${this.mouse.y - 14}px;width:28px;height:28px;border-radius:50%;border:3px solid rgba(25,118,210,0.9);z-index:2147483646;pointer-events:none;animation:cdpping .45s ease-out forwards;';
            if (!document.getElementById('cdp-ping-style')) { const s = document.createElement('style'); s.id = 'cdp-ping-style'; s.textContent = '@keyframes cdpping{from{transform:scale(.4);opacity:1}to{transform:scale(1.6);opacity:0}}'; document.head.appendChild(s); }
            document.body.appendChild(d); setTimeout(() => d.remove(), 500); })()`);
    }

    async mouseMove(x, y, steps = 1, stepDelay = 0) {
        const sx = this.mouse.x, sy = this.mouse.y;
        for (let i = 1; i <= steps; i++) {
            const nx = sx + (x - sx) * i / steps, ny = sy + (y - sy) * i / steps;
            this.mouse = { x: nx, y: ny };
            await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: nx, y: ny, buttons: this.down ? 1 : 0, button: this.down ? 'left' : 'none' });
            await this.moveCursorEl();
            if (stepDelay) await sleep(stepDelay);
        }
    }

    async mouseDown() { this.down = true; await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: this.mouse.x, y: this.mouse.y, button: 'left', clickCount: 1, buttons: 1 }); }
    async mouseUp() { this.down = false; await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: this.mouse.x, y: this.mouse.y, button: 'left', clickCount: 1, buttons: 0 }); }

    async click(x, y, { moveSteps = 12, moveDelay = 12 } = {}) {
        await this.mouseMove(x, y, moveSteps, moveDelay);
        await this.clickEffect();
        await this.mouseDown(); await sleep(40); await this.mouseUp();
    }

    async dblclick(x, y) {
        await this.mouseMove(x, y, 10, 10);
        await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 2, buttons: 1 });
        await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 2, buttons: 0 });
    }

    async drag(x1, y1, x2, y2, { steps = 25, stepDelay = 25, holdBefore = 150 } = {}) {
        await this.mouseMove(x1, y1, 12, 12);
        await this.mouseDown();
        await sleep(holdBefore);
        await this.mouseMove(x2, y2, steps, stepDelay);
        await sleep(80);
        await this.mouseUp();
    }

    async type(text, delay = 30) {
        for (const ch of text) {
            await this.send('Input.dispatchKeyEvent', { type: 'keyDown', text: ch, key: ch });
            await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: ch });
            if (delay) await sleep(delay);
        }
    }

    async key(key, { ctrl = false, shift = false } = {}) {
        const modifiers = (ctrl ? 2 : 0) | (shift ? 8 : 0);
        const codes = { Delete: 46, ArrowLeft: 37, ArrowRight: 39, ArrowUp: 38, ArrowDown: 40, Escape: 27, Enter: 13, Backspace: 8, a: 65, z: 90, y: 89, s: 83, d: 68 };
        const code = codes[key] ?? key.toUpperCase().charCodeAt(0);
        await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code: key.length === 1 ? 'Key' + key.toUpperCase() : key, windowsVirtualKeyCode: code, nativeVirtualKeyCode: code, modifiers });
        await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code: key.length === 1 ? 'Key' + key.toUpperCase() : key, windowsVirtualKeyCode: code, nativeVirtualKeyCode: code, modifiers });
    }

    async selectAll() { await this.key('a', { ctrl: true }); }

    /** Element-Mitte in Fensterkoordinaten; index wählt bei mehreren Treffern */
    async rect(selector, index = 0) {
        const r = await this.eval(`(() => { const els = document.querySelectorAll(${JSON.stringify(selector)}); const el = els[${index}]; if (!el) return null; let r = el.getBoundingClientRect(); if (r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) { el.scrollIntoView({ block: 'center', inline: 'nearest' }); r = el.getBoundingClientRect(); } return { x: r.left, y: r.top, w: r.width, h: r.height, cx: r.left + r.width / 2, cy: r.top + r.height / 2 }; })()`);
        if (!r) throw new Error('Element nicht gefunden: ' + selector + ' [' + index + ']');
        return r;
    }

    /** Element mit passendem Text (innerText enthält) */
    async rectByText(selector, text, index = 0) {
        const r = await this.eval(`(() => { const els = [...document.querySelectorAll(${JSON.stringify(selector)})].filter(e => (e.innerText || '').includes(${JSON.stringify(text)})); const el = els[${index}]; if (!el) return null; let r = el.getBoundingClientRect(); if (r.bottom < 0 || r.top > innerHeight || r.right < 0 || r.left > innerWidth) { el.scrollIntoView({ block: 'center', inline: 'nearest' }); r = el.getBoundingClientRect(); } return { x: r.left, y: r.top, w: r.width, h: r.height, cx: r.left + r.width / 2, cy: r.top + r.height / 2 }; })()`);
        if (!r) throw new Error('Element mit Text nicht gefunden: ' + selector + ' "' + text + '"');
        return r;
    }

    async waitGone(selector, timeout = 8000) {
        const t0 = Date.now();
        while (Date.now() - t0 < timeout) {
            if (!(await this.eval(`!!document.querySelector(${JSON.stringify(selector)})`))) return;
            await sleep(200);
        }
    }

    async waitFor(selector, timeout = 15000) {
        const t0 = Date.now();
        while (Date.now() - t0 < timeout) {
            if (await this.eval(`!!document.querySelector(${JSON.stringify(selector)})`)) return true;
            await sleep(150);
        }
        throw new Error('Timeout: ' + selector);
    }

    async screenshot(file, { format = 'png', quality = 85 } = {}) {
        const r = await this.send('Page.captureScreenshot', { format, quality, captureBeyondViewport: false });
        writeFileSync(file, Buffer.from(r.data, 'base64'));
    }

    /** Bildschirmaufnahme: Frames in ein Verzeichnis, gibt stop() zurück */
    async record(dir, fps = 10) {
        mkdirSync(dir, { recursive: true });
        let n = 0; let running = true;
        const frames = [];
        const loop = async () => {
            while (running) {
                const t = Date.now();
                try {
                    const r = await this.send('Page.captureScreenshot', { format: 'jpeg', quality: 88 });
                    const name = `${dir}/f${String(n++).padStart(6, '0')}.jpg`;
                    writeFileSync(name, Buffer.from(r.data, 'base64'));
                    frames.push({ name, t });
                } catch { /* Frame verloren */ }
                const wait = 1000 / fps - (Date.now() - t);
                if (wait > 0) await sleep(wait);
            }
        };
        const p = loop();
        return async () => { running = false; await p; return frames; };
    }

    async close() { try { this.ws?.close(); } catch {} this.proc?.kill(); }
}

export const sleep = (ms) => new Promise(r => setTimeout(r, ms));
