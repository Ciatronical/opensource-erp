<!-- src/features/shop/components/part-shop.card.vue -->
<!--
    Shop-Angaben eines Artikels (parts_ext) und seine Verkaufskanäle
    (parts_channel_shop) in der Artikelmaske — dev/shop-verkaufskanaele.md.

    Die Artikelmaske des Kerns lädt diese Karte nur bei aktiver Shop-Erweiterung.
    „Im Shop anbieten" heißt: in mindestens einem Kanal aktiv. Darunter werden
    die Kanäle gewählt — solange es nur den HugoShop gibt, entfällt die Auswahl
    — und je Kanal Aufschlag, Beschreibung und Langbeschreibung gepflegt.
    Ausschalten schaltet alle Kanäle ab; Angaben und Aufschläge bleiben
    erhalten (V5).

    Der Kanalpreis wird hier nur als Vorschau gerechnet, mit Verkaufspreis und
    Buchungsgruppe aus der Maske. Maßgeblich ist shop_channel_price() in der
    Datenbank; die Rechnung hier folgt ihr Schritt für Schritt.

    Bearbeiten (partsId gesetzt): lädt selbst und speichert Änderungen verzögert.
    Neuanlage (partsId leer): „Im Shop anbieten" ist vorbelegt; die Karte
    sammelt nur, die Maske ruft nach createPart saveFor(neueId) auf.
-->
<template>
    <v-card variant="outlined" elevation="1" class="mt-4">
        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
            <v-icon class="mr-2" size="small">mdi-storefront</v-icon>
            <span class="text-subtitle-1 font-weight-medium">{{ t('ShopView.partCard.title') }}</span>
            <v-spacer />
            <v-progress-circular v-if="speichert || laedt" indeterminate size="16" width="2" />
            <v-btn
                v-if="partsId && daten.listed && hugoshopAktiv && darfBearbeiten"
                variant="text"
                size="small"
                prepend-icon="mdi-cloud-upload-outline"
                :loading="veroeffentlicht"
                class="ml-2"
                @click="veroeffentlichen"
            >
                {{ t('ShopView.partCard.publish') }}
            </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text class="py-2 px-2 px-sm-3">
            <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-2">
                {{ fehler }}
            </v-alert>
            <v-alert v-else-if="!darfBearbeiten" type="info" variant="tonal" density="compact" class="mb-2">
                {{ t('ShopView.partCard.noPermission') }}
            </v-alert>

            <v-switch
                v-model="daten.listed"
                :label="t('ShopView.partCard.listed')"
                :disabled="!darfBearbeiten || laedt"
                color="primary"
                density="compact"
                hide-details
            />
            <div class="text-caption text-medium-emphasis mb-2">{{ t('ShopView.partCard.listedHint') }}</div>

            <template v-if="daten.listed && daten.channels.length">
                <!-- Kanalauswahl: entfällt, solange es nur einen Kanal gibt -->
                <div v-if="daten.channels.length > 1" class="mb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('ShopView.partCard.channels') }}</div>
                    <v-chip-group
                        v-model="ausgewaehlt"
                        multiple
                        mandatory
                        column
                        selected-class="text-primary"
                        :disabled="!darfBearbeiten"
                    >
                        <v-chip
                            v-for="kanal in daten.channels"
                            :key="kanal.channel_id"
                            :value="kanal.channel_id"
                            :disabled="gesperrt(kanal)"
                            :title="gesperrt(kanal) ? t('ShopView.partCard.serviceNotOnMarketplace') : ''"
                            filter
                            variant="outlined"
                            size="small"
                        >
                            {{ kanalName(kanal.type) }}
                        </v-chip>
                    </v-chip-group>
                </div>

                <!-- Preis und Texte je gewähltem Kanal -->
                <v-expansion-panels variant="accordion" multiple class="mb-3">
                    <v-expansion-panel
                        v-for="kanal in aktiveKanaele"
                        :key="kanal.channel_id"
                        elevation="0"
                        class="border"
                    >
                        <v-expansion-panel-title class="py-2">
                            <span class="font-weight-medium">{{ kanalName(kanal.type) }}</span>
                            <v-spacer />
                            <span class="text-body-2 mr-2">
                                {{ geld(kanalPreis(kanal).brutto) }} {{ t('ShopView.partCard.gross') }}
                            </span>
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-select
                                        v-model="kanal.markup_mode"
                                        :items="aufschlagArten(kanal)"
                                        :label="t('ShopView.partCard.markup')"
                                        :readonly="!darfBearbeiten"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                    />
                                </v-col>
                                <v-col v-if="mitWert(kanal.markup_mode)" cols="12" sm="6" class="py-1">
                                    <v-text-field
                                        v-model.number="kanal.markup_value"
                                        type="number"
                                        step="0.01"
                                        :label="t('ShopView.partCard.markupValue')"
                                        :suffix="kanal.markup_mode === 'percent' ? '%' : waehrung"
                                        :hint="kanal.markup_mode === 'amount' ? betragHinweis : ''"
                                        persistent-hint
                                        :readonly="!darfBearbeiten"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Vorschau des Kanalpreises -->
                                <v-col cols="12" class="py-1 text-body-2">
                                    {{ t('ShopView.partCard.basePrice') }}: {{ geld(grundpreis) }} {{ grundpreisArt }}
                                    ·
                                    {{ t('ShopView.partCard.channelPrice') }}:
                                    {{ geld(kanalPreis(kanal).netto) }} {{ t('ShopView.partCard.net') }} /
                                    {{ geld(kanalPreis(kanal).brutto) }} {{ t('ShopView.partCard.gross') }}
                                    <span v-if="vorgabe(kanal).round_99">· {{ t('ShopView.partCard.rounded99') }}</span>
                                    <div class="text-caption text-medium-emphasis">{{ t('ShopView.partCard.pricePreview') }}</div>
                                </v-col>

                                <!-- Texte: leer = Stammdaten -->
                                <v-col cols="12" class="py-1">
                                    <v-text-field
                                        v-model="kanal.title"
                                        :label="t('ShopView.partCard.channelTitle')"
                                        :hint="t('ShopView.partCard.channelTitleHint')"
                                        :placeholder="description"
                                        persistent-placeholder
                                        :readonly="!darfBearbeiten"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>
                                <v-col cols="12" class="py-1">
                                    <v-textarea
                                        v-model="kanal.description"
                                        :label="t('ShopView.partCard.channelDescription')"
                                        :hint="t('ShopView.partCard.channelDescriptionHint')"
                                        :placeholder="notes"
                                        persistent-placeholder
                                        :readonly="!darfBearbeiten"
                                        rows="3"
                                        auto-grow
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                    />
                                </v-col>

                                <!-- Kanaleigene Angaben (KANAL_FELDER), leer = Vorgabe aus den Einstellungen -->
                                <v-col
                                    v-for="feld in kanalFelder(kanal)"
                                    :key="feld.name"
                                    cols="12"
                                    sm="6"
                                    class="py-1"
                                >
                                    <v-select
                                        v-if="feld.items"
                                        v-model="kanal.settings[feld.name]"
                                        :items="feldAuswahl(feld)"
                                        :label="t(feld.label)"
                                        :readonly="!darfBearbeiten"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                    />
                                    <v-text-field
                                        v-else
                                        v-model="kanal.settings[feld.name]"
                                        :label="t(feld.label)"
                                        :hint="feld.hint ? t(feld.hint) : ''"
                                        :readonly="!darfBearbeiten"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Bilder eines Marktplatzes (V12); der HugoShop führt seine unten -->
                                <v-col v-if="kanal.type !== 'hugoshop'" cols="12" class="py-1">
                                    <div class="text-caption text-medium-emphasis mb-1">
                                        {{ t('ShopView.partCard.channelImages', { kanal: kanalName(kanal.type) }) }}
                                    </div>
                                    <div v-if="!partsId" class="text-caption">{{ t('ShopView.partCard.imagesAfterCreate') }}</div>
                                    <template v-else>
                                        <div class="d-flex flex-wrap ga-2 mb-2">
                                            <div
                                                v-for="(bild, i) in kanal.images"
                                                :key="bild.id"
                                                class="text-center"
                                                style="width: 96px;"
                                            >
                                                <v-img :src="bild.url" width="96" height="96" cover class="rounded border" />
                                                <div class="d-flex justify-center">
                                                    <v-btn
                                                        icon="mdi-chevron-left"
                                                        size="x-small"
                                                        variant="text"
                                                        :disabled="i === 0 || !darfBearbeiten"
                                                        :title="t('ShopView.partCard.moveLeft')"
                                                        @click="bildVerschieben(kanal, i, -1)"
                                                    />
                                                    <v-btn
                                                        icon="mdi-delete-outline"
                                                        size="x-small"
                                                        variant="text"
                                                        color="error"
                                                        :disabled="!darfBearbeiten"
                                                        :title="t('ShopView.partCard.removeImage')"
                                                        @click="bildLoeschen(kanal, bild)"
                                                    />
                                                    <v-btn
                                                        icon="mdi-chevron-right"
                                                        size="x-small"
                                                        variant="text"
                                                        :disabled="i === kanal.images.length - 1 || !darfBearbeiten"
                                                        :title="t('ShopView.partCard.moveRight')"
                                                        @click="bildVerschieben(kanal, i, 1)"
                                                    />
                                                </div>
                                                <div v-if="i === 0" class="text-caption">{{ t('ShopView.partCard.thumbnail') }}</div>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap ga-2">
                                            <v-btn
                                                size="small"
                                                variant="tonal"
                                                prepend-icon="mdi-image-plus"
                                                :loading="bildLaedt"
                                                :disabled="!darfBearbeiten"
                                                @click="bildWaehlen(kanal)"
                                            >
                                                {{ t('ShopView.partCard.addImage') }}
                                            </v-btn>
                                            <v-btn
                                                v-for="quelle in bildQuellen(kanal)"
                                                :key="quelle.type"
                                                size="small"
                                                variant="text"
                                                prepend-icon="mdi-image-move"
                                                :loading="bildLaedt"
                                                :disabled="!darfBearbeiten"
                                                @click="bilderUebernehmen(quelle.type, kanal.type)"
                                            >
                                                {{ t('ShopView.partCard.copyImagesFrom', { kanal: kanalName(quelle.type) }) }}
                                            </v-btn>
                                        </div>
                                    </template>
                                </v-col>

                                <!-- HugoShop: Bilder eines Marktplatzes übernehmen — nur, wenn
                                     OSERP die Webseite selbst beschreibt (V17) -->
                                <v-col
                                    v-if="kanal.type === 'hugoshop' && partsId && lokaleWebseite && bildQuellen(kanal).length"
                                    cols="12"
                                    class="py-1"
                                >
                                    <v-btn
                                        v-for="quelle in bildQuellen(kanal)"
                                        :key="quelle.type"
                                        size="small"
                                        variant="text"
                                        prepend-icon="mdi-image-move"
                                        :loading="bildLaedt"
                                        :disabled="!darfBearbeiten"
                                        @click="bilderUebernehmen(quelle.type, 'hugoshop')"
                                    >
                                        {{ t('ShopView.partCard.copyImagesFrom', { kanal: kanalName(quelle.type) }) }}
                                    </v-btn>
                                </v-col>

                                <!-- Stand des Abgleichs mit dem Marktplatz -->
                                <v-col v-if="kanal.type !== 'hugoshop' && partsId" cols="12" class="py-1">
                                    <v-alert
                                        :type="kanal.sync_status === 'error' ? 'error' : kanal.sync_status === 'active' ? 'success' : 'info'"
                                        variant="tonal"
                                        density="compact"
                                    >
                                        {{ t('ShopView.partCard.syncStatus', { kanal: kanalName(kanal.type) }) }}:
                                        {{ te(`ShopView.partCard.sync.${kanal.sync_status}`)
                                            ? t(`ShopView.partCard.sync.${kanal.sync_status}`)
                                            : t('ShopView.partCard.sync.none') }}
                                        <span v-if="kanal.external_id" class="text-caption">
                                            · {{ t('ShopView.partCard.listingId', { id: kanal.external_id }) }}
                                        </span>
                                        <div v-if="kanal.sync_error" class="text-caption">{{ kanal.sync_error }}</div>
                                    </v-alert>
                                </v-col>
                            </v-row>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </template>

            <v-row v-if="daten.listed" dense>
                <!-- Kategorie -->
                <v-col cols="12" sm="6" class="py-1">
                    <v-text-field
                        v-model="daten.category"
                        :label="t('ShopView.partCard.category')"
                        :readonly="!darfBearbeiten"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        autocomplete="off"
                    />
                </v-col>

                <!-- Produktseite: leer = Vorschlag der Maske -->
                <v-col cols="12" sm="6" class="py-1">
                    <v-text-field
                        v-model="daten.hyperlink"
                        :label="t('ShopView.partCard.hyperlink')"
                        :hint="t('ShopView.partCard.hyperlinkHint')"
                        :placeholder="suggestedLink"
                        persistent-placeholder
                        :readonly="!darfBearbeiten"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        autocomplete="off"
                    >
                        <template v-if="shopLink" #append-inner>
                            <v-btn
                                icon="mdi-open-in-new"
                                size="x-small"
                                variant="text"
                                :href="shopLink"
                                target="_blank"
                                rel="noopener"
                                :title="t('ShopView.partCard.openInShop')"
                            />
                        </template>
                    </v-text-field>
                </v-col>

                <!-- Navigationspfad -->
                <v-col cols="12" class="py-1">
                    <v-combobox
                        v-model="daten.breadcrumbs"
                        :label="t('ShopView.partCard.breadcrumbs')"
                        :hint="t('ShopView.partCard.breadcrumbsHint')"
                        :readonly="!darfBearbeiten"
                        multiple
                        chips
                        closable-chips
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                    />
                </v-col>

                <!-- Bilder: das erste ist das Vorschaubild in Suche, Warenkorb und Rechnung -->
                <v-col cols="12" class="py-1">
                    <v-combobox
                        v-model="daten.images"
                        :label="t('ShopView.partCard.images')"
                        :hint="t('ShopView.partCard.imagesHint')"
                        :readonly="!darfBearbeiten"
                        multiple
                        chips
                        closable-chips
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                    />
                    <div v-if="vorschau.length" class="d-flex flex-wrap ga-2 mt-2">
                        <div v-for="(bild, i) in vorschau" :key="bild.name" class="text-center" style="width: 96px;">
                            <v-img :src="bild.url" width="96" height="96" cover class="rounded border" />
                            <div v-if="i === 0" class="text-caption">{{ t('ShopView.partCard.thumbnail') }}</div>
                        </div>
                    </div>
                </v-col>

                <!-- Technische Daten, Eigenschaften, Downloads -->
                <v-col cols="12" class="py-1">
                    <ShopKeyValueEditor
                        v-model="daten.technical_data"
                        :title="t('ShopView.partCard.technicalData')"
                        :key-label="t('ShopView.partCard.label')"
                        :value-label="t('ShopView.partCard.value')"
                        :readonly="!darfBearbeiten"
                    />
                </v-col>
                <v-col cols="12" class="py-1">
                    <ShopKeyValueEditor
                        v-model="daten.properties"
                        :title="t('ShopView.partCard.properties')"
                        :key-label="t('ShopView.partCard.label')"
                        :value-label="t('ShopView.partCard.value')"
                        :readonly="!darfBearbeiten"
                    />
                </v-col>
                <v-col cols="12" class="py-1">
                    <ShopKeyValueEditor
                        v-model="daten.downloads"
                        :title="t('ShopView.partCard.downloads')"
                        :key-label="t('ShopView.partCard.displayName')"
                        :value-label="t('ShopView.partCard.file')"
                        :readonly="!darfBearbeiten"
                    />
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useShop } from '@/features/shop/composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'
import ShopKeyValueEditor from './shop-key-value.editor.vue'

const props = defineProps({
    /** Artikel; leer bei der Neuanlage */
    partsId: { type: [String, Number], default: null },
    /** Vorschlag für die Produktseite, solange keine eingetragen ist */
    suggestedLink: { type: String, default: '' },
    /** Verkaufspreis aus der Maske — Grundpreis der Preisvorschau */
    sellprice: { type: [Number, String], default: 0 },
    /** Buchungsgruppe aus der Maske — bestimmt den Steuersatz der Vorschau */
    buchungsgruppenId: { type: [Number, String], default: null },
    /** Beschreibung aus der Maske — Platzhalter für die Beschreibung im Kanal */
    description: { type: String, default: '' },
    /** Langbeschreibung aus der Maske — Platzhalter für den Kanal */
    notes: { type: String, default: '' },
    /** Artikeltyp aus der Maske — Dienstleistungen gehen nicht an Marktplätze (V19) */
    partType: { type: String, default: '' },
})

const { t, te, locale } = useI18n()
const oserp = oserpStore()
const shop = useShop()

const darfBearbeiten = computed(() =>
    oserp.checkPermission('shop_part_edit') || oserp.checkPermission('edit_shop_config'))

function leer() {
    return {
        listed: false,
        category: '',
        hyperlink: '',
        breadcrumbs: [],
        images: [],
        technical_data: [],
        properties: [],
        downloads: [],
        channels: [],
    }
}

const daten = ref(leer())
/** Vorgaben der Kanäle (Aufschlag, Rundung) je channel_id — nur zum Lesen */
const vorgaben = ref({})
/** Steuersatz der Standard-Steuerzone je Buchungsgruppe */
const steuersaetze = ref({})
const laedt = ref(false)
const speichert = ref(false)
const veroeffentlicht = ref(false)
const fehler = ref('')

let bereit = false      // erst nach dem Laden speichern
let timer = null
let zuletzt = null      // zuletzt gespeicherter Stand — gleiche Daten nicht erneut senden

// ── Umwandlung zwischen Datenbank und Formular ──

/** JSON-Spalten kommen als Text aus der Datenbank */
function lies(wert) {
    if (typeof wert !== 'string') return wert ?? null
    try { return JSON.parse(wert) } catch { return null }
}
const alsListe = wert => (Array.isArray(wert) ? wert.map(String) : [])
const zuPaaren = objekt => Object.entries(objekt && typeof objekt === 'object' ? objekt : {})
    .map(([key, value]) => ({ key, value: String(value ?? '') }))
/** Zeilen ohne Bezeichnung fallen weg */
const zuObjekt = paare => Object.fromEntries(paare
    .filter(p => String(p.key).trim() !== '')
    .map(p => [String(p.key).trim(), String(p.value ?? '')]))
const ohneLeere = liste => liste.map(s => String(s).trim()).filter(s => s !== '')

// ── Verkaufskanäle ──

const MIT_WERT = ['percent', 'amount']
const mitWert = art => MIT_WERT.includes(art)

/** Kanalzeile aus der Datenbank für das Formular; markup_mode 'default' = Vorgabe des Kanals */
function alsKanal(zeile) {
    return {
        channel_id: Number(zeile.channel_id),
        type: String(zeile.type),
        active: zeile.active === true || zeile.active === 't',
        markup_mode: zeile.markup_type || 'default',
        markup_value: zeile.markup_value == null ? null : Number(zeile.markup_value),
        title: zeile.title || '',
        description: zeile.description || '',
        // kanaleigene Angaben (KANAL_FELDER) und, nur zum Lesen, der Stand beim Kanal
        settings: lies(zeile.settings) && typeof lies(zeile.settings) === 'object' ? { ...lies(zeile.settings) } : {},
        sync_status: zeile.sync_status || '',
        sync_error: zeile.sync_error || '',
        external_id: zeile.external_id || '',
        images: Array.isArray(lies(zeile.images)) ? lies(zeile.images) : [],
    }
}

// ── Kanaleigene Angaben ──
//
// Je Kanaltyp eine Feldliste, wie in shopDefaultsConfig.js: ein neuer Kanal
// braucht nur einen Eintrag hier, keinen Vorlagencode. Leer heißt: Vorgabe
// aus den Einstellungen des Kanals.
const EBAY_ZUSTAENDE = ['NEW', 'USED_EXCELLENT', 'USED_GOOD', 'USED_ACCEPTABLE', 'FOR_PARTS_OR_NOT_WORKING']
const KANAL_FELDER = {
    ebay: [
        { name: 'category_id', label: 'ShopView.partCard.ebayCategory', hint: 'ShopView.partCard.channelDefaultHint' },
        { name: 'condition', label: 'ShopView.partCard.ebayCondition', items: EBAY_ZUSTAENDE, prefix: 'ShopView.ebayCondition.' },
    ],
}
const kanalFelder = kanal => KANAL_FELDER[kanal.type] || []
const feldAuswahl = feld => [
    { value: null, title: t('ShopView.partCard.channelDefault') },
    ...feld.items.map(wert => ({ value: wert, title: t(feld.prefix + wert) })),
]

/** Dienstleistungen gehen nicht an Marktplätze (V19) — außer, sie sind dort schon gewählt */
const gesperrt = kanal => kanal.type !== 'hugoshop' && props.partType === 'service' && !kanal.active

// ── Bilder der Marktplätze ──

const bildLaedt = ref(false)
const lokaleWebseite = computed(() => oserp.getClientDefaultValue('shop_publish_mode', 'local') !== 'hugocms')

/** Kanäle, aus denen sich Bilder übernehmen lassen: die anderen gewählten */
const bildQuellen = kanal => aktiveKanaele.value.filter(k => k.channel_id !== kanal.channel_id
    && (k.type === 'hugoshop' ? daten.value.images.length > 0 : k.images.length > 0))

/** Neue Bilderliste eines Kanals aus der Antwort übernehmen */
function bilderSetzen(kanal, antwort) {
    if (antwort?.images) kanal.images = antwort.images
}

function bildWaehlen(kanal) {
    const eingabe = document.createElement('input')
    eingabe.type = 'file'
    eingabe.accept = 'image/jpeg,image/png,image/gif,image/webp'
    eingabe.multiple = true
    eingabe.onchange = () => bilderHochladen(kanal, Array.from(eingabe.files || []))
    eingabe.click()
}

async function bilderHochladen(kanal, dateien) {
    bildLaedt.value = true
    for (const datei of dateien) {
        const inhalt = await new Promise((fertig, fehlschlag) => {
            const leser = new FileReader()
            leser.onload = () => fertig(leser.result)
            leser.onerror = fehlschlag
            leser.readAsDataURL(datei)
        }).catch(() => null)
        if (!inhalt) continue
        const antwort = await shop.uploadChannelImage(Number(props.partsId), kanal.type, datei.name, inhalt)
        if (shop.error.value) {
            toasts.error(shop.error.value || t('ShopView.partCard.imageError'))
            continue
        }
        bilderSetzen(kanal, antwort)
    }
    bildLaedt.value = false
}

async function bildLoeschen(kanal, bild) {
    bildLaedt.value = true
    bilderSetzen(kanal, await shop.deleteChannelImage(bild.id))
    bildLaedt.value = false
    if (shop.error.value) toasts.error(shop.error.value)
}

async function bildVerschieben(kanal, index, richtung) {
    const ids = kanal.images.map(b => b.id)
    const ziel = index + richtung
    if (ziel < 0 || ziel >= ids.length) return
    ;[ids[index], ids[ziel]] = [ids[ziel], ids[index]]
    bildLaedt.value = true
    bilderSetzen(kanal, await shop.sortChannelImages(Number(props.partsId), kanal.type, ids))
    bildLaedt.value = false
    if (shop.error.value) toasts.error(shop.error.value)
}

/**
 * Übernimmt Bilder aus einem anderen Kanal und lädt danach die Bilderlisten
 * neu — beim HugoShop ändert sich parts_ext.hugoshop_images
 */
async function bilderUebernehmen(von, nach) {
    bildLaedt.value = true
    const ergebnis = await shop.copyChannelImages(Number(props.partsId), von, nach)
    if (shop.error.value) {
        bildLaedt.value = false
        toasts.error(shop.error.value)
        return
    }
    const antwort = await shop.fetchPartShopData(Number(props.partsId))
    bildLaedt.value = false
    if (!antwort) return
    for (const zeile of antwort.channels || []) {
        const kanal = daten.value.channels.find(k => k.channel_id === Number(zeile.channel_id))
        if (kanal) kanal.images = Array.isArray(lies(zeile.images)) ? lies(zeile.images) : []
    }
    if (nach === 'hugoshop') {
        daten.value.images = alsListe(lies(antwort.part?.hugoshop_images))
    }
    toasts.success(t('ShopView.partCard.imagesCopied', { anzahl: ergebnis?.copied ?? 0 }))
}

function alsVorgabe(zeile) {
    return {
        markup_type: zeile.channel_markup_type || 'none',
        markup_value: Number(zeile.channel_markup_value) || 0,
        round_99: zeile.round_99 === true || zeile.round_99 === 't',
    }
}

const vorgabe = kanal => vorgaben.value[kanal.channel_id] || { markup_type: 'none', markup_value: 0, round_99: false }

const kanalName = typ => (te(`ShopView.channels.${typ}`) ? t(`ShopView.channels.${typ}`) : typ)

const aktiveKanaele = computed(() => daten.value.channels.filter(k => k.active))
const hugoshopAktiv = computed(() => aktiveKanaele.value.some(k => k.type === 'hugoshop'))

/** Auswahl der Kanäle als Liste von channel_id — für die Chip-Gruppe */
const ausgewaehlt = computed({
    get: () => aktiveKanaele.value.map(k => k.channel_id),
    set: ids => daten.value.channels.forEach(k => { k.active = ids.includes(k.channel_id) }),
})

/** Wer „Im Shop anbieten" einschaltet, bietet mindestens im ersten Kanal an (HugoShop) */
function mindestensEinKanal() {
    const kanaele = daten.value.channels
    if (!kanaele.length || kanaele.some(k => k.active)) return
    const erster = kanaele.find(k => k.type === 'hugoshop') || kanaele[0]
    erster.active = true
}

watch(() => daten.value.listed, an => { if (an) mindestensEinKanal() })

// ── Preisvorschau ──

const WAHR = ['t', 'true', '1', 'y', 'yes']
const bruttoPreise = computed(() =>
    WAHR.includes(String(oserp.getClientDefaultValue('shop_tax_included', '0')).trim().toLowerCase()))
const waehrung = computed(() => oserp.getClientDefaultValue('shop_standard_currency', 'EUR') || 'EUR')
const grundpreis = computed(() => Number(props.sellprice) || 0)
const grundpreisArt = computed(() => t(bruttoPreise.value ? 'ShopView.partCard.gross' : 'ShopView.partCard.net'))
const betragHinweis = computed(() =>
    t(bruttoPreise.value ? 'ShopView.partCard.markupAmountGross' : 'ShopView.partCard.markupAmountNet'))
const steuersatz = computed(() => Number(steuersaetze.value[props.buchungsgruppenId] ?? 0) || 0)

const rund2 = x => Math.round((x + Number.EPSILON) * 100) / 100

function geld(betrag) {
    try {
        return new Intl.NumberFormat(locale.value, { style: 'currency', currency: waehrung.value }).format(betrag)
    } catch {
        return rund2(betrag).toFixed(2)
    }
}

/**
 * Kanalpreis wie shop_channel_price(): Aufschlag des Artikels, sonst Vorgabe
 * des Kanals, auf Cent gerundet; bei round_99 den Bruttopreis auf die nächste
 * ,99 aufrunden. Gerechnet in Cent, damit 13,99 + 0,01 nicht an der
 * Gleitkommadarstellung auf 14,00000000000002 scheitert.
 */
function kanalPreis(kanal) {
    const v = vorgabe(kanal)
    const eigen = kanal.markup_mode !== 'default'
    const art = eigen ? kanal.markup_mode : v.markup_type
    const wert = eigen ? Number(kanal.markup_value) || 0 : v.markup_value
    const brutto = bruttoPreise.value
    const satz = steuersatz.value

    let preis = grundpreis.value
    if (art === 'percent') preis = rund2(preis * (1 + wert / 100))
    if (art === 'amount') preis = rund2(preis + wert)

    if (v.round_99 && preis > 0) {
        const cent = Math.round((brutto ? preis : preis * (1 + satz)) * 100)
        const ziel = Math.ceil((cent + 1) / 100) * 100 - 1
        preis = brutto ? ziel / 100 : ziel / 100 / (1 + satz)
    }

    return {
        netto: rund2(brutto ? preis / (1 + satz) : preis),
        brutto: rund2(brutto ? preis : preis * (1 + satz)),
    }
}

/** Auswahl des Aufschlags; die Vorgabe des Kanals steht im Eintrag */
function aufschlagArten(kanal) {
    const v = vorgabe(kanal)
    const text = v.markup_type === 'percent'
        ? `${v.markup_value} %`
        : v.markup_type === 'amount' ? geld(v.markup_value) : t('ShopView.partCard.defaultNone')
    return [
        { value: 'default', title: t('ShopView.partCard.markupDefault', { vorgabe: text }) },
        { value: 'none', title: t('ShopView.partCard.markupNone') },
        { value: 'percent', title: t('ShopView.partCard.markupPercent') },
        { value: 'amount', title: t('ShopView.partCard.markupAmount') },
    ]
}

function nutzdaten(partsId) {
    const d = daten.value
    return {
        parts_id: Number(partsId),
        category: d.category.trim(),
        hyperlink: d.hyperlink.trim() || props.suggestedLink,
        breadcrumbs: ohneLeere(d.breadcrumbs),
        images: ohneLeere(d.images),
        technical_data: zuObjekt(d.technical_data),
        properties: zuObjekt(d.properties),
        downloads: zuObjekt(d.downloads),
        channels: d.channels.map(k => ({
            channel_id: k.channel_id,
            active: k.active,
            markup_type: k.markup_mode === 'default' ? null : k.markup_mode,
            markup_value: mitWert(k.markup_mode) ? Number(k.markup_value) || 0 : null,
            title: k.title.trim(),
            description: k.description.trim(),
            settings: Object.fromEntries(Object.entries(k.settings || {})
                .filter(([, wert]) => wert !== undefined && wert !== null && String(wert).trim() !== '')
                .map(([name, wert]) => [name, String(wert).trim()])),
        })),
    }
}

function stand(partsId) {
    return daten.value.listed ? JSON.stringify(nutzdaten(partsId)) : 'aus'
}

/** Fehlende Rechte bekommen den Hinweis der Karte, alles andere die Meldung aus useShop() */
function fehlerText() {
    return shop.errorCode.value === 'NO_PERMISSION'
        ? t('ShopView.partCard.noPermission')
        : shop.error.value || t('ShopView.partCard.saveError')
}

// ── Links auf die Shop-Webseite (Muster mit %s aus den Shop-Einstellungen) ──

function formatLink(muster, wert) {
    return muster && muster.includes('%s') ? muster.replace('%s', wert) : ''
}

const shopLink = computed(() => {
    const ziel = daten.value.hyperlink.trim() || props.suggestedLink
    if (!props.partsId || !ziel) return ''
    return formatLink(oserp.getClientDefaultValue('shop_products_link', ''), ziel.toLowerCase())
})

const vorschau = computed(() => {
    const muster = oserp.getClientDefaultValue('shop_thumbnails_link', '')
    return ohneLeere(daten.value.images)
        .map(name => ({ name, url: formatLink(muster, name) }))
        .filter(bild => bild.url)
})

// ── Laden und Speichern ──

async function laden() {
    bereit = false
    fehler.value = ''

    // Auch bei der Neuanlage: die Kanäle und Steuersätze kommen mit
    laedt.value = true
    const antwort = await shop.fetchPartShopData(props.partsId ? Number(props.partsId) : 0)
    laedt.value = false

    if (shop.error.value) {
        fehler.value = fehlerText()
        return
    }

    const kanaele = antwort?.channels || []
    vorgaben.value = Object.fromEntries(kanaele.map(k => [Number(k.channel_id), alsVorgabe(k)]))
    steuersaetze.value = antwort?.tax_rates || {}

    if (!props.partsId) {
        // Neuanlage bei aktivem Shop: der Artikel ist in der Regel für den Shop
        // gedacht. Vorhandene Artikel zeigen dagegen den gespeicherten Stand.
        daten.value = { ...leer(), listed: true, channels: kanaele.map(alsKanal) }
        mindestensEinKanal()
        return
    }

    const zeile = antwort?.part
    daten.value = {
        listed: zeile?.listed === true || zeile?.listed === 't',
        category: zeile?.hugoshop_category || '',
        hyperlink: zeile?.hugoshop_hyperlink || '',
        breadcrumbs: alsListe(lies(zeile?.hugoshop_breadcrumbs)),
        images: alsListe(lies(zeile?.hugoshop_images)),
        technical_data: zuPaaren(lies(zeile?.hugoshop_technical_data)),
        properties: zuPaaren(lies(zeile?.hugoshop_properties)),
        downloads: zuPaaren(lies(zeile?.hugoshop_downloads)),
        channels: kanaele.map(alsKanal),
    }
    zuletzt = stand(props.partsId)

    // Der Watcher auf daten läuft erst vor dem nächsten Rendern — sonst hielte
    // er das Laden für eine Eingabe.
    await nextTick()
    bereit = true
}

/** Speichert für einen bestimmten Artikel — die id wird beim Planen festgehalten */
async function speichern(partsId) {
    if (!partsId || !darfBearbeiten.value) return

    const neuerStand = stand(partsId)
    if (neuerStand === zuletzt) return
    const liste = daten.value.listed
    const nutz = liste ? nutzdaten(partsId) : null

    speichert.value = true
    fehler.value = ''
    if (liste) {
        await shop.savePartShopData(nutz)
    } else {
        await shop.deletePartShopData(Number(partsId))
    }
    speichert.value = false

    if (shop.error.value) {
        fehler.value = fehlerText()
    } else {
        zuletzt = neuerStand
    }
}

watch(daten, () => {
    if (!bereit || !props.partsId) return
    const partsId = props.partsId
    clearTimeout(timer)
    timer = setTimeout(() => { timer = null; speichern(partsId) }, 800)
}, { deep: true })

// Wechsel des Artikels (auch Neuanlage → angelegter Artikel): Ausstehendes
// gehört noch zum bisherigen
watch(() => props.partsId, (neu, alt) => {
    if (timer && alt) {
        clearTimeout(timer)
        timer = null
        speichern(alt)
    }
    laden()
})

onMounted(laden)

onBeforeUnmount(() => {
    if (timer && props.partsId) {
        clearTimeout(timer)
        timer = null
        speichern(props.partsId)
    }
})

/**
 * Nimmt den Artikel in die Veröffentlichung auf
 *
 * Legt nur den Auftrag an. Die Seite entsteht beim nächsten Lauf von
 * tools/shop-publish.php — die Anwendung schreibt selbst keine Dateien.
 */
async function veroeffentlichen() {
    // Ausstehende Änderungen zuerst, sonst entstünde die Seite aus altem Stand
    if (timer) {
        clearTimeout(timer)
        timer = null
        await speichern(props.partsId)
    }

    veroeffentlicht.value = true
    const ergebnis = await shop.publishPart(Number(props.partsId))
    veroeffentlicht.value = false

    if (shop.error.value) {
        toasts.error(fehlerText())
        return
    }
    toasts.success(ergebnis?.queued === false
        ? t('ShopView.partCard.publishAlready')
        : t('ShopView.partCard.publishQueued'))
}

/**
 * Neuanlage: speichert die gesammelten Angaben am eben angelegten Artikel
 *
 * @returns {Promise<boolean>} false, wenn das Speichern scheiterte
 */
async function saveFor(partsId) {
    if (!daten.value.listed || !darfBearbeiten.value) return true
    await shop.savePartShopData(nutzdaten(partsId))
    if (shop.error.value) {
        toasts.error(fehlerText())
        return false
    }
    return true
}

defineExpose({ saveFor })
</script>
