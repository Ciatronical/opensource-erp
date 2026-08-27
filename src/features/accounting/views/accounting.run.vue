<!-- src/features/accounting/views/accounting.run.vue -->
<!--
    Der Durchlauf — der zweite und letzte Bildschirm der Buchhaltung.

    Ein Layout fuer alle Stapel: links der Beleg oder der Bankumsatz, rechts der
    fertige Vorschlag als lesbarer Satz. Die haeufigste Handlung ist eine Taste.
    Was hier gebucht wird, geht ins echte Hauptbuch — deshalb gibt es ein
    Rueckgaengig nur dort, wo es das auch wirklich sein kann (Bankzuordnung);
    eine freigegebene Eingangsrechnung ist gebucht und wird nur noch storniert.
-->
<template>
    <NavbarView />

    <v-container fluid class="pt-3 run">
        <!-- Kopf -->
        <div class="d-flex align-center flex-wrap ga-3 mb-2">
            <v-btn icon variant="text" size="small" :aria-label="t('AccountingView.run.back')"
                   @click="leave"><v-icon>mdi-arrow-left</v-icon></v-btn>
            <h1 class="text-h6 mb-0">{{ stackTitle }}</h1>
            <v-chip v-if="items.length" size="small" variant="tonal">
                {{ t('AccountingView.run.position', { current: Math.min(index + 1, items.length), total: items.length }) }}
            </v-chip>
            <v-chip v-if="total > items.length" size="small" variant="text" class="text-medium-emphasis">
                {{ t('AccountingView.run.ofTotal', { total }) }}
            </v-chip>
            <v-spacer />
            <EasymodeSwitch />
        </div>

        <v-progress-linear :model-value="progress" color="primary" height="4" rounded class="mb-4" />

        <!-- Ladezustand -->
        <div v-if="loading" class="d-flex justify-center pa-12">
            <v-progress-circular indeterminate color="primary" size="42" />
        </div>

        <!-- Stapel leer oder durchgearbeitet -->
        <v-card v-else-if="finished" variant="outlined" class="pa-8 text-center mx-auto" max-width="640">
            <v-icon size="56" :color="counters.done ? 'success' : 'medium-emphasis'">
                {{ counters.done ? 'mdi-check-circle-outline' : 'mdi-tray-remove' }}
            </v-icon>
            <h2 class="text-h6 mt-4 mb-1">
                {{ counters.done ? t('AccountingView.run.doneTitle') : t('AccountingView.run.emptyTitle') }}
            </h2>
            <p class="text-body-2 text-medium-emphasis mb-4">
                <template v-if="counters.done">
                    {{ t('AccountingView.run.doneSummary', {
                        done: counters.done, later: counters.later, failed: counters.failed }) }}
                </template>
                <template v-else>{{ t('AccountingView.run.emptySub') }}</template>
            </p>
            <div class="d-flex justify-center ga-2 flex-wrap">
                <v-btn color="primary" variant="elevated" class="text-none" @click="leave">
                    {{ t('AccountingView.run.backToCockpit') }}
                </v-btn>
                <v-btn v-if="counters.later" variant="outlined" class="text-none" @click="replayLater">
                    {{ t('AccountingView.run.replayLater', { count: counters.later }) }}
                </v-btn>
            </div>
        </v-card>

        <!-- ── Arbeitsflaeche: links Beleg, rechts Entscheidung ───────────── -->
        <v-row v-else-if="current" dense>
            <!-- Links -->
            <v-col cols="12" md="6">
                <v-card variant="outlined" class="source">
                    <template v-if="kind === 'belege'">
                        <v-card-text class="pb-0">
                            <div class="source__meta">
                                {{ current.invoice_date_fmt || '—' }} ·
                                {{ current.original_name || t('AccountingView.run.noDocument') }}
                            </div>
                            <div class="text-subtitle-1 font-weight-medium">
                                {{ current.vendor_name || current.customer_name || t('AccountingView.run.unknownPartner') }}
                                <span v-if="current.invoice_number" class="text-medium-emphasis">
                                    — {{ current.invoice_number }}
                                </span>
                            </div>
                        </v-card-text>
                        <v-card-text>
                            <iframe v-if="current.document_id" :src="documentUrl(current.document_id)"
                                    class="source__frame" :title="current.original_name || ''"></iframe>
                            <v-alert v-else type="info" variant="tonal" density="comfortable"
                                     :text="t('AccountingView.run.noDocumentHint')" />
                        </v-card-text>
                    </template>

                    <template v-else>
                        <v-card-text>
                            <div class="source__meta">
                                {{ current.transdate_fmt }} · {{ current.bank_account }}
                            </div>
                            <div class="text-h5 font-weight-bold mt-1"
                                 :class="Number(current.amount) < 0 ? 'text-error' : 'text-success'">
                                {{ Number(current.amount) < 0 ? '−' : '+' }}{{ money(Math.abs(current.amount)) }}
                            </div>
                            <div class="text-subtitle-1 mt-3">{{ current.remote_name || '—' }}</div>
                            <div class="text-caption text-medium-emphasis">{{ current.remote_iban || '' }}</div>
                            <v-divider class="my-3" />
                            <div class="text-caption text-medium-emphasis mb-1">
                                {{ t('AccountingView.run.purpose') }}
                            </div>
                            <div class="source__purpose">{{ current.purpose || '—' }}</div>
                        </v-card-text>
                    </template>
                </v-card>
            </v-col>

            <!-- Rechts -->
            <v-col cols="12" md="6">
                <v-card variant="outlined">
                    <v-card-text>
                        <!-- Der Vorschlag als Satz -->
                        <p class="sentence mb-4" v-html="sentence"></p>

                        <!-- Sicherheit -->
                        <div v-if="confidence !== null" class="d-flex align-center ga-2 mb-3">
                            <v-icon size="small" :color="confidenceColor">mdi-robot-outline</v-icon>
                            <span class="text-body-2">{{ confidenceLabel }}</span>
                            <v-progress-linear :model-value="confidence" :color="confidenceColor"
                                               height="6" rounded style="max-width: 140px" />
                        </div>

                        <!-- Fachliche Zusatzangaben -->
                        <v-alert v-if="!easymode && kind === 'belege'" variant="tonal" density="compact"
                                 class="mb-3 text-body-2" icon="mdi-swap-horizontal">
                            {{ t('AccountingView.run.postingLine', {
                                debit: current.debit_account || '—',
                                credit: current.credit_account || '—',
                                rate: rate(current.tax_rate)
                            }) }}
                        </v-alert>

                        <!-- Hinweis der KI -->
                        <div v-if="current.ai_notes && !editing" class="text-caption text-medium-emphasis mb-3">
                            <v-icon size="14" class="mr-1">mdi-information-outline</v-icon>{{ current.ai_notes }}
                        </div>

                        <!-- Kein Vorschlag: direkt suchen -->
                        <v-alert v-if="kind === 'bank' && !current.target_type && !editing"
                                 type="warning" variant="tonal" density="comfortable" class="mb-3"
                                 :text="t('AccountingView.run.noCandidate')" />

                        <!-- ── Ändern ──────────────────────────────────── -->
                        <v-expand-transition>
                            <div v-if="editing" class="edit pa-3 mb-3">
                                <template v-if="kind === 'belege'">
                                    <!-- Das Feld startet leer: wer "Ändern" waehlt, sucht ein
                                         anderes Konto. Ein vorbelegter Titel wuerde die Eingabe
                                         nur verlaengern statt sie zu ersetzen. -->
                                    <div class="text-caption text-medium-emphasis mb-1">
                                        {{ t('AccountingView.run.currentAccount', {
                                            account: current.debit_name
                                                ? `${current.debit_name} · ${current.debit_account}`
                                                : (current.debit_account || '—') }) }}
                                    </div>
                                    <v-autocomplete
                                        v-model="edit.debit_account"
                                        v-model:search="accountSearch"
                                        :items="accountOptions"
                                        item-title="label" item-value="accno"
                                        :label="label('AccountingView.run.editAccount')"
                                        density="compact" variant="outlined" no-filter clearable
                                        prepend-inner-icon="mdi-tag-outline"
                                        :placeholder="t('AccountingView.run.accountSearchHint')"
                                        :no-data-text="t('AccountingView.run.accountSearchHint')"
                                        :loading="accountLoading" hide-details class="mb-3"
                                        @update:model-value="onAccountPicked"
                                        @update:search="onAccountSearch" />
                                    <v-text-field v-model="edit.description"
                                                  :label="t('AccountingView.run.editText')"
                                                  density="compact" variant="outlined" hide-details class="mb-3" />
                                    <v-select v-if="!easymode" v-model="edit.tax_rate" :items="[0, 7, 19]"
                                              :label="t('AccountingView.run.editRate')"
                                              density="compact" variant="outlined" hide-details class="mb-3" />
                                </template>

                                <template v-else>
                                    <v-autocomplete
                                        v-model="edit.invoice"
                                        v-model:search="invoiceSearch"
                                        :items="invoiceOptions"
                                        item-title="_label" return-object
                                        :label="label('AccountingView.run.editInvoice')"
                                        density="compact" variant="outlined" no-filter
                                        prepend-inner-icon="mdi-receipt-text-outline"
                                        :loading="invoiceLoading" hide-details class="mb-3"
                                        :no-data-text="t('AccountingView.run.searchInvoiceHint')"
                                        @update:search="onInvoiceSearch" />
                                </template>

                                <div class="d-flex ga-2">
                                    <v-btn color="primary" size="small" variant="elevated" class="text-none"
                                           :loading="working" @click="applyEdit">
                                        {{ t('AccountingView.run.applyEdit') }}
                                    </v-btn>
                                    <v-btn size="small" variant="text" class="text-none" @click="editing = false">
                                        {{ t('AccountingView.run.cancelEdit') }}
                                    </v-btn>
                                </div>
                            </div>
                        </v-expand-transition>

                        <!-- ── Tasten ──────────────────────────────────── -->
                        <div class="d-flex ga-2 flex-wrap mb-3">
                            <v-btn color="primary" variant="elevated" class="text-none"
                                   :disabled="!canConfirm" :loading="working" @click="confirm">
                                <v-icon start>mdi-check</v-icon>{{ confirmLabel }}
                                <kbd class="ml-2">⏎</kbd>
                            </v-btn>
                            <!-- Ohne Vorschlag ist Suchen die eigentliche Handlung —
                                 dann tritt diese Schaltflaeche nach vorn. -->
                            <v-btn :variant="canConfirm ? 'outlined' : 'elevated'"
                                   :color="canConfirm ? undefined : 'primary'"
                                   class="text-none" @click="startEdit">
                                <v-icon start>mdi-pencil-outline</v-icon>{{ label('AccountingView.run.change') }}
                                <kbd class="ml-2">E</kbd>
                            </v-btn>
                            <v-btn variant="text" class="text-none" @click="later">
                                {{ t('AccountingView.run.later') }} <kbd class="ml-2">S</kbd>
                            </v-btn>
                            <v-btn v-if="lastUndo" variant="text" class="text-none" color="warning"
                                   :loading="working" @click="undo">
                                <v-icon start>mdi-undo</v-icon>{{ t('AccountingView.run.undo') }}
                                <kbd class="ml-2">Z</kbd>
                            </v-btn>
                        </div>

                        <div class="d-flex ga-3 flex-wrap text-caption text-medium-emphasis">
                            <span><kbd>←</kbd> <kbd>→</kbd> {{ t('AccountingView.run.keyBrowse') }}</span>
                            <span><kbd>Esc</kbd> {{ t('AccountingView.run.keyLeave') }}</span>
                            <span v-if="kind === 'belege'">
                                <v-icon size="13" class="mr-1">mdi-lock-outline</v-icon>
                                {{ t('AccountingView.run.noUndoHint') }}
                            </span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </v-container>

    <v-snackbar v-model="toast.show" :color="toast.color" timeout="4000" location="bottom">
        {{ toast.text }}
    </v-snackbar>

    <!-- Strg+K soll auch mitten im Durchlauf erreichbar sein -->
    <CommandPalette />
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import EasymodeSwitch from '../components/accounting.easymode-switch.vue'
import CommandPalette from '../components/accounting.command-palette.vue'
import { useCockpit } from '../composables/useCockpit.js'
import { useEasymode } from '../composables/useEasymode.js'
import { useAccounting } from '../composables/useAccounting.js'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { easymode, label } = useEasymode()
const {
    loading, fetchStack, matchBankTransaction, unmatchBankTransaction, searchOpenInvoices
} = useCockpit()
const { approveBooking, updateBooking, searchAccounts } = useAccounting()

const kind = computed(() => (route.params.kind === 'bank' ? 'bank' : 'belege'))

const items = ref([])
const total = ref(0)
const index = ref(0)
const working = ref(false)
const editing = ref(false)
const finished = ref(false)
const laterIds = ref([])
const counters = ref({ done: 0, later: 0, failed: 0 })
const lastUndo = ref(null)
const toast = ref({ show: false, text: '', color: 'success' })

const edit = ref({ debit_account: null, debit_name: null, description: '', tax_rate: 19, invoice: null })
const accountOptions = ref([])
const accountLoading = ref(false)
const accountSearch = ref('')
const invoiceOptions = ref([])
const invoiceLoading = ref(false)
const invoiceSearch = ref('')

const current = computed(() => items.value[index.value] || null)
const progress = computed(() => items.value.length ? (index.value / items.value.length) * 100 : 0)

const stackTitle = computed(() => kind.value === 'bank'
    ? label('AccountingView.cockpit.tiles.bank')
    : label('AccountingView.cockpit.tiles.documents'))

const confidence = computed(() => {
    if (!current.value) return null
    if (kind.value === 'bank') {
        return current.value.candidate_score !== null && current.value.candidate_score !== undefined
            ? Number(current.value.candidate_score) : null
    }
    return current.value.ai_confidence !== null && current.value.ai_confidence !== undefined
        ? Math.round(Number(current.value.ai_confidence) * 100) : null
})

const confidenceColor = computed(() =>
    confidence.value === null ? 'medium-emphasis'
        : confidence.value >= 90 ? 'success'
        : confidence.value >= 60 ? 'warning' : 'error'
)

const confidenceLabel = computed(() => {
    if (confidence.value === null) return label('AccountingView.run.noConfidence')
    if (confidence.value >= 90) return label('AccountingView.run.confidenceHigh', { percent: confidence.value })
    if (confidence.value >= 60) return label('AccountingView.run.confidenceMid', { percent: confidence.value })
    return label('AccountingView.run.confidenceLow', { percent: confidence.value })
})

const canConfirm = computed(() => {
    if (!current.value || working.value) return false
    if (kind.value === 'bank') return !!current.value.target_type
    return true
})

const confirmLabel = computed(() => kind.value === 'bank'
    ? label('AccountingView.run.confirmMatch')
    : label('AccountingView.run.confirmBooking'))

/**
 * Der Vorschlag als Satz. Die entscheidenden Angaben stehen hervorgehoben —
 * das ersetzt das Formular, ohne etwas zu verschweigen.
 */
const sentence = computed(() => {
    const item = current.value
    if (!item) return ''
    const chip = value => `<span class="chip">${escapeHtml(String(value ?? '—'))}</span>`

    if (kind.value === 'bank') {
        const incoming = Number(item.amount) > 0
        const partner = item.remote_name || t('AccountingView.run.unknownPartner')
        const head = incoming
            ? t('AccountingView.run.sentenceBankIn', {
                partner: chip(partner), amount: chip(money(Math.abs(item.amount))), date: chip(item.transdate_fmt) })
            : t('AccountingView.run.sentenceBankOut', {
                partner: chip(partner), amount: chip(money(Math.abs(item.amount))), date: chip(item.transdate_fmt) })
        if (!item.target_type) return head
        return head + ' ' + t('AccountingView.run.sentenceBankMatch', {
            invoice: chip(item.invnumber), name: chip(item.partner), amount: chip(money(item.candidate_amount))
        })
    }

    const account = item.debit_name
        ? `${item.debit_name} · ${item.debit_account}`
        : (item.debit_account || '—')
    return t('AccountingView.run.sentenceDocument', {
        vendor: chip(item.vendor_name || item.customer_name || t('AccountingView.run.unknownPartner')),
        amount: chip(money(item.amount)),
        rate: chip(rate(item.tax_rate)),
        account: chip(account)
    })
})

function escapeHtml(value) {
    return value.replace(/[&<>"']/g, char => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]
    ))
}

// Der Steuersatz kommt als NUMERIC ("19.00") aus der Datenbank — angezeigt wird
// er ohne ueberfluessige Nachkommastellen.
function rate(value) {
    const number = Number(value ?? 0)
    return (Number.isInteger(number) ? number : Number(number.toFixed(2))) + ' %'
}

function money(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
}

function documentUrl(documentId) {
    return `/api/accounting/?action=getDocumentPdf&document_id=${documentId}`
}

// ── Ablauf ───────────────────────────────────────────────────────────────
async function load() {
    finished.value = false
    index.value = 0
    counters.value = { done: 0, later: 0, failed: 0 }
    laterIds.value = []
    const stack = await fetchStack(kind.value === 'bank' ? 'bank' : 'documents', 200)
    items.value = stack.items || []
    total.value = stack.total || items.value.length
    if (!items.value.length) finished.value = true
}

function next() {
    editing.value = false
    if (index.value + 1 >= items.value.length) {
        finished.value = true
    } else {
        index.value++
    }
}

function move(step) {
    editing.value = false
    const target = index.value + step
    if (target < 0 || target >= items.value.length) return
    index.value = target
}

async function confirm() {
    if (!canConfirm.value) return
    working.value = true
    const item = current.value
    try {
        if (kind.value === 'bank') {
            const result = await matchBankTransaction(item.id, item.target_type, item.target_id)
            if (result.success) {
                counters.value.done++
                lastUndo.value = { kind: 'bank', id: item.id }
                next()
            } else {
                counters.value.failed++
                notify(errorText(result), 'error')
            }
        } else {
            const result = await approveBooking(item.id)
            if (result.success) {
                counters.value.done++
                lastUndo.value = null   // gebucht ist gebucht — Korrektur nur per Storno
                next()
            } else {
                counters.value.failed++
                notify(errorText(result), 'error')
            }
        }
    } finally {
        working.value = false
    }
}

function later() {
    if (!current.value) return
    counters.value.later++
    laterIds.value.push(current.value.id)
    next()
}

async function undo() {
    if (!lastUndo.value) return
    working.value = true
    try {
        const result = await unmatchBankTransaction(lastUndo.value.id)
        if (result.success) {
            counters.value.done = Math.max(counters.value.done - 1, 0)
            notify(t('AccountingView.run.undone'), 'warning')
            lastUndo.value = null
            move(-1)
        } else {
            notify(errorText(result), 'error')
        }
    } finally {
        working.value = false
    }
}

function startEdit() {
    const item = current.value
    if (!item) return
    if (kind.value === 'belege') {
        // debit_account bleibt leer — gesetzt wird nur, was der Anwender waehlt.
        edit.value = {
            debit_account: null,
            debit_name: null,
            description: item.description || '',
            tax_rate: Number(item.tax_rate ?? 19),
            invoice: null
        }
        accountOptions.value = []
        accountSearch.value = ''
    } else {
        edit.value = { debit_account: null, debit_name: null, description: '', tax_rate: 19, invoice: null }
        invoiceOptions.value = []
    }
    editing.value = true
}

async function applyEdit() {
    const item = current.value
    if (!item) return
    working.value = true
    try {
        if (kind.value === 'belege') {
            // Ohne neue Kontowahl bleibt das bisherige Konto stehen.
            const fields = {
                description: edit.value.description,
                tax_rate: edit.value.tax_rate
            }
            if (edit.value.debit_account) fields.debit_account = edit.value.debit_account

            const result = await updateBooking(item.id, fields)
            if (!result.success) {
                notify(errorText(result), 'error')
                return
            }
            Object.assign(item, {
                debit_account: edit.value.debit_account || item.debit_account,
                // Bei der Auswahl gemerkt — nie der alte Name zur neuen Nummer.
                debit_name: edit.value.debit_account ? edit.value.debit_name : item.debit_name,
                description: edit.value.description,
                tax_rate: edit.value.tax_rate
            })
            editing.value = false
            notify(t('AccountingView.run.changed'), 'success')
        } else {
            const invoice = edit.value.invoice
            if (!invoice) return
            Object.assign(item, {
                target_type: invoice.type,
                target_id: invoice.id,
                invnumber: invoice.invnumber,
                partner: invoice.customer_name || invoice.vendor_name,
                candidate_amount: invoice.open_amount,
                candidate_score: null
            })
            editing.value = false
        }
    } finally {
        working.value = false
    }
}

function replayLater() {
    const ids = new Set(laterIds.value)
    items.value = items.value.filter(item => ids.has(item.id))
    laterIds.value = []
    counters.value.later = 0
    index.value = 0
    finished.value = items.value.length === 0
}

function leave() {
    router.push({ name: 'accounting-overview' })
}

function notify(text, color = 'success') {
    toast.value = { show: true, text, color }
}

/**
 * Lesbare Fehlermeldung aus einer API-Antwort.
 *
 * Bei Fehlern steht in `text` nur der Code (etwa API_DATABASE_ERROR) und der
 * verstaendliche Satz in `payload`. Ungefiltert stuende im Balken ein Codewort.
 */
function errorText(result) {
    if (typeof result?.payload === 'string' && result.payload.trim()) return result.payload
    if (result?.text && !/^[A-Z0-9_]+$/.test(result.text)) return result.text
    return t('AccountingView.run.actionFailed')
}

// ── Suchfelder ───────────────────────────────────────────────────────────
/**
 * Die Bezeichnung wird sofort bei der Auswahl gemerkt.
 *
 * Vuetify setzt nach der Auswahl den Suchtext auf den Titel des Eintrags
 * ("Kfz-Reparaturen · 6540"). Die naechste Suche findet dazu nichts und leert
 * die Vorschlagsliste — ein spaeteres Nachschlagen darin ginge also ins Leere.
 */
function onAccountPicked(accno) {
    const option = accountOptions.value.find(entry => String(entry.accno) === String(accno))
    edit.value.debit_name = option ? option.name : null
}

let accountTimer = null
function onAccountSearch(query) {
    clearTimeout(accountTimer)
    if (!query || query.length < 2) return
    accountLoading.value = true
    accountTimer = setTimeout(async () => {
        const rows = await searchAccounts(query)
        accountOptions.value = rows.map(row => ({
            accno: String(row.accno),
            name: row.description || '',
            label: `${row.description || ''} · ${row.accno}`.trim()
        }))
        accountLoading.value = false
    }, 220)
}

let invoiceTimer = null
function onInvoiceSearch(query) {
    clearTimeout(invoiceTimer)
    if (!query || query.length < 2) return
    invoiceLoading.value = true
    invoiceTimer = setTimeout(async () => {
        const type = Number(current.value?.amount) > 0 ? 'ar' : 'ap'
        const rows = await searchOpenInvoices(query, type)
        invoiceOptions.value = rows.map(row => ({
            ...row,
            _label: `${row.invnumber} · ${row.customer_name || row.vendor_name || ''} · ${money(row.open_amount)}`
        }))
        invoiceLoading.value = false
    }, 220)
}

// ── Tastatur ─────────────────────────────────────────────────────────────
function onKeydown(event) {
    if (event.ctrlKey || event.metaKey || event.altKey) return
    const tag = (event.target?.tagName || '').toLowerCase()
    const typing = tag === 'input' || tag === 'textarea' || event.target?.isContentEditable
    if (event.key === 'Escape') { editing.value ? (editing.value = false) : leave(); return }
    if (typing) return

    switch (event.key.toLowerCase()) {
        case 'enter': event.preventDefault(); confirm(); break
        case 'e': event.preventDefault(); startEdit(); break
        case 's': event.preventDefault(); later(); break
        case 'z': if (lastUndo.value) { event.preventDefault(); undo() } break
        case 'arrowleft': event.preventDefault(); move(-1); break
        case 'arrowright': event.preventDefault(); move(1); break
    }
}

watch(kind, load)
onMounted(() => { load(); window.addEventListener('keydown', onKeydown) })
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown)
    clearTimeout(accountTimer)
    clearTimeout(invoiceTimer)
})
</script>

<style scoped>
.run { max-width: 1500px; }

.source__meta {
    font-size: .7rem; letter-spacing: .05em; text-transform: uppercase; opacity: .6;
}
.source__frame {
    width: 100%; height: 62vh; min-height: 380px; border: 0;
    background: rgba(var(--v-theme-on-surface), .04); border-radius: 4px;
}
.source__purpose {
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
    font-size: .82rem; line-height: 1.6; word-break: break-word;
}

.sentence { font-size: 1.05rem; line-height: 2; }
.sentence :deep(.chip) {
    background: rgba(var(--v-theme-primary), .12);
    border-bottom: 1.5px dotted rgb(var(--v-theme-primary));
    padding: .1rem .35rem; border-radius: 3px; font-weight: 500; white-space: nowrap;
}

.edit {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    background: rgba(var(--v-theme-on-surface), .03);
}

kbd {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 3px; padding: 0 .3em; font-size: .78em;
}
</style>
