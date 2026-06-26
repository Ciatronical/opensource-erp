<!-- src/features/lxcars/views/mechanic/mechanic-order.view.vue -->

<template>
    <div class="mechanic-detail">
        <!-- Header -->
        <div class="mechanic-detail__header pa-3 d-flex align-center">
            <v-btn icon variant="text" color="primary" @click="$router.push({ name: 'mechanic' })">
                <v-icon>mdi-arrow-left</v-icon>
            </v-btn>
            <div class="ml-2 flex-grow-1">
                <div class="d-flex align-center ga-2 flex-wrap">
                    <span class="text-subtitle-1 font-weight-bold">{{ docNumber }}</span>
                    <v-btn
                        v-if="customerId"
                        size="small"
                        variant="tonal"
                        color="primary"
                        prepend-icon="mdi-account"
                        @click="openCustomer"
                    >
                        {{ customerName }}
                    </v-btn>
                    <span v-else class="text-body-2 text-medium-emphasis">{{ customerName }}</span>
                    <v-btn
                        v-if="vehiclePlate"
                        size="small"
                        variant="tonal"
                        color="primary"
                        prepend-icon="mdi-car"
                        :disabled="!selectedCarId"
                        @click="openCarDetails"
                    >
                        {{ vehiclePlate }}
                    </v-btn>
                    <v-chip v-if="vehicleInfo" size="x-small" variant="text">{{ vehicleInfo }}</v-chip>
                </div>
            </div>
            <v-btn icon variant="text" color="primary" @click="reloadAll" :title="t('MechanicView.refresh')">
                <v-icon>mdi-refresh</v-icon>
            </v-btn>
            <v-btn icon variant="text" color="primary" @click="$router.push(t('routes.mainmenu'))" :title="t('MechanicView.exitMechanic')">
                <v-icon>mdi-exit-to-app</v-icon>
            </v-btn>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="d-flex justify-center pa-8">
            <v-progress-circular indeterminate color="primary" size="48" />
        </div>

        <template v-else-if="faktura.data">
            <v-container fluid class="mechanic-detail__content pa-3">
                <!-- Fahrzeug / Auftragsdaten -->
                <section v-if="vehicle" class="mb-4">
                    <vehicle-section-card
                        ref="vehicleSectionRef"
                        :is-invoice="vehicle.isInvoice.value"
                        :oe-ext-data="vehicle.oeExtData.value"
                        :selected-car-id="vehicle.selectedCarId.value"
                        :customer-cars="vehicle.customerCars.value"
                        :kfz-ort-options="vehicle.kfzOrtOptions.value"
                        :status-options="vehicle.statusOptions.value"
                        :display-km-stand="vehicle.displayKmStand.value"
                        :display-bringetermin="vehicle.displayBringetermin.value"
                        :display-fertigstellung="vehicle.displayFertigstellung.value"
                        :picker-date-bringetermin="vehicle.pickerDateBringetermin.value"
                        :picker-time-bringetermin="vehicle.pickerTimeBringetermin.value"
                        :picker-date-fertigstellung="vehicle.pickerDateFertigstellung.value"
                        :picker-time-fertigstellung="vehicle.pickerTimeFertigstellung.value"
                        :time-slots="vehicle.timeSlots.value"
                        :show-picker-bringetermin="vehicle.showPickerBringetermin.value"
                        :show-picker-fertigstellung="vehicle.showPickerFertigstellung.value"
                        :is-trailer="vehicle.isTrailer.value"
                        @toggle-intern="vehicle.toggleIntern"
                        @update:display-km-stand="v => vehicle.displayKmStand.value = v"
                        @blur-km-stand="vehicle.onBlurKmStand"
                        @car-change="vehicle.onCarChange"
                        @oe-ext-field-change="vehicle.onOeExtFieldChange"
                        @picker-date-select="vehicle.onPickerDateSelect"
                        @picker-time-select="vehicle.onPickerTimeSelect"
                        @clear-datetime="vehicle.onClearDatetime"
                    />
                </section>

                <!-- Wartung & Service (nicht bei Anhängern) -->
                <section v-if="vehicle && !vehicle.isTrailer.value && wartungEnabled" class="mb-4">
                    <maintenance-section-card
                        :oe-ext-data="vehicle.oeExtData.value"
                        :has-car="!!vehicle.selectedCarId.value"
                        @oe-ext-field-change="vehicle.onOeExtFieldChange"
                    />
                </section>

                <!-- Arbeitsanweisungen -->
                <section v-if="vehicle" class="mb-4">
                    <instructions-section-card
                        ref="instructionsRef"
                        :oe-id="fakturaId"
                        :ensure-oe-id="ensureOrderAndGetId"
                        :completion-validator="validateMaintenanceBeforeComplete"
                        @jump-to-positions="focusNewPosition"
                    />
                </section>

                <!-- Positionen -->
                <section class="mb-4">
                    <faktura-items-table-component
                        ref="itemsTableRef"
                        v-model="fakturaItems"
                        :article-list="[]"
                        :article-loading="false"
                        :net-amount="accounting.calculatedNetAmount.value"
                        :gross-amount="accounting.calculatedGrossAmount.value"
                        :tax-breakdown="accounting.taxBreakdown.value"
                        :calculate-item-total="accounting.calculateItemTotal"
                        :calculate-totals="accounting.calculateTotals"
                        :show-ai-suggest="false"
                        :ai-loading="false"
                        @article-search="items.onArticleSearch"
                        @article-select="items.onArticleSelect"
                        @create-article="items.createArticle"
                        @delete-item="items.deleteItem"
                        @delete-selected="items.deleteSelectedItems"
                        @edit-article="items.editArticle"
                        @set-item-discount="items.setItemDiscount"
                        @set-all-discounts="items.setAllDiscounts"
                        @add-new-row="items.addNewItemRow"
                        @items-changed="saveAllItems"
                        :show-parts-requests="true"
                        :parts-requests="partsRequestsList"
                        :recent-vendors="recentVendorsList"
                        @request-part="onRequestPart"
                        @order-part="onOrderPart"
                        @photo-part="onPhotoPart"
                    />
                </section>

                <!-- Mängel -->
                <section v-if="vehicle && wartungEnabled" class="mb-4">
                    <maengel-section-card
                        ref="maengelRef"
                        :oe-id="fakturaId"
                        :ensure-oe-id="ensureOrderAndGetId"
                        doc-type="order"
                    />
                </section>

                <!-- Notizen -->
                <section class="mb-4">
                    <v-card variant="outlined" class="faktura-card">
                        <v-card-title class="faktura-card__header">
                            <v-icon class="mr-2" size="small">mdi-note-text</v-icon>
                            {{ t('FakturaView.faktura.notes') }}
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="faktura-card__body">
                            <v-row>
                                <v-col cols="12" md="4">
                                    <v-textarea
                                        v-model="customerNotes"
                                        :label="t('FakturaView.faktura.customerNotes')"
                                        variant="outlined"
                                        density="compact"
                                        rows="4"
                                        hide-details
                                        readonly
                                        bg-color="grey-lighten-4"
                                    />
                                </v-col>
                                <v-col cols="12" md="4" v-if="vehicle">
                                    <v-textarea
                                        v-model="vehicle.vehicleNotes.value"
                                        :label="t('FakturaView.faktura.vehicleNotes')"
                                        variant="outlined"
                                        density="compact"
                                        rows="4"
                                        hide-details
                                        readonly
                                        bg-color="grey-lighten-4"
                                    />
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-textarea
                                        v-model="faktura.data.common.intnotes"
                                        @blur="onFakturaFieldChange('intnotes', faktura.data.common.intnotes)"
                                        :label="t('FakturaView.faktura.internalNotes')"
                                        variant="outlined"
                                        density="compact"
                                        rows="4"
                                        hide-details
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>
                </section>
            </v-container>
        </template>

        <!-- Maintenance Incomplete Dialog -->
        <v-dialog v-model="maintenanceIncompleteDialog.show" max-width="500" @keydown.esc="maintenanceIncompleteDialog.show = false">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-warning">
                    <v-icon class="mr-2">mdi-wrench-outline</v-icon>
                    {{ t('MaintenanceSectionCard.incompleteTitle') }}
                    <v-spacer />
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="small"
                        @click="maintenanceIncompleteDialog.show = false"
                    />
                </v-card-title>
                <v-card-text class="pt-4 pb-2">
                    <p>{{ t('MaintenanceSectionCard.incompleteText') }}</p>
                    <v-list density="compact" class="mt-2">
                        <v-list-item
                            v-for="field in maintenanceIncompleteDialog.fields"
                            :key="field"
                            class="px-2"
                        >
                            <template #prepend>
                                <v-icon size="small" color="warning">mdi-alert-circle</v-icon>
                            </template>
                            <v-list-item-title class="text-body-2">
                                {{ field === 'km_stand' ? t('FakturaView.faktura.kmStand') : t('MaintenanceSectionCard.fields.' + field) }}
                            </v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn color="primary" variant="elevated" @click="maintenanceIncompleteDialog.show = false">
                        {{ t('MaintenanceSectionCard.close') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Bulk Delete Items Confirmation Dialog -->
        <v-dialog v-model="items.bulkDeleteDialog.value.show" max-width="420" @keydown.esc="items.bulkDeleteDialog.value.show = false">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-error text-white">
                    <v-icon class="mr-2">mdi-delete-sweep</v-icon>
                    {{ t('FakturaView.dialogs.deleteBulk.title') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="items.bulkDeleteDialog.value.show = false" />
                </v-card-title>
                <v-card-text class="pt-4 pb-2">
                    <p>{{ t('FakturaView.dialogs.deleteBulk.text', { count: items.bulkDeleteDialog.value.items.length }) }}</p>
                    <v-list density="compact" class="mt-2 pa-0">
                        <v-list-item
                            v-for="item in items.bulkDeleteDialog.value.items"
                            :key="item.id || item.tempId"
                            class="px-0"
                            density="compact"
                        >
                            <template #prepend>
                                <v-icon size="small" color="error" class="mr-2">mdi-circle-small</v-icon>
                            </template>
                            <v-list-item-title class="text-body-2">
                                <span class="font-weight-medium">{{ item.partnumber }}</span>
                                <span v-if="item.description" class="text-medium-emphasis ml-1">– {{ item.description }}</span>
                            </v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="items.bulkDeleteDialog.value.show = false">
                        {{ t('FakturaView.dialogs.deleteBulk.cancel') }}
                    </v-btn>
                    <v-btn color="error" variant="elevated" prepend-icon="mdi-delete-sweep" @click="items.confirmBulkDeleteItems">
                        {{ t('FakturaView.dialogs.deleteBulk.confirm', { count: items.bulkDeleteDialog.value.items.length }) }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Item Confirmation Dialog -->
        <v-dialog v-model="items.deleteDialog.value.show" max-width="400">
            <v-card>
                <v-card-title class="bg-error text-white">
                    <v-icon class="mr-2">mdi-delete-alert</v-icon>
                    {{ t('FakturaView.dialogs.deleteItem.title') }}
                </v-card-title>
                <v-card-text class="pt-4">
                    <p>{{ t('FakturaView.dialogs.deleteItem.text') }}</p>
                    <p v-if="items.deleteDialog.value.item" class="mt-2 font-weight-medium">
                        {{ items.deleteDialog.value.item.partnumber }} - {{ items.deleteDialog.value.item.description }}
                    </p>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="items.deleteDialog.value.show = false">
                        {{ t('FakturaView.dialogs.deleteItem.cancel') }}
                    </v-btn>
                    <v-btn color="error" variant="elevated" prepend-icon="mdi-delete" @click="items.confirmDeleteItem">
                        {{ t('FakturaView.dialogs.deleteItem.confirm') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Edit Item Dialog -->
        <edit-part-dialog
            v-model="items.editDialog.value.show"
            :item="items.editDialog.value.item"
            @save="items.onEditItemSave"
        />

        <!-- Create Item Dialog -->
        <create-part-dialog
            v-model="items.createDialog.value.show"
            :search-text="items.createDialog.value.searchText"
            :item-index="items.createDialog.value.itemIndex"
            @save="items.onCreateArticleSave"
        />

        <!-- Foto-Dialog (Multi-Foto) -->
        <v-dialog v-model="photoDialog.show" max-width="600">
            <v-card>
                <v-card-title class="d-flex align-center">
                    {{ t('FakturaView.faktura.partPhoto') }}
                    <v-chip v-if="photoDialog.photos.length" size="x-small" class="ml-2" color="primary" variant="tonal">
                        {{ photoDialog.photos.length }}
                    </v-chip>
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <div v-if="photoDialog.photos.length" class="d-flex flex-wrap ga-2 mb-4">
                        <div
                            v-for="(photo, idx) in photoDialog.photos"
                            :key="photo.path"
                            class="position-relative"
                            style="width: 130px; height: 130px"
                        >
                            <v-img
                                :src="'data:image/jpeg;base64,' + photo.image"
                                width="130" height="130" cover
                                class="rounded border"
                                style="cursor: zoom-in"
                                @click="photoDialog.fullscreenIdx = idx; photoDialog.fullscreen = true"
                            />
                            <v-btn
                                icon="mdi-close-circle" size="x-small" color="red" variant="flat"
                                class="position-absolute" style="top: -6px; right: -6px; z-index: 1"
                                @click.stop="deletePhoto(photo.path)"
                            />
                        </div>
                    </div>
                    <v-file-input
                        :key="photoDialog.uploadKey"
                        :label="t('FakturaView.faktura.selectPhoto')"
                        accept="image/*" capture="environment"
                        prepend-icon="mdi-camera"
                        variant="outlined" density="compact" hide-details show-size
                        @update:model-value="onPhotoFileSelected"
                    />
                    <div v-if="photoDialog.uploading" class="text-center mt-3">
                        <v-progress-circular indeterminate size="24" color="primary" />
                    </div>
                </v-card-text>
                <v-divider />
                <v-card-actions class="justify-end pa-3">
                    <v-btn color="primary" variant="flat" prepend-icon="mdi-check" @click="photoDialog.show = false">
                        {{ t('FakturaView.faktura.photoDone') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Fullscreen Foto-Viewer -->
        <v-dialog v-model="photoDialog.fullscreen" max-width="90vw">
            <v-card class="pa-0 position-relative" style="background: #000">
                <v-btn icon="mdi-close" size="small" variant="text" color="white"
                    class="position-absolute" style="top: 8px; right: 8px; z-index: 2"
                    @click="photoDialog.fullscreen = false" />
                <v-btn v-if="photoDialog.photos.length > 1" icon="mdi-chevron-left" size="large" variant="text" color="white"
                    class="position-absolute" style="top: 50%; left: 4px; transform: translateY(-50%); z-index: 2"
                    @click.stop="photoDialog.fullscreenIdx = (photoDialog.fullscreenIdx - 1 + photoDialog.photos.length) % photoDialog.photos.length" />
                <v-btn v-if="photoDialog.photos.length > 1" icon="mdi-chevron-right" size="large" variant="text" color="white"
                    class="position-absolute" style="top: 50%; right: 4px; transform: translateY(-50%); z-index: 2"
                    @click.stop="photoDialog.fullscreenIdx = (photoDialog.fullscreenIdx + 1) % photoDialog.photos.length" />
                <v-img v-if="photoDialog.photos[photoDialog.fullscreenIdx]"
                    :src="'data:image/jpeg;base64,' + photoDialog.photos[photoDialog.fullscreenIdx].image"
                    max-height="85vh" contain />
                <div v-if="photoDialog.photos.length > 1" class="text-center pa-2" style="color: white; font-size: 0.8rem">
                    {{ photoDialog.fullscreenIdx + 1 }} / {{ photoDialog.photos.length }}
                </div>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { fakturaStore as useFakturaStore } from '@/core/stores/faktura.store.js'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import { useAccounting } from '@/core/views/faktura/composables/useAccounting.js'
import { useItemManagement } from '@/core/views/faktura/composables/useItemManagement.js'
import { useVehicleSection } from '@/core/views/faktura/composables/useVehicleSection.js'
import VehicleSectionCard from '@/core/views/faktura/cards/vehicle.section.card.vue'
import FakturaItemsTableComponent from '@/core/views/faktura/components/faktura.items.table.component.vue'
import EditPartDialog from '@/core/views/faktura/dialogs/edit.part.dialog.vue'
import CreatePartDialog from '@/core/views/faktura/dialogs/create.part.dialog.vue'
import InstructionsSectionCard from '@/features/lxcars/components/instructions.section.card.vue'
import MaengelSectionCard from '@/features/lxcars/components/maengel.section.card.vue'
import MaintenanceSectionCard from '@/features/lxcars/components/maintenance.section.card.vue'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    id: { type: [String, Number], required: true }
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const oserp = oserpStore()
const faktura = useFakturaStore()
const carsStore = lxcarsStore()

const loading = ref(false)
const fakturaId = ref(Number(props.id))
const fakturaType = ref('order')

// Refs fuer Sub-Komponenten
const vehicleSectionRef = ref(null)
const instructionsRef = ref(null)
const itemsTableRef = ref(null)
const maengelRef = ref(null)

// Daten
const fakturaItems = ref([])
const customerNotes = ref('')
const paymentList = ref([])
const currencyList = ref([])

// Header-Infos
const customerName = computed(() => faktura.data?.customer?.name || '')
const docNumber = computed(() => faktura.data?.common?.ordnumber || '')
const vehiclePlate = computed(() => {
    const cars = vehicle?.customerCars?.value || []
    const carId = vehicle?.selectedCarId?.value
    const car = cars.find(c => c.c_id === carId)
    return car?.c_ln || ''
})
const selectedCarId = computed(() => vehicle?.selectedCarId?.value || null)
const customerId = computed(() => faktura.data?.common?.customer_id || null)

function openCarDetails() {
    const carId = selectedCarId.value
    if (carId) router.push({ name: 'mechanic-car', params: { id: carId }, query: { order: fakturaId.value } })
}

function openCustomer() {
    if (customerId.value) router.push({ name: 'change-customer', params: { id: customerId.value } })
}
const vehicleInfo = computed(() => {
    const cars = vehicle?.customerCars?.value || []
    const carId = vehicle?.selectedCarId?.value
    const car = cars.find(c => c.c_id === carId)
    if (!car) return ''
    return [car.hersteller, car.d2].filter(Boolean).join(' ')
})

// ===== Composables =====

const accounting = useAccounting({
    fakturaItems, faktura, fakturaType, paymentList, oserp, currencyList
})

const vehicle = useVehicleSection({
    carsStore, fakturaId, fakturaType, t
})

// Wartung-Validierung beim Abschluss der letzten Anweisung
const maintenanceIncompleteDialog = ref({ show: false, fields: [] })

const wartungEnabled = computed(() => {
    const val = oserp.getClientDefaultValue('lxcars_wartung_enabled', true)
    if (val === null || val === undefined || val === '') return true
    return val === true || val === 'true' || val === 't' || val === '1'
})

function validateMaintenanceBeforeComplete() {
    if (!vehicle || !vehicle.selectedCarId.value) return true
    if (vehicle.isTrailer.value) return true
    if (!wartungEnabled.value) return true
    const e = vehicle.oeExtData.value || {}
    const missing = []
    if (!e.km_stand) missing.push('km_stand')
    if (!e.c_bf) missing.push('c_bf')
    if (!e.c_wd) missing.push('c_wd')
    if (!e.c_sk) {
        if (!e.c_zrd) missing.push('c_zrd')
        if (!e.c_zrk) missing.push('c_zrk')
    }
    if (missing.length === 0) return true
    maintenanceIncompleteDialog.value = { show: true, fields: missing }
    return false
}

async function saveAllItems() {
    try {
        accounting.flushCalculation()
        const accTransEntries = accounting.calculateAccTransEntries()
        const paymentEntries = accounting.calculatePaymentEntries()
        await faktura.updateFakturaItems(
            fakturaId.value,
            fakturaItems.value,
            fakturaType.value,
            accTransEntries,
            paymentEntries,
            faktura.data.common?.netamount || 0,
            faktura.data.common?.amount || 0
        )
    } catch (e) {
        console.error('Fehler beim Speichern der Positionen:', e)
        alerts.error(t('FakturaView.faktura.itemUpdateError'))
    }
}

async function ensureFakturaExists() {
    // Im Mechaniker-Modus wird nur auf bestehenden Auftraegen gearbeitet
    return
}

async function ensureOrderAndGetId() {
    return fakturaId.value
}

function flushRouteReplace() {}

const items = useItemManagement({
    fakturaItems, fakturaId, fakturaType, faktura, itemsTableRef,
    calculateItemTotal: accounting.calculateItemTotal,
    calculateTotals: accounting.calculateTotals,
    saveAllItems, ensureFakturaExists, flushRouteReplace,
    oserp, t, router
})

function focusNewPosition() {
    nextTick(() => {
        if (itemsTableRef.value) {
            itemsTableRef.value.focusArticleField(fakturaItems.value.length - 1)
        }
    })
}

// ===== Feld-Persistenz =====

async function onFakturaFieldChange(field, value) {
    if (!fakturaId.value) return
    try {
        await faktura.updateFakturaField(fakturaId.value, fakturaType.value, field, value)
    } catch (e) {
        console.error('Fehler beim Speichern des Feldes:', e)
    }
}

// ===== Ersatzteil-Bestellstatus =====

const partsRequestsList = ref([])
const recentVendorsList = ref([])

async function loadPartsRequests() {
    try {
        partsRequestsList.value = await carsStore.getPartsRequestsByOrder(fakturaId.value)
    } catch { partsRequestsList.value = [] }
}

async function loadRecentVendors() {
    try {
        recentVendorsList.value = await carsStore.getRecentVendors()
    } catch { recentVendorsList.value = [] }
}

async function onRequestPart(item) {
    if (!fakturaId.value || !item.id) return
    try {
        await carsStore.requestPartsForItem(fakturaId.value, item.id)
        loadPartsRequests()
    } catch (e) {
        console.error('Error requesting part:', e)
    }
}

async function onOrderPart(item, vendor) {
    const req = partsRequestsList.value.find(r => r.orderitem_id === item.id)
    if (!req) return
    try {
        await carsStore.markPartsRequestOrdered(req.id, vendor.id)
        loadPartsRequests()
    } catch (e) {
        console.error('Error ordering part:', e)
    }
}

// ===== Foto-Dialog =====

const photoDialog = ref({
    show: false, fullscreen: false, fullscreenIdx: 0,
    requestId: null, photos: [], uploading: false, uploadKey: 0
})

async function onPhotoPart(item) {
    const req = partsRequestsList.value.find(r => r.orderitem_id === item.id)
    if (!req) return
    photoDialog.value = { show: true, fullscreen: false, fullscreenIdx: 0, requestId: req.id, photos: [], uploading: false, uploadKey: 0 }
    if (req.photo) {
        try {
            const result = await carsStore.getPartsRequestPhoto(req.id)
            photoDialog.value.photos = result.photos || []
        } catch { /* keine Fotos */ }
    }
}

async function onPhotoFileSelected(files) {
    const file = Array.isArray(files) ? files[0] : files
    if (!file || !photoDialog.value.requestId) return
    photoDialog.value.uploading = true
    try {
        const base64 = await new Promise((resolve, reject) => {
            const reader = new FileReader()
            reader.onload = () => resolve(reader.result)
            reader.onerror = reject
            reader.readAsDataURL(file)
        })
        await carsStore.savePartsRequestPhoto(photoDialog.value.requestId, base64)
        const loaded = await carsStore.getPartsRequestPhoto(photoDialog.value.requestId)
        photoDialog.value.photos = loaded.photos || []
        photoDialog.value.uploadKey++
        loadPartsRequests()
    } catch (e) {
        console.error('Error uploading photo:', e)
    } finally {
        photoDialog.value.uploading = false
    }
}

async function deletePhoto(path) {
    if (!photoDialog.value.requestId) return
    try {
        await carsStore.deletePartsRequestPhoto(photoDialog.value.requestId, path)
        photoDialog.value.photos = photoDialog.value.photos.filter(p => p.path !== path)
        loadPartsRequests()
    } catch (e) {
        console.error('Error deleting photo:', e)
    }
}

// ===== Daten laden =====

async function loadFakturaData() {
    loading.value = true
    try {
        await faktura.fetchFakturaData(fakturaId.value, fakturaType.value)

        if (faktura.data) {
            fakturaItems.value = (faktura.data.positions || []).map(item => {
                let buchungsziel = item.buchungsziel
                if (typeof buchungsziel === 'string') {
                    try { buchungsziel = JSON.parse(buchungsziel) }
                    catch { buchungsziel = null }
                }
                return { ...item, buchungsziel, localArticleList: [], localArticleLoading: false }
            })

            // Leere Zeile am Ende
            const lastItem = fakturaItems.value[fakturaItems.value.length - 1]
            if (!lastItem || lastItem.parts_id) {
                fakturaItems.value.push(items.createEmptyItem())
            }

            customerNotes.value = faktura.data.customer?.notes || ''
            currencyList.value = oserp.session.company_config?.currencies || []

            // Fahrzeuge laden
            if (vehicle) {
                vehicle.loadVehicleData(faktura.data.common?.customer_id)
            }

            accounting.calculateTotals()
            loadPartsRequests()
            loadRecentVendors()
        }
    } catch (e) {
        console.error('Error loading faktura data:', e)
        alerts.error(t('FakturaView.faktura.loadError'))
    } finally {
        loading.value = false
    }
}

async function reloadAll() {
    await loadFakturaData()
}

// ===== SSE =====

let sseSource = null
let sseReloadPending = false

async function reloadFakturaData() {
    if (!fakturaId.value || sseReloadPending) return
    sseReloadPending = true
    try {
        await faktura.fetchFakturaData(fakturaId.value, fakturaType.value)
        if (!faktura.data) return

        fakturaItems.value = (faktura.data.positions || []).map(item => {
            let buchungsziel = item.buchungsziel
            if (typeof buchungsziel === 'string') {
                try { buchungsziel = JSON.parse(buchungsziel) }
                catch { buchungsziel = null }
            }
            return { ...item, buchungsziel, localArticleList: [], localArticleLoading: false }
        })

        const lastItem = fakturaItems.value[fakturaItems.value.length - 1]
        if (!lastItem || lastItem.parts_id) {
            fakturaItems.value.push(items.createEmptyItem())
        }

        customerNotes.value = faktura.data.customer?.notes || ''
        accounting.calculateTotals()
    } catch (e) {
        console.error('SSE reload error:', e)
    } finally {
        sseReloadPending = false
    }
}

function connectSSE() {
    sseSource = new EventSource('/sse/events')
    sseSource.onmessage = (event) => {
        try {
            const data = JSON.parse(event.data)
            const relevantTables = ['oe', 'orderitems', 'oe_instructions_lxcars', 'oe_defects', 'oe_parts_requests_lxcars']
            if (!relevantTables.includes(data.table)) return
            if (Number(data.id) !== Number(fakturaId.value)) return

            if (data.table === 'oe_instructions_lxcars') {
                instructionsRef.value?.loadInstructions()
            } else if (data.table === 'oe_defects') {
                maengelRef.value?.loadMaengel()
            } else if (data.table === 'oe_parts_requests_lxcars') {
                loadPartsRequests()
            } else {
                reloadFakturaData()
            }
        } catch { /* kein relevantes Event */ }
    }
    sseSource.onerror = () => {}
}

onMounted(() => {
    loadFakturaData()
    connectSSE()
})

onBeforeUnmount(() => {
    if (sseSource) { sseSource.close(); sseSource = null }
})
</script>

<style scoped>
.mechanic-detail {
    min-height: 100vh;
    background: #f0f2f5;
}

.mechanic-detail__header {
    background: white;
    border-bottom: 1px solid #e0e0e0;
    position: sticky;
    top: 0;
    z-index: 5;
}

.mechanic-detail__content {
    max-width: 1400px;
}
</style>
