<template>
    <v-dialog v-model="show" max-width="800" scrollable @after-leave="reset">
        <v-card v-if="transaction" rounded="lg">
            <v-card-title class="d-flex align-center py-3">
                <v-icon start>mdi-swap-horizontal</v-icon>
                {{ edit ? t('BankingView.chartBooking.editTitle') : t('BankingView.chartBooking.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" @click="show = false" />
            </v-card-title>
            <v-card-subtitle class="pb-2">
                <span :class="isOutgoing ? 'text-error' : 'text-success'" class="font-weight-semibold">{{ formatCurrency(transaction.amount) }}</span>
                · {{ transaction.remote_name || '—' }} · {{ formatDateShort(transaction.transdate) }}
                <div v-if="transaction.purpose" class="text-caption text-medium-emphasis text-truncate">{{ transaction.purpose }}</div>
            </v-card-subtitle>

            <v-card-text>
                <v-progress-linear v-if="loading" indeterminate class="mb-4" />
                <template v-else>
                    <!-- Erkennung -->
                    <v-alert v-if="suggestionText && !edit" type="info" variant="tonal" density="compact"
                             icon="mdi-lightbulb-on-outline" class="mb-4">
                        {{ suggestionText }}
                    </v-alert>

                    <!-- Vorhandene Buchung: nur verknüpfen (Gegenseite einer Umbuchung,
                         Bareinzahlung aus der Kasse) -->
                    <v-card v-if="existingLeg && !bookNewInstead && !edit" variant="tonal" color="success" class="mb-4" rounded="lg">
                        <v-card-text>
                            <div class="font-weight-semibold mb-1">
                                <v-icon start size="small">mdi-link-variant</v-icon>
                                {{ t('BankingView.chartBooking.existingTitle') }}
                            </div>
                            <div class="text-body-2">
                                {{ t('BankingView.chartBooking.beleg') }} {{ existingLeg.reference }} · {{ formatDateShort(existingLeg.transdate) }}
                                · {{ existingLeg.description || '—' }}
                            </div>
                            <div class="text-caption text-medium-emphasis">{{ existingLeg.counter_accounts }}</div>
                            <div class="d-flex ga-2 mt-3 flex-wrap">
                                <v-btn color="success" variant="elevated" size="small" :loading="saving"
                                       prepend-icon="mdi-check" @click="link">
                                    {{ t('BankingView.chartBooking.linkAction') }}
                                </v-btn>
                                <v-btn variant="text" size="small" @click="bookNewInstead = true">
                                    {{ t('BankingView.chartBooking.bookNewInstead') }}
                                </v-btn>
                            </div>
                        </v-card-text>
                    </v-card>

                    <template v-if="!existingLeg || bookNewInstead || edit">
                        <!-- Was ist das für ein Vorgang? -->
                        <div class="text-overline text-medium-emphasis mb-1">{{ t('BankingView.chartBooking.whatIsIt') }}</div>
                        <v-chip-group v-model="templateKey" mandatory column class="mb-3">
                            <v-chip v-for="tpl in templates" :key="tpl.key" :value="tpl.key"
                                    :prepend-icon="tpl.icon || 'mdi-tag-outline'" variant="outlined" filter
                                    :color="templateKey === tpl.key ? 'primary' : undefined">
                                {{ tplLabel(tpl) }}
                                <span v-if="tpl.accno" class="text-caption text-medium-emphasis ml-1">{{ tpl.accno }}</span>
                            </v-chip>
                        </v-chip-group>

                        <!-- Umbuchung auf eigenes Bankkonto -->
                        <template v-if="templateKey === 'transfer_own'">
                            <v-select
                                v-model="targetAccountId"
                                :items="ownAccounts"
                                :item-title="a => `${a.name} · ${a.accno} ${a.description}`"
                                item-value="id"
                                :label="t('BankingView.chartBooking.targetAccount')"
                                density="compact" variant="outlined"
                                prepend-inner-icon="mdi-bank"
                                :no-data-text="t('BankingView.chartBooking.noOwnAccounts')" />
                            <v-alert v-if="!ownAccounts.length" type="warning" variant="tonal" density="compact" class="mb-3">
                                {{ t('BankingView.chartBooking.noOwnAccounts') }}
                            </v-alert>
                            <v-alert v-else-if="counterpart" type="success" variant="tonal" density="compact" class="mb-3" icon="mdi-link-variant">
                                {{ t('BankingView.chartBooking.counterpartFound', {
                                    date: formatDateShort(counterpart.transdate), amount: formatCurrency(counterpart.amount), account: targetAccount?.name }) }}
                            </v-alert>
                            <v-alert v-else-if="targetAccountId" type="info" variant="tonal" density="compact" class="mb-3">
                                {{ t('BankingView.chartBooking.counterpartPending') }}
                            </v-alert>
                        </template>

                        <!-- Konto(en) und Steuer -->
                        <split-lines
                            v-else
                            v-model="lines"
                            :total="gross"
                            :search-accounts="searchAccountsForSplit"
                            :account-title="a => a ? `${a.accno} ${a.description}` : ''"
                            :account-label="t('BankingView.chartBooking.account')"
                            :tax-items="taxItems"
                            :default-tax="a => Number(a?.tax_id) || 0"
                        />

                        <v-text-field
                            v-model="description"
                            :label="t('BankingView.chartBooking.bookingText')"
                            density="compact" variant="outlined" class="mt-2"
                            prepend-inner-icon="mdi-text-short" />
                    </template>
                </template>
            </v-card-text>

            <v-divider />
            <v-card-actions class="pa-3">
                <v-spacer />
                <v-btn variant="text" @click="show = false">{{ t('BankingView.chartBooking.cancel') }}</v-btn>
                <v-btn v-if="!existingLeg || bookNewInstead || edit" color="primary" variant="elevated"
                       :loading="saving" :disabled="!canBook" prepend-icon="mdi-check" @click="book">
                    {{ edit ? t('BankingView.chartBooking.save') : t('BankingView.chartBooking.book') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
/**
 * Bankumsatz ohne Beleg auf ein Konto buchen (Dialogbuchung, kivitendo gl).
 *
 * Der Dialog fragt zuerst „Was ist das für ein Vorgang?" und bietet die
 * Vorlagen aus bank_booking_templates an (Umbuchung, Kasse, Privat, Lohn,
 * Finanzamt, Gebühren …). Das Backend erkennt den Vorgang vorab: eine
 * vorhandene Buchung auf dem Bankkonto wird nur verknüpft, eine eigene
 * Gegen-IBAN wird zur Umbuchung mit Zielkonto, ein Muster im Verwendungszweck
 * wählt die Vorlage vor. Alles andere läuft über die Positionen (split-lines),
 * also auch auf mehrere Konten verteilbar.
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMatching } from '../composables/useMatching.js'
import { useAccounting } from '@/features/accounting/composables/useAccounting.js'
import SplitLines from './split-lines.component.vue'
import { newLine, linesError } from '../composables/useSplitLines.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue:  { type: Boolean, default: false },
    transaction: { type: Object,  default: null },
    // Bearbeiten einer vorhandenen Dialogbuchung: { gl_id, description, lines[{chart_id, accno, description, gross, tax_id, tax_rate}] }
    edit:        { type: Object,  default: null },
})
const emit = defineEmits(['update:modelValue', 'done'])

const { t } = useI18n()
const matching = useMatching()
const { searchAccounts } = useAccounting()

const show = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })

const loading        = ref(false)
const saving         = ref(false)
const templates      = ref([])
const taxes          = ref([])
const ownAccounts    = ref([])
const existingLeg    = ref(null)
const suggestion     = ref(null)
const bookNewInstead = ref(false)
const templateKey    = ref(null)
const targetAccountId = ref(null)
const lines          = ref([newLine()])
const description    = ref('')

const isOutgoing = computed(() => Number(props.transaction?.amount) < 0)
const gross      = computed(() => Math.abs(Number(props.transaction?.amount) || 0))
const currentTemplate = computed(() => templates.value.find(x => x.key === templateKey.value) || null)
const targetAccount   = computed(() => ownAccounts.value.find(a => a.id === targetAccountId.value) || null)
const counterpart     = computed(() => targetAccount.value?.counterpart_transaction || null)

// Steuer je Position: Vorsteuer bei Geldausgang, Umsatzsteuer bei Geldeingang
const taxItems = computed(() => {
    const kind = isOutgoing.value ? 'expense' : 'income'
    return [
        ...taxes.value.filter(x => x.kind === kind).map(x => ({
            value: Number(x.tax_id), rate: Number(x.rate),
            title: `${Math.round(Number(x.rate) * 100)} % ${x.taxdescription || ''}`.trim(),
        })),
        { value: 0, rate: 0, title: t('BankingView.split.taxNone') },
    ]
})

const suggestionText = computed(() => {
    const s = suggestion.value
    if (!s) return ''
    if (s.type === 'link') return t('BankingView.chartBooking.suggestionLink')
    if (s.type === 'transfer_own') {
        const acc = ownAccounts.value.find(a => a.id === s.bank_account_id)
        return t('BankingView.chartBooking.suggestionTransfer', { account: acc?.name || '' })
    }
    const tpl = templates.value.find(x => x.key === s.key)
    return tpl ? t('BankingView.chartBooking.suggestionTemplate', { label: tplLabel(tpl) }) : ''
})

const canBook = computed(() => {
    if (templateKey.value === 'transfer_own') return !!targetAccountId.value
    return !linesError(gross.value, lines.value)
})

function tplLabel(tpl) {
    const key = `BankingView.chartBooking.tpl_${tpl.key}`
    const label = t(key)
    return label === key ? tpl.label : label
}

// immediate: der Hub rendert den Dialog auch schon geöffnet (v-if auf den Umsatz)
watch(() => props.modelValue, open => { if (open && props.transaction) load() }, { immediate: true })

async function load() {
    loading.value = true
    bookNewInstead.value = false
    try {
        const p = await matching.fetchChartBookingOptions(props.transaction.id)
        templates.value   = p.templates || []
        taxes.value       = p.taxes || []
        ownAccounts.value = p.own_accounts || []
        existingLeg.value = p.existing_leg || null
        suggestion.value  = p.suggestion || null
        description.value = [props.transaction.remote_name, props.transaction.purpose].filter(Boolean).join(' · ')

        // Bearbeiten: Positionen der gebuchten Buchung übernehmen — Umbuchung,
        // wenn das Gegenkonto ein eigenes Bankkonto ist, sonst die passende Vorlage
        if (props.edit) {
            description.value = props.edit.description || description.value
            const first = props.edit.lines?.[0]
            const own   = first ? ownAccounts.value.find(a => Number(a.chart_id) === Number(first.chart_id)) : null
            if (own && props.edit.lines.length === 1) {
                templateKey.value = 'transfer_own'
                targetAccountId.value = own.id
            } else {
                const tpl = templates.value.find(x => x.chart_id && first && Number(x.chart_id) === Number(first.chart_id))
                templateKey.value = tpl?.key || 'other'
                lines.value = (props.edit.lines || []).map(l => newLine(
                    { id: l.chart_id, accno: l.accno, description: l.description, tax_id: l.tax_id, tax_rate: l.tax_rate },
                    Number(l.tax_id) || 0, Number(l.gross)))
                if (!lines.value.length) lines.value = [newLine()]
            }
            return
        }

        // Vorbelegung aus der Erkennung, sonst die erste Vorlage
        const s = suggestion.value
        if (s?.type === 'transfer_own') {
            templateKey.value = 'transfer_own'
            targetAccountId.value = s.bank_account_id
        } else if (s?.type === 'template') {
            templateKey.value = s.key
        } else {
            const ibanHit = ownAccounts.value.find(a => a.matches_remote_iban)
            templateKey.value = templates.value.find(x => x.key !== 'transfer_own' && x.key !== 'other')?.key || templates.value[0]?.key || null
            if (ibanHit) { templateKey.value = 'transfer_own'; targetAccountId.value = ibanHit.id }
        }
        if (templateKey.value === 'transfer_own' && !targetAccountId.value && ownAccounts.value.length === 1) {
            targetAccountId.value = ownAccounts.value[0].id
        }
        applyTemplate()
    } catch (e) {
        alerts.error(e.message)
    } finally {
        loading.value = false
    }
}

// Vorlage gewählt: eine Position mit dem Konto der Vorlage und dessen Steuer
watch(templateKey, (nk, ok) => { if (ok !== undefined && nk !== ok) applyTemplate() })
function applyTemplate() {
    const tpl = currentTemplate.value
    if (!tpl || tpl.key === 'transfer_own') return
    const account = tpl.chart_id
        ? { id: tpl.chart_id, accno: tpl.accno, description: tpl.chart_description, tax_id: tpl.tax_id, tax_rate: tpl.tax_rate }
        : null
    lines.value = [newLine(account, account ? (Number(tpl.tax_id) || 0) : null, gross.value)]
}

async function searchAccountsForSplit(q) {
    const rows = await searchAccounts(q)
    return rows.map(a => ({ id: a.id, accno: a.accno, description: a.description, tax_id: a.tax_id, tax_rate: a.tax_rate }))
}

async function book() {
    if (!canBook.value) return
    saving.value = true
    try {
        const payload = { bank_transaction_id: props.transaction.id, description: description.value || undefined, template_key: templateKey.value || undefined }
        if (templateKey.value === 'transfer_own') {
            payload.bank_account_id = targetAccountId.value
        } else {
            payload.lines = lines.value.map(l => ({
                chart_id: l.account.id, gross: Number(l.gross),
                tax_id: l.tax === null || l.tax === undefined ? undefined : Number(l.tax),
            }))
        }
        const res = props.edit
            ? await matching.updateChartBooking(payload)
            : await matching.bookTransactionToChart(payload)
        alerts.success(props.edit ? t('BankingView.chartBooking.successEdit', { beleg: res.beleg }) : res.counterpart_booked
            ? t('BankingView.chartBooking.successCounterpart', { beleg: res.beleg, account: res.target_account || '' })
            : t('BankingView.chartBooking.success', { beleg: res.beleg }))
        emit('done')
        show.value = false
    } catch (e) {
        alerts.error(e.message)
    } finally {
        saving.value = false
    }
}

async function link() {
    if (!existingLeg.value) return
    saving.value = true
    try {
        await matching.linkTransactionToGl(props.transaction.id, existingLeg.value.acc_trans_id)
        alerts.success(t('BankingView.chartBooking.linkSuccess', { beleg: existingLeg.value.reference }))
        emit('done')
        show.value = false
    } catch (e) {
        alerts.error(e.message)
    } finally {
        saving.value = false
    }
}

function reset() {
    templates.value = []; taxes.value = []; ownAccounts.value = []
    existingLeg.value = null; suggestion.value = null
    templateKey.value = null; targetAccountId.value = null
    lines.value = [newLine()]; description.value = ''
}

function formatCurrency(v) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v) || 0) }
function formatDateShort(d) { return d ? new Date(d).toLocaleDateString('de-DE') : '—' }
</script>
