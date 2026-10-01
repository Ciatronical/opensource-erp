<!-- src/features/shop/components/shop-countries.config.vue -->
<!--
    Länder in der Firmenkonfiguration (Reiter Shop) — dev/shop-versand.md,
    Schritt 3.

    Länder stehen in den Adressen als Freitext („Deutschland", „DE",
    „Brandenburg"). Versandkosten und Lieferländer brauchen ein eindeutiges
    Land: Die Karte zeigt jeden vorkommenden Freitext mit der Zahl der
    Adressen und dem zugeordneten Land. Was nicht zugeordnet ist, steht oben
    und wird hier von Hand zugeordnet; gespeichert wird sofort.

    Die Ländernamen kommen aus dem ISO-Code (Intl.DisplayNames) in der Sprache
    des Benutzers, nicht aus der Datenbank.
-->
<template>
    <div>
        <v-row class="mt-6 mb-2">
            <v-col cols="12">
                <h3 class="text-h6 text-primary">{{ t('ShopView.countryConfig.title') }}</h3>
                <v-divider class="mt-2" />
            </v-col>
        </v-row>

        <div class="text-body-2 text-medium-emphasis mb-3">{{ t('ShopView.countryConfig.intro') }}</div>

        <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-3">{{ fehler }}</v-alert>
        <div v-else-if="laedt" class="d-flex align-center pa-2">
            <v-progress-circular indeterminate size="20" width="2" class="mr-2" />
        </div>

        <template v-else>
            <div class="d-flex flex-wrap align-center ga-4 mb-2">
                <div class="text-body-2">
                    {{ t('ShopView.countryConfig.companyCountry', {
                        text: mandantenland || '—',
                        country: mandantenlandCode ? landName(mandantenlandCode) : t('ShopView.countryConfig.unassigned'),
                    }) }}
                </div>
                <v-spacer />
                <v-chip v-if="offen" color="warning" variant="tonal" size="small">
                    {{ t('ShopView.countryConfig.openCount', { count: offen }) }}
                </v-chip>
                <v-switch
                    v-model="nurOffene"
                    :label="t('ShopView.countryConfig.onlyUnassigned')"
                    color="primary"
                    density="compact"
                    hide-details
                />
            </div>

            <v-table density="compact">
                <thead>
                    <tr>
                        <th>{{ t('ShopView.countryConfig.text') }}</th>
                        <th class="text-end">{{ t('ShopView.countryConfig.addresses') }}</th>
                        <th style="min-width: 22ch">{{ t('ShopView.countryConfig.country') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="zeile in sichtbar" :key="zeile.alias">
                        <td>
                            <span v-if="zeile.alias === ''" class="text-medium-emphasis">
                                {{ t('ShopView.countryConfig.empty') }}
                            </span>
                            <span v-else>{{ zeile.beispiel }}</span>
                            <v-chip v-if="zeile.manual" size="x-small" variant="tonal" class="ml-2">
                                {{ t('ShopView.countryConfig.manual') }}
                            </v-chip>
                        </td>
                        <td class="text-end">{{ zeile.anzahl }}</td>
                        <td>
                            <!-- Leer meint das Mandantenland — nicht hier zuzuordnen -->
                            <span v-if="zeile.alias === ''" class="text-medium-emphasis">
                                {{ zeile.iso_code ? landName(zeile.iso_code) : t('ShopView.countryConfig.unassigned') }}
                            </span>
                            <v-autocomplete
                                v-else
                                :model-value="zeile.iso_code"
                                :items="landAuswahl"
                                :placeholder="t('ShopView.countryConfig.unassigned')"
                                :loading="speichert[zeile.alias]"
                                :error="!zeile.iso_code"
                                variant="outlined"
                                density="compact"
                                hide-details
                                clearable
                                @update:model-value="wert => zuordnen(zeile, wert)"
                            />
                        </td>
                    </tr>
                    <tr v-if="!sichtbar.length">
                        <td colspan="3" class="text-medium-emphasis">{{ t('ShopView.countryConfig.allAssigned') }}</td>
                    </tr>
                </tbody>
            </v-table>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { shopFehler } from '../composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'

const i18n = useI18n()
const { t, locale } = i18n

const texte = ref([])
const laender = ref([])
const mandantenland = ref('')
const mandantenlandCode = ref(null)
const laedt = ref(false)
const fehler = ref('')
const speichert = ref({})
const nurOffene = ref(true)

/** Ländername in der Sprache des Benutzers; ohne Unterstützung bleibt der Code */
const anzeige = computed(() => {
    try {
        return new Intl.DisplayNames([locale.value], { type: 'region' })
    } catch {
        return null
    }
})
const landName = code => anzeige.value?.of(code) || code

const landAuswahl = computed(() => laender.value
    .map(land => ({ value: land.iso_code, title: `${landName(land.iso_code)} (${land.iso_code})` }))
    .sort((a, b) => a.title.localeCompare(b.title, locale.value)))

/** Nicht zugeordnete Freitexte; der leere zählt nicht, er meint das Mandantenland */
const offen = computed(() => texte.value.filter(z => z.alias !== '' && !z.iso_code).length)
/** In dieser Sitzung zugeordnete Freitexte bleiben sichtbar, bis neu geladen wird */
const bearbeitet = ref(new Set())
const sichtbar = computed(() => (nurOffene.value
    ? texte.value.filter(z => z.alias !== '' && (!z.iso_code || bearbeitet.value.has(z.alias)))
    : texte.value))

async function laden() {
    laedt.value = true
    fehler.value = ''
    try {
        const antwort = await axios.post('/api/shop/', { action: 'getShopCountryMapping' })
        if (!antwort.data?.success) {
            fehler.value = shopFehler(antwort.data, i18n, 'ShopView.countryConfig.loadError').text
            return
        }
        const daten = antwort.data.payload || {}
        texte.value = (daten.texts || []).map(z => ({ ...z, anzahl: Number(z.anzahl) || 0 }))
        bearbeitet.value = new Set()
        laender.value = daten.countries || []
        mandantenland.value = daten.company_country || ''
        mandantenlandCode.value = daten.company_country_code || null
        // Ist alles zugeordnet, gibt es nichts „Offenes" zu zeigen
        if (!offen.value) nurOffene.value = false
    } catch (e) {
        fehler.value = shopFehler(e?.response?.data, i18n, 'ShopView.countryConfig.loadError').text
    } finally {
        laedt.value = false
    }
}

/**
 * Ordnet einen Freitext zu oder hebt die Zuordnung auf
 *
 * Sofort gespeichert. Die Zeile bleibt stehen, auch wenn „nur nicht
 * zugeordnete" an ist — sie verschwindet erst beim nächsten Laden, damit sie
 * nicht unter dem Mauszeiger wegspringt.
 */
async function zuordnen(zeile, wert) {
    const vorher = { iso_code: zeile.iso_code, manual: zeile.manual }
    bearbeitet.value = new Set(bearbeitet.value).add(zeile.alias)
    zeile.iso_code = wert || null
    speichert.value = { ...speichert.value, [zeile.alias]: true }
    try {
        const antwort = await axios.post('/api/shop/', {
            action: 'saveShopCountryAlias', alias: zeile.alias, iso_code: wert || '',
        })
        if (!antwort.data?.success) {
            Object.assign(zeile, vorher)
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.countryConfig.saveError').text)
            return
        }
        zeile.manual = !!wert
        toasts.success(t('ShopView.countryConfig.saved'))
    } catch (e) {
        Object.assign(zeile, vorher)
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.countryConfig.saveError').text)
    } finally {
        speichert.value = { ...speichert.value, [zeile.alias]: false }
    }
}

onMounted(laden)
</script>
