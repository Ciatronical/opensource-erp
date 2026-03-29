// Composable: Aufträge sortieren, filtern, formatieren

import { ref, computed } from 'vue'
import { formatNumber } from '@/core/utils/numberFormat.js'

export function useCarOrders(locale) {
    const orders = ref([])
    const orderSortField = ref('transdate')
    const orderSortAsc = ref(false)
    const orderFilter = ref('')

    const sortedOrders = computed(() => {
        if (!orders.value.length) return []
        let list = orders.value
        const q = (orderFilter.value || '').trim().toLowerCase()
        if (q) {
            list = list.filter(o =>
                (o.ordnumber || '').toLowerCase().includes(q) ||
                (o.transdate || '').includes(q) ||
                (o.first_position || '').toLowerCase().includes(q) ||
                String(o.amount || '').includes(q)
            )
        }
        const field = orderSortField.value
        const asc = orderSortAsc.value
        return [...list].sort((a, b) => {
            let va = a[field], vb = b[field]
            if (field === 'amount') {
                va = Number(va) || 0
                vb = Number(vb) || 0
            } else if (field === 'transdate') {
                va = va ? va.split('.').reverse().join('') : ''
                vb = vb ? vb.split('.').reverse().join('') : ''
            } else {
                va = (va || '').toString().toLowerCase()
                vb = (vb || '').toString().toLowerCase()
            }
            if (va < vb) return asc ? -1 : 1
            if (va > vb) return asc ? 1 : -1
            return 0
        })
    })

    function toggleOrderSort(field) {
        if (orderSortField.value === field) {
            orderSortAsc.value = !orderSortAsc.value
        } else {
            orderSortField.value = field
            orderSortAsc.value = field === 'ordnumber' || field === 'first_position'
        }
    }

    function sortIcon(field) {
        if (orderSortField.value !== field) return 'mdi-unfold-more-horizontal'
        return orderSortAsc.value ? 'mdi-chevron-up' : 'mdi-chevron-down'
    }

    function formatAmount(value) {
        return formatNumber(value, locale.value) + ' \u20AC'
    }

    return {
        orders, sortedOrders, orderSortField, orderFilter,
        toggleOrderSort, sortIcon, formatAmount
    }
}
