<!-- src/core/views/config/tabs/portal/portal-setup.dialog.vue -->
<!--
    Einrichtung des Kundenportals Schritt für Schritt (dev/kundenportal.md,
    „Einrichtungsdialoge“): Webseite wählen oder anlegen, Webadresse, HugoCMS
    (mit Verbindungsprüfung), Zugang, Seiten, Funktionen, Paket installieren
    und Weiterleiter prüfen, einschalten. Jeder Schritt erklärt, worum es
    geht, und speichert beim Weitergehen; weiter geht es erst, wenn die
    Angaben stimmen bzw. die Prüfung bestanden ist.

    Dieselben Felder wie in der Karte der Webseite (websiteSettingsConfig.js,
    dargestellt über shop-config-field) — dieselben Hilfetexte, dieselben
    Aktionen (saveWebsiteSettings, testWebsiteHugoCms, installWebsitePackage,
    testWebsiteBackendUrl). Zum Neukonfigurieren mit website-id öffnen: dann
    sind die gespeicherten Werte vorbelegt.
-->
<template>
    <EinrichtungDialog
        :model-value="modelValue"
        :title="t('WebsitesView.setup.title')"
        icon="mdi-account-circle-outline"
        :steps="schritte"
        v-model:step="schritt"
        :next-disabled="!weiterMoeglich"
        :next-loading="speichert"
        :busy="laeuft"
        :next-label="schrittKey === 'fertig' ? t('WebsitesView.setup.finishButton') : ''"
        @update:model-value="wert => emit('update:modelValue', wert)"
        @next="weiter"
        @back="zurueck"
        @finish="abschliessen"
        @close="emit('cancel')"
    >
        <!-- 1. Webseite -->
        <template #step-webseite>
            <p class="text-body-1 mb-4">{{ t('WebsitesView.setup.webseite.intro') }}</p>
            <v-alert type="info" variant="tonal" density="compact" class="mb-4" icon="mdi-lightbulb-outline">
                {{ t('WebsitesView.setup.webseite.hint') }}
            </v-alert>
            <v-alert v-if="ladeFehler" type="error" variant="tonal" density="compact" class="mb-4">{{ ladeFehler }}</v-alert>
            <v-progress-linear v-if="laedt" indeterminate class="mb-4" />
            <v-radio-group v-else v-model="auswahl" hide-details>
                <v-card
                    v-for="seite in webseiten"
                    :key="seite.id"
                    variant="outlined"
                    class="mb-2 auswahl-karte"
                    :class="{ 'auswahl-karte--aktiv': auswahl === seite.id }"
                    @click="auswahl = seite.id"
                >
                    <v-card-text class="d-flex align-center ga-3 py-2">
                        <v-radio :value="seite.id" hide-details />
                        <div>
                            <div class="font-weight-medium">{{ seite.name }}</div>
                            <div class="text-caption text-medium-emphasis">
                                {{ seite.settings?.base_url || t('WebsitesView.setup.webseite.noAddress') }}
                                · {{ t(seite.active ? 'WebsitesView.active' : 'WebsitesView.setup.webseite.inactive') }}
                            </div>
                        </div>
                    </v-card-text>
                </v-card>
                <v-card
                    variant="outlined"
                    class="mb-2 auswahl-karte"
                    :class="{ 'auswahl-karte--aktiv': auswahl === 'neu' }"
                    @click="auswahl = 'neu'"
                >
                    <v-card-text class="d-flex align-center ga-3 py-2">
                        <v-radio value="neu" hide-details />
                        <div class="flex-grow-1">
                            <div class="font-weight-medium">{{ t('WebsitesView.setup.webseite.new') }}</div>
                            <v-text-field
                                v-if="auswahl === 'neu'"
                                v-model="neuName"
                                :label="t('WebsitesView.name')"
                                :hint="t('WebsitesView.setup.webseite.nameHint')"
                                persistent-hint
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

        <!-- 2. Webadresse -->
        <template #step-adresse>
            <p class="text-body-1 mb-2">{{ t('WebsitesView.setup.adresse.intro') }}</p>
            <Erklaerung :text="t('WebsitesView.help.baseUrl')" />
            <ShopConfigField :field="feld('base_url')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
            <v-alert v-if="werte.base_url && !gueltigeAdresse(werte.base_url)" type="warning" variant="tonal" density="compact">
                {{ t('WebsitesView.setup.adresse.invalid') }}
            </v-alert>
        </template>

        <!-- 3. HugoCMS -->
        <template #step-hugocms>
            <p class="text-body-1 mb-2">{{ t('WebsitesView.setup.hugocms.intro') }}</p>
            <Erklaerung :text="t('WebsitesView.help.hugocmsUrl')" />
            <ShopConfigField :field="feld('hugocms_url')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt">
                <template #append>
                    <v-btn
                        variant="tonal"
                        size="small"
                        prepend-icon="mdi-arrow-left-bold-box-outline"
                        :disabled="!gueltigeAdresse(werte.base_url)"
                        :title="t('WebsitesView.hugocmsFromBaseUrl_help')"
                        @click="hugocmsAdresseVorbelegen(true)"
                    >
                        {{ t('WebsitesView.hugocmsFromBaseUrl') }}
                    </v-btn>
                </template>
            </ShopConfigField>
            <Erklaerung :text="t('WebsitesView.help.hugocmsKey')" />
            <ShopConfigField :field="feld('hugocms_key')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
            <div class="d-flex flex-wrap align-center ga-3 mt-2">
                <v-btn
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-lan-connect"
                    :loading="hugocmsPrueft"
                    :disabled="!werte.hugocms_url || (!werte.hugocms_key && !gesetzt.hugocms_key)"
                    @click="hugocmsPruefen"
                >
                    {{ t('crm_fields.shopHugoCmsTest') }}
                </v-btn>
                <span class="text-caption text-medium-emphasis">{{ t('WebsitesView.setup.hugocms.testHint') }}</span>
            </div>
            <v-alert v-if="hugocmsErgebnis" :type="hugocmsErgebnis.art" variant="tonal" density="compact" class="mt-3">
                <div v-for="(zeile, nr) in hugocmsErgebnis.zeilen" :key="nr">{{ zeile }}</div>
            </v-alert>
            <template v-if="siteBefunde && siteBefunde.length">
                <div class="text-subtitle-2 mt-4">{{ t('crm_fields.shopSiteCheck') }}</div>
                <v-alert v-for="(befund, nr) in siteBefunde" :key="nr" :type="befund.art" variant="tonal" density="compact" class="mt-2">
                    <div>{{ t(`crm_fields.shopSiteCheck_${befund.code}`, befund.params || {}) }}</div>
                    <template v-if="befund.fix">
                        <div class="text-caption mt-2">{{ t('crm_fields.shopSiteCheckFix') }}</div>
                        <pre class="text-body-2 mt-1" style="white-space: pre-wrap">{{ befund.fix }}</pre>
                    </template>
                </v-alert>
            </template>
        </template>

        <!-- 4. Zugang -->
        <template #step-zugang>
            <p class="text-body-1 mb-2">{{ t('WebsitesView.setup.zugang.intro') }}</p>
            <Erklaerung :text="t('WebsitesView.help.publicKey')" />
            <v-alert v-if="schluesselNeu" type="success" variant="tonal" density="compact" class="mb-2">
                {{ t('WebsitesView.setup.zugang.keyGenerated') }}
            </v-alert>
            <ShopConfigField :field="feld('public_key')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
            <Erklaerung :text="t('WebsitesView.help.backendUrl')" />
            <ShopConfigField :field="feld('backend_url')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
            <v-alert v-if="werte.backend_url && !gueltigeAdresse(werte.backend_url)" type="warning" variant="tonal" density="compact">
                {{ t('WebsitesView.setup.adresse.invalid') }}
            </v-alert>
            <v-expansion-panels variant="accordion" class="mt-4">
                <v-expansion-panel :title="t('WebsitesView.setup.zugang.advanced')">
                    <v-expansion-panel-text>
                        <Erklaerung :text="t('WebsitesView.help.allowedOrigins')" />
                        <ShopConfigField :field="feld('allowed_origins')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>
        </template>

        <!-- 5. Seiten -->
        <template #step-seiten>
            <p class="text-body-1 mb-2">{{ t('WebsitesView.setup.seiten.intro') }}</p>
            <Erklaerung :text="t('WebsitesView.help.pages')" />
            <v-row dense>
                <v-col v-for="name in ['portal_path', 'login_page', 'register_page', 'account_page', 'invoice_page', 'contact_page']" :key="name" cols="12" sm="6">
                    <ShopConfigField :field="feld(name)" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
                </v-col>
            </v-row>
            <v-alert v-if="seitenFehler.length" type="warning" variant="tonal" density="compact" class="mt-2">
                {{ t('WebsitesView.setup.seiten.invalid', { fields: seitenFehler.join(', ') }) }}
            </v-alert>
            <div class="text-body-2 text-medium-emphasis mt-3">{{ t('WebsitesView.setup.seiten.preview') }}</div>
            <ul class="text-body-2 mt-1">
                <li v-for="name in ['login_page', 'register_page', 'account_page', 'invoice_page', 'contact_page']" :key="name">
                    <code>{{ seitenAdresse(name) }}</code> — {{ t(feld(name).label) }}
                </li>
            </ul>
        </template>

        <!-- 6. Funktionen -->
        <template #step-funktionen>
            <p class="text-body-1 mb-2">{{ t('WebsitesView.setup.funktionen.intro') }}</p>
            <Erklaerung :text="t('WebsitesView.help.templateSet')" />
            <ShopConfigField :field="feld('template_set')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
            <Erklaerung :text="t('WebsitesView.help.activationEnabled')" />
            <ShopConfigField :field="feld('activation_enabled')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
            <ShopConfigField v-if="werte.activation_enabled" :field="feld('activate_page')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
            <Erklaerung :text="t('WebsitesView.help.documents')" />
            <v-row dense>
                <v-col v-for="name in belegFelder" :key="name" cols="12" sm="6">
                    <ShopConfigField :field="feld(name)" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
                </v-col>
            </v-row>
            <Erklaerung :text="t('WebsitesView.help.contactMailTo')" />
            <ShopConfigField :field="feld('contact_mail_to')" :werte="werte" :quellen="quellen" :gesetzt="gesetzt" />
        </template>

        <!-- 7. Paket und Weiterleiter -->
        <template #step-paket>
            <p class="text-body-1 mb-3">{{ t('WebsitesView.setup.paket.intro') }}</p>
            <div class="text-subtitle-2 mb-1">{{ t('WebsitesView.setup.paket.step1') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-2">{{ t('WebsitesView.installHint') }}</div>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-package-variant-closed" :loading="installiert" @click="installieren">
                {{ t('WebsitesView.install') }}
            </v-btn>
            <v-alert v-if="installErgebnis" :type="installErgebnis.art" variant="tonal" density="compact" class="mt-3">
                <div v-for="(zeile, nr) in installErgebnis.zeilen" :key="nr">{{ zeile }}</div>
            </v-alert>

            <div class="text-subtitle-2 mt-6 mb-1">{{ t('WebsitesView.setup.paket.step2') }}</div>
            <p class="text-body-2 mb-2">{{ t('WebsitesView.setup.paket.proxyText') }}</p>
            <v-alert type="info" variant="tonal" density="compact" class="mb-2" icon="mdi-file-code-outline">
                <div>{{ t('WebsitesView.setup.paket.proxyFile') }}</div>
                <code class="d-block mt-1">backend/templates-default/portal/standard/kit/static/portal-api/index.php</code>
                <div class="mt-1">→ <code>&lt;Webseite&gt;/oserp-portal/static/portal-api/index.php</code></div>
            </v-alert>
            <v-expansion-panels variant="accordion" class="mb-4">
                <v-expansion-panel :title="t('WebsitesView.mountsHelp.title')">
                    <v-expansion-panel-text>
                        <p class="text-body-2">{{ t('WebsitesView.mountsHelp.text') }}</p>
                        <pre class="text-body-2 pa-2 rounded bg-grey-lighten-4" style="white-space: pre-wrap">{{ MOUNTS_JSON }}</pre>
                        <p class="text-body-2 mt-2" style="white-space: pre-line">{{ t('WebsitesView.mountsHelp.after') }}</p>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>

            <div class="text-subtitle-2 mb-1">{{ t('WebsitesView.setup.paket.step3') }}</div>
            <div class="text-body-2 text-medium-emphasis mb-2">{{ t('WebsitesView.proxyHint') }}</div>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-lan-connect" :loading="backendPrueft" @click="backendPruefen">
                {{ t('crm_fields.shopBackendTest') }}
            </v-btn>
            <v-alert
                v-for="(s, nr) in backendErgebnis"
                :key="nr"
                :type="s.art"
                variant="tonal"
                density="compact"
                class="mt-2"
                style="white-space: pre-line"
            >
                <strong>{{ te(`WebsitesView.backendTestStep.${s.schritt}`) ? t(`WebsitesView.backendTestStep.${s.schritt}`) : t(`crm_fields.shopBackendTestStep_${s.schritt}`) }}:</strong>
                {{ testText(s) }}
            </v-alert>
            <v-alert v-if="backendOk" type="success" variant="tonal" density="compact" class="mt-3">
                {{ t('WebsitesView.setup.paket.allGood') }}
            </v-alert>
        </template>

        <!-- 8. Fertig -->
        <template #step-fertig>
            <p class="text-body-1 mb-3">{{ t('WebsitesView.setup.fertig.intro', { name: seiteName }) }}</p>
            <v-list density="compact" class="mb-3">
                <v-list-item prepend-icon="mdi-web" :title="werte.base_url" :subtitle="t('WebsitesView.fields.baseUrl')" />
                <v-list-item prepend-icon="mdi-web-sync" :title="werte.hugocms_url" :subtitle="t('WebsitesView.fields.hugocmsUrl')" />
                <v-list-item prepend-icon="mdi-login" :title="seitenAdresse('login_page')" :subtitle="t('WebsitesView.fields.loginPage')" />
                <v-list-item prepend-icon="mdi-account" :title="seitenAdresse('account_page')" :subtitle="t('WebsitesView.fields.accountPage')" />
            </v-list>
            <v-alert v-if="!backendOk" type="warning" variant="tonal" density="compact" class="mb-3">
                {{ t('WebsitesView.setup.fertig.notVerified') }}
            </v-alert>
            <v-switch
                v-model="einschalten"
                :label="t('WebsitesView.setup.fertig.activate')"
                :hint="t('WebsitesView.activeHint')"
                persistent-hint
                color="primary"
            />
        </template>
    </EinrichtungDialog>
</template>

<script setup>
import { ref, reactive, computed, watch, defineComponent, h } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import EinrichtungDialog from '@/core/components/setup/einrichtung-dialog.vue'
import ShopConfigField from '../shop-config-field.component.vue'
import { websiteSettingFields } from './websiteSettingsConfig.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** Webseite zum Neukonfigurieren, 0 = wählen oder anlegen */
    websiteId: { type: Number, default: 0 },
})
const emit = defineEmits(['update:modelValue', 'done', 'cancel'])

const { t, te } = useI18n()
const oserp = oserpStore()

/** Erklärungstext als ruhiger Kasten; Absätze der Hilfetexte bleiben erhalten */
const Erklaerung = defineComponent({
    props: { text: { type: String, default: '' } },
    setup(p) {
        return () => h('div', { class: 'erklaerung text-body-2 mb-3', style: 'white-space: pre-line' }, p.text)
    },
})

const SCHRITTE = ['webseite', 'adresse', 'hugocms', 'zugang', 'seiten', 'funktionen', 'paket', 'fertig']
const schritte = computed(() => SCHRITTE.map(key => ({ key, title: t(`WebsitesView.setup.steps.${key}`) })))
const schritt = ref(1)
const schrittKey = computed(() => SCHRITTE[schritt.value - 1])

// ── Daten ──
const laedt = ref(false)
const ladeFehler = ref('')
const webseiten = ref([])
const templateSets = ref([])
const backendUrlVorschlag = ref('')
const secrets = ref({})
const auswahl = ref('neu')
const neuName = ref('')
const websiteId = ref(0)
const seiteName = computed(() => webseiten.value.find(s => s.id === websiteId.value)?.name || neuName.value)
const quellen = computed(() => ({ templateSets: templateSets.value }))

const WAHR = [true, 't', 'true', '1', 1, 'y', 'yes']
const alleFelder = websiteSettingFields().filter(f => !f.extension || oserp.isExtensionEnabled(f.extension))
const belegFelder = alleFelder.filter(f => f.name.startsWith('documents_')).map(f => f.name)
function feld(name) {
    return alleFelder.find(f => f.name === name) || { name, type: 'input', label: name }
}
const werte = reactive({})
const gesetzt = ref({})
let zuletzt = ''

function fehlerText(antwort, ersatz) {
    const code = String(antwort?.text || '').split(':')[0].trim()
    const schluessel = `WebsitesView.errors.${code}`
    if (code && te(schluessel)) return t(schluessel)
    return antwort?.debug || t(ersatz)
}

async function laden() {
    laedt.value = true
    ladeFehler.value = ''
    try {
        const antwort = await axios.post('/api/portal/', { action: 'getWebsites' })
        if (!antwort.data?.success) {
            ladeFehler.value = fehlerText(antwort.data, 'WebsitesView.loadError')
            return
        }
        const daten = antwort.data.payload || {}
        webseiten.value = (daten.websites || []).map(s => ({
            ...s,
            id: Number(s.id),
            active: WAHR.includes(s.active),
            settings: typeof s.settings === 'string' ? JSON.parse(s.settings) : (s.settings || {}),
            secrets_set: typeof s.secrets_set === 'string' ? JSON.parse(s.secrets_set) : (s.secrets_set || {}),
        }))
        templateSets.value = daten.template_sets || []
        backendUrlVorschlag.value = daten.backend_url_suggestion || ''
        const geheim = await axios.post('/api/portal/', { action: 'getWebsiteSecrets', website_id: 0 })
        const alle = geheim.data?.payload?.secrets || {}
        secrets.value = typeof alle === 'string' ? JSON.parse(alle) : alle
        if (props.websiteId && webseiten.value.some(s => s.id === props.websiteId)) {
            auswahl.value = props.websiteId
        } else if (!webseiten.value.length) {
            auswahl.value = 'neu'
        }
    } catch (e) {
        ladeFehler.value = fehlerText(e?.response?.data, 'WebsitesView.loadError')
    } finally {
        laedt.value = false
    }
}

/** Werte der gewählten Webseite in die Felder — wie website-settings.vue */
function uebernehmen(id) {
    const seite = webseiten.value.find(s => s.id === id)
    let geheim = secrets.value?.[id] ?? secrets.value?.[String(id)] ?? {}
    if (typeof geheim === 'string') { try { geheim = JSON.parse(geheim) || {} } catch { geheim = {} } }
    alleFelder.forEach(f => {
        const wert = seite?.settings?.[f.name]
        if (f.type === 'checkbox') werte[f.name] = WAHR.includes(wert)
        else if (f.type === 'password') werte[f.name] = String(geheim?.[f.name] ?? '')
        else werte[f.name] = wert === null || wert === undefined ? '' : String(wert)
    })
    gesetzt.value = { ...(seite?.secrets_set || {}) }
    if (!String(werte.backend_url || '').trim() && backendUrlVorschlag.value) werte.backend_url = backendUrlVorschlag.value
    zuletzt = JSON.stringify(nutzdaten())
}

function nutzdaten() {
    const settings = {}
    const secretsNeu = {}
    alleFelder.forEach(f => {
        const wert = werte[f.name]
        if (f.type === 'password') {
            if (String(wert || '').trim() !== '') secretsNeu[f.name] = String(wert)
        } else if (f.type === 'checkbox') {
            settings[f.name] = wert ? '1' : '0'
        } else {
            settings[f.name] = wert === null || wert === undefined ? '' : String(wert)
        }
    })
    return { settings, secrets: secretsNeu }
}

const speichert = ref(false)
async function speichern() {
    const daten = nutzdaten()
    const stand = JSON.stringify(daten)
    if (stand === zuletzt) return true
    speichert.value = true
    try {
        const antwort = await axios.post('/api/portal/', { action: 'saveWebsiteSettings', website_id: websiteId.value, ...daten })
        if (!antwort.data?.success) {
            toasts.error(fehlerText(antwort.data, 'WebsitesView.saveError'))
            return false
        }
        gesetzt.value = { ...(antwort.data.payload?.secrets_set || {}) }
        zuletzt = JSON.stringify(nutzdaten())
        return true
    } catch (e) {
        toasts.error(fehlerText(e?.response?.data, 'WebsitesView.saveError'))
        return false
    } finally {
        speichert.value = false
    }
}

// ── Prüfungen je Schritt ──
function gueltigeAdresse(wert) {
    try {
        const url = new URL(String(wert || '').trim())
        return ['http:', 'https:'].includes(url.protocol) && !!url.hostname
    } catch {
        return false
    }
}
function hugocmsAdresseVorbelegen(ueberschreiben = false) {
    if (!gueltigeAdresse(werte.base_url)) return
    if (!ueberschreiben && String(werte.hugocms_url || '').trim()) return
    werte.hugocms_url = String(werte.base_url).trim().replace(/\/+$/, '') + '/cms-api/'
}
const seitenNamen = ['login_page', 'register_page', 'account_page', 'invoice_page', 'contact_page']
/** Pfade: nur Kleinbuchstaben, Ziffern, Bindestrich, Schrägstrich; Schrägstrich am Ende */
function pfadGueltig(wert, mitAnfang) {
    const p = String(wert || '').trim()
    if (!p) return false
    if (!/^[a-z0-9\-/]+$/.test(p) || !p.endsWith('/')) return false
    return mitAnfang ? p.startsWith('/') : true
}
const seitenFehler = computed(() => {
    const fehlt = []
    if (!pfadGueltig(werte.portal_path, true)) fehlt.push(t(feld('portal_path').label))
    seitenNamen.forEach(n => { if (!pfadGueltig(werte[n], false)) fehlt.push(t(feld(n).label)) })
    return fehlt
})
function seitenAdresse(name) {
    const seite = String(werte[name] || '')
    const basis = String(werte.base_url || '').replace(/\/+$/, '')
    const pfad = seite.startsWith('/') ? seite : String(werte.portal_path || '/kunden/').replace(/\/+$/, '') + '/' + seite
    return basis + pfad
}

const weiterMoeglich = computed(() => {
    switch (schrittKey.value) {
        case 'webseite': return auswahl.value === 'neu' ? neuName.value.trim() !== '' : Number(auswahl.value) > 0
        case 'adresse': return gueltigeAdresse(werte.base_url)
        case 'hugocms': return hugocmsOk.value
        case 'zugang': return String(werte.public_key || '').trim() !== '' && gueltigeAdresse(werte.backend_url)
        case 'seiten': return seitenFehler.value.length === 0
        case 'funktionen': return !werte.activation_enabled || pfadGueltig(werte.activate_page, false)
        case 'paket': return true
        default: return true
    }
})
const laeuft = computed(() => speichert.value || hugocmsPrueft.value || installiert.value || backendPrueft.value)

// ── Schrittwechsel ──
async function weiter() {
    if (!weiterMoeglich.value) return
    const key = schrittKey.value
    if (key === 'webseite') {
        if (auswahl.value === 'neu') {
            speichert.value = true
            try {
                const antwort = await axios.post('/api/portal/', { action: 'createWebsite', name: neuName.value.trim() })
                if (!antwort.data?.success) {
                    toasts.error(fehlerText(antwort.data, 'WebsitesView.saveError'))
                    return
                }
                const id = Number(antwort.data.payload?.website_id) || 0
                await laden()
                auswahl.value = id
                websiteId.value = id
            } catch (e) {
                toasts.error(fehlerText(e?.response?.data, 'WebsitesView.saveError'))
                return
            } finally {
                speichert.value = false
            }
        } else {
            websiteId.value = Number(auswahl.value)
        }
        uebernehmen(websiteId.value)
        hugocmsErgebnis.value = null
        hugocmsOk.value = false
        siteBefunde.value = null
        backendErgebnis.value = []
        installErgebnis.value = null
    } else if (key === 'adresse') {
        hugocmsAdresseVorbelegen(false)
        if (!(await speichern())) return
    } else if (key === 'hugocms') {
        if (!(await speichern())) return
    } else if (key === 'zugang' || key === 'seiten' || key === 'funktionen') {
        if (!(await speichern())) return
    }
    if (schritt.value < SCHRITTE.length) schritt.value += 1
    if (schrittKey.value === 'zugang') schluesselSicherstellen()
}
function zurueck() {
    if (schritt.value > 1) schritt.value -= 1
}

/** Ohne Schlüssel kein Zugang: beim Betreten des Schritts einen erzeugen */
const schluesselNeu = ref(false)
function schluesselSicherstellen() {
    if (String(werte.public_key || '').trim() || gesetzt.value.public_key) return
    const bytes = crypto.getRandomValues(new Uint8Array(32))
    werte.public_key = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('')
    schluesselNeu.value = true
}

// ── HugoCMS prüfen ──
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
        if (!(await speichern())) return
        const antwort = await axios.post('/api/portal/', {
            action: 'testWebsiteHugoCms', website_id: websiteId.value,
            url: werte.hugocms_url || '', key: werte.hugocms_key || '',
        })
        if (!antwort.data?.success) {
            hugocmsErgebnis.value = { art: 'error', zeilen: [antwort.data?.debug || antwort.data?.text || t('crm_fields.shopHugoCmsFailed')] }
            return
        }
        const stand = antwort.data.payload || {}
        const zeilen = [t('crm_fields.shopHugoCmsOk')]
        let art = 'success'
        if (!stand.buildable) { zeilen.push(t('crm_fields.shopHugoCmsNotBuildable')); art = 'warning' }
        if (stand.grants_error) { zeilen.push(stand.grants_error); art = 'error' }
        hugocmsErgebnis.value = { art, zeilen }
        hugocmsOk.value = art !== 'error'
        siteBefunde.value = Array.isArray(stand.site_checks) ? stand.site_checks : null
    } catch (e) {
        hugocmsErgebnis.value = { art: 'error', zeilen: [e?.message || t('crm_fields.shopHugoCmsFailed')] }
    } finally {
        hugocmsPrueft.value = false
    }
}

// ── Paket und Weiterleiter ──
const installiert = ref(false)
const installErgebnis = ref(null)
async function installieren() {
    installiert.value = true
    installErgebnis.value = null
    try {
        if (!(await speichern())) return
        const antwort = await axios.post('/api/portal/', { action: 'installWebsitePackage', website_id: websiteId.value })
        if (!antwort.data?.success) {
            installErgebnis.value = { art: 'error', zeilen: [fehlerText(antwort.data, 'WebsitesView.installFailed')] }
            return
        }
        const daten = antwort.data.payload || {}
        const zeilen = (daten.messages || []).map(m => m.text)
        installErgebnis.value = daten.ok
            ? { art: 'success', zeilen: [t('WebsitesView.installDone'), ...zeilen] }
            : { art: 'warning', zeilen: [t('WebsitesView.installFailed'), ...zeilen] }
    } catch (e) {
        installErgebnis.value = { art: 'error', zeilen: [fehlerText(e?.response?.data, 'WebsitesView.installFailed')] }
    } finally {
        installiert.value = false
    }
}
const backendPrueft = ref(false)
const backendErgebnis = ref([])
const backendOk = computed(() => backendErgebnis.value.length > 0 && backendErgebnis.value.every(s => s.art === 'success'))
function testText(s) {
    const eigen = `WebsitesView.backendTest.${s.code}`
    return te(eigen) ? t(eigen, s.params || {}) : t(`crm_fields.shopBackendTest_${s.code}`, s.params || {})
}
async function backendPruefen() {
    backendPrueft.value = true
    backendErgebnis.value = []
    try {
        if (!(await speichern())) return
        const antwort = await axios.post('/api/portal/', {
            action: 'testWebsiteBackendUrl', website_id: websiteId.value,
            backend_url: werte.backend_url || '', base_url: werte.base_url || '',
            hugocms_url: werte.hugocms_url || '', key: werte.public_key || '',
        })
        backendErgebnis.value = antwort.data?.success
            ? (antwort.data.payload?.schritte || [])
            : [{ schritt: 'direkt', art: 'error', code: 'FAILED', params: { error: antwort.data?.debug || antwort.data?.text || '' } }]
    } catch (e) {
        backendErgebnis.value = [{ schritt: 'direkt', art: 'error', code: 'FAILED', params: { error: e?.message || '' } }]
    } finally {
        backendPrueft.value = false
    }
}

// ── Abschluss ──
const einschalten = ref(true)
async function abschliessen() {
    speichert.value = true
    try {
        if (!(await speichern())) return
        const seite = webseiten.value.find(s => s.id === websiteId.value)
        if (seite && einschalten.value !== seite.active) {
            const antwort = await axios.post('/api/portal/', { action: 'saveWebsite', website_id: websiteId.value, name: seite.name, active: einschalten.value })
            if (!antwort.data?.success) {
                toasts.error(fehlerText(antwort.data, 'WebsitesView.saveError'))
                return
            }
            oserp.loadCompanyConfig().catch(() => {})
        }
        toasts.success(t('WebsitesView.setup.fertig.done'))
        emit('done', websiteId.value)
        emit('update:modelValue', false)
    } finally {
        speichert.value = false
    }
}

// Beim Öffnen neu laden und vorn beginnen
watch(() => props.modelValue, offen => {
    if (!offen) return
    schritt.value = 1
    websiteId.value = 0
    neuName.value = ''
    schluesselNeu.value = false
    hugocmsErgebnis.value = null
    hugocmsOk.value = false
    installErgebnis.value = null
    backendErgebnis.value = []
    laden()
}, { immediate: true })

const MOUNTS_JSON = `"module": {
    "mounts": [
        { "source": "archetypes", "target": "archetypes" },
        { "source": "assets", "target": "assets" },
        { "source": "content", "target": "content" },
        { "source": "data", "target": "data" },
        { "source": "layouts", "target": "layouts" },
        { "source": "static", "target": "static" },
        { "source": "oserp-portal/static", "target": "static" },
        { "source": "oserp-portal/layouts", "target": "layouts" },
        { "source": "oserp-portal/assets/portal-ui", "target": "assets/portal-ui" },
        { "source": "oserp-portal/content", "target": "content/kunden" }
    ]
}`
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
