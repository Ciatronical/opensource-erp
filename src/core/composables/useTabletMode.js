// src/core/composables/useTabletMode.js

import { useDisplay } from 'vuetify'
import { computed } from 'vue'

/**
 * Erkennt ob die App im Tablet-Modus laeuft (Bildschirmbreite < 1280px).
 * Im Tablet-Modus werden z.B. Button-Beschriftungen ausgeblendet (nur Icons).
 *
 * @returns {{ isTablet: import('vue').ComputedRef<boolean> }}
 */
export function useTabletMode() {
    const { lgAndUp } = useDisplay()
    const isTablet = computed(() => !lgAndUp.value)
    return { isTablet }
}
