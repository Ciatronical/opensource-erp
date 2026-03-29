// src/core/views/customer-vendor/tabs/bank.tab.vue
<template>
    <v-row class="pa-2 pa-sm-3">
        <v-col cols="12" md="6">
            <v-card variant="outlined" elevation="1" class="mb-3">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                    <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.bank.accountTitle') }}</h4>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-2 px-2 px-sm-3">
                    <v-row dense>
                        <v-col cols="12" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.depositor')"
                                v-model="localData.depositor"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                @focus="onDepositorFocus"
                            />
                        </v-col>
                        <v-col cols="12" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.iban')"
                                v-model="localData.iban"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                                @blur="onIbanBlur"
                                :error="ibanError"
                                :error-messages="ibanErrorMessage"
                            />
                        </v-col>
                        <v-col cols="12" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.account_number')"
                                v-model="localData.account_number"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card variant="outlined" elevation="1" class="mb-3">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                    <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.bank.bankTitle') }}</h4>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-2 px-2 px-sm-3">
                    <v-row dense>
                        <v-col cols="12" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.bank')"
                                v-model="localData.bank"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.bic')"
                                v-model="localData.bic"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.bank_code')"
                                v-model="localData.bank_code"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>

            <v-card variant="outlined" elevation="1">
                <v-card-title class="py-2 px-3 bg-grey-lighten-4">
                    <h4 class="text-subtitle-1 mb-0">{{ t('CustomerVendorEditView.bank.mandateTitle') }}</h4>
                </v-card-title>
                <v-divider />
                <v-card-text class="py-2 px-2 px-sm-3">
                    <v-row dense>
                        <v-col cols="12" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.mandator_id')"
                                v-model="localData.mandator_id"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" class="py-1">
                            <v-text-field
                                :label="t('CustomerVendorEditView.fields.mandate_date_of_signature')"
                                v-model="localData.mandate_date_of_signature"
                                type="date"
                                variant="outlined"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" class="py-1 d-flex align-center">
                            <v-switch
                                :label="t('CustomerVendorEditView.fields.direct_debit')"
                                v-model="localData.direct_debit"
                                density="compact"
                                hide-details="auto"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>
        </v-col>
    </v-row>
</template>

<script>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'

export default {
    name: 'BankTab',
    props: {
        modelValue: { type: Object, required: true },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const oserpData = oserpStore()
        const ibanError = ref(false)
        const ibanErrorMessage = ref('')

        const localData = computed({
            get: () => props.modelValue,
            set: (value) => emit('update:modelValue', value)
        })

        /**
         * Beim Focus auf Kontoinhaber-Feld: Kundenname einfügen wenn leer
         */
        const onDepositorFocus = () => {
            console.log('🎯 onDepositorFocus triggered')
            console.log('Current depositor:', localData.value.depositor)
            console.log('Customer name:', props.modelValue.name)

            if (!localData.value.depositor && props.modelValue.name) {
                const updated = { ...props.modelValue }
                updated.depositor = props.modelValue.name
                emit('update:modelValue', updated)
                console.log('✅ Depositor set to:', props.modelValue.name)
            }
        }

        /**
         * Holt Bankdaten aus blz_de Tabelle via Backend
         */
        const onIbanBlur = async () => {
            console.log('🚀 onIbanBlur triggered')
            console.log('📋 IBAN value:', localData.value.iban)

            if (!localData.value.iban) {
                console.log('⚠️ No IBAN provided')
                ibanError.value = false
                ibanErrorMessage.value = ''
                return
            }

            // Remove spaces and convert to uppercase
            const iban = localData.value.iban.replace(/\s/g, '').toUpperCase()
            console.log('🔤 Cleaned IBAN:', iban)

            // Basic format validation
            if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) {
                console.log('❌ Invalid IBAN format')
                ibanError.value = true
                ibanErrorMessage.value = 'Ungültiges IBAN-Format'
                return
            }

            // Validate IBAN checksum (ISO 13616)
            if (!validateIBANChecksum(iban)) {
                console.log('❌ IBAN checksum validation failed')
                ibanError.value = true
                ibanErrorMessage.value = 'Ungültige IBAN-Prüfsumme'
                return
            }

            console.log('✅ IBAN format and checksum valid')
            ibanError.value = false
            ibanErrorMessage.value = ''

            try {
                console.log('🔍 Calling getBankDataFromIban...')

                // Call Store Action die das Backend aufruft
                const response = await oserpData.getBankDataFromIban(iban)

                console.log('📦 Full response:', response)

                // Store gibt bereits response.data zurück, also ist bankData direkt verfügbar
                if (response && response.success && response.data) {
                    const bankData = response.data
                    console.log('📦 Bank data:', bankData)

                    const updated = { ...props.modelValue }

                    // Auto-fill Bank name (von kurzname) - IMMER überschreiben
                    if (bankData.kurzname) {
                        updated.bank = bankData.kurzname
                        console.log('🏦 Bank set to:', bankData.kurzname)
                    }

                    // Auto-fill BIC - IMMER überschreiben
                    if (bankData.bic) {
                        updated.bic = bankData.bic
                        console.log('💳 BIC set to:', bankData.bic)
                    }

                    // Auto-fill Bank Code (BLZ) - IMMER überschreiben
                    if (bankData.blz) {
                        updated.bank_code = bankData.blz
                        console.log('🔢 Bank code set to:', bankData.blz)
                    }

                    // Extract account number from IBAN (Deutsche IBANs: Position 13-22) - IMMER überschreiben
                    if (iban.startsWith('DE')) {
                        updated.account_number = iban.substring(12)
                        console.log('💰 Account number set to:', updated.account_number)
                    }

                    emit('update:modelValue', updated)
                    console.log('✅ Bank data updated successfully')
                } else {
                    console.warn('⚠️ No bank data found for this IBAN')
                }
            } catch (error) {
                console.error('❌ Error fetching bank data:', error)
            }
        }

        /**
         * Validiert IBAN Prüfsumme nach ISO 13616
         */
        const validateIBANChecksum = (iban) => {
            // IBAN muss mindestens 15 Zeichen haben (kürzeste IBAN: NO)
            if (iban.length < 15) {
                return false
            }

            // Verschiebe die ersten 4 Zeichen ans Ende
            const rearranged = iban.substring(4) + iban.substring(0, 4)

            // Ersetze Buchstaben durch Zahlen (A=10, B=11, ..., Z=35)
            let numericString = ''
            for (let i = 0; i < rearranged.length; i++) {
                const char = rearranged[i]
                if (char >= 'A' && char <= 'Z') {
                    numericString += (char.charCodeAt(0) - 55).toString()
                } else {
                    numericString += char
                }
            }

            // Führe Modulo 97 Operation durch (in Blöcken um große Zahlen zu vermeiden)
            let remainder = 0
            for (let i = 0; i < numericString.length; i++) {
                remainder = (remainder * 10 + parseInt(numericString[i])) % 97
            }

            return remainder === 1
        }

        return {
            localData,
            t,
            ibanError,
            ibanErrorMessage,
            onDepositorFocus,
            onIbanBlur,
            validateIBANChecksum
        }
    }
}
</script>

<style scoped>
.bg-grey-lighten-4 {
    background-color: #f5f5f5;
}
</style>
