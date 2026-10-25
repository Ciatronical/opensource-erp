<!-- src/core/views/hr/tabs/hr.performance.tab.vue -->
<!--
    Personal → Leistung (nur mit LxCars): Leistungsdaten der Mechaniker.

    Links die Mechaniker mit Kurzverlauf, rechts entweder der Vergleich
    aller („Alle") oder die Einzelauswertung. Alle Zahlen kommen mit EINEM
    Aufruf für alle Mechaniker, der Wechsel zwischen ihnen lädt nichts nach.

    Kennzahlen:
    - Leistungsgrad  = Vorgabezeit / Ist-Zeit (über 100 % = schneller als Vorgabe)
    - Auslastung     = Ist-Zeit / verfügbare Zeit (Arbeitstage abzüglich
                       genehmigtem Urlaub × Nettominuten je Tag aus der Konfiguration)
    - Arbeitslohn    = Dienstleistungspositionen der Aufträge, anteilig nach Ist-Minuten

    Plausibilität: Tage über der Netto-Arbeitszeit und auffällige Einzelzeiten
    (hr.performance.checks.vue). Aufträge sind überall anklickbar.
-->
<template>
    <div class="perf">
        <!-- Zeitraum -->
        <div class="perf__toolbar">
            <v-btn-toggle v-model="period" mandatory density="compact" color="primary" variant="outlined" divided>
                <v-btn value="week" size="small">{{ t('HrView.performance.period.week') }}</v-btn>
                <v-btn value="month" size="small">{{ t('HrView.performance.period.month') }}</v-btn>
                <v-btn value="quarter" size="small">{{ t('HrView.performance.period.quarter') }}</v-btn>
                <v-btn value="year" size="small">{{ t('HrView.performance.period.year') }}</v-btn>
            </v-btn-toggle>
            <div class="d-flex align-center">
                <v-btn icon="mdi-chevron-left" size="small" variant="text" :aria-label="t('HrView.performance.previous')" @click="shift(-1)" />
                <span class="text-body-2 font-weight-medium perf__range">{{ rangeLabel }}</span>
                <v-btn icon="mdi-chevron-right" size="small" variant="text" :disabled="isCurrent" :aria-label="t('HrView.performance.next')" @click="shift(1)" />
                <v-btn v-if="!isCurrent" variant="text" size="small" color="primary" @click="goToday">
                    {{ t('HrView.performance.today') }}
                </v-btn>
            </div>
            <v-spacer />
            <span class="text-caption text-medium-emphasis">
                {{ t('HrView.performance.comparedTo', { range: prevRangeLabel }) }}
            </span>
            <v-progress-circular v-if="loading" indeterminate size="20" width="2" color="primary" />
        </div>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">{{ error }}</v-alert>

        <v-alert v-else-if="!loading && data && !mechanics.length" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('HrView.performance.noMechanics') }}
        </v-alert>

        <div v-if="data" class="perf__layout">
            <!-- ───────── Mechaniker-Liste ───────── -->
            <nav class="perf__list" :aria-label="t('HrView.performance.mechanics')">
                <v-list density="compact" nav class="pa-0" bg-color="transparent">
                    <v-list-item
                        :active="selected === 'all'"
                        color="primary"
                        rounded="lg"
                        @click="select('all')"
                    >
                        <template #prepend>
                            <v-avatar size="34" color="primary" variant="tonal">
                                <v-icon size="20">mdi-account-group</v-icon>
                            </v-avatar>
                        </template>
                        <v-list-item-title class="font-weight-medium">{{ t('HrView.performance.all') }}</v-list-item-title>
                        <v-list-item-subtitle>
                            {{ t('HrView.performance.activeCount', { n: activeMechanics.length }) }}
                        </v-list-item-subtitle>
                        <template #append>
                            <div class="perf__spark">
                                <v-sparkline
                                    :model-value="teamSpark"
                                    :color="C.series1"
                                    :line-width="2"
                                    :smooth="6"
                                    :padding="4"
                                    height="28"
                                    width="72"
                                />
                            </div>
                        </template>
                    </v-list-item>

                    <v-divider class="my-1" />

                    <v-list-item
                        v-for="m in listedMechanics"
                        :key="m.employee_id"
                        :active="selected === m.employee_id"
                        color="primary"
                        rounded="lg"
                        :class="{ 'perf__inactive': !m.done }"
                        @click="select(m.employee_id)"
                    >
                        <template #prepend>
                            <v-badge
                                :model-value="!!m.running"
                                color="success"
                                dot
                                location="bottom end"
                                offset-x="3"
                                offset-y="3"
                            >
                                <v-avatar size="34" :color="m.done ? 'primary' : 'grey'" variant="tonal">
                                    <span class="text-caption font-weight-bold">{{ initials(m.name) }}</span>
                                </v-avatar>
                            </v-badge>
                        </template>
                        <v-list-item-title class="font-weight-medium">{{ m.name }}</v-list-item-title>
                        <v-list-item-subtitle>
                            <template v-if="m.done">
                                {{ fmtHours(m.actual) }} · {{ pct(m.efficiency) }}
                                <span v-if="checkCount[m.employee_id]" class="perf__flag">
                                    <v-icon size="13">mdi-alert-circle-outline</v-icon>{{ checkCount[m.employee_id] }}
                                </span>
                            </template>
                            <template v-else>{{ t('HrView.performance.noActivity') }}</template>
                        </v-list-item-subtitle>
                        <template #append>
                            <div class="perf__spark">
                                <v-sparkline
                                    v-if="m.done"
                                    :model-value="sparkFor(m.employee_id)"
                                    :color="C.series1"
                                    :line-width="2"
                                    :smooth="6"
                                    :padding="4"
                                    height="28"
                                    width="72"
                                />
                            </div>
                        </template>
                    </v-list-item>
                </v-list>

                <v-btn
                    v-if="inactiveCount"
                    variant="text"
                    size="small"
                    class="mt-1"
                    block
                    @click="showInactive = !showInactive"
                >
                    {{ showInactive
                        ? t('HrView.performance.hideInactive')
                        : t('HrView.performance.showInactive', { n: inactiveCount }) }}
                </v-btn>
            </nav>

            <!-- ───────── Inhalt ───────── -->
            <div class="perf__content">

                <!-- ═══════ ALLE ═══════ -->
                <template v-if="selected === 'all'">
                    <div class="perf__kpis">
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.hours') }}</div>
                            <div class="perf__kpi-value">{{ fmtHours(team.actual) }}</div>
                            <div class="perf__kpi-foot" :class="deltaClass(team.hoursDelta)">
                                <v-icon size="14">{{ deltaIcon(team.hoursDelta) }}</v-icon>
                                {{ deltaText(team.hoursDelta) }}
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.efficiency') }}</div>
                            <div class="perf__kpi-value">{{ pct(team.efficiency) }}</div>
                            <div class="perf__kpi-foot" :class="deltaClass(team.efficiencyDelta)">
                                <v-icon size="14">{{ deltaIcon(team.efficiencyDelta) }}</v-icon>
                                {{ deltaPoints(team.efficiencyDelta) }}
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.utilization') }}</div>
                            <div class="perf__kpi-value">{{ pct(team.utilization) }}</div>
                            <div class="perf__kpi-foot text-medium-emphasis">
                                {{ t('HrView.performance.kpi.ofAvailable', { h: fmtHours(team.avail) }) }}
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.done') }}</div>
                            <div class="perf__kpi-value">{{ fmtInt(team.done) }}</div>
                            <div class="perf__kpi-foot text-medium-emphasis">
                                {{ t('HrView.performance.kpi.inOrders', { n: fmtInt(team.orders) }) }}
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.laborRevenue') }}</div>
                            <div class="perf__kpi-value">{{ fmtCurrency(team.revenue) }}</div>
                            <div class="perf__kpi-foot text-medium-emphasis">
                                {{ t('HrView.performance.kpi.perHour', { v: fmtCurrency(team.revenuePerHour) }) }}
                            </div>
                        </div>
                    </div>

                    <section-card v-if="teamInsights.length" :title="t('HrView.performance.insights')" icon="mdi-lightbulb-on-outline">
                        <ul class="perf__insights">
                            <li v-for="(line, i) in teamInsights" :key="i">{{ line }}</li>
                        </ul>
                    </section-card>

                    <performance-checks
                        :rows="checkRows"
                        :threshold="threshold"
                        show-mechanic
                        @select="select"
                    />

                    <div class="perf__grid-2">
                        <section-card :title="t('HrView.performance.matrix.title')" :subtitle="t('HrView.performance.matrix.subtitle')" icon="mdi-scatter-plot">
                            <div class="perf__chart perf__chart--tall">
                                <Bubble v-if="matrixData.datasets[0].data.length" ref="matrixRef" :data="matrixData" :options="matrixOptions" :plugins="[matrixLabelsPlugin, quadrantPlugin]" />
                                <div v-else class="perf__empty">{{ t('HrView.performance.noData') }}</div>
                            </div>
                        </section-card>

                        <section-card :title="t('HrView.performance.compare.title')" :subtitle="t('HrView.performance.compare.subtitle')" icon="mdi-chart-bar">
                            <div class="perf__chart perf__chart--tall">
                                <Bar v-if="compareData.labels.length" :data="compareData" :options="compareOptions" />
                                <div v-else class="perf__empty">{{ t('HrView.performance.noData') }}</div>
                            </div>
                        </section-card>
                    </div>

                    <section-card :title="t('HrView.performance.trend.teamTitle', { unit: bucketLabel })" icon="mdi-chart-timeline-variant">
                        <div class="perf__chart">
                            <Bar v-if="teamTrendData.labels.length" :data="teamTrendData" :options="teamTrendOptions" />
                            <div v-else class="perf__empty">{{ t('HrView.performance.noData') }}</div>
                        </div>
                    </section-card>

                    <section-card :title="t('HrView.performance.ranking.title')" icon="mdi-format-list-numbered" plain>
                        <v-table density="compact" hover class="perf__table">
                            <thead>
                                <tr>
                                    <th class="perf__th-rank">#</th>
                                    <th>{{ t('HrView.performance.ranking.mechanic') }}</th>
                                    <th class="perf__th-spark">{{ t('HrView.performance.ranking.trend') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.kpi.hours') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.ranking.planned') }}</th>
                                    <th class="text-center">{{ t('HrView.performance.kpi.efficiency') }}</th>
                                    <th class="text-center">{{ t('HrView.performance.kpi.utilization') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.ranking.done') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.ranking.orders') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.ranking.revenue') }}</th>
                                    <th class="text-center">{{ t('HrView.performance.ranking.checks') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(m, idx) in activeMechanics" :key="m.employee_id" class="perf__row" @click="select(m.employee_id)">
                                    <td class="text-medium-emphasis">{{ idx + 1 }}</td>
                                    <td class="font-weight-medium perf__name">
                                        {{ m.name }}
                                        <v-chip v-if="m.vacation_days" size="x-small" variant="tonal" class="ml-1">
                                            {{ t('HrView.performance.vacationDays', { n: m.vacation_days }) }}
                                        </v-chip>
                                    </td>
                                    <td>
                                        <v-sparkline :model-value="sparkFor(m.employee_id)" :color="C.series1" :line-width="2" :smooth="6" :padding="3" height="22" width="80" />
                                    </td>
                                    <td class="text-right perf__num">
                                        {{ fmtHours(m.actual) }}
                                        <span class="perf__delta" :class="deltaClass(m.hoursDelta)">{{ deltaText(m.hoursDelta) }}</span>
                                    </td>
                                    <td class="text-right perf__num text-medium-emphasis">{{ fmtHours(m.planned) }}</td>
                                    <td class="text-center">
                                        <v-chip size="x-small" variant="tonal" :color="efficiencyColor(m.efficiency)">
                                            {{ pct(m.efficiency) }}
                                        </v-chip>
                                    </td>
                                    <td class="text-center">
                                        <v-chip size="x-small" variant="tonal" :color="utilizationColor(m.utilization)">
                                            {{ pct(m.utilization) }}
                                        </v-chip>
                                    </td>
                                    <td class="text-right perf__num">{{ fmtInt(m.done) }}</td>
                                    <td class="text-right perf__num">{{ fmtInt(m.orders) }}</td>
                                    <td class="text-right perf__num">{{ fmtCurrency(m.labor_revenue) }}</td>
                                    <td class="text-center">
                                        <v-chip v-if="checkCount[m.employee_id]" size="x-small" variant="tonal" color="warning" prepend-icon="mdi-alert-circle-outline">
                                            {{ checkCount[m.employee_id] }}
                                        </v-chip>
                                        <v-icon v-else size="16" color="success">mdi-check</v-icon>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </section-card>

                    <section-card v-if="spread.length" :title="t('HrView.performance.spread.title')" :subtitle="t('HrView.performance.spread.subtitle')" icon="mdi-school-outline" plain>
                        <v-table density="compact" class="perf__table">
                            <thead>
                                <tr>
                                    <th>{{ t('HrView.performance.spread.task') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.spread.count') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.spread.teamAvg') }}</th>
                                    <th>{{ t('HrView.performance.spread.fastest') }}</th>
                                    <th>{{ t('HrView.performance.spread.slowest') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.spread.factor') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in spread" :key="s.description">
                                    <td class="perf__task" :title="s.description">{{ s.description }}</td>
                                    <td class="text-right perf__num">{{ fmtInt(s.n) }}</td>
                                    <td class="text-right perf__num">{{ fmtMinutes(s.team_avg) }}</td>
                                    <td class="perf__name">
                                        <button type="button" class="perf__link" @click="select(s.fastest.employee_id)">{{ s.fastest.name }}</button>
                                        <span class="text-medium-emphasis"> · {{ fmtMinutes(s.fastest.avg) }}</span>
                                    </td>
                                    <td class="perf__name">
                                        <button type="button" class="perf__link" @click="select(s.slowest.employee_id)">{{ s.slowest.name }}</button>
                                        <span class="text-medium-emphasis"> · {{ fmtMinutes(s.slowest.avg) }}</span>
                                    </td>
                                    <td class="text-right perf__num">{{ fmtFactor(s.ratio) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </section-card>
                </template>

                <!-- ═══════ EINZELNER MECHANIKER ═══════ -->
                <template v-else-if="current">
                    <div class="perf__head">
                        <v-avatar size="44" color="primary" variant="tonal">
                            <span class="text-subtitle-2 font-weight-bold">{{ initials(current.name) }}</span>
                        </v-avatar>
                        <div>
                            <div class="text-h6">{{ current.name }}</div>
                            <div class="text-caption text-medium-emphasis">
                                <template v-if="current.rank">
                                    {{ t('HrView.performance.rankOf', { rank: current.rank, n: activeMechanics.length }) }}
                                    <template v-if="current.active_days"> · {{ t('HrView.performance.activeDays', { n: current.active_days }) }}</template>
                                </template>
                                <template v-else>{{ t('HrView.performance.noActivity') }}</template>
                            </div>
                        </div>
                        <v-spacer />
                        <v-chip
                            v-if="current.running"
                            color="success"
                            variant="tonal"
                            prepend-icon="mdi-timer-play-outline"
                            class="perf__running"
                            :to="orderRoute(current.running.oe_id)"
                        >
                            {{ t('HrView.performance.workingOn', {
                                task: current.running.description,
                                order: current.running.ordnumber,
                                since: fmtTime(current.running.started_at)
                            }) }}
                        </v-chip>
                    </div>

                    <div class="perf__kpis">
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.hours') }}</div>
                            <div class="perf__kpi-value">{{ fmtHours(current.actual) }}</div>
                            <div class="perf__kpi-foot" :class="deltaClass(current.hoursDelta)">
                                <v-icon size="14">{{ deltaIcon(current.hoursDelta) }}</v-icon>
                                {{ deltaText(current.hoursDelta) }}
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.efficiency') }}</div>
                            <div class="perf__kpi-value">{{ pct(current.efficiency) }}</div>
                            <div class="perf__kpi-foot" :class="deltaClass(current.efficiencyDelta)">
                                <v-icon size="14">{{ deltaIcon(current.efficiencyDelta) }}</v-icon>
                                {{ deltaPoints(current.efficiencyDelta) }}
                                <span class="text-medium-emphasis"> · {{ t('HrView.performance.kpi.team', { v: pct(team.efficiency) }) }}</span>
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.utilization') }}</div>
                            <div class="perf__kpi-value">{{ pct(current.utilization) }}</div>
                            <div class="perf__kpi-foot text-medium-emphasis">
                                {{ t('HrView.performance.kpi.ofAvailable', { h: fmtHours(current.avail_minutes) }) }}
                                <template v-if="current.vacation_days"> · {{ t('HrView.performance.vacationDays', { n: current.vacation_days }) }}</template>
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.done') }}</div>
                            <div class="perf__kpi-value">{{ fmtInt(current.done) }}</div>
                            <div class="perf__kpi-foot text-medium-emphasis">
                                {{ t('HrView.performance.kpi.inOrders', { n: fmtInt(current.orders) }) }}
                            </div>
                        </div>
                        <div class="perf__kpi">
                            <div class="perf__kpi-label">{{ t('HrView.performance.kpi.laborRevenue') }}</div>
                            <div class="perf__kpi-value">{{ fmtCurrency(current.labor_revenue) }}</div>
                            <div class="perf__kpi-foot text-medium-emphasis">
                                {{ t('HrView.performance.kpi.perHour', { v: fmtCurrency(current.revenuePerHour) }) }}
                            </div>
                        </div>
                    </div>

                    <section-card v-if="currentInsights.length" :title="t('HrView.performance.insights')" icon="mdi-lightbulb-on-outline">
                        <ul class="perf__insights">
                            <li v-for="(line, i) in currentInsights" :key="i">{{ line }}</li>
                        </ul>
                    </section-card>

                    <performance-checks
                        :rows="currentChecks"
                        :threshold="threshold"
                    />

                    <div class="perf__grid-2">
                        <section-card :title="t('HrView.performance.trend.hoursTitle', { unit: bucketLabel })" :subtitle="t('HrView.performance.trend.hoursSubtitle')" icon="mdi-chart-bar">
                            <div class="perf__chart">
                                <Bar v-if="hoursData.labels.length" :data="hoursData" :options="hoursOptions" :plugins="[baselinePlugin]" />
                                <div v-else class="perf__empty">{{ t('HrView.performance.noData') }}</div>
                            </div>
                        </section-card>
                        <section-card :title="t('HrView.performance.trend.efficiencyTitle', { unit: bucketLabel })" :subtitle="t('HrView.performance.trend.efficiencySubtitle')" icon="mdi-speedometer">
                            <div class="perf__chart">
                                <Bar v-if="efficiencyData.labels.length" :data="efficiencyData" :options="efficiencyOptions" :plugins="[baselinePlugin]" />
                                <div v-else class="perf__empty">{{ t('HrView.performance.noData') }}</div>
                            </div>
                        </section-card>
                    </div>

                    <div class="perf__grid-2">
                        <section-card :title="t('HrView.performance.benchmark.title')" :subtitle="t('HrView.performance.benchmark.subtitle')" icon="mdi-swap-horizontal">
                            <div class="perf__chart perf__chart--tall">
                                <Bar v-if="benchmarkData.labels.length" :data="benchmarkData" :options="benchmarkOptions" />
                                <div v-else class="perf__empty">{{ t('HrView.performance.benchmark.empty') }}</div>
                            </div>
                        </section-card>

                        <section-card :title="t('HrView.performance.heatmap.title')" :subtitle="t('HrView.performance.heatmap.subtitle')" icon="mdi-view-grid-outline">
                            <div v-if="heatmap.max" class="perf__heat" :style="{ gridTemplateColumns: `auto repeat(${heatmap.hours.length}, 1fr)` }">
                                <div></div>
                                <div v-for="h in heatmap.hours" :key="'h' + h" class="perf__heat-axis">{{ h }}</div>
                                <template v-for="row in heatmap.rows" :key="row.dow">
                                    <div class="perf__heat-axis perf__heat-axis--row">{{ row.label }}</div>
                                    <div
                                        v-for="cell in row.cells"
                                        :key="row.dow + '-' + cell.hour"
                                        class="perf__heat-cell"
                                        :style="{ background: heatColor(cell.cnt) }"
                                    >
                                        <v-tooltip activator="parent" location="top">
                                            {{ t('HrView.performance.heatmap.tooltip', { day: row.label, hour: cell.hour, n: cell.cnt, min: fmtMinutes(cell.minutes) }) }}
                                        </v-tooltip>
                                    </div>
                                </template>
                            </div>
                            <div v-else class="perf__empty">{{ t('HrView.performance.noData') }}</div>
                        </section-card>
                    </div>

                    <div ref="ordersAnchor">
                        <section-card :title="t('HrView.performance.orders.title')" :subtitle="t('HrView.performance.orders.subtitle')" icon="mdi-car-wrench" plain>
                            <template #actions>
                                <v-chip
                                    v-if="orderBucket"
                                    size="small"
                                    color="primary"
                                    variant="tonal"
                                    closable
                                    @click:close="orderBucket = null"
                                >
                                    {{ t('HrView.performance.orders.onlyBucket', { v: orderBucketLabel }) }}
                                </v-chip>
                            </template>
                            <v-table v-if="currentOrders.length" density="compact" hover class="perf__table">
                                <thead>
                                    <tr>
                                        <th>{{ t('HrView.performance.orders.date') }}</th>
                                        <th>{{ t('HrView.performance.orders.order') }}</th>
                                        <th>{{ t('HrView.performance.orders.vehicle') }}</th>
                                        <th>{{ t('HrView.performance.orders.work') }}</th>
                                        <th class="text-right">{{ t('HrView.performance.legend.actual') }}</th>
                                        <th class="text-right">{{ t('HrView.performance.legend.planned') }}</th>
                                        <th class="perf__th-flag"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="o in visibleOrders" :key="o.oe_id" class="perf__row" @click="openOrder(o.oe_id)">
                                        <td class="perf__num">{{ fmtDay(o.day) }}</td>
                                        <td class="perf__name">
                                            <router-link :to="orderRoute(o.oe_id)" class="perf__link" @click.stop>{{ o.ordnumber || o.oe_id }}</router-link>
                                        </td>
                                        <td class="perf__name">
                                            {{ o.kennzeichen || '–' }}
                                            <div v-if="o.customer" class="text-caption text-medium-emphasis perf__customer">{{ o.customer }}</div>
                                        </td>
                                        <td class="perf__task" :title="o.tasks.join('\n')">
                                            {{ o.tasks.join(' · ') }}
                                        </td>
                                        <td class="text-right perf__num">{{ fmtMinutes(o.actual) }}</td>
                                        <td class="text-right perf__num text-medium-emphasis">{{ fmtMinutes(o.planned) }}</td>
                                        <td class="text-center">
                                            <v-icon v-if="o.flags" size="16" color="warning">mdi-alert-circle-outline</v-icon>
                                            <v-tooltip v-if="o.flags" activator="parent" location="left">
                                                {{ t('HrView.performance.orders.flagged', { n: o.flags }) }}
                                            </v-tooltip>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div v-else class="perf__empty pa-4">{{ t('HrView.performance.noData') }}</div>
                            <div v-if="currentOrders.length > ORDER_LIMIT" class="pa-2 text-center">
                                <v-btn variant="text" size="small" @click="allOrders = !allOrders">
                                    {{ allOrders ? t('HrView.performance.tasks.showLess') : t('HrView.performance.orders.showAll', { n: currentOrders.length }) }}
                                </v-btn>
                            </div>
                        </section-card>
                    </div>

                    <section-card :title="t('HrView.performance.tasks.title')" :subtitle="t('HrView.performance.tasks.subtitle')" icon="mdi-wrench-outline" plain>
                        <v-table density="compact" class="perf__table">
                            <thead>
                                <tr>
                                    <th class="perf__th-rank"></th>
                                    <th>{{ t('HrView.performance.tasks.task') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.tasks.count') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.tasks.total') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.tasks.avgActual') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.tasks.avgPlanned') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.tasks.teamAvg') }}</th>
                                    <th class="text-right">{{ t('HrView.performance.tasks.vsTeam') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="task in visibleTasks" :key="task.description">
                                    <!-- Eine Tätigkeit in genau einem Auftrag öffnet ihn direkt,
                                         sonst klappt die Zeile die einzelnen Aufträge auf -->
                                    <tr class="perf__row" :class="{ 'perf__row--open': expandedTask === task.description }" @click="onTaskClick(task)">
                                        <td class="text-center">
                                            <v-icon size="16" class="text-medium-emphasis">
                                                {{ task.orderCount === 1 ? 'mdi-open-in-new' : (expandedTask === task.description ? 'mdi-chevron-up' : 'mdi-chevron-down') }}
                                            </v-icon>
                                        </td>
                                        <td class="perf__task" :title="task.description">
                                            {{ task.description }}
                                            <span v-if="task.orderCount === 1" class="text-caption text-medium-emphasis"> · {{ orderOf(task.oeIds[0]).ordnumber || task.oeIds[0] }}</span>
                                        </td>
                                        <td class="text-right perf__num">{{ fmtInt(task.n) }}</td>
                                        <td class="text-right perf__num">{{ fmtHours(task.total_actual) }}</td>
                                        <td class="text-right perf__num">{{ fmtMinutes(task.avg_actual) }}</td>
                                        <td class="text-right perf__num text-medium-emphasis">{{ fmtMinutes(task.avg_planned) }}</td>
                                        <td class="text-right perf__num text-medium-emphasis">
                                            {{ task.team_mechanics > 1 ? fmtMinutes(task.team_avg) : '–' }}
                                        </td>
                                        <td class="text-right perf__num" :class="deltaClass(task.deviation)">
                                            {{ task.team_mechanics > 1 ? deltaText(task.deviation) : '–' }}
                                        </td>
                                    </tr>
                                    <tr
                                        v-for="it in (expandedTask === task.description ? taskItems(task.description) : [])"
                                        :key="'i' + it.id"
                                        class="perf__row perf__subrow"
                                        @click="openOrder(it.oe_id)"
                                    >
                                        <td></td>
                                        <td class="perf__task">
                                            <span class="perf__num text-medium-emphasis">{{ fmtDay(it.day) }}</span>
                                            <router-link :to="orderRoute(it.oe_id)" class="perf__link ml-2" @click.stop>{{ orderOf(it.oe_id).ordnumber || it.oe_id }}</router-link>
                                            <span class="ml-2">{{ orderOf(it.oe_id).kennzeichen || '' }}</span>
                                            <span v-if="orderOf(it.oe_id).customer" class="text-caption text-medium-emphasis ml-1">{{ orderOf(it.oe_id).customer }}</span>
                                        </td>
                                        <td></td>
                                        <td></td>
                                        <td class="text-right perf__num">{{ fmtMinutes(it.actual) }}</td>
                                        <td class="text-right perf__num text-medium-emphasis">{{ fmtMinutes(it.planned) }}</td>
                                        <td></td>
                                        <td class="text-center">
                                            <v-icon v-if="it.flag" size="16" color="warning">mdi-alert-circle-outline</v-icon>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </v-table>
                        <div v-if="currentTasks.length > TASK_LIMIT" class="pa-2 text-center">
                            <v-btn variant="text" size="small" @click="allTasks = !allTasks">
                                {{ allTasks ? t('HrView.performance.tasks.showLess') : t('HrView.performance.tasks.showAll', { n: currentTasks.length }) }}
                            </v-btn>
                        </div>
                    </section-card>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useTheme } from 'vuetify'
import { Bar, Bubble } from 'vue-chartjs'
import {
    Chart as ChartJS, Tooltip, Legend, BarElement, PointElement, CategoryScale, LinearScale
} from 'chart.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { hrStore } from '@/core/stores/hr.store.js'
import SectionCard from '@/core/components/layout/section-card.vue'
import PerformanceChecks from './hr.performance.checks.vue'
import { entityRoute } from '@/core/constants/routes.js'

ChartJS.register(Tooltip, Legend, BarElement, PointElement, CategoryScale, LinearScale)

const STORAGE_KEY = 'oserp.hr.performance.period'

// Farbrollen (validierte Palette, je Theme eigene Stufen)
const PALETTE = {
    light: {
        series1: '#2a78d6', series2: '#eb6834', series3: '#1baf7a', series4: '#eda100',
        series5: '#e87ba4', series6: '#008300', series7: '#6250d6', series8: '#e34948',
        neutral: '#c3c2b7', faster: '#2a78d6', slower: '#e34948',
        grid: '#e1e0d9', text: '#52514e', muted: '#898781', surface: '#ffffff',
        heat: ['#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b']
    },
    dark: {
        series1: '#3987e5', series2: '#d95926', series3: '#199e70', series4: '#c98500',
        series5: '#d55181', series6: '#008300', series7: '#9085e9', series8: '#e66767',
        neutral: '#5a5a57', faster: '#3987e5', slower: '#e66767',
        grid: '#2c2c2a', text: '#c3c2b7', muted: '#898781', surface: '#1e1e1e',
        heat: ['#184f95', '#1c5cab', '#256abf', '#2a78d6', '#3987e5', '#6da7ec', '#9ec5f4']
    }
}
const SERIES_SLOTS = ['series1', 'series2', 'series3', 'series4', 'series5', 'series6', 'series7', 'series8']

function pad(n) { return String(n).padStart(2, '0') }
function iso(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` }
function fromIso(s) { return new Date(s + 'T12:00:00') }
function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x }
function startOfWeek(d) { const x = new Date(d); const dow = x.getDay() || 7; x.setDate(x.getDate() - dow + 1); return x }

/**
 * Zeitraum und Vorperiode für Periode + Referenzdatum
 */
function calcRange(period, refDate) {
    const d = fromIso(refDate)
    let from, to, pFrom, pTo, bucket
    if (period === 'week') {
        from = startOfWeek(d); to = addDays(from, 6)
        pFrom = addDays(from, -7); pTo = addDays(to, -7); bucket = 'day'
    } else if (period === 'month') {
        from = new Date(d.getFullYear(), d.getMonth(), 1, 12)
        to = new Date(d.getFullYear(), d.getMonth() + 1, 0, 12)
        pFrom = new Date(d.getFullYear(), d.getMonth() - 1, 1, 12)
        pTo = new Date(d.getFullYear(), d.getMonth(), 0, 12); bucket = 'day'
    } else if (period === 'quarter') {
        const q = Math.floor(d.getMonth() / 3) * 3
        from = new Date(d.getFullYear(), q, 1, 12)
        to = new Date(d.getFullYear(), q + 3, 0, 12)
        pFrom = new Date(d.getFullYear(), q - 3, 1, 12)
        pTo = new Date(d.getFullYear(), q, 0, 12); bucket = 'week'
    } else {
        from = new Date(d.getFullYear(), 0, 1, 12); to = new Date(d.getFullYear(), 11, 31, 12)
        pFrom = new Date(d.getFullYear() - 1, 0, 1, 12); pTo = new Date(d.getFullYear() - 1, 11, 31, 12); bucket = 'month'
    }
    return { from: iso(from), to: iso(to), pFrom: iso(pFrom), pTo: iso(pTo), bucket }
}

/**
 * Alle Raster-Schlüssel (Tag/Wochenanfang/Monatsanfang) im Zeitraum —
 * damit auch leere Abschnitte im Diagramm erscheinen.
 */
function bucketKeys(from, to, bucket) {
    const keys = []
    let d = fromIso(from)
    const end = fromIso(to)
    if (bucket === 'week') d = startOfWeek(d)
    if (bucket === 'month') d = new Date(d.getFullYear(), d.getMonth(), 1, 12)
    while (d <= end) {
        keys.push(iso(d))
        if (bucket === 'day') d = addDays(d, 1)
        else if (bucket === 'week') d = addDays(d, 7)
        else d = new Date(d.getFullYear(), d.getMonth() + 1, 1, 12)
    }
    return keys
}

export default {
    name: 'HrPerformanceTab',
    components: { SectionCard, PerformanceChecks, Bar, Bubble },
    setup() {
        const { t, locale, n } = useI18n()
        const router = useRouter()
        const theme = useTheme()
        const oserp = oserpStore()
        const hr = hrStore()

        const dark = computed(() => theme.global.current.value.dark)
        const C = computed(() => dark.value ? PALETTE.dark : PALETTE.light)

        // ─── Zustand ───
        let storedPeriod = 'month'
        try { storedPeriod = localStorage.getItem(STORAGE_KEY) || 'month' } catch (e) { /* kein Speicher */ }
        const period = ref(['week', 'month', 'quarter', 'year'].includes(storedPeriod) ? storedPeriod : 'month')
        const refDate = ref(iso(new Date()))
        const selected = ref('all')
        const showInactive = ref(false)
        const loading = ref(false)
        const error = ref('')
        const data = ref(null)
        const matrixRef = ref(null)
        const TASK_LIMIT = 15
        const allTasks = ref(false)
        const ORDER_LIMIT = 15
        const allOrders = ref(false)
        const orderBucket = ref(null)
        const ordersAnchor = ref(null)

        const range = computed(() => calcRange(period.value, refDate.value))
        const isCurrent = computed(() => {
            const today = iso(new Date())
            return today >= range.value.from && today <= range.value.to
        })

        function fmtDate(s, opts) { return fromIso(s).toLocaleDateString(locale.value, opts) }
        const rangeLabel = computed(() => {
            const r = range.value
            if (period.value === 'month') return fmtDate(r.from, { month: 'long', year: 'numeric' })
            if (period.value === 'year') return fmtDate(r.from, { year: 'numeric' })
            if (period.value === 'quarter') {
                return t('HrView.performance.quarterLabel', { q: Math.floor(fromIso(r.from).getMonth() / 3) + 1, year: fromIso(r.from).getFullYear() })
            }
            return fmtDate(r.from, { day: 'numeric', month: 'short' }) + ' – ' + fmtDate(r.to, { day: 'numeric', month: 'short', year: 'numeric' })
        })
        const prevRangeLabel = computed(() => {
            const r = range.value
            if (period.value === 'month') return fmtDate(r.pFrom, { month: 'long', year: 'numeric' })
            if (period.value === 'year') return fmtDate(r.pFrom, { year: 'numeric' })
            if (period.value === 'quarter') {
                return t('HrView.performance.quarterLabel', { q: Math.floor(fromIso(r.pFrom).getMonth() / 3) + 1, year: fromIso(r.pFrom).getFullYear() })
            }
            return fmtDate(r.pFrom, { day: 'numeric', month: 'short' }) + ' – ' + fmtDate(r.pTo, { day: 'numeric', month: 'short' })
        })
        const bucketLabel = computed(() => t('HrView.performance.bucket.' + range.value.bucket))

        function shift(dir) {
            const d = fromIso(refDate.value)
            if (period.value === 'week') d.setDate(d.getDate() + dir * 7)
            else if (period.value === 'month') d.setMonth(d.getMonth() + dir, 1)
            else if (period.value === 'quarter') d.setMonth(d.getMonth() + dir * 3, 1)
            else d.setFullYear(d.getFullYear() + dir, 0, 1)
            refDate.value = iso(d)
        }
        function goToday() { refDate.value = iso(new Date()) }
        function select(id) { selected.value = id }

        // ─── Mechaniker aus der konfigurierten Gruppe (Store) ───
        const mechanicIds = computed(() => {
            const d = oserp.session?.company_config?.defaults_oserp || {}
            const groupId = parseInt(d.lxcars_employee_group)
            if (!groupId) return []
            const group = (oserp.session?.auth_groups || []).find(g => parseInt(g.id) === groupId)
            if (!group) return []
            const logins = new Set((group.logins || []).map(l => String(l).trim().toLowerCase()))
            return (oserp.session?.company_config?.employees || [])
                .filter(e => logins.has(String(e.login || '').trim().toLowerCase()))
                .map(e => e.id)
        })

        function timerConfig() {
            const d = oserp.session?.company_config?.defaults_oserp || {}
            return {
                arbeitsbeginn: d.lxcars_arbeitsbeginn || '08:00',
                arbeitsende: d.lxcars_arbeitsende || '17:00',
                pausen: d.lxcars_pausen || ''
            }
        }

        async function load() {
            loading.value = true
            error.value = ''
            try {
                const r = range.value
                data.value = await hr.fetchMechanicPerformance({
                    employee_ids: mechanicIds.value,
                    date_from: r.from, date_to: r.to,
                    prev_from: r.pFrom, prev_to: r.pTo,
                    bucket: r.bucket,
                    ...timerConfig()
                })
            } catch (e) {
                error.value = e?.message || String(e)
            } finally {
                loading.value = false
            }
        }

        onMounted(load)
        watch(range, load)
        watch(period, v => { try { localStorage.setItem(STORAGE_KEY, v) } catch (e) { /* kein Speicher */ } })

        // ─── Aufbereitung ───
        function ratio(a, b) { return b > 0 ? a / b : null }
        function deltaPct(cur, prev) { return prev > 0 ? (cur - prev) / prev : null }

        const mechanics = computed(() => {
            const list = (data.value?.mechanics || []).map(m => {
                const actual = Number(m.actual) || 0
                const planned = Number(m.planned) || 0
                const avail = Number(m.avail_minutes) || 0
                const prevActual = Number(m.prev?.actual) || 0
                const prevPlanned = Number(m.prev?.planned) || 0
                const efficiency = ratio(planned, actual)
                const prevEfficiency = ratio(prevPlanned, prevActual)
                return {
                    ...m,
                    actual, planned,
                    done: Number(m.done) || 0,
                    orders: Number(m.orders) || 0,
                    labor_revenue: Number(m.labor_revenue) || 0,
                    vacation_days: Number(m.vacation_days) || 0,
                    active_days: Number(m.active_days) || 0,
                    avail_minutes: avail,
                    efficiency,
                    utilization: ratio(actual, avail),
                    hoursDelta: deltaPct(actual, prevActual),
                    efficiencyDelta: (efficiency !== null && prevEfficiency !== null) ? efficiency - prevEfficiency : null,
                    revenuePerHour: actual > 0 ? (Number(m.labor_revenue) || 0) / (actual / 60) : 0
                }
            })
            list.sort((a, b) => b.actual - a.actual || a.name.localeCompare(b.name))
            list.forEach((m, i) => { m.rank = m.done ? i + 1 : 0 })
            return list
        })
        const activeMechanics = computed(() => mechanics.value.filter(m => m.done > 0))
        const inactiveCount = computed(() => mechanics.value.length - activeMechanics.value.length)
        const listedMechanics = computed(() => showInactive.value ? mechanics.value : activeMechanics.value)
        const current = computed(() => mechanics.value.find(m => m.employee_id === selected.value) || null)

        // Fällt der gewählte Mechaniker aus der Liste (Zeitraumwechsel), zurück zu „Alle"
        watch(mechanics, list => {
            if (selected.value !== 'all' && !list.some(m => m.employee_id === selected.value)) selected.value = 'all'
        })

        const team = computed(() => {
            const act = activeMechanics.value
            const actual = act.reduce((s, m) => s + m.actual, 0)
            const planned = act.reduce((s, m) => s + m.planned, 0)
            const avail = act.reduce((s, m) => s + m.avail_minutes, 0)
            const prevActual = mechanics.value.reduce((s, m) => s + (Number(m.prev?.actual) || 0), 0)
            const prevPlanned = mechanics.value.reduce((s, m) => s + (Number(m.prev?.planned) || 0), 0)
            const efficiency = ratio(planned, actual)
            const prevEfficiency = ratio(prevPlanned, prevActual)
            const revenue = act.reduce((s, m) => s + m.labor_revenue, 0)
            return {
                actual, planned, avail, revenue,
                done: act.reduce((s, m) => s + m.done, 0),
                orders: act.reduce((s, m) => s + m.orders, 0),
                efficiency,
                utilization: ratio(actual, avail),
                hoursDelta: deltaPct(actual, prevActual),
                efficiencyDelta: (efficiency !== null && prevEfficiency !== null) ? efficiency - prevEfficiency : null,
                revenuePerHour: actual > 0 ? revenue / (actual / 60) : 0
            }
        })

        // Zeitreihe je Mechaniker auf alle Raster-Schlüssel gefüllt
        const keys = computed(() => bucketKeys(range.value.from, range.value.to, range.value.bucket))
        const seriesByEmp = computed(() => {
            const map = {}
            for (const row of (data.value?.series || [])) {
                const id = row.employee_id
                if (!map[id]) map[id] = {}
                map[id][row.bucket] = { actual: Number(row.actual) || 0, planned: Number(row.planned) || 0, done: Number(row.done) || 0 }
            }
            return map
        })
        function seriesFor(id) {
            const s = seriesByEmp.value[id] || {}
            return keys.value.map(k => s[k] || { actual: 0, planned: 0, done: 0 })
        }
        function sparkFor(id) {
            const vals = seriesFor(id).map(b => b.actual / 60)
            return vals.length > 1 ? vals : [0, ...vals]
        }
        const teamSpark = computed(() => {
            const sum = keys.value.map(() => 0)
            for (const m of activeMechanics.value) seriesFor(m.employee_id).forEach((b, i) => { sum[i] += b.actual / 60 })
            return sum.length > 1 ? sum : [0, ...sum]
        })

        const bucketLabels = computed(() => keys.value.map(k => {
            const d = fromIso(k)
            if (range.value.bucket === 'day') {
                return period.value === 'week'
                    ? d.toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric' })
                    : d.toLocaleDateString(locale.value, { day: 'numeric', month: 'numeric' })
            }
            if (range.value.bucket === 'week') return t('HrView.performance.weekShort', { n: isoWeek(d) })
            return d.toLocaleDateString(locale.value, { month: 'short' })
        }))

        function isoWeek(date) {
            const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()))
            const dayNum = d.getUTCDay() || 7
            d.setUTCDate(d.getUTCDate() + 4 - dayNum)
            const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1))
            return Math.ceil((((d - yearStart) / 86400000) + 1) / 7)
        }

        // ─── Tätigkeiten des gewählten Mechanikers ───
        const currentTasks = computed(() => {
            if (!current.value) return []
            return (data.value?.tasks || [])
                .filter(x => x.employee_id === current.value.employee_id)
                .map(x => {
                    const avg = Number(x.avg_actual) || 0
                    const teamAvg = Number(x.team_avg) || 0
                    return {
                        ...x,
                        n: Number(x.n) || 0,
                        total_actual: Number(x.total_actual) || 0,
                        avg_actual: avg,
                        avg_planned: Number(x.avg_planned) || 0,
                        team_avg: teamAvg,
                        team_mechanics: Number(x.team_mechanics) || 0,
                        // positiv = schneller als der Team-Durchschnitt
                        deviation: teamAvg > 0 ? (teamAvg - avg) / teamAvg : null
                    }
                })
                .sort((a, b) => b.n - a.n || b.total_actual - a.total_actual)
                .map(x => {
                    const oeIds = [...new Set(taskItems(x.description).map(i => i.oe_id))]
                    return { ...x, oeIds, orderCount: oeIds.length }
                })
        })

        // Einzelne Anweisungen einer Tätigkeit des gewählten Mechanikers, neueste zuerst
        function taskItems(description) {
            if (!current.value) return []
            return items.value.filter(i => i.employee_id === current.value.employee_id && i.description === description)
        }
        const expandedTask = ref(null)
        function onTaskClick(task) {
            if (task.orderCount === 1) { openOrder(task.oeIds[0]); return }
            expandedTask.value = expandedTask.value === task.description ? null : task.description
        }

        const visibleTasks = computed(() => allTasks.value ? currentTasks.value : currentTasks.value.slice(0, TASK_LIMIT))
        watch(selected, () => { allTasks.value = false; allOrders.value = false; orderBucket.value = null; expandedTask.value = null })
        watch(range, () => { orderBucket.value = null })

        // ─── Plausibilität & Aufträge ───
        // Netto-Arbeitszeit je Tag (Minuten) – Grenze für „zu viele Stunden"
        const threshold = computed(() => Math.max(Number(data.value?.range?.avail_per_day) || 480, 60))
        const orderMap = computed(() => data.value?.orders || {})
        const orderOf = id => orderMap.value[id] || {}
        const nameOf = computed(() => Object.fromEntries(mechanics.value.map(m => [m.employee_id, m.name])))
        const items = computed(() => (data.value?.items || []).map(x => ({
            ...x,
            planned: Number(x.planned) || 0,
            actual: Number(x.actual) || 0,
            ref: x.ref === null ? null : Number(x.ref)
        })))

        // Zeilen der Plausibilitätskarte: Tage über der Arbeitszeit, darunter die
        // auffälligen Anweisungen desselben Tages, neueste zuerst
        const checkRows = computed(() => {
            const rows = []
            for (const d of (data.value?.long_days || [])) {
                rows.push({
                    key: 'd' + d.employee_id + d.day, kind: 'day', day: d.day,
                    employee_id: d.employee_id, name: nameOf.value[d.employee_id] || '',
                    actual: Number(d.minutes) || 0, n: Number(d.n) || 0,
                    orders: (d.oe_ids || []).map(id => ({ oe_id: id, ...orderOf(id) }))
                })
            }
            for (const x of items.value) {
                if (!x.flag) continue
                rows.push({
                    key: 'i' + x.id, kind: x.flag, day: x.day,
                    employee_id: x.employee_id, name: nameOf.value[x.employee_id] || '',
                    oe_id: x.oe_id, order: orderOf(x.oe_id), description: x.description,
                    actual: x.actual, ref: x.ref, ref_source: x.ref_source
                })
            }
            const order = { day: 0, timer: 1, high: 2, low: 3 }
            return rows.sort((a, b) => b.day.localeCompare(a.day)
                || a.name.localeCompare(b.name)
                || order[a.kind] - order[b.kind]
                || b.actual - a.actual)
        })
        const checkCount = computed(() => {
            const c = {}
            checkRows.value.forEach(r => { c[r.employee_id] = (c[r.employee_id] || 0) + 1 })
            return c
        })
        const currentChecks = computed(() => current.value
            ? checkRows.value.filter(r => r.employee_id === current.value.employee_id)
            : [])
        // Minuten aus vermutlich vergessenen Timern (für Hinweis in den Erkenntnissen)
        function timerMinutes(id) {
            return items.value
                .filter(x => x.flag === 'timer' && (id === undefined || x.employee_id === id))
                .reduce((s, x) => s + x.actual, 0)
        }

        // Raster-Schlüssel eines Tages passend zur Zeitreihe (Tag/Woche/Monat)
        function bucketOf(day) {
            if (range.value.bucket === 'week') return iso(startOfWeek(fromIso(day)))
            if (range.value.bucket === 'month') return day.slice(0, 7) + '-01'
            return day
        }
        const orderBucketLabel = computed(() => {
            const k = orderBucket.value
            const i = keys.value.indexOf(k)
            return i >= 0 ? bucketLabels.value[i] : ''
        })

        // Aufträge des gewählten Mechanikers, optional auf einen Diagramm-Abschnitt gefiltert
        const currentOrders = computed(() => {
            if (!current.value) return []
            const id = current.value.employee_id
            const map = new Map()
            for (const x of items.value) {
                if (x.employee_id !== id) continue
                if (orderBucket.value && bucketOf(x.day) !== orderBucket.value) continue
                let o = map.get(x.oe_id)
                if (!o) {
                    o = { oe_id: x.oe_id, ...orderOf(x.oe_id), day: x.day, actual: 0, planned: 0, flags: 0, tasks: [] }
                    map.set(x.oe_id, o)
                }
                if (x.day > o.day) o.day = x.day
                o.actual += x.actual
                o.planned += x.planned
                if (x.flag) o.flags++
                if (!o.tasks.includes(x.description)) o.tasks.push(x.description)
            }
            return [...map.values()].sort((a, b) => b.day.localeCompare(a.day) || b.actual - a.actual)
        })
        const visibleOrders = computed(() => allOrders.value ? currentOrders.value : currentOrders.value.slice(0, ORDER_LIMIT))

        function showBucketOrders(index) {
            const k = keys.value[index]
            if (!k) return
            orderBucket.value = orderBucket.value === k ? null : k
            allOrders.value = false
            ordersAnchor.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
        }

        // Benchmark: nur Tätigkeiten mit Vergleichsbasis (andere Mechaniker, genug Fälle)
        const benchmarkTasks = computed(() => currentTasks.value
            .filter(x => x.team_mechanics > 1 && x.n >= 2 && x.deviation !== null)
            .slice(0, 8))

        const spread = computed(() => (data.value?.spread || []).filter(s => s.fastest && s.slowest && s.fastest.employee_id !== s.slowest.employee_id))

        // ─── Aktivitätsprofil ───
        const heatmap = computed(() => {
            const cfg = timerConfig()
            const h0 = Math.max(0, parseInt(cfg.arbeitsbeginn) || 8)
            const h1 = Math.min(23, (parseInt(cfg.arbeitsende) || 17))
            const rows = []
            const cells = {}
            let max = 0
            let hasSaturday = false
            const src = current.value
                ? (data.value?.heatmap || []).filter(x => x.employee_id === current.value.employee_id)
                : []
            for (const x of src) {
                const key = x.dow + '-' + x.hour
                cells[key] = { cnt: Number(x.cnt) || 0, minutes: Number(x.minutes) || 0 }
                max = Math.max(max, cells[key].cnt)
                if (x.dow === 6) hasSaturday = true
            }
            const hours = []
            for (let h = h0; h <= h1; h++) hours.push(h)
            const names = [1, 2, 3, 4, 5, 6, 7].map(dow => {
                const d = new Date(2024, 0, dow) // 1.1.2024 war ein Montag
                return d.toLocaleDateString(locale.value, { weekday: 'short' })
            })
            for (let dow = 1; dow <= (hasSaturday ? 6 : 5); dow++) {
                rows.push({
                    dow, label: names[dow - 1],
                    cells: hours.map(hour => ({ hour, ...(cells[dow + '-' + hour] || { cnt: 0, minutes: 0 }) }))
                })
            }
            return { rows, hours, max }
        })
        function heatColor(cnt) {
            if (!cnt) return 'transparent'
            const steps = C.value.heat
            const i = Math.min(steps.length - 1, Math.floor((cnt / heatmap.value.max) * (steps.length - 1) + 0.5))
            return steps[i]
        }

        // ─── Formatierung ───
        function fmtHours(min) {
            const h = (Number(min) || 0) / 60
            return n(h, { maximumFractionDigits: h >= 100 ? 0 : 1 }) + ' h'
        }
        function fmtMinutes(min) {
            const v = Number(min) || 0
            if (v >= 90) return fmtHours(v)
            return n(v, { maximumFractionDigits: 0 }) + ' min'
        }
        function fmtInt(v) { return n(Number(v) || 0, { maximumFractionDigits: 0 }) }
        function fmtCurrency(v) { return n(Number(v) || 0, { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }) }
        function fmtFactor(v) { return v ? n(Number(v), { maximumFractionDigits: 1 }) + '×' : '–' }
        function pct(v) { return v === null || v === undefined ? '–' : n(Math.round(v * 100)) + ' %' }
        function fmtTime(ts) {
            if (!ts) return ''
            return new Date(ts).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
        }
        function fmtDay(s) {
            return s ? fromIso(s).toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: '2-digit' }) : ''
        }
        function initials(name) {
            return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('')
        }
        function deltaText(d) {
            if (d === null || d === undefined || !isFinite(d)) return t('HrView.performance.noComparison')
            const v = Math.round(d * 100)
            return (v > 0 ? '+' : '') + n(v) + ' %'
        }
        function deltaPoints(d) {
            if (d === null || d === undefined || !isFinite(d)) return t('HrView.performance.noComparison')
            const v = Math.round(d * 100)
            return t('HrView.performance.points', { v: (v > 0 ? '+' : '') + n(v) })
        }
        function deltaClass(d) {
            if (d === null || d === undefined || !isFinite(d) || Math.abs(d) < 0.005) return 'text-medium-emphasis'
            return d > 0 ? 'perf__up' : 'perf__down'
        }
        function deltaIcon(d) {
            if (d === null || d === undefined || !isFinite(d) || Math.abs(d) < 0.005) return 'mdi-minus'
            return d > 0 ? 'mdi-arrow-up-right' : 'mdi-arrow-down-right'
        }
        function efficiencyColor(e) {
            if (e === null) return 'grey'
            if (e >= 0.95) return 'success'
            if (e >= 0.75) return 'primary'
            return 'warning'
        }
        function utilizationColor(u) {
            if (u === null) return 'grey'
            if (u >= 0.8) return 'success'
            if (u >= 0.6) return 'primary'
            return 'warning'
        }

        // Auftrag in der Faktura öffnen (Ziel aus der zentralen Entitäts-Abbildung)
        function orderRoute(id) { return entityRoute('order', id) }
        function openOrder(id) {
            const target = orderRoute(id)
            if (target) router.push(target)
        }

        // ─── Erkenntnisse (regelbasiert, aus den geladenen Zahlen) ───
        const currentInsights = computed(() => {
            const m = current.value
            if (!m || !m.done) return []
            const out = []
            const lost = timerMinutes(m.employee_id)
            if (lost > 0) {
                out.push(t('HrView.performance.insight.timerForgotten', { h: fmtHours(lost), v: Math.round(lost / m.actual * 100) }))
            }
            if (m.efficiency !== null && team.value.efficiency !== null) {
                const diff = Math.round((m.efficiency - team.value.efficiency) * 100)
                if (Math.abs(diff) >= 5) {
                    out.push(t(diff > 0 ? 'HrView.performance.insight.fasterThanTeam' : 'HrView.performance.insight.slowerThanTeam', { v: Math.abs(diff) }))
                }
            }
            const best = [...benchmarkTasks.value].sort((a, b) => b.deviation - a.deviation)[0]
            const worst = [...benchmarkTasks.value].sort((a, b) => a.deviation - b.deviation)[0]
            if (best && best.deviation >= 0.15) {
                out.push(t('HrView.performance.insight.strength', { task: best.description, v: Math.round(best.deviation * 100) }))
            }
            if (worst && worst.deviation <= -0.15) {
                out.push(t('HrView.performance.insight.weakness', { task: worst.description, v: Math.round(-worst.deviation * 100), min: fmtMinutes(worst.avg_actual), team: fmtMinutes(worst.team_avg) }))
            }
            if (m.hoursDelta !== null && Math.abs(m.hoursDelta) >= 0.2) {
                out.push(t(m.hoursDelta > 0 ? 'HrView.performance.insight.moreHours' : 'HrView.performance.insight.fewerHours', { v: Math.round(Math.abs(m.hoursDelta) * 100) }))
            }
            if (m.utilization !== null && m.utilization < 0.5 && m.avail_minutes > 0) {
                out.push(t('HrView.performance.insight.lowUtilization', { v: Math.round(m.utilization * 100) }))
            }
            const topTask = currentTasks.value[0]
            if (topTask && m.actual > 0 && topTask.total_actual / m.actual >= 0.4) {
                out.push(t('HrView.performance.insight.focus', { task: topTask.description, v: Math.round(topTask.total_actual / m.actual * 100) }))
            }
            return out.slice(0, 4)
        })

        const teamInsights = computed(() => {
            const act = activeMechanics.value
            if (!act.length) return []
            const out = []
            const lost = timerMinutes()
            if (lost > 0 && team.value.actual > 0) {
                out.push(t('HrView.performance.insight.teamTimerForgotten', { h: fmtHours(lost), v: Math.round(lost / team.value.actual * 100) }))
            }
            const byEff = act.filter(m => m.efficiency !== null && m.actual >= 120).sort((a, b) => b.efficiency - a.efficiency)
            if (byEff.length >= 2) {
                out.push(t('HrView.performance.insight.teamBest', { name: byEff[0].name, v: Math.round(byEff[0].efficiency * 100) }))
            }
            const byRev = [...act].sort((a, b) => b.labor_revenue - a.labor_revenue)
            if (byRev[0]?.labor_revenue > 0 && team.value.revenue > 0) {
                out.push(t('HrView.performance.insight.teamRevenue', { name: byRev[0].name, v: Math.round(byRev[0].labor_revenue / team.value.revenue * 100) }))
            }
            if (team.value.hoursDelta !== null && Math.abs(team.value.hoursDelta) >= 0.1) {
                out.push(t(team.value.hoursDelta > 0 ? 'HrView.performance.insight.teamMoreHours' : 'HrView.performance.insight.teamFewerHours', { v: Math.round(Math.abs(team.value.hoursDelta) * 100) }))
            }
            const s = spread.value[0]
            if (s && s.ratio >= 1.5) {
                out.push(t('HrView.performance.insight.teamSpread', { task: s.description, fast: s.fastest.name, slow: s.slowest.name, factor: fmtFactor(s.ratio) }))
            }
            const running = act.filter(m => m.running)
            if (running.length) out.push(t('HrView.performance.insight.running', { n: running.length }))
            return out.slice(0, 4)
        })

        // ─── Diagramm-Grundeinstellungen ───
        const baseOptions = computed(() => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, color: C.value.text, usePointStyle: true } },
                tooltip: { backgroundColor: dark.value ? '#262626' : '#1e1e1e', padding: 10, cornerRadius: 6 }
            },
            scales: {
                x: { grid: { display: false }, border: { color: C.value.grid }, ticks: { color: C.value.muted } },
                y: { grid: { color: C.value.grid }, border: { display: false }, ticks: { color: C.value.muted } }
            }
        }))
        function merge(base, extra) {
            return {
                ...base, ...extra,
                plugins: { ...base.plugins, ...(extra.plugins || {}) },
                scales: {
                    x: { ...base.scales.x, ...(extra.scales?.x || {}) },
                    y: { ...base.scales.y, ...(extra.scales?.y || {}) }
                }
            }
        }
        const hoursTick = v => n(v, { maximumFractionDigits: 1 }) + ' h'
        const pctTick = v => n(v, { maximumFractionDigits: 0 }) + ' %'

        // Stunden je Abschnitt (Einzel): Ist gegen Vorgabe
        const hoursData = computed(() => {
            if (!current.value) return { labels: [], datasets: [] }
            const s = seriesFor(current.value.employee_id)
            const daily = range.value.bucket === 'day'
            return {
                labels: bucketLabels.value,
                datasets: [
                    {
                        label: t('HrView.performance.legend.actual'),
                        data: s.map(b => b.actual / 60),
                        backgroundColor: s.map(b => daily && b.actual > threshold.value ? C.value.slower : C.value.series1),
                        borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28
                    },
                    { label: t('HrView.performance.legend.planned'), data: s.map(b => b.planned / 60), backgroundColor: C.value.neutral, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28 }
                ]
            }
        })
        // Bei Tagesraster: Linie bei der Arbeitszeit; unplausible Ausreißer (vergessener
        // Timer, z. B. 100 h an einem Tag) werden abgeschnitten, damit der Rest lesbar bleibt
        const dailyCap = computed(() => Math.ceil(threshold.value / 60 * 2))
        const hoursOptions = computed(() => {
            const daily = range.value.bucket === 'day'
            const peak = Math.max(0, ...(hoursData.value.datasets[0]?.data || [0]))
            return merge(baseOptions.value, {
                onClick: (evt, els) => { if (els.length) showBucketOrders(els[0].index) },
                onHover: (evt, els) => { if (evt.native?.target) evt.native.target.style.cursor = els.length ? 'pointer' : 'default' },
                plugins: {
                    // Legende in Serienfarbe, auch wenn einzelne Balken rot markiert sind
                    legend: {
                        ...baseOptions.value.plugins.legend,
                        labels: {
                            ...baseOptions.value.plugins.legend.labels,
                            generateLabels: chart => ChartJS.defaults.plugins.legend.labels.generateLabels(chart)
                                .map(l => l.datasetIndex === 0 ? { ...l, fillStyle: C.value.series1, strokeStyle: C.value.series1 } : l)
                        }
                    },
                    tooltip: {
                        ...baseOptions.value.plugins.tooltip,
                        callbacks: {
                            label: ctx => ctx.dataset.label + ': ' + n(ctx.parsed.y, { maximumFractionDigits: 1 }) + ' h',
                            footer: () => t('HrView.performance.orders.clickHint')
                        }
                    },
                    baseline: daily ? { value: threshold.value / 60, color: C.value.slower } : {}
                },
                scales: {
                    y: {
                        ...baseOptions.value.scales.y, beginAtZero: true,
                        suggestedMax: daily ? Math.ceil(threshold.value / 60) + 1 : undefined,
                        max: daily && peak > dailyCap.value ? dailyCap.value : undefined,
                        ticks: { ...baseOptions.value.scales.y.ticks, callback: hoursTick }
                    }
                }
            })
        })

        // Leistungsgrad je Abschnitt (Einzel), Linie bei 100 %
        const efficiencyData = computed(() => {
            if (!current.value) return { labels: [], datasets: [] }
            const s = seriesFor(current.value.employee_id)
            const vals = s.map(b => b.actual > 0 ? b.planned / b.actual * 100 : null)
            return {
                labels: bucketLabels.value,
                datasets: [{
                    label: t('HrView.performance.kpi.efficiency'),
                    data: vals,
                    backgroundColor: vals.map(v => v === null ? C.value.neutral : (v >= 100 ? C.value.faster : C.value.slower)),
                    borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28
                }]
            }
        })
        const efficiencyOptions = computed(() => merge(baseOptions.value, {
            plugins: {
                legend: { display: false },
                tooltip: { ...baseOptions.value.plugins.tooltip, callbacks: { label: ctx => ctx.parsed.y === null ? t('HrView.performance.noData') : pctTick(ctx.parsed.y) } },
                baseline: { value: 100, color: C.value.muted }
            },
            scales: { y: { ...baseOptions.value.scales.y, beginAtZero: true, suggestedMax: 120, ticks: { ...baseOptions.value.scales.y.ticks, callback: pctTick } } }
        }))
        const baselinePlugin = {
            id: 'baseline',
            afterDatasetsDraw(chart, args, opts) {
                if (!opts?.value) return
                const y = chart.scales.y.getPixelForValue(opts.value)
                const { left, right } = chart.chartArea
                const ctx = chart.ctx
                ctx.save()
                ctx.strokeStyle = opts.color
                ctx.setLineDash([4, 4])
                ctx.lineWidth = 1
                ctx.beginPath(); ctx.moveTo(left, y); ctx.lineTo(right, y); ctx.stroke()
                ctx.restore()
            }
        }

        // Benchmark (Einzel): Abweichung vom Team-Durchschnitt je Tätigkeit
        const benchmarkData = computed(() => ({
            labels: benchmarkTasks.value.map(x => x.description.length > 34 ? x.description.slice(0, 32) + '…' : x.description),
            datasets: [{
                label: t('HrView.performance.benchmark.legend'),
                data: benchmarkTasks.value.map(x => Math.round(x.deviation * 100)),
                backgroundColor: benchmarkTasks.value.map(x => x.deviation >= 0 ? C.value.faster : C.value.slower),
                borderRadius: 4, borderSkipped: false, maxBarThickness: 22
            }]
        }))
        const benchmarkOptions = computed(() => merge(baseOptions.value, {
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...baseOptions.value.plugins.tooltip,
                    callbacks: {
                        title: items => benchmarkTasks.value[items[0].dataIndex]?.description || '',
                        label: ctx => {
                            const x = benchmarkTasks.value[ctx.dataIndex]
                            return t(ctx.parsed.x >= 0 ? 'HrView.performance.benchmark.tooltipFaster' : 'HrView.performance.benchmark.tooltipSlower', {
                                v: Math.abs(ctx.parsed.x), own: fmtMinutes(x.avg_actual), team: fmtMinutes(x.team_avg), n: x.n
                            })
                        }
                    }
                }
            },
            scales: {
                x: { grid: { color: C.value.grid }, border: { display: false }, ticks: { color: C.value.muted, callback: v => (v > 0 ? '+' : '') + v + ' %' } },
                y: { grid: { display: false }, border: { display: false }, ticks: { color: C.value.text, autoSkip: false } }
            }
        }))

        // Vergleich (Alle): Ist gegen Vorgabe je Mechaniker
        const compareData = computed(() => ({
            labels: activeMechanics.value.map(m => m.name),
            datasets: [
                { label: t('HrView.performance.legend.actual'), data: activeMechanics.value.map(m => m.actual / 60), backgroundColor: C.value.series1, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 },
                { label: t('HrView.performance.legend.planned'), data: activeMechanics.value.map(m => m.planned / 60), backgroundColor: C.value.neutral, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }
            ]
        }))
        const compareOptions = computed(() => merge(baseOptions.value, {
            indexAxis: 'y',
            onClick: (evt, els) => { if (els.length) select(activeMechanics.value[els[0].index]?.employee_id) },
            plugins: { tooltip: { ...baseOptions.value.plugins.tooltip, callbacks: { label: ctx => ctx.dataset.label + ': ' + n(ctx.parsed.x, { maximumFractionDigits: 1 }) + ' h' } } },
            scales: {
                x: { grid: { color: C.value.grid }, border: { display: false }, ticks: { color: C.value.muted, callback: hoursTick }, beginAtZero: true },
                y: { grid: { display: false }, border: { display: false }, ticks: { color: C.value.text, autoSkip: false } }
            }
        }))

        // Leistungsmatrix (Alle): Auslastung × Leistungsgrad, Größe = Stunden
        const matrixPoints = computed(() => activeMechanics.value
            .filter(m => m.efficiency !== null && m.utilization !== null)
            .map(m => ({
                x: Math.round(m.utilization * 100),
                y: Math.round(m.efficiency * 100),
                r: Math.max(6, Math.min(26, 6 + Math.sqrt(m.actual / 60) * 2)),
                name: m.name, id: m.employee_id, hours: m.actual / 60
            })))
        const matrixData = computed(() => ({
            datasets: [{
                label: t('HrView.performance.mechanics'),
                data: matrixPoints.value,
                backgroundColor: C.value.series1 + 'cc',
                borderColor: C.value.surface,
                borderWidth: 2,
                hoverBorderWidth: 2
            }]
        }))
        const matrixOptions = computed(() => merge(baseOptions.value, {
            onClick: (evt, els) => { if (els.length) select(matrixPoints.value[els[0].index]?.id) },
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...baseOptions.value.plugins.tooltip,
                    callbacks: {
                        title: items => matrixPoints.value[items[0].dataIndex]?.name || '',
                        label: ctx => {
                            const p = matrixPoints.value[ctx.dataIndex]
                            return [
                                t('HrView.performance.kpi.utilization') + ': ' + p.x + ' %',
                                t('HrView.performance.kpi.efficiency') + ': ' + p.y + ' %',
                                t('HrView.performance.kpi.hours') + ': ' + n(p.hours, { maximumFractionDigits: 1 }) + ' h'
                            ]
                        }
                    }
                },
                quadrants: { x: team.value.utilization !== null ? Math.round(team.value.utilization * 100) : null, y: 100, color: C.value.grid, text: C.value.muted,
                    labels: [t('HrView.performance.matrix.q1'), t('HrView.performance.matrix.q2'), t('HrView.performance.matrix.q3'), t('HrView.performance.matrix.q4')] },
                matrixLabels: { color: C.value.text }
            },
            scales: {
                x: { grid: { color: C.value.grid }, border: { display: false }, ticks: { color: C.value.muted, callback: pctTick }, min: 0, suggestedMax: 100,
                    title: { display: true, text: t('HrView.performance.kpi.utilization'), color: C.value.muted, font: { size: 11 } } },
                y: { grid: { color: C.value.grid }, border: { display: false }, ticks: { color: C.value.muted, callback: pctTick }, suggestedMin: 50, suggestedMax: 150,
                    title: { display: true, text: t('HrView.performance.kpi.efficiency'), color: C.value.muted, font: { size: 11 } } }
            }
        }))
        const matrixLabelsPlugin = {
            id: 'matrixLabels',
            afterDatasetsDraw(chart, args, opts) {
                const meta = chart.getDatasetMeta(0)
                const ctx = chart.ctx
                ctx.save()
                ctx.font = '11px system-ui, sans-serif'
                ctx.fillStyle = opts.color
                ctx.textBaseline = 'middle'
                const placed = []
                meta.data.forEach((el, i) => {
                    const p = chart.data.datasets[0].data[i]
                    if (!p) return
                    const label = p.name.split(' ')[0]
                    const r = el.options?.radius || 6
                    const w = ctx.measureText(label).width
                    let x = el.x + r + 4
                    let align = 'left'
                    if (x + w > chart.chartArea.right) { x = el.x - r - 4; align = 'right' }
                    // Überlappt die Beschriftung eine bereits gesetzte, rutscht sie zeilenweise nach unten
                    let y = el.y
                    const left = align === 'left' ? x : x - w
                    const collides = () => placed.some(b => left < b.right && left + w > b.left && Math.abs(y - b.y) < 12)
                    let tries = 0
                    while (collides() && tries < 6) { y += 12; tries++ }
                    placed.push({ left, right: left + w, y })
                    ctx.textAlign = align
                    ctx.fillText(label, x, y)
                })
                ctx.restore()
            }
        }
        const quadrantPlugin = {
            id: 'quadrants',
            beforeDatasetsDraw(chart, args, opts) {
                const { left, right, top, bottom } = chart.chartArea
                const ctx = chart.ctx
                ctx.save()
                ctx.strokeStyle = opts.color
                ctx.setLineDash([4, 4])
                ctx.lineWidth = 1
                let px = null, py = null
                if (opts.y !== null) {
                    py = chart.scales.y.getPixelForValue(opts.y)
                    if (py > top && py < bottom) { ctx.beginPath(); ctx.moveTo(left, py); ctx.lineTo(right, py); ctx.stroke() }
                }
                if (opts.x !== null) {
                    px = chart.scales.x.getPixelForValue(opts.x)
                    if (px > left && px < right) { ctx.beginPath(); ctx.moveTo(px, top); ctx.lineTo(px, bottom); ctx.stroke() }
                }
                ctx.setLineDash([])
                ctx.fillStyle = opts.text
                ctx.font = '10px system-ui, sans-serif'
                ctx.textBaseline = 'top'
                if (opts.labels) {
                    ctx.textAlign = 'right'; ctx.fillText(opts.labels[0], right - 4, top + 4)
                    ctx.textAlign = 'left'; ctx.fillText(opts.labels[1], left + 4, top + 4)
                    ctx.textBaseline = 'bottom'
                    ctx.fillText(opts.labels[2], left + 4, bottom - 4)
                    ctx.textAlign = 'right'; ctx.fillText(opts.labels[3], right - 4, bottom - 4)
                }
                ctx.restore()
            }
        }

        // Verlauf Team (Alle): gestapelte Stunden je Mechaniker, über 8 → „Weitere"
        const teamTrendData = computed(() => {
            const act = activeMechanics.value
            if (!act.length) return { labels: [], datasets: [] }
            const main = act.slice(0, 7)
            const rest = act.slice(7)
            const datasets = main.map((m, i) => ({
                label: m.name,
                data: seriesFor(m.employee_id).map(b => b.actual / 60),
                backgroundColor: C.value[SERIES_SLOTS[i]],
                borderColor: C.value.surface, borderWidth: { top: 2, right: 0, bottom: 0, left: 0 },
                borderSkipped: false, maxBarThickness: 36
            }))
            if (rest.length) {
                const sum = keys.value.map(() => 0)
                rest.forEach(m => seriesFor(m.employee_id).forEach((b, i) => { sum[i] += b.actual / 60 }))
                datasets.push({ label: t('HrView.performance.others', { n: rest.length }), data: sum, backgroundColor: C.value.neutral, borderColor: C.value.surface, borderWidth: { top: 2, right: 0, bottom: 0, left: 0 }, borderSkipped: false, maxBarThickness: 36 })
            }
            return { labels: bucketLabels.value, datasets }
        })
        const teamTrendOptions = computed(() => merge(baseOptions.value, {
            plugins: { tooltip: { ...baseOptions.value.plugins.tooltip, callbacks: { label: ctx => ctx.dataset.label + ': ' + n(ctx.parsed.y, { maximumFractionDigits: 1 }) + ' h' } } },
            scales: {
                x: { ...baseOptions.value.scales.x, stacked: true },
                y: {
                    ...baseOptions.value.scales.y, stacked: true, beginAtZero: true,
                    max: teamTrendCap.value,
                    ticks: { ...baseOptions.value.scales.y.ticks, callback: hoursTick }
                }
            }
        }))
        // Tagesraster: Ausreißer (vergessene Timer) über dem Dreifachen eines üblichen
        // Tages abschneiden, damit die übrigen Tage lesbar bleiben; Tooltip zeigt den echten Wert
        const teamTrendCap = computed(() => {
            if (range.value.bucket !== 'day') return undefined
            const sums = keys.value.map((_, i) => teamTrendData.value.datasets.reduce((s, d) => s + (d.data[i] || 0), 0))
            const used = sums.filter(v => v > 0).sort((a, b) => a - b)
            if (!used.length) return undefined
            const median = used[Math.floor(used.length / 2)]
            const cap = Math.ceil(Math.max(median * 3, threshold.value / 60 * 2))
            return used[used.length - 1] > cap ? cap : undefined
        })

        return {
            t, C,
            period, refDate, selected, showInactive, loading, error, data, matrixRef,
            range, isCurrent, rangeLabel, prevRangeLabel, bucketLabel, shift, goToday, select,
            mechanics, activeMechanics, inactiveCount, listedMechanics, current, team,
            sparkFor, teamSpark, currentTasks, visibleTasks, allTasks, TASK_LIMIT, taskItems, expandedTask, onTaskClick, orderOf, spread, heatmap, heatColor,
            threshold, checkRows, checkCount, currentChecks,
            currentOrders, visibleOrders, allOrders, ORDER_LIMIT, orderBucket, orderBucketLabel, ordersAnchor,
            fmtHours, fmtMinutes, fmtInt, fmtCurrency, fmtFactor, pct, fmtTime, fmtDay, initials,
            deltaText, deltaPoints, deltaClass, deltaIcon, efficiencyColor, utilizationColor, openOrder, orderRoute,
            currentInsights, teamInsights,
            hoursData, hoursOptions, efficiencyData, efficiencyOptions, baselinePlugin,
            benchmarkData, benchmarkOptions, compareData, compareOptions,
            matrixData, matrixOptions, matrixLabelsPlugin, quadrantPlugin,
            teamTrendData, teamTrendOptions
        }
    }
}
</script>

<style scoped>
.perf__toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap);
    margin-bottom: var(--oserp-gap-lg);
}
.perf__range { min-width: 12ch; text-align: center; }

.perf__layout {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    gap: var(--oserp-gap-lg);
    align-items: start;
}
@media (max-width: 959px) {
    .perf__layout { grid-template-columns: minmax(0, 1fr); }
    .perf__list { position: static !important; max-height: none !important; }
}
.perf__list {
    position: sticky;
    top: 72px;
    max-height: calc(100vh - 90px);
    overflow-y: auto;
    padding: var(--oserp-gap-sm);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius);
    background: var(--oserp-surface-soft);
}
.perf__inactive { opacity: 0.6; }
.perf__spark { width: 72px; display: flex; align-items: center; }

.perf__content {
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap-lg);
    min-width: 0;
}
.perf__head {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap);
    flex-wrap: wrap;
}
.perf__running { cursor: pointer; max-width: 100%; }

.perf__kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: var(--oserp-gap);
}
.perf__kpi {
    border: 1px solid var(--oserp-border);
    border-radius: var(--oserp-radius);
    background: var(--oserp-surface);
    padding: var(--oserp-gap) var(--oserp-gap-lg);
    min-width: 0;
}
.perf__kpi-label { font-size: 0.75rem; color: var(--oserp-text-muted); }
.perf__kpi-value { font-size: 1.6rem; font-weight: 600; line-height: 1.25; margin: 2px 0; }
.perf__kpi-foot { font-size: 0.75rem; display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
.perf__up { color: rgb(var(--v-theme-success)); }
.perf__down { color: rgb(var(--v-theme-error)); }
.perf__delta { font-size: 0.7rem; margin-left: 4px; }

.perf__grid-2 {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr));
    gap: var(--oserp-gap-lg);
}
.perf__chart { position: relative; height: 260px; }
.perf__chart--tall { height: 320px; }
.perf__empty {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--oserp-text-muted);
    font-size: 0.875rem;
}

.perf__insights { margin: 0; padding-left: 1.2em; font-size: 0.9rem; line-height: 1.6; }

.perf__table { overflow-x: auto; }
.perf__table th { white-space: nowrap; }
.perf__th-rank { width: 36px; }
.perf__th-spark { width: 96px; }
.perf__row { cursor: pointer; }
.perf__row--open td { background: var(--oserp-surface-soft); }
.perf__subrow td { background: var(--oserp-surface-soft); font-size: 0.8rem; }
.perf__name { white-space: nowrap; }
.perf__num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.perf__task { max-width: 0; width: 100%; min-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.perf__link { color: rgb(var(--v-theme-primary)); text-decoration: none; background: none; border: 0; padding: 0; font: inherit; cursor: pointer; }
.perf__row:hover .perf__link { text-decoration: underline; }
.perf__customer { max-width: 22ch; overflow: hidden; text-overflow: ellipsis; }
.perf__th-flag { width: 32px; }
.perf__flag {
    display: inline-flex;
    align-items: center;
    gap: 1px;
    margin-left: var(--oserp-gap-sm);
    color: rgb(var(--v-theme-warning));
}
.perf__link:hover { text-decoration: underline; }

.perf__heat {
    display: grid;
    gap: 3px;
    align-items: center;
}
.perf__heat-axis { font-size: 0.7rem; color: var(--oserp-text-muted); text-align: center; }
.perf__heat-axis--row { text-align: right; padding-right: 4px; }
.perf__heat-cell {
    aspect-ratio: 1.6;
    border-radius: var(--oserp-radius-sm);
    border: 1px solid var(--oserp-border-soft);
    min-height: 16px;
}
</style>
