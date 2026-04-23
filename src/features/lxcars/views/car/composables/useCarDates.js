// Composable: Display-Refs und Blur-Handler für alle Datumsfelder
// - Erstzulassung + HU (deutsches Format: TT.MM.JJJJ)
// - Wartungstermine (Monat/Jahr: MM/JJJJ)
// - km-Feld (Kurzeingabe: 180 → 180.000)

import { ref, watch } from 'vue'
import { parseShortDate, formatDateDE, parseMonthYear, formatMonthYear } from '@/features/lxcars/utils/validation.js'

export function useCarDates(car) {
    // ── Erstzulassung + HU ──
    const displayD = ref('')
    const displayHu = ref('')

    watch(() => car.value.c_d, (val) => { displayD.value = formatDateDE(val) })
    watch(() => car.value.c_hu, (val) => { displayHu.value = formatDateDE(val) })

    const dateRefs = { c_d: displayD, c_hu: displayHu }

    function onBlurDate(field) {
        const displayRef = dateRefs[field]
        const raw = displayRef.value.trim()
        if (!raw) { car.value[field] = null; return }
        const parsed = parseShortDate(raw)
        if (parsed) {
            car.value[field] = parsed
            displayRef.value = formatDateDE(parsed)
        } else {
            car.value[field] = null
        }
    }

    // ── Wartungstermine: Monat/Jahr ──
    const displayZrd = ref('')
    const displayBf = ref('')
    const displayWd = ref('')

    watch(() => car.value.c_zrd, (val) => { displayZrd.value = formatMonthYear(val) })
    watch(() => car.value.c_bf, (val) => { displayBf.value = formatMonthYear(val) })
    watch(() => car.value.c_wd, (val) => { displayWd.value = formatMonthYear(val) })

    const monthYearRefs = { c_zrd: displayZrd, c_bf: displayBf, c_wd: displayWd }

    function onBlurMonthYear(field) {
        const displayRef = monthYearRefs[field]
        const raw = displayRef.value.trim()
        if (!raw) { car.value[field] = null; return }
        const parsed = parseMonthYear(raw)
        if (parsed) {
            car.value[field] = parsed
            displayRef.value = formatMonthYear(parsed)
        } else {
            car.value[field] = null
        }
    }

    // ── km-Feld: Kurzeingabe (180 → 180.000) ──
    const displayZrk = ref('')
    const displayKm = ref('')

    function formatKm(value) {
        const num = Number(value)
        if (!num) return ''
        return num.toLocaleString('de-DE')
    }

    watch(() => car.value.c_zrk, (val) => { displayZrk.value = formatKm(val) })
    watch(() => car.value.c_km, (val) => { displayKm.value = formatKm(val) })

    function onBlurKm() {
        const raw = String(displayZrk.value).replace(/[.\s]/g, '').trim()
        if (!raw) { car.value.c_zrk = null; return }
        const num = parseInt(raw, 10)
        if (isNaN(num) || num <= 0) { car.value.c_zrk = null; displayZrk.value = ''; return }
        const km = num < 1000 ? num * 1000 : num
        car.value.c_zrk = km
        displayZrk.value = formatKm(km)
    }

    function onBlurKmStand() {
        const raw = String(displayKm.value).replace(/[.\s]/g, '').trim()
        if (!raw) { car.value.c_km = null; displayKm.value = ''; return }
        const num = parseInt(raw, 10)
        if (isNaN(num) || num <= 0) { car.value.c_km = null; displayKm.value = ''; return }
        car.value.c_km = num
        displayKm.value = formatKm(num)
    }

    return {
        displayD, displayHu, onBlurDate,
        displayZrd, displayBf, displayWd, onBlurMonthYear,
        displayZrk, onBlurKm,
        displayKm, onBlurKmStand
    }
}
