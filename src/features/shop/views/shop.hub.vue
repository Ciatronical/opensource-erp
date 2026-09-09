<!-- src/features/shop/views/shop.hub.vue -->
<!--
    Übersicht der Shop-Erweiterung: was noch einzurichten ist, ein paar
    Kennzahlen und die Wege zu den Einzelansichten.

    Die Einrichtungsprüfung steht bewusst oben und nicht in den Einstellungen:
    dort sieht man die einzelnen Felder, aber nicht, ob das Zusammenspiel
    stimmt — ob es den Versandartikel wirklich gibt zum Beispiel.
-->
<template>
    <NavbarView />
    <v-container fluid>

        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">
                    <v-icon start>mdi-storefront</v-icon>
                    {{ t('ShopView.menu.title') }}
                </h1>
            </v-col>
            <v-col cols="auto">
                <v-btn variant="text" size="small" :loading="shop.loading.value" @click="laden">
                    <v-icon start>mdi-refresh</v-icon>
                    {{ t('ShopView.common.reload') }}
                </v-btn>
            </v-col>
        </v-row>

        <v-alert v-if="shop.error.value" type="error" variant="tonal" density="compact" class="mb-3">
            {{ shop.error.value }}
        </v-alert>

        <!-- Einrichtung -->
        <v-alert
            v-if="status && !status.ready"
            type="warning"
            variant="tonal"
            border="start"
            class="mb-4"
        >
            <v-alert-title>{{ t('ShopView.status.notReady') }}</v-alert-title>
            <div class="text-body-2 mt-2">{{ t('ShopView.status.notReadyHint') }}</div>
            <ul class="mt-2">
                <li v-for="punkt in status.blocking" :key="punkt">
                    {{ feldName(punkt) }}
                </li>
            </ul>
        </v-alert>

        <v-alert
            v-else-if="status"
            type="success"
            variant="tonal"
            density="compact"
            class="mb-4"
            :text="t('ShopView.status.ready')"
        />

        <v-alert
            v-if="status && status.hints.length"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
        >
            <div class="text-body-2">{{ t('ShopView.status.hints') }}</div>
            <ul class="mt-1">
                <li v-for="punkt in status.hints" :key="punkt">
                    {{ feldName(punkt) }}
                </li>
            </ul>
        </v-alert>

        <!-- Kennzahlen -->
        <v-row v-if="status" class="mb-2">
            <v-col cols="12" sm="4" v-for="kachel in kennzahlen" :key="kachel.key">
                <v-card variant="tonal" density="compact">
                    <v-card-text class="d-flex align-center ga-3">
                        <v-icon size="32" :icon="kachel.icon" />
                        <div>
                            <div class="text-h6">{{ kachel.wert }}</div>
                            <div class="text-caption">{{ kachel.titel }}</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Wege -->
        <v-row>
            <v-col cols="12" sm="6" md="4" v-for="ziel in ziele" :key="ziel.name">
                <v-card
                    variant="outlined"
                    hover
                    @click="router.push({ name: ziel.name })"
                >
                    <v-card-item>
                        <template #prepend>
                            <v-icon size="28" :icon="ziel.icon" />
                        </template>
                        <v-card-title>{{ ziel.titel }}</v-card-title>
                        <v-card-subtitle>{{ ziel.text }}</v-card-subtitle>
                    </v-card-item>
                </v-card>
            </v-col>
        </v-row>

    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useShop } from '@/features/shop/composables/useShop.js'

const { t, te } = useI18n()
const router = useRouter()
const shop = useShop()

const status = ref(null)

/**
 * Übersetzt einen Einstellungsschlüssel in den Namen des Feldes
 *
 * Das Backend meldet, welche Einstellung fehlt (shop_public_key); die
 * Feldnamen stehen bereits unter crm_fields (shopPublicKey), weil der
 * Einstellungen-Tab sie braucht. Hier wird nur umgeschrieben.
 *
 * Nicht jeder gemeldete Punkt ist eine Einstellung — 'parts_ext' etwa meint
 * fehlende Artikelangaben. Für solche bleibt der Schlüssel stehen, statt eine
 * leere Zeile zu zeigen.
 */
function feldName(schluessel) {
    const key = 'crm_fields.' + schluessel.replace(/_([a-z])/g, (_, z) => z.toUpperCase())
    return te(key) ? t(key) : schluessel
}

const kennzahlen = computed(() => {
    if (!status.value) return []
    return [
        {
            key: 'parts',
            icon: 'mdi-tag-multiple',
            wert: status.value.counts.parts_with_shop_data,
            titel: t('ShopView.status.partsWithShopData'),
        },
        {
            key: 'carts',
            icon: 'mdi-cart-outline',
            wert: status.value.counts.carts,
            titel: t('ShopView.status.carts'),
        },
        {
            key: 'sessions',
            icon: 'mdi-account-clock-outline',
            wert: status.value.counts.sessions,
            titel: t('ShopView.status.sessions'),
        },
    ]
})

const ziele = computed(() => [
    {
        name: 'shop-orders',
        icon: 'mdi-receipt-text-outline',
        titel: t('ShopView.orders.title'),
        text: t('ShopView.orders.subtitle'),
    },
    {
        name: 'shop-withdrawals',
        icon: 'mdi-undo-variant',
        titel: t('ShopView.withdrawals.title'),
        text: t('ShopView.withdrawals.subtitle'),
    },
])

async function laden() {
    status.value = await shop.fetchStatus()
}

onMounted(laden)
</script>
