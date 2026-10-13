// src/core/views/config/tabs/masterListsConfig.js
//
// Darstellung der Stammdaten-Listen (Firmenkonfiguration → Werkzeuge →
// Stammdaten-Listen). Welche Tabellen und Spalten das Backend zulässt, steht
// in backend/api/oserp_config/master_lists.php — hier nur, wie sie aussehen.
//
// Feldtypen:
//   text, textarea, int, number        Eingabefelder
//   percent                            Bruch in der DB (0,05), Prozent im Dialog (5)
//   bool                               Schalter
//   select                             feste Auswahl (options: value + i18n-Schlüssel)
//   self                               Auswahl aus derselben Liste (itemValue = Spalte),
//                                      der Eintrag selbst ist ausgeschlossen
//
//   lockedWhenUsed  bei verwendeten Einträgen nicht änderbar (Backend sperrt ebenso)
//   defaults        Vorbelegung für neue Einträge
//   chips(item)     Kurzinfo in der Liste, Array aus { icon?, text }

const masterLists = [
    {
        key: 'units',
        icon: 'mdi-ruler',
        label: 'name',
        sortable: true,
        defaults: { type: 'dimension' },
        keywords: ['einheit', 'einheiten', 'unit', 'units', 'stück', 'stk', 'stunde', 'kg', 'liter', 'basiseinheit', 'umrechnung'],
        fields: [
            { name: 'name', type: 'text', required: true, lockedWhenUsed: true, cols: 6 },
            { name: 'type', type: 'select', cols: 6, options: [
                { value: 'dimension', label: 'masterLists.units.type_dimension' },
                { value: 'service',   label: 'masterLists.units.type_service' },
            ] },
            { name: 'base_unit', type: 'self', itemValue: 'name', cols: 6, filterSelf: (item, other) => !item.type || !other.type || item.type === other.type },
            { name: 'factor', type: 'number', cols: 6, help: 'masterLists.units.factor_help' },
        ],
        chips: (item, t) => [
            item.type ? { icon: item.type === 'service' ? 'mdi-account-wrench' : 'mdi-cube-outline', text: t(`masterLists.units.type_${item.type}`) } : null,
            item.base_unit ? { icon: 'mdi-swap-horizontal', text: `1 ${item.name} = ${fmt(item.factor)} ${item.base_unit}` } : null,
        ],
    },
    {
        key: 'business',
        icon: 'mdi-account-group-outline',
        label: 'description',
        sortable: false,
        keywords: ['kundentyp', 'kundentypen', 'kundengruppe', 'kundengruppen', 'business', 'type of business', 'gewerblich', 'privat', 'rabatt'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 12 },
            { name: 'discount', type: 'percent', cols: 6, help: 'masterLists.business.discount_help' },
            { name: 'customernumberinit', type: 'text', cols: 6, help: 'masterLists.business.customernumberinit_help' },
            { name: 'salesman', type: 'bool', cols: 12, help: 'masterLists.business.salesman_help' },
        ],
        chips: (item, t) => [
            Number(item.discount) ? { icon: 'mdi-sale', text: `${fmt(Number(item.discount) * 100)} %` } : null,
            item.customernumberinit ? { icon: 'mdi-counter', text: `${t('masterLists.business.fields.customernumberinit')}: ${item.customernumberinit}` } : null,
            item.salesman ? { icon: 'mdi-account-tie', text: t('masterLists.business.fields.salesman') } : null,
        ],
    },
    {
        key: 'partsgroup',
        icon: 'mdi-shape-outline',
        label: 'partsgroup',
        sortable: true,
        keywords: ['warengruppe', 'warengruppen', 'partsgroup', 'artikelgruppe', 'artikelgruppen'],
        fields: [
            { name: 'partsgroup', type: 'text', required: true, cols: 12 },
            { name: 'obsolete', type: 'bool', cols: 12 },
        ],
        chips: () => [],
    },
    {
        key: 'pricegroup',
        icon: 'mdi-tag-multiple-outline',
        label: 'pricegroup',
        sortable: true,
        keywords: ['preisgruppe', 'preisgruppen', 'pricegroup', 'preisliste', 'händler', 'endkunde'],
        fields: [
            { name: 'pricegroup', type: 'text', required: true, cols: 12 },
            { name: 'obsolete', type: 'bool', cols: 12 },
        ],
        chips: () => [],
    },
    {
        key: 'price_factors',
        icon: 'mdi-multiplication',
        label: 'description',
        sortable: true,
        keywords: ['preisfaktor', 'preisfaktoren', 'price factor', 'pro 100', 'pro 1000', 'mengeneinheit'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 6 },
            { name: 'factor', type: 'number', required: true, cols: 6, help: 'masterLists.price_factors.factor_help' },
        ],
        chips: (item) => [
            item.factor ? { icon: 'mdi-division', text: `÷ ${fmt(item.factor)}` } : null,
        ],
    },
    {
        key: 'department',
        icon: 'mdi-office-building-outline',
        label: 'description',
        sortable: false,
        keywords: ['abteilung', 'abteilungen', 'department', 'kostenstelle', 'filiale'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 12 },
        ],
        chips: () => [],
    },
    {
        key: 'payment_terms',
        icon: 'mdi-cash-clock',
        label: 'description',
        sortable: true,
        defaults: { terms_netto: 0, terms_skonto: 0, percent_skonto: 0, auto_calculation: true },
        keywords: ['zahlungsbedingung', 'zahlungsbedingungen', 'payment terms', 'zahlungsziel', 'skonto', 'netto', 'vorkasse', 'lastschrift'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 12 },
            { name: 'terms_netto', type: 'int', cols: 4, suffix: 'masterLists.days' },
            { name: 'terms_skonto', type: 'int', cols: 4, suffix: 'masterLists.days' },
            { name: 'percent_skonto', type: 'percent', cols: 4, suffix: '%' },
            { name: 'auto_calculation', type: 'bool', cols: 12, help: 'masterLists.payment_terms.auto_calculation_help' },
            { name: 'description_long', type: 'textarea', cols: 12, help: 'masterLists.payment_terms.description_long_help' },
            { name: 'description_long_invoice', type: 'textarea', cols: 12, help: 'masterLists.payment_terms.description_long_invoice_help' },
            { name: 'obsolete', type: 'bool', cols: 12 },
        ],
        chips: (item, t) => [
            { icon: 'mdi-calendar-clock', text: `${item.terms_netto ?? 0} ${t('masterLists.days')}` },
            Number(item.percent_skonto) ? { icon: 'mdi-sale', text: `${fmt(Number(item.percent_skonto) * 100)} % / ${item.terms_skonto ?? 0} ${t('masterLists.days')}` } : null,
        ],
    },
    {
        key: 'delivery_terms',
        icon: 'mdi-truck-delivery-outline',
        label: 'description',
        sortable: true,
        keywords: ['lieferbedingung', 'lieferbedingungen', 'delivery terms', 'frei haus', 'ab werk', 'incoterms'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 12 },
            { name: 'description_long', type: 'textarea', cols: 12 },
            { name: 'obsolete', type: 'bool', cols: 12 },
        ],
        chips: () => [],
    },
    {
        key: 'language',
        icon: 'mdi-translate',
        label: 'description',
        sortable: false,
        defaults: { output_numberformat: '', output_dateformat: '' },
        keywords: ['sprache', 'sprachen', 'language', 'languages', 'belegsprache', 'übersetzung', 'vorlagencode', 'zahlenformat', 'datumsformat'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 6 },
            { name: 'template_code', type: 'text', cols: 3, help: 'masterLists.language.template_code_help' },
            { name: 'article_code', type: 'text', cols: 3, help: 'masterLists.language.article_code_help' },
            { name: 'output_numberformat', type: 'select', cols: 6, options: [
                { value: '',         label: 'masterLists.language.format_default' },
                { value: '1,000.00', label: '1,000.00' },
                { value: '1000.00',  label: '1000.00' },
                { value: '1.000,00', label: '1.000,00' },
                { value: '1000,00',  label: '1000,00' },
                { value: "1'000.00", label: "1'000.00" },
            ] },
            { name: 'output_dateformat', type: 'select', cols: 6, options: [
                { value: '',         label: 'masterLists.language.format_default' },
                { value: 'dd.mm.yy', label: 'dd.mm.yy' },
                { value: 'dd/mm/yy', label: 'dd/mm/yy' },
                { value: 'mm/dd/yy', label: 'mm/dd/yy' },
                { value: 'yyyy-mm-dd', label: 'yyyy-mm-dd' },
            ] },
            { name: 'output_longdates', type: 'bool', cols: 6 },
            { name: 'obsolete', type: 'bool', cols: 6 },
        ],
        chips: (item) => [
            item.template_code ? { icon: 'mdi-file-document-outline', text: item.template_code } : null,
            item.output_numberformat ? { icon: 'mdi-numeric', text: item.output_numberformat } : null,
            item.output_dateformat ? { icon: 'mdi-calendar', text: item.output_dateformat } : null,
        ],
    },
    {
        key: 'project_types',
        icon: 'mdi-folder-cog-outline',
        label: 'description',
        sortable: true,
        keywords: ['projekttyp', 'projekttypen', 'project type', 'projekt', 'festpreis', 'support'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 12 },
            { name: 'internal', type: 'bool', cols: 12, help: 'masterLists.project_types.internal_help' },
        ],
        chips: (item, t) => [
            item.internal ? { icon: 'mdi-home-lock-outline', text: t('masterLists.project_types.fields.internal') } : null,
        ],
    },
    {
        key: 'project_statuses',
        icon: 'mdi-progress-check',
        label: 'description',
        sortable: true,
        keywords: ['projektstatus', 'project status', 'projekt', 'akquise', 'planung', 'fertiggestellt'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 6 },
            { name: 'name', type: 'text', cols: 6, help: 'masterLists.project_statuses.name_help' },
        ],
        chips: (item) => [
            item.name ? { icon: 'mdi-key-variant', text: item.name } : null,
        ],
    },
    {
        key: 'greetings',
        icon: 'mdi-hand-wave-outline',
        label: 'description',
        sortable: false,
        keywords: ['anrede', 'anreden', 'greeting', 'herr', 'frau', 'firma', 'titel'],
        fields: [
            { name: 'description', type: 'text', required: true, cols: 12 },
        ],
        chips: () => [],
    },
];

/** Zahl ohne Nachkomma-Rauschen (1000.00000 → 1000, 2.5 → 2,5). */
function fmt(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return '';
    return n.toLocaleString(undefined, { maximumFractionDigits: 5 });
}

export default masterLists;
