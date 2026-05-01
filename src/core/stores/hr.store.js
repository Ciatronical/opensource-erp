// src/core/stores/hr.store.js

import { defineStore } from 'pinia'
import axios from 'axios'
import { ApiError } from '@/core/utils/error.js'

export const hrStore = defineStore('hrStore', () => {

    async function post(action, payload = {}) {
        const response = await axios.post('/api/hr/', { action, ...payload })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text)
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

    return {
        fetchDashboard,
        fetchSalarySettings, saveSalarySettings,
        fetchPayrollRuns, fetchPayrollRun, createPayrollRun,
        updatePayrollItem, finalizePayrollRun, deletePayrollRun,
        fetchVacationOverview, saveVacationRequest,
        approveVacationRequest, rejectVacationRequest, deleteVacationRequest,
        saveVacationEntitlement,
        fetchLohnsteuerTableStatus, buildLohnsteuerTable, fetchLohnsteuerTable,
        calculatePayrollTaxes, previewLohnsteuer
    }
})
