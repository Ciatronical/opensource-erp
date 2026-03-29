# Customer Edit View - Modulare Komponentenstruktur

## Übersicht

Die CustomerEditView wurde in **21 kleine, wiederverwendbare Komponenten** aufgeteilt, um Wartbarkeit, Testbarkeit und Übersichtlichkeit zu verbessern. Alle Dateinamen folgen der Konvention mit Kleinbuchstaben und Punkten (`name.component.vue`).

## 📁 Verzeichnisstruktur

```
customer-edit-components/
├── customer.edit.view.vue          # Haupt-Container (10 KB)
├── README.md                        # Diese Datei
│
├── cards/                           # 7 wiederverwendbare Card-Komponenten
│   ├── access.data.card.vue        # Zugangsdaten (Username/Passwort)
│   ├── communication.card.vue      # Kommunikation (E-Mail, Telefon, etc.)
│   ├── conditions.card.vue         # Bedingungen (Kreditlimit, Zahlungsbedingungen)
│   ├── currency.prices.tax.card.vue # Währung, Preise & Steuern
│   ├── info.status.card.vue        # Info & Status (Branche, Verkäufer, etc.)
│   ├── name.address.card.vue       # Name & Adresse
│   └── numbers.ids.card.vue        # Nummern & IDs (Kundennummer, USt-ID)
│
├── tabs/                            # 9 Tab-Komponenten
│   ├── additional-billing.tab.vue  # Zusätzliche Rechnungsadressen
│   ├── bank.tab.vue                # Bankkonto-Informationen
│   ├── billing.tab.vue             # Hauptrechnungsadresse
│   ├── contacts.tab.vue            # Ansprechpartner
│   ├── deliveries.tab.vue          # Lieferungen (Platzhalter)
│   ├── notes.tab.vue               # Bemerkungen (Platzhalter)
│   ├── price-rules.tab.vue         # Preisregeln (Platzhalter)
│   ├── shipto.tab.vue              # Lieferadressen
│   └── turnover.tab.vue            # Umsatzstatistik (Platzhalter)
│
├── forms/                           # 3 Formular-Komponenten
│   ├── billing.address.form.vue    # Einzelne Rechnungsadresse
│   ├── contact.form.vue            # Einzelner Ansprechpartner
│   └── shipto.form.vue             # Einzelne Lieferadresse
│
└── components/                      # 1 Shared Component
    └── action.bar.vue              # Action-Buttons am Ende
```

## ✅ Vollständig implementierte Komponenten

### Cards (7/7)
- ✅ name.address.card.vue - Name, Adresse, Abteilungen
- ✅ communication.card.vue - Telefon, E-Mail, Homepage
- ✅ info.status.card.vue - Branche, Verkäufer, Status
- ✅ numbers.ids.card.vue - Kundennummer, USt-ID
- ✅ access.data.card.vue - Username, Passwort
- ✅ currency.prices.tax.card.vue - Währung, Rabatt, Steuern
- ✅ conditions.card.vue - Kreditlimit, Zahlungs-/Lieferbedingungen

### Tabs (9/9)
- ✅ billing.tab.vue - Orchestriert alle 7 Cards
- ✅ additional-billing.tab.vue - Expansion Panels mit billing.address.form
- ✅ bank.tab.vue - Kontoinhaber, IBAN, BIC, Mandat
- ✅ shipto.tab.vue - Expansion Panels mit shipto.form
- ✅ contacts.tab.vue - Expansion Panels mit contact.form
- ✅ deliveries.tab.vue - Platzhalter (v-alert)
- ✅ notes.tab.vue - Platzhalter (v-alert)
- ✅ price-rules.tab.vue - Platzhalter (v-alert)
- ✅ turnover.tab.vue - Platzhalter (v-alert)

### Forms (3/3)
- ✅ shipto.form.vue - Alle Lieferadressen-Felder
- ✅ contact.form.vue - Alle Ansprechpartner-Felder
- ✅ billing.address.form.vue - Alle zusätzliche Rechnungsadressen-Felder

### Components (1/1)
- ✅ action.bar.vue - Speichern, Löschen, Workflow-Menu

## 🎯 Vorteile der neuen Struktur

### 1. Wartbarkeit
- **Kleine Dateien**: Durchschnittlich 50-150 Zeilen statt 2000+
- **Klare Verantwortung**: Jede Komponente hat eine Aufgabe
- **Einfaches Debugging**: Fehler schnell lokalisiert

### 2. Wiederverwendbarkeit
- **Cards** können in anderen Views genutzt werden
- **Forms** sind standalone verwendbar
- **Konsistentes UI** durch gemeinsame Komponenten

### 3. Performance
- **Kleinere Re-Renders**: Vue optimiert besser
- **Lazy Loading möglich**: Tabs können bei Bedarf geladen werden
- **Besseres Tree-Shaking**: Ungenutzter Code wird entfernt

### 4. Testbarkeit
- **Isolierte Tests**: Jede Komponente separat testbar
- **Einfache Mocks**: Props/Events klar definiert
- **Unit-Tests**: Schnell und fokussiert

### 5. Developer Experience
- **Schnelles Auffinden**: Logische Ordnerstruktur
- **Weniger Scrolling**: Kleine Dateien
- **Klare Struktur**: Neue Entwickler finden sich sofort zurecht

## 📋 Komponentenhierarchie

```
customer.edit.view.vue
├── NavbarView (extern)
├── MessagesView (extern)
├── v-tabs
│   └── v-tabs-window
│       ├── billing.tab.vue
│       │   ├── name.address.card.vue
│       │   ├── communication.card.vue
│       │   ├── info.status.card.vue
│       │   ├── numbers.ids.card.vue
│       │   ├── access.data.card.vue
│       │   ├── currency.prices.tax.card.vue
│       │   └── conditions.card.vue
│       ├── additional-billing.tab.vue
│       │   └── billing.address.form.vue (mehrfach)
│       ├── bank.tab.vue
│       ├── shipto.tab.vue
│       │   └── shipto.form.vue (mehrfach)
│       ├── contacts.tab.vue
│       │   └── contact.form.vue (mehrfach)
│       ├── deliveries.tab.vue
│       ├── notes.tab.vue
│       ├── price-rules.tab.vue
│       └── turnover.tab.vue
└── action.bar.vue
```

## 🔧 Verwendete Patterns

### v-model Pattern (Two-Way Binding)
Alle bearbeitbaren Komponenten verwenden das `v-model` Pattern:

```vue
<!-- Parent -->
<name-address-card v-model="customerData" />

<!-- Child Component -->
<script>
const localData = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})
</script>
```

### Event Propagation
Events werden von Child zu Parent weitergereicht:

```vue
<!-- shipto.form.vue -->
<v-btn @click="$emit('remove')">Löschen</v-btn>

<!-- shipto.tab.vue -->
<shipto-form @remove="removeShipto(index)" />
```

### Props Drilling (kontrolliert)
Dropdown-Options werden durch die Hierarchie gereicht:

```vue
<!-- customer.edit.view.vue -->
<billing-tab
  :currencies="currencies"
  :taxzones="taxzones"
/>

<!-- billing.tab.vue -->
<currency-prices-tax-card
  :currencies="currencies"
  :taxzones="taxzones"
/>
```

## 📱 Responsive Design

Alle Komponenten sind responsive:

- **Mobile-first Ansatz**
- **Vuetify Breakpoints**: `cols="12" sm="6" md="4" lg="3"`
- **Responsive Padding**: `pa-2 pa-sm-3`
- **Conditional Display**: `d-none d-sm-inline`
- **Tab-Icons auf Mobile**: Icons statt Text auf kleinen Bildschirmen

## 🚀 Installation & Verwendung

### 1. Dateien kopieren
Kopiere die komplette `customer-edit-components/` Struktur in dein Projekt:
```
src/views/customers/
└── customer-edit-components/
```

### 2. Router anpassen
```javascript
{
  path: '/customer/:id',
  name: 'customer-edit',
  component: () => import('@/views/customers/customer-edit-components/customer.edit.view.vue'),
  props: true
}
```

### 3. Imports prüfen
Die Haupt-Komponente importiert:
```javascript
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import MessagesView from '@/core/components/messages/messages.view.vue'
```

Passe die Pfade an deine Projektstruktur an.

### 4. i18n Keys
Stelle sicher, dass alle Translation-Keys vorhanden sind:
```javascript
'CustomerEditView.tabs.billing'
'CustomerEditView.fields.name'
'CustomerEditView.actions.save'
// etc.
```

## 🎨 Styling

### Globale Styles
Die Card-Header verwenden:
```css
.bg-grey-lighten-4 {
  background-color: #f5f5f5;
}
```

### Vuetify Theme
Alle Komponenten nutzen das konfigurierte Vuetify Theme:
- Primary Color für Buttons und Badges
- Standard Elevation und Borders
- Consistent Spacing mit Vuetify's spacing system

## 🧪 Testing (Empfehlungen)

### Unit Tests
Jede Komponente kann isoliert getestet werden:

```javascript
import { mount } from '@vue/test-utils'
import NameAddressCard from '@/views/customers/customer-edit-components/cards/name.address.card.vue'

describe('NameAddressCard', () => {
  it('emits update:modelValue when field changes', async () => {
    const wrapper = mount(NameAddressCard, {
      props: { modelValue: { name: 'Test' } }
    })

    await wrapper.find('input').setValue('New Name')

    expect(wrapper.emitted('update:modelValue')).toBeTruthy()
  })
})
```

### Integration Tests
Teste Tab-Komponenten mit ihren Child-Komponenten:

```javascript
describe('BillingTab', () => {
  it('renders all cards', () => {
    const wrapper = mount(BillingTab, {
      props: { /* ... */ }
    })

    expect(wrapper.findComponent(NameAddressCard).exists()).toBe(true)
    expect(wrapper.findComponent(CommunicationCard).exists()).toBe(true)
    // ...
  })
})
```

## 🔮 Erweiterungen

### Neue Card hinzufügen
1. Erstelle `cards/new.card.vue`
2. Folge dem bestehenden Pattern (siehe name.address.card.vue)
3. Importiere in `tabs/billing.tab.vue`
4. Füge zum Template hinzu

### Neuen Tab implementieren
1. Erstelle `tabs/new.tab.vue`
2. Importiere in `customer.edit.view.vue`
3. Füge zum `v-tabs` und `v-tabs-window` hinzu
4. Füge Translation-Keys hinzu

### Neue Form-Komponente
1. Erstelle `forms/new.form.vue`
2. Verwende v-model Pattern
3. Emitte 'remove' Event
4. Nutze in entsprechendem Tab

## ⚠️ Wichtige Hinweise

### Props vs. Events
- **Props down**: Daten fließen von Parent zu Child
- **Events up**: Änderungen werden nach oben kommuniziert
- **v-model**: Syntactic sugar für :modelValue + @update:modelValue

### Reactivity
- **Deep Copy** in main component: `JSON.parse(JSON.stringify(data))`
- **Computed** in Child-Komponenten für v-model
- **Arrays**: Immer neue Arrays erstellen (`[...array, item]`)

### Performance
- **Expansion Panels**: Lazy rendering von Panel-Inhalten
- **v-show vs. v-if**: v-show für häufige Toggles
- **Key Attribute**: Immer eindeutige Keys bei v-for

## 📞 Support

Bei Fragen oder Problemen:
1. Prüfe die Konsole auf Fehler
2. Überprüfe Import-Pfade
3. Stelle sicher, dass alle Props korrekt durchgereicht werden
4. Prüfe i18n Translation-Keys

## 📄 Lizenz

Anpassbar für dein Projekt.

---

**Status**: ✅ Komplett implementiert (21/21 Komponenten)
**Letzte Aktualisierung**: 2025-11-21
**Dateinamenskonvention**: Kleinbuchstaben mit Punkten (z.B. `name.address.card.vue`)
