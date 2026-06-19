<!-- src/core/views/anschlagtafel/anschlagtafel.view.vue -->
<!--
    Anschlagtafel: zeigt per Telegram eingesprochene und via Whisper
    transkribierte Sprachnotizen live auf einem Firmenbildschirm an.
    Vollbild, neueste Notiz oben, Echtzeit ueber SSE (Named Event 'voicenote_change').
-->
<template>
    <div class="anschlagtafel">
        <div v-if="!sseConnected" class="anschlagtafel__no-sse" :title="t('Anschlagtafel.offline')" />

        <header class="anschlagtafel__topbar">
            <div class="anschlagtafel__title">
                <v-icon size="large">mdi-bulletin-board</v-icon>
                {{ t('Anschlagtafel.title') }}
            </div>
            <div class="anschlagtafel__clock">{{ currentTime }}</div>
            <v-btn size="small" variant="tonal" color="error" @click="exit">
                <v-icon start size="small">mdi-exit-to-app</v-icon>
                {{ t('Anschlagtafel.exit') }}
            </v-btn>
        </header>

        <main class="anschlagtafel__board">
            <div v-if="notes.length === 0" class="anschlagtafel__empty">
                <v-icon size="64">mdi-microphone-message</v-icon>
                <p>{{ t('Anschlagtafel.empty') }}</p>
            </div>

            <transition-group name="note" tag="div" class="anschlagtafel__grid">
                <article
                    v-for="note in notes"
                    :key="note.id"
                    class="anschlagtafel__note"
                    :class="{ 'anschlagtafel__note--failed': note.status === 'failed' }"
                >
                    <div class="anschlagtafel__note-head">
                        <span class="anschlagtafel__sender">
                            <v-icon size="small">mdi-account-voice</v-icon>
                            {{ note.sender_name || t('Anschlagtafel.unknown') }}
                        </span>
                        <span class="anschlagtafel__meta">
                            <span v-if="note.duration">{{ formatDuration(note.duration) }}</span>
                            <span class="anschlagtafel__time">{{ formatTime(note.itime) }}</span>
                            <v-btn
                                icon="mdi-check"
                                size="x-small"
                                variant="text"
                                :title="t('Anschlagtafel.dismiss')"
                                @click="dismiss(note.id)"
                            />
                        </span>
                    </div>
                    <p class="anschlagtafel__text">
                        <template v-if="note.status === 'failed'">
                            <v-icon size="small" color="warning">mdi-alert</v-icon>
                            {{ t('Anschlagtafel.failed') }}
                        </template>
                        <template v-else>{{ note.transcript }}</template>
                    </p>
                </article>
            </transition-group>
        </main>
    </div>
</template>

<script>
import { defineComponent, ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'

export default defineComponent({
    name: 'AnschlagtafelView',
    setup() {
        const { t } = useI18n()
        const router = useRouter()

        const notes = ref([])
        const sseConnected = ref(false)
        const currentTime = ref('')

        let sseSource = null
        let pollInterval = null
        let clockInterval = null

        // ── Daten laden ──

        async function loadNotes() {
            try {
                const res = await axios.post('/api/voicenotes/', {
                    action: 'getRecentVoiceNotes',
                    limit: 50,
                })
                if (res.data.success) {
                    notes.value = res.data.payload.notes || []
                }
            } catch { /* Netzwerkfehler ignorieren, Polling versucht es erneut */ }
        }

        async function dismiss(id) {
            try {
                await axios.post('/api/voicenotes/', { action: 'hideVoiceNote', id })
                notes.value = notes.value.filter(n => n.id !== id)
            } catch { /* ignorieren */ }
        }

        // ── SSE ──

        function connectSSE() {
            if (typeof EventSource === 'undefined') {
                startPolling()
                return
            }
            sseSource = new EventSource('/sse/events')
            sseSource.onopen = () => {
                sseConnected.value = true
                stopPolling()
            }
            sseSource.addEventListener('voicenote_change', (e) => {
                sseConnected.value = true
                try {
                    const note = JSON.parse(e.data)
                    // Neue Notiz oben einfuegen, Duplikate vermeiden.
                    if (!notes.value.some(n => n.id === note.id)) {
                        notes.value.unshift(note)
                        if (notes.value.length > 50) notes.value.pop()
                    }
                } catch { /* ignorieren */ }
            })
            sseSource.onerror = () => {
                sseConnected.value = false
                startPolling()
            }
        }

        function startPolling() {
            if (pollInterval) return
            pollInterval = setInterval(loadNotes, 30000)
        }

        function stopPolling() {
            if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
        }

        // ── Formatierung ──

        function formatTime(iso) {
            if (!iso) return ''
            const d = new Date(iso)
            return d.toLocaleString('de-DE', {
                day: '2-digit', month: '2-digit',
                hour: '2-digit', minute: '2-digit',
            })
        }

        function formatDuration(sec) {
            const s = Math.round(Number(sec) || 0)
            const m = Math.floor(s / 60)
            const r = s % 60
            return m > 0 ? `${m}:${String(r).padStart(2, '0')} min` : `${r}s`
        }

        function tickClock() {
            currentTime.value = new Date().toLocaleTimeString('de-DE', {
                hour: '2-digit', minute: '2-digit',
            })
        }

        function exit() {
            router.push('/')
        }

        onMounted(() => {
            tickClock()
            clockInterval = setInterval(tickClock, 10000)
            loadNotes()
            connectSSE()
        })

        onBeforeUnmount(() => {
            if (sseSource) sseSource.close()
            stopPolling()
            if (clockInterval) clearInterval(clockInterval)
        })

        return {
            t, notes, sseConnected, currentTime,
            dismiss, formatTime, formatDuration, exit,
        }
    },
})
</script>

<style scoped>
.anschlagtafel {
    position: fixed;
    inset: 0;
    display: flex;
    flex-direction: column;
    background: #1a1d21;
    color: #f4f4f5;
    overflow: hidden;
}

.anschlagtafel__no-sse {
    position: absolute;
    top: 10px;
    right: 10px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #e53935;
    box-shadow: 0 0 8px #e53935;
    z-index: 10;
}

.anschlagtafel__topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 24px;
    background: #232730;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.anschlagtafel__title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.6rem;
    font-weight: 700;
}

.anschlagtafel__clock {
    font-size: 1.6rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    opacity: 0.85;
}

.anschlagtafel__board {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}

.anschlagtafel__empty {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    opacity: 0.4;
    font-size: 1.4rem;
}

.anschlagtafel__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
    gap: 20px;
}

.anschlagtafel__note {
    background: #2b303b;
    border-left: 5px solid #4caf50;
    border-radius: 10px;
    padding: 18px 20px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
}

.anschlagtafel__note--failed {
    border-left-color: #fb8c00;
}

.anschlagtafel__note-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
    font-size: 1rem;
    opacity: 0.85;
}

.anschlagtafel__sender {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
}

.anschlagtafel__meta {
    display: flex;
    align-items: center;
    gap: 12px;
    font-variant-numeric: tabular-nums;
}

.anschlagtafel__time {
    opacity: 0.7;
}

.anschlagtafel__text {
    font-size: 1.5rem;
    line-height: 1.45;
    word-break: break-word;
}

/* Einblend-Animation fuer neue Notizen */
.note-enter-active {
    transition: all 0.4s ease;
}
.note-enter-from {
    opacity: 0;
    transform: translateY(-16px);
}
.note-move {
    transition: transform 0.4s ease;
}
</style>
