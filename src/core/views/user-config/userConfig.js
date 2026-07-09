// src/core/views/user-config/userConfig.js

const userConfig = [
    { name: "darstellung", type: "headline", label: "user_fields.appearance" },

    {
        name: "dark_mode",
        type: "checkbox",
        label: "user_fields.darkMode",
        tooltip: "user_fields.darkMode_help"
    },

    {
        name: "locale",
        type: "select",
        items: [
            { value: "de", title: "Deutsch" },
            { value: "en", title: "English" },
            { value: "pl", title: "Polski" },
            { value: "uk", title: "Українська" },
            { value: "ru", title: "Русский" },
            { value: "fr", title: "Français" },
            { value: "nl", title: "Nederlands" },
            { value: "da", title: "Dansk" },
            { value: "nb", title: "Norsk" },
            { value: "sv", title: "Svenska" },
            { value: "et", title: "Eesti" },
            { value: "lv", title: "Latviešu" },
            { value: "lt", title: "Lietuvių" },
            { value: "es", title: "Español" },
            { value: "it", title: "Italiano" },
            { value: "pt", title: "Português" },
            { value: "cs", title: "Čeština" },
            { value: "ro", title: "Română" },
            { value: "tr", title: "Türkçe" },
            { value: "fi", title: "Suomi" },
            { value: "zh", title: "中文" },
        ],
        label: "user_fields.locale",
        tooltip: "user_fields.locale_help",
        fieldstyle: "max-width: 30ch"
    },

    { name: "allgemein", type: "headline", label: "user_fields.general" },

    {
        name: "startup-view",
        type: "select",
        items: [
            { value: "customer-vendor", title: "CRM-Ansicht" },
            { value: "main-menu", title: "Hauptmenü" },
            { value: "wall-display", title: "Wandanzeige" },
            { value: "anschlagtafel", title: "Tafel" },
            { value: "mechanic", title: "Mechaniker-Modus" },
        ],
        label: "user_fields.startupView",
        tooltip: "user_fields.startupView_help",
        fieldstyle: "max-width: 40ch"
    },

    { name: "faktura", type: "headline", label: "user_fields.faktura" },

    {
        name: "faktura_compact_view",
        type: "checkbox",
        label: "user_fields.fakturaCompactView",
        tooltip: "user_fields.fakturaCompactView_help"
    },

    { name: "drucker", type: "headline", label: "user_fields.printer" },

    {
        name: "default_printer_id",
        type: "select",
        storeItems: "printers",
        itemValue: "id",
        itemTitle: "printer_description",
        clearable: true,
        label: "user_fields.defaultPrinter",
        tooltip: "user_fields.defaultPrinter_help",
        fieldstyle: "max-width: 40ch"
    },
];

export default userConfig;
