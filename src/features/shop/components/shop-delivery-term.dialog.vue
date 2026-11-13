<!-- src/features/shop/components/shop-delivery-term.dialog.vue -->
<!--
    Lieferbedingung direkt anlegen (2026-11-13).

    Der Dialog steht an jeder Stelle des Shops, an der eine Lieferbedingung
    gewählt wird: Shop-Karte des Artikels, Vorgaben der Verkaufskanäle und
    Abschnitt „Lieferzeiten“. Er legt den Eintrag in der Stammliste
    delivery_terms an, auf Wunsch gleich mit der Lieferzeit in Tagen
    (createShopDeliveryTerm), und meldet ihn mit „created“ zurück — die
    aufrufende Stelle nimmt ihn in ihre Auswahl auf und wählt ihn.

    Ändern und Löschen bleiben in der Firmenkonfiguration unter
    „Stammdaten-Listen“.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="520" @update:model-value="$emit('update:modelValue', $event)">
        <v-card>
            <v-card-title>{{ t('ShopView.deliveryTerm.title') }}</v-card-title>
            <v-card-text>
                <v-form ref="formular" @submit.prevent="anlegen">
                    <v-text-field
                        v-model="eintrag.description"
                        :label="t('ShopView.deliveryTerm.description')"
                        :hint="t('ShopView.deliveryTerm.descriptionHint')"
                        persistent-hint
                        :rules="[wert => !!String(wert || '').trim() || t('ShopView.deliveryTerm.descriptionRequired')]"
                        :error-messages="fehler"
                        autofocus
                        variant="outlined"
                        density="compact"
                        autocomplete="off"
                        class="lieferbedingung-feld"
                        @update:model-value="fehler = ''"
                    />
                    <v-text-field
                        v-model="eintrag.description_long"
                        :label="t('ShopView.deliveryTerm.text')"
                        :hint="t('ShopView.deliveryTerm.textHint')"
                        persistent-hint
                        variant="outlined"
                        density="compact"
                        autocomplete="off"
                        class="lieferbedingung-feld"
                    />
                    <v-row density="compact">
                        <v-col v-for="feld in ['days_min', 'days_max']" :key="feld" cols="6">
                            <v-text-field
                                v-model="eintrag[feld]"
                                type="number"
                                step="1"
                                min="0"
                                :label="t(feld === 'days_min' ? 'ShopView.deliveryTimes.daysMin' : 'ShopView.deliveryTimes.daysMax')"
                                :rules="[() => tageGueltig || t('ShopView.errors.DELIVERY_DAYS_INVALID')]"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                autocomplete="off"
                            />
                        </v-col>
                    </v-row>
                    <div class="text-caption text-medium-emphasis lieferbedingung-hinweis">{{ t('ShopView.deliveryTerm.daysHint') }}</div>
                    <!-- Enter in einem Feld sendet das Formular -->
                    <button type="submit" hidden />
                </v-form>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" :disabled="speichert" @click="$emit('update:modelValue', false)">{{ t('ShopView.common.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" :loading="speichert" @click="anlegen">{{ t('ShopView.deliveryTerm.create') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { shopFehler } from '../composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'created'])

const i18n = useI18n()
const { t } = i18n

const formular = ref(null)
const speichert = ref(false)
/** Meldung am Feld „Bezeichnung“ (Bezeichnung gibt es schon) */
const fehler = ref('')
const leererEintrag = () => ({ description: '', description_long: '', days_min: '', days_max: '' })
const eintrag = ref(leererEintrag())

const leer = wert => wert === null || wert === undefined || String(wert).trim() === ''
const tage = wert => (leer(wert) ? null : Number(wert))

/** Ganze Tage ab 0; „von“ nicht größer als „bis“ */
const tageGueltig = computed(() => {
    const von = tage(eintrag.value.days_min)
    const bis = tage(eintrag.value.days_max)
    const ganz = wert => wert === null || (Number.isInteger(wert) && wert >= 0)
    return ganz(von) && ganz(bis) && (von === null || bis === null || von <= bis)
})

// Jedes Öffnen beginnt leer
watch(() => props.modelValue, offen => {
    if (!offen) return
    eintrag.value = leererEintrag()
    fehler.value = ''
})

async function anlegen() {
    if (speichert.value) return
    const pruefung = await formular.value?.validate()
    if (!pruefung?.valid) return
    speichert.value = true
    try {
        const antwort = await axios.post('/api/shop/', {
            action: 'createShopDeliveryTerm',
            description: eintrag.value.description.trim(),
            description_long: String(eintrag.value.description_long || '').trim(),
            days_min: tage(eintrag.value.days_min),
            days_max: tage(eintrag.value.days_max),
        })
        if (!antwort.data?.success) {
            const meldung = shopFehler(antwort.data, i18n, 'ShopView.deliveryTerm.createError')
            if (meldung.code === 'DELIVERY_TERM_EXISTS') fehler.value = meldung.text
            else toasts.error(meldung.text)
            return
        }
        toasts.success(t('ShopView.deliveryTerm.created'))
        emit('created', antwort.data.payload?.delivery_term)
        emit('update:modelValue', false)
    } catch (e) {
        toasts.error(shopFehler(e?.response?.data, i18n, 'ShopView.deliveryTerm.createError').text)
    } finally {
        speichert.value = false
    }
}
</script>

<style scoped>
.lieferbedingung-feld {
    margin-bottom: var(--oserp-gap-sm);
}
.lieferbedingung-hinweis {
    margin-top: var(--oserp-gap-sm);
}
</style>
