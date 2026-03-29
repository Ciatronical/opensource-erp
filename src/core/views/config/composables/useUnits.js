// src/core/composables/useUnits.js

/**
 * Composable für Unit-Verwaltung und Konvertierung
 * Nachbau von kivitendo's $unit->convertible_units Logik
 */

/**
 * Findet alle Units die zu einer gegebenen Unit konvertierbar sind
 * @param {Array} allUnits - Alle verfügbaren Units
 * @param {String} unitName - Name der Basis-Unit (z.B. "kg")
 * @returns {Array} - Array von konvertierbaren Units
 */
export function getConvertibleUnits(allUnits, unitName) {
    if (!allUnits || !unitName) return [];
    
    // Finde die Basis-Unit
    const baseUnit = allUnits.find(u => u.name === unitName);
    if (!baseUnit) return [];
    
    const convertibleUnits = [baseUnit];
    
    // Finde alle Units die direkt oder indirekt konvertierbar sind
    // 1. Units die diese Unit als base_unit haben (Kinder)
    const findChildren = (unit) => {
        const children = allUnits.filter(u => u.base_unit === unit.name);
        children.forEach(child => {
            if (!convertibleUnits.find(u => u.id === child.id)) {
                convertibleUnits.push(child);
                findChildren(child); // Rekursiv für Enkel, Urenkel, etc.
            }
        });
    };
    
    // 2. Units von denen diese Unit abstammt (Eltern, Großeltern, etc.)
    const findParents = (unit) => {
        if (unit.base_unit) {
            const parent = allUnits.find(u => u.name === unit.base_unit);
            if (parent && !convertibleUnits.find(u => u.id === parent.id)) {
                convertibleUnits.push(parent);
                findParents(parent); // Rekursiv nach oben
                findChildren(parent); // Auch Geschwister hinzufügen
            }
        }
    };
    
    findChildren(baseUnit);
    findParents(baseUnit);
    
    // Sortiere nach sortkey
    return convertibleUnits.sort((a, b) => (a.sortkey || 0) - (b.sortkey || 0));
}

/**
 * Holt alle Gewichtseinheiten (konvertierbar zu "kg")
 * @param {Array} allUnits - Alle verfügbaren Units
 * @returns {Array} - Array von Gewichtseinheiten
 */
export function getWeightUnits(allUnits) {
    return getConvertibleUnits(allUnits, 'kg');
}

/**
 * Holt alle Zeit-Einheiten (konvertierbar zu "Std" oder "h")
 * @param {Array} allUnits - Alle verfügbaren Units
 * @returns {Array} - Array von Zeit-Einheiten
 */
export function getTimeUnits(allUnits) {
    // Versuche erst "Std", dann "h", dann "Stunde"
    const timeUnitNames = ['Std', 'h', 'Stunde'];
    for (const name of timeUnitNames) {
        const units = getConvertibleUnits(allUnits, name);
        if (units.length > 0) return units;
    }
    return [];
}

/**
 * Holt alle Volumen-Einheiten (konvertierbar zu "L")
 * @param {Array} allUnits - Alle verfügbaren Units
 * @returns {Array} - Array von Volumen-Einheiten
 */
export function getVolumeUnits(allUnits) {
    return getConvertibleUnits(allUnits, 'L');
}

/**
 * Berechnet Umrechnungsfaktor zwischen zwei Units
 * @param {Array} allUnits - Alle verfügbaren Units
 * @param {String} fromUnit - Von-Unit Name
 * @param {String} toUnit - Zu-Unit Name
 * @returns {Number|null} - Umrechnungsfaktor oder null wenn nicht konvertierbar
 */
export function getConversionFactor(allUnits, fromUnit, toUnit) {
    if (!allUnits || !fromUnit || !toUnit) return null;
    if (fromUnit === toUnit) return 1;
    
    const from = allUnits.find(u => u.name === fromUnit);
    const to = allUnits.find(u => u.name === toUnit);
    
    if (!from || !to) return null;
    
    // Prüfe ob konvertierbar
    const convertible = getConvertibleUnits(allUnits, fromUnit);
    if (!convertible.find(u => u.name === toUnit)) return null;
    
    // Berechne Faktoren zur Basis-Unit
    const getFactorToBase = (unit) => {
        let factor = 1;
        let current = unit;
        while (current.base_unit) {
            factor *= current.factor || 1;
            current = allUnits.find(u => u.name === current.base_unit);
            if (!current) break;
        }
        return factor;
    };
    
    const fromFactor = getFactorToBase(from);
    const toFactor = getFactorToBase(to);
    
    return fromFactor / toFactor;
}

/**
 * Beispiel-Nutzung in einer Vue-Komponente:
 * 
 * import { getWeightUnits } from '@/core/composables/useUnits';
 * import { computed } from 'vue';
 * import { oserpStore } from '@/core/stores/oserp.store.js';
 * 
 * const store = oserpStore();
 * 
 * const weightUnits = computed(() => {
 *     if (store.session?.company_config?.units) {
 *         return getWeightUnits(store.session.company_config.units);
 *     }
 *     return [];
 * });
 */
