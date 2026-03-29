<!-- src/core/views/config/tabs/edit/tax-edit.vue -->

<template>
    <v-form ref="formRef" @submit.prevent="handleSave">
        <!-- Steuerschlüssel (taxkey) - Pflichtfeld, readonly bei bestehenden -->
        <v-text-field
            v-model.number="localItem.taxkey"
            :label="t('tax.taxkey')"
            :rules="taxkeyRules"
            :readonly="!!localItem.id"
            type="number"
            required
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Beschreibung - Pflichtfeld -->
        <v-text-field
            v-model="localItem.taxdescription"
            :label="t('tax.taxdescription')"
            :rules="taxdescriptionRules"
            required
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Übersetzungen -->
        <v-card variant="outlined" class="mb-4">
            <v-card-title class="bg-grey-lighten-4">
                {{ t('tax.translations') }}
            </v-card-title>
            <v-card-text>
                <v-text-field
                    v-for="lang in languages"
                    :key="lang.id"
                    v-model="translations[lang.id]"
                    :label="`${lang.description} (${t('tax.translation')})`"
                    variant="outlined"
                    density="comfortable"
                    class="mb-2"
                />
            </v-card-text>
        </v-card>

        <!-- Steuersatz (rate) - Pflichtfeld, 0-100%, readonly bei bestehenden -->
        <v-text-field
            v-model.number="localItem.rate"
            :label="t('tax.rate')"
            :rules="rateRules"
            :readonly="!!localItem.id"
            :suffix="'%'"
            type="number"
            step="0.01"
            required
            variant="outlined"
            density="comfortable"
            class="mb-4"
        />

        <!-- Skontoautomatik Verkauf -->
        <v-autocomplete
            v-model="localItem.skonto_sales_chart_id"
            :items="skontoAccounts"
            :label="t('tax.skonto_sales_chart')"
            item-title="display"
            item-value="id"
            variant="outlined"
            density="comfortable"
            clearable
            class="mb-4"
        />

        <!-- Skontoautomatik Einkauf -->
        <v-autocomplete
            v-model="localItem.skonto_purchase_chart_id"
            :items="skontoAccounts"
            :label="t('tax.skonto_purchase_chart')"
            item-title="display"
            item-value="id"
            variant="outlined"
            density="comfortable"
            clearable
            class="mb-4"
        />

        <!-- Chart Categories (Checkboxes) -->
        <v-card variant="outlined" class="mb-4">
            <v-card-title class="bg-grey-lighten-4">
                {{ t('tax.chart_categories') }}
            </v-card-title>
            <v-card-text>
                <v-row>
                    <v-col cols="6" md="4">
                        <v-checkbox
                            v-model="chartCategories.asset"
                            :label="t('tax.asset')"
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="4">
                        <v-checkbox
                            v-model="chartCategories.liability"
                            :label="t('tax.liability')"
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="4">
                        <v-checkbox
                            v-model="chartCategories.equity"
                            :label="t('tax.equity')"
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="4">
                        <v-checkbox
                            v-model="chartCategories.revenue"
                            :label="t('tax.revenue')"
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="4">
                        <v-checkbox
                            v-model="chartCategories.expense"
                            :label="t('tax.expense')"
                            hide-details
                        />
                    </v-col>
                    <v-col cols="6" md="4">
                        <v-checkbox
                            v-model="chartCategories.costs"
                            :label="t('tax.costs')"
                            hide-details
                        />
                    </v-col>
                </v-row>
            </v-card-text>
        </v-card>

        <!-- Verknüpfte Konten (readonly Anzeige) -->
        <v-card v-if="localItem.id && linkedAccounts.length > 0" variant="outlined" class="mb-4">
            <v-card-title class="bg-grey-lighten-4">
                {{ t('tax.linked_accounts') }}
            </v-card-title>
            <v-card-text>
                <div class="d-flex flex-wrap gap-2">
                    <v-chip
                        v-for="account in linkedAccounts"
                        :key="account.id"
                        size="small"
                        variant="outlined"
                        @click="openAccountEdit(account.id)"
                        style="cursor: pointer"
                    >
                        {{ account.accno }}
                    </v-chip>
                </div>
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
import { ref, reactive, computed, onMounted } from 'vue';
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

// Übersetzungen (key: language_id, value: translation)
const translations = ref({});

// Chart Categories (A, L, Q, I, E, C)
const chartCategories = reactive({
    asset: false,     // A
    liability: false, // L
    equity: false,    // Q
    revenue: false,   // I
    expense: false,   // E
    costs: false      // C
});

// Kontenplan aus Store
const chartOfAccounts = computed(() => store.session?.company_config?.chart || []);

// Sprachen aus Store
const languages = computed(() => store.session?.company_config?.languages || []);

// Steuerkonten (alle Konten mit link enthält 'tax')
const taxAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => account.link && account.link.includes('tax'))
        .map(account => ({
            id: account.id,
            accno: account.accno,
            description: account.description,
            display: `${account.accno} ${account.description}`,
            value: account.id
        }))
        .sort((a, b) => a.accno.localeCompare(b.accno));
});

// Skontokonten (für Skonto-Automatik - alle AR/AP Konten)
const skontoAccounts = computed(() => {
    return chartOfAccounts.value
        .filter(account => {
            if (!account.link) return false;
            // AR = Debitorenkonten, AP = Kreditorenkonten
            return account.link.includes('AR') || account.link.includes('AP');
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

// Verknüpfte Konten (Konten die diese Steuer verwenden)
const linkedAccounts = computed(() => {
    if (!localItem.value.id || !localItem.value.taxkey) return [];
    
    // Finde alle Konten die diesen taxkey verwenden
    return chartOfAccounts.value
        .filter(account => account.taxkey_id === localItem.value.taxkey)
        .map(account => ({
            id: account.id,
            accno: account.accno
        }))
        .sort((a, b) => a.accno.localeCompare(b.accno));
});

// Validierung: taxkey
const taxkeyRules = [
    v => v !== null && v !== '' || t('tax.taxkey_required'),
    v => {
        if (v === 0 && localItem.value.rate > 0) {
            return t('tax.taxkey_zero_only_for_rate_zero');
        }
        return true;
    }
];

// Validierung: taxdescription
const taxdescriptionRules = [
    v => !!v || t('tax.taxdescription_required'),
    v => (v && v.length > 0) || t('tax.taxdescription_required')
];

// Validierung: rate (0-100%)
const rateRules = [
    v => v !== null && v !== '' || t('tax.rate_required'),
    v => v >= 0 && v < 100 || t('tax.rate_between_0_and_100'),
    v => {
        if (v > 0 && v <= 0.99) {
            return t('tax.rate_between_0_and_100');
        }
        return true;
    }
];

/**
 * Initialisiert chartCategories aus chart_categories String
 */
function initChartCategories() {
    const cats = localItem.value.chart_categories || '';
    chartCategories.asset = cats.includes('A');
    chartCategories.liability = cats.includes('L');
    chartCategories.equity = cats.includes('Q');
    chartCategories.revenue = cats.includes('I');
    chartCategories.expense = cats.includes('E');
    chartCategories.costs = cats.includes('C');
}

/**
 * Baut chart_categories String aus Checkboxen
 */
function buildChartCategories() {
    let cats = '';
    if (chartCategories.asset) cats += 'A';
    if (chartCategories.liability) cats += 'L';
    if (chartCategories.equity) cats += 'Q';
    if (chartCategories.revenue) cats += 'I';
    if (chartCategories.expense) cats += 'E';
    if (chartCategories.costs) cats += 'C';
    return cats;
}

/**
 * Speichert die Steuer
 */
async function handleSave() {
    const { valid } = await formRef.value.validate();
    
    if (!valid) {
        return;
    }
    
    // Baue chart_categories String
    localItem.value.chart_categories = buildChartCategories();
    
    // Füge Übersetzungen hinzu
    localItem.value.translations = translations.value;
    
    console.log('💾 Speichere Steuer:', localItem.value);
    console.log('📝 Übersetzungen:', translations.value);
    
    emit('save', localItem.value);
}

/**
 * Löscht die Steuer
 */
function handleDelete() {
    if (confirm(t('tax.delete_confirm'))) {
        emit('delete', localItem.value);
    }
}

/**
 * Öffnet Konto-Bearbeitung (placeholder - noch nicht implementiert)
 */
function openAccountEdit(accountId) {
    // TODO: Implementiere Navigation zur Konto-Bearbeitung
    alert(`Konto-Bearbeitung für ID ${accountId} ist noch nicht implementiert.`);
}

onMounted(() => {
    console.group('🔧 Steuer Edit - Debug');
    console.log('Item zum Bearbeiten:', localItem.value);
    console.log('Taxkey:', localItem.value.taxkey);
    console.log('Verfügbare Steuerkonten:', taxAccounts.value.length);
    console.log('Chart of Accounts (erste 3):', chartOfAccounts.value.slice(0, 3));
    console.groupEnd();
    
    // Konvertiere rate von Dezimal (0.19) zu Prozent (19)
    if (localItem.value.id && localItem.value.rate) {
        localItem.value.rate = localItem.value.rate * 100;
    }
    
    // Initialisiere chart_categories
    if (localItem.value.id) {
        initChartCategories();
        
        // Lade Übersetzungen aus generic_translations
        const genericTranslations = store.session?.company_config?.generic_translations || [];
        const taxTranslations = genericTranslations.filter(
            t => t.translation_type === 'SL::DB::Tax/taxdescription' && 
                 t.translation_id === localItem.value.id
        );
        
        // Fülle translations ref
        taxTranslations.forEach(t => {
            translations.value[t.language_id] = t.translation;
        });
        
        console.log('📝 Geladene Übersetzungen:', translations.value);
        
        // Debug: Zeige verknüpfte Konten
        console.log('🔗 Verknüpfte Konten:', linkedAccounts.value);
    } else {
        // Neue Steuer: Alle Kategorien aktiviert
        chartCategories.asset = true;
        chartCategories.liability = true;
        chartCategories.equity = true;
        chartCategories.revenue = true;
        chartCategories.expense = true;
        chartCategories.costs = true;
    }
});
</script>
