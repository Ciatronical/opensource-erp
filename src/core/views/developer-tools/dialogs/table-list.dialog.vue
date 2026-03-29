<!-- src/core/views/developer-tools/dialogs/table-list.dialog.vue -->
<template>
    <v-dialog v-model="dialogVisible" max-width="1200px" scrollable @keydown.esc="close">
        <v-card>
            <v-card-title class="py-3 px-4 bg-primary text-white d-flex align-center">
                <v-icon start>mdi-table-large</v-icon>
                <span class="text-h6">{{ t('DeveloperTools.table_list.title') }}</span>
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    color="white"
                    @click="close"
                />
            </v-card-title>

            <v-card-text class="pt-4" style="max-height: 70vh;">
                <!-- Suchfeld -->
                <v-text-field
                    v-model="searchQuery"
                    :label="t('DeveloperTools.table_list.search_label')"
                    prepend-inner-icon="mdi-magnify"
                    variant="outlined"
                    density="compact"
                    clearable
                    hide-details
                    class="mb-4"
                    @input="filterTables"
                />

                <!-- Loading -->
                <v-alert v-if="loading" type="info" variant="tonal">
                    {{ t('DeveloperTools.table_list.loading') }}
                </v-alert>

                <!-- Tabellenliste mit Expansion Panels -->
                <div v-else-if="filteredTables.length > 0">
                    <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                        {{ t('DeveloperTools.table_list.count_info', { count: filteredTables.length }) }}
                    </v-alert>

                    <v-expansion-panels
                        v-model="expandedPanels"
                        variant="accordion"
                        class="table-expansion-panels"
                        @update:model-value="onPanelExpand"
                    >
                        <v-expansion-panel
                            v-for="table in filteredTables"
                            :key="table.table_name"
                            class="table-panel"
                        >
                            <v-expansion-panel-title>
                                <div class="d-flex align-center w-100">
                                    <v-icon color="primary" class="mr-3">mdi-table</v-icon>
                                    <div class="flex-grow-1">
                                        <div class="font-weight-bold">{{ table.table_name }}</div>
                                        <div v-if="table.description" class="text-caption text-grey">
                                            {{ table.description }}
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 mr-2" @click.stop>
                                        <!-- JSON Export Button -->
                                        <v-btn
                                            icon
                                            size="small"
                                            variant="text"
                                            color="info"
                                            @click.stop="copyTableSchema(table)"
                                        >
                                            <v-icon>mdi-code-json</v-icon>
                                            <v-tooltip activator="parent" location="top">
                                                {{ t('DeveloperTools.table_list.copy_json') }}
                                            </v-tooltip>
                                        </v-btn>

                                        <!-- Insert Button -->
                                        <v-btn
                                            icon
                                            size="small"
                                            variant="text"
                                            color="success"
                                            @click.stop="insertTable(table.table_name)"
                                        >
                                            <v-icon>mdi-arrow-right-circle</v-icon>
                                            <v-tooltip activator="parent" location="top">
                                                {{ t('DeveloperTools.table_list.insert_table') }}
                                            </v-tooltip>
                                        </v-btn>
                                    </div>
                                </div>
                            </v-expansion-panel-title>

                            <v-expansion-panel-text>
                                <!-- Loading Spalten -->
                                <div v-if="loadingColumns[table.table_name]" class="d-flex align-center justify-center pa-4">
                                    <v-progress-circular indeterminate size="24" class="mr-2" />
                                    <span class="text-caption">{{ t('DeveloperTools.table_list.loading_columns') }}</span>
                                </div>

                                <!-- Spalten-Liste -->
                                <div v-else-if="table.columns && table.columns.length > 0" class="columns-details">
                                    <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                                        <v-icon start size="small">mdi-table-column</v-icon>
                                        {{ t('DeveloperTools.table_list.columns_count', { count: table.columns.length }) }}
                                    </v-alert>

                                    <v-table density="compact" class="columns-table">
                                        <thead>
                                            <tr>
                                                <th>{{ t('DeveloperTools.table_list.column_name') }}</th>
                                                <th>{{ t('DeveloperTools.table_list.column_type') }}</th>
                                                <th>{{ t('DeveloperTools.table_list.column_nullable') }}</th>
                                                <th>{{ t('DeveloperTools.table_list.column_description') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="column in table.columns" :key="column.name">
                                                <td class="font-weight-medium">
                                                    <v-icon size="x-small" class="mr-1" color="blue-grey">mdi-circle-small</v-icon>
                                                    {{ column.name }}
                                                </td>
                                                <td>
                                                    <v-chip size="x-small" variant="tonal" color="blue">
                                                        {{ formatColumnType(column) }}
                                                    </v-chip>
                                                </td>
                                                <td>
                                                    <v-icon
                                                        size="small"
                                                        :color="column.nullable ? 'grey' : 'orange'"
                                                    >
                                                        {{ column.nullable ? 'mdi-check-circle-outline' : 'mdi-alert-circle-outline' }}
                                                    </v-icon>
                                                </td>
                                                <td class="text-caption">
                                                    {{ column.description || '-' }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </div>

                                <!-- Noch nicht geladen - zeigt nichts, weil onPanelExpand automatisch lädt -->
                                <div v-else-if="!columnsLoaded[table.table_name]" class="d-flex align-center justify-center pa-4">
                                    <v-progress-circular indeterminate size="24" class="mr-2" />
                                    <span class="text-caption">{{ t('DeveloperTools.table_list.loading_columns') }}</span>
                                </div>

                                <!-- Keine Spalten gefunden (nach dem Laden) -->
                                <div v-else class="text-caption text-grey pa-3">
                                    {{ t('DeveloperTools.table_list.no_columns') }}
                                </div>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>
                </div>

                <!-- Keine Ergebnisse -->
                <v-alert v-else type="warning" variant="tonal">
                    {{ t('DeveloperTools.table_list.no_results') }}
                </v-alert>

                <!-- Hinweis -->
                <v-alert type="info" variant="tonal" class="mt-4" density="compact">
                    <v-icon start size="small">mdi-information</v-icon>
                    {{ t('DeveloperTools.table_list.usage_hint') }}
                </v-alert>

                <!-- Snackbar für Kopier-Feedback -->
                <v-snackbar
                    v-model="showCopySnackbar"
                    :timeout="2000"
                    color="success"
                    location="bottom"
                >
                    {{ t('DeveloperTools.table_list.json_copied') }}
                </v-snackbar>
            </v-card-text>

            <v-card-actions class="px-4 pb-4">
                <v-spacer />
                <v-btn
                    color="primary"
                    variant="elevated"
                    @click="close"
                >
                    {{ t('DeveloperTools.table_list.close') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    database: {
        type: String,
        default: 'company'
    }
});

const emit = defineEmits(['close', 'insert-table']);

const dialogVisible = ref(true);
const loading = ref(false);
const tables = ref([]);
const searchQuery = ref('');
const filteredTables = ref([]);
const showCopySnackbar = ref(false);
const expandedPanels = ref([]);
const loadingColumns = ref({});
const columnsLoaded = ref({});

/**
 * Tabellenbeschreibungen (hardcoded Fallbacks)
 */
const tableDescriptions = {
    // Stammdaten
    'customer': 'Kunden',
    'vendor': 'Lieferanten',
    'contacts': 'Ansprechpersonen für Kunden und Lieferanten',
    'shipto': 'Lieferadressen',
    'employee': 'Mitarbeiter',
    'parts': 'Artikel und Dienstleistungen',
    'assembly': 'Erzeugnisse/Baugruppen',

    // Belege
    'ar': 'Ausgangsrechnungen (Debitorenrechnungen)',
    'ap': 'Eingangsrechnungen (Kreditorenrechnungen)',
    'invoice': 'Rechnungspositionen',
    'oe': 'Angebote und Aufträge',
    'orderitems': 'Angebots- und Auftragspositionen',
    'delivery_orders': 'Lieferscheine',
    'delivery_order_items': 'Lieferscheinpositionen',

    // Buchführung
    'acc_trans': 'Buchungsjournal',
    'chart': 'Kontenrahmen',
    'buchungsgruppen': 'Buchungsgruppen',
    'taxzone_charts': 'Konten pro Buchungsgruppe und Steuerzone',
    'tax': 'Steuerschlüssel',
    'tax_zones': 'Steuerzonen (Inland, EU, Drittland)',

    // Bank
    'bank_accounts': 'Bankkonten',
    'bank_transactions': 'Banktransaktionen',
    'sepa_export': 'SEPA-Exporte',
    'sepa_export_items': 'SEPA-Export-Positionen',

    // Lager
    'warehouse': 'Lager',
    'bin': 'Lagerplätze',
    'inventory': 'Lagerbestand',

    // CRM
    'follow_ups': 'Wiedervorlagen',
    'follow_up_links': 'Verknüpfungen zu Wiedervorlagen',
    'notes': 'Notizen',
    'history_erp': 'Änderungshistorie',

    // Auth-Schema
    'auth.user': 'Benutzer',
    'auth.group': 'Benutzergruppen',
    'auth.clients': 'Mandanten',
    'auth.session': 'Sitzungen',
};

/**
 * Lädt alle Tabellen aus der Datenbank
 */
async function loadTables() {
    loading.value = true;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getKivitendoTables',
            database: props.database
        });

        if (response.data.success && response.data.payload && response.data.payload.tables) {
            // Füge Beschreibungen hinzu: DB-Beschreibung hat Vorrang, sonst Fallback aus Code
            const tablesWithDescriptions = response.data.payload.tables.map(table => {
                const tableName = table.schema === 'auth'
                    ? `auth.${table.table_name}`
                    : table.table_name;

                // Verwende DB-Beschreibung wenn vorhanden, sonst Fallback aus tableDescriptions
                const description = table.description ||
                                   tableDescriptions[tableName] ||
                                   tableDescriptions[table.table_name] ||
                                   '';

                return {
                    table_name: table.table_name,
                    schema: table.schema,
                    description: description,
                    columns: table.columns || [],
                    source: table.description ? 'database' : 'code'
                };
            });

            tables.value = tablesWithDescriptions;
            filteredTables.value = tablesWithDescriptions;
        } else {
            console.error('Failed to load tables:', response.data);
        }
    } catch (error) {
        console.error('Error loading tables:', error);
    } finally {
        loading.value = false;
    }
}

/**
 * Filtert Tabellen basierend auf Suchanfrage
 */
function filterTables() {
    if (!searchQuery.value) {
        filteredTables.value = tables.value;
        return;
    }

    const query = searchQuery.value.toLowerCase();
    filteredTables.value = tables.value.filter(table => {
        // Suche in Tabellenname und Beschreibung
        const matchesTable = table.table_name.toLowerCase().includes(query) ||
                            (table.description && table.description.toLowerCase().includes(query));

        // Suche auch in Spaltennamen und Beschreibungen
        const matchesColumns = table.columns && table.columns.some(col =>
            col.name.toLowerCase().includes(query) ||
            (col.description && col.description.toLowerCase().includes(query))
        );

        return matchesTable || matchesColumns;
    });
}

/**
 * Wird aufgerufen wenn ein Expansion-Panel geöffnet/geschlossen wird
 * Lädt Spalten-Informationen beim ersten Öffnen
 */
async function onPanelExpand(value) {
    // Bei accordion variant ist value der Index des geöffneten Panels (oder undefined wenn geschlossen)
    // Bei multiple kann es ein Array sein
    let panelIndex = value;

    // Falls Array (multiple mode), nehme den letzten/neuesten
    if (Array.isArray(value)) {
        if (value.length === 0) return;
        panelIndex = value[value.length - 1];
    }

    // Kein Panel geöffnet
    if (panelIndex === undefined || panelIndex === null) {
        return;
    }

    const table = filteredTables.value[panelIndex];
    if (!table) {
        console.warn('Table not found at index', panelIndex);
        return;
    }

    // Spalten bereits geladen?
    if (columnsLoaded.value[table.table_name]) {
        return;
    }

    // Bereits am Laden?
    if (loadingColumns.value[table.table_name]) {
        return;
    }

    // Spalten laden
    loadingColumns.value[table.table_name] = true;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getTableStructure',
            table: table.table_name,
            database: props.database
        });

        console.log('getTableStructure response for', table.table_name, response.data);

        if (response.data.success && response.data.payload && response.data.payload.structure) {
            const structure = response.data.payload.structure;

            // Finde die Tabelle in beiden Arrays und update die Spalten
            const updateColumns = (arr) => {
                const idx = arr.findIndex(t => t.table_name === table.table_name);
                if (idx !== -1 && structure.columns) {
                    arr[idx].columns = structure.columns.map(col => ({
                        name: col.column_name,
                        type: col.data_type,
                        nullable: col.is_nullable === 'YES',
                        default: col.column_default,
                        description: col.comment || ''
                    }));

                    // Tabellen-Kommentar updaten falls vorhanden
                    if (structure.table_comment && !arr[idx].description) {
                        arr[idx].description = structure.table_comment;
                    }
                }
            };

            updateColumns(tables.value);
            updateColumns(filteredTables.value);
        } else {
            console.error('getTableStructure failed:', response.data);
        }
    } catch (error) {
        console.error('Error loading columns for', table.table_name, error);
    } finally {
        loadingColumns.value[table.table_name] = false;
        columnsLoaded.value[table.table_name] = true;
    }
}

/**
 * Formatiert Spaltentyp für Anzeige
 */
function formatColumnType(column) {
    let type = column.type;
    if (column.max_length) {
        type += `(${column.max_length})`;
    }
    return type;
}

/**
 * Kopiert Tabellen-Schema als JSON in die Zwischenablage
 */
async function copyTableSchema(table) {
    const schema = {
        table_name: table.table_name,
        schema: table.schema,
        description: table.description,
        columns: table.columns.map(col => ({
            name: col.name,
            type: formatColumnType(col),
            nullable: col.nullable,
            default: col.default,
            description: col.description
        }))
    };

    try {
        await navigator.clipboard.writeText(JSON.stringify(schema, null, 2));
        showCopySnackbar.value = true;
    } catch (error) {
        console.error('Failed to copy JSON:', error);
    }
}

/**
 * Fügt Tabellennamen in SQL-Editor ein
 */
function insertTable(tableName) {
    emit('insert-table', tableName);
    close();
}

/**
 * Schließt den Dialog
 */
function close() {
    dialogVisible.value = false;
    emit('close');
}

onMounted(() => {
    loadTables();
});
</script>

<style scoped>
.table-expansion-panels {
    max-height: calc(70vh - 200px);
    overflow-y: auto;
}

.table-panel :deep(.v-expansion-panel-title) {
    padding: 12px 16px;
}

.columns-details {
    padding: 8px;
}

.columns-table {
    border: 1px solid rgba(0, 0, 0, 0.12);
    border-radius: 4px;
}

.columns-table th {
    background-color: rgba(0, 0, 0, 0.03);
    font-weight: 600;
    font-size: 0.75rem;
}

.columns-table td {
    font-size: 0.875rem;
}
</style>