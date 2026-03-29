# Mandantenkonfiguration - Vollständige Store-Integration

Vue.js Komponenten für die Mandantenkonfiguration mit **vollständiger** Integration aller Store-Daten.

## ✨ Alle Features

- ✅ **Vollständige Store-Integration** - Alle verfügbaren Dropdown-Daten
- ✅ **Tab-übergreifende Suche** - Automatischer Tab-Wechsel
- ✅ **Boolean-Felder** - Korrekt als true/false
- ✅ **238 Felder** - Alle defaults-Felder unterstützt
- ✅ **Responsive** - Desktop & Mobile
- ✅ **i18n** - Deutsch & Englisch
- ✅ **Feld-Highlighting** bei Suche

## 🗄️ Datenbank-Schema

### Auth-Schema
```sql
auth.clients           -- Mandanten
auth.user              -- Benutzer
auth.group             -- Gruppen
auth.session_oserp     -- Sessions
public.defaults_oserp  -- OpenERP Defaults (Key-Value)
public.employee_config_oserp  -- Mitarbeiter-Config
public.features_oserp  -- Features
```

### Company-Schema
```sql
defaults              -- 238 Felder Mandantenkonfiguration
customer/vendor       -- Kunden/Lieferanten
employee              -- Mitarbeiter
currencies            -- Währungen
units                 -- Einheiten (Gewicht, Stück, etc.)
business              -- Geschäftstypen
language              -- Sprachen
tax_zones             -- Steuerzonen
pricegroup            -- Preisgruppen
payment_terms         -- Zahlungsbedingungen
delivery_terms        -- Lieferbedingungen
department            -- Abteilungen
printers              -- Drucker
chart                 -- Kontenplan
part_classifications  -- Warenklassifizierungen
```

## 📊 Store-Struktur

```javascript
store.session = {
    logged_in_employee: {
        id, login, name, ...
    },
    company_config: {
        defaults: {              // 238 Felder
            company, address_street1, currency_id, ...
        },
        defaults_oserp: {},      // Key-Value
        currencies: [
            {id: 1, name: "EUR"}, ...
        ],
        units: [
            {id: 6, name: "t", type: "dimension"}, ...
        ],
        languages: [
            {id: 1, description: "Deutsch", ...}, ...
        ],
        employees: [
            {id: 1119, name: "Ronny", ...}, ...
        ],
        tax_zones: [
            {id: 4, description: "Inland", sortkey: 1}, ...
        ],
        pricegroups: [
            {id: 1, pricegroup: "Standard", ...}, ...
        ],
        payment_terms: [...],
        delivery_terms: [...],
        business_types: [...],
        business: [...],
        department: [...],
        printers: [...],
        payment_acc: [          // Konten mit AP_paid
            {id: 123, accno: "1200", description: "Bank"}, ...
        ],
        company_employee_config: {},  // Key-Value
        part_classifications: [...]
    },
    customer_vendor: {
        profile: {...},
        contacts: [...],
        shiptos: [...],
        billing_addresses: [...],
        offers: [...],
        orders: [...],
        invoices: [...],
        custom_vars: [...],
        cars: [...]             // Optional (lxCars Feature)
    }
}
```

## 🔧 Store-Verwendung

### Import & Initialisierung

```javascript
import { oserpStore } from '@/core/stores/oserp.store.js';

const store = oserpStore();
```

### Defaults zugreifen

```javascript
// Lesen
const companyName = store.session.company_config.defaults.company;

// Schreiben
store.session.company_config.defaults.company = "Neue Firma";
```

### Dropdown-Daten

```javascript
// Währungen
const currencies = computed(() => {
    if (store.session?.company_config?.currencies) {
        return store.session.company_config.currencies;
    }
    return [];
});

// In Template verwenden
<v-select
    v-model="defaults.currency_id"
    :items="currencies"
    item-title="name"
    item-value="id"
/>
```

### Gewichtseinheiten (gefiltert)

```javascript
// Nur Gewichts-Units (type: "dimension")
const weightUnits = computed(() => {
    if (store.session?.company_config?.units) {
        return store.session.company_config.units
            .filter(unit => unit.type === 'dimension');
    }
    return [];
});
```

## 📁 Komponenten-Struktur

```
client-config-complete/
├── client-defaults.view.vue      # Hauptkomponente
├── tabs/
│   ├── miscellaneous.tab.vue     # ✅ Store-integriert
│   ├── warehouse.tab.vue         # ✅ Boolean korrigiert
│   ├── ranges.of.numbers.tab.vue # ✅ Feldsuche
│   ├── default.accounts.tab.vue
│   ├── posting.configuration.tab.vue
│   ├── datev.check.configuration.tab.vue
│   ├── orders.deleteable.tab.vue
│   ├── features.tab.vue
│   ├── stocktaking.tab.vue
│   ├── record.links.tab.vue
│   └── bank.tab.vue
├── locales/
│   ├── de.json
│   ├── en.json
│   └── INTEGRATION.md
└── backend-examples/
    ├── api/defaults/
    │   ├── get.php
    │   └── save.php
    └── sql/
        └── client_defaults.sql
```

## 🎯 Feldtypen (238 Felder)

- **101 Boolean** - Ja/Nein Dropdowns (`true`/`false`)
- **30 Number** - Numerische Eingaben
- **67 String** - Texteingaben
- **38 Null** - Optional

### Boolean-Felder Beispiele
```
webdav, revtrans, doc_files, vertreter, ap_add_doc,
reqdate_on, deliverydate_on, fuzzy_skonto, feature_datev,
transfer_default, normalize_vc_names, ...
```

### Number-Felder Beispiele
```
id, precision, currency_id, ap_chart_id, ar_chart_id,
ap_changeable, ar_changeable, email_journal, ...
```

### String-Felder Beispiele
```
company, address_street1, customernumber, vendornumber,
invnumber, templates, weightunit, accounting_method, ...
```

## 🔍 Suche

### Tab-übergreifend
- **"Letzte Nummern"** → Tab "Nummernkreise"
- **"Firma"** → Tab "Verschiedenes"
- **"Lager"** → Tab "Lager"
- **"DATEV"** → Tab "DATEV"

### Feldsuche
Im aktiven Tab werden Felder gefiltert und hervorgehoben.

## 🚀 Installation

1. Kopiere `client-defaults.view.vue` → `src/core/views/config/`
2. Kopiere `tabs/` → `src/core/views/config/tabs/`
3. Integriere Übersetzungen (siehe `locales/INTEGRATION.md`)
4. Backend einrichten (siehe `backend-examples/`)

## ⚙️ Backend-Integration

### PHP Endpoint (GET)

```php
// api/defaults/get.php
$mandant = DbhCompany::begin();
$result = $mandant->fetch("SELECT row_to_json(d) FROM (SELECT * FROM defaults) AS d");
echo json_encode($result);
```

### PHP Endpoint (SAVE)

```php
// api/defaults/save.php
$data = json_decode(file_get_contents('php://input'), true);
$mandant = DbhCompany::begin();
$mandant->execute("SELECT save_client_defaults($1)", [json_encode($data)]);
```

### PostgreSQL Funktion

```sql
-- Siehe backend-examples/sql/client_defaults.sql
CREATE OR REPLACE FUNCTION save_client_defaults(data json)
RETURNS void AS $$
-- Update defaults table with data
$$ LANGUAGE plpgsql;
```

## 🐛 Debugging

Die Komponente loggt beim Start in die Console:

```
=== DROPDOWN DATEN ===
Units: [{id: 6, name: "t", type: "dimension"}, ...]
Currencies: [{id: 1, name: "EUR"}, ...]
Languages: [{id: 1, description: "Deutsch"}, ...]
Employees: [{id: 1119, name: "Ronny"}, ...]
Tax Zones: [{id: 4, description: "Inland"}, ...]
```

## 💡 Beispiele

### Neuen Dropdown hinzufügen

```javascript
// In Tab-Komponente
const pricegroups = computed(() => {
    if (store.session?.company_config?.pricegroups) {
        return store.session.company_config.pricegroups;
    }
    return [];
});

// In Template
<v-select
    v-model="defaults.pricegroup_id"
    :items="pricegroups"
    item-title="pricegroup"
    item-value="id"
/>
```

### Mitarbeiter-Dropdown

```javascript
const employees = computed(() => {
    if (store.session?.company_config?.employees) {
        return store.session.company_config.employees
            .filter(emp => !emp.deleted)
            .sort((a, b) => a.name.localeCompare(b.name));
    }
    return [];
});
```

## 📚 Dokumentation

- **Backend PHP-Funktion:** Siehe `getCV()` - lädt alle Store-Daten
- **Datenbankschema:** auth_schema.sql + startup_schema.sql
- **i18n Integration:** locales/INTEGRATION.md
- **API Endpoints:** backend-examples/api/

## ✅ Was ist neu

### v3 → Complete
- ✅ **Alle Store-Daten** verfügbar
- ✅ **Currencies** als Dropdown (nicht Radio-Buttons)
- ✅ **Languages** aus Store
- ✅ **Weight Units** gefiltert nach type
- ✅ **Employees, Tax Zones, Pricegroups** verfügbar
- ✅ **Vollständige Dokumentation**
- ✅ **Datenbank-Schema** dokumentiert

## 🆘 Support

Bei Problemen:
1. Console-Ausgaben prüfen (F12)
2. Store-Struktur loggen: `console.log(store.session.company_config)`
3. README durchlesen
4. Backend-Beispiele anschauen

## 📝 Hinweise

- **Warehouses** sind NICHT im company_config Store
- **Charts (Konten)** müssen separat geladen werden (außer payment_acc)
- **Boolean-Werte:** Immer `true`/`false`, nie `1`/`0`
- **Store ist reaktiv:** Änderungen werden automatisch reflektiert
