<!-- src/features/accounting/views/accounting.cockpit.vue -->
<!--
    Cockpit der Buchhaltung — der eine Bildschirm hinter dem einen Menuepunkt.

    Drei Zonen in dieser Reihenfolge: Wie steht es (Puls), was ist zu tun
    (Arbeitskacheln), wo im Jahr stehe ich (Zeitstrahl). Erledigte Bereiche
    verschwinden aus den Kacheln in die ruhige Zeile darunter — an einem
    sauberen Tag ist diese Seite fast leer, und genau das ist die Rueckmeldung.

    Belege koennen ueberall auf der Seite fallengelassen werden; einen
    Menuepunkt „hochladen" braucht es dafuer nicht mehr.
-->
<template>
    <NavbarView />

    <v-container fluid class="pt-3 cockpit" @dragover.prevent="onDragOver" @dragenter.prevent="onDragOver">
        <!-- Kopfzeile -->
        <div class="d-flex align-center flex-wrap ga-3 mb-4">
            <v-icon color="primary">mdi-calculator-variant-outline</v-icon>
            <h1 class="text-h6 mb-0">{{ t('AccountingView.menu.title') }}</h1>
            <span class="text-caption text-medium-emphasis">{{ periodLabel }}</span>
            <v-spacer />
            <v-btn variant="tonal" size="small" class="text-none" @click="paletteRef?.openPalette()">
                <v-icon start size="small">mdi-magnify</v-icon>
                {{ t('AccountingView.palette.button') }}
                <kbd class="ml-2">{{ shortcutLabel }}</kbd>
            </v-btn>
            <EasymodeSwitch />
            <ConceptButton />
            <v-btn icon variant="text" size="small" :loading="loading"
                   :aria-label="t('AccountingView.cockpit.reload')" @click="load">
                <v-icon>mdi-refresh</v-icon>
            </v-btn>
        </div>

        <!-- ── Puls ──────────────────────────────────────────────────────── -->
        <v-card variant="outlined" class="mb-4 pulse">
            <div class="pulse__grid">
                <button class="pulse__cell" @click="router.push({ name: 'banking-overview' })">
                    <div class="pulse__label">{{ label('AccountingView.cockpit.pulse.bank') }}</div>
                    <div class="pulse__value">{{ money(pulse.bank_balance) }}</div>
                    <div class="pulse__sub">{{ t('AccountingView.cockpit.pulse.bankAccounts', { count: pulse.bank_accounts || 0 }) }}</div>
                </button>
                <!-- Eigene Kachel, weil Bargeld eine eigene Kasse hat: der Bestand
                     wird gezählt und abgezeichnet, nicht von einer Bank bestätigt.
                     Ohne Kassenkonto im Kontenrahmen bleibt sie weg statt 0,00 zu zeigen. -->
                <button v-if="(pulse.cash_accounts || 0) > 0" class="pulse__cell"
                        @click="router.push({ name: 'kasse' })">
                    <div class="pulse__label">{{ label('AccountingView.cockpit.pulse.cash') }}</div>
                    <div class="pulse__value">{{ money(pulse.cash_balance) }}</div>
                    <div class="pulse__sub">{{ cashSubtitle }}</div>
                </button>
                <!-- Geld, das weder auf einem eingerichteten Bankkonto noch in einer
                     Kasse liegt: Zahlungsdienstleister, Verrechnungskonten. Früher
                     lief das stillschweigend unter „Bank" — was es nicht ist. Die
                     Kachel nennt die Konten deshalb beim Namen. -->
                <button v-if="(pulse.other_accounts || 0) > 0" class="pulse__cell"
                        @click="router.push({ name: 'accounting-reports' })">
                    <div class="pulse__label">{{ label('AccountingView.cockpit.pulse.other') }}</div>
                    <div class="pulse__value">{{ money(pulse.other_balance) }}</div>
                    <div class="pulse__sub">{{ otherSubtitle }}</div>
                </button>
                <button class="pulse__cell" @click="router.push({ name: 'accounting-open-items', query: { type: 'receivables' } })">
                    <div class="pulse__label">{{ label('AccountingView.cockpit.pulse.receivables') }}</div>
                    <div class="pulse__value" :class="{ 'text-error': (stacks.overdue?.count || 0) > 0 }">
                        {{ money(pulse.receivables_sum) }}
                    </div>
                    <div class="pulse__sub">
                        {{ t('AccountingView.cockpit.pulse.receivablesSub', {
                            count: pulse.receivables_count || 0, overdue: stacks.overdue?.count || 0 }) }}
                    </div>
                </button>
                <button class="pulse__cell" @click="router.push({ name: 'accounting-open-items', query: { type: 'payables' } })">
                    <div class="pulse__label">{{ label('AccountingView.cockpit.pulse.payables') }}</div>
                    <div class="pulse__value">{{ money(pulse.payables_sum) }}</div>
                    <div class="pulse__sub">
                        {{ pulse.payables_next_due
                            ? t('AccountingView.cockpit.pulse.payablesSub', { date: pulse.payables_next_due })
                            : t('AccountingView.cockpit.pulse.payablesNone') }}
                    </div>
                </button>
                <button class="pulse__cell" @click="router.push({ name: 'accounting-ustva' })">
                    <div class="pulse__label">{{ label('AccountingView.cockpit.pulse.vat', { period: vatPeriod?.label || '—' }) }}</div>
                    <div class="pulse__value">{{ money(Math.abs(vatPeriod?.payable || 0)) }}</div>
                    <div class="pulse__sub">{{ vatSubtitle }}</div>
                </button>
            </div>
        </v-card>

        <!-- ── Arbeitskacheln ────────────────────────────────────────────── -->
        <h2 class="text-subtitle-1 font-weight-medium mb-2">{{ label('AccountingView.cockpit.todoTitle') }}</h2>

        <div v-if="loading && !cockpit" class="d-flex ga-3 flex-wrap mb-4">
            <v-skeleton-loader v-for="n in 4" :key="n" type="card" width="240" />
        </div>

        <v-row v-else dense class="mb-1">
            <v-col v-for="tile in activeTiles" :key="tile.key" cols="12" sm="6" md="4" lg="3">
                <v-card :class="['tile', `tile--${tile.tone}`]" variant="outlined" hover @click="tile.action()">
                    <v-card-text class="pb-3">
                        <div class="d-flex align-center ga-2 tile__label">
                            <v-icon size="small" :color="tile.tone">{{ tile.icon }}</v-icon>
                            <span>{{ tile.label }}</span>
                        </div>
                        <div class="tile__number">{{ tile.value }}</div>
                        <div class="tile__sub">{{ tile.sub }}</div>
                        <v-progress-linear v-if="tile.progress !== undefined" :model-value="tile.progress"
                                           :color="tile.tone" height="4" rounded class="mt-2" />
                        <div class="tile__cta mt-2">
                            {{ tile.cta }} <v-icon size="14">mdi-arrow-right</v-icon>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Nichts zu tun: die ruhige Zeile statt leerer Kacheln -->
        <v-card v-if="quietTiles.length" variant="text" class="quiet mb-4">
            <div class="d-flex align-center flex-wrap ga-2 px-3 py-2">
                <v-icon size="small" color="success">mdi-check-circle-outline</v-icon>
                <span class="text-caption text-medium-emphasis">
                    {{ t('AccountingView.cockpit.nothingToDo') }}
                </span>
                <v-chip v-for="tile in quietTiles" :key="tile.key" size="x-small" variant="text"
                        class="px-1" @click="tile.action()">
                    {{ tile.label }}
                </v-chip>
            </div>
        </v-card>

        <!-- Automatik-Band: was ohne Rueckfrage gebucht wurde, bleibt sichtbar -->
        <v-alert v-if="auto.booked_week > 0" type="success" variant="tonal" density="comfortable"
                 icon="mdi-robot-outline" class="mb-4">
            <div class="d-flex align-center flex-wrap ga-2">
                <span>{{ t('AccountingView.cockpit.autoBooked', { count: auto.booked_week }) }}</span>
                <span v-if="auto.rules_count > 0" class="text-caption text-medium-emphasis">
                    {{ t('AccountingView.cockpit.autoRules', { count: auto.rules_count }) }}
                </span>
                <v-spacer />
                <v-btn size="small" variant="text" class="text-none"
                       :to="{ name: 'accounting-bookings', query: { status: 'booked' } }">
                    {{ t('AccountingView.cockpit.autoCheck') }}
                </v-btn>
            </div>
        </v-alert>

        <!-- ── Zeitstrahl ────────────────────────────────────────────────── -->
        <div class="d-flex align-center ga-2 mb-2">
            <h2 class="text-subtitle-1 font-weight-medium mb-0">
                {{ t('AccountingView.cockpit.yearTitle', { year: year }) }}
            </h2>
            <v-btn icon variant="text" size="x-small" :aria-label="t('AccountingView.cockpit.previousYear')"
                   @click="changeYear(-1)"><v-icon>mdi-chevron-left</v-icon></v-btn>
            <v-btn icon variant="text" size="x-small" :aria-label="t('AccountingView.cockpit.nextYear')"
                   @click="changeYear(1)"><v-icon>mdi-chevron-right</v-icon></v-btn>
        </div>
        <div class="timeline mb-2">
            <button v-for="month in timeline" :key="month.month"
                    class="timeline__cell" :class="`timeline__cell--${month.status}`"
                    :title="timelineTitle(month)"
                    @click="router.push({ name: 'accounting-ustva', query: { year, period: month.month } })">
                <span class="timeline__name">{{ month.short }}</span>
                <v-icon v-if="month.status === 'filed'" size="11">mdi-check</v-icon>
                <v-icon v-else-if="month.status === 'overdue'" size="11">mdi-alert</v-icon>
                <span v-else class="timeline__dot" />
            </button>
        </div>
        <div class="d-flex ga-4 flex-wrap text-caption text-medium-emphasis mb-6">
            <span><i class="legend legend--filed" />{{ t('AccountingView.cockpit.legendFiled') }}</span>
            <span><i class="legend legend--open" />{{ t('AccountingView.cockpit.legendOpen') }}</span>
            <span><i class="legend legend--overdue" />{{ t('AccountingView.cockpit.legendOverdue') }}</span>
        </div>

        <!-- ── Nachschlagen ──────────────────────────────────────────────── -->
        <!-- Sichtbar in beiden Modi: „Was steht auf welchem Konto?" ist keine
             Fachfrage, sondern die häufigste Frage überhaupt. -->
        <h2 class="text-subtitle-1 font-weight-medium mb-2">{{ t('AccountingView.cockpit.lookupTitle') }}</h2>
        <div class="d-flex flex-wrap ga-2 mb-6">
            <v-btn v-for="link in lookupLinks" :key="link.key" :to="link.to"
                   variant="tonal" size="small" class="text-none">
                <v-icon start size="small">{{ link.icon }}</v-icon>{{ link.title }}
            </v-btn>
        </div>

        <!-- ── Fachbereich (nur Profimodus) ──────────────────────────────── -->
        <template v-if="!easymode">
            <h2 class="text-subtitle-1 font-weight-medium mb-2">{{ t('AccountingView.cockpit.proTitle') }}</h2>
            <div class="d-flex flex-wrap ga-2 mb-6">
                <v-btn v-for="link in proLinks" :key="link.key" :to="link.to"
                       variant="outlined" size="small" class="text-none">
                    <v-icon start size="small">{{ link.icon }}</v-icon>{{ link.title }}
                </v-btn>
            </div>
        </template>

        <div class="text-caption text-medium-emphasis mb-4">
            <v-icon size="14" class="mr-1">mdi-tray-arrow-down</v-icon>
            {{ t('AccountingView.cockpit.dropHint') }}
        </div>
    </v-container>

    <!-- Ablegeflaeche fuer Belege: erscheint erst, wenn eine Datei ueber dem Fenster haengt -->
    <div v-if="dragging" class="dropzone" @dragover.prevent @dragleave="onDragLeave" @drop.prevent="onDrop">
        <div class="dropzone__inner">
            <v-icon size="56" color="primary">mdi-file-document-arrow-right-outline</v-icon>
            <div class="text-h6 mt-3">{{ t('AccountingView.cockpit.dropTitle') }}</div>
            <div class="text-body-2 text-medium-emphasis">{{ t('AccountingView.cockpit.dropSub') }}</div>
        </div>
    </div>

    <!-- Unstimmigkeiten: Rechnung und Kontrollkonto sagen Verschiedenes.
         Ein Klick auf die Zeile führt ins Kontoblatt — dort steht, welche
         Buchung die Differenz verursacht hat. -->
    <v-dialog v-model="checksDialog" max-width="1100" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center ga-2">
                <v-icon color="error">mdi-scale-balance</v-icon>
                {{ t('AccountingView.cockpit.checks.title') }}
                <v-spacer />
                <v-btn icon variant="text" size="small"
                       :aria-label="t('AccountingView.cockpit.checks.close')"
                       @click="checksDialog = false"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-card-subtitle class="pb-2">
                {{ t('AccountingView.cockpit.checks.info') }}
            </v-card-subtitle>
            <v-divider />
            <v-card-text>
                <v-skeleton-loader v-if="checksLoading" type="table" />
                <v-alert v-else-if="!checksList.length" type="success" variant="tonal"
                         density="comfortable" icon="mdi-check-circle-outline"
                         :text="t('AccountingView.cockpit.checks.none')" />
                <v-data-table v-else :headers="checksHeaders" :items="checksList"
                              density="compact" :items-per-page="25" hover
                              @click:row="(event, { item }) => openLedger(item)">
                    <template #item.reason="{ item }">
                        <v-chip size="x-small" variant="tonal"
                                :color="item.reason === 'paid_but_open' ? 'error' : 'warning'">
                            {{ t('AccountingView.cockpit.checks.reason.' + item.reason) }}
                        </v-chip>
                    </template>
                    <template #item.amount="{ item }">{{ money(item.amount) }}</template>
                    <template #item.paid="{ item }">{{ money(item.paid) }}</template>
                    <template #item.balance="{ item }">{{ money(item.balance) }}</template>
                    <template #item.difference="{ item }">
                        <span class="font-weight-bold text-error">{{ money(item.difference) }}</span>
                    </template>
                    <template #item.accno="{ item }">{{ item.accno }} {{ item.account_name }}</template>
                </v-data-table>
            </v-card-text>
            <v-divider />
            <v-card-actions class="px-4">
                <span class="text-caption text-medium-emphasis">
                    {{ t('AccountingView.cockpit.checks.summary', {
                        count: checksCount, amount: money(checksSum) }) }}
                </span>
                <v-spacer />
                <v-btn variant="text" class="text-none" @click="checksDialog = false">
                    {{ t('AccountingView.cockpit.checks.close') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-snackbar v-model="toast.show" :color="toast.color" timeout="5000" location="bottom">
        {{ toast.text }}
    </v-snackbar>

    <CommandPalette ref="paletteRef" />
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import CommandPalette from '../components/accounting.command-palette.vue'
import EasymodeSwitch from '../components/accounting.easymode-switch.vue'
import ConceptButton from '../components/accounting.concept-button.vue'
import { useCockpit } from '../composables/useCockpit.js'
import { useEasymode } from '../composables/useEasymode.js'
import { useInvoiceUpload } from '../composables/useInvoiceUpload.js'

const { t, locale } = useI18n()
const router = useRouter()
const { loading, cockpit, ustvaYear, fetchCockpit, fetchUstvaYear, fetchConsistency } = useCockpit()
const { easymode, label } = useEasymode()
const { uploadInvoice } = useInvoiceUpload()

const paletteRef = ref(null)
const year = ref(new Date().getFullYear())
const dragging = ref(false)
const toast = ref({ show: false, text: '', color: 'success' })

const pulse  = computed(() => cockpit.value?.pulse  || {})
const stacks = computed(() => cockpit.value?.stacks || {})
const auto   = computed(() => cockpit.value?.auto   || {})

const shortcutLabel = computed(() =>
    navigator.platform?.toLowerCase().includes('mac') ? '⌘K' : 'Strg+K'
)

const periodLabel = computed(() => cockpit.value?.periods?.current || '')

// Die massgebliche Voranmeldung ist die des Vormonats — sie ist abzugeben.
const vatPeriod = computed(() => {
    const months = ustvaYear.value?.months || []
    const previous = cockpit.value?.periods
    if (!months.length || !previous) return null
    if (previous.previous_year !== year.value) return months[months.length - 1] || null
    return months.find(month => month.month === previous.previous_month) || null
})

const cashSubtitle = computed(() => {
    const count = pulse.value.cash_accounts || 0
    return pulse.value.cash_last
        ? t('AccountingView.cockpit.pulse.cashSub', { count, date: pulse.value.cash_last })
        : t('AccountingView.cockpit.pulse.cashNone', { count })
})

// Bis zu drei Kontennamen liefert das Backend mit; sind es mehr, sagt der Rest
// der Zeile, wie viele noch dazugehören.
const otherSubtitle = computed(() => {
    const count = pulse.value.other_accounts || 0
    const names = pulse.value.other_names || ''
    const rest  = count - names.split(', ').filter(Boolean).length
    return rest > 0
        ? t('AccountingView.cockpit.pulse.otherSubMore', { names, count: rest })
        : t('AccountingView.cockpit.pulse.otherSub', { names })
})

const vatSubtitle = computed(() => {
    const period = vatPeriod.value
    if (!period) return ''
    if (period.filed_at) return t('AccountingView.cockpit.vatFiled')
    const days = daysUntil(period.due_date)
    if (days === null) return ''
    if (days < 0) return t('AccountingView.cockpit.vatOverdue', { days: Math.abs(days) })
    return t('AccountingView.cockpit.vatDue', { days })
})

const timeline = computed(() => {
    const months = ustvaYear.value?.months || []
    return months.map(month => ({
        ...month,
        short: new Date(year.value, month.month - 1, 1)
            .toLocaleDateString(locale.value, { month: 'short' })
    }))
})

/**
 * Die Kacheln. Jede kennt ihren Arbeitsvorrat; ist er leer, wandert sie in die
 * ruhige Zeile. Reihenfolge = Dringlichkeit, nicht Alphabet.
 */
const tiles = computed(() => {
    const documents = stacks.value.documents || {}
    const bank      = stacks.value.bank || {}
    const overdue   = stacks.value.overdue || {}
    const closing   = stacks.value.closing || {}
    const checks    = stacks.value.checks || {}
    const vat       = vatPeriod.value
    const vatDays   = vat && !vat.filed_at ? daysUntil(vat.due_date) : null

    return [
        {
            key: 'documents',
            active: (documents.count || 0) > 0,
            tone: 'primary',
            icon: 'mdi-file-document-multiple-outline',
            label: label('AccountingView.cockpit.tiles.documents'),
            value: documents.count || 0,
            sub: t('AccountingView.cockpit.tiles.documentsSub', {
                sure: documents.sure || 0, unclear: Math.max((documents.pending || 0) - (documents.sure || 0), 0)
            }),
            cta: label('AccountingView.cockpit.tiles.startRun'),
            action: () => router.push({ name: 'accounting-run', params: { kind: 'belege' } })
        },
        {
            key: 'bank',
            active: (bank.count || 0) > 0,
            tone: 'primary',
            icon: 'mdi-bank-transfer',
            label: label('AccountingView.cockpit.tiles.bank'),
            value: bank.count || 0,
            sub: t('AccountingView.cockpit.tiles.bankSub', {
                incoming: bank.incoming || 0, outgoing: bank.outgoing || 0
            }),
            cta: label('AccountingView.cockpit.tiles.startRun'),
            action: () => router.push({ name: 'accounting-run', params: { kind: 'bank' } })
        },
        {
            key: 'overdue',
            active: (overdue.count || 0) > 0,
            tone: 'error',
            icon: 'mdi-alarm-light-outline',
            label: label('AccountingView.cockpit.tiles.overdue'),
            value: overdue.count || 0,
            sub: t('AccountingView.cockpit.tiles.overdueSub', {
                amount: money(overdue.sum), days: overdue.days || 0
            }),
            cta: t('AccountingView.cockpit.tiles.showList'),
            action: () => router.push({ name: 'accounting-open-items', query: { type: 'receivables', overdue: '1' } })
        },
        {
            key: 'vat',
            active: !!vat && !vat.filed_at && vatDays !== null && vatDays <= 20,
            tone: vatDays !== null && vatDays < 0 ? 'error' : 'warning',
            icon: 'mdi-file-percent-outline',
            label: label('AccountingView.cockpit.tiles.vat', { period: vat?.label || '' }),
            value: vatDays === null ? '—'
                : vatDays < 0 ? t('AccountingView.cockpit.tiles.vatLate', { days: Math.abs(vatDays) })
                : t('AccountingView.cockpit.tiles.vatDays', { days: vatDays }),
            sub: t('AccountingView.cockpit.tiles.vatSub', { amount: money(Math.abs(vat?.payable || 0)) }),
            cta: t('AccountingView.cockpit.tiles.checkAndFile'),
            action: () => router.push({ name: 'accounting-ustva' })
        },
        {
            // Steht bewusst weit oben: eine Rechnung, die als bezahlt gilt,
            // deren Forderung aber offen steht, faellt sonst nirgends auf.
            key: 'checks',
            active: (checks.count || 0) > 0,
            tone: 'error',
            icon: 'mdi-scale-balance',
            label: label('AccountingView.cockpit.tiles.checks'),
            value: checks.count || 0,
            sub: t('AccountingView.cockpit.tiles.checksSub', { amount: money(checks.sum) }),
            cta: t('AccountingView.cockpit.tiles.showList'),
            action: () => openChecks()
        },
        {
            key: 'closing',
            active: (closing.percent ?? 100) < 100,
            tone: 'secondary',
            icon: 'mdi-calendar-check-outline',
            label: label('AccountingView.cockpit.tiles.closing', { period: cockpit.value?.periods?.previous || '' }),
            value: (closing.percent ?? 0) + ' %',
            sub: t('AccountingView.cockpit.tiles.closingSub', {
                bank: closing.bank_open || 0, docs: closing.book_open || 0
            }),
            progress: closing.percent ?? 0,
            cta: t('AccountingView.cockpit.tiles.checklist'),
            action: () => router.push({ name: 'accounting-run', params: { kind: 'bank' }, query: { period: 'previous' } })
        }
    ]
})

const activeTiles = computed(() => tiles.value.filter(tile => tile.active))
const quietTiles  = computed(() => tiles.value.filter(tile => !tile.active))

// Nachschlagewerk: die Auswertungen, die auch im einfachen Modus gebraucht
// werden. Bank und Kasse liegen im selben Hub, deshalb zwei Einträge auf
// dieselbe Seite mit unterschiedlichem Reiter.
const lookupLinks = computed(() => [
    { key: 'reports', icon: 'mdi-chart-box-outline', title: t('AccountingView.reports.title'),
      to: { name: 'accounting-reports' } },
    { key: 'ledger',  icon: 'mdi-file-table-outline', title: t('AccountingView.menu.accountLedger'),
      to: { name: 'accounting-account-ledger' } },
    { key: 'bank',    icon: 'mdi-bank-outline', title: t('BankingView.menu.title'),
      to: { name: 'banking-overview' } },
    { key: 'kasse',   icon: 'mdi-cash-register', title: t('KasseView.title'),
      to: { name: 'kasse' } },
    { key: 'belege',  icon: 'mdi-shield-check-outline', title: t('AccountingView.documentCheck.title'),
      to: { name: 'accounting-document-check' } }
])

const proLinks = computed(() => [
    { key: 'journal', icon: 'mdi-book-open-variant', title: t('AccountingView.bookings.modeJournal'), to: { name: 'accounting-bookings' } },
    { key: 'charts',  icon: 'mdi-format-list-numbered', title: t('AccountingView.menu.chartOfAccounts'), to: { name: 'accounting-chart-of-accounts' } },
    { key: 'manual',  icon: 'mdi-file-document-plus-outline', title: t('AccountingView.menu.invoiceManual'), to: { name: 'accounting-invoice-manual' } },
    { key: 'datev',   icon: 'mdi-file-export-outline', title: t('AccountingView.menu.datevExport'), to: { name: 'accounting-datev-export' } },
    { key: 'vendors', icon: 'mdi-truck-outline', title: t('AccountingView.menu.vendors'), to: { name: 'accounting-vendors' } },
    { key: 'customers', icon: 'mdi-account-group-outline', title: t('AccountingView.menu.customers'), to: { name: 'accounting-customers' } }
])

// ── Unstimmigkeiten ───────────────────────────────────────────────────────
const checksDialog  = ref(false)
const checksLoading = ref(false)
const checksList    = ref([])
const checksCount   = computed(() => stacks.value.checks?.count || 0)
const checksSum     = computed(() => stacks.value.checks?.sum || 0)

const checksHeaders = computed(() => [
    { title: t('AccountingView.cockpit.checks.date'),       key: 'transdate',  width: '110px' },
    { title: t('AccountingView.cockpit.checks.invoice'),    key: 'invnumber',  width: '110px' },
    { title: t('AccountingView.cockpit.checks.partner'),    key: 'partner' },
    { title: t('AccountingView.cockpit.checks.account'),    key: 'accno' },
    { title: t('AccountingView.cockpit.checks.amount'),     key: 'amount',     align: 'end' },
    { title: t('AccountingView.cockpit.checks.paid'),       key: 'paid',       align: 'end' },
    { title: t('AccountingView.cockpit.checks.balance'),    key: 'balance',    align: 'end' },
    { title: t('AccountingView.cockpit.checks.difference'), key: 'difference', align: 'end' },
    { title: t('AccountingView.cockpit.checks.reasonTitle'), key: 'reason',    width: '150px' }
])

async function openChecks() {
    checksDialog.value = true
    checksLoading.value = true
    const result = await fetchConsistency(200)
    checksList.value = result.items || []
    checksLoading.value = false
}

// Das Kontoblatt des Kontrollkontos ist der Ort, an dem die Differenz
// sichtbar wird — dorthin, nicht zur Rechnung, die ja richtig aussieht.
function openLedger(item) {
    checksDialog.value = false
    router.push({ name: 'accounting-account-ledger', query: { accno: item.accno } })
}

function money(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
}

function daysUntil(dateString) {
    if (!dateString) return null
    const target = new Date(dateString + 'T00:00:00')
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    return Math.round((target - today) / 86400000)
}

function timelineTitle(month) {
    const status = t('AccountingView.cockpit.status.' + month.status)
    return `${month.label} — ${status} · ${money(Math.abs(month.payable || 0))}`
}

function changeYear(step) {
    year.value += step
    fetchUstvaYear(year.value)
}

// ── Belege ueberall ablegen ───────────────────────────────────────────────
let dragCounter = 0

function onDragOver(event) {
    if (!event.dataTransfer?.types?.includes('Files')) return
    dragging.value = true
}

function onDragLeave() {
    dragging.value = false
}

function onWindowDragEnter(event) {
    if (!event.dataTransfer?.types?.includes('Files')) return
    dragCounter++
    dragging.value = true
}

function onWindowDragLeave() {
    dragCounter = Math.max(dragCounter - 1, 0)
    if (dragCounter === 0) dragging.value = false
}

async function onDrop(event) {
    dragging.value = false
    dragCounter = 0
    const files = Array.from(event.dataTransfer?.files || [])
    if (!files.length) return

    const allowed = ['application/pdf', 'image/jpeg', 'image/png']
    const accepted = files.filter(file => allowed.includes(file.type))
    if (!accepted.length) {
        toast.value = { show: true, color: 'error', text: t('AccountingView.cockpit.dropWrongType') }
        return
    }

    toast.value = { show: true, color: 'info', text: t('AccountingView.cockpit.dropWorking', { count: accepted.length }) }
    let ok = 0
    for (const file of accepted) {
        const result = await uploadInvoice(file)
        if (result) ok++
    }
    toast.value = ok
        ? { show: true, color: 'success', text: t('AccountingView.cockpit.dropDone', { count: ok }) }
        : { show: true, color: 'error', text: t('AccountingView.cockpit.dropFailed') }
    load()
}

async function load() {
    await Promise.all([fetchCockpit(), fetchUstvaYear(year.value)])
}

onMounted(() => {
    load()
    window.addEventListener('dragenter', onWindowDragEnter)
    window.addEventListener('dragleave', onWindowDragLeave)
})

onBeforeUnmount(() => {
    window.removeEventListener('dragenter', onWindowDragEnter)
    window.removeEventListener('dragleave', onWindowDragLeave)
})
</script>

<style scoped>
.cockpit { max-width: 1400px; }

kbd {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 3px;
    padding: 0 .3em;
    font-size: .78em;
}

/* ── Puls ─────────────────────────────────────────────────────────────── */
.pulse__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1px;
    background: rgba(var(--v-border-color), var(--v-border-opacity));
}
.pulse__cell {
    background: rgb(var(--v-theme-surface));
    padding: .7rem 1rem;
    text-align: left;
    cursor: pointer;
    transition: background .15s;
}
.pulse__cell:hover { background: rgba(var(--v-theme-on-surface), .04); }
.pulse__label { font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; opacity: .65; }
.pulse__value { font-size: 1.35rem; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.25; }
.pulse__sub   { font-size: .72rem; opacity: .6; }

/* ── Kacheln ──────────────────────────────────────────────────────────── */
.tile { cursor: pointer; border-left-width: 3px; height: 100%; }
.tile--primary   { border-left-color: rgb(var(--v-theme-primary)); }
.tile--error     { border-left-color: rgb(var(--v-theme-error)); }
.tile--warning   { border-left-color: rgb(var(--v-theme-warning)); }
.tile--secondary { border-left-color: rgb(var(--v-theme-secondary)); }
.tile__label  { font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; opacity: .7; }
.tile__number { font-size: 1.9rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; margin-top: .2rem; }
.tile__sub    { font-size: .76rem; opacity: .68; line-height: 1.45; }
.tile__cta    { font-size: .74rem; color: rgb(var(--v-theme-primary)); }

.quiet { border: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 4px; }

/* ── Zeitstrahl ───────────────────────────────────────────────────────── */
.timeline { display: grid; grid-template-columns: repeat(12, 1fr); gap: 3px; }
.timeline__cell {
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    padding: .35rem .1rem; border-radius: 3px; cursor: pointer;
    background: rgba(var(--v-theme-on-surface), .06);
    border: 1px solid transparent;
    font-size: .62rem;
}
.timeline__cell:hover { border-color: rgb(var(--v-theme-primary)); }
.timeline__name { text-transform: uppercase; letter-spacing: .04em; }
.timeline__dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; opacity: .35; }
.timeline__cell--filed   { background: rgba(var(--v-theme-success), .18); color: rgb(var(--v-theme-success)); }
.timeline__cell--overdue { background: rgba(var(--v-theme-error), .18);   color: rgb(var(--v-theme-error)); }
.timeline__cell--current { background: rgba(var(--v-theme-warning), .18); color: rgb(var(--v-theme-warning)); font-weight: 600; }
.timeline__cell--future  { opacity: .45; }

.legend { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: .35rem; vertical-align: middle; }
.legend--filed   { background: rgba(var(--v-theme-success), .55); }
.legend--open    { background: rgba(var(--v-theme-on-surface), .2); }
.legend--overdue { background: rgba(var(--v-theme-error), .55); }

/* ── Ablegeflaeche ────────────────────────────────────────────────────── */
.dropzone {
    position: fixed; inset: 0; z-index: 2400;
    background: rgba(var(--v-theme-surface), .92);
    display: flex; align-items: center; justify-content: center;
    border: 3px dashed rgb(var(--v-theme-primary));
}
.dropzone__inner { text-align: center; pointer-events: none; }
</style>
