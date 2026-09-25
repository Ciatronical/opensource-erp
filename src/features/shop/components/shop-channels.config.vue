<!-- src/features/shop/components/shop-channels.config.vue -->
<!--
    Verkaufskanäle in der Firmenkonfiguration (Reiter Shop) —
    dev/shop-verkaufskanaele.md, Schritt 3.

    Je Kanal die Vorgaben für alle Artikel ohne eigenen Aufschlag: Aufschlag
    in Prozent oder als fester Betrag, Rundung des Bruttopreises auf ,99.

    Die Kanäle liegen in sales_channel_shop, nicht in defaults_oserp. Die
    Karte lädt und speichert deshalb selbst über die Shop-API, verzögert wie
    die übrigen Felder der Firmenkonfiguration.

    Mindestens ein Kanal bleibt eingeschaltet (V8) — solange es nur den
    HugoShop gibt, ist er gesperrt. Ändert sich sein Preis, legt das Backend
    den Auftrag an, alle Produktseiten neu zu schreiben (V9).
-->
<template>
    <div>
        <v-row class="mt-6 mb-2">
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
                    {{ kanalName(kanal.type) }}
                    <v-chip size="x-small" variant="flat" class="ml-2" :color="kanal.active ? 'success' : 'grey'">
                        {{ t('ShopView.channelConfig.parts', { anzahl: kanal.parts }) }}
                    </v-chip>
                    <v-spacer />
                    <v-progress-circular v-if="speichert[kanal.channel_id]" indeterminate size="16" width="2" />
                </v-card-title>
            </v-card-item>

            <v-card-text class="pt-0">
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
            </v-card-text>
        </v-card>
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
    /** shop_tax_included aus dem Formular — gilt sofort, auch vor dem Speichern */
    taxIncluded: { type: [Boolean, String, Number], default: null },
})

const i18n = useI18n()
const { t, te } = i18n
const oserp = oserpStore()

const kanaele = ref([])
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
const kanalName = typ => (te(`ShopView.channels.${typ}`) ? t(`ShopView.channels.${typ}`) : typ)

/** Der letzte eingeschaltete Kanal lässt sich nicht abschalten (V8) */
const letzterAktiver = kanal => kanal.active && kanaele.value.filter(k => k.active).length === 1

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
        parts: Number(zeile.parts) || 0,
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
    }
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

onMounted(laden)

// Ausstehende Änderungen nicht verlieren, wenn der Reiter gewechselt wird
onBeforeUnmount(() => {
    Object.keys(timer).forEach(id => {
        clearTimeout(timer[id])
        const kanal = kanaele.value.find(k => k.channel_id === Number(id))
        if (kanal) speichern(kanal)
    })
})
</script>
