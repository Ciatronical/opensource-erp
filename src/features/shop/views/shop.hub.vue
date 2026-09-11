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

        <!-- Veröffentlichung: die Anwendung legt nur Aufträge an, geschrieben
             und gebaut wird von tools/shop-publish.php -->
        <v-card variant="outlined" class="mb-4">
            <v-card-item>
                <template #prepend>
                    <v-icon icon="mdi-cloud-upload-outline" />
                </template>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.publish.title') }}</v-card-title>
                <v-card-subtitle>{{ t('ShopView.publish.hint') }}</v-card-subtitle>
                <template #append>
                    <v-btn
                        color="primary"
                        variant="tonal"
                        size="small"
                        prepend-icon="mdi-cloud-upload-outline"
                        :loading="veroeffentlicht"
                        @click="alleVeroeffentlichen"
                    >
                        {{ t('ShopView.publish.all') }}
                    </v-btn>
                </template>
            </v-card-item>

            <v-card-text v-if="auftraege.length">
                <div class="text-caption text-medium-emphasis mb-1">
                    {{ t('ShopView.publish.open', { count: offeneAuftraege }) }}
                </div>
                <v-table density="compact">
                    <tbody>
                        <tr v-for="auftrag in auftraege" :key="auftrag.id">
                            <td style="width: 1%">
                                <v-icon
                                    size="small"
                                    :color="istOffen(auftrag) ? 'grey' : (fehlgeschlagen(auftrag) ? 'error' : 'success')"
                                    :icon="istOffen(auftrag) ? 'mdi-clock-outline' : (fehlgeschlagen(auftrag) ? 'mdi-alert-circle-outline' : 'mdi-check')"
                                />
                            </td>
                            <td class="text-caption text-no-wrap">{{ zeitpunkt(auftrag.itime) }}</td>
                            <td>{{ auftrag.function }}</td>
                            <td>{{ auftrag.partnumber }}</td>
                            <td class="text-caption">{{ auftrag.result || t('ShopView.publish.waiting') }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
            <v-card-text v-else class="text-caption text-medium-emphasis">
                {{ t('ShopView.publish.empty') }}
            </v-card-text>
        </v-card>

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
import * as toasts from '@/core/utils/toasts.js'

const { t, te, locale } = useI18n()
const router = useRouter()
const shop = useShop()

const status = ref(null)
const auftraege = ref([])
const veroeffentlicht = ref(false)

/** Wahrheitswerte kommen je nach Treiber als true oder 't' */
const istOffen = (auftrag) => auftrag.open === true || auftrag.open === 't'
const fehlgeschlagen = (auftrag) => String(auftrag.result || '').startsWith('Fehler')

const offeneAuftraege = computed(() => auftraege.value.filter(istOffen).length)

/** Zeitstempel aus der Datenbank ('2026-09-11 10:23:45.123') für die Anzeige */
function zeitpunkt(wert) {
    if (!wert) return ''
    const datum = new Date(String(wert).replace(' ', 'T'))
    return isNaN(datum) ? '' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'short', timeStyle: 'short' }).format(datum)
}

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
    auftraege.value = await shop.fetchPublishJobs() || []
}

/**
 * Nimmt alle Artikel des Shops in die Veröffentlichung auf
 *
 * Legt einen Auftrag an; die Seiten entstehen beim nächsten Lauf des
 * Veröffentlichungs-Skripts.
 */
async function alleVeroeffentlichen() {
    veroeffentlicht.value = true
    const ergebnis = await shop.publishAll()
    veroeffentlicht.value = false

    if (!shop.error.value) {
        toasts.success(ergebnis?.queued === false
            ? t('ShopView.publish.already')
            : t('ShopView.publish.queued'))
    }
    auftraege.value = await shop.fetchPublishJobs() || []
}

onMounted(laden)
</script>
