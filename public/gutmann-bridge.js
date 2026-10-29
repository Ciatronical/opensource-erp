/*
 * Brücke für die Hella-Gutmann-Oberfläche (mega macs X) in LxCars.
 *
 * Apache reicht die Oberfläche des Geräts unter /gutmann/ durch und hängt
 * dieses Skript vor die Skripte des Geräts (install/gutmann-proxy.sh,
 * docs/features/gutmann.md). Es läuft also im Dokument des Geräts, nicht in
 * OSERP, und erledigt drei Dinge:
 *
 * 1. Browser-Kennung: Das Gerät lässt nur Chrome/Chromium ab 69 und Edge ab 80
 *    zu und zeigt sonst eine Warnseite. Firefox und Safari bekommen eine
 *    Chrome-Kennung vorgespielt; die Oberfläche selbst läuft dort einwandfrei.
 * 2. WebSocket: Das Gerät verbindet sich fest per ws://<host>:<port>. Aus einer
 *    HTTPS-Seite ist das verboten (Mixed Content) — die Verbindung wird auf
 *    wss://<oserp>/gutmann/ws umgebogen, Apache reicht sie an das Gerät weiter.
 * 3. Farbschema: Das Gerät färbt alles über CSS-Variablen. gutmann-bridge.css
 *    überschreibt sie mit den OSERP-Farben, gesteuert über das Attribut
 *    data-oserp (light|dark) am <html>. Den Wert gibt LxCars beim Laden über
 *    ?oserp=… mit und bei einem Wechsel per postMessage.
 */
(function () {
    'use strict';

    // 1. Browser-Kennung (nur der String, den die ua-parser-Prüfung liest)
    var UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    try { Object.defineProperty(navigator, 'userAgent', { get: function () { return UA; } }); } catch (e) { /* schreibgeschützt: dann bleibt die Warnseite */ }
    try { Object.defineProperty(navigator, 'vendor', { get: function () { return 'Google Inc.'; } }); } catch (e) { /* s. o. */ }

    // 2. WebSocket auf den Proxy-Pfad umbiegen
    var basePath = location.pathname.replace(/\/[^/]*$/, '/');            // "/gutmann/"
    var wsBase = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + basePath + 'ws';
    var NativeWebSocket = window.WebSocket;
    function ProxiedWebSocket(url, protocols) {
        var target = String(url);
        try {
            var parsed = new URL(target, location.href);
            // Nur Verbindungen zum eigenen Host umleiten; fremde bleiben unberührt
            if (/^wss?:$/.test(parsed.protocol) && parsed.hostname === location.hostname) {
                target = wsBase + (parsed.pathname === '/' ? '' : parsed.pathname) + parsed.search;
            }
        } catch (e) { /* unbrauchbare URL: unverändert weitergeben */ }
        return protocols === undefined ? new NativeWebSocket(target) : new NativeWebSocket(target, protocols);
    }
    ProxiedWebSocket.prototype = NativeWebSocket.prototype;
    ['CONNECTING', 'OPEN', 'CLOSING', 'CLOSED'].forEach(function (k) { ProxiedWebSocket[k] = NativeWebSocket[k]; });
    window.WebSocket = ProxiedWebSocket;

    // 3. Farbschema von OSERP übernehmen
    function initTheme() {
        var html = document.documentElement;
        function applyTheme(theme) {
            theme = theme === 'dark' ? 'dark' : 'light';
            html.setAttribute('data-oserp', theme);
            // Das Gerät kennt die Designs light/dark/new/new_dark und setzt data-theme
            // selbst. „new" ist das modernere (runde Flächen); wir halten es passend
            // zum OSERP-Modus fest, damit Hell/Dunkel nicht auseinanderlaufen.
            var wanted = theme === 'dark' ? 'new_dark' : 'new';
            if (html.dataset.theme !== wanted) html.dataset.theme = wanted;
        }
        var fromQuery = /[?&]oserp=(light|dark)\b/.exec(location.search);
        applyTheme(fromQuery ? fromQuery[1] : 'light');

        // Das Gerät schreibt data-theme nach seinem eigenen Zustand — jedes Mal
        // wieder auf den OSERP-Modus zurücksetzen.
        new MutationObserver(function () {
            applyTheme(html.getAttribute('data-oserp'));
        }).observe(html, { attributes: true, attributeFilter: ['data-theme'] });

        window.addEventListener('message', function (ev) {
            if (ev.origin !== location.origin) return;
            var d = ev.data || {};
            if (d.type === 'oserp-theme') applyTheme(d.theme);
        });
    }
    // Als <script> im <head> gibt es <html> schon; läuft das Skript früher
    // (z. B. per DevTools vor dem Dokument), erst auf das DOM warten.
    if (document.documentElement) initTheme();
    else document.addEventListener('DOMContentLoaded', initTheme);
})();
