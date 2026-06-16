// Composable: Finanzberechnungen, Buchungssätze, Steuer-Breakdown

import { ref, computed, watch } from 'vue'

export function useAccounting({ fakturaItems, faktura, fakturaType, paymentList, oserp, currencyList }) {

    const calculatedNetAmount = ref(0)
    const calculatedGrossAmount = ref(0)
    const taxBreakdown = ref([])

    // AR-Konten für "Buchen auf" Dropdown (gefiltert nach link='AR')
    const arAmountList = computed(() => {
        const charts = oserp.session?.company_config?.chart || []
        return charts
            .filter(chart => chart.link === 'AR')
            .map(chart => ({
                ...chart,
                label: `${chart.accno} -- ${chart.description}`
            }))
            .sort((a, b) => a.accno.localeCompare(b.accno))
    })

    // Watcher: Setze Default-Wert für AR_target sobald arAmountList und faktura.data verfügbar sind
    watch([arAmountList, () => faktura.data], ([newList, data]) => {
        if (newList.length > 0 && data?.common && !data.common.AR_target) {
            faktura.data.common.AR_target = newList[0].id
        }
    }, { immediate: true })

    // Prüft ob Fremdwährung verwendet wird (nicht EUR)
    const isForeignCurrency = computed(() => {
        if (!faktura.data?.common?.currency_id) {
            return false
        }
        const currency = currencyList.value.find(c => c.id === faktura.data.common.currency_id)
        return currency && currency.name !== 'EUR'
    })

    function roundMoney(value, decimals = 2) {
        const factor = 10 ** decimals
        return Math.round((value + Number.EPSILON) * factor) / factor
    }

    function calculateItemTotal(item) {
        if (!item || !item.qty || !item.sellprice) {
            return
        }
        const subtotal = item.qty * item.sellprice
        const discountAmount = subtotal * item.discount
        item.marge_total = Math.round((subtotal - discountAmount) * 100) / 100
    }

    // Debounce Timer für calculateTotals
    let calculateTotalsTimeout = null

    function calculateTotals() {
        if (calculateTotalsTimeout) {
            clearTimeout(calculateTotalsTimeout)
        }
        calculateTotalsTimeout = setTimeout(calculateTotalsImmediate, 150)
    }

    function calculateTotalsImmediate() {
        const taxIncluded = !!faktura.data?.common?.taxincluded

        let netAmount = 0
        let grossAmount = 0
        const baseByRate = new Map()

        fakturaItems.value.forEach(item => {
            if (item.id !== null && item.parts_id) {
                const itemTotal = item.qty * item.sellprice * (1 - item.discount)
                const roundedItemTotal = roundMoney(itemTotal, 2)
                const rate = item.buchungsziel?.rate ?? 0
                const key = String(rate)

                if (taxIncluded) {
                    // Preise sind brutto → Netto rausrechnen
                    const netFromGross = rate ? roundMoney(roundedItemTotal / (1 + rate), 2) : roundedItemTotal
                    baseByRate.set(key, (baseByRate.get(key) ?? 0) + netFromGross)
                    grossAmount += roundedItemTotal
                } else {
                    baseByRate.set(key, (baseByRate.get(key) ?? 0) + roundedItemTotal)
                    netAmount += roundedItemTotal
                }
            }
        })

        let totalTax = 0
        const breakdown = []

        for (const [key, base] of baseByRate.entries()) {
            const rate = Number(key)
            const tax = rate ? roundMoney(base * rate, 2) : 0
            const total = roundMoney(base + tax, 2)
            totalTax += tax
            breakdown.push({ rate, base: roundMoney(base, 2), tax, total })
        }

        breakdown.sort((a, b) => b.rate - a.rate)
        taxBreakdown.value = breakdown

        if (taxIncluded) {
            netAmount = roundMoney(grossAmount - totalTax, 2)
        } else {
            grossAmount = roundMoney(netAmount + totalTax, 2)
            netAmount = roundMoney(netAmount, 2)
        }

        faktura.data.common.netamount = netAmount
        faktura.data.common.amount = grossAmount
        calculatedNetAmount.value = netAmount
        calculatedGrossAmount.value = grossAmount
    }

    /**
     * Berechnet die Buchungssätze für acc_trans basierend auf den Positionen
     */
    function calculateAccTransEntries() {
        if (fakturaType.value !== 'invoice') {
            return []
        }

        const taxIncluded = !!faktura.data?.common?.taxincluded
        const transdate = faktura.data.common?.transdate || new Date().toISOString().split('T')[0]
        const groups = new Map()

        fakturaItems.value.forEach(item => {
            if (!item.id || !item.parts_id || !item.buchungsziel) {
                return
            }

            const bz = item.buchungsziel
            const key = `${bz.income_chart_id}_${bz.tax_id}`

            if (!groups.has(key)) {
                groups.set(key, {
                    income_chart_id: bz.income_chart_id,
                    tax_chart_id: bz.tax_chart_id,
                    tax_id: bz.tax_id,
                    rate: bz.rate ?? 0,
                    netAmount: 0
                })
            }

            const itemTotal = item.qty * item.sellprice * (1 - item.discount)
            const rounded = roundMoney(itemTotal, 2)
            const rate = bz.rate ?? 0

            if (taxIncluded && rate) {
                groups.get(key).netAmount += roundMoney(rounded / (1 + rate), 2)
            } else {
                groups.get(key).netAmount += rounded
            }
        })

        const entries = []
        let grossTotal = 0

        for (const group of groups.values()) {
            const netAmount = roundMoney(group.netAmount, 2)
            const taxAmount = group.rate ? roundMoney(netAmount * group.rate, 2) : 0

            let taxkey = 0
            if (group.rate > 0.1) {
                taxkey = 3
            } else if (group.rate > 0) {
                taxkey = 2
            }

            entries.push({
                chart_id: group.income_chart_id,
                amount: netAmount,
                transdate: transdate,
                tax_id: group.tax_id,
                taxkey: taxkey
            })

            if (taxAmount !== 0 && group.tax_chart_id) {
                entries.push({
                    chart_id: group.tax_chart_id,
                    amount: taxAmount,
                    transdate: transdate,
                    tax_id: 0,
                    taxkey: 0
                })
            }

            grossTotal += netAmount + taxAmount
        }

        const arTargetId = faktura.data.common?.AR_target
        if (arTargetId && grossTotal !== 0) {
            entries.push({
                chart_id: arTargetId,
                amount: roundMoney(-grossTotal, 2),
                transdate: transdate,
                tax_id: 0,
                taxkey: 0
            })
        }

        return entries
    }

    /**
     * Berechnet die Zahlungsbuchungen für acc_trans
     */
    function calculatePaymentEntries() {
        if (fakturaType.value !== 'invoice') {
            return []
        }

        const arTargetId = faktura.data.common?.AR_target
        if (!arTargetId) {
            return []
        }

        const entries = []

        paymentList.value.forEach(payment => {
            const paymentAmount = Math.abs(payment.amount || 0)
            if (paymentAmount === 0 || !payment.chart_id) {
                return
            }

            const transdate = payment.transdate || new Date().toISOString().split('T')[0]
            const source = payment.source || ''
            const memo = payment.memo || ''
            // ID der bestehenden Zahlungsbuchung (AR_paid-Bein) mitsenden, damit das
            // Backend eine bearbeitete Zahlung erkennt statt sie zu duplizieren
            const accTransId = payment.acc_trans_id || null
            // Entsperrte bank-gebuchte Zahlung: Backend soll die geschützten acc_trans-Beine
            // in-place aktualisieren statt sie nur zu schützen (siehe payment.section.card.vue).
            const bankEdit = payment.bank_edit === true

            entries.push({
                acc_trans_id: accTransId,
                chart_id: arTargetId,
                amount: roundMoney(paymentAmount, 2),
                transdate, source, memo,
                tax_id: 0, taxkey: 0,
                bank_edit: bankEdit
            })

            entries.push({
                acc_trans_id: accTransId,
                chart_id: payment.chart_id,
                amount: roundMoney(-paymentAmount, 2),
                transdate, source, memo,
                tax_id: 0, taxkey: 0,
                bank_edit: bankEdit
            })
        })

        return entries
    }

    /**
     * Erzwingt sofortige Neuberechnung (umgeht Debounce)
     */
    function flushCalculation() {
        if (calculateTotalsTimeout) {
            clearTimeout(calculateTotalsTimeout)
            calculateTotalsTimeout = null
        }
        calculateTotalsImmediate()
    }

    return {
        calculatedNetAmount,
        calculatedGrossAmount,
        taxBreakdown,
        arAmountList,
        isForeignCurrency,
        roundMoney,
        calculateItemTotal,
        calculateTotals,
        flushCalculation,
        calculateAccTransEntries,
        calculatePaymentEntries
    }
}
