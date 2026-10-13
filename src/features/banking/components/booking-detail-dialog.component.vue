<template>
    <v-dialog v-model="show" max-width="860" scrollable @after-leave="reset">
        <v-card rounded="lg">
            <v-card-title class="d-flex align-center py-3">
                <v-icon start>mdi-book-open-page-variant-outline</v-icon>
                {{ t('BankingView.bookingDetail.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" @click="show = false" />
            </v-card-title>
            <v-card-subtitle v-if="transaction" class="pb-2">
                <span :class="Number(transaction.amount) < 0 ? 'text-error' : 'text-success'" class="font-weight-semibold">{{ formatCurrency(transaction.amount) }}</span>
                · {{ transaction.remote_name || '—' }} · {{ formatDateShort(transaction.transdate) }}
                <div v-if="transaction.purpose" class="text-caption text-medium-emphasis text-truncate">{{ transaction.purpose }}</div>
            </v-card-subtitle>

            <v-card-text>
                <v-progress-linear v-if="loading" indeterminate class="mb-4" />
                <v-alert v-else-if="!bookings.length" type="info" variant="tonal" density="compact">
                    {{ t('BankingView.bookingDetail.none') }}
                </v-alert>

                <div v-for="(b, idx) in bookings" :key="b.head.id" :class="{ 'mt-6': idx > 0 }">
                    <!-- Kopf -->
                    <div class="d-flex align-center flex-wrap ga-2 mb-2">
                        <v-chip size="small" variant="tonal" :color="typeColor(b.head.type)" :prepend-icon="typeIcon(b.head.type)">
                            {{ t('BankingView.bookingDetail.type_' + b.head.type) }}
                        </v-chip>
                        <span class="font-weight-semibold">{{ b.head.reference || '—' }}</span>
                        <span class="text-medium-emphasis">· {{ formatDateShort(b.head.transdate) }}</span>
                        <span v-if="b.head.partner_name" class="text-medium-emphasis">· {{ b.head.partner_name }}</span>
                        <v-chip v-if="b.head.storno" size="x-small" color="error" variant="flat">Storno</v-chip>
                        <v-spacer />
                        <span v-if="b.head.amount != null" class="text-caption text-medium-emphasis">
                            {{ t('BankingView.bookingDetail.invoiceAmount') }} {{ formatCurrency(b.head.amount) }}
                            · {{ t('BankingView.bookingDetail.paid') }} {{ formatCurrency(b.head.paid) }}
                        </span>
                    </div>
                    <div v-if="b.head.description" class="text-body-2 mb-3">{{ b.head.description }}</div>
                    <v-alert v-if="b.closed_error" type="warning" variant="tonal" density="compact" class="mb-3">{{ b.closed_error }}</v-alert>

                    <!-- Positionen (so, wie sie eingegeben wurden) -->
                    <v-table v-if="b.lines.length" density="compact" class="mb-3 detail-table">
                        <thead>
                            <tr>
                                <th>{{ t('BankingView.bookingDetail.account') }}</th>
                                <th class="text-end col-fit">{{ t('BankingView.bookingDetail.gross') }}</th>
                                <th class="text-end col-fit">{{ t('BankingView.bookingDetail.net') }}</th>
                                <th class="text-end col-fit">{{ t('BankingView.bookingDetail.tax') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="l in b.lines" :key="l.acc_trans_id">
                                <td>{{ l.accno }} <span class="text-medium-emphasis">{{ l.description }}</span></td>
                                <td class="text-end font-weight-medium col-fit">{{ formatCurrency(l.gross) }}</td>
                                <td class="text-end col-fit">{{ formatCurrency(l.net) }}</td>
                                <td class="text-end col-fit">
                                    <template v-if="Number(l.tax_rate) > 0">{{ Math.round(l.tax_rate * 100) }} % · {{ formatCurrency(l.tax) }}</template>
                                    <span v-else class="text-disabled">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>

                    <!-- Alle Buchungsbeine (Soll/Haben) -->
                    <v-expansion-panels variant="accordion" class="mb-2">
                        <v-expansion-panel>
                            <v-expansion-panel-title class="text-body-2">
                                <v-icon start size="small">mdi-format-list-numbered</v-icon>
                                {{ t('BankingView.bookingDetail.legs', { n: b.legs.length }) }}
                            </v-expansion-panel-title>
                            <v-expansion-panel-text>
                                <v-table density="compact" class="detail-table">
                                    <thead>
                                        <tr>
                                            <th class="col-fit">{{ t('BankingView.bookingDetail.date') }}</th>
                                            <th>{{ t('BankingView.bookingDetail.account') }}</th>
                                            <th class="text-end col-fit">{{ t('BankingView.bookingDetail.debit') }}</th>
                                            <th class="text-end col-fit">{{ t('BankingView.bookingDetail.credit') }}</th>
                                            <th class="text-end col-fit">{{ t('BankingView.bookingDetail.taxKey') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="l in b.legs" :key="l.acc_trans_id">
                                            <td class="col-fit">{{ formatDateShort(l.transdate) }}</td>
                                            <td>
                                                {{ l.accno }} <span class="text-medium-emphasis">{{ l.description }}</span>
                                                <v-icon v-if="l.bank_transaction_ids" size="14" class="ml-1" :title="t('BankingView.bookingDetail.linkedToBank')">mdi-link-variant</v-icon>
                                                <!-- Buchungstext/Beleg des Beins als Nebenzeile statt eigener Spalte -->
                                                <div v-if="l.memo || l.source" class="text-caption text-medium-emphasis">{{ l.memo || l.source }}</div>
                                            </td>
                                            <td class="text-end col-fit">{{ Number(l.amount) < 0 ? formatCurrency(-l.amount) : '' }}</td>
                                            <td class="text-end col-fit">{{ Number(l.amount) > 0 ? formatCurrency(l.amount) : '' }}</td>
                                            <td class="text-end col-fit">{{ l.taxkey || '' }}</td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>

                    <!-- Aktionen je Buchung -->
                    <div class="d-flex ga-2 flex-wrap">
                        <v-btn v-if="canEdit(b)" color="primary" variant="tonal" size="small" prepend-icon="mdi-pencil" @click="$emit('edit', b)">
                            {{ t('BankingView.bookingDetail.edit') }}
                        </v-btn>
                        <v-btn v-if="b.head.type !== 'gl'" variant="text" size="small" prepend-icon="mdi-open-in-new" @click="$emit('open-invoice', b)">
                            {{ t('BankingView.bookingDetail.openInvoice') }}
                        </v-btn>
                    </div>
                </div>
            </v-card-text>

            <v-divider />
            <v-card-actions class="pa-3">
                <v-btn v-if="bookings.length && mode === 'bank'" color="warning" variant="text" size="small" prepend-icon="mdi-undo" @click="$emit('unbook')">
                    {{ t('BankingView.bookingDetail.unbook') }}
                </v-btn>
                <v-btn v-if="bookings.length && mode === 'cash' && bookings[0].head.type === 'gl' && !bookings[0].closed_error" color="error" variant="text" size="small" prepend-icon="mdi-delete" @click="$emit('delete', bookings[0])">
                    {{ t('BankingView.bookingDetail.delete') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" @click="show = false">{{ t('BankingView.bookingDetail.close') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
/**
 * Gebuchten Vorgang ansehen — Bank (alle Buchungen zu einem Umsatz) und Kasse
 * (eine Buchung). Zeigt die Positionen, wie sie eingegeben wurden, und
 * aufklappbar jedes Buchungsbein mit Soll/Haben und Steuerschlüssel. Ändern,
 * Rückgängig/Löschen und „Rechnung öffnen" meldet der Dialog nach außen; die
 * Eltern öffnen den passenden Bearbeiten-Dialog.
 *
 * mode 'bank': bankTransactionId; mode 'cash': transId + cashRegisterId.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMatching } from '../composables/useMatching.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue:        { type: Boolean, default: false },
    mode:              { type: String,  default: 'bank' },
    bankTransactionId: { type: Number,  default: null },
    transId:           { type: Number,  default: null },
    cashRegisterId:    { type: Number,  default: null },
})
const emit = defineEmits(['update:modelValue', 'edit', 'unbook', 'delete', 'open-invoice'])
const { t } = useI18n()
const matching = useMatching()

const show = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const loading     = ref(false)
const bookings    = ref([])
const transaction = ref(null)

watch(() => props.modelValue, open => { if (open) load() }, { immediate: true })

async function load() {
    loading.value = true
    try {
        const p = props.mode === 'bank'
            ? await matching.fetchBookingDetail({ bank_transaction_id: props.bankTransactionId })
            : await matching.fetchBookingDetail({ trans_id: props.transId, cash_register_id: props.cashRegisterId })
        bookings.value    = p.bookings || []
        transaction.value = p.transaction || null
    } catch (e) {
        alerts.error(e.message)
    } finally {
        loading.value = false
    }
}

// Änderbar: Dialogbuchungen immer, Eingangsrechnungen aus dem Bankmodul
// (Positionen bei festem Bruttobetrag). Ausgangsrechnungen über die Faktura.
function canEdit(b) {
    if (b.closed_error || b.head.storno) return false
    if (b.head.type === 'gl') return true
    return b.head.type === 'ap' && props.mode === 'bank'
}

function typeIcon(type)  { return { gl: 'mdi-swap-horizontal', ap: 'mdi-file-document-arrow-right-outline', ar: 'mdi-file-document-outline' }[type] }
function typeColor(type) { return { gl: 'primary', ap: 'warning', ar: 'info' }[type] }
function reset() { bookings.value = []; transaction.value = null }
function formatCurrency(v) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v) || 0) }
function formatDateShort(d) { return d ? new Date(d).toLocaleDateString('de-DE') : '—' }

defineExpose({ reload: load })
</script>

<style scoped>
/* Datum, Beträge und Steuerschlüssel bleiben einzeilig; die Kontospalte nimmt den Rest */
.detail-table :deep(td.col-fit), .detail-table :deep(th.col-fit) { white-space: nowrap; width: 1%; }
</style>
