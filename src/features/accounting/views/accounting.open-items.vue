<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-2">{{ title }}</h1>
                <v-alert type="info" variant="tonal" density="comfortable" icon="mdi-information-outline"
                         class="mt-1 mb-2" :text="t('AccountingView.openItems.info')" />
            </v-col>
        </v-row>

        <!-- Summen -->
        <v-row class="mt-0">
            <v-col cols="12" sm="4">
                <v-card variant="tonal">
                    <v-card-text>
                        <div class="text-caption text-medium-emphasis">{{ t('AccountingView.openItems.count') }}</div>
                        <div class="text-h5 font-weight-bold">{{ stats.count }}</div>
                        <div v-if="isFiltered" class="text-caption text-medium-emphasis">
                            {{ t('AccountingView.openItems.shownCount', { count: stats.shownCount }) }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card variant="tonal" :color="isReceivable ? 'error' : 'deep-orange'">
                    <v-card-text>
                        <div class="text-caption text-medium-emphasis">{{ t('AccountingView.openItems.total') }}</div>
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(stats.total) }}</div>
                        <div v-if="isFiltered" class="text-caption text-medium-emphasis">
                            {{ t('AccountingView.openItems.shownTotal', { value: formatCurrency(stats.shownTotal) }) }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card variant="tonal" color="warning">
                    <v-card-text>
                        <div class="text-caption text-medium-emphasis">{{ t('AccountingView.openItems.overdue') }}</div>
                        <div class="text-h5 font-weight-bold">{{ formatCurrency(stats.overdue) }}</div>
                        <div v-if="isFiltered" class="text-caption text-medium-emphasis">
                            {{ t('AccountingView.openItems.shownTotal', { value: formatCurrency(stats.shownOverdue) }) }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Filterschalter -->
        <v-row class="mt-1">
            <v-col cols="12" class="d-flex align-center flex-wrap" style="gap: 4px 28px;">
                <v-switch v-model="onlyCurrentYear" color="primary" density="compact" hide-details inset
                          :label="t('AccountingView.openItems.onlyCurrentYear', { year: currentYear })" />
                <v-switch v-model="hideSmall" color="primary" density="compact" hide-details inset
                          :label="t('AccountingView.openItems.hideSmall')" />
                <span v-if="stats.hidden > 0" class="text-caption text-medium-emphasis">
                    {{ t('AccountingView.openItems.hiddenCount', { count: stats.hidden }) }}
                </span>
            </v-col>
        </v-row>

        <!-- Liste -->
        <v-row class="mt-0">
            <v-col cols="12">
                <v-data-table
                    :headers="headers"
                    :items="visibleItems"
                    :loading="loading"
                    density="compact"
                    :items-per-page="50"
                    hover
                    :no-data-text="t('AccountingView.openItems.empty')"
                    @click:row="(_, { item }) => open(item)"
                >
                    <template #item.invnumber="{ item }">
                        <a class="text-primary text-decoration-none font-weight-medium" @click.stop="open(item)">
                            {{ item.invnumber || '—' }}
                        </a>
                    </template>
                    <template #item.amount="{ item }">
                        {{ formatCurrency(item.amount) }}
                    </template>
                    <template #item.open_amount="{ item }">
                        <span class="font-weight-medium" :class="isReceivable ? 'text-error' : 'text-deep-orange'">
                            {{ formatCurrency(item.open_amount) }}
                        </span>
                    </template>
                    <template #item.status="{ item }">
                        <v-chip v-if="Number(item.days_overdue) > 0" color="error" size="x-small" variant="flat">
                            {{ t('AccountingView.openItems.daysOverdue', { days: item.days_overdue }) }}
                        </v-chip>
                        <v-chip v-else color="success" size="x-small" variant="tonal">
                            {{ t('AccountingView.openItems.onTime') }}
                        </v-chip>
                    </template>
                    <template #item.actions="{ item }">
                        <v-icon size="small" color="primary">{{ isReceivable ? 'mdi-file-document-outline' : 'mdi-book-open-variant' }}</v-icon>
                    </template>
                </v-data-table>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useAccounting } from '../composables/useAccounting.js'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const { loading, fetchOpenItems } = useAccounting()

const items = ref([])
const hideSmall = ref(true)         // Cent-/Rundungsreste standardmäßig ausblenden
const SMALL = 1                     // Schwelle in Euro
const onlyCurrentYear = ref(true)   // nur laufendes Jahr (Vorjahre = Eröffnungsvortrag, nicht im Hauptbuch)
const currentYear = new Date().getFullYear()

const type = computed(() => (route.query.type === 'payables' ? 'payables' : 'receivables'))
const isReceivable = computed(() => type.value === 'receivables')
const title = computed(() => isReceivable.value
    ? t('AccountingView.openItems.titleReceivables')
    : t('AccountingView.openItems.titlePayables'))

// Filter: nur laufendes Jahr (Default) und Kleinbeträge < 1 € (Rundungsreste) ausblenden
const visibleItems = computed(() => items.value.filter(i =>
    (!hideSmall.value || Number(i.open_amount) >= SMALL) &&
    (!onlyCurrentYear.value || Number(i.year) === currentYear)
))

// Die Kacheln zeigen IMMER den vollen Bestand — dieselbe Zahl wie die Kachel
// auf der Übersicht. Würden sie der gefilterten Liste folgen, stünde für
// „Offene Forderungen" auf zwei Seiten ein unterschiedlicher Betrag.
// Wie viel die Filter ausblenden, steht als Zusatzzeile darunter.
function sum(rows, onlyOverdue = false) {
    return rows.reduce((a, i) =>
        a + ((!onlyOverdue || Number(i.days_overdue) > 0) ? Number(i.open_amount || 0) : 0), 0)
}

const stats = computed(() => {
    const all = items.value
    const v   = visibleItems.value
    return {
        count:          all.length,
        total:          sum(all),
        overdue:        sum(all, true),
        hidden:         all.length - v.length,
        shownCount:     v.length,
        shownTotal:     sum(v),
        shownOverdue:   sum(v, true),
    }
})

// Blenden die Filter gerade etwas aus?
const isFiltered = computed(() => stats.value.hidden > 0)

const headers = computed(() => [
    { title: t('AccountingView.bookings.reference'), key: 'invnumber', width: '130px' },
    { title: isReceivable.value ? t('AccountingView.bookings.customer') : t('AccountingView.bookings.vendor'), key: 'partner' },
    { title: t('AccountingView.bookings.date'), key: 'transdate_fmt', width: '110px' },
    { title: t('AccountingView.openItems.dueDate'), key: 'duedate_fmt', width: '110px' },
    { title: t('AccountingView.bookings.amount'), key: 'amount', align: 'end', width: '120px' },
    { title: t('AccountingView.openItems.openAmount'), key: 'open_amount', align: 'end', width: '120px' },
    { title: t('AccountingView.bookings.status'), key: 'status', width: '140px' },
    { title: '', key: 'actions', width: '48px', sortable: false, align: 'center' }
])

// Forderung → echte Rechnung öffnen; Verbindlichkeit → Buchung im Journal
function open(item) {
    if (isReceivable.value) {
        router.push({ name: 'faktura-invoice-view', params: { id: item.id } })
    } else {
        router.push({ name: 'accounting-bookings', query: { src: 'ap', id: item.id } })
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

async function load() {
    const payload = await fetchOpenItems(type.value)
    items.value = payload.items || []
}

watch(type, load)
onMounted(load)
</script>
