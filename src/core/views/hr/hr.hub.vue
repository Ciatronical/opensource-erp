<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Header -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-icon color="primary" class="mr-1">mdi-account-group</v-icon>
            <h1 class="text-h6 mb-0">{{ t('HrView.title') }}</h1>
            <v-spacer />
        </div>

        <!-- Tabs -->
        <v-tabs v-model="tab" color="primary" class="mb-4">
            <v-tab value="dashboard" prepend-icon="mdi-view-dashboard">
                {{ t('HrView.tabs.dashboard') }}
            </v-tab>
            <v-tab value="payroll" prepend-icon="mdi-cash-multiple">
                {{ t('HrView.tabs.payroll') }}
            </v-tab>
            <v-tab value="vacation" prepend-icon="mdi-beach">
                {{ t('HrView.tabs.vacation') }}
                <v-badge
                    v-if="pendingCount > 0"
                    :content="pendingCount"
                    color="warning"
                    inline
                    class="ml-1"
                />
            </v-tab>
            <v-tab value="settings" prepend-icon="mdi-cog">
                {{ t('HrView.tabs.settings') }}
            </v-tab>
            <v-tab value="tax" prepend-icon="mdi-table-cog">
                {{ t('HrView.tabs.tax') }}
            </v-tab>
        </v-tabs>

        <v-tabs-window v-model="tab">

            <!-- DASHBOARD -->
            <v-tabs-window-item value="dashboard">
                <v-progress-linear v-if="dashLoading" indeterminate color="primary" class="mb-4" />

                <v-row v-if="dashboard">
                    <!-- KPI-Karten -->
                    <v-col cols="12" sm="6" md="3">
                        <v-card variant="tonal" color="primary" class="text-center pa-4">
                            <v-icon size="36" class="mb-2">mdi-account-group</v-icon>
                            <div class="text-h4 font-weight-bold">{{ dashboard.active_employees }}</div>
                            <div class="text-body-2">{{ t('HrView.dashboard.activeEmployees') }}</div>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="3">
                        <v-card
                            variant="tonal"
                            :color="dashboard.pending_vacation_requests > 0 ? 'warning' : 'grey'"
                            class="text-center pa-4 cursor-pointer"
                            @click="goToVacation"
                        >
                            <v-icon size="36" class="mb-2">mdi-clock-alert</v-icon>
                            <div class="text-h4 font-weight-bold">{{ dashboard.pending_vacation_requests }}</div>
                            <div class="text-body-2">{{ t('HrView.dashboard.pendingVacation') }}</div>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="3">
                        <v-card
                            variant="tonal"
                            color="info"
                            class="text-center pa-4 cursor-pointer"
                            @click="goToPayroll"
                        >
                            <v-icon size="36" class="mb-2">mdi-cash-multiple</v-icon>
                            <div v-if="dashboard.last_payroll_run" class="text-h6 font-weight-bold">
                                {{ monthName(dashboard.last_payroll_run.month) }}
                                {{ dashboard.last_payroll_run.year }}
                            </div>
                            <div v-else class="text-body-1 text-medium-emphasis">{{ t('HrView.dashboard.noPayrollRun') }}</div>
                            <div class="text-body-2">{{ t('HrView.dashboard.lastPayrollRun') }}</div>
                        </v-card>
                    </v-col>

                    <v-col cols="12" sm="6" md="3">
                        <v-card variant="tonal" color="success" class="pa-4">
                            <div class="d-flex align-center mb-2">
                                <v-icon class="mr-2">mdi-beach</v-icon>
                                <span class="text-body-2 font-weight-medium">{{ t('HrView.dashboard.myVacation') }}</span>
                            </div>
                            <div v-if="myVacation">
                                <v-progress-linear
                                    :model-value="myUsedPercent"
                                    :color="myUsedPercent >= 100 ? 'error' : 'success'"
                                    bg-color="rgba(255,255,255,0.3)"
                                    rounded
                                    height="8"
                                    class="mb-2"
                                />
                                <div class="text-caption">
                                    {{ t('HrView.dashboard.daysUsed', {
                                        used: myVacation.days_used,
                                        total: myVacation.days_total
                                    }) }}
                                </div>
                                <div class="text-body-2 font-weight-bold mt-1">
                                    {{ t('HrView.dashboard.daysRemaining', {
                                        days: myVacation.days_total - myVacation.days_used
                                    }) }}
                                </div>
                            </div>
                        </v-card>
                    </v-col>

                    <!-- Meine Urlaubsanträge -->
                    <v-col v-if="myVacation?.requests?.length" cols="12" md="6">
                        <v-card variant="outlined">
                            <v-card-title class="text-body-1">
                                <v-icon class="mr-2">mdi-calendar-account</v-icon>
                                {{ t('HrView.dashboard.myVacation') }} {{ currentYear }}
                            </v-card-title>
                            <v-list density="compact">
                                <v-list-item
                                    v-for="req in myVacation.requests"
                                    :key="req.id"
                                >
                                    <v-list-item-title class="text-body-2">
                                        {{ formatDate(req.date_from) }} – {{ formatDate(req.date_to) }}
                                        <v-chip
                                            size="x-small"
                                            :color="statusColor(req.status)"
                                            variant="tonal"
                                            class="ml-1"
                                        >
                                            {{ statusLabel(req.status) }}
                                        </v-chip>
                                    </v-list-item-title>
                                </v-list-item>
                            </v-list>
                        </v-card>
                    </v-col>

                    <!-- Letzter Lohnlauf -->
                    <v-col v-if="dashboard.last_payroll_run" cols="12" md="6">
                        <v-card variant="outlined">
                            <v-card-title class="text-body-1">
                                <v-icon class="mr-2">mdi-cash-multiple</v-icon>
                                {{ t('HrView.dashboard.lastPayrollRun') }}:
                                {{ monthName(dashboard.last_payroll_run.month) }} {{ dashboard.last_payroll_run.year }}
                            </v-card-title>
                            <v-card-text>
                                <v-chip
                                    :color="dashboard.last_payroll_run.status === 'final' ? 'success' : 'warning'"
                                    variant="tonal"
                                    size="small"
                                    class="mr-2"
                                >
                                    {{ dashboard.last_payroll_run.status === 'final'
                                        ? t('HrView.payroll.statusFinal')
                                        : t('HrView.payroll.statusDraft') }}
                                </v-chip>
                                <span class="text-body-2 text-medium-emphasis">
                                    {{ dashboard.last_payroll_run.item_count }} {{ t('HrView.payroll.employees') }}
                                </span>
                                <div class="mt-2">
                                    <v-btn
                                        size="small"
                                        variant="tonal"
                                        color="primary"
                                        prepend-icon="mdi-eye"
                                        @click="goToPayroll"
                                    >
                                        {{ t('HrView.payroll.view') }}
                                    </v-btn>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-tabs-window-item>

            <!-- LOHNABRECHNUNG -->
            <v-tabs-window-item value="payroll">
                <HrPayrollTab />
            </v-tabs-window-item>

            <!-- URLAUBSPLANUNG -->
            <v-tabs-window-item value="vacation">
                <HrVacationTab ref="vacationTabRef" />
            </v-tabs-window-item>

            <!-- EINSTELLUNGEN -->
            <v-tabs-window-item value="settings">
                <HrSettingsTab />
            </v-tabs-window-item>

            <!-- STEUERTABELLE -->
            <v-tabs-window-item value="tax">
                <HrTaxTab />
            </v-tabs-window-item>

        </v-tabs-window>
    </v-container>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import HrPayrollTab from './tabs/hr.payroll.tab.vue'
import HrVacationTab from './tabs/hr.vacation.tab.vue'
import HrSettingsTab from './tabs/hr.settings.tab.vue'
import HrTaxTab from './tabs/hr.tax.tab.vue'

const MONTH_NAMES = ['Januar','Februar','März','April','Mai','Juni',
    'Juli','August','September','Oktober','November','Dezember']

export default {
    name: 'HrHubView',
    components: { NavbarView, HrPayrollTab, HrVacationTab, HrSettingsTab, HrTaxTab },
    setup() {
        const { t } = useI18n()
        const hr = hrStore()
        const oserp = oserpStore()

        const tab = ref('dashboard')
        const dashboard = ref(null)
        const dashLoading = ref(false)
        const currentYear = new Date().getFullYear()

        const myVacation = computed(() => dashboard.value?.my_vacation)
        const myUsedPercent = computed(() => {
            const mv = myVacation.value
            if (!mv || !mv.days_total) return 0
            return Math.round((mv.days_used / mv.days_total) * 100)
        })

        const pendingCount = computed(() => dashboard.value?.pending_vacation_requests || 0)

        function monthName(m) { return MONTH_NAMES[m - 1] || m }

        function statusColor(s) {
            return { approved: 'success', pending: 'warning', rejected: 'error' }[s] || 'default'
        }

        function statusLabel(s) {
            return {
                approved: t('HrView.dashboard.approved'),
                pending: t('HrView.dashboard.pending'),
                rejected: t('HrView.dashboard.rejected')
            }[s] || s
        }

        function formatDate(d) {
            if (!d) return ''
            return new Date(d + 'T00:00:00').toLocaleDateString('de-DE', {
                day: '2-digit', month: '2-digit', year: 'numeric'
            })
        }

        function goToPayroll() { tab.value = 'payroll' }
        function goToVacation() { tab.value = 'vacation' }

        async function loadDashboard() {
            dashLoading.value = true
            try {
                const employeeId = oserp.session?.logged_in_employee?.id || 0
                dashboard.value = await hr.fetchDashboard(employeeId)
            } finally {
                dashLoading.value = false
            }
        }

        onMounted(loadDashboard)

        return {
            t, tab, dashboard, dashLoading, currentYear,
            myVacation, myUsedPercent, pendingCount,
            monthName, statusColor, statusLabel, formatDate,
            goToPayroll, goToVacation
        }
    }
}
</script>

<style scoped>
.cursor-pointer { cursor: pointer; }
</style>
