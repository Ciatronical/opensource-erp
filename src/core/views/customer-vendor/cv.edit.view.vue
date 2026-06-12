<!-- src/core/views/cv/cv.edit.view.vue -->

<template>
    <NavbarView />
    <MessagesView :messages="displayMessages" />
    <v-container class="pt-2 px-2 px-sm-4" fluid>
        <h1 class="pb-2 text-h5 text-sm-h4 d-flex align-center flex-wrap ga-2">
            <span>
                {{ isNewMode
                    ? (entitySrc === 'V' ? t('CustomerVendorEditView.titleNewVendor') : t('CustomerVendorEditView.titleNewCustomer'))
                    : (entitySrc === 'V' ? t('CustomerVendorEditView.titleEditVendor') : t('CustomerVendorEditView.titleEditCustomer'))
                }}
                <span v-if="!isNewMode"> - {{ cvData.customernumber || cvData.vendornumber }} {{ cvData.name }}</span>
            </span>
            <v-chip v-if="saving" color="orange" size="small" variant="flat">
                {{ t('CustomerVendorEditView.messages.saving') }}
            </v-chip>
            <v-chip v-else-if="saved" color="success" size="small" variant="flat">
                {{ t('CustomerVendorEditView.messages.savedShort') }}
            </v-chip>
        </h1>
        <action-bar
            @save-and-ar-transaction="saveAndArTransaction"
            @save-and-invoice="saveAndInvoice"
            @save-and-order="saveAndOrder"
            @save-and-quotation="saveAndQuotation"
            @delete="deleteCustomer"
            @show-history="showHistory"
            @close="closeView"
        />
        <v-alert v-if="autoSaveWarning" type="warning" variant="tonal" density="compact" class="mb-2" closable>
            {{ autoSaveWarning }}
        </v-alert>
        <v-sheet elevation="2">
            <v-row no-gutters>
                <!-- Linke Seite: Vertikale Tab-Liste -->
                <v-col cols="12" md="3" lg="2">
                    <v-list density="compact" class="tabs-list">
                        <v-list-item
                            value="billing"
                            :active="tab === 'billing'"
                            @click="tab = 'billing'"
                        >
                            <template #prepend>
                                <v-icon>mdi-receipt</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block">
                                {{ t('CustomerVendorEditView.tabs.billing') }}
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="additional-billing"
                            :active="tab === 'additional-billing'"
                            @click="tab = 'additional-billing'"
                        >
                            <template #prepend>
                                <v-icon>mdi-receipt-text-plus</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block d-flex align-center justify-space-between">
                                <span>{{ t('CustomerVendorEditView.tabs.additionalBilling') }}</span>
                                <v-badge
                                    v-if="billingAddresses.length > 0"
                                    :content="billingAddresses.length"
                                    color="primary"
                                    inline
                                />
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="bank"
                            :active="tab === 'bank'"
                            @click="tab = 'bank'"
                        >
                            <template #prepend>
                                <v-icon>mdi-bank</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block">
                                {{ t('CustomerVendorEditView.tabs.bank') }}
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="shipto"
                            :active="tab === 'shipto'"
                            @click="tab = 'shipto'"
                        >
                            <template #prepend>
                                <v-icon>mdi-truck-delivery</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block d-flex align-center justify-space-between">
                                <span>{{ t('CustomerVendorEditView.tabs.shipto') }}</span>
                                <v-badge
                                    v-if="shiptos.length > 0"
                                    :content="shiptos.length"
                                    color="primary"
                                    inline
                                />
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="contacts"
                            :active="tab === 'contacts'"
                            @click="tab = 'contacts'"
                        >
                            <template #prepend>
                                <v-icon>mdi-account-group</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block d-flex align-center justify-space-between">
                                <span>{{ t('CustomerVendorEditView.tabs.contacts') }}</span>
                                <v-badge
                                    v-if="contacts.length > 0"
                                    :content="contacts.length"
                                    color="primary"
                                    inline
                                />
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="deliveries"
                            :active="tab === 'deliveries'"
                            @click="tab = 'deliveries'"
                        >
                            <template #prepend>
                                <v-icon>mdi-package-variant</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block">
                                {{ t('CustomerVendorEditView.tabs.deliveries') }}
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="notes"
                            :active="tab === 'notes'"
                            @click="tab = 'notes'"
                        >
                            <template #prepend>
                                <v-icon>mdi-note-text</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block">
                                {{ t('CustomerVendorEditView.tabs.notes') }}
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="custom-vars"
                            :active="tab === 'custom-vars'"
                            @click="tab = 'custom-vars'"
                        >
                            <template #prepend>
                                <v-icon>mdi-variable</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block d-flex align-center justify-space-between">
                                <span>{{ t('CustomerVendorEditView.tabs.customVars') }}</span>
                                <v-badge
                                    v-if="customVars.length > 0"
                                    :content="customVars.length"
                                    color="primary"
                                    inline
                                />
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            v-if="!isNewMode"
                            value="files"
                            :active="tab === 'files'"
                            @click="tab = 'files'"
                        >
                            <template #prepend>
                                <v-icon>mdi-folder-open</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block">
                                {{ t('CustomerVendorEditView.tabs.files') }}
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="price-rules"
                            :active="tab === 'price-rules'"
                            @click="tab = 'price-rules'"
                        >
                            <template #prepend>
                                <v-icon>mdi-tag-multiple</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block">
                                {{ t('CustomerVendorEditView.tabs.priceRules') }}
                            </v-list-item-title>
                        </v-list-item>
                        <v-list-item
                            value="turnover"
                            :active="tab === 'turnover'"
                            @click="tab = 'turnover'"
                        >
                            <template #prepend>
                                <v-icon>mdi-chart-line</v-icon>
                            </template>
                            <v-list-item-title class="d-none d-md-block">
                                {{ t('CustomerVendorEditView.tabs.turnover') }}
                            </v-list-item-title>
                        </v-list-item>
                    </v-list>
                </v-col>
                <v-divider vertical class="d-none d-md-block"></v-divider>
                <v-divider class="d-md-none"></v-divider>
                <!-- Rechte Seite: Tab-Inhalte -->
                <v-col cols="12" md="9" lg="10"
                    @focusin.capture="onFocusIn"
                    @focusout.capture="onFocusOut"
                >
                    <v-window v-model="tab">
                        <v-window-item value="billing">
                            <billing-tab
                                v-model="cvData"
                                :entity-src="entitySrc"
                                :business-types="businessTypes"
                                :salesmen="salesmen"
                                :languages="languages"
                                :currencies="currencies"
                                :pricegroups="pricegroups"
                                :taxzones="taxzones"
                                :tax-included-options="taxIncludedOptions"
                                :zugferd-options="zugferdOptions"
                                :payment-terms="paymentTerms"
                                :delivery-terms="deliveryTerms"
                                :phone-numbers="cvPhoneNumbers"
                            />
                        </v-window-item>
                        <v-window-item value="additional-billing">
                            <additional-billing-tab
                                v-model="billingAddresses"
                                :expand-id="expandBillingAddressId"
                            />
                        </v-window-item>
                        <v-window-item value="bank">
                            <bank-tab v-model="cvData" />
                        </v-window-item>
                        <v-window-item value="shipto">
                            <shipto-tab
                                v-model="shiptos"
                                :expand-id="expandShiptoId"
                            />
                        </v-window-item>
                        <v-window-item value="contacts">
                            <contacts-tab v-model="contacts" />
                        </v-window-item>
                        <v-window-item value="deliveries">
                            <deliveries-tab />
                        </v-window-item>
                        <v-window-item value="notes">
                            <notes-tab />
                        </v-window-item>
                        <v-window-item value="custom-vars">
                            <custom-vars-tab v-model="customVars" />
                        </v-window-item>
                        <v-window-item v-if="!isNewMode" value="files">
                            <files-tab
                                :cv-id="id"
                                :src="entitySrc"
                                :cv-name="cvData.name || ''"
                            />
                        </v-window-item>
                        <v-window-item value="price-rules">
                            <price-rules-tab />
                        </v-window-item>
                        <v-window-item value="turnover">
                            <turnover-tab />
                        </v-window-item>
                    </v-window>
                </v-col>
            </v-row>
        </v-sheet>
    </v-container>

    <!-- Duplikat-Warnung Dialog -->
    <v-dialog v-model="showDuplicateDialog" max-width="560" persistent>
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon color="warning" class="mr-2">mdi-alert</v-icon>
                {{ t('CustomerVendorEditView.duplicate.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="x-small" variant="text" @click="showDuplicateDialog = false" />
            </v-card-title>
            <v-card-text>
                <!-- Exakt-Treffer -->
                <template v-if="duplicateExact.length">
                    <div class="font-weight-medium mb-2">{{ t('CustomerVendorEditView.duplicate.exactMatch') }}</div>
                    <v-list density="compact" class="mb-3">
                        <v-list-item v-for="dup in duplicateExact" :key="dup.src + '_' + dup.id" class="px-2">
                            <v-list-item-title>
                                <v-chip :color="dup.src === 'C' ? 'blue' : 'orange'" size="x-small" class="mr-1">
                                    {{ dup.src === 'C' ? t('CustomerVendorEditView.duplicate.customer') : t('CustomerVendorEditView.duplicate.vendor') }}
                                </v-chip>
                                {{ dup.name }}
                            </v-list-item-title>
                            <v-list-item-subtitle>{{ dup.street }}, {{ dup.zipcode }} {{ dup.city }}</v-list-item-subtitle>
                            <template #append>
                                <v-btn size="small" color="primary" variant="tonal" @click="navigateToDuplicate(dup)">
                                    {{ t('CustomerVendorEditView.duplicate.open') }}
                                </v-btn>
                            </template>
                        </v-list-item>
                    </v-list>
                </template>

                <!-- Teil-Treffer -->
                <template v-if="duplicatePartial.length">
                    <div class="font-weight-medium mb-2">{{ t('CustomerVendorEditView.duplicate.partialMatch') }}</div>
                    <v-list density="compact" class="mb-3">
                        <v-list-item v-for="dup in duplicatePartial" :key="dup.src + '_' + dup.id" class="px-2">
                            <v-list-item-title>
                                <v-chip :color="dup.src === 'C' ? 'blue' : 'orange'" size="x-small" class="mr-1">
                                    {{ dup.src === 'C' ? t('CustomerVendorEditView.duplicate.customer') : t('CustomerVendorEditView.duplicate.vendor') }}
                                </v-chip>
                                {{ dup.name }}
                            </v-list-item-title>
                            <v-list-item-subtitle>{{ dup.street }}, {{ dup.zipcode }} {{ dup.city }}</v-list-item-subtitle>
                            <template #append>
                                <v-btn size="small" color="primary" variant="tonal" @click="navigateToDuplicate(dup)">
                                    {{ t('CustomerVendorEditView.duplicate.open') }}
                                </v-btn>
                            </template>
                        </v-list-item>
                    </v-list>
                </template>
            </v-card-text>
            <v-card-actions>
                <v-btn variant="outlined" @click="cancelDuplicateDialog">
                    {{ t('CustomerVendorEditView.duplicate.cancel') }}
                </v-btn>
                <v-spacer />
                <v-btn color="warning" variant="flat" @click="proceedSaveCV">
                    {{ t('CustomerVendorEditView.duplicate.createAnyway') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import MessagesView from '@/core/components/messages/messages.view.vue'
import BillingTab from './tabs/billing.tab.vue'
import AdditionalBillingTab from './tabs/additional-billing.tab.vue'
import BankTab from './tabs/bank.tab.vue'
import ShiptoTab from './tabs/shipto.tab.vue'
import ContactsTab from './tabs/contacts.tab.vue'
import CustomVarsTab from './tabs/custom-vars.tab.vue'
import DeliveriesTab from './tabs/deliveries.tab.vue'
import NotesTab from './tabs/notes.tab.vue'
const FilesTab = defineAsyncComponent(() => import('./tabs/files.tab.vue'))
import PriceRulesTab from './tabs/price-rules.tab.vue'
import TurnoverTab from './tabs/turnover.tab.vue'
import WhatsappTab from './tabs/whatsapp.tab.vue'
import ActionBar from './components/action.bar.vue'

export default {
    name: 'CustomerVendorEditView',
    components: {
        NavbarView,
        MessagesView,
        BillingTab,
        AdditionalBillingTab,
        BankTab,
        ShiptoTab,
        ContactsTab,
        CustomVarsTab,
        DeliveriesTab,
        NotesTab,
        FilesTab,
        PriceRulesTab,
        TurnoverTab,
        WhatsappTab,
        ActionBar,
    },
    props: {
        id: {
            type: [String, Number],
            default: null,
        },
        src: {
            type: String,
            default: 'C',
        },
    },
    setup(props) {
        const id = ref(props.id)
        const oserpData = oserpStore()
        const { t } = useI18n()
        const route = useRoute()
        const router = useRouter()
        const displayMessages = ref([])
        const isNewMode = ref(!props.id)
        const entitySrc = ref(props.src)
        const saving = ref(false)
        const saved = ref(false)
        const savedCvId = ref(null)
        let savedTimer = null

        // Tab aus Query-Parameter oder Default 'billing'
        const tab = ref(route.query.tab || 'billing')

        // IDs für aufzuklappende Adressen aus Query-Parameter
        const expandShiptoId = ref(route.query.shiptoId ? Number(route.query.shiptoId) : null)
        const expandBillingAddressId = ref(route.query.billingAddressId ? Number(route.query.billingAddressId) : null)

        const cvData = ref({})
        const shiptos = ref([])
        const billingAddresses = ref([])
        const customVars = ref([])

        // Duplikat-Warnung State
        const showDuplicateDialog = ref(false)
        const duplicateExact = ref([])
        const duplicatePartial = ref([])
        let duplicateCheckDone = false
        const contacts = ref([])

        // Warnung wenn Auto-Save-Bedingungen nicht erfüllt
        const autoSaveWarning = computed(() => {
            if (!isNewMode.value && savedCvId.value) return null
            const missing = []
            if (!(cvData.value.name || '').trim()) missing.push(t('CustomerVendorEditView.fields.name'))
            if (!(cvData.value.zipcode || '').trim()) missing.push(t('CustomerVendorEditView.fields.zipcode'))
            if (!(cvData.value.street || '').trim()) missing.push(t('CustomerVendorEditView.fields.street'))
            if (entitySrc.value === 'C' && businessTypes.value.length > 1 && !cvData.value.business_id) missing.push(t('CustomerVendorEditView.fields.business_type'))
            if (missing.length === 0) return null
            return t('CustomerVendorEditView.messages.autoSaveWarning', { fields: missing.join(', ') })
        })

        // Auto-Save State
        let previousCustomerVendor = null
        let initialLoaded = false
        let textInputFocused = false
        let hasPendingChanges = false
        let saveTimeout = null

        // Dropdown Options aus dem Store
        const businessTypes = computed(() => {
            return (oserpData.session?.company_config?.business_types || []).map(item => ({
                title: item.description,
                value: item.id
            }))
        })

        const salesmen = computed(() => {
            return (oserpData.session?.company_config?.employees || []).map(item => ({
                title: item.name,
                value: item.id
            }))
        })

        const languages = computed(() => {
            return (oserpData.session?.company_config?.languages || []).map(item => ({
                title: item.description,
                value: item.id
            }))
        })

        const currencies = computed(() => {
            return (oserpData.session?.company_config?.currencies || []).map(item => ({
                title: item.name,
                value: item.id
            }))
        })

        const pricegroups = computed(() => {
            return (oserpData.session?.company_config?.pricegroups || []).map(item => ({
                title: item.pricegroup,
                value: item.id
            }))
        })

        const taxzones = computed(() => {
            const storeData = oserpData.session?.company_config?.tax_zones || []

            return storeData.map(item => ({
                title: item.description,
                value: item.id
            }))
        })

        const taxIncludedOptions = ref([
            { title: 'Verwende Benutzereinstellung', value: '' },
            { title: 'Ja', value: 1 },
            { title: 'Nein', value: 0 },
        ])

        const zugferdOptions = computed(() => [
            { title: t('CustomerVendorEditView.zugferd.option_default'),       value: -1 },
            { title: t('CustomerVendorEditView.zugferd.option_off'),           value: 0 },
            { title: t('CustomerVendorEditView.zugferd.option_zugferd'),       value: 1 },
            { title: t('CustomerVendorEditView.zugferd.option_zugferd_test'),  value: 2 },
            { title: t('CustomerVendorEditView.zugferd.option_xrechnung'),     value: 3 },
            { title: t('CustomerVendorEditView.zugferd.option_xrechnung_test'),value: 4 },
        ])

        const paymentTerms = computed(() => {
            return (oserpData.session?.company_config?.payment_terms || []).map(item => ({
                title: item.description,
                value: item.id
            }))
        })

        const deliveryTerms = computed(() => {
            return (oserpData.session?.company_config?.delivery_terms || []).map(item => ({
                title: item.description,
                value: item.id
            }))
        })

        // Alle Telefonnummern des Kunden/Lieferanten (Profil + Kontakte) für WhatsApp
        const cvPhoneNumbers = computed(() => {
            const nums = []
            const addNum = (num) => {
                const trimmed = (num || '').trim()
                if (trimmed && !nums.includes(trimmed)) nums.push(trimmed)
            }
            // Profil-Telefonnummern
            addNum(cvData.value.phone)
            // phone_numbers JSONB Array (falls vorhanden)
            if (Array.isArray(cvData.value.phone_numbers)) {
                cvData.value.phone_numbers.forEach(pn => addNum(typeof pn === 'string' ? pn : pn.number))
            }
            // Kontakt-Telefonnummern
            contacts.value.forEach(c => {
                addNum(c.cp_phone1)
                addNum(c.cp_phone2)
                addNum(c.cp_mobile1)
                addNum(c.cp_mobile2)
            })
            return nums
        })


        const updateLocalData = () => {
            if (oserpData.customer_vendor && oserpData.customer_vendor.profile) {
                cvData.value = JSON.parse(JSON.stringify(oserpData.customer_vendor.profile))
                shiptos.value = JSON.parse(JSON.stringify(oserpData.customer_vendor.shiptos || []))
                billingAddresses.value = JSON.parse(JSON.stringify(oserpData.customer_vendor.additional_billing_addresses || []))
                contacts.value = JSON.parse(JSON.stringify(oserpData.customer_vendor.contacts || []))
                customVars.value = JSON.parse(JSON.stringify(oserpData.customer_vendor.custom_vars || []))
            }
        }

        // Watch auf Store - DEAKTIVIERT weil es nach dem Speichern die Änderungen überschreibt
        // updateLocalData() wird explizit nach fetchCustomerOrVendor() aufgerufen
        // watch(() => oserpData.customer_vendor, () => {
        //     updateLocalData()
        // }, { deep: true })

        // Auto-Save Watcher
        watch([cvData, shiptos, billingAddresses, contacts, customVars], onDataChange, { deep: true })

        // Auto-Save Hilfsfunktionen
        function isTextInput(el) {
            if (!el) return false
            const tag = el.tagName?.toLowerCase()
            if (tag === 'textarea') return true
            if (tag === 'input') {
                const type = (el.type || 'text').toLowerCase()
                return ['text', 'number', 'password', 'email', 'url', 'search', 'tel', 'date'].includes(type)
            }
            return false
        }

        function onFocusIn(event) {
            if (isTextInput(event.target)) {
                textInputFocused = true
            }
        }

        function onFocusOut(event) {
            if (isTextInput(event.target)) {
                textInputFocused = false
                if (hasPendingChanges) {
                    hasPendingChanges = false
                    triggerAutoSave()
                }
            }
        }

        function onDataChange() {
            if (!initialLoaded) return
            // Bei Neuanlage sofort triggern — der Pending-Mechanismus ist
            // nur fuer Bestandsdatensaetze gedacht. Sonst blockiert das interne
            // Input des v-select (Kundentyp) den Save bis zum Blur.
            const cvIdNow = id.value || savedCvId.value
            if (!cvIdNow) {
                triggerAutoSave()
                return
            }
            if (textInputFocused) {
                hasPendingChanges = true
                return
            }
            triggerAutoSave()
        }

        function triggerAutoSave() {
            if (!(cvData.value.name || '').trim()) return
            if (!(cvData.value.zipcode || '').trim()) return
            // Bei Neuanlage zusaetzlich street verlangen, damit der
            // Duplikat-Check (name + street + zipcode) greifen kann
            const isNew = !(id.value || savedCvId.value)
            if (isNew && !(cvData.value.street || '').trim()) return
            if (entitySrc.value === 'C' && businessTypes.value.length > 1 && !cvData.value.business_id) return
            if (saveTimeout) clearTimeout(saveTimeout)
            saveTimeout = setTimeout(() => {
                saveCV()
            }, 500)
        }

        // Action Functions
        const saveCV = async () => {
            if (saving.value) return

            // Duplikat-Check vor dem ersten Speichern (Neuanlage)
            const cvId = id.value || savedCvId.value
            if (!cvId && !duplicateCheckDone) {
                const name = (cvData.value.name || '').trim()
                const street = (cvData.value.street || '').trim()
                const zipcode = (cvData.value.zipcode || '').trim()
                if (name && street && zipcode) {
                    try {
                        const result = await oserpData.checkDuplicateCV(name, street, zipcode, 0, entitySrc.value)
                        if (result.exact?.length || result.partial?.length) {
                            duplicateExact.value = result.exact || []
                            duplicatePartial.value = result.partial || []
                            showDuplicateDialog.value = true
                            return
                        }
                    } catch (e) {
                        console.warn('Duplicate check failed, proceeding with save:', e)
                    }
                }
                duplicateCheckDone = true
            }

            saving.value = true
            saved.value = false
            if (savedTimer) { clearTimeout(savedTimer); savedTimer = null }

            try {
                if (oserpData.customer_vendor) {
                    oserpData.customer_vendor.profile = { ...cvData.value }
                    // Stelle sicher, dass src gesetzt ist
                    if (!oserpData.customer_vendor.profile.src) {
                        oserpData.customer_vendor.profile.src = entitySrc.value
                    }

                    // Setze trans_id und module für alle shiptos
                    oserpData.customer_vendor.shiptos = shiptos.value.map(shipto => ({
                        ...shipto,
                        trans_id: cvId,
                        module: 'CT'
                    }))

                    // Setze customer_id für alle additional_billing_addresses
                    oserpData.customer_vendor.additional_billing_addresses = billingAddresses.value.map(addr => ({
                        ...addr,
                        customer_id: cvId
                    }))

                    oserpData.customer_vendor.contacts = contacts.value
                    oserpData.customer_vendor.custom_vars = customVars.value
                }

                const result = await oserpData.saveCV()

                // Neu angelegt → URL aktualisieren (ohne Component-Remount)
                if ((isNewMode.value || !cvId) && result?.payload?.new_id) {
                    const newId = result.payload.new_id
                    savedCvId.value = newId
                    isNewMode.value = false
                    id.value = String(newId)

                    // URL aktualisieren ohne Navigation (wie bei Car-Edit)
                    const editRouteName = entitySrc.value === 'V' ? 'vendor-edit' : 'customer-edit'
                    const resolved = router.resolve({ name: editRouteName, params: { id: newId } })
                    window.history.replaceState(history.state, '', resolved.href)

                    // Daten neu laden um IDs zu aktualisieren
                    initialLoaded = false
                    await oserpData.fetchCustomerOrVendor(newId, entitySrc.value)
                    updateLocalData()
                    await nextTick()
                    initialLoaded = true
                } else {
                    // Daten neu laden damit IDs von neuen Adressen aktualisiert werden
                    initialLoaded = false
                    await oserpData.fetchCustomerOrVendor(cvId, cvData.value.src || entitySrc.value)
                    updateLocalData()
                    await nextTick()
                    initialLoaded = true
                }

                // Visuelles Feedback
                saving.value = false
                saved.value = true
                savedTimer = setTimeout(() => { saved.value = false }, 2000)
            } catch (error) {
                console.error('Error saving Customer/Vendor:', error)
                saving.value = false
                const msg = {
                    type: 'error',
                    title: error?.message || t('CustomerVendorEditView.messages.saveError')
                }
                displayMessages.value.push(msg)
                setTimeout(() => {
                    const idx = displayMessages.value.indexOf(msg)
                    if (idx !== -1) displayMessages.value.splice(idx, 1)
                }, 8000)
            }
        }

        // Duplikat-Dialog: Trotzdem anlegen
        const proceedSaveCV = () => {
            showDuplicateDialog.value = false
            duplicateCheckDone = true
            saveCV()
        }

        // Duplikat-Dialog: Vorhandenen Datensatz oeffnen
        const navigateToDuplicate = (dup) => {
            showDuplicateDialog.value = false
            const routeName = dup.src === 'V' ? 'vendor-edit' : 'customer-edit'
            router.push({ name: routeName, params: { id: dup.id } })
        }

        // Duplikat-Dialog: Abbrechen — bei genau einem Treffer
        // direkt zum vorhandenen Datensatz navigieren
        const cancelDuplicateDialog = () => {
            const all = [...duplicateExact.value, ...duplicatePartial.value]
            if (all.length === 1) {
                navigateToDuplicate(all[0])
            } else {
                showDuplicateDialog.value = false
            }
        }

        const closeView = async () => {
            // Duplikat-Dialog offen? Nicht ueberspringen, Dialog bleibt vorne.
            if (showDuplicateDialog.value) return

            // Bei Neuanlage mit ausreichend Daten den Duplikat-Check
            // synchron nachholen, falls der Auto-Save noch nicht gelaufen ist.
            const cvId = id.value || savedCvId.value
            if (!cvId && !duplicateCheckDone) {
                const name = (cvData.value.name || '').trim()
                const street = (cvData.value.street || '').trim()
                const zipcode = (cvData.value.zipcode || '').trim()
                if (name && street && zipcode) {
                    try {
                        const result = await oserpData.checkDuplicateCV(
                            name, street, zipcode, 0, entitySrc.value
                        )
                        if (result.exact?.length || result.partial?.length) {
                            duplicateExact.value = result.exact || []
                            duplicatePartial.value = result.partial || []
                            showDuplicateDialog.value = true
                            return
                        }
                    } catch (e) {
                        // Bei Check-Fehler regulaer schliessen
                    }
                }
            }

            router.back()
        }

        const saveAndArTransaction = async () => {
            await saveCV()
        }

        const saveAndInvoice = async () => {
            await saveCV()
        }

        const saveAndOrder = async () => {
            await saveCV()
        }

        const saveAndQuotation = async () => {
            await saveCV()
        }

        const deleteCustomer = async () => {
            if (confirm(t('CustomerVendorEditView.actions.confirmDelete'))) {
                try {
                    await oserpData.deleteCustomer(id.value)
                } catch (error) {
                    const msg = {
                        type: 'error',
                        title: t('CustomerVendorEditView.messages.deleteError')
                    }
                    displayMessages.value.push(msg)
                    setTimeout(() => {
                        const idx = displayMessages.value.indexOf(msg)
                        if (idx !== -1) displayMessages.value.splice(idx, 1)
                    }, 8000)
                }
            }
        }

        const showHistory = () => {
            // Show history modal
        }

        // ── Pending Changes bei Navigation speichern (sendBeacon) ──
        function flushPendingChanges() {
            const cvId = id.value || savedCvId.value || cvData.value.id
            if (!initialLoaded) return
            if (!hasPendingChanges && !saveTimeout) return
            if (!cvId) return

            if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null }
            hasPendingChanges = false

            const profile = { ...cvData.value }
            if (!profile.src) profile.src = entitySrc.value

            const payload = {
                action: 'saveCV',
                profile: { ...profile, id: Number(cvId) },
                additional_billing_addresses: billingAddresses.value.map(addr => ({
                    ...addr,
                    customer_id: Number(cvId)
                })),
                contacts: contacts.value,
                shiptos: shiptos.value.map(shipto => ({
                    ...shipto,
                    trans_id: Number(cvId),
                    module: 'CT'
                })),
                custom_vars: customVars.value
            }
            navigator.sendBeacon(
                '/api/customer_vendor/',
                new Blob([JSON.stringify(payload)], { type: 'application/json' })
            )
        }

        const focusField = route.query.focus || null

        onMounted(async () => {
            if (isNewMode.value) {
                // Vorherigen Kunden sichern, damit er beim Abbrechen wiederhergestellt werden kann
                previousCustomerVendor = oserpData.customer_vendor || null

                // Defaults für Pflichtfelder aus company_config
                const defaultTaxzoneId = oserpData.session?.company_config?.tax_zones?.[0]?.id || null
                const defaultCurrencyId = oserpData.session?.company_config?.currencies?.[0]?.id || null

                // Leeres Profil für Neuanlage mit NOT NULL Defaults
                const profile = {
                    src: entitySrc.value,
                    taxzone_id: defaultTaxzoneId,
                    currency_id: defaultCurrencyId,
                    natural_person: false,
                    obsolete: false,
                    salesman_id: oserpData.session?.logged_in_employee?.id || null,
                }
                // create_zugferd_invoices existiert nur in der customer-Tabelle
                if (entitySrc.value === 'C') {
                    profile.create_zugferd_invoices = -1
                    // Businesstyp automatisch setzen wenn nur einer vorhanden
                    if (businessTypes.value.length === 1) {
                        profile.business_id = businessTypes.value[0].value
                    }
                }
                // Query-Parameter fuer Vorausfuellung (z.B. aus WhatsApp-Kontakt)
                // Query-Parameter fuer Vorausfuellung (z.B. aus WhatsApp-Kontakt)
                if (route.query.prefill_name) profile.name = route.query.prefill_name
                if (route.query.prefill_phone) profile.phone = route.query.prefill_phone
                if (route.query.prefill_email) profile.email = route.query.prefill_email

                oserpData.customer_vendor = {
                    profile,
                    shiptos: [],
                    additional_billing_addresses: [],
                    contacts: []
                }
                updateLocalData()
            } else {
                await oserpData.fetchCustomerOrVendor(id.value, entitySrc.value)
                updateLocalData()
            }
            await nextTick()
            initialLoaded = true
            window.addEventListener('beforeunload', flushPendingChanges)

            // Fokus auf gewünschtes Feld setzen (z.B. aus Faktura-View kommend)
            if (focusField) {
                await nextTick()
                const wrapper = document.querySelector(`[data-field="${focusField}"]`)
                const el = wrapper?.querySelector('input, textarea')
                if (el) {
                    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
                    setTimeout(() => el.focus(), 300)
                }
            }
        })

        onBeforeUnmount(() => {
            window.removeEventListener('beforeunload', flushPendingChanges)
            if (saveTimeout) clearTimeout(saveTimeout)
            if (savedTimer) clearTimeout(savedTimer)
            flushPendingChanges()

            // Neuanlage ohne Speichern abgebrochen → vorherigen Kunden wiederherstellen
            if (isNewMode.value && !savedCvId.value && previousCustomerVendor) {
                oserpData.customer_vendor = previousCustomerVendor
            }
        })

        return {
            id,
            isNewMode,
            entitySrc,
            saving,
            saved,
            displayMessages,
            tab,
            cvData,
            shiptos,
            billingAddresses,
            contacts,
            customVars,
            expandShiptoId,
            expandBillingAddressId,
            businessTypes,
            salesmen,
            languages,
            currencies,
            pricegroups,
            taxzones,
            taxIncludedOptions,
            zugferdOptions,
            paymentTerms,
            deliveryTerms,
            cvPhoneNumbers,
            saveCV,
            closeView,
            saveAndArTransaction,
            saveAndInvoice,
            saveAndOrder,
            saveAndQuotation,
            deleteCustomer,
            showHistory,
            onFocusIn,
            onFocusOut,
            autoSaveWarning,
            showDuplicateDialog,
            duplicateExact,
            duplicatePartial,
            proceedSaveCV,
            navigateToDuplicate,
            cancelDuplicateDialog,
            t,
        }
    },
}
</script>

<style scoped>
.tabs-list {
    border-right: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

/* Responsive Anpassung für kleinere Bildschirme */
@media (max-width: 960px) {
    .tabs-list {
        border-right: none;
    }
}
</style>