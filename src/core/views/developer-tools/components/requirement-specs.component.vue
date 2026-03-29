<!-- src/core/views/developer-tools/components/requirement-specs.component.vue -->
<template>
    <div>
        <!-- Header -->
        <div class="d-flex align-center mb-4">
            <div>
                <div class="text-h6 font-weight-bold">{{ t('DeveloperTools.reqSpecs.title') }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.reqSpecs.description') }}</div>
            </div>
            <v-spacer />
            <v-btn
                color="primary"
                prepend-icon="mdi-plus"
                @click="openSpecDialog()"
            >
                {{ t('DeveloperTools.reqSpecs.newSpec') }}
            </v-btn>
        </div>

        <!-- Loading -->
        <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

        <!-- Leere Ansicht -->
        <v-card v-if="!loading && specs.length === 0" variant="tonal" class="pa-8 text-center">
            <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-file-document-outline</v-icon>
            <div class="text-h6 text-grey">{{ t('DeveloperTools.reqSpecs.empty') }}</div>
            <div class="text-caption text-grey mt-1">{{ t('DeveloperTools.reqSpecs.emptyHint') }}</div>
        </v-card>

        <!-- Pflichtenheft-Liste -->
        <v-row v-else>
            <v-col v-for="spec in specs" :key="spec.id" cols="12" md="6" lg="4">
                <v-card
                    class="spec-card h-100"
                    :class="{ 'border-primary': selectedSpec?.id === spec.id }"
                    hover
                    @click="loadSpec(spec.id)"
                >
                    <v-card-title class="d-flex align-center pb-1">
                        <v-icon start size="small" color="primary">mdi-file-document-check</v-icon>
                        <span class="text-truncate">{{ spec.title }}</span>
                        <v-spacer />
                        <v-chip v-if="spec.status_name" size="x-small" :color="getStatusColor(spec.status_name)" variant="tonal">
                            {{ spec.status_name }}
                        </v-chip>
                    </v-card-title>

                    <v-card-text class="pt-2">
                        <div class="d-flex flex-wrap ga-2 mb-2">
                            <v-chip v-if="spec.customer_name" size="x-small" prepend-icon="mdi-account" variant="outlined">
                                {{ spec.customer_name }}
                            </v-chip>
                            <v-chip v-if="spec.project_name" size="x-small" prepend-icon="mdi-folder" variant="outlined">
                                {{ spec.project_name }}
                            </v-chip>
                            <v-chip v-if="spec.type_name" size="x-small" variant="outlined">
                                {{ spec.type_name }}
                            </v-chip>
                        </div>

                        <v-divider class="my-2" />

                        <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                            <span>
                                <v-icon size="14">mdi-format-list-checks</v-icon>
                                {{ spec.item_count }} {{ t('DeveloperTools.reqSpecs.items') }}
                            </span>
                            <span>
                                <v-icon size="14">mdi-clock-outline</v-icon>
                                {{ formatHours(spec.total_hours) }}h
                            </span>
                            <span v-if="spec.hourly_rate > 0">
                                <v-icon size="14">mdi-currency-eur</v-icon>
                                {{ formatCurrency(spec.total_hours * spec.hourly_rate) }}
                            </span>
                        </div>
                    </v-card-text>

                    <v-card-actions class="pt-0">
                        <v-btn size="small" variant="text" color="primary" @click.stop="loadSpec(spec.id)">
                            <v-icon start size="small">mdi-eye</v-icon>
                            {{ t('DeveloperTools.reqSpecs.details') }}
                        </v-btn>
                        <v-spacer />
                        <v-btn size="small" icon="mdi-pencil" variant="text" @click.stop="openSpecDialog(spec)" />
                        <v-btn size="small" icon="mdi-delete" variant="text" color="error" @click.stop="confirmDeleteSpec(spec)" />
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>

        <!-- Detail-Ansicht eines Pflichtenhefts -->
        <v-dialog v-model="showDetail" max-width="1200" scrollable>
            <v-card v-if="selectedSpec">
                <v-card-title class="d-flex align-center bg-primary text-white">
                    <v-icon start>mdi-file-document-check</v-icon>
                    {{ selectedSpec.title }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" color="white" @click="showDetail = false" />
                </v-card-title>

                <v-card-text class="pa-4">
                    <!-- Metadaten -->
                    <v-row class="mb-4">
                        <v-col cols="12" md="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.reqSpecs.customer') }}</div>
                            <div class="font-weight-medium">{{ selectedSpec.customer_name || '–' }}</div>
                        </v-col>
                        <v-col cols="12" md="3">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.reqSpecs.project') }}</div>
                            <div class="font-weight-medium">{{ selectedSpec.project_name || '–' }}</div>
                        </v-col>
                        <v-col cols="12" md="2">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.reqSpecs.status') }}</div>
                            <v-chip size="small" :color="getStatusColor(selectedSpec.status_name)" variant="tonal">
                                {{ selectedSpec.status_name || '–' }}
                            </v-chip>
                        </v-col>
                        <v-col cols="12" md="2">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.reqSpecs.hourlyRate') }}</div>
                            <div class="font-weight-medium">{{ formatCurrency(selectedSpec.hourly_rate) }}/h</div>
                        </v-col>
                        <v-col cols="12" md="2">
                            <div class="text-caption text-medium-emphasis">{{ t('DeveloperTools.reqSpecs.totalEstimation') }}</div>
                            <div class="font-weight-medium">{{ formatHours(selectedSpec.time_estimation) }}h</div>
                        </v-col>
                    </v-row>

                    <v-divider class="mb-4" />

                    <!-- Toolbar für Items -->
                    <div class="d-flex align-center mb-3">
                        <div class="text-subtitle-1 font-weight-bold">
                            <v-icon start size="small">mdi-file-tree</v-icon>
                            {{ t('DeveloperTools.reqSpecs.sections') }}
                        </div>
                        <v-spacer />
                        <v-btn size="small" color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openItemDialog(null)">
                            {{ t('DeveloperTools.reqSpecs.newSection') }}
                        </v-btn>
                    </div>

                    <!-- Baumansicht der Positionen -->
                    <div v-if="specItems.length === 0" class="text-center pa-6 text-grey">
                        <v-icon size="48" color="grey-lighten-2">mdi-file-tree-outline</v-icon>
                        <div class="mt-2">{{ t('DeveloperTools.reqSpecs.noItems') }}</div>
                    </div>

                    <div v-else>
                        <v-card
                            v-for="section in rootItems"
                            :key="section.id"
                            variant="outlined"
                            class="mb-2"
                        >
                            <v-card-title class="d-flex align-center py-2 px-4" style="font-size: 0.95rem">
                                <v-icon start size="small" color="primary">mdi-folder-outline</v-icon>
                                <span class="text-body-2 font-weight-bold">{{ section.fb_number }}</span>
                                <span class="ml-2">{{ section.title }}</span>
                                <v-chip v-if="section.is_flagged === 't' || section.is_flagged === true" size="x-small" color="warning" variant="flat" class="ml-2">
                                    <v-icon size="12">mdi-flag</v-icon>
                                </v-chip>
                                <v-spacer />
                                <span class="text-caption text-medium-emphasis mr-3">
                                    {{ formatHours(section.time_estimation) }}h
                                </span>
                                <v-btn size="x-small" icon="mdi-plus" variant="text" @click.stop="openItemDialog(section)" />
                                <v-btn size="x-small" icon="mdi-pencil" variant="text" @click.stop="openItemDialog(null, section)" />
                                <v-btn size="x-small" icon="mdi-delete" variant="text" color="error" @click.stop="confirmDeleteItem(section)" />
                            </v-card-title>

                            <!-- Funktionsblöcke -->
                            <div v-if="getChildren(section.id).length > 0">
                                <v-divider />
                                <v-list density="compact" class="py-0">
                                    <v-list-item
                                        v-for="child in getChildren(section.id)"
                                        :key="child.id"
                                        class="pl-8"
                                    >
                                        <template #prepend>
                                            <v-icon size="small" color="secondary">mdi-puzzle-outline</v-icon>
                                        </template>
                                        <v-list-item-title class="d-flex align-center">
                                            <span class="text-caption font-weight-bold mr-2">{{ child.fb_number }}</span>
                                            <span class="text-body-2">{{ child.title }}</span>
                                            <v-chip v-if="child.complexity_name" size="x-small" variant="outlined" class="ml-2">
                                                {{ child.complexity_name }}
                                            </v-chip>
                                            <v-chip v-if="child.risk_name" size="x-small" variant="outlined" color="warning" class="ml-1">
                                                {{ child.risk_name }}
                                            </v-chip>
                                            <v-chip v-if="child.is_flagged === 't' || child.is_flagged === true" size="x-small" color="warning" variant="flat" class="ml-1">
                                                <v-icon size="12">mdi-flag</v-icon>
                                            </v-chip>
                                        </v-list-item-title>
                                        <template #append>
                                            <span class="text-caption text-medium-emphasis mr-3">
                                                {{ formatHours(child.time_estimation) }}h
                                            </span>
                                            <v-btn size="x-small" icon="mdi-pencil" variant="text" @click.stop="openItemDialog(null, child)" />
                                            <v-btn size="x-small" icon="mdi-delete" variant="text" color="error" @click.stop="confirmDeleteItem(child)" />
                                        </template>
                                    </v-list-item>
                                </v-list>
                            </div>
                        </v-card>
                    </div>

                    <!-- Textblöcke -->
                    <div v-if="specTextBlocks.length > 0" class="mt-4">
                        <div class="text-subtitle-1 font-weight-bold mb-3">
                            <v-icon start size="small">mdi-text-box-outline</v-icon>
                            {{ t('DeveloperTools.reqSpecs.textBlocks') }}
                        </div>
                        <v-expansion-panels variant="accordion">
                            <v-expansion-panel v-for="tb in specTextBlocks" :key="tb.id">
                                <v-expansion-panel-title>{{ tb.title }}</v-expansion-panel-title>
                                <v-expansion-panel-text>
                                    <div v-html="tb.text || '–'" />
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Pflichtenheft erstellen/bearbeiten Dialog -->
        <v-dialog v-model="showSpecDialog" max-width="700" persistent>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon start>mdi-file-document-edit</v-icon>
                    {{ editingSpec?.id ? t('DeveloperTools.reqSpecs.editSpec') : t('DeveloperTools.reqSpecs.newSpec') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="showSpecDialog = false" />
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <!-- Validierungsfehler -->
                    <v-alert v-if="specError" type="error" variant="tonal" density="compact" closable class="mb-4" @click:close="specError = ''">
                        {{ specError }}
                    </v-alert>

                    <v-row>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editingSpec.title"
                                :label="t('DeveloperTools.reqSpecs.specTitle') + ' *'"
                                variant="outlined"
                                density="comfortable"
                                autofocus
                                :rules="[v => !!v || t('DeveloperTools.reqSpecs.required')]"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="editingSpec.type_id"
                                :items="masterData.types"
                                item-title="description"
                                item-value="id"
                                :label="t('DeveloperTools.reqSpecs.type') + ' *'"
                                variant="outlined"
                                density="comfortable"
                                :rules="[v => !!v || t('DeveloperTools.reqSpecs.required')]"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="editingSpec.status_id"
                                :items="masterData.statuses"
                                :item-title="s => s.name + ' – ' + s.description"
                                item-value="id"
                                :label="t('DeveloperTools.reqSpecs.status') + ' *'"
                                variant="outlined"
                                density="comfortable"
                                :rules="[v => !!v || t('DeveloperTools.reqSpecs.required')]"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-autocomplete
                                v-model="editingSpec.customer_id"
                                :items="masterData.customers"
                                item-title="name"
                                item-value="id"
                                :label="t('DeveloperTools.reqSpecs.customer') + ' *'"
                                variant="outlined"
                                density="comfortable"
                                :rules="[v => !!v || t('DeveloperTools.reqSpecs.required')]"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="editingSpec.project_id"
                                :items="masterData.projects"
                                :item-title="p => p.projectnumber + ' – ' + p.description"
                                item-value="id"
                                :label="t('DeveloperTools.reqSpecs.project')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field
                                v-model="editingSpec.hourly_rate"
                                :label="t('DeveloperTools.reqSpecs.hourlyRate')"
                                variant="outlined"
                                density="comfortable"
                                type="number"
                                suffix="€/h"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-text-field
                                v-model="editingSpec.time_estimation"
                                :label="t('DeveloperTools.reqSpecs.timeEstimation')"
                                variant="outlined"
                                density="comfortable"
                                type="number"
                                suffix="h"
                                hide-details="auto"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-divider />
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn variant="text" @click="showSpecDialog = false">{{ t('cancel') }}</v-btn>
                    <v-btn variant="flat" color="primary" :loading="saving" @click="saveSpec">
                        {{ t('DeveloperTools.reqSpecs.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Item erstellen/bearbeiten Dialog -->
        <v-dialog v-model="showItemDialog" max-width="700" persistent>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon start>mdi-puzzle-edit</v-icon>
                    {{ editingItem?.id ? t('DeveloperTools.reqSpecs.editItem') : t('DeveloperTools.reqSpecs.newItem') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="showItemDialog = false" />
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <v-row>
                        <v-col cols="12" md="8">
                            <v-text-field
                                v-model="editingItem.title"
                                :label="t('DeveloperTools.reqSpecs.itemTitle')"
                                variant="outlined"
                                density="comfortable"
                                autofocus
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingItem.item_type"
                                :items="itemTypes"
                                item-title="text"
                                item-value="value"
                                :label="t('DeveloperTools.reqSpecs.itemType')"
                                variant="outlined"
                                density="comfortable"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea
                                v-model="editingItem.description"
                                :label="t('DeveloperTools.reqSpecs.itemDescription')"
                                variant="outlined"
                                density="comfortable"
                                rows="3"
                                auto-grow
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingItem.complexity_id"
                                :items="masterData.complexities"
                                item-title="description"
                                item-value="id"
                                :label="t('DeveloperTools.reqSpecs.complexity')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="editingItem.risk_id"
                                :items="masterData.risks"
                                item-title="description"
                                item-value="id"
                                :label="t('DeveloperTools.reqSpecs.risk')"
                                variant="outlined"
                                density="comfortable"
                                clearable
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field
                                v-model="editingItem.time_estimation"
                                :label="t('DeveloperTools.reqSpecs.timeEstimation')"
                                variant="outlined"
                                density="comfortable"
                                type="number"
                                suffix="h"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-checkbox
                                v-model="editingItem.is_flagged"
                                :label="t('DeveloperTools.reqSpecs.flagged')"
                                density="comfortable"
                                color="warning"
                                hide-details
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-divider />
                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn variant="text" @click="showItemDialog = false">{{ t('cancel') }}</v-btn>
                    <v-btn variant="flat" color="primary" :loading="saving" @click="saveItem">
                        {{ t('DeveloperTools.reqSpecs.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const loading = ref(false);
const saving = ref(false);
const specs = ref([]);
const selectedSpec = ref(null);
const specItems = ref([]);
const specTextBlocks = ref([]);
const showDetail = ref(false);
const showSpecDialog = ref(false);
const showItemDialog = ref(false);
const editingSpec = ref({});
const editingItem = ref({});
const masterData = ref({ types: [], statuses: [], complexities: [], risks: [], acceptanceStatuses: [], customers: [], projects: [] });
const specError = ref('');

const itemTypes = [
    { value: 'section', text: t('DeveloperTools.reqSpecs.typeSection') },
    { value: 'function-block', text: t('DeveloperTools.reqSpecs.typeFunctionBlock') },
    { value: 'sub-function-block', text: t('DeveloperTools.reqSpecs.typeSubFunctionBlock') },
];

const rootItems = computed(() => specItems.value.filter(i => !i.parent_id));

function getChildren(parentId) {
    return specItems.value.filter(i => String(i.parent_id) === String(parentId));
}

function getStatusColor(status) {
    if (!status) return 'grey';
    const s = status.toLowerCase();
    if (s.includes('neu') || s.includes('new')) return 'info';
    if (s.includes('arbeit') || s.includes('progress') || s.includes('work')) return 'warning';
    if (s.includes('fertig') || s.includes('done') || s.includes('complete')) return 'success';
    if (s.includes('abgenommen') || s.includes('accept')) return 'success';
    return 'grey';
}

function formatHours(h) {
    return parseFloat(h || 0).toFixed(1);
}

function formatCurrency(val) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(val || 0);
}

async function loadSpecs() {
    loading.value = true;
    try {
        const res = await axios.post('/api/project_management/', { action: 'getRequirementSpecs' });
        if (res.data.success) specs.value = res.data.payload.specs;
    } catch (e) { console.error(e); }
    loading.value = false;
}

async function loadSpec(id) {
    try {
        const res = await axios.post('/api/project_management/', { action: 'getRequirementSpec', id });
        if (res.data.success) {
            selectedSpec.value = res.data.payload.spec;
            specItems.value = res.data.payload.items;
            specTextBlocks.value = res.data.payload.textBlocks;
            showDetail.value = true;
        }
    } catch (e) { console.error(e); }
}

async function loadMasterData() {
    try {
        const res = await axios.post('/api/project_management/', { action: 'getRequirementSpecMasterData' });
        if (res.data.success) masterData.value = res.data.payload;
    } catch (e) { console.error(e); }
}

function openSpecDialog(spec = null) {
    specError.value = '';
    editingSpec.value = spec
        ? { ...spec }
        : { title: '', type_id: null, status_id: null, customer_id: null, project_id: null, hourly_rate: 0, time_estimation: 0 };
    showSpecDialog.value = true;
}

async function saveSpec() {
    specError.value = '';

    // Frontend-Validierung
    if (!editingSpec.value.title) {
        specError.value = t('DeveloperTools.reqSpecs.specTitle') + ' ' + t('DeveloperTools.reqSpecs.required');
        return;
    }
    if (!editingSpec.value.type_id) {
        specError.value = t('DeveloperTools.reqSpecs.type') + ' ' + t('DeveloperTools.reqSpecs.required');
        return;
    }
    if (!editingSpec.value.status_id) {
        specError.value = t('DeveloperTools.reqSpecs.status') + ' ' + t('DeveloperTools.reqSpecs.required');
        return;
    }
    if (!editingSpec.value.customer_id) {
        specError.value = t('DeveloperTools.reqSpecs.customer') + ' ' + t('DeveloperTools.reqSpecs.required');
        return;
    }

    saving.value = true;
    try {
        const res = await axios.post('/api/project_management/', { action: 'saveRequirementSpec', spec: editingSpec.value });
        if (res.data.success) {
            showSpecDialog.value = false;
            await loadSpecs();
        } else {
            specError.value = res.data.payload || res.data.text || 'Fehler beim Speichern';
        }
    } catch (e) {
        specError.value = e.message || 'Netzwerkfehler';
        console.error(e);
    }
    saving.value = false;
}

async function confirmDeleteSpec(spec) {
    if (!confirm(t('DeveloperTools.reqSpecs.confirmDelete', { title: spec.title }))) return;
    try {
        await axios.post('/api/project_management/', { action: 'deleteRequirementSpec', id: spec.id });
        await loadSpecs();
    } catch (e) { console.error(e); }
}

function openItemDialog(parentSection = null, existingItem = null) {
    if (existingItem) {
        editingItem.value = { ...existingItem };
    } else {
        editingItem.value = {
            requirement_spec_id: selectedSpec.value.id,
            item_type: parentSection ? 'function-block' : 'section',
            parent_id: parentSection?.id || null,
            title: '',
            description: '',
            time_estimation: 0,
            is_flagged: false,
        };
    }
    showItemDialog.value = true;
}

async function saveItem() {
    saving.value = true;
    try {
        const res = await axios.post('/api/project_management/', { action: 'saveRequirementSpecItem', item: editingItem.value });
        if (res.data.success) {
            showItemDialog.value = false;
            await loadSpec(selectedSpec.value.id);
        }
    } catch (e) { console.error(e); }
    saving.value = false;
}

async function confirmDeleteItem(item) {
    if (!confirm(t('DeveloperTools.reqSpecs.confirmDeleteItem', { title: item.title }))) return;
    try {
        await axios.post('/api/project_management/', { action: 'deleteRequirementSpecItem', id: item.id });
        await loadSpec(selectedSpec.value.id);
    } catch (e) { console.error(e); }
}

onMounted(() => {
    loadSpecs();
    loadMasterData();
});
</script>

<style scoped>
.spec-card {
    transition: all 0.2s ease;
    cursor: pointer;
}
.spec-card:hover {
    transform: translateY(-2px);
}
.border-primary {
    border: 2px solid rgb(var(--v-theme-primary)) !important;
}
</style>
