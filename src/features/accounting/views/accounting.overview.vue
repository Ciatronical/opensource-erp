<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ t('AccountingView.overview.title') }}</h1>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                         class="mb-2" :text="t('AccountingView.overview.info')" />
            </v-col>
        </v-row>

        <!-- Statistik-Karten -->
        <v-row>
            <v-col cols="12" sm="6" md="3">
                <v-card color="warning" variant="tonal" @click="goToBookings('pending')">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-clock-outline</v-icon>
                        <div class="text-h4 font-weight-bold">{{ dashboard?.stats?.pending_count || 0 }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.pendingBookings') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card color="success" variant="tonal" @click="goToBookings('approved')">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-check-circle-outline</v-icon>
                        <div class="text-h4 font-weight-bold">{{ dashboard?.stats?.approved_count || 0 }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.approvedBookings') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card color="info" variant="tonal">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-arrow-down-bold</v-icon>
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.stats?.incoming_this_month) }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.incomingThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card color="primary" variant="tonal">
                    <v-card-text class="text-center">
                        <v-icon size="32" class="mb-2">mdi-arrow-up-bold</v-icon>
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.stats?.outgoing_this_month) }}</div>
                        <div class="text-body-2">{{ t('AccountingView.overview.outgoingThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Offene Posten (echte Zahlen aus ar/ap) -->
        <v-row class="mt-2">
            <v-col cols="12" sm="6">
                <v-card variant="tonal" color="error">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <div>
                                <div class="text-body-2">{{ t('AccountingView.overview.openReceivables') }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ t('AccountingView.overview.openReceivablesHint', { count: dashboard?.open_items?.receivables_count || 0 }) }}
                                </div>
                            </div>
                            <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.open_items?.receivables_sum) }}</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6">
                <v-card variant="tonal" color="deep-orange">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <div>
                                <div class="text-body-2">{{ t('AccountingView.overview.openPayables') }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ t('AccountingView.overview.openPayablesHint', { count: dashboard?.open_items?.payables_count || 0 }) }}
                                </div>
                            </div>
                            <div class="text-h5 font-weight-bold">{{ formatCurrency(dashboard?.open_items?.payables_sum) }}</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Steuer-Übersicht -->
        <v-row class="mt-2">
            <v-col cols="12" sm="6" md="4">
                <v-card variant="outlined">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-2">{{ t('AccountingView.overview.vstThisMonth') }}</span>
                            <span class="text-h6 text-success">{{ formatCurrency(dashboard?.stats?.vst_this_month) }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card variant="outlined">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-2">{{ t('AccountingView.overview.ustThisMonth') }}</span>
                            <span class="text-h6 text-error">{{ formatCurrency(dashboard?.stats?.ust_this_month) }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card variant="outlined" @click="router.push(t('BankingView.routes.bankingReconciliation'))">
                    <v-card-text>
                        <div class="d-flex justify-space-between align-center">
                            <span class="text-body-2">{{ t('AccountingView.overview.unmatchedBank') }}</span>
                            <v-chip :color="(dashboard?.unmatched_bank || 0) > 0 ? 'warning' : 'success'" size="small">
                                {{ dashboard?.unmatched_bank || 0 }}
                            </v-chip>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Schnellaktionen -->
        <v-row class="mt-4">
            <v-col cols="12">
                <h2 class="text-h6 mb-2">{{ t('AccountingView.overview.quickActions') }}</h2>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block color="primary" size="large" variant="elevated"
                       :to="t('AccountingView.routes.accountingInvoiceUpload')">
                    <v-icon start>mdi-upload</v-icon>
                    {{ t('AccountingView.overview.uploadInvoice') }}
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block color="secondary" size="large" variant="elevated"
                       @click="goToBookings('pending')">
                    <v-icon start>mdi-check-all</v-icon>
                    {{ t('AccountingView.overview.pendingBookings') }}
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block size="large" variant="elevated"
                       :to="t('AccountingView.routes.accountingOutgoing')">
                    <v-icon start>mdi-cash-check</v-icon>
                    {{ t('AccountingView.menu.outgoingMatching') }}
                </v-btn>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-btn block size="large" variant="elevated"
                       :to="t('AccountingView.routes.accountingDatevExport')">
                    <v-icon start>mdi-file-export</v-icon>
                    {{ t('AccountingView.menu.datevExport') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Letzte Buchungen -->
        <v-row class="mt-4">
            <v-col cols="12">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        {{ t('AccountingView.overview.recentBookings') }}
                        <v-spacer />
                        <v-btn variant="text" size="small" :to="t('AccountingView.routes.accountingBookings')">
                            {{ t('AccountingView.bookings.filterAll') }}
                            <v-icon end>mdi-arrow-right</v-icon>
                        </v-btn>
                    </v-card-title>
                    <v-data-table
                        :headers="recentHeaders"
                        :items="dashboard?.recent_bookings || []"
                        :loading="loading"
                        density="compact"
                        :items-per-page="10"
                        :no-data-text="t('AccountingView.bookings.noBookings')"
                    >
                        <template #item.amount="{ item }">
                            <span :class="item.type === 'incoming' ? 'text-error' : 'text-success'">
                                {{ item.type === 'incoming' ? '-' : '+' }}{{ formatCurrency(item.amount) }}
                            </span>
                        </template>
                        <template #item.status="{ item }">
                            <v-chip :color="statusColor(item.status)" size="x-small">
                                {{ t('AccountingView.bookings.status' + capitalize(item.status)) }}
                            </v-chip>
                        </template>
                        <template #item.ai_generated="{ item }">
                            <v-icon v-if="item.ai_generated" color="purple" size="small">mdi-robot</v-icon>
                        </template>
                    </v-data-table>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useRouter } from 'vue-router'
import { useAccounting } from '../composables/useAccounting.js'

const { t } = useI18n()
const router = useRouter()
const { loading, dashboard, fetchDashboard } = useAccounting()

const recentHeaders = computed(() => [
    { title: t('AccountingView.bookings.date'), key: 'booking_date_fmt', width: '100px' },
    { title: t('AccountingView.bookings.description'), key: 'description' },
    { title: t('AccountingView.bookings.vendor') + ' / ' + t('AccountingView.bookings.customer'), key: 'partner_name' },
    { title: t('AccountingView.bookings.amount'), key: 'amount', align: 'end' },
    { title: t('AccountingView.bookings.status'), key: 'status', width: '120px' },
    { title: 'KI', key: 'ai_generated', width: '50px', align: 'center' }
])

function goToBookings(status) {
    router.push({ path: t('AccountingView.routes.accountingBookings'), query: { status } })
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function statusColor(status) {
    const colors = { pending: 'warning', approved: 'success', booked: 'info', rejected: 'error' }
    return colors[status] || 'default'
}

function capitalize(str) {
    return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''
}

onMounted(() => {
    fetchDashboard()
})
</script>
