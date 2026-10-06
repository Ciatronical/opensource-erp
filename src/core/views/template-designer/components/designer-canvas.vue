<!-- src/core/views/template-designer/components/designer-canvas.vue -->
<!--
    Die Seite im Maßstab: Bausteine lassen sich mit der Maus verschieben und
    an acht Griffen in der Größe ändern, der Fließbereich an seinen Kanten.
    Beim Ziehen rasten Kanten an Rändern, Nachbarbausteinen und den
    DIN-5008-Marken ein (Hilfslinien erscheinen); Alt gedrückt = frei.

    Der Canvas ändert Position und Größe direkt im Design (die Objekte gehören
    der Ansicht) und meldet jeden abgeschlossenen Zug mit 'commit' — so landet
    ein Zug als ein Schritt im Verlauf. Alles andere (Inhalt, Eigenschaften)
    geschieht im Eigenschaften-Bereich.
-->
<template>
    <div class="tpl-canvas-wrap" @pointerdown="onBackgroundDown">
        <!-- Lineale -->
        <div class="tpl-ruler tpl-ruler--top" :style="{ width: px(PAGE_WIDTH), marginLeft: RULER + 'px' }">
            <span v-for="n in rulerTop" :key="n" class="tpl-ruler-label" :style="{ left: px(n) }">{{ n }}</span>
        </div>
        <div class="tpl-canvas-row">
            <div class="tpl-ruler tpl-ruler--left" :style="{ height: px(PAGE_HEIGHT) }">
                <span v-for="n in rulerLeft" :key="n" class="tpl-ruler-label" :style="{ top: px(n) }">{{ n }}</span>
            </div>

            <!-- Seite -->
            <div
                ref="pageEl"
                class="tpl-page"
                :class="{ 'tpl-page--following': pageView === 'following' }"
                :style="pageStyle"
                @dragover.prevent="onDragOver"
                @drop.prevent="onDrop"
            >
                <!-- Hintergrundbild -->
                <img v-if="backgroundUrl" :src="backgroundUrl" class="tpl-page-background" alt="">

                <!-- Rasterlinien -->
                <div v-if="showGrid" class="tpl-grid" :style="gridStyle" />

                <!-- DIN-5008-Overlay -->
                <template v-if="showDin">
                    <div class="tpl-din tpl-din-address" :style="rect(20, 45, 85, 45)">
                        <span>{{ t('TemplateDesigner.canvas.dinAddress') }}</span>
                    </div>
                    <div class="tpl-din tpl-din-return" :style="rect(20, 45, 85, 5)" />
                    <div class="tpl-din tpl-din-info" :style="rect(125, 50, 75, 40)">
                        <span>{{ t('TemplateDesigner.canvas.dinInfo') }}</span>
                    </div>
                    <div class="tpl-din-line" :style="{ top: px(105) }" />
                    <div class="tpl-din-line" :style="{ top: px(210) }" />
                    <div class="tpl-din-line tpl-din-line--punch" :style="{ top: px(148.5) }" />
                </template>

                <!-- Falz- und Lochmarken aus dem Design -->
                <template v-if="page.foldMarks">
                    <div v-for="y in foldMarkYs" :key="y" class="tpl-mark" :style="{ top: px(y), left: px(3), width: px(5) }" />
                </template>
                <div v-if="page.punchMark" class="tpl-mark" :style="{ top: px(148.5), left: px(3), width: px(8) }" />

                <!-- Hilfslinien beim Ziehen -->
                <div v-for="(g, i) in guides" :key="'g' + i" class="tpl-guide" :class="g.axis === 'x' ? 'tpl-guide--v' : 'tpl-guide--h'" :style="g.axis === 'x' ? { left: px(g.at) } : { top: px(g.at) }" />

                <!-- Fließbereich -->
                <div
                    class="tpl-body-area"
                    :class="{ 'tpl-body-area--selected': selectedId === 'body' }"
                    :style="bodyRect"
                    @pointerdown.stop="selectBody"
                >
                    <div class="tpl-body-edge tpl-body-edge--n" @pointerdown.stop="onBodyEdgeDown($event, 'n')" />
                    <div class="tpl-body-edge tpl-body-edge--s" @pointerdown.stop="onBodyEdgeDown($event, 's')" />
                    <div class="tpl-body-edge tpl-body-edge--w" @pointerdown.stop="onBodyEdgeDown($event, 'w')" />
                    <div class="tpl-body-edge tpl-body-edge--e" @pointerdown.stop="onBodyEdgeDown($event, 'e')" />
                    <div class="tpl-body-label">
                        {{ pageView === 'following' ? t('TemplateDesigner.canvas.bodyFollowing') : t('TemplateDesigner.canvas.bodyFirst') }}
                        · {{ Math.round(bodyTop) }}–{{ Math.round(PAGE_HEIGHT - page.bodyBottom) }} mm
                    </div>
                    <div class="tpl-body-inner">
                        <designer-body
                            :sections="design.body.sections"
                            :page="page"
                            :sample="sample"
                            :scale="scale"
                            :selected-id="selectedId"
                            @select="$emit('select', $event)"
                            @reorder="onReorder"
                            @detach="onDetach"
                        />
                    </div>
                </div>

                <!-- Bausteine -->
                <div
                    v-for="block in visibleBlocks"
                    :key="block.id"
                    class="tpl-block"
                    :class="{
                        'tpl-block--selected': block.id === selectedId,
                        'tpl-block--dimmed': block.pages !== 'all' && block.pages !== pageView,
                        'tpl-block--back': block.type === 'rect',
                    }"
                    :style="blockRect(block)"
                    @pointerdown.stop="onBlockDown($event, block)"
                    @dblclick.stop="$emit('edit', block.id)"
                >
                    <designer-block
                        :block="block"
                        :page="page"
                        :sample="sample"
                        :scale="scale"
                        :image-url="block.type === 'image' ? (imageUrls[block.props?.image] || '') : ''"
                    />
                    <div class="tpl-block-badge">
                        {{ t(`TemplateDesigner.blocks.${block.type}`) }}
                        <span v-if="block.pages !== 'all'"> · {{ t(`TemplateDesigner.pages.${block.pages}`) }}</span>
                    </div>
                    <template v-if="block.id === selectedId">
                        <div v-for="h in HANDLES" :key="h" class="tpl-handle" :class="'tpl-handle--' + h" @pointerdown.stop="onHandleDown($event, block, h)" />
                    </template>
                </div>

                <!-- Maßanzeige beim Ziehen -->
                <div v-if="dragInfo" class="tpl-drag-info" :style="{ left: px(dragInfo.x), top: px(dragInfo.y) }">{{ dragInfo.text }}</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DesignerBlock from './designer-block.vue';
import DesignerBody from './designer-body.vue';
import { PAGE_WIDTH, PAGE_HEIGHT } from '../designer.blocks.js';

const props = defineProps({
    design: { type: Object, required: true },
    sample: { type: Object, default: null },
    scale: { type: Number, default: 3 },
    selectedId: { type: String, default: null },
    pageView: { type: String, default: 'first' },      // 'first' | 'following'
    showGrid: { type: Boolean, default: true },
    showDin: { type: Boolean, default: false },
    gridStep: { type: Number, default: 1 },
    imageUrls: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['select', 'commit', 'edit', 'drop', 'reorder', 'detach']);

const { t } = useI18n();
const RULER = 22;
const HANDLES = ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'];

const pageEl = ref(null);
const guides = ref([]);
const dragInfo = ref(null);

const page = computed(() => props.design.page);
const px = (mm) => `${mm * props.scale}px`;
const rect = (x, y, w, h) => ({ left: px(x), top: px(y), width: px(w), height: px(h) });

const rulerTop = computed(() => Array.from({ length: Math.floor(PAGE_WIDTH / 10) + 1 }, (_, i) => i * 10));
const rulerLeft = computed(() => Array.from({ length: Math.floor(PAGE_HEIGHT / 10) + 1 }, (_, i) => i * 10));

const pageStyle = computed(() => ({
    width: px(PAGE_WIDTH),
    height: px(PAGE_HEIGHT),
}));

const gridStyle = computed(() => {
    const step = 5 * props.scale;
    return {
        backgroundSize: `${step}px ${step}px, ${step}px ${step}px, ${step * 2}px ${step * 2}px, ${step * 2}px ${step * 2}px`,
    };
});

const foldMarkYs = computed(() => page.value.foldMarks === 'A' ? [87, 192] : page.value.foldMarks === 'B' ? [105, 210] : []);

const bodyTop = computed(() => props.pageView === 'following' ? page.value.bodyTopFollowing : page.value.bodyTop);
const bodyRect = computed(() => rect(
    page.value.marginLeft,
    bodyTop.value,
    PAGE_WIDTH - page.value.marginLeft - page.value.marginRight,
    PAGE_HEIGHT - bodyTop.value - page.value.bodyBottom
));

const backgroundUrl = computed(() => {
    if (!page.value.background) return '';
    if (page.value.backgroundPages === 'first' && props.pageView !== 'first') return '';
    return props.imageUrls[page.value.background] || '';
});

/** Bausteine der angezeigten Seite: alle + die der gewählten Seitenart (die Auswahl bleibt immer sichtbar) */
const visibleBlocks = computed(() => {
    const blocks = (props.design.blocks || []).filter(b => b.pages === 'all' || !b.pages || b.pages === props.pageView || b.id === props.selectedId);
    // Flächen zuerst, damit sie hinter Text liegen
    return [...blocks].sort((a, b) => (a.type === 'rect' ? 0 : 1) - (b.type === 'rect' ? 0 : 1));
});

function blockRect(block) {
    return { ...rect(block.x, block.y, block.w, block.h), zIndex: block.id === props.selectedId ? 20 : (block.type === 'rect' ? 1 : 5) };
}

// ───────────────────────── Ziehen & Größe ─────────────────────────

let drag = null;

function clientToMm(e) {
    const r = pageEl.value.getBoundingClientRect();
    return { x: (e.clientX - r.left) / props.scale, y: (e.clientY - r.top) / props.scale };
}

function snapValue(v, step) {
    return Math.round(v / step) * step;
}

/** Einrastkandidaten: Seitenränder, Fließbereich, DIN-Marken, Kanten der anderen Bausteine */
function snapLines(exceptId) {
    const p = page.value;
    const xs = [0, PAGE_WIDTH, p.marginLeft, PAGE_WIDTH - p.marginRight, PAGE_WIDTH / 2, 20, 105, 125];
    const ys = [0, PAGE_HEIGHT, p.bodyTop, p.bodyTopFollowing, PAGE_HEIGHT - p.bodyBottom, 45, 50, 90, 105, 148.5, 210];
    for (const b of props.design.blocks || []) {
        if (b.id === exceptId) continue;
        xs.push(b.x, b.x + b.w, b.x + b.w / 2);
        ys.push(b.y, b.y + b.h, b.y + b.h / 2);
    }
    return { xs, ys };
}

function nearest(value, candidates, threshold) {
    let best = null;
    for (const c of candidates) {
        const d = Math.abs(c - value);
        if (d <= threshold && (best === null || d < best.d)) best = { c, d };
    }
    return best;
}

function onBlockDown(e, block) {
    if (e.button !== 0) return;
    emit('select', block.id);
    startDrag(e, { mode: 'move', block, sx: block.x, sy: block.y, sw: block.w, sh: block.h });
}

function onHandleDown(e, block, handle) {
    if (e.button !== 0) return;
    startDrag(e, { mode: 'resize', handle, block, sx: block.x, sy: block.y, sw: block.w, sh: block.h });
}

function onBodyEdgeDown(e, edge) {
    if (e.button !== 0) return;
    emit('select', 'body');
    const p = page.value;
    startDrag(e, { mode: 'body', edge, start: { ...p } });
}

function startDrag(e, state) {
    const origin = clientToMm(e);
    drag = { ...state, ox: origin.x, oy: origin.y, moved: false, target: e.currentTarget };
    e.currentTarget.setPointerCapture?.(e.pointerId);
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp, { once: true });
}

function onMove(e) {
    if (!drag) return;
    const pos = clientToMm(e);
    const dx = pos.x - drag.ox;
    const dy = pos.y - drag.oy;
    if (!drag.moved && Math.abs(dx) < 0.3 && Math.abs(dy) < 0.3) return;
    drag.moved = true;
    const free = e.altKey;
    const step = props.gridStep || 1;
    const threshold = 6 / props.scale; // 6 Bildschirmpixel
    guides.value = [];

    if (drag.mode === 'move') {
        const b = drag.block;
        let x = drag.sx + dx;
        let y = drag.sy + dy;
        if (!free) {
            x = snapValue(x, step);
            y = snapValue(y, step);
            const { xs, ys } = snapLines(b.id);
            const sxL = nearest(x, xs, threshold), sxR = nearest(x + b.w, xs, threshold), sxC = nearest(x + b.w / 2, xs, threshold);
            const best = [sxL && { ...sxL, off: 0 }, sxR && { ...sxR, off: b.w }, sxC && { ...sxC, off: b.w / 2 }].filter(Boolean).sort((a, c) => a.d - c.d)[0];
            if (best) { x = best.c - best.off; guides.value.push({ axis: 'x', at: best.c }); }
            const syT = nearest(y, ys, threshold), syB = nearest(y + b.h, ys, threshold), syC = nearest(y + b.h / 2, ys, threshold);
            const bestY = [syT && { ...syT, off: 0 }, syB && { ...syB, off: b.h }, syC && { ...syC, off: b.h / 2 }].filter(Boolean).sort((a, c) => a.d - c.d)[0];
            if (bestY) { y = bestY.c - bestY.off; guides.value.push({ axis: 'y', at: bestY.c }); }
        }
        b.x = round(Math.min(PAGE_WIDTH - b.w, Math.max(0, x)));
        b.y = round(Math.min(PAGE_HEIGHT - b.h, Math.max(0, y)));
        dragInfo.value = { x: b.x, y: b.y + b.h + 1, text: `${b.x} / ${b.y} mm` };
        return;
    }

    if (drag.mode === 'resize') {
        const b = drag.block;
        const h = drag.handle;
        let x = drag.sx, y = drag.sy, w = drag.sw, hh = drag.sh;
        if (h.includes('e')) w = drag.sw + dx;
        if (h.includes('s')) hh = drag.sh + dy;
        if (h.includes('w')) { x = drag.sx + dx; w = drag.sw - dx; }
        if (h.includes('n')) { y = drag.sy + dy; hh = drag.sh - dy; }
        if (!free) {
            const { xs, ys } = snapLines(b.id);
            if (h.includes('e')) { const s = nearest(x + w, xs, threshold); if (s) { w = s.c - x; guides.value.push({ axis: 'x', at: s.c }); } else w = snapValue(w, step); }
            if (h.includes('s')) { const s = nearest(y + hh, ys, threshold); if (s) { hh = s.c - y; guides.value.push({ axis: 'y', at: s.c }); } else hh = snapValue(hh, step); }
            if (h.includes('w')) { const s = nearest(x, xs, threshold); if (s) { w += x - s.c; x = s.c; guides.value.push({ axis: 'x', at: s.c }); } else { x = snapValue(x, step); w = drag.sx + drag.sw - x; } }
            if (h.includes('n')) { const s = nearest(y, ys, threshold); if (s) { hh += y - s.c; y = s.c; guides.value.push({ axis: 'y', at: s.c }); } else { y = snapValue(y, step); hh = drag.sy + drag.sh - y; } }
        }
        const minSize = 1;
        if (w < minSize) { if (h.includes('w')) x = drag.sx + drag.sw - minSize; w = minSize; }
        if (hh < minSize) { if (h.includes('n')) y = drag.sy + drag.sh - minSize; hh = minSize; }
        b.x = round(Math.max(0, x));
        b.y = round(Math.max(0, y));
        b.w = round(Math.min(PAGE_WIDTH - b.x, w));
        b.h = round(Math.min(PAGE_HEIGHT - b.y, hh));
        dragInfo.value = { x: b.x, y: b.y + b.h + 1, text: `${b.w} × ${b.h} mm` };
        return;
    }

    if (drag.mode === 'body') {
        const p = page.value;
        const s = drag.start;
        const snap = (v) => free ? v : snapValue(v, step);
        if (drag.edge === 'n') {
            const v = round(Math.max(10, Math.min(PAGE_HEIGHT - p.bodyBottom - 30, snap((props.pageView === 'following' ? s.bodyTopFollowing : s.bodyTop) + dy))));
            if (props.pageView === 'following') p.bodyTopFollowing = Math.min(v, p.bodyTop);
            else { p.bodyTop = v; if (p.bodyTopFollowing > v) p.bodyTopFollowing = v; }
            dragInfo.value = { x: p.marginLeft, y: bodyTop.value - 6, text: `${t('TemplateDesigner.canvas.bodyTop')} ${bodyTop.value} mm` };
        } else if (drag.edge === 's') {
            p.bodyBottom = round(Math.max(5, Math.min(PAGE_HEIGHT - bodyTop.value - 30, snap(s.bodyBottom - dy))));
            dragInfo.value = { x: p.marginLeft, y: PAGE_HEIGHT - p.bodyBottom + 1, text: `${t('TemplateDesigner.canvas.bodyBottom')} ${p.bodyBottom} mm` };
        } else if (drag.edge === 'w') {
            p.marginLeft = round(Math.max(5, Math.min(PAGE_WIDTH - p.marginRight - 60, snap(s.marginLeft + dx))));
            dragInfo.value = { x: p.marginLeft + 1, y: bodyTop.value + 2, text: `${p.marginLeft} mm` };
        } else if (drag.edge === 'e') {
            p.marginRight = round(Math.max(5, Math.min(PAGE_WIDTH - p.marginLeft - 60, snap(s.marginRight - dx))));
            dragInfo.value = { x: PAGE_WIDTH - p.marginRight - 20, y: bodyTop.value + 2, text: `${p.marginRight} mm` };
        }
    }
}

function onUp() {
    window.removeEventListener('pointermove', onMove);
    const moved = drag?.moved;
    drag = null;
    guides.value = [];
    dragInfo.value = null;
    if (moved) emit('commit');
}

function round(v) {
    return Math.round(v * 2) / 2;
}

function onBackgroundDown(e) {
    // Klick auf die Fläche neben/auf der Seite: Auswahl aufheben
    if (e.target === pageEl.value || e.target.classList.contains('tpl-canvas-wrap') || e.target.classList.contains('tpl-canvas-row') || e.target.classList.contains('tpl-grid')) {
        emit('select', null);
    }
}

function selectBody(e) {
    // Abschnitte melden sich selbst; das Ereignis läuft (für Sortable) weiter
    if (e?.target?.closest?.('.tpl-section')) return;
    emit('select', 'body');
}

function onReorder(sections) {
    emit('reorder', sections);
}

/** Abschnitt außerhalb des Fließbereichs abgelegt: Position in mm weitergeben */
function onDetach({ id, clientX, clientY }) {
    const pos = clientToMm({ clientX, clientY });
    emit('detach', { id, x: round(Math.max(0, Math.min(PAGE_WIDTH - 10, pos.x))), y: round(Math.max(0, Math.min(PAGE_HEIGHT - 10, pos.y))) });
}

// ───────────────────────── Ablegen aus der Palette ─────────────────────────

function onDragOver(e) {
    e.dataTransfer.dropEffect = 'copy';
}

function onDrop(e) {
    const raw = e.dataTransfer.getData('application/x-oserp-designer');
    if (!raw) return;
    let payload;
    try { payload = JSON.parse(raw); } catch { return; }
    const pos = clientToMm(e);
    emit('drop', payload, { x: round(Math.max(0, pos.x)), y: round(Math.max(0, pos.y)) });
}
</script>

<style scoped>
.tpl-canvas-wrap {
    display: inline-block;
    padding: 8px 32px 32px 8px;
    user-select: none;
}
.tpl-canvas-row {
    display: flex;
    align-items: flex-start;
}
.tpl-ruler {
    position: relative;
    background: #f0f2f5;
    color: #6b7280;
    font-size: 9px;
    font-family: Roboto, sans-serif;
    box-sizing: border-box;
}
.tpl-ruler--top {
    height: 22px;
    border-bottom: 1px solid #cfd4da;
    background-image: linear-gradient(90deg, #b8bec6 1px, transparent 1px);
    background-size: v-bind('px(10)') 100%;
}
.tpl-ruler--left {
    width: 22px;
    border-right: 1px solid #cfd4da;
    background-image: linear-gradient(180deg, #b8bec6 1px, transparent 1px);
    background-size: 100% v-bind('px(10)');
}
.tpl-ruler--top .tpl-ruler-label {
    position: absolute;
    top: 2px;
    transform: translateX(2px);
}
.tpl-ruler--left .tpl-ruler-label {
    position: absolute;
    left: 2px;
    transform: translateY(1px);
}
.tpl-page {
    position: relative;
    background: #fff;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18), 0 0 0 1px rgba(0, 0, 0, 0.06);
    overflow: hidden;
}
.tpl-page--following {
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.18), 0 0 0 1px rgba(0, 0, 0, 0.06), 6px 6px 0 -1px #fff, 6px 6px 0 0 rgba(0, 0, 0, 0.12);
}
.tpl-page-background {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: fill;
    pointer-events: none;
}
.tpl-grid {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background-image:
        linear-gradient(90deg, rgba(0, 0, 0, 0.05) 1px, transparent 1px),
        linear-gradient(180deg, rgba(0, 0, 0, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 0, 0, 0.1) 1px, transparent 1px),
        linear-gradient(180deg, rgba(0, 0, 0, 0.1) 1px, transparent 1px);
}
.tpl-din {
    position: absolute;
    border: 1px dashed rgba(156, 39, 176, 0.6);
    background: rgba(156, 39, 176, 0.04);
    color: rgba(156, 39, 176, 0.8);
    font-size: 9px;
    font-family: Roboto, sans-serif;
    padding: 2px 4px;
    box-sizing: border-box;
    pointer-events: none;
    display: flex;
    align-items: flex-end;
}
.tpl-din-return {
    border-style: dotted;
    background: transparent;
}
.tpl-din-line {
    position: absolute;
    left: 0;
    right: 0;
    border-top: 1px dashed rgba(156, 39, 176, 0.45);
    pointer-events: none;
}
.tpl-din-line--punch {
    border-top-style: dotted;
}
.tpl-mark {
    position: absolute;
    height: 1px;
    background: #888;
    pointer-events: none;
}
.tpl-guide {
    position: absolute;
    pointer-events: none;
    z-index: 30;
}
.tpl-guide--v {
    top: 0;
    bottom: 0;
    border-left: 1px solid #e91e63;
}
.tpl-guide--h {
    left: 0;
    right: 0;
    border-top: 1px solid #e91e63;
}
.tpl-body-area {
    position: absolute;
    border: 1px dashed rgba(30, 136, 229, 0.45);
    box-sizing: border-box;
    z-index: 2;
}
.tpl-body-area--selected {
    border-color: #1e88e5;
    border-style: solid;
}
.tpl-body-inner {
    position: absolute;
    inset: 0;
    overflow: hidden;
    padding-left: 0;
}
.tpl-body-label {
    position: absolute;
    top: -14px;
    left: -1px;
    font-size: 9px;
    line-height: 12px;
    padding: 0 4px;
    background: rgba(30, 136, 229, 0.12);
    color: #1565c0;
    border-radius: 2px 2px 0 0;
    font-family: Roboto, sans-serif;
    white-space: nowrap;
    pointer-events: none;
}
.tpl-body-edge {
    position: absolute;
    z-index: 25;
}
.tpl-body-edge--n { top: -4px; left: 0; right: 0; height: 8px; cursor: ns-resize; }
.tpl-body-edge--s { bottom: -4px; left: 0; right: 0; height: 8px; cursor: ns-resize; }
.tpl-body-edge--w { left: -4px; top: 0; bottom: 0; width: 8px; cursor: ew-resize; }
.tpl-body-edge--e { right: -4px; top: 0; bottom: 0; width: 8px; cursor: ew-resize; }
.tpl-body-edge:hover { background: rgba(30, 136, 229, 0.25); }

.tpl-block {
    position: absolute;
    box-sizing: border-box;
    cursor: move;
    outline: 1px solid transparent;
    transition: outline-color 0.1s;
}
.tpl-block:hover {
    outline-color: rgba(30, 136, 229, 0.5);
}
.tpl-block--selected {
    outline: 1.5px solid #1e88e5;
}
.tpl-block--dimmed {
    opacity: 0.35;
}
.tpl-block--back {
    cursor: move;
}
.tpl-block-badge {
    position: absolute;
    top: -15px;
    left: -1px;
    font-size: 9px;
    line-height: 13px;
    padding: 0 5px;
    background: #1e88e5;
    color: #fff;
    border-radius: 2px 2px 0 0;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    font-family: Roboto, sans-serif;
    z-index: 3;
}
.tpl-block:hover .tpl-block-badge,
.tpl-block--selected .tpl-block-badge {
    opacity: 1;
}
.tpl-handle {
    position: absolute;
    width: 8px;
    height: 8px;
    background: #fff;
    border: 1.5px solid #1e88e5;
    border-radius: 2px;
    z-index: 4;
}
.tpl-handle--n  { top: -5px; left: calc(50% - 4px); cursor: ns-resize; }
.tpl-handle--s  { bottom: -5px; left: calc(50% - 4px); cursor: ns-resize; }
.tpl-handle--e  { right: -5px; top: calc(50% - 4px); cursor: ew-resize; }
.tpl-handle--w  { left: -5px; top: calc(50% - 4px); cursor: ew-resize; }
.tpl-handle--ne { top: -5px; right: -5px; cursor: nesw-resize; }
.tpl-handle--nw { top: -5px; left: -5px; cursor: nwse-resize; }
.tpl-handle--se { bottom: -5px; right: -5px; cursor: nwse-resize; }
.tpl-handle--sw { bottom: -5px; left: -5px; cursor: nesw-resize; }
.tpl-drag-info {
    position: absolute;
    z-index: 40;
    font-size: 10px;
    font-family: Roboto, sans-serif;
    background: rgba(33, 33, 33, 0.85);
    color: #fff;
    padding: 1px 5px;
    border-radius: 3px;
    pointer-events: none;
    white-space: nowrap;
}
</style>
