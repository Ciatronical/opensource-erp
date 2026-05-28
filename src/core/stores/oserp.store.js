// src/core/stores/oserp.store.js

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * OSERP STORE - ZENTRALE DATENVERWALTUNG
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KONZEPT: REACTIVE STATE MANAGEMENT
 *
 * Der Pinia Store ist die zentrale Datenverwaltung der Anwendung:
 *
 * 1. SINGLE SOURCE OF TRUTH
 *    - Alle Komponenten greifen auf dieselben Daten zu
 *    - Keine Duplikate, keine Synchronisationsprobleme
 *
 * 2. ZENTRALE GESCHÄFTSLOGIK
 *    - API-Calls an einem Ort, nicht verstreut über 50 Komponenten
 *    - Wiederverwendbar und wartbar
 *
 * 3. REACTIVE UPDATES
 *    - Store ändert Daten → ALLE Komponenten aktualisieren sich automatisch
 *    - Component A speichert → Component B, C, D sehen sofort die neuen Daten
 *
 * 4. WORKFLOW
 *    Component → Store Action → API Call → Store Update → All Components Update
 *
 * ═══════════════════════════════════════════════════════════════════════════
 */

import { defineStore } from 'pinia';
import { ref, reactive } from 'vue';
import { ApiError, PermissionDeniedError } from '@/core/utils/error.js';
import { AuthStatus } from '@/core/constants/auth.js';
import axios from 'axios';

export const oserpStore = defineStore('oserpStore', () => {

    // =========================================================================
    // STATE - ZENTRALE DATEN
    // =========================================================================

    /**
     * Customer/Vendor Daten
     */
    const customer_vendor = ref(false);

    /**
     * Session-Daten (User, Client, Config, Permissions)
     */
    const session = reactive({
        user: '',
        client: '',
        auth_user_data: null,
        auth_groups: [],
        permissions: [],
        logged_in_employee: null,
        company_config: null,
        is_demo: false,
        demo_inactivity_minutes: 20,
        can_create_company: false
    });

    /**
     * Aktivierte Features
     */
    const features = reactive([]);

    function isDebugMode() {
        return getClientDefaultValue('debug', 'f') === 't';
    }

    // =========================================================================
    // HELPER FUNCTIONS - HILFSFUNKTIONEN
    // =========================================================================

    /**
     * Prüft ob ein Feature aktiviert ist
     */
    function isFeatureEnabled(featureName) {
        return features.some(f => (typeof f === 'object' ? f.value : f) === featureName);
    }

    /**
     * Prüft ob LxCars aktiviert ist
     */
    function isLxCars() {
        return isFeatureEnabled('lxcars');
    }

    /**
     * Prüft ob ANPR aktiviert ist (Standard: true wenn nicht explizit deaktiviert)
     */
    function isAnprEnabled() {
        const v = getClientDefaultValue('feature_anpr', null);
        if (v === null) return true;
        return v === true || v === 'true' || v === '1' || v === 1;
    }

    /**
     * Prüft ob NVR aktiviert ist (Standard: true wenn nicht explizit deaktiviert)
     */
    function isNvrEnabled() {
        const v = getClientDefaultValue('feature_nvr', null);
        if (v === null) return true;
        return v === true || v === 'true' || v === '1' || v === 1;
    }

    /**
     * Prüft ob User eine Permission hat
     */
    function checkPermission(permissionName) {
        return session.permissions && session.permissions.includes(permissionName);
    }

    /**
     * Wirft Fehler wenn Permission fehlt
     */
    function permit(permissionName) {
        if (!checkPermission(permissionName)) {
            throw new PermissionDeniedError(permissionName, 'Permission denied: ' + permissionName);
        }
    }

    /**
     * Holt einen Config-Wert aus company_employee_config
     */
    function getConfigValue(key, defaultValue = null) {
        return session['company_config']?.['company_employee_config']?.[key] ?? defaultValue;
    }

    /**
     * Speichert einen Employee-Config-Wert (Key/Value)
     */
    function setConfigValue(key, value) {
        if (!session.company_config) session.company_config = {};
        if (!session.company_config.company_employee_config) session.company_config.company_employee_config = {};
        session.company_config.company_employee_config[key] = value;
        axios.post('/api/oserp_config/', { action: 'saveEmployeeConfig', key, value });
    }

    /**
     * Löscht einen Employee-Config-Wert
     */
    function deleteConfigValue(key) {
        if (session.company_config?.company_employee_config) {
            delete session.company_config.company_employee_config[key];
        }
        axios.post('/api/oserp_config/', { action: 'deleteEmployeeConfig', key });
    }

    /**
     * Holt den Startup-View aus der Konfiguration
     */
    function getStartupViewConfig() {
        let value = getConfigValue('startup-view', null);
        if (value === null) {
            value = getClientDefaultValue('startup-view', null);
            if (value === null) {
                value = getAuthUserData('startup_view', 'main-menu');
            }
        }
        return value;
    }

    /**
     * Holt einen Client-Default-Wert aus defaults_oserp
     */
    function getClientDefaultValue(key, defaultValue = null) {
        return session['company_config']?.['defaults_oserp']?.[key] ?? defaultValue;
    }

    /**
     * Gibt Wert aus auth_user_data zurück
     *
     * @param {*} key Schlüssel im auth_user_data Objekt
     * @param {*} defaultValue default Wert falls key nicht existiert
     * @returns Wert aus auth_user_data oder defaultValue
     */
    function getAuthUserData(key, defaultValue = null) {
        return session['auth_user_data']?.[key] ?? defaultValue;
    }

    /**
     * Transformiert API-Response in Store-Daten
     */
    function transformResponseToStoreData(responseData) {
        session.user = responseData.payload.login;
        session.client = responseData.payload.client;
        session.auth_user_data = responseData.payload.auth_user_data;
        session.auth_groups = responseData.payload.auth_groups || [];
        session.permissions = responseData.payload.permissions;
        session.logged_in_employee = responseData.payload.main.logged_in_employee;
        session.company_config = responseData.payload.main.company_config;
        session.is_demo = responseData.payload.is_demo || false;
        session.demo_inactivity_minutes = responseData.payload.demo_inactivity_minutes || 20;
        session.can_create_company = responseData.payload.can_create_company || false;
        features.splice(0, features.length, ...(responseData.payload.main.company_config?.features || []));
        customer_vendor.value = responseData.payload.main.customer_vendor;

        // Letzten CV in localStorage merken
        const cv = customer_vendor.value;
        if (cv?.profile?.id) {
            localStorage.setItem('oserp_last_cv_id', cv.profile.id);
            localStorage.setItem('oserp_last_cv_src', cv.profile.src || 'C');
        }
    }

    /**
     * Gibt gespeicherte CV-Daten aus localStorage zurück
     */
    function getLastCvParams() {
        const savedId = localStorage.getItem('oserp_last_cv_id');
        const savedSrc = localStorage.getItem('oserp_last_cv_src');
        const params = {};
        if (savedId) {
            params.customerId = savedId;
            params.src = savedSrc || 'C';
        }
        return params;
    }

    // =========================================================================
    // AUTHENTICATION - LOGIN/LOGOUT/SESSION
    // =========================================================================

    /**
     * Lädt verfügbare Mandanten
     *
     * @return {Object} { clients: Array, is_demo: boolean }
     */
    async function fetchClients() {
        const result = await axios.post('/api/', { action: 'getClients' });

        if (result.data.success) {
            return {
                clients: result.data.payload.clients || [],
                is_demo: result.data.payload.is_demo || false
            };
        } else {
            throw new ApiError('ApiError', result.data.text);
        }
    }

    /**
     * Login
     */
    async function login(username, password, client, rememberMe = false) {
        const payload = {
            action: 'login',
            username: username,
            password: password,
            client: client,
            remember_me: rememberMe,
            ...getLastCvParams()
        };

        const response = await axios.post('/api/', payload);

        if (response.data.success) {
            transformResponseToStoreData(response.data);
            // Login-Zeitstempel fuer Info Bar speichern
            localStorage.setItem(`oserp_infobar_login_ts_${session.user}_${session.client}`, String(Date.now()))
            return AuthStatus.AUTHENTICATED;
        }

        throw new ApiError('ApiError', response.data.text, response.data.payload);
    }

    /**
     * Mandant wechseln (ohne erneuten Login)
     */
    async function switchClient(clientCode) {
        // Keine getLastCvParams(): die CV-IDs gehören zum alten Mandanten.
        // Das Backend ermittelt den CV aus der View-History des neuen Mandanten.
        const response = await axios.post('/api/', {
            action: 'switchClient',
            client: clientCode
        });

        if (response.data.success) {
            transformResponseToStoreData(response.data);
            localStorage.setItem(`oserp_infobar_login_ts_${session.user}_${session.client}`, String(Date.now()));
            return true;
        }

        throw new ApiError('ApiError', response.data.text);
    }

    /**
     * Neue Firma anlegen
     */
    async function createCompany(companyName, dbName, skr) {
        const response = await axios.post('/api/company/', {
            action: 'createCompany',
            companyName,
            dbName,
            skr
        });

        if (response.data.success) {
            return response.data;
        }

        throw new ApiError('ApiError', response.data.text || 'UNKNOWN_ERROR');
    }

    /**
     * Login-Zeitstempel aus localStorage lesen
     */
    function getLoginTimestamp() {
        return parseInt(localStorage.getItem(`oserp_infobar_login_ts_${session.user}_${session.client}`) || '0', 10)
    }

    /**
     * Logout
     */
    async function logout() {
        const response = await axios.post('/api/', { action: 'logout' });

        if (response.data.success) {
            session.user = '';
            session.client = '';
        } else {
            throw new ApiError('ApiError', response.data.text);
        }
    }

    /**
     * Session wiederherstellen
     *
     * @returns {AuthStatus} Status der Session-Wiederherstellung
     */
    async function restoreSession() {
        const response = await axios.post('/api/', { action: 'restoreSession', features: features, ...getLastCvParams() });

        if (response.data.success) {
            transformResponseToStoreData(response.data);
            return AuthStatus.AUTHENTICATED;
        }

        switch (response.data.text) {
            case 'SETUP_REQUIRED':
                return AuthStatus.SETUP_REQUIRED;
            default:
                return AuthStatus.NOT_AUTHENTICATED;
        }
    }

    /**
     * Prüft ob User authentifiziert ist
     *
     * @returns {AuthStatus} Authentifizierungs-Status
     */
    async function isAuthenticated() {
        if (session.user !== '' && session.client !== '') {
            return AuthStatus.AUTHENTICATED;
        }
        return await restoreSession();
    }

    // =========================================================================
    // CONFIG - MANDANTENKONFIGURATION
    // =========================================================================

    /**
     * Lädt Company Config neu (ohne vollständigen Session-Reload).
     * WICHTIG: Nach jedem Config-Save aufrufen damit alle Komponenten aktuelle Daten sehen!
     */
    async function loadCompanyConfig() {
        const response = await axios.post('/api/oserp_config/', { action: 'getCompanyConfig' });
        if (response.data.success && response.data.payload?.company_config) {
            session.company_config = response.data.payload.company_config;
            features.splice(0, features.length, ...(response.data.payload.company_config.features || []));
        }
    }

    /**
     * Speichert CRM Client Config (defaults_oserp)
     */
    async function saveCrmClientConfig() {
        const response = await axios.post('/api/oserp_config/', {
            action: 'saveCrmDefaults',
            config: session.company_config.defaults_oserp
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, 'Error saving CRM client config: ' + response.data.text);
        }
    }

    // =========================================================================
    // BUCHUNGSGRUPPEN - CRUD
    // =========================================================================

    /**
     * Speichert eine Buchungsgruppe (INSERT/UPDATE)
     *
     * @param {Object} buchungsgruppe - Buchungsgruppen-Daten inkl. taxzone_charts
     * @returns {Object} Response mit success/error
     */
    async function saveBuchungsgruppe(buchungsgruppe) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'saveBuchungsgruppe',
            data: buchungsgruppe
        });

        if (response.data.success) {
            // Lade Config neu damit alle Komponenten die aktualisierten Daten sehen
            await loadCompanyConfig();
            return response.data;
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error saving Buchungsgruppe: ' + response.data.text);
        }
    }

    /**
     * Löscht eine Buchungsgruppe
     *
     * @param {number} id - Buchungsgruppen-ID
     * @returns {Object} Response mit success/error
     */
    async function deleteBuchungsgruppe(id) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'deleteBuchungsgruppe',
            id: id
        });

        if (response.data.success) {
            // Lade Config neu damit alle Komponenten die aktualisierten Daten sehen
            await loadCompanyConfig();
            return response.data;
        } else {
            // Wenn "IN_USE" dann werfe keinen Error sondern gebe Response zurück
            if (response.data.error === 'IN_USE') {
                return response.data;
            }
            throw new ApiError('ApiError', response.data.text, 'Error deleting Buchungsgruppe: ' + response.data.text);
        }
    }

    /**
     * Sortiert Buchungsgruppen neu
     *
     * @param {Array} buchungsgruppeIds - Array von IDs in neuer Reihenfolge
     * @returns {Object} Response mit success/error
     */
    async function reorderBuchungsgruppen(buchungsgruppeIds) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'reorderBuchungsgruppen',
            bg_id: buchungsgruppeIds
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error reordering Buchungsgruppen: ' + response.data.text);
        }
    }

    // =========================================================================
    // STEUERZONEN - CRUD
    // =========================================================================

    /**
     * Speichert eine Steuerzone (INSERT/UPDATE)
     *
     * @param {Object} taxzone - Steuerzonen-Daten inkl. taxzone_charts
     * @returns {Object} Response mit success/error
     */
    async function saveTaxzone(taxzone) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'saveTaxzone',
            data: taxzone
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error saving Taxzone: ' + response.data.text);
        }
    }

    /**
     * Löscht eine Steuerzone
     *
     * @param {number} id - Steuerzonen-ID
     * @returns {Object} Response mit success/error
     */
    async function deleteTaxzone(id) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'deleteTaxzone',
            id: id
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            if (response.data.error === 'IN_USE') {
                return response.data;
            }
            throw new ApiError('ApiError', response.data.text, 'Error deleting Taxzone: ' + response.data.text);
        }
    }

    /**
     * Sortiert Steuerzonen neu
     *
     * @param {Array} taxzoneIds - Array von IDs in neuer Reihenfolge
     * @returns {Object} Response mit success/error
     */
    async function reorderTaxzones(taxzoneIds) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'reorderTaxzones',
            tzone_id: taxzoneIds
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error reordering Taxzones: ' + response.data.text);
        }
    }

    // =========================================================================
    // STEUERN - CRUD
    // =========================================================================

    /**
     * Speichert eine Steuer (INSERT/UPDATE)
     *
     * @param {Object} tax - Steuer-Daten
     * @returns {Object} Response mit success/error
     */
    async function saveTax(tax) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'saveTax',
            data: tax
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error saving Tax: ' + response.data.text);
        }
    }

    /**
     * Löscht eine Steuer
     *
     * @param {number} id - Steuer-ID
     * @returns {Object} Response mit success/error
     */
    async function deleteTax(id) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'deleteTax',
            id: id
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            if (response.data.error === 'IN_USE') {
                return response.data;
            }
            throw new ApiError('ApiError', response.data.text, 'Error deleting Tax: ' + response.data.text);
        }
    }

    // =========================================================================
    // BANK ACCOUNTS - BANKKONTEN
    // =========================================================================

    /**
     * Speichert Bankkonto
     */
    async function saveBankAccount(bankAccount) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'saveBankAccount',
            data: bankAccount
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error saving Bank Account: ' + response.data.text);
        }
    }

    /**
     * Löscht Bankkonto
     */
    async function deleteBankAccount(id) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'deleteBankAccount',
            id: id
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            if (response.data.error === 'IN_USE') {
                return response.data;
            }
            throw new ApiError('ApiError', response.data.text, 'Error deleting Bank Account: ' + response.data.text);
        }
    }

    /**
     * Speichert neue Sortierung der Bankkonten
     */
    async function reorderBankAccounts(bankAccountIds) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'reorderBankAccounts',
            ba_id: bankAccountIds
        });

        if (response.data.success) {
            await loadCompanyConfig();
            return response.data;
        } else {
            throw new ApiError('ApiError', response.data.text, 'Error reordering Bank Accounts: ' + response.data.text);
        }
    }

    /**
     * Holt Bankdaten aus blz_de Tabelle anhand IBAN
     */
    async function getBankDataFromIban(iban) {
        const response = await axios.post('/api/oserp_config/', {
            action: 'getBankDataFromIban',
            iban: iban
        });

        return response.data;
    }

    // =========================================================================
    // BANKING - BANKKONTEN
    // =========================================================================

    const banking_accounts = ref([]);
    const banking_unmatched_total = ref(0);

    /**
     * Laedt Banking-Uebersicht (Konten mit Salden)
     */
    async function fetchBankingOverview() {
        const response = await axios.post('/api/banking/', { action: 'getBankingOverview' });
        if (response.data.success) {
            banking_accounts.value = response.data.payload.accounts || [];
            banking_unmatched_total.value = banking_accounts.value.reduce(
                (sum, a) => sum + (a.unmatched_count || 0), 0
            );
        }
    }

    // =========================================================================
    // CUSTOMER/VENDOR - KUNDEN/LIEFERANTEN
    // =========================================================================

    /**
     * Duplikat-Pruefung fuer Kunden/Lieferanten (Exakt + Teilname)
     */
    async function checkDuplicateCV(name, street, zipcode, excludeId = 0, src = 'C') {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'checkDuplicateCV',
            name, street, zipcode, exclude_id: excludeId, src
        });
        return response.data?.payload || { exact: [], partial: [] };
    }

    /**
     * Speichert Customer/Vendor
     */
    async function saveCV() {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'saveCV',
            profile: customer_vendor.value.profile,
            additional_billing_addresses: customer_vendor.value.additional_billing_addresses,
            contacts: customer_vendor.value.contacts,
            shiptos: customer_vendor.value.shiptos
        });

        if (!response.data.success) {
            throw new ApiError('ApiError', response.data.text, response.data.payload || response.data.text);
        }

        return response.data;
    }

    /**
     * Lädt letzten Customer/Vendor — nutzt gespeicherte ID aus localStorage falls vorhanden
     */
    async function fetchLastCustomerOrVendor() {
        const params = { action: 'getCV' };
        const savedId = localStorage.getItem('oserp_last_cv_id');
        const savedSrc = localStorage.getItem('oserp_last_cv_src');
        if (savedId) {
            params.customerId = savedId;
            params.src = savedSrc || 'C';
        }
        const response = await axios.post('/api/customer_vendor/', params);

        if (response.data.success) {
            customer_vendor.value = response.data.payload.main.customer_vendor;
        } else {
            customer_vendor.value = null;
        }
    }

    /**
     * Lädt spezifischen Customer/Vendor und speichert ID im Browser
     */
    async function fetchCustomerOrVendor(customerId, src) {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'getCV',
            customerId: customerId,
            src: src,
            features: features
        });

        if (response.data.success) {
            const cv = response.data.payload.main.customer_vendor;
            if (cv?.profile?.id) {
                customer_vendor.value = cv;
                localStorage.setItem('oserp_last_cv_id', cv.profile.id);
                localStorage.setItem('oserp_last_cv_src', src || 'C');
            } else {
                customer_vendor.value = null;
            }
        } else {
            customer_vendor.value = null;
        }
    }

    // =========================================================================
    // EXPORTS - PUBLIC API
    // =========================================================================

    return {
        // Constants
        AuthStatus,

        // State
        session,
        customer_vendor,
        features,

        // Helper Functions
        getConfigValue,
        setConfigValue,
        deleteConfigValue,
        getClientDefaultValue,
        isFeatureEnabled,
        isLxCars,
        isAnprEnabled,
        isNvrEnabled,
        checkPermission,
        permit,
        isDebugMode,

        // Authentication
        fetchClients,
        login,
        logout,
        switchClient,
        createCompany,
        getLoginTimestamp,
        restoreSession,
        isAuthenticated,

        // Config
        loadCompanyConfig,
        saveCrmClientConfig,
        getStartupViewConfig,
        getAuthUserData,

        // Buchungsgruppen
        saveBuchungsgruppe,
        deleteBuchungsgruppe,
        reorderBuchungsgruppen,

        // Steuerzonen
        saveTaxzone,
        deleteTaxzone,
        reorderTaxzones,

        // Steuern
        saveTax,
        deleteTax,

        // Bank Accounts
        saveBankAccount,
        deleteBankAccount,
        reorderBankAccounts,
        getBankDataFromIban,

        // Banking
        banking_accounts,
        banking_unmatched_total,
        fetchBankingOverview,

        // Customer/Vendor
        checkDuplicateCV,
        saveCV,
        fetchLastCustomerOrVendor,
        fetchCustomerOrVendor
    };
});
