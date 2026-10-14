<!-- src/core/views/letters/letter.edit.view.vue -->
<!--
    Briefeditor: neuer Brief (an den aktuellen Kunden, an einen gesuchten
    Empfänger oder als Kopie eines Briefs) oder vorhandener Brief.

    Links der Empfänger mit Adresse, Ansprechperson, Datum, Ihr Zeichen und
    Unterzeichner; rechts Textbaustein, Betreff, Anrede (vorgeschlagen aus
    Ansprechperson/Kunde) und der Text. Darunter das Versandprotokoll.
    Aktionen: speichern, PDF, E-Mail, Post, Kopie, löschen. Vor E-Mail- und
    Postversand wird automatisch gespeichert.
-->
<template>
    <NavbarView />

    <v-container fluid class="pt-2 px-2 px-sm-4 letter-edit">
        <page-header
            :title="letter.id ? t('LetterEditView.titleEdit', { number: letter.letternumber }) : t('LetterEditView.titleNew')"
            :subtitle="cv ? cv.name : ''"
            :back-to="{ name: 'letters' }"
            :back-label="t('LetterEditView.backToList')"
        >
            <v-chip v-if="letter.id && !sentLog.length" size="small" variant="tonal" prepend-icon="mdi-pencil-outline">{{ t('LetterEditView.statusDraft') }}</v-chip>
            <v-chip v-else-if="sentLog.length" size="small" variant="tonal" color="success" prepend-icon="mdi-send-check-outline">{{ t('LetterEditView.statusSent', { n: sentLog.length }) }}</v-chip>
            <!-- Automatisches Speichern: Zustand statt Knopf -->
            <v-chip size="small" variant="text" :color="saveState.color" :prepend-icon="saveState.icon" class="text-body-2">{{ saveState.text }}</v-chip>
            <template #actions>
                <v-btn variant="tonal" color="error" prepend-icon="mdi-file-pdf-box" :loading="pdfLoading" :disabled="!hasRecipient" @click="showPdf">
                    {{ t('LetterEditView.pdf') }}
                </v-btn>
                <v-btn variant="tonal" color="info" prepend-icon="mdi-email" :disabled="!hasContent" @click="openEmailDialog">
                    {{ t('LetterEditView.email') }}
                </v-btn>
                <v-btn variant="tonal" color="deep-purple" prepend-icon="mdi-mailbox-up-outline" :disabled="!hasContent" @click="openPostalDialog">
                    {{ t('LetterEditView.post') }}
                </v-btn>
                <v-menu v-if="letter.id">
                    <template #activator="{ props: menuProps }">
                        <v-btn v-bind="menuProps" variant="tonal" icon="mdi-dots-vertical" :title="t('LetterEditView.more')" />
                    </template>
                    <v-list density="compact">
                        <v-list-item prepend-icon="mdi-content-copy" :title="t('LetterEditView.copy')" @click="copyLetter" />
                        <v-list-item prepend-icon="mdi-account-box-outline" :title="t('LetterEditView.openCustomer')" @click="openCustomer" />
                        <v-divider />
                        <v-list-item prepend-icon="mdi-delete-outline" base-color="error" :title="t('LetterEditView.delete')" @click="remove" />
                    </v-list>
                </v-menu>
            </template>
        </page-header>

        <v-alert v-if="sentLog.length" type="info" variant="tonal" density="compact" class="mb-3" icon="mdi-information-outline">
            {{ t('LetterEditView.alreadySentHint') }}
        </v-alert>

        <v-row>
            <!-- Empfänger und Rahmendaten -->
            <v-col cols="12" md="4">
                <section-card :title="t('LetterEditView.recipient')" icon="mdi-account-arrow-right-outline" class="letter-edit__card">
                    <template #actions>
                        <v-btn v-if="cv" size="small" variant="text" color="primary" prepend-icon="mdi-swap-horizontal" @click="changeRecipient">
                            {{ t('LetterEditView.changeRecipient') }}
                        </v-btn>
                    </template>
                        <!-- Empfänger suchen -->
                        <template v-if="!cv">
                            <v-btn-toggle v-model="searchType" mandatory density="compact" variant="outlined" color="primary" class="mb-3">
                                <v-btn value="customer" size="small"><v-icon start size="small">mdi-account-outline</v-icon>{{ t('LetterEditView.customer') }}</v-btn>
                                <v-btn value="vendor" size="small"><v-icon start size="small">mdi-truck-outline</v-icon>{{ t('LetterEditView.vendor') }}</v-btn>
                            </v-btn-toggle>
                            <v-autocomplete
                                v-model="searchSelected"
                                v-model:search="searchText"
                                :items="searchItems"
                                :loading="searchLoading"
                                item-title="name"
                                item-value="id"
                                return-object
                                :no-filter="true"
                                :label="t('LetterEditView.searchRecipient')"
                                :placeholder="t('LetterEditView.searchRecipientHint')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                prepend-inner-icon="mdi-magnify"
                                @update:model-value="onRecipientPicked"
                            />
                        </template>

                        <!-- Gewählter Empfänger -->
                        <template v-else>
                            <div class="d-flex align-start">
                                <v-icon :color="cv.src === 'V' ? 'orange-darken-2' : 'primary'" class="me-2 mt-1">
                                    {{ cv.src === 'V' ? 'mdi-truck-outline' : 'mdi-account-outline' }}
                                </v-icon>
                                <div class="flex-grow-1">
                                    <div class="font-weight-medium">{{ cv.name }}</div>
                                    <div v-if="cv.number" class="text-caption text-medium-emphasis">{{ cv.src === 'V' ? t('LetterEditView.vendorNumber') : t('LetterEditView.customerNumber') }} {{ cv.number }}</div>
                                    <div class="text-body-2 mt-1">
                                        <div v-if="cv.department_1">{{ cv.department_1 }}</div>
                                        <div v-if="cv.department_2">{{ cv.department_2 }}</div>
                                        <div v-if="selectedContact">{{ selectedContact.name }}</div>
                                        <div>{{ cv.street }}</div>
                                        <div>{{ cv.zipcode }} {{ cv.city }}</div>
                                        <div v-if="cv.country">{{ cv.country }}</div>
                                    </div>
                                </div>
                            </div>
                            <v-alert v-if="!addressOk" type="warning" variant="tonal" density="compact" class="mt-2" icon="mdi-map-marker-alert-outline">
                                {{ t('LetterEditView.addressIncomplete') }}
                            </v-alert>

                            <v-select
                                v-model="letter.cp_id"
                                :items="contactItems"
                                item-title="name"
                                item-value="cp_id"
                                :label="t('LetterEditView.contactPerson')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                clearable
                                class="mt-4"
                                prepend-inner-icon="mdi-account-tie-outline"
                                @update:model-value="onContactChanged"
                            />
                        </template>

                        <v-row dense class="mt-2">
                            <v-col cols="6">
                                <v-text-field v-model="letter.date" :label="t('LetterEditView.date')" type="date" variant="outlined" density="compact" hide-details />
                            </v-col>
                            <v-col cols="6">
                                <v-text-field v-model="letter.reference" :label="t('LetterEditView.reference')" variant="outlined" density="compact" hide-details />
                            </v-col>
                            <v-col cols="12">
                                <v-select v-model="letter.employee_id" :items="employees" item-title="name" item-value="id" :label="t('LetterEditView.signature')" variant="outlined" density="compact" hide-details prepend-inner-icon="mdi-draw-pen" />
                            </v-col>
                            <v-col cols="12">
                                <v-select v-model="letter.salesman_id" :items="employees" item-title="name" item-value="id" :label="t('LetterEditView.secondSignature')" variant="outlined" density="compact" hide-details clearable prepend-inner-icon="mdi-draw-pen" />
                            </v-col>
                            <v-col cols="12">
                                <v-textarea v-model="letter.intnotes" :label="t('LetterEditView.intnotes')" variant="outlined" density="compact" hide-details rows="2" auto-grow prepend-inner-icon="mdi-note-text-outline" />
                            </v-col>
                        </v-row>
                </section-card>
            </v-col>

            <!-- Brieftext -->
            <v-col cols="12" md="8">
                <section-card :title="t('LetterEditView.content')" icon="mdi-text-box-edit-outline" class="letter-edit__card">
                    <template #actions>
                        <!-- Textbausteine -->
                        <v-select
                            v-model="selectedTemplateId"
                            :items="templates"
                            item-title="name"
                            item-value="id"
                            :label="t('LetterEditView.template')"
                            :placeholder="t('LetterEditView.templateChoose')"
                            variant="solo-filled"
                            density="compact"
                            flat
                            hide-details
                            clearable
                            style="max-width: 280px"
                            @update:model-value="applyTemplate"
                        />
                        <v-menu>
                            <template #activator="{ props: menuProps }">
                                <v-btn v-bind="menuProps" icon="mdi-dots-vertical" size="small" variant="text" :title="t('LetterEditView.templateMenu')" />
                            </template>
                            <v-list density="compact">
                                <v-list-item prepend-icon="mdi-content-save-outline" :title="t('LetterEditView.templateSaveAs')" :disabled="!letter.subject && !letter.body" @click="openTemplateDialog(false)" />
                                <v-list-item v-if="selectedTemplateId" prepend-icon="mdi-content-save-edit-outline" :title="t('LetterEditView.templateUpdate')" @click="openTemplateDialog(true)" />
                                <v-list-item v-if="selectedTemplateId" prepend-icon="mdi-delete-outline" base-color="error" :title="t('LetterEditView.templateDelete')" @click="deleteTemplate" />
                            </v-list>
                        </v-menu>
                    </template>
                        <v-text-field ref="subjectField" v-model="letter.subject" :label="t('LetterEditView.subject')" variant="outlined" density="compact" hide-details class="mb-3" prepend-inner-icon="mdi-format-title" @focus="insertTarget = 'subject'" />
                        <v-text-field v-model="letter.greeting" :label="t('LetterEditView.greeting')" variant="outlined" density="compact" hide-details class="mb-3" prepend-inner-icon="mdi-hand-wave-outline">
                            <template #append-inner>
                                <v-tooltip location="top" :text="t('LetterEditView.greetingSuggest', { greeting: greetingSuggestion })">
                                    <template #activator="{ props: tip }">
                                        <v-icon v-bind="tip" size="small" :color="letter.greeting === greetingSuggestion ? 'grey-lighten-1' : 'primary'" style="cursor: pointer" @click="letter.greeting = greetingSuggestion">mdi-auto-fix</v-icon>
                                    </template>
                                </v-tooltip>
                            </template>
                        </v-text-field>
                        <HtmlEditorComponent ref="bodyEditor" v-model="letter.body" :placeholder="t('LetterEditView.bodyPlaceholder')" :min-height="320" :max-height="0" @focus="insertTarget = 'body'" />
                        <div class="text-caption text-medium-emphasis mt-2">
                            <v-icon size="14" class="me-1">mdi-draw-pen</v-icon>
                            {{ t('LetterEditView.closingHint') }}
                        </div>
                        <!-- Bausteine: Klick fügt den Wert als Pille an der Cursorstelle ein (Betreff oder Text) -->
                        <div class="d-flex align-center flex-wrap ga-1 mt-2">
                            <span class="text-caption text-medium-emphasis me-1">
                                <v-icon size="14" class="me-1">mdi-puzzle-outline</v-icon>{{ t('LetterEditView.placeholders') }}
                            </span>
                            <v-chip
                                v-for="ph in placeholders"
                                :key="ph.key"
                                size="small"
                                variant="tonal"
                                color="primary"
                                :title="ph.value ? t('LetterEditView.placeholderInsert', { value: ph.value }) : t('LetterEditView.placeholderEmpty')"
                                @mousedown.prevent
                                @click="insertPlaceholder(ph)"
                            >
                                {{ ph.label }}
                            </v-chip>
                        </div>
                </section-card>

                <!-- Versandprotokoll -->
                <section-card v-if="letter.id" :title="t('LetterEditView.dispatchLog')" icon="mdi-history" plain>
                    <template #actions>
                        <v-chip v-if="sentLog.length" size="x-small" variant="tonal" color="success">{{ sentLog.length }}</v-chip>
                    </template>
                        <div v-if="!sentLog.length" class="text-body-2 text-medium-emphasis letter-edit__empty">{{ t('LetterEditView.notSent') }}</div>
                        <v-list v-else density="compact" class="py-0">
                            <v-list-item v-for="e in sentLog" :key="e.channel + '-' + e.id">
                                <template #prepend>
                                    <v-icon :color="entryMeta(e).color">{{ entryMeta(e).icon }}</v-icon>
                                </template>
                                <v-list-item-title class="text-body-2">
                                    <span class="font-weight-medium">{{ entryMeta(e).label }}</span>
                                    <span class="text-medium-emphasis"> · {{ formatWhen(e.sent_at) }}</span>
                                    <span v-if="e.employee_name" class="text-medium-emphasis"> · {{ e.employee_name }}</span>
                                </v-list-item-title>
                                <v-list-item-subtitle class="text-caption">
                                    <span v-if="e.recipient">{{ e.recipient }}</span>
                                    <span v-if="e.channel === 'email' && e.subject"> · {{ e.subject }}</span>
                                    <span v-if="e.channel === 'post' && e.registered"> · {{ e.registered === 'r2' ? t('PostalDialog.registeredR2') : t('PostalDialog.registeredR1') }}</span>
                                    <span v-if="e.channel === 'post' && e.price"> · {{ formatMoney(e.price) }}</span>
                                    <span v-if="e.tracking_code"> · {{ e.tracking_code }}</span>
                                    <span v-if="e.tracking_status"> · {{ e.tracking_status }}</span>
                                    <span v-if="e.error" class="text-error"> · {{ e.error }}</span>
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-btn v-if="e.channel === 'post'" icon="mdi-open-in-app" size="x-small" variant="text" :title="t('LetterEditView.openPostal')" @click="openPostalDialog" />
                                </template>
                            </v-list-item>
                        </v-list>
                </section-card>
            </v-col>
        </v-row>

        <!-- E-Mail senden -->
        <v-dialog v-model="emailDialog" max-width="640" persistent scrollable>
            <v-card>
                <v-card-title class="d-flex align-center bg-info text-white">
                    <v-icon class="me-2">mdi-email</v-icon>
                    {{ t('LetterEditView.emailTitle') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="emailDialog = false" />
                </v-card-title>
                <v-card-text class="pt-4">
                    <v-combobox v-model="email.to" :items="emailSuggestions" :label="t('LetterEditView.emailTo')" variant="outlined" density="compact" hide-details="auto" class="mb-3" prepend-inner-icon="mdi-account-outline" />
                    <v-text-field v-model="email.cc" :label="t('LetterEditView.emailCc')" variant="outlined" density="compact" hide-details class="mb-3" />
                    <v-text-field v-model="email.subject" :label="t('LetterEditView.emailSubject')" variant="outlined" density="compact" hide-details class="mb-3" />
                    <HtmlEditorComponent v-model="email.body" :min-height="160" />
                    <div class="text-caption text-medium-emphasis mt-2">
                        <v-icon size="14" class="me-1">mdi-paperclip</v-icon>{{ t('LetterEditView.emailAttachmentHint') }}
                    </div>
                </v-card-text>
                <v-divider />
                <v-card-actions class="px-4 py-3">
                    <v-spacer />
                    <v-btn variant="text" @click="emailDialog = false">{{ t('LetterEditView.cancel') }}</v-btn>
                    <v-btn color="info" variant="elevated" prepend-icon="mdi-send" :loading="emailSending" :disabled="!email.to" @click="sendEmail">{{ t('LetterEditView.emailSend') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Textbaustein speichern -->
        <v-dialog v-model="templateDialog" max-width="420">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ templateUpdate ? t('LetterEditView.templateUpdate') : t('LetterEditView.templateSaveAs') }}</v-card-title>
                <v-card-text>
                    <v-text-field v-model="templateName" :label="t('LetterEditView.templateName')" variant="outlined" density="compact" hide-details autofocus @keyup.enter="saveTemplate" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="templateDialog = false">{{ t('LetterEditView.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :disabled="!templateName" @click="saveTemplate">{{ t('LetterEditView.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <PostalDialogComponent v-model="postalDialog" record-type="letter" :record-id="letter.id" @sent="onPostalSent" @changed="reloadLetter" />
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'
import PostalDialogComponent from '@/core/components/postal/postal.dialog.component.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import { openBase64Pdf } from '@/core/utils/download.js'

const props = defineProps({ id: { type: [String, Number], default: null } })

const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const oserp = oserpStore()

const letter = ref(emptyLetter())
const cv = ref(null)
const contacts = ref([])
const employees = ref([])
const templates = ref([])
const sentLog = ref([])
const greetingSuggestion = ref('')
const loading = ref(false)
const saving = ref(false)
const pdfLoading = ref(false)
let savedSnapshot = ''

// Bausteine mit Klarnamen; der aktuelle Wert dient als Vorschau im Tooltip
const insertTarget = ref('body')
const subjectField = ref(null)
const bodyEditor = ref(null)
const placeholders = computed(() => {
    const emp = employees.value.find(e => e.id === letter.value.employee_id)
    const cp = selectedContact.value
    const cpName = cp ? cp.name.replace(/^(Herr|Frau|Mr\.?|Ms\.?|Mrs\.?|Dr\.?)\s+/i, '') : ''
    return [
        { key: 'name',           label: t('LetterEditView.ph.name'),          value: cv.value?.name || '' },
        { key: 'customernumber', label: t('LetterEditView.ph.customernumber'), value: cv.value?.number || '' },
        { key: 'cp_givenname',   label: t('LetterEditView.ph.cpGivenname'),   value: cpName.split(' ').slice(0, -1).join(' ') },
        { key: 'cp_name',        label: t('LetterEditView.ph.cpName'),        value: cpName.split(' ').slice(-1)[0] || '' },
        { key: 'date',           label: t('LetterEditView.ph.date'),          value: letter.value.date ? new Date(letter.value.date).toLocaleDateString(locale.value) : '' },
        { key: 'letternumber',   label: t('LetterEditView.ph.letternumber'),  value: letter.value.letternumber || '' },
        { key: 'employee_name',  label: t('LetterEditView.ph.employeeName'),  value: emp?.name || '' },
        { key: 'company',        label: t('LetterEditView.ph.company'),       value: oserp.getClientDefaultValue('company_name', '') || oserp.session?.company_config?.defaults?.company || '' },
    ]
})

/** Baustein an der Cursorstelle einfügen: im Betreff als Text, im Brieftext als Pille */
function insertPlaceholder(ph) {
    if (insertTarget.value === 'subject') {
        const input = subjectField.value?.$el?.querySelector('input')
        const token = `<%${ph.key}%>`
        if (input) {
            const start = input.selectionStart ?? input.value.length
            const end = input.selectionEnd ?? start
            letter.value.subject = input.value.slice(0, start) + token + input.value.slice(end)
            nextTick(() => { input.focus(); input.setSelectionRange(start + token.length, start + token.length) })
        } else {
            letter.value.subject = (letter.value.subject || '') + token
        }
        return
    }
    bodyEditor.value?.insertPlaceholder(ph.key, ph.label)
}

function emptyLetter() {
    return {
        id: null, letternumber: '', date: new Date().toISOString().slice(0, 10), customer_id: null, vendor_id: null,
        cp_id: null, reference: '', subject: '', greeting: '', body: '', intnotes: '',
        employee_id: oserp.session?.logged_in_employee?.id || null, salesman_id: null,
    }
}

const hasRecipient = computed(() => !!(letter.value.customer_id || letter.value.vendor_id))
// Ein Brief entsteht erst, wenn etwas drinsteht: Betreff oder Text
const hasContent = computed(() => hasRecipient.value && !!(letter.value.subject?.trim() || stripHtml(letter.value.body)))
const canSave = computed(() => hasContent.value && !saving.value)
function stripHtml(html) {
    return String(html || '').replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim()
}
const addressOk = computed(() => !!(cv.value?.street && cv.value?.zipcode && cv.value?.city))
const contactItems = computed(() => contacts.value)
const selectedContact = computed(() => contacts.value.find(c => c.cp_id === letter.value.cp_id) || null)
const snapshot = () => JSON.stringify(letter.value)
const dirty = computed(() => hasRecipient.value && snapshot() !== savedSnapshot)

// ------------------------------------------------------------------
// Laden
// ------------------------------------------------------------------
async function load(params) {
    loading.value = true
    try {
        const { data } = await axios.post('/api/letters/', { action: 'getLetter', ...params })
        if (!data.success) throw new Error(data.text)
        const r = data.payload.results
        letter.value = { ...emptyLetter(), ...r.letter, date: (r.letter.date || '').slice(0, 10) }
        cv.value = r.customer_vendor
        contacts.value = r.contacts || []
        employees.value = r.employees || []
        templates.value = r.templates || []
        sentLog.value = r.sent_log || []
        greetingSuggestion.value = r.greeting_suggestion || ''
        // Auch ein neuer Brief gilt erst als geändert, wenn der Benutzer etwas eingibt
        savedSnapshot = snapshot()
    } catch (e) {
        toast.error(t('LetterEditView.loadError', { error: e.message }))
        router.replace({ name: 'letters' })
    } finally {
        loading.value = false
    }
}

function initialParams() {
    if (props.id) return { id: Number(props.id) }
    const q = route.query
    if (q.copy_of) return { copy_of: Number(q.copy_of) }
    if (q.vendor_id) return { vendor_id: Number(q.vendor_id), cp_id: Number(q.cp_id || 0) }
    if (q.customer_id) return { customer_id: Number(q.customer_id), cp_id: Number(q.cp_id || 0) }
    // Kein Empfänger angegeben: der aktuell gewählte Kunde/Lieferant ist der naheliegende
    const p = oserp.customer_vendor?.profile
    if (p?.id) return p.src === 'V' ? { vendor_id: p.id } : { customer_id: p.id }
    return null
}

async function reloadLetter() {
    if (letter.value.id) await load({ id: letter.value.id })
}

onMounted(async () => {
    const params = initialParams()
    if (params) await load(params)
    else await loadListsOnly()
})
watch(() => props.id, (id) => { if (id && Number(id) !== Number(letter.value.id)) load({ id: Number(id) }) })

/** Ohne Empfänger: nur Mitarbeiter und Textbausteine laden (über einen leeren Brief an niemanden geht das nicht) */
async function loadListsOnly() {
    letter.value = emptyLetter()
    cv.value = null
    contacts.value = []
    savedSnapshot = ''
}

// ------------------------------------------------------------------
// Empfänger suchen / wechseln
// ------------------------------------------------------------------
const searchType = ref('customer')
const searchText = ref('')
const searchItems = ref([])
const searchLoading = ref(false)
const searchSelected = ref(null)
let searchTimer = null

watch(searchText, (val) => {
    clearTimeout(searchTimer)
    const s = (val || '').trim()
    if (s.length < 3) { searchItems.value = []; return }
    searchLoading.value = true
    searchTimer = setTimeout(async () => {
        try {
            const { data } = await axios.post('/api/faktura/', { action: 'searchFakturaCustomers', search: s, type: searchType.value })
            searchItems.value = (data?.results || data?.payload?.results || []).map(r => ({ id: r.id, name: r.name }))
        } catch {
            searchItems.value = []
        } finally {
            searchLoading.value = false
        }
    }, 300)
})
watch(searchType, () => { searchText.value = ''; searchItems.value = []; searchSelected.value = null })

async function onRecipientPicked(item) {
    if (!item) return
    const keep = { subject: letter.value.subject, body: letter.value.body, reference: letter.value.reference, intnotes: letter.value.intnotes, id: letter.value.id, letternumber: letter.value.letternumber }
    const wasDirty = dirty.value
    await load(searchType.value === 'vendor' ? { vendor_id: item.id } : { customer_id: item.id })
    // Bereits geschriebener Text bleibt beim Empfängerwechsel erhalten
    Object.assign(letter.value, keep)
    if (wasDirty || keep.id) savedSnapshot = ''
    searchSelected.value = null
    searchItems.value = []
}

function changeRecipient() {
    savedSnapshot = ''
    cv.value = null
    letter.value.customer_id = null
    letter.value.vendor_id = null
    letter.value.cp_id = null
    contacts.value = []
}

async function onContactChanged() {
    // Anrede neu vorschlagen, wenn sie noch dem alten Vorschlag entsprach oder leer ist
    const keepGreeting = letter.value.greeting && letter.value.greeting !== greetingSuggestion.value
    try {
        const params = letter.value.vendor_id ? { vendor_id: letter.value.vendor_id } : { customer_id: letter.value.customer_id }
        const { data } = await axios.post('/api/letters/', { action: 'getLetter', ...params, cp_id: letter.value.cp_id || 0 })
        if (data.success) {
            greetingSuggestion.value = data.payload.results.greeting_suggestion || ''
            if (!keepGreeting) letter.value.greeting = greetingSuggestion.value
        }
    } catch { /* Vorschlag ist Komfort */ }
}

// ------------------------------------------------------------------
// Speichern, PDF, Kopie, Löschen
// ------------------------------------------------------------------
async function save(silent = false) {
    if (!canSave.value) return false
    saving.value = true
    try {
        const { data } = await axios.post('/api/letters/', { action: 'saveLetter', letter: letter.value })
        if (!data.success) throw new Error(data.text)
        const wasNew = !letter.value.id
        letter.value.id = data.payload.results.id
        letter.value.letternumber = data.payload.results.letternumber
        savedSnapshot = snapshot()
        if (!silent) toast.success(t('LetterEditView.saved', { number: letter.value.letternumber }))
        if (wasNew) {
            toast.info(t('LetterEditView.created', { number: letter.value.letternumber }))
            router.replace({ name: 'letter-edit', params: { id: letter.value.id } })
        }
        return true
    } catch (e) {
        alerts.error(e.message, t('LetterEditView.saveError'))
        return false
    } finally {
        saving.value = false
    }
}

// ------------------------------------------------------------------
// Automatisches Speichern: 1,5 s nach der letzten Änderung, sobald
// Empfänger und Inhalt da sind. Ohne Inhalt entsteht kein Brief.
// ------------------------------------------------------------------
const lastSavedAt = ref(null)
let autosaveTimer = null
let pendingSave = null

function scheduleAutosave() {
    clearTimeout(autosaveTimer)
    if (!dirty.value || !hasContent.value) return
    autosaveTimer = setTimeout(() => flushAutosave(), 1500)
}
async function flushAutosave() {
    clearTimeout(autosaveTimer)
    if (!dirty.value || !hasContent.value) return true
    if (pendingSave) await pendingSave
    if (!dirty.value) return true
    pendingSave = save(true).then(ok => { if (ok) lastSavedAt.value = new Date(); return ok })
    const ok = await pendingSave
    pendingSave = null
    return ok
}
watch(() => snapshot(), () => scheduleAutosave())
onBeforeUnmount(() => clearTimeout(autosaveTimer))

const saveState = computed(() => {
    if (saving.value) return { icon: 'mdi-progress-upload', color: 'primary', text: t('LetterEditView.saving') }
    if (!hasRecipient.value) return { icon: 'mdi-account-question-outline', color: 'grey', text: t('LetterEditView.needRecipient') }
    if (!hasContent.value && !letter.value.id) return { icon: 'mdi-text-box-plus-outline', color: 'grey', text: t('LetterEditView.needContent') }
    if (dirty.value) return { icon: 'mdi-content-save-edit-outline', color: 'warning', text: t('LetterEditView.unsaved') }
    if (lastSavedAt.value) return { icon: 'mdi-cloud-check-outline', color: 'success', text: t('LetterEditView.savedAt', { time: lastSavedAt.value.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) }) }
    return { icon: 'mdi-cloud-check-outline', color: 'success', text: t('LetterEditView.savedState') }
})

/** Vor Versand: ungespeicherte Änderungen sichern */
async function ensureSaved() {
    if (!hasContent.value) return false
    if (!letter.value.id || dirty.value) return flushAutosave()
    return true
}

async function showPdf() {
    pdfLoading.value = true
    try {
        const payload = letter.value.id && !dirty.value
            ? { action: 'getLetterPdf', id: letter.value.id }
            : { action: 'getLetterPdf', letter: letter.value }
        const { data } = await axios.post('/api/letters/', payload)
        if (!data.success) throw new Error(data.text)
        openBase64Pdf(data.payload.results.pdf, data.payload.results.filename)
    } catch (e) {
        alerts.error(e.message, t('LetterEditView.pdfError'))
    } finally {
        pdfLoading.value = false
    }
}

function copyLetter() {
    router.push({ name: 'letter-new', query: { copy_of: String(letter.value.id) } })
}

function openCustomer() {
    if (!cv.value) return
    router.push({ name: cv.value.src === 'V' ? 'change-vendor' : 'change-customer', params: { id: cv.value.id } })
}

async function remove() {
    const ok = await alerts.warning(t('LetterEditView.confirmDelete', { number: letter.value.letternumber }), t('LetterEditView.delete'))
    if (!ok.isConfirmed) return
    try {
        const { data } = await axios.post('/api/letters/', { action: 'deleteLetter', id: letter.value.id })
        if (!data.success) throw new Error(data.text)
        savedSnapshot = snapshot()
        toast.success(t('LetterEditView.deleted'))
        router.replace({ name: 'letters' })
    } catch (e) {
        alerts.error(e.message, t('LetterEditView.delete'))
    }
}

onBeforeRouteLeave(async () => {
    if (!dirty.value) return true
    // Inhalt vorhanden: still speichern. Ohne Inhalt gibt es nichts zu sichern.
    if (hasContent.value) {
        const ok = await flushAutosave()
        if (ok) return true
        const r = await alerts.question(t('LetterEditView.confirmLeave'), t('LetterEditView.unsaved'), t('LetterEditView.leaveWithoutSaving'), t('LetterEditView.stay'))
        return r.isConfirmed
    }
    return true
})

// ------------------------------------------------------------------
// Textbausteine
// ------------------------------------------------------------------
const selectedTemplateId = ref(null)
const templateDialog = ref(false)
const templateUpdate = ref(false)
const templateName = ref('')

async function applyTemplate(id) {
    const tpl = templates.value.find(x => x.id === id)
    if (!tpl) return
    if ((letter.value.subject || letter.value.body) && !(await alerts.question(t('LetterEditView.templateReplace'), tpl.name)).isConfirmed) {
        selectedTemplateId.value = null
        return
    }
    if (tpl.subject) letter.value.subject = tpl.subject
    if (tpl.body) letter.value.body = tpl.body
}

function openTemplateDialog(update) {
    templateUpdate.value = update
    templateName.value = update ? (templates.value.find(x => x.id === selectedTemplateId.value)?.name || '') : (letter.value.subject || '')
    templateDialog.value = true
}

async function saveTemplate() {
    try {
        const { data } = await axios.post('/api/letters/', {
            action: 'saveLetterTemplate',
            id: templateUpdate.value ? selectedTemplateId.value : 0,
            name: templateName.value,
            subject: letter.value.subject,
            body: letter.value.body,
        })
        if (!data.success) throw new Error(data.text)
        const id = data.payload.results.id
        const existing = templates.value.find(x => x.id === id)
        if (existing) Object.assign(existing, { name: templateName.value, subject: letter.value.subject, body: letter.value.body })
        else templates.value.push({ id, name: templateName.value, subject: letter.value.subject, body: letter.value.body })
        templates.value.sort((a, b) => a.name.localeCompare(b.name))
        selectedTemplateId.value = id
        templateDialog.value = false
        toast.success(t('LetterEditView.templateSaved'))
    } catch (e) {
        alerts.error(e.message, t('LetterEditView.templateSaveAs'))
    }
}

async function deleteTemplate() {
    const tpl = templates.value.find(x => x.id === selectedTemplateId.value)
    if (!tpl) return
    const ok = await alerts.warning(t('LetterEditView.templateConfirmDelete', { name: tpl.name }), t('LetterEditView.templateDelete'))
    if (!ok.isConfirmed) return
    try {
        const { data } = await axios.post('/api/letters/', { action: 'deleteLetterTemplate', id: tpl.id })
        if (!data.success) throw new Error(data.text)
        templates.value = templates.value.filter(x => x.id !== tpl.id)
        selectedTemplateId.value = null
        toast.success(t('LetterEditView.templateDeleted'))
    } catch (e) {
        alerts.error(e.message, t('LetterEditView.templateDelete'))
    }
}

// ------------------------------------------------------------------
// E-Mail
// ------------------------------------------------------------------
const emailDialog = ref(false)
const emailSending = ref(false)
const email = ref({ to: '', cc: '', subject: '', body: '' })
const emailSuggestions = computed(() => {
    const list = []
    if (selectedContact.value?.email) list.push(selectedContact.value.email)
    if (cv.value?.email) list.push(cv.value.email)
    for (const c of contacts.value) if (c.email && !list.includes(c.email)) list.push(c.email)
    return list
})

async function openEmailDialog() {
    if (!(await ensureSaved())) return
    email.value = {
        to: emailSuggestions.value[0] || '',
        cc: cv.value?.cc || '',
        subject: letter.value.subject || t('LetterEditView.emailDefaultSubject', { number: letter.value.letternumber }),
        body: `<p>${letter.value.greeting || ''}</p><p>${t('LetterEditView.emailDefaultBody')}</p><p>${t('LetterEditView.emailDefaultClosing')}<br>${employees.value.find(e => e.id === letter.value.employee_id)?.name || ''}</p>`,
    }
    emailDialog.value = true
}

async function sendEmail() {
    emailSending.value = true
    try {
        const { data } = await axios.post('/api/letters/', {
            action: 'sendLetterEmail',
            id: letter.value.id,
            to: email.value.to,
            cc: email.value.cc,
            subject: email.value.subject,
            body_html: email.value.body,
        })
        if (!data.success) throw new Error(data.text)
        sentLog.value.unshift({ id: Date.now(), ...data.payload.results.entry })
        emailDialog.value = false
        toast.success(t('LetterEditView.emailSent', { to: email.value.to }))
    } catch (e) {
        alerts.error(e.message, t('LetterEditView.emailTitle'))
    } finally {
        emailSending.value = false
    }
}

// ------------------------------------------------------------------
// Post
// ------------------------------------------------------------------
const postalDialog = ref(false)
async function openPostalDialog() {
    if (!(await ensureSaved())) return
    postalDialog.value = true
}
function onPostalSent(entry) {
    sentLog.value.unshift(entry)
}

// ------------------------------------------------------------------
// Anzeige
// ------------------------------------------------------------------
function entryMeta(e) {
    if (e.channel === 'email') return { icon: 'mdi-email', color: 'info', label: t('LetterEditView.channelEmail') }
    const m = {
        done: { icon: 'mdi-check-all', color: 'success', label: t('PostalDialog.statusDone') },
        sent: { icon: 'mdi-mailbox-up-outline', color: 'deep-purple', label: t('PostalDialog.statusSent') },
        manual: { icon: 'mdi-printer-check', color: 'grey', label: t('PostalDialog.statusManual') },
        canceled: { icon: 'mdi-cancel', color: 'warning', label: t('PostalDialog.statusCanceled') },
        error: { icon: 'mdi-alert-circle', color: 'error', label: t('PostalDialog.statusError') },
    }
    const s = m[e.status] || { icon: 'mdi-clock-outline', color: 'grey', label: t('PostalDialog.statusQueued') }
    return { ...s, label: t('LetterEditView.channelPost') + ' · ' + s.label }
}
function formatWhen(v) {
    if (!v) return ''
    return new Date(v).toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
function formatMoney(v) {
    return Number(v || 0).toLocaleString(locale.value, { style: 'currency', currency: 'EUR' })
}
</script>

<style scoped>
.letter-edit__card {
    margin-bottom: var(--oserp-gap-lg);
}
.letter-edit__empty {
    padding: var(--oserp-gap-lg);
}
</style>
