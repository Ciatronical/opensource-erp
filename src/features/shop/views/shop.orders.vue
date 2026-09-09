<!-- src/features/shop/views/shop.orders.vue -->
<!--
    Bestellungen aus dem Shop, mit ihrem Zahlungsstand.

    Der zweite Reiter zeigt die schwebenden PayPal-Zahlungen. Solange niemand
    hinsieht, bleibt eine schwebende Zahlung offen stehen — das ist der Ort,
    an dem jemand hinsieht.
-->
<template>
    <NavbarView />
    <v-container fluid>

        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">
                    <v-icon start>mdi-receipt-text-outline</v-icon>
                    {{ t('ShopView.orders.title') }}
                </h1>
            </v-col>
            <v-col cols="auto">
                <v-btn variant="text" size="small" @click="router.push({ name: 'shop-overview' })">
                    <v-icon start>mdi-arrow-left</v-icon>
                    {{ t('ShopView.menu.title') }}
                </v-btn>
            </v-col>
        </v-row>

        <v-alert v-if="shop.error.value" type="error" variant="tonal" density="compact" class="mb-3">
            {{ shop.error.value }}
        </v-alert>

        <v-tabs v-model="reiter" class="mb-3">
            <v-tab value="orders">{{ t('ShopView.orders.title') }}</v-tab>
            <v-tab value="pending">
                {{ t('ShopView.payments.title') }}
                <v-badge v-if="schwebende.length" :content="schwebende.length" color="warning" inline />
            </v-tab>
        </v-tabs>

        <v-window v-model="reiter">

            <!-- Bestellungen -->
            <v-window-item value="orders">
                <v-row align="center" class="mb-2">
                    <v-col cols="auto">
                        <v-checkbox
                            v-model="nurOffene"
                            :label="t('ShopView.orders.onlyUnpaid')"
                            density="compact"
                            hide-details
                            @update:model-value="bestellungenLaden"
                        />
                    </v-col>
                    <v-col cols="auto">
                        <v-btn variant="text" size="small" :loading="shop.loading.value" @click="bestellungenLaden">
                            <v-icon start>mdi-refresh</v-icon>
                            {{ t('ShopView.common.reload') }}
                        </v-btn>
                    </v-col>
                </v-row>

                <v-data-table
                    :headers="spalten"
                    :items="bestellungen"
                    :loading="shop.loading.value"
                    :no-data-text="t('ShopView.orders.empty')"
                    density="compact"
                    items-per-page="25"
                >
                    <template #item.transdate="{ item }">
                        {{ datum(item.transdate) }}
                    </template>
                    <template #item.customer="{ item }">
                        {{ item.customer }}
                        <v-chip v-if="istWahr(item.guest)" size="x-small" variant="tonal" class="ml-1">
                            {{ t('ShopView.orders.guest') }}
                        </v-chip>
                    </template>
                    <template #item.amount="{ item }">
                        {{ betrag(item.amount) }} {{ item.currency }}
                    </template>
                    <template #item.payment_status="{ item }">
                        <v-chip :color="zahlungFarbe(item.payment_status)" size="small" variant="tonal">
                            {{ zahlungText(item) }}
                        </v-chip>
                    </template>
                    <template #item.aktionen="{ item }">
                        <v-btn
                            icon="mdi-open-in-new"
                            variant="text"
                            size="small"
                            :title="t('ShopView.orders.openInvoice')"
                            @click="rechnungOeffnen(item)"
                        />
                    </template>
                </v-data-table>
            </v-window-item>

            <!-- Schwebende Zahlungen -->
            <v-window-item value="pending">
                <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                    {{ t('ShopView.payments.explain') }}
                </v-alert>

                <v-row align="center" class="mb-2">
                    <v-col cols="auto">
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="small"
                            :loading="abgleichLaeuft"
                            :disabled="!schwebende.length"
                            @click="abgleichen"
                        >
                            <v-icon start>mdi-sync</v-icon>
                            {{ t('ShopView.payments.reconcile') }}
                        </v-btn>
                    </v-col>
                    <v-col cols="auto">
                        <v-btn variant="text" size="small" @click="schwebendeLaden">
                            <v-icon start>mdi-refresh</v-icon>
                            {{ t('ShopView.common.reload') }}
                        </v-btn>
                    </v-col>
                </v-row>

                <v-data-table
                    :headers="spaltenSchwebend"
                    :items="schwebende"
                    :loading="shop.loading.value"
                    :no-data-text="t('ShopView.payments.empty')"
                    density="compact"
                >
                    <template #item.payment_mtime="{ item }">
                        {{ datum(item.payment_mtime) }}
                    </template>
                    <template #item.amount="{ item }">
                        {{ betrag(item.amount) }}
                    </template>
                </v-data-table>

                <v-card v-if="abgleichErgebnis.length" variant="outlined" class="mt-4">
                    <v-card-title class="text-subtitle-1">{{ t('ShopView.payments.result') }}</v-card-title>
                    <v-list density="compact">
                        <v-list-item v-for="(zeile, i) in abgleichErgebnis" :key="i">
                            <v-list-item-title>
                                {{ zeile.invnumber }} — {{ zeile.result }}
                                <span v-if="zeile.detail" class="text-caption text-medium-emphasis">
                                    ({{ zeile.detail }})
                                </span>
                            </v-list-item-title>
                        </v-list-item>
                    </v-list>
                    <v-card-text class="text-caption">{{ t('ShopView.payments.bookingHint') }}</v-card-text>
                </v-card>
            </v-window-item>

        </v-window>
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useShop } from '@/features/shop/composables/useShop.js'

const { t } = useI18n()
const router = useRouter()
const shop = useShop()

const reiter = ref('orders')
const nurOffene = ref(false)
const bestellungen = ref([])
const schwebende = ref([])
const abgleichErgebnis = ref([])
const abgleichLaeuft = ref(false)

const spalten = computed(() => [
    { title: t('ShopView.orders.invnumber'), key: 'invnumber' },
    { title: t('ShopView.orders.date'), key: 'transdate' },
    { title: t('ShopView.orders.customer'), key: 'customer' },
    { title: t('ShopView.orders.positions'), key: 'positions', align: 'end' },
    { title: t('ShopView.orders.amount'), key: 'amount', align: 'end' },
    { title: t('ShopView.orders.payment'), key: 'payment_status' },
    { title: '', key: 'aktionen', sortable: false, align: 'end' },
])

const spaltenSchwebend = computed(() => [
    { title: t('ShopView.orders.invnumber'), key: 'invnumber' },
    { title: t('ShopView.orders.customer'), key: 'customer' },
    { title: t('ShopView.orders.amount'), key: 'amount', align: 'end' },
    { title: t('ShopView.payments.since'), key: 'payment_mtime' },
    { title: t('ShopView.payments.reason'), key: 'payment_reason' },
])

/** PostgreSQL liefert Wahrheitswerte je nach Weg als 't' oder als Boolean */
function istWahr(wert) {
    return wert === true || wert === 't' || wert === '1' || wert === 1
}

/** Datum in der Schreibweise der Oberfläche — formatiert wird in Vue, nicht im Backend */
function datum(wert) {
    if (!wert) return ''
    const d = new Date(wert)
    return isNaN(d) ? String(wert) : d.toLocaleDateString()
}

function betrag(wert) {
    const zahl = Number(wert)
    return isNaN(zahl) ? String(wert ?? '') : zahl.toLocaleString(undefined, {
        minimumFractionDigits: 2, maximumFractionDigits: 2,
    })
}

function zahlungFarbe(status) {
    if (status === 'COMPLETED') return 'success'
    if (status === 'PENDING') return 'warning'
    if (status) return 'error'
    return 'default'
}

/**
 * Zeilen aus der Zeit vor der Zahlungsstatus-Spalte haben keinen Status; für
 * sie gilt wie früher die Payer-Id.
 */
function zahlungText(zeile) {
    if (zeile.payment_status === 'COMPLETED') return t('ShopView.payments.paid')
    if (zeile.payment_status === 'PENDING') return t('ShopView.payments.pending')
    if (zeile.payment_status) return t('ShopView.payments.failed')
    return zeile.paypal ? t('ShopView.payments.paid') : t('ShopView.payments.open')
}

function rechnungOeffnen(zeile) {
    router.push({ name: 'faktura-invoice-view', params: { id: zeile.ar_id } })
}

async function bestellungenLaden() {
    bestellungen.value = (await shop.fetchOrders({ open: nurOffene.value }))?.results ?? []
}

async function schwebendeLaden() {
    schwebende.value = (await shop.fetchPendingPayments())?.results ?? []
}

async function abgleichen() {
    abgleichLaeuft.value = true
    try {
        abgleichErgebnis.value = (await shop.reconcilePayments())?.results ?? []
        await schwebendeLaden()
    } finally {
        abgleichLaeuft.value = false
    }
}

onMounted(async () => {
    await bestellungenLaden()
    await schwebendeLaden()
})
</script>
