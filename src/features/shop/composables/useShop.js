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
    /** Nutzdaten einer Fehlerantwort (Kleinanzeigen-Dienst: Bildschirmfoto, Adresse) */
    const errorPayload = ref(null)

    function fehlerSetzen(antwort) {
        const { code, text } = shopFehler(antwort, i18n)
        errorCode.value = code
        error.value = text
        errorPayload.value = antwort?.payload ?? null
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
        errorPayload.value = null
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

    // ── Kleinanzeigen (dev/shop-kleinanzeigen.md) ──
    /** Board des Kanals: Kennzahlen, Vorschläge, Entwürfe, Online, Verkauft, Anfragen */
    const fetchKleinanzeigenBoard = (channel_id) => call('getKleinanzeigenBoard', channel_id ? { channel_id } : {})
    const fetchKleinanzeigenAd = (parts_id, channel_id) => call('getKleinanzeigenAd', { parts_id, channel_id })
    const saveKleinanzeigenAd = (daten) => call('saveKleinanzeigenAd', daten)
    const addKleinanzeigenParts = (parts_ids, channel_id) => call('addKleinanzeigenParts', { parts_ids, channel_id })
    const setKleinanzeigenAdState = (parts_id, channel_id, state, url = '') =>
        call('setKleinanzeigenAdState', { parts_id, channel_id, state, url })
    const sellKleinanzeigenAd = (daten) => call('sellKleinanzeigenAd', daten)
    const setKleinanzeigenLeadStatus = (lead_id, daten) => call('setKleinanzeigenLeadStatus', { lead_id, ...daten })
    const importKleinanzeigenLeads = (channel_id) => call('importKleinanzeigenLeads', { channel_id })
    const generateKleinanzeigenText = (parts_id, channel_id, hint, ai_model) =>
        call('generateKleinanzeigenText', { parts_id, channel_id, hint, ai_model })
    const suggestKleinanzeigenReply = (lead_id, ai_model) => call('suggestKleinanzeigenReply', { lead_id, ai_model })
    const fetchKleinanzeigenImagesZip = (parts_id, channel_id) => call('getKleinanzeigenImagesZip', { parts_id, channel_id })
    const fetchKleinanzeigenFeedUrl = (channel_id) => call('getKleinanzeigenFeedUrl', { channel_id })
    // Kleinanzeigen-Dienst (Headless-Browser, dev/shop-kleinanzeigen.md Teil 2)
    const fetchKleinanzeigenWorkerStatus = (channel_id, check = false) => call('getKleinanzeigenWorkerStatus', { channel_id, check })
    const loginKleinanzeigen = (channel_id) => call('loginKleinanzeigen', { channel_id })
    const confirmKleinanzeigenLogin = (channel_id, code) => call('confirmKleinanzeigenLogin', { channel_id, code })
    const openKleinanzeigenLogin = (channel_id) => call('openKleinanzeigenLogin', { channel_id })
    const remoteKleinanzeigenBrowser = (channel_id, op, extra = {}) => call('remoteKleinanzeigenBrowser', { channel_id, op, ...extra })
    const publishKleinanzeigenAd = (parts_id, channel_id) => call('publishKleinanzeigenAd', { parts_id, channel_id })
    const deleteKleinanzeigenAd = (parts_id, channel_id) => call('deleteKleinanzeigenAd', { parts_id, channel_id })
    const extendKleinanzeigenAd = (parts_id, channel_id) => call('extendKleinanzeigenAd', { parts_id, channel_id })
    const syncKleinanzeigenAds = (channel_id) => call('syncKleinanzeigenAds', { channel_id })
    const replyKleinanzeigenLead = (lead_id, text) => call('replyKleinanzeigenLead', { lead_id, text })

    /** Einrichtungsstand: was fehlt, was schränkt ein */
    const fetchStatus = () => call('getShopStatus')

    /** Bestellungen des Shops */
    const fetchOrders = (params = {}) => call('getShopOrders', params)

    /**
     * Bestell- oder Lieferstatus von Hand setzen (dev/shop-bestellstatus.md)
     *
     * @param {number} ar_id Rechnung der Bestellung
     * @param {string} kind 'order' oder 'delivery'
     * @param {string} status neuer Status, '' = automatisch
     */
    const setOrderStatus = (ar_id, kind, status) => call('setShopOrderStatus', { ar_id, kind, status })

    /** Bestell- und Lieferstatus einer Rechnung, null wenn sie keine Shop-Bestellung ist */
    const fetchOrderStatus = (ar_id) => call('getShopOrderStatus', { ar_id })

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
    const publishAll = (channel_ids = []) => call('publishShopAll', { channel_ids })

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
    const installPortalUi = (channel_ids = [], start = true) => call('installPortalUi', { channel_ids, start })

    /**
     * Stand der Veröffentlichung
     *
     * running, starting, aborted, die Meldungen des laufenden oder letzten
     * Laufs und seine Bilanz — gleich, ob ihn der Cron oder das Panel
     * gestartet hat.
     */
    const fetchPublishStatus = () => call('getShopPublishStatus')

    /**
     * Ausgabe des Laufs, in dem ein Auftrag erledigt wurde
     *
     * id, itime, finished und output (Zeilen, getrennt durch Zeilenumbruch).
     */
    const fetchPublishRunOutput = (id) => call('getShopPublishRunOutput', { id })

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

    /** Öffnet fehlgeschlagene Aufträge wieder; run = sofort ausführen, sonst für den Cron */
    const retryPublishJobs = (ids = [], run = true) => call('retryShopPublishJobs', { ids, run })

    return {
        loading,
        error,
        errorCode,
        errorPayload,
        publishPart,
        publishAll,
        fetchPublishJobs,
        runPublishJobs,
        installPortalUi,
        fetchPublishStatus,
        fetchPublishRunOutput,
        cleanupPublishJobs,
        deletePublishJobs,
        retryPublishJobs,
        fetchPartShopData,
        savePartShopData,
        deletePartShopData,
        uploadChannelImage,
        deleteChannelImage,
        sortChannelImages,
        copyChannelImages,
        fetchStatus,
        fetchOrders,
        fetchKleinanzeigenBoard,
        fetchKleinanzeigenAd,
        saveKleinanzeigenAd,
        addKleinanzeigenParts,
        setKleinanzeigenAdState,
        sellKleinanzeigenAd,
        setKleinanzeigenLeadStatus,
        importKleinanzeigenLeads,
        generateKleinanzeigenText,
        suggestKleinanzeigenReply,
        fetchKleinanzeigenImagesZip,
        fetchKleinanzeigenFeedUrl,
        fetchKleinanzeigenWorkerStatus,
        loginKleinanzeigen,
        confirmKleinanzeigenLogin,
        openKleinanzeigenLogin,
        remoteKleinanzeigenBrowser,
        publishKleinanzeigenAd,
        deleteKleinanzeigenAd,
        extendKleinanzeigenAd,
        syncKleinanzeigenAds,
        replyKleinanzeigenLead,
        setOrderStatus,
        fetchOrderStatus,
        fetchPendingPayments,
        reconcilePayments,
        fetchWithdrawals,
        setWithdrawalProcessed,
    }
}
