<!-- src/features/shop/components/shop-setup.dialog.vue -->
<!--
    Einrichtung des Shops Schritt für Schritt (dev/shop-betrieb.md,
    „Einrichtungsdialoge“): Webseite des Kundenportals wählen — ohne eine
    eingeschaltete Webseite läuft zuerst die Einrichtung des Kundenportals —,
    Verkaufskanal (HugoShop) wählen oder anlegen, Rechnungsstellung, Zahlung,
    Versand, Adressen der Shopseiten mit Prüfung der Hugo-Konfiguration, Paket
    installieren und Einrichtungsprüfung, Kanal einschalten.

    Dieselben Felder wie in der Firmenkonfiguration (shopDefaultsConfig.js)
    und der Kanalkarte (shopChannelSettingsConfig.js), dargestellt über
    shop-config-field — dieselben Hilfetexte und dieselben Aktionen
    (saveCrmDefaults, saveShopChannel, saveShopChannelSettings,
    testShopHugoCms, installPortalUi, getShopStatus). Zum Neukonfigurieren mit
    channel-id öffnen: die gespeicherten Werte sind vorbelegt.
-->
<template>
    <EinrichtungDialog
        :model-value="modelValue"
        :title="t('ShopView.setup.title')"
        icon="mdi-storefront"
        :steps="schritte"
        v-model:step="schritt"
        :next-disabled="!weiterMoeglich"
        :next-loading="speichert"
        :busy="laeuft"
        :next-label="schrittKey === 'fertig' ? t('ShopView.setup.finishButton') : ''"
        @update:model-value="wert => emit('update:modelValue', wert)"
        @next="weiter"
        @back="zurueck"
        @finish="abschliessen"
        @close="emit('cancel')"
    >
        <!-- 1. Webseite des Kundenportals -->
        <template #step-webseite>
            <p class="text-body-1 mb-4">{{ t('ShopView.setup.webseite.intro') }}</p>
            <v-alert v-if="ladeFehler" type="error" variant="tonal" density="compact" class="mb-4">{{ ladeFehler }}</v-alert>
            <v-progress-linear v-if="laedt" indeterminate class="mb-4" />
            <template v-else>
                <v-alert v-if="!aktiveWebseiten.length" type="warning" variant="tonal" density="compact" class="mb-4" icon="mdi-account-circle-outline">
                    <div>{{ t('ShopView.setup.webseite.none') }}</div>
                    <v-btn color="primary" variant="tonal" size="small" class="mt-2" prepend-icon="mdi-play" @click="portalOffen = true">
                        {{ t('ShopView.setup.webseite.setupPortal') }}
                    </v-btn>
                </v-alert>
                <v-radio-group v-else v-model="websiteId" hide-details>
                    <v-card
                        v-for="seite in aktiveWebseiten"
                        :key="seite.id"
                        variant="outlined"
                        class="mb-2 auswahl-karte"
                        :class="{ 'auswahl-karte--aktiv': websiteId === seite.id }"
                        @click="websiteId = seite.id"
                    >
                        <v-card-text class="d-flex align-center ga-3 py-2">
                            <v-radio :value="seite.id" hide-details />
                            <div>
                                <div class="font-weight-medium">{{ seite.name }}</div>
                                <div class="text-caption text-medium-emphasis">{{ seite.base_url || '' }}</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-radio-group>
                <div class="mt-3">
                    <v-btn variant="text" size="small" prepend-icon="mdi-account-circle-outline" @click="portalOffen = true">
                        {{ t('ShopView.setup.webseite.portalAgain') }}
                    </v-btn>
                </div>
            </template>
        </template>

        <!-- 2. Verkaufskanal -->
        <template #step-kanal>
            <p class="text-body-1 mb-2">{{ t('ShopView.setup.kanal.intro') }}</p>
            <Erklaerung :text="t('ShopView.setup.kanal.explain')" />
            <v-radio-group v-model="kanalAuswahl" hide-details>
                <v-card
                    v-for="k in kanaeleDerWebseite"
                    :key="k.channel_id"
                    variant="outlined"
                    class="mb-2 auswahl-karte"
                    :class="{ 'auswahl-karte--aktiv': kanalAuswahl === k.channel_id }"
                    @click="kanalAuswahl = k.channel_id"
                >
                    <v-card-text class="d-flex align-center ga-3 py-2">
                        <v-radio :value="k.channel_id" hide-details />
                        <div>
                            <div class="font-weight-medium">{{ k.name }}</div>
                            <div class="text-caption text-medium-emphasis">
                                {{ t('ShopView.setup.kanal.parts', { count: k.parts }) }} ·
                                {{ t(k.active ? 'ShopView.channelConfig.active' : 'ShopView.setup.kanal.inactive') }}
                            </div>
                        </div>
                    </v-card-text>
                </v-card>
                <v-card
                    variant="outlined"
                    class="mb-2 auswahl-karte"
                    :class="{ 'auswahl-karte--aktiv': kanalAuswahl === 'neu' }"
                    @click="kanalAuswahl = 'neu'"
                >
                    <v-card-text class="d-flex align-center ga-3 py-2">
                        <v-radio value="neu" hide-details />
                        <div class="flex-grow-1">
                            <div class="font-weight-medium">{{ t('ShopView.setup.kanal.new') }}</div>
                            <v-text-field
                                v-if="kanalAuswahl === 'neu'"
                                v-model="kanalName"
                                :label="t('ShopView.channelConfig.name')"
                                autocomplete="off"
                                class="mt-2"
                                style="max-width: 40ch"
                                @click.stop
                            />
                        </div>
                    </v-card-text>
                </v-card>
            </v-radio-group>
        </template>

        <!-- 3. Rechnungsstellung -->
        <template #step-rechnung>
            <p class="text-body-1 mb-2">{{ t('ShopView.setup.rechnung.intro') }}</p>
            <Erklaerung :text="t('ShopView.setup.rechnung.explain')" />
            <v-row dense>
                <v-col v-for="name in RECHNUNG" :key="name" cols="12" sm="6">
                    <ShopConfigField :field="mandantFeld(name)" :werte="mandant" :quellen="quellen" :gesetzt="mandantGesetzt" />
                </v-col>
            </v-row>
        </template>

        <!-- 4. Zahlung -->
        <template #step-zahlung>
            <p class="text-body-1 mb-2">{{ t('ShopView.setup.zahlung.intro') }}</p>
            <div class="text-subtitle-2 mt-2">{{ t('crm_fields.shopPayment') }}</div>
            <Erklaerung :text="t('ShopView.setup.zahlung.bank')" />
            <v-row dense>
                <v-col v-for="name in BANK" :key="name" cols="12" sm="6">
                    <ShopConfigField :field="mandantFeld(name)" :werte="mandant" :quellen="quellen" :gesetzt="mandantGesetzt" />
                </v-col>
            </v-row>
            <div class="text-subtitle-2 mt-4">{{ t('crm_fields.shopPaypal') }}</div>
            <Erklaerung :text="t('ShopView.setup.zahlung.paypal')" />
            <ShopConfigField :field="mandantFeld('shop_paypal_brand_name')" :werte="mandant" :quellen="quellen" :gesetzt="mandantGesetzt" />
            <ShopConfigField :field="kanalFeld('paypal_sandbox')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            <template v-if="kanalWerte.paypal_sandbox">
                <ShopConfigField :field="kanalFeld('paypal_sandbox_client_id')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
                <ShopConfigField :field="kanalFeld('paypal_sandbox_secret')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            </template>
            <template v-else>
                <ShopConfigField :field="kanalFeld('paypal_live_client_id')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
                <ShopConfigField :field="kanalFeld('paypal_live_secret')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            </template>
            <ShopConfigField :field="kanalFeld('paypal_payment_method_preference')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            <v-alert v-if="!paypalVollstaendig" type="info" variant="tonal" density="compact" class="mt-2">
                {{ t('ShopView.setup.zahlung.paypalOptional') }}
            </v-alert>
        </template>

        <!-- 5. Versand -->
        <template #step-versand>
            <p class="text-body-1 mb-2">{{ t('ShopView.setup.versand.intro') }}</p>
            <Erklaerung :text="t('ShopView.setup.versand.explain')" />
            <v-alert v-if="status?.company_country_missing" type="warning" variant="tonal" density="compact" class="mb-3">
                <div class="d-flex flex-wrap align-center ga-2">
                    <span>{{ t('ShopView.status.companyCountryMissing') }}</span>
                    <v-spacer />
                    <v-btn size="small" variant="tonal" prepend-icon="mdi-domain" :to="{ name: 'client-defaults', query: { tab: 'company', focus: 'address_country' } }" target="_blank">
                        {{ t('ShopView.status.companyCountryOpen') }}
                    </v-btn>
                </div>
            </v-alert>
            <v-alert :type="status?.shipping_missing ? 'warning' : 'success'" variant="tonal" density="compact" class="mb-3">
                <div class="d-flex flex-wrap align-center ga-2">
                    <span>{{ t(status?.shipping_missing ? 'ShopView.setup.versand.methodsMissing' : 'ShopView.setup.versand.methodsOk') }}</span>
                    <v-spacer />
                    <v-btn size="small" variant="tonal" prepend-icon="mdi-truck-delivery-outline" :to="{ name: 'shop-shipping' }" target="_blank">
                        {{ t('ShopView.shippingConfig.open') }}
                    </v-btn>
                    <v-btn size="small" variant="text" prepend-icon="mdi-refresh" :loading="statusLaedt" @click="statusLaden">
                        {{ t('ShopView.setup.versand.recheck') }}
                    </v-btn>
                </div>
            </v-alert>
            <v-row dense>
                <v-col cols="12" sm="6">
                    <v-text-field
                        v-model="kanal.free_shipping_from"
                        type="number"
                        min="0"
                        step="0.01"
                        :label="t('ShopView.channelConfig.freeShippingFrom')"
                        :hint="t('ShopView.channelConfig.freeShippingFromHint')"
                        persistent-hint
                        variant="outlined"
                        density="compact"
                    />
                </v-col>
                <v-col cols="12" sm="6">
                    <v-autocomplete
                        v-model="kanal.countries"
                        :items="laenderAuswahl"
                        multiple
                        chips
                        closable-chips
                        :label="t('ShopView.channelConfig.countries')"
                        :hint="t('ShopView.channelConfig.countriesHint')"
                        persistent-hint
                        variant="outlined"
                        density="compact"
                    />
                </v-col>
            </v-row>
        </template>

        <!-- 6. Seiten des Shops -->
        <template #step-seiten>
            <p class="text-body-1 mb-2">{{ t('ShopView.setup.seiten.intro') }}</p>
            <Erklaerung :text="t('ShopView.setup.seiten.explain')" />
            <v-row dense>
                <v-col v-for="name in ADRESSEN" :key="name" cols="12" :sm="['category_taxonomy', 'category_title'].includes(name) ? 6 : 12">
                    <ShopConfigField :field="kanalFeld(name)" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
                </v-col>
            </v-row>
            <div class="text-subtitle-2 mt-4">{{ t('crm_fields.shopPublish') }}</div>
            <Erklaerung :text="t('ShopView.setup.seiten.publish')" />
            <ShopConfigField :field="kanalFeld('auto_publish')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            <ShopConfigField :field="kanalFeld('channel_off_pages')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            <div class="text-subtitle-2 mt-4">{{ t('crm_fields.shopMail') }}</div>
            <ShopConfigField :field="kanalFeld('invoice_mail_subject')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            <ShopConfigField :field="kanalFeld('withdrawal_mail_to')" :werte="kanalWerte" :quellen="quellen" :gesetzt="kanalGesetzt" />
            <div class="d-flex flex-wrap align-center ga-3 mt-4">
                <v-btn color="primary" variant="tonal" prepend-icon="mdi-lan-connect" :loading="hugocmsPrueft" @click="hugocmsPruefen">
                    {{ t('crm_fields.shopHugoCmsTest') }}
                </v-btn>
                <span class="text-caption text-medium-emphasis">{{ t('ShopView.setup.seiten.testHint') }}</span>
            </div>
            <v-alert v-if="hugocmsErgebnis" :type="hugocmsErgebnis.art" variant="tonal" density="compact" class="mt-3">
                <div v-for="(zeile, nr) in hugocmsErgebnis.zeilen" :key="nr">{{ zeile }}</div>
            </v-alert>
            <template v-if="siteBefunde">
                <div class="text-subtitle-2 mt-4">{{ t('crm_fields.shopSiteCheck') }}</div>
                <v-alert v-if="!siteBefunde.length" type="success" variant="tonal" density="compact" class="mt-2">{{ t('crm_fields.shopSiteCheckOk') }}</v-alert>
                <v-alert v-for="(befund, nr) in siteBefunde" :key="nr" :type="befund.art" variant="tonal" density="compact" class="mt-2">
                    <div>{{ t(`crm_fields.shopSiteCheck_${befund.code}`, befund.params || {}) }}</div>
                    <template v-if="befund.fix">
                        <div class="text-caption mt-2">{{ t('crm_fields.shopSiteCheckFix') }}</div>
                        <pre class="text-body-2 mt-1" style="white-space: pre-wrap">{{ befund.fix }}</pre>
                    </template>
                </v-alert>
            </template>
        </template>

        <!-- 7. Paket und Prüfung -->
        <template #step-paket>
            <p class="text-body-1 mb-3">{{ t('ShopView.setup.paket.intro') }}</p>
            <div class="text-subtitle-2 mb-1">{{ t('ShopView.setup.paket.step1') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-2">{{ t('ShopView.setup.paket.installHint') }}</div>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-package-variant-closed" :loading="installiert" @click="installieren">
                {{ t('WebsitesView.install') }}
            </v-btn>
            <v-alert v-if="installErgebnis" :type="installErgebnis.art" variant="tonal" density="compact" class="mt-3">
                <div v-for="(zeile, nr) in installErgebnis.zeilen" :key="nr">{{ zeile }}</div>
            </v-alert>
            <div class="text-subtitle-2 mt-6 mb-1">{{ t('ShopView.setup.paket.step2') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-2">{{ t('ShopView.setup.paket.checkHint') }}</div>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-check-decagram-outline" :loading="statusLaedt" @click="statusLaden">
                {{ t('ShopView.setup.paket.check') }}
            </v-btn>
            <template v-if="status">
                <v-alert v-if="kanalBlockiert.length" type="warning" variant="tonal" density="compact" class="mt-3">
                    <div>{{ t('ShopView.status.notReadyHint') }}</div>
                    <ul class="mt-1"><li v-for="punkt in kanalBlockiert" :key="punkt">{{ feldName(punkt) }}</li></ul>
                </v-alert>
                <v-alert v-else type="success" variant="tonal" density="compact" class="mt-3">{{ t('ShopView.setup.paket.ready') }}</v-alert>
                <v-alert v-if="kanalHinweise.length" type="info" variant="tonal" density="compact" class="mt-2">
                    <div>{{ t('ShopView.status.hintsChannel', { name: kanal.name }) }}</div>
                    <ul class="mt-1"><li v-for="punkt in kanalHinweise" :key="punkt">{{ feldName(punkt) }}</li></ul>
                </v-alert>
            </template>
        </template>

        <!-- 8. Fertig -->
        <template #step-fertig>
            <p class="text-body-1 mb-3">{{ t('ShopView.setup.fertig.intro', { name: kanal.name }) }}</p>
            <v-list density="compact" class="mb-3">
                <v-list-item prepend-icon="mdi-web" :title="webseiteName" :subtitle="t('ShopView.channelConfig.website')" />
                <v-list-item prepend-icon="mdi-storefront" :title="kanal.name" :subtitle="t('ShopView.channelConfig.name')" />
                <v-list-item prepend-icon="mdi-credit-card-outline" :title="t(kanalWerte.paypal_sandbox ? 'crm_fields.shopPaypalSandboxGroup' : 'crm_fields.shopPaypalLiveGroup')" :subtitle="t('crm_fields.shopPaypal')" />
            </v-list>
            <v-alert v-if="kanalBlockiert.length" type="warning" variant="tonal" density="compact" class="mb-3">
                {{ t('ShopView.setup.fertig.notReady') }}
            </v-alert>
            <v-switch v-model="einschalten" :label="t('ShopView.setup.fertig.activate')" :hint="t('ShopView.setup.fertig.activateHint')" persistent-hint color="primary" />
            <v-alert type="info" variant="tonal" density="compact" class="mt-3" icon="mdi-lightbulb-outline">
                {{ t('ShopView.setup.fertig.nextSteps') }}
            </v-alert>
        </template>
    </EinrichtungDialog>

    <!-- Ohne eingeschaltete Webseite: zuerst das Kundenportal einrichten -->
    <PortalSetupDialog v-model="portalOffen" @done="portalFertig" />
</template>

<script setup>
import { ref, reactive, computed, watch, defineComponent, h } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import EinrichtungDialog from '@/core/components/setup/einrichtung-dialog.vue'
import PortalSetupDialog from '@/core/views/config/tabs/portal/portal-setup.dialog.vue'
import ShopConfigField from '@/core/views/config/tabs/shop-config-field.component.vue'
import shopDefaultsConfig from '@/core/views/config/tabs/shopDefaultsConfig.js'
import { channelSettingFields } from './shopChannelSettingsConfig.js'
import { shopFehler } from '../composables/useShop.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** Kanal zum Neukonfigurieren, 0 = wählen oder anlegen */
    channelId: { type: Number, default: 0 },
})
const emit = defineEmits(['update:modelValue', 'done', 'cancel'])

const i18n = useI18n()
const { t, te } = i18n
const oserp = oserpStore()

const Erklaerung = defineComponent({
    props: { text: { type: String, default: '' } },
    setup(p) {
        return () => h('div', { class: 'erklaerung text-body-2 mb-3', style: 'white-space: pre-line' }, p.text)
    },
})

const SCHRITTE = ['webseite', 'kanal', 'rechnung', 'zahlung', 'versand', 'seiten', 'paket', 'fertig']
const schritte = computed(() => SCHRITTE.map(key => ({ key, title: t(`ShopView.setup.steps.${key}`) })))
const schritt = ref(1)
const schrittKey = computed(() => SCHRITTE[schritt.value - 1])

const RECHNUNG = ['shop_contact_login', 'shop_target_account', 'shop_incoming_account', 'shop_standard_taxzone',
                  'shop_standard_currency', 'shop_tax_included', 'shop_active_price_source', 'shop_stock_bin_id']
const BANK = ['shop_payment_account_owner', 'shop_payment_bank', 'shop_payment_iban', 'shop_payment_bic']
const ADRESSEN = ['products_link', 'category_link', 'category_taxonomy', 'category_title', 'thumbnails_link', 'images_link', 'downloads_link']
const WAHR = [true, 't', 'true', '1', 1, 'y', 'yes']

// ── Daten ──
const laedt = ref(false)
const ladeFehler = ref('')
const webseiten = ref([])
const kanaele = ref([])
const secrets = ref({})
const templateSets = ref([])
const backendUrlVorschlag = ref('')
const alleLaender = ref([])
const quellen = ref({ shopStockBins: [], templateSets: [] })
const status = ref(null)
const statusLaedt = ref(false)

const websiteId = ref(0)
const aktiveWebseiten = computed(() => webseiten.value.filter(s => s.active))
const webseiteName = computed(() => webseiten.value.find(s => s.id === websiteId.value)?.name || '')
const kanalAuswahl = ref('neu')
const kanalName = ref('')
const kanal = reactive({ channel_id: 0, name: '', active: false, free_shipping_from: '', countries: [], website_id: 0, parts: 0 })
const kanaeleDerWebseite = computed(() => kanaele.value.filter(k => k.type === 'hugoshop' && (!k.website_id || k.website_id === websiteId.value)))

const alleMandantFelder = shopDefaultsConfig.filter(f => f.type !== 'headline' && f.type !== 'component')
function mandantFeld(name) {
    return alleMandantFelder.find(f => f.name === name) || { name, type: 'input', label: name }
}
const mandant = reactive({})
const mandantGesetzt = ref({})
let mandantZuletzt = ''

const alleKanalFelder = channelSettingFields('hugoshop')
function kanalFeld(name) {
    return alleKanalFelder.find(f => f.name === name) || { name, type: 'input', label: name }
}
const kanalWerte = reactive({})
const kanalGesetzt = ref({})
let kanalZuletzt = ''

function alsObjekt(wert) {
    if (wert && typeof wert === 'object') return wert
    try { return JSON.parse(wert || '{}') || {} } catch { return {} }
}
function alsListe(wert) {
    if (Array.isArray(wert)) return wert
    try { const l = JSON.parse(wert || '[]'); return Array.isArray(l) ? l : [] } catch { return [] }
}
function fehler(antwort, ersatz) {
    return shopFehler(antwort, i18n, ersatz).text
}

async function laden() {
    laedt.value = true
    ladeFehler.value = ''
    try {
        const [kan, geheim, vorlagen, vorgaben, lager] = await Promise.all([
            axios.post('/api/shop/', { action: 'getShopChannels' }),
            axios.post('/api/shop/', { action: 'getShopChannelSecrets', channel_id: 0 }),
            axios.post('/api/shop/', { action: 'getShopTemplateSets' }),
            axios.post('/api/oserp_config/', { action: 'getDefaults' }),
            axios.post('/api/warehouse/', { action: 'getWarehouseOptions' }),
        ])
        if (!kan.data?.success) {
            ladeFehler.value = fehler(kan.data, 'ShopView.channelConfig.loadError')
            return
        }
        const d = kan.data.payload || {}
        webseiten.value = (d.websites || []).map(s => ({ ...s, id: Number(s.id), active: WAHR.includes(s.active) }))
        kanaele.value = (d.channels || []).map(z => ({
            channel_id: Number(z.channel_id), type: String(z.type), name: String(z.name || ''),
            active: WAHR.includes(z.active), website_id: Number(z.website_id) || 0, parts: Number(z.parts) || 0,
            free_shipping_from: z.free_shipping_from === null || z.free_shipping_from === undefined ? '' : String(Number(z.free_shipping_from)),
            countries: alsListe(z.countries).map(String),
            settings: alsObjekt(z.settings), secrets_set: alsObjekt(z.secrets_set),
        }))
        backendUrlVorschlag.value = d.backend_url_suggestion || ''
        alleLaender.value = d.all_countries || []
        const alle = alsObjekt(geheim.data?.payload?.secrets)
        secrets.value = Object.fromEntries(Object.entries(alle).map(([id, v]) => [Number(id), alsObjekt(v)]))
        templateSets.value = vorlagen.data?.payload?.sets || []
        const vg = vorgaben.data?.payload?.results || {}
        const crm = vg.defaults_oserp || {}
        const crmGeheim = vg.defaults_oserp_secrets || {}
        alleMandantFelder.forEach(f => {
            const wert = crm[f.name]
            if (f.type === 'checkbox') mandant[f.name] = WAHR.includes(wert)
            else if (f.type === 'password') mandant[f.name] = ''
            else mandant[f.name] = wert === null || wert === undefined ? '' : String(wert)
        })
        mandantGesetzt.value = Object.fromEntries(Object.keys(crmGeheim).map(k => [k, true]))
        mandantZuletzt = JSON.stringify(mandantNutzdaten())
        const warenlager = lager.data?.success ? (lager.data.payload?.results?.warehouses || []) : []
        quellen.value = {
            templateSets: templateSets.value,
            shopStockBins: warenlager.flatMap(l => (l.bins || []).map(p => ({ value: String(p.id), title: `${l.description} – ${p.description}` }))),
        }
        // Vorbelegung: Kanal zum Neukonfigurieren, sonst der einzige HugoShop, sonst neu
        const vorgabe = kanaele.value.find(k => k.channel_id === props.channelId && k.type === 'hugoshop')
            || (kanaele.value.filter(k => k.type === 'hugoshop').length === 1 ? kanaele.value.find(k => k.type === 'hugoshop') : null)
        if (vorgabe) {
            kanalAuswahl.value = vorgabe.channel_id
            if (vorgabe.website_id && aktiveWebseiten.value.some(s => s.id === vorgabe.website_id)) websiteId.value = vorgabe.website_id
        }
        if (!websiteId.value && aktiveWebseiten.value.length === 1) websiteId.value = aktiveWebseiten.value[0].id
        await statusLaden()
    } catch (e) {
        ladeFehler.value = fehler(e?.response?.data, 'ShopView.channelConfig.loadError')
    } finally {
        laedt.value = false
    }
}

async function statusLaden() {
    statusLaedt.value = true
    try {
        const antwort = await axios.post('/api/shop/', { action: 'getShopStatus' })
        status.value = antwort.data?.success ? (antwort.data.payload || null) : null
    } catch {
        status.value = null
    } finally {
        statusLaedt.value = false
    }
}

/** Werte des gewählten Kanals in die Felder */
function kanalUebernehmen(id) {
    const k = kanaele.value.find(x => x.channel_id === id)
    if (!k) return
    Object.assign(kanal, { channel_id: k.channel_id, name: k.name, active: k.active, free_shipping_from: k.free_shipping_from,
                           countries: [...k.countries], website_id: k.website_id, parts: k.parts })
    const geheim = secrets.value[id] || {}
    alleKanalFelder.forEach(f => {
        const wert = k.settings?.[f.name]
        if (f.type === 'checkbox') kanalWerte[f.name] = WAHR.includes(wert)
        else if (f.type === 'password') kanalWerte[f.name] = String(geheim[f.name] ?? '')
        else kanalWerte[f.name] = wert === null || wert === undefined ? '' : String(wert)
    })
    // Neuer Kanal: Testumgebung von PayPal, bis Zugangsdaten für den Echtbetrieb da sind
    if (!Object.keys(k.settings || {}).length) kanalWerte.paypal_sandbox = true
    kanalGesetzt.value = { ...k.secrets_set }
    kanalZuletzt = JSON.stringify(kanalNutzdaten())
}
function kanalNutzdaten() {
    const settings = {}
    const geheim = {}
    alleKanalFelder.forEach(f => {
        const wert = kanalWerte[f.name]
        if (f.type === 'password') { if (String(wert || '').trim() !== '') geheim[f.name] = String(wert) }
        else if (f.type === 'checkbox') settings[f.name] = wert ? '1' : '0'
        else settings[f.name] = wert === null || wert === undefined ? '' : String(wert)
    })
    return { settings, secrets: geheim }
}
function mandantNutzdaten() {
    const config = {}
    alleMandantFelder.forEach(f => {
        const wert = mandant[f.name]
        if (f.type === 'password') { if (String(wert || '').trim() !== '') config[f.name] = String(wert) }
        else if (f.type === 'checkbox') config[f.name] = wert ? '1' : '0'
        else config[f.name] = wert === null || wert === undefined ? '' : String(wert)
    })
    return config
}

const speichert = ref(false)
async function mandantSpeichern() {
    const daten = mandantNutzdaten()
    const stand = JSON.stringify(daten)
    if (stand === mandantZuletzt) return true
    const antwort = await axios.post('/api/oserp_config/', { action: 'saveCrmDefaults', config: daten })
    if (!antwort.data?.success) { toasts.error(fehler(antwort.data, 'ShopView.channelConfig.saveError')); return false }
    mandantZuletzt = stand
    oserp.loadCompanyConfig().catch(() => {})
    return true
}
async function kanalEinstellungenSpeichern() {
    const daten = kanalNutzdaten()
    const stand = JSON.stringify(daten)
    if (stand === kanalZuletzt) return true
    const antwort = await axios.post('/api/shop/', { action: 'saveShopChannelSettings', channel_id: kanal.channel_id, ...daten })
    if (!antwort.data?.success) { toasts.error(fehler(antwort.data, 'ShopView.channelConfig.settingsSaveError')); return false }
    kanalGesetzt.value = { ...(antwort.data.payload?.secrets_set || {}) }
    kanalZuletzt = JSON.stringify(kanalNutzdaten())
    return true
}
async function kanalSpeichern(felder) {
    const antwort = await axios.post('/api/shop/', { action: 'saveShopChannel', channel_id: kanal.channel_id, ...felder })
    if (!antwort.data?.success) { toasts.error(fehler(antwort.data, 'ShopView.channelConfig.saveError')); return false }
    return true
}

// ── Prüfungen ──
const paypalVollstaendig = computed(() => {
    const p = kanalWerte.paypal_sandbox ? 'paypal_sandbox_' : 'paypal_live_'
    return String(kanalWerte[p + 'client_id'] || '').trim() !== ''
        && (String(kanalWerte[p + 'secret'] || '').trim() !== '' || kanalGesetzt.value[p + 'secret'])
})
const laenderAuswahl = computed(() => {
    let namen = null
    try { namen = new Intl.DisplayNames([i18n.locale.value], { type: 'region' }) } catch { namen = null }
    return alleLaender.value.map(code => ({ value: code, title: namen ? `${namen.of(code) || code} (${code})` : code }))
})
const kanalBlockiert = computed(() => (status.value?.blocking_channels || []).find(k => Number(k.channel_id) === kanal.channel_id)?.missing || [])
const kanalHinweise = computed(() => (status.value?.hint_channels || []).find(k => Number(k.channel_id) === kanal.channel_id)?.hints || [])
function feldName(punkt) {
    const schluessel = `ShopView.status.points.${punkt}`
    return te(schluessel) ? t(schluessel) : punkt
}
const weiterMoeglich = computed(() => {
    switch (schrittKey.value) {
        case 'webseite': return websiteId.value > 0
        case 'kanal': return kanalAuswahl.value === 'neu' ? kanalName.value.trim() !== '' : Number(kanalAuswahl.value) > 0
        case 'rechnung': return ['shop_target_account', 'shop_standard_taxzone', 'shop_standard_currency'].every(n => String(mandant[n] || '').trim() !== '')
        case 'zahlung': return true
        case 'versand': return kanal.free_shipping_from === '' || Number(kanal.free_shipping_from) >= 0
        case 'seiten': return hugocmsOk.value
        default: return true
    }
})
const laeuft = computed(() => speichert.value || hugocmsPrueft.value || installiert.value || statusLaedt.value)

// ── Schrittwechsel ──
async function weiter() {
    if (!weiterMoeglich.value) return
    const key = schrittKey.value
    speichert.value = true
    try {
        if (key === 'kanal') {
            if (kanalAuswahl.value === 'neu') {
                const antwort = await axios.post('/api/shop/', { action: 'createShopChannel', type: 'hugoshop', name: kanalName.value.trim() })
                if (!antwort.data?.success) { toasts.error(fehler(antwort.data, 'ShopView.channelConfig.createError')); return }
                const id = Number(antwort.data.payload?.channel_id) || 0
                await ladeKanaele()
                kanalAuswahl.value = id
            }
            kanalUebernehmen(Number(kanalAuswahl.value))
            if (kanal.website_id !== websiteId.value) {
                if (!(await kanalSpeichern({ website_id: websiteId.value }))) return
                kanal.website_id = websiteId.value
            }
            hugocmsErgebnis.value = null
            hugocmsOk.value = false
            siteBefunde.value = null
            installErgebnis.value = null
        } else if (key === 'rechnung') {
            if (!(await mandantSpeichern())) return
        } else if (key === 'zahlung') {
            if (!(await mandantSpeichern()) || !(await kanalEinstellungenSpeichern())) return
        } else if (key === 'versand') {
            const frei = kanal.free_shipping_from === '' ? null : Number(kanal.free_shipping_from)
            if (!(await kanalSpeichern({ free_shipping_from: frei, countries: [...kanal.countries] }))) return
        } else if (key === 'seiten') {
            if (!(await kanalEinstellungenSpeichern())) return
        }
        if (schritt.value < SCHRITTE.length) schritt.value += 1
    } finally {
        speichert.value = false
    }
}
function zurueck() {
    if (schritt.value > 1) schritt.value -= 1
}
async function ladeKanaele() {
    const kan = await axios.post('/api/shop/', { action: 'getShopChannels' })
    const d = kan.data?.payload || {}
    kanaele.value = (d.channels || []).map(z => ({
        channel_id: Number(z.channel_id), type: String(z.type), name: String(z.name || ''),
        active: WAHR.includes(z.active), website_id: Number(z.website_id) || 0, parts: Number(z.parts) || 0,
        free_shipping_from: z.free_shipping_from === null || z.free_shipping_from === undefined ? '' : String(Number(z.free_shipping_from)),
        countries: alsListe(z.countries).map(String),
        settings: alsObjekt(z.settings), secrets_set: alsObjekt(z.secrets_set),
    }))
}

// ── Kundenportal zuerst ──
const portalOffen = ref(false)
async function portalFertig(id) {
    await laden()
    if (id && aktiveWebseiten.value.some(s => s.id === id)) websiteId.value = id
}

// ── HugoCMS und Hugo-Konfiguration des Shops ──
const hugocmsPrueft = ref(false)
const hugocmsErgebnis = ref(null)
const hugocmsOk = ref(false)
const siteBefunde = ref(null)
async function hugocmsPruefen() {
    hugocmsPrueft.value = true
    hugocmsErgebnis.value = null
    hugocmsOk.value = false
    siteBefunde.value = null
    try {
        if (!(await kanalEinstellungenSpeichern())) return
        const antwort = await axios.post('/api/shop/', { action: 'testShopHugoCms', channel_id: kanal.channel_id })
        if (!antwort.data?.success) {
            hugocmsErgebnis.value = { art: 'error', zeilen: [antwort.data?.debug || antwort.data?.text || t('crm_fields.shopHugoCmsFailed')] }
            return
        }
        const stand = antwort.data.payload || {}
        const zeilen = [t('crm_fields.shopHugoCmsOk')]
        let art = 'success'
        if (!stand.buildable) { zeilen.push(t('crm_fields.shopHugoCmsNotBuildable')); art = 'warning' }
        if (false === stand.onlineShop) { zeilen.push(t('WebsitesView.hugocmsShopOff')); art = 'warning' }
        if (stand.grants_error) { zeilen.push(stand.grants_error); art = 'error' }
        const befunde = Array.isArray(stand.site_checks) ? stand.site_checks : null
        if (befunde && befunde.some(b => b.art === 'error')) art = 'error'
        hugocmsErgebnis.value = { art, zeilen }
        siteBefunde.value = befunde
        hugocmsOk.value = art !== 'error'
    } catch (e) {
        hugocmsErgebnis.value = { art: 'error', zeilen: [e?.message || t('crm_fields.shopHugoCmsFailed')] }
    } finally {
        hugocmsPrueft.value = false
    }
}

// ── Paket ──
const installiert = ref(false)
const installErgebnis = ref(null)
async function installieren() {
    installiert.value = true
    installErgebnis.value = null
    try {
        const antwort = await axios.post('/api/shop/', { action: 'installPortalUi', channel_id: kanal.channel_id, start: true })
        if (!antwort.data?.success) {
            installErgebnis.value = { art: 'error', zeilen: [fehler(antwort.data, 'ShopView.errors.API_ERROR')] }
            return
        }
        const d = antwort.data.payload || {}
        installErgebnis.value = d.started
            ? { art: 'success', zeilen: [t('ShopView.setup.paket.installStarted')] }
            : { art: 'info', zeilen: [t(d.running ? 'ShopView.setup.paket.installRunning' : 'ShopView.setup.paket.installQueued')] }
        await statusLaden()
    } catch (e) {
        installErgebnis.value = { art: 'error', zeilen: [e?.message || t('ShopView.errors.API_ERROR')] }
    } finally {
        installiert.value = false
    }
}

// ── Abschluss ──
const einschalten = ref(true)
async function abschliessen() {
    speichert.value = true
    try {
        if (einschalten.value !== kanal.active) {
            if (!(await kanalSpeichern({ active: einschalten.value }))) return
        }
        toasts.success(t('ShopView.setup.fertig.done'))
        emit('done', kanal.channel_id)
        emit('update:modelValue', false)
    } finally {
        speichert.value = false
    }
}

watch(() => props.modelValue, offen => {
    if (!offen) return
    schritt.value = 1
    websiteId.value = 0
    kanalAuswahl.value = 'neu'
    kanalName.value = ''
    hugocmsErgebnis.value = null
    hugocmsOk.value = false
    siteBefunde.value = null
    installErgebnis.value = null
    laden()
}, { immediate: true })
</script>

<style scoped>
.auswahl-karte {
    cursor: pointer;
}
.auswahl-karte--aktiv {
    border-color: rgb(var(--v-theme-primary));
}
.auswahl-karte :deep(.v-selection-control) {
    flex: 0 0 auto;
}
.erklaerung {
    border-left: 3px solid rgba(var(--v-theme-primary), 0.5);
    padding: 6px 12px;
    background: rgba(var(--v-theme-primary), 0.04);
    border-radius: 0 var(--oserp-radius, 4px) var(--oserp-radius, 4px) 0;
}
</style>
