// src/core/composables/useVoiceInput.js
//
// Wiederverwendbare Spracheingabe: nimmt über das Browser-Mikrofon auf
// (MediaRecorder) und schickt das Audio an den lokalen Whisper-Dienst
// (über den PHP-Proxy /api/voice/). Das transkribierte Ergebnis wird per
// Callback zurückgegeben.
//
// Nutzung:
//   const { recording, busy, error, supported, toggle } = useVoiceInput({
//       onText: (text) => { meinFeld.value += text }
//   })
//   <v-btn @click="toggle()" :loading="busy" :color="recording ? 'error' : ''">

import { ref } from 'vue'
import axios from 'axios'

export function useVoiceInput(options = {}) {
    const { onText = () => {}, lang = 'de' } = options

    const recording = ref(false)
    const busy = ref(false)
    const error = ref('')

    // Aufnahme wird nur unterstützt, wenn Mikrofon-API + MediaRecorder da sind.
    const supported = typeof navigator !== 'undefined'
        && !!navigator.mediaDevices?.getUserMedia
        && typeof window !== 'undefined'
        && typeof window.MediaRecorder !== 'undefined'

    let mediaRecorder = null
    let chunks = []
    let stream = null

    function cleanupStream() {
        if (stream) {
            stream.getTracks().forEach(t => t.stop())
            stream = null
        }
    }

    async function start() {
        if (!supported) {
            error.value = 'unsupported'
            return
        }
        error.value = ''
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true })
        } catch (e) {
            // Nutzer hat Mikrofon verweigert oder kein Gerät vorhanden.
            error.value = 'permission'
            return
        }

        chunks = []
        mediaRecorder = new MediaRecorder(stream)
        mediaRecorder.ondataavailable = (ev) => {
            if (ev.data && ev.data.size > 0) chunks.push(ev.data)
        }
        mediaRecorder.onstop = () => { transcribe() }
        mediaRecorder.start()
        recording.value = true
    }

    function stop() {
        if (mediaRecorder && recording.value) {
            recording.value = false
            mediaRecorder.stop() // -> onstop -> transcribe()
        }
    }

    function toggle() {
        if (recording.value) stop()
        else start()
    }

    async function transcribe() {
        cleanupStream()
        if (!chunks.length) return

        const blob = new Blob(chunks, { type: chunks[0].type || 'audio/webm' })
        chunks = []

        busy.value = true
        error.value = ''
        try {
            const dataUrl = await blobToDataUrl(blob)
            const { data } = await axios.post('/api/voice/', {
                action: 'transcribeAudio',
                audio: dataUrl,
                lang,
            })
            if (data?.success && data?.payload?.text) {
                onText(data.payload.text)
            } else if (data?.success) {
                // Erkannt, aber leer (nur Stille) — kein Fehler, nichts einfügen.
            } else {
                error.value = data?.text || 'failed'
            }
        } catch (e) {
            error.value = 'request'
        } finally {
            busy.value = false
        }
    }

    function blobToDataUrl(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onloadend = () => resolve(reader.result)
            reader.onerror = reject
            reader.readAsDataURL(blob)
        })
    }

    return { recording, busy, error, supported, start, stop, toggle }
}
