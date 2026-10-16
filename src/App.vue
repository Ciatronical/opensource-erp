<!-- App.vue -->
<template>
  <v-app>
    <v-main>
      <!-- Ladebildschirm bis der Router die erste Navigation abgeschlossen hat -->
      <div v-if="!appReady" class="app-loading">
        <v-icon size="64" color="primary" class="mb-4">mdi-timer-sand</v-icon>
        <div class="text-h6 text-grey-darken-1">{{ t('App.loading') }}</div>
      </div>
      <!-- Schlüssel aus Mandant und Pfad: nach dem Firmenwechsel landet der
           Benutzer oft auf derselben Seite (Startansicht). Mit dem Pfad allein
           behielte Vue die Komponente samt den Daten des alten Mandanten -->
      <router-view v-else :key="`${oserp.session.client}:${route.path}`" />
    </v-main>
    <ToastStack />
  </v-app>
</template>

<script setup>
import { ref, provide } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useUserPrefs } from '@/core/composables/useUserPrefs.js'
import ToastStack from '@/core/components/toast-stack.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t } = useI18n()
useUserPrefs()
const router = useRouter()
const route = useRoute()
const oserp = oserpStore()
const appReady = ref(false)

provide('appReady', appReady)

router.isReady().then(() => {
  appReady.value = true
})
</script>

<style scoped>
.app-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100vh;
}
</style>
