<!-- src/core/views/template-designer/components/designer-block.vue -->
<!--
    Darstellung eines frei platzierten Bausteins auf der Seite — mit
    Beispielwerten statt Platzhaltern, in der Schriftgröße und Ausrichtung,
    die später auch im PDF gelten. Keine Interaktion: Ziehen, Auswahl und
    Griffe liegen im Canvas.
-->
<template>
    <div class="tpl-block-content" :style="contentStyle">
        <!-- Text / Seitenzahl -->
        <div v-if="block.type === 'text' || block.type === 'pagenumber'" class="tpl-text" :style="textStyle" v-html="html" />

        <!-- Infobox -->
        <table v-else-if="block.type === 'infobox'" class="tpl-infobox" :style="textStyle">
            <tr v-for="(row, i) in visibleRows" :key="i">
                <td class="tpl-infobox-label" :style="{ width: mm(props.labelWidth || 32), fontWeight: props.labelBold ? 700 : 400 }" v-html="markupToHtml(renderSample(row.label, sample))" />
                <td class="tpl-infobox-value" :style="{ textAlign: props.valueAlign === 'left' ? 'left' : 'right' }" v-html="markupToHtml(renderSample(row.value, sample))" />
            </tr>
        </table>

        <!-- Bild -->
        <div v-else-if="block.type === 'image'" class="tpl-image" :style="{ justifyContent: imageJustify }">
            <img v-if="imageUrl" :src="imageUrl" class="tpl-image-img" :alt="props.image">
            <div v-else class="tpl-image-empty">
                <v-icon size="small">mdi-image-outline</v-icon>
                <span>{{ props.image || t('TemplateDesigner.canvas.noImage') }}</span>
            </div>
        </div>

        <!-- Linie -->
        <div v-else-if="block.type === 'line'" class="tpl-line" :style="lineStyle" />

        <!-- Fläche -->
        <div v-else-if="block.type === 'rect'" class="tpl-rect" :style="rectStyle" />

        <!-- GiroCode -->
        <div v-else-if="block.type === 'qrcode'" class="tpl-qr">
            <v-icon :size="qrIconSize">mdi-qrcode</v-icon>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { renderSample, markupToHtml, PT_TO_MM } from '../designer.blocks.js';

const props_ = defineProps({
    block: { type: Object, required: true },
    page: { type: Object, required: true },
    sample: { type: Object, default: null },
    scale: { type: Number, required: true },
    imageUrl: { type: String, default: '' },
});

const { t } = useI18n();

const block = computed(() => props_.block);
const props = computed(() => props_.block.props || {});

const mm = (v) => `${(Number(v) || 0) * props_.scale}px`;
const pt = (v) => `${(Number(v) || props_.page.fontSize || 10) * PT_TO_MM * props_.scale}px`;

const contentStyle = computed(() => ({
    color: props.value.color || props_.page.textColor || '#222',
}));

const textStyle = computed(() => {
    const size = props.value.fontSize || props_.page.fontSize || 10;
    const style = {
        fontSize: pt(size),
        lineHeight: String(props.value.lineHeight || 1.25),
        textAlign: props.value.align || 'left',
        fontWeight: props.value.bold ? 700 : 400,
        fontStyle: props.value.italic ? 'italic' : 'normal',
        fontFamily: props_.page.font === 'serif' ? 'Georgia, "Times New Roman", serif' : 'Helvetica, Arial, sans-serif',
    };
    if (props.value.underline) {
        style.borderBottom = `1px solid ${props_.page.accentColor || '#1F4E79'}`;
        style.paddingBottom = '1px';
    }
    if (props.value.fill) {
        style.background = props.value.fill;
        style.padding = `${props_.scale}px`;
    }
    return style;
});

const html = computed(() => markupToHtml(renderSample(props.value.text || '', props_.sample)));

const visibleRows = computed(() => (props.value.rows || []).filter(r => {
    if (!r.hideEmpty && !r.condition) return true;
    const key = r.condition || (String(r.value || '').match(/<%\s*([A-Za-z0-9_.]+)/) || [])[1];
    if (!key || !props_.sample) return true;
    const v = props_.sample.variables?.[key];
    return v !== undefined && v !== null && v !== '';
}));

const imageJustify = computed(() => ({ right: 'flex-end', center: 'center' }[props.value.align] || 'flex-start'));

const lineStyle = computed(() => {
    const vertical = block.value.h > block.value.w;
    const thickness = Math.max(1, (props.value.thickness || 0.4) * PT_TO_MM * props_.scale);
    return {
        background: props.value.color || props_.page.accentColor || '#1F4E79',
        width: vertical ? `${thickness}px` : '100%',
        height: vertical ? '100%' : `${thickness}px`,
    };
});

const rectStyle = computed(() => ({
    background: props.value.fill || 'transparent',
    border: props.value.borderColor ? `${Math.max(1, (props.value.borderWidth || 0.4) * PT_TO_MM * props_.scale)}px solid ${props.value.borderColor}` : 'none',
}));

const qrIconSize = computed(() => Math.min(block.value.w, block.value.h) * props_.scale * 0.9);
</script>

<style scoped>
.tpl-block-content {
    width: 100%;
    height: 100%;
    overflow: hidden;
}
.tpl-text {
    white-space: normal;
    word-break: break-word;
}
.tpl-infobox {
    border-collapse: collapse;
    width: 100%;
}
.tpl-infobox td {
    padding: 0;
    vertical-align: top;
    line-height: 1.3;
}
.tpl-infobox-value {
    padding-left: 4px !important;
}
.tpl-image {
    display: flex;
    align-items: flex-start;
    width: 100%;
    height: 100%;
}
.tpl-image-img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.tpl-image-empty {
    display: flex;
    align-items: center;
    gap: 4px;
    width: 100%;
    height: 100%;
    justify-content: center;
    font-size: 10px;
    color: rgba(0, 0, 0, 0.45);
    background: repeating-linear-gradient(45deg, rgba(0,0,0,0.03) 0 6px, transparent 6px 12px);
    border: 1px dashed rgba(0, 0, 0, 0.2);
}
.tpl-line {
    position: absolute;
    top: 0;
    left: 0;
}
.tpl-rect {
    width: 100%;
    height: 100%;
    box-sizing: border-box;
}
.tpl-qr {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: #333;
    background: #fff;
}
:deep(.tpl-missing) {
    color: #b26a00;
    background: rgba(255, 193, 7, 0.18);
    border-radius: 2px;
}
</style>
