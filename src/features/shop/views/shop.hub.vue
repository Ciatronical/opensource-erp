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

        <!-- Einrichtung: je Verkaufskanal, der noch keine Bestellungen annimmt,
             eine Meldung mit seinem Namen und dem, was ihm fehlt -->
        <template v-if="status && !status.ready">
            <v-alert
                v-for="kanal in status.blocking_channels || []"
                :key="kanal.channel_id"
                type="warning"
                variant="tonal"
                border="start"
                class="mb-4"
            >
                <v-alert-title>{{ t('ShopView.status.notReadyChannel', { name: kanal.name }) }}</v-alert-title>
                <div class="text-body-2 mt-2">{{ t('ShopView.status.notReadyHint') }}</div>
                <ul class="mt-2">
                    <li v-for="punkt in kanal.missing" :key="punkt">
                        {{ feldName(punkt) }}
                    </li>
                </ul>
            </v-alert>
        </template>

        <v-alert
            v-else-if="status"
            type="success"
            variant="tonal"
            density="compact"
            class="mb-4"
            :text="t('ShopView.status.ready')"
        />

        <!-- Ohne Versandart: Bestellungen laufen als „Standard“ ohne Versandkosten -->
        <v-alert
            v-if="status?.shipping_missing"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
        >
            <div class="d-flex flex-wrap align-center ga-2">
                <span>{{ t('ShopView.status.shippingMissing') }}</span>
                <v-spacer />
                <v-btn
                    v-if="oserp.checkPermission('edit_shop_config')"
                    size="small"
                    variant="tonal"
                    prepend-icon="mdi-truck-delivery-outline"
                    :to="{ name: 'shop-shipping' }"
                >
                    {{ t('ShopView.shippingConfig.open') }}
                </v-btn>
            </div>
        </v-alert>

        <!-- Hinweise des Mandanten: gelten für alle Verkaufskanäle -->
        <v-alert
            v-if="status && (status.hints.length || status.publish_problems?.length)"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
        >
            <div class="text-body-2">{{ t('ShopView.status.hintsAll') }}</div>
            <ul class="mt-1">
                <li v-for="punkt in status.hints" :key="punkt">
                    {{ feldName(punkt) }}
                </li>
            </ul>
            <!-- Was genau an der Veröffentlichung hakt: der Feldname allein
                 sagt nicht, dass der Pfad ins Leere zeigt -->
            <div v-if="status.publish_problems?.length" class="text-caption mt-2">
                <div v-for="(grund, index) in status.publish_problems" :key="index">{{ grund }}</div>
            </div>
        </v-alert>

        <!-- Hinweise je Verkaufskanal, mit seinem Namen -->
        <v-alert
            v-for="kanal in status?.hint_channels || []"
            :key="kanal.channel_id"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
        >
            <div class="text-body-2">{{ t('ShopView.status.hintsChannel', { name: kanal.name }) }}</div>
            <ul class="mt-1">
                <li v-for="punkt in kanal.hints" :key="punkt">
                    {{ feldName(punkt) }}
                </li>
            </ul>
            <div v-if="kanal.publish_problems.length" class="text-caption mt-2">
                <div v-for="(grund, index) in kanal.publish_problems" :key="index">{{ grund }}</div>
            </div>
        </v-alert>

        <!-- Empfehlungen: nichts fehlt, aber eine andere Einstellung wäre besser -->
        <v-alert
            v-if="status && status.recommendations?.length"
            type="info"
            variant="tonal"
            density="compact"
            icon="mdi-lightbulb-outline"
            class="mb-4"
        >
            <div class="text-body-2">{{ t('ShopView.status.recommendations') }}</div>
            <ul class="mt-1">
                <li v-for="punkt in status.recommendations" :key="punkt">
                    {{ te(`ShopView.status.recommendation.${punkt}`) ? t(`ShopView.status.recommendation.${punkt}`) : punkt }}
                </li>
            </ul>
        </v-alert>

        <!-- Kennzahlen -->
        <h2 v-if="status" class="text-subtitle-1 font-weight-medium mb-2">{{ t('ShopView.sections.figures') }}</h2>
        <!-- Je Verkaufskanal eine Karte: angebotene Artikel, bei HugoShops dazu
             Warenkörbe und Kundenanmeldungen -->
        <v-row v-if="status" class="mb-2">
            <v-col cols="12" sm="6" md="4" v-for="kanal in kennzahlen" :key="kanal.key">
                <!-- Klick öffnet den Kanal in der Ansicht „Verkaufskanäle“ — nur mit
                     dem Recht, das sie verlangt -->
                <v-card
                    variant="tonal"
                    density="compact"
                    class="fill-height"
                    :link="darfKanaele"
                    :to="darfKanaele ? { name: 'shop-channels', query: { channel: kanal.channel_id } } : undefined"
                >
                    <v-card-item class="pb-0">
                        <template #prepend>
                            <v-icon :icon="kanal.icon" />
                        </template>
                        <v-card-title class="text-subtitle-1">{{ kanal.name }}</v-card-title>
                    </v-card-item>
                    <v-card-text class="d-flex flex-wrap ga-6">
                        <div v-for="zahl in kanal.zahlen" :key="zahl.key">
                            <div class="text-h6">{{ zahl.wert }}</div>
                            <div class="text-caption">{{ zahl.titel }}</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Wege -->
        <h2 class="text-subtitle-1 font-weight-medium mb-2">{{ t('ShopView.sections.links') }}</h2>
        <v-row>
            <v-col cols="12" sm="6" md="4" v-for="ziel in ziele" :key="ziel.titel">
                <v-card
                    variant="outlined"
                    hover
                    @click="router.push({ name: ziel.name, query: ziel.query })"
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

        <!-- Veröffentlichung: die Anwendung legt nur Aufträge an, geschrieben
             und gebaut wird von tools/shop-publish.php -->
        <h2 class="text-subtitle-1 font-weight-medium mt-4 mb-2">{{ t('ShopView.sections.jobs') }}</h2>
        <v-card variant="outlined">
            <v-card-item>
                <template #prepend>
                    <v-icon icon="mdi-cloud-upload-outline" />
                </template>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.publish.title') }}</v-card-title>
                <v-card-subtitle>{{ t('ShopView.publish.hint') }}</v-card-subtitle>
                <template #append>
                    <div class="d-flex flex-wrap ga-2">
                        <!-- Alle offenen Aufträge sofort, ohne sie einzeln auszuwählen -->
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="small"
                            prepend-icon="mdi-play-circle-outline"
                            :loading="offeneStarten"
                            :disabled="!offeneAuftraege || veroeffentlicht || sofort || laeuft"
                            :title="t('ShopView.publish.runOpenHint')"
                            @click="offeneAusfuehren"
                        >
                            {{ t('ShopView.publish.runOpen') }}
                        </v-btn>
                        <v-btn
                            color="primary"
                            variant="flat"
                            size="small"
                            prepend-icon="mdi-cloud-upload-outline"
                            :loading="veroeffentlicht"
                            :disabled="sofort || laeuft"
                            @click="kanaeleWaehlen('queue')"
                        >
                            {{ t('ShopView.publish.all') }}
                        </v-btn>
                        <!-- Abkürzung: Auftrag anlegen, auswählen, sofort ausführen -->
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="small"
                            prepend-icon="mdi-flash"
                            :loading="sofort"
                            :disabled="veroeffentlicht || laeuft"
                            :title="t('ShopView.publish.allNowHint')"
                            @click="kanaeleWaehlen('sofort')"
                        >
                            {{ t('ShopView.publish.allNow') }}
                        </v-btn>
                        <!-- Paket in die Webseite und bauen — ohne auf den Cron zu warten -->
                        <v-btn
                            variant="tonal"
                            size="small"
                            prepend-icon="mdi-package-down"
                            :loading="installiert"
                            :disabled="veroeffentlicht || sofort || laeuft"
                            :title="t('ShopView.publish.installUiHint')"
                            @click="shopUiInstallieren"
                        >
                            {{ t('ShopView.publish.installUi') }}
                        </v-btn>
                    </div>
                </template>
            </v-card-item>

            <!-- Ein Lauf arbeitet im Hintergrund; die Seite bleibt bedienbar -->
            <template v-if="laeuft">
                <v-progress-linear indeterminate color="primary" />
                <v-card-text class="py-2 text-body-2">
                    {{ t('ShopView.publish.inProgress') }}
                </v-card-text>
            </template>
            <v-alert v-if="zuLange" type="info" variant="tonal" density="compact" class="mx-4 my-2">
                {{ t('ShopView.publish.tooLong') }}
            </v-alert>

            <!-- Abbruch und Fehler des letzten Laufs über der Liste — sichtbar,
                 ohne zu scrollen -->
            <!-- Die Meldungen eines Laufs öffnet der Status seiner Aufträge.
                 Ein abgebrochener Lauf hat keine gespeicherte Ausgabe — sein
                 Grund steht deshalb hier, aus der Ausgabe des Prozesses. -->
            <v-alert
                v-if="laufAbgebrochen"
                type="error"
                variant="tonal"
                density="compact"
                class="mx-4 my-2"
                closable
                :close-label="t('ShopView.publish.messagesClose')"
                @click:close="laufAbgebrochen = false"
            >
                <div>{{ t('ShopView.publish.aborted') }}</div>
                <pre v-if="laufAusgabe.length" class="text-caption mt-1 mb-0 laufausgabe">{{ laufAusgabe.join('\n') }}</pre>
            </v-alert>

            <!-- Fehler des letzten Laufs im Wortlaut — etwa Artikel ohne
                 passende Versandart. Bleibt stehen, bis es geschlossen wird. -->
            <v-alert
                v-else-if="laufFehler.length"
                type="error"
                variant="tonal"
                density="compact"
                class="mx-4 my-2"
                closable
                :close-label="t('ShopView.publish.messagesClose')"
                @click:close="laufFehler = []"
            >
                <div>{{ t('ShopView.publish.errorsTitle', { count: laufFehler.length }) }}</div>
                <pre class="text-caption mt-1 mb-0 laufausgabe">{{ laufFehler.join('\n') }}</pre>
            </v-alert>

            <v-card-text v-if="auftraege.length">
                <div class="d-flex align-center ga-4 mb-2">
                    <v-checkbox-btn
                        :model-value="alleGewaehlt"
                        :indeterminate="teilsGewaehlt"
                        :disabled="!auftraege.length"
                        :label="t('ShopView.publish.selectAll')"
                        density="compact"
                        hide-details
                        @update:model-value="alleUmschalten"
                    />
                    <div class="text-caption text-medium-emphasis">
                        {{ t('ShopView.publish.open', { count: offeneAuftraege }) }}
                    </div>
                    <v-spacer />
                    <v-btn
                        color="primary"
                        variant="tonal"
                        size="small"
                        prepend-icon="mdi-play"
                        :disabled="!offeneAuswahl.length || gescheiterteAuswahl.length > 0"
                        :loading="laeuft"
                        :title="gescheiterteAuswahl.length ? t('ShopView.publish.runBlocked') : undefined"
                        @click="ausgewaehlteAusfuehren"
                    >
                        {{ t('ShopView.publish.run') }}
                    </v-btn>
                    <!-- Fehlgeschlagene wieder öffnen und gleich ausführen -->
                    <v-btn
                        color="warning"
                        variant="tonal"
                        size="small"
                        prepend-icon="mdi-replay"
                        :disabled="!gescheiterteAuswahl.length || laeuft"
                        :loading="wiederholt"
                        :title="t('ShopView.publish.retryHint')"
                        @click="ausgewaehlteWiederholen"
                    >
                        {{ t('ShopView.publish.retry') }}
                    </v-btn>
                    <!-- Nur wieder öffnen: der nächste Lauf des Cron nimmt sie mit -->
                    <v-btn
                        variant="text"
                        size="small"
                        prepend-icon="mdi-clock-outline"
                        :disabled="!gescheiterteAuswahl.length || laeuft"
                        :loading="wiederGeoeffnet"
                        :title="t('ShopView.publish.reopenHint')"
                        @click="ausgewaehlteWiederOeffnen"
                    >
                        {{ t('ShopView.publish.reopen') }}
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="text"
                        size="small"
                        prepend-icon="mdi-delete-outline"
                        :disabled="!auswahl.length || laeuft"
                        :loading="loescht"
                        :title="t('ShopView.publish.deleteHint')"
                        @click="loeschenGefragt = true"
                    >
                        {{ t('ShopView.publish.delete') }}
                    </v-btn>
                    <v-btn
                        variant="text"
                        size="small"
                        prepend-icon="mdi-broom"
                        :disabled="!erledigteAuftraege"
                        :loading="raeumtAuf"
                        :title="t('ShopView.publish.cleanupHint')"
                        @click="aufraeumenGefragt = true"
                    >
                        {{ t('ShopView.publish.cleanup') }}
                    </v-btn>
                </div>
                <v-table density="compact">
                    <tbody>
                        <!-- Klick auf die Zeile wählt aus; das Ankreuzfeld
                             behält seinen eigenen Klick (sonst höbe die Zeile
                             ihn gleich wieder auf) -->
                        <tr
                            v-for="auftrag in auftraege"
                            :key="auftrag.id"
                            class="auftragszeile"
                            @click="auswahlUmschalten(auftrag.id)"
                        >
                            <td style="width: 1%" @click.stop>
                                <v-checkbox-btn
                                    v-model="auswahl"
                                    :value="auftrag.id"
                                    density="compact"
                                    hide-details
                                />
                            </td>
                            <td style="width: 1%">
                                <v-icon
                                    size="small"
                                    :color="istOffen(auftrag) ? 'grey' : (fehlgeschlagen(auftrag) ? 'error' : 'success')"
                                    :icon="istOffen(auftrag) ? 'mdi-clock-outline' : (fehlgeschlagen(auftrag) ? 'mdi-alert-circle-outline' : 'mdi-check')"
                                />
                            </td>
                            <td class="text-caption text-no-wrap">{{ zeitpunkt(auftrag.itime) }}</td>
                            <td>
                                {{ auftragsart(auftrag.function) }}
                                <!-- Kanal des Auftrags (channels/): gleiche Auftragsart gibt es je Kanal -->
                                <span v-if="auftrag.channel" class="text-medium-emphasis">
                                    · {{ auftrag.channel_name || (te(`ShopView.channels.${auftrag.channel}`) ? t(`ShopView.channels.${auftrag.channel}`) : auftrag.channel) }}
                                </span>
                            </td>
                            <!-- Zum Artikel, sofern es ihn (noch) gibt -->
                            <td v-if="auftrag.parts_id" @click.stop>
                                <router-link
                                    :to="entityRoute('article', auftrag.parts_id)"
                                    class="artikelverweis"
                                    :title="t('ShopView.publish.openArticle')"
                                >{{ auftrag.partnumber }}</router-link>
                            </td>
                            <td v-else>{{ auftrag.partnumber }}</td>
                            <!-- Mit gespeicherter Ausgabe öffnet der Status den Lauf -->
                            <td v-if="auftrag.run_id" class="text-caption" @click.stop>
                                <a
                                    href="#"
                                    class="laufverweis"
                                    :class="fehlgeschlagen(auftrag) ? 'text-error' : ''"
                                    :title="t('ShopView.publish.outputHint')"
                                    @click.prevent="ausgabeZeigen(auftrag)"
                                >{{ auftrag.result }}</a>
                            </td>
                            <td v-else class="text-caption">{{ auftrag.result || t('ShopView.publish.notExecuted') }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
            <v-card-text v-else class="text-caption text-medium-emphasis">
                {{ t('ShopView.publish.empty') }}
            </v-card-text>
        </v-card>

        <!-- Ausgabe des Laufs, in dem ein Auftrag erledigt wurde -->
        <v-dialog v-model="ausgabeOffen" max-width="1000" scrollable>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <span>{{ t('ShopView.publish.output') }}</span>
                    <v-spacer />
                    <v-btn
                        variant="text"
                        size="small"
                        icon="mdi-content-copy"
                        :disabled="!ausgabe"
                        :title="t('ShopView.publish.outputCopy')"
                        @click="ausgabeKopieren"
                    />
                    <v-btn variant="text" size="small" icon="mdi-close" @click="ausgabeOffen = false" />
                </v-card-title>
                <v-card-subtitle v-if="ausgabe">
                    {{ ausgabeAuftrag ? auftragsart(ausgabeAuftrag.function) : '' }}
                    {{ ausgabeAuftrag?.partnumber ? '· ' + ausgabeAuftrag.partnumber : '' }}
                    · {{ t('ShopView.publish.outputTime', { start: zeitpunkt(ausgabe.itime), end: zeitpunkt(ausgabe.finished) }) }}
                </v-card-subtitle>
                <v-card-text>
                    <v-progress-linear v-if="ausgabeLaedt" indeterminate color="primary" />
                    <div v-else-if="!ausgabeZeilen.length" class="text-caption text-medium-emphasis">
                        {{ t('ShopView.publish.outputEmpty') }}
                    </div>
                    <!-- „Alle Produkte“ hat eine Zeile je Seite: nur sichtbare Zeilen zeichnen -->
                    <v-virtual-scroll v-else :items="ausgabeZeilen" max-height="60vh" item-height="20">
                        <template #default="{ item }">
                            <div
                                class="text-caption laufausgabe"
                                :class="zeilenKlasse(item)"
                            >{{ item }}</div>
                        </template>
                    </v-virtual-scroll>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Rückfrage vor dem Löschen der Auswahl -->
        <!-- Bei mehreren HugoShops: wofür „Alle veröffentlichen“ gelten soll -->
        <v-dialog v-model="kanalwahlOffen" max-width="460">
            <v-card>
                <v-card-title>{{ kanalwahlArt === 'sofort' ? t('ShopView.publish.allNow') : t('ShopView.publish.all') }}</v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-2">{{ t('ShopView.publish.chooseChannels') }}</div>
                    <v-checkbox
                        v-for="kanal in hugoshops"
                        :key="kanal.channel_id"
                        v-model="gewaehlteKanaele"
                        :value="kanal.channel_id"
                        :label="kanal.name"
                        density="compact"
                        hide-details
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="kanalwahlOffen = false">{{ t('ShopView.publish.cancel') }}</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :disabled="!gewaehlteKanaele.length"
                        @click="kanalwahlBestaetigen"
                    >
                        {{ kanalwahlArt === 'sofort' ? t('ShopView.publish.allNow') : t('ShopView.publish.all') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="loeschenGefragt" max-width="460">
            <v-card>
                <v-card-title>{{ t('ShopView.publish.delete') }}</v-card-title>
                <v-card-text>
                    <div>{{ t('ShopView.publish.deleteConfirm', { count: auswahl.length }) }}</div>
                    <div class="text-caption text-medium-emphasis mt-2">{{ t('ShopView.publish.deleteHint') }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="loeschenGefragt = false">{{ t('ShopView.publish.cancel') }}</v-btn>
                    <v-btn color="error" variant="flat" :loading="loescht" @click="ausgewaehlteLoeschen">
                        {{ t('ShopView.publish.delete') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Rückfrage vor dem Aufräumen: gelöscht wird endgültig -->
        <v-dialog v-model="aufraeumenGefragt" max-width="460">
            <v-card>
                <v-card-title>{{ t('ShopView.publish.cleanup') }}</v-card-title>
                <v-card-text>
                    <div>{{ t('ShopView.publish.cleanupConfirm', { count: erledigteAuftraege }) }}</div>
                    <div class="text-caption text-medium-emphasis mt-2">{{ t('ShopView.publish.cleanupHint') }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="aufraeumenGefragt = false">{{ t('ShopView.publish.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="raeumtAuf" @click="aufraeumen">
                        {{ t('ShopView.publish.cleanup') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

    </v-container>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useShop } from '@/features/shop/composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'
import { entityRoute } from '@/core/constants/routes.js'
import { oserpStore } from '@/core/stores/oserp.store.js'

const { t, te, locale } = useI18n()
const router = useRouter()
const oserp = oserpStore()
const shop = useShop()

const status = ref(null)
const auftraege = ref([])
const veroeffentlicht = ref(false)
const sofort = ref(false)
const offeneStarten = ref(false)
/** Ausgabe eines Laufs im Dialog */
const ausgabeOffen = ref(false)
const ausgabeLaedt = ref(false)
const ausgabe = ref(null)
const ausgabeAuftrag = ref(null)
const ausgabeZeilen = computed(() => ausgabe.value?.output ? ausgabe.value.output.split('\n') : [])
const installiert = ref(false)
const auswahl = ref([])
const laeuft = ref(false)
const raeumtAuf = ref(false)
/** Der letzte Lauf kam nicht zu Ende, dazu die Ausgabe des Prozesses */
const laufAbgebrochen = ref(false)
const laufAusgabe = ref([])
/** Fehlerzeilen des zuletzt beendeten Laufs (summary.error_lines) */
const laufFehler = ref([])
/** Die Abfrageschleife hat ihre Obergrenze erreicht, der Lauf arbeitet vermutlich weiter */
const zuLange = ref(false)

/**
 * Eigene Instanz für die Abfrageschleife
 *
 * useShop() setzt bei jedem Aufruf loading und error zurück. Liefe die
 * Schleife über `shop`, verschwände alle zwei Sekunden eine Fehlermeldung, die
 * gerade angezeigt wird.
 */
const beobachter = useShop()

// Abfragetakt wie bei der Live-Analyse in HugoCMS: rekursives setTimeout statt
// setInterval, damit sich Abfragen bei langsamer Antwort nicht überlappen;
// 2 s, nach einer Minute 5 s, nach 15 Minuten Schluss.
const ABFRAGE_SCHNELL_MS = 2000
const ABFRAGE_LANGSAM_MS = 5000
const ABFRAGE_LANGSAM_NACH_MS = 60000
const ABFRAGE_HOECHSTENS_MS = 15 * 60000
let abfrageTimer = null
let abfrageBeginn = 0
const aufraeumenGefragt = ref(false)
const loescht = ref(false)
const wiederholt = ref(false)
const wiederGeoeffnet = ref(false)
const loeschenGefragt = ref(false)

/** Wahrheitswerte kommen je nach Treiber als true oder 't' */
const istOffen = (auftrag) => auftrag.open === true || auftrag.open === 't'

/**
 * Fehlerzeile in einer gespeicherten Ausgabe
 *
 * Anders als beim laufenden Lauf gibt es dazu keine Liste der Fehler; die
 * Meldungen der Erweiterung nennen sie aber beim Namen.
 */
const istFehlerText = (zeile) => /fehlgeschlagen|fehler|nicht gebaut|nicht erzeugt/i.test(zeile)

/** Überschrift eines Kanals in der gespeicherten Ausgabe (SHOP_RUN_HEADING im Backend) */
const istUeberschrift = (zeile) => String(zeile).startsWith('━━ ')

/** Darstellung einer Zeile der Ausgabe: Überschrift fett, Fehler rot, sonst gedämpft */
function zeilenKlasse(zeile) {
    // Abstand nach oben: trennt die Kanäle; v-virtual-scroll misst jede Zeile selbst
    if (istUeberschrift(zeile)) return 'font-weight-bold pt-4'
    return istFehlerText(zeile) ? 'text-error font-weight-medium' : 'text-medium-emphasis'
}
const fehlgeschlagen = (auftrag) => String(auftrag.result || '').startsWith('Fehler')

/**
 * Name der Auftragsart
 *
 * Unbekannte Arten stehen roh da: die Auftragstabelle stammt aus der Bridge
 * und kann Zeilen enthalten, die nicht aus dieser Erweiterung kommen.
 */
const auftragsart = (name) => te(`ShopView.publish.functions.${name}`)
    ? t(`ShopView.publish.functions.${name}`)
    : name

const offeneAuftraege = computed(() => auftraege.value.filter(istOffen).length)

/**
 * Erfolgreich erledigte Aufträge in der Liste
 *
 * Nur sie werden aufgeräumt. Die Liste zeigt die letzten 20 erledigten; in der
 * Tabelle können mehr stehen, gelöscht werden immer alle erfolgreichen.
 */
const erledigteAuftraege = computed(
    () => auftraege.value.filter((auftrag) => !istOffen(auftrag) && !fehlgeschlagen(auftrag)).length)

/**
 * Auswählen lässt sich jede Zeile
 *
 * Die offenen für „Jetzt ausführen", die erledigten zum Löschen — deshalb
 * zwei Teilmengen statt einer.
 */
const alleIds = computed(() => auftraege.value.map((auftrag) => auftrag.id))
const offeneIds = computed(() => auftraege.value.filter(istOffen).map((auftrag) => auftrag.id))
const offeneAuswahl = computed(() => auswahl.value.filter((id) => offeneIds.value.includes(id)))

/**
 * Gescheiterte Aufträge in der Auswahl
 *
 * Sie sperren „Jetzt ausführen": wer einen Fehlschlag angekreuzt hat, will
 * aufräumen, nicht starten — und ausführen ließe sich ein erledigter Auftrag
 * ohnehin nicht.
 */
const gescheiterteAuswahl = computed(
    () => auftraege.value.filter((auftrag) => auswahl.value.includes(auftrag.id) && fehlgeschlagen(auftrag))
        .map((auftrag) => auftrag.id))
const alleGewaehlt = computed(() => alleIds.value.length > 0 && auswahl.value.length === alleIds.value.length)
const teilsGewaehlt = computed(() => auswahl.value.length > 0 && !alleGewaehlt.value)

function alleUmschalten(gewaehlt) {
    auswahl.value = gewaehlt ? [...alleIds.value] : []
}

/** Ein Auftrag mehr oder weniger in der Auswahl */
function auswahlUmschalten(id) {
    const stelle = auswahl.value.indexOf(id)
    if (-1 === stelle) {
        auswahl.value.push(id)
    } else {
        auswahl.value.splice(stelle, 1)
    }
}

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
 * Nicht jeder gemeldete Punkt ist eine Einstellung — no_parts_offered etwa
 * meint einen Kanal ohne angebotene Artikel. Solche Punkte stehen unter
 * ShopView.status.points. Ist ein Schlüssel nirgends übersetzt, bleibt er
 * stehen, statt eine leere Zeile zu zeigen.
 */
function feldName(schluessel) {
    const eigener = `ShopView.status.points.${schluessel}`
    if (te(eigener)) return t(eigener)
    const key = 'crm_fields.' + schluessel.replace(/_([a-z])/g, (_, z) => z.toUpperCase())
    return te(key) ? t(key) : schluessel
}

/** Ansicht „Verkaufskanäle“ verlangt edit_shop_config — ohne das Recht kein Klick auf die Kennzahlen */
const darfKanaele = computed(() => oserp.checkPermission('edit_shop_config'))

const kennzahlen = computed(() => {
    if (!status.value) return []
    return (status.value.counts.channels || []).map(kanal => ({
        key: `kanal-${kanal.channel_id}`,
        channel_id: kanal.channel_id,
        name: kanal.name,
        icon: kanal.type === 'ebay' ? 'mdi-shopping-outline' : 'mdi-storefront-outline',
        zahlen: [
            { key: 'parts', wert: kanal.parts, titel: t('ShopView.status.partsOffered') },
            // Warenkörbe und Kundenanmeldungen gibt es nur im HugoShop
            ...(kanal.type === 'hugoshop' ? [
                { key: 'carts', wert: kanal.carts, titel: t('ShopView.status.carts') },
                { key: 'logins', wert: kanal.logins, titel: t('ShopView.status.logins') },
            ] : []),
        ],
    }))
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
    // Die Artikelliste des Kerns, vorgefiltert auf „Nur im Shop angebotene“
    // mit „Alle Verkaufskanäle“ (Vorgabe der Kanalauswahl)
    {
        name: 'article-list',
        query: { shop: 'offered' },
        icon: 'mdi-package-variant-closed',
        titel: t('ShopView.parts.title'),
        text: t('ShopView.parts.subtitle'),
    },
    // Angebotene Artikel ohne passende Versandart — ihre Seite wird nicht
    // veröffentlicht (dev/shop-versand.md, Nachtrag 2026-10-02)
    {
        name: 'article-list',
        query: { shop: 'offered', shipping: 'unfit' },
        icon: 'mdi-truck-alert-outline',
        titel: t('ShopView.partsShippingUnfit.title'),
        text: t('ShopView.partsShippingUnfit.subtitle'),
    },
    // Verkaufskanäle (dev/shop-mehrere-kanaele.md) und Versandarten
    // (dev/shop-versand.md) einrichten — nur mit dem Recht, das ihre API
    // verlangt
    ...(oserp.checkPermission('edit_shop_config') ? [{
        name: 'shop-channels',
        icon: 'mdi-store-cog',
        titel: t('ShopView.channelConfig.title'),
        text: t('ShopView.channelConfig.subtitle'),
    }, {
        name: 'shop-shipping',
        icon: 'mdi-truck-delivery-outline',
        titel: t('ShopView.shippingConfig.title'),
        text: t('ShopView.shippingConfig.subtitle'),
    }] : []),
])

async function laden() {
    status.value = await shop.fetchStatus()
    auftraege.value = await shop.fetchPublishJobs() || []
    // Gelöschte Aufträge fallen aus der Auswahl
    auswahl.value = auswahl.value.filter((id) => alleIds.value.includes(id))
}

/**
 * Führt die ausgewählten Aufträge sofort aus
 *
 * Damit lässt sich ohne Cron-Eintrag veröffentlichen. Ist gerade ein anderer
 * Lauf unterwegs — der Cron oder ein zweiter Mitarbeiter —, wird nichts getan;
 * er nimmt die offenen Aufträge ohnehin mit.
 */
async function ausgewaehlteAusfuehren() {
    await ausfuehren(offeneAuswahl.value)
}

/**
 * Zeigt die Ausgabe des Laufs, in dem ein Auftrag erledigt wurde
 *
 * @param {object} auftrag Zeile der Liste
 */
async function ausgabeZeigen(auftrag) {
    ausgabeAuftrag.value = auftrag
    ausgabe.value = null
    ausgabeOffen.value = true
    ausgabeLaedt.value = true
    try {
        ausgabe.value = await shop.fetchPublishRunOutput(auftrag.id)
        if (shop.error.value) {
            ausgabeOffen.value = false
        }
    } finally {
        ausgabeLaedt.value = false
    }
}

async function ausgabeKopieren() {
    try {
        await navigator.clipboard.writeText(ausgabe.value?.output || '')
        toasts.success(t('ShopView.publish.outputCopied'))
    } catch {
        toasts.error(t('ShopView.publish.outputCopyFailed'))
    }
}

/**
 * Führt alle offenen Aufträge sofort aus
 *
 * Ohne Auftragsnummern arbeitet der Läufer alle offenen ab — auch solche, die
 * nach dem letzten Laden der Liste hinzugekommen sind.
 */
async function offeneAusfuehren() {
    offeneStarten.value = true
    try {
        auswahl.value = [...offeneIds.value]
        await ausfuehren([])
    } finally {
        offeneStarten.value = false
    }
}

/**
 * Führt Aufträge aus und meldet das Ergebnis
 *
 * Gemeinsam für „Jetzt ausführen" und „Alle sofort veröffentlichen".
 *
 * @param {number[]} ids Auftragsnummern
 */
async function ausfuehren(ids) {
    const antwort = await shop.runPublishJobs(ids)
    if (shop.error.value) {
        return
    }
    gestartet(antwort)
}

/**
 * Installiert die Shop-Benutzerschnittstelle in der Webseite
 *
 * Das Backend legt einen Auftrag „Paket abgleichen“ an, der auch dann baut,
 * wenn das Paket schon aktuell war, und startet ihn sofort. Fehlende Mounts
 * oder params.shopui stehen danach als Hinweis in der Ausgabe des Laufs.
 */
async function shopUiInstallieren() {
    installiert.value = true
    try {
        const antwort = await shop.installShopUi()
        if (shop.error.value) {
            return
        }
        // Je eingeschaltetem HugoShop ein Auftrag (dev/shop-mehrere-kanaele.md)
        if (antwort?.job_ids?.length) {
            auswahl.value = [...antwort.job_ids]
        } else if (antwort?.job_id) {
            auswahl.value = [antwort.job_id]
        }
        gestartet(antwort)
    } finally {
        installiert.value = false
    }
}

/**
 * Ein Lauf wurde angestoßen: melden und beobachten
 *
 * @param {object|null} antwort Antwort von runShopPublishJobs bzw. installShopUi
 */
function gestartet(antwort) {
    // Der Läufer arbeitet jetzt im Hintergrund; die Antwort kommt sofort.
    // Lief schon einer, nimmt der die Aufträge mit — beobachtet wird so oder so.
    toasts.info(antwort?.started === false ? t('ShopView.publish.running') : t('ShopView.publish.started'))
    laufAbgebrochen.value = false
    laufAusgabe.value = []
    laufFehler.value = []
    beobachten()
}

/** Übernimmt den Stand aus getShopPublishStatus in die Anzeige */
function standUebernehmen(stand) {
    laufAbgebrochen.value = !!stand.aborted
    laufAusgabe.value = stand.output || []
}

/** Startet die Abfrageschleife — nach einem Start oder wenn beim Öffnen schon ein Lauf arbeitet */
function beobachten() {
    beobachtenBeenden()
    laeuft.value = true
    zuLange.value = false
    abfrageBeginn = Date.now()
    abfrageTimer = setTimeout(abfragen, 500)
}

/** Beendet nur die Schleife; der Lauf im Hintergrund bleibt davon unberührt */
function beobachtenBeenden() {
    if (abfrageTimer) {
        clearTimeout(abfrageTimer)
        abfrageTimer = null
    }
}

async function abfragen() {
    abfrageTimer = null
    const stand = await beobachter.fetchPublishStatus()
    if (!stand) {
        // Abfrage gescheitert (Netz, Anmeldung abgelaufen): Schleife beenden,
        // der Grund steht in der Fehlerzeile oben
        shop.error.value = beobachter.error.value
        laeuft.value = false
        return
    }

    standUebernehmen(stand)
    // Die Liste gleich mit — jede Zeile wechselt von „nicht ausgeführt" zu
    // ihrem Ergebnis, sobald der Läufer sie erledigt hat
    auftraege.value = await beobachter.fetchPublishJobs() || auftraege.value

    if (!stand.running) {
        await laufBeendet(stand)
        return
    }

    const vergangen = Date.now() - abfrageBeginn
    if (vergangen > ABFRAGE_HOECHSTENS_MS) {
        zuLange.value = true
        laeuft.value = false
        return
    }
    abfrageTimer = setTimeout(abfragen, vergangen > ABFRAGE_LANGSAM_NACH_MS ? ABFRAGE_LANGSAM_MS : ABFRAGE_SCHNELL_MS)
}

/** Der Lauf ist fertig oder abgebrochen: Bilanz melden, Übersicht neu laden */
async function laufBeendet(stand) {
    laeuft.value = false

    if (stand.aborted) {
        toasts.error(t('ShopView.publish.aborted'))
    } else if (stand.summary) {
        const bilanz = stand.summary
        laufFehler.value = stand.error_lines || bilanz.error_lines || []
        const text = t('ShopView.publish.done', {
            jobs: bilanz.jobs ?? 0,
            pages: bilanz.pages ?? 0,
            errors: bilanz.errors ?? 0,
        })
        if (bilanz.errors) {
            toasts.warning(text)
        } else {
            toasts.success(bilanz.built ? `${text} ${t('ShopView.publish.built')}` : text)
        }
    }

    auswahl.value = []
    await laden()
}

/**
 * Löscht die ausgewählten Aufträge
 *
 * Gedacht für fehlgeschlagene: Fehler gelesen, Zeile weg. Offene lassen sich
 * ebenso löschen — sie sind danach nicht mehr vorgemerkt.
 */
/**
 * Wiederholt die gewählten fehlgeschlagenen Aufträge
 *
 * Das Backend öffnet sie wieder und führt genau sie aus; die übrige Auswahl
 * bleibt unberührt. Danach wird der Lauf beobachtet wie bei „Jetzt ausführen“.
 */
async function ausgewaehlteWiederholen() {
    wiederholt.value = true
    const antwort = await shop.retryPublishJobs(gescheiterteAuswahl.value)
    wiederholt.value = false
    if (shop.error.value) {
        return
    }
    auswahl.value = []
    gestartet(antwort)
}

/**
 * Öffnet die gewählten fehlgeschlagenen Aufträge wieder, ohne sie auszuführen
 *
 * Der nächste Lauf des Cron nimmt sie mit; die Liste zeigt sie gleich als offen.
 */
async function ausgewaehlteWiederOeffnen() {
    wiederGeoeffnet.value = true
    const antwort = await shop.retryPublishJobs(gescheiterteAuswahl.value, false)
    wiederGeoeffnet.value = false
    if (shop.error.value) {
        return
    }
    toasts.success(t('ShopView.publish.reopened', { count: antwort?.retried ?? 0 }))
    auswahl.value = []
    await laden()
}

async function ausgewaehlteLoeschen() {
    loescht.value = true
    const ergebnis = await shop.deletePublishJobs(auswahl.value)
    loescht.value = false
    loeschenGefragt.value = false

    if (!shop.error.value) {
        toasts.success(t('ShopView.publish.deleted', { count: ergebnis?.removed ?? 0 }))
    }

    await laden()
}

/**
 * Löscht die erfolgreich erledigten Aufträge
 *
 * Damit die Auftragstabelle nicht vollläuft. Fehlgeschlagene bleiben stehen,
 * damit die Ursache sichtbar bleibt; der Läufer räumt zusätzlich regelmäßig
 * nach der Aufbewahrungsfrist aus der Firmenkonfiguration.
 */
async function aufraeumen() {
    raeumtAuf.value = true
    const ergebnis = await shop.cleanupPublishJobs()
    raeumtAuf.value = false
    aufraeumenGefragt.value = false

    if (!shop.error.value) {
        toasts.success(t('ShopView.publish.cleaned', { count: ergebnis?.removed ?? 0 }))
        // Wer aufräumt, hat die Meldungen des letzten Laufs gelesen
        laufFehler.value = []
        laufAbgebrochen.value = false
    }

    await laden()
}

/**
 * Alle Artikel veröffentlichen, ohne Umweg über die Liste
 *
 * Kürzt die drei Schritte „Alle veröffentlichen", den neuen Auftrag ankreuzen
 * und „Jetzt ausführen" ab. Steht schon ein offener Auftrag „Alle Produkte" in
 * der Warteschlange, legt das Backend keinen zweiten an — dann wird dieser
 * ausgeführt. Ist er inzwischen weg, hat ihn ein anderer Lauf (der Cron)
 * übernommen; das wird gemeldet wie bei „Jetzt ausführen".
 */
// ── Kanalwahl für „Alle veröffentlichen“ ──

/** Eingeschaltete HugoShops (aus der Einrichtungsprüfung) */
const hugoshops = computed(() => (status.value?.counts?.channels || []).filter((k) => k.type === 'hugoshop'))
const kanalwahlOffen = ref(false)
/** 'queue' = nur Auftrag anlegen, 'sofort' = anlegen und ausführen */
const kanalwahlArt = ref('queue')
const gewaehlteKanaele = ref([])

/**
 * Bei mehr als einem HugoShop erst fragen, für welche; sonst gleich los
 *
 * Vorausgewählt sind alle — wer nur einen will, nimmt die übrigen heraus.
 */
function kanaeleWaehlen(art) {
    if (hugoshops.value.length <= 1) {
        return art === 'sofort' ? alleSofortVeroeffentlichen() : alleVeroeffentlichen()
    }
    kanalwahlArt.value = art
    gewaehlteKanaele.value = hugoshops.value.map((k) => k.channel_id)
    kanalwahlOffen.value = true
}

function kanalwahlBestaetigen() {
    kanalwahlOffen.value = false
    const kanaele = [...gewaehlteKanaele.value]
    return kanalwahlArt.value === 'sofort' ? alleSofortVeroeffentlichen(kanaele) : alleVeroeffentlichen(kanaele)
}

async function alleSofortVeroeffentlichen(kanaele = []) {
    sofort.value = true
    try {
        const angelegt = await shop.publishAll(kanaele)
        if (shop.error.value) {
            return
        }

        // Je eingeschaltetem HugoShop ein Auftrag „Alle Produkte"; schon offene
        // legt das Backend nicht doppelt an — dann werden die offenen ausgeführt
        let ids = angelegt?.job_ids?.length ? [...angelegt.job_ids] : (angelegt?.job_id ? [angelegt.job_id] : [])
        if (!ids.length) {
            auftraege.value = await shop.fetchPublishJobs() || []
            ids = auftraege.value
                .filter((auftrag) => istOffen(auftrag) && auftrag.function === 'publish_all' && auftrag.channel === 'hugoshop'
                    && (!kanaele.length || kanaele.includes(Number(auftrag.channel_id))))
                .map((auftrag) => auftrag.id)
        }

        if (!ids.length) {
            toasts.info(t('ShopView.publish.running'))
            await laden()
            return
        }

        // Ausgewählt wie von Hand — die Zeilen sind während des Laufs markiert
        auswahl.value = ids
        await ausfuehren(ids)
    } finally {
        sofort.value = false
    }
}

/**
 * Nimmt alle Artikel des Shops in die Veröffentlichung auf
 *
 * Legt einen Auftrag an; die Seiten entstehen beim nächsten Lauf des
 * Veröffentlichungs-Skripts.
 */
async function alleVeroeffentlichen(kanaele = []) {
    veroeffentlicht.value = true
    const ergebnis = await shop.publishAll(kanaele)
    veroeffentlicht.value = false

    if (!shop.error.value) {
        toasts.success(ergebnis?.queued === false
            ? t('ShopView.publish.already')
            : t('ShopView.publish.queued'))
    }
    auftraege.value = await shop.fetchPublishJobs() || []
}

/**
 * Beim Öffnen den Stand holen
 *
 * Ein abgebrochener Lauf steht so auch nach dem Neuladen da, und ein Lauf,
 * der gerade arbeitet — auch einer aus dem Cron —, wird gleich verfolgt.
 */
async function standBeimOeffnen() {
    const stand = await beobachter.fetchPublishStatus()
    if (!stand) {
        return
    }
    standUebernehmen(stand)
    if (stand.running) {
        beobachten()
    }
}

onMounted(async () => {
    await laden()
    await standBeimOeffnen()
})

onBeforeUnmount(beobachtenBeenden)
</script>

<style scoped>
.auftragszeile {
    cursor: pointer;
}

.artikelverweis {
    color: inherit;
    text-decoration: underline dotted;
}

.laufverweis {
    color: inherit;
    text-decoration: underline dotted;
    cursor: pointer;
}

/* Ausgabe von Läufer und Hugo in Festbreitenschrift: Hugo gibt seine
   Zusammenfassung als Tabelle aus Leerzeichen, | und - aus — nur bei gleich
   breiten Zeichen stehen die Spalten untereinander */
.laufausgabe {
    font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, 'Liberation Mono', monospace;
    font-size: 0.75rem;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
