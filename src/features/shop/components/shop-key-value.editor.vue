<!-- src/features/shop/components/shop-key-value.editor.vue -->
<!--
    Tabelle aus Bezeichnung und Wert für die JSON-Objekte der Shop-Angaben
    (technische Daten, Eigenschaften, Downloads).

    Arbeitet auf einer Liste von Paaren statt auf dem Objekt: so sind leere und
    gleichnamige Zeilen während der Eingabe möglich. Zum Objekt wird die Liste
    erst beim Speichern (part-shop.card.vue).
-->
<template>
    <div>
        <div class="text-caption text-medium-emphasis mb-1">{{ title }}</div>
        <v-row v-for="(zeile, i) in modelValue" :key="i" dense class="align-center">
            <v-col cols="5" class="py-1">
                <v-text-field
                    v-model="zeile.key"
                    :label="keyLabel"
                    :readonly="readonly"
                    variant="outlined"
                    density="compact"
                    hide-details
                    autocomplete="off"
                />
            </v-col>
            <v-col cols="6" class="py-1">
                <v-text-field
                    v-model="zeile.value"
                    :label="valueLabel"
                    :readonly="readonly"
                    variant="outlined"
                    density="compact"
                    hide-details
                    autocomplete="off"
                />
            </v-col>
            <v-col cols="1" class="py-1 text-center">
                <v-btn
                    v-if="!readonly"
                    icon="mdi-close"
                    size="small"
                    variant="text"
                    :title="t('ShopView.partCard.removeRow')"
                    @click="entfernen(i)"
                />
            </v-col>
        </v-row>
        <v-btn
            v-if="!readonly"
            variant="text"
            size="small"
            prepend-icon="mdi-plus"
            class="mt-1"
            @click="hinzufuegen"
        >
            {{ t('ShopView.partCard.addRow') }}
        </v-btn>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    /** Liste aus { key, value } */
    modelValue: { type: Array, required: true },
    title: { type: String, default: '' },
    keyLabel: { type: String, default: '' },
    valueLabel: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

function hinzufuegen() {
    emit('update:modelValue', [...props.modelValue, { key: '', value: '' }])
}

function entfernen(index) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index))
}
</script>
