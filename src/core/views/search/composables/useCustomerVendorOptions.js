// src/modules/customer_vendor/composables/useCustomerVendorOptions.js
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';

// CACHE: Computed Properties nur 1x erstellen, nicht bei jedem Component-Mount!
let cachedOptions = null;

/**
 * Composable für gemeinsame Dropdown-Optionen in Customer/Vendor Search
 *
 * OPTIMIERT: Verwendet Caching um wiederholte Store-Zugriffe zu vermeiden
 *
 * @returns {Object} Objekt mit allen computed properties für Dropdown-Optionen
 */
export function useCustomerVendorOptions() {
    const { t } = useI18n();

    // Wenn bereits gecacht, gib Cache zurück
    if (cachedOptions) {
        return cachedOptions;
    }

    // Store-Zugriff mit Fallback
    let store;
    try {
        store = oserpStore();
    } catch (error) {
        console.error('Error accessing oserpStore:', error);
        store = { session: null };
    }

    // Verkäufer-Liste aus oserpStore
    const salesmenOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const employees = store.session?.company_config?.employees;
            if (!employees || !Array.isArray(employees)) return [];
            return employees.map(emp => ({
                title: emp.name || `${emp.firstname || ''} ${emp.lastname || ''}`.trim() || 'Unbenannt',
                value: emp.id
            }));
        } catch (error) {
            console.error('Error loading salesmen options:', error);
            return [];
        }
    });

    // Zahlungsbedingungen aus oserpStore
    const paymentTermsOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const paymentTerms = store.session?.company_config?.payment_terms;
            if (!paymentTerms || !Array.isArray(paymentTerms)) return [];
            return paymentTerms.map(term => ({
                title: term.description || term.name || 'Unbenannt',
                value: term.id
            }));
        } catch (error) {
            console.error('Error loading payment terms options:', error);
            return [];
        }
    });

    // Steuersätze aus oserpStore
    const taxZoneOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const taxZones = store.session?.company_config?.tax_zones;
            if (!taxZones || !Array.isArray(taxZones)) return [];
            return taxZones
                .filter(zone => !zone.obsolete)
                .map(zone => ({
                    title: zone.description || 'Unbenannt',
                    value: zone.id
                }));
        } catch (error) {
            console.error('Error loading tax zone options:', error);
            return [];
        }
    });

    // Währungen aus oserpStore
    const currencyOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const currencies = store.session?.company_config?.currencies;
            if (!currencies || !Array.isArray(currencies)) return [];
            return currencies.map(currency => ({
                title: currency.name || 'Unbenannt',
                value: currency.id
            }));
        } catch (error) {
            console.error('Error loading currency options:', error);
            return [];
        }
    });

    // Sprachen aus oserpStore
    const languageOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const languages = store.session?.company_config?.languages;
            if (!languages || !Array.isArray(languages)) return [];
            return languages.map(lang => ({
                title: lang.description || 'Unbenannt',
                value: lang.id
            }));
        } catch (error) {
            console.error('Error loading language options:', error);
            return [];
        }
    });

    // Kundentypen aus oserpStore
    const businessOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const businesses = store.session?.company_config?.business_types;
            if (!businesses || !Array.isArray(businesses)) return [];
            return businesses.map(biz => ({
                title: biz.description || 'Unbenannt',
                value: biz.id
            }));
        } catch (error) {
            console.error('Error loading business options:', error);
            return [];
        }
    });

    // Preisgruppen aus oserpStore
    const pricegroupOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const pricegroups = store.session?.company_config?.pricegroups;
            if (!pricegroups || !Array.isArray(pricegroups)) return [];
            return pricegroups.map(pg => ({
                title: pg.pricegroup || 'Unbenannt',
                value: pg.id
            }));
        } catch (error) {
            console.error('Error loading pricegroup options:', error);
            return [];
        }
    });

    // Lieferbedingungen aus oserpStore
    const deliveryTermOptions = computed(() => {
        try {
            if (!store || !store.session) return [];
            const deliveryTerms = store.session?.company_config?.delivery_terms;
            if (!deliveryTerms || !Array.isArray(deliveryTerms)) return [];
            return deliveryTerms.map(term => ({
                title: term.description || 'Unbenannt',
                value: term.id
            }));
        } catch (error) {
            console.error('Error loading delivery term options:', error);
            return [];
        }
    });

    // ZUGFeRD Einstellungen
    const zugferdSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.zugferd_options.1'), value: -1 },
                { label: t('CustomerVendorSearchView.selects.zugferd_options.2'), value: 0 },
                { label: t('CustomerVendorSearchView.selects.zugferd_options.3'), value: 1 },
                { label: t('CustomerVendorSearchView.selects.zugferd_options.4'), value: 2 },
                { label: t('CustomerVendorSearchView.selects.zugferd_options.5'), value: 3 },
                { label: t('CustomerVendorSearchView.selects.zugferd_options.6'), value: 4 },
            ];
        } catch (error) {
            console.error('Error loading zugferd options:', error);
            return [];
        }
    });

    // Status Optionen
    const statusSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.status_options.active'), value: false },
                { label: t('CustomerVendorSearchView.selects.status_options.inactive'), value: true },
            ];
        } catch (error) {
            console.error('Error loading status options:', error);
            return [];
        }
    });

    // Mahnsperre Optionen
    const dunningLockSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.dunning_lock_options.yes'), value: true },
                { label: t('CustomerVendorSearchView.selects.dunning_lock_options.no'), value: false },
            ];
        } catch (error) {
            console.error('Error loading dunning lock options:', error);
            return [];
        }
    });

    // Natürliche Person Optionen
    const naturalPersonSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.natural_person_options.yes'), value: true },
                { label: t('CustomerVendorSearchView.selects.natural_person_options.no'), value: false },
            ];
        } catch (error) {
            console.error('Error loading natural person options:', error);
            return [];
        }
    });

    // Steuer inkludiert Optionen
    const taxIncludedSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.taxincluded_options.yes'), value: true },
                { label: t('CustomerVendorSearchView.selects.taxincluded_options.no'), value: false },
            ];
        } catch (error) {
            console.error('Error loading tax included options:', error);
            return [];
        }
    });

    // Auftragssperre Optionen
    const orderLockSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.order_lock_options.yes'), value: true },
                { label: t('CustomerVendorSearchView.selects.order_lock_options.no'), value: false },
            ];
        } catch (error) {
            console.error('Error loading order lock options:', error);
            return [];
        }
    });

    // Rechnung per Post Optionen
    const postalInvoiceSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.postal_invoice_options.yes'), value: true },
                { label: t('CustomerVendorSearchView.selects.postal_invoice_options.no'), value: false },
            ];
        } catch (error) {
            console.error('Error loading postal invoice options:', error);
            return [];
        }
    });

    // Lastschrifteinzug Optionen
    const directDebitSettingsOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.direct_debit_options.yes'), value: true },
                { label: t('CustomerVendorSearchView.selects.direct_debit_options.no'), value: false },
            ];
        } catch (error) {
            console.error('Error loading direct debit options:', error);
            return [];
        }
    });

    // Geschlecht Optionen
    const genderOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.gender_options.m'), value: 'm' },
                { label: t('CustomerVendorSearchView.selects.gender_options.f'), value: 'f' }
            ];
        } catch (error) {
            console.error('Error loading gender options:', error);
            return [];
        }
    });

    // Hauptansprechpartner Optionen
    const mainContactOptions = computed(() => {
        try {
            return [
                { label: t('CustomerVendorSearchView.selects.main_contact_options.yes'), value: true },
                { label: t('CustomerVendorSearchView.selects.main_contact_options.no'), value: false }
            ];
        } catch (error) {
            console.error('Error loading main contact options:', error);
            return [];
        }
    });

    // Cache erstellen
    cachedOptions = {
        salesmenOptions,
        paymentTermsOptions,
        taxZoneOptions,
        currencyOptions,
        languageOptions,
        businessOptions,
        pricegroupOptions,
        deliveryTermOptions,
        zugferdSettingsOptions,
        statusSettingsOptions,
        dunningLockSettingsOptions,
        naturalPersonSettingsOptions,
        taxIncludedSettingsOptions,
        orderLockSettingsOptions,
        postalInvoiceSettingsOptions,
        directDebitSettingsOptions,
        genderOptions,
        mainContactOptions,
    };

    return cachedOptions;
}

/**
 * Cache leeren (z.B. bei Logout oder Session-Wechsel)
 */
export function clearCustomerVendorOptionsCache() {
    cachedOptions = null;
}
