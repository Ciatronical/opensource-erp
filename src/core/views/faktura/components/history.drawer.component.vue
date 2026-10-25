<!-- src/core/views/faktura/components/history.drawer.component.vue -->
<!--
    Belegchronik: wer hat wann was am Beleg geändert.

    Quelle sind die Diffs aus record_history_oserp (Trigger auf Kopf, Positionen
    und LxCars-Erweiterung), dazu kivitendo-Vorgeschichte, Belegkette und
    Wiedervorlagen (getFakturaHistory) sowie der Versand aus dem Store
    (faktura.data.sent_log). Faktura speichert jedes Feld einzeln — Zeilen
    derselben Person kurz hintereinander werden deshalb zu einer Bearbeitung
    zusammengefasst und netto gezeigt (A → B → C erscheint als A → C, eine kurz
    angelegte und wieder gelöschte Position verschwindet).

    Die Zeitreise rechnet aus den Diffs den Stand von Kopf und Positionen vor
    einer Bearbeitung zurück und stellt ihn gegen heute — ohne Schnappschüsse.
-->
<template>
    <v-navigation-drawer
        :model-value="modelValue"
        location="right"
        temporary
        :width="smAndDown ? undefined : 600"
        :class="{ 'history-drawer--full': smAndDown }"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <div class="history-drawer">
            <!-- Kopf -->
            <div class="history-drawer__head d-flex align-center ga-2">
                <v-icon color="primary">mdi-history</v-icon>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-subtitle-1 font-weight-medium">{{ t('FakturaView.history.title') }}</div>
                    <div class="text-caption text-medium-emphasis text-truncate">{{ docLabel }}</div>
                </div>
                <v-btn icon="mdi-refresh" variant="text" size="small" :loading="loading" :title="t('FakturaView.history.reload')" @click="load" />
                <v-btn icon="mdi-close" variant="text" size="small" :title="t('FakturaView.history.close')" @click="$emit('update:modelValue', false)" />
            </div>

            <div class="history-drawer__body">
                <!-- Überblick -->
                <div v-if="lastEdit || createdInfo" class="d-flex flex-wrap ga-2 mb-3">
                    <v-chip v-if="createdInfo" size="small" variant="tonal" prepend-icon="mdi-file-plus-outline">{{ createdInfo }}</v-chip>
                    <v-chip v-if="lastEdit" size="small" variant="tonal" color="primary" prepend-icon="mdi-pencil-outline">
                        {{ t('FakturaView.history.lastChange', { time: relativeTime(lastEdit.itime), name: actorName(lastEdit) }) }}
                    </v-chip>
                </div>

                <!-- Filter -->
                <v-chip-group v-model="filterKind" mandatory selected-class="text-primary" class="mb-1">
                    <v-chip value="all" size="small" variant="outlined" filter>{{ t('FakturaView.history.filter.all') }}</v-chip>
                    <v-chip value="head" size="small" variant="outlined" filter>{{ t('FakturaView.history.filter.head') }}</v-chip>
                    <v-chip value="items" size="small" variant="outlined" filter>{{ t('FakturaView.history.filter.items') }}</v-chip>
                    <v-chip value="events" size="small" variant="outlined" filter>{{ t('FakturaView.history.filter.events') }}</v-chip>
                </v-chip-group>
                <div class="d-flex flex-wrap ga-2 mb-3">
                    <v-select
                        v-model="filterActor"
                        :items="actorChoices"
                        :label="t('FakturaView.history.filterEmployee')"
                        density="compact"
                        variant="outlined"
                        hide-details
                        clearable
                        class="history-filter"
                    />
                    <v-text-field
                        v-model="filterText"
                        :label="t('FakturaView.history.search')"
                        prepend-inner-icon="mdi-magnify"
                        density="compact"
                        variant="outlined"
                        hide-details
                        clearable
                        class="history-filter"
                    />
                </div>

                <!-- Zeitreise -->
                <div v-if="editEvents.length" class="time-travel mb-4">
                    <div class="d-flex align-center flex-wrap ga-2 mb-1">
                        <v-icon size="small" color="primary">mdi-clock-time-four-outline</v-icon>
                        <span class="text-subtitle-2">{{ t('FakturaView.history.timeTravel') }}</span>
                        <span class="text-body-2 text-medium-emphasis">{{ travelLabel }}</span>
                        <v-spacer />
                        <v-btn v-if="travelIndex > 0" size="x-small" variant="text" @click="travelIndex = 0">
                            {{ t('FakturaView.history.backToToday') }}
                        </v-btn>
                    </div>
                    <v-slider
                        v-model="travelIndex"
                        :max="editEvents.length"
                        :min="0"
                        :step="1"
                        show-ticks="always"
                        tick-size="3"
                        color="primary"
                        hide-details
                    />
                    <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                        <span>{{ t('FakturaView.history.today') }}</span>
                        <span>{{ formatDateTime(editEvents[editEvents.length - 1].itime) }}</span>
                    </div>
                    <v-alert v-if="travelIndex > 0" type="info" variant="tonal" density="compact" icon="mdi-history" class="mt-2">
                        <div class="font-weight-medium mb-1">
                            {{ t('FakturaView.history.asOf', { time: formatDateTime(editEvents[travelIndex - 1].itime) }) }}
                        </div>
                        <div v-if="!travelDiff.head.length && !travelDiff.items.length" class="text-body-2">{{ t('FakturaView.history.noDiff') }}</div>
                        <table v-if="travelDiff.head.length" class="change-table">
                            <tbody>
                                <tr v-for="d in travelDiff.head" :key="d.key">
                                    <td class="change-table__label">{{ fieldLabel(d.key, d.table) }}</td>
                                    <td class="change-table__old">{{ formatValue(d.key, d.then, d.table) }}</td>
                                    <td class="change-table__arrow"><v-icon size="x-small">mdi-arrow-right</v-icon></td>
                                    <td>{{ formatValue(d.key, d.now, d.table) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-for="item in travelDiff.items" :key="'tt-' + item.id" class="text-body-2 mt-1">
                            <v-icon size="x-small" :color="item.state === 'added' ? 'success' : (item.state === 'removed' ? 'error' : undefined)">
                                {{ item.state === 'added' ? 'mdi-plus' : (item.state === 'removed' ? 'mdi-minus' : 'mdi-pencil-outline') }}
                            </v-icon>
                            <span class="font-weight-medium">{{ item.label }}</span>
                            <span class="text-medium-emphasis"> — {{ t('FakturaView.history.travelItem.' + item.state) }}</span>
                            <span v-if="item.changes.length" class="text-medium-emphasis">:
                                <span v-for="(c, i) in item.changes" :key="c.key">{{ i ? ', ' : '' }}{{ fieldLabel(c.key, 'item') }} {{ formatValue(c.key, c.then, 'item') }} → {{ formatValue(c.key, c.now, 'item') }}</span>
                            </span>
                        </div>
                    </v-alert>
                </div>

                <!-- Zeitleiste -->
                <div v-if="loading && !loaded" class="d-flex justify-center pa-6">
                    <v-progress-circular indeterminate color="primary" />
                </div>
                <v-alert v-else-if="!filteredEntries.length" type="info" variant="tonal" density="compact">
                    {{ entries.length ? t('FakturaView.history.noMatch') : t('FakturaView.history.empty') }}
                </v-alert>
                <v-timeline v-else side="end" density="compact" align="start" truncate-line="both">
                    <v-timeline-item
                        v-for="entry in filteredEntries"
                        :key="entry.key"
                        :dot-color="entry.color"
                        size="small"
                        :class="{ 'history-entry--travel': entry.travelIndex !== null && entry.travelIndex === travelIndex - 1 }"
                    >
                        <template #icon>
                            <span v-if="entry.kind === 'edit'" class="avatar-initials">{{ initials(actorName(entry)) }}</span>
                            <v-icon v-else size="x-small" color="white">{{ entry.icon }}</v-icon>
                        </template>
                        <div class="history-entry">
                            <div class="d-flex align-center flex-wrap ga-2">
                                <span v-if="actorName(entry)" class="text-body-2 font-weight-medium">{{ actorName(entry) }}</span>
                                <span class="text-caption text-medium-emphasis" :title="formatDateTime(entry.itime)">{{ relativeTime(entry.itime) }}</span>
                                <v-chip v-if="sourceLabel(entry)" size="x-small" variant="outlined" label>{{ sourceLabel(entry) }}</v-chip>
                                <v-chip v-for="m in entry.milestones || []" :key="m.key" size="x-small" variant="tonal" :color="m.color" label>{{ m.text }}</v-chip>
                            </div>

                            <!-- Bearbeitung -->
                            <template v-if="entry.kind === 'edit'">
                                <div v-if="entry.summary" class="text-body-2 text-medium-emphasis mt-1">{{ entry.summary }}</div>
                                <table v-if="visibleHead(entry).length" class="change-table mt-1">
                                    <tbody>
                                        <tr v-for="c in visibleHead(entry)" :key="c.table + c.key">
                                            <td class="change-table__label">{{ fieldLabel(c.key, c.table) }}</td>
                                            <template v-if="entry.created">
                                                <td class="change-table__new" colspan="3">{{ formatValue(c.key, c.new, c.table) }}</td>
                                            </template>
                                            <template v-else>
                                                <td class="change-table__old">{{ formatValue(c.key, c.old, c.table) }}</td>
                                                <td class="change-table__arrow"><v-icon size="x-small">mdi-arrow-right</v-icon></td>
                                                <td class="change-table__new">{{ formatValue(c.key, c.new, c.table) }}</td>
                                            </template>
                                        </tr>
                                    </tbody>
                                </table>
                                <template v-if="filterKind !== 'head'">
                                    <div v-for="item in entry.items" :key="'i' + item.id" class="history-item mt-1">
                                        <div class="text-body-2">
                                            <v-icon size="x-small" class="me-1" :color="item.state === 'added' ? 'success' : (item.state === 'removed' ? 'error' : 'primary')">
                                                {{ item.state === 'added' ? 'mdi-plus-circle-outline' : (item.state === 'removed' ? 'mdi-minus-circle-outline' : 'mdi-pencil-circle-outline') }}
                                            </v-icon>
                                            <span class="font-weight-medium history-item__label">{{ item.label }}</span>
                                            <span class="text-caption text-medium-emphasis text-no-wrap"> · {{ t('FakturaView.history.item.' + item.state) }}</span>
                                        </div>
                                        <div v-if="item.state !== 'changed'" class="text-caption text-medium-emphasis ms-5">{{ itemLine(item.values) }}</div>
                                        <table v-else class="change-table ms-5">
                                            <tbody>
                                                <tr v-for="c in item.changes" :key="c.key">
                                                    <td class="change-table__label">{{ fieldLabel(c.key, 'item') }}</td>
                                                    <td class="change-table__old">{{ formatValue(c.key, c.old, 'item') }}</td>
                                                    <td class="change-table__arrow"><v-icon size="x-small">mdi-arrow-right</v-icon></td>
                                                    <td class="change-table__new">{{ formatValue(c.key, c.new, 'item') }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </template>
                            </template>

                            <!-- Ereignisse: Versand, Belegkette, Wiedervorlage, kivitendo -->
                            <div v-else class="text-body-2 mt-1">
                                <a v-if="entry.link" href="#" class="history-link" @click.prevent="$emit('open-record', entry.link)">{{ entry.text }}</a>
                                <span v-else>{{ entry.text }}</span>
                                <div v-if="entry.detail" class="text-caption text-medium-emphasis">{{ entry.detail }}</div>
                            </div>
                        </div>
                    </v-timeline-item>
                </v-timeline>
            </div>
        </div>
    </v-navigation-drawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import axios from 'axios'
import { formatRelativeTime } from '@/core/utils/dateFormatter.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    fakturaId: { type: [Number, String], default: null },
    fakturaType: { type: String, default: 'invoice' },
    /** Aktueller Beleg (faktura.data): Ausgangspunkt der Zeitreise, Versandprotokoll */
    current: { type: [Object, Boolean], default: null },
    /** Positionen wie in der Maske (mit id, description, qty …) */
    positions: { type: Array, default: () => [] },
    /** Überschrift, z. B. „Auftrag 253625“ */
    docLabel: { type: String, default: '' },
})
defineEmits(['update:modelValue', 'open-record'])

const { t, te, locale } = useI18n()
const { smAndDown } = useDisplay()

const loading = ref(false)
const loaded = ref(false)
const history = ref({ results: [], legacy: [], links: [], followups: [], lookups: {} })
const filterKind = ref('all')
const filterActor = ref(null)
const filterText = ref('')
const travelIndex = ref(0)

async function load() {
    if (!props.fakturaId) return
    loading.value = true
    try {
        const response = await axios.post('/api/faktura/', {
            action: 'getFakturaHistory',
            fakturaID: Number(props.fakturaId),
            fakturaType: props.fakturaType,
        })
        if (response.data?.success) {
            const p = response.data.payload || {}
            history.value = { results: p.results || [], legacy: p.legacy || [], links: p.links || [], followups: p.followups || [], lookups: p.lookups || {} }
            loaded.value = true
            travelIndex.value = 0
        }
    } catch (e) {
        // Chronik ist Zusatzinformation — der Beleg bleibt bedienbar
    } finally {
        loading.value = false
    }
}

// Bei jedem Öffnen frisch laden: in der Zwischenzeit hat der Benutzer selbst geändert
watch(() => props.modelValue, (open) => { if (open) load() })
watch(() => props.fakturaId, () => { loaded.value = false; history.value = { results: [], legacy: [], links: [], followups: [], lookups: {} } })
// Offen und jemand ändert den Beleg (eigene Eingabe oder SSE-Reload): Chronik nachziehen
watch(() => props.current && props.current.last_change && props.current.last_change.itime, (now, before) => {
    if (props.modelValue && now && now !== before) load()
})

// ── Tabellen und Spalten ───────────────────────────────────────────────────
const HEAD_TABLES = ['oe', 'ar', 'ap', 'delivery_orders']
const EXT_TABLES = ['oe_ext', 'ar_ext']
const ITEM_TABLES = ['orderitems', 'invoice', 'delivery_order_items']
/** Kopfbeträge: erscheinen in der Zusammenfassung statt als Feldzeile */
const AMOUNT_KEYS = ['amount', 'netamount']
/** Positionsspalten der Zeitreise und der Kurzzeile */
const ITEM_KEYS = ['parts_id', 'description', 'qty', 'unit', 'sellprice', 'discount', 'longdescription']
/** Bearbeitungen derselben Person in diesem Abstand gehören zusammen (Faktura speichert je Feld) */
const EDIT_WINDOW_MS = 2 * 60 * 1000

function scope(table) {
    if (ITEM_TABLES.includes(table)) return 'item'
    if (EXT_TABLES.includes(table)) return 'ext'
    return 'head'
}

// ── Bearbeitungen: Diff-Zeilen zusammenfassen ──────────────────────────────
const editEventsAll = computed(() => {
    const list = []
    let current = null
    for (const row of history.value.results) {   // neueste zuerst
        const time = new Date(row.itime).getTime()
        const same = current
            && (current.employee_id ?? null) === (row.employee_id ?? null)
            && (current.source ?? null) === (row.source ?? null)
            && current.lastTime - time <= EDIT_WINDOW_MS
        if (same) {
            current.rows.push(row)
            current.lastTime = time
        } else {
            current = {
                key: 'e' + row.id, kind: 'edit', itime: row.itime, time, lastTime: time,
                employee_id: row.employee_id, employee_name: row.employee_name, source: row.source, rows: [row],
            }
            list.push(current)
        }
    }
    return list.map(buildEditEvent).filter(Boolean)
})

/** Netto-Änderungen einer Bearbeitung: je Feld erster alter und letzter neuer Wert */
function buildEditEvent(ev) {
    const chrono = [...ev.rows].reverse()
    const head = new Map()          // table|key -> {table, key, old, new}
    const items = new Map()         // row_id -> {...}
    let created = false
    let deleted = false
    for (const row of chrono) {
        const sc = scope(row.table_name)
        const changes = row.changes || {}
        if (sc === 'item') {
            const id = row.row_id
            let it = items.get(id)
            if (!it) {
                it = { id, label: row.row_label || '', inserted: false, deleted: false, before: {}, after: {} }
                items.set(id, it)
            }
            if (row.row_label) it.label = row.row_label
            if (row.action === 'INSERT') it.inserted = true
            if (row.action === 'DELETE') it.deleted = true
            for (const [key, ch] of Object.entries(changes)) {
                if (!(key in it.before)) it.before[key] = row.action === 'INSERT' ? null : ch.old
                it.after[key] = row.action === 'DELETE' ? null : ch.new
                if (row.action === 'DELETE') it.removedValues = { ...(it.removedValues || {}), [key]: ch.old }
            }
            continue
        }
        if (sc === 'head' && row.action === 'INSERT') created = true
        if (sc === 'head' && row.action === 'DELETE') deleted = true
        for (const [key, ch] of Object.entries(changes)) {
            const k = row.table_name + '|' + key
            if (!head.has(k)) head.set(k, { table: row.table_name, key, old: row.action === 'INSERT' ? null : ch.old, new: ch.new })
            else head.get(k).new = row.action === 'DELETE' ? null : ch.new
        }
    }

    const headChanges = [...head.values()].filter(c => created ? !isEmpty(c.new) : !sameValue(c.old, c.new))
    const itemList = []
    for (const it of items.values()) {
        if (it.inserted && it.deleted) continue   // kurz angelegt und wieder entfernt
        if (it.inserted) {
            itemList.push({ id: it.id, label: it.label, state: 'added', values: it.after, changes: [] })
        } else if (it.deleted) {
            itemList.push({ id: it.id, label: it.label, state: 'removed', values: { ...it.before, ...(it.removedValues || {}) }, changes: [] })
        } else {
            const changes = Object.keys(it.after)
                .filter(key => !sameValue(it.before[key], it.after[key]))
                .map(key => ({ key, old: it.before[key], new: it.after[key] }))
            if (changes.length) itemList.push({ id: it.id, label: it.label, state: 'changed', values: it.after, changes })
        }
    }
    if (!headChanges.length && !itemList.length && !created && !deleted) return null

    const event = { ...ev, created, deleted, head: headChanges, items: itemList }
    event.milestones = milestones(event)
    // Felder, die ein Etikett schon sagt, und die Beträge (stehen in der Zusammenfassung)
    event.covered = new Set([...AMOUNT_KEYS, ...event.milestones.flatMap(m => m.covers || [])])
    event.summary = summary(event)
    event.color = deleted ? 'error' : (created ? 'success' : 'primary')
    return event
}

/** Fachliche Wendepunkte als Etikett: angelegt, bestätigt, erledigt, bezahlt, storniert, gedruckt */
function milestones(ev) {
    const out = []
    const find = (key) => ev.head.find(c => c.key === key && HEAD_TABLES.includes(c.table))
    if (ev.created) out.push({ key: 'created', text: t('FakturaView.history.milestone.created'), color: 'success' })
    if (ev.deleted) out.push({ key: 'deleted', text: t('FakturaView.history.milestone.deleted'), color: 'error' })
    const rt = find('record_type')
    if (rt && !ev.created) {
        if (rt.new === 'sales_order' && rt.old === 'sales_order_intake') out.push({ key: 'confirmed', text: t('FakturaView.history.milestone.confirmed'), color: 'success', covers: ['record_type'] })
        else if (rt.new === 'sales_order_intake' && rt.old === 'sales_order') out.push({ key: 'unconfirmed', text: t('FakturaView.history.milestone.unconfirmed'), color: 'warning', covers: ['record_type'] })
    }
    const closed = find('closed')
    if (closed && !ev.created) {
        out.push(truthy(closed.new)
            ? { key: 'closed', text: t('FakturaView.history.milestone.closed'), color: 'success', covers: ['closed'] }
            : { key: 'reopened', text: t('FakturaView.history.milestone.reopened'), color: 'warning', covers: ['closed'] })
    }
    const paid = find('paid')
    if (paid && !ev.created) {
        const diff = Number(paid.new || 0) - Number(paid.old || 0)
        if (diff) out.push({ key: 'paid', text: t('FakturaView.history.milestone.paid', { amount: formatMoney(diff, true) }), color: diff > 0 ? 'success' : 'warning', covers: ['paid', 'datepaid'] })
    }
    const storno = find('storno')
    if (storno && truthy(storno.new)) out.push({ key: 'storno', text: t('FakturaView.history.milestone.storno'), color: 'error', covers: ['storno'] })
    const printed = ev.head.find(c => c.key === 'gedruckt' && EXT_TABLES.includes(c.table))
    if (printed && truthy(printed.new)) out.push({ key: 'printed', text: t('FakturaView.history.milestone.printed'), color: 'info', coversExt: ['gedruckt'] })
    return out
}

function summary(ev) {
    const parts = []
    const n = (state) => ev.items.filter(i => i.state === state).length
    if (n('added')) parts.push(t('FakturaView.history.summary.itemsAdded', { n: n('added') }, n('added')))
    if (n('changed')) parts.push(t('FakturaView.history.summary.itemsChanged', { n: n('changed') }, n('changed')))
    if (n('removed')) parts.push(t('FakturaView.history.summary.itemsRemoved', { n: n('removed') }, n('removed')))
    const amount = ev.head.find(c => c.key === 'amount' && HEAD_TABLES.includes(c.table))
    if (amount && !ev.created && !sameValue(amount.old, amount.new)) {
        parts.push(t('FakturaView.history.summary.amount', { old: formatMoney(amount.old), new: formatMoney(amount.new) }))
    }
    return parts.join(' · ')
}

/** Feldzeilen ohne das, was Zusammenfassung und Etiketten schon sagen */
function visibleHead(ev) {
    if (filterKind.value === 'items') return []
    return visibleHeadAll(ev)
}

// ── Weitere Ereignisse in derselben Zeitleiste ────────────────────────────
const LEGACY = {
    SAVED: 'saved', PRINTED: 'printed', POSTED: 'posted', 'PRINTED AND POSTED': 'printedPosted', SCREENED: 'screened',
    MAILED: 'mailed', DELETED: 'deleted', STORNO: 'storno', 'PAYMENT POSTED': 'paymentPosted', PREVIEWED: 'previewed', CANCELED: 'canceled',
}
const SENT_ICON = { email: 'mdi-email-outline', whatsapp: 'mdi-whatsapp', post: 'mdi-mailbox-up-outline', dhl: 'mdi-package-variant-closed' }

const otherEntries = computed(() => {
    const out = []
    for (const l of history.value.legacy) {
        const key = LEGACY[(l.addition || '').toUpperCase()]
        out.push({
            key: 'k' + l.id, kind: 'legacy', itime: l.itime, employee_name: l.employee_name, source: 'kivitendo',
            icon: 'mdi-archive-outline', color: 'grey',
            text: key ? t('FakturaView.history.legacy.' + key) : (l.addition || ''),
        })
    }
    for (const l of history.value.links) {
        const type = te('FakturaView.dokumentTypes.' + l.kind) ? t('FakturaView.dokumentTypes.' + l.kind) : l.kind
        out.push({
            key: 'l' + l.direction + l.record_table + l.record_id, kind: 'link', itime: l.itime,
            employee_name: l.employee_name, icon: l.direction === 'to' ? 'mdi-arrow-right-bold' : 'mdi-arrow-left-bold', color: 'teal',
            text: t(l.direction === 'to' ? 'FakturaView.history.link.to' : 'FakturaView.history.link.from', { type, number: l.number || l.record_id }),
            link: { record_table: l.record_table, record_id: l.record_id, kind: l.kind },
        })
    }
    for (const f of history.value.followups) {
        out.push({
            key: 'f' + f.id, kind: 'followup', itime: f.itime, employee_name: f.employee_name, icon: 'mdi-bell-ring-outline', color: 'amber-darken-2',
            text: t('FakturaView.history.followup.created', { subject: f.subject || '', date: formatDate(f.follow_up_date) }),
        })
        if (f.done_at) {
            out.push({
                key: 'fd' + f.id, kind: 'followup', itime: f.done_at, employee_name: f.done_by_name, icon: 'mdi-bell-check-outline', color: 'success',
                text: t('FakturaView.history.followup.done', { subject: f.subject || '' }),
            })
        }
    }
    const sent = (props.current && props.current.sent_log) || []
    sent.forEach((s, i) => {
        out.push({
            key: 's' + i + s.sent_at, kind: 'sent', itime: s.sent_at, employee_name: s.employee_name, icon: SENT_ICON[s.channel] || 'mdi-send', color: 'info',
            text: t('FakturaView.history.sent.' + (SENT_ICON[s.channel] ? s.channel : 'other'), { to: s.recipient || '' }),
            detail: s.subject || '',
        })
    })
    return out
})

const entries = computed(() => {
    const all = [...editEventsAll.value, ...otherEntries.value]
    all.sort((a, b) => new Date(b.itime) - new Date(a.itime))
    let idx = 0
    return all.map(e => ({ ...e, travelIndex: e.kind === 'edit' ? idx++ : null }))
})

const editEvents = computed(() => entries.value.filter(e => e.kind === 'edit'))

const filteredEntries = computed(() => {
    const q = (filterText.value || '').trim().toLowerCase()
    return entries.value.filter(e => {
        if (filterActor.value !== null && filterActor.value !== undefined && actorKey(e) !== filterActor.value) return false
        if (filterKind.value === 'head' && !(e.kind === 'edit' && (visibleHeadAll(e).length || e.milestones.length))) return false
        if (filterKind.value === 'items' && !(e.kind === 'edit' && e.items.length)) return false
        if (filterKind.value === 'events' && e.kind === 'edit' && !e.milestones.length) return false
        return !q || entryText(e).includes(q)
    })
})

function visibleHeadAll(e) {
    const extCovered = new Set((e.milestones || []).flatMap(m => m.coversExt || []))
    return e.head.filter(c => HEAD_TABLES.includes(c.table) ? !e.covered.has(c.key) : !extCovered.has(c.key))
}

function entryText(e) {
    const parts = [actorName(e), e.text || '', e.detail || '', e.summary || '', ...(e.milestones || []).map(m => m.text)]
    for (const c of e.head || []) parts.push(fieldLabel(c.key, c.table), formatValue(c.key, c.old, c.table), formatValue(c.key, c.new, c.table))
    for (const it of e.items || []) {
        parts.push(it.label, itemLine(it.values))
        for (const c of it.changes) parts.push(fieldLabel(c.key, 'item'), formatValue(c.key, c.old, 'item'), formatValue(c.key, c.new, 'item'))
    }
    return parts.join(' ').toLowerCase()
}

/** Filterschlüssel nach dem angezeigten Namen: Versand und Belegkette kennen keine Mitarbeiter-ID */
function actorKey(e) {
    return (e.kind === 'legacy' ? 'k:' : 'n:') + actorName(e)
}

const actorChoices = computed(() => {
    const seen = new Map()
    for (const e of entries.value) {
        if (actorName(e)) seen.set(actorKey(e), { title: actorName(e), value: actorKey(e) })
    }
    return [...seen.values()]
})

// ── Überblick ─────────────────────────────────────────────────────────────
const lastEdit = computed(() => editEvents.value[0] || null)
const createdInfo = computed(() => {
    const c = props.current && props.current.common
    if (!c || !c.itime) return ''
    return t('FakturaView.history.createdInfo', { time: formatDateTime(c.itime) })
})

// ── Zeitreise: Stand vor Bearbeitung N aus den Diffs zurückrechnen ───────
const travelLabel = computed(() => travelIndex.value === 0
    ? t('FakturaView.history.today')
    : t('FakturaView.history.stepsBack', { n: travelIndex.value }, travelIndex.value))

const travelDiff = computed(() => {
    const result = { head: [], items: [] }
    if (travelIndex.value <= 0) return result
    const now = { head: {}, items: new Map() }
    const common = (props.current && props.current.common) || {}
    for (const p of props.positions) now.items.set(p.id, pick(p))
    const then = { head: {}, items: new Map([...now.items].map(([id, v]) => [id, { ...v }])) }
    const touched = new Map()   // table|key -> {table, key}

    // Ereignisse neueste zuerst: bis einschließlich dem gewählten rückgängig machen
    for (let i = 0; i < travelIndex.value; i++) {
        for (const row of editEvents.value[i].rows) {   // innerhalb neueste zuerst
            const sc = scope(row.table_name)
            const changes = row.changes || {}
            if (sc === 'item') {
                const id = row.row_id
                if (row.action === 'INSERT') { then.items.delete(id); continue }
                const it = then.items.get(id) || { description: row.row_label || '' }
                for (const [key, ch] of Object.entries(changes)) if (ITEM_KEYS.includes(key)) it[key] = ch.old
                then.items.set(id, it)
                continue
            }
            for (const [key, ch] of Object.entries(changes)) {
                const k = row.table_name + '|' + key
                if (!touched.has(k)) {
                    touched.set(k, { table: row.table_name, key })
                    // „heute“ der Erweiterung: neuester Wert aus den Diffs (nicht in faktura.data)
                    now.head[k] = sc === 'head' && key in common ? common[key] : ch.new
                }
                then.head[k] = row.action === 'INSERT' ? null : ch.old
            }
        }
    }
    for (const [k, { table, key }] of touched) {
        if (!sameValue(then.head[k], now.head[k])) result.head.push({ table, key, then: then.head[k], now: now.head[k] })
    }
    const ids = new Set([...now.items.keys(), ...then.items.keys()])
    for (const id of ids) {
        const a = then.items.get(id)
        const b = now.items.get(id)
        if (a && !b) result.items.push({ id, label: a.description || '', state: 'removed', changes: [] })
        else if (!a && b) result.items.push({ id, label: b.description || '', state: 'added', changes: [] })
        else {
            const changes = ITEM_KEYS.filter(k => !sameValue(a[k], b[k])).map(k => ({ key: k, then: a[k], now: b[k] }))
            if (changes.length) result.items.push({ id, label: b.description || a.description || '', state: 'changed', changes })
        }
    }
    return result
})

function pick(p) {
    const out = {}
    for (const k of ITEM_KEYS) out[k] = p[k] ?? null
    return out
}

// ── Beschriftungen und Werte ──────────────────────────────────────────────
/** Spalte → vorhandener Text der Faktura-Maske (FakturaView.faktura.*) */
const FAKTURA_LABELS = {
    ordnumber: 'orderNumber', quonumber: 'quoteNumber', invnumber: 'invoiceNumber', donumber: 'deliveryOrderNumber',
    cusordnumber: 'customerOrderNumber', transdate: 'fakturaDate', reqdate: 'deliveryDate', deliverydate: 'deliveryDate',
    duedate: 'dueDate', customer_id: 'customer', vendor_id: 'vendor', shipto_id: 'deliveryAddress',
    billing_address_id: 'billingAddress', language_id: 'language', department_id: 'department', currency_id: 'currency',
    taxzone_id: 'taxZone', taxincluded: 'taxIncluded', shippingpoint: 'shippingPoint', shipvia: 'shipVia',
    transaction_description: 'transactionDescription', payment_id: 'paymentTerms', delivery_term_id: 'deliveryTerms',
    direct_debit: 'directDebit', employee_id: 'employee', salesman_id: 'soldBy', notes: 'publicNotes',
    intnotes: 'internalNotes', amount: 'grossAmount', netamount: 'netAmount', closed: 'status',
    description: 'description', longdescription: 'longDescription', qty: 'quantity', unit: 'unit',
    sellprice: 'price', discount: 'discount',
}
/** LxCars-Erweiterung (oe_ext/ar_ext) → FakturaView.faktura.* */
const EXT_LABELS = {
    km_stand: 'kmStand', kfz_ort: 'kfzOrt', status: 'status', gedruckt: 'gedruckt', intern: 'intern',
    bringetermin: 'bringetermin', fertigstellung: 'fertigstellung', c_id: 'licensePlate', kennzeichen: 'licensePlate',
}

function fieldLabel(key, table) {
    if (EXT_TABLES.includes(table) && EXT_LABELS[key]) return t('FakturaView.faktura.' + EXT_LABELS[key])
    if (key === 'parts_id') return t('FakturaView.history.fields.parts_id')
    if (FAKTURA_LABELS[key]) return t('FakturaView.faktura.' + FAKTURA_LABELS[key])
    const own = 'FakturaView.history.fields.' + key
    return te(own) ? t(own) : key
}

/** ID-Spalte → Klartext-Tabelle aus getFakturaHistory (lookups) */
const LOOKUP_OF = {
    employee_id: 'employee', salesman_id: 'employee', orderer_id: 'employee', customer_id: 'customer', vendor_id: 'vendor',
    shipto_id: 'shipto', billing_address_id: 'billing_address', cp_id: 'contact', parts_id: 'part', project_id: 'project',
    globalproject_id: 'project', payment_id: 'payment', delivery_term_id: 'delivery_term', taxzone_id: 'taxzone',
    currency_id: 'currency', language_id: 'language', department_id: 'department', dunning_config_id: 'dunning',
    price_factor_id: 'price_factor', pricegroup_id: 'pricegroup', storno_id: 'storno', c_id: 'car',
}
const MONEY_KEYS = ['sellprice', 'amount', 'netamount', 'paid', 'lastcost']
const DATE_KEYS = ['transdate', 'reqdate', 'deliverydate', 'duedate', 'datepaid', 'tax_point', 'expected_billing_date', 'fertigstellung']
const DATETIME_KEYS = ['bringetermin']

function formatValue(key, value, table) {
    if (isEmpty(value)) return '–'
    if (key === 'closed') return truthy(value) ? t('FakturaView.faktura.statusClosed') : t('FakturaView.faktura.statusOpen')
    if (key === 'record_type' || key === 'order_type') {
        const k = 'FakturaView.history.recordType.' + value
        return te(k) ? t(k) : String(value)
    }
    if (value === true || value === false || value === 't' || value === 'f') {
        return truthy(value) ? t('FakturaView.history.yes') : t('FakturaView.history.no')
    }
    if (LOOKUP_OF[key]) {
        const map = (history.value.lookups || {})[LOOKUP_OF[key]] || {}
        return map[String(value)] || ('#' + value)
    }
    if (MONEY_KEYS.includes(key)) return formatMoney(value)
    if (key === 'discount') return formatNumber(Number(value) * 100, 2) + ' %'
    if (key === 'qty' || key === 'km_stand') return formatNumber(value, key === 'qty' ? 3 : 0)
    // fertigstellung: Zeitstempel in oe_ext, Datum in ar_ext
    if (key === 'fertigstellung' && table === 'oe_ext') return formatDateTime(value)
    if (DATE_KEYS.includes(key)) return formatDate(value)
    if (DATETIME_KEYS.includes(key)) return formatDateTime(value)
    if (typeof value === 'object') return JSON.stringify(value)
    const text = String(value)
    return text.length > 300 ? text.slice(0, 300) + ' …' : text
}

/** Kurzzeile einer hinzugefügten/entfernten Position: „2 Stck × 45,50 € · Rabatt 10 %“ */
function itemLine(values) {
    const v = values || {}
    const parts = []
    if (!isEmpty(v.qty)) parts.push(`${formatNumber(v.qty, 3)}${v.unit ? ' ' + v.unit : ''} × ${formatMoney(v.sellprice || 0)}`)
    if (Number(v.discount)) parts.push(`${t('FakturaView.faktura.discount')} ${formatNumber(Number(v.discount) * 100, 2)} %`)
    if (v.parts_id && history.value.lookups?.part?.[String(v.parts_id)]) parts.push(history.value.lookups.part[String(v.parts_id)])
    return parts.join(' · ')
}

function actorName(e) {
    if (e.employee_name) return e.employee_name
    if (e.kind === 'legacy') return 'kivitendo'
    if (e.source && String(e.source).startsWith('cron')) return t('FakturaView.history.automatic')
    if (e.source === 'portal') return t('FakturaView.history.source.portal')
    if (e.kind === 'edit' && !e.source) return t('FakturaView.history.external')
    // Versand/Belegkette ohne Mitarbeiter (z. B. WhatsApp): kein Name statt „Unbekannt“
    return e.kind === 'edit' ? t('FakturaView.history.unknownEmployee') : ''
}

function sourceLabel(e) {
    if (e.kind === 'legacy') return 'kivitendo'
    if (e.kind !== 'edit') return ''
    if (!e.source) return e.employee_name ? t('FakturaView.history.source.external') : ''
    if (e.source === 'cron:recurring') return t('FakturaView.history.source.recurring')
    if (e.source === 'cron:ebay') return t('FakturaView.history.source.ebay')
    if (e.source === 'portal') return t('FakturaView.history.source.portal')
    return ''
}

function isEmpty(v) { return v === null || v === undefined || v === '' }
function truthy(v) { return v === true || v === 't' || v === 'true' || v === 1 }
function sameValue(a, b) {
    const na = isEmpty(a) ? null : a
    const nb = isEmpty(b) ? null : b
    // Zahlen nur als Zahlen vergleichen (2.20 = 2.2); Texte wie „007“ und „7“ bleiben verschieden
    if (typeof na === 'number' && typeof nb === 'number') return na === nb
    return JSON.stringify(na) === JSON.stringify(nb)
}

function formatNumber(value, digits) {
    const n = Number(value)
    if (Number.isNaN(n)) return String(value)
    return n.toLocaleString(locale.value, { minimumFractionDigits: 0, maximumFractionDigits: digits })
}
function formatMoney(value, signed = false) {
    const n = Number(value || 0)
    const text = n.toLocaleString(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
    return signed && n > 0 ? '+' + text : text
}
function formatDate(value) {
    if (!value) return ''
    const [y, m, d] = String(value).slice(0, 10).split('-').map(Number)
    if (!y || !m || !d) return String(value)
    return new Date(y, m - 1, d).toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' })
}
function formatDateTime(value) {
    if (!value) return ''
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
function relativeTime(value) {
    return formatRelativeTime(value, locale.value)
}
function initials(name) {
    return (name || '?').split(/\s+/).map(p => p[0]).filter(Boolean).slice(0, 2).join('').toUpperCase()
}

</script>

<style scoped>
.history-drawer {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.history-drawer__head {
    padding: var(--oserp-gap) var(--oserp-gap-lg);
    background: var(--oserp-section-head-bg);
    border-bottom: 1px solid var(--oserp-border-soft);
}

.history-drawer__body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: var(--oserp-gap-lg);
}

.history-drawer--full {
    width: 100% !important;
}

.history-filter {
    flex: 1 1 200px;
}

.time-travel {
    padding: var(--oserp-gap) var(--oserp-gap-lg);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius);
    background: var(--oserp-surface-soft);
}

.avatar-initials {
    font-size: 0.65rem;
    font-weight: 600;
    color: white;
}

.history-entry {
    padding-bottom: var(--oserp-gap-sm);
    min-width: 0;
    overflow-wrap: break-word;
}

.history-item__label {
    min-width: 0;
    overflow-wrap: break-word;
}

/* Zeitleiste in der schmalen Schublade: Inhalt darf nicht breiter als der Platz werden */
.history-drawer__body :deep(.v-timeline-item__body) {
    min-width: 0;
}

.history-entry--travel .history-entry {
    border-left: 3px solid rgb(var(--v-theme-primary));
    padding-left: var(--oserp-gap-sm);
}

.history-link {
    color: rgb(var(--v-theme-primary));
    text-decoration: none;
}

.history-link:hover {
    text-decoration: underline;
}

.change-table {
    border-collapse: collapse;
    font-size: 0.875rem;
}

.change-table td {
    padding: 1px var(--oserp-gap-sm) 1px 0;
    vertical-align: top;
}

.change-table__label {
    color: var(--oserp-text-muted);
    white-space: nowrap;
}

.change-table__old {
    text-decoration: line-through;
    color: var(--oserp-text-muted);
    overflow-wrap: break-word;
}

.change-table__new {
    font-weight: 500;
    overflow-wrap: break-word;
}

.change-table__arrow {
    color: var(--oserp-text-muted);
}
</style>
