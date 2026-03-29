// opensource-erp/frontend/src/views/config/tabs/crmDefaultsConfig.js

const crmDefaultsConfig = [
    { name: "debug", type: "headline", label: "crm_fields.debug" },

    { name: "debug", type: "checkbox", label: "crm_fields.debug", tooltip: "Debugausgaben in der Konsole und auf dem Bildschirm" },

    { name: "startup-view", type: "headline", label: "crm_fields.start-view" },

    {
        name: "startup-view",
        type: "select",
        items: [
            { value: "customer-vendor", title: "CRM-Ansicht" },
            { value: "main-menu", title: "Hauptmenü" },
        ],
        label: "crm_fields.select-view",
        tooltip: "crm_fields.select-view_help",
        fieldstyle: "max-width: 60ch"
    },

    { name: "features", type: "headline", label: "crm_fields.features" },

    {
        name: "features",
        type: "select",
        items: [
            { value: "lxcars", title: "LxCars" },
            { value: "flatcosts", title: "Flatcosts" },
            { value: "newfeature", title: "NewFeature" }
        ],
        label: "crm_fields.features",
        tooltip: "crm_fields.features_help",
        fieldstyle: "max-width: 60ch"
    },

    { name: "brevo", type: "headline", label: "crm_fields.brevo" },

    { name: "brevo_api_endpoint", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.brevoApiEndpoint", tooltip: "crm_fields.brevoApiEndpoint_help" },
    { name: "brevo_api_key", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.brevoApiKey", tooltip: "crm_fields.brevoApiKey_help" },
    { name: "brevo_api_test_enabled", type: "checkbox", label: "crm_fields.brevoApiTestEnabled", tooltip: "crm_fields.brevoApiTestEnabled_help" },
    { name: "brevo_api_test_recipient", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.brevoApiTestRecipient", tooltip: "crm_fields.brevoApiTestRecipient_help" },

    { name: "misc", type: "headline", label: "crm_fields.misc" },

    { name: "list_limit", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 60ch", label: "crm_fields.listLimit", tooltip: "crm_fields.listLimit_help" },

    { name: "phoneintegration", type: "headline", label: "crm_fields.phoneIntegration" },

    { name: "external_contexts", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.externalContexts", tooltip: "crm_fields.externalContexts_help" },
    { name: "internal_phones", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.internalPhones", tooltip: "crm_fields.internalPhones_help" },
    { name: "crmti_mobile_number", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.mobileNumber", tooltip: "crm_fields.mobileNumber_help" },
    { name: "ip_asterisk", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.asteriskIp", tooltip: "crm_fields.asteriskIp_help" },
    { name: "asterisk_passwd", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.asteriskPassword", tooltip: "crm_fields.asteriskPassword_help" },

    { name: "whatsapp", type: "headline", label: "crm_fields.whatsapp" },

    { name: "whatsapp_country_code", type: "input", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.whatsappCountryCode", tooltip: "crm_fields.whatsappCountryCode_help" },
    { name: "whatsapp_default_message", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappDefaultMessage", tooltip: "crm_fields.whatsappDefaultMessage_help" },

    { name: "whatsapp_business_api", type: "headline", label: "crm_fields.whatsappBusinessApi" },

    { name: "whatsapp_access_token", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappAccessToken", tooltip: "crm_fields.whatsappAccessToken_help" },
    { name: "whatsapp_phone_number_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.whatsappPhoneNumberId", tooltip: "crm_fields.whatsappPhoneNumberId_help" },
    { name: "whatsapp_business_account_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.whatsappBusinessAccountId", tooltip: "crm_fields.whatsappBusinessAccountId_help" },
    { name: "whatsapp_verify_token", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.whatsappVerifyToken", tooltip: "crm_fields.whatsappVerifyToken_help" },

    { name: "whatsapp_templates", type: "headline", label: "crm_fields.whatsappTemplates" },
    { name: "whatsapp_templates_manage", type: "component", component: "whatsapp-templates" },

    { name: "whatsapp_template_assignments", type: "headline", label: "crm_fields.whatsappTplAssignments" },
    { name: "whatsapp_tpl_chat", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplChat", tooltip: "crm_fields.whatsappTplChat_help" },
    { name: "whatsapp_tpl_chat_document", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplChatDocument", tooltip: "crm_fields.whatsappTplChatDocument_help" },
    { name: "whatsapp_tpl_chat_image", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplChatImage", tooltip: "crm_fields.whatsappTplChatImage_help" },
    { name: "whatsapp_tpl_faktura", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplFaktura", tooltip: "crm_fields.whatsappTplFaktura_help" },
    { name: "whatsapp_tpl_hu", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplHu", tooltip: "crm_fields.whatsappTplHu_help" },
    { name: "whatsapp_tpl_reminder", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplReminder", tooltip: "crm_fields.whatsappTplReminder_help" },
    { name: "whatsapp_tpl_appointment_confirm", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplAppointmentConfirm", tooltip: "crm_fields.whatsappTplAppointmentConfirm_help" },
    { name: "whatsapp_tpl_address", type: "dynamic-select", source: "whatsapp_templates", itemTitle: "display_name", itemValue: "id", fieldstyle: "max-width: 60ch", label: "crm_fields.whatsappTplAddress", tooltip: "crm_fields.whatsappTplAddress_help" },

    { name: "whatsapp_reminders", type: "headline", label: "crm_fields.whatsappReminders" },
    { name: "whatsapp_reminder_enabled", type: "checkbox", label: "crm_fields.whatsappReminderEnabled", tooltip: "crm_fields.whatsappReminderEnabled_help" },
    { name: "whatsapp_reminder_hours", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.whatsappReminderHours", tooltip: "crm_fields.whatsappReminderHours_help" },

    { name: "payment", type: "headline", label: "crm_fields.payment" },

    { name: "ec_terminal_ip_address", type: "input", size: 20, fieldstyle: "max-width: 60ch", label: "crm_fields.ecTerminalIp", tooltip: "crm_fields.ecTerminalIp_help" },
    { name: "ec_terminal_port", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 60ch", label: "crm_fields.ecTerminalPort", tooltip: "crm_fields.ecTerminalPort_help" },
    { name: "ec_terminal_passwd", type: "password", size: 20, fieldstyle: "max-width: 60ch", label: "crm_fields.ecTerminalPassword", tooltip: "crm_fields.ecTerminalPassword_help" },

    { name: "eletter", type: "headline", label: "crm_fields.eletter" },

    { name: "eletter_hostname", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.eletterHostname", tooltip: "crm_fields.eletterHostname_help" },
    { name: "eletter_username", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.eletterUsername", tooltip: "crm_fields.eletterUsername_help" },
    { name: "eletter_folder", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.eletterFolder", tooltip: "crm_fields.eletterFolder_help" },
    { name: "eletter_passwd", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.eletterPassword", tooltip: "crm_fields.eletterPassword_help" },

    { name: "email_client", type: "headline", label: "crm_fields.emailClient" },

    { name: "email_credentials", type: "headline", label: "crm_fields.emailCredentials" },

    { name: "email_address", type: "input", inputType: "email", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.emailAddress", tooltip: "crm_fields.emailAddress_help" },
    { name: "email_username", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.emailUsername", tooltip: "crm_fields.emailUsername_help" },
    { name: "email_password", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.emailPassword", tooltip: "crm_fields.emailPassword_help" },

    { name: "email_imap", type: "headline", label: "crm_fields.emailImap" },

    { name: "email_imap_host", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.emailImapHost", tooltip: "crm_fields.emailImapHost_help" },
    { name: "email_imap_port", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.emailImapPort", tooltip: "crm_fields.emailImapPort_help" },
    {
        name: "email_imap_encryption",
        type: "select",
        items: [
            { value: "ssl", title: "SSL/TLS (Port 993)" },
            { value: "starttls", title: "STARTTLS (Port 143)" },
            { value: "none", title: "Keine" }
        ],
        label: "crm_fields.emailImapEncryption",
        tooltip: "crm_fields.emailImapEncryption_help",
        fieldstyle: "max-width: 20ch"
    },

    { name: "email_smtp", type: "headline", label: "crm_fields.emailSmtp" },

    { name: "email_smtp_host", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.emailSmtpHost", tooltip: "crm_fields.emailSmtpHost_help" },
    { name: "email_smtp_port", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.emailSmtpPort", tooltip: "crm_fields.emailSmtpPort_help" },
    {
        name: "email_smtp_encryption",
        type: "select",
        items: [
            { value: "ssl", title: "SSL/TLS (Port 465)" },
            { value: "starttls", title: "STARTTLS (Port 587)" },
            { value: "none", title: "Keine" }
        ],
        label: "crm_fields.emailSmtpEncryption",
        tooltip: "crm_fields.emailSmtpEncryption_help",
        fieldstyle: "max-width: 20ch"
    },

    { name: "filemanager", type: "headline", label: "crm_fields.filemanager" },

    {
        name: "fm_default_view",
        type: "select",
        items: [
            { value: "list", title: "Liste" },
            { value: "grid", title: "Raster" },
        ],
        label: "crm_fields.fmDefaultView",
        tooltip: "crm_fields.fmDefaultView_help",
        fieldstyle: "max-width: 60ch"
    },
    { name: "fm_max_upload_size", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 30ch", label: "crm_fields.fmMaxUploadSize", tooltip: "crm_fields.fmMaxUploadSize_help" },
    { name: "fm_allowed_extensions", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.fmAllowedExtensions", tooltip: "crm_fields.fmAllowedExtensions_help" },
    { name: "dir_group", type: "input", size: 20, fieldstyle: "max-width: 60ch", label: "crm_fields.dirGroup", tooltip: "crm_fields.dirGroup_help" },
    { name: "dir_mode", type: "input", size: 20, fieldstyle: "max-width: 60ch", label: "crm_fields.dirMode", tooltip: "crm_fields.dirMode_help" },

    { name: "infobar", type: "headline", label: "crm_fields.infoBar" },

    { name: "infobar_max_calls", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.infoBarMaxCalls", tooltip: "crm_fields.infoBarMaxCalls_help" },
    { name: "infobar_max_emails", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.infoBarMaxEmails", tooltip: "crm_fields.infoBarMaxEmails_help" },
    { name: "infobar_max_whatsapps", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.infoBarMaxWhatsapps", tooltip: "crm_fields.infoBarMaxWhatsapps_help" },

    { name: "aag_online", type: "headline", label: "crm_fields.aagOnline" },

    { name: "aag_online_user", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.aagOnlineUser", tooltip: "crm_fields.aagOnlineUser_help" },
    { name: "aag_online_passwd", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.aagOnlinePassword", tooltip: "crm_fields.aagOnlinePassword_help" },
    { name: "aag_online_passwd2", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.aagOnlinePassword2", tooltip: "crm_fields.aagOnlinePassword2_help" },

    { name: "wall_display", type: "headline", label: "crm_fields.wallDisplay" },

    { name: "wall_display_enabled", type: "checkbox", label: "crm_fields.wallDisplayEnabled", tooltip: "crm_fields.wallDisplayEnabled_help" }
];

export default crmDefaultsConfig;
