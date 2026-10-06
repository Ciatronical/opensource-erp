<!-- src/core/views/template-designer/template-designer.view.vue -->
<!--
    Vorlageneditor: Druckvorlagen (Rechnung, Angebot, Auftrag, Lieferschein …)
    per Drag & Drop gestalten — nur für Systemadministratoren.

    Links die Palette (Bausteine, Abschnitte, Felder mit Beispielwerten,
    Bilder), in der Mitte die Seite im Maßstab, rechts die Eigenschaften der
    Auswahl. Das Design ist JSON und wird im Backend versioniert gespeichert;
    beim Speichern entsteht daraus die .tex-Datei im Vorlagensatz, die der
    Druck wie jede andere Vorlage verwendet. Die PDF-Vorschau rendert das
    aktuelle (auch ungespeicherte) Design mit einem echten Beleg.

    Firmendaten (Adresse, Steuernummer, Bank …) kommen aus dem Store und
    werden als Vorschläge angeboten; Felder und Beispielwerte liefert das
    Backend aus derselben Druckdatenstrecke, die auch das PDF füllt.
-->
<template>
    <div class="tpl-designer">
        <navbar-view :title="t('TemplateDesigner.title')" :show-back-button="true" />

        <!-- Werkzeugleiste -->
        <div class="tpl-toolbar">
            <v-select
                v-model="templateSet"
                :items="setItems"
                item-title="label"
                item-value="name"
                density="compact"
                variant="outlined"
                hide-details
                class="tpl-set-select"
                :prepend-inner-icon="data?.writable ? 'mdi-folder-edit-outline' : 'mdi-lock-outline'"
                :label="t('TemplateDesigner.toolbar.templateSet')"
                @update:model-value="switchSet"
            />
            <v-btn v-if="data && !data.writable" size="small" variant="tonal" color="warning" prepend-icon="mdi-content-copy" :to="{ name: 'client-defaults' }">
                {{ t('TemplateDesigner.toolbar.createCopy') }}
            </v-btn>

            <div class="tpl-doctypes">
                <v-chip
                    v-for="dt in docTypes"
                    :key="dt.key"
                    size="small"
                    :variant="dt.key === docType ? 'flat' : 'tonal'"
                    :color="dt.key === docType ? 'primary' : undefined"
                    class="tpl-doctype-chip"
                    @click="switchDocType(dt.key)"
                >
                    <v-icon start size="x-small">{{ docTypeIcon(dt) }}</v-icon>
                    {{ docLabel(t, dt.key) }}
                    <span v-if="isDirty(dt.key)" class="tpl-dirty-dot" :title="t('TemplateDesigner.toolbar.unsaved')" />
                </v-chip>
            </div>

            <v-spacer />

            <v-btn-toggle v-model="mode" mandatory density="compact" variant="outlined" color="primary" class="tpl-pageview" @update:model-value="onModeChange">
                <v-btn value="design" size="small" icon="mdi-drawing-box" :title="t('TemplateDesigner.toolbar.modeDesign')" />
                <v-btn value="source" size="small" icon="mdi-code-tags" :title="t('TemplateDesigner.toolbar.modeSource')" />
            </v-btn-toggle>

            <template v-if="mode === 'design'">
            <v-btn-group density="compact" variant="outlined" divided>
                <v-btn icon="mdi-undo" size="small" :disabled="!history.canUndo.value" :title="t('TemplateDesigner.toolbar.undo')" @click="undo" />
                <v-btn icon="mdi-redo" size="small" :disabled="!history.canRedo.value" :title="t('TemplateDesigner.toolbar.redo')" @click="redo" />
            </v-btn-group>
            <v-btn-group density="compact" variant="outlined" divided>
                <v-btn icon="mdi-magnify-minus-outline" size="small" @click="zoom = Math.max(1.5, zoom - 0.5)" />
                <v-btn size="small" class="tpl-zoom-label" @click="fitZoom">{{ Math.round(zoom / 3.78 * 100) }} %</v-btn>
                <v-btn icon="mdi-magnify-plus-outline" size="small" @click="zoom = Math.min(8, zoom + 0.5)" />
            </v-btn-group>
            <v-btn-toggle v-model="pageView" mandatory density="compact" variant="outlined" color="primary" class="tpl-pageview">
                <v-btn value="first" size="small" :title="t('TemplateDesigner.toolbar.firstPage')">1</v-btn>
                <v-btn value="following" size="small" :title="t('TemplateDesigner.toolbar.followingPages')">2+</v-btn>
            </v-btn-toggle>
            <v-btn :icon="showGrid ? 'mdi-grid' : 'mdi-grid-off'" size="small" variant="text" :color="showGrid ? 'primary' : undefined" :title="t('TemplateDesigner.toolbar.grid')" @click="showGrid = !showGrid" />
            <v-btn icon="mdi-ruler-square" size="small" variant="text" :color="showDin ? 'primary' : undefined" :title="t('TemplateDesigner.toolbar.din')" @click="showDin = !showDin" />
            <v-btn
                size="small"
                :variant="preview.open ? 'flat' : 'tonal'"
                :color="preview.open ? 'primary' : undefined"
                prepend-icon="mdi-file-pdf-box"
                :loading="preview.loading"
                @click="togglePreview"
            >
                {{ t('TemplateDesigner.toolbar.preview') }}
            </v-btn>
            <v-btn size="small" color="primary" prepend-icon="mdi-content-save" :loading="saving" :disabled="!data?.writable || !isDirty(docType)" @click="save(false)">
                {{ t('TemplateDesigner.toolbar.save') }}
            </v-btn>
            <v-menu>
                <template #activator="{ props: m }">
                    <v-btn v-bind="m" icon="mdi-dots-vertical" size="small" variant="text" />
                </template>
                <v-list density="compact">
                    <v-list-item prepend-icon="mdi-content-save-all" :disabled="!data?.writable || !dirtyTypes.length" @click="save(true)">
                        <v-list-item-title>{{ t('TemplateDesigner.menu.saveAll', { count: dirtyTypes.length }) }}</v-list-item-title>
                    </v-list-item>
                    <v-list-item prepend-icon="mdi-content-duplicate" :disabled="!design" @click="applyDialog = true">
                        <v-list-item-title>{{ t('TemplateDesigner.menu.applyLayout') }}</v-list-item-title>
                    </v-list-item>
                    <v-list-item prepend-icon="mdi-history" :disabled="!(data?.versions?.[docType]?.length)" @click="versionsDialog = true">
                        <v-list-item-title>{{ t('TemplateDesigner.menu.versions') }}</v-list-item-title>
                    </v-list-item>
                    <v-list-item prepend-icon="mdi-file-replace-outline" @click="startDialog = true">
                        <v-list-item-title>{{ t('TemplateDesigner.menu.restart') }}</v-list-item-title>
                    </v-list-item>
                    <v-divider />
                    <v-list-item prepend-icon="mdi-refresh" @click="load()">
                        <v-list-item-title>{{ t('TemplateDesigner.menu.reload') }}</v-list-item-title>
                    </v-list-item>
                    <v-list-item prepend-icon="mdi-delete-restore" :disabled="!data?.writable || !currentType?.designed" class="text-error" @click="removeDesign">
                        <v-list-item-title>{{ t('TemplateDesigner.menu.remove') }}</v-list-item-title>
                    </v-list-item>
                </v-list>
            </v-menu>
            </template>
        </div>

        <!-- Hinweise -->
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="ma-2">{{ error }}</v-alert>
        <v-alert v-else-if="data && !data.writable" type="warning" variant="tonal" density="compact" class="ma-2">
            {{ t('TemplateDesigner.alerts.readOnly') }}
        </v-alert>
        <v-alert v-else-if="currentType?.kfzOverride" type="info" variant="tonal" density="compact" class="ma-2">
            {{ t('TemplateDesigner.alerts.kfzOverride') }}
        </v-alert>

        <v-skeleton-loader v-if="loading && !data" type="article, image" class="ma-4" />

        <!-- Quelltext-Modus: vorhandene Vorlagendateien bearbeiten -->
        <designer-source
            v-else-if="data && mode === 'source'"
            ref="sourceRef"
            :template-set="templateSet"
            :writable="data.writable"
            :doc-types="docTypes"
            :doc-type="docType"
            :suggestions="suggestions"
            :document-id="preview.documentId"
            :initial-path="sourcePath"
            @dirty="sourceDirty = $event"
        />

        <!-- Arbeitsfläche -->
        <div v-else-if="data" class="tpl-workspace">
            <aside class="tpl-side tpl-side--left">
                <designer-palette
                    :items="palette"
                    :sections="sectionTemplatesList"
                    :field-groups="fieldGroups"
                    :sample="data.sample"
                    :images="data.images"
                    :image-urls="imageUrls"
                    :writable="data.writable"
                    :uploading="uploading"
                    :has-company-logo="!!suggestions.logoDataUrl"
                    @add-block="addBlock"
                    @add-section="addSection"
                    @insert-field="insertField"
                    @add-image="addImage"
                    @upload="uploadImage"
                    @use-company-logo="useCompanyLogo"
                />
            </aside>

            <main class="tpl-center" :class="{ 'tpl-center--split': preview.open }">
                <div class="tpl-canvas-scroll" tabindex="0">
                    <designer-canvas
                        v-if="design"
                        :design="design"
                        :sample="data.sample"
                        :scale="zoom"
                        :selected-id="selectedId"
                        :page-view="pageView"
                        :show-grid="showGrid"
                        :show-din="showDin"
                        :image-urls="imageUrls"
                        @select="selectedId = $event"
                        @commit="commit"
                        @edit="selectedId = $event"
                        @drop="onDrop"
                        @reorder="reorderSections"
                        @detach="sectionToBlock($event.id, $event.x, $event.y)"
                    />
                    <div v-else class="tpl-no-design">
                        <v-icon size="48" color="primary">mdi-file-document-plus-outline</v-icon>
                        <div class="text-h6 mt-2">{{ t('TemplateDesigner.start.noDesign', { type: docLabel(t, docType) }) }}</div>
                        <div class="text-body-2 text-medium-emphasis mb-4">
                            {{ currentType?.handmade ? t('TemplateDesigner.start.handmadeHint', { file: currentType.file }) : t('TemplateDesigner.start.hint') }}
                        </div>
                        <div class="d-flex flex-wrap ga-2 justify-center">
                            <v-btn color="primary" prepend-icon="mdi-auto-fix" @click="startDialog = true">{{ t('TemplateDesigner.start.choose') }}</v-btn>
                            <v-btn v-if="currentType?.exists" variant="tonal" prepend-icon="mdi-code-tags" @click="openSource(currentType.file)">{{ t('TemplateDesigner.start.editSource') }}</v-btn>
                        </div>
                    </div>
                </div>

                <!-- PDF-Vorschau -->
                <div v-if="preview.open" class="tpl-preview">
                    <div class="tpl-preview-bar">
                        <v-select
                            v-model="preview.documentId"
                            :items="sampleDocItems"
                            density="compact"
                            variant="outlined"
                            hide-details
                            class="tpl-preview-doc"
                            :label="t('TemplateDesigner.preview.document')"
                            @update:model-value="changeSampleDocument"
                        />
                        <v-switch v-model="preview.auto" :label="t('TemplateDesigner.preview.auto')" color="primary" density="compact" hide-details class="ms-2" />
                        <v-spacer />
                        <v-btn
                            :icon="preview.mode === 'images' ? 'mdi-file-pdf-box' : 'mdi-image-multiple-outline'"
                            size="small"
                            variant="text"
                            :title="preview.mode === 'images' ? t('TemplateDesigner.preview.asPdf') : t('TemplateDesigner.preview.asImages')"
                            @click="togglePreviewMode"
                        />
                        <v-btn icon="mdi-refresh" size="small" variant="text" :loading="preview.loading" @click="renderPreview" />
                        <v-btn icon="mdi-open-in-new" size="small" variant="text" :disabled="!preview.url && !preview.pages.length" @click="openPreviewWindow" />
                        <v-btn icon="mdi-close" size="small" variant="text" @click="preview.open = false" />
                    </div>
                    <v-alert v-if="preview.error" type="error" variant="tonal" density="compact" class="ma-2 tpl-preview-error">
                        <div class="font-weight-medium">{{ preview.error }}</div>
                        <details v-if="preview.debug" class="mt-1">
                            <summary class="text-caption">{{ t('TemplateDesigner.preview.details') }}</summary>
                            <pre class="tpl-preview-log">{{ preview.debug }}</pre>
                        </details>
                    </v-alert>
                    <iframe v-if="preview.url" :src="preview.url" class="tpl-preview-frame" :title="t('TemplateDesigner.toolbar.preview')" />
                    <div v-else-if="preview.pages.length" class="tpl-preview-pages">
                        <img v-for="(src, i) in preview.pages" :key="i" :src="src" class="tpl-preview-page" :alt="`${i + 1}`">
                    </div>
                    <div v-else-if="!preview.error" class="tpl-preview-empty">
                        <v-progress-circular v-if="preview.loading" indeterminate color="primary" />
                        <span v-else>{{ t('TemplateDesigner.preview.empty') }}</span>
                    </div>
                </div>
            </main>

            <aside class="tpl-side tpl-side--right">
                <designer-inspector
                    v-if="design"
                    :design="design"
                    :selected="selectedId"
                    :field-groups="fieldGroups"
                    :suggestions="suggestionList"
                    :images="data.images"
                    :sample="data.sample"
                    @commit="commit"
                    @delete="deleteSelected"
                    @duplicate="duplicateSelected"
                    @move-section="moveSection"
                    @reorder="reorderSections"
                    @select="selectedId = $event"
                    @to-block="sectionToBlock()"
                    @to-section="blockToSection"
                />
            </aside>
        </div>

        <!-- Startvorlage wählen -->
        <v-dialog v-model="startDialog" max-width="760">
            <v-card>
                <v-card-title>{{ t('TemplateDesigner.start.title', { type: docLabel(t, docType) }) }}</v-card-title>
                <v-card-text>
                    <div class="tpl-preset-grid">
                        <v-card v-for="p in presetList" :key="p.key" variant="outlined" class="tpl-preset" hover @click="startWithPreset(p)">
                            <v-icon size="32" color="primary" class="mb-2">{{ p.icon }}</v-icon>
                            <div class="font-weight-medium">{{ p.title }}</div>
                            <div class="text-caption text-medium-emphasis">{{ p.description }}</div>
                        </v-card>
                    </div>
                    <template v-if="designedOtherTypes.length">
                        <v-divider class="my-4" />
                        <div class="text-body-2 mb-2">{{ t('TemplateDesigner.start.copyFrom') }}</div>
                        <div class="d-flex flex-wrap ga-2">
                            <v-chip v-for="dt in designedOtherTypes" :key="dt" variant="tonal" color="primary" prepend-icon="mdi-content-copy" @click="startFromType(dt)">
                                {{ docLabel(t, dt) }}
                            </v-chip>
                        </div>
                    </template>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="startDialog = false">{{ t('TemplateDesigner.common.cancel') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Grundlayout übertragen -->
        <v-dialog v-model="applyDialog" max-width="520">
            <v-card>
                <v-card-title>{{ t('TemplateDesigner.apply.title') }}</v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-3">{{ t('TemplateDesigner.apply.hint', { type: docLabel(t, docType) }) }}</div>
                    <v-checkbox v-for="dt in docTypes.filter(d => d.key !== docType)" :key="dt.key" v-model="applyTargets" :value="dt.key" :label="docLabel(t, dt.key) + (dt.designed ? '' : ' · ' + t('TemplateDesigner.apply.willCreate'))" density="compact" hide-details />
                </v-card-text>
                <v-card-actions>
                    <v-btn variant="text" @click="applyTargets = docTypes.filter(d => d.key !== docType).map(d => d.key)">{{ t('TemplateDesigner.apply.all') }}</v-btn>
                    <v-spacer />
                    <v-btn variant="text" @click="applyDialog = false">{{ t('TemplateDesigner.common.cancel') }}</v-btn>
                    <v-btn color="primary" :disabled="!applyTargets.length" @click="applyLayout">{{ t('TemplateDesigner.apply.apply') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Versionen -->
        <v-dialog v-model="versionsDialog" max-width="520">
            <v-card>
                <v-card-title>{{ t('TemplateDesigner.versions.title', { type: docLabel(t, docType) }) }}</v-card-title>
                <v-list density="compact">
                    <v-list-item v-for="v in (data?.versions?.[docType] || [])" :key="v.id" :title="t('TemplateDesigner.versions.version', { version: v.version })" :subtitle="formatVersion(v)">
                        <template #append>
                            <v-chip v-if="v.is_current" size="x-small" color="primary" variant="tonal">{{ t('TemplateDesigner.versions.current') }}</v-chip>
                            <v-btn v-else size="small" variant="text" color="primary" @click="loadVersion(v.id)">{{ t('TemplateDesigner.versions.load') }}</v-btn>
                        </template>
                    </v-list-item>
                </v-list>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="versionsDialog = false">{{ t('TemplateDesigner.common.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Speichern bestätigen -->
        <v-dialog v-model="saveDialog.open" max-width="560">
            <v-card>
                <v-card-title>{{ t('TemplateDesigner.saveDialog.title') }}</v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-2">{{ t('TemplateDesigner.saveDialog.files') }}</div>
                    <ul class="mb-3">
                        <li v-for="dt in saveDialog.types" :key="dt.key"><code>{{ dt.file }}</code> — {{ docLabel(t, dt.key) }}</li>
                    </ul>
                    <v-alert v-if="saveDialog.handmade.length" type="warning" variant="tonal" density="compact" class="mb-3">
                        {{ t('TemplateDesigner.saveDialog.handmade', { files: saveDialog.handmade.join(', ') }) }}
                    </v-alert>
                    <v-checkbox v-if="saveDialog.kfz" v-model="saveDialog.alsoKfz" :label="t('TemplateDesigner.saveDialog.alsoKfz')" density="compact" hide-details />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="saveDialog.open = false">{{ t('TemplateDesigner.common.cancel') }}</v-btn>
                    <v-btn color="primary" :loading="saving" @click="doSave">{{ t('TemplateDesigner.saveDialog.confirm') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { onBeforeRouteLeave } from 'vue-router';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { fakturaStore } from '@/core/stores/faktura.store.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import { formatDate } from '@/core/utils/dateFormatter.js';
import DesignerCanvas from './components/designer-canvas.vue';
import DesignerPalette from './components/designer-palette.vue';
import DesignerInspector from './components/designer-inspector.vue';
import DesignerSource from './components/designer-source.vue';
import { useDesignerHistory } from './composables/useDesignerHistory.js';
import {
    paletteItems, sectionTemplates, presets, companySuggestions, suggestionChips, fieldCatalog,
    docLabel, clone, uid, BLOCK_TYPES, numberField, dateField, infoRows,
} from './designer.blocks.js';

const { t, locale } = useI18n();
const store = oserpStore();
const faktura = fakturaStore();
const history = useDesignerHistory();

// ───────────────────────── Zustand ─────────────────────────

const loading = ref(false);
const saving = ref(false);
const uploading = ref(false);
const error = ref('');
const data = ref(null);                 // Antwort von getTemplateDesigner
const templateSet = ref('');
const docType = ref('invoice');
const designs = reactive({});           // Belegart -> Design (bearbeitet)
const originals = reactive({});         // Belegart -> JSON des gespeicherten Stands
const selectedId = ref(null);
const pageView = ref('first');
// 'design' = Bausteine auf der Seite, 'source' = Quelltext der Vorlagendateien
const mode = ref('design');
const sourceRef = ref(null);
const sourcePath = ref('');
const sourceDirty = ref(false);
const zoom = ref(3);
const showGrid = ref(true);
const showDin = ref(false);
const imageUrls = reactive({});
const startDialog = ref(false);
const applyDialog = ref(false);
const applyTargets = ref([]);
const versionsDialog = ref(false);
const saveDialog = reactive({ open: false, types: [], handmade: [], kfz: false, alsoKfz: false, all: false });
// Ohne eingebaute PDF-Anzeige (Tablets, manche Browser) kommen gerasterte Seiten; umschaltbar
const preview = reactive({
    open: false, url: '', pages: [], loading: false, error: '', debug: '', auto: true, documentId: null,
    mode: (typeof navigator !== 'undefined' && navigator.pdfViewerEnabled === false) ? 'images' : 'pdf',
});

const design = computed(() => designs[docType.value] || null);
const docTypes = computed(() => data.value?.documentTypes || []);
const currentType = computed(() => docTypes.value.find(d => d.key === docType.value) || null);
const dirtyTypes = computed(() => docTypes.value.map(d => d.key).filter(isDirty));
const designedOtherTypes = computed(() => docTypes.value.filter(d => d.key !== docType.value && designs[d.key]).map(d => d.key));

const setItems = computed(() => {
    if (!data.value) return [];
    const sets = data.value.templateSets || {};
    return [
        ...(sets.templateSets || []).map(s => ({ name: s.name, label: s.label + (s.name === sets.activeSet ? ` · ${t('TemplateDesigner.toolbar.active')}` : '') })),
        ...(sets.masterSets || []).map(s => ({ name: s.name, label: `${s.label} · ${t('TemplateDesigner.toolbar.master')}` })),
    ];
});

const suggestions = computed(() => companySuggestions(store.session?.company_config));
const suggestionList = computed(() => suggestionChips(t, suggestions.value));
const palette = computed(() => paletteItems(t, suggestions.value));
const sectionTemplatesList = computed(() => sectionTemplates(t, docType.value));
const presetList = computed(() => presets(t, suggestions.value));
const fieldGroups = computed(() => fieldCatalog(t, data.value?.sample));
const sampleDocItems = computed(() => (data.value?.sample_documents?.[docType.value] || []).map(d => ({
    title: `${d.number || d.id} · ${d.name || ''} · ${formatDate(d.date)}`, value: d.id,
})));

function isDirty(type) {
    const d = designs[type];
    if (!d) return false;
    return JSON.stringify(d) !== (originals[type] || '');
}

function docTypeIcon(dt) {
    if (designs[dt.key]) return 'mdi-check-circle-outline';
    if (dt.handmade) return 'mdi-pencil-outline';
    return 'mdi-plus-circle-outline';
}

// ───────────────────────── Laden ─────────────────────────

/**
 * Lädt Set, Designs und Beispieldaten. Lokale, ungespeicherte Designs bleiben
 * erhalten (keepLocal), damit ein Wechsel der Belegart nichts verwirft.
 */
async function load(params = {}, keepLocal = true) {
    loading.value = true;
    error.value = '';
    try {
        const payload = await faktura.designerLoad({
            templateSet: params.templateSet ?? templateSet.value,
            documentType: params.documentType ?? docType.value,
            sampleDocumentId: params.sampleDocumentId ?? null,
        });
        const setChanged = payload.templateSet !== templateSet.value;
        data.value = payload;
        templateSet.value = payload.templateSet;
        if (setChanged || !keepLocal) {
            for (const k of Object.keys(designs)) delete designs[k];
            for (const k of Object.keys(originals)) delete originals[k];
        }
        for (const [type, entry] of Object.entries(payload.designs || {})) {
            if (!designs[type] || !isDirty(type) || setChanged || !keepLocal) {
                designs[type] = normalizeDesign(entry.design);
            }
            originals[type] = JSON.stringify(normalizeDesign(entry.design));
        }
        preview.documentId = payload.sample?.id || null;
        await loadImages(payload.images || []);
        if (!designs[docType.value]) startDialog.value = true;
    } catch (e) {
        error.value = e?.message || String(e);
    } finally {
        loading.value = false;
    }
}

/** Fehlende Felder älterer Designs ergänzen */
function normalizeDesign(d) {
    const design = clone(d || {});
    design.v = design.v || 1;
    design.page = { marginLeft: 20, marginRight: 20, bodyTop: 100, bodyTopFollowing: 35, bodyBottom: 40, fontSize: 10, font: 'sans',
        textColor: '#222222', accentColor: '#1F4E79', foldMarks: 'B', punchMark: true, background: '', backgroundPages: 'all', currency: 'euro', ...(design.page || {}) };
    design.blocks = (design.blocks || []).map(b => ({ ...b, id: b.id || uid(), props: b.props || {} }));
    design.body = design.body || {};
    design.body.sections = (design.body.sections || []).map(s => {
        const section = { ...s, id: s.id || uid('s') };
        if (section.type === 'table') {
            section.columns = (section.columns || []).map(c => ({ ...c, visible: c.visible !== false }));
        }
        return section;
    });
    return design;
}

async function loadImages(images) {
    for (const img of images) {
        if (imageUrls[img.path]) continue;
        try {
            const url = await faktura.designerImageUrl(templateSet.value, img.path);
            if (url) imageUrls[img.path] = url;
        } catch { /* Bild bleibt ohne Vorschau */ }
    }
}

function releaseImages() {
    for (const k of Object.keys(imageUrls)) {
        URL.revokeObjectURL(imageUrls[k]);
        delete imageUrls[k];
    }
}

async function switchSet(name) {
    if (dirtyTypes.value.length || sourceDirty.value) {
        const r = await alerts.warning(t('TemplateDesigner.alerts.discardChanges'), t('TemplateDesigner.alerts.unsavedTitle'), t('TemplateDesigner.common.discard'), t('TemplateDesigner.common.cancel'));
        if (!r.isConfirmed) { templateSet.value = data.value.templateSet; return; }
    }
    releaseImages();
    selectedId.value = null;
    await load({ templateSet: name }, false);
    history.reset(design.value);
}

async function switchDocType(type) {
    if (type === docType.value) return;
    docType.value = type;
    selectedId.value = null;
    pageView.value = 'first';
    preview.url = '';
    preview.pages = [];
    preview.error = '';
    await load({ documentType: type });
    history.reset(design.value);
    if (preview.open && design.value) renderPreview();
}

async function changeSampleDocument(id) {
    await load({ sampleDocumentId: id });
    if (preview.open) renderPreview();
}

// ───────────────────────── Verlauf ─────────────────────────

function commit() {
    if (design.value) history.commit(design.value);
    schedulePreview();
}

function undo() {
    const d = history.undo();
    if (d) { designs[docType.value] = d; schedulePreview(); }
}

function redo() {
    const d = history.redo();
    if (d) { designs[docType.value] = d; schedulePreview(); }
}

// ───────────────────────── Bausteine ─────────────────────────

function addBlock(key, at = null) {
    if (!design.value) return;
    const item = palette.value.find(i => i.key === key);
    if (!item) return;
    const block = item.build(docType.value);
    if (at) { block.x = at.x; block.y = at.y; }
    design.value.blocks.push(block);
    selectedId.value = block.id;
    commit();
}

function addSection(type) {
    if (!design.value) return;
    const tpl = sectionTemplatesList.value.find(s => s.type === type);
    if (!tpl) return;
    const section = tpl.build();
    design.value.body.sections.push(section);
    selectedId.value = section.id;
    commit();
}

function addImage(path, at = null) {
    if (!design.value) return;
    const img = data.value.images.find(i => i.path === path);
    const ratio = img?.width && img?.height ? img.height / img.width : 0.35;
    const w = 60;
    const block = { id: uid(), type: 'image', x: at?.x ?? 20, y: at?.y ?? 10, w, h: Math.max(5, Math.round(w * ratio * 2) / 2), pages: 'all', props: { image: path, align: 'left' } };
    design.value.blocks.push(block);
    selectedId.value = block.id;
    commit();
}

/** Feld in den gewählten Text einfügen, sonst als neuen Textbaustein anlegen */
function insertField(key, at = null) {
    if (!design.value) return;
    const tag = `<%${key}%>`;
    const block = design.value.blocks.find(b => b.id === selectedId.value);
    if (block && !at && (block.type === 'text' || block.type === 'pagenumber')) {
        block.props.text = (block.props.text || '') + (block.props.text ? ' ' : '') + tag;
        commit();
        return;
    }
    const section = design.value.body.sections.find(s => s.id === selectedId.value);
    if (section && !at && (section.type === 'text' || section.type === 'subject')) {
        section.text = (section.text || '') + (section.text ? ' ' : '') + tag;
        commit();
        return;
    }
    const sample = fieldGroups.value.flatMap(g => g.fields).find(f => f.key === key);
    const newBlock = { id: uid(), type: 'text', x: at?.x ?? 20, y: at?.y ?? 120, w: Math.max(30, Math.min(80, (sample?.sample?.length || 10) * 2.2)), h: 6, pages: 'first', props: { text: tag } };
    design.value.blocks.push(newBlock);
    selectedId.value = newBlock.id;
    commit();
}

function onDrop(payload, at) {
    if (payload.kind === 'block') addBlock(payload.key, at);
    else if (payload.kind === 'field') insertField(payload.key, at);
    else if (payload.kind === 'image') addImage(payload.path, at);
}

function deleteSelected() {
    if (!design.value || !selectedId.value || selectedId.value === 'body') return;
    const bi = design.value.blocks.findIndex(b => b.id === selectedId.value);
    if (bi >= 0) design.value.blocks.splice(bi, 1);
    const si = design.value.body.sections.findIndex(s => s.id === selectedId.value);
    if (si >= 0) design.value.body.sections.splice(si, 1);
    selectedId.value = null;
    commit();
}

function duplicateSelected() {
    if (!design.value) return;
    const block = design.value.blocks.find(b => b.id === selectedId.value);
    if (block) {
        const copy = clone(block);
        copy.id = uid();
        copy.x = Math.min(200, block.x + 5);
        copy.y = Math.min(290, block.y + 5);
        design.value.blocks.push(copy);
        selectedId.value = copy.id;
        commit();
    }
}

function moveSection(delta) {
    if (!design.value) return;
    const list = design.value.body.sections;
    const i = list.findIndex(s => s.id === selectedId.value);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    commit();
}

function reorderSections(sections) {
    if (!design.value) return;
    design.value.body.sections = sections;
    commit();
}

/**
 * Textabschnitt des Fließbereichs als frei platzierbaren Baustein auf die Seite legen
 * (Knopf im Eigenschaften-Panel oder Abschnitt aus dem Fließbereich herausziehen)
 */
function sectionToBlock(id = selectedId.value, atX = null, atY = null) {
    if (!design.value) return;
    const list = design.value.body.sections;
    const i = list.findIndex(s => s.id === id);
    const section = list[i];
    if (!section || (section.type !== 'text' && section.type !== 'subject')) return;
    const page = design.value.page;
    const w = atX !== null ? Math.min(80, 210 - atX) : 210 - page.marginLeft - page.marginRight;
    const block = {
        id: uid(), type: 'text', pages: 'first',
        x: atX ?? page.marginLeft, y: atY ?? Math.max(10, page.bodyTop - 25), w: Math.max(20, w), h: 20,
        props: { text: section.text || '', fontSize: section.fontSize, bold: !!section.bold || section.type === 'subject',
                 italic: !!section.italic, align: section.align || 'left', color: section.color || '', condition: section.condition || '' },
    };
    list.splice(i, 1);
    design.value.blocks.push(block);
    selectedId.value = block.id;
    commit();
}

/** Freien Textbaustein in den Fließbereich übernehmen (am Ende) */
function blockToSection() {
    if (!design.value) return;
    const bi = design.value.blocks.findIndex(b => b.id === selectedId.value);
    const block = design.value.blocks[bi];
    if (!block || block.type !== 'text') return;
    const p = block.props || {};
    const section = { id: uid('s'), type: 'text', text: p.text || '', fontSize: p.fontSize, bold: !!p.bold, italic: !!p.italic,
                      align: p.align || 'left', color: p.color || '', condition: p.condition || '', spaceAfter: 3 };
    design.value.blocks.splice(bi, 1);
    design.value.body.sections.push(section);
    selectedId.value = section.id;
    commit();
}

async function onModeChange(next) {
    if (next === 'design' && sourceDirty.value) {
        const r = await alerts.warning(t('TemplateDesigner.alerts.discardChanges'), t('TemplateDesigner.alerts.unsavedTitle'), t('TemplateDesigner.common.discard'), t('TemplateDesigner.common.cancel'));
        if (!r.isConfirmed) { mode.value = 'source'; return; }
        sourceDirty.value = false;
    }
    if (next === 'design') {
        // Dateien könnten sich geändert haben (handgeschrieben ↔ erzeugt)
        await load({}, true);
    }
}

/** Quelltext einer Datei öffnen (z. B. "Quelltext bearbeiten" bei handgeschriebener Vorlage) */
function openSource(path) {
    sourcePath.value = path || '';
    mode.value = 'source';
}

function nudge(dx, dy) {
    const block = design.value?.blocks.find(b => b.id === selectedId.value);
    if (!block) return;
    block.x = Math.max(0, Math.min(210 - block.w, block.x + dx));
    block.y = Math.max(0, Math.min(297 - block.h, block.y + dy));
    commit();
}

function onKey(e) {
    const tag = (e.target?.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target?.isContentEditable) return;
    if (startDialog.value || applyDialog.value || versionsDialog.value || saveDialog.open) return;
    if (mode.value !== 'design' || !design.value) return;
    const step = e.shiftKey ? 5 : 1;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (isDirty(docType.value)) save(false); return; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') { e.preventDefault(); duplicateSelected(); return; }
    switch (e.key) {
        case 'Delete': case 'Backspace': e.preventDefault(); deleteSelected(); break;
        case 'ArrowLeft': e.preventDefault(); nudge(-step, 0); break;
        case 'ArrowRight': e.preventDefault(); nudge(step, 0); break;
        case 'ArrowUp': e.preventDefault(); nudge(0, -step); break;
        case 'ArrowDown': e.preventDefault(); nudge(0, step); break;
        case 'Escape': selectedId.value = null; break;
    }
}

// ───────────────────────── Bilder ─────────────────────────

async function uploadImage(filename, dataUrl) {
    uploading.value = true;
    try {
        const r = await faktura.designerUploadImage(templateSet.value, filename, dataUrl);
        data.value.images = r.images;
        if (imageUrls[r.path]) { URL.revokeObjectURL(imageUrls[r.path]); delete imageUrls[r.path]; }
        await loadImages(r.images);
        toasts.success(t('TemplateDesigner.alerts.imageUploaded', { path: r.path }));
        return r.path;
    } catch (e) {
        toasts.error(e?.message || t('TemplateDesigner.alerts.uploadFailed'));
        return null;
    } finally {
        uploading.value = false;
    }
}

/** Logo aus der Firmenkonfiguration in den Vorlagensatz übernehmen und platzieren */
async function useCompanyLogo() {
    if (!suggestions.value.logoDataUrl) return;
    const path = await uploadImage('firmenlogo.png', suggestions.value.logoDataUrl);
    if (!path || !design.value) return;
    const empty = design.value.blocks.find(b => b.type === 'image' && !b.props.image);
    if (empty) { empty.props.image = path; selectedId.value = empty.id; commit(); }
    else addImage(path, { x: 120, y: 12 });
}

// ───────────────────────── Startvorlagen & Layout ─────────────────────────

function startWithPreset(p) {
    const logo = data.value.images.find(i => /logo/i.test(i.path))?.path || data.value.images[0]?.path || '';
    designs[docType.value] = normalizeDesign(p.build(docType.value, logo));
    startDialog.value = false;
    selectedId.value = null;
    history.reset(null);
    commit();
    if (!originals[docType.value]) originals[docType.value] = '';
}

function startFromType(type) {
    const source = designs[type];
    if (!source) return;
    const d = clone(source);
    d.blocks = d.blocks.map(b => ({ ...b, id: uid() }));
    d.body.sections = d.body.sections.map(s => ({ ...s, id: uid('s') }));
    designs[docType.value] = d;
    startDialog.value = false;
    history.reset(null);
    commit();
    if (!originals[docType.value]) originals[docType.value] = '';
}

/**
 * Seite und Bausteine der aktuellen Belegart auf andere übertragen; deren Fließbereich bleibt.
 * Belegspezifisches wird dabei übersetzt: Belegname, Nummern- und Datumsfeld in Texten
 * (z. B. die laufende Kopfzeile) und die Zeilen der Infobox.
 */
function applyLayout() {
    const source = design.value;
    if (!source) return;
    const din = presetList.value.find(p => p.key === 'din5008');
    const from = docType.value;
    for (const type of applyTargets.value) {
        const base = designs[type] ? clone(designs[type]) : normalizeDesign(din.build(type, ''));
        base.page = clone(source.page);
        base.blocks = source.blocks.map(b => {
            const copy = { ...clone(b), id: uid() };
            if ((copy.type === 'text' || copy.type === 'pagenumber') && copy.props?.text) {
                copy.props.text = copy.props.text
                    .split(`<%${numberField(from)}%>`).join(`<%${numberField(type)}%>`)
                    .split(`<%${dateField(from)}%>`).join(`<%${dateField(type)}%>`)
                    .split(docLabel(t, from)).join(docLabel(t, type));
            }
            if (copy.type === 'infobox') {
                copy.props.rows = infoRows(t, type);
            }
            return copy;
        });
        designs[type] = base;
        if (!originals[type]) originals[type] = '';
    }
    applyDialog.value = false;
    applyTargets.value = [];
    toasts.success(t('TemplateDesigner.apply.done'));
}

async function loadVersion(id) {
    try {
        const v = await faktura.designerVersion(id);
        designs[docType.value] = normalizeDesign(v.design);
        versionsDialog.value = false;
        selectedId.value = null;
        commit();
        toasts.info(t('TemplateDesigner.versions.loaded', { version: v.version }));
    } catch (e) {
        toasts.error(e?.message || String(e));
    }
}

function formatVersion(v) {
    const when = v.itime ? new Date(v.itime).toLocaleString(locale.value) : '';
    return [when, v.employee].filter(Boolean).join(' · ');
}

async function removeDesign() {
    const r = await alerts.warning(t('TemplateDesigner.alerts.removeText', { file: currentType.value?.file }), t('TemplateDesigner.menu.remove'), t('TemplateDesigner.common.remove'), t('TemplateDesigner.common.cancel'));
    if (!r.isConfirmed) return;
    try {
        const res = await faktura.designerRemove(templateSet.value, docType.value);
        delete designs[docType.value];
        delete originals[docType.value];
        toasts.success(res.restored ? t('TemplateDesigner.alerts.restored', { file: res.restored }) : t('TemplateDesigner.alerts.removed'));
        await load({}, true);
        history.reset(null);
    } catch (e) {
        toasts.error(e?.message || String(e));
    }
}

// ───────────────────────── Speichern ─────────────────────────

function save(all) {
    const types = (all ? dirtyTypes.value : [docType.value]).map(k => docTypes.value.find(d => d.key === k)).filter(Boolean);
    if (!types.length) return;
    saveDialog.types = types;
    saveDialog.handmade = types.filter(d => d.handmade).map(d => d.file);
    saveDialog.kfz = types.some(d => d.kfzOverride);
    saveDialog.alsoKfz = false;
    saveDialog.all = all;
    // Ohne Besonderheiten direkt speichern — der Dialog ist nur für Rückfragen da
    if (!saveDialog.handmade.length && !saveDialog.kfz) { doSave(); return; }
    saveDialog.open = true;
}

async function doSave() {
    saving.value = true;
    try {
        const items = saveDialog.types.map(d => ({ documentType: d.key, design: designs[d.key] }));
        const r = await faktura.designerSave(templateSet.value, items, saveDialog.alsoKfz);
        for (const d of saveDialog.types) originals[d.key] = JSON.stringify(designs[d.key]);
        saveDialog.open = false;
        toasts.success(r.backups?.length
            ? t('TemplateDesigner.alerts.savedWithBackup', { files: r.written.join(', '), backups: r.backups.join(', ') })
            : t('TemplateDesigner.alerts.saved', { files: r.written.join(', ') }));
        await load({}, true);
    } catch (e) {
        alerts.error(e?.message || String(e), t('TemplateDesigner.alerts.saveFailed'), e);
    } finally {
        saving.value = false;
    }
}

// ───────────────────────── Vorschau ─────────────────────────

let previewTimer = null;

function togglePreview() {
    preview.open = !preview.open;
    if (preview.open) renderPreview();
}

function schedulePreview() {
    if (!preview.open || !preview.auto) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(renderPreview, 1200);
}

async function renderPreview() {
    if (!design.value) return;
    preview.loading = true;
    preview.error = '';
    preview.debug = '';
    try {
        if (preview.mode === 'images') {
            preview.pages = await faktura.designerPreviewPages(templateSet.value, docType.value, design.value, preview.documentId);
            if (preview.url) { URL.revokeObjectURL(preview.url); preview.url = ''; }
        } else {
            preview.pages = [];
            const url = await faktura.designerPreview(templateSet.value, docType.value, design.value, preview.documentId);
            if (preview.url) URL.revokeObjectURL(preview.url);
            preview.url = url;
        }
    } catch (e) {
        preview.error = e?.message || String(e);
        preview.debug = typeof e?.debug === 'string' ? e.debug : (e?.payload || '');
    } finally {
        preview.loading = false;
    }
}

function togglePreviewMode() {
    preview.mode = preview.mode === 'images' ? 'pdf' : 'images';
    renderPreview();
}

function openPreviewWindow() {
    if (preview.url) window.open(preview.url, '_blank');
    else if (preview.pages.length) window.open(preview.pages[0], '_blank');
}

function fitZoom() {
    const el = document.querySelector('.tpl-canvas-scroll');
    if (!el) return;
    zoom.value = Math.max(1.5, Math.min(8, Math.floor(((el.clientWidth - 80) / 210) * 10) / 10));
}

// ───────────────────────── Lebenszyklus ─────────────────────────

watch(() => preview.auto, v => { if (v) schedulePreview(); });

onMounted(async () => {
    // Tastenkürzel gelten im ganzen Editor, nicht nur bei fokussierter Seite
    window.addEventListener('keydown', onKey);
    await load({}, false);
    history.reset(design.value);
    requestAnimationFrame(fitZoom);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    clearTimeout(previewTimer);
    if (preview.url) URL.revokeObjectURL(preview.url);
    releaseImages();
});

onBeforeRouteLeave(async () => {
    if (!dirtyTypes.value.length && !sourceDirty.value) return true;
    const r = await alerts.warning(t('TemplateDesigner.alerts.discardChanges'), t('TemplateDesigner.alerts.unsavedTitle'), t('TemplateDesigner.common.discard'), t('TemplateDesigner.common.cancel'));
    return r.isConfirmed;
});

// Für das Template
void BLOCK_TYPES;
</script>

<style>
/* Der Editor braucht die ganze Breite: die Seitenränder, die das globale
   Layout breiten Bildschirmen gibt (style.css, >= 1900px), entfallen hier. */
.v-main:has(> .tpl-designer) {
    padding-left: var(--v-layout-left, 0px) !important;
    padding-right: var(--v-layout-right, 0px) !important;
}
</style>

<style scoped>
.tpl-designer {
    display: flex;
    flex-direction: column;
    /* Fensterhöhe abzüglich der App-Leiste (v-main setzt --v-layout-top) */
    height: calc(100vh - var(--v-layout-top, 56px));
    overflow: hidden;
}
/* Die Info-Leiste des Navbars ist sticky; in diesem festen Rahmen würde sie
   über die Werkzeugleiste rutschen — hier bleibt sie im Fluss. */
.tpl-designer :deep(.info-bar) {
    position: relative;
    top: auto;
}
.tpl-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    background: rgb(var(--v-theme-surface));
    flex-wrap: wrap;
}
.tpl-set-select {
    max-width: 220px;
    min-width: 170px;
}
.tpl-doctypes {
    display: flex;
    gap: 4px;
    overflow-x: auto;
    max-width: 38vw;
    padding: 2px;
}
.tpl-doctype-chip {
    flex: 0 0 auto;
    cursor: pointer;
}
.tpl-dirty-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #ff9800;
    margin-left: 6px;
}
.tpl-zoom-label {
    min-width: 56px;
    font-size: 12px;
}
.tpl-pageview {
    height: 32px;
}
.tpl-workspace {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
}
/* Hinweise über der Arbeitsfläche dürfen im Flex-Rahmen nicht zusammengedrückt werden */
.tpl-designer > .v-alert {
    flex: 0 0 auto;
}
.tpl-side {
    flex: 0 0 290px;
    overflow-y: auto;
    padding: 8px;
    background: rgb(var(--v-theme-surface));
}
.tpl-side--left {
    border-right: 1px solid rgba(0, 0, 0, 0.1);
}
.tpl-side--right {
    flex-basis: 340px;
    border-left: 1px solid rgba(0, 0, 0, 0.1);
}
.tpl-center {
    flex: 1 1 auto;
    display: flex;
    min-width: 0;
    background: #e4e7eb;
}
.tpl-canvas-scroll {
    flex: 1 1 auto;
    overflow: auto;
    outline: none;
}
.tpl-center--split .tpl-canvas-scroll {
    flex: 1 1 50%;
}
.tpl-preview {
    flex: 1 1 50%;
    display: flex;
    flex-direction: column;
    min-width: 320px;
    border-left: 1px solid rgba(0, 0, 0, 0.12);
    background: #fff;
}
.tpl-preview-bar {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 8px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    white-space: nowrap;
}
.tpl-preview-doc {
    flex: 1 1 auto;
    min-width: 140px;
    max-width: 320px;
}
.tpl-preview-bar :deep(.v-switch .v-label) {
    white-space: nowrap;
}
.tpl-preview-frame {
    flex: 1 1 auto;
    border: none;
    width: 100%;
}
.tpl-preview-pages {
    flex: 1 1 auto;
    overflow: auto;
    background: #525659;
    padding: 12px;
}
.tpl-preview-page {
    display: block;
    width: 100%;
    max-width: 900px;
    margin: 0 auto 12px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
    background: #fff;
}
.tpl-preview-empty {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(0, 0, 0, 0.5);
}
.tpl-preview-error {
    max-height: 50%;
    overflow: auto;
}
.tpl-preview-log {
    font-size: 11px;
    white-space: pre-wrap;
    max-height: 300px;
    overflow: auto;
}
.tpl-no-design {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    text-align: center;
    padding: 32px;
}
.tpl-preset-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
}
.tpl-preset {
    padding: 16px 12px;
    text-align: center;
    cursor: pointer;
}
</style>
