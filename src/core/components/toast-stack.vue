<!-- core/components/toast-stack.vue -->
<!--
  Zeigt die Einblendungen aus core/utils/toasts.js oben rechts an, mehrere
  untereinander. Unabhängig von SweetAlert2, damit ein offener Dialog stehen
  bleibt, wenn eine Meldung eintrifft.

  Die Anzeigedauer misst der Fortschrittsbalken selbst: seine CSS-Animation
  läuft so lange wie der Timer, an ihrem Ende verschwindet die Einblendung.
  Steht die Maus darauf, hält die Animation an — und damit auch der Timer.
-->
<template>
  <div class="toast-stack" aria-live="polite">
    <TransitionGroup name="toast">
      <div
        v-for="item in toastItems"
        :key="item.id"
        class="toast-item elevation-6"
        :class="[`toast-${item.icon}`, { 'toast-clickable': item.onClick }]"
        :role="item.icon === 'error' ? 'alert' : 'status'"
        @click="onClick(item)"
      >
        <v-icon class="toast-icon" :icon="ICONS[item.icon] || ICONS.info" size="22" />
        <div class="toast-body">
          <div class="toast-title">{{ item.title }}</div>
          <div v-if="item.text" class="toast-text">{{ item.text }}</div>
        </div>
        <v-btn
          class="toast-close"
          icon="mdi-close"
          size="x-small"
          variant="text"
          density="comfortable"
          :aria-label="t('Toast.close')"
          @click.stop="closeToast(item.id)"
        />
        <div
          class="toast-timer"
          :class="{ 'toast-timer-hidden': !item.timerProgressBar }"
          :style="{ animationDuration: `${item.timer}ms` }"
          @animationend="closeToast(item.id)"
        />
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { toastItems, closeToast } from '@/core/utils/toasts.js'

const { t } = useI18n()

const ICONS = {
  success: 'mdi-check-circle',
  error: 'mdi-alert-circle',
  warning: 'mdi-alert',
  info: 'mdi-information',
}

function onClick(item) {
  if (!item.onClick) return
  closeToast(item.id)
  item.onClick()
}
</script>

<style scoped>
/* Unter der Navigationsleiste; über allen Dialogen (Vuetify, SweetAlert2) */
.toast-stack {
  position: fixed;
  top: 64px;
  right: 16px;
  z-index: 100000;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
  max-width: calc(100vw - 32px);
  pointer-events: none;
}

.toast-item {
  --toast-color: var(--v-theme-info);
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  width: 25rem;
  max-width: 100%;
  padding: 10px 6px 12px 14px;
  overflow: hidden;
  border-left: 4px solid rgb(var(--toast-color));
  border-radius: 6px;
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  pointer-events: auto;
}

.toast-success { --toast-color: var(--v-theme-success); }
.toast-error   { --toast-color: var(--v-theme-error); }
.toast-warning { --toast-color: var(--v-theme-warning); }

.toast-clickable { cursor: pointer; }

.toast-icon {
  flex: none;
  margin-top: 1px;
  color: rgb(var(--toast-color));
}

.toast-body {
  flex: 1;
  min-width: 0;
  padding-top: 2px;
}

.toast-title {
  font-size: 0.95rem;
  font-weight: 500;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.toast-clickable .toast-title { color: rgb(var(--toast-color)); }

.toast-text {
  margin-top: 3px;
  font-size: 0.88rem;
  line-height: 1.4;
  opacity: 0.85;
  overflow-wrap: anywhere;
}

.toast-close {
  flex: none;
  margin-top: -2px;
  opacity: 0.6;
}

/* Fortschrittsbalken und Timer zugleich */
.toast-timer {
  position: absolute;
  left: 0;
  bottom: 0;
  height: 3px;
  width: 100%;
  background: rgb(var(--toast-color));
  transform-origin: left;
  animation-name: toast-countdown;
  animation-timing-function: linear;
  animation-fill-mode: forwards;
}

.toast-timer-hidden { opacity: 0; }

.toast-item:hover .toast-timer { animation-play-state: paused; }

@keyframes toast-countdown {
  from { transform: scaleX(1); }
  to   { transform: scaleX(0); }
}

.toast-enter-active,
.toast-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.toast-enter-from,
.toast-leave-to { opacity: 0; transform: translateX(24px); }
.toast-move { transition: transform 0.2s ease; }
</style>
