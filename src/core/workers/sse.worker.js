// src/core/workers/sse.worker.js
//
// SharedWorker: hält EINE EventSource-Verbindung zu /sse/events für ALLE
// gleichzeitig geöffneten Tabs derselben Herkunft (Origin).
//
// Ohne diese Bündelung öffnet jeder Tab — und darin jeder Consumer (Chat,
// Info-Leiste, aktive View) — eine eigene Dauerverbindung. Über HTTP/1.1
// erlaubt der Browser aber nur 6 gleichzeitige Verbindungen pro Host; schon
// bei drei Tabs ist dieses Kontingent allein durch offene SSE-Streams belegt
// und jeder weitere Request (API, Assets) bleibt hängen — die Seite baut sich
// nicht mehr auf. Läuft alles über diesen Worker, existiert browserweit nur
// EINE /sse/events-Verbindung, unabhängig von der Zahl der Tabs.

const SSE_URL = '/sse/events'

let source = null
let connected = false
const ports = new Set()

// Benannte Events, für die bereits ein Listener registriert ist. EventSource
// liefert benannte Events (event: chat_message …) nur an explizit per
// addEventListener registrierte Listener — es gibt keinen Platzhalter. Deshalb
// abonnieren wir jeden von den Tabs angeforderten Namen genau einmal.
const listenedEvents = new Set()

function broadcast(msg) {
    for (const port of ports) {
        try { port.postMessage(msg) } catch { /* Port gehört zu totem Tab */ }
    }
}

function ensureListener(eventName) {
    if (!source || !eventName || listenedEvents.has(eventName)) return
    listenedEvents.add(eventName)
    source.addEventListener(eventName, (event) => {
        broadcast({ type: 'sse', event: eventName, data: event.data, lastEventId: event.lastEventId })
    })
}

function connect() {
    if (source) return
    source = new EventSource(SSE_URL)
    source.onopen = () => { connected = true; broadcast({ type: 'state', connected: true }) }
    source.onerror = () => { connected = false; broadcast({ type: 'state', connected: false }) }
    // Standard-Nachrichten (ohne event:-Feld)
    source.onmessage = (event) => {
        broadcast({ type: 'sse', event: 'message', data: event.data, lastEventId: event.lastEventId })
    }
    // Vor dem (Neu-)Aufbau angeforderte benannte Events erneut registrieren
    const names = [...listenedEvents]
    listenedEvents.clear()
    names.forEach(ensureListener)
}

function maybeShutdown() {
    // Kein Tab mehr verbunden → Verbindung schließen und Serverlast sparen.
    // (Browser beenden den SharedWorker ohnehin, sobald der letzte Tab geht;
    // dieser explizite Weg greift beim sauberen beforeunload sofort.)
    if (ports.size === 0 && source) {
        source.close()
        source = null
        connected = false
        listenedEvents.clear()
    }
}

self.onconnect = (e) => {
    const port = e.ports[0]
    ports.add(port)
    port.start()
    port.onmessage = (ev) => {
        const msg = ev.data || {}
        if (msg.type === 'listen') {
            connect()
            ensureListener(msg.event)
        } else if (msg.type === 'close') {
            ports.delete(port)
            maybeShutdown()
        }
    }
    connect()
    // Aktuellen Verbindungsstatus sofort an den neuen Tab melden
    port.postMessage({ type: 'state', connected })
}
