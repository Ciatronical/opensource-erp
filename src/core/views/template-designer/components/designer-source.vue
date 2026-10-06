<!-- src/core/views/template-designer/components/designer-source.vue -->
<!--
    Quelltext-Modus: vorhandene Vorlagendateien (.tex/.sty) des Satzes direkt
    bearbeiten — Belegvorlagen, Firmendaten (ident.tex, euro_account.tex),
    Einstellungen (insettings.tex), Sprachdateien.

    Links die Dateien nach Art, in der Mitte der Editor mit PDF-Vorschau,
    rechts ein Formular mit den \newcommand-Werten der Datei (Firma, Straße,
    IBAN …) samt Vorschlägen aus der Firmenkonfiguration — Firmendaten lassen
    sich so pflegen, ohne LaTeX zu lesen. Jede Speicherung sichert den alten
    Stand als .bak-<Zeit>.
-->
<template>
    <div class="tpl-source">
        <!-- Dateien -->
        <aside class="tpl-source-files">
            <v-text-field v-model="filter" density="compact" variant="outlined" hide-details clearable prepend-inner-icon="mdi-magnify" :placeholder="t('TemplateDesigner.source.searchFile')" class="ma-2" />
            <div v-for="group in groups" :key="group.kind" class="mb-2">
                <div class="tpl-source-group">{{ t(`TemplateDesigner.source.kinds.${group.kind}`) }}</div>
                <div
                    v-for="f in group.files"
                    :key="f.path"
                    class="tpl-source-file"
                    :class="{ 'tpl-source-file--active': f.path === file.path }"
                    @click="open(f.path)"
                >
                    <v-icon size="small" class="me-1">{{ f.generated ? 'mdi-auto-fix' : (f.kind === 'document' ? 'mdi-file-document-outline' : 'mdi-file-code-outline') }}</v-icon>
                    <span class="tpl-source-name text-truncate">{{ f.path }}</span>
                    <span v-if="f.documentType" class="tpl-source-type">{{ docLabel(t, f.documentType) }}</span>
                </div>
            </div>
        </aside>

        <!-- Editor + Vorschau -->
        <main class="tpl-source-center" :class="{ 'tpl-source-center--split': preview.open }">
            <div class="tpl-source-editor">
                <div class="tpl-source-bar">
                    <v-icon size="small" class="me-1">mdi-file-code-outline</v-icon>
                    <span class="font-weight-medium">{{ file.path || t('TemplateDesigner.source.noFile') }}</span>
                    <span v-if="isDirty" class="tpl-dirty-dot ms-2" :title="t('TemplateDesigner.toolbar.unsaved')" />
                    <span v-if="file.path" class="text-caption text-medium-emphasis ms-3">{{ t('TemplateDesigner.source.line') }} {{ cursorLine }}</span>
                    <v-spacer />
                    <v-btn size="small" variant="tonal" prepend-icon="mdi-file-pdf-box" :loading="preview.loading" :disabled="!file.path" :color="preview.open ? 'primary' : undefined" @click="togglePreview">
                        {{ t('TemplateDesigner.toolbar.preview') }}
                    </v-btn>
                    <v-btn size="small" color="primary" prepend-icon="mdi-content-save" class="ms-2" :loading="saving" :disabled="!writable || !isDirty" @click="save">
                        {{ t('TemplateDesigner.toolbar.save') }}
                    </v-btn>
                </div>
                <v-alert v-if="file.generated" type="warning" variant="tonal" density="compact" class="mx-2 mb-2">
                    {{ t('TemplateDesigner.source.generatedWarning') }}
                </v-alert>
                <v-alert v-if="!writable" type="warning" variant="tonal" density="compact" class="mx-2 mb-2">{{ t('TemplateDesigner.alerts.readOnly') }}</v-alert>
                <div class="tpl-source-code">
                    <designer-code v-if="file.path" ref="code" v-model="file.content" :readonly="!writable" @save="save" @cursor="cursorLine = $event.line" />
                    <div v-else class="tpl-source-empty">
                        <v-icon size="40" color="primary">mdi-file-code-outline</v-icon>
                        <div class="text-body-1 mt-2">{{ t('TemplateDesigner.source.pickFile') }}</div>
                        <div class="text-body-2 text-medium-emphasis">{{ t('TemplateDesigner.source.intro') }}</div>
                    </div>
                </div>
            </div>

            <div v-if="preview.open" class="tpl-preview">
                <div class="tpl-preview-bar">
                    <v-select v-model="preview.documentType" :items="docTypeItems" density="compact" variant="outlined" hide-details class="tpl-preview-doc" :label="t('TemplateDesigner.source.previewAs')" @update:model-value="renderPreview" />
                    <v-switch v-model="preview.auto" :label="t('TemplateDesigner.preview.auto')" color="primary" density="compact" hide-details class="ms-2" />
                    <v-spacer />
                    <v-btn :icon="preview.mode === 'images' ? 'mdi-file-pdf-box' : 'mdi-image-multiple-outline'" size="small" variant="text" @click="preview.mode = preview.mode === 'images' ? 'pdf' : 'images'; renderPreview()" />
                    <v-btn icon="mdi-refresh" size="small" variant="text" :loading="preview.loading" @click="renderPreview" />
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

        <!-- Werte der Datei -->
        <aside class="tpl-source-side">
            <template v-if="file.path">
                <div class="tpl-inspector-head">
                    <v-icon size="small" class="me-2">mdi-form-textbox</v-icon>{{ t('TemplateDesigner.source.values') }}
                </div>
                <div v-if="!variables.length" class="text-body-2 text-medium-emphasis">{{ t('TemplateDesigner.source.noValues') }}</div>
                <div v-for="v in variables" :key="v.name" class="tpl-source-var">
                    <v-text-field
                        :model-value="v.value"
                        :label="'\\' + v.name"
                        density="compact"
                        variant="outlined"
                        hide-details
                        :readonly="!writable"
                        @update:model-value="setVariable(v, $event)"
                        @focus="code?.goToLine(v.line)"
                    />
                    <v-chip
                        v-if="suggestionFor(v) && suggestionFor(v) !== v.value"
                        size="x-small"
                        variant="tonal"
                        color="primary"
                        class="mt-1"
                        prepend-icon="mdi-domain"
                        :title="t('TemplateDesigner.source.useSuggestion')"
                        @click="setVariable(v, suggestionFor(v))"
                    >
                        {{ suggestionFor(v) }}
                    </v-chip>
                </div>
                <div class="tpl-group-title">{{ t('TemplateDesigner.source.info') }}</div>
                <div class="text-body-2">
                    <div>{{ t(`TemplateDesigner.source.kinds.${file.kind}`) }}<span v-if="file.documentType"> · {{ docLabel(t, file.documentType) }}</span></div>
                    <div v-if="file.backups.length" class="text-caption text-medium-emphasis mt-1">{{ t('TemplateDesigner.source.backups', { count: file.backups.length, last: file.backups[file.backups.length - 1] }) }}</div>
                </div>
                <div class="tpl-group-title">{{ t('TemplateDesigner.source.help') }}</div>
                <div class="text-caption text-medium-emphasis tpl-source-help">
                    <div><code>&lt;%name%&gt;</code> {{ t('TemplateDesigner.source.helpField') }}</div>
                    <div><code>&lt;%if notes%&gt; … &lt;%end if%&gt;</code> {{ t('TemplateDesigner.source.helpIf') }}</div>
                    <div><code>&lt;%foreach number%&gt; … &lt;%end number%&gt;</code> {{ t('TemplateDesigner.source.helpLoop') }}</div>
                </div>
            </template>
        </aside>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { fakturaStore } from '@/core/stores/faktura.store.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import DesignerCode from './designer-code.vue';
import { docLabel } from '../designer.blocks.js';

const props = defineProps({
    templateSet: { type: String, required: true },
    writable: { type: Boolean, default: false },
    docTypes: { type: Array, default: () => [] },
    docType: { type: String, default: 'invoice' },
    suggestions: { type: Object, default: () => ({}) },   // companySuggestions()
    documentId: { type: Number, default: null },
    initialPath: { type: String, default: '' },
});
const emit = defineEmits(['dirty', 'files']);

const { t } = useI18n();
const faktura = fakturaStore();

const files = ref([]);
const filter = ref('');
const file = reactive({ path: '', content: '', original: '', kind: '', documentType: null, generated: false, backups: [] });
const saving = ref(false);
const cursorLine = ref(1);
const code = ref(null);
const preview = reactive({ open: false, url: '', pages: [], loading: false, error: '', debug: '', auto: true, documentType: 'invoice',
    mode: (typeof navigator !== 'undefined' && navigator.pdfViewerEnabled === false) ? 'images' : 'pdf' });

const isDirty = computed(() => !!file.path && file.content !== file.original);
watch(isDirty, v => emit('dirty', v));

const KIND_ORDER = ['document', 'identity', 'settings', 'language', 'other'];
const groups = computed(() => {
    const q = (filter.value || '').toLowerCase();
    const list = files.value.filter(f => !q || f.path.toLowerCase().includes(q));
    return KIND_ORDER.map(kind => ({ kind, files: list.filter(f => f.kind === kind) })).filter(g => g.files.length);
});

const docTypeItems = computed(() => props.docTypes.map(d => ({ title: docLabel(t, d.key), value: d.key })));

/** \newcommand-Werte direkt aus dem aktuellen Inhalt — so bleiben sie beim Tippen im Editor aktuell */
const variables = computed(() => {
    const out = [];
    file.content.split('\n').forEach((line, i) => {
        const m = line.match(/^\s*\\(?:re)?newcommand\s*\{\\([A-Za-z]+)\}\s*\{([^{}]*)\}/);
        // Technische Zuweisungen mit Platzhaltern (\lxlangcode usw.) gehören nicht ins Formular
        if (m && !m[2].includes('<%')) out.push({ name: m[1], value: m[2], line: i + 1 });
    });
    return out;
});

/** Vorschlag aus der Firmenkonfiguration für bekannte Variablennamen */
const SUGGESTION_MAP = {
    firma: 'company', strasse: 'street', ort: 'cityLine', email: 'email', emails: 'email', telefon: 'phone',
    ustid: 'ustid', stnr: 'taxnumber', steuernummer: 'taxnumber', iban: 'iban', bic: 'bic', bank: 'bank',
    kontoinhab: 'accountHolder', inhaber: 'ceo', homepage: 'homepage',
};
function suggestionFor(v) {
    const key = SUGGESTION_MAP[v.name.toLowerCase()];
    return key ? (props.suggestions[key] || '') : '';
}

/** Wert einer \newcommand-Zeile ersetzen; LaTeX-Sonderzeichen werden escaped, sofern der Wert kein LaTeX ist */
function setVariable(v, value) {
    const lines = file.content.split('\n');
    const line = lines[v.line - 1] || '';
    const safe = /\\/.test(value) ? value : String(value ?? '').replace(/([&%#_{}$])/g, '\\$1');
    lines[v.line - 1] = line.replace(/(\\(?:re)?newcommand\s*\{\\[A-Za-z]+\}\s*\{)([^{}]*)(\})/, (_, a, __, c) => a + safe + c);
    file.content = lines.join('\n');
    schedulePreview();
}

async function loadFiles() {
    try {
        const r = await faktura.designerFiles(props.templateSet);
        files.value = r.files || [];
        emit('files', files.value);
    } catch (e) {
        toasts.error(e?.message || String(e));
    }
}

async function open(path) {
    if (path === file.path) return;
    if (isDirty.value) {
        const r = await alerts.warning(t('TemplateDesigner.alerts.discardChanges'), t('TemplateDesigner.alerts.unsavedTitle'), t('TemplateDesigner.common.discard'), t('TemplateDesigner.common.cancel'));
        if (!r.isConfirmed) return;
    }
    try {
        const r = await faktura.designerFile(props.templateSet, path);
        Object.assign(file, { path: r.path, content: r.content, original: r.content, kind: r.kind, documentType: r.documentType, generated: r.generated, backups: r.backups || [] });
        if (r.documentType) preview.documentType = r.documentType;
        preview.url = ''; preview.pages = []; preview.error = '';
        if (preview.open) renderPreview();
    } catch (e) {
        toasts.error(e?.message || String(e));
    }
}

async function save() {
    if (!file.path || !props.writable || !isDirty.value) return;
    saving.value = true;
    try {
        const r = await faktura.designerSaveFile(props.templateSet, file.path, file.content);
        file.original = file.content;
        if (r.backup) file.backups = [...file.backups, r.backup];
        toasts.success(r.backup ? t('TemplateDesigner.source.savedBackup', { file: file.path, backup: r.backup }) : t('TemplateDesigner.source.saved', { file: file.path }));
        await loadFiles();
    } catch (e) {
        alerts.error(e?.message || String(e), t('TemplateDesigner.alerts.saveFailed'), e);
    } finally {
        saving.value = false;
    }
}

let previewTimer = null;
function schedulePreview() {
    if (!preview.open || !preview.auto) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(renderPreview, 1500);
}
watch(() => file.content, schedulePreview);

function togglePreview() {
    preview.open = !preview.open;
    if (preview.open) renderPreview();
}

async function renderPreview() {
    if (!file.path) return;
    preview.loading = true; preview.error = ''; preview.debug = '';
    try {
        if (preview.mode === 'images') {
            preview.pages = await faktura.designerPreviewFilePages(props.templateSet, file.path, file.content, preview.documentType, props.documentId);
            if (preview.url) { URL.revokeObjectURL(preview.url); preview.url = ''; }
        } else {
            const url = await faktura.designerPreviewFile(props.templateSet, file.path, file.content, preview.documentType, props.documentId);
            if (preview.url) URL.revokeObjectURL(preview.url);
            preview.url = url; preview.pages = [];
        }
    } catch (e) {
        preview.error = e?.message || String(e);
        preview.debug = typeof e?.debug === 'string' ? e.debug : '';
    } finally {
        preview.loading = false;
    }
}

/** Belegart gewechselt (Chips in der Werkzeugleiste): deren Vorlagendatei öffnen */
watch(() => props.docType, (type) => {
    const f = files.value.find(x => x.documentType === type && x.kind === 'document');
    if (f) open(f.path);
    preview.documentType = type;
});
watch(() => props.templateSet, async () => { Object.assign(file, { path: '', content: '', original: '' }); await loadFiles(); });

onMounted(async () => {
    await loadFiles();
    const wanted = props.initialPath || files.value.find(x => x.documentType === props.docType && x.kind === 'document')?.path;
    if (wanted) await open(wanted);
    preview.documentType = props.docType;
});
onBeforeUnmount(() => { clearTimeout(previewTimer); if (preview.url) URL.revokeObjectURL(preview.url); });

defineExpose({ isDirty, save, open });
</script>

<style scoped>
.tpl-source {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
}
.tpl-source-files {
    flex: 0 0 260px;
    overflow-y: auto;
    border-right: 1px solid rgba(0, 0, 0, 0.1);
    background: rgb(var(--v-theme-surface));
    font-size: 12.5px;
}
.tpl-source-group {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.55);
    padding: 6px 12px 2px;
}
.tpl-source-file {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    cursor: pointer;
}
.tpl-source-file:hover {
    background: rgba(var(--v-theme-primary), 0.06);
}
.tpl-source-file--active {
    background: rgba(var(--v-theme-primary), 0.12);
    font-weight: 600;
}
.tpl-source-name {
    min-width: 0;
    flex: 1 1 auto;
}
.tpl-source-type {
    font-size: 10.5px;
    color: rgba(var(--v-theme-on-surface), 0.5);
    white-space: nowrap;
}
.tpl-source-center {
    flex: 1 1 auto;
    display: flex;
    min-width: 0;
    background: #e4e7eb;
}
.tpl-source-editor {
    flex: 1 1 50%;
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 8px;
}
.tpl-source-bar {
    display: flex;
    align-items: center;
    padding: 0 4px 8px;
    font-size: 13px;
}
.tpl-source-code {
    flex: 1 1 auto;
    min-height: 0;
}
.tpl-source-empty {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 24px;
}
.tpl-source-side {
    flex: 0 0 320px;
    overflow-y: auto;
    padding: 10px;
    border-left: 1px solid rgba(0, 0, 0, 0.1);
    background: rgb(var(--v-theme-surface));
    font-size: 13px;
}
.tpl-source-var {
    margin-bottom: 10px;
}
.tpl-source-help div {
    margin-bottom: 4px;
}
.tpl-source-help code {
    background: rgba(0, 0, 0, 0.06);
    padding: 0 3px;
    border-radius: 3px;
}
.tpl-inspector-head {
    display: flex;
    align-items: center;
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 10px;
}
.tpl-group-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.55);
    margin: 14px 0 6px;
}
.tpl-dirty-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #ff9800;
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
    max-width: 260px;
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
</style>
