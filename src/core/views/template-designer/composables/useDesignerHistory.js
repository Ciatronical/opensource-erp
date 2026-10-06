// src/core/views/template-designer/composables/useDesignerHistory.js
//
// Rückgängig/Wiederholen für den Vorlageneditor: Schnappschüsse des Designs
// als JSON. Zusammenhängende Änderungen (Ziehen eines Bausteins) werden erst
// beim Loslassen als ein Schritt abgelegt, damit "Rückgängig" nicht jeden
// Millimeter einzeln zurückgeht.

import { ref, computed } from 'vue';

const LIMIT = 100;

export function useDesignerHistory() {
    const past = ref([]);
    const future = ref([]);
    let current = null;

    const canUndo = computed(() => past.value.length > 0);
    const canRedo = computed(() => future.value.length > 0);

    /** Setzt den Ausgangszustand (z. B. nach dem Laden oder Wechsel der Belegart) */
    function reset(design) {
        past.value = [];
        future.value = [];
        current = design ? JSON.stringify(design) : null;
    }

    /** Legt den aktuellen Zustand als Schritt ab, wenn er sich geändert hat */
    function commit(design) {
        const next = JSON.stringify(design);
        if (next === current) return;
        if (current !== null) {
            past.value.push(current);
            if (past.value.length > LIMIT) past.value.shift();
        }
        current = next;
        future.value = [];
    }

    function undo() {
        if (!past.value.length) return null;
        future.value.push(current);
        current = past.value.pop();
        return JSON.parse(current);
    }

    function redo() {
        if (!future.value.length) return null;
        past.value.push(current);
        current = future.value.pop();
        return JSON.parse(current);
    }

    return { canUndo, canRedo, reset, commit, undo, redo };
}
