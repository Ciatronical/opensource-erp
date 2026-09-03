<!-- src/features/accounting/components/accounting.command-palette.vue -->
<!--
    Befehlspalette der Buchhaltung (Strg+K).

    Sie ersetzt das frühere Untermenü: statt dreizehn Eintraegen gibt es einen
    Menuepunkt und diese Eingabe. Ohne Suchbegriff stehen die haeufigen Ziele
    da; ab zwei Zeichen kommen Konten, Kunden, Lieferanten und Belegnummern aus
    der Datenbank dazu. Fachliche Ziele (Kontenrahmen, DATEV, Journal) sind im
    einfachen Modus nicht in der Vorschlagsliste, ueber die Suche aber jederzeit
    erreichbar — verborgen ist nichts.
-->
<template>
    <v-dialog v-model="open" max-width="680" scrollable :scrim="true" transition="fade-transition">
        <v-card class="palette">
            <v-text-field
                ref="inputRef"
                v-model="query"
                :placeholder="t('AccountingView.palette.placeholder')"
                variant="solo"
                flat
                hide-details
                autofocus
                prepend-inner-icon="mdi-magnify"
                density="comfortable"
                class="palette__input"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="choose(flat[cursor])"
                @keydown.esc="open = false"
            >
                <template #append-inner>
                    <v-progress-circular v-if="searching" indeterminate size="18" width="2" />
                </template>
            </v-text-field>

            <v-divider />

            <v-card-text class="pa-0 palette__list">
                <template v-for="group in groups" :key="group.key">
                    <v-list-subheader class="text-caption font-weight-bold">
                        {{ group.label }}
                    </v-list-subheader>
                    <v-list density="compact" class="py-0">
                        <v-list-item
                            v-for="entry in group.items"
                            :key="entry._i"
                            :active="entry._i === cursor"
                            @click="choose(entry)"
                            @mousemove="cursor = entry._i"
                        >
                            <template #prepend>
                                <v-icon size="small" :color="entry.color || 'medium-emphasis'">{{ entry.icon }}</v-icon>
                            </template>
                            <v-list-item-title class="text-body-2">{{ entry.title }}</v-list-item-title>
                            <v-list-item-subtitle v-if="entry.subtitle" class="text-caption">
                                {{ entry.subtitle }}
                            </v-list-item-subtitle>
                            <template #append>
                                <v-chip v-if="entry.pro && easymode" size="x-small" variant="tonal" class="ml-2">
                                    {{ t('AccountingView.easymode.pro') }}
                                </v-chip>
                            </template>
                        </v-list-item>
                    </v-list>
                </template>

                <div v-if="!flat.length" class="pa-6 text-center text-medium-emphasis text-body-2">
                    {{ query.length >= 2 ? t('AccountingView.palette.noResults') : t('AccountingView.palette.hint') }}
                </div>
            </v-card-text>

            <v-divider />
            <div class="d-flex align-center ga-3 px-4 py-2 text-caption text-medium-emphasis flex-wrap">
                <span><kbd>↑</kbd> <kbd>↓</kbd> {{ t('AccountingView.palette.keyMove') }}</span>
                <span><kbd>⏎</kbd> {{ t('AccountingView.palette.keyOpen') }}</span>
                <span><kbd>Esc</kbd> {{ t('AccountingView.palette.keyClose') }}</span>
            </div>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useCockpit } from '../composables/useCockpit.js'
import { useEasymode } from '../composables/useEasymode.js'
import { entityRoute } from '@/core/constants/routes.js'

const { t } = useI18n()
const router = useRouter()
const { searchTargets } = useCockpit()
const { easymode } = useEasymode()

const open = ref(false)
const query = ref('')
const cursor = ref(0)
const searching = ref(false)
const remote = ref([])
const inputRef = ref(null)

// Feste Ziele. `pro` markiert die fachlichen — sie stehen im einfachen Modus
// nicht in der Vorschlagsliste, werden ueber die Suche aber gefunden.
const targets = computed(() => [
    { key: 'run-documents', icon: 'mdi-file-document-multiple-outline', color: 'primary',
      title: t('AccountingView.cockpit.tiles.documents'), subtitle: t('AccountingView.palette.runHint'),
      to: { name: 'accounting-run', params: { kind: 'belege' } } },
    { key: 'run-bank', icon: 'mdi-bank-transfer', color: 'primary',
      title: t('AccountingView.cockpit.tiles.bank'), subtitle: t('AccountingView.palette.runHint'),
      to: { name: 'accounting-run', params: { kind: 'bank' } } },
    { key: 'overdue', icon: 'mdi-alarm-light-outline', color: 'error',
      title: t('AccountingView.cockpit.tiles.overdue'), to: { name: 'accounting-open-items', query: { type: 'receivables' } } },
    { key: 'payables', icon: 'mdi-cash-clock',
      title: t('AccountingView.cockpit.pulse.payables'), to: { name: 'accounting-open-items', query: { type: 'payables' } } },
    { key: 'ustva', icon: 'mdi-file-percent-outline',
      title: t('AccountingView.menu.ustva'), to: { name: 'accounting-ustva' } },
    { key: 'manual', icon: 'mdi-file-document-plus-outline',
      title: t('AccountingView.menu.invoiceManual'), to: { name: 'accounting-invoice-manual' } },
    { key: 'reports', icon: 'mdi-chart-box-outline',
      title: t('AccountingView.reports.title'), subtitle: t('AccountingView.palette.reportsHint'),
      to: { name: 'accounting-reports' } },
    { key: 'ledger', icon: 'mdi-file-table-outline',
      title: t('AccountingView.menu.accountLedger'), to: { name: 'accounting-account-ledger' } },
    { key: 'banking', icon: 'mdi-bank', title: t('BankingView.menu.title'), to: { name: 'banking-overview' } },
    { key: 'kasse', icon: 'mdi-cash-register', title: t('KasseView.title'), to: { name: 'kasse' } },
    { key: 'belegcheck', icon: 'mdi-shield-check-outline',
      title: t('AccountingView.documentCheck.title'), subtitle: t('AccountingView.palette.checkHint'),
      to: { name: 'accounting-document-check' } },
    { key: 'journal', icon: 'mdi-book-open-variant', pro: true,
      title: t('AccountingView.bookings.modeJournal'), to: { name: 'accounting-bookings' } },
    { key: 'charts', icon: 'mdi-format-list-numbered', pro: true,
      title: t('AccountingView.menu.chartOfAccounts'), to: { name: 'accounting-chart-of-accounts' } },
    { key: 'datev', icon: 'mdi-file-export-outline', pro: true,
      title: t('AccountingView.menu.datevExport'), to: { name: 'accounting-datev-export' } },
    { key: 'vendors', icon: 'mdi-truck-outline', pro: true,
      title: t('AccountingView.menu.vendors'), to: { name: 'accounting-vendors' } },
    { key: 'customers', icon: 'mdi-account-group-outline', pro: true,
      title: t('AccountingView.menu.customers'), to: { name: 'accounting-customers' } },
])

const matchedTargets = computed(() => {
    const q = query.value.trim().toLowerCase()
    if (!q) return targets.value.filter(item => !(item.pro && easymode.value))
    return targets.value.filter(item =>
        item.title.toLowerCase().includes(q) || (item.subtitle || '').toLowerCase().includes(q)
    )
})

const remoteIcons = {
    account:  'mdi-tag-outline',
    customer: 'mdi-account-outline',
    vendor:   'mdi-truck-outline',
    ar:       'mdi-receipt-text-outline',
    ap:       'mdi-receipt-text-arrow-left-outline'
}

const remoteEntries = computed(() => remote.value.map(row => ({
    key: row.kind + row.ref,
    icon: remoteIcons[row.kind] || 'mdi-circle-small',
    title: row.kind === 'account' ? `${row.label} · ${row.code}` : (row.code ? `${row.label} · ${row.code}` : row.label),
    // Bei Konten steht in extra die Kontenkategorie ("E", "A", …) — die sagt
    // im Suchergebnis nichts aus und bleibt deshalb weg.
    subtitle: row.kind === 'account' ? '' : (row.extra || ''),
    remote: row
})))

const groups = computed(() => {
    const result = []
    if (matchedTargets.value.length) {
        result.push({ key: 'targets', label: t('AccountingView.palette.groupGoto'), items: matchedTargets.value })
    }
    if (remoteEntries.value.length) {
        result.push({ key: 'remote', label: t('AccountingView.palette.groupData'), items: remoteEntries.value })
    }
    // Fortlaufender Index ueber alle Gruppen — die Pfeiltasten laufen durch.
    let i = 0
    for (const group of result) for (const item of group.items) item._i = i++
    return result
})

const flat = computed(() => groups.value.flatMap(group => group.items))

let timer = null
watch(query, value => {
    cursor.value = 0
    clearTimeout(timer)
    if (value.trim().length < 2) { remote.value = []; searching.value = false; return }
    searching.value = true
    timer = setTimeout(async () => {
        remote.value = await searchTargets(value.trim())
        searching.value = false
    }, 220)
})

function move(step) {
    if (!flat.value.length) return
    cursor.value = (cursor.value + step + flat.value.length) % flat.value.length
}

function choose(entry) {
    if (!entry) return
    open.value = false
    if (entry.to) { router.push(entry.to); return }

    const row = entry.remote
    if (!row) return
    if (row.kind === 'account') {
        router.push({ name: 'accounting-account-ledger', query: { accno: row.code } })
    } else if (row.kind === 'customer' || row.kind === 'vendor') {
        const route = entityRoute(row.kind, row.ref)
        if (route) router.push(route)
    } else {
        router.push({ name: 'accounting-bookings', query: { src: row.kind, id: row.ref } })
    }
}

function onKeydown(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        open.value = !open.value
    }
}

watch(open, async value => {
    if (value) {
        query.value = ''
        remote.value = []
        cursor.value = 0
        await nextTick()
        inputRef.value?.focus?.()
    }
})

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => { window.removeEventListener('keydown', onKeydown); clearTimeout(timer) })

defineExpose({ openPalette: () => { open.value = true } })
</script>

<style scoped>
.palette__input :deep(input) { font-size: 1.05rem; }
.palette__list { max-height: 52vh; }
kbd {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-bottom-width: 2px;
    border-radius: 3px;
    padding: 0 .3em;
    font-size: .9em;
}
</style>
