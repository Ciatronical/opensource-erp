// vite.config.js
import { fileURLToPath, URL } from 'node:url'
import { writeFileSync, mkdirSync, readdirSync, readFileSync } from 'node:fs'
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
      const script = resolve(import.meta.dirname, 'tools/check-api-health.php')
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

// Routen-Pfade aller Sprachen als eigenes, winziges Modul
//
// Die Routen-Tabelle wird beim Modul-Import gebaut und braucht dafür die
// `routes.*`-Pfade ALLER Sprachen auf einen Schlag: jede fremdsprachige URL
// hängt als `alias` an der Route, damit Lesezeichen den Sprachwechsel
// überleben. Genau das war der einzige Grund, warum früher sämtliche
// Sprachdateien eager gebündelt wurden — 7 MB JSON für 21 Sprachen im
// Initial-Load, von denen ein Benutzer nur eine sieht.
//
// Dieses Plugin sammelt nur die `routes`-Blöcke ein (~38 kB über alle Sprachen
// zusammen). Alles Übrige lädt src/i18n/index.js erst für die Sprache nach,
// die tatsächlich benutzt wird.
function i18nRoutesPlugin() {
  const VIRTUAL_ID = 'virtual:oserp-route-messages'
  const RESOLVED_ID = '\0' + VIRTUAL_ID
  const LOCALE_RE = /[\\/]locales[\\/]([a-z-]+)\.json$/i

  const srcDir = resolve(import.meta.dirname, 'src')

  function collect(dir, found = []) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = resolve(dir, entry.name)
      if (entry.isDirectory()) collect(full, found)
      else if (LOCALE_RE.test(full)) found.push(full)
    }
    return found
  }

  function merge(target, source) {
    const out = { ...target }
    for (const key in source) {
      const value = source[key]
      if (value && typeof value === 'object' && !Array.isArray(value)) {
        out[key] = merge(out[key] || {}, value)
      } else {
        out[key] = value
      }
    }
    return out
  }

  // `routes`-Blöcke stehen nicht nur oben im Katalog, sondern auch unter einem
  // Modul-Namensraum (z. B. CarView.routes.newCar). Deshalb wird der ganze
  // Baum durchsucht und nur der Pfad zu jedem `routes`-Zweig behalten.
  function pickRoutes(node) {
    if (!node || typeof node !== 'object' || Array.isArray(node)) return null
    let picked = null
    for (const key in node) {
      const value = node[key]
      if (key === 'routes' && value && typeof value === 'object') {
        picked = picked || {}
        picked[key] = value
        continue
      }
      const nested = pickRoutes(value)
      if (nested) {
        picked = picked || {}
        picked[key] = nested
      }
    }
    return picked
  }

  return {
    name: 'oserp-i18n-routes',
    resolveId(id) {
      if (id === VIRTUAL_ID) return RESOLVED_ID
    },
    load(id) {
      if (id !== RESOLVED_ID) return
      const messages = {}
      for (const file of collect(srcDir)) {
        const locale = file.match(LOCALE_RE)[1].toLowerCase()
        let json
        try {
          json = JSON.parse(readFileSync(file, 'utf-8'))
        } catch (e) {
          this.error(`Sprachdatei nicht lesbar: ${file} — ${e.message}`)
        }
        const routes = pickRoutes(json)
        if (!routes) continue
        messages[locale] = merge(messages[locale] || {}, routes)
        // Im Dev-Server auf Änderungen an den Sprachdateien reagieren
        this.addWatchFile(file)
      }
      return `export default ${JSON.stringify(messages)}`
    },
    handleHotUpdate({ file, server }) {
      if (!LOCALE_RE.test(file)) return
      const mod = server.moduleGraph.getModuleById(RESOLVED_ID)
      if (mod) server.moduleGraph.invalidateModule(mod)
    }
  }
}

// Nach jedem Build eine build-id.txt schreiben, damit der SSE-Server
// den Clients ein build_changed Event senden kann
function buildIdPlugin() {
  return {
    name: 'write-build-id',
    closeBundle() {
      const outPath = resolve(import.meta.dirname, 'dist/build-id.txt')
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
    i18nRoutesPlugin(),
    buildIdPlugin(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@special': fileURLToPath(new URL('./special/frontend', import.meta.url))
    }
  },
  // Der geteilte SSE-SharedWorker (sse.worker.js) wird als ES-Modul geladen
  // (new SharedWorker(..., { type: 'module' })) — daher auch im Build als ESM
  // bündeln statt als klassisches IIFE.
  worker: {
    format: 'es'
  },
  build: {
    // Der grösste Chunk beim Seitenaufruf ist der Anwendungsrumpf mit rund
    // 550 kB (130 kB gzip); alles Grössere — Sprachen, xlsx, Kalender,
    // Vuetify, Tiptap — liegt in eigenen Chunks und wird erst bei Bedarf
    // geladen. Die Vorgabe von 500 kB würde also nur den Rumpf dauerhaft
    // anmeckern. 600 kB bleibt eng genug, um echtes Wachstum zu melden.
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      output: {
        manualChunks(id) {
          // Sprachdateien: ein Chunk je Sprache statt 789 Einzeldateien.
          // Geladen wird zur Laufzeit nur die aktive Sprache (+ Fallback).
          if (!id.includes('node_modules')) {
            const locale = id.match(/[\\/]locales[\\/]([a-z-]+)\.json$/i)
            if (locale) return `locale-${locale[1].toLowerCase()}`
          }
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
          // FullCalendar (+ Headless-Core, Preact-Renderer, Temporal-Polyfill)
          if (id.includes('node_modules/@fullcalendar') ||
              id.includes('node_modules/@full-ui/') ||
              id.includes('node_modules/preact') ||
              id.includes('node_modules/temporal-polyfill')) return 'vendor-calendar'
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
