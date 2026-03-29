# Customer Vendor Search - Refaktorierte Komponenten

Diese ZIP-Datei enthält die aufgeteilte und refaktorierte Version der `search.view.vue` Komponente.

## Struktur

```
search/
├── search.view.vue (Hauptkomponente, ~230 Zeilen)
├── components/
│   ├── search-filters.component.vue (Type-Filter & SQL Query, ~50 Zeilen)
│   ├── customer-vendor-search-form.component.vue (Kunden/Lieferanten-Formular, ~400 Zeilen)
│   ├── contacts-search-form.component.vue (Kontakte-Formular, ~250 Zeilen)
│   └── search-results-table.component.vue (Ergebnistabelle, ~150 Zeilen)
└── composables/
    └── useCustomerVendorOptions.js (Gemeinsame Dropdown-Optionen, ~180 Zeilen)
```

## Installation

1. Entpacke die ZIP-Datei
2. Kopiere den gesamten `search/` Ordner nach `src/views/customer-vendor/`
3. Der `dialogs/` Ordner ist bereits vorhanden und wird nicht überschrieben

## Änderungen gegenüber dem Original

### Vorteile der Aufteilung

- **Bessere Wartbarkeit**: Jede Komponente hat eine klare Verantwortlichkeit
- **Reduzierte Komplexität**: Von 600+ Zeilen auf max. 400 Zeilen pro Komponente
- **Wiederverwendbarkeit**: Formulare und Komponenten können in anderen Views verwendet werden
- **Testbarkeit**: Kleinere Komponenten sind leichter zu testen
- **Übersichtlichkeit**: Klare Struktur mit logischer Gruppierung

### Hauptkomponente (search.view.vue)

- Orchestriert alle Unterkomponenten
- Enthält die Geschäftslogik (API-Calls, State Management)
- Minimale UI-Definition
- ~230 Zeilen (vorher: 600+)

### Komponenten

1. **search-filters.component.vue**
   - Radio Buttons für Type-Auswahl (customer/vendor/contacts)
   - SQL Query Toggle und Input
   - Reine Präsentationskomponente

2. **customer-vendor-search-form.component.vue**
   - Alle Suchfelder für Kunden und Lieferanten
   - Toggle für zusätzliche Suchkriterien
   - Nutzt `useCustomerVendorOptions` Composable

3. **contacts-search-form.component.vue**
   - Alle Suchfelder für Kontakte
   - Nutzt `useCustomerVendorOptions` Composable

4. **search-results-table.component.vue**
   - Zeigt Suchergebnisse in VDataTable
   - Dynamische Headers basierend auf Datentyp
   - Bulk Actions Menü
   - Zebra-Streifen Styling

### Composable

**useCustomerVendorOptions.js**
- Zentralisiert alle Dropdown-Optionen
- Zugriff auf oserpStore
- Übersetzungen via vue-i18n
- Kann in anderen Komponenten wiederverwendet werden

## Verwendung

Die Hauptkomponente wird identisch zur vorherigen Version verwendet:

```vue
<template>
  <search-view :message="message" :messages="messages" />
</template>

<script setup>
import SearchView from '@/views/customer-vendor/search/search.view.vue';
</script>
```

## Props & Events

### search.view.vue

**Props:**
- `message` (Object): Einzelne Nachricht
- `messages` (Array): Array von Nachrichten
- `crmView` (Boolean): CRM-Ansicht Flag

### Unterkomponenten

Alle Props und Events sind in den jeweiligen Komponenten mit JSDoc dokumentiert.

## Hinweise

- Die Funktionalität ist identisch zum Original
- Alle Übersetzungen verwenden die bestehenden i18n Keys
- Import-Pfade müssen ggf. angepasst werden
- `dialogs/` Ordner ist nicht enthalten (bereits vorhanden)

## Nächste Schritte

Nach der Integration könnten weitere Optimierungen durchgeführt werden:

1. Unit Tests für Komponenten schreiben
2. Composable erweitern für andere Customer/Vendor Views
3. Formular-Validierung hinzufügen
4. Performance-Optimierungen (z.B. virtuelle Scrolling für große Tabellen)
