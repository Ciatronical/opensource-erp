// src/features/lxcars/stores/lxcars.store.js

import { defineStore } from 'pinia';
import { ref } from 'vue';
import axios from 'axios';
import { ApiError } from '@/core/utils/error.js';

export const lxcarsStore = defineStore('lxcarsStore', () => {
    const data = ref(false);

    /**
     * Temporäre Scan-Daten für Übergabe an car.edit.view
     * Wird nach Übernahme in car.edit.view geleert
     */
    const pendingScanData = ref(null);

    /**
     * Speichert ein neues Fahrzeug (INSERT)
     *
     * @param {Object} carData - Fahrzeugdaten
     * @return {Promise<Object>} Response mit payload (inkl. c_id)
     */
    async function saveCar(carData, kbaData = null) {
        const payload = {
            action: 'saveCar',
            data: carData
        };
        if (kbaData) payload.kba = kbaData;
        const response = await axios.post('/api/lxcars/', payload);

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error saving car: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Aktualisiert ein bestehendes Fahrzeug (UPDATE)
     *
     * @param {Object} carData - Fahrzeugdaten (muss c_id enthalten)
     * @param {Object|null} kbaData - KBA-Stammdaten (optional)
     * @return {Promise<Object>}
     */
    async function updateCar(carData, kbaData = null) {
        const payload = { action: 'updateCar', data: carData }
        if (kbaData) payload.kba = kbaData

        const response = await axios.post('/api/lxcars/', payload);

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error updating car: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Löscht ein Fahrzeug anhand der ID
     *
     * @param {number} carId - Fahrzeug-ID (c_id)
     * @return {Promise<Object>}
     */
    async function deleteCar(carId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'deleteCar',
            id: carId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error deleting car: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Lädt ein Fahrzeug anhand der ID
     *
     * @param {number} carId - Fahrzeug-ID (c_id)
     * @return {Promise<Object>} Response mit payload (Fahrzeugdaten)
     */
    async function loadCar(carId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getCar',
            id: carId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading car: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Prüft ob ein Kennzeichen bereits vergeben ist
     *
     * @param {string} licensePlate - Kennzeichen
     * @param {number|null} excludeId - Aktuelle c_id (Edit-Modus)
     * @return {Promise<Object>} { exists: bool, owner_name: string }
     */
    async function checkLicensePlate(licensePlate, excludeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'checkCarLicense',
            data: { c_ln: licensePlate, c_id: excludeId || 0 }
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error checking license plate: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Prüft mehrere Kennzeichen auf einmal (Batch)
     *
     * @param {string[]} plates - Array von Kennzeichen
     * @return {Promise<Object>} { "B-AB1234": { exists, c_id, owner_name }, ... }
     */
    async function checkLicensePlateBatch(plates) {
        const response = await axios.post('/api/lxcars/', {
            action: 'checkCarLicenseBatch',
            plates,
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error checking license plates: ' + response.data.text);
        }
        return response.data.payload.results;
    }

    /**
     * Prüft ob eine FIN bereits vergeben ist
     *
     * @param {string} fin - Fahrgestellnummer
     * @param {number|null} excludeId - Aktuelle c_id (Edit-Modus)
     * @return {Promise<Object>} { exists: bool, owner_name: string }
     */
    async function checkFin(fin, excludeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'checkCarFin',
            data: { c_fin: fin, c_id: excludeId || 0 }
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error checking FIN: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Lädt die Aufträge eines Fahrzeugs via oe_ext
     *
     * @param {number} carId - Fahrzeug-ID (c_id)
     * @return {Promise<Array>} Liste der Aufträge
     */
    async function loadCarOrders(carId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getCarOrders',
            id: carId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading car orders: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Prüft den km-Stand auf Plausibilität gegen alle früheren Aufträge/Rechnungen des Fahrzeugs.
     * Gibt { last_km } zurück – den höchsten bisher erfassten km-Stand (0 = kein Vorgänger).
     *
     * @param {number} oeId  - Aktuelle Auftrags-ID
     * @param {number} carId - Fahrzeug-ID
     */
    async function checkKmStandPlausibility(oeId, carId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'checkKmStandPlausibility',
            oe_id: oeId,
            c_id: carId
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error checking km plausibility');
        }
        return response.data.payload;
    }

    /**
     * Lädt alle Fahrzeuge eines Kunden (für Kennzeichen-Auswahl im Auftrag)
     *
     * @param {number} customerId - Kunden-ID
     * @return {Promise<Array>} Liste der Fahrzeuge [{c_id, c_ln, c_m, c_mt}]
     */
    async function loadCustomerCars(customerId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getCustomerCars',
            customer_id: customerId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading customer cars: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Lädt alle LxCars-Initialdaten für eine Faktura in einem einzigen Call.
     * Kombiniert: getCustomerCars + getCarForOrder/Invoice + getPartsRequestsByOrder + getRecentVendors
     *
     * @param {number} fakturaId
     * @param {number} customerId
     * @param {string} fakturaType - 'order', 'quotation' oder 'invoice'
     * @return {Promise<{customer_cars, car_data, parts_requests, recent_vendors}>}
     */
    async function loadLxCarsFakturaInit(fakturaId, customerId, fakturaType) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getLxCarsFakturaInit',
            faktura_id: fakturaId,
            customer_id: customerId,
            faktura_type: fakturaType
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading LxCars faktura init');
        }
        return response.data.payload;
    }

    /**
     * Lädt das verknüpfte Fahrzeug eines Auftrags
     *
     * @param {number} oeId - Auftrags-ID
     * @return {Promise<Object>} {c_id, c_ln}
     */
    async function loadCarForOrder(oeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getCarForOrder',
            oe_id: oeId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading car for order: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Sendet WhatsApp-Terminbestaetigung fuer Bringetermin (vom Frontend debounced)
     *
     * @param {number} oeId - Auftrags-ID
     */
    async function sendBringeterminWa(oeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'sendBringeterminWa',
            oe_id: oeId
        });
        return response.data;
    }

    /**
     * Aktualisiert oe_ext-Felder (km_stand, kfz_ort, etc.)
     *
     * @param {number} oeId - Auftrags-ID
     * @param {Object} data - Key-Value-Paare der zu ändernden Felder
     */
    async function updateOeExt(oeId, data) {
        const response = await axios.post('/api/lxcars/', {
            action: 'updateOeExt',
            oe_id: oeId,
            data: data
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error updating oe_ext: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Lädt das verknüpfte Fahrzeug einer Rechnung
     *
     * @param {number} arId - Rechnungs-ID
     * @return {Promise<Object>} {c_id, c_ln, km_stand}
     */
    async function loadCarForInvoice(arId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getCarForInvoice',
            ar_id: arId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading car for invoice: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Verknüpft eine Rechnung mit einem Fahrzeug
     *
     * @param {number} arId - Rechnungs-ID
     * @param {number|null} cId - Fahrzeug-ID (null = Verknüpfung lösen)
     */
    async function linkCarToInvoice(arId, cId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'linkCarToInvoice',
            ar_id: arId,
            c_id: cId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error linking car to invoice: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Aktualisiert ar_ext-Felder (km_stand)
     *
     * @param {number} arId - Rechnungs-ID
     * @param {Object} data - Key-Value-Paare der zu ändernden Felder
     */
    async function updateArExt(arId, data) {
        const response = await axios.post('/api/lxcars/', {
            action: 'updateArExt',
            ar_id: arId,
            data: data
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error updating ar_ext: ' + response.data.text);
        }
        return response.data;
    }

    /**
     * Verknüpft einen Auftrag mit einem Fahrzeug
     *
     * @param {number} oeId - Auftrags-ID
     * @param {number|null} cId - Fahrzeug-ID (null = Verknüpfung lösen)
     */
    async function linkCarToFaktura(oeId, cId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'linkCarToFaktura',
            oe_id: oeId,
            c_id: cId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error linking car: ' + response.data.text);
        }
        return response.data;
    }

    // ===== Arbeitsanweisungen (Instructions) =====

    async function loadInstructions(oeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getInstructions',
            oe_id: oeId
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading instructions: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function addInstruction(oeId, description) {
        const response = await axios.post('/api/lxcars/', {
            action: 'addInstruction',
            oe_id: oeId,
            description: description
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error adding instruction: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function updateInstruction(id, data) {
        const response = await axios.post('/api/lxcars/', {
            action: 'updateInstruction',
            id: id,
            data: data
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error updating instruction: ' + response.data.text);
        }
        return response.data;
    }

    async function setAllInstructionsEmployee(oeId, employeeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'setAllInstructionsEmployee',
            oe_id: oeId,
            employee_id: employeeId
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error setting employee for all instructions: ' + response.data.text);
        }
        return response.data;
    }

    async function deleteInstruction(id) {
        const response = await axios.post('/api/lxcars/', {
            action: 'deleteInstruction',
            id: id
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error deleting instruction: ' + response.data.text);
        }
        return response.data;
    }

    async function deleteAllInstructions(oeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'deleteAllInstructions',
            oe_id: oeId
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error deleting all instructions: ' + response.data.text);
        }
        return response.data;
    }

    async function reorderInstructions(oeId, order) {
        const response = await axios.post('/api/lxcars/', {
            action: 'reorderInstructions',
            oe_id: oeId,
            order: order
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error reordering instructions: ' + response.data.text);
        }
        return response.data;
    }

    async function searchInstructions(query) {
        const response = await axios.post('/api/lxcars/', {
            action: 'searchInstructions',
            query: query
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error searching instructions: ' + response.data.text);
        }
        return response.data.payload;
    }

    // ===== Master-Arbeitsanweisungen =====

    async function loadMasterInstructions() {
        const response = await axios.post('/api/lxcars/', {
            action: 'getMasterInstructions'
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading master instructions: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function addMasterInstruction(description) {
        const response = await axios.post('/api/lxcars/', {
            action: 'addMasterInstruction',
            description: description
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, response.data.text);
        }
        return response.data.payload;
    }

    async function updateMasterInstruction(id, description, instructionNumber) {
        const payload = { action: 'updateMasterInstruction', id, description };
        if (instructionNumber !== undefined) payload.instruction_number = instructionNumber;
        const response = await axios.post('/api/lxcars/', payload);
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error updating master instruction: ' + response.data.text);
        }
        return response.data;
    }

    async function checkInstructionNumber(number, excludeNumber) {
        const response = await axios.post('/api/lxcars/', {
            action: 'checkInstructionNumber',
            number: number,
            exclude_number: excludeNumber || ''
        });
        return response.data.payload;
    }

    async function deleteMasterInstruction(id) {
        const response = await axios.post('/api/lxcars/', {
            action: 'deleteMasterInstruction',
            id: id
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error deleting master instruction: ' + response.data.text);
        }
        return response.data;
    }

    // ===== Zeiterfassung =====

    async function startInstructionTimer(id, employeeId, timerConfig) {
        const response = await axios.post('/api/lxcars/', {
            action: 'startInstructionTimer',
            id,
            employee_id: employeeId,
            pausen: timerConfig.pausen || '',
            arbeitsbeginn: timerConfig.arbeitsbeginn || '08:00',
            arbeitsende: timerConfig.arbeitsende || '17:00'
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error starting timer: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function stopInstructionTimer(id, timerConfig) {
        const response = await axios.post('/api/lxcars/', {
            action: 'stopInstructionTimer',
            id,
            pausen: timerConfig.pausen || '',
            arbeitsbeginn: timerConfig.arbeitsbeginn || '08:00',
            arbeitsende: timerConfig.arbeitsende || '17:00'
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error stopping timer: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function getActiveInstructionTimer(employeeId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getActiveTimer',
            employee_id: employeeId
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error getting active timer: ' + response.data.text);
        }
        return response.data.payload;
    }

    // ===== Mängel =====

    async function loadMaengel(oeId, docType = 'order') {
        const response = await axios.post('/api/lxcars/', {
            action: 'getMaengel',
            oe_id: oeId,
            doc_type: docType
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading maengel: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function addMangel(oeId, mangelData, docType = 'order') {
        const response = await axios.post('/api/lxcars/', {
            action: 'addMangel',
            oe_id: oeId,
            doc_type: docType,
            ...mangelData
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error adding mangel: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function updateMangel(id, data, docType = 'order') {
        const response = await axios.post('/api/lxcars/', {
            action: 'updateMangel',
            id: id,
            data: data,
            doc_type: docType
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error updating mangel: ' + response.data.text);
        }
        return response.data;
    }

    async function deleteMangel(id, docType = 'order') {
        const response = await axios.post('/api/lxcars/', {
            action: 'deleteMangel',
            id: id,
            doc_type: docType
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error deleting mangel: ' + response.data.text);
        }
        return response.data;
    }

    async function deleteAllMaengel(oeId, docType = 'order') {
        const response = await axios.post('/api/lxcars/', {
            action: 'deleteAllMaengel',
            oe_id: oeId,
            doc_type: docType
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error deleting all maengel: ' + response.data.text);
        }
        return response.data;
    }

    async function searchMaengelliste(query) {
        const response = await axios.post('/api/lxcars/', {
            action: 'searchMaengelliste',
            query: query
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error searching maengelliste: ' + response.data.text);
        }
        return response.data.payload;
    }

    async function addCustomMangel(data) {
        const response = await axios.post('/api/lxcars/', {
            action: 'addCustomMangel',
            ...data
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error creating custom mangel: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Sucht KBA-Datensätze anhand von HSN + TSN (+ optional D2)
     *
     * @param {string} hsn - HSN (4 Ziffern)
     * @param {string} tsn - TSN (mind. 3 Zeichen, wird serverseitig gekürzt)
     * @param {string} [d2] - D2 Typschlüssel (optional, schränkt Ergebnis ein)
     * @return {Promise<Array>} [ { id, hsn, tsn, d2, hersteller, marke, name, ... } ]
     */
    async function lookupKba(hsn, tsn, d2) {
        const payload = {
            action: 'lookupKba',
            hsn: hsn,
            tsn: tsn
        };
        if (d2) payload.d2 = d2;
        const response = await axios.post('/api/lxcars/', payload);

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error looking up KBA: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Sucht KBA-Einträge nur anhand der HSN (alle TSN-Varianten)
     *
     * @param {string} hsn - 4-stellige HSN
     * @return {Promise<Array>}
     */
    async function lookupKbaByHsn(hsn) {
        const response = await axios.post('/api/lxcars/', {
            action: 'lookupKbaByHsn',
            hsn: hsn
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error looking up KBA by HSN: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Fuzzy-KBA-Lookup: Prüft ob HSN+TSN exakt existiert, sonst OCR-Korrektur-Vorschläge
     *
     * @param {string} hsn - HSN (4 Zeichen)
     * @param {string} tsn - TSN (mind. 3 Zeichen)
     * @return {Promise<{exact: boolean, suggestions: Array}>}
     */
    async function lookupKbaFuzzy(hsn, tsn) {
        const response = await axios.post('/api/lxcars/', {
            action: 'lookupKbaFuzzy',
            hsn: hsn,
            tsn: tsn
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error in KBA fuzzy lookup: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Speichert Scan-KBA-Daten in special_kba_lxcars (ohne Standard-KBA-Zuordnung)
     *
     * @param {number} carId - Fahrzeug-ID
     * @param {Object} kbaData - KBA-Felder aus dem Scan
     * @return {Promise<Object>}
     */
    async function saveSpecialKba(carId, kbaData) {
        const response = await axios.post('/api/lxcars/', {
            action: 'saveSpecialKba',
            c_id: carId,
            data: kbaData
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error saving special KBA: ' + response.data.text);
        }
        return response.data;
    }

    // ===== Fahrzeugzulassung (carreg) =====

    /**
     * Laedt Fahrzeug- und Kundendaten fuer das Zulassungsformular
     *
     * @param {number} cId - Fahrzeug-ID
     * @return {Promise<Object>} Vorausgefuellte Formulardaten
     */
    async function getCarregData(cId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getCarregData',
            c_id: cId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading carreg data: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Erzeugt das Zulassungs-PDF
     *
     * @param {Object} formData - Alle Formularfelder
     * @return {Promise<Object>} { pdf: base64, filename: string }
     */
    async function generateCarregPdf(formData) {
        const response = await axios.post('/api/lxcars/', {
            action: 'generateCarregPdf',
            ...formData
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error generating carreg PDF: ' + response.data.text);
        }
        return response.data.payload;
    }

    // ===== Fahrzeugschein-Scan-Bilder =====

    /**
     * Speichert Scan-Bilder (Original + Feld-Ausschnitte) für ein Fahrzeug
     *
     * @param {number} cId - Fahrzeug-ID
     * @param {string} cLn - Kennzeichen (für Symlink)
     * @param {string|null} originalImage - Base64 des Original-Fahrzeugscheins
     * @param {Object} fieldImages - { hsnImg: base64, vinImg: base64, ... }
     * @param {boolean} isPdf - Ist das Original ein PDF?
     */
    async function saveScanImages(cId, cLn, originalImage, fieldImages, isPdf = false, tempImageId = null, scanId = null) {
        const payload = {
            action: 'saveScanImages',
            c_id: cId,
            c_ln: cLn,
            original_image: originalImage || null,
            field_images: fieldImages || {},
            is_pdf: isPdf
        }
        if (tempImageId) payload.temp_image_id = tempImageId
        if (scanId) payload.scan_id = scanId
        const response = await axios.post('/api/lxcars/', payload);

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error saving scan images: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Gibt die Liste der gespeicherten Scan-Bilder zurück
     *
     * @param {number} cId - Fahrzeug-ID
     * @return {Promise<Object>} { c_id, files: [ { name, type, field? } ] }
     */
    async function getScanImages(cId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getScanImages',
            c_id: cId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading scan images: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Lädt ein einzelnes Scan-Bild als Base64
     *
     * @param {number} cId - Fahrzeug-ID
     * @param {string} filename - Dateiname (z.B. 'crop_hsn.jpg')
     * @return {Promise<Object>} { image: base64, mime, filename }
     */
    async function getScanImage(cId, filename) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getScanImage',
            c_id: cId,
            filename: filename
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading scan image: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Lädt alle Crop-Bilder + Original eines Fahrzeugs in einem Call
     *
     * @param {number} cId - Fahrzeug-ID
     * @return {Promise<Object>} { original: { image, mime } | null, crops: { hsn: { image, mime }, ... } }
     */
    async function getScanCrops(cId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getScanCrops',
            c_id: cId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading scan crops: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Lädt die Liste der verfügbaren Crop-Felder aus dem tmp-Cache
     *
     * @param {string} scanId - Scan-ID
     * @return {Promise<Object>} { fields: ['hsn', 'vin', ...], has_original: true }
     */
    async function getScanTempCropList(scanId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getScanTempCropList',
            scan_id: scanId
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading scan crop list: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Lädt ein einzelnes Crop-Bild aus dem tmp-Cache
     *
     * @param {string} scanId - Scan-ID
     * @param {string} field - Feldname (z.B. 'hsn', 'vin')
     * @return {Promise<Object>} { image: '<base64>', mime: 'image/jpeg' }
     */
    async function getScanTempCrop(scanId, field) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getScanTempCrop',
            scan_id: scanId,
            field
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading scan crop: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Holt Detail-Daten eines einzelnen Scans (nur Textdaten, Bilder im tmp-Cache)
     *
     * @param {string} scanId - Scan-ID vom Scanner-Service
     * @return {Promise<Object>} { car, owner, kba, raw }
     */
    async function getScanDetail(scanId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'getScanDetail',
            scan_id: scanId
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading scan detail: ' + response.data.text);
        }
        return response.data.payload;
    }

    // ===== Fahrzeugschein-Scan =====

    /**
     * Lädt Fahrzeugschein-Scans paginiert aus der DB (synct neue von der API auf Seite 1)
     *
     * @param {number} page - Aktuelle Seite (1-basiert, default 1)
     * @param {number} per_page - Einträge pro Seite (default 20)
     * @return {Promise<Object>} { results, total, page, per_page, total_pages }
     */
    async function getScans(page = 1, per_page = 20, search = '') {
        const payload = { action: 'getScans', page, per_page };
        if (search) payload.search = search;
        const response = await axios.post('/api/lxcars/', payload);

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error loading scans: ' + (response.data.payload || response.data.text));
        }
        return response.data.payload;
    }

    /**
     * Mappt Scan-Rohdaten auf Car-Felder (via Backend-Helper)
     *
     * @param {Object} scanData - Rohdaten eines Scans
     * @return {Promise<Object>} { car: {...}, owner: {...} }
     */
    async function mapScanData(scanData) {
        const response = await axios.post('/api/lxcars/', {
            action: 'mapScanData',
            data: scanData
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error mapping scan data: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Scannt einen Fahrzeugschein via Backend-Proxy
     *
     * @param {string} imageBase64 - Base64-kodiertes Bild
     * @param {boolean} isPdf - Ist das Bild ein PDF?
     * @return {Promise<Object>} { car: {...}, owner: {...}, raw: {...} }
     */
    async function scanFahrzeugschein(imageBase64, isPdf = false) {
        const response = await axios.post('/api/lxcars/', {
            action: 'scanFahrzeugschein',
            image: imageBase64,
            is_pdf: isPdf
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error scanning Fahrzeugschein: ' + (response.data.payload || response.data.text));
        }
        return response.data.payload;
    }

    /**
     * Sucht Kunden anhand des Namens (für Scan-Zuordnung)
     *
     * @param {string} name - Suchbegriff
     * @return {Promise<Array>} [ { id, name, street, zipcode, city } ]
     */
    async function searchCustomerForScan(name) {
        const response = await axios.post('/api/lxcars/', {
            action: 'searchCustomerForScan',
            name: name
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error searching customers: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Sucht Fahrzeuge anhand des Kennzeichens (fuer Scan-Zuordnung)
     *
     * @param {string} cln - Kennzeichen (Suchbegriff)
     * @return {Promise<Array>} [ { c_id, c_ln, owner_name, hersteller, modell } ]
     */
    async function searchCarForScan(cln) {
        const response = await axios.post('/api/lxcars/', {
            action: 'searchCarForScan',
            c_ln: cln
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error searching cars: ' + response.data.text);
        }
        return response.data.payload;
    }

    /**
     * Findet automatisch einen Kunden anhand Name (similarity) + exakter Adresse
     *
     * @param {string} name - Haltername aus dem Scan
     * @param {string} street - Straße
     * @param {string} zipcode - PLZ
     * @param {string} city - Ort
     * @return {Promise<Object|null>} Kundendaten oder null
     */
    async function autoMatchCustomerForScan(name, street, zipcode, city) {
        const response = await axios.post('/api/lxcars/', {
            action: 'autoMatchCustomerForScan',
            name, street, zipcode, city
        })
        if (!response.data.success) return null
        return response.data.payload
    }

    /**
     * Druckt eine grüne Plakette (gelbes Etikett) mit Kennzeichen
     *
     * @param {string} cLn - Kennzeichen
     * @param {number} printerId - Drucker-ID
     * @return {Promise<Object>}
     */
    async function printYellowLabel(cLn, printerId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'printYellowLabel',
            c_ln: cLn,
            printerId
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error printing yellow label: ' + response.data.text)
        }
        return response.data
    }

    /**
     * Druckt 4 Reifenetiketten (VR, VL, HR, HL)
     *
     * @param {Object} params - Druckdaten
     * @param {number} printerId - Drucker-ID
     * @return {Promise<Object>}
     */
    async function printTyreLabel(params, printerId) {
        const response = await axios.post('/api/lxcars/', {
            action: 'printTyreLabel',
            ...params,
            printerId
        })
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error printing tyre labels: ' + response.data.text)
        }
        return response.data
    }

    async function checkDuplicateCustomer(name, street, zipcode) {
        const response = await axios.post('/api/lxcars/', {
            action: 'checkDuplicateCustomer',
            name, street, zipcode
        });
        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error checking duplicate: ' + response.data.text);
        }
        return response.data.payload;
    }

    // ===== Mechaniker-Modus =====

    async function loadMechanicOrders() {
        const response = await axios.post('/api/lxcars/', { action: 'getMechanicOrders' })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading mechanic orders')
        return response.data.payload
    }

    async function loadAllMechanicOrders() {
        const response = await axios.post('/api/lxcars/', { action: 'getAllMechanicOrders' })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading all mechanic orders')
        return response.data.payload
    }

    async function loadMechanicOrderDetail(oeId) {
        const response = await axios.post('/api/lxcars/', { action: 'getMechanicOrderDetail', oe_id: oeId })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading mechanic order detail')
        return response.data.payload
    }

    async function addPartsRequest(oeId, data) {
        const response = await axios.post('/api/lxcars/', { action: 'addPartsRequest', oe_id: oeId, ...data })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error adding parts request')
        return response.data.payload
    }

    async function updatePartsRequest(id, data) {
        const response = await axios.post('/api/lxcars/', { action: 'updatePartsRequest', id, ...data })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error updating parts request')
        return response.data
    }

    async function deletePartsRequest(id) {
        const response = await axios.post('/api/lxcars/', { action: 'deletePartsRequest', id })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error deleting parts request')
        return response.data
    }

    async function requestPartsForItem(oeId, orderitemId, note = '') {
        const response = await axios.post('/api/lxcars/', { action: 'requestPartsForItem', oe_id: oeId, orderitem_id: orderitemId, note })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error requesting parts for item')
        return response.data
    }

    async function cancelPartsRequest(orderitemId) {
        const response = await axios.post('/api/lxcars/', { action: 'cancelPartsRequest', orderitem_id: orderitemId })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error canceling parts request')
        return response.data
    }

    async function markPartsRequestReceived(id) {
        const response = await axios.post('/api/lxcars/', { action: 'markPartsRequestReceived', id })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error marking as received')
        return response.data
    }

    async function getPartsRequestsByOrder(oeId) {
        const response = await axios.post('/api/lxcars/', { action: 'getPartsRequestsByOrder', oe_id: oeId })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading parts requests')
        return response.data.payload
    }

    async function savePartsRequestPhoto(id, image) {
        const response = await axios.post('/api/lxcars/', { action: 'savePartsRequestPhoto', id, image })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error saving photo')
        return response.data.payload
    }

    async function getPartsRequestPhoto(id) {
        const response = await axios.post('/api/lxcars/', { action: 'getPartsRequestPhoto', id })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading photo')
        return response.data.payload
    }

    async function deletePartsRequestPhoto(id, path) {
        const response = await axios.post('/api/lxcars/', { action: 'deletePartsRequestPhoto', id, path })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error deleting photo')
        return response.data.payload
    }

    async function markPartsRequestOrdered(id, vendorId) {
        const response = await axios.post('/api/lxcars/', { action: 'markPartsRequestOrdered', id, vendor_id: vendorId })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error marking as ordered')
        return response.data
    }

    async function revertPartsRequestToPending(id) {
        const response = await axios.post('/api/lxcars/', { action: 'revertPartsRequestToPending', id })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error reverting to pending')
        return response.data
    }

    async function loadPendingPartsRequests() {
        const response = await axios.post('/api/lxcars/', { action: 'getPendingPartsRequests' })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading pending requests')
        return response.data.payload
    }

    async function getRecentVendors() {
        const response = await axios.post('/api/lxcars/', { action: 'getRecentVendors' })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading vendors')
        return response.data.payload
    }

    async function searchVendors(q) {
        const response = await axios.post('/api/lxcars/', { action: 'searchVendors', q })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error searching vendors')
        return response.data.payload
    }

    // ===== Fahrzeug-Chat (KI-Werkstattmeister) =====

    async function loadCarChat(cId) {
        const response = await axios.post('/api/lxcars/', { action: 'getCarChat', c_id: cId })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error loading car chat')
        return response.data.payload
    }

    async function sendCarChatMessage(cId, message, document = null) {
        const payload = { action: 'sendCarChatMessage', c_id: cId, message }
        if (document) payload.document = document
        const response = await axios.post('/api/lxcars/', payload)
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error sending chat message')
        return response.data.payload
    }

    async function clearCarChat(cId) {
        const response = await axios.post('/api/lxcars/', { action: 'clearCarChat', c_id: cId })
        if (!response.data.success) throw new ApiError('ApiError', response.data.text, 'Error clearing car chat')
        return response.data
    }

    return {
        pendingScanData,
        getScans,
        mapScanData,
        scanFahrzeugschein,
        searchCustomerForScan,
        autoMatchCustomerForScan,
        searchCarForScan,
        checkDuplicateCustomer,
        saveScanImages,
        getScanImages,
        getScanImage,
        getScanCrops,
        getScanTempCropList,
        getScanTempCrop,
        getScanDetail,
        saveCar,
        updateCar,
        deleteCar,
        loadCar,
        loadCarOrders,
        checkKmStandPlausibility,
        loadLxCarsFakturaInit,
        loadCustomerCars,
        loadCarForOrder,
        linkCarToFaktura,
        sendBringeterminWa,
        updateOeExt,
        loadCarForInvoice,
        linkCarToInvoice,
        updateArExt,
        checkLicensePlate,
        checkLicensePlateBatch,
        checkFin,
        lookupKba, lookupKbaByHsn, lookupKbaFuzzy, saveSpecialKba,
        loadInstructions,
        addInstruction,
        updateInstruction,
        setAllInstructionsEmployee,
        deleteInstruction,
        deleteAllInstructions,
        reorderInstructions,
        searchInstructions,
        loadMasterInstructions,
        addMasterInstruction,
        updateMasterInstruction,
        checkInstructionNumber,
        deleteMasterInstruction,
        startInstructionTimer,
        stopInstructionTimer,
        getActiveInstructionTimer,
        loadMaengel,
        addMangel,
        updateMangel,
        deleteMangel,
        deleteAllMaengel,
        searchMaengelliste,
        addCustomMangel,
        getCarregData,
        generateCarregPdf,
        printYellowLabel,
        printTyreLabel,
        // Mechaniker-Modus
        loadMechanicOrders,
        loadAllMechanicOrders,
        loadMechanicOrderDetail,
        addPartsRequest,
        updatePartsRequest,
        deletePartsRequest,
        savePartsRequestPhoto,
        getPartsRequestPhoto,
        deletePartsRequestPhoto,
        markPartsRequestOrdered,
        revertPartsRequestToPending,
        loadPendingPartsRequests,
        getRecentVendors,
        searchVendors,
        requestPartsForItem,
        cancelPartsRequest,
        markPartsRequestReceived,
        getPartsRequestsByOrder,
        // Fahrzeug-Chat
        loadCarChat,
        sendCarChatMessage,
        clearCarChat,
        data
    };
});
