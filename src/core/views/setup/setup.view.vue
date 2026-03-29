<!-- src/core/views/setup/setup.view.vue -->
<template>
    <v-container fluid class="fill-height bg-grey-lighten-4">
        <v-row align="center" justify="center">
            <v-col cols="12" sm="10" md="8" lg="6" xl="4">
                <v-card elevation="8" class="mx-auto">
                    <!-- Header -->
                    <v-card-title class="text-h4 bg-primary text-white py-6">
                        <v-icon icon="mdi-cog-outline" size="large" class="mr-3"></v-icon>
                        {{ $t('setup.title') }}
                    </v-card-title>

                    <v-divider></v-divider>

                    <!-- Setup-Formular -->
                    <v-card-text class="pa-6">
                        <v-alert
                            v-if="!setupRequired && !loading"
                            type="info"
                            variant="tonal"
                            class="mb-4"
                        >
                            {{ $t('setup.setupAlreadyDone') }}
                        </v-alert>

                        <v-alert
                            v-if="configFound"
                            type="success"
                            variant="tonal"
                            class="mb-4"
                        >
                            <v-icon size="small" class="mr-2">mdi-check-circle</v-icon>
                            {{ $t('setup.configFound') }}
                            <div v-if="configSource" class="text-caption mt-1">
                                {{ configSource }}
                            </div>
                        </v-alert>

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

                        <v-alert
                            v-if="successMessage"
                            type="success"
                            variant="tonal"
                            class="mb-4"
                        >
                            {{ successMessage }}
                        </v-alert>

                        <div v-if="setupRequired">
                            <p v-if="!setupCompleted" class="text-body-1 mb-4">
                                {{ $t('setup.welcome') }}
                            </p>

                            <!-- Zusammenfassung nach erfolgreicher Speicherung -->
                            <v-list v-if="setupCompleted" class="mb-4">
                                <v-list-item prepend-icon="mdi-server">
                                    <v-list-item-title>{{ $t('setup.fields.host') }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ formData.host }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item prepend-icon="mdi-lan">
                                    <v-list-item-title>{{ $t('setup.fields.port') }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ formData.port }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item prepend-icon="mdi-database">
                                    <v-list-item-title>{{ $t('setup.fields.database') }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ formData.auth_db }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item prepend-icon="mdi-account">
                                    <v-list-item-title>{{ $t('setup.fields.login') }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ formData.auth_user }}</v-list-item-subtitle>
                                </v-list-item>
                                <v-list-item prepend-icon="mdi-lock">
                                    <v-list-item-title>{{ $t('setup.fields.password') }}</v-list-item-title>
                                    <v-list-item-subtitle>••••••••</v-list-item-subtitle>
                                </v-list-item>
                            </v-list>

                            <v-form v-else ref="form" v-model="formValid" @submit.prevent="saveSetup">
                                <!-- Host -->
                                <v-text-field
                                    v-model="formData.host"
                                    :label="$t('setup.fields.host')"
                                    :placeholder="$t('setup.placeholders.host')"
                                    :rules="[rules.required]"
                                    :readonly="setupCompleted"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-2"
                                    prepend-inner-icon="mdi-server"
                                ></v-text-field>

                                <!-- Port -->
                                <v-text-field
                                    v-model="formData.port"
                                    :label="$t('setup.fields.port')"
                                    :placeholder="$t('setup.placeholders.port')"
                                    type="number"
                                    :rules="[rules.required, rules.port]"
                                    :readonly="setupCompleted"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-2"
                                    prepend-inner-icon="mdi-lan"
                                ></v-text-field>

                                <!-- Datenbankname -->
                                <v-text-field
                                    v-model="formData.auth_db"
                                    :label="$t('setup.fields.database')"
                                    :placeholder="$t('setup.placeholders.database')"
                                    :rules="[rules.required]"
                                    :readonly="setupCompleted"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-2"
                                    prepend-inner-icon="mdi-database"
                                ></v-text-field>

                                <!-- Login -->
                                <v-text-field
                                    v-model="formData.auth_user"
                                    :label="$t('setup.fields.login')"
                                    :placeholder="$t('setup.placeholders.login')"
                                    :rules="[rules.required]"
                                    :readonly="setupCompleted"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-2"
                                    prepend-inner-icon="mdi-account"
                                    autocomplete="username"
                                ></v-text-field>

                                <!-- Passwort -->
                                <v-text-field
                                    v-model="formData.auth_pass"
                                    :label="$t('setup.fields.password')"
                                    :type="showPassword ? 'text' : 'password'"
                                    :rules="[rules.required]"
                                    :readonly="setupCompleted"
                                    variant="outlined"
                                    density="comfortable"
                                    class="mb-4"
                                    prepend-inner-icon="mdi-lock"
                                    :append-inner-icon="showPassword ? 'mdi-eye' : 'mdi-eye-off'"
                                    @click:append-inner="!setupCompleted && (showPassword = !showPassword)"
                                    autocomplete="current-password"
                                ></v-text-field>

                                <!-- Test-Ergebnis -->
                                <v-alert
                                    v-if="testResult.message"
                                    :type="testResult.success ? 'success' : 'error'"
                                    variant="tonal"
                                    class="mb-4"
                                >
                                    {{ testResult.message }}
                                    <div v-if="testResult.version" class="text-caption mt-1">
                                        {{ testResult.version }}
                                    </div>
                                </v-alert>

                                <!-- Buttons -->
                                <div class="d-flex gap-2">
                                    <v-btn
                                        color="primary"
                                        variant="outlined"
                                        :loading="testLoading"
                                        :disabled="!formValid || saveLoading || setupCompleted"
                                        @click="testConnection"
                                        prepend-icon="mdi-connection"
                                    >
                                        {{ testLoading ? $t('setup.buttons.testingConnection') : $t('setup.buttons.testConnection') }}
                                    </v-btn>

                                    <v-spacer></v-spacer>

                                    <v-btn
                                        type="submit"
                                        color="primary"
                                        :loading="saveLoading"
                                        :disabled="!formValid || testLoading || saveLoading"
                                        prepend-icon="mdi-check"
                                    >
                                        {{ saveLoading ? $t('setup.buttons.savingSetup') : $t('setup.buttons.saveSetup') }}
                                    </v-btn>
                                </div>
                            </v-form>

                            <!-- Datenbankupdate nach Setup -->
                            <div v-if="setupCompleted" class="mt-4">
                                <v-divider class="mb-4"></v-divider>
                                <div class="text-subtitle-1 font-weight-medium mb-3">
                                    <v-icon icon="mdi-database-sync" size="small" class="mr-1"></v-icon>
                                    {{ $t('setup.update.title') }}
                                </div>

                                <v-alert
                                    v-if="updateLoading"
                                    type="info"
                                    variant="tonal"
                                    class="mb-4"
                                >
                                    <template #prepend>
                                        <v-progress-circular indeterminate size="20"></v-progress-circular>
                                    </template>
                                    {{ $t('setup.update.running') }}
                                </v-alert>

                                <v-alert
                                    v-if="updateResult && updateResult.success"
                                    type="success"
                                    variant="tonal"
                                    class="mb-4"
                                >
                                    {{ $t('setup.update.success') }}
                                </v-alert>

                                <v-alert
                                    v-if="updateError"
                                    type="warning"
                                    variant="tonal"
                                    class="mb-4"
                                >
                                    {{ updateError }}
                                </v-alert>
                            </div>

                            <!-- Abschließen-Button nach Setup und Update -->
                            <div v-if="setupCompleted && !updateLoading" class="d-flex justify-end">
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-check-all"
                                    @click="finishSetup"
                                >
                                    {{ $t('setup.buttons.finish') }}
                                </v-btn>
                            </div>
                        </div>
                    </v-card-text>
                </v-card>

                <!-- Copyright Footer -->
                <div class="text-center mt-4 text-body-2 text-grey-darken-1">
                    {{ $t('setup.footer.copyright') }} &copy; {{ new Date().getFullYear() }}
                </div>
            </v-col>
        </v-row>
    </v-container>
</template>

<script>
    export default {
        name: 'SetupView',

        setup() {
            return {}
        },

        data() {
            return {
                loading: false,
                setupRequired: true,
                setupCompleted: false,
                formValid: false,
                formData: {
                    host: 'localhost',
                    port: '5432',
                    auth_db: 'oserp_auth',
                    auth_user: 'postgres',
                    auth_pass: ''
                },
                configFound: false,
                configSource: '',
                showPassword: false,
                testLoading: false,
                saveLoading: false,
                testResult: {
                    success: false,
                    message: '',
                    version: ''
                },
                errorMessage: '',
                successMessage: '',
                updateLoading: false,
                updateResult: null,
                updateError: '',
                rules: {
                    required: value => !!value || this.$t('setup.validation.required'),
                    port: value => {
                        const port = parseInt(value);
                        return (port >= 1 && port <= 65535) || this.$t('setup.validation.portRange');
                    }
                }
            }
        },

        mounted() {
            this.loadDefaults();
        },

        methods: {
            /**
             * Lädt Defaults aus kivitendo.conf (falls vorhanden) oder verwendet App-Defaults
             */
            async loadDefaults() {
                try {
                    const response = await fetch('/api/setup/', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'getDefaults'
                        })
                    });

                    const data = await response.json();

                    if (data.success && data.payload) {
                        const defaults = data.payload.defaults;
                        this.configFound = data.payload.config_found || false;
                        this.configSource = data.payload.config_source || '';

                        // Befülle Formular mit Defaults (nur wenn Felder leer sind)
                        if (defaults.host) this.formData.host = defaults.host;
                        if (defaults.port) this.formData.port = defaults.port;
                        if (defaults.auth_db) this.formData.auth_db = defaults.auth_db;
                        if (defaults.auth_user) this.formData.auth_user = defaults.auth_user;
                        if (defaults.auth_pass) this.formData.auth_pass = defaults.auth_pass;
                    }
                } catch (error) {
                    console.error('Fehler beim Laden der Defaults:', error);
                    // Kein Error-Message - Defaults sind optional
                }
            },

            /**
             * Testet die Datenbankverbindung
             */
            async testConnection() {
                this.testLoading = true;
                this.testResult = { success: false, message: '', version: '' };
                this.errorMessage = '';

                try {
                    const response = await fetch('/api/setup/', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'test',
                            data: this.formData
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.testResult = data.payload;
                    } else {
                        if (data.payload && data.payload.errors) {
                            this.errorMessage = data.payload.errors.join(', ');
                        } else if (data.payload && data.payload.message) {
                            this.testResult.message = data.payload.message;
                        } else {
                            this.errorMessage = this.$t('setup.errors.testConnection');
                        }
                    }
                } catch (error) {
                    console.error('Fehler beim Testen der Verbindung:', error);
                    this.errorMessage = this.$t('setup.errors.testNetworkError');
                } finally {
                    this.testLoading = false;
                }
            },

            /**
             * Speichert die Konfiguration
             */
            /**
             * Schließt das Setup ab und leitet zum Login weiter
             */
            finishSetup() {
                window.location.href = '/login';
            },

            /**
             * Führt das Datenbankupdate nach dem Setup aus
             */
            async runDatabaseUpdate() {
                this.updateLoading = true;
                this.updateError = '';
                this.updateResult = null;

                try {
                    const response = await fetch('/api/update/', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'updateAllDatabases',
                            dry_run: false
                        })
                    });

                    const data = await response.json();
                    this.updateResult = data;

                    if (!data.success) {
                        this.updateError = data.text || this.$t('setup.update.failed');
                    }
                } catch (error) {
                    console.error('Fehler beim Datenbankupdate:', error);
                    this.updateError = this.$t('setup.update.networkError');
                } finally {
                    this.updateLoading = false;
                }
            },

            /**
             * Speichert die Konfiguration
             */
            async saveSetup() {
                if (!this.formValid) {
                    return;
                }

                this.saveLoading = true;
                this.errorMessage = '';
                this.successMessage = '';

                try {
                    const response = await fetch('/api/setup/', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            action: 'save',
                            data: this.formData
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.setupCompleted = true;
                        this.successMessage = this.$t('setup.setupCompleteMessage');
                        // Vorherige Meldungen ausblenden
                        this.configFound = false;
                        this.testResult = { success: false, message: '', version: '' };

                        // Datenbankupdate automatisch starten
                        this.runDatabaseUpdate();
                    } else {
                        if (data.payload && data.payload.errors) {
                            this.errorMessage = data.payload.errors.join(', ');
                        } else if (data.payload && data.payload.message) {
                            this.errorMessage = data.payload.message;
                        } else {
                            this.errorMessage = data.text || this.$t('setup.errors.saveSetup');
                        }
                    }
                } catch (error) {
                    console.error('Fehler beim Speichern:', error);
                    this.errorMessage = this.$t('setup.errors.saveNetworkError');
                } finally {
                    this.saveLoading = false;
                }
            }
        }
    }
</script>

<style scoped>
.gap-2 {
    gap: 8px;
}
</style>