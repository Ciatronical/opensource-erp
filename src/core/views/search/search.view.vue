<!-- src/core/views/search/search.view.vue -->
<template>
    <NavbarView :message="message" :messages="messages" />

    <v-container class="pt-2 px-2 px-sm-4" fluid>
        <h1 class="pb-2 text-h5 text-sm-h4">
            {{ pageTitle }}
        </h1>

        <v-row>
            <v-col cols="auto">
                <v-tabs v-model="activeTab" color="primary" direction="vertical">
                    <v-tab value="customer_vendor">{{ t('SearchView.tabs.customer_vendor') }}</v-tab>
                    <v-tab v-if="oserp.isLxCars()" value="vehicle">{{ t('SearchView.tabs.vehicle') }}</v-tab>
                    <v-tab value="article">{{ t('SearchView.tabs.article') }}</v-tab>
                    <v-tab value="invoice">{{ t('SearchView.tabs.invoice') }}</v-tab>
                    <v-tab value="purchase_invoice">{{ t('SearchView.tabs.purchase_invoice') }}</v-tab>
                    <v-tab value="quotation">{{ t('SearchView.tabs.quotation') }}</v-tab>
                    <v-tab value="order">{{ t('SearchView.tabs.order') }}</v-tab>
                    <v-tab value="purchase_order">{{ t('SearchView.tabs.purchase_order') }}</v-tab>
                    <v-tab value="delivery_order">{{ t('SearchView.tabs.delivery_order') }}</v-tab>
                </v-tabs>
            </v-col>

            <v-col>
        <!-- Tab: Kunde/Lieferant/Person (bestehendes Suchformular) -->
        <template v-if="activeTab === 'customer_vendor'">
            <!--
                KRITISCH: @keydown.enter.capture auf v-card
                Fängt ALLE Enter-Tasten in allen Suchfeldern ab!
                .capture = Event wird in der Capture-Phase abgefangen (bevor es die Kinder erreicht)
                NIE entfernen!
            -->
            <v-card @keydown.enter.capture="handleEnterKey">
                <v-card-text>
                    <!--
                        WICHTIG: @search Event wird von SearchFilters ausgelöst
                        Diese Funktionalität MUSS erhalten bleiben für SQL-Query Feld!
                    -->
                    <SearchFilters
                        v-model:type-filter="typeFilter"
                        v-model:use-sql-query="useSqlQuery"
                        v-model:sql-query="sqlQuery"
                        @search="searchCV"
                    />

                    <v-row v-show="!useSqlQuery && (typeFilter === 'customer' || typeFilter === 'vendor')" dense>
                        <v-col>
                            <CustomerVendorSearchForm
                                v-model="searchCriteria"
                                v-model:show-additional-criteria="showAdditionalCriteriaCV"
                                :type-filter="typeFilter"
                            />
                        </v-col>
                    </v-row>

                    <v-row v-show="!useSqlQuery && typeFilter === 'contacts'" dense>
                        <v-col>
                            <ContactsSearchForm v-model="searchCriteria" />
                        </v-col>
                    </v-row>

                    <v-row dense>
                        <v-col class="d-flex justify-end">
                            <!-- SQL-Mode Buttons -->
                            <template v-if="useSqlQuery">
                                <v-btn
                                    color="success"
                                    variant="outlined"
                                    prepend-icon="mdi-content-save"
                                    @click="openSaveQueryDialog"
                                    :disabled="!hasSqlQuery"
                                    class="mr-2"
                                >
                                    {{ t('CustomerVendorSearchView.buttons.save_query') }}
                                </v-btn>

                                <v-btn
                                    color="secondary"
                                    variant="outlined"
                                    prepend-icon="mdi-folder-open"
                                    @click="openSavedQueriesDialog"
                                    class="mr-2"
                                >
                                    {{ t('CustomerVendorSearchView.buttons.saved_queries') }}
                                </v-btn>

                                <v-btn
                                    color="info"
                                    variant="outlined"
                                    prepend-icon="mdi-help-circle"
                                    @click="openSqlHelpDialog"
                                    class="mr-2"
                                >
                                    {{ t('CustomerVendorSearchView.buttons.help') }}
                                </v-btn>
                            </template>

                            <!-- Dubletten des gewaehlten Typs in der Buchhaltung aufraeumen -->
                            <v-btn
                                v-if="typeFilter === 'customer' || typeFilter === 'vendor'"
                                color="secondary"
                                variant="outlined"
                                prepend-icon="mdi-content-duplicate"
                                @click="openDuplicates"
                                class="mr-2"
                            >
                                {{ t('CustomerVendorSearchView.buttons.duplicates') }}
                            </v-btn>

                            <!--
                                WICHTIG: Suchen-Button ruft searchCV() auf
                                Diese Funktion wird AUCH vom Enter-Listener aufgerufen!
                            -->
                            <v-btn color="primary" prepend-icon="mdi-magnify" @click="searchCV">
                                {{ t('CustomerVendorSearchView.buttons.search') }}
                            </v-btn>
                            <v-btn
                                v-if="hasSearchCriteria || hasSqlQuery || hasSearchedCV"
                                color="secondary"
                                variant="outlined"
                                prepend-icon="mdi-close"
                                @click="resetCV"
                                class="ml-2"
                            >
                                {{ t('CustomerVendorSearchView.buttons.reset') }}
                            </v-btn>
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <SearchResultsTable
                v-if="hasSearchedCV"
                class="mt-4"
                :search-results="searchResults"
                :total-results="cvTotal"
                :items-per-page="cvItemsPerPage"
                :loading="loading"
                :loaded-data-type="loadedDataType"
                v-model:selected="selected"
                @refresh="loadCVData"
                @row-click="onCVRowClick"
                @open-brevo-dialog="openBrevoMarketingMailDialog"
                @update:options="onCVOptionsUpdate"
            />

            <v-alert v-else type="info" class="text-center mt-4">
                {{ t('CustomerVendorSearchView.table.text_no_search_table_shown') }}
            </v-alert>
        </template>

        <!-- Tabs: Faktura-Dokumente -->
        <template v-if="isDocumentTab">
            <v-card @keydown.enter.capture="handleDocEnterKey">
                <v-card-text>
                    <!-- Zeitraum (Monat) und Drucker -->
                    <div class="d-flex align-center flex-wrap ga-2 mb-3">
                        <DocumentPeriodNav v-model="docPeriod" @clear="onPeriodClear" />
                        <v-spacer />
                        <v-menu v-if="isPrintableTab && printerList.length > 0">
                            <template #activator="{ props: menu }">
                                <v-btn
                                    v-bind="menu"
                                    variant="tonal"
                                    size="small"
                                    class="text-none"
                                    prepend-icon="mdi-printer-settings"
                                >
                                    {{ selectedPrinter ? selectedPrinter.printer_description : t('SearchView.document_actions.select_printer') }}
                                </v-btn>
                            </template>
                            <v-list density="compact">
                                <v-list-item
                                    v-for="printer in printerList"
                                    :key="printer.id"
                                    prepend-icon="mdi-printer"
                                    :active="selectedPrinter?.id === printer.id"
                                    @click="selectPrinter(printer)"
                                >
                                    <v-list-item-title>{{ printer.printer_description }}</v-list-item-title>
                                </v-list-item>
                            </v-list>
                        </v-menu>
                    </div>

                    <DocumentSearchForm
                        v-model="docSearchCriteria"
                        :document-type="activeTab"
                    />

                    <v-row dense class="mt-2">
                        <v-col class="d-flex justify-end">
                            <v-btn color="primary" prepend-icon="mdi-magnify" @click="searchDocuments">
                                {{ t('CustomerVendorSearchView.buttons.search') }}
                            </v-btn>
                            <v-btn
                                v-if="hasDocSearchCriteria || hasSearchedDoc"
                                color="secondary"
                                variant="outlined"
                                prepend-icon="mdi-close"
                                @click="resetDoc"
                                class="ml-2"
                            >
                                {{ t('CustomerVendorSearchView.buttons.reset') }}
                            </v-btn>
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card v-if="hasSearchedDoc" class="mt-4">
                <v-card-text>
                    <v-toolbar flat>
                        <v-btn
                            icon="mdi-refresh"
                            variant="text"
                            @click="loadDocData"
                            :loading="docLoading"
                        ></v-btn>
                        <v-toolbar-title>
                            {{ docScopeLabel }}
                            <div class="text-caption text-medium-emphasis">{{ docSummary }}</div>
                        </v-toolbar-title>
                        <v-spacer></v-spacer>

                        <!-- Sammelaktionen: Auswahl oder alle Treffer -->
                        <v-menu v-if="isPrintableTab && docTotal > 0">
                            <template #activator="{ props: menu }">
                                <v-btn
                                    v-bind="menu"
                                    :color="docSelected.length > 0 ? 'primary' : undefined"
                                    :variant="docSelected.length > 0 ? 'flat' : 'tonal'"
                                    class="text-none"
                                    prepend-icon="mdi-file-document-multiple"
                                    append-icon="mdi-menu-down"
                                >
                                    {{ docSelected.length > 0
                                        ? t('SearchView.document_table.selected', { count: docSelected.length })
                                        : t('SearchView.document_table.all_results', { count: docTotal }) }}
                                </v-btn>
                            </template>
                            <v-list density="compact">
                                <v-list-subheader>{{ docSelected.length > 0 ? t('SearchView.document_table.scope_selection') : docScopeLabel }}</v-list-subheader>
                                <v-list-item prepend-icon="mdi-printer" :title="t('SearchView.document_actions.print')" @click="docPrint(bulkScope())" />
                                <v-list-item prepend-icon="mdi-file-pdf-box" :title="t('SearchView.document_actions.pdf')" @click="docDownloadPdf(bulkScope())" />
                                <v-list-item prepend-icon="mdi-email" :title="t('SearchView.document_actions.email')" @click="docOpenEmail(bulkScope())" />
                                <v-list-item prepend-icon="mdi-whatsapp" :title="t('SearchView.document_actions.whatsapp')" @click="docOpenWhatsApp(bulkScope())" />
                                <template v-if="docSelected.length > 0">
                                    <v-divider />
                                    <v-list-item prepend-icon="mdi-checkbox-blank-off-outline" :title="t('SearchView.document_table.clear_selection')" @click="docSelected = []" />
                                </template>
                            </v-list>
                        </v-menu>
                    </v-toolbar>

                    <v-data-table-server
                        v-model="docSelected"
                        :headers="docHeaders"
                        :items="docSearchResults"
                        :items-length="docTotal"
                        :items-per-page="docItemsPerPage"
                        :items-per-page-options="[10, 25, 50, 100, { title: t('SearchView.all'), value: -1 }]"
                        :loading="docLoading"
                        :no-data-text="t('SearchView.document_table.text_no_results')"
                        :show-select="isPrintableTab"
                        item-value="id"
                        hover
                        class="zebra-table mt-5"
                        @click:row="onDocRowClick"
                        @update:options="onDocOptionsUpdate"
                    >
                        <template #item.transdate="{ item }">
                            {{ formatDate(item.transdate) }}
                        </template>

                        <template #item.amount="{ item }">
                            <span v-if="item.amount !== null" class="text-no-wrap">
                                {{ formatAmount(item.amount) }}
                            </span>
                        </template>

                        <template #item.doc_status="{ item }">
                            <v-chip
                                :color="item.doc_status === 'open' ? 'warning' : 'success'"
                                variant="tonal"
                                size="small"
                            >
                                {{ item.doc_status === 'open'
                                    ? t('SearchView.document_table.status_open')
                                    : t('SearchView.document_table.status_closed')
                                }}
                            </v-chip>
                        </template>

                        <template #item.itime="{ item }">
                            {{ formatDateTime(item.itime) }}
                        </template>

                        <template #item.actions="{ item }">
                            <div class="d-flex justify-end doc-row-actions">
                                <v-btn
                                    icon="mdi-printer"
                                    variant="text"
                                    size="small"
                                    :title="t('SearchView.document_actions.print')"
                                    @click.stop="docPrint(rowScope(item))"
                                />
                                <v-btn
                                    icon="mdi-file-pdf-box"
                                    variant="text"
                                    size="small"
                                    color="error"
                                    :title="t('SearchView.document_actions.pdf')"
                                    @click.stop="docDownloadPdf(rowScope(item))"
                                />
                                <v-btn
                                    icon="mdi-email"
                                    variant="text"
                                    size="small"
                                    color="info"
                                    :title="t('SearchView.document_actions.email')"
                                    @click.stop="docOpenEmail(rowScope(item))"
                                />
                                <v-btn
                                    icon="mdi-whatsapp"
                                    variant="text"
                                    size="small"
                                    color="green-darken-1"
                                    :title="t('SearchView.document_actions.whatsapp')"
                                    @click.stop="docOpenWhatsApp(rowScope(item))"
                                />
                            </div>
                        </template>
                    </v-data-table-server>
                </v-card-text>
            </v-card>

            <v-alert v-else type="info" class="text-center mt-4">
                {{ t('CustomerVendorSearchView.table.text_no_search_table_shown') }}
            </v-alert>
        </template>

        <!-- Tab: Fahrzeuge -->
        <template v-if="activeTab === 'vehicle'">
            <v-card @keydown.enter.capture="handleVehicleEnterKey">
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="vehicleSearchCriteria.c_ln" :label="t('SearchView.vehicle_fields.license_plate')" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="vehicleSearchCriteria.c_fin" :label="t('SearchView.vehicle_fields.fin')" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="vehicleSearchCriteria.owner_name" :label="t('SearchView.vehicle_fields.owner')" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="vehicleSearchCriteria.hersteller" :label="t('SearchView.vehicle_fields.manufacturer')" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                    </v-row>
                    <v-row dense class="mt-2">
                        <v-col class="d-flex justify-end">
                            <v-btn color="primary" prepend-icon="mdi-magnify" @click="loadVehicleData">
                                {{ t('CustomerVendorSearchView.buttons.search') }}
                            </v-btn>
                            <v-btn
                                v-if="hasVehicleSearchCriteria"
                                color="secondary"
                                variant="outlined"
                                prepend-icon="mdi-close"
                                @click="resetVehicle"
                                class="ml-2"
                            >
                                {{ t('CustomerVendorSearchView.buttons.reset') }}
                            </v-btn>
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card class="mt-4">
                <v-card-text>
                    <v-data-table-server
                        :headers="vehicleHeaders"
                        :items="vehicleSearchResults"
                        :items-length="vehicleTotal"
                        :items-per-page="vehicleItemsPerPage"
                        :items-per-page-options="[10, 25, 50, 100, { title: t('SearchView.all'), value: -1 }]"
                        :loading="vehicleLoading"
                        :no-data-text="t('SearchView.vehicle_fields.no_results')"
                        hover
                        class="zebra-table"
                        @click:row="onVehicleRowClick"
                        @update:options="onVehicleOptionsUpdate"
                    >
                        <template #item.c_hu="{ item }">
                            {{ item.c_hu ? formatDateTime(item.c_hu) : '' }}
                        </template>
                        <template #item.c_it="{ item }">
                            {{ formatDateTime(item.c_it) }}
                        </template>
                    </v-data-table-server>
                </v-card-text>
            </v-card>
        </template>

        <!-- Tab: Artikel -->
        <template v-if="activeTab === 'article'">
            <v-card @keydown.enter.capture="handleArticleEnterKey">
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="articleSearchCriteria.partnumber" :label="t('SearchView.article_fields.partnumber')" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-text-field v-model="articleSearchCriteria.description" :label="t('SearchView.article_fields.description')" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                        <v-col cols="12" sm="6" md="2">
                            <v-select
                                v-model="articleSearchCriteria.part_type"
                                :label="t('SearchView.article_fields.part_type')"
                                :items="articleTypeOptions"
                                variant="outlined"
                                density="compact"
                                hide-details
                                clearable
                            />
                        </v-col>
                        <v-col cols="6" sm="3" md="1.5">
                            <v-text-field v-model="articleSearchCriteria.sellprice_from" :label="t('SearchView.article_fields.sellprice_from')" type="number" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                        <v-col cols="6" sm="3" md="1.5">
                            <v-text-field v-model="articleSearchCriteria.sellprice_to" :label="t('SearchView.article_fields.sellprice_to')" type="number" variant="outlined" density="compact" hide-details clearable />
                        </v-col>
                    </v-row>
                    <v-row dense class="mt-2">
                        <v-col class="d-flex justify-end">
                            <v-btn color="primary" prepend-icon="mdi-magnify" @click="searchArticles">
                                {{ t('CustomerVendorSearchView.buttons.search') }}
                            </v-btn>
                            <v-btn
                                v-if="hasArticleSearchCriteria"
                                color="secondary"
                                variant="outlined"
                                prepend-icon="mdi-close"
                                @click="resetArticle"
                                class="ml-2"
                            >
                                {{ t('CustomerVendorSearchView.buttons.reset') }}
                            </v-btn>
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card class="mt-4">
                <v-card-text>
                    <v-data-table-server
                        :headers="articleHeaders"
                        :items="articleSearchResults"
                        :items-length="articleTotal"
                        :items-per-page="articleItemsPerPage"
                        :items-per-page-options="[10, 25, 50, 100, { title: t('SearchView.all'), value: -1 }]"
                        :loading="articleLoading"
                        :no-data-text="t('SearchView.article_fields.no_results')"
                        hover
                        class="zebra-table"
                        @click:row="onArticleRowClick"
                        @update:options="onArticleOptionsUpdate"
                    >
                        <template #item.part_type="{ item }">
                            {{ item.part_type === 'service' ? t('SearchView.article_fields.part_type_service') : t('SearchView.article_fields.part_type_part') }}
                        </template>
                        <template #item.sellprice="{ item }">
                            <span class="text-no-wrap">{{ formatAmount(item.sellprice) }}</span>
                        </template>
                        <template #item.obsolete="{ item }">
                            <v-chip
                                :color="item.obsolete ? 'error' : 'success'"
                                variant="tonal"
                                size="small"
                            >
                                {{ item.obsolete ? t('SearchView.article_fields.obsolete_yes') : t('SearchView.article_fields.obsolete_no') }}
                            </v-chip>
                        </template>
                        <template #item.itime="{ item }">
                            {{ formatDateTime(item.itime) }}
                        </template>
                    </v-data-table-server>
                </v-card-text>
            </v-card>
        </template>
            </v-col>
        </v-row>
    </v-container>

    <BrevoMarketingMailDialog
        v-if="brevoMarketingMailDialogData"
        :data="brevoMarketingMailDialogData"
        @submit="submitBrevoMarketingMailDialog"
        @close="resetBrevoMarketingMailDialog"
    />

    <SqlHelpDialog
        v-model="showSqlHelpDialog"
        :type-filter="typeFilter"
    />

    <SaveQueryDialog
        v-model="showSaveQueryDialog"
        :query="sqlQuery"
        :type-filter="typeFilter"
        @saved="onQuerySaved"
    />

    <SavedQueriesDialog
        v-model="showSavedQueriesDialog"
        :type-filter="typeFilter"
        @select-query="onSelectQuery"
    />

    <!-- Belegversand aus der Trefferliste -->
    <SendEmailDialog
        v-model="emailDialog.visible"
        :initial-to="emailDialog.to"
        :initial-cc="emailDialog.cc"
        :initial-bcc="emailDialog.bcc"
        :initial-subject="emailDialog.subject"
        :initial-body="emailDialog.body"
        :attachment-name="emailDialog.attachmentName"
        @send="onEmailSend"
        @cancel="emailDialog.visible = false"
    />

    <SendWhatsAppDialog
        v-model="waDialog.visible"
        :initial-phone="waDialog.phone"
        :phone-list="waDialog.phoneList"
        :attachment-name="waDialog.attachmentName"
        :salutation="waDialog.salutation"
        :doc-ref="waDialog.docRef"
        :amount="waDialog.amount"
        :sending="docBusy === 'whatsapp'"
        @send="onWhatsAppSend"
        @cancel="waDialog.visible = false"
    />

    <v-overlay :model-value="!!docBusy" class="align-center justify-center" persistent>
        <v-card class="pa-6 text-center" min-width="260">
            <v-progress-circular indeterminate color="primary" size="48" />
            <div class="mt-4 text-body-1">{{ docBusyText }}</div>
        </v-card>
    </v-overlay>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import * as alerts from '@/core/utils/alerts.js';
import * as toasts from '@/core/utils/toasts.js';
import { formatDate, formatDateTime } from '@/core/utils/dateFormatter.js';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import BrevoMarketingMailDialog from './dialogs/brevo-marketing-mail.dialog.vue';
import SqlHelpDialog from './dialogs/sql-help.dialog.vue';
import SaveQueryDialog from './dialogs/save-query.dialog.vue';
import SavedQueriesDialog from './dialogs/saved-queries.dialog.vue';
import SearchFilters from './components/search-filters.component.vue';
import CustomerVendorSearchForm from './components/customer-vendor-search-form.component.vue';
import ContactsSearchForm from './components/contacts-search-form.component.vue';
import SearchResultsTable from './components/search-results-table.component.vue';
import DocumentSearchForm from './components/document-search-form.component.vue';
import DocumentPeriodNav from './components/document-period-nav.component.vue';
import SendEmailDialog from '@/core/views/faktura/dialogs/send.email.dialog.vue';
import SendWhatsAppDialog from './dialogs/send-whatsapp.dialog.vue';
import router from '@/core/router/index.js';
import { oserpStore } from '@/core/stores/oserp.store.js';
import { useViewHistory } from '@/core/composables/useViewHistory.js';

const { t, locale } = useI18n();
const oserp = oserpStore();
const { fetchCustomerOrVendor } = oserp;
const { saveToHistory } = useViewHistory();

// Props
const props = defineProps({
    message: {
        type: Object,
        default: () => ({ title: '', description: '', type: 'info' })
    },
    messages: {
        type: Array,
        default: () => []
    },
    crmView: {
        type: Boolean,
        default: false
    }
});

// ──────────────────────────────────────────
// Tab-Steuerung
// ──────────────────────────────────────────
const activeTab = ref('customer_vendor');

const documentTypes = ['invoice', 'purchase_invoice', 'quotation', 'order', 'purchase_order', 'delivery_order'];

/**
 * Prüft ob der aktive Tab ein Dokument-Tab ist
 */
const isDocumentTab = computed(() => documentTypes.includes(activeTab.value));

/**
 * Berechnet den Seitentitel basierend auf dem aktiven Tab
 */
const pageTitle = computed(() => {
    if (activeTab.value === 'customer_vendor') {
        if (typeFilter.value === 'customer') {
            return t('CustomerVendorSearchView.title_customer');
        } else if (typeFilter.value === 'vendor') {
            return t('CustomerVendorSearchView.title_vendor');
        } else {
            return t('CustomerVendorSearchView.title_contacts');
        }
    }
    if (activeTab.value === 'vehicle') {
        return t('SearchView.title') + ' — ' + t('SearchView.tabs.vehicle');
    }
    if (activeTab.value === 'article') {
        return t('SearchView.title') + ' — ' + t('SearchView.tabs.article');
    }
    return t('SearchView.title') + ' — ' + t(`SearchView.tabs.${activeTab.value}`);
});

// ──────────────────────────────────────────
// Kunde/Lieferant/Person (bestehendes Verhalten)
// ──────────────────────────────────────────
const loading = ref(false);
const selected = ref([]);
// Kommt man von der Dublettenprüfung zurück, steht der Typ in der URL — so
// landet man wieder bei den Kunden bzw. Lieferanten, nicht beim Standard.
const typeFromRoute = router.currentRoute.value.query.type;
const typeFilter = ref(['customer', 'vendor'].includes(typeFromRoute) ? typeFromRoute : 'customer');

// Dublettenpruefung: fuehrt in die Buchhaltungsansicht des gewaehlten Typs,
// wo doppelte Stammdaten zusammengefuehrt oder geloescht werden koennen.
// `from` merkt sich den Absprung, damit "Zurück" dort wieder hierher führt.
function openDuplicates() {
    router.push({
        name: typeFilter.value === 'vendor' ? 'accounting-vendors' : 'accounting-customers',
        query: { from: 'search' }
    });
}
const useSqlQuery = ref(false);
const showAdditionalCriteriaCV = ref(false);
const sqlQuery = ref('');
const searchCriteria = ref({});
const searchResults = ref([]);
const cvTotal = ref(0);
const cvItemsPerPage = ref(10);
const cvCurrentOptions = ref({ page: 1, itemsPerPage: 10, sortBy: [] });
const hasSearchedCV = ref(false);
const loadedDataType = ref(null);
const brevoMarketingMailDialogData = ref(null);
const showSqlHelpDialog = ref(false);
const showSaveQueryDialog = ref(false);
const showSavedQueriesDialog = ref(false);

/**
 * Initialisiert Standard-Datumsfelder und lädt erste Seite
 */
onMounted(() => {
    const today = new Date().toISOString().split('T')[0];
    searchCriteria.value = {
        itime_to: today,
        mtime_to: today,
        obsolete: false
    };
    loadCVData();
});

/**
 * Setzt Suchkriterien zurück und lädt erste Seite bei Type-Filter-Wechsel
 */
watch(typeFilter, async () => {
    const today = new Date().toISOString().split('T')[0];
    searchResults.value = [];
    cvTotal.value = 0;
    selected.value = [];
    searchCriteria.value = {
        itime_to: today,
        mtime_to: today,
        obsolete: false
    };
    sqlQuery.value = '';
    cvCurrentOptions.value = { page: 1, itemsPerPage: cvItemsPerPage.value, sortBy: [] };
    await loadCVData();
});

const hasSearchCriteria = computed(() => {
    return Object.values(searchCriteria.value).some(value => {
        if (value === null || value === undefined) return false;
        if (value === false || value === 0) return true;
        if (typeof value === 'string') return value.trim() !== '';
        return true;
    });
});

const hasSqlQuery = computed(() => {
    return sqlQuery.value && sqlQuery.value.toString().trim() !== '';
});

// Live-Suche: bei jeder Eingabe nach 300ms automatisch suchen (nicht im SQL-Modus)
let cvSearchTimeout = null;
watch(searchCriteria, () => {
    if (useSqlQuery.value) return;
    if (cvSearchTimeout) clearTimeout(cvSearchTimeout);
    cvSearchTimeout = setTimeout(() => {
        cvCurrentOptions.value.page = 1;
        loadCVData();
    }, 300);
}, { deep: true });

/**
 * Handler für Enter-Taste im Kunden/Lieferanten/Personen-Tab
 */
const handleEnterKey = (event) => {
    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
        event.preventDefault();
        searchCV();
    }
};

/**
 * Startet die Kunden/Lieferanten/Personen-Suche
 */
const searchCV = async () => {
    if (!hasSearchCriteria.value && (!hasSqlQuery.value || !useSqlQuery.value)) {
        alerts.warning(t('CustomerVendorSearchView.alerts.warning_no_search_criteria'))
            .then(async (result) => {
                if (result.isConfirmed) {
                    await loadCVData();
                }
            });
        return;
    }

    await loadCVData();
};

/**
 * Lädt die Kunden/Lieferanten/Personen-Daten
 */
const loadCVData = async () => {
    loading.value = true;

    let where = null;
    if (hasSqlQuery.value && useSqlQuery.value) {
        where = sqlQuery.value;
    } else if (hasSearchCriteria.value && !useSqlQuery.value) {
        where = {};
        for (const [key, value] of Object.entries(searchCriteria.value)) {
            if (value === null || value === undefined) continue;
            if (typeof value === 'string' && value.trim() === '') continue;
            where[key] = value;
        }

        if (typeFilter.value === 'contacts' && 'obsolete' in where) {
            delete where.obsolete;
        }
    }

    const opts = cvCurrentOptions.value;
    const sortBy = opts.sortBy?.[0]?.key || 'id';
    const sortOrder = opts.sortBy?.[0]?.order || 'desc';
    const limit = opts.itemsPerPage;
    const offset = (opts.page - 1) * opts.itemsPerPage;

    const payload = {
        action: 'searchCV',
        type: typeFilter.value,
        where,
        limit,
        offset,
        sortBy,
        sortOrder
    };

    try {
        const response = await axios.post('/api/customer_vendor/', payload);

        if (response.data.sql_error) {
            const errorHtml = `
                <div style="text-align: left; font-size: 13px;">
                    <p style="margin-bottom: 8px;"><strong>${t('CustomerVendorSearchView.errors.sql_state')}:</strong> ${response.data.sql_state}</p>
                    <p style="margin-bottom: 4px;"><strong>${t('CustomerVendorSearchView.errors.error_message')}:</strong></p>
                    <pre style="background: #f5f5f5; padding: 8px; border-radius: 4px; overflow-x: auto; max-height: 200px; font-size: 12px; line-height: 1.4;">${response.data.error_message}</pre>
                    <p style="margin-bottom: 4px; margin-top: 12px;"><strong>${t('CustomerVendorSearchView.errors.your_query')}:</strong></p>
                    <pre style="background: #f5f5f5; padding: 8px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.4;">${response.data.query}</pre>
                </div>
            `;

            alerts.error(
                t('CustomerVendorSearchView.errors.sql_error_title'),
                errorHtml
            );
            searchResults.value = [];
            cvTotal.value = 0;
        } else if (response.data.success) {
            searchResults.value = response.data.payload.search.results ?? [];
            cvTotal.value = response.data.payload.search.total ?? 0;
        } else {
            toasts.error(t('CustomerVendorSearchView.toasts.error_loading_search_results'));
            searchResults.value = [];
            cvTotal.value = 0;
        }
    } catch (error) {
        toasts.error(t('CustomerVendorSearchView.toasts.error_loading_search_results'));
        searchResults.value = [];
        cvTotal.value = 0;
    }

    selected.value = [];
    hasSearchedCV.value = true;
    loadedDataType.value = typeFilter.value;
    loading.value = false;
};

/**
 * Handler für Pagination/Sortierung der CV-Tabelle
 */
const onCVOptionsUpdate = (options) => {
    cvCurrentOptions.value = options;
    cvItemsPerPage.value = options.itemsPerPage;
    if (hasSearchedCV.value) loadCVData();
};

/**
 * Setzt die Kunden/Lieferanten/Personen-Suche zurück
 */
const resetCV = () => {
    const today = new Date().toISOString().split('T')[0];
    searchCriteria.value = {
        itime_to: today,
        mtime_to: today,
        obsolete: false
    };
    sqlQuery.value = '';
    searchResults.value = [];
    cvTotal.value = 0;
    selected.value = [];
    hasSearchedCV.value = false;
    loadedDataType.value = null;
};

const openSqlHelpDialog = () => { showSqlHelpDialog.value = true; };
const openSaveQueryDialog = () => { showSaveQueryDialog.value = true; };
const openSavedQueriesDialog = () => { showSavedQueriesDialog.value = true; };
const onQuerySaved = () => {};
const onSelectQuery = (query) => { sqlQuery.value = query; };

const openBrevoMarketingMailDialog = (id) => {
    brevoMarketingMailDialogData.value = {
        ids: id ? [id] : selected.value,
        type: loadedDataType.value,
    };
};

const submitBrevoMarketingMailDialog = async (data) => {
    resetBrevoMarketingMailDialog();

    try {
        const response = await axios.post('/api/brevo/', {
            action: 'sendMail',
            template: data.template,
            type: data.type,
            ids: data.ids
        });

        if (response.data.success) {
            const text = response.data.payload.sent_count > 1
                ? t('CustomerVendorSearchView.toasts.brevo_marketing_mails_were_prefix')
                : t('CustomerVendorSearchView.toasts.brevo_marketing_mail_was_prefix');
            toasts.success(response.data.payload.sent_count + ' ' + text + ' erfolgreich versendet.');
        } else {
            toasts.error(t('CustomerVendorSearchView.toasts.error_sending_brevo_marketing_mails'));
        }
    } catch (error) {
        toasts.error(t('CustomerVendorSearchView.toasts.error_sending_brevo_marketing_mails'));
    }
};

const resetBrevoMarketingMailDialog = () => {
    brevoMarketingMailDialogData.value = null;
};

/**
 * Handler für Zeilen-Klick im Kunden/Lieferanten/Personen-Tab
 *
 * Bei Kontakten (Personen): item.id = cp_id, item.cp_cv_id = Kunden-/Lieferanten-ID
 * → Navigiert zur CRM-Ansicht des zugehörigen Kunden/Lieferanten mit ?tab=contacts
 */
const onCVRowClick = async (event, row) => {
    const item = row.item;

    if (loadedDataType.value === 'contacts') {
        const cvId = item.cp_cv_id;
        const src = item.cv_type === 'V' ? 'V' : 'C';
        saveToHistory({
            type: src === 'V' ? 'vendor' : 'customer',
            id: cvId,
            title: item.cv_name || item.name || '',
            subtitle: item.cv_number || '',
            route: { name: 'customer-vendor', query: { tab: 'contacts', cpId: item.id } }
        });
        await fetchCustomerOrVendor(cvId, src);
        router.push({
            name: 'customer-vendor',
            query: { tab: 'contacts', cpId: item.id }
        });
    } else {
        saveToHistory({
            type: item.src === 'V' ? 'vendor' : 'customer',
            id: item.id,
            title: item.name || '',
            subtitle: item.cv_number || item.customernumber || item.vendornumber || '',
            route: { name: 'customer-vendor', params: { id: item.id } }
        });
        await fetchCustomerOrVendor(item.id, item.src);
        router.push({
            name: 'customer-vendor',
            params: { id: item.id }
        });
    }
};

// ──────────────────────────────────────────
// Dokument-Suche
// ──────────────────────────────────────────
const docLoading = ref(false);
const docSearchCriteria = ref({});
const docSearchResults = ref([]);
const docTotal = ref(0);
const docItemsPerPage = ref(10);
const docCurrentOptions = ref({ page: 1, itemsPerPage: 10, sortBy: [] });
const hasSearchedDoc = ref(false);

/**
 * Bei Tab-Wechsel: Suchkriterien zurücksetzen und erste Seite laden
 */
watch(activeTab, async (tab) => {
    docSearchCriteria.value = {};
    docSearchResults.value = [];
    docTotal.value = 0;
    docSumAmount.value = null;
    docSelected.value = [];
    docRowsById.clear();
    docCurrentOptions.value = { page: 1, itemsPerPage: docItemsPerPage.value, sortBy: [] };

    if (documentTypes.includes(tab)) {
        applyPeriod();
        await loadDocData();
    } else if (tab === 'vehicle') {
        vehicleSearchCriteria.value = {};
        vehicleSearchResults.value = [];
        vehicleTotal.value = 0;
        vehicleCurrentOptions.value = { page: 1, itemsPerPage: vehicleItemsPerPage.value, sortBy: [] };
        await loadVehicleData();
    } else if (tab === 'article') {
        articleSearchCriteria.value = {};
        articleSearchResults.value = [];
        articleTotal.value = 0;
        articleCurrentOptions.value = { page: 1, itemsPerPage: articleItemsPerPage.value, sortBy: [] };
        await loadArticleData();
    }
});

/**
 * Prüft ob Dokument-Suchkriterien vorhanden sind
 */
const hasDocSearchCriteria = computed(() => {
    return Object.values(docSearchCriteria.value).some(value => {
        if (value === null || value === undefined) return false;
        if (typeof value === 'string') return value.trim() !== '';
        if (typeof value === 'number') return true;
        return true;
    });
});

/**
 * Mapping von Tab-Typ zu Faktura-Routenname
 */
const fakturaRouteMap = {
    invoice: 'faktura-invoice-view',
    purchase_invoice: 'faktura-invoice-view',
    quotation: 'faktura-quotation-view',
    order: 'faktura-order-view',
    purchase_order: 'faktura-order-view',
    delivery_order: 'faktura-delivery-order-view',
};

/**
 * Spaltenüberschriften für die Dokument-Ergebnistabelle
 */
const docHeaders = computed(() => {
    const headers = [
        { title: t('SearchView.document_table.document_number'), key: 'document_number', sortable: true },
        { title: t('SearchView.document_table.cv_name'), key: 'cv_name', sortable: true },
        { title: t('SearchView.document_table.cv_number'), key: 'cv_number', sortable: true },
        { title: t('SearchView.document_table.transdate'), key: 'transdate', sortable: true },
    ];

    // Lieferscheine haben kein Betragsfeld
    if (activeTab.value !== 'delivery_order') {
        headers.push({ title: t('SearchView.document_table.amount'), key: 'amount', sortable: true, align: 'end' });
    }

    headers.push(
        { title: t('SearchView.document_table.status'), key: 'doc_status', sortable: true },
        { title: t('SearchView.document_table.created_at'), key: 'itime', sortable: true },
    );

    if (isPrintableTab.value) {
        headers.push({ title: '', key: 'actions', sortable: false, align: 'end', width: 180 });
    }

    return headers;
});

/**
 * Handler für Enter-Taste in den Dokument-Tabs
 */
const handleDocEnterKey = (event) => {
    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
        event.preventDefault();
        searchDocuments();
    }
};

/**
 * Startet die Dokument-Suche
 */
const searchDocuments = async () => {
    if (!hasDocSearchCriteria.value) {
        alerts.warning(t('CustomerVendorSearchView.alerts.warning_no_search_criteria'))
            .then(async (result) => {
                if (result.isConfirmed) {
                    await loadDocData();
                }
            });
        return;
    }

    await loadDocData();
};

/**
 * Lädt die Dokument-Suchergebnisse vom Backend
 */
const loadDocData = async () => {
    docLoading.value = true;

    const where = {};
    for (const [key, value] of Object.entries(docSearchCriteria.value)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        where[key] = value;
    }

    const opts = docCurrentOptions.value;
    const sortBy = opts.sortBy?.[0]?.key || 'id';
    const sortOrder = opts.sortBy?.[0]?.order || 'desc';
    const limit = opts.itemsPerPage;
    const offset = (opts.page - 1) * opts.itemsPerPage;

    try {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'searchDocuments',
            type: activeTab.value,
            where,
            limit,
            offset,
            sortBy,
            sortOrder
        });

        if (response.data.sql_error) {
            toasts.error(response.data.error_message || t('CustomerVendorSearchView.toasts.error_loading_search_results'));
            docSearchResults.value = [];
            docTotal.value = 0;
        } else if (response.data.success) {
            docSearchResults.value = response.data.payload.results ?? [];
            docTotal.value = response.data.payload.total ?? 0;
            docSumAmount.value = response.data.payload.sum_amount ?? null;
            for (const row of docSearchResults.value) docRowsById.set(row.id, row);
        } else {
            toasts.error(t('CustomerVendorSearchView.toasts.error_loading_search_results'));
            docSearchResults.value = [];
            docTotal.value = 0;
        }
    } catch (error) {
        toasts.error(t('CustomerVendorSearchView.toasts.error_loading_search_results'));
        docSearchResults.value = [];
        docTotal.value = 0;
    }

    hasSearchedDoc.value = true;
    docLoading.value = false;
};

/**
 * Handler für Pagination/Sortierung der Dokument-Tabelle
 */
const onDocOptionsUpdate = (options) => {
    docCurrentOptions.value = options;
    docItemsPerPage.value = options.itemsPerPage;
    if (hasSearchedDoc.value) loadDocData();
};

/**
 * Setzt die Dokument-Suche zurück
 */
const resetDoc = () => {
    docSearchCriteria.value = {};
    docSelected.value = [];
    const monat = aktuellerMonat();
    if (docPeriod.value?.year === monat.year && docPeriod.value?.month === monat.month) {
        applyPeriod();
        loadDocData();
    } else {
        docPeriod.value = monat;
    }
};

// ──────────────────────────────────────────
// Zeitraum (Monat) der Dokument-Suche
// ──────────────────────────────────────────
const heute = new Date();
const aktuellerMonat = () => ({ year: heute.getFullYear(), month: heute.getMonth() + 1 });
const docPeriod = ref(aktuellerMonat());
let docPeriodStill = false; // true: Zeitraum wurde wegen Handänderung der Datumsfelder gelöscht, nicht neu laden

/**
 * Erster und letzter Tag eines Monats als ISO-Datum
 */
function periodRange(period) {
    const mm = String(period.month).padStart(2, '0');
    const lastDay = new Date(period.year, period.month, 0).getDate();
    return { from: `${period.year}-${mm}-01`, to: `${period.year}-${mm}-${String(lastDay).padStart(2, '0')}` };
}

/**
 * Überträgt den gewählten Monat in die Datumsfelder der Suche
 */
function applyPeriod() {
    const criteria = { ...docSearchCriteria.value };
    if (docPeriod.value) {
        const range = periodRange(docPeriod.value);
        criteria.transdate_from = range.from;
        criteria.transdate_to = range.to;
    } else {
        delete criteria.transdate_from;
        delete criteria.transdate_to;
    }
    docSearchCriteria.value = criteria;
}

watch(docPeriod, async () => {
    if (docPeriodStill) {
        docPeriodStill = false;
        return;
    }
    applyPeriod();
    docSelected.value = [];
    docCurrentOptions.value = { ...docCurrentOptions.value, page: 1 };
    await loadDocData();
});

/**
 * "Alle Zeiträume" gewählt, obwohl schon kein Monat aktiv war (Datum von Hand
 * geändert): Datumsfelder leeren und neu laden
 */
async function onPeriodClear() {
    if (docPeriod.value !== null) return;
    applyPeriod();
    docSelected.value = [];
    await loadDocData();
}

// Datumsfelder von Hand geändert → Monatsanzeige passt nicht mehr, auf "Alle" stellen
watch(() => [docSearchCriteria.value.transdate_from, docSearchCriteria.value.transdate_to], ([from, to]) => {
    if (!docPeriod.value) return;
    const range = periodRange(docPeriod.value);
    if (from !== range.from || to !== range.to) {
        docPeriodStill = true;
        docPeriod.value = null;
    }
});

/**
 * Lesbarer Zeitraum, z. B. "September 2026" oder "01.09.2026 – 15.09.2026"
 */
const docPeriodLabel = computed(() => {
    if (docPeriod.value) {
        return new Date(docPeriod.value.year, docPeriod.value.month - 1, 1)
            .toLocaleDateString(locale.value, { month: 'long', year: 'numeric' });
    }
    const from = docSearchCriteria.value.transdate_from;
    const to = docSearchCriteria.value.transdate_to;
    if (from || to) return [from ? formatDate(from) : '', to ? formatDate(to) : ''].join(' – ');
    return t('SearchView.period.all');
});

/**
 * Überschrift der Trefferliste: "Rechnungen September 2026"
 */
const docScopeLabel = computed(() => `${t(`SearchView.tabs.${activeTab.value}`)} ${docPeriodLabel.value}`);

/**
 * Anzahl und Summe der Treffer
 */
const docSummary = computed(() => {
    if (docSumAmount.value === null || docSumAmount.value === undefined) {
        return t('SearchView.document_table.summary_count', { count: docTotal.value }, docTotal.value);
    }
    return t('SearchView.document_table.summary', { count: docTotal.value, sum: formatAmount(docSumAmount.value) }, docTotal.value);
});

// ──────────────────────────────────────────
// Drucken / PDF / Versand aus der Trefferliste
// ──────────────────────────────────────────
const printableTypes = ['invoice', 'quotation', 'order', 'purchase_order', 'delivery_order'];
const isPrintableTab = computed(() => printableTypes.includes(activeTab.value));

const docSelected = ref([]);          // IDs der markierten Belege
const docRowsById = new Map();        // geladene Zeilen, damit die Auswahl auch über Seiten hinweg bekannt bleibt
const docSumAmount = ref(null);
const docBusy = ref(null);            // 'pdf' | 'print' | 'email' | 'whatsapp'
const docBusyText = computed(() => docBusy.value ? t(`SearchView.document_actions.busy_${docBusy.value}`) : '');

const printerList = computed(() => (oserp.session.company_config?.printers || []).filter(p => !p.hide_factura));
const selectedPrinter = ref(null);

onMounted(() => {
    const savedPrinterId = oserp.getConfigValue('default_printer_id');
    if (savedPrinterId) {
        selectedPrinter.value = printerList.value.find(p => String(p.id) === String(savedPrinterId)) || null;
    }
    if (!selectedPrinter.value && printerList.value.length === 1) {
        selectedPrinter.value = printerList.value[0];
    }
});

function selectPrinter(printer) {
    selectedPrinter.value = printer;
    oserp.setConfigValue('default_printer_id', String(printer.id));
    toasts.success(t('SearchView.document_actions.printer_selected', { name: printer.printer_description }));
}

/**
 * Dateinamen-tauglich machen: unzulässige Zeichen raus, Leerzeichen → Bindestrich
 */
const sanitizeFilename = (s) => String(s)
    .replace(/[\\/:*?"<>|]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');

const singularLabel = (printType) => t(`SearchView.doc_singular.${printType || activeTab.value}`);
const pluralLabel = () => t(`SearchView.tabs.${activeTab.value}`);

/**
 * Ein einzelner Beleg aus der Trefferliste
 */
function rowScope(item) {
    const printType = item.print_type || activeTab.value;
    const number = item.document_number || item.id;
    let filename = [sanitizeFilename(singularLabel(printType)), sanitizeFilename(number)].filter(Boolean).join('-');
    const name = sanitizeFilename(item.cv_name || '');
    if (name) filename += '--' + name;
    return {
        kind: 'single',
        rows: [item],
        payload: { documents: [{ id: item.id, fakturaType: printType }] },
        label: `${singularLabel(printType)} ${number}`,
        filename: filename + '.pdf'
    };
}

/**
 * Markierte Belege oder — ohne Markierung — alle Treffer des aktuellen Filters
 */
function bulkScope() {
    const plural = pluralLabel();
    if (docSelected.value.length > 0) {
        const rows = docSelected.value.map(id => docRowsById.get(id) || { id, print_type: activeTab.value });
        return {
            kind: 'selection',
            rows,
            payload: { documents: rows.map(r => ({ id: r.id, fakturaType: r.print_type || activeTab.value })) },
            label: `${plural} ${t('SearchView.document_table.scope_selection')}`,
            filename: `${sanitizeFilename(plural)}-${sanitizeFilename(t('SearchView.document_table.scope_selection'))}.pdf`
        };
    }
    let suffix;
    if (docPeriod.value) {
        suffix = `${docPeriod.value.year}-${String(docPeriod.value.month).padStart(2, '0')}`;
    } else {
        const from = docSearchCriteria.value.transdate_from;
        const to = docSearchCriteria.value.transdate_to;
        suffix = (from || to) ? [from, to].filter(Boolean).join('_') : sanitizeFilename(t('SearchView.period.all'));
    }
    return {
        kind: 'all',
        rows: [],
        payload: { type: activeTab.value, where: currentDocWhere() },
        label: docScopeLabel.value,
        filename: `${sanitizeFilename(plural)}-${suffix}.pdf`
    };
}

/**
 * Aktive Filter der Dokument-Suche (gleiches Format wie beim Laden)
 */
function currentDocWhere() {
    const where = {};
    for (const [key, value] of Object.entries(docSearchCriteria.value)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        where[key] = value;
    }
    return where;
}

/**
 * Sammel-PDF vom Backend holen — als Blob (Download) oder Base64 (Anhang)
 */
async function fetchBatchPdf(scope, asBase64 = false) {
    const body = {
        action: 'generateBatchPdf',
        ...scope.payload,
        printerId: selectedPrinter.value?.id ?? null,
        filename: scope.filename
    };
    if (asBase64) {
        const response = await axios.post('/api/print/', body);
        if (!response.data.success) {
            throw new Error(response.data.text || 'PDF error');
        }
        return response.data.payload.pdf;
    }
    const response = await axios.post('/api/print/', { ...body, 'content-type': 'application/pdf' }, { responseType: 'blob' });
    if (response.data.type === 'application/json') {
        const errData = JSON.parse(await response.data.text());
        throw new Error(errData.text || 'PDF error');
    }
    return response.data;
}

/**
 * PDF herunterladen (einzeln, Auswahl oder ganzer Zeitraum)
 */
async function docDownloadPdf(scope) {
    docBusy.value = 'pdf';
    let blobUrl = null;
    try {
        const blob = await fetchBatchPdf(scope);
        blobUrl = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = scope.filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    } catch (e) {
        console.error('Fehler beim Erstellen der PDF:', e);
        alerts.error(t('SearchView.document_actions.pdf_error'));
    } finally {
        if (blobUrl) setTimeout(() => URL.revokeObjectURL(blobUrl), 10000);
        docBusy.value = null;
    }
}

/**
 * Als ein Druckauftrag an den gewählten Drucker senden
 */
async function docPrint(scope) {
    if (!selectedPrinter.value) {
        alerts.warning(t('SearchView.document_actions.select_printer_first'));
        return;
    }
    docBusy.value = 'print';
    try {
        const response = await axios.post('/api/print/', {
            action: 'printBatchToPrinter',
            ...scope.payload,
            printerId: selectedPrinter.value.id
        });
        if (response.data.success) {
            const payload = response.data.payload || {};
            const failed = (payload.errors || []).length;
            if (failed > 0) {
                toasts.warning(t('SearchView.document_actions.printed_partial', { count: payload.count, failed }));
            } else {
                toasts.success(t('SearchView.document_actions.printed', { count: payload.count, name: payload.printer }, payload.count));
            }
            docSelected.value = [];
        } else {
            alerts.error(response.data.text || t('SearchView.document_actions.print_error'));
        }
    } catch (e) {
        console.error('Fehler beim Drucken:', e);
        alerts.error(t('SearchView.document_actions.print_error'));
    } finally {
        docBusy.value = null;
    }
}

// ===== E-Mail =====
const emailDialog = ref({ visible: false, to: '', cc: '', bcc: '', subject: '', body: '', attachmentName: '', scope: null });

const emailSenderFieldMap = {
    invoice: 'email_sender_invoice',
    credit_note: 'email_sender_invoice',
    quotation: 'email_sender_sales_quotation',
    order: 'email_sender_sales_order',
    delivery_order: 'email_sender_sales_delivery_order',
    purchase_order: 'email_sender_purchase_order'
};
const emailRecordTypeMap = {
    invoice: 'invoice',
    credit_note: 'invoice',
    quotation: 'sales_quotation',
    order: 'sales_order',
    delivery_order: 'sales_delivery_order',
    purchase_order: 'purchase_order'
};

/**
 * E-Mail-Dialog öffnen: Einzelbeleg an den Kunden, Sammel-PDF an den zuletzt genutzten Empfänger
 */
function docOpenEmail(scope) {
    const row = scope.kind === 'single' ? scope.rows[0] : null;
    const plural = pluralLabel();
    emailDialog.value = {
        visible: true,
        scope,
        attachmentName: scope.filename,
        to: row ? (row.cv_email || '') : (oserp.getConfigValue('search_batch_email_to') || ''),
        cc: row ? (row.cv_cc || '') : '',
        bcc: row ? (row.cv_bcc || '') : '',
        subject: row
            ? t('SearchView.send.email_subject_single', { type: singularLabel(row.print_type), number: row.document_number || row.id })
            : t('SearchView.send.email_subject_batch', { type: plural, period: docPeriodLabel.value }),
        body: row
            ? t('SearchView.send.email_body_single', { type: singularLabel(row.print_type), number: row.document_number || row.id })
            : t('SearchView.send.email_body_batch', { type: plural, period: docPeriodLabel.value, count: scope.kind === 'selection' ? scope.rows.length : docTotal.value })
    };
}

async function onEmailSend(emailData) {
    const scope = emailDialog.value.scope;
    docBusy.value = 'email';
    try {
        const pdfBase64 = await fetchBatchPdf(scope, true);
        const defaults = oserp.session.company_config?.defaults || {};
        const printType = scope.kind === 'single' ? (scope.rows[0].print_type || activeTab.value) : activeTab.value;
        const splitAddresses = (value) => (value || '').split(/[,;]/)
            .map(e => ({ email: e.trim(), name: '' }))
            .filter(e => e.email);

        const response = await axios.post('/api/email/', {
            action: 'sendEmail',
            from_name: defaults[emailSenderFieldMap[printType] || ''] || '',
            to: splitAddresses(emailData.to),
            cc: splitAddresses(emailData.cc),
            bcc: splitAddresses(emailData.bcc),
            subject: emailData.subject,
            body_text: emailData.body,
            body_html: '',
            record_type: scope.kind === 'single' ? (emailRecordTypeMap[printType] || null) : null,
            attachments: [{
                filename: scope.filename,
                content_base64: pdfBase64,
                content_type: 'application/pdf'
            }]
        });

        if (response.data.success) {
            toasts.success(t('SearchView.document_actions.email_sent'));
            if (scope.kind !== 'single') oserp.setConfigValue('search_batch_email_to', emailData.to);
            emailDialog.value.visible = false;
        } else {
            alerts.error(response.data.text || t('SearchView.document_actions.email_error'));
        }
    } catch (e) {
        console.error('Fehler beim E-Mail-Versand:', e);
        alerts.error(t('SearchView.document_actions.email_error'));
    } finally {
        docBusy.value = null;
        emailDialog.value.visible = false;
    }
}

// ===== WhatsApp =====
const waDialog = ref({ visible: false, phone: '', phoneList: [], attachmentName: '', salutation: '', docRef: '', amount: '', scope: null });

/**
 * WhatsApp-Dialog öffnen: Handynummer des Kunden bzw. zuletzt genutzte Nummer beim Sammelversand
 */
function docOpenWhatsApp(scope) {
    const row = scope.kind === 'single' ? scope.rows[0] : null;
    let phoneList = [];
    if (row?.cv_phone_numbers) {
        try {
            phoneList = typeof row.cv_phone_numbers === 'string' ? JSON.parse(row.cv_phone_numbers) : row.cv_phone_numbers;
        } catch {
            phoneList = [];
        }
    }
    const companyName = oserp.session.company_config?.defaults?.company || '';
    const amount = row ? row.amount : docSumAmount.value;
    waDialog.value = {
        visible: true,
        scope,
        phone: row ? (row.cv_phone || '') : (oserp.getConfigValue('search_batch_whatsapp_to') || ''),
        phoneList: Array.isArray(phoneList) ? phoneList : [],
        attachmentName: (companyName ? sanitizeFilename(companyName) + '-' : '') + scope.filename,
        salutation: row ? [row.cv_greeting, row.cv_name].filter(Boolean).join(' ') : '',
        docRef: row
            ? t(`SearchView.send.doc_ref.${row.print_type || activeTab.value}`, { number: row.document_number || row.id })
            : scope.label,
        amount: amount !== null && amount !== undefined ? formatAmount(amount) : ''
    };
}

async function onWhatsAppSend(waData) {
    const scope = waDialog.value.scope;
    docBusy.value = 'whatsapp';
    try {
        const pdfBase64 = await fetchBatchPdf(scope, true);
        const row = scope.kind === 'single' ? scope.rows[0] : null;
        const response = await axios.post('/api/whatsapp/', {
            action: 'sendWhatsAppDocument',
            to: waData.phone,
            customer_id: row && row.cv_src === 'C' ? (row.cv_id || 0) : 0,
            document_base64: pdfBase64,
            filename: waDialog.value.attachmentName,
            template_id: waData.templateId,
            parameters: waData.parameters
        });
        if (response.data.success) {
            toasts.success(t('SearchView.document_actions.whatsapp_sent'));
            if (scope.kind !== 'single') oserp.setConfigValue('search_batch_whatsapp_to', waData.phone);
            waDialog.value.visible = false;
        } else {
            alerts.error(response.data.text || t('SearchView.document_actions.whatsapp_error'));
        }
    } catch (e) {
        console.error('Fehler beim WhatsApp-Versand:', e);
        alerts.error(t('SearchView.document_actions.whatsapp_error'));
    } finally {
        docBusy.value = null;
        waDialog.value.visible = false;
    }
}


/**
 * Formatiert einen Betrag als EUR-Währung
 */
function formatAmount(value) {
    if (value === null || value === undefined) return '';
    return Number(value).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
}

/**
 * Handler für Zeilen-Klick in der Dokument-Ergebnistabelle
 * Lädt den Kunden/Lieferanten in den Store und navigiert zum Dokument
 *
 * @param {Event} event - Das Klick-Event
 * @param {Object} row - Die angeklickte Zeile
 */
const docTypeMap = {
    invoice: 'invoice',
    purchase_invoice: 'invoice',
    quotation: 'quotation',
    order: 'order',
    purchase_order: 'order',
    delivery_order: 'order',
};

const onDocRowClick = async (event, row) => {
    if (event?.target?.closest?.('.v-selection-control, .doc-row-actions')) return;
    const item = row.item;

    // Zur Faktura-Ansicht navigieren
    const routeName = fakturaRouteMap[activeTab.value];
    if (routeName) {
        saveToHistory({
            type: docTypeMap[activeTab.value] || activeTab.value,
            id: item.id,
            title: item.document_number || '',
            subtitle: item.cv_name || '',
            route: { name: routeName, params: { id: item.id } }
        });
    }

    // Kunden/Lieferanten im Store laden
    if (item.cv_id) {
        await fetchCustomerOrVendor(item.cv_id, item.cv_src);
    }

    if (routeName) {
        router.push({
            name: routeName,
            params: { id: item.id }
        });
    }
};

// ──────────────────────────────────────────
// Fahrzeug-Suche (nur bei LxCars)
// ──────────────────────────────────────────
const vehicleLoading = ref(false);
const vehicleSearchCriteria = ref({});
const vehicleSearchResults = ref([]);
const vehicleTotal = ref(0);
const vehicleItemsPerPage = ref(10);
const vehicleCurrentOptions = ref({ page: 1, itemsPerPage: 10, sortBy: [] });

const vehicleHeaders = computed(() => [
    { title: t('SearchView.vehicle_fields.license_plate'), key: 'c_ln', sortable: true },
    { title: t('SearchView.vehicle_fields.fin'), key: 'c_fin', sortable: true },
    { title: t('SearchView.vehicle_fields.manufacturer'), key: 'hersteller', sortable: true },
    { title: t('SearchView.vehicle_fields.model'), key: 'modell', sortable: true },
    { title: t('SearchView.vehicle_fields.owner'), key: 'owner_name', sortable: true },
    { title: t('SearchView.vehicle_fields.hu'), key: 'c_hu', sortable: true },
    { title: t('SearchView.vehicle_fields.created_at'), key: 'c_it', sortable: true },
]);

const hasVehicleSearchCriteria = computed(() => {
    return Object.values(vehicleSearchCriteria.value).some(v => v && String(v).trim() !== '');
});

// Live-Suche: bei jeder Eingabe nach 300ms automatisch suchen
let vehicleSearchTimeout = null;
watch(vehicleSearchCriteria, () => {
    if (vehicleSearchTimeout) clearTimeout(vehicleSearchTimeout);
    vehicleSearchTimeout = setTimeout(() => {
        vehicleCurrentOptions.value.page = 1;
        loadVehicleData();
    }, 300);
}, { deep: true });

const handleVehicleEnterKey = (event) => {
    if (event.target?.tagName === 'INPUT') {
        event.preventDefault();
        if (vehicleSearchTimeout) clearTimeout(vehicleSearchTimeout);
        loadVehicleData();
    }
};

const loadVehicleData = async () => {
    vehicleLoading.value = true;

    const where = {};
    for (const [key, value] of Object.entries(vehicleSearchCriteria.value)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        where[key] = value;
    }

    const opts = vehicleCurrentOptions.value;
    const sortBy = opts.sortBy?.[0]?.key || 'c_id';
    const sortOrder = opts.sortBy?.[0]?.order || 'desc';
    const limit = opts.itemsPerPage;
    const offset = (opts.page - 1) * opts.itemsPerPage;

    try {
        const response = await axios.post('/api/customer_vendor/', {
            action: 'searchVehicles',
            where,
            limit,
            offset,
            sortBy,
            sortOrder
        });

        if (response.data.success) {
            vehicleSearchResults.value = response.data.payload.results ?? [];
            vehicleTotal.value = response.data.payload.total ?? 0;
        } else {
            vehicleSearchResults.value = [];
            vehicleTotal.value = 0;
        }
    } catch {
        vehicleSearchResults.value = [];
        vehicleTotal.value = 0;
    }

    vehicleLoading.value = false;
};

const onVehicleOptionsUpdate = (options) => {
    vehicleCurrentOptions.value = options;
    vehicleItemsPerPage.value = options.itemsPerPage;
    loadVehicleData();
};

const resetVehicle = () => {
    vehicleSearchCriteria.value = {};
    vehicleSearchResults.value = [];
    vehicleTotal.value = 0;
    vehicleCurrentOptions.value = { page: 1, itemsPerPage: vehicleItemsPerPage.value, sortBy: [] };
    loadVehicleData();
};

const onVehicleRowClick = (event, row) => {
    const item = row.item;
    saveToHistory({
        type: 'vehicle',
        id: item.c_id,
        title: item.c_ln || '',
        subtitle: [item.hersteller, item.modell, item.owner_name].filter(Boolean).join(' · '),
        route: { name: 'car', params: { id: item.c_id } }
    });
    router.push({ name: 'car', params: { id: item.c_id } });
};

// ──────────────────────────────────────────
// Artikel-Suche
// ──────────────────────────────────────────
const articleLoading = ref(false);
const articleSearchCriteria = ref({});
const articleSearchResults = ref([]);
const articleTotal = ref(0);
const articleItemsPerPage = ref(10);
const articleCurrentOptions = ref({ page: 1, itemsPerPage: 10, sortBy: [] });

const articleHeaders = computed(() => [
    { title: t('SearchView.article_fields.partnumber'), key: 'partnumber', sortable: true },
    { title: t('SearchView.article_fields.description'), key: 'description', sortable: true },
    { title: t('SearchView.article_fields.part_type'), key: 'part_type', sortable: true },
    { title: t('SearchView.article_fields.unit'), key: 'unit', sortable: true },
    { title: t('SearchView.article_fields.sellprice'), key: 'sellprice', sortable: true, align: 'end' },
    { title: t('SearchView.article_fields.obsolete'), key: 'obsolete', sortable: true },
    { title: t('SearchView.article_fields.created_at'), key: 'itime', sortable: true },
]);

const articleTypeOptions = computed(() => [
    { title: t('SearchView.article_fields.part_type_part'), value: 'part' },
    { title: t('SearchView.article_fields.part_type_service'), value: 'service' },
]);

const hasArticleSearchCriteria = computed(() => {
    return Object.values(articleSearchCriteria.value).some(v => v !== null && v !== undefined && String(v).trim() !== '');
});

// Live-Suche: bei jeder Eingabe nach 300ms automatisch suchen
let articleSearchTimeout = null;
watch(articleSearchCriteria, () => {
    if (articleSearchTimeout) clearTimeout(articleSearchTimeout);
    articleSearchTimeout = setTimeout(() => {
        articleCurrentOptions.value.page = 1;
        loadArticleData();
    }, 300);
}, { deep: true });

const handleArticleEnterKey = (event) => {
    if (event.target?.tagName === 'INPUT') {
        event.preventDefault();
        if (articleSearchTimeout) clearTimeout(articleSearchTimeout);
        loadArticleData();
    }
};

/**
 * Startet die Artikelsuche
 */
const searchArticles = async () => {
    if (!hasArticleSearchCriteria.value) {
        alerts.warning(t('CustomerVendorSearchView.alerts.warning_no_search_criteria'))
            .then(async (result) => {
                if (result.isConfirmed) {
                    await loadArticleData();
                }
            });
        return;
    }

    await loadArticleData();
};

/**
 * Lädt Artikel-Suchergebnisse vom Backend
 */
const loadArticleData = async () => {
    articleLoading.value = true;

    const where = {};
    for (const [key, value] of Object.entries(articleSearchCriteria.value)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        where[key] = value;
    }

    const opts = articleCurrentOptions.value;
    const sortBy = opts.sortBy?.[0]?.key || 'partnumber';
    const sortOrder = opts.sortBy?.[0]?.order || 'asc';
    const limit = opts.itemsPerPage;
    const offset = (opts.page - 1) * opts.itemsPerPage;

    try {
        const response = await axios.post('/api/parts/', {
            action: 'searchParts',
            where,
            limit,
            offset,
            sortBy,
            sortOrder
        });

        if (response.data.success) {
            articleSearchResults.value = response.data.payload.results ?? [];
            articleTotal.value = response.data.payload.total ?? 0;
        } else {
            articleSearchResults.value = [];
            articleTotal.value = 0;
        }
    } catch {
        articleSearchResults.value = [];
        articleTotal.value = 0;
    }

    articleLoading.value = false;
};

const onArticleOptionsUpdate = (options) => {
    articleCurrentOptions.value = options;
    articleItemsPerPage.value = options.itemsPerPage;
    loadArticleData();
};

const resetArticle = () => {
    articleSearchCriteria.value = {};
    articleSearchResults.value = [];
    articleTotal.value = 0;
    articleCurrentOptions.value = { page: 1, itemsPerPage: articleItemsPerPage.value, sortBy: [] };
    loadArticleData();
};

const onArticleRowClick = (event, row) => {
    const item = row.item;
    saveToHistory({
        type: 'article',
        id: item.id,
        title: [item.partnumber, item.description].filter(Boolean).join(' — '),
        subtitle: '',
        route: { name: 'article-edit', params: { id: item.id } }
    });
    router.push({ name: 'article-edit', params: { id: item.id } });
};
</script>
