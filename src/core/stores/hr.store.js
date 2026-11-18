// src/core/stores/hr.store.js

import { defineStore } from 'pinia'
import axios from 'axios'
import { ApiError } from '@/core/utils/error.js'

export const hrStore = defineStore('hrStore', () => {

    async function post(action, payload = {}) {
        const response = await axios.post('/api/hr/', { action, ...payload })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, typeof response.data.payload === 'string' ? response.data.payload : '')
        }
        return response.data.payload
    }

    // Dashboard
    async function fetchDashboard(employee_id) {
        return (await post('getHrDashboard', { employee_id })).result
    }

    // Gehaltseinstellungen
    async function fetchSalarySettings() {
        return (await post('getSalarySettings')).result.employees
    }

    async function saveSalarySettings(data) {
        return await post('saveSalarySettings', data)
    }

    // Lohnabrechnungsläufe
    async function fetchPayrollRuns() {
        return (await post('getPayrollRuns')).result.runs
    }

    async function fetchPayrollRun(id) {
        return (await post('getPayrollRun', { id })).result
    }

    async function createPayrollRun(data) {
        return await post('createPayrollRun', data)
    }

    async function updatePayrollItem(data) {
        return await post('updatePayrollItem', data)
    }

    async function finalizePayrollRun(id, employee_id) {
        return await post('finalizePayrollRun', { id, employee_id })
    }

    async function deletePayrollRun(id) {
        return await post('deletePayrollRun', { id })
    }

    // Urlaubsplanung
    async function fetchVacationOverview(year) {
        return (await post('getVacationOverview', { year })).result
    }

    async function saveVacationRequest(data) {
        return await post('saveVacationRequest', data)
    }

    async function approveVacationRequest(id, employee_id) {
        return await post('approveVacationRequest', { id, employee_id })
    }

    async function rejectVacationRequest(id, employee_id, rejection_reason) {
        return await post('rejectVacationRequest', { id, employee_id, rejection_reason })
    }

    async function deleteVacationRequest(id, employee_id) {
        return await post('deleteVacationRequest', { id, employee_id })
    }

    async function saveVacationEntitlement(data) {
        return await post('saveVacationEntitlement', data)
    }

    // Lohnsteuer-PAP
    async function fetchLohnsteuerTableStatus() {
        return (await post('getLohnsteuerTableStatus')).result
    }

    async function buildLohnsteuerTable(data) {
        return await post('buildLohnsteuerTable', data)
    }

    async function fetchLohnsteuerTable(year, steuerklasse) {
        return (await post('getLohnsteuerTable', { year, steuerklasse })).result
    }

    async function calculatePayrollTaxes(run_id, kirchensteuer = false, year = null) {
        return await post('calculatePayrollTaxes', { run_id, kirchensteuer, year })
    }

    async function previewLohnsteuer(data) {
        return await post('previewLohnsteuer', data)
    }

    // Leistung (LxCars): Mechaniker-Kennzahlen, eine Abfrage für alle Mechaniker
    async function fetchMechanicPerformance(params) {
        const response = await axios.post('/api/lxcars/', { action: 'getMechanicPerformance', ...params })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload.result
    }

    // Regelarbeitszeit (Sollstunden je Wochentag) eines Mitarbeiters speichern
    async function saveEmployeeWorkHours(params) {
        const response = await axios.post('/api/lxcars/', { action: 'saveEmployeeWorkHours', ...params })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
        }
        return response.data.payload
    }

    // ── Personalverwaltung: Fragebogen, Personalakte, Schreiben ──
    // employee_id ist hier immer der Mitarbeiter, dessen Akte bearbeitet wird.

    async function fetchPersonnelOverview(include_inactive = false) {
        return (await post('getPersonnelOverview', { include_inactive })).results
    }

    async function fetchPersonnelFile(employee_id) {
        return (await post('getPersonnelFile', { employee_id })).results
    }

    async function savePersonnelData(employee_id, data) {
        return (await post('savePersonnelData', { employee_id, data })).results
    }

    async function fetchPersonnelQuestionnairePdf(employee_id, blank = false) {
        return (await post('getPersonnelQuestionnairePdf', { employee_id, blank })).results
    }

    // Upload als multipart: der Axios-Interceptor lässt FormData unangetastet
    async function uploadPersonnelDocument(fields, file) {
        const formData = new FormData()
        formData.append('action', 'uploadPersonnelDocument')
        Object.entries(fields).forEach(([key, value]) => formData.append(key, value ?? ''))
        formData.append('file', file)
        const response = await axios.post('/api/hr/', formData)
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, typeof response.data.payload === 'string' ? response.data.payload : '')
        }
        return response.data.payload.results
    }

    async function updatePersonnelDocument(data) {
        return (await post('updatePersonnelDocument', data)).results
    }

    async function deletePersonnelDocument(id) {
        return await post('deletePersonnelDocument', { id })
    }

    function personnelDocumentUrl(id, download = false) {
        return '/api/hr/?action=getPersonnelDocument&id=' + encodeURIComponent(id) + (download ? '&download=1' : '')
    }

    async function savePersonnelTemplate(data) {
        return (await post('savePersonnelTemplate', data)).results
    }

    async function deletePersonnelTemplate(id) {
        return await post('deletePersonnelTemplate', { id })
    }

    async function fetchPersonnelLetterDraft(employee_id, template) {
        return (await post('getPersonnelLetterDraft', {
            employee_id, template_id: template.id || 0, default_key: template.id ? '' : template.default_key
        })).results
    }

    async function fetchPersonnelLetterPdf(employee_id, letter) {
        return (await post('getPersonnelLetterPdf', { employee_id, letter })).results
    }

    async function savePersonnelLetter(employee_id, category, title, letter) {
        return (await post('savePersonnelLetter', { employee_id, category, title, letter })).results
    }

    return {
        fetchDashboard,
        fetchSalarySettings, saveSalarySettings,
        fetchPayrollRuns, fetchPayrollRun, createPayrollRun,
        updatePayrollItem, finalizePayrollRun, deletePayrollRun,
        fetchVacationOverview, saveVacationRequest,
        approveVacationRequest, rejectVacationRequest, deleteVacationRequest,
        saveVacationEntitlement,
        fetchLohnsteuerTableStatus, buildLohnsteuerTable, fetchLohnsteuerTable,
        calculatePayrollTaxes, previewLohnsteuer,
        fetchMechanicPerformance, saveEmployeeWorkHours,
        fetchPersonnelOverview, fetchPersonnelFile, savePersonnelData, fetchPersonnelQuestionnairePdf,
        uploadPersonnelDocument, updatePersonnelDocument, deletePersonnelDocument, personnelDocumentUrl,
        savePersonnelTemplate, deletePersonnelTemplate,
        fetchPersonnelLetterDraft, fetchPersonnelLetterPdf, savePersonnelLetter
    }
})
