<template>
    <v-dialog v-model="dialogVisible" max-width="450" persistent>
        <v-card rounded="lg">
            <v-card-title class="d-flex align-center bg-grey-lighten-4 py-3">
                <v-icon color="primary" class="me-2">mdi-phone-settings</v-icon>
                {{ t('PhoneActions.configTitle') }}
            </v-card-title>
            <v-divider />
            <v-card-text class="py-4">
                <v-select
                    v-model="selectedContext"
                    :label="t('PhoneActions.externalContext')"
                    :items="contextItems"
                    variant="outlined"
                    density="compact"
                    hide-details="auto"
                    class="mb-4"
                />
                <v-select
                    v-model="selectedPhone"
                    :label="t('PhoneActions.internalPhone')"
                    :items="phoneItems"
                    variant="outlined"
                    density="compact"
                    hide-details="auto"
                />
            </v-card-text>
            <v-divider />
            <v-card-actions class="pa-3">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="onCancel"
                >
                    {{ t('PhoneActions.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    :disabled="!selectedContext || !selectedPhone"
                    prepend-icon="mdi-phone"
                    @click="onCall"
                >
                    {{ t('PhoneActions.call') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePhoneActions } from '@/core/composables/usePhoneActions.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    phone: { type: String, default: '' },
    name: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const { clickToCall, loadPhoneConfig, savePhoneConfig } = usePhoneActions()

const dialogVisible = computed({
    get: () => props.modelValue,
    set: (val) => emit('update:modelValue', val)
})

const contextItems = ref([])
const phoneItems = ref([])
const selectedContext = ref('')
const selectedPhone = ref('')

watch(() => props.modelValue, async (visible) => {
    if (!visible) return
    const config = await loadPhoneConfig()
    contextItems.value = (config.external_contexts || '')
        .split(',')
        .map(s => s.trim())
        .filter(Boolean)
    phoneItems.value = (config.internal_phones || '')
        .split(',')
        .map(s => s.trim())
        .filter(Boolean)
    selectedContext.value = config.user_external_context || contextItems.value[0] || ''
    selectedPhone.value = config.user_internal_phone || phoneItems.value[0] || ''
})

async function onCall() {
    await savePhoneConfig(selectedContext.value, selectedPhone.value)
    dialogVisible.value = false
    clickToCall(props.phone, props.name)
}

function onCancel() {
    dialogVisible.value = false
}
</script>
