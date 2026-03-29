// Composable: Validierungsregeln, hasValidationErrors, Unique-Checks

import { ref, computed, watch, nextTick } from 'vue'
import {
    validateLicensePlate, validateHsn, validateTsn, validateEmission,
    validateFirstRegistration, validateHuDate, validateFin,
    validateMonthYear, parseShortDate
} from '@/features/lxcars/utils/validation.js'

export function useCarValidation(car, t, { displayD, displayHu, carsStore, isEditMode, carId, initialLoaded }) {
    // i18n-Wrapper: übersetzt Validierungs-Keys, gibt true durch
    function tr(validator) {
        return v => {
            const result = validator(v)
            if (result === true) return true
            if (typeof result === 'object' && result.key) return t('CarEditView.' + result.key, result.params)
            return t('CarEditView.' + result)
        }
    }

    // ── Unique-Check State ──
    const lnDuplicateError = ref('')
    const finDuplicateError = ref('')
    let lnCheckTimeout = null
    let finCheckTimeout = null

    // ── Validierungs-Rules (nur aktiv wenn Shield eingeschaltet) ──
    const rulesLn = computed(() => {
        const rules = car.value.chk_c_ln ? [tr(validateLicensePlate)] : []
        if (lnDuplicateError.value) rules.push(() => lnDuplicateError.value)
        return rules
    })
    const rulesHsn = computed(() => car.value.chk_c_2 ? [tr(validateHsn)] : [])
    const rulesTsn = computed(() => car.value.chk_c_3 ? [tr(validateTsn)] : [])
    const rulesEm = computed(() => car.value.chk_c_em ? [tr(validateEmission)] : [])

    const rulesD = computed(() => {
        if (!car.value.chk_c_d) return []
        return [(v) => {
            if (!v) return true
            const iso = parseShortDate(v)
            if (!iso) return t('CarEditView.validation.dateFormat')
            const result = validateFirstRegistration(iso)
            if (result === true) return true
            return t('CarEditView.' + result)
        }]
    })

    const rulesHu = computed(() => {
        if (!car.value.chk_c_hu) return []
        return [(v) => {
            if (!v) return true
            const iso = parseShortDate(v)
            if (!iso) return t('CarEditView.validation.dateFormat')
            const result = validateHuDate(iso)
            if (result === true) return true
            return t('CarEditView.' + result)
        }]
    })

    const rulesFin = computed(() => {
        const finchk = car.value.c_finchk
        const rules = car.value.chk_fin ? [tr(v => validateFin(v, finchk))] : []
        if (finDuplicateError.value) rules.push(() => finDuplicateError.value)
        return rules
    })

    const rulesMonthYear = [(v) => {
        if (!v) return true
        const result = validateMonthYear(v)
        if (result === true) return true
        return t('CarEditView.' + result)
    }]

    // ── hasValidationErrors (blockiert Auto-Save) ──
    const hasValidationErrors = computed(() => {
        if (lnDuplicateError.value) return true
        if (finDuplicateError.value) return true
        if (car.value.chk_c_ln && validateLicensePlate(car.value.c_ln) !== true) return true
        if (car.value.chk_c_2 && validateHsn(car.value.c_2) !== true) return true
        if (car.value.chk_c_3 && validateTsn(car.value.c_3) !== true) return true
        if (car.value.chk_c_em && validateEmission(car.value.c_em) !== true) return true
        if (car.value.chk_c_d && displayD.value && !parseShortDate(displayD.value)) return true
        if (car.value.chk_c_d && validateFirstRegistration(car.value.c_d) !== true) return true
        if (car.value.chk_c_hu && displayHu.value && !parseShortDate(displayHu.value)) return true
        if (car.value.chk_c_hu && car.value.c_hu && validateHuDate(car.value.c_hu) === 'validation.huFuture') return true
        if (car.value.chk_fin && validateFin(car.value.c_fin, car.value.c_finchk) !== true) return true
        return false
    })

    // ── FIN-Feld Revalidierung bei Prüfziffer-Änderung ──
    const finFieldRef = ref(null)
    watch(() => car.value.c_finchk, () => {
        nextTick(() => { finFieldRef.value?.validate() })
    })

    // ── Unique-Checks (debounced) ──
    watch(() => car.value.c_ln, (newVal) => {
        lnDuplicateError.value = ''
        if (lnCheckTimeout) clearTimeout(lnCheckTimeout)
        if (!newVal || !initialLoaded.value) return
        lnCheckTimeout = setTimeout(async () => {
            try {
                const result = await carsStore.checkLicensePlate(newVal, isEditMode.value ? Number(carId.value) : 0)
                if (result.exists) {
                    lnDuplicateError.value = t('CarEditView.validation.licensePlateDuplicate', { owner: result.owner_name || '?' })
                }
            } catch (e) { /* Netzwerkfehler ignorieren */ }
        }, 600)
    })

    watch(() => car.value.c_fin, (newVal) => {
        finDuplicateError.value = ''
        if (finCheckTimeout) clearTimeout(finCheckTimeout)
        if (!newVal || !initialLoaded.value) return
        finCheckTimeout = setTimeout(async () => {
            try {
                const result = await carsStore.checkFin(newVal, isEditMode.value ? Number(carId.value) : 0)
                if (result.exists) {
                    finDuplicateError.value = t('CarEditView.validation.finDuplicate', { owner: result.owner_name || '?' })
                }
            } catch (e) { /* Netzwerkfehler ignorieren */ }
        }, 600)
    })

    function cleanup() {
        if (lnCheckTimeout) clearTimeout(lnCheckTimeout)
        if (finCheckTimeout) clearTimeout(finCheckTimeout)
    }

    return {
        rulesLn, rulesHsn, rulesTsn, rulesEm, rulesD, rulesHu, rulesFin, rulesMonthYear,
        hasValidationErrors, finFieldRef, cleanup
    }
}
