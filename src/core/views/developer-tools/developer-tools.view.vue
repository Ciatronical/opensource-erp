<!-- src/core/views/developer-tools/developer-tools.view.vue -->
<template>
    <navbar-view />
    <messages-view />

    <!-- Success/Error Message -->
    <v-alert
        v-if="showSuccessMessage"
        :type="successMessage.includes('Fehler') ? 'error' : 'success'"
        :text="successMessage"
        variant="tonal"
        closable
        class="ma-4"
        @click:close="showSuccessMessage = false"
    />

    <!-- Loading Overlay während Restore -->
    <v-overlay
        v-model="restoreLoading"
        class="align-center justify-center"
        persistent
    >
        <v-card class="pa-6 text-center" min-width="300">
            <v-progress-circular
                indeterminate
                size="64"
                color="primary"
                class="mb-4"
            />
            <div class="text-h6">Backup wird wiederhergestellt...</div>
            <div class="text-caption mt-2">Bitte warten, dies kann einige Minuten dauern.</div>
        </v-card>
    </v-overlay>

    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <v-card>
                    <v-card-title class="bg-primary text-white">
                        <v-icon start>mdi-database</v-icon>
                        {{ t('DeveloperTools.title') }}
                    </v-card-title>

                    <v-card-text class="pa-0">
                        <v-row no-gutters>
                            <!-- Linke Seite: Vertikale Tab-Liste -->
                            <v-col cols="12" md="3" lg="2">
                                <v-list density="compact" class="tabs-list">
                                    <v-list-item
                                        v-for="tabDef in tabItems"
                                        :key="tabDef.value"
                                        :value="tabDef.value"
                                        :active="activeTab === tabDef.value"
                                        @click="setActiveTab(tabDef.value)"
                                    >
                                        <template #prepend>
                                            <v-icon>{{ tabDef.icon }}</v-icon>
                                        </template>
                                        <v-list-item-title class="d-none d-md-block">
                                            {{ t(tabDef.labelKey) }}
                                        </v-list-item-title>
                                    </v-list-item>
                                </v-list>
                            </v-col>

                            <v-divider vertical class="d-none d-md-block" />
                            <v-divider class="d-md-none" />

                            <!-- Rechte Seite: Tab-Inhalt -->
                            <v-col cols="12" md="9" lg="10">
                                <div class="pa-4">
                                    <v-window v-model="activeTab">
                                        <v-window-item value="backup">
                                            <database-backup-component
                                                @restore-started="handleRestoreStarted"
                                                @restore-completed="handleRestoreCompleted"
                                            />
                                        </v-window-item>

                                        <v-window-item value="sql">
                                            <sql-tool-component />
                                        </v-window-item>

                                        <v-window-item value="api-tester">
                                            <api-tester-component />
                                        </v-window-item>

                                        <v-window-item value="store-viewer">
                                            <store-viewer-component />
                                        </v-window-item>

                                        <v-window-item value="schema-update">
                                            <schema-update-component />
                                        </v-window-item>

                                        <v-window-item value="auto-test">
                                            <auto-test-component />
                                        </v-window-item>

                                        <v-window-item value="requirement-specs">
                                            <requirement-specs-component />
                                        </v-window-item>

                                        <v-window-item value="tickets">
                                            <tickets-component />
                                        </v-window-item>
                                    </v-window>
                                </div>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import NavbarView from '@/core/components/navbar/navbar.view.vue';
import MessagesView from '@/core/components/messages/messages.view.vue';
import DatabaseBackupComponent from './components/database-backup.component.vue';
import SqlToolComponent from './components/sql-tool.component.vue';
import ApiTesterComponent from './components/api-tester.component.vue';
import StoreViewerComponent from './components/store-viewer.component.vue';
import SchemaUpdateComponent from './components/schema-update.component.vue';
import AutoTestComponent from './components/auto-test.component.vue';
import RequirementSpecsComponent from './components/requirement-specs.component.vue';
import TicketsComponent from './components/tickets.component.vue';

const { t } = useI18n();

// Tab-Definitionen
const tabItems = [
    { value: 'backup', icon: 'mdi-database-export', labelKey: 'DeveloperTools.tabs.backup' },
    { value: 'sql', icon: 'mdi-code-braces', labelKey: 'DeveloperTools.tabs.sql' },
    { value: 'api-tester', icon: 'mdi-api', labelKey: 'DeveloperTools.tabs.apiTester' },
    { value: 'store-viewer', icon: 'mdi-database-eye', labelKey: 'DeveloperTools.tabs.storeViewer' },
    { value: 'schema-update', icon: 'mdi-update', labelKey: 'DeveloperTools.tabs.schemaUpdate' },
    { value: 'auto-test', icon: 'mdi-test-tube', labelKey: 'DeveloperTools.tabs.autoTest' },
    { value: 'requirement-specs', icon: 'mdi-file-document-check', labelKey: 'DeveloperTools.tabs.requirementSpecs' },
    { value: 'tickets', icon: 'mdi-ticket-outline', labelKey: 'DeveloperTools.tabs.tickets' },
];

// Aktiver Tab mit localStorage-Persistenz
const DEVTOOLS_TAB_KEY = 'devtools_activeTab';
const activeTab = ref(localStorage.getItem(DEVTOOLS_TAB_KEY) || 'backup');

function setActiveTab(value) {
    activeTab.value = value;
}

watch(activeTab, (newVal) => {
    localStorage.setItem(DEVTOOLS_TAB_KEY, newVal);
});

// State
const restoreLoading = ref(false);
const successMessage = ref('');
const showSuccessMessage = ref(false);

/**
 * Handler: Restore wurde gestartet
 */
function handleRestoreStarted() {
    restoreLoading.value = true;
}

/**
 * Handler: Restore wurde abgeschlossen
 */
function handleRestoreCompleted(result) {
    restoreLoading.value = false;

    if (result.success) {
        successMessage.value = `Backup "${result.restoredBackup}" erfolgreich wiederhergestellt. Automatisches Backup "${result.autoBackupName}" wurde erstellt.`;
        showSuccessMessage.value = true;

        // Verstecke Meldung nach 3 Sekunden und reload dann
        setTimeout(() => {
            showSuccessMessage.value = false;
            window.location.reload();
        }, 3000);
    } else {
        successMessage.value = 'Fehler beim Wiederherstellen: ' + result.error;
        showSuccessMessage.value = true;

        // Verstecke Fehlermeldung nach 5 Sekunden
        setTimeout(() => {
            showSuccessMessage.value = false;
        }, 5000);
    }
}
</script>

<style scoped>
.tabs-list {
    min-height: 300px;
}

@media (max-width: 960px) {
    .tabs-list {
        min-height: auto;
        display: flex;
        overflow-x: auto;
    }
}
</style>
