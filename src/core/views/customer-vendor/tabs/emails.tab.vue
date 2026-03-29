<!-- src/core/views/customer-vendor/tabs/emails.tab.vue -->
<template>
    <v-row class="pa-2 pa-sm-3">
        <v-col cols="12">
            <!-- Kein Email-Config -->
            <v-alert v-if="configError" type="warning" variant="tonal" density="compact" class="mb-3">
                {{ t('CustomerVendorEditView.emails.noConfig') }}
            </v-alert>

            <!-- Keine Email-Adressen beim Kunden -->
            <v-alert v-else-if="!emailAddresses.length" type="info" variant="tonal" density="compact" class="mb-3">
                {{ t('CustomerVendorEditView.emails.noAddresses') }}
            </v-alert>

            <template v-else>
                <!-- Toolbar -->
                <div class="d-flex align-center mb-3 ga-2">
                    <v-btn color="primary" size="small" variant="tonal" @click="showCompose = true">
                        <v-icon start size="small">mdi-pencil</v-icon>
                        {{ t('CustomerVendorEditView.emails.compose') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="text" size="small" @click="loadEmails" :loading="loading">
                        <v-icon start size="small">mdi-refresh</v-icon>
                        {{ t('CustomerVendorEditView.emails.refresh') }}
                    </v-btn>
                </div>

                <!-- Loading -->
                <div v-if="loading && !emails.length" class="text-center py-8">
                    <v-progress-circular indeterminate size="32" width="3" />
                    <div class="text-body-2 text-medium-emphasis mt-2">{{ t('CustomerVendorEditView.emails.loading') }}</div>
                </div>

                <!-- Fehler -->
                <v-alert v-else-if="error" type="error" variant="tonal" density="compact" class="mb-3">
                    {{ error }}
                </v-alert>

                <!-- Keine Emails -->
                <div v-else-if="!emails.length && !loading" class="text-center text-medium-emphasis py-8">
                    <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-email-off-outline</v-icon>
                    <div>{{ t('CustomerVendorEditView.emails.noEmails') }}</div>
                </div>

                <!-- Email-Liste + Vorschau -->
                <template v-else>
                    <v-row>
                        <!-- Emailliste -->
                        <v-col cols="12" :md="selectedEmail ? 5 : 12">
                            <v-card variant="outlined" elevation="0">
                                <v-list density="compact" class="pa-0">
                                    <v-list-item
                                        v-for="email in emails"
                                        :key="email.uid"
                                        :active="selectedEmail?.uid === email.uid"
                                        @click="selectEmail(email)"
                                        class="email-list-item"
                                        :class="{ 'font-weight-bold': !email.seen }"
                                    >
                                        <template #prepend>
                                            <v-icon
                                                :color="email.seen ? 'grey-lighten-1' : 'primary'"
                                                size="small"
                                                class="mr-2"
                                            >
                                                {{ email.seen ? 'mdi-email-open-outline' : 'mdi-email' }}
                                            </v-icon>
                                        </template>
                                        <v-list-item-title class="text-body-2">
                                            {{ email.subject || t('CustomerVendorEditView.emails.noSubject') }}
                                        </v-list-item-title>
                                        <v-list-item-subtitle class="text-caption">
                                            <span>{{ email.from_name || email.from }}</span>
                                            <span class="float-right">{{ formatDate(email.date) }}</span>
                                        </v-list-item-subtitle>
                                        <template #append>
                                            <v-icon v-if="email.has_attachments" size="x-small" color="grey">mdi-paperclip</v-icon>
                                        </template>
                                    </v-list-item>
                                </v-list>
                            </v-card>

                            <!-- Pagination -->
                            <div v-if="totalPages > 1" class="d-flex justify-center mt-2">
                                <v-pagination v-model="page" :length="totalPages" density="compact" size="small" />
                            </div>
                        </v-col>

                        <!-- Vorschau -->
                        <v-col v-if="selectedEmail" cols="12" md="7">
                            <v-card variant="outlined" elevation="0">
                                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                                    <span class="text-subtitle-2 text-truncate">{{ selectedEmail.subject }}</span>
                                    <v-spacer />
                                    <v-btn variant="text" size="x-small" icon @click="selectedEmail = null">
                                        <v-icon size="small">mdi-close</v-icon>
                                    </v-btn>
                                </v-card-title>
                                <v-divider />

                                <!-- Lade-Indikator für Body -->
                                <div v-if="loadingBody" class="text-center py-6">
                                    <v-progress-circular indeterminate size="24" width="2" />
                                </div>

                                <template v-else-if="emailBody">
                                    <!-- Header -->
                                    <div class="px-3 py-2 text-caption text-medium-emphasis">
                                        <div><strong>{{ t('CustomerVendorEditView.emails.from') }}:</strong> {{ emailBody.from_name || emailBody.from }} &lt;{{ emailBody.from }}&gt;</div>
                                        <div><strong>{{ t('CustomerVendorEditView.emails.to') }}:</strong> {{ emailBody.to }}</div>
                                        <div v-if="emailBody.cc"><strong>CC:</strong> {{ emailBody.cc }}</div>
                                        <div><strong>{{ t('CustomerVendorEditView.emails.date') }}:</strong> {{ formatDate(emailBody.date) }}</div>
                                    </div>
                                    <v-divider />

                                    <!-- Body -->
                                    <div class="pa-3 email-body">
                                        <div v-if="emailBody.body_html" v-html="sanitizeHtml(emailBody.body_html)" class="email-html-content" />
                                        <pre v-else class="text-body-2" style="white-space: pre-wrap;">{{ emailBody.body_text }}</pre>
                                    </div>

                                    <!-- Attachments -->
                                    <template v-if="emailBody.attachments?.length">
                                        <v-divider />
                                        <div class="px-3 py-2">
                                            <div class="text-caption text-medium-emphasis mb-1">
                                                {{ t('CustomerVendorEditView.emails.attachments') }} ({{ emailBody.attachments.length }})
                                            </div>
                                            <v-chip
                                                v-for="(att, i) in emailBody.attachments"
                                                :key="i"
                                                size="small"
                                                variant="outlined"
                                                class="mr-1 mb-1"
                                                @click="downloadAttachment(att)"
                                            >
                                                <v-icon start size="small">mdi-file-outline</v-icon>
                                                {{ att.filename }}
                                                <span class="text-caption text-medium-emphasis ml-1">({{ formatSize(att.size) }})</span>
                                            </v-chip>
                                        </div>
                                    </template>
                                </template>
                            </v-card>
                        </v-col>
                    </v-row>
                </template>
            </template>

            <!-- Compose Dialog -->
            <v-dialog v-model="showCompose" max-width="700" persistent>
                <v-card>
                    <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                        <v-icon class="mr-2" size="small">mdi-email-edit</v-icon>
                        <span class="text-subtitle-1">{{ t('CustomerVendorEditView.emails.compose') }}</span>
                        <v-spacer />
                        <v-btn variant="text" size="x-small" icon @click="showCompose = false">
                            <v-icon>mdi-close</v-icon>
                        </v-btn>
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="py-3">
                        <v-text-field
                            v-model="composeData.to"
                            :label="t('CustomerVendorEditView.emails.to')"
                            variant="outlined"
                            density="compact"
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="composeData.subject"
                            :label="t('CustomerVendorEditView.emails.subject')"
                            variant="outlined"
                            density="compact"
                            class="mb-2"
                        />
                        <v-textarea
                            v-model="composeData.body"
                            :label="t('CustomerVendorEditView.emails.body')"
                            variant="outlined"
                            density="compact"
                            rows="10"
                        />
                        <v-alert v-if="sendError" type="error" variant="tonal" density="compact" class="mt-2">
                            {{ sendError }}
                        </v-alert>
                    </v-card-text>
                    <v-divider />
                    <v-card-actions class="px-3 py-2">
                        <v-spacer />
                        <v-btn variant="text" @click="showCompose = false">
                            {{ t('CustomerVendorEditView.emails.cancel') }}
                        </v-btn>
                        <v-btn color="primary" :loading="sending" @click="doSendEmail">
                            <v-icon start size="small">mdi-send</v-icon>
                            {{ t('CustomerVendorEditView.emails.send') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-col>
    </v-row>
</template>

<script>
import { ref, watch, onMounted } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

export default {
    name: 'EmailsTab',
    props: {
        cvId: { type: [Number, String], required: true },
        src: { type: String, required: true },
        emailAddresses: { type: Array, default: () => [] },
    },
    setup(props) {
        const { t } = useI18n()

        const emails = ref([])
        const loading = ref(false)
        const error = ref('')
        const configError = ref(false)
        const page = ref(1)
        const totalPages = ref(0)

        const selectedEmail = ref(null)
        const emailBody = ref(null)
        const loadingBody = ref(false)

        const showCompose = ref(false)
        const composeData = ref({ to: '', subject: '', body: '' })
        const sending = ref(false)
        const sendError = ref('')

        async function loadEmails() {
            if (!props.emailAddresses.length) return

            loading.value = true
            error.value = ''
            configError.value = false

            try {
                const resp = await axios.post('/api/email/', {
                    action: 'getEmails',
                    email_addresses: props.emailAddresses,
                    page: page.value,
                    limit: 50,
                })
                if (resp.data.success) {
                    emails.value = resp.data.payload.emails
                    totalPages.value = resp.data.payload.pages
                } else {
                    if (resp.data.text === 'EMAIL_NOT_CONFIGURED') {
                        configError.value = true
                    } else {
                        error.value = resp.data.text || 'Unbekannter Fehler'
                    }
                }
            } catch (err) {
                error.value = err.message || String(err)
            } finally {
                loading.value = false
            }
        }

        async function selectEmail(email) {
            selectedEmail.value = email
            loadingBody.value = true
            emailBody.value = null

            try {
                const resp = await axios.post('/api/email/', {
                    action: 'getEmail',
                    uid: email.uid,
                    folder: email.folder || 'INBOX',
                })
                if (resp.data.success) {
                    emailBody.value = { ...email, ...resp.data.payload }
                    // Als gelesen markieren in der Liste
                    email.seen = true
                }
            } catch (err) {
                emailBody.value = { ...email, body_text: 'Fehler beim Laden: ' + err.message }
            } finally {
                loadingBody.value = false
            }
        }

        async function doSendEmail() {
            sending.value = true
            sendError.value = ''

            try {
                const resp = await axios.post('/api/email/', {
                    action: 'sendEmail',
                    to: [{ email: composeData.value.to, name: '' }],
                    subject: composeData.value.subject,
                    body_html: composeData.value.body.replace(/\n/g, '<br>'),
                    body_text: composeData.value.body,
                })
                if (resp.data.success) {
                    showCompose.value = false
                    composeData.value = { to: '', subject: '', body: '' }
                } else {
                    sendError.value = resp.data.text || 'Fehler beim Senden'
                }
            } catch (err) {
                sendError.value = err.message || String(err)
            } finally {
                sending.value = false
            }
        }

        function formatDate(dateStr) {
            if (!dateStr) return ''
            try {
                const d = new Date(dateStr)
                if (isNaN(d.getTime())) return dateStr
                const now = new Date()
                const isToday = d.toDateString() === now.toDateString()
                if (isToday) {
                    return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
                }
                return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
            } catch {
                return dateStr
            }
        }

        function formatSize(bytes) {
            if (!bytes) return ''
            if (bytes < 1024) return bytes + ' B'
            if (bytes < 1048576) return Math.round(bytes / 1024) + ' KB'
            return (bytes / 1048576).toFixed(1) + ' MB'
        }

        function sanitizeHtml(html) {
            // Script-Tags und Event-Handler entfernen
            return html
                .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
                .replace(/\son\w+\s*=\s*["'][^"']*["']/gi, '')
                .replace(/\son\w+\s*=\s*\S+/gi, '')
        }

        function downloadAttachment(att) {
            const blob = new Blob(
                [Uint8Array.from(atob(att.content_base64), c => c.charCodeAt(0))],
                { type: att.content_type || 'application/octet-stream' }
            )
            const url = URL.createObjectURL(blob)
            const a = document.createElement('a')
            a.href = url
            a.download = att.filename
            a.click()
            URL.revokeObjectURL(url)
        }

        // Compose-Dialog mit Kunden-Email vorbelegen
        watch(showCompose, (val) => {
            if (val && props.emailAddresses.length) {
                composeData.value.to = props.emailAddresses[0]
            }
        })

        // Bei Seitenwechsel Emails neu laden
        watch(page, () => loadEmails())

        onMounted(() => {
            if (props.emailAddresses.length) {
                loadEmails()
            }
        })

        return {
            t, emails, loading, error, configError, page, totalPages,
            selectedEmail, emailBody, loadingBody,
            showCompose, composeData, sending, sendError,
            loadEmails, selectEmail, doSendEmail,
            formatDate, formatSize, sanitizeHtml, downloadAttachment,
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
    background-color: #f5f5f5;
}

.email-list-item {
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.email-body {
    max-height: 500px;
    overflow-y: auto;
}

.email-html-content {
    font-size: 0.875rem;
    line-height: 1.5;
}

.email-html-content :deep(img) {
    max-width: 100%;
    height: auto;
}

.email-html-content :deep(table) {
    max-width: 100%;
}
</style>
