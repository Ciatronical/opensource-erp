<!-- src/features/shop/views/shop.channels.vue -->
<!--
    Verkaufskanäle: eigene Ansicht im Shop-Menü (dev/shop-mehrere-kanaele.md).

    Je Kanal Preisvorgaben, Freigrenze, Lieferländer und — aufklappbar — die
    Einstellungen der Instanz: Webseite, Shop-Schlüssel, PayPal, HugoCMS beim
    HugoShop, Zugang und Richtlinien beim eBay-Kanal. Dazu Kanäle anlegen und
    löschen. Die Karte selbst ist shop-channels.config.vue; bis hierher stand
    sie in der Firmenkonfiguration (Reiter Shop).

    Recht: edit_shop_config — wie die Kanal-API (getShopChannels und Co.).
-->
<template>
    <NavbarView />
    <v-container fluid>

        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">
                    <v-icon start>mdi-store-cog</v-icon>
                    {{ t('ShopView.channelConfig.title') }}
                </h1>
            </v-col>
            <v-col cols="auto">
                <v-btn variant="text" size="small" @click="router.push({ name: 'shop-overview' })">
                    <v-icon start>mdi-arrow-left</v-icon>
                    {{ t('ShopView.menu.title') }}
                </v-btn>
            </v-col>
        </v-row>

        <v-alert v-if="!darf" type="warning" variant="tonal" density="compact">
            {{ t('ShopView.errors.NO_PERMISSION') }}
        </v-alert>

        <!-- ?channel=… (Kennzahlen der Übersicht): diesen Kanal öffnen -->
        <ShopChannelsConfig v-else :mit-ueberschrift="false" :fokus="Number(route.query.channel) || 0" />
    </v-container>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import ShopChannelsConfig from '../components/shop-channels.config.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const oserp = oserpStore()

/** Ohne das Recht lehnt die Kanal-API ab — die Seite sagt es gleich */
const darf = computed(() => oserp.checkPermission('edit_shop_config'))
</script>
