<!-- src/core/views/faktura/components/faktura.items.table.component.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-format-list-bulleted</v-icon>
            <span class="text-subtitle-1 font-weight-medium">{{ t('FakturaView.faktura.items') }}</span>
            <v-chip v-if="modelValue.length > 1" size="x-small" variant="tonal" color="primary" class="ml-2">{{ modelValue.length - 1 }}</v-chip>
            <v-spacer />
            <v-btn
                v-if="selectedItems.size > 0"
                variant="tonal"
                color="error"
                size="small"
                prepend-icon="mdi-delete-sweep"
                class="mr-2"
                @click="emitDeleteSelected"
            >
                {{ t('FakturaView.dialogs.deleteBulk.button', { count: selectedItems.size }) }}
            </v-btn>
            <v-tooltip v-if="showAiSuggest" location="bottom" :text="t('FakturaView.faktura.aiSuggestPositions')">
                <template #activator="{ props: tip }">
                    <v-btn v-bind="tip" variant="tonal" color="purple" icon="mdi-creation"
                           size="small" :loading="aiLoading" @click="$emit('ai-suggest')" />
                </template>
            </v-tooltip>
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__table-body">
            <v-table density="compact" class="invoice-items-table">
                <thead>
                    <tr>
                        <th style="width: 36px; text-align: center">
                            <v-checkbox-btn
                                :model-value="allRealItemsSelected"
                                :indeterminate="someSelected"
                                density="compact"
                                hide-details
                                @click="toggleSelectAll"
                            />
                        </th>
                        <th style="width: 50px; text-align: center">{{ t('FakturaView.faktura.position') }}</th>
                        <th style="width: 36px; text-align: center">
                            <v-icon size="small" color="grey" :title="t('FakturaView.faktura.tooltipDrag')">mdi-drag-vertical</v-icon>
                        </th>
                        <th style="width: 36px; text-align: center">
                            <v-icon size="small" color="grey" :title="t('FakturaView.faktura.tooltipEdit')">mdi-pencil</v-icon>
                        </th>
                        <th style="width: 130px">{{ t('FakturaView.faktura.partNumber') }}</th>
                        <th style="width: 90px">{{ t('FakturaView.faktura.type') }}</th>
                        <th style="min-width: 220px">{{ t('FakturaView.faktura.description') }}</th>
                        <th style="width: 36px; text-align: center">
                            <v-icon size="small" color="grey" :title="t('FakturaView.faktura.longDescription')">mdi-text-box-outline</v-icon>
                        </th>
                        <th style="width: 100px; text-align: right">{{ t('FakturaView.faktura.quantity') }}</th>
                        <th style="width: 80px">{{ t('FakturaView.faktura.unit') }}</th>
                        <th style="width: 120px; text-align: right">{{ t('FakturaView.faktura.price') }}</th>
                        <th style="width: 90px; text-align: right">{{ t('FakturaView.faktura.discount') }}</th>
                        <th style="width: 50px; text-align: center">
                            <v-btn
                                size="x-small"
                                variant="tonal"
                                color="primary"
                                :title="t('FakturaView.faktura.tooltipToggleDiscount')"
                                @click="toggleAllDiscounts"
                            >
                                {{ allDiscountsButtonLabel }}
                            </v-btn>
                        </th>
                        <th style="width: 130px; text-align: right">{{ t('FakturaView.faktura.total') }}</th>
                        <th v-if="showPartsRequests" style="width: 220px; text-align: center">
                            <v-icon size="small" color="grey">mdi-cart</v-icon>
                        </th>
                        <th style="width: 36px; text-align: center">
                            <v-icon size="small" color="grey" :title="t('FakturaView.faktura.tooltipDelete')">mdi-delete</v-icon>
                        </th>
                    </tr>
                </thead>
                <draggable
                    :list="modelValue"
                    tag="tbody"
                    :item-key="getItemKey"
                    handle=".drag-handle"
                    :move="canMoveItem"
                    @change="onDragChange"
                >
                    <template #item="{ element: item, index }">
                        <tr :class="[
                            { 'new-item-row': !item.parts_id },
                            { 'selected-item-row': item.parts_id && selectedItems.has(item.id || item.tempId) },
                            getItemOrderClass(item)
                        ]">
                            <td class="text-center">
                                <v-checkbox-btn
                                    v-if="item.parts_id"
                                    :model-value="selectedItems.has(item.id || item.tempId)"
                                    density="compact"
                                    hide-details
                                    @click.stop="toggleItemSelection(item)"
                                />
                            </td>
                            <td class="text-center" :class="{ 'field-disabled': !item.parts_id }">
                                {{ item.position }}
                            </td>
                            <td class="text-center">
                                <v-icon
                                    :class="['drag-handle', { 'drag-disabled': !item.parts_id }]"
                                    :style="item.parts_id ? 'cursor: move' : 'cursor: not-allowed'"
                                    size="small"
                                >
                                    mdi-drag-vertical
                                </v-icon>
                            </td>
                            <td class="text-center">
                                <v-btn
                                    :disabled="!item.parts_id"
                                    :class="{ 'btn-disabled': !item.parts_id }"
                                    icon="mdi-pencil"
                                    size="x-small"
                                    variant="text"
                                    color="primary"
                                    @click="$emit('edit-article', item)"
                                />
                            </td>
                            <td>
                                <v-text-field
                                    :model-value="item.partnumber || ''"
                                    :disabled="!item.parts_id"
                                    :class="{ 'field-disabled': !item.parts_id }"
                                    readonly
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                />
                            </td>
                            <td>
                                <v-text-field
                                    :model-value="formatPartType(item.part_type, item.classification_id)"
                                    :disabled="!item.parts_id"
                                    :class="{ 'field-disabled': !item.parts_id }"
                                    readonly
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                />
                            </td>
                            <td>
                                <!-- Artikelsuche nur in leerer Zeile -->
                                <v-autocomplete
                                    v-if="!item.parts_id"
                                    :ref="el => { if (el) autocompleteRefs[index] = el }"
                                    :items="item.localArticleList || []"
                                    :loading="false"
                                    :placeholder="t('FakturaView.faktura.searchArticle')"
                                    :no-data-text="null"
                                    :hide-no-data="true"
                                    :no-filter="true"
                                    :custom-filter="() => true"
                                    class="new-item-input"
                                    item-title="label"
                                    item-value="id"
                                    return-object
                                    menu-icon=""
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="one-time-code"
                                    @update:search="onLocalArticleSearch(item, index, $event)"
                                    @update:model-value="onLocalArticleSelect(item, index, $event)"
                                    @keydown.enter="onArticleEnter(item, index, $event)"
                                >
                                    <!-- Mikrofon IM Feld — Feld-/Dropdown-Breite bleibt
                                         identisch. Diktat sucht bestehende Artikel (Vorrang),
                                         legt nie neue an. -->
                                    <template #append-inner>
                                        <VoiceInputButton
                                            density="compact"
                                            color="grey-darken-1"
                                            @transcript="text => onVoicePosition(item, index, text)"
                                        />
                                    </template>
                                    <template #item="{ props, item: listItem }">
                                        <v-list-item
                                            v-bind="props"
                                            :title="listItem.raw.description"
                                            :subtitle="listItem.raw.partnumber"
                                        />
                                    </template>
                                </v-autocomplete>
                                <!-- Bestehende Position: zuverlässiges Textfeld + EIGENE
                                     Vorschlagsliste (kein v-menu/v-autocomplete – die fangen
                                     Pfeiltasten/Fokus selbst ab). Per Teleport an body, damit
                                     die Tabelle die Liste nicht abschneidet. -->
                                <div v-else class="article-replace-wrap" :ref="el => { if (el) wrapRefs[index] = el }">
                                    <v-text-field
                                        v-model="item.description"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        autocomplete="off"
                                        @update:model-value="onExistingDescInput(item, index, $event)"
                                        @blur="onExistingDescBlur(item, index)"
                                        @keydown.down="moveReplaceHighlight(item, index, 1, $event)"
                                        @keydown.up="moveReplaceHighlight(item, index, -1, $event)"
                                        @keydown.enter="onExistingEnter(item, index, $event)"
                                        @keydown.esc.stop.prevent="replaceMenu[index] = false"
                                    />
                                    <Teleport to="body">
                                        <div
                                            v-if="replaceMenu[index] && (item.localArticleList || []).length"
                                            class="ac-suggestions"
                                            :style="suggestionStyle(index)"
                                        >
                                            <div
                                                v-for="(art, ai) in item.localArticleList"
                                                :key="art.id"
                                                class="ac-suggestion"
                                                :class="{ 'ac-active': replaceActiveIdx[index] === ai }"
                                                @mousedown.prevent="chooseReplacement(item, index, art)"
                                                @mousemove="replaceActiveIdx[index] = ai"
                                            >
                                                <div class="ac-suggestion__title">{{ art.description }}</div>
                                                <div class="ac-suggestion__sub">{{ art.partnumber }}</div>
                                            </div>
                                        </div>
                                    </Teleport>
                                </div>
                            </td>
                            <td class="text-center">
                                <v-btn
                                    :icon="item.longdescription ? 'mdi-text-box' : 'mdi-text-box-outline'"
                                    :color="item.longdescription ? 'primary' : 'grey'"
                                    :disabled="!item.parts_id"
                                    :class="{ 'btn-disabled': !item.parts_id }"
                                    size="x-small"
                                    variant="text"
                                    :title="item.longdescription || t('FakturaView.faktura.noLongDescription')"
                                    @click="openLongDescriptionDialog(item, index)"
                                />
                            </td>
                            <td>
                                <v-text-field
                                    :ref="el => { if (el) qtyRefs[index] = el }"
                                    :disabled="!item.parts_id"
                                    :class="['text-right-input', { 'field-disabled': !item.parts_id }]"
                                    :model-value="getFormattedQty(item)"
                                    @update:model-value="onQtyInput(item, $event)"
                                    @blur="onQtyBlur(item)"
                                    @keydown.enter="onQtyBlur(item); handleEnter(index)"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                    @focus="$event.target.select()"
                                />
                            </td>
                            <td>
                                <v-text-field
                                    v-model="item.unit"
                                    :disabled="!item.parts_id"
                                    :class="['text-right-input', { 'field-disabled': !item.parts_id }]"
                                    readonly
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                />
                            </td>
                            <td>
                                <v-text-field
                                    :disabled="!item.parts_id"
                                    :class="['text-right-input', { 'field-disabled': !item.parts_id }]"
                                    :model-value="getFormattedPrice(item)"
                                    @update:model-value="onPriceInput(item, $event)"
                                    @blur="onPriceBlur(item)"
                                    @keydown.enter="onPriceBlur(item); handleEnter(index)"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                    @focus="$event.target.select()"
                                />
                            </td>
                            <td>
                                <v-text-field
                                    :disabled="!item.parts_id"
                                    :class="['text-right-input', { 'field-disabled': !item.parts_id }]"
                                    :model-value="getFormattedDiscount(item)"
                                    @update:model-value="onDiscountInput(item, $event)"
                                    @blur="onDiscountBlur(item)"
                                    @keydown.enter="onDiscountBlur(item); handleEnter(index)"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                    @focus="$event.target.select()"
                                />
                            </td>
                            <td>
                                <v-btn
                                    :disabled="!item.parts_id"
                                    size="small"
                                    variant="outlined"
                                    @click="toggleItemDiscount(item)"
                                >
                                    {{ getItemDiscountButtonLabel(item) }}
                                </v-btn>
                            </td>
                            <td>
                                <v-text-field
                                    :disabled="!item.parts_id"
                                    :class="['text-right-input', { 'field-disabled': !item.parts_id }]"
                                    :model-value="formatNumberDisplay(calculateItemAmount(item), 2)"
                                    readonly
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                    autocomplete="off"
                                />
                            </td>
                            <td v-if="showPartsRequests" class="pa-0 px-1">
                                <div v-if="item.parts_id && item.part_type !== 'service'" class="d-flex align-center ga-1">
                                    <!-- Kein Status: Anfordern-Button -->
                                    <v-btn
                                        v-if="!getItemOrderStatus(item)"
                                        icon
                                        size="x-small"
                                        variant="text"
                                        color="grey"
                                        :title="t('FakturaView.faktura.requestPart')"
                                        @click="$emit('request-part', item)"
                                    >
                                        <v-icon size="small">mdi-cart-plus</v-icon>
                                    </v-btn>
                                    <!-- Pending/Ordered: Lieferant-Auswahl -->
                                    <template v-if="getItemOrderStatus(item)">
                                        <v-menu
                                            v-if="getItemOrderStatus(item) === 'pending'"
                                            v-model="vendorMenuOpen[item.id]"
                                            location="bottom start"
                                            :close-on-content-click="false"
                                            offset="4"
                                            @update:model-value="val => { if (val) openVendorMenu(item) }"
                                        >
                                            <template #activator="{ props: menuProps }">
                                                <v-chip
                                                    v-bind="menuProps"
                                                    size="small"
                                                    color="red"
                                                    variant="tonal"
                                                    prepend-icon="mdi-truck-delivery"
                                                    class="parts-vendor-chip"
                                                >
                                                    {{ t('FakturaView.faktura.selectVendor') }}
                                                </v-chip>
                                            </template>
                                            <v-card min-width="300" max-width="380" class="vendor-picker-card">
                                                <v-card-title class="d-flex align-center text-body-1 py-2 px-3">
                                                    <v-icon class="mr-2" size="small">mdi-truck-delivery</v-icon>
                                                    {{ t('FakturaView.faktura.selectVendor') }}
                                                    <v-spacer />
                                                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="vendorMenuOpen[item.id] = false" />
                                                </v-card-title>
                                                <v-divider />
                                                <v-text-field
                                                    v-model="vendorSearch"
                                                    :placeholder="t('FakturaView.faktura.searchVendor')"
                                                    prepend-inner-icon="mdi-magnify"
                                                    density="compact"
                                                    variant="outlined"
                                                    hide-details
                                                    single-line
                                                    autofocus
                                                    class="mx-3 my-2"
                                                    @keydown.down.prevent="vendorHighlightMove(1)"
                                                    @keydown.up.prevent="vendorHighlightMove(-1)"
                                                    @keydown.enter.prevent="vendorHighlightSelect(item)"
                                                    @keydown.esc="vendorMenuOpen[item.id] = false"
                                                />
                                                <v-divider />
                                                <v-list density="comfortable" max-height="280" class="overflow-y-auto pa-0">
                                                    <v-progress-linear v-if="vendorSearching" indeterminate color="primary" height="2" />
                                                    <v-list-item
                                                        v-for="(vendor, vi) in vendorDisplayList"
                                                        :key="vendor.id"
                                                        :active="vi === vendorHighlightIdx || vendor.id === vendorCurrentId"
                                                        :color="vi === vendorHighlightIdx ? 'primary' : undefined"
                                                        :variant="vendor.id === vendorCurrentId && vi !== vendorHighlightIdx ? 'tonal' : undefined"
                                                        @click="onVendorSelect(item, vendor)"
                                                        @mouseenter="vendorHighlightIdx = vi"
                                                    >
                                                        <template #prepend>
                                                            <v-icon size="small" :color="vendor.id === vendorCurrentId ? 'primary' : 'grey-lighten-1'">
                                                                {{ vendor.id === vendorCurrentId ? 'mdi-check-circle' : 'mdi-domain' }}
                                                            </v-icon>
                                                        </template>
                                                        <v-list-item-title>{{ vendor.name }}</v-list-item-title>
                                                        <template #append>
                                                            <v-chip v-if="vendor.order_count > 0" size="x-small" color="primary" variant="tonal">
                                                                {{ vendor.order_count }}
                                                            </v-chip>
                                                        </template>
                                                    </v-list-item>
                                                    <v-list-item v-if="!vendorDisplayList.length && !vendorSearching" disabled>
                                                        <v-list-item-title class="text-caption text-medium-emphasis">{{ t('FakturaView.faktura.noVendors') }}</v-list-item-title>
                                                    </v-list-item>
                                                </v-list>
                                            </v-card>
                                        </v-menu>
                                        <!-- Bestellt: Lieferant + Zurücksetzen/Löschen -->
                                        <template v-if="getItemOrderStatus(item) === 'ordered'">
                                            <v-chip
                                                size="small"
                                                color="green"
                                                variant="tonal"
                                                prepend-icon="mdi-truck-check"
                                                class="parts-vendor-chip"
                                            >
                                                {{ getItemVendorName(item) }}
                                            </v-chip>
                                            <v-btn
                                                icon
                                                size="x-small"
                                                variant="text"
                                                color="warning"
                                                :title="t('FakturaView.faktura.revertToPending')"
                                                @click="$emit('revert-part', item)"
                                            >
                                                <v-icon size="small">mdi-undo</v-icon>
                                            </v-btn>
                                        </template>
                                        <v-btn
                                            icon
                                            size="x-small"
                                            variant="text"
                                            color="error"
                                            :title="t('FakturaView.faktura.deletePart')"
                                            @click="$emit('delete-part', item)"
                                        >
                                            <v-icon size="small">mdi-delete</v-icon>
                                        </v-btn>
                                        <!-- Foto-Button mit Anzahl-Badge -->
                                        <v-tooltip :text="getItemPhotoCount(item) ? t('FakturaView.faktura.viewPhoto') + ' (' + getItemPhotoCount(item) + ')' : t('FakturaView.faktura.addPhoto')" location="bottom">
                                            <template #activator="{ props: tip }">
                                                <v-badge
                                                    :content="getItemPhotoCount(item)"
                                                    :model-value="getItemPhotoCount(item) > 0"
                                                    color="primary"
                                                    offset-x="-2"
                                                    offset-y="-2"
                                                >
                                                    <v-btn
                                                        v-bind="tip"
                                                        icon
                                                        size="x-small"
                                                        variant="text"
                                                        :color="getItemPhotoCount(item) ? 'primary' : 'grey'"
                                                        @click="$emit('photo-part', item)"
                                                    >
                                                        <v-icon size="small">{{ getItemPhotoCount(item) ? 'mdi-image-check' : 'mdi-camera-plus' }}</v-icon>
                                                    </v-btn>
                                                </v-badge>
                                            </template>
                                        </v-tooltip>
                                    </template>
                                </div>
                            </td>
                            <td class="text-center">
                                <v-btn
                                    :disabled="!item.parts_id"
                                    :class="{ 'btn-disabled': !item.parts_id }"
                                    icon="mdi-close"
                                    size="x-small"
                                    variant="text"
                                    color="grey"
                                    @click="$emit('delete-item', index)"
                                />
                            </td>
                        </tr>
                    </template>
                </draggable>
            </v-table>

            <!-- Totals -->
            <v-row dense class="mt-4 mr-2 mb-2 justify-end">
                <v-col cols="12" md="3">
                    <v-row dense>
                        <!-- Steuer im Preis inbegriffen -->
                        <v-col cols="12" class="py-1">
                            <v-switch
                                :model-value="taxincluded"
                                @update:model-value="$emit('update:taxincluded', $event)"
                                :label="t('FakturaView.faktura.taxIncluded')"
                                color="primary"
                                density="compact"
                                hide-details
                                inset
                            />
                        </v-col>

                        <!-- Nettobetrag -->
                        <v-col cols="12" class="py-1">
                            <v-text-field
                                :model-value="formatNumberDisplay(netAmount, 2)"
                                :label="t('FakturaView.faktura.netAmount')"
                                readonly
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                class="text-right-input"
                            />
                        </v-col>

                        <!-- Steuer-Aufschlüsselung nach Steuersatz -->
                        <v-col
                            v-for="(item, index) in taxBreakdown"
                            :key="index"
                            cols="12"
                            class="py-1"
                        >
                            <v-text-field
                                :model-value="formatNumberDisplay(item.tax, 2)"
                                :label="`${t('FakturaView.faktura.tax')} ${(item.rate * 100).toFixed(0)}%`"
                                readonly
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                class="text-right-input"
                            />
                        </v-col>

                        <!-- Bruttobetrag -->
                        <v-col cols="12" class="py-1">
                            <v-text-field
                                :model-value="formatNumberDisplay(grossAmount, 2)"
                                :label="t('FakturaView.faktura.grossAmount')"
                                readonly
                                variant="outlined"
                                density="compact"
                                hide-details
                                autocomplete="off"
                                class="text-right-input"
                            />
                        </v-col>
                    </v-row>
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>

    <!-- Langtext Dialog -->
    <v-dialog
        v-model="longDescDialog.show"
        max-width="600"
        @keydown.esc="closeLongDescriptionDialog"
    >
        <v-card>
            <v-card-title class="d-flex align-center py-3 px-4 bg-grey-lighten-3">
                <v-icon class="mr-2">mdi-text-box</v-icon>
                {{ t('FakturaView.faktura.longDescription') }}
                <v-spacer />
                <VoiceInputButton
                    color="primary"
                    class="mr-1"
                    @transcript="onVoiceLongDesc"
                />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    density="compact"
                    size="small"
                    @click="closeLongDescriptionDialog"
                />
            </v-card-title>
            <v-card-text class="pt-4">
                <p v-if="longDescDialog.item" class="text-body-2 text-medium-emphasis mb-3">
                    {{ longDescDialog.item.partnumber }} - {{ longDescDialog.item.description }}
                </p>
                <v-textarea
                    v-model="longDescDialog.text"
                    :label="t('FakturaView.faktura.longDescription')"
                    variant="outlined"
                    rows="8"
                    auto-grow
                    autofocus
                    hide-details
                    autocomplete="off"
                />
            </v-card-text>
            <v-card-actions class="pa-4 pt-0">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="closeLongDescriptionDialog"
                >
                    {{ t('FakturaView.common.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="elevated"
                    prepend-icon="mdi-check"
                    @click="saveLongDescription"
                >
                    {{ t('FakturaView.common.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>


</template>

<script>
// src/core/views/faktura/components/faktura.items.table.component.vue

import { defineComponent, ref, reactive, nextTick, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatNumber, parseNumber } from '@/core/utils/numberFormat.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import draggable from 'vuedraggable'
import VoiceInputButton from '@/core/components/voice-input-button.vue'

export default defineComponent({
    name: 'FakturaItemsTableComponent',
    components: {
        draggable,
        VoiceInputButton
    },
    props: {
        modelValue: {
            type: Array,
            required: true
        },
        articleList: {
            type: Array,
            default: () => []
        },
        articleLoading: {
            type: Boolean,
            default: false
        },
        netAmount: {
            type: Number,
            default: 0
        },
        grossAmount: {
            type: Number,
            default: 0
        },
        taxBreakdown: {
            type: Array,
            default: () => []
        },
        taxincluded: {
            type: Boolean,
            default: false
        },
        calculateItemTotal: {
            type: Function,
            required: true
        },
        calculateTotals: {
            type: Function,
            required: true
        },
        showAiSuggest: {
            type: Boolean,
            default: false
        },
        aiLoading: {
            type: Boolean,
            default: false
        },
        showPartsRequests: {
            type: Boolean,
            default: false
        },
        partsRequests: {
            type: Array,
            default: () => []
        },
        recentVendors: {
            type: Array,
            default: () => []
        }
    },
    emits: [
        'update:modelValue',
        'update:taxincluded',
        'article-search',
        'article-select',
        'article-replace',
        'create-article',
        'delete-item',
        'delete-selected',
        'edit-article',
        'set-item-discount',
        'set-all-discounts',
        'add-new-row',
        'items-changed',
        'ai-suggest',
        'request-part',
        'order-part',
        'revert-part',
        'delete-part',
        'photo-part'
    ],
    setup(props, { emit, expose }) {
        const { t, locale } = useI18n()
        const oserp = oserpStore()

        const qtyRefs = ref([])
        const autocompleteRefs = ref([])
        const searchTimeouts = ref({})

        // ── Mehrfachauswahl ──
        const selectedItems = ref(new Set())

        const realItems = computed(() => props.modelValue.filter(i => i.parts_id))

        const allRealItemsSelected = computed(() =>
            realItems.value.length > 0 && realItems.value.every(i => selectedItems.value.has(i.id || i.tempId))
        )

        const someSelected = computed(() =>
            selectedItems.value.size > 0 && !allRealItemsSelected.value
        )

        function toggleItemSelection(item) {
            const key = item.id || item.tempId
            const next = new Set(selectedItems.value)
            if (next.has(key)) next.delete(key)
            else next.add(key)
            selectedItems.value = next
        }

        function toggleSelectAll() {
            if (allRealItemsSelected.value) {
                selectedItems.value = new Set()
            } else {
                selectedItems.value = new Set(realItems.value.map(i => i.id || i.tempId))
            }
        }

        function emitDeleteSelected() {
            const items = realItems.value.filter(i => selectedItems.value.has(i.id || i.tempId))
            emit('delete-selected', items)
            selectedItems.value = new Set()
        }

        // Langtext Dialog State
        const longDescDialog = ref({
            show: false,
            item: null,
            index: null,
            text: ''
        })

        // Temporäre Editing-States für Eingabefelder
        const editingQty = ref({})
        const editingPrice = ref({})
        const editingDiscount = ref({})

        const currentLocale = computed(() => locale.value)

        /**
         * Eindeutiger Key für jedes Item (auch neue ohne id)
         *
         * @param {Object} item - Das Item
         * @returns {string|number} Eindeutiger Key
         */
        function getItemKey(item) {
            return item.id || item.tempId || `temp-${item.position}`
        }

        /**
         * Prüft ob ein Item bewegt werden darf
         *
         * @param {Object} evt - Das Drag-Event
         * @returns {boolean} True wenn Item bewegt werden darf
         */
        function canMoveItem(evt) {
            const draggedItem = evt.draggedContext.element
            const relatedItem = evt.relatedContext.element

            // Leere Zeilen (ohne parts_id) dürfen nicht gezogen werden
            if (!draggedItem.parts_id) {
                return false
            }

            // Nicht vor/über leere Zeilen droppen (leere Zeile muss immer am Ende bleiben)
            if (relatedItem && !relatedItem.parts_id) {
                return false
            }

            return true
        }

        /**
         * Nach dem Drag: Positionen neu nummerieren
         *
         * @param {Object} evt - Das Change-Event
         */
        function onDragChange(evt) {
            // Nur bei moved-Events reagieren
            if (evt.moved) {
                // Positionen neu nummerieren
                props.modelValue.forEach((item, idx) => {
                    item.position = idx + 1
                })

                // Änderungen speichern
                emit('items-changed')
            }
        }

        // Computed für "Alle Rabatte"-Button Label
        const allDiscountsButtonLabel = computed(() => {
            // Prüfe ob alle Items mit ID 100% Rabatt haben
            const itemsWithId = props.modelValue.filter(item => item.id !== null)
            if (itemsWithId.length === 0) return '100%'

            const allHave100Percent = itemsWithId.every(item => item.discount === 1)
            return allHave100Percent ? '0%' : '100%'
        })

        /**
         * Toggle alle Rabatte zwischen 0% und 100%
         */
        function toggleAllDiscounts() {
            const itemsWithId = props.modelValue.filter(item => item.id !== null)
            if (itemsWithId.length === 0) return

            const allHave100Percent = itemsWithId.every(item => item.discount === 1)
            const newDiscount = allHave100Percent ? 0 : 1

            emit('set-all-discounts', newDiscount)
        }

        /**
         * Button-Label für einzelne Item-Rabatte
         *
         * @param {Object} item - Das Item
         * @returns {string} Label für den Button
         */
        function getItemDiscountButtonLabel(item) {
            return item.discount === 1 ? '0%' : '100%'
        }

        /**
         * Toggle einzelner Item-Rabatt zwischen 0% und 100%
         *
         * @param {Object} item - Das Item
         */
        function toggleItemDiscount(item) {
            const newDiscount = item.discount === 1 ? 0 : 1
            emit('set-item-discount', item, newDiscount)
        }

        /**
         * Handler für Enter-Taste - neue Zeile hinzufügen
         *
         * @param {number} index - Index des aktuellen Items
         */
        function handleEnter(index) {
            emit('add-new-row', index)
        }

        /**
         * Lokale Suche pro Zeile mit Debouncing
         *
         * @param {Object} item - Das Item
         * @param {number} index - Index des Items
         * @param {string} searchTerm - Der Suchbegriff
         */
        async function onLocalArticleSearch(item, index, searchTerm) {
            if (!searchTerm || searchTerm.length < 2) {
                item.localArticleList = []
                item.localArticleLoading = false
                return
            }

            // Vorherigen Timeout für diese Zeile löschen
            if (searchTimeouts.value[index]) {
                clearTimeout(searchTimeouts.value[index])
            }

            // Neuen Timeout für diese Zeile setzen
            searchTimeouts.value[index] = setTimeout(() => {
                item.localArticleLoading = true

                // Emitte Search Event an Parent
                emit('article-search', searchTerm, index, item)
            }, 50)
        }

        /**
         * Handler für Artikelauswahl
         *
         * @param {Object} item - Das Item
         * @param {number} index - Index des Items
         * @param {Object} selectedArticle - Der ausgewählte Artikel
         */
        function onLocalArticleSelect(item, index, selectedArticle) {
            // Setze Loading auf false für diese Zeile
            item.localArticleLoading = false

            // Leere die lokale Liste nach Auswahl
            item.localArticleList = []

            // Emitte Select Event an Parent
            emit('article-select', item, index, selectedArticle)
        }

        /**
         * Handler für Enter-Taste im Artikel-Autocomplete
         * Wählt automatisch den einzigen Artikel aus, wenn nur einer in der Liste ist
         * Öffnet Dialog zum Anlegen wenn keine Ergebnisse gefunden wurden
         *
         * @param {Object} item - Das aktuelle Item
         * @param {number} index - Index des Items
         * @param {KeyboardEvent} event - Das Keyboard-Event
         */
        function onArticleEnter(item, index, event) {
            const articleList = item.localArticleList || []
            const autocomplete = autocompleteRefs.value[index]
            const searchText = autocomplete?.search || ''

            // Wenn genau ein Artikel in der Liste ist, diesen automatisch auswählen
            if (articleList.length === 1) {
                event.preventDefault()
                event.stopPropagation()

                const selectedArticle = articleList[0]
                onLocalArticleSelect(item, index, selectedArticle)
            }
            // Wenn keine Artikel gefunden und Suchtext vorhanden, Dialog zum Anlegen öffnen
            else if (articleList.length === 0 && searchText.length >= 2) {
                event.preventDefault()
                event.stopPropagation()

                // Event an Parent emittieren um Create-Dialog zu öffnen
                emit('create-article', searchText, item, index)
            }
        }

        /**
         * Focus auf Qty-Feld setzen
         *
         * @param {number} index - Index des Items
         */
        function focusQtyField(index) {

            if (autocompleteRefs.value[index]) {

                nextTick(() => {

                    const autocompleteInput = autocompleteRefs.value[index].$el?.querySelector('input')
                    if (autocompleteInput) {
                        autocompleteInput.blur()
                    }

                    // Dann Focus auf qty setzen (mit etwas mehr Wartezeit)
                    setTimeout(() => {
                        if (qtyRefs.value[index]) {
                            const qtyInput = qtyRefs.value[index].$el?.querySelector('input')
                            if (qtyInput) {
                                qtyInput.focus()
                                qtyInput.select()
                            }
                        }
                    }, 150)
                })
            }
        }

        /**
         * Focus auf Artikel-Suchfeld setzen
         *
         * @param {number} index - Index des Items
         */
        // Diktierte Position: wie getippt behandeln — den gesprochenen Text in die
        // Artikelsuche füttern (Vorrang haben bestehende Artikel, es wird nie ein
        // neuer Artikel angelegt). Kein Satzpunkt am Ende. Über ein natives
        // input-Event, damit Vuetifys interne Suche (@update:search) anspringt.
        function onVoicePosition(item, index, text) {
            const spoken = (text || '').trim().replace(/[.!?…]+$/u, '').trim()
            if (!spoken) return
            nextTick(() => {
                const ac = autocompleteRefs.value[index]
                const input = ac?.$el?.querySelector('input')
                if (!input) return
                input.focus()
                const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set
                setter.call(input, spoken)
                input.dispatchEvent(new Event('input', { bubbles: true }))
            })
        }

        function focusArticleField(index) {
            nextTick(() => {
                setTimeout(() => {
                    if (autocompleteRefs.value[index]) {
                        const input = autocompleteRefs.value[index].$el?.querySelector('input')
                        if (input) {
                            input.focus()
                        }
                    }
                }, 50)
            })
        }

        /**
         * Methode zum Aktualisieren der lokalen Artikelliste von außen
         *
         * @param {number} index - Index des Items
         * @param {Array} articles - Liste der Artikel
         */
        function updateLocalArticleList(index, articles) {
            const item = props.modelValue[index]
            if (item) {
                item.localArticleList = articles
                item.localArticleLoading = false
                // Eigenes Ersetzen-Vorschlagsmenü (gefüllte Positionen) öffnen,
                // sobald Treffer vorliegen; schließen, wenn keine. Für leere
                // Zeilen ist dieses Menü nicht im DOM → ohne Wirkung.
                replaceMenu[index] = (articles && articles.length > 0)
                replaceActiveIdx[index] = 0 // Tastatur-Markierung auf ersten Treffer
            }
        }

        // ===== Eigenes Artikel-Ersetzen-Menü für bestehende Positionen =====
        // Bewusst KEIN v-autocomplete/v-combobox (deren Modell-Reset leerte die
        // Anzeige). Ein normales Textfeld zeigt die Beschreibung zuverlässig;
        // beim Tippen erscheinen Vorschläge in einem eigenen v-menu, ein Klick
        // ersetzt die komplette Position.
        const replaceMenu = reactive({})
        const replaceActiveIdx = reactive({}) // markierter Vorschlag je Zeile (Tastatur)
        const wrapRefs = reactive({})         // Wrapper-Element je Zeile (für Positionierung)

        // Position der Teleport-Vorschlagsliste aus dem Wrapper-Rechteck (fixed).
        function suggestionStyle(index) {
            const el = wrapRefs[index]
            if (!el || !el.getBoundingClientRect) return { display: 'none' }
            const r = el.getBoundingClientRect()
            return {
                position: 'fixed',
                top: (r.bottom + 2) + 'px',
                left: r.left + 'px',
                width: r.width + 'px',
                zIndex: 3000
            }
        }

        // Pfeiltasten: Markierung im Vorschlagsmenü bewegen (nur wenn offen).
        function moveReplaceHighlight(item, index, delta, event) {
            const list = item?.localArticleList || []
            if (!replaceMenu[index] || !list.length) return // sonst Cursor normal bewegen
            event?.preventDefault?.()
            const cur = replaceActiveIdx[index] ?? 0
            let next = cur + delta
            if (next < 0) next = list.length - 1
            if (next >= list.length) next = 0
            replaceActiveIdx[index] = next
        }

        // Enter: markierten Vorschlag übernehmen, sonst normales Verhalten (nächste Zeile).
        function onExistingEnter(item, index, event) {
            const list = item?.localArticleList || []
            if (replaceMenu[index] && list.length) {
                event?.preventDefault?.()
                const i = replaceActiveIdx[index] ?? 0
                chooseReplacement(item, index, list[i])
            } else {
                handleEnter(index)
            }
        }

        function onExistingDescInput(item, index, val) {
            // v-model hat item.description bereits gesetzt; hier nur die Suche anstoßen.
            onLocalArticleSearch(item, index, val)
            if (!val || val.trim().length < 2) {
                replaceMenu[index] = false
            }
        }

        function onExistingDescBlur(item, index) {
            // Menü schließen und Beschreibung wie gehabt speichern.
            // (Klicks auf Vorschläge nutzen @mousedown.prevent → kein vorzeitiger Blur.)
            replaceMenu[index] = false
            onDescriptionBlur(item)
        }

        function chooseReplacement(item, index, article) {
            replaceMenu[index] = false
            item.localArticleList = []
            emit('article-replace', item, index, article)
            // Fokus wie beim normalen Erfassen auf die nächste Position legen.
            focusNextPosition(index)
        }

        // Fokussiert das Eingabefeld der nächsten Position – egal ob diese eine
        // gefüllte Zeile (eigenes Textfeld) oder die leere Zeile (Autocomplete) ist.
        function focusNextPosition(index) {
            const next = index + 1
            nextTick(() => {
                setTimeout(() => {
                    let input = wrapRefs[next]?.querySelector?.('input')
                    if (!input && autocompleteRefs.value[next]) {
                        input = autocompleteRefs.value[next].$el?.querySelector('input')
                    }
                    if (input) input.focus()
                }, 60)
            })
        }

        /**
         * Übernimmt alle pending Eingaben in die Items
         * Wird vor dem Speichern aufgerufen
         */
        function commitPendingEdits() {
            // Alle Items durchgehen und pending Edits übernehmen
            props.modelValue.forEach(item => {
                const itemId = item.id || item.tempId

                // Pending Qty
                if (editingQty.value[itemId] !== undefined) {
                    const parsed = parseNumberInput(editingQty.value[itemId])
                    if (parsed !== null && Number.isFinite(parsed)) {
                        item.qty = parsed
                    }
                    delete editingQty.value[itemId]
                }

                // Pending Price
                if (editingPrice.value[itemId] !== undefined) {
                    const parsed = parseNumberInput(editingPrice.value[itemId])
                    if (parsed !== null && Number.isFinite(parsed)) {
                        item.sellprice = parsed
                    }
                    delete editingPrice.value[itemId]
                }

                // Pending Discount (Eingabe in Prozent, gespeichert als Faktor)
                if (editingDiscount.value[itemId] !== undefined) {
                    const parsed = parseNumberInput(editingDiscount.value[itemId])
                    if (parsed !== null && Number.isFinite(parsed)) {
                        item.discount = parsed / 100
                    }
                    delete editingDiscount.value[itemId]
                }
            })

            // Totals neu berechnen
            props.calculateTotals()
        }

        // Expose für Parent-Komponente
        expose({
            focusQtyField,
            focusArticleField,
            updateLocalArticleList,
            commitPendingEdits
        })

        /**
         * Formatiert den Artikeltyp für die Anzeige
         *
         * @param {string} partType - Der Artikeltyp
         * @param {number} classificationId - Die Klassifizierungs-ID
         * @returns {string} Formatierter Typ
         */
        function formatPartType(partType, classificationId) {
            // Wenn kein partType, gebe leeren String zurück
            if (!partType) return ''

            const typeMap = {
                'part': 'W',
                'service': 'D',
                'assembly': 'E',
                'assortment': 'K'
            }

            const abbreviation = oserp.session.company_config.part_classifications
                .find(item => item.id === classificationId)?.abbreviation || 'None (typeabbreviation)'

            const classifyMap = {
                'None (typeabbreviation)': '',
                'Purchase (typeabbreviation)': 'E',
                'Sales (typeabbreviation)': 'V',
                'Merchandise (typeabbreviation)': 'H',
                'Production (typeabbreviation)': 'P'
            }

            return (typeMap[partType] || '') + (classifyMap[abbreviation] || '')
        }

        /**
         * Berechnet den Betrag eines Items
         *
         * @param {Object} item - Das Item
         * @returns {number} Berechneter Betrag
         */
        function calculateItemAmount(item) {
            if (!item.qty || !item.sellprice) {
                return 0
            }

            const subtotal = item.qty * item.sellprice
            const discountAmount = subtotal * item.discount
            return subtotal - discountAmount
        }

        /**
         * Formatiert Zahl für Anzeige (locale-aware)
         *
         * @param {number} value - Der Wert
         * @param {number} digits - Anzahl Nachkommastellen
         * @returns {string} Formatierte Zahl
         */
        function formatNumberDisplay(value, digits = 2) {
            return formatNumber(value, currentLocale.value, digits)
        }

        /**
         * Parst Zahl aus Eingabe (locale-aware)
         *
         * @param {string} text - Der Text
         * @returns {number|null} Geparste Zahl oder null
         */
        function parseNumberInput(text) {
            return parseNumber(text, currentLocale.value)
        }

        // === QTY Feld ===

        /**
         * Gibt die formatierte Menge zurück
         *
         * @param {Object} item - Das Item
         * @returns {string} Formatierte Menge
         */
        function getFormattedQty(item) {
            const itemId = item.id || item.tempId
            // Während Eingabe: zeige rohen Text
            if (editingQty.value[itemId] !== undefined) {
                return editingQty.value[itemId]
            }
            // Sonst: zeige formatierte Zahl mit 2 Nachkommastellen
            return formatNumberDisplay(item.qty || 0, 2)
        }

        /**
         * Handler für Mengeneingabe
         *
         * @param {Object} item - Das Item
         * @param {string} value - Der eingegebene Wert
         */
        function onQtyInput(item, value) {
            const itemId = item.id || item.tempId
            editingQty.value[itemId] = value
        }

        /**
         * Handler für Blur des Mengenfelds
         *
         * @param {Object} item - Das Item
         */
        function onQtyBlur(item) {
            const itemId = item.id || item.tempId
            const rawValue = editingQty.value[itemId]

            if (rawValue !== undefined) {
                const parsed = parseNumberInput(rawValue)
                if (parsed !== null && Number.isFinite(parsed)) {
                    item.qty = parsed
                    props.calculateItemTotal(item)
                    props.calculateTotals()
                    // Auto-Save wenn Item bereits gespeichert
                    if (item.id) {
                        emit('items-changed')
                    }
                }
                delete editingQty.value[itemId]
            }
        }

        // === SELLPRICE Feld ===

        /**
         * Gibt den formatierten Preis zurück
         *
         * @param {Object} item - Das Item
         * @returns {string} Formatierter Preis
         */
        function getFormattedPrice(item) {
            const itemId = item.id || item.tempId
            if (editingPrice.value[itemId] !== undefined) {
                return editingPrice.value[itemId]
            }
            return formatNumberDisplay(item.sellprice || 0, 2)
        }

        /**
         * Handler für Preiseingabe
         *
         * @param {Object} item - Das Item
         * @param {string} value - Der eingegebene Wert
         */
        function onPriceInput(item, value) {
            const itemId = item.id || item.tempId
            editingPrice.value[itemId] = value
        }

        /**
         * Handler für Blur des Preisfelds
         *
         * @param {Object} item - Das Item
         */
        function onPriceBlur(item) {
            const itemId = item.id || item.tempId
            const rawValue = editingPrice.value[itemId]

            if (rawValue !== undefined) {
                const parsed = parseNumberInput(rawValue)
                if (parsed !== null && Number.isFinite(parsed)) {
                    item.sellprice = parsed
                    props.calculateItemTotal(item)
                    props.calculateTotals()
                    // Auto-Save wenn Item bereits gespeichert
                    if (item.id) {
                        emit('items-changed')
                    }
                }
                delete editingPrice.value[itemId]
            }
        }

        // === DISCOUNT Feld ===

        /**
         * Gibt den formatierten Rabatt zurück
         *
         * @param {Object} item - Das Item
         * @returns {string} Formatierter Rabatt
         */
        function getFormattedDiscount(item) {
            const itemId = item.id || item.tempId
            if (editingDiscount.value[itemId] !== undefined) {
                return editingDiscount.value[itemId]
            }
            const percent = Math.round((item.discount || 0) * 10000) / 100
            return formatNumberDisplay(percent, Number.isInteger(percent) ? 0 : 2)
        }

        /**
         * Handler für Rabatteingabe
         *
         * @param {Object} item - Das Item
         * @param {string} value - Der eingegebene Wert
         */
        function onDiscountInput(item, value) {
            const itemId = item.id || item.tempId
            editingDiscount.value[itemId] = value
        }

        /**
         * Handler für Blur des Rabattfelds
         *
         * @param {Object} item - Das Item
         */
        function onDiscountBlur(item) {
            const itemId = item.id || item.tempId
            const rawValue = editingDiscount.value[itemId]

            if (rawValue !== undefined) {
                const parsed = parseNumberInput(rawValue)
                if (parsed !== null && Number.isFinite(parsed)) {
                    item.discount = parsed / 100
                    props.calculateItemTotal(item)
                    props.calculateTotals()
                    // Auto-Save wenn Item bereits gespeichert
                    if (item.id) {
                        emit('items-changed')
                    }
                }
                delete editingDiscount.value[itemId]
            }
        }

        // === LANGTEXT DIALOG ===

        /**
         * Handler für Blur des Beschreibungsfelds
         *
         * @param {Object} item - Das Item
         */
        function onDescriptionBlur(item) {
            // Auto-Save wenn Item bereits gespeichert
            if (item.id) {
                emit('items-changed')
            }
        }

        /**
         * Öffnet den Langtext-Dialog für ein Item
         *
         * @param {Object} item - Das Item
         * @param {number} index - Index des Items
         */
        function openLongDescriptionDialog(item, index) {
            longDescDialog.value = {
                show: true,
                item: item,
                index: index,
                text: item.longdescription || ''
            }
        }

        /**
         * Diktierten Text an den Langtext anhängen (nicht ersetzen), damit man
         * mehrere Sätze nacheinander einsprechen kann.
         */
        function onVoiceLongDesc(text) {
            const add = (text || '').trim()
            if (!add) return
            const cur = longDescDialog.value.text || ''
            longDescDialog.value.text = cur ? (cur.replace(/\s+$/, '') + ' ' + add) : add
        }

        /**
         * Schließt den Langtext-Dialog ohne zu speichern
         */
        function closeLongDescriptionDialog() {
            longDescDialog.value.show = false
        }

        /**
         * Speichert den Langtext und schließt den Dialog
         */
        function saveLongDescription() {
            if (longDescDialog.value.item) {
                longDescDialog.value.item.longdescription = longDescDialog.value.text
                // Event emittieren damit Parent saveAllItems aufruft
                emit('items-changed')
            }
            longDescDialog.value.show = false
        }

        function getItemOrderStatus(item) {
            if (!item.id || !props.partsRequests.length) return null
            const req = props.partsRequests.find(r => r.orderitem_id === item.id)
            return req ? req.status : null
        }

        function getItemOrderClass(item) {
            const status = getItemOrderStatus(item)
            if (status === 'pending') return 'parts-request-pending'
            if (status === 'ordered') return 'parts-request-ordered'
            return ''
        }

        function getItemVendorName(item) {
            if (!item.id) return ''
            const req = props.partsRequests.find(r => r.orderitem_id === item.id)
            return req?.vendor_name || ''
        }

        function getItemVendorId(item) {
            if (!item.id) return null
            const req = props.partsRequests.find(r => r.orderitem_id === item.id)
            return req?.vendor_id ? Number(req.vendor_id) : null
        }

        function getItemPhoto(item) {
            if (!item.id) return null
            const req = props.partsRequests.find(r => r.orderitem_id === item.id)
            return req?.photo || null
        }

        function getItemPhotoCount(item) {
            const photo = getItemPhoto(item)
            if (!photo) return 0
            return photo.split(',').filter(Boolean).length
        }

        // Lieferant-Menu
        const vendorMenuOpen = reactive({})
        const vendorSearch = ref('')
        const vendorCurrentId = ref(null)
        const vendorHighlightIdx = ref(-1)
        const vendorSearchResults = ref([])
        const vendorSearching = ref(false)
        let vendorSearchTimer = null
        let carsStore = null
        try { carsStore = lxcarsStore() } catch { /* LxCars nicht aktiv */ }

        // Ohne Suchbegriff: Top-5 (recentVendors), mit Suchbegriff: API-Ergebnisse
        const vendorDisplayList = computed(() => {
            if (!vendorSearch.value.trim()) return props.recentVendors
            return vendorSearchResults.value
        })

        watch(vendorSearch, (val) => {
            if (vendorSearchTimer) clearTimeout(vendorSearchTimer)
            vendorHighlightIdx.value = -1
            const q = (val || '').trim()
            if (q.length < 2) { vendorSearchResults.value = []; return }
            vendorSearching.value = true
            vendorSearchTimer = setTimeout(async () => {
                try {
                    vendorSearchResults.value = carsStore ? await carsStore.searchVendors(q) : []
                } catch { vendorSearchResults.value = [] }
                vendorSearching.value = false
            }, 250)
        })

        function openVendorMenu(item) {
            vendorCurrentId.value = getItemVendorId(item)
            vendorSearch.value = ''
            vendorSearchResults.value = []
            vendorHighlightIdx.value = -1
        }

        function vendorHighlightMove(dir) {
            const len = vendorDisplayList.value.length
            if (!len) return
            vendorHighlightIdx.value = Math.max(0, Math.min(len - 1, vendorHighlightIdx.value + dir))
        }

        function vendorHighlightSelect(item) {
            const list = vendorDisplayList.value
            if (vendorHighlightIdx.value >= 0 && vendorHighlightIdx.value < list.length) {
                onVendorSelect(item, list[vendorHighlightIdx.value])
            }
        }

        function onVendorSelect(item, vendor) {
            vendorMenuOpen[item.id] = false
            emit('order-part', item, vendor)
        }

        return {
            t,
            selectedItems,
            allRealItemsSelected,
            someSelected,
            toggleItemSelection,
            toggleSelectAll,
            emitDeleteSelected,
            getItemOrderStatus,
            getItemOrderClass,
            getItemVendorName,
            getItemVendorId,
            getItemPhoto,
            getItemPhotoCount,
            vendorMenuOpen,
            vendorSearch,
            vendorCurrentId,
            vendorHighlightIdx,
            vendorDisplayList,
            vendorSearching,
            openVendorMenu,
            vendorHighlightMove,
            vendorHighlightSelect,
            onVendorSelect,
            qtyRefs,
            autocompleteRefs,
            searchTimeouts,
            longDescDialog,
            onVoiceLongDesc,
            onVoicePosition,
            getItemKey,
            canMoveItem,
            onDragChange,
            allDiscountsButtonLabel,
            toggleAllDiscounts,
            getItemDiscountButtonLabel,
            toggleItemDiscount,
            onLocalArticleSearch,
            onLocalArticleSelect,
            onArticleEnter,
            handleEnter,
            formatPartType,
            calculateItemAmount,
            formatNumberDisplay,
            getFormattedQty,
            onQtyInput,
            onQtyBlur,
            getFormattedPrice,
            onPriceInput,
            onPriceBlur,
            getFormattedDiscount,
            onDiscountInput,
            onDiscountBlur,
            onDescriptionBlur,
            replaceMenu,
            replaceActiveIdx,
            wrapRefs,
            suggestionStyle,
            onExistingDescInput,
            onExistingDescBlur,
            moveReplaceHighlight,
            onExistingEnter,
            chooseReplacement,
            openLongDescriptionDialog,
            closeLongDescriptionDialog,
            saveLongDescription
        }
    }
})
</script>

<style scoped>
/* ============================================
   FAKTURA ITEMS TABLE
   ============================================ */

/* Eigene Artikel-Vorschlagsliste (per Teleport an body) */
.article-replace-wrap { position: relative; }
.ac-suggestions {
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-border-color), 0.25);
    border-radius: 4px;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
    max-height: 320px;
    overflow-y: auto;
    min-width: 280px;
}
.ac-suggestion {
    padding: 6px 12px;
    cursor: pointer;
    line-height: 1.25;
}
.ac-suggestion.ac-active,
.ac-suggestion:hover {
    background: rgba(var(--v-theme-primary), 0.12);
}
.ac-suggestion__title { font-weight: 500; }
.ac-suggestion__sub {
    font-size: 0.75rem;
    color: rgba(var(--v-theme-on-surface), 0.6);
}

/* Card Styling */
.faktura-card {
    border-radius: 8px;
    overflow: hidden;
}

.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #eeeeee;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}

.faktura-card__table-body {
    padding: 0 !important;
}

/* Tabellen Styling */
.invoice-items-table {
    width: 100%;
}

/* Tabellen-Header */
.invoice-items-table :deep(thead th) {
    padding: 12px 8px !important;
    vertical-align: middle;
    white-space: nowrap;
    font-weight: 700 !important;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: rgba(0, 0, 0, 0.7) !important;
    background-color: #fafafa;
    border-bottom: 2px solid rgba(0, 0, 0, 0.12);
}

/* Tabellen-Zellen */
.invoice-items-table :deep(tbody td) {
    padding: 8px 6px !important;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

/* Zebra-Streifen für bessere Lesbarkeit */
.invoice-items-table :deep(tbody tr:nth-child(even)) {
    background-color: #fafafa;
}

.invoice-items-table :deep(tbody tr:hover) {
    background-color: #f0f7ff;
}

/* Vuetify Input-Wrapper optimieren */
.invoice-items-table :deep(.v-input) {
    flex: unset;
    grid-template-rows: auto !important;
}

.invoice-items-table :deep(.v-input__control) {
    display: flex;
}

.invoice-items-table :deep(.v-input__details) {
    display: none !important;
}

/* Input-Felder */
.invoice-items-table :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 10px;
    --v-field-padding-end: 10px;
}

/* Autocomplete Styling */
.invoice-items-table :deep(.v-autocomplete) {
    flex: unset !important;
}

.invoice-items-table :deep(.v-autocomplete .v-input__control) {
    min-height: unset;
}

/* Drag Handle */
.drag-handle {
    cursor: move;
    color: #9e9e9e;
    transition: color 0.2s;
}

.drag-handle:hover {
    color: #616161;
}

.dragging {
    opacity: 0.5;
    background-color: #e3f2fd !important;
}

/* Rechtsbündige Zahlenfelder */
.text-right-input :deep(input) {
    text-align: right;
}

/* Ausgegraute Felder für neue Zeilen ohne Artikel */
.field-disabled {
    opacity: 0.4;
}

.field-disabled :deep(.v-field) {
    background-color: #f5f5f5;
}

/* Ausgegrautes Drag-Handle für neue Zeilen */
.drag-disabled {
    opacity: 0.25;
    cursor: not-allowed !important;
}

/* Ausgegraute Buttons für neue Zeilen */
.btn-disabled {
    opacity: 0.25 !important;
}

/* Ausgewählte Zeile */
.selected-item-row {
    background-color: rgba(244, 67, 54, 0.06) !important;
}

.selected-item-row:hover {
    background-color: rgba(244, 67, 54, 0.12) !important;
}

/* Neue Zeile - einladend gestaltet */
.new-item-row {
    background-color: #fff !important;
}

.new-item-row:hover {
    background-color: #fff !important;
}

/* Artikel-Suche in neuer Zeile hervorheben */
.new-item-input :deep(.v-field) {
    background-color: #fff;
    border-color: #1976d2;
}

.new-item-input :deep(.v-field--focused) {
    border-color: #1976d2;
    box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.15);
}

.new-item-input :deep(input::placeholder) {
    color: #1976d2;
    opacity: 0.7;
}

/* Dropdown-Pfeil ist über menu-icon="" entfernt; append-inner bleibt sichtbar,
   damit der Mikrofon-Knopf angezeigt wird. */

/* Action-Buttons in den Zellen */
.invoice-items-table :deep(.v-btn--icon) {
    transition: transform 0.15s, color 0.15s;
}

.invoice-items-table :deep(.v-btn--icon:hover:not(:disabled)) {
    transform: scale(1.1);
}

/* Ersatzteil-Bestellstatus */
.parts-request-pending {
    background-color: rgba(244, 67, 54, 0.08) !important;
    border-left: 3px solid #f44336;
}

.parts-request-pending:hover {
    background-color: rgba(244, 67, 54, 0.14) !important;
}

.parts-request-ordered {
    background-color: rgba(255, 152, 0, 0.08) !important;
    border-left: 3px solid #ff9800;
}

.parts-request-ordered:hover {
    background-color: rgba(255, 152, 0, 0.14) !important;
}

/* Lieferant-Chip in der Bestellspalte */
.parts-vendor-chip {
    max-width: 160px;
    cursor: pointer;
    font-size: 0.7rem !important;
}

.parts-vendor-chip :deep(.v-chip__content) {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Suchfeld im Lieferant-Popup */
.parts-vendor-search-field :deep(.v-field__input) {
    font-size: 0.8rem !important;
}
</style>