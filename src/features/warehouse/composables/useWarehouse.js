// src/features/warehouse/composables/useWarehouse.js
//
// Composable fuer das Lagermodul: Bestand, Buchungen, Bewegungen, Stammdaten.
// Jede Funktion entspricht genau einem Backend-Aufruf; zusammengesetzt wird
// nichts — das erledigt die Datenbank.

import { ref } from 'vue'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'

const API_URL = '/api/warehouse/'

/**
 * Einheitlicher Aufruf: wirft bei fachlichen Fehlern eine Exception mit
 * Fehlercode und Nutzlast, damit die Ansicht gezielt reagieren kann
 * (z. B. "zu wenig Bestand" mit der verfuegbaren Menge).
 */
async function post(action, payload = {}) {
    const store = oserpStore()
    const { data } = await axios.post(API_URL, {
        action,
        employee_id: store.session?.logged_in_employee?.id,
        ...payload,
    })
    if (!data.success) {
        const err = new Error(data.text || 'API_ERROR')
        err.code = data.text
        err.payload = data.payload
        throw err
    }
    return data.payload?.results ?? data.payload ?? null
}

export function useWarehouse() {
    const loading = ref(false)

    async function run(fn) {
        loading.value = true
        try { return await fn() } finally { loading.value = false }
    }

    // ── Cockpit & Stammdaten ────────────────────────────────────────────────
    const fetchOverview   = (deadDays = 180) => run(() => post('getWarehouseOverview', { dead_days: deadDays }))
    const fetchOptions    = ()                => run(() => post('getWarehouseOptions'))
    const createDefault   = (warehouse, bin)  => run(() => post('createDefaultWarehouse', { warehouse, bin }))
    const saveWarehouse   = (payload)         => run(() => post('saveWarehouse', payload))
    const saveBin         = (payload)         => run(() => post('saveBin', payload))
    const deleteBin       = (id)              => run(() => post('deleteBin', { id }))

    // ── Bestand ─────────────────────────────────────────────────────────────
    const fetchStock      = (filters = {})    => run(() => post('getStock', filters))
    const fetchPartStock  = (partsId)         => run(() => post('getPartStock', { parts_id: partsId }))
    const lookupCode      = (code)            => run(() => post('lookupPartByCode', { code }))
    const setStockable    = (partsId, on)     => run(() => post('setPartStockable', { parts_id: partsId, stockable: on }))
    const setRop          = (partsId, rop)    => run(() => post('setPartRop', { parts_id: partsId, rop }))
    const recalcOnhand    = (partsId = 0)     => run(() => post('recalcPartsOnhand', { parts_id: partsId }))

    // ── Buchen ──────────────────────────────────────────────────────────────
    // Ohne run(): der Scanner-Modus zeigt seinen eigenen Ladezustand und darf
    // nicht die ganze Ansicht sperren.
    const bookStock       = (payload)         => post('bookStock', payload)
    const undoTransfer    = (transId)         => post('undoStockTransfer', { trans_id: transId })

    // ── Bewegungen ──────────────────────────────────────────────────────────
    const fetchJournal    = (filters = {})    => run(() => post('getStockJournal', filters))

    return {
        loading,
        fetchOverview, fetchOptions, createDefault, saveWarehouse, saveBin, deleteBin,
        fetchStock, fetchPartStock, lookupCode, setStockable, setRop, recalcOnhand,
        bookStock, undoTransfer,
        fetchJournal,
    }
}

/**
 * Composable fuer die Inventur.
 */
export function useStocktaking() {
    const loading = ref(false)

    async function run(fn) {
        loading.value = true
        try { return await fn() } finally { loading.value = false }
    }

    const fetchSessions = ()              => run(() => post('getStocktakingSessions'))
    const createSession = (payload)       => run(() => post('createStocktakingSession', payload))
    const fetchList     = (payload)       => run(() => post('getStocktakingList', payload))
    const fetchSummary  = (sessionId)     => run(() => post('getStocktakingSummary', { session_id: sessionId }))
    const postSession   = (sessionId)     => run(() => post('postStocktaking', { session_id: sessionId }))
    const cancelSession = (sessionId)     => run(() => post('cancelStocktakingSession', { session_id: sessionId }))

    // Ohne run(): waehrend des Zaehlens darf die Liste nicht flackern.
    const saveCount     = (payload)       => post('saveStocktakingCount', payload)
    const deleteCount   = (countId)       => post('deleteStocktakingCount', { count_id: countId })

    return {
        loading,
        fetchSessions, createSession, fetchList, fetchSummary, postSession, cancelSession,
        saveCount, deleteCount,
    }
}
