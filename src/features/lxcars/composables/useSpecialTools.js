// src/features/lxcars/composables/useSpecialTools.js
//
// Composable für das Spezialwerkzeug: Werkzeuge, Zuordnungsregeln, feste
// Fahrzeugzuordnungen und die KI-Analyse. Jede Funktion entspricht genau
// einem Backend-Aufruf — zusammengesetzt wird in der Datenbank.

import { ref } from 'vue'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'

const API_URL = '/api/lxcars/'

/**
 * Einheitlicher Aufruf: wirft bei fachlichen Fehlern eine Exception mit
 * Fehlercode, damit die Ansicht gezielt reagieren kann (z. B. fehlender
 * API-Schlüssel → Hinweis auf die Firmeneinstellungen).
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

export function useSpecialTools() {
    const loading = ref(false)

    async function run(fn) {
        loading.value = true
        try { return await fn() } finally { loading.value = false }
    }

    // ── Werkzeuge ───────────────────────────────────────────────────────────
    const fetchTools   = (filters = {}) => run(() => post('getSpecialTools', filters))
    const fetchOptions = ()             => post('getSpecialToolOptions')
    const fetchTool    = (id, search = '', limit = 300) => post('getSpecialTool', { id, search, limit })
    const saveTool     = (payload)      => post('saveSpecialTool', payload)
    const deleteTool   = (id)           => post('deleteSpecialTool', { id })
    const rebuildMatches = ()           => post('rebuildSpecialToolMatches')
    const saveShopOffer = (payload)     => post('saveSpecialToolShopOffer', payload)

    // ── KI ──────────────────────────────────────────────────────────────────
    // Ohne run(): die Analyse dauert, der Dialog zeigt seinen eigenen Zustand.
    const analyzeTool  = (id, aiModel = null) => post('analyzeSpecialTool', { id, ai_model: aiModel })

    // ── Regeln ──────────────────────────────────────────────────────────────
    const saveRule       = (payload)       => post('saveSpecialToolRule', payload)
    const setRuleActive  = (id, active)    => post('setSpecialToolRuleActive', { id, active })
    const deleteRule     = (id)            => post('deleteSpecialToolRule', { id })
    const previewCriteria = (criteria, limit = 8) => post('previewSpecialToolCriteria', { criteria, limit })

    // ── Fahrzeuge ───────────────────────────────────────────────────────────
    const setVehicle     = (toolId, cId, mode, note = '') => post('setSpecialToolVehicle', { tool_id: toolId, c_id: cId, mode, note })
    const searchVehicles = (toolId, term, limit = 20)     => post('searchSpecialToolVehicles', { tool_id: toolId, term, limit })
    const fetchForCar    = (cId)                          => post('getSpecialToolsForCar', { c_id: cId })

    return {
        loading,
        fetchTools, fetchOptions, fetchTool, saveTool, deleteTool, rebuildMatches, saveShopOffer,
        analyzeTool,
        saveRule, setRuleActive, deleteRule, previewCriteria,
        setVehicle, searchVehicles, fetchForCar,
    }
}

/** Die Kriterien einer Regel in Anzeige-Chips übersetzen (Listen und Bereiche) */
export const CRITERIA_LIST_KEYS = ['makes', 'hsn', 'models', 'engine_codes', 'fuel', 'vehicle_types']
export const CRITERIA_RANGE_KEYS = [['ccm_from', 'ccm_to'], ['kw_from', 'kw_to'], ['year_from', 'year_to']]

/**
 * Leere Kriterien-Vorlage für den Regel-Editor
 */
export function emptyCriteria() {
    return {
        makes: [], hsn: [], models: [], engine_codes: [], fuel: [], vehicle_types: [],
        ccm_from: null, ccm_to: null, kw_from: null, kw_to: null, year_from: null, year_to: null,
    }
}

/**
 * Kriterien fürs Backend bereinigen: leere Listen und leere Zahlen weglassen
 */
export function compactCriteria(c) {
    const out = {}
    for (const key of CRITERIA_LIST_KEYS) {
        const list = (c[key] || []).map(v => String(v).trim()).filter(Boolean)
        if (list.length) out[key] = list
    }
    for (const [from, to] of CRITERIA_RANGE_KEYS) {
        for (const key of [from, to]) {
            const n = Number(c[key])
            if (c[key] !== null && c[key] !== '' && Number.isFinite(n) && n > 0) out[key] = Math.round(n)
        }
    }
    return out
}
