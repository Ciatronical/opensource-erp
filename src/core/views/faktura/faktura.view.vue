
<!-- src/core/views/faktura/faktura.view.vue -->

<template>
    <v-container fluid class="pa-0">
        <NavbarView />

        <v-container fluid class="faktura-container">
            <!-- Action Bar -->
            <section class="faktura-section">
                <action-bar-component
                    :printer-list="printerList"
                    :selected-printer="selectedPrinter"
                    :template-list="templateList"
                    :selected-template-id="selectedTemplate"
                    :faktura-type="fakturaType"
                    :closed="!!faktura.data?.common?.closed"
                    :show-closed="!!faktura.data && !['invoice', 'purchase_invoice'].includes(fakturaType)"
                    @toggle-closed="toggleClosed"
                    @save="saveFaktura"
                    @close="closeView"
                    @reuse="reuseFaktura"
                    @create-quotation="createQuotationFromFaktura"
                    @create-order="createOrderFromFaktura"
                    @create-delivery-order="createDeliveryOrderFromFaktura"
                    @create-invoice="createInvoiceFromFaktura"
                    @create-credit-note="createCreditNoteFromFaktura"
                    @cancel="cancelFaktura"
                    @create-supplier-inquiry="createSupplierInquiryFromFaktura"
                    @create-supplier-order="createSupplierOrderFromFaktura"
                    @create-complaint="createComplaintFromFaktura"
                    @save-as-draft="saveAsDraft"
                    @select-printer="selectPrinter"
                    @select-template="selectTemplate"
                    @print="printFaktura"
                    @pdf-preview="showPdfPreview"
                    @send-email="sendEmail"
                    @send-whatsapp="sendWhatsApp"
                    @create-dhl-label="openDhlDialog"
                    :show-dhl-button="dhlEnabled"
                    @show-on-display="showOnDisplay"
                    @show-history="showHistory"
                    @set-followup="setFollowUp"
                    :show-vehicle-voice="!!vehicle && !!vehicle.selectedCarId.value"
                    @voice-vehicle="onVoiceVehicle"
                    @export-xinvoice="exportXInvoice"
                    @delete="items.deleteFaktura"
                    :show-vehicle-button="!!vehicle && !!vehicle.selectedCarId.value"
                    :show-special-button="showSpecialButton"
                    :show-display-button="wallDisplayEnabled"
                    :has-customer="hasCustomer"
                    :compact-view="compactView"
                    @toggle-compact="compactView = !compactView"
                    @open-vehicle="openVehicleEdit"
                    @import-silverdat="onImportSilverdat"
                    @open-aag="onOpenAag"
                    :aag-loading="aagLoading"
                    :aag-configured="aagConfigured"
                    :esi-available="esiAvailable"
                    :gutmann-available="gutmannAvailable"
                    :hgs-available="hgsAvailable"
                    :hgs-loading="hgsLoading"
                    @open-esi="onOpenEsi"
                    @open-gutmann="onOpenGutmann"
                    @open-hgs="onOpenHgs"
                    @open-special="openSpecialDialog"
                />
            </section>

            <special-dialog
                v-if="showSpecialButton"
                v-model="specialDialogVisible"
                :car-id="vehicle?.selectedCarId?.value"
                :km-stand="vehicle?.oeExtData?.value?.km_stand"
            />

            <!-- Stammdaten: Kompakt oder vollständig -->
            <section class="faktura-section" v-if="faktura.data && faktura.data.common">

                <!-- Kompaktansicht -->
                <v-card v-if="compactView" variant="outlined" class="faktura-card" :class="{ 'section-disabled': !hasCustomer }">
                    <v-card-text class="py-2 px-4">
                        <div class="d-flex align-center flex-wrap ga-4">
                            <!-- Kundenname -->
                            <div class="d-flex align-center">
                                <v-icon size="small" color="primary" class="mr-1">mdi-account</v-icon>
                                <span class="font-weight-bold text-body-1">{{ customerName || '–' }}</span>
                            </div>
                            <!-- Belegnummer -->
                            <div class="d-flex align-center">
                                <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-pound</v-icon>
                                <span class="text-body-2">{{ compactDocNumber || '–' }}</span>
                            </div>
                            <!-- Datum -->
                            <div class="d-flex align-center">
                                <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-calendar</v-icon>
                                <span class="text-body-2">{{ compactTransdate || '–' }}</span>
                            </div>
                            <!-- Mitarbeiter -->
                            <div class="d-flex align-center">
                                <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-account-tie</v-icon>
                                <span class="text-body-2">{{ compactEmployeeName || '–' }}</span>
                            </div>
                            <v-divider vertical class="mx-1" />
                            <!-- Telefonnummern -->
                            <template v-if="contactPhone1">
                                <PhoneActionBar :phone="contactPhone1" :name="customerName" whatsapp-tab @whatsapp="switchToWhatsAppTabFromFaktura" :show-number="true" />
                            </template>
                            <template v-for="(entry, idx) in phoneNumbers" :key="'cpn-' + idx">
                                <div class="d-flex align-center">
                                    <PhoneActionBar :phone="entry.number" :name="customerName" whatsapp-tab @whatsapp="switchToWhatsAppTabFromFaktura" :show-number="true" />
                                    <span v-if="entry.label" class="text-caption text-grey ml-n1">{{ entry.label }}</span>
                                </div>
                            </template>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Vollständige Ansicht -->
                <v-row v-else>
                    <v-col cols="12" md="6" lg="4">
                        <customer-info-card
                            v-model="faktura.data.common"
                            :customer-list="customerList"
                            :delivery-address-list="deliveryAddressList"
                            :billing-address-list="billingAddressList"
                            :contact-phone1="contactPhone1"
                            :contact-phone2="contactPhone2"
                            :contact-email="contactEmail"
                            :contact-label="contactLabel"
                            :customer-name="customerName"
                            :phone-numbers="phoneNumbers"
                            :credit-limit="creditLimit"
                            :is-vendor="isVendor"
                            :has-customer="hasCustomer"
                            @update:customer-id="onCustomerChange"
                            @field-change="onFakturaFieldChange"
                        />
                    </v-col>

                    <v-col cols="12" md="6" lg="4" :class="{ 'section-disabled': !hasCustomer }">
                        <faktura-details-card
                            v-model="faktura.data.common"
                            :faktura-type="fakturaType"
                            @field-change="onFakturaFieldChange"
                        />
                    </v-col>

                    <v-col cols="12" md="6" lg="4" :class="{ 'section-disabled': !hasCustomer }">
                        <additional-info-card
                            v-model="faktura.data.common"
                            :ar-amount-list="accounting.arAmountList.value"
                            :currency-list="currencyList"
                            :tax-zone-list="taxZoneList"
                            :language-list="languageList"
                            :department-list="departmentList"
                            :employee-list="employeeList"
                            :payment-term-list="paymentTermList"
                            :delivery-term-list="deliveryTermList"
                            @field-change="onFakturaFieldChange"
                        />
                    </v-col>
                </v-row>
            </section>

            <!-- Fahrzeug / Auftragsdaten (lxcars Feature, bei Auftrag/Angebot/Rechnung) -->
            <section class="faktura-section" v-if="vehicle && (fakturaType === 'order' || fakturaType === 'quotation' || fakturaType === 'invoice') && faktura.data" :class="{ 'section-disabled': !hasCustomer }">
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

            <!-- Wartung & Service (lxcars Feature, nur bei Auftrag, nicht bei Anhängern) -->
            <section class="faktura-section" v-if="vehicle && fakturaType === 'order' && faktura.data && !vehicle.isTrailer.value && wartungEnabled" :class="{ 'section-disabled': !hasCustomer }">
                <maintenance-section-card
                    :oe-ext-data="vehicle.oeExtData.value"
                    :has-car="!!vehicle.selectedCarId.value"
                    :show-voice="false"
                    @oe-ext-field-change="vehicle.onOeExtFieldChange"
                />
            </section>

            <!-- Arbeitsanweisungen (lxcars Feature, nur bei Auftrag) -->
            <section class="faktura-section" v-if="vehicle && fakturaType === 'order' && faktura.data" :class="{ 'section-disabled': !hasCustomer }">
                <instructions-section-card
                    ref="instructionsRef"
                    :oe-id="fakturaId"
                    :ensure-oe-id="ensureOrderAndGetId"
                    :completion-validator="validateMaintenanceBeforeComplete"
                    @jump-to-positions="focusNewPosition"
                />
            </section>

            <!-- Positionen -->
            <section class="faktura-section" v-if="faktura.data" :class="{ 'section-disabled': !hasCustomer }">
                <faktura-items-table-component
                    ref="itemsTableRef"
                    v-model="fakturaItems"
                    :article-list="articleList"
                    :article-loading="articleLoading"
                    :net-amount="accounting.calculatedNetAmount.value"
                    :gross-amount="accounting.calculatedGrossAmount.value"
                    :tax-breakdown="accounting.taxBreakdown.value"
                    :taxincluded="!!faktura.data.common.taxincluded"
                    @update:taxincluded="onTaxIncludedChange"
                    :calculate-item-total="accounting.calculateItemTotal"
                    :calculate-totals="accounting.calculateTotals"
                    :show-ai-suggest="showAiSuggest"
                    :ai-loading="aiLoading"
                    @article-search="items.onArticleSearch"
                    @article-select="items.onArticleSelect"
                    @article-replace="items.onArticleReplace"
                    @create-article="items.createArticle"
                    @delete-item="items.deleteItem"
                    @delete-selected="items.deleteSelectedItems"
                    @edit-article="items.editArticle"
                    @set-item-discount="items.setItemDiscount"
                    @set-all-discounts="items.setAllDiscounts"
                    @add-new-row="items.addNewItemRow"
                    @items-changed="saveAllItems"
                    @ai-suggest="onAiSuggest"
                    :show-parts-requests="fakturaType === 'order' && (mechanicModeEnabled || partsRequestsList.length > 0)"
                    :parts-requests="partsRequestsList"
                    :recent-vendors="recentVendorsList"
                    @request-part="onRequestPart"
                    @order-part="onOrderPart"
                    @revert-part="onRevertPart"
                    @delete-part="onDeletePart"
                    @photo-part="onPhotoPart"
                />
            </section>

            <!-- Mängel (lxcars Feature, bei Aufträgen, Angeboten und Rechnungen) -->
            <section class="faktura-section" v-if="vehicle && (fakturaType === 'order' || fakturaType === 'invoice' || fakturaType === 'quotation') && faktura.data && wartungEnabled" :class="{ 'section-disabled': !hasCustomer }">
                <maengel-section-card ref="maengelRef" :oe-id="fakturaId" :ensure-oe-id="ensureOrderAndGetId" :doc-type="fakturaType === 'invoice' ? 'invoice' : 'order'" />
            </section>

            <!-- Zahlungen (nur bei Rechnungen) -->
            <section class="faktura-section" v-if="fakturaType === 'invoice' && faktura.data">
                <PaymentSectionCard
                    v-model="paymentList"
                    :payment-acc-list="paymentAccList"
                    :gross-amount="accounting.calculatedGrossAmount.value"
                    :show-exchange-rate="accounting.isForeignCurrency.value"
                    :invnumber="faktura.data.common?.invnumber || ''"
                    @save="() => saveAllItems(true)"
                />
            </section>

            <!-- Notizen -->
            <section class="faktura-section" v-if="faktura.data && faktura.data.common" :class="{ 'section-disabled': !hasCustomer }">
                <v-card variant="outlined" class="faktura-card">
                    <v-card-title class="faktura-card__header">
                        <v-icon class="mr-2" size="small">mdi-note-text</v-icon>
                        {{ t('FakturaView.faktura.notes') }}
                    </v-card-title>
                    <v-divider />
                    <v-card-text class="faktura-card__body">
                        <v-row>
                            <v-col cols="12" md="3">
                                <v-textarea
                                    v-model="customerNotes"
                                    :label="t('FakturaView.faktura.customerNotes')"
                                    variant="outlined"
                                    density="compact"
                                    rows="6"
                                    hide-details
                                    autocomplete="off"
                                    readonly
                                    bg-color="grey-lighten-4"
                                />
                            </v-col>
                            <v-col cols="12" md="3" v-if="vehicle">
                                <v-textarea
                                    v-model="vehicle.vehicleNotes.value"
                                    :label="t('FakturaView.faktura.vehicleNotes')"
                                    variant="outlined"
                                    density="compact"
                                    rows="6"
                                    hide-details
                                    autocomplete="off"
                                    readonly
                                    bg-color="grey-lighten-4"
                                />
                            </v-col>
                            <v-col cols="12" md="3">
                                <v-textarea
                                    v-model="faktura.data.common.intnotes"
                                    @blur="onFakturaFieldChange('intnotes', faktura.data.common.intnotes)"
                                    :label="t('FakturaView.faktura.internalNotes')"
                                    variant="outlined"
                                    density="compact"
                                    rows="6"
                                    hide-details
                                    autocomplete="off"
                                />
                            </v-col>
                            <v-col cols="12" md="3">
                                <html-editor-component
                                    v-model="faktura.data.common.notes"
                                    :label="t('FakturaView.faktura.publicNotes')"
                                    @blur="onFakturaFieldChange('notes', faktura.data.common.notes)"
                                />
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </section>
        </v-container>

        <!-- km-Stand Plausibilitäts-Dialog -->
        <v-dialog v-model="kmPlausibilityDialog.show" max-width="520" @keydown.esc="kmPlausibilityDialog.show = false">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-warning">
                    <v-icon class="mr-2">mdi-speedometer-slow</v-icon>
                    {{ t('FakturaView.faktura.kmPlausibility.title') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="kmPlausibilityDialog.show = false" />
                </v-card-title>
                <v-card-text class="pt-4 pb-2">
                    <p>{{ t('FakturaView.faktura.kmPlausibility.text', { current: kmPlausibilityDialog.currentKm?.toLocaleString('de-DE'), last: kmPlausibilityDialog.lastKm?.toLocaleString('de-DE') }) }}</p>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="kmPlausibilityDialog.show = false">
                        {{ t('FakturaView.faktura.kmPlausibility.cancel') }}
                    </v-btn>
                    <v-btn color="warning" variant="elevated" prepend-icon="mdi-check-bold" @click="kmPlausibilityDialog.show = false; convertAndNavigate('invoice')">
                        {{ t('FakturaView.faktura.kmPlausibility.proceed') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Item Confirmation Dialog -->
        <v-dialog v-model="items.deleteDialog.value.show" max-width="400" @keydown.esc="items.deleteDialog.value.show = false">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-error text-white">
                    <v-icon class="mr-2">mdi-delete-alert</v-icon>
                    {{ t('FakturaView.dialogs.deleteItem.title') }}
                    <v-spacer />
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="small"
                        @click="items.deleteDialog.value.show = false"
                    />
                </v-card-title>
                <v-card-text class="pt-4 pb-2">
                    <p>{{ t('FakturaView.dialogs.deleteItem.text') }}</p>
                    <p v-if="items.deleteDialog.value.item" class="mt-2 font-weight-medium">
                        {{ items.deleteDialog.value.item.partnumber }} - {{ items.deleteDialog.value.item.description }}
                    </p>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        @click="items.deleteDialog.value.show = false"
                    >
                        {{ t('FakturaView.dialogs.deleteItem.cancel') }}
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="elevated"
                        prepend-icon="mdi-delete"
                        @click="items.confirmDeleteItem"
                    >
                        {{ t('FakturaView.dialogs.deleteItem.confirm') }}
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
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="small"
                        @click="items.bulkDeleteDialog.value.show = false"
                    />
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
                    <v-btn
                        variant="text"
                        @click="items.bulkDeleteDialog.value.show = false"
                    >
                        {{ t('FakturaView.dialogs.deleteBulk.cancel') }}
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="elevated"
                        prepend-icon="mdi-delete-sweep"
                        @click="items.confirmBulkDeleteItems"
                    >
                        {{ t('FakturaView.dialogs.deleteBulk.confirm', { count: items.bulkDeleteDialog.value.items.length }) }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Faktura Confirmation Dialog -->
        <v-dialog v-model="items.deleteFakturaDialog.value.show" max-width="500" persistent>
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-error text-white">
                    <v-icon class="mr-2">mdi-alert-octagon</v-icon>
                    {{ t('FakturaView.dialogs.deleteFaktura.title') }}
                    <v-spacer />
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="small"
                        @click="items.deleteFakturaDialog.value.show = false"
                    />
                </v-card-title>
                <v-card-text class="pt-4 pb-2">
                    <p class="text-h6 mb-3">{{ t('FakturaView.dialogs.deleteFaktura.text') }}</p>
                    <v-alert
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mb-3"
                    >
                        {{ t('FakturaView.dialogs.deleteFaktura.warning') }}
                    </v-alert>
                    <p v-if="faktura.data?.common" class="mt-2 font-weight-medium">
                        {{ t(`FakturaView.dokumentTypes.${fakturaType}`) }} #{{ faktura.data.common.invnumber || faktura.data.common.ordnumber || faktura.data.common.quonumber }}
                    </p>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        @click="items.deleteFakturaDialog.value.show = false"
                    >
                        {{ t('FakturaView.dialogs.deleteFaktura.cancel') }}
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="elevated"
                        prepend-icon="mdi-delete-forever"
                        @click="items.confirmDeleteFaktura"
                    >
                        {{ t('FakturaView.dialogs.deleteFaktura.confirm') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Storno Dialog -->
        <v-dialog v-model="showStornoDialog" max-width="500" persistent>
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-warning text-white">
                    <v-icon class="mr-2">mdi-cancel</v-icon>
                    {{ t('FakturaView.dialogs.storno.title') }}
                    <v-spacer />
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="small"
                        @click="showStornoDialog = false"
                    />
                </v-card-title>
                <v-card-text class="pt-4 pb-2">
                    <p class="text-h6 mb-3">{{ t('FakturaView.dialogs.storno.text') }}</p>
                    <v-alert
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mb-3"
                    >
                        {{ t('FakturaView.dialogs.storno.warning') }}
                    </v-alert>
                    <p v-if="faktura.data?.common" class="mt-2 font-weight-medium">
                        {{ t('FakturaView.dokumentTypes.invoice') }} #{{ faktura.data.common.invnumber }}
                    </p>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        @click="showStornoDialog = false"
                    >
                        {{ t('FakturaView.dialogs.storno.cancel') }}
                    </v-btn>
                    <v-btn
                        color="warning"
                        variant="elevated"
                        prepend-icon="mdi-cancel"
                        @click="confirmStorno"
                    >
                        {{ t('FakturaView.dialogs.storno.confirm') }}
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

        <!-- Send Email Dialog -->
        <send-email-dialog
            v-model="emailDialogVisible"
            :initial-to="emailDialogTo"
            :initial-subject="emailDialogSubject"
            :initial-body="emailDialogBody"
            :attachment-name="emailDialogAttachmentName"
            @send="onEmailSend"
            @cancel="emailDialogVisible = false"
        />

        <!-- Send WhatsApp Dialog -->
        <v-dialog v-model="waDialogVisible" max-width="600" persistent>
            <v-card>
                <v-card-title class="d-flex align-center bg-green-darken-1">
                    <v-icon class="mr-2">mdi-whatsapp</v-icon>
                    {{ t('FakturaView.dialogs.sendWhatsApp.title') }}
                    <v-spacer />
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="x-small"
                        @click="waDialogVisible = false"
                    />
                </v-card-title>

                <v-card-text class="pt-4">
                    <v-row dense>
                        <v-col cols="12">
                            <v-select
                                v-if="waPhoneOptions.length > 1"
                                v-model="waDialogPhone"
                                :items="waPhoneOptions"
                                :label="t('FakturaView.dialogs.sendWhatsApp.phone')"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-phone"
                                :rules="[v => !!v || t('FakturaView.dialogs.sendWhatsApp.phoneRequired')]"
                            />
                            <v-text-field
                                v-else
                                v-model="waDialogPhone"
                                :label="t('FakturaView.dialogs.sendWhatsApp.phone')"
                                variant="outlined"
                                density="compact"
                                autocomplete="off"
                                prepend-inner-icon="mdi-phone"
                                :rules="[v => !!v || t('FakturaView.dialogs.sendWhatsApp.phoneRequired')]"
                            />
                        </v-col>

                        <!-- Template Parameter Fields -->
                        <v-col v-if="waSelectedTemplate" cols="12">
                            <v-text-field
                                v-for="(param, index) in waTemplateParams"
                                :key="index"
                                v-model="param.value"
                                :label="param.label"
                                variant="outlined"
                                density="compact"
                                class="mb-1"
                            />
                        </v-col>

                        <!-- Template Preview -->
                        <v-col v-if="waSelectedTemplate" cols="12">
                            <v-alert
                                type="info"
                                variant="tonal"
                                density="compact"
                                :title="t('FakturaView.dialogs.sendWhatsApp.templatePreview')"
                            >
                                <div class="text-body-2" style="white-space: pre-wrap;">{{ waRenderedPreview }}</div>
                            </v-alert>
                        </v-col>

                        <v-col cols="12">
                            <v-chip color="green" variant="tonal" prepend-icon="mdi-paperclip">
                                {{ waDialogAttachmentName }}
                            </v-chip>
                        </v-col>
                    </v-row>
                </v-card-text>

                <v-divider />

                <v-card-actions class="pa-4">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        @click="waDialogVisible = false"
                    >
                        {{ t('FakturaView.dialogs.sendWhatsApp.cancel') }}
                    </v-btn>
                    <v-btn
                        color="green-darken-1"
                        variant="elevated"
                        prepend-icon="mdi-whatsapp"
                        :disabled="!waDialogPhone || !waSelectedTemplate"
                        :loading="pdfLoading"
                        @click="onWhatsAppSend({ phone: waDialogPhone })"
                    >
                        {{ t('FakturaView.dialogs.sendWhatsApp.send') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- DHL Versandetikett Dialog -->
        <v-dialog v-model="dhlDialogVisible" max-width="550" persistent>
            <v-card>
                <v-card-title class="d-flex align-center bg-amber-darken-3">
                    <v-icon class="mr-2">mdi-package-variant-closed</v-icon>
                    {{ t('FakturaView.dialogs.dhl.title') }}
                    <v-spacer />
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="x-small"
                        @click="dhlDialogVisible = false"
                    />
                </v-card-title>

                <v-card-text class="pt-4">
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field
                                v-model="dhlRecipientDisplay"
                                :label="t('FakturaView.dialogs.dhl.recipient')"
                                variant="outlined"
                                density="compact"
                                prepend-inner-icon="mdi-account"
                                readonly
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model="dhlWeight"
                                :label="t('FakturaView.dialogs.dhl.weight')"
                                variant="outlined"
                                density="compact"
                                type="number"
                                step="0.1"
                                min="0.1"
                                suffix="kg"
                                prepend-inner-icon="mdi-weight-kilogram"
                                :rules="[v => !!v && parseFloat(v) > 0 || t('FakturaView.dialogs.dhl.weightRequired')]"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-select
                                v-model="dhlProduct"
                                :label="t('FakturaView.dialogs.dhl.product')"
                                variant="outlined"
                                density="compact"
                                :items="dhlProducts"
                                item-title="title"
                                item-value="value"
                            />
                        </v-col>
                        <v-col cols="12">
                            <span class="text-caption text-grey">{{ t('FakturaView.dialogs.dhl.dimensions') }}</span>
                        </v-col>
                        <v-col cols="4">
                            <v-text-field
                                v-model="dhlLength"
                                :label="t('FakturaView.dialogs.dhl.length')"
                                variant="outlined"
                                density="compact"
                                type="number"
                                suffix="cm"
                            />
                        </v-col>
                        <v-col cols="4">
                            <v-text-field
                                v-model="dhlWidth"
                                :label="t('FakturaView.dialogs.dhl.width')"
                                variant="outlined"
                                density="compact"
                                type="number"
                                suffix="cm"
                            />
                        </v-col>
                        <v-col cols="4">
                            <v-text-field
                                v-model="dhlHeight"
                                :label="t('FakturaView.dialogs.dhl.height')"
                                variant="outlined"
                                density="compact"
                                type="number"
                                suffix="cm"
                            />
                        </v-col>
                    </v-row>

                    <!-- Bestehende Sendungen -->
                    <v-list v-if="dhlExistingShipments.length > 0" density="compact" class="mt-2">
                        <v-list-subheader>{{ t('FakturaView.dialogs.dhl.existingShipments') }}</v-list-subheader>
                        <v-list-item
                            v-for="s in dhlExistingShipments"
                            :key="s.id"
                            prepend-icon="mdi-package-variant-closed-check"
                        >
                            <v-list-item-title>
                                <a :href="'https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html?piececode=' + s.shipment_no" target="_blank" class="text-decoration-none">
                                    {{ s.shipment_no }}
                                </a>
                            </v-list-item-title>
                            <v-list-item-subtitle>{{ s.product }} · {{ s.weight }} kg · {{ s.created_at }}</v-list-item-subtitle>
                            <template #append>
                                <v-btn icon="mdi-download" size="x-small" variant="text" @click="downloadDhlLabel(s.shipment_no)" />
                                <v-btn icon="mdi-delete" size="x-small" variant="text" color="error" @click="onDhlDelete(s.shipment_no)" />
                            </template>
                        </v-list-item>
                    </v-list>
                </v-card-text>

                <v-card-actions class="px-4 pb-4">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        @click="dhlDialogVisible = false"
                    >
                        {{ t('FakturaView.common.cancel') }}
                    </v-btn>
                    <v-btn
                        color="amber-darken-3"
                        variant="elevated"
                        prepend-icon="mdi-package-variant-closed"
                        :disabled="!dhlWeight || parseFloat(dhlWeight) <= 0"
                        :loading="dhlLoading"
                        @click="onDhlCreate"
                    >
                        {{ t('FakturaView.dialogs.dhl.create') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Instructions Incomplete Dialog -->
        <v-dialog v-model="instructionsIncompleteDialog.show" max-width="500" @keydown.esc="instructionsIncompleteDialog.show = false">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-warning">
                    <v-icon class="mr-2">mdi-clipboard-alert-outline</v-icon>
                    {{ t('FakturaView.faktura.instructions.incompleteTitle') }}
                    <v-spacer />
                    <v-btn
                        icon="mdi-close"
                        variant="text"
                        density="compact"
                        size="small"
                        @click="instructionsIncompleteDialog.show = false"
                    />
                </v-card-title>
                <v-card-text class="pt-4 pb-2">
                    <p>{{ t('FakturaView.faktura.instructions.incompleteText') }}</p>
                    <p class="mt-3 font-weight-medium">{{ t('FakturaView.faktura.instructions.incompleteList') }}</p>
                    <v-list density="compact" class="mt-1">
                        <v-list-item
                            v-for="instr in instructionsIncompleteDialog.items"
                            :key="instr.id"
                            class="px-2"
                        >
                            <template #prepend>
                                <v-icon size="small" color="warning">mdi-alert-circle</v-icon>
                            </template>
                            <v-list-item-title class="text-body-2">
                                #{{ instr.instruction_number }} — {{ instr.description }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                <span v-if="!instr.employee_id" class="text-error mr-3">{{ t('FakturaView.faktura.instructions.completedBy') }}</span>
                                <span v-if="!instr.actual_minutes" class="text-error">{{ t('FakturaView.faktura.instructions.actualTime') }}</span>
                            </v-list-item-subtitle>
                        </v-list-item>
                    </v-list>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn
                        color="primary"
                        variant="elevated"
                        @click="instructionsIncompleteDialog.show = false"
                    >
                        {{ t('FakturaView.common.ok') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

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
                    <v-btn
                        color="primary"
                        variant="elevated"
                        @click="maintenanceIncompleteDialog.show = false"
                    >
                        {{ t('MaintenanceSectionCard.close') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Create Item Dialog -->
        <create-part-dialog
            v-model="items.createDialog.value.show"
            :search-text="items.createDialog.value.searchText"
            :item-index="items.createDialog.value.itemIndex"
            @save="items.onCreateArticleSave"
        />

        <!-- SilverDAT Import: verstecktes Dateifeld – Dateidialog erscheint sofort beim Klick -->
        <input
            v-if="silverdatImport"
            ref="silverdatFileInput"
            type="file"
            accept=".xml"
            class="d-none"
            @change="onSilverdatFileSelect"
        />

        <!-- SilverDAT Import Dialog (Vorschau nach Dateiauswahl) -->
        <silverdat-import-dialog
            v-if="silverdatImport"
            v-model="silverdatImport.showDialog.value"
            :import-items="silverdatImport.importItems.value"
            :vehicle-info="silverdatImport.vehicleInfo.value"
            :importing="silverdatImport.importing.value"
            :import-error="silverdatImport.importError.value"
            :file-name="silverdatImport.fileName.value"
            :summary="silverdatImport.summary.value"
            @do-import="onSilverdatImport"
            @close="onSilverdatClose"
            @change-file="onImportSilverdat"
            @clear-error="silverdatImport.importError.value = ''"
        />

        <!-- KI-Positionsvorschläge Dialog -->
        <suggest-positions-dialog
            v-model="aiDialogVisible"
            :items="aiSuggestedItems"
            @confirmed="onAiConfirmed"
            @skipped="aiDialogVisible = false"
        />
        <!-- Foto-Dialog für Ersatzteil (Multi-Foto-Galerie) -->
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
                    <!-- Foto-Galerie -->
                    <div v-if="photoDialog.photos.length" class="d-flex flex-wrap ga-2 mb-4">
                        <div
                            v-for="(photo, idx) in photoDialog.photos"
                            :key="photo.path"
                            class="position-relative"
                            style="width: 130px; height: 130px"
                        >
                            <v-img
                                :src="'data:image/jpeg;base64,' + photo.image"
                                width="130"
                                height="130"
                                cover
                                class="rounded border"
                                style="cursor: zoom-in"
                                @click="openFullscreen(idx)"
                            />
                            <v-btn
                                icon="mdi-close-circle"
                                size="x-small"
                                color="red"
                                variant="flat"
                                class="position-absolute"
                                style="top: -6px; right: -6px; z-index: 1"
                                @click.stop="deletePhoto(photo.path)"
                            />
                        </div>
                    </div>
                    <div v-else class="text-center text-medium-emphasis py-4 mb-3">
                        <v-icon size="48" color="grey-lighten-1">mdi-camera-off</v-icon>
                        <div class="text-body-2 mt-2">{{ t('FakturaView.faktura.noPhotos') }}</div>
                    </div>
                    <!-- Upload-Bereich -->
                    <v-file-input
                        :key="photoDialog.uploadKey"
                        :label="t('FakturaView.faktura.selectPhoto')"
                        accept="image/*"
                        capture="environment"
                        prepend-icon="mdi-camera"
                        variant="outlined"
                        density="compact"
                        hide-details
                        show-size
                        @update:model-value="onPhotoFileSelected"
                    />
                    <div v-if="photoDialog.uploading" class="text-center mt-3">
                        <v-progress-circular indeterminate size="24" color="primary" />
                    </div>
                </v-card-text>
                <v-divider />
                <v-card-actions class="justify-end pa-3">
                    <v-btn
                        color="primary"
                        variant="flat"
                        prepend-icon="mdi-check"
                        @click="closePhotoDialog"
                    >
                        {{ t('FakturaView.faktura.photoDone') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Fullscreen Foto-Viewer mit Navigation -->
        <v-dialog v-model="photoDialog.fullscreen" max-width="90vw">
            <v-card class="pa-0 position-relative" style="background: #000">
                <v-btn
                    icon="mdi-close"
                    size="small"
                    variant="text"
                    color="white"
                    class="position-absolute"
                    style="top: 8px; right: 8px; z-index: 2"
                    @click="photoDialog.fullscreen = false"
                />
                <v-btn
                    v-if="photoDialog.photos.length > 1"
                    icon="mdi-chevron-left"
                    size="large"
                    variant="text"
                    color="white"
                    class="position-absolute"
                    style="top: 50%; left: 4px; transform: translateY(-50%); z-index: 2"
                    @click.stop="photoDialog.fullscreenIdx = (photoDialog.fullscreenIdx - 1 + photoDialog.photos.length) % photoDialog.photos.length"
                />
                <v-btn
                    v-if="photoDialog.photos.length > 1"
                    icon="mdi-chevron-right"
                    size="large"
                    variant="text"
                    color="white"
                    class="position-absolute"
                    style="top: 50%; right: 4px; transform: translateY(-50%); z-index: 2"
                    @click.stop="photoDialog.fullscreenIdx = (photoDialog.fullscreenIdx + 1) % photoDialog.photos.length"
                />
                <v-img
                    v-if="photoDialog.photos[photoDialog.fullscreenIdx]"
                    :src="'data:image/jpeg;base64,' + photoDialog.photos[photoDialog.fullscreenIdx].image"
                    max-height="85vh"
                    contain
                />
                <div v-if="photoDialog.photos.length > 1" class="text-center pa-2" style="color: white; font-size: 0.8rem">
                    {{ photoDialog.fullscreenIdx + 1 }} / {{ photoDialog.photos.length }}
                </div>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script>
import { defineComponent } from 'vue'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import CustomerInfoCard from './cards/customer.info.card.vue'
import FakturaDetailsCard from './cards/faktura.details.card.vue'
import AdditionalInfoCard from './cards/additional.info.card.vue'
import VehicleSectionCard from './cards/vehicle.section.card.vue'
import ActionBarComponent from './components/action.bar.component.vue'
import FakturaItemsTableComponent from './components/faktura.items.table.component.vue'
import EditPartDialog from './dialogs/edit.part.dialog.vue'
import CreatePartDialog from './dialogs/create.part.dialog.vue'
import SendEmailDialog from './dialogs/send.email.dialog.vue'
import SilverdatImportDialog from './dialogs/silverdat.import.dialog.vue'
import PaymentSectionCard from './cards/payment.section.card.vue'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'
import InstructionsSectionCard from '@/features/lxcars/components/instructions.section.card.vue'
import MaengelSectionCard from '@/features/lxcars/components/maengel.section.card.vue'
import MaintenanceSectionCard from '@/features/lxcars/components/maintenance.section.card.vue'
import SuggestPositionsDialog from '@/features/lxcars/components/suggest-positions-dialog.vue'
import PhoneActionBar from '@/core/components/phone-action-bar.vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { fakturaStore } from '@/core/stores/faktura.store.js'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import { useAccounting } from './composables/useAccounting.js'
import { useItemManagement } from './composables/useItemManagement.js'
import { useVehicleSection } from './composables/useVehicleSection.js'
import { useSilverDATImport } from './composables/useSilverDATImport.js'
import { onMounted, onBeforeUnmount, ref, computed, nextTick, defineAsyncComponent, shallowRef, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'
import { openAppWindow, aagWindowOpen, setAagWindowCarId } from '@/core/utils/aagWindow.js'
import { hasVehicleId, isKbaValid, buildEsiUrl, buildGutmannUrl } from '@/core/utils/diagLinks.js'
import { parseMonthYear, formatMonthYear } from '@/features/lxcars/utils/validation.js'

const specialDialogModules = import.meta.glob('../special/special.dialog.vue')
const SpecialDialog = specialDialogModules['../special/special.dialog.vue']
    ? defineAsyncComponent(specialDialogModules['../special/special.dialog.vue'])
    : { render: () => null }

export default defineComponent({
    name: 'FakturaView',
    components: {
        NavbarView,
        CustomerInfoCard,
        FakturaDetailsCard,
        AdditionalInfoCard,
        VehicleSectionCard,
        ActionBarComponent,
        FakturaItemsTableComponent,
        EditPartDialog,
        CreatePartDialog,
        SendEmailDialog,
        SilverdatImportDialog,
        PaymentSectionCard,
        HtmlEditorComponent,
        InstructionsSectionCard,
        MaengelSectionCard,
        MaintenanceSectionCard,
        SuggestPositionsDialog,
        SpecialDialog,
        PhoneActionBar
    },
    props: {
        fakturaID: {
            type: [Number, String],
            required: false,
            default: null,
        },
    },
    setup(props) {
        const route = useRoute()
        const router = useRouter()
        const { t, locale } = useI18n()
        const oserp = oserpStore()
        const faktura = fakturaStore()
        const carsStore = oserp.isLxCars() ? lxcarsStore() : null

        // Dokumenttyp aus der Route ermitteln (kann durch Backend-Antwort überschrieben werden)
        const fakturaTypeOverride = ref(null)
        const fakturaType = computed(() => {
            if (fakturaTypeOverride.value) return fakturaTypeOverride.value
            if (route.meta && route.meta.documentType) {
                return route.meta.documentType
            }
            const pathSegments = route.path.split('/')
            const typeIndex = pathSegments.indexOf('faktura') + 1
            if (typeIndex > 0 && pathSegments[typeIndex]) {
                return pathSegments[typeIndex]
            }
            return 'invoice'
        })

        const fakturaId = ref(props.fakturaID || Number(route.params.id) || null)

        const isVendor = computed(() => {
            return ['purchase_order', 'purchase_invoice', 'request_quotation'].includes(fakturaType.value)
        })

        const hasCustomer = computed(() => {
            return !!(faktura.data?.common?.customer_id || faktura.data?.common?.vendor_id)
        })

        // Storno-Dialog
        const showStornoDialog = ref(false)

        // Basis-Refs
        const itemsTableRef = ref(null)
        const instructionsRef = ref(null)
        const maengelRef = ref(null)
        const fakturaItems = ref([])
        const paymentList = ref([])
        const customerList = ref([])
        const articleList = ref([])
        const articleLoading = ref(false)

        // Dropdown-Listen
        const deliveryAddressList = ref([])
        const billingAddressList = ref([])
        const currencyList = ref([])
        const taxZoneList = ref([])
        const languageList = ref([])
        const departmentList = ref([])
        const employeeList = ref([])
        const printerList = ref([])
        const templateList = ref([])
        const selectedTemplate = ref(null)
        const selectedPrinter = ref(null)
        const pdfLoading = ref(false)
        const paymentTermList = ref([])
        const deliveryTermList = ref([])
        const paymentAccList = ref([])
        const pendingRouteReplace = ref(null)

        // Kontaktdaten
        const contactPhone1 = ref('')
        const contactPhone2 = ref('')
        const contactEmail = ref('')
        const contactLabel = ref('')
        const customerName = ref('')
        const phoneNumbers = ref([])
        const creditLimit = ref(0)
        const customerNotes = ref('')

        // Kompaktansicht (gespeichert in employee_config_oserp)
        const rawCompact = oserp.getConfigValue('faktura_compact_view', false)
        const compactView = ref(rawCompact === true || rawCompact === 'true' || rawCompact === 't' || rawCompact === '1')

        watch(compactView, (val) => {
            oserp.setConfigValue('faktura_compact_view', val)
        })

        const compactDocNumber = computed(() => {
            const c = faktura.data?.common
            if (!c) return ''
            switch (fakturaType.value) {
                case 'invoice': case 'purchase_invoice': return c.invnumber
                case 'order': case 'purchase_order': return c.ordnumber
                case 'quotation': case 'request_quotation': return c.quonumber
                case 'delivery_order': return c.donumber
                default: return c.invnumber
            }
        })

        const compactTransdate = computed(() => {
            const d = faktura.data?.common?.transdate
            if (!d) return ''
            const parts = d.split('-')
            if (parts.length === 3) return `${parts[2]}.${parts[1]}.${parts[0]}`
            return d
        })

        const compactEmployeeName = computed(() => {
            const empId = faktura.data?.common?.employee_id
            if (!empId) return ''
            const emp = employeeList.value.find(e => e.id === empId)
            return emp?.name || ''
        })

        function switchToWhatsAppTabFromFaktura(phone) {
            const cvId = isVendor.value
                ? faktura.data?.common?.vendor_id
                : faktura.data?.common?.customer_id
            if (!cvId) return
            const routeName = isVendor.value ? 'change-vendor' : 'change-customer'
            router.push({
                name: routeName,
                params: { id: cvId },
                query: { tab: 'whatsapp', whatsappPhone: phone }
            })
        }

        // ===== Composables =====

        const accounting = useAccounting({
            fakturaItems, faktura, fakturaType, paymentList, oserp, currencyList
        })

        const vehicle = carsStore ? useVehicleSection({
            carsStore, fakturaId, fakturaType, t
        }) : null

        // Fahrzeug-Dropdown fokussieren bei mehreren Fahrzeugen nach Kundenwechsel
        const vehicleSectionRef = ref(null)
        if (vehicle) {
            watch(() => vehicle.shouldFocusCar.value, (shouldFocus) => {
                if (shouldFocus) {
                    vehicle.shouldFocusCar.value = false
                    nextTick(() => {
                        vehicleSectionRef.value?.focusCarSelect()
                    })
                }
            })
        }

        // SilverDAT Import (nur bei aktivem LxCars)
        const silverdatImport = carsStore ? useSilverDATImport({ t }) : null

        const specialDialogVisible = ref(false)
        const wallDisplayEnabled = computed(() => {
            const val = oserp.getClientDefaultValue('wall_display_enabled', false)
            return val === true || val === 'true' || val === 't' || val === '1'
        })

        const dhlEnabled = computed(() => {
            const val = oserp.getClientDefaultValue('dhl_enabled', false)
            return val === true || val === 'true' || val === 't' || val === '1'
        })

        // AAG-Online nur anbieten, wenn Zugangsdaten hinterlegt sind
        const aagConfigured = computed(() =>
            !!String(oserp.getClientDefaultValue('aag_online_user', '') || '').trim() &&
            !!String(oserp.getClientDefaultValue('aag_online_passwd', '') || '').trim()
        )

        const mechanicModeEnabled = computed(() => {
            if (!oserp.isLxCars()) return false
            const val = oserp.getClientDefaultValue('lxcars_mechanic_mode', false)
            return val === true || val === 'true' || val === 't' || val === '1'
        })

        const wartungEnabled = computed(() => {
            if (!oserp.isLxCars()) return false
            const val = oserp.getClientDefaultValue('lxcars_wartung_enabled', true)
            if (val === null || val === undefined || val === '') return true
            return val === true || val === 'true' || val === 't' || val === '1'
        })

        // Ersatzteil-Bestellstatus pro Position
        const partsRequestsList = ref([])

        async function loadPartsRequests() {
            if (!carsStore || !fakturaId.value || fakturaType.value !== 'order') return
            try {
                partsRequestsList.value = await carsStore.getPartsRequestsByOrder(fakturaId.value)
            } catch { partsRequestsList.value = [] }
        }

        async function onRequestPart(item) {
            if (!fakturaId.value || !item.id) return
            try {
                await carsStore.requestPartsForItem(fakturaId.value, item.id)
                loadPartsRequests()
                window.dispatchEvent(new CustomEvent('parts-requests-changed'))
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
                window.dispatchEvent(new CustomEvent('parts-requests-changed'))
            } catch (e) {
                console.error('Error ordering part:', e)
            }
        }

        async function onRevertPart(item) {
            const req = partsRequestsList.value.find(r => r.orderitem_id === item.id)
            if (!req) return
            try {
                await carsStore.revertPartsRequestToPending(req.id)
                loadPartsRequests()
                window.dispatchEvent(new CustomEvent('parts-requests-changed'))
            } catch (e) {
                console.error('Error reverting part:', e)
            }
        }

        async function onDeletePart(item) {
            const req = partsRequestsList.value.find(r => r.orderitem_id === item.id)
            if (!req) return
            try {
                await carsStore.deletePartsRequest(req.id)
                loadPartsRequests()
                window.dispatchEvent(new CustomEvent('parts-requests-changed'))
            } catch (e) {
                console.error('Error deleting part:', e)
            }
        }

        // Foto-Dialog (Multi-Foto)
        const photoDialog = ref({
            show: false,
            fullscreen: false,
            fullscreenIdx: 0,
            requestId: null,
            photos: [],
            uploading: false,
            uploadKey: 0
        })

        async function onPhotoPart(item) {
            const req = partsRequestsList.value.find(r => r.orderitem_id === item.id)
            if (!req) return
            photoDialog.value = { show: true, fullscreen: false, fullscreenIdx: 0, requestId: req.id, photos: [], uploading: false, uploadKey: 0 }
            // Vorhandene Fotos laden
            if (req.photo) {
                try {
                    const result = await carsStore.getPartsRequestPhoto(req.id)
                    photoDialog.value.photos = result.photos || []
                } catch { /* keine Fotos vorhanden */ }
            }
        }

        function closePhotoDialog() {
            photoDialog.value.show = false
            photoDialog.value.fullscreen = false
        }

        function openFullscreen(idx) {
            photoDialog.value.fullscreenIdx = idx
            photoDialog.value.fullscreen = true
        }

        async function onPhotoFileSelected(files) {
            const file = Array.isArray(files) ? files[0] : files
            if (!file || !photoDialog.value.requestId) return
            photoDialog.value.uploading = true
            try {
                const base64 = await fileToBase64(file)
                const result = await carsStore.savePartsRequestPhoto(photoDialog.value.requestId, base64)
                // Fotos neu laden um aktuelle Base64-Daten zu bekommen
                const loaded = await carsStore.getPartsRequestPhoto(photoDialog.value.requestId)
                photoDialog.value.photos = loaded.photos || []
                photoDialog.value.uploadKey++ // v-file-input zurücksetzen
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

        function fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader()
                reader.onload = () => resolve(reader.result)
                reader.onerror = reject
                reader.readAsDataURL(file)
            })
        }

        const recentVendorsList = ref([])

        async function loadRecentVendors() {
            if (!carsStore || fakturaType.value !== 'order') return
            try {
                recentVendorsList.value = await carsStore.getRecentVendors()
            } catch { recentVendorsList.value = [] }
        }

        const showSpecialButton = computed(() => {
            if (fakturaType.value !== 'order') return false
            if (!oserp.isLxCars()) return false
            if (!vehicle?.selectedCarId?.value) return false
            return (oserp.session.auth_groups || []).some(g => g.name === 'Special')
        })

        function openSpecialDialog() {
            specialDialogVisible.value = true
        }

        // saveAllItems braucht accounting — wird als Funktion definiert bevor useItemManagement aufgerufen wird
        async function saveAllItems(updatePayments = false) {
            try {
                accounting.flushCalculation()
                const accTransEntries = accounting.calculateAccTransEntries()
                // paymentEntries nur berechnen und übergeben wenn PaymentSectionCard
                // explizit speichert — sonst würde ein Item-Save alle Zahlungen löschen
                const paymentEntries = updatePayments ? accounting.calculatePaymentEntries() : null
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

        const items = useItemManagement({
            fakturaItems, fakturaId, fakturaType, faktura, itemsTableRef,
            calculateItemTotal: accounting.calculateItemTotal,
            calculateTotals: accounting.calculateTotals,
            saveAllItems, ensureFakturaExists, flushRouteReplace,
            oserp, t, router,
            suppressSSEReload() { suppressSSEReloadUntil = Date.now() + 2000 }
        })

        // ===== Lifecycle =====

        const routeNameMap = {
            order: 'faktura-order-view',
            purchase_order: 'faktura-order-view',
            quotation: 'faktura-quotation-view',
            request_quotation: 'faktura-quotation-view',
            invoice: 'faktura-invoice-view',
            purchase_invoice: 'faktura-invoice-view',
            delivery_order: 'faktura-delivery-order-view',
            credit_note: 'faktura-credit-note-view',
            invoice_storno: 'faktura-invoice-view'
        }

        /**
         * Stellt sicher, dass ein Faktura-Dokument in der DB existiert.
         * Wird beim Erstellen der ersten Position aufgerufen.
         * Das Backend bestimmt den effektiven Dokumenttyp anhand von cvSrc
         * (z.B. order + Vendor → purchase_order, invoice + Vendor → purchase_invoice).
         */
        async function ensureFakturaExists() {
            if (fakturaId.value) return

            const cvProfile = oserp.customer_vendor?.profile || {}
            const cvId = cvProfile.id || null
            const cvSrc = cvProfile.src || null
            const result = await faktura.createFaktura(fakturaType.value, cvId, cvSrc)
            fakturaId.value = result.id
            faktura.data.common.id = result.id

            // Dokumentnummer in den lokalen Daten setzen
            if (result.docNumber) {
                const effectiveType = result.fakturaType || fakturaType.value
                const numberField = { invoice: 'invnumber', purchase_invoice: 'invnumber', quotation: 'quonumber', request_quotation: 'quonumber' }[effectiveType] || 'ordnumber'
                faktura.data.common[numberField] = result.docNumber
            }

            // lxcars: Fahrzeug verknüpfen wenn c_id als Query-Parameter übergeben wurde
            const queryCId = route.query.c_id ? parseInt(route.query.c_id) : null
            if (queryCId && carsStore) {
                const linkFn = fakturaType.value === 'invoice'
                    ? carsStore.linkCarToInvoice
                    : carsStore.linkCarToFaktura
                await linkFn(result.id, queryCId).catch(() => {})
                // Fahrzeugdaten nachladen damit oe_ext-Felder befüllt werden
                if (vehicle) {
                    const customerId = faktura.data?.common?.customer_id || faktura.data?.common?.vendor_id
                    vehicle.loadVehicleData(customerId)
                }
            }

            // Effektiven Dokumenttyp vom Backend übernehmen (z.B. 'purchase_order')
            if (result.fakturaType && result.fakturaType !== fakturaType.value) {
                fakturaTypeOverride.value = result.fakturaType
            }

            // URL-Aktualisierung zurückstellen: erst nach dem ersten Item-Speichern durchführen,
            // damit die neue Position sichtbar ist wenn die Komponente neu mountet.
            const targetRoute = routeNameMap[fakturaType.value]
            if (targetRoute) {
                pendingRouteReplace.value = { name: targetRoute, params: { id: result.id } }
            }
        }

        async function flushRouteReplace() {
            if (!pendingRouteReplace.value) return
            const pending = pendingRouteReplace.value
            pendingRouteReplace.value = null
            // URL per history.replaceState aktualisieren statt router.replace(),
            // damit kein Remount der Komponente ausgelöst wird (der den Fokus stiehlt).
            const resolved = router.resolve(pending)
            window.history.replaceState(history.state, '', resolved.href)
        }

        async function ensureOrderAndGetId() {
            await ensureFakturaExists()
            flushRouteReplace()
            return fakturaId.value
        }

        // ===== Focus: Von Anweisungen zur neuen Position springen =====

        function focusNewPosition() {
            nextTick(() => {
                if (itemsTableRef.value) {
                    itemsTableRef.value.focusArticleField(fakturaItems.value.length - 1)
                }
            })
        }

        // ===== SSE: Echtzeit-Aktualisierung =====

        let sseSource = null
        let sseReloadPending = false
        let suppressSSEReloadUntil = 0

        async function reloadFakturaData() {
            if (!fakturaId.value || sseReloadPending) return
            if (Date.now() < suppressSSEReloadUntil) return
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

                // Leere Zeile am Ende sicherstellen
                const lastItem = fakturaItems.value[fakturaItems.value.length - 1]
                if (!lastItem || lastItem.parts_id) {
                    fakturaItems.value.push(items.createEmptyItem())
                }

                contactEmail.value = faktura.data.customer.email || ''
                contactPhone1.value = faktura.data.customer.phone || ''
                contactPhone2.value = faktura.data.customer.fax || ''
                contactLabel.value = faktura.data.customer.contact || ''
                customerName.value = faktura.data.customer.name || ''
                const pn = faktura.data.customer.phone_numbers
                phoneNumbers.value = typeof pn === 'string' ? JSON.parse(pn) : (pn || [])
                customerNotes.value = faktura.data.customer.notes || ''

                if (fakturaType.value === 'invoice') {
                    const defaultChartId = paymentAccList.value.length > 0 ? paymentAccList.value[0].id : null
                    paymentList.value = faktura.data.payment ? faktura.data.payment.map(p => ({
                        ...p, chart_id: p.chart_id || defaultChartId
                    })) : []
                }

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
                    const fakturaTables = ['oe', 'ar', 'orderitems', 'invoice', 'oe_instructions_lxcars', 'oe_defects', 'ar_defects', 'oe_parts_requests_lxcars']
                    if (!fakturaTables.includes(data.table)) return
                    if (Number(data.id) !== Number(fakturaId.value)) return
                    if (data.table === 'oe_instructions_lxcars') {
                        instructionsRef.value?.loadInstructions()
                    } else if (data.table === 'oe_defects' || data.table === 'ar_defects') {
                        maengelRef.value?.loadMaengel()
                    } else if (data.table === 'oe_parts_requests_lxcars') {
                        loadPartsRequests()
                    } else {
                        reloadFakturaData()
                    }
                } catch { /* kein Faktura-Event */ }
            }
            sseSource.onerror = () => { /* reconnect ist automatisch */ }
        }

        onBeforeUnmount(() => {
            if (sseSource) {
                sseSource.close()
                sseSource = null
            }
        })

        // SumUp: Beim Öffnen einer noch offenen Rechnung den fälligen Betrag
        // automatisch an das gekoppelte Kartenterminal senden. Schutz: nur bei
        // Typ "Rechnung", nur wenn SumUp aktiviert ist und nur wenn noch ein
        // offener Betrag besteht – sonst würde der Reader bei jedem Ansehen
        // blockiert bzw. eine bezahlte Rechnung erneut zur Kasse gebeten.
        let terminalCheckoutSent = false
        async function maybeSendInvoiceToTerminal() {
            if (terminalCheckoutSent) return
            if (fakturaType.value !== 'invoice' || !fakturaId.value) return

            const en = oserp.getClientDefaultValue('sumup_enabled', null)
            const enabled = en === true || en === 'true' || en === 't' || en === '1' || en === 1
            if (!enabled) return

            // Bruttobetrag sofort berechnen (Debounce umgehen) und offenen Rest ermitteln
            accounting.flushCalculation()
            const gross = accounting.calculatedGrossAmount.value || 0
            const paid = (paymentList.value || []).reduce((sum, p) => sum + Math.abs(p.amount || 0), 0)
            const remaining = Math.round((gross - paid) * 100) / 100
            if (remaining <= 0) return // bereits (vollständig) bezahlt

            terminalCheckoutSent = true
            try {
                toasts.info(t('FakturaView.faktura.terminalSending', { amount: remaining.toFixed(2) }))
                await faktura.sendSumupCheckout(remaining, {
                    fakturaID: fakturaId.value,
                    description: faktura.data?.common?.invnumber || ''
                })
                toasts.success(t('FakturaView.faktura.terminalSent'))
            } catch (e) {
                terminalCheckoutSent = false // bei Fehler erneuter Versuch möglich
                toasts.error(t('FakturaView.faktura.terminalError', { msg: e.message || '' }))
            }
        }

        onMounted(async () => {
            try {
                if (!fakturaId.value) {
                    // Neu-Modus: Leeren Zustand initialisieren
                    const defaults = oserp.session.company_config?.defaults || {}
                    const cvProfile = oserp.customer_vendor?.profile || {}
                    const isCustomer = cvProfile.src === 'C' || !cvProfile.src
                    faktura.data = {
                        common: {
                            id: null,
                            transdate: new Date().toISOString().split('T')[0],
                            taxzone_id: cvProfile.taxzone_id || defaults.taxzone_id || 4,
                            currency_id: cvProfile.currency_id || defaults.currency_id || 1,
                            customer_id: isCustomer ? (cvProfile.id || null) : null,
                            vendor_id: !isCustomer ? (cvProfile.id || null) : null,
                            taxincluded: !!cvProfile.taxincluded_checked,
                            closed: false
                        },
                        customer: cvProfile.id ? cvProfile : {},
                        positions: [],
                        shiptos: [],
                        billing_addresses: [],
                        payment: [],
                        customers: cvProfile.id && cvProfile.name
                            ? [{ id: cvProfile.id, name: cvProfile.name }]
                            : []
                    }
                    customerList.value = faktura.data.customers || []
                    loadDropdownLists()
                    fakturaItems.value.push(items.createEmptyItem())

                    // lxcars: Fahrzeuge des Kunden laden bzw. vorauswählen
                    const queryCId = route.query.c_id ? parseInt(route.query.c_id) : null
                    const customerId = faktura.data.common.customer_id || faktura.data.common.vendor_id
                    if (vehicle && customerId && (fakturaType.value === 'order' || fakturaType.value === 'quotation' || fakturaType.value === 'invoice')) {
                        if (queryCId) {
                            vehicle.preselectCar(customerId, queryCId)
                        } else {
                            vehicle.loadVehicleData(customerId)
                        }
                    }

                    // Fokus: Bei LxCars-Aufträgen ins Instruction-Feld, sonst ins Artikel-Feld
                    nextTick(() => {
                        setTimeout(() => {
                            if (vehicle && fakturaType.value === 'order' && instructionsRef.value) {
                                instructionsRef.value.focusInput()
                            } else if (itemsTableRef.value) {
                                itemsTableRef.value.focusArticleField(0)
                            }
                        }, 300)
                    })

                    return
                }

                // getTemplateList parallel zu getFakturaData starten
                const templatePromise = faktura.getTemplateList().catch(() => null)

                // LxCars: alle Initialdaten in einem Call vorladen (nur wenn aktiv).
                // customer_id = 0 → Backend leitet sie selbst aus der Faktura ab.
                const lxCarsTypes = ['order', 'quotation', 'invoice']
                const needsLxCars = !!vehicle && lxCarsTypes.includes(fakturaType.value)
                const lxCarsPromise = (needsLxCars && carsStore)
                    ? carsStore.loadLxCarsFakturaInit(fakturaId.value, 0, fakturaType.value).catch(() => null)
                    : Promise.resolve(null)

                await faktura.fetchFakturaData(fakturaId.value, fakturaType.value)

                if (faktura.data) {
                    fakturaItems.value = (faktura.data.positions || []).map(item => {
                        let buchungsziel = item.buchungsziel
                        if (typeof buchungsziel === 'string') {
                            try { buchungsziel = JSON.parse(buchungsziel) }
                            catch (e) { buchungsziel = null }
                        }
                        return {
                            ...item,
                            buchungsziel,
                            localArticleList: [],
                            localArticleLoading: false
                        }
                    })

                    customerList.value = faktura.data.customers || []
                    await loadDropdownLists()

                    // Hintergrund-Kunde/-Lieferant auf das Dokument synchronisieren
                    const cvId = isVendor.value
                        ? faktura.data.common?.vendor_id
                        : faktura.data.common?.customer_id
                    if (cvId) {
                        oserp.fetchCustomerOrVendor(cvId, isVendor.value ? 'V' : 'C').catch(() => {})
                    }

                    if (fakturaItems.value.length > 0) {
                        const lastItem = fakturaItems.value[fakturaItems.value.length - 1]
                        if (lastItem.parts_id) {
                            fakturaItems.value.push(items.createEmptyItem())
                        }
                    } else {
                        fakturaItems.value.push(items.createEmptyItem())
                    }

                    nextTick(() => {
                        setTimeout(() => {
                            if (itemsTableRef.value) {
                                itemsTableRef.value.focusArticleField(fakturaItems.value.length - 1)
                            }
                        }, 300)
                    })

                    if (fakturaType.value === 'invoice') {
                        const defaultChartId = paymentAccList.value.length > 0 ? paymentAccList.value[0].id : null
                        paymentList.value = faktura.data.payment ? faktura.data.payment.map(payment => ({
                            ...payment,
                            chart_id: payment.chart_id || defaultChartId
                        })) : []
                    }

                    contactEmail.value = faktura.data.customer.email || ''
                    contactPhone1.value = faktura.data.customer.phone || ''
                    contactPhone2.value = faktura.data.customer.fax || ''
                    contactLabel.value = faktura.data.customer.contact || ''
                    customerName.value = faktura.data.customer.name || ''
                    const pn = faktura.data.customer.phone_numbers
                    phoneNumbers.value = typeof pn === 'string' ? JSON.parse(pn) : (pn || [])
                    customerNotes.value = faktura.data.customer.notes || ''

                    // lxcars: Fahrzeuge + Ersatzteile + Lieferanten aus dem zusammengeführten Call
                    if (needsLxCars) {
                        const lxData = await lxCarsPromise
                        if (lxData) {
                            vehicle.loadVehicleData(null, lxData)
                            partsRequestsList.value = lxData.parts_requests || []
                            recentVendorsList.value = lxData.recent_vendors || []
                        } else {
                            // Fallback: Einzelaufrufe
                            vehicle.loadVehicleData(faktura.data.common?.customer_id)
                            loadPartsRequests()
                            loadRecentVendors()
                        }
                    }

                    accounting.calculateTotals()

                    // SumUp: offenen Rechnungsbetrag automatisch ans Terminal senden
                    maybeSendInvoiceToTerminal()

                    // Druckvorlagen aus dem parallel gestarteten Call
                    try {
                        const templateData = await templatePromise
                        if (templateData) {
                            templateList.value = (templateData.templateSets || []).map(set => ({
                                id: set.name,
                                name: set.label
                            }))
                            if (templateData.activeSet) {
                                selectedTemplate.value = templateData.activeSet
                            }
                        }
                    } catch (e) {
                        console.error('Fehler beim Laden der Druckvorlagen:', e)
                    }
                }
            } catch (e) {
                alerts.error(t('FakturaView.faktura.loadError'))
            }

            // SSE starten fuer Echtzeit-Updates
            if (fakturaId.value) {
                connectSSE()
            }
        })

        // ===== Anweisungen-Validierung (lxcars) =====

        const instructionsIncompleteDialog = ref({ show: false, items: [] })

        async function validateInstructionsComplete() {
            if (!vehicle || !carsStore || !fakturaId.value) return true
            try {
                const instructions = await carsStore.loadInstructions(fakturaId.value)
                const incomplete = instructions.filter(i => !i.employee_id || !i.actual_minutes)
                if (incomplete.length > 0) {
                    instructionsIncompleteDialog.value = { show: true, items: incomplete }
                    return false
                }
                return true
            } catch (e) {
                console.error('Fehler beim Laden der Anweisungen:', e)
                return true
            }
        }

        // ===== Wartung-Validierung beim Abschluss der letzten Anweisung =====

        const maintenanceIncompleteDialog = ref({ show: false, fields: [] })

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

        // ===== Feld-Persistenz =====

        async function onFakturaFieldChange(field, value) {
            const fakturaID = faktura.data.common?.id
            if (!fakturaID) return
            try {
                // "Bestätigt"-Schalter: record_type (sales_order ↔ sales_order_intake)
                // läuft über eine eigene, validierte Action statt updateFakturaField.
                if (field === 'record_type') {
                    await faktura.setOrderConfirmed(fakturaID, value === 'sales_order')
                    return
                }
                await faktura.updateFakturaField(fakturaID, fakturaType.value, field, value)
                if (field === 'taxincluded') {
                    // "Steuer im Preis inbegriffen" ändert die Netto/Brutto-Aufteilung
                    // jeder Position → Summen neu berechnen UND die persistierten
                    // Beträge (netamount/amount) samt Buchungssätzen (acc_trans) neu schreiben.
                    await saveAllItems()
                } else if (field === 'taxzone_id') {
                    // Steuerzone bestimmt Konten und Steuersatz jeder Position →
                    // Positionen samt buchungsziel neu laden, Summen neu berechnen ...
                    suppressSSEReloadUntil = 0
                    await reloadFakturaData()
                    // ... und die Buchungssätze (acc_trans) mit den neuen
                    // Konten/Steuersätzen neu schreiben
                    await saveAllItems()
                }
            } catch (e) {
                console.error('Fehler beim Speichern des Feldes:', e)
                alerts.error(t('FakturaView.faktura.fieldUpdateError'))
            }
        }

        // Schalter "Steuer im Preis inbegriffen" sitzt im Summenbereich der Positionen
        // und emittiert nur den neuen Wert → erst im Speicher setzen (damit Neuberechnung
        // und Schalterzustand stimmen), dann speichern + Summen/Buchungssätze neu schreiben.
        async function onTaxIncludedChange(value) {
            if (!faktura.data?.common) return
            faktura.data.common.taxincluded = value
            // Summen sofort sichtbar neu berechnen (vor dem Speichern-Roundtrip),
            // onFakturaFieldChange persistiert anschließend Flag + Buchungssätze.
            accounting.flushCalculation()
            await onFakturaFieldChange('taxincluded', value)
        }

        async function toggleClosed() {
            if (!faktura.data?.common) return
            const newVal = !faktura.data.common.closed
            // Beim Schließen: Anweisungen validieren (nur lxcars)
            if (newVal && vehicle) {
                const valid = await validateInstructionsComplete()
                if (!valid) return
            }
            faktura.data.common.closed = newVal
            onFakturaFieldChange('closed', newVal)
        }

        // ===== Kundenwechsel =====

        async function onCustomerChange(newCvId) {
            // Datenmodell aktualisieren
            const field = isVendor.value ? 'vendor_id' : 'customer_id'
            faktura.data.common[field] = newCvId

            // In DB speichern
            await onFakturaFieldChange(field, newCvId)

            // Vehicle-Daten aktualisieren (lxcars)
            if (vehicle && (fakturaType.value === 'order' || fakturaType.value === 'quotation' || fakturaType.value === 'invoice')) {
                vehicle.onCustomerChangeVehicle(newCvId)
            }
        }

        // ===== Dropdown-Listen laden =====

        async function loadDropdownLists() {
            try {
                deliveryAddressList.value = faktura.data.shiptos || []
                billingAddressList.value = faktura.data.billing_addresses || []
                currencyList.value = oserp.session.company_config.currencies || []
                taxZoneList.value = oserp.session.company_config.tax_zones || []
                languageList.value = oserp.session.company_config.languages || []
                departmentList.value = oserp.session.company_config.department || []
                creditLimit.value = faktura.data.customer?.creditlimit || 0
                employeeList.value = oserp.session.company_config.employees || []
                printerList.value = (oserp.session.company_config.printers || []).filter(p => !p.hide_factura)

                // Gespeicherten Drucker aus Employee-Config laden
                const savedPrinterId = oserp.getConfigValue('default_printer_id')
                if (savedPrinterId) {
                    selectedPrinter.value = printerList.value.find(p => String(p.id) === String(savedPrinterId)) || null
                }
                paymentTermList.value = oserp.session.company_config.payment_terms || []
                deliveryTermList.value = oserp.session.company_config.delivery_terms || []

                paymentAccList.value = (oserp.session.company_config.payment_acc || []).map(acc => ({
                    ...acc,
                    label: `${acc.accno} - ${acc.description}`
                }))
            } catch (e) {
                // Error loading dropdown lists
            }
        }

        // ===== Action-Stubs =====

        function saveFaktura() {
            // TODO: Implement save
        }

        function closeView() {
            router.back()
        }

        function openVehicleEdit() {
            if (vehicle?.selectedCarId.value) {
                router.push({ name: 'car', params: { id: vehicle.selectedCarId.value } })
            }
        }

        // ===== AAG-Online =====

        const aagLoading = ref(false)

        // Token im Hintergrund vorladen, sobald ein Auftrag mit Fahrzeug geladen
        // ist (Bedingung wie der AAG-Button). Nicht-blockierend, einmalig pro
        // Auftrag – der Auftrag selbst wird dadurch nicht langsamer.
        let aagTokenWarmed = false
        watch(
            () => aagConfigured.value && fakturaType.value === 'order' && !!vehicle && !!vehicle.selectedCarId?.value,
            (ready) => {
                if (ready && !aagTokenWarmed) {
                    aagTokenWarmed = true
                    faktura.warmAagToken()
                }
            },
            { immediate: true }
        )

        async function onOpenAag() {
            if (!fakturaId.value) return

            // Als eigenes App-Fenster (Popup ohne Tab-Leiste) öffnen, nicht als Tab.
            // Sofort öffnen (vermeidet Popup-Blocker); URL wird gesetzt, sobald sie vorliegt.
            // Wie der Fahrzeug-Button: nur das Fahrzeug an AAG-Online übergeben, kein
            // Auftrags-/WorkTask-Beleg. So zeigt AAG die Fahrzeug-Ansicht (Kennzeichen
            // + Modell) statt "Auftrag ##### Name". Titel daher: "Kennzeichen · Modell".
            const cId = vehicle?.selectedCarId?.value || 0
            const carObj = vehicle?.customerCars?.value?.find(c => c.c_id === cId)
            const plate = (carObj?.c_ln || '').trim()
            const model = (carObj?.c_mt || '').trim()
            const aagTitle = [plate, model].filter(Boolean).join(' · ') || 'AAG-Online'
            const aagName = 'aag-' + (plate.replace(/\s+/g, '') || String(cId) || 'online')
            // Vorhandenes AAG-Fenster wiederverwenden (genau ein Fenster) oder neu öffnen.
            const reusing = aagWindowOpen()
            const aagWindow = openAppWindow(aagName, aagTitle)

            aagLoading.value = true
            try {
                const { data } = await axios.post('/api/lxcars/', {
                    action: 'getAagVehicleUrl',
                    c_id: cId,
                    vin: (carObj?.c_fin || '').trim(),
                    oe_id: fakturaId.value
                })
                const portalUrl = data?.success ? data.payload?.portalUrl : null
                if (!portalUrl) {
                    if (aagWindow && !reusing) aagWindow.close() // nur frisch geöffnetes schließen
                    toasts.error(t('FakturaView.faktura.aagError'))
                    return
                }
                if (aagWindow) {
                    aagWindow.location.href = portalUrl
                    aagWindow.focus()
                    setAagWindowCarId(cId)
                } else {
                    // Fenster wurde blockiert – im selben Tab als Fallback öffnen
                    window.open(portalUrl, '_blank')
                }
            } catch (e) {
                if (aagWindow && !reusing) aagWindow.close()
                console.error('AAG-Online error:', e)
                toasts.error(t('FakturaView.faktura.aagError') + (e?.message ? '\n' + e.message : ''))
            } finally {
                aagLoading.value = false
            }
        }

        // ===== ESI[tronic] / Hella Gutmann (verknüpftes Fahrzeug des Auftrags) =====

        // Das aktuell verknüpfte Fahrzeug aus der Kundenfahrzeug-Liste (enthält
        // seit der Backend-Erweiterung c_2/c_3/c_fin).
        const selectedCarObj = computed(() =>
            vehicle?.customerCars?.value?.find(c => c.c_id === vehicle?.selectedCarId?.value) || null
        )

        const gutmannBaseUrl = computed(() =>
            String(oserp.getClientDefaultValue('gutmann_megamacs_url', '') || '').trim()
        )

        // Sichtbar wie der AAG-Button: Auftrag mit verknüpftem, identifizierbarem
        // Fahrzeug (gültige HSN/TSN ODER FIN — auch bei ausgenullter TSN).
        const esiAvailable = computed(() =>
            fakturaType.value === 'order' && !!selectedCarObj.value &&
            hasVehicleId(selectedCarObj.value.c_2, selectedCarObj.value.c_3, selectedCarObj.value.c_fin)
        )

        const gutmannAvailable = computed(() =>
            esiAvailable.value && !!gutmannBaseUrl.value
        )

        function onOpenEsi() {
            const car = selectedCarObj.value
            if (!car || !hasVehicleId(car.c_2, car.c_3, car.c_fin)) return
            window.location.href = buildEsiUrl(car.c_2, car.c_3, car.c_fin)
        }

        function onOpenGutmann() {
            const car = selectedCarObj.value
            if (!car || !hasVehicleId(car.c_2, car.c_3, car.c_fin)) return
            const url = buildGutmannUrl(gutmannBaseUrl.value, car.c_2, car.c_3, car.c_fin)
            const win = openAppWindow('gutmann-megamacs')
            if (win) {
                win.location.href = url
                win.focus()
            } else {
                window.open(url, '_blank')
            }
        }

        // HGS-Data-Suche braucht gültige HSN/TSN; Auflösung der vehicleId im Backend.
        const hgsLoading = ref(false)
        const hgsAvailable = computed(() =>
            fakturaType.value === 'order' && !!selectedCarObj.value &&
            isKbaValid(selectedCarObj.value.c_2, selectedCarObj.value.c_3)
        )

        async function onOpenHgs() {
            const car = selectedCarObj.value
            if (!car) return
            const win = openAppWindow('hgs-data')
            hgsLoading.value = true
            try {
                const { data } = await axios.post('/api/lxcars/', {
                    action: 'getHgsVehicleUrl',
                    c_id: car.c_id
                })
                const portalUrl = data?.success ? data.payload?.portalUrl : null
                if (!portalUrl) {
                    if (win) win.close()
                    toasts.error(t('FakturaView.faktura.hgsError') + (data?.payload?.message ? '\n' + data.payload.message : ''))
                    return
                }
                if (win) {
                    win.location.href = portalUrl
                    win.focus()
                } else {
                    window.open(portalUrl, '_blank')
                }
            } catch (e) {
                if (win) win.close()
                console.error('HGS-Data error:', e)
                toasts.error(t('FakturaView.faktura.hgsError') + (e?.message ? '\n' + e.message : ''))
            } finally {
                hgsLoading.value = false
            }
        }

        function showOnDisplay() {
            if (!fakturaId.value) return
            const displayRoute = router.resolve({
                name: 'wall-display',
                query: { faktura: `${fakturaType.value}:${fakturaId.value}` }
            })
            window.open(displayRoute.href, 'wall-display')
        }

        // ===== SilverDAT Import =====

        const silverdatFileInput = ref(null)

        // Öffnet sofort den Dateiauswahldialog (ohne Zwischen-Dropzone)
        function onImportSilverdat() {
            if (!silverdatImport) return
            silverdatImport.reset()
            silverdatImport.showDialog.value = false
            const input = silverdatFileInput.value
            if (input) {
                input.value = '' // gleiche Datei erneut wählbar
                input.click()
            }
        }

        // Nach Dateiauswahl: parsen und Vorschau-Dialog öffnen
        async function onSilverdatFileSelect(event) {
            if (!silverdatImport) return
            await silverdatImport.onFileSelect(event)
            if (silverdatImport.importItems.value.length || silverdatImport.importError.value) {
                silverdatImport.showDialog.value = true
            }
        }

        async function onSilverdatImport() {
            if (!silverdatImport || !fakturaId.value) return

            try {
                const result = await silverdatImport.doImport(
                    fakturaId.value, fakturaType.value
                )

                if (!result) return // doImport zeigt Fehler im Dialog

                // Dialog sofort schließen
                silverdatImport.showDialog.value = false
                silverdatImport.reset()
                toasts.success(t('FakturaView.faktura.silverdat.success', { count: result.count || 0 }))

                // Faktura-Daten komplett neu laden (Items mit allen Properties)
                await faktura.fetchFakturaData(fakturaId.value, fakturaType.value)
                if (faktura.data) {
                    fakturaItems.value = (faktura.data.positions || []).map(item => {
                        let buchungsziel = item.buchungsziel
                        if (typeof buchungsziel === 'string') {
                            try { buchungsziel = JSON.parse(buchungsziel) }
                            catch (e) { buchungsziel = null }
                        }
                        return { ...item, buchungsziel, localArticleList: [], localArticleLoading: false }
                    })
                    accounting.calculateTotals()
                }
            } catch (e) {
                console.error('SilverDAT import handler error:', e)
                if (silverdatImport) {
                    silverdatImport.importError.value = String(e?.message || e)
                    silverdatImport.importing.value = false
                }
            }
        }

        function onSilverdatClose() {
            if (silverdatImport) {
                silverdatImport.showDialog.value = false
                silverdatImport.reset()
            }
        }

        // ===== Dokument-Konvertierung (generisch) =====

        async function convertAndNavigate(targetType) {
            if (!fakturaId.value) return
            try {
                const result = await faktura.convertFaktura(fakturaId.value, fakturaType.value, targetType)
                toasts.success(t('FakturaView.workflow.created'))
                const targetRoute = routeNameMap[result.fakturaType]
                if (targetRoute) {
                    router.push({ name: targetRoute, params: { id: result.id } })
                }
            } catch (e) {
                console.error('Fehler bei Dokumentkonvertierung:', e)
                // API-Fehlermeldung anzeigen (z.B. Duplikat-Hinweis), sonst generischer Text
                const msg = e?.code || t('FakturaView.workflow.error')
                toasts.error(msg)
            }
        }

        function reuseFaktura() {
            convertAndNavigate(fakturaType.value)
        }

        function createQuotationFromFaktura() {
            convertAndNavigate('quotation')
        }

        function createOrderFromFaktura() {
            convertAndNavigate('order')
        }

        function createDeliveryOrderFromFaktura() {
            convertAndNavigate('delivery_order')
        }

        const kmPlausibilityDialog = ref({ show: false, currentKm: 0, lastKm: 0 })

        async function createInvoiceFromFaktura() {
            // Anweisungen validieren (nur lxcars)
            if (vehicle) {
                const valid = await validateInstructionsComplete()
                if (!valid) return
                // km_stand ist Pflicht für Autos und Motorräder (nicht Anhänger)
                if (vehicle.selectedCarId.value && !vehicle.isTrailer.value) {
                    const kmStand = vehicle.oeExtData.value?.km_stand
                    if (!kmStand) {
                        toasts.error(t('FakturaView.faktura.kmStandRequired'))
                        return
                    }
                    // Plausibilitätsprüfung gegen frühere Aufträge/Rechnungen
                    try {
                        const result = await carsStore.checkKmStandPlausibility(fakturaId.value, vehicle.selectedCarId.value)
                        if (result.last_km > 0 && kmStand < result.last_km) {
                            kmPlausibilityDialog.value = { show: true, currentKm: kmStand, lastKm: result.last_km }
                            return
                        }
                    } catch { /* Bei Netzwerkfehler trotzdem weitermachen */ }
                }
            }
            convertAndNavigate('invoice')
        }

        function createCreditNoteFromFaktura() {
            convertAndNavigate('credit_note')
        }

        function cancelFaktura() {
            showStornoDialog.value = true
        }

        async function confirmStorno() {
            showStornoDialog.value = false
            convertAndNavigate('invoice_storno')
        }

        function createSupplierInquiryFromFaktura() {
            convertAndNavigate('request_quotation')
        }

        function createSupplierOrderFromFaktura() {
            convertAndNavigate('purchase_order')
        }

        function createComplaintFromFaktura() {
            // TODO: Reklamation (späterer Schritt)
        }

        function saveAsDraft() {
            // TODO: Implement save as draft
        }

        function showHistory() {
            // TODO: Implement history
        }

        function setFollowUp() {
            // TODO: Implement follow-up
        }

        // Fahrzeugdaten per Sprache: der Kollege spricht frei ("Kilometerstand
        // 120369, Zahnriemen fällig bei 20000, Bremsflüssigkeit 02/2029"). Whisper
        // transkribiert, der lokale LLM strukturiert, wir tragen die Felder in die
        // Auftrags-Fahrzeugdaten (oe_ext) ein — Auto-Save läuft über
        // onOeExtFieldChange. Gekoppelte Zahnriemen-Felder (c_sk) werden übersprungen.
        async function onVoiceVehicle(text) {
            const spoken = (text || '').trim()
            if (!spoken || !vehicle || !vehicle.selectedCarId.value) return
            try {
                const { data } = await axios.post('/api/lxcars/', { action: 'extractVehicleData', text: spoken })
                if (!data?.success) {
                    alerts.error(data?.text || t('CarEditView.voice.failed'))
                    return
                }
                const f = data.payload?.fields || {}
                const ext = vehicle.onOeExtFieldChange
                const coupled = !!vehicle.oeExtData.value?.c_sk
                const kmFmt = (v) => Number(v) ? Number(v).toLocaleString('de-DE') : ''
                const done = []

                if (f.c_km != null) {
                    const v = parseInt(f.c_km, 10)
                    if (v > 0) { ext('km_stand', v); done.push(`${t('MaintenanceSectionCard.voice.kmStand')}: ${kmFmt(v)} km`) }
                }
                if (f.c_zrk != null && !coupled) {
                    let n = parseInt(f.c_zrk, 10)
                    if (n > 0) { n = n < 1000 ? n * 1000 : n; ext('c_zrk', n); done.push(`${t('MaintenanceSectionCard.fields.c_zrk')}: ${kmFmt(n)} km`) }
                }
                if (f.c_zrd && !coupled) {
                    const d = parseMonthYear(f.c_zrd)
                    if (d) { ext('c_zrd', d); done.push(`${t('MaintenanceSectionCard.fields.c_zrd')}: ${formatMonthYear(d)}`) }
                }
                if (f.c_bf) {
                    const d = parseMonthYear(f.c_bf)
                    if (d) { ext('c_bf', d); done.push(`${t('MaintenanceSectionCard.fields.c_bf')}: ${formatMonthYear(d)}`) }
                }
                if (f.c_wd) {
                    const d = parseMonthYear(f.c_wd)
                    if (d) { ext('c_wd', d); done.push(`${t('MaintenanceSectionCard.fields.c_wd')}: ${formatMonthYear(d)}`) }
                }

                if (done.length) toasts.success(t('MaintenanceSectionCard.voice.applied') + ' ' + done.join(' · '))
                else toasts.info(t('MaintenanceSectionCard.voice.nothing'))
            } catch (e) {
                alerts.error(t('CarEditView.voice.failed'))
            }
        }

        async function exportXInvoice() {
            if (!fakturaId.value) {
                alerts.warning(t('FakturaView.faktura.einvoice.saveFirst'))
                return
            }
            if (fakturaType.value !== 'invoice') {
                alerts.warning(t('FakturaView.faktura.einvoice.onlyInvoice'))
                return
            }

            try {
                const response = await axios.post('/api/faktura/', {
                    action: 'generateEInvoice',
                    fakturaID: fakturaId.value,
                    fakturaType: fakturaType.value,
                })

                if (!response.data?.success) {
                    const code = response.data?.text || 'EINVOICE_ERROR'
                    const violations = response.data?.payload?.violations
                    if (Array.isArray(violations) && violations.length) {
                        alerts.error(t('FakturaView.faktura.einvoice.invalid') + '\n' + violations.slice(0, 5).join('\n'))
                    } else if (code === 'EINVOICE_DISABLED') {
                        alerts.warning(t('FakturaView.faktura.einvoice.disabled'))
                    } else {
                        alerts.error(t('FakturaView.faktura.einvoice.failed'))
                    }
                    return
                }

                const payload = response.data.payload
                const bin = atob(payload.content)
                const bytes = new Uint8Array(bin.length)
                for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)
                const blob = new Blob([bytes], { type: payload.mimetype })

                const url = URL.createObjectURL(blob)
                const a = document.createElement('a')
                a.href = url
                a.download = payload.filename
                document.body.appendChild(a)
                a.click()
                document.body.removeChild(a)
                URL.revokeObjectURL(url)

                const key = payload.testMode
                    ? 'FakturaView.faktura.einvoice.doneTest'
                    : 'FakturaView.faktura.einvoice.done'
                alerts.success(t(key, { format: payload.format }))
            } catch (e) {
                console.error('exportXInvoice error', e)
                alerts.error(t('FakturaView.faktura.einvoice.failed'))
            }
        }

        function selectPrinter(printer) {
            selectedPrinter.value = printer
            oserp.setConfigValue('default_printer_id', String(printer.id))
            alerts.success(t('FakturaView.faktura.printerSelected', { name: printer.printer_description }))
        }

        function selectTemplate(template) {
            selectedTemplate.value = template.id
        }

        async function printFaktura() {
            if (!selectedPrinter.value) {
                alerts.warning(t('FakturaView.faktura.selectPrinterFirst'))
                return
            }
            try {
                pdfLoading.value = true
                await faktura.printToPrinter(
                    fakturaId.value,
                    fakturaType.value,
                    selectedTemplate.value,
                    selectedPrinter.value.id
                )
                alerts.success(t('FakturaView.faktura.printSuccess', { name: selectedPrinter.value.printer_description }))
            } catch (e) {
                console.error('Fehler beim Drucken:', e)
                alerts.error(t('FakturaView.faktura.printError'))
            } finally {
                pdfLoading.value = false
            }
        }

        /**
         * Baut einen sprechenden Dateinamen für den PDF-Download,
         * z. B. "Angebot-0815--Ronny-Zimmermann.pdf"
         */
        function buildPdfFilename() {
            const typeNames = {
                invoice: 'Rechnung',
                purchase_invoice: 'Eingangsrechnung',
                order: 'Auftrag',
                purchase_order: 'Bestellung',
                quotation: 'Angebot',
                request_quotation: 'Anfrage',
                delivery_order: 'Lieferschein',
                credit_note: 'Gutschrift',
            }
            const typePart = typeNames[fakturaType.value] || 'Dokument'
            const numberPart = compactDocNumber.value || fakturaId.value || ''
            const namePart = (customerName.value || faktura.data?.customer?.name || '').trim()

            // Für Dateisysteme unzulässige Zeichen entfernen, Leerzeichen → Bindestrich
            const sanitize = (s) => String(s)
                .replace(/[\\/:*?"<>|]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '')

            const parts = [sanitize(typePart), sanitize(numberPart)].filter(Boolean)
            let filename = parts.join('-')
            const cleanName = sanitize(namePart)
            if (cleanName) filename += '--' + cleanName
            return (filename || 'Dokument') + '.pdf'
        }

        async function showPdfPreview() {
            let blobUrl = null
            try {
                pdfLoading.value = true
                blobUrl = await faktura.generatePDFPreview(
                    fakturaId.value,
                    fakturaType.value,
                    selectedTemplate.value,
                    selectedPrinter.value?.id ?? null
                )
                const link = document.createElement('a')
                link.href = blobUrl
                link.download = buildPdfFilename()
                document.body.appendChild(link)
                link.click()
                document.body.removeChild(link)
            } catch (e) {
                console.error('Fehler bei PDF-Vorschau:', e)
                const detail = e.code || ''
                alerts.error(detail || t('FakturaView.faktura.pdfError'))
                if (e.message) console.error('LaTeX Debug:', e.message)
            } finally {
                if (blobUrl) setTimeout(() => URL.revokeObjectURL(blobUrl), 10000)
                pdfLoading.value = false
            }
        }

        // ===== E-Mail Dialog =====
        const emailDialogVisible = ref(false)
        const emailDialogTo = ref('')
        const emailDialogSubject = ref('')
        const emailDialogBody = ref('')
        const emailDialogAttachmentName = ref('')

        /**
         * Mapping: fakturaType → email_sender_* Feld in defaults
         */
        const emailSenderFieldMap = {
            invoice: 'email_sender_invoice',
            quotation: 'email_sender_sales_quotation',
            order: 'email_sender_sales_order',
            delivery_order: 'email_sender_sales_delivery_order',
            purchase_invoice: 'email_sender_purchase_invoice',
            purchase_order: 'email_sender_purchase_order',
            request_quotation: 'email_sender_request_quotation'
        }

        // Mapping fakturaType -> email_journal_record_type enum
        const emailRecordTypeMap = {
            invoice: 'invoice',
            quotation: 'sales_quotation',
            order: 'sales_order',
            delivery_order: 'sales_delivery_order',
            purchase_invoice: 'purchase_invoice',
            purchase_order: 'purchase_order',
            request_quotation: 'request_quotation'
        }

        /**
         * Oeffnet den E-Mail-Dialog mit vorausgefuellten Werten aus der Config
         */
        function sendEmail() {
            const defaults = oserp.session.company_config?.defaults || {}
            const common = faktura.data?.common || {}
            const docTypeLabel = t(`FakturaView.dokumentTypes.${fakturaType.value}`)
            const docNumber = common.invnumber || common.ordnumber || common.quonumber || common.donumber || ''

            // Empfaenger: Kunden-E-Mail
            emailDialogTo.value = contactEmail.value || ''

            // Betreff: Dokumenttyp + Nummer, optional mit Vorgangsbeschreibung
            let subject = `${docTypeLabel} ${docNumber}`.trim()
            if (defaults.email_subject_transaction_description && common.transaction_description) {
                subject += ` - ${common.transaction_description}`
            }
            emailDialogSubject.value = subject

            // Nachrichtentext
            const customerName = common.customer_name || common.vendor_name || ''
            emailDialogBody.value = t('FakturaView.dialogs.sendEmail.defaultBody', {
                type: docTypeLabel,
                number: docNumber,
                customer: customerName
            })

            // Anhang-Name
            emailDialogAttachmentName.value = `${docTypeLabel}_${docNumber}.pdf`.replace(/\s+/g, '_')

            emailDialogVisible.value = true
        }

        /**
         * Sendet die E-Mail mit PDF-Anhang
         */
        async function onEmailSend(emailData) {
            try {
                pdfLoading.value = true

                // PDF als Base64 generieren
                const pdfBase64 = await faktura.generatePDFBase64(
                    fakturaId.value,
                    fakturaType.value,
                    selectedTemplate.value
                )

                const defaults = oserp.session.company_config?.defaults || {}
                const senderField = emailSenderFieldMap[fakturaType.value] || ''
                const fromName = defaults[senderField] || ''

                // Empfaenger aufbereiten
                const toList = emailData.to.split(/[,;]/).map(e => ({
                    email: e.trim(),
                    name: ''
                })).filter(e => e.email)

                const ccList = emailData.cc
                    ? emailData.cc.split(/[,;]/).map(e => ({
                        email: e.trim(),
                        name: ''
                    })).filter(e => e.email)
                    : []

                // E-Mail via Backend senden
                const response = await axios.post('/api/email/', {
                    action: 'sendEmail',
                    from_name: fromName,
                    to: toList,
                    cc: ccList,
                    subject: emailData.subject,
                    body_text: emailData.body,
                    body_html: '',
                    record_type: emailRecordTypeMap[fakturaType.value] || null,
                    attachments: [{
                        filename: emailDialogAttachmentName.value,
                        content_base64: pdfBase64,
                        content_type: 'application/pdf'
                    }]
                })

                if (response.data.success) {
                    toasts.success(t('FakturaView.faktura.emailSent'))
                    emailDialogVisible.value = false
                } else {
                    const detail = response.data.text || ''
                    alerts.error(detail || t('FakturaView.faktura.emailError'))
                }
            } catch (e) {
                console.error('Fehler beim E-Mail-Versand:', e)
                alerts.error(t('FakturaView.faktura.emailError'))
            } finally {
                pdfLoading.value = false
                emailDialogVisible.value = false
            }
        }

        // ===== WhatsApp Dialog =====
        const waDialogVisible = ref(false)
        const waDialogPhone = ref('')
        const waDialogMessage = ref('')
        const waDialogAttachmentName = ref('')
        const waTemplates = ref([])
        const waSelectedTemplate = ref(null)
        const waTemplateParams = ref([])
        const waTemplatesLoading = ref(false)

        const waPhoneOptions = computed(() => {
            const isMobile = (num) => {
                const n = (num || '').replace(/[\s\-\(\)\.]/g, '')
                return /^(\+4915|\+4916|\+4917|\+436|\+417|015|016|017|06[^4]|07[^2])/.test(n)
            }
            const all = []
            if (contactPhone1.value) {
                all.push({ title: contactPhone1.value, value: contactPhone1.value, mobile: isMobile(contactPhone1.value) })
            }
            for (const entry of phoneNumbers.value) {
                const num = entry.number || ''
                if (num && !all.find(a => a.value === num)) {
                    const label = entry.label ? `${num} (${entry.label})` : num
                    all.push({ title: label, value: num, mobile: isMobile(num) })
                }
            }
            all.sort((a, b) => (b.mobile ? 1 : 0) - (a.mobile ? 1 : 0))
            return all
        })

        /**
         * Berechnet die gerenderte Template-Vorschau
         */
        const waRenderedPreview = computed(() => {
            if (!waSelectedTemplate.value?.body_text) return ''
            let body = waSelectedTemplate.value.body_text
            for (const param of waTemplateParams.value) {
                body = body.replace(param.placeholder, param.value || param.placeholder)
            }
            return body
        })

        /**
         * Laedt genehmigte WhatsApp-Templates vom Backend
         */
        async function loadWaTemplates() {
            try {
                waTemplatesLoading.value = true
                const response = await axios.post('/api/whatsapp/', {
                    action: 'getWhatsAppTemplates'
                })
                if (response.data.success) {
                    const templates = response.data.payload?.templates || []
                    waTemplates.value = templates

                    // Zugeordnetes Faktura-Template aus Config waehlen
                    const configTplId = Number(oserp.session?.company_config?.defaults_oserp?.whatsapp_tpl_faktura || 0)
                    const matched = configTplId > 0 ? templates.find(t => t.id === configTplId) : null
                    waSelectedTemplate.value = matched || templates.find(t => t.template_type === 'document') || null
                } else {
                    waTemplates.value = []
                }
            } catch (e) {
                console.error('Fehler beim Laden der WhatsApp-Templates:', e)
                waTemplates.value = []
            } finally {
                waTemplatesLoading.value = false
            }
        }

        /**
         * Extrahiert Platzhalter aus Template-Body und befuellt bekannte Parameter
         */
        function initTemplateParams(template) {
            if (!template?.body_text) {
                waTemplateParams.value = []
                return
            }
            const common = faktura.data?.common || {}
            const customer = faktura.data?.customer || {}
            const docNumber = common.invnumber || common.ordnumber || common.quonumber || common.donumber || ''
            const grossAmount = accounting.calculatedGrossAmount.value || common.amount || 0

            // Anrede + Nachname (z.B. "Herr Müller")
            const greeting = customer.greeting || ''
            const name = customerName.value || ''
            const salutation = greeting ? `${greeting} ${name}`.trim() : name

            // Dokumenttyp mit Artikel + Nummer (z.B. "Ihre Rechnung Nr. 12345")
            const docTypeArticleMap = {
                invoice: 'Ihre Rechnung',
                quotation: 'Ihr Angebot',
                order: 'Ihr Auftrag',
                delivery_order: 'Ihr Lieferschein'
            }
            const docTypeWithArticle = docTypeArticleMap[fakturaType.value] || t(`FakturaView.dokumentTypes.${fakturaType.value}`)
            const docRef = docNumber ? `${docTypeWithArticle} Nr. ${docNumber}` : docTypeWithArticle

            // Platzhalter {{1}}, {{2}}, ... extrahieren
            const placeholders = template.body_text.match(/\{\{\d+\}\}/g) || []
            const unique = [...new Set(placeholders)]

            // Bekannte Parameter auto-befuellen
            const autoFill = {
                '{{1}}': salutation,
                '{{2}}': docRef,
                '{{3}}': typeof grossAmount === 'number'
                    ? grossAmount.toLocaleString(locale.value === 'de' ? 'de-DE' : 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    : String(grossAmount)
            }

            const paramLabels = {
                '{{1}}': t('FakturaView.dialogs.sendWhatsApp.paramSalutation'),
                '{{2}}': t('FakturaView.dialogs.sendWhatsApp.paramDocRef'),
                '{{3}}': t('FakturaView.dialogs.sendWhatsApp.paramAmount')
            }

            waTemplateParams.value = unique.map(ph => ({
                placeholder: ph,
                label: paramLabels[ph] || `${t('FakturaView.dialogs.sendWhatsApp.paramGeneric')} ${ph}`,
                value: autoFill[ph] || ''
            }))
        }

        /**
         * Oeffnet den WhatsApp-Dialog mit vorausgefuellten Werten
         */
        function sendWhatsApp() {
            const common = faktura.data?.common || {}
            const docTypeLabel = t(`FakturaView.dokumentTypes.${fakturaType.value}`)
            const docNumber = common.invnumber || common.ordnumber || common.quonumber || common.donumber || ''

            // Telefonnummer: Handynummer bevorzugen (kein WhatsApp auf Festnetz)
            const firstMobile = waPhoneOptions.value.find(p => p.mobile)
            waDialogPhone.value = firstMobile?.value || waPhoneOptions.value[0]?.value || contactPhone1.value || ''

            // Nachricht
            waDialogMessage.value = t('FakturaView.dialogs.sendWhatsApp.defaultMessage', {
                type: docTypeLabel,
                number: docNumber
            })

            // Anhang-Name: Firmenname-Dokumenttyp-Nummer
            const companyName = oserp.session.company_config?.defaults?.company || 'Dokument'
            waDialogAttachmentName.value = `${companyName}-${docTypeLabel}-${docNumber}.pdf`.replace(/\s+/g, '_')

            // Template laden und automatisch waehlen
            waSelectedTemplate.value = null
            waTemplateParams.value = []
            loadWaTemplates()

            waDialogVisible.value = true
        }

        /**
         * Sendet das Dokument per WhatsApp mit PDF-Anhang
         */
        async function onWhatsAppSend(waData) {
            try {
                pdfLoading.value = true

                if (!waSelectedTemplate.value) {
                    alerts.error(t('FakturaView.faktura.whatsappError'))
                    return
                }

                // PDF als Base64 generieren
                const pdfBase64 = await faktura.generatePDFBase64(
                    fakturaId.value,
                    fakturaType.value,
                    selectedTemplate.value
                )

                const common = faktura.data?.common || {}
                const customerId = common.customer_id || common.vendor_id || 0
                const paramValues = waTemplateParams.value.map(p => p.value)

                // Template + Dokument in einem Call senden
                const response = await axios.post('/api/whatsapp/', {
                    action: 'sendWhatsAppDocument',
                    to: waData.phone,
                    customer_id: customerId,
                    document_base64: pdfBase64,
                    filename: waDialogAttachmentName.value,
                    template_id: waSelectedTemplate.value.id,
                    parameters: paramValues
                })

                if (response.data.success) {
                    toasts.success(t('FakturaView.faktura.whatsappSent'))
                    waDialogVisible.value = false
                } else {
                    const detail = response.data.text || ''
                    alerts.error(detail || t('FakturaView.faktura.whatsappError'))
                }
            } catch (e) {
                console.error('Fehler beim WhatsApp-Versand:', e)
                alerts.error(t('FakturaView.faktura.whatsappError'))
            } finally {
                pdfLoading.value = false
                waDialogVisible.value = false
            }
        }

        // Template-Parameter initialisieren bei Auswahl
        watch(waSelectedTemplate, (tpl) => {
            initTemplateParams(tpl)
        })

        // ===== DHL Versand =====
        const dhlDialogVisible = ref(false)
        const dhlWeight = ref('')
        const dhlProduct = ref('V01PAK')
        const dhlLength = ref('')
        const dhlWidth = ref('')
        const dhlHeight = ref('')
        const dhlLoading = ref(false)
        const dhlExistingShipments = ref([])
        const dhlRecipientDisplay = ref('')

        const dhlProducts = [
            { value: 'V01PAK', title: 'DHL Paket' },
            { value: 'V53WPAK', title: 'DHL Paket International' },
            { value: 'V62WP', title: 'DHL Warenpost' },
            { value: 'V66WPI', title: 'DHL Warenpost International' }
        ]

        /**
         * Oeffnet den DHL-Dialog
         */
        function openDhlDialog() {
            const common = faktura.data?.common || {}
            const customer = faktura.data?.customer || {}

            // Empfaenger-Anzeige
            const name = customerName.value || customer.name || ''
            const street = customer.street || ''
            const zipCity = [customer.zipcode, customer.city].filter(Boolean).join(' ')
            dhlRecipientDisplay.value = [name, street, zipCity].filter(Boolean).join(', ')

            // Standard-Produkt aus Config
            const defaultProduct = oserp.getClientDefaultValue('dhl_default_product', 'V01PAK')
            dhlProduct.value = defaultProduct || 'V01PAK'

            // Felder zuruecksetzen
            dhlWeight.value = ''
            dhlLength.value = ''
            dhlWidth.value = ''
            dhlHeight.value = ''

            // Bestehende Sendungen laden
            loadDhlShipments()

            dhlDialogVisible.value = true
        }

        /**
         * Bestehende DHL-Sendungen zum aktuellen Beleg laden
         */
        async function loadDhlShipments() {
            try {
                const response = await axios.post('/api/dhl/', {
                    action: 'getDhlShipments',
                    record_id: fakturaId.value,
                    record_type: fakturaType.value
                })
                if (response.data.success) {
                    dhlExistingShipments.value = response.data.payload?.shipments || []
                }
            } catch (e) {
                console.error('Fehler beim Laden der DHL-Sendungen:', e)
            }
        }

        /**
         * DHL-Label erstellen
         */
        async function onDhlCreate() {
            try {
                dhlLoading.value = true
                const response = await axios.post('/api/dhl/', {
                    action: 'createDhlLabel',
                    record_id: fakturaId.value,
                    record_type: fakturaType.value,
                    weight: parseFloat(dhlWeight.value),
                    product: dhlProduct.value,
                    length: dhlLength.value ? parseInt(dhlLength.value) : null,
                    width: dhlWidth.value ? parseInt(dhlWidth.value) : null,
                    height: dhlHeight.value ? parseInt(dhlHeight.value) : null
                })

                if (response.data.success) {
                    const payload = response.data.payload
                    toasts.success(t('FakturaView.dialogs.dhl.success', { shipmentNo: payload.shipment_no }))

                    // Label-PDF zum Download anbieten
                    if (payload.label_b64) {
                        const link = document.createElement('a')
                        link.href = 'data:application/pdf;base64,' + payload.label_b64
                        link.download = `DHL-Label-${payload.shipment_no}.pdf`
                        link.click()
                    }

                    // Liste aktualisieren
                    loadDhlShipments()
                } else {
                    const detail = response.data.text || ''
                    alerts.error(detail || t('FakturaView.dialogs.dhl.error'))
                }
            } catch (e) {
                console.error('Fehler beim DHL-Label-Erstellen:', e)
                alerts.error(t('FakturaView.dialogs.dhl.error'))
            } finally {
                dhlLoading.value = false
            }
        }

        /**
         * Bestehendes DHL-Label herunterladen
         */
        async function downloadDhlLabel(shipmentNo) {
            try {
                const response = await axios.post('/api/dhl/', {
                    action: 'getDhlLabelPdf',
                    shipment_no: shipmentNo
                })
                if (response.data.success && response.data.payload?.label_b64) {
                    const link = document.createElement('a')
                    link.href = 'data:application/pdf;base64,' + response.data.payload.label_b64
                    link.download = `DHL-Label-${shipmentNo}.pdf`
                    link.click()
                }
            } catch (e) {
                console.error('Fehler beim Label-Download:', e)
            }
        }

        /**
         * DHL-Sendung stornieren
         */
        async function onDhlDelete(shipmentNo) {
            try {
                const response = await axios.post('/api/dhl/', {
                    action: 'deleteDhlLabel',
                    shipment_no: shipmentNo
                })
                if (response.data.success) {
                    toasts.success(t('FakturaView.dialogs.dhl.deleted'))
                    loadDhlShipments()
                } else {
                    alerts.error(response.data.text || t('FakturaView.dialogs.dhl.error'))
                }
            } catch (e) {
                console.error('Fehler beim DHL-Storno:', e)
            }
        }

        // KI-Positionsvorschläge
        const showAiSuggest = computed(() => fakturaType.value === 'order' && !!vehicle)
        const aiLoading = ref(false)
        const aiDialogVisible = ref(false)
        const aiSuggestedItems = ref([])

        async function onAiSuggest() {
            const oeId = await ensureOrderAndGetId()
            if (!oeId) return
            aiLoading.value = true
            try {
                const response = await axios.post('/api/lxcars/', {
                    action: 'suggestPositions',
                    oe_id: oeId
                }, { timeout: 60000 })
                const data = response.data
                if (!data.success) {
                    const detail = typeof data.payload === 'string' ? data.payload : ''
                    toasts.error(t('FakturaView.faktura.aiError') + (detail ? '\n' + detail : ''))
                    return
                }
                const suggestions = data.payload.suggested_items || []
                if (suggestions.length > 0) {
                    aiSuggestedItems.value = suggestions
                    aiDialogVisible.value = true
                } else {
                    toasts.info(t('FakturaView.faktura.aiNoSuggestions'))
                }
            } catch {
                toasts.error(t('FakturaView.faktura.aiError'))
            } finally {
                aiLoading.value = false
            }
        }

        async function onAiConfirmed(selectedItems) {
            try {
                const response = await axios.post('/api/lxcars/', {
                    action: 'addSuggestedItems',
                    oe_id: fakturaId.value,
                    items: selectedItems
                })
                if (response.data.success) {
                    toasts.success(t('FakturaView.faktura.aiPositionsAdded', { count: selectedItems.length }))
                    await faktura.fetchFakturaData(fakturaId.value, fakturaType.value)
                    if (faktura.data) {
                        fakturaItems.value = (faktura.data.positions || []).map(item => {
                            let buchungsziel = item.buchungsziel
                            if (typeof buchungsziel === 'string') {
                                try { buchungsziel = JSON.parse(buchungsziel) }
                                catch (e) { buchungsziel = null }
                            }
                            return { ...item, buchungsziel, localArticleList: [], localArticleLoading: false }
                        })
                        fakturaItems.value.push(items.createEmptyItem())
                    }
                } else {
                    toasts.error(t('FakturaView.faktura.aiPositionsError'))
                }
            } catch {
                toasts.error(t('FakturaView.faktura.aiPositionsError'))
            } finally {
                aiDialogVisible.value = false
            }
        }

        return {
            t,
            oserp,
            faktura,
            fakturaType,
            fakturaId,
            isVendor,
            hasCustomer,
            fakturaItems,
            itemsTableRef,
            instructionsRef,
            maengelRef,
            vehicleSectionRef,
            customerList,
            articleList,
            articleLoading,
            deliveryAddressList,
            billingAddressList,
            currencyList,
            taxZoneList,
            languageList,
            departmentList,
            employeeList,
            printerList,
            templateList,
            selectedTemplate,
            selectedPrinter,
            pdfLoading,
            paymentTermList,
            deliveryTermList,
            paymentAccList,
            contactPhone1,
            contactPhone2,
            contactEmail,
            contactLabel,
            customerName,
            phoneNumbers,
            creditLimit,
            customerNotes,
            compactView,
            compactDocNumber,
            compactTransdate,
            compactEmployeeName,
            switchToWhatsAppTabFromFaktura,
            paymentList,
            // Composables
            accounting,
            vehicle,
            items,
            // Orchestrierung
            ensureOrderAndGetId,
            focusNewPosition,
            saveAllItems,
            toggleClosed,
            onFakturaFieldChange,
            onTaxIncludedChange,
            onCustomerChange,
            instructionsIncompleteDialog,
            maintenanceIncompleteDialog,
            validateMaintenanceBeforeComplete,
            kmPlausibilityDialog,
            // Actions
            saveFaktura,
            closeView,
            reuseFaktura,
            createQuotationFromFaktura,
            createOrderFromFaktura,
            createDeliveryOrderFromFaktura,
            createInvoiceFromFaktura,
            createCreditNoteFromFaktura,
            cancelFaktura,
            confirmStorno,
            showStornoDialog,
            createSupplierInquiryFromFaktura,
            createSupplierOrderFromFaktura,
            createComplaintFromFaktura,
            saveAsDraft,
            showHistory,
            setFollowUp,
            onVoiceVehicle,
            exportXInvoice,
            selectPrinter,
            selectTemplate,
            printFaktura,
            showPdfPreview,
            sendEmail,
            emailDialogVisible,
            emailDialogTo,
            emailDialogSubject,
            emailDialogBody,
            emailDialogAttachmentName,
            onEmailSend,
            // WhatsApp
            sendWhatsApp,
            // DHL
            dhlEnabled,
            aagConfigured,
            esiAvailable,
            gutmannAvailable,
            hgsAvailable,
            hgsLoading,
            onOpenEsi,
            onOpenGutmann,
            onOpenHgs,
            dhlDialogVisible,
            dhlWeight,
            dhlProduct,
            dhlProducts,
            dhlLength,
            dhlWidth,
            dhlHeight,
            dhlLoading,
            dhlExistingShipments,
            dhlRecipientDisplay,
            openDhlDialog,
            onDhlCreate,
            downloadDhlLabel,
            onDhlDelete,
            waDialogVisible,
            waDialogPhone,
            waDialogAttachmentName,
            waPhoneOptions,
            waSelectedTemplate,
            waTemplateParams,
            waRenderedPreview,
            onWhatsAppSend,
            openVehicleEdit,
            showOnDisplay,
            silverdatImport,
            silverdatFileInput,
            onSilverdatFileSelect,
            onImportSilverdat,
            onSilverdatImport,
            onSilverdatClose,
            aagLoading,
            onOpenAag,
            // KI-Positionsvorschläge
            showAiSuggest,
            aiLoading,
            aiDialogVisible,
            aiSuggestedItems,
            onAiSuggest,
            onAiConfirmed,
            specialDialogVisible,
            showSpecialButton,
            wallDisplayEnabled,
            mechanicModeEnabled,
            wartungEnabled,
            partsRequestsList,
            recentVendorsList,
            onRequestPart,
            onOrderPart,
            onRevertPart,
            onDeletePart,
            onPhotoPart,
            photoDialog,
            closePhotoDialog,
            openFullscreen,
            onPhotoFileSelected,
            deletePhoto,
            openSpecialDialog
        }
    }
})
</script>

<style scoped>
/* ============================================
   FAKTURA VIEW - PROFESSIONELLES LAYOUT
   ============================================ */

/* Container */
.faktura-container {
    padding: 24px;
    max-width: 1800px;
}

/* Sektionen mit einheitlichem Abstand */
.faktura-section {
    margin-bottom: 24px;
}

.faktura-section:last-child {
    margin-bottom: 0;
}

.section-disabled {
    pointer-events: none;
    opacity: 0.45;
    user-select: none;
}

/* ============================================
   CARD STYLING - EINHEITLICH
   ============================================ */

.faktura-card {
    height: 100%;
    border-radius: 8px;
}

.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #f5f5f5;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}

.faktura-card__body {
    padding: 16px !important;
}

/* ============================================
   INPUT FELDER
   ============================================ */

.faktura-card__body :deep(.v-input) {
    flex: unset;
    grid-template-rows: auto !important;
}

.faktura-card__body :deep(.v-input__details) {
    display: none !important;
}

.faktura-card__body :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 12px;
    --v-field-padding-end: 12px;
}
</style>
