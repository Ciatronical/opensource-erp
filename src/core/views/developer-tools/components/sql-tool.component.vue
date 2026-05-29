<!-- src/core/views/system/components/sql-tool.component.vue -->
<template>
    <v-card variant="outlined">
        <v-card-title class="bg-grey-lighten-4">
            <v-icon start color="info">mdi-code-braces</v-icon>
            {{ t('DeveloperTools.sql.title') }}
        </v-card-title>
        <v-card-text class="pa-4">
            <!-- Datenbank-Auswahl & Tabellenliste Button -->
            <div class="d-flex align-center mb-4">
                <v-select
                    v-model="selectedDatabase"
                    :items="databaseOptions"
                    item-title="label"
                    item-value="value"
                    :label="t('DeveloperTools.sql.database_label')"
                    density="compact"
                    variant="outlined"
                    style="max-width: 400px;"
                />

                <v-btn
                    color="info"
                    prepend-icon="mdi-table-large"
                    variant="outlined"
                    density="compact"
                    class="ml-3"
                    @click="showTableListDialog = true"
                >
                    {{ t('DeveloperTools.sql.table_list_button') }}
                </v-btn>
            </div>

            <p class="mb-4 text-caption text-grey" v-html="t('DeveloperTools.sql.warning') + '<br>' + t('DeveloperTools.sql.multi_query_tip')"></p>

            <!-- Spaltenanzeige -->
            <v-card v-if="selectedTableForColumns && tableColumns.length > 0" variant="outlined" class="mb-4 column-display-card">
                <v-card-title class="bg-blue-lighten-5 text-subtitle-2 py-2">
                    <v-icon start size="small" color="blue">mdi-table-column</v-icon>
                    {{ t('DeveloperTools.sql.columns_of', { table: selectedTableForColumns }) }}
                    <v-spacer />
                    <v-chip size="small" variant="flat" color="blue">
                        {{ t('DeveloperTools.sql.column_count', { count: tableColumns.length }) }}
                    </v-chip>
                    <v-btn
                        icon
                        size="x-small"
                        variant="text"
                        @click="closeColumnDisplay"
                        class="ml-2"
                    >
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>
                <v-card-text class="pa-3">
                    <div class="columns-grid">
                        <v-chip
                            v-for="column in tableColumns"
                            :key="column.name"
                            size="small"
                            variant="outlined"
                            color="blue-darken-2"
                            class="column-chip"
                            @click="insertColumnAtCursor(column.name)"
                        >
                            <v-icon start size="x-small">mdi-table-column</v-icon>
                            <strong>{{ column.name }}</strong>
                            <span class="ml-2 text-grey text-caption">({{ column.type }})</span>
                        </v-chip>
                    </div>
                </v-card-text>
            </v-card>

            <!-- SQL Input mit Autocomplete und Syntax-Highlighting -->
            <div class="sql-editor-wrapper mb-4">
                <pre
                    ref="sqlHighlightPre"
                    class="sql-highlight"
                    aria-hidden="true"
                    v-html="highlightedSql"
                ></pre>
                <textarea
                    ref="sqlTextarea"
                    v-model="sqlQuery"
                    class="sql-textarea"
                    spellcheck="false"
                   :placeholder="selectedDatabase === 'company'
                    ?   'SELECT * FROM customer LIMIT 10;\n' +
                        'UPDATE customer SET name = \'Neuer Name\' WHERE id = 1;\n' +
                        'INSERT INTO customer (name, street, city) VALUES (\'Firma GmbH\', \'Hauptstr. 1\', \'Berlin\');\n' +
                        'DELETE FROM customer WHERE id = 1;\n' +
                        'SELECT c.*, e.name as employee_name FROM customer c LEFT JOIN employee e ON c.salesman_id = e.id LIMIT 10;'
                    :   'SELECT * FROM auth.user LIMIT 10;\n' +
                        'UPDATE auth.user SET name = \'Neuer Name\' WHERE id = 1;\n' +
                        'INSERT INTO auth.user (name, login, password) VALUES (\'Max Mustermann\', \'mmustermann\', \'hash123\');\n' +
                        'DELETE FROM auth.user WHERE id = 1;\n' +
                        'SELECT u.*, r.name as role_name FROM auth.user u LEFT JOIN auth.role r ON u.role_id = r.id LIMIT 10;'"
                    rows="12"
                    @keydown="handleKeydown"
                    @input="handleInput"
                    @scroll="syncScroll"
                ></textarea>

                <!-- Autocomplete Dropdown -->
                <div
                    v-if="showAutocomplete && suggestions.length > 0"
                    class="autocomplete-dropdown"
                    :style="{ top: dropdownPosition.top + 'px', left: dropdownPosition.left + 'px' }"
                >
                    <div
                        v-for="(suggestion, index) in suggestions"
                        :key="index"
                        class="autocomplete-item"
                        :class="{ active: index === selectedSuggestionIndex }"
                        @click="applySuggestion(suggestion)"
                        @mouseenter="selectedSuggestionIndex = index"
                    >
                        <v-icon
                            v-if="suggestion.type === 'keyword'"
                            size="small"
                            class="suggestion-icon"
                            color="purple"
                        >
                            mdi-code-tags
                        </v-icon>
                        <v-icon
                            v-else-if="suggestion.type === 'table'"
                            size="small"
                            class="suggestion-icon"
                            color="blue"
                        >
                            mdi-table
                        </v-icon>
                        <v-icon
                            v-else-if="suggestion.type === 'column'"
                            size="small"
                            class="suggestion-icon"
                            color="green"
                        >
                            mdi-table-column
                        </v-icon>
                        <span class="suggestion-text">{{ suggestion.text }}</span>
                        <span v-if="suggestion.description" class="suggestion-desc">{{ suggestion.description }}</span>
                    </div>
                </div>
            </div>

            <v-btn
                color="primary"
                prepend-icon="mdi-play"
                :loading="sqlLoading"
                @click="executeSql"
            >
                {{ t('DeveloperTools.sql.execute_button') }}
            </v-btn>

            <v-btn
                variant="text"
                prepend-icon="mdi-delete"
                class="ml-2"
                @click="sqlQuery = ''; sqlResult = null"
            >
                {{ t('DeveloperTools.sql.clear_button') }}
            </v-btn>

            <!-- SQL Ergebnis -->
            <v-card v-if="sqlResult" variant="outlined" class="mt-4">
                <!-- Single Query Result -->
                <template v-if="!sqlResult.multiple">
                    <v-card-title class="bg-grey-lighten-5 text-subtitle-2">
                        <v-icon start size="small">mdi-table</v-icon>
                        {{ t('DeveloperTools.sql.result_title') }}
                        <v-spacer />

                        <!-- Edit Mode Toggle -->
                        <v-btn
                            v-if="sqlResult.editable && sqlResult.rows && sqlResult.rows.length > 0"
                            :color="editMode ? 'warning' : 'grey'"
                            :variant="editMode ? 'flat' : 'outlined'"
                            size="small"
                            class="mr-2"
                            @click="toggleEditMode"
                        >
                            <v-icon start size="small">{{ editMode ? 'mdi-pencil-off' : 'mdi-pencil' }}</v-icon>
                            {{ editMode ? t('DeveloperTools.sql.edit_mode_off') : t('DeveloperTools.sql.edit_mode_on') }}
                        </v-btn>

                        <!-- Info warum nicht editierbar -->
                        <v-tooltip v-if="!sqlResult.editable && sqlResult.edit_reason" location="bottom">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small" color="grey" class="mr-2">mdi-information-outline</v-icon>
                            </template>
                            {{ sqlResult.edit_reason }}
                        </v-tooltip>

                        <v-chip v-if="sqlResult.execution_time" size="small" variant="flat" class="mr-2">
                            <v-icon start size="small">mdi-clock-outline</v-icon>
                            {{ sqlResult.execution_time }}ms
                        </v-chip>
                        <v-chip size="small" variant="flat" class="mr-2">
                            {{ t('DeveloperTools.sql.rows', { count: sqlResult.rows ? sqlResult.rows.length : 0 }) }}
                        </v-chip>
                        <v-chip
                            v-if="sqlResult.auto_limited"
                            size="small"
                            color="warning"
                            variant="flat"
                            class="mr-2"
                        >
                            <v-icon start size="small">mdi-alert</v-icon>
                            {{ t('DeveloperTools.sql.auto_limited', { limit: sqlResult.auto_limit }) }}
                        </v-chip>
                        <v-btn
                            v-if="sqlResult.rows && sqlResult.rows.length > 0"
                            icon
                            size="small"
                            variant="text"
                            color="primary"
                            @click="downloadResultsCSV(sqlResult)"
                        >
                            <v-icon>mdi-download</v-icon>
                            <v-tooltip activator="parent" location="bottom">
                                {{ t('DeveloperTools.sql.download_results') }}
                            </v-tooltip>
                        </v-btn>
                    </v-card-title>
                    <v-card-text class="pa-0">
                        <!-- Tabellendarstellung für SELECT -->
                        <div v-if="sqlResult.rows && sqlResult.rows.length > 0">
                            <!-- Normale Tabelle (nicht editierbar) -->
                            <v-data-table
                                v-if="!editMode"
                                :headers="sqlTableHeaders"
                                :items="sqlResult.rows"
                                density="compact"
                                :items-per-page="50"
                                :items-per-page-options="[10, 25, 50, 100, -1]"
                                class="sql-result-table"
                            >
                                <template v-for="col in sqlResult.columns" #[`item.${col}`]="{ item }">
                                    <span :key="col" class="text-mono">{{ formatCellValue(item[col]) }}</span>
                                </template>
                            </v-data-table>

                            <!-- Editierbare Tabelle -->
                            <v-data-table
                                v-else
                                :headers="editableTableHeaders"
                                :items="editableRows"
                                density="compact"
                                :items-per-page="50"
                                :items-per-page-options="[10, 25, 50, 100, -1]"
                                class="sql-result-table editable-table"
                            >
                                <!-- Aktionen-Spalte -->
                                <template #item._actions="{ item, index }">
                                    <div class="d-flex align-center">
                                        <!-- Zeile wird gerade editiert -->
                                        <template v-if="editingRowIndex === index">
                                            <v-btn
                                                icon
                                                size="x-small"
                                                color="success"
                                                variant="flat"
                                                :loading="savingRow"
                                                @click="saveRow(index)"
                                                class="mr-1"
                                            >
                                                <v-icon size="small">mdi-check</v-icon>
                                                <v-tooltip activator="parent" location="top">Speichern</v-tooltip>
                                            </v-btn>
                                            <v-btn
                                                icon
                                                size="x-small"
                                                color="grey"
                                                variant="text"
                                                @click="cancelEdit"
                                            >
                                                <v-icon size="small">mdi-close</v-icon>
                                                <v-tooltip activator="parent" location="top">Abbrechen</v-tooltip>
                                            </v-btn>
                                        </template>

                                        <!-- Zeile nicht im Edit-Modus -->
                                        <template v-else>
                                            <v-btn
                                                icon
                                                size="x-small"
                                                color="primary"
                                                variant="text"
                                                @click="startEditRow(index)"
                                                :disabled="editingRowIndex !== null"
                                            >
                                                <v-icon size="small">mdi-pencil</v-icon>
                                                <v-tooltip activator="parent" location="top">Bearbeiten</v-tooltip>
                                            </v-btn>
                                            <v-btn
                                                icon
                                                size="x-small"
                                                color="error"
                                                variant="text"
                                                @click="confirmDeleteRow(index)"
                                                :disabled="editingRowIndex !== null"
                                            >
                                                <v-icon size="small">mdi-delete</v-icon>
                                                <v-tooltip activator="parent" location="top">Löschen</v-tooltip>
                                            </v-btn>
                                        </template>
                                    </div>
                                </template>

                                <!-- Editierbare Zellen -->
                                <template v-for="col in sqlResult.columns" #[`item.${col}`]="{ item, index }">
                                    <!-- Primärschlüssel ist nicht editierbar -->
                                    <span
                                        v-if="col === sqlResult.primary_key"
                                        :key="col"
                                        class="text-mono pk-cell"
                                    >
                                        <v-icon size="x-small" color="amber" class="mr-1">mdi-key</v-icon>
                                        {{ formatCellValue(item[col]) }}
                                    </span>

                                    <!-- Editierbare Zelle -->
                                    <template v-else>
                                        <input
                                            v-if="editingRowIndex === index"
                                            :key="col + '-edit'"
                                            v-model="editedRowData[col]"
                                            class="cell-input"
                                            :class="{ 'cell-changed': hasChanged(col, item[col]) }"
                                            @keydown.enter="saveRow(index)"
                                            @keydown.escape="cancelEdit"
                                        />
                                        <span
                                            v-else
                                            :key="col + '-view'"
                                            class="text-mono cell-value"
                                            @dblclick="startEditRow(index)"
                                        >
                                            {{ formatCellValue(item[col]) }}
                                        </span>
                                    </template>
                                </template>
                            </v-data-table>

                            <!-- Edit-Modus Hinweis -->
                            <v-alert
                                v-if="editMode"
                                type="info"
                                variant="tonal"
                                density="compact"
                                class="ma-2"
                            >
                                <v-icon start size="small">mdi-information</v-icon>
                                {{ t('DeveloperTools.sql.edit_hint') }}
                                <strong class="ml-1">{{ sqlResult.table_name }}</strong>
                                (PK: <code>{{ sqlResult.primary_key }}</code>)
                            </v-alert>
                        </div>

                        <!-- Erfolgsmeldung für INSERT/UPDATE/DELETE -->
                        <div v-else-if="sqlResult.success" class="pa-4">
                            <v-alert type="success" variant="tonal">
                                {{ sqlResult.message }}
                                <span v-if="sqlResult.affected_rows">
                                    ({{ sqlResult.affected_rows }} Zeilen betroffen)
                                </span>
                            </v-alert>
                        </div>

                        <!-- Fehlermeldung -->
                        <div v-else class="pa-4">
                            <v-alert type="error" variant="tonal">
                                {{ sqlResult.error }}
                            </v-alert>
                        </div>
                    </v-card-text>
                </template>

                <!-- Multiple Queries Result -->
                <template v-else>
                    <v-card-title class="bg-grey-lighten-5 text-subtitle-2">
                        <v-icon start size="small">mdi-table-multiple</v-icon>
                        {{ t('DeveloperTools.sql.queries_executed', { count: sqlResult.total_queries }) }}
                        <v-spacer />
                        <v-chip size="small" variant="flat" class="mr-2">
                            <v-icon start size="small">mdi-clock-outline</v-icon>
                            {{ sqlResult.total_execution_time }}ms
                        </v-chip>
                        <v-chip size="small" variant="flat" class="mr-2">
                            {{ t('DeveloperTools.sql.total_rows', { count: sqlResult.total_rows }) }}
                        </v-chip>
                        <v-btn
                            v-if="sqlResult.results && sqlResult.results.some(r => r.rows && r.rows.length > 0)"
                            icon
                            size="small"
                            variant="text"
                            color="primary"
                            @click="downloadAllResultsCSV(sqlResult.results)"
                        >
                            <v-icon>mdi-download</v-icon>
                            <v-tooltip activator="parent" location="bottom">
                                {{ t('DeveloperTools.sql.download_all_results') }}
                            </v-tooltip>
                        </v-btn>
                    </v-card-title>
                    <v-card-text class="pa-0">
                        <!-- Jedes Query-Ergebnis einzeln -->
                        <v-expansion-panels variant="accordion">
                            <v-expansion-panel
                                v-for="(result, index) in sqlResult.results"
                                :key="index"
                            >
                                <v-expansion-panel-title>
                                    <div class="d-flex align-center w-100">
                                        <v-chip size="small" class="mr-2">{{ index + 1 }}</v-chip>
                                        <span class="text-mono text-truncate" style="max-width: 60%">{{ result.query }}</span>
                                        <v-spacer />

                                        <!-- Editierbar-Indikator -->
                                        <v-icon
                                            v-if="result.editable"
                                            size="small"
                                            color="success"
                                            class="mr-2"
                                        >
                                            mdi-pencil-circle
                                        </v-icon>

                                        <v-chip size="x-small" variant="flat" class="mr-2">
                                            {{ result.execution_time }}ms
                                        </v-chip>
                                        <v-chip size="x-small" variant="flat" class="mr-2">
                                            {{ result.rows ? result.rows.length : result.affected_rows || 0 }} Zeilen
                                        </v-chip>
                                        <v-btn
                                            v-if="result.rows && result.rows.length > 0"
                                            icon
                                            size="x-small"
                                            variant="text"
                                            color="primary"
                                            @click.stop="downloadResultsCSV(result)"
                                        >
                                            <v-icon size="small">mdi-download</v-icon>
                                        </v-btn>
                                    </div>
                                </v-expansion-panel-title>
                                <v-expansion-panel-text>
                                    <!-- SELECT Result -->
                                    <div v-if="result.rows && result.rows.length > 0">
                                        <v-data-table
                                            :headers="getTableHeaders(result.columns)"
                                            :items="result.rows"
                                            density="compact"
                                            :items-per-page="25"
                                            :items-per-page-options="[10, 25, 50, -1]"
                                            class="sql-result-table"
                                        >
                                            <template v-for="col in result.columns" #[`item.${col}`]="{ item }">
                                                <span :key="col" class="text-mono">{{ formatCellValue(item[col]) }}</span>
                                            </template>
                                        </v-data-table>
                                    </div>

                                    <!-- INSERT/UPDATE/DELETE Result -->
                                    <div v-else class="pa-4">
                                        <v-alert type="success" variant="tonal">
                                            {{ result.message }}
                                            <span v-if="result.affected_rows">
                                                ({{ result.affected_rows }} Zeilen betroffen)
                                            </span>
                                        </v-alert>
                                    </div>
                                </v-expansion-panel-text>
                            </v-expansion-panel>
                        </v-expansion-panels>
                    </v-card-text>
                </template>
            </v-card>

            <!-- Query History -->
            <sql-query-history-component
                ref="queryHistoryRef"
                :database="selectedDatabase"
                @select-query="loadQueryFromHistory"
            />

            <!-- Table List Dialog -->
            <table-list-dialog
                v-if="showTableListDialog"
                :database="selectedDatabase"
                @close="showTableListDialog = false"
                @insert-table="insertTableName"
            />

            <!-- Delete Confirmation Dialog -->
            <v-dialog v-model="showDeleteDialog" max-width="500">
                <v-card>
                    <v-card-title class="bg-error text-white">
                        <v-icon start>mdi-alert</v-icon>
                        {{ t('DeveloperTools.sql.delete_confirm_title') }}
                    </v-card-title>
                    <v-card-text class="pa-4">
                        <p class="mb-3">{{ t('DeveloperTools.sql.delete_confirm_text') }}</p>
                        <v-card variant="outlined" class="pa-3 bg-grey-lighten-4">
                            <code class="text-caption">
                                {{ sqlResult?.table_name }}.{{ sqlResult?.primary_key }} = {{ deleteRowPkValue }}
                            </code>
                        </v-card>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer />
                        <v-btn
                            variant="text"
                            @click="showDeleteDialog = false"
                        >
                            {{ t('cancel') }}
                        </v-btn>
                        <v-btn
                            color="error"
                            variant="flat"
                            :loading="deletingRow"
                            @click="deleteRow"
                        >
                            <v-icon start>mdi-delete</v-icon>
                            {{ t('delete') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import SqlQueryHistoryComponent from './sql-query-history.component.vue';
import TableListDialog from '../dialogs/table-list.dialog.vue';

const { t } = useI18n();

// localStorage Keys fuer HMR-Persistenz
const DEVTOOLS_SQL_QUERY_KEY = 'devtools_sqlQuery';
const DEVTOOLS_SQL_DB_KEY = 'devtools_sqlDatabase';

// State (mit localStorage-Restore)
const sqlQuery = ref(localStorage.getItem(DEVTOOLS_SQL_QUERY_KEY) || '');
const sqlResult = ref(null);
const sqlLoading = ref(false);
const sqlTextarea = ref(null);
const sqlHighlightPre = ref(null);
const selectedDatabase = ref(localStorage.getItem(DEVTOOLS_SQL_DB_KEY) || 'company');
const queryHistoryRef = ref(null);
const selectedTableForColumns = ref('');
const tableColumns = ref([]);
const companyDbName = ref('');
const authDbName = ref('');
const showTableListDialog = ref(false);

// ── SQL Syntax-Highlighting ──

const SQL_KW_SET = new Set([
    'SELECT','FROM','WHERE','AND','OR','NOT','ORDER','BY','GROUP',
    'HAVING','LIMIT','OFFSET','JOIN','LEFT','RIGHT','INNER','OUTER','CROSS','FULL','ON',
    'INSERT','INTO','VALUES','UPDATE','SET','DELETE','CREATE','ALTER',
    'DROP','TABLE','ASC','DESC','DISTINCT','AS','COUNT','SUM','AVG',
    'MIN','MAX','CASE','WHEN','THEN','ELSE','END','NULL','IS','IN',
    'LIKE','ILIKE','BETWEEN','EXISTS','UNION','ALL','TRUE','FALSE',
    'IF','BEGIN','COMMIT','ROLLBACK','RETURNING','WITH','RECURSIVE',
    'COALESCE','CAST','EXTRACT','OVER','PARTITION','ROW_NUMBER','RANK'
]);

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

const highlightedSql = computed(() => {
    const text = sqlQuery.value;
    if (!text) return '\n';
    // Tokenize: Strings ('...'), Kommentare (-- bis Zeilenende), Wörter, Rest
    const tokenRegex = /'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"|--[^\n]*|\b\w+\b|[^\w'"-]+|-/g;
    let result = '';
    let match;
    while ((match = tokenRegex.exec(text)) !== null) {
        const token = match[0];
        if (token.startsWith("'") || token.startsWith('"')) {
            result += '<span class="sql-str">' + escapeHtml(token) + '</span>';
        } else if (token.startsWith('--')) {
            result += '<span class="sql-comment">' + escapeHtml(token) + '</span>';
        } else if (SQL_KW_SET.has(token.toUpperCase())) {
            result += '<span class="sql-kw">' + escapeHtml(token) + '</span>';
        } else {
            result += escapeHtml(token);
        }
    }
    return result + '\n'; // trailing newline damit pre-Höhe zum textarea passt
});

function syncScroll() {
    if (sqlHighlightPre.value && sqlTextarea.value) {
        sqlHighlightPre.value.scrollTop = sqlTextarea.value.scrollTop;
        sqlHighlightPre.value.scrollLeft = sqlTextarea.value.scrollLeft;
    }
}

// Edit Mode State
const editMode = ref(false);
const editingRowIndex = ref(null);
const editedRowData = ref({});
const originalRowData = ref({});
const savingRow = ref(false);
const deletingRow = ref(false);
const showDeleteDialog = ref(false);
const deleteRowIndex = ref(null);
const deleteRowPkValue = ref(null);

const databaseOptions = computed(() => {
    const options = [
        { label: t('DeveloperTools.sql.company_db'), value: 'company' },
        { label: t('DeveloperTools.sql.auth_db'), value: 'auth' }
    ];

    // Füge DB-Namen hinzu wenn verfügbar
    if (companyDbName.value) {
        options[0].label = `${t('DeveloperTools.sql.company_db')} - ${companyDbName.value}`;
    }
    if (authDbName.value) {
        options[1].label = `${t('DeveloperTools.sql.auth_db')} - ${authDbName.value}`;
    }

    return options;
});

/**
 * Lädt die Datenbanknamen und aktualisiert die Dropdown-Labels
 */
async function loadDatabaseNames() {
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getDatabaseNames'
        });

        if (response.data.success && response.data.payload) {
            // Speichere DB-Namen (computed wird automatisch aktualisiert)
            companyDbName.value = response.data.payload.company;
            authDbName.value = response.data.payload.auth;
        }
    } catch (error) {
        console.error('Fehler beim Laden der Datenbanknamen:', error);
    }
}

// Autocomplete State
const showAutocomplete = ref(false);
const suggestions = ref([]);
const selectedSuggestionIndex = ref(0);
const dropdownPosition = ref({ top: 0, left: 0 });
const tableNames = ref([]);
const columnCache = ref([]);
const columnCacheTable = ref(null);

// Computed: Table Headers
const sqlTableHeaders = computed(() => {
    if (!sqlResult.value || !sqlResult.value.columns) {
        return [];
    }
    return sqlResult.value.columns.map(col => ({
        title: col,
        key: col,
        align: 'start',
        sortable: true
    }));
});

// Computed: Editable Table Headers (mit Aktionen-Spalte)
const editableTableHeaders = computed(() => {
    if (!sqlResult.value || !sqlResult.value.columns) {
        return [];
    }

    const headers = [
        {
            title: '',
            key: '_actions',
            align: 'center',
            sortable: false,
            width: '80px'
        }
    ];

    sqlResult.value.columns.forEach(col => {
        headers.push({
            title: col === sqlResult.value.primary_key ? `🔑 ${col}` : col,
            key: col,
            align: 'start',
            sortable: true
        });
    });

    return headers;
});

// Computed: Editierbare Zeilen
const editableRows = computed(() => {
    if (!sqlResult.value || !sqlResult.value.rows) {
        return [];
    }
    return sqlResult.value.rows;
});

/**
 * Formatiert Zellenwerte für die Anzeige
 */
function formatCellValue(value) {
    if (value === null) {
        return 'NULL';
    }
    if (value === true) {
        return 'true';
    }
    if (value === false) {
        return 'false';
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }
    return value;
}

/**
 * Schaltet Edit-Modus um
 */
function toggleEditMode() {
    editMode.value = !editMode.value;
    if (!editMode.value) {
        cancelEdit();
    }
}

/**
 * Startet die Bearbeitung einer Zeile
 */
function startEditRow(index) {
    const row = editableRows.value[index];
    editingRowIndex.value = index;
    originalRowData.value = { ...row };
    editedRowData.value = { ...row };
}

/**
 * Bricht die Bearbeitung ab
 */
function cancelEdit() {
    editingRowIndex.value = null;
    editedRowData.value = {};
    originalRowData.value = {};
}

/**
 * Prüft ob ein Wert geändert wurde
 */
function hasChanged(column, originalValue) {
    const editedValue = editedRowData.value[column];

    // NULL-Handling
    if (originalValue === null && (editedValue === '' || editedValue === 'NULL')) {
        return false;
    }

    // String-Vergleich
    return String(editedValue) !== String(originalValue);
}

/**
 * Speichert eine bearbeitete Zeile
 */
async function saveRow(index) {
    if (!sqlResult.value?.table_name || !sqlResult.value?.primary_key) {
        return;
    }

    // Sammle nur geänderte Werte
    const updates = {};
    const row = editableRows.value[index];

    for (const col of sqlResult.value.columns) {
        if (col !== sqlResult.value.primary_key && hasChanged(col, row[col])) {
            let value = editedRowData.value[col];

            // NULL-Handling
            if (value === '' || value === 'NULL') {
                value = null;
            }

            updates[col] = value;
        }
    }

    // Keine Änderungen?
    if (Object.keys(updates).length === 0) {
        cancelEdit();
        return;
    }

    savingRow.value = true;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'updateTableRow',
            table: sqlResult.value.table_name,
            primary_key: sqlResult.value.primary_key,
            primary_value: row[sqlResult.value.primary_key],
            updates: updates,
            database: selectedDatabase.value
        });

        if (response.data.success) {
            // Aktualisiere die Zeile im Result
            if (response.data.payload?.updated_row) {
                Object.assign(sqlResult.value.rows[index], response.data.payload.updated_row);
            }
            cancelEdit();
        } else {
            alert(response.data.text || 'Fehler beim Speichern');
        }
    } catch (error) {
        console.error('Fehler beim Speichern:', error);
        alert(error.response?.data?.text || error.message);
    } finally {
        savingRow.value = false;
    }
}

/**
 * Zeigt Lösch-Bestätigung
 */
function confirmDeleteRow(index) {
    const row = editableRows.value[index];
    deleteRowIndex.value = index;
    deleteRowPkValue.value = row[sqlResult.value.primary_key];
    showDeleteDialog.value = true;
}

/**
 * Löscht eine Zeile
 */
async function deleteRow() {
    if (deleteRowIndex.value === null || !sqlResult.value?.table_name || !sqlResult.value?.primary_key) {
        return;
    }

    deletingRow.value = true;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'deleteTableRow',
            table: sqlResult.value.table_name,
            primary_key: sqlResult.value.primary_key,
            primary_value: deleteRowPkValue.value,
            database: selectedDatabase.value
        });

        if (response.data.success) {
            // Entferne Zeile aus Result
            sqlResult.value.rows.splice(deleteRowIndex.value, 1);
            sqlResult.value.row_count--;
            showDeleteDialog.value = false;
        } else {
            alert(response.data.text || 'Fehler beim Löschen');
        }
    } catch (error) {
        console.error('Fehler beim Löschen:', error);
        alert(error.response?.data?.text || error.message);
    } finally {
        deletingRow.value = false;
        deleteRowIndex.value = null;
        deleteRowPkValue.value = null;
    }
}

/**
 * Erstellt Table Headers für ein einzelnes Query-Result (bei multiple queries)
 */
function getTableHeaders(columns) {
    if (!columns) {
        return [];
    }
    return columns.map(col => ({
        title: col,
        key: col,
        align: 'start',
        sortable: true
    }));
}

/**
 * Lädt Tabellennamen beim Start
 */
async function loadTableNames() {
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getTableNames',
            database: selectedDatabase.value
        });

        if (response.data.success && response.data.payload) {
            tableNames.value = response.data.payload.tables || [];
        }
    } catch (error) {
        console.error('Fehler beim Laden der Tabellennamen:', error);
    }
}

/**
 * Lädt Spaltennamen für eine Tabelle (mit Cache)
 */
async function loadColumnNamesForTable(tableName) {
    if (columnCacheTable.value === tableName && columnCache.value.length > 0) {
        return;
    }

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getColumnNames',
            table: tableName,
            database: selectedDatabase.value
        });

        if (response.data.success && response.data.payload) {
            columnCache.value = response.data.payload.columns || [];
            columnCacheTable.value = tableName;
        }
    } catch (error) {
        console.error('Fehler beim Laden der Spaltennamen:', error);
        columnCache.value = [];
    }
}

/**
 * Lädt Spalten für die im Autocomplete ausgewählte Tabelle
 */
async function loadColumnsForSelectedTable(tableName) {
    if (!tableName) {
        return;
    }

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getColumnNames',
            table: tableName,
            database: selectedDatabase.value
        });

        if (response.data.success && response.data.payload) {
            tableColumns.value = response.data.payload.columns || [];
            selectedTableForColumns.value = tableName;
        } else {
            tableColumns.value = [];
            selectedTableForColumns.value = '';
        }
    } catch (error) {
        console.error('Fehler beim Laden der Spaltennamen:', error);
        tableColumns.value = [];
        selectedTableForColumns.value = '';
    }
}

/**
 * Schließt die Spaltenanzeige
 */
function closeColumnDisplay() {
    tableColumns.value = [];
    selectedTableForColumns.value = '';
}

/**
 * Fügt einen Spaltennamen an der Cursor-Position ein
 */
function insertColumnAtCursor(columnName) {
    if (!sqlTextarea.value) {
        return;
    }

    const textarea = sqlTextarea.value;
    const cursorPos = textarea.selectionStart;
    const beforeCursor = sqlQuery.value.substring(0, cursorPos);
    const afterCursor = sqlQuery.value.substring(cursorPos);

    const beforeTrimmed = beforeCursor.trimEnd();
    const afterTrimmed = afterCursor.trimStart();
    const beforeUpper = beforeTrimmed.toUpperCase();

    // Prüfe ob der Cursor in der SELECT-Liste steht
    const inSelectList = beforeUpper.includes('SELECT') &&
                         !beforeUpper.includes('FROM');

    if (inSelectList) {
        const selectIdx = beforeUpper.lastIndexOf('SELECT');
        const afterSelect = beforeTrimmed.substring(selectIdx + 6).trim();

        // Links: Komma nötig wenn bereits Spalten vorhanden
        let prefix = ' ';
        if (afterSelect.length > 0 && !afterSelect.endsWith(',')) {
            prefix = ', ';
        }

        // Rechts: Komma nötig wenn danach noch ein Spaltenname oder * kommt
        let suffix = ' ';
        const afterUpperTrimmed = afterTrimmed.toUpperCase();
        const sqlKeywords = ['FROM', 'WHERE', 'ORDER', 'GROUP', 'HAVING', 'LIMIT', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'UNION'];
        const startsWithKeyword = sqlKeywords.some(kw => afterUpperTrimmed.startsWith(kw));

        if (afterTrimmed.length > 0 && !startsWithKeyword &&
            !afterTrimmed.startsWith(',') && !afterTrimmed.startsWith(')')) {
            suffix = ', ';
        }

        sqlQuery.value = beforeTrimmed + prefix + columnName + suffix + afterTrimmed;

        nextTick(() => {
            const newCursorPos = beforeTrimmed.length + prefix.length + columnName.length;
            textarea.setSelectionRange(newCursorPos, newCursorPos);
            textarea.focus();
        });
    } else {
        // Standard-Verhalten für alle anderen Positionen
        const needsPrefix = beforeCursor.length > 0 &&
                            !beforeCursor.endsWith(' ') &&
                            !beforeCursor.endsWith(',') &&
                            !beforeCursor.endsWith('(');

        const prefix = needsPrefix ? ', ' : '';
        // Leerzeichen anhängen wenn danach kein Leerzeichen/Komma folgt
        const needsSuffix = afterCursor.length > 0 &&
                            !afterCursor.startsWith(' ') &&
                            !afterCursor.startsWith(',');
        const suffix = needsSuffix ? ' ' : '';
        const insertText = prefix + columnName + suffix;

        sqlQuery.value = beforeCursor + insertText + afterCursor;

        nextTick(() => {
            const newCursorPos = cursorPos + prefix.length + columnName.length;
            textarea.setSelectionRange(newCursorPos, newCursorPos);
            textarea.focus();
        });
    }
}

/**
 * Prüft ob wir in einem String sind (innerhalb von Quotes)
 */
function isInsideQuotes(text) {
    const singleQuotes = (text.match(/'/g) || []).length;
    const doubleQuotes = (text.match(/"/g) || []).length;
    return (singleQuotes % 2 === 1) || (doubleQuotes % 2 === 1);
}

/**
 * Findet die Tabelle im Query
 */
function findTableInQuery(query) {
    const match = query.match(/FROM\s+(\w+)/i);
    return match ? match[1] : null;
}

/**
 * Holt das letzte Wort vor dem Cursor
 */
function getLastWord(text) {
    const match = text.match(/(\w+)$/);
    return match ? match[1] : '';
}

/**
 * Bestimmt welche Clausel wir gerade sind (WHERE, ORDER BY, GROUP BY, etc.)
 */
function getCurrentClause(text) {
    const upperText = text.toUpperCase();

    // Finde die letzte Position von jeder Clausel
    const positions = {
        SELECT: upperText.lastIndexOf('SELECT'),
        FROM: upperText.lastIndexOf('FROM'),
        WHERE: upperText.lastIndexOf('WHERE'),
        GROUP_BY: upperText.lastIndexOf('GROUP BY'),
        HAVING: upperText.lastIndexOf('HAVING'),
        ORDER_BY: upperText.lastIndexOf('ORDER BY'),
        LIMIT: upperText.lastIndexOf('LIMIT'),
        OFFSET: upperText.lastIndexOf('OFFSET'),
        JOIN: Math.max(
            upperText.lastIndexOf(' JOIN'),
            upperText.lastIndexOf('LEFT JOIN'),
            upperText.lastIndexOf('RIGHT JOIN'),
            upperText.lastIndexOf('INNER JOIN')
        )
    };

    // Finde die Clausel mit der höchsten Position
    let currentClause = 'NONE';
    let maxPos = -1;

    for (const [clause, pos] of Object.entries(positions)) {
        if (pos > maxPos) {
            maxPos = pos;
            currentClause = clause;
        }
    }

    return currentClause;
}

/**
 * Prüft ob nach dem letzten Keyword ein Spaltenname erwartet wird
 */
function expectsColumnName(text, clause) {
    const upperText = text.toUpperCase();

    // Liste von Kontexten wo Spaltennamen erwartet werden
    const columnContexts = [
        /WHERE\s+\w*$/i,           // WHERE i
        /AND\s+\w*$/i,             // AND i
        /OR\s+\w*$/i,              // OR i
        /ON\s+\w*$/i,              // ON i
        /HAVING\s+\w*$/i,          // HAVING i
        /ORDER BY\s+\w*$/i,        // ORDER BY i
        /GROUP BY\s+\w*$/i,        // GROUP BY i
        /,\s*\w*$/                 // , i (in SELECT oder ORDER BY)
    ];

    // Prüfe ob wir in einem dieser Kontexte sind
    for (const pattern of columnContexts) {
        if (pattern.test(text)) {
            return true;
        }
    }

    // In ORDER BY Clausel: Nach Spaltenname kommt ASC/DESC oder Komma
    if (clause === 'ORDER_BY') {
        // Wenn letztes Wort ein Spaltenname sein könnte (kein ASC/DESC/Komma folgt)
        if (!/(?:ASC|DESC|,)\s*\w*$/i.test(text)) {
            return true;
        }
    }

    return false;
}

/**
 * Prüft ob ein Operator erwartet wird (nach Spaltenname in WHERE/HAVING)
 */
function expectsOperator(text, clause) {
    // Nur in WHERE, HAVING, ON relevant
    if (!['WHERE', 'HAVING', 'JOIN'].includes(clause)) {
        return false;
    }

    // Pattern: Nach WHERE/AND/OR/ON kommt Spaltenname, dann Leerzeichen
    // z.B. "WHERE id " oder "AND delivery_term_id I"
    const afterColumnPattern = /(?:WHERE|AND|OR|ON|HAVING)\s+\w+\s+\w*$/i;
    return afterColumnPattern.test(text);
}

/**
 * Prüft ob ein Tablenname erwartet wird
 */
function expectsTableName(text) {
    // Nach FROM, JOIN, UPDATE, INTO, TABLE
    return /(?:FROM|JOIN|UPDATE|INTO|TABLE)\s+\w*$/i.test(text);
}

/**
 * Prüft ob ein Wert erwartet wird (nach Operator)
 */
function expectsValue(text) {
    // Nach Vergleichsoperatoren wird ein Wert erwartet, keine Keywords
    // z.B. "WHERE id = " oder "AND name LIKE " oder "WHERE status IN "
    const afterOperatorPattern = /(?:=|!=|<>|<|>|<=|>=|LIKE|ILIKE|IN)\s+\w*$/i;
    return afterOperatorPattern.test(text);
}

/**
 * Analysiert den Kontext und gibt Suggestions zurück
 */
async function getSuggestions(query, cursorPos) {
    const beforeCursor = query.substring(0, cursorPos);
    const lastWord = getLastWord(beforeCursor);

    if (!lastWord) {
        return [];
    }

    // REGEL 1: Niemals in Quotes
    if (isInsideQuotes(beforeCursor)) {
        return [];
    }

    // REGEL 2: Wert erwartet? (nach Operator) → KEINE Vorschläge
    if (expectsValue(beforeCursor)) {
        return [];
    }

    const results = [];
    const lowerLastWord = lastWord.toLowerCase();
    const upperLastWord = lastWord.toUpperCase();

    // Finde Tabelle
    const currentTable = findTableInQuery(query);

    // Bestimme aktuelle Clausel
    const currentClause = getCurrentClause(beforeCursor);

    // REGEL 3: Tabellennamen erwartet?
    if (expectsTableName(beforeCursor)) {
        tableNames.value.forEach(table => {
            // Bei auth-Datenbank: Schema-Präfix hinzufügen
            const tableWithSchema = selectedDatabase.value === 'auth' ? 'auth.' + table : table;

            if (table.toLowerCase().startsWith(lowerLastWord)) {
                results.push({
                    text: tableWithSchema + ' ',
                    type: 'table',
                    description: 'Tabelle'
                });
            }
        });
        return results.slice(0, 10);
    }

    // REGEL 4: Operator erwartet? (nach Spaltenname in WHERE)
    if (expectsOperator(beforeCursor, currentClause)) {
        const operators = [
            '=', '!=', '<>', '<', '>', '<=', '>=',
            'LIKE', 'ILIKE', 'NOT LIKE', 'NOT ILIKE',
            'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL', 'BETWEEN'
        ];

        operators.forEach(op => {
            if (op.startsWith(upperLastWord) || op.toLowerCase().startsWith(lowerLastWord)) {
                results.push({
                    text: op + ' ',
                    type: 'keyword',
                    description: 'Operator'
                });
            }
        });
        return results.slice(0, 10);
    }

    // REGEL 5: Spaltenname erwartet?
    if (expectsColumnName(beforeCursor, currentClause) && currentTable) {
        await loadColumnNamesForTable(currentTable);

        // In SELECT-Liste UND vor FROM: Komma anhängen
        // Prüfe ob FROM vor dem Cursor ist (nicht im ganzen Query!)
        const hasFromBeforeCursor = beforeCursor.toUpperCase().includes('FROM');
        const isInSelectList = currentClause === 'SELECT' && !hasFromBeforeCursor;
        const suffix = isInSelectList ? ', ' : ' ';

        columnCache.value.forEach(col => {
            if (col.name.toLowerCase().startsWith(lowerLastWord)) {
                results.push({
                    text: col.name + suffix,
                    type: 'column',
                    description: col.type
                });
            }
        });
        return results.slice(0, 10);
    }

    // REGEL 6: SQL Keywords
    const keywords = [
        'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT',
        'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET',
        'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'ON',
        'INSERT INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE FROM',
        'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE',
        'ASC', 'DESC', 'DISTINCT', 'AS',
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
        'CASE', 'WHEN', 'THEN', 'ELSE', 'END'
    ];

    keywords.forEach(keyword => {
        if (keyword.startsWith(upperLastWord)) {
            if (keyword === 'SELECT') {
                results.push({
                    text: 'SELECT * FROM ',
                    type: 'keyword',
                    description: 'SQL Keyword'
                });
                results.push({
                    text: 'SELECT * ',
                    type: 'keyword',
                    description: 'SQL Keyword'
                });
                results.push({
                    text: 'SELECT ',
                    type: 'keyword',
                    description: 'SQL Keyword'
                });
            } else {
                results.push({
                    text: keyword + ' ',
                    type: 'keyword',
                    description: 'SQL Keyword'
                });
            }
        }
    });

    return results.slice(0, 10);
}

/**
 * Berechnet Position für Autocomplete-Dropdown
 */
function calculateDropdownPosition() {
    if (!sqlTextarea.value) return;

    const textarea = sqlTextarea.value;
    const cursorPos = textarea.selectionStart;

    const textBeforeCursor = textarea.value.substring(0, cursorPos);
    const lines = textBeforeCursor.split('\n');
    const currentLineNumber = lines.length;
    const currentLineText = lines[lines.length - 1];

    const lineHeight = 22;
    const charWidth = 8.4;
    const padding = 12;

    const top = (currentLineNumber * lineHeight) + padding;
    const left = (currentLineText.length * charWidth) + padding;

    dropdownPosition.value = {
        top: Math.min(top, 200),
        left: Math.min(left, 100)
    };
}

/**
 * Input Handler
 */
async function handleInput() {
    const cursorPos = sqlTextarea.value.selectionStart;
    const newSuggestions = await getSuggestions(sqlQuery.value, cursorPos);

    if (newSuggestions.length > 0) {
        suggestions.value = newSuggestions;
        selectedSuggestionIndex.value = 0;
        showAutocomplete.value = true;
        calculateDropdownPosition();
    } else {
        showAutocomplete.value = false;
    }
}

/**
 * Keyboard Handler
 */
function handleKeydown(event) {
    // Auto-Closing Quotes
    if (event.key === "'" || event.key === '"') {
        const textarea = sqlTextarea.value;
        const cursorPos = textarea.selectionStart;
        const beforeCursor = sqlQuery.value.substring(0, cursorPos);
        const afterCursor = sqlQuery.value.substring(cursorPos);

        // Wenn bereits in String, normales Verhalten
        if (isInsideQuotes(beforeCursor)) {
            return;
        }

        // Füge schließendes Quote hinzu
        event.preventDefault();
        const quote = event.key;
        sqlQuery.value = beforeCursor + quote + quote + afterCursor;

        // Setze Cursor zwischen die Quotes
        nextTick(() => {
            textarea.setSelectionRange(cursorPos + 1, cursorPos + 1);
            textarea.focus();
        });
        return;
    }

    // Smart Pair Deletion - Backspace löscht beide Quotes wenn Cursor dazwischen
    if (event.key === 'Backspace') {
        const textarea = sqlTextarea.value;
        const cursorPos = textarea.selectionStart;
        const beforeCursor = sqlQuery.value.substring(0, cursorPos);
        const afterCursor = sqlQuery.value.substring(cursorPos);

        // Prüfe ob Cursor zwischen zwei gleichen Quotes steht
        // z.B. "|'" oder "|""
        const charBefore = beforeCursor.charAt(beforeCursor.length - 1);
        const charAfter = afterCursor.charAt(0);

        if ((charBefore === "'" && charAfter === "'") || (charBefore === '"' && charAfter === '"')) {
            // Lösche beide Quotes
            event.preventDefault();
            sqlQuery.value = beforeCursor.slice(0, -1) + afterCursor.slice(1);

            // Cursor bleibt an gleicher Position (jetzt zwischen den gelöschten Quotes)
            nextTick(() => {
                textarea.setSelectionRange(cursorPos - 1, cursorPos - 1);
                textarea.focus();
            });
            return;
        }
    }

    // Autocomplete ist aktiv
    if (showAutocomplete.value && suggestions.value.length > 0) {
        if (event.key === 'Tab' || event.key === 'Enter') {
            event.preventDefault();
            applySuggestion(suggestions.value[selectedSuggestionIndex.value]);
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            selectedSuggestionIndex.value = Math.min(
                selectedSuggestionIndex.value + 1,
                suggestions.value.length - 1
            );
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            selectedSuggestionIndex.value = Math.max(selectedSuggestionIndex.value - 1, 0);
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            showAutocomplete.value = false;
            return;
        }
    }

    else {
        if (event.key === 'Enter' && !event.ctrlKey && !event.shiftKey) {
            event.preventDefault();
            executeSql();
            return;
        }

        if (event.key === 'Enter' && (event.ctrlKey || event.shiftKey)) {
            return;
        }
    }
}

/**
 * Wendet eine Suggestion an
 */
function applySuggestion(suggestion) {
    const cursorPos = sqlTextarea.value.selectionStart;
    const beforeCursor = sqlQuery.value.substring(0, cursorPos);
    const afterCursor = sqlQuery.value.substring(cursorPos);

    const lastWordStart = beforeCursor.lastIndexOf(getLastWord(beforeCursor));
    const newBefore = beforeCursor.substring(0, lastWordStart);

    sqlQuery.value = newBefore + suggestion.text + afterCursor;

    nextTick(() => {
        const newCursorPos = (newBefore + suggestion.text).length;
        sqlTextarea.value.setSelectionRange(newCursorPos, newCursorPos);
        sqlTextarea.value.focus();
    });

    showAutocomplete.value = false;

    // Wenn eine Tabelle ausgewählt wurde, lade die Spalten
    if (suggestion.type === 'table') {
        // Entferne Schema-Präfix falls vorhanden
        const tableName = suggestion.text.replace('auth.', '').trim();
        loadColumnsForSelectedTable(tableName);
    }
}

/**
 * Führt SQL-Abfrage aus
 */
async function executeSql() {
    if (!sqlQuery.value.trim()) {
        return;
    }

    sqlLoading.value = true;
    sqlResult.value = null;
    editMode.value = false;
    cancelEdit();

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'executeSql',
            query: sqlQuery.value,
            database: selectedDatabase.value
        });

        if (response.data.success) {
            sqlResult.value = response.data.payload;

            // History neu laden nach erfolgreicher Query
            if (queryHistoryRef.value) {
                queryHistoryRef.value.loadHistory();
            }
        } else {
            sqlResult.value = {
                success: false,
                error: response.data.text || 'Unbekannter Fehler'
            };
        }
    } catch (error) {
        console.error('Fehler beim Ausführen der SQL-Abfrage:', error);
        sqlResult.value = {
            success: false,
            error: error.response?.data?.text || error.message
        };
    } finally {
        sqlLoading.value = false;
    }
}

/**
 * Lädt ein einzelnes Query-Ergebnis als CSV herunter
 */
function downloadResultsCSV(result) {
    if (!result.rows || result.rows.length === 0) return;

    const rows = result.rows;
    const columns = result.columns;

    // CSV Header
    let csv = columns.join(',') + '\n';

    // CSV Rows
    rows.forEach(row => {
        const values = columns.map(col => {
            let value = row[col];

            // Formatierung für CSV
            if (value === null) return '';
            if (value === true) return 'true';
            if (value === false) return 'false';
            if (typeof value === 'object') value = JSON.stringify(value);

            // Escaping für CSV
            value = String(value);
            if (value.includes(',') || value.includes('"') || value.includes('\n')) {
                value = '"' + value.replace(/"/g, '""') + '"';
            }

            return value;
        });
        csv += values.join(',') + '\n';
    });

    // Download
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    const now = new Date();
    const timestamp = now.toISOString().replace(/[:.]/g, '-').substring(0, 19);
    const filename = `sql_results_${timestamp}.csv`;

    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * Lädt alle Query-Ergebnisse als separate CSV-Dateien herunter
 */
function downloadAllResultsCSV(results) {
    if (!results || results.length === 0) return;

    const now = new Date();
    const timestamp = now.toISOString().replace(/[:.]/g, '-').substring(0, 19);

    results.forEach((result, index) => {
        if (result.rows && result.rows.length > 0) {
            const rows = result.rows;
            const columns = result.columns;

            // CSV Header
            let csv = columns.join(',') + '\n';

            // CSV Rows
            rows.forEach(row => {
                const values = columns.map(col => {
                    let value = row[col];

                    // Formatierung für CSV
                    if (value === null) return '';
                    if (value === true) return 'true';
                    if (value === false) return 'false';
                    if (typeof value === 'object') value = JSON.stringify(value);

                    // Escaping für CSV
                    value = String(value);
                    if (value.includes(',') || value.includes('"') || value.includes('\n')) {
                        value = '"' + value.replace(/"/g, '""') + '"';
                    }

                    return value;
                });
                csv += values.join(',') + '\n';
            });

            // Download
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);

            const filename = `sql_results_${timestamp}_query${index + 1}.csv`;

            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Kleine Verzögerung zwischen Downloads
            if (index < results.length - 1) {
                setTimeout(() => {}, 100);
            }
        }
    });
}

/**
 * Lädt eine Query aus der History in das Textarea
 */
function loadQueryFromHistory(query) {
    sqlQuery.value = query;
    sqlResult.value = null;
    editMode.value = false;
    cancelEdit();

    // Fokus auf Textarea setzen
    nextTick(() => {
        if (sqlTextarea.value) {
            sqlTextarea.value.focus();
        }
    });
}

// Watcher: Tabellen neu laden wenn Datenbank gewechselt wird
watch(selectedDatabase, () => {
    // localStorage aktualisieren
    localStorage.setItem(DEVTOOLS_SQL_DB_KEY, selectedDatabase.value);

    // Query und Ergebnis leeren
    sqlQuery.value = '';
    sqlResult.value = null;
    editMode.value = false;
    cancelEdit();

    // Cache leeren
    columnCache.value = [];
    columnCacheTable.value = null;

    // Spaltenanzeige schließen
    closeColumnDisplay();

    // Tabellen neu laden
    loadTableNames();

    // Autocomplete schließen
    showAutocomplete.value = false;
});

// Watcher: SQL-Query in localStorage persistieren (fuer HMR)
watch(sqlQuery, (val) => {
    localStorage.setItem(DEVTOOLS_SQL_QUERY_KEY, val);
});

/**
 * Fügt einen Tabellennamen aus dem Dialog an der Cursor-Position ein
 */
function insertTableName(tableName) {
    if (!sqlTextarea.value) {
        sqlQuery.value += tableName;
        return;
    }

    const textarea = sqlTextarea.value;
    const cursorPos = textarea.selectionStart;
    const beforeCursor = sqlQuery.value.substring(0, cursorPos);
    const afterCursor = sqlQuery.value.substring(cursorPos);

    // Prüfe ob wir ein Leerzeichen vor dem Tabellennamen brauchen
    const needsSpace = beforeCursor.length > 0 &&
                       !beforeCursor.endsWith(' ') &&
                       !beforeCursor.endsWith('\n');

    const prefix = needsSpace ? ' ' : '';
    sqlQuery.value = beforeCursor + prefix + tableName + afterCursor;

    // Setze Cursor nach eingefügtem Tabellennamen
    nextTick(() => {
        const newPos = cursorPos + prefix.length + tableName.length;
        textarea.setSelectionRange(newPos, newPos);
        textarea.focus();
    });
}


onMounted(() => {
    loadDatabaseNames();
    loadTableNames();
});
</script>

<style scoped>
.sql-editor-wrapper {
    position: relative;
}

.sql-highlight {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    margin: 0;
    padding: 12px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 14px;
    line-height: 22px;
    letter-spacing: normal;
    word-spacing: normal;
    border: 1px solid transparent;
    border-radius: 4px;
    box-sizing: border-box;
    pointer-events: none;
    overflow: hidden;
    white-space: pre-wrap;
    word-wrap: break-word;
    color: #333;
}

.sql-highlight :deep(.sql-kw) {
    color: #1976d2;
}

.sql-highlight :deep(.sql-str) {
    color: #c62828;
}

.sql-highlight :deep(.sql-comment) {
    color: #9e9e9e;
    font-style: italic;
}

.sql-textarea {
    position: relative;
    width: 100%;
    padding: 12px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 14px;
    line-height: 22px;
    letter-spacing: normal;
    word-spacing: normal;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-sizing: border-box;
    resize: vertical;
    background: transparent;
    color: transparent;
    caret-color: #333;
}

.sql-textarea:focus {
    outline: none;
    border-color: #1976d2;
    box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.1);
}

.autocomplete-dropdown {
    position: absolute;
    background: white;
    border: 1px solid #1976d2;
    border-radius: 4px;
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
    max-height: 340px;
    overflow-y: auto;
    z-index: 2000;
    min-width: 250px;
    max-width: 400px;
}

.autocomplete-item {
    padding: 10px 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    font-size: 13px;
    border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.1s;
}

.autocomplete-item:last-child {
    border-bottom: none;
}

.autocomplete-item:hover,
.autocomplete-item.active {
    background-color: #1976d2;
    color: white;
}

.autocomplete-item:hover .suggestion-desc,
.autocomplete-item.active .suggestion-desc {
    opacity: 1;
}

.suggestion-icon {
    margin-right: 10px;
    flex-shrink: 0;
}

.autocomplete-item.active .suggestion-icon {
    color: white !important;
}

.suggestion-text {
    font-family: 'Courier New', Courier, monospace;
    font-weight: 600;
    flex-grow: 1;
}

.suggestion-desc {
    font-size: 11px;
    opacity: 0.6;
    margin-left: auto;
    padding-left: 12px;
    font-style: italic;
}

.text-mono {
    font-family: 'Courier New', Courier, monospace;
    font-size: 12px;
    white-space: pre;
}

.sql-result-table {
    font-size: 12px;
}

.sql-result-table :deep(th) {
    font-weight: 600 !important;
    background-color: #e3f2fd !important;
    white-space: nowrap;
    border-bottom: 2px solid #1976d2 !important;
}

.sql-result-table :deep(td) {
    white-space: nowrap;
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    border-bottom: 1px solid #e0e0e0 !important;
}

.sql-result-table :deep(.v-data-table__th) {
    background-color: #e3f2fd !important;
}

.sql-result-table :deep(tbody tr:nth-child(odd)) {
    background-color: #fafafa;
}

.sql-result-table :deep(tbody tr:nth-child(even)) {
    background-color: #ffffff;
}

.sql-result-table :deep(tbody tr:hover) {
    background-color: #f5f5f5 !important;
}

/* Spaltenanzeige */
.column-display-card {
    border: 2px solid #e3f2fd !important;
}

.columns-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.column-chip {
    cursor: pointer;
    font-family: 'Courier New', Courier, monospace;
    font-size: 12px;
    transition: all 0.2s ease;
}

.column-chip:hover {
    background-color: #1976d2 !important;
    color: white !important;
    transform: translateY(-2px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.column-chip:hover .text-grey {
    color: rgba(255, 255, 255, 0.8) !important;
}

.column-chip:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

/* Editierbare Tabelle */
.editable-table :deep(tbody tr:hover) {
    background-color: #fff8e1 !important;
}

.pk-cell {
    color: #f57c00;
    font-weight: 600;
}

.cell-input {
    width: 100%;
    min-width: 80px;
    padding: 4px 8px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 12px;
    border: 1px solid #1976d2;
    border-radius: 3px;
    background-color: #fff;
}

.cell-input:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.3);
}

.cell-input.cell-changed {
    background-color: #fff3e0;
    border-color: #ff9800;
}

.cell-value {
    cursor: text;
    padding: 4px 0;
    display: inline-block;
}

.cell-value:hover {
    background-color: rgba(25, 118, 210, 0.1);
    border-radius: 3px;
}
</style>