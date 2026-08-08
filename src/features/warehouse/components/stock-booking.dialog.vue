<!-- src/features/warehouse/components/stock-booking.dialog.vue -->
<!--
    Ein Dialog fuer alle drei Lagerbuchungen. Statt den Anwender vorab
    entscheiden zu lassen, welche Maske er braucht, schaltet er hier oben um —
    das Formular passt sich an. Zielfelder erscheinen nur beim Umlagern.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="620" @update:model-value="close">
        <v-card rounded="lg">
            <v-card-title class="d-flex align-center pa-4 pb-2">
                <v-icon :color="directionColor" class="mr-2">{{ directionIcon }}</v-icon>
                <span class="text-subtitle-1 font-weight-bold">{{ t('WarehouseView.booking.title') }}</span>
                <v-spacer />
                <v-btn icon variant="text" size="small" @click="close(false)">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <v-card-text class="pt-4">
                <!-- Richtung -->
                <v-btn-toggle v-model="form.direction" mandatory divided variant="outlined"
                              class="mb-4 w-100" density="comfortable">
                    <v-btn value="in" class="flex-grow-1">
                        <v-icon start size="small">mdi-tray-arrow-down</v-icon>
                        {{ t('WarehouseView.booking.in') }}
                    </v-btn>
                    <v-btn value="out" class="flex-grow-1">
                        <v-icon start size="small">mdi-tray-arrow-up</v-icon>
                        {{ t('WarehouseView.booking.out') }}
                    </v-btn>
                    <v-btn value="transfer" class="flex-grow-1">
                        <v-icon start size="small">mdi-swap-horizontal</v-icon>
                        {{ t('WarehouseView.booking.transfer') }}
                    </v-btn>
                </v-btn-toggle>

                <!-- Artikel -->
                <v-autocomplete
                    v-model="form.part"
                    v-model:search="partSearch"
                    :items="partItems"
                    :loading="searching"
                    item-title="label"
                    item-value="id"
                    return-object
                    :label="t('WarehouseView.booking.part')"
                    :hint="t('WarehouseView.booking.partHint')"
                    persistent-hint
                    variant="outlined"
                    density="compact"
                    no-filter
                    hide-no-data
                    autofocus
                    class="mb-4"
                />

                <v-row dense>
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="form.qty"
                            :label="t('WarehouseView.booking.qty')"
                            :suffix="form.part?.unit || ''"
                            type="number"
                            min="0"
                            step="any"
                            variant="outlined"
                            density="compact"
                        />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="form.shippingdate"
                            :label="t('WarehouseView.booking.date')"
                            type="date"
                            variant="outlined"
                            density="compact"
                        />
                    </v-col>
                </v-row>

                <!-- Quelle bzw. Ziel bei Einlagerung -->
                <div class="text-caption text-medium-emphasis mt-2 mb-1">
                    {{ form.direction === 'in' ? t('WarehouseView.booking.target') : t('WarehouseView.booking.source') }}
                </div>
                <v-row dense>
                    <v-col cols="12" sm="6">
                        <v-select
                            v-model="form.warehouse_id"
                            :items="warehouses"
                            item-title="description"
                            item-value="id"
                            :label="t('WarehouseView.warehouse')"
                            variant="outlined"
                            density="compact"
                            @update:model-value="form.bin_id = binsOf(form.warehouse_id)[0]?.id ?? null"
                        />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-select
                            v-model="form.bin_id"
                            :items="binsOf(form.warehouse_id)"
                            item-title="description"
                            item-value="id"
                            :label="t('WarehouseView.bin')"
                            variant="outlined"
                            density="compact"
                        />
                    </v-col>
                </v-row>

                <!-- Ziel nur beim Umlagern -->
                <template v-if="form.direction === 'transfer'">
                    <div class="text-caption text-medium-emphasis mt-2 mb-1">{{ t('WarehouseView.booking.target') }}</div>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-select
                                v-model="form.target_warehouse_id"
                                :items="warehouses"
                                item-title="description"
                                item-value="id"
                                :label="t('WarehouseView.warehouse')"
                                variant="outlined"
                                density="compact"
                                @update:model-value="form.target_bin_id = binsOf(form.target_warehouse_id)[0]?.id ?? null"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-select
                                v-model="form.target_bin_id"
                                :items="binsOf(form.target_warehouse_id)"
                                item-title="description"
                                item-value="id"
                                :label="t('WarehouseView.bin')"
                                variant="outlined"
                                density="compact"
                            />
                        </v-col>
                    </v-row>
                </template>

                <!-- Verfuegbarer Bestand am gewaehlten Platz -->
                <v-alert v-if="availableHint !== null" type="info" variant="tonal" density="compact" class="mt-3">
                    {{ t('WarehouseView.booking.available', { qty: availableHint, unit: form.part?.unit || '' }) }}
                </v-alert>

                <v-expansion-panels variant="accordion" class="mt-3">
                    <v-expansion-panel :title="t('WarehouseView.booking.more')">
                        <v-expansion-panel-text>
                            <v-row dense>
                                <v-col cols="12" sm="6">
                                    <v-select
                                        v-model="form.transfer_type_id"
                                        :items="typeItems"
                                        item-title="label"
                                        item-value="id"
                                        :label="t('WarehouseView.booking.type')"
                                        clearable
                                        variant="outlined"
                                        density="compact"
                                    />
                                </v-col>
                                <v-col cols="12" sm="6">
                                    <v-text-field
                                        v-model="form.chargenumber"
                                        :label="t('WarehouseView.booking.charge')"
                                        variant="outlined"
                                        density="compact"
                                    />
                                </v-col>
                                <v-col v-if="showBestbefore" cols="12" sm="6">
                                    <v-text-field
                                        v-model="form.bestbefore"
                                        :label="t('WarehouseView.booking.bestbefore')"
                                        type="date"
                                        variant="outlined"
                                        density="compact"
                                    />
                                </v-col>
                                <v-col cols="12">
                                    <v-text-field
                                        v-model="form.comment"
                                        :label="t('WarehouseView.booking.comment')"
                                        variant="outlined"
                                        density="compact"
                                    />
                                </v-col>
                            </v-row>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-3">
                <v-spacer />
                <v-btn variant="text" @click="close(false)">{{ t('WarehouseView.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" :loading="saving" :disabled="!canSave" @click="submit">
                    {{ t('WarehouseView.booking.book') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWarehouse } from '../composables/useWarehouse.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    warehouses: { type: Array, default: () => [] },
    transferTypes: { type: Array, default: () => [] },
    showBestbefore: { type: Boolean, default: false },
    // Vorbelegung, wenn aus der Bestandsliste heraus gebucht wird
    presetPart: { type: Object, default: null },
    presetDirection: { type: String, default: 'in' },
})
const emit = defineEmits(['update:modelValue', 'booked'])

const { t } = useI18n()
const { lookupCode, bookStock, fetchPartStock } = useWarehouse()

const saving = ref(false)
const searching = ref(false)
const partSearch = ref('')
const partItems = ref([])
const availableHint = ref(null)
const locations = ref([])

const form = ref(emptyForm())

function emptyForm() {
    return {
        direction: props.presetDirection || 'in',
        part: null,
        qty: null,
        warehouse_id: null,
        bin_id: null,
        target_warehouse_id: null,
        target_bin_id: null,
        transfer_type_id: null,
        chargenumber: '',
        bestbefore: '',
        comment: '',
        shippingdate: new Date().toISOString().slice(0, 10),
    }
}

const directionIcon = computed(() => ({
    in: 'mdi-tray-arrow-down', out: 'mdi-tray-arrow-up', transfer: 'mdi-swap-horizontal',
}[form.value.direction]))

const directionColor = computed(() => ({
    in: 'success', out: 'warning', transfer: 'info',
}[form.value.direction]))

const typeItems = computed(() =>
    props.transferTypes
        .filter(x => x.direction === form.value.direction)
        .map(x => ({ id: x.id, label: t(`WarehouseView.transferTypes.${x.description}`) }))
)

const canSave = computed(() =>
    !!form.value.part && Number(form.value.qty) > 0 && !!form.value.bin_id &&
    (form.value.direction !== 'transfer' || !!form.value.target_bin_id)
)

function binsOf(warehouseId) {
    return props.warehouses.find(w => w.id === warehouseId)?.bins || []
}

// Artikelsuche — dieselbe Aufloesung wie im Scanner-Modus
let searchTimer = null
watch(partSearch, (term) => {
    if (searchTimer) clearTimeout(searchTimer)
    if (!term || term.length < 2) { partItems.value = []; return }
    searchTimer = setTimeout(async () => {
        searching.value = true
        try {
            const res = await lookupCode(term)
            partItems.value = (res.items || []).map(p => ({
                ...p, label: `${p.partnumber} — ${p.description}`,
            }))
        } catch { partItems.value = [] } finally { searching.value = false }
    }, 250)
})

// Bestand am gewaehlten Platz anzeigen, sobald Artikel und Platz feststehen
watch(() => [form.value.part?.id, form.value.bin_id, form.value.chargenumber], async () => {
    availableHint.value = null
    if (!form.value.part?.id || !form.value.bin_id) return
    if (!locations.value.length || locations.value[0]?.parts_id !== form.value.part.id) {
        const res = await fetchPartStock(form.value.part.id)
        locations.value = (res.locations || []).map(l => ({ ...l, parts_id: form.value.part.id }))
    }
    const hit = locations.value.find(l =>
        l.bin_id === form.value.bin_id && (l.chargenumber || '') === (form.value.chargenumber || ''))
    availableHint.value = hit ? Number(hit.qty) : 0
})

watch(() => props.modelValue, (open) => {
    if (!open) return
    form.value = emptyForm()
    locations.value = []
    availableHint.value = null
    const first = props.warehouses[0]
    form.value.warehouse_id = first?.id ?? null
    form.value.bin_id = first?.bins?.[0]?.id ?? null
    form.value.target_warehouse_id = first?.id ?? null
    form.value.target_bin_id = first?.bins?.[1]?.id ?? first?.bins?.[0]?.id ?? null
    if (props.presetPart) {
        form.value.part = { ...props.presetPart, label: `${props.presetPart.partnumber} — ${props.presetPart.description}` }
        partItems.value = [form.value.part]
    }
})

async function submit() {
    saving.value = true
    try {
        await bookStock({
            direction: form.value.direction,
            parts_id: form.value.part.id,
            qty: Number(form.value.qty),
            warehouse_id: form.value.warehouse_id,
            bin_id: form.value.bin_id,
            target_warehouse_id: form.value.target_warehouse_id,
            target_bin_id: form.value.target_bin_id,
            transfer_type_id: form.value.transfer_type_id,
            chargenumber: form.value.chargenumber,
            bestbefore: form.value.bestbefore,
            comment: form.value.comment,
            shippingdate: form.value.shippingdate,
        })
        alerts.success(t('WarehouseView.booking.done'))
        emit('booked')
        close(false)
    } catch (e) {
        if (e.code === 'NOT_ENOUGH_STOCK') {
            alerts.error(t('WarehouseView.booking.notEnough', {
                available: e.payload?.available ?? 0,
                requested: e.payload?.requested ?? 0,
            }))
        } else {
            alerts.error(e.message)
        }
    } finally {
        saving.value = false
    }
}

function close() {
    emit('update:modelValue', false)
}
</script>
