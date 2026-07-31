<!-- src/core/views/call-history/call-history.view.vue -->

<template>
  <NavbarView />

  <v-container fluid class="pa-2 pa-md-4">
    <v-row class="mb-4">
      <v-col cols="12">
        <h1 class="text-h5 text-md-h4 d-flex align-center">
          <v-icon class="me-2" color="primary">mdi-phone-log</v-icon>
          {{ t('CallHistoryView.title') }}
        </h1>
      </v-col>
    </v-row>

    <!-- Filter -->
    <v-row class="mb-2">
      <v-col cols="12" sm="6" md="3">
        <v-text-field
          v-model="filterSearch"
          :label="t('CallHistoryView.filterSearch')"
          prepend-inner-icon="mdi-magnify"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          @update:model-value="onFilterChange"
        />
      </v-col>
      <v-col cols="12" sm="6" md="2">
        <v-select
          v-model="filterDirection"
          :label="t('CallHistoryView.filterDirection')"
          :items="directionOptions"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          @update:model-value="onFilterChange"
        />
      </v-col>
      <v-col cols="12" sm="6" md="2">
        <v-text-field
          v-model="filterDateFrom"
          :label="t('CallHistoryView.filterDateFrom')"
          type="date"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          @update:model-value="onFilterChange"
        />
      </v-col>
      <v-col cols="12" sm="6" md="2">
        <v-text-field
          v-model="filterDateTo"
          :label="t('CallHistoryView.filterDateTo')"
          type="date"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          @update:model-value="onFilterChange"
        />
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12">
        <v-card variant="outlined" rounded="lg">
          <v-card-text class="pa-0" style="overflow-x: auto;">
            <v-data-table-server
              :headers="headers"
              :items="callHistory"
              :items-length="totalCount"
              :items-per-page="itemsPerPage"
              :items-per-page-options="[
                { value: 25, title: '25' },
                { value: 50, title: '50' },
                { value: 100, title: '100' },
                { value: 200, title: '200' },
                { value: 500, title: '500' },
                { value: -1, title: t('CallHistoryView.all') },
              ]"
              :page="page"
              :loading="loading"
              density="compact"
              :no-data-text="t('CallHistoryView.noData')"
              :row-props="getRowProps"
              hover
              class="zebra-table"
              @update:page="onPageChange"
              @update:items-per-page="onItemsPerPageChange"
            >
              <template #item.call_date="{ item }">
                {{ formatCallDate(item.call_date) }}
              </template>
              <template #item.caller_name="{ item }">
                <div class="d-inline-flex align-center">
                  <a
                    v-if="item.crmti_caller_id && item.crmti_caller_typ !== 'X'"
                    href="#"
                    class="text-decoration-none"
                    @click.prevent="openCustomer(item)"
                  >
                    {{ item.caller_name }}
                  </a>
                  <v-btn
                    icon
                    size="x-small"
                    variant="text"
                    :color="item.crmti_caller_typ === 'X' || !item.crmti_caller_id ? 'warning' : 'grey'"
                    :title="t('CallHistoryView.assignCall')"
                    @click="openAssignDialog(item)"
                  >
                    <v-icon size="small">
                      {{ item.crmti_caller_typ === 'X' || !item.crmti_caller_id ? 'mdi-account-plus' : 'mdi-pencil' }}
                    </v-icon>
                  </v-btn>
                </div>
              </template>
              <template #item.crmti_direction="{ item }">
                <v-chip
                  :color="isCallMissed(item.crmti_status) ? 'error' : (item.crmti_direction === 'E' ? 'success' : 'info')"
                  size="small"
                  variant="tonal"
                  :title="isCallMissed(item.crmti_status) ? t('CallHistoryView.missed') : ''"
                >
                  <v-icon start size="small">
                    {{ directionIcon(item) }}
                  </v-icon>
                  {{ item.crmti_direction === 'E' ? t('CallHistoryView.inbound') : t('CallHistoryView.outbound') }}
                </v-chip>
              </template>
              <template #item.crmti_number="{ item }">
                <span v-if="item.crmti_number" class="text-no-wrap">{{ formatPhone(item.crmti_number) }}</span>
                <span v-else>-</span>
              </template>
              <template #item.actions="{ item }">
                <div class="d-inline-flex align-center">
                  <PhoneActionBar
                    v-if="item.crmti_number"
                    :phone="item.crmti_number"
                    :name="item.caller_name ?? ''"
                    :show-number="false"
                  />
                  <v-btn
                    icon
                    size="x-small"
                    variant="text"
                    color="primary"
                    :title="t('CallHistoryView.playRecording')"
                    @click="playPhoneCall(item.unique_call_id)"
                  >
                    <v-icon size="small">mdi-play-circle</v-icon>
                  </v-btn>
                  <v-btn
                    v-if="item.crmti_caller_id && item.crmti_caller_typ === 'C'"
                    icon
                    size="x-small"
                    variant="text"
                    color="purple"
                    :title="t('CallHistoryView.generateOrder')"
                    :loading="generatingOrderId === item.crmti_id"
                    :disabled="!!generatingOrderId"
                    @click="generateOrderFromCall(item)"
                  >
                    <v-icon size="small">mdi-creation</v-icon>
                  </v-btn>
                </div>
              </template>
            </v-data-table-server>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <AssignCallDialog
      v-model="assignDialogVisible"
      :call="assignDialogCall"
      @assigned="fetchCallHistory"
    />
  </v-container>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PhoneActionBar from '@/core/components/phone-action-bar.vue'
import AssignCallDialog from './assign-call-dialog.vue'
import * as toast from '@/core/utils/toasts.js'
import { formatPhone } from '@/core/utils/phoneFormat.js'
import { isCallMissed } from '@/core/utils/callStatus.js'

export default {
  name: 'CallHistoryView',
  components: { NavbarView, PhoneActionBar, AssignCallDialog },
  setup() {
    const { t } = useI18n();
    const router = useRouter();

    const callHistory = ref([]);
    const totalCount = ref(0);
    const loading = ref(false);
    const page = ref(1);
    const itemsPerPage = ref(25);

    // Auftrag aus Aufnahme generieren
    const generatingOrderId = ref(null);

    // Zuordnungs-Dialog
    const assignDialogVisible = ref(false);
    const assignDialogCall = ref(null);

    function openAssignDialog(item) {
      assignDialogCall.value = item;
      assignDialogVisible.value = true;
    }

    // Filter
    const filterSearch = ref('');
    const filterDirection = ref(null);
    const filterDateFrom = ref(null);
    const filterDateTo = ref(null);
    let filterDebounce = null;

    const directionOptions = [
      { title: t('CallHistoryView.inbound'), value: 'E' },
      { title: t('CallHistoryView.outbound'), value: 'A' },
    ];

    function onFilterChange() {
      clearTimeout(filterDebounce);
      filterDebounce = setTimeout(() => {
        page.value = 1;
        fetchCallHistory();
      }, 400);
    }

    const headers = [
      { title: t('CallHistoryView.date'), key: 'call_date', sortable: false },
      { title: t('CallHistoryView.direction'), key: 'crmti_direction', sortable: false },
      { title: t('CallHistoryView.customerVendor'), key: 'caller_name', sortable: false },
      { title: t('CallHistoryView.from'), key: 'crmti_src', sortable: false },
      { title: t('CallHistoryView.to'), key: 'crmti_dst', sortable: false },
      { title: t('CallHistoryView.number'), key: 'crmti_number', sortable: false, cellProps: { class: 'pr-0' } },
      { title: '', key: 'actions', sortable: false, cellProps: { class: 'pl-0' } },
    ];

    async function fetchCallHistory() {
      loading.value = true;
      try {
        const isAll = itemsPerPage.value === -1;
        const offset = isAll ? 0 : (page.value - 1) * itemsPerPage.value;
        const params = {
          action: 'getAllCallHistory',
          limit: isAll ? -1 : itemsPerPage.value,
          offset: offset,
        };
        if (filterSearch.value) params.search = filterSearch.value;
        if (filterDirection.value) params.direction = filterDirection.value;
        if (filterDateFrom.value) params.date_from = filterDateFrom.value;
        if (filterDateTo.value) params.date_to = filterDateTo.value;
        const response = await axios.post('/api/customer_vendor/', params);
        const main = response.data.payload.main;
        callHistory.value = main.call_history ?? [];
        totalCount.value = main.total_count ?? 0;
      } catch {
        toast.error(t('CallHistoryView.connectionError'));
      } finally {
        loading.value = false;
      }
    }

    function onPageChange(newPage) {
      page.value = newPage;
      fetchCallHistory();
    }

    function onItemsPerPageChange(newPerPage) {
      itemsPerPage.value = newPerPage;
      page.value = 1;
      fetchCallHistory();
    }

    function formatCallDate(callDate) {
      if (!callDate) return '';
      const ts = typeof callDate === 'object' ? callDate.parsedValue : callDate;
      return new Date(ts).toLocaleString();
    }

    // Nicht angenommene Anrufe rot hervorheben (eingehend verpasst / ausgehend nicht erreicht)
    function getRowProps({ item }) {
      return isCallMissed(item.crmti_status) ? { class: 'missed-call' } : {}
    }

    // Verpasst: eingehend = phone-missed, ausgehend nicht erreicht = phone-cancel
    function directionIcon(item) {
      if (isCallMissed(item.crmti_status)) {
        return item.crmti_direction === 'E' ? 'mdi-phone-missed' : 'mdi-phone-cancel'
      }
      return item.crmti_direction === 'E' ? 'mdi-phone-incoming' : 'mdi-phone-outgoing'
    }

    function openCustomer(item) {
      if (item.crmti_caller_id) {
        const routeName = item.crmti_caller_typ === 'V' ? 'change-vendor' : 'change-customer'
        router.push({ name: routeName, params: { id: item.crmti_caller_id } })
      }
    }

    async function playPhoneCall(uniqueCallId) {
      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'playPhoneCall',
          unique_call_id: uniqueCallId,
        });
        const data = response.data;
        if (!data.success) {
          toast.error(t('CallHistoryView.fileNotFound'));
          return;
        }
        window.open('/api/customer_vendor/monitor/' + data.payload.filename, '_blank');
      } catch {
        toast.error(t('CallHistoryView.connectionError'));
      }
    }

    async function generateOrderFromCall(item) {
      generatingOrderId.value = item.crmti_id;
      try {
        const response = await axios.post('/api/customer_vendor/', {
          action: 'generateOrderFromCall',
          unique_call_id: item.unique_call_id,
          customer_id: item.crmti_caller_id,
        }, { timeout: 180000 });
        const data = response.data;
        if (!data.success) {
          const errorMap = {
            MISSING_API_KEYS: 'missingApiKeys',
            FILE_NOT_FOUND: 'noRecording',
            WHISPER_API_ERROR: 'transcriptionFailed',
            CLAUDE_API_ERROR: 'extractionFailed',
          };
          const key = errorMap[data.text] || 'orderError';
          const detail = typeof data.payload === 'string' ? data.payload : '';
          toast.error(t('CallHistoryView.' + key) + (detail ? '\n' + detail : ''));
          return;
        }
        const payload = data.payload;
        toast.success(t('CallHistoryView.orderCreated', { ordnumber: payload.ordnumber }));
        router.push({ name: 'faktura-order-view', params: { id: payload.oe_id } });
      } catch {
        toast.error(t('CallHistoryView.orderError'));
      } finally {
        generatingOrderId.value = null;
      }
    }

    // SSE: Echtzeit-Updates bei neuen Anrufen
    let eventSource = null;

    onMounted(() => {
      fetchCallHistory();
      eventSource = new EventSource('/sse/events');
      eventSource.onmessage = (event) => {
        // Nur bei echten Anruf-Änderungen neu laden. Der SSE-Server liefert
        // alle unbenannten Kanäle (WhatsApp, Kalender, Faktura, Kamera …) über
        // denselben onmessage-Handler; ohne diesen Filter würde jede fremde
        // Notification (v. a. camera_event) die Liste neu laden → Flackern.
        // crmti_change ist am Feld crmti_id erkennbar (ganze Zeile oder
        // {action:'insert', crmti_id}).
        try {
          const data = JSON.parse(event.data);
          if (data.crmti_id === undefined) return;
        } catch { return; }
        if (!loading.value) fetchCallHistory();
      };
    });

    onUnmounted(() => {
      if (eventSource) {
        eventSource.close();
        eventSource = null;
      }
    });

    return {
      t, headers, callHistory, totalCount, loading, isCallMissed,
      getRowProps, directionIcon,
      page, itemsPerPage, formatCallDate, formatPhone, openCustomer,
      playPhoneCall, onPageChange, onItemsPerPageChange,
      filterSearch, filterDirection, filterDateFrom, filterDateTo,
      directionOptions, onFilterChange,
      assignDialogVisible, assignDialogCall, openAssignDialog, fetchCallHistory,
      generatingOrderId, generateOrderFromCall,
    };
  }
}
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
  background-color: rgba(0, 0, 0, 0.03);
}
/* Nicht angenommene Anrufe rot hervorheben (gewinnt gegen Zebra) */
.zebra-table :deep(tbody tr.missed-call),
.zebra-table :deep(tbody tr.missed-call:nth-child(odd)) {
  background-color: rgba(var(--v-theme-error), 0.10);
}
.zebra-table :deep(tbody tr.missed-call:hover) {
  background-color: rgba(var(--v-theme-error), 0.18) !important;
}
.zebra-table :deep(table) {
  width: 100% !important;
}
.zebra-table :deep(th),
.zebra-table :deep(td) {
  padding: 4px 12px !important;
  white-space: nowrap;
}
.zebra-table :deep(.pr-0) {
  padding-right: 0 !important;
}
.zebra-table :deep(.pl-0) {
  padding-left: 0 !important;
}
</style>
