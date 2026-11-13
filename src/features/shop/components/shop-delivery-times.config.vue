<!-- src/features/shop/components/shop-delivery-times.config.vue -->
<!--
    Lieferzeiten: Tage je Lieferbedingung (Dropshipping und Lieferzeiten,
    2026-11-13).

    Die Lieferbedingungen sind die kivitendo-Stammliste delivery_terms — ihr
    Text steht auf Produktseite, Warenkorb und Rechnung und wird in der
    Firmenkonfiguration unter „Stammdaten-Listen“ gepflegt. Hier kommt je
    Lieferbedingung die Lieferzeit in Tagen ab Bestellung dazu
    (delivery_term_shop): sie steht in den Suchmaschinendaten der
    Produktseiten.

    Gespeichert wird je Zeile beim Verlassen eines Feldes
    (saveShopDeliveryTime). Neue Lieferbedingungen legt der Dialog
    shop-delivery-term.dialog.vue an. Recht: edit_shop_config.
-->
<template>
    <!-- Aufbau wie Länderzonen und Versandarten der Ansicht (shop-shipping.config.vue):
         Überschrift mit Hinzufügen, Hinweis, je Eintrag eine umrandete Karte -->
    <div>
        <div class="d-flex align-center mt-6 mb-1">
            <div class="text-subtitle-1 font-weight-medium">{{ t('ShopView.deliveryTimes.title') }}</div>
            <v-spacer />
            <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="anlegen">{{ t('ShopView.deliveryTerm.add') }}</v-btn>
        </div>
        <div class="text-caption text-medium-emphasis mb-2">{{ t('ShopView.deliveryTimes.intro') }}</div>

        <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-2">{{ fehler }}</v-alert>
        <div v-else-if="laedt" class="d-flex align-center pa-2">
            <v-progress-circular indeterminate size="20" width="2" />
        </div>
        <div v-else-if="!zeilen.length" class="text-caption text-medium-emphasis mb-2">
            {{ t('ShopView.deliveryTimes.empty') }}
        </div>

        <template v-else>
            <v-card v-for="zeile in zeilen" :key="zeile.id" variant="outlined" class="mb-2">
                <v-card-text class="py-2">
                    <v-row dense align="center">
                        <v-col cols="12" sm="6" class="py-1 d-flex align-center">
                            <div class="flex-grow-1">
                                <div class="text-body-2">
                                    {{ zeile.description }}
                                    <v-chip v-if="zeile.obsolete" size="x-small" class="lieferzeiten-marke">{{ t('ShopView.partCard.inactive') }}</v-chip>
                                </div>
                                <div v-if="zeile.description_long" class="text-caption text-medium-emphasis">{{ zeile.description_long }}</div>
                            </div>
                            <!-- Bezeichnung und Text für Kunden direkt ändern -->
                            <v-btn
                                icon="mdi-pencil-outline"
                                size="small"
                                variant="text"
                                :title="t('ShopView.deliveryTerm.edit')"
                                :aria-label="t('ShopView.deliveryTerm.edit')"
                                @click="bearbeiten(zeile)"
                            />
                        </v-col>
                        <v-col v-for="feld in ['days_min', 'days_max']" :key="feld" cols="6" sm="3" class="py-1">
                            <v-text-field
                                v-model="zeile[feld]"
                                persistent-placeholder
                                type="number"
                                step="1"
                                min="0"
                                :label="t(feld === 'days_min' ? 'ShopView.deliveryTimes.daysMin' : 'ShopView.deliveryTimes.daysMax')"
                                :rules="[() => gueltig(zeile) || t('ShopView.errors.DELIVERY_DAYS_INVALID')]"
                                :loading="zeile.speichert"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                autocomplete="off"
                                @blur="speichern(zeile)"
                                @keyup.enter="speichern(zeile)"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>
        </template>

        <ShopDeliveryTermDialog v-model="neuDialog" :eintrag="inArbeit" @created="aufnehmen" @updated="uebernehmen" />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import ShopDeliveryTermDialog from './shop-delivery-term.dialog.vue'
import { shopFehler } from '../composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'

const i18n = useI18n()
const { t } = i18n

const WAHR = [true, 't', 'true', '1', 1]
const zeilen = ref([])
const laedt = ref(false)
const fehler = ref('')
/** Zuletzt gespeicherter Stand je Lieferbedingung — Gleiches geht nicht erneut */
const zuletzt = {}

const leer = wert => wert === null || wert === undefined || String(wert).trim() === ''
const tage = wert => (leer(wert) ? null : Number(wert))
const neuDialog = ref(false)
/** Lieferbedingung, die der Dialog gerade ändert; null = er legt eine neue an */
const inArbeit = ref(null)
function anlegen() {
    inArbeit.value = null
    neuDialog.value = true
}
function bearbeiten(zeile) {
    inArbeit.value = { id: zeile.id, description: zeile.description, description_long: zeile.description_long }
    neuDialog.value = true
}
/** Geänderte Bezeichnung und Text in die Zeile übernehmen — die Tage bleiben */
function uebernehmen(lieferbedingung) {
    const zeile = zeilen.value.find(z => z.id === Number(lieferbedingung?.id))
    if (!zeile) return
    zeile.description = lieferbedingung.description || ''
    zeile.description_long = lieferbedingung.description_long || ''
}
const stand = zeile => JSON.stringify([tage(zeile.days_min), tage(zeile.days_max)])

/** Ganze Tage ab 0; „von“ nicht größer als „bis“ */
function gueltig(zeile) {
    const von = tage(zeile.days_min)
    const bis = tage(zeile.days_max)
    const ganz = wert => wert === null || (Number.isInteger(wert) && wert >= 0)
    return ganz(von) && ganz(bis) && (von === null || bis === null || von <= bis)
}

const alsZeile = d => ({
    id: Number(d.id),
    description: d.description || '',
    description_long: d.description_long || '',
    obsolete: WAHR.includes(d.obsolete),
    days_min: leer(d.days_min) ? '' : String(Number(d.days_min)),
    days_max: leer(d.days_max) ? '' : String(Number(d.days_max)),
    speichert: false,
})

/** Im Dialog angelegte Lieferbedingung ans Ende der Liste */
function aufnehmen(lieferbedingung) {
    if (!lieferbedingung) return
    const zeile = alsZeile(lieferbedingung)
    zuletzt[zeile.id] = stand(zeile)
    zeilen.value.push(zeile)
}

async function laden() {
    laedt.value = true
    fehler.value = ''
    try {
        const antwort = await axios.post('/api/shop/', { action: 'getShopDeliveryTimes' })
        if (!antwort.data?.success) {
            fehler.value = shopFehler(antwort.data, i18n, 'ShopView.deliveryTimes.loadError').text
            return
        }
        zeilen.value = (antwort.data.payload?.delivery_terms || []).map(alsZeile)
        zeilen.value.forEach(zeile => { zuletzt[zeile.id] = stand(zeile) })
    } catch (e) {
        fehler.value = shopFehler(e?.response?.data, i18n, 'ShopView.deliveryTimes.loadError').text
    } finally {
        laedt.value = false
    }
}

async function speichern(zeile) {
    if (zeile.speichert || !gueltig(zeile) || stand(zeile) === zuletzt[zeile.id]) return
    const gesendet = stand(zeile)
    zeile.speichert = true
    try {
        const antwort = await axios.post('/api/shop/', {
            action: 'saveShopDeliveryTime',
            delivery_term_id: zeile.id,
            days_min: tage(zeile.days_min),
            days_max: tage(zeile.days_max),
        })
        if (!antwort.data?.success) {
            toasts.error(shopFehler(antwort.data, i18n, 'ShopView.deliveryTimes.saveError').text)
            return
        }
        zuletzt[zeile.id] = gesendet
        toasts.success(t('ShopView.deliveryTimes.saved'))
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.deliveryTimes.saveError').text)
    } finally {
        zeile.speichert = false
        // Während des Speicherns weitergetippt: den neuen Stand nachziehen
        if (stand(zeile) !== zuletzt[zeile.id] && stand(zeile) !== gesendet) speichern(zeile)
    }
}

onMounted(laden)
</script>

<style scoped>
.lieferzeiten-marke {
    margin-left: var(--oserp-gap-sm);
}
</style>
