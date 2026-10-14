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

                    <!-- CC / BCC (optional, vorbelegt aus dem Kundenstamm) -->
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="emailCc"
                            :label="t('FakturaView.dialogs.sendEmail.cc')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            prepend-inner-icon="mdi-account-multiple"
                        />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="emailBcc"
                            :label="t('FakturaView.dialogs.sendEmail.bcc')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            prepend-inner-icon="mdi-account-multiple-outline"
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

                    <!-- Anhänge: Beleg-PDF (fest) + zusätzliche Dateien -->
                    <v-col cols="12">
                        <div class="d-flex flex-wrap align-center ga-2 mb-2">
                            <v-chip color="primary" variant="tonal" prepend-icon="mdi-paperclip">
                                {{ attachmentName }}
                            </v-chip>
                            <v-chip
                                v-for="(att, idx) in extraAttachments"
                                :key="att.filename"
                                variant="tonal"
                                prepend-icon="mdi-file-outline"
                                closable
                                @click:close="removeAttachment(idx)"
                            >
                                {{ att.filename }} ({{ formatSize(att.size) }})
                            </v-chip>
                        </div>
                        <v-file-input
                            :key="fileInputKey"
                            :label="t('FakturaView.dialogs.sendEmail.addAttachment')"
                            multiple
                            variant="outlined"
                            density="compact"
                            prepend-icon=""
                            prepend-inner-icon="mdi-paperclip-plus"
                            :hint="t('FakturaView.dialogs.sendEmail.attachmentHint', { max: formatSize(maxTotalBytes) })"
                            persistent-hint
                            :loading="readingFiles"
                            @update:model-value="onFilesSelected"
                        />
                        <v-alert
                            v-if="sizeExceeded"
                            type="warning"
                            variant="tonal"
                            density="compact"
                            class="mt-3"
                        >
                            {{ t('FakturaView.dialogs.sendEmail.attachmentTooLarge', { size: formatSize(totalExtraBytes), max: formatSize(maxTotalBytes) }) }}
                        </v-alert>
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
import { oserpStore } from '@/core/stores/oserp.store'

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
         * Vorausgefuellte CC-Adressen (Kundenstamm)
         */
        initialCc: {
            type: String,
            default: ''
        },
        /**
         * Vorausgefuellte BCC-Adressen (Kundenstamm)
         */
        initialBcc: {
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
        const emailBcc = ref('')
        const emailSubject = ref('')
        const emailBody = ref('')
        const sending = ref(false)

        // Zusätzliche Anhänge [{filename, content_base64, content_type, size}]
        const extraAttachments = ref([])
        const readingFiles = ref(false)
        const fileInputKey = ref(0)
        // Obergrenze für alle zusätzlichen Dateien zusammen: Firmenkonfiguration
        // → E-Mail-Client → Maximale E-Mail-Größe (vom Mailserver ermittelbar),
        // gekappt durch post_max_size des PHP-Servers, weil Anhänge als Base64
        // im JSON-Request liegen (Zuschlag 37 %). Ohne Konfiguration 10 MB.
        const oserp = oserpStore()
        const BASE64_OVERHEAD = 1.37
        const maxTotalBytes = computed(() => {
            const configuredMb = Number(oserp.getClientDefaultValue('email_max_message_size_mb', 0)) || 0
            const phpPostMax = Number(oserp.session?.company_config?.php_limits?.post_max_size || 0)
            const limits = [
                configuredMb > 0 ? configuredMb * 1024 * 1024 : 0,
                phpPostMax > 0 ? Math.floor(phpPostMax / BASE64_OVERHEAD) : 0
            ].filter(v => v > 0)
            return limits.length ? Math.min(...limits) : 10 * 1024 * 1024
        })
        const totalExtraBytes = computed(() => extraAttachments.value.reduce((sum, a) => sum + (a.size || 0), 0))
        const sizeExceeded = computed(() => totalExtraBytes.value > maxTotalBytes.value)

        const isValid = computed(() => {
            return emailTo.value && emailTo.value.trim() !== '' &&
                   emailSubject.value && emailSubject.value.trim() !== '' &&
                   !sizeExceeded.value && !readingFiles.value
        })

        function formatSize(bytes) {
            if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
            if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`
            return `${bytes} B`
        }

        function fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader()
                // Nur den Base64-Teil, ohne "data:…;base64,"-Präfix
                reader.onload = () => resolve(String(reader.result).split(',')[1] || '')
                reader.onerror = reject
                reader.readAsDataURL(file)
            })
        }

        /**
         * Gewählte Dateien einlesen und an die Anhangliste hängen.
         * Gleichnamige Dateien werden ersetzt, das Eingabefeld danach geleert.
         */
        async function onFilesSelected(files) {
            const list = Array.isArray(files) ? files : (files ? [files] : [])
            if (!list.length) return
            readingFiles.value = true
            try {
                for (const file of list) {
                    const content_base64 = await fileToBase64(file)
                    const entry = {
                        filename: file.name,
                        content_base64,
                        content_type: file.type || 'application/octet-stream',
                        size: file.size
                    }
                    const existing = extraAttachments.value.findIndex(a => a.filename === file.name)
                    if (existing >= 0) extraAttachments.value.splice(existing, 1, entry)
                    else extraAttachments.value.push(entry)
                }
            } finally {
                readingFiles.value = false
                fileInputKey.value++
            }
        }

        function removeAttachment(idx) {
            extraAttachments.value.splice(idx, 1)
        }

        // Dialog-Felder initialisieren wenn geoeffnet
        watch(() => props.modelValue, (newValue) => {
            if (newValue) {
                emailTo.value = props.initialTo || ''
                emailCc.value = props.initialCc || ''
                emailBcc.value = props.initialBcc || ''
                emailSubject.value = props.initialSubject || ''
                emailBody.value = props.initialBody || ''
                extraAttachments.value = []
                fileInputKey.value++
                sending.value = false
            }
        })

        function onSend() {
            if (!isValid.value || sending.value) return

            sending.value = true
            emit('send', {
                to: emailTo.value.trim(),
                cc: emailCc.value.trim(),
                bcc: emailBcc.value.trim(),
                subject: emailSubject.value.trim(),
                body: emailBody.value.trim(),
                // Zusätzliche Anhänge ohne das Anzeige-Feld size
                attachments: extraAttachments.value.map(({ filename, content_base64, content_type }) => ({ filename, content_base64, content_type }))
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
            emailBcc,
            emailSubject,
            emailBody,
            sending,
            isValid,
            extraAttachments,
            readingFiles,
            fileInputKey,
            maxTotalBytes,
            totalExtraBytes,
            sizeExceeded,
            formatSize,
            onFilesSelected,
            removeAttachment,
            onSend,
            onCancel,
            resetSending
        }
    }
})
</script>
