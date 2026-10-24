<!-- src/features/shop/views/shop.kleinanzeigen.vue -->
<!--
    Kleinanzeigen-Board (dev/shop-kleinanzeigen.md): der Arbeitsplatz des
    Verkaufskanals Kleinanzeigen.

    Kleinanzeigen.de hat keine Schnittstelle. Das Board nimmt dem Benutzer
    deshalb alles ab, was sich abnehmen lässt: Es schlägt vor, was sich zu
    Geld machen lässt (gebundenes Kapital, das sich nicht bewegt), bereitet
    jede Anzeige fertig vor, sagt, was online zu tun ist (Preis geändert,
    ausverkauft, auffrischen), sammelt die Anfragen aus dem Postfach und
    hält den Verkauf mit Rechnung und Lagerbuchung fest.

    Rechte: lesen mit shop_order, shop_part_edit oder edit_shop_config;
    anbieten und verkaufen mit shop_part_edit oder edit_shop_config.
-->
<template>
    <NavbarView />
    <v-container fluid>
        <page-header
            :title="t('ShopView.kleinanzeigen.title')"
            :subtitle="untertitel"
            :back-to="{ name: 'shop-overview' }"
        >
            <v-chip v-if="board && !board.channel.active" size="small" color="warning" variant="flat">
                {{ t('ShopView.kleinanzeigen.channelOff') }}
            </v-chip>
            <!-- Betriebsart: Dienst (Headless-Browser) stellt selbst ein, oder von Hand -->
            <v-chip v-if="automatisch" size="small" :color="worker?.reachable ? 'success' : 'error'" variant="flat" prepend-icon="mdi-robot-outline">
                {{ worker?.reachable ? t('ShopView.kleinanzeigen.auto.on') : t('ShopView.kleinanzeigen.auto.down') }}
            </v-chip>
            <template #actions>
                <v-btn v-if="automatisch" variant="text" size="small" prepend-icon="mdi-sync" :loading="gleichtAb" @click="abgleichen">
                    {{ t('ShopView.kleinanzeigen.auto.sync') }}
                </v-btn>
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
                    @update:model-value="laden"
                />
                <v-btn variant="text" size="small" prepend-icon="mdi-open-in-new" href="https://www.kleinanzeigen.de/m-meine-anzeigen.html" target="_blank" rel="noopener">
                    {{ t('ShopView.kleinanzeigen.openMyAds') }}
                </v-btn>
                <v-btn variant="text" size="small" :loading="shop.loading.value" @click="laden">
                    <v-icon start>mdi-refresh</v-icon>
                    {{ t('ShopView.common.reload') }}
                </v-btn>
            </template>
        </page-header>

        <v-alert v-if="shop.error.value" type="error" variant="tonal" density="compact" class="mb-3">{{ shop.error.value }}</v-alert>
        <!-- Fehler der letzten Dienst-Aktion bleibt stehen, bis die nächste gelingt — ein Toast allein ist schnell weg -->
        <v-alert v-if="dienstMeldung" type="error" variant="tonal" density="compact" class="mb-3" closable @click:close="dienstMeldung = ''">
            <div class="font-weight-medium">{{ t('ShopView.kleinanzeigen.auto.failed', { titel: dienstMeldungTitel }) }}</div>
            <div>{{ dienstMeldung }}</div>
        </v-alert>

        <v-alert v-if="board && !board.channel.active" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('ShopView.kleinanzeigen.channelOffHint') }}
            <v-btn v-if="darfKanaele" size="small" variant="text" :to="{ name: 'shop-channels', query: { channel: kanalId } }">{{ t('ShopView.channelConfig.title') }}</v-btn>
        </v-alert>
        <v-alert v-else-if="board && !board.channel.location" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('ShopView.kleinanzeigen.locationMissing') }}
            <v-btn v-if="darfKanaele" size="small" variant="text" :to="{ name: 'shop-channels', query: { channel: kanalId } }">{{ t('ShopView.channelConfig.title') }}</v-btn>
        </v-alert>

        <!-- Kennzahlen -->
        <v-row v-if="board" class="mb-2" dense>
            <v-col v-for="zahl in kennzahlen" :key="zahl.key" cols="6" sm="4" md="2">
                <v-card variant="tonal" :color="zahl.color" class="fill-height" :link="!!zahl.reiter" @click="zahl.reiter && (reiter = zahl.reiter)">
                    <v-card-text class="py-3">
                        <div class="text-h5">{{ zahl.wert }}</div>
                        <div class="text-caption">{{ zahl.titel }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-tabs v-model="reiter" class="mb-3">
            <v-tab value="suggestions">
                <v-icon start>mdi-lightbulb-on-outline</v-icon>
                {{ t('ShopView.kleinanzeigen.tabs.suggestions') }}
                <v-badge v-if="vorschlaege.length" :content="vorschlaege.length" color="grey" inline />
            </v-tab>
            <v-tab value="drafts">
                <v-icon start>mdi-file-document-edit-outline</v-icon>
                {{ t('ShopView.kleinanzeigen.tabs.drafts') }}
                <v-badge v-if="board?.drafts?.length" :content="board.drafts.length" color="grey" inline />
            </v-tab>
            <v-tab value="online">
                <v-icon start>mdi-bullhorn-outline</v-icon>
                {{ t('ShopView.kleinanzeigen.tabs.online') }}
                <v-badge v-if="board?.counts?.todo" :content="board.counts.todo" color="warning" inline />
            </v-tab>
            <v-tab value="leads">
                <v-icon start>mdi-message-text-outline</v-icon>
                {{ t('ShopView.kleinanzeigen.tabs.leads') }}
                <v-badge v-if="board?.counts?.leads" :content="board.counts.leads" color="error" inline />
            </v-tab>
            <v-tab value="sold">
                <v-icon start>mdi-cash-check</v-icon>
                {{ t('ShopView.kleinanzeigen.tabs.sold') }}
            </v-tab>
        </v-tabs>

        <v-window v-model="reiter">

            <!-- Vorschläge: Ware, die Geld bindet und sich nicht bewegt -->
            <v-window-item value="suggestions">
                <section-card :title="t('ShopView.kleinanzeigen.suggestions.title')" :subtitle="t('ShopView.kleinanzeigen.suggestions.subtitle')" icon="mdi-lightbulb-on-outline" plain>
                    <template #actions>
                        <v-btn
                            v-if="darfBearbeiten"
                            size="small"
                            color="primary"
                            variant="flat"
                            prepend-icon="mdi-plus"
                            :disabled="!gewaehlt.length"
                            :loading="nimmtAuf"
                            @click="anbieten(gewaehlt)"
                        >
                            {{ t('ShopView.kleinanzeigen.suggestions.offerSelected', { anzahl: gewaehlt.length }) }}
                        </v-btn>
                    </template>
                    <v-data-table
                        v-model="gewaehlt"
                        :headers="vorschlagSpalten"
                        :items="vorschlaege"
                        item-value="parts_id"
                        :show-select="darfBearbeiten"
                        density="compact"
                        :items-per-page="25"
                        :no-data-text="t('ShopView.kleinanzeigen.suggestions.empty')"
                    >
                        <template #item.description="{ item }">
                            <router-link :to="entityRoute('article', item.parts_id)" class="text-decoration-none">
                                <span class="text-caption text-medium-emphasis">{{ item.partnumber }}</span>
                                {{ item.description }}
                            </router-link>
                        </template>
                        <template #item.onhand="{ item }">{{ zahl(item.onhand, 0) }} {{ item.unit }}</template>
                        <template #item.capital="{ item }">{{ geld(item.capital) }}</template>
                        <template #item.idle_months="{ item }">
                            <v-chip size="x-small" :color="item.idle_months >= 12 ? 'error' : item.idle_months >= 6 ? 'warning' : 'grey'" variant="tonal">
                                {{ item.last_use ? t('ShopView.kleinanzeigen.suggestions.idle', { monate: item.idle_months }) : t('ShopView.kleinanzeigen.suggestions.neverUsed') }}
                            </v-chip>
                        </template>
                        <template #item.images="{ item }">
                            <v-icon v-if="item.images > 0" size="small" color="success" :title="t('ShopView.kleinanzeigen.suggestions.hasImages', { anzahl: item.images })">mdi-image-check-outline</v-icon>
                            <v-icon v-else size="small" color="grey" :title="t('ShopView.kleinanzeigen.suggestions.noImages')">mdi-image-off-outline</v-icon>
                        </template>
                        <template #item.price="{ item }">{{ zahl(item.price, 0) }} €</template>
                        <template #item.aktion="{ item }">
                            <v-btn v-if="darfBearbeiten" size="x-small" variant="tonal" color="primary" :loading="nimmtAuf" @click="anbieten([item.parts_id])">
                                {{ t('ShopView.kleinanzeigen.suggestions.offer') }}
                            </v-btn>
                        </template>
                    </v-data-table>
                </section-card>
            </v-window-item>

            <!-- Entwürfe: vorbereitet, noch nicht eingestellt -->
            <v-window-item value="drafts">
                <div v-if="!board?.drafts?.length" class="text-body-2 text-medium-emphasis pa-4">{{ t('ShopView.kleinanzeigen.drafts.empty') }}</div>
                <v-row v-else dense>
                    <v-col v-for="anzeige in board.drafts" :key="anzeige.parts_id" cols="12" sm="6" md="4" lg="3">
                        <v-card variant="outlined" class="fill-height d-flex flex-column anzeige-karte" hover @click="anzeigeOeffnen(anzeige)">
                            <v-img :src="anzeige.images[0]?.url" height="150" cover class="bg-grey-lighten-3">
                                <template #placeholder>
                                    <div class="d-flex fill-height align-center justify-center text-medium-emphasis">
                                        <v-icon size="40">mdi-image-off-outline</v-icon>
                                    </div>
                                </template>
                            </v-img>
                            <v-card-item>
                                <v-card-title class="text-subtitle-2 text-wrap">{{ anzeige.title }}</v-card-title>
                                <v-card-subtitle>{{ anzeige.partnumber }} · {{ zahl(anzeige.price, 0) }} € {{ anzeige.price_type === 'negotiable' ? 'VB' : '' }}</v-card-subtitle>
                            </v-card-item>
                            <v-card-text class="pt-0">
                                <v-chip v-for="m in anzeige.missing" :key="m" size="x-small" color="warning" variant="tonal" class="mr-1 mb-1">
                                    {{ t(`ShopView.kleinanzeigen.missing.${m}`) }}
                                </v-chip>
                                <v-chip v-if="!anzeige.missing.length" size="x-small" color="success" variant="tonal" prepend-icon="mdi-check">
                                    {{ t('ShopView.kleinanzeigen.drafts.ready') }}
                                </v-chip>
                                <!-- Letzter Fehler des Dienstes an dieser Anzeige (parts_channel_shop.sync_error) -->
                                <v-alert v-if="anzeige.sync_error" type="error" variant="tonal" density="compact" class="mt-2 text-caption">
                                    <div class="text-medium-emphasis">{{ t('ShopView.kleinanzeigen.drafts.lastError', { zeit: anzeige.sync_mtime || '' }) }}</div>
                                    {{ anzeige.sync_error }}
                                </v-alert>
                            </v-card-text>
                            <v-spacer />
                            <v-card-actions>
                                <!-- Automatisch: sofort über den Dienst einstellen; von Hand: Anzeige öffnen -->
                                <v-btn
                                    size="small"
                                    color="primary"
                                    :variant="automatisch ? 'flat' : 'tonal'"
                                    prepend-icon="mdi-rocket-launch-outline"
                                    :loading="arbeitet.has(anzeige.parts_id)"
                                    :disabled="automatisch && anzeige.missing.length > 0"
                                    @click.stop="automatisch ? einstellen(anzeige) : anzeigeOeffnen(anzeige)"
                                >
                                    {{ automatisch ? t('ShopView.kleinanzeigen.auto.postNow') : t('ShopView.kleinanzeigen.drafts.post') }}
                                </v-btn>
                                <v-spacer />
                                <v-btn v-if="darfBearbeiten" size="small" variant="text" icon="mdi-close" :title="t('ShopView.kleinanzeigen.drafts.remove')" @click.stop="entfernen(anzeige)" />
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </v-window-item>

            <!-- Online: was läuft, was zu tun ist -->
            <v-window-item value="online">
                <div v-if="!board?.online?.length" class="text-body-2 text-medium-emphasis pa-4">{{ t('ShopView.kleinanzeigen.online.empty') }}</div>
                <v-row v-else dense>
                    <v-col v-for="anzeige in board.online" :key="anzeige.parts_id" cols="12" sm="6" md="4" lg="3">
                        <v-card variant="outlined" class="fill-height d-flex flex-column anzeige-karte" :class="{ 'anzeige-karte--todo': anzeige.todo }" hover @click="anzeigeOeffnen(anzeige)">
                            <v-img :src="anzeige.images[0]?.url" height="150" cover class="bg-grey-lighten-3">
                                <div class="d-flex ga-1 pa-2">
                                    <v-chip size="x-small" variant="flat" :color="anzeige.state === 'reserved' ? 'info' : 'success'">
                                        {{ t(`ShopView.kleinanzeigen.state.${anzeige.state}`) }}
                                    </v-chip>
                                    <v-chip v-if="anzeige.todo" size="x-small" variant="flat" :color="anzeige.todo === 'delete' ? 'error' : anzeige.todo === 'update' ? 'warning' : 'info'" prepend-icon="mdi-alert-circle-outline">
                                        {{ t(`ShopView.kleinanzeigen.todo.${anzeige.todo}`) }}
                                    </v-chip>
                                </div>
                            </v-img>
                            <v-card-item>
                                <v-card-title class="text-subtitle-2 text-wrap">{{ anzeige.title }}</v-card-title>
                                <v-card-subtitle>
                                    {{ anzeige.partnumber }} · {{ zahl(anzeige.price, 0) }} € {{ anzeige.price_type === 'negotiable' ? 'VB' : '' }}
                                    · {{ t('ShopView.kleinanzeigen.online.days', { tage: anzeige.days_online ?? 0 }) }}
                                </v-card-subtitle>
                            </v-card-item>
                            <v-card-text class="pt-0 text-caption">
                                <div>{{ t('ShopView.kleinanzeigen.online.stock', { bestand: zahl(anzeige.onhand, 0), einheit: anzeige.unit || '' }) }}</div>
                                <div v-if="anzeige.leads" class="text-error font-weight-medium">
                                    <v-icon size="x-small">mdi-message-badge-outline</v-icon>
                                    {{ t('ShopView.kleinanzeigen.online.leads', { anzahl: anzeige.leads }) }}
                                </div>
                            </v-card-text>
                            <v-spacer />
                            <v-card-actions>
                                <v-btn v-if="anzeige.url" size="small" variant="text" icon="mdi-open-in-new" :href="anzeige.url" target="_blank" rel="noopener" :title="t('ShopView.kleinanzeigen.ad.openAd')" @click.stop />
                                <!-- Automatisch: die Aufgabe gleich erledigen lassen -->
                                <template v-if="automatisch && anzeige.todo">
                                    <v-btn v-if="anzeige.todo === 'update'" size="small" color="warning" variant="tonal" prepend-icon="mdi-update" :loading="arbeitet.has(anzeige.parts_id)" @click.stop="einstellen(anzeige)">
                                        {{ t('ShopView.kleinanzeigen.auto.updateNow') }}
                                    </v-btn>
                                    <v-btn v-else-if="anzeige.todo === 'delete'" size="small" color="error" variant="tonal" prepend-icon="mdi-delete-outline" :loading="arbeitet.has(anzeige.parts_id)" @click.stop="loeschen(anzeige)">
                                        {{ t('ShopView.kleinanzeigen.auto.deleteNow') }}
                                    </v-btn>
                                    <v-btn v-else-if="anzeige.todo === 'refresh'" size="small" color="info" variant="tonal" prepend-icon="mdi-refresh" :loading="arbeitet.has(anzeige.parts_id)" @click.stop="auffrischen(anzeige)">
                                        {{ t('ShopView.kleinanzeigen.auto.refreshNow') }}
                                    </v-btn>
                                </template>
                                <v-spacer />
                                <v-btn v-if="darfBearbeiten" size="small" color="success" variant="tonal" prepend-icon="mdi-cash-check" @click.stop="verkaufOeffnen(anzeige)">
                                    {{ t('ShopView.kleinanzeigen.action.sold') }}
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-col>
                </v-row>
            </v-window-item>

            <!-- Anfragen aus dem Postfach -->
            <v-window-item value="leads">
                <section-card :title="t('ShopView.kleinanzeigen.leads.title')" :subtitle="letzterAbruf" icon="mdi-message-text-outline" plain>
                    <template #actions>
                        <v-btn size="small" variant="text" prepend-icon="mdi-open-in-new" href="https://www.kleinanzeigen.de/m-nachrichten.html" target="_blank" rel="noopener">
                            {{ t('ShopView.kleinanzeigen.leads.openInbox') }}
                        </v-btn>
                        <v-btn v-if="mailReady" size="small" variant="tonal" prepend-icon="mdi-email-sync-outline" :loading="holtAnfragen" @click="anfragenHolen">
                            {{ t('ShopView.kleinanzeigen.leads.fetch') }}
                        </v-btn>
                    </template>
                    <v-alert v-if="!mailReady" type="info" variant="tonal" density="compact" class="ma-3">{{ t('ShopView.kleinanzeigen.leads.noMail') }}</v-alert>
                    <div v-if="!board?.leads?.length" class="text-body-2 text-medium-emphasis pa-4">{{ t('ShopView.kleinanzeigen.leads.empty') }}</div>
                    <v-list v-else lines="three">
                        <template v-for="lead in board.leads" :key="lead.id">
                            <v-list-item :class="{ 'bg-amber-lighten-5': lead.status === 'open' }">
                                <template #prepend>
                                    <v-avatar :color="leadFarbe(lead.status)" size="36">
                                        <v-icon color="white" size="small">{{ leadIcon(lead.status) }}</v-icon>
                                    </v-avatar>
                                </template>
                                <v-list-item-title class="d-flex align-center flex-wrap ga-2">
                                    <strong>{{ lead.sender_name || t('ShopView.kleinanzeigen.leads.unknownSender') }}</strong>
                                    <span class="text-caption text-medium-emphasis">{{ zeit(lead.received_at) }}</span>
                                    <v-chip size="x-small" variant="tonal" :color="leadFarbe(lead.status)">{{ t(`ShopView.kleinanzeigen.leadStatus.${lead.status}`) }}</v-chip>
                                </v-list-item-title>
                                <v-list-item-subtitle class="text-wrap">
                                    <span v-if="lead.ad_title">{{ t('ShopView.kleinanzeigen.leads.about') }} <strong>{{ lead.ad_title }}</strong></span>
                                    <span v-else class="text-warning">{{ t('ShopView.kleinanzeigen.leads.unassigned') }}</span>
                                    <v-select
                                        v-if="!lead.parts_id && darfBearbeiten && board.online.length"
                                        :items="board.online"
                                        item-title="title"
                                        item-value="parts_id"
                                        :label="t('ShopView.kleinanzeigen.leads.assign')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        class="mt-1"
                                        style="max-width: 420px"
                                        @update:model-value="id => leadZuordnen(lead, id)"
                                    />
                                </v-list-item-subtitle>
                                <div class="text-body-2 mt-1 nachricht">{{ lead.message }}</div>
                                <!-- Aus den Unterhaltungen des Dienstes: Antwort direkt senden; sonst Vorschlag zum Kopieren -->
                                <div v-if="lead.conversation && automatisch && lead.status !== 'won'" class="mt-2">
                                    <v-textarea
                                        v-model="antworten[lead.id]"
                                        :label="t('ShopView.kleinanzeigen.auto.reply')"
                                        rows="2"
                                        auto-grow
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        style="max-width: 720px"
                                    />
                                    <v-btn size="x-small" color="primary" variant="flat" prepend-icon="mdi-send" class="mt-1" :loading="sendetAntwort === lead.id" :disabled="!(antworten[lead.id] || '').trim()" @click="antwortSenden(lead)">
                                        {{ t('ShopView.kleinanzeigen.auto.sendReply') }}
                                    </v-btn>
                                </div>
                                <div v-else-if="antworten[lead.id]" class="mt-2 pa-2 rounded antwort">
                                    <div class="text-caption text-medium-emphasis mb-1">{{ t('ShopView.kleinanzeigen.leads.replySuggestion') }}</div>
                                    <div class="text-body-2" style="white-space: pre-wrap">{{ antworten[lead.id] }}</div>
                                    <v-btn size="x-small" variant="tonal" prepend-icon="mdi-content-copy" class="mt-1" @click="kopieren(antworten[lead.id])">{{ t('ShopView.kleinanzeigen.ad.copy') }}</v-btn>
                                </div>
                                <div class="d-flex flex-wrap ga-1 mt-2">
                                    <v-btn v-if="aiReady && lead.status !== 'won'" size="x-small" variant="tonal" color="primary" prepend-icon="mdi-creation" :loading="antwortLaeuft === lead.id" @click="antwortVorschlagen(lead)">
                                        {{ t('ShopView.kleinanzeigen.leads.suggestReply') }}
                                    </v-btn>
                                    <v-btn v-if="lead.status === 'open'" size="x-small" variant="tonal" prepend-icon="mdi-reply-outline" @click="leadStatus(lead, 'answered')">{{ t('ShopView.kleinanzeigen.leadAction.answered') }}</v-btn>
                                    <v-btn v-if="darfBearbeiten && lead.parts_id && lead.status !== 'won'" size="x-small" variant="tonal" color="success" prepend-icon="mdi-cash-check" @click="verkaufAusAnfrage(lead)">{{ t('ShopView.kleinanzeigen.action.sold') }}</v-btn>
                                    <v-btn v-if="lead.status !== 'lost' && lead.status !== 'won'" size="x-small" variant="text" prepend-icon="mdi-close" @click="leadStatus(lead, 'lost')">{{ t('ShopView.kleinanzeigen.leadAction.lost') }}</v-btn>
                                    <v-btn v-if="lead.status === 'lost' || lead.status === 'answered'" size="x-small" variant="text" prepend-icon="mdi-restore" @click="leadStatus(lead, 'open')">{{ t('ShopView.kleinanzeigen.leadAction.reopen') }}</v-btn>
                                </div>
                            </v-list-item>
                            <v-divider />
                        </template>
                    </v-list>
                </section-card>
            </v-window-item>

            <!-- Verkauft -->
            <v-window-item value="sold">
                <section-card :title="t('ShopView.kleinanzeigen.sold.title')" :subtitle="t('ShopView.kleinanzeigen.sold.subtitle', { betrag: geld(board?.counts?.revenue_total || 0) })" icon="mdi-cash-check" plain>
                    <v-data-table
                        :headers="verkaufSpalten"
                        :items="board?.sold || []"
                        item-value="id"
                        density="compact"
                        :items-per-page="25"
                        :no-data-text="t('ShopView.kleinanzeigen.sold.empty')"
                    >
                        <template #item.sold_at="{ item }">{{ zeit(item.sold_at) }}</template>
                        <template #item.description="{ item }">
                            <router-link v-if="item.parts_id" :to="entityRoute('article', item.parts_id)" class="text-decoration-none">
                                <span class="text-caption text-medium-emphasis">{{ item.partnumber }}</span> {{ item.description }}
                            </router-link>
                            <span v-else>{{ item.description || '—' }}</span>
                        </template>
                        <template #item.qty="{ item }">{{ zahl(item.qty, 0) }}</template>
                        <template #item.price="{ item }">{{ geld(item.price) }}</template>
                        <template #item.total="{ item }">{{ geld(Number(item.qty) * Number(item.price)) }}</template>
                        <template #item.payment="{ item }">{{ t(`ShopView.kleinanzeigen.payment.${item.payment}`) }}</template>
                        <template #item.buyer="{ item }">
                            <router-link v-if="item.customer_id" :to="entityRoute('customer', item.customer_id)" class="text-decoration-none">{{ item.customer_name || item.buyer_name }}</router-link>
                            <span v-else>{{ item.buyer_name || '—' }}</span>
                        </template>
                        <template #item.invnumber="{ item }">
                            <router-link v-if="item.ar_id" :to="entityRoute('invoice', item.ar_id)" class="text-decoration-none">{{ item.invnumber }}</router-link>
                            <span v-else class="text-medium-emphasis">{{ t('ShopView.kleinanzeigen.sold.noInvoice') }}</span>
                        </template>
                    </v-data-table>
                </section-card>
            </v-window-item>
        </v-window>

        <KleinanzeigenAdDialog
            v-model="anzeigeDialog"
            :parts-id="anzeigeId"
            :channel-id="kanalId"
            :ai-ready="aiReady"
            :automatic="automatisch"
            @geaendert="laden"
            @verkaufen="verkaufOeffnen"
        />
        <KleinanzeigenSellDialog
            v-model="verkaufDialog"
            :channel-id="kanalId"
            :ad="verkaufAnzeige"
            :lead-id="verkaufLeadId"
            :buyer-name="verkaufKaeufer"
            @verkauft="verkauft"
        />
    </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'
import SectionCard from '@/core/components/layout/section-card.vue'
import KleinanzeigenAdDialog from '@/features/shop/components/kleinanzeigen-ad.dialog.vue'
import KleinanzeigenSellDialog from '@/features/shop/components/kleinanzeigen-sell.dialog.vue'
import { useShop } from '@/features/shop/composables/useShop.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { aiModelStore } from '@/core/stores/ai-model.store.js'
import { entityRoute } from '@/core/constants/routes.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import * as toasts from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const { t, te, locale } = useI18n()
const route = useRoute()
const shop = useShop()
const oserp = oserpStore()
const aiModels = aiModelStore()

const darfBearbeiten = computed(() => oserp.checkPermission('shop_part_edit') || oserp.checkPermission('edit_shop_config'))
const darfKanaele = computed(() => oserp.checkPermission('edit_shop_config'))

const board = ref(null)
const vorschlaege = ref([])
const kanaele = ref([])
const kanalId = ref(Number(route.query.channel) || 0)
const aiReady = ref(false)
const mailReady = ref(false)
const reiter = ref(route.query.tab ? String(route.query.tab) : 'suggestions')
const gewaehlt = ref([])
const nimmtAuf = ref(false)
const holtAnfragen = ref(false)
const antworten = ref({})
const antwortLaeuft = ref(0)
/** Betriebsart (dev/shop-kleinanzeigen.md, Teil 2): Dienst stellt selbst ein */
const automatisch = ref(false)
const worker = ref(null)
/** Artikel, für die der Dienst gerade arbeitet (parts_id) */
const arbeitet = ref(new Set())
const gleichtAb = ref(false)
const sendetAntwort = ref(0)

const anzeigeDialog = ref(false)
const anzeigeId = ref(0)
const verkaufDialog = ref(false)
const verkaufAnzeige = ref(null)
const verkaufLeadId = ref(0)
const verkaufKaeufer = ref('')

const zahl = (w, stellen = 2) => formatNumber(w ?? 0, locale.value, stellen)
const geld = w => `${formatNumber(w ?? 0, locale.value, 2)} €`
const zeit = w => (w ? new Date(String(w).replace(' ', 'T')).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '')

/** Kanalname, wenn er mehr sagt als der Titel — sonst, was das Board tut */
const untertitel = computed(() => {
    const name = board.value?.channel?.name || ''
    return name && name.toLowerCase() !== t('ShopView.kleinanzeigen.title').toLowerCase() ? name : t('ShopView.kleinanzeigen.subtitle')
})

const letzterAbruf = computed(() => (board.value?.channel?.lead_last_check
    ? t('ShopView.kleinanzeigen.leads.lastCheck', { zeit: zeit(board.value.channel.lead_last_check) })
    : t('ShopView.kleinanzeigen.leads.subtitle')))

const kennzahlen = computed(() => {
    const c = board.value?.counts || {}
    return [
        { key: 'online', wert: c.active || 0, titel: t('ShopView.kleinanzeigen.figures.online'), color: 'success', reiter: 'online' },
        { key: 'value', wert: geld(c.online_value), titel: t('ShopView.kleinanzeigen.figures.onlineValue'), color: 'success', reiter: 'online' },
        { key: 'todo', wert: c.todo || 0, titel: t('ShopView.kleinanzeigen.figures.todo'), color: c.todo ? 'warning' : undefined, reiter: 'online' },
        { key: 'leads', wert: c.leads || 0, titel: t('ShopView.kleinanzeigen.figures.leads'), color: c.leads ? 'error' : undefined, reiter: 'leads' },
        { key: 'revenue', wert: geld(c.revenue_30d), titel: t('ShopView.kleinanzeigen.figures.revenue30'), color: 'primary', reiter: 'sold' },
        { key: 'capital', wert: geld(vorschlaege.value.reduce((s, v) => s + Number(v.capital || 0), 0)), titel: t('ShopView.kleinanzeigen.figures.capital'), color: 'secondary', reiter: 'suggestions' },
    ]
})

const vorschlagSpalten = computed(() => [
    { title: t('ShopView.kleinanzeigen.suggestions.part'), key: 'description', sortable: true },
    { title: t('ShopView.kleinanzeigen.suggestions.stock'), key: 'onhand', align: 'end' },
    { title: t('ShopView.kleinanzeigen.suggestions.capital'), key: 'capital', align: 'end' },
    { title: t('ShopView.kleinanzeigen.suggestions.lastUse'), key: 'idle_months' },
    { title: '', key: 'images', sortable: false, width: 40 },
    { title: t('ShopView.kleinanzeigen.suggestions.price'), key: 'price', align: 'end' },
    { title: '', key: 'aktion', sortable: false, align: 'end' },
])
const verkaufSpalten = computed(() => [
    { title: t('ShopView.kleinanzeigen.sold.date'), key: 'sold_at' },
    { title: t('ShopView.kleinanzeigen.suggestions.part'), key: 'description' },
    { title: t('ShopView.kleinanzeigen.sell.qty'), key: 'qty', align: 'end' },
    { title: t('ShopView.kleinanzeigen.sell.price'), key: 'price', align: 'end' },
    { title: t('ShopView.kleinanzeigen.sell.total'), key: 'total', align: 'end' },
    { title: t('ShopView.kleinanzeigen.sell.payment'), key: 'payment' },
    { title: t('ShopView.kleinanzeigen.sell.buyer'), key: 'buyer' },
    { title: t('ShopView.orders.invnumber'), key: 'invnumber' },
])

const leadFarbe = s => ({ open: 'warning', answered: 'info', won: 'success', lost: 'grey' }[s] || 'grey')
const leadIcon = s => ({ open: 'mdi-email-alert-outline', answered: 'mdi-reply', won: 'mdi-cash-check', lost: 'mdi-close' }[s] || 'mdi-email-outline')

async function laden() {
    const antwort = await shop.fetchKleinanzeigenBoard(kanalId.value || undefined)
    if (!antwort) return
    board.value = antwort.board
    vorschlaege.value = antwort.suggestions || []
    kanaele.value = antwort.channels || []
    kanalId.value = antwort.board?.channel?.channel_id || kanalId.value
    aiReady.value = !!antwort.ai_ready
    mailReady.value = !!antwort.mail_ready
    automatisch.value = !!antwort.automatic
    worker.value = antwort.worker || null
    gewaehlt.value = gewaehlt.value.filter(id => vorschlaege.value.some(v => v.parts_id === id))
}

/** Fehler des Dienstes: übersetzter Code samt Einzelheit des Backends (shopFehler), sonst die rohe Meldung */
function dienstFehler() {
    const code = shop.errorCode.value || ''
    return shop.error.value || (te(`ShopView.errors.${code}`) ? t(`ShopView.errors.${code}`) : t('ShopView.errors.API_ERROR'))
}

/** Letzter Fehler einer Dienst-Aktion — bleibt als Alert stehen, laden() löscht shop.error */
const dienstMeldung = ref('')
const dienstMeldungTitel = ref('')

async function dienstAktion(anzeige, aufruf, erfolg) {
    arbeitet.value = new Set([...arbeitet.value, anzeige.parts_id])
    dienstMeldung.value = ''
    const antwort = await aufruf()
    arbeitet.value = new Set([...arbeitet.value].filter(id => id !== anzeige.parts_id))
    if (!antwort) {
        dienstMeldung.value = dienstFehler()
        dienstMeldungTitel.value = anzeige.title || anzeige.partnumber || ''
        toasts.error(dienstMeldung.value, { timer: 12000 })
        await laden()
        return
    }
    toasts.success(erfolg(antwort))
    await laden()
}

/** Anzeige über den Dienst einstellen oder aktualisieren */
const einstellen = anzeige => dienstAktion(anzeige, () => shop.publishKleinanzeigenAd(anzeige.parts_id, kanalId.value),
    a => t(a.updated ? 'ShopView.kleinanzeigen.auto.updated' : 'ShopView.kleinanzeigen.auto.posted', { id: a.id }))
const loeschen = anzeige => dienstAktion(anzeige, () => shop.deleteKleinanzeigenAd(anzeige.parts_id, kanalId.value),
    () => t('ShopView.kleinanzeigen.auto.deleted'))
const auffrischen = anzeige => dienstAktion(anzeige, () => shop.extendKleinanzeigenAd(anzeige.parts_id, kanalId.value),
    a => t(a.method === 'extended' ? 'ShopView.kleinanzeigen.auto.extended' : 'ShopView.kleinanzeigen.auto.republished'))

async function abgleichen() {
    gleichtAb.value = true
    const antwort = await shop.syncKleinanzeigenAds(kanalId.value)
    gleichtAb.value = false
    if (!antwort) {
        toasts.error(dienstFehler(), { timer: 8000 })
        return
    }
    toasts.success(t('ShopView.kleinanzeigen.auto.synced', antwort))
    laden()
}

async function antwortSenden(lead) {
    sendetAntwort.value = lead.id
    const antwort = await shop.replyKleinanzeigenLead(lead.id, (antworten.value[lead.id] || '').trim())
    sendetAntwort.value = 0
    if (antwort === null) {
        toasts.error(dienstFehler(), { timer: 8000 })
        return
    }
    toasts.success(t('ShopView.kleinanzeigen.auto.replied'))
    antworten.value = { ...antworten.value, [lead.id]: '' }
    laden()
}

async function anbieten(ids) {
    if (!ids.length) return
    nimmtAuf.value = true
    const antwort = await shop.addKleinanzeigenParts(ids, kanalId.value)
    nimmtAuf.value = false
    if (!antwort) return
    toasts.success(t('ShopView.kleinanzeigen.suggestions.offered', { anzahl: antwort.added, bilder: antwort.images_copied }))
    gewaehlt.value = []
    await laden()
    reiter.value = 'drafts'
    // Ein einzelner Artikel: gleich die Anzeige prüfen — auch automatisch
    // stellt der Dienst erst auf Knopfdruck ein, damit Titel und Preis stimmen
    if (ids.length === 1) anzeigeOeffnen({ parts_id: ids[0] })
}

function anzeigeOeffnen(anzeige) {
    anzeigeId.value = Number(anzeige.parts_id)
    anzeigeDialog.value = true
}

async function entfernen(anzeige) {
    const antwort = await alerts.warning(t('ShopView.kleinanzeigen.drafts.removeConfirm', { titel: anzeige.title }), '', t('ShopView.kleinanzeigen.drafts.remove'), t('ShopView.common.cancel'))
    if (!antwort?.isConfirmed) return
    // Abwählen wie in der Artikelkarte: Kanalzeile aus, Entwurf beendet
    const ergebnis = await shop.setKleinanzeigenAdState(anzeige.parts_id, kanalId.value, 'removed')
    if (ergebnis !== null) laden()
}

function verkaufOeffnen(anzeige) {
    verkaufAnzeige.value = anzeige
    verkaufLeadId.value = 0
    verkaufKaeufer.value = ''
    anzeigeDialog.value = false
    verkaufDialog.value = true
}

function verkaufAusAnfrage(lead) {
    const anzeige = (board.value?.online || []).find(a => a.parts_id === lead.parts_id)
        || (board.value?.drafts || []).find(a => a.parts_id === lead.parts_id)
    if (!anzeige) {
        toasts.error(t('ShopView.kleinanzeigen.leads.adGone'))
        return
    }
    verkaufAnzeige.value = anzeige
    verkaufLeadId.value = lead.id
    verkaufKaeufer.value = lead.sender_name || ''
    verkaufDialog.value = true
}

function verkauft() {
    laden()
    reiter.value = 'sold'
}

async function anfragenHolen() {
    holtAnfragen.value = true
    const antwort = await shop.importKleinanzeigenLeads(kanalId.value)
    holtAnfragen.value = false
    if (!antwort) return
    toasts.success(t('ShopView.kleinanzeigen.leads.fetched', antwort))
    laden()
}

async function leadStatus(lead, status) {
    const antwort = await shop.setKleinanzeigenLeadStatus(lead.id, { status })
    if (antwort !== null) laden()
}

async function leadZuordnen(lead, partsId) {
    const antwort = await shop.setKleinanzeigenLeadStatus(lead.id, { parts_id: partsId })
    if (antwort !== null) laden()
}

async function antwortVorschlagen(lead) {
    antwortLaeuft.value = lead.id
    const antwort = await shop.suggestKleinanzeigenReply(lead.id, aiModels.requestModel('kleinanzeigen'))
    antwortLaeuft.value = 0
    if (!antwort?.reply) {
        toasts.error(shop.error.value || t('ShopView.errors.API_ERROR'))
        return
    }
    antworten.value = { ...antworten.value, [lead.id]: antwort.reply }
}

async function kopieren(text) {
    try {
        await navigator.clipboard.writeText(text)
        toasts.success(t('ShopView.kleinanzeigen.ad.copied'), { timer: 1500 })
    } catch {
        toasts.error(t('ShopView.kleinanzeigen.ad.copyFailed'))
    }
}

onMounted(laden)
</script>

<style scoped>
.anzeige-karte { cursor: pointer; }
.anzeige-karte--todo { border-color: rgb(var(--v-theme-warning)); }
.nachricht { white-space: pre-wrap; max-height: 9em; overflow: hidden; }
.antwort { background: var(--oserp-surface-2, rgba(0, 0, 0, 0.03)); }
</style>
