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
    Als neu verwenden (templatePartsId): die Neuanlage zeigt die Shop-Angaben
    der Vorlage; die Kanalbilder der Vorlage kopiert savePartShopData beim
    Anlegen, weil sie je Artikel abgelegt sind.
-->
<template>
    <v-card id="abschnitt-shop" variant="outlined" elevation="1" class="mt-4 sprungziel">
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

            <!-- Versandartikel: gehört seiner Versandart und wird nie angeboten -->
            <v-alert v-if="versandartikelVon" type="info" variant="tonal" density="compact" class="mb-2">
                {{ t('ShopView.partCard.shippingPart', { name: versandartikelVon }) }}
            </v-alert>

            <v-switch
                v-model="daten.listed"
                :label="t('ShopView.partCard.listed')"
                :disabled="!darfBearbeiten || laedt || (!!versandartikelVon && !daten.listed)"
                color="primary"
                density="compact"
                hide-details
            />
            <div class="text-caption text-medium-emphasis mb-2">{{ t('ShopView.partCard.listedHint') }}</div>

            <v-row v-if="daten.listed" dense>
                <!-- Kategorie aus dem Sortiment (dev/shop-sortiment.md); die Gruppe
                     gehört der Kategorie und steht darunter -->
                <v-col id="abschnitt-shop-angaben" cols="12" sm="6" class="py-1 sprungziel">
                    <v-autocomplete
                        v-model="daten.category_id"
                        :items="kategorieAuswahl"
                        item-value="value"
                        item-title="title"
                        :label="t('ShopView.partCard.category')"
                        :hint="t('ShopView.partCard.categoryHint')"
                        persistent-hint
                        :readonly="!darfBearbeiten"
                        clearable
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        autocomplete="off"
                    >
                        <!-- Vuetify 4: item ist der Eintrag selbst (kein item.raw mehr) -->
                        <template #item="{ props: eintrag, item }">
                            <v-list-item v-bind="eintrag" :subtitle="item.group" />
                        </template>
                        <template #append>
                            <v-btn
                                icon="mdi-tag-multiple-outline"
                                size="x-small"
                                variant="text"
                                :title="t('ShopView.partCard.categoryManage')"
                                @click="sortimentOeffnen"
                            />
                        </template>
                    </v-autocomplete>
                </v-col>

                <!-- Produktseite (parts_ext.hugoshop_hyperlink): leer = Seite nach der
                     Artikelnummer. Bei der Neuanlage trägt die Karte den Vorschlag der
                     Maske ein; nach der Eingabe prüft sie, ob ein anderer Artikel die
                     Seite schon belegt (checkShopPageName). Bei bestehenden Artikeln
                     übernimmt der Knopf im Feld den Vorschlag -->
                <v-col cols="12" sm="6" class="py-1">
                    <v-text-field
                        v-model="daten.hyperlink"
                        :label="t('ShopView.partCard.hyperlink')"
                        :hint="t('ShopView.partCard.hyperlinkHint')"
                        :placeholder="partsId ? partnumber : suggestedLink"
                        persistent-placeholder
                        :error-messages="seiteBelegt ? [t('ShopView.partCard.hyperlinkTaken', seiteBelegt)] : []"
                        :readonly="!darfBearbeiten"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        autocomplete="off"
                        @update:model-value="hyperlinkGetippt"
                        @blur="seitePruefen"
                    >
                        <template v-if="shopLink || vorschlagMoeglich" #append-inner>
                            <v-btn
                                v-if="vorschlagMoeglich"
                                icon="mdi-auto-fix"
                                size="x-small"
                                variant="text"
                                :title="t('ShopView.partCard.hyperlinkSuggest', { link: suggestedLink })"
                                @click="vorschlagUebernehmen"
                            />
                            <v-btn
                                v-if="shopLink"
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

                <!-- Einordnung im Sortiment (parts_ext.hugoshop_breadcrumbs): Kurzbeschreibung
                     der Produktseite und Suchbegriffe, keine Navigation der Webseite -->
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

                <!-- Medien des Artikels (dev/shop-medienbibliothek.md): Produktbilder
                     und Downloads aus der zentralen Bibliothek — das erste Bild ist
                     das Vorschaubild in Suche, Warenkorb und Rechnung. Hochladen,
                     aus der Bibliothek wählen, Vorschau, Kontextmenü -->
                <v-col id="abschnitt-shop-bilder" cols="12" class="py-1 sprungziel">
                    <ShopPartMedia
                        v-model:images="daten.images"
                        v-model:downloads="daten.downloads"
                        :parts-id="props.partsId"
                        :readonly="!darfBearbeiten"
                    />
                </v-col>

                <!-- Technische Daten, Eigenschaften, Downloads -->
                <v-col id="abschnitt-shop-technik" cols="12" class="py-1 sprungziel">
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

                <!-- Versand (dev/shop-versand.md, Schritt 5). Das Gewicht steht
                     in den Stammdaten der Artikelmaske (parts.weight) -->
                <v-col id="abschnitt-shop-versand" cols="12" class="pt-4 pb-1 sprungziel">
                    <div class="text-subtitle-2">{{ t('ShopView.partCard.shipping') }}</div>
                    <div class="text-caption" :class="gewicht === null ? 'text-warning' : 'text-medium-emphasis'">
                        {{ gewicht === null
                            ? t('ShopView.partCard.weightMissing')
                            : t('ShopView.partCard.weightInfo', { weight: gewicht, unit: gewichtseinheit }) }}
                    </div>
                </v-col>
                <v-col cols="12" sm="6" class="py-1">
                    <v-select
                        v-model="daten.shipping.shipping_method_id"
                        :items="versandartAuswahl"
                        :label="t('ShopView.partCard.shippingMethod')"
                        :hint="t('ShopView.partCard.shippingMethodHint')"
                        persistent-hint
                        :readonly="!darfBearbeiten"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                    />
                </v-col>
                <v-col cols="12" sm="6" class="py-1">
                    <v-select
                        v-model="daten.shipping.delivery_term_id"
                        :items="lieferbedingungAuswahl"
                        :label="t('ShopView.partCard.deliveryTerm')"
                        :hint="lieferbedingungText || t('ShopView.partCard.deliveryTermHint')"
                        persistent-hint
                        clearable
                        :readonly="!darfBearbeiten"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                    />
                </v-col>
                <v-col v-for="feld in ['length', 'width', 'height']" :key="feld" cols="4" sm="2" class="py-1">
                    <v-text-field
                        persistent-placeholder
                        v-model="daten.shipping[feld]"
                        type="number"
                        step="0.1"
                        min="0"
                        suffix="cm"
                        :label="t(`ShopView.partCard.${feld}`)"
                        :rules="[groesserNull]"
                        :readonly="!darfBearbeiten"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                    />
                </v-col>
                <v-col cols="12" sm="6" class="py-1">
                    <v-text-field
                        v-model="daten.shipping.min_qty"
                        type="number"
                        step="1"
                        min="0"
                        :label="t('ShopView.partCard.minQty')"
                        :hint="t('ShopView.partCard.minQtyHint')"
                        persistent-hint
                        :rules="[groesserNull]"
                        :readonly="!darfBearbeiten"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                    />
                </v-col>
            </v-row>

            <!-- Verkaufskanäle zuletzt, unter dem Versand -->
            <template v-if="daten.listed && daten.channels.length">
                <!-- Alle Kanäle als Panels; das Häkchen ordnet den Artikel dem Kanal
                     zu. Aufklappbar nur mit Häkchen. Das Häkchen steht neben der
                     Kopfzeile, nicht darin: die ist ein <button>, und Firefox gibt
                     Klicks auf Felder darin nicht weiter. -->
                <div id="abschnitt-shop-kanaele" class="text-subtitle-2 mt-4 mb-2 sprungziel">{{ t('ShopView.partCard.channels') }}</div>
                <v-alert v-if="kanalFehlt" type="warning" variant="tonal" density="compact" class="my-1">
                    {{ t('ShopView.partCard.chooseChannel') }}
                </v-alert>
                <!-- Abstand zwischen den Panels (ga-2); im Accordion sind die inneren
                     Ecken sonst eckig — die Variable rundet sie wie die äußeren -->
                <v-expansion-panels
                    v-model="offeneKanaele"
                    variant="accordion"
                    multiple
                    class="mb-1 ga-1"
                    style="--v-expansion-panels-inner-radius: 4px"
                >
                    <v-expansion-panel
                        v-for="kanal in daten.channels"
                        :key="kanal.channel_id"
                        :value="kanal.channel_id"
                        elevation="0"
                        class="border"
                        color="grey-lighten-3"
                    >
                        <div class="d-flex align-center">
                            <v-checkbox-btn
                                :model-value="kanal.active"
                                :disabled="!darfBearbeiten || gesperrt(kanal) || letzterKanal(kanal)"
                                :title="gesperrt(kanal) ? t('ShopView.partCard.serviceNotOnMarketplace') : ''"
                                density="compact"
                                class="flex-grow-0 ml-2"
                                @update:model-value="an => kanalSetzen(kanal, an)"
                            />
                            <v-expansion-panel-title
                                class="py-2"
                                :readonly="!kanal.active"
                                :hide-actions="!kanal.active"
                            >
                                <span class="font-weight-medium" :class="kanal.active ? '' : 'text-medium-emphasis'">
                                    {{ kanalName(kanal) }}
                                </span>
                                <!-- Sprung zu den Einstellungen des Kanals; v-icon statt v-btn,
                                     weil der Panel-Kopf selbst ein <button> ist. .stop: kein Auf-/Zuklappen -->
                                <v-icon
                                    v-if="darfKanaele"
                                    icon="mdi-pencil"
                                    size="small"
                                    role="button"
                                    tabindex="0"
                                    class="ml-2 text-medium-emphasis"
                                    :title="t('ShopView.partCard.editChannel')"
                                    :aria-label="t('ShopView.partCard.editChannel')"
                                    @click.stop="kanalEinstellungen(kanal)"
                                    @keydown.enter.stop.prevent="kanalEinstellungen(kanal)"
                                    @keydown.space.stop.prevent="kanalEinstellungen(kanal)"
                                />
                                <v-spacer />
                                <span v-if="kanal.active" class="text-body-2 mr-2">
                                    {{ geld(kanalPreis(kanal).brutto) }} {{ t('ShopView.partCard.gross') }}
                                </span>
                            </v-expansion-panel-title>
                        </div>
                        <v-expansion-panel-text>
                            <v-row dense>
                                <!-- Verfügbarkeit: angeboten, aber vorübergehend nicht
                                     bestellbar (parts_channel_shop.unavailable) -->
                                <v-col cols="12" class="pb-5">
                                    <v-switch
                                        v-model="kanal.unavailable"
                                        :label="t('ShopView.partCard.unavailable')"
                                        :hint="t('ShopView.partCard.unavailableHint')"
                                        persistent-hint
                                        :readonly="!darfBearbeiten"
                                        color="error"
                                        density="compact"
                                        inset
                                    />
                                    <v-alert
                                        v-if="obsolete"
                                        type="warning"
                                        variant="tonal"
                                        density="compact"
                                        class="mt-2"
                                        :text="t('ShopView.partCard.obsoleteHint')"
                                    />
                                </v-col>

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
                                        persistent-placeholder
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

                                <!-- HugoShop: Versand je Zone für diesen Artikel allein, in seiner
                                     Mindestabnahme — im Warenkorb mit weiteren Artikeln kann es anders kommen -->
                                <v-col v-if="kanal.type === 'hugoshop' && kanal.active && kanal.shipping_prices.length" cols="12" class="py-1">
                                    <div class="text-body-2 font-weight-medium">{{ t('ShopView.partCard.shippingPrices') }}</div>
                                    <div class="text-caption text-medium-emphasis mb-1">
                                        {{ t('ShopView.partCard.shippingPricesHint', { menge: mindestmenge, art: grundpreisArt }) }}
                                    </div>
                                    <v-table density="compact">
                                        <!-- Kosten und Differenz nur, wenn Kosten gepflegt sind — netto -->
                                        <thead v-if="mitKosten(kanal)">
                                            <tr>
                                                <th>{{ t('ShopView.partCard.shippingZone') }}</th>
                                                <th>{{ t('ShopView.partCard.shippingMethod') }}</th>
                                                <th class="text-right">{{ t('ShopView.partCard.shippingPrice') }}</th>
                                                <th class="text-right">{{ t('ShopView.partCard.shippingCost') }}</th>
                                                <th class="text-right">{{ t('ShopView.partCard.shippingDifference') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="zeile in kanal.shipping_prices" :key="zeile.zone_id ?? 'rest'">
                                                <td>{{ zeile.zone || t('ShopView.shippingConfig.otherCountries') }}</td>
                                                <td>{{ zeile.status === 'ok' ? zeile.method : versandStatusText(zeile.status) }}</td>
                                                <td class="text-right text-no-wrap">
                                                    <template v-if="zeile.status === 'ok'">
                                                        {{ WAHR_WERTE.includes(zeile.free) ? t('ShopView.partCard.shippingFree') : geld(Number(zeile.price)) }}
                                                        <span
                                                            v-if="!WAHR_WERTE.includes(zeile.free) && WAHR_WERTE.includes(zeile.free_applies) && zeile.free_from !== null"
                                                            class="text-caption text-medium-emphasis"
                                                        >· {{ t('ShopView.partCard.shippingFreeFrom', { betrag: geld(Number(zeile.free_from)) }) }}</span>
                                                    </template>
                                                </td>
                                                <template v-if="mitKosten(kanal)">
                                                    <td class="text-right text-no-wrap">
                                                        {{ zeile.cost !== null && zeile.cost !== undefined ? geld(Number(zeile.cost)) : '' }}
                                                    </td>
                                                    <td
                                                        class="text-right text-no-wrap"
                                                        :class="differenz(zeile) !== null && differenz(zeile) < 0 ? 'text-error' : ''"
                                                    >
                                                        {{ differenz(zeile) === null ? '' : (differenz(zeile) > 0 ? '+' : '') + geld(differenz(zeile)) }}
                                                    </td>
                                                </template>
                                            </tr>
                                        </tbody>
                                    </v-table>
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
                                    <!-- Kleinanzeigen-Kategorie: Blätter des Baums (dev/shop-kleinanzeigen.md K22), leer = Kanalvorgabe -->
                                    <v-autocomplete
                                        v-else-if="feld.source"
                                        v-model="kanal.settings[feld.name]"
                                        :items="quellen[feld.source] || []"
                                        item-title="path"
                                        item-value="path"
                                        :label="t(feld.label)"
                                        :hint="feld.hint ? t(feld.hint) : ''"
                                        :no-data-text="t('ShopView.kleinanzeigen.ad.categoryEmpty')"
                                        :readonly="!darfBearbeiten"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        clearable
                                        auto-select-first
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
                                        {{ t('ShopView.partCard.channelImages', { kanal: kanalName(kanal) }) }}
                                    </div>
                                    <div v-if="!partsId" class="text-caption">
                                        {{ kanal.template_images
                                            ? t('ShopView.partCard.imagesFromTemplate', { n: kanal.template_images })
                                            : t('ShopView.partCard.imagesAfterCreate') }}
                                    </div>
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
                                                :key="quelle.channel_id"
                                                size="small"
                                                variant="text"
                                                prepend-icon="mdi-image-move"
                                                :loading="bildLaedt"
                                                :disabled="!darfBearbeiten"
                                                @click="bilderUebernehmen(quelle, kanal)"
                                            >
                                                {{ t('ShopView.partCard.copyImagesFrom', { kanal: kanalName(quelle) }) }}
                                            </v-btn>
                                        </div>
                                    </template>
                                </v-col>

                                <!-- HugoShop: ohne passende Versandart keine Seite -->
                                <v-col v-if="kanal.type === 'hugoshop' && kanal.active && kanal.shipping_message" cols="12" class="py-1">
                                    <v-alert type="warning" variant="tonal" density="compact">
                                        {{ t('ShopView.partCard.shippingUnfit') }}
                                        <div class="text-caption">{{ kanal.shipping_message }}</div>
                                    </v-alert>
                                </v-col>

                                <!-- Stand des Abgleichs mit dem Marktplatz -->
                                <v-col v-if="kanal.type !== 'hugoshop' && partsId" cols="12" class="py-1">
                                    <v-alert
                                        :type="kanal.sync_status === 'error' ? 'error' : kanal.sync_status === 'active' ? 'success' : 'info'"
                                        variant="tonal"
                                        density="compact"
                                    >
                                        {{ t('ShopView.partCard.syncStatus', { kanal: kanalName(kanal) }) }}:
                                        {{ te(`ShopView.partCard.sync.${kanal.sync_status}`)
                                            ? t(`ShopView.partCard.sync.${kanal.sync_status}`)
                                            : t('ShopView.partCard.sync.none') }}
                                        <span v-if="kanal.external_id" class="text-caption">
                                            · {{ t('ShopView.partCard.listingId', { id: kanal.external_id }) }}
                                        </span>
                                        <div v-if="kanal.sync_error" class="text-caption">{{ kanal.sync_error }}</div>
                                        <!-- eBay: Produktseite im eBay-Board gestalten (dev/shop-ebay.md) -->
                                        <div v-if="kanal.type === 'ebay'" class="mt-2">
                                            <v-btn
                                                size="small"
                                                variant="tonal"
                                                prepend-icon="mdi-palette-outline"
                                                :to="{ name: 'shop-ebay', query: { channel: kanal.channel_id, tab: 'offered', page: partsId } }"
                                            >
                                                {{ t('ShopView.ebay.designPage') }}
                                            </v-btn>
                                        </div>
                                        <!-- Kleinanzeigen: eingestellt wird im Board (dev/shop-kleinanzeigen.md) -->
                                        <div v-if="kanal.type === 'kleinanzeigen'" class="mt-2">
                                            <v-btn
                                                size="small"
                                                variant="tonal"
                                                prepend-icon="mdi-bullhorn-outline"
                                                :to="{ name: 'shop-kleinanzeigen', query: { channel: kanal.channel_id, tab: kanal.sync_status === 'active' || kanal.sync_status === 'reserved' ? 'online' : 'drafts' } }"
                                            >
                                                {{ t('ShopView.kleinanzeigen.openBoard') }}
                                            </v-btn>
                                        </div>
                                    </v-alert>
                                </v-col>
                            </v-row>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </template>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useShop } from '@/features/shop/composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'
import ShopKeyValueEditor from './shop-key-value.editor.vue'
import ShopPartMedia from './shop-part-media.vue'
import { shopSlug } from '@/features/shop/utils/slug.js'

const props = defineProps({
    /** Artikel; leer bei der Neuanlage */
    partsId: { type: [String, Number], default: null },
    /** Neuanlage aus einer Vorlage („Als neu verwenden“): deren Shop-Angaben vorbelegen */
    templatePartsId: { type: [String, Number], default: null },
    /** Vorschlag für die Produktseite, solange keine eingetragen ist */
    suggestedLink: { type: String, default: '' },
    /** Artikelnummer bzw. die nächste freie — Seite ohne Kennung, Anhang an einen vergebenen Vorschlag */
    partnumber: { type: String, default: '' },
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
    /** „Veraltet“ aus der Maske — dann ist der Artikel in keinem Kanal verfügbar */
    obsolete: { type: Boolean, default: false },
})

const { t, te, locale } = useI18n()
const oserp = oserpStore()
const shop = useShop()

const darfBearbeiten = computed(() =>
    oserp.checkPermission('shop_part_edit') || oserp.checkPermission('edit_shop_config'))

/** Ansicht „Verkaufskanäle“ verlangt edit_shop_config — ohne das Recht kein Bearbeiten-Symbol */
const darfKanaele = computed(() => oserp.checkPermission('edit_shop_config'))

const router = useRouter()

/**
 * Öffnet die Einstellungen des Kanals in einem neuen Tab — der Artikel
 * kann ungespeicherte Änderungen haben, die beim Verlassen verloren gingen.
 */
function kanalEinstellungen(kanal) {
    const ziel = router.resolve({ name: 'shop-channels', query: { channel: kanal.channel_id } })
    window.open(ziel.href, '_blank')
}

/**
 * Sortiment in einem neuen Reiter: die Karte kann ungespeicherte Änderungen
 * haben, die beim Verlassen verloren gingen. Kehrt der Benutzer zurück, lädt
 * die Karte die Auswahl neu (kategorienAuffrischen).
 */
function sortimentOeffnen() {
    window.open(router.resolve({ name: 'shop-assortment' }).href, '_blank')
}

/**
 * Lädt die Auswahlliste der Kategorien nach, wenn dieser Reiter wieder
 * sichtbar wird — eine im Sortiment (anderer Reiter) angelegte oder
 * umbenannte Kategorie steht sonst erst nach dem Neuladen der Seite zur Wahl.
 *
 * Nur die Liste, nicht die Karte: deren ungespeicherte Eingaben bleiben. Die
 * Reihenfolge ist die von getPartShopData (Gruppe nach Reihenfolge und Name,
 * ohne Gruppe zuletzt, darin nach Namen). Scheitert der Abruf, bleibt die
 * bisherige Liste — ein Hinweis darauf brächte nichts.
 */
async function kategorienAuffrischen() {
    if (document.visibilityState !== 'visible' || !darfBearbeiten.value) return
    try {
        const antwort = await axios.post('/api/shop/', { action: 'getShopAssortment' })
        if (!antwort.data?.success) return
        const { groups = [], categories = [] } = antwort.data.payload || {}
        const rang = new Map(groups.map((g, i) => [Number(g.id), { i, name: g.name }]))
        const ohne = groups.length
        kategorien.value = categories
            .map(k => ({ id: Number(k.id), name: k.name, group: rang.get(Number(k.group_id))?.name || null,
                         _rang: rang.get(Number(k.group_id))?.i ?? ohne }))
            .sort((a, b) => a._rang - b._rang || a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }))
    } catch {
        // bisherige Liste behalten
    }
}

/** Kategorien des Sortiments, kommen mit getPartShopData */
const kategorien = ref([])
const kategorieAuswahl = computed(() => kategorien.value.map(k => ({
    value: Number(k.id),
    title: k.name,
    group: k.group || t('ShopView.assortment.noGroup'),
})))

function leer() {
    return {
        listed: false,
        category_id: null,
        hyperlink: '',
        breadcrumbs: [],
        images: [],
        technical_data: [],
        properties: [],
        downloads: [],
        channels: [],
        shipping: leererVersand(),
    }
}

const WAHR_WERTE = [true, 't', 'true', 1, '1']

/** Versandangaben (parts_shipping_shop); leer = nicht gepflegt */
function leererVersand() {
    return { shipping_method_id: null, length: '', width: '', height: '', min_qty: '', delivery_term_id: null }
}
const zahlText = wert => (wert === null || wert === undefined || wert === '' ? '' : String(Number(wert)))
const zahlOderNull = wert => (wert === null || wert === undefined || String(wert).trim() === '' ? null : Number(wert))
const groesserNull = wert => zahlOderNull(wert) === null || zahlOderNull(wert) > 0 || t('ShopView.partCard.greaterZero')
const versandGueltig = () => ['length', 'width', 'height', 'min_qty']
    .every(feld => groesserNull(daten.value.shipping?.[feld]) === true)

/** Versandart, der dieser Artikel als Versandartikel gehört — leer = keiner */
const versandartikelVon = ref('')

/** Auswahllisten und Gewicht, kommen mit getPartShopData */
const versandarten = ref([])
const lieferbedingungen = ref([])
const gewichtseinheit = ref('')
const gewicht = ref(null)

const versandartAuswahl = computed(() => [
    { value: null, title: t('ShopView.partCard.shippingMethodCheapest') },
    ...versandarten.value
        .filter(m => m.active || m.id === daten.value.shipping.shipping_method_id)
        .map(m => ({ value: Number(m.id), title: m.active ? m.description : `${m.description} (${t('ShopView.partCard.inactive')})` })),
])
/** Ungültige Lieferbedingungen nur, wenn sie gerade gewählt ist */
const lieferbedingungAuswahl = computed(() => lieferbedingungen.value
    .filter(d => !d.obsolete || d.id === daten.value.shipping.delivery_term_id)
    .map(d => ({ value: Number(d.id), title: d.description })))
const lieferbedingungText = computed(() =>
    lieferbedingungen.value.find(d => d.id === daten.value.shipping.delivery_term_id)?.description_long || '')

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
        name: String(zeile.name || ''),
        products_link: String(zeile.products_link || ''),
        thumbnails_link: String(zeile.thumbnails_link || ''),
        active: zeile.active === true || zeile.active === 't',
        markup_mode: zeile.markup_type || 'default',
        markup_value: zeile.markup_value == null ? null : Number(zeile.markup_value),
        title: zeile.title || '',
        description: zeile.description || '',
        unavailable: zeile.unavailable === true || zeile.unavailable === 't',
        // kanaleigene Angaben (KANAL_FELDER) und, nur zum Lesen, der Stand beim Kanal
        settings: lies(zeile.settings) && typeof lies(zeile.settings) === 'object' ? { ...lies(zeile.settings) } : {},
        sync_status: zeile.sync_status || '',
        sync_error: zeile.sync_error || '',
        // HugoShop: warum keine Versandart passt, leer = passt (nur zum Lesen)
        shipping_message: zeile.shipping_message || '',
        // HugoShop: Versandart und Preis je Zone (nur zum Lesen)
        shipping_prices: Array.isArray(lies(zeile.shipping_prices)) ? lies(zeile.shipping_prices) : [],
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
const KLEINANZEIGEN_PREISARTEN = ['fixed', 'negotiable', 'giveaway']
const KLEINANZEIGEN_VERSAND = ['pickup', 'shipping', 'both']
const KLEINANZEIGEN_ZUSTAENDE = ['new', 'like_new', 'very_good', 'good', 'ok', 'defective']
const KANAL_FELDER = {
    ebay: [
        { name: 'category_id', label: 'ShopView.partCard.ebayCategory', hint: 'ShopView.partCard.channelDefaultHint' },
        { name: 'condition', label: 'ShopView.partCard.ebayCondition', items: EBAY_ZUSTAENDE, prefix: 'ShopView.ebayCondition.' },
    ],
    // Kleinanzeigen (dev/shop-kleinanzeigen.md): Preis in ganzen Euro, leer = Kanalpreis
    kleinanzeigen: [
        { name: 'price', label: 'ShopView.kleinanzeigen.ad.price', hint: 'ShopView.kleinanzeigen.ad.priceCardHint' },
        { name: 'price_type', label: 'ShopView.kleinanzeigen.ad.priceType', items: KLEINANZEIGEN_PREISARTEN, prefix: 'ShopView.kleinanzeigen.priceType.' },
        { name: 'shipping', label: 'ShopView.kleinanzeigen.ad.shipping', items: KLEINANZEIGEN_VERSAND, prefix: 'ShopView.kleinanzeigen.shipping.' },
        { name: 'condition', label: 'ShopView.kleinanzeigen.ad.condition', items: KLEINANZEIGEN_ZUSTAENDE, prefix: 'ShopView.kleinanzeigen.condition.' },
        { name: 'category', label: 'ShopView.kleinanzeigen.ad.category', hint: 'ShopView.partCard.channelDefaultHint', source: 'kleinanzeigenCategories' },
    ],
}
const kanalFelder = kanal => KANAL_FELDER[kanal.type] || []
/** Auswahllisten der Kanalfelder (source), etwa der Kategoriebaum von Kleinanzeigen — einmal je Karte geladen */
const quellen = ref({})
async function quellenLaden(kanaele) {
    if (quellen.value.kleinanzeigenCategories || !kanaele.some(k => k.type === 'kleinanzeigen')) return
    const antwort = await shop.fetchKleinanzeigenCategories()
    quellen.value = { ...quellen.value, kleinanzeigenCategories: (antwort?.categories || []).filter(k => k.is_leaf) }
}
const feldAuswahl = feld => [
    { value: null, title: t('ShopView.partCard.channelDefault') },
    ...feld.items.map(wert => ({ value: wert, title: t(feld.prefix + wert) })),
]

/** Dienstleistungen gehen nicht an Marktplätze (V19) — außer, sie sind dort schon gewählt */
const gesperrt = kanal => kanal.type !== 'hugoshop' && props.partType === 'service' && !kanal.active

// ── Bilder der Marktplätze ──

const bildLaedt = ref(false)

/**
 * Kanäle, aus denen ein Marktplatz Bilder übernehmen kann: die anderen
 * gewählten. In einen HugoShop überträgt OSERP keine Bilder — sie liegen bei
 * HugoCMS auf dem Webserver.
 */
const bildQuellen = kanal => aktiveKanaele.value.filter(k => k.channel_id !== kanal.channel_id
    && !(k.type === 'hugoshop' && kanal.type === 'hugoshop')
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
        const antwort = await shop.uploadChannelImage(Number(props.partsId), kanal.channel_id, datei.name, inhalt)
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
    bilderSetzen(kanal, await shop.sortChannelImages(Number(props.partsId), kanal.channel_id, ids))
    bildLaedt.value = false
    if (shop.error.value) toasts.error(shop.error.value)
}

/**
 * Übernimmt Bilder aus einem anderen Kanal und lädt danach die Bilderlisten
 * neu — beim HugoShop ändert sich parts_ext.hugoshop_images
 *
 * @param {object} von Quellkanal
 * @param {object} nach Zielkanal
 */
async function bilderUebernehmen(von, nach) {
    bildLaedt.value = true
    const ergebnis = await shop.copyChannelImages(Number(props.partsId), von.channel_id, nach.channel_id)
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

/** Name des Kanals (dev/shop-mehrere-kanaele.md), sonst die Bezeichnung seiner Art */
const kanalName = kanal => kanal.name
    || (te(`ShopView.channels.${kanal.type}`) ? t(`ShopView.channels.${kanal.type}`) : kanal.type)

const aktiveKanaele = computed(() => daten.value.channels.filter(k => k.active))
const hugoshopAktiv = computed(() => aktiveKanaele.value.some(k => k.type === 'hugoshop'))

/** Aufgeklappte Kanäle (channel_id); beim Laden alle zu (alleKanaeleZu) */
const offeneKanaele = ref([])

/** Beim Öffnen der Artikelmaske stehen alle Kanäle eingeklappt da */
function alleKanaeleZu() {
    offeneKanaele.value = []
}

/**
 * Häkchen eines Kanals: zuordnen klappt das Panel auf, abwählen klappt es zu
 * — ohne Zuordnung gibt es dort nichts zu bearbeiten
 */
function kanalSetzen(kanal, an) {
    kanal.active = !!an
    offeneKanaele.value = an
        ? [...new Set([...offeneKanaele.value, kanal.channel_id])]
        : offeneKanaele.value.filter(id => id !== kanal.channel_id)
}

/**
 * Der letzte zugeordnete Kanal lässt sich nicht abwählen — außer bei der
 * Neuanlage mit Auswahl (dort darf es leer sein, dann wird nicht angelegt).
 * Ganz aus dem Shop nimmt den Artikel der Schalter „Im Shop anbieten".
 */
const letzterKanal = kanal => kanal.active && aktiveKanaele.value.length === 1 && !auswahlNoetig.value

/**
 * Neuanlage mit mehr als einem wählbaren Kanal: keine Vorgabe — der Benutzer
 * wählt selbst. Mit nur einem wählbaren bleibt es bei der Vorgabe.
 */
const auswahlNoetig = computed(() =>
    !props.partsId && daten.value.channels.filter(k => !gesperrt(k)).length > 1)

/** Im Shop anbieten, aber noch kein Kanal gewählt — die Artikelmaske legt dann nicht an */
const kanalFehlt = computed(() =>
    auswahlNoetig.value && daten.value.listed && !aktiveKanaele.value.length)

/** Wer „Im Shop anbieten" einschaltet, bietet mindestens im ersten Kanal an (HugoShop) */
function mindestensEinKanal() {
    const kanaele = daten.value.channels
    if (!kanaele.length || kanaele.some(k => k.active) || auswahlNoetig.value) return
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

/** Stückzahl der Preisanzeige: Mindestabnahme, sonst 1 */
const mindestmenge = computed(() => Number(daten.value.shipping?.min_qty) > 0 ? Number(daten.value.shipping.min_qty) : 1)

/** Hat eine Zeile des Kanals Kosten? Dann zeigt die Tabelle Kosten und Differenz */
const mitKosten = kanal => kanal.shipping_prices.some(z => z.cost !== null && z.cost !== undefined)

/**
 * Was der Versand einbringt oder kostet, netto: Kundenpreis (frei = 0) minus
 * Kosten. null, wenn es keinen Preis oder keine Kosten gibt.
 */
function differenz(zeile) {
    if (zeile.status !== 'ok' || zeile.cost === null || zeile.cost === undefined) return null
    const preis = WAHR_WERTE.includes(zeile.free) ? 0 : Number(zeile.price_net ?? 0)
    return rund2(preis - Number(zeile.cost))
}

/** Warum es für eine Zone keinen Versandpreis gibt */
function versandStatusText(status) {
    const schluessel = `ShopView.partCard.shippingStatus.${status}`
    return te(schluessel) ? t(schluessel) : status
}

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
        category_id: d.category_id || 0,
        hyperlink: d.hyperlink.trim(),
        breadcrumbs: ohneLeere(d.breadcrumbs),
        images: ohneLeere(d.images),
        technical_data: zuObjekt(d.technical_data),
        properties: zuObjekt(d.properties),
        downloads: zuObjekt(d.downloads),
        shipping: {
            shipping_method_id: d.shipping.shipping_method_id || null,
            length: zahlOderNull(d.shipping.length),
            width: zahlOderNull(d.shipping.width),
            height: zahlOderNull(d.shipping.height),
            min_qty: zahlOderNull(d.shipping.min_qty),
            delivery_term_id: d.shipping.delivery_term_id || null,
        },
        channels: d.channels.map(k => ({
            channel_id: k.channel_id,
            active: k.active,
            markup_type: k.markup_mode === 'default' ? null : k.markup_mode,
            markup_value: mitWert(k.markup_mode) ? Number(k.markup_value) || 0 : null,
            title: k.title.trim(),
            description: k.description.trim(),
            unavailable: !!k.unavailable,
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

// ── Links auf die Shop-Webseite (Muster mit %s aus den Einstellungen des HugoShops) ──

function formatLink(muster, wert) {
    return muster && muster.includes('%s') ? muster.replace('%s', wert) : ''
}

/**
 * HugoShop, dessen Webseite die Links meinen: der erste, in dem der Artikel
 * angeboten wird, sonst der erste überhaupt (dev/shop-mehrere-kanaele.md)
 */
const linkKanal = computed(() => aktiveKanaele.value.find(k => k.type === 'hugoshop')
    || daten.value.channels.find(k => k.type === 'hugoshop') || null)

const shopLink = computed(() => {
    const ziel = daten.value.hyperlink.trim() || props.partnumber
    if (!props.partsId || !ziel) return ''
    return formatLink(linkKanal.value?.products_link || '', ziel.toLowerCase())
})

// ── Produktseite: Vorschlag und Prüfung auf Doppelte (checkShopPageName) ──
// Bei der Neuanlage wird der Vorschlag der Maske (Beschreibung ohne
// Artikelnummer) als Wert eingetragen und folgt der Beschreibung, bis der
// Benutzer das Feld anfasst. Belegt ein anderer Artikel den Namen, bekommt
// er die Artikelnummer angehängt. Bestehende Artikel behalten ihren Stand:
// leer heißt Seite nach Artikelnummer, der Knopf im Feld übernimmt den
// Vorschlag bewusst — eine neue Kennung ist eine neue Adresse der Seite.
const hyperlinkBeruehrt = ref(false)
const seiteBelegt = ref(null)   // { id, partnumber, description } des anderen Artikels
let pruefTimer = null
let pruefLauf = 0

const vorschlagMoeglich = computed(() =>
    darfBearbeiten.value && !!props.partsId && !daten.value.hyperlink.trim() && !!props.suggestedLink)

function hyperlinkGetippt() {
    hyperlinkBeruehrt.value = true
    clearTimeout(pruefTimer)
    pruefTimer = setTimeout(() => seitePruefen(), 600)
}

/** Belegt ein anderer Artikel diese Produktseite? Dessen Nummer und Beschreibung, sonst null */
async function seiteBesitzer(name) {
    const resp = await axios.post('/api/shop/', {
        action: 'checkShopPageName', parts_id: Number(props.partsId) || 0, hyperlink: name,
    })
    return resp.data?.success ? (resp.data.payload?.owner || null) : null
}

/** Prüfung nach der Eingabe; eine spätere Eingabe macht die laufende Antwort ungültig */
async function seitePruefen() {
    clearTimeout(pruefTimer)
    const name = daten.value.hyperlink.trim()
    const lauf = ++pruefLauf
    if (!name) { seiteBelegt.value = null; return }
    try {
        const besitzer = await seiteBesitzer(name)
        if (lauf === pruefLauf) seiteBelegt.value = besitzer
    } catch {
        // Prüfung gerade nicht möglich — das Speichern prüft noch einmal
    }
}

/** Vorschlag eintragen; ist er vergeben, mit angehängter Artikelnummer */
async function vorschlagAnwenden() {
    const vorschlag = props.suggestedLink
    if (!vorschlag) return
    const lauf = ++pruefLauf
    let name = vorschlag
    try {
        if (await seiteBesitzer(name) && props.partnumber) name = `${vorschlag}-${shopSlug(props.partnumber)}`
    } catch {
        // ohne Prüfung bleibt der einfache Vorschlag
    }
    if (lauf !== pruefLauf) return
    daten.value.hyperlink = name
    seitePruefen()
}

function vorschlagUebernehmen() {
    hyperlinkBeruehrt.value = true
    vorschlagAnwenden()
}

// Neuanlage: der Vorschlag folgt der Beschreibung, solange das Feld unberührt ist
watch(() => props.suggestedLink, () => {
    if (props.partsId || hyperlinkBeruehrt.value) return
    clearTimeout(pruefTimer)
    if (!props.suggestedLink) { daten.value.hyperlink = ''; seiteBelegt.value = null; return }
    pruefTimer = setTimeout(() => vorschlagAnwenden(), 600)
})

// Bilder und Downloads pflegt ShopPartMedia (dev/shop-medienbibliothek.md)
// direkt an daten.images und daten.downloads; gespeichert wird mit der Karte

// ── Laden und Speichern ──

async function laden() {
    bereit = false
    fehler.value = ''
    hyperlinkBeruehrt.value = false
    seiteBelegt.value = null

    // Auch bei der Neuanlage: die Kanäle und Steuersätze kommen mit; mit
    // Vorlage ihre Angaben
    const quelle = props.partsId || props.templatePartsId
    laedt.value = true
    const antwort = await shop.fetchPartShopData(quelle ? Number(quelle) : 0)
    laedt.value = false

    if (shop.error.value) {
        fehler.value = fehlerText()
        return
    }

    const kanaele = antwort?.channels || []
    quellenLaden(kanaele)
    vorgaben.value = Object.fromEntries(kanaele.map(k => [Number(k.channel_id), alsVorgabe(k)]))
    steuersaetze.value = antwort?.tax_rates || {}
    const versand = antwort?.shipping || {}
    versandarten.value = (versand.methods || []).map(m => ({ ...m, id: Number(m.id), active: WAHR_WERTE.includes(m.active) }))
    lieferbedingungen.value = (versand.delivery_terms || []).map(d => ({ ...d, id: Number(d.id), obsolete: WAHR_WERTE.includes(d.obsolete) }))
    gewichtseinheit.value = versand.weightunit || ''
    kategorien.value = antwort?.categories || []
    gewicht.value = antwort?.part?.weight === null || antwort?.part?.weight === undefined ? null : Number(antwort.part.weight)
    versandartikelVon.value = antwort?.part?.shipping_method_of || ''

    if (!quelle) {
        // Neuanlage bei aktivem Shop: der Artikel ist in der Regel für den Shop
        // gedacht. Vorhandene Artikel zeigen dagegen den gespeicherten Stand.
        daten.value = { ...leer(), listed: true, channels: kanaele.map(alsKanal) }
        mindestensEinKanal()
        alleKanaeleZu()
        return
    }

    const zeile = antwort?.part
    daten.value = {
        listed: zeile?.listed === true || zeile?.listed === 't',
        category_id: zeile?.hugoshop_category_id ? Number(zeile.hugoshop_category_id) : null,
        hyperlink: zeile?.hugoshop_hyperlink || '',
        breadcrumbs: alsListe(lies(zeile?.hugoshop_breadcrumbs)),
        images: alsListe(lies(zeile?.hugoshop_images)),
        technical_data: zuPaaren(lies(zeile?.hugoshop_technical_data)),
        properties: zuPaaren(lies(zeile?.hugoshop_properties)),
        downloads: zuPaaren(lies(zeile?.hugoshop_downloads)),
        channels: kanaele.map(alsKanal),
        shipping: versand.part ? {
            shipping_method_id: versand.part.shipping_method_id ? Number(versand.part.shipping_method_id) : null,
            length: zahlText(versand.part.length),
            width: zahlText(versand.part.width),
            height: zahlText(versand.part.height),
            min_qty: zahlText(versand.part.min_qty),
            delivery_term_id: versand.part.delivery_term_id ? Number(versand.part.delivery_term_id) : null,
        } : leererVersand(),
    }
    alleKanaeleZu()

    if (!props.partsId) {
        // Vorlage: der Stand beim Kanal (Abgleich, Kennung, Bilder) gehört ihr;
        // die Bilder kommen beim Anlegen mit, bis dahin zählt die Karte sie nur
        versandartikelVon.value = ''
        // auch ihre Produktseite: der neue Artikel bekommt den Vorschlag der Maske
        daten.value.hyperlink = ''
        vorschlagAnwenden()
        for (const kanal of daten.value.channels) {
            Object.assign(kanal, { sync_status: '', sync_error: '', external_id: '', shipping_message: '', shipping_prices: [] })
            kanal.template_images = kanal.images.length
            kanal.images = []
        }
        return
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
    // Ungültige Versandangaben: nicht senden, das Feld zeigt den Fehler
    if (!versandGueltig()) return

    const neuerStand = stand(partsId)
    if (neuerStand === zuletzt) return
    const liste = daten.value.listed
    const nutz = liste ? nutzdaten(partsId) : null

    speichert.value = true
    fehler.value = ''
    if (liste) {
        const ergebnis = await shop.savePartShopData(nutz)
        versandMeldungen(ergebnis?.shipping_messages)
    } else {
        await shop.deletePartShopData(Number(partsId))
    }
    speichert.value = false

    if (shop.error.value) {
        fehler.value = fehlerText()
        if (shop.errorCode.value === 'PAGE_NAME_TAKEN') seiteBelegt.value = shop.errorPayload.value || null
    } else {
        zuletzt = neuerStand
    }
}

/**
 * Übernimmt die Prüfung der Versandart nach dem Speichern
 *
 * Gewicht, Maße und Versandart ändern, ob eine Versandart passt — die Antwort
 * von savePartShopData sagt es je HugoShop. Nur ein Anzeigewert: der Watcher
 * auf daten sieht die Änderung, stand() aber nicht.
 */
function versandMeldungen(meldungen) {
    if (!Array.isArray(meldungen)) return
    for (const meldung of meldungen) {
        const kanal = daten.value.channels.find(k => k.channel_id === Number(meldung.channel_id))
        if (kanal) {
            kanal.shipping_message = meldung.message || ''
            kanal.shipping_prices = Array.isArray(meldung.prices) ? meldung.prices : []
        }
    }
}

watch(daten, () => {
    if (!bereit || !props.partsId) return
    const partsId = props.partsId
    clearTimeout(timer)
    timer = setTimeout(() => { timer = null; speichern(partsId) }, 800)
}, { deep: true })

// Wechsel des Artikels (auch Neuanlage → angelegter Artikel) oder der Vorlage:
// Ausstehendes gehört noch zum bisherigen. Ein Watcher für beides, sonst lüde
// der Wechsel Bearbeiten → „Als neu verwenden“ zweimal.
watch(() => [props.partsId, props.templatePartsId], (neu, [alt]) => {
    if (timer && alt) {
        clearTimeout(timer)
        timer = null
        speichern(alt)
    }
    laden()
})

onMounted(() => {
    laden()
    document.addEventListener('visibilitychange', kategorienAuffrischen)
})

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', kategorienAuffrischen)
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
 * @param {number|string} partsId der neue Artikel
 * @param {number|string|null} templatePartsId Vorlage, deren Kanalbilder mitkommen
 * @returns {Promise<boolean>} false, wenn das Speichern scheiterte
 */
async function saveFor(partsId, templatePartsId = null) {
    if (!daten.value.listed || !darfBearbeiten.value) return true
    await shop.savePartShopData({
        ...nutzdaten(partsId),
        ...(templatePartsId ? { template_parts_id: Number(templatePartsId) } : {}),
    })
    if (shop.error.value) {
        toasts.error(fehlerText())
        return false
    }
    return true
}

/**
 * Abschnitte der Karte für die Sprungmarken der Artikelmaske — nur, was
 * gerade zu sehen ist (ohne „Im Shop anbieten“ nur die Karte selbst)
 */
const abschnitte = computed(() => [
    { id: 'abschnitt-shop', titel: t('ShopView.partCard.title'), icon: 'mdi-storefront' },
    ...(daten.value.listed ? [
        { id: 'abschnitt-shop-angaben', titel: t('ShopView.partCard.sectionPage'), icon: 'mdi-web' },
        { id: 'abschnitt-shop-bilder', titel: t('ShopView.partCard.images'), icon: 'mdi-image-multiple-outline' },
        { id: 'abschnitt-shop-technik', titel: t('ShopView.partCard.technicalData'), icon: 'mdi-format-list-bulleted' },
        { id: 'abschnitt-shop-versand', titel: t('ShopView.partCard.shipping'), icon: 'mdi-truck-delivery-outline' },
        ...(daten.value.channels.length
            ? [{ id: 'abschnitt-shop-kanaele', titel: t('ShopView.partCard.channels'), icon: 'mdi-store-cog' }]
            : []),
    ] : []),
])

defineExpose({ saveFor, kanalFehlt, abschnitte })
</script>

<style scoped>
/* Sprungmarken der Artikelmaske: Abstand zur festen Kopfleiste */
.sprungziel {
    scroll-margin-top: 72px;
}
</style>
