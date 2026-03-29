<!-- src/core/views/faktura/dialogs/send.whatsapp.dialog.vue -->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="600"
        persistent
    >
        <v-card>
            <v-card-title class="d-flex align-center bg-green-darken-1">
                <v-icon class="mr-2">mdi-whatsapp</v-icon>
                {{ t('FakturaView.dialogs.sendWhatsApp.title') }}
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    density="compact"
                    size="x-small"
                    @click="onCancel"
                />
            </v-card-title>

            <v-card-text class="pt-4">
                <v-row dense>
                    <!-- Telefonnummer -->
                    <v-col cols="12">
                        <v-select
                            v-if="phoneOptions.length > 1"
                            v-model="selectedPhone"
                            :items="phoneOptions"
                            :label="t('FakturaView.dialogs.sendWhatsApp.phone')"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-phone"
                            :rules="[v => !!v || t('FakturaView.dialogs.sendWhatsApp.phoneRequired')]"
                        />
                        <v-text-field
                            v-else
                            v-model="selectedPhone"
                            :label="t('FakturaView.dialogs.sendWhatsApp.phone')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            prepend-inner-icon="mdi-phone"
                            :rules="[v => !!v || t('FakturaView.dialogs.sendWhatsApp.phoneRequired')]"
                        />
                    </v-col>

                    <!-- Nachricht (Caption) -->
                    <v-col cols="12">
                        <v-textarea
                            v-model="waMessage"
                            :label="t('FakturaView.dialogs.sendWhatsApp.message')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            rows="4"
                            auto-grow
                        />
                    </v-col>

                    <!-- Anhang-Info -->
                    <v-col cols="12">
                        <v-chip color="green" variant="tonal" prepend-icon="mdi-paperclip">
                            {{ attachmentName }}
                        </v-chip>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="onCancel"
                >
                    {{ t('FakturaView.dialogs.sendWhatsApp.cancel') }}
                </v-btn>
                <v-btn
                    color="green-darken-1"
                    variant="elevated"
                    prepend-icon="mdi-whatsapp"
                    :disabled="!isValid"
                    :loading="sending"
                    @click="onSend"
                >
                    {{ t('FakturaView.dialogs.sendWhatsApp.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
// src/core/views/faktura/dialogs/send.whatsapp.dialog.vue

import { defineComponent, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

export default defineComponent({
    name: 'SendWhatsAppDialog',
    props: {
        /**
         * Dialog sichtbar
         */
        modelValue: {
            type: Boolean,
            default: false
        },
        /**
         * Vorausgefuellte Telefonnummer
         */
        initialPhone: {
            type: String,
            default: ''
        },
        /**
         * Alle verfuegbaren Telefonnummern des Kunden
         */
        phoneList: {
            type: Array,
            default: () => []
        },
        /**
         * Vorausgefuellter Nachrichtentext
         */
        initialMessage: {
            type: String,
            default: ''
        },
        /**
         * Name der angehaengten Datei (zur Anzeige)
         */
        attachmentName: {
            type: String,
            default: ''
        }
    },
    emits: ['update:modelValue', 'send', 'cancel'],
    setup(props, { emit }) {
        const { t } = useI18n()

        const selectedPhone = ref('')
        const waMessage = ref('')
        const sending = ref(false)

        const phoneOptions = computed(() => {
            const phones = []
            if (props.initialPhone) phones.push(props.initialPhone)
            for (const p of props.phoneList) {
                const num = typeof p === 'string' ? p : (p.number || '')
                if (num && !phones.includes(num)) phones.push(num)
            }
            return phones
        })

        const isValid = computed(() => {
            return selectedPhone.value && selectedPhone.value.trim() !== ''
        })

        // Dialog-Felder initialisieren wenn geoeffnet
        watch(() => props.modelValue, (newValue) => {
            if (newValue) {
                selectedPhone.value = props.initialPhone || (phoneOptions.value.length > 0 ? phoneOptions.value[0] : '')
                waMessage.value = props.initialMessage || ''
                sending.value = false
            }
        })

        function onSend() {
            if (!isValid.value || sending.value) return

            sending.value = true
            emit('send', {
                phone: selectedPhone.value.trim(),
                message: waMessage.value.trim()
            })
        }

        function onCancel() {
            emit('cancel')
            emit('update:modelValue', false)
        }

        function resetSending() {
            sending.value = false
        }

        return {
            t,
            selectedPhone,
            waMessage,
            sending,
            phoneOptions,
            isValid,
            onSend,
            onCancel,
            resetSending
        }
    }
})
</script>
