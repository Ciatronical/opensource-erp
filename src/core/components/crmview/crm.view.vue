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
        <section-card :title="$t('CrmView.contactData')" icon="mdi-account-box" plain class="h-100">
          <template #actions>
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
          </template>
          <CrmCustomerVendorDetailsView />
        </section-card>
      </v-col>

      <!-- Vorgaenge -->
      <v-col cols="12" md="6">
        <section-card :title="$t('CrmView.occurrences')" icon="mdi-file-document-multiple" plain class="h-100">
          <template #actions>
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
          </template>
          <OccurrenceView :search-text="occurrenceFilter" />
        </section-card>
      </v-col>

      <!-- Fahrzeuge (nur für Kunden) -->
      <v-col v-if="oserp.isLxCars() && isCustomer" cols="12" md="6">
        <section-card :title="$t('CrmView.vehicles')" icon="mdi-car" class="h-100">
          <template #actions>
            <v-btn
              size="small"
              variant="tonal"
              color="primary"
              prepend-icon="mdi-plus"
              @click="router.push({ name: 'fahrzeug-neu' })"
            >
              {{ $t('CrmView.newVehicle') }}
            </v-btn>
          </template>
          <CarsView />
        </section-card>
      </v-col>

      <!-- Kontakthistorie: Anrufe, WhatsApp, Briefe und E-Mails chronologisch -->
      <v-col cols="12" md="6">
        <section-card :title="$t('CrmView.contactHistory')" icon="mdi-history" plain class="h-100">
          <template #actions>
            <!-- Filter nach Kontaktart, mit Anzahl je Art -->
            <v-btn-toggle
              v-model="historyFilter"
              density="compact"
              variant="outlined"
              color="primary"
              divided
              mandatory
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
              <v-btn value="letter" size="small" :title="$t('CrmView.letter')">
                <v-icon start size="small">mdi-email-edit-outline</v-icon>{{ historyCounts.letter }}
              </v-btn>
              <v-btn value="visit" size="small" :title="$t('CrmView.visit')">
                <v-icon start size="small">mdi-account-voice</v-icon>{{ historyCounts.visit }}
              </v-btn>
              <v-btn v-if="historyCounts.confirmation" value="confirmation" size="small" :title="$t('CrmView.confirmation')">
                <v-icon start size="small">mdi-handshake-outline</v-icon>{{ historyCounts.confirmation }}
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
            <v-progress-circular v-if="emailsLoading" indeterminate size="18" width="2" color="primary" :title="$t('CrmView.emailsLoading')" />
            <!-- Kunde vor Ort: festhalten, was der Kunde im Laden/in der Werkstatt gesagt hat -->
            <v-btn
              icon
              size="small"
              variant="text"
              color="primary"
              :title="$t('CrmView.customerOnSite')"
              @click="openVisitDialog()"
            >
              <v-icon>mdi-account-voice</v-icon>
            </v-btn>
            <!-- Brief an diesen Kunden/Lieferanten schreiben -->
            <v-btn
              icon
              size="small"
              variant="text"
              color="primary"
              :title="$t('CrmView.newLetter')"
              @click="newLetter"
            >
              <v-icon>mdi-email-edit-outline</v-icon>
            </v-btn>
          </template>
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
                v-else-if="item.kind === 'visit'"
                icon
                size="small"
                variant="text"
                color="primary"
                :title="$t('CrmView.editVisit')"
                @click="openVisitDialog(item)"
              >
                <v-icon>mdi-pencil</v-icon>
              </v-btn>
              <div v-else-if="item.kind === 'confirmation'" class="d-flex justify-center">
                <!-- Zusage am Telefon: Aufnahme anhören -->
                <v-btn
                  v-if="item.unique_call_id"
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
                  icon
                  size="small"
                  variant="text"
                  :color="item.suggestion ? 'info' : 'primary'"
                  :title="item.suggestion ? $t('CrmView.confirmationReview') : $t('CrmView.openOrder')"
                  @click="router.push(entityRoute('order', item.record_id))"
                >
                  <v-icon>{{ item.suggestion ? 'mdi-gesture-tap' : 'mdi-open-in-app' }}</v-icon>
                </v-btn>
              </div>
              <v-btn
                v-else-if="item.kind === 'letter'"
                icon
                size="small"
                variant="text"
                color="primary"
                :title="$t('CrmView.openLetter')"
                @click="router.push({ name: 'letter-edit', params: { id: item.id } })"
              >
                <v-icon>mdi-open-in-app</v-icon>
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
        </section-card>
      </v-col>
    </v-row>

    <!-- Kunde vor Ort erfassen/ändern -->
    <v-dialog v-model="visitDialog.show" max-width="560">
      <section-card :title="visitDialog.id ? $t('CrmView.editVisit') : $t('CrmView.customerOnSite')" icon="mdi-account-voice" :subtitle="profile?.name">
        <v-row density="compact">
          <v-col cols="12">
            <v-textarea
              v-model="visitDialog.text"
              :label="$t('CrmView.visitText')"
              :placeholder="$t('CrmView.visitPlaceholder')"
              rows="5"
              auto-grow
              autofocus
              @keydown.ctrl.enter="saveVisit"
              @keydown.meta.enter="saveVisit"
            />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field
              v-model="visitDialog.when"
              type="datetime-local"
              :label="$t('CrmView.visitWhen')"
            />
          </v-col>
          <v-col cols="12" sm="6" class="d-flex align-center text-caption text-medium-emphasis">
            {{ $t('CrmView.visitSaveHint') }}
          </v-col>
        </v-row>
        <div class="d-flex mt-4">
          <v-btn
            v-if="visitDialog.id"
            variant="text"
            color="error"
            prepend-icon="mdi-delete"
            :loading="visitDialog.deleting"
            @click="deleteVisit"
          >
            {{ $t('CrmView.delete') }}
          </v-btn>
          <v-spacer />
          <v-btn variant="text" @click="visitDialog.show = false">{{ $t('CrmView.cancel') }}</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            prepend-icon="mdi-content-save"
            :disabled="!visitDialog.text.trim()"
            :loading="visitDialog.saving"
            @click="saveVisit"
          >
            {{ $t('CrmView.save') }}
          </v-btn>
        </div>
      </section-card>
    </v-dialog>
  </v-container>
</template>

<script>
import { ref, reactive, computed, watch, onActivated, onDeactivated, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import axios from 'axios'
import OccurrenceView from '@/core/components/crmview/occurrence.view.vue'
import CrmCustomerVendorDetailsView from '@/core/components/crmview/cvdetails.view.vue'
import CarsView from '@/core/components/crmview/cars.view.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { onServerEvent } from '@/core/composables/sseClient.js'
import { entityRoute } from '@/core/constants/routes.js'
import { collectEmailAddresses } from '@/core/utils/cvContactAddresses.js'
import { formatPhone } from '@/core/utils/phoneFormat.js'
import { isCallMissed } from '@/core/utils/callStatus.js'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

// E-Mails kommen live per IMAP (getEmails) und nicht aus der DB. Damit ein
// Kundenwechsel hin und zurück keinen zweiten IMAP-Lauf auslöst, wird das
// Ergebnis je Kunde für die Sitzung gemerkt (Schlüssel: src:id + Adressen).
const emailCache = new Map()

export default {
  name: 'CrmView',
  components: { OccurrenceView, CrmCustomerVendorDetailsView, CarsView, SectionCard },
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
    // Kontakthistorie: Anrufe, WhatsApp, Briefe, Vor-Ort aus dem Store (getCV), E-Mails per IMAP.
    // Alles aktualisiert sich live über SSE (siehe unten), es gibt keinen Aktualisieren-Knopf.
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

    const ownAddresses = computed(() => emailAddresses.value.map(a => a.toLowerCase()))

    // Alle Einträge auf ein gemeinsames Format bringen:
    // { key, kind: call|whatsapp|letter|visit|confirmation|email, ts (ms), direction I|O, missed, summary, detail, ... }
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
        if (e.kind === 'letter') {
          // number = Briefnummer, name = Versandwege (email,post), status = draft|sent
          const channels = (e.name || '').split(',').filter(Boolean).map(ch => t('CrmView.letterChannel.' + ch))
          return {
            key: 'letter-' + e.id,
            kind: 'letter',
            id: e.id,
            ts,
            direction: 'O',
            missed: false,
            draft: e.status === 'draft',
            summary: e.text || t('CrmView.noSubject'),
            detail: [t('CrmView.letterNumber', { number: e.number }), channels.length ? t('CrmView.letterSentVia', { channels: channels.join(', ') }) : t('CrmView.letterDraft')].join(' · '),
          }
        }
        if (e.kind === 'visit') {
          // name = erfassender Mitarbeiter, text = was der Kunde vor Ort gesagt hat
          return {
            key: 'visit-' + e.id,
            kind: 'visit',
            id: e.id,
            ts,
            direction: 'I',
            missed: false,
            text: e.text || '',
            summary: e.text || '',
            detail: e.name ? t('CrmView.visitRecordedBy', { name: e.name }) : '',
          }
        }
        if (e.kind === 'confirmation') {
          // Auftragsbestätigung bzw. Vorschlag der KI: number = Auftragsnummer, extension = Kanal,
          // status = confirmed | confirmed_auto | revoked | suggested_accept | suggested_decline | declined,
          // name = Mitarbeiter, text = Zitat/Notiz, record_id = Auftrag, unique_call_id = Anruf der Zusage.
          // Bestätigung und spätere Rücknahme kommen als zwei Einträge mit derselben id.
          const channel = t('CrmView.confirmationChannel.' + (e.extension || 'other'))
          const by = e.status === 'confirmed_auto' ? t('CrmView.confirmationAuto') : (e.name ? t('CrmView.confirmationBy', { name: e.name }) : '')
          return {
            key: 'confirmation-' + e.id + '-' + e.status,
            kind: 'confirmation',
            id: e.id,
            record_id: e.record_id,
            unique_call_id: e.unique_call_id,
            ts,
            direction: 'I',
            missed: false,
            status: e.status,
            suggestion: e.status.startsWith('suggested'),
            summary: t('CrmView.confirmationStatus.' + e.status, { number: e.number, channel }),
            detail: [e.text && e.status !== 'revoked' ? `„${e.text}“` : '', e.status.startsWith('suggested') ? '' : channel,
              e.status === 'revoked' && e.name ? t('CrmView.confirmationRevokedBy', { name: e.name }) : by].filter(Boolean).join(' · '),
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
      const c = { call: 0, whatsapp: 0, letter: 0, visit: 0, confirmation: 0, email: 0 }
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
      { title: '', key: 'actions', sortable: false, align: 'center', width: '88px' },
    ];

    // Auftragsbestätigung: Farbe und Symbol je Zustand
    const CONFIRMATION_META = {
      confirmed:         { color: 'success', icon: 'mdi-handshake-outline' },
      confirmed_auto:    { color: 'success', icon: 'mdi-handshake-outline' },
      revoked:           { color: 'grey',    icon: 'mdi-undo-variant' },
      suggested_accept:  { color: 'info',    icon: 'mdi-message-question-outline' },
      suggested_decline: { color: 'error',   icon: 'mdi-close-octagon-outline' },
      declined:          { color: 'grey',    icon: 'mdi-close-octagon-outline' },
    }

    function entryColor(item) {
      if (item.missed) return 'error'
      if (item.kind === 'letter') return item.draft ? 'grey' : 'deep-purple'
      if (item.kind === 'visit') return 'teal'
      if (item.kind === 'confirmation') return CONFIRMATION_META[item.status]?.color || 'grey'
      return item.direction === 'I' ? 'success' : 'info'
    }

    function entryIcon(item) {
      if (item.kind === 'call') {
        if (item.missed) return 'mdi-phone-missed'
        return item.direction === 'I' ? 'mdi-phone-incoming' : 'mdi-phone-outgoing'
      }
      if (item.kind === 'whatsapp') return 'mdi-whatsapp'
      if (item.kind === 'letter') return item.draft ? 'mdi-email-edit-outline' : 'mdi-email-seal-outline'
      if (item.kind === 'visit') return 'mdi-account-voice'
      if (item.kind === 'confirmation') return CONFIRMATION_META[item.status]?.icon || 'mdi-handshake-outline'
      return item.direction === 'I' ? 'mdi-email-arrow-left-outline' : 'mdi-email-arrow-right-outline'
    }

    function entryTitle(item) {
      if (item.kind === 'letter') return item.draft ? t('CrmView.letterDraft') : t('CrmView.outbound')
      if (item.kind === 'visit') return t('CrmView.customerOnSite')
      if (item.kind === 'confirmation') return item.summary
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
    // Briefe, Vor-Ort-Notizen und E-Mails kommen über den benannten Kanal
    // contact_history_change ({kind, cv_ids}); E-Mails meldet der Dienst
    // oserp-email-eingang (IMAP IDLE), sie werden dann per IMAP neu geladen.
    // Der SSE-Server liefert crmti_change (ganze crmti-Zeile) und whatsapp_message
    // als unbenannte Events über 'message'; fremde Kanäle werden ignoriert.
    // ------------------------------------------------------------------
    let unsubSse = null
    let unsubHistory = null
    let viewActive = true
    // Während die Ansicht verborgen ist (keep-alive) eingetroffene E-Mail-Meldung
    let emailsStale = false

    function onContactHistoryChange(event) {
      let data
      try { data = JSON.parse(event.data) } catch { return }
      const ids = (data.cv_ids || []).map(Number)
      if (data.kind === 'email') {
        // Zwischengespeicherte Mails der betroffenen Kunden verwerfen,
        // damit auch ein späterer Wechsel dorthin frisch lädt
        for (const key of emailCache.keys()) {
          if (ids.includes(Number(key.split(':')[1]))) emailCache.delete(key)
        }
      }
      if (!ids.includes(Number(profile.value?.id))) return
      if (data.kind === 'email') {
        if (viewActive) loadEmails(true)
        else emailsStale = true
      } else if (viewActive) {
        refreshCustomerData()
      }
    }

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
      unsubHistory = onServerEvent('contact_history_change', onContactHistoryChange)
    })
    onUnmounted(() => {
      if (unsubSse) { unsubSse(); unsubSse = null }
      if (unsubHistory) { unsubHistory(); unsubHistory = null }
    })
    onActivated(() => {
      viewActive = true
      refreshCustomerData()
      if (emailsStale) { emailsStale = false; loadEmails() }
    })
    onDeactivated(() => { viewActive = false })

    const editRoute = computed(() => {
      if (!profile.value?.id) return null
      const routeName = profile.value.src === 'V' ? 'vendor-edit' : 'customer-edit'
      return { name: routeName, params: { id: profile.value.id } }
    })

    function goToEdit() {
      if (editRoute.value) router.push(editRoute.value)
    }

    // Neuer Brief an diesen Kunden/Lieferanten (Kontakt → Briefe)
    function newLetter() {
      if (!profile.value?.id) return
      const query = profile.value.src === 'V' ? { vendor_id: String(profile.value.id) } : { customer_id: String(profile.value.id) }
      router.push({ name: 'letter-new', query })
    }

    // ------------------------------------------------------------------
    // Kunde vor Ort: Gesprächsnotiz erfassen (kivitendo-Notiz am Kunden).
    // Der gespeicherte Eintrag kommt fertig zurück und wird direkt in die
    // Historie im Store einsortiert — kein erneutes getCV nötig.
    // ------------------------------------------------------------------
    const visitDialog = reactive({ show: false, id: null, text: '', when: '', saving: false, deleting: false })

    // datetime-local erwartet Ortszeit ohne Zone: YYYY-MM-DDTHH:mm
    function toLocalInput(ms) {
      const d = new Date(ms)
      return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
    }

    function openVisitDialog(item = null) {
      Object.assign(visitDialog, {
        show: true,
        id: item?.id ?? null,
        text: item?.text ?? '',
        when: toLocalInput(item?.ts ?? Date.now()),
        saving: false,
        deleting: false,
      })
    }

    function replaceHistoryEntry(id, entry) {
      const cv = oserp.customer_vendor
      if (!cv) return
      const list = (cv.contact_history ?? []).filter(e => !(e.kind === 'visit' && e.id === id))
      cv.contact_history = entry ? [entry, ...list] : list
    }

    async function saveVisit() {
      if (!visitDialog.text.trim() || visitDialog.saving || !profile.value?.id) return
      visitDialog.saving = true
      try {
        const resp = await axios.post('/api/customer_vendor/', {
          action: 'saveVisitNote',
          id: visitDialog.id,
          cv_id: profile.value.id,
          text: visitDialog.text,
          subject: t('CrmView.customerOnSite'),
          ts: visitDialog.when ? new Date(visitDialog.when).getTime() : Date.now(),
          employee_id: oserp.session?.logged_in_employee?.id || null,
        })
        if (!resp.data.success) throw new Error(resp.data.text)
        replaceHistoryEntry(visitDialog.id, resp.data.payload)
        historyFilter.value = 'all'
        visitDialog.show = false
        toast.success(t('CrmView.visitSaved'))
      } catch (e) {
        alerts.error(e.message || t('CrmView.connectionError'), t('CrmView.customerOnSite'))
      } finally {
        visitDialog.saving = false
      }
    }

    async function deleteVisit() {
      if (!visitDialog.id) return
      const ok = await alerts.warning(t('CrmView.visitConfirmDelete'), t('CrmView.delete'), t('CrmView.delete'), t('CrmView.cancel'))
      if (!ok.isConfirmed) return
      visitDialog.deleting = true
      try {
        const resp = await axios.post('/api/customer_vendor/', {
          action: 'deleteVisitNote',
          id: visitDialog.id,
          cv_id: profile.value.id,
        })
        if (!resp.data.success) throw new Error(resp.data.text)
        replaceHistoryEntry(visitDialog.id, null)
        visitDialog.show = false
      } catch (e) {
        alerts.error(e.message || t('CrmView.connectionError'), t('CrmView.customerOnSite'))
      } finally {
        visitDialog.deleting = false
      }
    }

    return {
      oserp, router, occurrenceFilter, isCustomer, newLetter, profile,
      visitDialog, openVisitDialog, saveVisit, deleteVisit,
      contactHistoryHeaders, filteredHistory, historyFilter, historyCounts,
      emailsLoading, emailsNotConfigured,
      entryColor, entryIcon, entryTitle, formatHistoryDate, playPhoneCall, openContactTab, entityRoute,
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
