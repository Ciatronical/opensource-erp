<!-- src/features/shop/components/shop-order-status.chip.vue -->
<!--
    Bestell- oder Lieferstatus einer Shop-Bestellung als Chip mit Auswahl
    (dev/shop-bestellstatus.md). Ein Klick öffnet die Liste: „Automatisch“
    mit dem abgeleiteten Wert oder einer der Werte von Hand. Gespeichert wird
    beim Aufrufer — die Komponente meldet nur die Wahl (set, '' = automatisch).

    item ist eine Zeile wie aus getShopOrders: <kind>_status, <kind>_auto,
    <kind>_manual.
-->
<template>
    <v-menu :disabled="disabled">
        <template #activator="{ props: menue }">
            <v-chip
                v-bind="menue"
                :color="art.farben[status] || 'default'"
                size="small"
                variant="tonal"
                :disabled="disabled"
                :title="vonHand ? t('ShopView.orders.statusManual') : t('ShopView.orders.statusAutomatic')"
                @click.stop
            >
                <v-progress-circular v-if="loading" indeterminate size="12" width="2" class="mr-1" />
                <v-icon v-else-if="vonHand" start size="x-small">mdi-hand-back-right-outline</v-icon>
                {{ t(`ShopView.${kind}Status.${status}`) }}
            </v-chip>
        </template>
        <v-list density="compact">
            <v-list-item :active="!vonHand" @click="emit('set', '')">
                <v-list-item-title>
                    {{ t('ShopView.orders.statusAuto', { status: t(`ShopView.${kind}Status.${item[`${kind}_auto`]}`) }) }}
                </v-list-item-title>
            </v-list-item>
            <v-divider />
            <v-list-item
                v-for="wert in art.werte"
                :key="wert"
                :active="vonHand && status === wert"
                @click="emit('set', wert)"
            >
                <v-list-item-title>{{ t(`ShopView.${kind}Status.${wert}`) }}</v-list-item-title>
            </v-list-item>
        </v-list>
    </v-menu>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import shopOrderStatus from './shopOrderStatus.js'

const props = defineProps({
    /** 'order' (Bestellstatus) oder 'delivery' (Lieferstatus) */
    kind: { type: String, required: true },
    item: { type: Object, required: true },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['set'])

const { t } = useI18n()

const art = computed(() => shopOrderStatus[props.kind])
const status = computed(() => props.item[`${props.kind}_status`])

/** PostgreSQL liefert Wahrheitswerte je nach Weg als 't' oder als Boolean */
const vonHand = computed(() => [true, 't', 'true', '1', 1].includes(props.item[`${props.kind}_manual`]))
</script>
