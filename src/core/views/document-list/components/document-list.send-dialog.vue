<!-- src/core/views/document-list/components/document-list.send-dialog.vue -->
<!-- E-Mail-Versand aus der Belegliste: die Liste (PDF/CSV) an den Steuerberater,
     die Belege als Sammel-PDF an einen Empfänger oder ein einzelner Beleg an
     den Kunden. Was angehängt wird, bestimmt die aufrufende Ansicht über
     attachmentOptions — der Dialog zeigt nur die Auswahl und sammelt Adressen,
     Betreff und Text ein. Das Zusammenstellen und Senden macht die Ansicht. -->
<template>
    <v-dialog :model-value="modelValue" max-width="640" persistent scrollable @update:model-value="v => emit('update:modelValue', v)">
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon class="me-2">mdi-email-send-outline</v-icon>
                {{ title }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" size="small" :disabled="sending" @click="cancel" />
            </v-card-title>

            <v-card-text class="pt-2">
                <v-text-field
                    v-model="form.to"
                    :label="t('DocumentList.send.to')"
                    :hint="t('DocumentList.send.toHint')"
                    persistent-hint
                    density="compact"
                    variant="outlined"
                    prepend-inner-icon="mdi-account-outline"
                    class="mb-3"
                    autofocus
                />
                <v-row dense class="mb-1">
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="form.cc"
                            :label="t('DocumentList.send.cc')"
                            density="compact"
                            variant="outlined"
                            hide-details
                        />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="form.bcc"
                            :label="t('DocumentList.send.bcc')"
                            density="compact"
                            variant="outlined"
                            hide-details
                        />
                    </v-col>
                </v-row>
                <v-text-field
                    v-model="form.subject"
                    :label="t('DocumentList.send.subject')"
                    density="compact"
                    variant="outlined"
                    class="mb-3 mt-2"
                />
                <v-textarea
                    v-model="form.body"
                    :label="t('DocumentList.send.body')"
                    density="compact"
                    variant="outlined"
                    rows="5"
                    auto-grow
                    class="mb-2"
                />

                <div class="text-caption text-medium-emphasis mb-1">{{ t('DocumentList.send.attachments') }}</div>
                <v-checkbox
                    v-for="option in options"
                    :key="option.key"
                    v-model="option.checked"
                    density="compact"
                    hide-details
                >
                    <template #label>
                        <span>{{ option.label }}</span>
                        <span v-if="option.hint" class="text-medium-emphasis ms-1">({{ option.hint }})</span>
                    </template>
                </v-checkbox>

                <!-- Viele Belege: jeder wird einzeln gesetzt — das dauert -->
                <v-alert v-if="slowHint" type="info" variant="tonal" density="compact" class="mt-3">
                    {{ slowHint }}
                </v-alert>
            </v-card-text>

            <v-card-actions class="px-4 pb-4">
                <v-btn variant="text" :disabled="sending" @click="cancel">{{ t('DocumentList.send.cancel') }}</v-btn>
                <v-spacer />
                <v-btn color="primary" variant="flat" :loading="sending" :disabled="!canSend" @click="send">
                    <v-icon start>mdi-send</v-icon>
                    {{ t('DocumentList.send.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { reactive, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    modelValue:     { type: Boolean, default: false },
    title:          { type: String,  default: '' },
    initialTo:      { type: String,  default: '' },
    initialCc:      { type: String,  default: '' },
    initialBcc:     { type: String,  default: '' },
    initialSubject: { type: String,  default: '' },
    initialBody:    { type: String,  default: '' },
    /** [{ key, label, hint, checked }] — welche Anhänge zur Wahl stehen */
    attachmentOptions: { type: Array, default: () => [] },
    /** Hinweistext, wenn der Versand länger dauern kann */
    slowHint:       { type: String,  default: '' },
    sending:        { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'send', 'cancel'])

const { t } = useI18n()

const form    = reactive({ to: '', cc: '', bcc: '', subject: '', body: '' })
const options = ref([])

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
function addressesValid(value) {
    const list = String(value || '').split(/[,;]/).map(s => s.trim()).filter(Boolean)
    return list.every(a => EMAIL.test(a))
}

const canSend = computed(() =>
    !props.sending
    && form.to.trim() !== ''
    && addressesValid(form.to) && addressesValid(form.cc) && addressesValid(form.bcc)
    && options.value.some(o => o.checked)
)

// Beim Öffnen frisch vorbelegen — der Dialog wird für Liste, Auswahl und
// Einzelbeleg wiederverwendet
watch(() => props.modelValue, (open) => {
    if (!open) return
    form.to      = props.initialTo
    form.cc      = props.initialCc
    form.bcc     = props.initialBcc
    form.subject = props.initialSubject
    form.body    = props.initialBody
    options.value = props.attachmentOptions.map(o => ({ ...o, checked: o.checked !== false }))
})

function cancel() {
    emit('cancel')
    emit('update:modelValue', false)
}

function send() {
    const attachments = {}
    for (const o of options.value) attachments[o.key] = !!o.checked
    emit('send', { to: form.to, cc: form.cc, bcc: form.bcc, subject: form.subject, body: form.body, attachments })
}
</script>
