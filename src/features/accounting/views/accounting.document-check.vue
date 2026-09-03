<!-- src/features/accounting/views/accounting.document-check.vue -->
<!--
    Belegprüfung.

    Jeder Beleg trägt seit der Ablage einen SHA-256-Hash. Der beweist aber erst
    dann etwas, wenn ihn jemand nachrechnet — bis hierher lag er ungenutzt in
    der Datenbank. Diese Seite rechnet ihn nach und zeigt, was nicht stimmt:
    veränderte Dateien, verschwundene Dateien, Belege ohne Bearbeiter und
    Belege, die an keiner Buchung hängen.
-->
<template>
    <NavbarView />

    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.documentCheck.title')">
            <template #actions>
                <v-btn color="primary" variant="flat" size="small" class="text-none"
                       :loading="loading" @click="check">
                    <v-icon start size="small">mdi-shield-check-outline</v-icon>
                    {{ t('AccountingView.documentCheck.run') }}
                </v-btn>
            </template>
        </AccountingPageHeader>

        <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                 class="mb-3" :text="t('AccountingView.documentCheck.info')" />

        <template v-if="report">
            <!-- Ergebnis auf einen Blick -->
            <v-card variant="outlined" class="mb-3">
                <div class="figures">
                    <div v-for="tile in tiles" :key="tile.key"
                         class="figures__cell" :class="{ 'figures__cell--bad': tile.bad && tile.value > 0 }">
                        <div class="figures__label">{{ tile.label }}</div>
                        <div class="figures__value" :class="tile.bad && tile.value > 0 ? 'text-error' : ''">
                            {{ tile.value }}
                        </div>
                    </div>
                </div>
            </v-card>

            <v-alert v-if="alarm" type="error" variant="tonal" density="comfortable"
                     icon="mdi-alert-octagon-outline" class="mb-3">
                {{ t('AccountingView.documentCheck.tampered', {
                    changed: report.zaehler.geaendert, missing: report.zaehler.fehlt }) }}
            </v-alert>
            <v-alert v-else-if="report.gesamt > 0" type="success" variant="tonal" density="comfortable"
                     icon="mdi-shield-check-outline" class="mb-3"
                     :text="t('AccountingView.documentCheck.allGood', { count: report.geprueft })" />

            <v-alert v-if="report.nicht_geprueft > 0" type="warning" variant="tonal" density="compact"
                     class="mb-3" :text="t('AccountingView.documentCheck.notAllChecked', { count: report.nicht_geprueft })" />

            <div class="d-flex align-center flex-wrap ga-3 mb-2">
                <v-switch v-model="onlyProblems" :label="t('AccountingView.documentCheck.onlyProblems')"
                          density="compact" color="primary" hide-details class="flex-grow-0" />
                <v-spacer />
                <span class="text-caption text-medium-emphasis">
                    <v-icon size="14" class="mr-1">mdi-folder-outline</v-icon>
                    {{ t('AccountingView.documentCheck.directory') }}: <code>{{ report.verzeichnis }}</code>
                </span>
            </div>

            <v-data-table
                :headers="headers"
                :items="rows"
                :items-per-page="50"
                density="compact"
                hover
                :no-data-text="t('AccountingView.documentCheck.noRows')"
                @click:row="(_, { item }) => openLog(item)">
                <template #[`item.ergebnis`]="{ item }">
                    <v-chip size="x-small" :color="tone(item.ergebnis)" variant="flat">
                        {{ t('AccountingView.documentCheck.result.' + item.ergebnis) }}
                    </v-chip>
                </template>
                <template #[`item.abgelegt_von`]="{ item }">
                    <span v-if="item.abgelegt_von">{{ item.abgelegt_von }}</span>
                    <v-chip v-else size="x-small" color="warning" variant="tonal">
                        {{ t('AccountingView.documentCheck.noEmployee') }}
                    </v-chip>
                </template>
                <template #[`item.zuordnung`]="{ item }">
                    <span v-if="item.ap_id">{{ t('AccountingView.documentCheck.toInvoice') }}</span>
                    <span v-else-if="item.kassenbuchungen > 0">{{ t('AccountingView.documentCheck.toCash') }}</span>
                    <v-chip v-else size="x-small" color="warning" variant="tonal">
                        {{ t('AccountingView.documentCheck.orphan') }}
                    </v-chip>
                </template>
                <template #[`item.aufbewahrung_bis`]="{ item }">
                    {{ item.aufbewahrung_bis ? formatDate(item.aufbewahrung_bis) : '—' }}
                </template>
            </v-data-table>
        </template>

        <v-alert v-else-if="!loading" type="info" variant="tonal" density="comfortable"
                 :text="t('AccountingView.documentCheck.startHint')" />

        <!-- Protokoll eines Belegs -->
        <v-dialog v-model="logDialog" max-width="720" scrollable>
            <v-card v-if="current">
                <v-card-title class="text-subtitle-1">{{ current.name }}</v-card-title>
                <v-card-subtitle>{{ t('AccountingView.documentCheck.logTitle') }}</v-card-subtitle>
                <v-card-text>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>{{ t('AccountingView.documentCheck.logWhen') }}</th>
                                <th>{{ t('AccountingView.documentCheck.logWhat') }}</th>
                                <th>{{ t('AccountingView.documentCheck.logWho') }}</th>
                                <th>{{ t('AccountingView.documentCheck.logNote') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(entry, index) in (logs[current.id] || [])" :key="index">
                                <td class="text-no-wrap">{{ entry.zeitpunkt }}</td>
                                <td>
                                    {{ t('AccountingView.documentCheck.action.' + entry.aktion) }}
                                    <span v-if="entry.ergebnis" class="text-error"> · {{ entry.ergebnis }}</span>
                                </td>
                                <td>{{ entry.mitarbeiter }}</td>
                                <td class="text-caption text-medium-emphasis">{{ entry.hinweis }}</td>
                            </tr>
                            <tr v-if="!(logs[current.id] || []).length">
                                <td colspan="4" class="text-center text-disabled py-4">
                                    {{ t('AccountingView.documentCheck.logEmpty') }}
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="logDialog = false">{{ t('AccountingView.ledger.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useDocumentCheck } from '../composables/useDocumentCheck.js'

const { t } = useI18n()
const { loading, report, logs, runCheck, fetchLog } = useDocumentCheck()

const onlyProblems = ref(false)
const logDialog    = ref(false)
const current      = ref(null)

const headers = computed(() => [
    { title: t('AccountingView.documentCheck.colResult'), key: 'ergebnis', width: '120px' },
    { title: t('AccountingView.documentCheck.colName'),   key: 'name' },
    { title: t('AccountingView.documentCheck.colStored'), key: 'abgelegt_am', width: '140px' },
    { title: t('AccountingView.documentCheck.colBy'),     key: 'abgelegt_von', width: '160px' },
    { title: t('AccountingView.documentCheck.colLink'),   key: 'zuordnung', width: '140px' },
    { title: t('AccountingView.documentCheck.colUntil'),  key: 'aufbewahrung_bis', width: '130px' }
])

const rows = computed(() => {
    const list = report.value?.dokumente || []
    if (!onlyProblems.value) return list
    return list.filter(row => row.ergebnis !== 'ok' || row.ohne_bearbeiter || row.verwaist)
})

const alarm = computed(() =>
    (report.value?.zaehler?.geaendert || 0) > 0 || (report.value?.zaehler?.fehlt || 0) > 0
)

const tiles = computed(() => {
    const z = report.value?.zaehler || {}
    const list = report.value?.dokumente || []
    return [
        { key: 'checked', label: t('AccountingView.documentCheck.tileChecked'), value: report.value?.geprueft || 0 },
        { key: 'ok',      label: t('AccountingView.documentCheck.result.ok'),        value: z.ok || 0 },
        { key: 'changed', label: t('AccountingView.documentCheck.result.geaendert'), value: z.geaendert || 0, bad: true },
        { key: 'missing', label: t('AccountingView.documentCheck.result.fehlt'),     value: z.fehlt || 0, bad: true },
        { key: 'noemp',   label: t('AccountingView.documentCheck.tileNoEmployee'),
          value: list.filter(row => row.ohne_bearbeiter).length, bad: true },
        { key: 'orphan',  label: t('AccountingView.documentCheck.tileOrphan'),
          value: list.filter(row => row.verwaist).length, bad: true }
    ]
})

function tone(result) {
    if (result === 'ok') return 'success'
    if (result === 'geaendert' || result === 'fehlt') return 'error'
    return 'warning'
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString('de-DE') : '—'
}

async function check() {
    await runCheck({ limit: 1000 })
}

async function openLog(row) {
    current.value = row
    logDialog.value = true
    if (!logs.value[row.id]) await fetchLog(row.id)
}

onMounted(check)
</script>

<style scoped>
.figures {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1px;
    background: rgba(var(--v-border-color), var(--v-border-opacity));
}
.figures__cell { background: rgb(var(--v-theme-surface)); padding: .6rem 1rem; }
.figures__cell--bad { background: rgba(var(--v-theme-error), .06); }
.figures__label { font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; opacity: .65; }
.figures__value { font-size: 1.3rem; font-weight: 600; font-variant-numeric: tabular-nums; }

code { font-size: .85em; }
</style>
