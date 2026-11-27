<!-- src/core/views/customer-vendor/tabs/vendor-part-sync-curves.dialog.vue -->
<!--
    Preiskurven des Artikelabgleichs bearbeiten: anlegen, ändern, kopieren,
    löschen (vendor_part_sync_curves).

    Eine Kurve ist eine Liste von Stützpunkten — Einkaufspreis und Aufschlag in
    Prozent. Gerechnet wird nur in der Datenbank: die Rechenbeispiele unter den
    Stützpunkten holt der Dialog von dort (getVendorPartSyncCurvePreview), auch
    für noch nicht gespeicherte Eingaben.

        <VendorPartSyncCurvesDialog v-model="offen" :curves="curves" :curve-id="…" @saved="load" />
-->
<template>
    <v-dialog v-model="offen" max-width="860" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center bg-primary text-white py-2 px-3">
                <v-icon start>mdi-chart-bell-curve-cumulative</v-icon>
                {{ t('VendorPartSync.curvesEdit') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="x-small" variant="text" color="white" @click="offen = false" />
            </v-card-title>

            <v-divider />

            <v-card-text class="vpc-body">
                <v-form ref="formular" @submit.prevent="speichern">
                    <div class="vpc-row">
                        <v-select
                            :model-value="kurve.id"
                            :items="auswahl"
                            :label="t('VendorPartSync.curve')"
                            density="compact"
                            variant="outlined"
                            hide-details
                            class="vpc-grow"
                            @update:model-value="waehlen"
                        />
                        <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="waehlen(0)">{{ t('VendorPartSync.curveNew') }}</v-btn>
                        <v-btn size="small" variant="tonal" prepend-icon="mdi-content-copy" :disabled="!kurve.id" @click="kopieren">{{ t('VendorPartSync.curveCopy') }}</v-btn>
                    </div>

                    <v-text-field
                        v-model="kurve.name"
                        :label="t('VendorPartSync.curveName')"
                        density="compact"
                        variant="outlined"
                        hide-details="auto"
                        class="vpc-block"
                        :rules="[nameRegel]"
                    />
                    <v-textarea
                        v-model="kurve.description"
                        :label="t('VendorPartSync.curveDescription')"
                        density="compact"
                        variant="outlined"
                        hide-details
                        rows="3"
                        auto-grow
                        class="vpc-block"
                    />

                    <div class="vpc-subtitle">{{ t('VendorPartSync.curvePoints') }}</div>
                    <div v-for="(punkt, index) in kurve.points" :key="punkt.key" class="vpc-row vpc-point">
                        <v-text-field
                            v-model="punkt.cost"
                            type="number"
                            min="0"
                            step="any"
                            suffix="€"
                            :label="t('VendorPartSync.curvePointCost')"
                            density="compact"
                            variant="outlined"
                            hide-details="auto"
                            :rules="[kostenRegel, (wert) => einmalRegel(wert, index)]"
                        />
                        <v-text-field
                            v-model="punkt.percent"
                            type="number"
                            min="0"
                            step="any"
                            suffix="%"
                            :label="t('VendorPartSync.markup')"
                            density="compact"
                            variant="outlined"
                            hide-details="auto"
                            :rules="[prozentRegel]"
                        />
                        <v-btn
                            icon="mdi-delete-outline"
                            size="small"
                            variant="text"
                            :disabled="kurve.points.length < 2"
                            :title="t('VendorPartSync.curvePointRemove')"
                            @click="kurve.points.splice(index, 1)"
                        />
                    </div>
                    <v-btn size="small" variant="text" prepend-icon="mdi-plus" class="vpc-block" @click="punktHinzu">{{ t('VendorPartSync.curvePointAdd') }}</v-btn>
                    <div class="vpc-hint">{{ t('VendorPartSync.curvePointsHint') }}</div>

                    <!-- Rechenbeispiele aus der Datenbank, auch für ungespeicherte Eingaben -->
                    <div class="vpc-subtitle">{{ t('VendorPartSync.curvePreview') }}</div>
                    <v-table v-if="beispiele.length" density="compact">
                        <tbody>
                            <tr>
                                <th>{{ t('VendorPartSync.field_lastcost') }}</th>
                                <td v-for="beispiel in beispiele" :key="beispiel.cost" class="text-right">{{ geld(beispiel.cost) }}</td>
                            </tr>
                            <tr>
                                <th>{{ t('VendorPartSync.markup') }}</th>
                                <td v-for="beispiel in beispiele" :key="beispiel.cost" class="text-right">{{ n(Number(beispiel.percent), { maximumFractionDigits: 1 }) }} %</td>
                            </tr>
                            <tr>
                                <th>{{ t('VendorPartSync.field_sellprice') }}</th>
                                <td v-for="beispiel in beispiele" :key="beispiel.cost" class="text-right">{{ geld(beispiel.price) }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                    <div v-else class="vpc-hint">{{ t('VendorPartSync.curvePreviewEmpty') }}</div>

                    <v-alert v-if="kurve.id" type="info" variant="tonal" density="compact" class="vpc-block">
                        {{ t('VendorPartSync.curveLiveHint') }}
                    </v-alert>
                </v-form>
            </v-card-text>

            <v-divider />

            <v-card-actions class="px-3">
                <v-btn v-if="kurve.id" color="error" variant="text" prepend-icon="mdi-delete-outline" :loading="loescht" @click="loeschen">
                    {{ t('VendorPartSync.curveDelete') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" @click="offen = false">{{ t('VendorPartSync.curveClose') }}</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :loading="speichert" @click="speichern">
                    {{ t('VendorPartSync.curveSave') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useVendorPartSync } from '@/core/composables/useVendorPartSync.js'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // Preiskurven aus getVendorPartSync (id, name, description, points)
    curves: { type: Array, default: () => [] },
    // Kurve, die beim Öffnen gezeigt wird; 0 = die erste
    curveId: { type: Number, default: 0 },
})
// saved: es wurde gespeichert oder gelöscht (id der Kurve, 0 nach dem Löschen) — die Liste neu laden
const emit = defineEmits(['update:modelValue', 'saved'])

const { t, te, n } = useI18n()
const api = useVendorPartSync()

const offen = computed({
    get: () => props.modelValue,
    set: (wert) => emit('update:modelValue', wert),
})

const formular = ref(null)
const kurve = ref({ id: 0, name: '', description: '', points: [] })
const beispiele = ref([])
const speichert = ref(false)
const loescht = ref(false)
// Nach dem Speichern diese Kurve zeigen, sobald die neue Liste da ist
let merken = null
let punktNr = 0
let vorschauTimer = null
// Die Vorschau folgt der Eingabe mit etwas Abstand, nicht jedem Tastendruck
const VORSCHAU_NACH_MS = 400

const auswahl = computed(() => [
    ...props.curves.map((eintrag) => ({ value: eintrag.id, title: eintrag.name })),
    ...(kurve.value.id ? [] : [{ value: 0, title: t('VendorPartSync.curveNew') }]),
])

const punkt = (cost = '', percent = '') => ({ key: ++punktNr, cost, percent })

/** Kurve in die Maske holen; 0 = leere, neue Kurve */
function waehlen(id) {
    const quelle = props.curves.find((eintrag) => eintrag.id === id)
    kurve.value = quelle
        ? {
            id: quelle.id,
            name: quelle.name,
            description: quelle.description || '',
            points: (quelle.points || []).map((eintrag) => punkt(eintrag.cost, eintrag.percent)),
        }
        : { id: 0, name: '', description: '', points: [punkt()] }
    formular.value?.resetValidation()
}

/** Die gezeigte Kurve als Vorlage einer neuen */
function kopieren() {
    kurve.value = { ...kurve.value, id: 0, name: t('VendorPartSync.curveCopyName', { name: kurve.value.name }) }
}

function punktHinzu() {
    kurve.value.points.push(punkt())
}

const zahl = (wert) => (wert === '' || wert === null || wert === undefined ? NaN : Number(wert))
const nameRegel = (wert) => !!String(wert ?? '').trim() || t('VendorPartSync.curveNameRequired')
const kostenRegel = (wert) => zahl(wert) > 0 || t('VendorPartSync.curveCostInvalid')
const prozentRegel = (wert) => zahl(wert) >= 0 || t('VendorPartSync.rangeInvalid')
const einmalRegel = (wert, index) =>
    !kurve.value.points.some((eintrag, i) => i !== index && zahl(eintrag.cost) === zahl(wert)) || t('VendorPartSync.curveCostTwice')

/** Stützpunkte, wie sie die Datenbank annimmt: nur die vollständig ausgefüllten */
const gueltigePunkte = computed(() => kurve.value.points
    .filter((eintrag) => zahl(eintrag.cost) > 0 && zahl(eintrag.percent) >= 0)
    .map((eintrag) => ({ cost: zahl(eintrag.cost), percent: zahl(eintrag.percent) })))

async function vorschauLaden() {
    vorschauTimer = null
    if (!gueltigePunkte.value.length) {
        beispiele.value = []
        return
    }
    try {
        beispiele.value = (await api.previewCurve(gueltigePunkte.value))?.examples || []
    } catch (e) {
        beispiele.value = []
    }
}

watch(gueltigePunkte, () => {
    clearTimeout(vorschauTimer)
    vorschauTimer = setTimeout(vorschauLaden, VORSCHAU_NACH_MS)
}, { deep: true })

const fehlertext = (e) => (te('VendorPartSync.err_' + e.code) ? t('VendorPartSync.err_' + e.code) : e.message)

async function speichern() {
    const pruefung = await formular.value?.validate()
    if (pruefung && !pruefung.valid) return
    speichert.value = true
    try {
        const antwort = await api.saveCurve({
            id: kurve.value.id,
            name: kurve.value.name.trim(),
            description: kurve.value.description.trim(),
            points: gueltigePunkte.value,
        })
        merken = antwort.id
        toasts.success(t('VendorPartSync.curveSaved'))
        emit('saved', antwort.id)
    } catch (e) {
        alerts.error(fehlertext(e))
    } finally {
        speichert.value = false
    }
}

async function loeschen() {
    const antwort = await alerts.warning(
        t('VendorPartSync.curveDeleteConfirm', { name: kurve.value.name }), '',
        t('VendorPartSync.curveDelete'), t('VendorPartSync.abort'),
    )
    if (!antwort.isConfirmed) return
    loescht.value = true
    try {
        await api.deleteCurve(kurve.value.id)
        merken = 0
        toasts.success(t('VendorPartSync.curveDeleted'))
        emit('saved', 0)
    } catch (e) {
        alerts.error(fehlertext(e))
    } finally {
        loescht.value = false
    }
}

// Beim Öffnen die gewünschte Kurve zeigen, sonst die erste
watch(offen, (istOffen) => {
    if (istOffen) {
        waehlen(props.curves.some((eintrag) => eintrag.id === props.curveId) ? props.curveId : (props.curves[0]?.id ?? 0))
    }
})

// Neue Liste nach dem Speichern oder Löschen: die gespeicherte Kurve wieder zeigen
watch(() => props.curves, () => {
    if (!offen.value || merken === null) return
    waehlen(props.curves.some((eintrag) => eintrag.id === merken) ? merken : (props.curves[0]?.id ?? 0))
    merken = null
})

function geld(wert) {
    return n(Number(wert), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

onBeforeUnmount(() => clearTimeout(vorschauTimer))
</script>

<style scoped>
/* Abstände aus den Tokens (src/core/styles/tokens.css) */
.vpc-body {
    padding: var(--oserp-gap-lg);
}

.vpc-row {
    display: flex;
    align-items: flex-start;
    gap: var(--oserp-gap-sm);
}

.vpc-grow {
    flex: 1 1 auto;
}

.vpc-block {
    margin-top: var(--oserp-gap);
}

.vpc-point {
    margin-top: var(--oserp-gap-sm);
}

.vpc-subtitle {
    margin-top: var(--oserp-gap-lg);
    font-weight: var(--oserp-section-title-weight);
}

.vpc-hint {
    margin-top: var(--oserp-gap-sm);
    color: var(--oserp-text-muted);
    font-size: 0.875rem;
}
</style>
