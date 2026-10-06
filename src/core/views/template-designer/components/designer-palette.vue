<!-- src/core/views/template-designer/components/designer-palette.vue -->
<!--
    Linke Spalte: Bausteine, Abschnitte des Fließbereichs, Felder des Belegs
    und Bilder des Vorlagensatzes. Ein Klick legt den Baustein an einer
    sinnvollen Stelle an, Ziehen auf die Seite platziert ihn dort. Felder
    lassen sich in den gerade bearbeiteten Text einfügen oder als neuen
    Textbaustein auf die Seite ziehen.
-->
<template>
    <div class="tpl-palette">
        <v-expansion-panels v-model="open" multiple variant="accordion" density="compact">
            <!-- Bausteine -->
            <v-expansion-panel value="blocks">
                <v-expansion-panel-title class="tpl-palette-title">
                    <v-icon size="small" class="me-2">mdi-shape-outline</v-icon>{{ t('TemplateDesigner.palette.blocks') }}
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <div class="tpl-palette-grid">
                        <div
                            v-for="item in items"
                            :key="item.key"
                            class="tpl-palette-item"
                            draggable="true"
                            :title="t('TemplateDesigner.palette.clickOrDrag')"
                            @dragstart="dragStart($event, { kind: 'block', key: item.key })"
                            @click="$emit('add-block', item.key)"
                        >
                            <v-icon size="small">{{ item.icon }}</v-icon>
                            <span>{{ item.title }}</span>
                        </div>
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- Abschnitte des Fließbereichs -->
            <v-expansion-panel value="sections">
                <v-expansion-panel-title class="tpl-palette-title">
                    <v-icon size="small" class="me-2">mdi-view-sequential-outline</v-icon>{{ t('TemplateDesigner.palette.sections') }}
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <div class="tpl-palette-grid">
                        <div
                            v-for="item in sections"
                            :key="item.type"
                            class="tpl-palette-item"
                            :title="t('TemplateDesigner.palette.clickToAppend')"
                            @click="$emit('add-section', item.type)"
                        >
                            <v-icon size="small">{{ item.icon }}</v-icon>
                            <span>{{ item.title }}</span>
                        </div>
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- Felder -->
            <v-expansion-panel value="fields">
                <v-expansion-panel-title class="tpl-palette-title">
                    <v-icon size="small" class="me-2">mdi-code-braces</v-icon>{{ t('TemplateDesigner.palette.fields') }}
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <v-text-field
                        v-model="fieldFilter"
                        density="compact"
                        variant="outlined"
                        hide-details
                        clearable
                        prepend-inner-icon="mdi-magnify"
                        :placeholder="t('TemplateDesigner.palette.searchField')"
                        class="mb-2"
                    />
                    <div v-if="!sample" class="text-caption text-medium-emphasis mb-2">{{ t('TemplateDesigner.palette.noSample') }}</div>
                    <div v-for="group in filteredGroups" :key="group.key" class="mb-2">
                        <div class="tpl-field-group">{{ group.title }}</div>
                        <div
                            v-for="field in group.fields"
                            :key="field.key"
                            class="tpl-field"
                            draggable="true"
                            :title="`<%${field.key}%>`"
                            @dragstart="dragStart($event, { kind: 'field', key: field.key })"
                            @click="$emit('insert-field', field.key)"
                        >
                            <span class="tpl-field-label">{{ field.label }}</span>
                            <span class="tpl-field-sample">{{ field.sample || '–' }}</span>
                        </div>
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- Bilder -->
            <v-expansion-panel value="images">
                <v-expansion-panel-title class="tpl-palette-title">
                    <v-icon size="small" class="me-2">mdi-image-multiple-outline</v-icon>{{ t('TemplateDesigner.palette.images') }}
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <div class="d-flex flex-wrap ga-1 mb-2">
                        <v-btn size="small" variant="tonal" prepend-icon="mdi-upload" :disabled="!writable" :loading="uploading" @click="fileInput?.click()">
                            {{ t('TemplateDesigner.palette.upload') }}
                        </v-btn>
                        <v-btn v-if="hasCompanyLogo" size="small" variant="tonal" prepend-icon="mdi-domain" :disabled="!writable" :loading="uploading" @click="$emit('use-company-logo')">
                            {{ t('TemplateDesigner.palette.companyLogo') }}
                        </v-btn>
                        <input ref="fileInput" type="file" accept="image/png,image/jpeg,application/pdf" class="d-none" @change="onFile">
                    </div>
                    <div v-if="!images.length" class="text-caption text-medium-emphasis">{{ t('TemplateDesigner.palette.noImages') }}</div>
                    <div class="tpl-image-grid">
                        <div
                            v-for="img in images"
                            :key="img.path"
                            class="tpl-image-item"
                            draggable="true"
                            :title="img.path"
                            @dragstart="dragStart($event, { kind: 'image', path: img.path })"
                            @click="$emit('add-image', img.path)"
                        >
                            <img v-if="imageUrls[img.path]" :src="imageUrls[img.path]" alt="">
                            <v-icon v-else size="large">mdi-file-pdf-box</v-icon>
                            <span class="tpl-image-name">{{ img.path }}</span>
                        </div>
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    items: { type: Array, required: true },       // paletteItems()
    sections: { type: Array, required: true },    // sectionTemplates()
    fieldGroups: { type: Array, required: true }, // fieldCatalog()
    sample: { type: Object, default: null },
    images: { type: Array, default: () => [] },
    imageUrls: { type: Object, default: () => ({}) },
    writable: { type: Boolean, default: false },
    uploading: { type: Boolean, default: false },
    hasCompanyLogo: { type: Boolean, default: false },
});
const emit = defineEmits(['add-block', 'add-section', 'insert-field', 'add-image', 'upload', 'use-company-logo']);

const { t } = useI18n();
const open = ref(['blocks', 'fields']);
const fieldFilter = ref('');
const fileInput = ref(null);

const filteredGroups = computed(() => {
    const q = (fieldFilter.value || '').toLowerCase().trim();
    if (!q) return props.fieldGroups;
    return props.fieldGroups
        .map(g => ({ ...g, fields: g.fields.filter(f => f.key.includes(q) || f.label.toLowerCase().includes(q) || f.sample.toLowerCase().includes(q)) }))
        .filter(g => g.fields.length);
});

function dragStart(e, payload) {
    e.dataTransfer.setData('application/x-oserp-designer', JSON.stringify(payload));
    e.dataTransfer.effectAllowed = 'copy';
}

function onFile(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => emit('upload', file.name, reader.result);
    reader.readAsDataURL(file);
}
</script>

<style scoped>
.tpl-palette {
    font-size: 13px;
}
.tpl-palette-title {
    min-height: 40px;
    font-weight: 500;
    font-size: 13px;
}
.tpl-palette-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}
.tpl-palette-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 8px 4px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 6px;
    cursor: grab;
    text-align: center;
    font-size: 11.5px;
    line-height: 1.2;
    background: rgba(var(--v-theme-surface), 1);
    transition: background 0.15s, border-color 0.15s;
}
.tpl-palette-item:hover {
    background: rgba(var(--v-theme-primary), 0.08);
    border-color: rgba(var(--v-theme-primary), 0.5);
}
.tpl-field-group {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.55);
    margin: 6px 0 2px;
}
.tpl-field {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    padding: 3px 6px;
    border-radius: 4px;
    cursor: grab;
    font-size: 12px;
}
.tpl-field:hover {
    background: rgba(var(--v-theme-primary), 0.08);
}
.tpl-field-label {
    white-space: nowrap;
}
.tpl-field-sample {
    color: rgba(var(--v-theme-on-surface), 0.5);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 55%;
    text-align: right;
}
.tpl-image-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
}
.tpl-image-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 6px;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 6px;
    cursor: grab;
    background: repeating-conic-gradient(rgba(0,0,0,0.04) 0 25%, transparent 0 50%) 0 0 / 12px 12px;
}
.tpl-image-item:hover {
    border-color: rgba(var(--v-theme-primary), 0.5);
}
.tpl-image-item img {
    max-width: 100%;
    max-height: 48px;
    object-fit: contain;
}
.tpl-image-name {
    font-size: 10px;
    word-break: break-all;
    text-align: center;
    line-height: 1.2;
}
</style>
