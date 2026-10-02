// src/core/views/recurring-invoices/composables/useRecurringInvoices.js
//
// Zugriff auf backend/api/faktura/recurring.php. Alle Aufrufe gehen über
// denselben Endpunkt; ein Fehler der API wird als Error geworfen, damit die
// Aufrufer ihn mit alerts/toasts anzeigen können.

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/faktura/'

async function call(action, params = {}) {
    const r = await axios.post(API_URL, { action, ...params })
    if (!r.data?.success) {
        const msg = typeof r.data?.payload === 'string' ? r.data.payload : (r.data?.text || action)
        throw new Error(msg)
    }
    return r.data.payload?.results ?? r.data.payload
}

export function useRecurringInvoices() {
    const loading = ref(false)

    async function fetchOverview() {
        loading.value = true
        try { return await call('getRecurringOverview') }
        finally { loading.value = false }
    }

    async function fetchConfig(oeId, id = null) {
        loading.value = true
        try { return await call('getRecurringConfig', id ? { id } : { oe_id: oeId }) }
        finally { loading.value = false }
    }

    function preview(params) {
        return call('previewRecurringPeriods', params)
    }

    function saveConfig(config, items) {
        return call('saveRecurringConfig', { config, items })
    }

    function setStatus(id, status, extra = {}) {
        return call('setRecurringStatus', { id, status, ...extra })
    }

    function deleteConfig(id) {
        return call('deleteRecurringConfig', { id })
    }

    /** items: [{ config_id, period_start, force }] — oder all = true für alle fälligen */
    async function createInvoices(items, all = false) {
        loading.value = true
        try { return await call('createRecurringInvoices', all ? { all: true } : { items }) }
        finally { loading.value = false }
    }

    function skipPeriod(configId, periodStart, reason = '', undo = false) {
        return call('skipRecurringPeriod', { config_id: configId, period_start: periodStart, reason, undo })
    }

    function sendEmail(periodicInvoiceId) {
        return call('sendRecurringInvoiceEmail', { periodic_invoice_id: periodicInvoiceId })
    }

    return {
        loading,
        fetchOverview, fetchConfig, preview, saveConfig, setStatus, deleteConfig,
        createInvoices, skipPeriod, sendEmail
    }
}

/**
 * Voreinstellungen des Rhythmus: Einheit + Anzahl. Die Auswahl im Dialog
 * zeigt sie als Chips, „custom" öffnet Anzahl und Einheit.
 */
export const RHYTHM_PRESETS = [
    { key: 'monthly',     unit: 'month', count: 1 },
    { key: 'quarterly',   unit: 'month', count: 3 },
    { key: 'halfyearly',  unit: 'month', count: 6 },
    { key: 'yearly',      unit: 'year',  count: 1 },
    { key: 'weekly',      unit: 'week',  count: 1 },
    { key: 'biweekly',    unit: 'week',  count: 2 },
    { key: 'once',        unit: 'once',  count: 1 },
]

/** Platzhalter für Positionstexte, Bemerkungen und E-Mail */
export const PLACEHOLDERS = [
    'period_month', 'period', 'period_start_date', 'period_end_date', 'period_days',
    'current_month', 'current_month_long', 'current_year', 'current_quarter',
    'previous_month', 'previous_month_long', 'previous_year', 'previous_quarter',
    'next_month', 'next_month_long', 'next_year', 'next_quarter'
]

/** Zusätzliche Platzhalter nur für E-Mail-Betreff und -Text (Spalten der Rechnung) */
export const EMAIL_PLACEHOLDERS = ['invnumber', 'amount', 'netamount', 'duedate', 'transdate', 'ordnumber', 'transaction_description']

/**
 * Lesbarer Rhythmus („Monatlich", „Alle 2 Wochen", „Einmalig").
 * @param {Function} t vue-i18n t
 */
export function rhythmText(t, unit, count) {
    const n = Number(count) || 1
    if (unit === 'once') return t('RecurringInvoices.rhythm.once')
    if (unit === 'month' && n === 1)  return t('RecurringInvoices.rhythm.monthly')
    if (unit === 'month' && n === 3)  return t('RecurringInvoices.rhythm.quarterly')
    if (unit === 'month' && n === 6)  return t('RecurringInvoices.rhythm.halfyearly')
    if ((unit === 'month' && n === 12) || (unit === 'year' && n === 1)) return t('RecurringInvoices.rhythm.yearly')
    if (unit === 'week' && n === 1)   return t('RecurringInvoices.rhythm.weekly')
    if (unit === 'week' && n === 2)   return t('RecurringInvoices.rhythm.biweekly')
    if (unit === 'day' && n === 1)    return t('RecurringInvoices.rhythm.daily')
    return t('RecurringInvoices.rhythm.every', { n, unit: t(`RecurringInvoices.units.${unit}`, n) })
}

/** Betrag als Währung (Firmenwährung Euro, Anzeige in der Oberflächensprache) */
export function money(value, locale = 'de') {
    const map = { de: 'de-DE', en: 'en-GB', fr: 'fr-FR', es: 'es-ES', it: 'it-IT', nl: 'nl-NL', pl: 'pl-PL' }
    return new Intl.NumberFormat(map[locale] || 'de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
}
