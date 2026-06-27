// vite.config.js
import { fileURLToPath, URL } from 'node:url'
import { writeFileSync, mkdirSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { execFileSync } from 'node:child_process'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
// vue-devtools bei Ärger, erst mal weglassen
import vueDevTools from 'vite-plugin-vue-devtools'

// Vor jedem Build die Backend-API absichern: verwaiste require_once (wie einst
// asanetwork.php → ganze /api/lxcars/-API tot → Autocomplete in instructions/
// Positionen ausgefallen), Syntaxfehler und Output vor <?php hart abfangen.
// Bricht den Build ab, wenn die API kaputt wäre. Fehlt PHP, wird nur gewarnt
// (Build läuft weiter), damit reine Frontend-Umgebungen nicht blockiert werden.
function apiHealthPlugin() {
  return {
    name: 'api-health-check',
    apply: 'build',
    buildStart() {
      const script = resolve(__dirname, 'tools/check-api-health.php')
      try {
        execFileSync('php', [script], { stdio: 'inherit' })
      } catch (e) {
        if (e && e.code === 'ENOENT') {
          this.warn('PHP nicht gefunden — API-Health-Check übersprungen.')
          return
        }
        // Non-zero Exit = echte Probleme → Build abbrechen
        this.error('API-Health-Check fehlgeschlagen — Build abgebrochen (siehe Ausgabe oben).')
      }
    }
  }
}

// Nach jedem Build eine build-id.txt schreiben, damit der SSE-Server
// den Clients ein build_changed Event senden kann
function buildIdPlugin() {
  return {
    name: 'write-build-id',
    closeBundle() {
      const outPath = resolve(__dirname, 'dist/build-id.txt')
      mkdirSync(dirname(outPath), { recursive: true })
      writeFileSync(outPath, Date.now().toString(), 'utf-8')
    }
  }
}

export default defineConfig({
  plugins: [
    vue(),
    // Vuetify Tree-Shaking: importiert nur tatsächlich verwendete Komponenten
    vuetify({ autoImport: true }),
    vueDevTools(),
    apiHealthPlugin(),
    buildIdPlugin(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@special': fileURLToPath(new URL('./special/frontend', import.meta.url))
    }
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          // Vuetify — alle Module (Components, Directives, Styles-Logik)
          if (id.includes('node_modules/vuetify')) return 'vendor-vuetify'
          // Vue-Kern + Router + State + Draggable + vue-i18n-Internals (@intlify)
          if (id.includes('node_modules/vue/') ||
              id.includes('node_modules/@vue/') ||
              id.includes('node_modules/vue-router') ||
              id.includes('node_modules/pinia') ||
              id.includes('node_modules/vue-i18n') ||
              id.includes('node_modules/@intlify/') ||
              id.includes('node_modules/vuedraggable') ||
              id.includes('node_modules/sortablejs')) return 'vendor-vue'
          // FullCalendar
          if (id.includes('node_modules/@fullcalendar')) return 'vendor-calendar'
          // Tiptap Rich-Text-Editor
          if (id.includes('node_modules/@tiptap') ||
              id.includes('node_modules/prosemirror') ||
              id.includes('node_modules/@prosemirror')) return 'vendor-tiptap'
          // Chart.js
          if (id.includes('node_modules/chart.js') ||
              id.includes('node_modules/vue-chartjs')) return 'vendor-charts'
          // libphonenumber
          if (id.includes('node_modules/libphonenumber-js')) return 'vendor-phone'
          // axios
          if (id.includes('node_modules/axios')) return 'vendor-axios'
          // sweetalert2
          if (id.includes('node_modules/sweetalert2')) return 'vendor-sweetalert'
          // vuefinder + uppy: kein manualChunk mehr — FilesTab ist async (defineAsyncComponent),
          // daher landen vuefinder-Deps in einem lazy-chunk ohne zirkuläre Abhängigkeit zu vendor-vue
        }
      }
    }
  },
  server: {
    proxy: {
      '/sse': {
        target: 'http://localhost:3001',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/sse/, ''),
      },
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
      '/webhook': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      }
    },
    watch: {
      followSymlinks: false,
      // Backend, Daten-Uploads und Build-Artefakte NICHT watchen.
      // Sonst versucht Vite jede frisch hochgeladene Vendor-/Kunden-Datei
      // in backend/data zu überwachen und läuft ins inotify-Limit (ENOSPC),
      // wodurch der Dev-Server beim Upload abstürzt.
      ignored: [
        '**/backend/**',
        '**/backups/**',
        '**/dist/**',
        '**/docker/**',
        '**/install/**',
      ],
    }
  }
})
