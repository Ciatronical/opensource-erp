import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
export default defineConfig({ root: __dirname, plugins: [vue(), vuetify({ autoImport: false })], build: { outDir: 'dist', emptyOutDir: true } })
