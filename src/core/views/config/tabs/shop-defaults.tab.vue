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
            <!-- Sprungmarken zu den Unterbereichen (Überschriften der Konfiguration) -->
            <div class="d-flex flex-wrap ga-2 mt-2">
                <v-chip
                    v-for="abschnitt in abschnitte"
                    :key="abschnitt.name"
                    size="small"
                    variant="tonal"
                    @click="zuAbschnitt(abschnitt.name)"
                >
                    {{ t(abschnitt.label) }}
                </v-chip>
            </div>

            <template v-for="(field, i) in shopConfig" :key="field.name + '-' + i">
                <!-- Überschrift — zugleich Sprungziel -->
                <v-row v-if="field.type === 'headline'" :id="`shop-abschnitt-${field.name}`" class="mt-6 mb-2 sprungziel">
                    <v-col cols="12">
                        <h3 class="text-h6 text-primary">{{ t(field.label) }}</h3>
                        <v-divider class="mt-2"></v-divider>
                    </v-col>
                </v-row>

                <!--
                    Gruppe: fasst zusammengehörige Felder in einer Karte. Mit
                    activeWhen ist die Karte hervorgehoben, wenn ihr Schalter
                    gilt. Die Gruppen der Instanzen (PayPal, HugoCMS, eBay)
                    stehen seit dev/shop-mehrere-kanaele.md, Schritt 5, in der
                    Kanalkarte (shop-channel-settings.vue).
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
                        />
                    </v-card-text>
                </v-card>

                <!-- Verkaufskanäle: eigene Ansicht im Shop-Menü, hier der Weg dorthin -->
                <v-alert
                    v-else-if="field.type === 'component' && field.component === 'sales-channels-link'"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="my-4"
                >
                    <div class="d-flex flex-wrap align-center ga-2">
                        <span>{{ t('ShopView.channelConfig.movedHint') }}</span>
                        <v-spacer />
                        <v-btn
                            size="small"
                            variant="tonal"
                            prepend-icon="mdi-store-cog"
                            :to="{ name: 'shop-channels' }"
                        >
                            {{ t('ShopView.channelConfig.open') }}
                        </v-btn>
                    </div>
                </v-alert>

                <!-- Länder: Zuordnung der Freitexte, lädt und speichert selbst -->
                <ShopCountriesConfig v-else-if="field.type === 'component' && field.component === 'countries'" />

                <!-- Versandarten, Zonen und Preise: eigene Ansicht im Shop-Menü, hier der Weg dorthin -->
                <v-alert
                    v-else-if="field.type === 'component' && field.component === 'shipping-methods-link'"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="my-4"
                >
                    <div class="d-flex flex-wrap align-center ga-2">
                        <span>{{ t('ShopView.shippingConfig.movedHint') }}</span>
                        <v-spacer />
                        <v-btn
                            size="small"
                            variant="tonal"
                            prepend-icon="mdi-truck-delivery-outline"
                            :to="{ name: 'shop-shipping' }"
                        >
                            {{ t('ShopView.shippingConfig.open') }}
                        </v-btn>
                    </div>
                </v-alert>

                <!-- Einzelfeld -->
                <ShopConfigField v-else :field="field" :werte="crmDefaults" :quellen="quellen" :gesetzt="crmSecrets" />
            </template>
        </template>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, inject, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import ShopConfigField from './shop-config-field.component.vue'

const ShopCountriesConfig = defineAsyncComponent(() => import('@/features/shop/components/shop-countries.config.vue'))

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
    /** Unterbereich aus der Seitenleiste oder der Suche: Name der Überschrift */
    openPanel: {
        type: String,
        default: ''
    }
})

const shopConfig = ref([])

/** Unterbereiche für die Sprungmarken: die Überschriften der Konfiguration */
const abschnitte = computed(() => shopConfig.value.filter(f => f.type === 'headline'))

/** Springt zu einem Unterbereich; den Abstand zur Kopfleiste hält .sprungziel */
function zuAbschnitt(name) {
    if (!name) return
    nextTick(() => {
        document.getElementById(`shop-abschnitt-${name}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    })
}

// Klick auf einen Unterbereich in der Seitenleiste (wie beim CRM-Tab). Erst
// wenn die Konfiguration geladen ist — vorher gibt es die Überschrift noch nicht.
watch(() => props.openPanel, (name) => { if (configLoaded.value) zuAbschnitt(name) })
const configError = ref(null)
const configLoaded = ref(false)

/**
 * Auswahllisten, die nicht in der Firmenkonfiguration stehen — die Lagerplätze
 * kommen aus der Lagerverwaltung. Die Vorlagensätze lädt seit
 * dev/shop-mehrere-kanaele.md die Kanalkarte selbst.
 */
const quellen = ref({ shopStockBins: [] })

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
 * activeWhen nennt ein Feld und den Wert, bei dem die Gruppe zählt. Ohne
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
        zuAbschnitt(props.openPanel)
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
 * Läuft auch über die Felder in Gruppen.
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
    await ladeLagerplaetze()
    if (!configError.value) {
        ohneSpeichern(normalizeShopDefaults)
    }
})

defineExpose({
    normalizeShopDefaults
})
</script>

<style scoped>
/* Sprungmarken: Abstand zur festen Kopfleiste */
.sprungziel {
    scroll-margin-top: 72px;
}
</style>
