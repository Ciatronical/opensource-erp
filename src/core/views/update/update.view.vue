<!-- src/core/views/update/update.view.vue -->
<template>
    <v-container fluid class="pa-0">
    <NavbarView />
    <v-container fluid class="bg-grey-lighten-4 pa-6">
        <v-row justify="center">
            <v-col cols="12" lg="10" xl="8">
                <v-card elevation="8" class="mx-auto">
                    <!-- Header -->
                    <v-card-title class="text-h4 bg-primary text-white py-6">
                        <v-icon icon="mdi-update" size="large" class="mr-3"></v-icon>
                        {{ $t('update.title') }}
                    </v-card-title>

                    <v-divider></v-divider>

                    <v-card-text class="pa-6">
                        <!-- Beschreibung -->
                        <p v-if="!updateResult" class="text-body-1 mb-4">
                            {{ $t('update.description') }}
                        </p>

                        <!-- Erfolgs-Meldung -->
                        <v-alert
                            v-if="successMessage"
                            type="success"
                            variant="tonal"
                            class="mb-4"
                        >
                            {{ successMessage }}
                        </v-alert>

                        <!-- Fehler-Meldung -->
                        <v-alert
                            v-if="errorMessage"
                            type="error"
                            variant="tonal"
                            closable
                            @click:close="errorMessage = ''"
                            class="mb-4"
                        >
                            {{ errorMessage }}
                        </v-alert>

                        <!-- Ergebnisse -->
                        <div v-if="updateResult" class="mb-4">
                            <!-- Git Pull Status -->
                            <v-alert
                                v-if="updateResult.git"
                                :type="updateResult.git.success ? 'success' : 'error'"
                                variant="tonal"
                                density="compact"
                                class="mb-3"
                            >
                                <v-icon size="small" class="mr-1">
                                    {{ updateResult.git.success ? 'mdi-git' : 'mdi-alert-circle' }}
                                </v-icon>
                                {{ updateResult.git.success ? $t('update.gitPullSuccess') : $t('update.gitPullFailed') }}
                                <span v-if="updateResult.git.output" class="text-caption ms-2 font-monospace">
                                    {{ updateResult.git.output }}
                                </span>
                            </v-alert>

                            <v-expansion-panels multiple v-model="expandedPanels">
                                <!-- Auth-Datenbank -->
                                <v-expansion-panel>
                                    <v-expansion-panel-title>
                                        <v-icon
                                            :color="getStatusColor(updateResult.auth)"
                                            size="small"
                                            class="mr-2"
                                        >
                                            {{ getStatusIcon(updateResult.auth) }}
                                        </v-icon>
                                        <strong>{{ $t('update.authDatabase') }}</strong>
                                        <span v-if="updateResult.auth_backup" class="text-caption ms-3">
                                            <v-chip
                                                size="x-small"
                                                :color="updateResult.auth_backup.success ? 'success' : 'error'"
                                                variant="tonal"
                                            >
                                                {{ updateResult.auth_backup.success
                                                    ? $t('update.backupSuccess', { filename: updateResult.auth_backup.filename })
                                                    : $t('update.backupFailed')
                                                }}
                                            </v-chip>
                                        </span>
                                        <span v-else-if="updateResult.dry_run" class="text-caption ms-3">
                                            <v-chip size="x-small" color="grey" variant="tonal">
                                                {{ $t('update.backupSkipped') }}
                                            </v-chip>
                                        </span>
                                    </v-expansion-panel-title>
                                    <v-expansion-panel-text>
                                        <SchemaMessages
                                            v-if="updateResult.auth"
                                            :result="updateResult.auth"
                                        />
                                    </v-expansion-panel-text>
                                </v-expansion-panel>

                                <!-- Pro Mandant -->
                                <v-expansion-panel
                                    v-for="client in updateResult.clients"
                                    :key="client.id"
                                >
                                    <v-expansion-panel-title>
                                        <v-icon
                                            :color="getStatusColor(client.update)"
                                            size="small"
                                            class="mr-2"
                                        >
                                            {{ getStatusIcon(client.update) }}
                                        </v-icon>
                                        <strong>{{ $t('update.clientDatabase', { name: client.name }) }}</strong>
                                        <span class="text-caption text-grey ms-2">
                                            ({{ client.dbname }})
                                        </span>
                                        <span v-if="client.backup" class="text-caption ms-3">
                                            <v-chip
                                                size="x-small"
                                                :color="client.backup.success ? 'success' : 'error'"
                                                variant="tonal"
                                            >
                                                {{ client.backup.success
                                                    ? $t('update.backupSuccess', { filename: client.backup.filename })
                                                    : $t('update.backupFailed')
                                                }}
                                            </v-chip>
                                        </span>
                                        <span v-else-if="updateResult.dry_run" class="text-caption ms-3">
                                            <v-chip size="x-small" color="grey" variant="tonal">
                                                {{ $t('update.backupSkipped') }}
                                            </v-chip>
                                        </span>
                                        <span v-if="client.features && client.features.length > 1" class="text-caption ms-2">
                                            <v-chip
                                                v-for="feature in client.features.filter(f => f !== 'crm')"
                                                :key="feature"
                                                size="x-small"
                                                color="info"
                                                variant="tonal"
                                                class="ms-1"
                                            >
                                                {{ feature }}
                                            </v-chip>
                                        </span>
                                    </v-expansion-panel-title>
                                    <v-expansion-panel-text>
                                        <SchemaMessages
                                            v-if="client.update"
                                            :result="client.update"
                                        />
                                    </v-expansion-panel-text>
                                </v-expansion-panel>
                            </v-expansion-panels>

                            <!-- Keine Mandanten -->
                            <v-alert
                                v-if="updateResult.clients && updateResult.clients.length === 0"
                                type="warning"
                                variant="tonal"
                                class="mt-3"
                            >
                                {{ $t('update.noClients') }}
                            </v-alert>
                        </div>

                        <!-- Buttons -->
                        <div v-if="!updateCompleted" class="d-flex gap-2">
                            <v-btn
                                color="info"
                                variant="outlined"
                                :loading="dryRunLoading"
                                :disabled="updateLoading"
                                @click="runDryRun"
                                prepend-icon="mdi-eye"
                            >
                                {{ dryRunLoading ? $t('update.buttons.checking') : $t('update.buttons.dryRun') }}
                            </v-btn>

                            <v-spacer></v-spacer>

                            <v-btn
                                color="warning"
                                :loading="updateLoading"
                                :disabled="dryRunLoading"
                                @click="runUpdate"
                                prepend-icon="mdi-database-sync"
                            >
                                {{ updateLoading ? $t('update.buttons.updating') : $t('update.buttons.update') }}
                            </v-btn>
                        </div>

                        <!-- Fertig-Button nach erfolgreichem Update -->
                        <div v-if="updateCompleted" class="d-flex justify-end">
                            <v-btn
                                color="primary"
                                prepend-icon="mdi-check-all"
                                @click="$router.push('/')"
                            >
                                {{ $t('update.buttons.finish') }}
                            </v-btn>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
    </v-container>
</template>

<script>
    import NavbarView from '@/core/components/navbar/navbar.view.vue'

    /**
     * Inline-Komponente fuer Schema-Meldungen eines einzelnen DB-Updates
     */
    const SchemaMessages = {
        name: 'SchemaMessages',
        props: {
            result: { type: Object, required: true }
        },
        template: `
            <div>
                <!-- Meldungen -->
                <v-list v-if="result.messages && result.messages.length > 0" density="compact">
                    <v-list-item
                        v-for="(msg, index) in result.messages"
                        :key="index"
                        :class="{ 'change-highlight': isChangeMessage(msg) }"
                    >
                        <template v-slot:prepend>
                            <v-icon
                                v-if="isChangeMessage(msg)"
                                color="warning"
                                size="small"
                                class="mr-2"
                            >mdi-alert-circle</v-icon>
                            <v-icon
                                v-else
                                color="success"
                                size="small"
                                class="mr-2"
                            >mdi-check-circle</v-icon>
                        </template>
                        <v-list-item-title :class="{ 'font-weight-bold text-warning': isChangeMessage(msg) }">
                            {{ translateMessage(msg) }}
                        </v-list-item-title>
                    </v-list-item>
                </v-list>

                <!-- SQL Statements (nur bei Dry-Run) -->
                <div v-if="result.sql_statements && result.sql_statements.length > 0" class="mt-2">
                    <div class="text-subtitle-2 mb-1">
                        <v-icon size="small" class="mr-1" color="primary">mdi-database-edit</v-icon>
                        SQL-Statements ({{ result.sql_statements.length }})
                    </div>
                    <v-list density="compact">
                        <v-list-item
                            v-for="(stmt, index) in result.sql_statements"
                            :key="index"
                        >
                            <v-list-item-title class="font-weight-bold text-caption">
                                {{ stmt.type }}: {{ stmt.table || stmt.target }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption font-monospace">
                                {{ stmt.sql }}
                            </v-list-item-subtitle>
                        </v-list-item>
                    </v-list>
                </div>

                <!-- Fehler -->
                <v-alert
                    v-for="(err, index) in (result.errors || [])"
                    :key="'err-' + index"
                    type="error"
                    variant="tonal"
                    density="compact"
                    class="mt-2"
                >
                    {{ err }}
                </v-alert>

                <!-- Keine Aenderungen -->
                <v-alert
                    v-if="(!result.messages || result.messages.length === 0) && (!result.errors || result.errors.length === 0)"
                    type="info"
                    variant="tonal"
                    density="compact"
                >
                    Keine Meldungen
                </v-alert>
            </div>
        `,
        methods: {
            isChangeMessage(msg) {
                if (typeof msg === 'object' && msg.type) {
                    const changeTypes = ['columns_added', 'column_added', 'table_created', 'dry_run_mode']
                    return changeTypes.includes(msg.type)
                }
                const changeIndicators = ['würde', 'would', 'added', 'created', 'hinzugefügt', 'erstellt']
                return changeIndicators.some(indicator => String(msg).toLowerCase().includes(indicator.toLowerCase()))
            },

            translateMessage(msg) {
                if (typeof msg !== 'object' || !msg.type) {
                    return String(msg)
                }

                const key = 'update.schemaMessages.' + msg.type
                const params = { ...msg }
                const translation = this.$t(key, params)

                if (translation.includes('|')) {
                    const [dryRunText, updateText] = translation.split('|').map(s => s.trim())
                    return msg.dry_run ? dryRunText : updateText
                }

                return translation
            }
        }
    }

    export default {
        name: 'UpdateView',

        components: {
            NavbarView,
            SchemaMessages
        },

        data() {
            return {
                dryRunLoading: false,
                updateLoading: false,
                updateCompleted: false,
                updateResult: null,
                errorMessage: '',
                successMessage: '',
                expandedPanels: []
            }
        },

        methods: {
            /**
             * Status-Farbe fuer ein Update-Ergebnis
             */
            getStatusColor(result) {
                if (!result) return 'grey'
                return result.success ? 'success' : 'error'
            },

            /**
             * Status-Icon fuer ein Update-Ergebnis
             */
            getStatusIcon(result) {
                if (!result) return 'mdi-help-circle'
                return result.success ? 'mdi-check-circle' : 'mdi-alert-circle'
            },

            /**
             * Fuehrt einen Dry-Run aus (zeigt nur was passieren wuerde)
             */
            async runDryRun() {
                this.dryRunLoading = true
                this.errorMessage = ''
                this.successMessage = ''
                this.updateResult = null

                try {
                    const response = await fetch('/api/update/', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'updateAllDatabases',
                            dry_run: true
                        })
                    })

                    const data = await response.json()

                    if (data.success) {
                        this.updateResult = data.payload
                        this.successMessage = this.$t('update.dryRunSuccess')
                        // Alle Panels aufklappen
                        this.expandedPanels = Array.from(
                            { length: 1 + (data.payload.clients?.length || 0) },
                            (_, i) => i
                        )
                    } else {
                        this.updateResult = data.payload
                        this.errorMessage = data.text || this.$t('update.errorMessages.dryRunFailed')
                    }
                } catch (error) {
                    console.error('Fehler beim Dry-Run:', error)
                    this.errorMessage = this.$t('update.errorMessages.networkError')
                } finally {
                    this.dryRunLoading = false
                }
            },

            /**
             * Fuehrt das tatsaechliche Update aus
             */
            async runUpdate() {
                this.updateLoading = true
                this.errorMessage = ''
                this.successMessage = ''

                try {
                    const response = await fetch('/api/update/', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'updateAllDatabases',
                            dry_run: false
                        })
                    })

                    const data = await response.json()

                    if (data.success) {
                        this.updateResult = data.payload
                        this.successMessage = this.$t('update.updateSuccess')
                        this.updateCompleted = true
                        // Alle Panels aufklappen
                        this.expandedPanels = Array.from(
                            { length: 1 + (data.payload.clients?.length || 0) },
                            (_, i) => i
                        )
                    } else {
                        this.updateResult = data.payload
                        this.errorMessage = data.text || this.$t('update.errorMessages.updateFailed')
                    }
                } catch (error) {
                    console.error('Fehler beim Update:', error)
                    this.errorMessage = this.$t('update.errorMessages.networkError')
                } finally {
                    this.updateLoading = false
                }
            }
        }
    }
</script>

<style scoped>
.gap-2 {
    gap: 8px;
}

.font-monospace {
    font-family: monospace;
    white-space: pre-wrap;
    word-break: break-all;
}

.change-highlight {
    background-color: rgba(251, 140, 0, 0.1);
    border-left: 3px solid rgb(251, 140, 0);
    margin-bottom: 2px;
}
</style>
