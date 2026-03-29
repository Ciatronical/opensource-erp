<!-- src/features/lxcars/views/reports/reports.view.vue -->
<template>
    <navbar-view />
    <v-container fluid class="pa-4" style="max-width: 1400px">
        <!-- Header -->
        <div class="d-flex align-center mb-4">
            <v-icon size="large" color="primary" class="mr-3">mdi-chart-timeline-variant-shimmer</v-icon>
            <div>
                <h1 class="text-h5 font-weight-bold">{{ t('ReportsView.title') }}</h1>
                <p class="text-body-2 text-medium-emphasis mb-0">{{ t('ReportsView.subtitle') }}</p>
            </div>
        </div>

        <!-- Tabs -->
        <v-tabs v-model="activeTab" color="primary" class="mb-4">
            <v-tab value="my">
                <v-icon start>mdi-account</v-icon>
                {{ t('ReportsView.myTab') }}
            </v-tab>
            <v-tab v-if="isManager" value="team">
                <v-icon start>mdi-account-group</v-icon>
                {{ t('ReportsView.teamTab') }}
            </v-tab>
        </v-tabs>

        <!-- Zeitraum-Auswahl -->
        <v-card variant="outlined" class="mb-4">
            <v-card-text class="d-flex align-center ga-3 flex-wrap py-2">
                <v-btn-toggle v-model="period" mandatory density="compact" color="primary" variant="outlined">
                    <v-btn value="day" size="small">{{ t('ReportsView.day') }}</v-btn>
                    <v-btn value="week" size="small">{{ t('ReportsView.week') }}</v-btn>
                    <v-btn value="month" size="small">{{ t('ReportsView.month') }}</v-btn>
                </v-btn-toggle>
                <v-btn icon="mdi-chevron-left" size="small" variant="text" @click="navigateDate(-1)" />
                <span class="text-body-2 font-weight-medium">{{ dateLabel }}</span>
                <v-btn icon="mdi-chevron-right" size="small" variant="text" :disabled="isToday" @click="navigateDate(1)" />
                <v-btn v-if="!isToday" variant="text" size="small" color="primary" @click="refDate = todayStr()">
                    {{ t('ReportsView.today') }}
                </v-btn>
                <v-spacer />
                <v-progress-circular v-if="loading" indeterminate size="20" width="2" />
            </v-card-text>
        </v-card>

        <!-- ═══════════════════════════════════════════════
             MEINE AUSWERTUNG
             ═══════════════════════════════════════════════ -->
        <div v-if="activeTab === 'my'">

            <!-- KPI-Karten -->
            <v-row class="mb-4">
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.productiveHours') }}</div>
                        <div class="text-h4 font-weight-bold mt-1 text-primary">{{ fmtH(myData.summary?.total_actual) }}</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.hours') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.utilization') }}</div>
                        <div class="text-h4 font-weight-bold mt-1" :class="utilColor(myUtilization)">{{ myUtilization }}%</div>
                        <div class="text-caption text-medium-emphasis">{{ fmtH(myData.available_total) }}h {{ t('ReportsView.available') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.efficiency') }}</div>
                        <div class="text-h4 font-weight-bold mt-1" :class="effColor(myEfficiency)">{{ myEfficiency }}%</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.plannedVsActual') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.ordersWorked') }}</div>
                        <div class="text-h4 font-weight-bold mt-1">{{ myData.summary?.orders_count || 0 }}</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.orders') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.tasksDone') }}</div>
                        <div class="text-h4 font-weight-bold mt-1 text-success">{{ myData.summary?.tasks_done || 0 }}</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.of') }} {{ myData.summary?.tasks_total || 0 }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.bestDay') }}</div>
                        <div class="text-h4 font-weight-bold mt-1 text-amber-darken-2">{{ fmtH(myData.best_day_minutes) }}h</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.personalRecord') }}</div>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Tagesstunden-Chart -->
            <v-card variant="outlined" class="mb-4">
                <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                    <v-icon start size="small">mdi-chart-bar</v-icon>
                    {{ t('ReportsView.dailyHours') }}
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <Bar v-if="myDailyChart.labels.length" :data="myDailyChart" :options="dailyOpts" />
                    <div v-else class="text-center text-medium-emphasis py-6">{{ t('ReportsView.noData') }}</div>
                </v-card-text>
            </v-card>

            <v-row>
                <!-- Bearbeitete Fahrzeuge -->
                <v-col cols="12" lg="7">
                    <v-card v-if="myData.vehicles && myData.vehicles.length" variant="outlined" class="h-100">
                        <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                            <v-icon start size="small">mdi-car-wrench</v-icon>
                            {{ t('ReportsView.vehiclesWorkedOn') }}
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="pa-0">
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th>{{ t('ReportsView.order') }}</th>
                                        <th>{{ t('ReportsView.licensePlate') }}</th>
                                        <th>{{ t('ReportsView.vehicleType') }}</th>
                                        <th class="text-right">{{ t('ReportsView.actual') }}</th>
                                        <th class="text-right">{{ t('ReportsView.planned') }}</th>
                                        <th class="text-center">{{ t('ReportsView.efficiency') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="v in myData.vehicles" :key="v.ordnumber">
                                        <td class="font-weight-medium">{{ v.ordnumber }}</td>
                                        <td>{{ v.kennzeichen || '–' }}</td>
                                        <td>{{ v.fahrzeugtyp || '–' }}</td>
                                        <td class="text-right">{{ fmtH(v.actual_minutes) }}h</td>
                                        <td class="text-right text-medium-emphasis">{{ fmtH(v.planned_minutes) }}h</td>
                                        <td class="text-center">
                                            <v-chip size="x-small" :color="effChipColor(v.planned_minutes, v.actual_minutes)" variant="tonal">
                                                {{ calcEff(v.planned_minutes, v.actual_minutes) }}%
                                            </v-chip>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Top-Tätigkeiten -->
                <v-col cols="12" lg="5">
                    <v-card v-if="myData.top_tasks && myData.top_tasks.length" variant="outlined" class="h-100">
                        <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                            <v-icon start size="small">mdi-star-outline</v-icon>
                            {{ t('ReportsView.topTasks') }}
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="pa-0">
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th>{{ t('ReportsView.task') }}</th>
                                        <th class="text-right">{{ t('ReportsView.count') }}</th>
                                        <th class="text-right">{{ t('ReportsView.avgTime') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="task in myData.top_tasks" :key="task.description">
                                        <td class="text-truncate" style="max-width: 200px">{{ task.description }}</td>
                                        <td class="text-right">{{ task.times_done }}x</td>
                                        <td class="text-right">{{ fmtH(task.avg_actual) }}h</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </div>

        <!-- ═══════════════════════════════════════════════
             TEAM-AUSWERTUNG
             ═══════════════════════════════════════════════ -->
        <div v-if="activeTab === 'team' && isManager">

            <!-- Team-KPIs -->
            <v-row class="mb-4">
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.teamHours') }}</div>
                        <div class="text-h4 font-weight-bold mt-1 text-primary">{{ fmtH(teamData.totals?.total_actual) }}</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.productive') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.teamPlanned') }}</div>
                        <div class="text-h4 font-weight-bold mt-1">{{ fmtH(teamData.totals?.total_planned) }}</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.planned') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.teamEfficiency') }}</div>
                        <div class="text-h4 font-weight-bold mt-1" :class="effColor(teamEfficiency)">{{ teamEfficiency }}%</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.plannedVsActual') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.teamUtilization') }}</div>
                        <div class="text-h4 font-weight-bold mt-1" :class="utilColor(teamUtilization)">{{ teamUtilization }}%</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.ofAvailable') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.totalOrders') }}</div>
                        <div class="text-h4 font-weight-bold mt-1 text-success">{{ teamData.totals?.total_orders || 0 }}</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.orders') }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-card variant="outlined" class="text-center pa-3 h-100">
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.mechanics') }}</div>
                        <div class="text-h4 font-weight-bold mt-1">{{ teamData.mechanic_count || 0 }}</div>
                        <div class="text-caption text-medium-emphasis">{{ t('ReportsView.active') }}</div>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Mechaniker-Vergleich (horizontal bar) -->
            <v-card variant="outlined" class="mb-4">
                <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                    <v-icon start size="small">mdi-account-group</v-icon>
                    {{ t('ReportsView.mechanicComparison') }}
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <Bar v-if="teamCompChart.labels.length" :data="teamCompChart" :options="teamCompOpts" />
                    <div v-else class="text-center text-medium-emphasis py-6">{{ t('ReportsView.noData') }}</div>
                </v-card-text>
            </v-card>

            <!-- Tagesübersicht Team (stacked bar) -->
            <v-card variant="outlined" class="mb-4">
                <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                    <v-icon start size="small">mdi-calendar-clock</v-icon>
                    {{ t('ReportsView.dailyTeamHours') }}
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <Bar v-if="teamDailyChart.labels.length" :data="teamDailyChart" :options="teamDailyOpts" />
                    <div v-else class="text-center text-medium-emphasis py-6">{{ t('ReportsView.noData') }}</div>
                </v-card-text>
            </v-card>

            <!-- Detail-Tabelle Mechaniker -->
            <v-card v-if="teamData.team && teamData.team.length" variant="outlined" class="mb-4">
                <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                    {{ t('ReportsView.detailedStats') }}
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-0">
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th style="width:36px"></th>
                                <th>{{ t('ReportsView.mechanic') }}</th>
                                <th class="text-right">{{ t('ReportsView.actual') }}</th>
                                <th class="text-right">{{ t('ReportsView.planned') }}</th>
                                <th class="text-center">{{ t('ReportsView.efficiency') }}</th>
                                <th class="text-center">{{ t('ReportsView.utilization') }}</th>
                                <th class="text-right">{{ t('ReportsView.tasksDone') }}</th>
                                <th class="text-right">{{ t('ReportsView.ordersWorked') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(m, idx) in teamData.team" :key="m.employee_id">
                                <td class="text-center">
                                    <v-icon v-if="idx === 0" color="amber" size="small">mdi-trophy</v-icon>
                                    <v-icon v-else-if="idx === 1" color="grey-lighten-1" size="small">mdi-trophy-outline</v-icon>
                                    <v-icon v-else-if="idx === 2" color="brown-lighten-1" size="small">mdi-trophy-outline</v-icon>
                                    <span v-else class="text-medium-emphasis text-caption">{{ idx + 1 }}</span>
                                </td>
                                <td class="font-weight-medium">{{ m.employee_name }}</td>
                                <td class="text-right font-weight-bold">{{ fmtH(m.actual_minutes) }}h</td>
                                <td class="text-right text-medium-emphasis">{{ fmtH(m.planned_minutes) }}h</td>
                                <td class="text-center">
                                    <v-chip size="x-small" :color="effChipColor(m.planned_minutes, m.actual_minutes)" variant="tonal">
                                        {{ calcEff(m.planned_minutes, m.actual_minutes) }}%
                                    </v-chip>
                                </td>
                                <td class="text-center">
                                    <v-chip size="x-small" :color="utilChipColor(m.actual_minutes)" variant="tonal">
                                        {{ calcUtil(m.actual_minutes) }}%
                                    </v-chip>
                                </td>
                                <td class="text-right">{{ m.done_count }}/{{ m.total_count }}</td>
                                <td class="text-right">{{ m.order_count }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card-text>
            </v-card>

            <v-row>
                <!-- Fahrzeug-Zeiten -->
                <v-col cols="12" lg="7">
                    <v-card v-if="teamData.vehicles && teamData.vehicles.length" variant="outlined" class="h-100">
                        <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                            <v-icon start size="small">mdi-car-clock</v-icon>
                            {{ t('ReportsView.timePerVehicle') }}
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="pa-0">
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th>{{ t('ReportsView.order') }}</th>
                                        <th>{{ t('ReportsView.licensePlate') }}</th>
                                        <th>{{ t('ReportsView.vehicleType') }}</th>
                                        <th class="text-right">{{ t('ReportsView.actual') }}</th>
                                        <th class="text-right">{{ t('ReportsView.planned') }}</th>
                                        <th>{{ t('ReportsView.mechanics') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="v in teamData.vehicles" :key="v.ordnumber">
                                        <td class="font-weight-medium">{{ v.ordnumber }}</td>
                                        <td>{{ v.kennzeichen || '–' }}</td>
                                        <td>{{ v.fahrzeugtyp || '–' }}</td>
                                        <td class="text-right font-weight-bold">{{ fmtH(v.actual_minutes) }}h</td>
                                        <td class="text-right text-medium-emphasis">{{ fmtH(v.planned_minutes) }}h</td>
                                        <td class="text-caption">{{ v.mechanics }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Top-Tätigkeiten Team -->
                <v-col cols="12" lg="5">
                    <v-card v-if="teamData.top_tasks && teamData.top_tasks.length" variant="outlined" class="h-100">
                        <v-card-title class="py-2 px-4 bg-grey-lighten-4 text-subtitle-1">
                            <v-icon start size="small">mdi-format-list-numbered</v-icon>
                            {{ t('ReportsView.topTasksTeam') }}
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="pa-0">
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th>{{ t('ReportsView.task') }}</th>
                                        <th class="text-right">{{ t('ReportsView.count') }}</th>
                                        <th class="text-right">{{ t('ReportsView.avgTime') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="task in teamData.top_tasks" :key="task.description">
                                        <td class="text-truncate" style="max-width: 200px">{{ task.description }}</td>
                                        <td class="text-right">{{ task.times_done }}x</td>
                                        <td class="text-right">{{ fmtH(task.avg_actual) }}h</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </div>
    </v-container>
</template>

<script>
import { defineComponent, ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { Bar } from 'vue-chartjs'
import {
    Chart as ChartJS, Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale
} from 'chart.js'
import axios from 'axios'

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale)

const COLORS = ['#2196F3', '#4CAF50', '#FF9800', '#9C27B0', '#00BCD4', '#F44336', '#3F51B5', '#8BC34A', '#FF5722', '#607D8B']

export default defineComponent({
    name: 'ReportsView',
    components: { Bar, NavbarView },

    setup() {
        const { t, locale } = useI18n()
        const oserp = oserpStore()

        const activeTab = ref('my')
        const period = ref('week')
        const refDate = ref(todayStr())
        const loading = ref(false)
        const myData = ref({})
        const teamData = ref({})

        // ─── Werkstattleiter? ───
        const isManager = computed(() => {
            const val = oserp.session?.company_config?.defaults_oserp?.lxcars_werkstattleitung_group
            const groupId = val ? parseInt(val) : null
            if (!groupId) return false
            const group = (oserp.session?.auth_groups || []).find(g => parseInt(g.id) === groupId)
            if (!group || !group.logins) return false
            return group.logins.includes(oserp.session?.user)
        })

        // ─── Config ───
        function getTimerConfig() {
            const d = oserp.session?.company_config?.defaults_oserp || {}
            return {
                arbeitsbeginn: d.lxcars_arbeitsbeginn || '08:00',
                arbeitsende: d.lxcars_arbeitsende || '17:00',
                pausen: d.lxcars_pausen || ''
            }
        }

        // ─── Datum-Helpers ───
        function todayStr() { return new Date().toISOString().slice(0, 10) }
        const isToday = computed(() => refDate.value === todayStr())

        const dateLabel = computed(() => {
            const d = new Date(refDate.value + 'T12:00:00')
            if (period.value === 'day') return d.toLocaleDateString(locale.value, { weekday: 'long', day: 'numeric', month: 'long' })
            if (period.value === 'week') {
                const mon = new Date(d); const dow = mon.getDay() || 7; mon.setDate(mon.getDate() - dow + 1)
                const sun = new Date(mon); sun.setDate(sun.getDate() + 6)
                return mon.toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) + ' – ' +
                    sun.toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' })
            }
            return d.toLocaleDateString(locale.value, { month: 'long', year: 'numeric' })
        })

        function navigateDate(dir) {
            const d = new Date(refDate.value + 'T12:00:00')
            if (period.value === 'day') d.setDate(d.getDate() + dir)
            else if (period.value === 'week') d.setDate(d.getDate() + dir * 7)
            else d.setMonth(d.getMonth() + dir)
            refDate.value = d.toISOString().slice(0, 10)
        }

        // ─── Format-Helpers ───
        function fmtH(min) {
            if (!min) return '0'
            const h = (parseInt(min) / 60).toFixed(1)
            return h.endsWith('.0') ? h.slice(0, -2) : h
        }

        function calcEff(planned, actual) {
            const p = parseInt(planned) || 0; const a = parseInt(actual) || 1
            if (p === 0) return '–'
            return Math.round((p / a) * 100)
        }

        function effChipColor(planned, actual) {
            const e = calcEff(planned, actual)
            if (e === '–') return 'grey'
            if (e >= 95) return 'success'
            if (e >= 75) return 'primary'
            return 'warning'
        }

        function effColor(val) {
            if (val >= 95) return 'text-success'
            if (val >= 75) return 'text-primary'
            if (val > 0) return 'text-warning'
            return ''
        }

        function utilColor(val) {
            if (val >= 80) return 'text-success'
            if (val >= 60) return 'text-primary'
            if (val > 0) return 'text-warning'
            return ''
        }

        function calcUtil(actualMinutes) {
            const avail = parseInt(teamData.value.available_total) || 1
            return Math.round((parseInt(actualMinutes) || 0) / avail * 100)
        }

        function utilChipColor(actualMinutes) {
            const u = calcUtil(actualMinutes)
            if (u >= 80) return 'success'
            if (u >= 60) return 'primary'
            return 'warning'
        }

        // ─── Computed KPIs ───
        const myUtilization = computed(() => {
            const actual = parseInt(myData.value.summary?.total_actual) || 0
            const avail = parseInt(myData.value.available_total) || 1
            return Math.round((actual / avail) * 100)
        })

        const myEfficiency = computed(() => {
            const planned = parseInt(myData.value.summary?.total_planned) || 0
            const actual = parseInt(myData.value.summary?.total_actual) || 1
            if (!planned) return 0
            return Math.round((planned / actual) * 100)
        })

        const teamEfficiency = computed(() => {
            const p = parseInt(teamData.value.totals?.total_planned) || 0
            const a = parseInt(teamData.value.totals?.total_actual) || 1
            if (!p) return 0
            return Math.round((p / a) * 100)
        })

        const teamUtilization = computed(() => {
            const actual = parseInt(teamData.value.totals?.total_actual) || 0
            const mc = parseInt(teamData.value.mechanic_count) || 1
            const avail = parseInt(teamData.value.available_total) || 1
            return Math.round((actual / (avail * mc)) * 100)
        })

        // ─── API Calls ───
        async function loadMyReport() {
            const emp = oserp.session?.logged_in_employee
            if (!emp?.id) return
            loading.value = true
            try {
                const cfg = getTimerConfig()
                const res = await axios.post('/api/lxcars/', {
                    action: 'getMyReport', employee_id: emp.id,
                    period: period.value, date: refDate.value, ...cfg
                })
                if (res.data.success) myData.value = res.data.payload
            } catch (e) { console.error('My report error:', e) }
            finally { loading.value = false }
        }

        async function loadTeamReport() {
            if (!isManager.value) return
            loading.value = true
            try {
                const cfg = getTimerConfig()
                const res = await axios.post('/api/lxcars/', {
                    action: 'getTeamReport',
                    period: period.value, date: refDate.value, ...cfg
                })
                if (res.data.success) teamData.value = res.data.payload
            } catch (e) { console.error('Team report error:', e) }
            finally { loading.value = false }
        }

        async function loadData() {
            if (activeTab.value === 'my') await loadMyReport()
            else await loadTeamReport()
        }

        onMounted(loadData)
        watch([period, refDate, activeTab], loadData)

        // ─── Charts: Meine Tagesübersicht ───
        const myDailyChart = computed(() => {
            const daily = myData.value.daily || []
            const avail = parseInt(myData.value.available_minutes_per_day) || 480
            return {
                labels: daily.map(d => {
                    const dt = new Date(d.work_date + 'T12:00:00')
                    return period.value === 'month'
                        ? dt.getDate() + '.'
                        : dt.toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric' })
                }),
                datasets: [
                    {
                        label: t('ReportsView.actual'),
                        data: daily.map(d => (parseInt(d.actual_minutes) || 0) / 60),
                        backgroundColor: daily.map(d => {
                            const h = (parseInt(d.actual_minutes) || 0) / 60
                            const target = avail / 60
                            if (h >= target * 0.85) return '#4CAF50'
                            if (h >= target * 0.5) return '#2196F3'
                            if (h > 0) return '#FF9800'
                            return '#E0E0E0'
                        }),
                        borderRadius: 6, borderSkipped: false
                    },
                    {
                        label: t('ReportsView.planned'),
                        data: daily.map(d => (parseInt(d.planned_minutes) || 0) / 60),
                        backgroundColor: 'rgba(0,0,0,0.08)',
                        borderRadius: 6, borderSkipped: false
                    }
                ]
            }
        })

        const dailyOpts = {
            responsive: true, maintainAspectRatio: true, aspectRatio: 2.5,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12 } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + 'h' } }
            },
            scales: { y: { beginAtZero: true, ticks: { callback: v => v + 'h' } } }
        }

        // ─── Charts: Team-Vergleich (horizontal) ───
        const teamCompChart = computed(() => {
            const team = teamData.value.team || []
            return {
                labels: team.map(m => m.employee_name),
                datasets: [
                    {
                        label: t('ReportsView.actual'),
                        data: team.map(m => (parseInt(m.actual_minutes) || 0) / 60),
                        backgroundColor: '#2196F3', borderRadius: 6, borderSkipped: false
                    },
                    {
                        label: t('ReportsView.planned'),
                        data: team.map(m => (parseInt(m.planned_minutes) || 0) / 60),
                        backgroundColor: 'rgba(0,0,0,0.08)', borderRadius: 6, borderSkipped: false
                    }
                ]
            }
        })

        const teamCompOpts = {
            responsive: true, maintainAspectRatio: true, aspectRatio: 2,
            indexAxis: 'y',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12 } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.parsed.x.toFixed(1) + 'h' } }
            },
            scales: { x: { beginAtZero: true, ticks: { callback: v => v + 'h' } } }
        }

        // ─── Charts: Team-Tagesübersicht (stacked) ───
        const teamDailyChart = computed(() => {
            const dbe = teamData.value.daily_by_employee || []
            if (!dbe.length) return { labels: [], datasets: [] }

            // Unique Tage und Mitarbeiter
            const dates = [...new Set(dbe.map(r => r.work_date))].sort()
            const employees = [...new Set(dbe.map(r => r.employee_name))]

            const datasets = employees.map((name, idx) => ({
                label: name,
                data: dates.map(d => {
                    const row = dbe.find(r => r.employee_name === name && r.work_date === d)
                    return (parseInt(row?.actual_minutes) || 0) / 60
                }),
                backgroundColor: COLORS[idx % COLORS.length],
                borderRadius: 4, borderSkipped: false
            }))

            return {
                labels: dates.map(d => {
                    const dt = new Date(d + 'T12:00:00')
                    return dt.toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric' })
                }),
                datasets
            }
        })

        const teamDailyOpts = {
            responsive: true, maintainAspectRatio: true, aspectRatio: 2.5,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12 } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + 'h' } }
            },
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true, ticks: { callback: v => v + 'h' } }
            }
        }

        return {
            t, activeTab, period, refDate, loading, myData, teamData,
            isManager, isToday, dateLabel, todayStr, navigateDate,
            fmtH, calcEff, effChipColor, effColor, utilColor, calcUtil, utilChipColor,
            myUtilization, myEfficiency, teamEfficiency, teamUtilization,
            myDailyChart, dailyOpts,
            teamCompChart, teamCompOpts,
            teamDailyChart, teamDailyOpts
        }
    }
})
</script>
