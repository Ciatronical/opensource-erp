<!-- src/core/views/startup/startup.view.vue -->

<template>
  <NavbarView :message="message" :messages="messages" />

  <CrmView v-if="crmView" />
  <v-container class="pt-5" v-else>
    <v-row justify="center" align="stretch" class="text-center">
      <v-col cols="12" sm="8" md="8" lg="8" xl="3" v-for="card in cards" :key="card.title">
        <v-card variant="outlined" elevation="1" class="d-flex flex-column h-100">
          <v-card-title class="py-3 bg-secondary text-white">
            <h4 class="m-0">{{ card.title }}</h4>
          </v-card-title>
          <v-card-text class="flex-grow-1">
            <v-list density="comfortable">
              <template v-for="(item, i) in card.items" :key="i">
                <v-divider v-if="item === '-'" class="my-2" />
                <v-list-item v-else :title="item.title" :to="item.to" :link="!!item.to" />
              </template>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script>
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import CrmView from '@/core/components/crmview/crm.view.vue'
import { ref } from 'vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useNavigationCards } from '@/core/composables/navigation.cards.js'

export default {
  name: 'StartupView',
  components: { NavbarView, CrmView },

  props: {
    // alte API (einzelne Nachricht)
    message: {
      type: Object,
      default: () => ({ title: '', description: '', type: 'info' })
    },
    // neue API (mehrere Nachrichten)
    messages: {
      type: Array,
      default: () => []
    },
    // ob die Ansicht für Kunden/Lieferanten genutzt wird
    crmView: {
      type: Boolean,
      default: false
    },
    // optionale ID (z.B. Kunden-/Lieferanten-ID)
    id: {
      type: Number,
      default: null
    },
    // C = Kunde, V = Lieferant
    src: {
      type: String,
      default: 'C'
    }
  },

  setup(props) {
    const id = ref(props.id)
    const oserp = oserpStore()
    const { cards } = useNavigationCards()

    if(null !== id.value) {
      oserp.fetchCustomerOrVendor(id.value, props.src)
    } else if (props.crmView) {
      // CRM-Ansicht ohne explizite ID (z.B. nach router.back() von Faktura/Fahrzeug):
      // Aktuellen Kunden/Lieferanten neu laden, damit neue Dokumente/Fahrzeuge sichtbar sind.
      const profile = oserp.customer_vendor?.profile
      if (profile?.id) {
        oserp.fetchCustomerOrVendor(profile.id, profile.src || 'C')
      }
    }

    return { cards }
  }
}
</script>