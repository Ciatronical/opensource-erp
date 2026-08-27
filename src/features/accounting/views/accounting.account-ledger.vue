<template>
    <NavbarView />
    <v-container fluid>
        <AccountingPageHeader :title="t('AccountingView.ledger.title')">
            <template #actions>
                <v-btn variant="tonal" size="small" class="text-none" :disabled="!account" :loading="printing"
                       @click="printLedger({ accno: account.accno, from_date: fromDate, to_date: toDate })">
                    <v-icon start size="small">mdi-printer-outline</v-icon>
                    {{ t('AccountingView.ledger.print') }}
                </v-btn>
                <v-btn variant="text" size="small" class="text-none"
                       :to="{ name: 'accounting-reports' }">
                    <v-icon start size="small">mdi-chart-box-outline</v-icon>
                    {{ t('AccountingView.reports.title') }}
                </v-btn>
            </template>
        </AccountingPageHeader>

        <v-row>
            <v-col cols="12">
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                         class="mt-1 mb-2" :text="t('AccountingView.ledger.info')" />
            </v-col>
        </v-row>

        <!-- Auswahl: Konto + Zeitraum -->
        <v-row>
            <v-col cols="12" sm="6" md="4">
                <!-- Das Suchfeld bleibt leer und dient nur der Auswahl. Waere das
                     gewaehlte Konto darin vorbelegt, haenge v-autocomplete jede
                     Eingabe an den Titel an ("1800 BankKasse") und faende nichts. -->
                <v-autocomplete
                    v-model="accountPick"
                    v-model:search="accountSearch"
                    :items="accountOptions"
                    item-title="label"
                    item-value="accno"
                    return-object
                    :label="t('AccountingView.ledger.account')"
                    :placeholder="t('AccountingView.ledger.pickHint')"
                    :no-data-text="t('AccountingView.ledger.pickHint')"
                    density="compact" variant="outlined" hide-details
                    :loading="accountLoading" no-filter clearable
                    prepend-inner-icon="mdi-bank-outline"
                    @update:model-value="onAccountPick" />
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-text-field v-model="fromDate" type="date" :label="t('AccountingView.ledger.fromDate')"
                              density="compact" variant="outlined" hide-details @change="load" />
            </v-col>
            <v-col cols="6" sm="3" md="2">
                <v-text-field v-model="toDate" type="date" :label="t('AccountingView.ledger.toDate')"
                              density="compact" variant="outlined" hide-details @change="load" />
            </v-col>
            <v-col cols="12" sm="6" md="2">
                <v-btn color="primary" variant="elevated" block :disabled="!account" @click="load">
                    <v-icon start>mdi-refresh</v-icon>{{ t('AccountingView.ledger.show') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Kopfzahlen -->
        <v-row v-if="ledger" class="mt-1">
            <v-col cols="12">
                <v-chip class="mr-2 mb-1" color="primary" variant="tonal" prepend-icon="mdi-tag-outline">
                    {{ accountLabel }}
                </v-chip>
                <v-chip class="mr-2 mb-1" variant="tonal">
                    {{ t('AccountingView.ledger.opening') }}: <strong class="ml-1">{{ formatCurrency(ledger.opening) }}</strong>
                </v-chip>
                <v-chip class="mr-2 mb-1" color="error" variant="tonal">
                    {{ t('AccountingView.ledger.sumSoll') }}: {{ formatCurrency(ledger.sum_soll) }}
                </v-chip>
                <v-chip class="mr-2 mb-1" color="success" variant="tonal">
                    {{ t('AccountingView.ledger.sumHaben') }}: {{ formatCurrency(ledger.sum_haben) }}
                </v-chip>
                <v-chip class="mr-2 mb-1" color="primary" variant="flat">
                    {{ t('AccountingView.ledger.closing') }}: <strong class="ml-1">{{ formatCurrency(ledger.closing) }}</strong>
                </v-chip>
            </v-col>
        </v-row>

        <!-- Leerer Zeitraum: sagen, was los ist, statt eine leere Tabelle zu zeigen -->
        <v-row v-if="showEmptyHint" class="mt-2">
            <v-col cols="12">
                <v-alert :type="widened ? 'info' : 'warning'" variant="tonal" density="comfortable"
                         icon="mdi-calendar-search">
                    <div class="d-flex align-center flex-wrap ga-3">
                        <span>
                            {{ widened
                                ? t('AccountingView.ledger.emptyEverywhere', { account: accountLabel })
                                : t('AccountingView.ledger.emptyInRange', { account: accountLabel }) }}
                        </span>
                        <v-btn v-if="!widened" size="small" variant="elevated" color="primary"
                               class="text-none" :loading="loading" @click="widenRange">
                            <v-icon start size="small">mdi-arrow-expand-horizontal</v-icon>
                            {{ t('AccountingView.ledger.widenRange') }}
                        </v-btn>
                    </div>
                </v-alert>
            </v-col>
        </v-row>

        <!-- Buchungen -->
        <v-row class="mt-2">
            <v-col cols="12">
                <v-data-table
                    :headers="headers"
                    :items="ledger?.rows || []"
                    :loading="loading"
                    density="compact"
                    :items-per-page="100"
                    :no-data-text="account ? t('AccountingView.ledger.noRows') : t('AccountingView.ledger.pickAccount')"
                    @click:row="(_, { item }) => openEntry(item)">
                    <template #[`item.soll`]="{ item }">
                        <span v-if="Number(item.soll) > 0">{{ formatCurrency(item.soll) }}</span>
                    </template>
                    <template #[`item.haben`]="{ item }">
                        <span v-if="Number(item.haben) > 0">{{ formatCurrency(item.haben) }}</span>
                    </template>
                    <template #[`item.saldo`]="{ item }">
                        <span :class="Number(item.saldo) < 0 ? 'text-error' : ''">{{ formatCurrency(item.saldo) }}</span>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>

        <!-- Beleg-Detail (Soll/Haben-Zeilen der angeklickten Buchung) -->
        <v-dialog v-model="entryDialog" max-width="800">
            <v-card v-if="entry">
                <v-card-title>{{ entry.head?.reference || '—' }} — {{ entry.head?.transdate_fmt }}</v-card-title>
                <v-card-subtitle v-if="entry.head?.partner">{{ entry.head.partner }}</v-card-subtitle>
                <v-card-text>
                    <div class="mb-2">{{ entry.head?.description }}</div>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>{{ t('AccountingView.ledger.colAccount') }}</th>
                                <th class="text-end">{{ t('AccountingView.ledger.soll') }}</th>
                                <th class="text-end">{{ t('AccountingView.ledger.haben') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in entry.lines" :key="l.acc_trans_id">
                                <td>{{ l.accno }} {{ l.account_name }}</td>
                                <td class="text-end">{{ Number(l.soll) > 0 ? formatCurrency(l.soll) : '' }}</td>
                                <td class="text-end">{{ Number(l.haben) > 0 ? formatCurrency(l.haben) : '' }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="entryDialog = false">{{ t('AccountingView.ledger.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import AccountingPageHeader from '../components/accounting.page-header.vue'
import { useAccounting } from '../composables/useAccounting.js'
import { useReports } from '../composables/useReports.js'

const { t } = useI18n()
const route = useRoute()
const { searchAccounts, fetchAccountLedger, fetchJournalEntry } = useAccounting()
const { printing, printAccountLedger: printLedger } = useReports()

const account = ref(null)
const accountOptions = ref([])
const accountLoading = ref(false)
const accountSearch = ref('')
const accountPick = ref(null)
let   accountTimer = null

const fromDate = ref(`${new Date().getFullYear()}-01-01`)
const toDate   = ref(todayISO())
const ledger   = ref(null)
const loading  = ref(false)

const entryDialog = ref(false)
const entry = ref(null)
const widened = ref(false)

// Ein Konto ohne Buchungen im gewaehlten Jahr ist der Normalfall, keine Panne —
// deshalb wird es erklaert und der Zeitraum laesst sich mit einem Klick oeffnen.
const showEmptyHint = computed(() =>
    !!account.value?.accno && !loading.value && ledger.value !== null && !(ledger.value?.rows || []).length
)

const accountLabel = computed(() => account.value?.label || account.value?.accno || '')

function todayISO() {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const headers = computed(() => [
    { title: t('AccountingView.ledger.date'), key: 'transdate_fmt', width: '110px' },
    { title: t('AccountingView.ledger.reference'), key: 'reference', width: '150px' },
    { title: t('AccountingView.ledger.partner'), key: 'partner' },
    { title: t('AccountingView.ledger.memo'), key: 'memo' },
    { title: t('AccountingView.ledger.soll'), key: 'soll', align: 'end', width: '120px' },
    { title: t('AccountingView.ledger.haben'), key: 'haben', align: 'end', width: '120px' },
    { title: t('AccountingView.ledger.saldo'), key: 'saldo', align: 'end', width: '130px' }
])

/**
 * Auswahl uebernehmen und das Suchfeld wieder leeren — das gewaehlte Konto
 * steht als Chip ueber der Tabelle, nicht im Eingabefeld.
 */
function onAccountPick(picked) {
    if (!picked) return
    account.value = picked
    accountPick.value = null
    accountSearch.value = ''
    widened.value = false
    load()
}

// An den Suchtext gehaengt statt an ein Template-Ereignis: so kann die Suche
// nicht stillschweigend abgehaengt werden, wenn das Feld einmal umgebaut wird.
watch(accountSearch, q => {
    clearTimeout(accountTimer)
    if (!q || q.length < 1) return
    accountTimer = setTimeout(async () => {
        accountLoading.value = true
        const rows = await searchAccounts(q)
        accountOptions.value = rows.map(a => ({
            accno: a.accno, description: a.description, label: `${a.accno} ${a.description}`
        }))
        accountLoading.value = false
    }, 300)
})

async function load() {
    if (!account.value?.accno) { ledger.value = null; return }
    loading.value = true
    ledger.value = await fetchAccountLedger({ accno: account.value.accno, from_date: fromDate.value, to_date: toDate.value })
    loading.value = false
}

/** Zeitraum auf alle Jahre oeffnen — beantwortet "gibt es hier ueberhaupt etwas?" */
async function widenRange() {
    fromDate.value = '1990-01-01'
    toDate.value = todayISO()
    widened.value = true
    await load()
}

async function openEntry(item) {
    entry.value = await fetchJournalEntry(item.trans_id, item.src)
    if (entry.value) entryDialog.value = true
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

// Deep-Link aus Kontenrahmen und Saldenliste: ?accno=5400&from=…&to=…
onMounted(async () => {
    const accno = route.query.accno
    if (!accno) return

    // Kommt der Aufruf aus der Saldenliste, muss das Kontoblatt denselben
    // Zeitraum zeigen — sonst stimmen die Summen der beiden Seiten nicht überein.
    if (route.query.from) fromDate.value = String(route.query.from)
    if (route.query.to)   toDate.value   = String(route.query.to)

    // Der Deep-Link bringt nur die Nummer mit. Ohne die Bezeichnung stuende im
    // Auswahlfeld bloss "6540" — deshalb wird sie hier nachgeschlagen.
    const rows = await searchAccounts(String(accno))
    const hit = rows.find(a => String(a.accno) === String(accno))
    account.value = hit
        ? { accno: hit.accno, description: hit.description, label: `${hit.accno} ${hit.description}` }
        : { accno: String(accno), label: String(accno) }
    accountOptions.value = [account.value]
    await load()
})
</script>
