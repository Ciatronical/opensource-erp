<!-- customer-vendor-search-form.component.vue -->
<template>
    <div>
        <v-row dense>
            <v-col>
                <v-row dense>
                    <v-col cols="2">
                        <v-text-field
                            :model-value="modelValue.number"
                            @update:model-value="updateField('number', $event)"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.customer_number') : t('CustomerVendorSearchView.fields.vendor_number')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="1">
                        <v-text-field
                            :model-value="modelValue.greeting"
                            @update:model-value="updateField('greeting', $event)"
                            :label="t('CustomerVendorSearchView.fields.greeting')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.name"
                            @update:model-value="updateField('name', $event)"
                            :label="t('CustomerVendorSearchView.fields.name')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.email"
                            @update:model-value="updateField('email', $event)"
                            :label="t('CustomerVendorSearchView.fields.mail')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.phone"
                            @update:model-value="updateField('phone', $event)"
                            :label="t('CustomerVendorSearchView.fields.phone_fax')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.street"
                            @update:model-value="updateField('street', $event)"
                            :label="t('CustomerVendorSearchView.fields.street')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="1">
                        <v-text-field
                            :model-value="modelValue.zipcode"
                            @update:model-value="updateField('zipcode', $event)"
                            :label="t('CustomerVendorSearchView.fields.zipcode')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.city"
                            @update:model-value="updateField('city', $event)"
                            :label="t('CustomerVendorSearchView.fields.city')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="5">
                        <v-text-field
                            :model-value="modelValue.country"
                            @update:model-value="updateField('country', $event)"
                            :label="t('CustomerVendorSearchView.fields.country')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-switch
                        color="green-darken-4"
                        base-color="red-darken-4"
                        :label="t('CustomerVendorSearchView.fields.switch_show_additional_search_fields')"
                        :model-value="showAdditionalCriteria"
                        @update:model-value="$emit('update:showAdditionalCriteria', $event)"
                        density="compact"
                    ></v-switch>
                </v-row>
            </v-col>
        </v-row>

        <v-row v-show="showAdditionalCriteria" dense>
            <v-col>
                <v-row dense>
                    <v-col cols="2">
                        <v-text-field
                            :model-value="modelValue.gln"
                            @update:model-value="updateField('gln', $event)"
                            :label="t('CustomerVendorSearchView.fields.gln')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.salesman_id"
                            @update:model-value="updateField('salesman_id', $event)"
                            :items="options.salesmenOptions.value"
                            :label="t('CustomerVendorSearchView.fields.salesman')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.business_id"
                            @update:model-value="updateField('business_id', $event)"
                            :items="options.businessOptions.value"
                            :label="t('CustomerVendorSearchView.fields.business_type')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="2">
                        <v-text-field
                            :model-value="modelValue.contact"
                            @update:model-value="updateField('contact', $event)"
                            :label="t('CustomerVendorSearchView.fields.contact')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="2">
                        <v-text-field
                            :model-value="modelValue.department"
                            @update:model-value="updateField('department', $event)"
                            :label="t('CustomerVendorSearchView.fields.department')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="2">
                        <v-select
                            :model-value="modelValue.natural_person"
                            @update:model-value="updateField('natural_person', $event)"
                            :items="options.naturalPersonSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.natural_person')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="2">
                        <v-select
                            :model-value="modelValue.dunning_lock"
                            @update:model-value="updateField('dunning_lock', $event)"
                            :items="options.dunningLockSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.dunning_lock')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="2">
                        <v-select
                            :model-value="modelValue.obsolete"
                            @update:model-value="updateField('obsolete', $event)"
                            :items="options.statusSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.status')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.language_id"
                            @update:model-value="updateField('language_id', $event)"
                            :items="options.languageOptions.value"
                            :label="t('CustomerVendorSearchView.fields.language')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.homepage"
                            @update:model-value="updateField('homepage', $event)"
                            :label="t('CustomerVendorSearchView.fields.homepage')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="2">
                        <v-text-field
                            :model-value="modelValue.ustid"
                            @update:model-value="updateField('ustid', $event)"
                            :label="t('CustomerVendorSearchView.fields.ustid')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.taxnumber"
                            @update:model-value="updateField('taxnumber', $event)"
                            :label="t('CustomerVendorSearchView.fields.taxnumber')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.creditlimit"
                            @update:model-value="updateField('creditlimit', $event)"
                            type="number"
                            :label="t('CustomerVendorSearchView.fields.creditlimit')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="4">
                        <v-text-field
                            :model-value="modelValue.discount"
                            @update:model-value="updateField('discount', $event)"
                            type="number"
                            :label="t('CustomerVendorSearchView.fields.discount')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            suffix="%"
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-select
                            :model-value="modelValue.taxincluded"
                            @update:model-value="updateField('taxincluded', $event)"
                            :items="options.taxIncludedSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.taxincluded')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.pricegroup_id"
                            @update:model-value="updateField('pricegroup_id', $event)"
                            :items="options.pricegroupOptions.value"
                            :label="t('CustomerVendorSearchView.fields.pricegroup')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.delivery_term_id"
                            @update:model-value="updateField('delivery_term_id', $event)"
                            :items="options.deliveryTermOptions.value"
                            :label="t('CustomerVendorSearchView.fields.delivery_terms')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.taxzone_id"
                            @update:model-value="updateField('taxzone_id', $event)"
                            :items="options.taxZoneOptions.value"
                            :label="t('CustomerVendorSearchView.fields.tax_rate')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.currency_id"
                            @update:model-value="updateField('currency_id', $event)"
                            :items="options.currencyOptions.value"
                            :label="t('CustomerVendorSearchView.fields.currency')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.zugferd"
                            @update:model-value="updateField('zugferd', $event)"
                            :items="options.zugferdSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.zugferd_settings')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="3">
                        <v-autocomplete
                            :model-value="modelValue.payment_id"
                            @update:model-value="updateField('payment_id', $event)"
                            :items="options.paymentTermsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.payment_terms')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-autocomplete>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.notes"
                            @update:model-value="updateField('notes', $event)"
                            :label="t('CustomerVendorSearchView.fields.notes')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.iban"
                            @update:model-value="updateField('iban', $event)"
                            :label="t('CustomerVendorSearchView.fields.iban')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.bic"
                            @update:model-value="updateField('bic', $event)"
                            :label="t('CustomerVendorSearchView.fields.bic')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.hourly_rate"
                            @update:model-value="updateField('hourly_rate', $event)"
                            type="number"
                            :label="t('CustomerVendorSearchView.fields.hourly_rate')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-select
                            :model-value="modelValue.order_lock"
                            @update:model-value="updateField('order_lock', $event)"
                            :items="options.orderLockSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.order_lock')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.invoice_mail"
                            @update:model-value="updateField('invoice_mail', $event)"
                            :label="t('CustomerVendorSearchView.fields.invoice_mail')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.delivery_order_mail"
                            @update:model-value="updateField('delivery_order_mail', $event)"
                            :label="t('CustomerVendorSearchView.fields.delivery_order_mail')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.dunning_mail"
                            @update:model-value="updateField('dunning_mail', $event)"
                            :label="t('CustomerVendorSearchView.fields.dunning_mail')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-select
                            :model-value="modelValue.postal_invoice"
                            @update:model-value="updateField('postal_invoice', $event)"
                            :items="options.postalInvoiceSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.postal_invoice')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-select
                            :model-value="modelValue.direct_debit"
                            @update:model-value="updateField('direct_debit', $event)"
                            :items="options.directDebitSettingsOptions.value"
                            :label="t('CustomerVendorSearchView.fields.direct_debit')"
                            item-title="label"
                            item-value="value"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-select>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.contact_origin"
                            @update:model-value="updateField('contact_origin', $event)"
                            :label="t('CustomerVendorSearchView.fields.contact_origin')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.username"
                            @update:model-value="updateField('username', $event)"
                            :label="t('CustomerVendorSearchView.fields.username')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <!-- Platz für weitere Felder -->
                    </v-col>
                </v-row>

                <!-- Kunde/Lieferant Datumsbereich - ZUERST -->
                <v-row dense>
                    <v-col cols="12">
                        <v-divider></v-divider>
                        <h3 class="text-subtitle-2 mt-2 mb-2">
                            {{ typeFilter === 'customer' ? t('CustomerVendorSearchView.sections.customer_record') : t('CustomerVendorSearchView.sections.vendor_record') }}
                        </h3>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.itime_from"
                            @update:model-value="updateField('itime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.created_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.itime_to"
                            @update:model-value="updateField('itime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.created_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.mtime_from"
                            @update:model-value="updateField('mtime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.updated_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.mtime_to"
                            @update:model-value="updateField('mtime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.updated_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                </v-row>

                <!-- Rechnungen Datumsbereich -->
                <v-row dense>
                    <v-col cols="12">
                        <v-divider></v-divider>
                        <h3 class="text-subtitle-2 mt-2 mb-2">
                            {{ typeFilter === 'customer' ? t('CustomerVendorSearchView.sections.invoices') : t('CustomerVendorSearchView.sections.vendor_invoices') }}
                        </h3>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.invoice_itime_from"
                            @update:model-value="updateField('invoice_itime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.invoice_created_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.invoice_itime_to"
                            @update:model-value="updateField('invoice_itime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.invoice_created_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.invoice_mtime_from"
                            @update:model-value="updateField('invoice_mtime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.invoice_updated_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.invoice_mtime_to"
                            @update:model-value="updateField('invoice_mtime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.invoice_updated_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                </v-row>

                <!-- Angebote/Anfragen Datumsbereich -->
                <v-row dense>
                    <v-col cols="12">
                        <v-divider></v-divider>
                        <h3 class="text-subtitle-2 mt-2 mb-2">
                            {{ typeFilter === 'customer' ? t('CustomerVendorSearchView.sections.quotations') : t('CustomerVendorSearchView.sections.requests_for_quotation') }}
                        </h3>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.quotation_itime_from"
                            @update:model-value="updateField('quotation_itime_from', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.quotation_created_at_from') : t('CustomerVendorSearchView.fields.rfq_created_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.quotation_itime_to"
                            @update:model-value="updateField('quotation_itime_to', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.quotation_created_at_to') : t('CustomerVendorSearchView.fields.rfq_created_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.quotation_mtime_from"
                            @update:model-value="updateField('quotation_mtime_from', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.quotation_updated_at_from') : t('CustomerVendorSearchView.fields.rfq_updated_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.quotation_mtime_to"
                            @update:model-value="updateField('quotation_mtime_to', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.quotation_updated_at_to') : t('CustomerVendorSearchView.fields.rfq_updated_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                </v-row>

                <!-- Aufträge/Bestellungen Datumsbereich -->
                <v-row dense>
                    <v-col cols="12">
                        <v-divider></v-divider>
                        <h3 class="text-subtitle-2 mt-2 mb-2">
                            {{ typeFilter === 'customer' ? t('CustomerVendorSearchView.sections.orders') : t('CustomerVendorSearchView.sections.purchase_orders') }}
                        </h3>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.order_itime_from"
                            @update:model-value="updateField('order_itime_from', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.order_created_at_from') : t('CustomerVendorSearchView.fields.purchase_order_created_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.order_itime_to"
                            @update:model-value="updateField('order_itime_to', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.order_created_at_to') : t('CustomerVendorSearchView.fields.purchase_order_created_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.order_mtime_from"
                            @update:model-value="updateField('order_mtime_from', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.order_updated_at_from') : t('CustomerVendorSearchView.fields.purchase_order_updated_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.order_mtime_to"
                            @update:model-value="updateField('order_mtime_to', $event)"
                            type="date"
                            :label="typeFilter === 'customer' ? t('CustomerVendorSearchView.fields.order_updated_at_to') : t('CustomerVendorSearchView.fields.purchase_order_updated_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                </v-row>

                <!-- Lieferscheine Datumsbereich -->
                <v-row dense>
                    <v-col cols="12">
                        <v-divider></v-divider>
                        <h3 class="text-subtitle-2 mt-2 mb-2">
                            {{ t('CustomerVendorSearchView.sections.delivery_orders') }}
                        </h3>
                    </v-col>
                </v-row>
                <v-row dense>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.delivery_order_itime_from"
                            @update:model-value="updateField('delivery_order_itime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.delivery_order_created_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.delivery_order_itime_to"
                            @update:model-value="updateField('delivery_order_itime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.delivery_order_created_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.delivery_order_mtime_from"
                            @update:model-value="updateField('delivery_order_mtime_from', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.delivery_order_updated_at_from')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                        ></v-text-field>
                    </v-col>
                    <v-col cols="3">
                        <v-text-field
                            :model-value="modelValue.delivery_order_mtime_to"
                            @update:model-value="updateField('delivery_order_mtime_to', $event)"
                            type="date"
                            :label="t('CustomerVendorSearchView.fields.delivery_order_updated_at_to')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            clearable
                            :placeholder="todayDate"
                        ></v-text-field>
                    </v-col>
                </v-row>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCustomerVendorOptions } from '../composables/useCustomerVendorOptions.js';

const { t } = useI18n();
const options = useCustomerVendorOptions();

/**
 * Heutiges Datum im Format YYYY-MM-DD für Placeholder
 */
const todayDate = computed(() => new Date().toISOString().split('T')[0]);

const props = defineProps({
    modelValue: {
        type: Object,
        required: true
    },
    typeFilter: {
        type: String,
        required: true
    },
    showAdditionalCriteria: {
        type: Boolean,
        required: true
    }
});

const emit = defineEmits(['update:modelValue', 'update:showAdditionalCriteria']);

/**
 * Aktualisiert ein einzelnes Feld im modelValue Objekt
 *
 * @param {string} field - Name des Feldes
 * @param {*} value - Neuer Wert
 */
const updateField = (field, value) => {
    emit('update:modelValue', {
        ...props.modelValue,
        [field]: value
    });
};
</script>
