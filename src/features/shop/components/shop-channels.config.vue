<!-- src/features/shop/components/shop-channels.config.vue -->
<!--
    Verkaufskanäle — eigene Ansicht im Shop-Menü (views/shop.channels.vue),
    früher Teil der Firmenkonfiguration (Reiter Shop);
    dev/shop-verkaufskanaele.md, Schritt 3.

    Je Kanal die Vorgaben für alle Artikel ohne eigenen Aufschlag: Aufschlag
    in Prozent oder als fester Betrag, Rundung des Bruttopreises auf ,99.

    Die Kanäle liegen in sales_channel_shop, nicht in defaults_oserp. Die
    Karte lädt und speichert deshalb selbst über die Shop-API, verzögert nach
    jeder Änderung.

    Mindestens ein Kanal bleibt eingeschaltet (V8) — solange es nur den
    HugoShop gibt, ist er gesperrt. Ändert sich sein Preis, legt das Backend
    den Auftrag an, alle Produktseiten neu zu schreiben (V9).

    Mehrere Kanäle je Art (dev/shop-mehrere-kanaele.md, Schritt 5): Jede Karte
    ist eine Instanz mit eigenem Namen und eigenen Einstellungen (Webseite,
    Shop-Schlüssel, PayPal, eBay-Zugang …) im aufklappbaren Bereich
    darunter (shop-channel-settings.vue). Unter den Karten lassen sich Kanäle
    anlegen; löschen lässt sich ein abgeschalteter Kanal ohne Belege (M5).
-->
<template>
    <div>
        <!-- Eigene Überschrift nur, wenn die Seite keine trägt -->
        <v-row v-if="mitUeberschrift" class="mt-6 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">{{ t('ShopView.channelConfig.title') }}</h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>

        <div class="text-body-2 text-medium-emphasis mb-3">{{ t('ShopView.channelConfig.intro') }}</div>

        <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-3">{{ fehler }}</v-alert>
        <div v-else-if="laedt" class="d-flex align-center pa-2">
            <v-progress-circular indeterminate size="20" width="2" class="mr-2" />
        </div>

        <v-card
            v-for="kanal in kanaele"
            :key="kanal.channel_id"
            variant="outlined"
            class="my-3"
        >
            <v-card-item>
                <template #prepend>
                    <v-icon :icon="kanalIcon(kanal.type)" />
                </template>
                <v-card-title class="text-subtitle-1 d-flex align-center">
                    {{ kanal.name || artName(kanal.type) }}
                    <v-chip size="x-small" variant="outlined" class="ml-2">{{ artName(kanal.type) }}</v-chip>
                    <v-chip size="x-small" variant="flat" class="ml-2" :color="kanal.active ? 'success' : 'grey'">
                        {{ t('ShopView.channelConfig.parts', { anzahl: kanal.parts }) }}
                    </v-chip>
                    <v-spacer />
                    <v-progress-circular v-if="speichert[kanal.channel_id]" indeterminate size="16" width="2" />
                    <!-- Löschen (M5): nur abgeschaltet, ohne offene Aufträge und Belege -->
                    <v-btn
                        v-if="kanal.deletable"
                        icon="mdi-delete-outline"
                        variant="text"
                        size="small"
                        color="error"
                        :title="t('ShopView.channelConfig.delete')"
                        @click="loeschenFragen(kanal)"
                    />
                </v-card-title>
            </v-card-item>

            <v-card-text class="pt-0">
                <v-row dense>
                    <v-col cols="12" sm="6" md="4" class="py-1">
                        <v-text-field
                            v-model="kanal.name"
                            :label="t('ShopView.channelConfig.name')"
                            :rules="[wert => !!String(wert || '').trim() || t('ShopView.channelConfig.nameRequired')]"
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                            autocomplete="off"
                        />
                    </v-col>
                </v-row>

                <v-switch
                    v-model="kanal.active"
                    :label="t('ShopView.channelConfig.active')"
                    :disabled="letzterAktiver(kanal)"
                    :hint="letzterAktiver(kanal) ? t('ShopView.channelConfig.activeLast') : ''"
                    persistent-hint
                    color="primary"
                    density="compact"
                    class="mb-2"
                />
                <!-- M3: neue Shop-Artikel automatisch in diesen Kanal aufnehmen -->
                <v-switch
                    v-model="kanal.auto_add_parts"
                    :label="t('ShopView.channelConfig.autoAddParts')"
                    :hint="t('ShopView.channelConfig.autoAddPartsHint')"
                    persistent-hint
                    color="primary"
                    density="compact"
                    class="mb-2"
                />

                <v-row dense>
                    <v-col cols="12" sm="6" md="4" class="py-1">
                        <v-select
                            v-model="kanal.markup_type"
                            :items="aufschlagArten"
                            :label="t('ShopView.partCard.markup')"
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                        />
                    </v-col>
                    <v-col v-if="kanal.markup_type !== 'none'" cols="12" sm="6" md="4" class="py-1">
                        <v-text-field
                            persistent-placeholder
                            v-model.number="kanal.markup_value"
                            type="number"
                            step="0.01"
                            :label="t('ShopView.partCard.markupValue')"
                            :suffix="kanal.markup_type === 'percent' ? '%' : waehrung"
                            :hint="kanal.markup_type === 'amount' ? betragHinweis : ''"
                            :rules="[wertPruefen(kanal)]"
                            persistent-hint
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                            autocomplete="off"
                        />
                    </v-col>
                </v-row>

                <v-checkbox
                    v-model="kanal.round_99"
                    :label="t('ShopView.channelConfig.round99')"
                    :hint="t('ShopView.channelConfig.round99Hint')"
                    persistent-hint
                    color="primary"
                    density="compact"
                    class="mt-2"
                />
                <v-alert
                    v-if="kanal.round_99 && !bruttoPreise"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mt-2"
                >
                    {{ t('ShopView.channelConfig.round99NetWarning') }}
                </v-alert>

                <!-- Freigrenze (dev/shop-versand.md, Entscheidung 4). eBay
                     rechnet den Versand selbst (W4) — dort ohne Wirkung -->
                <v-row v-if="kanal.type !== 'ebay'" dense class="mt-2">
                    <v-col cols="12" sm="6" md="4" class="py-1">
                        <v-text-field
                            persistent-placeholder
                            v-model="kanal.free_shipping_from"
                            type="number"
                            step="0.01"
                            min="0"
                            :label="t('ShopView.channelConfig.freeShippingFrom')"
                            :hint="t('ShopView.channelConfig.freeShippingFromHint')"
                            :suffix="waehrung"
                            :rules="[freigrenzePruefen]"
                            persistent-hint
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                            autocomplete="off"
                        />
                    </v-col>
                    <!-- Lieferländer (dev/shop-versand.md, Schritt 7): leer = alle -->
                    <v-col cols="12" sm="6" md="8" class="py-1">
                        <v-autocomplete
                            v-model="kanal.countries"
                            :items="landAuswahl"
                            :label="t('ShopView.channelConfig.countries')"
                            :hint="t('ShopView.channelConfig.countriesHint')"
                            persistent-hint
                            multiple
                            chips
                            closable-chips
                            clearable
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                        />
                    </v-col>
                </v-row>

                <!-- eBay: Lieferbedingungen mit einer Lieferzeit, die eBay nicht
                     abbilden kann (W11) — solche Artikel werden dort nicht angeboten -->
                <v-row v-if="kanal.type === 'ebay'" dense class="mt-2">
                    <v-col cols="12" md="8" class="py-1">
                        <v-autocomplete
                            v-model="kanal.excluded_delivery_terms"
                            :items="lieferbedingungAuswahl"
                            :label="t('ShopView.channelConfig.excludedDeliveryTerms')"
                            :hint="t('ShopView.channelConfig.excludedDeliveryTermsHint')"
                            persistent-hint
                            multiple
                            chips
                            closable-chips
                            clearable
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                        />
                    </v-col>
                </v-row>

                <!-- Einstellungen der Instanz: erst beim Aufklappen geladen -->
                <v-expansion-panels variant="accordion" class="mt-4">
                    <v-expansion-panel>
                        <v-expansion-panel-title>
                            <v-icon start size="small">mdi-cog-outline</v-icon>
                            {{ t('ShopView.channelConfig.settings', { name: kanal.name || artName(kanal.type) }) }}
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <ShopChannelSettings
                                :channel-id="kanal.channel_id"
                                :type="kanal.type"
                                :settings="kanal.settings"
                                :secrets-set="kanal.secrets_set"
                                :quellen="quellen"
                            />
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </v-card-text>
        </v-card>

        <!-- Kanal anlegen: eine weitere Instanz einer Art, abgeschaltet -->
        <v-card v-if="!laedt && !fehler && arten.length" variant="outlined" class="my-3">
            <v-card-text class="d-flex flex-wrap align-center ga-2">
                <span class="text-body-2 mr-2">{{ t('ShopView.channelConfig.newChannel') }}</span>
                <v-select
                    v-model="neu.type"
                    :items="artAuswahl"
                    :label="t('ShopView.channelConfig.newChannelType')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    style="max-width: 20ch"
                />
                <v-text-field
                    v-model="neu.name"
                    :label="t('ShopView.channelConfig.name')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    autocomplete="off"
                    style="max-width: 40ch"
                    @keyup.enter="anlegen"
                />
                <v-btn
                    color="primary"
                    variant="tonal"
                    prepend-icon="mdi-plus"
                    :disabled="!neu.type || !neu.name.trim()"
                    :loading="legtAn"
                    @click="anlegen"
                >
                    {{ t('ShopView.channelConfig.create') }}
                </v-btn>
            </v-card-text>
        </v-card>

        <v-dialog v-model="loeschDialog" max-width="480">
            <v-card>
                <v-card-title>{{ t('ShopView.channelConfig.delete') }}</v-card-title>
                <v-card-text>{{ t('ShopView.channelConfig.deleteConfirm', { name: zuLoeschen?.name || '' }) }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="loeschDialog = false">{{ t('ShopView.channelConfig.cancel') }}</v-btn>
                    <v-btn color="error" variant="tonal" :loading="loescht" @click="loeschen">
                        {{ t('ShopView.channelConfig.delete') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { shopFehler } from '../composables/useShop.js'
import ShopChannelSettings from './shop-channel-settings.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    /**
     * shop_tax_included aus einem Formular — gilt sofort, auch vor dem
     * Speichern; null = der gespeicherte Wert aus getShopChannels
     */
    taxIncluded: { type: [Boolean, String, Number], default: null },
    /** Überschrift „Verkaufskanäle" zeigen — in der eigenen Ansicht trägt sie die Seite */
    mitUeberschrift: { type: Boolean, default: true },
})

const i18n = useI18n()
const { t, te } = i18n
const oserp = oserpStore()

const kanaele = ref([])
/** Auswahllisten aus getShopChannels */
const alleLaender = ref([])
const lieferbedingungen = ref([])
const laedt = ref(false)
const fehler = ref('')
const speichert = ref({})

/** Brutto-Verkaufspreise: aus dem Formular, sonst aus der Antwort des Backends */
const ausBackend = ref(false)
const WAHR = [true, 't', 'true', '1', 1]
const bruttoPreise = computed(() => (props.taxIncluded === null ? ausBackend.value : WAHR.includes(props.taxIncluded)))

const waehrung = computed(() => oserp.getClientDefaultValue('shop_standard_currency', 'EUR') || 'EUR')
const betragHinweis = computed(() =>
    t(bruttoPreise.value ? 'ShopView.partCard.markupAmountGross' : 'ShopView.partCard.markupAmountNet'))

const aufschlagArten = computed(() => [
    { value: 'none', title: t('ShopView.partCard.markupNone') },
    { value: 'percent', title: t('ShopView.partCard.markupPercent') },
    { value: 'amount', title: t('ShopView.partCard.markupAmount') },
])

const KANAL_ICONS = { hugoshop: 'mdi-storefront', ebay: 'mdi-shopping', amazon: 'mdi-package-variant' }
const kanalIcon = typ => KANAL_ICONS[typ] || 'mdi-store'
/** Bezeichnung der Art (HugoShop, eBay) — der Kanal selbst trägt seinen Namen */
const artName = typ => (te(`ShopView.channels.${typ}`) ? t(`ShopView.channels.${typ}`) : typ)

/** Arten, von denen sich Kanäle anlegen lassen (getShopChannels) */
const arten = ref([])
const artAuswahl = computed(() => arten.value.map(art => ({ value: art, title: artName(art) })))

/** Vorlagensätze für die Einstellungen der HugoShops — einmal geladen */
const quellen = ref({ shopTemplateSets: [] })

/** Der letzte eingeschaltete Kanal lässt sich nicht abschalten (V8) */
const letzterAktiver = kanal => kanal.active && kanaele.value.filter(k => k.active).length === 1

const landAnzeige = computed(() => {
    try {
        return new Intl.DisplayNames([i18n.locale.value], { type: 'region' })
    } catch {
        return null
    }
})
const landAuswahl = computed(() => alleLaender.value
    .map(code => ({ value: code, title: `${landAnzeige.value?.of(code) || code} (${code})` }))
    .sort((a, b) => a.title.localeCompare(b.title, i18n.locale.value)))
const lieferbedingungAuswahl = computed(() => lieferbedingungen.value.map(d => ({
    value: Number(d.id),
    title: d.description_long ? `${d.description} – ${d.description_long}` : d.description,
})))

/** Leer heißt „keine Freigrenze"; sonst ein Betrag ab 0 */
const freigrenzeWert = wert => (wert === '' || wert === null || wert === undefined ? null : Number(wert))
function freigrenzePruefen(wert) {
    const zahl = freigrenzeWert(wert)
    return zahl === null || (Number.isFinite(zahl) && zahl >= 0) || t('ShopView.channelConfig.freeShippingFromInvalid')
}

/** Ein Abschlag von 100 % oder mehr ergäbe keinen Preis */
function wertPruefen(kanal) {
    return () => kanal.markup_type !== 'percent' || Number(kanal.markup_value) > -100
        || t('ShopView.channelConfig.invalidPercent')
}

// ── Laden und Speichern ──

let bereit = false
const timer = {}
const zuletzt = {}

function alsKanal(zeile) {
    return {
        channel_id: Number(zeile.channel_id),
        type: String(zeile.type),
        active: WAHR.includes(zeile.active),
        markup_type: zeile.markup_type || 'none',
        markup_value: Number(zeile.markup_value) || 0,
        round_99: WAHR.includes(zeile.round_99),
        free_shipping_from: zeile.free_shipping_from === null || zeile.free_shipping_from === undefined
            ? '' : String(Number(zeile.free_shipping_from)),
        countries: alsListe(zeile.countries).map(String),
        excluded_delivery_terms: alsListe(alsObjekt(zeile.settings).excluded_delivery_terms).map(Number),
        parts: Number(zeile.parts) || 0,
        name: String(zeile.name || ''),
        auto_add_parts: WAHR.includes(zeile.auto_add_parts),
        // Für den Einstellungsbereich; ändert sich hier nicht mit
        settings: alsObjekt(zeile.settings),
        secrets_set: alsObjekt(zeile.secrets_set),
        deletable: WAHR.includes(zeile.deletable),
    }
}

/** Was gespeichert wird — zum Vergleich, damit Gleiches nicht erneut geht */
function nutzdaten(kanal) {
    return {
        channel_id: kanal.channel_id,
        active: kanal.active,
        markup_type: kanal.markup_type,
        markup_value: kanal.markup_type === 'none' ? 0 : Number(kanal.markup_value) || 0,
        round_99: kanal.round_99,
        free_shipping_from: freigrenzeWert(kanal.free_shipping_from),
        countries: [...kanal.countries].sort(),
        name: kanal.name.trim(),
        auto_add_parts: kanal.auto_add_parts,
        // nur eBay kennt den Ausschluss; andere Kanäle lassen ihn unberührt
        ...(kanal.type === 'ebay' ? { excluded_delivery_terms: [...kanal.excluded_delivery_terms].sort((a, b) => a - b) } : {}),
    }
}

/** JSON-Spalten kommen je nach Treiber als Text oder schon gelesen */
function alsObjekt(wert) {
    if (wert && typeof wert === 'object') return wert
    try { return JSON.parse(wert || '{}') || {} } catch { return {} }
}
function alsListe(wert) {
    if (Array.isArray(wert)) return wert
    try { const liste = JSON.parse(wert || '[]'); return Array.isArray(liste) ? liste : [] } catch { return [] }
}

async function laden() {
    bereit = false
    laedt.value = true
    fehler.value = ''
    try {
        const antwort = await axios.post('/api/shop/', { action: 'getShopChannels' })
        if (!antwort.data?.success) {
            fehler.value = shopFehler(antwort.data, i18n, 'ShopView.channelConfig.loadError').text
            return
        }
        alleLaender.value = antwort.data.payload?.all_countries || []
        arten.value = antwort.data.payload?.types || []
        lieferbedingungen.value = antwort.data.payload?.delivery_terms || []
        kanaele.value = (antwort.data.payload?.channels || []).map(alsKanal)
        ausBackend.value = WAHR.includes(antwort.data.payload?.tax_included)
        kanaele.value.forEach(k => { zuletzt[k.channel_id] = JSON.stringify(nutzdaten(k)) })
    } catch (e) {
        fehler.value = shopFehler(e?.response?.data, i18n, 'ShopView.channelConfig.loadError').text
    } finally {
        laedt.value = false
    }
    // Der Watcher läuft erst vor dem nächsten Rendern — sonst hielte er das
    // Laden für eine Eingabe
    await nextTick()
    bereit = true
}

async function speichern(kanal) {
    const daten = nutzdaten(kanal)
    const stand = JSON.stringify(daten)
    if (stand === zuletzt[kanal.channel_id]) return
    if (daten.markup_type === 'percent' && daten.markup_value <= -100) return
    if (freigrenzePruefen(kanal.free_shipping_from) !== true) return
    if (!daten.name) return
    const geschaltet = JSON.parse(zuletzt[kanal.channel_id] || '{}').active !== daten.active

    speichert.value = { ...speichert.value, [kanal.channel_id]: true }
    try {
        const antwort = await axios.post('/api/shop/', { action: 'saveShopChannel', ...daten })
        if (!antwort.data?.success) {
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.channelConfig.saveError').text)
            return
        }
        // Das Backend lässt den letzten eingeschalteten Kanal eingeschaltet —
        // gilt dann dessen Stand
        const aktiv = antwort.data.payload?.active
        if (typeof aktiv === 'boolean' && aktiv !== kanal.active) {
            kanal.active = aktiv
        }
        zuletzt[kanal.channel_id] = JSON.stringify(nutzdaten(kanal))
        toasts.success(antwort.data.payload?.republish
            ? t('ShopView.channelConfig.republishQueued')
            : t('ShopView.channelConfig.saved'))
        // Ob sich der Kanal löschen lässt, hängt am Schalter und an den
        // Aufträgen, die das Abschalten anlegt — dafür neu laden
        if (geschaltet) {
            await laden()
        }
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.channelConfig.saveError').text)
    } finally {
        speichert.value = { ...speichert.value, [kanal.channel_id]: false }
    }
}

watch(kanaele, liste => {
    if (!bereit) return
    liste.forEach(kanal => {
        if (JSON.stringify(nutzdaten(kanal)) === zuletzt[kanal.channel_id]) return
        clearTimeout(timer[kanal.channel_id])
        timer[kanal.channel_id] = setTimeout(() => {
            delete timer[kanal.channel_id]
            speichern(kanal)
        }, 800)
    })
}, { deep: true })

// ── Anlegen und Löschen ──

const neu = reactive({ type: 'hugoshop', name: '' })
const legtAn = ref(false)

async function anlegen() {
    if (!neu.type || !neu.name.trim()) return
    legtAn.value = true
    try {
        const antwort = await axios.post('/api/shop/', { action: 'createShopChannel', type: neu.type, name: neu.name.trim() })
        if (!antwort.data?.success) {
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.channelConfig.createError').text)
            return
        }
        toasts.success(t('ShopView.channelConfig.created'))
        neu.name = ''
        await laden()
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.channelConfig.createError').text)
    } finally {
        legtAn.value = false
    }
}

const loeschDialog = ref(false)
const zuLoeschen = ref(null)
const loescht = ref(false)

function loeschenFragen(kanal) {
    zuLoeschen.value = kanal
    loeschDialog.value = true
}

async function loeschen() {
    if (!zuLoeschen.value) return
    loescht.value = true
    try {
        const antwort = await axios.post('/api/shop/', { action: 'deleteShopChannel', channel_id: zuLoeschen.value.channel_id })
        if (!antwort.data?.success) {
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.channelConfig.deleteError').text)
            return
        }
        toasts.success(t('ShopView.channelConfig.deleted'))
        loeschDialog.value = false
        await laden()
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.channelConfig.deleteError').text)
    } finally {
        loescht.value = false
    }
}

async function ladeVorlagensaetze() {
    try {
        const antwort = await axios.post('/api/shop/', { action: 'getShopTemplateSets' })
        if (antwort.data?.success) {
            quellen.value = { ...quellen.value, shopTemplateSets: antwort.data.payload?.sets || [] }
        }
    } catch {
        // Ohne Liste bleibt die Auswahl leer — der gespeicherte Satz gilt weiter
    }
}

onMounted(() => {
    laden()
    ladeVorlagensaetze()
})

// Ausstehende Änderungen nicht verlieren, wenn der Reiter gewechselt wird
onBeforeUnmount(() => {
    Object.keys(timer).forEach(id => {
        clearTimeout(timer[id])
        const kanal = kanaele.value.find(k => k.channel_id === Number(id))
        if (kanal) speichern(kanal)
    })
})
</script>
