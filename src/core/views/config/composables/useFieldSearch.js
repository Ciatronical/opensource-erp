// src/core/views/config/composables/useFieldSearch.js
//
// Gemeinsame Feld-Suche fuer die Reiter der Firmenkonfiguration.
// Statt die Filter-/Highlight-Logik in jedem Reiter zu kopieren, teilen
// sich alle Reiter dieselbe Implementierung: Treffer werden hervorgehoben,
// Nicht-Treffer ausgeblendet.
//
// Ein Feld ist ein Objekt mit mindestens { key, label } und optional
// { searchTerms: string[] } fuer zusaetzliche Suchbegriffe.

import { computed, unref } from 'vue';

/**
 * @param {Array|Ref} fields  Feld-Definitionen ({ key, label, searchTerms? })
 * @param {Ref<string>} searchQuery  reaktiver Suchbegriff
 * @return {{ filteredFields, isHighlighted, hasQuery }}
 */
export function useFieldSearch(fields, searchQuery) {
    const normalizedQuery = computed(() => (unref(searchQuery) || '').trim().toLowerCase());

    const matches = (field) => {
        const q = normalizedQuery.value;
        if (!q) return false;
        if ((field.label || '').toLowerCase().includes(q)) return true;
        if ((field.key || '').toLowerCase().includes(q)) return true;
        if ((field.searchTerms || []).some(term => term.toLowerCase().includes(q))) return true;
        return false;
    };

    const filteredFields = computed(() => {
        const list = unref(fields);
        if (!normalizedQuery.value) return list;
        return list.filter(matches);
    });

    const isHighlighted = (field) => matches(field);

    const hasQuery = computed(() => normalizedQuery.value.length > 0);

    return { filteredFields, isHighlighted, hasQuery };
}
