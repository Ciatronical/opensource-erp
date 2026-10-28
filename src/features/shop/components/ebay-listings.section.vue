<!-- src/features/shop/components/ebay-listings.section.vue -->
<!--
    Angebote bei eBay (dev/shop-ebay.md, Teil Angebote): alles, was im Konto
    läuft — auch im Seller Hub eingestellt —, mit der Zuordnung zum Artikel.

    Filter-Chips führen zu dem, was zu tun ist: ohne Zuordnung, mit Vorschlag,
    mehr bei eBay als im Lager (Überverkauf), Menge oder Preis weichen ab.
    Zuordnen per Vorschlag (✓/✗) oder Artikelsuche in der Zeile; was es im
    ERP noch nicht gibt, wird „Als Artikel angelegt“ — mit Bild und auf
    Wunsch mit der eBay-Menge als Bestand.
-->
<template>
    <section-card :title="t('ShopView.ebay.listings.title')" :subtitle="untertitel" icon="mdi-format-list-bulleted-square" plain>
        <template #actions>
            <v-btn
                v-if="darfBearbeiten && auswahlVorschlaege.length"
                size="small"
                variant="tonal"
                color="success"
                prepend-icon="mdi-check-all"
                :loading="arbeitet === 'accept'"
                @click="vorschlaegeUebernehmen"
            >
                {{ t('ShopView.ebay.listings.acceptSuggestions', { anzahl: auswahlVorschlaege.length }) }}
            </v-btn>
            <v-btn
                v-if="darfBearbeiten && auswahlOhne.length"
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="mdi-package-variant-plus"
                @click="anlegenDialog = true"
            >
                {{ t('ShopView.ebay.listings.createParts', { anzahl: auswahlOhne.length }) }}
            </v-btn>
            <v-btn size="small" variant="text" prepend-icon="mdi-cloud-download-outline" :loading="arbeitet === 'sync'" @click="lesen">
                {{ t('ShopView.ebay.listings.sync') }}
            </v-btn>
        </template>

        <div class="d-flex flex-wrap align-center ga-2 pa-3">
            <v-chip-group v-model="filter" mandatory selected-class="text-primary" class="flex-grow-1">
                <v-chip v-for="f in filterListe" :key="f.key" :value="f.key" size="small" variant="outlined" filter>
                    {{ f.text }}
                    <v-badge v-if="f.anzahl" :content="f.anzahl" :color="f.color || 'grey'" inline />
                </v-chip>
            </v-chip-group>
            <v-text-field
                v-model="suche"
                density="compact"
                variant="outlined"
                hide-details
                clearable
                prepend-inner-icon="mdi-magnify"
                :placeholder="t('ShopView.ebay.listings.search')"
                style="max-width: 280px"
            />
        </div>

        <v-data-table
            v-model="gewaehlt"
            :headers="spalten"
            :items="gefiltert"
            item-value="id"
            :show-select="darfBearbeiten"
            density="compact"
            :items-per-page="50"
            :loading="laedt"
            :no-data-text="angebote.length ? t('ShopView.ebay.listings.noneInFilter') : t('ShopView.ebay.listings.empty')"
        >
            <template #item.image_url="{ item }">
                <v-avatar size="40" rounded="sm" class="my-1">
                    <v-img v-if="item.image_url" :src="item.image_url" cover />
                    <v-icon v-else color="grey">mdi-image-off-outline</v-icon>
                </v-avatar>
            </template>
            <template #item.title="{ item }">
                <div class="titel">
                    <a v-if="item.view_url" :href="item.view_url" target="_blank" rel="noopener" class="text-decoration-none">{{ item.title }}</a>
                    <span v-else>{{ item.title }}</span>
                    <div class="text-caption text-medium-emphasis">
                        {{ item.item_id }}<span v-if="item.variation_text"> · {{ item.variation_text }}</span><span v-if="item.sku"> · SKU {{ item.sku }}</span>
                        <v-chip v-if="item.status === 'ended'" size="x-small" variant="tonal" class="ml-1">{{ t('ShopView.ebay.listings.ended') }}</v-chip>
                        <span v-if="item.watch_count" class="ml-1"><v-icon size="x-small">mdi-eye-outline</v-icon> {{ item.watch_count }}</span>
                    </div>
                    <div v-if="item.sync_error" class="text-caption text-error">{{ item.sync_error }}</div>
                </div>
            </template>
            <template #item.quantity="{ item }">
                <div class="text-no-wrap">
                    <span :class="{ 'text-error font-weight-medium': item.oversell }">{{ item.quantity }}</span>
                    <span v-if="item.parts_id" class="text-medium-emphasis"> / {{ zahl(item.oserp_quantity, 0) }}</span>
                    <v-icon v-if="item.oversell" size="small" color="error" class="ml-1" :title="t('ShopView.ebay.listings.oversellHint')">mdi-alert</v-icon>
                    <v-icon v-else-if="item.stock_diff" size="small" color="warning" class="ml-1" :title="t('ShopView.ebay.listings.stockDiffHint')">mdi-swap-vertical</v-icon>
                </div>
                <div v-if="item.lot_size > 1" class="text-caption text-medium-emphasis">{{ t('ShopView.ebay.listings.lot', { anzahl: item.lot_size }) }}</div>
            </template>
            <template #item.price="{ item }">
                <div class="text-no-wrap">{{ geld(item.price) }}</div>
                <div v-if="item.price_diff" class="text-caption text-warning text-no-wrap" :title="t('ShopView.ebay.listings.priceDiffHint')">
                    OSERP {{ geld(item.oserp_price) }}
                </div>
            </template>
            <template #item.parts_id="{ item }">
                <!-- Zugeordnet -->
                <div v-if="item.parts_id" class="d-flex align-center ga-1">
                    <v-chip size="small" variant="tonal" :color="item.link_source === 'inventory' ? 'primary' : 'success'" :to="entityRoute('article', item.parts_id)">
                        {{ item.partnumber }}
                    </v-chip>
                    <span class="text-caption text-truncate artikel">{{ item.description }}</span>
                    <v-btn v-if="darfBearbeiten" icon="mdi-palette-outline" size="x-small" variant="text" :title="t('ShopView.ebay.listings.designPage')" @click="emit('seite', item.parts_id)" />
                    <v-btn v-if="darfBearbeiten && item.link_source !== 'inventory'" icon="mdi-link-variant-off" size="x-small" variant="text" :title="t('ShopView.ebay.listings.unlink')" @click="loesen(item, false)" />
                </div>
                <!-- Vorschlag -->
                <div v-else-if="item.suggestion_parts_id" class="d-flex align-center ga-1">
                    <v-chip size="small" variant="outlined" color="warning" prepend-icon="mdi-lightbulb-on-outline">
                        {{ item.suggestion_partnumber }} · {{ Math.round(item.suggestion_score) }} %
                    </v-chip>
                    <span class="text-caption text-truncate artikel">{{ item.suggestion_description }}</span>
                    <template v-if="darfBearbeiten">
                        <v-btn icon="mdi-check" size="x-small" variant="tonal" color="success" :title="t('ShopView.ebay.listings.accept')" @click="zuordnen(item, item.suggestion_parts_id)" />
                        <v-btn icon="mdi-close" size="x-small" variant="text" :title="t('ShopView.ebay.listings.dismiss')" @click="loesen(item, true)" />
                    </template>
                </div>
                <!-- Ohne: Artikel suchen -->
                <v-autocomplete
                    v-else-if="darfBearbeiten"
                    :items="trefferFuer(item)"
                    item-title="label"
                    item-value="parts_id"
                    density="compact"
                    variant="outlined"
                    hide-details
                    no-filter
                    :placeholder="t('ShopView.ebay.listings.searchPart')"
                    class="suche"
                    @update:search="text => artikelSuchen(item, text)"
                    @update:model-value="id => id && zuordnen(item, id)"
                />
            </template>
        </v-data-table>

        <!-- Als Artikel anlegen -->
        <v-dialog v-model="anlegenDialog" max-width="520">
            <v-card>
                <v-card-title>{{ t('ShopView.ebay.listings.createTitle', { anzahl: auswahlOhne.length }) }}</v-card-title>
                <v-card-text>
                    <p class="text-body-2 mb-3">{{ t('ShopView.ebay.listings.createText') }}</p>
                    <v-checkbox v-model="bestandUebernehmen" :label="t('ShopView.ebay.listings.takeStock')" hide-details density="compact" />
                    <div class="text-caption text-medium-emphasis ml-8">{{ t('ShopView.ebay.listings.takeStockHint') }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="anlegenDialog = false">{{ t('ShopView.common.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="arbeitet === 'create'" @click="anlegen">{{ t('ShopView.ebay.listings.createConfirm') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </section-card>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import { useShop } from '@/features/shop/composables/useShop.js'
import { entityRoute } from '@/core/constants/routes.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    channelId: { type: Number, required: true },
    darfBearbeiten: { type: Boolean, default: false },
    /** Letzter Abgleich (board.channel.listings_last_sync) */
    lastSync: { type: String, default: '' },
    /** Filter von außen (Kennzahl angeklickt): unlinked, suggested, oversell … */
    startFilter: { type: String, default: '' },
    /** Suche von außen: eBay-Artikelnummer aus der Prüfung */
    startSuche: { type: String, default: '' },
})
const emit = defineEmits(['geaendert', 'seite'])

const { t, locale } = useI18n()
const shop = useShop()

const angebote = ref([])
const laedt = ref(false)
const arbeitet = ref('')
const filter = ref(props.startFilter || 'todo')
const suche = ref(props.startSuche || '')
const gewaehlt = ref([])
const anlegenDialog = ref(false)
const bestandUebernehmen = ref(true)
const treffer = ref({})

const zahl = (w, stellen = 2) => formatNumber(w ?? 0, locale.value, stellen)
const geld = w => (w === null || w === undefined ? '—' : `${formatNumber(w, locale.value, 2)} €`)
const untertitel = computed(() => (props.lastSync
    ? t('ShopView.ebay.listings.lastSync', { zeit: new Date(String(props.lastSync).replace(' ', 'T')).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) })
    : t('ShopView.ebay.listings.neverSynced')))

const aktive = computed(() => angebote.value.filter(a => a.status === 'active'))
const FILTER = {
    todo: a => a.status === 'active' && (!a.parts_id || a.oversell),
    all: a => a.status === 'active',
    unlinked: a => a.status === 'active' && !a.parts_id && !a.suggestion_parts_id,
    suggested: a => a.status === 'active' && !a.parts_id && !!a.suggestion_parts_id,
    linked: a => a.status === 'active' && !!a.parts_id,
    oversell: a => a.oversell,
    stock: a => a.stock_diff,
    price: a => a.price_diff,
    ended: a => a.status === 'ended',
}
const filterListe = computed(() => [
    { key: 'todo', text: t('ShopView.ebay.listings.filter.todo'), color: 'warning' },
    { key: 'all', text: t('ShopView.ebay.listings.filter.all') },
    { key: 'unlinked', text: t('ShopView.ebay.listings.filter.unlinked'), color: 'warning' },
    { key: 'suggested', text: t('ShopView.ebay.listings.filter.suggested'), color: 'warning' },
    { key: 'linked', text: t('ShopView.ebay.listings.filter.linked'), color: 'success' },
    { key: 'oversell', text: t('ShopView.ebay.listings.filter.oversell'), color: 'error' },
    { key: 'stock', text: t('ShopView.ebay.listings.filter.stock') },
    { key: 'price', text: t('ShopView.ebay.listings.filter.price') },
    { key: 'ended', text: t('ShopView.ebay.listings.filter.ended') },
].map(f => ({ ...f, anzahl: angebote.value.filter(FILTER[f.key]).length })))

/** Volltext, Begriffe UND-verknüpft: Titel, Nummern, SKU, Artikel */
const gefiltert = computed(() => {
    const begriffe = (suche.value || '').toLowerCase().split(/\s+/).filter(Boolean)
    return angebote.value.filter(FILTER[filter.value] || FILTER.all).filter(a => {
        if (!begriffe.length) return true
        const text = [a.title, a.item_id, a.sku, a.variation_sku, a.partnumber, a.description, a.ean].join(' ').toLowerCase()
        return begriffe.every(b => text.includes(b))
    })
})
const auswahl = computed(() => angebote.value.filter(a => gewaehlt.value.includes(a.id)))
const auswahlVorschlaege = computed(() => auswahl.value.filter(a => !a.parts_id && a.suggestion_parts_id))
const auswahlOhne = computed(() => auswahl.value.filter(a => !a.parts_id))

const spalten = computed(() => [
    { title: '', key: 'image_url', sortable: false, width: 56 },
    { title: t('ShopView.ebay.listings.col.title'), key: 'title' },
    { title: t('ShopView.ebay.listings.col.quantity'), key: 'quantity', align: 'end' },
    { title: t('ShopView.ebay.listings.col.price'), key: 'price', align: 'end' },
    { title: t('ShopView.ebay.listings.col.part'), key: 'parts_id', sortable: false, width: 380 },
])

async function laden() {
    laedt.value = true
    const antwort = await shop.fetchEbayListings(props.channelId)
    laedt.value = false
    if (!antwort) {
        toasts.error(shop.error.value)
        return
    }
    angebote.value = antwort.listings || []
    gewaehlt.value = gewaehlt.value.filter(id => angebote.value.some(a => a.id === id))
    // Nichts zu tun: gleich alle zeigen statt einer leeren Liste
    if (filter.value === 'todo' && !angebote.value.some(FILTER.todo)) filter.value = 'all'
}

async function lesen() {
    arbeitet.value = 'sync'
    const antwort = await shop.syncEbay(props.channelId, 'listings')
    arbeitet.value = ''
    if (!antwort) {
        toasts.error(shop.error.value, { timer: 10000 })
        return
    }
    toasts.success(t('ShopView.ebay.listings.synced', antwort.listings || {}))
    await laden()
    emit('geaendert')
}

async function zuordnen(angebot, partsId) {
    const antwort = await shop.linkEbayListing(angebot.id, partsId)
    if (antwort === null) {
        toasts.error(shop.error.value)
        return
    }
    toasts.success(t('ShopView.ebay.listings.linked'), { timer: 1500 })
    await laden()
    emit('geaendert')
}

async function loesen(angebot, ablehnen) {
    const antwort = await shop.unlinkEbayListing(angebot.id, ablehnen)
    if (antwort === null) return
    await laden()
    emit('geaendert')
}

async function vorschlaegeUebernehmen() {
    arbeitet.value = 'accept'
    const antwort = await shop.acceptEbayListingSuggestions(props.channelId, auswahlVorschlaege.value.map(a => a.id))
    arbeitet.value = ''
    if (!antwort) return
    toasts.success(t('ShopView.ebay.listings.acceptedCount', { anzahl: antwort.linked }))
    gewaehlt.value = []
    await laden()
    emit('geaendert')
}

async function anlegen() {
    arbeitet.value = 'create'
    const antwort = await shop.createEbayListingParts(props.channelId, auswahlOhne.value.map(a => a.id), bestandUebernehmen.value)
    arbeitet.value = ''
    if (!antwort) {
        toasts.error(shop.error.value)
        return
    }
    anlegenDialog.value = false
    gewaehlt.value = []
    toasts.success(t('ShopView.ebay.listings.created', { anzahl: antwort.created }))
    if (bestandUebernehmen.value && !antwort.stock_bin) toasts.warning(t('ShopView.ebay.listings.noStockBin'), { timer: 10000 })
    if (antwort.errors?.length) toasts.error(antwort.errors.join('\n'), { timer: 12000 })
    await laden()
    emit('geaendert')
}

// Artikelsuche je Zeile
const suchUhren = {}
const trefferFuer = angebot => treffer.value[angebot.id] || []
function artikelSuchen(angebot, text) {
    clearTimeout(suchUhren[angebot.id])
    if (!text || text.length < 2) return
    suchUhren[angebot.id] = setTimeout(async () => {
        const antwort = await shop.searchEbayParts(text)
        treffer.value = {
            ...treffer.value,
            [angebot.id]: (antwort?.results || []).map(p => ({ ...p, label: `${p.partnumber} — ${p.description}` })),
        }
    }, 250)
}

watch(() => props.channelId, laden, { immediate: true })
watch(() => props.startFilter, f => { if (f) filter.value = f })
watch(() => props.startSuche, s => { if (s) { suche.value = s; filter.value = 'all' } })

defineExpose({ laden })
</script>

<style scoped>
.titel { max-width: 520px; padding: 4px 0; }
.artikel { max-width: 160px; }
.suche { min-width: 280px; }
</style>
