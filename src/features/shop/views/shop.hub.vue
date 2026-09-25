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
            <!-- Was genau an der Veröffentlichung hakt: der Feldname allein
                 sagt nicht, dass der Pfad ins Leere zeigt -->
            <div v-if="status.publish_problems?.length" class="text-caption mt-2">
                <div v-for="(grund, index) in status.publish_problems" :key="index">{{ grund }}</div>
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

        <!-- Veröffentlichung: die Anwendung legt nur Aufträge an, geschrieben
             und gebaut wird von tools/shop-publish.php -->
        <v-card variant="outlined" class="mt-4">
            <v-card-item>
                <template #prepend>
                    <v-icon icon="mdi-cloud-upload-outline" />
                </template>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.publish.title') }}</v-card-title>
                <v-card-subtitle>{{ t('ShopView.publish.hint') }}</v-card-subtitle>
                <template #append>
                    <div class="d-flex flex-wrap ga-2">
                        <v-btn
                            color="primary"
                            variant="flat"
                            size="small"
                            prepend-icon="mdi-cloud-upload-outline"
                            :loading="veroeffentlicht"
                            :disabled="sofort || laeuft"
                            @click="alleVeroeffentlichen"
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
                            @click="alleSofortVeroeffentlichen"
                        >
                            {{ t('ShopView.publish.allNow') }}
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
                                    · {{ te(`ShopView.channels.${auftrag.channel}`) ? t(`ShopView.channels.${auftrag.channel}`) : auftrag.channel }}
                                </span>
                            </td>
                            <td>{{ auftrag.partnumber }}</td>
                            <td class="text-caption">{{ auftrag.result || t('ShopView.publish.notExecuted') }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card-text>
            <v-card-text v-else class="text-caption text-medium-emphasis">
                {{ t('ShopView.publish.empty') }}
            </v-card-text>

            <!-- Was der letzte Lauf gemeldet hat. Ohne diese Zeilen stünde nur
                 die Zahl der Fehler da, nicht der Grund. -->
            <v-divider v-if="laufMeldungen.length || laufAbgebrochen" />
            <v-card-text v-if="laufMeldungen.length || laufAbgebrochen">
                <div class="d-flex align-center mb-2">
                    <div class="text-body-2">{{ t('ShopView.publish.messages') }}</div>
                    <v-spacer />
                    <v-btn
                        variant="text"
                        size="small"
                        icon="mdi-close"
                        :title="t('ShopView.publish.messagesClose')"
                        @click="meldungenAusblenden"
                    />
                </div>
                <!-- Der Lauf kam nicht zu Ende: Grund aus der Ausgabe des Prozesses -->
                <v-alert v-if="laufAbgebrochen" type="error" variant="tonal" density="compact" class="mb-2">
                    <div>{{ t('ShopView.publish.aborted') }}</div>
                    <pre v-if="laufAusgabe.length" class="text-caption mt-1 mb-0 laufausgabe">{{ laufAusgabe.join('\n') }}</pre>
                </v-alert>
                <div
                    v-for="(zeile, index) in laufMeldungen"
                    :key="index"
                    class="text-caption"
                    :class="istFehlerzeile(zeile) ? 'text-error font-weight-medium' : 'text-medium-emphasis'"
                >
                    {{ zeile }}
                </div>
            </v-card-text>
        </v-card>

        <!-- Rückfrage vor dem Löschen der Auswahl -->
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

const { t, te, locale } = useI18n()
const router = useRouter()
const shop = useShop()

const status = ref(null)
const auftraege = ref([])
const veroeffentlicht = ref(false)
const sofort = ref(false)
const auswahl = ref([])
const laeuft = ref(false)
const raeumtAuf = ref(false)
/** Meldungen des letzten Laufs, dazu die davon, die Fehler waren */
const laufMeldungen = ref([])
const laufFehler = ref([])
const laufAbgebrochen = ref(false)
const laufAusgabe = ref([])
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
const loeschenGefragt = ref(false)

/** Wahrheitswerte kommen je nach Treiber als true oder 't' */
const istOffen = (auftrag) => auftrag.open === true || auftrag.open === 't'

/**
 * Ist diese Zeile eine Fehlermeldung?
 *
 * Das Backend liefert die Fehlerzeilen ein zweites Mal einzeln, statt sie hier
 * am Wortlaut zu erraten.
 */
const istFehlerzeile = (zeile) => laufFehler.value.includes(String(zeile).replace(/^\d{2}:\d{2}:\d{2}\s+/, ''))
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

    // Der Läufer arbeitet jetzt im Hintergrund; die Antwort kommt sofort.
    // Lief schon einer, nimmt der die Aufträge mit — beobachtet wird so oder so.
    toasts.info(antwort?.started === false ? t('ShopView.publish.running') : t('ShopView.publish.started'))
    laufMeldungen.value = []
    laufFehler.value = []
    laufAbgebrochen.value = false
    laufAusgabe.value = []
    beobachten()
}

/** Übernimmt den Stand aus getShopPublishStatus in die Anzeige */
function standUebernehmen(stand) {
    laufMeldungen.value = stand.lines || []
    laufFehler.value = stand.error_lines || []
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

function meldungenAusblenden() {
    laufMeldungen.value = []
    laufFehler.value = []
    laufAbgebrochen.value = false
    laufAusgabe.value = []
}

/**
 * Löscht die ausgewählten Aufträge
 *
 * Gedacht für fehlgeschlagene: Fehler gelesen, Zeile weg. Offene lassen sich
 * ebenso löschen — sie sind danach nicht mehr vorgemerkt.
 */
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
async function alleSofortVeroeffentlichen() {
    sofort.value = true
    try {
        const angelegt = await shop.publishAll()
        if (shop.error.value) {
            return
        }

        let id = angelegt?.job_id || 0
        if (!id) {
            auftraege.value = await shop.fetchPublishJobs() || []
            id = auftraege.value.find((auftrag) => istOffen(auftrag) && auftrag.function === 'publish_all')?.id || 0
        }

        if (!id) {
            toasts.info(t('ShopView.publish.running'))
            await laden()
            return
        }

        // Ausgewählt wie von Hand — die Zeile ist während des Laufs markiert
        auswahl.value = [id]
        await ausfuehren([id])
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

/**
 * Beim Öffnen den Stand holen
 *
 * Die Meldungen des letzten Laufs stehen so auch nach dem Neuladen da, und ein
 * Lauf, der gerade arbeitet — auch einer aus dem Cron —, wird gleich verfolgt.
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

.laufausgabe {
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
