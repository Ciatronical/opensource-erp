<template>
    <div class="split-lines">
        <div
            v-for="(line, i) in lines"
            :key="i"
            class="split-line"
            :class="{ 'split-line--multi': multi }"
        >
            <v-autocomplete
                v-model="line.account"
                :items="itemsFor(i)"
                :item-title="accountTitle"
                item-value="id"
                return-object
                :label="multi ? t('BankingView.split.accountN', { n: i + 1 }) : accountLabel"
                density="compact"
                variant="outlined"
                :no-filter="!!searchAccounts"
                :loading="!!loading[i]"
                :no-data-text="searchAccounts ? t('BankingView.split.searchHint') : t('BankingView.split.noAccounts')"
                clearable
                hide-details="auto"
                :hint="multi ? '' : hintFor(line)"
                :persistent-hint="!multi && !!hintFor(line)"
                :prepend-inner-icon="multi ? 'mdi-tag-outline' : undefined"
                class="split-line__account"
                @update:search="q => onSearch(i, q)"
                @update:model-value="acc => onAccountPicked(i, acc)"
            />

            <v-text-field
                v-if="multi"
                :ref="el => amountRefs[i] = el"
                v-model.number="line.gross"
                type="number"
                step="0.01"
                min="0"
                :label="t('BankingView.split.amount')"
                density="compact"
                variant="outlined"
                hide-details="auto"
                :hint="hintFor(line)"
                persistent-hint
                prepend-inner-icon="mdi-currency-eur"
                class="split-line__amount"
                @focus="e => e.target.select()"
            >
                <template v-if="rest !== 0" #append-inner>
                    <v-tooltip :text="t('BankingView.split.takeRemainder', { amount: fmt(rest) })" location="top">
                        <template #activator="{ props: tip }">
                            <v-icon
                                v-bind="tip"
                                icon="mdi-auto-fix"
                                size="small"
                                color="primary"
                                style="cursor: pointer"
                                @click="takeRemainder(i)"
                            />
                        </template>
                    </v-tooltip>
                </template>
            </v-text-field>

            <v-select
                v-model="line.tax"
                :items="taxItems"
                item-title="title"
                item-value="value"
                :label="t('BankingView.split.tax')"
                density="compact"
                variant="outlined"
                hide-details
                class="split-line__tax"
            />

            <v-btn
                v-if="multi"
                icon="mdi-close"
                size="small"
                variant="text"
                :title="t('BankingView.split.removeLine')"
                class="split-line__remove"
                @click="removeLine(i)"
            />
        </div>

        <div class="split-lines__footer">
            <v-btn
                size="small"
                variant="text"
                color="primary"
                prepend-icon="mdi-call-split"
                class="text-none"
                @click="addLine"
            >
                {{ multi ? t('BankingView.split.addLine') : t('BankingView.split.split') }}
            </v-btn>
            <v-spacer />
            <span v-if="multi" class="text-caption" :class="rest === 0 ? 'text-success' : 'text-error'">
                {{ rest === 0
                    ? t('BankingView.split.sumOk', { sum: fmt(sum) })
                    : t('BankingView.split.remainder', { sum: fmt(sum), total: fmt(total), rest: fmt(rest) }) }}
            </span>
        </div>
    </div>
</template>

<script setup>
/**
 * Positionen einer Splitbuchung — ein Bruttobetrag auf mehrere Konten und
 * Steuersätze verteilen (kivitendo: mehrere Zeilen in Dialog- und
 * Kreditorenbuchung).
 *
 * Mit einer Position sieht das Feld aus wie ein gewöhnliches Gegenkonto mit
 * Steuerhinweis; „Aufteilen" macht daraus eine Liste. Jede weitere Position
 * übernimmt das Konto der vorherigen und wechselt den Steuersatz (19 % ↔ 7 %),
 * denn genau das ist der häufigste Fall: derselbe Supermarkt-Bon, zwei Sätze.
 * Der offene Rest steht unter der Liste; der Zauberstab an einer Position
 * trägt ihn dort ein.
 *
 * v-model: Array von { account, gross, tax } (siehe useSplitLines.js).
 * total:   zu verteilender Bruttobetrag; bei genau einer Position ist ihr
 *          Betrag immer der Gesamtbetrag.
 * items / searchAccounts: feste Kontenliste ODER Suchfunktion (q) → Konten.
 * taxItems: [{ value, title, rate }] — value ist, was der Dialog an das
 *          Backend schickt (Steuersatz oder tax.id); rate der Bruchteil (0.19).
 * defaultTax(account): Steuer-value, der beim Wählen eines Kontos gesetzt wird.
 */
import { ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { newLine, linesSum, remainder, netTax } from '../composables/useSplitLines.js'

const props = defineProps({
    modelValue:     { type: Array,    required: true },
    total:          { type: Number,   default: 0 },
    items:          { type: Array,    default: () => [] },
    searchAccounts: { type: Function, default: null },
    accountTitle:   { type: Function, default: a => (a ? `${a.accno} – ${a.description}` : '') },
    accountLabel:   { type: String,   default: '' },
    taxItems:       { type: Array,    default: () => [] },
    defaultTax:     { type: Function, default: () => null },
})
const emit = defineEmits(['update:modelValue', 'account-change'])

const { t } = useI18n()

const lines = computed(() => props.modelValue)
const multi = computed(() => lines.value.length > 1)
const sum   = computed(() => linesSum(lines.value))
const rest  = computed(() => remainder(props.total, lines.value))

// Bei einer Position ist ihr Betrag der Gesamtbetrag — immer, ohne Zutun.
watch([() => props.total, () => lines.value.length], () => {
    if (lines.value.length === 1) lines.value[0].gross = props.total
}, { immediate: true })

// ── Kontenauswahl: feste Liste oder Suche je Position ────────────────────────
const searchResults = ref({})
const loading       = ref({})
const timers        = {}

function itemsFor(i) {
    if (!props.searchAccounts) return props.items
    const own = lines.value[i]?.account
    const found = searchResults.value[i] || []
    // Das gewählte Konto bleibt in der Liste, sonst zeigt das Feld nur die Nummer
    return own && !found.some(a => a.id === own.id) ? [own, ...found] : found
}

function onSearch(i, q) {
    if (!props.searchAccounts) return
    clearTimeout(timers[i])
    if (!q || q.length < 1) return
    const current = lines.value[i]?.account
    if (current && props.accountTitle(current) === q) return
    timers[i] = setTimeout(async () => {
        loading.value = { ...loading.value, [i]: true }
        try { searchResults.value = { ...searchResults.value, [i]: await props.searchAccounts(q) } }
        finally { loading.value = { ...loading.value, [i]: false } }
    }, 300)
}

function onAccountPicked(i, acc) {
    const line = lines.value[i]
    if (acc) line.tax = props.defaultTax(acc)
    emit('account-change', i, acc)
}

// ── Positionen hinzufügen / entfernen ────────────────────────────────────────
const amountRefs = ref([])

function rateOf(taxValue) {
    return Number(props.taxItems.find(x => x.value === taxValue)?.rate ?? 0)
}

/** 19 % ↔ 7 %: der andere Regelsatz, falls vorhanden — sonst die Steuer der Vorlage */
function flippedTax(taxValue) {
    const r = rateOf(taxValue)
    const want = r > 0.1 ? 0.07 : (r > 0 ? 0.19 : null)
    const hit = want === null ? null : props.taxItems.find(x => Math.abs(Number(x.rate) - want) < 0.001)
    return hit ? hit.value : taxValue
}

async function addLine() {
    const prev = lines.value[lines.value.length - 1]
    const line = newLine(prev?.account ?? null, prev ? flippedTax(prev.tax) : null, rest.value > 0 ? rest.value : '')
    emit('update:modelValue', [...lines.value, line])
    await nextTick()
    amountRefs.value[lines.value.length - 1]?.focus?.()
}

function removeLine(i) {
    emit('update:modelValue', lines.value.filter((_, k) => k !== i))
}

function takeRemainder(i) {
    const line = lines.value[i]
    line.gross = Math.round(((Number(line.gross) || 0) + rest.value) * 100) / 100
}

// ── Anzeige ──────────────────────────────────────────────────────────────────
function fmt(v) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v) || 0)
}

function hintFor(line) {
    if (!line?.account) return ''
    const rate = rateOf(line.tax)
    if (rate <= 0) return t('BankingView.split.lineNoTax')
    const { net, tax } = netTax(line.gross, rate)
    return t('BankingView.split.lineHint', { rate: Math.round(rate * 100), tax: fmt(tax), net: fmt(net) })
}

defineExpose({ sum, rest })
</script>

<style scoped>
.split-line {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    margin-bottom: 8px;
}
.split-line__account { flex: 1 1 auto; min-width: 0; }
.split-line__amount  { flex: 0 0 150px; }
.split-line__tax     { flex: 0 0 150px; }
.split-line__remove  { margin-top: 4px; }
.split-lines__footer {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
@media (max-width: 600px) {
    .split-line--multi { flex-wrap: wrap; }
    .split-line--multi .split-line__account { flex-basis: 100%; }
    .split-line--multi .split-line__amount,
    .split-line--multi .split-line__tax { flex: 1 1 40%; }
}
</style>
