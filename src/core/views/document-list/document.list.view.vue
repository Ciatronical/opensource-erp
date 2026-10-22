<!-- src/core/views/document-list/document.list.view.vue -->
<!-- Belegliste fuer /rechnung, /gutschrift, /auftrag, /angebot, /lieferschein
     und /artikel. Welcher Typ gezeigt wird, steht in der Route (meta.listType) —
     so teilen sich alle Listen eine Ansicht und bleiben in Bedienung und Optik
     identisch. Klick auf eine Zeile oeffnet den Beleg bzw. den Artikel.

     Belege zeigen Netto, USt und Brutto (Rechnungen zusätzlich den offenen
     Betrag) und die Summen über alle Treffer des Filters. Die Liste lässt sich
     drucken, als PDF/CSV/Excel exportieren und per E-Mail versenden; markierte
     Belege (oder alle Treffer) gehen als Sammel-PDF zum Drucker, als Download
     oder per E-Mail — mit denselben Druckvorlagen wie der Einzelbeleg. -->
<template>
    <NavbarView />

    <v-container class="pt-2 px-2 px-sm-4" fluid>
        <v-row class="mb-2 align-center">
            <v-col cols="12" md="auto">
                <h1 class="text-h5 text-sm-h4 d-flex align-center">
                    <v-icon class="me-2" color="primary">{{ config.icon }}</v-icon>
                    {{ t(config.title) }}
                    <v-chip v-if="!loading" class="ms-3" size="small" variant="tonal" color="primary">
                        {{ isParts ? rows.length + (atLimit ? '+' : '') : totals.count }}
                    </v-chip>
                </h1>
            </v-col>
            <v-spacer />
            <v-col cols="12" md="auto" class="d-flex flex-wrap ga-2 justify-md-end align-center">
                <!-- In der Artikelansicht steht er zwischen Suchfeld und Filtern -->
                <v-btn v-if="hasFilter && !isParts" variant="text" size="small" prepend-icon="mdi-filter-remove" @click="reset">
                    {{ t('DocumentList.reset') }}
                </v-btn>
                <v-btn
                    v-if="config.create"
                    color="primary"
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-plus"
                    :to="{ name: config.create.route }"
                >
                    {{ t(config.create.label) }}
                </v-btn>

                <!-- Liste: drucken, exportieren, versenden — immer alle Treffer des Filters -->
                <template v-if="!isParts">
                    <v-btn
                        variant="tonal"
                        size="small"
                        class="text-none"
                        prepend-icon="mdi-printer-outline"
                        :loading="busy === 'list-print'"
                        :disabled="!totals.count || !!busy"
                        @click="printList"
                    >
                        {{ t('DocumentList.actions.print') }}
                    </v-btn>
                    <v-menu>
                        <template #activator="{ props: menu }">
                            <v-btn
                                v-bind="menu"
                                variant="tonal"
                                size="small"
                                class="text-none"
                                prepend-icon="mdi-download-outline"
                                append-icon="mdi-menu-down"
                                :loading="busy === 'export'"
                                :disabled="!totals.count || !!busy"
                            >
                                {{ t('DocumentList.actions.export') }}
                            </v-btn>
                        </template>
                        <v-list density="compact">
                            <v-list-subheader>{{ t('DocumentList.actions.exportScope', { count: totals.count }) }}</v-list-subheader>
                            <v-list-item prepend-icon="mdi-file-pdf-box" :title="t('DocumentList.actions.exportPdf')" :subtitle="t('DocumentList.actions.exportPdfHint')" @click="downloadList('pdf')" />
                            <v-list-item prepend-icon="mdi-microsoft-excel" :title="t('DocumentList.actions.exportXlsx')" :subtitle="t('DocumentList.actions.exportXlsxHint')" @click="downloadList('xlsx')" />
                            <v-list-item prepend-icon="mdi-file-delimited-outline" :title="t('DocumentList.actions.exportCsv')" :subtitle="t('DocumentList.actions.exportCsvHint')" @click="downloadList('csv')" />
                        </v-list>
                    </v-menu>
                    <v-btn
                        variant="tonal"
                        color="primary"
                        size="small"
                        class="text-none"
                        prepend-icon="mdi-email-send-outline"
                        :disabled="!totals.count || !!busy"
                        @click="openSendDialog('list')"
                    >
                        {{ t('DocumentList.actions.email') }}
                    </v-btn>
                </template>
            </v-col>
        </v-row>

        <!-- Filter -->
        <v-row dense class="mb-3">
            <v-col cols="12" :md="isParts ? 8 : 4">
                <v-text-field
                    v-model="search"
                    :label="t(isParts ? 'DocumentList.searchParts' : 'DocumentList.search')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    autofocus
                />
            </v-col>
            <v-col v-if="isParts && hasFilter" cols="auto" class="d-flex align-center">
                <v-btn variant="text" size="small" prepend-icon="mdi-filter-remove" @click="reset">
                    {{ t('DocumentList.reset') }}
                </v-btn>
            </v-col>
            <template v-if="!isParts">
                <!-- Schnellwahl setzt von/bis; Handeingabe schaltet auf „Benutzerdefiniert“ -->
                <v-col cols="12" sm="4" md="2">
                    <v-select
                        v-model="periodPreset"
                        :items="periodItems"
                        :label="t('DocumentList.period.label')"
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-text-field
                        v-model="from"
                        :label="t('DocumentList.from')"
                        type="date"
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="4" md="2">
                    <v-text-field
                        v-model="to"
                        :label="t('DocumentList.to')"
                        type="date"
                        variant="outlined"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="12" md="2" class="d-flex align-center">
                    <v-btn-toggle v-model="status" mandatory density="compact" variant="outlined" rounded="lg" divided class="w-100">
                        <v-btn value="all" size="small" class="flex-grow-1">{{ t('DocumentList.statusAll') }}</v-btn>
                        <v-btn value="open" size="small" class="flex-grow-1" color="warning">{{ t('DocumentList.open') }}</v-btn>
                        <v-btn value="closed" size="small" class="flex-grow-1" color="success">{{ t('DocumentList.closed') }}</v-btn>
                    </v-btn-toggle>
                </v-col>
            </template>
            <!-- Zwei Schalter, ein Zustand (partsScope): es ist immer nur
                 einer an; beide aus heißt „nur aktive Artikel“ -->
            <v-col v-else cols="12" md="6" class="d-flex align-center flex-wrap mt-2">
                <v-switch
                    v-model="obsoleteOnly"
                    :label="t('DocumentList.obsoleteOnly')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <v-switch
                    v-model="showAll"
                    :label="t('DocumentList.showAll')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <!-- Nur mit Shop-Erweiterung; ein Zustand (shopFilter) für beide,
                     keiner zusammen mit „Alle anzeigen“ -->
                <v-switch
                    v-if="shopEnabled"
                    v-model="notInShop"
                    :label="t('DocumentList.notInShop')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <v-switch
                    v-if="shopEnabled"
                    v-model="shopOnly"
                    :label="t('DocumentList.shopOnly')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <!-- Waren ohne Gewicht zum Nachpflegen (Versandkosten im Shop) -->
                <v-switch
                    v-if="shopEnabled"
                    v-model="weightMissing"
                    :label="t('DocumentList.weightMissing')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
                <!-- Im HugoShop angeboten, aber keine Versandart passt — wird nicht veröffentlicht -->
                <v-switch
                    v-if="shopEnabled"
                    v-model="shippingUnfit"
                    :label="t('DocumentList.shippingUnfit')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="ms-1 me-4"
                />
            </v-col>
            <!-- Verfeinert „Nur im Shop angebotene“ auf einen Kanal — deshalb
                 nur sichtbar, solange dieser Filter an ist -->
            <v-col v-if="isParts && shopOnly && channels.length" cols="12" md="3" class="mt-2">
                <v-select
                    v-model="channelId"
                    :items="channelItems"
                    :label="t('DocumentList.channel')"
                    variant="outlined"
                    density="compact"
                    hide-details
                />
            </v-col>
        </v-row>

        <!-- Summen über alle Treffer des Filters — unabhängig vom Limit der Tabelle -->
        <v-sheet v-if="!isParts && hasAmounts" rounded="lg" border class="summary-bar mb-3">
            <div class="summary-item">
                <span class="summary-label">{{ t('DocumentList.totals.count') }}</span>
                <span class="summary-value">{{ totals.count }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">{{ t('DocumentList.columns.net') }}</span>
                <span class="summary-value">{{ formatCurrency(totals.netamount) }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">{{ t('DocumentList.columns.tax') }}</span>
                <span class="summary-value">{{ formatCurrency(totals.tax) }}</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">{{ t('DocumentList.columns.gross') }}</span>
                <span class="summary-value font-weight-bold">{{ formatCurrency(totals.amount) }}</span>
            </div>
            <div v-if="hasPayments" class="summary-item">
                <span class="summary-label">{{ t('DocumentList.columns.open') }}</span>
                <span class="summary-value" :class="totals.open > 0.005 ? 'text-warning' : ''">{{ formatCurrency(totals.open) }}</span>
            </div>
        </v-sheet>

        <v-alert v-if="error" type="warning" variant="tonal" density="compact" class="mb-2">
            {{ error }}
        </v-alert>

        <!-- Belegaktionen: Auswahl oder alle Treffer; rechts der Drucker -->
        <div v-if="!isParts" class="d-flex flex-wrap align-center ga-2 mb-2">
            <v-menu>
                <template #activator="{ props: menu }">
                    <v-btn
                        v-bind="menu"
                        :color="selected.length ? 'primary' : undefined"
                        :variant="selected.length ? 'flat' : 'tonal'"
                        size="small"
                        class="text-none"
                        prepend-icon="mdi-file-document-multiple-outline"
                        append-icon="mdi-menu-down"
                        :loading="busy === 'docs-pdf' || busy === 'docs-print'"
                        :disabled="!rows.length || !!busy"
                    >
                        {{ selected.length
                            ? t('DocumentList.docs.selected', { count: selected.length })
                            : t('DocumentList.docs.all', { count: rows.length }) }}
                    </v-btn>
                </template>
                <v-list density="compact">
                    <v-list-subheader>{{ selected.length ? t('DocumentList.docs.scopeSelection') : t('DocumentList.docs.scopeAll') }}</v-list-subheader>
                    <v-list-item prepend-icon="mdi-printer-outline" :title="t('DocumentList.docs.print')" :subtitle="printerSubtitle" @click="printDocs(docsScope())" />
                    <v-list-item prepend-icon="mdi-file-pdf-box" :title="t('DocumentList.docs.pdf')" @click="downloadDocs(docsScope())" />
                    <v-list-item prepend-icon="mdi-email-outline" :title="t('DocumentList.docs.email')" @click="openSendDialog('docs')" />
                    <template v-if="selected.length">
                        <v-divider />
                        <v-list-item prepend-icon="mdi-checkbox-blank-off-outline" :title="t('DocumentList.docs.clearSelection')" @click="selected = []" />
                    </template>
                </v-list>
            </v-menu>
            <span v-if="atLimit" class="text-caption text-medium-emphasis">
                {{ t('DocumentList.limitHint', { limit: LIMIT }) }}
            </span>
            <v-spacer />
            <v-menu v-if="printerList.length">
                <template #activator="{ props: menu }">
                    <v-btn v-bind="menu" variant="text" size="small" class="text-none" prepend-icon="mdi-printer-settings">
                        {{ selectedPrinter ? selectedPrinter.printer_description : t('DocumentList.docs.selectPrinter') }}
                    </v-btn>
                </template>
                <v-list density="compact">
                    <v-list-item
                        v-for="printer in printerList"
                        :key="printer.id"
                        prepend-icon="mdi-printer"
                        :active="selectedPrinter?.id === printer.id"
                        :title="printer.printer_description"
                        @click="selectPrinter(printer)"
                    />
                </v-list>
            </v-menu>
        </div>

        <v-data-table
            v-model="selected"
            :headers="headers"
            :items="rows"
            :loading="loading"
            :items-per-page="50"
            :show-select="!isParts"
            item-value="id"
            density="compact"
            hover
            class="zebra-table"
            @click:row="openRow"
        >
            <template #item.transdate="{ item }">
                <span class="text-no-wrap">{{ formatDate(item.transdate, locale) }}</span>
            </template>
            <template #item.customer_name="{ item }">
                <div class="text-truncate" style="max-width: 16rem" :title="item.customer_name">{{ item.customer_name }}</div>
            </template>
            <template #item.description="{ item }">
                <div class="text-truncate" style="max-width: 18rem" :title="item.description">{{ item.description }}</div>
            </template>
            <template #item.netamount="{ item }">
                <span class="text-no-wrap">{{ formatCurrency(item.netamount) }}</span>
            </template>
            <template #item.tax="{ item }">
                <span class="text-no-wrap">{{ formatCurrency(item.tax) }}</span>
            </template>
            <template #item.amount="{ item }">
                <span class="text-no-wrap font-weight-medium">{{ formatCurrency(item.amount) }}</span>
            </template>
            <template #item.open_amount="{ item }">
                <span class="text-no-wrap" :class="Number(item.open_amount) > 0.005 ? 'text-warning' : 'text-medium-emphasis'">
                    {{ formatCurrency(item.open_amount) }}
                </span>
            </template>
            <template #item.sellprice="{ item }">
                <span class="text-no-wrap">{{ formatCurrency(item.sellprice) }}</span>
            </template>
            <!-- Artikelart aus kivitendo: part, service, assembly, assortment -->
            <template #item.part_type="{ item }">
                {{ te(`DocumentList.partTypes.${item.part_type}`) ? t(`DocumentList.partTypes.${item.part_type}`) : item.part_type }}
            </template>
            <template #item.onhand="{ item }">
                <span class="text-no-wrap">{{ formatQty(item.onhand) }}</span>
            </template>
            <template #item.closed="{ item }">
                <span class="d-inline-flex align-center ga-1">
                    <v-icon
                        v-if="hasSentChannel(item, 'email_journal')"
                        size="16"
                        color="info"
                        :title="t('DocumentList.sentEmail')"
                    >mdi-email-check</v-icon>
                    <v-icon
                        v-if="hasSentChannel(item, 'whatsapp_messages')"
                        size="16"
                        color="green-darken-1"
                        :title="t('DocumentList.sentWhatsapp')"
                    >mdi-whatsapp</v-icon>
                    <v-chip
                        :color="item.closed ? 'success' : (item.overdue ? 'error' : 'warning')"
                        size="x-small"
                        variant="tonal"
                        :title="item.duedate ? t('DocumentList.dueOn', { date: formatDate(item.duedate, locale) }) : undefined"
                    >
                        {{ t(item.closed ? 'DocumentList.closed' : (item.overdue ? 'DocumentList.overdue' : 'DocumentList.open')) }}
                    </v-chip>
                </span>
            </template>
            <!-- Einzelbeleg: PDF, E-Mail an den Kunden, Drucker -->
            <template #item.actions="{ item }">
                <div class="d-flex justify-end row-actions">
                    <v-btn
                        icon="mdi-file-pdf-box"
                        variant="text"
                        size="small"
                        density="comfortable"
                        :title="t('DocumentList.docs.pdf')"
                        :disabled="!!busy"
                        @click.stop="downloadDocs(rowScope(item))"
                    />
                    <v-btn
                        icon="mdi-email-outline"
                        variant="text"
                        size="small"
                        density="comfortable"
                        color="info"
                        :title="t('DocumentList.docs.emailSingle')"
                        :disabled="!!busy"
                        @click.stop="openSendDialog('single', item)"
                    />
                    <v-btn
                        icon="mdi-printer-outline"
                        variant="text"
                        size="small"
                        density="comfortable"
                        :title="t('DocumentList.docs.print')"
                        :disabled="!!busy"
                        @click.stop="printDocs(rowScope(item))"
                    />
                </div>
            </template>
            <template #item.obsolete="{ item }">
                <v-chip v-if="item.obsolete" color="grey" size="x-small" variant="tonal">
                    {{ t('DocumentList.obsolete') }}
                </v-chip>
            </template>
            <template #no-data>
                <div class="text-medium-emphasis py-6">{{ t('DocumentList.noData') }}</div>
            </template>
        </v-data-table>

        <!-- Versand: Liste, Belegauswahl oder Einzelbeleg -->
        <DocumentListSendDialog
            v-model="sendDialog.visible"
            :title="sendDialog.title"
            :initial-to="sendDialog.to"
            :initial-cc="sendDialog.cc"
            :initial-bcc="sendDialog.bcc"
            :initial-subject="sendDialog.subject"
            :initial-body="sendDialog.body"
            :attachment-options="sendDialog.options"
            :slow-hint="sendDialog.slowHint"
            :sending="busy === 'email'"
            @send="onSend"
        />
    </v-container>
</template>

<script>
import { computed, ref, reactive, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import DocumentListSendDialog from './components/document-list.send-dialog.vue'
import { formatDate } from '@/core/utils/dateFormatter.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { downloadBase64File, openBase64Pdf } from '@/core/utils/download.js'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'

// Alles, was sich je Listentyp unterscheidet, steht an genau einer Stelle.
// create: Schaltfläche zum Anlegen — nur wo es eine eigene Neuanlage gibt.
// emailRecordType/recordTable: so landet eine versendete Mail im Journal am
// Beleg und die Liste zeigt das Versandsymbol.
const TYPES = {
    invoice:        { title: 'DocumentList.titles.invoice',       singular: 'DocumentList.singular.invoice',       icon: 'mdi-file-document-outline',  route: 'faktura-invoice-view',
                      emailRecordType: 'invoice',              recordTable: 'ar',              senderField: 'email_sender_invoice' },
    credit_note:    { title: 'DocumentList.titles.creditNote',    singular: 'DocumentList.singular.creditNote',    icon: 'mdi-file-undo-outline',      route: 'faktura-credit-note-view',
                      emailRecordType: 'invoice',              recordTable: 'ar',              senderField: 'email_sender_invoice' },
    order:          { title: 'DocumentList.titles.order',         singular: 'DocumentList.singular.order',         icon: 'mdi-clipboard-text-outline', route: 'faktura-order-view',
                      emailRecordType: 'sales_order',          recordTable: 'oe',              senderField: 'email_sender_sales_order' },
    quotation:      { title: 'DocumentList.titles.quotation',     singular: 'DocumentList.singular.quotation',     icon: 'mdi-file-sign',              route: 'faktura-quotation-view',
                      emailRecordType: 'sales_quotation',      recordTable: 'oe',              senderField: 'email_sender_sales_quotation' },
    delivery_order: { title: 'DocumentList.titles.deliveryOrder', singular: 'DocumentList.singular.deliveryOrder', icon: 'mdi-truck-outline',          route: 'faktura-delivery-order-view',
                      emailRecordType: 'sales_delivery_order', recordTable: 'delivery_orders', senderField: 'email_sender_sales_delivery_order' },
    part:           { title: 'DocumentList.titles.part',          icon: 'mdi-package-variant-closed', route: 'article-edit',
                      create: { route: 'article-new', label: 'DocumentList.newPart' } },
}

// Ab so vielen Belegen fragt der Sammeldruck nach — jeder Beleg wird einzeln gesetzt
const MANY_DOCS = 50

const pad = n => String(n).padStart(2, '0')
const isoDate = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const sanitizeFilename = s => String(s || '').replace(/[^\p{L}\p{N}_\-]+/gu, '_').replace(/^_+|_+$/g, '')

export default {
    name: 'DocumentListView',
    components: { NavbarView, DocumentListSendDialog },
    setup() {
        const { t, te, locale } = useI18n()
        const route = useRoute()
        const router = useRouter()
        const oserp = oserpStore()

        const listType = computed(() => route.meta?.listType || 'invoice')
        const config = computed(() => TYPES[listType.value] || TYPES.invoice)
        const isParts = computed(() => listType.value === 'part')
        // Lieferscheine führen keine Beträge; bezahlt/offen gibt es nur bei Rechnungen und Gutschriften
        const hasAmounts = computed(() => !isParts.value && listType.value !== 'delivery_order')
        const hasPayments = computed(() => ['invoice', 'credit_note'].includes(listType.value))

        const rows = ref([])
        const totals = ref({ count: 0, amount: 0, netamount: 0, tax: 0, open: 0 })
        const selected = ref([])
        const loading = ref(false)
        const error = ref('')
        const search = ref('')
        const from = ref('')
        const to = ref('')
        // 'all', 'open', 'closed' — filtert auf dem Server, damit Summen und Export dasselbe meinen
        const status = ref('all')
        // Welche Artikel: 'active' (Vorgabe), 'obsolete' oder 'all'
        const partsScope = ref('active')
        const obsoleteOnly = computed({
            get: () => partsScope.value === 'obsolete',
            set: (an) => { partsScope.value = an ? 'obsolete' : 'active' },
        })
        const showAll = computed({
            get: () => partsScope.value === 'all',
            set: (an) => {
                partsScope.value = an ? 'all' : 'active'
                if (an) {
                    shopFilter.value = ''
                    channelIdState.value = 0
                }
            },
        })

        // ── Zeitraum-Schnellwahl ────────────────────────────────────────────
        // Setzt von/bis; tippt man selbst ein Datum, springt die Auswahl auf
        // „Benutzerdefiniert“, löscht man beide, auf „Gesamt“.
        function presetRange(key) {
            const now = new Date()
            const y = now.getFullYear(), m = now.getMonth()
            switch (key) {
                case 'thisMonth':   return [new Date(y, m, 1), new Date(y, m + 1, 0)]
                case 'lastMonth':   return [new Date(y, m - 1, 1), new Date(y, m, 0)]
                case 'thisQuarter': { const q = Math.floor(m / 3) * 3; return [new Date(y, q, 1), new Date(y, q + 3, 0)] }
                case 'lastQuarter': { const q = Math.floor(m / 3) * 3 - 3; return [new Date(y, q, 1), new Date(y, q + 3, 0)] }
                case 'thisYear':    return [new Date(y, 0, 1), new Date(y, 11, 31)]
                case 'lastYear':    return [new Date(y - 1, 0, 1), new Date(y - 1, 11, 31)]
                default:            return null
            }
        }
        const PRESETS = ['all', 'thisMonth', 'lastMonth', 'thisQuarter', 'lastQuarter', 'thisYear', 'lastYear']
        const periodPresetState = ref('all')
        let applyingPreset = false
        const periodPreset = computed({
            get: () => periodPresetState.value,
            set: (key) => {
                periodPresetState.value = key
                if (key === 'custom') return
                applyingPreset = true
                const range = presetRange(key)
                from.value = range ? isoDate(range[0]) : ''
                to.value   = range ? isoDate(range[1]) : ''
                applyingPreset = false
            },
        })
        const periodItems = computed(() => [
            ...PRESETS.map(key => ({ value: key, title: t(`DocumentList.period.${key}`) })),
            { value: 'custom', title: t('DocumentList.period.custom') },
        ])
        watch([from, to], ([f, tt]) => {
            if (applyingPreset) return
            if (!f && !tt) { periodPresetState.value = 'all'; return }
            const match = PRESETS.find(key => {
                const range = presetRange(key)
                return range && isoDate(range[0]) === f && isoDate(range[1]) === tt
            })
            periodPresetState.value = match || 'custom'
        })
        // Lesbarer Zeitraum für Betreff, Dateinamen und Listenkopf
        const periodLabel = computed(() => {
            if (!from.value && !to.value) return t('DocumentList.period.all')
            if (periodPresetState.value !== 'custom' && periodPresetState.value !== 'all') {
                const range = presetRange(periodPresetState.value)
                const fmt = new Intl.DateTimeFormat(locale.value, ['thisMonth', 'lastMonth'].includes(periodPresetState.value)
                    ? { month: 'long', year: 'numeric' } : { day: '2-digit', month: '2-digit', year: 'numeric' })
                return ['thisMonth', 'lastMonth'].includes(periodPresetState.value)
                    ? fmt.format(range[0])
                    : `${fmt.format(range[0])} – ${fmt.format(range[1])}`
            }
            return [from.value ? formatDate(from.value, locale.value) : '…', to.value ? formatDate(to.value, locale.value) : '…'].join(' – ')
        })
        const periodFile = computed(() => {
            if (!from.value && !to.value) return t('DocumentList.period.all')
            return [from.value || 'Beginn', to.value || 'heute'].join('_')
        })

        // ── Shop-Filter der Artikelliste ────────────────────────────────────
        // Im Shop angeboten oder nicht — zusätzlich zu aktiv/ausgemustert,
        // nicht zusammen mit „Alle anzeigen“. '' = egal, 'offered', 'not_offered'
        const shopEnabled = computed(() => oserp.isExtensionEnabled('shop'))
        // Vorbelegung aus der URL (?shop=offered): so öffnet die Übersicht des
        // Shops die Liste gleich gefiltert
        const shopFilterState = ref(['offered', 'not_offered'].includes(route.query.shop) ? route.query.shop : '')
        // Nur Waren ohne Gewicht (?weight=missing) — zum Nachpflegen für den Versand
        const weightMissingState = ref(route.query.weight === 'missing')
        // Ohne passende Versandart (?shipping=unfit) — diese Seiten werden nicht veröffentlicht
        const shippingUnfitState = ref(route.query.shipping === 'unfit')
        const shopFilter = computed({
            get: () => (shopEnabled.value ? shopFilterState.value : ''),
            set: (wert) => {
                shopFilterState.value = wert
                if (wert && partsScope.value === 'all') partsScope.value = 'active'
                // Die Kanalauswahl gehört zu „angeboten“ — sonst wirkte eine unsichtbare Auswahl
                if (wert !== 'offered') channelIdState.value = 0
            },
        })
        const weightMissing = computed({
            get: () => shopEnabled.value && weightMissingState.value,
            set: (an) => { weightMissingState.value = an },
        })
        const shippingUnfit = computed({
            get: () => shopEnabled.value && shippingUnfitState.value,
            set: (an) => { shippingUnfitState.value = an },
        })
        const shopOnly = computed({
            get: () => shopFilter.value === 'offered',
            set: (an) => { shopFilter.value = an ? 'offered' : '' },
        })
        const notInShop = computed({
            get: () => shopFilter.value === 'not_offered',
            set: (an) => { shopFilter.value = an ? 'not_offered' : '' },
        })

        // Kanal innerhalb von „Nur im Shop angebotene“: 0 = alle Kanäle. Die
        // Kanäle kommen einmal beim Öffnen, nur bei aktiver Shop-Erweiterung
        const channels = ref([])
        const channelIdState = ref(0)
        const channelId = computed({
            get: () => (shopOnly.value ? channelIdState.value : 0),
            set: (id) => { channelIdState.value = Number(id) || 0 },
        })
        const channelItems = computed(() => [
            { value: 0, title: t('DocumentList.allChannels') },
            ...channels.value.map(kanal => ({
                value: Number(kanal.channel_id),
                // Name des Kanals (mehrere je Art), sonst die Bezeichnung der Art
                title: kanal.name || (te(`ShopView.channels.${kanal.type}`) ? t(`ShopView.channels.${kanal.type}`) : kanal.type),
            })),
        ])

        async function loadChannels() {
            if (!shopEnabled.value) return
            try {
                const res = await axios.post('/api/faktura/', { action: 'getPartsSalesChannels' })
                channels.value = res.data?.success ? (res.data.payload.channels || []) : []
            } catch {
                channels.value = []
            }
        }

        const LIMIT = 500
        const atLimit = computed(() => rows.value.length >= LIMIT)
        const hasFilter = computed(() =>
            !!search.value || !!from.value || !!to.value || status.value !== 'all' || partsScope.value !== 'active' || !!shopFilter.value
            || weightMissing.value || shippingUnfit.value
        )

        const headers = computed(() => {
            if (isParts.value) {
                return [
                    { title: t('DocumentList.columns.partnumber'), key: 'partnumber' },
                    { title: t('DocumentList.columns.description'), key: 'description' },
                    { title: t('DocumentList.columns.partType'), key: 'part_type' },
                    { title: t('DocumentList.columns.unit'), key: 'unit' },
                    { title: t('DocumentList.columns.sellprice'), key: 'sellprice', align: 'end' },
                    { title: t('DocumentList.columns.onhand'), key: 'onhand', align: 'end' },
                    { title: '', key: 'obsolete', sortable: false },
                ]
            }
            const cols = [
                { title: t('DocumentList.columns.number'), key: 'number' },
                { title: t('DocumentList.columns.date'), key: 'transdate' },
                { title: t('DocumentList.columns.customer'), key: 'customer_name' },
                { title: t('DocumentList.columns.description'), key: 'description' },
            ]
            if (hasAmounts.value) {
                cols.push(
                    { title: t('DocumentList.columns.net'), key: 'netamount', align: 'end' },
                    { title: t('DocumentList.columns.tax'), key: 'tax', align: 'end' },
                    { title: t('DocumentList.columns.gross'), key: 'amount', align: 'end' },
                )
            }
            if (hasPayments.value) cols.push({ title: t('DocumentList.columns.open'), key: 'open_amount', align: 'end' })
            cols.push(
                { title: t('DocumentList.columns.status'), key: 'closed', align: 'center' },
                { title: '', key: 'actions', sortable: false, align: 'end', width: 120 },
            )
            return cols
        })

        // Versandkanäle je Beleg (sent_channels: "email_journal,whatsapp_messages")
        const hasSentChannel = (item, channel) => String(item.sent_channels || '').split(',').includes(channel)

        function formatCurrency(value) {
            if (value === null || value === undefined || value === '') return ''
            return new Intl.NumberFormat(locale.value, {
                style: 'currency', currency: 'EUR',
            }).format(Number(value))
        }

        // Bestand kommt als numeric(x,5) — ungekuerzt liest sich "0.00000" wie ein Fehler
        function formatQty(value) {
            if (value === null || value === undefined || value === '') return ''
            return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(Number(value))
        }

        // Filter der Belegliste — Laden und Export nutzen exakt dieselben Werte
        function listParams() {
            return { documentType: listType.value, q: search.value || '', from: from.value || '', to: to.value || '', status: status.value === 'all' ? '' : status.value }
        }

        async function load() {
            loading.value = true
            error.value = ''
            try {
                const payload = isParts.value
                    ? { action: 'searchParts', q: search.value || '', scope: partsScope.value, shop: shopFilter.value, weight_missing: weightMissing.value,
                        shipping_unfit: shippingUnfit.value, channel_id: channelId.value, limit: LIMIT }
                    : { action: 'searchDocuments', ...listParams(), limit: LIMIT }
                const res = await axios.post('/api/faktura/', payload)
                if (res.data?.success) {
                    rows.value = (isParts.value ? res.data.payload.parts : res.data.payload.documents) || []
                    totals.value = res.data.payload.totals || { count: rows.value.length, amount: 0, netamount: 0, tax: 0, open: 0 }
                } else {
                    rows.value = []
                    totals.value = { count: 0, amount: 0, netamount: 0, tax: 0, open: 0 }
                    error.value = res.data?.payload || t('DocumentList.loadError')
                }
            } catch {
                rows.value = []
                error.value = t('DocumentList.loadError')
            } finally {
                // Eine Markierung darf nicht auf Zeilen zeigen, die es nicht mehr gibt
                const ids = new Set(rows.value.map(r => r.id))
                selected.value = selected.value.filter(id => ids.has(id))
                loading.value = false
            }
        }

        // Tippen laedt nach kurzer Pause nach — kein Suchknopf noetig
        let debounce = null
        watch([search, from, to, status, partsScope, shopFilter, channelId, weightMissing, shippingUnfit], () => {
            clearTimeout(debounce)
            debounce = setTimeout(load, 350)
        })
        watch(listType, () => { selected.value = []; load() })
        onMounted(() => {
            load()
            loadChannels()
            initPrinter()
        })

        function reset() {
            search.value = ''
            from.value = ''
            to.value = ''
            status.value = 'all'
            partsScope.value = 'active'
            shopFilterState.value = ''
            channelIdState.value = 0
            weightMissingState.value = false
            shippingUnfitState.value = false
        }

        function openRow(_event, row) {
            router.push({ name: config.value.route, params: { id: row.item.id } })
        }

        // ── Drucker (wie in der Suche: Vorgabe aus der Benutzerkonfiguration) ──
        const printerList = computed(() => (oserp.session.company_config?.printers || []).filter(p => !p.hide_factura))
        const selectedPrinter = ref(null)
        function initPrinter() {
            const savedId = oserp.getConfigValue('default_printer_id')
            if (savedId) selectedPrinter.value = printerList.value.find(p => String(p.id) === String(savedId)) || null
            if (!selectedPrinter.value && printerList.value.length === 1) selectedPrinter.value = printerList.value[0]
        }
        function selectPrinter(printer) {
            selectedPrinter.value = printer
            oserp.setConfigValue('default_printer_id', String(printer.id))
            toasts.success(t('DocumentList.docs.printerSelected', { name: printer.printer_description }))
        }
        // Ohne Drucker öffnet „Drucken“ das PDF im Browser — von dort geht es zum Drucker
        const printerSubtitle = computed(() => selectedPrinter.value
            ? selectedPrinter.value.printer_description
            : t('DocumentList.docs.printInBrowser'))

        // Eine Aktion zur Zeit: '', 'list-print', 'export', 'docs-pdf', 'docs-print', 'email'
        const busy = ref('')

        function apiErrorText(res, fallback) {
            const p = res?.data?.payload
            if (typeof p === 'string' && p) return p
            return res?.data?.text || fallback
        }

        // ── Liste: drucken, exportieren ─────────────────────────────────────
        // Beschriftungen übersetzt mitgeben — PDF und CSV entstehen im Backend
        function exportLabels() {
            const keys = ['number', 'date', 'duedate', 'customer', 'customernumber', 'description', 'net', 'tax', 'gross', 'paid', 'open', 'status']
            const labels = Object.fromEntries(keys.map(k => [k, t(`DocumentList.columns.${k}`)]))
            return {
                ...labels,
                title:         t(config.value.title),
                period:        periodLabel.value,
                company:       oserp.session.company_config?.defaults?.company || '',
                statusOpen:    t('DocumentList.open'),
                statusClosed:  t('DocumentList.closed'),
                statusOverdue: t('DocumentList.overdue'),
                total:         t('DocumentList.totals.total'),
                documents:     t('DocumentList.totals.documents'),
                createdAt:     t('DocumentList.totals.createdAt'),
            }
        }

        async function fetchListFile(format) {
            const res = await axios.post('/api/faktura/', { action: 'exportDocumentList', ...listParams(), format, labels: exportLabels() })
            if (!res.data?.success) throw new Error(apiErrorText(res, t('DocumentList.actions.exportError')))
            return res.data.payload
        }

        async function printList() {
            busy.value = 'list-print'
            try {
                const file = await fetchListFile('pdf')
                openBase64Pdf(file.data, file.filename)
            } catch (e) {
                alerts.error(e.message)
            } finally {
                busy.value = ''
            }
        }

        // Excel entsteht im Browser aus den Rohzeilen: echte Zahlen und Datumswerte
        // statt Text, unabhängig von der Excel-Spracheinstellung des Empfängers
        async function buildXlsx(payload) {
            const XLSX = await import('xlsx')
            const l = exportLabels()
            const withAmounts = hasAmounts.value, withPayments = hasPayments.value
            // Kopfzeile und Spaltenbreiten (Zeichen) parallel aufbauen
            const head = [], widths = []
            const col = (label, w) => { head.push(label); widths.push({ wch: w }) }
            col(l.number, 16); col(l.date, 12)
            if (withPayments) col(l.duedate, 12)
            col(l.customer, 36); col(l.customernumber, 14); col(l.description, 40)
            if (withAmounts) { col(l.net, 14); col(l.tax, 14); col(l.gross, 14) }
            if (withPayments) { col(l.paid, 14); col(l.open, 14) }
            col(l.status, 12)

            const toDate = v => (v ? new Date(v + 'T00:00:00') : null)
            const num = v => (v === null || v === undefined || v === '' ? null : Number(v))
            const data = payload.documents.map(r => {
                const row = [r.number, toDate(r.transdate)]
                if (withPayments) row.push(toDate(r.duedate))
                row.push(r.customer_name || '', r.customernumber || '', r.description || '')
                if (withAmounts) row.push(num(r.netamount), num(r.tax), num(r.amount))
                if (withPayments) row.push(num(r.paid), num(r.open_amount))
                row.push(r.status_text)
                return row
            })
            if (withAmounts) {
                const tot = payload.totals
                const row = [`${l.total} (${tot.count} ${l.documents})`, null]
                if (withPayments) row.push(null)
                row.push(null, null, null, Number(tot.netamount), Number(tot.tax), Number(tot.amount))
                if (withPayments) row.push(null, Number(tot.open))
                row.push(null)
                data.push(row)
            }

            const ws = XLSX.utils.aoa_to_sheet([head, ...data], { cellDates: true })
            // Spaltenformate: Datum und Beträge
            const range = XLSX.utils.decode_range(ws['!ref'])
            for (let R = 1; R <= range.e.r; R++) {
                for (let C = 0; C <= range.e.c; C++) {
                    const cell = ws[XLSX.utils.encode_cell({ r: R, c: C })]
                    if (!cell) continue
                    if (cell.t === 'd') cell.z = 'dd.mm.yyyy'
                    else if (cell.t === 'n') cell.z = '#,##0.00'
                }
            }
            ws['!cols'] = widths
            ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: 0, c: head.length - 1 } }) }
            const wb = XLSX.utils.book_new()
            XLSX.utils.book_append_sheet(wb, ws, t(config.value.title).slice(0, 31))
            return XLSX.write(wb, { type: 'base64', bookType: 'xlsx', cellDates: true })
        }

        async function downloadList(format) {
            busy.value = 'export'
            try {
                if (format === 'xlsx') {
                    const payload = await fetchListFile('rows')
                    const base64 = await buildXlsx(payload)
                    downloadBase64File(base64, payload.filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                } else {
                    const file = await fetchListFile(format)
                    downloadBase64File(file.data, file.filename, file.mime)
                }
            } catch (e) {
                alerts.error(e.message)
            } finally {
                busy.value = ''
            }
        }

        // ── Belege: Sammel-PDF, Drucker, E-Mail ─────────────────────────────
        // Markierte Belege, sonst alle geladenen Treffer
        function docsScope() {
            const list = selected.value.length
                ? rows.value.filter(r => selected.value.includes(r.id))
                : rows.value
            return {
                kind: selected.value.length ? 'selection' : 'all',
                docs: list.map(r => ({ id: r.id, fakturaType: r.print_type || listType.value })),
                filename: `${sanitizeFilename(t(config.value.title))}_${sanitizeFilename(periodFile.value)}.pdf`,
            }
        }
        function rowScope(row) {
            return {
                kind: 'single',
                row,
                docs: [{ id: row.id, fakturaType: row.print_type || listType.value }],
                filename: `${sanitizeFilename(t(config.value.singular))}_${sanitizeFilename(row.number || row.id)}.pdf`,
            }
        }

        // Viele Belege dauern — vorher fragen, statt den Benutzer rätseln zu lassen
        async function confirmMany(scope) {
            if (scope.docs.length < MANY_DOCS) return true
            const r = await alerts.question(
                t('DocumentList.docs.manyText', { count: scope.docs.length }),
                t('DocumentList.docs.manyTitle'),
                t('DocumentList.docs.continue'),
                t('DocumentList.send.cancel'),
            )
            return !!r?.isConfirmed
        }

        async function fetchDocsPdf(scope) {
            const res = await axios.post('/api/print/', {
                action: 'generateBatchPdf',
                documents: scope.docs,
                printerId: selectedPrinter.value?.id ?? null,
                filename: scope.filename,
            })
            if (!res.data?.success) throw new Error(apiErrorText(res, t('DocumentList.docs.pdfError')))
            const payload = res.data.payload
            if ((payload.errors || []).length) toasts.warning(t('DocumentList.docs.partial', { failed: payload.errors.length }))
            return payload
        }

        async function downloadDocs(scope) {
            if (!scope.docs.length || !(await confirmMany(scope))) return
            busy.value = 'docs-pdf'
            try {
                const pdf = await fetchDocsPdf(scope)
                downloadBase64File(pdf.pdf, scope.filename, 'application/pdf')
            } catch (e) {
                alerts.error(e.message)
            } finally {
                busy.value = ''
            }
        }

        async function printDocs(scope) {
            if (!scope.docs.length || !(await confirmMany(scope))) return
            busy.value = 'docs-print'
            try {
                if (!selectedPrinter.value) {
                    // Kein Drucker eingerichtet: PDF im Browser öffnen, dort drucken
                    const pdf = await fetchDocsPdf(scope)
                    openBase64Pdf(pdf.pdf, scope.filename)
                    return
                }
                const res = await axios.post('/api/print/', {
                    action: 'printBatchToPrinter',
                    documents: scope.docs,
                    printerId: selectedPrinter.value.id,
                })
                if (!res.data?.success) throw new Error(apiErrorText(res, t('DocumentList.docs.printError')))
                const payload = res.data.payload || {}
                if ((payload.errors || []).length) {
                    toasts.warning(t('DocumentList.docs.printedPartial', { count: payload.count, failed: payload.errors.length }))
                } else {
                    toasts.success(t('DocumentList.docs.printed', { count: payload.count, name: payload.printer }))
                }
                if (scope.kind === 'selection') selected.value = []
            } catch (e) {
                alerts.error(e.message)
            } finally {
                busy.value = ''
            }
        }

        // ── E-Mail ──────────────────────────────────────────────────────────
        const sendDialog = reactive({ visible: false, mode: 'list', title: '', to: '', cc: '', bcc: '', subject: '', body: '', options: [], slowHint: '', scope: null })

        function openSendDialog(mode, row = null) {
            const title  = t(config.value.title)
            const period = periodLabel.value
            const scope  = mode === 'single' ? rowScope(row) : docsScope()
            const docCount = scope.docs.length
            const rememberedTo = oserp.getConfigValue('document_list_email_to') || ''

            const listPdf = { key: 'listPdf', label: t('DocumentList.send.attachListPdf', { title }), hint: t('DocumentList.send.docCount', { count: totals.value.count }) }
            const listCsv = { key: 'listCsv', label: t('DocumentList.send.attachListCsv', { title }) }
            const docsPdf = { key: 'docs', label: t('DocumentList.send.attachDocs', { title }), hint: t('DocumentList.send.docCount', { count: docCount }) }

            if (mode === 'single') {
                const singular = t(config.value.singular)
                Object.assign(sendDialog, {
                    mode, scope,
                    title:   t('DocumentList.send.titleSingle', { type: singular, number: row.number }),
                    to:      row.customer_email || '',
                    cc:      row.customer_cc || '',
                    bcc:     row.customer_bcc || '',
                    subject: t('DocumentList.send.subjectSingle', { type: singular, number: row.number }),
                    body:    t('DocumentList.send.bodySingle', { type: singular, number: row.number }),
                    options: [{ key: 'docs', label: t('DocumentList.send.attachSingle', { type: singular, number: row.number }), checked: true }],
                    slowHint: '',
                })
            } else if (mode === 'docs') {
                Object.assign(sendDialog, {
                    mode, scope,
                    title:   t('DocumentList.send.titleDocs', { title }),
                    to:      rememberedTo, cc: '', bcc: '',
                    subject: t('DocumentList.send.subjectList', { title, period }),
                    body:    t('DocumentList.send.bodyDocs', { title, period, count: docCount }),
                    options: [{ ...docsPdf, checked: true }, { ...listPdf, checked: false }, { ...listCsv, checked: false }],
                    slowHint: docCount >= MANY_DOCS ? t('DocumentList.docs.manyText', { count: docCount }) : '',
                })
            } else {
                Object.assign(sendDialog, {
                    mode, scope,
                    title:   t('DocumentList.send.titleList', { title }),
                    to:      rememberedTo, cc: '', bcc: '',
                    subject: t('DocumentList.send.subjectList', { title, period }),
                    body:    t('DocumentList.send.bodyList', {
                        title, period, count: totals.value.count,
                        net: formatCurrency(totals.value.netamount), tax: formatCurrency(totals.value.tax), gross: formatCurrency(totals.value.amount),
                    }),
                    options: [{ ...listPdf, checked: true }, { ...listCsv, checked: true }, { ...docsPdf, checked: false }],
                    slowHint: '',
                })
            }
            sendDialog.visible = true
        }

        const splitAddresses = value => String(value || '').split(/[,;]/).map(e => ({ email: e.trim(), name: '' })).filter(e => e.email)

        async function onSend(form) {
            const scope = sendDialog.scope
            if (form.attachments.docs && scope.docs.length >= MANY_DOCS && !(await confirmMany(scope))) return
            busy.value = 'email'
            try {
                const attachments = []
                if (form.attachments.listPdf) {
                    const f = await fetchListFile('pdf')
                    attachments.push({ filename: f.filename, content_base64: f.data, content_type: f.mime })
                }
                if (form.attachments.listCsv) {
                    const f = await fetchListFile('csv')
                    attachments.push({ filename: f.filename, content_base64: f.data, content_type: f.mime })
                }
                if (form.attachments.docs) {
                    const pdf = await fetchDocsPdf(scope)
                    attachments.push({ filename: scope.filename, content_base64: pdf.pdf, content_type: 'application/pdf' })
                }

                const defaults = oserp.session.company_config?.defaults || {}
                const single = sendDialog.mode === 'single'
                const res = await axios.post('/api/email/', {
                    action:       'sendEmail',
                    from_name:    defaults[config.value.senderField] || '',
                    to:           splitAddresses(form.to),
                    cc:           splitAddresses(form.cc),
                    bcc:          splitAddresses(form.bcc),
                    subject:      form.subject,
                    body_text:    form.body,
                    body_html:    '',
                    record_type:  single ? config.value.emailRecordType : null,
                    record_table: single ? config.value.recordTable : null,
                    record_id:    single ? Number(scope.row.id) : null,
                    attachments,
                })
                if (!res.data?.success) throw new Error(apiErrorText(res, t('DocumentList.send.failed')))

                toasts.success(t('DocumentList.send.sent', { to: splitAddresses(form.to).map(a => a.email).join(', ') }))
                if (!single) oserp.setConfigValue('document_list_email_to', form.to)
                sendDialog.visible = false
                // Versandsymbol am Beleg nachziehen
                if (single) load()
            } catch (e) {
                alerts.error(e.message)
            } finally {
                busy.value = ''
            }
        }

        return {
            hasSentChannel, LIMIT,
            t, te, locale, config, isParts, hasAmounts, hasPayments, rows, totals, selected, headers, loading, error, busy,
            search, from, to, status, periodPreset, periodItems,
            obsoleteOnly, showAll, shopOnly, notInShop, weightMissing, shippingUnfit, shopEnabled, channels, channelId, channelItems, hasFilter, atLimit,
            printerList, selectedPrinter, selectPrinter, printerSubtitle,
            formatDate, formatCurrency, formatQty, reset, openRow,
            printList, downloadList, docsScope, rowScope, downloadDocs, printDocs,
            sendDialog, openSendDialog, onSend,
        }
    },
}
</script>

<style scoped>
.zebra-table :deep(tbody tr:nth-child(odd)) {
    background-color: rgba(0, 0, 0, 0.03);
}
.zebra-table :deep(tbody tr:hover) {
    cursor: pointer;
}
/* Zeilenaktionen erst beim Überfahren voll sichtbar — die Tabelle bleibt ruhig */
.zebra-table :deep(.row-actions) {
    opacity: 0.45;
    transition: opacity 0.15s;
}
.zebra-table :deep(tr:hover .row-actions),
.zebra-table :deep(tr:focus-within .row-actions) {
    opacity: 1;
}
.summary-bar {
    display: flex;
    flex-wrap: wrap;
    gap: var(--oserp-gap, 12px) var(--oserp-gap-lg, 24px);
    padding: var(--oserp-gap-sm, 8px) var(--oserp-gap, 12px);
}
.summary-item {
    display: flex;
    flex-direction: column;
    min-width: 7rem;
}
.summary-label {
    font-size: 0.75rem;
    color: var(--oserp-text-muted, rgba(0, 0, 0, 0.6));
}
.summary-value {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
</style>
