<!-- src/core/views/config/tabs/features.tab.vue -->
<template>
    <div>
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- ERWEITERUNGEN -->
        <!-- Eigenständige Module mit eigenem Datenbankschema. Beim Umschalten
             läuft ein Schema-Update, daher der Bestätigungsdialog im Parent. -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <template v-if="extensions">
            <h3 class="text-h6 mb-1">{{ $t('extensions.title') }}</h3>
            <div class="text-caption text-medium-emphasis mb-4">{{ $t('extensions.intro') }}</div>

            <v-row v-if="extensions.length" class="mb-2">
                <v-col v-for="extension in extensions" :key="extension.name" cols="12" md="6">
                    <v-card
                        :variant="extension.active ? 'outlined' : 'tonal'"
                        :color="extension.active ? 'primary' : undefined"
                        class="feature-card"
                    >
                        <v-card-text class="pa-4">
                            <div class="d-flex align-center justify-space-between">
                                <div class="d-flex align-center ga-3">
                                    <v-icon size="32" :color="extension.active ? 'primary' : 'grey'">
                                        {{ extension.icon }}
                                    </v-icon>
                                    <div>
                                        <div class="text-subtitle-1 font-weight-bold">{{ extension.title }}</div>
                                        <div class="text-caption text-medium-emphasis">{{ extension.description }}</div>
                                    </div>
                                </div>
                                <v-switch
                                    :model-value="extension.active"
                                    color="primary"
                                    hide-details
                                    density="compact"
                                    inset
                                    @update:model-value="value => emit('toggle-extension', { name: extension.name, enabled: !!value })"
                                />
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-alert v-else type="info" variant="tonal" density="compact" class="mb-2">
                {{ $t('extensions.none') }}
            </v-alert>

            <v-divider class="my-6" />
        </template>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- WERKZEUGE (nur Systemadministratoren) -->
        <!-- SQL-Werkzeug der Developer-Tools: standardmäßig aus, nur für
             Administratoren, jede Ausführung landet in sql_tool_log. -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <template v-if="crmDefaults && oserp.isAdmin()">
            <h3 class="text-h6 mb-4">{{ $t('toolsFeatures') }}</h3>

            <v-row class="mb-2">
                <v-col cols="12" md="6">
                    <v-card
                        data-field-name="developer_sql_tool_enabled"
                        :variant="crmDefaults.developer_sql_tool_enabled ? 'outlined' : 'tonal'"
                        :color="crmDefaults.developer_sql_tool_enabled ? 'primary' : undefined"
                        class="feature-card"
                    >
                        <v-card-text class="pa-4">
                            <div class="d-flex align-center justify-space-between ga-3">
                                <div class="d-flex align-center ga-3 flex-grow-1" style="min-width: 0">
                                    <v-icon size="32" :color="crmDefaults.developer_sql_tool_enabled ? 'primary' : 'grey'">mdi-database-cog-outline</v-icon>
                                    <div>
                                        <div class="text-subtitle-1 font-weight-bold">{{ $t('featureSqlTool') }}</div>
                                        <div class="text-caption text-medium-emphasis">{{ $t('featureSqlToolDesc') }}</div>
                                    </div>
                                </div>
                                <v-switch
                                    v-model="crmDefaults.developer_sql_tool_enabled"
                                    :true-value="true"
                                    :false-value="false"
                                    color="primary"
                                    hide-details
                                    density="compact"
                                    inset
                                    class="flex-shrink-0"
                                />
                            </div>
                            <v-alert
                                v-if="crmDefaults.developer_sql_tool_enabled"
                                type="warning" variant="tonal" density="compact" class="mt-3"
                            >
                                {{ $t('featureSqlToolOnHint') }}
                            </v-alert>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-divider class="my-6" />
        </template>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- KAMERA & ÜBERWACHUNG -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <template v-if="crmDefaults">
            <h3 class="text-h6 mb-4">{{ $t('cameraAndSurveillance') }}</h3>

            <v-row class="mb-2">
                <!-- ANPR -->
                <v-col cols="12" md="6">
                    <v-card
                        data-field-name="feature_anpr"
                        :variant="crmDefaults.feature_anpr ? 'outlined' : 'tonal'"
                        :color="crmDefaults.feature_anpr ? 'primary' : undefined"
                        class="feature-card"
                    >
                        <v-card-text class="pa-4">
                            <div class="d-flex align-center justify-space-between">
                                <div class="d-flex align-center ga-3">
                                    <v-icon size="32" :color="crmDefaults.feature_anpr ? 'primary' : 'grey'">mdi-car-search</v-icon>
                                    <div>
                                        <div class="text-subtitle-1 font-weight-bold">ANPR</div>
                                        <div class="text-caption text-medium-emphasis">{{ $t('featureAnprDesc') }}</div>
                                    </div>
                                </div>
                                <v-switch
                                    v-model="crmDefaults.feature_anpr"
                                    :true-value="true"
                                    :false-value="false"
                                    color="primary"
                                    hide-details
                                    density="compact"
                                    inset
                                />
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- NVR -->
                <v-col cols="12" md="6">
                    <v-card
                        data-field-name="feature_nvr"
                        :variant="crmDefaults.feature_nvr ? 'outlined' : 'tonal'"
                        :color="crmDefaults.feature_nvr ? 'primary' : undefined"
                        class="feature-card"
                    >
                        <v-card-text class="pa-4">
                            <div class="d-flex align-center justify-space-between">
                                <div class="d-flex align-center ga-3">
                                    <v-icon size="32" :color="crmDefaults.feature_nvr ? 'primary' : 'grey'">mdi-cctv</v-icon>
                                    <div>
                                        <div class="text-subtitle-1 font-weight-bold">NVR</div>
                                        <div class="text-caption text-medium-emphasis">{{ $t('featureNvrDesc') }}</div>
                                    </div>
                                </div>
                                <v-switch
                                    v-model="crmDefaults.feature_nvr"
                                    :true-value="true"
                                    :false-value="false"
                                    color="primary"
                                    hide-details
                                    density="compact"
                                    inset
                                />
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-divider class="my-6" />
        </template>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- WERKSTATT -->
        <!-- Gutmann-Diagnose: mega macs als Seite in LxCars. Braucht die
             Apache-Weiterleitung (install/gutmann-proxy.sh), darum Standard aus. -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <template v-if="crmDefaults && oserp.isLxCars()">
            <h3 class="text-h6 mb-4">{{ $t('workshopFeatures') }}</h3>

            <v-row class="mb-2">
                <v-col cols="12" md="6">
                    <v-card
                        data-field-name="feature_gutmann"
                        :variant="crmDefaults.feature_gutmann ? 'outlined' : 'tonal'"
                        :color="crmDefaults.feature_gutmann ? 'primary' : undefined"
                        class="feature-card"
                    >
                        <v-card-text class="pa-4">
                            <div class="d-flex align-center justify-space-between ga-3">
                                <div class="d-flex align-center ga-3 flex-grow-1" style="min-width: 0">
                                    <v-icon size="32" :color="crmDefaults.feature_gutmann ? 'primary' : 'grey'">mdi-car-wrench</v-icon>
                                    <div>
                                        <div class="text-subtitle-1 font-weight-bold">{{ $t('featureGutmann') }}</div>
                                        <div class="text-caption text-medium-emphasis">{{ $t('featureGutmannDesc') }}</div>
                                    </div>
                                </div>
                                <v-switch
                                    v-model="crmDefaults.feature_gutmann"
                                    :true-value="true"
                                    :false-value="false"
                                    color="primary"
                                    hide-details
                                    density="compact"
                                    inset
                                    class="flex-shrink-0"
                                />
                            </div>
                            <v-alert v-if="crmDefaults.feature_gutmann" type="info" variant="tonal" density="compact" class="mt-3">
                                {{ $t('featureGutmannHint') }}
                                <code class="d-block mt-1">sudo ./install/gutmann-proxy.sh &lt;adresse:port&gt;</code>
                            </v-alert>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>
        </template>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <!-- VERKAUF -->
        <!-- Wiederkehrende Rechnungen: schaltet nur das Angebot ab (Hinweis im
             Auftrag, Menüpunkt). Bestehende Abrechnungen laufen weiter und
             bleiben an ihrem Auftrag sichtbar — darum der Hinweis mit Anzahl. -->
        <!-- ═══════════════════════════════════════════════════════════════ -->
        <template v-if="crmDefaults">
            <h3 class="text-h6 mb-4">{{ $t('salesFeatures') }}</h3>

            <v-row class="mb-2">
                <v-col cols="12" md="6">
                    <v-card
                        data-field-name="feature_recurring"
                        :variant="crmDefaults.feature_recurring ? 'outlined' : 'tonal'"
                        :color="crmDefaults.feature_recurring ? 'primary' : undefined"
                        class="feature-card"
                    >
                        <v-card-text class="pa-4">
                            <div class="d-flex align-center justify-space-between ga-3">
                                <div class="d-flex align-center ga-3 flex-grow-1" style="min-width: 0">
                                    <v-icon size="32" :color="crmDefaults.feature_recurring ? 'primary' : 'grey'">mdi-autorenew</v-icon>
                                    <div>
                                        <div class="text-subtitle-1 font-weight-bold">{{ $t('featureRecurring') }}</div>
                                        <div class="text-caption text-medium-emphasis">{{ $t('featureRecurringDesc') }}</div>
                                    </div>
                                </div>
                                <v-switch
                                    v-model="crmDefaults.feature_recurring"
                                    :true-value="true"
                                    :false-value="false"
                                    color="primary"
                                    hide-details
                                    density="compact"
                                    inset
                                    class="flex-shrink-0"
                                />
                            </div>
                            <v-alert
                                v-if="!crmDefaults.feature_recurring && recurringActive > 0"
                                type="info" variant="tonal" density="compact" class="mt-3"
                            >
                                {{ $t('featureRecurringActiveHint', { count: recurringActive }) }}
                            </v-alert>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Reverse Charge (§ 13b UStG): eigene Steuerzone mit 0 % und
                     Erlöskonto „Leistungen nach § 13b" — Speichern legt die Zone
                     an bzw. blendet sie aus (SQL reverse_charge_set). Der Satz
                     erscheint auf jedem Beleg in dieser Zone. -->
                <v-col cols="12" md="6">
                    <v-card
                        data-field-name="feature_reverse_charge"
                        :variant="crmDefaults.feature_reverse_charge ? 'outlined' : 'tonal'"
                        :color="crmDefaults.feature_reverse_charge ? 'primary' : undefined"
                        class="feature-card"
                    >
                        <v-card-text class="pa-4">
                            <div class="d-flex align-center justify-space-between ga-3">
                                <div class="d-flex align-center ga-3 flex-grow-1" style="min-width: 0">
                                    <v-icon size="32" :color="crmDefaults.feature_reverse_charge ? 'primary' : 'grey'">mdi-swap-horizontal-bold</v-icon>
                                    <div>
                                        <div class="text-subtitle-1 font-weight-bold">{{ $t('featureReverseCharge') }}</div>
                                        <div class="text-caption text-medium-emphasis">{{ $t('featureReverseChargeDesc') }}</div>
                                    </div>
                                </div>
                                <v-switch
                                    v-model="crmDefaults.feature_reverse_charge"
                                    :true-value="true"
                                    :false-value="false"
                                    color="primary"
                                    hide-details
                                    density="compact"
                                    inset
                                    class="flex-shrink-0"
                                />
                            </div>
                            <!-- text-high-emphasis: der Satz soll nicht die Primärfarbe der Karte erben -->
                            <div data-field-name="reverse_charge_text">
                            <div v-if="crmDefaults.feature_reverse_charge" class="text-high-emphasis mt-3">
                                <v-textarea
                                    v-model="crmDefaults.reverse_charge_text"
                                    :label="$t('reverseChargeText')"
                                    :hint="$t('reverseChargeTextHint')"
                                    persistent-hint
                                    variant="outlined"
                                    density="compact"
                                    rows="2"
                                    auto-grow
                                />
                            </div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <v-divider class="my-6" />
        </template>

        <!-- DATEV & UStVA -->
        <h3 class="text-h6 mb-4">{{ $t('datevAndUstva') }}</h3>

        <v-row>
            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.feature_datev"
                    :label="$t('featureDatev')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.feature_ustva"
                    :label="$t('featureUstva')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Dateimanagement -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('fileManagement') }}</h3>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.webdav"
                    :label="$t('webdav')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.webdav_documents"
                    :label="$t('webdavDocuments')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.webdav_sync_extern"
                    :label="$t('webdavSyncExtern')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.webdav_sync_extern_url"
                    :label="$t('webdavSyncExternUrl')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.webdav_sync_extern_login"
                    :label="$t('webdavSyncExternLogin')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <password-field
                    v-model="defaults.webdav_sync_extern_pass"
                    :label="$t('webdavSyncExternPass')"
                    variant="outlined"
                    density="compact"
                    autocomplete="new-password"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_storage"
                    :label="$t('docStorage')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_storage_for_documents"
                    :label="$t('docStorageForDocuments')"
                    :items="storageOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_storage_for_attachments"
                    :label="$t('docStorageForAttachments')"
                    :items="storageOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_storage_for_images"
                    :label="$t('docStorageForImages')"
                    :items="storageOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_storage_for_shopimages"
                    :label="$t('docStorageForShopimages')"
                    :items="storageOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_delete_printfiles"
                    :label="$t('docDeletePrintfiles')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.doc_max_filesize"
                    :label="$t('docMaxFilesize')"
                    type="number"
                    suffix="MB"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.email_attachment_vc_files_checked"
                    :label="$t('emailAttachmentVcFilesChecked')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.email_attachment_part_files_checked"
                    :label="$t('emailAttachmentPartFilesChecked')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.email_attachment_record_files_checked"
                    :label="$t('emailAttachmentRecordFilesChecked')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Datei-Speicher -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('fileStorage') }}</h3>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_files"
                    :label="$t('docFiles')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.doc_webdav"
                    :label="$t('docWebdav')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- Berichte -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('reports') }}</h3>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.feature_balance"
                    :label="$t('featureBalance')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.feature_eurechnung"
                    :label="$t('featureEurechnung')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.feature_erfolgsrechnung"
                    :label="$t('featureErfolgsrechnung')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- E-Mail -->
            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('email') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-text-field
                    v-model="defaults.global_bcc"
                    :label="$t('globalBcc')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('globalBccHelp') }}
                        </v-tooltip>
                    </template>
                </v-text-field>
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.bcc_to_login"
                    :label="$t('bccToLogin')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-select
                    v-model="defaults.email_journal"
                    :label="$t('emailJournal')"
                    :items="emailJournalOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>

            <!-- E-Mail Absender für verschiedene Belegtypen -->
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_sales_quotation"
                    :label="$t('emailSenderSalesQuotation')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_request_quotation"
                    :label="$t('emailSenderRequestQuotation')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_sales_order"
                    :label="$t('emailSenderSalesOrder')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_purchase_order"
                    :label="$t('emailSenderPurchaseOrder')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_sales_delivery_order"
                    :label="$t('emailSenderSalesDeliveryOrder')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_purchase_delivery_order"
                    :label="$t('emailSenderPurchaseDeliveryOrder')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_invoice"
                    :label="$t('emailSenderInvoice')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_purchase_invoice"
                    :label="$t('emailSenderPurchaseInvoice')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_letter"
                    :label="$t('emailSenderLetter')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12" md="6">
                <v-text-field
                    v-model="defaults.email_sender_dunning"
                    :label="$t('emailSenderDunning')"
                    variant="outlined"
                    density="compact"
                    autocomplete="off"
                />
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.email_subject_transaction_description"
                    :label="$t('emailSubjectTransactionDescription')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { defineProps } from 'vue';
import { useI18n } from 'vue-i18n'
import PasswordField from '@/core/components/password-field.vue';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const oserp = oserpStore();

const props = defineProps({
    defaults: {
        type: Object,
        required: true
    },
    crmDefaults: {
        type: Object,
        default: null
    },
    // Katalog der verfügbaren Erweiterungen inklusive Aktivierungszustand
    extensions: {
        type: Array,
        default: null
    },
    // Anzahl laufender wiederkehrender Abrechnungen (aus getDefaults)
    recurringActive: {
        type: Number,
        default: 0
    }
});

const emit = defineEmits(['toggle-extension']);

// Ja/Nein Optionen für Boolean-Felder
const yesNoOptions = [
    { title: t('yes'), value: true },
    { title: t('no'), value: false }
];

/**
 * Speicher-Optionen für Dokumenten-Backend
 * None = Kein Speicher
 * Filesystem = Dateisystem
 * Webdav = WebDAV-Speicher
 */
const storageOptions = [
    { title: t('none'), value: 'None' },
    { title: t('filesystem'), value: 'Filesystem' },
    { title: t('webdav'), value: 'Webdav' }
];

/**
 * E-Mail Journal Optionen
 * 0 = Kein Journal
 * 1 = Journal ohne Anhänge
 * 2 = Journal mit Anhängen
 */
const emailJournalOptions = [
    { title: t('noJournal'), value: 0 },
    { title: t('journalWithoutAttachments'), value: 1 },
    { title: t('journalWithAttachments'), value: 2 }
];
</script>

<style scoped>
.feature-card {
    transition: all 0.2s ease;
}
.feature-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}
</style>
