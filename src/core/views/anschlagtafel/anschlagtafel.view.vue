<!-- src/core/views/anschlagtafel/anschlagtafel.view.vue -->
<!--
    Anschlagtafel: zeigt per Telegram eingesprochene und via Whisper
    transkribierte Sprachnotizen live auf einem Firmenbildschirm an.
    Hochformat (Samsung QM50, 1080x1920), neueste Notiz oben, Echtzeit ueber
    SSE (Named Event 'voicenote_change' mit action: new | removed | cleared).
    Design an Apple orientiert: dunkel, Frosted-Glass-Karten, grosse Typo.
    Neue Notizen loesen einen Signalton aus (Web Audio, kein Sound-Asset noetig).
-->
<template>
    <div class="atafel">
        <header class="atafel__bar">
            <div class="atafel__brand">
                <span class="atafel__logo">
                    <svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true">
                        <path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2Z"/>
                    </svg>
                </span>
                <span class="atafel__title">{{ t('Anschlagtafel.title') }}</span>
                <span class="atafel__dot" :class="{ 'atafel__dot--live': sseConnected }"
                      :title="sseConnected ? t('Anschlagtafel.online') : t('Anschlagtafel.offline')" />
            </div>
            <div class="atafel__clockwrap">
                <div class="atafel__clock">{{ currentTime }}</div>
                <div class="atafel__date">{{ currentDate }}</div>
            </div>
            <div class="atafel__actions">
                <button
                    class="atafel__icon-btn"
                    :class="{ 'atafel__icon-btn--muted': !soundEnabled }"
                    :title="soundEnabled ? t('Anschlagtafel.soundOn') : t('Anschlagtafel.soundOff')"
                    @click="toggleSound"
                >
                    <svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true">
                        <path fill="currentColor" d="M4 9v6h4l5 5V4L8 9H4Z"/>
                        <path v-if="soundEnabled" fill="currentColor"
                              d="M16.5 12a4.5 4.5 0 0 0-2.5-4.03v8.06A4.5 4.5 0 0 0 16.5 12Zm-2.5 8.7a8.5 8.5 0 0 0 0-17.4v2.06a6.5 6.5 0 0 1 0 13.28v2.06Z"/>
                        <path v-else fill="currentColor"
                              d="m21.4 8.4-1.4-1.4-2.6 2.6-2.6-2.6-1.4 1.4 2.6 2.6-2.6 2.6 1.4 1.4 2.6-2.6 2.6 2.6 1.4-1.4-2.6-2.6 2.6-2.6Z"/>
                    </svg>
                </button>
                <button class="atafel__icon-btn" :title="t('Anschlagtafel.exit')" @click="exit">✕</button>
            </div>
        </header>

        <!-- Der Browser (auch der Tizen-Browser des Samsung-Displays) gibt Audio erst
             nach einer Nutzeraktion frei. Ein Klick bzw. OK auf der Fernbedienung genuegt. -->
        <button v-if="soundEnabled && soundLocked" class="atafel__unlock" @click="unlockAudio">
            <span class="atafel__unlock-icon">🔔</span>
            <span class="atafel__unlock-text">
                <strong>{{ t('Anschlagtafel.enableSound') }}</strong>
                <small>{{ t('Anschlagtafel.enableSoundHint') }}</small>
            </span>
        </button>

        <main class="atafel__board">
            <div v-if="notes.length === 0" class="atafel__empty">
                <div class="atafel__empty-icon">
                    <svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true">
                        <path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2Z"/>
                    </svg>
                </div>
                <p>{{ t('Anschlagtafel.empty') }}</p>
                <span class="atafel__empty-hint">{{ t('Anschlagtafel.emptyHint') }}</span>
            </div>

            <transition-group name="note" tag="div" class="atafel__list">
                <article
                    v-for="(note, index) in notes"
                    :key="note.id"
                    class="atafel__card"
                    :class="{
                        'atafel__card--failed': note.status === 'failed',
                        'atafel__card--latest': index === 0,
                    }"
                    :style="{ '--accent': avatarColor(note.sender_name) }"
                >
                    <div class="atafel__card-head">
                        <span class="atafel__avatar" :style="{ background: avatarColor(note.sender_name) }">
                            {{ initials(note.sender_name) }}
                        </span>
                        <span class="atafel__sender">{{ note.sender_name || t('Anschlagtafel.unknown') }}</span>
                        <span v-if="index === 0" class="atafel__badge">{{ t('Anschlagtafel.latestBadge') }}</span>
                        <span class="atafel__time">{{ formatTime(note.itime) }}</span>
                        <button class="atafel__done" :title="t('Anschlagtafel.dismiss')" @click="dismiss(note.id)">✓</button>
                    </div>
                    <p class="atafel__text">
                        <template v-if="note.status === 'failed'">⚠️ {{ t('Anschlagtafel.failed') }}</template>
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
        const currentDate = ref('')
        const soundEnabled = ref(localStorage.getItem('anschlagtafel_sound') !== 'off')
        const soundLocked = ref(true)

        let sseSource = null
        let pollInterval = null
        let clockInterval = null
        let reconcileInterval = null
        let audioCtx = null
        let lastChime = 0
        let knownIds = new Set()
        let initialLoadDone = false

        // ── Signalton ──

        // Der Ton wird per Web Audio erzeugt, damit auf dem Display keine
        // Sound-Datei geladen werden muss (funktioniert auch offline).
        function chime(volume = 0.9) {
            if (!soundEnabled.value) return
            const ctx = audioCtx
            if (!ctx || ctx.state !== 'running') { soundLocked.value = true; return }
            const start = ctx.currentTime + 0.02
            // Zwei Glockentöne (Ding-Dong) mit klarer Hüllkurve — auf dem Display gut hörbar.
            const toene = [[880, 0], [1174.66, 0.22]]
            for (const [frequenz, versatz] of toene) {
                const osc = ctx.createOscillator()
                const gain = ctx.createGain()
                const t0 = start + versatz
                osc.type = 'triangle'
                osc.frequency.value = frequenz
                gain.gain.setValueAtTime(0.0001, t0)
                gain.gain.exponentialRampToValueAtTime(volume, t0 + 0.02)
                gain.gain.exponentialRampToValueAtTime(0.0001, t0 + 1.1)
                osc.connect(gain).connect(ctx.destination)
                osc.start(t0)
                osc.stop(t0 + 1.15)
            }
        }

        // Mehrere Notizen kurz hintereinander sollen nur einmal klingeln.
        function notifyNew() {
            const jetzt = Date.now()
            if (jetzt - lastChime < 3000) return
            lastChime = jetzt
            chime()
        }

        async function unlockAudio(mitBestaetigung = true) {
            if (!audioCtx) {
                const Ctx = window.AudioContext || window.webkitAudioContext
                if (!Ctx) { soundLocked.value = false; return }
                audioCtx = new Ctx()
            }
            const warGesperrt = soundLocked.value
            try { await audioCtx.resume() } catch { /* Autoplay-Sperre, Banner bleibt */ }
            soundLocked.value = audioCtx.state !== 'running'
            // Kurze Bestätigung, damit die Lautstärke am Display gleich geprüft werden kann.
            if (mitBestaetigung && warGesperrt && !soundLocked.value) chime(0.5)
        }

        function toggleSound() {
            soundEnabled.value = !soundEnabled.value
            localStorage.setItem('anschlagtafel_sound', soundEnabled.value ? 'on' : 'off')
            if (soundEnabled.value) unlockAudio()
        }

        // ── Daten laden ──

        async function loadNotes() {
            try {
                const res = await axios.post('/api/voicenotes/', {
                    action: 'getRecentVoiceNotes',
                    limit: 50,
                })
                if (res.data.success) {
                    const liste = res.data.payload.notes || []
                    // Beim ersten Laden nicht klingeln — nur bei wirklich neuen Einträgen.
                    const hatNeue = initialLoadDone && liste.some(n => !knownIds.has(n.id))
                    notes.value = liste
                    knownIds = new Set(liste.map(n => n.id))
                    initialLoadDone = true
                    if (hatNeue) notifyNew()
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
                // Bei jeder (Wieder-)Verbindung mit dem DB-Stand abgleichen. Entfernt
                // "Geister"-Eintraege, die geloescht wurden, waehrend die Tafel offline
                // war oder ein 'removed'-Event verpasst hat.
                loadNotes()
            }
            sseSource.addEventListener('voicenote_change', (e) => {
                sseConnected.value = true
                try {
                    const d = JSON.parse(e.data)
                    if (d.action === 'removed') {
                        // Einzelnen Eintrag live entfernen (Sprachbefehl "loeschen"/"Korrektur").
                        notes.value = notes.value.filter(n => n.id !== d.id)
                    } else if (d.action === 'updated') {
                        // Eintrag an Ort und Stelle korrigieren (Position bleibt erhalten).
                        const n = notes.value.find(x => x.id === d.id)
                        if (n) {
                            n.transcript = d.transcript
                            if (d.status) n.status = d.status
                        }
                    } else if (d.action === 'cleared') {
                        // Sprachbefehl "Alle loeschen".
                        notes.value = []
                    } else if (d.action === 'reordered') {
                        // Reihenfolge von der PC-Tafel per Drag & Drop live uebernehmen.
                        if (Array.isArray(d.order)) {
                            const rank = new Map(d.order.map((id, i) => [id, i]))
                            notes.value = [...notes.value].sort((a, b) =>
                                (rank.has(a.id) ? rank.get(a.id) : Infinity) -
                                (rank.has(b.id) ? rank.get(b.id) : Infinity)
                            )
                        }
                    } else {
                        // Neue Notiz oben einfuegen, Duplikate vermeiden.
                        if (!notes.value.some(n => n.id === d.id)) {
                            notes.value.unshift(d)
                            if (notes.value.length > 50) notes.value.pop()
                            knownIds = new Set(notes.value.map(n => n.id))
                            notifyNew()
                        }
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
            const now = new Date()
            const sameDay = d.toDateString() === now.toDateString()
            const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
            if (sameDay) return time
            return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' }) + ' ' + time
        }

        // Initialen fuer den Avatar-Kreis.
        function initials(name) {
            if (!name) return '?'
            const parts = String(name).trim().split(/\s+/).filter(Boolean)
            if (parts.length === 0) return '?'
            if (parts.length === 1) return parts[0].charAt(0).toUpperCase()
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase()
        }

        // Stabile, angenehme Akzentfarbe aus dem Namen ableiten (Avatar + Kartenrand).
        function avatarColor(name) {
            const palette = [
                '#007AFF', '#34C759', '#FF9500', '#FF2D55',
                '#AF52DE', '#5AC8FA', '#FFCC00', '#5856D6',
            ]
            const s = String(name || '?')
            let h = 0
            for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0
            return palette[h % palette.length]
        }

        function tickClock() {
            const now = new Date()
            currentTime.value = now.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
            currentDate.value = now.toLocaleDateString('de-DE', {
                weekday: 'long', day: '2-digit', month: 'long',
            })
        }

        function exit() {
            router.push({ name: 'startup' })
        }

        // Erste beliebige Nutzeraktion (Klick, Touch, OK auf der Fernbedienung)
        // gibt den Ton frei; danach wird der Listener nicht mehr gebraucht.
        function onFirstInteraction() {
            unlockAudio()
            if (!soundLocked.value) removeUnlockListeners()
        }

        function removeUnlockListeners() {
            window.removeEventListener('pointerdown', onFirstInteraction)
            window.removeEventListener('keydown', onFirstInteraction)
        }

        onMounted(() => {
            tickClock()
            clockInterval = setInterval(tickClock, 10000)
            // Manche Signage-Browser erlauben Audio ohne Nutzeraktion — erst versuchen,
            // sonst blendet das Banner die Freischaltung ein.
            if (soundEnabled.value) unlockAudio(false)
            window.addEventListener('pointerdown', onFirstInteraction)
            window.addEventListener('keydown', onFirstInteraction)
            loadNotes()
            connectSSE()
            // Sicherheits-Abgleich: auch bei stehender SSE-Verbindung regelmaessig mit
            // dem DB-Stand synchronisieren, damit verpasste Loeschungen ("Geister") nicht
            // dauerhaft auf dem Dauer-Display haengenbleiben.
            reconcileInterval = setInterval(loadNotes, 300000)
        })

        onBeforeUnmount(() => {
            if (sseSource) sseSource.close()
            stopPolling()
            if (clockInterval) clearInterval(clockInterval)
            if (reconcileInterval) clearInterval(reconcileInterval)
            removeUnlockListeners()
            if (audioCtx) { audioCtx.close(); audioCtx = null }
        })

        return {
            t, notes, sseConnected, currentTime, currentDate,
            soundEnabled, soundLocked, toggleSound, unlockAudio,
            dismiss, formatTime, initials, avatarColor, exit,
        }
    },
})
</script>

<style scoped>
.atafel {
    position: fixed;
    inset: 0;
    display: flex;
    flex-direction: column;
    background:
        radial-gradient(60vh 60vh at 12% 0%, rgba(0, 122, 255, 0.10) 0%, transparent 60%),
        radial-gradient(70vh 70vh at 95% 12%, rgba(175, 82, 222, 0.10) 0%, transparent 60%),
        radial-gradient(80vh 80vh at 50% 108%, rgba(52, 199, 89, 0.10) 0%, transparent 55%),
        linear-gradient(170deg, #fbfcff 0%, #eef2f9 100%);
    color: #1d1d1f;
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
    letter-spacing: 0.2px;
}

/* ── Kopfleiste ─────────────────────────────────────────────── */
.atafel__bar {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 2vh;
    padding: 1.4vh 3vh;
    background: rgba(255, 255, 255, 0.72);
    backdrop-filter: blur(24px) saturate(180%);
    -webkit-backdrop-filter: blur(24px) saturate(180%);
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    box-shadow: 0 0.4vh 2vh rgba(17, 24, 39, 0.05);
}

.atafel__brand {
    display: flex;
    align-items: center;
    gap: 1.4vh;
    min-width: 0;
}

.atafel__logo {
    display: grid;
    place-items: center;
    width: 4vh;
    height: 4vh;
    font-size: 2.2vh;
    border-radius: 1.1vh;
    color: #fff;
    background: linear-gradient(160deg, #4da2ff, #007AFF);
    box-shadow: 0 0.8vh 1.8vh rgba(0, 122, 255, 0.35);
}

.atafel__title {
    font-size: 2.3vh;
    font-weight: 700;
    letter-spacing: 0.3px;
    white-space: nowrap;
    color: #1d1d1f;
}

.atafel__dot {
    width: 1.3vh;
    height: 1.3vh;
    border-radius: 50%;
    background: #ff3b30;
    box-shadow: 0 0 0 0.4vh rgba(255, 59, 48, 0.15);
    transition: background 0.4s, box-shadow 0.4s;
}
.atafel__dot--live {
    background: #34c759;
    box-shadow: 0 0 0 0.4vh rgba(52, 199, 89, 0.18);
}

.atafel__clockwrap {
    text-align: center;
    line-height: 1;
}
.atafel__clock {
    font-size: 3vh;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.5px;
    color: #1d1d1f;
}
.atafel__date {
    margin-top: 0.3vh;
    font-size: 1.5vh;
    font-weight: 500;
    color: #86868b;
    text-transform: capitalize;
}

.atafel__actions {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 1vh;
}

.atafel__icon-btn {
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    width: 4vh;
    height: 4vh;
    border: none;
    border-radius: 50%;
    font-size: 2vh;
    color: #86868b;
    background: rgba(0, 0, 0, 0.05);
    cursor: pointer;
    transition: background 0.2s, color 0.2s;
}
.atafel__icon-btn:hover { background: rgba(255, 59, 48, 0.15); color: #ff3b30; }
.atafel__icon-btn--muted { color: #ff3b30; background: rgba(255, 59, 48, 0.12); }

/* ── Ton freischalten ───────────────────────────────────────── */
.atafel__unlock {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 2vh;
    width: 100%;
    padding: 1.4vh 3vh;
    border: none;
    border-bottom: 1px solid rgba(255, 149, 0, 0.25);
    text-align: left;
    color: #7a4a00;
    background: linear-gradient(180deg, rgba(255, 204, 0, 0.22), rgba(255, 149, 0, 0.14));
    cursor: pointer;
    animation: unlock-pulse 2.4s ease-in-out infinite;
}
.atafel__unlock-icon { font-size: 3.2vh; line-height: 1; }
.atafel__unlock-text { display: flex; flex-direction: column; gap: 0.3vh; }
.atafel__unlock-text strong { font-size: 2.1vh; font-weight: 700; }
.atafel__unlock-text small { font-size: 1.6vh; color: #9a6a2a; }

@keyframes unlock-pulse {
    0%, 100% { background: linear-gradient(180deg, rgba(255, 204, 0, 0.22), rgba(255, 149, 0, 0.14)); }
    50%      { background: linear-gradient(180deg, rgba(255, 204, 0, 0.38), rgba(255, 149, 0, 0.24)); }
}

/* ── Board ──────────────────────────────────────────────────── */
.atafel__board {
    flex: 1 1 auto;
    overflow-y: auto;
    padding: 1.6vh 3vh;
    scrollbar-width: none;
}
.atafel__board::-webkit-scrollbar { display: none; }

.atafel__list {
    display: flex;
    flex-direction: column;
    gap: 1.2vh;
}

/* ── Karte ──────────────────────────────────────────────────── */
.atafel__card {
    position: relative;
    background: #ffffff;
    border: 1px solid rgba(17, 24, 39, 0.05);
    border-radius: 1.8vh;
    padding: 1.5vh 2.4vh 1.5vh 3vh;
    box-shadow:
        0 0.2vh 0.6vh rgba(17, 24, 39, 0.04),
        0 1vh 2.4vh rgba(17, 24, 39, 0.07);
    overflow: hidden;
}
.atafel__card::before {
    content: "";
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 0.7vh;
    background: var(--accent, #007AFF);
}
.atafel__card--failed::before {
    background: linear-gradient(180deg, #ff9500, #ff3b30);
}

/* Neueste Notiz ("Letzter Eintrag") rot hervorheben, damit klar ist, worauf sich
   der Sprachbefehl "loesche den letzten Eintrag" bezieht. */
.atafel__card--latest {
    border-color: rgba(255, 59, 48, 0.35);
    background: linear-gradient(180deg, #fff5f4 0%, #ffffff 60%);
    box-shadow:
        0 0.2vh 0.6vh rgba(255, 59, 48, 0.08),
        0 1vh 2.4vh rgba(255, 59, 48, 0.14);
}
.atafel__card--latest::before {
    background: #ff3b30;
    width: 0.9vh;
}
.atafel__card--latest .atafel__sender {
    color: #d70015;
}

.atafel__card-head {
    display: flex;
    align-items: center;
    gap: 1.2vh;
    margin-bottom: 0.8vh;
}

.atafel__avatar {
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    width: 3.6vh;
    height: 3.6vh;
    border-radius: 50%;
    font-size: 1.6vh;
    font-weight: 700;
    color: #fff;
    text-transform: uppercase;
    box-shadow: 0 0.4vh 1vh rgba(17, 24, 39, 0.18);
}

.atafel__sender {
    font-size: 1.9vh;
    font-weight: 600;
    color: #1d1d1f;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.atafel__badge {
    flex: 0 0 auto;
    padding: 0.2vh 1vh;
    border-radius: 1vh;
    font-size: 1.3vh;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: #d70015;
    background: rgba(255, 59, 48, 0.12);
}

.atafel__time {
    margin-left: auto;
    font-size: 1.6vh;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    color: #a1a1a6;
    white-space: nowrap;
}

.atafel__done {
    flex: 0 0 auto;
    width: 3.4vh;
    height: 3.4vh;
    border: none;
    border-radius: 50%;
    font-size: 1.7vh;
    color: #86868b;
    background: rgba(0, 0, 0, 0.05);
    cursor: pointer;
    transition: background 0.2s, color 0.2s;
}
.atafel__done:hover { background: rgba(52, 199, 89, 0.18); color: #34c759; }

.atafel__text {
    margin: 0;
    font-size: 2.3vh;
    font-weight: 500;
    line-height: 1.3;
    color: #1d1d1f;
    word-break: break-word;
}

/* ── Leerzustand ────────────────────────────────────────────── */
.atafel__empty {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1.6vh;
    color: #b0b0b8;
}
.atafel__empty-icon {
    display: grid;
    place-items: center;
    width: 18vh;
    height: 18vh;
    font-size: 9vh;
    border-radius: 50%;
    color: #007AFF;
    background: rgba(0, 122, 255, 0.08);
}
.atafel__empty p {
    margin: 0;
    font-size: 3vh;
    font-weight: 600;
    color: #6e6e73;
}
.atafel__empty-hint {
    font-size: 2vh;
    color: #a1a1a6;
}

/* ── Animationen (neue/entfernte Notizen) ───────────────────── */
.note-enter-active {
    transition: opacity 0.45s ease, transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}
.note-enter-from {
    opacity: 0;
    transform: translateY(-3vh) scale(0.98);
}
.note-leave-active {
    transition: opacity 0.35s ease, transform 0.35s ease;
    position: relative;
}
.note-leave-to {
    opacity: 0;
    transform: translateX(6vh);
}
.note-move {
    transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}
</style>
