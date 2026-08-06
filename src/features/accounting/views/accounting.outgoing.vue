<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-1">{{ t('AccountingView.outgoing.title') }}</h1>
                <p class="text-body-2 text-grey mb-4">{{ t('AccountingView.outgoing.subtitle') }}</p>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mt-1 mb-2" :text="t('AccountingView.outgoing.info')" />
            </v-col>
        </v-row>

        <!-- Statistiken -->
        <v-row>
            <v-col cols="12" sm="4">
                <v-card variant="outlined">
                    <v-card-text class="text-center">
                        <div class="text-h4 font-weight-bold">{{ matchStats.totalTransactions || 0 }}</div>
                        <div class="text-body-2 text-grey">{{ t('AccountingView.outgoing.openTransactions') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card variant="outlined">
                    <v-card-text class="text-center">
                        <div class="text-h4 font-weight-bold">{{ matchStats.totalOpenInvoices || 0 }}</div>
                        <div class="text-body-2 text-grey">{{ t('AccountingView.outgoing.openInvoices') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card :color="(matchStats.matchCount || 0) > 0 ? 'success' : 'default'" variant="tonal">
                    <v-card-text class="text-center">
                        <div class="text-h4 font-weight-bold">{{ matchStats.matchCount || 0 }}</div>
                        <div class="text-body-2">{{ t('AccountingView.outgoing.matchFound') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Aktionen -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-btn color="primary" variant="elevated" @click="loadMatches" :loading="loading">
                    <v-icon start>mdi-refresh</v-icon>
                    {{ t('AccountingView.outgoing.runMatching') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Match-Tabelle -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-alert v-if="!loading && matches.length === 0" type="info" variant="tonal">
                    <div>{{ t('AccountingView.outgoing.noMatches') }}</div>
                    <!-- Eine leere Liste ist meist kein Fehler, sondern heisst:
                         die Zahlungen sind schon verbucht. Das gehoert dazugesagt. -->
                    <div v-if="matchStats.alreadySettled > 0" class="text-body-2 mt-2">
                        {{ t('AccountingView.outgoing.alreadySettledHint', { count: matchStats.alreadySettled }) }}
                    </div>
                </v-alert>

                <v-card v-for="match in pagedMatches" :key="match.transaction_id + '-' + match.invoice_id" class="mb-3">
                    <v-card-text>
                        <v-row align="center">
                            <v-col cols="12" md="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.customerName') }}</div>
                                <a class="font-weight-bold text-primary text-decoration-none" style="cursor: pointer" @click="openCustomer(match)">
                                    {{ match.customer_name }}
                                </a>
                                <div class="text-caption">
                                    {{ t('AccountingView.outgoing.invoiceNumber') }}:
                                    <a class="text-primary text-decoration-none font-weight-medium" style="cursor: pointer" @click="openInvoice(match)">
                                        {{ match.invoice_number }}
                                    </a>
                                </div>
                                <div class="text-caption text-grey">
                                    <v-icon size="13" class="mr-1">mdi-calendar</v-icon>{{ t('AccountingView.outgoing.transactionDate') }}: {{ formatDate(match.transdate) }}
                                </div>
                                <v-progress-linear
                                    :model-value="match.confidence * 100"
                                    :color="match.confidence >= 0.9 ? 'success' : match.confidence >= 0.7 ? 'warning' : 'error'"
                                    height="16" rounded class="mt-2"
                                >
                                    <template #default>
                                        <span class="text-caption">{{ t('AccountingView.outgoing.confidence') }} {{ Math.round(match.confidence * 100) }}%</span>
                                    </template>
                                </v-progress-linear>
                                <div v-if="match.match_reason" class="text-caption text-grey mt-1">{{ match.match_reason }}</div>
                            </v-col>
                            <v-col cols="4" md="2">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.txAmount') }}</div>
                                <div class="text-h6 text-success">{{ formatCurrency(match.tx_amount) }}</div>
                            </v-col>
                            <v-col cols="4" md="2">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.invoiceAmount') }}</div>
                                <div class="text-h6">{{ formatCurrency(match.invoice_total) }}</div>
                            </v-col>
                            <v-col cols="4" md="2">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.openAmount') }}</div>
                                <div class="text-h6 text-warning">{{ formatCurrency(match.invoice_amount) }}</div>
                            </v-col>
                            <v-col cols="12" md="2" class="text-end">
                                <v-btn color="success" variant="elevated" @click="onConfirm(match)">
                                    <v-icon start>mdi-check</v-icon>
                                    {{ t('AccountingView.outgoing.confirm') }}
                                </v-btn>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>

                <!-- Seitenblaetterung: 200 Karten am Stueck sind weder zu ueberblicken
                     noch fluessig zu bedienen. -->
                <div v-if="pageCount > 1" class="d-flex align-center justify-center flex-wrap mt-4" style="gap: 16px">
                    <v-pagination v-model="page" :length="pageCount" :total-visible="7" density="comfortable" rounded />
                    <span class="text-caption text-medium-emphasis">
                        {{ t('AccountingView.outgoing.pageInfo', { from: rangeFrom, to: rangeTo, total: matches.length }) }}
                    </span>
                </div>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useOutgoingMatching } from '../composables/useOutgoingMatching.js'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()
const router = useRouter()

// Re.-Nr. → Rechnung ansehen; Kundenname → zum Kunden
function openInvoice(match) {
    if (match.invoice_id) router.push({ name: 'faktura-invoice-view', params: { id: match.invoice_id } })
}
function openCustomer(match) {
    if (match.customer_id) router.push({ name: 'change-customer', params: { id: match.customer_id } })
}
const { loading, matches, matchStats, fetchMatches, confirmMatch } = useOutgoingMatching()

const page     = ref(1)
const PER_PAGE = 20
const pageCount  = computed(() => Math.max(1, Math.ceil(matches.value.length / PER_PAGE)))
const pagedMatches = computed(() => matches.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE))
const rangeFrom = computed(() => matches.value.length ? (page.value - 1) * PER_PAGE + 1 : 0)
const rangeTo   = computed(() => Math.min(page.value * PER_PAGE, matches.value.length))

// Nach dem Buchen kann die letzte Seite leer werden — dann eine Seite zurueck
watch(() => matches.value.length, () => {
    if (page.value > pageCount.value) page.value = pageCount.value
})

async function loadMatches() {
    page.value = 1
    await fetchMatches()
}

async function onConfirm(match) {
    const result = await confirmMatch(match.transaction_id, match.invoice_id)
    if (result.success) {
        toasts.success(t('AccountingView.outgoing.confirmed', { invoice: match.invoice_number }))
        // Match aus Liste entfernen
        const idx = matches.value.findIndex(m => m.transaction_id === match.transaction_id)
        if (idx !== -1) matches.value.splice(idx, 1)
    } else {
        toasts.error(result.text || t('AccountingView.outgoing.confirmError'))
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function formatDate(value) {
    if (!value) return '—'
    const d = new Date(value)
    return isNaN(d) ? value : d.toLocaleDateString('de-DE')
}

onMounted(() => {
    loadMatches()
})
</script>
