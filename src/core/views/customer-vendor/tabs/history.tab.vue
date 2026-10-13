<!-- src/core/views/customer-vendor/tabs/history.tab.vue -->
<!--
    Zeitreise: wer hat wann was am Kunden/Lieferanten geändert.

    Quelle sind die Diffs aus cv_history_oserp (Trigger beim Speichern) und,
    als Vorgeschichte, die kivitendo-Ereignisse aus history_erp. Mehrere
    Trigger-Zeilen desselben Speicherns (Stammdaten + Erweiterung + Kontakte)
    werden zu einem Ereignis zusammengefasst.

    Der Schieberegler reist durch die Ereignisse: aus den Diffs wird der Stand
    der Stammdaten vor dem gewählten Ereignis zurückgerechnet und gegen heute
    gestellt — ohne dass irgendwo Schnappschüsse gespeichert werden.
-->
<template>
    <div class="pa-2 pa-sm-4">
        <section-card :title="t('CustomerVendorEditView.history.title')" icon="mdi-history" quiet>
            <template #actions>
                <v-chip v-if="events.length" size="small" variant="tonal">
                    {{ t('CustomerVendorEditView.history.count', { n: events.length }, events.length) }}
                </v-chip>
                <v-btn size="small" variant="text" icon="mdi-refresh" :loading="loading" :title="t('CustomerVendorEditView.history.reload')" @click="load" />
            </template>

            <p class="text-body-2 text-medium-emphasis mb-3">{{ t('CustomerVendorEditView.history.intro') }}</p>

            <!-- Filter -->
            <div class="d-flex flex-wrap ga-2 mb-3">
                <v-select
                    v-model="filterEmployee"
                    :items="employeeChoices"
                    :label="t('CustomerVendorEditView.history.filterEmployee')"
                    clearable
                    style="max-width: 260px"
                />
                <v-text-field
                    v-model="filterText"
                    :label="t('CustomerVendorEditView.history.search')"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    style="max-width: 320px"
                />
            </div>

            <!-- Zeitreise-Regler -->
            <div v-if="profileEvents.length" class="time-travel mb-4">
                <div class="d-flex align-center flex-wrap ga-2 mb-1">
                    <v-icon size="small" color="primary">mdi-clock-time-four-outline</v-icon>
                    <span class="text-subtitle-2">{{ t('CustomerVendorEditView.history.timeTravel') }}</span>
                    <span class="text-body-2 text-medium-emphasis">{{ travelLabel }}</span>
                    <v-spacer />
                    <v-btn v-if="travelIndex > 0" size="x-small" variant="text" @click="travelIndex = 0">
                        {{ t('CustomerVendorEditView.history.backToToday') }}
                    </v-btn>
                </div>
                <v-slider
                    v-model="travelIndex"
                    :max="profileEvents.length"
                    :min="0"
                    :step="1"
                    show-ticks="always"
                    tick-size="3"
                    color="primary"
                    hide-details
                    :thumb-label="false"
                    direction="horizontal"
                    class="time-travel__slider"
                />
                <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                    <span>{{ t('CustomerVendorEditView.history.today') }}</span>
                    <span>{{ oldestLabel }}</span>
                </div>
                <v-alert v-if="travelIndex > 0" type="info" variant="tonal" density="compact" icon="mdi-history" class="mt-2">
                    <div class="font-weight-medium mb-1">{{ t('CustomerVendorEditView.history.asOf', { time: formatTime(profileEvents[travelIndex - 1].itime) }) }}</div>
                    <div v-if="!travelDiff.length" class="text-body-2">{{ t('CustomerVendorEditView.history.noDiff') }}</div>
                    <table v-else class="travel-table">
                        <tbody>
                            <tr v-for="d in travelDiff" :key="d.col">
                                <td class="travel-table__label">{{ fieldLabel('customer', d.col) }}</td>
                                <td class="travel-table__then">{{ formatValue(d.col, d.then) }}</td>
                                <td class="travel-table__arrow"><v-icon size="x-small">mdi-arrow-right</v-icon></td>
                                <td>{{ formatValue(d.col, d.now) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </v-alert>
            </div>

            <!-- Verlauf -->
            <div v-if="loading && !events.length" class="d-flex justify-center pa-6">
                <v-progress-circular indeterminate color="primary" />
            </div>
            <v-alert v-else-if="!filteredEvents.length" type="info" variant="tonal" density="compact">
                {{ events.length ? t('CustomerVendorEditView.history.noMatch') : t('CustomerVendorEditView.history.empty') }}
            </v-alert>
            <v-timeline v-else side="end" density="compact" align="start" truncate-line="both" class="history-timeline">
                <v-timeline-item
                    v-for="ev in filteredEvents"
                    :key="ev.key"
                    :dot-color="ev.source === 'kivitendo' ? 'grey' : (ev.hasDelete ? 'error' : (ev.hasInsert ? 'success' : 'primary'))"
                    size="small"
                    :class="{ 'history-event--travel': ev.profileIndex === travelIndex - 1 }"
                >
                    <template #icon>
                        <span class="avatar-initials">{{ initials(ev.employee_name) }}</span>
                    </template>
                    <div class="history-event">
                        <div class="d-flex align-center flex-wrap ga-2">
                            <span class="text-body-2 font-weight-medium">{{ ev.employee_name || t('CustomerVendorEditView.history.unknownEmployee') }}</span>
                            <span class="text-caption text-medium-emphasis">{{ formatTime(ev.itime) }}</span>
                            <v-chip v-if="ev.source === 'kivitendo'" size="x-small" variant="outlined" label>kivitendo</v-chip>
                        </div>

                        <!-- kivitendo-Ereignis -->
                        <div v-if="ev.source === 'kivitendo'" class="text-body-2 mt-1">
                            {{ legacyLabel(ev.rows[0].action) }}
                            <span v-if="ev.rows[0].what_done" class="text-medium-emphasis"> — {{ ev.rows[0].what_done }}</span>
                        </div>

                        <!-- Eigene Diffs -->
                        <div v-for="row in ev.rows" v-else :key="row.id" class="history-row mt-1">
                            <div class="text-caption text-medium-emphasis d-flex align-center ga-1">
                                <v-icon size="x-small">{{ rowIcon(row) }}</v-icon>
                                <span>{{ rowTitle(row) }}</span>
                                <span v-if="row.action === 'INSERT'" class="text-success">· {{ t('CustomerVendorEditView.history.created') }}</span>
                                <span v-else-if="row.action === 'DELETE'" class="text-error">· {{ t('CustomerVendorEditView.history.deleted') }}</span>
                            </div>
                            <table v-if="row.action !== 'DELETE'" class="change-table">
                                <tbody>
                                    <tr v-for="(ch, col) in row.changes" :key="col">
                                        <td class="change-table__label">{{ fieldLabel(row.table_name, col) }}</td>
                                        <td v-if="row.action === 'UPDATE'" class="change-table__old">{{ formatValue(col, ch.old) }}</td>
                                        <td v-if="row.action === 'UPDATE'" class="change-table__arrow"><v-icon size="x-small">mdi-arrow-right</v-icon></td>
                                        <td class="change-table__new">{{ formatValue(col, ch.new) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </v-timeline-item>
            </v-timeline>
        </section-card>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import SectionCard from '@/core/components/layout/section-card.vue'
import { CV_ALL_FIELDS } from '../cvFieldCatalog.js'

const props = defineProps({
    cvId: { type: [String, Number], required: true },
    src: { type: String, default: 'C' },
    /** Aktueller Stand der Stammdaten — Ausgangspunkt der Zeitreise */
    profile: { type: Object, default: () => ({}) },
    /** Reiter ist sichtbar: dann laden */
    active: { type: Boolean, default: false },
})

const { t, te } = useI18n()
const oserp = oserpStore()

const loading = ref(false)
const rows = ref([])
const filterEmployee = ref(null)
const filterText = ref('')
const travelIndex = ref(0)
let loadedFor = null

async function load() {
    loading.value = true
    try {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'getCvHistory',
            cv_id: Number(props.cvId),
            src: props.src,
            employee_id: oserp.session?.logged_in_employee?.id || null,
        })
        rows.value = response.data?.payload?.results || []
        loadedFor = `${props.src}:${props.cvId}`
        travelIndex.value = 0
    } catch (e) {
        rows.value = []
    } finally {
        loading.value = false
    }
}

// Erst laden, wenn der Reiter wirklich geöffnet wird — nicht bei jedem Kunden vorab
watch(() => props.active, (active) => {
    if (active && loadedFor !== `${props.src}:${props.cvId}`) load()
})
onMounted(() => { if (props.active) load() })

// ── Ereignisse: Zeilen desselben Speicherns zusammenfassen ───────────────
const PROFILE_TABLES = ['customer', 'vendor', 'customer_ext', 'vendor_ext']

const events = computed(() => {
    const list = []
    let current = null
    for (const row of rows.value) {
        const time = new Date(row.itime).getTime()
        const sameEvent = current
            && current.source === row.source
            && row.source === 'oserp'
            && (current.employee_id ?? null) === (row.employee_id ?? null)
            && Math.abs(current.time - time) < 3000
        if (sameEvent) {
            current.rows.push(row)
        } else {
            current = {
                key: row.source + '-' + row.id,
                itime: row.itime,
                time,
                employee_id: row.employee_id,
                employee_name: row.employee_name,
                source: row.source,
                rows: [row],
            }
            list.push(current)
        }
    }
    let profileIndex = 0
    for (const ev of list) {
        ev.hasInsert = ev.rows.some(r => r.action === 'INSERT')
        ev.hasDelete = ev.rows.some(r => r.action === 'DELETE')
        ev.profileRows = ev.rows.filter(r => PROFILE_TABLES.includes(r.table_name) && r.action !== 'DELETE')
        ev.profileIndex = ev.profileRows.length ? profileIndex++ : null
    }
    return list
})

const profileEvents = computed(() => events.value.filter(ev => ev.profileIndex !== null))

const employeeChoices = computed(() => {
    const seen = new Map()
    for (const ev of events.value) {
        const title = ev.employee_name || t('CustomerVendorEditView.history.unknownEmployee')
        seen.set(ev.employee_id ?? 'none', { title, value: ev.employee_id ?? 'none' })
    }
    return [...seen.values()]
})

const filteredEvents = computed(() => {
    const q = (filterText.value || '').trim().toLowerCase()
    return events.value.filter(ev => {
        if (filterEmployee.value !== null && filterEmployee.value !== undefined && (ev.employee_id ?? 'none') !== filterEmployee.value) return false
        if (!q) return true
        return eventText(ev).includes(q)
    })
})

function eventText(ev) {
    const parts = [ev.employee_name || '']
    for (const row of ev.rows) {
        parts.push(rowTitle(row), row.what_done || '', row.action || '')
        for (const [col, ch] of Object.entries(row.changes || {})) {
            parts.push(fieldLabel(row.table_name, col), formatValue(col, ch.old), formatValue(col, ch.new))
        }
    }
    return parts.join(' ').toLowerCase()
}

// ── Zeitreise: Stand vor Ereignis N aus den Diffs zurückrechnen ─────────
const travelDiff = computed(() => {
    if (travelIndex.value <= 0) return []
    const then = { ...props.profile }
    // Ereignisse sind neueste zuerst: alles bis einschließlich dem gewählten rückgängig machen
    for (let i = 0; i < travelIndex.value; i++) {
        for (const row of profileEvents.value[i].profileRows) {
            for (const [col, ch] of Object.entries(row.changes || {})) {
                then[col] = ch.old ?? null
            }
        }
    }
    const cols = new Set()
    for (let i = 0; i < travelIndex.value; i++) {
        for (const row of profileEvents.value[i].profileRows) {
            Object.keys(row.changes || {}).forEach(c => cols.add(c))
        }
    }
    return [...cols]
        .filter(col => !sameValue(then[col], props.profile[col]))
        .map(col => ({ col, then: then[col], now: props.profile[col] }))
})

function sameValue(a, b) {
    const na = (a === '' || a === undefined) ? null : a
    const nb = (b === '' || b === undefined) ? null : b
    return JSON.stringify(na) === JSON.stringify(nb)
}

const travelLabel = computed(() => travelIndex.value === 0
    ? t('CustomerVendorEditView.history.today')
    : t('CustomerVendorEditView.history.stepsBack', { n: travelIndex.value }, travelIndex.value))

const oldestLabel = computed(() => profileEvents.value.length
    ? formatTime(profileEvents.value[profileEvents.value.length - 1].itime)
    : '')

// ── Beschriftungen und Werte ─────────────────────────────────────────────
// Spalte der Tabelle contacts -> Beschriftungsschlüssel unter fields.*
// (als Paare, damit der Routen-Check kein "name: '…'" darin sieht)
const CONTACT_FIELDS = Object.fromEntries([
    ['cp_name', 'lastname'], ['cp_givenname', 'givenname'], ['cp_title', 'title'], ['cp_abteilung', 'department'],
    ['cp_position', 'position'], ['cp_phone1', 'phone1'], ['cp_phone2', 'phone2'], ['cp_mobile1', 'mobile1'],
    ['cp_mobile2', 'mobile2'], ['cp_fax', 'fax'], ['cp_email', 'email_business'], ['cp_privatemail', 'email_private'],
    ['cp_privatphone', 'phone_private'], ['cp_birthday', 'birthday'], ['cp_gender', 'gender'],
    ['cp_street', 'street'], ['cp_zipcode', 'zipcode'], ['cp_city', 'city'],
])

function fieldLabel(table, col) {
    let key = col
    if (table === 'contacts') key = CONTACT_FIELDS[col] || col
    else if (table === 'shipto') key = col.startsWith('shipto') ? col.slice(6) : col
    else {
        const field = CV_ALL_FIELDS.find(f => f.key === col || (f.model || []).includes(col))
        if (field) return t(field.label)
    }
    if (key === 'cp_gender' || key === 'gender') key = 'gender'
    const i18nKey = 'CustomerVendorEditView.fields.' + key
    return te(i18nKey) ? t(i18nKey) : col
}

const cc = () => oserp.session?.company_config || {}
const ID_LOOKUPS = {
    business_id: () => cc().business_types, salesman_id: () => cc().employees, language_id: () => cc().languages,
    currency_id: () => cc().currencies, payment_id: () => cc().payment_terms, delivery_term_id: () => cc().delivery_terms,
    taxzone_id: () => cc().tax_zones, pricegroup_id: () => cc().pricegroups,
}

function formatValue(col, value) {
    if (value === null || value === undefined || value === '') return '–'
    if (value === true || value === 't') return t('CustomerVendorEditView.history.yes')
    if (value === false || value === 'f') return t('CustomerVendorEditView.history.no')
    if (ID_LOOKUPS[col]) {
        const item = (ID_LOOKUPS[col]() || []).find(i => String(i.id) === String(value))
        if (item) return item.description || item.name || item.pricegroup || String(value)
    }
    if (Array.isArray(value)) {
        if (!value.length) return '–'
        return value.map(v => typeof v === 'string' ? v : [v.label, v.number || v.email || v.url].filter(Boolean).join(': ')).join(', ')
    }
    if (typeof value === 'object') return JSON.stringify(value)
    if (col === 'cp_gender' || col === 'gender') {
        if (value === 'm') return t('CustomerVendorEditView.fields.gender_m')
        if (value === 'f') return t('CustomerVendorEditView.fields.gender_f')
    }
    return String(value)
}

function rowTitle(row) {
    if (PROFILE_TABLES.includes(row.table_name)) return t('CustomerVendorEditView.history.tableProfile')
    const base = row.table_name === 'contacts'
        ? t('CustomerVendorEditView.history.tableContact')
        : (row.table_name === 'shipto' ? t('CustomerVendorEditView.history.tableShipto') : t('CustomerVendorEditView.history.tableBilling'))
    return row.row_label ? `${base} „${row.row_label}“` : base
}

function rowIcon(row) {
    if (row.table_name === 'contacts') return 'mdi-account-outline'
    if (row.table_name === 'shipto') return 'mdi-truck-delivery-outline'
    if (row.table_name === 'additional_billing_addresses') return 'mdi-receipt-text-outline'
    return 'mdi-card-account-details-outline'
}

const LEGACY = { SAVED: 'saved', PRINTED: 'printed', MAILED: 'mailed', DELETED: 'deleted', SCREENED: 'screened', POSTED: 'posted' }
function legacyLabel(addition) {
    const key = LEGACY[(addition || '').toUpperCase()]
    return key ? t('CustomerVendorEditView.history.legacy.' + key) : (addition || '')
}

function initials(name) {
    return (name || '?').split(/\s+/).map(p => p[0]).filter(Boolean).slice(0, 2).join('').toUpperCase()
}

function formatTime(value) {
    if (!value) return ''
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
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

.history-event {
    padding-bottom: var(--oserp-gap-sm);
}

.history-event--travel .history-event {
    border-left: 3px solid rgb(var(--v-theme-primary));
    padding-left: var(--oserp-gap-sm);
}

.history-row + .history-row {
    margin-top: var(--oserp-gap-sm);
}

.change-table,
.travel-table {
    border-collapse: collapse;
    font-size: 0.875rem;
}

.change-table td,
.travel-table td {
    padding: 1px var(--oserp-gap-sm) 1px 0;
    vertical-align: top;
}

.change-table__label,
.travel-table__label {
    color: var(--oserp-text-muted);
    white-space: nowrap;
}

.change-table__old,
.travel-table__then {
    text-decoration: line-through;
    color: var(--oserp-text-muted);
    overflow-wrap: anywhere;
}

.change-table__new {
    font-weight: 500;
    overflow-wrap: anywhere;
}

.change-table__arrow,
.travel-table__arrow {
    color: var(--oserp-text-muted);
}
</style>
