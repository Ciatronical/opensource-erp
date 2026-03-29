<!-- src/core/views/faktura/dialogs/send.email.dialog.vue -->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="700"
        persistent
    >
        <v-card>
            <v-card-title class="d-flex align-center bg-info">
                <v-icon class="mr-2">mdi-email-send</v-icon>
                {{ t('FakturaView.dialogs.sendEmail.title') }}
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    density="compact"
                    size="small"
                    @click="onCancel"
                />
            </v-card-title>

            <v-card-text class="pt-4">
                <v-row dense>
                    <!-- Empfaenger -->
                    <v-col cols="12">
                        <v-text-field
                            v-model="emailTo"
                            :label="t('FakturaView.dialogs.sendEmail.to')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            prepend-inner-icon="mdi-account"
                            :rules="[v => !!v || t('FakturaView.dialogs.sendEmail.toRequired')]"
                        />
                    </v-col>

                    <!-- CC (optional) -->
                    <v-col cols="12">
                        <v-text-field
                            v-model="emailCc"
                            :label="t('FakturaView.dialogs.sendEmail.cc')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            prepend-inner-icon="mdi-account-multiple"
                        />
                    </v-col>

                    <!-- Betreff -->
                    <v-col cols="12">
                        <v-text-field
                            v-model="emailSubject"
                            :label="t('FakturaView.dialogs.sendEmail.subject')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            prepend-inner-icon="mdi-format-title"
                            :rules="[v => !!v || t('FakturaView.dialogs.sendEmail.subjectRequired')]"
                        />
                    </v-col>

                    <!-- Nachricht -->
                    <v-col cols="12">
                        <v-textarea
                            v-model="emailBody"
                            :label="t('FakturaView.dialogs.sendEmail.body')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            rows="6"
                            auto-grow
                        />
                    </v-col>

                    <!-- Anhang-Info -->
                    <v-col cols="12">
                        <v-chip color="primary" variant="tonal" prepend-icon="mdi-paperclip">
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
                    {{ t('FakturaView.dialogs.sendEmail.cancel') }}
                </v-btn>
                <v-btn
                    color="info"
                    variant="elevated"
                    prepend-icon="mdi-email-send"
                    :disabled="!isValid"
                    :loading="sending"
                    @click="onSend"
                >
                    {{ t('FakturaView.dialogs.sendEmail.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
// src/core/views/faktura/dialogs/send.email.dialog.vue

import { defineComponent, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

export default defineComponent({
    name: 'SendEmailDialog',
    props: {
        /**
         * Dialog sichtbar
         */
        modelValue: {
            type: Boolean,
            default: false
        },
        /**
         * Vorausgefuellte Empfaenger-Adresse
         */
        initialTo: {
            type: String,
            default: ''
        },
        /**
         * Vorausgefuellter Betreff
         */
        initialSubject: {
            type: String,
            default: ''
        },
        /**
         * Vorausgefuellter Nachrichtentext
         */
        initialBody: {
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

        const emailTo = ref('')
        const emailCc = ref('')
        const emailSubject = ref('')
        const emailBody = ref('')
        const sending = ref(false)

        const isValid = computed(() => {
            return emailTo.value && emailTo.value.trim() !== '' &&
                   emailSubject.value && emailSubject.value.trim() !== ''
        })

        // Dialog-Felder initialisieren wenn geoeffnet
        watch(() => props.modelValue, (newValue) => {
            if (newValue) {
                emailTo.value = props.initialTo || ''
                emailCc.value = ''
                emailSubject.value = props.initialSubject || ''
                emailBody.value = props.initialBody || ''
                sending.value = false
            }
        })

        function onSend() {
            if (!isValid.value || sending.value) return

            sending.value = true
            emit('send', {
                to: emailTo.value.trim(),
                cc: emailCc.value.trim(),
                subject: emailSubject.value.trim(),
                body: emailBody.value.trim()
            })
        }

        function onCancel() {
            emit('cancel')
            emit('update:modelValue', false)
        }

        /**
         * Wird von der Parent-Komponente aufgerufen um den Sending-Status zurueckzusetzen
         */
        function resetSending() {
            sending.value = false
        }

        return {
            t,
            emailTo,
            emailCc,
            emailSubject,
            emailBody,
            sending,
            isValid,
            onSend,
            onCancel,
            resetSending
        }
    }
})
</script>
