// src/features/accounting/composables/useEasymode.js

import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'

/**
 * Einfacher Modus der Buchhaltung.
 *
 * An (Standard): Alltagssprache statt Fachsprache, keine Soll-/Haben-Spalten,
 * keine Steuerschluessel, nur die Arbeitskacheln. Aus: die fachliche Sicht mit
 * Buchungssatz, Steuerschluessel und Kontenrahmen.
 *
 * Der Wert liegt in company_employee_config und gilt damit pro Benutzer — er
 * laesst sich in den Benutzereinstellungen und direkt in der Buchhaltung
 * umschalten, beide schreiben in denselben Schluessel.
 */
export const EASYMODE_KEY = 'accounting_easymode'

export function useEasymode() {
    const oserp = oserpStore()
    const { t, te } = useI18n()

    const easymode = computed({
        get() {
            const value = oserp.getConfigValue(EASYMODE_KEY, null)
            // Ohne gespeicherte Entscheidung ist der einfache Modus an: er ist
            // fuer die Mehrheit richtig, und der Umschalter steht sichtbar daneben.
            if (value === null || value === undefined || value === '') return true
            return value === true || value === 'true' || value === 't' || value === '1' || value === 1
        },
        set(value) {
            oserp.setConfigValue(EASYMODE_KEY, !!value)
        }
    })

    /**
     * Beschriftung im jeweiligen Modus.
     *
     * Grundlage ist immer der Alltagsbegriff. Gibt es zum Schluessel eine
     * fachliche Fassung unter AccountingView.pro.*, gewinnt sie im Profimodus.
     * So steht im einfachen Modus "Kunden schulden mir" und im fachlichen
     * "Forderungen (Debitoren)" — ohne dass jede Stelle zwei Schluessel kennt.
     */
    function label(key, params) {
        if (!easymode.value) {
            const proKey = key.replace('AccountingView.', 'AccountingView.pro.')
            if (te(proKey)) return t(proKey, params || {})
        }
        return t(key, params || {})
    }

    /** Fachbegriff nur im Profimodus zeigen, sonst den Alltagsbegriff */
    function term(easyText, proText) {
        return easymode.value ? easyText : proText
    }

    return { easymode, label, term }
}
