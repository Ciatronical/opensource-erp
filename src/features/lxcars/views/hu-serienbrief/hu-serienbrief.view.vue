<!-- src/features/lxcars/views/hu-serienbrief/hu-serienbrief.view.vue -->
<template>
    <NavbarView :message="message" :messages="messages" />

    <v-container class="pt-2 px-2 px-sm-4" fluid>
        <!-- Header -->
        <v-row class="mb-2 align-center">
            <v-col>
                <h1 class="text-h5 text-sm-h4 d-flex align-center">
                    <v-icon class="me-2" color="primary">mdi-car-clock</v-icon>
                    {{ t('HuSerienbriefView.title') }}
                    <v-chip
                        v-if="customers.length"
                        class="ms-3"
                        size="small"
                        variant="tonal"
                        color="primary"
                    >
                        {{ customers.length }} {{ t('HuSerienbriefView.chips.results_count') }}
                    </v-chip>
                </h1>
            </v-col>
            <v-col cols="auto">
                <v-btn
                    variant="text"
                    prepend-icon="mdi-refresh"
                    size="small"
                    @click="loadData"
                >
                    {{ t('HuSerienbriefView.buttons.reload') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Filterleiste -->
        <v-card variant="outlined" class="mb-3 pa-3">
            <v-row align="center" dense>
                <v-col cols="auto">
                    <v-icon color="primary" size="small" class="me-1">mdi-filter-outline</v-icon>
                    <span class="text-body-2 font-weight-medium">{{ t('HuSerienbriefView.filter.title') }}</span>
                </v-col>
                <v-col cols="auto">
                    <v-text-field
                        v-model="dateFrom"
                        :label="t('HuSerienbriefView.fields.date_from')"
                        type="date"
                        hide-details
                        density="compact"
                        variant="outlined"
                        style="min-width: 170px"
                        @update:model-value="loadData"
                    />
                </v-col>
                <v-col cols="auto" class="text-body-2 text-medium-emphasis px-1">
                    &mdash;
                </v-col>
                <v-col cols="auto">
                    <v-text-field
                        v-model="dateTo"
                        :label="t('HuSerienbriefView.fields.date_to')"
                        type="date"
                        hide-details
                        density="compact"
                        variant="outlined"
                        style="min-width: 170px"
                        @update:model-value="loadData"
                    />
                </v-col>
                <v-col cols="auto">
                    <v-checkbox
                        v-model="showExcluded"
                        :label="t('HuSerienbriefView.checkboxes.show_excluded')"
                        hide-details
                        density="compact"
                    />
                </v-col>
            </v-row>
            <div class="text-caption text-medium-emphasis d-flex align-center mt-1">
                <v-icon size="x-small" color="success" class="me-1">mdi-bell-ring-outline</v-icon>
                {{ t('HuSerienbriefView.hint.bells') }}
            </div>
        </v-card>

        <!-- Aktionsleiste -->
        <v-sheet v-if="recipientIds.length" color="transparent" class="d-flex flex-wrap ga-2 mb-3 align-center">
            <span class="text-body-2 text-medium-emphasis me-1">
                {{ t('HuSerienbriefView.actionbar.recipients', { count: recipientIds.length }) }}
            </span>
            <v-btn
                variant="flat"
                color="primary"
                prepend-icon="mdi-printer"
                size="small"
                :loading="pdfLoading"
                @click="printLetters(recipientIds)"
            >
                {{ t('HuSerienbriefView.buttons.print_all') }}
            </v-btn>
            <v-btn
                variant="tonal"
                color="primary"
                prepend-icon="mdi-email-fast"
                size="small"
                @click="showBrevoDialog = true"
            >
                {{ t('HuSerienbriefView.buttons.email_all') }}
            </v-btn>
            <v-btn
                variant="tonal"
                color="green"
                prepend-icon="mdi-whatsapp"
                size="small"
                :loading="whatsappLoading"
                @click="sendWhatsAppAll"
            >
                {{ t('HuSerienbriefView.buttons.whatsapp_all') }} ({{ recipientWithPhone.length }})
            </v-btn>
            <v-btn
                variant="tonal"
                color="secondary"
                prepend-icon="mdi-upload"
                size="small"
                :loading="sftpLoading"
                @click="sendViaSftp"
            >
                {{ t('HuSerienbriefView.buttons.send_pin') }}
            </v-btn>
        </v-sheet>

        <!-- Sortierung -->
        <div v-if="!loading && customers.length" class="d-flex align-center flex-wrap ga-2 mb-2">
            <span class="text-body-2 text-medium-emphasis d-inline-flex align-center">
                <v-icon size="small" class="me-1">mdi-sort</v-icon>{{ t('HuSerienbriefView.sort.label') }}
            </span>
            <v-btn-group density="compact" variant="outlined" divided>
                <v-btn
                    size="small"
                    prepend-icon="mdi-star-circle"
                    :color="sortBy === 'best' ? 'primary' : undefined"
                    :variant="sortBy === 'best' ? 'flat' : 'outlined'"
                    @click="setSort('best')"
                >
                    {{ t('HuSerienbriefView.sort.best') }}
                </v-btn>
                <v-btn
                    size="small"
                    :color="sortBy === 'revenue' ? 'primary' : undefined"
                    :variant="sortBy === 'revenue' ? 'flat' : 'outlined'"
                    :title="t('HuSerienbriefView.sort.toggle_hint')"
                    @click="setSort('revenue')"
                >
                    <v-icon start>mdi-cash</v-icon>
                    {{ t('HuSerienbriefView.sort.revenue') }}
                    <v-icon v-if="sortBy === 'revenue'" end>{{ sortDir === 'desc' ? 'mdi-arrow-down' : 'mdi-arrow-up' }}</v-icon>
                </v-btn>
                <v-btn
                    v-if="hasDistances"
                    size="small"
                    :color="sortBy === 'distance' ? 'primary' : undefined"
                    :variant="sortBy === 'distance' ? 'flat' : 'outlined'"
                    :title="t('HuSerienbriefView.sort.toggle_hint')"
                    @click="setSort('distance')"
                >
                    <v-icon start>mdi-map-marker-distance</v-icon>
                    {{ t('HuSerienbriefView.sort.distance') }}
                    <v-icon v-if="sortBy === 'distance'" end>{{ sortDir === 'desc' ? 'mdi-arrow-down' : 'mdi-arrow-up' }}</v-icon>
                </v-btn>
            </v-btn-group>
        </div>

        <!-- Ladeanzeige -->
        <v-card v-if="loading" variant="outlined" class="rounded-lg">
            <v-skeleton-loader type="list-item-two-line@8" />
        </v-card>

        <!-- Leerzustand -->
        <v-card
            v-else-if="!customers.length"
            variant="outlined"
            class="pa-8 text-center text-medium-emphasis rounded-lg"
        >
            <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-car-off</v-icon>
            <div class="text-body-1">{{ t('HuSerienbriefView.table.no_results') }}</div>
            <div class="text-caption mt-1">{{ t('HuSerienbriefView.table.no_results_hint') }}</div>
        </v-card>

        <!-- Kunden-Liste -->
        <v-card v-else variant="outlined" class="rounded-lg overflow-hidden">
            <template v-for="(item, idx) in sortedCustomers" :key="item.customer_id">
                <v-divider v-if="idx > 0" />
                <div class="cust-block" :class="{ 'cust-block--off': item.hu_excluded }">
                    <!-- Kundenzeile -->
                    <div class="d-flex align-center px-2 px-sm-3 py-2 ga-2 cust-head">
                        <v-tooltip location="top" max-width="320" :text="item.hu_excluded ? t('HuSerienbriefView.tooltips.include') : t('HuSerienbriefView.tooltips.exclude')">
                            <template #activator="{ props: tp }">
                                <v-btn
                                    v-bind="tp"
                                    :icon="item.hu_excluded ? 'mdi-bell-off' : 'mdi-bell-ring'"
                                    :color="item.hu_excluded ? 'grey' : 'success'"
                                    variant="tonal"
                                    size="small"
                                    @click.stop="toggleCustomerNotify(item)"
                                />
                            </template>
                        </v-tooltip>

                        <div class="flex-grow-1 min-w-0">
                            <div
                                class="text-subtitle-2 font-weight-bold text-truncate hu-link"
                                :class="{ 'text-disabled': item.hu_excluded }"
                                role="button"
                                :title="t('HuSerienbriefView.tooltips.open_customer')"
                                @click="goToCustomer(item)"
                            >
                                {{ item.customer_name }}
                            </div>
                            <div class="text-caption text-medium-emphasis d-flex flex-wrap ga-3">
                                <span v-if="item.customer_zipcode || item.customer_city" class="d-inline-flex align-center text-truncate" :title="addressLine(item)">
                                    <v-icon size="x-small" class="me-1">mdi-map-marker-outline</v-icon>{{ [item.customer_zipcode, item.customer_city].filter(Boolean).join(' ') }}
                                </span>
                                <span v-if="item.distance_km != null" class="d-inline-flex align-center" :class="distClass(item.distance_km)" :title="t('HuSerienbriefView.tooltips.distance')">
                                    <v-icon size="x-small" class="me-1">mdi-map-marker-distance</v-icon>{{ Math.round(Number(item.distance_km)) }} km
                                </span>
                                <span v-if="item.customer_phone" class="d-inline-flex align-center">
                                    <v-icon size="x-small" class="me-1">mdi-phone</v-icon>{{ item.customer_phone }}
                                </span>
                                <span v-if="item.customer_email" class="d-inline-flex align-center text-truncate">
                                    <v-icon size="x-small" class="me-1">mdi-email-outline</v-icon>{{ item.customer_email }}
                                </span>
                            </div>
                        </div>

                        <v-tooltip location="top" :text="t('HuSerienbriefView.tooltips.revenue')">
                            <template #activator="{ props: tp }">
                                <v-chip
                                    v-bind="tp"
                                    v-if="Number(item.annual_revenue) > 0"
                                    size="small"
                                    variant="tonal"
                                    :color="revenueColor(item.annual_revenue)"
                                    class="font-weight-medium flex-shrink-0"
                                >
                                    <v-icon start size="x-small">mdi-cash</v-icon>
                                    {{ fmtEur(item.annual_revenue) }}
                                </v-chip>
                            </template>
                        </v-tooltip>

                        <v-chip v-if="item.hu_excluded" size="x-small" variant="tonal" color="grey">
                            {{ t('HuSerienbriefView.chips.excluded') }}
                        </v-chip>
                        <template v-else>
                            <v-tooltip location="top" :text="t('HuSerienbriefView.tooltips.print_single')">
                                <template #activator="{ props: tp }">
                                    <v-btn
                                        v-bind="tp"
                                        icon="mdi-printer-outline"
                                        size="small"
                                        variant="text"
                                        color="primary"
                                        :loading="pdfSingleId === item.customer_id"
                                        @click.stop="printLetters([item.customer_id], item.customer_id)"
                                    />
                                </template>
                            </v-tooltip>
                            <v-tooltip v-if="item.customer_phone" location="top" :text="t('HuSerienbriefView.tooltips.whatsapp_single')">
                                <template #activator="{ props: tp }">
                                    <v-btn
                                        v-bind="tp"
                                        icon="mdi-whatsapp"
                                        size="small"
                                        variant="text"
                                        color="green"
                                        @click.stop="sendWhatsApp(item)"
                                    />
                                </template>
                            </v-tooltip>
                        </template>
                    </div>

                    <!-- Fahrzeugzeilen -->
                    <div
                        v-for="fz in item.fahrzeuge"
                        :key="fz.c_id"
                        class="veh-line d-flex align-center py-1 pe-2 pe-sm-3 ga-1"
                        :class="{ 'veh-line--off': fz.c_hu_notify === false || item.hu_excluded }"
                    >
                        <v-tooltip
                            location="top"
                            max-width="320"
                            :text="item.hu_excluded ? t('HuSerienbriefView.tooltips.car_locked') : (fz.c_hu_notify === false ? t('HuSerienbriefView.tooltips.car_include') : t('HuSerienbriefView.tooltips.car_exclude'))"
                        >
                            <template #activator="{ props: tp }">
                                <div v-bind="tp" class="d-inline-flex">
                                    <v-btn
                                        :icon="(fz.c_hu_notify === false || item.hu_excluded) ? 'mdi-bell-off-outline' : 'mdi-bell-ring-outline'"
                                        :color="item.hu_excluded ? 'grey-lighten-1' : (fz.c_hu_notify === false ? 'grey' : 'success')"
                                        :disabled="item.hu_excluded"
                                        size="small"
                                        variant="text"
                                        @click.stop="toggleCarNotify(item, fz)"
                                    />
                                </div>
                            </template>
                        </v-tooltip>

                        <v-icon size="small" class="me-1" :color="(fz.c_hu_notify === false || item.hu_excluded) ? 'grey-lighten-1' : 'grey-darken-1'">mdi-car</v-icon>
                        <span
                            class="font-weight-bold me-2 veh-plate hu-link"
                            :class="{ 'text-disabled text-decoration-line-through': fz.c_hu_notify === false || item.hu_excluded }"
                            role="button"
                            :title="t('HuSerienbriefView.tooltips.open_vehicle')"
                            @click.stop="goToVehicle(fz)"
                        >
                            {{ fz.c_ln }}
                        </span>
                        <span class="text-body-2 text-medium-emphasis text-truncate flex-grow-1">
                            {{ [fz.c_m, fz.c_t].filter(Boolean).join(' ') }}
                        </span>
                        <v-chip
                            size="x-small"
                            variant="tonal"
                            :color="item.hu_excluded ? 'grey' : huChipColor(fz.c_hu)"
                            class="ms-2 font-weight-medium flex-shrink-0"
                        >
                            <v-icon start size="x-small">mdi-calendar-check</v-icon>
                            {{ formatDate(fz.c_hu) }}
                        </v-chip>
                    </div>
                </div>
            </template>
        </v-card>

        <!-- Brevo Email Dialog -->
        <BrevoMarketingMailDialog
            v-if="showBrevoDialog"
            :data="{ type: 'customer', ids: recipientIds }"
            @submit="onBrevoSubmit"
            @close="showBrevoDialog = false"
        />
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import axios from 'axios';
import * as toasts from '@/core/utils/toasts.js';
import { formatDate } from '@/core/utils/dateFormatter.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import BrevoMarketingMailDialog from '@/core/views/search/dialogs/brevo-marketing-mail.dialog.vue';

const { t } = useI18n();
const router = useRouter();

// Klick auf Kundennamen -> Kundenmaske, Klick auf Kennzeichen -> Fahrzeugmaske
function goToCustomer(item) {
    router.push({ name: 'change-customer', params: { id: item.customer_id } });
}
function goToVehicle(fz) {
    router.push({ name: 'car', params: { id: fz.c_id } });
}

const props = defineProps({
    message: {
        type: Object,
        default: () => ({ title: '', description: '', type: 'info' })
    },
    messages: {
        type: Array,
        default: () => []
    }
});

// State
const loading = ref(false);
const pdfLoading = ref(false);
const pdfSingleId = ref(null);
const sftpLoading = ref(false);
const whatsappLoading = ref(false);
const customers = ref([]);
const showExcluded = ref(false);
const sortBy = ref('best'); // 'best' (Umsatz + Nähe) | 'revenue' | 'distance'
const sortDir = ref('desc'); // 'asc' | 'desc' (nur für 'revenue' und 'distance')
const showBrevoDialog = ref(false);
const vorlaufMonate = ref(0);
const dateFrom = ref('');
const dateTo = ref('');

onMounted(() => {
    loadData();
});

watch(showExcluded, () => {
    loadData();
});

// Empfänger = nicht abgewählte Kunden, die noch mindestens ein aktives Fahrzeug haben.
// Die Sende-Aktionen (Backend) filtern serverseitig ebenfalls nach c_hu_notify und
// hu_serienbrief_excluded, sodass nur aktive Fahrzeuge im Brief/WhatsApp landen.
const recipientCustomers = computed(() =>
    customers.value.filter(c => !c.hu_excluded && (c.fahrzeuge || []).some(f => f.c_hu_notify !== false))
);
const recipientIds = computed(() => recipientCustomers.value.map(c => c.customer_id));
const recipientWithPhone = computed(() => recipientCustomers.value.filter(c => c.customer_phone));

// Entfernungs-Sortierung nur anbieten, wenn überhaupt Entfernungen vorliegen
const hasDistances = computed(() => customers.value.some(c => c.distance_km != null));

// Sortier-Button: aktiven erneut klicken kehrt die Richtung um, sonst Moduswechsel
// mit sinnvoller Startrichtung (Umsatz: höchster zuerst, Entfernung: nächste zuerst).
function setSort(mode) {
    if (sortBy.value === mode) {
        if (mode === 'best') return;
        sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc';
    } else {
        sortBy.value = mode;
        sortDir.value = mode === 'distance' ? 'asc' : 'desc';
    }
}

// Anzeigereihenfolge:
//  - 'best'     (Standard): kombiniertes Ranking aus hohem Umsatz + kurzer Entfernung
//  - 'revenue'  : nur Umsatz, höchster zuerst
//  - 'distance' : nur Entfernung, nächste zuerst (ohne Entfernung landen unten)
const sortedCustomers = computed(() => {
    const list = [...customers.value];

    if (sortBy.value === 'revenue') {
        const dir = sortDir.value === 'asc' ? 1 : -1;
        list.sort((a, b) => dir * (Number(a.annual_revenue || 0) - Number(b.annual_revenue || 0)));
        return list;
    }
    if (sortBy.value === 'distance') {
        const dir = sortDir.value === 'asc' ? 1 : -1;
        list.sort((a, b) => {
            // Kunden ohne Entfernung immer ans Ende, egal welche Richtung
            const da = a.distance_km == null ? null : Number(a.distance_km);
            const db = b.distance_km == null ? null : Number(b.distance_km);
            if (da == null && db == null) return 0;
            if (da == null) return 1;
            if (db == null) return -1;
            return dir * (da - db);
        });
        return list;
    }

    // 'best': Umsatz auf 0..1 normieren (mehr = besser), Nähe auf 0..1 (näher = besser),
    // beide gleich gewichtet addieren. Ohne Entfernung zählt nur der Umsatz.
    const maxRev = Math.max(1, ...list.map(c => Number(c.annual_revenue || 0)));
    const dists = list.map(c => (c.distance_km == null ? null : Number(c.distance_km))).filter(d => d != null);
    const maxDist = dists.length ? Math.max(...dists) : 0;
    const score = (c) => {
        const rev = Number(c.annual_revenue || 0) / maxRev;
        const prox = (c.distance_km != null && maxDist > 0) ? (1 - Number(c.distance_km) / maxDist) : 0;
        return rev + prox;
    };
    list.sort((a, b) => score(b) - score(a));
    return list;
});

// Vollständige Adresse als Tooltip (Straße + PLZ Ort)
function addressLine(item) {
    return [item.customer_street, [item.customer_zipcode, item.customer_city].filter(Boolean).join(' ')]
        .filter(Boolean).join(', ');
}

// Jahresumsatz (letzte 12 Monate) als Euro-Betrag ohne Nachkommastellen
function fmtEur(v) {
    return Number(v || 0).toLocaleString('de-DE', { maximumFractionDigits: 0 }) + ' €';
}

// Farbe des Umsatz-Chips: mehr Umsatz = kräftiger
function revenueColor(v) {
    const n = Number(v || 0);
    if (n >= 5000) return 'green-darken-1';
    if (n >= 1000) return 'teal';
    return 'blue-grey';
}

// Entfernung farblich hervorheben: weit weg = auffälliger (Kunde lohnt evtl. nicht)
function distClass(km) {
    const n = Number(km);
    if (n > 100) return 'text-error font-weight-bold';
    if (n > 50) return 'text-warning';
    return '';
}

// Farbe des HU-Chips nach Dringlichkeit
function huChipColor(hu) {
    if (!hu) return 'grey';
    const due = new Date(hu);
    if (isNaN(due)) return 'primary';
    const now = new Date();
    if (due < now) return 'error';
    const days = (due - now) / 86400000;
    if (days <= 31) return 'warning';
    return 'primary';
}

async function loadData() {
    loading.value = true;
    try {
        // Nur Daten mitsenden wenn bereits gesetzt (beim ersten Load berechnet das Backend aus Vorlauf-Config)
        const params = {
            action: 'getHuFaelligList',
            include_excluded: showExcluded.value,
        };
        if (dateFrom.value) params.date_from = dateFrom.value;
        if (dateTo.value) params.date_to = dateTo.value;

        const response = await axios.post('/api/lxcars/', params);

        if (response.data.success) {
            customers.value = response.data.payload.results ?? [];
            vorlaufMonate.value = response.data.payload.vorlauf_monate ?? 0;
            // Immer die vom Backend berechneten Daten uebernehmen
            dateFrom.value = response.data.payload.date_from ?? dateFrom.value;
            dateTo.value = response.data.payload.date_to ?? dateTo.value;
        } else {
            toasts.error(t('HuSerienbriefView.toasts.error_loading'));
            customers.value = [];
        }
    } catch (error) {
        toasts.error(t('HuSerienbriefView.toasts.error_loading'));
        customers.value = [];
    }
    loading.value = false;
}

// Kunden-Glocke: schaltet alle Fahrzeuge des Kunden ab/an (customer_ext.hu_serienbrief_excluded).
// Kunde zaehlt mehr als Fahrzeug — auch kuenftige Fahrzeuge sind dann ausgeschlossen.
async function toggleCustomerNotify(item) {
    const newExcluded = !item.hu_excluded;
    try {
        await axios.post('/api/lxcars/', {
            action: 'setHuExcluded',
            customer_id: item.customer_id,
            excluded: newExcluded
        });
        item.hu_excluded = newExcluded;
        toasts.success(newExcluded
            ? t('HuSerienbriefView.toasts.excluded_success')
            : t('HuSerienbriefView.toasts.included_success'));
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.exclude_error'));
    }
}

// Fahrzeug-Glocke: schaltet die HU-Benachrichtigung dieses Fahrzeugs ab/an (cars_lxcars.c_hu_notify).
async function toggleCarNotify(item, fz) {
    if (item.hu_excluded) return;
    const newNotify = fz.c_hu_notify === false;
    try {
        await axios.post('/api/lxcars/', {
            action: 'setCarHuNotify',
            c_id: fz.c_id,
            notify: newNotify
        });
        fz.c_hu_notify = newNotify;
        toasts.success(newNotify
            ? t('HuSerienbriefView.toasts.car_included')
            : t('HuSerienbriefView.toasts.car_excluded'));
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.exclude_error'));
    }
}

async function sendWhatsApp(item) {
    try {
        const response = await axios.post('/api/lxcars/', {
            action: 'sendHuWhatsAppBulk',
            customer_ids: [item.customer_id]
        });
        if (response.data.success && response.data.payload?.sent > 0) {
            toasts.success(t('HuSerienbriefView.toasts.whatsapp_sent'));
        } else {
            toasts.error(response.data.text || t('HuSerienbriefView.toasts.whatsapp_error'));
        }
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.whatsapp_error'));
    }
}

async function sendWhatsAppAll() {
    const ids = recipientWithPhone.value.map(c => c.customer_id);
    if (!ids.length) {
        toasts.error(t('HuSerienbriefView.toasts.whatsapp_no_phone'));
        return;
    }

    whatsappLoading.value = true;
    try {
        const response = await axios.post('/api/lxcars/', {
            action: 'sendHuWhatsAppBulk',
            customer_ids: ids
        });
        if (response.data.success) {
            const p = response.data.payload;
            toasts.success(t('HuSerienbriefView.toasts.whatsapp_bulk_sent', { sent: p.sent, failed: p.failed }));
        } else {
            toasts.error(response.data.text || t('HuSerienbriefView.toasts.whatsapp_error'));
        }
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.whatsapp_error'));
    }
    whatsappLoading.value = false;
}

async function onBrevoSubmit(data) {
    showBrevoDialog.value = false;
    try {
        const response = await axios.post('/api/brevo/', {
            action: 'sendMail',
            template: data.template,
            type: 'customer',
            ids: data.ids
        });
        if (response.data.success) {
            toasts.success(t('HuSerienbriefView.toasts.email_sent'));
        } else {
            toasts.error(t('HuSerienbriefView.toasts.email_error'));
        }
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.email_error'));
    }
}

async function sendViaSftp() {
    if (!recipientIds.value.length) return;

    sftpLoading.value = true;
    try {
        const response = await axios.post('/api/lxcars/', {
            action: 'sendHuPdfViaSftp',
            customer_ids: recipientIds.value,
            date_from: dateFrom.value,
            date_to: dateTo.value
        });

        if (response.data.success) {
            toasts.success(t('HuSerienbriefView.toasts.sftp_success'));
        } else {
            toasts.error(response.data.text || t('HuSerienbriefView.toasts.sftp_error'));
        }
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.sftp_error'));
    }
    sftpLoading.value = false;
}

// Druck: entweder alle Empfaenger (singleId leer) oder ein einzelner Kunde.
async function printLetters(ids, singleId = null) {
    if (!ids.length) return;

    if (singleId) pdfSingleId.value = singleId; else pdfLoading.value = true;
    try {
        const response = await axios.post('/api/lxcars/', {
            action: 'generateHuPdf',
            customer_ids: ids,
            date_from: dateFrom.value,
            date_to: dateTo.value
        });

        if (response.data.success && response.data.payload?.pdf) {
            // Base64 PDF dekodieren und im Browser öffnen
            const byteChars = atob(response.data.payload.pdf);
            const byteNumbers = new Array(byteChars.length);
            for (let i = 0; i < byteChars.length; i++) {
                byteNumbers[i] = byteChars.charCodeAt(i);
            }
            const byteArray = new Uint8Array(byteNumbers);
            const blob = new Blob([byteArray], { type: 'application/pdf' });
            const url = URL.createObjectURL(blob);
            window.open(url, '_blank');
        } else {
            console.error('PDF generation failed:', response.data);
            toasts.error(response.data.text || t('HuSerienbriefView.toasts.pdf_error'));
        }
    } catch (err) {
        console.error('PDF request error:', err?.response?.data || err?.message || err);
        const serverMsg = err?.response?.data?.text;
        toasts.error(serverMsg || t('HuSerienbriefView.toasts.pdf_error'));
    }
    if (singleId) pdfSingleId.value = null; else pdfLoading.value = false;
}
</script>

<style scoped>
/* Kundenkopf leicht abheben, damit die Gruppen in der Liste erkennbar sind */
.cust-head {
    background-color: rgba(0, 0, 0, 0.025);
}
.cust-block--off {
    opacity: 0.7;
}

/* Fahrzeugzeilen unter dem Kunden einrücken */
.veh-line {
    padding-left: 44px;
    transition: background-color 0.12s ease;
}
.veh-line:hover {
    background-color: rgba(var(--v-theme-primary), 0.06);
}
.veh-line--off {
    opacity: 0.7;
}

/* Kennzeichen nicht umbrechen / abschneiden lassen */
.veh-plate {
    white-space: nowrap;
    flex-shrink: 0;
}

.min-w-0 {
    min-width: 0;
}

/* Anklickbare Namen/Kennzeichen: als Link erkennbar */
.hu-link {
    cursor: pointer;
}
.hu-link:hover {
    text-decoration: underline;
    color: rgb(var(--v-theme-primary));
}
</style>
