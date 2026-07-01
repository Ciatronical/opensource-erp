<template>
    <NavbarView />
    <v-container fluid>

        <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline" class="mb-3" :text="t('BankingView.menu.info')" />

        <!-- Header -->
        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">{{ t('BankingView.menu.title') }}</h1>
            </v-col>
            <v-col cols="auto">
                <v-select
                    v-model="selectedAccountId"
                    :items="banking.accounts.value"
                    item-title="name"
                    item-value="id"
                    density="compact"
                    hide-details
                    variant="outlined"
                    style="min-width:240px"
                    prepend-inner-icon="mdi-bank"
                />
            </v-col>
            <v-col cols="auto" class="d-flex ga-2">
                <v-btn
                    v-if="selectedAccount?.fints_id"
                    variant="text"
                    size="small"
                    :loading="banking.loading.value && syncingAccountId === selectedAccountId"
                    @click="openSyncDialog(selectedAccount)"
                >
                    <v-icon start>mdi-sync</v-icon>
                    {{ t('BankingView.overview.syncButton') }}
                </v-btn>
                <v-btn
                    v-if="banking.accounts.value.filter(a => a.fints_id).length > 1"
                    variant="text"
                    size="small"
                    :loading="syncingAll"
                    @click="syncAllAccounts"
                >
                    <v-icon start>mdi-sync-circle</v-icon>
                    {{ t('BankingView.utils.syncAll') }}
                </v-btn>
                <v-btn variant="text" size="small" @click="openNewStandingOrder()">
                    <v-icon start>mdi-repeat</v-icon>
                    {{ t('BankingView.standing.newOrder') }}
                </v-btn>
                <v-btn variant="text" size="small" @click="openNewMandate()">
                    <v-icon start>mdi-file-sign</v-icon>
                    {{ t('BankingView.mandate.newMandate') }}
                </v-btn>
                <v-btn color="primary" variant="tonal" size="small" @click="openNewTransfer()">
                    <v-icon start>mdi-bank-transfer-out</v-icon>
                    {{ t('BankingView.transfers.newTransfer') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Alerts -->
        <v-alert
            v-for="(alert, i) in bankUtils.alerts.value"
            :key="i"
            :type="alert.level === 'error' ? 'error' : alert.level === 'warning' ? 'warning' : 'info'"
            variant="tonal"
            density="compact"
            closable
            class="mb-2"
        >{{ alert.message }}</v-alert>

        <!-- Statistik-Karten -->
        <v-row class="mb-3">
            <v-col cols="6" sm="3">
                <v-card variant="tonal" :color="(selectedAccount?.balance ?? 0) >= 0 ? 'primary' : 'error'">
                    <v-card-text class="text-center py-3">
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(selectedAccount?.balance) }}</div>
                        <div class="text-body-2">{{ t('BankingView.overview.balance') }}</div>
                        <div class="text-caption text-disabled mt-1">{{ t('BankingView.overview.lastSync') }}: {{ formatDateShort(selectedAccount?.last_sync ?? selectedAccount?.last_transaction_date) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card color="success" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <v-icon size="24" class="mb-1">mdi-arrow-down-bold</v-icon>
                        <div class="text-h6 font-weight-bold">{{ formatCurrency(selectedAccount?.income_this_month) }}</div>
                        <div class="text-body-2">{{ t('BankingView.overview.incomeThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card color="warning" variant="tonal">
                    <v-card-text class="text-center py-3">
                        <v-icon size="24" class="mb-1">mdi-arrow-up-bold</v-icon>
                        <div class="text-h6 font-weight-bold">{{ formatCurrency(selectedAccount?.expenses_this_month) }}</div>
                        <div class="text-body-2">{{ t('BankingView.overview.expensesThisMonth') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card :color="(selectedAccount?.unmatched_count ?? 0) > 0 ? 'warning' : 'default'" variant="tonal" style="cursor:pointer" @click="activeTab = 'reconciliation'">
                    <v-card-text class="text-center py-3">
                        <v-icon size="24" class="mb-1">mdi-link-variant-off</v-icon>
                        <div class="text-h6 font-weight-bold">{{ selectedAccount?.unmatched_count ?? 0 }}</div>
                        <div class="text-body-2">{{ t('BankingView.overview.unmatched') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Tabs -->
        <v-tabs v-model="activeTab" color="primary" class="mb-4">
            <v-tab value="transactions"   prepend-icon="mdi-format-list-bulleted">{{ t('BankingView.transactions.title') }}</v-tab>
            <v-tab value="transfers"      prepend-icon="mdi-bank-transfer-out">{{ t('BankingView.transfers.title') }}</v-tab>
            <v-tab value="liquidity"      prepend-icon="mdi-chart-line">{{ t('BankingView.liquidity.title') }}</v-tab>
            <v-tab value="directdebits"   prepend-icon="mdi-bank-minus">{{ t('BankingView.directdebit.title') }}</v-tab>
            <v-tab value="templates"      prepend-icon="mdi-content-copy">{{ t('BankingView.templates.title') }}</v-tab>
            <v-tab value="standing"       prepend-icon="mdi-repeat">{{ t('BankingView.standing.title') }}</v-tab>
            <v-tab value="mandates"       prepend-icon="mdi-file-sign">{{ t('BankingView.mandate.title') }}</v-tab>
            <v-tab value="reconciliation" prepend-icon="mdi-scale-balance">{{ t('BankingView.reconciliation.title') }}</v-tab>
            <v-tab value="accounts"       prepend-icon="mdi-bank-outline">{{ t('BankingView.menu.overview') }}</v-tab>
        </v-tabs>

        <v-window v-model="activeTab" class="tab-window">

            <!-- ── UMSÄTZE ── -->
            <v-window-item value="transactions">
                <div class="tab-toolbar">
                    <v-btn-toggle v-model="matchFilter" mandatory density="compact" variant="outlined" rounded="lg">
                        <v-btn value="all" size="small">{{ t('BankingView.transactions.filterAll') }}</v-btn>
                        <v-btn value="unmatched" size="small" color="warning">{{ t('BankingView.transactions.filterUnmatched') }}</v-btn>
                        <v-btn value="matched" size="small" color="info">{{ t('BankingView.transactions.filterMatched') }}</v-btn>
                        <v-btn value="booked" size="small" color="success">{{ t('BankingView.transactions.filterBooked') }}</v-btn>
                        <v-btn value="ignored" size="small">{{ t('BankingView.transactions.filterIgnored') }}</v-btn>
                    </v-btn-toggle>
                    <v-text-field
                        v-model="txSearch"
                        :placeholder="t('BankingView.transactions.searchPlaceholder')"
                        prepend-inner-icon="mdi-magnify"
                        density="compact"
                        variant="outlined"
                        rounded="lg"
                        hide-details
                        clearable
                        style="max-width:320px"
                        class="ml-2"
                    />
                    <v-spacer />
                    <v-text-field v-model="fromDate" :label="t('BankingView.transactions.fromDate')" type="date" density="compact" hide-details style="max-width:140px" />
                    <v-text-field v-model="toDate" :label="t('BankingView.transactions.toDate')" type="date" density="compact" hide-details style="max-width:140px" />
                </div>

                <div class="d-flex justify-end ga-2 mb-2">
                    <v-btn variant="text" size="small" :loading="exportingCsv" @click="doExportCsv">
                        <v-icon start>mdi-file-delimited-outline</v-icon>{{ t('BankingView.utils.exportCsv') }}
                    </v-btn>
                    <v-btn variant="text" size="small" :loading="exportingPdf" @click="doExportPdf">
                        <v-icon start>mdi-file-pdf-box</v-icon>{{ t('BankingView.utils.exportPdf') }}
                    </v-btn>
                </div>

                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="txHeaders"
                        :items="displayedTransactions"
                        :loading="banking.loading.value"
                        :items-per-page="50"
                        density="compact"
                        hover
                        :row-props="getTxRowProps"
                        @click:row="onTxRowClick"
                    >
                        <template #item.transdate="{ item }">
                            <span class="text-no-wrap text-body-2">{{ formatDateShort(item.transdate) }}</span>
                        </template>
                        <template #item.amount="{ item }">
                            <span :class="item.amount >= 0 ? 'text-success font-weight-semibold' : 'text-error font-weight-semibold'">
                                {{ formatCurrency(item.amount) }}
                            </span>
                        </template>
                        <template #item.remote_name="{ item }">
                            <div>
                                <div class="font-weight-medium text-body-2">{{ item.remote_name || '—' }}</div>
                                <div v-if="item.remote_iban" class="text-caption text-disabled">{{ formatIban(item.remote_iban) }}</div>
                            </div>
                        </template>
                        <template #item.purpose="{ item }">
                            <div class="text-body-2" style="max-width:380px; white-space:normal">{{ item.purpose || '—' }}</div>
                        </template>
                        <template #item.match_status="{ item }">
                            <v-chip :color="txStatusColor(item.match_status)" size="x-small" variant="tonal">
                                {{ t('BankingView.transactions.filter' + capitalize(item.match_status)) }}
                            </v-chip>
                        </template>
                        <template #item.assignments="{ item }">
                            <div v-if="item.assignments?.length" class="text-caption">
                                <template v-for="(a, i) in item.assignments" :key="i">
                                    <a
                                        v-if="a.ar_id"
                                        href="#"
                                        class="d-block text-primary text-decoration-none font-weight-medium"
                                        :title="t('BankingView.transactions.openInvoice')"
                                        @click.stop.prevent="goToInvoice(a)"
                                    >{{ a.invnumber }}</a>
                                    <span v-else class="d-block">{{ a.invnumber }}</span>
                                </template>
                            </div>
                            <div v-else-if="item.pending_invnumber" class="text-caption text-medium-emphasis">
                                {{ item.pending_invnumber }}
                            </div>
                        </template>
                        <template #item.actions="{ item }">
                            <div class="d-flex ga-1" @click.stop>
                                <v-btn
                                    v-if="item.match_status === 'unmatched' && item.amount > 0"
                                    icon="mdi-bank-transfer-in"
                                    size="x-small"
                                    variant="text"
                                    color="success"
                                    :title="t('BankingView.booking.titleShort')"
                                    @click="openBookingDialog(item)"
                                />
                                <v-btn
                                    v-if="item.match_status === 'unmatched' && item.amount > 0"
                                    icon="mdi-credit-card-sync-outline"
                                    size="x-small"
                                    variant="text"
                                    color="primary"
                                    :title="t('BankingView.settlement.assign')"
                                    @click="openSettlement(item)"
                                />
                                <v-btn
                                    v-else-if="item.match_status === 'matched'"
                                    icon="mdi-check-circle-outline"
                                    size="x-small"
                                    variant="text"
                                    color="info"
                                    :title="t('BankingView.booking.titleMatched')"
                                    @click="openBookingDialog(item)"
                                />
                                <v-btn v-if="item.match_status === 'unmatched'" icon="mdi-eye-off" size="x-small" variant="text" @click="ignoreTransaction(item)" />
                                <v-btn v-if="item.match_status === 'ignored'" icon="mdi-undo" size="x-small" variant="text" @click="resetTransaction(item)" />
                                <v-btn
                                    v-if="item.match_status === 'booked'"
                                    icon="mdi-undo"
                                    size="x-small"
                                    variant="text"
                                    color="warning"
                                    :title="t('BankingView.booking.unbookTitle')"
                                    @click="unbookTransaction(item)"
                                />
                            </div>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('BankingView.transactions.noTransactions') }}</div>
                        </template>
                    </v-data-table>
                </v-card>

                <!-- Buchungsdialog -->
                <BookingDialog
                    v-if="bookingDialogTransaction"
                    v-model="showBookingDialog"
                    :transaction="bookingDialogTransaction"
                    :account-id="selectedAccountId"
                    @done="onBookingDone"
                    @ignore="onIgnoreFromDialog"
                    @settlement="onSettlementFromBooking"
                />

                <!-- Kartenabrechnung zuordnen -->
                <SettlementDialog
                    v-model="showSettlementDialog"
                    :transaction="settlementTransaction"
                    @booked="onBookingDone"
                />
            </v-window-item>

            <!-- ── ÜBERWEISUNGEN ── -->
            <v-window-item value="transfers">
                <!-- Batch-Banner -->
                <v-alert v-if="batchSelectableCount >= 2 || batchDeletable.length >= 1" type="info" variant="tonal" density="compact" rounded="lg" class="mb-3">
                    <div class="d-flex align-center gap-2">
                        <span class="flex-grow-1">
                            {{ t('BankingView.transfers.batchSelected', { count: batchDeletable.length, total: formatCurrency(batchSelectableTotal) }) }}
                        </span>
                        <v-btn v-if="batchSelectableCount >= 2" color="primary" variant="elevated" size="small" @click="confirmSubmitBatch">
                            <v-icon start>mdi-bank-transfer</v-icon>
                            {{ t('BankingView.transfers.submitBatch') }}
                        </v-btn>
                        <v-btn v-if="batchDeletable.length >= 1" color="error" variant="elevated" size="small" @click="confirmDeleteBatch">
                            <v-icon start>mdi-delete</v-icon>
                            {{ t('BankingView.transfers.batchDeleteSelected', { count: batchDeletable.length }) }}
                        </v-btn>
                    </div>
                </v-alert>

                <div class="tab-toolbar">
                    <span class="text-body-2 text-disabled">{{ transfers.transferOrders.value.length }} {{ t('BankingView.transfers.noOrders').includes('Keine') ? 'Aufträge' : 'orders' }}</span>
                    <v-spacer />
                    <v-btn variant="text" size="small" :loading="reconciling" @click="runReconcile">
                        <v-icon start>mdi-refresh</v-icon>{{ t('BankingView.transfers.reconcile') }}
                    </v-btn>
                </div>

                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        v-model="selectedIds"
                        :headers="transferHeaders"
                        :items="transfers.transferOrders.value"
                        :loading="transfers.loading.value"
                        :items-per-page="50"
                        density="compact"
                        hover
                        show-select
                        return-object
                        item-value="id"
                    >
                        <!-- Sammel-Pfeil im Tabellenkopf: sendet alle offenen Entwuerfe auf einmal -->
                        <template #header.actions>
                            <v-btn
                                v-if="draftCount > 0"
                                icon="mdi-send"
                                size="small"
                                variant="tonal"
                                color="primary"
                                :loading="transfers.loading.value"
                                :title="t('BankingView.transfers.submitAllHint', { count: draftCount })"
                                @click="confirmSubmitAllDrafts"
                            />
                        </template>

                        <template #item.status="{ item }">
                            <div>
                                <v-chip :color="transferStatusColor(item.status)" size="x-small" variant="tonal">
                                    {{ t('BankingView.transfers.status' + capitalize(item.status)) }}
                                </v-chip>
                                <div v-if="item.status === 'executed' && item.executed_at" class="text-caption text-success mt-1">{{ formatDateShort(item.executed_at) }}</div>
                                <div v-else-if="item.status === 'submitted' && item.submitted_at" class="text-caption text-disabled mt-1">{{ formatDateShort(item.submitted_at) }}</div>
                            </div>
                        </template>
                        <template #item.amount="{ item }">
                            <span class="font-weight-semibold">{{ formatCurrency(item.amount) }}</span>
                        </template>
                        <template #item.remote_name="{ item }">
                            <div>
                                <div class="font-weight-medium text-body-2">{{ item.remote_name }}</div>
                                <div class="text-caption text-disabled">{{ formatIban(item.remote_iban) }}</div>
                            </div>
                        </template>
                        <template #item.itime="{ item }">
                            <span class="text-caption text-disabled">{{ formatDateShort(item.itime) }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <div class="d-flex ga-1">
                                <v-btn v-if="sendableStatuses.includes(item.status)" icon="mdi-pencil" size="x-small" variant="text" @click="editTransfer(item)" />
                                <v-btn v-if="sendableStatuses.includes(item.status)" icon="mdi-send" size="x-small" variant="text" color="primary" @click="confirmSubmitTransfer(item)" />
                                <v-btn v-if="item.status !== 'executed'" icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteTransfer(item)" />
                                <v-btn
                                    v-if="['submitted','executed'].includes(item.status)"
                                    icon="mdi-printer"
                                    size="x-small" variant="text"
                                    :title="t('BankingView.utils.printConfirmation')"
                                    @click="bankUtils.printTransferConfirmation(item.id).catch(e => alerts.error(e.message))"
                                />
                            </div>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('BankingView.transfers.noOrders') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

            <!-- ── LIQUIDITÄT ── -->
            <v-window-item value="liquidity">
                <div v-if="!selectedAccountId" class="text-center text-disabled py-8">Konto auswählen</div>
                <template v-else>
                    <div class="d-flex align-center mb-3">
                        <span class="text-body-2 text-disabled">{{ t('BankingView.liquidity.forecast') }}</span>
                        <v-spacer />
                        <v-btn variant="text" size="small" color="deep-orange" class="mr-1"
                               :loading="bankUtils.aiForecastLoading.value" @click="loadAIForecast">
                            <v-icon start>mdi-brain</v-icon>{{ t('BankingView.liquidity.aiButton') }}
                        </v-btn>
                        <v-btn variant="text" size="small" :loading="bankUtils.forecastLoading.value" @click="loadForecast">
                            <v-icon start>mdi-refresh</v-icon>Aktualisieren
                        </v-btn>
                    </div>

                    <v-card v-if="bankUtils.forecast.value.length || bankUtils.aiForecast.value.length" rounded="lg" elevation="0" border class="mb-4 pa-3">
                        <LineChart :data="forecastChartData" :options="forecastChartOptions" style="height:220px" />
                    </v-card>

                    <!-- Kompakttabelle: nur Tage mit Bewegung + erster/letzter Tag -->
                    <v-card rounded="lg" elevation="0" border>
                        <v-data-table
                            :headers="forecastHeaders"
                            :items="forecastTableRows"
                            :items-per-page="20"
                            density="compact"
                        >
                            <template #item.date="{ item }">
                                <span class="text-body-2">{{ item.label || formatDateShort(item.date) }}</span>
                            </template>
                            <template #item.delta="{ item }">
                                <span v-if="item.delta !== 0" :class="item.delta > 0 ? 'text-success font-weight-medium' : 'text-error font-weight-medium'">
                                    {{ item.delta > 0 ? '+ ' : '− ' }}{{ formatCurrency(Math.abs(item.delta)) }}
                                </span>
                                <span v-else class="text-disabled">—</span>
                            </template>
                            <template #item.balance="{ item }">
                                <span :class="item.balance >= 0 ? 'text-success' : 'text-error'" class="font-weight-medium">
                                    {{ formatCurrency(item.balance) }}
                                </span>
                            </template>
                            <template #item.ai_balance="{ item }">
                                <span v-if="item.ai_balance !== null"
                                      :class="item.ai_balance >= 0 ? 'text-deep-orange' : 'text-error'"
                                      class="font-weight-medium">
                                    {{ formatCurrency(item.ai_balance) }}
                                </span>
                                <span v-else class="text-disabled">—</span>
                            </template>
                            <template #no-data>
                                <div class="text-center pa-6 text-disabled">{{ t('BankingView.liquidity.noForecast') }}</div>
                            </template>
                        </v-data-table>
                    </v-card>
                </template>
            </v-window-item>

            <!-- ── LASTSCHRIFTEN ── -->
            <v-window-item value="directdebits">
                <div class="tab-toolbar mb-3">
                    <v-btn color="primary" variant="tonal" size="small" @click="openNewDirectDebit">
                        <v-icon start>mdi-plus</v-icon>{{ t('BankingView.directdebit.newOrder') }}
                    </v-btn>
                </div>
                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="ddHeaders"
                        :items="bankUtils.directDebits.value"
                        :loading="bankUtils.ddLoading.value"
                        :items-per-page="50"
                        density="compact"
                        hover
                    >
                        <template #item.status="{ item }">
                            <v-chip :color="transferStatusColor(item.status)" size="x-small" variant="tonal">
                                {{ t('BankingView.transfers.status' + capitalize(item.status)) }}
                            </v-chip>
                        </template>
                        <template #item.amount="{ item }">
                            <span class="font-weight-semibold">{{ formatCurrency(item.amount) }}</span>
                        </template>
                        <template #item.actions="{ item }">
                            <div class="d-flex ga-1">
                                <v-btn
                                    v-if="item.status === 'draft'"
                                    size="x-small" variant="elevated" color="primary"
                                    :title="t('BankingView.directdebit.submit')"
                                    @click="confirmSubmitDD(item)"
                                >
                                    <v-icon>mdi-send</v-icon>
                                </v-btn>
                                <v-btn v-if="item.status === 'draft'" icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteDD(item)" />
                            </div>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('BankingView.directdebit.noOrders') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

            <!-- ── VORLAGEN ── -->
            <v-window-item value="templates">
                <!-- Gruppen-Reihe -->
                <div class="d-flex align-center mb-3 flex-wrap ga-2">
                    <v-btn-group
                        v-for="g in tmplStore.groups.value"
                        :key="g.id"
                        :color="g.color || 'primary'"
                        :variant="selectedGroupId === g.id ? 'elevated' : 'tonal'"
                        density="compact"
                        rounded="sm"
                        divided
                    >
                        <v-btn size="small" @click="selectGroup(g.id)">
                            <v-icon start size="14">mdi-folder-outline</v-icon>
                            {{ g.name }}
                            <span class="ml-2 text-caption font-weight-bold">{{ g.template_count }}</span>
                        </v-btn>
                        <v-btn size="small" @click.stop="editGroup(g)">
                            <v-icon size="14">mdi-pencil</v-icon>
                        </v-btn>
                    </v-btn-group>
                    <v-btn
                        v-if="ungroupedTemplateCount > 0 || selectedGroupId === -1"
                        :variant="selectedGroupId === -1 ? 'elevated' : 'tonal'"
                        color="default"
                        density="compact"
                        rounded="sm"
                        size="small"
                        @click="selectGroup(-1)"
                    >
                        <v-icon start size="14">mdi-folder-open-outline</v-icon>
                        {{ t('BankingView.templates.noGroupLabel') }}
                        <span class="ml-2 text-caption font-weight-bold">{{ ungroupedTemplateCount }}</span>
                    </v-btn>
                    <v-btn color="default" variant="outlined" density="compact" rounded="sm" size="small" @click="openNewGroup">
                        <v-icon start size="14">mdi-plus</v-icon>
                        {{ t('BankingView.templates.newGroup') }}
                    </v-btn>
                </div>

                <!-- Aktionsleiste -->
                <div class="tab-toolbar mb-3">
                    <v-btn color="primary" variant="tonal" size="small" @click="openNewTemplate()">
                        <v-icon start>mdi-plus</v-icon>{{ t('BankingView.templates.newTemplate') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn
                        v-if="groupTemplates.length > 0"
                        color="success" variant="elevated" size="small"
                        :disabled="selectedTemplateIds.length === 0"
                        @click="executeSelectedTemplates"
                    >
                        <v-icon start>mdi-send</v-icon>
                        {{ t('BankingView.templates.executeSelected', { n: selectedTemplateIds.length || groupTemplates.length }) }}
                    </v-btn>
                    <v-btn
                        v-if="groupTemplates.length > 0"
                        color="primary" variant="elevated" size="small"
                        @click="executeAllTemplates"
                    >
                        <v-icon start>mdi-send-check</v-icon>
                        {{ t('BankingView.templates.executeAll', { n: groupTemplates.length }) }}
                    </v-btn>
                </div>

                <!-- Hinweis wenn keine Gruppe gewählt -->
                <div v-if="selectedGroupId === null" class="text-center text-disabled py-8">
                    {{ t('BankingView.templates.noGroupSelected') }}
                </div>

                <!-- Template-Liste -->
                <v-card v-else rounded="lg" elevation="0" border>
                    <div v-if="groupTemplates.length > 0" class="d-flex align-center px-3 py-1 border-b">
                        <v-checkbox-btn
                            :model-value="allTemplatesSelected"
                            :indeterminate="someTemplatesSelected"
                            density="compact"
                            hide-details
                            @click="toggleAllTemplates"
                        />
                        <span class="text-caption text-disabled ml-1">{{ t('BankingView.templates.selectAll') }}</span>
                    </div>
                    <v-list density="compact" lines="two">
                        <v-list-item v-if="groupTemplates.length === 0" class="text-center text-disabled py-6">
                            {{ t('BankingView.templates.noTemplates') }}
                        </v-list-item>
                        <v-list-item
                            v-for="tmpl in groupTemplates"
                            :key="tmpl.id"
                            :class="{ 'bg-primary-lighten-5': selectedTemplateIds.includes(tmpl.id) }"
                            @click="toggleTemplateSelection(tmpl.id)"
                        >
                            <template #prepend>
                                <v-checkbox-btn
                                    :model-value="selectedTemplateIds.includes(tmpl.id)"
                                    density="compact"
                                    @click.stop="toggleTemplateSelection(tmpl.id)"
                                />
                            </template>
                            <v-list-item-title class="d-flex align-center">
                                <span class="font-weight-medium">{{ tmpl.remote_name }}</span>
                                <span class="mx-2 text-disabled text-caption">{{ formatIban(tmpl.remote_iban) }}</span>
                                <v-spacer />
                                <span class="font-weight-semibold text-body-2">{{ formatCurrency(tmpl.amount) }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <span class="text-caption">{{ tmpl.purpose_template }}</span>
                                <span v-if="hasPlaceholders(tmpl.purpose_template)" class="ml-2 text-primary text-caption">
                                    → {{ resolvePurpose(tmpl.purpose_template) }}
                                </span>
                            </v-list-item-subtitle>
                            <template #append>
                                <div class="d-flex ga-1">
                                    <v-btn icon="mdi-pencil" size="x-small" variant="text" @click.stop="openEditTemplate(tmpl)" />
                                    <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click.stop="confirmDeleteTemplate(tmpl)" />
                                </div>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-window-item>

            <!-- ── DAUERAUFTRÄGE ── -->
            <v-window-item value="standing">
                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="standingHeaders"
                        :items="soStore.standingOrders.value"
                        :loading="soStore.loading.value"
                        :items-per-page="50"
                        density="compact"
                        hover
                    >
                        <template #item.status="{ item }">
                            <v-chip :color="soStatusColor(item.status)" size="x-small" variant="tonal">
                                {{ t('BankingView.standing.status' + capitalize(item.status)) }}
                            </v-chip>
                        </template>
                        <template #item.amount="{ item }">
                            <span class="font-weight-semibold">{{ formatCurrency(item.amount) }}</span>
                        </template>
                        <template #item.frequency="{ item }">
                            {{ t('BankingView.standing.freq' + capitalize(item.frequency)) }}
                        </template>
                        <template #item.next_execution_date="{ item }">
                            <span :class="isOverdue(item.next_execution_date) ? 'text-error font-weight-medium' : ''">
                                {{ formatDateShort(item.next_execution_date) }}
                            </span>
                        </template>
                        <template #item.actions="{ item }">
                            <div class="d-flex ga-1">
                                <v-btn
                                    v-if="item.status === 'active'"
                                    size="x-small" variant="elevated" color="primary"
                                    :title="t('BankingView.standing.execute')"
                                    @click="runStandingOrder(item)"
                                >
                                    <v-icon>mdi-send</v-icon>
                                </v-btn>
                                <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="editStandingOrder(item)" />
                                <v-btn
                                    :icon="item.status === 'active' ? 'mdi-pause' : 'mdi-play'"
                                    size="x-small" variant="text"
                                    :color="item.status === 'active' ? 'warning' : 'success'"
                                    @click="toggleStandingOrderStatus(item)"
                                />
                                <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteStandingOrder(item)" />
                            </div>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('BankingView.standing.noOrders') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

            <!-- ── EINZUGSERMÄCHTIGUNGEN ── -->
            <v-window-item value="mandates">
                <v-card rounded="lg" elevation="0" border>
                    <v-data-table
                        :headers="mandateHeaders"
                        :items="soStore.mandates.value"
                        :loading="soStore.loading.value"
                        :items-per-page="50"
                        density="compact"
                        hover
                    >
                        <template #item.status="{ item }">
                            <v-chip :color="item.status === 'active' ? 'success' : 'default'" size="x-small" variant="tonal">
                                {{ t('BankingView.mandate.status' + capitalize(item.status)) }}
                            </v-chip>
                        </template>
                        <template #item.max_amount="{ item }">
                            <span v-if="item.max_amount">{{ formatCurrency(item.max_amount) }}</span>
                            <span v-else class="text-disabled">—</span>
                        </template>
                        <template #item.revoked_date="{ item }">
                            <span v-if="item.revoked_date" class="text-error">{{ formatDateShort(item.revoked_date) }}</span>
                            <span v-else class="text-disabled">—</span>
                        </template>
                        <template #item.actions="{ item }">
                            <div class="d-flex ga-1">
                                <v-btn icon="mdi-pencil" size="x-small" variant="text" @click="editMandate(item)" />
                                <v-btn
                                    v-if="item.status === 'active'"
                                    icon="mdi-cancel"
                                    size="x-small" variant="text" color="warning"
                                    :title="t('BankingView.mandate.revoke')"
                                    @click="confirmRevokeMandate(item)"
                                />
                                <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="confirmDeleteMandate(item)" />
                            </div>
                        </template>
                        <template #no-data>
                            <div class="text-center pa-6 text-disabled">{{ t('BankingView.mandate.noMandates') }}</div>
                        </template>
                    </v-data-table>
                </v-card>
            </v-window-item>

            <!-- ── ABGLEICH ── -->
            <v-window-item value="reconciliation">
                <v-row v-if="selectedAccountId">
                    <v-col cols="12" lg="6">
                        <v-card rounded="lg" elevation="0" border>
                            <v-card-title class="d-flex align-center text-body-1 font-weight-semibold pa-3">
                                {{ t('BankingView.reconciliation.bankTransactions') }}
                                <v-spacer />
                                <v-btn color="primary" variant="tonal" size="small" :loading="matching.loading.value" @click="runAutoMatchAction">
                                    <v-icon start>mdi-auto-fix</v-icon>{{ t('BankingView.reconciliation.autoMatch') }}
                                </v-btn>
                            </v-card-title>
                            <v-divider />
                            <div v-if="selectedForBooking.length > 0" class="pa-2 bg-primary-lighten-5 d-flex align-center">
                                <span class="text-caption">{{ selectedForBooking.length }} ausgewählt</span>
                                <v-spacer />
                                <v-btn color="primary" size="x-small" variant="elevated" @click="bookSelectedAction">
                                    {{ t('BankingView.reconciliation.bookSelected') }}
                                </v-btn>
                            </div>
                            <v-list density="compact" class="recon-list">
                                <v-list-item v-if="unmatchedTransactions.length === 0" class="text-center text-disabled py-6">
                                    {{ t('BankingView.transactions.noTransactions') }}
                                </v-list-item>
                                <v-list-item
                                    v-for="bt in unmatchedTransactions"
                                    :key="bt.id"
                                    :class="{ 'selected-tx': selectedTransaction?.id === bt.id }"
                                    rounded="0"
                                    @click="selectTransaction(bt)"
                                >
                                    <template #prepend>
                                        <v-checkbox-btn v-if="bt.match_status === 'matched'" v-model="selectedForBooking" :value="bt.id" density="compact" />
                                        <v-icon v-else :color="bt.amount >= 0 ? 'success' : 'error'" size="18">
                                            {{ bt.amount >= 0 ? 'mdi-arrow-down-circle' : 'mdi-arrow-up-circle' }}
                                        </v-icon>
                                    </template>
                                    <v-list-item-title class="d-flex align-center text-body-2">
                                        <span :class="bt.amount >= 0 ? 'text-success font-weight-semibold' : 'text-error font-weight-semibold'">{{ formatCurrency(bt.amount) }}</span>
                                        <span class="mx-2 text-disabled">·</span>
                                        <span class="text-truncate">{{ bt.remote_name || bt.purpose }}</span>
                                        <v-spacer />
                                        <span class="text-caption text-disabled text-no-wrap">{{ formatDateShort(bt.transdate) }}</span>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="bt.match_status === 'matched' && bt.matched_invoice" class="text-caption text-success">
                                        ↔ {{ bt.matched_invoice }}
                                    </v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-card>
                    </v-col>

                    <v-col cols="12" lg="6">
                        <v-card rounded="lg" elevation="0" border>
                            <v-card-title class="d-flex align-center text-body-1 font-weight-semibold pa-3">
                                {{ t('BankingView.reconciliation.openInvoices') }}
                                <v-tabs v-model="invoiceTab" density="compact" class="ml-2">
                                    <v-tab value="receivables" size="small">{{ t('BankingView.reconciliation.receivables') }}</v-tab>
                                    <v-tab value="payables" size="small">{{ t('BankingView.reconciliation.payables') }}</v-tab>
                                </v-tabs>
                                <v-spacer />
                                <v-text-field v-model="invoiceSearch" :placeholder="t('BankingView.reconciliation.searchInvoices')" density="compact" hide-details prepend-inner-icon="mdi-magnify" style="max-width:180px" />
                            </v-card-title>
                            <v-divider />
                            <v-list density="compact" class="recon-list">
                                <v-list-item v-if="filteredInvoices.length === 0" class="text-center text-disabled py-6">
                                    —
                                </v-list-item>
                                <v-list-item
                                    v-for="inv in filteredInvoices"
                                    :key="inv.id"
                                    rounded="0"
                                    @click="matchInvoiceToTransaction(inv)"
                                >
                                    <v-list-item-title class="d-flex align-center text-body-2">
                                        <span class="font-weight-medium">{{ inv.invnumber }}</span>
                                        <span class="mx-2 text-disabled">·</span>
                                        <span class="text-truncate">{{ inv.customer_name || inv.vendor_name }}</span>
                                        <v-spacer />
                                        <span :class="invoiceTab === 'receivables' ? 'text-success font-weight-semibold' : 'text-error font-weight-semibold'" class="text-no-wrap">
                                            {{ formatCurrency(inv.open_amount) }}
                                        </span>
                                    </v-list-item-title>
                                    <v-list-item-subtitle class="text-caption text-disabled">
                                        {{ t('BankingView.reconciliation.dueDate') }}: {{ formatDateShort(inv.duedate) }}
                                    </v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-card>
                    </v-col>
                </v-row>
                <v-alert v-else type="info" variant="tonal" rounded="lg">{{ t('BankingView.overview.noAccounts') }}</v-alert>
            </v-window-item>

            <!-- ── KONTEN ── -->
            <v-window-item value="accounts">
                <v-alert v-if="!banking.loading.value && banking.accounts.value.length === 0" type="info" variant="tonal" rounded="lg" class="mb-4">
                    {{ t('BankingView.overview.noAccounts') }}
                </v-alert>
                <v-row>
                    <v-col v-for="account in banking.accounts.value" :key="account.id" cols="12" md="6" lg="4">
                        <v-card rounded="lg" elevation="0" border class="account-card" @click="selectedAccountId = account.id; activeTab = 'transactions'">
                            <v-card-title class="d-flex align-center pa-4">
                                <v-avatar color="primary" size="40" class="mr-3">
                                    <v-icon color="white">mdi-bank</v-icon>
                                </v-avatar>
                                <div>
                                    <div class="text-body-1 font-weight-semibold">{{ account.name }}</div>
                                    <div class="text-caption text-disabled">{{ formatIban(account.iban) }}</div>
                                </div>
                                <v-spacer />
                                <v-chip v-if="account.fints_id" color="success" size="x-small" variant="tonal">FinTS</v-chip>
                            </v-card-title>
                            <v-divider />
                            <v-card-text class="pa-4">
                                <div class="text-h5 font-weight-bold mb-1" :class="account.balance >= 0 ? 'text-success' : 'text-error'">
                                    {{ formatCurrency(account.balance) }}
                                </div>
                                <div class="d-flex gap-4 text-caption text-disabled">
                                    <span>↑ {{ formatCurrency(account.income_this_month) }}</span>
                                    <span>↓ {{ formatCurrency(account.expenses_this_month) }}</span>
                                    <span v-if="account.unmatched_count > 0" class="text-warning">{{ account.unmatched_count }} nicht zugeordnet</span>
                                </div>
                            </v-card-text>
                            <v-card-actions class="px-4 pb-3" @click.stop>
                                <v-btn variant="text" size="small" color="primary" @click="selectedAccountId = account.id; activeTab = 'transactions'">
                                    <v-icon start>mdi-format-list-bulleted</v-icon>Umsätze
                                </v-btn>
                                <v-spacer />
                                <v-btn variant="text" size="small" @click="openFintsSetup(account)">
                                    <v-icon start>mdi-cog</v-icon>
                                    {{ account.fints_id ? t('BankingView.overview.editFints') : t('BankingView.overview.setupFints') }}
                                </v-btn>
                                <v-btn v-if="account.fints_id" variant="tonal" color="primary" size="small" @click="openSyncDialog(account)">
                                    <v-icon start>mdi-sync</v-icon>Sync
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </v-window-item>
        </v-window>
    </v-container>

    <!-- ══════════════════════ DIALOGE ══════════════════════ -->

    <!-- FinTS Setup -->
    <v-dialog v-model="showFintsDialog" max-width="560" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ t('BankingView.fints.title') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <v-text-field v-model="fintsForm.fints_bank_code" :label="t('BankingView.fints.bankCode')" class="mb-3" @update:model-value="onBankCodeChange" />
                <v-text-field v-model="fintsForm.fints_url" :label="t('BankingView.fints.url')" :hint="fintsBankName ? t('BankingView.fints.urlAutodetected', { bank: fintsBankName }) : t('BankingView.fints.urlHint')" persistent-hint class="mb-3" />
                <v-text-field v-model="fintsForm.fints_username" :label="t('BankingView.fints.username')" class="mb-3" />
                <v-select v-model="fintsForm.fints_tan_mode" :label="t('BankingView.fints.tanMode')" :items="tanModeOptions" :hint="t('BankingView.fints.tanModeHint')" persistent-hint clearable class="mb-3" />
                <v-text-field v-model="fintsForm.sync_from_date" :label="t('BankingView.fints.syncFromDate')" type="date" />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn v-if="fintsForm.existing" color="error" variant="text" @click="removeFintsConfig">{{ t('BankingView.fints.delete') }}</v-btn>
                <v-spacer />
                <v-btn variant="text" @click="showFintsDialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" @click="saveFintsSetup">{{ t('BankingView.fints.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Sync (PIN) -->
    <v-dialog v-model="showSyncDialog" max-width="420" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ t('BankingView.sync.title') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <div class="text-body-2 text-disabled mb-3">{{ syncAccount?.name }} — {{ formatIban(syncAccount?.iban) }}</div>
                <v-text-field v-model="syncPin" :label="t('BankingView.sync.pin')" :hint="t('BankingView.sync.pinHint')" persistent-hint type="password" autocomplete="off" class="mb-3" @keyup.enter="startSync" />
                <div class="d-flex align-center">
                    <v-checkbox v-model="rememberPin" :label="t('BankingView.sync.rememberPin')" density="compact" hide-details />
                    <v-btn v-if="syncAccount?.has_saved_pin" size="x-small" variant="text" color="error" class="ml-2" @click="deleteSavedPin">{{ t('BankingView.sync.deletePin') }}</v-btn>
                </div>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="closeSyncDialog">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :loading="banking.loading.value" @click="startSync">{{ t('BankingView.sync.start') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- TAN (Sync) -->
    <v-dialog v-model="showTanDialog" max-width="420" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ banking.tanChallenge.decoupled ? t('BankingView.tan.titleDecoupled') : t('BankingView.tan.title') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <div v-if="banking.tanChallenge.challenge" class="mb-3">
                    <div class="text-caption text-disabled">{{ t('BankingView.tan.challenge') }}</div>
                    <div class="text-body-1 font-weight-medium">{{ banking.tanChallenge.challenge }}</div>
                </div>
                <v-alert v-if="banking.tanChallenge.decoupled" type="info" variant="tonal" density="compact">
                    {{ banking.tanChallenge.message || t('BankingView.tan.decoupledHint') }}
                </v-alert>
                <v-text-field v-else v-model="tanInput" :label="t('BankingView.tan.tanInput')" type="text" inputmode="numeric" autocomplete="one-time-code" @keyup.enter="submitTanAction" />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="showTanDialog = false">{{ t('BankingView.tan.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :loading="banking.loading.value" @click="submitTanAction">
                    {{ banking.tanChallenge.decoupled ? t('BankingView.tan.continue') : t('BankingView.tan.submit') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Überweisung: Neu/Bearbeiten -->
    <v-dialog v-model="showTransferDialog" max-width="640" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ editingId ? t('BankingView.transfers.editTransfer') : t('BankingView.transfers.newTransfer') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <v-select v-model="transferForm.bank_account_id" :items="banking.accounts.value" item-title="name" item-value="id" :label="t('BankingView.transfers.fromAccount')" class="mb-3" />
                <RecipientAutocomplete v-if="!editingId" v-model="recipient" class="mb-2" />
                <div v-if="recipient" class="mb-3 d-flex align-center pa-3 bg-grey-lighten-4 rounded-lg">
                    <v-chip size="x-small" :color="recipient.recipient_type === 'customer' ? 'primary' : 'secondary'" variant="tonal" class="mr-3">
                        {{ recipient.recipient_type === 'customer' ? t('BankingView.transfers.customerShort') : t('BankingView.transfers.vendorShort') }}
                    </v-chip>
                    <div class="flex-grow-1">
                        <div class="text-body-2 font-weight-medium">{{ recipient.name }}</div>
                        <div class="text-caption text-disabled">{{ recipient.number }}</div>
                    </div>
                    <v-btn icon="mdi-close-circle" size="small" variant="text" @click="clearRecipient" />
                </div>
                <v-text-field v-if="!recipient" v-model="transferForm.remote_name" :label="t('BankingView.transfers.recipientName')" class="mb-3" />
                <v-text-field v-model="transferForm.remote_iban" :label="t('BankingView.transfers.recipientIban')" :error-messages="ibanError ? [ibanError] : []" :hint="ibanHint" :persistent-hint="!!ibanHint" class="mb-3" @blur="autofillBicFromIban">
                    <template v-if="ibanValid" #append-inner><v-icon color="success">mdi-check-circle</v-icon></template>
                </v-text-field>
                <v-text-field v-model="transferForm.remote_bic" :label="t('BankingView.transfers.recipientBic')" :error-messages="bicError ? [bicError] : []" :hint="bicAutoFillHint" :persistent-hint="!!bicAutoFillHint" class="mb-3" />
                <v-row dense>
                    <v-col cols="6">
                        <v-text-field v-model.number="transferForm.amount" :label="t('BankingView.transfers.amount')" type="number" min="0.01" step="0.01" prefix="EUR" />
                    </v-col>
                    <v-col cols="6">
                        <v-text-field v-model="transferForm.execution_date" :label="t('BankingView.transfers.executionDate')" :hint="t('BankingView.transfers.executionDateHint')" :persistent-hint="!!transferForm.execution_date" :error-messages="executionDateError ? [executionDateError] : []" :disabled="!!transferForm.instant" :min="todayIso" :max="maxExecutionDateIso" type="date" clearable />
                    </v-col>
                </v-row>
                <v-text-field v-model="transferForm.purpose" :label="t('BankingView.transfers.purpose')" :hint="t('BankingView.transfers.purposeHint')" persistent-hint counter="140" maxlength="140" class="mb-2" />
                <v-checkbox v-model="transferForm.instant" :label="t('BankingView.transfers.instantLabel')" :hint="t('BankingView.transfers.instantHint')" :disabled="!!transferForm.execution_date" density="compact" hide-details />
                <!-- Als Vorlage speichern -->
                <v-divider class="my-3" />
                <v-checkbox v-model="transferForm.saveAsTemplate" :label="t('BankingView.templates.saveAsTemplate')" density="compact" hide-details class="mb-2" />
                <v-expand-transition>
                    <v-row v-if="transferForm.saveAsTemplate" dense>
                        <v-col cols="6">
                            <v-text-field v-model="transferForm.templateName" :label="t('BankingView.templates.templateName')" density="compact" hide-details />
                        </v-col>
                        <v-col cols="6">
                            <v-select v-model="transferForm.templateGroupId" :items="tmplStore.groups.value" item-title="name" item-value="id" :label="t('BankingView.templates.groupAssign')" density="compact" hide-details clearable />
                        </v-col>
                    </v-row>
                </v-expand-transition>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="showTransferDialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :disabled="!canSave" @click="saveTransfer">{{ t('BankingView.transfers.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- PIN für Überweisung -->
    <v-dialog v-model="showPinDialog" max-width="400" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ isBatchSubmit ? t('BankingView.transfers.submitBatch') : t('BankingView.transfers.submit') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <div class="mb-3 pa-3 bg-grey-lighten-4 rounded-lg text-body-2">
                    <span v-if="isBatchSubmit">{{ t('BankingView.transfers.batchSummary', { count: batchSubmitIds.length, total: formatCurrency(batchSubmitTotal) }) }}</span>
                    <span v-else><strong>{{ submitTarget?.remote_name }}</strong> — {{ formatCurrency(submitTarget?.amount) }}</span>
                </div>
                <v-text-field v-model="submitPin" :label="t('BankingView.sync.pin')" :hint="t('BankingView.sync.pinHint')" persistent-hint type="password" autocomplete="off" @keyup.enter="executeSubmitTransfer" />
                <div class="d-flex align-center mt-2">
                    <v-checkbox v-model="rememberTransferPin" :label="t('BankingView.sync.rememberPin')" density="compact" hide-details />
                    <v-btn
                        v-if="submitAccountHasSavedPin"
                        size="x-small" variant="text" color="error" class="ml-2"
                        @click="banking.deleteBankingPin(submitBankAccountId).then(() => { banking.fetchAccounts(); alerts.info(t('BankingView.sync.pinDeleted')) }).catch(e => alerts.error(e.message))"
                    >{{ t('BankingView.sync.deletePin') }}</v-btn>
                </div>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="closePinDialog">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :loading="transfers.loading.value" @click="executeSubmitTransfer">{{ t('BankingView.transfers.submit') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- VoP Dialog -->
    <v-dialog v-model="showVopDialog" max-width="480" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4 d-flex align-center gap-2">
                <v-icon color="warning">mdi-account-question</v-icon>
                {{ t('BankingView.vop.title') }}
            </v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <v-alert type="warning" variant="tonal" density="compact" rounded="lg" class="mb-3">{{ t('BankingView.vop.mismatchHint') }}</v-alert>
                <div class="pa-3 bg-grey-lighten-4 rounded-lg mb-3">
                    <div class="text-caption text-disabled">{{ t('BankingView.transfers.recipient') }}</div>
                    <div class="font-weight-medium">{{ submitTarget?.remote_name }}</div>
                    <div class="text-caption">{{ formatIban(submitTarget?.remote_iban) }}</div>
                </div>
                <div v-if="transfers.vopApproval.closeMatchName" class="mb-3 pa-3 bg-orange-lighten-5 rounded-lg">
                    <div class="text-caption text-disabled">{{ t('BankingView.vop.closeMatch') }}</div>
                    <div class="font-weight-medium">{{ transfers.vopApproval.closeMatchName }}</div>
                </div>
                <div v-if="transfers.vopApproval.naReason" class="text-caption text-warning mb-2">{{ transfers.vopApproval.naReason }}</div>
                <div v-if="transfers.vopApproval.notice" class="text-body-2 mb-2">{{ transfers.vopApproval.notice }}</div>
                <div class="text-caption text-disabled">{{ t('BankingView.vop.codeLabel') }}: <strong>{{ transfers.vopApproval.result }}</strong></div>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="cancelVopApproval">{{ t('BankingView.vop.cancel') }}</v-btn>
                <v-btn color="warning" variant="elevated" :loading="transfers.loading.value" @click="confirmVopApproval">{{ t('BankingView.vop.confirm') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Lastschrift Dialog -->
    <v-dialog v-model="showDDDialog" max-width="560" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ t('BankingView.directdebit.newOrder') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <v-select v-model="ddForm.bank_account_id" :items="banking.accounts.value" item-title="name" item-value="id" :label="t('BankingView.transfers.fromAccount')" class="mb-3" />
                <v-text-field v-model="ddForm.debtor_name" :label="t('BankingView.directdebit.debtor')" class="mb-3" />
                <v-text-field v-model="ddForm.debtor_iban" :label="t('BankingView.directdebit.debtorIban')" class="mb-3" @blur="lookupDDDebtorBic" />
                <v-text-field v-model="ddForm.debtor_bic" label="Schuldner-BIC" class="mb-3" />
                <v-text-field v-model="ddForm.mandate_reference" :label="t('BankingView.directdebit.mandateRef')" class="mb-3" />
                <v-row dense>
                    <v-col cols="5">
                        <v-text-field v-model.number="ddForm.amount" :label="t('BankingView.transfers.amount')" type="number" min="0.01" step="0.01" prefix="EUR" />
                    </v-col>
                    <v-col cols="4">
                        <v-text-field v-model="ddForm.collection_date" :label="t('BankingView.directdebit.collectionDate')" type="date" />
                    </v-col>
                    <v-col cols="3">
                        <v-select v-model="ddForm.sequence_type" :label="t('BankingView.directdebit.sequenceType')" :items="['FRST','RCUR','FNAL','OOFF']" />
                    </v-col>
                </v-row>
                <v-text-field v-model="ddForm.purpose" :label="t('BankingView.transfers.purpose')" maxlength="140" class="mb-3" />
                <v-text-field v-model="ddForm.creditor_id" :label="t('BankingView.directdebit.creditorId')" :hint="t('BankingView.directdebit.creditorIdHint')" persistent-hint />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="showDDDialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :disabled="!canSaveDD" @click="saveDirectDebit">{{ t('BankingView.transfers.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- DD PIN Dialog -->
    <v-dialog v-model="showDDPinDialog" max-width="400" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ t('BankingView.directdebit.submit') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <div class="mb-3 pa-3 bg-grey-lighten-4 rounded-lg text-body-2">
                    <strong>{{ ddSubmitTarget?.debtor_name }}</strong> — {{ formatCurrency(ddSubmitTarget?.amount) }}
                </div>
                <v-text-field v-model="ddPin" :label="t('BankingView.sync.pin')" type="password" autocomplete="off" class="mb-2" @keyup.enter="executeSubmitDD" />
                <v-text-field v-model="ddCreditorId" :label="t('BankingView.directdebit.creditorId')" :hint="t('BankingView.directdebit.creditorIdHint')" persistent-hint />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="showDDPinDialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" @click="executeSubmitDD">{{ t('BankingView.transfers.submit') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Gruppe Dialog -->
    <v-dialog v-model="showGroupDialog" max-width="420" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ editingGroupId ? t('BankingView.templates.editGroup') : t('BankingView.templates.newGroup') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <v-text-field v-model="groupForm.name" :label="t('BankingView.templates.groupName')" class="mb-3" />
                <div class="text-caption text-disabled mb-2">{{ t('BankingView.templates.groupColor') }}</div>
                <div class="d-flex ga-2 flex-wrap">
                    <v-chip
                        v-for="c in groupColors"
                        :key="c.value"
                        :color="c.value"
                        :variant="groupForm.color === c.value ? 'elevated' : 'tonal'"
                        size="small"
                        class="cursor-pointer"
                        @click="groupForm.color = c.value"
                    >{{ c.label }}</v-chip>
                </div>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-btn v-if="editingGroupId" variant="text" color="error" @click="confirmDeleteGroup">{{ t('BankingView.transfers.delete') }}</v-btn>
                <v-spacer />
                <v-btn variant="text" @click="showGroupDialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :disabled="!groupForm.name" @click="saveGroup">{{ t('BankingView.transfers.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Template Dialog -->
    <v-dialog v-model="showTemplateDialog" max-width="600" persistent scrollable>
        <v-card rounded="xl">
            <v-card-title class="pa-4">
                {{ editingTemplateId ? t('BankingView.templates.editTemplate') : t('BankingView.templates.newTemplate') }}
            </v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <!-- Bezeichnung + Gruppe -->
                <v-row dense class="mb-3">
                    <v-col cols="7">
                        <v-text-field v-model="tmplForm.name" :label="t('BankingView.templates.templateName')" hide-details variant="outlined" density="compact" />
                    </v-col>
                    <v-col cols="5">
                        <v-select v-model="tmplForm.group_id" :items="tmplStore.groups.value" item-title="name" item-value="id" :label="t('BankingView.templates.groupAssign')" hide-details clearable variant="outlined" density="compact" />
                    </v-col>
                </v-row>
                <!-- Absenderkonto -->
                <v-select v-model="tmplForm.bank_account_id" :items="banking.accounts.value" item-title="name" item-value="id" :label="t('BankingView.transfers.fromAccount')" class="mb-3" variant="outlined" density="compact" hide-details />
                <!-- Empfänger-Autocomplete -->
                <RecipientAutocomplete v-model="tmplRecipient" class="mb-2" />
                <div v-if="tmplRecipient" class="mb-3 d-flex align-center pa-3 bg-grey-lighten-4 rounded-lg">
                    <v-chip size="x-small" :color="tmplRecipient.recipient_type === 'customer' ? 'primary' : 'secondary'" variant="tonal" class="mr-3">
                        {{ tmplRecipient.recipient_type === 'customer' ? t('BankingView.transfers.customerShort') : t('BankingView.transfers.vendorShort') }}
                    </v-chip>
                    <div class="flex-grow-1">
                        <div class="text-body-2 font-weight-medium">{{ tmplRecipient.name }}</div>
                        <div class="text-caption text-disabled">{{ tmplRecipient.number }}</div>
                    </div>
                    <v-btn icon="mdi-close-circle" size="small" variant="text" @click="tmplRecipient = null; tmplForm.remote_name = ''; tmplForm.remote_iban = ''; tmplForm.remote_bic = ''" />
                </div>
                <v-text-field v-if="!tmplRecipient" v-model="tmplForm.remote_name" :label="t('BankingView.transfers.recipientName')" class="mb-3" variant="outlined" density="compact" hide-details />
                <v-text-field v-model="tmplForm.remote_iban" :label="t('BankingView.transfers.recipientIban')" class="mb-3" variant="outlined" density="compact" hide-details />
                <v-row dense class="mb-3">
                    <v-col cols="5">
                        <v-text-field v-model.number="tmplForm.amount" :label="t('BankingView.transfers.amount')" type="number" min="0.01" step="0.01" prefix="EUR" variant="outlined" density="compact" hide-details />
                    </v-col>
                    <v-col cols="7">
                        <v-text-field v-model="tmplForm.remote_bic" :label="t('BankingView.transfers.recipientBic')" variant="outlined" density="compact" hide-details />
                    </v-col>
                </v-row>
                <!-- Verwendungszweck-Vorlage -->
                <v-text-field
                    v-model="tmplForm.purpose_template"
                    :label="t('BankingView.templates.purposeTemplate')"
                    variant="outlined"
                    density="compact"
                    counter="140"
                    maxlength="140"
                    class="mb-2"
                />
                <!-- Klickbare Platzhalter -->
                <div class="d-flex align-center flex-wrap ga-1 mb-2">
                    <span class="text-caption text-disabled mr-1">{{ t('BankingView.templates.placeholders') }}:</span>
                    <v-chip
                        v-for="ph in purposePlaceholders"
                        :key="ph.val"
                        size="x-small"
                        color="primary"
                        variant="tonal"
                        class="cursor-pointer"
                        :title="ph.desc"
                        @click="insertPlaceholder(ph.val)"
                    >{{ ph.val }}</v-chip>
                </div>
                <!-- Live-Vorschau -->
                <div v-if="tmplPurposePreview" class="pa-2 rounded-lg bg-primary-lighten-5 text-caption">
                    <span class="text-disabled">{{ t('BankingView.templates.purposePreview') }}</span>
                    <strong class="text-primary ml-1">{{ tmplPurposePreview }}</strong>
                </div>
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="showTemplateDialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :disabled="!canSaveTemplate" @click="saveTemplate">{{ t('BankingView.transfers.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Dauerauftrag Dialog -->
    <v-dialog v-model="showSODialog" max-width="560" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ editingSOId ? t('BankingView.standing.editOrder') : t('BankingView.standing.newOrder') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <v-select v-model="soForm.bank_account_id" :items="banking.accounts.value" item-title="name" item-value="id" :label="t('BankingView.transfers.fromAccount')" class="mb-3" />
                <v-text-field v-model="soForm.remote_name" :label="t('BankingView.standing.recipient')" class="mb-3" />
                <v-text-field v-model="soForm.remote_iban" :label="t('BankingView.transfers.recipientIban')" class="mb-3" />
                <v-text-field v-model="soForm.remote_bic"  :label="t('BankingView.transfers.recipientBic')"  class="mb-3" />
                <v-row dense>
                    <v-col cols="6">
                        <v-text-field v-model.number="soForm.amount" :label="t('BankingView.standing.amount')" type="number" min="0.01" step="0.01" prefix="EUR" />
                    </v-col>
                    <v-col cols="6">
                        <v-select v-model="soForm.frequency" :label="t('BankingView.standing.frequency')" :items="freqOptions" />
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="6">
                        <v-text-field v-model.number="soForm.execution_day" :label="t('BankingView.standing.executionDay')" :hint="t('BankingView.standing.executionDayHint')" persistent-hint type="number" min="1" max="31" />
                    </v-col>
                    <v-col cols="6">
                        <v-text-field v-model="soForm.start_date" :label="t('BankingView.standing.startDate')" type="date" />
                    </v-col>
                </v-row>
                <v-text-field v-model="soForm.purpose"  :label="t('BankingView.standing.purpose')" maxlength="140" counter="140" class="mt-2 mb-3" />
                <v-text-field v-model="soForm.end_date" :label="t('BankingView.standing.endDate')" type="date" clearable />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="showSODialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :disabled="!canSaveSO" @click="saveStandingOrder">{{ t('BankingView.transfers.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- Einzugsermächtigung Dialog -->
    <v-dialog v-model="showMandateDialog" max-width="560" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ editingMandateId ? t('BankingView.mandate.editMandate') : t('BankingView.mandate.newMandate') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <v-select v-model="mandateForm.bank_account_id" :items="banking.accounts.value" item-title="name" item-value="id" :label="t('BankingView.transfers.fromAccount')" class="mb-3" />
                <v-text-field v-model="mandateForm.mandate_reference" :label="t('BankingView.mandate.reference')" class="mb-3" />
                <v-text-field v-model="mandateForm.creditor_name" :label="t('BankingView.mandate.creditorName')" class="mb-3" />
                <v-row dense>
                    <v-col cols="6">
                        <v-text-field v-model="mandateForm.creditor_id"   :label="t('BankingView.mandate.creditorId')" />
                    </v-col>
                    <v-col cols="6">
                        <v-select v-model="mandateForm.mandate_type" :label="t('BankingView.mandate.mandateType')" :items="mandateTypeOptions" />
                    </v-col>
                </v-row>
                <v-text-field v-model="mandateForm.creditor_iban" :label="t('BankingView.mandate.creditorIban')" class="mt-2 mb-3" />
                <v-row dense>
                    <v-col cols="6">
                        <v-text-field v-model.number="mandateForm.max_amount" :label="t('BankingView.mandate.maxAmount')" type="number" min="0" step="0.01" prefix="EUR" />
                    </v-col>
                    <v-col cols="6">
                        <v-text-field v-model="mandateForm.issued_date" :label="t('BankingView.mandate.issuedDate')" type="date" />
                    </v-col>
                </v-row>
                <v-text-field v-model="mandateForm.purpose" :label="t('BankingView.mandate.purpose')" maxlength="140" class="mt-2" />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="showMandateDialog = false">{{ t('BankingView.sync.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :disabled="!canSaveMandate" @click="saveMandate">{{ t('BankingView.transfers.save') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <!-- TAN für Überweisung -->
    <v-dialog v-model="showTransferTanDialog" max-width="420" persistent>
        <v-card rounded="xl">
            <v-card-title class="pa-4">{{ transfers.tanChallenge.decoupled ? t('BankingView.tan.titleDecoupled') : t('BankingView.tan.title') }}</v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <div v-if="transfers.tanChallenge.challenge" class="mb-3">
                    <div class="text-caption text-disabled">{{ t('BankingView.tan.challenge') }}</div>
                    <div class="text-body-1 font-weight-medium">{{ transfers.tanChallenge.challenge }}</div>
                </div>
                <v-alert v-if="transfers.tanChallenge.decoupled" type="info" variant="tonal" density="compact">
                    {{ transfers.tanChallenge.message || t('BankingView.tan.decoupledHint') }}
                </v-alert>
                <v-text-field v-else v-model="transferTanInput" :label="t('BankingView.tan.tanInput')" type="text" inputmode="numeric" autocomplete="one-time-code" @keyup.enter="submitTanForTransfer" />
            </v-card-text>
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="cancelTransferTan">{{ t('BankingView.tan.cancel') }}</v-btn>
                <v-btn color="primary" variant="elevated" :loading="transfers.loading.value" @click="submitTanForTransfer">
                    {{ transfers.tanChallenge.decoupled ? t('BankingView.tan.submitDecoupled') : t('BankingView.tan.submit') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useBanking, startGlobalKeepAlive } from '../composables/useBanking.js'
import { useTransfers } from '../composables/useTransfers.js'
import { useMatching } from '../composables/useMatching.js'
import { useStandingOrders } from '../composables/useStandingOrders.js'
import { useTransferTemplates, resolvePurpose } from '../composables/useTransferTemplates.js'
import { useBankingUtils, lookupBicFromIban } from '../composables/useBankingUtils.js'
import { Line as LineChart } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler } from 'chart.js'
ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler)
import RecipientAutocomplete from '../components/recipient-autocomplete.component.vue'
import BookingDialog from '../components/booking-dialog.component.vue'
import SettlementDialog from '../components/settlement-dialog.component.vue'
import { validateIban, validateBic, normalizeIban } from '@/core/utils/iban.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'
import Swal from 'sweetalert2'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const banking   = useBanking()
const transfers  = useTransfers()
const matching   = useMatching()
const soStore    = useStandingOrders()
const tmplStore  = useTransferTemplates()
const bankUtils  = useBankingUtils()

// ── Zustand ──────────────────────────────────────────────
const activeTab          = ref('transactions')
const selectedAccountId  = ref(null)
const syncingAccountId   = ref(null)

const selectedAccount = computed(() =>
    banking.accounts.value.find(a => a.id === selectedAccountId.value) ?? null
)

// ── BIC-Lookup ────────────────────────────────────────────
const bicAutoFillHint = ref('')

async function autofillBicFromIban() {
    if (!transferForm.remote_iban || transferForm.remote_bic) return
    const result = await lookupBicFromIban(transferForm.remote_iban)
    if (result?.bic) {
        transferForm.remote_bic = result.bic
        bicAutoFillHint.value = t('BankingView.utils.bicFound', { bic: result.bic })
    }
}

// ── Export ────────────────────────────────────────────────
const exportingCsv = ref(false)
const exportingPdf = ref(false)

async function doExportCsv() {
    exportingCsv.value = true
    try { await bankUtils.exportCsv(selectedAccountId.value, fromDate.value || null, toDate.value || null) }
    catch (e) { alerts.error(e.message) }
    finally { exportingCsv.value = false }
}

async function doExportPdf() {
    exportingPdf.value = true
    try { await bankUtils.exportPdf(selectedAccountId.value, fromDate.value || null, toDate.value || null) }
    catch (e) { alerts.error(e.message) }
    finally { exportingPdf.value = false }
}

// ── Multi-Sync ────────────────────────────────────────────
const syncingAll = ref(false)

async function syncAllAccounts() {
    const fintAccounts = banking.accounts.value.filter(a => a.fints_id)
    if (!fintAccounts.length) return
    syncingAll.value = true
    let totalImported = 0
    for (const account of fintAccounts) {
        try {
            const pin = await banking.loadBankingPin(account.id).catch(() => null)
            if (!pin) continue
            const result = await banking.syncTransactions(account.id, pin, null, null)
            totalImported += result.importedCount || 0
        } catch { /* einzelne Fehler überspringen */ }
    }
    await banking.fetchAccounts()
    await loadTransactions()
    syncingAll.value = false
    alerts.success(t('BankingView.utils.syncAllDone', { imported: totalImported, n: fintAccounts.length }))
}

// ── Liquidität ────────────────────────────────────────────
const forecastHeaders = computed(() => {
    const headers = [
        { title: 'Datum',                                key: 'date',       width: '110px' },
        { title: t('BankingView.liquidity.delta'),       key: 'delta',      width: '150px', align: 'end' },
        { title: t('BankingView.liquidity.balance'),     key: 'balance',    align: 'end' },
    ]
    if (bankUtils.aiForecast.value.length)
        headers.push({ title: t('BankingView.liquidity.aiForecast'), key: 'ai_balance', align: 'end' })
    return headers
})

const forecastTableRows = computed(() => {
    const data   = bankUtils.forecast.value
    const aiData = bankUtils.aiForecast.value
    const source = data.length ? data : aiData
    if (!source.length) return []

    const aiByDate = Object.fromEntries(aiData.map(d => [d.date, d]))
    const rows = source.map(r => ({
        ...r,
        ai_balance: aiByDate[r.date]?.balance ?? null,
        ai_delta:   aiByDate[r.date]?.delta   ?? null,
    }))

    const last = rows[rows.length - 1]
    return rows.filter((r, i) =>
        r.delta !== 0 || (r.ai_delta !== null && r.ai_delta !== 0) || i === 0 || r === last
    )
})

const forecastChartData = computed(() => {
    const data   = bankUtils.forecast.value
    const aiData = bankUtils.aiForecast.value

    // Wer hat Daten? AI allein als Fallback für Labels möglich
    const labelSource = data.length ? data : aiData
    const labels      = labelSource.map(d => d.label || d.date.slice(5))
    const datasets    = []

    if (data.length) {
        datasets.push({
            label: t('BankingView.liquidity.balance'),
            data: data.map(d => d.balance),
            borderColor: '#1565c0',
            backgroundColor: 'rgba(21,101,192,0.08)',
            fill: true,
            tension: 0.3,
            pointRadius: 2,
            pointHoverRadius: 5,
        })
    }

    if (aiData.length) {
        const aiByDate = Object.fromEntries(aiData.map(d => [d.date, d.balance]))
        datasets.push({
            label: t('BankingView.liquidity.aiForecast'),
            data: labelSource.map(d => aiByDate[d.date] ?? null),
            borderColor: '#e65100',
            backgroundColor: 'rgba(230,81,0,0.06)',
            fill: !data.length,  // Fläche nur wenn kein Basisforecast
            tension: 0.4,
            pointRadius: 2,
            pointHoverRadius: 5,
            borderDash: data.length ? [6, 4] : [],
            spanGaps: true,
        })
    }

    return { labels, datasets }
})

const forecastChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
        y: { ticks: { callback: v => v.toLocaleString('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }) } }
    }
}

async function loadForecast() {
    if (selectedAccountId.value) await bankUtils.fetchForecast(selectedAccountId.value, 30)
}

async function loadAIForecast() {
    if (!selectedAccountId.value) return
    try {
        if (!bankUtils.forecast.value.length)
            await bankUtils.fetchForecast(selectedAccountId.value, 30)
        await bankUtils.fetchAIForecast(selectedAccountId.value, 30)
    } catch (e) {
        alerts.error(e.message)
    }
}

// ── Lastschriften ─────────────────────────────────────────
const showDDDialog     = ref(false)
const showDDPinDialog  = ref(false)
const ddSubmitTarget   = ref(null)
const ddPin            = ref('')
const ddCreditorId     = ref('')

const ddForm = reactive({
    bank_account_id: null, debtor_name: '', debtor_iban: '', debtor_bic: '',
    mandate_reference: '', amount: null, collection_date: new Date(Date.now() + 2*24*60*60*1000).toISOString().slice(0,10),
    purpose: '', sequence_type: 'RCUR', creditor_id: ''
})

const canSaveDD = computed(() =>
    !!ddForm.bank_account_id && !!ddForm.debtor_name && !!ddForm.debtor_iban
    && !!ddForm.mandate_reference && ddForm.amount > 0 && !!ddForm.purpose && !!ddForm.collection_date
)

const ddHeaders = computed(() => [
    { title: t('BankingView.transfers.status'),           key: 'status',           width: '110px' },
    { title: t('BankingView.directdebit.debtor'),         key: 'debtor_name'                       },
    { title: t('BankingView.directdebit.mandateRef'),     key: 'mandate_reference', width: '140px' },
    { title: t('BankingView.transfers.amount'),           key: 'amount',            width: '120px', align: 'end' },
    { title: t('BankingView.directdebit.collectionDate'), key: 'collection_date',   width: '130px' },
    { title: '',                                           key: 'actions',           width: '90px',  sortable: false },
])

function openNewDirectDebit() {
    Object.assign(ddForm, {
        bank_account_id: selectedAccountId.value ?? banking.accounts.value[0]?.id ?? null,
        debtor_name: '', debtor_iban: '', debtor_bic: '', mandate_reference: '',
        amount: null, collection_date: new Date(Date.now() + 2*24*60*60*1000).toISOString().slice(0,10),
        purpose: '', sequence_type: 'RCUR', creditor_id: ''
    })
    showDDDialog.value = true
    activeTab.value = 'directdebits'
}

async function lookupDDDebtorBic() {
    if (!ddForm.debtor_iban || ddForm.debtor_bic) return
    const r = await lookupBicFromIban(ddForm.debtor_iban)
    if (r?.bic) ddForm.debtor_bic = r.bic
}

async function saveDirectDebit() {
    try {
        await bankUtils.createDirectDebit(ddForm)
        showDDDialog.value = false
        await bankUtils.fetchDirectDebits(selectedAccountId.value)
    } catch (e) { alerts.error(e.message) }
}

function confirmSubmitDD(item) {
    ddSubmitTarget.value = item
    ddPin.value = ''
    ddCreditorId.value = item.creditor_id || ''
    showDDPinDialog.value = true
}

async function executeSubmitDD() {
    if (!ddPin.value) return
    try {
        const result = await bankUtils.submitDirectDebit(ddSubmitTarget.value.id, ddPin.value, ddCreditorId.value)
        if (result.text === 'TAN_REQUIRED') {
            showDDPinDialog.value = false
            // TAN-Dialog öffnen (shared)
            banking.tanChallenge.challenge = result.payload?.challenge || ''
            banking.tanChallenge.tanMedium = result.payload?.tan_medium || ''
            banking.tanChallenge.decoupled = !!result.payload?.decoupled
            banking.tanChallenge.message   = result.payload?.message || ''
            showTanDialog.value = true
            return
        }
        showDDPinDialog.value = false
        ddPin.value = ''
        alerts.success(t('BankingView.alerts.transferSubmitted'))
        await bankUtils.fetchDirectDebits(selectedAccountId.value)
    } catch (e) { alerts.error(e.message) }
}

async function confirmDeleteDD(item) {
    const r = await Swal.fire({ title: 'Lastschrift löschen?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
    if (!r.isConfirmed) return
    try { await bankUtils.deleteDirectDebit(item.id); await bankUtils.fetchDirectDebits(selectedAccountId.value) }
    catch (e) { alerts.error(e.message) }
}

// ── Tabs: Vorlagen ───────────────────────────────────────
const selectedGroupId      = ref(null)
const selectedTemplateIds  = ref([])
const showGroupDialog      = ref(false)
const showTemplateDialog   = ref(false)
const editingGroupId       = ref(null)
const editingTemplateId    = ref(null)

const groupForm = reactive({ name: '', color: 'primary', description: '' })
const tmplForm  = reactive({
    group_id: null, bank_account_id: null, name: '',
    remote_name: '', remote_iban: '', remote_bic: '',
    amount: null, purpose_template: '', sort_order: 0
})

const groupColors = [
    { value: 'primary',  label: 'Blau'   },
    { value: 'success',  label: 'Grün'   },
    { value: 'warning',  label: 'Orange' },
    { value: 'error',    label: 'Rot'    },
    { value: 'info',     label: 'Cyan'   },
    { value: 'secondary',label: 'Lila'   },
]

const ungroupedTemplateCount = computed(() =>
    tmplStore.templates.value.filter(t => !t.group_id).length
)

const groupTemplates = computed(() => {
    if (selectedGroupId.value === null) return []
    if (selectedGroupId.value === -1) return tmplStore.templates.value.filter(t => !t.group_id)
    return tmplStore.templates.value.filter(t => t.group_id === selectedGroupId.value)
})

const purposePlaceholders = [
    { val: '{MM/JJJJ}', desc: 'Monat/Jahr, z.B. 06/2026' },
    { val: '{MM/YYYY}', desc: 'Monat/Jahr (englisch), z.B. 06/2026' },
    { val: '{MONAT}',   desc: 'Monatsname, z.B. Juni' },
    { val: '{MM}',      desc: 'Monat zweistellig, z.B. 06' },
    { val: '{JJJJ}',    desc: 'Jahr vierstellig, z.B. 2026' },
    { val: '{DATUM}',   desc: 'Datum, z.B. 01.06.2026' },
]

function insertPlaceholder(ph) {
    if ((tmplForm.purpose_template + ph).length <= 140)
        tmplForm.purpose_template += ph
}

const allTemplatesSelected = computed(() =>
    groupTemplates.value.length > 0 &&
    selectedTemplateIds.value.length === groupTemplates.value.length
)
const someTemplatesSelected = computed(() => selectedTemplateIds.value.length > 0 && !allTemplatesSelected.value)

function toggleAllTemplates() {
    if (allTemplatesSelected.value) selectedTemplateIds.value = []
    else selectedTemplateIds.value = groupTemplates.value.map(t => t.id)
}

const canSaveTemplate = computed(() =>
    !!tmplForm.remote_name && !!tmplForm.remote_iban && tmplForm.amount > 0 && !!tmplForm.purpose_template
)

const tmplPurposePreview = computed(() => {
    if (!tmplForm.purpose_template) return ''
    if (!hasPlaceholders(tmplForm.purpose_template)) return ''
    return resolvePurpose(tmplForm.purpose_template)
})

function hasPlaceholders(s) {
    return /\{[^}]+\}/.test(s || '')
}

function selectGroup(id) {
    selectedGroupId.value = id
    selectedTemplateIds.value = tmplStore.templates.value
        .filter(t => id === -1 ? !t.group_id : t.group_id === id)
        .map(t => t.id)
    // Vorlagen für diese Gruppe frisch laden
    if (id === -1) tmplStore.fetchTemplates()
    else tmplStore.fetchTemplates(id)
}

function toggleTemplateSelection(id) {
    const idx = selectedTemplateIds.value.indexOf(id)
    if (idx >= 0) selectedTemplateIds.value.splice(idx, 1)
    else selectedTemplateIds.value.push(id)
}

function openNewGroup() {
    editingGroupId.value = null
    Object.assign(groupForm, { name: '', color: 'primary', description: '' })
    showGroupDialog.value = true
}

function editGroup(g) {
    editingGroupId.value = g.id
    Object.assign(groupForm, { name: g.name, color: g.color || 'primary', description: g.description || '' })
    showGroupDialog.value = true
}

async function saveGroup() {
    try {
        if (editingGroupId.value) {
            await tmplStore.updateGroup({ id: editingGroupId.value, ...groupForm })
        } else {
            const r = await tmplStore.createGroup(groupForm)
            selectedGroupId.value = r.id
        }
        showGroupDialog.value = false
        await tmplStore.fetchGroups()
    } catch (e) { alerts.error(e.message) }
}

async function confirmDeleteGroup() {
    const r = await Swal.fire({ title: t('BankingView.templates.confirmDeleteGroup'), icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
    if (!r.isConfirmed) return
    try {
        await tmplStore.deleteGroup(editingGroupId.value)
        showGroupDialog.value = false
        if (selectedGroupId.value === editingGroupId.value) selectedGroupId.value = null
        await tmplStore.fetchGroups()
    } catch (e) { alerts.error(e.message) }
}

function openNewTemplate() {
    editingTemplateId.value = null
    tmplRecipient.value = null
    Object.assign(tmplForm, {
        group_id: selectedGroupId.value === -1 ? null : selectedGroupId.value,
        bank_account_id: selectedAccountId.value ?? banking.accounts.value[0]?.id ?? null,
        name: '', remote_name: '', remote_iban: '', remote_bic: '',
        amount: null, purpose_template: '', sort_order: 0
    })
    showTemplateDialog.value = true
}

function openEditTemplate(tmpl) {
    editingTemplateId.value = tmpl.id
    tmplRecipient.value = null
    Object.assign(tmplForm, {
        group_id: tmpl.group_id, bank_account_id: tmpl.bank_account_id,
        name: tmpl.name || '', remote_name: tmpl.remote_name, remote_iban: tmpl.remote_iban,
        remote_bic: tmpl.remote_bic || '', amount: parseFloat(tmpl.amount),
        purpose_template: tmpl.purpose_template, sort_order: tmpl.sort_order || 0
    })
    showTemplateDialog.value = true
}

async function saveTemplate() {
    try {
        if (editingTemplateId.value) {
            await tmplStore.updateTemplate({ id: editingTemplateId.value, ...tmplForm })
        } else {
            await tmplStore.createTemplate(tmplForm)
        }
        showTemplateDialog.value = false
        await tmplStore.fetchTemplates()
    } catch (e) { alerts.error(e.message) }
}

async function confirmDeleteTemplate(tmpl) {
    const r = await Swal.fire({ title: t('BankingView.templates.confirmDeleteTemplate'), icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
    if (!r.isConfirmed) return
    try { await tmplStore.deleteTemplate(tmpl.id); await tmplStore.fetchTemplates() }
    catch (e) { alerts.error(e.message) }
}

async function executeTemplateList(tmplList) {
    const now = new Date()
    const txList = tmplList.map(tmpl => ({
        template_id:    tmpl.id,
        recipient_type: tmpl.recipient_type,
        recipient_id:   tmpl.recipient_id,
        remote_name:    tmpl.remote_name,
        remote_iban:    tmpl.remote_iban,
        remote_bic:     tmpl.remote_bic,
        amount:         tmpl.amount,
        purpose:        resolvePurpose(tmpl.purpose_template, now),
        bank_account_id: tmpl.bank_account_id ?? selectedAccountId.value,
    }))
    try {
        const result = await tmplStore.createTransfersFromTemplates(txList)
        await transfers.fetchTransferOrders()
        alerts.success(t('BankingView.templates.created', { n: result.created }))
        activeTab.value = 'transfers'
    } catch (e) { alerts.error(e.message) }
}

function executeSelectedTemplates() {
    const list = selectedTemplateIds.value.length > 0
        ? groupTemplates.value.filter(t => selectedTemplateIds.value.includes(t.id))
        : groupTemplates.value
    executeTemplateList(list)
}

function executeAllTemplates() {
    executeTemplateList(groupTemplates.value)
}

// ── Tabs: Daueraufträge ──────────────────────────────────
const showSODialog   = ref(false)
const editingSOId    = ref(null)
const soForm = reactive({
    bank_account_id: null, remote_name: '', remote_iban: '', remote_bic: '',
    amount: null, purpose: '', frequency: 'monthly', execution_day: 1,
    start_date: new Date().toISOString().slice(0,10), end_date: ''
})

const freqOptions = computed(() => [
    { value: 'monthly',   title: t('BankingView.standing.freqMonthly')   },
    { value: 'quarterly', title: t('BankingView.standing.freqQuarterly') },
    { value: 'yearly',    title: t('BankingView.standing.freqYearly')    },
    { value: 'weekly',    title: t('BankingView.standing.freqWeekly')    },
])

const canSaveSO = computed(() =>
    !!soForm.bank_account_id && !!soForm.remote_name && !!soForm.remote_iban
    && soForm.amount > 0 && !!soForm.purpose && !!soForm.start_date
)

const standingHeaders = computed(() => [
    { title: t('BankingView.standing.status'),       key: 'status',              width: '100px' },
    { title: t('BankingView.standing.recipient'),    key: 'remote_name'                         },
    { title: t('BankingView.standing.amount'),       key: 'amount',              width: '120px', align: 'end' },
    { title: t('BankingView.standing.frequency'),    key: 'frequency',           width: '130px' },
    { title: t('BankingView.standing.nextExecution'),key: 'next_execution_date', width: '140px' },
    { title: t('BankingView.standing.purpose'),      key: 'purpose'                             },
    { title: '',                                      key: 'actions',             width: '130px', sortable: false },
])

function openNewStandingOrder() {
    editingSOId.value = null
    Object.assign(soForm, {
        bank_account_id: selectedAccountId.value ?? banking.accounts.value[0]?.id ?? null,
        remote_name: '', remote_iban: '', remote_bic: '', amount: null,
        purpose: '', frequency: 'monthly', execution_day: 1,
        start_date: new Date().toISOString().slice(0,10), end_date: ''
    })
    showSODialog.value = true
    activeTab.value = 'standing'
}

function editStandingOrder(item) {
    editingSOId.value = item.id
    Object.assign(soForm, {
        bank_account_id: item.bank_account_id, remote_name: item.remote_name,
        remote_iban: item.remote_iban, remote_bic: item.remote_bic || '',
        amount: parseFloat(item.amount), purpose: item.purpose,
        frequency: item.frequency, execution_day: item.execution_day,
        start_date: item.start_date, end_date: item.end_date || ''
    })
    showSODialog.value = true
}

async function saveStandingOrder() {
    try {
        if (editingSOId.value) {
            await soStore.updateStandingOrder({ id: editingSOId.value, ...soForm })
        } else {
            await soStore.createStandingOrder(soForm)
        }
        showSODialog.value = false
        alerts.success(editingSOId.value ? t('BankingView.alerts.ruleSaved') : t('BankingView.alerts.transferCreated'))
        await soStore.fetchStandingOrders(selectedAccountId.value)
    } catch (e) { alerts.error(e.message) }
}

async function toggleStandingOrderStatus(item) {
    const newStatus = item.status === 'active' ? 'paused' : 'active'
    try {
        await soStore.setStandingOrderStatus(item.id, newStatus)
        await soStore.fetchStandingOrders(selectedAccountId.value)
    } catch (e) { alerts.error(e.message) }
}

async function confirmDeleteStandingOrder(item) {
    const r = await Swal.fire({ title: t('BankingView.standing.confirmDelete'), icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
    if (!r.isConfirmed) return
    try { await soStore.deleteStandingOrder(item.id); await soStore.fetchStandingOrders(selectedAccountId.value) }
    catch (e) { alerts.error(e.message) }
}

async function runStandingOrder(item) {
    const r = await Swal.fire({ title: t('BankingView.standing.confirmExecute'), icon: 'question', showCancelButton: true })
    if (!r.isConfirmed) return
    try {
        const result = await soStore.executeStandingOrder(item.id)
        await soStore.fetchStandingOrders(selectedAccountId.value)
        await transfers.fetchTransferOrders()
        alerts.success(t('BankingView.standing.executed'))
        // Zum Überweisungen-Tab wechseln damit der User den Entwurf gleich sieht
        activeTab.value = 'transfers'
    } catch (e) { alerts.error(e.message) }
}

function isOverdue(dateStr) {
    if (!dateStr) return false
    return new Date(dateStr) < new Date(new Date().toISOString().slice(0,10))
}

function soStatusColor(s) { return { active: 'success', paused: 'warning', ended: 'default' }[s] || 'default' }

// ── Tabs: Einzugsermächtigungen ──────────────────────────
const showMandateDialog = ref(false)
const editingMandateId  = ref(null)
const mandateForm = reactive({
    bank_account_id: null, mandate_reference: '', creditor_name: '',
    creditor_id: '', creditor_iban: '', creditor_bic: '',
    max_amount: null, purpose: '', mandate_type: 'RCUR',
    issued_date: new Date().toISOString().slice(0,10), first_collection: ''
})

const mandateTypeOptions = [
    { value: 'RCUR', title: 'RCUR — Wiederkehrend' },
    { value: 'FRST', title: 'FRST — Erstlastschrift' },
    { value: 'FNAL', title: 'FNAL — Letztlastschrift' },
    { value: 'OOFF', title: 'OOFF — Einmalig'        },
]

const canSaveMandate = computed(() =>
    !!mandateForm.mandate_reference && !!mandateForm.creditor_name && !!mandateForm.issued_date
)

const mandateHeaders = computed(() => [
    { title: t('BankingView.mandate.status'),    key: 'status',            width: '90px'  },
    { title: t('BankingView.mandate.creditorName'), key: 'creditor_name'                  },
    { title: t('BankingView.mandate.reference'), key: 'mandate_reference', width: '150px' },
    { title: t('BankingView.mandate.creditorId'),key: 'creditor_id',       width: '140px' },
    { title: t('BankingView.mandate.maxAmount'), key: 'max_amount',        width: '120px', align: 'end' },
    { title: t('BankingView.mandate.issuedDate'),key: 'issued_date',       width: '120px' },
    { title: t('BankingView.mandate.revokedDate'),key: 'revoked_date',     width: '120px' },
    { title: '',                                  key: 'actions',           width: '100px', sortable: false },
])

function openNewMandate() {
    editingMandateId.value = null
    Object.assign(mandateForm, {
        bank_account_id: selectedAccountId.value ?? banking.accounts.value[0]?.id ?? null,
        mandate_reference: '', creditor_name: '', creditor_id: '',
        creditor_iban: '', creditor_bic: '', max_amount: null,
        purpose: '', mandate_type: 'RCUR',
        issued_date: new Date().toISOString().slice(0,10), first_collection: ''
    })
    showMandateDialog.value = true
    activeTab.value = 'mandates'
}

function editMandate(item) {
    editingMandateId.value = item.id
    Object.assign(mandateForm, {
        bank_account_id: item.bank_account_id, mandate_reference: item.mandate_reference,
        creditor_name: item.creditor_name, creditor_id: item.creditor_id || '',
        creditor_iban: item.creditor_iban || '', creditor_bic: item.creditor_bic || '',
        max_amount: item.max_amount ? parseFloat(item.max_amount) : null,
        purpose: item.purpose || '', mandate_type: item.mandate_type || 'RCUR',
        issued_date: item.issued_date, first_collection: item.first_collection || ''
    })
    showMandateDialog.value = true
}

async function saveMandate() {
    try {
        if (editingMandateId.value) {
            await soStore.updateMandate({ id: editingMandateId.value, ...mandateForm })
        } else {
            await soStore.createMandate(mandateForm)
        }
        showMandateDialog.value = false
        alerts.success(t('BankingView.alerts.ruleSaved'))
        await soStore.fetchMandates(selectedAccountId.value)
    } catch (e) { alerts.error(e.message) }
}

async function confirmRevokeMandate(item) {
    const r = await Swal.fire({ title: t('BankingView.mandate.confirmRevoke'), icon: 'warning', showCancelButton: true })
    if (!r.isConfirmed) return
    try {
        await soStore.revokeMandate(item.id, new Date().toISOString().slice(0,10))
        alerts.success(t('BankingView.mandate.revoke') + 'd')
        await soStore.fetchMandates(selectedAccountId.value)
    } catch (e) { alerts.error(e.message) }
}

async function confirmDeleteMandate(item) {
    const r = await Swal.fire({ title: t('BankingView.mandate.confirmDelete'), icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
    if (!r.isConfirmed) return
    try { await soStore.deleteMandate(item.id); await soStore.fetchMandates(selectedAccountId.value) }
    catch (e) { alerts.error(e.message) }
}

// ── Tabs: Umsätze ────────────────────────────────────────
// Standard-Zeitraum: aktuelles (Kalender-)Geschäftsjahr — es werden nur Umsätze
// des laufenden Jahres angezeigt. Über die Datumsfelder erweiterbar.
const FY_START = `${new Date().getFullYear()}-01-01`
const FY_END   = `${new Date().getFullYear()}-12-31`
const matchFilter = ref('all')
const fromDate    = ref(FY_START)
const toDate      = ref(FY_END)

// true, während ein Sprung aus einer Rechnung angewandt wird — verhindert, dass
// der Konto-Watcher den (bewusst geleerten) Datumsfilter überschreibt.
let applyingJump = false

// Kluger Volltext-Filter über die Umsätze: durchsucht Gegenname, IBAN,
// Verwendungszweck, Betrag sowie zugeordnete Rechnungsnummern/Kunden. Mehrere
// Begriffe (durch Leerzeichen getrennt) müssen alle vorkommen (UND-Verknüpfung).
// Wird beim Sprung aus einer Rechnung mit der Rechnungsnummer vorbelegt.
const txSearch = ref('')

function txHaystack(tx) {
    const parts = [
        tx.remote_name, tx.remote_iban, tx.purpose, tx.transaction_text,
        tx.pending_invnumber, tx.pending_target_name, String(tx.amount ?? '')
    ]
    for (const a of (tx.assignments || [])) {
        parts.push(a.invnumber, a.customer_name, a.vendor_name)
    }
    return parts.filter(Boolean).join(' ').toLowerCase()
}

const displayedTransactions = computed(() => {
    const all = banking.transactions.value
    const q = (txSearch.value || '').trim().toLowerCase()
    if (!q) return all
    const tokens = q.split(/\s+/).filter(Boolean)
    return all.filter(tx => {
        const hay = txHaystack(tx)
        return tokens.every(tok => hay.includes(tok))
    })
})

/** Öffnet die zugeordnete (Ausgangs-)Rechnung aus der Umsatz-Liste */
function goToInvoice(assignment) {
    if (!assignment.ar_id) return
    router.push({ name: 'faktura-invoice-view', params: { id: assignment.ar_id } })
}

const txHeaders = computed(() => [
    { title: t('BankingView.transactions.date'),       key: 'transdate',    width: '90px' },
    { title: t('BankingView.transactions.amount'),     key: 'amount',       width: '110px', align: 'end' },
    { title: t('BankingView.transactions.remoteName'), key: 'remote_name',  width: '200px' },
    { title: t('BankingView.transactions.purpose'),    key: 'purpose' },
    { title: t('BankingView.transactions.status'),     key: 'match_status', width: '110px' },
    { title: '',                                        key: 'assignments',  width: '140px', sortable: false },
    { title: '',                                        key: 'actions',      width: '70px',  sortable: false },
])

watch([matchFilter, fromDate, toDate], () => loadTransactions())

// Suche serverseitig (debounced) — findet auch Treffer außerhalb der ersten 200
// geladenen Zeilen; das Client-Filter (displayedTransactions) sorgt für sofortiges
// Feedback während des Tippens.
let txSearchTimer = null
watch(txSearch, () => {
    clearTimeout(txSearchTimer)
    txSearchTimer = setTimeout(loadTransactions, 350)
})

async function loadTransactions() {
    if (!selectedAccountId.value) return
    await banking.fetchTransactions(selectedAccountId.value, {
        match_status: matchFilter.value,
        from_date: fromDate.value || undefined,
        to_date:   toDate.value   || undefined,
        search:    (txSearch.value || '').trim() || undefined,
    })
}

async function ignoreTransaction(item) {
    try { await banking.setTransactionStatus(item.id, 'ignored'); await loadTransactions() }
    catch (e) { alerts.error(e.message) }
}

async function resetTransaction(item) {
    try { await banking.setTransactionStatus(item.id, 'unmatched'); await loadTransactions() }
    catch (e) { alerts.error(e.message) }
}

const showBookingDialog      = ref(false)
const bookingDialogTransaction = ref(null)

function isBookable(item) {
    return (item.match_status === 'unmatched' || item.match_status === 'matched') && item.amount > 0
}

function getTxRowProps({ item }) {
    if (isBookable(item)) return { class: 'tx-bookable' }
    if (item.match_status === 'booked') return { class: 'tx-booked' }
    return {}
}

function onTxRowClick(_e, { item }) {
    if (isBookable(item)) openBookingDialog(item)
}

function openBookingDialog(item) {
    bookingDialogTransaction.value = item
    showBookingDialog.value = true
}

const showSettlementDialog = ref(false)
const settlementTransaction = ref(null)

function openSettlement(item) {
    settlementTransaction.value = item
    showSettlementDialog.value = true
}

// Aus dem "Zahlung buchen"-Dialog heraus zur Kartenabrechnung wechseln.
function onSettlementFromBooking(item) {
    showBookingDialog.value = false
    openSettlement(item)
}

async function onBookingDone() {
    await loadTransactions()
}

async function onIgnoreFromDialog(transactionId) {
    try { await banking.setTransactionStatus(transactionId, 'ignored'); await loadTransactions() }
    catch (e) { alerts.error(e.message) }
}

async function unbookTransaction(item) {
    const res = await alerts.question(
        t('BankingView.booking.unbookConfirm'),
        t('BankingView.booking.unbookTitle'),
        t('BankingView.booking.unbookConfirmBtn'),
        t('BankingView.booking.cancel')
    )
    if (!res.isConfirmed) return
    try {
        await matching.unbookTransaction(item.id)
        alerts.success(t('BankingView.booking.unbookSuccess'))
        await loadTransactions()
    } catch (e) {
        alerts.error(e.message)
    }
}

// ── Tabs: Überweisungen ──────────────────────────────────
const showTransferDialog = ref(false)
const editingId          = ref(null)
const recipient          = ref(null)
const tmplRecipient      = ref(null)
const selectedIds        = ref([])
const batchSubmitIds     = ref([])
const batchId            = ref(null)
const showPinDialog      = ref(false)
const showVopDialog      = ref(false)
const showTransferTanDialog = ref(false)
const submitTarget       = ref(null)
const submitPin          = ref('')
const rememberTransferPin = ref(true)
const transferTanInput   = ref('')
const reconciling        = ref(false)

const submitBankAccountId = computed(() =>
    submitTarget.value?.bank_account_id
    ?? transfers.transferOrders.value.find(o => o.id === batchSubmitIds.value[0])?.bank_account_id
    ?? null
)
const submitAccountHasSavedPin = computed(() =>
    !!banking.accounts.value.find(a => a.id === submitBankAccountId.value)?.has_saved_pin
)

const todayIso         = new Date().toISOString().slice(0, 10)
const maxExecutionDateIso = (() => { const d = new Date(); d.setFullYear(d.getFullYear()+1); return d.toISOString().slice(0,10) })()

const transferForm = reactive({
    bank_account_id: null, remote_name: '', remote_iban: '', remote_bic: '',
    amount: null, purpose: '', execution_date: '', instant: false, engine: 'vop_check',
    saveAsTemplate: false, templateName: '', templateGroupId: null
})

const isBatchSubmit      = computed(() => batchSubmitIds.value.length > 0)
const ibanCheck          = computed(() => validateIban(transferForm.remote_iban))
const ibanValid          = computed(() => ibanCheck.value.valid)
const ibanError          = computed(() => {
    if (!transferForm.remote_iban) return ''
    if (ibanCheck.value.valid) return ''
    const c = ibanCheck.value
    if (c.code === 'format')   return t('BankingView.transfers.ibanFormatError')
    if (c.code === 'length')   return t('BankingView.transfers.ibanLengthError', { country: c.country, expected: c.expectedLength })
    if (c.code === 'checksum') return t('BankingView.transfers.ibanChecksumError')
    return t('BankingView.transfers.ibanInvalid')
})
const ibanHint = computed(() => {
    if (!recipient.value || !ibanValid.value) return ''
    const entered = normalizeIban(transferForm.remote_iban)
    const stored  = normalizeIban(recipient.value.iban || '')
    if (!stored && entered) return t('BankingView.transfers.ibanWillBeSaved')
    if (stored && entered && stored !== entered) return t('BankingView.transfers.ibanWillBeUpdated')
    return ''
})
const bicError = computed(() => {
    if (!transferForm.remote_bic) return ''
    return validateBic(transferForm.remote_bic).valid ? '' : t('BankingView.transfers.bicFormatError')
})
const executionDateError = computed(() => {
    if (!transferForm.execution_date) return ''
    if (transferForm.instant) return t('BankingView.transfers.executionDateInstantConflict')
    if (transferForm.execution_date < todayIso) return t('BankingView.transfers.executionDatePast')
    if (transferForm.execution_date > maxExecutionDateIso) return t('BankingView.transfers.executionDateTooFar')
    return ''
})
const canSave = computed(() =>
    !!transferForm.bank_account_id && !!transferForm.remote_name
    && ibanValid.value && !bicError.value && !executionDateError.value
    && transferForm.amount > 0 && !!transferForm.purpose
)

const batchSelectable = computed(() => {
    const drafts = selectedIds.value
        .map(item => transfers.transferOrders.value.find(o => o.id === (item.id || item)))
        .filter(o => o && sendableStatuses.includes(o.status) && !o.instant)
    if (drafts.length < 2) return []
    const accountId = drafts[0].bank_account_id
    const execDate  = drafts[0].execution_date || null
    return drafts.filter(o => o.bank_account_id === accountId && (o.execution_date || null) === execDate)
})
const batchSelectableCount = computed(() => batchSelectable.value.length)
const batchSelectableTotal = computed(() => batchSelectable.value.reduce((s, o) => s + parseFloat(o.amount||0), 0))

// Sendbare Aufträge: Entwürfe und zuvor fehlgeschlagene (rejected) — ohne
// Instant, die sind per Definition Einzeltransaktionen. Basis für den
// "Alle senden"-Pfeil im Tabellenkopf.
const sendableStatuses = ['draft', 'rejected']
const draftOrders = computed(() => transfers.transferOrders.value.filter(o => sendableStatuses.includes(o.status) && !o.instant))
const draftCount  = computed(() => draftOrders.value.length)
const draftTotal  = computed(() => draftOrders.value.reduce((s, o) => s + parseFloat(o.amount||0), 0))

// Warteschlange fuer "Alle senden": nach (Konto, Ausfuehrungsdatum) gruppierte
// Drafts. Jede Gruppe geht als eigener SEPA-Sammelauftrag raus (eine TAN je
// Gruppe). Normalfall — alle Drafts gleiches Konto, sofort — ist genau eine
// Gruppe und damit eine einzige TAN-Bestaetigung fuer alles.
const pendingGroups = ref([])

// Ausführungsdatum für "Alle senden": macht aus dem (von der Bank blockierten)
// Sofort-Sammelauftrag eine terminierte Sammelüberweisung mit EINER pushTAN.
// Nur für den "Alle senden"-Weg gesetzt; manuelle Auswahl bleibt null (Sofort).
const batchExecutionDate = ref(null)

// Nächster Werktag (Mo–Fr) als Vorgabe-Ausführungsdatum, YYYY-MM-DD.
function nextBusinessDayIso() {
    const d = new Date()
    d.setDate(d.getDate() + 1)
    while (d.getDay() === 0 || d.getDay() === 6) d.setDate(d.getDate() + 1)
    return d.toISOString().slice(0, 10)
}

const batchDeletable = computed(() =>
    selectedIds.value
        .map(item => transfers.transferOrders.value.find(o => o.id === (item.id || item)))
        .filter(o => o && o.status !== 'executed')
)

const transferHeaders = computed(() => [
    { title: t('BankingView.transfers.status'),      key: 'status',      width: '130px' },
    { title: t('BankingView.transfers.recipient'),   key: 'remote_name' },
    { title: t('BankingView.transfers.amount'),      key: 'amount',      width: '120px', align: 'end' },
    { title: t('BankingView.transfers.purpose'),     key: 'purpose' },
    { title: t('BankingView.transfers.fromAccount'), key: 'account_name', width: '160px' },
    { title: '',                                      key: 'itime',        width: '90px' },
    { title: '',                                      key: 'actions',      width: '110px', sortable: false },
])

watch(recipient, (r) => {
    if (!r) return
    transferForm.remote_name = r.name
    if (!transferForm.remote_iban && r.iban) transferForm.remote_iban = r.iban
    if (!transferForm.remote_bic  && r.bic)  transferForm.remote_bic  = r.bic
})

watch(tmplRecipient, (r) => {
    if (!r) return
    tmplForm.remote_name = r.name
    if (!tmplForm.remote_iban && r.iban) tmplForm.remote_iban = r.iban
    if (!tmplForm.remote_bic  && r.bic)  tmplForm.remote_bic  = r.bic
})

function openNewTransfer(preselectId = null) {
    editingId.value    = null
    recipient.value    = null
    Object.assign(transferForm, {
        bank_account_id: preselectId ?? selectedAccountId.value ?? banking.accounts.value[0]?.id ?? null,
        remote_name: '', remote_iban: '', remote_bic: '',
        amount: null, purpose: '', execution_date: '', instant: false, engine: 'vop_check',
        saveAsTemplate: false, templateName: '', templateGroupId: null
    })
    showTransferDialog.value = true
    activeTab.value = 'transfers'
}

function clearRecipient() {
    recipient.value = null
    transferForm.remote_name = ''
    transferForm.remote_iban = ''
    transferForm.remote_bic  = ''
}

function editTransfer(item) {
    editingId.value = item.id
    recipient.value = null
    Object.assign(transferForm, {
        bank_account_id: item.bank_account_id,
        remote_name: item.remote_name, remote_iban: item.remote_iban,
        remote_bic: item.remote_bic || '', amount: parseFloat(item.amount),
        purpose: item.purpose, execution_date: item.execution_date || '',
        instant: !!item.instant, engine: item.engine || 'vop_check'
    })
    showTransferDialog.value = true
}

async function maybePersistRecipientBank() {
    if (!recipient.value) return
    const entered = normalizeIban(transferForm.remote_iban)
    const stored  = normalizeIban(recipient.value.iban || '')
    if (!entered) return
    if (stored === entered && (recipient.value.bic||'') === (transferForm.remote_bic||'')) return
    if (stored && stored !== entered) {
        const ok = await Swal.fire({
            title: t('BankingView.transfers.confirmIbanUpdateTitle'),
            text: t('BankingView.transfers.confirmIbanUpdateText', { name: recipient.value.name }),
            icon: 'question', showCancelButton: true,
            confirmButtonText: t('BankingView.transfers.confirmUpdate'),
            cancelButtonText:  t('BankingView.transfers.confirmKeep')
        })
        if (!ok.isConfirmed) return
    }
    try { await transfers.updateRecipientBank({ type: recipient.value.recipient_type, id: recipient.value.id, iban: entered, bic: transferForm.remote_bic||'' }) }
    catch (e) { alerts.warning(t('BankingView.transfers.recipientSaveFailed') + ': ' + e.message) }
}

async function saveTransfer() {
    // Verwendungszweck: leer → automatisch Datum einsetzen
    if (!transferForm.purpose.trim()) {
        const now = new Date()
        transferForm.purpose = now.toLocaleDateString('de-DE') + ' ' + now.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
        alerts.info(t('BankingView.templates.purposeAutoFill'))
    }
    try {
        if (editingId.value) {
            await transfers.updateTransfer({ id: editingId.value, ...transferForm })
        } else {
            await transfers.createTransfer({ ...transferForm, recipient_type: recipient.value?.recipient_type||null, recipient_id: recipient.value?.id||null })
            await maybePersistRecipientBank()
            // Als Vorlage speichern
            if (transferForm.saveAsTemplate) {
                await tmplStore.createTemplate({
                    group_id:        transferForm.templateGroupId,
                    bank_account_id: transferForm.bank_account_id,
                    remote_name:     transferForm.remote_name,
                    remote_iban:     transferForm.remote_iban,
                    remote_bic:      transferForm.remote_bic,
                    amount:          transferForm.amount,
                    purpose_template: transferForm.purpose,
                    name:            transferForm.templateName || null,
                })
                await tmplStore.fetchTemplates()
            }
        }
        showTransferDialog.value = false
        alerts.success(t('BankingView.alerts.transferCreated'))
        await transfers.fetchTransferOrders()
    } catch (e) { alerts.error(e.message) }
}

async function confirmDeleteTransfer(item) {
    const r = await Swal.fire({ title: t('BankingView.transfers.confirmDelete'), icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
    if (!r.isConfirmed) return
    try { await transfers.deleteTransfer(item.id); alerts.success(t('BankingView.alerts.transferDeleted')); await transfers.fetchTransferOrders() }
    catch (e) { alerts.error(e.message) }
}

async function confirmDeleteBatch() {
    const items = batchDeletable.value
    if (!items.length) return
    const r = await Swal.fire({ title: t('BankingView.transfers.confirmDeleteBatch', { count: items.length }), icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
    if (!r.isConfirmed) return
    try {
        await Promise.all(items.map(o => transfers.deleteTransfer(o.id)))
        selectedIds.value = []
        alerts.success(t('BankingView.alerts.transfersBatchDeleted', { count: items.length }))
        await transfers.fetchTransferOrders()
    } catch (e) { alerts.error(e.message) }
}

async function confirmSubmitTransfer(item) {
    submitTarget.value = item; batchSubmitIds.value = []; batchId.value = null; submitPin.value = ''
    const account = banking.accounts.value.find(a => a.id === item.bank_account_id)
    if (account?.has_saved_pin) {
        try {
            const pin = await banking.loadBankingPin(item.bank_account_id)
            if (pin) { submitPin.value = pin; executeSubmitTransfer(); return }
        } catch { /* Fallback auf Dialog */ }
    }
    showPinDialog.value = true
}

// "Auswahl senden" (manuelle Checkbox-Auswahl) — ausgewählte Überweisungen
// einzeln nacheinander senden, jede mit eigener pushTAN (wie "Alle senden").
async function confirmSubmitBatch() {
    if (batchSelectable.value.length < 2) return
    batchExecutionDate.value = null
    pendingGroups.value = batchSelectable.value.map(o => [o])
    await startSubmitGroup(pendingGroups.value[0])
}

// Startet das Senden einer Gruppe gleichartiger Drafts (gleiches Konto +
// Ausfuehrungsdatum). >= 2 -> Sammelauftrag, sonst Einzelueberweisung. Bei
// gespeicherter PIN wird ohne Dialog direkt gesendet.
async function startSubmitGroup(group) {
    if (!group || group.length === 0) return
    if (group.length < 2) { await confirmSubmitTransfer(group[0]); return }
    submitTarget.value = null; batchSubmitIds.value = group.map(d => d.id)
    batchSubmitTotalCache = group.reduce((s,d) => s + parseFloat(d.amount||0), 0)
    batchId.value = null; submitPin.value = ''
    const accountId = group[0].bank_account_id
    const account = banking.accounts.value.find(a => a.id === accountId)
    if (account?.has_saved_pin) {
        try {
            const pin = await banking.loadBankingPin(accountId)
            if (pin) { submitPin.value = pin; executeSubmitTransfer(); return }
        } catch { /* Fallback auf Dialog */ }
    }
    showPinDialog.value = true
}

// "Alle senden" — sammelt alle Drafts, gruppiert nach Konto+Datum und sendet
// Gruppe fuer Gruppe. Nach erfolgreichem Senden einer Gruppe startet
// advanceGroupQueue() automatisch die naechste.
async function confirmSubmitAllDrafts() {
    if (draftCount.value === 0) { alerts.info(t('BankingView.transfers.noDraftsToSubmit')); return }
    const r = await Swal.fire({
        title: t('BankingView.transfers.submitAllConfirmTitle'),
        text:  t('BankingView.transfers.submitAllConfirmText', { count: draftCount.value, total: formatCurrency(draftTotal.value) }),
        icon: 'question', showCancelButton: true,
        confirmButtonText: t('BankingView.transfers.submit'),
        cancelButtonText:  t('BankingView.sync.cancel')
    })
    if (!r.isConfirmed) return
    batchExecutionDate.value = null
    // Einzeln nacheinander abarbeiten: jede Überweisung als eigener Auftrag mit
    // eigener pushTAN-Freigabe. Ein echter Sammelauftrag wird von der Sparkasse
    // blockiert (asynchroner VoP-Namensabgleich → keine Sammel-pushTAN); der
    // Einzelversand funktioniert dagegen zuverlässig. Ein Klick startet die
    // ganze Kette, der Nutzer bestätigt jede Freigabe am Handy.
    pendingGroups.value = draftOrders.value.map(o => [o])
    await startSubmitGroup(pendingGroups.value[0])
}

// Aktuelle Gruppe als fertig markieren und ggf. die naechste starten.
async function advanceGroupQueue() {
    if (pendingGroups.value.length === 0) return
    pendingGroups.value.shift()
    const next = pendingGroups.value[0]
    if (next) await startSubmitGroup(next)
}

let batchSubmitTotalCache = 0
const batchSubmitTotal = computed(() => batchSubmitTotalCache)

function closePinDialog() { submitPin.value = ''; submitTarget.value = null; batchSubmitIds.value = []; batchId.value = null; pendingGroups.value = []; showPinDialog.value = false }

async function executeSubmitTransfer() {
    if (!submitPin.value) return
    try {
        const result = isBatchSubmit.value
            ? await transfers.submitTransferBatch(batchSubmitIds.value, submitPin.value, batchExecutionDate.value)
            : await transfers.submitTransfer(submitTarget.value.id, submitPin.value)
        if (result.vopApprovalRequired) { showPinDialog.value = false; showVopDialog.value = true; return }
        if (result.tanRequired) {
            if (isBatchSubmit.value && result.batchId) batchId.value = result.batchId
            showPinDialog.value = false; transferTanInput.value = ''; showTransferTanDialog.value = true
        } else {
            if (rememberTransferPin.value && submitBankAccountId.value) {
                banking.saveBankingPin(submitBankAccountId.value, submitPin.value).catch(() => {})
                banking.fetchAccounts()
            }
            const key = isBatchSubmit.value ? 'BankingView.alerts.batchSubmitted' : 'BankingView.alerts.transferSubmitted'
            const params = isBatchSubmit.value ? { count: result.count } : {}
            submitPin.value = ''; showPinDialog.value = false; selectedIds.value = []; batchSubmitIds.value = []
            alerts.success(t(key, params))
            await transfers.fetchTransferOrders()
            await advanceGroupQueue()
        }
    } catch (e) { pendingGroups.value = []; alerts.error(e.message) }
}

async function confirmVopApproval() {
    try {
        const result = await transfers.approveVopTransfer(submitTarget.value.id, submitPin.value)
        if (result.tanRequired) { showVopDialog.value = false; transferTanInput.value = ''; showTransferTanDialog.value = true }
        else { showVopDialog.value = false; submitPin.value = ''; alerts.success(t('BankingView.alerts.transferSubmitted')); await transfers.fetchTransferOrders(); await advanceGroupQueue() }
    } catch (e) { pendingGroups.value = []; showVopDialog.value = false; submitPin.value = ''; alerts.error(e.message) }
}

function cancelVopApproval() { showVopDialog.value = false; submitPin.value = ''; submitTarget.value = null; pendingGroups.value = []; alerts.info(t('BankingView.alerts.transferCancelled')) }

async function submitTanForTransfer() {
    const tanValue = transfers.tanChallenge.decoupled ? '' : transferTanInput.value
    if (!transfers.tanChallenge.decoupled && !transferTanInput.value) return
    stopDecoupledPolling()
    try {
        const result = isBatchSubmit.value
            ? await transfers.submitTransferBatchTan(batchId.value, tanValue, submitPin.value)
            : await transfers.submitTransferTan(submitTarget.value.id, tanValue, submitPin.value)
        if (result.tanRequired) {
            if (result.batchId) batchId.value = result.batchId
            transferTanInput.value = ''
            if (transfers.tanChallenge.decoupled) startTransferDecoupledPolling()
            return
        }
        if (rememberTransferPin.value && submitBankAccountId.value) {
            banking.saveBankingPin(submitBankAccountId.value, submitPin.value).catch(() => {})
            banking.fetchAccounts()
        }
        // Erfolgsmeldung VOR dem Leeren von batchSubmitIds bestimmen — sonst ist
        // isBatchSubmit bereits false und es käme fälschlich der Einzel-Toast.
        const key = isBatchSubmit.value ? 'BankingView.alerts.batchSubmitted' : 'BankingView.alerts.transferSubmitted'
        const params = isBatchSubmit.value ? { count: result.count || batchSubmitIds.value.length } : {}
        showTransferTanDialog.value = false; submitPin.value = ''; transferTanInput.value = ''
        selectedIds.value = []; batchSubmitIds.value = []
        alerts.success(t(key, params))
        await transfers.fetchTransferOrders()
        await advanceGroupQueue()
    } catch (e) { pendingGroups.value = []; alerts.error(e.message) }
}

async function runReconcile() {
    reconciling.value = true
    try {
        const result = await transfers.reconcileTransfers(null)
        await transfers.fetchTransferOrders()
        const matched = result.matched_count || 0
        const expired = result.expired_count || 0
        if (!matched && !expired) alerts.info(t('BankingView.alerts.reconcileNoChanges'))
        else alerts.success(t('BankingView.alerts.reconcileResult', { matched, expired }))
    } catch (e) { alerts.error(e.message) }
    finally { reconciling.value = false }
}

// ── Tabs: Abgleich ────────────────────────────────────────
const invoiceTab           = ref('receivables')
const invoiceSearch        = ref('')
const selectedTransaction  = ref(null)
const selectedForBooking   = ref([])

const unmatchedTransactions = computed(() =>
    (banking.transactions.value || []).filter(t => ['unmatched','matched'].includes(t.match_status))
)

const filteredInvoices = computed(() => {
    const list = invoiceTab.value === 'receivables'
        ? (matching.openInvoices.value?.receivables || [])
        : (matching.openInvoices.value?.payables    || [])
    if (!invoiceSearch.value) return list
    const q = invoiceSearch.value.toLowerCase()
    return list.filter(i =>
        (i.invnumber||'').toLowerCase().includes(q) ||
        (i.customer_name||'').toLowerCase().includes(q) ||
        (i.vendor_name||'').toLowerCase().includes(q)
    )
})

function selectTransaction(bt) {
    selectedTransaction.value = bt
}

async function matchInvoiceToTransaction(inv) {
    if (!selectedTransaction.value) { alerts.info('Bitte zuerst einen Bankumsatz auswählen'); return }
    try {
        await matching.matchTransaction(selectedTransaction.value.id, inv.id, invoiceTab.value === 'payables' ? 'ap' : 'ar')
        alerts.success(t('BankingView.alerts.matchSuccess'))
        selectedTransaction.value = null
        await loadTransactions()
        await matching.fetchOpenInvoices(selectedAccountId.value)
    } catch (e) { alerts.error(e.message) }
}

async function runAutoMatchAction() {
    try {
        const result = await matching.runAutoMatch(selectedAccountId.value)
        alerts.success(t('BankingView.reconciliation.matchCount', { count: result.count || 0 }))
        await loadTransactions()
    } catch (e) { alerts.error(e.message) }
}

async function bookSelectedAction() {
    if (!selectedForBooking.value.length) return
    const ok = await Swal.fire({ title: t('BankingView.reconciliation.confirmBook'), icon: 'question', showCancelButton: true })
    if (!ok.isConfirmed) return
    try {
        await matching.bookMatchedTransactions(selectedForBooking.value, selectedAccountId.value)
        alerts.success(t('BankingView.reconciliation.bookSuccess', { count: selectedForBooking.value.length }))
        selectedForBooking.value = []
        await loadTransactions()
    } catch (e) { alerts.error(e.message) }
}

// ── Sync ──────────────────────────────────────────────────
const showSyncDialog  = ref(false)
const showTanDialog   = ref(false)
const syncAccount     = ref(null)
const syncPin         = ref('')
const rememberPin     = ref(true)
const tanInput        = ref('')
let   decoupledPollTimer = null

function openSyncDialog(account) {
    syncAccount.value = account
    syncingAccountId.value = account.id
    if (account.has_saved_pin) {
        banking.loadBankingPin(account.id).then(pin => {
            if (pin) { syncPin.value = pin; rememberPin.value = true; runSync(); return }
            syncPin.value = ''; showSyncDialog.value = true
        }).catch(() => { syncPin.value = ''; showSyncDialog.value = true })
    } else {
        syncPin.value = ''; rememberPin.value = true; showSyncDialog.value = true
    }
}

function closeSyncDialog() { syncPin.value = ''; showSyncDialog.value = false; syncingAccountId.value = null }

async function deleteSavedPin() {
    try { await banking.deleteBankingPin(syncAccount.value.id); await banking.fetchAccounts(); alerts.info(t('BankingView.sync.pinDeleted')) }
    catch (e) { alerts.error(e.message) }
}

function stopDecoupledPolling() { if (decoupledPollTimer) { clearTimeout(decoupledPollTimer); decoupledPollTimer = null } }

function startDecoupledPolling() {
    stopDecoupledPolling()
    const tick = async () => {
        if (!showTanDialog.value) { stopDecoupledPolling(); return }
        try {
            const result = await banking.submitTan(syncAccount.value.id, '', syncPin.value)
            if (result.tanRequired) { decoupledPollTimer = setTimeout(tick, 2000); return }
            stopDecoupledPolling()
            if (rememberPin.value) banking.saveBankingPin(syncAccount.value.id, syncPin.value).catch(()=>{})
            showTanDialog.value = false; syncPin.value = ''; tanInput.value = ''; syncingAccountId.value = null
            if (result.importedCount > 0) alerts.success(t('BankingView.alerts.syncSuccess', { count: result.importedCount }))
            else alerts.info(t('BankingView.alerts.syncNoNew'))
            activeTab.value = 'transactions'
            await banking.fetchAccounts()
            await loadTransactions()
        } catch (e) { stopDecoupledPolling(); alerts.error(e.message) }
    }
    decoupledPollTimer = setTimeout(tick, 2000)
}

watch(showTanDialog, v => { if (!v) stopDecoupledPolling() })

function startTransferDecoupledPolling() {
    stopDecoupledPolling()
    const tick = async () => {
        if (!showTransferTanDialog.value) { stopDecoupledPolling(); return }
        try {
            const result = isBatchSubmit.value
                ? await transfers.submitTransferBatchTan(batchId.value, '', submitPin.value)
                : await transfers.submitTransferTan(submitTarget.value?.id, '', submitPin.value)
            if (result.tanRequired) {
                if (result.batchId) batchId.value = result.batchId
                decoupledPollTimer = setTimeout(tick, 2000)
                return
            }
            stopDecoupledPolling()
            if (rememberTransferPin.value && submitBankAccountId.value) {
                banking.saveBankingPin(submitBankAccountId.value, submitPin.value).catch(() => {})
                banking.fetchAccounts()
            }
            // Erfolgsmeldung VOR dem Leeren von batchSubmitIds bestimmen (sonst Einzel-Toast).
            const key = isBatchSubmit.value ? 'BankingView.alerts.batchSubmitted' : 'BankingView.alerts.transferSubmitted'
            const params = isBatchSubmit.value ? { count: result.count || batchSubmitIds.value.length } : {}
            showTransferTanDialog.value = false; submitPin.value = ''; transferTanInput.value = ''
            selectedIds.value = []; batchSubmitIds.value = []
            alerts.success(t(key, params))
            await transfers.fetchTransferOrders()
            await advanceGroupQueue()
        } catch (e) { stopDecoupledPolling(); pendingGroups.value = []; showTransferTanDialog.value = false; submitPin.value = ''; alerts.error(e.message) }
    }
    decoupledPollTimer = setTimeout(tick, 2000)
}

watch(showTransferTanDialog, v => {
    if (!v) { stopDecoupledPolling(); return }
    if (transfers.tanChallenge.decoupled) startTransferDecoupledPolling()
})

// Abbruch im TAN-Dialog: laufende Gruppen-Warteschlange verwerfen.
function cancelTransferTan() {
    pendingGroups.value = []
    showTransferTanDialog.value = false
}

async function runSync() {
    if (!syncPin.value) return
    try {
        const result = await banking.syncTransactions(syncAccount.value.id, syncPin.value, null, null)
        if (result.tanRequired) {
            showSyncDialog.value = false; tanInput.value = ''; showTanDialog.value = true
            if (banking.tanChallenge.decoupled) startDecoupledPolling()
        } else {
            if (rememberPin.value) banking.saveBankingPin(syncAccount.value.id, syncPin.value).catch(()=>{})
            syncPin.value = ''; showSyncDialog.value = false; syncingAccountId.value = null
            if (result.importedCount > 0) alerts.success(t('BankingView.alerts.syncSuccess', { count: result.importedCount }))
            else alerts.info(t('BankingView.alerts.syncNoNew'))
            activeTab.value = 'transactions'
            await banking.fetchAccounts()
            await loadTransactions()
        }
    } catch (e) { alerts.error(e.message) }
}

function startSync() { return runSync() }

async function submitTanAction() {
    const isDecoupled = banking.tanChallenge.decoupled
    if (!isDecoupled && !tanInput.value) return
    stopDecoupledPolling()
    try {
        const result = await banking.submitTan(syncAccount.value.id, isDecoupled ? '' : tanInput.value, syncPin.value)
        if (result.tanRequired) { if (isDecoupled) startDecoupledPolling(); return }
        if (rememberPin.value) banking.saveBankingPin(syncAccount.value.id, syncPin.value).catch(()=>{})
        showTanDialog.value = false; syncPin.value = ''; tanInput.value = ''; syncingAccountId.value = null
        if (result.importedCount > 0) alerts.success(t('BankingView.alerts.syncSuccess', { count: result.importedCount }))
        else alerts.info(t('BankingView.alerts.syncNoNew'))
        activeTab.value = 'transactions'
        await banking.fetchAccounts()
        await loadTransactions()
    } catch (e) { alerts.error(e.message) }
}

// ── FinTS Setup ───────────────────────────────────────────
const showFintsDialog = ref(false)
const fintsForm = reactive({ bank_account_id: null, fints_url: '', fints_bank_code: '', fints_username: '', fints_tan_mode: '', sync_from_date: '', existing: false })
const fintsBankName = ref('')
const tanModeOptions = [
    { value: '900', title: '900 — iTAN' },
    { value: '910', title: '910 — chipTAN manuell' },
    { value: '911', title: '911 — chipTAN optisch' },
    { value: '912', title: '912 — chipTAN USB' },
    { value: '913', title: '913 — chipTAN QR' },
    { value: '920', title: '920 — smsTAN' },
    { value: '921', title: '921 — pushTAN' },
    { value: '942', title: '942 — mobileTAN' },
]

async function openFintsSetup(account) {
    fintsForm.bank_account_id = account.id
    fintsForm.fints_bank_code = account.bank_code || ''
    fintsForm.fints_url = ''; fintsForm.fints_username = ''; fintsForm.fints_tan_mode = ''; fintsForm.sync_from_date = ''; fintsForm.existing = false
    fintsBankName.value = ''
    const config = await banking.fetchFintsConfig(account.id)
    if (config) {
        Object.assign(fintsForm, { fints_url: config.fints_url||'', fints_bank_code: config.fints_bank_code||'', fints_username: config.fints_username||'', fints_tan_mode: config.fints_tan_mode||'', sync_from_date: config.sync_from_date||'', existing: true })
    }
    if (!fintsForm.fints_url && fintsForm.fints_bank_code) await autofillFintsUrl(fintsForm.fints_bank_code)
    showFintsDialog.value = true
}

async function autofillFintsUrl(blz) {
    const info = await banking.fetchFintsUrlByBlz(blz)
    if (info?.url) { fintsForm.fints_url = info.url; fintsBankName.value = info.name || '' }
    else { fintsBankName.value = '' }
}

function onBankCodeChange(blz) { if (!fintsForm.fints_url && blz) autofillFintsUrl(blz) }

async function saveFintsSetup() {
    try { await banking.saveFintsConfig(fintsForm); showFintsDialog.value = false; alerts.success(t('BankingView.alerts.fintsConfigSaved')); await banking.fetchAccounts() }
    catch (e) { alerts.error(e.message) }
}

async function removeFintsConfig() {
    try { await banking.deleteFintsConfig(fintsForm.bank_account_id); showFintsDialog.value = false; alerts.success(t('BankingView.alerts.fintsConfigDeleted')); await banking.fetchAccounts() }
    catch (e) { alerts.error(e.message) }
}

// ── Hilfsfunktionen ───────────────────────────────────────
function formatCurrency(v)   { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0) }
function formatIban(iban)     { return iban ? iban.replace(/(.{4})/g, '$1 ').trim() : '—' }
function formatDateShort(d)   { return d ? new Date(d).toLocaleDateString('de-DE') : '—' }
function capitalize(s)        { return s ? s.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('') : '' }
function txStatusColor(s)     { return { unmatched: 'warning', matched: 'info', booked: 'success', ignored: 'default' }[s] || 'default' }
function transferStatusColor(s) { return { draft: 'default', pending_tan: 'warning', submitted: 'info', executed: 'success', rejected: 'error', cancelled: 'default', expired: 'warning' }[s] || 'default' }

// ── Initialisierung ───────────────────────────────────────
onMounted(async () => {
    await Promise.all([banking.fetchAccounts(), transfers.fetchTransferOrders(), tmplStore.fetchGroups(), tmplStore.fetchTemplates()])
    if (!selectedAccountId.value && banking.accounts.value.length > 0) {
        selectedAccountId.value = banking.accounts.value[0].id
    }
    await Promise.all([
        loadTransactions(),
        soStore.fetchStandingOrders(selectedAccountId.value),
        soStore.fetchMandates(selectedAccountId.value),
        bankUtils.fetchAlerts(selectedAccountId.value),
        bankUtils.fetchDirectDebits(selectedAccountId.value),
        loadForecast(),
        selectedAccountId.value ? matching.fetchOpenInvoices(selectedAccountId.value) : Promise.resolve(),
    ])
    // Query-Parameter: direkt Überweisungs-Dialog öffnen
    const newFor = parseInt(route.query.new_for, 10)
    if (newFor > 0) openNewTransfer(newFor)
    if (route.query.tab) activeTab.value = route.query.tab

    // Query-Parameter: aus einer Rechnung kommend den Such-Filter vorbelegen,
    // damit nur der/die betreffende(n) Umsatz/Umsätze sichtbar sind. Datumsfilter
    // wird hierfür geleert (die Zahlung kann auch aus einem früheren Jahr stammen).
    if (route.query.q) {
        applyingJump = true
        const focusAcct = parseInt(route.query.account, 10)
        if (focusAcct > 0) selectedAccountId.value = focusAcct
        activeTab.value = 'transactions'
        matchFilter.value = 'all'
        fromDate.value = ''
        toDate.value = ''
        txSearch.value = String(route.query.q)
        await loadTransactions()
        applyingJump = false
    }
    // Trust-Anker-Refresh global starten (läuft für die gesamte Browser-Session)
    startGlobalKeepAlive()
})

watch(selectedAccountId, async (id) => {
    if (!id) return
    if (!applyingJump) {
        matchFilter.value = 'all'; fromDate.value = FY_START; toDate.value = FY_END; txSearch.value = ''
    }
    await Promise.all([
        loadTransactions(),
        matching.fetchOpenInvoices(id),
        soStore.fetchStandingOrders(id),
        soStore.fetchMandates(id),
        bankUtils.fetchAlerts(id),
        bankUtils.fetchDirectDebits(id),
        loadForecast(),
    ])
})

watch(activeTab, async (tab) => {
    if (tab === 'transactions') await loadTransactions()
    if (tab === 'reconciliation' && selectedAccountId.value) {
        await banking.fetchTransactions(selectedAccountId.value, { match_status: 'all' })
        await matching.fetchOpenInvoices(selectedAccountId.value)
    }
    if (tab === 'liquidity' && selectedAccountId.value && !bankUtils.forecast.value.length) {
        await loadForecast()
    }
})
</script>

<style scoped>
.tab-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.recon-list {
    max-height: 480px;
    overflow-y: auto;
}
.selected-tx {
    background: rgba(var(--v-theme-primary), 0.08) !important;
    border-left: 3px solid rgb(var(--v-theme-primary));
}
:deep(.tx-bookable) {
    cursor: pointer;
}
:deep(.tx-booked) {
    opacity: 0.65;
}
</style>
