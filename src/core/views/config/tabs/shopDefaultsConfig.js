// src/core/views/config/tabs/shopDefaultsConfig.js
//
// Felder des Einstellungen-Tabs der Shop-Erweiterung. Die Namen entsprechen
// den shop_*-Schlüsseln in defaults_oserp, angelegt von
// backend/upstall/shop/company_schema.sql.

const shopDefaultsConfig = [
    { name: "shop_access", type: "headline", label: "crm_fields.shopAccess" },

    // Wird beim Laden bewusst nicht mitgeliefert (siehe getCompanyConfig).
    // Ein leer gelassenes Feld lässt den gespeicherten Wert unangetastet.
    { name: "shop_public_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPublicKey", tooltip: "crm_fields.shopPublicKey_help" },
    { name: "shop_allowed_origins", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopAllowedOrigins", tooltip: "crm_fields.shopAllowedOrigins_help" },
    { name: "shop_cart_lifetime_hours", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.shopCartLifetimeHours", tooltip: "crm_fields.shopCartLifetimeHours_help" },
    { name: "shop_context_lifetime_hours", type: "input", inputType: "number", size: 5, fieldstyle: "max-width: 15ch", label: "crm_fields.shopContextLifetimeHours", tooltip: "crm_fields.shopContextLifetimeHours_help" },

    { name: "shop_invoicing", type: "headline", label: "crm_fields.shopInvoicing" },

    { name: "shop_contact_login", type: "dynamic-select", source: "employees", itemTitle: "name", itemValue: "login", fieldstyle: "max-width: 60ch", label: "crm_fields.shopContactLogin", tooltip: "crm_fields.shopContactLogin_help" },
    { name: "shop_target_account", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopTargetAccount", tooltip: "crm_fields.shopTargetAccount_help" },
    { name: "shop_incoming_account", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopIncomingAccount", tooltip: "crm_fields.shopIncomingAccount_help" },
    { name: "shop_standard_taxzone", type: "input", size: 30, fieldstyle: "max-width: 30ch", label: "crm_fields.shopStandardTaxzone", tooltip: "crm_fields.shopStandardTaxzone_help" },
    { name: "shop_standard_currency", type: "input", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.shopStandardCurrency", tooltip: "crm_fields.shopStandardCurrency_help" },
    { name: "shop_tax_included", type: "checkbox", label: "crm_fields.shopTaxIncluded", tooltip: "crm_fields.shopTaxIncluded_help" },
    { name: "shop_active_price_source", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopActivePriceSource", tooltip: "crm_fields.shopActivePriceSource_help" },

    { name: "shop_shipping", type: "headline", label: "crm_fields.shopShipping" },

    { name: "shop_shipping_partnumber", type: "input", size: 20, fieldstyle: "max-width: 25ch", label: "crm_fields.shopShippingPartnumber", tooltip: "crm_fields.shopShippingPartnumber_help" },
    { name: "shop_free_shipping_from", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.shopFreeShippingFrom", tooltip: "crm_fields.shopFreeShippingFrom_help" },

    { name: "shop_payment", type: "headline", label: "crm_fields.shopPayment" },

    { name: "shop_payment_account_owner", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopPaymentAccountOwner", tooltip: "crm_fields.shopPaymentAccountOwner_help" },
    { name: "shop_payment_bank", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopPaymentBank", tooltip: "crm_fields.shopPaymentBank_help" },
    { name: "shop_payment_iban", type: "input", size: 34, fieldstyle: "max-width: 35ch", label: "crm_fields.shopPaymentIban", tooltip: "crm_fields.shopPaymentIban_help" },
    { name: "shop_payment_bic", type: "input", size: 11, fieldstyle: "max-width: 20ch", label: "crm_fields.shopPaymentBic", tooltip: "crm_fields.shopPaymentBic_help" },

    { name: "shop_paypal", type: "headline", label: "crm_fields.shopPaypal" },

    // Der Schalter steht vor den beiden Gruppen: er entscheidet, welche gilt,
    // und die Oberfläche zeigt das dann an der Karte an.
    { name: "shop_paypal_sandbox", type: "checkbox", label: "crm_fields.shopPaypalSandbox", tooltip: "crm_fields.shopPaypalSandbox_help" },
    {
        name: "shop_paypal_payment_method_preference", type: "select", fieldstyle: "max-width: 45ch",
        items: [
            { title: "IMMEDIATE_PAYMENT_REQUIRED", value: "IMMEDIATE_PAYMENT_REQUIRED" },
            { title: "UNRESTRICTED", value: "UNRESTRICTED" },
        ],
        label: "crm_fields.shopPaypalPaymentMethodPreference", tooltip: "crm_fields.shopPaypalPaymentMethodPreference_help"
    },

    // PayPal vergibt für Test- und Echtbetrieb getrennte Zugangsdaten. Zwei
    // Karten, damit die vier ähnlich benannten Felder nicht ineinander
    // übergehen und sichtbar ist, welches Paar gerade gilt.
    //
    // Die Geheimnisse werden wie shop_public_key nicht ausgeliefert; ein leer
    // gelassenes Feld behält den gespeicherten Wert.
    {
        type: "group",
        name: "shop_paypal_sandbox_group",
        icon: "mdi-test-tube",
        label: "crm_fields.shopPaypalSandboxGroup",
        tooltip: "crm_fields.shopPaypalSandboxGroup_help",
        activeWhen: { field: "shop_paypal_sandbox", value: true },
        fields: [
            { name: "shop_paypal_sandbox_client_id", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalSandboxClientId", tooltip: "crm_fields.shopPaypalSandboxClientId_help" },
            { name: "shop_paypal_sandbox_secret", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalSandboxSecret", tooltip: "crm_fields.shopPaypalSandboxSecret_help" },
            // Erzwingt Fehlerantworten von PayPal — wirkt nur in der Testumgebung
            // und steht deshalb hier und nicht bei den allgemeinen Angaben.
            { name: "shop_paypal_mock_response", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalMockResponse", tooltip: "crm_fields.shopPaypalMockResponse_help" },
        ],
    },
    {
        type: "group",
        name: "shop_paypal_live_group",
        icon: "mdi-cash-multiple",
        label: "crm_fields.shopPaypalLiveGroup",
        tooltip: "crm_fields.shopPaypalLiveGroup_help",
        activeWhen: { field: "shop_paypal_sandbox", value: false },
        fields: [
            { name: "shop_paypal_live_client_id", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalLiveClientId", tooltip: "crm_fields.shopPaypalLiveClientId_help" },
            { name: "shop_paypal_live_secret", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalLiveSecret", tooltip: "crm_fields.shopPaypalLiveSecret_help" },
        ],
    },

    { name: "shop_links", type: "headline", label: "crm_fields.shopLinks" },

    { name: "shop_base_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopBaseUrl", tooltip: "crm_fields.shopBaseUrl_help" },
    { name: "shop_products_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopProductsLink", tooltip: "crm_fields.shopProductsLink_help" },
    { name: "shop_category_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopCategoryLink", tooltip: "crm_fields.shopCategoryLink_help" },
    { name: "shop_thumbnails_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopThumbnailsLink", tooltip: "crm_fields.shopThumbnailsLink_help" },

    { name: "shop_search", type: "headline", label: "crm_fields.shopSearch" },

    { name: "shop_search_weighting", type: "input", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.shopSearchWeighting", tooltip: "crm_fields.shopSearchWeighting_help" },

    { name: "shop_mail", type: "headline", label: "crm_fields.shopMail" },

    { name: "shop_invoice_mail_subject", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopInvoiceMailSubject", tooltip: "crm_fields.shopInvoiceMailSubject_help" },
    { name: "shop_withdrawal_mail_to", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopWithdrawalMailTo", tooltip: "crm_fields.shopWithdrawalMailTo_help" },
];

export default shopDefaultsConfig;
