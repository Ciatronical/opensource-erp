<!-- src/core/views/faktura/components/action.bar.component.vue -->

<template>
    <v-card variant="outlined" class="action-bar mb-4">
        <v-card-text class="action-bar__content">
            <div class="action-bar__left">
                <!-- Dokumenttyp + Kompaktansicht -->
                <v-chip color="primary" variant="elevated" size="default">
                    <v-icon start size="small">mdi-file-document</v-icon>
                    {{ t(`FakturaView.dokumentTypes.${fakturaType}`) }}
                </v-chip>
                <v-btn
                    icon
                    size="small"
                    variant="text"
                    :color="compactView ? 'primary' : 'grey'"
                    :title="compactView ? t('FakturaView.faktura.showDetails') : t('FakturaView.faktura.compactView')"
                    @click="$emit('toggle-compact')"
                >
                    <v-icon>{{ compactView ? 'mdi-arrow-expand' : 'mdi-arrow-collapse' }}</v-icon>
                </v-btn>

                <v-divider vertical class="mx-2" />

                <!-- Workflow Menu - Wichtigste Aktion -->
                <v-menu>
                    <template #activator="{ props }">
                        <v-btn
                            v-bind="props"
                            color="primary"
                            variant="elevated"
                            prepend-icon="mdi-swap-horizontal"
                            :disabled="!hasCustomer"
                        >
                            {{ t('FakturaView.faktura.workflow') }}
                        </v-btn>
                    </template>
                    <v-list density="compact">
                        <!-- ===== KOPIEREN ===== -->
                        <v-list-item
                            prepend-icon="mdi-content-copy"
                            @click="$emit('reuse')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.reuse') }}</v-list-item-title>
                        </v-list-item>

                        <!-- ===== DOKUMENTE ERSTELLEN ===== -->
                        <v-divider class="my-1" />

                        <v-list-item
                            v-if="canCreateQuotation"
                            prepend-icon="mdi-file-document-outline"
                            @click="$emit('create-quotation')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createQuotation') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="canCreateOrder"
                            prepend-icon="mdi-file-document-plus"
                            @click="$emit('create-order')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createOrder') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="canCreateDeliveryOrder"
                            prepend-icon="mdi-truck-delivery"
                            @click="$emit('create-delivery-order')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createDeliveryOrder') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="canCreateInvoice"
                            prepend-icon="mdi-file-document-edit"
                            @click="$emit('create-invoice')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createInvoice') }}</v-list-item-title>
                        </v-list-item>

                        <!-- ===== KORREKTUREN ===== -->
                        <v-divider v-if="canCreateCreditNote || canCancel || canCreateComplaint" class="my-1" />

                        <v-list-item
                            v-if="canCreateCreditNote"
                            prepend-icon="mdi-cash-refund"
                            @click="$emit('create-credit-note')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createCreditNote') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="canCancel"
                            prepend-icon="mdi-cancel"
                            @click="$emit('cancel')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.cancel') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="canCreateComplaint"
                            prepend-icon="mdi-alert-circle-outline"
                            @click="$emit('create-complaint')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createComplaint') }}</v-list-item-title>
                        </v-list-item>

                        <!-- ===== EINKAUF ===== -->
                        <v-divider v-if="canCreateSupplierInquiry || canCreateSupplierOrder" class="my-1" />

                        <v-list-item
                            v-if="canCreateSupplierInquiry"
                            prepend-icon="mdi-help-circle-outline"
                            @click="$emit('create-supplier-inquiry')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createSupplierInquiry') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="canCreateSupplierOrder"
                            prepend-icon="mdi-cart-arrow-down"
                            @click="$emit('create-supplier-order')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.createSupplierOrder') }}</v-list-item-title>
                        </v-list-item>

                        <!-- ===== STATUS / EXPORT ===== -->
                        <v-divider v-if="canSaveAsDraft || canExportXInvoice" class="my-1" />

                        <v-list-item
                            v-if="canSaveAsDraft"
                            prepend-icon="mdi-file-edit-outline"
                            @click="$emit('save-as-draft')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.saveAsDraft') }}</v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="canExportXInvoice"
                            prepend-icon="mdi-file-xml-box"
                            @click="$emit('export-xinvoice')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.exportXInvoice') }}</v-list-item-title>
                        </v-list-item>

                        <!-- ===== LÖSCHEN ===== -->
                        <v-divider class="my-1" />

                        <v-list-item
                            prepend-icon="mdi-delete-outline"
                            class="text-error"
                            @click="$emit('delete')"
                        >
                            <v-list-item-title>{{ t('FakturaView.faktura.delete') }}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-menu>

                <v-divider vertical class="mx-2" />

                <!-- Printer Selection -->
                <v-menu v-if="printerList.length > 0">
                    <template #activator="{ props }">
                        <v-btn
                            v-bind="props"
                            variant="tonal"
                            prepend-icon="mdi-printer-settings"
                            :disabled="!hasCustomer"
                        >
                            {{ selectedPrinter ? selectedPrinter.printer_description : t('FakturaView.faktura.selectPrinter') }}
                        </v-btn>
                    </template>
                    <v-list density="compact">
                        <v-list-item
                            v-for="printer in printerList"
                            :key="printer.id"
                            prepend-icon="mdi-printer"
                            @click="$emit('select-printer', printer)"
                        >
                            <v-list-item-title>{{ printer.printer_description }}</v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-menu>

                <!-- Print -->
                <v-tooltip location="bottom" :text="t('FakturaView.common.print')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            icon="mdi-printer"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('print')"
                        />
                    </template>
                </v-tooltip>

                <!-- PDF Preview -->
                <v-tooltip location="bottom" :text="t('FakturaView.faktura.pdfPreview')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="error"
                            icon="mdi-file-pdf-box"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('pdf-preview')"
                        />
                    </template>
                </v-tooltip>

                <!-- Email -->
                <v-tooltip location="bottom" :text="t('FakturaView.common.email')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="info"
                            icon="mdi-email"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('send-email')"
                        />
                    </template>
                </v-tooltip>

                <!-- WhatsApp -->
                <v-tooltip location="bottom" text="WhatsApp">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="green-darken-1"
                            icon="mdi-whatsapp"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('send-whatsapp')"
                        />
                    </template>
                </v-tooltip>

                <!-- DHL Versandetikett -->
                <v-tooltip v-if="showDhlButton" location="bottom" :text="t('FakturaView.faktura.dhlLabel')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="amber-darken-3"
                            icon="mdi-package-variant-closed"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('create-dhl-label')"
                        />
                    </template>
                </v-tooltip>

                <!-- Auf Display zeigen -->
                <v-tooltip v-if="showDisplayButton" location="bottom" :text="t('FakturaView.faktura.showOnDisplay')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="deep-orange"
                            icon="mdi-monitor"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('show-on-display')"
                        />
                    </template>
                </v-tooltip>

                <!-- Fahrzeug (nur bei aktivem LxCars + verknuepftem Fahrzeug) -->
                <v-tooltip location="bottom" :text="t('FakturaView.faktura.openVehicle')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-if="showVehicleButton"
                            v-bind="tip"
                            variant="tonal"
                            color="teal"
                            icon="mdi-car"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('open-vehicle')"
                        />
                    </template>
                </v-tooltip>

                <!-- SilverDAT Import (nur bei Aufträgen mit LxCars) -->
                <v-tooltip v-if="canImportSilverDAT" location="bottom" :text="t('FakturaView.faktura.importSilverDAT')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="orange"
                            icon="mdi-file-import"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('import-silverdat')"
                        />
                    </template>
                </v-tooltip>

                <!-- AAG-Online (nur bei Aufträgen mit LxCars) -->
                <v-tooltip v-if="canOpenAag" location="bottom" :text="t('FakturaView.faktura.openAag')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="indigo"
                            icon="mdi-car-search"
                            size="small"
                            :disabled="!hasCustomer"
                            :loading="aagLoading"
                            @click="$emit('open-aag')"
                        />
                    </template>
                </v-tooltip>

                <!-- ESI[tronic] (nur bei Aufträgen mit gültiger HSN/TSN) -->
                <v-tooltip v-if="esiAvailable" location="bottom" :text="t('FakturaView.faktura.openEsi')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="teal-darken-1"
                            icon="mdi-cog-outline"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('open-esi')"
                        />
                    </template>
                </v-tooltip>

                <!-- Hella Gutmann mega macs (nur bei Aufträgen mit gültiger HSN/TSN, wenn konfiguriert) -->
                <v-tooltip v-if="gutmannAvailable" location="bottom" :text="t('FakturaView.faktura.openGutmann')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="cyan-darken-2"
                            icon="mdi-lan-connect"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('open-gutmann')"
                        />
                    </template>
                </v-tooltip>

                <!-- HGS-Data (nur bei Aufträgen mit identifizierbarem Fahrzeug) -->
                <v-tooltip v-if="hgsAvailable" location="bottom" :text="t('FakturaView.faktura.openHgs')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="blue-grey-darken-1"
                            icon="mdi-database-search-outline"
                            size="small"
                            :disabled="!hasCustomer"
                            :loading="hgsLoading"
                            @click="$emit('open-hgs')"
                        />
                    </template>
                </v-tooltip>

                <v-tooltip v-if="showSpecialButton" location="bottom" :text="t('SpecialDialog.buttonTooltip')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="deep-purple"
                            icon="mdi-car-wrench"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('open-special')"
                        />
                    </template>
                </v-tooltip>

                <!-- History - Icon Button -->
                <v-tooltip location="bottom">
                    <template #activator="{ props }">
                        <v-btn
                            v-bind="props"
                            icon
                            variant="text"
                            @click="$emit('show-history')"
                        >
                            <v-icon>mdi-history</v-icon>
                        </v-btn>
                    </template>
                    <span>{{ t('FakturaView.faktura.history') }}</span>
                </v-tooltip>

                <!-- Wiedervorlage - Icon Button -->
                <v-tooltip location="bottom">
                    <template #activator="{ props }">
                        <v-btn
                            v-bind="props"
                            icon
                            variant="text"
                            @click="$emit('set-followup')"
                        >
                            <v-icon>mdi-bell-ring-outline</v-icon>
                        </v-btn>
                    </template>
                    <span>{{ t('FakturaView.faktura.followUp') }}</span>
                </v-tooltip>

                <!-- Fahrzeugdaten einsprechen (Kilometerstand, Zahnriemen, Bremsflüssigkeit …) -->
                <VoiceInputButton
                    v-if="showVehicleVoice"
                    color="primary"
                    @transcript="$emit('voice-vehicle', $event)"
                />
            </div>

            <div class="action-bar__right">
                <v-chip
                    v-if="showClosed"
                    :color="closed ? 'success' : 'info'"
                    variant="tonal"
                    size="small"
                    :prepend-icon="closed ? 'mdi-check-circle' : 'mdi-progress-clock'"
                    style="cursor: pointer"
                    @click="$emit('toggle-closed')"
                >
                    {{ closed ? t('FakturaView.faktura.statusClosed') : t('FakturaView.faktura.statusOpen') }}
                </v-chip>
                <v-btn
                    variant="tonal"
                    prepend-icon="mdi-close"
                    @click="$emit('close')"
                >
                    {{ t('FakturaView.common.close') }}
                </v-btn>
            </div>
        </v-card-text>
    </v-card>
</template>

<script>
// src/core/views/faktura/components/action.bar.component.vue

import { defineComponent, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VoiceInputButton from '@/core/components/voice-input-button.vue'

export default defineComponent({
    name: 'ActionBarComponent',
    components: { VoiceInputButton },

    props: {
        printerList: {
            type: Array,
            default: () => []
        },
        templateList: {
            type: Array,
            default: () => []
        },
        selectedTemplateId: {
            type: [Number, String],
            default: null
        },
        selectedPrinter: {
            type: Object,
            default: null
        },
        fakturaType: {
            type: String,
            default: 'invoice'
        },
        closed: {
            type: Boolean,
            default: false
        },
        showClosed: {
            type: Boolean,
            default: false
        },
        showVehicleButton: {
            type: Boolean,
            default: false
        },
        showVehicleVoice: {
            type: Boolean,
            default: false
        },
        showSpecialButton: {
            type: Boolean,
            default: false
        },
        showDisplayButton: {
            type: Boolean,
            default: false
        },
        showDhlButton: {
            type: Boolean,
            default: false
        },
        compactView: {
            type: Boolean,
            default: false
        },
        hasCustomer: {
            type: Boolean,
            default: true
        },
        aagLoading: {
            type: Boolean,
            default: false
        },
        aagConfigured: {
            type: Boolean,
            default: false
        },
        esiAvailable: {
            type: Boolean,
            default: false
        },
        gutmannAvailable: {
            type: Boolean,
            default: false
        },
        hgsAvailable: {
            type: Boolean,
            default: false
        },
        hgsLoading: {
            type: Boolean,
            default: false
        }
    },

    emits: [
        'toggle-compact',
        'close',
        'toggle-closed',
        'reuse',
        'create-quotation',
        'create-order',
        'create-delivery-order',
        'create-invoice',
        'create-credit-note',
        'cancel',
        'create-supplier-inquiry',
        'create-supplier-order',
        'create-complaint',
        'save-as-draft',
        'select-printer',
        'print',
        'pdf-preview',
        'send-email',
        'send-whatsapp',
        'select-template',
        'show-history',
        'set-followup',
        'voice-vehicle',
        'export-xinvoice',
        'delete',
        'open-vehicle',
        'import-silverdat',
        'open-aag',
        'open-esi',
        'open-gutmann',
        'open-hgs',
        'open-special',
        'create-dhl-label',
        'show-on-display'
    ],

    setup(props) {
        const { t } = useI18n()

        // Workflow-Optionen basierend auf Dokumenttyp

        // Angebot erstellen - bei Auftrag, Rechnung, Lieferschein
        const canCreateQuotation = computed(() => ['order', 'invoice', 'delivery_order'].includes(props.fakturaType))

        // Auftrag erstellen - bei Angebot, Rechnung, Lieferschein
        const canCreateOrder = computed(() => ['quotation', 'invoice', 'delivery_order'].includes(props.fakturaType))

        // Lieferschein erstellen - bei Auftrag
        const canCreateDeliveryOrder = computed(() => props.fakturaType === 'order')

        // Rechnung erstellen - bei Angebot, Auftrag, Lieferschein
        const canCreateInvoice = computed(() => ['quotation', 'order', 'delivery_order'].includes(props.fakturaType))

        // Gutschrift erstellen - bei Rechnung
        const canCreateCreditNote = computed(() => props.fakturaType === 'invoice')

        // Storno - nur bei Rechnung
        const canCancel = computed(() => props.fakturaType === 'invoice')

        // Lieferantenanfrage - bei Angebot, Auftrag
        const canCreateSupplierInquiry = computed(() => ['quotation', 'order'].includes(props.fakturaType))

        // Lieferantenauftrag - bei Auftrag
        const canCreateSupplierOrder = computed(() => props.fakturaType === 'order')

        // Reklamation - bei Rechnung, Lieferschein
        const canCreateComplaint = computed(() => ['invoice', 'delivery_order'].includes(props.fakturaType))

        // Entwurf speichern - bei Rechnung
        const canSaveAsDraft = computed(() => props.fakturaType === 'invoice')

        // Faktur-X / ZUGFeRD Export - bei Rechnung
        const canExportXInvoice = computed(() => props.fakturaType === 'invoice')

        // SilverDAT Import - bei Aufträgen mit LxCars
        const canImportSilverDAT = computed(() => props.fakturaType === 'order' && props.showVehicleButton)

        // AAG-Online - bei Aufträgen mit verknüpftem Fahrzeug, nur wenn konfiguriert
        const canOpenAag = computed(() => props.fakturaType === 'order' && props.showVehicleButton && props.aagConfigured)

        return {
            t,
            canCreateQuotation,
            canCreateOrder,
            canCreateDeliveryOrder,
            canCreateInvoice,
            canCreateCreditNote,
            canCancel,
            canCreateSupplierInquiry,
            canCreateSupplierOrder,
            canCreateComplaint,
            canSaveAsDraft,
            canExportXInvoice,
            canImportSilverDAT,
            canOpenAag
        }
    }
})
</script>

<style scoped>
/* ============================================
   ACTION BAR
   ============================================ */

.action-bar {
    background-color: #f5f5f5;
    border-radius: 8px;
}

.action-bar__content {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 12px 16px !important;
}

.action-bar__left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.action-bar__right {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Responsive: Auf kleinen Bildschirmen untereinander */
@media (max-width: 960px) {
    .action-bar__content {
        flex-direction: column;
        align-items: stretch;
    }

    .action-bar__left,
    .action-bar__right {
        justify-content: center;
    }

    .action-bar__left .v-divider {
        display: none;
    }
}
</style>