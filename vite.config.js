// vite.config.js
import { fileURLToPath, URL } from 'node:url'
import { writeFileSync, mkdirSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
// vue-devtools bei Ärger, erst mal weglassen
import vueDevTools from 'vite-plugin-vue-devtools'

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
    vueDevTools(),
    buildIdPlugin(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@special': fileURLToPath(new URL('./special/frontend', import.meta.url))
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
    }
  }
})
