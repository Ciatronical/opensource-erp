<!-- src/core/views/customer-vendor/tabs/custom-vars.tab.vue -->
<template>
    <v-row class="pa-2 pa-sm-3">
        <v-col cols="12">
            <v-card variant="outlined" elevation="1">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                    <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.customVars.title') }}</h4>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-3 px-2 px-sm-3">
                    <v-alert v-if="!rows.length" type="info" density="compact" variant="tonal">
                        {{ t('CustomerVendorEditView.customVars.noConfigs') }}
                    </v-alert>

                    <v-row v-for="row in rows" :key="row.config_id" no-gutters class="mb-1">
                        <v-col cols="12">
                            <!-- Boolean -->
                            <v-checkbox
                                v-if="row.type === 'bool' || row.type === 'boolean'"
                                v-model="row.model"
                                :label="row.description"
                                density="compact"
                                hide-details
                                @update:model-value="emitChange"
                            />
                            <!-- Auswahl -->
                            <v-select
                                v-else-if="row.type === 'select'"
                                v-model="row.model"
                                :items="parseOptions(row.options)"
                                :label="row.description"
                                density="compact"
                                variant="outlined"
                                clearable
                                hide-details
                                @update:model-value="emitChange"
                            />
                            <!-- Zahl -->
                            <v-text-field
                                v-else-if="row.type === 'number'"
                                v-model="row.model"
                                :label="row.description"
                                type="number"
                                density="compact"
                                variant="outlined"
                                hide-details
                                @update:model-value="emitChange"
                            />
                            <!-- Datum -->
                            <v-text-field
                                v-else-if="row.type === 'date' || row.type === 'timestamp'"
                                v-model="row.model"
                                :label="row.description"
                                type="date"
                                density="compact"
                                variant="outlined"
                                hide-details
                                @update:model-value="emitChange"
                            />
                            <!-- Mehrzeiliger Text -->
                            <v-textarea
                                v-else-if="row.type === 'textfield' || row.type === 'textarea' || row.type === 'htmleditor'"
                                v-model="row.model"
                                :label="row.description"
                                rows="2"
                                auto-grow
                                density="compact"
                                variant="outlined"
                                hide-details
                                @update:model-value="emitChange"
                            />
                            <!-- Einzeiliger Text (Default) -->
                            <v-text-field
                                v-else
                                v-model="row.model"
                                :label="row.description"
                                density="compact"
                                variant="outlined"
                                hide-details
                                @update:model-value="emitChange"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>
        </v-col>
    </v-row>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => []
    }
})
const emit = defineEmits(['update:modelValue'])

const rows = ref([])
// Verhindert, dass der eigene Emit den Watcher die Eingabe zuruecksetzen laesst
let internalUpdate = false

// Initialwert je nach Typ aus der passenden Wertespalte bilden
function initModel(cv) {
    switch (cv.type) {
        case 'bool':
        case 'boolean':
            return cv.bool_value === true || cv.bool_value === 't'
        case 'number':
            return cv.number_value ?? ''
        case 'date':
        case 'timestamp':
            return cv.timestamp_value ? String(cv.timestamp_value).substring(0, 10) : ''
        default:
            return cv.text_value ?? ''
    }
}

// Nur bei externen Aenderungen (Datensatzwechsel/Reload) neu aufbauen,
// nicht bei eigenen Emits waehrend des Tippens
watch(() => props.modelValue, (list) => {
    if (internalUpdate) {
        internalUpdate = false
        return
    }
    rows.value = (list || []).map(cv => ({
        config_id: cv.config_id,
        name: cv.name,
        description: cv.description,
        type: cv.type,
        options: cv.options,
        model: initModel(cv)
    }))
}, { immediate: true, deep: true })

function parseOptions(options) {
    if (!options) return []
    return String(options)
        .split(/\r?\n|##/)
        .map(o => o.trim())
        .filter(o => o.length)
        .map(o => ({ title: o, value: o }))
}

// Geänderte Variablen an das Elternteil melden — Backend liest config_id + type + value
function emitChange() {
    internalUpdate = true
    emit('update:modelValue', rows.value.map(r => ({
        config_id: r.config_id,
        type: r.type,
        value: r.model
    })))
}
</script>

<style scoped>
.bg-grey-lighten-4 { background-color: #f5f5f5; }
</style>
