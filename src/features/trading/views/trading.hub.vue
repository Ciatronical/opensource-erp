<!-- src/features/trading/views/trading.hub.vue -->
<!--
    KI-Trading: Übersicht, Strategien, Forschungslabor, Orders, Sicherheit, Protokoll.

    Alle Zahlen kommen aus gespeicherten Kontoständen, Orders und Kursen
    (backend/api/trading). Die Ansicht rechnet nichts hinzu — sie formatiert nur.
-->
<template>
    <NavbarView />
    <v-container fluid class="trading-page">
        <page-header :title="t('TradingView.title')" :subtitle="t('TradingView.subtitle')">
            <v-chip size="small" :color="modeColor" variant="tonal" :prepend-icon="modeIcon">
                {{ t('TradingView.mode.' + (summary?.mode || 'simulation')) }}
            </v-chip>
            <v-chip size="small" variant="tonal" :color="engineActive ? 'success' : 'warning'"
                    :prepend-icon="engineActive ? 'mdi-robot-outline' : 'mdi-robot-off-outline'">
                {{ engineActive ? t('TradingView.ai.active') : t('TradingView.ai.inactive') }}
            </v-chip>
            <template #actions>
                <v-btn variant="outlined" prepend-icon="mdi-book-open-page-variant-outline"
                       :to="{ name: 'docs', params: { slug: 'trading' } }">
                    {{ t('TradingView.guide') }}
                </v-btn>
                <v-tooltip location="bottom" max-width="360">
                    <template #activator="{ props: tip }">
                        <v-btn v-bind="tip" color="error" variant="flat" prepend-icon="mdi-alert-octagon" @click="killSwitch">
                            {{ t('TradingView.killSwitch.button') }}
                        </v-btn>
                    </template>
                    {{ t('TradingView.killSwitch.tooltip') }}
                </v-tooltip>
                <v-btn variant="text" icon="mdi-refresh" :loading="store.loading" :aria-label="t('TradingView.refresh')" @click="reload" />
            </template>
        </page-header>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" closable class="mb-3" @click:close="error = ''">{{ error }}</v-alert>
        <v-alert v-for="lock in summary?.locks || []" :key="lock.id" type="error" variant="tonal" density="compact" class="mb-3" icon="mdi-lock-alert">
            <strong>{{ t('TradingView.lock.active') }}:</strong>
            {{ t('TradingView.lock.reason.' + lock.reason_code, lock.reason_code) }} — {{ lock.message }}
            <span class="trading-muted">({{ fmtDateTime(lock.set_at) }}, {{ lock.set_by }})</span>
            <template #append>
                <v-btn size="small" variant="outlined" @click="clearLock(lock)">{{ t('TradingView.lock.clear') }}</v-btn>
            </template>
        </v-alert>

        <v-tabs v-model="tab" density="compact" class="mb-3" show-arrows>
            <v-tab v-for="name in tabs" :key="name" :value="name">{{ t('TradingView.tabs.' + name) }}</v-tab>
        </v-tabs>

        <v-window v-model="tab" :touch="false">
            <!-- ═════════════ Übersicht ═════════════ -->
            <v-window-item value="overview">
                <v-alert v-if="summary && !summary.has_data" type="info" variant="tonal" density="compact" class="mb-3">
                    {{ t('TradingView.overview.noData') }}
                </v-alert>
                <div class="trading-tiles">
                    <div v-for="tile in tiles" :key="tile.label" class="trading-tile">
                        <div class="trading-tile__label">{{ tile.label }}</div>
                        <div class="trading-tile__value">
                            <v-icon v-if="tile.icon" size="small" class="mr-1">{{ tile.icon }}</v-icon>{{ tile.value }}
                        </div>
                        <div v-if="tile.hint" class="trading-tile__hint">{{ tile.hint }}</div>
                    </div>
                </div>

                <v-row density="comfortable" class="mt-1">
                    <v-col cols="12" lg="8">
                        <section-card :title="t('TradingView.overview.equityTitle')" icon="mdi-chart-line">
                            <template #actions>
                                <v-btn-toggle v-model="days" density="compact" mandatory variant="outlined" divided @update:model-value="reload">
                                    <v-btn v-for="d in [30, 90, 180, 365]" :key="d" :value="d" size="small">{{ t('TradingView.overview.days', { n: d }) }}</v-btn>
                                </v-btn-toggle>
                            </template>
                            <trading-line-chart :series="equitySeries" indexed :empty-text="t('TradingView.overview.chartEmpty')" />
                            <div class="trading-muted mt-1">{{ t('TradingView.overview.equityNote') }}</div>
                        </section-card>
                    </v-col>
                    <v-col cols="12" lg="4">
                        <section-card :title="t('TradingView.overview.strategyTitle')" icon="mdi-chart-bar">
                            <trading-bar-chart :items="strategyBars" :series-label="t('TradingView.kpi.totalReturn')" :empty-text="t('TradingView.overview.noStrategies')" />
                        </section-card>
                        <section-card :title="t('TradingView.overview.positions')" icon="mdi-wallet-outline" plain class="mt-3">
                            <v-table density="compact">
                                <tbody>
                                    <tr v-for="p in dashboard?.positions || []" :key="p.symbol">
                                        <td>{{ p.symbol }}</td>
                                        <td class="text-right">{{ fmtNum(p.qty, 6) }}</td>
                                        <td class="text-right">{{ fmtMoney(p.qty * p.last_close) }}</td>
                                    </tr>
                                    <tr v-if="!(dashboard?.positions || []).length"><td class="trading-muted">{{ t('TradingView.overview.noPositions') }}</td></tr>
                                </tbody>
                            </v-table>
                        </section-card>
                    </v-col>
                </v-row>

                <v-row density="comfortable">
                    <v-col cols="12" lg="6">
                        <section-card :title="t('TradingView.report.title') + (report ? ' · ' + fmtDate(report.day) : '')" icon="mdi-file-chart-outline">
                            <template v-if="report">
                                <p>{{ reportText }}</p>
                                <ul class="trading-list">
                                    <li v-if="report.data.summary.best_strategy">
                                        {{ t('TradingView.report.best', { code: report.data.summary.best_strategy.code, pct: fmtPct(report.data.summary.best_strategy.return_pct) }) }}
                                    </li>
                                    <li v-if="report.data.summary.worst_strategy && report.data.strategies.length > 1">
                                        {{ t('TradingView.report.worst', { code: report.data.summary.worst_strategy.code, pct: fmtPct(report.data.summary.worst_strategy.return_pct) }) }}
                                    </li>
                                    <li v-if="report.data.summary.benchmark">
                                        {{ t('TradingView.report.benchmark', { symbol: report.data.summary.benchmark.symbol, pct: fmtPct(report.data.summary.benchmark.return_pct) }) }}
                                    </li>
                                    <li v-for="x in report.data.experiments" :key="'x' + x.id">{{ t('TradingView.report.experiment', { id: x.id }) }}: {{ x.summary }}</li>
                                    <li v-for="(c, i) in report.data.status_changes" :key="'c' + i">{{ c.code }} → {{ t('TradingView.status.' + c.status) }}: {{ c.reason }}</li>
                                    <li>{{ t('TradingView.report.orders', { filled: report.data.orders.filled, rejected: report.data.orders.rejected, fees: fmtMoney(report.data.orders.fees) }) }}</li>
                                    <li>{{ t('TradingView.report.risks', { n: report.data.risks.length }) }}</li>
                                </ul>
                            </template>
                            <div v-else class="trading-muted">{{ t('TradingView.report.none') }}</div>
                        </section-card>
                    </v-col>
                    <v-col cols="12" lg="6">
                        <section-card :title="t('TradingView.alerts.title')" icon="mdi-bell-outline" plain>
                            <v-list density="compact" lines="two">
                                <v-list-item v-for="a in dashboard?.alerts || []" :key="a.id"
                                             :prepend-icon="a.severity === 'critical' ? 'mdi-alert-circle' : 'mdi-alert-outline'"
                                             :title="t('TradingView.events.' + a.event, a.event)"
                                             :subtitle="fmtDateTime(a.ts) + ' · ' + detailText(a.details)" />
                                <v-list-item v-if="!(dashboard?.alerts || []).length" :title="t('TradingView.alerts.none')" />
                            </v-list>
                        </section-card>
                    </v-col>
                </v-row>
            </v-window-item>

            <!-- ═════════════ Strategien ═════════════ -->
            <v-window-item value="strategies">
                <section-card :title="t('TradingView.strategies.title')" icon="mdi-strategy" plain>
                    <template #actions>
                        <v-switch v-model="showAll" :label="t('TradingView.strategies.showAll')" density="compact" hide-details color="primary" @update:model-value="loadStrategies" />
                    </template>
                    <v-data-table :headers="strategyHeaders" :items="strategies" density="compact" :items-per-page="25" hover @click:row="(e, row) => openStrategy(row.item)">
                        <template #item.status="{ item }">
                            <v-chip size="x-small" variant="tonal" :color="statusColor(item.status)" :prepend-icon="statusIcon(item.status)">{{ t('TradingView.status.' + item.status) }}</v-chip>
                        </template>
                        <template #item.family="{ item }">{{ t('TradingView.family.' + item.family, item.family) }}</template>
                        <template #item.origin="{ item }">{{ t('TradingView.origin.' + item.origin, item.origin) }}</template>
                        <template #item.train="{ item }">{{ fmtNum(item.backtests?.train?.sharpe) }}</template>
                        <template #item.validation="{ item }">{{ fmtNum(item.backtests?.validation?.sharpe) }}</template>
                        <template #item.test="{ item }">{{ fmtNum(item.backtests?.test?.sharpe) }}</template>
                        <template #item.drawdown="{ item }">{{ item.backtests?.test ? fmtPct(-item.backtests.test.max_drawdown * 100) : '' }}</template>
                        <template #item.paper="{ item }">{{ item.paper?.equity ? fmtPct((item.paper.equity / item.paper.start_capital - 1) * 100) : '' }}</template>
                        <template #item.status_reason="{ item }"><span class="trading-muted">{{ item.status_reason }}</span></template>
                        <template #no-data>{{ t('TradingView.strategies.none') }}</template>
                    </v-data-table>
                </section-card>
            </v-window-item>

            <!-- ═════════════ Labor ═════════════ -->
            <v-window-item value="lab">
                <div class="trading-tiles mb-3">
                    <div class="trading-tile"><div class="trading-tile__label">{{ t('TradingView.lab.versionsTested') }}</div><div class="trading-tile__value">{{ lab?.totals?.versions_tested ?? '–' }}</div></div>
                    <div class="trading-tile"><div class="trading-tile__label">{{ t('TradingView.lab.testEvaluations') }}</div><div class="trading-tile__value">{{ lab?.totals?.test_set_evaluations ?? '–' }}</div><div class="trading-tile__hint">{{ t('TradingView.lab.testEvaluationsHint') }}</div></div>
                    <div class="trading-tile"><div class="trading-tile__label">{{ t('TradingView.lab.passed') }}</div><div class="trading-tile__value">{{ lab?.totals?.passed ?? '–' }}</div></div>
                </div>
                <v-row density="comfortable">
                    <v-col cols="12" lg="5">
                        <section-card :title="t('TradingView.lab.families')" icon="mdi-family-tree" plain>
                            <v-table density="compact">
                                <thead><tr>
                                    <th>{{ t('TradingView.strategies.family') }}</th>
                                    <th class="text-right">{{ t('TradingView.lab.tested') }}</th>
                                    <th class="text-right">{{ t('TradingView.lab.passed') }}</th>
                                    <th class="text-right">{{ t('TradingView.lab.avgSharpe') }}</th>
                                    <th class="text-right">{{ t('TradingView.lab.bestSharpe') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="f in lab?.families || []" :key="f.family">
                                        <td>{{ t('TradingView.family.' + f.family, f.family) }}</td>
                                        <td class="text-right">{{ f.tested }}</td>
                                        <td class="text-right">{{ f.passed }}</td>
                                        <td class="text-right">{{ fmtNum(f.avg_validation_sharpe) }}</td>
                                        <td class="text-right">{{ fmtNum(f.best_validation_sharpe) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </section-card>
                        <p class="trading-muted mt-2">{{ t('TradingView.lab.method') }}</p>
                    </v-col>
                    <v-col cols="12" lg="7">
                        <section-card :title="t('TradingView.lab.experiments')" icon="mdi-flask-outline" plain>
                            <template #actions>
                                <v-btn size="small" variant="tonal" prepend-icon="mdi-play" :loading="running" @click="runEngine(true)">{{ t('TradingView.lab.runNow') }}</v-btn>
                            </template>
                            <v-list density="compact" lines="three">
                                <v-list-item v-for="x in lab?.experiments || []" :key="x.id" prepend-icon="mdi-flask-outline"
                                             :title="'#' + x.id + ' · ' + fmtDateTime(x.started_at) + ' · ' + (x.summary || t('TradingView.lab.running'))"
                                             :subtitle="x.hypothesis" />
                                <v-list-item v-if="!(lab?.experiments || []).length" :title="t('TradingView.lab.none')" />
                            </v-list>
                        </section-card>
                    </v-col>
                </v-row>
            </v-window-item>

            <!-- ═════════════ Orders ═════════════ -->
            <v-window-item value="orders">
                <section-card :title="t('TradingView.orders.title')" icon="mdi-swap-horizontal" plain>
                    <v-data-table :headers="orderHeaders" :items="orders" density="compact" :items-per-page="25">
                        <template #item.itime="{ item }">{{ fmtDateTime(item.itime) }}</template>
                        <template #item.side="{ item }">{{ t('TradingView.orders.side.' + item.side) }}</template>
                        <template #item.order_type="{ item }">{{ t('TradingView.orders.type.' + item.order_type, item.order_type) }}</template>
                        <template #item.qty="{ item }">{{ fmtNum(item.qty, 6) }}</template>
                        <template #item.avg_fill_price="{ item }">{{ item.avg_fill_price ? fmtNum(item.avg_fill_price, 2) : '' }}</template>
                        <template #item.fees="{ item }">{{ Number(item.fees) ? fmtNum(item.fees, 2) : '' }}</template>
                        <template #item.status="{ item }">
                            <v-chip size="x-small" variant="tonal" :color="orderColor(item.status)">{{ t('TradingView.orders.status.' + item.status, item.status) }}</v-chip>
                        </template>
                        <template #item.error="{ item }"><span class="trading-muted">{{ item.error }}</span></template>
                        <template #no-data>{{ t('TradingView.orders.none') }}</template>
                    </v-data-table>
                </section-card>
            </v-window-item>

            <!-- ═════════════ Sicherheit ═════════════ -->
            <v-window-item value="control">
                <v-row density="comfortable">
                    <v-col cols="12" lg="6">
                        <section-card :title="t('TradingView.live.title')" icon="mdi-cash-lock">
                            <p>
                                <v-chip :color="control?.live_authorized ? 'error' : 'default'" variant="tonal" size="small"
                                        :prepend-icon="control?.live_authorized ? 'mdi-cash-check' : 'mdi-cash-off'">
                                    {{ control?.live_authorized ? t('TradingView.live.enabled') : t('TradingView.live.disabled') }}
                                </v-chip>
                            </p>
                            <v-tooltip v-if="!control?.live_authorized" location="bottom" max-width="420">
                                <template #activator="{ props: tip }">
                                    <span v-bind="tip">
                                        <v-btn color="error" variant="outlined" prepend-icon="mdi-cash-lock-open" @click="liveDialog = true">
                                            {{ t('TradingView.live.enableButton') }}
                                        </v-btn>
                                    </span>
                                </template>
                                {{ t('TradingView.live.enableTooltip') }}
                            </v-tooltip>
                            <v-tooltip v-else location="bottom" max-width="420">
                                <template #activator="{ props: tip }">
                                    <v-btn v-bind="tip" color="primary" variant="flat" prepend-icon="mdi-cash-off" @click="disableLive">
                                        {{ t('TradingView.live.disableButton') }}
                                    </v-btn>
                                </template>
                                {{ t('TradingView.live.disableTooltip') }}
                            </v-tooltip>
                            <p class="trading-muted mt-2">{{ t('TradingView.control.ownOnly') }}</p>
                            <v-table v-if="(control?.authorizations || []).length" density="compact" class="mt-3">
                                <tbody>
                                    <tr v-for="(a, i) in control.authorizations" :key="i">
                                        <td>{{ fmtDateTime(a.itime) }}</td>
                                        <td>{{ t('TradingView.live.log.' + a.action) }}</td>
                                        <td>{{ a.login }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </section-card>

                        <section-card :title="t('TradingView.limits.title')" icon="mdi-shield-lock-outline" class="mt-3">
                            <p class="trading-muted">{{ t('TradingView.limits.note') }}</p>
                            <v-row density="compact">
                                <v-col v-for="l in control?.limits || []" :key="l.key" cols="12" sm="6">
                                    <v-text-field v-model="limitEdit[l.key]" type="number" min="0" :label="t('TradingView.limits.' + l.key, l.key)"
                                                  :suffix="unitText(l.unit)" />
                                </v-col>
                            </v-row>
                            <template #actions>
                                <v-btn size="small" variant="tonal" prepend-icon="mdi-content-save" @click="saveLimits">{{ t('TradingView.save') }}</v-btn>
                            </template>
                        </section-card>
                    </v-col>

                    <v-col cols="12" lg="6">
                        <section-card :title="t('TradingView.broker.title')" icon="mdi-bank-transfer">
                            <template #actions>
                                <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="editConnection()">{{ t('TradingView.broker.add') }}</v-btn>
                            </template>
                            <v-table density="compact">
                                <tbody>
                                    <tr v-for="c in control?.connections || []" :key="c.id">
                                        <td>{{ c.name }}<div class="trading-muted">{{ c.adapter }} · {{ t('TradingView.broker.env.' + c.environment) }}</div></td>
                                        <td class="text-right">{{ fmtNum(c.capital_limit, 0) }}</td>
                                        <td><v-chip size="x-small" variant="tonal" :color="c.active ? 'success' : 'default'">{{ c.active ? t('TradingView.broker.active') : t('TradingView.broker.inactive') }}</v-chip></td>
                                        <td class="text-right text-no-wrap">
                                            <v-btn size="x-small" variant="text" :loading="testing === c.id" @click="testConnection(c)">{{ t('TradingView.broker.test') }}</v-btn>
                                            <v-btn size="x-small" variant="text" icon="mdi-pencil" :aria-label="t('TradingView.broker.edit')" @click="editConnection(c)" />
                                        </td>
                                    </tr>
                                    <tr v-if="!(control?.connections || []).length"><td class="trading-muted">{{ t('TradingView.broker.none') }}</td></tr>
                                </tbody>
                            </v-table>
                            <p class="trading-muted mt-2">{{ t('TradingView.broker.note') }}</p>
                        </section-card>

                        <section-card :title="t('TradingView.settings.title')" icon="mdi-tune" class="mt-3">
                            <template v-for="section in settingSections" :key="section">
                                <div class="trading-subtitle">{{ t('TradingView.settings.section.' + section) }}</div>
                                <v-row density="compact">
                                    <v-col v-for="s in (control?.settings || []).filter(x => x.section === section)" :key="s.key" cols="12" sm="6">
                                        <v-switch v-if="s.value_type === 'bool'" v-model="settingEdit[s.key]" true-value="1" false-value="0" color="primary" density="compact" hide-details
                                                  :label="t('TradingView.settings.' + s.key, s.key)" :readonly="s.company_wide && !isAdmin"
                                                  :hint="s.company_wide ? t('TradingView.settings.companyWide') : undefined" :persistent-hint="s.company_wide" />
                                        <v-text-field v-else v-model="settingEdit[s.key]" :type="s.value_type === 'number' ? 'number' : 'text'"
                                                      :label="t('TradingView.settings.' + s.key, s.key)" :readonly="s.company_wide && !isAdmin"
                                                  :hint="s.company_wide ? t('TradingView.settings.companyWide') : undefined" :persistent-hint="s.company_wide" />
                                    </v-col>
                                </v-row>
                            </template>
                            <template #actions>
                                <v-btn size="small" variant="tonal" prepend-icon="mdi-content-save" @click="saveSettings">{{ t('TradingView.save') }}</v-btn>
                            </template>
                        </section-card>

                        <section-card :title="t('TradingView.instruments.title')" icon="mdi-format-list-bulleted" plain class="mt-3">
                            <p class="trading-muted">{{ t('TradingView.control.adminOnly') }}</p>
                            <v-table density="compact">
                                <tbody>
                                    <tr v-for="i in control?.instruments || []" :key="i.id">
                                        <td>{{ i.symbol }} <v-icon v-if="i.is_benchmark" size="x-small" :title="t('TradingView.instruments.benchmark')">mdi-flag-variant</v-icon></td>
                                        <td class="trading-muted">{{ t('TradingView.instruments.bars', { n: i.bars }) }}{{ i.last_bar ? ' · ' + fmtDate(i.last_bar) : '' }}</td>
                                        <td class="text-right"><v-switch :model-value="i.active" :disabled="!isAdmin" density="compact" hide-details color="primary" :aria-label="i.symbol" @update:model-value="v => saveInstrument(i, v)" /></td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </section-card>
                    </v-col>
                </v-row>
            </v-window-item>

            <!-- ═════════════ Protokoll ═════════════ -->
            <v-window-item value="log">
                <section-card :title="t('TradingView.log.title')" icon="mdi-clipboard-text-clock-outline" plain>
                    <template #actions>
                        <v-btn-toggle v-model="logSeverity" density="compact" variant="outlined" divided @update:model-value="loadLog">
                            <v-btn value="" size="small">{{ t('TradingView.log.all') }}</v-btn>
                            <v-btn value="warning" size="small">{{ t('TradingView.log.warning') }}</v-btn>
                            <v-btn value="critical" size="small">{{ t('TradingView.log.critical') }}</v-btn>
                        </v-btn-toggle>
                    </template>
                    <v-data-table :headers="logHeaders" :items="log" density="compact" :items-per-page="50">
                        <template #item.ts="{ item }">{{ fmtDateTime(item.ts) }}</template>
                        <template #item.event="{ item }">{{ t('TradingView.events.' + item.event, item.event) }}</template>
                        <template #item.details="{ item }"><span class="trading-muted">{{ detailText(item.details) }}</span></template>
                    </v-data-table>
                </section-card>
            </v-window-item>
        </v-window>

        <!-- Strategie-Detail -->
        <v-dialog v-model="strategyDialog" max-width="1000" scrollable>
            <v-card v-if="detail">
                <v-card-title>{{ detail.strategy.code }} · {{ t('TradingView.family.' + detail.strategy.family, detail.strategy.family) }}</v-card-title>
                <v-card-text>
                    <p>{{ detail.strategy.hypothesis }}</p>
                    <p class="trading-muted">{{ detail.strategy.status_reason }}</p>
                    <trading-line-chart :series="detailSeries" indexed :height="240" :empty-text="t('TradingView.strategies.noCurve')" />
                    <div v-for="v in detail.versions" :key="v.id" class="mt-4">
                        <div class="trading-subtitle">{{ t('TradingView.strategies.version', { n: v.version }) }} · {{ fmtDate(v.itime) }} <span v-if="v.change_note" class="trading-muted">— {{ v.change_note }}</span></div>
                        <code class="trading-params">{{ paramText(v.params) }}</code>
                        <v-table density="compact">
                            <thead><tr>
                                <th>{{ t('TradingView.strategies.segment') }}</th>
                                <th class="text-right">{{ t('TradingView.kpi.totalReturn') }}</th>
                                <th class="text-right">{{ t('TradingView.strategies.benchmark') }}</th>
                                <th class="text-right">Sharpe</th>
                                <th class="text-right">{{ t('TradingView.strategies.drawdown') }}</th>
                                <th class="text-right">{{ t('TradingView.strategies.trades') }}</th>
                                <th class="text-right">{{ t('TradingView.strategies.pValue') }}</th>
                                <th class="text-right">{{ t('TradingView.strategies.dsr') }}</th>
                                <th class="text-right">{{ t('TradingView.strategies.stability') }}</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="(b, i) in v.backtests" :key="i">
                                    <td>{{ t('TradingView.strategies.segments.' + b.segment) }} <span class="trading-muted">{{ fmtDate(b.from_ts) }}–{{ fmtDate(b.to_ts) }}</span></td>
                                    <td class="text-right">{{ fmtPct(b.total_return * 100) }}</td>
                                    <td class="text-right">{{ b.benchmark_return != null ? fmtPct(b.benchmark_return * 100) : '' }}</td>
                                    <td class="text-right">{{ fmtNum(b.sharpe) }}</td>
                                    <td class="text-right">{{ fmtPct(-b.max_drawdown * 100) }}</td>
                                    <td class="text-right">{{ b.trades }}</td>
                                    <td class="text-right">{{ b.p_value != null ? fmtNum(b.p_value) : '' }}</td>
                                    <td class="text-right">{{ b.deflated_sharpe != null ? fmtNum(b.deflated_sharpe) : '' }}</td>
                                    <td class="text-right">{{ b.stability != null ? fmtPct(b.stability * 100, false) : '' }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>
                </v-card-text>
                <v-card-actions><v-spacer /><v-btn variant="text" @click="strategyDialog = false">{{ t('TradingView.close') }}</v-btn></v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Sicherheitsbestätigung Echtgeld -->
        <v-dialog v-model="liveDialog" max-width="620" persistent>
            <v-card>
                <v-card-title><v-icon start color="error">mdi-alert</v-icon>{{ t('TradingView.live.confirmTitle') }}</v-card-title>
                <v-card-text>
                    <p>{{ t('TradingView.live.enableTooltip') }}</p>
                    <v-checkbox v-for="n in [1, 2, 3]" :key="n" v-model="liveChecks[n - 1]" density="compact" hide-details :label="t('TradingView.live.check' + n)" />
                    <p class="mt-3">{{ t('TradingView.live.typeWord', { word: 'ECHTGELD' }) }}</p>
                    <v-text-field v-model="liveWord" variant="outlined" density="compact" hide-details autocomplete="off" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="liveDialog = false">{{ t('TradingView.cancel') }}</v-btn>
                    <v-btn color="error" variant="flat" :disabled="liveWord !== 'ECHTGELD' || liveChecks.includes(false)" @click="enableLive">{{ t('TradingView.live.enableButton') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Broker-Verbindung -->
        <v-dialog v-model="connDialog" max-width="560">
            <v-card>
                <v-card-title>{{ conn.id ? t('TradingView.broker.edit') : t('TradingView.broker.add') }}</v-card-title>
                <v-card-text>
                    <v-defaults-provider :defaults="{ VTextField: { variant: 'outlined', density: 'compact', hideDetails: 'auto', class: 'mb-3' }, VSelect: { variant: 'outlined', density: 'compact', hideDetails: 'auto', class: 'mb-3' } }">
                        <v-text-field v-model="conn.name" :label="t('TradingView.broker.name')" />
                        <v-select v-model="conn.adapter" :items="control?.adapters || []" :label="t('TradingView.broker.adapter')" :disabled="!!conn.id" />
                        <v-select v-model="conn.environment" :disabled="!!conn.id" :label="t('TradingView.broker.environment')"
                                  :items="['sandbox', 'live'].map(v => ({ value: v, title: t('TradingView.broker.env.' + v) }))" />
                        <v-text-field v-model="conn.api_key" :label="t('TradingView.broker.apiKey')" :placeholder="conn.id ? t('TradingView.broker.unchanged') : ''" autocomplete="off" />
                        <v-text-field v-model="conn.api_secret" type="password" :label="t('TradingView.broker.apiSecret')" :placeholder="conn.id ? t('TradingView.broker.unchanged') : ''" autocomplete="new-password" />
                        <v-text-field v-model="conn.capital_limit" type="number" min="0" :label="t('TradingView.broker.capitalLimit')" :hint="t('TradingView.broker.capitalLimitHint')" persistent-hint />
                        <v-switch v-model="conn.active" color="primary" density="compact" hide-details :label="t('TradingView.broker.active')" />
                    </v-defaults-provider>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="connDialog = false">{{ t('TradingView.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" @click="saveConnection">{{ t('TradingView.save') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-snackbar v-model="snack" timeout="4000">{{ snackText }}</v-snackbar>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Swal from 'sweetalert2'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import TradingLineChart from '@/features/trading/components/trading.line-chart.vue'
import TradingBarChart from '@/features/trading/components/trading.bar-chart.vue'
import { tradingStore } from '@/features/trading/stores/trading.store.js'
import { useTradingFormat } from '@/features/trading/composables/useTradingFormat.js'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = tradingStore()

const tabs = ['overview', 'strategies', 'lab', 'orders', 'control', 'log']
const tab = ref(tabs.includes(route.query.tab) ? route.query.tab : 'overview')
const days = ref(180)
const error = ref('')
const snack = ref(false)
const snackText = ref('')

const dashboard = computed(() => store.dashboard)
const summary = computed(() => store.summary)
const isAdmin = computed(() => !!(dashboard.value?.is_admin || control.value?.is_admin))
const report = computed(() => dashboard.value?.report || null)
const { fmtMoney, fmtPct, fmtNum, fmtDate, fmtDateTime, engineAlive } = useTradingFormat(() => summary.value?.currency || 'EUR')
const engineActive = computed(() => engineAlive(summary.value))

const strategies = ref([])
const showAll = ref(false)
const lab = ref(null)
const orders = ref([])
const control = ref(null)
const log = ref([])
const logSeverity = ref('')
const limitEdit = ref({})
const settingEdit = ref({})
const running = ref(false)
const testing = ref(null)

const modeColor = computed(() => ({ live: 'error', halted: 'error', simulation: 'info' }[summary.value?.mode] || 'info'))
const modeIcon = computed(() => ({ live: 'mdi-cash', halted: 'mdi-hand-back-left', simulation: 'mdi-test-tube' }[summary.value?.mode] || 'mdi-test-tube'))

const tiles = computed(() => {
    const s = summary.value || {}
    const has = !!s.has_data
    return [
        { label: s.account_kind === 'live' ? t('TradingView.kpi.startCapitalLive') : t('TradingView.kpi.startCapital'), value: has ? fmtMoney(s.start_capital) : '–' },
        { label: t('TradingView.kpi.portfolioValue'), value: has ? fmtMoney(s.portfolio_value) : '–', hint: has ? t('TradingView.kpi.asOf', { ts: fmtDateTime(s.as_of) }) : '' },
        { label: t('TradingView.kpi.dayPnl'), value: has ? fmtMoney(s.day_pnl, true) : '–' },
        { label: t('TradingView.kpi.totalPnl'), value: has ? fmtMoney(s.total_pnl, true) : '–' },
        { label: t('TradingView.kpi.totalReturn'), value: has ? fmtPct(s.total_return_pct) : '–',
          hint: s.benchmark?.return_pct != null ? t('TradingView.kpi.benchmarkHint', { symbol: s.benchmark.symbol, pct: fmtPct(s.benchmark.return_pct) }) : '' },
        { label: t('TradingView.kpi.activeStrategies'), value: s.active_strategies ?? '–' },
        { label: t('TradingView.kpi.mode'), value: t('TradingView.mode.' + (s.mode || 'simulation')), icon: modeIcon.value },
        { label: t('TradingView.kpi.aiStatus'), value: engineActive.value ? t('TradingView.ai.active') : t('TradingView.ai.inactive'),
          hint: s.engine_heartbeat ? t('TradingView.kpi.lastRun', { ts: fmtDateTime(s.engine_heartbeat) }) : t('TradingView.kpi.neverRun') },
    ]
})

const equitySeries = computed(() => {
    const curve = dashboard.value?.equity_curve || []
    const series = [{ label: t('TradingView.overview.portfolio'), points: curve.map(p => ({ x: p.day, y: p.equity })) }]
    if (curve.some(p => p.benchmark != null)) {
        series.push({ label: summary.value?.benchmark?.symbol || t('TradingView.strategies.benchmark'), points: curve.map(p => ({ x: p.day, y: p.benchmark })) })
    }
    return series
})
const strategyBars = computed(() => (dashboard.value?.strategies || [])
    .filter(s => s.equity != null)
    .map(s => ({ label: s.code, value: (s.equity / s.start_capital - 1) * 100 })))

const reportText = computed(() => {
    const s = report.value?.data?.summary
    if (!s?.has_data) return t('TradingView.report.none')
    return t('TradingView.report.headline', {
        value: fmtMoney(s.portfolio_value), day: fmtMoney(s.day_pnl, true), total: fmtPct(s.total_return_pct), n: s.active_strategies,
    })
})

const strategyHeaders = computed(() => [
    { title: t('TradingView.strategies.code'), key: 'code' },
    { title: t('TradingView.strategies.family'), key: 'family' },
    { title: t('TradingView.strategies.statusCol'), key: 'status' },
    { title: t('TradingView.strategies.origin'), key: 'origin' },
    { title: t('TradingView.strategies.sharpeTrain'), key: 'train', align: 'end', sortable: false },
    { title: t('TradingView.strategies.sharpeValidation'), key: 'validation', align: 'end', sortable: false },
    { title: t('TradingView.strategies.sharpeTest'), key: 'test', align: 'end', sortable: false },
    { title: t('TradingView.strategies.drawdown'), key: 'drawdown', align: 'end', sortable: false },
    { title: t('TradingView.strategies.paperReturn'), key: 'paper', align: 'end', sortable: false },
    { title: t('TradingView.strategies.reason'), key: 'status_reason', sortable: false },
])
const orderHeaders = computed(() => [
    { title: t('TradingView.orders.time'), key: 'itime' },
    { title: t('TradingView.orders.account'), key: 'account' },
    { title: t('TradingView.orders.symbol'), key: 'symbol' },
    { title: t('TradingView.orders.sideCol'), key: 'side' },
    { title: t('TradingView.orders.typeCol'), key: 'order_type' },
    { title: t('TradingView.orders.qty'), key: 'qty', align: 'end' },
    { title: t('TradingView.orders.price'), key: 'avg_fill_price', align: 'end' },
    { title: t('TradingView.orders.fees'), key: 'fees', align: 'end' },
    { title: t('TradingView.orders.statusCol'), key: 'status' },
    { title: t('TradingView.orders.note'), key: 'error', sortable: false },
])
const logHeaders = computed(() => [
    { title: t('TradingView.orders.time'), key: 'ts' },
    { title: t('TradingView.log.actor'), key: 'actor' },
    { title: t('TradingView.log.event'), key: 'event' },
    { title: t('TradingView.log.details'), key: 'details', sortable: false },
])
const settingSections = computed(() => [...new Set((control.value?.settings || []).map(s => s.section))])

function statusColor(s) {
    return { live: 'error', live_eligible: 'success', paper: 'info', queued: 'default', retired: 'warning', rejected: 'default' }[s] || 'default'
}
function statusIcon(s) {
    return { live: 'mdi-cash', live_eligible: 'mdi-check-decagram', paper: 'mdi-test-tube', queued: 'mdi-timer-sand', retired: 'mdi-archive-outline', rejected: 'mdi-close' }[s] || 'mdi-help'
}
function orderColor(s) {
    return { filled: 'success', partially_filled: 'info', submitted: 'info', submitting: 'info', risk_rejected: 'warning', rejected: 'warning', error: 'error' }[s] || 'default'
}
function unitText(unit) {
    return { percent: '%', currency: summary.value?.currency || 'EUR', minutes: 'min', count: '' }[unit] ?? ''
}
function detailText(details) {
    if (!details) return ''
    return Object.entries(details).filter(([, v]) => v !== null && typeof v !== 'object').map(([k, v]) => `${k}: ${v}`).join(' · ')
}
function paramText(params) {
    return Object.entries(params || {}).map(([k, v]) => `${k}=${v}`).join('  ')
}
function notify(text) {
    snackText.value = text
    snack.value = true
}
async function guarded(fn) {
    try {
        error.value = ''
        return await fn()
    } catch (e) {
        error.value = e.message
    }
}

const loadStrategies = () => guarded(async () => { strategies.value = await store.call('getTradingStrategies', { scope: showAll.value ? 'all' : 'running' }) || [] })
const loadLab = () => guarded(async () => { lab.value = await store.call('getTradingExperiments', { limit: 50 }) })
const loadOrders = () => guarded(async () => { orders.value = await store.call('getTradingOrders', { limit: 300 }) || [] })
const loadLog = () => guarded(async () => { log.value = await store.call('getTradingAudit', { limit: 500, severity: logSeverity.value || '' }) || [] })
const loadControl = () => guarded(async () => {
    control.value = await store.call('getTradingControl')
    limitEdit.value = Object.fromEntries((control.value?.limits || []).map(l => [l.key, Number(l.value)]))
    settingEdit.value = Object.fromEntries((control.value?.settings || []).map(s => [s.key, s.value]))
})

function loadTab() {
    return { overview: () => guarded(() => store.loadDashboard(days.value)), strategies: loadStrategies, lab: loadLab, orders: loadOrders, control: loadControl, log: loadLog }[tab.value]()
}
async function reload() {
    await loadTab()
    if (tab.value !== 'overview') await guarded(() => store.loadSummary())
}
watch(tab, (value) => {
    router.replace({ name: 'trading', query: value === 'overview' ? {} : { tab: value } })
    loadTab()
})
// Menüeinträge springen über ?tab=… in einen Reiter, auch wenn die Seite schon offen ist
watch(() => route.query.tab, (value) => {
    const wanted = tabs.includes(value) ? value : 'overview'
    if (wanted !== tab.value) tab.value = wanted
})
onMounted(async () => {
    await guarded(() => store.loadDashboard(days.value))
    if (tab.value !== 'overview') loadTab()
})

// ── Strategie-Detail ──
const strategyDialog = ref(false)
const detail = ref(null)
async function openStrategy(item) {
    await guarded(async () => {
        detail.value = await store.call('getTradingStrategy', { id: item.id })
        strategyDialog.value = true
    })
}
const detailSeries = computed(() => {
    if (!detail.value) return []
    const paper = detail.value.paper_curve || []
    if (paper.length > 1) return [{ label: t('TradingView.strategies.paperCurve'), points: paper.map(p => ({ x: p.day, y: p.equity })) }]
    const full = detail.value.versions?.[0]?.backtests?.find(b => b.segment === 'full')
    if (!full?.equity_curve) return []
    const start = new Date(full.from_ts).getTime()
    return [{ label: t('TradingView.strategies.backtestCurve'), points: full.equity_curve.map((y, i) => ({ x: new Date(start + i * 86400000).toISOString().slice(0, 10), y })) }]
})

// ── Not-Aus und Sperren ──
async function killSwitch() {
    const answer = await Swal.fire({
        icon: 'warning', title: t('TradingView.killSwitch.confirmTitle'), text: t('TradingView.killSwitch.confirmText'),
        showCancelButton: true, confirmButtonText: t('TradingView.killSwitch.button'), cancelButtonText: t('TradingView.cancel'),
    })
    if (!answer.isConfirmed) return
    await guarded(async () => {
        const r = await store.call('triggerTradingKillSwitch')
        notify(t('TradingView.killSwitch.done', { n: r?.canceled_orders ?? 0 }))
    })
    reload()
}
async function clearLock(lock) {
    const answer = await Swal.fire({
        icon: 'question', title: t('TradingView.lock.clearTitle'), text: t('TradingView.lock.clearText'),
        showCancelButton: true, confirmButtonText: t('TradingView.lock.clear'), cancelButtonText: t('TradingView.cancel'),
    })
    if (!answer.isConfirmed) return
    await guarded(() => store.call('clearTradingLock', { id: lock.id }))
    reload()
}

// ── Echtgeldfreigabe ──
const liveDialog = ref(false)
const liveWord = ref('')
const liveChecks = ref([false, false, false])
watch(liveDialog, (open) => {
    if (open) {
        liveWord.value = ''
        liveChecks.value = [false, false, false]
    }
})
async function enableLive() {
    await guarded(async () => {
        await store.call('enableLiveTrading', { confirmation: liveWord.value })
        notify(t('TradingView.live.enabledDone'))
    })
    liveDialog.value = false
    await loadControl()
    guarded(() => store.loadSummary())
}
async function disableLive() {
    await guarded(async () => {
        await store.call('disableLiveTrading')
        notify(t('TradingView.live.disabledDone'))
    })
    await loadControl()
    guarded(() => store.loadSummary())
}

// ── Grenzen, Einstellungen, Instrumente ──
async function saveLimits() {
    await guarded(async () => {
        await store.call('saveTradingRiskLimits', { limits: limitEdit.value })
        notify(t('TradingView.saved'))
    })
    loadControl()
}
async function saveSettings() {
    await guarded(async () => {
        // Firmenweite Einstellungen schickt nur ein Administrator mit — sonst lehnt die API alles ab
        const company_wide = (control.value?.settings || []).filter(s => s.company_wide).map(s => s.key)
        const settings = Object.fromEntries(Object.entries(settingEdit.value).filter(([key]) => isAdmin.value || !company_wide.includes(key)))
        await store.call('saveTradingSettings', { settings })
        notify(t('TradingView.saved'))
    })
    loadControl()
}
async function saveInstrument(instrument, active) {
    await guarded(() => store.call('saveTradingInstrument', { id: instrument.id, active }))
    loadControl()
}
async function runEngine(research) {
    running.value = true
    await guarded(async () => {
        const r = await store.call('runTradingEngine', { research })
        notify(r?.research?.summary || r?.skipped || t('TradingView.lab.runDone'))
    })
    running.value = false
    reload()
}

// ── Broker ──
const connDialog = ref(false)
const conn = ref({})
function editConnection(c = null) {
    conn.value = c
        ? { id: c.id, name: c.name, adapter: c.adapter, environment: c.environment, capital_limit: Number(c.capital_limit), active: c.active, api_key: '', api_secret: '' }
        : { id: 0, name: '', adapter: control.value?.adapters?.[0] || 'kraken', environment: 'sandbox', capital_limit: 0, active: true, api_key: '', api_secret: '' }
    connDialog.value = true
}
async function saveConnection() {
    await guarded(async () => {
        await store.call('saveTradingBrokerConnection', conn.value)
        connDialog.value = false
        notify(t('TradingView.saved'))
    })
    loadControl()
}
async function testConnection(c) {
    testing.value = c.id
    await guarded(async () => {
        const r = await store.call('testTradingBrokerConnection', { id: c.id })
        notify(t('TradingView.broker.testOk') + ' ' + Object.entries(r?.balances || {}).filter(([, v]) => v > 0).map(([k, v]) => `${k} ${fmtNum(v, 4)}`).join(', '))
    })
    testing.value = null
}
</script>

<style scoped>
.trading-page { padding: var(--oserp-page-pad); }
.trading-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: var(--oserp-gap);
}
.trading-tile {
    background: var(--oserp-surface);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius);
    padding: var(--oserp-gap) var(--oserp-gap-lg);
}
.trading-tile__label { color: var(--oserp-text-muted); font-size: 0.8rem; }
.trading-tile__value { font-size: 1.35rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.trading-tile__hint { color: var(--oserp-text-muted); font-size: 0.75rem; }
.trading-muted { color: var(--oserp-text-muted); font-size: 0.85rem; }
.trading-subtitle { font-weight: var(--oserp-section-title-weight); margin: var(--oserp-gap) 0 var(--oserp-gap-xs); }
.trading-list { padding-left: var(--oserp-gap-xl); }
.trading-params { display: block; margin-bottom: var(--oserp-gap-sm); white-space: pre-wrap; font-size: 0.8rem; }
</style>
