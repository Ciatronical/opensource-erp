// Globaler WhatsApp Media-Cache — ueberlebt Komponentenwechsel innerhalb der SPA-Session
// Wird von whatsapp.view.vue und whatsapp.tab.vue gemeinsam genutzt

import { reactive } from 'vue'

export const waMediaCache = reactive({})
