<!-- src/core/views/faktura/cards/customer.info.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-account</v-icon>
            {{ isVendor ? t('FakturaView.faktura.vendor') : t('FakturaView.faktura.customer') }}
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body">
            <v-row dense>
                <!-- Kunde/Lieferant mit Edit-Button -->
                <v-col cols="12" class="py-1">
                    <div class="d-flex align-center gap-1">
                        <v-autocomplete
                            v-model:search="customerSearch"
                            :model-value="modelValue.customer_id || modelValue.vendor_id"
                            @update:model-value="onCustomerSelect"
                            :label="isVendor ? t('FakturaView.faktura.vendor') : t('FakturaView.faktura.customer')"
                            :items="customerItems"
                            :loading="customerLoading"
                            item-title="name"
                            item-value="id"
                            :no-filter="true"
                            clearable
                            variant="outlined"
                            density="compact"
                            hide-details
                            autocomplete="off"
                            class="flex-grow-1"
                        />
                        <v-btn
                            icon
                            size="x-small"
                            variant="text"
                            color="primary"
                            :disabled="!customerId"
                            :title="t('FakturaView.faktura.editCustomer')"
                            @click="openCustomerEdit('billing')"
                        >
                            <v-icon size="small">mdi-pencil</v-icon>
                        </v-btn>
                    </div>
                </v-col>

            </v-row>
            <v-row dense :class="{ 'section-disabled': !hasCustomer }">
                <!-- Lieferadresse mit Edit-Button -->
                <v-col cols="12" class="py-1">
                    <div class="d-flex align-center gap-1">
                        <v-autocomplete
                            :model-value="modelValue.shipto_id"
                            @update:model-value="onFieldChange('shipto_id', $event)"
                            :label="t('FakturaView.faktura.deliveryAddress')"
                            :items="deliveryAddressList"
                            item-title="shiptoname"
                            item-value="shipto_id"
                            clearable
                            variant="outlined"
                            density="compact"
                            hide-details
                            class="flex-grow-1"
                        >
                            <template #item="{ item, props }">
                                <v-list-item
                                    v-bind="props"
                                    :title="item.raw.shiptoname"
                                    :subtitle="`${item.raw.shiptostreet || ''} ${item.raw.shiptozipcode || ''} ${item.raw.shiptocity || ''}`"
                                />
                            </template>
                        </v-autocomplete>
                        <v-btn
                            icon
                            size="x-small"
                            variant="text"
                            color="primary"
                            :disabled="!customerId"
                            :title="t('FakturaView.faktura.editDeliveryAddresses')"
                            @click="openCustomerEdit('shipto')"
                        >
                            <v-icon size="small">mdi-pencil</v-icon>
                        </v-btn>
                    </div>
                </v-col>

                <!-- Rechnungsadresse mit Edit-Button -->
                <v-col cols="12" class="py-1">
                    <div class="d-flex align-center gap-1">
                        <v-autocomplete
                            :model-value="modelValue.billing_address_id"
                            @update:model-value="onFieldChange('billing_address_id', $event)"
                            :label="t('FakturaView.faktura.billingAddress')"
                            :items="billingAddressList"
                            item-title="name"
                            item-value="id"
                            clearable
                            variant="outlined"
                            density="compact"
                            hide-details
                            class="flex-grow-1"
                        >
                            <template #item="{ item, props }">
                                <v-list-item
                                    v-bind="props"
                                    :title="item.raw.adr_code"
                                    :subtitle="`${item.raw.street || ''} ${item.raw.zipcode || ''} ${item.raw.city || ''}`"
                                />
                            </template>
                        </v-autocomplete>
                        <v-btn
                            icon
                            size="x-small"
                            variant="text"
                            color="primary"
                            :disabled="!customerId"
                            :title="t('FakturaView.faktura.editBillingAddresses')"
                            @click="openCustomerEdit('additional-billing')"
                        >
                            <v-icon size="small">mdi-pencil</v-icon>
                        </v-btn>
                    </div>
                </v-col>

                <!-- Telefon (Hauptnummer) -->
                <v-col cols="12" class="py-1" v-if="contactPhone1">
                    <div class="phone-entry rounded pa-2">
                        <div class="d-flex align-center gap-1">
                            <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-phone</v-icon>
                            <PhoneActionBar :phone="contactPhone1" :name="customerName" :show-whatsapp="true" whatsapp-tab @whatsapp="switchToWhatsAppTab" />
                            <v-spacer />
                            <v-btn
                                icon
                                size="x-small"
                                variant="text"
                                color="primary"
                                :disabled="!customerId"
                                :title="t('FakturaView.faktura.editCustomer')"
                                @click="openCustomerEdit('billing', 'phone')"
                            >
                                <v-icon size="small">mdi-pencil</v-icon>
                            </v-btn>
                        </div>
                        <div v-if="contactLabel" class="text-caption text-grey mt-1 ml-6">{{ contactLabel }}</div>
                    </div>
                </v-col>

                <!-- Zusätzliche Telefonnummern aus customer_ext/vendor_ext -->
                <v-col cols="12" class="py-1" v-for="(entry, idx) in phoneNumbers" :key="'pn-' + idx">
                    <div class="phone-entry rounded pa-2">
                        <div class="d-flex align-center gap-1">
                            <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-phone</v-icon>
                            <PhoneActionBar :phone="entry.number" :name="customerName" :show-whatsapp="true" whatsapp-tab @whatsapp="switchToWhatsAppTab" />
                        </div>
                        <div v-if="entry.label" class="text-caption text-grey mt-1 ml-6">{{ entry.label }}</div>
                    </div>
                </v-col>

                <!-- Fax (nur wenn vorhanden) -->
                <v-col cols="12" class="py-1" v-if="contactPhone2">
                    <div class="d-flex align-center gap-1">
                        <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-fax</v-icon>
                        <span class="text-body-2">{{ formatPhone(contactPhone2) }}</span>
                        <span class="text-caption text-grey ml-1">{{ t('FakturaView.faktura.phone2') }}</span>
                    </div>
                </v-col>

                <!-- E-Mail -->
                <v-col cols="12" class="py-1" v-if="contactEmail">
                    <div class="d-flex align-center gap-1">
                        <v-icon size="small" color="grey-darken-1" class="mr-1">mdi-email</v-icon>
                        <a :href="'mailto:' + contactEmail" class="text-decoration-none text-body-2">{{ contactEmail }}</a>
                        <v-spacer />
                        <v-btn
                            icon
                            size="x-small"
                            variant="text"
                            color="primary"
                            :disabled="!customerId"
                            :title="t('FakturaView.faktura.editCustomer')"
                            @click="openCustomerEdit('billing', 'email')"
                        >
                            <v-icon size="small">mdi-pencil</v-icon>
                        </v-btn>
                    </div>
                </v-col>

                <v-col cols="12" class="py-1" v-if="!isVendor">
                    <div class="d-flex align-center gap-1">
                        <v-text-field
                            :model-value="formatCurrency(creditLimit)"
                            :label="t('FakturaView.faktura.creditLimit')"
                            readonly
                            variant="outlined"
                            density="compact"
                            hide-details
                            autocomplete="off"
                            prepend-inner-icon="mdi-credit-card"
                            class="flex-grow-1"
                        />
                        <v-btn
                            icon
                            size="x-small"
                            variant="text"
                            color="primary"
                            :disabled="!customerId"
                            :title="t('FakturaView.faktura.editCustomer')"
                            @click="openCustomerEdit('billing', 'creditlimit')"
                        >
                            <v-icon size="small">mdi-pencil</v-icon>
                        </v-btn>
                    </div>
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>


<script>
// src/core/views/faktura/cards/customer.info.card.vue

import { defineComponent, computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { formatNumber } from '@/core/utils/numberFormat.js'
import { formatPhone } from '@/core/utils/phoneFormat.js'
import PhoneActionBar from '@/core/components/phone-action-bar.vue'
import axios from 'axios'

export default defineComponent({
    name: 'CustomerInfoCard',
    components: { PhoneActionBar },
    props: {
        modelValue: {
            type: Object,
            required: true
        },
        customerList: {
            type: Array,
            default: () => []
        },
        deliveryAddressList: {
            type: Array,
            default: () => []
        },
        billingAddressList: {
            type: Array,
            default: () => []
        },
        contactPhone1: {
            type: String,
            default: ''
        },
        contactPhone2: {
            type: String,
            default: ''
        },
        contactEmail: {
            type: String,
            default: ''
        },
        contactLabel: {
            type: String,
            default: ''
        },
        customerName: {
            type: String,
            default: ''
        },
        phoneNumbers: {
            type: Array,
            default: () => []
        },
        creditLimit: {
            type: Number,
            default: 0
        },
        isVendor: {
            type: Boolean,
            default: false
        },
        hasCustomer: {
            type: Boolean,
            default: true
        }
    },
    emits: ['update:modelValue', 'update:customer-id', 'field-change'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const router = useRouter()

        const customerSearch = ref('')
        const customerSearchResults = ref([])
        const customerLoading = ref(false)
        const lastSelectedCustomer = ref(null)
        let debounceTimer = null
        let justSelected = false

        const customerItems = computed(() => {
            const selectedId = props.modelValue.customer_id || props.modelValue.vendor_id
            const items = []

            // 1) Zuletzt ausgewaehlten Kunden immer einschliessen
            // eslint-disable-next-line eqeqeq
            if (lastSelectedCustomer.value && lastSelectedCustomer.value.id == selectedId) {
                items.push(lastSelectedCustomer.value)
            }

            // 2) Aus customerList (vom Parent), falls nicht schon drin
            if (selectedId) {
                // eslint-disable-next-line eqeqeq
                const fromList = props.customerList.find(c => c.id == selectedId)
                // eslint-disable-next-line eqeqeq
                if (fromList && !items.some(i => i.id == fromList.id)) {
                    items.push(fromList)
                }
            }

            // 3) Suchergebnisse dazu (Duplikate vermeiden)
            for (const r of customerSearchResults.value) {
                // eslint-disable-next-line eqeqeq
                if (!items.some(i => i.id == r.id)) {
                    items.push(r)
                }
            }

            return items
        })

        watch(customerSearch, (val) => {
            clearTimeout(debounceTimer)
            if (justSelected) {
                justSelected = false
                return
            }
            const search = (val || '').trim()
            if (search.length < 3) {
                customerSearchResults.value = []
                return
            }
            customerLoading.value = true
            debounceTimer = setTimeout(async () => {
                try {
                    const { data } = await axios.post('/api/faktura/', {
                        action: 'searchFakturaCustomers',
                        search,
                        type: props.isVendor ? 'vendor' : 'customer'
                    })
                    customerSearchResults.value = data?.results || []
                } catch {
                    customerSearchResults.value = []
                } finally {
                    customerLoading.value = false
                }
            }, 300)
        })

        /**
         * Kunde ausgewaehlt: Namen merken + Event emittieren
         */
        function onCustomerSelect(newId) {
            if (newId) {
                justSelected = true
                // eslint-disable-next-line eqeqeq
                const sel = customerSearchResults.value.find(c => c.id == newId)
                if (sel) lastSelectedCustomer.value = sel
                customerSearchResults.value = []
            } else {
                lastSelectedCustomer.value = null
            }
            emit('update:customer-id', newId)
        }

        /**
         * Aktuelle Kunden-/Lieferanten-ID
         */
        const customerId = computed(() => {
            return props.modelValue.customer_id || props.modelValue.vendor_id
        })

        /**
         * Formatiert einen Währungsbetrag
         */
        function formatCurrency(value) {
            if (!value) return '0.00'
            return formatNumber(value, 2)
        }

        /**
         * Aktualisiert das Model und emittiert das field-change Event für Backend-Speicherung
         *
         * @param {string} field - Feldname
         * @param {any} value - Neuer Wert
         */
        function onFieldChange(field, value) {
            emit('update:modelValue', { ...props.modelValue, [field]: value })
            emit('field-change', field, value)
        }

        /**
         * Öffnet die Kunden-/Lieferanten-Bearbeitung im entsprechenden Tab
         *
         * @param {string} tab - Tab-Name (billing, shipto, additional-billing)
         */
        function switchToWhatsAppTab(phone) {
            if (!customerId.value) return
            const routeName = props.isVendor ? 'change-vendor' : 'change-customer'
            router.push({
                name: routeName,
                params: { id: customerId.value },
                query: { tab: 'whatsapp', whatsappPhone: phone }
            })
        }

        function openCustomerEdit(tab, focusField) {
            if (!customerId.value) return

            const routeName = props.isVendor ? 'vendor-edit' : 'customer-edit'

            // Query-Parameter für Tab und ggf. ausgewählte Adresse
            const query = { tab: tab }

            if (focusField) {
                query.focus = focusField
            }

            // Bei Lieferadresse: shipto_id mitgeben
            if (tab === 'shipto' && props.modelValue.shipto_id) {
                query.shiptoId = props.modelValue.shipto_id
            }

            // Bei Rechnungsadresse: billing_address_id mitgeben
            if (tab === 'additional-billing' && props.modelValue.billing_address_id) {
                query.billingAddressId = props.modelValue.billing_address_id
            }

            router.push({
                name: routeName,
                params: { id: customerId.value },
                query: query
            })
        }

        return {
            t,
            customerSearch,
            customerItems,
            customerLoading,
            customerId,
            formatCurrency,
            formatPhone,
            onFieldChange,
            onCustomerSelect,
            openCustomerEdit,
            switchToWhatsAppTab
        }
    }
})
</script>

<style scoped>
/* ============================================
   CUSTOMER INFO CARD
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

/* Input-Felder */
.faktura-card__body :deep(.v-input) {
    flex: unset;
    grid-template-rows: auto !important;
    margin-bottom: 4px;
}

.faktura-card__body :deep(.v-input__details) {
    display: none !important;
}

.faktura-card__body :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 12px;
    --v-field-padding-end: 12px;
}

/* Readonly Felder dezent stylen */
.faktura-card__body :deep(.v-field--disabled),
.faktura-card__body :deep(.v-input--readonly .v-field) {
    background-color: #fafafa;
}

/* Rechtsbündige Zahlenfelder */
.text-right-input :deep(input) {
    text-align: right;
}

/* Telefon-Einträge mit Rahmen */
.phone-entry {
    border: 1px solid rgba(0, 0, 0, 0.12);
    background-color: #fafafa;
}

.section-disabled {
    pointer-events: none;
    opacity: 0.45;
    user-select: none;
}
</style>