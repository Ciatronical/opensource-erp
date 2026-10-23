// src/core/views/config/tabs/portal/websiteSettingsConfig.js
//
// Einstellungen einer Webseite des Kundenportals (dev/kundenportal.md). Die
// Namen sind die Schlüssel in website_oserp.settings bzw. — bei type
// "password" — website_secret_oserp, wie website_setting_keys() im Schema
// sie nennt. Das Backend nimmt nur diese an.
//
// Darstellung über shop-config-field.component.vue. Alle Texte stehen unter
// WebsitesView.fields.* (label: Beschriftung, *_help: ein Satz beim Zeigen)
// und WebsitesView.help.* (Erklärung in der Hilfsanzeige, Klick auf das
// Info-Symbol) — in Alltagssprache für Administratoren ohne
// Programmierkenntnisse. Die Kanalkarte des Shops bleibt bei crm_fields.*.

const website = [
    // Webadresse der Webseite — die wichtigste Angabe, deshalb ganz oben, noch
    // vor HugoCMS. Über sie läuft der dritte Prüfschritt (Weiterleiter
    // /portal-api/), und aus ihr entstehen die Links in Mails.
    { name: "base_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.baseUrl", tooltip: "WebsitesView.fields.baseUrl_help", help: "WebsitesView.help.baseUrl" },

    // HugoCMS: ohne Verbindung entsteht keine Webseite. action: die Karte
    // zeigt unter den Feldern „Verbindung prüfen"
    {
        name: "hugocms",
        type: "group",
        icon: "mdi-web-sync",
        label: "WebsitesView.fields.hugocms",
        tooltip: "WebsitesView.fields.hugocms_help",
        action: "hugocmsTest",
        // Jedes Feld: label (Beschriftung), tooltip (ein Satz beim Zeigen),
        // help (Erklärung in der Hilfsanzeige, Klick auf das Info-Symbol)
        fields: [
            { name: "hugocms_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.hugocmsUrl", tooltip: "WebsitesView.fields.hugocmsUrl_help", help: "WebsitesView.help.hugocmsUrl" },
            { name: "hugocms_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.hugocmsKey", tooltip: "WebsitesView.fields.hugocmsKey_help", help: "WebsitesView.help.hugocmsKey" },
        ],
    },

    { name: "zugang", type: "headline", label: "WebsitesView.fields.access" },

    // Erzeugt wird ein neuer Schlüssel im Browser; er gehört auch in den
    // Reverse-Proxy, falls einer den mitgelieferten ersetzt. Je Webseite ein
    // eigener — er bestimmt im öffentlichen Zugang Mandant und Webseite.
    { name: "public_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", generate: true, label: "WebsitesView.fields.publicKey", tooltip: "WebsitesView.fields.publicKey_help", help: "WebsitesView.help.publicKey" },
    { name: "allowed_origins", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.allowedOrigins", tooltip: "WebsitesView.fields.allowedOrigins_help", help: "WebsitesView.help.allowedOrigins" },
    // action: die Karte zeigt unter dem Feld „Verbindung prüfen“ (testWebsiteBackendUrl)
    { name: "backend_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.backendUrl", tooltip: "WebsitesView.fields.backendUrl_help", action: "backendTest", help: "WebsitesView.help.backendUrl" },

    // Seiten der Webseite, auf die Mails und Nachrichten verlinken
    { name: "seiten", type: "headline", label: "WebsitesView.fields.pages" },

    // Seite mit <shop-invoice> — Ziel des Links in den Mails; leer = /rechnung/
    { name: "invoice_page", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "WebsitesView.fields.invoicePage", tooltip: "WebsitesView.fields.invoicePage_help", help: "WebsitesView.help.invoicePage" },
    // Seite mit <portal-activate> — Ziel des Links in der WhatsApp-Nachricht; leer = /freischalten/
    { name: "activate_page", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "WebsitesView.fields.activatePage", tooltip: "WebsitesView.fields.activatePage_help", help: "WebsitesView.help.activatePage" },

    { name: "veroeffentlichung", type: "headline", label: "WebsitesView.fields.publish" },

    { name: "template_set", type: "dynamic-select", source: "templateSets", itemTitle: "title", itemValue: "name", fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.templateSet", tooltip: "WebsitesView.fields.templateSet_help", help: "WebsitesView.help.templateSet" },

    { name: "kontakt", type: "headline", label: "WebsitesView.fields.contact" },

    { name: "contact_mail_to", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "WebsitesView.fields.contactMailTo", tooltip: "WebsitesView.fields.contactMailTo_help", help: "WebsitesView.help.contactMailTo" },
]

/** Alle Felder, Gruppen aufgelöst — für Laden und Speichern */
export function websiteSettingFields() {
    return website
        .flatMap(feld => (feld.type === 'group' ? (feld.fields || []) : [feld]))
        .filter(feld => feld.type !== 'headline')
}

export default website
