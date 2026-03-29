<!-- src/core/components/navbar/info-bar.component.vue -->
<template>
  <v-slide-y-transition>
    <v-sheet
      v-if="hasItems"
      class="info-bar d-flex align-center px-3 py-1 ga-2"
      elevation="2"
    >
      <!-- Chronologische Ereignisse -->
      <v-chip
        v-for="item in chronologicalItems"
        :key="item.id"
        :color="chipColor(item)"
        variant="elevated"
        size="small"
        closable
        label
        class="info-chip cursor-pointer"
        @click="openItem(item)"
        @click:close="dismissItem(item.type, item.dismissId)"
      >
        <v-icon start size="14">{{ chipIcon(item) }}</v-icon>
        <span class="info-chip-name font-weight-medium text-truncate">
          {{ item.name || t('InfoBar.unknownCaller') }}
        </span>
        <span class="text-caption ms-1 opacity-80 flex-shrink-0">({{ formatDateTime(item.timestamp) }})</span>
      </v-chip>

      <!-- Ersatzteil-Anfragen: Pro Auftrag ein Chip -->
      <v-chip
        v-for="pr in pendingPartsRequests"
        :key="'pr-' + pr.oe_id"
        color="red"
        variant="elevated"
        size="small"
        closable
        label
        class="info-chip cursor-pointer"
        @click="openOrderWithParts(pr)"
        @click:close="dismissItem('parts', pr.oe_id)"
      >
        <v-icon start size="14">mdi-cart-arrow-down</v-icon>
        <span class="info-chip-name font-weight-medium text-truncate">
          {{ pr.customer_name || pr.ordnumber || '#' + pr.oe_id }}
        </span>
        <v-badge :content="pr.pending_count" color="white" text-color="red" inline class="ml-1" />
      </v-chip>

      <v-spacer />
    </v-sheet>
  </v-slide-y-transition>
</template>

<script>
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
      chronologicalItems, pendingPartsRequests, hasItems,
      dismissItem
    } = useInfoBar()

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
      if (item.type === 'call') return item.direction === 'E' ? 'green-darken-1' : 'blue-darken-1'
      if (item.type === 'email') return 'deep-purple-darken-1'
      return 'green-darken-2'
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

    return {
      t,
      chronologicalItems,
      pendingPartsRequests,
      hasItems,
      dismissItem,
      formatDateTime,
      chipColor,
      chipIcon,
      openItem,
      openOrderWithParts
    }
  }
}
</script>

<style scoped>
.info-bar {
  background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
  border-left: 3px solid #1976d2;
  overflow: hidden;
  white-space: nowrap;
  flex-wrap: nowrap;
  min-height: 40px;
  position: sticky;
  top: 48px;
  z-index: 1004;
}
.info-chip {
  width: 220px;
  min-width: 220px;
  max-width: 220px;
}
.info-chip-name {
  max-width: 140px;
  display: inline-block;
  vertical-align: middle;
}
.info-chip :deep(.v-chip__close) {
  color: white !important;
  opacity: 0.9;
}
.cursor-pointer {
  cursor: pointer;
}
</style>
