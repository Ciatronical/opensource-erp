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
                        />
                    </v-card-text>
                </v-card>

                <!-- Einzelfeld -->
                <ShopConfigField v-else :field="field" :werte="crmDefaults" />
            </template>
        </template>
    </v-container>
</template>

<script setup>
import { ref, onMounted, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import ShopConfigField from './shop-config-field.component.vue'

const { t } = useI18n()

const props = defineProps({
    crmDefaults: {
        type: Object,
        required: true
    }
})

const shopConfig = ref([])
const configError = ref(null)
const configLoaded = ref(false)

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
    if (!configError.value) {
        ohneSpeichern(normalizeShopDefaults)
    }
})

defineExpose({
    normalizeShopDefaults
})
</script>
