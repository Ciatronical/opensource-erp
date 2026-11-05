<!-- src/core/components/cv-image-picker.dialog.vue -->
<!--
    Bilder aus der Dateiablage eines Kunden oder Lieferanten auswählen (dieselbe
    Ablage wie der Reiter „Dateien“ in der Kundenmaske). Ordner per Klick
    öffnen, Bilder per Klick markieren (mehrere möglich), Doppelklick fügt ein
    einzelnes Bild sofort ein. Liefert die gewählten Bilder als File-Objekte.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="960" scrollable @update:model-value="emit('update:modelValue', $event)">
        <v-card>
            <v-card-title class="d-flex align-center flex-wrap ga-1">
                <v-icon class="me-2">mdi-folder-image</v-icon>
                <template v-for="(crumb, i) in crumbs" :key="crumb.path">
                    <v-icon v-if="i" size="18" class="text-medium-emphasis">mdi-chevron-right</v-icon>
                    <span v-if="i === crumbs.length - 1" class="px-1">{{ crumb.name }}</span>
                    <v-btn v-else variant="text" color="primary" class="px-1 text-none" @click="open(crumb.path)">{{ crumb.name }}</v-btn>
                </template>
            </v-card-title>
            <v-divider />

            <v-card-text class="cv-image-picker__body">
                <div v-if="loading" class="d-flex justify-center pa-8">
                    <v-progress-circular indeterminate color="primary" />
                </div>
                <template v-else>
                    <div v-if="folders.length" class="cv-image-picker__folders">
                        <v-btn
                            v-for="f in folders"
                            :key="f.path"
                            variant="tonal"
                            prepend-icon="mdi-folder"
                            class="text-none justify-start"
                            @click="open(f.path)"
                        >
                            {{ f.basename }}
                        </v-btn>
                    </div>

                    <div v-if="pictures.length" class="cv-image-picker__grid">
                        <button
                            v-for="p in pictures"
                            :key="p.path"
                            type="button"
                            class="cv-image-picker__tile"
                            :class="{ 'cv-image-picker__tile--selected': selected.includes(p.path) }"
                            :aria-pressed="selected.includes(p.path)"
                            :title="p.basename"
                            @click="toggle(p.path)"
                            @dblclick="insert([p.path])"
                        >
                            <img :src="previewUrl(p.path)" :alt="p.basename" loading="lazy">
                            <!-- Nummer = Reihenfolge im Brief -->
                            <span class="cv-image-picker__check">{{ selected.indexOf(p.path) + 1 || '' }}</span>
                            <span class="cv-image-picker__name">{{ p.basename }}</span>
                        </button>
                    </div>

                    <div v-else class="text-medium-emphasis text-center pa-8">
                        <v-icon size="40" class="mb-2">mdi-image-off-outline</v-icon>
                        <div>{{ t('HtmlEditor.folderEmpty') }}</div>
                    </div>
                </template>
            </v-card-text>

            <v-divider />
            <v-card-actions>
                <span class="text-caption text-medium-emphasis ms-2">{{ t('HtmlEditor.folderHint') }}</span>
                <v-spacer />
                <v-btn variant="text" @click="emit('update:modelValue', false)">{{ t('HtmlEditor.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" :disabled="!selected.length" :loading="fetching" @click="insert(selected)">
                    {{ t('HtmlEditor.folderInsert', { n: selected.length }) }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** { cv_id, src } — src 'C' Kunde, 'V' Lieferant */
    folder: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue', 'select'])
const { t } = useI18n()

const API_URL = '/api/customer_vendor/'
// Was der Browser lesen kann (HEIC vom iPhone z. B. nicht)
const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp']

const path = ref('')
const entries = ref([])
const selected = ref([])
const loading = ref(false)
const fetching = ref(false)

const rootLabel = computed(() => t(props.folder.src === 'V' ? 'HtmlEditor.folderVendor' : 'HtmlEditor.folderCustomer'))
const folders = computed(() => entries.value.filter(e => e.type === 'dir'))
const pictures = computed(() => entries.value.filter(e => e.type === 'file' && IMAGE_EXTS.includes(e.extension)))
const crumbs = computed(() => {
    const parts = path.value ? path.value.split('/') : []
    return [{ name: rootLabel.value, path: '' }, ...parts.map((name, i) => ({ name, path: parts.slice(0, i + 1).join('/') }))]
})

/** Pfad der Ablage ohne Speicher-Präfix (local://…) */
function plain(p) {
    return String(p || '').replace(/^[a-z]+:\/\//i, '')
}

async function open(dir) {
    loading.value = true
    selected.value = []
    try {
        const { data } = await axios.post(API_URL, { action: 'vfIndex', cv_id: props.folder.cv_id, src: props.folder.src, path: dir })
        if (!data.success) throw new Error(data.text)
        path.value = plain(data.payload.dirname)
        entries.value = (data.payload.files || []).map(e => ({ ...e, path: plain(e.path) }))
    } catch (e) {
        toast.error(e.message)
        entries.value = []
    } finally {
        loading.value = false
    }
}

function previewUrl(p) {
    const params = new URLSearchParams({ action: 'vfPreview', cv_id: props.folder.cv_id, src: props.folder.src, path: p })
    return `${API_URL}?${params}`
}

function toggle(p) {
    const i = selected.value.indexOf(p)
    if (i >= 0) selected.value.splice(i, 1)
    else selected.value.push(p)
}

/** Gewählte Bilder laden und in Klickreihenfolge übergeben */
async function insert(paths) {
    fetching.value = true
    try {
        const files = []
        for (const p of paths) {
            const response = await fetch(previewUrl(p))
            if (!response.ok) {
                toast.error(t('HtmlEditor.imageError', { name: p.split('/').pop() }))
                continue
            }
            const blob = await response.blob()
            files.push(new File([blob], p.split('/').pop(), { type: blob.type }))
        }
        if (files.length) emit('select', files)
        emit('update:modelValue', false)
    } finally {
        fetching.value = false
    }
}

// Beim Öffnen im zuletzt benutzten Ordner weitermachen; anderer Kunde → oben
let lastFolder = null
watch(() => props.modelValue, (show) => {
    if (!show) return
    const key = `${props.folder.src}:${props.folder.cv_id}`
    open(key === lastFolder ? path.value : '')
    lastFolder = key
})
</script>

<style scoped>
.cv-image-picker__body {
    min-height: 360px;
}
.cv-image-picker__folders {
    display: flex;
    flex-wrap: wrap;
    gap: var(--oserp-gap-sm);
    margin-bottom: var(--oserp-gap-lg);
}
.cv-image-picker__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: var(--oserp-gap);
}
.cv-image-picker__tile {
    position: relative;
    padding: 0;
    border: 0;
    aspect-ratio: 1;
    border-radius: var(--oserp-radius-sm);
    overflow: hidden;
    background: var(--oserp-surface-soft);
    outline: 2px solid transparent;
    transition: outline-color 0.12s;
}
.cv-image-picker__tile:hover {
    outline-color: var(--oserp-border);
}
.cv-image-picker__tile--selected,
.cv-image-picker__tile--selected:hover {
    outline: 3px solid rgb(var(--v-theme-primary));
}
.cv-image-picker__tile img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.cv-image-picker__check {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid #fff;
    background: rgba(0, 0, 0, 0.25);
    color: #fff;
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 20px;
    text-align: center;
    box-shadow: 0 0 3px rgba(0, 0, 0, 0.5);
}
.cv-image-picker__tile--selected .cv-image-picker__check {
    background: rgb(var(--v-theme-primary));
}
.cv-image-picker__name {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 2px 6px;
    font-size: 0.72rem;
    color: #fff;
    background: rgba(0, 0, 0, 0.45);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-align: left;
}
</style>
