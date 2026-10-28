<!-- src/features/shop/views/shop.ebay.vue -->
<!--
    eBay-Board (dev/shop-ebay.md): der Arbeitsplatz des Verkaufskanals eBay.

    Prüfen     Verkäufe, deren Käufer nicht eindeutig ist — Kandidaten mit
               Gründen, eine Entscheidung legt die Rechnung an. Eindeutige
               rechnet der Abruf selbst ab.
    Verkäufe   alle Bestellungen mit Zahlung, Rechnung und Versand; Versand
               melden schickt die Sendungsnummer an eBay.
    Angebote   alles, was im eBay-Konto läuft, mit Zuordnung zum Artikel.
    Im Kanal   von OSERP eingestellte und übernommene Artikel, Produktseiten,
               Vorschläge, was sich zu verkaufen lohnt.

    „Mit eBay verbinden“ öffnet die Zustimmung bei eBay im Popup; der
    Rücksprung (backend/webhook/ebay-oauth.php) meldet sich per postMessage.

    Rechte: lesen mit shop_order, shop_part_edit oder edit_shop_config;
    abrechnen mit shop_order oder edit_shop_config; Angebote und Seiten mit
    shop_part_edit oder edit_shop_config; verbinden mit edit_shop_config.
-->
<template>
    <NavbarView />
    <v-container fluid>
        <page-header :title="t('ShopView.ebay.title')" :subtitle="untertitel" :back-to="{ name: 'shop-overview' }">
            <template v-if="board">
                <v-chip v-if="kanal.connected" size="small" color="success" variant="flat" prepend-icon="mdi-link-variant">
                    {{ kanal.account || t('ShopView.ebay.connected') }}
                </v-chip>
                <v-chip v-else size="small" color="warning" variant="flat" prepend-icon="mdi-link-variant-off">{{ t('ShopView.ebay.notConnected') }}</v-chip>
                <v-chip v-if="kanal.environment === 'sandbox'" size="small" color="info" variant="flat" prepend-icon="mdi-flask-outline">{{ t('ShopView.ebay.sandbox') }}</v-chip>
                <v-chip v-if="!kanal.active" size="small" color="warning" variant="flat">{{ t('ShopView.ebay.channelOff') }}</v-chip>
            </template>
            <template #actions>
                <v-select
                    v-if="kanaele.length > 1"
                    v-model="kanalId"
                    :items="kanaele"
                    item-title="name"
                    item-value="channel_id"
                    variant="outlined"
                    density="compact"
                    hide-details
                    style="min-width: 200px"
                    @update:model-value="kanalWechsel"
                />
                <v-btn v-if="board && kanal.connected" variant="tonal" color="primary" size="small" prepend-icon="mdi-sync" :loading="gleichtAb" @click="abgleichen">
                    {{ t('ShopView.ebay.syncNow') }}
                </v-btn>
                <v-btn variant="text" size="small" prepend-icon="mdi-open-in-new" :href="sellerHub" target="_blank" rel="noopener">
                    {{ t('ShopView.ebay.sellerHub') }}
                </v-btn>
                <v-btn variant="text" size="small" :loading="shop.loading.value && !gleichtAb" @click="laden">
                    <v-icon start>mdi-refresh</v-icon>
                    {{ t('ShopView.common.reload') }}
                </v-btn>
            </template>
        </page-header>

        <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-3" closable @click:close="fehler = ''">{{ fehler }}</v-alert>

        <!-- Noch kein eBay-Kanal -->
        <section-card v-if="geladen && !board" :title="t('ShopView.ebay.setup.noChannelTitle')" icon="mdi-shopping-outline">
            <p class="text-body-2 mb-3">{{ t('ShopView.ebay.setup.noChannelText') }}</p>
            <v-btn v-if="darfKanaele" color="primary" variant="flat" prepend-icon="mdi-plus" :to="{ name: 'shop-channels' }">{{ t('ShopView.ebay.setup.createChannel') }}</v-btn>
        </section-card>

        <template v-if="board">
            <!-- Einrichtung: verbinden, Kanal an, Bestand -->
            <v-alert v-if="!kanal.connected" type="info" variant="tonal" class="mb-3" icon="mdi-link-variant">
                <div class="font-weight-medium">{{ t('ShopView.ebay.setup.connectTitle') }}</div>
                <div class="text-body-2">{{ t('ShopView.ebay.setup.connectText') }}</div>
                <div v-if="acceptUrl" class="text-body-2 mt-2 d-flex align-center flex-wrap ga-2">
                    {{ t('ShopView.ebay.setup.acceptUrl') }}
                    <code class="url">{{ acceptUrl }}</code>
                    <v-btn size="x-small" variant="tonal" prepend-icon="mdi-content-copy" @click="kopieren(acceptUrl)">{{ t('ShopView.ebay.setup.copy') }}</v-btn>
                </div>
                <div v-else class="text-body-2 mt-2 text-warning">{{ t('ShopView.ebay.setup.publicHostMissing') }}</div>
                <div class="mt-3 d-flex flex-wrap ga-2">
                    <v-btn v-if="darfKanaele" color="primary" variant="flat" prepend-icon="mdi-login" :loading="verbindet" @click="verbinden">{{ t('ShopView.ebay.setup.connect') }}</v-btn>
                    <v-btn v-if="darfKanaele" variant="text" :to="{ name: 'shop-channels', query: { channel: kanalId } }">{{ t('ShopView.ebay.setup.settings') }}</v-btn>
                    <v-btn variant="text" href="https://developer.ebay.com/my/keys" target="_blank" rel="noopener" prepend-icon="mdi-open-in-new">{{ t('ShopView.ebay.setup.developerKeys') }}</v-btn>
                </div>
            </v-alert>
            <v-alert v-else-if="!kanal.active" type="info" variant="tonal" density="compact" class="mb-3">
                {{ t('ShopView.ebay.channelOffHint') }}
                <v-btn v-if="darfKanaele" size="small" variant="text" :to="{ name: 'shop-channels', query: { channel: kanalId } }">{{ t('ShopView.channelConfig.title') }}</v-btn>
            </v-alert>
            <v-alert v-if="kanal.connected && zahlen.oversell > 0 && kanal.legacy_sync === 'off'" type="warning" variant="tonal" density="compact" class="mb-3">
                {{ t('ShopView.ebay.setup.oversellSyncOff', { anzahl: zahlen.oversell }) }}
                <v-btn size="small" variant="text" @click="zuAngeboten('oversell')">{{ t('ShopView.ebay.setup.showListings') }}</v-btn>
                <v-btn v-if="darfKanaele" size="small" variant="text" :to="{ name: 'shop-channels', query: { channel: kanalId } }">{{ t('ShopView.ebay.setup.enableSync') }}</v-btn>
            </v-alert>
            <v-alert v-if="kanal.legacy_sync !== 'off' && kanal.out_of_stock_control === '0'" type="warning" variant="tonal" density="compact" class="mb-3">
                {{ t('ShopView.ebay.setup.outOfStockControl') }}
            </v-alert>
            <v-alert v-if="kanal.connected && !kanal.auto_invoice" type="info" variant="tonal" density="compact" class="mb-3">
                {{ t('ShopView.ebay.setup.autoInvoiceOff') }}
            </v-alert>

            <!-- Kennzahlen -->
            <v-row class="mb-2" dense>
                <v-col v-for="zahl in kennzahlen" :key="zahl.key" cols="6" sm="4" md="2">
                    <v-card variant="tonal" :color="zahl.color" class="fill-height" link @click="zahl.klick()">
                        <v-card-text class="py-3">
                            <div class="text-h5">{{ zahl.wert }}</div>
                            <div class="text-caption">{{ zahl.titel }}</div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-tabs v-model="reiter" class="mb-3">
                <v-tab value="review">
                    <v-icon start>mdi-account-question-outline</v-icon>
                    {{ t('ShopView.ebay.tabs.review') }}
                    <v-badge v-if="zahlen.review" :content="zahlen.review" color="error" inline />
                </v-tab>
                <v-tab value="orders">
                    <v-icon start>mdi-receipt-text-outline</v-icon>
                    {{ t('ShopView.ebay.tabs.orders') }}
                    <v-badge v-if="zahlen.to_ship" :content="zahlen.to_ship" color="warning" inline />
                </v-tab>
                <v-tab value="listings">
                    <v-icon start>mdi-format-list-bulleted-square</v-icon>
                    {{ t('ShopView.ebay.tabs.listings') }}
                    <v-badge v-if="zahlen.unlinked" :content="zahlen.unlinked" color="grey" inline />
                </v-tab>
                <v-tab value="offered">
                    <v-icon start>mdi-storefront-outline</v-icon>
                    {{ t('ShopView.ebay.tabs.offered') }}
                    <v-badge v-if="zahlen.sync_errors" :content="zahlen.sync_errors" color="error" inline />
                </v-tab>
            </v-tabs>

            <v-window v-model="reiter">
                <!-- Prüfen -->
                <v-window-item value="review">
                    <div v-if="board.review.length" class="d-flex flex-wrap align-center ga-2 mb-3">
                        <span class="text-body-2 text-medium-emphasis">{{ t('ShopView.ebay.review.intro', { anzahl: board.review.length }) }}</span>
                        <v-spacer />
                        <v-btn
                            v-if="darfAbrechnen && board.review.length > 1"
                            size="small"
                            variant="tonal"
                            prepend-icon="mdi-check-all"
                            :loading="uebernimmt"
                            @click="alleUebernehmen"
                        >
                            {{ t('ShopView.ebay.review.acceptAll', { anzahl: board.review.length }) }}
                        </v-btn>
                    </div>
                    <v-alert v-else type="success" variant="tonal" icon="mdi-check-circle-outline">
                        <div class="font-weight-medium">{{ t('ShopView.ebay.review.emptyTitle') }}</div>
                        <div class="text-body-2">{{ t('ShopView.ebay.review.emptyText', { anzahl: zahlen.sold_30d || 0 }) }}</div>
                    </v-alert>
                    <EbayReviewCard
                        v-for="(bestellung, i) in board.review"
                        :key="bestellung.id"
                        :ref="el => (karten[i] = el)"
                        :order="bestellung"
                        :busy="entscheidet === bestellung.id"
                        class="mb-3"
                        @entscheiden="wahl => entscheiden(bestellung, wahl, i)"
                        @zurueckstellen="zurueckstellen(bestellung)"
                        @zuordnen="itemId => zuAngeboten('all', itemId)"
                    />
                </v-window-item>

                <!-- Verkäufe -->
                <v-window-item value="orders">
                    <section-card :title="t('ShopView.ebay.orders.title')" :subtitle="letzterAbruf" icon="mdi-receipt-text-outline" plain>
                        <template #actions>
                            <v-btn v-if="kanal.connected" size="small" variant="text" prepend-icon="mdi-cloud-download-outline" :loading="holt" @click="bestellungenHolen">
                                {{ t('ShopView.ebay.orders.fetch') }}
                            </v-btn>
                        </template>
                        <div class="pa-3">
                            <v-chip-group v-model="bestellFilter" mandatory selected-class="text-primary">
                                <v-chip v-for="f in bestellFilterListe" :key="f.key" :value="f.key" size="small" variant="outlined" filter>
                                    {{ f.text }}
                                    <v-badge v-if="f.anzahl" :content="f.anzahl" :color="f.color || 'grey'" inline />
                                </v-chip>
                            </v-chip-group>
                        </div>
                        <v-data-table
                            :headers="bestellSpalten"
                            :items="bestellungen"
                            item-value="id"
                            density="compact"
                            :items-per-page="25"
                            :no-data-text="t('ShopView.ebay.orders.empty')"
                        >
                            <template #item.ordered_at="{ item }">{{ zeit(item.ordered_at) }}</template>
                            <template #item.ebay_order_id="{ item }">
                                <a :href="ebayBestellung(item.ebay_order_id)" target="_blank" rel="noopener" class="text-decoration-none text-body-2">{{ item.ebay_order_id }}</a>
                                <div class="text-caption text-medium-emphasis text-truncate positionen">
                                    {{ (item.items || []).map(p => `${p.quantity} × ${p.title}`).join(', ') }}
                                </div>
                            </template>
                            <template #item.buyer="{ item }">
                                <router-link v-if="item.customer_id" :to="entityRoute('customer', item.customer_id)" class="text-decoration-none">{{ item.customer_name }}</router-link>
                                <span v-else>{{ item.buyer_name || item.buyer_username }}</span>
                                <div class="text-caption text-medium-emphasis">{{ item.buyer_username }}</div>
                            </template>
                            <template #item.total="{ item }">{{ geld(item.total) }}</template>
                            <template #item.state="{ item }">
                                <v-chip size="x-small" :color="standFarbe(item)" variant="tonal">{{ standText(item) }}</v-chip>
                                <div v-if="item.decided_by === 'auto' && item.ar_id" class="text-caption text-medium-emphasis">{{ t('ShopView.ebay.orders.auto') }}</div>
                            </template>
                            <template #item.invnumber="{ item }">
                                <router-link v-if="item.ar_id" :to="entityRoute('invoice', item.ar_id)" class="text-decoration-none">{{ item.invnumber }}</router-link>
                                <v-icon v-if="item.ar_id && item.posting_reason && item.posting_reason !== 'posted'" size="small" color="warning" class="ml-1" :title="t('ShopView.ebay.orders.notPosted', { grund: item.posting_reason })">mdi-alert-outline</v-icon>
                                <span v-if="!item.ar_id" class="text-medium-emphasis">—</span>
                            </template>
                            <template #item.shipping="{ item }">
                                <div v-if="item.shipped_at" class="text-no-wrap">
                                    <v-icon size="small" color="success">mdi-truck-check-outline</v-icon>
                                    <span class="text-caption ml-1">{{ item.carrier }} {{ item.tracking_number }}</span>
                                </div>
                                <div v-else-if="item.to_ship">
                                    <v-btn v-if="darfAbrechnen" size="x-small" variant="tonal" color="primary" prepend-icon="mdi-truck-fast-outline" @click="versandOeffnen(item)">
                                        {{ item.tracking_number ? t('ShopView.ebay.orders.shipRetry') : t('ShopView.ebay.orders.ship') }}
                                    </v-btn>
                                    <div v-if="item.ship_error" class="text-caption text-error">{{ item.ship_error }}</div>
                                </div>
                                <span v-else class="text-medium-emphasis">—</span>
                            </template>
                            <template #item.aktion="{ item }">
                                <v-btn v-if="darfAbrechnen && item.state === 'ignored'" size="x-small" variant="text" prepend-icon="mdi-restore" @click="wiederPruefen(item)">{{ t('ShopView.ebay.orders.reopen') }}</v-btn>
                                <v-btn v-else-if="darfAbrechnen && item.state === 'review'" size="x-small" variant="text" prepend-icon="mdi-account-question-outline" @click="reiter = 'review'">{{ t('ShopView.ebay.orders.toReview') }}</v-btn>
                                <v-btn v-else-if="item.credit_needed && item.ar_id" size="x-small" variant="tonal" color="error" :to="entityRoute('invoice', item.ar_id)">{{ t('ShopView.ebay.orders.openForCredit') }}</v-btn>
                            </template>
                        </v-data-table>
                    </section-card>
                </v-window-item>

                <!-- Angebote -->
                <v-window-item value="listings">
                    <v-alert v-if="!kanal.connected" type="info" variant="tonal" density="compact">{{ t('ShopView.ebay.listings.connectFirst') }}</v-alert>
                    <EbayListingsSection
                        v-else-if="angeboteGeladen"
                        ref="angeboteRef"
                        :channel-id="kanalId"
                        :darf-bearbeiten="darfBearbeiten"
                        :last-sync="kanal.listings_last_sync || ''"
                        :start-filter="angeboteFilter"
                        :start-suche="angeboteSuche"
                        @geaendert="laden"
                        @seite="seiteOeffnen"
                    />
                </v-window-item>

                <!-- Im Kanal -->
                <v-window-item value="offered">
                    <section-card :title="t('ShopView.ebay.offered.title')" :subtitle="t('ShopView.ebay.offered.subtitle')" icon="mdi-storefront-outline" plain>
                        <v-data-table
                            :headers="kanalSpalten"
                            :items="board.offered"
                            item-value="parts_id"
                            density="compact"
                            :items-per-page="25"
                            :no-data-text="t('ShopView.ebay.offered.empty')"
                        >
                            <template #item.description="{ item }">
                                <router-link :to="entityRoute('article', item.parts_id)" class="text-decoration-none">
                                    <span class="text-caption text-medium-emphasis">{{ item.partnumber }}</span> {{ item.description }}
                                </router-link>
                                <div v-if="item.error" class="text-caption text-error">{{ item.error }}</div>
                            </template>
                            <template #item.onhand="{ item }">{{ zahl(item.onhand, 0) }} {{ item.unit }}</template>
                            <template #item.price="{ item }">{{ geld(item.price) }}</template>
                            <template #item.status="{ item }">
                                <v-chip size="x-small" variant="tonal" :color="item.status === 'error' ? 'error' : item.status === 'active' ? 'success' : 'grey'">
                                    {{ te(`ShopView.partCard.sync.${item.status}`) ? t(`ShopView.partCard.sync.${item.status}`) : t('ShopView.partCard.sync.none') }}
                                </v-chip>
                                <v-chip v-if="item.legacy" size="x-small" variant="tonal" class="ml-1">{{ t('ShopView.ebay.offered.legacy') }}</v-chip>
                            </template>
                            <template #item.page="{ item }">
                                <v-btn v-if="darfBearbeiten" size="x-small" :variant="item.has_page ? 'text' : 'tonal'" color="primary" prepend-icon="mdi-palette-outline" @click="seiteOeffnen(item.parts_id)">
                                    {{ item.has_page ? t('ShopView.ebay.offered.editPage') : t('ShopView.ebay.offered.designPage') }}
                                </v-btn>
                                <v-icon v-if="!item.images" size="small" color="warning" class="ml-1" :title="t('ShopView.ebay.offered.noImages')">mdi-image-off-outline</v-icon>
                            </template>
                        </v-data-table>
                    </section-card>

                    <section-card :title="t('ShopView.ebay.suggestions.title')" :subtitle="t('ShopView.ebay.suggestions.subtitle')" icon="mdi-lightbulb-on-outline" plain class="mt-3">
                        <template #actions>
                            <v-btn
                                v-if="darfBearbeiten"
                                size="small"
                                color="primary"
                                variant="flat"
                                prepend-icon="mdi-plus"
                                :disabled="!vorschlagWahl.length"
                                :loading="nimmtAuf"
                                @click="anbieten(vorschlagWahl)"
                            >
                                {{ t('ShopView.ebay.suggestions.offerSelected', { anzahl: vorschlagWahl.length }) }}
                            </v-btn>
                        </template>
                        <v-data-table
                            v-model="vorschlagWahl"
                            :headers="vorschlagSpalten"
                            :items="vorschlaege"
                            item-value="parts_id"
                            :show-select="darfBearbeiten"
                            density="compact"
                            :items-per-page="10"
                            :no-data-text="t('ShopView.ebay.suggestions.empty')"
                        >
                            <template #item.description="{ item }">
                                <router-link :to="entityRoute('article', item.parts_id)" class="text-decoration-none">
                                    <span class="text-caption text-medium-emphasis">{{ item.partnumber }}</span> {{ item.description }}
                                </router-link>
                            </template>
                            <template #item.onhand="{ item }">{{ zahl(item.onhand, 0) }} {{ item.unit }}</template>
                            <template #item.capital="{ item }">{{ geld(item.capital) }}</template>
                            <template #item.idle_months="{ item }">
                                <v-chip v-if="!item.last_use || item.idle_months > 0" size="x-small" :color="item.idle_months >= 12 ? 'error' : item.idle_months >= 6 ? 'warning' : 'grey'" variant="tonal">
                                    {{ item.last_use ? t('ShopView.kleinanzeigen.suggestions.idle', { monate: item.idle_months }) : t('ShopView.kleinanzeigen.suggestions.neverUsed') }}
                                </v-chip>
                            </template>
                            <template #item.aktion="{ item }">
                                <v-btn v-if="darfBearbeiten" size="x-small" variant="tonal" color="primary" @click="seiteOeffnen(item.parts_id)">{{ t('ShopView.ebay.suggestions.designAndOffer') }}</v-btn>
                            </template>
                        </v-data-table>
                    </section-card>
                </v-window-item>
            </v-window>
        </template>

        <!-- Versand melden -->
        <v-dialog v-model="versandDialog" max-width="460">
            <v-card v-if="versandBestellung">
                <v-card-title>{{ t('ShopView.ebay.orders.shipTitle') }}</v-card-title>
                <v-card-subtitle>{{ versandBestellung.ebay_order_id }} · {{ versandBestellung.customer_name || versandBestellung.buyer_name }}</v-card-subtitle>
                <v-card-text>
                    <v-select v-model="versand.carrier" :items="versanddienste" :label="t('ShopView.ebay.orders.carrier')" variant="outlined" density="compact" class="mb-3" />
                    <v-text-field v-model="versand.tracking_number" :label="t('ShopView.ebay.orders.trackingNumber')" variant="outlined" density="compact" autofocus @keydown.enter="versandMelden" />
                    <div class="text-caption text-medium-emphasis mt-2">{{ t('ShopView.ebay.orders.shipHint') }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="versandDialog = false">{{ t('ShopView.common.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="meldet" :disabled="!versand.tracking_number.trim()" @click="versandMelden">{{ t('ShopView.ebay.orders.shipConfirm') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <EbayPageDialog v-model="seiteDialog" :parts-id="seiteId" :channel-id="kanalId" :ai-ready="aiReady" @gespeichert="laden" />
    </v-container>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import EbayReviewCard from '@/features/shop/components/ebay-review.card.vue'
import EbayListingsSection from '@/features/shop/components/ebay-listings.section.vue'
import EbayPageDialog from '@/features/shop/components/ebay-page.dialog.vue'
import { useShop } from '@/features/shop/composables/useShop.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { entityRoute } from '@/core/constants/routes.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import * as toasts from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const { t, te, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const shop = useShop()
const oserp = oserpStore()

const darfBearbeiten = computed(() => oserp.checkPermission('shop_part_edit') || oserp.checkPermission('edit_shop_config'))
const darfAbrechnen = computed(() => oserp.checkPermission('shop_order') || oserp.checkPermission('edit_shop_config'))
const darfKanaele = computed(() => oserp.checkPermission('edit_shop_config'))

const board = ref(null)
const geladen = ref(false)
const kanaele = ref([])
const kanalId = ref(Number(route.query.channel) || 0)
const vorschlaege = ref([])
const aiReady = ref(false)
const acceptUrl = ref('')
const versanddienste = ref(['DHL'])
const reiter = ref(route.query.tab ? String(route.query.tab) : 'review')
const fehler = ref('')
const gleichtAb = ref(false)
const holt = ref(false)
const verbindet = ref(false)
const uebernimmt = ref(false)
const entscheidet = ref(0)
const nimmtAuf = ref(false)
const vorschlagWahl = ref([])
const karten = ref([])
const bestellFilter = ref('all')
const angeboteRef = ref(null)
const angeboteFilter = ref('')
const angeboteSuche = ref('')
// Angebote erst laden, wenn der Reiter zum ersten Mal offen ist — es können Tausende sein
const angeboteGeladen = ref(reiter.value === 'listings')
watch(reiter, wert => { if (wert === 'listings') angeboteGeladen.value = true })

const seiteDialog = ref(false)
const seiteId = ref(0)
const versandDialog = ref(false)
const versandBestellung = ref(null)
const versand = ref({ carrier: 'DHL', tracking_number: '' })
const meldet = ref(false)

const kanal = computed(() => board.value?.channel || {})
const zahlen = computed(() => board.value?.counts || {})
const sellerHub = computed(() => (kanal.value.environment === 'sandbox' ? 'https://www.sandbox.ebay.com/sh/ovw' : 'https://www.ebay.de/sh/ovw'))
const untertitel = computed(() => {
    const name = kanal.value.name || ''
    return name && name.toLowerCase() !== 'ebay' ? name : t('ShopView.ebay.subtitle')
})
const letzterAbruf = computed(() => (kanal.value.last_check
    ? t('ShopView.ebay.orders.lastCheck', { zeit: new Date(kanal.value.last_check).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) })
    : t('ShopView.ebay.orders.subtitle')))

const zahl = (w, stellen = 2) => formatNumber(w ?? 0, locale.value, stellen)
const geld = w => `${formatNumber(w ?? 0, locale.value, 2)} €`
const zeit = w => (w ? new Date(String(w).replace(' ', 'T') + (/[Z+]/.test(String(w).slice(10)) ? '' : 'Z')).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '')
const ebayBestellung = id => `https://www.ebay.de/sh/ord/details?orderid=${encodeURIComponent(id)}`

const kennzahlen = computed(() => {
    const c = zahlen.value
    return [
        { key: 'review', wert: c.review || 0, titel: t('ShopView.ebay.figures.review'), color: c.review ? 'error' : 'success', klick: () => (reiter.value = 'review') },
        { key: 'ship', wert: c.to_ship || 0, titel: t('ShopView.ebay.figures.toShip'), color: c.to_ship ? 'warning' : undefined, klick: () => { reiter.value = 'orders'; bestellFilter.value = 'to_ship' } },
        { key: 'revenue', wert: geld(c.revenue_30d), titel: t('ShopView.ebay.figures.revenue30', { anzahl: c.sold_30d || 0 }), color: 'primary', klick: () => { reiter.value = 'orders'; bestellFilter.value = 'invoiced' } },
        { key: 'listings', wert: c.listings || 0, titel: t('ShopView.ebay.figures.listings', { betrag: geld(c.listing_value) }), color: 'success', klick: () => zuAngeboten('all') },
        { key: 'unlinked', wert: c.unlinked || 0, titel: t('ShopView.ebay.figures.unlinked', { anzahl: c.suggested || 0 }), color: c.unlinked ? 'warning' : undefined, klick: () => zuAngeboten(c.suggested ? 'suggested' : 'unlinked') },
        { key: 'oversell', wert: c.oversell || 0, titel: t('ShopView.ebay.figures.oversell'), color: c.oversell ? 'error' : undefined, klick: () => zuAngeboten('oversell') },
    ]
})

// ── Verkäufe ──
const BESTELL_FILTER = {
    all: () => true,
    to_ship: o => o.to_ship,
    invoiced: o => !!o.ar_id,
    waiting_payment: o => o.state === 'waiting_payment',
    credit_needed: o => o.credit_needed,
    cancelled: o => o.state === 'cancelled',
    ignored: o => o.state === 'ignored',
}
const bestellFilterListe = computed(() => [
    { key: 'all', text: t('ShopView.ebay.orders.filter.all') },
    { key: 'to_ship', text: t('ShopView.ebay.orders.filter.toShip'), color: 'warning' },
    { key: 'invoiced', text: t('ShopView.ebay.orders.filter.invoiced') },
    { key: 'waiting_payment', text: t('ShopView.ebay.orders.filter.waitingPayment'), color: 'info' },
    { key: 'credit_needed', text: t('ShopView.ebay.orders.filter.creditNeeded'), color: 'error' },
    { key: 'cancelled', text: t('ShopView.ebay.orders.filter.cancelled') },
    { key: 'ignored', text: t('ShopView.ebay.orders.filter.ignored') },
].map(f => ({ ...f, anzahl: f.key === 'all' ? 0 : (board.value?.orders || []).filter(BESTELL_FILTER[f.key]).length })))
const bestellungen = computed(() => (board.value?.orders || []).filter(BESTELL_FILTER[bestellFilter.value] || BESTELL_FILTER.all))
const bestellSpalten = computed(() => [
    { title: t('ShopView.ebay.orders.col.date'), key: 'ordered_at' },
    { title: t('ShopView.ebay.orders.col.order'), key: 'ebay_order_id', sortable: false },
    { title: t('ShopView.ebay.orders.col.buyer'), key: 'buyer', sortable: false },
    { title: t('ShopView.ebay.orders.col.total'), key: 'total', align: 'end' },
    { title: t('ShopView.ebay.orders.col.state'), key: 'state' },
    { title: t('ShopView.orders.invnumber'), key: 'invnumber' },
    { title: t('ShopView.ebay.orders.col.shipping'), key: 'shipping', sortable: false },
    { title: '', key: 'aktion', sortable: false, align: 'end' },
])
function standText(o) {
    if (o.credit_needed) return t('ShopView.ebay.orders.state.credit_needed')
    if (o.shipped_at) return t('ShopView.ebay.orders.state.shipped')
    return t(`ShopView.ebay.orders.state.${o.state}`)
}
function standFarbe(o) {
    if (o.credit_needed) return 'error'
    return { invoiced: 'success', review: 'error', waiting_payment: 'info', cancelled: 'grey', ignored: 'grey', history: 'grey' }[o.state] || 'grey'
}

const kanalSpalten = computed(() => [
    { title: t('ShopView.kleinanzeigen.suggestions.part'), key: 'description' },
    { title: t('ShopView.kleinanzeigen.suggestions.stock'), key: 'onhand', align: 'end' },
    { title: t('ShopView.ebay.offered.price'), key: 'price', align: 'end' },
    { title: t('ShopView.ebay.offered.status'), key: 'status' },
    { title: '', key: 'page', sortable: false, align: 'end' },
])
const vorschlagSpalten = computed(() => [
    { title: t('ShopView.kleinanzeigen.suggestions.part'), key: 'description' },
    { title: t('ShopView.kleinanzeigen.suggestions.stock'), key: 'onhand', align: 'end' },
    { title: t('ShopView.kleinanzeigen.suggestions.capital'), key: 'capital', align: 'end' },
    { title: t('ShopView.kleinanzeigen.suggestions.lastUse'), key: 'idle_months' },
    { title: '', key: 'aktion', sortable: false, align: 'end' },
])

async function laden() {
    const antwort = await shop.fetchEbayBoard(kanalId.value || undefined)
    geladen.value = true
    if (!antwort) {
        fehler.value = shop.error.value
        return
    }
    fehler.value = ''
    board.value = antwort.board
    kanaele.value = antwort.channels || []
    kanalId.value = antwort.board?.channel?.channel_id || kanalId.value
    vorschlaege.value = antwort.suggestions || []
    aiReady.value = !!antwort.ai_ready
    acceptUrl.value = antwort.accept_url || ''
    versanddienste.value = antwort.carriers || versanddienste.value
    vorschlagWahl.value = vorschlagWahl.value.filter(id => vorschlaege.value.some(v => v.parts_id === id))
    // Nichts zu prüfen: gleich zu den Verkäufen, außer der Reiter wurde gewählt
    if (!route.query.tab && reiter.value === 'review' && !antwort.board?.review?.length && antwort.board?.orders?.length) reiter.value = 'orders'
}

function kanalWechsel() {
    angeboteGeladen.value = reiter.value === 'listings'
    laden()
}

function zuAngeboten(filter, suche = '') {
    angeboteFilter.value = filter
    angeboteSuche.value = suche
    angeboteGeladen.value = true
    reiter.value = 'listings'
}

async function abgleichen() {
    gleichtAb.value = true
    const antwort = await shop.syncEbay(kanalId.value, 'all')
    gleichtAb.value = false
    if (!antwort) {
        fehler.value = shop.error.value
        return
    }
    meldeAbruf(antwort.orders)
    await laden()
    angeboteRef.value?.laden()
}

async function bestellungenHolen() {
    holt.value = true
    const antwort = await shop.syncEbay(kanalId.value, 'orders')
    holt.value = false
    if (!antwort) {
        fehler.value = shop.error.value
        return
    }
    meldeAbruf(antwort.orders)
    laden()
}

function meldeAbruf(bilanz) {
    if (!bilanz) return
    toasts.success(t('ShopView.ebay.syncResult', {
        rechnungen: bilanz.invoiced || 0, pruefen: bilanz.review || 0, zahlung: bilanz.waiting_payment || 0, versand: bilanz.shipped || 0,
    }), { timer: 6000 })
    if (bilanz.errors?.length) toasts.warning(bilanz.errors.join('\n'), { timer: 10000 })
}

// ── Prüfung ──
async function entscheiden(bestellung, wahl, index) {
    entscheidet.value = bestellung.id
    const antwort = await shop.decideEbayOrder(bestellung.id, wahl.customer_id, wahl.update_address)
    entscheidet.value = 0
    if (!antwort) {
        toasts.error(shop.error.value, { timer: 10000 })
        await laden()
        return
    }
    toasts.clickable(
        t('ShopView.ebay.review.invoiced', { nummer: antwort.invnumber }),
        antwort.posted ? '' : t('ShopView.ebay.review.notPosted'),
        () => router.push(entityRoute('invoice', antwort.ar_id)),
        { timer: 5000 },
    )
    await laden()
    // Die nächste Karte bekommt den Fokus — Prüfen geht dann ohne Maus weiter
    await nextTick()
    karten.value[Math.min(index, (board.value?.review?.length || 1) - 1)]?.fokus?.()
}

async function zurueckstellen(bestellung) {
    const antwort = await alerts.question(t('ShopView.ebay.review.ignoreQuestion'), '', t('ShopView.ebay.review.ignore'), t('ShopView.common.cancel'))
    if (!antwort?.isConfirmed) return
    if (await shop.setEbayOrderState(bestellung.id, 'ignored') !== null) laden()
}

async function wiederPruefen(bestellung) {
    if (await shop.setEbayOrderState(bestellung.id, 'review') !== null) {
        await laden()
        reiter.value = 'review'
    }
}

async function alleUebernehmen() {
    const antwort = await alerts.question(t('ShopView.ebay.review.acceptAllQuestion', { anzahl: board.value.review.length }), '', t('ShopView.ebay.review.acceptAllConfirm'), t('ShopView.common.cancel'))
    if (!antwort?.isConfirmed) return
    uebernimmt.value = true
    const ergebnis = await shop.acceptEbayOrderSuggestions(kanalId.value)
    uebernimmt.value = false
    if (!ergebnis) {
        toasts.error(shop.error.value)
        return
    }
    toasts.success(t('ShopView.ebay.review.acceptedAll', { anzahl: ergebnis.invoiced }))
    if (ergebnis.errors?.length) toasts.warning(ergebnis.errors.join('\n'), { timer: 10000 })
    laden()
}

// ── Versand ──
function versandOeffnen(bestellung) {
    versandBestellung.value = bestellung
    versand.value = { carrier: bestellung.carrier || 'DHL', tracking_number: bestellung.tracking_number || '' }
    versandDialog.value = true
}

async function versandMelden() {
    if (!versand.value.tracking_number.trim()) return
    meldet.value = true
    const antwort = await shop.shipEbayOrder(versandBestellung.value.id, versand.value.tracking_number.trim(), versand.value.carrier)
    meldet.value = false
    if (!antwort) {
        toasts.error(shop.error.value, { timer: 10000 })
        laden()
        return
    }
    versandDialog.value = false
    toasts.success(t('ShopView.ebay.orders.shipped'))
    laden()
}

// ── Artikel im Kanal ──
function seiteOeffnen(partsId) {
    seiteId.value = Number(partsId)
    seiteDialog.value = true
}

async function anbieten(ids) {
    nimmtAuf.value = true
    const antwort = await shop.addEbayParts(kanalId.value, ids)
    nimmtAuf.value = false
    if (!antwort) {
        toasts.error(shop.error.value)
        return
    }
    toasts.success(t('ShopView.ebay.suggestions.offered', { anzahl: antwort.added, bilder: antwort.images_copied }))
    vorschlagWahl.value = []
    laden()
}

// ── Mit eBay verbinden ──
let erwarteterZustand = ''
let popup = null

async function verbinden() {
    verbindet.value = true
    const antwort = await shop.fetchEbayConnectUrl(kanalId.value)
    if (!antwort?.url) {
        verbindet.value = false
        fehler.value = shop.error.value
        return
    }
    erwarteterZustand = new URL(antwort.url).searchParams.get('state') || ''
    popup = window.open(antwort.url, 'oserp-ebay-oauth', 'width=520,height=760')
    if (!popup) {
        // Popup blockiert: im selben Fenster, der Rücksprung legt den Code in localStorage
        window.location.href = antwort.url
    }
}

async function anmeldungAbschliessen(nachricht) {
    verbindet.value = false
    if (nachricht.error || !nachricht.code) {
        toasts.warning(t('ShopView.ebay.setup.connectCancelled'))
        return
    }
    verbindet.value = true
    const ergebnis = await shop.finishEbayConnect(kanalId.value, nachricht.code, nachricht.state)
    verbindet.value = false
    if (!ergebnis) {
        fehler.value = shop.error.value
        return
    }
    toasts.success(t('ShopView.ebay.setup.connectedAs', { konto: ergebnis.account || '—' }))
    await laden()
    // Gleich die Angebote lesen — das ist der erste Schritt nach dem Verbinden
    zuAngeboten('todo')
    gleichtAb.value = true
    const lauf = await shop.syncEbay(kanalId.value, 'listings')
    gleichtAb.value = false
    if (lauf) {
        toasts.success(t('ShopView.ebay.listings.synced', lauf.listings || {}))
        await laden()
        angeboteRef.value?.laden()
    } else {
        fehler.value = shop.error.value
    }
}

function nachrichtEmpfangen(ereignis) {
    const nachricht = ereignis.data
    if (!nachricht || nachricht.type !== 'oserp-ebay-oauth') return
    // Nur die Antwort auf den eigenen Aufruf; den Code prüft das Backend ohnehin
    if (erwarteterZustand && nachricht.state && nachricht.state !== erwarteterZustand) return
    anmeldungAbschliessen(nachricht)
}

async function kopieren(text) {
    try {
        await navigator.clipboard.writeText(text)
        toasts.success(t('ShopView.ebay.setup.copied'), { timer: 1500 })
    } catch {
        toasts.error(t('ShopView.kleinanzeigen.ad.copyFailed'))
    }
}

onMounted(async () => {
    window.addEventListener('message', nachrichtEmpfangen)
    await laden()
    // Aus der Artikelkarte: gleich die Produktseite dieses Artikels
    if (board.value && Number(route.query.page)) seiteOeffnen(route.query.page)
    // Rücksprung ohne Popup: Code liegt in localStorage
    try {
        const gemerkt = JSON.parse(localStorage.getItem('oserp-ebay-oauth') || 'null')
        if (gemerkt?.type === 'oserp-ebay-oauth' && board.value) {
            localStorage.removeItem('oserp-ebay-oauth')
            anmeldungAbschliessen(gemerkt)
        }
    } catch {
        // kein localStorage: nichts abzuholen
    }
})
onBeforeUnmount(() => window.removeEventListener('message', nachrichtEmpfangen))
</script>

<style scoped>
.url { padding: 2px 6px; border-radius: var(--oserp-radius-sm, 4px); background: var(--oserp-surface-2, rgba(0, 0, 0, 0.05)); word-break: break-all; }
.positionen { max-width: 340px; }
</style>
