<!-- src/core/views/config/tabs/shop-defaults.tab.vue -->
<template>
    <v-container fluid class="pa-0">
        <!-- Fehler beim Laden der Config -->
        <v-alert
            v-if="configError"
            type="error"
            variant="tonal"
            prominent
            border="start"
        >
            <v-alert-title class="text-h6">
                <v-icon start>mdi-alert-circle</v-icon>
                {{ t('configLoadError') || 'Konfigurationsfehler' }}
            </v-alert-title>

            <div class="mt-4">
                <div class="text-body-1 mb-2">{{ t('syntaxErrorInConfigFile') || 'Syntaxfehler in der Konfigurationsdatei' }}</div>
                <div class="text-caption text-grey-darken-1 mb-4">
                    <strong>Datei:</strong> <code>src/core/views/config/tabs/shopDefaultsConfig.js</code>
                </div>

                <v-divider class="my-3"></v-divider>

                <div class="text-body-2 font-weight-bold mb-2">{{ t('errorDetails') || 'Fehlerdetails' }}:</div>
                <pre class="pa-3 bg-grey-lighten-4 rounded text-caption overflow-auto" style="max-height: 200px;">{{ configError }}</pre>
            </div>
        </v-alert>

        <!-- Ladeanzeige -->
        <div v-else-if="!configLoaded" class="d-flex justify-center align-center pa-8">
            <v-progress-circular indeterminate color="primary" />
            <span class="ml-3">{{ t('loadingConfiguration') }}</span>
        </div>

        <template v-else>
            <!-- Geheimnisse werden aus Sicherheitsgründen nicht geladen -->
            <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                <v-icon start size="small">mdi-shield-key-outline</v-icon>
                {{ t('crm_fields.shopSecretsNotice') }}
            </v-alert>

            <template v-for="(field, i) in shopConfig" :key="field.name + '-' + i">
                <!-- Überschrift -->
                <v-row v-if="field.type === 'headline'" class="mt-6 mb-2">
                    <v-col cols="12">
                        <h3 class="text-h6 text-primary">{{ t(field.label) }}</h3>
                        <v-divider class="mt-2"></v-divider>
                    </v-col>
                </v-row>

                <!--
                    Gruppe: fasst zusammengehörige Felder in einer Karte.

                    Die PayPal-Zugangsdaten gibt es zweimal — für Test- und für
                    Echtbetrieb. Ohne diese Trennung stünden vier fast gleich
                    benannte Felder untereinander, und welches Paar gerade gilt,
                    stünde nirgends. Die Karte des aktiven Paars ist deshalb
                    hervorgehoben, die andere zurückgenommen.
                -->
                <v-card
                    v-else-if="field.type === 'group'"
                    :variant="istAktiv(field) ? 'tonal' : 'outlined'"
                    :color="istAktiv(field) ? 'primary' : undefined"
                    class="my-3"
                    :data-group-name="field.name"
                >
                    <v-card-item>
                        <template #prepend>
                            <v-icon :icon="field.icon || 'mdi-folder-outline'" />
                        </template>
                        <v-card-title class="text-subtitle-1">
                            {{ t(field.label) }}
                            <v-chip
                                v-if="field.activeWhen"
                                size="x-small"
                                :color="istAktiv(field) ? 'success' : 'grey'"
                                variant="flat"
                                class="ml-2"
                            >
                                {{ istAktiv(field) ? t('crm_fields.shopGroupActive') : t('crm_fields.shopGroupInactive') }}
                            </v-chip>
                        </v-card-title>
                        <v-card-subtitle v-if="field.tooltip">{{ t(field.tooltip) }}</v-card-subtitle>
                    </v-card-item>

                    <v-card-text class="pt-0">
                        <ShopConfigField
                            v-for="unterfeld in field.fields"
                            :key="unterfeld.name"
                            :field="unterfeld"
                            :werte="crmDefaults"
                            :quellen="quellen"
                            :gesetzt="crmSecrets"
                            :vorgaben="crmFallbacks"
                        />

                        <!-- eBay-Kanal: Verbindungstest, Bestellabruf, Stand -->
                        <EbayStatusConfig v-if="field.action === 'ebayPanel'" class="mt-2" />

                        <!-- Verbindung zu HugoCMS prüfen: mit den Werten im
                             Formular, auch wenn sie noch nicht gespeichert sind -->
                        <template v-if="field.action === 'hugocmsTest'">
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
                                <div v-for="(zeile, index) in hugocmsErgebnis.zeilen" :key="index">{{ zeile }}</div>
                            </v-alert>
                        </template>
                    </v-card-text>
                </v-card>

                <!-- Verkaufskanäle: Komponente der Shop-Erweiterung, lädt und
                     speichert selbst (eigene Tabelle statt defaults_oserp) -->
                <ShopChannelsConfig
                    v-else-if="field.type === 'component' && field.component === 'sales-channels'"
                    :tax-included="crmDefaults.shop_tax_included ?? null"
                />

                <!-- Einzelfeld -->
                <ShopConfigField v-else :field="field" :werte="crmDefaults" :quellen="quellen" :gesetzt="crmSecrets" :vorgaben="crmFallbacks" />
            </template>
        </template>
    </v-container>
</template>

<script setup>
import { ref, onMounted, inject, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import ShopConfigField from './shop-config-field.component.vue'

const ShopChannelsConfig = defineAsyncComponent(() => import('@/features/shop/components/shop-channels.config.vue'))
const EbayStatusConfig = defineAsyncComponent(() => import('@/features/shop/components/shop-ebay-status.vue'))

const { t } = useI18n()

const props = defineProps({
    crmDefaults: {
        type: Object,
        required: true
    },
    /** Geheimnisse: Schlüssel -> hinterlegt ja/nein. Die Werte kommen nie mit */
    crmSecrets: {
        type: Object,
        default: () => ({})
    },
    /** Vorgaben aus der settings.ini für leere Felder: Schlüssel -> Wert */
    crmFallbacks: {
        type: Object,
        default: () => ({})
    }
})

const shopConfig = ref([])
const configError = ref(null)
const configLoaded = ref(false)

/**
 * Auswahllisten, die nicht in der Firmenkonfiguration stehen
 *
 * Die Vorlagensätze der Produktseiten liegen im Dateisystem des Servers; sie
 * kommen deshalb aus der Shop-Erweiterung statt aus getCompanyConfig.
 */
const quellen = ref({ shopTemplateSets: [], shopStockBins: [] })

/** Verbindungstest zu HugoCMS: läuft gerade, und was kam heraus */
const hugocmsPrueft = ref(false)
const hugocmsErgebnis = ref(null)

/**
 * Prüft die Verbindung zu HugoCMS
 *
 * Schickt Adresse und — falls eingetippt — Schlüssel aus dem Formular mit.
 * Die Firmenkonfiguration speichert verzögert; ohne das prüfte ein Klick direkt
 * nach der Eingabe noch den alten Stand. Ein leeres Schlüsselfeld heißt: der
 * gespeicherte gilt.
 */
async function hugocmsPruefen() {
    hugocmsPrueft.value = true
    hugocmsErgebnis.value = null
    try {
        const response = await axios.post('/api/shop/', {
            action: 'testShopHugoCms',
            url: props.crmDefaults.shop_hugocms_url || '',
            key: props.crmDefaults.shop_hugocms_key || '',
        })
        if (!response.data?.success) {
            hugocmsErgebnis.value = { art: 'error', zeilen: [response.data?.debug || response.data?.text || t('crm_fields.shopHugoCmsFailed')] }
            return
        }
        hugocmsErgebnis.value = hugocmsBericht(response.data.payload || {})
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
    if (stand.running) {
        zeilen.push(t('crm_fields.shopHugoCmsRunning'))
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

async function ladeVorlagensaetze() {
    try {
        const response = await axios.post('/api/shop/', { action: 'getShopTemplateSets' })
        if (response.data?.success) {
            quellen.value = { ...quellen.value, shopTemplateSets: response.data.payload?.sets || [] }
        }
    } catch (e) {
        // Ohne Liste bleibt das Feld leer — der gespeicherte Satz gilt weiter
        console.warn('Vorlagensätze konnten nicht geladen werden:', e)
    }
}

/**
 * Lagerplätze für die Einstellung shop_stock_bin_id
 *
 * Aus der Lagerverwaltung (getWarehouseOptions), als „Lager – Platz“. Die
 * Werte sind Zeichenketten, weil defaults_oserp Text speichert — sonst fände
 * die Auswahl den gespeicherten Platz nicht wieder.
 */
async function ladeLagerplaetze() {
    try {
        const response = await axios.post('/api/warehouse/', { action: 'getWarehouseOptions' })
        const lager = response.data?.success ? (response.data.payload?.results?.warehouses || []) : []
        quellen.value = {
            ...quellen.value,
            shopStockBins: lager.flatMap(l => (l.bins || []).map(p => ({
                value: String(p.id),
                title: `${l.description} – ${p.description}`,
            }))),
        }
    } catch (e) {
        // Ohne Liste bleibt das Feld leer — der gespeicherte Platz gilt weiter
        console.warn('Lagerplätze konnten nicht geladen werden:', e)
    }
}

/**
 * Gilt diese Gruppe gerade?
 *
 * activeWhen nennt ein Feld und den Wert, bei dem die Gruppe zählt — für die
 * PayPal-Zugangsdaten ist das der Schalter shop_paypal_sandbox. Ohne
 * activeWhen ist eine Gruppe immer aktiv.
 */
function istAktiv(gruppe) {
    if (!gruppe.activeWhen) return true
    return Boolean(props.crmDefaults[gruppe.activeWhen.field]) === Boolean(gruppe.activeWhen.value)
}

/**
 * Lädt die Felddefinition
 */
async function loadConfigFile() {
    try {
        const config = await import('./shopDefaultsConfig.js')
        shopConfig.value = config.default || []
        configError.value = null
        configLoaded.value = true
    } catch (error) {
        console.error('Error loading shopDefaultsConfig.js:', error)
        configError.value = error.message
        shopConfig.value = []
        configLoaded.value = false
    }
}

/**
 * Wandelt die Werte aus der Datenbank in JavaScript-Typen
 *
 * Wahrheitswerte kommen als 't'/'f'/'1'/'0'. Passwortfelder bleiben leer — sie
 * werden von getCompanyConfig bewusst nicht ausgeliefert, und cleanData()
 * übergeht leere Felder beim Speichern.
 *
 * Läuft auch über die Felder in Gruppen: sonst blieben die PayPal-Zugangsdaten
 * unbehandelt.
 */
function normalizeShopDefaults() {
    const alleFelder = shopConfig.value.flatMap(f => f.type === 'group' ? (f.fields || []) : [f])

    alleFelder.forEach(field => {
        if (field.type === 'checkbox') {
            const value = props.crmDefaults[field.name]
            props.crmDefaults[field.name] =
                value === true ||
                value === 'true' ||
                value === 't' ||
                value === '1' ||
                value === 1
        } else if (field.type === 'password') {
            props.crmDefaults[field.name] = ''
        }
    })
}

/**
 * Normalisieren, ohne dass die Elternansicht daraus eine Nutzeränderung macht
 *
 * Fallback für den Fall, dass der Tab außerhalb der Firmenkonfiguration
 * eingebunden wird: dann läuft fn einfach ungeschuetzt.
 */
const ohneSpeichern = inject('ohneSpeichern', fn => fn())

onMounted(async () => {
    await loadConfigFile()
    await Promise.all([ladeVorlagensaetze(), ladeLagerplaetze()])
    if (!configError.value) {
        ohneSpeichern(normalizeShopDefaults)
    }
})

defineExpose({
    normalizeShopDefaults
})
</script>
