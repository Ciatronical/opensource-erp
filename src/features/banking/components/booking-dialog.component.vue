<template>
    <v-dialog v-model="show" max-width="620" scrollable @after-leave="onClosed">
        <v-card :loading="loading">
            <!-- Header -->
            <v-card-title class="d-flex align-center py-3">
                <v-icon start>mdi-bank-outline</v-icon>
                {{ t('BankingView.booking.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" @click="close" />
            </v-card-title>

            <!-- Umsatz-Übersicht -->
            <v-card-text class="pb-0">
                <v-sheet color="grey-lighten-4" rounded="lg" class="pa-3 mb-4">
                    <div class="d-flex align-center mb-1">
                        <span
                            class="text-h5 font-weight-bold"
                            :class="transaction.amount >= 0 ? 'text-success' : 'text-error'"
                        >
                            {{ formatCurrency(transaction.amount) }}
                        </span>
                        <v-spacer />
                        <span class="text-body-2 text-medium-emphasis">{{ formatDate(transaction.transdate) }}</span>
                    </div>
                    <div class="font-weight-medium">{{ transaction.remote_name || '—' }}</div>
                    <div v-if="transaction.remote_iban" class="text-caption text-medium-emphasis">
                        {{ formatIban(transaction.remote_iban) }}
                    </div>
                    <div v-if="transaction.purpose" class="text-body-2 mt-1 text-medium-emphasis">
                        {{ transaction.purpose }}
                    </div>
                </v-sheet>

                <!-- Bereits zugeordnet: Buchung bestätigen oder Zuordnung ändern -->
                <template v-if="transaction.match_status === 'matched' && currentMatch && !changingAssignment">
                    <div class="text-overline text-medium-emphasis mb-2">{{ t('BankingView.booking.currentMatch') }}</div>
                    <v-card variant="tonal" color="info" class="mb-4" rounded="lg">
                        <v-card-text>
                            <div class="d-flex align-center">
                                <div>
                                    <div class="font-weight-bold text-body-1">{{ currentMatch.invnumber }}</div>
                                    <div class="text-body-2">{{ currentMatch.contact_name }}</div>
                                </div>
                                <v-spacer />
                                <div class="text-right">
                                    <div class="font-weight-bold text-success">{{ formatCurrency(currentMatch.open_amount) }}</div>
                                    <div class="text-caption text-medium-emphasis">{{ t('BankingView.booking.openAmount') }}</div>
                                </div>
                            </div>
                            <div class="mt-3 d-flex ga-2 flex-wrap">
                                <v-btn color="success" variant="elevated" :loading="booking" @click="bookCurrent">
                                    <v-icon start>mdi-check-circle</v-icon>
                                    {{ t('BankingView.booking.actionBook') }}
                                </v-btn>
                                <v-btn variant="tonal" @click="changingAssignment = true">
                                    <v-icon start>mdi-swap-horizontal</v-icon>
                                    {{ t('BankingView.booking.actionChangeAssignment') }}
                                </v-btn>
                            </div>
                        </v-card-text>
                    </v-card>
                </template>

                <!-- Sammelzahlung erkannt: mehrere Rechnungen, Summe = Betrag -->
                <template v-else-if="!loading">
                    <template v-if="suggestedGroup">
                        <div class="d-flex align-center mb-2">
                            <div class="text-overline text-medium-emphasis">{{ t('BankingView.booking.groupPayment') }}</div>
                            <v-spacer />
                            <v-chip color="success" size="small" variant="tonal">
                                {{ Math.round(suggestedGroup.confidence * 100) }}%
                            </v-chip>
                        </div>
                        <v-card variant="tonal" color="success" class="mb-4" rounded="lg">
                            <v-card-text>
                                <v-list density="compact" class="pa-0 bg-transparent">
                                    <v-list-item
                                        v-for="inv in suggestedGroup.invoices"
                                        :key="inv.ar_id"
                                        class="px-0"
                                    >
                                        <v-list-item-title class="d-flex align-center">
                                            <span class="font-weight-bold">{{ inv.invnumber }}</span>
                                            <span class="text-body-2 text-medium-emphasis ml-2">{{ inv.contact_name }}</span>
                                            <v-spacer />
                                            <span class="font-weight-medium">{{ formatCurrency(inv.open_amount) }}</span>
                                        </v-list-item-title>
                                    </v-list-item>
                                </v-list>
                                <v-divider class="my-2" />
                                <div class="d-flex align-center text-body-2 font-weight-bold mb-3">
                                    <span>{{ t('BankingView.booking.groupTotal') }}</span>
                                    <v-spacer />
                                    <span class="text-success">{{ formatCurrency(suggestedGroup.total_amount) }}</span>
                                </div>
                                <v-btn
                                    color="success"
                                    variant="elevated"
                                    block
                                    :loading="booking"
                                    @click="bookGroup"
                                >
                                    <v-icon start>mdi-check-all</v-icon>
                                    {{ t('BankingView.booking.actionBookGroup', { count: suggestedGroup.invoices.length }) }}
                                </v-btn>
                            </v-card-text>
                        </v-card>
                    </template>

                    <!-- Einzelne Empfehlung (höchste Konfidenz ≥ 0.88) -->
                    <template v-if="topRecommendation">
                        <div class="d-flex align-center mb-2">
                            <div class="text-overline text-medium-emphasis">{{ t('BankingView.booking.recommendation') }}</div>
                            <v-spacer />
                            <v-chip :color="confidenceColor(topRecommendation.confidence)" size="small" variant="tonal">
                                {{ Math.round(topRecommendation.confidence * 100) }}%
                            </v-chip>
                        </div>
                        <v-card
                            variant="tonal"
                            :color="confidenceColor(topRecommendation.confidence)"
                            class="mb-4"
                            rounded="lg"
                        >
                            <v-card-text>
                                <div class="d-flex align-center">
                                    <div>
                                        <div class="font-weight-bold text-body-1">{{ topRecommendation.invnumber }}</div>
                                        <div class="text-body-2">{{ topRecommendation.contact_name }}</div>
                                        <div class="text-caption text-medium-emphasis">
                                            {{ matchTypeLabel(topRecommendation.match_type) }}
                                        </div>
                                    </div>
                                    <v-spacer />
                                    <div class="text-right">
                                        <div class="font-weight-bold text-success">{{ formatCurrency(topRecommendation.open_amount) }}</div>
                                        <div class="text-caption text-medium-emphasis">
                                            {{ t('BankingView.booking.dueDate') }}: {{ formatDate(topRecommendation.duedate) }}
                                        </div>
                                    </div>
                                </div>
                                <v-btn
                                    color="success"
                                    variant="elevated"
                                    block
                                    class="mt-3"
                                    :loading="booking"
                                    @click="bookNow(topRecommendation)"
                                >
                                    <v-icon start>mdi-check-circle</v-icon>
                                    {{ t('BankingView.booking.actionBookNow') }}
                                </v-btn>
                            </v-card-text>
                        </v-card>
                    </template>

                    <!-- Keine Empfehlung und keine Gruppe -->
                    <template v-else-if="!suggestedGroup && candidates.length === 0">
                        <v-alert type="warning" variant="tonal" class="mb-4" rounded="lg">
                            <div class="font-weight-bold">{{ t('BankingView.booking.noCandidates') }}</div>
                            <div class="text-body-2 mt-1">{{ t('BankingView.booking.noCandidatesHint') }}</div>
                            <v-btn
                                class="mt-3"
                                size="small"
                                color="primary"
                                variant="flat"
                                prepend-icon="mdi-credit-card-sync-outline"
                                @click="openSettlement"
                            >
                                {{ t('BankingView.settlement.assign') }}
                            </v-btn>
                        </v-alert>
                    </template>

                    <!-- Weitere Kandidaten (unterhalb der Empfehlung oder wenn keine Empfehlung) -->
                    <template v-if="otherCandidates.length > 0">
                        <div class="text-overline text-medium-emphasis mb-2">
                            {{ topRecommendation ? t('BankingView.booking.otherCandidates') : t('BankingView.booking.candidates') }}
                        </div>
                        <v-card variant="outlined" class="mb-4" rounded="lg">
                            <v-list density="compact">
                                <v-list-item
                                    v-for="(c, i) in otherCandidates"
                                    :key="c.target_id"
                                    :divider="i < otherCandidates.length - 1"
                                >
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-medium">{{ c.invnumber }}</span>
                                        <span class="text-body-2 text-medium-emphasis ml-2">{{ c.contact_name }}</span>
                                        <v-spacer />
                                        <span class="font-weight-medium">{{ formatCurrency(c.open_amount) }}</span>
                                    </v-list-item-title>
                                    <v-list-item-subtitle class="d-flex align-center mt-1">
                                        <v-chip :color="confidenceColor(c.confidence)" size="x-small" variant="tonal" class="mr-2">
                                            {{ Math.round(c.confidence * 100) }}%
                                        </v-chip>
                                        {{ matchTypeLabel(c.match_type) }}
                                        <v-spacer />
                                        <span class="text-caption text-medium-emphasis">
                                            {{ t('BankingView.booking.dueDate') }}: {{ formatDate(c.duedate) }}
                                        </span>
                                    </v-list-item-subtitle>
                                    <template #append>
                                        <div class="d-flex ga-1 ml-2">
                                            <v-btn
                                                size="small"
                                                color="success"
                                                variant="tonal"
                                                :loading="booking"
                                                @click="bookNow(c)"
                                            >
                                                {{ t('BankingView.booking.actionBookNow') }}
                                            </v-btn>
                                            <v-btn
                                                size="small"
                                                variant="text"
                                                @click="assignOnly(c)"
                                            >
                                                {{ t('BankingView.booking.actionAssignOnly') }}
                                            </v-btn>
                                        </div>
                                    </template>
                                </v-list-item>
                            </v-list>
                        </v-card>
                    </template>
                </template>

                <!-- Manuelle Suche -->
                <div class="text-overline text-medium-emphasis mb-2">{{ t('BankingView.booking.manualSearch') }}</div>
                <v-text-field
                    v-model="manualSearch"
                    :placeholder="t('BankingView.booking.searchPlaceholder')"
                    prepend-inner-icon="mdi-magnify"
                    density="compact"
                    hide-details
                    clearable
                    class="mb-2"
                    @input="onSearchInput"
                />
                <v-card v-if="searchResults.length > 0" variant="outlined" class="mb-3" rounded="lg">
                    <v-list density="compact">
                        <v-list-item
                            v-for="(inv, i) in searchResults"
                            :key="'s-' + inv.id"
                            :divider="i < searchResults.length - 1"
                        >
                            <v-list-item-title class="d-flex align-center">
                                <span class="font-weight-medium">{{ inv.invnumber }}</span>
                                <span class="text-body-2 text-medium-emphasis ml-2">{{ inv.customer_name }}</span>
                                <v-spacer />
                                <span class="font-weight-medium">{{ formatCurrency(inv.open_amount) }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                {{ t('BankingView.booking.dueDate') }}: {{ formatDate(inv.duedate) }}
                            </v-list-item-subtitle>
                            <template #append>
                                <div class="d-flex ga-1 ml-2">
                                    <v-btn
                                        size="small"
                                        color="success"
                                        variant="tonal"
                                        :loading="booking"
                                        @click="bookNow({ target_type: 'ar', target_id: inv.id, open_amount: inv.open_amount })"
                                    >
                                        {{ t('BankingView.booking.actionBookNow') }}
                                    </v-btn>
                                    <v-btn
                                        size="small"
                                        variant="text"
                                        @click="assignOnly({ target_type: 'ar', target_id: inv.id })"
                                    >
                                        {{ t('BankingView.booking.actionAssignOnly') }}
                                    </v-btn>
                                </div>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
                <div v-else-if="manualSearch && !searchLoading" class="text-caption text-medium-emphasis mb-3">
                    {{ t('BankingView.booking.noSearchResults') }}
                </div>
            </v-card-text>

            <!-- Aktions-Footer -->
            <v-divider />
            <v-card-actions class="pa-3">
                <v-btn
                    prepend-icon="mdi-robot-outline"
                    color="secondary"
                    variant="tonal"
                    size="small"
                    @click="weroni"
                >
                    Weroni
                </v-btn>
                <v-btn
                    prepend-icon="mdi-credit-card-sync-outline"
                    color="primary"
                    variant="tonal"
                    size="small"
                    @click="openSettlement"
                >
                    {{ t('BankingView.settlement.assign') }}
                </v-btn>
                <v-btn
                    v-if="transaction.match_status !== 'ignored'"
                    variant="text"
                    size="small"
                    @click="ignore"
                >
                    {{ t('BankingView.booking.actionIgnore') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" @click="close">{{ t('BankingView.booking.cancel') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMatching } from '../composables/useMatching.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    transaction: { type: Object, required: true },
    accountId: { type: Number, required: true }
})

const emit = defineEmits(['update:modelValue', 'done', 'settlement'])

const { t } = useI18n()
const matching = useMatching()

const loading = ref(false)
const booking = ref(false)
const candidates = ref([])
const currentMatch = ref(null)
const suggestedGroup = ref(null)
const changingAssignment = ref(false)
const manualSearch = ref('')
const searchResults = ref([])
const searchLoading = ref(false)
let searchTimer = null

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v)
})

const topRecommendation = computed(() => {
    return candidates.value.find(c => c.confidence >= 0.88) ?? null
})

const otherCandidates = computed(() => {
    if (!topRecommendation.value) return candidates.value
    return candidates.value.filter(c => c.target_id !== topRecommendation.value.target_id)
})

// Beim ersten Mount mit v-if neu gerendert — onMounted lädt direkt
onMounted(async () => {
    if (props.modelValue) {
        await loadCandidates()
    }
})

// Für Re-Öffnung ohne v-if-Remount
watch(() => props.modelValue, async (open) => {
    if (open) {
        changingAssignment.value = false
        manualSearch.value = ''
        searchResults.value = []
        await loadCandidates()
    }
})

async function loadCandidates() {
    loading.value = true
    candidates.value = []
    currentMatch.value = null
    try {
        const payload = await matching.fetchMatchCandidates(props.transaction.id)
        candidates.value = payload.candidates || []
        currentMatch.value = payload.current_match || null
        suggestedGroup.value = payload.suggested_group || null
    } catch (e) {
        alerts.error(e.message)
    } finally {
        loading.value = false
    }
}

/**
 * Wirft einen sichtbaren Fehler, wenn die Buchung serverseitig nichts gebucht
 * hat (booked_count === 0) oder Fehler zurückkam. Das Backend liefert in diesen
 * Fällen success=true mit errors[] — ohne diese Prüfung bliebe der Fehlschlag
 * still und es würde fälschlich "Erfolg" gemeldet.
 */
function assertBooked(result) {
    const errs = result?.errors
    if (Array.isArray(errs) && errs.length) {
        throw new Error(errs.join('\n'))
    }
    if (typeof result?.booked_count === 'number' && result.booked_count === 0) {
        throw new Error(t('BankingView.booking.nothingBooked'))
    }
}

async function bookNow(candidate) {
    const txAmount = Math.abs(parseFloat(props.transaction.amount) || 0)
    const invAmount = Math.abs(parseFloat(candidate.open_amount) || 0)
    if (Math.abs(txAmount - invAmount) > 0.01) {
        const res = await alerts.warning(
            t('BankingView.booking.amountMismatchWarning', {
                invAmount: formatCurrency(candidate.open_amount),
                txAmount: formatCurrency(props.transaction.amount)
            }),
            t('BankingView.booking.amountMismatchTitle'),
            t('BankingView.booking.actionBook'),
            t('BankingView.booking.cancel')
        )
        if (!res.isConfirmed) return
    }
    booking.value = true
    try {
        await matching.matchTransaction(props.transaction.id, candidate.target_type, candidate.target_id)
        const result = await matching.bookMatchedTransactions([props.transaction.id], props.accountId)
        assertBooked(result)
        alerts.success(t('BankingView.booking.bookSuccess'))
        emit('done')
        close()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        booking.value = false
    }
}

async function assignOnly(candidate) {
    try {
        await matching.matchTransaction(props.transaction.id, candidate.target_type, candidate.target_id)
        alerts.success(t('BankingView.booking.assignSuccess'))
        emit('done')
        close()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function bookGroup() {
    if (!suggestedGroup.value) return
    booking.value = true
    try {
        const result = await matching.bookTransactionMultiple(
            props.transaction.id,
            suggestedGroup.value.target_ids,
            props.accountId
        )
        assertBooked(result)
        alerts.success(t('BankingView.booking.bookSuccess'))
        emit('done')
        close()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        booking.value = false
    }
}

async function bookCurrent() {
    booking.value = true
    try {
        const result = await matching.bookMatchedTransactions([props.transaction.id], props.accountId)
        assertBooked(result)
        alerts.success(t('BankingView.booking.bookSuccess'))
        emit('done')
        close()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        booking.value = false
    }
}

async function ignore() {
    try {
        // Direkt über banking-composable — wird vom Parent per 'done' neu geladen
        emit('ignore', props.transaction.id)
        close()
    } catch (e) {
        alerts.error(e.message)
    }
}

function onSearchInput() {
    clearTimeout(searchTimer)
    if (!manualSearch.value || manualSearch.value.trim().length < 2) {
        searchResults.value = []
        return
    }
    searchTimer = setTimeout(() => doSearch(manualSearch.value.trim()), 350)
}

async function doSearch(term) {
    searchLoading.value = true
    try {
        await matching.fetchOpenInvoices('ar', term)
        searchResults.value = matching.openInvoices.value.filter(inv => inv.type === 'ar')
    } finally {
        searchLoading.value = false
    }
}

function weroni() {
    alerts.info(t('BankingView.booking.weroniSoon'))
}

// Kartenabrechnung (Flatpay/Rapyd): an den Hub melden, der den Settlement-Dialog
// mit demselben Umsatz oeffnet.
function openSettlement() {
    emit('settlement', props.transaction)
    show.value = false
}

function close() {
    show.value = false
}

function onClosed() {
    candidates.value = []
    currentMatch.value = null
    suggestedGroup.value = null
    manualSearch.value = ''
    searchResults.value = []
    changingAssignment.value = false
}

function matchTypeLabel(type) {
    const key = `BankingView.booking.matchType_${type}`
    const label = t(key)
    return label === key ? type : label
}

function confidenceColor(confidence) {
    if (confidence >= 0.95) return 'success'
    if (confidence >= 0.88) return 'primary'
    if (confidence >= 0.70) return 'warning'
    return 'default'
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function formatDate(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('de-DE')
}

function formatIban(iban) {
    if (!iban) return '—'
    return iban.replace(/(.{4})/g, '$1 ').trim()
}
</script>
