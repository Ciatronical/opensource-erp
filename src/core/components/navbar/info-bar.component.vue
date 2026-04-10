<!-- src/core/components/navbar/info-bar.component.vue -->
<template>
  <v-slide-y-transition>
    <v-sheet
      v-if="hasItems"
      class="info-bar"
      elevation="2"
    >
      <!-- Ersatzteil-Anfragen: Warenkorb + Kundenname (Dismiss gilt nur für heute) -->
      <v-chip
        v-for="pr in visiblePartsRequests"
        :key="'pr-' + pr.oe_id"
        color="deep-orange-darken-2"
        variant="elevated"
        size="small"
        closable
        label
        class="info-chip cursor-pointer"
        :title="(pr.customer_name || pr.ordnumber || '#' + pr.oe_id) + ' (' + pr.pending_count + ')'"
        @click="openOrderWithParts(pr)"
        @click:close="dismissItem('parts', pr.oe_id)"
      >
        <v-icon start size="14">mdi-cart-arrow-down</v-icon>
        <span class="info-chip-text font-weight-medium">
          {{ truncate(pr.customer_name || pr.ordnumber || '#' + pr.oe_id, 18) }}
        </span>
      </v-chip>

      <!-- ANPR: Erkannte Fahrzeuge an der Zufahrt -->
      <v-chip
        v-for="det in visibleAnprDetections"
        :key="'anpr-' + det.id"
        color="indigo-darken-2"
        variant="elevated"
        size="small"
        closable
        label
        class="info-chip cursor-pointer"
        :title="det.c_ln + (det.customer_name ? ' — ' + det.customer_name : '') + ' (' + formatAnprTime(det.detected_at) + ')'"
        @click="openAnprDetection(det)"
        @click:close="dismissItem('anpr', det.id)"
      >
        <v-icon start size="14">mdi-car-side</v-icon>
        <span class="info-chip-text font-weight-medium">
          {{ det.c_ln }}
        </span>
      </v-chip>

      <!-- Chronologische Ereignisse (füllen restliche Slots) -->
      <v-chip
        v-for="item in visibleChronologicalItems"
        :key="item.id"
        :color="chipColor(item)"
        variant="elevated"
        size="small"
        closable
        label
        class="info-chip cursor-pointer"
        :title="(item.name || t('InfoBar.unknownCaller')) + ' — ' + formatDateTime(item.timestamp)"
        @click="openItem(item)"
        @click:close="dismissItem(item.type, item.dismissId)"
      >
        <v-icon start size="14">{{ chipIcon(item) }}</v-icon>
        <span class="info-chip-text font-weight-medium">
          {{ truncate(item.name || t('InfoBar.unknownCaller'), 18) }}
        </span>
      </v-chip>
    </v-sheet>
  </v-slide-y-transition>
</template>

<script>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useInfoBar } from '@/core/composables/useInfoBar'

const WEEKDAYS_DE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa']
const WEEKDAYS_EN = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

export default {
  name: 'InfoBarComponent',
  setup() {
    const { t, locale } = useI18n()
    const router = useRouter()
    const {
      chronologicalItems, pendingPartsRequests, anprDetections, hasItems,
      dismissItem
    } = useInfoBar()

    const MAX_TOTAL = 9

    // Ersatzteilanforderungen haben Prioritaet — werden zuerst angezeigt
    const visiblePartsRequests = computed(() =>
      pendingPartsRequests.value.slice(0, MAX_TOTAL)
    )

    // ANPR-Erkennungen haben zweite Prioritaet
    const visibleAnprDetections = computed(() => {
      const remaining = Math.max(0, MAX_TOTAL - visiblePartsRequests.value.length)
      return anprDetections.value.slice(0, remaining)
    })

    // Chronologische Items fuellen die restlichen Plaetze
    const visibleChronologicalItems = computed(() => {
      const remaining = Math.max(0, MAX_TOTAL - visiblePartsRequests.value.length - visibleAnprDetections.value.length)
      return chronologicalItems.value.slice(0, remaining)
    })

    // Anzeigetext sicher kürzen, damit Close-Button immer Platz hat
    function truncate(str, max) {
      if (!str) return ''
      return str.length > max ? str.substring(0, max) + '…' : str
    }

    function formatDateTime(epochMs) {
      if (!epochMs) return ''
      const d = new Date(epochMs)
      const now = new Date()
      const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })

      const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
      const yesterday = new Date(today)
      yesterday.setDate(yesterday.getDate() - 1)
      const itemDay = new Date(d.getFullYear(), d.getMonth(), d.getDate())

      if (itemDay.getTime() === today.getTime()) {
        return time
      }
      if (itemDay.getTime() === yesterday.getTime()) {
        return t('InfoBar.yesterday') + ' ' + time
      }
      const weekdays = locale.value === 'en' ? WEEKDAYS_EN : WEEKDAYS_DE
      return weekdays[d.getDay()] + ' ' + time
    }

    function chipColor(item) {
      if (item.type === 'call') return item.direction === 'E' ? 'teal-darken-2' : 'blue-grey-darken-3'
      if (item.type === 'email') return 'deep-purple-darken-2'
      return 'green-darken-3'
    }

    function chipIcon(item) {
      if (item.type === 'call') return item.direction === 'E' ? 'mdi-phone-incoming' : 'mdi-phone-outgoing'
      if (item.type === 'email') return 'mdi-email-outline'
      return 'mdi-whatsapp'
    }

    function openItem(item) {
      if (item.type === 'call') openCall(item.data)
      else if (item.type === 'email') openEmail(item.data)
      else if (item.type === 'whatsapp') openWhatsapp(item.data)
    }

    function openCall(call) {
      if (call.crmti_caller_id) {
        const routeName = call.crmti_caller_typ === 'V' ? 'change-vendor' : 'change-customer'
        router.push({ name: routeName, params: { id: call.crmti_caller_id } })
      } else {
        router.push({ name: 'call-history' })
      }
    }

    function openEmail(email) {
      if (email?.customer_id) {
        router.push({ name: 'change-customer', params: { id: email.customer_id }, query: { tab: 'emails' } })
      } else {
        router.push({ name: 'emails' })
      }
    }

    function openWhatsapp(wa) {
      if (wa.customer_id) {
        const routeName = wa.src === 'V' ? 'change-vendor' : 'change-customer'
        router.push({ name: routeName, params: { id: wa.customer_id }, query: { tab: 'whatsapp' } })
      } else {
        router.push({ name: 'whatsapp' })
      }
    }

    function openOrderWithParts(pr) {
      router.push({ name: 'faktura-order-view', params: { id: pr.oe_id }, query: { focusParts: '1' } })
    }

    function openAnprDetection(det) {
      if (det.vehicle_id) {
        router.push({ name: 'car-edit', params: { id: det.vehicle_id } })
      } else if (det.customer_id) {
        router.push({ name: 'change-customer', params: { id: det.customer_id } })
      }
    }

    function formatAnprTime(dateStr) {
      if (!dateStr) return ''
      const d = new Date(dateStr)
      return d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
    }

    return {
      t,
      visiblePartsRequests,
      visibleAnprDetections,
      visibleChronologicalItems,
      hasItems,
      dismissItem,
      truncate,
      formatDateTime,
      formatAnprTime,
      chipColor,
      chipIcon,
      openItem,
      openOrderWithParts,
      openAnprDetection
    }
  }
}
</script>

<style scoped>
.info-bar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
  border-left: 3px solid #1976d2;
  min-height: 40px;
  position: sticky;
  top: 48px;
  z-index: 1004;
}

/* Alle Chips gleich groß — teilen sich den verfügbaren Platz gleichmäßig */
.info-chip {
  flex: 1 1 0 !important;
  min-width: 0 !important;
  max-width: 180px;
}

/* Chip-Inhalt: Flex-Layout damit Close-Button garantiert sichtbar bleibt */
.info-chip :deep(.v-chip__content) {
  display: flex;
  align-items: center;
  flex: 1;
  min-width: 0;
  overflow: visible;
}

/* Icons duerfen nicht schrumpfen oder abgeschnitten werden */
.info-chip :deep(.v-icon) {
  flex-shrink: 0 !important;
}

/* Close-Button darf NIEMALS verschwinden oder schrumpfen */
.info-chip :deep(.v-chip__close) {
  color: white !important;
  opacity: 0.9;
  flex-shrink: 0 !important;
  margin-left: 4px;
}

/* Text kürzt mit Ellipsis */
.info-chip-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cursor-pointer {
  cursor: pointer;
}
</style>
