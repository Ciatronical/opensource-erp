<!-- src/core/views/tafel/tafel.view.vue -->
<!--
    Tafel-Verwaltung (PC-Variante der Anschlagtafel).
    Hinzufuegen, Loeschen und Umsortieren (Drag & Drop) von Notizen direkt vom PC —
    bisher ging das nur per Telegram-Sprachnachricht. Nutzt dieselbe Datenquelle
    (voice_notes) und denselben SSE-Kanal (voicenote_change) wie die Anschlagtafel
    auf dem TV. Ein hier hinzugefuegter Eintrag erscheint live auf dem TV, ein hier
    geloeschter verschwindet dort, und die Reihenfolge wird auf dem TV live uebernommen.
-->
<template>
    <NavbarView />

    <v-container class="tafel-page py-4" style="max-width: 820px;">
        <!-- Kopfzeile -->
        <div class="d-flex align-center flex-wrap mb-4">
            <v-icon size="32" color="primary" class="me-2">mdi-bulletin-board</v-icon>
            <h1 class="text-h5 font-weight-bold mb-0">{{ t('Tafel.title') }}</h1>
            <v-chip
                class="ms-3"
                size="small"
                :color="sseConnected ? 'success' : 'grey'"
                variant="tonal"
            >
                <span class="tafel-dot" :class="{ 'tafel-dot--live': sseConnected }" />
                {{ sseConnected ? t('Tafel.online') : t('Tafel.offline') }}
            </v-chip>
            <v-spacer />
            <v-btn
                variant="tonal"
                color="primary"
                prepend-icon="mdi-television-play"
                :to="{ name: 'anschlagtafel' }"
            >
                {{ t('Tafel.openBoard') }}
            </v-btn>
        </div>

        <!-- Neuer Eintrag -->
        <v-card class="mb-6 tafel-composer" rounded="xl" elevation="2">
            <v-card-text class="pb-2">
                <v-autocomplete
                    v-model="selectedEmployeeId"
                    :items="employees"
                    item-title="name"
                    item-value="id"
                    :label="t('Tafel.senderLabel')"
                    prepend-inner-icon="mdi-account"
                    variant="solo-filled"
                    density="comfortable"
                    flat
                    hide-details
                    auto-select-first
                    class="mb-3"
                />
                <v-textarea
                    v-model="newText"
                    :label="t('Tafel.textLabel')"
                    :placeholder="t('Tafel.textPlaceholder')"
                    variant="solo-filled"
                    density="comfortable"
                    flat
                    rows="2"
                    auto-grow
                    hide-details
                    @keydown.enter.exact.prevent="addNote"
                />
            </v-card-text>
            <v-card-actions class="px-4 pb-3 pt-0">
                <span class="text-caption text-medium-emphasis">{{ t('Tafel.enterHint') }}</span>
                <v-spacer />
                <v-btn
                    color="primary"
                    variant="flat"
                    rounded="lg"
                    prepend-icon="mdi-plus"
                    :loading="adding"
                    :disabled="!newText.trim()"
                    @click="addNote"
                >
                    {{ t('Tafel.addButton') }}
                </v-btn>
            </v-card-actions>
        </v-card>

        <!-- Liste -->
        <div v-if="loading" class="text-center py-10">
            <v-progress-circular indeterminate color="primary" />
        </div>

        <v-sheet
            v-else-if="notes.length === 0"
            rounded="xl"
            class="text-center py-12 px-4 tafel-empty"
            color="transparent"
        >
            <v-icon size="56" color="grey-lighten-1">mdi-bulletin-board</v-icon>
            <div class="text-h6 mt-3 text-medium-emphasis">{{ t('Tafel.empty') }}</div>
            <div class="text-body-2 text-disabled mt-1">{{ t('Tafel.emptyHint') }}</div>
        </v-sheet>

        <template v-else>
            <div class="d-flex align-center mb-2 px-1">
                <span class="text-caption text-medium-emphasis">
                    {{ t('Tafel.count', { n: notes.length }) }}
                </span>
                <v-spacer />
                <span class="text-caption text-disabled d-flex align-center">
                    <v-icon size="14" class="me-1">mdi-drag-horizontal-variant</v-icon>
                    {{ t('Tafel.dragHint') }}
                </span>
            </div>

            <draggable
                :list="notes"
                item-key="id"
                handle=".tafel-handle"
                :animation="180"
                ghost-class="tafel-ghost"
                chosen-class="tafel-chosen"
                drag-class="tafel-drag"
                @change="onDragChange"
            >
                <template #item="{ element: note }">
                    <v-card class="tafel-card mb-2" rounded="lg" variant="flat" border>
                        <div class="d-flex align-center pa-3">
                            <v-icon
                                class="tafel-handle me-2"
                                color="grey-lighten-1"
                                :title="t('Tafel.dragHint')"
                            >
                                mdi-drag-vertical
                            </v-icon>
                            <v-avatar
                                :color="avatarColor(note.sender_name)"
                                size="42"
                                class="me-3 text-white font-weight-bold flex-shrink-0"
                            >
                                {{ initials(note.sender_name) }}
                            </v-avatar>
                            <div class="flex-grow-1" style="min-width: 0;">
                                <div class="d-flex align-center flex-wrap ga-2">
                                    <span class="font-weight-medium">
                                        {{ note.sender_name || t('Tafel.unknown') }}
                                    </span>
                                    <span class="text-caption text-medium-emphasis">
                                        {{ formatTime(note.itime) }}
                                    </span>
                                    <v-chip
                                        v-if="note.status === 'failed'"
                                        size="x-small"
                                        color="warning"
                                        variant="flat"
                                    >
                                        {{ t('Tafel.failed') }}
                                    </v-chip>
                                </div>
                                <div class="tafel-text mt-1">{{ note.transcript }}</div>
                            </div>
                            <v-btn
                                icon="mdi-delete-outline"
                                variant="text"
                                color="medium-emphasis"
                                size="small"
                                class="ms-2 tafel-delete flex-shrink-0"
                                :title="t('Tafel.delete')"
                                @click="removeNote(note)"
                            />
                        </div>
                    </v-card>
                </template>
            </draggable>
        </template>
    </v-container>
</template>

<script>
import { defineComponent, ref, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import draggable from 'vuedraggable'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as alerts from '@/core/utils/alerts.js'

export default defineComponent({
    name: 'TafelView',
    components: { NavbarView, draggable },
    setup() {
        const { t } = useI18n()
        const oserp = oserpStore()

        const notes = ref([])
        const employees = ref([])
        const selectedEmployeeId = ref(null)
        const loading = ref(true)
        const adding = ref(false)
        const sseConnected = ref(false)
        const newText = ref('')

        let sseSource = null
        let pollInterval = null

        // ── Daten laden ──

        async function loadNotes() {
            try {
                const res = await axios.post('/api/voicenotes/', {
                    action: 'getRecentVoiceNotes',
                    limit: 100,
                })
                if (res.data.success) {
                    notes.value = res.data.payload.notes || []
                }
            } catch { /* Netzwerkfehler ignorieren, Polling/SSE versucht es erneut */ }
            finally {
                loading.value = false
            }
        }

        async function loadEmployees() {
            try {
                const res = await axios.post('/api/voicenotes/', { action: 'getVoiceNoteEmployees' })
                if (res.data.success) {
                    employees.value = res.data.payload.employees || []
                    // Vorbelegung: der eingeloggte Mitarbeiter.
                    const me = employees.value.find(e => e.login && e.login === oserp.session.user)
                    selectedEmployeeId.value = me ? me.id : (employees.value[0]?.id ?? null)
                }
            } catch { /* ignorieren */ }
        }

        function selectedSenderName() {
            const emp = employees.value.find(e => e.id === selectedEmployeeId.value)
            return emp ? emp.name : (oserp.session.user || '')
        }

        // ── Hinzufuegen ──

        async function addNote() {
            const text = newText.value.trim()
            if (!text || adding.value) return
            adding.value = true
            try {
                const res = await axios.post('/api/voicenotes/', {
                    action: 'addVoiceNote',
                    transcript: text,
                    sender_name: selectedSenderName(),
                })
                if (res.data.success) {
                    const note = res.data.payload.note
                    // Optimistisch oben einfuegen (Dedup gegen das SSE-'new'-Event).
                    if (note && !notes.value.some(n => n.id === note.id)) {
                        notes.value.unshift(note)
                    }
                    newText.value = ''
                } else {
                    alerts.error(t('Tafel.addError'))
                }
            } catch {
                alerts.error(t('Tafel.addError'))
            } finally {
                adding.value = false
            }
        }

        // ── Loeschen ──

        async function removeNote(note) {
            const res = await alerts.question(
                t('Tafel.confirmDeleteText'),
                t('Tafel.confirmDeleteTitle'),
                t('Tafel.delete'),
                t('Tafel.cancel'),
            )
            if (!res.isConfirmed) return
            try {
                await axios.post('/api/voicenotes/', { action: 'hideVoiceNote', id: note.id })
                notes.value = notes.value.filter(n => n.id !== note.id)
            } catch {
                alerts.error(t('Tafel.deleteError'))
            }
        }

        // ── Umsortieren (Drag & Drop) ──

        async function onDragChange(evt) {
            if (!evt.moved) return
            const order = notes.value.map(n => n.id)
            try {
                await axios.post('/api/voicenotes/', { action: 'reorderVoiceNotes', ids: order })
            } catch {
                alerts.error(t('Tafel.reorderError'))
                loadNotes() // bei Fehler serverseitige Reihenfolge wiederherstellen
            }
        }

        // Lokale Reihenfolge nach der vom Server gemeldeten Reihenfolge ausrichten.
        function applyOrder(order) {
            if (!Array.isArray(order)) return
            const rank = new Map(order.map((id, i) => [id, i]))
            notes.value = [...notes.value].sort((a, b) =>
                (rank.has(a.id) ? rank.get(a.id) : Infinity) -
                (rank.has(b.id) ? rank.get(b.id) : Infinity)
            )
        }

        // ── SSE (Live-Sync mit TV, Telegram und anderen PCs) ──

        function connectSSE() {
            if (typeof EventSource === 'undefined') {
                startPolling()
                return
            }
            sseSource = new EventSource('/sse/events')
            sseSource.onopen = () => {
                sseConnected.value = true
                stopPolling()
                // Bei (Wieder-)Verbindung mit dem DB-Stand abgleichen.
                loadNotes()
            }
            sseSource.addEventListener('voicenote_change', (e) => {
                sseConnected.value = true
                try {
                    const d = JSON.parse(e.data)
                    if (d.action === 'removed') {
                        notes.value = notes.value.filter(n => n.id !== d.id)
                    } else if (d.action === 'updated') {
                        const n = notes.value.find(x => x.id === d.id)
                        if (n) {
                            n.transcript = d.transcript
                            if (d.status) n.status = d.status
                        }
                    } else if (d.action === 'cleared') {
                        notes.value = []
                    } else if (d.action === 'reordered') {
                        applyOrder(d.order)
                    } else {
                        // Neue Notiz (z.B. per Telegram) oben einfuegen, Duplikate vermeiden.
                        if (!notes.value.some(n => n.id === d.id)) {
                            notes.value.unshift(d)
                            if (notes.value.length > 100) notes.value.pop()
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

        function initials(name) {
            if (!name) return '?'
            const parts = String(name).trim().split(/\s+/).filter(Boolean)
            if (parts.length === 0) return '?'
            if (parts.length === 1) return parts[0].charAt(0).toUpperCase()
            return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase()
        }

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

        onMounted(() => {
            loadEmployees()
            loadNotes()
            connectSSE()
        })

        onBeforeUnmount(() => {
            if (sseSource) sseSource.close()
            stopPolling()
        })

        return {
            t, notes, employees, selectedEmployeeId, loading, adding, sseConnected, newText,
            addNote, removeNote, onDragChange, formatTime, initials, avatarColor,
        }
    },
})
</script>

<style scoped>
.tafel-page {
    min-height: 60vh;
}

/* Live-Punkt im Status-Chip */
.tafel-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
    background: currentColor;
    opacity: 0.5;
}
.tafel-dot--live {
    opacity: 1;
    animation: tafel-pulse 1.8s ease-in-out infinite;
}
@keyframes tafel-pulse {
    0%, 100% { transform: scale(1);   opacity: 1; }
    50%      { transform: scale(1.4); opacity: 0.6; }
}

.tafel-composer {
    background: rgb(var(--v-theme-surface));
}

.tafel-text {
    font-size: 1rem;
    line-height: 1.4;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Karten: dezent, beim Hover leicht anheben */
.tafel-card {
    transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.06s ease;
}
.tafel-card:hover {
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
}
.tafel-handle {
    cursor: grab;
    touch-action: none;
}
.tafel-handle:active {
    cursor: grabbing;
}

/* Loeschen erst beim Hover deutlich zeigen (aufgeraeumter Look) */
.tafel-delete {
    opacity: 0.35;
    transition: opacity 0.2s ease;
}
.tafel-card:hover .tafel-delete {
    opacity: 1;
}

/* Drag-Zustaende */
.tafel-ghost {
    opacity: 0.4;
}
.tafel-ghost .tafel-card {
    border-style: dashed !important;
    border-color: rgb(var(--v-theme-primary)) !important;
    background: rgba(var(--v-theme-primary), 0.06);
}
.tafel-chosen .tafel-card {
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
}
.tafel-drag {
    cursor: grabbing;
}
</style>
