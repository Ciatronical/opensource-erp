<!-- src/core/views/faktura/dialogs/edit.part.dialog.vue -->

<template>
    <v-dialog
        v-model="dialogVisible"
        max-width="600"
        @keydown.esc="close"
    >
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-primary text-white">
                <v-icon class="mr-2">mdi-pencil</v-icon>
                {{ t('FakturaView.dialogs.editItem.title') }}
            </v-card-title>

            <v-card-text class="pt-4">
                <v-row v-if="localItem" dense>
                    <!-- Artikelnummer (readonly) -->
                    <v-col cols="12" sm="4">
                        <v-text-field
                            :model-value="localItem.partnumber"
                            :label="t('FakturaView.faktura.partNumber')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            readonly
                            bg-color="grey-lighten-4"
                        />
                    </v-col>

                    <!-- Einheit -->
                    <v-col cols="6" sm="4">
                        <v-text-field
                            v-model="localItem.unit"
                            :label="t('FakturaView.faktura.unit')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                        />
                    </v-col>

                    <!-- Menge -->
                    <v-col cols="6" sm="4">
                        <v-text-field
                            v-model.number="localItem.qty"
                            :label="t('FakturaView.faktura.quantity')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            type="number"
                            step="0.01"
                        />
                    </v-col>

                    <!-- Beschreibung -->
                    <v-col cols="12">
                        <v-text-field
                            v-model="localItem.description"
                            :label="t('FakturaView.faktura.description')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                        />
                    </v-col>

                    <!-- Langbeschreibung -->
                    <v-col cols="12">
                        <v-textarea
                            v-model="localItem.longdescription"
                            :label="t('FakturaView.faktura.longDescription')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            rows="3"
                            auto-grow
                        />
                    </v-col>

                    <!-- Preis -->
                    <v-col cols="6" sm="4">
                        <v-text-field
                            :model-value="priceDisplay"
                            @update:model-value="priceRaw = $event"
                            @focus="onPriceFocus"
                            @blur="onPriceBlur"
                            @keydown.enter="onPriceBlur"
                            :label="t('FakturaView.faktura.price')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            prefix="€"
                        />
                    </v-col>

                    <!-- Rabatt (Eingabe in Prozent, gespeichert als Faktor 0-1) -->
                    <v-col cols="6" sm="4">
                        <v-text-field
                            v-model.number="discountPercent"
                            :label="t('FakturaView.faktura.discount')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            type="number"
                            step="0.01"
                            suffix="%"
                        />
                    </v-col>

                    <!-- Berechneter Gesamtpreis (readonly) -->
                    <v-col cols="12" sm="4">
                        <v-text-field
                            :model-value="calculatedTotal"
                            :label="t('FakturaView.faktura.total')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            readonly
                            bg-color="grey-lighten-4"
                            prefix="€"
                        />
                    </v-col>

                    <!-- Checkbox: Auch Stammdaten aktualisieren -->
                    <v-col cols="12">
                        <v-checkbox
                            v-model="updateMasterData"
                            :label="t('FakturaView.dialogs.editItem.updateMasterData')"
                            :hint="t('FakturaView.dialogs.editItem.updateMasterDataHint')"
                            persistent-hint
                            density="compact"
                            color="primary"
                        />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="close"
                >
                    {{ t('FakturaView.dialogs.editItem.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="elevated"
                    prepend-icon="mdi-content-save"
                    :loading="saving"
                    @click="save"
                >
                    {{ t('FakturaView.dialogs.editItem.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { parseNumber, formatNumber } from '@/core/utils/numberFormat.js'

const { t } = useI18n()

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false
    },
    item: {
        type: Object,
        default: null
    }
})

const emit = defineEmits(['update:modelValue', 'save'])

const localItem = ref(null)
const updateMasterData = ref(true)  // Default: aktiviert
const saving = ref(false)

// v-model Binding für Dialog
const dialogVisible = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
})

// Item kopieren wenn Dialog geöffnet wird
watch(() => props.modelValue, (newValue) => {
    if (newValue && props.item) {
        localItem.value = { ...props.item }
        updateMasterData.value = true  // Default: aktiviert
        saving.value = false
    }
}, { immediate: true })

// Item aktualisieren wenn sich das Prop ändert
watch(() => props.item, (newItem) => {
    if (props.modelValue && newItem) {
        localItem.value = { ...newItem }
    }
})

// Preis-Feld mit Rechenunterstützung
const priceRaw = ref(null)
const priceEditing = ref(false)
const currentLocale = computed(() => useI18n().locale.value)

const priceDisplay = computed(() => {
    if (priceEditing.value && priceRaw.value !== null) return priceRaw.value
    return formatNumber(localItem.value?.sellprice || 0, currentLocale.value, 2)
})

function onPriceFocus(e) {
    priceEditing.value = true
    priceRaw.value = formatNumber(localItem.value?.sellprice || 0, currentLocale.value, 2)
    e.target.select()
}

function onPriceBlur() {
    if (priceRaw.value !== null) {
        const parsed = parseNumber(priceRaw.value, currentLocale.value)
        if (parsed !== null && Number.isFinite(parsed)) {
            localItem.value.sellprice = parsed
        }
    }
    priceEditing.value = false
    priceRaw.value = null
}

// Rabatt-Anzeige (Prozent) <-> Speicherung (Faktor 0-1)
const discountPercent = computed({
    get: () => {
        if (!localItem.value) return 0
        return (localItem.value.discount || 0) * 100
    },
    set: (value) => {
        if (!localItem.value) return
        const num = Number(value)
        localItem.value.discount = Number.isFinite(num) ? num / 100 : 0
    }
})

// Berechneter Gesamtpreis
const calculatedTotal = computed(() => {
    if (!localItem.value || !localItem.value.qty || !localItem.value.sellprice) {
        return '0,00'
    }
    const subtotal = localItem.value.qty * localItem.value.sellprice
    const discountAmount = subtotal * (localItem.value.discount || 0)
    const total = subtotal - discountAmount
    return total.toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })
})

// Dialog schließen
function close() {
    emit('update:modelValue', false)
}

// Speichern
function save() {
    saving.value = true
    emit('save', {
        item: { ...localItem.value },
        updateMasterData: updateMasterData.value
    })
}

// Saving-Status zurücksetzen wenn Dialog geschlossen wird
function setSavingDone() {
    saving.value = false
}

// Expose für Parent-Komponente
defineExpose({
    setSavingDone
})
</script>