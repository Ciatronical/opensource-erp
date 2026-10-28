<!-- src/features/shop/components/ebay-review.card.vue -->
<!--
    Ein eBay-Verkauf in der Prüfung (dev/shop-ebay.md): links der Käufer, wie
    eBay ihn meldet, rechts die Kunden des Stamms, die es sein könnten — mit
    Punkten und den Gründen (eBay-Name bekannt, E-Mail, Telefon, Name,
    Straße, PLZ). Vorgewählt ist der Vorschlag des Abgleichs, sonst „neuer
    Kunde“. Eine Entscheidung legt sofort die Rechnung an.

    Tastatur, solange die Karte den Fokus hat: 1–5 Kandidat, N neuer Kunde,
    Enter Rechnung erstellen, I zurückstellen. Die Ansicht springt danach
    zur nächsten Karte.
-->
<template>
    <v-card
        ref="karte"
        variant="outlined"
        class="pruefung"
        :class="{ 'pruefung--fehler': order.error }"
        tabindex="0"
        @keydown="taste"
    >
        <v-card-item class="pb-1">
            <div class="d-flex flex-wrap align-center ga-2">
                <v-chip size="small" :color="grundFarbe" variant="tonal" :prepend-icon="grundIcon">{{ grundText }}</v-chip>
                <a :href="ebayBestellung" target="_blank" rel="noopener" class="text-body-2 text-decoration-none">
                    {{ order.ebay_order_id }} <v-icon size="x-small">mdi-open-in-new</v-icon>
                </a>
                <span class="text-caption text-medium-emphasis">{{ zeit(order.ordered_at) }}</span>
                <v-spacer />
                <span class="text-subtitle-1 font-weight-medium">{{ geld(order.total) }}</span>
            </div>
        </v-card-item>

        <v-card-text class="pt-1">
            <v-alert v-if="order.error" type="error" variant="tonal" density="compact" class="mb-3">{{ order.error }}</v-alert>

            <!-- Positionen mit ihrem Artikel -->
            <div class="positionen mb-3">
                <div v-for="(pos, i) in order.items" :key="i" class="d-flex align-center ga-2 py-1">
                    <v-avatar v-if="pos.image_url" size="32" rounded="sm"><v-img :src="pos.image_url" cover /></v-avatar>
                    <v-icon v-else size="20" color="grey">mdi-package-variant</v-icon>
                    <span class="text-body-2">{{ pos.quantity }} ×</span>
                    <span class="text-body-2 text-truncate">{{ pos.title }}</span>
                    <v-icon size="small" color="grey">mdi-arrow-right</v-icon>
                    <router-link v-if="pos.parts_id && pos.source !== 'collective'" :to="entityRoute('article', pos.parts_id)" class="text-body-2 text-decoration-none text-no-wrap">
                        {{ pos.partnumber }}
                    </router-link>
                    <v-chip v-else-if="pos.source === 'collective'" size="x-small" variant="tonal">{{ t('ShopView.ebay.review.collectivePart') }}</v-chip>
                    <v-btn v-else size="x-small" variant="tonal" color="warning" prepend-icon="mdi-link-variant" @click="emit('zuordnen', pos.item_id)">
                        {{ t('ShopView.ebay.review.linkListing') }}
                    </v-btn>
                </div>
            </div>

            <v-row dense>
                <!-- Käufer bei eBay -->
                <v-col cols="12" md="5">
                    <div class="text-overline text-medium-emphasis">{{ t('ShopView.ebay.review.buyer') }}</div>
                    <div class="kaeufer pa-3">
                        <div class="font-weight-medium">{{ buyer.company || buyer.name || buyer.username }}</div>
                        <div v-if="buyer.company && buyer.name">{{ buyer.name }}</div>
                        <div>{{ buyer.street }}</div>
                        <div>{{ [buyer.zipcode, buyer.city].filter(Boolean).join(' ') }} <span v-if="buyer.country && buyer.country !== 'DE'" class="text-medium-emphasis">({{ buyer.country }})</span></div>
                        <div class="d-flex flex-wrap ga-1 mt-2">
                            <v-chip size="x-small" variant="tonal" prepend-icon="mdi-account">{{ buyer.username }}</v-chip>
                            <v-chip v-if="buyer.email" size="x-small" variant="tonal" prepend-icon="mdi-email-outline">{{ buyer.email }}</v-chip>
                            <v-chip v-if="buyer.phone" size="x-small" variant="tonal" prepend-icon="mdi-phone">{{ buyer.phone }}</v-chip>
                        </div>
                        <div v-if="lieferungAnders" class="text-caption text-medium-emphasis mt-2">
                            <v-icon size="x-small">mdi-truck-outline</v-icon>
                            {{ t('ShopView.ebay.review.shipTo') }}: {{ [buyer.ship.name, buyer.ship.street, [buyer.ship.zipcode, buyer.ship.city].join(' ')].filter(Boolean).join(', ') }}
                        </div>
                        <div v-if="!buyer.registered" class="text-caption text-medium-emphasis mt-1">{{ t('ShopView.ebay.review.onlyShipTo') }}</div>
                    </div>
                </v-col>

                <!-- Kunde im Stamm -->
                <v-col cols="12" md="7">
                    <div class="text-overline text-medium-emphasis">{{ t('ShopView.ebay.review.customer') }}</div>
                    <v-radio-group v-model="wahl" hide-details density="compact" class="mt-0">
                        <div
                            v-for="(kandidat, i) in kandidaten"
                            :key="kandidat.customer_id"
                            class="kandidat pa-2 mb-1"
                            :class="{ 'kandidat--gewaehlt': wahl === kandidat.customer_id }"
                            @click="wahl = kandidat.customer_id"
                        >
                            <div class="d-flex align-start ga-2">
                                <v-radio :value="kandidat.customer_id" density="compact" class="flex-grow-0" />
                                <div class="flex-grow-1" style="min-width: 0">
                                    <div class="d-flex align-center ga-2">
                                        <span class="taste">{{ i + 1 }}</span>
                                        <span class="font-weight-medium" :class="farbe(kandidat.hits.name)">{{ kandidat.name }}</span>
                                        <span class="text-caption text-medium-emphasis">{{ kandidat.customernumber }}</span>
                                        <v-spacer />
                                        <span class="text-caption font-weight-medium">{{ kandidat.score }}</span>
                                    </div>
                                    <v-progress-linear :model-value="kandidat.score" :color="punkteFarbe(kandidat.score)" height="3" rounded class="my-1" />
                                    <div class="text-body-2">
                                        <span :class="farbe(kandidat.hits.street)">{{ kandidat.street }}</span>,
                                        <span :class="farbe(kandidat.hits.zipcode ? 'same' : null)">{{ kandidat.zipcode }}</span> {{ kandidat.city }}
                                    </div>
                                    <div class="d-flex flex-wrap ga-1 mt-1">
                                        <v-chip v-for="grund in gruende(kandidat)" :key="grund.key" size="x-small" :color="grund.color" variant="tonal" :prepend-icon="grund.icon">
                                            {{ grund.text }}
                                        </v-chip>
                                        <router-link :to="entityRoute('customer', kandidat.customer_id)" target="_blank" class="text-caption ml-1" @click.stop>
                                            {{ t('ShopView.ebay.review.openCustomer') }}
                                        </router-link>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Anderer Kunde: Suche -->
                        <div v-if="anderer" class="kandidat kandidat--gewaehlt pa-2 mb-1">
                            <div class="d-flex align-center ga-2">
                                <v-radio :value="anderer.customer_id" density="compact" class="flex-grow-0" />
                                <span class="font-weight-medium">{{ anderer.name }}</span>
                                <span class="text-caption text-medium-emphasis">{{ anderer.customernumber }} · {{ [anderer.street, anderer.zipcode, anderer.city].filter(Boolean).join(' ') }}</span>
                            </div>
                        </div>

                        <div class="kandidat pa-2 mb-1" :class="{ 'kandidat--gewaehlt': wahl === 0 }" @click="wahl = 0">
                            <div class="d-flex align-center ga-2">
                                <v-radio :value="0" density="compact" class="flex-grow-0" />
                                <span class="taste">N</span>
                                <v-icon size="small" color="primary">mdi-account-plus-outline</v-icon>
                                <span class="font-weight-medium">{{ t('ShopView.ebay.review.newCustomer') }}</span>
                                <span class="text-caption text-medium-emphasis">{{ t('ShopView.ebay.review.newCustomerHint') }}</span>
                            </div>
                        </div>
                    </v-radio-group>

                    <v-autocomplete
                        v-model="suche"
                        v-model:search="suchtext"
                        :items="suchtreffer"
                        :loading="sucht"
                        item-value="customer_id"
                        item-title="name"
                        return-object
                        no-filter
                        clearable
                        density="compact"
                        variant="outlined"
                        hide-details
                        prepend-inner-icon="mdi-account-search-outline"
                        :label="t('ShopView.ebay.review.otherCustomer')"
                        :no-data-text="suchtext?.length >= 2 ? t('ShopView.ebay.review.noCustomerFound') : t('ShopView.ebay.review.typeToSearch')"
                        class="mt-2"
                        @update:model-value="andererGewaehlt"
                    >
                        <template #item="{ props: eintrag, item }">
                            <v-list-item v-bind="eintrag" :subtitle="`${item.raw.customernumber} · ${[item.raw.street, item.raw.zipcode, item.raw.city].filter(Boolean).join(' ')}`" />
                        </template>
                    </v-autocomplete>

                    <v-checkbox
                        v-if="wahl && anschriftAnders"
                        v-model="anschriftUebernehmen"
                        density="compact"
                        hide-details
                        class="mt-1"
                        :label="t('ShopView.ebay.review.updateAddress')"
                    />
                </v-col>
            </v-row>
        </v-card-text>

        <v-card-actions class="px-4 pb-3">
            <span class="text-caption text-medium-emphasis d-none d-md-inline">{{ t('ShopView.ebay.review.keys') }}</span>
            <v-spacer />
            <v-btn variant="text" prepend-icon="mdi-archive-arrow-down-outline" :disabled="busy" @click="emit('zurueckstellen')">
                {{ t('ShopView.ebay.review.ignore') }}
            </v-btn>
            <v-btn color="primary" variant="flat" prepend-icon="mdi-receipt-text-check-outline" :loading="busy" :disabled="!positionenKlar" @click="entscheiden">
                {{ wahl ? t('ShopView.ebay.review.invoiceFor', { name: gewaehlterName }) : t('ShopView.ebay.review.invoiceNew') }}
            </v-btn>
        </v-card-actions>
    </v-card>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShop } from '@/features/shop/composables/useShop.js'
import { entityRoute } from '@/core/constants/routes.js'
import { formatNumber } from '@/core/utils/numberFormat.js'

const props = defineProps({
    /** Eintrag aus board.review: id, ebay_order_id, buyer, match, items, error */
    order: { type: Object, required: true },
    busy: { type: Boolean, default: false },
})
const emit = defineEmits(['entscheiden', 'zurueckstellen', 'zuordnen'])

const { t, locale } = useI18n()
const shop = useShop()
const karte = ref(null)

const buyer = computed(() => props.order.buyer || props.order.match?.buyer || {})
const kandidaten = computed(() => props.order.match?.candidates || [])
const grund = computed(() => (props.order.error ? 'error' : props.order.match?.reason || 'uncertain'))
const grundText = computed(() => t(`ShopView.ebay.review.reason.${grund.value}`))
const grundFarbe = computed(() => ({ error: 'error', address_changed: 'info', several: 'warning' }[grund.value] || 'warning'))
const grundIcon = computed(() => ({ error: 'mdi-alert-circle-outline', address_changed: 'mdi-home-export-outline', several: 'mdi-account-multiple-outline' }[grund.value] || 'mdi-help-circle-outline'))
const ebayBestellung = computed(() => `https://www.ebay.de/sh/ord/details?orderid=${encodeURIComponent(props.order.ebay_order_id)}`)
const positionenKlar = computed(() => (props.order.items || []).every(p => p.parts_id))
const lieferungAnders = computed(() => {
    const s = buyer.value.ship || {}
    return s.street && (s.street !== buyer.value.street || s.zipcode !== buyer.value.zipcode || s.name !== buyer.value.name)
})

// Vorwahl: Entscheidung von früher, Vorschlag des Abgleichs, sonst neuer Kunde
const wahl = ref(props.order.customer_id || props.order.match?.customer_id || (props.order.match?.state === 'new' ? 0 : kandidaten.value[0]?.customer_id) || 0)
const anderer = ref(null)
const gewaehlt = computed(() => kandidaten.value.find(k => k.customer_id === wahl.value) || (anderer.value?.customer_id === wahl.value ? anderer.value : null))
const gewaehlterName = computed(() => gewaehlt.value?.name || '')
const anschriftAnders = computed(() => {
    const k = gewaehlt.value
    if (!k || !buyer.value.registered) return false
    if (k.hits) return k.hits.street !== 'same' || !k.hits.zipcode
    return (k.zipcode || '') !== (buyer.value.zipcode || '')
})
const anschriftUebernehmen = ref(props.order.match?.reason === 'address_changed')

// Suche nach einem anderen Kunden
const suche = ref(null)
const suchtext = ref('')
const suchtreffer = ref([])
const sucht = ref(false)
let suchUhr = null
watch(suchtext, text => {
    clearTimeout(suchUhr)
    if (!text || text.length < 2 || text === suche.value?.name) return
    suchUhr = setTimeout(async () => {
        sucht.value = true
        const antwort = await shop.searchEbayCustomers(text)
        sucht.value = false
        suchtreffer.value = antwort?.results || []
    }, 250)
})
function andererGewaehlt(kunde) {
    if (!kunde) return
    anderer.value = kandidaten.value.some(k => k.customer_id === kunde.customer_id) ? null : kunde
    wahl.value = kunde.customer_id
}

const zeit = w => (w ? new Date(String(w).replace(' ', 'T') + (String(w).includes('Z') ? '' : 'Z')).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '')
const geld = w => `${formatNumber(w ?? 0, locale.value, 2)} €`
const punkteFarbe = p => (p >= 85 ? 'success' : p >= 60 ? 'warning' : 'grey')
/** same = passt, similar/weak = ähnlich, sonst weicht ab */
const farbe = treffer => (treffer === 'same' ? 'text-success' : treffer === 'similar' || treffer === 'weak' ? 'text-warning' : 'text-error')

function gruende(k) {
    const h = k.hits || {}
    return [
        h.known && { key: 'known', text: t('ShopView.ebay.review.hits.known', { anzahl: k.ebay_orders }), color: 'success', icon: 'mdi-account-check' },
        h.email && { key: 'email', text: t('ShopView.ebay.review.hits.email'), color: 'success', icon: 'mdi-email-check-outline' },
        h.phone && { key: 'phone', text: t('ShopView.ebay.review.hits.phone'), color: 'success', icon: 'mdi-phone-check' },
        h.name && { key: 'name', text: t(`ShopView.ebay.review.hits.name.${h.name}`), color: h.name === 'same' ? 'success' : 'warning', icon: 'mdi-card-account-details-outline' },
        h.street && { key: 'street', text: t(`ShopView.ebay.review.hits.street.${h.street}`), color: h.street === 'same' ? 'success' : 'warning', icon: 'mdi-home-outline' },
        k.address_changed && { key: 'moved', text: t('ShopView.ebay.review.hits.moved'), color: 'info', icon: 'mdi-home-export-outline' },
        k.invoices > 0 && { key: 'invoices', text: t('ShopView.ebay.review.hits.invoices', { anzahl: k.invoices }, k.invoices), color: undefined, icon: 'mdi-receipt-text-outline' },
    ].filter(Boolean)
}

function entscheiden() {
    if (!positionenKlar.value || props.busy) return
    emit('entscheiden', { customer_id: wahl.value || 0, update_address: !!wahl.value && anschriftAnders.value && anschriftUebernehmen.value })
}

function taste(ereignis) {
    if (['INPUT', 'TEXTAREA'].includes(ereignis.target?.tagName)) return
    const nr = Number(ereignis.key)
    if (nr >= 1 && nr <= kandidaten.value.length) {
        wahl.value = kandidaten.value[nr - 1].customer_id
    } else if (ereignis.key === 'n' || ereignis.key === 'N') {
        wahl.value = 0
    } else if (ereignis.key === 'Enter') {
        entscheiden()
    } else if (ereignis.key === 'i' || ereignis.key === 'I') {
        emit('zurueckstellen')
    } else {
        return
    }
    ereignis.preventDefault()
}

defineExpose({ fokus: () => karte.value?.$el?.focus() })
</script>

<style scoped>
.pruefung:focus-visible, .pruefung:focus { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 1px; }
.pruefung--fehler { border-color: rgb(var(--v-theme-error)); }
.positionen { border-bottom: 1px solid var(--oserp-border, rgba(0, 0, 0, 0.08)); padding-bottom: var(--oserp-gap-sm, 8px); }
.kaeufer { background: var(--oserp-surface-2, rgba(0, 0, 0, 0.03)); border-radius: var(--oserp-radius, 8px); }
.kandidat { border: 1px solid var(--oserp-border, rgba(0, 0, 0, 0.12)); border-radius: var(--oserp-radius, 8px); cursor: pointer; }
.kandidat--gewaehlt { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.04); }
.taste {
    display: inline-block; min-width: 18px; padding: 0 4px; border-radius: 4px; text-align: center;
    font-size: 11px; line-height: 16px; border: 1px solid var(--oserp-border, rgba(0, 0, 0, 0.2)); color: var(--oserp-text-muted, #666);
}
</style>
