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
 * Dismissed Items werden in employee_config (Backend, primaer) gespeichert und mit localStorage gemergt.
 * Warenkorb-Items (parts) werden nur fuer 24h ausgeblendet, alle anderen dauerhaft.
 */
export function useInfoBar() {
    const oserp = oserpStore()

    const newCalls = ref([])
    const newEmails = ref([])
    const newWhatsapps = ref([])
    const pendingPartsRequests = ref([])
    const anprDetections = ref([])
    const completedOrders = ref([])
    const missingOrders = ref([])
    const dismissed = ref({ calls: [], emails: [], whatsapps: [], parts: [], anpr: [], completed: [], parts_ts: null })

    let eventSource = null
    let emailPollInterval = null
    let whatsappPollInterval = null

    // --- localStorage-Key pro User+Client ---
    function lsKey() {
        return `oserp_infobar_dismissed_${oserp.session.user}_${oserp.session.client}`
    }

    // --- Dismissed Items laden (Backend ist primaer, localStorage wird gemergt) ---
    function loadDismissed() {
        try {
            let fromBackend = null
            let fromLocal = null

            // 1. Backend (employee_config) — Single Source of Truth
            const stored = oserp.getConfigValue('infobar_dismissed', null)
            if (stored) {
                const p = typeof stored === 'string' ? JSON.parse(stored) : stored
                if (p && p._v3) fromBackend = p
            }

            // 2. localStorage — nur als Merge-Quelle (fuer noch nicht synchronisierte Aenderungen)
            try {
                const ls = localStorage.getItem(lsKey())
                if (ls) {
                    const p = JSON.parse(ls)
                    if (p && p._v3) fromLocal = p
                }
            } catch { /* ignorieren */ }

            // Beide Quellen mergen (Vereinigungsmenge der dismissed IDs)
            if (!fromBackend && !fromLocal) {
                dismissed.value = { calls: [], emails: [], whatsapps: [], parts: [], anpr: [], completed: [], parts_ts: null }
                saveDismissed()
                return
            }

            const a = fromBackend || { calls: [], emails: [], whatsapps: [], parts: [], anpr: [], completed: [], parts_ts: null }
            const b = fromLocal || { calls: [], emails: [], whatsapps: [], parts: [], anpr: [], completed: [], parts_ts: null }
            const mergeUnique = (arr1, arr2) => [...new Set([...(arr1 || []), ...(arr2 || [])])]

            // Parts-Dismiss: nur 24h gueltig (neuester Timestamp zaehlt)
            const now = Date.now()
            const partsTs = Math.max(a.parts_ts || 0, b.parts_ts || 0) || null
            const partsStillValid = (partsTs && (now - partsTs) < 86400000)
                ? mergeUnique(a.parts, b.parts)
                : []

            dismissed.value = {
                calls: mergeUnique(a.calls, b.calls),
                emails: mergeUnique(a.emails, b.emails),
                whatsapps: mergeUnique(a.whatsapps, b.whatsapps),
                parts: partsStillValid,
                anpr: mergeUnique(a.anpr, b.anpr),
                completed: mergeUnique(a.completed, b.completed),
                parts_ts: partsStillValid.length ? partsTs : null
            }

            // Nach dem Merge: beide Quellen aktualisieren
            saveDismissed()
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
            completed: dismissed.value.completed,
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

    async function fetchCompletedOrders() {
        if (!oserp.isLxCars()) return
        try {
            const response = await axios.post('/api/lxcars/', {
                action: 'getCompletedOrders'
            })
            if (response.data.success) {
                completedOrders.value = response.data.payload || []
            }
        } catch { /* LxCars nicht verfügbar */ }
    }

    async function fetchMissingOrders() {
        if (!oserp.isLxCars()) return
        try {
            const response = await axios.post('/api/lxcars/', {
                action: 'getMissingOrders'
            })
            if (response.data.success) {
                missingOrders.value = response.data.payload || []
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

    // --- Computed: alle Events in einer einzigen, chronologisch sortierten Liste ---
    const unifiedItems = computed(() => {
        const items = []

        // Fertiggestellte Aufträge
        completedOrders.value
            .filter(co => !dismissed.value.completed.includes(co.oe_id))
            .forEach(co => {
                items.push({
                    type: 'completed',
                    id: 'completed-' + co.oe_id,
                    dismissId: co.oe_id,
                    timestamp: co.completed_at ? new Date(co.completed_at).getTime() : 0,
                    name: co.customer_name || co.ordnumber || ('#' + co.oe_id),
                    data: co
                })
            })

        // Ersatzteil-Anfragen
        pendingPartsRequests.value
            .filter(pr => !dismissed.value.parts.includes(pr.oe_id))
            .forEach(pr => {
                items.push({
                    type: 'parts',
                    id: 'parts-' + pr.oe_id,
                    dismissId: pr.oe_id,
                    timestamp: pr.requested_at ? new Date(pr.requested_at).getTime() : 0,
                    name: pr.customer_name || pr.ordnumber || ('#' + pr.oe_id),
                    data: pr
                })
            })

        // ANPR-Erkennungen
        const maxAnpr = parseInt(oserp.getClientDefaultValue('anpr_infobar_max', '3'), 10) || 3
        anprDetections.value
            .filter(d => !dismissed.value.anpr.includes(d.id))
            .slice(0, maxAnpr)
            .forEach(det => {
                items.push({
                    type: 'anpr',
                    id: 'anpr-' + det.id,
                    dismissId: det.id,
                    timestamp: det.detected_at ? new Date(det.detected_at).getTime() : 0,
                    name: det.c_ln,
                    data: det
                })
            })

        // "Auftrag fehlt"-Meldungen aus dem Mechanikermodus (Dismiss serverseitig)
        missingOrders.value.forEach(mo => {
            items.push({
                type: 'missing_order',
                id: 'mo-' + mo.id,
                dismissId: mo.id,
                timestamp: mo.itime ? new Date(mo.itime).getTime() : 0,
                name: mo.label,
                data: mo
            })
        })

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
                    data: wa
                })
            })

        // Sortierung: Warenkoerbe (parts) IMMER zuerst (= leseanfang-seitig),
        // alles andere dahinter chronologisch (neueste zuerst).
        // Innerhalb der Warenkoerbe ebenfalls neueste zuerst.
        items.sort((a, b) => {
            const aIsParts = a.type === 'parts'
            const bIsParts = b.type === 'parts'
            if (aIsParts && !bIsParts) return -1
            if (!aIsParts && bIsParts) return 1
            return b.timestamp - a.timestamp
        })

        // Dubletten entfernen: pro Typ + Inhaltsschluessel nur das neueste behalten.
        // Da bereits nach Timestamp absteigend sortiert ist, ist das erste Vorkommen das neueste.
        const seen = new Set()
        return items.filter(item => {
            const key = dedupKey(item)
            if (!key) return true
            const full = item.type + ':' + key
            if (seen.has(full)) return false
            seen.add(full)
            return true
        })
    })

    function dedupKey(item) {
        const d = item.data || {}
        switch (item.type) {
            case 'whatsapp': return d.customer_id || d.phone_number || null
            case 'anpr':     return d.c_ln || null
            case 'call':     return d.crmti_caller_id || d.crmti_src || null
            case 'missing_order': return d.c_id || d.label || null
            case 'email':    return d.from || null
            default:         return null
        }
    }

    const hasItems = computed(() => unifiedItems.value.length > 0)

    // --- Actions ---
    function dismissItem(type, id) {
        console.log('[InfoBar] dismissItem aufgerufen:', { type, id, idType: typeof id })

        // Alle IDs sammeln, die zur selben Dublettengruppe gehoeren
        const ids = collectDuplicateIds(type, id)
        console.log('[InfoBar] collectDuplicateIds liefert:', ids)

        if (type === 'call') {
            ids.forEach(i => { if (!dismissed.value.calls.includes(i)) dismissed.value.calls.push(i) })
        } else if (type === 'email') {
            ids.forEach(i => { if (!dismissed.value.emails.includes(i)) dismissed.value.emails.push(i) })
        } else if (type === 'whatsapp') {
            ids.forEach(i => { if (!dismissed.value.whatsapps.includes(i)) dismissed.value.whatsapps.push(i) })
        } else if (type === 'parts') {
            ids.forEach(i => { if (!dismissed.value.parts.includes(i)) dismissed.value.parts.push(i) })
            dismissed.value.parts_ts = Date.now()
        } else if (type === 'completed') {
            ids.forEach(i => { if (!dismissed.value.completed.includes(i)) dismissed.value.completed.push(i) })
        } else if (type === 'anpr') {
            ids.forEach(i => {
                if (!dismissed.value.anpr.includes(i)) dismissed.value.anpr.push(i)
                // Auch serverseitig als dismissed markieren
                axios.post('/api/lxcars/', { action: 'dismissAnprDetection', id: i }).catch(() => {})
            })
        } else if (type === 'missing_order') {
            // Dismiss rein serverseitig (Tabelle missing_orders_lxcars)
            ids.forEach(i => {
                axios.post('/api/lxcars/', { action: 'dismissMissingOrder', id: i }).catch(() => {})
            })
            missingOrders.value = missingOrders.value.filter(m => !ids.includes(m.id))
            return
        }
        console.log('[InfoBar] dismissed nach Update:', JSON.parse(JSON.stringify(dismissed.value)))
        saveDismissed()
    }

    // Findet alle IDs in der Quell-Liste, die denselben Inhaltsschluessel haben
    // wie das per id uebergebene Item — fuer Dubletten-Sammelloeschung mit einem Klick.
    function collectDuplicateIds(type, id) {
        const sourceMap = {
            call:      { list: newCalls.value,             idField: 'crmti_id' },
            email:     { list: newEmails.value,            idField: 'uid' },
            whatsapp:  { list: newWhatsapps.value,         idField: 'id' },
            parts:     { list: pendingPartsRequests.value, idField: 'oe_id' },
            anpr:      { list: anprDetections.value,       idField: 'id' },
            completed: { list: completedOrders.value,      idField: 'oe_id' },
            missing_order: { list: missingOrders.value,    idField: 'id' }
        }
        const src = sourceMap[type]
        if (!src) {
            console.warn('[InfoBar] kein sourceMap fuer type:', type)
            return [id]
        }

        console.log('[InfoBar] Quell-Liste laenge:', src.list.length, 'idField:', src.idField)
        console.log('[InfoBar] Quell-Liste IDs+Keys:', src.list.map(x => ({
            id: x[src.idField],
            idType: typeof x[src.idField],
            key: dedupKey({ type, data: x })
        })))

        // Loose comparison damit String/Number-Mismatch nicht stoert
        const target = src.list.find(x => x[src.idField] == id)
        if (!target) {
            console.warn('[InfoBar] Ziel nicht in Quell-Liste gefunden! id:', id)
            return [id]
        }

        const key = dedupKey({ type, data: target })
        console.log('[InfoBar] Ziel gefunden, dedupKey:', key, 'Ziel-Daten:', target)
        if (!key) {
            console.warn('[InfoBar] kein dedupKey ableitbar — nur Einzel-Item dismissen')
            return [id]
        }

        const matches = src.list.filter(x => dedupKey({ type, data: x }) === key)
        console.log('[InfoBar] Dubletten gefunden:', matches.length, matches.map(x => x[src.idField]))
        return matches.map(x => x[src.idField])
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
                if (data.type === 'camera_event' || data.type === 'camera_alert') {
                    // Camera-Events an die Camera-View weiterleiten
                    window.dispatchEvent(new MessageEvent('camera-sse', { data: event.data }))
                } else if (data.message_type !== undefined) {
                    fetchNewWhatsapps()
                } else if (data.table === 'anpr_detections_lxcars') {
                    fetchAnprDetections()
                } else if (data.table === 'missing_orders_lxcars') {
                    fetchMissingOrders()
                } else if (data.table === 'oe_instructions_lxcars') {
                    fetchCompletedOrders()
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
        fetchCompletedOrders()
        fetchAnprDetections()
        fetchMissingOrders()
    }

    // --- Firmenwechsel: Daten zuruecksetzen und neu laden ---
    watch(() => oserp.session.client, () => {
        newCalls.value = []
        newEmails.value = []
        newWhatsapps.value = []
        pendingPartsRequests.value = []
        completedOrders.value = []
        anprDetections.value = []
        missingOrders.value = []
        dismissed.value = { calls: [], emails: [], whatsapps: [], parts: [], anpr: [], completed: [], parts_ts: null }

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
        unifiedItems,
        hasItems,
        dismissItem,
        fetchPendingPartsRequests,
        fetchAnprDetections,
        closeAll
    }
}
