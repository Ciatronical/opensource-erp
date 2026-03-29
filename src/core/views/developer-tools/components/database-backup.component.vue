<!-- src/core/views/system/components/database-backup.component.vue -->
<template>
    <v-row>
        <!-- Datenbank-Auswahl -->
        <v-col cols="12">
            <v-select
                v-model="selectedDatabase"
                :items="databaseOptions"
                item-title="label"
                item-value="value"
                :label="$t('DeveloperTools.sql.database_label')"
                density="compact"
                variant="outlined"
                class="mb-4"
                style="max-width: 400px;"
            />
        </v-col>

        <!-- Backup erstellen -->
        <v-col cols="12" md="6">
            <v-card variant="outlined">
                <v-card-title class="bg-grey-lighten-4">
                    <v-icon start color="success">mdi-database-export</v-icon>
                    {{ $t('DeveloperTools.backup.title') }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <p class="mb-4">
                        {{ $t('DeveloperTools.backup.description') }}
                    </p>

                    <v-text-field
                        v-model="backupName"
                        :label="$t('DeveloperTools.backup.name_label')"
                        :hint="$t('DeveloperTools.backup.name_hint')"
                        variant="outlined"
                        density="comfortable"
                        clearable
                        class="mb-4"
                    />

                    <v-btn
                        color="success"
                        prepend-icon="mdi-content-save"
                        :loading="backupLoading"
                        @click="createBackup"
                    >
                        {{ $t('DeveloperTools.backup.create_button') }}
                    </v-btn>
                </v-card-text>
            </v-card>
        </v-col>

        <!-- Backup wiederherstellen -->
        <v-col cols="12" md="6">
            <v-card variant="outlined">
                <v-card-title class="bg-grey-lighten-4">
                    <v-icon start color="warning">mdi-database-import</v-icon>
                    {{ $t('DeveloperTools.restore.title') }}
                </v-card-title>
                <v-card-text class="pa-4">
                    <p class="mb-4">
                        {{ $t('DeveloperTools.restore.description') }}
                    </p>

                    <v-select
                        v-model="selectedBackup"
                        :items="backups"
                        item-title="display_name"
                        item-value="filename"
                        :label="$t('DeveloperTools.restore.select_label')"
                        variant="outlined"
                        density="comfortable"
                        class="mb-4"
                    >
                        <template #item="{ props, item }">
                            <v-list-item v-bind="props">
                                <template #subtitle>
                                    {{ item.raw.date }} - {{ item.raw.size }}
                                </template>
                            </v-list-item>
                        </template>
                    </v-select>

                    <v-btn
                        color="warning"
                        prepend-icon="mdi-restore"
                        :loading="restoreLoading"
                        :disabled="!selectedBackup"
                        @click="confirmRestore"
                    >
                        {{ $t('DeveloperTools.restore.restore_button') }}
                    </v-btn>
                </v-card-text>
            </v-card>
        </v-col>

        <!-- Verfügbare Backups -->
        <v-col cols="12">
            <v-expansion-panels variant="accordion">
                <v-expansion-panel>
                    <v-expansion-panel-title>
                        <template #default="{ expanded }">
                            <v-icon start color="primary">mdi-folder-open</v-icon>
                            {{ $t('DeveloperTools.list.title') }}
                            <v-chip class="ml-2" size="small">{{ backups.length }}</v-chip>
                            <v-spacer />
                            <v-icon :class="{ 'rotate-icon': expanded }">mdi-chevron-down</v-icon>
                        </template>
                        <template #actions>
                            <v-tooltip v-if="backups.length > 0" location="bottom">
                                <template #activator="{ props }">
                                    <v-btn
                                        v-bind="props"
                                        icon
                                        size="small"
                                        variant="text"
                                        @click.stop="confirmDeleteAllBackups"
                                    >
                                        <v-icon>mdi-delete-sweep</v-icon>
                                    </v-btn>
                                </template>
                                <span>{{ $t('delete_all') }}</span>
                            </v-tooltip>
                        </template>
                    </v-expansion-panel-title>

                    <v-expansion-panel-text>
                        <v-list v-if="backups.length > 0" density="compact">
                            <v-list-item
                                v-for="backup in backups"
                                :key="backup.filename"
                                class="backup-item"
                            >
                                <template #prepend>
                                    <v-icon color="primary">mdi-database</v-icon>
                                </template>

                                <v-list-item-title>
                                    {{ backup.display_name }}
                                </v-list-item-title>

                                <v-list-item-subtitle>
                                    {{ backup.date }} - {{ backup.size }}
                                </v-list-item-subtitle>

                                <template #append>
                                    <v-btn
                                        icon
                                        size="small"
                                        variant="text"
                                        @click="downloadBackup(backup.filename)"
                                    >
                                        <v-icon>mdi-download</v-icon>
                                    </v-btn>
                                    <v-btn
                                        icon
                                        size="small"
                                        variant="text"
                                        color="error"
                                        @click="confirmDelete(backup)"
                                    >
                                        <v-icon>mdi-delete</v-icon>
                                    </v-btn>
                                </template>
                            </v-list-item>
                        </v-list>

                        <div v-else class="pa-4 text-center text-grey">
                            {{ $t('DeveloperTools.list.empty') }}
                        </div>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>
        </v-col>
    </v-row>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import Swal from 'sweetalert2';

const { t: $t } = useI18n();

const backupName = ref('');
const selectedBackup = ref(null);
const backups = ref([]);
const backupLoading = ref(false);
const restoreLoading = ref(false);
const selectedDatabase = ref('company');
const databaseNames = ref({ company: 'company', auth: 'auth' }); // Speichert echte DB-Namen
const databaseOptions = ref([
    { label: 'Firmendatenbank', value: 'company' },
    { label: 'Authentifizierungsdatenbank', value: 'auth' }
]);

/**
 * Generiert einen neuen Backup-Namen mit aktuellem Timestamp
 */
function generateBackupName() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');

    // Verwende den tatsächlichen DB-Namen, nicht das Label
    const dbName = databaseNames.value[selectedDatabase.value] || selectedDatabase.value;

    return `${dbName}_backup_${year}-${month}-${day}_${hours}-${minutes}-${seconds}`;
}

/**
 * Lädt die Datenbanknamen und aktualisiert die Dropdown-Labels
 */
async function loadDatabaseNames() {
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getDatabaseNames'
        });

        if (response.data.success && response.data.payload) {
            const dbNames = response.data.payload;

            // Speichere echte DB-Namen
            databaseNames.value = {
                company: dbNames.company,
                auth: dbNames.auth
            };

            databaseOptions.value = [
                { label: `Firmendatenbank - ${dbNames.company}`, value: 'company' },
                { label: `Authentifizierungsdatenbank - ${dbNames.auth}`, value: 'auth' }
            ];

            // JETZT erst Backup-Name setzen (nach dem Laden der DB-Namen)
            backupName.value = generateBackupName();
        }
    } catch (error) {
        console.error('Fehler beim Laden der Datenbanknamen:', error);
    }
}

/**
 * Lädt die Liste der verfügbaren Backups
 */
async function loadBackupList() {
    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'getBackupList',
            database: selectedDatabase.value
        });

        if (response.data.success && response.data.payload) {
            backups.value = response.data.payload.backups || [];
        }
    } catch (error) {
        console.error('Fehler beim Laden der Backup-Liste:', error);
        backups.value = [];
    }
}

/**
 * Erstellt ein neues Backup
 */
async function createBackup() {
    backupLoading.value = true;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'createDatabaseBackup',
            name: backupName.value || generateBackupName(),
            database: selectedDatabase.value
        });

        if (response.data.success) {
            await Swal.fire({
                title: $t('success'),
                text: response.data.text,
                icon: 'success',
                timer: 3000,
                showConfirmButton: false
            });

            // Backup-Name leeren und neu setzen
            backupName.value = '';
            await new Promise(resolve => setTimeout(resolve, 100)); // Kurz warten für neue Sekunde
            backupName.value = generateBackupName();

            loadBackupList();
        } else {
            Swal.fire($t('error'), response.data.text || $t('backup_create_error'), 'error');
        }
    } catch (error) {
        console.error('Fehler beim Erstellen des Backups:', error);
        Swal.fire($t('error'), $t('network_error_backup'), 'error');
    } finally {
        backupLoading.value = false;
    }
}

/**
 * Bestätigt und stellt ein Backup wieder her
 */
async function confirmRestore() {
    const backup = backups.value.find(b => b.filename === selectedBackup.value);
    if (!backup) return;

    const result = await Swal.fire({
        title: $t('DeveloperTools.restore.confirm_title'),
        text: $t('DeveloperTools.restore.confirm_text', { backup: backup.display_name }),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: $t('confirm'),
        cancelButtonText: $t('cancel'),
        confirmButtonColor: '#ff9800'
    });

    if (!result.isConfirmed) return;

    restoreLoading.value = true;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'restoreDatabaseBackup',
            filename: selectedBackup.value,
            database: selectedDatabase.value
        });

        if (response.data.success) {
            await Swal.fire({
                title: $t('success'),
                text: response.data.text + '\n\n' + $t('page_reload'),
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });

            // Nach Restore: Seite neu laden
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            Swal.fire($t('error'), response.data.text || $t('backup_restore_error'), 'error');
        }
    } catch (error) {
        console.error('Fehler beim Wiederherstellen:', error);
        Swal.fire($t('error'), $t('network_error_restore'), 'error');
    } finally {
        restoreLoading.value = false;
    }
}

/**
 * Bestätigt und löscht ein Backup
 */
async function confirmDelete(backup) {
    const result = await Swal.fire({
        title: $t('DeveloperTools.delete.confirm_title'),
        text: $t('DeveloperTools.delete.confirm_text', { backup: backup.display_name }),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: $t('delete'),
        cancelButtonText: $t('cancel'),
        confirmButtonColor: '#f44336'
    });

    if (!result.isConfirmed) return;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'deleteDatabaseBackup',
            filename: backup.filename,
            database: selectedDatabase.value
        });

        if (response.data.success) {
            await Swal.fire({
                title: $t('deleted'),
                text: response.data.text,
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
            loadBackupList();
        } else {
            Swal.fire($t('error'), response.data.text || $t('backup_delete_error'), 'error');
        }
    } catch (error) {
        console.error('Fehler beim Löschen:', error);
        Swal.fire($t('error'), $t('network_error_delete'), 'error');
    }
}

/**
 * Lädt ein Backup herunter
 */
function downloadBackup(filename) {
    window.location.href = `/api/developer-tools/?action=downloadDatabaseBackup&filename=${encodeURIComponent(filename)}&database=${selectedDatabase.value}`;
}

/**
 * Bestätigt und löscht alle Backups
 */
async function confirmDeleteAllBackups() {
    const result = await Swal.fire({
        title: $t('DeveloperTools.delete_all.confirm_title'),
        text: $t('DeveloperTools.delete_all.confirm_text', { count: backups.value.length }),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: $t('delete_all'),
        cancelButtonText: $t('cancel'),
        confirmButtonColor: '#f44336'
    });

    if (!result.isConfirmed) return;

    try {
        const response = await axios.post('/api/developer-tools/', {
            action: 'deleteAllBackups',
            database: selectedDatabase.value
        });

        if (response.data.success) {
            await Swal.fire({
                title: $t('deleted'),
                text: $t('DeveloperTools.delete_all.success_text', { count: response.data.payload.deleted_count }),
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
            loadBackupList();
        } else {
            Swal.fire($t('error'), response.data.text || $t('DeveloperTools.delete_all.error'), 'error');
        }
    } catch (error) {
        console.error('Fehler beim Löschen aller Backups:', error);
        Swal.fire($t('error'), $t('network_error_delete'), 'error');
    }
}

// Watcher: Backups neu laden wenn Datenbank gewechselt wird
watch(selectedDatabase, () => {
    selectedBackup.value = null;
    backupName.value = generateBackupName(); // Backup-Name aktualisieren
    loadBackupList();
});

// Setze Default-Backup-Name beim Mount
onMounted(() => {
    // Backup-Name wird NACH loadDatabaseNames() gesetzt
    loadDatabaseNames();
    loadBackupList();
});
</script>

<style scoped>
.backup-item {
    border-bottom: 1px solid #e0e0e0;
}

.backup-item:last-child {
    border-bottom: none;
}

.rotate-icon {
    transform: rotate(180deg);
    transition: transform 0.3s ease;
}
</style>