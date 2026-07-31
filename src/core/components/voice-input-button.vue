<!-- src/core/components/voice-input-button.vue -->
<!--
    Wiederverwendbarer Mikrofon-Button für Spracheingabe.

    Nimmt über das Mikrofon auf, schickt das Audio an den lokalen Whisper-Dienst
    und gibt den erkannten Text per Event `@transcript` zurück. Der aufrufende
    Kontext entscheidet, was mit dem Text passiert (ins Feld schreiben, anhängen …).

    Beispiel:
        <VoiceInputButton @transcript="text => meinFeld += text" />

    Ein Klick startet die Aufnahme (Button wird rot), der nächste Klick beendet
    sie und transkribiert (Spinner). Push-to-talk ist bewusst nicht nötig — für
    Kollegen, die schlecht tippen, ist Klick/Klick am robustesten.
-->
<template>
    <v-tooltip location="top" :text="tooltipText">
        <template #activator="{ props }">
            <v-btn
                v-if="supported"
                v-bind="props"
                :icon="recording ? 'mdi-stop' : 'mdi-microphone'"
                :color="recording ? 'error' : (error ? 'warning' : color)"
                :loading="busy"
                :size="size"
                :density="density"
                variant="text"
                @click.stop.prevent="toggle()"
            />
        </template>
    </v-tooltip>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useVoiceInput } from '@/core/composables/useVoiceInput.js'

const props = defineProps({
    size: { type: String, default: 'small' },
    density: { type: String, default: 'comfortable' },
    color: { type: String, default: 'primary' },
    lang: { type: String, default: 'de' },
})

const emit = defineEmits(['transcript'])
const { t } = useI18n()

const { recording, busy, error, supported, toggle } = useVoiceInput({
    lang: props.lang,
    onText: (text) => emit('transcript', text),
})

const tooltipText = computed(() => {
    if (busy.value) return t('voiceInput.transcribing')
    if (recording.value) return t('voiceInput.stop')
    if (error.value === 'permission') return t('voiceInput.permissionDenied')
    if (error.value) return t('voiceInput.error')
    return t('voiceInput.start')
})
</script>
