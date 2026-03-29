<!-- src/core/views/config/tabs/edit/buchungsgruppe-edit.vue -->

<template>
    <v-form ref="formRef" @submit.prevent="handleSave">
        <!-- DEBUG PANEL - ENTFERNEN WENN FUNKTIONIERT -->
        <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            <div class="text-caption">
                <strong>🐛 Debug Info:</strong><br>
                Item ID: {{ localItem.id || 'NEU' }}<br>
                Beschreibung: {{ localItem.description || '(leer)' }}<br>
                Steuerzonen: {{ taxzones.length }}<br>
                Kontenplan: {{ chartOfAccounts.length }} Konten<br>
                Bestandskonten: {{ inventoryAccounts.length }}<br>
                Erlöskonten: {{ incomeAccounts.length }}<br>
                Aufwandskonten: {{ expenseAccounts.length }}<br>
                <strong>Used:</strong> {{ localItem.used || false }}
            </div>
        </v-alert>

        <!-- Beschreibung - IMMER editierbar -->
        <v-text-field
            v-model="localItem.description"
            :label="t('buchungsgruppe.description')"
            :rules="[v => !!v || t('buchungsgruppe.description_required')]"
            required
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Bestandskonto - disabled wenn used -->
        <v-autocomplete
            v-model="localItem.inventory_accno_id"
            :items="inventoryAccounts"
            :label="t('buchungsgruppe.inventory_account')"
            :disabled="localItem.used"
            item-title="display"
            item-value="id"
            variant="outlined"
            density="comfortable"
            clearable
            class="mb-6"
        />

        <!-- Konten pro Steuerzone - disabled wenn used -->
        <v-card
            v-for="taxzone in taxzones"
            :key="taxzone.id"
            class="mb-4"
            variant="outlined"
        >
            <v-card-title class="bg-grey-lighten-4">
                <v-icon class="me-2" size="small">mdi-earth</v-icon>
                {{ taxzone.description }}
            </v-card-title>
            <v-card-text>
                <v-row>
                    <!-- Erlöskonto - disabled wenn used -->
                    <v-col cols="12" md="6">
                        <v-autocomplete
                            :model-value="getIncomeAccnoId(taxzone.id)"
                            @update:model-value="setIncomeAccnoId(taxzone.id, $event)"
                            :items="incomeAccounts"
                            :label="t('buchungsgruppe.income_account')"
                            :disabled="localItem.used"
                            item-title="display"
                            item-value="id"
                            variant="outlined"
                            density="comfortable"
                            clearable
                        />
                    </v-col>

                    <!-- Aufwandskonto - disabled wenn used -->
                    <v-col cols="12" md="6">
                        <v-autocomplete
                            :model-value="getExpenseAccnoId(taxzone.id)"
                            @update:model-value="setExpenseAccnoId(taxzone.id, $event)"
                            :items="expenseAccounts"
                            :label="t('buchungsgruppe.expense_account')"
                            :disabled="localItem.used"
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

        <!-- Ungültig - nur anzeigen wenn bereits verwendet -->
        <v-checkbox
            v-if="localItem.used"
            v-model="localItem.obsolete"
            :label="t('buchungsgruppe.obsolete')"
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

const props = defineProps({
    item: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['save', 'cancel', 'delete']);

// Lokale Kopie des Items mit Fallback-Werten
const localItem = ref({
    description: '',
    inventory_accno_id: null,
    obsolete: false,
    ...props.item
});
const formRef = ref(null);

// Lokale Kopie der taxzone_charts für diese Buchungsgruppe
const localTaxzoneCharts = ref([]);

console.log('🎯 Buchungsgruppe-Edit Props:', props.item);
console.log('🎯 LocalItem initialisiert:', localItem.value);

/**
 * Holt die income_accno_id für eine Steuerzone
 */
function getIncomeAccnoId(taxzoneId) {
    const chart = localTaxzoneCharts.value.find(tc => tc.taxzone_id === taxzoneId);
    return chart?.income_accno_id || null;
}

/**
 * Setzt die income_accno_id für eine Steuerzone
 */
function setIncomeAccnoId(taxzoneId, accnoId) {
    const index = localTaxzoneCharts.value.findIndex(tc => tc.taxzone_id === taxzoneId);

    if (index >= 0) {
        localTaxzoneCharts.value[index].income_accno_id = accnoId;
    } else {
        // Neuer Eintrag
        localTaxzoneCharts.value.push({
            taxzone_id: taxzoneId,
            buchungsgruppen_id: localItem.value.id,
            income_accno_id: accnoId,
            expense_accno_id: null
        });
    }

    console.log('📝 Income Accno gesetzt:', taxzoneId, '→', accnoId);
}

/**
 * Holt die expense_accno_id für eine Steuerzone
 */
function getExpenseAccnoId(taxzoneId) {
    const chart = localTaxzoneCharts.value.find(tc => tc.taxzone_id === taxzoneId);
    return chart?.expense_accno_id || null;
}

/**
 * Setzt die expense_accno_id für eine Steuerzone
 */
function setExpenseAccnoId(taxzoneId, accnoId) {
    const index = localTaxzoneCharts.value.findIndex(tc => tc.taxzone_id === taxzoneId);

    if (index >= 0) {
        localTaxzoneCharts.value[index].expense_accno_id = accnoId;
    } else {
        // Neuer Eintrag
        localTaxzoneCharts.value.push({
            taxzone_id: taxzoneId,
            buchungsgruppen_id: localItem.value.id,
            income_accno_id: null,
            expense_accno_id: accnoId
        });
    }

    console.log('📝 Expense Accno gesetzt:', taxzoneId, '→', accnoId);
}

// Konten aus Store
const taxzones = computed(() => {
    const zones = store.session?.company_config?.tax_zones || [];
    console.log('📍 Verfügbare Steuerzonen:', zones);
    return zones;
});

const chartOfAccounts = computed(() => {
    const chart = store.session?.company_config?.chart || [];
    console.log('📊 Kontenplan geladen:', chart.length, 'Konten');
    return chart;
});

const taxzoneCharts = computed(() => {
    const allCharts = store.session?.company_config?.taxzone_charts || [];
    console.log('🔗 Alle taxzone_charts:', allCharts.length);
    return allCharts;
});

// Hole die taxzone_charts für diese Buchungsgruppe
const buchungsgruppeCharts = computed(() => {
    if (!localItem.value.id) return [];

    const charts = taxzoneCharts.value.filter(tc =>
        tc.buchungsgruppen_id === localItem.value.id
    );

    console.log('📌 taxzone_charts für Buchungsgruppe', localItem.value.id, ':', charts);
    return charts;
});

// Gefilterte Konten nach Typ
const inventoryAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => {
            // Bestandskonten: Konten mit "IC" im link (Inventory Control)
            if (!account.link) return false;
            return account.link.includes('IC');
        })
        .map(account => ({
            id: account.id,
            accno: account.accno,
            description: account.description,
            display: `${account.accno} ${account.description}`,
            // Füge einen eindeutigen Key für Vuetify hinzu
            value: account.id
        }))
        .sort((a, b) => a.accno.localeCompare(b.accno));
});

const incomeAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => {
            // Erlöskonten: link enthält IC_income ODER IC_sale
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

const expenseAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => {
            // Aufwandskonten: link enthält IC_expense ODER IC_cogs
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
 * Speichert die Buchungsgruppe
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

    console.log('💾 Speichere Buchungsgruppe:', itemToSave);

    emit('save', itemToSave);
}

/**
 * Löscht die Buchungsgruppe
 */
function handleDelete() {
    if (confirm(t('buchungsgruppe.delete_confirm'))) {
        emit('delete', localItem.value);
    }
}

onMounted(() => {
    console.group('🔧 Buchungsgruppe Edit - Debug');
    console.log('Item zum Bearbeiten:', localItem.value);
    console.log('Verfügbare Steuerzonen:', taxzones.value);
    console.log('Kontenplan geladen:', chartOfAccounts.value.length);
    console.log('Bestandskonten:', inventoryAccounts.value.length);
    console.log('Erlöskonten:', incomeAccounts.value.length);
    console.log('Aufwandskonten:', expenseAccounts.value.length);

    // Zeige die Struktur des ersten Kontos
    if (chartOfAccounts.value.length > 0) {
        console.log('Beispiel-Konto Struktur:', chartOfAccounts.value[0]);
    }

    // Lade die taxzone_charts für diese Buchungsgruppe
    if (localItem.value.id) {
        localTaxzoneCharts.value = buchungsgruppeCharts.value.map(tc => ({ ...tc }));
        console.log('📋 Geladene taxzone_charts:', localTaxzoneCharts.value);
    } else {
        // Neue Buchungsgruppe: Initialisiere leere Charts für alle Steuerzonen
        localTaxzoneCharts.value = taxzones.value.map(tz => ({
            taxzone_id: tz.id,
            buchungsgruppen_id: null,
            income_accno_id: null,
            expense_accno_id: null
        }));
        console.log('🆕 Initialisierte taxzone_charts für neue Buchungsgruppe');
    }

    console.groupEnd();
});
</script>
