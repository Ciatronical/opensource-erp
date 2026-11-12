<!-- src/features/lxcars/components/order-images.camera.dialog.vue -->
<!--
    Kamera für Bilder am Auftrag (docs/features/lxcars.md → Bilder am Auftrag)

    Zeigt das Kamerabild direkt in der Anwendung (getUserMedia). Der Dialog
    bleibt nach dem Auslösen offen, so lassen sich mehrere Fotos hintereinander
    aufnehmen, ohne jedes Mal die Kamera-App des Geräts zu öffnen und das Foto
    dort zu bestätigen. Jedes Foto geht sofort per „shot“ an die Bilderkarte,
    die es im Hintergrund hochlädt.

    Lässt sich die Kamera nicht öffnen (Zugriff verweigert, kein Gerät), bietet
    der Dialog den bisherigen Weg über die Kamera-App an („fallback“).
-->
<template>
    <v-dialog :model-value="modelValue" fullscreen @update:model-value="emit('update:modelValue', $event)">
        <v-card class="order-camera">
            <div class="order-camera__stage">
                <video
                    v-show="!error"
                    ref="video"
                    class="order-camera__video"
                    autoplay
                    playsinline
                    muted
                    @loadedmetadata="ready = true"
                />
                <div v-if="flash" class="order-camera__flash" />
                <v-progress-circular v-if="!ready && !error" indeterminate color="primary" class="order-camera__center" />
                <div v-if="error" class="order-camera__center order-camera__error">
                    <v-icon size="48">mdi-camera-off</v-icon>
                    <p>{{ t('OrderImages.cameraError') }}</p>
                    <v-btn color="primary" prepend-icon="mdi-camera" @click="emit('fallback')">
                        {{ t('OrderImages.cameraFallback') }}
                    </v-btn>
                </div>
            </div>
            <div class="order-camera__bar">
                <span class="order-camera__count">
                    <template v-if="count">{{ t('OrderImages.cameraCount', { count }) }}</template>
                </span>
                <v-btn
                    icon="mdi-camera"
                    size="x-large"
                    color="primary"
                    :disabled="!ready || !!error"
                    :aria-label="t('OrderImages.cameraShutter')"
                    :title="t('OrderImages.cameraShutter')"
                    @click="shoot"
                />
                <div class="order-camera__done">
                    <v-btn variant="tonal" size="large" @click="emit('update:modelValue', false)">
                        {{ t('OrderImages.cameraDone') }}
                    </v-btn>
                </div>
            </div>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, shallowRef, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'shot', 'fallback'])

const { t } = useI18n()

/** Qualität des Einzelbilds; die Bilderkarte verkleinert und komprimiert danach noch einmal */
const FRAME_QUALITY = 0.95
/** Dauer des Aufblitzens nach dem Auslösen (ms) */
const FLASH_MS = 120

const video = ref(null)
const stream = shallowRef(null)
const ready = ref(false)
const error = ref(false)
const flash = ref(false)
const count = ref(0)

async function start() {
    ready.value = false
    error.value = false
    count.value = 0
    try {
        const media = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1920 },
                height: { ideal: 1080 },
            },
        })
        // Während der Rückfrage des Browsers wieder geschlossen
        if (!props.modelValue) {
            media.getTracks().forEach(track => track.stop())
            return
        }
        stream.value = media
    } catch (e) {
        console.error('Error opening camera:', e)
        error.value = true
    }
}

function stop() {
    stream.value?.getTracks().forEach(track => track.stop())
    stream.value = null
    ready.value = false
}

// Das <video> entsteht erst mit dem Dialoginhalt: verbinden, sobald beides da ist
watch([video, stream], ([el, media]) => {
    if (el && el.srcObject !== media) el.srcObject = media
})

watch(() => props.modelValue, open => open ? start() : stop(), { immediate: true })

onBeforeUnmount(stop)

async function shoot() {
    const el = video.value
    if (!el || !el.videoWidth) return

    const canvas = document.createElement('canvas')
    canvas.width = el.videoWidth
    canvas.height = el.videoHeight
    canvas.getContext('2d').drawImage(el, 0, 0)

    flash.value = true
    setTimeout(() => { flash.value = false }, FLASH_MS)

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', FRAME_QUALITY))
    if (!blob) return
    count.value++
    emit('shot', new File([blob], 'foto.jpg', { type: 'image/jpeg' }))
}
</script>

<style scoped>
.order-camera {
    --order-camera-stage: #000; /* Hintergrund hinter dem Kamerabild, dafür gibt es kein Token */
    --order-camera-flash: #fff;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.order-camera__stage {
    position: relative;
    flex: 1 1 auto;
    min-height: 0;
    background: var(--order-camera-stage);
}

.order-camera__video {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

.order-camera__flash {
    position: absolute;
    inset: 0;
    background: var(--order-camera-flash);
    opacity: 0.7;
    pointer-events: none;
}

.order-camera__center {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}

.order-camera__error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--oserp-gap);
    width: min(90%, 420px);
    padding: var(--oserp-gap-lg);
    border-radius: var(--oserp-radius);
    background: var(--oserp-surface);
    text-align: center;
}

.order-camera__error p {
    margin: 0;
}

/* Auslöser bleibt mittig, gleich wie breit Zähler und „Fertig“ sind */
.order-camera__bar {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: var(--oserp-gap);
    padding: var(--oserp-gap) var(--oserp-gap-lg);
    background: var(--oserp-surface);
}

.order-camera__count {
    color: var(--oserp-text-muted);
    font-size: 0.875rem;
}

.order-camera__done {
    display: flex;
    justify-content: flex-end;
}
</style>
