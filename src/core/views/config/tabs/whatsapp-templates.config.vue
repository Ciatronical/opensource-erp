<!-- src/core/views/config/tabs/whatsapp-templates.config.vue -->
<template>
    <v-container fluid class="pa-0">
        <!-- Toolbar -->
        <v-row class="mb-4" align="center">
            <v-col cols="auto">
                <v-btn
                    color="primary"
                    variant="flat"
                    prepend-icon="mdi-plus"
                    @click="openCreate"
                >
                    {{ t('crm_fields.whatsappTpl.createTemplate') }}
                </v-btn>
            </v-col>
            <v-col cols="auto">
                <v-btn
                    variant="outlined"
                    prepend-icon="mdi-file-document-outline"
                    :loading="loadingDefaults"
                    @click="loadDefaultTemplates"
                >
                    {{ t('crm_fields.whatsappTpl.loadDefaults') }}
                </v-btn>
            </v-col>
            <v-col cols="auto">
                <v-btn
                    variant="outlined"
                    prepend-icon="mdi-sync"
                    :loading="syncing"
                    @click="syncFromMeta"
                >
                    {{ t('crm_fields.whatsappTpl.syncFromMeta') }}
                </v-btn>
            </v-col>
            <v-spacer />
            <v-col cols="12" md="3">
                <v-text-field
                    v-model="searchQuery"
                    :label="t('crm_fields.whatsappTpl.search')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                />
            </v-col>
        </v-row>

        <!-- Data Table -->
        <v-data-table
            v-show="!editing"
            :headers="tableHeaders"
            :items="filteredTemplates"
            :loading="loading"
            :items-per-page="10"
            density="comfortable"
            hover
        >
            <!-- Display Name -->
            <template #item.display_name="{ item }">
                <strong>{{ item.display_name }}</strong>
                <div class="text-caption text-grey">{{ item.name }}</div>
            </template>

            <!-- Template Type -->
            <template #item.template_type="{ item }">
                <v-chip
                    :color="templateTypeColor(item.template_type)"
                    size="small"
                    variant="tonal"
                >
                    {{ t('crm_fields.whatsappTpl.type_' + (item.template_type || 'general')) }}
                </v-chip>
            </template>

            <!-- Body -->
            <template #item.body_text="{ item }">
                <span class="text-body-2" style="max-width: 400px; display: inline-block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    {{ item.body_text }}
                </span>
            </template>

            <!-- Status -->
            <template #item.status="{ item }">
                <v-chip
                    :color="statusColor(item.status)"
                    size="small"
                    variant="flat"
                >
                    {{ t('crm_fields.whatsappTpl.status_' + (item.status || 'draft')) }}
                </v-chip>
                <div v-if="item.status === 'rejected' && item.rejection_reason" class="text-caption text-error mt-1">
                    {{ item.rejection_reason }}
                </div>
            </template>

            <!-- Actions -->
            <template #item.actions="{ item }">
                <v-btn
                    icon="mdi-pencil"
                    size="small"
                    variant="text"
                    :title="t('crm_fields.whatsappTpl.edit')"
                    @click="openEdit(item)"
                />
                <v-btn
                    icon="mdi-send"
                    size="small"
                    variant="text"
                    color="primary"
                    :title="t('crm_fields.whatsappTpl.submitToMeta')"
                    :loading="submittingId === item.id"
                    :disabled="item.status === 'approved'"
                    @click="submitToMeta(item)"
                />
                <v-btn
                    icon="mdi-delete"
                    size="small"
                    variant="text"
                    color="error"
                    :title="t('crm_fields.whatsappTpl.delete')"
                    @click="confirmDelete(item)"
                />
            </template>

            <!-- No Data -->
            <template #no-data>
                <div class="text-center pa-6 text-grey">
                    <v-icon size="48" class="mb-2">mdi-whatsapp</v-icon>
                    <div>{{ t('crm_fields.whatsappTpl.noTemplates') }}</div>
                </div>
            </template>
        </v-data-table>

        <!-- ============================================================ -->
        <!-- INLINE Edit/Create (ersetzt v-dialog komplett)               -->
        <!-- ============================================================ -->
        <v-card v-if="editing" variant="outlined" class="mt-4">
            <v-card-title class="bg-primary text-white d-flex align-center">
                <v-icon start>mdi-whatsapp</v-icon>
                {{ editingTemplate.id
                    ? t('crm_fields.whatsappTpl.editTitle')
                    : t('crm_fields.whatsappTpl.createTitle')
                }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" color="white" size="small" @click="cancelEdit" />
            </v-card-title>

            <v-card-text class="pa-4">
                <v-row>
                    <!-- Left: Form Fields -->
                    <v-col cols="12" md="7">
                        <v-text-field
                            v-model="editingTemplate.display_name"
                            :label="t('crm_fields.whatsappTpl.displayName')"
                            variant="outlined"
                            density="compact"
                            class="mb-3"
                            @update:model-value="generateName"
                        />

                        <v-text-field
                            v-model="editingTemplate.name"
                            :label="t('crm_fields.whatsappTpl.name')"
                            variant="outlined"
                            density="compact"
                            class="mb-3"
                            :hint="t('crm_fields.whatsappTpl.nameHint')"
                            persistent-hint
                            readonly
                        />

                        <v-row class="mb-3">
                            <v-col cols="6">
                                <v-select
                                    v-model="editingTemplate.category"
                                    :label="t('crm_fields.whatsappTpl.category')"
                                    :items="categoryOptions"
                                    variant="outlined"
                                    density="compact"
                                />
                            </v-col>
                            <v-col cols="6">
                                <v-select
                                    v-model="editingTemplate.template_type"
                                    :label="t('crm_fields.whatsappTpl.templateType')"
                                    :items="templateTypeOptions"
                                    variant="outlined"
                                    density="compact"
                                />
                            </v-col>
                        </v-row>

                        <v-select
                            v-model="editingTemplate.header_type"
                            :label="t('crm_fields.whatsappTpl.headerType')"
                            :items="headerTypeOptions"
                            variant="outlined"
                            density="compact"
                            class="mb-3"
                        />

                        <v-text-field
                            v-if="editingTemplate.header_type === 'TEXT'"
                            v-model="editingTemplate.header_text"
                            :label="t('crm_fields.whatsappTpl.headerText')"
                            variant="outlined"
                            density="compact"
                            class="mb-3"
                        />

                        <v-textarea
                            v-model="editingTemplate.body_text"
                            :label="t('crm_fields.whatsappTpl.bodyText')"
                            variant="outlined"
                            density="compact"
                            rows="6"
                            class="mb-3"
                            :hint="t('crm_fields.whatsappTpl.bodyTextHint')"
                            persistent-hint
                        >
                            <template #append-inner>
                                <div class="d-flex flex-column ga-1">
                                    <v-tooltip :text="t('crm_fields.whatsappTpl.insertEmployeeName')" location="top">
                                        <template #activator="{ props }">
                                            <v-btn
                                                v-bind="props"
                                                icon="mdi-account-plus"
                                                size="x-small"
                                                variant="text"
                                                @click="insertPlaceholder('body_text', '{{mitarbeiter_name}}')"
                                            />
                                        </template>
                                    </v-tooltip>
                                    <v-tooltip :text="t('crm_fields.whatsappTpl.insertCustomerName')" location="top">
                                        <template #activator="{ props }">
                                            <v-btn
                                                v-bind="props"
                                                icon="mdi-account-arrow-right"
                                                size="x-small"
                                                variant="text"
                                                @click="insertPlaceholder('body_text', '{{kunde_name}}')"
                                            />
                                        </template>
                                    </v-tooltip>
                                </div>
                            </template>
                        </v-textarea>

                        <!-- Beispielwerte für Meta-Variablen -->
                        <v-alert
                            v-if="bodyVariables.length > 0"
                            type="info"
                            variant="tonal"
                            density="compact"
                            class="mb-3"
                        >
                            <div class="text-caption font-weight-bold mb-2">{{ t('crm_fields.whatsappTpl.exampleTitle') }}</div>
                            <v-text-field
                                v-for="v in bodyVariables"
                                :key="v"
                                v-model="editingTemplate.example_values.body[v - 1]"
                                :label="'{{' + v + '}}'"
                                variant="outlined"
                                density="compact"
                                class="mb-1"
                                :placeholder="t('crm_fields.whatsappTpl.examplePlaceholder', { nr: v })"
                                hide-details
                            />
                        </v-alert>

                        <v-text-field
                            v-model="editingTemplate.footer_text"
                            :label="t('crm_fields.whatsappTpl.footerText')"
                            variant="outlined"
                            density="compact"
                        >
                            <template #append-inner>
                                <v-tooltip :text="t('crm_fields.whatsappTpl.insertEmployeeName')" location="top">
                                    <template #activator="{ props }">
                                        <v-btn
                                            v-bind="props"
                                            icon="mdi-account-plus"
                                            size="x-small"
                                            variant="text"
                                            @click="insertPlaceholder('footer_text', '{{mitarbeiter_name}}')"
                                        />
                                    </template>
                                </v-tooltip>
                                <v-tooltip :text="t('crm_fields.whatsappTpl.insertCustomerName')" location="top">
                                    <template #activator="{ props }">
                                        <v-btn
                                            v-bind="props"
                                            icon="mdi-account-arrow-right"
                                            size="x-small"
                                            variant="text"
                                            @click="insertPlaceholder('footer_text', '{{kunde_name}}')"
                                        />
                                    </template>
                                </v-tooltip>
                            </template>
                        </v-text-field>
                    </v-col>

                    <!-- Right: Live Preview -->
                    <v-col cols="12" md="5">
                        <div class="text-subtitle-2 mb-2">
                            {{ t('crm_fields.whatsappTpl.preview') }}
                        </div>
                        <v-card variant="outlined" class="pa-0" style="background: #e5ddd5; border-radius: 8px;">
                            <div class="pa-3">
                                <!-- Preview Bubble -->
                                <div
                                    style="background: #dcf8c6; border-radius: 8px; padding: 8px 12px; max-width: 100%; word-wrap: break-word; box-shadow: 0 1px 1px rgba(0,0,0,0.1);"
                                >
                                    <!-- Header -->
                                    <div
                                        v-if="editingTemplate.header_type === 'DOCUMENT'"
                                        class="d-flex align-center mb-2 pa-2 rounded"
                                        style="background: rgba(0,0,0,0.06);"
                                    >
                                        <v-icon size="small" class="mr-2">mdi-file-pdf-box</v-icon>
                                        <span class="text-caption">PDF-Dokument</span>
                                    </div>
                                    <div
                                        v-else-if="editingTemplate.header_type === 'IMAGE'"
                                        class="d-flex align-center mb-2 pa-2 rounded"
                                        style="background: rgba(0,0,0,0.06);"
                                    >
                                        <v-icon size="small" class="mr-2">mdi-image</v-icon>
                                        <span class="text-caption">Bild</span>
                                    </div>
                                    <div
                                        v-else-if="editingTemplate.header_type === 'VIDEO'"
                                        class="d-flex align-center mb-2 pa-2 rounded"
                                        style="background: rgba(0,0,0,0.06);"
                                    >
                                        <v-icon size="small" class="mr-2">mdi-video</v-icon>
                                        <span class="text-caption">Video</span>
                                    </div>
                                    <div
                                        v-else-if="editingTemplate.header_text"
                                        class="font-weight-bold text-body-2 mb-1"
                                    >
                                        {{ editingTemplate.header_text }}
                                    </div>

                                    <!-- Body with highlighted placeholders -->
                                    <div
                                        class="text-body-2"
                                        style="white-space: pre-wrap;"
                                        v-html="highlightedBody"
                                    />

                                    <!-- Footer -->
                                    <div
                                        v-if="editingTemplate.footer_text"
                                        class="text-caption text-grey-darken-1 mt-1"
                                    >
                                        {{ resolvedFooter }}
                                    </div>
                                </div>
                            </div>
                        </v-card>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-btn variant="text" @click="cancelEdit">
                    {{ t('crm_fields.whatsappTpl.cancel') }}
                </v-btn>
                <v-spacer />
                <v-btn
                    color="primary"
                    variant="flat"
                    :loading="saving"
                    prepend-icon="mdi-content-save"
                    @click="saveTemplate"
                >
                    {{ t('crm_fields.whatsappTpl.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>

        <!-- Delete Confirmation (inline statt Dialog) -->
        <v-card v-if="deletingTemplate" variant="outlined" color="error" class="mt-4">
            <v-card-text class="d-flex align-center ga-3 pa-4">
                <v-icon color="error">mdi-alert</v-icon>
                <span>{{ t('crm_fields.whatsappTpl.deleteConfirm', { name: deletingTemplate?.display_name }) }}</span>
                <v-spacer />
                <v-btn variant="text" size="small" @click="deletingTemplate = null">
                    {{ t('crm_fields.whatsappTpl.cancel') }}
                </v-btn>
                <v-btn
                    color="error"
                    variant="flat"
                    size="small"
                    :loading="deleting"
                    @click="deleteTemplate"
                >
                    {{ t('crm_fields.whatsappTpl.delete') }}
                </v-btn>
            </v-card-text>
        </v-card>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import * as toast from '@/core/utils/toasts.js';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const store = oserpStore();

// State
const templates = ref([]);
const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const syncing = ref(false);
const loadingDefaults = ref(false);
const submittingId = ref(null);
const searchQuery = ref('');
const editing = ref(false);
const deletingTemplate = ref(null);

const emptyTemplate = {
    id: null,
    name: '',
    display_name: '',
    category: 'UTILITY',
    template_type: 'general',
    header_type: null,
    header_text: '',
    body_text: '',
    footer_text: '',
    status: 'draft',
    example_values: { body: [], header: [] }
};

const editingTemplate = ref({ ...emptyTemplate });

// Table Headers
const tableHeaders = computed(() => [
    { title: t('crm_fields.whatsappTpl.colDisplayName'), key: 'display_name', sortable: true },
    { title: t('crm_fields.whatsappTpl.colType'), key: 'template_type', sortable: true, width: '120px' },
    { title: t('crm_fields.whatsappTpl.colBody'), key: 'body_text', sortable: false },
    { title: t('crm_fields.whatsappTpl.colStatus'), key: 'status', sortable: true, width: '120px' },
    { title: t('crm_fields.whatsappTpl.colActions'), key: 'actions', sortable: false, width: '150px', align: 'end' }
]);

// Dropdown Options
const categoryOptions = [
    { title: 'UTILITY', value: 'UTILITY' },
    { title: 'MARKETING', value: 'MARKETING' }
];

const templateTypeOptions = computed(() => [
    { title: t('crm_fields.whatsappTpl.type_general'), value: 'general' },
    { title: t('crm_fields.whatsappTpl.type_chat'), value: 'chat' },
    { title: t('crm_fields.whatsappTpl.type_document'), value: 'document' },
    { title: t('crm_fields.whatsappTpl.type_hu'), value: 'hu' },
    { title: t('crm_fields.whatsappTpl.type_reminder'), value: 'reminder' },
    { title: t('crm_fields.whatsappTpl.type_appointment_confirm'), value: 'appointment_confirm' },
    { title: t('crm_fields.whatsappTpl.type_address'), value: 'address' }
]);

const headerTypeOptions = [
    { title: 'Kein Header', value: null },
    { title: 'Text', value: 'TEXT' },
    { title: 'Dokument (PDF)', value: 'DOCUMENT' },
    { title: 'Bild', value: 'IMAGE' },
    { title: 'Video', value: 'VIDEO' }
];

// Computed
const filteredTemplates = computed(() => {
    if (!searchQuery.value) return templates.value;
    const q = searchQuery.value.toLowerCase();
    return templates.value.filter(tpl =>
        (tpl.display_name || '').toLowerCase().includes(q) ||
        (tpl.name || '').toLowerCase().includes(q) ||
        (tpl.body_text || '').toLowerCase().includes(q) ||
        (tpl.template_type || '').toLowerCase().includes(q)
    );
});

const employeeName = computed(() => store.session?.logged_in_employee?.name || '');

// Meta-Variablen {{1}}, {{2}} etc. aus Body-Text extrahieren
const bodyVariables = computed(() => {
    const text = editingTemplate.value.body_text || '';
    const matches = text.match(/\{\{(\d+)\}\}/g) || [];
    const nums = [...new Set(matches.map(m => parseInt(m.replace(/[{}]/g, ''))))];
    return nums.sort((a, b) => a - b);
});

const resolvedFooter = computed(() => {
    return resolvePlaceholders(editingTemplate.value.footer_text || '');
});

function resolvePlaceholders(text) {
    return text
        .replace(/\{\{mitarbeiter_name\}\}/g, employeeName.value)
        .replace(/\{\{kunde_name\}\}/g, t('crm_fields.whatsappTpl.placeholderPreviewCustomer'));
}

const highlightedBody = computed(() => {
    const text = editingTemplate.value.body_text || '';
    const escaped = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    return escaped
        .replace(/\{\{mitarbeiter_name\}\}/g, '<span style="background: #c8e6c9; padding: 1px 4px; border-radius: 3px;">' + employeeName.value + '</span>')
        .replace(/\{\{kunde_name\}\}/g, '<span style="background: #bbdefb; padding: 1px 4px; border-radius: 3px;">' + t('crm_fields.whatsappTpl.placeholderPreviewCustomer') + '</span>')
        .replace(
            /(\{\{\d+\}\})/g,
            '<span style="background: #ffeb3b; padding: 1px 4px; border-radius: 3px; font-weight: 600;">$1</span>'
        );
});

// Helpers
function statusColor(status) {
    const colors = {
        draft: 'grey',
        pending: 'orange',
        approved: 'green',
        rejected: 'red'
    };
    return colors[status] || 'grey';
}

function templateTypeColor(type) {
    const colors = {
        general: 'blue-grey',
        chat: 'teal',
        document: 'blue',
        hu: 'orange',
        reminder: 'amber-darken-2',
        appointment_confirm: 'green',
        address: 'deep-purple'
    };
    return colors[type] || 'blue-grey';
}

function generateName() {
    const display = editingTemplate.value.display_name || '';
    editingTemplate.value.name = display
        .toLowerCase()
        .replace(/[äÄ]/g, 'ae')
        .replace(/[öÖ]/g, 'oe')
        .replace(/[üÜ]/g, 'ue')
        .replace(/[ß]/g, 'ss')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function insertPlaceholder(field, placeholder) {
    editingTemplate.value[field] = (editingTemplate.value[field] || '') + placeholder;
}

// Edit Operations
function openCreate() {
    editingTemplate.value = { ...emptyTemplate, example_values: { body: [], header: [] } };
    editing.value = true;
}

function openEdit(item) {
    const ex = typeof item.example_values === 'string'
        ? JSON.parse(item.example_values || '{}')
        : (item.example_values || {});
    editingTemplate.value = {
        ...JSON.parse(JSON.stringify(item)),
        example_values: { body: ex.body || [], header: ex.header || [] }
    };
    editing.value = true;
}

function cancelEdit() {
    editing.value = false;
    editingTemplate.value = { ...emptyTemplate };
}

function confirmDelete(item) {
    deletingTemplate.value = item;
}

// API Operations
async function loadTemplates() {
    loading.value = true;
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'getWhatsAppTemplates'
        });
        if (resp.data.success) {
            templates.value = resp.data.payload?.templates || [];
        }
    } catch {
        toast.error(t('crm_fields.whatsappTpl.loadError'));
    } finally {
        loading.value = false;
    }
}

async function saveTemplate() {
    if (!editingTemplate.value.display_name || !editingTemplate.value.body_text) {
        toast.warning(t('crm_fields.whatsappTpl.fillRequired'));
        return;
    }

    saving.value = true;
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'saveWhatsAppTemplate',
            template: editingTemplate.value
        });
        if (resp.data.success) {
            toast.success(t('crm_fields.whatsappTpl.saveSuccess'));
            editing.value = false;
            await loadTemplates();
        } else {
            toast.error(resp.data.payload || resp.data.text || t('crm_fields.whatsappTpl.saveError'));
        }
    } catch {
        toast.error(t('crm_fields.whatsappTpl.saveError'));
    } finally {
        saving.value = false;
    }
}

async function deleteTemplate() {
    if (!deletingTemplate.value) return;

    deleting.value = true;
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'deleteWhatsAppTemplate',
            id: deletingTemplate.value.id
        });
        if (resp.data.success) {
            toast.success(t('crm_fields.whatsappTpl.deleteSuccess'));
            deletingTemplate.value = null;
            await loadTemplates();
        } else {
            toast.error(resp.data.payload || resp.data.text || t('crm_fields.whatsappTpl.deleteError'));
        }
    } catch {
        toast.error(t('crm_fields.whatsappTpl.deleteError'));
    } finally {
        deleting.value = false;
    }
}

async function submitToMeta(item) {
    submittingId.value = item.id;
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'submitTemplateToMeta',
            id: item.id
        });
        if (resp.data.success) {
            toast.success(t('crm_fields.whatsappTpl.submitSuccess'));
            await loadTemplates();
        } else {
            toast.error(resp.data.payload || resp.data.text || t('crm_fields.whatsappTpl.submitError'));
        }
    } catch {
        toast.error(t('crm_fields.whatsappTpl.submitError'));
    } finally {
        submittingId.value = null;
    }
}

async function loadDefaultTemplates() {
    loadingDefaults.value = true;
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'loadDefaultTemplates'
        });
        if (resp.data.success) {
            toast.success(t('crm_fields.whatsappTpl.defaultsLoaded'));
            await loadTemplates();
        } else {
            toast.error(resp.data.payload || resp.data.text || t('crm_fields.whatsappTpl.defaultsError'));
        }
    } catch {
        toast.error(t('crm_fields.whatsappTpl.defaultsError'));
    } finally {
        loadingDefaults.value = false;
    }
}

async function syncFromMeta() {
    syncing.value = true;
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'syncWhatsAppTemplates'
        });
        if (resp.data.success) {
            toast.success(t('crm_fields.whatsappTpl.syncSuccess'));
            await loadTemplates();
        } else {
            toast.error(resp.data.payload || resp.data.text || t('crm_fields.whatsappTpl.syncError'));
        }
    } catch {
        toast.error(t('crm_fields.whatsappTpl.syncError'));
    } finally {
        syncing.value = false;
    }
}

// Init
onMounted(() => {
    loadTemplates();
});
</script>
