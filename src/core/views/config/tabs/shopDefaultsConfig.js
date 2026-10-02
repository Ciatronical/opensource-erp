// src/core/views/config/tabs/shopDefaultsConfig.js
//
// Felder des Einstellungen-Tabs der Shop-Erweiterung. Die Namen entsprechen
// den shop_*-Schlüsseln in defaults_oserp, angelegt von
// backend/upstall/shop/company_schema.sql.
//
// Hier stehen nur Einstellungen für den ganzen Mandanten. Was einer Instanz
// gehört — Shop-Schlüssel, Adressen und Verzeichnisse der Webseite,
// Vorlagensatz, HugoCMS, PayPal, Mails, eBay-Zugang —, steht seit
// dev/shop-mehrere-kanaele.md in der Karte des Verkaufskanals, in der eigenen
// Ansicht „Verkaufskanäle" (src/features/shop/views/shop.channels.vue).

const shopDefaultsConfig = [
    { name: "shop_access", type: "headline", label: "crm_fields.shopAccess" },

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
    // Lagerplatz für Verkäufe (dev/shop-verkaufskanaele.md, O14): Rechnungen aus
    // HugoShop und eBay buchen die Waren von hier aus. Leer = keine Buchung.
    // Die Auswahl „Lager – Platz" lädt der Tab (quellen.shopStockBins).
    { name: "shop_stock_bin_id", type: "dynamic-select", source: "shopStockBins", itemTitle: "title", itemValue: "value", fieldstyle: "max-width: 60ch", label: "crm_fields.shopStockBinId", tooltip: "crm_fields.shopStockBinId_help" },

    // Verkaufskanäle (dev/shop-mehrere-kanaele.md): eigene Ansicht im
    // Shop-Menü (shop-channels); hier nur der Verweis dorthin.
    { name: "shop_sales_channels", type: "component", component: "sales-channels-link" },

    // Länder (dev/shop-versand.md, Schritt 3): Zuordnung der Freitexte aus den
    // Adressen zu ISO-Codes — Grundlage für Versandkosten und Lieferländer.
    // Eigene Tabellen, deshalb eine Komponente.
    { name: "shop_countries", type: "component", component: "countries" },

    { name: "shop_shipping", type: "headline", label: "crm_fields.shopShipping" },

    // Einen Versandartikel für alle gibt es nicht mehr: jede Versandart hat
    // ihren eigenen. Ohne Versandart läuft der Versand als „Standard" ohne
    // Kosten, und die Shop-Übersicht warnt.
    // Die Freigrenze steht je Kanal in der Ansicht „Verkaufskanäle"
    // (dev/shop-versand.md, Entscheidung 4)

    // Versandarten, Länderzonen und Preise (dev/shop-versand.md, Schritt 4):
    // eigene Ansicht im Shop-Menü (shop-shipping); hier nur der Verweis dorthin
    { name: "shop_shipping_methods", type: "component", component: "shipping-methods-link" },

    { name: "shop_payment", type: "headline", label: "crm_fields.shopPayment" },

    { name: "shop_payment_account_owner", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopPaymentAccountOwner", tooltip: "crm_fields.shopPaymentAccountOwner_help" },
    { name: "shop_payment_bank", type: "input", size: 40, fieldstyle: "max-width: 40ch", label: "crm_fields.shopPaymentBank", tooltip: "crm_fields.shopPaymentBank_help" },
    { name: "shop_payment_iban", type: "input", size: 34, fieldstyle: "max-width: 35ch", label: "crm_fields.shopPaymentIban", tooltip: "crm_fields.shopPaymentIban_help" },
    { name: "shop_payment_bic", type: "input", size: 11, fieldstyle: "max-width: 20ch", label: "crm_fields.shopPaymentBic", tooltip: "crm_fields.shopPaymentBic_help" },

    { name: "shop_publish", type: "headline", label: "crm_fields.shopPublish" },

    // Wurzel aller Webseiten: die Verzeichnisse der HugoShops gelten relativ
    // dazu. Trägt ein Administrator sie zusätzlich in die settings.ini ein,
    // wirkt der Eintrag dort als Riegel: die eingestellte Wurzel muss darunter
    // liegen.
    //
    // Gebaut wird mit dem Programm hugo aus dem Verzeichnis
    // shop_publish_command_path — nur das Verzeichnis, den Dateinamen und die
    // Argumente setzt das Backend selbst zusammen und prüft beides vor jedem
    // Bau. Ein Eintrag in der settings.ini springt ein, wenn das Feld leer
    // ist, und erscheint dort als Vorgabe.
    //
    // shop_job_retention_days: Nach wie vielen Tagen der Läufer erfolgreich
    // erledigte Aufträge aus der Warteschlange löscht. 0 schaltet das ab;
    // fehlgeschlagene Aufträge bleiben immer stehen.
    { name: "shop_sites_dir", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.shopSitesDir", tooltip: "crm_fields.shopSitesDir_help" },
    { name: "shop_publish_command_path", type: "input", size: 60, fieldstyle: "max-width: 60ch", validate: "absolutePath", label: "crm_fields.shopPublishCommandPath", tooltip: "crm_fields.shopPublishCommandPath_help" },
    { name: "shop_job_retention_days", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.shopJobRetentionDays", tooltip: "crm_fields.shopJobRetentionDays_help" },
    { name: "shop_thumbnail_size", type: "input", inputType: "number", size: 10, fieldstyle: "max-width: 15ch", label: "crm_fields.shopThumbnailSize", tooltip: "crm_fields.shopThumbnailSize_help" },
    // Adresse von OpensourceERP, unter der eBay die Artikelbilder abholt —
    // für alle eBay-Kanäle dieselbe; Zugang und Richtlinien stehen je
    // eBay-Kanal in seiner Karte
    { name: "ebay_public_host", type: "input", size: 60, fieldstyle: "max-width: 60ch", label: "crm_fields.ebayPublicHost", tooltip: "crm_fields.ebayPublicHost_help" },

    { name: "shop_search", type: "headline", label: "crm_fields.shopSearch" },

    { name: "shop_search_weighting", type: "input", size: 10, fieldstyle: "max-width: 20ch", label: "crm_fields.shopSearchWeighting", tooltip: "crm_fields.shopSearchWeighting_help" },
];

export default shopDefaultsConfig;
