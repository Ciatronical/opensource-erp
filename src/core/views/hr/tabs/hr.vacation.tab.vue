<template>
    <div>
        <!-- Header -->
        <div class="d-flex align-center mb-4 flex-wrap ga-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('HrView.vacation.title') }}</span>
            <v-spacer />
            <v-select
                v-model="selectedYear"
                :items="yearItems"
                density="compact"
                variant="outlined"
                hide-details
                style="max-width: 100px"
                @update:modelValue="load"
            />
            <v-btn-toggle v-model="viewMode" density="compact" variant="outlined" mandatory>
                <v-btn value="list" icon="mdi-format-list-bulleted" size="small" />
                <v-btn value="calendar" icon="mdi-calendar-month" size="small" />
            </v-btn-toggle>
            <v-btn color="primary" size="small" prepend-icon="mdi-plus" @click="openNewRequest">
                {{ t('HrView.vacation.newRequest') }}
            </v-btn>
        </div>

        <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

        <!-- AUSSTEHENDE ANTRÄGE (immer oben sichtbar) -->
        <v-card v-if="pendingRequests.length > 0" variant="tonal" color="warning" class="mb-4">
            <v-card-title class="d-flex align-center text-body-1 pb-1">
                <v-icon class="mr-2">mdi-clock-alert</v-icon>
                {{ t('HrView.vacation.pending') }}
                <v-chip size="x-small" class="ml-2" color="warning" variant="elevated">
                    {{ pendingRequests.length }}
                </v-chip>
            </v-card-title>
            <v-card-text class="pt-0">
                <v-list density="compact" bg-color="transparent" class="pa-0">
                    <v-list-item
                        v-for="req in pendingRequests"
                        :key="req.id"
                        class="px-0"
                    >
                        <template #prepend>
                            <v-avatar size="28" color="warning" variant="tonal">
                                <v-icon size="16">mdi-account</v-icon>
                            </v-avatar>
                        </template>
                        <v-list-item-title class="text-body-2">
                            <strong>{{ req.employee_name }}</strong>
                            — {{ formatDate(req.date_from) }} bis {{ formatDate(req.date_to) }}
                            <v-chip size="x-small" variant="tonal" class="ml-1">{{ req.days }} Tage</v-chip>
                        </v-list-item-title>
                        <v-list-item-subtitle v-if="req.notes" class="text-caption">
                            {{ req.notes }}
                        </v-list-item-subtitle>
                        <template #append>
                            <v-btn
                                size="x-small"
                                color="success"
                                variant="tonal"
                                prepend-icon="mdi-check"
                                class="mr-1"
                                @click="approve(req)"
                            >
                                {{ t('HrView.vacation.approve') }}
                            </v-btn>
                            <v-btn
                                size="x-small"
                                color="error"
                                variant="tonal"
                                prepend-icon="mdi-close"
                                @click="openRejectDialog(req)"
                            >
                                {{ t('HrView.vacation.reject') }}
                            </v-btn>
                        </template>
                    </v-list-item>
                </v-list>
            </v-card-text>
        </v-card>

        <!-- LISTENANSICHT -->
        <div v-if="viewMode === 'list'">
            <v-expansion-panels v-if="overview.employees?.length" multiple>
                <v-expansion-panel
                    v-for="emp in overview.employees"
                    :key="emp.employee_id"
                    :value="emp.employee_id"
                >
                    <v-expansion-panel-title>
                        <div class="d-flex align-center ga-2 w-100 flex-wrap">
                            <v-icon color="primary" size="20">mdi-account</v-icon>
                            <span class="font-weight-medium">{{ emp.name }}</span>
                            <v-spacer />

                            <!-- Urlaubsbalken -->
                            <div class="d-flex align-center ga-2 mr-2" style="min-width: 180px">
                                <v-progress-linear
                                    :model-value="usedPercent(emp)"
                                    :color="usedPercent(emp) >= 100 ? 'error' : 'primary'"
                                    bg-color="grey-lighten-3"
                                    rounded
                                    height="8"
                                    style="min-width: 80px"
                                />
                                <span class="text-caption text-no-wrap text-medium-emphasis">
                                    {{ emp.days_used }} / {{ emp.days_total }} T
                                </span>
                            </div>

                            <v-btn
                                size="x-small"
                                variant="text"
                                icon="mdi-pencil"
                                :title="t('HrView.vacation.editEntitlement')"
                                @click.stop="openEntitlementDialog(emp)"
                            />
                        </div>
                    </v-expansion-panel-title>
                    <v-expansion-panel-text>
                        <div class="d-flex ga-3 mb-3 flex-wrap">
                            <v-chip size="small" color="primary" variant="tonal">
                                {{ t('HrView.vacation.daysTotal') }}: {{ emp.days_total }}
                            </v-chip>
                            <v-chip size="small" color="warning" variant="tonal">
                                {{ t('HrView.vacation.daysUsed') }}: {{ emp.days_used }}
                            </v-chip>
                            <v-chip size="small" :color="remaining(emp) < 0 ? 'error' : 'success'" variant="tonal">
                                {{ t('HrView.vacation.daysRemaining') }}: {{ remaining(emp) }}
                            </v-chip>
                            <v-spacer />
                            <v-btn
                                size="x-small"
                                variant="tonal"
                                color="primary"
                                prepend-icon="mdi-plus"
                                @click="openNewRequest(emp)"
                            >
                                {{ t('HrView.vacation.newRequest') }}
                            </v-btn>
                        </div>

                        <v-list v-if="emp.requests?.length" density="compact" class="pa-0">
                            <v-list-item
                                v-for="req in emp.requests"
                                :key="req.id"
                                class="px-0 rounded mb-1"
                                :class="statusBg(req.status)"
                            >
                                <template #prepend>
                                    <v-icon :color="statusColor(req.status)" size="18" class="mr-1">
                                        {{ statusIcon(req.status) }}
                                    </v-icon>
                                </template>
                                <v-list-item-title class="text-body-2">
                                    {{ formatDate(req.date_from) }} – {{ formatDate(req.date_to) }}
                                    <v-chip size="x-small" :color="statusColor(req.status)" variant="tonal" class="ml-1">
                                        {{ req.days }} T
                                    </v-chip>
                                </v-list-item-title>
                                <v-list-item-subtitle v-if="req.notes || req.rejection_reason" class="text-caption">
                                    {{ req.rejection_reason || req.notes }}
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-chip
                                        size="x-small"
                                        :color="statusColor(req.status)"
                                        variant="tonal"
                                        class="mr-2"
                                    >
                                        {{ statusLabel(req.status) }}
                                    </v-chip>
                                    <v-btn
                                        v-if="req.status === 'pending'"
                                        size="x-small"
                                        variant="text"
                                        icon="mdi-pencil"
                                        @click="openEditRequest(req, emp)"
                                    />
                                    <v-btn
                                        size="x-small"
                                        variant="text"
                                        color="error"
                                        icon="mdi-delete"
                                        @click="deleteRequest(req)"
                                    />
                                </template>
                            </v-list-item>
                        </v-list>
                        <p v-else class="text-body-2 text-medium-emphasis">{{ t('HrView.vacation.noRequests') }}</p>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>
        </div>

        <!-- KALENDERANSICHT -->
        <div v-else-if="viewMode === 'calendar'" class="vacation-calendar">
            <div class="d-flex align-center mb-3 ga-2">
                <v-btn icon="mdi-chevron-left" size="small" variant="text" @click="prevMonth" />
                <span class="text-subtitle-1 font-weight-medium" style="min-width: 160px; text-align: center">
                    {{ calMonthName }} {{ calYear }}
                </span>
                <v-btn icon="mdi-chevron-right" size="small" variant="text" @click="nextMonth" />
                <v-btn size="x-small" variant="tonal" @click="goToday">Heute</v-btn>
            </div>

            <div class="calendar-grid">
                <!-- Wochentags-Header -->
                <div class="cal-header">
                    <div class="cal-employee-col"></div>
                    <div
                        v-for="day in calDays"
                        :key="day.date"
                        class="cal-day-header"
                        :class="{ 'cal-weekend': day.isWeekend, 'cal-today': day.isToday }"
                    >
                        <div class="cal-day-num">{{ day.day }}</div>
                        <div class="cal-day-wd text-caption">{{ day.weekday }}</div>
                    </div>
                </div>

                <!-- Mitarbeiter-Zeilen -->
                <div
                    v-for="emp in overview.employees"
                    :key="emp.employee_id"
                    class="cal-row"
                >
                    <div class="cal-employee-col text-body-2 text-truncate">{{ emp.name }}</div>
                    <div
                        v-for="day in calDays"
                        :key="day.date"
                        class="cal-day-cell"
                        :class="{ 'cal-weekend': day.isWeekend }"
                        :style="getCellStyle(emp, day)"
                        :title="getCellTooltip(emp, day)"
                    />
                </div>
            </div>

            <!-- Legende -->
            <div class="d-flex ga-3 mt-3 flex-wrap">
                <div class="d-flex align-center ga-1">
                    <div class="legend-box" style="background: #4caf50;" />
                    <span class="text-caption">{{ t('HrView.vacation.statusApproved') }}</span>
                </div>
                <div class="d-flex align-center ga-1">
                    <div class="legend-box" style="background: #ff9800;" />
                    <span class="text-caption">{{ t('HrView.vacation.statusPending') }}</span>
                </div>
                <div class="d-flex align-center ga-1">
                    <div class="legend-box" style="background: #f44336; opacity: 0.5" />
                    <span class="text-caption">{{ t('HrView.vacation.statusRejected') }}</span>
                </div>
            </div>
        </div>

        <!-- Dialog: Antrag erstellen/bearbeiten -->
        <v-dialog v-model="showRequestDialog" max-width="480">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="mr-2" color="primary">mdi-beach</v-icon>
                    {{ requestForm.id ? t('HrView.vacation.edit') : t('HrView.vacation.newRequest') }}
                </v-card-title>
                <v-card-text>
                    <v-select
                        v-model="requestForm.employee_id"
                        :label="t('HrView.vacation.employee')"
                        :items="employeeItems"
                        item-title="name"
                        item-value="employee_id"
                        variant="outlined"
                        density="comfortable"
                        class="mb-3"
                    />
                    <v-row dense>
                        <v-col cols="6">
                            <v-text-field
                                v-model="requestForm.date_from"
                                :label="t('HrView.vacation.dateFrom')"
                                type="date"
                                variant="outlined"
                                density="comfortable"
                                @update:modelValue="calcDays"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model="requestForm.date_to"
                                :label="t('HrView.vacation.dateTo')"
                                type="date"
                                variant="outlined"
                                density="comfortable"
                                @update:modelValue="calcDays"
                            />
                        </v-col>
                    </v-row>
                    <v-text-field
                        v-model.number="requestForm.days"
                        :label="t('HrView.vacation.days')"
                        type="number"
                        min="0.5"
                        step="0.5"
                        variant="outlined"
                        density="comfortable"
                        class="mb-2"
                    />
                    <v-text-field
                        v-model="requestForm.notes"
                        :label="t('HrView.vacation.notes')"
                        variant="outlined"
                        density="comfortable"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showRequestDialog = false">{{ t('HrView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="savingRequest" @click="saveRequest">
                        {{ t('HrView.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Ablehnen mit Grund -->
        <v-dialog v-model="showRejectDialog" max-width="400">
            <v-card>
                <v-card-title>{{ t('HrView.vacation.reject') }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="rejectionReason"
                        :label="t('HrView.vacation.rejectionReason')"
                        variant="outlined"
                        density="comfortable"
                        autofocus
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showRejectDialog = false">{{ t('HrView.cancel') }}</v-btn>
                    <v-btn color="error" @click="confirmReject">{{ t('HrView.vacation.reject') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dialog: Jahresanspruch bearbeiten -->
        <v-dialog v-model="showEntitlementDialog" max-width="360">
            <v-card v-if="entitlementTarget">
                <v-card-title>{{ t('HrView.vacation.setEntitlement') }}</v-card-title>
                <v-card-text>
                    <p class="text-body-2 mb-3">{{ entitlementTarget.name }}</p>
                    <v-text-field
                        v-model.number="entitlementDays"
                        :label="t('HrView.vacation.daysTotal')"
                        type="number"
                        min="0"
                        step="0.5"
                        suffix="Tage"
                        variant="outlined"
                        density="comfortable"
                        autofocus
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showEntitlementDialog = false">{{ t('HrView.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="savingEntitlement" @click="saveEntitlement">
                        {{ t('HrView.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { hrStore } from '@/core/stores/hr.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toasts from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const MONTH_NAMES = ['Januar','Februar','März','April','Mai','Juni',
    'Juli','August','September','Oktober','November','Dezember']
const WEEKDAYS_SHORT = ['So','Mo','Di','Mi','Do','Fr','Sa']

function workdaysBetween(from, to) {
    let count = 0
    const cur = new Date(from)
    const end = new Date(to)
    while (cur <= end) {
        const d = cur.getDay()
        if (d !== 0 && d !== 6) count++
        cur.setDate(cur.getDate() + 1)
    }
    return count
}

export default {
    name: 'HrVacationTab',
    setup() {
        const { t } = useI18n()
        const hr = hrStore()
        const oserp = oserpStore()

        const selectedYear = ref(new Date().getFullYear())
        const viewMode = ref('list')
        const loading = ref(false)
        const overview = ref({ employees: [], pending_requests: [] })

        const calMonth = ref(new Date().getMonth())
        const calYear = ref(new Date().getFullYear())

        const yearItems = computed(() => {
            const y = new Date().getFullYear()
            return [y - 1, y, y + 1]
        })

        const pendingRequests = computed(() => overview.value.pending_requests || [])

        const employeeItems = computed(() => overview.value.employees || [])

        const calMonthName = computed(() => MONTH_NAMES[calMonth.value])

        const calDays = computed(() => {
            const days = []
            const daysInMonth = new Date(calYear.value, calMonth.value + 1, 0).getDate()
            const today = new Date().toISOString().split('T')[0]
            for (let d = 1; d <= daysInMonth; d++) {
                const date = `${calYear.value}-${String(calMonth.value + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
                const wd = new Date(date).getDay()
                days.push({
                    day: d,
                    date,
                    weekday: WEEKDAYS_SHORT[wd],
                    isWeekend: wd === 0 || wd === 6,
                    isToday: date === today
                })
            }
            return days
        })

        function getCellStyle(emp, day) {
            const req = emp.requests?.find(r => r.date_from <= day.date && r.date_to >= day.date)
            if (!req) return {}
            const colors = { approved: '#4caf50', pending: '#ff9800', rejected: '#f44336' }
            return {
                background: colors[req.status] || 'transparent',
                opacity: req.status === 'rejected' ? '0.4' : '0.7'
            }
        }

        function getCellTooltip(emp, day) {
            const req = emp.requests?.find(r => r.date_from <= day.date && r.date_to >= day.date)
            if (!req) return ''
            return `${emp.name}: ${statusLabel(req.status)} (${req.days} T)`
        }

        function statusColor(s) {
            return { approved: 'success', pending: 'warning', rejected: 'error' }[s] || 'default'
        }

        function statusIcon(s) {
            return { approved: 'mdi-check-circle', pending: 'mdi-clock', rejected: 'mdi-close-circle' }[s] || 'mdi-circle'
        }

        function statusLabel(s) {
            return { approved: t('HrView.vacation.statusApproved'), pending: t('HrView.vacation.statusPending'), rejected: t('HrView.vacation.statusRejected') }[s] || s
        }

        function statusBg(s) {
            return { approved: 'bg-success-lighten-5', pending: '', rejected: 'bg-error-lighten-5' }[s] || ''
        }

        function usedPercent(emp) {
            if (!emp.days_total) return 0
            return Math.round((emp.days_used / emp.days_total) * 100)
        }

        function remaining(emp) {
            return (emp.days_total || 0) - (emp.days_used || 0)
        }

        function formatDate(d) {
            if (!d) return ''
            return new Date(d + 'T00:00:00').toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
        }

        function prevMonth() {
            if (calMonth.value === 0) { calMonth.value = 11; calYear.value-- }
            else calMonth.value--
        }

        function nextMonth() {
            if (calMonth.value === 11) { calMonth.value = 0; calYear.value++ }
            else calMonth.value++
        }

        function goToday() {
            calMonth.value = new Date().getMonth()
            calYear.value = new Date().getFullYear()
        }

        async function load() {
            loading.value = true
            try {
                overview.value = await hr.fetchVacationOverview(selectedYear.value)
            } finally {
                loading.value = false
            }
        }

        // Antrag-Dialog
        const showRequestDialog = ref(false)
        const savingRequest = ref(false)
        const requestForm = ref({})

        function openNewRequest(emp = null) {
            const myId = oserp.session?.logged_in_employee?.id || 0
            requestForm.value = {
                employee_id: emp?.employee_id || myId,
                date_from: '',
                date_to: '',
                days: 0,
                notes: '',
                id: null
            }
            showRequestDialog.value = true
        }

        function openEditRequest(req, emp) {
            requestForm.value = {
                id: req.id,
                employee_id: emp.employee_id,
                date_from: req.date_from,
                date_to: req.date_to,
                days: req.days,
                notes: req.notes || ''
            }
            showRequestDialog.value = true
        }

        function calcDays() {
            const f = requestForm.value
            if (f.date_from && f.date_to && f.date_to >= f.date_from) {
                f.days = workdaysBetween(f.date_from, f.date_to)
            }
        }

        async function saveRequest() {
            savingRequest.value = true
            try {
                await hr.saveVacationRequest(requestForm.value)
                showRequestDialog.value = false
                toasts.success(t('HrView.vacation.saved'))
                await load()
            } catch {
                toasts.error(t('HrView.vacation.saveError'))
            } finally {
                savingRequest.value = false
            }
        }

        async function approve(req) {
            const employeeId = oserp.session?.logged_in_employee?.id || 0
            try {
                await hr.approveVacationRequest(req.id, employeeId)
                toasts.success(t('HrView.vacation.approved'))
                await load()
            } catch {
                toasts.error(t('HrView.vacation.saveError'))
            }
        }

        // Ablehnen-Dialog
        const showRejectDialog = ref(false)
        const rejectionReason = ref('')
        const rejectTarget = ref(null)

        function openRejectDialog(req) {
            rejectTarget.value = req
            rejectionReason.value = ''
            showRejectDialog.value = true
        }

        async function confirmReject() {
            const employeeId = oserp.session?.logged_in_employee?.id || 0
            try {
                await hr.rejectVacationRequest(rejectTarget.value.id, employeeId, rejectionReason.value)
                showRejectDialog.value = false
                toasts.success(t('HrView.vacation.rejected'))
                await load()
            } catch {
                toasts.error(t('HrView.vacation.saveError'))
            }
        }

        async function deleteRequest(req) {
            const res = await alerts.question(t('HrView.vacation.confirmDelete'))
            if (!res.isConfirmed) return
            try {
                await hr.deleteVacationRequest(req.id)
                toasts.success(t('HrView.vacation.deleted'))
                await load()
            } catch {
                toasts.error(t('HrView.vacation.saveError'))
            }
        }

        // Jahresanspruch-Dialog
        const showEntitlementDialog = ref(false)
        const savingEntitlement = ref(false)
        const entitlementTarget = ref(null)
        const entitlementDays = ref(30)

        function openEntitlementDialog(emp) {
            entitlementTarget.value = emp
            entitlementDays.value = emp.days_total || 30
            showEntitlementDialog.value = true
        }

        async function saveEntitlement() {
            savingEntitlement.value = true
            try {
                await hr.saveVacationEntitlement({
                    employee_id: entitlementTarget.value.employee_id,
                    year: selectedYear.value,
                    days_total: entitlementDays.value
                })
                showEntitlementDialog.value = false
                toasts.success(t('HrView.vacation.entitlementSaved'))
                await load()
            } catch {
                toasts.error(t('HrView.vacation.saveError'))
            } finally {
                savingEntitlement.value = false
            }
        }

        onMounted(load)

        return {
            t, selectedYear, yearItems, viewMode, loading, overview,
            calMonth, calYear, calMonthName, calDays,
            pendingRequests, employeeItems,
            formatDate, usedPercent, remaining, statusColor, statusIcon, statusLabel, statusBg,
            getCellStyle, getCellTooltip,
            prevMonth, nextMonth, goToday, load,
            showRequestDialog, savingRequest, requestForm,
            openNewRequest, openEditRequest, calcDays, saveRequest,
            approve, showRejectDialog, rejectionReason, rejectTarget,
            openRejectDialog, confirmReject, deleteRequest,
            showEntitlementDialog, savingEntitlement, entitlementTarget,
            entitlementDays, openEntitlementDialog, saveEntitlement
        }
    }
}
</script>

<style scoped>
.vacation-calendar {
    overflow-x: auto;
}
.calendar-grid {
    display: flex;
    flex-direction: column;
    min-width: 600px;
    border: 1px solid rgba(0,0,0,0.12);
    border-radius: 8px;
    overflow: hidden;
}
.cal-header, .cal-row {
    display: flex;
    align-items: stretch;
    min-height: 32px;
}
.cal-header { background: rgba(0,0,0,0.04); font-weight: 600; }
.cal-row { border-top: 1px solid rgba(0,0,0,0.06); }
.cal-row:hover { background: rgba(0,0,0,0.02); }
.cal-employee-col {
    min-width: 140px;
    max-width: 140px;
    padding: 4px 8px;
    border-right: 1px solid rgba(0,0,0,0.12);
    display: flex;
    align-items: center;
    font-size: 13px;
}
.cal-day-header {
    flex: 1;
    min-width: 24px;
    text-align: center;
    padding: 2px 0;
    border-right: 1px solid rgba(0,0,0,0.06);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.cal-day-cell {
    flex: 1;
    min-width: 24px;
    border-right: 1px solid rgba(0,0,0,0.06);
    cursor: default;
    transition: opacity 0.15s;
}
.cal-day-num { font-size: 11px; font-weight: 600; line-height: 1.2; }
.cal-day-wd { font-size: 9px; opacity: 0.6; }
.cal-weekend { background: rgba(0,0,0,0.035); }
.cal-today .cal-day-num { color: #1976d2; font-weight: 800; }
.legend-box {
    width: 14px;
    height: 14px;
    border-radius: 3px;
}
</style>
