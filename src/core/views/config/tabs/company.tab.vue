<!-- src/core/views/config/tabs/company.tab.vue -->
<template>
    <div>
        <!-- Firmenname und Adresse -->
        <h3 class="text-h6 mb-4">{{ $t('companyNameAndAddress') }}</h3>

        <v-row>
            <v-col cols="12">
                <v-text-field
                    v-model="defaults.company"
                    :label="$t('companyName')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.address_street1"
                    :label="$t('street1')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.address_street2"
                    :label="$t('street2')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="4">
                <v-text-field
                    v-model="defaults.address_zipcode"
                    :label="$t('zipcode')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="8">
                <v-text-field
                    v-model="defaults.address_city"
                    :label="$t('city')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.address_country"
                    :label="$t('country')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Firmenlogo -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('companyLogo') }}</h3>
            </v-col>

            <v-col cols="12">
                <div class="d-flex align-center ga-4 mb-4">
                    <img
                        v-if="companyLogo"
                        :src="companyLogo"
                        class="company-logo-preview border rounded-lg"
                    >
                    <div v-else class="company-logo-placeholder border rounded-lg d-flex align-center justify-center text-grey">
                        <v-icon size="32">mdi-image-outline</v-icon>
                    </div>
                    <div class="d-flex flex-column ga-2">
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="small"
                            prepend-icon="mdi-image-plus"
                            @click="logoDialogOpen = true"
                        >
                            {{ $t('selectImage') }}
                        </v-btn>
                        <v-btn
                            v-if="companyLogo"
                            color="error"
                            variant="text"
                            size="small"
                            prepend-icon="mdi-delete"
                            @click="deleteLogo"
                        >
                            {{ $t('deleteLogo') }}
                        </v-btn>
                    </div>
                </div>

                <!-- Logo-Upload-Dialog -->
                <v-dialog v-model="logoDialogOpen" max-width="500">
                    <v-card>
                        <v-card-title>{{ $t('companyLogo') }}</v-card-title>
                        <v-card-text>
                            <div v-if="logoPreview" class="d-flex flex-column align-center">
                                <canvas
                                    ref="logoCropCanvas"
                                    :width="cropWidth"
                                    :height="cropHeight"
                                    class="border rounded-lg mb-3"
                                    style="max-width: 100%; cursor: crosshair"
                                    @mousedown="cropStart"
                                    @mousemove="cropMove"
                                    @mouseup="cropEnd"
                                />
                                <div class="d-flex align-center ga-2 mb-3" style="width: 100%; max-width: 300px">
                                    <v-icon size="small">mdi-magnify-minus</v-icon>
                                    <v-slider
                                        v-model="cropScale"
                                        :min="cropScaleMin"
                                        :max="3"
                                        :step="0.01"
                                        hide-details
                                        density="compact"
                                        @update:model-value="drawCrop"
                                    />
                                    <v-icon size="small">mdi-magnify-plus</v-icon>
                                </div>
                            </div>
                            <div v-else class="text-center text-grey py-8">
                                {{ $t('logoHint') }}
                            </div>
                            <v-file-input
                                accept="image/png, image/jpeg, image/webp"
                                :label="$t('selectImage')"
                                variant="outlined"
                                density="compact"
                                prepend-icon=""
                                prepend-inner-icon="mdi-camera"
                                hide-details
                                @update:model-value="onLogoFileSelected"
                            />
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer />
                            <v-btn variant="text" @click="logoDialogOpen = false">
                                {{ $t('cancel') }}
                            </v-btn>
                            <v-btn color="primary" variant="elevated" :disabled="!logoPreview" @click="saveLogo">
                                {{ $t('saveLogo') }}
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
            </v-col>

            <!-- Firmeneinstellungen -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('companySettings') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-textarea
                    v-model="defaults.signature"
                    :label="$t('signature')"
                    variant="outlined"
                    rows="4"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.co_ustid"
                    :label="$t('vatId')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.sepa_creditor_id"
                    :label="$t('sepaCreditorId')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.businessnumber"
                    :label="$t('businessNumber')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.duns"
                    :label="$t('dunsNumber')"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Spracheinstellungen -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('languageSettings') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.language_id"
                    :label="$t('defaultLanguageCustomersVendors')"
                    :items="[]"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Währung -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('currency') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.currency_id"
                    :label="$t('defaultCurrency')"
                    :items="currenciesList"
                    item-title="name"
                    item-value="id"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Gewichtseinheit -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('weight') }}</h3>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.weightunit"
                    :label="$t('weightUnit')"
                    :items="weightUnits"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.show_weight"
                    :label="$t('showWeight')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Druckvorlagen -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('printTemplates') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-radio-group v-model="useTemplates">
                    <v-radio
                        value="existing"
                        :label="$t('useExistingPrintTemplates')"
                    />
                    <v-select
                        v-if="useTemplates === 'existing'"
                        v-model="defaults.templates"
                        :items="availableTemplateSets"
                        item-title="label"
                        item-value="name"
                        variant="outlined"
                        density="compact"
                        class="mt-2"
                        :no-data-text="$t('noTemplateSetsFound')"
                    />

                    <v-radio
                        value="new"
                        :label="$t('createNewFromMaster')"
                        class="mt-4"
                    />
                    <v-row v-if="useTemplates === 'new'" class="mt-2" align="center">
                        <v-col cols="12" md="4">
                            <v-select
                                v-model="newMasterTemplate"
                                :items="masterTemplateSets"
                                item-title="label"
                                item-value="name"
                                :label="$t('masterTemplate')"
                                variant="outlined"
                                density="compact"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-text-field
                                v-model="newTemplateSetName"
                                :placeholder="$t('newName')"
                                variant="outlined"
                                density="compact"
                            />
                        </v-col>
                        <v-col cols="12" md="4">
                            <v-btn
                                color="primary"
                                variant="elevated"
                                :loading="creatingTemplateSet"
                                :disabled="!newMasterTemplate || !newTemplateSetName"
                                @click="onCreateTemplateSet"
                            >
                                {{ $t('createCopy') }}
                            </v-btn>
                        </v-col>
                    </v-row>
                </v-radio-group>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.print_interpolate_variables_in_positions"
                    :label="$t('interpolateVariablesInPositions')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('interpolateVariablesInPositionsHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <!-- Drucker -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('printers') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-data-table
                    :headers="printerHeaders"
                    :items="printers"
                    :no-data-text="$t('noPrintersConfigured')"
                    density="compact"
                    hide-default-footer
                    :items-per-page="-1"
                >
                    <!-- Beschreibung -->
                    <template #item.printer_description="{ item }">
                        <v-text-field
                            v-model="item.printer_description"
                            variant="plain"
                            density="compact"
                            hide-details
                            @blur="onPrinterBlur(item)"
                        />
                    </template>

                    <!-- Druckbefehl -->
                    <template #item.printer_command="{ item }">
                        <v-text-field
                            v-model="item.printer_command"
                            variant="plain"
                            density="compact"
                            hide-details
                            @blur="onPrinterBlur(item)"
                        />
                    </template>

                    <!-- Template-Code -->
                    <template #item.template_code="{ item }">
                        <v-text-field
                            v-model="item.template_code"
                            variant="plain"
                            density="compact"
                            hide-details
                            @blur="onPrinterBlur(item)"
                        />
                    </template>

                    <!-- In Faktura ausblenden -->
                    <template #item.hide_factura="{ item }">
                        <v-checkbox
                            v-model="item.hide_factura"
                            hide-details
                            density="compact"
                            @update:model-value="onPrinterBlur(item)"
                        />
                    </template>

                    <!-- Aktionen -->
                    <template #item.actions="{ item }">
                        <v-btn
                            icon="mdi-delete"
                            variant="text"
                            size="small"
                            color="error"
                            @click="confirmDeletePrinter(item)"
                        />
                    </template>
                </v-data-table>

                <v-btn
                    color="primary"
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-plus"
                    class="mt-2"
                    @click="addPrinter"
                >
                    {{ $t('addPrinter') }}
                </v-btn>

                <!-- Lösch-Bestätigungsdialog -->
                <v-dialog v-model="showDeletePrinterDialog" max-width="400" persistent>
                    <v-card>
                        <v-card-title class="bg-error text-white">
                            <v-icon start>mdi-delete-alert</v-icon>
                            {{ $t('printers') }}
                        </v-card-title>
                        <v-card-text class="pa-4">
                            {{ $t('deletePrinterConfirm') }}
                        </v-card-text>
                        <v-card-actions>
                            <v-btn @click="showDeletePrinterDialog = false">{{ $t('cancel') }}</v-btn>
                            <v-spacer />
                            <v-btn color="error" variant="flat" @click="deletePrinter">
                                <v-icon start>mdi-delete</v-icon>
                                {{ $t('delete') }}
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { fakturaStore } from '@/core/stores/faktura.store.js';
import { getWeightUnits } from '../composables/useUnits.js';
import * as toasts from '@/core/utils/toasts.js';

const { t } = useI18n();
const store = oserpStore();
const faktura = fakturaStore();

// Props: Erhält das defaults-Objekt vom Parent
defineProps({
    defaults: {
        type: Object,
        required: true
    }
});

// Lokaler State für Template-Auswahl
const useTemplates = ref('existing');
const newMasterTemplate = ref('');
const newTemplateSetName = ref('');
const creatingTemplateSet = ref(false);

// === Drucker ===
const printers = ref([]);
const showDeletePrinterDialog = ref(false);
let printerToDelete = null;

const printerHeaders = computed(() => [
    { title: t('printerDescription'), key: 'printer_description', sortable: false },
    { title: t('printerCommand'), key: 'printer_command', sortable: false },
    { title: t('templateCode'), key: 'template_code', sortable: false },
    { title: t('hideFaktura'), key: 'hide_factura', sortable: false, width: '120px' },
    { title: '', key: 'actions', sortable: false, width: '50px' }
]);

// Dynamisch geladene Template-Sets
const availableTemplateSets = ref([]);
const masterTemplateSets = ref([]);

// Template-Sets beim Mounten laden
onMounted(async () => {
    await loadTemplateSets();
    await loadPrinters();
});

async function loadTemplateSets() {
    try {
        const data = await faktura.getTemplateList();
        availableTemplateSets.value = data.templateSets || [];
        masterTemplateSets.value = data.masterSets || [];
        if (masterTemplateSets.value.length > 0 && !newMasterTemplate.value) {
            newMasterTemplate.value = masterTemplateSets.value[0].name;
        }
    } catch (e) {
        console.error('Fehler beim Laden der Template-Sets:', e);
    }
}

async function onCreateTemplateSet() {
    if (!newMasterTemplate.value || !newTemplateSetName.value) return;
    creatingTemplateSet.value = true;
    try {
        await faktura.createTemplateSet(newMasterTemplate.value, newTemplateSetName.value);
        // Liste neu laden
        await loadTemplateSets();
        // Auf "Vorhandene" wechseln
        useTemplates.value = 'existing';
        newTemplateSetName.value = '';
    } catch (e) {
        console.error('Fehler beim Erstellen des Template-Sets:', e);
    } finally {
        creatingTemplateSet.value = false;
    }
}

/**
 * Computed: Währungsliste aus dem oserpStore
 */
const currenciesList = computed(() => {
    return store.session?.company_config?.currencies || [];
});

/**
 * Computed: Gewichtseinheiten aus dem oserpStore
 */
const weightUnits = computed(() => {
    if (store.session?.company_config?.units) {
        const units = getWeightUnits(store.session.company_config.units);
        return units.map(unit => ({
            title: unit.name,
            value: unit.name
        }));
    }

    return [
        { title: 't', value: 't' },
        { title: 'kg', value: 'kg' },
        { title: 'g', value: 'g' },
        { title: 'mg', value: 'mg' }
    ];
});

// Ja/Nein Optionen für alle Boolean-Dropdowns
const yesNoOptions = [
    { title: t('yes'), value: true },
    { title: t('no'), value: false }
];

// === Firmenlogo ===
const companyLogo = computed(() => store.getClientDefaultValue('company_logo', null));
const logoDialogOpen = ref(false);
const logoPreview = ref(null);
const logoCropCanvas = ref(null);
const cropWidth = 450;
const cropHeight = 90;
const cropScale = ref(1);
const cropScaleMin = ref(1);
let cropImg = null;
let cropOffset = { x: 0, y: 0 };
let cropDragging = false;
let cropDragStart = { x: 0, y: 0 };
let cropOffsetStart = { x: 0, y: 0 };

function onLogoFileSelected(files) {
    const file = Array.isArray(files) ? files[0] : files;
    if (!file) { logoPreview.value = null; return; }
    const reader = new FileReader();
    reader.onload = (e) => {
        logoPreview.value = e.target.result;
        cropImg = new Image();
        cropImg.onload = () => {
            const scaleX = cropWidth / cropImg.width;
            const scaleY = cropHeight / cropImg.height;
            cropScaleMin.value = Math.round(Math.max(scaleX, scaleY) * 100) / 100;
            cropScale.value = cropScaleMin.value;
            const w = cropImg.width * cropScale.value;
            const h = cropImg.height * cropScale.value;
            cropOffset = { x: (cropWidth - w) / 2, y: (cropHeight - h) / 2 };
            nextTick(drawCrop);
        };
        cropImg.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function drawCrop() {
    const canvas = logoCropCanvas.value;
    if (!canvas || !cropImg) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, cropWidth, cropHeight);
    const s = cropScale.value;
    const w = cropImg.width * s;
    const h = cropImg.height * s;
    cropOffset.x = Math.min(0, Math.max(cropWidth - w, cropOffset.x));
    cropOffset.y = Math.min(0, Math.max(cropHeight - h, cropOffset.y));
    ctx.drawImage(cropImg, cropOffset.x, cropOffset.y, w, h);
}

function cropStart(e) {
    cropDragging = true;
    const r = e.target.getBoundingClientRect();
    cropDragStart = { x: e.clientX - r.left, y: e.clientY - r.top };
    cropOffsetStart = { ...cropOffset };
}
function cropMove(e) {
    if (!cropDragging) return;
    const r = e.target.getBoundingClientRect();
    const mx = (e.clientX - r.left) - cropDragStart.x;
    const my = (e.clientY - r.top) - cropDragStart.y;
    cropOffset.x = cropOffsetStart.x + mx;
    cropOffset.y = cropOffsetStart.y + my;
    drawCrop();
}
function cropEnd() { cropDragging = false; }

function saveLogo() {
    const canvas = logoCropCanvas.value;
    if (!canvas) return;
    const dataUrl = canvas.toDataURL('image/png');
    if (!store.session.company_config) store.session.company_config = {};
    if (!store.session.company_config.defaults_oserp) store.session.company_config.defaults_oserp = {};
    store.session.company_config.defaults_oserp['company_logo'] = dataUrl;
    axios.post('/api/oserp_config/', { action: 'saveClientDefault', key: 'company_logo', value: dataUrl });
    logoDialogOpen.value = false;
    logoPreview.value = null;
}

function deleteLogo() {
    if (store.session.company_config?.defaults_oserp) {
        delete store.session.company_config.defaults_oserp['company_logo'];
    }
    axios.post('/api/oserp_config/', { action: 'saveClientDefault', key: 'company_logo', value: '' });
}

// === Drucker-Verwaltung ===

const printerApi = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: { 'Content-Type': 'application/json' }
});

function syncPrintersToStore() {
    if (store.session.company_config) {
        store.session.company_config.printers = printers.value
            .filter(p => p.id)
            .map(({ _isNew, ...rest }) => rest);
    }
}

async function loadPrinters() {
    try {
        const response = await printerApi.post('/oserp_config/', { action: 'getPrinters' });
        if (response.data.success) {
            printers.value = response.data.payload?.results || [];
        }
    } catch (error) {
        console.error('loadPrinters Fehler:', error);
    }
}

function addPrinter() {
    printers.value.push({
        id: null,
        printer_description: '',
        printer_command: '',
        template_code: '',
        hide_factura: false,
        _isNew: true
    });
}

async function onPrinterBlur(item) {
    // Nicht speichern wenn Beschreibung leer
    if (!item.printer_description?.trim()) return;

    try {
        const response = await printerApi.post('/oserp_config/', {
            action: 'savePrinter',
            id: item.id,
            printer_description: item.printer_description,
            printer_command: item.printer_command || '',
            template_code: item.template_code || '',
            hide_factura: !!item.hide_factura
        });

        if (response.data.success) {
            // Bei neuem Drucker: ID zuweisen
            if (!item.id && response.data.payload?.results?.id) {
                item.id = response.data.payload.results.id;
                item._isNew = false;
            }
            syncPrintersToStore();
            toasts.success(t('printerSaved'));
        }
    } catch (error) {
        console.error('savePrinter Fehler:', error);
    }
}

function confirmDeletePrinter(item) {
    printerToDelete = item;
    showDeletePrinterDialog.value = true;
}

async function deletePrinter() {
    if (!printerToDelete) return;

    // Neuer, noch nicht gespeicherter Drucker: einfach aus Liste entfernen
    if (!printerToDelete.id) {
        printers.value = printers.value.filter(p => p !== printerToDelete);
        showDeletePrinterDialog.value = false;
        printerToDelete = null;
        return;
    }

    try {
        const response = await printerApi.post('/oserp_config/', {
            action: 'deletePrinter',
            id: printerToDelete.id
        });

        if (response.data.success) {
            printers.value = printers.value.filter(p => p.id !== printerToDelete.id);
            syncPrintersToStore();
            toasts.success(t('printerDeleted'));
        }
    } catch (error) {
        console.error('deletePrinter Fehler:', error);
    } finally {
        showDeletePrinterDialog.value = false;
        printerToDelete = null;
    }
}
</script>

<style scoped>
.company-logo-preview {
    height: 90px;
    width: 450px;
    max-width: 100%;
    object-fit: contain;
}
.company-logo-placeholder {
    height: 90px;
    width: 450px;
    max-width: 100%;
    background: #f5f5f5;
}
</style>
