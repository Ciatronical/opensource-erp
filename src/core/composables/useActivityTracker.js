// src/core/composables/useActivityTracker.js

import { ref, onMounted, onBeforeUnmount } from 'vue'
import axios from 'axios'

/**
 * Verfolgt Benutzeraktivität und setzt die Demo-Datenbank
 * nach einer konfigurierbaren Inaktivitätszeit zurück.
 *
 * @param {Object} options
 * @param {number} options.inactivityMinutes Inaktivitätszeit in Minuten
 * @param {Function} options.onReset Callback nach erfolgreichem Reset
 */
export function useActivityTracker({ inactivityMinutes, onReset }) {
    const WARNING_BEFORE_SECONDS = 120
    const showWarning = ref(false)
    const remainingSeconds = ref(0)

    let inactivityTimer = null
    let warningTimer = null
    let countdownInterval = null
    let lastActivity = 0

    const events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart']

    function resetTimers() {
        showWarning.value = false
        remainingSeconds.value = 0

        clearTimeout(inactivityTimer)
        clearTimeout(warningTimer)
        clearInterval(countdownInterval)

        const totalMs = inactivityMinutes * 60 * 1000
        const warningMs = totalMs - (WARNING_BEFORE_SECONDS * 1000)

        // Warnung anzeigen
        if (warningMs > 0) {
            warningTimer = setTimeout(() => {
                showWarning.value = true
                remainingSeconds.value = WARNING_BEFORE_SECONDS
                countdownInterval = setInterval(() => {
                    remainingSeconds.value--
                    if (remainingSeconds.value <= 0) {
                        clearInterval(countdownInterval)
                    }
                }, 1000)
            }, warningMs)
        }

        // Reset auslösen
        inactivityTimer = setTimeout(async () => {
            showWarning.value = false
            clearInterval(countdownInterval)
            await triggerReset()
        }, totalMs)
    }

    async function triggerReset() {
        try {
            await axios.post('/api/demo/', { action: 'resetDemo' })
        } catch (e) {
            console.error('Demo-Reset fehlgeschlagen:', e)
        }
        onReset?.()
    }

    function onActivity() {
        const now = Date.now()
        if (now - lastActivity < 1000) return
        lastActivity = now
        resetTimers()
    }

    function start() {
        events.forEach(e => document.addEventListener(e, onActivity, { passive: true }))
        resetTimers()
    }

    function stop() {
        events.forEach(e => document.removeEventListener(e, onActivity))
        clearTimeout(inactivityTimer)
        clearTimeout(warningTimer)
        clearInterval(countdownInterval)
        showWarning.value = false
    }

    onMounted(start)
    onBeforeUnmount(stop)

    return { showWarning, remainingSeconds }
}
