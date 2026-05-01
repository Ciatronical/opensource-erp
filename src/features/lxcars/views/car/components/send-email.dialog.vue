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
                <!-- AN -->
                <v-combobox
                    ref="emailFieldRef"
                    v-model="to.state.model"
                    v-model:search="to.state.searchText"
                    :items="to.state.items"
                    :loading="to.state.loading"
                    :label="t('CarEditView.email.to')"
                    variant="outlined"
                    density="compact"
                    prepend-inner-icon="mdi-email"
                    item-title="email"
                    return-object
                    no-filter
                    hide-no-data
                    autocomplete="off"
                    type="email"
                    :rules="[v => !!(typeof v === 'string' ? v.trim() : v?.email?.trim()) || t('CarEditView.email.to')]"
                    @update:model-value="onToSelect"
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item v-bind="itemProps" :title="undefined">
                            <template #prepend>
                                <v-icon size="small" :color="item.raw.cvType === 'vendor' ? 'orange-darken-2' : 'primary'">
                                    {{ item.raw.cvType === 'vendor' ? 'mdi-truck-delivery' : 'mdi-account' }}
                                </v-icon>
                            </template>
                            <v-list-item-title>{{ item.raw.name }}</v-list-item-title>
                            <v-list-item-subtitle>{{ item.raw.email }}</v-list-item-subtitle>
                        </v-list-item>
                    </template>
                </v-combobox>

                <!-- CC/BCC-Toggle -->
                <div v-if="!showCcBcc" class="d-flex justify-end mt-n3 mb-3">
                    <v-btn variant="plain" size="x-small" density="compact" class="text-medium-emphasis" @click="showCcBcc = true">
                        {{ t('CarEditView.email.addCcBcc') }}
                    </v-btn>
                </div>

                <!-- CC -->
                <v-combobox
                    v-if="showCcBcc"
                    v-model="cc.state.model"
                    v-model:search="cc.state.searchText"
                    :items="cc.state.items"
                    :loading="cc.state.loading"
                    :label="t('CarEditView.email.cc')"
                    variant="outlined"
                    density="compact"
                    prepend-inner-icon="mdi-email-plus-outline"
                    item-title="email"
                    return-object
                    no-filter
                    hide-no-data
                    autocomplete="off"
                    type="email"
                    clearable
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item v-bind="itemProps" :title="undefined">
                            <template #prepend>
                                <v-icon size="small" :color="item.raw.cvType === 'vendor' ? 'orange-darken-2' : 'primary'">
                                    {{ item.raw.cvType === 'vendor' ? 'mdi-truck-delivery' : 'mdi-account' }}
                                </v-icon>
                            </template>
                            <v-list-item-title>{{ item.raw.name }}</v-list-item-title>
                            <v-list-item-subtitle>{{ item.raw.email }}</v-list-item-subtitle>
                        </v-list-item>
                    </template>
                </v-combobox>

                <!-- BCC -->
                <v-combobox
                    v-if="showCcBcc"
                    v-model="bcc.state.model"
                    v-model:search="bcc.state.searchText"
                    :items="bcc.state.items"
                    :loading="bcc.state.loading"
                    :label="t('CarEditView.email.bcc')"
                    variant="outlined"
                    density="compact"
                    prepend-inner-icon="mdi-email-lock-outline"
                    item-title="email"
                    return-object
                    no-filter
                    hide-no-data
                    autocomplete="off"
                    type="email"
                    clearable
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item v-bind="itemProps" :title="undefined">
                            <template #prepend>
                                <v-icon size="small" :color="item.raw.cvType === 'vendor' ? 'orange-darken-2' : 'primary'">
                                    {{ item.raw.cvType === 'vendor' ? 'mdi-truck-delivery' : 'mdi-account' }}
                                </v-icon>
                            </template>
                            <v-list-item-title>{{ item.raw.name }}</v-list-item-title>
                            <v-list-item-subtitle>{{ item.raw.email }}</v-list-item-subtitle>
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

                <!-- Weitere Dateien -->
                <div class="mt-2">
                    <input ref="fileInputRef" type="file" multiple class="d-none" @change="onFileChange" />
                    <v-btn size="small" variant="tonal" prepend-icon="mdi-paperclip-plus" @click="triggerFileInput">
                        {{ t('CarEditView.email.addFiles') }}
                    </v-btn>
                    <v-chip
                        v-for="(file, i) in additionalFiles"
                        :key="i"
                        size="small"
                        color="secondary"
                        variant="tonal"
                        prepend-icon="mdi-paperclip"
                        closable
                        class="ml-2 mt-1"
                        @click:close="removeAdditionalFile(i)"
                    >
                        {{ file.name }}
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
import { defineComponent, ref, reactive, computed, watch, nextTick } from 'vue'
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
        const fileInputRef = ref(null)

        const subject = ref('')
        const body = ref('')
        const attachFull = ref(true)
        const additionalFiles = ref([])
        const sending = ref(false)
        const showCcBcc = ref(false)

        function makeEmailField() {
            let timer = null
            const state = reactive({ model: '', searchText: '', items: [], loading: false })

            watch(() => state.searchText, (val) => {
                clearTimeout(timer)
                const q = (val || '').trim()
                if (q.length < 3) { state.items = []; return }
                state.loading = true
                timer = setTimeout(async () => {
                    try {
                        const { data } = await axios.post('/api/faktura/', { action: 'searchCvEmails', search: q })
                        state.items = data?.results || []
                    } catch { state.items = [] }
                    finally { state.loading = false }
                }, 300)
            })

            function reset(value = '') {
                clearTimeout(timer)
                state.model = value
                state.searchText = ''
                state.items = []
                state.loading = false
            }

            return { state, reset }
        }

        const to = makeEmailField()
        const cc = makeEmailField()
        const bcc = makeEmailField()

        function extractEmail(v) {
            if (!v) return ''
            if (typeof v === 'string') return v.trim()
            if (typeof v === 'object' && v.email) return String(v.email).trim()
            return ''
        }

        function onToSelect(item) {
            if (!item || typeof item !== 'object') return
            const extras = Array.isArray(item.extra_emails) ? item.extra_emails.filter(Boolean) : []
            if (extras.length > 0) {
                cc.state.model = extras[0]
                showCcBcc.value = true
            }
        }

        const isValid = computed(() => {
            return !!extractEmail(to.state.model) && !!subject.value && subject.value.trim() !== ''
        })

        watch(() => props.modelValue, (visible) => {
            if (!visible) return
            to.reset(props.initialEmail || '')
            cc.reset()
            bcc.reset()
            subject.value = props.initialSubject || ''
            body.value = props.initialBody || ''
            attachFull.value = props.attachFullDefault
            additionalFiles.value = []
            sending.value = false
            showCcBcc.value = false
            nextTick(() => {
                const input = emailFieldRef.value?.$el?.querySelector('input')
                input?.focus()
            })
        })

        function triggerFileInput() {
            fileInputRef.value?.click()
        }

        function onFileChange(e) {
            additionalFiles.value = [...additionalFiles.value, ...Array.from(e.target.files)]
            e.target.value = ''
        }

        function removeAdditionalFile(i) {
            additionalFiles.value = additionalFiles.value.filter((_, idx) => idx !== i)
        }

        function fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader()
                reader.onload = () => resolve(reader.result.split(',')[1])
                reader.onerror = reject
                reader.readAsDataURL(file)
            })
        }

        async function onSend() {
            if (!isValid.value || sending.value) return
            const addr = extractEmail(to.state.model)
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

            const ccAddr = extractEmail(cc.state.model)
            if (ccAddr) payload.cc = [{ email: ccAddr, name: '' }]

            const bccAddr = extractEmail(bcc.state.model)
            if (bccAddr) payload.bcc = [{ email: bccAddr, name: '' }]

            const attachments = []
            if (attachFull.value && props.attachmentContent) {
                attachments.push({
                    filename: props.attachmentFilename,
                    content_base64: props.attachmentContent,
                    content_type: 'text/plain; charset=utf-8'
                })
            }
            for (const file of additionalFiles.value) {
                attachments.push({
                    filename: file.name,
                    content_base64: await fileToBase64(file),
                    content_type: file.type || 'application/octet-stream'
                })
            }
            if (attachments.length) {
                payload.attachments = attachments
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
            emailFieldRef, fileInputRef,
            subject, body, attachFull, additionalFiles, sending, showCcBcc,
            to, cc, bcc,
            isValid,
            onToSelect,
            triggerFileInput, onFileChange, removeAdditionalFile,
            onSend, onCancel
        }
    }
})
</script>
