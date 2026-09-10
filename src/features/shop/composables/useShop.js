// src/features/shop/composables/useShop.js
//
// Datenzugriff des Shop-Admin-Panels. Alle Aufrufe gehen an /api/shop/ und
// damit an den Mitarbeiter-Zugang der Erweiterung — der Kundenzugang unter
// /shop/ ist ein anderer Einstiegspunkt und von hier nicht erreichbar.

import { ref } from 'vue'
import axios from 'axios'

const API_URL = '/api/shop/'

export function useShop() {
    const loading = ref(false)
    const error = ref(null)

    /**
     * Ruft eine Aktion des Shop-Backends auf
     *
     * Fehler landen in error und werden nicht geworfen: die Ansichten zeigen
     * sie an, statt mit einer leeren Seite abzubrechen.
     */
    async function call(action, params = {}) {
        loading.value = true
        error.value = null
        try {
            const response = await axios.post(API_URL, { action, ...params })
            if (response.data?.success === false) {
                error.value = response.data.debug || response.data.text || 'FEHLER'
                return null
            }
            return response.data?.payload ?? null
        } catch (e) {
            error.value = e?.message || 'NETZWERKFEHLER'
            return null
        } finally {
            loading.value = false
        }
    }

    /** Einrichtungsstand: was fehlt, was schränkt ein */
    const fetchStatus = () => call('getShopStatus')

    /** Bestellungen des Shops */
    const fetchOrders = (params = {}) => call('getShopOrders', params)

    /** Rechnungen mit schwebender PayPal-Zahlung */
    const fetchPendingPayments = () => call('getPendingPayments')

    /** Fragt die schwebenden Zahlungen bei PayPal nach */
    const reconcilePayments = () => call('reconcileShopPayments')

    /** Eingegangene Widerrufe */
    const fetchWithdrawals = (params = {}) => call('getShopWithdrawals', params)

    /** Merkt einen Widerruf als bearbeitet vor */
    const setWithdrawalProcessed = (id, processed) =>
        call('setShopWithdrawalProcessed', { id, processed })

    /** Shop-Angaben eines Artikels; listed = steht im Shop */
    const fetchPartShopData = (parts_id) => call('getPartShopData', { parts_id })

    /** Speichert die Shop-Angaben — und nimmt den Artikel damit in den Shop */
    const savePartShopData = (daten) => call('savePartShopData', daten)

    /** Nimmt den Artikel aus dem Shop */
    const deletePartShopData = (parts_id) => call('deletePartShopData', { parts_id })

    return {
        loading,
        error,
        fetchPartShopData,
        savePartShopData,
        deletePartShopData,
        fetchStatus,
        fetchOrders,
        fetchPendingPayments,
        reconcilePayments,
        fetchWithdrawals,
        setWithdrawalProcessed,
    }
}
