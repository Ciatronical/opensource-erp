// src/core/composables/useChat.js
// Mitarbeiter-Chat: gemeinsamer Zustand fuer Navbar-Button und Chat-Panel.
//
// Der Zustand liegt bewusst auf Modulebene (Singleton): Navbar-Badge, Panel und
// eventuelle weitere Consumer teilen sich eine SSE-Verbindung und eine Liste.
// Eingehende Nachrichten kommen ueber pg_notify('chat_message') -> SSE-Server.
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'
import i18n from '@/i18n'

// --- Gemeinsamer Zustand ---
export const panelOpen = ref(false)
export const conversations = ref([])
export const employees = ref([])
export const messages = ref([])
export const activeConversationId = ref(null)
// Neuer Chat: Kollege ist gewaehlt, die Unterhaltung entsteht erst mit der
// ersten Nachricht (kein Muell in der DB, wenn doch nicht geschrieben wird).
export const pendingEmployee = ref(null)
export const partnerReadId = ref(0)
export const loadingOverview = ref(false)
export const loadingMessages = ref(false)
export const sending = ref(false)
// Zaehler, den nur bewusste Benutzeraktionen hochsetzen (Klick auf den
// Chat-Button). Das Panel setzt daraufhin den Fokus ins Eingabefeld — beim
// automatischen Aufpoppen einer eingehenden Nachricht bewusst nicht, sonst
// landet der naechste Tastendruck ungewollt im Chat.
export const focusRequest = ref(0)

export const unreadTotal = computed(() =>
    conversations.value.reduce((sum, c) => sum + Number(c.unread || 0), 0)
)

export const activeConversation = computed(() =>
    conversations.value.find(c => c.id === activeConversationId.value) || null
)

/** Titel des aktiven Chats — im neuen Chat der gewaehlte Kollege */
export const activeTitle = computed(() => {
    if (pendingEmployee.value) return pendingEmployee.value.name
    const c = activeConversation.value
    if (!c) return ''
    return c.title || c.partner_names || ''
})

let eventSource = null
let started = false

function myEmployeeId() {
    return oserpStore().session?.logged_in_employee?.id || 0
}

// --- API ---

/** Unterhaltungen, Kollegenliste und Ungelesen-Zaehler laden (ein Call, eine Query) */
export async function loadOverview() {
    const employee_id = myEmployeeId()
    if (!employee_id) return
    loadingOverview.value = true
    try {
        const res = await axios.post('/api/chat/', { action: 'getChatOverview', employee_id })
        if (res.data?.success) {
            conversations.value = res.data.payload.conversations || []
            employees.value = res.data.payload.employees || []
        }
    } catch (err) {
        console.error('[Chat] Uebersicht konnte nicht geladen werden:', err)
    } finally {
        loadingOverview.value = false
    }
}

/** Unterhaltung oeffnen — laedt die Nachrichten und markiert sie als gelesen */
export async function openConversation(conversationId) {
    const employee_id = myEmployeeId()
    if (!employee_id || !conversationId) return
    activeConversationId.value = conversationId
    pendingEmployee.value = null
    loadingMessages.value = true
    try {
        const res = await axios.post('/api/chat/', {
            action: 'getChatMessages',
            conversation_id: conversationId,
            employee_id,
        })
        if (res.data?.success) {
            messages.value = res.data.payload.messages || []
            partnerReadId.value = Number(res.data.payload.partner_read_id || 0)
            // Zaehler lokal zuruecksetzen — das Backend hat bereits mitgelesen
            const conv = conversations.value.find(c => c.id === conversationId)
            if (conv) conv.unread = 0
        }
    } catch (err) {
        console.error('[Chat] Nachrichten konnten nicht geladen werden:', err)
    } finally {
        loadingMessages.value = false
    }
}

/** Chat mit einem Kollegen starten — bestehende Unterhaltung wird wiederverwendet */
export async function openChatWith(employee) {
    const existing = conversations.value.find(c =>
        !c.is_group && (c.partner_ids || []).length === 1 && c.partner_ids[0] === employee.id
    )
    if (existing) {
        await openConversation(existing.id)
        return
    }
    activeConversationId.value = null
    messages.value = []
    partnerReadId.value = 0
    pendingEmployee.value = employee
}

/** Zurueck zur Unterhaltungsliste */
export function closeConversation() {
    activeConversationId.value = null
    pendingEmployee.value = null
    messages.value = []
}

/** Nachricht senden — legt beim ersten Mal die Unterhaltung mit an */
export async function sendMessage(text) {
    const employee_id = myEmployeeId()
    const message = (text || '').trim()
    if (!employee_id || !message) return false

    sending.value = true
    try {
        const res = await axios.post('/api/chat/', {
            action: 'sendChatMessage',
            employee_id,
            message,
            conversation_id: activeConversationId.value || 0,
            to_employee_id: pendingEmployee.value?.id || 0,
        })
        if (!res.data?.success) return false

        const sent = res.data.payload.message
        activeConversationId.value = sent.conversation_id
        pendingEmployee.value = null
        if (!messages.value.some(m => m.id === sent.id)) {
            messages.value.push({
                id: sent.id,
                employee_id: sent.employee_id,
                employee_name: oserpStore().session?.logged_in_employee?.name || '',
                message: sent.message,
                itime: sent.itime,
            })
        }
        // Liste aktualisieren (letzte Nachricht, Reihenfolge, neue Unterhaltung)
        await loadOverview()
        return true
    } catch (err) {
        console.error('[Chat] Nachricht konnte nicht gesendet werden:', err)
        return false
    } finally {
        sending.value = false
    }
}

/** Eigene Nachricht loeschen */
export async function deleteMessage(id) {
    const employee_id = myEmployeeId()
    if (!employee_id || !id) return
    try {
        await axios.post('/api/chat/', { action: 'deleteChatMessage', id, employee_id })
        messages.value = messages.value.filter(m => m.id !== id)
        await loadOverview()
    } catch (err) {
        console.error('[Chat] Nachricht konnte nicht geloescht werden:', err)
    }
}

// --- Benachrichtigung ---

// Kurzer Hinweiston ohne Audiodatei — WebAudio reicht fuer zwei Toene und
// erspart ein Asset im Build.
function playNotificationSound() {
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext
        if (!Ctx) return
        const ctx = new Ctx()
        const gain = ctx.createGain()
        gain.gain.setValueAtTime(0.0001, ctx.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02)
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.45)
        gain.connect(ctx.destination)
        const osc = ctx.createOscillator()
        osc.type = 'sine'
        osc.frequency.setValueAtTime(660, ctx.currentTime)
        osc.frequency.setValueAtTime(880, ctx.currentTime + 0.12)
        osc.connect(gain)
        osc.start()
        osc.stop(ctx.currentTime + 0.5)
        osc.onended = () => ctx.close().catch(() => {})
    } catch { /* Ton ist nur Beiwerk */ }
}

// --- SSE ---

function handleChatEvent(payload) {
    const me = myEmployeeId()
    if (!me) return

    if (payload.action === 'read') {
        // Lesebestaetigung der Gegenseite
        if (payload.conversation_id === activeConversationId.value && payload.employee_id !== me) {
            partnerReadId.value = Math.max(partnerReadId.value, Number(payload.last_read_id || 0))
        }
        return
    }

    if (payload.action !== 'new') return
    const recipients = payload.recipients || []
    if (!recipients.includes(me)) return

    const own = payload.employee_id === me
    const isActive = payload.conversation_id === activeConversationId.value

    if (isActive) {
        // Offener Chat: Nachricht direkt anhaengen und als gelesen melden
        if (!messages.value.some(m => m.id === payload.id)) {
            messages.value.push({
                id: payload.id,
                employee_id: payload.employee_id,
                employee_name: payload.sender_name,
                message: payload.message,
                itime: payload.itime,
            })
        }
        if (!own) {
            if (payload.truncated) openConversation(payload.conversation_id)
            else markRead(payload.conversation_id)
            if (!panelOpen.value) playNotificationSound()
        }
        loadOverview()
        return
    }

    loadOverview()

    if (own) return   // eigene Nachricht aus einem anderen Tab

    playNotificationSound()
    toast.info(i18n.global.t('Chat.newMessageFrom', { name: payload.sender_name }))

    // Steckt der Benutzer gerade in einer anderen Unterhaltung, wird er nicht
    // herausgerissen — dort zeigt der Zaehler in der Liste die neue Nachricht.
    // Sonst oeffnet sich das Chatfenster direkt mit der Nachricht.
    if (activeConversationId.value) return
    panelOpen.value = true
    openConversation(payload.conversation_id)
}

/** Unterhaltung als gelesen markieren (ohne Nachladen der Nachrichten) */
export async function markRead(conversationId) {
    const employee_id = myEmployeeId()
    if (!employee_id || !conversationId) return
    try {
        await axios.post('/api/chat/', { action: 'markChatRead', conversation_id: conversationId, employee_id })
        const conv = conversations.value.find(c => c.id === conversationId)
        if (conv) conv.unread = 0
    } catch { /* nicht kritisch */ }
}

/** SSE-Verbindung und Erstdaten — wird einmalig von der Navbar gestartet */
export function startChat() {
    if (started) return
    started = true
    loadOverview()

    // Beim ersten Rendern der Navbar steht der Mitarbeiter evtl. noch nicht im
    // Store (Session wird parallel geladen) — dann nachziehen, sobald er da ist.
    watch(() => oserpStore().session?.logged_in_employee?.id, (id, old) => {
        if (id && id !== old) loadOverview()
    })

    if (typeof EventSource === 'undefined') return
    eventSource = new EventSource('/sse/events')
    eventSource.addEventListener('chat_message', (event) => {
        try {
            handleChatEvent(JSON.parse(event.data))
        } catch (err) {
            console.error('[Chat] SSE-Payload nicht lesbar:', err)
        }
    })
    eventSource.onerror = () => {
        // EventSource verbindet selbststaendig neu; beim naechsten Oeffnen des
        // Panels wird ohnehin frisch geladen.
    }
}

export function stopChat() {
    if (eventSource) { eventSource.close(); eventSource = null }
    started = false
    conversations.value = []
    employees.value = []
    messages.value = []
    activeConversationId.value = null
    pendingEmployee.value = null
    panelOpen.value = false
}

/** Panel umschalten — beim Oeffnen wird die Uebersicht aufgefrischt */
export function toggleChatPanel() {
    panelOpen.value = !panelOpen.value
    if (panelOpen.value) {
        loadOverview()
        focusRequest.value++
    }
}

export function useChat() {
    return {
        panelOpen, conversations, employees, messages, activeConversationId,
        activeConversation, activeTitle, pendingEmployee, partnerReadId,
        loadingOverview, loadingMessages, sending, unreadTotal, focusRequest,
        loadOverview, openConversation, openChatWith, closeConversation,
        sendMessage, deleteMessage, markRead, startChat, stopChat, toggleChatPanel,
    }
}
