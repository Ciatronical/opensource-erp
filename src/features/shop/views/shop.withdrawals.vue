<!-- src/features/shop/views/shop.withdrawals.vue -->
<!--
    Eingegangene Widerrufe (§ 356a BGB).

    Die Bridge schrieb sie in eine Logdatei; hier stehen sie in der Datenbank
    und lassen sich als bearbeitet vormerken. Gelöscht wird nichts — ein
    Vorgang, der nachweisbar sein muss, bleibt stehen.
-->
<template>
    <NavbarView />
    <v-container fluid>

        <v-row align="center" class="mb-3">
            <v-col>
                <h1 class="text-h5">
                    <v-icon start>mdi-undo-variant</v-icon>
                    {{ t('ShopView.withdrawals.title') }}
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

        <v-row align="center" class="mb-2">
            <v-col cols="auto">
                <v-checkbox
                    v-model="nurOffene"
                    :label="t('ShopView.withdrawals.onlyOpen')"
                    density="compact"
                    hide-details
                    @update:model-value="laden"
                />
            </v-col>
            <v-col cols="auto">
                <v-btn variant="text" size="small" :loading="shop.loading.value" @click="laden">
                    <v-icon start>mdi-refresh</v-icon>
                    {{ t('ShopView.common.reload') }}
                </v-btn>
            </v-col>
        </v-row>

        <v-data-table
            :headers="spalten"
            :items="widerrufe"
            :loading="shop.loading.value"
            :no-data-text="t('ShopView.withdrawals.empty')"
            density="compact"
            items-per-page="25"
            show-expand
            v-model:expanded="ausgeklappt"
            item-value="id"
        >
            <template #item.itime="{ item }">
                {{ zeitpunkt(item.itime) }}
            </template>
            <template #item.ordernumber="{ item }">
                {{ item.ordernumber || '—' }}
                <v-tooltip v-if="item.ordernumber && !item.ar_id" location="top">
                    <template #activator="{ props }">
                        <v-icon v-bind="props" size="small" color="warning" class="ml-1">
                            mdi-help-circle-outline
                        </v-icon>
                    </template>
                    {{ t('ShopView.withdrawals.unmatched') }}
                </v-tooltip>
            </template>
            <template #item.processed="{ item }">
                <v-chip :color="item.processed ? 'success' : 'warning'" size="small" variant="tonal">
                    {{ item.processed ? t('ShopView.withdrawals.done') : t('ShopView.withdrawals.open') }}
                </v-chip>
            </template>
            <template #item.aktionen="{ item }">
                <v-btn
                    :icon="item.processed ? 'mdi-undo' : 'mdi-check'"
                    variant="text"
                    size="small"
                    :title="item.processed ? t('ShopView.withdrawals.reopen') : t('ShopView.withdrawals.markDone')"
                    @click="umschalten(item)"
                />
            </template>
            <template #expanded-row="{ columns, item }">
                <tr>
                    <td :colspan="columns.length" class="py-3">
                        <div class="text-body-2">
                            <strong>{{ t('ShopView.withdrawals.email') }}:</strong> {{ item.email || '—' }}
                        </div>
                        <div class="text-body-2 mt-2">
                            <strong>{{ t('ShopView.withdrawals.reason') }}:</strong>
                        </div>
                        <div class="text-body-2 text-medium-emphasis" style="white-space: pre-wrap">{{
                            item.reason || t('ShopView.withdrawals.noReason')
                        }}</div>
                    </td>
                </tr>
            </template>
        </v-data-table>

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

const widerrufe = ref([])
const nurOffene = ref(false)
const ausgeklappt = ref([])

const spalten = computed(() => [
    { title: t('ShopView.withdrawals.received'), key: 'itime' },
    { title: t('ShopView.withdrawals.name'), key: 'name' },
    { title: t('ShopView.withdrawals.order'), key: 'ordernumber' },
    { title: t('ShopView.withdrawals.state'), key: 'processed' },
    { title: '', key: 'aktionen', sortable: false, align: 'end' },
])

function zeitpunkt(wert) {
    if (!wert) return ''
    const d = new Date(wert)
    return isNaN(d) ? String(wert) : d.toLocaleString()
}

async function laden() {
    widerrufe.value = (await shop.fetchWithdrawals({ open: nurOffene.value }))?.results ?? []
}

async function umschalten(zeile) {
    await shop.setWithdrawalProcessed(zeile.id, !zeile.processed)
    await laden()
}

onMounted(laden)
</script>
