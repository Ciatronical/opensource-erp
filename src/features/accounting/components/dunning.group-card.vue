<!-- src/features/accounting/components/dunning.group-card.vue -->
<!--
    Ein Mahnbrief im Vorschlag: ein Kunde, eine Stufe, seine überfälligen
    Rechnungen. Die Karte ist die Bedienoberfläche des Briefs — Stufe, Weg,
    Empfänger und Rechnungsauswahl stehen direkt daran, nicht in einem Dialog.

    Der Entwurf (draft) gehört der Elternansicht; die Karte schreibt hinein.
    So kennt die Leiste am unteren Rand jederzeit die Summe über alle Karten.
-->
<template>
    <v-card variant="outlined" class="group" :class="{ 'group--selected': draft.selected, 'group--locked': group.dunning_lock }">
        <div class="group__head">
            <v-checkbox-btn v-model="draft.selected" :disabled="group.dunning_lock || !draft.ids.length" color="primary"
                            :aria-label="group.customer" />

            <div class="group__who">
                <a class="group__name" @click="$emit('open-customer', group.customer_id)">{{ group.customer }}</a>
                <div class="text-caption text-medium-emphasis">
                    {{ [group.customernumber, group.city].filter(Boolean).join(' · ') }}
                    · {{ t('AccountingView.dunning.run.maxOverdue', { days: group.max_days_overdue }) }}
                </div>
            </div>

            <div class="group__history d-none d-md-flex">
                <v-tooltip v-for="h in group.history.slice(0, 3)" :key="h.dunning_id" location="top"
                           :text="h.description + ' · ' + h.transdate">
                    <template #activator="{ props: tip }">
                        <v-chip v-bind="tip" size="x-small" variant="tonal" color="secondary" class="px-1">
                            {{ h.level }}
                        </v-chip>
                    </template>
                </v-tooltip>
                <span v-if="!group.history.length" class="text-caption text-medium-emphasis">
                    {{ t('AccountingView.dunning.run.noHistory') }}
                </span>
            </div>

            <v-spacer />

            <v-select v-model="draft.config_id" :items="levels" item-title="dunning_description" item-value="id"
                      density="compact" variant="outlined" hide-details class="group__level"
                      :label="t('AccountingView.dunning.run.level')" />

            <v-btn-toggle v-model="draft.channel" mandatory density="compact" variant="outlined" divided rounded="lg">
                <v-tooltip location="top" :text="t('AccountingView.dunning.run.channelEmail')">
                    <template #activator="{ props: tip }">
                        <v-btn v-bind="tip" value="email" size="small" :disabled="!emailConfigured" icon="mdi-email-outline" />
                    </template>
                </v-tooltip>
                <v-tooltip location="top" :text="t('AccountingView.dunning.run.channelPrint')">
                    <template #activator="{ props: tip }">
                        <v-btn v-bind="tip" value="print" size="small" icon="mdi-printer-outline" />
                    </template>
                </v-tooltip>
                <v-tooltip location="top" :text="t('AccountingView.dunning.run.channelNone')">
                    <template #activator="{ props: tip }">
                        <v-btn v-bind="tip" value="none" size="small" icon="mdi-archive-outline" />
                    </template>
                </v-tooltip>
            </v-btn-toggle>

            <v-tooltip location="top" :text="group.dunning_lock ? t('AccountingView.dunning.run.unlock') : t('AccountingView.dunning.run.lock')">
                <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" icon variant="text" size="small" :color="group.dunning_lock ? 'error' : undefined"
                           @click="$emit('toggle-lock', group)">
                        <v-icon>{{ group.dunning_lock ? 'mdi-lock' : 'mdi-lock-open-variant-outline' }}</v-icon>
                    </v-btn>
                </template>
            </v-tooltip>

            <v-btn size="small" variant="text" class="text-none" :disabled="!draft.ids.length || !draft.config_id"
                   @click="$emit('preview', group)">
                <v-icon start size="small">mdi-eye-outline</v-icon>
                {{ t('AccountingView.dunning.run.preview') }}
            </v-btn>
        </div>

        <v-alert v-if="group.dunning_lock" type="warning" variant="tonal" density="compact" class="mx-3 mb-2"
                 icon="mdi-lock" :text="t('AccountingView.dunning.run.lockedHint')" />

        <div v-if="draft.channel === 'email'" class="px-3 pb-2">
            <v-text-field v-model="draft.email" density="compact" variant="outlined" hide-details
                          prepend-inner-icon="mdi-at" :label="t('AccountingView.dunning.run.emailAddress')"
                          :error="!draft.email" :placeholder="t('AccountingView.dunning.run.emailMissing')"
                          style="max-width: 420px" />
        </div>

        <v-table density="compact" class="group__table">
            <thead>
                <tr>
                    <th style="width: 40px"></th>
                    <th>{{ t('AccountingView.dunning.run.invoice') }}</th>
                    <th>{{ t('AccountingView.dunning.run.date') }}</th>
                    <th>{{ t('AccountingView.dunning.run.dueDate') }}</th>
                    <th class="text-end">{{ t('AccountingView.dunning.run.days') }}</th>
                    <th class="text-end">{{ t('AccountingView.dunning.run.amount') }}</th>
                    <th class="text-end">{{ t('AccountingView.dunning.run.open') }}</th>
                    <th>{{ t('AccountingView.dunning.run.state') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="inv in group.invoices" :key="inv.id" :class="{ 'row--off': !draft.ids.includes(inv.id) }">
                    <td>
                        <v-checkbox-btn :model-value="draft.ids.includes(inv.id)" density="compact" color="primary"
                                        :disabled="inv.state === 'locked'" @update:model-value="toggle(inv, $event)" />
                    </td>
                    <td>
                        <a class="text-primary text-decoration-none font-weight-medium" @click="$emit('open-invoice', inv.id)">
                            {{ inv.invnumber }}
                        </a>
                        <div v-if="inv.current_level" class="text-caption text-medium-emphasis">
                            {{ t('AccountingView.dunning.run.currentLevel', { level: inv.current_description, date: inv.last_dunning_date }) }}
                        </div>
                    </td>
                    <td>{{ inv.transdate }}</td>
                    <td>{{ inv.duedate }}</td>
                    <td class="text-end">
                        <span :class="daysClass(inv.days_overdue)">{{ inv.days_overdue }}</span>
                    </td>
                    <td class="text-end">{{ money(inv.amount) }}</td>
                    <td class="text-end font-weight-medium">{{ money(inv.open_amount) }}</td>
                    <td>
                        <v-chip size="x-small" :color="stateColor(inv.state)" :variant="inv.state === 'ready' ? 'flat' : 'tonal'">
                            {{ stateLabel(inv) }}
                        </v-chip>
                    </td>
                </tr>
            </tbody>
        </v-table>

        <div class="group__foot">
            <span>{{ t('AccountingView.dunning.run.sumOpen') }} <strong>{{ money(sums.open) }}</strong></span>
            <span v-if="sums.fee > 0">+ {{ t('AccountingView.dunning.run.fee') }} <strong>{{ money(sums.fee) }}</strong></span>
            <span v-if="sums.interest > 0">+ {{ t('AccountingView.dunning.run.interest') }} <strong>{{ money(sums.interest) }}</strong></span>
            <v-spacer />
            <span class="group__total">{{ t('AccountingView.dunning.run.total') }} {{ money(sums.total) }}</span>
        </div>
    </v-card>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    group: { type: Object, required: true },
    draft: { type: Object, required: true },
    levels: { type: Array, default: () => [] },
    emailConfigured: { type: Boolean, default: false }
})

defineEmits(['preview', 'toggle-lock', 'open-customer', 'open-invoice'])

const { t } = useI18n()

const level = computed(() => props.levels.find(l => l.id === props.draft.config_id) || null)

/**
 * Summen der ausgewählten Rechnungen. Die Zinsen werden hier mit dem Satz der
 * gewählten Stufe gerechnet — wechselt der Benutzer die Stufe, stimmt die
 * Anzeige sofort; verbindlich rechnet die Datenbank beim Lauf noch einmal.
 */
const sums = computed(() => {
    const chosen = props.group.invoices.filter(i => props.draft.ids.includes(i.id))
    const rate = Number(level.value?.interest_rate || 0)
    const open = chosen.reduce((a, i) => a + Number(i.open_amount || 0), 0)
    const interest = chosen.reduce((a, i) =>
        a + Math.round(Number(i.open_amount) * Math.max(Number(i.days_overdue), 0) * rate / 360 * 100) / 100, 0)
    const fee = chosen.length ? Number(level.value?.fee || 0) : 0
    return { open, fee, interest, total: open + fee + interest }
})

function toggle(inv, on) {
    const ids = props.draft.ids
    const idx = ids.indexOf(inv.id)
    if (on && idx < 0) ids.push(inv.id)
    if (!on && idx >= 0) ids.splice(idx, 1)
    if (!ids.length) props.draft.selected = false
    else if (on && !props.group.dunning_lock) props.draft.selected = true
}

function stateColor(state) {
    return { ready: 'error', waiting: 'warning', min_amount: 'grey', direct_debit: 'info', locked: 'error', max_level: 'secondary' }[state] || 'grey'
}

function stateLabel(inv) {
    switch (inv.state) {
        case 'ready':        return t('AccountingView.dunning.run.stateReady')
        case 'waiting':      return t('AccountingView.dunning.run.stateWaiting', { days: inv.ready_in_days })
        case 'min_amount':   return t('AccountingView.dunning.run.stateMinAmount')
        case 'direct_debit': return t('AccountingView.dunning.run.stateDirectDebit')
        case 'locked':       return t('AccountingView.dunning.run.stateLocked')
        case 'max_level':    return t('AccountingView.dunning.run.stateMaxLevel')
        default:             return inv.state
    }
}

function daysClass(days) {
    const d = Number(days)
    return d > 60 ? 'text-error font-weight-bold' : d > 30 ? 'text-warning font-weight-medium' : ''
}

function money(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
}
</script>

<style scoped>
.group { border-left-width: 3px; border-left-color: rgba(var(--v-border-color), var(--v-border-opacity)); transition: border-color .15s; }
.group--selected { border-left-color: rgb(var(--v-theme-primary)); }
.group--locked   { border-left-color: rgb(var(--v-theme-error)); opacity: .85; }
.group__head { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem .75rem; padding: .5rem .75rem .5rem .25rem; }
.group__head :deep(.v-selection-control) { flex: 0 0 auto; min-height: 0; }
.group__who { min-width: 200px; }
.group__name { font-weight: 600; cursor: pointer; color: inherit; text-decoration: none; }
.group__name:hover { color: rgb(var(--v-theme-primary)); }
.group__history { display: flex; gap: 3px; align-items: center; }
.group__level { max-width: 230px; min-width: 190px; }
.group__table :deep(th) { font-size: .7rem; letter-spacing: .04em; text-transform: uppercase; opacity: .7; white-space: nowrap; }
.group__table :deep(td) { font-size: .82rem; }
.row--off td { opacity: .55; }
.group__foot { display: flex; align-items: center; flex-wrap: wrap; gap: 1rem; padding: .45rem .9rem; font-size: .82rem;
               border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: rgba(var(--v-theme-on-surface), .03); }
.group__total { font-weight: 700; font-size: .95rem; font-variant-numeric: tabular-nums; }
</style>
