<!-- src/core/views/customer-vendor/tabs/turnover.tab.vue -->
<template>
    <v-row class="pa-2 pa-sm-3">
        <!-- Wenn keine Daten vorhanden -->
        <v-col v-if="!hasData" cols="12">
            <v-alert type="info" variant="tonal" border="start">
                <v-alert-title>{{ t('CustomerVendorEditView.turnover.noData') }}</v-alert-title>
                {{ t('CustomerVendorEditView.turnover.noDataText') }}
            </v-alert>
        </v-col>

        <!-- Statistik-Karten -->
        <template v-else>
            <!-- Trend & Gesamtstatistik -->
            <v-col cols="12">
                <v-row>
                    <!-- Aktuelles Jahr -->
                    <v-col cols="12" sm="6" md="3">
                        <v-card variant="outlined" elevation="1" class="h-100">
                            <v-card-text class="text-center">
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('CustomerVendorEditView.turnover.currentYear') }}</div>
                                <div class="text-h5 font-weight-bold text-primary">{{ formatCurrency(trend.current_year_revenue) }}</div>
                                <div class="text-caption mt-1">
                                    <v-chip size="small" :color="trendColor" variant="flat">
                                        <v-icon size="small" start>{{ trendIcon }}</v-icon>
                                        {{ formatPercent(trend.growth_percent) }}
                                    </v-chip>
                                </div>
                                <div class="text-caption text-medium-emphasis mt-1">
                                    {{ trend.current_year_invoices }} {{ t('CustomerVendorEditView.turnover.invoices') }}
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <!-- Letztes Jahr -->
                    <v-col cols="12" sm="6" md="3">
                        <v-card variant="outlined" elevation="1" class="h-100">
                            <v-card-text class="text-center">
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('CustomerVendorEditView.turnover.lastYear') }}</div>
                                <div class="text-h5 font-weight-bold">{{ formatCurrency(trend.last_year_revenue) }}</div>
                                <div class="text-caption mt-1 text-medium-emphasis">
                                    {{ t('CustomerVendorEditView.turnover.growth') }}: {{ formatCurrency(trend.growth_absolute) }}
                                </div>
                                <div class="text-caption text-medium-emphasis mt-1">
                                    {{ trend.last_year_invoices }} {{ t('CustomerVendorEditView.turnover.invoices') }}
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <!-- Gesamtumsatz -->
                    <v-col cols="12" sm="6" md="3">
                        <v-card variant="outlined" elevation="1" class="h-100">
                            <v-card-text class="text-center">
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('CustomerVendorEditView.turnover.totalRevenue') }}</div>
                                <div class="text-h5 font-weight-bold text-success">{{ formatCurrency(totals.total_revenue_all_time) }}</div>
                                <div class="text-caption mt-1 text-medium-emphasis">
                                    ⌀ {{ formatCurrency(totals.avg_invoice_value) }}
                                </div>
                                <div class="text-caption text-medium-emphasis mt-1">
                                    {{ totals.total_invoices }} {{ t('CustomerVendorEditView.turnover.invoicesTotal') }}
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <!-- Offene Posten -->
                    <v-col cols="12" sm="6" md="3">
                        <v-card variant="outlined" elevation="1" class="h-100">
                            <v-card-text class="text-center">
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('CustomerVendorEditView.turnover.outstanding') }}</div>
                                <div class="text-h5 font-weight-bold" :class="outstanding.count > 0 ? 'text-error' : ''">
                                    {{ formatCurrency(outstanding.total_amount) }}
                                </div>
                                <div class="text-caption mt-1 text-medium-emphasis">
                                    {{ outstanding.count }} {{ t('CustomerVendorEditView.turnover.openInvoices') }}
                                </div>
                                <v-btn
                                    v-if="outstanding.count > 0"
                                    size="x-small"
                                    variant="text"
                                    color="error"
                                    @click="showOutstandingDetails = !showOutstandingDetails"
                                    class="mt-1"
                                >
                                    {{ showOutstandingDetails ? t('CustomerVendorEditView.turnover.hideDetails') : t('CustomerVendorEditView.turnover.showDetails') }}
                                </v-btn>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-col>

            <!-- Offene Posten Details -->
            <v-col v-if="showOutstandingDetails && outstanding.invoices && outstanding.invoices.length > 0" cols="12">
                <v-card variant="outlined" elevation="1">
                    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                        <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.turnover.outstandingDetails') }}</h4>
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="pa-0">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>{{ t('CustomerVendorEditView.turnover.invoiceNumber') }}</th>
                                    <th>{{ t('CustomerVendorEditView.turnover.date') }}</th>
                                    <th>{{ t('CustomerVendorEditView.turnover.dueDate') }}</th>
                                    <th class="text-right">{{ t('CustomerVendorEditView.turnover.amount') }}</th>
                                    <th class="text-right">{{ t('CustomerVendorEditView.turnover.paid') }}</th>
                                    <th class="text-right">{{ t('CustomerVendorEditView.turnover.outstandingAmount') }}</th>
                                    <th class="text-right">{{ t('CustomerVendorEditView.turnover.overdue') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="invoice in outstanding.invoices" :key="invoice.invoice_number">
                                    <td>{{ invoice.invoice_number }}</td>
                                    <td>{{ invoice.date }}</td>
                                    <td>{{ invoice.due_date }}</td>
                                    <td class="text-right">{{ formatCurrency(invoice.amount) }}</td>
                                    <td class="text-right">{{ formatCurrency(invoice.paid) }}</td>
                                    <td class="text-right font-weight-bold">{{ formatCurrency(invoice.outstanding) }}</td>
                                    <td class="text-right">
                                        <v-chip
                                            v-if="invoice.days_overdue > 0"
                                            size="small"
                                            color="error"
                                            variant="flat"
                                        >
                                            {{ invoice.days_overdue }} {{ t('CustomerVendorEditView.turnover.days') }}
                                        </v-chip>
                                        <span v-else class="text-success">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card-text>
                </v-card>
            </v-col>

            <!-- Charts -->
            <v-col cols="12" lg="8">
                <!-- Monatsstatistik Chart -->
                <v-card variant="outlined" elevation="1">
                    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                        <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.turnover.monthlyRevenue') }}</h4>
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="pa-3">
                        <Bar
                            v-if="monthlyData.length > 0"
                            :data="monthlyChartData"
                            :options="monthlyChartOptions"
                        />
                        <v-alert v-else type="info" variant="tonal" density="compact">
                            {{ t('CustomerVendorEditView.turnover.noMonthlyData') }}
                        </v-alert>
                    </v-card-text>
                </v-card>

                <!-- Jahresstatistik Chart -->
                <v-card variant="outlined" elevation="1" class="mt-3">
                    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                        <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.turnover.yearlyRevenue') }}</h4>
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="pa-3">
                        <Line
                            v-if="yearlyData.length > 0"
                            :data="yearlyChartData"
                            :options="yearlyChartOptions"
                        />
                        <v-alert v-else type="info" variant="tonal" density="compact">
                            {{ t('CustomerVendorEditView.turnover.noYearlyData') }}
                        </v-alert>
                    </v-card-text>
                </v-card>
            </v-col>

            <!-- Top Produkte -->
            <v-col cols="12" lg="4">
                <v-card variant="outlined" elevation="1" class="h-100">
                    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                        <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.turnover.topProducts') }}</h4>
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="pa-2">
                        <v-list v-if="topProducts && topProducts.length > 0" density="compact" class="pa-0">
                            <v-list-item
                                v-for="(product, index) in topProducts"
                                :key="index"
                                class="px-2"
                            >
                                <template #prepend>
                                    <v-avatar size="32" :color="getProductColor(index)" class="mr-2">
                                        <span class="text-white font-weight-bold">{{ index + 1 }}</span>
                                    </v-avatar>
                                </template>
                                <v-list-item-title class="text-body-2 font-weight-medium">
                                    {{ product.partnumber }}
                                </v-list-item-title>
                                <v-list-item-subtitle class="text-caption">
                                    {{ product.description }}
                                </v-list-item-subtitle>
                                <template #append>
                                    <div class="text-right">
                                        <div class="text-body-2 font-weight-bold">{{ formatCurrency(product.total_revenue) }}</div>
                                        <div class="text-caption text-medium-emphasis">{{ product.quantity_sold }} {{ t('CustomerVendorEditView.turnover.units') }}</div>
                                    </div>
                                </template>
                            </v-list-item>
                        </v-list>
                        <v-alert v-else type="info" variant="tonal" density="compact">
                            {{ t('CustomerVendorEditView.turnover.noProductData') }}
                        </v-alert>
                    </v-card-text>
                </v-card>
            </v-col>

            <!-- Quartalsstatistik Tabelle -->
            <v-col v-if="quarterlyData && quarterlyData.length > 0" cols="12">
                <v-card variant="outlined" elevation="1">
                    <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                        <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.turnover.quarterlyRevenue') }}</h4>
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="pa-0">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>{{ t('CustomerVendorEditView.turnover.quarter') }}</th>
                                    <th class="text-right">{{ t('CustomerVendorEditView.turnover.revenue') }}</th>
                                    <th class="text-right">{{ t('CustomerVendorEditView.turnover.invoices') }}</th>
                                    <th class="text-right">{{ t('CustomerVendorEditView.turnover.avgInvoice') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="quarter in quarterlyData" :key="quarter.quarter">
                                    <td class="font-weight-medium">{{ quarter.quarter }}</td>
                                    <td class="text-right">{{ formatCurrency(quarter.revenue) }}</td>
                                    <td class="text-right">{{ quarter.invoice_count }}</td>
                                    <td class="text-right">{{ formatCurrency(quarter.revenue / quarter.invoice_count) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-card-text>
                </v-card>
            </v-col>
        </template>
    </v-row>
</template>

<script>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { Bar, Line } from 'vue-chartjs'
import {
    Chart as ChartJS,
    Title,
    Tooltip,
    Legend,
    BarElement,
    LineElement,
    CategoryScale,
    LinearScale,
    PointElement
} from 'chart.js'

// Chart.js Komponenten registrieren
ChartJS.register(Title, Tooltip, Legend, BarElement, LineElement, CategoryScale, LinearScale, PointElement)

export default {
    name: 'TurnoverTab',
    components: {
        Bar,
        Line
    },
    setup() {
        const { t } = useI18n()
        const oserpData = oserpStore()
        const showOutstandingDetails = ref(false)

        // Daten aus Store
        const turnoverStats = computed(() => oserpData.customer_vendor?.turnover_statistics || null)
        const hasData = computed(() => turnoverStats.value && turnoverStats.value.totals?.total_invoices > 0)

        const totals = computed(() => turnoverStats.value?.totals || {})
        const trend = computed(() => turnoverStats.value?.trend || {})
        const outstanding = computed(() => turnoverStats.value?.outstanding || { count: 0, total_amount: 0, invoices: [] })
        const topProducts = computed(() => turnoverStats.value?.top_products || [])
        const yearlyData = computed(() => (turnoverStats.value?.yearly || []).reverse())
        const monthlyData = computed(() => (turnoverStats.value?.monthly || []).reverse())
        const quarterlyData = computed(() => turnoverStats.value?.quarterly || [])

        // Trend-Farbe und Icon
        const trendColor = computed(() => {
            const growth = trend.value.growth_percent || 0
            if (growth > 10) return 'success'
            if (growth > 0) return 'info'
            if (growth === 0) return 'grey'
            return 'error'
        })

        const trendIcon = computed(() => {
            const growth = trend.value.growth_percent || 0
            if (growth > 0) return 'mdi-trending-up'
            if (growth === 0) return 'mdi-trending-neutral'
            return 'mdi-trending-down'
        })

        // Chart.js Daten für Monatsstatistik
        const monthlyChartData = computed(() => ({
            labels: monthlyData.value.map(d => d.month_name),
            datasets: [{
                label: t('CustomerVendorEditView.turnover.revenue'),
                data: monthlyData.value.map(d => d.revenue),
                backgroundColor: '#1976D2',
                borderColor: '#1976D2',
                borderWidth: 1
            }]
        }))

        // Chart.js Optionen für Monatsstatistik
        const monthlyChartOptions = {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return formatCurrency(context.parsed.y)
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => formatCurrency(value)
                    }
                },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            }
        }

        // Chart.js Daten für Jahresstatistik
        const yearlyChartData = computed(() => ({
            labels: yearlyData.value.map(d => d.year),
            datasets: [{
                label: t('CustomerVendorEditView.turnover.revenue'),
                data: yearlyData.value.map(d => d.revenue),
                borderColor: '#4CAF50',
                backgroundColor: 'rgba(76, 175, 80, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        }))

        // Chart.js Optionen für Jahresstatistik
        const yearlyChartOptions = {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2.5,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            return formatCurrency(context.parsed.y)
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: (value) => formatCurrency(value)
                    }
                }
            }
        }

        // Formatierungsfunktionen
        const formatCurrency = (value) => {
            if (!value && value !== 0) return '—'
            const currency = totals.value.currency || 'EUR'
            return new Intl.NumberFormat('de-DE', {
                style: 'currency',
                currency: currency
            }).format(value)
        }

        const formatPercent = (value) => {
            if (!value && value !== 0) return '0%'
            const sign = value > 0 ? '+' : ''
            return sign + value.toFixed(1) + '%'
        }

        const getProductColor = (index) => {
            const colors = ['#1976D2', '#4CAF50', '#FFC107', '#FF5722', '#9C27B0', '#00BCD4', '#795548', '#607D8B', '#E91E63', '#3F51B5']
            return colors[index % colors.length]
        }

        return {
            t,
            hasData,
            totals,
            trend,
            outstanding,
            topProducts,
            yearlyData,
            monthlyData,
            quarterlyData,
            trendColor,
            trendIcon,
            showOutstandingDetails,
            formatCurrency,
            formatPercent,
            getProductColor,
            monthlyChartData,
            monthlyChartOptions,
            yearlyChartData,
            yearlyChartOptions
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
    background-color: #f5f5f5;
}
.h-100 {
    height: 100%;
}
</style>