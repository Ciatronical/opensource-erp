<!-- src/features/lxcars/views/car/car.edit.view.vue -->

<template>
    <NavbarView v-if="!readonly" />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Titel-Zeile -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-btn v-if="readonly" icon variant="text" color="primary" size="small" :title="t('CarEditView.backToOrder')" @click="goBack">
                <v-icon>mdi-arrow-left</v-icon>
            </v-btn>
            <v-icon color="primary" class="mr-1">mdi-car</v-icon>
            <h1 class="text-h6 mb-0">
                {{ readonly ? t('CarEditView.titleView') : (isEditMode ? t('CarEditView.titleEdit') : t('CarEditView.titleNew')) }}
            </h1>
            <v-chip v-if="isEditMode && car.c_ln" size="small" variant="tonal" color="primary" class="font-weight-bold">
                {{ car.c_ln }}
            </v-chip>
            <template v-if="readonly">
                <v-chip size="x-small" color="info" variant="tonal">
                    <v-icon start size="x-small">mdi-eye</v-icon>
                    {{ t('CarEditView.readonly') }}
                </v-chip>
                <v-btn v-if="backOrderId" variant="tonal" size="small" color="primary" @click="backToOrder">
                    <v-icon start size="small">mdi-clipboard-text</v-icon>
                    {{ t('CarEditView.backToOrder') }}
                </v-btn>
            </template>
            <template v-if="!readonly">
                <v-btn v-if="hasScanImages" variant="text" size="small" color="blue-darken-2" @click="openScanImagesDialog">
                    <v-icon start size="small">mdi-file-image-outline</v-icon>
                    {{ t('CarEditView.scanImages.button') }}
                </v-btn>
                <v-btn v-if="isEditMode" variant="tonal" size="small" color="primary" :title="t('CarEditView.files.openButton')" @click="filesDialogOpen = true">
                    <v-icon start size="small">mdi-folder-open-outline</v-icon>
                    {{ t('CarEditView.files.button') }}
                </v-btn>
                <v-btn v-if="aagAvailable" variant="tonal" size="small" color="indigo" :loading="aagLoading || ktypeLoading" :title="t('CarEditView.aag.tsnTooltip')" @click="openAag">
                    <v-icon start size="small">mdi-car-search</v-icon>
                    {{ t('CarEditView.aag.button') }}
                </v-btn>
                <v-btn v-if="esiAvailable" variant="tonal" size="small" color="teal-darken-1" :title="t('CarEditView.esi.tooltip')" @click="openEsi">
                    <v-icon start size="small">mdi-cog-outline</v-icon>
                    {{ t('CarEditView.esi.button') }}
                </v-btn>
                <v-btn v-if="gutmannAvailable" variant="tonal" size="small" color="cyan-darken-2" :title="t('CarEditView.gutmann.tooltip')" @click="openGutmann">
                    <v-icon start size="small">mdi-lan-connect</v-icon>
                    {{ t('CarEditView.gutmann.button') }}
                </v-btn>
                <v-btn v-if="hgsAvailable" variant="tonal" size="small" color="blue-grey-darken-1" :loading="hgsLoading" :title="t('CarEditView.hgs.tooltip')" @click="openHgs">
                    <v-icon start size="small">mdi-database-search-outline</v-icon>
                    {{ t('CarEditView.hgs.button') }}
                </v-btn>
                <v-btn v-if="isEditMode" variant="tonal" size="small" color="success" @click="openCarRegistration">
                    <v-icon start size="small">mdi-card-account-details</v-icon>
                    {{ t('CarEditView.registration.button') }}
                </v-btn>
                <v-btn v-if="isEditMode" variant="tonal" size="small" color="red" :disabled="!kbaData" @click="rotesHeftDialog = true">
                    <v-icon start size="small">mdi-book-open-variant</v-icon>
                    {{ t('CarEditView.rotesHeft.button') }}
                </v-btn>
                <v-btn v-if="isEditMode && oserpData.customer_vendor?.profile?.id" variant="tonal" size="small" color="primary" @click="navigateToCustomer">
                    <v-icon start size="small">mdi-account-box</v-icon>
                    {{ t('CarEditView.navigateToCustomer') }}
                </v-btn>
                <v-btn v-if="isEditMode && car.c_ln" variant="tonal" size="small" color="green" :title="t('CarEditView.yellowLabel.tooltip')" :loading="yellowLabelPrinting" @click="onPrintYellowLabel">
                    <v-icon start size="small">mdi-label</v-icon>
                    {{ t('CarEditView.yellowLabel.button') }}
                </v-btn>
                <v-btn v-if="isEditMode" variant="tonal" size="small" color="grey-darken-2" :title="t('CarEditView.export.tooltip')" @click="exportCarData">
                    <v-icon start size="small">mdi-file-download-outline</v-icon>
                    {{ t('CarEditView.export.button') }}
                </v-btn>
                <v-btn v-if="isEditMode" variant="tonal" size="small" color="info" :title="t('CarEditView.email.tooltip')" @click="openEmailDialog">
                    <v-icon start size="small">mdi-email-send-outline</v-icon>
                    {{ t('CarEditView.email.button') }}
                </v-btn>
                <v-btn v-if="isEditMode" variant="tonal" size="small" color="orange-darken-2" :title="t('CarEditView.sell.tooltip')" @click="sellDialog = true">
                    <v-icon start size="small">mdi-tag-arrow-up-outline</v-icon>
                    {{ t('CarEditView.sell.button') }}
                </v-btn>
                <v-btn v-if="isEditMode && oserpData.checkPermission('special_access')" variant="tonal" size="small" color="deep-purple" @click="specialDialog = true">
                    <v-icon start size="small">mdi-star-circle</v-icon>
                    Special
                </v-btn>
                <v-btn v-if="isEditMode" variant="tonal" size="small" color="error" @click="deleteConfirmDialog = true">
                    <v-icon start size="small">mdi-delete</v-icon>
                    {{ t('CarEditView.delete.button') }}
                </v-btn>
            </template>
            <v-spacer />
            <v-btn v-if="isMechanicMode" icon variant="text" color="primary" :title="t('MechanicView.exitMechanic')" @click="router.push(t('routes.mainmenu'))">
                <v-icon>mdi-exit-to-app</v-icon>
            </v-btn>
            <template v-if="!readonly">
                <v-chip v-if="saving" size="x-small" color="warning" variant="tonal">
                    <v-progress-circular indeterminate size="12" width="2" class="mr-1" />
                    {{ t('CarEditView.saving') }}
                </v-chip>
                <v-chip v-else-if="isEditMode && !error" size="x-small" color="success" variant="tonal">
                    <v-icon start size="x-small">mdi-check</v-icon>
                    {{ t('CarEditView.saved') }}
                </v-chip>
            </template>
        </div>

        <!-- Alerts -->
        <v-alert v-if="!readonly && !oserpData.customer_vendor" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('CarEditView.messages.noCustomer') }}
            <template #append>
                <v-btn size="small" variant="tonal" color="warning" @click="focusSearch">
                    {{ t('CarEditView.messages.selectCustomer') }}
                </v-btn>
            </template>
        </v-alert>
        <v-alert v-else-if="!readonly && oserpData.customer_vendor.profile?.src === 'V'" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('CarEditView.messages.vendorNotAllowed') }}
            <template #append>
                <v-btn size="small" variant="tonal" color="warning" @click="focusSearch">
                    {{ t('CarEditView.messages.selectCustomer') }}
                </v-btn>
            </template>
        </v-alert>
        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('CarEditView.messages.loadError') }}...
        </v-alert>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
            {{ error }}
        </v-alert>

        <v-form
            v-if="(oserpData.customer_vendor && oserpData.customer_vendor.profile?.src !== 'V' && !loading) || (readonly && !loading)"
            autocomplete="off"
            :readonly="readonly"
            @submit.prevent
            @focusin.capture="onFocusIn"
            @focusout.capture="onFocusOut"
        >
            <v-row>

                <!-- ========== LINKE SPALTE ========== -->
                <v-col cols="12" lg="6">

                    <!-- Identifikation -->
                    <v-card variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-card-text-outline</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('CarEditView.sections.identification') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="py-2 px-2 px-sm-3">
                            <v-row v-if="isEditMode" dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-autocomplete
                                        :model-value="currentOwnerId"
                                        :label="t('CarEditView.fields.c_ow')"
                                        :items="ownerItems"
                                        :loading="ownerLoading"
                                        item-title="name"
                                        item-value="id"
                                        :no-filter="true"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        prepend-inner-icon="mdi-account-switch"
                                        @update:search="onOwnerSearch"
                                        @update:model-value="onOwnerChange"
                                    >
                                        <template #no-data />
                                    </v-autocomplete>
                                </v-col>
                            </v-row>
                            <v-row dense align="center">
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="car.c_ln" :label="t('CarEditView.fields.c_ln')" variant="outlined" density="compact" hide-details="auto" maxlength="10" tabindex="1" :rules="rulesLn" @click="copyToClipboard('Kennzeichen', car.c_ln)">
                                        <template #append-inner>
                                            <v-tooltip v-if="fieldCrops.c_ln" location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_ln" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                            <v-tooltip v-if="district" location="top" :text="district">
                                                <template #activator="{ props: tip }">
                                                    <v-icon v-bind="tip" size="small" color="info" class="mr-1" tabindex="-1">mdi-map-marker-outline</v-icon>
                                                </template>
                                            </v-tooltip>
                                            <v-icon v-if="!readonly" size="small" :color="car.chk_c_ln ? 'success' : 'grey-lighten-1'" class="cursor-pointer" tabindex="-1" @click.stop="toggleShield('chk_c_ln')">
                                                {{ car.chk_c_ln ? 'mdi-shield-check' : 'mdi-shield-outline' }}
                                            </v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <!-- HU-Benachrichtigung: pro Fahrzeug ein-/ausschaltbar (steuert Serienbrief/WhatsApp) -->
                                <v-col cols="12" sm="6" class="py-1 d-flex align-center">
                                    <v-switch
                                        v-model="car.c_hu_notify"
                                        :label="t('CarEditView.fields.c_hu_notify')"
                                        :prepend-icon="car.c_hu_notify ? 'mdi-bell-ring-outline' : 'mdi-bell-off-outline'"
                                        color="primary"
                                        density="compact"
                                        hide-details
                                        inset
                                        :disabled="readonly"
                                        tabindex="-1"
                                    />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="car.c_2" :label="t('CarEditView.fields.c_2')" variant="outlined" density="compact" hide-details="auto" maxlength="4" tabindex="2" :rules="rulesHsn" @click="copyToClipboard('HSN', car.c_2)" @dblclick="copyToClipboardNow('KBA', kbaClipboardText())">
                                        <template #append-inner>
                                            <v-tooltip v-if="fieldCrops.c_2" location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_2" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                            <v-icon v-if="!readonly" size="small" :color="car.chk_c_2 ? 'success' : 'grey-lighten-1'" class="cursor-pointer" tabindex="-1" @click.stop="toggleShield('chk_c_2')">
                                                {{ car.chk_c_2 ? 'mdi-shield-check' : 'mdi-shield-outline' }}
                                            </v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                            </v-row>
                            <v-row dense align="center">
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field ref="tsnFieldRef" v-model="car.c_3" :label="t('CarEditView.fields.c_3')" variant="outlined" density="compact" hide-details="auto" maxlength="10" tabindex="3" :rules="rulesTsn" @click="copyToClipboard('TSN', car.c_3)" @dblclick="copyToClipboardNow('KBA', kbaClipboardText())">
                                        <template #append-inner>
                                            <v-tooltip v-if="fieldCrops.c_3" location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_3" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                            <v-icon v-if="!readonly" size="small" :color="car.chk_c_3 ? 'success' : 'grey-lighten-1'" class="cursor-pointer" tabindex="-1" @click.stop="toggleShield('chk_c_3')">
                                                {{ car.chk_c_3 ? 'mdi-shield-check' : 'mdi-shield-outline' }}
                                            </v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <!-- AAG-Online per FIN suchen, wenn TSN ein Platzhalter ist (000…) -->
                                <v-col v-if="showAagTsnButton" cols="auto" class="py-1">
                                    <v-tooltip location="bottom" :text="ktypeLoading ? t('CarEditView.ktype.resolving') : t('CarEditView.aag.tsnTooltip')">
                                        <template #activator="{ props: tipProps }">
                                            <v-btn
                                                v-bind="tipProps"
                                                icon="mdi-car-search"
                                                variant="tonal"
                                                color="indigo"
                                                size="small"
                                                tabindex="-1"
                                                :loading="aagLoading || ktypeLoading"
                                                @click="openAag"
                                            />
                                        </template>
                                    </v-tooltip>
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="car.c_d2" :label="t('CarEditView.fields.d2')" variant="outlined" density="compact" hide-details="auto" maxlength="30" tabindex="4">
                                        <template v-if="fieldCrops.c_d2" #append-inner>
                                            <v-tooltip location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_d2" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="car.c_em" :label="t('CarEditView.fields.c_em')" variant="outlined" density="compact" hide-details="auto" maxlength="6" tabindex="5" :rules="rulesEm">
                                        <template #append-inner>
                                            <v-tooltip v-if="fieldCrops.c_em" location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_em" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                            <v-icon v-if="!readonly" size="small" :color="car.chk_c_em ? 'success' : 'grey-lighten-1'" class="cursor-pointer" tabindex="-1" @click.stop="toggleShield('chk_c_em')">
                                                {{ car.chk_c_em ? 'mdi-shield-check' : 'mdi-shield-outline' }}
                                            </v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                            </v-row>

                            <v-divider class="my-2" />

                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="displayD" :label="t('CarEditView.fields.c_d')" variant="outlined" density="compact" hide-details="auto" placeholder="TT.MM.JJJJ" tabindex="6" :rules="rulesD" @blur="onBlurDate('c_d')">
                                        <template #append-inner>
                                            <v-tooltip v-if="fieldCrops.c_d" location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_d" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                            <v-icon v-if="!readonly" size="small" :color="car.chk_c_d ? 'success' : 'grey-lighten-1'" class="cursor-pointer" tabindex="-1" @click.stop="toggleShield('chk_c_d')">
                                                {{ car.chk_c_d ? 'mdi-shield-check' : 'mdi-shield-outline' }}
                                            </v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="displayHu" :label="t('CarEditView.fields.c_hu')" variant="outlined" density="compact" hide-details="auto" placeholder="TT.MM.JJJJ" tabindex="7" :rules="rulesHu" @blur="onBlurDate('c_hu')">
                                        <template #append-inner>
                                            <v-tooltip v-if="fieldCrops.c_hu" location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_hu" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                            <v-icon v-if="!readonly" size="small" :color="car.chk_c_hu ? 'success' : 'grey-lighten-1'" class="cursor-pointer" tabindex="-1" @click.stop="toggleShield('chk_c_hu')">
                                                {{ car.chk_c_hu ? 'mdi-shield-check' : 'mdi-shield-outline' }}
                                            </v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="12" sm="6" class="py-1 d-flex align-center">
                                    <v-checkbox v-model="car.c_pb" :label="t('CarEditView.fields.c_pb')" color="indigo" density="compact" hide-details tabindex="-1" :disabled="readonly" />
                                </v-col>
                            </v-row>

                            <v-divider class="my-2" />

                            <v-row dense>
                                <v-col cols="9" sm="7" class="py-1">
                                    <v-text-field ref="finFieldRef" v-model="car.c_fin" :label="t('CarEditView.fields.c_fin')" variant="outlined" density="compact" hide-details="auto" maxlength="30" tabindex="8" :rules="rulesFin" @click="copyToClipboard('FIN', car.c_fin)">
                                        <template #append-inner>
                                            <v-tooltip v-if="fieldCrops.c_fin" location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer mr-1" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_fin" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                            <v-icon v-if="!readonly" size="small" :color="car.chk_fin ? 'success' : 'grey-lighten-1'" class="cursor-pointer" tabindex="-1" @click.stop="toggleShield('chk_fin')">
                                                {{ car.chk_fin ? 'mdi-shield-check' : 'mdi-shield-outline' }}
                                            </v-icon>
                                        </template>
                                    </v-text-field>
                                </v-col>
                                <v-col cols="3" sm="2" class="py-1">
                                    <v-text-field v-model="car.c_finchk" :label="t('CarEditView.fields.c_finchk')" variant="outlined" density="compact" hide-details="auto" maxlength="1" tabindex="9">
                                        <template v-if="fieldCrops.c_finchk" #append-inner>
                                            <v-tooltip location="end" content-class="crop-tooltip">
                                                <template #activator="{ props: tipProps }">
                                                    <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1">mdi-image-outline</v-icon>
                                                </template>
                                                <img :src="fieldCrops.c_finchk" class="crop-tooltip-img" loading="eager" />
                                            </v-tooltip>
                                        </template>
                                    </v-text-field>
                                </v-col>
                            </v-row>

                            <!-- TecDoc-Ktype (über AAG-Online aus HSN/TSN bzw. FIN ermittelt) -->
                            <v-row v-if="isEditMode && (ktypeNo || ktypeLoading)" dense align="center">
                                <v-col cols="12" class="py-1">
                                    <v-chip v-if="ktypeNo" size="small" color="indigo" variant="tonal" prepend-icon="mdi-car-info">
                                        {{ t('CarEditView.ktype.label') }} {{ ktypeNo }}<template v-if="ktypeDesc"> · {{ ktypeDesc }}</template>
                                    </v-chip>
                                    <span v-else class="text-caption text-medium-emphasis d-inline-flex align-center">
                                        <v-progress-circular indeterminate size="14" width="2" class="mr-2" />
                                        {{ t('CarEditView.ktype.resolving') }}
                                    </span>
                                    <v-tooltip v-if="!readonly && !ktypeLoading && aagConfigured" location="bottom" :text="t('CarEditView.ktype.refresh')">
                                        <template #activator="{ props: tipProps }">
                                            <v-btn v-bind="tipProps" icon="mdi-refresh" variant="text" size="x-small" tabindex="-1" class="ml-1" @click="resolveKtypeBg" />
                                        </template>
                                    </v-tooltip>
                                </v-col>
                            </v-row>

                            <v-divider class="my-2" />

                            <v-row dense>
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-combobox v-model="car.c_mkb" :items="installedEnginesList" :label="t('CarEditView.fields.c_mkb')" variant="outlined" density="compact" hide-details="auto" tabindex="10" @update:model-value="triggerSave" />
                                </v-col>
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-text-field v-model="car.c_gart" :label="t('CarEditView.fields.c_gart')" variant="outlined" density="compact" hide-details="auto" maxlength="30" tabindex="11" />
                                </v-col>
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-text-field v-model="car.c_color" :label="t('CarEditView.fields.c_color')" variant="outlined" density="compact" hide-details="auto" maxlength="30" tabindex="12" />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <!-- Reifen -->
                    <v-card class="mt-3" variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-tire</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('CarEditView.sections.tires') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="py-2 px-2 px-sm-3">
                            <!-- Spaltenheader -->
                            <v-row dense class="text-caption text-medium-emphasis font-weight-bold mb-1">
                                <v-col cols="1"></v-col>
                                <v-col>{{ t('CarEditView.tireTable.type') }}</v-col>
                                <v-col>{{ t('CarEditView.tireTable.storage') }}</v-col>
                                <v-col>{{ t('CarEditView.tireTable.condition') }}</v-col>
                                <v-col cols="auto" style="width: 80px"></v-col>
                            </v-row>
                            <!-- Sommerreifen -->
                            <v-row dense align="center">
                                <v-col cols="1" class="text-caption font-weight-bold py-1">{{ t('CarEditView.tireTable.summer') }}</v-col>
                                <v-col class="py-1">
                                    <v-text-field v-model="car.c_st" variant="outlined" density="compact" hide-details maxlength="30" tabindex="13" />
                                </v-col>
                                <v-col class="py-1">
                                    <v-text-field v-model="car.c_st_l" variant="outlined" density="compact" hide-details maxlength="30" tabindex="14" />
                                </v-col>
                                <v-col class="py-1">
                                    <v-text-field v-model="car.c_st_z" variant="outlined" density="compact" hide-details maxlength="30" tabindex="15" />
                                </v-col>
                                <v-col cols="auto" class="py-1" style="width: 80px">
                                    <v-btn v-if="isEditMode && !readonly" size="small" variant="tonal" color="orange" :title="t('CarEditView.tyreLabel.tooltip')" :loading="tyreLabelPrinting === 'summer'" @click="onPrintTyreLabel('summer')">
                                        <v-icon size="small">mdi-printer</v-icon>
                                    </v-btn>
                                </v-col>
                            </v-row>
                            <!-- Winterreifen -->
                            <v-row dense align="center">
                                <v-col cols="1" class="text-caption font-weight-bold py-1">{{ t('CarEditView.tireTable.winter') }}</v-col>
                                <v-col class="py-1">
                                    <v-text-field v-model="car.c_wt" variant="outlined" density="compact" hide-details maxlength="30" tabindex="16" />
                                </v-col>
                                <v-col class="py-1">
                                    <v-text-field v-model="car.c_wt_l" variant="outlined" density="compact" hide-details maxlength="30" tabindex="17" />
                                </v-col>
                                <v-col class="py-1">
                                    <v-text-field v-model="car.c_wt_z" variant="outlined" density="compact" hide-details maxlength="30" tabindex="18" />
                                </v-col>
                                <v-col cols="auto" class="py-1" style="width: 80px">
                                    <v-btn v-if="isEditMode && !readonly" size="small" variant="tonal" color="blue" :title="t('CarEditView.tyreLabel.tooltip')" :loading="tyreLabelPrinting === 'winter'" @click="onPrintTyreLabel('winter')">
                                        <v-icon size="small">mdi-printer</v-icon>
                                    </v-btn>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <!-- Wartung & Service -->
                    <v-card class="mt-3" variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-wrench</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('CarEditView.sections.maintenance') }}</span>
                            <v-spacer />
                            <v-tooltip location="top" :text="voiceRecording ? t('CarEditView.voice.stop') : t('CarEditView.voice.hint')">
                                <template #activator="{ props: tp }">
                                    <v-btn
                                        v-if="voiceSupported"
                                        v-bind="tp"
                                        :icon="voiceRecording ? 'mdi-stop' : 'mdi-microphone'"
                                        :color="voiceRecording ? 'error' : 'primary'"
                                        :loading="voiceBusy || voiceExtracting"
                                        :disabled="readonly"
                                        size="small"
                                        variant="tonal"
                                        class="mr-2"
                                        tabindex="-1"
                                        @click="voiceToggle()"
                                    />
                                </template>
                            </v-tooltip>
                            <v-chip
                                :variant="car.c_sk ? 'flat' : 'outlined'"
                                :color="car.c_sk ? 'primary' : undefined"
                                size="small"
                                :class="readonly ? '' : 'cursor-pointer'"
                                tabindex="-1"
                                @click="readonly ? null : (car.c_sk = !car.c_sk)"
                            >
                                <v-icon start size="x-small">{{ car.c_sk ? 'mdi-link-variant' : 'mdi-link-variant-off' }}</v-icon>
                                {{ t('CarEditView.fields.c_sk') }}
                            </v-chip>
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="py-2 px-2 px-sm-3">
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="displayZrd" :label="t('CarEditView.fields.c_zrd')" variant="outlined" density="compact" hide-details="auto" placeholder="MM/JJ" tabindex="19" :rules="car.c_sk ? [] : rulesMonthYear" :disabled="car.c_sk" @blur="onBlurMonthYear('c_zrd')" />
                                </v-col>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="displayZrk" :label="t('CarEditView.fields.c_zrk')" variant="outlined" density="compact" hide-details="auto" suffix="km" placeholder="z.B. 180" tabindex="20" :disabled="car.c_sk" @blur="onBlurKm" />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="displayBf" :label="t('CarEditView.fields.c_bf')" variant="outlined" density="compact" hide-details="auto" placeholder="MM/JJ" tabindex="21" :rules="rulesMonthYear" @blur="onBlurMonthYear('c_bf')" />
                                </v-col>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="displayWd" :label="t('CarEditView.fields.c_wd')" variant="outlined" density="compact" hide-details="auto" placeholder="MM/JJ" tabindex="22" :rules="rulesMonthYear" @blur="onBlurMonthYear('c_wd')" />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="12" sm="6" class="py-1">
                                    <v-text-field v-model="displayKm" :label="t('CarEditView.fields.c_km')" variant="outlined" density="compact" hide-details="auto" suffix="km" tabindex="22" @blur="onBlurKmStand" />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <!-- Notizen -->
                    <v-card class="mt-3" variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-note-text-outline</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('CarEditView.sections.notes') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="py-2 px-2 px-sm-3">
                            <v-row dense>
                                <v-col cols="12" class="py-1">
                                    <v-textarea v-model="car.c_text" :label="t('CarEditView.fields.c_text')" variant="outlined" density="compact" hide-details="auto" rows="3" auto-grow tabindex="23" />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>


                </v-col>

                <!-- ========== RECHTE SPALTE ========== -->
                <v-col cols="12" lg="6">

                    <!-- KBA-Stammdaten (read-only) -->
                    <v-card variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-blue-lighten-5 d-flex align-center">
                            <v-icon class="mr-2" size="small" color="blue-darken-2">mdi-database-outline</v-icon>
                            <span class="text-subtitle-1 font-weight-medium text-blue-darken-2">{{ t('CarEditView.sections.kba') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-card-text v-if="kbaData" class="py-2 px-2 px-sm-3">
                            <!-- Hauptfelder -->
                            <v-row dense>
                                <v-col cols="12" class="py-1">
                                    <v-text-field :model-value="kbaData.hersteller" :label="t('CarEditView.kba.hersteller')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.fhzart" :label="t('CarEditView.kba.fhzart')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.d2" :label="t('CarEditView.kba.d2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="12" class="py-1">
                                    <v-text-field :model-value="kbaData.name" :label="t('CarEditView.kba.name')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                            </v-row>

                            <v-divider class="my-1" />

                            <v-row dense>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.hubraum ? kbaData.hubraum + ' ccm' : ''" :label="t('CarEditView.kba.hubraum')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.leistung ? kbaData.leistung + ' kW / ' + Math.round(kbaData.leistung * 1.35962) + ' PS' : ''" :label="t('CarEditView.kba.leistung')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.kraftstoff" :label="t('CarEditView.kba.kraftstoff')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.t" :label="t('CarEditView.kba.t')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.masse" :label="t('CarEditView.kba.masse')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.achsen" :label="t('CarEditView.kba.achsen')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                            </v-row>
                            <v-row dense>
                                <v-col cols="6" class="py-1">
                                    <v-text-field :model-value="kbaData.field_14_1" :label="t('CarEditView.kba.field_14_1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                </v-col>
                            </v-row>

                            <!-- Erweiterte Felder -->
                                    <v-divider class="my-1" />

                                    <!-- Achslasten -->
                                    <v-row dense>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_7_1" :label="t('CarEditView.kba.field_7_1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_7_2" :label="t('CarEditView.kba.field_7_2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_7_3" :label="t('CarEditView.kba.field_7_3')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Marke, Klasse, Aufbau, Antrieb -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.marke" :label="t('CarEditView.kba.marke')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.klasse" :label="t('CarEditView.kba.klasse')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.aufbau" :label="t('CarEditView.kba.aufbau')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.antrieb" :label="t('CarEditView.kba.antrieb')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.sitze" :label="t('CarEditView.kba.sitze')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.datum" :label="t('CarEditView.kba.datum')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <v-divider class="my-1" />

                                    <!-- D-Felder / Handelsbezeichnung -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.d1" :label="t('CarEditView.kba.d1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.d3" :label="t('CarEditView.kba.d3')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- J, 4, 2, 5 -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.j" :label="t('CarEditView.kba.j')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_4" :label="t('CarEditView.kba.field_4')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_2" :label="t('CarEditView.kba.field_2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_5" :label="t('CarEditView.kba.field_5')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Leistung / Motor -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.p1" :label="t('CarEditView.kba.p1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.p2_p4" :label="t('CarEditView.kba.p2_p4')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.p3" :label="t('CarEditView.kba.p3')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.l" :label="t('CarEditView.kba.l')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Emissionen / Umwelt -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.v9" :label="t('CarEditView.kba.v9')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.v7" :label="t('CarEditView.kba.v7')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_14" :label="t('CarEditView.kba.field_14')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_9" :label="t('CarEditView.kba.field_9')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_10" :label="t('CarEditView.kba.field_10')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Massen -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.g" :label="t('CarEditView.kba.g')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.q" :label="t('CarEditView.kba.q')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.f1" :label="t('CarEditView.kba.f1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.f2" :label="t('CarEditView.kba.f2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Bereifung -->
                                    <v-row dense>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_8_1" :label="t('CarEditView.kba.field_8_1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_8_2" :label="t('CarEditView.kba.field_8_2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_8_3" :label="t('CarEditView.kba.field_8_3')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Geräusch / Abgas -->
                                    <v-row dense>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.u1" :label="t('CarEditView.kba.u1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.u2" :label="t('CarEditView.kba.u2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.u3" :label="t('CarEditView.kba.u3')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Anhängelast -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.o1" :label="t('CarEditView.kba.o1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.o2" :label="t('CarEditView.kba.o2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Sitzplätze -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.s1" :label="t('CarEditView.kba.s1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.s2" :label="t('CarEditView.kba.s2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Bereifung Achsen -->
                                    <v-row dense>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_15_1" :label="t('CarEditView.kba.field_15_1')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_15_2" :label="t('CarEditView.kba.field_15_2')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_15_3" :label="t('CarEditView.kba.field_15_3')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>

                                    <!-- Sonstige -->
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.k" :label="t('CarEditView.kba.k')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_6" :label="t('CarEditView.kba.field_6')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_12" :label="t('CarEditView.kba.field_12')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_13" :label="t('CarEditView.kba.field_13')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_17" :label="t('CarEditView.kba.field_17')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_18" :label="t('CarEditView.kba.field_18')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_19" :label="t('CarEditView.kba.field_19')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                        <v-col cols="4" class="py-1">
                                            <v-text-field :model-value="kbaData.field_20" :label="t('CarEditView.kba.field_20')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                                    <v-row dense>
                                        <v-col cols="6" class="py-1">
                                            <v-text-field :model-value="kbaData.field_21" :label="t('CarEditView.kba.field_21')" variant="plain" density="compact" hide-details readonly tabindex="-1" class="kba-field" />
                                        </v-col>
                                    </v-row>
                        </v-card-text>
                        <v-card-text v-else class="py-4 text-center">
                            <v-icon size="32" color="grey-lighten-1" class="mb-1">mdi-database-off-outline</v-icon>
                            <div class="text-body-2 text-medium-emphasis">{{ t('CarEditView.kba.noData') }}</div>
                        </v-card-text>
                    </v-card>

                    <!-- Debug-Infos (ausklappbar) -->
                    <div v-if="!readonly" class="text-center mt-2">
                        <v-btn variant="text" size="x-small" color="grey" @click="showDebug = !showDebug">
                            <v-icon start size="small">{{ showDebug ? 'mdi-chevron-up' : 'mdi-bug-outline' }}</v-icon>
                            {{ t('CarEditView.sections.debug') }}
                        </v-btn>
                    </div>
                    <v-expand-transition>
                        <v-card v-show="showDebug" class="mt-1" variant="outlined" elevation="1" color="grey-darken-1">
                            <v-card-text class="py-2 px-2 px-sm-3">
                                <v-row dense>
                                    <v-col cols="4" class="py-1">
                                        <v-text-field v-model.number="car.kba_id" :label="t('CarEditView.fields.kba_id')" variant="outlined" density="compact" hide-details="auto" type="number" />
                                    </v-col>
                                    <v-col cols="4" class="py-1">
                                        <v-text-field v-model="car.c_e_id" :label="t('CarEditView.fields.c_e_id')" variant="outlined" density="compact" hide-details="auto" maxlength="30" />
                                    </v-col>
                                    <v-col cols="4" class="py-1">
                                        <v-text-field v-model="car.c_m" :label="t('CarEditView.fields.c_m')" variant="outlined" density="compact" hide-details="auto" maxlength="5" />
                                    </v-col>
                                </v-row>
                                <v-row dense>
                                    <v-col cols="6" class="py-1">
                                        <v-text-field v-model="car.c_mt" :label="t('CarEditView.fields.c_mt')" variant="outlined" density="compact" hide-details="auto" maxlength="30" />
                                    </v-col>
                                    <v-col cols="3" class="py-1">
                                        <v-text-field v-model="car.c_t" :label="t('CarEditView.fields.c_t')" variant="outlined" density="compact" hide-details="auto" maxlength="5" />
                                    </v-col>
                                    <v-col cols="3" class="py-1">
                                        <v-text-field v-model="car.filename" :label="t('CarEditView.fields.filename')" variant="outlined" density="compact" hide-details="auto" />
                                    </v-col>
                                </v-row>
                                <v-row dense>
                                    <v-col cols="6" class="py-1">
                                        <v-text-field v-model="car.scan_id" :label="t('CarEditView.fields.scan_id')" variant="outlined" density="compact" hide-details="auto" />
                                    </v-col>
                                    <v-col cols="6" class="py-1">
                                        <v-text-field v-model="car.scan_detail_id" :label="t('CarEditView.fields.scan_detail_id')" variant="outlined" density="compact" hide-details="auto" />
                                    </v-col>
                                </v-row>
                            </v-card-text>
                        </v-card>
                    </v-expand-transition>

                </v-col>

            </v-row>

            <!-- Aufträge + KI-Chat nebeneinander -->
            <v-row v-if="isEditMode" class="mt-3">
                <v-col cols="12" :lg="oserpData.isLxCars() ? 7 : 12">
                    <!-- Neuste Aufträge -->
                    <v-card variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-file-document-multiple-outline</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('CarEditView.sections.orders') }}</span>
                            <v-chip v-if="orders.length" size="x-small" variant="tonal" color="primary" class="ml-2">{{ orders.length }}</v-chip>
                            <v-btn
                                v-if="!readonly"
                                size="small"
                                variant="outlined"
                                ref="newOrderBtn"
                                color="primary"
                                prepend-icon="mdi-plus"
                                class="ml-3"
                                @click.stop="createNewOrder"
                            >
                                {{ t('CarEditView.orderTable.newOrder') }}
                            </v-btn>
                            <v-spacer />
                            <v-text-field
                                v-if="orders.length > 2"
                                v-model="orderFilter"
                                prepend-inner-icon="mdi-magnify"
                                :placeholder="t('CarEditView.orderTable.filterPlaceholder')"
                                :title="t('CarEditView.orderTable.filterHint')"
                                variant="solo-filled"
                                density="compact"
                                flat
                                hide-details
                                single-line
                                clearable
                                style="max-width: 240px;"
                                tabindex="-1"
                            />
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="pa-0">
                            <v-data-table
                                :headers="orderHeaders"
                                :items="filteredOrders"
                                :sort-by="[{ key: 'transdate', order: 'desc' }]"
                                :items-per-page="20"
                                :no-data-text="t('CarEditView.orderTable.noOrders')"
                                density="compact"
                                hover
                                :row-props="() => ({ class: readonly ? 'zebra-row' : 'zebra-row cursor-pointer' })"
                                class="orders-table zebra-table"
                                @click:row="(event, { item }) => readonly ? null : openOrder(item.id)"
                            >
                                <template #item.ordnumber="{ item }">
                                    <span class="font-weight-medium text-no-wrap">{{ item.ordnumber }}</span>
                                </template>
                                <template #item.transdate="{ item }">
                                    <span class="text-medium-emphasis text-no-wrap">{{ item.transdate }}</span>
                                </template>
                                <template #item.record_type="{ item }">
                                    <v-icon v-if="item.record_type === 'sales_order'" color="success" size="small" :title="t('CarEditView.orderTable.confirmed')">mdi-check-circle</v-icon>
                                    <v-icon v-else color="grey" size="small" :title="t('CarEditView.orderTable.notConfirmed')">mdi-clock-outline</v-icon>
                                </template>
                                <template #item.amount="{ item }">
                                    <span class="font-weight-medium text-no-wrap">{{ formatAmount(item.amount) }}</span>
                                </template>
                            </v-data-table>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- KI-Werkstattmeister-Chat: auch in Readonly bedienbar (eigener v-form-Kontext) -->
                <v-col v-if="oserpData.isLxCars()" cols="12" lg="5">
                    <v-form :readonly="false" autocomplete="off" @submit.prevent>
                        <CarChatCard :car-id="Number(carId)" />
                    </v-form>
                </v-col>
            </v-row>

            <!-- Wiki-Wissensartikel fuer diesen Fahrzeugtyp -->
            <v-card v-if="isEditMode && car.c_2 && car.c_3 && car.c_3.length >= 3" class="mt-3" variant="outlined" elevation="1">
                <v-card-title class="py-2 px-3 bg-amber-lighten-5 d-flex align-center">
                    <v-icon class="mr-2" size="small" color="amber-darken-2">mdi-book-open-variant</v-icon>
                    <span class="text-subtitle-1 font-weight-medium">{{ t('CarEditView.wiki.title') }} ({{ car.c_2 }}/{{ car.c_3.substring(0, 3) }})</span>
                    <v-chip v-if="wikiArticles.length" size="x-small" variant="tonal" color="amber" class="ml-2">{{ wikiArticles.length }}</v-chip>
                    <v-btn v-if="!readonly" size="small" variant="outlined" color="amber-darken-2" prepend-icon="mdi-plus" class="ml-3" tabindex="-1" @click="createKbaArticle">
                        {{ t('CarEditView.wiki.newArticle') }}
                    </v-btn>
                    <v-spacer />
                </v-card-title>
                <v-divider />
                <v-card-text v-if="wikiArticles.length" class="pa-0">
                    <v-expansion-panels variant="accordion">
                        <v-expansion-panel v-for="article in wikiArticles" :key="article.id">
                            <v-expansion-panel-title class="text-body-2">
                                <strong>{{ article.title }}</strong>
                                <span v-if="article.updated_by_name" class="text-caption text-medium-emphasis ml-2">
                                    &mdash; {{ article.updated_by_name }}
                                </span>
                            </v-expansion-panel-title>
                            <v-expansion-panel-text>
                                <div v-html="article.content" class="wiki-article-content"></div>
                                <div v-if="!readonly" class="mt-2">
                                    <v-btn size="small" variant="text" color="primary" :to="{ name: 'wiki-edit', params: { id: article.id } }" tabindex="-1">
                                        <v-icon start size="small">mdi-pencil</v-icon>
                                        {{ t('CarEditView.wiki.edit') }}
                                    </v-btn>
                                </div>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>
                </v-card-text>
                <v-card-text v-else class="text-medium-emphasis text-center py-4">
                    {{ t('CarEditView.wiki.noArticles') }}
                </v-card-text>
            </v-card>

        </v-form>

        <!-- KBA-Auswahldialog (bei mehreren Treffern oder TSN-Platzhalter) -->
        <v-dialog v-model="kbaSelectDialog" max-width="800" persistent @keydown.esc="kbaSelectDialog = false">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-blue-lighten-5 text-blue-darken-2">
                    <v-icon class="mr-2">mdi-database-search-outline</v-icon>
                    {{ t('CarEditView.kba.selectTitle') }}
                    <v-spacer />
                    <v-text-field
                        v-model="kbaSelectFilter"
                        :placeholder="t('CarEditView.kba.searchPlaceholder')"
                        prepend-inner-icon="mdi-magnify"
                        variant="outlined"
                        density="compact"
                        hide-details
                        clearable
                        style="max-width: 260px;"
                    />
                </v-card-title>
                <v-card-text class="pt-3 pb-1">
                    <div class="text-body-2 text-medium-emphasis mb-3">{{ t('CarEditView.kba.selectText') }}</div>
                    <v-table density="compact" hover fixed-header height="400">
                        <thead>
                            <tr>
                                <th>{{ t('CarEditView.kba.hersteller') }}</th>
                                <th>{{ t('CarEditView.kba.name') }}</th>
                                <th>{{ t('CarEditView.kba.hubraum') }}</th>
                                <th>{{ t('CarEditView.kba.leistung') }}</th>
                                <th>{{ t('CarEditView.kba.kraftstoff') }}</th>
                                <th>{{ t('CarEditView.kba.d2') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, idx) in kbaSelectFiltered"
                                :key="row.id"
                                :class="idx % 2 === 0 ? 'bg-white' : 'bg-grey-lighten-4'"
                                style="cursor: pointer;"
                                @click="selectKba(row)"
                            >
                                <td>{{ row.hersteller }}</td>
                                <td>{{ row.name }}</td>
                                <td>{{ row.hubraum }}</td>
                                <td>{{ row.leistung }}</td>
                                <td>{{ row.kraftstoff }}</td>
                                <td>{{ row.d2 }}</td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-btn variant="text" @click="kbaSelectDialog = false">
                        {{ t('CarEditView.kba.selectCancel') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="tonal" @click="skipKbaSelect">
                        <v-icon start>mdi-skip-next</v-icon>
                        {{ t('CarEditView.kba.skipButton') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Bestätigungsdialog: Special-KBA -->
        <v-dialog v-model="showSpecialKbaConfirm" max-width="550" persistent>
            <v-card>
                <v-card-title class="d-flex align-center">
                    <v-icon color="warning" class="mr-2">mdi-alert</v-icon>
                    {{ t('CarEditView.kba.confirmDialog.title') }}
                </v-card-title>
                <v-card-text>
                    <div class="text-body-1 mb-4">{{ t('CarEditView.kba.confirmDialog.text') }}</div>
                    <v-text-field
                        v-model="specialKbaForm.hersteller"
                        :label="t('CarEditView.kba.hersteller') + ' *'"
                        variant="outlined"
                        density="compact"
                        :rules="[v => !!v?.trim() || t('CarEditView.kba.confirmDialog.required')]"
                        class="mb-2"
                    />
                    <v-text-field
                        v-model="specialKbaForm.marke"
                        :label="t('CarEditView.kba.marke') + ' *'"
                        variant="outlined"
                        density="compact"
                        :rules="[v => !!v?.trim() || t('CarEditView.kba.confirmDialog.required')]"
                        class="mb-2"
                    />
                    <v-text-field
                        v-model="specialKbaForm.d2"
                        :label="t('CarEditView.kba.d2')"
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-card-text>
                <v-card-actions>
                    <v-btn variant="outlined" @click="showSpecialKbaConfirm = false">
                        <v-icon start>mdi-arrow-left</v-icon>
                        {{ t('CarEditView.kba.confirmDialog.backButton') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="tonal" color="warning" :disabled="!specialKbaFormValid" @click="confirmSpecialKba">
                        <v-icon start>mdi-database-plus</v-icon>
                        {{ t('CarEditView.kba.confirmDialog.confirmButton') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Fahrzeugschein-Dialog (nur Original-Dokument) -->
        <v-dialog v-model="scanImagesDialog" max-width="900" @keydown.esc="scanImagesDialog = false">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-blue-lighten-5 text-blue-darken-2">
                    <v-icon class="mr-2">mdi-file-image-outline</v-icon>
                    {{ t('CarEditView.scanImages.dialogTitle') }}
                    <v-spacer />
                    <v-btn icon variant="text" size="small" @click="scanImagesDialog = false">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-divider />
                <v-card-text class="pa-4">
                    <div v-if="scanImagesLoading" class="text-center py-8">
                        <v-progress-circular indeterminate size="32" width="3" />
                        <div class="text-body-2 text-medium-emphasis mt-2">{{ t('CarEditView.scanImages.loading') }}</div>
                    </div>
                    <template v-else>
                        <div v-if="scanOriginalSrc" class="text-center scan-original-container mb-4">
                            <div class="text-body-2 text-medium-emphasis mb-2">{{ t('CarEditView.scanImages.original') }}</div>
                            <img :src="scanOriginalSrc" class="rounded border" style="width:100%; display:block;" />
                        </div>
                        <div v-if="hasCropsAvailable">
                            <div class="text-body-2 text-medium-emphasis mb-2">
                                {{ scanOriginalSrc ? t('CarEditView.scanImages.crops') : t('CarEditView.scanImages.noCropsOnly') }}
                            </div>
                            <v-row dense>
                                <v-col v-for="(src, field) in fieldCrops" :key="field" cols="6" sm="4" md="3">
                                    <v-card variant="outlined" class="pa-1">
                                        <img :src="src" class="rounded" style="height:60px; width:100%; object-fit:contain; display:block;" />
                                        <div class="text-caption text-center mt-1">{{ fieldCropLabels[field] || field }}</div>
                                    </v-card>
                                </v-col>
                            </v-row>
                        </div>
                        <div v-if="!scanOriginalSrc && !hasCropsAvailable" class="text-center text-medium-emphasis py-8">
                            <v-icon size="48" color="grey-lighten-1" class="mb-2">mdi-image-off-outline</v-icon>
                            <div>{{ t('CarEditView.scanImages.noImages') }}</div>
                        </div>
                    </template>
                </v-card-text>
            </v-card>
        </v-dialog>

        <!-- Crop-Detail wird als Tooltip direkt am Icon angezeigt -->

        <!-- Rotes Heft Dialog -->
        <RotesHeftDialog v-model="rotesHeftDialog" :car="car" :kba-data="kbaData" />

        <!-- Fahrzeug-Dateimanager Dialog -->
        <CarFilesDialog v-model="filesDialogOpen" :c-id="car.c_id" :plate="car.c_ln || ''" />

        <!-- Verkaufstext-Dialog -->
        <CarSellDialog v-if="isEditMode" v-model="sellDialog" :car="car" :c-id="car.c_id" />

        <!-- KBA-Fuzzy-Korrektur: öffnet bei ungültiger oder unbekannter HSN -->
        <v-dialog v-model="kbaFuzzyDialog" max-width="720" persistent>
            <v-card>
                <v-card-title class="d-flex align-center ga-2 py-3 px-4">
                    <v-icon color="warning" size="24">mdi-alert-circle-outline</v-icon>
                    <span>{{ t('CarEditView.kbaFuzzy.title') }}</span>
                </v-card-title>
                <v-card-text class="pt-2">
                    <v-alert type="warning" variant="tonal" class="mb-4" density="compact">
                        {{ t('CarEditView.kbaFuzzy.warning', { hsn: kbaFuzzyOriginal.hsn, tsn: kbaFuzzyOriginal.tsn }) }}
                    </v-alert>
                    <template v-if="kbaFuzzySuggestions.length">
                        <div class="text-body-2 font-weight-medium mb-2">{{ t('CarEditView.kbaFuzzy.suggestions') }}</div>
                        <v-table density="compact" hover class="rounded border mb-2">
                            <thead>
                                <tr>
                                    <th>{{ t('CarEditView.kbaFuzzy.hsn') }}</th>
                                    <th>{{ t('CarEditView.kbaFuzzy.tsn') }}</th>
                                    <th>{{ t('CarEditView.kbaFuzzy.manufacturer') }}</th>
                                    <th>{{ t('CarEditView.kbaFuzzy.model') }}</th>
                                    <th>{{ t('CarEditView.kbaFuzzy.vehicleType') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in kbaFuzzySuggestions" :key="s.id" style="cursor:pointer" @click="applyKbaFuzzyCorrection(s)">
                                    <td><strong class="text-success-darken-2">{{ s.hsn }}</strong></td>
                                    <td><strong class="text-success-darken-2">{{ s.tsn }}</strong></td>
                                    <td>{{ s.marke || s.hersteller }}</td>
                                    <td>{{ s.name }}</td>
                                    <td>{{ s.fhzart }}</td>
                                    <td>
                                        <v-btn size="x-small" color="primary" variant="tonal" @click.stop="applyKbaFuzzyCorrection(s)">
                                            {{ t('CarEditView.kbaFuzzy.useButton') }}
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </template>
                    <v-alert v-else type="info" variant="tonal" density="compact">
                        {{ t('CarEditView.kbaFuzzy.noSuggestions') }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-4 pb-3">
                    <v-btn color="grey" variant="text" @click="dismissKbaFuzzy">
                        {{ t('CarEditView.kbaFuzzy.keepButton') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- E-Mail versenden Dialog -->
        <SendEmailDialog
            v-model="emailDialog"
            :initial-email="emailDialogInitialEmail"
            :initial-subject="emailDialogInitialSubject"
            :initial-body="emailDialogInitialBody"
            :attachment-filename="emailDialogAttachmentFilename"
            :attachment-content="emailDialogAttachmentContent"
            :attach-full-default="emailDialogAttachFullDefault"
            :from-name="emailDialogFromName"
            record-type="car"
        />

        <!-- Special Dialog -->
        <!-- <SpecialDialog v-if="oserpData.checkPermission('special_access')" v-model="specialDialog" :car-id="car.c_id" /> -->

        <!-- Fahrzeug löschen Bestätigungsdialog -->
        <v-dialog v-model="deleteConfirmDialog" max-width="440">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4">
                    <v-icon class="mr-2" color="error">mdi-alert-circle-outline</v-icon>
                    {{ t('CarEditView.delete.title') }}
                    <v-spacer />
                    <v-btn icon="mdi-close" size="x-small" variant="text" @click="deleteConfirmDialog = false" />
                </v-card-title>
                <v-card-text>
                    {{ t('CarEditView.delete.text', { plate: car.c_ln || '–' }) }}
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="deleteConfirmDialog = false">
                        {{ t('CarEditView.delete.cancel') }}
                    </v-btn>
                    <v-btn color="error" variant="elevated" prepend-icon="mdi-delete" :loading="deleting" @click="executeDeleteCar">
                        {{ t('CarEditView.delete.confirm') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

    </v-container>


</template>

<script>
import { ref, computed, watch, nextTick, defineAsyncComponent, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute, onBeforeRouteLeave } from 'vue-router'
import axios from 'axios'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import { openAppWindow, aagWindowOpen, aagWindowCarId, setAagWindowCarId } from '@/core/utils/aagWindow.js'
import { hasVehicleId, isKbaValid, buildEsiUrl, buildGutmannUrl } from '@/core/utils/diagLinks.js'
import { wikiStore } from '@/core/stores/wiki.store.js'
import { getDistrictByPlate } from '@/features/lxcars/utils/kennzeichen.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { useCarDates } from './composables/useCarDates.js'
import { useVoiceInput } from '@/core/composables/useVoiceInput.js'
import { useViewHistory } from '@/core/composables/useViewHistory.js'
import * as toasts from '@/core/utils/toasts.js'
import { useCarValidation } from './composables/useCarValidation.js'
import { useCarOrders } from './composables/useCarOrders.js'
import { useCarAutoSave } from './composables/useCarAutoSave.js'
import Swal from 'sweetalert2'
import RotesHeftDialog from './components/rotes-heft.dialog.vue'
import CarChatCard from './components/car-chat.card.vue'
import SendEmailDialog from './components/send-email.dialog.vue'
import CarFilesDialog from './components/car-files.dialog.vue'
import CarSellDialog from './components/car-sell.dialog.vue'

// const SpecialDialog = defineAsyncComponent(() => import('@special/special.dialog.vue'))

export default {
    name: 'CarEditView',
    components: { NavbarView, RotesHeftDialog, CarChatCard, SendEmailDialog, CarFilesDialog, CarSellDialog /*, SpecialDialog */ },

    props: {
        id: {
            type: String,
            default: null
        },
        readonly: {
            type: Boolean,
            default: false
        }
    },

    setup(props) {
        const readonly = computed(() => !!props.readonly)
        const { t, locale } = useI18n()
        const router = useRouter()
        const route = useRoute()
        const isMechanicMode = computed(() => route.name === 'mechanic-car')
        const backOrderId = computed(() => {
            const q = route.query.order
            const n = Array.isArray(q) ? q[0] : q
            const id = Number(n)
            return Number.isFinite(id) && id > 0 ? id : null
        })
        function backToOrder() {
            if (backOrderId.value) {
                router.push({ name: 'mechanic-order', params: { id: backOrderId.value } })
            }
        }
        function goBack() {
            if (backOrderId.value) {
                router.push({ name: 'mechanic-order', params: { id: backOrderId.value } })
            } else {
                router.back()
            }
        }
        const oserpData = oserpStore()
        const carsStore = lxcarsStore()
        const wikiStoreInstance = wikiStore()

        const isEditMode = computed(() => !!props.id)
        const carId = computed(() => props.id)

        const car = ref({
            c_ln: '', c_2: '', c_3: '', c_d2: '', c_em: '', c_mkb: '', c_t: '',
            c_d: null, c_hu: null, c_fin: '',
            c_st: '', c_wt: '', c_st_l: '', c_wt_l: '',
            c_mt: '', c_e_id: '', c_text: '', c_m: '', c_color: '', c_gart: '',
            c_st_z: '', c_wt_z: '',
            chk_c_ln: true, chk_c_2: true, chk_c_3: true, chk_c_em: true,
            chk_c_d: true, chk_fin: true, chk_c_hu: true,
            c_sk: false, c_zrk: null, c_zrd: '', c_bf: '', c_wd: '', c_km: null,
            c_pb: false, c_hu_notify: true,
            c_finchk: '', kba_id: null,
            installed_engines: '',
            scan_detail_id: '', scan_id: '', filename: ''
        })

        // Verbaute Motorkennbuchstaben (aus Ktype-Auflösung) als Dropdown-Optionen
        const installedEnginesList = computed(() =>
            String(car.value.installed_engines || '')
                .split(',')
                .map(s => s.trim())
                .filter(Boolean)
        )

        const district = computed(() => getDistrictByPlate(car.value.c_ln))
        const initialLoaded = ref(false)

        // Composables
        const {
            displayD, displayHu, onBlurDate,
            displayZrd, displayBf, displayWd, onBlurMonthYear,
            displayZrk, onBlurKm,
            displayKm, onBlurKmStand
        } = useCarDates(car)

        // ── Intelligente Spracheingabe (Wartung) ──────────────────────────────
        // Der Kollege spricht frei ("Kilometerstand 120369, Zahnriemen fällig bei
        // Kilometer 20000, Bremsflüssigkeit fällig 02/2029"). Whisper transkribiert,
        // der lokale LLM strukturiert, wir tragen die Felder ein wie von Hand
        // getippt (Blur-Handler → Auto-Save greift automatisch).
        const voiceExtracting = ref(false)
        const kmFmt = (v) => Number(v) ? Number(v).toLocaleString('de-DE') : ''

        async function applyVehicleSpeech(text) {
            const spoken = (text || '').trim()
            if (!spoken || readonly.value) return
            voiceExtracting.value = true
            try {
                const { data } = await axios.post('/api/lxcars/', { action: 'extractVehicleData', text: spoken })
                if (!data?.success) {
                    toasts.error(data?.text || t('CarEditView.voice.failed'))
                    return
                }
                const f = data.payload?.fields || {}
                const done = []

                if (f.c_km != null)  { displayKm.value  = String(f.c_km);  onBlurKmStand(); done.push(`${t('CarEditView.fields.c_km')}: ${kmFmt(car.value.c_km)} km`) }
                if (f.c_zrk != null) { displayZrk.value = String(f.c_zrk); onBlurKm();      done.push(`${t('CarEditView.fields.c_zrk')}: ${kmFmt(car.value.c_zrk)} km`) }
                if (f.c_zrd)         { displayZrd.value = String(f.c_zrd); onBlurMonthYear('c_zrd'); done.push(`${t('CarEditView.fields.c_zrd')}: ${displayZrd.value}`) }
                if (f.c_bf)          { displayBf.value  = String(f.c_bf);  onBlurMonthYear('c_bf');  done.push(`${t('CarEditView.fields.c_bf')}: ${displayBf.value}`) }
                if (f.c_wd)          { displayWd.value  = String(f.c_wd);  onBlurMonthYear('c_wd');  done.push(`${t('CarEditView.fields.c_wd')}: ${displayWd.value}`) }
                if (f.c_hu)          { displayHu.value  = '01.' + String(f.c_hu).replace('/', '.'); onBlurDate('c_hu'); done.push(`${t('CarEditView.fields.c_hu')}: ${displayHu.value}`) }
                if (f.c_ln)          { car.value.c_ln = String(f.c_ln).toUpperCase(); done.push(`${t('CarEditView.fields.c_ln')}: ${car.value.c_ln}`) }

                if (done.length) {
                    toasts.success(t('CarEditView.voice.applied') + ' ' + done.join(' · '))
                } else {
                    toasts.info(t('CarEditView.voice.nothing'))
                }
            } catch (e) {
                toasts.error(t('CarEditView.voice.failed'))
            } finally {
                voiceExtracting.value = false
            }
        }

        const {
            recording: voiceRecording,
            busy: voiceBusy,
            supported: voiceSupported,
            toggle: voiceToggle
        } = useVoiceInput({ onText: applyVehicleSpeech })

        const {
            orders, orderFilter, filteredOrders,
            formatAmount, compareDate
        } = useCarOrders(locale)

        // Spalten der Auftrags-Tabelle (CRM-Optik: v-data-table mit Pager)
        const orderHeaders = computed(() => [
            { title: t('CarEditView.orderTable.number'), key: 'ordnumber', sortable: true },
            { title: t('CarEditView.orderTable.date'), key: 'transdate', sortable: true, sort: compareDate },
            { title: t('CarEditView.orderTable.firstPosition'), key: 'description', sortable: true },
            { title: t('CarEditView.orderTable.status'), key: 'record_type', sortable: true, align: 'center', width: '60px' },
            { title: t('CarEditView.orderTable.amount'), key: 'amount', sortable: true, align: 'end' },
        ])

        const {
            rulesLn, rulesHsn, rulesTsn, rulesEm, rulesD, rulesHu, rulesFin, rulesMonthYear,
            hasValidationErrors, finFieldRef, cleanup: validationCleanup
        } = useCarValidation(car, t, { displayD, displayHu, carsStore, isEditMode, carId, initialLoaded })

        // KBA-Daten die beim ersten INSERT mitgeschickt werden (aus Scan)
        const pendingKbaData = ref(null)
        // KBA-Stammdaten (geladen vom Backend oder aus Scan)
        const kbaData = ref(null)
        const showDebug = ref(false)

        // KBA-Auswahldialog (bei mehreren Treffern oder TSN-Platzhalter)
        const kbaSelectDialog = ref(false)
        const kbaSelectOptions = ref([])
        const kbaSelectFilter = ref('')
        const useSpecialKba = ref(false)
        const showSpecialKbaConfirm = ref(false)
        const specialKbaForm = ref({ hersteller: '', marke: '', d2: '' })

        // KBA-Fuzzy-Korrektur (ungültige oder unbekannte HSN)
        const kbaFuzzyDialog = ref(false)
        const kbaFuzzySuggestions = ref([])
        const kbaFuzzyOriginal = ref({ hsn: '', tsn: '' })

        // Flag: Scan-Daten werden gerade importiert → Feld-Watchers (HSN/TSN) nicht auslösen
        let importingScanData = false

        // Scan-Bilder: werden nach dem ersten Save persistent gespeichert
        const pendingScanImages = ref(null)
        // Backup: Bilder im Speicher behalten auch nachdem pendingScanImages geleert wird
        const lastScanImages = ref(null)

        // Wiki-Wissensartikel fuer diesen Fahrzeugtyp
        const wikiArticles = ref([])

        // Rotes Heft Dialog
        const rotesHeftDialog = ref(false)
        const specialDialog = ref(false)
        // Fahrzeug-Dateimanager Dialog
        const filesDialogOpen = ref(false)
        // Verkaufstext-Dialog
        const sellDialog = ref(false)

        // E-Mail Dialog
        const emailDialog = ref(false)
        const emailDialogInitialEmail = ref('')
        const emailDialogInitialSubject = ref('')
        const emailDialogInitialBody = ref('')
        const emailDialogAttachmentFilename = ref('')
        const emailDialogAttachmentContent = ref('')
        const emailDialogAttachFullDefault = ref(true)
        const emailDialogFromName = ref('')

        // Fahrzeug löschen
        const deleteConfirmDialog = ref(false)
        const deleting = ref(false)

        async function executeDeleteCar() {
            deleting.value = true
            markDeleted()
            try {
                await carsStore.deleteCar(car.value.c_id)
                deleteConfirmDialog.value = false
                // Zurück zur Kundenseite navigieren
                const customerId = oserpData.customer_vendor?.profile?.id
                if (customerId) {
                    router.push({ name: 'change-customer', params: { id: customerId } })
                } else {
                    router.push('/')
                }
            } catch (err) {
                console.error('Delete car error:', err)
                Swal.fire({ toast: true, icon: 'error', position: 'top-end', showConfirmButton: false, timer: 3000, title: t('CarEditView.delete.error') })
            } finally {
                deleting.value = false
            }
        }

        // Fahrzeugschein-Dialog
        const scanImagesDialog = ref(false)
        const scanImagesLoading = ref(false)

        const {
            saving, loading, error, savedCarId,
            toggleShield, onFocusIn, onFocusOut, triggerSave, markDeleted
        } = useCarAutoSave({ car, isEditMode, hasValidationErrors, oserpData, carsStore, router, props, t, orders, validationCleanup, initialLoaded, pendingKbaData, kbaData, pendingScanImages, useSpecialKba, kbaSelectDialog, readonly })

        // Aufgerufenes Fahrzeug im "Zuletzt besucht"-Verlauf der Schnellsuche merken.
        // Greift bei jedem Öffnen eines Fahrzeugs (nicht nur aus der Fahrzeugsuche),
        // damit auch über Auftrag/Kunde/Direktlink besuchte Fahrzeuge auftauchen.
        const { saveToHistory: saveVehicleToHistory } = useViewHistory()
        watch(initialLoaded, (loaded) => {
            if (!loaded || !isEditMode.value || readonly.value) return
            const plate = car.value.c_ln
            if (!plate) return
            saveVehicleToHistory({
                type: 'vehicle',
                id: Number(props.id),
                title: plate,
                subtitle: [kbaData.value?.hersteller, kbaData.value?.name, oserpData.customer_vendor?.profile?.name].filter(Boolean).join(' · '),
                route: { name: 'car', params: { id: Number(props.id) } }
            })
        })

        // Scan-Daten übernehmen (von car.scan.view.vue via Store)
        watch(initialLoaded, (loaded) => {
            if (!loaded || isEditMode.value) return
            if (!carsStore.pendingScanData) return

            const scanData = carsStore.pendingScanData
            carsStore.pendingScanData = null

            // Neues Format: { car: {...}, kba: {...} }
            const carFields = scanData.car || scanData
            const kbaFields = scanData.kba || null

            // Feld-Watchers (HSN→TSN leeren) während des Imports deaktivieren
            // nextTick nötig, weil Vue 3 Watchers erst nach dem synchronen Code flusht
            importingScanData = true

            // Fahrzeugdaten aus dem Scan übernehmen
            const scanFieldNames = ['c_ln', 'c_2', 'c_3', 'c_fin', 'c_finchk', 'c_d', 'c_hu', 'c_em']
            for (const field of scanFieldNames) {
                if (carFields[field]) {
                    car.value[field] = carFields[field]
                }
            }

            nextTick(() => { importingScanData = false })

            // Special-KBA-Flag aus Scan übernehmen
            if (scanData.useSpecialKba) {
                useSpecialKba.value = true
            }

            // KBA-Daten für ersten Save merken + sofort anzeigen
            if (kbaFields && Object.keys(kbaFields).length) {
                pendingKbaData.value = kbaFields
                kbaData.value = kbaFields
                // D2 aus KBA-Daten ins Fahrzeug übernehmen
                if (kbaFields.d2 && !car.value.c_d2) {
                    car.value.c_d2 = kbaFields.d2
                }
            }

            // Scan-Bilder für späteres Speichern merken + Backup für Anzeige.
            // Im Listen-Flow liegen die Crops im tmp/{scanId}-Cache; saveScanImages
            // kopiert sie anhand der scanId — daher reicht scanId als Trigger.
            const hasImages = (scanData.images && typeof scanData.images === 'object' && Object.keys(scanData.images).length > 0)
            if (hasImages || scanData.originalImage || scanData.tempImageId || scanData.scanId) {
                const imgData = {
                    images: hasImages ? scanData.images : null,
                    originalImage: scanData.originalImage || null,
                    isPdf: scanData.isPdf || false,
                    tempImageId: scanData.tempImageId || null,
                    scanId: scanData.scanId || null
                }
                pendingScanImages.value = imgData
                lastScanImages.value = imgData
            }

        })

        // Auto-Uppercase für Identifikationsfelder
        const uppercaseFields = ['c_ln', 'c_2', 'c_3', 'c_d2', 'c_em', 'c_fin', 'c_finchk', 'c_mkb']
        watch(car, (val) => {
            for (const f of uppercaseFields) {
                if (typeof val[f] === 'string' && val[f] !== val[f].toUpperCase()) {
                    val[f] = val[f].toUpperCase()
                }
            }
        }, { deep: true })

        // Template-Refs
        const newOrderBtn = ref(null)

        // Zwischenablage
        let copyClickTimer = null
        function showCopyToast(label, text) {
            Swal.fire({
                toast: true, icon: 'info', position: 'top-end',
                showConfirmButton: false, timer: 1000, timerProgressBar: false,
                showClass: { popup: 'swal2-show', icon: '' },
                hideClass: { popup: 'swal2-hide' },
                title: t('CarEditView.messages.copied', { label, text })
            })
        }

        function copyToClipboard(label, text) {
            if (!text) return
            if (copyClickTimer) { clearTimeout(copyClickTimer); copyClickTimer = null }
            copyClickTimer = setTimeout(() => {
                copyClickTimer = null
                navigator.clipboard.writeText(text.trim())
                showCopyToast(label, text.trim())
            }, 250)
        }

        function copyToClipboardNow(label, text) {
            if (!text) return
            if (copyClickTimer) { clearTimeout(copyClickTimer); copyClickTimer = null }
            navigator.clipboard.writeText(text.trim())
            showCopyToast(label, text.trim())
        }

        function kbaClipboardText() {
            return (car.value.c_2 || '').replace(/\s/g, '') + (car.value.c_3 || '').substring(0, 3)
        }

        // Auto-Advance: HSN (4 Zeichen) → TSN
        const tsnFieldRef = ref(null)
        watch(() => car.value.c_2, (val) => {
            if (val && val.length === 4) {
                nextTick(() => {
                    tsnFieldRef.value?.focus()
                })
            }
        })

        // Prüft ob die TSN ein Platzhalter ist (nur Nullen, mind. 3)
        function hasTsnPlaceholder(tsn) {
            return /^0{3,}$/.test((tsn || '').trim())
        }

        // KBA-Auto-Lookup: Wenn HSN+TSN vorhanden aber kein kba_id → KBA suchen
        let kbaLookupPending = false
        async function tryKbaLookup() {
            const hsn = (car.value.c_2 || '').trim()
            const tsn = (car.value.c_3 || '').trim()

            if (!hsn || tsn.length < 3) return
            if (kbaLookupPending) return

            // Ungültige HSN (Buchstaben) → sofort Fuzzy-Korrektur anbieten
            if (!/^\d{4}$/.test(hsn)) {
                await tryKbaFuzzy(hsn, tsn)
                return
            }

            if (car.value.kba_id || kbaData.value) return

            kbaLookupPending = true
            try {
                // TSN-Platzhalter (000+) → alle Fahrzeuge dieser HSN anzeigen
                if (hasTsnPlaceholder(tsn)) {
                    const results = await carsStore.lookupKbaByHsn(hsn)
                    kbaSelectOptions.value = results || []
                    kbaSelectFilter.value = ''
                    kbaSelectDialog.value = true
                    return
                }

                const d2 = (car.value.c_d2 || '').trim()
                const results = await carsStore.lookupKba(hsn, tsn, d2)
                if (!results || results.length === 0) {
                    // Exakt kein Treffer → Fuzzy-Korrektur versuchen
                    await tryKbaFuzzy(hsn, tsn)
                    return
                }

                if (results.length === 1) {
                    car.value.kba_id = results[0].id
                    kbaData.value = results[0]
                } else {
                    kbaSelectOptions.value = results
                    kbaSelectFilter.value = ''
                    kbaSelectDialog.value = true
                }
            } catch (e) {
                console.error('KBA lookup error:', e)
            } finally {
                kbaLookupPending = false
            }
        }

        async function tryKbaFuzzy(hsn, tsn) {
            if (!hsn || tsn.length < 3) return
            try {
                const tsn3 = tsn.substring(0, 3)
                const result = await carsStore.lookupKbaFuzzy(hsn, tsn3)
                if (result.exact) return  // Alles korrekt

                const suggestions = result.suggestions || []

                // Eindeutiger Treffer → sofort automatisch korrigieren
                const uniqueHsnTsn = [...new Set(suggestions.map(s => s.hsn + '/' + s.tsn))]
                if (uniqueHsnTsn.length === 1) {
                    applyKbaFuzzyCorrection(suggestions[0])
                    return
                }

                // Mehrere Treffer oder kein Treffer → Dialog
                kbaFuzzySuggestions.value = suggestions
                kbaFuzzyOriginal.value = { hsn, tsn: tsn3 }
                kbaFuzzyDialog.value = true
            } catch (e) {
                console.error('KBA fuzzy error:', e)
            }
        }

        function applyKbaFuzzyCorrection(suggestion) {
            car.value.c_2 = suggestion.hsn
            car.value.c_3 = suggestion.tsn
            car.value.kba_id = suggestion.id
            kbaData.value = { ...suggestion }
            kbaFuzzyDialog.value = false
        }

        function dismissKbaFuzzy() {
            kbaFuzzyDialog.value = false
        }

        const kbaSelectFiltered = computed(() => {
            const q = (kbaSelectFilter.value || '').toLowerCase().trim()
            if (!q) return kbaSelectOptions.value
            return kbaSelectOptions.value.filter(k =>
                (k.name || '').toLowerCase().includes(q) ||
                (k.tsn || '').toLowerCase().includes(q) ||
                (k.d2 || '').toLowerCase().includes(q) ||
                (k.marke || '').toLowerCase().includes(q) ||
                (k.hersteller || '').toLowerCase().includes(q)
            )
        })

        function selectKba(row) {
            // Bei TSN-Platzhalter: TSN aus KBA übernehmen
            if (hasTsnPlaceholder(car.value.c_3)) {
                car.value.c_3 = row.tsn || car.value.c_3
            }
            // D2 aus KBA übernehmen wenn nicht manuell gesetzt
            if (!car.value.c_d2 && row.d2) {
                car.value.c_d2 = row.d2
            }
            car.value.kba_id = row.id
            kbaData.value = row
            useSpecialKba.value = false
            kbaSelectDialog.value = false
        }

        function skipKbaSelect() {
            specialKbaForm.value = { hersteller: '', marke: '', d2: '' }
            showSpecialKbaConfirm.value = true
        }

        const specialKbaFormValid = computed(() =>
            specialKbaForm.value.hersteller?.trim() && specialKbaForm.value.marke?.trim()
        )

        async function confirmSpecialKba() {
            if (!specialKbaFormValid.value) return

            showSpecialKbaConfirm.value = false
            useSpecialKba.value = true
            kbaSelectDialog.value = false

            const kba = {
                hsn: (car.value.c_2 || '').trim(),
                tsn: (car.value.c_3 || '').trim(),
                hersteller: specialKbaForm.value.hersteller.trim(),
                marke: specialKbaForm.value.marke.trim(),
                d2: (specialKbaForm.value.d2 || '').trim(),
            }
            pendingKbaData.value = kba

            // Fahrzeug existiert bereits → direkt Special-KBA speichern
            const carId = Number(props.id || savedCarId.value)
            if (carId) {
                await carsStore.saveSpecialKba(carId, kba)
                pendingKbaData.value = null
                return
            }

            // Fahrzeug noch nicht gespeichert → jetzt Fahrzeug anlegen + Special-KBA
            if (!oserpData.customer_vendor?.profile?.id) return
            if (!car.value.c_ln?.trim()) return

            const payload = {
                ...car.value,
                c_ow: oserpData.customer_vendor.profile.id
            }
            const result = await carsStore.saveCar(payload, null)
            pendingKbaData.value = null

            if (result.payload?.c_id) {
                savedCarId.value = result.payload.c_id
                const resolved = router.resolve({ name: 'car', params: { id: result.payload.c_id } })
                window.history.replaceState(history.state, '', resolved.href)

                await carsStore.saveSpecialKba(result.payload.c_id, kba)
            }
        }

        // HSN-Änderung: TSN + D2 leeren, KBA-Zuordnung zurücksetzen
        watch(() => car.value.c_2, (newVal, oldVal) => {
            if (!initialLoaded.value || importingScanData) return
            if ((newVal || '').trim() === (oldVal || '').trim()) return
            car.value.c_3 = ''
            car.value.c_d2 = ''
            car.value.kba_id = null
            kbaData.value = null
            kbaLookupPending = false
        })

        // TSN-Änderung: KBA-Zuordnung zurücksetzen und neu suchen
        watch(() => car.value.c_3, (newVal, oldVal) => {
            if (!initialLoaded.value || importingScanData) return
            if ((newVal || '').trim() === (oldVal || '').trim()) return
            car.value.kba_id = null
            kbaData.value = null
            kbaLookupPending = false
            tryKbaLookup()
        })

        // D2-Änderung: KBA-Zuordnung zurücksetzen und neu suchen
        watch(() => car.value.c_d2, (newVal, oldVal) => {
            if (!initialLoaded.value || importingScanData) return
            if ((newVal || '').trim() === (oldVal || '').trim()) return
            if (car.value.kba_id || kbaData.value) {
                car.value.kba_id = null
                kbaData.value = null
                kbaLookupPending = false
            }
            tryKbaLookup()
        })

        // Beim Laden: KBA-Lookup falls kba_id fehlt; bei ungültiger HSN sofort Fuzzy-Dialog
        watch(initialLoaded, (loaded) => {
            if (!loaded || !isEditMode.value) return
            const hsn = (car.value.c_2 || '').trim()
            const tsn = (car.value.c_3 || '').trim()
            if (hsn && !/^\d{4}$/.test(hsn) && tsn.length >= 3) {
                tryKbaFuzzy(hsn, tsn)
            } else if (!car.value.kba_id) {
                tryKbaLookup()
            }
        })

        // Wiki-Artikel fuer KBA laden
        async function loadWikiArticles() {
            const hsn = (car.value.c_2 || '').trim()
            const tsn = (car.value.c_3 || '').trim()
            if (hsn.length >= 4 && tsn.length >= 3) {
                try {
                    wikiArticles.value = await wikiStoreInstance.fetchPagesByKba(hsn, tsn.substring(0, 3))
                } catch (e) {
                    console.error('Wiki KBA articles error:', e)
                    wikiArticles.value = []
                }
            } else {
                wikiArticles.value = []
            }
        }

        // Watcher: Wiki-Artikel laden wenn HSN/TSN sich aendern
        watch([() => car.value.c_2, () => car.value.c_3], () => {
            if (initialLoaded.value && isEditMode.value) loadWikiArticles()
        })

        // Beim Laden: Wiki-Artikel laden
        watch(initialLoaded, (loaded) => {
            if (loaded && isEditMode.value) loadWikiArticles()
        })

        function createKbaArticle() {
            const hsn = (car.value.c_2 || '').trim()
            const tsn = (car.value.c_3 || '').trim().substring(0, 3)
            router.push({ name: 'wiki-new', query: { hsn, tsn } })
        }

        function openOrder(orderId) {
            router.push({ name: 'faktura-order-view', params: { id: orderId } })
        }

        function createNewOrder() {
            router.push({ name: 'order-new', query: { c_id: props.id } })
        }

        function navigateToCustomer() {
            const cvId = oserpData.customer_vendor?.profile?.id
            if (cvId) {
                router.push({ name: 'change-customer', params: { id: cvId } })
            }
        }

        function openCarRegistration() {
            router.push({ name: 'car-registration', params: { id: props.id } })
        }

        // ===== AAG-Online per FIN (bei TSN-Platzhalter) =====

        const aagLoading = ref(false)
        // Wurde das AAG-Portal geöffnet? Dann beim Zurückkehren (Fenster-Fokus)
        // prüfen, ob dort ein Fahrzeug gewählt wurde, und den Ktype übernehmen.
        let aagPortalOpened = false
        let aagCloseTimer = null   // Poll-Timer, der auf das Schließen des Popups wartet
        let aagEngineSeed = ''     // beim Button gesendeter Motorcode (Echo ignorieren)

        // AAG-Online konfiguriert? (Zugangsdaten in den Firmen-Defaults hinterlegt)
        const aagConfigured = computed(() =>
            !!String(oserpData.getClientDefaultValue('aag_online_user', '') || '').trim() &&
            !!String(oserpData.getClientDefaultValue('aag_online_passwd', '') || '').trim()
        )

        // Kontextbutton am TSN-Feld: nur wenn AAG konfiguriert, TSN ein Platzhalter
        // (beginnt mit 000) und eine FIN vorhanden ist, mit der gesucht werden kann.
        const showAagTsnButton = computed(() =>
            aagConfigured.value &&
            (car.value.c_3 || '').substring(0, 3) === '000' && !!(car.value.c_fin || '').trim()
        )

        // AAG-Button in der oberen Leiste: sobald das Fahrzeug identifizierbar ist
        // (Ktype, gültige HSN/TSN oder FIN) und AAG konfiguriert ist.
        const aagAvailable = computed(() => {
            if (!aagConfigured.value || !isEditMode.value) return false
            const hsn = (car.value.c_2 || '').trim()
            const tsn = (car.value.c_3 || '').trim()
            const hasKba = /^\d{4}$/.test(hsn) && tsn.length >= 3 && !hasTsnPlaceholder(tsn)
            return !!ktypeNo.value || hasKba || !!(car.value.c_fin || '').trim()
        })

        // Fenstertitel/-name für AAG-Online: Kennzeichen zuerst, dann Modell
        // (z. B. "M-AB 1234 · Audi Q5"). Modell aus den KBA-Daten, sonst aus dem
        // Typ-Feld (D.2) bzw. dem gespeicherten Modelltext.
        function aagWindowInfo() {
            const plate = (car.value.c_ln || '').trim()
            const model =
                [kbaData.value?.hersteller, kbaData.value?.name].filter(Boolean).join(' ').trim() ||
                (car.value.c_d2 || '').trim() ||
                (car.value.c_mt || '').trim()
            const title = [plate, model].filter(Boolean).join(' · ') || 'AAG-Online'
            const name = 'aag-' + (plate.replace(/\s+/g, '') || String(props.id) || 'online')
            return { title, name }
        }

        async function openAag() {
            // Zeigt das (einzige) AAG-Fenster bereits dieses Fahrzeug? Dann nur nach
            // vorn holen — kein erneuter Import. Ein Re-Import würde die Portal-Sitzung
            // neu laden (gewähltes Fahrzeug/Warenkorb weg) und den Beleg neu mit c_mkb
            // seeden. Für eine bewusste Neu-Übertragung (geänderte Daten) das
            // AAG-Fenster vorher schließen.
            if (aagWindowOpen() && aagWindowCarId() === Number(props.id)) {
                openAppWindow() // bringt das vorhandene Fenster in den Vordergrund
                return
            }

            // Vorhandenes AAG-Fenster wiederverwenden (genau ein Fenster) oder neu öffnen.
            // Sofort öffnen (Popup-Blocker vermeiden, wirkt schneller).
            const reusing = aagWindowOpen()
            const { title, name } = aagWindowInfo()
            const aagWindow = openAppWindow(name, title)

            aagLoading.value = true
            try {
                const { data } = await axios.post('/api/lxcars/', {
                    action: 'getAagVehicleUrl',
                    c_id: Number(props.id) || 0,
                    vin: (car.value.c_fin || '').trim()
                })

                const portalUrl = data?.success ? data.payload?.portalUrl : null
                if (!portalUrl) {
                    if (aagWindow && !reusing) aagWindow.close() // nur frisch geöffnetes schließen
                    Swal.fire({ icon: 'error', title: t('CarEditView.aag.error'), text: data?.text || '' })
                    return
                }
                aagPortalOpened = true // beim Zurückkehren ggf. Auswahl übernehmen
                // Den jetzt gesendeten Motorcode merken — AAG echo't ihn zurück,
                // er zählt also NICHT als neue Auswahl (nur ein abweichender Code zählt).
                aagEngineSeed = (car.value.c_mkb || '').trim()
                if (aagWindow) {
                    aagWindow.location.href = portalUrl
                    aagWindow.focus()
                    setAagWindowCarId(Number(props.id))
                    startAagCloseWatch()
                } else {
                    window.open(portalUrl, '_blank')
                }
            } catch (e) {
                if (aagWindow && !reusing) aagWindow.close()
                console.error('AAG-Online error:', e)
                Swal.fire({ icon: 'error', title: t('CarEditView.aag.error'), text: String(e?.message || e) })
            } finally {
                aagLoading.value = false
            }
        }

        // ===== Esitronic (Bosch ESI[tronic] 2.0) per HSN/TSN öffnen =====
        //
        // Der Browser kann keine lokale .exe starten. Auf den Arbeitsplätzen ist
        // daher einmalig ein Custom-URL-Protokoll registriert (esitronic://),
        // das einen kleinen Launcher mit HSN/TSN aufruft und ESI[tronic] startet.
        // Siehe dev/esitronic-protokoll-setup.md.

        // Sichtbar wie der AAG-Button: sobald das Fahrzeug identifizierbar ist
        // (gültige HSN/TSN ODER eine FIN). Bei ausgenullter TSN wird die FIN übergeben.
        const esiAvailable = computed(() =>
            isEditMode.value && hasVehicleId(car.value.c_2, car.value.c_3, car.value.c_fin)
        )

        function openEsi() {
            if (!hasVehicleId(car.value.c_2, car.value.c_3, car.value.c_fin)) {
                Swal.fire({ icon: 'warning', title: t('CarEditView.esi.noKba') })
                return
            }
            // Protokoll-Aufruf an den lokal registrierten Handler übergeben.
            // Registrierte Protokolle navigieren die Seite nicht weg; ist nichts
            // registriert, zeigt der Browser nur einen Hinweis.
            window.location.href = buildEsiUrl(car.value.c_2, car.value.c_3, car.value.c_fin)
        }

        // ===== Hella Gutmann mega macs (Web-Oberfläche im Werkstatt-LAN) =====
        //
        // Anders als ESI (Desktop) ist mega macs X über eine Browser-Oberfläche
        // im Werkstattnetz erreichbar. Basis-URL kommt aus den Firmen-Defaults
        // (gutmann_megamacs_url, z. B. http://macsx-6129:8889); HSN/TSN/FIN
        // werden als Query angehängt und die Seite als App-Fenster geöffnet.

        const gutmannBaseUrl = computed(() =>
            String(oserpData.getClientDefaultValue('gutmann_megamacs_url', '') || '').trim()
        )

        const gutmannAvailable = computed(() =>
            isEditMode.value && !!gutmannBaseUrl.value && hasVehicleId(car.value.c_2, car.value.c_3, car.value.c_fin)
        )

        function openGutmann() {
            if (!hasVehicleId(car.value.c_2, car.value.c_3, car.value.c_fin)) {
                Swal.fire({ icon: 'warning', title: t('CarEditView.gutmann.noKba') })
                return
            }
            const url = buildGutmannUrl(gutmannBaseUrl.value, car.value.c_2, car.value.c_3, car.value.c_fin)

            // mega macs ist eine Web-App → als eigenes App-Fenster öffnen (wie AAG)
            const win = openAppWindow('gutmann-megamacs')
            if (win) {
                win.location.href = url
                win.focus()
            } else {
                window.open(url, '_blank')
            }
        }

        // ===== HGS-Data (Hella Gutmann Online-Fahrzeugdaten) =====
        //
        // HGS-Data adressiert Fahrzeuge über eine interne vehicleId. Das Backend
        // meldet sich an, löst per HSN/TSN-Suche die vehicleId auf und liefert die
        // car-data-URL; wir öffnen sie als App-Fenster (Browser ist eingeloggt).

        const hgsLoading = ref(false)

        // HGS-Data-Suche braucht gültige HSN/TSN.
        const hgsAvailable = computed(() =>
            isEditMode.value && isKbaValid(car.value.c_2, car.value.c_3)
        )

        async function openHgs() {
            // Sofort öffnen (Popup-Blocker vermeiden); URL setzen, sobald sie vorliegt.
            const win = openAppWindow('hgs-data')
            hgsLoading.value = true
            try {
                const { data } = await axios.post('/api/lxcars/', {
                    action: 'getHgsVehicleUrl',
                    c_id: Number(props.id) || 0
                })
                const portalUrl = data?.success ? data.payload?.portalUrl : null
                if (!portalUrl) {
                    if (win) win.close()
                    Swal.fire({ icon: 'error', title: t('CarEditView.hgs.error'), text: data?.payload?.message || data?.text || '' })
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
                Swal.fire({ icon: 'error', title: t('CarEditView.hgs.error'), text: String(e?.message || e) })
            } finally {
                hgsLoading.value = false
            }
        }

        // ===== TecDoc-Ktype (Hintergrund-Ermittlung beim Laden) =====

        const ktypeNo = ref(null)
        const ktypeDesc = ref('')
        const ktypeLoading = ref(false)

        // Fahrzeug identifizierbar, wenn gültige HSN/TSN ODER eine FIN vorliegt
        function carIsIdentifiable() {
            const hsn = (car.value.c_2 || '').trim()
            const tsn = (car.value.c_3 || '').trim()
            const hasKba = /^\d{4}$/.test(hsn) && tsn.length >= 3 && !hasTsnPlaceholder(tsn)
            return hasKba || !!(car.value.c_fin || '').trim()
        }

        // Motorcode sichtbar machen: ist c_mkb leer und genau ein Motor bekannt,
        // diesen ins Feld übernehmen. Wird beim Laden UND nach der Ktype-Abfrage genutzt.
        function applyEnginesToMkb() {
            if (!(car.value.c_mkb || '').trim() && installedEnginesList.value.length === 1) {
                car.value.c_mkb = installedEnginesList.value[0]
            }
        }

        // Ktype (und ggf. Motor) auflösen/aktualisieren. Setzt c_mkb nur, wenn es leer
        // ist (applyEnginesToMkb) — die aktive Motorauswahl aus dem Portal läuft
        // seed-bewusst über syncAagEngine, nicht hier (sonst Echo-Übernahme).
        async function resolveKtypeBg() {
            if (ktypeLoading.value || !isEditMode.value || !aagConfigured.value || !carIsIdentifiable()) return
            ktypeLoading.value = true
            try {
                const res = await carsStore.resolveKtype(Number(props.id))
                if (res?.c_ktype) {
                    ktypeNo.value = res.c_ktype
                    ktypeDesc.value = res.c_ktype_desc || ''
                }
                // Verbaute Motorkennbuchstaben übernehmen (für das c_mkb-Dropdown)
                if (res && res.installed_engines !== undefined && res.installed_engines !== null) {
                    car.value.installed_engines = res.installed_engines
                }
                applyEnginesToMkb()
            } catch (e) {
                console.error('Ktype resolve error:', e)
            } finally {
                ktypeLoading.value = false
            }
        }

        // Beim Laden: gespeicherten Ktype übernehmen, sonst im Hintergrund ermitteln
        watch(initialLoaded, (loaded) => {
            if (!loaded || !isEditMode.value) return
            ktypeNo.value = car.value.c_ktype || null
            ktypeDesc.value = car.value.c_ktype_desc || ''
            // Bereits gespeicherten Motorcode sofort sichtbar machen
            applyEnginesToMkb()
            // Motorcode nachladen, wenn FIN vorhanden, aber noch keiner gespeichert ist.
            // Pro Browser-Session nur einmal je Fahrzeug versuchen — sonst würden
            // Fahrzeuge, für die AAG keinen Motorcode kennt, bei jedem Öffnen erneut
            // mehrere AAG-Abfragen auslösen.
            const triedKey = `aag_eng_tried_${props.id}`
            const needEngines = !!(car.value.c_fin || '').trim()
                && !installedEnginesList.value.length
                && !sessionStorage.getItem(triedKey)
            if (needEngines) sessionStorage.setItem(triedKey, '1')
            if (!ktypeNo.value || needEngines) resolveKtypeBg()
        })

        // Leichtgewichtiger Motor-Sync: liest den im AAG-Beleg hinterlegten Motor und
        // übernimmt ihn als aktiven c_mkb — ABER nur, wenn er sich vom gesendeten Seed
        // unterscheidet (sonst ist es nur das Echo, KEINE echte Portal-Auswahl).
        async function syncAagEngine() {
            try {
                const res = await carsStore.getAagEngine(Number(props.id), aagEngineSeed)
                if (!res) return
                if (res.installed_engines !== undefined && res.installed_engines !== null) {
                    car.value.installed_engines = res.installed_engines
                }
                const picked = (res.engine_code || '').trim() // bereits seed-bereinigt (echte Auswahl)
                if (picked && car.value.c_mkb !== picked) {
                    car.value.c_mkb = picked
                    triggerSave()
                }
            } catch (e) {
                console.warn('AAG-Motor-Sync fehlgeschlagen:', e)
            }
        }

        // Rückkehr aus dem Portal: Hat das Fahrzeug schon einen Ktype, nur den Motor
        // syncen (seed-bewusst). Sonst (mehrdeutige FIN: Fahrzeug erst im Portal gewählt)
        // den Ktype auflösen — das übernimmt den Motor mit (c_mkb war leer).
        function onPortalReturn() {
            if (ktypeLoading.value) return
            if (ktypeNo.value) syncAagEngine()
            else resolveKtypeBg()
        }

        // Pollt, ob das AAG-Popup geschlossen wurde → finaler Sync. Robuster als der
        // reine Fenster-Fokus, weil das Schließen das eindeutige "fertig"-Signal ist.
        function startAagCloseWatch() {
            if (aagCloseTimer) clearInterval(aagCloseTimer)
            aagCloseTimer = setInterval(() => {
                if (!aagWindowOpen()) {
                    clearInterval(aagCloseTimer); aagCloseTimer = null
                    aagPortalOpened = false
                    onPortalReturn()
                }
            }, 1000)
        }

        // Fenster-Fokus, solange das Popup offen ist: Motorauswahl übernehmen.
        // aagPortalOpened bleibt aktiv (mehrere Wechsel möglich) — der letzte Sync gewinnt.
        function onWindowFocusKtype() {
            if (!aagPortalOpened) return
            onPortalReturn()
        }
        onMounted(() => window.addEventListener('focus', onWindowFocusKtype))
        onBeforeUnmount(() => {
            window.removeEventListener('focus', onWindowFocusKtype)
            if (aagCloseTimer) { clearInterval(aagCloseTimer); aagCloseTimer = null }
        })

        // ===== Label-Druck =====

        const yellowLabelPrinting = ref(false)
        const tyreLabelPrinting = ref(null)  // null | 'summer' | 'winter' — pro Saison, damit nur der geklickte Button den Spinner zeigt

        /**
         * Druckt grüne Plakette mit Kennzeichen
         */
        async function showNoPrinterError(printerField = 'lxcars_yellow_label_printer') {
            const result = await Swal.fire({
                icon: 'warning',
                title: t('CarEditView.yellowLabel.noPrinter'),
                text: t('CarEditView.yellowLabel.noPrinterHint'),
                showCancelButton: true,
                confirmButtonText: t('CarEditView.yellowLabel.goToConfig'),
                cancelButtonText: 'OK'
            })
            if (result.isConfirmed) {
                router.push({ name: 'client-defaults', query: { tab: 'lxcars', focus: printerField } })
            }
        }

        async function onPrintYellowLabel() {
            const printerId = oserpData.session?.company_config?.defaults_oserp?.lxcars_yellow_label_printer
            if (!printerId) {
                showNoPrinterError()
                return
            }
            yellowLabelPrinting.value = true
            try {
                await carsStore.printYellowLabel(car.value.c_ln, printerId)
            } catch (err) {
                console.error('Yellow label print error:', err)
                Swal.fire({ toast: true, icon: 'error', position: 'top-end', showConfirmButton: false, timer: 3000, title: t('CarEditView.yellowLabel.error') })
            } finally {
                yellowLabelPrinting.value = false
            }
        }

        /**
         * Druckt 4 Reifenetiketten für Sommer- oder Winterreifen
         *
         * @param {string} season - 'summer' oder 'winter'
         */
        async function onPrintTyreLabel(season) {
            const printerId = oserpData.session?.company_config?.defaults_oserp?.lxcars_tyre_label_printer
            if (!printerId) {
                showNoPrinterError('lxcars_tyre_label_printer')
                return
            }
            const dim = season === 'summer' ? car.value.c_st : car.value.c_wt
            const location = season === 'summer' ? car.value.c_st_l : car.value.c_wt_l
            const missing = []
            if (!dim) missing.push(t('CarEditView.tyreLabel.fieldDim'))
            if (!location) missing.push(t('CarEditView.tyreLabel.fieldLocation'))
            if (missing.length) {
                Swal.fire({
                    toast: true, icon: 'error', position: 'top-end',
                    showConfirmButton: false, timer: 3000, timerProgressBar: true,
                    title: t('CarEditView.tyreLabel.missingFields', { fields: missing.join(', ') })
                })
                return
            }
            const customerName = oserpData.customer_vendor?.profile?.name || ''
            const hersteller = kbaData.value?.hersteller || kbaData.value?.d1 || kbaData.value?.marke || ''
            const fhzTyp = kbaData.value?.d2 || ''
            const hubraum = kbaData.value?.hubraum || 0

            tyreLabelPrinting.value = season
            try {
                await carsStore.printTyreLabel({
                    c_ln: car.value.c_ln,
                    name: customerName,
                    dim,
                    location,
                    hersteller,
                    fhz_typ: fhzTyp,
                    hubraum
                }, printerId)
            } catch (err) {
                console.error('Tyre label print error:', err)
                Swal.fire({ toast: true, icon: 'error', position: 'top-end', showConfirmButton: false, timer: 3000, title: t('CarEditView.tyreLabel.error') })
            } finally {
                tyreLabelPrinting.value = null
            }
        }

        const awaitingCustomer = ref(false)

        function focusSearch() {
            awaitingCustomer.value = true
            const input = document.querySelector('.v-app-bar input')
            if (input) input.focus()
        }

        // ===== Besitzer wechseln =====
        const ownerSearchResults = ref([])
        const ownerLoading = ref(false)
        const lastSelectedOwner = ref(null)
        let ownerDebounceTimer = null
        let ownerJustSelected = false

        // Besitzer-Item nach Laden initialisieren
        watch(initialLoaded, (loaded) => {
            if (!loaded) return
            const p = oserpData.customer_vendor?.profile
            if (p?.name && p?.id) {
                lastSelectedOwner.value = { id: p.id, name: p.name }
            }
        })

        const ownerItems = computed(() => {
            const currentId = oserpData.customer_vendor?.profile?.id
            const items = []
            // eslint-disable-next-line eqeqeq
            if (lastSelectedOwner.value && lastSelectedOwner.value.id == currentId) {
                items.push(lastSelectedOwner.value)
            } else if (currentId && oserpData.customer_vendor?.profile?.name) {
                items.push({ id: currentId, name: oserpData.customer_vendor.profile.name })
            }
            for (const r of ownerSearchResults.value) {
                // eslint-disable-next-line eqeqeq
                if (!items.some(i => i.id == r.id)) items.push(r)
            }
            return items
        })

        const currentOwnerId = computed(() => oserpData.customer_vendor?.profile?.id || null)

        function onOwnerSearch(val) {
            clearTimeout(ownerDebounceTimer)
            if (ownerJustSelected) { ownerJustSelected = false; return }
            const search = (val || '').trim()
            if (search.length < 3) { ownerSearchResults.value = []; return }
            ownerLoading.value = true
            ownerDebounceTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.post('/api/faktura/', {
                        action: 'searchFakturaCustomers',
                        search,
                        type: 'customer'
                    })
                    ownerSearchResults.value = data?.results || []
                } catch { ownerSearchResults.value = [] }
                finally { ownerLoading.value = false }
            }, 300)
        }

        async function onOwnerChange(newId) {
            if (!newId) return
            ownerJustSelected = true
            // eslint-disable-next-line eqeqeq
            const sel = ownerSearchResults.value.find(c => c.id == newId)
            if (sel) lastSelectedOwner.value = sel
            ownerSearchResults.value = []
            await oserpData.fetchCustomerOrVendor(newId, 'C')
            triggerSave()
            nextTick(() => {
                newOrderBtn.value?.$el?.focus()
            })
        }

        // ===== Fahrzeugschein-Bilder =====

        const hasScanImages = computed(() => {
            return !!(car.value.filename || pendingScanImages.value || lastScanImages.value)
        })

        // Vom Backend geladene Crop-Daten (persistent nach Seiten-Reload)
        const loadedScanData = ref(null) // { original: { image, mime } | null, crops: { hsn: { image, mime }, ... } }

        // Crops vom Backend laden wenn Fahrzeug gespeicherte Scan-Daten hat
        async function loadScanCropsFromBackend() {
            const carId = Number(props.id || savedCarId.value)
            if (!carId || loadedScanData.value) return
            try {
                loadedScanData.value = await carsStore.getScanCrops(carId)
            } catch (e) {
                console.error('Error loading scan crops:', e)
            }
        }

        // Bei bestehendem Fahrzeug: Crops automatisch laden
        watch(() => car.value.filename, (fn) => {
            if (fn && !loadedScanData.value && !lastScanImages.value) {
                loadScanCropsFromBackend()
            }
        }, { immediate: true })

        // Original-Bild als data-URI
        const scanOriginalSrc = ref('')

        // Ob Crop-Bilder (aus Backend oder Memory) vorhanden sind
        const hasCropsAvailable = computed(() => {
            return Object.keys(fieldCrops.value).length > 0
        })

        // Bild 90° gegen den Uhrzeigersinn drehen (Canvas)
        function rotateImage90CCW(dataUrl) {
            return new Promise((resolve) => {
                const img = new Image()
                img.onload = () => {
                    const canvas = document.createElement('canvas')
                    // Nach 90° CCW-Drehung werden Breite und Höhe getauscht
                    canvas.width = img.height
                    canvas.height = img.width
                    const ctx = canvas.getContext('2d')
                    // 90° CCW = -90° = 270°
                    ctx.translate(0, canvas.height)
                    ctx.rotate(-Math.PI / 2)
                    ctx.drawImage(img, 0, 0)
                    resolve(canvas.toDataURL('image/jpeg', 0.92))
                }
                img.onerror = () => resolve(dataUrl) // Fallback: ungedreht
                img.src = dataUrl
            })
        }

        async function openScanImagesDialog() {
            scanImagesDialog.value = true
            scanImagesLoading.value = true
            scanOriginalSrc.value = ''

            try {
                // Backend-Daten laden falls noch nicht vorhanden
                if (!loadedScanData.value) {
                    await loadScanCropsFromBackend()
                }

                let src = ''

                // Original vom Backend
                if (loadedScanData.value?.original) {
                    const o = loadedScanData.value.original
                    src = `data:${o.mime};base64,${o.image}`
                }
                // Fallback: In-Memory Original
                else if (lastScanImages.value?.originalImage) {
                    const mime = lastScanImages.value.isPdf ? 'application/pdf' : 'image/jpeg'
                    src = `data:${mime};base64,${lastScanImages.value.originalImage}`
                }

                // Bild 90° CCW drehen (nur bei Bildern, nicht bei PDFs)
                if (src && !src.startsWith('data:application/pdf')) {
                    src = await rotateImage90CCW(src)
                }
                scanOriginalSrc.value = src
            } catch (e) {
                console.error('Error loading scan image:', e)
                if (lastScanImages.value?.originalImage) {
                    const mime = lastScanImages.value.isPdf ? 'application/pdf' : 'image/jpeg'
                    scanOriginalSrc.value = `data:${mime};base64,${lastScanImages.value.originalImage}`
                }
            } finally {
                scanImagesLoading.value = false
            }
        }

        // ===== Crop-Bilder pro Feld =====

        // Mapping: Car-Feld → mögliche API-Image-Keys (API liefert z.B. 'hsn_img', 'vin_img')
        const fieldToCropKeys = {
            c_ln: ['registrationNumber_img', 'registrationnumber_img', 'registrationNumberImg'],
            c_2: ['hsn_img', 'hsnImg'],
            c_3: ['tsn_img', 'tsnImg', 'field_2_2_img'],
            c_fin: ['vin_img', 'vinImg'],
            c_finchk: ['field_3_img', 'field_3Img'],
            c_d: ['ez_img', 'ezImg'],
            c_hu: ['hu_img', 'huImg'],
            c_d2: ['d2_1_img', 'd2_1Img', 'd2_2_img', 'd2_2Img', 'd2_3_img', 'd2_3Img', 'd2_4_img', 'd2_4Img'],
            // Nur field_14_1 (= Emissionsklasse). field_14 ist ein anderer/größerer
            // Bereich und darf NICHT als Emissions-Crop verwendet werden.
            c_em: ['field_14_1_img', 'em_img', 'emImg'],
        }

        // Mapping: Backend-Crop-Feldname (aus Dateiname) → Car-Feld
        // Enthält sowohl alte Dateinamen (mit trailing _) als auch neue (ohne)
        const diskCropToField = {
            registrationNumber: 'c_ln', 'registrationNumber_': 'c_ln',
            hsn: 'c_2', 'hsn_': 'c_2',
            tsn: 'c_3', 'tsn_': 'c_3', field_2_2: 'c_3', 'field_2_2_': 'c_3',
            vin: 'c_fin', 'vin_': 'c_fin',
            ez: 'c_d', 'ez_': 'c_d',
            hu: 'c_hu', 'hu_': 'c_hu',
            d2_1: 'c_d2', 'd2_1_': 'c_d2', d2_2: 'c_d2', d2_3: 'c_d2', d2_4: 'c_d2',
            // Nur field_14_1 (= Emissionsklasse) auf c_em mappen, NICHT field_14
            // (anderer, größerer Bereich → falscher Ausschnitt).
            field_14_1: 'c_em', 'field_14_1_': 'c_em', em: 'c_em', 'em_': 'c_em',
            field_3: 'c_finchk', 'field_3_': 'c_finchk',
        }

        const fieldCropLabels = {
            c_ln: 'Kennzeichen', c_2: 'HSN', c_3: 'TSN',
            c_fin: 'FIN', c_finchk: 'FIN-Check', c_d: 'Erstzulassung', c_hu: 'HU', c_em: 'Emissionscode',
        }

        // Computed: verfügbare Crop-Bilder als data-URIs pro Feldname
        // Quellen: 1) In-Memory (lastScanImages) → 2) Backend (loadedScanData)
        const fieldCrops = computed(() => {
            const result = {}

            // Quelle 1: In-Memory (frischer Scan, noch nicht gespeichert oder gerade gespeichert)
            const memImgs = lastScanImages.value?.images
            if (memImgs && typeof memImgs === 'object') {
                for (const [field, keys] of Object.entries(fieldToCropKeys)) {
                    for (const key of keys) {
                        if (memImgs[key]) {
                            result[field] = `data:image/jpeg;base64,${memImgs[key]}`
                            break
                        }
                    }
                }
            }

            // Quelle 2: Backend (vom Filesystem geladen)
            const diskCrops = loadedScanData.value?.crops
            if (diskCrops && typeof diskCrops === 'object') {
                for (const [cropField, data] of Object.entries(diskCrops)) {
                    const carField = diskCropToField[cropField]
                    if (carField && !result[carField] && data?.image) {
                        result[carField] = `data:${data.mime};base64,${data.image}`
                    }
                }
            }

            return result
        })

        // Crop-Bilder werden als Tooltip direkt am Icon angezeigt

        function buildCarExport() {
            // Technische / interne Felder, die nicht im Export erscheinen sollen
            const skipFields = new Set([
                'c_id', 'c_ow', 'kba_id', 'scan_id', 'scan_detail_id', 'filename'
            ])
            const displayMap = {
                c_d: displayD.value,
                c_hu: displayHu.value,
                c_zrd: displayZrd.value,
                c_bf: displayBf.value,
                c_wd: displayWd.value,
                c_zrk: displayZrk.value,
                c_km: displayKm.value,
            }
            const labelOverrides = { c_d2: 'D2' }

            const lines = []
            lines.push('╔══════════════════════════════════════════════════════════╗')
            lines.push('║              ' + t('CarEditView.export.header').padEnd(44) + '║')
            lines.push('╚══════════════════════════════════════════════════════════╝')
            lines.push(`${t('CarEditView.export.exportedAt')}: ${new Date().toLocaleString(locale.value)}`)
            lines.push('')
            lines.push('━━━ ' + t('CarEditView.export.sectionCar') + ' ━━━')

            const ownerName = oserpData.customer_vendor?.profile?.name
            if (ownerName) {
                lines.push(`${t('CarEditView.fields.c_ow')}: ${ownerName}`)
            }

            for (const [key, rawValue] of Object.entries(car.value)) {
                if (skipFields.has(key)) continue
                if (key.startsWith('chk_')) continue
                const value = Object.prototype.hasOwnProperty.call(displayMap, key) ? displayMap[key] : rawValue
                if (value === null || value === undefined || value === '' || value === false) continue
                const label = labelOverrides[key] ?? t(`CarEditView.fields.${key}`, key)
                lines.push(`${label}: ${value}`)
            }
            if (kbaData.value && Object.keys(kbaData.value).length) {
                lines.push('')
                lines.push('━━━ ' + t('CarEditView.export.sectionKba') + ' ━━━')
                for (const [key, value] of Object.entries(kbaData.value)) {
                    if (value === null || value === undefined || value === '') continue
                    const label = t(`CarEditView.kba.${key}`, key)
                    lines.push(`${label}: ${value}`)
                }
            }

            lines.push('')
            lines.push('')
            lines.push('   ____________')
            lines.push('  /|  _____   |   ┌─────────────────────────────────────┐')
            lines.push(' / |_/_____\\__|   │  L x C a r s                        │')
            lines.push('|__|__O____O_|    │  ' + t('CarEditView.export.slogan').padEnd(35) + '│')
            lines.push('   `----------    │  https://lxcars.de                  │')
            lines.push('                  └─────────────────────────────────────┘')
            lines.push('')
            lines.push('   ' + t('CarEditView.export.tagline'))

            const content = lines.join('\n')
            const plate = (car.value.c_ln || 'Fahrzeug').replace(/[^a-zA-Z0-9\-]/g, '_')
            const dateStr = new Date().toISOString().slice(0, 10)
            return { content, filename: `Fahrzeug_${plate}_${dateStr}.txt` }
        }

        function exportCarData() {
            const { content, filename } = buildCarExport()
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' })
            const url = URL.createObjectURL(blob)
            const a = document.createElement('a')
            a.href = url
            a.download = filename
            document.body.appendChild(a)
            a.click()
            document.body.removeChild(a)
            URL.revokeObjectURL(url)
        }

        function getEmailPlaceholders() {
            const marke = car.value.c_m || kbaData.value?.hersteller || kbaData.value?.d1 || kbaData.value?.marke || ''
            const modell = kbaData.value?.d2 || kbaData.value?.name || kbaData.value?.d3 || ''
            return {
                kennzeichen: car.value.c_ln || '',
                fin: car.value.c_fin || '',
                marke,
                modell,
                ez: displayD.value || '',
                km: displayKm.value || '',
                hu: displayHu.value || '',
                kunde: oserpData.customer_vendor?.profile?.name || '',
                mitarbeiter: oserpData.session?.logged_in_employee?.name || ''
            }
        }

        function applyPlaceholders(template, values) {
            if (!template) return ''
            return String(template).replace(/\{(\w+)\}/g, (m, key) => (values[key] !== undefined ? values[key] : m))
        }

        function escapeHtml(s) {
            return String(s ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
        }

        function buildDefaultEmailBody(values) {
            const line = (label, val) => val ? `${escapeHtml(label)}: <strong>${escapeHtml(val)}</strong>` : ''
            const lines = [
                line(t('CarEditView.email.bodyFieldPlate'), values.kennzeichen),
                line(t('CarEditView.email.bodyFieldFin'), values.fin),
                line(t('CarEditView.email.bodyFieldMake'), values.marke),
                line(t('CarEditView.email.bodyFieldModel'), values.modell),
                line(t('CarEditView.email.bodyFieldFirstReg'), values.ez),
                line(t('CarEditView.email.bodyFieldKm'), values.km),
                line(t('CarEditView.email.bodyFieldHu'), values.hu)
            ].filter(Boolean).join('<br>')
            return [
                `<p>${escapeHtml(t('CarEditView.email.bodyGreeting'))}</p>`,
                `<p>${escapeHtml(t('CarEditView.email.bodyIntro'))}</p>`,
                `<p>${lines}</p>`,
                `<p>${escapeHtml(t('CarEditView.email.bodyOutro'))}<br>${escapeHtml(values.mitarbeiter)}</p>`
            ].join('')
        }

        function textToHtml(text) {
            if (!text) return ''
            // Zeilenumbrüche in <p>/<br> umwandeln
            const paragraphs = String(text).split(/\n{2,}/).map(p =>
                `<p>${escapeHtml(p).replace(/\n/g, '<br>')}</p>`
            )
            return paragraphs.join('')
        }

        function openEmailDialog() {
            const defaults = oserpData.session?.company_config?.defaults_oserp || {}
            const subjectTpl = defaults.lxcars_email_subject || `${t('CarEditView.email.bodyHeader')} {kennzeichen} — {marke} {modell}`
            const bodyTpl = defaults.lxcars_email_body || ''

            const values = getEmailPlaceholders()

            emailDialogInitialSubject.value = applyPlaceholders(subjectTpl, values).trim()
            emailDialogInitialBody.value = bodyTpl
                ? textToHtml(applyPlaceholders(bodyTpl, values))
                : buildDefaultEmailBody(values)
            emailDialogInitialEmail.value = ''
            emailDialogAttachFullDefault.value = defaults.lxcars_email_attach_full !== false

            // Anhang: gesamter Export als base64
            const { content, filename } = buildCarExport()
            emailDialogAttachmentFilename.value = filename
            // UTF-8-sichere base64-Konvertierung
            emailDialogAttachmentContent.value = btoa(unescape(encodeURIComponent(content)))

            // Absendername: Mitarbeitername oder Company-Name
            emailDialogFromName.value = values.mitarbeiter || oserpData.session?.company_config?.defaults?.company || ''

            emailDialog.value = true
        }

        onBeforeRouteLeave(async (to) => {
            if (!awaitingCustomer.value) return
            awaitingCustomer.value = false

            const id = to.params?.id
            if (!id) return

            if (to.name === 'change-customer') {
                await oserpData.fetchCustomerOrVendor(id, 'C')
                return false
            }
            if (to.name === 'change-vendor') {
                await oserpData.fetchCustomerOrVendor(id, 'V')
                return false
            }
        })

        return {
            t, oserpData, car, orders, loading, error, saving, isEditMode, readonly, isMechanicMode, backOrderId, backToOrder, goBack, district,
            ownerItems, ownerLoading, currentOwnerId, onOwnerSearch, onOwnerChange,
            rulesLn, rulesHsn, rulesTsn, rulesEm, rulesD, rulesHu, rulesFin, rulesMonthYear,
            finFieldRef, tsnFieldRef, newOrderBtn, copyToClipboard, copyToClipboardNow, kbaClipboardText,
            displayD, displayHu, onBlurDate,
            displayZrd, displayBf, displayWd, onBlurMonthYear,
            displayZrk, onBlurKm,
            displayKm, onBlurKmStand,
            voiceRecording, voiceBusy, voiceExtracting, voiceSupported, voiceToggle,
            toggleShield, orderFilter, filteredOrders, orderHeaders,
            formatAmount, openOrder, createNewOrder, navigateToCustomer, openCarRegistration, focusSearch,
            showAagTsnButton, aagAvailable, aagLoading, openAag, aagConfigured,
            esiAvailable, openEsi,
            gutmannAvailable, openGutmann,
            hgsAvailable, hgsLoading, openHgs,
            ktypeNo, ktypeDesc, ktypeLoading, resolveKtypeBg,
            installedEnginesList, triggerSave,
            yellowLabelPrinting, tyreLabelPrinting, onPrintYellowLabel, onPrintTyreLabel,
            onFocusIn, onFocusOut,
            kbaData, showDebug, rotesHeftDialog, specialDialog, filesDialogOpen, sellDialog,
            deleteConfirmDialog, deleting, executeDeleteCar,
            kbaSelectDialog, kbaSelectOptions, kbaSelectFiltered, kbaSelectFilter, selectKba,
            skipKbaSelect, showSpecialKbaConfirm, confirmSpecialKba, useSpecialKba, specialKbaForm, specialKbaFormValid,
            kbaFuzzyDialog, kbaFuzzySuggestions, kbaFuzzyOriginal, applyKbaFuzzyCorrection, dismissKbaFuzzy,
            hasScanImages, hasCropsAvailable, scanImagesDialog, scanImagesLoading, scanOriginalSrc,
            openScanImagesDialog,
            fieldCrops, fieldCropLabels,
            wikiArticles, createKbaArticle,
            carId,
            exportCarData,
            emailDialog, emailDialogInitialEmail, emailDialogInitialSubject, emailDialogInitialBody,
            emailDialogAttachmentFilename, emailDialogAttachmentContent,
            emailDialogAttachFullDefault, emailDialogFromName,
            openEmailDialog
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
    background-color: #f5f5f5;
}


/* ── Auftrags-Tabelle (CRM-Optik: v-data-table mit Pager + Zebra) ── */
.orders-table :deep(tbody tr.cursor-pointer) {
    cursor: pointer;
}
.zebra-table :deep(tbody tr:nth-child(odd)) {
    background-color: rgba(0, 0, 0, 0.03);
}
.zebra-table :deep(tbody tr:hover) {
    background-color: rgba(var(--v-theme-primary), 0.08) !important;
}
.orders-table :deep(td) {
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ── KBA-Felder (read-only) ── */
.bg-blue-lighten-5 {
    background-color: #e3f2fd;
}
.kba-field :deep(.v-field__input) {
    font-weight: 500;
    color: rgba(0, 0, 0, 0.8);
    padding-top: 0;
    min-height: 28px;
}
.kba-field :deep(.v-field) {
    border-bottom: 1px solid rgba(0, 0, 0, 0.08);
}
.kba-field :deep(.v-label) {
    font-size: 0.75rem;
    color: rgba(0, 0, 0, 0.5);
}

/* Wiki-Artikel im Fahrzeug */
.wiki-article-content :deep(p) {
    margin-bottom: 0.5em;
}
.wiki-article-content :deep(ul),
.wiki-article-content :deep(ol) {
    padding-left: 1.5em;
    margin: 0.5em 0;
}
.wiki-article-content :deep(a) {
    color: rgb(var(--v-theme-primary));
}

</style>

<style>
/* Sanftes Fade für Clipboard-Toast */
.swal2-container .swal2-popup.swal2-toast.swal2-show {
    animation: swal2-toast-fade-in 0.2s ease-out;
}
.swal2-container .swal2-popup.swal2-toast.swal2-hide {
    animation: swal2-toast-fade-out 0.3s ease-in;
}
@keyframes swal2-toast-fade-in {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes swal2-toast-fade-out {
    from { opacity: 1; transform: translateY(0); }
    to { opacity: 0; transform: translateY(-8px); }
}

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
