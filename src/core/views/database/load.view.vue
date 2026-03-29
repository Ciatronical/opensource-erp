<template>
  <NavbarView />
  <div class="pa-4">
    <h1>{{ t('LoadDatabaseView.title') }}</h1>
    <p>{{ t('LoadDatabaseView.description') }}</p>
    <v-btn variant="outlined" @click="loadDatabase">{{ t('LoadDatabaseView.loadButton') }}</v-btn>
  </div>
</template>

<script setup>
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { ref } from 'vue'

const { t } = useI18n()
const oserp = oserpStore()
</script setup>
