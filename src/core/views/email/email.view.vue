<!-- src/core/views/email/email.view.vue -->
<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>
        <!-- Titel -->
        <div class="d-flex align-center mb-3">
            <v-icon color="primary" class="mr-1">mdi-email-multiple</v-icon>
            <h1 class="text-h6 mb-0">{{ t('EmailView.title') }}</h1>
        </div>

        <!-- Nicht konfiguriert -->
        <v-alert v-if="configError" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('EmailView.noConfig') }}
        </v-alert>

        <template v-else>
            <v-row>
                <!-- Linke Spalte: Ordner -->
                <v-col cols="12" md="2">
                    <v-card variant="outlined" elevation="0">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                            <span class="text-subtitle-2">{{ t('EmailView.folders') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-list density="compact" class="pa-0">
                            <v-list-item
                                v-for="folder in folders"
                                :key="folder.name"
                                :active="currentFolder === folder.name"
                                @click="selectFolder(folder.name)"
                                :disabled="folder.noSelect"
                            >
                                <template #prepend>
                                    <v-icon size="small">{{ getFolderIcon(folder.name) }}</v-icon>
                                </template>
                                <v-list-item-title class="text-body-2">{{ folder.name }}</v-list-item-title>
                            </v-list-item>
                        </v-list>
                    </v-card>

                    <v-btn color="primary" block class="mt-3" @click="showCompose = true">
                        <v-icon start size="small">mdi-pencil</v-icon>
                        {{ t('EmailView.compose') }}
                    </v-btn>
                </v-col>

                <!-- Mitte: Emailliste -->
                <v-col cols="12" :md="selectedEmail ? 4 : 10">
                    <v-card variant="outlined" elevation="0">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center ga-2">
                            <v-text-field
                                v-model="searchText"
                                :placeholder="t('EmailView.search')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                prepend-inner-icon="mdi-magnify"
                                clearable
                                class="flex-grow-1"
                                @keyup.enter="loadEmails"
                                @click:clear="searchText = ''; loadEmails()"
                            />
                            <v-btn variant="text" size="small" @click="loadEmails" :loading="loading">
                                <v-icon size="small">mdi-refresh</v-icon>
                            </v-btn>
                        </v-card-title>
                        <v-divider />

                        <!-- Loading -->
                        <div v-if="loading && !emails.length" class="text-center py-8">
                            <v-progress-circular indeterminate size="32" width="3" />
                            <div class="text-body-2 text-medium-emphasis mt-2">{{ t('EmailView.loading') }}</div>
                        </div>

                        <!-- Fehler -->
                        <v-alert v-else-if="error" type="error" variant="tonal" density="compact" class="ma-3">
                            {{ error }}
                        </v-alert>

                        <!-- Leere Liste -->
                        <div v-else-if="!emails.length && !loading" class="text-center text-medium-emphasis py-8">
                            <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-email-off-outline</v-icon>
                            <div>{{ t('EmailView.noEmails') }}</div>
                        </div>

                        <!-- Emailliste -->
                        <v-list v-else density="compact" class="pa-0">
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
                                    {{ email.subject || t('EmailView.noSubject') }}
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

                        <!-- Pagination -->
                        <template v-if="totalPages > 1">
                            <v-divider />
                            <div class="d-flex justify-center align-center py-2">
                                <v-pagination v-model="page" :length="totalPages" density="compact" size="small" />
                            </div>
                        </template>
                    </v-card>
                </v-col>

                <!-- Rechts: Vorschau -->
                <v-col v-if="selectedEmail" cols="12" md="6">
                    <v-card variant="outlined" elevation="0">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <span class="text-subtitle-2 text-truncate flex-grow-1">{{ selectedEmail.subject || t('EmailView.noSubject') }}</span>
                            <v-btn variant="text" size="x-small" icon @click="selectedEmail = null">
                                <v-icon size="small">mdi-close</v-icon>
                            </v-btn>
                        </v-card-title>
                        <v-divider />

                        <div v-if="loadingBody" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" width="2" />
                        </div>

                        <template v-else-if="emailBody">
                            <!-- Header -->
                            <div class="px-3 py-2 text-caption text-medium-emphasis">
                                <div><strong>{{ t('EmailView.from') }}:</strong> {{ emailBody.from_name || emailBody.from }} &lt;{{ emailBody.from }}&gt;</div>
                                <div><strong>{{ t('EmailView.to') }}:</strong> {{ emailBody.to }}</div>
                                <div v-if="emailBody.cc"><strong>{{ t('EmailView.cc') }}:</strong> {{ emailBody.cc }}</div>
                                <div><strong>{{ t('EmailView.date') }}:</strong> {{ formatDate(emailBody.date) }}</div>
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
                                        {{ t('EmailView.attachments') }} ({{ emailBody.attachments.length }})
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

        <!-- Compose Dialog -->
        <v-dialog v-model="showCompose" max-width="700" persistent>
            <v-card>
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small">mdi-email-edit</v-icon>
                    <span class="text-subtitle-1">{{ t('EmailView.compose') }}</span>
                    <v-spacer />
                    <v-btn variant="text" size="x-small" icon @click="showCompose = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-3">
                    <!-- Kundensuche -->
                    <v-autocomplete
                        v-model="composeCustomer"
                        v-model:search="customerSearch"
                        :items="customerResults"
                        :loading="customerLoading"
                        :placeholder="t('EmailView.searchCustomer')"
                        :label="t('EmailView.customer')"
                        variant="outlined"
                        density="compact"
                        class="mb-2"
                        item-value="id"
                        item-title="name"
                        return-object
                        no-filter
                        clearable
                        hide-no-data
                        prepend-inner-icon="mdi-account-search"
                        @update:search="onCustomerSearch"
                        @update:model-value="onCustomerSelect"
                    >
                        <template #item="{ item, props: itemProps }">
                            <v-list-item v-bind="itemProps">
                                <template #subtitle>
                                    <span>{{ item.raw.number }}</span>
                                    <span class="ml-2 text-primary">{{ item.raw.emails.join(', ') }}</span>
                                </template>
                                <template #append>
                                    <v-icon size="x-small" color="grey">
                                        {{ item.raw.type === 'customer' ? 'mdi-account' : 'mdi-truck' }}
                                    </v-icon>
                                </template>
                            </v-list-item>
                        </template>
                    </v-autocomplete>

                    <!-- Link zum Kunden öffnen -->
                    <div v-if="composeCustomer" class="mb-2">
                        <v-btn variant="text" size="small" color="primary" @click="openCustomer">
                            <v-icon start size="small">mdi-open-in-new</v-icon>
                            {{ composeCustomer.name }} {{ t('EmailView.openCustomer') }}
                        </v-btn>
                    </div>

                    <v-text-field
                        v-model="composeData.to"
                        :label="t('EmailView.to')"
                        variant="outlined"
                        density="compact"
                        class="mb-2"
                    />
                    <v-text-field
                        v-model="composeData.cc"
                        :label="t('EmailView.cc')"
                        variant="outlined"
                        density="compact"
                        class="mb-2"
                    />
                    <v-text-field
                        v-model="composeData.subject"
                        :label="t('EmailView.subject')"
                        variant="outlined"
                        density="compact"
                        class="mb-2"
                    />
                    <v-textarea
                        v-model="composeData.body"
                        :label="t('EmailView.body')"
                        variant="outlined"
                        density="compact"
                        rows="12"
                    />
                    <v-alert v-if="sendError" type="error" variant="tonal" density="compact" class="mt-2">
                        {{ sendError }}
                    </v-alert>
                </v-card-text>
                <v-divider />
                <v-card-actions class="px-3 py-2">
                    <v-spacer />
                    <v-btn variant="text" @click="showCompose = false">
                        {{ t('EmailView.cancel') }}
                    </v-btn>
                    <v-btn color="primary" :loading="sending" @click="doSendEmail">
                        <v-icon start size="small">mdi-send</v-icon>
                        {{ t('EmailView.send') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script>
import { ref, watch, onMounted } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'

export default {
    name: 'EmailView',
    components: { NavbarView },

    setup() {
        const { t } = useI18n()
        const router = useRouter()

        const folders = ref([])
        const currentFolder = ref('INBOX')
        const emails = ref([])
        const loading = ref(false)
        const error = ref('')
        const configError = ref(false)
        const page = ref(1)
        const totalPages = ref(0)
        const searchText = ref('')

        const selectedEmail = ref(null)
        const emailBody = ref(null)
        const loadingBody = ref(false)

        const showCompose = ref(false)
        const composeData = ref({ to: '', cc: '', subject: '', body: '' })
        const sending = ref(false)
        const sendError = ref('')

        // Kundensuche im Compose-Dialog
        const composeCustomer = ref(null)
        const customerSearch = ref('')
        const customerResults = ref([])
        const customerLoading = ref(false)
        let customerSearchTimeout = null

        async function loadFolders() {
            try {
                const resp = await axios.post('/api/email/', { action: 'getEmailFolders' })
                if (resp.data.success) {
                    folders.value = resp.data.payload.folders
                } else if (resp.data.text === 'EMAIL_NOT_CONFIGURED') {
                    configError.value = true
                }
            } catch (err) {
                // Ordner konnten nicht geladen werden — wird beim Emails-Laden nochmal versucht
            }
        }

        async function loadEmails() {
            loading.value = true
            error.value = ''
            configError.value = false

            try {
                const resp = await axios.post('/api/email/', {
                    action: 'getAllEmails',
                    folder: currentFolder.value,
                    page: page.value,
                    limit: 50,
                    search: searchText.value,
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

        function selectFolder(folderName) {
            currentFolder.value = folderName
            page.value = 1
            selectedEmail.value = null
            emailBody.value = null
            loadEmails()
        }

        async function selectEmail(email) {
            selectedEmail.value = email
            loadingBody.value = true
            emailBody.value = null

            try {
                const resp = await axios.post('/api/email/', {
                    action: 'getEmail',
                    uid: email.uid,
                    folder: currentFolder.value,
                })
                if (resp.data.success) {
                    emailBody.value = { ...email, ...resp.data.payload }
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
                const toList = composeData.value.to.split(/[;,]/).map(e => ({ email: e.trim(), name: '' })).filter(e => e.email)
                const ccList = composeData.value.cc ? composeData.value.cc.split(/[;,]/).map(e => ({ email: e.trim(), name: '' })).filter(e => e.email) : []

                const resp = await axios.post('/api/email/', {
                    action: 'sendEmail',
                    to: toList,
                    cc: ccList,
                    subject: composeData.value.subject,
                    body_html: composeData.value.body.replace(/\n/g, '<br>'),
                    body_text: composeData.value.body,
                })
                if (resp.data.success) {
                    showCompose.value = false
                    composeData.value = { to: '', cc: '', subject: '', body: '' }
                    composeCustomer.value = null
                } else {
                    sendError.value = resp.data.text || 'Fehler beim Senden'
                }
            } catch (err) {
                sendError.value = err.message || String(err)
            } finally {
                sending.value = false
            }
        }

        function onCustomerSearch(val) {
            if (customerSearchTimeout) clearTimeout(customerSearchTimeout)
            if (!val || val.length < 2) {
                customerResults.value = []
                return
            }
            customerLoading.value = true
            customerSearchTimeout = setTimeout(async () => {
                try {
                    const resp = await axios.post('/api/email/', {
                        action: 'searchCvEmails',
                        query: val,
                    })
                    if (resp.data.success) {
                        customerResults.value = resp.data.payload || []
                    }
                } catch (e) {
                    customerResults.value = []
                } finally {
                    customerLoading.value = false
                }
            }, 300)
        }

        function onCustomerSelect(item) {
            if (!item) return
            // Emails in das To-Feld eintragen
            composeData.value.to = item.emails.join('; ')
        }

        function openCustomer() {
            if (composeCustomer.value?.route) {
                showCompose.value = false
                router.push(composeCustomer.value.route)
            }
        }

        function getFolderIcon(name) {
            const n = name.toLowerCase()
            if (n === 'inbox' || n.includes('posteingang')) return 'mdi-inbox'
            if (n.includes('sent') || n.includes('gesendet')) return 'mdi-send'
            if (n.includes('draft') || n.includes('entwu') || n.includes('entwü')) return 'mdi-file-edit-outline'
            if (n.includes('trash') || n.includes('papierkorb') || n.includes('deleted')) return 'mdi-delete-outline'
            if (n.includes('spam') || n.includes('junk')) return 'mdi-alert-circle-outline'
            if (n.includes('archive') || n.includes('archiv')) return 'mdi-archive-outline'
            return 'mdi-folder-outline'
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

        watch(page, () => loadEmails())

        onMounted(() => {
            loadFolders()
            loadEmails()
        })

        return {
            t,
            folders, currentFolder, emails, loading, error, configError,
            page, totalPages, searchText,
            selectedEmail, emailBody, loadingBody,
            showCompose, composeData, sending, sendError,
            composeCustomer, customerSearch, customerResults, customerLoading,
            loadFolders, loadEmails, selectFolder, selectEmail,
            doSendEmail, onCustomerSearch, onCustomerSelect, openCustomer,
            getFolderIcon, formatDate, formatSize,
            sanitizeHtml, downloadAttachment,
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
    max-height: 600px;
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
