// src/features/banking/composables/useTransferTemplates.js

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/banking/'

// Platzhalter-Auflösung
export function resolvePurpose(template, referenceDate = new Date()) {
    const d     = referenceDate
    const pad   = n => String(n).padStart(2, '0')
    const month = pad(d.getMonth() + 1)
    const year  = String(d.getFullYear())
    const day   = pad(d.getDate())
    const monthNames = ['Januar','Februar','März','April','Mai','Juni',
                        'Juli','August','September','Oktober','November','Dezember']
    return template
        .replace(/\{MM\/JJJJ\}/g, `${month}/${year}`)
        .replace(/\{MM\/YYYY\}/g, `${month}/${year}`)
        .replace(/\{MM\}/g,       month)
        .replace(/\{JJJJ\}/g,     year)
        .replace(/\{YYYY\}/g,     year)
        .replace(/\{DATUM\}/g,    `${day}.${month}.${year}`)
        .replace(/\{MONAT\}/g,    monthNames[d.getMonth()])
}

export function useTransferTemplates() {
    const loading   = ref(false)
    const groups    = ref([])
    const templates = ref([])

    async function fetchGroups() {
        loading.value = true
        try {
            const r = await axios.post(API_URL, { action: 'getTransferTemplateGroups' })
            if (r.data.success) groups.value = r.data.payload.groups || []
        } finally { loading.value = false }
    }

    async function createGroup(data) {
        const r = await axios.post(API_URL, { action: 'createTransferTemplateGroup', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        return r.data.payload
    }

    async function updateGroup(data) {
        const r = await axios.post(API_URL, { action: 'updateTransferTemplateGroup', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function deleteGroup(id) {
        const r = await axios.post(API_URL, { action: 'deleteTransferTemplateGroup', id })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function fetchTemplates(groupId = null, bankAccountId = null) {
        loading.value = true
        try {
            const r = await axios.post(API_URL, {
                action: 'getTransferTemplates',
                group_id: groupId,
                bank_account_id: bankAccountId
            })
            if (r.data.success) templates.value = r.data.payload.templates || []
        } finally { loading.value = false }
    }

    async function createTemplate(data) {
        const r = await axios.post(API_URL, { action: 'createTransferTemplate', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
        return r.data.payload
    }

    async function updateTemplate(data) {
        const r = await axios.post(API_URL, { action: 'updateTransferTemplate', ...data })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    async function deleteTemplate(id) {
        const r = await axios.post(API_URL, { action: 'deleteTransferTemplate', id })
        if (!r.data.success) throw new Error(r.data.payload || r.data.text)
    }

    // Überweisungen aus aufgelösten Templates anlegen
    async function createTransfersFromTemplates(transferList) {
        loading.value = true
        try {
            const r = await axios.post(API_URL, {
                action: 'createTransfersFromTemplates',
                transfers: transferList
            })
            if (!r.data.success) throw new Error(r.data.payload || r.data.text)
            return r.data.payload // { created, transfer_ids }
        } finally { loading.value = false }
    }

    return {
        loading, groups, templates,
        fetchGroups, createGroup, updateGroup, deleteGroup,
        fetchTemplates, createTemplate, updateTemplate, deleteTemplate,
        createTransfersFromTemplates
    }
}
