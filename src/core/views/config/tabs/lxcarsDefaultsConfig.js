// opensource-erp/frontend/src/views/config/tabs/lxcarsDefaultsConfig.js

const lxcarsDefaultsConfig = [
    { name: "lxcars", type: "headline", label: "crm_fields.lxcars" },

    { name: "lxcarsapi", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsApi", tooltip: "crm_fields.lxcarsApi_help" },
    { name: "openai_api_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.openaiApiKey", tooltip: "crm_fields.openaiApiKey_help" },
    { name: "anthropic_api_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.anthropicApiKey", tooltip: "crm_fields.anthropicApiKey_help" },
    { name: "ai_gewicht_rechnungen", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.aiGewichtRechnungen", tooltip: "crm_fields.aiGewichtRechnungen_help" },
    { name: "ai_gewicht_auftraege", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.aiGewichtAuftraege", tooltip: "crm_fields.aiGewichtAuftraege_help" },
    { name: "ai_gewicht_angebote", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.aiGewichtAngebote", tooltip: "crm_fields.aiGewichtAngebote_help" },
    { name: "lxcars_chat_system_prompt", type: "textarea", rows: 6, fieldstyle: "max-width: 80ch", label: "crm_fields.lxcarsChatSystemPrompt", tooltip: "crm_fields.lxcarsChatSystemPrompt_help" },
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

    { name: "lxcars_mechanic_mode", type: "headline", label: "crm_fields.lxcarsMechanicMode" },
    { name: "lxcars_mechanic_mode", type: "checkbox", label: "crm_fields.lxcarsMechanicModeEnabled", tooltip: "crm_fields.lxcarsMechanicModeEnabled_help" },
    { name: "lxcars_employee_group", type: "dynamic-select", source: "auth_groups", itemTitle: "name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.lxcarsEmployeeGroup", tooltip: "crm_fields.lxcarsEmployeeGroup_help" },
];

export default lxcarsDefaultsConfig;
