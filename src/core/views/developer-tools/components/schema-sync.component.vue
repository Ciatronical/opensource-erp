<!-- src/core/views/system/components/schema-sync.component.vue -->
<template>
    <v-card flat>
        <v-card-title class="text-h6 bg-blue-grey-lighten-5">
            <v-icon start>mdi-database-sync</v-icon>
            {{ $t('DeveloperTools.schemaSync.title') }}
        </v-card-title>

        <v-card-text class="pa-4">
            <v-row>
                <!-- Schema Exportieren -->
                <v-col cols="12" md="6">
                    <v-card variant="outlined">
                        <v-card-text>
                            <div class="text-subtitle-1 font-weight-bold mb-2">
                                <v-icon start size="small">mdi-database-export</v-icon>
                                Schema exportieren
                            </div>
                            <p class="text-body-2 text-grey-darken-1 mb-4">
                                Exportiert das aktuelle Datenbankschema in eine Datei auf dem Server
                            </p>
                            <v-btn
                                color="primary"
                                variant="flat"
                                block
                                :loading="exportLoading"
                                @click="exportSchema"
                            >
                                <v-icon start>mdi-content-save</v-icon>
                                Schema speichern
                            </v-btn>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Schema Herunterladen -->
                <v-col cols="12" md="6">
                    <v-card variant="outlined">
                        <v-card-text>
                            <div class="text-subtitle-1 font-weight-bold mb-2">
                                <v-icon start size="small">mdi-download</v-icon>
                                Schema herunterladen
                            </div>
                            <p class="text-body-2 text-grey-darken-1 mb-4">
                                Lädt die gespeicherte Schema-Datei herunter zum Bearbeiten
                            </p>
                            <v-btn
                                color="secondary"
                                variant="flat"
                                block
                                :loading="downloadLoading"
                                @click="downloadSchema"
                            >
                                <v-icon start>mdi-download</v-icon>
                                Schema herunterladen
                            </v-btn>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Schema Hochladen -->
                <v-col cols="12" md="6">
                    <v-card variant="outlined">
                        <v-card-text>
                            <div class="text-subtitle-1 font-weight-bold mb-2">
                                <v-icon start size="small">mdi-upload</v-icon>
                                Schema hochladen
                            </div>
                            <p class="text-body-2 text-grey-darken-1 mb-4">
                                Lädt eine bearbeitete Schema-Datei hoch (überschreibt die aktuelle)
                            </p>
                            <v-btn
                                color="info"
                                variant="flat"
                                block
                                :loading="uploadLoading"
                                @click="uploadSchemaFile"
                            >
                                <v-icon start>mdi-upload</v-icon>
                                Schema hochladen
                            </v-btn>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Migration Ausführen -->
                <v-col cols="12" md="6">
                    <v-card variant="outlined">
                        <v-card-text>
                            <div class="text-subtitle-1 font-weight-bold mb-2">
                                <v-icon start size="small">mdi-database-sync</v-icon>
                                Migration durchführen
                            </div>
                            <p class="text-body-2 text-grey-darken-1 mb-4">
                                Vergleicht Schema-Datei mit DB und generiert ALTER TABLE Statements
                            </p>
                            <v-btn
                                color="success"
                                variant="flat"
                                block
                                :loading="migrationLoading"
                                @click="startMigration"
                            >
                                <v-icon start>mdi-file-compare</v-icon>
                                Migration starten
                            </v-btn>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Status Messages -->
            <v-row v-if="statusMessage" class="mt-4">
                <v-col cols="12">
                    <v-alert
                        :type="statusType"
                        variant="tonal"
                        closable
                        @click:close="statusMessage = ''"
                    >
                        {{ statusMessage }}
                    </v-alert>
                </v-col>
            </v-row>

            <!-- File Input (hidden) für Upload -->
            <input
                ref="fileInput"
                type="file"
                accept=".sql"
                style="display: none"
                @change="handleFileUpload"
            />
        </v-card-text>

        <!-- Migration Preview Dialog -->
        <v-dialog
            v-model="showMigrationPreview"
            max-width="900"
            scrollable
        >
            <v-card>
                <v-card-title class="bg-primary text-white">
                    <v-icon start>mdi-file-compare</v-icon>
                    Migration Preview
                </v-card-title>

                <v-card-text class="pa-0">
                    <!-- Statistik -->
                    <v-container>
                        <v-row>
                            <v-col cols="4">
                                <v-card variant="tonal" color="success">
                                    <v-card-text class="text-center">
                                        <div class="text-h4">{{ migrationPreview?.stats?.new_tables || 0 }}</div>
                                        <div class="text-caption">Neue Tabellen</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="4">
                                <v-card variant="tonal" color="info">
                                    <v-card-text class="text-center">
                                        <div class="text-h4">{{ migrationPreview?.stats?.altered_tables || 0 }}</div>
                                        <div class="text-caption">Geänderte Tabellen</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="4">
                                <v-card variant="tonal" color="warning">
                                    <v-card-text class="text-center">
                                        <div class="text-h4">{{ migrationPreview?.stats?.new_columns || 0 }}</div>
                                        <div class="text-caption">Neue Spalten</div>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-container>

                    <v-divider></v-divider>

                    <!-- CREATE TABLE Statements -->
                    <v-expansion-panels v-if="migrationPreview?.migrations?.create_tables?.length" class="ma-4">
                        <v-expansion-panel>
                            <v-expansion-panel-title>
                                <v-icon start color="success">mdi-table-plus</v-icon>
                                Neue Tabellen ({{ migrationPreview.migrations.create_tables.length }})
                            </v-expansion-panel-title>
                            <v-expansion-panel-text>
                                <v-card
                                    v-for="(item, idx) in migrationPreview.migrations.create_tables"
                                    :key="idx"
                                    variant="outlined"
                                    class="mb-2"
                                >
                                    <v-card-title class="text-subtitle-2">
                                        {{ item.table }}
                                    </v-card-title>
                                    <v-card-text>
                                        <pre class="text-caption bg-grey-lighten-4 pa-2">{{ item.statement }}</pre>
                                    </v-card-text>
                                </v-card>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>

                    <!-- ALTER TABLE Statements -->
                    <v-expansion-panels v-if="Object.keys(migrationPreview?.migrations?.alter_tables || {}).length" class="ma-4">
                        <v-expansion-panel>
                            <v-expansion-panel-title>
                                <v-icon start color="info">mdi-table-edit</v-icon>
                                Spalten-Änderungen ({{ Object.keys(migrationPreview.migrations.alter_tables).length }} Tabellen)
                            </v-expansion-panel-title>
                            <v-expansion-panel-text>
                                <div
                                    v-for="(alters, tableName) in migrationPreview.migrations.alter_tables"
                                    :key="tableName"
                                    class="mb-4"
                                >
                                    <v-card variant="outlined">
                                        <v-card-title class="text-subtitle-2 bg-blue-grey-lighten-5">
                                            {{ tableName }}
                                        </v-card-title>
                                        <v-card-text>
                                            <div
                                                v-for="(alter, idx) in alters"
                                                :key="idx"
                                                class="mb-2"
                                            >
                                                <v-chip size="small" color="info" class="mb-1">
                                                    {{ alter.action }}: {{ alter.column }}
                                                </v-chip>
                                                <pre class="text-caption bg-grey-lighten-4 pa-2">{{ alter.statement }}</pre>
                                            </div>
                                        </v-card-text>
                                    </v-card>
                                </div>
                            </v-expansion-panel-text>
                        </v-expansion-panel>
                    </v-expansion-panels>

                    <!-- Warnings -->
                    <v-alert
                        v-if="migrationPreview?.migrations?.warnings?.length"
                        type="warning"
                        variant="tonal"
                        class="ma-4"
                    >
                        <v-alert-title>
                            <v-icon start>mdi-alert</v-icon>
                            Warnungen
                        </v-alert-title>
                        <div
                            v-for="(warning, idx) in migrationPreview.migrations.warnings"
                            :key="idx"
                            class="text-caption mt-2"
                        >
                            <strong>{{ warning.table }}.{{ warning.column }}:</strong> {{ warning.message }}
                        </div>
                    </v-alert>
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions>
                    <v-btn
                        @click="showMigrationPreview = false"
                    >
                        Abbrechen
                    </v-btn>
                    <v-spacer></v-spacer>
                    <v-btn
                        color="success"
                        variant="flat"
                        :loading="executingMigration"
                        @click="executeMigration"
                    >
                        <v-icon start>mdi-check</v-icon>
                        Migration ausführen
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { oserpStore } from '@/core/stores/oserp.store.js';

const store = oserpStore();

// Datenbankname aus dem Store
// session.client IST bereits der Datenbankname als String (z.B. "Startup" -> "startup")
const dbName = computed(() => {
    const client = store.session?.client;
    if (typeof client === 'string') {
        return client.toLowerCase();
    }
    return client?.dbname || client?.db_name || client?.name || '';
});

const exportLoading = ref(false);
const downloadLoading = ref(false);
const uploadLoading = ref(false);
const migrationLoading = ref(false);
const executingMigration = ref(false);
const statusMessage = ref('');
const statusType = ref('success');
const fileInput = ref(null);
const showMigrationPreview = ref(false);
const migrationPreview = ref(null);

/**
 * Exportiert das Schema und speichert es auf dem Server
 */
async function exportSchema() {
    exportLoading.value = true;
    statusMessage.value = '';

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'schemaToFile'
        });

        console.log('Export response:', response.data);  // DEBUG

        if (response.data.success) {
            // Versuche verschiedene Response-Strukturen
            const filename = response.data.data?.filename ||
                           response.data.filename ||
                           response.data.payload?.filename ||
                           'schema.sql';
            statusMessage.value = `Schema erfolgreich gespeichert: ${filename}`;
            statusType.value = 'success';
        } else {
            throw new Error(response.data.message || 'Export fehlgeschlagen');
        }
    } catch (error) {
        console.error('Schema export error:', error);
        console.error('Error response:', error.response?.data);  // DEBUG
        statusMessage.value = 'Fehler beim Exportieren: ' + (error.response?.data?.message || error.message);
        statusType.value = 'error';
    } finally {
        exportLoading.value = false;
    }
}

/**
 * Lädt die gespeicherte Schema-Datei herunter
 * Einfacher Download via window.location - wie im Backup-Tool!
 */
function downloadSchema() {
    window.location.href = '/api/developer-tools/?action=downloadSchema';
}

/**
 * Öffnet den Datei-Upload-Dialog
 */
function uploadSchemaFile() {
    fileInput.value.click();
}

/**
 * Verarbeitet die hochgeladene Datei
 */
async function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // DEBUG
    console.log('=== Upload Debug ===');
    console.log('store.session.client:', store.session?.client);
    console.log('dbName.value:', dbName.value);

    uploadLoading.value = true;
    statusMessage.value = '';

    try {
        const formData = new FormData();
        formData.append('action', 'uploadSchema');
        formData.append('schema_file', file);
        formData.append('dbName', dbName.value);

        // DEBUG: Zeige FormData-Inhalt
        console.log('FormData dbName:', formData.get('dbName'));

        const response = await axios.post('/api/developer-tools/', formData, {
            headers: {
                'Content-Type': 'multipart/form-data'
            }
        });

        if (response.data.success) {
            const filename = response.data.data?.filename ||
                           response.data.filename ||
                           response.data.payload?.filename ||
                           'schema.sql';
            statusMessage.value = `Schema-Datei erfolgreich hochgeladen: ${filename}`;
            statusType.value = 'success';
        } else {
            throw new Error(response.data.message || 'Upload fehlgeschlagen');
        }
    } catch (error) {
        console.error('Schema upload error:', error);
        statusMessage.value = 'Fehler beim Hochladen: ' + (error.response?.data?.message || error.message);
        statusType.value = 'error';
    } finally {
        uploadLoading.value = false;
        event.target.value = '';
    }
}

/**
 * Startet die Migration (Vergleich und Preview)
 */
async function startMigration() {
    migrationLoading.value = true;
    statusMessage.value = '';

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'fileToSchemaWithMigration',
            dbName: dbName.value
        });

        if (response.data.success) {
            migrationPreview.value = response.data.data;
            showMigrationPreview.value = true;
        }
    } catch (error) {
        console.error('Migration preview error:', error);
        statusMessage.value = 'Fehler beim Analysieren: ' + (error.response?.data?.message || error.message);
        statusType.value = 'error';
    } finally {
        migrationLoading.value = false;
    }
}

/**
 * Führt die Migration nach Benutzer-Bestätigung aus
 */
async function executeMigration() {
    // Prüfe ob Preview-Daten vorhanden sind
    if (!migrationPreview.value?.migrations) {
        statusMessage.value = 'Keine Migration-Daten vorhanden. Bitte zuerst "Migration starten" klicken.';
        statusType.value = 'error';
        return;
    }

    executingMigration.value = true;

    try {
        const statements = [];

        if (migrationPreview.value.migrations.create_tables) {
            migrationPreview.value.migrations.create_tables.forEach(item => {
                statements.push(item.statement);
            });
        }

        if (migrationPreview.value.migrations.alter_tables) {
            Object.values(migrationPreview.value.migrations.alter_tables).forEach(alters => {
                alters.forEach(alter => {
                    statements.push(alter.statement);
                });
            });
        }

        if (statements.length === 0) {
            statusMessage.value = 'Keine Änderungen zum Ausführen gefunden.';
            statusType.value = 'info';
            showMigrationPreview.value = false;
            return;
        }

        const response = await axios.post('/api/developer-tools/', {
            action: 'executeMigration',
            statements: statements
        });

        if (response.data.success) {
            statusMessage.value = `Migration erfolgreich! ${response.data.data.executed} Statements ausgeführt.`;
            statusType.value = 'success';
            showMigrationPreview.value = false;
            migrationPreview.value = null;
        }
    } catch (error) {
        console.error('Migration execution error:', error);
        statusMessage.value = 'Fehler beim Ausführen: ' + (error.response?.data?.message || error.message);
        statusType.value = 'error';
    } finally {
        executingMigration.value = false;
    }
}
</script>