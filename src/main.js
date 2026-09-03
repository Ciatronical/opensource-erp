import { createApp } from 'vue'
import App from './App.vue'
import router from './core/router'
import { createPinia } from 'pinia'
import { oserpStore } from '@/core/stores/oserp.store'

// i18n
import i18n from './i18n'
import { de, en } from 'vuetify/locale'

// Vuetify (Tree-Shaking via vite-plugin-vuetify — kein manueller Import nötig)
import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import '@mdi/font/css/materialdesignicons.css'
import './style.css'
import preserveCursor from '@/core/directives/preserveCursor'
import axios from 'axios'

// App & Plugins
const pinia = createPinia()
const app = createApp(App)

const vuetify = createVuetify({
  locale: {
    locale: 'de',
    fallback: 'en',
    messages: { de, en },
  },
  theme: {
    defaultTheme: 'light',
    themes: {
      light: {
        dark: false,
        colors: {
          background: '#FFFFFF',
          surface: '#FFFFFF',
          // Primaerfarbe traegt die Hauptaktion. Das fruehere Grau (#757575)
          // gab ihr kein Signal: "Speichern" sah aus wie jeder andere Button,
          // und tonale bzw. outlined Buttons verschwanden auf Weiss beinahe.
          // #1976D2 erfuellt WCAG AA (4,6:1 gegen Weiss) und kollidiert mit
          // keiner der semantischen Farben (success/warning/error).
          primary: '#1976D2',
          secondary: '#616161',
          accent: '#E0E0E0',
          error: '#FF5252',
          info: '#2196F3',
          success: '#4CAF50',
          warning: '#FB8C00',
        }
      },
      dark: {
        dark: true,
        colors: {
          background: '#121212',
          surface: '#1E1E1E',
          primary: '#90CAF9',
          secondary: '#B0BEC5',
          accent: '#424242',
          error: '#FF5252',
          info: '#2196F3',
          success: '#4CAF50',
          warning: '#FB8C00',
        }
      }
    }
  }
})

// Globale Zahlformatierungsfunktion
app.config.globalProperties.$n = (value, opts = {}) => {
  const num = typeof value === 'string' ? Number(value) : value
  if (!Number.isFinite(num)) return ''
  return new Intl.NumberFormat(i18n.global.locale.value, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
    ...opts
  }).format(num)
}

// Plugins registrieren
app.use(router)
app.use(pinia)
app.use(i18n)
app.use(vuetify)
app.directive('preserve-cursor', preserveCursor)

/* 🔥 WICHTIG: NACH pinia */
const store = oserpStore()

/**
 * Mitarbeiter-Kennung an jede Anfrage hängen.
 *
 * Das Backend darf nicht raten, wer gerade handelt: die PHP-Session kennt den
 * Mitarbeiter nicht (sie wird nirgends damit gefüllt), der Store dagegen schon.
 * Buchungen und Belege tragen dadurch, wer sie angelegt hat — ohne diese Angabe
 * ist eine Buchhaltung nicht nachvollziehbar.
 *
 * Ein bereits gesetztes employee_id im Aufruf gewinnt; Datei-Uploads per
 * FormData bleiben unangetastet.
 */
axios.interceptors.request.use(config => {
    const daten = config.data
    if (!daten || typeof daten !== 'object' || daten instanceof FormData) return config
    if (daten.employee_id !== undefined) return config

    const id = store.session?.logged_in_employee?.id
    if (id) daten.employee_id = id
    return config
})

// Debug-Modus prüfen
if (!store.isDebugMode()) {
  // Todo: console.log wieder aktivieren, wenn Debug-Modus aus
  //console.log = () => {}
  console.debug = () => {}
  console.warn = () => {}
}

// Mount
app.mount('#app')
