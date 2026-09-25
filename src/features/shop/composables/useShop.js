// src/features/shop/composables/useShop.js
//
// Datenzugriff des Shop-Admin-Panels. Alle Aufrufe gehen an /api/shop/ und
// damit an den Mitarbeiter-Zugang der Erweiterung — der Kundenzugang unter
// /shop/ ist ein anderer Einstiegspunkt und von hier nicht erreichbar.

import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const API_URL = '/api/shop/'

/**
 * Code und lesbare Meldung aus einer Fehlerantwort des Backends
 *
 * Im Fehlerfall steht in `text` nur der Code (etwa API_DATABASE_ERROR), die
 * Einzelheiten in `payload` oder `debug`. Ist der Code übersetzt, steht die
 * Übersetzung vorn und die Einzelheit dahinter — Mitarbeiter brauchen sie, um
 * den Fehler weiterzugeben. Sonst bleibt nur die Einzelheit, im Notfall ein
 * allgemeiner Satz; der blanke Code nie.
 *
 * @param {object} antwort response.data einer Anfrage an /api/shop/
 * @param {object} i18n    { t, te } aus useI18n()
 * @param {string} ersatz  Übersetzungsschlüssel, wenn nichts Lesbares vorliegt
 * @returns {{code: string, text: string}}
 */
export function shopFehler(antwort, { t, te }, ersatz = 'ShopView.errors.API_ERROR') {
    // Keine JSON-Antwort: Netz weg oder der Server kam nicht bis zur Ausgabe
    if (!antwort || typeof antwort !== 'object') {
        return { code: 'NETWORK_ERROR', text: t('ShopView.errors.NETWORK_ERROR') }
    }
    // api.call.php hängt bei unerwarteten Fehlern die Meldung an den Code an
    const [code, ...rest] = String(antwort.text || 'API_ERROR').split(':')
    const detail = [antwort.debug, antwort.payload, rest.join(':')]
        .find(wert => typeof wert === 'string' && wert.trim() && wert !== code)
        ?.trim()
    const schluessel = `ShopView.errors.${code.trim()}`
    if (te(schluessel)) {
        return { code: code.trim(), text: detail ? `${t(schluessel)} (${detail})` : t(schluessel) }
    }
    return { code: code.trim(), text: detail || t(ersatz) }
}

export function useShop() {
    const i18n = useI18n()
    const loading = ref(false)
    const error = ref(null)
    const errorCode = ref(null)

    function fehlerSetzen(antwort) {
        const { code, text } = shopFehler(antwort, i18n)
        errorCode.value = code
        error.value = text
    }

    /**
     * Ruft eine Aktion des Shop-Backends auf
     *
     * Fehler landen in error und werden nicht geworfen: die Ansichten zeigen
     * sie an, statt mit einer leeren Seite abzubrechen. error ist schon der
     * lesbare Satz, errorCode der Code des Backends für Abfragen.
     */
    async function call(action, params = {}) {
        loading.value = true
        error.value = null
        errorCode.value = null
        try {
            const response = await axios.post(API_URL, { action, ...params })
            if (response.data?.success === false) {
                fehlerSetzen(response.data)
                return null
            }
            return response.data?.payload ?? null
        } catch (e) {
            fehlerSetzen(e?.response?.data)
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

    /** Bild für einen Marktplatz-Kanal hochladen (inhalt als data:-Adresse) */
    const uploadChannelImage = (parts_id, channel, filename, data) =>
        call('uploadShopChannelImage', { parts_id, channel, filename, data })

    /** Bild aus einem Marktplatz-Kanal entfernen */
    const deleteChannelImage = (image_id) => call('deleteShopChannelImage', { image_id })

    /** Reihenfolge der Bilder eines Marktplatz-Kanals setzen; das erste ist das Hauptbild */
    const sortChannelImages = (parts_id, channel, ids) => call('sortShopChannelImages', { parts_id, channel, ids })

    /** Bilder aus einem anderen Kanal übernehmen */
    const copyChannelImages = (parts_id, from, to) => call('copyShopChannelImages', { parts_id, from, to })

    /** Nimmt einen Artikel in die Veröffentlichung auf */
    const publishPart = (parts_id) => call('publishShopPart', { parts_id })

    /** Nimmt alle Artikel des Shops in die Veröffentlichung auf */
    const publishAll = () => call('publishShopAll')

    /** Offene und zuletzt erledigte Veröffentlichungs-Aufträge */
    const fetchPublishJobs = () => call('getShopPublishJobs')

    /**
     * Startet den Läufer im Hintergrund und kehrt sofort zurück
     *
     * Leere Liste heißt: alle offenen. Den Fortschritt liefert danach
     * fetchPublishStatus(). Läuft schon ein anderer Lauf, kommt
     * `started: false` zurück — er nimmt die Aufträge ohnehin mit.
     */
    const runPublishJobs = (ids = []) => call('runShopPublishJobs', { ids })

    /**
     * Installiert die Shop-Benutzerschnittstelle in der Webseite
     *
     * Paket abgleichen und die Webseite bauen — was sonst der Läufer im Cron
     * tut. Kehrt sofort zurück wie runPublishJobs(); `job_id` ist der
     * Auftrag, der dafür läuft.
     */
    const installShopUi = () => call('installShopUi')

    /**
     * Stand der Veröffentlichung
     *
     * running, starting, aborted, die Meldungen des laufenden oder letzten
     * Laufs und seine Bilanz — gleich, ob ihn der Cron oder das Panel
     * gestartet hat.
     */
    const fetchPublishStatus = () => call('getShopPublishStatus')

    /**
     * Löscht die erfolgreich erledigten Aufträge
     *
     * Fehlgeschlagene und offene bleiben stehen. Der Läufer räumt außerdem
     * regelmäßig nach der eingestellten Aufbewahrungsfrist.
     */
    const cleanupPublishJobs = () => call('cleanupShopPublishJobs')

    /**
     * Löscht ausgewählte erledigte Aufträge
     *
     * Für gelesene Fehlermeldungen. Offene bleiben stehen, auch wenn sie in
     * der Auswahl sind.
     */
    const deletePublishJobs = (ids = []) => call('deleteShopPublishJobs', { ids })

    return {
        loading,
        error,
        errorCode,
        publishPart,
        publishAll,
        fetchPublishJobs,
        runPublishJobs,
        installShopUi,
        fetchPublishStatus,
        cleanupPublishJobs,
        deletePublishJobs,
        fetchPartShopData,
        savePartShopData,
        deletePartShopData,
        uploadChannelImage,
        deleteChannelImage,
        sortChannelImages,
        copyChannelImages,
        fetchStatus,
        fetchOrders,
        fetchPendingPayments,
        reconcilePayments,
        fetchWithdrawals,
        setWithdrawalProcessed,
    }
}
