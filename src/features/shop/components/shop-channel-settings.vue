<!-- src/features/shop/components/shop-channel-settings.vue -->
<!--
    Einstellungen einer Instanz in der Kanalkarte (dev/shop-mehrere-kanaele.md,
    Schritt 5): Adressen, Verzeichnisse, Shop-Schlüssel, HugoCMS, PayPal und
    Mails eines HugoShops; Zugang, Richtlinien und Bestellabruf eines
    eBay-Kanals. Die Felder stehen in shopChannelSettingsConfig.js und werden
    wie im Reiter „Shop" über shop-config-field dargestellt.

    Gespeichert wird verzögert nach jeder Änderung (saveShopChannelSettings).
    Geheimnisse kommen nie vom Server: das Feld bleibt leer, der Platzhalter
    sagt, ob etwas hinterlegt ist, und nur ein neu eingegebener Wert geht
    hinaus.
-->
<template>
    <div>
        <v-alert type="info" variant="tonal" density="compact" class="mb-2">
            <v-icon start size="small">mdi-shield-key-outline</v-icon>
            {{ t('crm_fields.shopSecretsNotice') }}
        </v-alert>

        <template v-for="(feld, index) in felder" :key="feld.name + '-' + index">
            <div v-if="feld.type === 'headline'" class="text-subtitle-2 text-primary mt-4">
                {{ t(feld.label) }}
                <v-divider class="mt-1" />
            </div>

            <v-card
                v-else-if="feld.type === 'group'"
                :variant="istAktiv(feld) ? 'tonal' : 'outlined'"
                :color="istAktiv(feld) ? 'primary' : undefined"
                class="my-3"
            >
                <v-card-item>
                    <template #prepend>
                        <v-icon :icon="feld.icon || 'mdi-folder-outline'" />
                    </template>
                    <v-card-title class="text-subtitle-1">
                        {{ t(feld.label) }}
                        <v-chip
                            v-if="feld.activeWhen"
                            size="x-small"
                            :color="istAktiv(feld) ? 'success' : 'grey'"
                            variant="flat"
                            class="ml-2"
                        >
                            {{ istAktiv(feld) ? t('crm_fields.shopGroupActive') : t('crm_fields.shopGroupInactive') }}
                        </v-chip>
                    </v-card-title>
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
                        :kanal="channelId"
                    />

                    <!-- eBay: Verbindungstest, Bestellabruf, Stand dieses Kanals -->
                    <EbayStatusConfig v-if="feld.action === 'ebayPanel'" :channel-id="channelId" class="mt-2" />

                    <!-- Verbindung zu HugoCMS prüfen: mit den Werten im Formular,
                         auch wenn sie noch nicht gespeichert sind -->
                    <template v-if="feld.action === 'hugocmsTest'">
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
                    </template>
                </v-card-text>
            </v-card>

            <ShopConfigField
                v-else
                :field="feld"
                :werte="werte"
                :quellen="quellen"
                :gesetzt="gesetzt"
                :kanal="channelId"
            />
        </template>
    </div>
</template>

<script setup>
import { reactive, ref, computed, watch, onBeforeUnmount, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import ShopConfigField from '@/core/views/config/tabs/shop-config-field.component.vue'
import shopChannelSettingsConfig, { channelSettingFields } from './shopChannelSettingsConfig.js'
import { shopFehler } from '../composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'

const EbayStatusConfig = defineAsyncComponent(() => import('./shop-ebay-status.vue'))

const props = defineProps({
    channelId: { type: Number, required: true },
    /** Art: hugoshop oder ebay */
    type: { type: String, required: true },
    /** settings des Kanals, wie getShopChannels sie liefert */
    settings: { type: Object, default: () => ({}) },
    /** Geheimnisse: Schlüssel -> hinterlegt */
    secretsSet: { type: Object, default: () => ({}) },
    /** Auswahllisten (Vorlagensätze) */
    quellen: { type: Object, default: () => ({}) },
})

const i18n = useI18n()
const { t } = i18n

const felder = computed(() => shopChannelSettingsConfig[props.type] || [])
const alleFelder = computed(() => channelSettingFields(props.type))

const WAHR = [true, 't', 'true', '1', 1, 'y', 'yes']

/** Werte für die Felder: Wahrheitswerte als boolean, Geheimnisse leer */
const werte = reactive({})
const gesetzt = ref({ ...props.secretsSet })

function uebernehmen() {
    alleFelder.value.forEach(feld => {
        const wert = props.settings?.[feld.name]
        if (feld.type === 'checkbox') {
            werte[feld.name] = WAHR.includes(wert)
        } else if (feld.type === 'password') {
            werte[feld.name] = ''
        } else {
            werte[feld.name] = wert === null || wert === undefined ? '' : String(wert)
        }
    })
}
uebernehmen()

/**
 * Gilt diese Gruppe gerade? (PayPal: das Paar, das der Schalter wählt)
 */
function istAktiv(gruppe) {
    if (!gruppe.activeWhen) return true
    return Boolean(werte[gruppe.activeWhen.field]) === Boolean(gruppe.activeWhen.value)
}

// ── Speichern ──

/** Was gespeichert würde: Einstellungen und neu eingegebene Geheimnisse */
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

async function speichern() {
    const daten = nutzdaten()
    const stand = JSON.stringify(daten)
    if (stand === zuletzt) return

    speichert.value = true
    try {
        const antwort = await axios.post('/api/shop/', {
            action: 'saveShopChannelSettings',
            channel_id: props.channelId,
            ...daten,
        })
        if (!antwort.data?.success) {
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.channelConfig.settingsSaveError').text)
            return
        }
        gesetzt.value = { ...(antwort.data.payload?.secrets_set || {}) }
        // Gespeicherte Geheimnisse verschwinden aus dem Feld — der Platzhalter
        // sagt danach „hinterlegt"
        alleFelder.value.filter(feld => feld.type === 'password').forEach(feld => { werte[feld.name] = '' })
        zuletzt = JSON.stringify(nutzdaten())
        toasts.success(t('ShopView.channelConfig.settingsSaved'))
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.channelConfig.settingsSaveError').text)
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

// Ausstehende Änderungen nicht verlieren, wenn die Karte zugeklappt wird
onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer)
        speichern()
    }
})

// ── HugoCMS ──

const hugocmsPrueft = ref(false)
const hugocmsErgebnis = ref(null)

/**
 * Prüft die Verbindung zu HugoCMS — mit Adresse und, falls eingetippt,
 * Schlüssel aus dem Formular; ein leeres Schlüsselfeld heißt: der
 * gespeicherte gilt
 */
async function hugocmsPruefen() {
    hugocmsPrueft.value = true
    hugocmsErgebnis.value = null
    try {
        const antwort = await axios.post('/api/shop/', {
            action: 'testShopHugoCms',
            channel_id: props.channelId,
            url: werte.hugocms_url || '',
            key: werte.hugocms_key || '',
        })
        if (!antwort.data?.success) {
            hugocmsErgebnis.value = { art: 'error', zeilen: [antwort.data?.debug || antwort.data?.text || t('crm_fields.shopHugoCmsFailed')] }
            return
        }
        hugocmsErgebnis.value = hugocmsBericht(antwort.data.payload || {})
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

defineExpose({ speichert })
</script>
