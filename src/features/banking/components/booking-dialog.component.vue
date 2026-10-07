<template>
    <v-dialog v-model="show" max-width="660" scrollable @after-leave="onClosed">
        <v-card :loading="loading">
            <!-- Header -->
            <v-card-title class="d-flex align-center py-3">
                <v-icon start>{{ isOutgoing ? 'mdi-bank-transfer-out' : 'mdi-bank-transfer-in' }}</v-icon>
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
                    <!-- Erkannter Kontakt: auch ohne offenen Beleg weiß der Dialog, wer zahlt -->
                    <div v-if="contact" class="d-flex align-center flex-wrap ga-2 mt-2">
                        <v-chip size="small" color="primary" variant="tonal" :title="contactTitle">
                            <v-icon start size="small">{{ contact.type === 'vendor' ? 'mdi-domain' : 'mdi-account' }}</v-icon>
                            {{ contact.name }}<span v-if="contact.number" class="text-medium-emphasis ml-1">({{ contact.number }})</span>
                        </v-chip>
                        <span class="text-caption text-medium-emphasis">
                            {{ t('BankingView.booking.contactRecognized', { source: contactSourceLabel(contact) }) }}
                        </span>
                    </div>
                </v-sheet>

                <!-- Sammelbuchung: vom Nutzer zusammengestellte Belege -->
                <template v-if="selected.length > 0">
                    <div class="d-flex align-center mb-2">
                        <div class="text-overline text-medium-emphasis">{{ t('BankingView.booking.selection') }}</div>
                        <v-spacer />
                        <v-chip :color="selectionBalanced ? 'success' : 'warning'" size="small" variant="tonal">
                            <v-icon start size="small">{{ selectionBalanced ? 'mdi-check' : 'mdi-scale-unbalanced' }}</v-icon>
                            {{ selectionStatusText }}
                        </v-chip>
                    </div>
                    <v-card variant="tonal" :color="selectionBalanced ? 'success' : 'warning'" class="mb-4" rounded="lg">
                        <v-card-text>
                            <v-list density="compact" class="pa-0 bg-transparent">
                                <v-list-item v-for="doc in selected" :key="docKey(doc)" class="px-0">
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-bold">{{ doc.invnumber }}</span>
                                        <v-chip v-if="doc.is_credit_note" size="x-small" color="info" variant="flat" class="ml-2 flex-shrink-0">
                                            {{ t('BankingView.booking.creditNote') }}
                                        </v-chip>
                                        <span class="text-body-2 text-medium-emphasis ml-2 text-truncate flex-grow-1 doc-contact">{{ doc.contact_name }}</span>
                                        <span class="font-weight-medium flex-shrink-0" :class="doc.is_credit_note ? 'text-info' : ''">
                                            {{ formatCurrency(doc.open_amount) }}
                                        </span>
                                        <v-btn
                                            icon="mdi-close"
                                            size="x-small"
                                            variant="text"
                                            class="ml-1"
                                            :title="t('BankingView.booking.actionRemoveFromSelection')"
                                            @click="toggleSelect(doc)"
                                        />
                                    </v-list-item-title>
                                </v-list-item>
                            </v-list>
                            <v-divider class="my-2" />
                            <div class="d-flex align-center text-body-2 mb-1">
                                <span>{{ t('BankingView.booking.selectionTotal') }}</span>
                                <v-spacer />
                                <span class="font-weight-bold">{{ formatCurrency(selectionDocTotal) }}</span>
                            </div>
                            <div class="d-flex align-center text-body-2 mb-3">
                                <span>{{ t('BankingView.booking.selectionDiff') }}</span>
                                <v-spacer />
                                <span class="font-weight-bold" :class="selectionBalanced ? 'text-success' : 'text-warning'">
                                    {{ formatCurrency(selectionRemaining) }}
                                </span>
                            </div>
                            <v-btn
                                color="success"
                                variant="elevated"
                                block
                                :disabled="!selectionBalanced"
                                :loading="booking"
                                @click="bookSelection"
                            >
                                <v-icon start>mdi-check-all</v-icon>
                                {{ t('BankingView.booking.actionBookSelection', { count: selected.length }) }}
                            </v-btn>
                            <div v-if="!selectionBalanced" class="text-caption text-medium-emphasis mt-2">
                                {{ t('BankingView.booking.selectionHint') }}
                            </div>
                        </v-card-text>
                    </v-card>
                </template>

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

                <template v-else-if="!loading">
                    <!-- Sammelzahlung erkannt: mehrere Belege, Summe = Betrag -->
                    <template v-if="suggestedGroup && selected.length === 0">
                        <div class="d-flex align-center mb-2">
                            <div class="text-overline text-medium-emphasis">{{ t('BankingView.booking.groupPayment') }}</div>
                            <v-spacer />
                            <v-chip color="success" size="small" variant="tonal">
                                {{ Math.round(suggestedGroup.confidence * 100) }}%
                            </v-chip>
                        </div>
                        <v-card variant="tonal" color="success" class="mb-4" rounded="lg">
                            <v-card-text>
                                <div class="text-caption text-medium-emphasis mb-2">
                                    {{ suggestedGroup.match_type === 'subset_sum'
                                        ? t('BankingView.booking.groupPaymentSubset')
                                        : t('BankingView.booking.groupPaymentPurpose') }}
                                </div>
                                <v-list density="compact" class="pa-0 bg-transparent">
                                    <v-list-item
                                        v-for="inv in suggestedGroup.invoices"
                                        :key="docKey(inv)"
                                        class="px-0"
                                    >
                                        <v-list-item-title class="d-flex align-center">
                                            <span class="font-weight-bold">{{ inv.invnumber }}</span>
                                            <v-chip v-if="inv.is_credit_note" size="x-small" color="info" variant="flat" class="ml-2 flex-shrink-0">
                                                {{ t('BankingView.booking.creditNote') }}
                                            </v-chip>
                                            <span class="text-body-2 text-medium-emphasis ml-2 text-truncate flex-grow-1 doc-contact">{{ inv.contact_name }}</span>
                                            <span class="font-weight-medium flex-shrink-0">{{ formatCurrency(inv.open_amount) }}</span>
                                        </v-list-item-title>
                                    </v-list-item>
                                </v-list>
                                <v-divider class="my-2" />
                                <div class="d-flex align-center text-body-2 font-weight-bold mb-3">
                                    <span>{{ t('BankingView.booking.groupTotal') }}</span>
                                    <v-spacer />
                                    <span class="text-success">{{ formatCurrency(suggestedGroup.total_amount) }}</span>
                                </div>
                                <div class="d-flex ga-2 flex-wrap">
                                    <v-btn
                                        color="success"
                                        variant="elevated"
                                        class="flex-grow-1"
                                        :loading="booking"
                                        @click="bookGroup"
                                    >
                                        <v-icon start>mdi-check-all</v-icon>
                                        {{ t('BankingView.booking.actionBookGroup', { count: suggestedGroup.invoices.length }) }}
                                    </v-btn>
                                    <v-btn variant="tonal" :title="t('BankingView.booking.actionEditGroup')" @click="editGroup">
                                        <v-icon start>mdi-playlist-edit</v-icon>
                                        {{ t('BankingView.booking.actionEditGroup') }}
                                    </v-btn>
                                </div>
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
                                        <div class="font-weight-bold text-body-1">
                                            {{ topRecommendation.invnumber }}
                                            <v-chip v-if="topRecommendation.is_credit_note" size="x-small" color="info" variant="flat" class="ml-1">
                                                {{ t('BankingView.booking.creditNote') }}
                                            </v-chip>
                                        </div>
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
                                <div class="d-flex ga-2 mt-3">
                                    <v-btn
                                        color="success"
                                        variant="elevated"
                                        class="flex-grow-1"
                                        :loading="booking"
                                        @click="bookNow(topRecommendation)"
                                    >
                                        <v-icon start>mdi-check-circle</v-icon>
                                        {{ t('BankingView.booking.actionBookNow') }}
                                    </v-btn>
                                    <v-btn
                                        :icon="isSelected(topRecommendation) ? 'mdi-checkbox-marked' : 'mdi-plus-box-outline'"
                                        :color="isSelected(topRecommendation) ? 'success' : undefined"
                                        variant="tonal"
                                        :title="t('BankingView.booking.actionAddToSelection')"
                                        @click="toggleSelect(topRecommendation)"
                                    />
                                </div>
                            </v-card-text>
                        </v-card>
                    </template>

                    <!-- Keine Empfehlung und keine Gruppe -->
                    <template v-else-if="!suggestedGroup && candidates.length === 0">
                        <v-alert type="warning" variant="tonal" class="mb-4" rounded="lg">
                            <div class="font-weight-bold">{{ t('BankingView.booking.noCandidates') }}</div>
                            <div class="text-body-2 mt-1">
                                <template v-if="contact">{{ t('BankingView.booking.contactNoOpenDocs') }} </template>
                                {{ t('BankingView.booking.noCandidatesHint') }}
                            </div>
                            <div class="d-flex ga-2 flex-wrap mt-3">
                                <v-btn
                                    v-if="isOutgoing"
                                    size="small"
                                    color="primary"
                                    variant="flat"
                                    prepend-icon="mdi-file-document-plus"
                                    @click="createAp"
                                >
                                    {{ createApLabel }}
                                </v-btn>
                                <v-btn
                                    v-else
                                    size="small"
                                    color="primary"
                                    variant="flat"
                                    prepend-icon="mdi-credit-card-sync-outline"
                                    @click="openSettlement"
                                >
                                    {{ t('BankingView.settlement.assign') }}
                                </v-btn>
                            </div>
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
                                    :key="docKey(c)"
                                    :divider="i < otherCandidates.length - 1"
                                    :class="{ 'bg-green-lighten-5': isSelected(c) }"
                                >
                                    <template #prepend>
                                        <v-checkbox-btn
                                            :model-value="isSelected(c)"
                                            color="success"
                                            density="compact"
                                            :title="t('BankingView.booking.actionAddToSelection')"
                                            @update:model-value="toggleSelect(c)"
                                        />
                                    </template>
                                    <v-list-item-title class="d-flex align-center">
                                        <span class="font-weight-medium">{{ c.invnumber }}</span>
                                        <v-chip v-if="c.is_credit_note" size="x-small" color="info" variant="flat" class="ml-2 flex-shrink-0">
                                            {{ t('BankingView.booking.creditNote') }}
                                        </v-chip>
                                        <span class="text-body-2 text-medium-emphasis ml-2 text-truncate flex-grow-1 doc-contact">{{ c.contact_name }}</span>
                                        <span class="font-weight-medium flex-shrink-0" :class="c.is_credit_note ? 'text-info' : ''">
                                            {{ formatCurrency(c.open_amount) }}
                                        </span>
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

                <!-- Belege direkt am Umsatz: Gebühren, Verträge, Kontoauszugsseiten —
                     mehrere, unabhängig von der Zuordnung -->
                <TransactionDocuments
                    :transaction-id="transaction.id"
                    @changed="n => emit('documents', transaction.id, n)"
                />

                <!-- Manuelle Suche -->
                <div class="d-flex align-center mb-2">
                    <div class="text-overline text-medium-emphasis">{{ t('BankingView.booking.manualSearch') }}</div>
                    <v-spacer />
                    <v-btn-toggle v-model="searchType" density="compact" variant="outlined" divided mandatory>
                        <v-btn value="ap" size="x-small">{{ t('BankingView.booking.searchTypeAp') }}</v-btn>
                        <v-btn value="ar" size="x-small">{{ t('BankingView.booking.searchTypeAr') }}</v-btn>
                    </v-btn-toggle>
                </div>
                <v-text-field
                    v-model="manualSearch"
                    :placeholder="searchType === 'ap'
                        ? t('BankingView.booking.searchPlaceholderAp')
                        : t('BankingView.booking.searchPlaceholder')"
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
                            :key="'s-' + docKey(inv)"
                            :divider="i < searchResults.length - 1"
                            :class="{ 'bg-green-lighten-5': isSelected(inv) }"
                        >
                            <template #prepend>
                                <v-checkbox-btn
                                    :model-value="isSelected(inv)"
                                    color="success"
                                    density="compact"
                                    :title="t('BankingView.booking.actionAddToSelection')"
                                    @update:model-value="toggleSelect(inv)"
                                />
                            </template>
                            <v-list-item-title class="d-flex align-center">
                                <span class="font-weight-medium">{{ inv.invnumber }}</span>
                                <v-chip v-if="inv.is_credit_note" size="x-small" color="info" variant="flat" class="ml-2 flex-shrink-0">
                                    {{ t('BankingView.booking.creditNote') }}
                                </v-chip>
                                <span class="text-body-2 text-medium-emphasis ml-2 text-truncate flex-grow-1 doc-contact">{{ inv.contact_name }}</span>
                                <span class="font-weight-medium flex-shrink-0" :class="inv.is_credit_note ? 'text-info' : ''">
                                    {{ formatCurrency(inv.open_amount) }}
                                </span>
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
                                        @click="bookNow(inv)"
                                    >
                                        {{ t('BankingView.booking.actionBookNow') }}
                                    </v-btn>
                                    <v-btn
                                        size="small"
                                        variant="text"
                                        @click="assignOnly(inv)"
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
            <v-card-actions class="pa-3 flex-wrap">
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
                    v-if="isOutgoing"
                    prepend-icon="mdi-file-document-plus"
                    color="primary"
                    variant="tonal"
                    size="small"
                    @click="createAp"
                >
                    {{ createApLabel }}
                </v-btn>
                <v-btn
                    v-else
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
import TransactionDocuments from './transaction-documents.component.vue'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    transaction: { type: Object, required: true },
    accountId: { type: Number, required: true }
})

const emit = defineEmits(['update:modelValue', 'done', 'ignore', 'settlement', 'createAp', 'documents'])

const { t } = useI18n()
const matching = useMatching()

const loading = ref(false)
const booking = ref(false)
const candidates = ref([])
const currentMatch = ref(null)
const suggestedGroup = ref(null)
// Erkannter Lieferant/Kunde ({type, id, name, number, iban, source, hits}) —
// unabhängig von offenen Belegen, siehe _bt_contactSuggestion im Backend
const contact = ref(null)
const changingAssignment = ref(false)
const manualSearch = ref('')
const searchResults = ref([])
const searchLoading = ref(false)
const searchType = ref('ar')
// Sammelbuchung: vom Nutzer gewählte Belege ({target_type, target_id, invnumber,
// contact_name, open_amount, is_credit_note})
const selected = ref([])
let searchTimer = null

const show = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v)
})

// Geldausgang → Eingangsrechnungen des Lieferanten, Geldeingang → Ausgangsrechnungen
const isOutgoing = computed(() => parseFloat(props.transaction.amount) < 0)

// „Eingangsrechnung für XYZ anlegen", sobald der Lieferant erkannt ist
const createApLabel = computed(() =>
    contact.value?.type === 'vendor'
        ? t('BankingView.booking.actionCreateApFor', { name: contact.value.name })
        : t('BankingView.booking.actionCreateAp')
)

const contactTitle = computed(() => {
    if (!contact.value) return ''
    const role = t(contact.value.type === 'vendor' ? 'BankingView.booking.contactVendor' : 'BankingView.booking.contactCustomer')
    return `${role} · ${t('BankingView.booking.contactRecognized', { source: contactSourceLabel(contact.value) })}`
})

const topRecommendation = computed(() => {
    return candidates.value.find(c => c.confidence >= 0.88) ?? null
})

const otherCandidates = computed(() => {
    if (!topRecommendation.value) return candidates.value
    return candidates.value.filter(c => docKey(c) !== docKey(topRecommendation.value))
})

// Erwartete Bankbewegung der Auswahl: Ausgangsrechnung bringt Geld (+offen),
// Eingangsrechnung kostet Geld (−offen); Gutschriften haben negativen offenen
// Betrag und drehen sich dadurch automatisch um.
const selectionBank = computed(() =>
    selected.value.reduce((sum, d) => sum + bankEffect(d), 0)
)
// Anzeige aus Nutzersicht: Belegsumme und Rest in Belegrichtung (ohne das
// Bank-Vorzeichen), damit bei einer Lastschrift "Belegsumme 70 €, noch 250,50 €
// offen" steht und nicht "-70 € / -250,50 €".
const selectionDocTotal = computed(() =>
    Math.round((isOutgoing.value ? -selectionBank.value : selectionBank.value) * 100) / 100
)
const selectionRemaining = computed(() =>
    Math.round((Math.abs(parseFloat(props.transaction.amount) || 0) - selectionDocTotal.value) * 100) / 100
)
const selectionBalanced = computed(() => Math.abs(selectionRemaining.value) < 0.01)
const selectionStatusText = computed(() => {
    if (selectionBalanced.value) return t('BankingView.booking.selectionMatches')
    if (selectionRemaining.value > 0) {
        return t('BankingView.booking.selectionRemaining', { amount: formatCurrency(selectionRemaining.value) })
    }
    return t('BankingView.booking.selectionExceeds', { amount: formatCurrency(-selectionRemaining.value) })
})

// Beim ersten Mount mit v-if neu gerendert — onMounted lädt direkt
onMounted(async () => {
    if (props.modelValue) {
        await openDialog()
    }
})

// Für Re-Öffnung ohne v-if-Remount
watch(() => props.modelValue, async (open) => {
    if (open) await openDialog()
})

// Suchtyp umschalten → laufende Suche mit dem neuen Typ wiederholen
watch(searchType, () => {
    if (manualSearch.value && manualSearch.value.trim().length >= 2) {
        doSearch(manualSearch.value.trim())
    } else {
        searchResults.value = []
    }
})

async function openDialog() {
    changingAssignment.value = false
    manualSearch.value = ''
    searchResults.value = []
    selected.value = []
    searchType.value = isOutgoing.value ? 'ap' : 'ar'
    await loadCandidates()
}

async function loadCandidates() {
    loading.value = true
    candidates.value = []
    currentMatch.value = null
    try {
        const payload = await matching.fetchMatchCandidates(props.transaction.id)
        candidates.value = payload.candidates || []
        currentMatch.value = payload.current_match || null
        suggestedGroup.value = payload.suggested_group || null
        contact.value = payload.contact || null
    } catch (e) {
        alerts.error(e.message)
    } finally {
        loading.value = false
    }
}

// ── Sammelbuchung ────────────────────────────────────────────────────────

function normalizeDoc(doc) {
    return {
        target_type:    doc.target_type || doc.type,
        target_id:      doc.target_id ?? doc.id,
        invnumber:      doc.invnumber,
        contact_name:   doc.contact_name,
        open_amount:    parseFloat(doc.open_amount) || 0,
        is_credit_note: !!doc.is_credit_note
    }
}

function docKey(doc) {
    return `${doc.target_type || doc.type}:${doc.target_id ?? doc.id}`
}

function bankEffect(doc) {
    const open = parseFloat(doc.open_amount) || 0
    return (doc.target_type || doc.type) === 'ar' ? open : -open
}

function isSelected(doc) {
    const key = docKey(doc)
    return selected.value.some(d => docKey(d) === key)
}

function toggleSelect(doc) {
    const key = docKey(doc)
    const idx = selected.value.findIndex(d => docKey(d) === key)
    if (idx >= 0) selected.value.splice(idx, 1)
    else selected.value.push(normalizeDoc(doc))
}

// Vorschlag in die Auswahl übernehmen, damit der Nutzer einzelne Belege
// tauschen kann
function editGroup() {
    if (!suggestedGroup.value) return
    selected.value = suggestedGroup.value.invoices.map(normalizeDoc)
}

async function bookSelection() {
    if (!selectionBalanced.value || selected.value.length === 0) return
    await bookTargets(selected.value.map(d => ({ target_type: d.target_type, target_id: d.target_id })))
}

async function bookGroup() {
    if (!suggestedGroup.value) return
    await bookTargets(suggestedGroup.value.targets)
}

async function bookTargets(targets) {
    booking.value = true
    try {
        const result = await matching.bookTransactionMultiple(props.transaction.id, targets, props.accountId)
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

// ── Einzelbuchung ────────────────────────────────────────────────────────

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
    const doc = normalizeDoc(candidate)
    const txAmount = Math.abs(parseFloat(props.transaction.amount) || 0)
    const invAmount = Math.abs(doc.open_amount)
    if (Math.abs(txAmount - invAmount) > 0.01) {
        const res = await alerts.warning(
            t('BankingView.booking.amountMismatchWarning', {
                invAmount: formatCurrency(doc.open_amount),
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
        await matching.matchTransaction(props.transaction.id, doc.target_type, doc.target_id)
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
    const doc = normalizeDoc(candidate)
    try {
        await matching.matchTransaction(props.transaction.id, doc.target_type, doc.target_id)
        alerts.success(t('BankingView.booking.assignSuccess'))
        emit('done')
        close()
    } catch (e) {
        alerts.error(e.message)
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
        await matching.fetchOpenInvoices(searchType.value, term)
        searchResults.value = matching.openInvoices.value.filter(inv => inv.type === searchType.value)
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

// Geldausgang ohne passenden Beleg: Eingangsrechnung direkt aus dem Umsatz
// anlegen (Hub öffnet den AP-Dialog, der erkannte Lieferant wird vorbelegt).
function createAp() {
    emit('createAp', props.transaction, contact.value)
    show.value = false
}

function close() {
    show.value = false
}

function onClosed() {
    candidates.value = []
    currentMatch.value = null
    suggestedGroup.value = null
    contact.value = null
    manualSearch.value = ''
    searchResults.value = []
    selected.value = []
    changingAssignment.value = false
}

function contactSourceLabel(c) {
    const key = `BankingView.booking.contactSource_${c.source}`
    const label = t(key)
    return label === key ? c.source : label
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

<style scoped>
/* Kontaktname darf schrumpfen und kürzt mit Ellipse, Chip und Betrag bleiben ganz */
.doc-contact {
    min-width: 0;
}
</style>
