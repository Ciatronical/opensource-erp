<!-- src/features/shop/components/invoice-shop-status.card.vue -->
<!--
    Bestell- und Lieferstatus einer Shop-Bestellung in der Rechnungsansicht
    (dev/shop-bestellstatus.md). Komponente der Shop-Erweiterung: die
    Faktura lädt sie nur, wenn die Erweiterung aktiv ist, und die Karte
    erscheint nur, wenn die Rechnung aus einem Verkaufskanal stammt
    (getShopOrderStatus liefert sonst null).

    Recht: shop_order oder edit_shop_config — wie die Liste der Bestellungen.
-->
<template>
    <v-card v-if="stand" variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-truck-delivery-outline</v-icon>
            {{ t('ShopView.invoiceCard.title') }}
            <span class="text-body-2 text-medium-emphasis ml-2">{{ stand.channel_name }}</span>
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body">
            <v-row dense align="center">
                <v-col cols="auto" class="text-body-2">{{ t('ShopView.orders.orderStatus') }}</v-col>
                <v-col cols="auto">
                    <ShopOrderStatusChip
                        kind="order"
                        :item="stand"
                        :loading="speichert === 'order'"
                        @set="status => statusSetzen('order', status)"
                    />
                </v-col>
                <v-col cols="auto" class="text-body-2 ml-md-6">{{ t('ShopView.orders.deliveryStatus') }}</v-col>
                <v-col cols="auto">
                    <ShopOrderStatusChip
                        kind="delivery"
                        :item="stand"
                        :loading="speichert === 'delivery'"
                        @set="status => statusSetzen('delivery', status)"
                    />
                </v-col>
            </v-row>
            <div v-if="stand.delivery_notified" class="text-caption text-medium-emphasis mt-2">
                {{ t('ShopView.invoiceCard.notified', {
                    status: t(`ShopView.deliveryStatus.${stand.delivery_notified}`),
                    date: datum(stand.delivery_notified_mtime),
                }) }}
            </div>
            <div v-if="stand.ebay_order_id" class="text-caption text-medium-emphasis mt-2">
                {{ t('ShopView.invoiceCard.ebayHint') }}
            </div>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useShop } from '@/features/shop/composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'
import ShopOrderStatusChip from './shop-order-status.chip.vue'

const props = defineProps({
    /** Rechnung (ar.id) */
    arId: { type: [Number, String], default: null },
})

const { t } = useI18n()
const oserp = oserpStore()
const shop = useShop()

/** Zeile aus getShopOrderStatus, null = keine Shop-Bestellung */
const stand = ref(null)
const speichert = ref('')

const darf = () => oserp.checkPermission('shop_order') || oserp.checkPermission('edit_shop_config')

async function laden() {
    stand.value = null
    const id = Number(props.arId)
    if (!id || !darf()) {
        return
    }
    stand.value = (await shop.fetchOrderStatus(id))?.results ?? null
}

/** Datum in der Schreibweise der Oberfläche — formatiert wird in Vue, nicht im Backend */
function datum(wert) {
    if (!wert) return ''
    const d = new Date(wert)
    return isNaN(d) ? String(wert) : d.toLocaleDateString()
}

/**
 * Setzt einen Status von Hand ('' = zurück an die Automatik)
 *
 * Die Antwort bringt beide Status — der Bestellstatus hängt vom Lieferstatus
 * ab — und ob eine Mail an den Kunden ging; danach wird neu geladen, damit
 * auch die Zeile „zuletzt gemeldet“ stimmt.
 */
async function statusSetzen(kind, status) {
    speichert.value = kind
    try {
        const ergebnis = await shop.setOrderStatus(Number(props.arId), kind, status)
        if (shop.error.value || !ergebnis) {
            toasts.error(shop.error.value || t('ShopView.errors.API_ERROR'))
            return
        }
        if (ergebnis.mail === 'sent') {
            toasts.success(t('ShopView.orders.mailSent'))
        } else if (ergebnis.mail === 'failed') {
            toasts.warning(t('ShopView.orders.mailFailed'))
        } else {
            toasts.success(t('ShopView.orders.statusSaved'))
        }
        await laden()
    } finally {
        speichert.value = ''
    }
}

watch(() => props.arId, laden, { immediate: true })
</script>

<style scoped>
/* Wie die Karten der Faktura (faktura.view.vue, payment.section.card.vue) —
   deren Stile sind scoped und reichen nicht in diese Komponente */
.faktura-card {
    border-radius: 8px;
}

.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #f5f5f5;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}

.faktura-card__body {
    padding: 16px !important;
}
</style>
