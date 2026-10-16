<!-- src/features/shop/components/shop-part-media.vue -->
<!--
    Medien eines Artikels (dev/shop-medienbibliothek.md): die Produktbilder und
    Downloads, die der Artikel nennt — als Kacheln oder Liste mit Kontextmenü,
    Hochladen (auch durch Ablegen) und Auswahl aus der Bibliothek. Die Dateien
    liegen in der zentralen Medienbibliothek; hier erscheinen nur die des
    Artikels. Die Namen hält die Artikelkarte (v-model: images als Liste,
    downloads als Paare aus Anzeigename und Datei); gespeichert wird mit der
    Karte.

        <ShopPartMedia v-model:images="daten.images" v-model:downloads="daten.downloads"
                       :parts-id="partsId" :readonly="!darfBearbeiten" />

    Umbenennen, Umwandeln und Löschen aus der Bibliothek wirken auf die
    Bibliothek selbst (edit_shop_config); die Verweise aller Artikel folgen,
    die eigene Liste wird sofort nachgezogen. Das erste Bild ist das
    Vorschaubild in Suche, Warenkorb und Rechnung.
-->
<template>
    <div class="partmedien">
        <div class="text-subtitle-2 mb-2">{{ t('ShopView.media.title') }}</div>

        <div class="partmedien__layout">
            <!-- Bereiche als senkrechte Schiene, wie die Werkzeugleiste des
                 HugoCMS-Dateimanagers: Symbol und Name, auf Wunsch nur Symbole -->
            <nav class="partmedien__rail" :class="{ 'partmedien__rail--schmal': railSchmal }">
                <button
                    type="button"
                    class="partmedien__knopf partmedien__umschalter d-none d-md-flex"
                    :title="railSchmal ? t('ShopView.media.railExpand') : t('ShopView.media.railCollapse')"
                    @click="railSchmal = !railSchmal"
                >
                    <v-icon :icon="railSchmal ? 'mdi-chevron-right' : 'mdi-chevron-left'" size="20" />
                </button>
                <div class="partmedien__abschnitt">{{ t('ShopView.media.areas') }}</div>
                <v-tooltip v-for="b in BEREICHE" :key="b.wert" :text="t(b.label)" location="right" :disabled="!railNurSymbole">
                    <template #activator="{ props: aktivator }">
                        <button
                            v-bind="aktivator"
                            type="button"
                            class="partmedien__knopf"
                            :class="{ 'partmedien__knopf--aktiv': bereich === b.wert }"
                            @click="bereich = b.wert"
                        >
                            <v-icon :icon="b.icon" size="20" />
                            <span class="partmedien__text">{{ t(b.label) }}</span>
                            <span class="partmedien__zahl">{{ b.wert === 'images' ? bildNamen.length : downloadNamen.length }}</span>
                        </button>
                    </template>
                </v-tooltip>
            </nav>

            <div class="partmedien__inhalt">
                <!-- Leiste: Suche, Ansicht, Hochladen, aus der Bibliothek -->
                <div class="partmedien__leiste d-flex flex-wrap align-center ga-2 mb-2">
                    <v-text-field
                        v-model="filter"
                        :label="t('ShopView.media.search')"
                        prepend-inner-icon="mdi-magnify"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        style="max-width: 28ch; min-width: 16ch"
                    />
                    <v-spacer />
                    <span class="text-caption text-medium-emphasis">{{ t('ShopView.media.count', { count: gefiltert.length }) }}</span>
                    <v-btn-toggle v-model="ansicht" mandatory density="compact" variant="text">
                        <v-btn value="grid" icon="mdi-view-grid-outline" size="small" :title="t('ShopView.media.viewGrid')" />
                        <v-btn value="list" icon="mdi-view-list-outline" size="small" :title="t('ShopView.media.viewList')" />
                    </v-btn-toggle>
                    <v-btn v-if="!readonly" size="small" variant="tonal" prepend-icon="mdi-upload" :loading="hochladung.laeuft" @click="hochladenWaehlen">
                        {{ t('ShopView.media.upload') }}
                    </v-btn>
                </div>

                <!-- Auswahlleiste -->
                <div v-if="auswahl.length && !readonly" class="d-flex align-center ga-2 mb-2 partmedien__auswahl">
                    <span class="text-caption">{{ t('ShopView.media.pickSelected', { count: auswahl.length }) }}</span>
                    <v-btn size="x-small" variant="tonal" color="error" prepend-icon="mdi-delete-outline" @click="loeschenFragen(gewaehlteDateien)">
                        {{ t('ShopView.media.delete') }}
                    </v-btn>
                    <v-btn size="x-small" variant="text" @click="auswahl = []">{{ t('ShopView.media.clearSelection') }}</v-btn>
                </div>

                <!-- Ablage: Kacheln oder Liste, Ziehen und Ablegen zum Hochladen -->
                <div
                    class="partmedien__ablage"
                    :class="{ 'partmedien__ablage--zieht': zieht }"
                    @dragenter.prevent="ziehenBeginn"
                    @dragover.prevent="ziehenBeginn"
                    @dragleave.prevent="ziehenEnde"
                    @drop.prevent="ablegen"
                >
                    <div v-if="zieht && !readonly" class="partmedien__hinweis">
                        <v-icon size="40">mdi-tray-arrow-down</v-icon>
                        <div>{{ t('ShopView.media.dropHint') }}</div>
                    </div>

                    <v-progress-linear v-if="laedt" indeterminate class="mb-2" />

                    <div v-if="!laedt && !gefiltert.length" class="text-medium-emphasis py-6 text-center">
                        {{ namen.length ? t('ShopView.media.empty') : t('ShopView.media.emptyArticle') }}
                    </div>

                    <!-- Kacheln, in der Reihenfolge des Artikels -->
                    <div v-else-if="ansicht === 'grid'" class="partmedien__raster">
                        <v-card
                            v-for="datei in gefiltert"
                            :key="datei.path"
                            variant="outlined"
                            class="partmedien__kachel"
                            :class="{ 'partmedien__kachel--gewaehlt': istGewaehlt(datei), 'partmedien__kachel--fehlt': datei.missing }"
                            @click="kachelKlick($event, datei)"
                            @dblclick="ansehen(datei)"
                            @contextmenu.prevent="menueOeffnen($event, datei)"
                        >
                            <v-img v-if="istBild(datei) && !datei.missing" :src="kachelBild(datei)" height="110" cover />
                            <div v-else class="partmedien__symbol">
                                <v-icon size="44" :color="datei.missing ? 'warning' : undefined">{{ datei.missing ? 'mdi-file-alert-outline' : symbol(datei) }}</v-icon>
                            </div>
                            <v-checkbox-btn
                                v-if="!readonly"
                                class="partmedien__wahl"
                                :model-value="istGewaehlt(datei)"
                                density="compact"
                                @click.stop="umschalten(datei)"
                            />
                            <v-btn icon="mdi-dots-vertical" size="x-small" variant="tonal" class="partmedien__menue" @click.stop="menueOeffnen($event, datei)" />
                            <v-chip v-if="bereich === 'images' && datei.index === 0" size="x-small" color="primary" variant="flat" class="partmedien__marke">
                                {{ t('ShopView.partCard.thumbnail') }}
                            </v-chip>
                            <v-card-text class="pa-2 text-caption">
                                <div class="text-truncate" :title="datei.basename">{{ datei.basename }}</div>
                                <div v-if="bereich === 'downloads' && datei.anzeigename && datei.anzeigename !== datei.basename" class="text-truncate text-medium-emphasis" :title="datei.anzeigename">
                                    {{ datei.anzeigename }}
                                </div>
                                <div :class="datei.missing ? 'text-warning' : 'text-medium-emphasis'">
                                    {{ datei.missing ? t('ShopView.media.missingFile') : groesse(datei.file_size) }}
                                </div>
                            </v-card-text>
                        </v-card>
                    </div>

                    <!-- Liste -->
                    <v-data-table
                        v-else
                        v-model="auswahl"
                        :headers="spalten"
                        :items="gefiltert"
                        item-value="path"
                        :show-select="!readonly"
                        density="compact"
                        :items-per-page="-1"
                        hide-default-footer
                        class="partmedien__liste"
                        @dblclick:row="(_, { item }) => ansehen(item)"
                        @contextmenu:row="(e, { item }) => menueOeffnen(e, item)"
                    >
                        <template #item.basename="{ item }">
                            <div class="d-flex align-center ga-2">
                                <v-avatar v-if="istBild(item) && !item.missing" size="28" rounded="sm">
                                    <v-img :src="kachelBild(item)" cover />
                                </v-avatar>
                                <v-icon v-else size="small" :color="item.missing ? 'warning' : undefined">{{ item.missing ? 'mdi-file-alert-outline' : symbol(item) }}</v-icon>
                                <span class="text-truncate" :title="item.basename">{{ item.basename }}</span>
                                <v-chip v-if="bereich === 'images' && item.index === 0" size="x-small" color="primary" variant="tonal">{{ t('ShopView.partCard.thumbnail') }}</v-chip>
                                <span v-if="item.missing" class="text-caption text-warning">{{ t('ShopView.media.missingFile') }}</span>
                            </div>
                        </template>
                        <template #item.anzeigename="{ item }">{{ item.anzeigename }}</template>
                        <template #item.file_size="{ item }">{{ item.missing ? '' : groesse(item.file_size) }}</template>
                        <template #item.last_modified="{ item }">{{ item.missing ? '' : zeit(item.last_modified) }}</template>
                        <template #item.aktionen="{ item }">
                            <v-btn icon="mdi-dots-vertical" size="x-small" variant="text" @click.stop="menueOeffnen($event, item)" />
                        </template>
                    </v-data-table>
                </div>
            </div>
        </div>

        <!-- Kontextmenü einer Datei -->
        <v-menu v-model="menue.offen" :target="menue.ziel" location="bottom start" :close-on-content-click="true">
            <v-list density="compact" min-width="240">
                <template v-if="menue.datei && !menue.datei.missing">
                    <v-list-item prepend-icon="mdi-eye-outline" :title="t('ShopView.media.preview')" @click="ansehen(menue.datei)" />
                    <v-list-item prepend-icon="mdi-open-in-new" :title="t('ShopView.media.openInTab')" @click="imTabOeffnen(menue.datei)" />
                    <v-list-item prepend-icon="mdi-download-outline" :title="t('ShopView.media.download')" @click="herunterladen(menue.datei)" />
                    <v-list-item prepend-icon="mdi-package-variant-closed" :title="t('ShopView.media.usage')" @click="verwendungZeigen(menue.datei)" />
                </template>
                <template v-if="!readonly">
                    <v-divider />
                    <v-list-item
                        v-if="bereich === 'images' && menue.datei?.index > 0"
                        prepend-icon="mdi-image-frame"
                        :title="t('ShopView.media.setThumbnail')"
                        @click="alsVorschaubild(menue.datei)"
                    />
                    <v-list-item
                        v-if="bereich === 'downloads' && !menue.datei?.missing"
                        prepend-icon="mdi-form-textbox"
                        :title="t('ShopView.media.displayName')"
                        @click="anzeigenameOeffnen(menue.datei)"
                    />
                    <template v-if="darfVerwalten && menue.datei && !menue.datei.missing">
                        <v-list-item
                            v-if="bereich === 'images'"
                            prepend-icon="mdi-swap-horizontal"
                            :title="t('ShopView.media.convert')"
                            @click="umwandlungOeffnen(menue.datei)"
                        />
                        <v-list-item prepend-icon="mdi-rename-outline" :title="t('ShopView.media.rename')" @click="umbenennenOeffnen(menue.datei)" />
                    </template>
                    <!-- Fehlt die Datei in der Bibliothek, geht nur der Verweis; sonst
                         Datei und Verweis zusammen -->
                    <v-list-item
                        v-if="menue.datei?.missing"
                        prepend-icon="mdi-link-off"
                        :title="t('ShopView.media.removeFromArticle')"
                        @click="vomArtikelEntfernen([menue.datei])"
                    />
                    <v-list-item v-else prepend-icon="mdi-delete-outline" :title="t('ShopView.media.delete')" base-color="error" @click="loeschenFragen([menue.datei])" />
                </template>
            </v-list>
        </v-menu>

        <!-- Hochladen: Name und Format je Datei, dann Fortschritt — jeden Namen
             gibt es in der Bibliothek nur einmal; Bilder lassen sich dabei umwandeln -->
        <v-dialog v-model="hochladung.offen" max-width="720" :persistent="hochladung.laeuft">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.media.uploadTitle') }}</v-card-title>
                <v-card-text>
                    <div class="text-caption text-medium-emphasis mb-3">{{ t('ShopView.media.uploadHint') }}</div>
                    <div v-for="zeile in hochladung.zeilen" :key="zeile.id" class="partmedien__uploadzeile">
                        <div class="d-flex align-center ga-2">
                            <v-icon size="small" :color="zeile.status === 'fehler' ? 'error' : zeile.status === 'fertig' ? 'success' : undefined">
                                {{ zeile.status === 'fehler' ? 'mdi-alert-circle-outline' : zeile.status === 'fertig' ? 'mdi-check-circle-outline' : symbolFuerEndung(zeile.endung) }}
                            </v-icon>
                            <v-text-field
                                v-model="zeile.basis"
                                :label="t('ShopView.media.uploadNameLabel')"
                                :suffix="'.' + zielEndung(zeile)"
                                :disabled="zeile.status === 'fertig' || hochladung.laeuft"
                                variant="outlined"
                                density="compact"
                                hide-details
                                class="flex-grow-1"
                            />
                            <v-select
                                v-if="bereich === 'images'"
                                v-model="zeile.format"
                                :items="formatWahl"
                                :label="t('ShopView.media.uploadFormatLabel')"
                                :disabled="zeile.status === 'fertig' || hochladung.laeuft"
                                variant="outlined"
                                density="compact"
                                hide-details
                                style="max-width: 16ch"
                            />
                            <span class="text-caption text-medium-emphasis text-no-wrap">{{ groesse(zeile.datei.size) }}</span>
                        </div>
                        <v-progress-linear
                            v-if="zeile.status === 'laeuft' || zeile.status === 'fertig'"
                            :model-value="zeile.percent"
                            :color="zeile.status === 'fertig' ? 'success' : 'primary'"
                            height="4"
                            rounded
                            class="mt-1"
                        />
                        <div v-if="zeile.fehler" class="text-caption text-error mt-1">{{ zeile.fehler }}</div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="hochladung.laeuft" @click="hochladung.offen = false">{{ t('ShopView.common.close') }}</v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        prepend-icon="mdi-upload"
                        :loading="hochladung.laeuft"
                        :disabled="!hochladung.zeilen.some(z => z.status !== 'fertig' && z.basis.trim())"
                        @click="hochladen"
                    >
                        {{ t('ShopView.media.upload') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Vorschau -->
        <v-dialog v-model="vorschau.offen" max-width="1100">
            <v-card>
                <v-card-title class="d-flex align-center ga-2 text-subtitle-1">
                    <v-icon size="small">{{ vorschau.datei ? symbol(vorschau.datei) : 'mdi-file' }}</v-icon>
                    <span class="text-truncate">{{ vorschau.datei?.basename }}</span>
                    <v-spacer />
                    <v-btn icon="mdi-close" size="small" variant="text" @click="vorschau.offen = false" />
                </v-card-title>
                <v-divider />
                <v-card-text class="partmedien__vorschau">
                    <template v-if="vorschau.datei">
                        <img v-if="istBild(vorschau.datei)" :src="mediaPreviewUrl(vorschau.datei.path)" :alt="vorschau.datei.basename" class="partmedien__vorschaubild">
                        <iframe v-else-if="imRahmen(vorschau.datei)" :src="mediaPreviewUrl(vorschau.datei.path)" :title="vorschau.datei.basename" class="partmedien__vorschaurahmen" />
                        <div v-else class="text-medium-emphasis py-6 text-center">{{ t('ShopView.media.noPreview') }}</div>
                    </template>
                </v-card-text>
                <v-divider />
                <v-card-actions>
                    <span v-if="vorschau.datei" class="text-caption text-medium-emphasis">
                        {{ groesse(vorschau.datei.file_size) }} · {{ zeit(vorschau.datei.last_modified) }}
                    </span>
                    <v-spacer />
                    <v-btn variant="text" prepend-icon="mdi-download-outline" @click="herunterladen(vorschau.datei)">{{ t('ShopView.media.download') }}</v-btn>
                    <v-btn variant="text" @click="vorschau.offen = false">{{ t('ShopView.common.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Verwendung: welche Artikel die Datei nennen -->
        <v-dialog v-model="verwendung.offen" max-width="640">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.media.usageTitle', { name: verwendung.name }) }}</v-card-title>
                <v-card-text>
                    <v-progress-linear v-if="verwendung.laedt" indeterminate />
                    <div v-else-if="!verwendung.liste.length" class="text-medium-emphasis">{{ t('ShopView.media.usageNone') }}</div>
                    <v-list v-else density="compact">
                        <v-list-item
                            v-for="artikel in verwendung.liste"
                            :key="artikel.parts_id"
                            :title="artikel.description"
                            :subtitle="artikel.partnumber"
                            prepend-icon="mdi-package-variant-closed"
                            :to="{ name: ENTITY_ROUTE_NAMES.article, params: { id: artikel.parts_id } }"
                            target="_blank"
                        />
                    </v-list>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="verwendung.offen = false">{{ t('ShopView.common.close') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Anzeigename eines Downloads -->
        <v-dialog v-model="anzeigename.offen" max-width="480">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.media.displayNameTitle', { name: anzeigename.datei?.basename }) }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="anzeigename.wert"
                        :label="t('ShopView.media.displayNameLabel')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autofocus
                        @keydown.enter="anzeigenameSetzen"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="anzeigename.offen = false">{{ t('ShopView.common.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :disabled="!anzeigename.wert.trim()" @click="anzeigenameSetzen">{{ t('ShopView.media.renameApply') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Umbenennen in der Bibliothek: die Verweise aller Artikel folgen, die Endung bleibt -->
        <v-dialog v-model="umbenennung.offen" max-width="480">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.media.renameTitle', { name: umbenennung.alt }) }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="umbenennung.neu"
                        :label="t('ShopView.media.renameName')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        autofocus
                        @keydown.enter="umbenennen"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="umbenennung.laeuft" @click="umbenennung.offen = false">{{ t('ShopView.common.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="umbenennung.laeuft" :disabled="!umbenennung.neu.trim()" @click="umbenennen">
                        {{ t('ShopView.media.renameApply') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Löschen: aus dem Artikel und aus der Bibliothek -->
        <v-dialog v-model="loeschung.offen" max-width="520">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.media.deleteTitle', { count: loeschung.dateien.length }) }}</v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-2">{{ t('ShopView.media.deleteConfirm') }}</div>
                    <div class="text-caption text-medium-emphasis">
                        {{ loeschung.dateien.slice(0, 10).map(d => d.basename).join(', ') }}{{ loeschung.dateien.length > 10 ? ' …' : '' }}
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="loeschung.laeuft" @click="loeschung.offen = false">{{ t('ShopView.common.cancel') }}</v-btn>
                    <v-btn color="error" variant="flat" :loading="loeschung.laeuft" @click="loeschen">{{ t('ShopView.media.delete') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Produktbild in ein anderes Format umwandeln -->
        <v-dialog v-model="umwandlung.offen" max-width="480">
            <v-card>
                <v-card-title class="text-subtitle-1">{{ t('ShopView.media.convertTitle', { name: umwandlung.name }) }}</v-card-title>
                <v-card-text>
                    <v-select
                        v-model="umwandlung.format"
                        :items="umwandlung.formate"
                        :label="t('ShopView.media.convertFormat')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        class="mb-3"
                    />
                    <div class="text-caption text-medium-emphasis">{{ t('ShopView.media.convertHint') }}</div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="umwandlung.laeuft" @click="umwandlung.offen = false">{{ t('ShopView.common.cancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="umwandlung.laeuft" :disabled="!umwandlung.format" @click="umwandeln">
                        {{ t('ShopView.media.convertApply') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <input ref="dateiEingabe" type="file" multiple :accept="akzeptiert" class="d-none" @change="dateienGewaehlt">
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import * as toast from '@/core/utils/toasts.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { ENTITY_ROUTE_NAMES } from '@/core/constants/routes.js'
import { shopMediaApi, mediaPreviewUrl, mediaDownloadUrl, mediaThumbnailUrl, uploadMedia } from '../composables/shopMediaApi.js'

const props = defineProps({
    /** Artikel; null bei der Neuanlage (dann kein Löschen aus der Bibliothek mit Verweis) */
    partsId: { type: [String, Number], default: null },
    /** Nur ansehen */
    readonly: { type: Boolean, default: false },
})
/** Bilder: Dateinamen in der Reihenfolge des Artikels, das erste ist das Vorschaubild */
const images = defineModel('images', { type: Array, default: () => [] })
/** Downloads: Paare aus Anzeigename (key) und Datei (value) */
const downloads = defineModel('downloads', { type: Array, default: () => [] })

const { t, locale } = useI18n()
const oserp = oserpStore()
const darfVerwalten = computed(() => oserp.checkPermission('edit_shop_config'))

const BEREICHE = [
    { wert: 'images', label: 'ShopView.media.areaImages', icon: 'mdi-image-multiple-outline' },
    { wert: 'downloads', label: 'ShopView.media.areaDownloads', icon: 'mdi-download-outline' },
]
const BILD_EXTS = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp'])
const RAHMEN_EXTS = new Set(['pdf', 'txt'])
const basename = (name) => String(name || '').trim().split('/').pop()

// ── Namen aus dem Artikel ──

const bereich = ref('images')
const bildNamen = computed(() => images.value.map(n => String(n).trim()).filter(Boolean))
const downloadNamen = computed(() => downloads.value.map(p => String(p?.value || '').trim()).filter(Boolean))
const namen = computed(() => bereich.value === 'images' ? bildNamen.value : downloadNamen.value)

// ── Einträge aus der Bibliothek ──

const dateien = ref([])
const laedt = ref(false)
const filter = ref('')
const auswahl = ref([])   // Pfade (bereich://name)

async function laden() {
    const art = bereich.value === 'images' ? 'image' : 'download'
    const liste = namen.value
    if (!liste.length) { dateien.value = []; return }
    laedt.value = true
    try {
        const eintraege = await shopMediaApi.items(art, liste)
        const nachName = new Map(eintraege.map(e => [e.basename, e]))
        // in der Reihenfolge des Artikels, mit dem Namen, wie er dort steht
        dateien.value = liste.map((name, index) => {
            const eintrag = nachName.get(basename(name))
            const paar = bereich.value === 'downloads' ? downloads.value.find(p => String(p?.value || '').trim() === name) : null
            return {
                ...(eintrag || { basename: basename(name), path: `${bereich.value}://${basename(name)}`, extension: basename(name).split('.').pop().toLowerCase(), file_size: 0, last_modified: 0, missing: true }),
                storage: bereich.value,
                index,
                modelName: name,
                anzeigename: paar ? String(paar.key || '') : '',
            }
        })
    } catch (e) {
        toast.error(fehlertext(e))
        dateien.value = []
    } finally {
        laedt.value = false
    }
}

watch(bereich, () => { auswahl.value = []; filter.value = ''; laden() })
watch(() => JSON.stringify([namen.value, bereich.value === 'downloads' ? downloads.value.map(p => p?.key) : null]), () => laden(), { immediate: true })

const gefiltert = computed(() => {
    const suche = (filter.value || '').trim().toLowerCase()
    return suche ? dateien.value.filter(d => d.basename.toLowerCase().includes(suche) || (d.anzeigename || '').toLowerCase().includes(suche)) : dateien.value
})
const gewaehlteDateien = computed(() => {
    const menge = new Set(auswahl.value)
    return dateien.value.filter(d => menge.has(d.path))
})

const spalten = computed(() => [
    { title: t('ShopView.media.colName'), key: 'basename', sortable: false },
    ...(bereich.value === 'downloads' ? [{ title: t('ShopView.media.displayNameLabel'), key: 'anzeigename', sortable: false }] : []),
    { title: t('ShopView.media.colSize'), key: 'file_size', sortable: false, width: 110, align: 'end' },
    { title: t('ShopView.media.colModified'), key: 'last_modified', sortable: false, width: 170 },
    { title: t('ShopView.media.colActions'), key: 'aktionen', sortable: false, width: 60, align: 'end' },
])

/** Fehlermeldung: übersetzter Code, sonst der Text aus dem Backend */
function fehlertext(e) {
    const code = e?.code ? `ShopView.errors.${e.code}` : ''
    return code && t(code) !== code ? t(code) : (e?.message || t('ShopView.errors.API_ERROR'))
}

// ── Schiene und Ansicht ──

const { mdAndUp } = useDisplay()
const railSchmal = ref(false)
try { railSchmal.value = localStorage.getItem('oserp.shopMedia.rail') === 'schmal' } catch { /* ohne Speicher */ }
watch(railSchmal, (wert) => { try { localStorage.setItem('oserp.shopMedia.rail', wert ? 'schmal' : 'breit') } catch { /* ohne Speicher */ } })
const railNurSymbole = computed(() => railSchmal.value || !mdAndUp.value)

const ansicht = ref('grid')
try { ansicht.value = localStorage.getItem('oserp.shopMedia.view') === 'list' ? 'list' : 'grid' } catch { /* ohne Speicher */ }
watch(ansicht, (wert) => { try { localStorage.setItem('oserp.shopMedia.view', wert) } catch { /* ohne Speicher */ } })

// ── Darstellung ──

/** Kachelbild trägt die Änderungszeit, damit eine ersetzte Datei nicht aus dem Browser-Cache kommt */
const kachelBild = (datei) => {
    const url = datei.storage === 'downloads' ? mediaPreviewUrl(datei.path) : mediaThumbnailUrl(datei.basename)
    return `${url}&v=${datei.last_modified}`
}
const istBild = (datei) => BILD_EXTS.has((datei.extension || '').toLowerCase())
const imRahmen = (datei) => RAHMEN_EXTS.has((datei.extension || '').toLowerCase())
function symbol(datei) {
    const ext = (datei.extension || '').toLowerCase()
    if (BILD_EXTS.has(ext)) return 'mdi-image-outline'
    if (ext === 'pdf') return 'mdi-file-pdf-box'
    if (ext === 'zip') return 'mdi-folder-zip-outline'
    if (ext === 'txt') return 'mdi-file-document-outline'
    return 'mdi-file-outline'
}
function groesse(bytes) {
    const n = Number(bytes) || 0
    if (n < 1024) return `${n} B`
    if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`
    return `${(n / 1048576).toFixed(1)} MB`
}
function zeit(sekunden) {
    const n = Number(sekunden) || 0
    return n ? new Date(n * 1000).toLocaleString(locale.value) : ''
}

// ── Auswahl ──

const istGewaehlt = (datei) => auswahl.value.includes(datei.path)
function umschalten(datei) {
    auswahl.value = istGewaehlt(datei) ? auswahl.value.filter(p => p !== datei.path) : [...auswahl.value, datei.path]
}
function kachelKlick(ereignis, datei) {
    if (props.readonly) return
    if (ereignis.ctrlKey || ereignis.metaKey || ereignis.shiftKey) umschalten(datei)
    else auswahl.value = istGewaehlt(datei) && auswahl.value.length === 1 ? [] : [datei.path]
}

// ── Kontextmenü ──

const menue = ref({ offen: false, ziel: [0, 0], datei: null })
function menueOeffnen(ereignis, datei) {
    menue.value = { offen: false, ziel: [ereignis.clientX, ereignis.clientY], datei }
    requestAnimationFrame(() => { menue.value.offen = true })
}

// ── Ansehen, Öffnen, Herunterladen ──

const vorschau = ref({ offen: false, datei: null })
function ansehen(datei) {
    if (!datei || datei.missing) return
    vorschau.value = { offen: true, datei }
}
function imTabOeffnen(datei) {
    if (!datei) return
    window.open(istBild(datei) || imRahmen(datei) ? mediaPreviewUrl(datei.path) : mediaDownloadUrl(datei.path), '_blank')
}
function herunterladen(datei) {
    if (!datei) return
    window.open(mediaDownloadUrl(datei.path), '_blank')
}

// ── Verwendung ──

const verwendung = ref({ offen: false, laedt: false, name: '', liste: [] })
async function verwendungZeigen(datei) {
    if (!datei) return
    verwendung.value = { offen: true, laedt: true, name: datei.basename, liste: [] }
    try {
        verwendung.value.liste = await shopMediaApi.usage(datei.path)
    } catch (e) {
        toast.error(fehlertext(e))
    } finally {
        verwendung.value.laedt = false
    }
}

// ── Artikel: hinzufügen, entfernen, Reihenfolge, Anzeigename ──

function hinzufuegen(neueNamen) {
    if (bereich.value === 'images') {
        const vorhanden = new Set(bildNamen.value.map(basename))
        images.value = [...bildNamen.value, ...neueNamen.filter(n => !vorhanden.has(basename(n)))]
    } else {
        const vorhanden = new Set(downloadNamen.value.map(basename))
        images.value = images.value // unverändert
        downloads.value = [
            ...downloads.value,
            ...neueNamen.filter(n => !vorhanden.has(basename(n))).map(n => ({ key: n.replace(/\.[^.]+$/, ''), value: n })),
        ]
    }
}
function vomArtikelEntfernen(liste) {
    const weg = new Set(liste.filter(Boolean).map(d => d.modelName))
    if (!weg.size) return
    if (bereich.value === 'images') images.value = images.value.filter(n => !weg.has(String(n).trim()))
    else downloads.value = downloads.value.filter(p => !weg.has(String(p?.value || '').trim()))
    auswahl.value = []
}
function alsVorschaubild(datei) {
    if (!datei) return
    images.value = [datei.modelName, ...images.value.filter(n => String(n).trim() !== datei.modelName)]
}
/** Nach Umbenennen oder Umwandeln in der Bibliothek: den Namen im Artikel nachziehen */
function nameErsetzen(alt, neu) {
    if (bereich.value === 'images') images.value = images.value.map(n => String(n).trim() === alt ? neu : n)
    else downloads.value = downloads.value.map(p => String(p?.value || '').trim() === alt ? { ...p, value: neu } : p)
}

const anzeigename = ref({ offen: false, datei: null, wert: '' })
function anzeigenameOeffnen(datei) {
    if (!datei) return
    anzeigename.value = { offen: true, datei, wert: datei.anzeigename || '' }
}
function anzeigenameSetzen() {
    const a = anzeigename.value
    if (!a.wert.trim()) return
    downloads.value = downloads.value.map(p => String(p?.value || '').trim() === a.datei.modelName ? { ...p, key: a.wert.trim() } : p)
    a.offen = false
}

// ── Hochladen (Knopf oder Ablegen): Dialog mit Name und Format, dann in die
//    Bibliothek und an den Artikel ──

const dateiEingabe = ref(null)
const akzeptiert = computed(() => bereich.value === 'downloads'
    ? '.pdf,.zip,.txt,.jpg,.jpeg,.png,.gif,.webp'
    : 'image/jpeg,image/png,image/gif,image/webp')
const hochladung = ref({ offen: false, laeuft: false, zeilen: [] })
const formatWahl = computed(() => [
    { title: t('ShopView.media.keepFormat'), value: '' },
    { title: 'WebP', value: 'webp' },
    { title: 'JPEG', value: 'jpg' },
    { title: 'PNG', value: 'png' },
])
const zieht = ref(false)
let ziehtTiefe = 0

function hochladenWaehlen() {
    dateiEingabe.value?.click()
}
function dateienGewaehlt(ereignis) {
    const liste = Array.from(ereignis.target.files || [])
    ereignis.target.value = ''
    hochladungVorbereiten(liste)
}
/** Endung, unter der die Datei abgelegt wird: Zielformat, sonst die der Datei */
function zielEndung(zeile) {
    const ext = zeile.endung === 'jpeg' ? 'jpg' : zeile.endung
    return bereich.value === 'images' && zeile.format ? zeile.format : ext
}
function symbolFuerEndung(ext) {
    return symbol({ extension: ext })
}
function hochladungVorbereiten(liste) {
    if (!liste.length || props.readonly) return
    hochladung.value = {
        offen: true,
        laeuft: false,
        zeilen: liste.map((datei, i) => ({
            id: `${Date.now()}-${i}`,
            datei,
            basis: datei.name.replace(/\.[^.]+$/, ''),
            endung: (datei.name.split('.').pop() || '').toLowerCase(),
            format: '',
            status: 'offen',
            percent: 0,
            fehler: '',
        })),
    }
}
function ziehenBeginn() {
    ziehtTiefe++
    zieht.value = true
}
function ziehenEnde() {
    ziehtTiefe = Math.max(0, ziehtTiefe - 1)
    if (!ziehtTiefe) zieht.value = false
}
function ablegen(ereignis) {
    ziehtTiefe = 0
    zieht.value = false
    if (props.readonly) return
    hochladungVorbereiten(Array.from(ereignis.dataTransfer?.files || []))
}
/** Lädt die offenen Zeilen des Dialogs hoch; Zeilen mit Fehler bleiben zur Korrektur stehen */
async function hochladen() {
    const h = hochladung.value
    const ziel = bereich.value
    h.laeuft = true
    const neueNamen = []
    for (const zeile of h.zeilen) {
        if (zeile.status === 'fertig' || !zeile.basis.trim()) continue
        zeile.status = 'laeuft'
        zeile.fehler = ''
        zeile.percent = 0
        try {
            const antwort = await uploadMedia(ziel, zeile.datei, (prozent) => { zeile.percent = prozent }, {
                name: `${zeile.basis.trim()}.${zielEndung(zeile)}`,
                format: ziel === 'images' ? zeile.format : '',
            })
            zeile.status = 'fertig'
            zeile.percent = 100
            neueNamen.push(antwort.filename)
            // Datei liegt in der Bibliothek, aber ohne Vorschaubild (GD fehlt,
            // kein WebP, Verzeichnis nicht schreibbar): sagen, statt es still
            // zu übergehen — die Webseite zeigt sonst kein Bild in den Listen
            if (antwort.thumbnail_error) {
                toast.warning(t('ShopView.media.thumbnailError', { name: antwort.filename, reason: antwort.thumbnail_error }))
            }
        } catch (e) {
            zeile.status = 'fehler'
            zeile.fehler = fehlertext(e)
        }
    }
    h.laeuft = false
    if (neueNamen.length) {
        toast.success(t('ShopView.media.uploadDone', { count: neueNamen.length }))
        if (ziel === bereich.value) hinzufuegen(neueNamen)
    }
    if (!h.zeilen.some(z => z.status !== 'fertig')) h.offen = false
}

// ── Bibliothek: Umbenennen, Löschen, Umwandeln ──

const umbenennung = ref({ offen: false, laeuft: false, datei: null, alt: '', neu: '' })
function umbenennenOeffnen(datei) {
    if (!datei) return
    umbenennung.value = { offen: true, laeuft: false, datei, alt: datei.basename, neu: datei.basename }
}
async function umbenennen() {
    const u = umbenennung.value
    if (!u.neu.trim() || u.neu.trim() === u.alt) { u.offen = false; return }
    u.laeuft = true
    try {
        const ergebnis = await shopMediaApi.rename(bereich.value, u.datei.path, u.neu.trim())
        toast.success(t('ShopView.media.renameDone', { name: ergebnis.name, parts: ergebnis.parts }))
        nameErsetzen(u.datei.modelName, ergebnis.name)
        u.offen = false
    } catch (e) {
        toast.error(fehlertext(e))
    } finally {
        u.laeuft = false
    }
}

/**
 * Löschen heißt: aus dem Artikel und aus der Bibliothek. Nennt ein anderer
 * Artikel die Datei, bleibt sie dort (MEDIA_IN_USE) — der Verweis dieses
 * Artikels ist dann schon weg, die Karte zieht nach und sagt es.
 */
const loeschung = ref({ offen: false, laeuft: false, dateien: [] })
function loeschenFragen(liste) {
    const dateienListe = liste.filter(Boolean)
    if (!dateienListe.length) return
    const fehlend = dateienListe.filter(d => d.missing)
    if (fehlend.length) vomArtikelEntfernen(fehlend)
    const vorhanden = dateienListe.filter(d => !d.missing)
    if (!vorhanden.length) return
    loeschung.value = { offen: true, laeuft: false, dateien: vorhanden }
}
async function loeschen() {
    const l = loeschung.value
    l.laeuft = true
    const weg = []
    let geloescht = 0
    for (const datei of l.dateien) {
        try {
            await shopMediaApi.remove(bereich.value, [datei.path], Number(props.partsId) || 0)
            geloescht++
            weg.push(datei)
        } catch (e) {
            if (e?.code === 'MEDIA_IN_USE') {
                toast.info(t('ShopView.media.deleteKept', { name: datei.basename }))
                weg.push(datei)
            } else {
                toast.error(fehlertext(e))
            }
        }
    }
    if (geloescht) toast.success(t('ShopView.media.deleteDone', { count: geloescht }))
    vomArtikelEntfernen(weg)
    l.laeuft = false
    l.offen = false
}

const ZIELFORMATE = [
    { title: 'WebP', value: 'webp' },
    { title: 'JPEG', value: 'jpg' },
    { title: 'PNG', value: 'png' },
]
const umwandlung = ref({ offen: false, laeuft: false, datei: null, name: '', format: '', formate: [] })
function umwandlungOeffnen(datei) {
    if (!datei) return
    const ext = (datei.extension || '').toLowerCase()
    const jetzt = ext === 'jpeg' ? 'jpg' : ext
    const formate = ZIELFORMATE.filter(f => f.value !== jetzt)
    umwandlung.value = { offen: true, laeuft: false, datei, name: datei.basename, format: formate[0]?.value || '', formate }
}
async function umwandeln() {
    const u = umwandlung.value
    u.laeuft = true
    try {
        const ergebnis = await shopMediaApi.convert(u.datei.path, u.format)
        toast.success(t('ShopView.media.convertResult', {
            name: ergebnis.name, parts: ergebnis.parts, before: groesse(ergebnis.size_before), after: groesse(ergebnis.size_after),
        }))
        nameErsetzen(u.datei.modelName, ergebnis.name)
        u.offen = false
    } catch (e) {
        toast.error(fehlertext(e))
    } finally {
        u.laeuft = false
    }
}
</script>

<style scoped>
.partmedien__layout {
    display: flex;
    gap: var(--oserp-gap);
    align-items: stretch;
    min-height: 260px;
}
/* Schiene der Bereiche, wie die Werkzeugleiste des HugoCMS-Dateimanagers */
.partmedien__rail {
    flex: 0 0 auto;
    display: flex;
    flex-direction: column;
    gap: 4px;
    width: 188px;
    padding: var(--oserp-gap-xs);
    background-color: var(--oserp-surface-soft);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius);
    overflow: hidden;
    transition: width 0.18s ease;
    user-select: none;
}
.partmedien__rail--schmal {
    width: 48px;
}
.partmedien__knopf {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    height: 38px;
    padding: 0 8px;
    border: 1px solid transparent;
    border-radius: var(--oserp-radius-sm);
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 0.9rem;
    text-align: left;
    white-space: nowrap;
    cursor: pointer;
}
.partmedien__knopf:hover {
    background-color: var(--oserp-surface);
    border-color: var(--oserp-border);
}
.partmedien__knopf--aktiv,
.partmedien__knopf--aktiv:hover {
    background-color: rgba(var(--v-theme-primary), 0.12);
    border-color: rgba(var(--v-theme-primary), 0.3);
    color: rgb(var(--v-theme-primary));
}
.partmedien__text {
    flex: 1 1 auto;
    overflow: hidden;
    text-overflow: ellipsis;
}
.partmedien__zahl {
    flex: 0 0 auto;
    min-width: 18px;
    padding: 0 5px;
    border-radius: 9px;
    background-color: var(--oserp-surface);
    color: var(--oserp-text-muted);
    font-size: 0.72rem;
    line-height: 18px;
    text-align: center;
}
.partmedien__umschalter {
    justify-content: flex-end;
    height: 30px;
    margin-bottom: 2px;
    color: var(--oserp-text-muted);
}
.partmedien__abschnitt {
    padding: 6px 8px 2px;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--oserp-text-muted);
}
.partmedien__rail--schmal .partmedien__text,
.partmedien__rail--schmal .partmedien__abschnitt,
.partmedien__rail--schmal .partmedien__zahl {
    display: none;
}
.partmedien__rail--schmal .partmedien__knopf {
    justify-content: center;
    padding: 0;
}
@media (max-width: 959.98px) {
    .partmedien__rail {
        width: 48px;
    }
    .partmedien__rail .partmedien__text,
    .partmedien__rail .partmedien__abschnitt,
    .partmedien__rail .partmedien__zahl {
        display: none;
    }
    .partmedien__rail .partmedien__knopf {
        justify-content: center;
        padding: 0;
    }
}
.partmedien__inhalt {
    flex: 1 1 auto;
    min-width: 0;
}
.partmedien__leiste {
    padding: var(--oserp-gap-xs) var(--oserp-gap-sm);
    background-color: var(--oserp-surface-soft);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius);
}
.partmedien__ablage {
    position: relative;
    min-height: 180px;
    border: 1px dashed transparent;
    border-radius: var(--oserp-radius);
    transition: border-color 0.15s, background-color 0.15s;
}
.partmedien__ablage--zieht {
    border-color: rgb(var(--v-theme-primary));
    background-color: var(--oserp-surface-soft);
}
.partmedien__hinweis {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--oserp-gap-sm);
    pointer-events: none;
    color: rgb(var(--v-theme-primary));
}
.partmedien__raster {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: var(--oserp-gap-sm);
}
.partmedien__kachel {
    position: relative;
    cursor: pointer;
    user-select: none;
}
.partmedien__kachel--gewaehlt {
    border-color: rgb(var(--v-theme-primary));
    background-color: var(--oserp-surface-soft);
}
.partmedien__kachel--fehlt {
    border-style: dashed;
}
.partmedien__symbol {
    height: 110px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--oserp-text-muted);
    background-color: var(--oserp-surface-soft);
}
.partmedien__wahl {
    position: absolute;
    top: 2px;
    left: 2px;
    background-color: var(--oserp-surface);
    border-radius: var(--oserp-radius-sm);
    opacity: 0;
    transition: opacity 0.15s;
}
.partmedien__menue {
    position: absolute;
    top: 4px;
    right: 4px;
    opacity: 0;
    transition: opacity 0.15s;
}
.partmedien__marke {
    position: absolute;
    left: 4px;
    bottom: 52px;
}
.partmedien__kachel:hover .partmedien__wahl,
.partmedien__kachel:hover .partmedien__menue,
.partmedien__kachel--gewaehlt .partmedien__wahl {
    opacity: 1;
}
.partmedien__auswahl {
    padding: var(--oserp-gap-xs) var(--oserp-gap-sm);
    background-color: var(--oserp-surface-soft);
    border-radius: var(--oserp-radius-sm);
}
.partmedien__uploadzeile + .partmedien__uploadzeile {
    margin-top: var(--oserp-gap-sm);
}
.partmedien__vorschau {
    text-align: center;
}
.partmedien__vorschaubild {
    max-width: 100%;
    max-height: 70vh;
    object-fit: contain;
}
.partmedien__vorschaurahmen {
    width: 100%;
    height: 70vh;
    border: 0;
}
</style>
