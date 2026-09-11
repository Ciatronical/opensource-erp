<!-- src/features/shop/components/part-shop.card.vue -->
<!--
    Shop-Angaben eines Artikels (parts_ext) in der Artikelmaske.

    Die Artikelmaske des Kerns lädt diese Karte nur bei aktiver Shop-Erweiterung.
    Ob ein Artikel im Shop steht, entscheidet die parts_ext-Zeile: die
    Shop-Suche verknüpft per JOIN. Deshalb der Schalter „Im Shop anbieten" —
    ohne ihn legte jede Bearbeitung eine Zeile an und brächte den Artikel in
    den Shop.

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
                v-if="partsId && daten.listed && darfBearbeiten"
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
})

const { t } = useI18n()
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
    }
}

const daten = ref(leer())
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
    }
}

function stand(partsId) {
    return daten.value.listed ? JSON.stringify(nutzdaten(partsId)) : 'aus'
}

function fehlerText(code) {
    return code === 'NO_PERMISSION'
        ? t('ShopView.partCard.noPermission')
        : t('ShopView.partCard.saveError')
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
    if (!props.partsId) {
        // Neuanlage bei aktivem Shop: der Artikel ist in der Regel für den Shop
        // gedacht. Vorhandene Artikel zeigen dagegen den gespeicherten Stand.
        daten.value = { ...leer(), listed: true }
        return
    }

    laedt.value = true
    const zeile = await shop.fetchPartShopData(Number(props.partsId))
    laedt.value = false

    if (shop.error.value) {
        fehler.value = fehlerText(shop.error.value)
        return
    }

    daten.value = {
        listed: zeile?.listed === true || zeile?.listed === 't',
        category: zeile?.hugoshop_category || '',
        hyperlink: zeile?.hugoshop_hyperlink || '',
        breadcrumbs: alsListe(lies(zeile?.hugoshop_breadcrumbs)),
        images: alsListe(lies(zeile?.hugoshop_images)),
        technical_data: zuPaaren(lies(zeile?.hugoshop_technical_data)),
        properties: zuPaaren(lies(zeile?.hugoshop_properties)),
        downloads: zuPaaren(lies(zeile?.hugoshop_downloads)),
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
        fehler.value = fehlerText(shop.error.value)
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
        toasts.error(fehlerText(shop.error.value))
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
        toasts.error(fehlerText(shop.error.value))
        return false
    }
    return true
}

defineExpose({ saveFor })
</script>
