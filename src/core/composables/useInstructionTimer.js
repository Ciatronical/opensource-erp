// src/core/composables/useInstructionTimer.js
//
// DB-backed Zeiterfassung für LxCars-Arbeitsanweisungen.
// - Wahrheitswert lebt in der DB (timer_started_at)
// - Frontend ist nur Live-Display (1-Sekunden-Tick)
// - Timer überlebt Auftragswechsel, Reload, Browser-Crash
// - Nur ein Timer pro Mitarbeiter (Backend-enforced)

import { reactive, ref } from 'vue'
import { oserpStore } from '@/core/stores/oserp.store.js'

// ─── Globaler State (Singleton, nur für UI-Display) ───
const activeTimer = reactive({
    instructionId: null,   // welche Instruction hat timer_started_at
    oeId: null,            // welcher Auftrag
    startedAt: null,       // DB-Timestamp als Date-Objekt (für Live-Display)
    description: null,     // Beschreibung (für Cross-Order-Anzeige)
    ordnumber: null        // Auftragsnummer (für Cross-Order-Anzeige)
})

let tickInterval = null
const tickCounter = ref(0) // Reactive trigger für UI-Updates

// ─── Config aus dem Store lesen ───

function getTimerConfig() {
    const oserp = oserpStore()
    const d = oserp.session?.company_config?.defaults_oserp || {}
    return {
        arbeitsbeginn: d.lxcars_arbeitsbeginn || '08:00',
        arbeitsende: d.lxcars_arbeitsende || '17:00',
        pausen: d.lxcars_pausen || ''
    }
}

function parseTime(str) {
    const [h, m] = (str || '0:0').split(':').map(Number)
    return h * 60 + (m || 0)
}

function parseBreaks(pausenStr) {
    if (!pausenStr || !pausenStr.trim()) return []
    return pausenStr.split(',').map(b => {
        const parts = b.trim().split('-')
        if (parts.length !== 2) return null
        return { start: parseTime(parts[0]), end: parseTime(parts[1]) }
    }).filter(Boolean)
}

function nowMinutes() {
    const now = new Date()
    return now.getHours() * 60 + now.getMinutes()
}

/**
 * Prüft ob gerade außerhalb der Arbeitszeit ist.
 */
function checkWorkTime() {
    const cfg = getTimerConfig()
    const breaks = parseBreaks(cfg.pausen)
    const workStart = parseTime(cfg.arbeitsbeginn)
    const workEnd = parseTime(cfg.arbeitsende)
    const now = nowMinutes()

    return {
        outsideWorkHours: now < workStart || now >= workEnd,
        inBreak: breaks.some(b => now >= b.start && now < b.end)
    }
}

/**
 * Berechnet die Display-Sekunden seit Start, abzüglich Pausen + Nicht-Arbeitszeit.
 * Nur für die Live-Anzeige — der echte Wert wird beim Stoppen serverseitig berechnet.
 */
function getDisplaySeconds(instructionId) {
    if (activeTimer.instructionId !== instructionId || !activeTimer.startedAt) return 0

    const cfg = getTimerConfig()
    const breaks = parseBreaks(cfg.pausen)
    const workStart = parseTime(cfg.arbeitsbeginn)
    const workEnd = parseTime(cfg.arbeitsende)

    const start = activeTimer.startedAt
    const now = new Date()

    // Minutenweise Netto-Arbeitszeit berechnen
    let netMinutes = 0
    const current = new Date(start)
    // Auf volle Minute abrunden
    current.setSeconds(0, 0)

    while (current < now) {
        const dayMinute = current.getHours() * 60 + current.getMinutes()

        if (dayMinute >= workStart && dayMinute < workEnd) {
            const inBreak = breaks.some(b => dayMinute >= b.start && dayMinute < b.end)
            if (!inBreak) {
                netMinutes++
            }
        }
        current.setMinutes(current.getMinutes() + 1)
    }

    return netMinutes * 60
}

// ─── Tick-Logik (nur für UI-Refresh) ───

function startTicking() {
    if (tickInterval) return
    tickInterval = setInterval(() => {
        if (activeTimer.instructionId) {
            tickCounter.value++
        }
    }, 1000)
}

function stopTicking() {
    if (tickInterval) {
        clearInterval(tickInterval)
        tickInterval = null
    }
}

// ─── Public API ───

function isRunning(instructionId) {
    // eslint-disable-next-line no-unused-expressions
    tickCounter.value
    return activeTimer.instructionId === instructionId
}

function isPaused(instructionId) {
    // eslint-disable-next-line no-unused-expressions
    tickCounter.value
    if (activeTimer.instructionId !== instructionId) return false
    const wt = checkWorkTime()
    return wt.outsideWorkHours || wt.inBreak
}

function formatElapsed(instructionId) {
    // eslint-disable-next-line no-unused-expressions
    tickCounter.value

    const totalSec = getDisplaySeconds(instructionId)
    const h = Math.floor(totalSec / 3600)
    const m = Math.floor((totalSec % 3600) / 60)
    const s = totalSec % 60
    if (h > 0) {
        return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
    }
    return m + ':' + String(s).padStart(2, '0')
}

/**
 * Setzt den lokalen Display-State (wird nach DB-Call aufgerufen).
 */
function setActive(instructionId, oeId, startedAtStr, description, ordnumber) {
    activeTimer.instructionId = instructionId
    activeTimer.oeId = oeId
    activeTimer.startedAt = startedAtStr ? new Date(startedAtStr) : null
    activeTimer.description = description || null
    activeTimer.ordnumber = ordnumber || null
    startTicking()
}

/**
 * Löscht den lokalen Display-State (nach Stop).
 */
function clearActive() {
    activeTimer.instructionId = null
    activeTimer.oeId = null
    activeTimer.startedAt = null
    activeTimer.description = null
    activeTimer.ordnumber = null
    stopTicking()
}

/**
 * Initialisiert den Timer-State aus DB-Daten (z.B. nach getActiveTimer-API).
 */
function initFromDb(timerData) {
    if (timerData && timerData.timer_started_at) {
        setActive(
            parseInt(timerData.id),
            parseInt(timerData.oe_id),
            timerData.timer_started_at,
            timerData.description,
            timerData.ordnumber
        )
    } else {
        clearActive()
    }
}

/**
 * Erkennt Timer-State aus einer Instructions-Liste (nach loadInstructions).
 * Setzt den lokalen Display-State wenn eine Instruction einen laufenden Timer hat.
 */
function detectFromInstructions(instructions, oeId) {
    const oserp = oserpStore()
    const myId = oserp.session?.logged_in_employee?.id
    if (!myId) return

    const running = instructions.find(i =>
        i.timer_started_at && parseInt(i.timer_employee_id) === parseInt(myId)
    )
    if (running) {
        setActive(parseInt(running.id), oeId, running.timer_started_at, running.description)
    }
}

export function useInstructionTimer() {
    return {
        activeTimer,
        tickCounter,
        isRunning,
        isPaused,
        formatElapsed,
        checkWorkTime,
        getTimerConfig,
        setActive,
        clearActive,
        initFromDb,
        detectFromInstructions
    }
}
