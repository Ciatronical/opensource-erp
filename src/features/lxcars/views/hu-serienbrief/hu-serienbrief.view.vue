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
        </v-card>

        <!-- Aktionsleiste -->
        <v-row v-if="selected.length" dense class="mb-2">
            <v-col cols="auto">
                <v-btn
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-printer"
                    size="small"
                    :loading="pdfLoading"
                    @click="printSelected"
                >
                    {{ t('HuSerienbriefView.buttons.print_all') }} ({{ selected.length }})
                </v-btn>
            </v-col>
            <v-col cols="auto">
                <v-btn
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-email-fast"
                    size="small"
                    @click="showBrevoDialog = true"
                >
                    {{ t('HuSerienbriefView.buttons.email_all') }} ({{ selected.length }})
                </v-btn>
            </v-col>
            <v-col cols="auto">
                <v-btn
                    variant="tonal"
                    color="green"
                    prepend-icon="mdi-whatsapp"
                    size="small"
                    :loading="whatsappLoading"
                    @click="sendWhatsAppSelected"
                >
                    {{ t('HuSerienbriefView.buttons.whatsapp_all') }} ({{ selectedWithPhone.length }})
                </v-btn>
            </v-col>
            <v-col cols="auto">
                <v-btn
                    variant="tonal"
                    color="secondary"
                    prepend-icon="mdi-upload"
                    size="small"
                    :loading="sftpLoading"
                    @click="sendViaSftp"
                >
                    {{ t('HuSerienbriefView.buttons.send_pin') }} ({{ selected.length }})
                </v-btn>
            </v-col>
        </v-row>

        <!-- Tabelle -->
        <v-card :loading="loading">
            <v-data-table
                v-model="selected"
                :headers="headers"
                :items="customers"
                :items-per-page="50"
                :items-per-page-options="[25, 50, 100, -1]"
                :sort-by="[{ key: 'customer_name', order: 'asc' }]"
                :loading="loading"
                :no-data-text="t('HuSerienbriefView.table.no_results')"
                item-value="customer_id"
                show-select
                hover
                class="zebra-table"
            >
                <template #item.customer_name="{ item }">
                    <span
                        class="font-weight-medium"
                        :class="{ 'text-disabled': item.hu_excluded }"
                    >
                        {{ item.customer_name }}
                    </span>
                    <v-chip
                        v-if="item.hu_excluded"
                        class="ms-2"
                        size="x-small"
                        variant="tonal"
                        color="grey"
                    >
                        {{ t('HuSerienbriefView.chips.excluded') }}
                    </v-chip>
                </template>

                <template #item.fahrzeuge="{ item }">
                    <div v-for="fz in item.fahrzeuge" :key="fz.c_id" class="d-flex align-center text-no-wrap">
                        <v-tooltip
                            :text="fz.c_hu_notify === false ? t('HuSerienbriefView.tooltips.car_include') : t('HuSerienbriefView.tooltips.car_exclude')"
                            location="top"
                        >
                            <template #activator="{ props: tp }">
                                <v-btn
                                    v-bind="tp"
                                    :icon="fz.c_hu_notify === false ? 'mdi-bell-off-outline' : 'mdi-bell-ring-outline'"
                                    :color="fz.c_hu_notify === false ? 'grey' : 'primary'"
                                    size="x-small"
                                    variant="text"
                                    class="me-1"
                                    @click.stop="toggleCarNotify(item, fz)"
                                />
                            </template>
                        </v-tooltip>
                        <v-icon size="x-small" class="me-1">mdi-car</v-icon>
                        <strong :class="{ 'text-disabled text-decoration-line-through': fz.c_hu_notify === false }">{{ fz.c_ln }}</strong>
                        <span v-if="fz.c_m" class="text-medium-emphasis ms-1">{{ fz.c_m }} {{ fz.c_t }}</span>
                    </div>
                </template>

                <template #item.hu_dates="{ item }">
                    <div v-for="fz in item.fahrzeuge" :key="fz.c_id">
                        {{ formatDate(fz.c_hu) }}
                    </div>
                </template>

                <template #item.customer_phone="{ item }">
                    <span v-if="item.customer_phone" class="text-no-wrap">
                        {{ item.customer_phone }}
                    </span>
                    <span v-else class="text-disabled">&mdash;</span>
                </template>

                <template #item.customer_email="{ item }">
                    <span v-if="item.customer_email" class="text-no-wrap">
                        {{ item.customer_email }}
                    </span>
                    <span v-else class="text-disabled">&mdash;</span>
                </template>

                <template #item.actions="{ item }">
                    <div class="d-flex ga-1">
                        <v-tooltip :text="t('HuSerienbriefView.tooltips.whatsapp_single')" location="top">
                            <template #activator="{ props: tp }">
                                <v-btn
                                    v-if="item.customer_phone"
                                    v-bind="tp"
                                    icon="mdi-whatsapp"
                                    size="x-small"
                                    variant="text"
                                    color="green"
                                    @click.stop="sendWhatsApp(item)"
                                />
                            </template>
                        </v-tooltip>
                        <v-tooltip :text="t('HuSerienbriefView.tooltips.exclude')" location="top">
                            <template #activator="{ props: tp }">
                                <v-btn
                                    v-if="!item.hu_excluded"
                                    v-bind="tp"
                                    icon="mdi-account-remove"
                                    size="x-small"
                                    variant="text"
                                    color="grey"
                                    @click.stop="excludeCustomer(item)"
                                />
                            </template>
                        </v-tooltip>
                        <v-tooltip :text="t('HuSerienbriefView.tooltips.include')" location="top">
                            <template #activator="{ props: tp }">
                                <v-btn
                                    v-if="item.hu_excluded"
                                    v-bind="tp"
                                    icon="mdi-account-plus"
                                    size="x-small"
                                    variant="text"
                                    color="primary"
                                    @click.stop="includeCustomer(item)"
                                />
                            </template>
                        </v-tooltip>
                    </div>
                </template>
            </v-data-table>
        </v-card>

        <!-- Brevo Email Dialog -->
        <BrevoMarketingMailDialog
            v-if="showBrevoDialog"
            :data="{ type: 'customer', ids: selected }"
            @submit="onBrevoSubmit"
            @close="showBrevoDialog = false"
        />
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import * as toasts from '@/core/utils/toasts.js';
import { formatDate } from '@/core/utils/dateFormatter.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import BrevoMarketingMailDialog from '@/core/views/search/dialogs/brevo-marketing-mail.dialog.vue';

const { t } = useI18n();

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
const sftpLoading = ref(false);
const whatsappLoading = ref(false);
const customers = ref([]);
const selected = ref([]);
const showExcluded = ref(false);
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

const headers = computed(() => [
    { title: t('HuSerienbriefView.fields.customer_name'), key: 'customer_name', sortable: true },
    { title: t('HuSerienbriefView.fields.kennzeichen'), key: 'fahrzeuge', sortable: false },
    { title: t('HuSerienbriefView.fields.hu_datum'), key: 'hu_dates', sortable: false },
    { title: t('HuSerienbriefView.fields.telefon'), key: 'customer_phone', sortable: true },
    { title: t('HuSerienbriefView.fields.email'), key: 'customer_email', sortable: true },
    { title: t('HuSerienbriefView.fields.actions'), key: 'actions', sortable: false, align: 'end' },
]);

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

async function excludeCustomer(item) {
    try {
        await axios.post('/api/lxcars/', {
            action: 'setHuExcluded',
            customer_id: item.customer_id,
            excluded: true
        });
        toasts.success(t('HuSerienbriefView.toasts.excluded_success'));

        if (!showExcluded.value) {
            customers.value = customers.value.filter(c => c.customer_id !== item.customer_id);
        } else {
            item.hu_excluded = true;
        }
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.exclude_error'));
    }
}

async function includeCustomer(item) {
    try {
        await axios.post('/api/lxcars/', {
            action: 'setHuExcluded',
            customer_id: item.customer_id,
            excluded: false
        });
        toasts.success(t('HuSerienbriefView.toasts.included_success'));
        item.hu_excluded = false;
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.exclude_error'));
    }
}

async function toggleCarNotify(item, fz) {
    // Aktueller Zustand: c_hu_notify === false → abgewählt. Klick invertiert.
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

        // Abgewähltes Fahrzeug ausblenden, wenn nicht "auch abgewählte anzeigen"
        if (!newNotify && !showExcluded.value) {
            item.fahrzeuge = item.fahrzeuge.filter(f => f.c_id !== fz.c_id);
            if (!item.fahrzeuge.length) {
                customers.value = customers.value.filter(c => c.customer_id !== item.customer_id);
            }
        }
    } catch {
        toasts.error(t('HuSerienbriefView.toasts.exclude_error'));
    }
}

const selectedWithPhone = computed(() =>
    customers.value.filter(c => selected.value.includes(c.customer_id) && c.customer_phone)
);

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

async function sendWhatsAppSelected() {
    const items = selectedWithPhone.value;
    if (!items.length) {
        toasts.error(t('HuSerienbriefView.toasts.whatsapp_no_phone'));
        return;
    }

    whatsappLoading.value = true;
    try {
        const response = await axios.post('/api/lxcars/', {
            action: 'sendHuWhatsAppBulk',
            customer_ids: items.map(c => c.customer_id)
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
    if (!selected.value.length) return;

    sftpLoading.value = true;
    try {
        const response = await axios.post('/api/lxcars/', {
            action: 'sendHuPdfViaSftp',
            customer_ids: selected.value,
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

async function printSelected() {
    if (!selected.value.length) return;

    pdfLoading.value = true;
    try {
        const response = await axios.post('/api/lxcars/', {
            action: 'generateHuPdf',
            customer_ids: selected.value,
            date_from: dateFrom.value,
            date_to: dateTo.value
        });

        console.log('PDF response debug:', response.data.payload?.debug);
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
            console.error('Debug info:', response.data.payload?.debug);
            if (response.data.payload?.latex) {
                console.error('Generated LaTeX:', response.data.payload.latex);
            }
            toasts.error(response.data.text || t('HuSerienbriefView.toasts.pdf_error'));
        }
    } catch (err) {
        console.error('PDF request error:', err?.response?.data || err?.message || err);
        const serverMsg = err?.response?.data?.text;
        toasts.error(serverMsg || t('HuSerienbriefView.toasts.pdf_error'));
    }
    pdfLoading.value = false;
}
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
    background-color: rgba(0, 0, 0, 0.03);
}
.zebra-table :deep(tbody tr:hover) {
    background-color: rgba(var(--v-theme-primary), 0.08) !important;
}
</style>
