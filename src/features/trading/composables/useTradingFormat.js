// src/features/trading/composables/useTradingFormat.js

import { useI18n } from 'vue-i18n'

/**
 * Zahlen- und Datumsformate der Trading-Ansichten in der Sprache der Oberfläche
 *
 * @param {Function} currency - liefert die Kontowährung (z. B. 'EUR')
 */
export function useTradingFormat(currency = () => 'EUR') {
    const { locale } = useI18n()

    function fmtMoney(value, signed = false) {
        const n = Number(value)
        if (!Number.isFinite(n)) return '–'
        return new Intl.NumberFormat(locale.value, {
            style: 'currency', currency: currency() || 'EUR', maximumFractionDigits: 2,
            signDisplay: signed ? 'exceptZero' : 'auto',
        }).format(n)
    }

    function fmtPct(value, signed = true) {
        const n = Number(value)
        if (!Number.isFinite(n)) return '–'
        return new Intl.NumberFormat(locale.value, {
            minimumFractionDigits: 1, maximumFractionDigits: 1, signDisplay: signed ? 'exceptZero' : 'auto',
        }).format(n) + ' %'
    }

    function fmtNum(value, digits = 2) {
        const n = Number(value)
        if (value === null || value === undefined || value === '' || !Number.isFinite(n)) return ''
        return new Intl.NumberFormat(locale.value, { maximumFractionDigits: digits }).format(n)
    }

    function fmtDate(value) {
        const d = new Date(value)
        return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(d)
    }

    function fmtDateTime(value) {
        const d = new Date(value)
        return Number.isNaN(d.getTime()) ? '' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(d)
    }

    // „KI aktiv“ heisst: eingeschaltet, nicht angehalten, und der letzte Lauf ist jünger als eine Stunde
    function engineAlive(summary) {
        if (!summary?.engine_enabled || summary.mode === 'halted' || !summary.engine_heartbeat) return false
        return Date.now() - new Date(summary.engine_heartbeat).getTime() < 3600 * 1000
    }

    return { fmtMoney, fmtPct, fmtNum, fmtDate, fmtDateTime, engineAlive }
}
