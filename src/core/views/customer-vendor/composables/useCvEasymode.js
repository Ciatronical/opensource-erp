// src/core/views/customer-vendor/composables/useCvEasymode.js

import { computed } from 'vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import {
    CV_ALL_FIELDS,
    CV_REQUIRED_FIELD_KEYS,
    CV_TABS,
    CV_EASYMODE_DEFAULT_FIELDS,
    CV_EASYMODE_DEFAULT_TABS,
    parseFieldList,
    fieldHasValue,
} from '../cvFieldCatalog.js'

/** Benutzerentscheidung (company_employee_config) */
export const CV_EASYMODE_KEY = 'cv_easymode'
/** Firmenstandard und Feldauswahl (defaults_oserp) */
export const CV_EASYMODE_DEFAULT_KEY = 'cv_easymode_default'
export const CV_EASYMODE_FIELDS_KEY = 'cv_easymode_fields'
export const CV_EASYMODE_TABS_KEY = 'cv_easymode_tabs'

function toBool(value, fallback) {
    if (value === null || value === undefined || value === '') return fallback
    return value === true || value === 'true' || value === 't' || value === '1' || value === 1
}

/**
 * Einfacher Modus der Kunden-/Lieferantenmaske.
 *
 * Wie die Kompaktansicht der Faktura: der Benutzer schaltet in der Maske um,
 * die Entscheidung wird pro Benutzer gemerkt. Solange er nichts entschieden
 * hat, gilt der Firmenstandard (Firmenkonfiguration → CRM → Einfacher Modus),
 * und der steht ohne Eintrag auf "an".
 *
 * Welche Felder und Reiter im einfachen Modus bleiben, legt die Firma dort
 * fest. Pflichtfelder fürs Speichern bleiben immer sichtbar — sonst käme der
 * Benutzer nie zum Speichern.
 */
export function useCvEasymode() {
    const oserp = oserpStore()

    const companyDefault = computed(() =>
        toBool(oserp.getClientDefaultValue(CV_EASYMODE_DEFAULT_KEY, null), true))

    const easymode = computed({
        get() {
            return toBool(oserp.getConfigValue(CV_EASYMODE_KEY, null), companyDefault.value)
        },
        set(value) {
            if (!!value === easymode.value) return
            oserp.setConfigValue(CV_EASYMODE_KEY, !!value)
        },
    })

    const easyFieldKeys = computed(() => {
        const chosen = parseFieldList(
            oserp.getClientDefaultValue(CV_EASYMODE_FIELDS_KEY, null),
            CV_EASYMODE_DEFAULT_FIELDS
        )
        return new Set([...chosen, ...CV_REQUIRED_FIELD_KEYS])
    })

    const easyTabKeys = computed(() => {
        const chosen = parseFieldList(
            oserp.getClientDefaultValue(CV_EASYMODE_TABS_KEY, null),
            CV_EASYMODE_DEFAULT_TABS
        )
        const fixed = CV_TABS.filter(tab => tab.fixed).map(tab => tab.key)
        return new Set([...chosen, ...fixed])
    })

    /** Feld anzeigen? Außerhalb des einfachen Modus immer. */
    function show(fieldKey) {
        return !easymode.value || easyFieldKeys.value.has(fieldKey)
    }

    /** Mindestens eines der Felder sichtbar? (Karte ganz ausblenden, wenn nicht) */
    function showAny(fieldKeys) {
        return fieldKeys.some(show)
    }

    function showTab(tabKey) {
        return !easymode.value || easyTabKeys.value.has(tabKey)
    }

    /**
     * Felder, die im einfachen Modus ausgeblendet sind, im Profil aber einen
     * Wert tragen. Vorbelegte Felder (Währung, Steuersatz …) zählen nicht,
     * sonst stünde die Liste bei jedem neuen Kunden voll.
     */
    function hiddenFilledFields(profile) {
        if (!easymode.value) return []
        const src = profile?.src || 'C'
        return CV_ALL_FIELDS.filter(field =>
            !field.defaulted
            && (!field.src || field.src === src)
            && !easyFieldKeys.value.has(field.key)
            && fieldHasValue(field, profile)
        )
    }

    return { easymode, companyDefault, easyFieldKeys, easyTabKeys, show, showAny, showTab, hiddenFilledFields }
}
