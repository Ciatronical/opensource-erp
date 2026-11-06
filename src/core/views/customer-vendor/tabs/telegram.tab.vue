<!-- src/core/views/customer-vendor/tabs/telegram.tab.vue -->
<!--
    Reiter Telegram in der Kontaktkarte: Verlauf mit dem Firmen-Bot und Antworten.
    Ist der Kunde noch nicht verknüpft, steht dort der Link auf den Bot – jede
    SMS trägt ihn ohnehin im Antwort-Hinweis.
-->
<template>
    <telegram-chat :customer-id="cvId" :src="src" height="420px" />
</template>

<script setup>
import TelegramChat from '@/core/components/telegram/telegram-chat.vue'

defineProps({
    cvId: { type: [Number, String], required: true },
    src: { type: String, default: 'C' },
})
</script>
