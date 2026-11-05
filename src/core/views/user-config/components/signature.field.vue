<!-- src/core/views/user-config/components/signature.field.vue -->
<!--
    Eigene Unterschrift für Briefe: hochladen (Datei, Strg+V oder
    Hineinziehen) oder mit Maus, Finger bzw. Stift zeichnen. Ein Foto oder
    Scan vom Blatt wird freigestellt (heller Hintergrund → durchsichtig) und
    auf die Schrift zugeschnitten. Gespeichert wird sofort; die Vorschau zeigt
    die Unterschrift so, wie sie im Brief über der Linie steht.
-->
<template>
    <div id="signature">
        <div
            class="signature-field__paper"
            :class="{ 'signature-field__paper--drag': dragOver }"
            @dragover.prevent="dragOver = true"
            @dragleave="dragOver = false"
            @drop.prevent="onDrop"
        >
            <div class="signature-field__sheet">
                <div class="signature-field__ink">
                    <v-progress-circular v-if="busy" indeterminate size="28" color="primary" />
                    <img v-else-if="image" :src="image" :alt="t('UserSignature.title')" class="signature-field__img">
                    <span v-else class="signature-field__empty">{{ t('UserSignature.empty') }}</span>
                </div>
                <div class="signature-field__line" />
                <div class="signature-field__name">{{ employeeName }}</div>
            </div>
        </div>

        <div class="d-flex flex-wrap ga-2 mt-3">
            <v-btn variant="tonal" color="primary" prepend-icon="mdi-upload" :disabled="busy" @click="fileInput?.click()">
                {{ image ? t('UserSignature.replace') : t('UserSignature.upload') }}
            </v-btn>
            <v-btn variant="tonal" prepend-icon="mdi-draw" :disabled="busy" @click="openPad">
                {{ t('UserSignature.draw') }}
            </v-btn>
            <v-btn v-if="image" variant="text" color="error" prepend-icon="mdi-delete-outline" :disabled="busy" @click="remove">
                {{ t('UserSignature.remove') }}
            </v-btn>
            <input ref="fileInput" type="file" accept="image/*" hidden @change="onFile">
        </div>
        <div class="text-caption text-medium-emphasis mt-2">
            <v-icon size="14" class="me-1">mdi-information-outline</v-icon>{{ t('UserSignature.hint') }}
        </div>

        <!-- Unterschrift zeichnen -->
        <v-dialog v-model="pad.show" max-width="680">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon class="me-2">mdi-draw</v-icon>{{ t('UserSignature.drawTitle') }}
                    <v-spacer />
                    <v-btn-toggle v-model="pad.color" mandatory density="compact" variant="outlined" divided>
                        <v-btn v-for="c in inkColors" :key="c.value" :value="c.value" size="small" :title="t(c.label)">
                            <v-icon :color="c.value">mdi-circle</v-icon>
                        </v-btn>
                    </v-btn-toggle>
                </v-card-title>
                <v-card-text>
                    <canvas
                        ref="canvas"
                        class="signature-field__canvas"
                        width="1200"
                        height="400"
                        @pointerdown="padStart"
                        @pointermove="padMove"
                        @pointerup="padEnd"
                        @pointercancel="padEnd"
                        @pointerleave="padEnd"
                    />
                    <div class="text-caption text-medium-emphasis mt-1">{{ t('UserSignature.drawHint') }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-btn variant="text" prepend-icon="mdi-eraser" :disabled="!pad.dirty" @click="padClear">{{ t('UserSignature.clear') }}</v-btn>
                    <v-spacer />
                    <v-btn variant="text" @click="pad.show = false">{{ t('UserSignature.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :disabled="!pad.dirty" @click="padApply">{{ t('UserSignature.apply') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { prepareSignature } from '@/core/utils/imageTools.js'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const store = oserpStore()

const image = ref(null)
const busy = ref(false)
const dragOver = ref(false)
const fileInput = ref(null)
const employeeName = computed(() => store.session?.logged_in_employee?.name || '')

async function load() {
    busy.value = true
    try {
        const { data } = await axios.post('/api/oserp_config/', { action: 'getEmployeeSignature' })
        image.value = data?.payload?.results?.image || null
    } finally {
        busy.value = false
    }
}

/** Bild aufbereiten und sofort speichern */
async function saveSignature(source) {
    busy.value = true
    try {
        let png
        try {
            png = await prepareSignature(source)
        } catch {
            toast.error(t('UserSignature.unreadable'))
            return
        }
        const { data } = await axios.post('/api/oserp_config/', { action: 'saveEmployeeSignature', image: png })
        if (!data.success) throw new Error(data.text)
        image.value = png
        toast.success(t('UserSignature.saved'))
    } catch (e) {
        alerts.error(e.message, t('UserSignature.title'))
    } finally {
        busy.value = false
    }
}

function onFile(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (file) saveSignature(file)
}

function onDrop(event) {
    dragOver.value = false
    const file = Array.from(event.dataTransfer?.files || []).find(f => f.type.startsWith('image/'))
    if (file) saveSignature(file)
}

/** Strg+V mit einem Bild in der Zwischenablage (Screenshot, kopiertes Foto) */
function onPaste(event) {
    const file = Array.from(event.clipboardData?.files || []).find(f => f.type.startsWith('image/'))
    if (!file) return
    event.preventDefault()
    saveSignature(file)
}

async function remove() {
    const ok = await alerts.warning(t('UserSignature.confirmRemove'), t('UserSignature.remove'))
    if (!ok.isConfirmed) return
    busy.value = true
    try {
        await axios.post('/api/oserp_config/', { action: 'deleteEmployeeSignature' })
        image.value = null
    } finally {
        busy.value = false
    }
}

// ------------------------------------------------------------------
// Zeichenfeld: Maus, Finger oder Stift (Pointer Events), geglättete Linie,
// Strichstärke folgt dem Stiftdruck, wo das Gerät ihn meldet
// ------------------------------------------------------------------
const inkColors = [
    { value: '#1a237e', label: 'UserSignature.inkBlue' },
    { value: '#111111', label: 'UserSignature.inkBlack' },
]
const canvas = ref(null)
const pad = ref({ show: false, color: inkColors[0].value, dirty: false })
let drawing = false
let last = null

function openPad() {
    pad.value.show = true
    pad.value.dirty = false
    nextTick(() => padClear())
}

function point(e) {
    const r = canvas.value.getBoundingClientRect()
    return {
        x: (e.clientX - r.left) * (canvas.value.width / r.width),
        y: (e.clientY - r.top) * (canvas.value.height / r.height),
        p: e.pressure && e.pointerType === 'pen' ? e.pressure : 0.5,
    }
}

function padStart(e) {
    drawing = true
    canvas.value.setPointerCapture(e.pointerId)
    last = point(e)
    const ctx = canvas.value.getContext('2d')
    ctx.fillStyle = pad.value.color
    ctx.beginPath()
    ctx.arc(last.x, last.y, 2.5, 0, Math.PI * 2)
    ctx.fill()
    pad.value.dirty = true
}

function padMove(e) {
    if (!drawing) return
    const p = point(e)
    const ctx = canvas.value.getContext('2d')
    ctx.strokeStyle = pad.value.color
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.lineWidth = 3 + p.p * 4
    const mid = { x: (last.x + p.x) / 2, y: (last.y + p.y) / 2 }
    ctx.beginPath()
    ctx.moveTo(last.mx ?? last.x, last.my ?? last.y)
    ctx.quadraticCurveTo(last.x, last.y, mid.x, mid.y)
    ctx.stroke()
    last = { ...p, mx: mid.x, my: mid.y }
}

function padEnd() {
    drawing = false
    last = null
}

function padClear() {
    const c = canvas.value
    if (!c) return
    c.getContext('2d').clearRect(0, 0, c.width, c.height)
    pad.value.dirty = false
}

async function padApply() {
    const png = canvas.value.toDataURL('image/png')
    pad.value.show = false
    await saveSignature(png)
}

onMounted(() => {
    load()
    window.addEventListener('paste', onPaste)
})
onBeforeUnmount(() => window.removeEventListener('paste', onPaste))
</script>

<style scoped>
/* Ein Stück Papier: auch im Dunkelmodus weiß, wie später im Brief */
.signature-field__paper {
    background: var(--oserp-paper);
    color: var(--oserp-paper-ink);
    border: 1px dashed var(--oserp-border);
    border-radius: var(--oserp-radius-sm);
    padding: var(--oserp-gap-xl) var(--oserp-gap-xl) var(--oserp-gap-lg);
    transition: border-color 0.15s;
}
.signature-field__paper--drag {
    border-color: rgb(var(--v-theme-primary));
    border-style: solid;
}
.signature-field__sheet {
    width: min(100%, 300px);
}
.signature-field__ink {
    height: 80px;
    display: flex;
    align-items: flex-end;
    /* wie im Druck: die Unterschrift ragt etwas über die Linie */
    margin-bottom: -10px;
    position: relative;
}
.signature-field__img {
    max-height: 80px;
    max-width: 100%;
    object-fit: contain;
    object-position: left bottom;
}
.signature-field__empty {
    font-size: 0.8rem;
    opacity: 0.5;
    margin-bottom: 16px;
}
.signature-field__line {
    border-top: 1px solid var(--oserp-paper-ink);
}
.signature-field__name {
    font-size: 0.85rem;
    margin-top: var(--oserp-gap-xs);
}
.signature-field__canvas {
    width: 100%;
    aspect-ratio: 3 / 1;
    background: var(--oserp-paper);
    border: 1px solid var(--oserp-border);
    border-radius: var(--oserp-radius-sm);
    touch-action: none;
    cursor: crosshair;
    display: block;
}
</style>
