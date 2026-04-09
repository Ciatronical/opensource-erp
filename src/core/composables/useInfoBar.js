// src/core/composables/useInfoBar.js
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { weroniStore } from '@/features/weroni/stores/weroni.store.js'
import * as toast from '@/core/utils/toasts.js'

/**
 * SSE-Verbindungsstatus (modul-weit, geteilt zwischen allen Consumern)
 */
export const sseConnected = ref(false)

/**
 * Composable fuer die Info Bar in der Navbar
 * Zeigt Anrufe, Emails und WhatsApp-Nachrichten der letzten 7 Tage chronologisch an.
 * Dismissed Items werden in localStorage (primaer) und employee_config_oserp (Backup) gespeichert.
 * Warenkorb-Items (parts) werden nur fuer 24h ausgeblendet, alle anderen dauerhaft.
 */
export function useInfoBar() {
    const oserp = oserpStore()

    const newCalls = ref([])
    const newEmails = ref([])
    const newWhatsapps = ref([])
    const pendingPartsRequests = ref([])
    const anprDetections = ref([])
    const dismissed = ref({ calls: [], emails: [], whatsapps: [], parts: [], anpr: [], parts_ts: null })

    let eventSource = null
    let emailPollInterval = null
    let whatsappPollInterval = null

    // --- localStorage-Key pro User+Client ---
    function lsKey() {
        return `oserp_infobar_dismissed_${oserp.session.user}_${oserp.session.client}`
    }

    // --- Dismissed Items laden (localStorage primaer, employee_config Fallback) ---
    function loadDismissed() {
        try {
            let parsed = null

            // 1. localStorage (primaer, sofort verfuegbar)
            const ls = localStorage.getItem(lsKey())
            if (ls) {
                parsed = JSON.parse(ls)
            }

            // 2. Fallback: employee_config (fuer erstmaligen Login / neues Geraet)
            if (!parsed) {
                const stored = oserp.getConfigValue('infobar_dismissed', null)
                if (stored) {
                    parsed = typeof stored === 'string' ? JSON.parse(stored) : stored
                }
            }

            if (!parsed || !parsed._v3) {
                // Alte Daten (v2 oder aelter) verwerfen, sauber starten
                dismissed.value = { calls: [], emails: [], whatsapps: [], parts: [], anpr: [], parts_ts: null }
                saveDismissed()
                return
            }

            // Parts-Dismiss: nur 24h gueltig (Timestamp-basiert)
            const now = Date.now()
            const partsStillValid = (parsed.parts_ts && (now - parsed.parts_ts) < 86400000)
                ? (parsed.parts || [])
                : []

            dismissed.value = {
                calls: parsed.calls || [],
                emails: parsed.emails || [],
                whatsapps: parsed.whatsapps || [],
                parts: partsStillValid,
                anpr: parsed.anpr || [],
                parts_ts: partsStillValid.length ? parsed.parts_ts : null
            }
        } catch { /* ignorieren */ }
    }

    function saveDismissed() {
        const data = {
            _v3: true,
            calls: dismissed.value.calls,
            emails: dismissed.value.emails,
            whatsapps: dismissed.value.whatsapps,
            parts: dismissed.value.parts,
            anpr: dismissed.value.anpr,
            parts_ts: dismissed.value.parts_ts
        }
        const json = JSON.stringify(data)

        // localStorage: sofort persistent, ueberlebt Navigation und Page-Reload
        try { localStorage.setItem(lsKey(), json) } catch { /* quota */ }

        // Backend-Sync: fuer Cross-Device und Backup
        oserp.setConfigValue('infobar_dismissed', json)
    }

    // --- 7-Tage-Fenster ---
    function get7DaysAgoString() {
        const d = new Date()
        d.setDate(d.getDate() - 7)
        return d.toISOString().split('T')[0]
    }

    // --- Anrufe laden (via bestehende API) ---
    async function fetchNewCalls() {
        try {
            const response = await axios.post('/api/customer_vendor/', {
                action: 'getAllCallHistory',
                date_from: get7DaysAgoString(),
                limit: 50,
                offset: 0
            })
            if (response.data.success) {
                newCalls.value = response.data.payload.main?.call_history || []
            }
        } catch { /* still ignorieren */ }
    }

    // --- Emails laden (via neuer API) ---
    async function fetchNewEmails() {
        try {
            const response = await axios.post('/api/email/', {
                action: 'getNewEmails',
                since_date: get7DaysAgoString(),
                limit: 50
            })
            if (response.data.success) {
                newEmails.value = response.data.payload?.emails || []
            }
        } catch { /* Email nicht konfiguriert → still ignorieren */ }
    }

    // --- WhatsApp-Nachrichten laden ---
    async function fetchNewWhatsapps() {
        try {
            const response = await axios.post('/api/whatsapp/', {
                action: 'getNewWhatsAppMessages',
                since_date: get7DaysAgoString(),
                limit: 50
            })
            if (response.data.success) {
                newWhatsapps.value = response.data.payload?.messages || []
            }
        } catch { /* WhatsApp nicht konfiguriert → still ignorieren */ }
    }

    async function fetchPendingPartsRequests() {
        if (!oserp.isLxCars()) return
        try {
            const response = await axios.post('/api/lxcars/', {
                action: 'getPendingPartsRequests'
            })
            if (response.data.success) {
                pendingPartsRequests.value = response.data.payload || []
            }
        } catch { /* LxCars nicht verfügbar */ }
    }

    async function fetchAnprDetections() {
        if (!oserp.isLxCars()) return
        const enabled = oserp.getClientDefaultValue('anpr_enabled', '0')
        if (enabled !== '1' && enabled !== 't' && enabled !== true) return
        try {
            const response = await axios.post('/api/lxcars/', {
                action: 'getPendingAnprDetections'
            })
            if (response.data.success) {
                anprDetections.value = response.data.payload?.detections || []
            }
        } catch { /* ANPR nicht verfuegbar */ }
    }

    // --- Config: Max. Anzahl ---
    const maxCalls = computed(() =>
        parseInt(oserp.getClientDefaultValue('infobar_max_calls', '5'), 10) || 5
    )
    const maxEmails = computed(() =>
        parseInt(oserp.getClientDefaultValue('infobar_max_emails', '5'), 10) || 5
    )
    const maxWhatsapps = computed(() =>
        parseInt(oserp.getClientDefaultValue('infobar_max_whatsapps', '5'), 10) || 5
    )

    // --- Computed: chronologische Liste aller Events ---
    const chronologicalItems = computed(() => {
        const items = []

        // Anrufe
        newCalls.value
            .filter(c => !dismissed.value.calls.includes(c.crmti_id))
            .slice(0, maxCalls.value)
            .forEach(call => {
                items.push({
                    type: 'call',
                    id: 'call-' + call.crmti_id,
                    dismissId: call.crmti_id,
                    timestamp: call.call_date || 0,
                    name: call.caller_name || call.crmti_number || call.crmti_src || null,
                    detail: null,
                    direction: call.crmti_direction,
                    data: call
                })
            })

        // Emails
        newEmails.value
            .filter(e => !dismissed.value.emails.includes(e.uid))
            .slice(0, maxEmails.value)
            .forEach(email => {
                items.push({
                    type: 'email',
                    id: 'email-' + email.uid,
                    dismissId: email.uid,
                    timestamp: email.date ? new Date(email.date).getTime() : 0,
                    name: email.from || null,
                    detail: email.subject,
                    data: email
                })
            })

        // WhatsApp
        newWhatsapps.value
            .filter(w => !dismissed.value.whatsapps.includes(w.id))
            .slice(0, maxWhatsapps.value)
            .forEach(wa => {
                items.push({
                    type: 'whatsapp',
                    id: 'wa-' + wa.id,
                    dismissId: wa.id,
                    timestamp: wa.itime ? new Date(wa.itime).getTime() : 0,
                    name: wa.contact_name || wa.phone_number || null,
                    detail: wa.message_text,
                    data: wa
                })
            })

        // Neueste zuerst
        items.sort((a, b) => b.timestamp - a.timestamp)

        return items
    })

    const filteredPartsRequests = computed(() =>
        pendingPartsRequests.value.filter(pr => !dismissed.value.parts.includes(pr.oe_id))
    )

    const filteredAnprDetections = computed(() => {
        const maxAnpr = parseInt(oserp.getClientDefaultValue('anpr_infobar_max', '3'), 10) || 3
        return anprDetections.value
            .filter(d => !dismissed.value.anpr.includes(d.id))
            .slice(0, maxAnpr)
    })

    const hasItems = computed(() =>
        chronologicalItems.value.length > 0 ||
        filteredPartsRequests.value.length > 0 ||
        filteredAnprDetections.value.length > 0
    )

    // --- Actions ---
    function dismissItem(type, id) {
        if (type === 'call' && !dismissed.value.calls.includes(id)) {
            dismissed.value.calls.push(id)
        } else if (type === 'email' && !dismissed.value.emails.includes(id)) {
            dismissed.value.emails.push(id)
        } else if (type === 'whatsapp' && !dismissed.value.whatsapps.includes(id)) {
            dismissed.value.whatsapps.push(id)
        } else if (type === 'parts' && !dismissed.value.parts.includes(id)) {
            dismissed.value.parts.push(id)
            dismissed.value.parts_ts = Date.now()
        } else if (type === 'anpr' && !dismissed.value.anpr.includes(id)) {
            dismissed.value.anpr.push(id)
            // Auch serverseitig als dismissed markieren
            axios.post('/api/lxcars/', { action: 'dismissAnprDetection', id }).catch(() => {})
        }
        saveDismissed()
    }

    function closeAll() {
        // Nicht mehr verwendet — Leiste kann nicht komplett geschlossen werden
    }

    // --- Setup: SSE + Polling starten ---
    function startListeners() {
        console.log('[SSE] Verbindung wird aufgebaut: /sse/events')
        eventSource = new EventSource('/sse/events')
        eventSource.onopen = () => {
            console.log('[SSE] Verbindung hergestellt ✓')
            sseConnected.value = true
        }
        eventSource.onmessage = (event) => {
            console.log('[SSE] Message empfangen:', event.data)
            try {
                const data = JSON.parse(event.data)
                if (data.message_type !== undefined) {
                    fetchNewWhatsapps()
                } else if (data.table === 'anpr_detections_lxcars') {
                    fetchAnprDetections()
                } else if (data.table === 'oe_parts_requests_lxcars') {
                    fetchPendingPartsRequests()
                } else if (data.urgency !== undefined) {
                    // Weroni-Rückfrage
                    try { weroniStore().fetchPendingCount() } catch { /* store evtl. noch nicht bereit */ }
                } else {
                    fetchNewCalls()
                }
            } catch {
                fetchNewCalls()
                fetchNewWhatsapps()
            }
        }
        // Neuer Build erkannt: Seite automatisch neu laden
        eventSource.addEventListener('build_changed', (event) => {
            console.warn('[SSE] build_changed empfangen:', event.data)
            toast.info('Neues Update verfügbar — Seite wird neu geladen...')
            setTimeout(() => window.location.reload(), 2000)
        })
        eventSource.onerror = (err) => {
            console.error('[SSE] Verbindungsfehler — readyState:', eventSource.readyState,
                '(0=CONNECTING, 1=OPEN, 2=CLOSED)', err)
            sseConnected.value = false
        }

        emailPollInterval = setInterval(fetchNewEmails, 60000)
        whatsappPollInterval = setInterval(fetchNewWhatsapps, 120000)
    }

    function stopListeners() {
        if (eventSource) { eventSource.close(); eventSource = null }
        if (emailPollInterval) { clearInterval(emailPollInterval); emailPollInterval = null }
        if (whatsappPollInterval) { clearInterval(whatsappPollInterval); whatsappPollInterval = null }
        sseConnected.value = false
    }

    function loadAndFetchAll() {
        loadDismissed()
        fetchNewCalls()
        fetchNewEmails()
        fetchNewWhatsapps()
        fetchPendingPartsRequests()
        fetchAnprDetections()
    }

    // --- Firmenwechsel: Daten zuruecksetzen und neu laden ---
    watch(() => oserp.session.client, () => {
        newCalls.value = []
        newEmails.value = []
        newWhatsapps.value = []
        pendingPartsRequests.value = []
        anprDetections.value = []
        dismissed.value = { calls: [], emails: [], whatsapps: [], parts: [], anpr: [], parts_ts: null }

        stopListeners()
        loadAndFetchAll()
        startListeners()
    })

    // --- Lifecycle ---
    function onPartsChanged() {
        fetchPendingPartsRequests()
    }

    onMounted(() => {
        loadAndFetchAll()
        startListeners()
        window.addEventListener('parts-requests-changed', onPartsChanged)
    })

    onUnmounted(() => {
        stopListeners()
        window.removeEventListener('parts-requests-changed', onPartsChanged)
    })

    return {
        chronologicalItems,
        pendingPartsRequests: filteredPartsRequests,
        anprDetections: filteredAnprDetections,
        hasItems,
        dismissItem,
        fetchPendingPartsRequests,
        fetchAnprDetections,
        closeAll
    }
}
