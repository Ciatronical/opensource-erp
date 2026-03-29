<!-- src/core/views/config/tabs/edit/bank-account-edit.vue -->

<template>
    <v-form ref="formRef" @submit.prevent="handleSave">
        <!-- IBAN - Wichtigstes Feld zuerst -->
        <v-text-field
            v-model="localItem.iban"
            :label="t('bank_account.iban')"
            :rules="ibanRules"
            variant="outlined"
            density="comfortable"
            @blur="lookupBankData"
            class="mb-4"
        >
            <template v-slot:append-inner>
                <v-tooltip location="top">
                    <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                    </template>
                    {{ t('bank_account.iban_help') }}
                </v-tooltip>
            </template>
        </v-text-field>

        <!-- Name - Pflichtfeld -->
        <v-text-field
            v-model="localItem.name"
            :label="t('bank_account.name')"
            :rules="[v => !!v || t('bank_account.name_required')]"
            required
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- QR-IBAN (Schweiz) -->
        <v-text-field
            v-model="localItem.qr_iban"
            :label="t('bank_account.qr_iban')"
            variant="outlined"
            density="comfortable"
            class="mb-4"
        >
            <template v-slot:append-inner>
                <v-tooltip location="top">
                    <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                    </template>
                    {{ t('bank_account.qr_iban_help') }}
                </v-tooltip>
            </template>
        </v-text-field>

        <!-- Bank -->
        <v-text-field
            v-model="localItem.bank"
            :label="t('bank_account.bank')"
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Bank Code (BLZ) -->
        <v-text-field
            v-model="localItem.bank_code"
            :label="t('bank_account.bank_code')"
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Bank Account ID (Schweiz) -->
        <v-text-field
            v-model="localItem.bank_account_id"
            :label="t('bank_account.bank_account_id')"
            variant="outlined"
            density="comfortable"
            class="mb-4"
        >
            <template v-slot:append-inner>
                <v-tooltip location="top">
                    <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                    </template>
                    {{ t('bank_account.bank_account_id_help') }}
                </v-tooltip>
            </template>
        </v-text-field>

        <!-- BIC -->
        <v-text-field
            v-model="localItem.bic"
            :label="t('bank_account.bic')"
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Fibu-Konto - Pflichtfeld -->
        <v-autocomplete
            v-model="localItem.chart_id"
            :items="bankAccounts"
            :label="t('bank_account.chart_id')"
            :rules="[v => !!v || t('bank_account.chart_id_required')]"
            item-title="display"
            item-value="id"
            variant="outlined"
            density="comfortable"
            required
            class="mb-4"
        >
            <template v-slot:append-inner>
                <v-tooltip location="top">
                    <template v-slot:activator="{ props }">
                        <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                    </template>
                    {{ t('bank_account.chart_id_help') }}
                </v-tooltip>
            </template>
        </v-autocomplete>

        <!-- Verwendung -->
        <v-card variant="outlined" class="mb-4">
            <v-card-title class="bg-grey-lighten-4">
                {{ t('bank_account.usage') }}
            </v-card-title>
            <v-card-text>
                <v-checkbox
                    v-model="localItem.use_with_bank_import"
                    :label="t('bank_account.use_with_bank_import')"
                    hide-details
                    class="mb-2"
                >
                    <template v-slot:append>
                        <v-tooltip location="top">
                            <template v-slot:activator="{ props }">
                                <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                            </template>
                            {{ t('bank_account.use_with_bank_import_help') }}
                        </v-tooltip>
                    </template>
                </v-checkbox>
                <v-checkbox
                    v-model="localItem.use_for_zugferd"
                    :label="t('bank_account.use_for_zugferd')"
                    hide-details
                    class="mb-2"
                >
                    <template v-slot:append>
                        <v-tooltip location="top">
                            <template v-slot:activator="{ props }">
                                <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                            </template>
                            {{ t('bank_account.use_for_zugferd_help') }}
                        </v-tooltip>
                    </template>
                </v-checkbox>
                <v-checkbox
                    v-model="localItem.use_for_qrbill"
                    :label="t('bank_account.use_for_qrbill')"
                    hide-details
                >
                    <template v-slot:append>
                        <v-tooltip location="top">
                            <template v-slot:activator="{ props }">
                                <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                            </template>
                            {{ t('bank_account.use_for_qrbill_help') }}
                        </v-tooltip>
                    </template>
                </v-checkbox>
            </v-card-text>
        </v-card>

        <!-- Kontenabgleich -->
        <v-card variant="outlined" class="mb-4">
            <v-card-title class="bg-grey-lighten-4">
                {{ t('bank_account.reconciliation') }}
            </v-card-title>
            <v-card-text>
                <v-text-field
                    v-model="localItem.reconciliation_starting_date"
                    :label="t('bank_account.reconciliation_starting_date')"
                    type="date"
                    variant="outlined"
                    density="comfortable"
                    class="mb-2"
                >
                    <template v-slot:append-inner>
                        <v-tooltip location="top">
                            <template v-slot:activator="{ props }">
                                <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                            </template>
                            {{ t('bank_account.reconciliation_starting_date_help') }}
                        </v-tooltip>
                    </template>
                </v-text-field>
                <v-text-field
                    v-model.number="localItem.reconciliation_starting_balance"
                    :label="t('bank_account.reconciliation_starting_balance')"
                    type="number"
                    step="0.01"
                    variant="outlined"
                    density="comfortable"
                >
                    <template v-slot:append-inner>
                        <v-tooltip location="top">
                            <template v-slot:activator="{ props }">
                                <v-icon v-bind="props" size="small" color="info">mdi-information</v-icon>
                            </template>
                            {{ t('bank_account.reconciliation_starting_balance_help') }}
                        </v-tooltip>
                    </template>
                </v-text-field>
            </v-card-text>
        </v-card>

        <!-- Actions -->
        <v-card-actions class="px-0 pt-4">
            <v-spacer />
            <v-btn
                variant="text"
                @click="$emit('cancel')"
            >
                {{ t('cancel') }}
            </v-btn>
            <v-btn
                v-if="localItem.id"
                color="error"
                variant="text"
                :disabled="localItem.used"
                @click="handleDelete"
            >
                {{ t('delete') }}
            </v-btn>
            <v-btn
                color="primary"
                variant="elevated"
                type="submit"
            >
                {{ t('save') }}
            </v-btn>
        </v-card-actions>
    </v-form>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const store = oserpStore();
const formRef = ref(null);

const props = defineProps({
    item: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['save', 'cancel', 'delete']);

// Lokale Kopie des Items
const localItem = ref({ ...props.item });

/**
 * IBAN-Validierung (reines JavaScript, keine externe Bibliothek)
 */
const ibanRules = [
    v => {
        if (!v) return true; // Optional field

        // Remove spaces and convert to uppercase
        const iban = v.replace(/\s/g, '').toUpperCase();

        // Check length (15-34 characters)
        if (iban.length < 15 || iban.length > 34) {
            return t('bank_account.iban_invalid_length');
        }

        // Check format (2 letters + 2 digits + alphanumeric)
        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) {
            return t('bank_account.iban_invalid_format');
        }

        // Check country-specific lengths
        const countryLengths = {
            'DE': 22, 'AT': 20, 'CH': 21, 'FR': 27, 'IT': 27, 'ES': 24,
            'NL': 18, 'BE': 16, 'LU': 20, 'GB': 22, 'IE': 22, 'PL': 28,
            'PT': 25, 'SE': 24, 'DK': 18, 'FI': 18, 'NO': 15
        };
        const country = iban.substring(0, 2);
        if (countryLengths[country] && iban.length !== countryLengths[country]) {
            return `${t('bank_account.iban_invalid_country_length')} (${country}: ${countryLengths[country]} Zeichen)`;
        }

        // MOD-97 Checksum Validation (offizieller IBAN-Standard)
        // 1. Move first 4 chars to end
        const rearranged = iban.substring(4) + iban.substring(0, 4);

        // 2. Replace letters with numbers (A=10, B=11, ..., Z=35)
        const numericIban = rearranged.replace(/[A-Z]/g, char => char.charCodeAt(0) - 55);

        // 3. Calculate mod 97 (for large numbers, do it in chunks)
        let remainder = '';
        for (let i = 0; i < numericIban.length; i++) {
            remainder += numericIban[i];
            if (remainder.length >= 9) {
                remainder = (parseInt(remainder) % 97).toString();
            }
        }
        remainder = parseInt(remainder) % 97;

        // Valid IBAN has remainder = 1
        if (remainder !== 1) {
            return t('bank_account.iban_invalid_checksum');
        }

        return true;
    }
];

// Kontenplan aus Store
const chartOfAccounts = computed(() => store.session?.company_config?.chart || []);

// Bereits verwendete chart_ids (außer beim aktuellen Item)
const usedChartIds = computed(() => {
    const bankAccounts = store.session?.company_config?.bank_accounts || [];
    return bankAccounts
        .filter(ba => ba.id !== localItem.value.id) // Exclude current item
        .map(ba => ba.chart_id)
        .filter(id => id !== null);
});

// Bankkonten (alle Konten mit link enthält 'AR_paid' oder 'AP_paid', aber nicht bereits verwendet)
const bankAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => {
            if (!account.link) return false;
            // Nur AR_paid/AP_paid Konten
            if (!account.link.includes('AR_paid') && !account.link.includes('AP_paid')) return false;
            // Nicht bereits verwendet (außer beim aktuellen Item)
            if (usedChartIds.value.includes(account.id)) return false;
            return true;
        })
        .map(account => ({
            id: account.id,
            accno: account.accno,
            description: account.description,
            display: `${account.accno} ${account.description}`,
            value: account.id
        }))
        .sort((a, b) => a.accno.localeCompare(b.accno));
});

/**
 * Speichert das Bankkonto
 */
async function handleSave() {
    const { valid } = await formRef.value.validate();

    if (!valid) {
        return;
    }

    console.log('💾 Speichere Bankkonto:', localItem.value);

    emit('save', localItem.value);
}

/**
 * Holt Bankdaten aus blz_de Tabelle anhand IBAN
 */
async function lookupBankData() {
    if (!localItem.value.iban) {
        return;
    }

    const iban = localItem.value.iban.replace(/\s/g, '').toUpperCase();

    // Nur für deutsche IBANs
    if (!iban.startsWith('DE')) {
        return;
    }

    // Validiere IBAN erst
    const valid = ibanRules.every(rule => rule(iban) === true);
    if (!valid) {
        return;
    }

    try {
        console.log('🔍 Hole Bankdaten für IBAN:', iban);

        const response = await store.getBankDataFromIban(iban);

        console.log('📦 API Response:', response);

        if (response?.success && response.data) {
            const bankData = response.data;
            console.log('✅ Bankdaten:', bankData);

            // Fülle ALLE Felder aus (überschreibe auch bestehende Werte)
            // Name = Bankname (z.B. "ING-DiBa")
            if (bankData.bank) {
                localItem.value.name = bankData.bank;
                console.log('📝 Name:', localItem.value.name);
            }

            // Bank = Kurzname (z.B. "ING-DiBa Frankfurt am Main")
            if (bankData.kurzname) {
                localItem.value.bank = bankData.kurzname;
                console.log('🏦 Bank:', localItem.value.bank);
            }

            // BIC
            if (bankData.bic) {
                localItem.value.bic = bankData.bic;
                console.log('🔑 BIC:', localItem.value.bic);
            }

            // BLZ
            if (bankData.blz) {
                localItem.value.bank_code = bankData.blz;
                console.log('🔢 BLZ:', localItem.value.bank_code);
            }

            console.log('✅ Alle Felder ausgefüllt');
        } else {
            console.log('⚠️ Keine Bankdaten gefunden');
        }
    } catch (error) {
        console.error('❌ Fehler beim Laden der Bankdaten:', error);
        // Fail silently - user can still enter manually
    }
}

/**
 * Löscht das Bankkonto
 */
function handleDelete() {
    if (confirm(t('bank_account.delete_confirm'))) {
        emit('delete', localItem.value);
    }
}

onMounted(() => {
    console.group('🏦 Bankkonto Edit - Debug');
    console.log('Item zum Bearbeiten:', localItem.value);
    console.log('Verfügbare Fibu-Konten:', bankAccounts.value.length);
    console.groupEnd();
});
</script>
