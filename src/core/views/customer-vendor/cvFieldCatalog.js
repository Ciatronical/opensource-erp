// src/core/views/customer-vendor/cvFieldCatalog.js

/**
 * Feldkatalog der Kunden-/Lieferantenmaske.
 *
 * Eine Quelle für zwei Stellen: die Firmenkonfiguration (CRM → Einfacher Modus →
 * Kunde anlegen / bearbeiten) bietet genau diese Felder und Reiter zur Auswahl an,
 * und die Maske blendet im einfachen Modus alles aus, was dort nicht gewählt ist.
 *
 * `key`      Schlüssel in der Konfiguration (defaults_oserp.cv_easymode_fields)
 * `label`    i18n-Schlüssel der Feldbeschriftung
 * `model`    Spalten im Profil, die zu diesem Feld gehören (für "ausgefüllt, aber
 *            ausgeblendet"); fehlt es, heißt die Spalte wie der Schlüssel
 * `required` Pflichtfeld für das Speichern — im einfachen Modus immer sichtbar
 * `defaulted` wird bei der Neuanlage vorbelegt und zählt deshalb nicht als
 *            "vom Benutzer ausgefüllt"
 * `src`      nur für Kunden ('C') bzw. Lieferanten ('V')
 */
export const CV_FIELD_GROUPS = [
    {
        key: 'nameAddress',
        title: 'CustomerVendorEditView.billing.nameAddressTitle',
        icon: 'mdi-card-account-details-outline',
        fields: [
            { key: 'name', label: 'CustomerVendorEditView.fields.name', required: true },
            { key: 'keywords', label: 'CustomerVendorEditView.fields.keywords' },
            { key: 'greeting', label: 'CustomerVendorEditView.fields.greeting' },
            { key: 'zipcode', label: 'CustomerVendorEditView.fields.zipcode', required: true },
            { key: 'city', label: 'CustomerVendorEditView.fields.city', required: true },
            { key: 'street', label: 'CustomerVendorEditView.fields.street', required: true },
            { key: 'country', label: 'CustomerVendorEditView.fields.country', defaulted: true },
            { key: 'natural_person', label: 'CustomerVendorEditView.fields.natural_person', defaulted: true },
            { key: 'department_1', label: 'CustomerVendorEditView.fields.department_1' },
            { key: 'department_2', label: 'CustomerVendorEditView.fields.department_2' },
            { key: 'gln', label: 'CustomerVendorEditView.fields.gln' },
            { key: 'commercial_court', label: 'CustomerVendorEditView.fields.commercial_court', src: 'C' },
        ],
    },
    {
        key: 'communication',
        title: 'CustomerVendorEditView.billing.communicationTitle',
        icon: 'mdi-phone-message-outline',
        fields: [
            { key: 'contact', label: 'CustomerVendorEditView.fields.contact' },
            { key: 'phone', label: 'CustomerVendorEditView.fields.phone' },
            { key: 'phone_numbers', label: 'CustomerVendorEditView.fields.phone_numbers' },
            { key: 'email', label: 'CustomerVendorEditView.fields.email' },
            { key: 'cc', label: 'CustomerVendorEditView.fields.cc' },
            { key: 'bcc', label: 'CustomerVendorEditView.fields.bcc' },
            { key: 'emails', label: 'CustomerVendorEditView.fields.emails' },
            { key: 'homepage', label: 'CustomerVendorEditView.fields.homepage' },
            { key: 'urls', label: 'CustomerVendorEditView.fields.urls' },
            { key: 'invoice_mail', label: 'CustomerVendorEditView.fields.invoice_mail', src: 'C' },
            { key: 'delivery_order_mail', label: 'CustomerVendorEditView.fields.delivery_order_mail', src: 'C' },
        ],
    },
    {
        key: 'infoStatus',
        title: 'CustomerVendorEditView.billing.infoStatusTitle',
        icon: 'mdi-information-outline',
        fields: [
            // Pflicht, sobald mehr als ein Kundentyp existiert — die Maske prüft das selbst
            { key: 'business_type', label: 'CustomerVendorEditView.fields.business_type', model: ['business_id'], required: true, src: 'C' },
            { key: 'salesman', label: 'CustomerVendorEditView.fields.salesman', model: ['salesman_id'], defaulted: true },
            { key: 'language', label: 'CustomerVendorEditView.fields.language', model: ['language_id'] },
            { key: 'obsolete', label: 'CustomerVendorEditView.fields.obsolete' },
            { key: 'notes', label: 'CustomerVendorEditView.fields.notes' },
            { key: 'contact_origin', label: 'CustomerVendorEditView.fields.contact_origin' },
        ],
    },
    {
        key: 'numbersIds',
        title: 'CustomerVendorEditView.billing.numbersIdsTitle',
        icon: 'mdi-identifier',
        fields: [
            { key: 'customernumber', label: 'CustomerVendorEditView.fields.customernumber', model: ['customernumber', 'vendornumber'] },
            { key: 'taxnumber', label: 'CustomerVendorEditView.fields.taxnumber' },
            { key: 'ustid', label: 'CustomerVendorEditView.fields.ustid' },
            { key: 'c_vendor_id', label: 'CustomerVendorEditView.fields.c_vendor_id' },
            { key: 'c_vendor_routing_id', label: 'CustomerVendorEditView.fields.c_vendor_routing_id' },
        ],
    },
    {
        key: 'accessData',
        title: 'CustomerVendorEditView.billing.accessDataTitle',
        icon: 'mdi-key-outline',
        fields: [
            { key: 'username', label: 'CustomerVendorEditView.fields.username' },
            { key: 'password', label: 'CustomerVendorEditView.fields.password', model: ['user_password'] },
        ],
    },
    {
        key: 'currencyPricesTax',
        title: 'CustomerVendorEditView.billing.currencyPricesTaxTitle',
        icon: 'mdi-cash-multiple',
        fields: [
            { key: 'currency', label: 'CustomerVendorEditView.fields.currency', model: ['currency_id'], defaulted: true },
            { key: 'pricegroup', label: 'CustomerVendorEditView.fields.pricegroup', model: ['pricegroup_id'] },
            { key: 'taxzone', label: 'CustomerVendorEditView.fields.taxzone', model: ['taxzone_id'], defaulted: true },
            { key: 'taxincluded_checked', label: 'CustomerVendorEditView.fields.taxincluded_checked' },
            { key: 'discount', label: 'CustomerVendorEditView.fields.discount' },
            { key: 'hourly_rate', label: 'CustomerVendorEditView.fields.hourly_rate', defaulted: true },
            { key: 'create_zugferd_invoices', label: 'CustomerVendorEditView.fields.create_zugferd_invoices', defaulted: true, src: 'C' },
        ],
    },
    {
        key: 'conditions',
        title: 'CustomerVendorEditView.billing.conditionsTitle',
        icon: 'mdi-handshake-outline',
        fields: [
            { key: 'creditlimit', label: 'CustomerVendorEditView.fields.creditlimit' },
            { key: 'dunning_lock', label: 'CustomerVendorEditView.fields.dunning_lock' },
            { key: 'order_lock', label: 'CustomerVendorEditView.fields.order_lock' },
            { key: 'direct_debit', label: 'CustomerVendorEditView.fields.direct_debit' },
            { key: 'payment_terms', label: 'CustomerVendorEditView.fields.payment_terms', model: ['payment_id'] },
            { key: 'delivery_terms', label: 'CustomerVendorEditView.fields.delivery_terms', model: ['delivery_term_id'] },
            { key: 'hu_notify', label: 'CustomerVendorEditView.fields.hu_notify', model: ['hu_serienbrief_excluded'], defaulted: true },
        ],
    },
]

/** Reiter der Maske; `fixed` ist immer sichtbar, `editOnly` erst nach dem Anlegen */
export const CV_TABS = [
    { key: 'billing', label: 'CustomerVendorEditView.tabs.billing', icon: 'mdi-receipt', fixed: true },
    { key: 'additional-billing', label: 'CustomerVendorEditView.tabs.additionalBilling', icon: 'mdi-receipt-text-plus', badge: 'billingAddresses' },
    { key: 'bank', label: 'CustomerVendorEditView.tabs.bank', icon: 'mdi-bank' },
    { key: 'shipto', label: 'CustomerVendorEditView.tabs.shipto', icon: 'mdi-truck-delivery', badge: 'shiptos' },
    { key: 'contacts', label: 'CustomerVendorEditView.tabs.contacts', icon: 'mdi-account-group', badge: 'contacts' },
    { key: 'deliveries', label: 'CustomerVendorEditView.tabs.deliveries', icon: 'mdi-package-variant' },
    { key: 'notes', label: 'CustomerVendorEditView.tabs.notes', icon: 'mdi-note-text' },
    { key: 'custom-vars', label: 'CustomerVendorEditView.tabs.customVars', icon: 'mdi-variable', badge: 'customVars' },
    { key: 'files', label: 'CustomerVendorEditView.tabs.files', icon: 'mdi-folder-open', editOnly: true },
    { key: 'price-rules', label: 'CustomerVendorEditView.tabs.priceRules', icon: 'mdi-tag-multiple' },
    { key: 'turnover', label: 'CustomerVendorEditView.tabs.turnover', icon: 'mdi-chart-line' },
    { key: 'history', label: 'CustomerVendorEditView.tabs.history', icon: 'mdi-history', editOnly: true },
]

/** Empfohlene Auswahl, solange die Firma nichts anderes gespeichert hat */
export const CV_EASYMODE_DEFAULT_FIELDS = [
    'name', 'greeting', 'zipcode', 'city', 'street', 'country',
    'phone', 'email',
    'business_type', 'notes',
    'customernumber', 'ustid',
    'payment_terms',
]

export const CV_EASYMODE_DEFAULT_TABS = ['billing', 'shipto', 'contacts', 'bank', 'files', 'history']

export const CV_ALL_FIELDS = CV_FIELD_GROUPS.flatMap(g => g.fields)

export const CV_REQUIRED_FIELD_KEYS = CV_ALL_FIELDS.filter(f => f.required).map(f => f.key)

/** Firmenkonfiguration lesen: JSON-Array als Text, sonst die Empfehlung */
export function parseFieldList(value, fallback) {
    if (Array.isArray(value)) return value
    if (typeof value === 'string' && value.trim().startsWith('[')) {
        try {
            const parsed = JSON.parse(value)
            if (Array.isArray(parsed)) return parsed
        } catch (e) {
            // kaputter Wert → Empfehlung
        }
    }
    return [...fallback]
}

/** Ist im Profil für dieses Feld etwas eingetragen? */
export function fieldHasValue(field, profile) {
    if (!profile) return false
    const columns = field.model || [field.key]
    return columns.some(col => {
        const v = profile[col]
        if (v === null || v === undefined || v === false || v === '') return false
        if (Array.isArray(v)) return v.length > 0
        if (typeof v === 'number') return v !== 0
        if (typeof v === 'string') return v.trim() !== ''
        return true
    })
}
