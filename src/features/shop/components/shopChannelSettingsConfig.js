// src/features/shop/components/shopChannelSettingsConfig.js
//
// Einstellungen einer Instanz in der Kanalkarte (dev/shop-mehrere-kanaele.md,
// Schritt 5). Je Art die Felder, die bis dahin im Reiter „Shop" unter ihren
// alten Schlüsseln (shop_*, ebay_*) standen. Die Namen sind die Schlüssel in
// sales_channel_shop.settings bzw. — bei type "password" —
// sales_channel_secret_shop, wie shop_channel_setting_keys() im Schema sie
// nennt. Das Backend nimmt nur diese an.
//
// Darstellung über shop-config-field.component.vue wie im Reiter „Shop";
// Beschriftungen und Hilfetexte sind dieselben (crm_fields.*).

const hugoshop = [
    { name: "zugang", type: "headline", label: "crm_fields.shopAccess" },

    // Erzeugt wird ein neuer Schlüssel im Browser; er gehört auch in den
    // Reverse-Proxy, falls einer den mitgelieferten ersetzt. Je HugoShop ein
    // eigener — er bestimmt im öffentlichen Zugang Mandant und Kanal.
    { name: "public_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", generate: true, label: "crm_fields.shopPublicKey", tooltip: "crm_fields.shopPublicKey_help" },
    { name: "allowed_origins", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopAllowedOrigins", tooltip: "crm_fields.shopAllowedOrigins_help" },

    { name: "adressen", type: "headline", label: "crm_fields.shopLinks" },

    { name: "base_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopBaseUrl", tooltip: "crm_fields.shopBaseUrl_help" },
    { name: "products_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopProductsLink", tooltip: "crm_fields.shopProductsLink_help" },
    { name: "category_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopCategoryLink", tooltip: "crm_fields.shopCategoryLink_help" },
    { name: "thumbnails_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopThumbnailsLink", tooltip: "crm_fields.shopThumbnailsLink_help" },
    { name: "images_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopImagesLink", tooltip: "crm_fields.shopImagesLink_help" },
    { name: "downloads_link", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopDownloadsLink", tooltip: "crm_fields.shopDownloadsLink_help" },

    { name: "veroeffentlichung", type: "headline", label: "crm_fields.shopPublish" },

    { name: "backend_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopBackendUrl", tooltip: "crm_fields.shopBackendUrl_help" },
    { name: "template_set", type: "dynamic-select", source: "shopTemplateSets", itemTitle: "title", itemValue: "name", fieldstyle: "max-width: 60ch", label: "crm_fields.shopTemplateSet", tooltip: "crm_fields.shopTemplateSet_help" },
    // lokal: OSERP schreibt in die Webseite und baut selbst; HugoCMS: Übertragung
    // an HugoCMS, Bau dort — dann gelten die Verzeichnisfelder nicht
    {
        name: "publish_mode", type: "select", fieldstyle: "max-width: 45ch",
        items: [
            { title: "crm_fields.shopPublishModeLocal", value: "local" },
            { title: "crm_fields.shopPublishModeHugoCms", value: "hugocms" },
        ],
        label: "crm_fields.shopPublishMode", tooltip: "crm_fields.shopPublishMode_help"
    },
    // Relativ zur Wurzel aller Webseiten (Reiter „Shop"); jeder HugoShop braucht
    // sein eigenes Verzeichnis
    { name: "site_dir", type: "input", size: 60, fieldstyle: "max-width: 60ch", validate: "relativePath", browse: "sites", label: "crm_fields.shopSiteDir", tooltip: "crm_fields.shopSiteDir_help" },
    { name: "content_dir", type: "input", size: 60, fieldstyle: "max-width: 60ch", validate: "relativePath", browse: "site", label: "crm_fields.shopContentDir", tooltip: "crm_fields.shopContentDir_help" },
    { name: "images_dir", type: "input", size: 60, fieldstyle: "max-width: 60ch", validate: "relativePath", browse: "site", label: "crm_fields.shopImagesDir", tooltip: "crm_fields.shopImagesDir_help" },
    { name: "thumbnails_dir", type: "input", size: 60, fieldstyle: "max-width: 60ch", validate: "relativePath", browse: "site", label: "crm_fields.shopThumbnailsDir", tooltip: "crm_fields.shopThumbnailsDir_help" },
    { name: "publish_clean_destination", type: "checkbox", label: "crm_fields.shopPublishCleanDestination", tooltip: "crm_fields.shopPublishCleanDestination_help" },
    // Seiten bei Preisänderungen automatisch neu schreiben (V22) und was beim
    // Abschalten des HugoShops mit den Seiten geschieht (V16)
    { name: "auto_publish", type: "checkbox", label: "crm_fields.shopAutoPublish", tooltip: "crm_fields.shopAutoPublish_help" },
    {
        name: "channel_off_pages", type: "select", fieldstyle: "max-width: 60ch",
        items: [
            { title: "crm_fields.shopChannelOffPagesDraft", value: "draft" },
            { title: "crm_fields.shopChannelOffPagesRemove", value: "remove" },
        ],
        label: "crm_fields.shopChannelOffPages", tooltip: "crm_fields.shopChannelOffPages_help"
    },
    // action: die Karte zeigt unter den Feldern „Verbindung prüfen"
    {
        name: "hugocms",
        type: "group",
        icon: "mdi-web-sync",
        label: "crm_fields.shopHugoCms",
        tooltip: "crm_fields.shopHugoCms_help",
        action: "hugocmsTest",
        fields: [
            { name: "hugocms_url", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopHugoCmsUrl", tooltip: "crm_fields.shopHugoCmsUrl_help" },
            { name: "hugocms_key", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopHugoCmsKey", tooltip: "crm_fields.shopHugoCmsKey_help" },
        ],
    },

    { name: "mail", type: "headline", label: "crm_fields.shopMail" },

    { name: "invoice_mail_subject", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopInvoiceMailSubject", tooltip: "crm_fields.shopInvoiceMailSubject_help" },
    { name: "withdrawal_mail_to", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopWithdrawalMailTo", tooltip: "crm_fields.shopWithdrawalMailTo_help" },

    { name: "paypal", type: "headline", label: "crm_fields.shopPaypal" },

    // Der Schalter entscheidet, welches Paar gilt; die Karte des aktiven ist
    // hervorgehoben (M2: PayPal je HugoShop)
    { name: "paypal_sandbox", type: "checkbox", label: "crm_fields.shopPaypalSandbox", tooltip: "crm_fields.shopPaypalSandbox_help" },
    {
        name: "paypal_payment_method_preference", type: "select", fieldstyle: "max-width: 45ch",
        items: [
            { title: "IMMEDIATE_PAYMENT_REQUIRED", value: "IMMEDIATE_PAYMENT_REQUIRED" },
            { title: "UNRESTRICTED", value: "UNRESTRICTED" },
        ],
        label: "crm_fields.shopPaypalPaymentMethodPreference", tooltip: "crm_fields.shopPaypalPaymentMethodPreference_help"
    },
    {
        type: "group",
        name: "paypal_sandbox_group",
        icon: "mdi-test-tube",
        label: "crm_fields.shopPaypalSandboxGroup",
        tooltip: "crm_fields.shopPaypalSandboxGroup_help",
        activeWhen: { field: "paypal_sandbox", value: true },
        fields: [
            { name: "paypal_sandbox_client_id", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalSandboxClientId", tooltip: "crm_fields.shopPaypalSandboxClientId_help" },
            { name: "paypal_sandbox_secret", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalSandboxSecret", tooltip: "crm_fields.shopPaypalSandboxSecret_help" },
            // Erzwingt Fehlerantworten von PayPal — wirkt nur in der Testumgebung
            { name: "paypal_mock_response", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalMockResponse", tooltip: "crm_fields.shopPaypalMockResponse_help" },
        ],
    },
    {
        type: "group",
        name: "paypal_live_group",
        icon: "mdi-cash-multiple",
        label: "crm_fields.shopPaypalLiveGroup",
        tooltip: "crm_fields.shopPaypalLiveGroup_help",
        activeWhen: { field: "paypal_sandbox", value: false },
        fields: [
            { name: "paypal_live_client_id", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalLiveClientId", tooltip: "crm_fields.shopPaypalLiveClientId_help" },
            { name: "paypal_live_secret", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopPaypalLiveSecret", tooltip: "crm_fields.shopPaypalLiveSecret_help" },
        ],
    },
]

// eBay: je Kanal ein eBay-Konto (M4). Die Adresse für die Bilder
// (ebay_public_host) gilt für den ganzen Mandanten und bleibt im Reiter „Shop".
// action ebayPanel: Verbindungstest, Bestellabruf und Stand dieses Kanals.
const ebay = [
    {
        name: "ebay_zugang",
        type: "group",
        icon: "mdi-shopping",
        label: "crm_fields.shopEbay",
        tooltip: "crm_fields.shopEbay_help",
        action: "ebayPanel",
        fields: [
            {
                name: "environment", type: "select", fieldstyle: "max-width: 30ch",
                items: [
                    { title: "crm_fields.ebayEnvironmentProduction", value: "production" },
                    { title: "crm_fields.ebayEnvironmentSandbox", value: "sandbox" },
                ],
                label: "crm_fields.ebayEnvironment", tooltip: "crm_fields.ebayEnvironment_help"
            },
            { name: "marketplace_id", type: "input", size: 20, fieldstyle: "max-width: 20ch", label: "crm_fields.ebayMarketplaceId", tooltip: "crm_fields.ebayMarketplaceId_help" },
            { name: "client_id", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.ebayClientId", tooltip: "crm_fields.ebayClientId_help" },
            { name: "client_secret", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.ebayClientSecret", tooltip: "crm_fields.ebayClientSecret_help" },
            { name: "refresh_token", type: "password", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.ebayRefreshToken", tooltip: "crm_fields.ebayRefreshToken_help" },
            { name: "default_category_id", type: "input", size: 20, fieldstyle: "max-width: 30ch", label: "crm_fields.ebayCategoryId", tooltip: "crm_fields.ebayCategoryId_help" },
            {
                name: "default_condition", type: "select", fieldstyle: "max-width: 40ch",
                items: [
                    { title: "ShopView.ebayCondition.NEW", value: "NEW" },
                    { title: "ShopView.ebayCondition.USED_EXCELLENT", value: "USED_EXCELLENT" },
                    { title: "ShopView.ebayCondition.USED_GOOD", value: "USED_GOOD" },
                    { title: "ShopView.ebayCondition.USED_ACCEPTABLE", value: "USED_ACCEPTABLE" },
                    { title: "ShopView.ebayCondition.FOR_PARTS_OR_NOT_WORKING", value: "FOR_PARTS_OR_NOT_WORKING" },
                ],
                label: "crm_fields.ebayCondition", tooltip: "crm_fields.ebayCondition_help"
            },
            { name: "payment_policy_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayPaymentPolicy", tooltip: "crm_fields.ebayPaymentPolicy_help" },
            { name: "return_policy_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayReturnPolicy", tooltip: "crm_fields.ebayReturnPolicy_help" },
            { name: "fulfillment_policy_id", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayFulfillmentPolicy", tooltip: "crm_fields.ebayFulfillmentPolicy_help" },
            { name: "merchant_location_key", type: "input", size: 30, fieldstyle: "max-width: 40ch", label: "crm_fields.ebayLocationKey", tooltip: "crm_fields.ebayLocationKey_help" },
            // Bestellimport
            { name: "default_parts_id", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.ebayDefaultPartsId", tooltip: "crm_fields.ebayDefaultPartsId_help" },
            { name: "employee_login", type: "input", size: 30, fieldstyle: "max-width: 30ch", label: "crm_fields.ebayEmployeeLogin", tooltip: "crm_fields.ebayEmployeeLogin_help" },
        ],
    },
]

const shopChannelSettingsConfig = { hugoshop, ebay }

/** Alle Felder einer Art, Gruppen aufgelöst — für Laden und Speichern */
export function channelSettingFields(typ) {
    return (shopChannelSettingsConfig[typ] || [])
        .flatMap(feld => (feld.type === 'group' ? (feld.fields || []) : [feld]))
        .filter(feld => feld.type !== 'headline')
}

export default shopChannelSettingsConfig
