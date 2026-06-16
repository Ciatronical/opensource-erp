// src/features/weroni/stores/weroni.store.js

import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'

export const weroniStore = defineStore('weroniStore', () => {
    const panelOpen = ref(false)
    const activeTab = ref('chat')
    const pendingQuestionCount = ref(0)
    const sessionId = ref('weroni_' + Date.now())

    async function sendMessage(message) {
        const response = await axios.post('/api/weroni/', {
            action: 'weroniChat',
            message,
            session_id: sessionId.value
        })
        if (!response.data.success) throw new Error(response.data.payload || response.data.text)
        return response.data.payload
    }

    async function loadConversation() {
        const response = await axios.post('/api/weroni/', {
            action: 'getWeroniConversation',
            session_id: sessionId.value
        })
        if (!response.data.success) throw new Error(response.data.text)
        return response.data.payload
    }

    async function clearConversation() {
        await axios.post('/api/weroni/', {
            action: 'clearWeroniConversation',
            session_id: sessionId.value
        })
        sessionId.value = 'weroni_' + Date.now()
    }

    async function loadTasks(includeDone = false) {
        const response = await axios.post('/api/weroni/', {
            action: 'getWeroniTasks',
            include_done: includeDone
        })
        if (!response.data.success) throw new Error(response.data.text)
        return response.data.payload
    }

    async function loadQuestions() {
        const response = await axios.post('/api/weroni/', {
            action: 'getWeroniQuestions'
        })
        if (!response.data.success) throw new Error(response.data.text)
        return response.data.payload
    }

    async function answerQuestion(questionId, answer) {
        const response = await axios.post('/api/weroni/', {
            action: 'answerWeroniQuestion',
            question_id: questionId,
            answer
        })
        if (!response.data.success) throw new Error(response.data.text)
        await fetchPendingCount()
        return response.data
    }

    async function fetchPendingCount() {
        try {
            const response = await axios.post('/api/weroni/', {
                action: 'getWeroniPendingCount'
            })
            if (response.data.success) {
                pendingQuestionCount.value = response.data.payload.count
            }
        } catch { /* leise */ }
    }

    async function loadActions(limit = 20) {
        const response = await axios.post('/api/weroni/', {
            action: 'getWeroniActions',
            limit
        })
        if (!response.data.success) throw new Error(response.data.text)
        return response.data.payload
    }

    async function confirmTask(taskId) {
        const response = await axios.post('/api/weroni/', { action: 'confirmWeroniTask', task_id: taskId })
        if (!response.data.success) throw new Error(response.data.text)
        return response.data
    }

    async function rejectTask(taskId, reason = '') {
        const response = await axios.post('/api/weroni/', { action: 'rejectWeroniTask', task_id: taskId, reason })
        if (!response.data.success) throw new Error(response.data.text)
        return response.data
    }

    async function analyzeDocument(fileBase64, filename, mimeType, message = '') {
        const response = await axios.post('/api/weroni/', {
            action: 'weroniAnalyzeDocument',
            file_base64: fileBase64,
            filename,
            mime_type: mimeType,
            session_id: sessionId.value,
            message
        })
        if (!response.data.success) throw new Error(response.data.payload || response.data.text)
        return response.data.payload
    }

    function togglePanel() {
        panelOpen.value = !panelOpen.value
    }

    return {
        panelOpen, activeTab, pendingQuestionCount, sessionId,
        sendMessage, loadConversation, clearConversation,
        loadTasks, loadQuestions, answerQuestion,
        fetchPendingCount, loadActions, analyzeDocument,
        confirmTask, rejectTask, togglePanel
    }
})
