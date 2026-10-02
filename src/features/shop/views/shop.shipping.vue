<!-- src/features/shop/views/shop.shipping.vue -->
<!--
    Versandarten: eigene Ansicht im Shop-Menü (dev/shop-versand.md).

    Länderzonen, Versandarten mit Anbieter, Versandartikel, Rang und Grenzen
    sowie die Preisstufen je Kanal, Zone, Gewicht und Stückzahl. Die Karte
    selbst ist shop-shipping.config.vue; bis hierher stand sie in der
    Firmenkonfiguration (Reiter Shop). Ob die Preise brutto oder netto
    gelten, liest sie aus getShopShipping.

    Recht: edit_shop_config — wie die Versand-API (getShopShipping und Co.).
-->
<template>
    <NavbarView />
    <v-container fluid>

        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">
                    <v-icon start>mdi-truck-delivery-outline</v-icon>
                    {{ t('ShopView.shippingConfig.title') }}
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

        <ShopShippingConfig v-else :mit-ueberschrift="false" />
    </v-container>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import ShopShippingConfig from '../components/shop-shipping.config.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t } = useI18n()
const router = useRouter()
const oserp = oserpStore()

/** Ohne das Recht lehnt die Versand-API ab — die Seite sagt es gleich */
const darf = computed(() => oserp.checkPermission('edit_shop_config'))
</script>
