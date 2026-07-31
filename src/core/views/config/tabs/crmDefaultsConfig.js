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

    // KI-Schlüssel (OpenAI, Anthropic) liegen jetzt im Tab "KI und Gesundheit"
    // (ai-health.tab.vue) — dort ist alles rund um KI gebündelt.

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

    { name: "whatsapp_profile_picture", type: "component", component: "whatsapp-profile-picture" },

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

    { name: "telegram", type: "headline", label: "crm_fields.telegram" },

    { name: "telegram_enabled", type: "checkbox", label: "crm_fields.telegramEnabled", tooltip: "crm_fields.telegramEnabled_help" },
    { name: "telegram_bot_token", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.telegramBotToken", tooltip: "crm_fields.telegramBotToken_help" },
    { name: "telegram_bot_username", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.telegramBotUsername", tooltip: "crm_fields.telegramBotUsername_help" },
    { name: "telegram_webhook_secret", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.telegramWebhookSecret", tooltip: "crm_fields.telegramWebhookSecret_help" },

    { name: "payment", type: "headline", label: "crm_fields.payment" },

    { name: "ec_terminal_ip_address", type: "input", size: 20, fieldstyle: "max-width: 60ch", label: "crm_fields.ecTerminalIp", tooltip: "crm_fields.ecTerminalIp_help" },
    { name: "ec_terminal_port", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 60ch", label: "crm_fields.ecTerminalPort", tooltip: "crm_fields.ecTerminalPort_help" },
    { name: "ec_terminal_passwd", type: "password", size: 20, fieldstyle: "max-width: 60ch", label: "crm_fields.ecTerminalPassword", tooltip: "crm_fields.ecTerminalPassword_help" },

    { name: "sumup", type: "headline", label: "crm_fields.sumup" },

    { name: "sumup_enabled", type: "checkbox", label: "crm_fields.sumupEnabled", tooltip: "crm_fields.sumupEnabled_help" },
    { name: "sumup_api_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.sumupApiKey", tooltip: "crm_fields.sumupApiKey_help" },
    { name: "sumup_merchant_code", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.sumupMerchantCode", tooltip: "crm_fields.sumupMerchantCode_help" },
    { name: "sumup_reader_pairing", type: "component", component: "sumup-reader-pairing" },

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

    { name: "calendar", type: "headline", label: "crm_fields.calendar" },

    { name: "calendar_day_start",   type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.calendarDayStart",   tooltip: "crm_fields.calendarDayStart_help" },
    { name: "calendar_day_end",     type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.calendarDayEnd",     tooltip: "crm_fields.calendarDayEnd_help" },
    { name: "calendar_break_start", type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.calendarBreakStart", tooltip: "crm_fields.calendarBreakStart_help" },
    { name: "calendar_break_end",   type: "input", inputType: "time", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.calendarBreakEnd",   tooltip: "crm_fields.calendarBreakEnd_help" },

    { name: "wall_display", type: "headline", label: "crm_fields.wallDisplay" },

    { name: "wall_display_enabled", type: "checkbox", label: "crm_fields.wallDisplayEnabled", tooltip: "crm_fields.wallDisplayEnabled_help" },
    {
        name: "wall_display_size",
        type: "select",
        items: [
            { value: "auto",    title: "Automatisch (an Auflösung anpassen)" },
            { value: "compact", title: "Kompakt (TV / Querformat)" },
            { value: "normal",  title: "Normal" },
            { value: "qm50c",   title: "QM50C (Samsung, 50″ 4K)" },
            { value: "large",   title: "Groß (Hochformat / Wandtablet)" }
        ],
        fieldstyle: "max-width: 40ch",
        label: "crm_fields.wallDisplaySize",
        tooltip: "crm_fields.wallDisplaySize_help"
    },

    {
        name: "wall_display_controllers",
        type: "input",
        size: 60,
        fieldstyle: "max-width: 60ch",
        label: "crm_fields.wallDisplayControllers",
        tooltip: "crm_fields.wallDisplayControllers_help"
    },

    { name: "dhl", type: "headline", label: "crm_fields.dhl" },

    { name: "dhl_enabled", type: "checkbox", label: "crm_fields.dhlEnabled", tooltip: "crm_fields.dhlEnabled_help" },
    { name: "dhl_sandbox", type: "checkbox", label: "crm_fields.dhlSandbox", tooltip: "crm_fields.dhlSandbox_help" },
    { name: "dhl_api_key", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.dhlApiKey", tooltip: "crm_fields.dhlApiKey_help" },
    { name: "dhl_user", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.dhlUser", tooltip: "crm_fields.dhlUser_help" },
    { name: "dhl_password", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.dhlPassword", tooltip: "crm_fields.dhlPassword_help" },
    { name: "dhl_billing_number", type: "input", size: 20, fieldstyle: "max-width: 30ch", label: "crm_fields.dhlBillingNumber", tooltip: "crm_fields.dhlBillingNumber_help" },
    {
        name: "dhl_default_product",
        type: "select",
        items: [
            { value: "V01PAK", title: "DHL Paket" },
            { value: "V53WPAK", title: "DHL Paket International" },
            { value: "V62WP", title: "DHL Warenpost" },
            { value: "V66WPI", title: "DHL Warenpost International" }
        ],
        label: "crm_fields.dhlDefaultProduct",
        tooltip: "crm_fields.dhlDefaultProduct_help",
        fieldstyle: "max-width: 40ch"
    },
    {
        name: "dhl_label_format",
        type: "select",
        items: [
            { value: "910-300-600", title: "103 x 199 mm (Labeldrucker)" },
            { value: "910-300-700", title: "A4" },
            { value: "910-300-700-oZ", title: "A4 (ohne Rand)" },
            { value: "910-300-410", title: "103 x 150 mm" },
            { value: "100x70mm", title: "100 x 70 mm (Warenpost)" }
        ],
        label: "crm_fields.dhlLabelFormat",
        tooltip: "crm_fields.dhlLabelFormat_help",
        fieldstyle: "max-width: 40ch"
    },

    { name: "dhl_shipper", type: "headline", label: "crm_fields.dhlShipper" },

    { name: "dhl_shipper_name", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.dhlShipperName", tooltip: "crm_fields.dhlShipperName_help" },
    { name: "dhl_shipper_street", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.dhlShipperStreet", tooltip: "crm_fields.dhlShipperStreet_help" },
    { name: "dhl_shipper_house", type: "input", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.dhlShipperHouse", tooltip: "crm_fields.dhlShipperHouse_help" },
    { name: "dhl_shipper_zip", type: "input", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.dhlShipperZip", tooltip: "crm_fields.dhlShipperZip_help" },
    { name: "dhl_shipper_city", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.dhlShipperCity", tooltip: "crm_fields.dhlShipperCity_help" },
    { name: "dhl_shipper_country", type: "input", size: 5, fieldstyle: "max-width: 10ch", label: "crm_fields.dhlShipperCountry", tooltip: "crm_fields.dhlShipperCountry_help" },

    { name: "weroni", type: "headline", label: "crm_fields.weroniHeadline" },
    { name: "weroni_enabled", type: "checkbox", label: "crm_fields.weroniEnabled", tooltip: "crm_fields.weroniEnabled_help" },
    { name: "weroni_mode", type: "select", items: [{ value: "assistant", title: "Assistent" }, { value: "autonomous", title: "Autonom" }], fieldstyle: "max-width: 30ch", label: "crm_fields.weroniMode", tooltip: "crm_fields.weroniMode_help" },
    { name: "weroni_system_prompt", type: "textarea", rows: 6, fieldstyle: "max-width: 80ch", label: "crm_fields.weroniSystemPrompt", tooltip: "crm_fields.weroniSystemPrompt_help" },
    { name: "weroni_phone_number", type: "input", size: 20, fieldstyle: "max-width: 30ch", label: "crm_fields.weroniPhoneNumber", tooltip: "crm_fields.weroniPhoneNumber_help" },

    { name: "accounting", type: "headline", label: "crm_fields.accountingHeadline" },
    { name: "accounting_ai_model", type: "select", items: [{ value: "claude-sonnet-4-6-20250514", title: "Claude Sonnet 4.6 (Standard)" }, { value: "claude-haiku-4-5-20251001", title: "Claude Haiku 4.5 (Schneller)" }, { value: "claude-opus-4-6-20250514", title: "Claude Opus 4.6 (Genauer)" }], fieldstyle: "max-width: 40ch", label: "crm_fields.accountingAiModel", tooltip: "crm_fields.accountingAiModel_help" },
    { name: "accounting_default_tax_rate", type: "select", items: [{ value: "19", title: "19% (Standard)" }, { value: "7", title: "7% (Ermaessigt)" }, { value: "0", title: "0% (Steuerfrei)" }], fieldstyle: "max-width: 20ch", label: "crm_fields.accountingDefaultTaxRate", tooltip: "crm_fields.accountingDefaultTaxRate_help" },
    { name: "accounting_default_debit_account", type: "input", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.accountingDefaultDebitAccount", tooltip: "crm_fields.accountingDefaultDebitAccount_help" },
    { name: "accounting_default_credit_account", type: "input", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.accountingDefaultCreditAccount", tooltip: "crm_fields.accountingDefaultCreditAccount_help" },
    { name: "accounting_auto_create_vendor", type: "checkbox", label: "crm_fields.accountingAutoCreateVendor", tooltip: "crm_fields.accountingAutoCreateVendor_help" },

    { name: "ebay", type: "headline", label: "crm_fields.ebayHeadline" },
    { name: "ebay_enabled", type: "checkbox", label: "crm_fields.ebayEnabled", tooltip: "crm_fields.ebayEnabled_help" },
    { name: "ebay_environment", type: "select", items: [{ value: "production", title: "Produktiv" }, { value: "sandbox", title: "Sandbox (Test)" }], fieldstyle: "max-width: 30ch", label: "crm_fields.ebayEnvironment", tooltip: "crm_fields.ebayEnvironment_help" },
    { name: "ebay_marketplace_id", type: "input", size: 20, fieldstyle: "max-width: 20ch", label: "crm_fields.ebayMarketplaceId", tooltip: "crm_fields.ebayMarketplaceId_help" },
    { name: "ebay_client_id", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.ebayClientId", tooltip: "crm_fields.ebayClientId_help" },
    { name: "ebay_client_secret", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.ebayClientSecret", tooltip: "crm_fields.ebayClientSecret_help" },
    { name: "ebay_refresh_token", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.ebayRefreshToken", tooltip: "crm_fields.ebayRefreshToken_help" },
    { name: "ebay_default_parts_id", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.ebayDefaultPartsId", tooltip: "crm_fields.ebayDefaultPartsId_help" },
    { name: "ebay_employee_login", type: "input", size: 30, fieldstyle: "max-width: 30ch", label: "crm_fields.ebayEmployeeLogin", tooltip: "crm_fields.ebayEmployeeLogin_help" },
    { name: "ebay_panel", type: "component", component: "ebay-status", label: "crm_fields.ebayPanel" },

    { name: "ebay_listing", type: "headline", label: "crm_fields.ebayListingHeadline" },
    { name: "ebay_listing_enabled", type: "checkbox", label: "crm_fields.ebayListingEnabled", tooltip: "crm_fields.ebayListingEnabled_help" },
    { name: "ebay_default_category_id", type: "input", size: 20, fieldstyle: "max-width: 30ch", label: "crm_fields.ebayCategoryId", tooltip: "crm_fields.ebayCategoryId_help" },
    { name: "ebay_default_condition", type: "select", items: [{ value: "NEW", title: "Neu" }, { value: "USED_EXCELLENT", title: "Gebraucht – sehr gut" }, { value: "USED_GOOD", title: "Gebraucht – gut" }, { value: "USED_ACCEPTABLE", title: "Gebraucht – akzeptabel" }, { value: "FOR_PARTS_OR_NOT_WORKING", title: "Defekt / Ersatzteil" }], fieldstyle: "max-width: 40ch", label: "crm_fields.ebayCondition", tooltip: "crm_fields.ebayCondition_help" },
    { name: "ebay_payment_policy_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayPaymentPolicy", tooltip: "crm_fields.ebayPaymentPolicy_help" },
    { name: "ebay_return_policy_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayReturnPolicy", tooltip: "crm_fields.ebayReturnPolicy_help" },
    { name: "ebay_fulfillment_policy_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayFulfillmentPolicy", tooltip: "crm_fields.ebayFulfillmentPolicy_help" },
    { name: "ebay_merchant_location_key", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayLocationKey", tooltip: "crm_fields.ebayLocationKey_help" },
    { name: "ebay_listing_quantity", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.ebayListingQuantity", tooltip: "crm_fields.ebayListingQuantity_help" }
];

export default crmDefaultsConfig;
