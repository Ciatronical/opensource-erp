<!-- src/features/lxcars/components/order-images.section.card.vue -->
<!--
    Bilder am Auftrag (docs/features/lxcars.md → Bilder am Auftrag)

    Zwei Wege: „Foto aufnehmen“ öffnet auf Android und iPhone direkt die
    Kamera (capture="environment"), „Bilder wählen“ die Galerie bzw. den
    Dateidialog. Jedes Bild wird im Browser verkleinert und sofort hochgeladen;
    der Server legt es im Dokumentenordner des Kunden ab. Im Betrachter lassen
    sich Bildunterschrift und – bei eingeschaltetem Kundenportal – die
    Freigabe für den Kunden pflegen.
-->
<template>
    <section-card icon="mdi-image-multiple-outline">
        <template #title>
            {{ t('OrderImages.title') }}
            <v-chip v-if="images.length" size="x-small" variant="tonal" color="primary" class="ml-2">
                {{ images.length }}
            </v-chip>
        </template>
        <template #actions>
            <v-btn
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="mdi-camera"
                :disabled="uploading"
                @click="cameraInput?.click()"
            >
                {{ t('OrderImages.takePhoto') }}
            </v-btn>
            <v-btn
                size="small"
                variant="tonal"
                prepend-icon="mdi-image-multiple"
                :disabled="uploading"
                @click="fileInput?.click()"
            >
                {{ t('OrderImages.choosePhotos') }}
            </v-btn>
        </template>

        <input ref="cameraInput" type="file" accept="image/*" capture="environment" hidden @change="onFilesPicked">
        <input ref="fileInput" type="file" accept="image/*" multiple hidden @change="onFilesPicked">

        <div v-if="loading" class="text-center py-4">
            <v-progress-circular indeterminate size="24" />
        </div>
        <template v-else>
            <p v-if="!images.length && !uploading" class="order-images__empty">
                {{ t('OrderImages.empty') }}
            </p>
            <div v-if="images.length || uploading" class="order-images__grid">
                <div
                    v-for="(img, idx) in images"
                    :key="img.id"
                    class="order-images__tile"
                    :title="img.caption || ''"
                    @click="openViewer(idx)"
                >
                    <v-img :src="carsStore.orderImageUrl(img.id, true)" aspect-ratio="1" cover class="order-images__thumb">
                        <v-icon
                            v-if="img.portal_visible"
                            size="small"
                            color="primary"
                            class="order-images__badge"
                            :title="t('OrderImages.portalVisible')"
                        >mdi-account-eye</v-icon>
                    </v-img>
                    <div class="order-images__caption">{{ img.caption || ' ' }}</div>
                </div>
                <div v-if="uploading" class="order-images__tile order-images__tile--pending">
                    <v-progress-circular indeterminate size="28" color="primary" />
                    <div class="order-images__caption">
                        {{ t('OrderImages.uploading', { done: uploadDone, total: uploadTotal }) }}
                    </div>
                </div>
            </div>
        </template>
    </section-card>

    <!-- Betrachter mit Unterschrift und Freigabe -->
    <v-dialog v-model="viewer.show" max-width="1100" :fullscreen="display.smAndDown.value">
        <v-card v-if="current" tabindex="0" @keydown.left="step(-1)" @keydown.right="step(1)">
            <v-toolbar density="compact" color="transparent">
                <v-toolbar-title class="text-body-2">
                    {{ viewer.index + 1 }} / {{ images.length }} · {{ uploadedInfo(current) }}
                </v-toolbar-title>
                <v-btn
                    icon="mdi-delete-outline"
                    variant="text"
                    color="error"
                    :title="t('OrderImages.delete')"
                    @click="confirmDelete(current)"
                />
                <v-btn icon="mdi-close" variant="text" @click="viewer.show = false" />
            </v-toolbar>
            <div class="order-images__stage">
                <v-btn
                    v-if="images.length > 1"
                    icon="mdi-chevron-left"
                    variant="text"
                    class="order-images__nav order-images__nav--left"
                    @click="step(-1)"
                />
                <v-img :src="carsStore.orderImageUrl(current.id)" max-height="70vh" class="order-images__full" />
                <v-btn
                    v-if="images.length > 1"
                    icon="mdi-chevron-right"
                    variant="text"
                    class="order-images__nav order-images__nav--right"
                    @click="step(1)"
                />
            </div>
            <v-card-text>
                <v-row density="compact">
                    <v-col cols="12" :sm="portalEnabled ? 8 : 12">
                        <v-text-field
                            v-model="captionDraft"
                            :label="t('OrderImages.caption')"
                            :hint="portalEnabled ? t('OrderImages.captionHint') : ''"
                            :persistent-hint="portalEnabled"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            @blur="saveCaption"
                            @keydown.enter.prevent="saveCaption"
                        />
                    </v-col>
                    <v-col v-if="portalEnabled" cols="12" sm="4">
                        <v-switch
                            :model-value="!!current.portal_visible"
                            :label="t('OrderImages.portalVisible')"
                            color="primary"
                            density="compact"
                            hide-details
                            @update:model-value="setPortalVisible"
                        />
                    </v-col>
                </v-row>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import SectionCard from '@/core/components/layout/section-card.vue'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { resizeImageForUpload } from '@/features/lxcars/utils/imageResize.js'
import { formatDateTime } from '@/core/utils/dateFormatter.js'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    /** Auftrag; null bei noch nicht gespeichertem Beleg */
    oeId: { type: [Number, String], default: null },
    /** Speichert den Beleg bei Bedarf und liefert die Auftrags-ID */
    ensureOeId: { type: Function, default: null },
})

const { t, locale } = useI18n()
const display = useDisplay()
const carsStore = lxcarsStore()
const oserp = oserpStore()

const cameraInput = ref(null)
const fileInput = ref(null)
const images = ref([])
const loading = ref(false)
const uploading = ref(false)
const uploadDone = ref(0)
const uploadTotal = ref(0)

/** Freigabe nur anbieten, wenn der Mandant ein Kundenportal betreibt */
const portalEnabled = computed(() => oserp.hasPortalWebsite())

// ===== Laden =====

async function loadImages() {
    const oeId = Number(props.oeId) || 0
    if (!oeId) {
        images.value = []
        return
    }
    loading.value = true
    try {
        images.value = await carsStore.loadOrderImages(oeId)
    } catch (e) {
        console.error('Error loading order images:', e)
    } finally {
        loading.value = false
    }
}

onMounted(loadImages)
watch(() => props.oeId, loadImages)

// ===== Hochladen =====

/** Obergrenze des Servers (uploadOrderImage: 20 MB) */
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024

function isImageFile(file) {
    if (file.type) return file.type.startsWith('image/')
    return /\.(jpe?g|png|webp|gif|heic|heif)$/i.test(file.name || '')
}

async function onFilesPicked(event) {
    const files = Array.from(event.target.files || [])
    event.target.value = ''
    if (!files.length) return

    let oeId = Number(props.oeId) || 0
    if (!oeId && props.ensureOeId) {
        try {
            oeId = Number(await props.ensureOeId()) || 0
        } catch { oeId = 0 }
    }
    if (!oeId) {
        toast.warning(t('OrderImages.saveFirst'))
        return
    }

    uploading.value = true
    uploadTotal.value = files.length
    uploadDone.value = 0
    for (const file of files) {
        // accept="image/*" lässt sich im Dateidialog umgehen: Typ hier prüfen,
        // HEIC vom iPhone meldet sich je nach Browser ohne MIME-Typ
        if (!isImageFile(file)) {
            toast.warning(t('OrderImages.notAnImage', { name: file.name }))
            uploadDone.value++
            continue
        }
        try {
            const { blob, filename } = await resizeImageForUpload(file)
            // Nur beim Rückfall auf das Original (Browser konnte es nicht verkleinern)
            if (blob.size > MAX_UPLOAD_BYTES) {
                toast.warning(t('OrderImages.tooLarge', { name: file.name }))
                uploadDone.value++
                continue
            }
            const row = await carsStore.uploadOrderImage(oeId, blob, filename)
            images.value.push(row)
        } catch (e) {
            toast.error(t('OrderImages.uploadFailed', { name: file.name }), { text: e?.message || '' })
        }
        uploadDone.value++
    }
    uploading.value = false
}

// ===== Betrachter =====

const viewer = reactive({ show: false, index: 0 })
const captionDraft = ref('')

const current = computed(() => viewer.show ? images.value[viewer.index] || null : null)

watch(current, img => { captionDraft.value = img?.caption || '' })

function openViewer(index) {
    viewer.index = index
    viewer.show = true
}

function step(delta) {
    if (images.value.length < 2) return
    saveCaption()
    viewer.index = (viewer.index + delta + images.value.length) % images.value.length
}

function uploadedInfo(img) {
    const date = formatDateTime(img.created_at, locale.value)
    return img.employee_name
        ? t('OrderImages.uploadedBy', { name: img.employee_name, date })
        : t('OrderImages.uploadedAt', { date })
}

async function saveCaption() {
    const img = current.value
    if (!img) return
    const caption = captionDraft.value.trim()
    if (caption === (img.caption || '')) return
    try {
        const row = await carsStore.updateOrderImage(img.id, { caption })
        Object.assign(img, row)
    } catch (e) {
        toast.error(t('OrderImages.saveFailed'), { text: e?.message || '' })
    }
}

async function setPortalVisible(value) {
    const img = current.value
    if (!img) return
    try {
        const row = await carsStore.updateOrderImage(img.id, { portal_visible: !!value })
        Object.assign(img, row)
    } catch (e) {
        toast.error(t('OrderImages.saveFailed'), { text: e?.message || '' })
    }
}

async function confirmDelete(img) {
    const result = await alerts.question(t('OrderImages.deleteConfirm'), t('OrderImages.delete'), t('OrderImages.delete'), t('OrderImages.cancel'))
    if (!result.isConfirmed) return
    try {
        await carsStore.deleteOrderImage(img.id)
        images.value = images.value.filter(i => i.id !== img.id)
        if (!images.value.length) {
            viewer.show = false
        } else if (viewer.index >= images.value.length) {
            viewer.index = images.value.length - 1
        }
    } catch (e) {
        toast.error(t('OrderImages.deleteFailed'), { text: e?.message || '' })
    }
}

defineExpose({ loadImages })
</script>

<style scoped>
.order-images__empty {
    margin: 0;
    color: var(--oserp-text-muted);
    font-size: 0.875rem;
}

.order-images__grid {
    --order-images-tile: 140px; /* Kachelbreite, dafür gibt es kein Token */
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(var(--order-images-tile), 1fr));
    gap: var(--oserp-gap-sm);
}

.order-images__tile {
    cursor: zoom-in;
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius-sm);
    overflow: hidden;
    background: var(--oserp-surface-soft);
}

.order-images__tile--pending {
    cursor: default;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: var(--order-images-tile);
    gap: var(--oserp-gap-xs);
}

.order-images__badge {
    position: absolute;
    top: var(--oserp-gap-xs);
    right: var(--oserp-gap-xs);
    background: var(--oserp-surface);
    border-radius: 50%;
    padding: var(--oserp-gap-xs);
}

.order-images__caption {
    font-size: 0.75rem;
    color: var(--oserp-text-muted);
    padding: var(--oserp-gap-xs) var(--oserp-gap-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.order-images__stage {
    position: relative;
    background: var(--oserp-surface-soft);
}

.order-images__full {
    max-height: 70vh;
}

.order-images__nav {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 1;
}

.order-images__nav--left { left: var(--oserp-gap-sm); }
.order-images__nav--right { right: var(--oserp-gap-sm); }
</style>
