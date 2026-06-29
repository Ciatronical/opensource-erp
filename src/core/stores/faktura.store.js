// src/core/stores/faktura.store.js

import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { ApiError } from '@/core/utils/error.js';

export const fakturaStore = defineStore('fakturaStore', () => {
    const data = ref(false);

    /**
     * Lädt Faktura-Daten (Rechnung, Auftrag, Angebot, Lieferschein)
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {string} fakturaType - Typ: 'invoice', 'order', 'quotation', 'delivery_order'
     * @return {Promise<void>}
     */
    async function fetchFakturaData(fakturaID, fakturaType = 'invoice') {
        const response = await axios.post('/api/faktura/', {
            action: 'getFakturaData',
            fakturaID: fakturaID,
            fakturaType: fakturaType
        });

        if (response.data.success) {
            data.value = response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error fetching faktura data: ' + response.data.text);
        }
    }

    /**
     * Lädt Artikel/Parts für Autocomplete
     *
     * @param {string} term - Suchbegriff
     * @param {number} taxzone - Steuerzone ID
     * @return {Promise<Array>}
     */
    async function fetchParts(term, taxzone) {
        const response = await axios.post('/api/parts/', {
            action: 'findParts',
            term: term,
            taxzone: taxzone
        });

        if (response.data.success) {
            return response.data.payload.parts;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error fetching parts: ' + response.data.text);
        }
    }

    /**
     * Speichert Faktura-Daten
     *
     * @param {Object} fakturaData - Zu speichernde Daten
     * @param {string} fakturaType - Typ des Dokuments
     * @return {Promise<Object>}
     */
    async function saveFakturaData(fakturaData, fakturaType = 'invoice') {
        const response = await axios.post('/api/faktura/', {
            action: 'saveFakturaData',
            fakturaData: fakturaData,
            fakturaType: fakturaType
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error saving faktura: ' + response.data.text);
        }
    }

    /**
     * Erstellt ein neues leeres Faktura-Dokument
     *
     * @param {string} fakturaType - Typ: 'order', 'quotation', 'invoice'
     * @param {number|null} cvId - Kunden-/Lieferanten-ID (optional)
     * @param {string|null} cvSrc - 'C' für Kunde, 'V' für Lieferant (optional)
     * @return {Promise<Object>} Objekt mit { id: number }
     */
    async function createFaktura(fakturaType = 'order', cvId = null, cvSrc = null) {
        const response = await axios.post('/api/faktura/', {
            action: 'createFaktura',
            fakturaType: fakturaType,
            cvId: cvId,
            cvSrc: cvSrc
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error creating faktura: ' + response.data.text);
        }
    }

    /**
     * Eine Faktura-Position anlegen
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {Object} itemData - Daten der Position
     * @param {string} fakturaType - Typ des Dokuments
     * @return {Promise<Object>} Die neue Position mit ID
     */
    async function createFakturaItem(fakturaID, itemData, fakturaType = 'invoice') {
        const response = await axios.post('/api/faktura/', {
            action: 'createFakturaItem',
            fakturaID: fakturaID,
            item: itemData,
            fakturaType: fakturaType
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error creating item: ' + response.data.text);
        }
    }

    /**
     * Ersetzt den Artikel einer bestehenden Position (isoliertes Einzel-Update).
     * @param {number} itemId
     * @param {number} partsId
     * @param {Object} fields - { description, qty, sellprice, unit }
     * @param {string} fakturaType
     */
    async function replaceFakturaItemArticle(itemId, partsId, fields, fakturaType = 'order') {
        const response = await axios.post('/api/faktura/', {
            action: 'replaceFakturaItemArticle',
            item_id: itemId,
            parts_id: partsId,
            description: fields.description ?? '',
            qty: fields.qty ?? 1,
            sellprice: fields.sellprice ?? 0,
            unit: fields.unit ?? '',
            fakturaType
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error replacing article: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Importiert SilverDAT VXS-Positionen als Faktura-Positionen (Bulk)
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {string} fakturaType - Typ des Dokuments
     * @param {Array} items - Array mit {description, partnumber, qty, sellprice, unit, part_type, longdescription}
     * @param {number} buchungsgruppeTeile - Buchungsgruppen-ID für Ersatzteile
     * @param {number} buchungsgruppeArbeit - Buchungsgruppen-ID für Dienstleistungen
     * @return {Promise<Array>} Array mit angelegten Positionen
     */
    async function importSilverDATItems(fakturaID, fakturaType, items, buchungsgruppeTeile, buchungsgruppeArbeit) {
        const response = await axios.post('/api/faktura/', {
            action: 'importSilverDATItems',
            fakturaID,
            fakturaType,
            buchungsgruppeTeile,
            buchungsgruppeArbeit,
            items
        })

        if (response.data.success) {
            return response.data.payload
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error importing SilverDAT items: ' + response.data.text)
        }
    }


    /**
     * Lädt das AAG-Online-Token im Hintergrund vor, damit der langsame Login
     * (~3 s) nicht erst beim Klick auf den AAG-Button anfällt. Bewusst
     * "feuern und vergessen" – Fehler werden ignoriert, der Auftrag darf
     * dadurch nicht langsamer werden.
     */
    function warmAagToken() {
        axios.post('/api/faktura/', { action: 'warmAagToken' }).catch(() => {})
    }

    /**
     * Aktualisiert mehrere Faktura-Positionen (Bulk-Update) und Buchungen
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {Array} items - Array mit Positions-Daten (müssen id enthalten)
     * @param {string} fakturaType - Typ des Dokuments
     * @param {Array} accTransEntries - Array mit Buchungssätzen für acc_trans (Positionen)
     * @param {Array} paymentEntries - Array mit Buchungssätzen für acc_trans (Zahlungen)
     * @param {number} netAmount - Nettobetrag
     * @param {number} grossAmount - Bruttobetrag
     * @return {Promise<Object>}
     */
    async function updateFakturaItems(fakturaID, items, fakturaType = 'invoice', accTransEntries = [], paymentEntries = null, netAmount = 0, grossAmount = 0) {
        // Nur Items mit ID (bereits gespeichert) senden
        const itemsWithId = items.filter(item => item.id)

        const hasPayments = paymentEntries !== null && paymentEntries.length > 0
        if (itemsWithId.length === 0 && accTransEntries.length === 0 && !hasPayments) {
            return { success: true, count: 0 }
        }

        // Nur relevante Felder senden
        const cleanItems = itemsWithId.map(item => ({
            id: item.id,
            position: item.position,
            description: item.description || '',
            longdescription: item.longdescription || '',
            qty: item.qty || 1,
            sellprice: item.sellprice || 0,
            discount: item.discount || 0,
            unit: item.unit || '',
            fxsellprice: item.sellprice || 0
        }))

        const body = {
            action: 'updateFakturaItems',
            fakturaID: fakturaID,
            items: cleanItems,
            fakturaType: fakturaType,
            accTransEntries: accTransEntries,
            netAmount: netAmount,
            grossAmount: grossAmount
        }

        // paymentEntries nur mitsenden wenn explizit übergeben (null = nicht anfassen)
        if (paymentEntries !== null) {
            body.paymentEntries = paymentEntries
        }

        const response = await axios.post('/api/faktura/', body);

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error updating items: ' + response.data.text);
        }
    }

    /**
     * Löscht eine Faktura-Position
     *
     * @param {number} itemID - ID der Position
     * @param {string} fakturaType - Typ des Dokuments
     * @return {Promise<void>}
     */
    async function deleteFakturaItem(itemID, fakturaType = 'invoice') {
        const response = await axios.post('/api/faktura/', {
            action: 'deleteFakturaItem',
            itemID: itemID,
            fakturaType: fakturaType
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error deleting item: ' + response.data.text);
        }
    }

    /**
     * Löscht eine komplette Faktura (Rechnung, Auftrag, Angebot, Lieferschein)
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {string} fakturaType - Typ: 'invoice', 'order', 'quotation', 'delivery_order'
     * @return {Promise<void>}
     */
    async function deleteFaktura(fakturaID, fakturaType = 'invoice') {
        const response = await axios.post('/api/faktura/', {
            action: 'deleteFaktura',
            fakturaID: fakturaID,
            fakturaType: fakturaType
        });

        if (!response.data.success) {
            const err = new ApiError('ApiError', response.data.text, response.data.text);
            err.payload = response.data.payload || null;
            throw err;
        }
    }

    /**
     * Aktualisiert einen Artikel in den Stammdaten (parts Tabelle)
     *
     * @param {Object} partData - Artikel-Daten
     * @param {number} partData.parts_id - ID des Artikels
     * @param {string} partData.description - Beschreibung
     * @param {string} partData.notes - Langbeschreibung
     * @param {number} partData.sellprice - Verkaufspreis
     * @param {string} partData.unit - Einheit
     * @return {Promise<Object>}
     */
    async function updatePart(partData) {
        const response = await axios.post('/api/parts/', {
            action: 'updatePart',
            parts_id: partData.parts_id,
            description: partData.description,
            notes: partData.notes,
            sellprice: partData.sellprice,
            unit: partData.unit
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error updating part: ' + response.data.text);
        }
    }

    /**
     * Aktualisiert ein einzelnes Feld in der Faktura
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {string} fakturaType - Typ des Dokuments
     * @param {string} field - Feldname
     * @param {any} value - Neuer Wert
     * @return {Promise<Object>}
     */
    async function updateFakturaField(fakturaID, fakturaType, field, value) {
        const response = await axios.post('/api/faktura/', {
            action: 'updateFakturaField',
            fakturaID: fakturaID,
            fakturaType: fakturaType,
            field: field,
            value: value
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error updating field: ' + response.data.text);
        }
    }

    /**
     * Bestätigt einen Verkaufsauftrag oder setzt ihn auf Auftragseingang zurück
     * (schaltet record_type sales_order ↔ sales_order_intake).
     *
     * @param {number} fakturaID - Auftrags-ID
     * @param {boolean} confirmed - true = bestätigt, false = Auftragseingang
     * @return {Promise<Object>} { record_type }
     */
    async function setOrderConfirmed(fakturaID, confirmed) {
        const response = await axios.post('/api/faktura/', {
            action: 'setOrderConfirmed',
            fakturaID: fakturaID,
            confirmed: confirmed
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error confirming order: ' + response.data.text);
        }
    }

    /**
     * Legt einen neuen Artikel in der parts Tabelle an
     *
     * @param {Object} partData - Artikel-Daten
     * @param {string} partData.description - Beschreibung (Pflicht)
     * @param {string} partData.part_type - 'part' oder 'service' (Pflicht)
     * @param {number} partData.buchungsgruppen_id - Buchungsgruppen-ID (Pflicht)
     * @param {string} partData.partnumber - Artikelnummer (optional)
     * @param {number} partData.sellprice - Verkaufspreis
     * @param {string} partData.unit - Einheit
     * @param {string} partData.notes - Langbeschreibung
     * @return {Promise<Object>}
     */
    async function createPart(partData) {
        const response = await axios.post('/api/parts/', {
            action: 'createPart',
            description: partData.description,
            part_type: partData.part_type,
            buchungsgruppen_id: partData.buchungsgruppen_id,
            partnumber: partData.partnumber || '',
            sellprice: partData.sellprice || 0,
            unit: partData.unit || 'Stck',
            notes: partData.notes || ''
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error creating part: ' + response.data.text);
        }
    }

    /**
     * Laedt die Liste verfuegbarer Template-Sets
     *
     * @return {Promise<Object>} {activeSet, templateSets: [{name, label}], masterSets: [{name, label}]}
     */
    async function getTemplateList() {
        const response = await axios.post('/api/print/', {
            action: 'getTemplateList'
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error fetching template list: ' + response.data.text);
        }
    }

    /**
     * Erstellt ein neues Template-Set durch Kopie eines Master-Sets
     *
     * @param {string} masterSet - Name des Master-Sets (RB, marei, mersiha, rev-odt)
     * @param {string} newName - Name fuer das neue Template-Set
     * @return {Promise<Object>} {name, basedOn}
     */
    async function createTemplateSet(masterSet, newName) {
        const response = await axios.post('/api/print/', {
            action: 'createTemplateSet',
            masterSet: masterSet,
            newName: newName
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error creating template set: ' + response.data.text);
        }
    }

    /**
     * Generiert eine PDF-Vorschau und gibt eine Blob-URL zurueck
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {string} fakturaType - Typ: 'invoice', 'order'
     * @param {string|null} templateSet - Template-Set-Name oder null fuer Default
     * @return {Promise<string>} Blob-URL fuer window.open()
     */
    async function generatePDFPreview(fakturaID, fakturaType, templateSet = null, printerId = null) {
        const response = await axios.post('/api/print/', {
            action: 'generatePDF',
            fakturaID: fakturaID,
            fakturaType: fakturaType,
            templateSet: templateSet,
            printerId: printerId,
            'content-type': 'application/pdf'
        }, {
            responseType: 'blob'
        });

        // Backend setzt Content-Type auf JSON zurueck bei Fehlern
        if (response.data.type === 'application/json') {
            const text = await response.data.text();
            const errorData = JSON.parse(text);
            const debugInfo = errorData.debug ? '\n\n' + errorData.debug : '';
            throw new ApiError('ApiError', errorData.text || 'PDF generation failed', debugInfo);
        }

        return URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    }

    /**
     * Generiert eine PDF und gibt sie als Base64-String zurueck (fuer E-Mail-Anhang)
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {string} fakturaType - Typ: 'invoice', 'order', 'quotation', 'delivery_order'
     * @param {string|null} templateSet - Template-Set-Name oder null fuer Default
     * @return {Promise<string>} Base64-kodierter PDF-Inhalt
     */
    async function generatePDFBase64(fakturaID, fakturaType, templateSet = null) {
        const response = await axios.post('/api/print/', {
            action: 'generatePDF',
            fakturaID: fakturaID,
            fakturaType: fakturaType,
            templateSet: templateSet,
            'content-type': 'application/pdf'
        }, {
            responseType: 'blob'
        });

        if (response.data.type === 'application/json') {
            const text = await response.data.text();
            const errorData = JSON.parse(text);
            const debugInfo = errorData.debug ? '\n\n' + errorData.debug : '';
            throw new ApiError('ApiError', errorData.text || 'PDF generation failed', debugInfo);
        }

        // Blob zu Base64 konvertieren
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => {
                // data:application/pdf;base64,... → nur den Base64-Teil extrahieren
                const base64 = reader.result.split(',')[1];
                resolve(base64);
            };
            reader.onerror = reject;
            reader.readAsDataURL(response.data);
        });
    }

    /**
     * Sendet ein Dokument an einen Drucker
     *
     * @param {number} fakturaID - ID des Dokuments
     * @param {string} fakturaType - Typ: 'invoice', 'order'
     * @param {string|null} templateSet - Template-Set-Name oder null fuer Default
     * @param {number} printerId - ID des Druckers
     * @return {Promise<Object>}
     */
    async function printToPrinter(fakturaID, fakturaType, templateSet = null, printerId) {
        const response = await axios.post('/api/print/', {
            action: 'printToPrinter',
            fakturaID: fakturaID,
            fakturaType: fakturaType,
            templateSet: templateSet,
            printerId: printerId
        });

        if (response.data.success) {
            return response.data;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error printing: ' + response.data.text);
        }
    }

    /**
     * Sendet einen Betrag an das gekoppelte SumUp-Kartenterminal (Cloud API).
     * Liefert synchron nur die Bestätigung, dass der Checkout am Terminal
     * gestartet wurde – das Zahlungsergebnis kommt bei SumUp asynchron.
     *
     * @param {number} amount - Zu kassierender Betrag (brutto)
     * @param {Object} [opts] - { fakturaID, currency, description }
     * @return {Promise<Object>}
     */
    async function sendSumupCheckout(amount, { fakturaID = null, currency = 'EUR', description = '' } = {}) {
        const response = await axios.post('/api/payment/', {
            action: 'sumupCheckout',
            amount: amount,
            fakturaID: fakturaID,
            currency: currency,
            description: description
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error sending SumUp checkout: ' + response.data.text);
        }
    }

    /**
     * Konvertiert ein Dokument in einen anderen Dokumenttyp
     * (z.B. Angebot → Auftrag, Auftrag → Rechnung)
     *
     * @param {number} sourceId - ID des Quelldokuments
     * @param {string} sourceType - Quelltyp (order, quotation, invoice, ...)
     * @param {string} targetType - Zieltyp (order, quotation, invoice, ...)
     * @return {Promise<Object>} { id: number, fakturaType: string }
     */
    async function convertFaktura(sourceId, sourceType, targetType) {
        const response = await axios.post('/api/faktura/', {
            action: 'convertFaktura',
            sourceId: sourceId,
            sourceType: sourceType,
            targetType: targetType
        });

        if (response.data.success) {
            return response.data.payload;
        }
        else {
            throw new ApiError('ApiError', response.data.text, 'Error converting faktura: ' + response.data.text);
        }
    }

    return {
        fetchFakturaData,
        fetchParts,
        saveFakturaData,
        createFaktura,
        convertFaktura,
        deleteFakturaItem,
        deleteFaktura,
        createFakturaItem,
        replaceFakturaItemArticle,
        importSilverDATItems,
        warmAagToken,
        updateFakturaItems,
        updateFakturaField,
        setOrderConfirmed,
        updatePart,
        createPart,
        getTemplateList,
        createTemplateSet,
        generatePDFPreview,
        generatePDFBase64,
        printToPrinter,
        sendSumupCheckout,
        data
    };
});