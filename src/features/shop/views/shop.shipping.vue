<!-- src/features/shop/views/shop.shipping.vue -->
<!--
    Versandarten: eigene Ansicht im Shop-Menü (dev/shop-versand.md).

    Länderzonen, Versandarten mit Anbieter, Versandartikel, Rang und Grenzen
    sowie die Preisstufen je Kanal, Zone, Gewicht und Stückzahl; zwischen
    Länderzonen und Versandarten die Lieferzeiten in Tagen je Lieferbedingung
    (shop-delivery-times.config.vue). Die Karte
    selbst ist shop-shipping.config.vue; bis hierher stand sie in der
    Firmenkonfiguration (Reiter Shop). Ob die Preise brutto oder netto
    gelten, liest sie aus getShopShipping.

    Recht: edit_shop_config — wie die Versand-API (getShopShipping und Co.).
-->
<template>
    <NavbarView />
    <!-- Seitennavigation wie in der Firmenkonfiguration (shop-nav-layout.vue) -->
    <ShopNavLayout :ziele="darf ? ziele : []">
        <template #kopf>
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
        </template>

        <v-alert v-if="!darf" type="warning" variant="tonal" density="compact">
            {{ t('ShopView.errors.NO_PERMISSION') }}
        </v-alert>

        <ShopShippingConfig v-else ref="versand" :mit-ueberschrift="false">
            <!-- Lieferzeit in Tagen je Lieferbedingung: unter den
                 Länderzonen, vor den Versandarten -->
            <template #nach-zonen>
                <div id="versand-lieferzeiten" class="versand-anker">
                    <ShopDeliveryTimesConfig />
                </div>
            </template>
        </ShopShippingConfig>
    </ShopNavLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import ShopNavLayout from '../components/shop-nav-layout.vue'
import ShopShippingConfig from '../components/shop-shipping.config.vue'
import ShopDeliveryTimesConfig from '../components/shop-delivery-times.config.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t } = useI18n()
const router = useRouter()
const oserp = oserpStore()

/** Ohne das Recht lehnt die Versand-API ab — die Seite sagt es gleich */
const darf = computed(() => oserp.checkPermission('edit_shop_config'))

// ── Seitennavigation ──

/** Versandkomponente: nennt ihre Länderzonen und Versandarten (gliederung) */
const versand = ref(null)

/** Sprungziele in der Reihenfolge der Ansicht: Abschnitte, darunter ihre Einträge */
const ziele = computed(() => {
    const gliederung = versand.value?.gliederung || { zonen: [], methoden: [] }
    const zonen = t('ShopView.shippingConfig.zones')
    const arten = t('ShopView.shippingConfig.methods')
    const eintrag = gruppe => e => ({ id: e.id, title: e.title || '—', ebene: 1, inaktiv: e.active === false, gruppe })
    return [
        { id: 'versand-zonen', title: zonen, icon: 'mdi-earth' },
        ...gliederung.zonen.map(eintrag(zonen)),
        { id: 'versand-lieferzeiten', title: t('ShopView.deliveryTimes.title'), icon: 'mdi-truck-fast-outline' },
        { id: 'versand-arten', title: arten, icon: 'mdi-truck-delivery-outline' },
        ...gliederung.methoden.map(eintrag(arten)),
    ]
})
</script>

<style scoped>
.versand-anker {
    scroll-margin-top: var(--oserp-gap-lg);
}
</style>
