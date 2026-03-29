<!-- src/core/views/follow-up/components/follow-up-dialog.vue -->
<!-- Dialog zum Erstellen/Bearbeiten von Wiedervorlagen -->
<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="600"
        persistent
    >
        <v-card>
            <!-- Header -->
            <v-card-title class="d-flex align-center pa-4 bg-grey-lighten-4">
                <v-icon class="me-2" :color="isEdit ? 'primary' : 'success'">
                    {{ isEdit ? 'mdi-pencil' : 'mdi-plus-circle' }}
                </v-icon>
                <span>
                    {{ isEdit ? t('FollowUpDialog.titleEdit') : t('FollowUpDialog.titleCreate') }}
                </span>
                <v-spacer />
                <v-btn
                    icon
                    variant="text"
                    @click="close"
                >
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <!-- Form -->
            <v-card-text class="pa-4">
                <v-form ref="formRef" v-model="formValid">
                    <v-row>
                        <!-- Datum -->
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model="formData.followUpDate"
                                :label="t('FollowUpDialog.date') + ' *'"
                                type="date"
                                :rules="[rules.required]"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-calendar"
                            />
                        </v-col>

                        <!-- Schnellauswahl Datum -->
                        <v-col cols="12" sm="6" class="d-flex align-center gap-1 flex-wrap">
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('today')"
                            >
                                {{ t('FollowUpDialog.quickDate.today') }}
                            </v-chip>
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('tomorrow')"
                            >
                                {{ t('FollowUpDialog.quickDate.tomorrow') }}
                            </v-chip>
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('nextWeek')"
                            >
                                {{ t('FollowUpDialog.quickDate.nextWeek') }}
                            </v-chip>
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('twoWeeks')"
                            >
                                {{ t('FollowUpDialog.quickDate.twoWeeks') }}
                            </v-chip>
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('nextMonth')"
                            >
                                {{ t('FollowUpDialog.quickDate.nextMonth') }}
                            </v-chip>
                        </v-col>

                        <!-- Betreff -->
                        <v-col cols="12">
                            <v-text-field
                                v-model="formData.subject"
                                :label="t('FollowUpDialog.subject') + ' *'"
                                :rules="[rules.required]"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-format-title"
                                :counter="100"
                                maxlength="100"
                            />
                        </v-col>

                        <!-- Notiztext -->
                        <v-col cols="12">
                            <v-textarea
                                v-model="formData.body"
                                :label="t('FollowUpDialog.body')"
                                variant="outlined"
                                density="compact"
                                rows="4"
                                auto-grow
                                prepend-inner-icon="mdi-text"
                                :counter="2000"
                                maxlength="2000"
                            />
                        </v-col>

                        <!-- Mitarbeiter zuweisen -->
                        <v-col cols="12">
                            <v-autocomplete
                                v-model="formData.assignedEmployees"
                                :items="employeeItems"
                                :label="t('FollowUpDialog.assignedEmployees')"
                                variant="outlined"
                                density="compact"
                                chips
                                closable-chips
                                multiple
                                prepend-inner-icon="mdi-account-group"
                            >
                                <template #chip="{ item, props: chipProps }">
                                    <v-chip
                                        v-bind="chipProps"
                                        :color="getEmployeeColor(item.value)"
                                        variant="tonal"
                                    >
                                        {{ item.title }}
                                    </v-chip>
                                </template>
                            </v-autocomplete>
                        </v-col>

                        <!-- Verknüpfung (optional, wenn Context vorhanden) -->
                        <v-col v-if="showLinkSection" cols="12">
                            <v-expansion-panels variant="accordion">
                                <v-expansion-panel>
                                    <v-expansion-panel-title>
                                        <v-icon class="me-2">mdi-link</v-icon>
                                        {{ t('FollowUpDialog.linkSection') }}
                                        <v-chip
                                            v-if="formData.transType"
                                            size="x-small"
                                            class="ms-2"
                                            color="primary"
                                        >
                                            {{ formData.transType }}
                                        </v-chip>
                                    </v-expansion-panel-title>
                                    <v-expansion-panel-text>
                                        <v-row>
                                            <v-col cols="12" sm="6">
                                                <v-select
                                                    v-model="formData.transType"
                                                    :items="linkTypeItems"
                                                    :label="t('FollowUpDialog.linkType')"
                                                    variant="outlined"
                                                    density="compact"
                                                    clearable
                                                />
                                            </v-col>

                                            <!-- Autocomplete für alle Link-Typen -->
                                            <v-col v-if="formData.transType" cols="12">
                                                <v-autocomplete
                                                    v-model="selectedLink"
                                                    v-model:search="linkSearchQuery"
                                                    :items="linkSearchResults"
                                                    :loading="linkSearchLoading"
                                                    :label="getLinkSearchLabel(formData.transType)"
                                                    item-title="name"
                                                    item-value="id"
                                                    variant="outlined"
                                                    density="compact"
                                                    clearable
                                                    return-object
                                                    no-filter
                                                    prepend-inner-icon="mdi-magnify"
                                                    @update:search="searchLink"
                                                    @update:model-value="onLinkSelected"
                                                >
                                                    <template #item="{ props: itemProps, item }">
                                                        <v-list-item v-bind="itemProps">
                                                            <template #prepend>
                                                                <v-icon>{{ getLinkIcon(formData.transType) }}</v-icon>
                                                            </template>
                                                            <template #title>
                                                                <strong>{{ item.raw.name || item.raw.number || item.raw.id }}</strong>
                                                            </template>
                                                            <template #subtitle>
                                                                <span v-if="item.raw.customer_name">{{ item.raw.customer_name }}</span>
                                                                <span v-else-if="item.raw.vendor_name">{{ item.raw.vendor_name }}</span>
                                                                <span v-if="item.raw.city"> · {{ item.raw.city }}</span>
                                                                <span v-if="item.raw.amount" class="text-green"> · {{ formatAmount(item.raw.amount) }}</span>
                                                            </template>
                                                        </v-list-item>
                                                    </template>
                                                    <template #no-data>
                                                        <v-list-item>
                                                            <v-list-item-title>
                                                                {{ linkSearchQuery ? t('FollowUpDialog.noResults') : t('FollowUpDialog.startTyping') }}
                                                            </v-list-item-title>
                                                        </v-list-item>
                                                    </template>
                                                </v-autocomplete>
                                            </v-col>
                                        </v-row>
                                    </v-expansion-panel-text>
                                </v-expansion-panel>
                            </v-expansion-panels>
                        </v-col>
                    </v-row>
                </v-form>
            </v-card-text>

            <v-divider />

            <!-- Actions -->
            <v-card-actions class="pa-4">
                <v-btn
                    variant="text"
                    @click="close"
                >
                    {{ t('FollowUpDialog.cancel') }}
                </v-btn>
                <v-spacer />
                <v-btn
                    color="primary"
                    variant="flat"
                    :disabled="!formValid"
                    :loading="saving"
                    prepend-icon="mdi-content-save"
                    @click="save"
                >
                    {{ t('FollowUpDialog.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
// src/core/views/follow-up/components/follow-up-dialog.vue
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false
    },
    followUp: {
        type: Object,
        default: null
    },
    employees: {
        type: Array,
        default: () => []
    },
    // Für Context-basierte Verknüpfung (z.B. aus Kundenansicht)
    contextLink: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['update:modelValue', 'save']);

// Form State
const formRef = ref(null);
const formValid = ref(false);
const saving = ref(false);

// Autocomplete State
const linkSearchQuery = ref('');
const linkSearchResults = ref([]);
const linkSearchLoading = ref(false);
const selectedLink = ref(null);
let searchTimeout = null;

const formData = ref({
    id: null,
    followUpDate: '',
    subject: '',
    body: '',
    assignedEmployees: [],
    transType: null,
    transId: null,
    transInfo: ''
});

// Validation Rules
const rules = {
    required: (v) => !!v || t('FollowUpDialog.validation.required')
};

// Computed
const isEdit = computed(() => !!formData.value.id);

const showLinkSection = computed(() => {
    return !props.contextLink; // Zeige nur wenn kein Context-Link vorhanden
});

const employeeItems = computed(() => {
    return props.employees.map(emp => ({
        title: emp.name || `${emp.firstname || ''} ${emp.lastname || ''}`.trim(),
        value: emp.id
    }));
});

const linkTypeItems = computed(() => [
    { title: t('FollowUpDialog.linkTypes.customer'), value: 'customer' },
    { title: t('FollowUpDialog.linkTypes.vendor'), value: 'vendor' },
    { title: t('FollowUpDialog.linkTypes.salesQuotation'), value: 'sales_quotation' },
    { title: t('FollowUpDialog.linkTypes.salesOrder'), value: 'sales_order' },
    { title: t('FollowUpDialog.linkTypes.salesDeliveryOrder'), value: 'sales_delivery_order' },
    { title: t('FollowUpDialog.linkTypes.salesInvoice'), value: 'sales_invoice' },
    { title: t('FollowUpDialog.linkTypes.purchaseQuotation'), value: 'request_quotation' },
    { title: t('FollowUpDialog.linkTypes.purchaseOrder'), value: 'purchase_order' },
    { title: t('FollowUpDialog.linkTypes.purchaseDeliveryOrder'), value: 'purchase_delivery_order' },
    { title: t('FollowUpDialog.linkTypes.purchaseInvoice'), value: 'purchase_invoice' }
]);

// Methods
function resetForm() {
    formData.value = {
        id: null,
        followUpDate: new Date().toISOString().split('T')[0],
        subject: '',
        body: '',
        assignedEmployees: [],
        transType: null,
        transId: null,
        transInfo: ''
    };
}

function loadFollowUp() {
    if (props.followUp) {
        formData.value = {
            id: props.followUp.id || null,
            followUpDate: props.followUp.follow_up_date || new Date().toISOString().split('T')[0],
            subject: props.followUp.subject || props.followUp.note?.subject || '',
            body: props.followUp.body || props.followUp.note?.body || '',
            assignedEmployees: props.followUp.assigned_employees?.map(e => e.employee_id) || [],
            transType: props.followUp.links?.[0]?.trans_type || null,
            transId: props.followUp.links?.[0]?.trans_id || null,
            transInfo: props.followUp.links?.[0]?.trans_info || ''
        };
    } else {
        resetForm();

        // Context-Link vorbelegen wenn vorhanden
        if (props.contextLink) {
            formData.value.transType = props.contextLink.type;
            formData.value.transId = props.contextLink.id;
            formData.value.transInfo = props.contextLink.info;
        }
    }
}

function setDate(quickDate) {
    const date = new Date();
    switch (quickDate) {
        case 'today':
            break;
        case 'tomorrow':
            date.setDate(date.getDate() + 1);
            break;
        case 'nextWeek':
            date.setDate(date.getDate() + 7);
            break;
        case 'twoWeeks':
            date.setDate(date.getDate() + 14);
            break;
        case 'nextMonth':
            date.setMonth(date.getMonth() + 1);
            break;
    }
    formData.value.followUpDate = date.toISOString().split('T')[0];
}

function getEmployeeColor(employeeId) {
    const colors = ['primary', 'secondary', 'success', 'info', 'warning'];
    return colors[employeeId % colors.length];
}

async function searchLink(query) {
    if (!query || query.length < 2) {
        linkSearchResults.value = [];
        return;
    }

    // Debounce
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }

    searchTimeout = setTimeout(async () => {
        linkSearchLoading.value = true;
        try {
            const response = await axios.post('/api/followup/', {
                action: 'searchForLink',
                type: formData.value.transType,
                query: query,
                limit: 10
            });

            if (response.data.success && response.data.payload?.results) {
                // Ergebnisse transformieren: name-Feld hinzufügen je nach Typ
                const transformed = response.data.payload.results.map(item => {
                    // Customer/Vendor haben bereits "name"
                    if (item.name) {
                        return item;
                    }

                    // Rechnungen/Orders: Verwende "number" als name
                    if (item.number) {
                        return {
                            ...item,
                            name: String(item.number)
                        };
                    }

                    // Fallback
                    return {
                        ...item,
                        name: `ID: ${item.id}`
                    };
                });

                // Force-clear und dann setzen für Vue Reactivity
                linkSearchResults.value = [];
                setTimeout(() => {
                    linkSearchResults.value = transformed;
                }, 0);
            } else {
                linkSearchResults.value = [];
            }
        } catch (error) {
            console.error('Error searching link:', error);
            linkSearchResults.value = [];
        } finally {
            linkSearchLoading.value = false;
        }
    }, 300);
}

function onLinkSelected(item) {
    if (item) {
        formData.value.transId = item.id;
        // Für Rechnungen/Orders: Zeige Nummer + Kunde/Lieferant
        // Für Kunden/Lieferanten: Zeige nur Namen
        if (item.number) {
            // Hat eine Nummer (Rechnung/Order)
            const partnerName = item.customer_name || item.vendor_name || '';
            formData.value.transInfo = partnerName ? `${item.number} (${partnerName})` : item.number;
        } else {
            // Nur Name (Kunde/Lieferant)
            formData.value.transInfo = item.name;
        }
    } else {
        formData.value.transId = null;
        formData.value.transInfo = '';
    }
}

function getLinkIcon(type) {
    const iconMap = {
        'customer': 'mdi-account',
        'vendor': 'mdi-domain',
        'sales_quotation': 'mdi-file-document-outline',
        'sales_order': 'mdi-file-document',
        'sales_delivery_order': 'mdi-truck-delivery',
        'sales_invoice': 'mdi-receipt',
        'request_quotation': 'mdi-file-question-outline',
        'purchase_order': 'mdi-cart',
        'purchase_delivery_order': 'mdi-package-variant',
        'purchase_invoice': 'mdi-receipt-text'
    };
    return iconMap[type] || 'mdi-link';
}

function getLinkSearchLabel(type) {
    const labelMap = {
        'customer': t('FollowUpDialog.searchCustomer'),
        'vendor': t('FollowUpDialog.searchVendor'),
        'sales_quotation': t('FollowUpDialog.searchQuotation'),
        'sales_order': t('FollowUpDialog.searchOrder'),
        'sales_delivery_order': t('FollowUpDialog.searchDeliveryOrder'),
        'sales_invoice': t('FollowUpDialog.searchInvoice'),
        'request_quotation': t('FollowUpDialog.searchRequestQuotation'),
        'purchase_order': t('FollowUpDialog.searchPurchaseOrder'),
        'purchase_delivery_order': t('FollowUpDialog.searchPurchaseDeliveryOrder'),
        'purchase_invoice': t('FollowUpDialog.searchPurchaseInvoice')
    };
    return labelMap[type] || t('FollowUpDialog.search');
}

function formatAmount(amount) {
    if (!amount) return '';
    return new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR'
    }).format(amount);
}

function close() {
    emit('update:modelValue', false);
}

async function save() {
    if (!formValid.value) return;

    saving.value = true;
    try {
        emit('save', { ...formData.value });
    } finally {
        saving.value = false;
    }
}

// Watchers
watch(() => props.modelValue, (newVal) => {
    if (newVal) {
        loadFollowUp();
    }
});

watch(() => props.followUp, () => {
    if (props.modelValue) {
        loadFollowUp();
    }
}, { deep: true });

// Reset Autocomplete wenn Type sich ändert
watch(() => formData.value.transType, (newType, oldType) => {
    // Immer clearen wenn Type sich ändert (außer beim ersten Laden)
    if (newType !== oldType && oldType !== undefined) {
        selectedLink.value = null;
        linkSearchResults.value = [];
        linkSearchQuery.value = '';
        formData.value.transId = null;
        formData.value.transInfo = '';
    }
});
</script>