<!-- src/features/lxcars/views/car/components/send-email.dialog.vue -->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="780"
        persistent
        scrollable
    >
        <v-card>
            <v-card-title class="d-flex align-center bg-info py-3 px-4">
                <v-icon class="mr-2">mdi-email-send</v-icon>
                {{ t('CarEditView.email.dialogTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="onCancel" />
            </v-card-title>

            <v-divider />

            <v-card-text class="pt-4">
                <!-- Empfaenger: Kombi aus Freitext + CV-Suche -->
                <v-combobox
                    ref="emailFieldRef"
                    v-model="email"
                    :items="cvItems"
                    :loading="cvLoading"
                    :label="t('CarEditView.email.to')"
                    variant="outlined"
                    density="compact"
                    prepend-inner-icon="mdi-email"
                    item-title="email"
                    item-value="email"
                    no-filter
                    hide-no-data
                    autocomplete="off"
                    type="email"
                    :rules="[v => !!(typeof v === 'string' ? v.trim() : v?.email?.trim()) || t('CarEditView.email.to')]"
                    @update:search="onSearch"
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item v-bind="itemProps" :title="undefined">
                            <template #prepend>
                                <v-icon size="small" :color="item.raw.cvType === 'vendor' ? 'orange-darken-2' : 'primary'">
                                    {{ item.raw.cvType === 'vendor' ? 'mdi-truck-delivery' : 'mdi-account' }}
                                </v-icon>
                            </template>
                            <v-list-item-title>
                                {{ item.raw.name }}
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                {{ item.raw.email }}
                            </v-list-item-subtitle>
                        </v-list-item>
                    </template>
                </v-combobox>

                <!-- Betreff -->
                <v-text-field
                    v-model="subject"
                    :label="t('CarEditView.email.subject')"
                    variant="outlined"
                    density="compact"
                    prepend-inner-icon="mdi-format-title"
                    autocomplete="off"
                    :rules="[v => !!v?.trim() || t('CarEditView.email.subject')]"
                />

                <!-- Nachricht (HTML-Editor) -->
                <div class="text-caption mb-1">{{ t('CarEditView.email.body') }}</div>
                <html-editor-component v-model="body" />

                <!-- Anhang -->
                <div class="mt-3 d-flex align-center">
                    <v-checkbox
                        v-model="attachFull"
                        :label="t('CarEditView.email.attachFull')"
                        density="compact"
                        hide-details
                        color="primary"
                    />
                    <v-chip v-if="attachFull" size="small" color="primary" variant="tonal" prepend-icon="mdi-paperclip" class="ml-3">
                        {{ attachmentFilename }}
                    </v-chip>
                </div>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" :disabled="sending" @click="onCancel">
                    {{ t('CarEditView.email.cancel') }}
                </v-btn>
                <v-btn
                    color="info"
                    variant="elevated"
                    prepend-icon="mdi-email-send"
                    :disabled="!isValid || sending"
                    :loading="sending"
                    @click="onSend"
                >
                    {{ sending ? t('CarEditView.email.sending') : t('CarEditView.email.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { defineComponent, ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import Swal from 'sweetalert2'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'

export default defineComponent({
    name: 'SendEmailDialog',
    components: { HtmlEditorComponent },
    props: {
        modelValue: { type: Boolean, default: false },
        initialEmail: { type: String, default: '' },
        initialSubject: { type: String, default: '' },
        initialBody: { type: String, default: '' },
        attachmentFilename: { type: String, default: '' },
        attachmentContent: { type: String, default: '' },
        attachFullDefault: { type: Boolean, default: true },
        fromName: { type: String, default: '' },
        recordType: { type: String, default: null }
    },
    emits: ['update:modelValue', 'sent'],
    setup(props, { emit }) {
        const { t } = useI18n()

        const emailFieldRef = ref(null)
        const cvItems = ref([])
        const cvLoading = ref(false)
        let cvDebounceTimer = null

        const email = ref('')
        const subject = ref('')
        const body = ref('')
        const attachFull = ref(true)
        const sending = ref(false)

        function extractEmail(v) {
            if (!v) return ''
            if (typeof v === 'string') return v.trim()
            if (typeof v === 'object' && v.email) return String(v.email).trim()
            return ''
        }

        const isValid = computed(() => {
            return !!extractEmail(email.value) && !!subject.value && subject.value.trim() !== ''
        })

        // Beim Oeffnen: Felder initialisieren und Fokus setzen
        watch(() => props.modelValue, (visible) => {
            if (!visible) return
            cvItems.value = []
            email.value = props.initialEmail || ''
            subject.value = props.initialSubject || ''
            body.value = props.initialBody || ''
            attachFull.value = props.attachFullDefault
            sending.value = false
            nextTick(() => {
                const input = emailFieldRef.value?.$el?.querySelector('input')
                input?.focus()
            })
        })

        function onSearch(val) {
            clearTimeout(cvDebounceTimer)
            const search = (val || '').trim()
            if (search.length < 3) {
                cvItems.value = []
                return
            }
            // Parallel Kunden + Lieferanten durchsuchen, nur mit Email
            cvLoading.value = true
            cvDebounceTimer = setTimeout(async () => {
                try {
                    const [cust, vend] = await Promise.all([
                        axios.post('/api/faktura/', { action: 'searchFakturaCustomers', search, type: 'customer' }),
                        axios.post('/api/faktura/', { action: 'searchFakturaCustomers', search, type: 'vendor' })
                    ])
                    const customers = (cust.data?.results || []).map(r => ({ ...r, cvType: 'customer' }))
                    const vendors = (vend.data?.results || []).map(r => ({ ...r, cvType: 'vendor' }))
                    cvItems.value = [...customers, ...vendors]
                        .filter(r => r.email)
                        .sort((a, b) => a.name.localeCompare(b.name))
                } catch {
                    cvItems.value = []
                } finally {
                    cvLoading.value = false
                }
            }, 300)
        }

        async function onSend() {
            if (!isValid.value || sending.value) return
            const addr = extractEmail(email.value)
            if (!addr) return
            sending.value = true

            const payload = {
                action: 'sendEmail',
                from_name: props.fromName,
                to: [{ email: addr, name: '' }],
                subject: subject.value.trim(),
                body_html: body.value,
                record_type: props.recordType
            }

            if (attachFull.value && props.attachmentContent) {
                payload.attachments = [{
                    filename: props.attachmentFilename,
                    content_base64: props.attachmentContent,
                    content_type: 'text/plain; charset=utf-8'
                }]
            }

            try {
                const { data } = await axios.post('/api/email/', payload)
                if (data?.success) {
                    Swal.fire({
                        toast: true, icon: 'success', position: 'top-end',
                        showConfirmButton: false, timer: 3000,
                        title: t('CarEditView.email.success')
                    })
                    emit('sent')
                    emit('update:modelValue', false)
                } else {
                    const msg = data?.text || t('CarEditView.email.error')
                    Swal.fire({ icon: 'error', title: t('CarEditView.email.error'), text: msg })
                }
            } catch (e) {
                console.error('Email send error:', e)
                Swal.fire({ icon: 'error', title: t('CarEditView.email.error'), text: e.message || '' })
            } finally {
                sending.value = false
            }
        }

        function onCancel() {
            if (sending.value) return
            emit('update:modelValue', false)
        }

        return {
            t,
            emailFieldRef,
            cvItems, cvLoading,
            email, subject, body, attachFull, sending,
            isValid,
            onSearch,
            onSend, onCancel
        }
    }
})
</script>
