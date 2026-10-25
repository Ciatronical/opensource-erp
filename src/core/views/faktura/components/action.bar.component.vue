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

                <!-- Email (Badge: schon so oft versendet) -->
                <v-tooltip location="bottom" :text="sentEmailCount ? t('FakturaView.faktura.sent.tooltipEmail', { n: sentEmailCount }) : t('FakturaView.common.email')">
                    <template #activator="{ props: tip }">
                        <v-badge :model-value="sentEmailCount > 0" :content="sentEmailCount" color="success" offset-x="4" offset-y="4">
                            <v-btn
                                v-bind="tip"
                                variant="tonal"
                                color="info"
                                icon="mdi-email"
                                size="small"
                                :disabled="!hasCustomer"
                                @click="$emit('send-email')"
                            />
                        </v-badge>
                    </template>
                </v-tooltip>

                <!-- WhatsApp (Badge: schon so oft versendet) -->
                <v-tooltip location="bottom" :text="sentWhatsappCount ? t('FakturaView.faktura.sent.tooltipWhatsapp', { n: sentWhatsappCount }) : 'WhatsApp'">
                    <template #activator="{ props: tip }">
                        <v-badge :model-value="sentWhatsappCount > 0" :content="sentWhatsappCount" color="success" offset-x="4" offset-y="4">
                            <v-btn
                                v-bind="tip"
                                variant="tonal"
                                color="green-darken-1"
                                icon="mdi-whatsapp"
                                size="small"
                                :disabled="!hasCustomer"
                                @click="$emit('send-whatsapp')"
                            />
                        </v-badge>
                    </template>
                </v-tooltip>

                <!-- Post (Hybridbrief; Badge: schon so oft eingeliefert) -->
                <v-tooltip location="bottom" :text="sentPostCount ? t('FakturaView.faktura.sent.tooltipPost', { n: sentPostCount }) : t('FakturaView.faktura.sendPost')">
                    <template #activator="{ props: tip }">
                        <v-badge :model-value="sentPostCount > 0" :content="sentPostCount" color="success" offset-x="4" offset-y="4">
                            <v-btn
                                v-bind="tip"
                                variant="tonal"
                                color="deep-purple"
                                icon="mdi-mailbox-up-outline"
                                size="small"
                                :disabled="!hasCustomer"
                                @click="$emit('send-post')"
                            />
                        </v-badge>
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

                <!-- Gutachten-Import (nur bei Aufträgen mit LxCars) – fremde Gutachten als PDF/Foto -->
                <v-tooltip v-if="canImportGutachten" location="bottom" :text="t('FakturaView.faktura.importGutachten')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="deep-orange"
                            icon="mdi-file-pdf-box"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('import-gutachten')"
                        />
                    </template>
                </v-tooltip>

                <!-- Weroni Rechtsprüfung des Gutachtens (nur bei Aufträgen mit LxCars) -->
                <v-tooltip v-if="canImportGutachten" location="bottom" :text="t('FakturaView.faktura.weroniReview')">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="deep-purple"
                            icon="mdi-scale-balance"
                            size="small"
                            :disabled="!hasCustomer"
                            @click="$emit('weroni-review')"
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

                <!-- Verlauf (Belegchronik): wer hat wann was geändert -->
                <v-tooltip location="bottom" :text="historyTooltip">
                    <template #activator="{ props: tip }">
                        <v-btn
                            v-bind="tip"
                            variant="tonal"
                            color="blue-grey"
                            icon="mdi-history"
                            size="small"
                            :disabled="!historyEnabled"
                            @click="$emit('show-history')"
                        />
                    </template>
                </v-tooltip>

                <!-- Wiedervorlage: ohne offene direkt anlegen, sonst Liste mit Erledigt/Bearbeiten.
                     Badge = offene Wiedervorlagen, rot sobald eine heute fällig oder überfällig ist -->
                <v-menu location="bottom" :disabled="!followUps.length">
                    <template #activator="{ props: menu }">
                        <v-tooltip location="bottom" :text="followUps.length ? t('FakturaView.faktura.followUpOpen', { n: followUps.length }) : t('FakturaView.faktura.followUp')">
                            <template #activator="{ props: tip }">
                                <v-badge :model-value="followUps.length > 0" :content="followUps.length" :color="followUpDue ? 'error' : 'primary'" offset-x="4" offset-y="4">
                                    <v-btn
                                        v-bind="mergeProps(menu, tip)"
                                        variant="tonal"
                                        color="amber-darken-3"
                                        icon="mdi-bell-ring-outline"
                                        size="small"
                                        :disabled="!followUpEnabled"
                                        @click="!followUps.length && $emit('set-followup')"
                                    />
                                </v-badge>
                            </template>
                        </v-tooltip>
                    </template>
                    <v-list density="compact" min-width="320" max-width="440">
                        <v-list-subheader>{{ t('FakturaView.faktura.followUpOpen', { n: followUps.length }) }}</v-list-subheader>
                        <v-list-item
                            v-for="fu in followUps"
                            :key="fu.id"
                            :title="fu.subject"
                            :subtitle="followUpAssignees(fu)"
                            @click="$emit('edit-followup', fu)"
                        >
                            <template #prepend>
                                <v-chip size="small" variant="tonal" label class="me-3" :color="followUpColor(fu)">
                                    {{ formatFollowUpDate(fu.follow_up_date) }}
                                </v-chip>
                            </template>
                            <template #append>
                                <v-btn
                                    icon="mdi-check"
                                    size="small"
                                    variant="text"
                                    color="success"
                                    :title="t('FakturaView.faktura.followUpDone')"
                                    @click.stop="$emit('done-followup', fu)"
                                />
                            </template>
                        </v-list-item>
                        <v-divider class="my-1" />
                        <v-list-item prepend-icon="mdi-plus" :title="t('FakturaView.faktura.followUpNew')" @click="$emit('set-followup')" />
                    </v-list>
                </v-menu>

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

import { defineComponent, computed, mergeProps } from 'vue'
import { useI18n } from 'vue-i18n'
import VoiceInputButton from '@/core/components/voice-input-button.vue'
import { formatRelativeTime } from '@/core/utils/dateFormatter.js'

export default defineComponent({
    name: 'ActionBarComponent',
    components: { VoiceInputButton },

    props: {
        /** Anzahl bisheriger Versendungen je Kanal (Badge an den Versand-Buttons) */
        sentEmailCount: {
            type: Number,
            default: 0
        },
        sentWhatsappCount: {
            type: Number,
            default: 0
        },
        sentPostCount: {
            type: Number,
            default: 0
        },
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
        showGutachtenButton: {
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
        hgsAvailable: {
            type: Boolean,
            default: false
        },
        hgsLoading: {
            type: Boolean,
            default: false
        },
        /** Offene Wiedervorlagen dieses Belegs (getFakturaData → follow_ups) */
        followUps: {
            type: Array,
            default: () => []
        },
        /** Wiedervorlage erst, wenn der Beleg gespeichert ist (braucht die ID) */
        followUpEnabled: {
            type: Boolean,
            default: false
        },
        /** Verlauf erst bei gespeichertem Beleg */
        historyEnabled: {
            type: Boolean,
            default: false
        },
        /** Letzte Änderung aus der Belegchronik (getFakturaData → last_change) */
        lastChange: {
            type: Object,
            default: null
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
        'send-post',
        'select-template',
        'show-history',
        'set-followup',
        'edit-followup',
        'done-followup',
        'voice-vehicle',
        'export-xinvoice',
        'delete',
        'open-vehicle',
        'import-silverdat',
        'import-gutachten',
        'weroni-review',
        'open-aag',
        'open-hgs',
        'open-special',
        'create-dhl-label',
        'show-on-display'
    ],

    setup(props) {
        const { t, locale } = useI18n()

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

        // Gutachten-Import - bei Aufträgen mit LxCars, auch ohne verknüpftes Fahrzeug
        // (das Fahrzeug entsteht aus dem Gutachten)
        const canImportGutachten = computed(() => props.fakturaType === 'order' && props.showGutachtenButton)

        // AAG-Online - bei Aufträgen mit verknüpftem Fahrzeug, nur wenn konfiguriert
        const canOpenAag = computed(() => props.fakturaType === 'order' && props.showVehicleButton && props.aagConfigured)

        // Wiedervorlagen: Fälligkeit gegen das lokale Datum (YYYY-MM-DD lässt sich als Text vergleichen)
        const todayIso = () => {
            const d = new Date()
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
        }
        const followUpDue = computed(() => props.followUps.some(fu => String(fu.follow_up_date) <= todayIso()))
        function followUpColor(fu) {
            const date = String(fu.follow_up_date)
            const today = todayIso()
            if (date < today) return 'error'
            if (date === today) return 'warning'
            return 'primary'
        }
        function formatFollowUpDate(value) {
            const [y, m, d] = String(value || '').split('-').map(Number)
            if (!y || !m || !d) return value || ''
            return new Date(y, m - 1, d).toLocaleDateString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric' })
        }
        // Tooltip der Verlauf-Taste: wer zuletzt geändert hat — hilfreich, wenn mehrere am Beleg arbeiten
        const historyTooltip = computed(() => {
            const lc = props.lastChange
            if (!lc || !lc.itime) return t('FakturaView.history.title')
            // Wie in der Chronik: automatisch, Shop, außerhalb von OSERP oder unbekannt
            const src = lc.source ? String(lc.source) : ''
            const name = lc.employee_name
                || (src.startsWith('cron') ? t('FakturaView.history.automatic')
                    : src === 'portal' ? t('FakturaView.history.source.portal')
                    : !src ? t('FakturaView.history.external')
                    : t('FakturaView.history.unknownEmployee'))
            return t('FakturaView.history.title') + ' · ' + t('FakturaView.history.lastChange', { time: formatRelativeTime(lc.itime, locale.value), name })
        })

        function followUpAssignees(fu) {
            return (fu.assigned_employees || []).map(e => e.employee_name).filter(Boolean).join(', ')
        }

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
            canImportGutachten,
            canOpenAag,
            mergeProps,
            followUpDue,
            followUpColor,
            formatFollowUpDate,
            followUpAssignees,
            historyTooltip
        }
    }
})
</script>

<style scoped>
/* ============================================
   ACTION BAR
   ============================================ */

.action-bar {
    background-color: var(--oserp-surface-soft);
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

/* Responsive: Auf kleinen Bildschirmen untereinander
   (unterhalb des Vuetify-Breakpoints md, seit Vuetify 4: 840px) */
@media (max-width: 839.98px) {
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