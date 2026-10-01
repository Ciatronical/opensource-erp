<!-- src/features/shop/components/shop-shipping.config.vue -->
<!--
    Versandarten, Länderzonen und Preise in der Firmenkonfiguration (Reiter
    Shop) — dev/shop-versand.md, Schritt 4.

    Eine Versandart hat einen Anbieter (Lieferant, oder allgemein), einen
    Versandartikel (Bezeichnung, Erlöskonto und Steuer der Versandposition),
    einen Rang (bei verschiedenen zugeordneten Versandarten gilt die
    ranghöchste), Grenzen für Gewicht und Abmessungen und Preisstufen je
    Kanal, Zone, Gewicht und Stückzahl.

    Gespeichert wird verzögert nach jeder Änderung, wie in den übrigen Karten.
    Versandarten und Zonen liegen in eigenen Tabellen; die Karte lädt und
    speichert deshalb selbst über die Shop-API.
-->
<template>
    <div>
        <v-row class="mt-6 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">{{ t('ShopView.shippingConfig.title') }}</h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>

        <div class="text-body-2 text-medium-emphasis mb-3">{{ t('ShopView.shippingConfig.intro') }}</div>

        <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-3">{{ fehler }}</v-alert>
        <div v-else-if="laedt" class="d-flex align-center pa-2">
            <v-progress-circular indeterminate size="20" width="2" class="mr-2" />
        </div>

        <template v-else>
            <!-- ── Länderzonen ── -->
            <div class="d-flex align-center mt-2 mb-1">
                <div class="text-subtitle-1 font-weight-medium">{{ t('ShopView.shippingConfig.zones') }}</div>
                <v-spacer />
                <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="zoneNeu">
                    {{ t('ShopView.shippingConfig.addZone') }}
                </v-btn>
            </div>
            <div class="text-caption text-medium-emphasis mb-2">{{ t('ShopView.shippingConfig.zonesHint') }}</div>

            <v-card v-for="zone in zonen" :key="zone.schluessel" variant="outlined" class="mb-2">
                <v-card-text class="py-2">
                    <v-row dense align="center">
                        <v-col cols="12" sm="4" class="py-1">
                            <v-text-field
                                v-model="zone.description"
                                :label="t('ShopView.shippingConfig.description')"
                                :rules="[pflicht]"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                autocomplete="off"
                            />
                        </v-col>
                        <v-col cols="12" sm="7" class="py-1">
                            <v-autocomplete
                                v-model="zone.countries"
                                :items="landAuswahl"
                                :label="t('ShopView.shippingConfig.countries')"
                                multiple
                                chips
                                closable-chips
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="1" class="py-1 d-flex justify-end align-center">
                            <v-progress-circular v-if="zone.speichert" indeterminate size="16" width="2" class="mr-1" />
                            <v-btn
                                icon="mdi-delete-outline"
                                size="small"
                                variant="text"
                                color="error"
                                :title="t('ShopView.shippingConfig.deleteZone')"
                                @click="loeschenFragen('zone', zone)"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>
            <div v-if="!zonen.length" class="text-caption text-medium-emphasis mb-2">{{ t('ShopView.shippingConfig.noZones') }}</div>

            <!-- ── Versandarten ── -->
            <div class="d-flex align-center mt-6 mb-1">
                <div class="text-subtitle-1 font-weight-medium">{{ t('ShopView.shippingConfig.methods') }}</div>
                <v-spacer />
                <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="methodeNeu">
                    {{ t('ShopView.shippingConfig.addMethod') }}
                </v-btn>
            </div>

            <v-alert v-if="!methoden.some(m => m.active && m.id)" type="warning" variant="tonal" density="compact" class="mb-2">
                {{ t('ShopView.shippingConfig.noActiveMethod') }}
            </v-alert>

            <v-card v-for="methode in methoden" :key="methode.schluessel" variant="outlined" class="mb-3">
                <v-card-item>
                    <v-card-title class="text-subtitle-1 d-flex align-center">
                        <v-icon icon="mdi-truck-delivery-outline" class="mr-2" />
                        {{ methode.description || t('ShopView.shippingConfig.newMethod') }}
                        <v-chip v-if="methode.parts" size="x-small" variant="tonal" class="ml-2">
                            {{ t('ShopView.shippingConfig.assignedParts', { count: methode.parts }) }}
                        </v-chip>
                        <v-spacer />
                        <v-progress-circular v-if="methode.speichert" indeterminate size="16" width="2" class="mr-2" />
                        <v-switch
                            v-model="methode.active"
                            :label="t('ShopView.shippingConfig.active')"
                            color="primary"
                            density="compact"
                            hide-details
                            class="mr-2"
                        />
                        <v-btn
                            icon="mdi-delete-outline"
                            size="small"
                            variant="text"
                            color="error"
                            :title="t('ShopView.shippingConfig.deleteMethod')"
                            @click="loeschenFragen('methode', methode)"
                        />
                    </v-card-title>
                </v-card-item>

                <v-card-text class="pt-0">
                    <v-row dense>
                        <v-col cols="12" sm="6" md="4" class="py-1">
                            <v-text-field
                                v-model="methode.description"
                                :label="t('ShopView.shippingConfig.description')"
                                :rules="[pflicht]"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                autocomplete="off"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" md="4" class="py-1">
                            <v-autocomplete
                                v-model="methode.vendor_id"
                                :items="anbieterAuswahl"
                                :label="t('ShopView.shippingConfig.vendor')"
                                :placeholder="t('ShopView.shippingConfig.vendorGeneric')"
                                persistent-placeholder
                                clearable
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="6" md="4" class="py-1">
                            <v-autocomplete
                                v-model="methode.parts_id"
                                :items="artikelAuswahl(methode)"
                                :label="t('ShopView.shippingConfig.part')"
                                :hint="t('ShopView.shippingConfig.partHint')"
                                :rules="[pflicht]"
                                :loading="artikelSucheLaeuft"
                                no-filter
                                persistent-hint
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                @update:search="text => artikelSuchen(text)"
                                @update:model-value="id => artikelGewaehlt(methode, id)"
                            />
                        </v-col>
                        <v-col cols="6" sm="3" md="2" class="py-1">
                            <v-text-field
                                v-model="methode.rank"
                                type="number"
                                step="1"
                                :label="t('ShopView.shippingConfig.rank')"
                                :hint="t('ShopView.shippingConfig.rankHint')"
                                persistent-hint
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="6" sm="3" md="2" class="py-1">
                            <v-text-field
                                v-model="methode.max_weight"
                                type="number"
                                step="0.001"
                                min="0"
                                :label="t('ShopView.shippingConfig.maxWeight')"
                                :suffix="gewichtseinheit"
                                :rules="[grenzePruefen]"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="6" sm="3" md="2" class="py-1">
                            <v-text-field
                                v-model="methode.max_length"
                                type="number"
                                step="0.1"
                                min="0"
                                :label="t('ShopView.shippingConfig.maxLength')"
                                suffix="cm"
                                :rules="[grenzePruefen]"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="6" sm="3" md="2" class="py-1">
                            <v-text-field
                                v-model="methode.max_girth"
                                type="number"
                                step="0.1"
                                min="0"
                                :label="t('ShopView.shippingConfig.maxGirth')"
                                :title="t('ShopView.shippingConfig.maxGirthHint')"
                                suffix="cm"
                                :rules="[grenzePruefen]"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" md="4" class="py-1">
                            <v-text-field
                                v-model="methode.ebay_fulfillment_policy_id"
                                :label="t('ShopView.shippingConfig.ebayPolicy')"
                                :hint="t('ShopView.shippingConfig.ebayPolicyHint')"
                                persistent-hint
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                autocomplete="off"
                            />
                        </v-col>
                    </v-row>

                    <v-checkbox
                        v-model="methode.free_shipping_applies"
                        :label="t('ShopView.shippingConfig.freeShippingApplies')"
                        :hint="t('ShopView.shippingConfig.freeShippingAppliesHint')"
                        persistent-hint
                        color="primary"
                        density="compact"
                        class="mt-1"
                    />

                    <!-- Preisstufen -->
                    <div class="d-flex align-center mt-3">
                        <div class="text-body-2 font-weight-medium">{{ t('ShopView.shippingConfig.rates') }}</div>
                        <v-spacer />
                        <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="stufeNeu(methode)">
                            {{ t('ShopView.shippingConfig.addRate') }}
                        </v-btn>
                    </div>
                    <div class="text-caption text-medium-emphasis mb-1">
                        {{ t('ShopView.shippingConfig.ratesHint', { art: t(bruttoPreise ? 'ShopView.partCard.gross' : 'ShopView.partCard.net') }) }}
                    </div>
                    <v-alert v-if="!methode.rates.length" type="info" variant="tonal" density="compact" class="mb-1">
                        {{ t('ShopView.shippingConfig.noRates') }}
                    </v-alert>
                    <v-alert v-else-if="doppelteStufe(methode)" type="error" variant="tonal" density="compact" class="mb-1">
                        {{ t('ShopView.shippingConfig.duplicateRate') }}
                    </v-alert>
                    <v-row v-for="(stufe, index) in methode.rates" :key="index" dense align="center">
                        <v-col cols="6" md="3" class="py-1">
                            <v-select
                                v-model="stufe.channel_id"
                                :items="kanalAuswahl"
                                :label="t('ShopView.shippingConfig.channel')"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="6" md="3" class="py-1">
                            <v-select
                                v-model="stufe.zone_id"
                                :items="zonenAuswahl"
                                :label="t('ShopView.shippingConfig.zone')"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="4" md="2" class="py-1">
                            <v-text-field
                                v-model="stufe.weight_from"
                                type="number"
                                step="0.001"
                                min="0"
                                :label="t('ShopView.shippingConfig.weightFrom')"
                                :suffix="gewichtseinheit"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="4" md="1" class="py-1">
                            <v-text-field
                                v-model="stufe.qty_from"
                                type="number"
                                step="1"
                                min="0"
                                :label="t('ShopView.shippingConfig.qtyFrom')"
                                variant="outlined"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="3" md="2" class="py-1">
                            <v-text-field
                                v-model="stufe.price"
                                type="number"
                                step="0.01"
                                min="0"
                                :label="t('ShopView.shippingConfig.price')"
                                :suffix="waehrung"
                                :rules="[preisPruefen]"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="1" class="py-1 d-flex justify-end">
                            <v-btn
                                icon="mdi-close"
                                size="small"
                                variant="text"
                                :title="t('ShopView.shippingConfig.deleteRate')"
                                @click="methode.rates.splice(index, 1)"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>
        </template>

        <!-- Rückfrage vor dem Löschen -->
        <v-dialog v-model="loeschDialog" max-width="480">
            <v-card>
                <v-card-title>{{ loeschZiel?.art === 'zone' ? t('ShopView.shippingConfig.deleteZone') : t('ShopView.shippingConfig.deleteMethod') }}</v-card-title>
                <v-card-text>
                    {{ loeschZiel?.art === 'zone'
                        ? t('ShopView.shippingConfig.deleteZoneConfirm', { name: loeschZiel?.eintrag.description })
                        : t('ShopView.shippingConfig.deleteMethodConfirm', { name: loeschZiel?.eintrag.description }) }}
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="loeschDialog = false">{{ t('ShopView.shippingConfig.cancel') }}</v-btn>
                    <v-btn color="error" variant="flat" @click="loeschen">{{ t('ShopView.shippingConfig.delete') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { shopFehler } from '../composables/useShop.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    /** shop_tax_included aus dem Formular — Preise netto oder brutto wie parts.sellprice */
    taxIncluded: { type: [Boolean, String, Number], default: null },
})

const i18n = useI18n()
const { t, te, locale } = i18n
const oserp = oserpStore()

const WAHR = [true, 't', 'true', '1', 1]
const bruttoPreise = computed(() => WAHR.includes(props.taxIncluded))
const waehrung = computed(() => oserp.getClientDefaultValue('shop_standard_currency', 'EUR') || 'EUR')

const methoden = ref([])
const zonen = ref([])
const kanaele = ref([])
const anbieter = ref([])
const laender = ref([])
const gewichtseinheit = ref('')
const laedt = ref(false)
const fehler = ref('')

// ── Auswahllisten ──

const anzeige = computed(() => {
    try {
        return new Intl.DisplayNames([locale.value], { type: 'region' })
    } catch {
        return null
    }
})
const landAuswahl = computed(() => laender.value
    .map(code => ({ value: code, title: `${anzeige.value?.of(code) || code} (${code})` }))
    .sort((a, b) => a.title.localeCompare(b.title, locale.value)))

const anbieterAuswahl = computed(() => anbieter.value.map(v => ({
    value: Number(v.id), title: v.vendornumber ? `${v.name} (${v.vendornumber})` : v.name,
})))

const kanalName = typ => (te(`ShopView.channels.${typ}`) ? t(`ShopView.channels.${typ}`) : typ)
/** eBay rechnet den Versand selbst (W4) — dort gibt es keine Preisstufen */
const kanalAuswahl = computed(() => [
    { value: null, title: t('ShopView.shippingConfig.allChannels') },
    ...kanaele.value.filter(k => k.type !== 'ebay').map(k => ({ value: Number(k.channel_id), title: kanalName(k.type) })),
])
const zonenAuswahl = computed(() => [
    { value: null, title: t('ShopView.shippingConfig.otherCountries') },
    ...zonen.value.filter(z => z.id).map(z => ({ value: z.id, title: z.description })),
])

// Versandartikel: gesucht wird im Backend; der gewählte bleibt in der Liste
const artikelTreffer = ref([])
const artikelSucheLaeuft = ref(false)
let artikelTimer = null
function artikelAuswahl(methode) {
    const liste = [...artikelTreffer.value]
    if (methode.parts_id && !liste.some(a => a.id === methode.parts_id)) {
        liste.unshift({ id: methode.parts_id, partnumber: methode.partnumber, description: methode.part_description })
    }
    return liste.map(a => ({
        value: a.id,
        title: `${a.partnumber} – ${a.description}${WAHR.includes(a.obsolete) ? ` (${t('ShopView.shippingConfig.obsolete')})` : ''}`,
    }))
}
function artikelSuchen(text) {
    clearTimeout(artikelTimer)
    if (!text || text.length < 2 || text.includes(' – ')) return
    artikelTimer = setTimeout(async () => {
        artikelSucheLaeuft.value = true
        try {
            const antwort = await axios.post('/api/shop/', { action: 'searchShopShippingParts', q: text })
            artikelTreffer.value = (antwort.data?.payload || []).map(a => ({ ...a, id: Number(a.id) }))
        } catch {
            artikelTreffer.value = []
        } finally {
            artikelSucheLaeuft.value = false
        }
    }, 300)
}
function artikelGewaehlt(methode, id) {
    const treffer = artikelTreffer.value.find(a => a.id === id)
    if (treffer) {
        methode.partnumber = treffer.partnumber
        methode.part_description = treffer.description
    }
}

// ── Prüfungen ──

const leer = wert => wert === null || wert === undefined || String(wert).trim() === ''
const pflicht = wert => !leer(wert) || t('ShopView.shippingConfig.required')
const grenzePruefen = wert => leer(wert) || Number(wert) > 0 || t('ShopView.shippingConfig.limitInvalid')
const preisPruefen = wert => (!leer(wert) && Number(wert) >= 0) || t('ShopView.shippingConfig.priceInvalid')
const stufenSchluessel = s => [s.channel_id ?? 0, s.zone_id ?? 0, Number(s.weight_from) || 0, Number(s.qty_from) || 0].join('|')
function doppelteStufe(methode) {
    const schluessel = methode.rates.map(stufenSchluessel)
    return new Set(schluessel).size !== schluessel.length
}
function methodeGueltig(m) {
    return !leer(m.description) && m.parts_id
        && [m.max_weight, m.max_length, m.max_girth].every(w => grenzePruefen(w) === true)
        && m.rates.every(s => preisPruefen(s.price) === true && Number(s.weight_from || 0) >= 0 && Number(s.qty_from || 0) >= 0)
        && !doppelteStufe(m)
}

// ── Laden ──

let bereit = false
let laufend = 0
const zahlOderLeer = wert => (wert === null || wert === undefined ? '' : String(Number(wert)))

function alsMethode(m) {
    return {
        schluessel: `m${m.id}`,
        id: Number(m.id),
        description: m.description || '',
        vendor_id: m.vendor_id ? Number(m.vendor_id) : null,
        parts_id: m.parts_id ? Number(m.parts_id) : null,
        partnumber: m.partnumber || '',
        part_description: m.part_description || '',
        rank: String(m.rank ?? 0),
        max_weight: zahlOderLeer(m.max_weight),
        max_length: zahlOderLeer(m.max_length),
        max_girth: zahlOderLeer(m.max_girth),
        free_shipping_applies: WAHR.includes(m.free_shipping_applies),
        ebay_fulfillment_policy_id: m.ebay_fulfillment_policy_id || '',
        active: WAHR.includes(m.active),
        parts: Number(m.parts) || 0,
        rates: (m.rates || []).map(s => ({
            channel_id: s.channel_id ? Number(s.channel_id) : null,
            zone_id: s.zone_id ? Number(s.zone_id) : null,
            weight_from: zahlOderLeer(s.weight_from),
            qty_from: zahlOderLeer(s.qty_from),
            price: zahlOderLeer(s.price),
        })),
        speichert: false,
    }
}

function alsZone(z) {
    return {
        schluessel: `z${z.id}`,
        id: Number(z.id),
        description: z.description || '',
        sortkey: Number(z.sortkey) || 0,
        countries: z.countries || [],
        speichert: false,
    }
}

async function laden() {
    bereit = false
    laedt.value = true
    fehler.value = ''
    try {
        const antwort = await axios.post('/api/shop/', { action: 'getShopShipping' })
        if (!antwort.data?.success) {
            fehler.value = shopFehler(antwort.data, i18n, 'ShopView.shippingConfig.loadError').text
            return
        }
        const daten = antwort.data.payload || {}
        methoden.value = (daten.methods || []).map(alsMethode)
        zonen.value = (daten.zones || []).map(alsZone)
        kanaele.value = daten.channels || []
        anbieter.value = daten.vendors || []
        laender.value = daten.countries || []
        gewichtseinheit.value = daten.weightunit || ''
        methoden.value.forEach(m => { zuletzt[m.schluessel] = JSON.stringify(methodeNutzdaten(m)) })
        zonen.value.forEach(z => { zuletzt[z.schluessel] = JSON.stringify(zoneNutzdaten(z)) })
    } catch (e) {
        fehler.value = shopFehler(e?.response?.data, i18n, 'ShopView.shippingConfig.loadError').text
    } finally {
        laedt.value = false
    }
    await nextTick()
    bereit = true
}

// ── Speichern ──

const zuletzt = {}
const timer = {}
/** Je Eintrag nur ein Speichern zur Zeit — eine neue Versandart sonst zweimal angelegt */
const inArbeit = {}

const zahl = wert => (leer(wert) ? null : Number(wert))
function methodeNutzdaten(m) {
    return {
        id: m.id || 0,
        description: m.description.trim(),
        vendor_id: m.vendor_id || null,
        parts_id: m.parts_id || 0,
        rank: Number(m.rank) || 0,
        max_weight: zahl(m.max_weight),
        max_length: zahl(m.max_length),
        max_girth: zahl(m.max_girth),
        free_shipping_applies: m.free_shipping_applies,
        ebay_fulfillment_policy_id: m.ebay_fulfillment_policy_id.trim(),
        active: m.active,
        rates: m.rates.map(s => ({
            channel_id: s.channel_id || null,
            zone_id: s.zone_id || null,
            weight_from: Number(s.weight_from) || 0,
            qty_from: Number(s.qty_from) || 0,
            price: zahl(s.price),
        })),
    }
}
function zoneNutzdaten(z) {
    return { id: z.id || 0, description: z.description.trim(), sortkey: z.sortkey, countries: [...z.countries].sort() }
}

async function speichern(eintrag, art) {
    const daten = art === 'zone' ? zoneNutzdaten(eintrag) : methodeNutzdaten(eintrag)
    const stand = JSON.stringify(daten)
    if (stand === zuletzt[eintrag.schluessel]) return
    if (art === 'zone' ? leer(eintrag.description) : !methodeGueltig(eintrag)) return
    if (inArbeit[eintrag.schluessel]) {
        // Läuft schon — danach noch einmal, mit dem dann aktuellen Stand
        inArbeit[eintrag.schluessel] = 'nochmal'
        return
    }

    inArbeit[eintrag.schluessel] = true
    eintrag.speichert = true
    try {
        const antwort = await axios.post('/api/shop/', {
            action: art === 'zone' ? 'saveShopShippingZone' : 'saveShopShippingMethod', ...daten,
        })
        if (!antwort.data?.success) {
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.shippingConfig.saveError').text)
            return
        }
        eintrag.id = Number(antwort.data.payload?.id) || eintrag.id
        zuletzt[eintrag.schluessel] = JSON.stringify(art === 'zone' ? zoneNutzdaten(eintrag) : methodeNutzdaten(eintrag))
        // Ein Land wechselt die Zone: die andere Zone zeigt es nicht mehr
        if (art === 'zone') {
            zonen.value.filter(z => z !== eintrag).forEach(z => {
                const vorher = z.countries.length
                z.countries = z.countries.filter(c => !eintrag.countries.includes(c))
                if (z.countries.length !== vorher) zuletzt[z.schluessel] = JSON.stringify(zoneNutzdaten(z))
            })
        }
        toasts.success(t('ShopView.shippingConfig.saved'))
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.shippingConfig.saveError').text)
    } finally {
        eintrag.speichert = false
        const nochmal = inArbeit[eintrag.schluessel] === 'nochmal'
        delete inArbeit[eintrag.schluessel]
        if (nochmal) speichern(eintrag, art)
    }
}

function planen(liste, art) {
    if (!bereit) return
    liste.forEach(eintrag => {
        const daten = art === 'zone' ? zoneNutzdaten(eintrag) : methodeNutzdaten(eintrag)
        if (JSON.stringify(daten) === zuletzt[eintrag.schluessel]) return
        clearTimeout(timer[eintrag.schluessel])
        timer[eintrag.schluessel] = setTimeout(() => {
            delete timer[eintrag.schluessel]
            speichern(eintrag, art)
        }, 800)
    })
}

watch(methoden, liste => planen(liste, 'methode'), { deep: true })
watch(zonen, liste => planen(liste, 'zone'), { deep: true })

// ── Neu und Löschen ──

function methodeNeu() {
    laufend += 1
    methoden.value.push(alsMethode({
        id: 0, description: '', rank: 0, free_shipping_applies: true, active: true,
        rates: [{ channel_id: null, zone_id: null, weight_from: 0, qty_from: 0, price: null }],
    }))
    methoden.value[methoden.value.length - 1].schluessel = `neu-m${laufend}`
}

function zoneNeu() {
    laufend += 1
    zonen.value.push({ ...alsZone({ id: 0, description: '', sortkey: zonen.value.length + 1, countries: [] }), schluessel: `neu-z${laufend}` })
}

function stufeNeu(methode) {
    const letzte = methode.rates[methode.rates.length - 1]
    methode.rates.push({
        channel_id: letzte?.channel_id ?? null,
        zone_id: letzte?.zone_id ?? null,
        weight_from: '',
        qty_from: '',
        price: '',
    })
}

const loeschDialog = ref(false)
const loeschZiel = ref(null)
function loeschenFragen(art, eintrag) {
    // Noch nicht gespeichert: einfach weg
    if (!eintrag.id) {
        const liste = art === 'zone' ? zonen : methoden
        liste.value = liste.value.filter(e => e !== eintrag)
        return
    }
    loeschZiel.value = { art, eintrag }
    loeschDialog.value = true
}

async function loeschen() {
    const { art, eintrag } = loeschZiel.value
    loeschDialog.value = false
    try {
        const antwort = await axios.post('/api/shop/', {
            action: art === 'zone' ? 'deleteShopShippingZone' : 'deleteShopShippingMethod', id: eintrag.id,
        })
        if (!antwort.data?.success) {
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.shippingConfig.saveError').text)
            return
        }
        clearTimeout(timer[eintrag.schluessel])
        if (art === 'zone') {
            zonen.value = zonen.value.filter(z => z !== eintrag)
            // Stufen dieser Zone hat die Datenbank mitgelöscht
            methoden.value.forEach(m => {
                m.rates = m.rates.filter(s => s.zone_id !== eintrag.id)
                zuletzt[m.schluessel] = JSON.stringify(methodeNutzdaten(m))
            })
        } else {
            methoden.value = methoden.value.filter(m => m !== eintrag)
        }
        toasts.success(t('ShopView.shippingConfig.deleted'))
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.shippingConfig.saveError').text)
    }
}

onMounted(laden)
onBeforeUnmount(() => {
    // Ausstehendes sofort speichern, statt es zu verlieren
    Object.keys(timer).forEach(schluessel => {
        clearTimeout(timer[schluessel])
        const methode = methoden.value.find(m => m.schluessel === schluessel)
        if (methode) speichern(methode, 'methode')
        const zone = zonen.value.find(z => z.schluessel === schluessel)
        if (zone) speichern(zone, 'zone')
    })
})
</script>
