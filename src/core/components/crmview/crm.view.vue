<!-- src/components/crmview/crm.view.vue -->

<template>
  <v-container class="pt-5" fluid>
    <v-row v-if="!oserp.customer_vendor" justify="center" class="pt-10">
      <v-col cols="12" sm="8" md="6" lg="4">
        <v-alert type="info" variant="tonal" prominent>
          <template #prepend>
            <v-icon size="large">mdi-account-plus</v-icon>
          </template>
          {{ $t('CrmView.noCustomerVendor') }}
        </v-alert>
      </v-col>
    </v-row>
    <v-row v-else align="stretch">
      <!-- Kontaktdaten (mit vertikalen Tabs) -->
      <v-col cols="12" md="6">
        <v-card variant="outlined" rounded="lg" class="d-flex flex-column h-100">
          <v-card-title class="d-flex align-center bg-grey-lighten-4 py-3">
            <v-icon color="primary" class="me-2">mdi-account-box</v-icon>
            {{ $t('CrmView.contactData') }}
            <v-spacer />
            <v-btn
              v-if="editRoute"
              icon
              size="small"
              variant="text"
              color="primary"
              :title="$t('CrmView.edit')"
              @click="goToEdit"
            >
              <v-icon>mdi-pencil</v-icon>
            </v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text class="flex-grow-1 pa-0">
            <CrmCustomerVendorDetailsView />
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Vorgaenge -->
      <v-col cols="12" md="6">
        <v-card variant="outlined" rounded="lg" class="d-flex flex-column h-100">
          <v-card-title class="d-flex align-center bg-grey-lighten-4 py-3">
            <v-icon color="primary" class="me-2">mdi-file-document-multiple</v-icon>
            {{ $t('CrmView.occurrences') }}
            <v-spacer />
            <v-text-field
              v-model="occurrenceFilter"
              :placeholder="$t('CrmView.filterPlaceholder')"
              prepend-inner-icon="mdi-magnify"
              variant="solo-filled"
              density="compact"
              flat
              hide-details
              clearable
              single-line
              style="max-width: 240px;"
            />
          </v-card-title>
          <v-divider />
          <v-card-text class="flex-grow-1 pa-0">
            <OccurrenceView :search-text="occurrenceFilter" />
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Fahrzeuge (nur für Kunden) -->
      <v-col v-if="oserp.isLxCars() && isCustomer" cols="12" md="6">
        <v-card variant="outlined" rounded="lg" class="d-flex flex-column h-100">
          <v-card-title class="d-flex align-center bg-grey-lighten-4 py-3">
            <v-icon color="primary" class="me-2">mdi-car</v-icon>
            {{ $t('CrmView.vehicles') }}
            <v-spacer />
            <v-btn
              size="small"
              variant="tonal"
              color="primary"
              prepend-icon="mdi-plus"
              @click="router.push({ name: 'fahrzeug-neu' })"
            >
              {{ $t('CrmView.newVehicle') }}
            </v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text class="flex-grow-1">
            <CarsView />
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Kontakthistorie -->
      <v-col cols="12" md="6">
        <v-card variant="outlined" rounded="lg" class="d-flex flex-column h-100">
          <v-card-title class="d-flex align-center bg-grey-lighten-4 py-3">
            <v-icon color="primary" class="me-2">mdi-history</v-icon>
            {{ $t('CrmView.contactHistory') }}
          </v-card-title>
          <v-divider />
          <v-card-text class="flex-grow-1 pa-0">
            <v-data-table
              :headers="contactHistoryHeaders"
              :items="contactHistory"
              density="compact"
              :items-per-page="10"
              :no-data-text="$t('CrmView.noContactHistory')"
              hover
              class="zebra-table"
            >
              <template #item.call_date="{ item }">
                {{ formatCallDate(item.call_date) }}
              </template>
              <template #item.crmti_direction="{ item }">
                <v-chip
                  :color="item.crmti_direction === 'E' ? 'success' : 'info'"
                  size="small"
                  variant="tonal"
                >
                  <v-icon start size="small">
                    {{ item.crmti_direction === 'E' ? 'mdi-phone-incoming' : 'mdi-phone-outgoing' }}
                  </v-icon>
                  {{ item.crmti_direction === 'E' ? $t('CrmView.inbound') : $t('CrmView.outbound') }}
                </v-chip>
              </template>
              <template #item.actions="{ item }">
                <v-btn
                  icon
                  size="small"
                  variant="text"
                  color="primary"
                  :title="$t('CrmView.playRecording')"
                  @click="playPhoneCall(item.unique_call_id)"
                >
                  <v-icon>mdi-play-circle</v-icon>
                </v-btn>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script>
import { ref, computed, onActivated, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'
import OccurrenceView from '@/core/components/crmview/occurrence.view.vue'
import CrmCustomerVendorDetailsView from '@/core/components/crmview/cvdetails.view.vue'
import CarsView from '@/core/components/crmview/cars.view.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toast from '@/core/utils/toasts.js'

export default {
  name: 'CrmView',
  components: { OccurrenceView, CrmCustomerVendorDetailsView, CarsView },
  setup() {
    const oserp = oserpStore();
    const router = useRouter();
    const { t } = useI18n();

    // Volltextfilter fuer die Vorgangs-Tabs (rechts neben dem Titel)
    const occurrenceFilter = ref('');

    // Kundendaten beim Aktivieren aktualisieren (nur wirksam innerhalb <keep-alive>).
    // Ohne <keep-alive> uebernimmt StartupView.setup() den Refresh (auch ohne id-Prop).
    // NICHT bei onMounted: StartupView.setup() ruft fetchCustomerOrVendor bereits auf.
    // onMounted wuerde mit alten Store-Daten eine Race Condition ausloesen.
    function refreshCustomerData() {
      const profile = oserp.customer_vendor?.profile
      if (profile?.id) {
        oserp.fetchCustomerOrVendor(profile.id, profile.src || 'C')
      }
    }
    onActivated(refreshCustomerData)

    const contactHistoryHeaders = [
      { title: t('CrmView.date'), key: 'call_date', sortable: true },
      { title: t('CrmView.direction'), key: 'crmti_direction', sortable: true },
      { title: t('CrmView.from'), key: 'crmti_src', sortable: true },
      { title: t('CrmView.to'), key: 'crmti_dst', sortable: true },
      { title: t('CrmView.number'), key: 'crmti_number', sortable: true },
      { title: '', key: 'actions', sortable: false, align: 'center', width: '60px' },
    ];

    const contactHistory = computed(() => {
      return oserp.customer_vendor?.contact_history ?? [];
    });

    function formatCallDate(callDate) {
      if (!callDate) return '';
      const ts = typeof callDate === 'object' ? callDate.parsedValue : callDate;
      return new Date(ts).toLocaleString();
    }

    async function playPhoneCall(uniqueCallId) {
      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'playPhoneCall',
          unique_call_id: uniqueCallId,
        });
        const data = response.data;
        if (!data.success) {
          toast.error(t('CrmView.fileNotFound'));
          return;
        }
        window.open('/api/customer_vendor/monitor/' + data.payload.filename, '_blank');
      } catch {
        toast.error(t('CrmView.connectionError'));
      }
    }

    const isCustomer = computed(() => oserp.customer_vendor?.profile?.src !== 'V')

    const editRoute = computed(() => {
      const profile = oserp.customer_vendor?.profile
      if (!profile?.id) return null
      const routeName = profile.src === 'V' ? 'vendor-edit' : 'customer-edit'
      return { name: routeName, params: { id: profile.id } }
    })

    function goToEdit() {
      if (editRoute.value) router.push(editRoute.value)
    }

    return { oserp, router, occurrenceFilter, isCustomer, contactHistoryHeaders, contactHistory, formatCallDate, playPhoneCall, editRoute, goToEdit };
  }
}
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
  background-color: rgba(0, 0, 0, 0.03);
}
</style>
