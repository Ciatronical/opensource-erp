<!-- src/features/lxcars/views/car/car.scan.view.vue -->

<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Titel -->
        <div class="d-flex align-center mb-3">
            <v-icon color="primary" class="mr-1">mdi-camera-document</v-icon>
            <h1 class="text-h6 mb-0">{{ t('CarScanView.title') }}</h1>
        </div>

        <!-- Fehler: kein API-Key -->
        <v-alert v-if="!hasApiKey" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('CarScanView.errors.noApiKey') }}
        </v-alert>

        <!-- ========== ANSICHT 1: Scan-Liste ========== -->
        <template v-if="step === 'list'">
            <v-card variant="outlined" elevation="1" :disabled="!hasApiKey">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center ga-2">
                    <v-icon class="mr-2" size="small">mdi-format-list-bulleted</v-icon>
                    <span class="text-subtitle-1 font-weight-medium">{{ t('CarScanView.scanList.title') }}</span>
                    <v-spacer />
                    <v-text-field
                        v-model="scanSearch"
                        :placeholder="t('CarScanView.scanList.searchPlaceholder')"
                        prepend-inner-icon="mdi-magnify"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        style="max-width: 280px;"
                        @keydown.enter="loadScans(1)"
                        @click:clear="scanSearch = ''; loadScans(1)"
                    />
                    <v-btn
                        variant="text"
                        size="small"
                        @click="loadScans(1)"
                    >
                        <v-icon start size="small">mdi-refresh</v-icon>
                        {{ t('CarScanView.scanList.refresh') }}
                    </v-btn>
                </v-card-title>
                <v-divider />

                <!-- Loading -->
                <div v-if="loadingScans" class="text-center py-8">
                    <v-progress-circular indeterminate size="32" width="3" />
                    <div class="text-body-2 text-medium-emphasis mt-2">{{ t('CarScanView.scanList.loading') }}</div>
                </div>

                <!-- Fehler beim Laden -->
                <v-alert v-else-if="scanListError" type="error" variant="tonal" density="compact" class="ma-3">
                    {{ t('CarScanView.errors.loadScans') }}: {{ scanListError }}
                </v-alert>

                <!-- Leere Liste -->
                <div v-else-if="!scanList.length" class="text-center text-medium-emphasis py-8">
                    <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-file-document-outline</v-icon>
                    <div>{{ t('CarScanView.scanList.empty') }}</div>
                </div>

                <!-- Scan-Tabelle -->
                <v-table v-else density="compact" hover class="scan-list-table">
                    <thead>
                        <tr>
                            <th style="width: 32px"></th>
                            <th>{{ t('CarScanView.scanList.date') }}</th>
                            <th>{{ t('CarScanView.scanList.firstname') }}</th>
                            <th>{{ t('CarScanView.scanList.name') }}</th>
                            <th>{{ t('CarScanView.scanList.licensePlate') }}</th>
                            <th>{{ t('CarScanView.scanList.maker') }}</th>
                            <th>{{ t('CarScanView.scanList.model') }}</th>
                            <th style="width: 36px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="scan in scanList"
                            :key="scan.scan_id"
                            :class="scanRowClass(scan)"
                            @click="onScanRowClick(scan)"
                        >
                            <td class="scan-list-table__status-cell">
                                <v-icon v-if="getScanIcon(scan)" :color="getScanIcon(scan).color" size="small">{{ getScanIcon(scan).icon }}</v-icon>
                            </td>
                            <td class="text-no-wrap">{{ formatTimestamp(scan.itime) }}</td>
                            <td>
                                <span v-if="getScanStatus(scan)" class="scan-link" @click.stop="openOwner(scan)">{{ scan.firstname || '' }}</span>
                                <template v-else>{{ scan.firstname || '' }}</template>
                            </td>
                            <td>
                                <span v-if="getScanStatus(scan)" class="scan-link" @click.stop="openOwner(scan)">{{ scan.name1 || '' }}</span>
                                <template v-else>{{ scan.name1 || '' }}</template>
                            </td>
                            <td class="font-weight-medium">
                                <span v-if="getScanStatus(scan)" class="scan-link scan-link--primary" @click.stop="openCar(scan)">{{ scan.registrationNumber || scan.registrationnumber || '' }}</span>
                                <template v-else>{{ scan.registrationNumber || scan.registrationnumber || '' }}</template>
                            </td>
                            <td>{{ scan.d1 || '' }}</td>
                            <td>{{ scan.d3 || '' }}</td>
                            <td class="text-center">
                                <v-tooltip v-if="getScanStatus(scan)" location="left" :text="t('CarScanView.buttons.rescan')">
                                    <template #activator="{ props: tip }">
                                        <v-btn v-bind="tip" icon="mdi-refresh" size="x-small" variant="text" color="orange" @click.stop="selectScan(scan)" />
                                    </template>
                                </v-tooltip>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <!-- Pagination -->
                <div v-if="scanList.length && scanTotalPages > 1" class="d-flex align-center justify-space-between px-3 py-2 bg-grey-lighten-5">
                    <v-btn
                        variant="text"
                        size="small"
                        :disabled="scanPage <= 1 || loadingScans"
                        @click="loadScans(scanPage - 1)"
                    >
                        <v-icon start size="small">mdi-chevron-left</v-icon>
                        {{ t('CarScanView.scanList.prevPage') }}
                    </v-btn>
                    <span class="text-body-2 text-medium-emphasis">
                        {{ t('CarScanView.scanList.pageInfo', { page: scanPage, totalPages: scanTotalPages, total: scanTotal }) }}
                    </span>
                    <v-btn
                        variant="text"
                        size="small"
                        :disabled="scanPage >= scanTotalPages || loadingScans"
                        @click="loadScans(scanPage + 1)"
                    >
                        {{ t('CarScanView.scanList.nextPage') }}
                        <v-icon end size="small">mdi-chevron-right</v-icon>
                    </v-btn>
                </div>
            </v-card>

            <!-- Upload-Button -->
            <div class="d-flex mt-3">
                <v-btn variant="outlined" :disabled="!hasApiKey" @click="step = 'upload'">
                    <v-icon start>mdi-upload</v-icon>
                    {{ t('CarScanView.buttons.upload') }}
                </v-btn>
            </div>
        </template>

        <!-- ========== ANSICHT 2: Upload ========== -->
        <template v-if="step === 'upload'">
            <v-card variant="outlined" elevation="1" :disabled="!hasApiKey">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small">mdi-upload</v-icon>
                    <span class="text-subtitle-1 font-weight-medium">{{ t('CarScanView.upload.title') }}</span>
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-6">
                    <div
                        class="scan-dropzone"
                        :class="{ 'scan-dropzone--active': isDragging }"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="onDrop"
                        @click="$refs.fileInput.click()"
                    >
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/*,.pdf"
                            class="d-none"
                            @change="onFileSelect"
                        />
                        <v-icon size="64" color="grey-lighten-1" class="mb-3">mdi-file-image-plus-outline</v-icon>
                        <div class="text-body-1 text-medium-emphasis">{{ t('CarScanView.upload.hint') }}</div>
                        <div class="text-caption text-disabled mt-1">{{ t('CarScanView.upload.formats') }}</div>

                        <!-- Preview -->
                        <div v-if="previewUrl" class="mt-4">
                            <v-img :src="previewUrl" max-height="200" max-width="300" class="mx-auto rounded" contain />
                            <div class="text-caption text-medium-emphasis mt-1">{{ selectedFileName }}</div>
                        </div>
                    </div>

                    <div class="d-flex justify-center ga-3 mt-4">
                        <v-btn variant="outlined" @click="resetToList">
                            <v-icon start>mdi-arrow-left</v-icon>
                            {{ t('CarScanView.buttons.backToList') }}
                        </v-btn>
                        <v-btn
                            color="primary"
                            :disabled="!selectedFile"
                            :loading="scanning"
                            @click="startUploadScan"
                        >
                            <v-icon start>mdi-magnify-scan</v-icon>
                            {{ scanning ? t('CarScanView.scanning') : t('CarScanView.upload.button') }}
                        </v-btn>
                    </div>

                    <v-alert v-if="scanError" type="error" variant="tonal" density="compact" class="mt-4">
                        {{ t('CarScanView.errors.scanFailed') }}: {{ scanError }}
                    </v-alert>
                </v-card-text>
            </v-card>
        </template>

        <!-- ========== ANSICHT 2b: KBA-Auswahl bei unvollständiger TSN ========== -->
        <template v-if="step === 'kba-select'">
            <v-alert type="warning" variant="tonal" density="compact" class="mb-3" prominent>
                <div class="font-weight-bold mb-1">{{ t('CarScanView.kbaSelect.title') }}</div>
                <div>{{ t('CarScanView.kbaSelect.description', { tsn: incompleteTsn }) }}</div>
                <div class="mt-1">
                    <v-chip size="small" class="mr-2">{{ t('CarScanView.kbaSelect.hsn') }}: {{ scanResult.kba?.hsn }}</v-chip>
                    <v-chip size="small">{{ t('CarScanView.kbaSelect.tsn') }}: {{ incompleteTsn }}</v-chip>
                </div>
            </v-alert>

            <v-card variant="outlined" elevation="1">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small">mdi-database-search</v-icon>
                    <span class="text-subtitle-1 font-weight-medium">KBA-Datenbank (HSN {{ scanResult.kba?.hsn }})</span>
                    <v-spacer />
                    <v-text-field
                        v-model="kbaSearchFilter"
                        :placeholder="t('CarScanView.kbaSelect.search')"
                        prepend-inner-icon="mdi-magnify"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        style="max-width: 280px;"
                    />
                </v-card-title>
                <v-divider />

                <!-- Loading -->
                <div v-if="loadingKbaList" class="text-center py-8">
                    <v-progress-circular indeterminate size="32" width="3" />
                    <div class="text-body-2 text-medium-emphasis mt-2">{{ t('CarScanView.kbaSelect.loading') }}</div>
                </div>

                <!-- Keine Ergebnisse -->
                <div v-else-if="!kbaListFiltered.length" class="text-center text-medium-emphasis py-8">
                    <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-database-off-outline</v-icon>
                    <div>{{ t('CarScanView.kbaSelect.noResults', { hsn: scanResult.kba?.hsn }) }}</div>
                </div>

                <!-- KBA-Tabelle -->
                <v-table v-else density="compact" hover fixed-header height="400" class="kba-select-table">
                    <thead>
                        <tr>
                            <th>{{ t('CarScanView.kbaSelect.tsn') }}</th>
                            <th>{{ t('CarScanView.kbaSelect.manufacturer') }}</th>
                            <th>{{ t('CarScanView.kbaSelect.model') }}</th>
                            <th>{{ t('CarScanView.kbaSelect.displacement') }}</th>
                            <th>{{ t('CarScanView.kbaSelect.power') }}</th>
                            <th>{{ t('CarScanView.kbaSelect.fuel') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(kba, idx) in kbaListFiltered"
                            :key="kba.id"
                            :class="idx % 2 === 0 ? 'bg-white' : 'bg-grey-lighten-4'"
                            style="cursor: pointer;"
                            @click="selectKbaEntry(kba)"
                        >
                            <td class="font-weight-medium">{{ kba.tsn }}{{ kba.d2 ? ' / ' + kba.d2 : '' }}</td>
                            <td>{{ kba.marke || kba.hersteller }}</td>
                            <td>{{ kba.name }}</td>
                            <td>{{ kba.hubraum ? kba.hubraum + ' ccm' : '' }}</td>
                            <td>{{ kba.leistung ? kba.leistung + ' kW' : '' }}</td>
                            <td>{{ kba.kraftstoff }}</td>
                        </tr>
                    </tbody>
                </v-table>
            </v-card>

            <!-- Aktions-Buttons -->
            <div class="d-flex ga-2 mt-4 flex-wrap">
                <v-btn variant="outlined" @click="resetToList">
                    <v-icon start>mdi-arrow-left</v-icon>
                    {{ t('CarScanView.kbaSelect.backButton') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="tonal" @click="skipKbaSelection">
                    <v-icon start>mdi-skip-next</v-icon>
                    {{ t('CarScanView.kbaSelect.skipButton') }}
                </v-btn>
            </div>

            <!-- Bestätigungsdialog: Special-KBA -->
            <v-dialog v-model="showSpecialKbaConfirm" max-width="550" persistent>
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon color="warning" class="mr-2">mdi-alert</v-icon>
                        {{ t('CarScanView.kbaSelect.confirmDialog.title') }}
                    </v-card-title>
                    <v-card-text>
                        <div class="text-body-1 mb-4">{{ t('CarScanView.kbaSelect.confirmDialog.text') }}</div>
                        <v-text-field
                            v-model="specialKbaForm.hersteller"
                            :label="t('CarScanView.kbaSelect.manufacturer') + ' *'"
                            variant="outlined"
                            density="compact"
                            :rules="[v => !!v?.trim() || t('CarScanView.kbaSelect.confirmDialog.required')]"
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="specialKbaForm.marke"
                            :label="t('CarScanView.kbaSelect.manufacturer') + ' (' + t('CarScanView.kbaSelect.model') + ') *'"
                            variant="outlined"
                            density="compact"
                            :rules="[v => !!v?.trim() || t('CarScanView.kbaSelect.confirmDialog.required')]"
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="specialKbaForm.d2"
                            label="D.2"
                            variant="outlined"
                            density="compact"
                            hide-details
                        />
                    </v-card-text>
                    <v-card-actions>
                        <v-btn variant="outlined" @click="showSpecialKbaConfirm = false">
                            <v-icon start>mdi-arrow-left</v-icon>
                            {{ t('CarScanView.kbaSelect.confirmDialog.backButton') }}
                        </v-btn>
                        <v-spacer />
                        <v-btn variant="tonal" color="warning" :disabled="!specialKbaFormValid" @click="confirmSpecialKba">
                            <v-icon start>mdi-database-plus</v-icon>
                            {{ t('CarScanView.kbaSelect.confirmDialog.confirmButton') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </template>

        <!-- ========== ANSICHT 3: Ergebnis + Kundenzuordnung ========== -->
        <template v-if="step === 'result'">
            <!-- Duplikat-Info: Fahrzeug existiert bereits -->
            <v-alert v-if="existingCarId && isOwnerMatch" type="info" variant="tonal" density="compact" class="mb-3" prominent>
                <div class="font-weight-bold mb-1">{{ t('CarScanView.duplicate.title') }}</div>
                <div v-for="(warn, i) in duplicateWarnings" :key="i">{{ warn }}</div>
                <div class="mt-1 font-weight-medium">{{ t('CarScanView.duplicate.alreadyComplete') }}</div>
                <v-btn
                    class="mt-2"
                    color="primary"
                    variant="flat"
                    size="small"
                    :loading="updatingExistingCar"
                    @click="updateExistingCarData"
                >
                    <v-icon start>mdi-update</v-icon>
                    {{ t('CarScanView.duplicate.updateData') }}
                </v-btn>
            </v-alert>
            <v-alert v-else-if="existingCarId" type="warning" variant="tonal" density="compact" class="mb-3" prominent>
                <div class="font-weight-bold mb-1">{{ t('CarScanView.duplicate.title') }}</div>
                <div v-for="(warn, i) in duplicateWarnings" :key="i">{{ warn }}</div>
                <div class="mt-1 font-weight-medium">{{ t('CarScanView.duplicate.ownerChange') }}</div>
            </v-alert>

            <!-- Loading-Overlay wenn Kunde geladen wird -->
            <v-overlay :model-value="loadingCustomer" contained class="align-center justify-center" persistent>
                <v-progress-circular indeterminate size="48" width="4" color="primary" />
            </v-overlay>

            <!-- ── Halter-Information ── -->
            <v-card variant="outlined" elevation="1">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small">mdi-account</v-icon>
                    <span class="text-subtitle-1 font-weight-medium">{{ t('CarScanView.result.ownerInfo') }}</span>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-3 px-3">
                    <!-- Name (Kundensuche) + Anrede -->
                    <v-row dense>
                        <v-col cols="12" sm="9">
                            <v-combobox
                                ref="ownerNameRef"
                                v-model="scanResult.owner.name"
                                v-model:menu="ownerNameMenuOpen"
                                :items="customerResults"
                                :loading="searchingCustomers"
                                item-title="name"
                                item-value="id"
                                no-filter
                                :label="t('CarScanView.result.ownerName')"
                                :placeholder="t('CarScanView.result.searchPlaceholder')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                @update:search="onOwnerNameSearch"
                                @update:model-value="onOwnerNameSelected"
                                @keydown.enter="onOwnerNameEnter"
                            >
                                <template #prepend-inner>
                                    <v-tooltip v-if="scanCrops.owner_firstname || scanCrops.owner_name" location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1" @mouseenter="loadCropImage('owner_firstname'); loadCropImage('owner_name')">mdi-image-outline</v-icon>
                                        </template>
                                        <div class="d-flex ga-1">
                                            <img v-if="typeof scanCrops.owner_firstname === 'string'" :src="scanCrops.owner_firstname" class="crop-tooltip-img" />
                                            <img v-if="typeof scanCrops.owner_name === 'string'" :src="scanCrops.owner_name" class="crop-tooltip-img" />
                                            <v-progress-circular v-if="scanCrops.owner_firstname === true || scanCrops.owner_name === true" indeterminate size="24" />
                                        </div>
                                    </v-tooltip>
                                    <v-icon v-else size="small" class="mr-1">mdi-account-search</v-icon>
                                </template>
                                <template #no-data>
                                    <v-list-item v-if="ownerNameSearchText.length >= 2" :title="t('CarScanView.result.noResults')" />
                                </template>
                                <template #item="{ item, props: itemProps }">
                                    <v-list-item v-bind="itemProps" :subtitle="[item.raw.street, item.raw.zipcode + ' ' + item.raw.city].filter(Boolean).join(' · ')" />
                                </template>
                            </v-combobox>
                        </v-col>
                        <v-col cols="12" sm="3">
                            <v-select
                                v-model="scanResult.owner.greeting"
                                :items="greetingOptions"
                                :label="t('CarScanView.result.ownerGreeting')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                clearable
                            />
                        </v-col>
                    </v-row>
                    <!-- Strasse -->
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field v-model="scanResult.owner.address1" :label="t('CarScanView.result.ownerStreet')" variant="outlined" density="compact" hide-details>
                                <template v-if="scanCrops.owner_address1" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('owner_address1')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.owner_address1 === 'string'" :src="scanCrops.owner_address1" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                    </v-row>
                    <!-- PLZ + Ort -->
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field v-model="scanResult.owner.address2" :label="t('CarScanView.result.ownerAddress')" variant="outlined" density="compact" hide-details>
                                <template v-if="scanCrops.owner_address2" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('owner_address2')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.owner_address2 === 'string'" :src="scanCrops.owner_address2" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                    </v-row>

                    <v-divider class="my-3" />

                    <!-- Kommunikation -->
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.contact" :label="t('CustomerVendorEditView.fields.contact')" variant="outlined" density="compact" hide-details prepend-inner-icon="mdi-account-outline" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.phone" :label="t('CarScanView.result.ownerPhone')" variant="outlined" density="compact" hide-details type="tel" prepend-inner-icon="mdi-phone" />
                        </v-col>
                    </v-row>
                    <template v-for="(entry, idx) in ownerPhoneNumbers" :key="'phone-' + idx">
                        <v-row dense>
                            <v-col cols="12" sm="5">
                                <v-text-field v-model="entry.label" :label="t('CustomerVendorEditView.fields.phoneLabel')" variant="outlined" density="compact" hide-details />
                            </v-col>
                            <v-col cols="10" sm="5">
                                <v-text-field v-model="entry.number" :label="t('CustomerVendorEditView.fields.phoneNumber')" variant="outlined" density="compact" hide-details />
                            </v-col>
                            <v-col cols="2" sm="2" class="d-flex align-center">
                                <v-btn icon size="small" variant="text" color="error" @click="removeOwnerPhone(idx)">
                                    <v-icon>mdi-close</v-icon>
                                </v-btn>
                            </v-col>
                        </v-row>
                    </template>
                    <v-row dense>
                        <v-col cols="12">
                            <v-btn variant="text" size="small" prepend-icon="mdi-plus" @click="addOwnerPhone">
                                {{ t('CustomerVendorEditView.fields.addPhone') }}
                            </v-btn>
                        </v-col>
                    </v-row>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.email" :label="t('CarScanView.result.ownerEmail')" variant="outlined" density="compact" hide-details type="email" prepend-inner-icon="mdi-email-outline" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.cc" :label="t('CustomerVendorEditView.fields.cc')" variant="outlined" density="compact" hide-details type="email" />
                        </v-col>
                    </v-row>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.bcc" :label="t('CustomerVendorEditView.fields.bcc')" variant="outlined" density="compact" hide-details type="email" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.homepage" :label="t('CustomerVendorEditView.fields.homepage')" variant="outlined" density="compact" hide-details prepend-inner-icon="mdi-web" />
                        </v-col>
                    </v-row>
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.invoice_mail" :label="t('CustomerVendorEditView.fields.invoice_mail')" variant="outlined" density="compact" hide-details type="email" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="scanResult.owner.delivery_order_mail" :label="t('CustomerVendorEditView.fields.delivery_order_mail')" variant="outlined" density="compact" hide-details type="email" />
                        </v-col>
                    </v-row>
                    <!-- Natürliche Person + Kundentyp -->
                    <v-row dense>
                        <v-col cols="12" sm="6">
                            <v-switch
                                v-model="scanResult.owner.natural_person"
                                :label="t('CustomerVendorEditView.fields.natural_person')"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col v-if="businessTypes.length > 1" cols="12" sm="6">
                            <v-select
                                v-model="scanResult.owner.business_id"
                                :items="businessTypes"
                                :label="t('CarScanView.result.ownerBusinessType')"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                :rules="businessTypes.length > 1 ? [v => !!v || t('CarScanView.result.businessTypeRequired')] : []"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <!-- ── Fahrzeug-Identifikation ── -->
            <v-card class="mt-3" variant="outlined" elevation="1">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                    <v-icon class="mr-2" size="small">mdi-car</v-icon>
                    <span class="text-subtitle-1 font-weight-medium">{{ t('CarScanView.result.vehicleData') }}</span>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-3 px-3">
                    <!-- Kennzeichen + Modell -->
                    <v-row dense>
                        <v-col cols="12" sm="5">
                            <v-combobox
                                v-model="scanResult.car.c_ln"
                                :items="carSearchResults"
                                :loading="searchingCars"
                                item-title="c_ln"
                                item-value="c_id"
                                no-filter
                                :label="t('CarEditView.fields.c_ln')"
                                :placeholder="t('CarScanView.result.carSearchPlaceholder')"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                :error-messages="licensePlateError"
                                @update:search="onCarSearch"
                                @update:model-value="onCarSelected"
                                @keydown.enter="onCarEnter"
                            >
                                <template #prepend-inner>
                                    <v-tooltip v-if="scanCrops.c_ln" location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1" @mouseenter="loadCropImage('c_ln')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_ln === 'string'" :src="scanCrops.c_ln" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                    <v-icon v-else size="small" class="mr-1">mdi-card-text</v-icon>
                                </template>
                                <template #no-data>
                                    <v-list-item v-if="carSearchText.length >= 2" :title="t('CarScanView.result.noCarResults')" />
                                </template>
                                <template #item="{ item, props: itemProps }">
                                    <v-list-item v-bind="itemProps" :subtitle="[item.raw.owner_name, [item.raw.hersteller, item.raw.modell].filter(Boolean).join(' ')].filter(Boolean).join(' · ')" />
                                </template>
                            </v-combobox>
                        </v-col>
                        <v-col cols="12" sm="7">
                            <v-text-field
                                :model-value="vehicleName"
                                :label="t('CarEditView.fields.c_mt')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                readonly
                                bg-color="grey-lighten-4"
                            />
                        </v-col>
                    </v-row>

                    <!-- HSN / TSN / D.2 / Emissionsklasse -->
                    <v-row dense>
                        <v-col cols="4" sm="2">
                            <v-text-field v-model="scanResult.car.c_2" :label="t('CarEditView.fields.c_2')" variant="outlined" density="compact" hide-details maxlength="4">
                                <template v-if="scanCrops.c_2" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_2')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_2 === 'string'" :src="scanCrops.c_2" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                        <v-col cols="4" sm="3">
                            <v-text-field v-model="scanResult.car.c_3" :label="t('CarEditView.fields.c_3')" variant="outlined" density="compact" hide-details maxlength="10">
                                <template v-if="scanCrops.c_3" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_3')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_3 === 'string'" :src="scanCrops.c_3" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                        <v-col cols="4" sm="4">
                            <v-text-field v-model="scanResult.kba.d2" :label="t('CarEditView.fields.d2')" variant="outlined" density="compact" hide-details>
                                <template v-if="scanCrops.d2" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('d2')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.d2 === 'string'" :src="scanCrops.d2" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                        <v-col cols="4" sm="3">
                            <v-text-field v-model="scanResult.car.c_em" :label="t('CarEditView.fields.c_em')" variant="outlined" density="compact" hide-details maxlength="6">
                                <template v-if="scanCrops.c_em" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_em')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_em === 'string'" :src="scanCrops.c_em" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                    </v-row>

                    <v-divider class="my-3" />

                    <!-- Erstzulassung / Nächste HU -->
                    <v-row dense>
                        <v-col cols="6" sm="4">
                            <v-text-field v-model="scanResult.car.c_d" :label="t('CarEditView.fields.c_d')" variant="outlined" density="compact" hide-details>
                                <template v-if="scanCrops.c_d" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_d')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_d === 'string'" :src="scanCrops.c_d" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                        <v-col cols="6" sm="4">
                            <v-text-field
                                v-model="scanResult.car.c_hu"
                                :label="t('CarScanView.result.nextHu') + (huExtrapolated ? ' (' + t('CarScanView.result.huExtrapolated') + ')' : '')"
                                variant="outlined"
                                density="compact"
                                hide-details
                            >
                                <template v-if="scanCrops.c_hu" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_hu')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_hu === 'string'" :src="scanCrops.c_hu" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                    </v-row>

                    <v-divider class="my-3" />

                    <!-- FIN + FIN-Check -->
                    <v-row dense>
                        <v-col cols="9">
                            <v-text-field v-model="scanResult.car.c_fin" :label="t('CarEditView.fields.c_fin')" variant="outlined" density="compact" hide-details maxlength="30">
                                <template v-if="scanCrops.c_fin" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_fin')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_fin === 'string'" :src="scanCrops.c_fin" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                        <v-col cols="3">
                            <v-text-field v-model="scanResult.car.c_finchk" :label="t('CarEditView.fields.c_finchk')" variant="outlined" density="compact" hide-details maxlength="1">
                                <template v-if="scanCrops.c_finchk" #prepend-inner>
                                    <v-tooltip location="end" content-class="crop-tooltip">
                                        <template #activator="{ props: tipProps }">
                                            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_finchk')">mdi-image-outline</v-icon>
                                        </template>
                                        <img v-if="typeof scanCrops.c_finchk === 'string'" :src="scanCrops.c_finchk" class="crop-tooltip-img" />
                                        <v-progress-circular v-else indeterminate size="24" />
                                    </v-tooltip>
                                </template>
                            </v-text-field>
                        </v-col>
                    </v-row>

                    <!-- Technische Daten (KBA) -->
                    <template v-if="scanResult.kba && (scanResult.kba.hubraum || scanResult.kba.leistung || scanResult.kba.kraftstoff)">
                        <v-divider class="my-3" />
                        <div class="text-caption text-medium-emphasis mb-2 text-uppercase font-weight-bold" style="letter-spacing: 0.05em">
                            {{ t('CarScanView.result.technicalData') }}
                        </div>
                        <div class="d-flex flex-wrap ga-2">
                            <v-chip v-if="scanResult.kba.hubraum" size="small" variant="tonal" color="blue-grey" prepend-icon="mdi-engine">
                                {{ scanResult.kba.hubraum }} ccm
                            </v-chip>
                            <v-chip v-if="scanResult.kba.leistung" size="small" variant="tonal" color="blue-grey" prepend-icon="mdi-flash">
                                {{ scanResult.kba.leistung }} kW / {{ Math.round(scanResult.kba.leistung * 1.35962) }} PS
                            </v-chip>
                            <v-chip v-if="scanResult.kba.kraftstoff" size="small" variant="tonal" color="blue-grey" prepend-icon="mdi-gas-station">
                                {{ scanResult.kba.kraftstoff }}
                            </v-chip>
                        </div>
                    </template>
                </v-card-text>
            </v-card>

            <!-- Hinweis: Kundentyp erforderlich -->
            <v-alert v-if="businessTypes.length > 1 && !scanResult.owner.business_id" type="info" variant="tonal" density="compact" class="mt-3">
                {{ t('CarScanView.result.businessTypeRequired') }}
            </v-alert>

            <!-- Hinweis: Kunde ausgewählt -->
            <v-alert v-if="selectedCustomer" type="success" variant="tonal" density="compact" class="mt-3" closable @click:close="selectedCustomer = null">
                {{ t('CarScanView.result.customerSelected', { name: selectedCustomer.name, id: selectedCustomer.id }) }}
            </v-alert>

            <!-- Fehler beim Speichern -->
            <v-alert v-if="saveError" type="error" variant="tonal" density="compact" class="mt-3">
                {{ t('CarScanView.newCustomerForm.saveError') }}: {{ saveError }}
            </v-alert>

            <!-- Aktions-Buttons -->
            <div class="d-flex ga-2 mt-4 flex-wrap">
                <v-btn variant="outlined" @click="resetToList">
                    <v-icon start>mdi-arrow-left</v-icon>
                    {{ t('CarScanView.buttons.backToList') }}
                </v-btn>
                <v-spacer />
                <v-btn
                    v-if="!(existingCarId && isOwnerMatch)"
                    :color="selectedCustomer ? 'primary' : 'secondary'"
                    :variant="selectedCustomer ? 'flat' : 'tonal'"
                    :loading="savingVehicle"
                    :disabled="businessTypes.length > 1 && !scanResult.owner.business_id"
                    @click="onSaveClick"
                >
                    <v-icon start>{{ selectedCustomer ? 'mdi-content-save' : 'mdi-account-plus' }}</v-icon>
                    {{ selectedCustomer ? t('CarScanView.buttons.saveVehicle') : t('CarScanView.buttons.newCustomer') }}
                </v-btn>
            </div>

            <!-- Duplikat-Warnung Dialog -->
            <v-dialog v-model="showDuplicateDialog" max-width="560" persistent>
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon class="mr-2" color="warning">mdi-alert</v-icon>
                        {{ t('CarScanView.duplicate.customerTitle') }}
                        <v-spacer />
                        <v-btn icon="mdi-close" size="x-small" variant="text" @click="showDuplicateDialog = false" />
                    </v-card-title>
                    <v-divider />
                    <v-card-text>
                        <!-- Exakte Duplikate → Blockiert -->
                        <template v-if="customerDuplicateExact.length">
                            <div class="text-body-2 font-weight-medium mb-2">{{ t('CarScanView.duplicate.exactMatch') }}</div>
                            <v-list density="compact" class="mb-3">
                                <v-list-item v-for="dup in customerDuplicateExact" :key="dup.id" :title="dup.name" :subtitle="[dup.street, dup.zipcode + ' ' + dup.city].filter(Boolean).join(', ')" prepend-icon="mdi-account-alert">
                                    <template #append>
                                        <v-btn size="small" variant="text" color="primary" @click="useExistingCustomer(dup)">
                                            {{ t('CarScanView.duplicate.useExisting') }}
                                        </v-btn>
                                    </template>
                                </v-list-item>
                            </v-list>
                        </template>
                        <!-- Teilweise Duplikate → Warnung -->
                        <template v-if="customerDuplicatePartial.length">
                            <div class="text-body-2 font-weight-medium mb-2">{{ t('CarScanView.duplicate.partialMatch') }}</div>
                            <v-list density="compact" class="mb-3">
                                <v-list-item v-for="dup in customerDuplicatePartial" :key="dup.id" :title="dup.name" :subtitle="[dup.street, dup.zipcode + ' ' + dup.city].filter(Boolean).join(', ')" prepend-icon="mdi-account-question">
                                    <template #append>
                                        <v-btn size="small" variant="text" color="primary" @click="useExistingCustomer(dup)">
                                            {{ t('CarScanView.duplicate.useExisting') }}
                                        </v-btn>
                                    </template>
                                </v-list-item>
                            </v-list>
                        </template>
                    </v-card-text>
                    <v-divider />
                    <v-card-actions>
                        <v-btn variant="outlined" @click="showDuplicateDialog = false">{{ t('CarScanView.duplicate.cancel') }}</v-btn>
                        <v-spacer />
                        <v-btn v-if="!customerDuplicateExact.length" color="warning" variant="flat" @click="proceedCreateNewCustomer">
                            {{ t('CarScanView.duplicate.createAnyway') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </template>

        <!-- KBA-Fuzzy-Korrektur-Dialog: öffnet wenn gescannte HSN/TSN nicht in kba_lxcars -->
            <v-dialog v-model="kbaFuzzyDialog" max-width="720" persistent>
                <v-card>
                    <v-card-title class="d-flex align-center ga-2 py-3 px-4 bg-warning-lighten-5">
                        <v-icon color="warning-darken-1" size="24">mdi-alert-circle-outline</v-icon>
                        <span>{{ t('CarScanView.kbaFuzzy.title') }}</span>
                    </v-card-title>
                    <v-card-text class="pt-4">
                        <!-- Gescannte Werte immer als Text + optional als Crop-Bild -->
                        <div class="d-flex ga-4 mb-4 flex-wrap">
                            <div>
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('CarScanView.kbaFuzzy.scannedHsn') }}</div>
                                <div class="d-flex align-center ga-2">
                                    <v-chip color="error" variant="tonal" size="small" class="font-weight-bold">{{ kbaFuzzyOriginal.hsn }}</v-chip>
                                    <img v-if="typeof scanCrops.c_2 === 'string'" :src="scanCrops.c_2" style="max-height:32px; border:1px solid rgba(0,0,0,0.12); border-radius:4px;" />
                                </div>
                            </div>
                            <div>
                                <div class="text-caption text-medium-emphasis mb-1">{{ t('CarScanView.kbaFuzzy.scannedTsn') }}</div>
                                <div class="d-flex align-center ga-2">
                                    <v-chip color="error" variant="tonal" size="small" class="font-weight-bold">{{ kbaFuzzyOriginal.tsn }}</v-chip>
                                    <img v-if="typeof scanCrops.c_3 === 'string'" :src="scanCrops.c_3" style="max-height:32px; border:1px solid rgba(0,0,0,0.12); border-radius:4px;" />
                                </div>
                            </div>
                        </div>
                        <v-alert type="warning" variant="tonal" class="mb-4" density="compact">
                            {{ t('CarScanView.kbaFuzzy.warning') }}
                        </v-alert>

                        <template v-if="kbaFuzzySuggestions.length">
                            <div class="text-body-2 font-weight-medium mb-2">{{ t('CarScanView.kbaFuzzy.suggestions') }}</div>
                            <v-table density="compact" hover class="rounded border mb-2">
                                <thead>
                                    <tr>
                                        <th class="text-left">{{ t('CarScanView.kbaFuzzy.hsn') }}</th>
                                        <th class="text-left">{{ t('CarScanView.kbaFuzzy.tsn') }}</th>
                                        <th class="text-left">{{ t('CarScanView.kbaFuzzy.manufacturer') }}</th>
                                        <th class="text-left">{{ t('CarScanView.kbaFuzzy.model') }}</th>
                                        <th class="text-left">{{ t('CarScanView.kbaFuzzy.vehicleType') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="s in kbaFuzzySuggestions" :key="s.id" class="cursor-pointer" style="cursor:pointer" @click="applyKbaFuzzyCorrection(s)">
                                        <td><strong class="text-success-darken-2">{{ s.hsn }}</strong></td>
                                        <td><strong class="text-success-darken-2">{{ s.tsn }}</strong></td>
                                        <td>{{ s.marke || s.hersteller }}</td>
                                        <td>{{ s.name }}</td>
                                        <td>{{ s.fhzart }}</td>
                                        <td>
                                            <v-btn size="x-small" color="primary" variant="tonal" @click.stop="applyKbaFuzzyCorrection(s)">
                                                {{ t('CarScanView.kbaFuzzy.useButton') }}
                                            </v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </template>

                        <v-alert v-else type="info" variant="tonal" density="compact">
                            {{ t('CarScanView.kbaFuzzy.noSuggestions') }}
                        </v-alert>
                    </v-card-text>
                    <v-card-actions class="px-4 pb-3">
                        <v-btn color="grey" variant="text" @click="dismissKbaFuzzy">
                            {{ t('CarScanView.kbaFuzzy.keepButton') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

    </v-container>
</template>

<script>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { formatDateDE, parseShortDate, validateLicensePlate } from '@/features/lxcars/utils/validation.js'

export default {
    name: 'CarScanView',
    components: { NavbarView },

    setup() {
        const { t } = useI18n()
        const router = useRouter()
        const oserpData = oserpStore()
        const carsStore = lxcarsStore()

        // State
        const step = ref('list') // 'list' | 'upload' | 'result'
        const scanList = ref([])
        const loadingScans = ref(false)
        const scanListError = ref('')
        const scanPage = ref(1)
        const scanTotalPages = ref(1)
        const scanTotal = ref(0)
        const scanPerPage = 20
        const scanSearch = ref('')

        // Scan-Listen-Status: Kennzeichen → { exists, c_id, owner_name }
        const scanCarStatus = ref({})

        // Upload State
        const selectedFile = ref(null)
        const selectedFileName = ref('')
        const previewUrl = ref(null)
        const isDragging = ref(false)
        const scanning = ref(false)
        const scanError = ref('')

        // Result State
        const scanResult = ref({ car: {}, kba: {}, owner: {} })
        let pendingDetailPromise = null
        const selectedCustomer = ref(null)
        const customerResults = ref([])
        const searchingCustomers = ref(false)
        const customerSearchText = ref('')
        const loadingCustomer = ref(false)
        const duplicateWarnings = ref([])
        const existingCarId = ref(null)
        const existingOwnerName = ref('')

        // Kunden-Duplikat-Prüfung
        const customerDuplicateExact = ref([])
        const customerDuplicatePartial = ref([])
        const showDuplicateDialog = ref(false)
        const savingVehicle = ref(false)
        const updatingExistingCar = ref(false)

        // KBA-Auswahl bei unvollständiger TSN
        const kbaList = ref([])
        const loadingKbaList = ref(false)
        const kbaSearchFilter = ref('')
        const useSpecialKba = ref(false)
        const incompleteTsn = ref('')
        const showSpecialKbaConfirm = ref(false)
        const specialKbaForm = ref({ hersteller: '', marke: '', d2: '' })

        // KBA-Fuzzy-Korrektur
        const kbaFuzzyDialog = ref(false)
        const kbaFuzzySuggestions = ref([])
        const kbaFuzzyOriginal = ref({ hsn: '', tsn: '' })

        // Car-Autocomplete State (Kennzeichen)
        const carSearchResults = ref([])
        const searchingCars = ref(false)
        const carSearchText = ref('')

        // Owner-Name-Autocomplete State
        const ownerNameRef = ref(null)
        const ownerNameMenuOpen = ref(false)
        const ownerNameSearchText = ref('')

        // Kennzeichen-Validierung (reaktiv, auch bei programmatisch gesetzten Werten)
        const licensePlateError = computed(() => {
            const result = validateLicensePlate(scanResult.value.car?.c_ln)
            if (result === true) return ''
            return t('CarEditView.' + result)
        })

        // Anrede-Optionen
        const greetingOptions = ['Herr', 'Frau']

        // Zusätzliche Telefonnummern (Bezeichnung + Nummer)
        const ownerPhoneNumbers = computed(() => {
            if (!scanResult.value.owner.phone_numbers) {
                scanResult.value.owner.phone_numbers = []
            }
            return scanResult.value.owner.phone_numbers
        })

        function addOwnerPhone() {
            if (!scanResult.value.owner.phone_numbers) {
                scanResult.value.owner.phone_numbers = []
            }
            scanResult.value.owner.phone_numbers.push({ label: '', number: '' })
        }

        function removeOwnerPhone(index) {
            scanResult.value.owner.phone_numbers.splice(index, 1)
        }

        // Gefilterte KBA-Liste für kba-select Step
        const kbaListFiltered = computed(() => {
            if (!kbaSearchFilter.value || !kbaSearchFilter.value.trim()) return kbaList.value
            const q = kbaSearchFilter.value.toLowerCase()
            return kbaList.value.filter(k =>
                (k.name || '').toLowerCase().includes(q) ||
                (k.tsn || '').toLowerCase().includes(q) ||
                (k.d2 || '').toLowerCase().includes(q) ||
                (k.marke || '').toLowerCase().includes(q) ||
                (k.hersteller || '').toLowerCase().includes(q)
            )
        })

        // Prüft ob die TSN mindestens 3 aufeinanderfolgende Nullen enthält
        // TSN besteht ausschließlich aus Nullen (mind. 3) → Platzhalter
        function hasTsnPlaceholder(tsn) {
            return /^0{3,}$/.test((tsn || '').trim())
        }

        // KBA-Liste für eine HSN laden und zum kba-select Step wechseln
        async function showKbaSelection() {
            const hsn = scanResult.value.kba?.hsn || ''
            if (!hsn) {
                step.value = 'result'
                return
            }

            loadingKbaList.value = true
            kbaSearchFilter.value = ''
            step.value = 'kba-select'

            try {
                kbaList.value = await carsStore.lookupKbaByHsn(hsn)
            } catch (err) {
                console.error('KBA-Lookup Fehler:', err)
                kbaList.value = []
            } finally {
                loadingKbaList.value = false
            }
        }

        // Benutzer wählt einen KBA-Eintrag → KBA-Daten übernehmen, weiter zum Result
        function selectKbaEntry(kba) {
            // D2 aus dem Scan bewahren falls der KBA-Eintrag keins hat
            const scanD2 = scanResult.value.kba?.d2 || ''
            // Scan-KBA durch gewählten KBA-Eintrag ersetzen
            scanResult.value.kba = { ...kba }
            if (!kba.d2 && scanD2) {
                scanResult.value.kba.d2 = scanD2
            }
            // TSN + HSN im Fahrzeug-Datensatz durch die echten Werte aus der KBA ersetzen (statt 000)
            if (kba.tsn) {
                scanResult.value.car.c_2 = kba.hsn || scanResult.value.car.c_2
                scanResult.value.car.c_3 = kba.tsn
            }
            useSpecialKba.value = false
            step.value = 'result'
        }

        // Benutzer überspringt KBA-Auswahl → special_kba wird beim Speichern genutzt
        function skipKbaSelection() {
            // Formular mit vorhandenen Scan-Daten vorbelegen
            const kba = scanResult.value.kba || {}
            specialKbaForm.value = {
                hersteller: kba.hersteller || kba.marke || '',
                marke: kba.marke || kba.hersteller || '',
                d2: kba.d2 || '',
            }
            showSpecialKbaConfirm.value = true
        }

        const specialKbaFormValid = computed(() =>
            specialKbaForm.value.hersteller?.trim() && specialKbaForm.value.marke?.trim()
        )

        function confirmSpecialKba() {
            if (!specialKbaFormValid.value) return
            showSpecialKbaConfirm.value = false
            useSpecialKba.value = true
            // Formular-Daten in scanResult.kba übernehmen
            if (scanResult.value.kba) {
                scanResult.value.kba.hersteller = specialKbaForm.value.hersteller.trim()
                scanResult.value.kba.marke = specialKbaForm.value.marke.trim()
                scanResult.value.kba.d2 = (specialKbaForm.value.d2 || '').trim()
            }
            step.value = 'result'
        }

        // KBA-Fuzzy: Prüft ob HSN+TSN in kba_lxcars existiert; korrigiert automatisch bei eindeutigem Treffer
        async function checkKbaFuzzy(hsn, tsn) {
            if (!hsn || tsn.length < 3) return
            try {
                const result = await carsStore.lookupKbaFuzzy(hsn, tsn)
                if (result.exact) return  // Alles korrekt

                const suggestions = result.suggestions || []

                // Eindeutiger Treffer → sofort automatisch korrigieren, kein Dialog nötig
                const uniqueHsnTsn = [...new Set(suggestions.map(s => s.hsn + '/' + s.tsn))]
                if (uniqueHsnTsn.length === 1) {
                    applyKbaFuzzyCorrection(suggestions[0])
                    return
                }

                // Mehrere Treffer oder kein Treffer → Dialog anzeigen
                kbaFuzzySuggestions.value = suggestions
                kbaFuzzyOriginal.value = { hsn, tsn }
                kbaFuzzyDialog.value = true
            } catch (err) {
                console.error('KBA fuzzy check error:', err)
            }
        }

        function applyKbaFuzzyCorrection(suggestion) {
            const scanD2 = scanResult.value.kba?.d2 || ''
            scanResult.value.car.c_2 = suggestion.hsn
            scanResult.value.car.c_3 = suggestion.tsn
            scanResult.value.kba = { ...suggestion }
            if (!suggestion.d2 && scanD2) scanResult.value.kba.d2 = scanD2
            kbaFuzzyDialog.value = false
        }

        function dismissKbaFuzzy() {
            kbaFuzzyDialog.value = false
        }

        // Prüfen ob Halter im Scan = Halter in DB (Halterwechsel erkennen)
        const isOwnerMatch = computed(() => {
            if (!existingCarId.value || !existingOwnerName.value) return false
            const scanOwner = (scanResult.value.owner?.name || '').toLowerCase().trim()
            const dbOwner = existingOwnerName.value.toLowerCase().trim()
            if (!scanOwner || !dbOwner) return false
            return dbOwner.includes(scanOwner) || scanOwner.includes(dbOwner)
        })

        // API-Key Check
        const hasApiKey = computed(() => {
            if (oserpData.session?.is_demo) return true
            const config = oserpData.session?.company_config?.defaults_oserp
            return config && config.lxcarsapi && config.lxcarsapi.trim() !== ''
        })

        // Halter-Name formatieren
        const ownerDisplayName = computed(() => {
            const owner = scanResult.value.owner
            if (!owner) return ''
            return (owner.name || '').trim() || '–'
        })

        // Crop-Bilder aus scanResult.images (nach Scan oder Detail-Laden verfügbar)
        const scanCropKeys = {
            c_ln: ['registrationNumber_img', 'registrationnumber_img', 'registrationNumberImg'],
            c_2: ['hsn_img', 'hsnImg'],
            c_3: ['tsn_img', 'tsnImg', 'field_2_2_img'],
            d2: ['d2_1_img', 'd2_1Img', 'd2_2_img', 'd2_2Img', 'd2_3_img', 'd2_3Img', 'd2_4_img', 'd2_4Img'],
            c_fin: ['vin_img', 'vinImg'],
            c_finchk: ['field_3_img', 'field_3Img'],
            c_d: ['ez_img', 'ezImg'],
            c_hu: ['hu_img', 'huImg'],
            c_em: ['field_14_1_img', 'em_img', 'emImg', 'field_14_img'],
            owner_firstname: ['firstname_img', 'firstnameImg'],
            owner_name: ['name1_img', 'name2_img', 'name1Img', 'name2Img'],
            owner_address1: ['address1_img', 'address1Img'],
            owner_address2: ['address2_img', 'address2Img'],
        }
        // Geladene Crop-Bilder (lazy, pro Feld)
        const loadedCrops = ref({})
        const loadingCrops = ref({})

        // Crop-Feld → API-Feldname Mapping (umgekehrt aus scanCropKeys)
        const cropFieldMap = {}
        for (const [field, keys] of Object.entries(scanCropKeys)) {
            for (const key of keys) {
                const cropName = key.replace(/[_]?[iI]mg$/, '')
                if (!cropFieldMap[field]) cropFieldMap[field] = []
                cropFieldMap[field].push(cropName)
            }
        }

        const scanCrops = computed(() => {
            // Upload-Flow: Bilder liegen direkt in scanResult.images (altes Format)
            const imgs = scanResult.value.images
            if (imgs && typeof imgs === 'object' && Object.keys(imgs).length > 0) {
                const result = {}
                for (const [field, keys] of Object.entries(scanCropKeys)) {
                    for (const key of keys) {
                        if (imgs[key]) {
                            result[field] = `data:image/jpeg;base64,${imgs[key]}`
                            break
                        }
                    }
                }
                return result
            }

            // Listen-Flow: Bilder aus tmp-Cache (lazy geladen)
            const result = {}
            for (const field of Object.keys(scanCropKeys)) {
                if (loadedCrops.value[field]) {
                    result[field] = loadedCrops.value[field]
                } else if (availableCropFields.value.includes(field)) {
                    result[field] = true  // Markiert: verfügbar, aber noch nicht geladen
                }
            }
            return result
        })

        // Verfügbare Crop-Felder aus dem tmp-Cache
        const availableCropFields = ref([])

        // Crop-Liste vom Server holen (nach getScanDetail)
        async function loadCropFieldList(scanId) {
            if (!scanId) return
            try {
                const result = await carsStore.getScanTempCropList(scanId)
                // Server-Felder (z.B. 'hsn') auf Template-Felder (z.B. 'c_2') mappen
                const mapped = []
                for (const [field, cropNames] of Object.entries(cropFieldMap)) {
                    if (cropNames.some(cn => result.fields.includes(cn))) {
                        mapped.push(field)
                    }
                }
                availableCropFields.value = mapped
            } catch {
                availableCropFields.value = []
            }
        }

        // Einzelnes Crop-Bild laden (wird beim Hover aufgerufen)
        async function loadCropImage(field) {
            if (loadedCrops.value[field] || loadingCrops.value[field]) return
            const scanId = scanResult.value.raw?.scan_id
            if (!scanId) return

            // Alle moeglichen Crop-Dateinamen fuer dieses Feld (z.B. c_3 -> ['tsn', 'field_2_2'])
            // Duplikate entfernen, da scanCropKeys oft Cmel-/Snake-Varianten hat, die zum gleichen
            // Backend-Dateinamen kollabieren.
            const cropNames = [...new Set(cropFieldMap[field] || [])]
            if (!cropNames.length) return

            loadingCrops.value[field] = true
            try {
                // Alle Varianten durchprobieren, bis eine den Crop liefert
                for (const name of cropNames) {
                    try {
                        const data = await carsStore.getScanTempCrop(scanId, name)
                        if (data?.image) {
                            loadedCrops.value[field] = `data:${data.mime || 'image/jpeg'};base64,${data.image}`
                            return
                        }
                    } catch {
                        // Naechsten Alias probieren
                    }
                }
            } finally {
                loadingCrops.value[field] = false
            }
        }

        // HU-Datum auf nächsten Fälligkeitstermin hochrechnen
        // Neuwagen: erste HU 36 Monate nach Erstzulassung, danach alle 24 Monate
        // Gebrauchtwagen: HU-Datum + 24 Monate
        // Parst ISO (2026-10-01) und DE (01.10.2026) Datumsformate
        function parseDateAny(str) {
            if (!str) return null
            // DE-Format: TT.MM.JJJJ
            const de = str.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/)
            if (de) return new Date(+de[3], +de[2] - 1, +de[1])
            // ISO-Format: YYYY-MM-DD
            const d = new Date(str)
            return isNaN(d.getTime()) ? null : d
        }

        function extrapolateNextHu(huDateStr, firstRegDateStr) {
            const now = new Date()
            const nowMonth = new Date(now.getFullYear(), now.getMonth(), 1)

            if (huDateStr) {
                const hu = parseDateAny(huDateStr)
                if (!hu) return null

                let next = new Date(hu.getFullYear(), hu.getMonth(), 1)
                let extrapolated = false
                while (next < nowMonth) {
                    next.setMonth(next.getMonth() + 24)
                    extrapolated = true
                }

                return {
                    date: next,
                    formatted: `01.${String(next.getMonth() + 1).padStart(2, '0')}.${next.getFullYear()}`,
                    overdue: false,
                    extrapolated
                }
            }

            // Neuwagen: kein HU-Datum → erste HU 36 Monate nach Erstzulassung
            if (firstRegDateStr) {
                const firstReg = parseDateAny(firstRegDateStr)
                if (!firstReg) return null

                let next = new Date(firstReg.getFullYear(), firstReg.getMonth() + 36, 1)
                let extrapolated = false
                // Falls bereits fällig, danach alle 24 Monate
                while (next < nowMonth) {
                    next.setMonth(next.getMonth() + 24)
                    extrapolated = true
                }

                return {
                    date: next,
                    formatted: `01.${String(next.getMonth() + 1).padStart(2, '0')}.${next.getFullYear()}`,
                    overdue: false,
                    extrapolated
                }
            }

            return null
        }

        // Fahrzeugname (Hersteller + Modell) als readonly Anzeige
        const vehicleName = computed(() => {
            const kba = scanResult.value.kba || {}
            return [kba.hersteller || kba.d1, kba.name || kba.d3].filter(Boolean).join(' ')
        })

        // HU-Extrapolation: einmalig beim Laden anwenden, dann editierbar
        const huExtrapolated = ref(false)

        function applyHuExtrapolation() {
            const car = scanResult.value.car || {}
            const result = extrapolateNextHu(car.c_hu, car.c_d)
            if (result) {
                car.c_hu = result.formatted
                huExtrapolated.value = result.extrapolated
            } else {
                huExtrapolated.value = false
            }
        }

        // ===== Duplikat-Prüfung =====

        async function checkDuplicates(car) {
            const warnings = []
            let foundCarId = null
            let foundOwnerName = ''
            const checks = []

            if (car.c_ln) {
                checks.push(
                    carsStore.checkLicensePlate(car.c_ln, 0)
                        .then(result => {
                            if (result?.exists) {
                                warnings.push(t('CarScanView.duplicate.licensePlate', { plate: car.c_ln, owner: result.owner_name || '?' }))
                                if (result.c_id) foundCarId = result.c_id
                                if (result.owner_name) foundOwnerName = result.owner_name
                            }
                        })
                        .catch(() => {})
                )
            }

            if (car.c_fin && car.c_fin.length >= 10) {
                checks.push(
                    carsStore.checkFin(car.c_fin, 0)
                        .then(result => {
                            if (result?.exists) {
                                warnings.push(t('CarScanView.duplicate.fin', { fin: car.c_fin, owner: result.owner_name || '?' }))
                                if (result.c_id && !foundCarId) foundCarId = result.c_id
                                if (result.owner_name && !foundOwnerName) foundOwnerName = result.owner_name
                            }
                        })
                        .catch(() => {})
                )
            }

            await Promise.all(checks)
            duplicateWarnings.value = warnings
            existingCarId.value = foundCarId
            existingOwnerName.value = foundOwnerName
        }

        // ===== Scan-Liste =====

        // Automatische Suche ab 3 Zeichen (oder wenn Feld geleert wird)
        let scanSearchTimeout = null
        watch(scanSearch, (val) => {
            if (scanSearchTimeout) clearTimeout(scanSearchTimeout)
            const q = (val || '').trim()
            if (q.length >= 3 || q.length === 0) {
                scanSearchTimeout = setTimeout(() => loadScans(1), 300)
            }
        })

        async function loadScans(page) {
            if (page !== undefined) scanPage.value = page
            loadingScans.value = true
            scanListError.value = ''
            try {
                const result = await carsStore.getScans(scanPage.value, scanPerPage, (scanSearch.value || '').trim())
                scanList.value = result.results
                scanTotalPages.value = result.total_pages
                scanTotal.value = result.total

                // Status für jedes Kennzeichen prüfen (parallel, vor Anzeige)
                await checkScanListStatus(result.results)
            } catch (err) {
                scanListError.value = err.message || String(err)
                scanList.value = []
            } finally {
                loadingScans.value = false
            }
        }

        async function checkScanListStatus(scans) {
            const plates = [...new Set(scans.map(s => normalizePlate(s.registrationNumber || s.registrationnumber || '')).filter(Boolean))]
            if (!plates.length) {
                scanCarStatus.value = {}
                return
            }

            try {
                const results = await carsStore.checkLicensePlateBatch(plates)
                const status = {}
                for (const plate of plates) {
                    const match = results[plate.toUpperCase()]
                    if (match?.exists) {
                        status[plate] = match
                    }
                }
                scanCarStatus.value = status
            } catch {
                scanCarStatus.value = {}
            }
        }

        // Kennzeichen normalisieren (gleiche Logik wie Backend mapScanToCarFields):
        // "MOL ID 100" → "MOL-ID100", "B AB 1234" → "B-AB1234"
        function normalizePlate(raw) {
            if (!raw) return ''
            const parts = raw.trim().split(/\s+/)
            if (parts.length > 1) {
                return (parts[0] + '-' + parts.slice(1).join('')).replace(/\*/g, '').toUpperCase()
            }
            return raw.replace(/\*/g, '').toUpperCase().trim()
        }

        function getScanPlate(scan) {
            return normalizePlate(scan.registrationNumber || scan.registrationnumber || '')
        }

        function getScanStatus(scan) {
            return scanCarStatus.value[getScanPlate(scan)] || null
        }

        function scanRowClass(scan) {
            const status = getScanStatus(scan)
            if (!status) return 'scan-list-table__row'

            // Fahrzeug existiert → prüfen ob Halter übereinstimmt
            const scanName = (scan.name1 || '').toLowerCase().trim()
            const dbName = (status.owner_name || '').toLowerCase().trim()
            const ownerMatch = scanName && dbName && (dbName.includes(scanName) || scanName.includes(dbName))

            if (ownerMatch) return 'scan-list-table__row scan-list-table__row--complete'
            return 'scan-list-table__row scan-list-table__row--exists'
        }

        function onScanRowClick(scan) {
            const status = getScanStatus(scan)
            if (status) {
                // Existierendes Fahrzeug → direkt öffnen
                router.push({ name: 'car', params: { id: status.c_id } })
                return
            }
            selectScan(scan)
        }

        function openCar(scan) {
            const status = getScanStatus(scan)
            if (status?.c_id) {
                router.push({ name: 'car', params: { id: status.c_id } })
            }
        }

        function openOwner(scan) {
            const status = getScanStatus(scan)
            if (status?.customer_id) {
                router.push({ name: 'customer-edit', params: { id: status.customer_id } })
            }
        }

        // Icon + Farbe für Status-Markierung in der Scan-Liste
        function getScanIcon(scan) {
            const status = getScanStatus(scan)
            if (!status) return null
            const scanName = (scan.name1 || '').toLowerCase().trim()
            const dbName = (status.owner_name || '').toLowerCase().trim()
            const ownerMatch = scanName && dbName && (dbName.includes(scanName) || scanName.includes(dbName))
            if (ownerMatch) return { icon: 'mdi-check-circle', color: 'grey' }
            return { icon: 'mdi-swap-horizontal-circle', color: 'orange' }
        }

        function formatTimestamp(ts) {
            if (!ts) return ''
            // PostgreSQL liefert "2026-03-20 14:30:00" (Leerzeichen statt T) → ISO-konform machen
            const date = new Date(String(ts).replace(' ', 'T') + 'Z')
            if (isNaN(date)) return ts
            return date.toLocaleDateString('de-DE', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                timeZone: 'Europe/Berlin',
            })
        }

        // Scan aus der Liste auswählen → sofort anzeigen, Detail im Hintergrund laden
        async function selectScan(scan) {
            scanError.value = ''

            // Sofort alle verfügbaren Daten aus dem Listeneintrag mappen
            // (Scan-Liste liefert bereits die Detail-Rohdaten, nur ohne Bilder)
            const mapped = mapScanListItemToFields(scan)
            scanResult.value = {
                ...mapped,
                images: null,
                // raw.scan_id wird von loadCropImage benoetigt, um die Crops
                // aus backend/tmp/{scan_id}/ via getScanTempCrop zu laden
                raw: { scan_id: scan.scan_id, scan_detail_id: scan.scan_detail_id }
            }

            // Duplikat-Info aus bereits geladenem scanCarStatus nutzen
            const status = getScanStatus(scan)
            if (status) {
                duplicateWarnings.value = [t('CarScanView.duplicate.licensePlate', { plate: mapped.car.c_ln, owner: status.owner_name || '?' })]
                existingCarId.value = status.c_id
                existingOwnerName.value = status.owner_name || ''
            } else {
                duplicateWarnings.value = []
                existingCarId.value = null
                existingOwnerName.value = ''
            }

            // HU-Datum extrapolieren
            applyHuExtrapolation()

            // Anrede ermitteln (awaiten, damit sie vor Anzeige gesetzt ist)
            if (scanResult.value.owner?.name && !scanResult.value.owner.greeting) {
                await lookupGreeting(scanResult.value.owner.name)
            }

            // Auto-Match: Kunde anhand Name + Adresse finden
            selectedCustomer.value = null
            await tryAutoMatchCustomer()

            // Kunden-Suche immer ausführen (für Dropdown-Liste)
            const name = formatOwnerName(scanResult.value.owner)
            if (name) {
                await searchCustomers(name)
            }

            // TSN-Prüfung: Bei Platzhalter-TSN (000+) → KBA-Auswahl anbieten
            const tsnFull = scanResult.value.car?.c_3 || scanResult.value.kba?.tsn || ''
            if (hasTsnPlaceholder(tsnFull)) {
                incompleteTsn.value = tsnFull
                await showKbaSelection()
            } else {
                step.value = 'result'
                // KBA-Validierung: HSN+TSN in kba_lxcars prüfen → Korrektur anbieten
                const hsn = (scanResult.value.car?.c_2 || '').trim()
                const tsn = tsnFull.substring(0, 3)
                await checkKbaFuzzy(hsn, tsn)
            }

            // Dropdown automatisch öffnen wenn Suchergebnisse vorhanden
            if (customerResults.value.length && step.value === 'result') {
                await nextTick()
                ownerNameMenuOpen.value = true
            }

            // Reset lazy-loaded crops und sofort die verfügbare Crop-Liste laden.
            // Beim ersten Aufruf existiert der tmp-Cache noch nicht — er wird erst
            // von getScanDetail/cacheScanToTmp angelegt; daher unten nach erfolgreichem
            // Detail-Load nochmal aufrufen.
            loadedCrops.value = {}
            availableCropFields.value = []
            loadCropFieldList(scan.scan_id)

            // Detail im Hintergrund laden (volle Fahrzeugdaten, FIN etc.)
            pendingDetailPromise = carsStore.getScanDetail(scan.scan_id)
                .then(async (detail) => {
                    normalizeScanResult(detail)
                    // Benutzer-Bearbeitungen bewahren: nur fehlende Felder ergänzen
                    mergeScanDetail(detail)
                    applyHuExtrapolation()
                    // Anrede nachholen falls noch leer (z.B. Vorname erst aus Detail verfügbar)
                    if (scanResult.value.owner?.name && !scanResult.value.owner.greeting) {
                        await lookupGreeting(scanResult.value.owner.name)
                    }
                    // Auto-Match mit vollständigen Daten erneut versuchen (falls beim ersten Mal fehlgeschlagen)
                    if (!selectedCustomer.value) {
                        const detailMatched = await tryAutoMatchCustomer()
                        if (!detailMatched) {
                            const name = formatOwnerName(scanResult.value.owner)
                            if (name) searchCustomers(name)
                        }
                    }
                    // Volle Duplikat-Prüfung mit FIN (nur wenn noch nicht als Duplikat erkannt)
                    await checkDuplicates(scanResult.value.car || {})

                    // Crop-Liste erneut laden — getScanDetail hat den tmp-Cache
                    // möglicherweise gerade erst angelegt (cacheScanToTmp).
                    if (!availableCropFields.value.length) {
                        await loadCropFieldList(scan.scan_id)
                    }
                })
                .catch(err => console.error('Error loading scan detail:', err))
        }

        // ===== Upload =====

        function onFileSelect(event) {
            const file = event.target.files[0]
            if (file) setFile(file)
        }

        function onDrop(event) {
            isDragging.value = false
            const file = event.dataTransfer.files[0]
            if (file) setFile(file)
        }

        function setFile(file) {
            selectedFile.value = file
            selectedFileName.value = file.name
            scanError.value = ''

            if (file.type.startsWith('image/')) {
                previewUrl.value = URL.createObjectURL(file)
            } else {
                previewUrl.value = null
            }
        }

        async function startUploadScan() {
            if (!selectedFile.value) return

            scanning.value = true
            scanError.value = ''

            try {
                const base64 = await fileToBase64(selectedFile.value)
                const isPdf = selectedFile.value.type === 'application/pdf'

                const result = await carsStore.scanFahrzeugschein(base64, isPdf)
                // Original-Bild: temp_image_id vom Backend (bereits auf Disk gespeichert)
                // + base64 für In-Memory-Vorschau im Frontend
                result.originalImage = base64
                result.isPdf = isPdf
                // temp_image_id kommt vom Backend (scanFahrzeugschein speichert Original als Temp-Datei)
                normalizeScanResult(result)
                pendingDetailPromise = null // Upload hat Bilder sofort, kein Detail-Load noetig
                scanResult.value = result
                applyHuExtrapolation()

                // Anrede ermitteln (awaiten, damit sie vor Anzeige gesetzt ist)
                if (scanResult.value.owner?.name && !scanResult.value.owner.greeting) {
                    await lookupGreeting(scanResult.value.owner.name)
                }

                // Duplikat-Prüfung (Kennzeichen / FIN)
                await checkDuplicates(result.car || {})

                // Auto-Match: Kunde anhand Name + Adresse finden
                selectedCustomer.value = null
                await tryAutoMatchCustomer()

                // Kunden-Suche immer ausführen (für Dropdown-Liste)
                const name = formatOwnerName(result.owner)
                if (name) {
                    await searchCustomers(name)
                }

                // TSN-Prüfung: Bei Platzhalter-TSN (000+) → KBA-Auswahl anbieten
                const tsnFull = scanResult.value.car?.c_3 || scanResult.value.kba?.tsn || ''
                if (hasTsnPlaceholder(tsnFull)) {
                    incompleteTsn.value = tsnFull
                    await showKbaSelection()
                } else {
                    step.value = 'result'
                    // KBA-Validierung: HSN+TSN in kba_lxcars prüfen → Korrektur anbieten
                    const hsn = (scanResult.value.car?.c_2 || '').trim()
                    const tsn = tsnFull.substring(0, 3)
                    await checkKbaFuzzy(hsn, tsn)
                }

                // Dropdown automatisch öffnen wenn Suchergebnisse vorhanden
                if (customerResults.value.length && step.value === 'result') {
                    await nextTick()
                    ownerNameMenuOpen.value = true
                }
            } catch (err) {
                scanError.value = err.message || String(err)
            } finally {
                scanning.value = false
            }
        }

        function fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader()
                reader.onload = () => {
                    const result = reader.result
                    const base64 = result.split(',')[1]
                    resolve(base64)
                }
                reader.onerror = reject
                reader.readAsDataURL(file)
            })
        }

        // ===== Hilfsfunktionen =====

        // Einfacher Datumsparser (spiegelt parseScanDate aus cars.php)
        // Formate: "03.26", "03.2026", "15.03.2020", "15.03.20", "03/26", "0326"
        // Gibt DE-Format zurück: TT.MM.JJJJ
        function parseScanDateJS(dateStr) {
            if (!dateStr) return null
            dateStr = dateStr.trim().replace(/\//g, '.')
            const parts = dateStr.split('.')

            if (parts.length === 2 && parts[0].length <= 2 && parts[1].length === 2) {
                const m = parseInt(parts[0]), y = 2000 + parseInt(parts[1])
                if (m >= 1 && m <= 12) return `01.${String(m).padStart(2, '0')}.${y}`
            }
            if (parts.length === 2 && parts[0].length <= 2 && parts[1].length === 4) {
                const m = parseInt(parts[0]), y = parseInt(parts[1])
                if (m >= 1 && m <= 12 && y >= 1886) return `01.${String(m).padStart(2, '0')}.${y}`
            }
            if (parts.length === 3 && parts[2].length === 4) {
                return `${parts[0].padStart(2, '0')}.${parts[1].padStart(2, '0')}.${parts[2]}`
            }
            if (parts.length === 3 && parts[2].length === 2) {
                return `${parts[0].padStart(2, '0')}.${parts[1].padStart(2, '0')}.${2000 + parseInt(parts[2])}`
            }
            if (dateStr.length === 4 && /^\d+$/.test(dateStr)) {
                const m = parseInt(dateStr.substring(0, 2)), y = 2000 + parseInt(dateStr.substring(2, 4))
                if (m >= 1 && m <= 12) return `01.${String(m).padStart(2, '0')}.${y}`
            }
            return null
        }

        // ISO-Datum (2026-10-01) → DE-Format (01.10.2026)
        function isoToDE(isoDate) {
            if (!isoDate) return isoDate
            const m = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})/)
            if (!m) return isoDate
            return `${m[3]}.${m[2]}.${m[1]}`
        }

        // Frontend-Pendant zu mapScanToCarFields (Backend: cars.php)
        // Mappt Rohdaten eines Scan-Listeneintrags auf car/kba/owner-Felder
        function mapScanListItemToFields(scan) {
            const plate = normalizePlate(scan.registrationNumber || scan.registrationnumber || scan.registration_number || '')

            let tsnFull = (scan.field_2_2 || scan.tsn || '').replace(/[^a-zA-Z0-9]/g, '')
            const tsnShort = tsnFull.toUpperCase().substring(0, 3)
            const d2 = ((scan.d2_1 || '') + (scan.d2_2 || '') + (scan.d2_3 || '') + (scan.d2_4 || '')).trim()

            const car = {
                c_ln:     plate.substring(0, 10),
                c_2:      (scan.hsn || '').substring(0, 4),
                c_3:      tsnFull.toUpperCase().substring(0, 10),
                c_fin:    (scan.vin || '').toUpperCase().substring(0, 30),
                c_finchk: (scan.field_3 || '').substring(0, 1),
                c_d:      parseScanDateJS(scan.ez),
                c_hu:     parseScanDateJS(scan.hu),
                c_em:     (scan.field_14_1 || scan.field_14 || '').substring(0, 6),
            }

            const kba = {
                hsn:        scan.hsn || '',
                tsn:        tsnShort,
                d2:         d2,
                d1:         scan.d1 || '',
                hersteller: scan.d1 || scan.Maker || scan.maker || '',
                marke:      scan.Maker || scan.maker || scan.d1 || '',
                name:       scan.Model || scan.model || '',
                d3:         scan.d3 || '',
                hubraum:    scan.ccm || '',
                leistung:   scan.powerkw || '',
                kraftstoff: scan.fuel || '',
            }

            const ownerName = formatOwnerName({ name: [scan.firstname, scan.name1 || scan.name2].filter(Boolean).join(' ').trim() })
            const owner = {
                name:        ownerName,
                address1:    scan.address1 || '',
                address2:    scan.address2 || '',
                phone:       '',
                email:       '',
                greeting:    '',
                business_id: businessTypes.value.length === 1 ? businessTypes.value[0].value : null,
                natural_person: true,
                phone_numbers: [],
                contact:     '',
                cc:          '',
                bcc:         '',
                homepage:    '',
                invoice_mail: '',
                delivery_order_mail: '',
            }
            return { car, kba, owner }
        }

        // Halter-Name formatieren (erstes + letztes Wort, richtige Groß-/Kleinschreibung)
        // Bindestrich-Namen (Hans-Peter) bleiben erhalten, einfache Zweitnamen werden entfernt
        function formatOwnerName(owner) {
            if (!owner) return ''
            const fullName = (owner.name || '').trim()
            if (!fullName) return ''

            // Firmen nicht kürzen
            const lower = fullName.toLowerCase()
            if (lower.includes(' gmbh') || lower.includes(' ohg') || lower.includes(' ag') || lower.includes(' kg')) {
                return fullName
            }

            // Namensteil richtig formatieren (auch Bindestrich-Namen: HANS-PETER → Hans-Peter)
            const capitalize = s => s.split('-').map(p => p.charAt(0).toUpperCase() + p.slice(1).toLowerCase()).join('-')

            const parts = fullName.split(/\s+/)
            if (parts.length > 2) {
                return [parts[0], parts[parts.length - 1]]
                    .map(capitalize)
                    .join(' ')
            }
            return parts.map(capitalize).join(' ')
        }

        // Backend-Daten normalisieren: Owner-Felder zusammenführen, Datumsformate konvertieren
        function normalizeScanResult(result) {
            // Owner: firstname + name zusammenführen, fehlende Felder ergänzen
            if (!result.owner) result.owner = {}
            const o = result.owner
            if (o.firstname) {
                o.name = [o.firstname, o.name].filter(Boolean).join(' ').trim()
                delete o.firstname
            }
            o.name = formatOwnerName(o)
            if (!('phone' in o)) o.phone = ''
            if (!('email' in o)) o.email = ''
            if (!('greeting' in o)) o.greeting = ''
            if (!('business_id' in o)) o.business_id = businessTypes.value.length === 1 ? businessTypes.value[0].value : null
            if (!('natural_person' in o)) o.natural_person = true
            if (!('phone_numbers' in o)) o.phone_numbers = []
            if (!('contact' in o)) o.contact = ''
            if (!('cc' in o)) o.cc = ''
            if (!('bcc' in o)) o.bcc = ''
            if (!('homepage' in o)) o.homepage = ''
            if (!('invoice_mail' in o)) o.invoice_mail = ''
            if (!('delivery_order_mail' in o)) o.delivery_order_mail = ''
            // Datumsfelder: ISO → DE-Format
            if (result.car) {
                if (result.car.c_d) result.car.c_d = isoToDE(result.car.c_d)
                if (result.car.c_hu) result.car.c_hu = isoToDE(result.car.c_hu)
            }
        }

        // Detail-Daten in bestehenden scanResult mergen ohne Benutzer-Bearbeitungen zu überschreiben.
        // Bilder + temp_image_id werden immer übernommen. Bei car/kba/owner werden nur leere Felder ergänzt.
        function mergeScanDetail(detail) {
            const current = scanResult.value

            // Meta immer übernehmen (Bilder kommen jetzt aus tmp-Cache, nicht mehr aus Detail)
            if (detail.temp_image_id) current.temp_image_id = detail.temp_image_id
            if (detail.originalImage) current.originalImage = detail.originalImage
            if (detail.isPdf !== undefined) current.isPdf = detail.isPdf
            // raw mergen, damit scan_id/scan_detail_id fuer loadCropImage erhalten bleiben
            if (detail.raw) current.raw = { ...(current.raw || {}), ...detail.raw }

            // car/kba/owner: nur leere Felder aus Detail ergänzen
            for (const section of ['car', 'kba', 'owner']) {
                const src = detail[section]
                if (!src) continue
                if (!current[section]) { current[section] = src; continue }
                for (const [key, val] of Object.entries(src)) {
                    if (val && !current[section][key]) {
                        current[section][key] = val
                    }
                }
            }
        }

        // Anrede über firstnameToGender-Tabelle ermitteln
        async function lookupGreeting(name) {
            const firstname = (name || '').trim().split(/\s+/)[0]
            if (!firstname) return
            try {
                const response = await axios.post('/api/customer_vendor/', {
                    action: 'lookupGreeting',
                    firstname
                })
                const greeting = response.data?.payload?.greeting
                if (greeting && scanResult.value.owner) {
                    scanResult.value.owner.greeting = greeting
                }
            } catch {
                // Stille Fehlerbehandlung
            }
        }

        // ===== Auto-Match Kunde (Name + Adresse) =====

        async function tryAutoMatchCustomer() {
            const owner = scanResult.value?.owner || {}
            const name = (owner.name || '').trim()
            if (!name) return false

            const addr = parseOwnerAddress(owner)
            if (!addr.zipcode) return false

            try {
                const match = await carsStore.autoMatchCustomerForScan(name, addr.street, addr.zipcode, addr.city)
                if (!match) return false

                // Kunde auto-selektieren + DB-Daten in Felder eintragen
                selectedCustomer.value = match
                scanResult.value.owner.name = match.name
                scanResult.value.owner.address1 = match.street || owner.address1
                scanResult.value.owner.address2 = [match.zipcode, match.city].filter(Boolean).join(' ') || owner.address2
                scanResult.value.owner.greeting = match.greeting || owner.greeting || ''
                scanResult.value.owner.phone = match.phone || owner.phone || ''
                scanResult.value.owner.email = match.email || owner.email || ''
                if (match.business_id) scanResult.value.owner.business_id = match.business_id
                if ('natural_person' in match) scanResult.value.owner.natural_person = match.natural_person
                if (match.phone_numbers?.length) scanResult.value.owner.phone_numbers = match.phone_numbers
                scanResult.value.owner.contact = match.contact || owner.contact || ''
                scanResult.value.owner.cc = match.cc || owner.cc || ''
                scanResult.value.owner.bcc = match.bcc || owner.bcc || ''
                scanResult.value.owner.homepage = match.homepage || owner.homepage || ''
                scanResult.value.owner.invoice_mail = match.invoice_mail || owner.invoice_mail || ''
                scanResult.value.owner.delivery_order_mail = match.delivery_order_mail || owner.delivery_order_mail || ''
                return true
            } catch {
                return false
            }
        }

        // ===== Kunden-Suche (Autocomplete) =====

        let searchTimeout = null
        function onCustomerSearch(value) {
            clearTimeout(searchTimeout)
            customerSearchText.value = value || ''
            if (!value || value.length < 2) {
                customerResults.value = []
                return
            }
            searchTimeout = setTimeout(() => searchCustomers(value), 300)
        }

        async function searchCustomers(name) {
            searchingCustomers.value = true
            try {
                customerResults.value = await carsStore.searchCustomerForScan(name)
            } catch {
                customerResults.value = []
            } finally {
                searchingCustomers.value = false
            }
        }

        // Autocomplete: Kunde ausgewählt → sofort weiter
        async function onCustomerSelected(customer) {
            if (!customer) return
            selectCustomerAndProceed(customer)
        }

        // ===== Kennzeichen-Suche (Autocomplete im Result-View) =====

        let carSearchTimeout = null
        function onCarSearch(value) {
            clearTimeout(carSearchTimeout)
            carSearchText.value = value || ''
            if (!value || value.length < 2) {
                carSearchResults.value = []
                return
            }
            carSearchTimeout = setTimeout(() => searchCars(value), 300)
        }

        async function searchCars(cln) {
            searchingCars.value = true
            try {
                carSearchResults.value = await carsStore.searchCarForScan(cln)
            } catch {
                carSearchResults.value = []
            } finally {
                searchingCars.value = false
            }
        }

        function onCarEnter() {
            if (carSearchResults.value.length === 1) {
                onCarSelected(carSearchResults.value[0])
            }
        }

        function onCarSelected(value) {
            if (!value) return
            // Objekt aus Autocomplete → existierendes Auto ausgewählt
            if (typeof value === 'object' && value.c_id) {
                scanResult.value.car.c_ln = value.c_ln
                existingCarId.value = value.c_id
                existingOwnerName.value = value.owner_name || ''
                duplicateWarnings.value = [t('CarScanView.duplicate.licensePlate', { plate: value.c_ln, owner: value.owner_name || '?' })]
                return
            }
            // Freitext → Duplikat-Check neu auslösen
            existingCarId.value = null
            existingOwnerName.value = ''
            duplicateWarnings.value = []
            if (typeof value === 'string' && value.length >= 2) {
                checkDuplicates({ ...scanResult.value.car, c_ln: value })
            }
        }

        // ===== Owner-Name-Suche (Autocomplete im Result-View) =====

        let ownerNameTimeout = null
        function onOwnerNameSearch(value) {
            clearTimeout(ownerNameTimeout)
            ownerNameSearchText.value = value || ''
            if (!value || value.length < 2) {
                customerResults.value = []
                return
            }
            ownerNameTimeout = setTimeout(() => searchCustomers(value), 300)
        }

        function onOwnerNameEnter() {
            if (customerResults.value.length === 1) {
                onOwnerNameSelected(customerResults.value[0])
            }
        }

        function onOwnerNameSelected(value) {
            if (!value) {
                selectedCustomer.value = null
                return
            }
            // Objekt aus Autocomplete → Kunde merken + DB-Daten in Felder eintragen
            if (typeof value === 'object' && value.id) {
                selectedCustomer.value = value
                const owner = scanResult.value.owner || {}
                scanResult.value.owner.name = value.name || ''
                scanResult.value.owner.address1 = value.street || owner.address1
                scanResult.value.owner.address2 = [value.zipcode, value.city].filter(Boolean).join(' ') || owner.address2
                scanResult.value.owner.greeting = value.greeting || owner.greeting || ''
                scanResult.value.owner.phone = value.phone || owner.phone || ''
                scanResult.value.owner.email = value.email || owner.email || ''
                if (value.business_id) scanResult.value.owner.business_id = value.business_id
                if ('natural_person' in value) scanResult.value.owner.natural_person = value.natural_person
                if (value.phone_numbers?.length) scanResult.value.owner.phone_numbers = value.phone_numbers
                scanResult.value.owner.contact = value.contact || owner.contact || ''
                scanResult.value.owner.cc = value.cc || owner.cc || ''
                scanResult.value.owner.bcc = value.bcc || owner.bcc || ''
                scanResult.value.owner.homepage = value.homepage || owner.homepage || ''
                scanResult.value.owner.invoice_mail = value.invoice_mail || owner.invoice_mail || ''
                scanResult.value.owner.delivery_order_mail = value.delivery_order_mail || owner.delivery_order_mail || ''
                return
            }
            // Freitext → Kundenzuordnung aufheben + Anrede anhand Vorname ermitteln
            selectedCustomer.value = null
            if (typeof value === 'string' && value.trim()) {
                lookupGreeting(value.trim())
            }
        }

        // ===== Save-Logik =====

        // Zentraler Button-Handler: Kunde ausgewählt → Fahrzeug speichern, sonst → neuen Kunden anlegen
        async function onSaveClick() {
            if (selectedCustomer.value) {
                // Existierenden Kunden verwenden
                savingVehicle.value = true
                try {
                    await selectCustomerAndProceed(selectedCustomer.value)
                } finally {
                    savingVehicle.value = false
                }
            } else {
                // Neuer Kunde → erst Duplikat-Prüfung
                const owner = scanResult.value.owner || {}
                const addr = parseOwnerAddress(owner)
                const name = (owner.name || '').trim()
                if (!name) {
                    saveNewCustomerDirect()
                    return
                }
                try {
                    const result = await carsStore.checkDuplicateCustomer(name, addr.street, addr.zipcode)
                    customerDuplicateExact.value = result.exact || []
                    customerDuplicatePartial.value = result.partial || []
                    if (customerDuplicateExact.value.length || customerDuplicatePartial.value.length) {
                        showDuplicateDialog.value = true
                    } else {
                        saveNewCustomerDirect()
                    }
                } catch {
                    // Bei Fehler trotzdem weiter (Backend-saveCV prüft nochmal)
                    saveNewCustomerDirect()
                }
            }
        }

        // Aus Duplikat-Dialog: existierenden Kunden verwenden
        function useExistingCustomer(dup) {
            showDuplicateDialog.value = false
            selectedCustomer.value = dup
        }

        // Aus Duplikat-Dialog: trotzdem anlegen
        function proceedCreateNewCustomer() {
            showDuplicateDialog.value = false
            saveNewCustomerDirect()
        }

        // Kunde laden + Fahrzeug anlegen (oder Halterwechsel)
        async function selectCustomerAndProceed(customer) {
            loadingCustomer.value = true
            try {
                // Sicherstellen dass Detail-Daten (inkl. Bilder) geladen sind
                if (pendingDetailPromise) {
                    await pendingDetailPromise.catch(() => {})
                    pendingDetailPromise = null
                }

                if (existingCarId.value) {
                    // Existierendes Fahrzeug: Daten aktualisieren + Eigentümer ändern
                    await oserpData.fetchCustomerOrVendor(customer.id, 'C')

                    // Fahrzeugdaten aus Scan aktualisieren (Datumsfelder DE→ISO)
                    const carUpdate = { c_id: existingCarId.value, c_ow: customer.id }
                    const carFields = scanResult.value.car || {}
                    const updateFields = ['c_ln', 'c_2', 'c_3', 'c_fin', 'c_finchk', 'c_d', 'c_hu', 'c_em']
                    for (const f of updateFields) {
                        if (carFields[f]) {
                            let val = carFields[f]
                            if (f === 'c_d' || f === 'c_hu') val = parseShortDate(val) || val
                            carUpdate[f] = val
                        }
                    }
                    const kba = scanResult.value.kba || {}
                    const kbaForUpdate = Object.keys(kba).length ? kba : null
                    await carsStore.updateCar(carUpdate, kbaForUpdate)

                    // Scan-Bilder speichern (await — sonst Race mit Edit-View-Load)
                    const imgObj = scanResult.value.images
                    const hasFieldImages = imgObj && typeof imgObj === 'object' && Object.keys(imgObj).length > 0
                    const scanIdForSave = scanResult.value.raw?.scan_id || null
                    if (hasFieldImages || scanResult.value.temp_image_id || scanIdForSave) {
                        const cLn = carFields.c_ln || ''
                        try {
                            await carsStore.saveScanImages(
                                existingCarId.value, cLn, null,
                                hasFieldImages ? imgObj : {},
                                false,
                                scanResult.value.temp_image_id || null,
                                scanIdForSave
                            )
                        } catch (err) {
                            console.error('Error saving scan images:', err)
                        }
                    }

                    router.push({ name: 'car', params: { id: existingCarId.value } })
                } else {
                    // Neues Fahrzeug anlegen
                    carsStore.pendingScanData = {
                        car: { ...scanResult.value.car },
                        kba: scanResult.value.kba || {},
                        useSpecialKba: useSpecialKba.value,
                        images: scanResult.value.images || null,
                        originalImage: scanResult.value.originalImage || null,
                        isPdf: scanResult.value.isPdf || false,
                        tempImageId: scanResult.value.temp_image_id || null,
                        scanId: scanResult.value.raw?.scan_id || null
                    }
                    await oserpData.fetchCustomerOrVendor(customer.id, 'C')
                    router.push({ name: 'fahrzeug-neu' })
                }
            } catch (err) {
                console.error('Error:', err)
                loadingCustomer.value = false
            }
        }

        // ===== Neuer Kunde + Fahrzeug =====

        const saveError = ref('')

        const businessTypes = computed(() => {
            return (oserpData.session?.company_config?.business_types || []).map(item => ({
                title: item.description,
                value: item.id
            }))
        })

        // Adresse aus owner.address2 parsen: "12345 Stadtname" → PLZ + Ort
        function parseOwnerAddress(owner) {
            const street = owner?.address1 || ''
            let zipcode = ''
            let city = ''

            const addr2 = (owner?.address2 || '').trim()
            if (addr2) {
                const parts = addr2.split(/\s+/)
                if (parts.length > 1 && /^\d+$/.test(parts[0])) {
                    zipcode = parts[0]
                    city = parts.slice(1).join(' ')
                } else {
                    city = addr2
                }
            }

            return { street, zipcode, city }
        }

        // Neuen Kunden + Fahrzeug direkt aus scanResult speichern (oder Halterwechsel)
        async function saveNewCustomerDirect() {
            const owner = scanResult.value.owner || {}
            const name = (ownerDisplayName.value || '').trim()
            if (!name || name === '–') return

            savingVehicle.value = true
            saveError.value = ''

            try {
                // Sicherstellen dass Detail-Daten (inkl. Bilder) geladen sind
                if (pendingDetailPromise) {
                    await pendingDetailPromise.catch(() => {})
                    pendingDetailPromise = null
                }

                // 1. Defaults für Kunde
                const defaultTaxzoneId = oserpData.session?.company_config?.tax_zones?.[0]?.id
                const defaultCurrencyId = oserpData.session?.company_config?.currencies?.[0]?.id

                // 2. Kundendaten direkt aus scanResult
                const addr = parseOwnerAddress(owner)
                const profile = {
                    src: 'C',
                    name,
                    greeting: owner.greeting || '',
                    street: addr.street,
                    zipcode: addr.zipcode,
                    city: addr.city,
                    phone: owner.phone || '',
                    email: owner.email || '',
                    contact: owner.contact || '',
                    cc: owner.cc || '',
                    bcc: owner.bcc || '',
                    homepage: owner.homepage || '',
                    invoice_mail: owner.invoice_mail || '',
                    delivery_order_mail: owner.delivery_order_mail || '',
                    taxzone_id: defaultTaxzoneId,
                    currency_id: defaultCurrencyId,
                    natural_person: owner.natural_person ?? false,
                    phone_numbers: owner.phone_numbers || [],
                    obsolete: false,
                    create_zugferd_invoices: -1
                }
                if (owner.business_id) {
                    profile.business_id = owner.business_id
                }

                oserpData.customer_vendor = {
                    profile,
                    shiptos: [],
                    additional_billing_addresses: [],
                    contacts: []
                }
                const cvResult = await oserpData.saveCV()
                const customerId = cvResult.payload.new_id

                if (existingCarId.value) {
                    // Existierendes Fahrzeug: Daten aktualisieren + Eigentümer ändern
                    const carUpdate = { c_id: existingCarId.value, c_ow: customerId }
                    const carFields = scanResult.value.car || {}
                    const updateFields = ['c_ln', 'c_2', 'c_3', 'c_fin', 'c_finchk', 'c_d', 'c_hu', 'c_em']
                    for (const f of updateFields) {
                        if (carFields[f]) {
                            let val = carFields[f]
                            if (f === 'c_d' || f === 'c_hu') val = parseShortDate(val) || val
                            carUpdate[f] = val
                        }
                    }
                    const kba = scanResult.value.kba || {}
                    const kbaForUpdate = Object.keys(kba).length ? kba : null
                    await carsStore.updateCar(carUpdate, kbaForUpdate)

                    // Scan-Bilder speichern (await — sonst Race mit Edit-View-Load)
                    const imgObj = scanResult.value.images
                    const hasFieldImages = imgObj && typeof imgObj === 'object' && Object.keys(imgObj).length > 0
                    const scanIdForSave2 = scanResult.value.raw?.scan_id || null
                    if (hasFieldImages || scanResult.value.temp_image_id || scanIdForSave2) {
                        const cLn = carFields.c_ln || ''
                        try {
                            await carsStore.saveScanImages(
                                existingCarId.value, cLn, null,
                                hasFieldImages ? imgObj : {},
                                false,
                                scanResult.value.temp_image_id || null,
                                scanIdForSave2
                            )
                        } catch (err) {
                            console.error('Error saving scan images:', err)
                        }
                    }

                    await oserpData.fetchCustomerOrVendor(customerId, 'C')
                    router.push({ name: 'car', params: { id: existingCarId.value } })
                } else {
                    // 3. Fahrzeug anlegen — Datumsfelder von DE nach ISO
                    const carData = { ...scanResult.value.car, c_ow: customerId }
                    if (carData.c_d) carData.c_d = parseShortDate(carData.c_d) || carData.c_d
                    if (carData.c_hu) carData.c_hu = parseShortDate(carData.c_hu) || carData.c_hu
                    const kba = scanResult.value.kba || {}
                    // Bei special_kba: keine KBA an saveCar übergeben (wird separat gespeichert)
                    const kbaData = (!useSpecialKba.value && Object.keys(kba).length) ? kba : null
                    const carResult = await carsStore.saveCar(carData, kbaData)
                    const carId = carResult.payload.c_id

                    // 3b. Special-KBA speichern (wenn TSN unvollständig und keine KBA gewählt)
                    if (useSpecialKba.value && Object.keys(kba).length) {
                        carsStore.saveSpecialKba(carId, kba)
                            .catch(err => console.error('Error saving special KBA:', err))
                    }

                    // 4. Scan-Bilder speichern (await — sonst Race mit Edit-View-Load)
                    const imgObj = scanResult.value.images
                    const hasFieldImages = imgObj && typeof imgObj === 'object' && Object.keys(imgObj).length > 0
                    const cLn = scanResult.value.car.c_ln || ''
                    const tempImageId = scanResult.value.temp_image_id || null
                    const scanIdForSave3 = scanResult.value.raw?.scan_id || null
                    try {
                        await carsStore.saveScanImages(carId, cLn, null, hasFieldImages ? imgObj : {}, false, tempImageId, scanIdForSave3)
                    } catch (err) {
                        console.error('Error saving scan images:', err)
                    }

                    // 5. Kunde laden + zum Fahrzeug-Edit navigieren
                    await oserpData.fetchCustomerOrVendor(customerId, 'C')
                    router.push({ name: 'car', params: { id: carId } })
                }
            } catch (err) {
                saveError.value = err.message || String(err)
            } finally {
                savingVehicle.value = false
            }
        }

        // Fahrzeugdaten aus Scan auf bestehendes Fahrzeug übernehmen (Update)
        async function updateExistingCarData() {
            if (!existingCarId.value) return

            updatingExistingCar.value = true
            saveError.value = ''

            try {
                // Fahrzeugdaten aus Scan zusammenbauen
                const carData = { ...scanResult.value.car, c_id: existingCarId.value }
                if (carData.c_d) carData.c_d = parseShortDate(carData.c_d) || carData.c_d
                if (carData.c_hu) carData.c_hu = parseShortDate(carData.c_hu) || carData.c_hu

                // KBA-Daten falls vorhanden — bei bestehendem Fahrzeug immer
                // die alte Methode (prepareKba → kba_lxcars) verwenden
                const kba = scanResult.value.kba || {}
                const kbaData = Object.keys(kba).length ? kba : null

                // Fahrzeug + KBA in einem Call updaten
                await carsStore.updateCar(carData, kbaData)

                // Detail-Daten (inkl. Bilder im tmp-Cache) abwarten, dann
                // Fahrzeugschein-Bild und Crops auf das bestehende Fahrzeug übertragen.
                if (pendingDetailPromise) {
                    await pendingDetailPromise.catch(() => {})
                    pendingDetailPromise = null
                }
                const imgObj = scanResult.value.images
                const hasFieldImages = imgObj && typeof imgObj === 'object' && Object.keys(imgObj).length > 0
                const scanIdForSave = scanResult.value.raw?.scan_id || null
                if (hasFieldImages || scanResult.value.temp_image_id || scanIdForSave) {
                    const cLn = carData.c_ln || ''
                    try {
                        await carsStore.saveScanImages(
                            existingCarId.value, cLn, null,
                            hasFieldImages ? imgObj : {},
                            false,
                            scanResult.value.temp_image_id || null,
                            scanIdForSave
                        )
                    } catch (err) {
                        console.error('Error saving scan images:', err)
                    }
                }

                // Zur Fahrzeug-Ansicht navigieren
                router.push({ name: 'car', params: { id: existingCarId.value } })
            } catch (err) {
                saveError.value = err.message || String(err)
            } finally {
                updatingExistingCar.value = false
            }
        }

        function resetToList() {
            step.value = 'list'
            selectedFile.value = null
            selectedFileName.value = ''
            previewUrl.value = null
            scanError.value = ''
            scanResult.value = { car: {}, kba: {}, owner: {} }
            selectedCustomer.value = null
            customerResults.value = []
            loadingCustomer.value = false
            duplicateWarnings.value = []
            carSearchResults.value = []
            carSearchText.value = ''
            ownerNameSearchText.value = ''
            existingCarId.value = null
            existingOwnerName.value = ''
            customerDuplicateExact.value = []
            customerDuplicatePartial.value = []
            showDuplicateDialog.value = false
            savingVehicle.value = false
            kbaList.value = []
            kbaSearchFilter.value = ''
            useSpecialKba.value = false
            incompleteTsn.value = ''
            showSpecialKbaConfirm.value = false
            kbaFuzzyDialog.value = false
            kbaFuzzySuggestions.value = []
        }

        // Beim Laden Scans abrufen
        onMounted(() => {
            if (hasApiKey.value) {
                loadScans()
            }
        })

        return {
            t, step,
            // Scan-Liste
            scanList, loadingScans, scanListError, scanCarStatus, scanPage, scanTotalPages, scanTotal, scanSearch,
            loadScans, formatTimestamp, selectScan, scanRowClass, onScanRowClick, getScanIcon, getScanStatus, openCar, openOwner,
            // Upload
            selectedFile, selectedFileName, previewUrl, isDragging,
            scanning, scanError,
            onFileSelect, onDrop, startUploadScan,
            // KBA-Auswahl (unvollständige TSN)
            kbaListFiltered, loadingKbaList, kbaSearchFilter, incompleteTsn, showSpecialKbaConfirm, specialKbaForm, specialKbaFormValid,
            selectKbaEntry, skipKbaSelection, confirmSpecialKba,
            // KBA-Fuzzy-Korrektur
            kbaFuzzyDialog, kbaFuzzySuggestions, kbaFuzzyOriginal,
            applyKbaFuzzyCorrection, dismissKbaFuzzy,
            // Ergebnis
            scanResult, hasApiKey, ownerDisplayName, vehicleName, huExtrapolated, scanCrops, loadCropImage,
            duplicateWarnings, existingCarId, isOwnerMatch,
            selectedCustomer, customerResults, searchingCustomers, customerSearchText, loadingCustomer,
            onCustomerSearch, onCustomerSelected,
            // Kennzeichen-Autocomplete
            carSearchResults, searchingCars, carSearchText, licensePlateError,
            onCarSearch, onCarSelected, onCarEnter,
            // Owner-Name-Autocomplete
            ownerNameRef, ownerNameMenuOpen, ownerNameSearchText, onOwnerNameSearch, onOwnerNameSelected, onOwnerNameEnter,
            greetingOptions, ownerPhoneNumbers, addOwnerPhone, removeOwnerPhone,
            // Save-Logik + Duplikat-Dialog
            onSaveClick, savingVehicle,
            customerDuplicateExact, customerDuplicatePartial, showDuplicateDialog,
            useExistingCustomer, proceedCreateNewCustomer,
            // Update bestehendes Fahrzeug
            updatingExistingCar, updateExistingCarData,
            // Neuer Kunde + Fahrzeug
            saveError, businessTypes,
            saveNewCustomerDirect,
            // Navigation
            resetToList
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
    background-color: #f5f5f5;
}

.scan-list-table thead th {
    font-weight: 700;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: rgba(0, 0, 0, 0.7);
    border-bottom: 2px solid rgba(0, 0, 0, 0.12);
}

.scan-list-table__row {
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.scan-list-table__row:hover {
    background-color: rgba(var(--v-theme-primary), 0.06) !important;
}

/* Fahrzeug existiert, anderer Halter (Halterwechsel möglich) */
.scan-list-table__row--exists {
    background-color: #fff3e0 !important;
}

.scan-list-table__row--exists:hover {
    background-color: #ffe0b2 !important;
}

/* Fahrzeug + Halter komplett vorhanden → gedämpft */
.scan-list-table__row--complete {
    background-color: #f5f5f5 !important;
    color: rgba(0, 0, 0, 0.5);
}

/* Klickbare Links in der Scan-Liste */
.scan-link {
    cursor: pointer;
    text-decoration: none;
    border-bottom: 1px dotted rgba(0, 0, 0, 0.3);
    transition: color 0.15s, border-color 0.15s;
}

.scan-link:hover {
    color: #1976D2;
    border-color: #1976D2;
}

.scan-link--primary {
    font-weight: 600;
    border-bottom-style: solid;
}

.scan-list-table__status-cell {
    width: 32px;
    padding: 4px 0 4px 8px !important;
    text-align: center;
}

.scan-dropzone {
    border: 2px dashed rgba(0, 0, 0, 0.2);
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.scan-dropzone:hover {
    border-color: rgb(var(--v-theme-primary));
    background-color: rgba(var(--v-theme-primary), 0.03);
}

.scan-dropzone--active {
    border-color: rgb(var(--v-theme-primary));
    background-color: rgba(var(--v-theme-primary), 0.06);
}
</style>

<style>
/* Unscoped: Tooltip rendert via Teleport im Body */
.crop-tooltip {
    background: white !important;
    padding: 4px !important;
    border-radius: 6px !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25) !important;
    opacity: 1 !important;
}
.crop-tooltip-img {
    max-width: 280px;
    max-height: 140px;
    border-radius: 4px;
    display: block;
}
</style>
