# Mandantenkonfiguration - Mit kivitendo Unit-Logik

Vue.js Komponenten mit **korrekter** Unit-Konvertierung nach kivitendo-Vorbild.

## 🎯 Gewichtseinheiten - Wie kivitendo

### Problem
❌ **FALSCH:** Alle Units mit `type: "dimension"` filtern
```javascript
units.filter(unit => unit.type === 'dimension')  // Liefert AUCH L, ml!
```

### Lösung  
✅ **RICHTIG:** Konvertierbare Units zu "kg" finden (wie kivitendo)
```javascript
import { getWeightUnits } from '@/core/composables/useUnits';

const weightUnits = computed(() => {
    if (store.session?.company_config?.units) {
        return getWeightUnits(store.session.company_config.units);
    }
    return [];
});
```

### Wie kivitendo es macht

**Perl-Code (kivitendo):**
```perl
sub init_all_weightunits { 
    my $unit = SL::DB::Manager::Unit->find_by(name => 'kg'); 
    $unit ? $unit->convertible_units : [] 
}
```

**Vue-Nachbau:**
```javascript
export function getWeightUnits(allUnits) {
    return getConvertibleUnits(allUnits, 'kg');
}
```

## 📊 Unit-Struktur

Units haben eine **Hierarchie**:

```json
[
  {"name":"mg",  "base_unit":null,  "factor":null},     // Basis
  {"name":"g",   "base_unit":"mg",  "factor":1000},     // ×1000 von mg
  {"name":"kg",  "base_unit":"g",   "factor":1000},     // ×1000 von g
  {"name":"t",   "base_unit":"kg",  "factor":1000}      // ×1000 von kg
]
```

**Konvertierbare Units zu "kg":**
- `mg` (Basis der Kette)
- `g` (Eltern von kg)
- `kg` (selbst)
- `t` (Kind von kg)

**NICHT konvertierbar zu "kg":**
- `L` (Liter - andere Kette)
- `ml` (Milliliter - andere Kette)
- `Stck` (Stück - keine Kette)

## 🔧 Composable: useUnits.js

### Funktionen

```javascript
// Gewichtseinheiten (konvertierbar zu "kg")
getWeightUnits(allUnits)

// Zeit-Einheiten (konvertierbar zu "Std")
getTimeUnits(allUnits)

// Volumen-Einheiten (konvertierbar zu "L")
getVolumeUnits(allUnits)

// Allgemein: Konvertierbare Units
getConvertibleUnits(allUnits, 'kg')

// Umrechnungsfaktor berechnen
getConversionFactor(allUnits, 'kg', 't')  // Returns: 0.001
```

### Verwendung in Komponenten

```javascript
import { getWeightUnits } from '@/core/composables/useUnits';
import { computed } from 'vue';
import { oserpStore } from '@/core/stores/oserp.store.js';

const store = oserpStore();

const weightUnits = computed(() => {
    if (store.session?.company_config?.units) {
        return getWeightUnits(store.session.company_config.units);
    }
    return [];
});

// In Template verwenden
<v-select
    v-model="defaults.weightunit"
    :items="weightUnits"
    item-title="name"
    item-value="name"
/>
```

## 📁 Struktur

```
client-config-complete/
├── client-defaults.view.vue
├── composables/
│   └── useUnits.js           ✨ NEU: Unit-Konvertierung
├── tabs/
│   ├── miscellaneous.tab.vue  ✅ Nutzt getWeightUnits()
│   └── ... (10 weitere Tabs)
├── locales/
└── backend-examples/
```

## 🚀 Installation

1. Kopiere `composables/useUnits.js` → `src/core/composables/`
2. Kopiere `client-defaults.view.vue` → `src/core/views/config/`
3. Kopiere `tabs/` → `src/core/views/config/tabs/`
4. Integriere Übersetzungen

## 💡 Beispiele

### Gewichtseinheiten-Dropdown

```javascript
// Tab-Komponente
import { getWeightUnits } from '@/core/composables/useUnits';

const weightUnits = computed(() => {
    return getWeightUnits(store.session.company_config.units || []);
});
```

**Ergebnis:** `["mg", "g", "kg", "t"]` (nur Gewichte!)

### Zeit-Einheiten-Dropdown

```javascript
import { getTimeUnits } from '@/core/composables/useUnits';

const timeUnits = computed(() => {
    return getTimeUnits(store.session.company_config.units || []);
});
```

**Ergebnis:** `["min", "Std", "Tag"]`

### Umrechnung

```javascript
import { getConversionFactor } from '@/core/composables/useUnits';

const factor = getConversionFactor(allUnits, 'kg', 't');
console.log(factor);  // 0.001 (1 kg = 0.001 t)

const factor2 = getConversionFactor(allUnits, 't', 'g');
console.log(factor2);  // 1000000 (1 t = 1000000 g)
```

## 🐛 Debugging

```javascript
// In Browser Console
import { getWeightUnits } from '@/core/composables/useUnits';
const { oserpStore } = await import('/src/core/stores/oserp.store.js');
const store = oserpStore();

const weights = getWeightUnits(store.session.company_config.units);
console.log('Gewichtseinheiten:', weights.map(u => u.name));
// Ausgabe: ["mg", "g", "kg", "t"]
```

## ✅ Was ist neu

### v3 → Complete (mit kivitendo-Logik)
- ✅ **useUnits.js** Composable erstellt
- ✅ **getWeightUnits()** - Nachbau von kivitendo
- ✅ **getConvertibleUnits()** - Findet Unit-Hierarchien
- ✅ **getTimeUnits()** - Für Zeit-Einheiten
- ✅ **getVolumeUnits()** - Für Volumen
- ✅ **getConversionFactor()** - Umrechnungen
- ✅ **miscellaneous.tab.vue** nutzt getWeightUnits()

## 📚 Technische Details

### Algorithmus: convertible_units

1. **Finde Basis-Unit** (z.B. "kg")
2. **Finde alle Kinder** (Units mit `base_unit: "kg"`)
   - Rekursiv: Kinder der Kinder, etc.
3. **Finde alle Eltern** (Unit wo `base_unit` drauf zeigt)
   - Rekursiv: Eltern der Eltern, etc.
4. **Finde Geschwister** (Kinder der Eltern)
5. **Sortiere** nach `sortkey`

### Beispiel-Baum

```
           mg (Basis)
            |
          g (×1000)
            |
         kg (×1000) ← Ausgangspunkt
            |
          t (×1000)
```

**Konvertierbar zu kg:** mg, g, kg, t
**Faktor kg→t:** 0.001
**Faktor t→mg:** 1000000

## 🆘 Support

- **Problem:** Falsche Units angezeigt
  - **Lösung:** Prüfe `base_unit` und `factor` in units-Array
  
- **Problem:** Import-Fehler useUnits
  - **Lösung:** Pfad prüfen: `@/core/composables/useUnits`

- **Problem:** Umrechnung falsch
  - **Lösung:** Console-Log der Units-Hierarchie
