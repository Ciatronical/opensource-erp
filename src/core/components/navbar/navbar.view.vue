<!-- src/core/components/navbar/navbar.view.vue -->
<template>
  <v-app-bar color="grey-lighten-4" elevation="1" density="comfortable">
    <!-- Weroni — KI-Bürokauffrau (ganz links) -->
    <v-btn
      v-if="weroniEnabled"
      icon
      variant="text"
      :title="$t('weroni.tooltip')"
      :class="['ms-2', { 'weroni-blink': weroniPending }]"
      @click="weroni.togglePanel()"
    >
      <img :src="weroniIcon" width="24" height="24" alt="Weroni" style="border-radius: 50%;">
      <v-badge v-if="weroniPending" :content="weroni.pendingQuestionCount" color="error" floating offset-x="-4" offset-y="-4" />
    </v-btn>

    <v-btn
      v-if="showQuickIcons"
      icon
      variant="text"
      color="primary"
      @click="openInNewTab"
      :title="$t('NavbarView.newTabTooltip')"
      class="ms-2"
    >
      <v-icon>mdi-open-in-new</v-icon>
    </v-btn>
    <v-btn
      icon
      variant="text"
      color="primary"
      @click="openMenu"
      :title="$t('NavbarView.openInNewTabTooltip')"
      class="ms-2"
    >
      <v-icon>mdi-widgets</v-icon>
    </v-btn>
    <router-link :to="{ name: 'startup' }" class="text-decoration-none text-primary me-1"><v-icon>mdi-home</v-icon></router-link>

    <v-toolbar-title v-if="showCompany" class="text-h6 navbar-title">
      <router-link :to="{ name: 'startup' }" class="text-decoration-none text-primary">{{ appTitle }}</router-link>
    </v-toolbar-title>

    <!-- LxCars Schnellzugriff -->
    <v-btn
      v-if="oserpData.isLxCars() && showQuickIcons"
      icon
      variant="text"
      color="primary"
      :to="{ name: 'order-search' }"
      :title="$t('CarView.orderSearchTooltip')"
    >
      <v-icon>mdi-clipboard-search-outline</v-icon>
    </v-btn>
    <v-btn
      v-if="showQuickIcons"
      icon
      variant="text"
      color="primary"
      :to="{ name: 'calendar' }"
      :title="$t('NavbarView.calendarTooltip')"
    >
      <v-icon>mdi-calendar-month</v-icon>
    </v-btn>
    <v-btn
      v-if="showQuickIcons"
      icon
      variant="text"
      color="primary"
      :to="{ name: 'call-history' }"
      :title="$t('NavbarView.callHistoryTooltip')"
    >
      <v-icon>mdi-phone</v-icon>
    </v-btn>
    <v-btn
      v-if="oserpData.isLxCars() && showQuickIcons"
      icon
      variant="text"
      color="primary"
      :to="{ name: 'car-new-from-scan' }"
      :title="$t('CarView.scanTooltip')"
      style="position: relative"
    >
      <v-icon size="22">mdi-car-side</v-icon>
      <v-icon size="11" style="position: absolute; bottom: 6px; right: 6px;" color="primary">mdi-camera</v-icon>
    </v-btn>

    <!-- Responsive Menüs — geben bei Platzmangel als Erstes nach, damit die
         Symbole rechts nicht aus der Leiste geschoben werden (die Toolbar
         schneidet Überhang kommentarlos ab) -->
    <div ref="menuBox" class="navbar-flex">
      <ResponsiveMenu :menus="cards" :mode="menuMode" @menu-click="handleMenuAction" />
    </div>

    <!-- Globale Schnellsuche (Inline ab grossen Bildschirmen) -->
    <GlobalSearchComponent v-if="searchInline" class="mx-2 navbar-search" />

    <v-spacer />

    <!-- Rechte Seite: darf nie abgeschnitten werden -->
    <div class="navbar-right">

    <!-- Firmenlogo oder Firmenname — Klick öffnet Firmenliste -->
    <v-menu v-if="showCompany" v-model="clientMenuOpen" location="bottom end" :close-on-content-click="true" open-on-hover>
      <template #activator="{ props: menuProps }">
        <div v-bind="menuProps" class="d-flex align-center cursor-pointer">
          <img
            v-if="companyLogo"
            :src="companyLogo"
            class="company-logo me-2"
            :title="t('NavbarView.switchClient')"
          >
          <span v-else class="text-body-2 font-weight-medium text-primary me-1">{{ oserpData.session.client }}</span>
          <v-icon v-if="clientList.length > 1" size="x-small" class="me-1">mdi-chevron-down</v-icon>
        </div>
      </template>
      <v-list density="compact" min-width="220">
        <v-list-item
          v-for="client in clientList"
          :key="client.code"
          :disabled="client.name === oserpData.session.client"
          @click="doSwitchClient(client.code)"
        >
          <v-list-item-title class="text-body-2">{{ client.name }}</v-list-item-title>
          <template #append>
            <v-icon v-if="client.name === oserpData.session.client" size="small" color="primary">mdi-check</v-icon>
          </template>
        </v-list-item>
        <v-divider class="my-1" />
        <v-list-item
          :disabled="!oserpData.session.can_create_company"
          @click="openCreateCompanyDialog"
        >
          <template #prepend><v-icon size="small" class="me-2">mdi-plus-circle-outline</v-icon></template>
          <v-list-item-title class="text-body-2">{{ t('NavbarView.newCompany') }}</v-list-item-title>
        </v-list-item>
        <v-divider class="my-1" />
        <v-list-item :to="{ name: 'client-defaults' }" @click="clientMenuOpen = false">
          <template #prepend><v-icon size="small" class="me-2">mdi-cog</v-icon></template>
          <v-list-item-title class="text-body-2">{{ t('SystemMenu.clientConfig') }}</v-list-item-title>
        </v-list-item>
        <v-list-item :to="{ name: 'developer-tools' }" @click="clientMenuOpen = false">
          <template #prepend><v-icon size="small" class="me-2">mdi-wrench</v-icon></template>
          <v-list-item-title class="text-body-2">{{ t('SystemMenu.developerTools') }}</v-list-item-title>
        </v-list-item>
        <v-list-item :to="{ name: 'system-update' }" @click="clientMenuOpen = false">
          <template #prepend><v-icon size="small" class="me-2">mdi-update</v-icon></template>
          <v-list-item-title class="text-body-2">{{ t('SystemMenu.systemUpdate') }}</v-list-item-title>
        </v-list-item>
        <v-divider class="my-1" />
        <v-list-item :to="{ name: 'docs' }" @click="clientMenuOpen = false">
          <template #prepend><v-icon size="small" class="me-2">mdi-book-open-variant</v-icon></template>
          <v-list-item-title class="text-body-2">{{ t('SystemMenu.docs') }}</v-list-item-title>
        </v-list-item>
        <v-list-item @click="showAboutDialog = true; clientMenuOpen = false">
          <template #prepend><v-icon size="small" class="me-2">mdi-information-outline</v-icon></template>
          <v-list-item-title class="text-body-2">{{ t('SystemMenu.about') }}</v-list-item-title>
        </v-list-item>
      </v-list>
    </v-menu>

    <!-- Kamera / Videoüberwachung -->
    <v-btn
      icon
      variant="text"
      :to="{ name: 'camera' }"
      :title="t('CameraView.title')"
    >
      <v-icon>mdi-cctv</v-icon>
    </v-btn>

    <!-- Mitarbeiter-Chat -->
    <v-btn
      icon
      variant="text"
      color="primary"
      :title="t('Chat.tooltip')"
      @click="toggleChatPanel"
    >
      <v-badge
        :model-value="chatUnread > 0"
        :content="chatUnread"
        color="error"
        offset-x="-2"
        offset-y="-2"
      >
        <v-icon>mdi-chat-outline</v-icon>
      </v-badge>
    </v-btn>

    <!-- Account-Menü -->
    <v-menu v-model="accountMenuOpen" location="bottom end" :close-on-content-click="false">
      <template #activator="{ props }">
        <v-btn
          variant="text"
          color="primary"
          v-bind="props"
          :title="$t('NavbarView.accountTooltip')"
          class="text-none"
        >
          <v-icon
            start
            :color="sseConnected ? 'primary' : 'orange-darken-2'"
            :title="sseConnected ? t('NavbarView.sseConnected') : t('NavbarView.sseDisconnected')"
          >mdi-account-circle</v-icon>
          <span v-if="showUserName" class="text-body-2">{{ oserpData.session.user }}</span>
        </v-btn>
      </template>

      <v-card min-width="280">
        <v-card-text class="pb-2">
          <div class="text-subtitle-2 font-weight-bold">{{ oserpData.session.user }}</div>
          <div class="text-caption text-medium-emphasis">{{ oserpData.session.client }}</div>
        </v-card-text>
        <v-divider />
        <v-list density="compact">
          <v-list-item @click="logout">
            <template #prepend>
              <v-icon size="small" class="me-2">mdi-logout</v-icon>
            </template>
            <v-list-item-title>{{ $t('NavbarView.logoutTooltip') }}</v-list-item-title>
          </v-list-item>
          <v-list-item :to="{ name: 'user-config' }" @click="accountMenuOpen = false">
            <template #prepend>
              <v-icon size="small" class="me-2">mdi-cog</v-icon>
            </template>
            <v-list-item-title>{{ $t('NavbarView.userConfig') }}</v-list-item-title>
          </v-list-item>
          <template v-if="oserpData.isLxCars()">
            <v-divider class="my-1" />
            <v-list-item :to="{ name: 'lxcars-reports' }" @click="accountMenuOpen = false">
              <template #prepend>
                <v-icon size="small" class="me-2">mdi-chart-timeline-variant-shimmer</v-icon>
              </template>
              <v-list-item-title>{{ $t('NavbarView.reports') }}</v-list-item-title>
            </v-list-item>
          </template>
        </v-list>
      </v-card>
    </v-menu>

    </div><!-- /navbar-right -->

    <!-- Globale Schnellsuche auf kleinen Bildschirmen: eigene volle Zeile,
         damit das Eingabefeld nicht zusammengequetscht wird -->
    <template v-if="!searchInline" #extension>
      <GlobalSearchComponent class="mx-2" style="width: 100%" />
    </template>
  </v-app-bar>

  <!-- Info Bar: Neue Anrufe & E-Mails -->
  <InfoBarComponent />

  <!-- Weroni Panel (rechtes Drawer) -->
  <WeroniPanel v-if="weroniEnabled" />

  <!-- Mitarbeiter-Chat (rechtes Drawer, oeffnet sich nur auf Klick) -->
  <ChatPanel />

  <!-- Ausgewaehlter Kunde/Lieferant. Auf Seiten mit meta.hideCustomerBar
       (z. B. der gesamten Buchhaltung) ausgeblendet — dort arbeitet man
       mandantenweit und der zuletzt gewaehlte Kunde stiftet nur Verwirrung. -->
  <div v-if="oserpData.customer_vendor !== false && !route.meta.hideCustomerBar" class="text-left pa-2">
    <v-btn
      v-if="editRoute"
      icon
      size="20"
      class="me-2"
      :title="t('NavbarView.editCvTooltip')"
      @click="goToEdit"
    >
      <v-icon size="18">mdi-pencil</v-icon>
    </v-btn>
    <span class="px-2" v-if="cvSrc === 'C'">{{ $t('NavbarView.currentC') }}</span>
    <span class="px-2" v-else>{{ $t('NavbarView.currentV') }}</span>
    <span class="font-weight-bold">{{ oserpData.customer_vendor?.profile?.name }}</span>
    <span class="font-weight-semibold ps-2">( {{ cvSrc === 'C' ? oserpData.customer_vendor?.profile?.customernumber : oserpData.customer_vendor?.profile?.vendornumber }} )</span>
  </div>

  <!-- Demo-Modus Banner -->
  <v-banner
    v-if="isDemo"
    color="warning"
    density="compact"
    icon="mdi-flask-outline"
    class="text-center"
    stacked
  >
    <template #text>
      <span class="text-body-2 font-weight-medium">{{ t('NavbarView.demoBanner') }}</span>
    </template>
  </v-banner>

  <!-- Demo Inaktivitäts-Warnung -->
  <v-snackbar
    v-if="isDemo"
    :model-value="showDemoWarning"
    color="error"
    timeout="-1"
    location="top"
    multi-line
  >
    <div class="d-flex align-center">
      <v-icon class="me-3">mdi-alert</v-icon>
      <span>{{ t('NavbarView.demoResetWarning', { seconds: demoRemainingSeconds }) }}</span>
    </div>
  </v-snackbar>

  <!-- MessagesView Integration -->
  <MessagesView :messages="displayMessages" />

  <!-- Über-Dialog -->
  <AboutDialog v-model="showAboutDialog" :app-title="appTitle" />

  <!-- Neue Firma anlegen -->
  <v-dialog v-model="showCreateCompanyDialog" max-width="500" persistent>
    <v-card>
      <v-card-title class="bg-primary text-white">
        <v-icon start>mdi-domain-plus</v-icon>
        {{ t('NavbarView.createCompanyTitle') }}
      </v-card-title>
      <v-card-text class="pa-4">
        <v-text-field
          ref="createCompanyNameRef"
          v-model="createCompanyName"
          :label="t('NavbarView.companyName')"
          variant="outlined"
          density="compact"
          class="mb-3"
          :error-messages="createCompanyError"
          @update:model-value="createCompanyError = ''"
          @keydown.enter="canSubmitCompany && doCreateCompany()"
        />
        <v-text-field
          v-model="createCompanyDbName"
          :label="t('NavbarView.dbName')"
          variant="outlined"
          density="compact"
          class="mb-3"
          :hint="t('NavbarView.dbNameHint')"
          persistent-hint
          @keydown.enter="canSubmitCompany && doCreateCompany()"
        />
        <v-select
          v-model="createCompanySkr"
          :items="skrOptions"
          :label="t('NavbarView.chartOfAccounts')"
          variant="outlined"
          density="compact"
        />
      </v-card-text>
      <v-card-actions>
        <v-btn @click="closeCreateCompanyDialog">{{ t('NavbarView.cancel') }}</v-btn>
        <v-spacer />
        <v-btn
          color="primary"
          variant="flat"
          :loading="createCompanyLoading"
          :disabled="!canSubmitCompany"
          @click="doCreateCompany"
        >
          <v-icon start>mdi-check</v-icon>
          {{ t('NavbarView.createCompany') }}
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script>
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import MessagesView from '@/core/components/messages/messages.view.vue'
import ResponsiveMenu from '@/core/components/menus/responsive.menus.vue'
import GlobalSearchComponent from '@/core/components/navbar/global-search.component.vue'
import InfoBarComponent from '@/core/components/navbar/info-bar.component.vue'
import { sseConnected } from '@/core/composables/useInfoBar'
import AboutDialog from '@/core/components/about/about.dialog.vue'
import weroniIcon from '@/assets/weroni/weroni-24.png'
import WeroniPanel from '@/features/weroni/components/weroni-panel.vue'
import { weroniStore } from '@/features/weroni/stores/weroni.store.js'
import { useNavigationCards } from '@/core/composables/navigation.cards'
import { useActivityTracker } from '@/core/composables/useActivityTracker'
import ChatPanel from '@/core/components/chat/chat.panel.vue'
import { startChat, stopChat, toggleChatPanel, unreadTotal as chatUnread } from '@/core/composables/useChat.js'

export default {
  name: 'NavbarView',
  components: {
    MessagesView,
    ResponsiveMenu,
    GlobalSearchComponent,
    InfoBarComponent,
    AboutDialog,
    WeroniPanel,
    ChatPanel
  },
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
    }
  },
  setup(props) {
    const oserpData = oserpStore()
    const weroni = weroniStore()
    const router = useRouter()
    const route = useRoute()
    const { t } = useI18n()
    const cvSrc = computed(() => oserpData.customer_vendor?.profile?.src || 'C')
    const appReady = inject('appReady')

    // Titel der Anwendung: erste aktive Erweiterung mit eigenem Namen gewinnt
    const extensionDisplayNames = {
      lxcars: 'LxCars'
    }

    const appTitle = computed(() => {
      const active = oserpData.extensions.find(e => extensionDisplayNames[e])
      return active ? extensionDisplayNames[active] : 'OpensourceERP'
    })

    // Demo-Modus
    const isDemo = computed(() => oserpData.session.is_demo)

    const { showWarning: showDemoWarning, remainingSeconds: demoRemainingSeconds } = isDemo.value
      ? useActivityTracker({
          inactivityMinutes: oserpData.session.demo_inactivity_minutes,
          onReset: async () => {
            appReady.value = false
            try { await oserpData.logout() } catch {}
            await router.push({ name: 'login' })
            appReady.value = true
          }
        })
      : { showWarning: computed(() => false), remainingSeconds: computed(() => 0) }

    const editRoute = computed(() => {
      const profile = oserpData.customer_vendor?.profile
      if (!profile?.id) return null
      const routeName = profile.src === 'V' ? 'vendor-edit' : 'customer-edit'
      return { name: routeName, params: { id: profile.id } }
    })

    function goToEdit() {
      if (editRoute.value) router.push(editRoute.value)
    }

    // Weroni
    const weroniEnabled = computed(() => {
        const v = oserpData.getClientDefaultValue('weroni_enabled', false)
        return v === true || v === 'true' || v === 't' || v === '1'
    })
    const weroniPending = computed(() => weroni.pendingQuestionCount > 0)

    // Weroni: Einmalig offene Rückfragen laden (Updates kommen via SSE)
    if (weroniEnabled.value) {
        weroni.fetchPendingCount()
    }

    // Navigation Cards aus Composable
    const { cards } = useNavigationCards(true)

    // Mitarbeiter-Chat: eine SSE-Verbindung fuer Badge und Panel
    onMounted(startChat)
    onUnmounted(stopChat)

    // ── Platz in der Leiste messen ─────────────────────────────────────
    // Die Toolbar schneidet Ueberhang ohne Vorwarnung ab (overflow: hidden),
    // und wie viel Platz sie braucht, haengt von Mandant, Sprache und aktiven
    // Features ab — feste Breakpoints reichen dafuer nicht. Darum wird
    // gemessen und stufenweise ausgeduennt, bis alles hineinpasst.
    //
    //   0 Textmenue + Suche in der Leiste + Logo + Benutzername
    //   1 Menue als Symbole
    //   2 Suche in die zweite Zeile
    //   3 Menue als Hamburger
    //   4 Schnellzugriff-Symbole (Kalender, Telefon, ...) aus
    //   5 Firmenlogo/-name aus
    //   6 Benutzername aus (nur noch Symbol)
    const MAX_STAGE = 6
    const menuBox = ref(null)
    const stage = ref(initialStage())
    // Fensterbreite merken, bei der auf die naechste Stufe geschaltet wurde —
    // erst deutlich darueber geht es wieder zurueck (kein Hin-und-Her).
    const escalatedAt = []
    let resizeObserver = null

    // Startwert grob schaetzen, damit die Leiste nicht sichtbar durchschaltet
    function initialStage() {
      const w = typeof window !== 'undefined' ? window.innerWidth : 1920
      if (w < 600) return 6
      if (w < 860) return 5
      if (w < 1100) return 3
      if (w < 1400) return 2
      if (w < 2100) return 1
      return 0
    }

    const menuMode = computed(() => {
      if (stage.value >= 3) return 'burger'
      if (stage.value >= 1) return 'icons'
      return 'text'
    })
    const searchInline = computed(() => stage.value < 2)
    const showQuickIcons = computed(() => stage.value < 4)
    const showCompany = computed(() => stage.value < 5)
    const showUserName = computed(() => stage.value < 6)

    function measureBar() {
      const box = menuBox.value
      const toolbar = box?.closest('.v-toolbar__content')
      if (!toolbar) return
      const w = window.innerWidth

      if (toolbar.scrollWidth > toolbar.clientWidth + 1) {
        if (stage.value < MAX_STAGE) {
          escalatedAt[stage.value] = w
          stage.value++
          nextTick(measureBar)
        }
        return
      }

      // Es passt: probeweise eine Stufe zurueck. Wurde auf dieser Breite schon
      // einmal hochgestuft, erst ab 40px mehr Fensterbreite erneut versuchen —
      // sonst kippt die Leiste bei jedem Pixel hin und her.
      if (stage.value > 0) {
        const at = escalatedAt[stage.value - 1]
        if (at === undefined || w > at + 40) {
          stage.value--
          nextTick(measureBar)
        }
      }
    }

    onMounted(() => {
      nextTick(measureBar)
      if (typeof ResizeObserver !== 'undefined' && menuBox.value?.parentElement) {
        resizeObserver = new ResizeObserver(() => measureBar())
        resizeObserver.observe(menuBox.value.parentElement)
      }
    })
    onUnmounted(() => { if (resizeObserver) resizeObserver.disconnect() })

    // Menuepunkte aendern sich mit Sprache, Mandant und Features — neu messen
    watch(() => cards.value.length, () => {
      escalatedAt.length = 0
      stage.value = initialStage()
      nextTick(measureBar)
    })

    // Firmenwechsel
    const accountMenuOpen = ref(false)
    const clientMenuOpen = ref(false)
    const showAboutDialog = ref(false)
    const clientList = ref([])

    // Firmenliste einmalig beim Laden der Navbar holen
    oserpData.fetchClients()
      .then(({ clients }) => { clientList.value = clients })
      .catch(() => {})

    async function doSwitchClient(clientCode) {
      accountMenuOpen.value = false
      try {
        await oserpData.switchClient(clientCode)
        router.push({ name: 'startup' })
      } catch (err) {
        console.error('Firmenwechsel fehlgeschlagen:', err)
      }
    }

    // Neue Firma anlegen
    const showCreateCompanyDialog = ref(false)
    const createCompanySkr = ref('')
    const createCompanyName = ref('')
    const createCompanyDbName = ref('')
    const createCompanyLoading = ref(false)
    const createCompanyError = ref('')
    const createCompanyNameRef = ref(null)
    const canSubmitCompany = computed(() => createCompanyName.value.trim() !== '' && createCompanyDbName.value.trim() !== '')

    const skrOptions = [
      { title: 'SKR03', value: 'skr03' },
      { title: 'SKR04', value: 'skr04' }
    ]

    function openCreateCompanyDialog() {
      clientMenuOpen.value = false
      createCompanySkr.value = 'skr03'
      createCompanyName.value = ''
      createCompanyDbName.value = ''
      createCompanyError.value = ''
      showCreateCompanyDialog.value = true
      nextTick(() => createCompanyNameRef.value?.focus())
    }

    function closeCreateCompanyDialog() {
      showCreateCompanyDialog.value = false
      createCompanySkr.value = ''
      createCompanyName.value = ''
      createCompanyDbName.value = ''
      createCompanyError.value = ''
    }

    async function doCreateCompany() {
      createCompanyLoading.value = true
      createCompanyError.value = ''
      try {
        const companyName = createCompanyName.value.trim()
        await oserpData.createCompany(
          companyName,
          createCompanyDbName.value.trim(),
          createCompanySkr.value
        )
        closeCreateCompanyDialog()
        // Firmenliste neu laden und zur neuen Firma wechseln
        const { clients } = await oserpData.fetchClients()
        clientList.value = clients
        const newClient = clients.find(c => c.name === companyName)
        if (newClient) {
          await oserpData.switchClient(newClient.code)
          router.push({ name: 'startup' })
        }
      } catch (err) {
        const errorCode = err.code || err.message || 'UNKNOWN_ERROR'
        const key = 'NavbarView.createCompanyError.' + errorCode
        createCompanyError.value = t(key) !== key ? t(key) : t('NavbarView.createCompanyError.UNKNOWN_ERROR')
      } finally {
        createCompanyLoading.value = false
      }
    }

    // Firmenlogo (nur Anzeige, Upload jetzt in Firmenkonfiguration)
    const companyLogo = computed(() => oserpData.getClientDefaultValue('company_logo', null))

    const logout = async () => {
      appReady.value = false
      stopChat()
      try {
        await oserpData.logout()
      } catch (err) {
        console.error('Logout error:', err)
      }
      await router.push({ name: 'login' })
      appReady.value = true
    }

    const openMenu = () => {
      router.push({ name: 'menu' })
    }

    const openInNewTab = () => {
      window.open(window.location.href, '_blank')
    }

    const mapType = (t) => (['info', 'warning', 'error', 'success'].includes(t) ? t : 'info')

    // Handler für Menü-Aktionen ohne Route
    const handleMenuAction = (item) => {
      if (item?.action === 'chat') {
        toggleChatPanel()
        return
      }
      // Hier können weitere Custom-Actions behandelt werden
      console.log('Menu action:', item)
    }

    // Anzeige-Messages ableiten: bevorzugt prop.messages, dann prop.message, sonst Fallback
    const displayMessages = computed(() => {
      const list = []

      if (Array.isArray(props.messages) && props.messages.length) {
        props.messages.forEach(m => {
          if (m && m.title) list.push({
            title: m.title,
            description: m.description || '',
            type: mapType(m.type || 'info')
          })
        })
      }

      if (props.message && props.message.title) {
        list.push({
          title: props.message.title,
          description: props.message.description || '',
          type: mapType(props.message.type || 'info')
        })
      }

      if (!oserpData.customer_vendor) {
        list.push({
          title: 'Willkommen zu OpensourceERP!',
          description:
            'Es scheint, als hätten Sie noch keine Kunden angelegt. Beginnen Sie damit, Ihren ersten Kunden zu erstellen, um die Funktionen von OpenSourceERP zu erkunden.',
          type: 'info',
        })
      }

      return list
    })

    return {
      oserpData,
      route,
      appTitle,
      isDemo,
      showDemoWarning,
      demoRemainingSeconds,
      logout,
      openMenu,
      openInNewTab,
      t,
      cvSrc,
      editRoute,
      goToEdit,
      displayMessages,
      cards,
      handleMenuAction,
      accountMenuOpen,
      clientMenuOpen,
      clientList,
      doSwitchClient,
      companyLogo,
      showAboutDialog,
      sseConnected,
      weroni,
      weroniIcon,
      weroniEnabled,
      weroniPending,
      showCreateCompanyDialog,
      createCompanySkr,
      createCompanyNameRef,
      canSubmitCompany,
      createCompanyName,
      createCompanyDbName,
      createCompanyLoading,
      createCompanyError,
      skrOptions,
      openCreateCompanyDialog,
      closeCreateCompanyDialog,
      doCreateCompany,
      chatUnread,
      toggleChatPanel,
      menuBox,
      menuMode,
      searchInline,
      showQuickIcons,
      showCompany,
      showUserName
    }
  }
}
</script>

<style scoped>
/* Die Toolbar schneidet Überhang ohne Vorwarnung ab (overflow: hidden).
   Darum bekommt die rechte Gruppe festen Platz, während Menüs und Suchfeld
   nachgeben — sonst verschwinden Kamera, Chat und Benutzer bei vielen
   Menüpunkten oder schmalem Fenster einfach aus dem Bild. */
.navbar-right {
  display: flex;
  align-items: center;
  flex: 0 0 auto;
}
/* Menüleiste schrumpft nicht — lieber gibt das Suchfeld Breite ab, und wenn
   selbst das nicht reicht, schaltet measureMenus() auf Symbole um. */
.navbar-flex {
  display: flex;
  align-items: center;
  flex: 0 0 auto;
}
/* Der Produktname soll nicht zu "L..." schrumpfen — Platz gibt die Suche her */
.navbar-title {
  flex: 0 0 auto;
}
/* Das Suchfeld nimmt den freien Platz, nicht der v-spacer: mit gleichem
   flex-grow teilen sich beide den Rest und das Feld bleibt unnoetig schmal.
   Unter 200px ist es kaum brauchbar — dann schiebt measureBar() es lieber in
   die zweite Zeile, wo es die volle Breite hat. */
.navbar-search {
  flex: 6 1 auto;
  min-width: 200px;
  max-width: 720px;
}
.cursor-pointer {
  cursor: pointer;
}
.weroni-blink {
  animation: weroni-pulse 1.5s ease-in-out infinite;
}
@keyframes weroni-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
.company-logo {
  height: 36px;
  width: auto;
  max-width: 180px;
  object-fit: contain;
  border-radius: 4px;
}
</style>
