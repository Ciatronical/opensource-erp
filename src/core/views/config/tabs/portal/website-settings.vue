<!-- src/core/views/config/tabs/portal/website-settings.vue -->
<!--
    Einstellungen einer Webseite des Kundenportals (dev/kundenportal.md):
    HugoCMS, Zugang (Schlüssel, Herkünfte, Adresse des Portal-Zugangs),
    Adressen der Seiten, Vorlagensatz, Kontaktformular. Die Felder stehen in
    websiteSettingsConfig.js und werden über shop-config-field dargestellt —
    wie die Kanalkarte des Shops, aus der diese Felder stammen.

    Gespeichert wird verzögert nach jeder Änderung (saveWebsiteSettings).
    Die Schlüssel (Geheimnisse) kommen über getWebsiteSecrets mit und stehen
    verdeckt im Feld, damit man sie im Fehlerfall ansehen und mit HugoCMS
    vergleichen kann; ein geleertes Feld behält den gespeicherten Wert.
-->
<template>
    <div>
        <v-alert type="info" variant="tonal" density="compact" class="mb-2">
            <v-icon start size="small">mdi-shield-key-outline</v-icon>
            {{ t('WebsitesView.secretsNotice') }}
        </v-alert>

        <template v-for="(feld, index) in felder" :key="feld.name + '-' + index">
            <div v-if="feld.type === 'headline'" class="text-subtitle-2 text-primary mt-4 pt-4">
                {{ t(feld.label) }}
                <v-divider class="mt-1" />
            </div>

            <v-card v-else-if="feld.type === 'group'" variant="outlined" class="my-3">
                <v-card-item>
                    <template #prepend>
                        <v-icon :icon="feld.icon || 'mdi-folder-outline'" />
                    </template>
                    <v-card-title class="text-subtitle-1">{{ t(feld.label) }}</v-card-title>
                    <v-card-subtitle v-if="feld.tooltip">{{ t(feld.tooltip) }}</v-card-subtitle>
                </v-card-item>

                <v-card-text class="pt-0">
                    <ShopConfigField
                        v-for="unterfeld in feld.fields"
                        :key="unterfeld.name"
                        :field="unterfeld"
                        :werte="werte"
                        :quellen="quellen"
                        :gesetzt="gesetzt"
                    />

                    <template v-if="feld.action === 'hugocmsTest'">
                        <v-divider class="my-4" />
                        <v-btn
                            variant="tonal"
                            size="small"
                            prepend-icon="mdi-lan-connect"
                            :loading="hugocmsPrueft"
                            @click="hugocmsPruefen"
                        >
                            {{ t('crm_fields.shopHugoCmsTest') }}
                        </v-btn>
                        <v-alert
                            v-if="hugocmsErgebnis"
                            :type="hugocmsErgebnis.art"
                            variant="tonal"
                            density="compact"
                            class="mt-3"
                        >
                            <div v-for="(zeile, nr) in hugocmsErgebnis.zeilen" :key="nr">{{ zeile }}</div>
                        </v-alert>

                        <!-- Freigaben in HugoCMS, nur lesend -->
                        <div v-if="freigaben" class="pt-1 mt-4" style="max-width: 70ch">
                            <div class="text-subtitle-2">{{ t('crm_fields.shopHugoCmsGrants') }}</div>
                            <div class="text-caption text-medium-emphasis mb-2">{{ t('WebsitesView.fields.grants_help') }}</div>
                            <v-text-field
                                v-for="freigabe in FREIGABEN.filter(f => typeof freigaben[f.feld] === 'string')"
                                :key="freigabe.feld"
                                :model-value="freigabe.datei ? freigaben[freigabe.feld] : freigaben[freigabe.feld] + '/'"
                                :label="t(freigabe.label)"
                                :prepend-inner-icon="freigabe.icon"
                                readonly
                                variant="outlined"
                                density="compact"
                                hide-details
                                class="pt-2 mb-2"
                            />
                        </div>

                        <v-divider class="mt-4" />
                        <!-- Signaturschlüssel dieser Installation: in HugoCMS eintragen,
                             dann überträgt das Portal Weiterleiter und 404-Seite selbst -->
                        <v-text-field
                            v-if="signingKey"
                            :model-value="signingKey"
                            :label="t('crm_fields.shopSigningKey')"
                            :hint="t('WebsitesView.fields.signingKey_help')"
                            persistent-hint
                            readonly
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-shield-key-outline"
                            class="mt-4"
                            style="max-width: 70ch"
                            @focus="$event.target.select()"
                        >
                            <template #append-inner>
                                <v-btn
                                    :icon="signaturKopiert ? 'mdi-check' : 'mdi-content-copy'"
                                    variant="text"
                                    size="small"
                                    :title="t('crm_fields.shopSigningKeyCopy')"
                                    @click="signaturKopieren"
                                />
                            </template>
                        </v-text-field>
                        <div v-else class="text-caption text-medium-emphasis mt-4">
                            {{ t('crm_fields.shopSigningKeyMissing') }}
                        </div>
                    </template>
                </v-card-text>
            </v-card>

            <template v-else>
                <ShopConfigField
                    :field="feld"
                    :werte="werte"
                    :quellen="quellen"
                    :gesetzt="gesetzt"
                />

                <!-- Adresse des Portal-Zugangs prüfen: plausibel, direkt, über die Webseite -->
                <div v-if="feld.action === 'backendTest'" class="mb-4">
                    <div class="text-caption text-medium-emphasis mb-2" style="max-width: 70ch">
                        {{ t('WebsitesView.proxyHint') }}
                    </div>
                    <v-btn
                        variant="tonal"
                        size="small"
                        prepend-icon="mdi-lan-connect"
                        :loading="backendPrueft"
                        @click="backendPruefen"
                    >
                        {{ t('crm_fields.shopBackendTest') }}
                    </v-btn>
                    <v-alert
                        v-for="(schritt, nr) in backendErgebnis"
                        :key="nr"
                        :type="schritt.art"
                        variant="tonal"
                        density="compact"
                        class="mt-2"
                        style="max-width: 90ch"
                    >
                        <strong>{{ t(`crm_fields.shopBackendTestStep_${schritt.schritt}`) }}:</strong>
                        {{ testText(schritt) }}
                    </v-alert>
                </div>
            </template>
        </template>
    </div>
</template>

<script setup>
import { reactive, ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import ShopConfigField from '@/core/views/config/tabs/shop-config-field.component.vue'
import websiteSettingsConfig, { websiteSettingFields } from './websiteSettingsConfig.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    websiteId: { type: Number, required: true },
    /** settings der Webseite, wie getWebsites sie liefert */
    settings: { type: Object, default: () => ({}) },
    /** Geheimnisse: Schlüssel -> hinterlegt */
    secretsSet: { type: Object, default: () => ({}) },
    /** Geheimnisse: Schlüssel -> Wert (getWebsiteSecrets) */
    secrets: { type: Object, default: () => ({}) },
    /** Vorlagensätze (getWebsites: template_sets) */
    templateSets: { type: Array, default: () => [] },
    /** Öffentlicher Signaturschlüssel dieser Installation */
    signingKey: { type: String, default: '' },
    /** Adresse, unter der OpensourceERP gerade läuft, mit Portal-Zugang — für ein leeres backend_url */
    backendUrlVorschlag: { type: String, default: '' },
})

const { t, te } = useI18n()

const felder = computed(() => websiteSettingsConfig)
const alleFelder = computed(() => websiteSettingFields())
const quellen = computed(() => ({ templateSets: props.templateSets }))

const WAHR = [true, 't', 'true', '1', 1, 'y', 'yes']

/** Werte für die Felder: Wahrheitswerte als boolean, Geheimnisse aus secrets */
const werte = reactive({})
const gesetzt = ref({ ...props.secretsSet })

alleFelder.value.forEach(feld => {
    const wert = props.settings?.[feld.name]
    if (feld.type === 'checkbox') {
        werte[feld.name] = WAHR.includes(wert)
    } else if (feld.type === 'password') {
        werte[feld.name] = String(props.secrets?.[feld.name] ?? '')
    } else {
        werte[feld.name] = wert === null || wert === undefined ? '' : String(wert)
    }
})

/** Was gespeichert würde: Einstellungen und nicht leere Geheimnisse (leer = behalten) */
function nutzdaten() {
    const settings = {}
    const secrets = {}
    alleFelder.value.forEach(feld => {
        const wert = werte[feld.name]
        if (feld.type === 'password') {
            if (String(wert || '').trim() !== '') secrets[feld.name] = String(wert)
        } else if (feld.type === 'checkbox') {
            settings[feld.name] = wert ? '1' : '0'
        } else {
            settings[feld.name] = wert === null || wert === undefined ? '' : String(wert)
        }
    })
    return { settings, secrets }
}

let zuletzt = JSON.stringify(nutzdaten())
let timer = null
const speichert = ref(false)

// Leere Adresse des Portal-Zugangs: mit der Adresse vorbelegen, unter der
// OpensourceERP gerade aufgerufen wird. Nach „zuletzt“, damit die Vorbelegung
// als Änderung zählt und gespeichert wird.
if (props.backendUrlVorschlag && '' === String(werte.backend_url || '').trim()) {
    werte.backend_url = props.backendUrlVorschlag
}

/** Lesbarer Text zu einer Fehlerantwort */
function fehlerText(antwort, ersatz) {
    const code = String(antwort?.text || '').split(':')[0].trim()
    const schluessel = `WebsitesView.errors.${code}`
    if (code && te(schluessel)) return t(schluessel)
    return antwort?.debug || t(ersatz)
}

async function speichern() {
    const daten = nutzdaten()
    const stand = JSON.stringify(daten)
    if (stand === zuletzt) return

    speichert.value = true
    try {
        const antwort = await axios.post('/api/portal/', {
            action: 'saveWebsiteSettings',
            website_id: props.websiteId,
            ...daten,
        })
        if (!antwort.data?.success) {
            toasts.error(fehlerText(antwort.data, 'WebsitesView.saveError'))
            return
        }
        gesetzt.value = { ...(antwort.data.payload?.secrets_set || {}) }
        zuletzt = JSON.stringify(nutzdaten())
        const abgelehnt = antwort.data.payload?.rejected_keys || []
        if (abgelehnt.length) {
            const namen = abgelehnt.map(schluessel => {
                const feld = alleFelder.value.find(f => f.name === schluessel)
                return feld ? t(feld.label) : schluessel
            })
            toasts.warning(t('WebsitesView.settingsRejected', { fields: namen.join(', ') }), { timer: 15000 })
        } else {
            toasts.success(t('WebsitesView.settingsSaved'))
        }
    } catch (e) {
        toasts.error(fehlerText(e?.response?.data, 'WebsitesView.saveError'))
    } finally {
        speichert.value = false
    }
}

watch(werte, () => {
    if (JSON.stringify(nutzdaten()) === zuletzt) return
    clearTimeout(timer)
    timer = setTimeout(() => {
        timer = null
        speichern()
    }, 800)
}, { deep: true })

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer)
        speichern()
    }
})

// ── Adresse des Portal-Zugangs ──

const backendPrueft = ref(false)
const backendErgebnis = ref([])

/**
 * Text zu einem Prüfschritt: Codes mit Portal-Wortlaut stehen unter
 * WebsitesView.backendTest, neutrale (UNREACHABLE, NOT_FOUND, …) teilen
 * sich die Webseiten mit der Kanalkarte unter crm_fields.shopBackendTest_*.
 */
function testText(schritt) {
    const eigen = `WebsitesView.backendTest.${schritt.code}`
    return te(eigen)
        ? t(eigen, schritt.params || {})
        : t(`crm_fields.shopBackendTest_${schritt.code}`, schritt.params || {})
}

async function backendPruefen() {
    backendPrueft.value = true
    backendErgebnis.value = []
    try {
        const antwort = await axios.post('/api/portal/', {
            action: 'testWebsiteBackendUrl',
            website_id: props.websiteId,
            backend_url: werte.backend_url || '',
            base_url: werte.base_url || '',
            hugocms_url: werte.hugocms_url || '',
            key: werte.public_key || '',
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

// ── HugoCMS ──

const hugocmsPrueft = ref(false)
const hugocmsErgebnis = ref(null)
const freigaben = ref(null)

const FREIGABEN = [
    { feld: 'package', label: 'WebsitesView.fields.grantPackage', icon: 'mdi-package-variant-closed' },
    { feld: 'contentDir', label: 'crm_fields.shopHugoCmsGrantContentDir', icon: 'mdi-file-document-multiple-outline' },
    { feld: 'categoryGroups', label: 'crm_fields.shopHugoCmsGrantCategoryGroups', icon: 'mdi-file-tree-outline', datei: true },
    { feld: 'images', label: 'crm_fields.shopHugoCmsGrantImages', icon: 'mdi-image-multiple-outline' },
    { feld: 'thumbnails', label: 'crm_fields.shopHugoCmsGrantThumbnails', icon: 'mdi-image-size-select-small' },
    { feld: 'downloads', label: 'crm_fields.shopHugoCmsGrantDownloads', icon: 'mdi-download-outline' },
]

const signaturKopiert = ref(false)
async function signaturKopieren() {
    try {
        await navigator.clipboard.writeText(props.signingKey)
        signaturKopiert.value = true
    } catch {
        signaturKopiert.value = false
    }
}

async function hugocmsPruefen() {
    hugocmsPrueft.value = true
    hugocmsErgebnis.value = null
    freigaben.value = null
    try {
        const antwort = await axios.post('/api/portal/', {
            action: 'testWebsiteHugoCms',
            website_id: props.websiteId,
            url: werte.hugocms_url || '',
            key: werte.hugocms_key || '',
        })
        if (!antwort.data?.success) {
            hugocmsErgebnis.value = { art: 'error', zeilen: [antwort.data?.debug || antwort.data?.text || t('crm_fields.shopHugoCmsFailed')] }
            return
        }
        const stand = antwort.data.payload || {}
        hugocmsErgebnis.value = hugocmsBericht(stand)
        freigaben.value = !stand.grants_error && stand.grants ? stand.grants : null
    } catch (e) {
        hugocmsErgebnis.value = { art: 'error', zeilen: [e?.message || t('crm_fields.shopHugoCmsFailed')] }
    } finally {
        hugocmsPrueft.value = false
    }
}

/** Baustand von HugoCMS als Meldung: verbunden, kann bauen, letzter Lauf */
function hugocmsBericht(stand) {
    const zeilen = [t('crm_fields.shopHugoCmsOk')]
    let art = 'success'

    if (!stand.buildable) {
        zeilen.push(t('crm_fields.shopHugoCmsNotBuildable'))
        art = 'warning'
    } else if (stand.paused) {
        zeilen.push(t('crm_fields.shopHugoCmsPaused'))
        art = 'warning'
    }
    if (stand.running) zeilen.push(t('crm_fields.shopHugoCmsRunning'))
    // Shop auf der Webseite, aber in HugoCMS nicht freigegeben: der Lauf
    // scheitert dann an den Freigaben — hier schon beim Verbindungstest sagen
    if (false === stand.onlineShop && stand.features?.shop) {
        zeilen.push(t('WebsitesView.hugocmsShopOff'))
        art = 'warning'
    }
    if (stand.grants_error) {
        zeilen.push(stand.grants_error)
        art = 'error'
    }
    if (stand.signedPhp?.ready) {
        zeilen.push(stand.oserp_signing_key ? t('crm_fields.shopHugoCmsSignedReady') : t('crm_fields.shopHugoCmsSignedNoKey'))
    } else if (stand.signedPhp) {
        zeilen.push(stand.oserp_signing_key ? t('crm_fields.shopHugoCmsSignedMissing') : t('crm_fields.shopHugoCmsSignedNoKey'))
    }
    const letzter = stand.last
    if (letzter?.finishedAt) {
        const wann = new Date(letzter.finishedAt).toLocaleString()
        zeilen.push(letzter.success
            ? t('crm_fields.shopHugoCmsLastOk', { when: wann, seconds: letzter.seconds })
            : t('crm_fields.shopHugoCmsLastFailed', { when: wann, code: letzter.exitCode }))
    } else if (stand.buildable) {
        zeilen.push(t('crm_fields.shopHugoCmsNoBuild'))
    }
    return { art, zeilen }
}

defineExpose({ speichert })
</script>
