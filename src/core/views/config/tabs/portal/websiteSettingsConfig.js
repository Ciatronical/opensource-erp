// src/core/views/config/tabs/portal/websiteSettingsConfig.js
//
// Einstellungen einer Webseite des Kundenportals (dev/kundenportal.md). Die
// Namen sind die Schlüssel in website_oserp.settings bzw. — bei type
// "password" — website_secret_oserp, wie website_setting_keys() im Schema
// sie nennt. Das Backend nimmt nur diese an.
//
// Darstellung über shop-config-field.component.vue. Beschriftungen und
// Hilfen mit Portal-Wortlaut stehen unter WebsitesView.fields.*; neutrale
// Beschriftungen (etwa „Adresse von HugoCMS“) teilen sich die Webseiten mit
// der Kanalkarte des Shops unter crm_fields.*.

const website = [
    // HugoCMS zuerst: ohne Verbindung entsteht keine Webseite. action: die
    // Karte zeigt unter den Feldern „Verbindung prüfen"
    {
        name: "hugocms",
        type: "group",
        icon: "mdi-web-sync",
        label: "crm_fields.shopHugoCms",
        tooltip: "WebsitesView.fields.hugocms_help",
        action: "hugocmsTest",
        fields: [
            { name: "hugocms_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopHugoCmsUrl", tooltip: "WebsitesView.fields.hugocmsUrl_help" },
            { name: "hugocms_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopHugoCmsKey", tooltip: "crm_fields.shopHugoCmsKey_help" },
        ],
    },

    { name: "zugang", type: "headline", label: "WebsitesView.fields.access" },

    // Die Adresse der Webseite zuerst: über sie läuft der dritte Prüfschritt
    // (Weiterleiter /portal-api/), und auf sie beziehen sich die Herkünfte
    { name: "base_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.baseUrl", tooltip: "WebsitesView.fields.baseUrl_help" },
    // Erzeugt wird ein neuer Schlüssel im Browser; er gehört auch in den
    // Reverse-Proxy, falls einer den mitgelieferten ersetzt. Je Webseite ein
    // eigener — er bestimmt im öffentlichen Zugang Mandant und Webseite.
    { name: "public_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", generate: true, label: "WebsitesView.fields.publicKey", tooltip: "WebsitesView.fields.publicKey_help" },
    { name: "allowed_origins", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopAllowedOrigins", tooltip: "WebsitesView.fields.allowedOrigins_help" },
    // action: die Karte zeigt unter dem Feld „Verbindung prüfen“ (testWebsiteBackendUrl)
    { name: "backend_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.backendUrl", tooltip: "WebsitesView.fields.backendUrl_help", action: "backendTest" },

    // Seiten der Webseite, auf die Mails und Nachrichten verlinken
    { name: "seiten", type: "headline", label: "WebsitesView.fields.pages" },

    // Seite mit <shop-invoice> — Ziel des Links in den Mails; leer = /rechnung/
    { name: "invoice_page", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopInvoicePage", tooltip: "WebsitesView.fields.invoicePage_help" },
    // Seite mit <portal-activate> — Ziel des Links in der WhatsApp-Nachricht; leer = /freischalten/
    { name: "activate_page", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "WebsitesView.fields.activatePage", tooltip: "WebsitesView.fields.activatePage_help" },

    { name: "veroeffentlichung", type: "headline", label: "crm_fields.shopPublish" },

    { name: "template_set", type: "dynamic-select", source: "templateSets", itemTitle: "title", itemValue: "name", fieldstyle: "max-width: 60ch", label: "crm_fields.shopTemplateSet", tooltip: "WebsitesView.fields.templateSet_help" },

    { name: "kontakt", type: "headline", label: "WebsitesView.fields.contact" },

    { name: "contact_mail_to", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.contactMailTo", tooltip: "WebsitesView.fields.contactMailTo_help" },
]

/** Alle Felder, Gruppen aufgelöst — für Laden und Speichern */
export function websiteSettingFields() {
    return website
        .flatMap(feld => (feld.type === 'group' ? (feld.fields || []) : [feld]))
        .filter(feld => feld.type !== 'headline')
}

export default website
