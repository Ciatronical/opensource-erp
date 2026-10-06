<!-- src/core/views/template-designer/components/designer-body.vue -->
<!--
    Der Fließbereich auf der Seite: Betreff, Texte, Positionstabelle, Summen,
    Unterschrift — in der Reihenfolge, in der sie später gedruckt werden.
    Abschnitte lassen sich anklicken (Eigenschaften rechts) und mit der Maus
    umsortieren. Gezeigt werden Beispielwerte des gewählten Belegs.
-->
<template>
    <VueDraggable
        :model-value="sections"
        class="tpl-body"
        :animation="150"
        :delay="120"
        :delay-on-touch-only="false"
        :fallback-tolerance="3"
        :force-fallback="true"
        ghost-class="tpl-section-ghost"
        :style="bodyStyle"
        @update:model-value="$emit('reorder', $event)"
        @end="onEnd"
    >
        <div
            v-for="section in sections"
            :key="section.id"
            class="tpl-section"
            :class="{ 'tpl-section--selected': section.id === selectedId }"
            :style="sectionStyle(section)"
            @pointerdown="$emit('select', section.id)"
        >
            <div class="tpl-section-tag" :title="t('TemplateDesigner.canvas.dragToReorder')">
                <v-icon size="x-small" class="me-1">mdi-drag-vertical</v-icon>{{ t(`TemplateDesigner.sections.${section.type}`) }}
            </div>

            <!-- Betreff / Text -->
            <div v-if="section.type === 'subject' || section.type === 'text'" :style="textStyle(section)" v-html="textHtml(section)" />

            <!-- Positionstabelle -->
            <table v-else-if="section.type === 'table'" class="tpl-table" :style="{ fontSize: pt(section.fontSize) }">
                <thead>
                    <tr :style="{ background: section.headerFill || 'transparent', borderBottom: section.rules !== 'none' ? `1px solid ${page.accentColor}` : 'none', borderTop: section.rules !== 'none' ? `1px solid ${page.accentColor}` : 'none' }">
                        <th v-for="col in visibleColumns(section)" :key="col.key" :style="columnStyle(col, section)">{{ col.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in sampleRows" :key="row.index"
                        :style="{ background: section.zebra && row.index % 2 === 0 ? (section.zebraFill || '#F6F8FB') : 'transparent', borderBottom: section.rules === 'rows' ? `1px solid ${page.accentColor}` : 'none' }">
                        <td v-for="col in visibleColumns(section)" :key="col.key" :style="columnStyle(col, section)">
                            <template v-if="col.key === 'description'">
                                <span :style="{ fontWeight: section.descriptionBold ? 700 : 400 }">{{ cell('description', row.index) }}</span>
                                <div v-if="section.longdescription !== false && cell('longdescription', row.index, '')" class="tpl-table-sub">{{ cell('longdescription', row.index, '') }}</div>
                                <div v-if="section.serialnumber && cell('serialnumber', row.index, '')" class="tpl-table-sub">{{ section.serialnumberLabel || t('TemplateDesigner.labels.serialnumber') }}: {{ cell('serialnumber', row.index, '') }}</div>
                            </template>
                            <template v-else-if="col.key === 'qty'">{{ cell('qty', row.index) }} {{ cell('unit', row.index, '') }}</template>
                            <template v-else-if="col.key === 'p_discount'">{{ discount(row.index) }}</template>
                            <template v-else>{{ cell(col.key, row.index) }}{{ ['sellprice', 'linetotal'].includes(col.key) ? currency : '' }}</template>
                        </td>
                    </tr>
                    <tr v-if="!sampleRows.length">
                        <td :colspan="visibleColumns(section).length" class="tpl-table-empty">{{ t('TemplateDesigner.canvas.noPositions') }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Summen -->
            <div v-else-if="section.type === 'totals'" class="tpl-totals-wrap">
                <table class="tpl-totals" :style="{ width: mm(section.width || 80), fontSize: pt(section.fontSize) }">
                    <template v-for="(row, i) in section.rows" :key="i">
                        <template v-if="row.type === 'tax'">
                            <tr v-for="(tax, ti) in taxRows" :key="`t${ti}`" :style="{ fontWeight: row.bold ? 700 : 400 }">
                                <td>{{ renderSample(row.label || '<%taxdescription%>', sample, ti) }}</td>
                                <td class="tpl-totals-value">{{ tax }}{{ currency }}</td>
                            </tr>
                        </template>
                        <tr v-else :style="{ fontWeight: row.bold ? 700 : 400, borderTop: row.ruleAbove ? `1px solid ${page.accentColor}` : 'none' }">
                            <td>{{ renderSample(row.label, sample) }}</td>
                            <td class="tpl-totals-value">{{ renderSample(row.value, sample) }}{{ currency }}</td>
                        </tr>
                    </template>
                </table>
            </div>

            <!-- Unterschrift -->
            <div v-else-if="section.type === 'signature'" class="tpl-signature">
                <div class="tpl-signature-box" :style="{ width: mm(section.width || 60) }"><span>{{ section.left }}</span></div>
                <div v-if="section.right" class="tpl-signature-box" :style="{ width: mm(section.width || 60) }"><span>{{ section.right }}</span></div>
            </div>

            <!-- Abstand -->
            <div v-else-if="section.type === 'spacer'" class="tpl-spacer" :style="{ height: mm(section.height || 5) }" />
        </div>

        <div v-if="!sections.length" class="tpl-body-empty">
            <v-icon size="small" class="me-1">mdi-information-outline</v-icon>
            {{ t('TemplateDesigner.canvas.bodyEmpty') }}
        </div>
    </VueDraggable>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { VueDraggable } from 'vue-draggable-plus';
import { renderSample, markupToHtml, PT_TO_MM } from '../designer.blocks.js';

const props = defineProps({
    sections: { type: Array, required: true },
    page: { type: Object, required: true },
    sample: { type: Object, default: null },
    scale: { type: Number, required: true },
    selectedId: { type: String, default: null },
});
const emit = defineEmits(['select', 'reorder', 'detach']);

/**
 * Losgelassen außerhalb des Fließbereichs: der Abschnitt wird zum freien
 * Baustein an der Mausposition (Notizen neben die Anschrift ziehen o. ä.)
 */
function onEnd(evt) {
    const e = evt?.originalEvent;
    const id = props.sections[evt?.oldIndex]?.id;
    if (!e || !id) return;
    const x = e.clientX ?? e.changedTouches?.[0]?.clientX;
    const y = e.clientY ?? e.changedTouches?.[0]?.clientY;
    if (x === undefined) return;
    const area = evt.to?.closest?.('.tpl-body-area')?.getBoundingClientRect();
    if (!area) return;
    const margin = 12;
    if (x < area.left - margin || x > area.right + margin || y < area.top - margin || y > area.bottom + margin) {
        emit('detach', { id, clientX: x, clientY: y });
    }
}

const { t } = useI18n();

const mm = (v) => `${(Number(v) || 0) * props.scale}px`;
const pt = (v) => `${(Number(v) || props.page.fontSize || 10) * PT_TO_MM * props.scale}px`;

const bodyStyle = computed(() => ({
    fontSize: pt(props.page.fontSize),
    fontFamily: props.page.font === 'serif' ? 'Georgia, "Times New Roman", serif' : 'Helvetica, Arial, sans-serif',
    color: props.page.textColor || '#222',
}));

const currency = computed(() => ({ euro: ' €', EUR: ' EUR', none: '' }[props.page.currency] ?? ' €'));

function sectionStyle(section) {
    return { marginBottom: mm(section.spaceAfter ?? 3) };
}

function textStyle(section) {
    return {
        fontSize: pt(section.fontSize),
        fontWeight: section.bold || section.type === 'subject' && section.bold !== false ? 700 : 400,
        fontStyle: section.italic ? 'italic' : 'normal',
        textAlign: section.align || 'left',
        color: section.color || undefined,
        lineHeight: String(section.lineHeight || 1.3),
    };
}

function textHtml(section) {
    return markupToHtml(renderSample(section.text || '', props.sample));
}

function visibleColumns(section) {
    return (section.columns || []).filter(c => c.visible !== false);
}

function columnStyle(col, section) {
    return {
        textAlign: col.align || 'left',
        width: col.width ? mm(col.width) : 'auto',
        padding: `${props.scale * 0.6}px ${props.scale * 1.2}px`,
        fontWeight: section.headerBold === false ? 400 : undefined,
    };
}

const sampleRows = computed(() => {
    const n = props.sample?.arrays?.description?.length || 0;
    return Array.from({ length: Math.min(n, 12) }, (_, index) => ({ index }));
});

function cell(key, index, fallback = null) {
    const value = props.sample?.arrays?.[key]?.[index];
    if (value === undefined || value === null || value === '') return fallback === null ? `[${key}]` : fallback;
    return String(value).replace(/<[^>]+>/g, '');
}

function discount(index) {
    const d = cell('p_discount', index, '0');
    return d === '0' ? '' : `${d} %`;
}

const taxRows = computed(() => props.sample?.arrays?.tax || []);
</script>

<style scoped>
.tpl-body {
    position: relative;
    width: 100%;
    min-height: 100%;
}
.tpl-section {
    position: relative;
    outline: 1px dashed transparent;
    outline-offset: 2px;
    transition: outline-color 0.15s;
    cursor: grab;
}
.tpl-section:active {
    cursor: grabbing;
}
.tpl-section:hover {
    outline-color: rgba(33, 150, 243, 0.45);
}
.tpl-section--selected {
    outline: 1.5px solid #1e88e5;
}
.tpl-section-tag {
    position: absolute;
    right: 0;
    top: -14px;
    font-size: 9px;
    line-height: 12px;
    padding: 0 4px;
    border-radius: 2px;
    background: #1e88e5;
    color: #fff;
    opacity: 0;
    pointer-events: none;
    font-family: Roboto, sans-serif;
    display: flex;
    align-items: center;
    z-index: 3;
}
.tpl-section:hover .tpl-section-tag,
.tpl-section--selected .tpl-section-tag {
    opacity: 1;
}
.tpl-section-ghost {
    opacity: 0.4;
    background: rgba(33, 150, 243, 0.08);
}
.tpl-table {
    width: 100%;
    border-collapse: collapse;
}
.tpl-table th {
    font-weight: 700;
    vertical-align: bottom;
}
.tpl-table td {
    vertical-align: top;
}
.tpl-table-sub {
    font-size: 0.8em;
    opacity: 0.85;
}
.tpl-table-empty {
    text-align: center;
    opacity: 0.5;
    padding: 6px;
}
.tpl-totals-wrap {
    display: flex;
    justify-content: flex-end;
}
.tpl-totals {
    border-collapse: collapse;
}
.tpl-totals td {
    padding: 1px 4px;
}
.tpl-totals-value {
    text-align: right;
    white-space: nowrap;
}
.tpl-signature {
    display: flex;
    justify-content: space-between;
    margin-top: 3em;
}
.tpl-signature-box {
    border-top: 1px solid currentColor;
    font-size: 0.8em;
    padding-top: 2px;
}
.tpl-spacer {
    background: repeating-linear-gradient(90deg, rgba(0,0,0,0.06) 0 4px, transparent 4px 8px);
}
.tpl-body-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    color: rgba(0, 0, 0, 0.45);
    font-family: Roboto, sans-serif;
    font-size: 12px;
}
</style>
