<!-- src/features/accounting/views/accounting.magic.vue -->
<!--
    Magisch Buchen: Belege, die die Belegsuche aus Postfach, WhatsApp und
    Ordnern geholt hat, liegen hier als Vorschlag. Alles, was sauber erkannt
    wurde (Kreditor, Konto, Betrag, Rechnungsnummer), ist vorbelegt — ein Klick
    bucht den ganzen Stapel. Unsicheres ist abgewählt und nennt den Grund.
-->
<template>
    <NavbarView />
    <v-container fluid class="magic">
        <div class="d-flex align-center flex-wrap ga-3 mb-4">
            <div>
                <h1 class="text-h5 font-weight-bold d-flex align-center ga-2">
                    <v-icon color="primary">mdi-auto-fix</v-icon>
                    {{ t('AccountingView.magic.title') }}
                </h1>
                <div class="text-body-2 text-medium-emphasis">
                    {{ lastRun ? t('AccountingView.magic.lastRun', { when: formatDateTime(lastRun) }) : t('AccountingView.magic.neverRun') }}
                    <span v-if="!enabled" class="text-warning"> · {{ t('AccountingView.magic.scheduleOff') }}</span>
                </div>
            </div>
            <v-spacer />
            <v-btn variant="tonal" prepend-icon="mdi-cog-outline" @click="router.push({ name: 'client-defaults' })">
                {{ t('AccountingView.magic.sources') }}
            </v-btn>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-magnify-scan" :loading="searching" @click="searchNow">
                {{ t('AccountingView.magic.searchNow') }}
            </v-btn>
        </div>

        <!-- Ergebnis der letzten Suche in dieser Sitzung -->
        <v-alert v-if="searchResult" :type="searchResult.imported > 0 ? 'success' : 'info'" variant="tonal" density="compact" class="mb-4" closable @click:close="searchResult = null">
            <div class="font-weight-medium">{{ t('AccountingView.magic.searchDone', { imported: searchResult.imported }) }}</div>
            <div v-for="s in searchResult.sources" :key="s.id" class="text-caption">
                <v-icon size="x-small" class="mr-1">{{ sourceIcon(s.type) }}</v-icon>
                {{ s.name }}: {{ t('AccountingView.magic.sourceStats', { imported: s.imported, duplicate: s.duplicate, not_invoice: s.not_invoice, error: s.error }) }}
                <span v-if="s.message" :class="s.status === 'error' ? 'text-error' : 'text-medium-emphasis'"> — {{ s.message }}</span>
            </div>
        </v-alert>

        <!-- Buchungsergebnis -->
        <v-alert v-if="bookResult" :type="bookResult.failed.length ? 'warning' : 'success'" variant="tonal" density="compact" class="mb-4" closable @click:close="bookResult = null">
            <div class="font-weight-medium">{{ t('AccountingView.magic.booked', { count: bookResult.booked }) }}</div>
            <div v-for="r in bookResult.failed" :key="r.booking_id" class="text-caption text-error">
                {{ r.vendor_name }} {{ r.invoice_number }}: {{ r.error }}
            </div>
        </v-alert>

        <v-card rounded="lg" elevation="0" border :loading="loading">
            <v-card-title class="d-flex align-center py-3">
                <v-checkbox-btn
                    :model-value="allAutoSelected"
                    :indeterminate="selected.length > 0 && !allAutoSelected"
                    density="compact"
                    class="mr-2"
                    @update:model-value="toggleAll"
                />
                {{ t('AccountingView.magic.proposals', { count: items.length }) }}
                <v-spacer />
                <v-chip v-if="selectedSum > 0" size="small" variant="tonal" color="primary" class="mr-3">
                    {{ t('AccountingView.magic.selectedSum', { count: selected.length, sum: money(selectedSum) }) }}
                </v-chip>
                <v-btn color="success" variant="elevated" prepend-icon="mdi-auto-fix" :disabled="selected.length === 0" :loading="booking" @click="bookSelected">
                    {{ t('AccountingView.magic.bookSelected', { count: selected.length }) }}
                </v-btn>
            </v-card-title>
            <v-divider />

            <div v-if="!loading && items.length === 0" class="text-center pa-10 text-medium-emphasis">
                <v-icon size="48" class="mb-2">mdi-check-decagram-outline</v-icon>
                <div>{{ t('AccountingView.magic.empty') }}</div>
            </div>

            <v-table v-else density="comfortable" class="magic__table">
                <thead>
                    <tr>
                        <th style="width:44px"></th>
                        <th>{{ t('AccountingView.magic.colSource') }}</th>
                        <th>{{ t('AccountingView.magic.colVendor') }}</th>
                        <th>{{ t('AccountingView.magic.colInvoice') }}</th>
                        <th class="text-right">{{ t('AccountingView.magic.colAmount') }}</th>
                        <th>{{ t('AccountingView.magic.colAccount') }}</th>
                        <th>{{ t('AccountingView.magic.colStatus') }}</th>
                        <th style="width:48px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="it in items" :key="it.id" :class="{ 'magic__row--off': !selected.includes(it.id) }">
                        <td>
                            <v-checkbox-btn v-model="selected" :value="it.id" density="compact" :disabled="it.reasons.includes('already_posted')" />
                        </td>
                        <td>
                            <div class="d-flex align-center text-no-wrap">
                                <v-icon size="small" class="mr-1">{{ sourceIcon(it.source_type) }}</v-icon>
                                <span class="text-truncate magic__origin">{{ it.source_name || t('AccountingView.magic.sourceUpload') }}</span>
                            </div>
                            <div class="text-caption text-medium-emphasis text-truncate magic__origin" :title="(it.origin_info?.from || '') + ' ' + (it.origin_info?.subject || '')">
                                {{ formatDate(it.found_at) }}<template v-if="it.origin_info?.subject || it.origin_info?.from"> · {{ it.origin_info.subject || it.origin_info.from }}</template>
                            </div>
                        </td>
                        <td>
                            <div class="font-weight-medium">{{ it.vendor_name || '—' }}</div>
                            <div class="text-caption text-medium-emphasis text-truncate magic__origin">{{ it.description }}</div>
                        </td>
                        <td class="text-no-wrap">
                            <div>{{ it.invoice_number || '—' }}</div>
                            <div class="text-caption text-medium-emphasis">{{ formatDate(it.invoice_date) }}</div>
                        </td>
                        <td class="text-right text-no-wrap font-weight-medium">{{ money(it.amount) }}</td>
                        <td class="text-no-wrap">
                            <span v-if="it.debit_account">{{ it.debit_account }}</span>
                            <span class="text-caption text-medium-emphasis ml-1">{{ it.debit_name }}</span>
                        </td>
                        <td>
                            <v-chip v-if="it.auto" size="small" color="success" variant="tonal" prepend-icon="mdi-check">
                                {{ t('AccountingView.magic.ready') }} · {{ Math.round((it.ai_confidence || 0) * 100) }}%
                            </v-chip>
                            <v-chip v-for="r in it.reasons" :key="r" size="small" color="warning" variant="tonal" class="mr-1" prepend-icon="mdi-alert-circle-outline">
                                {{ t('AccountingView.magic.reason_' + r) }}
                            </v-chip>
                        </td>
                        <td>
                            <div class="d-flex ga-1">
                                <v-btn icon="mdi-file-pdf-box" size="small" variant="text" :title="t('AccountingView.magic.openDocument')" @click="openDocument(it)" />
                                <v-btn icon="mdi-pencil-outline" size="small" variant="text" :title="t('AccountingView.magic.edit')" @click="router.push({ name: 'accounting-run', params: { kind: 'belege' } })" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card>

        <!-- Übersprungenes (kein Beleg, Duplikat, Fehler) -->
        <v-expansion-panels v-if="skipped.length > 0" class="mt-4" variant="accordion">
            <v-expansion-panel>
                <v-expansion-panel-title>
                    <v-icon start size="small">mdi-eye-off-outline</v-icon>
                    {{ t('AccountingView.magic.skipped', { count: skipped.length }) }}
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <v-table density="compact" class="text-caption">
                        <tbody>
                            <tr v-for="s in skipped" :key="s.id">
                                <td class="text-no-wrap">{{ formatDate(s.itime) }}</td>
                                <td><v-icon size="x-small" class="mr-1">{{ sourceIcon(s.source_type) }}</v-icon>{{ s.source_name }}</td>
                                <td>{{ s.filename }}<span v-if="s.info?.subject" class="text-medium-emphasis"> · {{ s.info.subject }}</span></td>
                                <td><v-chip size="x-small" :color="s.result === 'error' ? 'error' : 'default'" variant="tonal">{{ t('AccountingView.magic.result_' + s.result) }}</v-chip></td>
                                <td class="text-medium-emphasis">{{ s.message }}</td>
                                <td>
                                    <v-btn v-if="s.document_id" icon="mdi-file-pdf-box" size="x-small" variant="text" @click="openDocument({ document_id: s.document_id })" />
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useMagicBooking } from '../composables/useMagicBooking.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const router = useRouter()
const magic = useMagicBooking()

const loading = ref(false)
const searching = ref(false)
const booking = ref(false)
const items = ref([])
const skipped = ref([])
const selected = ref([])
const lastRun = ref(null)
const enabled = ref(false)
const searchResult = ref(null)
const bookResult = ref(null)

const autoIds = computed(() => items.value.filter(i => i.auto).map(i => i.id))
const allAutoSelected = computed(() => autoIds.value.length > 0 && autoIds.value.every(id => selected.value.includes(id)))
const selectedSum = computed(() => items.value.filter(i => selected.value.includes(i.id)).reduce((s, i) => s + Number(i.amount || 0), 0))

async function load() {
    loading.value = true
    try {
        const p = await magic.fetchProposals()
        items.value = p.items || []
        skipped.value = p.skipped || []
        lastRun.value = p.last_run
        enabled.value = !!p.enabled
        // Vorbelegung: alles, was ohne Nacharbeit buchbar ist
        selected.value = autoIds.value.slice()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        loading.value = false
    }
}

function toggleAll(v) {
    selected.value = v ? autoIds.value.slice() : []
}

async function searchNow() {
    searching.value = true
    try {
        searchResult.value = await magic.runSearch()
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        searching.value = false
    }
}

async function bookSelected() {
    if (selected.value.length === 0) return
    booking.value = true
    try {
        const r = await magic.bookProposals(selected.value)
        bookResult.value = { booked: r.booked, failed: (r.results || []).filter(x => !x.ok) }
        if (r.booked > 0) alerts.success(t('AccountingView.magic.booked', { count: r.booked }))
        await load()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        booking.value = false
    }
}

function openDocument(it) {
    if (!it.document_id) return
    window.open(magic.documentPdfUrl(it.document_id), '_blank')
}

function sourceIcon(type) {
    return { imap: 'mdi-email-outline', whatsapp: 'mdi-whatsapp', folder: 'mdi-folder-outline', portal: 'mdi-web' }[type] || 'mdi-upload-outline'
}
function money(v) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v) || 0)
}
function formatDate(d) {
    if (!d) return '—'
    const x = new Date(String(d).replace(' ', 'T'))
    return isNaN(x.getTime()) ? String(d) : x.toLocaleDateString('de-DE')
}
function formatDateTime(d) {
    if (!d) return '—'
    const x = new Date(String(d).replace(' ', 'T'))
    return isNaN(x.getTime()) ? String(d) : x.toLocaleDateString('de-DE') + ' ' + x.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
}

onMounted(load)
</script>

<style scoped>
.magic__row--off td { opacity: 0.6; }
.magic__origin { max-width: 220px; }
.magic__table :deep(td) { vertical-align: middle; }
</style>
