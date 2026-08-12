// src/core/composables/sseClient.js
//
// Einheitlicher SSE-Client. Bündelt ALLE Echtzeit-Consumer (Chat, Info-Leiste,
// Views) auf EINE Verbindung statt — wie bisher — mehrere pro Tab.
//
// Bevorzugt wird ein SharedWorker, der die Verbindung sogar über mehrere Tabs
// hinweg teilt: dann existiert browserweit nur EINE /sse/events-Verbindung,
// egal wie viele Tabs offen sind. Wo SharedWorker fehlt (z. B. iOS-Safari),
// fällt der Client automatisch auf eine tab-lokale Einzelverbindung zurück —
// immer noch genau eine statt der bisherigen zwei-plus pro Tab.
//
// Warum überhaupt: Über HTTP/1.1 erlaubt der Browser nur 6 gleichzeitige
// Verbindungen pro Host. Jede offene SSE-Verbindung belegt dauerhaft einen
// dieser Slots — mehrere Tabs mit je mehreren SSE-Streams sprengten das Limit,
// wodurch normale API-Requests hängen blieben (siehe [[project_sse_shared_connection]]).
import { ref } from 'vue'

// Verbindungsstatus, geteilt zwischen allen Consumern.
export const sseConnected = ref(false)

// eventName -> Set<handler>.  'message' = Standard-SSE ohne event:-Feld.
const handlers = new Map()

let workerPort = null   // SharedWorker-Port (bevorzugter Pfad)
let source = null       // Fallback: tab-lokale EventSource
let mode = null         // 'worker' | 'source' | null (noch nicht verbunden)

function dispatch(eventName, event) {
    const set = handlers.get(eventName)
    if (!set) return
    for (const fn of set) {
        try { fn(event) } catch (err) { console.error('[SSE] Handler-Fehler:', err) }
    }
}

// --- Bevorzugter Pfad: SharedWorker (eine Verbindung für alle Tabs) ---
function initWorker() {
    try {
        const sw = new SharedWorker(new URL('../workers/sse.worker.js', import.meta.url), { type: 'module' })
        workerPort = sw.port
        workerPort.onmessage = (ev) => {
            const msg = ev.data || {}
            if (msg.type === 'state') {
                sseConnected.value = !!msg.connected
            } else if (msg.type === 'sse') {
                // MessageEvent-ähnliches Objekt nachbauen (Consumer lesen .data)
                dispatch(msg.event, { data: msg.data, lastEventId: msg.lastEventId })
            }
        }
        workerPort.start()
        mode = 'worker'
        // Bereits registrierte Event-Namen beim Worker anfordern
        for (const name of handlers.keys()) workerPort.postMessage({ type: 'listen', event: name })
        // Beim Schließen des Tabs den Port abmelden, damit der Worker die
        // Verbindung freigeben kann, sobald kein Tab mehr da ist.
        window.addEventListener('beforeunload', () => {
            try { workerPort.postMessage({ type: 'close' }) } catch { /* egal */ }
        })
        return true
    } catch {
        // SharedWorker nicht verfügbar/blockiert → Fallback
        return false
    }
}

// --- Fallback: tab-lokale EventSource (eine pro Tab) ---
function initSource() {
    source = new EventSource('/sse/events')
    source.onopen = () => { sseConnected.value = true }
    source.onerror = () => { sseConnected.value = false }
    source.onmessage = (event) => dispatch('message', event)
    mode = 'source'
    // Bereits registrierte benannte Events nachziehen
    for (const name of handlers.keys()) {
        if (name !== 'message') source.addEventListener(name, (event) => dispatch(name, event))
    }
}

function ensureConnection() {
    if (mode) return
    if (typeof SharedWorker !== 'undefined' && initWorker()) return
    if (typeof EventSource !== 'undefined') initSource()
}

function ensureListen(eventName) {
    if (mode === 'worker') {
        workerPort.postMessage({ type: 'listen', event: eventName })
    } else if (mode === 'source' && eventName !== 'message') {
        source.addEventListener(eventName, (event) => dispatch(eventName, event))
    }
}

/**
 * Auf ein SSE-Event hören. eventName ist der Name aus dem SSE-Feld `event:`
 * (z. B. 'chat_message', 'build_changed'); 'message' steht für Standard-
 * Nachrichten ohne event:-Feld.
 *
 * Öffnet die (geteilte) Verbindung beim ersten Consumer und gibt eine
 * Abmelde-Funktion zurück — im onUnmounted/stopXxx aufrufen.
 *
 * @param {string} eventName
 * @param {(event: {data: string, lastEventId?: string}) => void} handler
 * @returns {() => void} unsubscribe
 */
export function onServerEvent(eventName, handler) {
    ensureConnection()
    let set = handlers.get(eventName)
    const firstForName = !set
    if (!set) { set = new Set(); handlers.set(eventName, set) }
    set.add(handler)
    if (firstForName) ensureListen(eventName)

    return () => {
        const s = handlers.get(eventName)
        if (!s) return
        s.delete(handler)
        if (s.size === 0) handlers.delete(eventName)
    }
}
