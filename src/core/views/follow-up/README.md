# Wiedervorlage (Follow-Up) Modul

Modernes Wiedervorlage-System für OpensourceERP mit Post-It Style Kanban-Board.

## ✨ Features

- **Kanban-Board**: Drag & Drop zwischen Spalten (Überfällig, Heute, Kommend)
- **Post-It Style**: Moderne, farbcodierte Karten mit Hover-Effekten
- **Listenansicht**: Tabellarische Darstellung mit Sortierung
- **Kalenderansicht**: Monatsübersicht mit Tages-Detail
- **Dashboard-Widget**: Kompakte Übersicht für die Startseite
- **Responsive**: Funktioniert auf Desktop und Mobile
- **i18n**: Deutsch und Englisch

## 📁 Dateistruktur

```
src/core/views/follow-up/
├── follow-up.view.vue          # Hauptansicht
├── components/
│   ├── follow-up-card.vue      # Post-It Karte
│   ├── follow-up-dialog.vue    # Erstellen/Bearbeiten Dialog
│   ├── follow-up-list.vue      # Listenansicht
│   ├── follow-up-calendar.vue  # Kalenderansicht
│   └── follow-up-widget.vue    # Dashboard-Widget
├── locales/
│   ├── de.json                 # Deutsche Übersetzungen
│   └── en.json                 # Englische Übersetzungen
└── README.md

backend/api/
├── functions/
│   └── fn_follow_up.sql        # PostgreSQL Funktionen
└── followup.api.php            # PHP API (Transport Layer)
```

## 🚀 Installation

### 1. Datenbank-Funktionen installieren

```bash
psql -U postgres -d your_database -f backend/api/functions/fn_follow_up.sql
```

### 2. Route hinzufügen

In `src/core/router/index.js`:

```javascript
import FollowUpView from '@/core/views/follow-up/follow-up.view.vue';

// In routes Array:
{
    path: '/wiedervorlage',
    name: 'follow-up',
    component: FollowUpView
}
```

### 3. Übersetzungen registrieren

In `src/i18n/index.js`:

```javascript
import followUpDe from '@/core/views/follow-up/locales/de.json';
import followUpEn from '@/core/views/follow-up/locales/en.json';

// In messages:
const messages = {
    de: { ...otherDe, ...followUpDe },
    en: { ...otherEn, ...followUpEn }
};
```

### 4. Widget auf Startseite einbinden (optional)

```vue
<template>
    <follow-up-widget :max-display="5" />
</template>

<script setup>
import FollowUpWidget from '@/core/views/follow-up/components/follow-up-widget.vue';
</script>
```

### 5. Menüeintrag hinzufügen

```javascript
{
    title: 'Wiedervorlage',
    icon: 'mdi-clipboard-text-clock',
    to: { name: 'follow-up' }
}
```

## 🗄️ Datenbank-Schema

Das Modul nutzt die bestehenden kivitendo-Tabellen:

- `follow_ups` - Haupttabelle
- `follow_up_links` - Verknüpfungen zu Kunden, Rechnungen etc.
- `follow_up_created_for_employees` - Mitarbeiter-Zuweisungen
- `follow_up_done` - Erledigungsstatus
- `notes` - Notizen (subject, body)

## 📡 API-Endpunkte

| Action | Beschreibung |
|--------|--------------|
| `getAll` | Alle Wiedervorlagen laden |
| `getById` | Einzelne Wiedervorlage |
| `getDashboard` | Dashboard-Daten |
| `create` | Neue Wiedervorlage |
| `update` | Aktualisieren |
| `markDone` | Als erledigt markieren |
| `markUndone` | Wieder öffnen |
| `delete` | Löschen |

### Beispiel API-Call

```javascript
// Im Store
const result = await store.apiCall('followUp', 'getAll', {
    showDone: false,
    fromDate: '2025-01-01',
    toDate: '2025-12-31'
});
```

## 🎨 Prioritäten / Farben

| Priorität | Farbe | Beschreibung |
|-----------|-------|--------------|
| `overdue` | Rot | Überfällig |
| `today` | Amber | Heute fällig |
| `soon` | Orange | In den nächsten 3 Tagen |
| `normal` | Grün | Später |

## 🔗 Verknüpfungstypen

Wiedervorlagen können mit folgenden Objekten verknüpft werden:

- `customer` - Kunde
- `vendor` - Lieferant
- `sales_quotation` - Angebot
- `sales_order` - Auftrag
- `sales_delivery_order` - Lieferschein
- `sales_invoice` - Rechnung
- `request_quotation` - Anfrage
- `purchase_order` - Bestellung
- `purchase_delivery_order` - Wareneingang
- `purchase_invoice` - Eingangsrechnung

## 🧩 Integration mit anderen Views

### Wiedervorlage aus Kundenansicht erstellen

```vue
<follow-up-dialog
    v-model="dialogOpen"
    :context-link="{
        type: 'customer',
        id: customer.id,
        info: customer.name
    }"
    @save="handleSave"
/>
```

## 📱 Responsive Design

- **Desktop**: 3-Spalten Kanban-Board
- **Tablet**: 2-Spalten oder gestapelt
- **Mobile**: Gestapelte Spalten, Touch-optimiert

## 🛠️ Technologien

- **Vue 3** mit Composition API
- **Vuetify 3** für UI-Komponenten
- **vuedraggable** für Drag & Drop
- **vue-i18n** für Übersetzungen
- **PostgreSQL** Funktionen für Datenzugriff

## 📝 Code-Konventionen

Entsprechend den Projekt-Richtlinien:
- Dateiname und Pfad als Kommentar in der ersten Zeile
- JSON aus der Datenbank holen - nicht mit PHP zusammenbauen
- 4 Leerzeichen Einrückung
- Vue 3 Composition API mit `<script setup>`
