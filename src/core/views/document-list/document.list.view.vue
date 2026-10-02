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
                <!-- In der Artikelansicht steht er zwischen Suchfeld und Filtern -->
                <v-btn v-if="hasFilter && !isParts" variant="text" size="small" prepend-icon="mdi-filter-remove" @click="reset">
                    {{ t('DocumentList.reset') }}
                </v-btn>
                <v-btn
                    v-if="config.create"
                    color="primary"
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-plus"
                    class="ms-2"
                    :to="{ name: config.create.route }"
                >
                    {{ t(config.create.label) }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row dense class="mb-5">
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
            <v-col v-if="isParts && hasFilter" cols="auto" class="d-flex align-center">
                <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="reset">
                    {{ t('DocumentList.reset') }}
                </v-btn>
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
            <!-- Zwei Schalter, ein Zustand (partsScope): es ist immer nur
                 einer an; beide aus heißt „nur aktive Artikel“ -->
            <v-col v-else cols="12" md="6" class="d-flex align-center flex-wrap mt-2">
                <v-switch
                    v-model="obsoleteOnly"
                    :label="t('DocumentList.obsoleteOnly')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <v-switch
                    v-model="showAll"
                    :label="t('DocumentList.showAll')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <!-- Nur mit Shop-Erweiterung; ein Zustand (shopFilter) für beide,
                     keiner zusammen mit „Alle anzeigen“ -->
                <v-switch
                    v-if="shopEnabled"
                    v-model="notInShop"
                    :label="t('DocumentList.notInShop')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <v-switch
                    v-if="shopEnabled"
                    v-model="shopOnly"
                    :label="t('DocumentList.shopOnly')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <!-- Waren ohne Gewicht zum Nachpflegen (Versandkosten im Shop) -->
                <v-switch
                    v-if="shopEnabled"
                    v-model="weightMissing"
                    :label="t('DocumentList.weightMissing')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <!-- Im HugoShop angeboten, aber keine Versandart passt — wird nicht veröffentlicht -->
                <v-switch
                    v-if="shopEnabled"
                    v-model="shippingUnfit"
                    :label="t('DocumentList.shippingUnfit')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
            </v-col>
            <!-- Verfeinert „Nur im Shop angebotene“ auf einen Kanal — deshalb
                 nur sichtbar, solange dieser Filter an ist -->
            <v-col v-if="isParts && shopOnly && channels.length" cols="12" md="3" class="mt-2">
                <v-select
                    v-model="channelId"
                    :items="channelItems"
                    :label="t('DocumentList.channel')"
                    variant="outlined"
                    density="compact"
                    hide-details
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
            <!-- Artikelart aus kivitendo: part, service, assembly, assortment -->
            <template #item.part_type="{ item }">
                {{ te(`DocumentList.partTypes.${item.part_type}`) ? t(`DocumentList.partTypes.${item.part_type}`) : item.part_type }}
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
import { oserpStore } from '@/core/stores/oserp.store.js'

// Alles, was sich je Listentyp unterscheidet, steht an genau einer Stelle.
// create: Schaltfläche zum Anlegen — nur wo es eine eigene Neuanlage gibt.
const TYPES = {
    invoice:        { title: 'DocumentList.titles.invoice',       icon: 'mdi-file-document-outline',  route: 'faktura-invoice-view' },
    credit_note:    { title: 'DocumentList.titles.creditNote',    icon: 'mdi-file-undo-outline',      route: 'faktura-credit-note-view' },
    order:          { title: 'DocumentList.titles.order',         icon: 'mdi-clipboard-text-outline', route: 'faktura-order-view' },
    quotation:      { title: 'DocumentList.titles.quotation',     icon: 'mdi-file-sign',              route: 'faktura-quotation-view' },
    delivery_order: { title: 'DocumentList.titles.deliveryOrder', icon: 'mdi-truck-outline',          route: 'faktura-delivery-order-view' },
    part:           { title: 'DocumentList.titles.part',          icon: 'mdi-package-variant-closed', route: 'article-edit',
                      create: { route: 'article-new', label: 'DocumentList.newPart' } },
}

export default {
    name: 'DocumentListView',
    components: { NavbarView },
    setup() {
        const { t, te, locale } = useI18n()
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
        // Welche Artikel: 'active' (Vorgabe), 'obsolete' oder 'all'
        const partsScope = ref('active')
        const obsoleteOnly = computed({
            get: () => partsScope.value === 'obsolete',
            set: (an) => { partsScope.value = an ? 'obsolete' : 'active' },
        })
        const showAll = computed({
            get: () => partsScope.value === 'all',
            set: (an) => {
                partsScope.value = an ? 'all' : 'active'
                if (an) {
                    shopFilter.value = ''
                    channelIdState.value = 0
                }
            },
        })

        // Im Shop angeboten oder nicht — zusätzlich zu aktiv/ausgemustert,
        // nicht zusammen mit „Alle anzeigen“. '' = egal, 'offered', 'not_offered'
        const shopEnabled = computed(() => oserpStore().isExtensionEnabled('shop'))
        // Vorbelegung aus der URL (?shop=offered): so öffnet die Übersicht des
        // Shops die Liste gleich gefiltert
        const shopFilterState = ref(['offered', 'not_offered'].includes(route.query.shop) ? route.query.shop : '')
        // Nur Waren ohne Gewicht (?weight=missing) — zum Nachpflegen für den Versand
        const weightMissingState = ref(route.query.weight === 'missing')
        // Ohne passende Versandart (?shipping=unfit) — diese Seiten werden nicht veröffentlicht
        const shippingUnfitState = ref(route.query.shipping === 'unfit')
        const shopFilter = computed({
            get: () => (shopEnabled.value ? shopFilterState.value : ''),
            set: (wert) => {
                shopFilterState.value = wert
                if (wert && partsScope.value === 'all') partsScope.value = 'active'
                // Die Kanalauswahl gehört zu „angeboten“ — sonst wirkte eine unsichtbare Auswahl
                if (wert !== 'offered') channelIdState.value = 0
            },
        })
        const weightMissing = computed({
            get: () => shopEnabled.value && weightMissingState.value,
            set: (an) => { weightMissingState.value = an },
        })
        const shippingUnfit = computed({
            get: () => shopEnabled.value && shippingUnfitState.value,
            set: (an) => { shippingUnfitState.value = an },
        })
        const shopOnly = computed({
            get: () => shopFilter.value === 'offered',
            set: (an) => { shopFilter.value = an ? 'offered' : '' },
        })
        const notInShop = computed({
            get: () => shopFilter.value === 'not_offered',
            set: (an) => { shopFilter.value = an ? 'not_offered' : '' },
        })

        // Kanal innerhalb von „Nur im Shop angebotene“: 0 = alle Kanäle. Die
        // Kanäle kommen einmal beim Öffnen, nur bei aktiver Shop-Erweiterung
        const channels = ref([])
        const channelIdState = ref(0)
        const channelId = computed({
            get: () => (shopOnly.value ? channelIdState.value : 0),
            set: (id) => { channelIdState.value = Number(id) || 0 },
        })
        const channelItems = computed(() => [
            { value: 0, title: t('DocumentList.allChannels') },
            ...channels.value.map(kanal => ({
                value: Number(kanal.channel_id),
                // Name des Kanals (mehrere je Art), sonst die Bezeichnung der Art
                title: kanal.name || (te(`ShopView.channels.${kanal.type}`) ? t(`ShopView.channels.${kanal.type}`) : kanal.type),
            })),
        ])

        async function loadChannels() {
            if (!shopEnabled.value) return
            try {
                const res = await axios.post('/api/faktura/', { action: 'getPartsSalesChannels' })
                channels.value = res.data?.success ? (res.data.payload.channels || []) : []
            } catch {
                channels.value = []
            }
        }

        const LIMIT = 500
        const atLimit = computed(() => rows.value.length >= LIMIT)
        const hasFilter = computed(() =>
            !!search.value || !!from.value || !!to.value || openOnly.value || partsScope.value !== 'active' || !!shopFilter.value
            || weightMissing.value || shippingUnfit.value
        )

        const headers = computed(() => isParts.value
            ? [
                { title: t('DocumentList.columns.partnumber'), key: 'partnumber' },
                { title: t('DocumentList.columns.description'), key: 'description' },
                { title: t('DocumentList.columns.partType'), key: 'part_type' },
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
                    ? { action: 'searchParts', q: search.value || '', scope: partsScope.value, shop: shopFilter.value, weight_missing: weightMissing.value,
                        shipping_unfit: shippingUnfit.value, channel_id: channelId.value, limit: LIMIT }
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
        watch([search, from, to, partsScope, shopFilter, channelId, weightMissing, shippingUnfit], () => {
            clearTimeout(debounce)
            debounce = setTimeout(load, 350)
        })
        watch(listType, load)
        onMounted(() => {
            load()
            loadChannels()
        })

        function reset() {
            search.value = ''
            from.value = ''
            to.value = ''
            openOnly.value = false
            partsScope.value = 'active'
            shopFilterState.value = ''
            channelIdState.value = 0
            weightMissingState.value = false
            shippingUnfitState.value = false
        }

        function openRow(_event, row) {
            router.push({ name: config.value.route, params: { id: row.item.id } })
        }

        return {
            t, te, locale, config, isParts, rows, visibleRows, headers, loading, error,
            search, from, to, openOnly, obsoleteOnly, showAll, shopOnly, notInShop, weightMissing, shippingUnfit, shopEnabled, channels, channelId, channelItems, hasFilter, atLimit,
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
