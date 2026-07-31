// opensource-erp/frontend/src/views/config/tabs/lxcarsDefaultsConfig.js

const lxcarsDefaultsConfig = [
    { name: "lxcars", type: "headline", label: "crm_fields.lxcars" },

    { name: "lxcarsapi", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsApi", tooltip: "crm_fields.lxcarsApi_help" },

    // KI-Gewichte, lokale KI (Ollama) und OpenAI-Key liegen jetzt im eigenen
    // Tab "KI und Gesundheit" (ai-health.tab.vue). Feature-spezifische KI-Prompts
    // bleiben hier, weil sie nur mit LxCars sinnvoll sind.
    { name: "lxcars_chat_system_prompt", type: "textarea", rows: 6, fieldstyle: "max-width: 80ch", label: "crm_fields.lxcarsChatSystemPrompt", tooltip: "crm_fields.lxcarsChatSystemPrompt_help" },
    { name: "lxcars_sell_system_prompt", type: "textarea", rows: 4, fieldstyle: "max-width: 80ch", label: "crm_fields.lxcarsSellSystemPrompt", tooltip: "crm_fields.lxcarsSellSystemPrompt_help" },
    { name: "instructionprefix", type: "input", size: 10, fieldstyle: "max-width: 30ch", label: "crm_fields.instructionPrefix", tooltip: "crm_fields.instructionPrefix_help" },
    { name: "instructionnumber", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 30ch", label: "crm_fields.instructionNumber", tooltip: "crm_fields.instructionNumber_help" },
    { name: "lxcars_auto_folders", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsAutoFolders", tooltip: "crm_fields.lxcarsAutoFolders_help" },
    { name: "lxcars_order_statuses", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsOrderStatuses", tooltip: "crm_fields.lxcarsOrderStatuses_help" },
    { name: "lxcars_kfz_ort_options", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsKfzOrtOptions", tooltip: "crm_fields.lxcarsKfzOrtOptions_help" },
    { name: "lxcars_order_hide_status", type: "input", size: 30, fieldstyle: "max-width: 30ch", label: "crm_fields.lxcarsOrderHideStatus", tooltip: "crm_fields.lxcarsOrderHideStatus_help" },
    { name: "lxcars_order_future_days", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.lxcarsOrderFutureDays", tooltip: "crm_fields.lxcarsOrderFutureDays_help" },
    { name: "lxcars_hu_vorlauf_monate", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.lxcarsHuVorlaufMonate", tooltip: "crm_fields.lxcarsHuVorlaufMonate_help" },
    { name: "lxcars_hu_trigger_descriptions", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsHuTriggerDescriptions", tooltip: "crm_fields.lxcarsHuTriggerDescriptions_help" },
    { name: "lxcars_hu_brief_text", type: "textarea", rows: 12, fieldstyle: "max-width: 80ch", label: "crm_fields.lxcarsHuBriefText", tooltip: "crm_fields.lxcarsHuBriefText_help" },
    { name: "lxcars_hu_whatsapp_enabled", type: "checkbox", label: "crm_fields.lxcarsHuWhatsappEnabled", tooltip: "crm_fields.lxcarsHuWhatsappEnabled_help" },

    { name: "aag_online", type: "headline", label: "crm_fields.aagOnline" },

    { name: "aag_online_user", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.aagOnlineUser", tooltip: "crm_fields.aagOnlineUser_help" },
    { name: "aag_online_passwd", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.aagOnlinePassword", tooltip: "crm_fields.aagOnlinePassword_help" },
    { name: "aag_online_passwd2", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.aagOnlinePassword2", tooltip: "crm_fields.aagOnlinePassword2_help" },

    { name: "gutmann", type: "headline", label: "crm_fields.gutmann" },

    { name: "gutmann_megamacs_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.gutmannMegamacsUrl", tooltip: "crm_fields.gutmannMegamacsUrl_help" },

    { name: "hgs_data", type: "headline", label: "crm_fields.hgsData" },

    { name: "hgs_data_user", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.hgsDataUser", tooltip: "crm_fields.hgsDataUser_help" },
    { name: "hgs_data_passwd", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.hgsDataPassword", tooltip: "crm_fields.hgsDataPassword_help" },

    { name: "lxcars_termin", type: "headline", label: "crm_fields.lxcarsTermin" },
    { name: "lxcars_default_abgabezeit", type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.lxcarsDefaultAbgabezeit", tooltip: "crm_fields.lxcarsDefaultAbgabezeit_help" },
    { name: "lxcars_default_fertigstellungszeit", type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.lxcarsDefaultFertigstellungszeit", tooltip: "crm_fields.lxcarsDefaultFertigstellungszeit_help" },
    { name: "lxcars_time_range", type: "input", size: 15, fieldstyle: "max-width: 20ch", label: "crm_fields.lxcarsTimeRange", tooltip: "crm_fields.lxcarsTimeRange_help" },

    { name: "lxcars_label_printers", type: "headline", label: "crm_fields.lxcarsLabelPrinters" },
    { name: "lxcars_yellow_label_printer", type: "dynamic-select", source: "printers", itemTitle: "printer_description", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsYellowLabelPrinter", tooltip: "crm_fields.lxcarsYellowLabelPrinter_help" },
    { name: "lxcars_tyre_label_printer", type: "dynamic-select", source: "printers", itemTitle: "printer_description", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsTyreLabelPrinter", tooltip: "crm_fields.lxcarsTyreLabelPrinter_help" },

    { name: "lxcars_zeiterfassung", type: "headline", label: "crm_fields.lxcarsZeiterfassung" },
    { name: "lxcars_werkstattleitung_group", type: "dynamic-select", source: "auth_groups", itemTitle: "name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsWerkstattleitungGroup", tooltip: "crm_fields.lxcarsWerkstattleitungGroup_help" },
    { name: "lxcars_arbeitsbeginn", type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.lxcarsArbeitsbeginn", tooltip: "crm_fields.lxcarsArbeitsbeginn_help" },
    { name: "lxcars_arbeitsende", type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.lxcarsArbeitsende", tooltip: "crm_fields.lxcarsArbeitsende_help" },
    { name: "lxcars_pausen", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsPausen", tooltip: "crm_fields.lxcarsPausen_help" },

    { name: "lxcars_email", type: "headline", label: "crm_fields.lxcarsEmail" },
    { name: "lxcars_email_subject", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsEmailSubject", tooltip: "crm_fields.lxcarsEmailSubject_help" },
    { name: "lxcars_email_body", type: "textarea", rows: 10, fieldstyle: "max-width: 80ch", label: "crm_fields.lxcarsEmailBody", tooltip: "crm_fields.lxcarsEmailBody_help" },
    { name: "lxcars_email_attach_full", type: "checkbox", label: "crm_fields.lxcarsEmailAttachFull", tooltip: "crm_fields.lxcarsEmailAttachFull_help" },

    { name: "lxcars_mechanic_mode", type: "headline", label: "crm_fields.lxcarsMechanicMode" },
    { name: "lxcars_mechanic_mode", type: "checkbox", label: "crm_fields.lxcarsMechanicModeEnabled", tooltip: "crm_fields.lxcarsMechanicModeEnabled_help" },
    { name: "lxcars_employee_group", type: "dynamic-select", source: "auth_groups", itemTitle: "name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsEmployeeGroup", tooltip: "crm_fields.lxcarsEmployeeGroup_help" },

    { name: "lxcars_wartung", type: "headline", label: "crm_fields.lxcarsWartung" },
    { name: "lxcars_wartung_enabled", type: "checkbox", label: "crm_fields.lxcarsWartungEnabled", tooltip: "crm_fields.lxcarsWartungEnabled_help" },
];

export default lxcarsDefaultsConfig;
