<!-- src/features/lxcars/components/maintenance.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card" :class="{ 'is-disabled': !hasCar }">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-wrench</v-icon>
            {{ t('MaintenanceSectionCard.title') }}
            <v-spacer />
            <v-tooltip location="top" :text="voiceRecording ? t('MaintenanceSectionCard.voice.stop') : t('MaintenanceSectionCard.voice.hint')">
                <template #activator="{ props: tp }">
                    <v-btn
                        v-if="voiceSupported && showVoice"
                        v-bind="tp"
                        :icon="voiceRecording ? 'mdi-stop' : 'mdi-microphone'"
                        :color="voiceRecording ? 'error' : 'primary'"
                        :loading="voiceBusy || voiceExtracting"
                        :disabled="!hasCar"
                        size="small"
                        variant="tonal"
                        class="mr-2"
                        tabindex="-1"
                        @click="voiceToggle()"
                    />
                </template>
            </v-tooltip>
            <v-chip
                :variant="oeExtData.c_sk ? 'flat' : 'outlined'"
                :color="oeExtData.c_sk ? 'primary' : undefined"
                :disabled="!hasCar"
                size="small"
                class="cursor-pointer"
                @click="onToggleSk"
            >
                <v-icon start size="x-small">{{ oeExtData.c_sk ? 'mdi-link-variant' : 'mdi-link-variant-off' }}</v-icon>
                {{ t('MaintenanceSectionCard.fields.c_sk') }}
            </v-chip>
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body">
            <v-row dense>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayZrd"
                        :label="t('MaintenanceSectionCard.fields.c_zrd')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        placeholder="MM/JJ"
                        :disabled="!hasCar || oeExtData.c_sk"
                        @blur="onBlurMonthYear('c_zrd')"
                    />
                </v-col>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayZrk"
                        :label="t('MaintenanceSectionCard.fields.c_zrk')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        suffix="km"
                        placeholder="z.B. 180"
                        :disabled="!hasCar || oeExtData.c_sk"
                        @blur="onBlurKm"
                    />
                </v-col>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayBf"
                        :label="t('MaintenanceSectionCard.fields.c_bf')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        placeholder="MM/JJ"
                        :disabled="!hasCar"
                        @blur="onBlurMonthYear('c_bf')"
                    />
                </v-col>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayWd"
                        :label="t('MaintenanceSectionCard.fields.c_wd')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        placeholder="MM/JJ"
                        :disabled="!hasCar"
                        @blur="onBlurMonthYear('c_wd')"
                    />
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>

<script>
import { defineComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { parseMonthYear, formatMonthYear } from '@/features/lxcars/utils/validation.js'
import { useVoiceInput } from '@/core/composables/useVoiceInput.js'
import * as toasts from '@/core/utils/toasts.js'

export default defineComponent({
    name: 'MaintenanceSectionCard',
    props: {
        oeExtData: { type: Object, required: true },
        hasCar: { type: Boolean, default: false },
        // Im Auftrag sitzt das Sprach-Mic in der Aktionsleiste (neben Wiedervorlage);
        // dort wird das Karten-Mic ausgeblendet. Im Werkstatt-Modus (ohne Leiste) an.
        showVoice: { type: Boolean, default: true }
    },
    emits: ['oe-ext-field-change'],
    setup(props, { emit }) {
        const { t } = useI18n()

        const displayZrd = ref(formatMonthYear(props.oeExtData.c_zrd))
        const displayBf = ref(formatMonthYear(props.oeExtData.c_bf))
        const displayWd = ref(formatMonthYear(props.oeExtData.c_wd))
        const displayZrk = ref(formatKm(props.oeExtData.c_zrk))

        watch(() => props.oeExtData.c_zrd, v => { displayZrd.value = formatMonthYear(v) })
        watch(() => props.oeExtData.c_bf,  v => { displayBf.value  = formatMonthYear(v) })
        watch(() => props.oeExtData.c_wd,  v => { displayWd.value  = formatMonthYear(v) })
        watch(() => props.oeExtData.c_zrk, v => { displayZrk.value = formatKm(v) })

        function formatKm(value) {
            const num = Number(value)
            if (!num) return ''
            return num.toLocaleString('de-DE')
        }

        const displayRefs = { c_zrd: displayZrd, c_bf: displayBf, c_wd: displayWd }

        function onBlurMonthYear(field) {
            const raw = (displayRefs[field].value || '').trim()
            const parsed = raw ? parseMonthYear(raw) : null
            const current = props.oeExtData[field] || null
            if (parsed === current) {
                displayRefs[field].value = formatMonthYear(parsed)
                return
            }
            displayRefs[field].value = formatMonthYear(parsed)
            emit('oe-ext-field-change', field, parsed)
        }

        function onBlurKm() {
            const raw = String(displayZrk.value).replace(/[.\s]/g, '').trim()
            let km = null
            if (raw) {
                const num = parseInt(raw, 10)
                if (!isNaN(num) && num > 0) km = num < 1000 ? num * 1000 : num
            }
            const current = props.oeExtData.c_zrk || null
            if (km === current) {
                displayZrk.value = formatKm(km)
                return
            }
            displayZrk.value = formatKm(km)
            emit('oe-ext-field-change', 'c_zrk', km)
        }

        function onToggleSk() {
            if (!props.hasCar) return
            emit('oe-ext-field-change', 'c_sk', !props.oeExtData.c_sk)
        }

        // ── Intelligente Spracheingabe ────────────────────────────────────────
        // Frei gesprochen ("Kilometerstand 120369, Zahnriemen fällig bei 20000,
        // Bremsflüssigkeit 02/2029"): Whisper transkribiert, der lokale LLM
        // strukturiert, wir setzen die Felder wie von Hand (Auto-Save greift über
        // die oe-ext-field-change-Events). Gekoppelte Zahnriemen-Felder (c_sk)
        // werden übersprungen — genau wie beim Tippen (dort sind sie deaktiviert).
        const voiceExtracting = ref(false)

        async function applyMaintenanceSpeech(text) {
            const spoken = (text || '').trim()
            if (!spoken || !props.hasCar) return
            voiceExtracting.value = true
            try {
                const { data } = await axios.post('/api/lxcars/', { action: 'extractVehicleData', text: spoken })
                if (!data?.success) {
                    toasts.error(data?.text || t('MaintenanceSectionCard.voice.failed'))
                    return
                }
                const f = data.payload?.fields || {}
                const done = []

                if (f.c_km != null) {
                    const v = parseInt(f.c_km, 10)
                    if (v > 0) { emit('oe-ext-field-change', 'km_stand', v); done.push(`${t('MaintenanceSectionCard.voice.kmStand')}: ${formatKm(v)} km`) }
                }
                if (f.c_zrk != null && !props.oeExtData.c_sk) {
                    let n = parseInt(f.c_zrk, 10)
                    if (n > 0) { n = n < 1000 ? n * 1000 : n; emit('oe-ext-field-change', 'c_zrk', n); done.push(`${t('MaintenanceSectionCard.fields.c_zrk')}: ${formatKm(n)} km`) }
                }
                if (f.c_zrd && !props.oeExtData.c_sk) {
                    const d = parseMonthYear(f.c_zrd)
                    if (d) { emit('oe-ext-field-change', 'c_zrd', d); done.push(`${t('MaintenanceSectionCard.fields.c_zrd')}: ${formatMonthYear(d)}`) }
                }
                if (f.c_bf) {
                    const d = parseMonthYear(f.c_bf)
                    if (d) { emit('oe-ext-field-change', 'c_bf', d); done.push(`${t('MaintenanceSectionCard.fields.c_bf')}: ${formatMonthYear(d)}`) }
                }
                if (f.c_wd) {
                    const d = parseMonthYear(f.c_wd)
                    if (d) { emit('oe-ext-field-change', 'c_wd', d); done.push(`${t('MaintenanceSectionCard.fields.c_wd')}: ${formatMonthYear(d)}`) }
                }

                if (done.length) toasts.success(t('MaintenanceSectionCard.voice.applied') + ' ' + done.join(' · '))
                else toasts.info(t('MaintenanceSectionCard.voice.nothing'))
            } catch (e) {
                toasts.error(t('MaintenanceSectionCard.voice.failed'))
            } finally {
                voiceExtracting.value = false
            }
        }

        const {
            recording: voiceRecording,
            busy: voiceBusy,
            supported: voiceSupported,
            toggle: voiceToggle
        } = useVoiceInput({ onText: applyMaintenanceSpeech })

        return {
            t, displayZrd, displayBf, displayWd, displayZrk, onBlurMonthYear, onBlurKm, onToggleSk,
            voiceRecording, voiceBusy, voiceExtracting, voiceSupported, voiceToggle
        }
    }
})
</script>

<style scoped>
.faktura-card { border-radius: 8px; }
.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #f5f5f5;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}
.faktura-card__body { padding: 16px !important; }
.faktura-card__body :deep(.v-input) { flex: unset; grid-template-rows: auto !important; }
.faktura-card__body :deep(.v-input__details) { display: none !important; }
.faktura-card__body :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 12px;
    --v-field-padding-end: 12px;
}
.is-disabled { opacity: 0.75; }
.cursor-pointer { cursor: pointer; }
</style>
