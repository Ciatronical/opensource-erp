<!-- src/core/components/navbar/info-bar.component.vue -->
<template>
  <v-slide-y-transition>
    <v-sheet
      v-if="hasItems"
      class="info-bar"
      elevation="2"
    >
      <!-- Alle Items in einer einzigen, chronologisch sortierten Liste -->
      <v-chip
        v-for="item in visibleItems"
        :key="item.id"
        :color="chipColor(item)"
        variant="tonal"
        size="small"
        closable
        label
        class="info-chip cursor-pointer"
        :title="chipTitle(item)"
        @click="openItem(item)"
        @click:close="dismissItem(item.type, item.dismissId)"
      >
        <v-icon start size="14">{{ chipIcon(item) }}</v-icon>
        <span class="info-chip-text font-weight-medium">
          {{ truncate(chipLabel(item), item.type === 'missing_order' ? 28 : 18) }}
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
import { isCallMissed } from '@/core/utils/callStatus.js'

const WEEKDAYS_DE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa']
const WEEKDAYS_EN = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

export default {
  name: 'InfoBarComponent',
  setup() {
    const { t, locale } = useI18n()
    const router = useRouter()
    const { unifiedItems, hasItems, dismissItem } = useInfoBar()

    const MAX_TOTAL = 9

    // Alle Items zusammen, chronologisch sortiert (neueste zuerst), max. MAX_TOTAL
    const visibleItems = computed(() => unifiedItems.value.slice(0, MAX_TOTAL))

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
      if (item.type === 'completed') return 'success'
      if (item.type === 'parts') return 'deep-orange'
      if (item.type === 'missing_order') return 'orange-darken-3'
      if (item.type === 'anpr') return 'light-blue'
      if (item.type === 'call') {
        if (isCallMissed(item.data?.crmti_status)) return 'red'
        return item.direction === 'E' ? 'teal' : 'blue-grey'
      }
      if (item.type === 'email') return 'deep-purple'
      if (item.type === 'whatsapp') return 'green'
      return 'grey'
    }

    function chipIcon(item) {
      if (item.type === 'completed') return 'mdi-check-circle'
      if (item.type === 'parts') return 'mdi-cart-arrow-down'
      if (item.type === 'missing_order') return 'mdi-clipboard-alert-outline'
      if (item.type === 'anpr') return 'mdi-car-side'
      if (item.type === 'call') {
        if (isCallMissed(item.data?.crmti_status)) return item.direction === 'E' ? 'mdi-phone-missed' : 'mdi-phone-cancel'
        return item.direction === 'E' ? 'mdi-phone-incoming' : 'mdi-phone-outgoing'
      }
      if (item.type === 'email') return 'mdi-email-outline'
      if (item.type === 'whatsapp') return 'mdi-whatsapp'
      return 'mdi-bell-outline'
    }

    function chipLabel(item) {
      if (item.type === 'completed') return (item.name || ('#' + item.data.oe_id)) + ' ✔'
      if (item.type === 'parts') return item.name || ('#' + item.data.oe_id)
      if (item.type === 'missing_order') return (item.name || '?') + ' ' + t('InfoBar.noOrder')
      if (item.type === 'anpr') return item.name || ''
      return item.name || t('InfoBar.unknownCaller')
    }

    function chipTitle(item) {
      if (item.type === 'completed') {
        return (item.data.customer_name || item.data.ordnumber) + ' — alle Arbeiten erledigt'
      }
      if (item.type === 'parts') {
        const base = item.data.customer_name || item.data.ordnumber || ('#' + item.data.oe_id)
        return base + ' (' + item.data.pending_count + ') — ' + formatDateTime(item.timestamp)
      }
      if (item.type === 'anpr') {
        const cust = item.data.customer_name ? ' — ' + item.data.customer_name : ''
        return item.data.c_ln + cust + ' (' + formatDateTime(item.timestamp) + ')'
      }
      if (item.type === 'missing_order') {
        const owner = item.data.owner_name ? ' — ' + item.data.owner_name : ''
        return (item.name || '?') + ' ' + t('InfoBar.noOrder') + owner + ' (' + formatDateTime(item.timestamp) + ')'
      }
      return (item.name || t('InfoBar.unknownCaller')) + ' — ' + formatDateTime(item.timestamp)
    }

    function openItem(item) {
      if (item.type === 'completed') router.push({ name: 'faktura-order-view', params: { id: item.data.oe_id } })
      else if (item.type === 'parts') openOrderWithParts(item.data)
      else if (item.type === 'anpr') openAnprDetection(item.data)
      else if (item.type === 'call') openCall(item.data)
      else if (item.type === 'missing_order') openMissingOrder(item.data)
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

    function openMissingOrder(mo) {
      // Hat die Meldung ein erkanntes Fahrzeug, zur Fahrzeugansicht springen,
      // damit das Büro von dort den fehlenden Auftrag anlegen kann.
      if (mo.c_id) {
        router.push({ name: 'car', params: { id: mo.c_id } })
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
        router.push({ name: 'car', params: { id: det.vehicle_id } })
      } else if (det.customer_id) {
        router.push({ name: 'change-customer', params: { id: det.customer_id } })
      }
    }

    return {
      t,
      visibleItems,
      hasItems,
      dismissItem,
      truncate,
      chipColor,
      chipIcon,
      chipLabel,
      chipTitle,
      openItem
    }
  }
}
</script>

<style scoped>
/*
 * Reihenfolge: neueste Items zuerst im Array (siehe unifiedItems im Composable).
 * Render-Richtung kommt aus dem dir-Attribut der App: in LTR-Sprachen (de/en/...)
 * landet das neueste Item links, in RTL-Sprachen (ar/he/...) automatisch rechts.
 * NIEMALS row-reverse setzen — das wuerde die Auto-RTL-Logik brechen.
 */
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
  opacity: 0.7;
  flex-shrink: 0 !important;
  margin-left: 4px;
}
.info-chip :deep(.v-chip__close:hover) {
  opacity: 1;
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
