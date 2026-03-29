<!-- src/core/views/config/tabs/edit/taxzone-edit.vue -->

<template>
    <v-form ref="formRef" @submit.prevent="handleSave">
        <!-- Beschreibung - IMMER editierbar -->
        <v-text-field
            v-model="localItem.description"
            :label="t('taxzone.description')"
            :rules="[v => !!v || t('taxzone.description_required')]"
            required
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Konten pro Buchungsgruppe -->
        <v-card
            v-for="bg in buchungsgruppen"
            :key="bg.id"
            class="mb-4"
            variant="outlined"
        >
            <v-card-title class="bg-grey-lighten-4">
                <v-icon class="me-2" size="small">mdi-file-document-multiple</v-icon>
                {{ bg.description }}
            </v-card-title>
            <v-card-text>
                <v-row>
                    <!-- Erlöskonto -->
                    <v-col cols="12" md="6">
                        <v-autocomplete
                            :model-value="getIncomeAccnoId(bg.id)"
                            @update:model-value="setIncomeAccnoId(bg.id, $event)"
                            :items="incomeAccounts"
                            :label="t('taxzone.income_account')"
                            :disabled="localItem.used"
                            :rules="[v => !!v || t('taxzone.income_required')]"
                            item-title="display"
                            item-value="id"
                            variant="outlined"
                            density="comfortable"
                            clearable
                        />
                    </v-col>

                    <!-- Aufwandskonto -->
                    <v-col cols="12" md="6">
                        <v-autocomplete
                            :model-value="getExpenseAccnoId(bg.id)"
                            @update:model-value="setExpenseAccnoId(bg.id, $event)"
                            :items="expenseAccounts"
                            :label="t('taxzone.expense_account')"
                            :disabled="localItem.used"
                            :rules="[v => !!v || t('taxzone.expense_required')]"
                            item-title="display"
                            item-value="id"
                            variant="outlined"
                            density="comfortable"
                            clearable
                        />
                    </v-col>
                </v-row>
            </v-card-text>
        </v-card>

        <!-- Ungültig - nur wenn bereits verwendet -->
        <v-checkbox
            v-if="localItem.used"
            v-model="localItem.obsolete"
            :label="t('taxzone.obsolete')"
            hide-details
            class="mb-4"
        />

        <!-- Actions -->
        <v-card-actions class="px-0 pt-4">
            <v-spacer />
            <v-btn
                variant="text"
                @click="$emit('cancel')"
            >
                {{ t('cancel') }}
            </v-btn>
            <!-- DELETE-Button nur disabled wenn used -->
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

// Lokale Kopie der taxzone_charts
const localTaxzoneCharts = ref([]);

// Buchungsgruppen aus Store
const buchungsgruppen = computed(() => store.session?.company_config?.buchungsgruppen || []);

// Kontenplan aus Store
const chartOfAccounts = computed(() => store.session?.company_config?.chart || []);

// Taxzone Charts für diese Steuerzone
const taxzoneCharts = computed(() => {
    if (!localItem.value.id) return [];
    return (store.session?.company_config?.taxzone_charts || [])
        .filter(tc => tc.taxzone_id === localItem.value.id);
});

// Erlöskonten (IC_income ODER IC_sale)
const incomeAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => {
            if (!account.link) return false;
            return account.link.includes('IC_income') || account.link.includes('IC_sale');
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

// Aufwandskonten (IC_expense ODER IC_cogs)
const expenseAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => {
            if (!account.link) return false;
            return account.link.includes('IC_expense') || account.link.includes('IC_cogs');
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
 * Holt income_accno_id für eine Buchungsgruppe
 */
function getIncomeAccnoId(buchungsgruppenId) {
    const chart = localTaxzoneCharts.value.find(tc => tc.buchungsgruppen_id === buchungsgruppenId);
    return chart?.income_accno_id || null;
}

/**
 * Setzt income_accno_id für eine Buchungsgruppe
 */
function setIncomeAccnoId(buchungsgruppenId, accnoId) {
    const chart = localTaxzoneCharts.value.find(tc => tc.buchungsgruppen_id === buchungsgruppenId);
    if (chart) {
        chart.income_accno_id = accnoId;
    }
}

/**
 * Holt expense_accno_id für eine Buchungsgruppe
 */
function getExpenseAccnoId(buchungsgruppenId) {
    const chart = localTaxzoneCharts.value.find(tc => tc.buchungsgruppen_id === buchungsgruppenId);
    return chart?.expense_accno_id || null;
}

/**
 * Setzt expense_accno_id für eine Buchungsgruppe
 */
function setExpenseAccnoId(buchungsgruppenId, accnoId) {
    const chart = localTaxzoneCharts.value.find(tc => tc.buchungsgruppen_id === buchungsgruppenId);
    if (chart) {
        chart.expense_accno_id = accnoId;
    }
}

/**
 * Speichert die Steuerzone
 */
async function handleSave() {
    const { valid } = await formRef.value.validate();

    if (!valid) {
        return;
    }

    // Füge taxzone_charts zum Item hinzu
    const itemToSave = {
        ...localItem.value,
        taxzone_charts: localTaxzoneCharts.value
    };

    console.log('💾 Speichere Steuerzone:', itemToSave);

    emit('save', itemToSave);
}

/**
 * Löscht die Steuerzone
 */
function handleDelete() {
    if (confirm(t('taxzone.delete_confirm'))) {
        emit('delete', localItem.value);
    }
}

onMounted(() => {
    console.group('🔧 Steuerzone Edit - Debug');
    console.log('Item zum Bearbeiten:', localItem.value);
    console.log('Verfügbare Buchungsgruppen:', buchungsgruppen.value);
    console.log('Kontenplan geladen:', chartOfAccounts.value.length);
    console.log('Erlöskonten:', incomeAccounts.value.length);
    console.log('Aufwandskonten:', expenseAccounts.value.length);

    // Lade die taxzone_charts für diese Steuerzone
    if (localItem.value.id) {
        localTaxzoneCharts.value = taxzoneCharts.value.map(tc => ({ ...tc }));
        console.log('📋 Geladene taxzone_charts:', localTaxzoneCharts.value);
    } else {
        // Neue Steuerzone: Initialisiere leere Charts für alle Buchungsgruppen
        localTaxzoneCharts.value = buchungsgruppen.value.map(bg => ({
            buchungsgruppen_id: bg.id,
            taxzone_id: null,
            income_accno_id: null,
            expense_accno_id: null
        }));
        console.log('🆕 Initialisierte taxzone_charts für neue Steuerzone');
    }

    console.groupEnd();
});
</script>
