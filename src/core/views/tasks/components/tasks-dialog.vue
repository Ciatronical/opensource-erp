<!-- src/core/views/tasks/components/tasks-dialog.vue -->
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
                    {{ isEdit ? t('TasksDialog.titleEdit') : t('TasksDialog.titleCreate') }}
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
                                v-model="formData.taskDate"
                                :label="t('TasksDialog.date') + ' *'"
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
                                {{ t('TasksDialog.quickDate.today') }}
                            </v-chip>
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('tomorrow')"
                            >
                                {{ t('TasksDialog.quickDate.tomorrow') }}
                            </v-chip>
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('nextWeek')"
                            >
                                {{ t('TasksDialog.quickDate.nextWeek') }}
                            </v-chip>
                            <v-chip
                                size="small"
                                variant="outlined"
                                @click="setDate('nextMonth')"
                            >
                                {{ t('TasksDialog.quickDate.nextMonth') }}
                            </v-chip>
                        </v-col>

                        <!-- Betreff -->
                        <v-col cols="12">
                            <v-text-field
                                v-model="formData.subject"
                                :label="t('TasksDialog.subject') + ' *'"
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
                                :label="t('TasksDialog.body')"
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
                                :label="t('TasksDialog.assignedEmployees')"
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
                                        {{ t('TasksDialog.linkSection') }}
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
                                                    :label="t('TasksDialog.linkType')"
                                                    variant="outlined"
                                                    density="compact"
                                                    clearable
                                                />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field
                                                    v-model="formData.transId"
                                                    :label="t('TasksDialog.linkId')"
                                                    type="number"
                                                    variant="outlined"
                                                    density="compact"
                                                    :disabled="!formData.transType"
                                                />
                                            </v-col>
                                            <v-col cols="12">
                                                <v-text-field
                                                    v-model="formData.transInfo"
                                                    :label="t('TasksDialog.linkInfo')"
                                                    variant="outlined"
                                                    density="compact"
                                                    :disabled="!formData.transType"
                                                    hint="z.B. Kundenname, Rechnungsnummer"
                                                />
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
                    {{ t('TasksDialog.cancel') }}
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
                    {{ t('TasksDialog.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
// src/core/views/tasks/components/tasks-dialog.vue
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false
    },
    task: {
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

const formData = ref({
    id: null,
    taskDate: '',
    subject: '',
    body: '',
    assignedEmployees: [],
    transType: null,
    transId: null,
    transInfo: ''
});

// Validation Rules
const rules = {
    required: (v) => !!v || t('TasksDialog.validation.required')
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
    { title: t('TasksDialog.linkTypes.customer'), value: 'customer' },
    { title: t('TasksDialog.linkTypes.vendor'), value: 'vendor' },
    { title: t('TasksDialog.linkTypes.salesQuotation'), value: 'sales_quotation' },
    { title: t('TasksDialog.linkTypes.salesOrder'), value: 'sales_order' },
    { title: t('TasksDialog.linkTypes.salesDeliveryOrder'), value: 'sales_delivery_order' },
    { title: t('TasksDialog.linkTypes.salesInvoice'), value: 'sales_invoice' },
    { title: t('TasksDialog.linkTypes.purchaseQuotation'), value: 'request_quotation' },
    { title: t('TasksDialog.linkTypes.purchaseOrder'), value: 'purchase_order' },
    { title: t('TasksDialog.linkTypes.purchaseDeliveryOrder'), value: 'purchase_delivery_order' },
    { title: t('TasksDialog.linkTypes.purchaseInvoice'), value: 'purchase_invoice' }
]);

// Methods
function resetForm() {
    formData.value = {
        id: null,
        taskDate: new Date().toISOString().split('T')[0],
        subject: '',
        body: '',
        assignedEmployees: [],
        transType: null,
        transId: null,
        transInfo: ''
    };
}

function loadTasks() {
    if (props.task) {
        formData.value = {
            id: props.task.id || null,
            taskDate: props.task.task_date || new Date().toISOString().split('T')[0],
            subject: props.task.note?.subject || '',
            body: props.task.note?.body || '',
            assignedEmployees: props.task.assigned_employees?.map(e => e.employee_id) || [],
            transType: props.task.links?.[0]?.trans_type || null,
            transId: props.task.links?.[0]?.trans_id || null,
            transInfo: props.task.links?.[0]?.trans_info || ''
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
        case 'nextMonth':
            date.setMonth(date.getMonth() + 1);
            break;
    }
    formData.value.taskDate = date.toISOString().split('T')[0];
}

function getEmployeeColor(employeeId) {
    const colors = ['primary', 'secondary', 'success', 'info', 'warning'];
    return colors[employeeId % colors.length];
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
        loadTasks();
    }
});

watch(() => props.task, () => {
    if (props.modelValue) {
        loadTasks();
    }
}, { deep: true });
</script>
