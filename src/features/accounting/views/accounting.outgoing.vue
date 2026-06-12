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
                    {{ t('AccountingView.outgoing.matchFound') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Match-Tabelle -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-alert v-if="!loading && matches.length === 0" type="info" variant="tonal">
                    {{ t('AccountingView.outgoing.noMatches') }}
                </v-alert>

                <v-card v-for="match in matches" :key="match.transaction_id + '-' + match.invoice_id" class="mb-3">
                    <v-card-text>
                        <v-row align="center">
                            <v-col cols="12" md="4">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.customerName') }}</div>
                                <div class="font-weight-bold">{{ match.customer_name }}</div>
                                <div class="text-caption">{{ t('AccountingView.outgoing.invoiceNumber') }}: {{ match.invoice_number }}</div>
                            </v-col>
                            <v-col cols="6" md="2">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.txAmount') }}</div>
                                <div class="text-h6 text-success">{{ formatCurrency(match.tx_amount) }}</div>
                            </v-col>
                            <v-col cols="6" md="2">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.invoiceAmount') }}</div>
                                <div class="text-h6">{{ formatCurrency(match.invoice_amount) }}</div>
                            </v-col>
                            <v-col cols="6" md="2">
                                <div class="text-caption text-grey">{{ t('AccountingView.outgoing.confidence') }}</div>
                                <v-progress-linear
                                    :model-value="match.confidence * 100"
                                    :color="match.confidence >= 0.9 ? 'success' : match.confidence >= 0.7 ? 'warning' : 'error'"
                                    height="20"
                                    rounded
                                >
                                    <template #default>
                                        <span class="text-caption">{{ Math.round(match.confidence * 100) }}%</span>
                                    </template>
                                </v-progress-linear>
                                <div class="text-caption text-grey mt-1">{{ match.match_reason }}</div>
                            </v-col>
                            <v-col cols="6" md="2" class="text-end">
                                <v-btn color="success" variant="elevated" @click="onConfirm(match)">
                                    <v-icon start>mdi-check</v-icon>
                                    {{ t('AccountingView.outgoing.confirm') }}
                                </v-btn>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useOutgoingMatching } from '../composables/useOutgoingMatching.js'

const { t } = useI18n()
const { loading, matches, matchStats, fetchMatches, confirmMatch } = useOutgoingMatching()

async function loadMatches() {
    await fetchMatches()
}

async function onConfirm(match) {
    const result = await confirmMatch(match.transaction_id, match.invoice_id)
    if (result.success) {
        // Match aus Liste entfernen
        const idx = matches.value.findIndex(m => m.transaction_id === match.transaction_id)
        if (idx !== -1) matches.value.splice(idx, 1)
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

onMounted(() => {
    loadMatches()
})
</script>
