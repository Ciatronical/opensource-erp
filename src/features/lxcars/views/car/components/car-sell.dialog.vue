<!-- src/features/lxcars/views/car/components/car-sell.dialog.vue -->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="680"
        persistent
        @keydown.esc="onClose"
    >
        <v-card rounded="lg">
            <!-- Header -->
            <v-card-title class="d-flex align-center pa-4 pb-3">
                <v-icon color="orange-darken-2" class="mr-2">mdi-tag-arrow-up-outline</v-icon>
                <div>
                    <div class="text-subtitle-1 font-weight-bold">{{ t('CarSellDialog.title') }}</div>
                    <div class="text-caption text-medium-emphasis">{{ car?.c_ln || '' }}</div>
                </div>
                <v-spacer />
                <v-btn icon variant="text" size="small" :disabled="loading" @click="onClose">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <!-- Schritt 1: Aktuelle Mängel -->
            <template v-if="step === 1">
                <v-card-text class="pa-4">
                    <p class="text-body-2 mb-3">
                        {{ t('CarSellDialog.step1Info') }}
                    </p>
                    <v-textarea
                        v-model="currentDefects"
                        :label="t('CarSellDialog.defectsLabel')"
                        :placeholder="t('CarSellDialog.defectsPlaceholder')"
                        rows="5"
                        auto-grow
                        variant="outlined"
                        density="comfortable"
                        autofocus
                    />
                    <v-alert type="info" variant="tonal" density="compact" class="mt-2 text-body-2">
                        {{ t('CarSellDialog.step1Hint') }}
                    </v-alert>
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-3 ga-2">
                    <v-btn variant="text" @click="onClose">
                        {{ t('CarSellDialog.cancel') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="tonal" color="orange-darken-2" :loading="loading" prepend-icon="mdi-creation" @click="generate">
                        {{ t('CarSellDialog.generate') }}
                    </v-btn>
                </v-card-actions>
            </template>

            <!-- Schritt 2: Generierter Verkaufstext -->
            <template v-else-if="step === 2">
                <v-card-text class="pa-4">
                    <v-alert v-if="savedBanner" type="success" variant="tonal" density="compact" class="mb-3" closable @click:close="savedBanner = false">
                        {{ t('CarSellDialog.savedSuccess') }}
                    </v-alert>
                    <div class="d-flex align-center mb-2 ga-2">
                        <span class="text-body-2 text-medium-emphasis flex-grow-1">{{ t('CarSellDialog.step2Info') }}</span>
                        <v-btn size="x-small" variant="text" color="primary" prepend-icon="mdi-content-copy" @click="copyText">
                            {{ t('CarSellDialog.copy') }}
                        </v-btn>
                    </div>
                    <v-textarea
                        v-model="generatedText"
                        variant="outlined"
                        rows="16"
                        auto-grow
                        density="comfortable"
                        style="font-family: monospace; font-size: 0.85rem;"
                    />
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-3 ga-2 flex-wrap">
                    <v-btn variant="text" @click="step = 1">
                        <v-icon start>mdi-arrow-left</v-icon>
                        {{ t('CarSellDialog.back') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="tonal" color="grey-darken-1" :loading="loading" prepend-icon="mdi-refresh" @click="generate">
                        {{ t('CarSellDialog.regenerate') }}
                    </v-btn>
                    <v-btn variant="tonal" color="success" :loading="saving" prepend-icon="mdi-content-save" @click="save">
                        {{ t('CarSellDialog.save') }}
                    </v-btn>
                </v-card-actions>
            </template>
        </v-card>
    </v-dialog>
</template>

<script>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import Swal from 'sweetalert2'

export default {
    name: 'CarSellDialog',

    props: {
        modelValue: { type: Boolean, default: false },
        car:        { type: Object,  default: null },
        cId:        { type: Number,  required: true }
    },

    emits: ['update:modelValue'],

    setup(props, { emit }) {
        const { t } = useI18n()
        const store = lxcarsStore()

        const step           = ref(1)
        const currentDefects = ref('')
        const generatedText  = ref('')
        const loading        = ref(false)
        const saving         = ref(false)
        const savedBanner    = ref(false)

        // Beim Öffnen: gespeicherten Text laden (falls vorhanden)
        watch(() => props.modelValue, async (opened) => {
            if (!opened) return
            step.value           = 1
            currentDefects.value = ''
            generatedText.value  = ''
            savedBanner.value    = false

            try {
                const result = await store.getSalesText(props.cId)
                if (result.exists && result.text) {
                    generatedText.value = result.text
                    step.value = 2
                }
            } catch {
                // kein gespeicherter Text vorhanden — Schritt 1 bleibt
            }
        })

        async function generate() {
            loading.value = true
            savedBanner.value = false
            try {
                const result = await store.generateSalesText(props.cId, currentDefects.value)
                generatedText.value = result.text
                step.value = 2
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: t('CarSellDialog.errorGenerate'),
                    text: err?.message || String(err),
                    confirmButtonText: 'OK'
                })
            } finally {
                loading.value = false
            }
        }

        async function save() {
            saving.value = true
            try {
                await store.saveSalesText(props.cId, generatedText.value)
                savedBanner.value = true
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: t('CarSellDialog.errorSave'),
                    text: err?.message || String(err),
                    confirmButtonText: 'OK'
                })
            } finally {
                saving.value = false
            }
        }

        async function copyText() {
            try {
                await navigator.clipboard.writeText(generatedText.value)
                Swal.fire({
                    toast: true, icon: 'success', position: 'top-end',
                    showConfirmButton: false, timer: 2000,
                    title: t('CarSellDialog.copied')
                })
            } catch {
                // Fallback: nichts tun
            }
        }

        function onClose() {
            if (loading.value) return
            emit('update:modelValue', false)
        }

        return {
            t, step, currentDefects, generatedText,
            loading, saving, savedBanner,
            generate, save, copyText, onClose
        }
    }
}
</script>
