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

      <!-- Kontakthistorie: Anrufe, WhatsApp und E-Mails chronologisch -->
      <v-col cols="12" md="6">
        <v-card variant="outlined" rounded="lg" class="d-flex flex-column h-100">
          <v-card-title class="d-flex align-center bg-grey-lighten-4 py-3">
            <v-icon color="primary" class="me-2">mdi-history</v-icon>
            {{ $t('CrmView.contactHistory') }}
            <v-spacer />
            <!-- Filter nach Kontaktart, mit Anzahl je Art -->
            <v-btn-toggle
              v-model="historyFilter"
              density="compact"
              variant="outlined"
              color="primary"
              divided
              mandatory
              class="me-2"
            >
              <v-btn value="all" size="small" :title="$t('CrmView.all')">
                {{ $t('CrmView.all') }}
              </v-btn>
              <v-btn value="call" size="small" :title="$t('CrmView.call')">
                <v-icon start size="small">mdi-phone</v-icon>{{ historyCounts.call }}
              </v-btn>
              <v-btn value="whatsapp" size="small" :title="$t('CrmView.whatsapp')">
                <v-icon start size="small">mdi-whatsapp</v-icon>{{ historyCounts.whatsapp }}
              </v-btn>
              <v-btn
                value="email"
                size="small"
                :disabled="emailsNotConfigured"
                :title="emailsNotConfigured ? $t('CrmView.emailsNotConfigured') : $t('CrmView.email')"
              >
                <v-icon start size="small">mdi-email-outline</v-icon>{{ historyCounts.email }}
              </v-btn>
            </v-btn-toggle>
            <v-btn
              icon
              size="small"
              variant="text"
              color="primary"
              :loading="emailsLoading"
              :title="$t('CrmView.refresh')"
              @click="refreshHistory"
            >
              <v-icon>mdi-refresh</v-icon>
            </v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text class="flex-grow-1 pa-0">
            <v-data-table
              :headers="contactHistoryHeaders"
              :items="filteredHistory"
              item-value="key"
              density="compact"
              :items-per-page="10"
              :sort-by="[{ key: 'ts', order: 'desc' }]"
              :no-data-text="$t('CrmView.noContactHistory')"
              hover
              class="zebra-table"
            >
              <template #item.ts="{ item }">
                <span class="text-no-wrap">{{ formatHistoryDate(item.ts) }}</span>
              </template>
              <template #item.kind="{ item }">
                <v-chip
                  :color="entryColor(item)"
                  size="small"
                  variant="tonal"
                  :title="entryTitle(item)"
                >
                  <v-icon start size="small">{{ entryIcon(item) }}</v-icon>
                  {{ $t('CrmView.' + item.kind) }}
                </v-chip>
              </template>
              <template #item.summary="{ item }">
                <div class="text-truncate" :title="item.summary">{{ item.summary }}</div>
                <div v-if="item.detail" class="text-caption text-medium-emphasis text-truncate" :title="item.detail">
                  {{ item.detail }}
                </div>
              </template>
              <template #item.actions="{ item }">
                <v-btn
                  v-if="item.kind === 'call'"
                  icon
                  size="small"
                  variant="text"
                  color="primary"
                  :title="$t('CrmView.playRecording')"
                  @click="playPhoneCall(item.unique_call_id)"
                >
                  <v-icon>mdi-play-circle</v-icon>
                </v-btn>
                <v-btn
                  v-else
                  icon
                  size="small"
                  variant="text"
                  color="primary"
                  :title="item.kind === 'whatsapp' ? $t('CrmView.openWhatsApp') : $t('CrmView.openEmail')"
                  @click="openContactTab(item.kind === 'whatsapp' ? 'whatsapp' : 'emails')"
                >
                  <v-icon>mdi-open-in-app</v-icon>
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
import { ref, computed, watch, onActivated, onDeactivated, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import axios from 'axios'
import OccurrenceView from '@/core/components/crmview/occurrence.view.vue'
import CrmCustomerVendorDetailsView from '@/core/components/crmview/cvdetails.view.vue'
import CarsView from '@/core/components/crmview/cars.view.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { onServerEvent } from '@/core/composables/sseClient.js'
import { collectEmailAddresses } from '@/core/utils/cvContactAddresses.js'
import { formatPhone } from '@/core/utils/phoneFormat.js'
import { isCallMissed } from '@/core/utils/callStatus.js'
import * as toast from '@/core/utils/toasts.js'

// E-Mails kommen live per IMAP (getEmails) und nicht aus der DB. Damit ein
// Kundenwechsel hin und zurück keinen zweiten IMAP-Lauf auslöst, wird das
// Ergebnis je Kunde für die Sitzung gemerkt (Schlüssel: src:id + Adressen).
const emailCache = new Map()

export default {
  name: 'CrmView',
  components: { OccurrenceView, CrmCustomerVendorDetailsView, CarsView },
  setup() {
    const oserp = oserpStore();
    const router = useRouter();
    const route = useRoute();
    const { t } = useI18n();

    // Volltextfilter fuer die Vorgangs-Tabs (rechts neben dem Titel)
    const occurrenceFilter = ref('');

    const profile = computed(() => oserp.customer_vendor?.profile)
    const isCustomer = computed(() => profile.value?.src !== 'V')

    // Kundendaten beim Aktivieren aktualisieren (nur wirksam innerhalb <keep-alive>).
    // Ohne <keep-alive> uebernimmt StartupView.setup() den Refresh (auch ohne id-Prop).
    // NICHT bei onMounted: StartupView.setup() ruft fetchCustomerOrVendor bereits auf.
    // onMounted wuerde mit alten Store-Daten eine Race Condition ausloesen.
    function refreshCustomerData() {
      if (profile.value?.id) {
        oserp.fetchCustomerOrVendor(profile.value.id, profile.value.src || 'C')
      }
    }

    // ------------------------------------------------------------------
    // Kontakthistorie: Anrufe + WhatsApp aus dem Store (getCV), E-Mails per IMAP
    // ------------------------------------------------------------------
    const historyFilter = ref('all')
    const emails = ref([])
    const emailsLoading = ref(false)
    const emailsNotConfigured = ref(false)

    const emailAddresses = computed(() =>
      collectEmailAddresses(profile.value, oserp.customer_vendor?.contacts || [])
    )
    const emailCacheKey = computed(() =>
      profile.value?.id ? `${profile.value.src || 'C'}:${profile.value.id}:${emailAddresses.value.join(',')}` : ''
    )

    async function loadEmails(force = false) {
      const key = emailCacheKey.value
      if (!key || !emailAddresses.value.length) {
        emails.value = []
        return
      }
      if (!force && emailCache.has(key)) {
        emails.value = emailCache.get(key)
        return
      }
      emailsLoading.value = true
      try {
        const resp = await axios.post('/api/email/', {
          action: 'getEmails',
          email_addresses: emailAddresses.value,
          page: 1,
          limit: 50,
        })
        if (resp.data.success) {
          emailsNotConfigured.value = false
          const list = resp.data.payload.emails || []
          emailCache.set(key, list)
          // Zwischenzeitlich anderer Kunde gewählt? Dann nicht überschreiben.
          if (emailCacheKey.value === key) emails.value = list
        } else if (resp.data.text === 'EMAIL_NOT_CONFIGURED') {
          emailsNotConfigured.value = true
          emails.value = []
        } else {
          toast.error(resp.data.text || t('CrmView.connectionError'))
        }
      } catch {
        toast.error(t('CrmView.connectionError'))
      } finally {
        emailsLoading.value = false
      }
    }

    watch(emailCacheKey, () => loadEmails(), { immediate: true })

    function refreshHistory() {
      refreshCustomerData()
      loadEmails(true)
    }

    const ownAddresses = computed(() => emailAddresses.value.map(a => a.toLowerCase()))

    // Alle Einträge auf ein gemeinsames Format bringen:
    // { key, kind: call|whatsapp|email, ts (ms), direction I|O, missed, summary, detail, ... }
    const contactHistory = computed(() => {
      const fromDb = (oserp.customer_vendor?.contact_history ?? []).map(e => {
        const ts = Number(e.ts)
        if (e.kind === 'call') {
          const number = formatPhone(e.number)
          return {
            key: 'call-' + e.id,
            kind: 'call',
            ts,
            direction: e.direction,
            missed: isCallMissed(e.status),
            summary: e.name && e.name !== e.number ? e.name : number,
            detail: [number, e.extension].filter(Boolean).join(' · '),
            unique_call_id: e.unique_call_id,
          }
        }
        return {
          key: 'wa-' + e.id,
          kind: 'whatsapp',
          ts,
          direction: e.direction,
          missed: false,
          summary: e.text || '',
          detail: [e.name, formatPhone(e.number)].filter(Boolean).join(' · '),
        }
      })
      const fromImap = emails.value.map(m => {
        const inbound = ownAddresses.value.includes(String(m.from || '').toLowerCase())
        return {
          key: 'mail-' + (m.folder || '') + '-' + m.uid,
          kind: 'email',
          ts: m.date ? new Date(m.date).getTime() : 0,
          direction: inbound ? 'I' : 'O',
          missed: false,
          summary: m.subject || t('CrmView.noSubject'),
          detail: inbound ? (m.from_name ? `${m.from_name} <${m.from}>` : m.from) : m.to,
        }
      })
      return [...fromDb, ...fromImap]
    })

    const historyCounts = computed(() => {
      const c = { call: 0, whatsapp: 0, email: 0 }
      contactHistory.value.forEach(e => { c[e.kind]++ })
      return c
    })

    const filteredHistory = computed(() =>
      historyFilter.value === 'all'
        ? contactHistory.value
        : contactHistory.value.filter(e => e.kind === historyFilter.value)
    )

    // Die Inhaltsspalte bekommt den Restplatz (max-width: 0 + Textkürzung),
    // damit lange Betreffs/Nachrichten die Karte nicht horizontal sprengen.
    const contactHistoryHeaders = [
      { title: t('CrmView.date'), key: 'ts', sortable: true, nowrap: true },
      { title: t('CrmView.contactType'), key: 'kind', sortable: true, nowrap: true },
      { title: t('CrmView.content'), key: 'summary', sortable: false, cellProps: { class: 'history-content' } },
      { title: '', key: 'actions', sortable: false, align: 'center', width: '56px' },
    ];

    function entryColor(item) {
      if (item.missed) return 'error'
      return item.direction === 'I' ? 'success' : 'info'
    }

    function entryIcon(item) {
      if (item.kind === 'call') {
        if (item.missed) return 'mdi-phone-missed'
        return item.direction === 'I' ? 'mdi-phone-incoming' : 'mdi-phone-outgoing'
      }
      if (item.kind === 'whatsapp') return 'mdi-whatsapp'
      return item.direction === 'I' ? 'mdi-email-arrow-left-outline' : 'mdi-email-arrow-right-outline'
    }

    function entryTitle(item) {
      const dir = t(item.direction === 'I' ? 'CrmView.inbound' : 'CrmView.outbound')
      return item.missed ? `${dir} · ${t('CrmView.missedCall')}` : dir
    }

    function formatHistoryDate(ts) {
      if (!ts) return '';
      const d = new Date(ts)
      return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
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

    // WhatsApp-Chat bzw. E-Mail-Tab in der Kontaktdaten-Karte öffnen
    // (cvdetails.view.vue folgt route.query.tab)
    function openContactTab(tab) {
      router.replace({ query: { ...route.query, tab } })
    }

    // ------------------------------------------------------------------
    // Live-Aktualisierung: neuer Anruf / neue WhatsApp dieses Kunden -> getCV neu laden.
    // Der SSE-Server liefert crmti_change (ganze crmti-Zeile) und whatsapp_message
    // als unbenannte Events über 'message'; fremde Kanäle werden ignoriert.
    // ------------------------------------------------------------------
    let unsubSse = null
    let viewActive = true

    function concernsCurrentCv(data) {
      const id = Number(profile.value?.id)
      if (!id) return false
      if (data.crmti_id !== undefined) {
        // Ansprechpersonen (typ K) tragen die cp_id — sicherheitshalber neu laden
        return data.crmti_caller_typ === 'K' || Number(data.crmti_caller_id) === id
      }
      if (data.message_type !== undefined) {
        // Ohne Kundenzuordnung greift im Backend die Rufnummern-Suche
        return data.customer_id === null || data.customer_id === undefined || Number(data.customer_id) === id
      }
      return false
    }

    onMounted(() => {
      unsubSse = onServerEvent('message', (event) => {
        if (!viewActive) return
        let data
        try { data = JSON.parse(event.data) } catch { return }
        if (concernsCurrentCv(data)) refreshCustomerData()
      })
    })
    onUnmounted(() => {
      if (unsubSse) { unsubSse(); unsubSse = null }
    })
    onActivated(() => { viewActive = true; refreshCustomerData() })
    onDeactivated(() => { viewActive = false })

    const editRoute = computed(() => {
      if (!profile.value?.id) return null
      const routeName = profile.value.src === 'V' ? 'vendor-edit' : 'customer-edit'
      return { name: routeName, params: { id: profile.value.id } }
    })

    function goToEdit() {
      if (editRoute.value) router.push(editRoute.value)
    }

    return {
      oserp, router, occurrenceFilter, isCustomer,
      contactHistoryHeaders, filteredHistory, historyFilter, historyCounts,
      emailsLoading, emailsNotConfigured, refreshHistory,
      entryColor, entryIcon, entryTitle, formatHistoryDate, playPhoneCall, openContactTab,
      editRoute, goToEdit,
    };
  }
}
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
  background-color: rgba(0, 0, 0, 0.03);
}
.zebra-table :deep(td.history-content) {
  max-width: 0;
  width: 100%;
}
</style>
