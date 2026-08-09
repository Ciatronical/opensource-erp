<!-- src/core/views/document-list/document.list.view.vue -->
<!-- Belegliste fuer /rechnung, /gutschrift, /auftrag, /angebot, /lieferschein
     und /artikel. Welcher Typ gezeigt wird, steht in der Route (meta.listType) —
     so teilen sich alle Listen eine Ansicht und bleiben in Bedienung und Optik
     identisch. Klick auf eine Zeile oeffnet den Beleg bzw. den Artikel. -->
<template>
    <NavbarView />

    <v-container class="pt-2 px-2 px-sm-4" fluid>
        <v-row class="mb-2 align-center">
            <v-col>
                <h1 class="text-h5 text-sm-h4 d-flex align-center">
                    <v-icon class="me-2" color="primary">{{ config.icon }}</v-icon>
                    {{ t(config.title) }}
                    <v-chip v-if="!loading" class="ms-3" size="small" variant="tonal" color="primary">
                        {{ rows.length }}{{ atLimit ? '+' : '' }}
                    </v-chip>
                </h1>
            </v-col>
            <v-col cols="auto">
                <v-btn v-if="hasFilter" variant="text" size="small" prepend-icon="mdi-filter-remove" @click="reset">
                    {{ t('DocumentList.reset') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row dense class="mb-1">
            <v-col cols="12" :md="isParts ? 8 : 5">
                <v-text-field
                    v-model="search"
                    :label="t(isParts ? 'DocumentList.searchParts' : 'DocumentList.search')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    autofocus
                />
            </v-col>
            <template v-if="!isParts">
                <v-col cols="6" md="2">
                    <v-text-field
                        v-model="from"
                        :label="t('DocumentList.from')"
                        type="date"
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" md="2">
                    <v-text-field
                        v-model="to"
                        :label="t('DocumentList.to')"
                        type="date"
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="12" md="3" class="d-flex align-center">
                    <v-switch
                        v-model="openOnly"
                        :label="t('DocumentList.openOnly')"
                        color="primary"
                        density="compact"
                        hide-details
                        class="ms-1"
                    />
                </v-col>
            </template>
            <v-col v-else cols="12" md="4" class="d-flex align-center">
                <v-switch
                    v-model="withObsolete"
                    :label="t('DocumentList.withObsolete')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1"
                />
            </v-col>
        </v-row>

        <v-alert v-if="error" type="warning" variant="tonal" density="compact" class="mb-2">
            {{ error }}
        </v-alert>

        <v-data-table
            :headers="headers"
            :items="visibleRows"
            :loading="loading"
            :items-per-page="50"
            density="compact"
            hover
            class="zebra-table"
            @click:row="openRow"
        >
            <template #item.transdate="{ item }">{{ formatDate(item.transdate, locale) }}</template>
            <template #item.amount="{ item }">
                <span class="text-no-wrap">{{ item.amount === null ? '' : formatCurrency(item.amount) }}</span>
            </template>
            <template #item.sellprice="{ item }">
                <span class="text-no-wrap">{{ formatCurrency(item.sellprice) }}</span>
            </template>
            <template #item.onhand="{ item }">
                <span class="text-no-wrap">{{ formatQty(item.onhand) }}</span>
            </template>
            <template #item.closed="{ item }">
                <v-chip :color="item.closed ? 'success' : 'warning'" size="x-small" variant="tonal">
                    {{ t(item.closed ? 'DocumentList.closed' : 'DocumentList.open') }}
                </v-chip>
            </template>
            <template #item.obsolete="{ item }">
                <v-chip v-if="item.obsolete" color="grey" size="x-small" variant="tonal">
                    {{ t('DocumentList.obsolete') }}
                </v-chip>
            </template>
            <template #no-data>
                <div class="text-medium-emphasis py-6">{{ t('DocumentList.noData') }}</div>
            </template>
        </v-data-table>
    </v-container>
</template>

<script>
import { computed, ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { formatDate } from '@/core/utils/dateFormatter.js'

// Alles, was sich je Listentyp unterscheidet, steht an genau einer Stelle.
const TYPES = {
    invoice:        { title: 'DocumentList.titles.invoice',       icon: 'mdi-file-document-outline',  route: 'faktura-invoice-view' },
    credit_note:    { title: 'DocumentList.titles.creditNote',    icon: 'mdi-file-undo-outline',      route: 'faktura-credit-note-view' },
    order:          { title: 'DocumentList.titles.order',         icon: 'mdi-clipboard-text-outline', route: 'faktura-order-view' },
    quotation:      { title: 'DocumentList.titles.quotation',     icon: 'mdi-file-sign',              route: 'faktura-quotation-view' },
    delivery_order: { title: 'DocumentList.titles.deliveryOrder', icon: 'mdi-truck-outline',          route: 'faktura-delivery-order-view' },
    part:           { title: 'DocumentList.titles.part',          icon: 'mdi-package-variant-closed', route: 'article-edit' },
}

export default {
    name: 'DocumentListView',
    components: { NavbarView },
    setup() {
        const { t, locale } = useI18n()
        const route = useRoute()
        const router = useRouter()

        const listType = computed(() => route.meta?.listType || 'invoice')
        const config = computed(() => TYPES[listType.value] || TYPES.invoice)
        const isParts = computed(() => listType.value === 'part')

        const rows = ref([])
        const loading = ref(false)
        const error = ref('')
        const search = ref('')
        const from = ref('')
        const to = ref('')
        const openOnly = ref(false)
        const withObsolete = ref(false)

        const LIMIT = 500
        const atLimit = computed(() => rows.value.length >= LIMIT)
        const hasFilter = computed(() =>
            !!search.value || !!from.value || !!to.value || openOnly.value || withObsolete.value
        )

        const headers = computed(() => isParts.value
            ? [
                { title: t('DocumentList.columns.partnumber'), key: 'partnumber' },
                { title: t('DocumentList.columns.description'), key: 'description' },
                { title: t('DocumentList.columns.unit'), key: 'unit' },
                { title: t('DocumentList.columns.sellprice'), key: 'sellprice', align: 'end' },
                { title: t('DocumentList.columns.onhand'), key: 'onhand', align: 'end' },
                { title: '', key: 'obsolete', sortable: false },
            ]
            : [
                { title: t('DocumentList.columns.number'), key: 'number' },
                { title: t('DocumentList.columns.date'), key: 'transdate' },
                { title: t('DocumentList.columns.customer'), key: 'customer_name' },
                { title: t('DocumentList.columns.description'), key: 'description' },
                { title: t('DocumentList.columns.amount'), key: 'amount', align: 'end' },
                { title: t('DocumentList.columns.status'), key: 'closed', align: 'center' },
            ]
        )

        // "Nur offene" filtert lokal — die Zeilen sind schon da, ein zweiter
        // Server-Roundtrip waere reine Verschwendung.
        const visibleRows = computed(() =>
            openOnly.value && !isParts.value ? rows.value.filter(r => !r.closed) : rows.value
        )

        function formatCurrency(value) {
            if (value === null || value === undefined || value === '') return ''
            return new Intl.NumberFormat(locale.value, {
                style: 'currency', currency: 'EUR',
            }).format(Number(value))
        }

        // Bestand kommt als numeric(x,5) — ungekuerzt liest sich "0.00000" wie ein Fehler
        function formatQty(value) {
            if (value === null || value === undefined || value === '') return ''
            return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(Number(value))
        }

        async function load() {
            loading.value = true
            error.value = ''
            try {
                const payload = isParts.value
                    ? { action: 'searchParts', q: search.value || '', all: withObsolete.value, limit: LIMIT }
                    : { action: 'searchDocuments', documentType: listType.value, q: search.value || '',
                        from: from.value || '', to: to.value || '', limit: LIMIT }
                const res = await axios.post('/api/faktura/', payload)
                if (res.data?.success) {
                    rows.value = (isParts.value ? res.data.payload.parts : res.data.payload.documents) || []
                } else {
                    rows.value = []
                    error.value = res.data?.payload || t('DocumentList.loadError')
                }
            } catch {
                rows.value = []
                error.value = t('DocumentList.loadError')
            } finally {
                loading.value = false
            }
        }

        // Tippen laedt nach kurzer Pause nach — kein Suchknopf noetig
        let debounce = null
        watch([search, from, to, withObsolete], () => {
            clearTimeout(debounce)
            debounce = setTimeout(load, 350)
        })
        watch(listType, load)
        onMounted(load)

        function reset() {
            search.value = ''
            from.value = ''
            to.value = ''
            openOnly.value = false
            withObsolete.value = false
        }

        function openRow(_event, row) {
            router.push({ name: config.value.route, params: { id: row.item.id } })
        }

        return {
            t, locale, config, isParts, rows, visibleRows, headers, loading, error,
            search, from, to, openOnly, withObsolete, hasFilter, atLimit,
            formatDate, formatCurrency, formatQty, reset, openRow,
        }
    },
}
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
    background-color: rgba(0, 0, 0, 0.03);
}
.zebra-table :deep(tbody tr:hover) {
    cursor: pointer;
}
</style>
