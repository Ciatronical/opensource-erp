// src/core/views/template-designer/designer.blocks.js
//
// Bausteine, Startvorlagen und Hilfsfunktionen des Vorlageneditors.
//
// Ein Design ist reines JSON (siehe backend/api/print/template_design_compiler.php):
//   page    Seite, Ränder des Fließbereichs, Schrift, Farben, Marken
//   blocks  frei platzierte Bausteine in Millimetern
//   body    Fließbereich mit Abschnitten (Betreff, Text, Tabelle, Summen, …)
//
// Alle Maße sind Millimeter, Schriftgrößen Punkt. Der Canvas rechnet mit
// einem Maßstab (px je mm) um, der Generator nimmt die Werte direkt.

export const PAGE_WIDTH = 210;
export const PAGE_HEIGHT = 297;

/** Schriftgrad in Punkt → Millimeter (1 pt = 1/72 Zoll) */
export const PT_TO_MM = 25.4 / 72;

let counter = 0;
/** Kurze eindeutige Kennung für Bausteine und Abschnitte */
export function uid(prefix = 'b') {
    counter += 1;
    return `${prefix}${Date.now().toString(36)}${counter.toString(36)}`;
}

/** Tiefe Kopie ohne Referenzen (Designs sind reines JSON) */
export function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

/**
 * Baustein-Typen des Canvas: Symbol, Standardgröße, Eigenschaften.
 * Beschriftungen kommen aus TemplateDesigner.blocks.<type>.
 */
export const BLOCK_TYPES = {
    text:       { icon: 'mdi-text',                 w: 80, h: 20 },
    infobox:    { icon: 'mdi-table-row',            w: 65, h: 36 },
    image:      { icon: 'mdi-image-outline',        w: 60, h: 25 },
    line:       { icon: 'mdi-minus',                w: 170, h: 1 },
    rect:       { icon: 'mdi-rectangle-outline',    w: 60, h: 30 },
    pagenumber: { icon: 'mdi-numeric',              w: 40, h: 5 },
    qrcode:     { icon: 'mdi-qrcode',               w: 22, h: 22 },
};

/**
 * Fertige Bausteine für die Palette: ein Klick legt sie mit sinnvollem
 * Inhalt an. Die Vorschläge (Firmendaten) füllen Absender und Fußzeile.
 *
 * @param {Function} t - Übersetzer
 * @param {Object} s - Firmenvorschläge aus companySuggestions()
 * @returns {Array} Liste von {key, icon, title, build()}
 */
export function paletteItems(t, s) {
    const companyLine = [s.company, s.street, s.cityLine].filter(Boolean).join(' · ');
    return [
        {
            key: 'logo', icon: 'mdi-image-outline', title: t('TemplateDesigner.palette.logo'),
            build: () => block('image', 120, 12, 70, 22, 'all', { image: '', align: 'right' })
        },
        {
            key: 'sender', icon: 'mdi-email-outline', title: t('TemplateDesigner.palette.sender'),
            build: () => block('text', 20, 45, 85, 5, 'first', {
                text: companyLine || t('TemplateDesigner.palette.senderPlaceholder'),
                fontSize: 7, underline: true, color: '#555555'
            })
        },
        {
            key: 'address', icon: 'mdi-account-box-outline', title: t('TemplateDesigner.palette.address'),
            build: () => block('text', 20, 52, 85, 38, 'first', {
                text: '<%name%>\n<%department_1%>\n<%cp_givenname%> <%cp_name%>\n<%street%>\n<%zipcode%> <%city%>\n<%country%>'
            })
        },
        {
            key: 'shipto', icon: 'mdi-truck-outline', title: t('TemplateDesigner.palette.shipto'),
            build: () => block('text', 20, 92, 85, 20, 'first', {
                text: '**' + t('TemplateDesigner.palette.shiptoHeading') + '**\n<%shiptoname%>\n<%shiptostreet%>\n<%shiptozipcode%> <%shiptocity%>',
                fontSize: 8, condition: 'shiptoname'
            })
        },
        {
            key: 'infobox', icon: 'mdi-table-row', title: t('TemplateDesigner.palette.infobox'),
            build: (docType) => block('infobox', 125, 50, 65, 40, 'first', {
                fontSize: 9, labelWidth: 30, labelBold: false, valueAlign: 'right',
                rows: infoRows(t, docType)
            })
        },
        {
            key: 'text', icon: 'mdi-text', title: t('TemplateDesigner.palette.text'),
            build: () => block('text', 20, 120, 80, 15, 'first', { text: t('TemplateDesigner.palette.textPlaceholder') })
        },
        {
            key: 'runhead', icon: 'mdi-page-next-outline', title: t('TemplateDesigner.palette.runhead'),
            build: (docType) => block('text', 20, 22, 120, 8, 'following', {
                text: `${docLabel(t, docType)} <%${numberField(docType)}%> · ${t('TemplateDesigner.palette.pageOf')}`,
                fontSize: 8, color: '#777777'
            })
        },
        {
            key: 'footer', icon: 'mdi-page-layout-footer', title: t('TemplateDesigner.palette.footer'),
            build: () => block('text', 20, 268, 170, 22, 'all', {
                text: footerText(s), fontSize: 7, align: 'center', color: '#555555'
            })
        },
        {
            key: 'pagenumber', icon: 'mdi-numeric', title: t('TemplateDesigner.palette.pagenumber'),
            build: () => block('pagenumber', 150, 262, 40, 5, 'all', {
                text: t('TemplateDesigner.palette.pageOf'), fontSize: 7, align: 'right', color: '#777777'
            })
        },
        {
            key: 'line', icon: 'mdi-minus', title: t('TemplateDesigner.palette.line'),
            build: () => block('line', 20, 266, 170, 1, 'all', { thickness: 0.4, color: '' })
        },
        {
            key: 'rect', icon: 'mdi-rectangle-outline', title: t('TemplateDesigner.palette.rect'),
            build: () => block('rect', 20, 10, 170, 30, 'all', { fill: '#F2F5F9', borderColor: '', borderWidth: 0.4 })
        },
        {
            key: 'qrcode', icon: 'mdi-qrcode', title: t('TemplateDesigner.palette.qrcode'),
            build: () => block('qrcode', 168, 90, 22, 22, 'first', {})
        },
    ];
}

function block(type, x, y, w, h, pages, props) {
    return { id: uid(), type, x, y, w, h, pages, props };
}

/** Beschriftete Fußzeile aus den Firmendaten — ein `·` trennt die Angaben */
function footerText(s) {
    const line1 = [s.company ? `**${s.company}**` : '', s.street, s.cityLine, s.phone ? `Tel. ${s.phone}` : '', s.email].filter(Boolean).join(' · ');
    const line2 = [s.ceo, s.register, s.taxnumber ? `St.-Nr. ${s.taxnumber}` : '', s.ustid ? `USt-IdNr. ${s.ustid}` : ''].filter(Boolean).join(' · ');
    const line3 = [s.bank, s.iban ? `IBAN ${s.iban}` : '', s.bic ? `BIC ${s.bic}` : ''].filter(Boolean).join(' · ');
    return [line1, line2, line3].filter(Boolean).join('\n');
}

/** Belegnummernfeld je Belegart (für Betreff und laufende Kopfzeile) */
export function numberField(docType) {
    if (['invoice', 'invoice_storno', 'credit_note', 'proforma'].includes(docType)) return 'invnumber';
    if (['quotation', 'request_quotation', 'purchase_quotation_intake'].includes(docType)) return 'quonumber';
    if (['delivery_order', 'purchase_delivery_order'].includes(docType)) return 'donumber';
    return 'ordnumber';
}

/** Datumsfeld je Belegart */
export function dateField(docType) {
    if (['invoice', 'invoice_storno', 'credit_note', 'proforma'].includes(docType)) return 'invdate';
    if (['quotation', 'request_quotation', 'purchase_quotation_intake'].includes(docType)) return 'quodate';
    if (['delivery_order', 'purchase_delivery_order'].includes(docType)) return 'dodate';
    return 'orddate';
}

/** Verkaufsseite: Kunde; Einkaufsseite: Lieferant */
export function isPurchase(docType) {
    return ['purchase_order', 'request_quotation', 'purchase_quotation_intake', 'purchase_delivery_order'].includes(docType);
}

/** Belege ohne Beträge (Lieferscheine) */
export function hasAmounts(docType) {
    return !['delivery_order', 'purchase_delivery_order'].includes(docType);
}

/** Übersetzte Bezeichnung einer Belegart */
export function docLabel(t, docType) {
    const key = `TemplateDesigner.docTypes.${docType}`;
    const label = t(key);
    return label === key ? docType : label;
}

/** Zeilen der Infobox je Belegart */
export function infoRows(t, docType) {
    const L = (k) => t(`TemplateDesigner.labels.${k}`);
    const rows = [
        { label: `${docLabel(t, docType)}-${L('number')}`, value: `<%${numberField(docType)}%>` },
        { label: L('date'), value: `<%${dateField(docType)}%>` },
        { label: isPurchase(docType) ? L('vendornumber') : L('customernumber'), value: '<%customernumber%>' },
    ];
    if (['invoice', 'invoice_storno', 'credit_note', 'proforma'].includes(docType)) {
        rows.push({ label: L('ordnumber'), value: '<%ordnumber%>', hideEmpty: true });
        rows.push({ label: L('deliverydate'), value: '<%deliverydate%>', hideEmpty: true });
        rows.push({ label: L('duedate'), value: '<%duedate%>', hideEmpty: true });
    } else if (['order', 'sales_order_intake', 'purchase_order'].includes(docType)) {
        rows.push({ label: L('quonumber'), value: '<%quonumber%>', hideEmpty: true });
        rows.push({ label: L('cusordnumber'), value: '<%cusordnumber%>', hideEmpty: true });
        rows.push({ label: L('reqdate'), value: '<%reqdate%>', hideEmpty: true });
    } else if (['quotation', 'request_quotation', 'purchase_quotation_intake'].includes(docType)) {
        rows.push({ label: L('validUntil'), value: '<%reqdate%>', hideEmpty: true });
    } else {
        rows.push({ label: L('ordnumber'), value: '<%ordnumber%>', hideEmpty: true });
        rows.push({ label: L('reqdate'), value: '<%reqdate%>', hideEmpty: true });
    }
    rows.push({ label: L('contact'), value: '<%employee_name%>' });
    return rows;
}

/** Standardspalten der Positionstabelle */
export function tableColumns(t, docType) {
    const L = (k) => t(`TemplateDesigner.labels.${k}`);
    const cols = [
        { key: 'runningnumber', label: L('pos'), width: 10, align: 'right', visible: true },
        { key: 'number', label: L('partnumber'), width: 24, align: 'left', visible: true },
        { key: 'description', label: L('description'), width: null, align: 'left', visible: true },
        { key: 'qty', label: L('qty'), width: 20, align: 'right', visible: true },
        { key: 'sellprice', label: L('sellprice'), width: 24, align: 'right', visible: hasAmounts(docType) },
        { key: 'p_discount', label: L('discount'), width: 14, align: 'right', visible: hasAmounts(docType) },
        { key: 'linetotal', label: L('linetotal'), width: 26, align: 'right', visible: hasAmounts(docType) },
    ];
    return cols;
}

/** Standardzeilen des Summenblocks */
export function totalsRows(t) {
    const L = (k) => t(`TemplateDesigner.labels.${k}`);
    return [
        { label: L('subtotal'), value: '<%subtotal%>' },
        { type: 'tax', label: `${L('plus')} <%taxrate%> % <%taxdescription%>` },
        { label: L('total'), value: '<%invtotal%>', bold: true, ruleAbove: true },
    ];
}

/** Neue Abschnitte des Fließbereichs für die Palette */
export function sectionTemplates(t, docType) {
    return [
        { type: 'subject', icon: 'mdi-format-title', title: t('TemplateDesigner.sections.subject'),
          build: () => ({ id: uid('s'), type: 'subject', text: `${docLabel(t, docType)} ${t('TemplateDesigner.labels.nr')} <%${numberField(docType)}%>`, fontSize: 12, bold: true, spaceAfter: 4 }) },
        { type: 'text', icon: 'mdi-text-long', title: t('TemplateDesigner.sections.text'),
          build: () => ({ id: uid('s'), type: 'text', text: t('TemplateDesigner.palette.textPlaceholder'), spaceAfter: 3, condition: '' }) },
        { type: 'table', icon: 'mdi-table', title: t('TemplateDesigner.sections.table'),
          build: () => ({ id: uid('s'), type: 'table', fontSize: 9, headerFill: '#E8EEF5', headerBold: true, zebra: false, zebraFill: '#F6F8FB',
                          rules: 'header', longdescription: true, descriptionBold: true, serialnumber: false,
                          continuedText: t('TemplateDesigner.labels.continued'), columns: tableColumns(t, docType), spaceAfter: 3 }) },
        { type: 'totals', icon: 'mdi-sigma', title: t('TemplateDesigner.sections.totals'),
          build: () => ({ id: uid('s'), type: 'totals', width: 80, rows: totalsRows(t), spaceAfter: 4 }) },
        { type: 'signature', icon: 'mdi-draw-pen', title: t('TemplateDesigner.sections.signature'),
          build: () => ({ id: uid('s'), type: 'signature', left: t('TemplateDesigner.labels.placeDate'), right: t('TemplateDesigner.labels.signature'), width: 60, spaceAfter: 2 }) },
        { type: 'spacer', icon: 'mdi-arrow-expand-vertical', title: t('TemplateDesigner.sections.spacer'),
          build: () => ({ id: uid('s'), type: 'spacer', height: 8 }) },
    ];
}

/** Standardwerte der Seite */
export function defaultPage() {
    return {
        marginLeft: 20, marginRight: 20,
        bodyTop: 100, bodyTopFollowing: 35, bodyBottom: 40,
        fontSize: 10, font: 'sans',
        textColor: '#222222', accentColor: '#1F4E79',
        foldMarks: 'B', punchMark: true,
        background: '', backgroundPages: 'all',
        currency: 'euro',
    };
}

/**
 * Startvorlagen: fertige Designs je Belegart.
 *
 * @param {Function} t - Übersetzer
 * @param {Object} s - Firmenvorschläge
 * @returns {Array} [{key, title, description, build(docType, logoPath)}]
 */
export function presets(t, s) {
    const intro = (docType) => t(`TemplateDesigner.intro.${docType}`, t('TemplateDesigner.intro.default'));
    const bodySections = (docType) => {
        const sections = [
            { id: uid('s'), type: 'subject', text: `${docLabel(t, docType)} ${t('TemplateDesigner.labels.nr')} <%${numberField(docType)}%>`, fontSize: 12, bold: true, spaceAfter: 4 },
            { id: uid('s'), type: 'text', text: `${t('TemplateDesigner.labels.salutation')}\n\n${intro(docType)}`, spaceAfter: 3, condition: '' },
            { id: uid('s'), type: 'text', text: '<%notes%>', spaceAfter: 3, condition: 'notes' },
            { id: uid('s'), type: 'table', fontSize: 9, headerFill: '#E8EEF5', headerBold: true, zebra: false, zebraFill: '#F6F8FB',
              rules: 'header', longdescription: true, descriptionBold: true, serialnumber: false,
              continuedText: t('TemplateDesigner.labels.continued'), columns: tableColumns(t, docType), spaceAfter: 3 },
        ];
        if (hasAmounts(docType)) {
            sections.push({ id: uid('s'), type: 'totals', width: 80, rows: totalsRows(t), spaceAfter: 4 });
        }
        sections.push({ id: uid('s'), type: 'text', text: `${t('TemplateDesigner.labels.paymentTerms')}: <%payment_terms%>`, condition: 'payment_terms', spaceAfter: 2 });
        sections.push({ id: uid('s'), type: 'text', text: `${t('TemplateDesigner.labels.closing')}\n\n<%employee_name%>`, spaceAfter: 0 });
        if (['quotation', 'delivery_order', 'purchase_delivery_order'].includes(docType)) {
            sections.push({ id: uid('s'), type: 'signature', left: t('TemplateDesigner.labels.placeDate'), right: t('TemplateDesigner.labels.signature'), width: 60, spaceAfter: 2 });
        }
        return sections;
    };
    const items = paletteItems(t, s);
    const pick = (key, docType) => items.find(i => i.key === key).build(docType);

    return [
        {
            key: 'din5008', icon: 'mdi-file-document-outline',
            title: t('TemplateDesigner.presets.din5008'), description: t('TemplateDesigner.presets.din5008Desc'),
            build: (docType, logoPath) => {
                const logo = pick('logo', docType);
                logo.props.image = logoPath || '';
                return {
                    v: 1,
                    page: defaultPage(),
                    blocks: [logo, pick('sender', docType), pick('address', docType), pick('infobox', docType),
                             pick('runhead', docType), pick('line', docType), pick('footer', docType), pick('pagenumber', docType)],
                    body: { sections: bodySections(docType) },
                };
            }
        },
        {
            key: 'modern', icon: 'mdi-palette-outline',
            title: t('TemplateDesigner.presets.modern'), description: t('TemplateDesigner.presets.modernDesc'),
            build: (docType, logoPath) => {
                const page = { ...defaultPage(), accentColor: '#0D47A1', bodyTop: 105 };
                const band = block('rect', 0, 0, 210, 38, 'all', { fill: '#0D47A1', borderColor: '', borderWidth: 0 });
                const logo = block('image', 20, 8, 60, 22, 'all', { image: logoPath || '', align: 'left' });
                const company = block('text', 120, 10, 70, 22, 'all', {
                    text: [s.company ? `**${s.company}**` : '', s.street, s.cityLine, s.phone ? `Tel. ${s.phone}` : '', s.email].filter(Boolean).join('\n'),
                    fontSize: 8, align: 'right', color: '#FFFFFF'
                });
                const info = pick('infobox', docType);
                info.props.labelBold = true;
                const footer = pick('footer', docType);
                footer.props.color = '#0D47A1';
                return {
                    v: 1, page,
                    blocks: [band, logo, company, pick('sender', docType), pick('address', docType), info,
                             pick('runhead', docType), pick('line', docType), footer, pick('pagenumber', docType)],
                    body: { sections: bodySections(docType) },
                };
            }
        },
        {
            key: 'compact', icon: 'mdi-view-compact-outline',
            title: t('TemplateDesigner.presets.compact'), description: t('TemplateDesigner.presets.compactDesc'),
            build: (docType, logoPath) => {
                const page = { ...defaultPage(), bodyTop: 85, bodyTopFollowing: 30, bodyBottom: 30, fontSize: 9, foldMarks: '' };
                const logo = block('image', 140, 10, 50, 18, 'all', { image: logoPath || '', align: 'right' });
                const sender = pick('sender', docType); sender.y = 40;
                const address = pick('address', docType); address.y = 46; address.h = 32;
                const info = pick('infobox', docType); info.y = 44; info.h = 34; info.props.fontSize = 8;
                const footer = pick('footer', docType); footer.y = 275; footer.h = 16; footer.props.fontSize = 6.5;
                const line = pick('line', docType); line.y = 273;
                return {
                    v: 1, page,
                    blocks: [logo, sender, address, info, pick('runhead', docType), line, footer, pick('pagenumber', docType)],
                    body: { sections: bodySections(docType) },
                };
            }
        },
        {
            key: 'blank', icon: 'mdi-file-outline',
            title: t('TemplateDesigner.presets.blank'), description: t('TemplateDesigner.presets.blankDesc'),
            build: (docType) => ({
                v: 1, page: defaultPage(), blocks: [],
                body: { sections: [
                    { id: uid('s'), type: 'table', fontSize: 9, headerFill: '', headerBold: true, zebra: false, zebraFill: '#F6F8FB',
                      rules: 'header', longdescription: true, descriptionBold: false, serialnumber: false,
                      continuedText: t('TemplateDesigner.labels.continued'), columns: tableColumns(t, docType), spaceAfter: 3 },
                ] },
            })
        },
    ];
}

/**
 * Firmendaten aus dem Store als Vorschläge für Texte.
 * Liest defaults (kivitendo), defaults_oserp und bank_accounts — alles liegt
 * bereits in company_config, es wird nichts nachgeladen.
 *
 * @param {Object} companyConfig - store.session.company_config
 * @returns {Object} {company, street, cityLine, zipcode, city, country, taxnumber, ustid, email, phone, bank, iban, bic, accountHolder, logoDataUrl, ...}
 */
export function companySuggestions(companyConfig) {
    const d = companyConfig?.defaults || {};
    const o = companyConfig?.defaults_oserp || {};
    const bank = (companyConfig?.bank_accounts || [])[0] || {};
    const street = d.address_street1 || (d.address ? String(d.address).split('\n')[0] : '') || '';
    const zipcode = d.address_zipcode || '';
    const city = d.address_city || '';
    return {
        company: d.company || '',
        street,
        street2: d.address_street2 || '',
        zipcode,
        city,
        cityLine: [zipcode, city].filter(Boolean).join(' '),
        country: d.address_country || '',
        taxnumber: d.taxnumber || '',
        ustid: d.co_ustid || '',
        email: o.email_address || o.company_email || '',
        phone: o.company_phone || '',
        ceo: o.company_ceo || '',
        register: o.company_register || '',
        bank: bank.bank || '',
        iban: bank.iban || '',
        bic: bank.bic || '',
        accountHolder: bank.name || '',
        logoDataUrl: typeof o.company_logo === 'string' && o.company_logo.startsWith('data:') ? o.company_logo : '',
    };
}

/** Vorschlagschips für den Texteditor: [{key, label, value}] ohne leere Werte */
export function suggestionChips(t, s) {
    const defs = [
        ['company', s.company], ['street', s.street], ['cityLine', s.cityLine], ['country', s.country],
        ['taxnumber', s.taxnumber], ['ustid', s.ustid], ['email', s.email], ['phone', s.phone],
        ['ceo', s.ceo], ['register', s.register], ['bank', s.bank], ['iban', s.iban], ['bic', s.bic], ['accountHolder', s.accountHolder],
    ];
    return defs.filter(([, v]) => v).map(([key, value]) => ({ key, value, label: t(`TemplateDesigner.suggestions.${key}`) }));
}

/** Zuordnung der Druckfelder zu Gruppen (nur Anzeige — die Feldliste selbst kommt vom Backend) */
const FIELD_GROUPS = {
    document: ['invnumber', 'ordnumber', 'quonumber', 'donumber', 'invdate', 'orddate', 'quodate', 'dodate', 'transdate',
               'reqdate', 'duedate', 'deliverydate', 'cusordnumber', 'transaction_description', 'payment_terms', 'notes', 'invnumber_for_credit_note'],
    customer: ['name', 'customernumber', 'department_1', 'department_2', 'street', 'zipcode', 'city', 'country', 'ustid',
               'greeting', 'customer_phone', 'customer_fax', 'natural_person'],
    contact: ['cp_givenname', 'cp_name', 'cp_title', 'cp_gender'],
    shipto: ['shiptoname', 'shiptodepartment_1', 'shiptodepartment_2', 'shiptocontact', 'shiptostreet', 'shiptozipcode', 'shiptocity', 'shiptocountry'],
    employee: ['employee_name', 'employee_tel', 'employee_email', 'employee_company'],
    amounts: ['subtotal', 'invtotal', 'ordtotal'],
    positions: ['runningnumber', 'number', 'description', 'longdescription', 'serialnumber', 'qty', 'unit', 'sellprice', 'p_discount', 'linetotal'],
    taxes: ['taxdescription', 'tax', 'taxrate'],
};

/** Felder, die intern sind oder für Texte nichts hergeben */
const HIDDEN_FIELDS = new Set(['language_code', 'currency', 'media', 'formname', 'storno', 'is_storno', 'is_credit_note', 'is_invoice',
    'is_kfz', 'has_positions', 'has_instructions', 'has_maengel', 'hu_bestanden', 'hu_nicht_bestanden', 'hu_ueberfaellig',
    'has_ersatzteile', 'has_arbeiten', 'gedruckt', 'taxzone_id', 'qr_image', 'filename', 'template_meta', 'epc_amount']);

/** Bedingungsfelder: Wahrheitswerte, die sich für "nur anzeigen wenn" eignen */
export const CONDITION_FIELDS = ['notes', 'payment_terms', 'shiptoname', 'ordnumber', 'quonumber', 'donumber', 'cusordnumber',
    'department_1', 'cp_name', 'ustid', 'deliverydate', 'duedate', 'reqdate', 'is_storno', 'is_credit_note', 'is_kfz',
    'has_instructions', 'has_maengel', 'has_positions'];

/**
 * Feldkatalog aus den Beispieldaten des Backends, gruppiert und beschriftet
 *
 * @param {Function} t - Übersetzer
 * @param {Object|null} sample - {variables, arrays} aus getTemplateDesigner
 * @returns {Array} [{key, title, fields: [{key, label, sample}]}]
 */
export function fieldCatalog(t, sample) {
    const vars = sample?.variables || {};
    const arrays = sample?.arrays || {};
    const keys = new Set([...Object.keys(vars), ...Object.keys(arrays)]);
    // Ohne Beispielbeleg: die bekannten Gruppen trotzdem anbieten
    if (keys.size === 0) {
        Object.values(FIELD_GROUPS).flat().forEach(k => keys.add(k));
    }
    const assigned = new Set();
    const groups = [];
    for (const [group, list] of Object.entries(FIELD_GROUPS)) {
        const fields = list.filter(k => keys.has(k) && !HIDDEN_FIELDS.has(k)).map(k => fieldEntry(t, k, vars, arrays));
        fields.forEach(f => assigned.add(f.key));
        if (fields.length) groups.push({ key: group, title: t(`TemplateDesigner.fieldGroups.${group}`), fields });
    }
    const rest = [...keys].filter(k => !assigned.has(k) && !HIDDEN_FIELDS.has(k)).sort().map(k => fieldEntry(t, k, vars, arrays));
    if (rest.length) groups.push({ key: 'other', title: t('TemplateDesigner.fieldGroups.other'), fields: rest });
    return groups;
}

function fieldEntry(t, key, vars, arrays) {
    const labelKey = `TemplateDesigner.fields.${key}`;
    const label = t(labelKey);
    let sampleValue = vars[key];
    if (sampleValue === undefined && Array.isArray(arrays[key])) sampleValue = arrays[key][0];
    if (typeof sampleValue === 'object' && sampleValue !== null) sampleValue = '';
    return { key, label: label === labelKey ? key : label, sample: sampleValue == null ? '' : String(sampleValue) };
}

/**
 * Ersetzt Platzhalter durch Beispielwerte für die Anzeige im Canvas.
 * Unbekannte oder leere Felder bleiben als [feld] sichtbar, damit man sie
 * im Entwurf wiederfindet. HTML-Notizen werden auf Text reduziert.
 *
 * @param {string} text - Text mit <%feld%>
 * @param {Object|null} sample - Beispieldaten
 * @param {number} [index] - Zeilenindex für Positions-Arrays
 * @returns {string}
 */
export function renderSample(text, sample, index = null) {
    if (!text) return '';
    const vars = sample?.variables || {};
    const arrays = sample?.arrays || {};
    return String(text).replace(/<%\s*([A-Za-z0-9_.]+)[^%]*%>/g, (m, key) => {
        let value;
        if (index !== null && Array.isArray(arrays[key])) value = arrays[key][index];
        else if (vars[key] !== undefined) value = vars[key];
        else if (Array.isArray(arrays[key])) value = arrays[key][0];
        if (value === undefined || value === null || value === '' || typeof value === 'object') return `[${key}]`;
        return stripHtml(String(value));
    }).replace(/\{page\}/g, '1').replace(/\{pages\}/g, '1');
}

function stripHtml(value) {
    if (!/<[a-z][\s\S]*>/i.test(value)) return value;
    const div = document.createElement('div');
    div.innerHTML = value.replace(/<\/p>|<br\s*\/?>/gi, '\n');
    return div.textContent || '';
}

/**
 * Leichte Auszeichnung für den Canvas: **fett**, *kursiv* → HTML, Rest escaped
 */
export function markupToHtml(text) {
    const escaped = String(text ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return escaped
        .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
        .replace(/(^|[^\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/g, '$1<i>$2</i>')
        .replace(/\[([a-z0-9_.]+)\]/gi, '<span class="tpl-missing">[$1]</span>')
        .replace(/\n/g, '<br>');
}

/** Hex-Farbe gültig? */
export function isHex(value) {
    return /^#?[0-9a-fA-F]{6}$/.test(String(value || ''));
}

/** Zahl in Grenzen halten und runden */
export function clamp(value, min, max, step = 0.5) {
    const n = Math.round((Number(value) || 0) / step) * step;
    return Math.min(max, Math.max(min, n));
}
