<!-- src/core/views/setup/setup.view.vue -->
<!--
    Setup-Assistent: richtet OSERP ohne k9o ein.
      1. Datenbankserver prüfen (Server wird untersucht: Rechte, vorhandene Datenbanken)
      2. Auth-Datenbank: neu anlegen oder vorhandene (k9o) übernehmen
      3. Administrator anlegen bzw. vorhandenen Benutzer bestimmen
      4. Erste Firma mit Kontenrahmen anlegen (optional)
      5. Zusammenfassung, Installation, Weiter zur Anmeldung
    Backend: backend/api/setup/setup.php (probe, install)
-->
<template>
    <v-container fluid class="fill-height bg-grey-lighten-4">
        <v-row align="center" justify="center">
            <v-col cols="12" md="10" lg="8" xl="6">
                <v-card elevation="8" class="mx-auto">
                    <v-card-title class="bg-primary text-white py-5 d-flex align-center ga-3">
                        <v-icon icon="mdi-rocket-launch-outline" size="large" />
                        <div>
                            <div class="text-h5">{{ $t('setup.title') }}</div>
                            <div class="text-body-2 opacity-80">{{ $t('setup.subtitle') }}</div>
                        </div>
                    </v-card-title>

                    <v-alert v-if="!setupRequired" type="info" variant="tonal" class="ma-4">{{ $t('setup.setupAlreadyDone') }}</v-alert>

                    <template v-else>
                        <!-- Unfertige Installation: sagen, was fehlt, statt den Benutzer raten zu lassen -->
                        <v-alert
                            v-if="stageHint"
                            type="warning"
                            variant="tonal"
                            class="ma-4 mb-0"
                            icon="mdi-wrench-outline"
                        >
                            {{ stageHint }}
                        </v-alert>

                        <v-stepper v-model="step" :items="stepTitles" flat hide-actions :mobile="mobile" class="setup-stepper">
                            <!-- ── 1. Server ── -->
                            <template #item.1>
                                <div class="pa-2">
                                    <p class="text-body-1 mb-4">{{ $t('setup.server.intro') }}</p>
                                    <v-alert v-if="configFound && !useStored" type="success" variant="tonal" density="compact" class="mb-4">
                                        {{ $t('setup.server.configFound') }} <span class="text-caption">{{ configSource }}</span>
                                    </v-alert>

                                    <!-- Zugang steht schon in der Konfiguration: niemand muss das Passwort erneut eintippen -->
                                    <v-alert v-if="useStored" type="info" variant="tonal" density="compact" class="mb-4">
                                        <div>{{ $t('setup.server.stored', { host: server.host, port: server.port, user: server.user }) }}</div>
                                        <v-btn size="small" variant="text" class="mt-1 px-0" @click="useStored = false">
                                            {{ $t('setup.server.enterManually') }}
                                        </v-btn>
                                    </v-alert>

                                    <v-form v-if="!useStored" ref="serverForm" v-model="serverValid" @submit.prevent="probeServer">
                                        <v-row dense>
                                            <v-col cols="8">
                                                <v-text-field v-model="server.host" :label="$t('setup.fields.host')" :rules="[rules.required]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-server" />
                                            </v-col>
                                            <v-col cols="4">
                                                <v-text-field v-model="server.port" :label="$t('setup.fields.port')" type="number" :rules="[rules.required, rules.port]" variant="outlined" density="comfortable" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="server.user" :label="$t('setup.fields.login')" :hint="$t('setup.server.userHint')" persistent-hint :rules="[rules.required]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-account-key-outline" autocomplete="username" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="server.pass" :label="$t('setup.fields.password')" :type="showPass ? 'text' : 'password'" :rules="[rules.required]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-lock-outline" :append-inner-icon="showPass ? 'mdi-eye-off-outline' : 'mdi-eye-outline'" autocomplete="current-password" @click:append-inner="showPass = !showPass" @keyup.enter="probeServer" />
                                            </v-col>
                                        </v-row>
                                    </v-form>

                                    <v-alert v-if="probeError" type="error" variant="tonal" class="mb-3">{{ probeError }}</v-alert>

                                    <v-card v-if="probe" variant="tonal" color="success" class="mb-3">
                                        <v-card-text>
                                            <div class="d-flex align-center ga-2 font-weight-bold"><v-icon>mdi-check-circle-outline</v-icon>{{ $t('setup.server.connected', { version: probe.server.version }) }}</div>
                                            <div class="d-flex flex-wrap ga-2 mt-2">
                                                <v-chip size="small" :color="probe.server.createdb || probe.server.superuser ? 'success' : 'error'" variant="flat">
                                                    {{ probe.server.superuser ? $t('setup.server.superuser') : (probe.server.createdb ? $t('setup.server.createdb') : $t('setup.server.noCreatedb')) }}
                                                </v-chip>
                                                <v-chip size="small" variant="outlined">{{ $t('setup.server.foundAuth', { count: authDatabases.length }) }}</v-chip>
                                                <v-chip size="small" variant="outlined">{{ $t('setup.server.foundCompany', { count: companyDatabases.length }) }}</v-chip>
                                            </div>
                                            <div v-if="!probe.server.createdb && !probe.server.superuser" class="text-body-2 mt-2">{{ $t('setup.server.noCreatedbHint') }}</div>
                                        </v-card-text>
                                    </v-card>
                                </div>
                            </template>

                            <!-- ── 2. Auth-DB ── -->
                            <template #item.2>
                                <div class="pa-2">
                                    <p class="text-body-1 mb-4">{{ $t('setup.auth.intro') }}</p>
                                    <v-radio-group v-model="auth.mode" hide-details>
                                        <v-card :variant="auth.mode === 'new' ? 'tonal' : 'outlined'" :color="auth.mode === 'new' ? 'primary' : undefined" class="mb-3 mode-card" @click="auth.mode = 'new'">
                                            <v-card-text class="d-flex ga-3 align-start">
                                                <v-radio value="new" hide-details density="compact" />
                                                <div class="flex-grow-1">
                                                    <div class="font-weight-bold">{{ $t('setup.auth.modeNew') }}</div>
                                                    <div class="text-caption mb-2">{{ $t('setup.auth.modeNewHint') }}</div>
                                                    <v-text-field v-if="auth.mode === 'new'" v-model="auth.name" :label="$t('setup.auth.name')" :rules="[rules.required, rules.dbname, rules.authNameFree]" variant="outlined" density="compact" class="font-mono" @click.stop />
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                        <v-card :variant="auth.mode === 'existing' ? 'tonal' : 'outlined'" :color="auth.mode === 'existing' ? 'primary' : undefined" :disabled="existingDatabases.length === 0" class="mode-card" @click="existingDatabases.length && (auth.mode = 'existing')">
                                            <v-card-text class="d-flex ga-3 align-start">
                                                <v-radio value="existing" hide-details density="compact" :disabled="existingDatabases.length === 0" />
                                                <div class="flex-grow-1">
                                                    <div class="font-weight-bold">{{ $t('setup.auth.modeExisting') }}</div>
                                                    <div class="text-caption mb-2">{{ existingDatabases.length ? $t('setup.auth.modeExistingHint') : $t('setup.auth.noneFound') }}</div>
                                                    <v-select v-if="auth.mode === 'existing'" v-model="auth.existingName" :items="existingDatabases" item-title="name" item-value="name" :label="$t('setup.auth.select')" variant="outlined" density="compact" @click.stop>
                                                        <template #item="{ props: itemProps, item }">
                                                            <v-list-item v-bind="itemProps" :subtitle="dbSubtitle(item.raw)" />
                                                        </template>
                                                    </v-select>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                    </v-radio-group>
                                </div>
                            </template>

                            <!-- ── 3. Administrator ── -->
                            <template #item.3>
                                <div class="pa-2">
                                    <p class="text-body-1 mb-4">{{ hasExistingUsers ? $t('setup.admin.introExisting') : $t('setup.admin.intro') }}</p>

                                    <!-- Standardzugang oder eigener Administrator. Gibt es in der
                                         Auth-Datenbank schon Benutzer, ist nur der eigene moeglich:
                                         dann muss sich jemand ausweisen, der bereits Zugang hat. -->
                                    <v-radio-group v-if="!hasExistingUsers" v-model="adminMode" hide-details class="mb-4">
                                        <v-card :variant="adminMode === 'default' ? 'tonal' : 'outlined'" :color="adminMode === 'default' ? 'primary' : undefined" class="mb-3 mode-card" @click="adminMode = 'default'">
                                            <v-card-text class="d-flex ga-3 align-start">
                                                <v-radio value="default" hide-details density="compact" />
                                                <div>
                                                    <div class="font-weight-bold">{{ $t('setup.admin.modeDefault', { login: defaultAdminLogin }) }}</div>
                                                    <div class="text-caption">{{ $t('setup.admin.modeDefaultHint', { login: defaultAdminLogin }) }}</div>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                        <v-card :variant="adminMode === 'custom' ? 'tonal' : 'outlined'" :color="adminMode === 'custom' ? 'primary' : undefined" class="mode-card" @click="adminMode = 'custom'">
                                            <v-card-text class="d-flex ga-3 align-start">
                                                <v-radio value="custom" hide-details density="compact" />
                                                <div>
                                                    <div class="font-weight-bold">{{ $t('setup.admin.modeCustom') }}</div>
                                                    <div class="text-caption">{{ $t('setup.admin.modeCustomHint') }}</div>
                                                </div>
                                            </v-card-text>
                                        </v-card>
                                    </v-radio-group>

                                    <v-alert v-if="hasExistingUsers" type="info" variant="tonal" density="compact" class="mb-4">
                                        {{ $t('setup.admin.existingUsersHint') }}
                                    </v-alert>
                                    <v-alert v-else-if="adminMode === 'default'" type="warning" variant="tonal" density="compact" class="mb-2">
                                        {{ $t('setup.admin.defaultWarning', { login: defaultAdminLogin }) }}
                                    </v-alert>

                                    <v-form v-if="adminMode === 'custom'" ref="adminForm" v-model="adminValid">
                                        <v-row dense>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="admin.login" :label="$t('setup.admin.login')" :hint="hasExistingUsers ? $t('setup.admin.loginHintExisting') : ''" persistent-hint :rules="[rules.required, rules.login]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-account-outline" autocomplete="off" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="admin.name" :label="$t('setup.admin.name')" variant="outlined" density="comfortable" prepend-inner-icon="mdi-card-account-details-outline" />
                                            </v-col>
                                            <v-col cols="12">
                                                <v-text-field v-model="admin.email" :label="$t('setup.admin.email')" :rules="[rules.email]" type="email" variant="outlined" density="comfortable" prepend-inner-icon="mdi-email-outline" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="admin.password" :label="$t('setup.admin.password')" :type="showAdminPass ? 'text' : 'password'" :rules="[rules.adminPassword]" :hint="hasExistingUsers ? $t('setup.admin.passwordHintExisting') : $t('setup.admin.passwordHint')" persistent-hint variant="outlined" density="comfortable" prepend-inner-icon="mdi-lock-outline" autocomplete="new-password">
                                                    <template #append-inner>
                                                        <v-icon class="cursor-pointer" @click="showAdminPass = !showAdminPass">{{ showAdminPass ? 'mdi-eye-off-outline' : 'mdi-eye-outline' }}</v-icon>
                                                        <v-icon class="cursor-pointer ms-2" :title="$t('setup.admin.generate')" @click="generateAdminPassword">mdi-dice-multiple-outline</v-icon>
                                                    </template>
                                                </v-text-field>
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="admin.passwordConfirm" :label="$t('setup.admin.passwordConfirm')" :type="showAdminPass ? 'text' : 'password'" :rules="[rules.adminPasswordMatch]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-lock-check-outline" autocomplete="new-password" />
                                            </v-col>
                                        </v-row>
                                    </v-form>
                                </div>
                            </template>

                            <!-- ── 4. Firma ── -->
                            <template #item.4>
                                <div class="pa-2">
                                    <p class="text-body-1 mb-3">{{ $t('setup.company.intro') }}</p>
                                    <v-switch v-model="company.create" :label="$t('setup.company.createSwitch')" color="primary" inset hide-details class="mb-3" />
                                    <v-expand-transition>
                                        <v-form v-if="company.create" ref="companyForm" v-model="companyValid">
                                            <v-text-field v-model="company.name" :label="$t('setup.company.name')" :rules="[rules.required]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-domain" @update:model-value="onCompanyName" />
                                            <v-text-field v-model="company.dbname" :label="$t('setup.company.dbname')" :hint="$t('setup.company.dbnameHint')" persistent-hint :rules="[rules.required, rules.dbname, rules.companyDbFree]" variant="outlined" density="comfortable" prepend-inner-icon="mdi-database-outline" class="font-mono mb-3" @update:model-value="dbnameTouched = true" />
                                            <div class="text-subtitle-2 mb-2">{{ $t('setup.company.chart') }}</div>
                                            <v-row dense>
                                                <v-col v-for="chart in charts" :key="chart.id" cols="12" sm="6">
                                                    <v-card :variant="company.skr === chart.id ? 'tonal' : 'outlined'" :color="company.skr === chart.id ? 'primary' : undefined" hover class="h-100" @click="company.skr = chart.id">
                                                        <v-card-text class="pa-3">
                                                            <div class="d-flex align-center ga-2 font-weight-bold">
                                                                <v-icon size="small">{{ company.skr === chart.id ? 'mdi-radiobox-marked' : 'mdi-radiobox-blank' }}</v-icon>{{ chart.name }}
                                                            </div>
                                                            <div class="text-caption mt-1">{{ $te('setup.company.charts.' + chart.id) ? $t('setup.company.charts.' + chart.id) : '' }}</div>
                                                        </v-card-text>
                                                    </v-card>
                                                </v-col>
                                            </v-row>
                                        </v-form>
                                    </v-expand-transition>
                                    <v-alert v-if="!company.create && auth.mode === 'new'" type="warning" variant="tonal" density="compact" class="mt-2">{{ $t('setup.company.noneWarning') }}</v-alert>
                                </div>
                            </template>

                            <!-- ── 5. Zusammenfassung / Installation ── -->
                            <template #item.5>
                                <div class="pa-2">
                                    <template v-if="!installing && !installResult">
                                        <p class="text-body-1 mb-3">{{ $t('setup.summary.intro') }}</p>
                                        <v-list density="comfortable" class="border rounded mb-3">
                                            <v-list-item prepend-icon="mdi-server" :title="$t('setup.summary.server')" :subtitle="`${server.host}:${server.port} (${server.user})`" />
                                            <v-list-item prepend-icon="mdi-shield-key-outline" :title="$t('setup.summary.auth')" :subtitle="auth.mode === 'new' ? $t('setup.summary.authNew', { name: auth.name }) : $t('setup.summary.authExisting', { name: auth.existingName })" />
                                            <v-list-item prepend-icon="mdi-account-key-outline" :title="$t('setup.summary.admin')" :subtitle="adminSummary" />
                                            <v-list-item prepend-icon="mdi-domain" :title="$t('setup.summary.company')" :subtitle="company.create ? `${company.name} — ${company.dbname} [${company.skr.toUpperCase()}]` : $t('setup.summary.noCompany')" />
                                        </v-list>
                                        <v-alert v-if="installError" type="error" variant="tonal" class="mb-3">{{ installError }}</v-alert>
                                    </template>
                                    <div v-else-if="installing" class="text-center pa-6">
                                        <v-progress-circular indeterminate color="primary" size="56" width="5" class="mb-4" />
                                        <div class="text-h6">{{ $t('setup.summary.installing') }}</div>
                                        <div class="text-body-2 text-medium-emphasis mt-2">{{ $t('setup.summary.installingHint') }}</div>
                                    </div>
                                    <div v-else>
                                        <div class="text-center mb-4">
                                            <v-icon color="success" size="64">mdi-check-circle-outline</v-icon>
                                            <div class="text-h6 mt-2">{{ $t('setup.summary.done') }}</div>
                                        </div>
                                        <v-list density="compact" class="border rounded mb-3">
                                            <v-list-item v-for="s in installResult.steps" :key="s.step" prepend-icon="mdi-check" :title="$t('setup.summary.steps.' + s.step)" :subtitle="s.detail" />
                                        </v-list>
                                        <v-alert v-if="installResult.warnings?.length" type="warning" variant="tonal" class="mb-3">
                                            <div v-for="(w, i) in installResult.warnings" :key="i" class="text-body-2">{{ w }}</div>
                                        </v-alert>
                                        <v-alert
                                            :type="installResult.admin_default_password ? 'warning' : 'info'"
                                            variant="tonal"
                                            density="compact"
                                        >
                                            {{ installResult.admin_default_password
                                                ? $t('setup.summary.loginHintDefault', { login: installResult.admin_login })
                                                : $t('setup.summary.loginHint', { login: installResult.admin_login || admin.login }) }}
                                        </v-alert>
                                    </div>
                                </div>
                            </template>
                        </v-stepper>

                        <v-divider />
                        <v-card-actions class="pa-4">
                            <v-btn v-if="step > 1 && !installing && !installResult" variant="text" prepend-icon="mdi-chevron-left" @click="step--">{{ $t('setup.buttons.back') }}</v-btn>
                            <v-spacer />
                            <template v-if="step === 1">
                                <v-btn color="primary" variant="outlined" :loading="probing" :disabled="!serverValid && !useStored" prepend-icon="mdi-connection" class="me-2" @click="probeServer">{{ $t('setup.buttons.testConnection') }}</v-btn>
                                <v-btn color="primary" variant="flat" :disabled="!probe" append-icon="mdi-chevron-right" @click="step = 2">{{ $t('setup.buttons.next') }}</v-btn>
                            </template>
                            <v-btn v-else-if="step === 2" color="primary" variant="flat" :disabled="!authStepValid" append-icon="mdi-chevron-right" @click="step = 3">{{ $t('setup.buttons.next') }}</v-btn>
                            <v-btn v-else-if="step === 3" color="primary" variant="flat" :disabled="adminMode === 'custom' && !adminValid" append-icon="mdi-chevron-right" @click="goToCompany">{{ $t('setup.buttons.next') }}</v-btn>
                            <v-btn v-else-if="step === 4" color="primary" variant="flat" :disabled="company.create && !companyValid" append-icon="mdi-chevron-right" @click="step = 5">{{ $t('setup.buttons.next') }}</v-btn>
                            <template v-else-if="step === 5">
                                <v-btn v-if="!installResult" color="primary" variant="flat" :loading="installing" prepend-icon="mdi-rocket-launch-outline" @click="runInstall">{{ $t('setup.buttons.install') }}</v-btn>
                                <v-btn v-else color="primary" variant="flat" prepend-icon="mdi-login" @click="finishSetup">{{ $t('setup.buttons.finish') }}</v-btn>
                            </template>
                        </v-card-actions>
                    </template>
                </v-card>

                <div class="text-center mt-4 text-body-2 text-grey-darken-1">
                    {{ $t('setup.footer.copyright') }} &copy; {{ new Date().getFullYear() }}
                </div>
            </v-col>
        </v-row>
    </v-container>
</template>

<script>
import { useDisplay } from 'vuetify';
import { generatePassword, isValidDbName, isValidEmail, isValidLogin, suggestDbName } from '@/core/views/admin/adminHelpers.js';

export default {
    name: 'SetupView',

    setup() {
        const { mobile } = useDisplay();
        return { mobile };
    },

    data() {
        return {
            setupRequired: true,
            step: 1,
            // Zustand der Installation (Backend-Aktion "status"): entscheidet, ob der
            // Assistent bei null anfaengt oder eine angefangene Einrichtung fortsetzt
            state: null,
            useStored: false,
            adminMode: 'default',
            defaultAdminLogin: 'admin',
            configFound: false,
            configSource: '',
            showPass: false,
            showAdminPass: false,
            server: { host: 'localhost', port: '5432', user: 'postgres', pass: '' },
            serverValid: false,
            probing: false,
            probe: null,
            probeError: '',
            auth: { mode: 'new', name: 'oserp_auth', existingName: null },
            admin: { login: 'admin', name: '', email: '', password: '', passwordConfirm: '' },
            adminValid: false,
            company: { create: true, name: '', dbname: '', skr: 'skr03' },
            companyValid: false,
            dbnameTouched: false,
            installing: false,
            installResult: null,
            installError: ''
        };
    },

    computed: {
        stepTitles() {
            return [
                this.$t('setup.steps.server'), this.$t('setup.steps.auth'), this.$t('setup.steps.admin'),
                this.$t('setup.steps.company'), this.$t('setup.steps.summary')
            ];
        },
        databases() {
            return this.probe?.databases || [];
        },
        authDatabases() {
            return this.databases.filter(d => d.is_auth);
        },
        // Uebernehmbar sind vorhandene Auth-Datenbanken und leere Datenbanken. Leere
        // kommen haeufig vom Hoster, wo man selbst keine anlegen darf.
        existingDatabases() {
            return this.databases.filter(d => d.is_auth || d.is_empty);
        },
        hasExistingUsers() {
            return (this.state?.users || 0) > 0;
        },
        // Hinweistext ueber dem Assistenten, wenn schon etwas da ist
        stageHint() {
            const stage = this.state?.stage;
            if (!stage || stage === 'fresh') return '';
            const key = 'setup.state.' + stage;
            return this.$te(key) ? this.$t(key, { db: this.state.auth_db }) : '';
        },
        adminSummary() {
            if (this.adminMode === 'default' && !this.hasExistingUsers) {
                return this.$t('setup.summary.adminDefault', { login: this.defaultAdminLogin });
            }
            return this.admin.login + (this.admin.name ? ' (' + this.admin.name + ')' : '');
        },
        companyDatabases() {
            return this.databases.filter(d => d.is_company);
        },
        charts() {
            return this.probe?.charts?.length ? this.probe.charts : [{ id: 'skr03', name: 'SKR03' }, { id: 'skr04', name: 'SKR04' }];
        },
        authStepValid() {
            if (this.auth.mode === 'new') {
                // Eine Datenbank, die es schon gibt, kann nicht neu angelegt werden —
                // ausser sie steht bereits in der Konfiguration und fehlt auf dem Server.
                const belegt = this.databases.some(d => d.name === this.auth.name);
                return isValidDbName(this.auth.name) && !belegt;
            }
            return !!this.auth.existingName;
        },
        rules() {
            return {
                required: v => !!(v !== null && v !== undefined && String(v).trim()) || this.$t('setup.validation.required'),
                port: v => (Number(v) >= 1 && Number(v) <= 65535) || this.$t('setup.validation.portRange'),
                dbname: v => isValidDbName(v) || this.$t('setup.validation.dbname'),
                authNameFree: v => !this.databases.some(d => d.name === v) || this.$t('setup.validation.dbExists'),
                companyDbFree: v => !this.databases.some(d => d.name === v) || this.$t('setup.validation.dbExists'),
                login: v => isValidLogin(v) || this.$t('setup.validation.login'),
                email: v => !v || isValidEmail(v) || this.$t('setup.validation.email'),
                // Bei vorhandener Auth-DB darf das Passwort leer bleiben (Benutzer existiert schon)
                adminPassword: v => this.adminMode !== 'custom' || (this.hasExistingUsers && !v) || (!!v && v.length >= 8) || this.$t('setup.validation.passwordLength'),
                adminPasswordMatch: v => v === this.admin.password || this.$t('setup.validation.passwordMismatch')
            };
        }
    },

    async mounted() {
        await this.loadState();
        if (!this.useStored) {
            this.loadDefaults();
        }
    },

    methods: {
        /**
         * Holt den Einrichtungsstand vom Server
         *
         * Gibt es bereits eine settings.ini, ist die Installation nur angefangen:
         * dann uebernimmt der Assistent Zugang und Datenbanknamen von dort, und der
         * Benutzer muss nichts davon erneut eingeben.
         */
        async loadState() {
            try {
                const data = await this.api('status');
                if (!data.success || !data.payload) return;
                const st = data.payload;
                this.state = st;
                this.defaultAdminLogin = st.default_admin_login || 'admin';

                if (!st.settings_exists) return;

                this.useStored = true;
                this.server.host = st.host;
                this.server.port = String(st.port);
                this.server.user = st.auth_user;
                if (st.stage === 'no_database') {
                    // Konfiguration zeigt auf eine Datenbank, die es noch nicht gibt
                    this.auth.mode = 'new';
                    this.auth.name = st.auth_db;
                } else {
                    this.auth.mode = 'existing';
                    this.auth.existingName = st.auth_db;
                }
                if (st.users > 0) {
                    this.adminMode = 'custom';   // vorhandene Installation: Ausweis noetig
                }
                await this.probeServer();
            } catch (e) {
                // Ohne Zustand laeuft der Assistent wie beim Erstaufruf weiter
            }
        },

        /**
         * Untertitel eines Datenbankeintrags in der Auswahlliste
         *
         * @param {Object} db - Eintrag aus der Serverabfrage
         * @return {string}
         */
        dbSubtitle(db) {
            if (db.is_auth) {
                return this.$t('setup.auth.dbMeta', { users: db.users ?? 0, clients: db.clients ?? 0 });
            }
            return this.$t('setup.auth.dbEmpty');
        },

        /**
         * Vorbelegung aus einer vorhandenen k9o-Konfiguration (falls auf dem Server)
         */
        async loadDefaults() {
            try {
                const data = await this.api('getDefaults');
                if (data.success && data.payload) {
                    const d = data.payload.defaults;
                    this.configFound = data.payload.config_found || false;
                    this.configSource = data.payload.config_source || '';
                    if (d.host) this.server.host = d.host;
                    if (d.port) this.server.port = String(d.port);
                    if (d.auth_user) this.server.user = d.auth_user;
                    if (d.auth_pass) this.server.pass = d.auth_pass;
                    if (this.configFound && d.auth_db) this.auth.existingName = d.auth_db;
                }
            } catch (e) {
                // Defaults sind optional
            }
        },

        async api(action, payload = {}) {
            const response = await fetch('/api/setup/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...payload })
            });
            return response.json();
        },

        /**
         * Server untersuchen: Rechte und vorhandene Datenbanken
         */
        async probeServer() {
            this.probing = true;
            this.probe = null;
            this.probeError = '';
            try {
                const data = await this.api('probe', this.useStored
                    ? { use_stored_credentials: true }
                    : { host: this.server.host, port: Number(this.server.port), user: this.server.user, pass: this.server.pass });
                if (data.success) {
                    this.probe = data.payload;
                    // Sinnvolle Vorbelegung: gibt es genau eine Auth-DB, diese anbieten
                    if (this.authDatabases.length && !this.auth.existingName) this.auth.existingName = this.authDatabases[0].name;
                    if (this.authDatabases.length && this.configFound && !this.useStored) this.auth.mode = 'existing';
                    if (!this.useStored && this.auth.mode === 'new' && this.databases.some(d => d.name === this.auth.name)) {
                        this.auth.name = 'oserp_auth_' + new Date().getFullYear();
                    }
                } else {
                    this.probeError = data.payload?.message || data.payload || data.text || this.$t('setup.errors.testConnection');
                }
            } catch (e) {
                this.probeError = this.$t('setup.errors.testNetworkError');
            } finally {
                this.probing = false;
            }
        },

        generateAdminPassword() {
            const pw = generatePassword(14);
            this.admin.password = pw;
            this.admin.passwordConfirm = pw;
            this.showAdminPass = true;
        },

        onCompanyName(value) {
            if (!this.dbnameTouched) this.company.dbname = suggestDbName(value);
        },

        goToCompany() {
            // Vorhandene Auth-DB mit Firmen: Firmenanlage standardmäßig aus
            const existing = this.authDatabases.find(d => d.name === this.auth.existingName);
            if (this.auth.mode === 'existing' && existing && existing.clients > 0) this.company.create = false;
            this.step = 4;
        },

        async runInstall() {
            this.installing = true;
            this.installError = '';
            try {
                // Beim Standardzugang bleibt der Anmeldename leer — dann legt der Server
                // admin/admin an. Sonst werden die eingegebenen Daten uebernommen; bei einer
                // Installation mit vorhandenen Benutzern dienen sie zugleich als Ausweis.
                const eigenerAdmin = this.adminMode === 'custom' || this.hasExistingUsers;
                const data = await this.api('install', {
                    data: {
                        use_stored_credentials: this.useStored,
                        host: this.server.host, port: Number(this.server.port), user: this.server.user, pass: this.server.pass,
                        auth_db: this.auth.mode === 'new' ? this.auth.name : this.auth.existingName,
                        auth_mode: this.auth.mode,
                        admin_login: eigenerAdmin ? this.admin.login : '',
                        admin_password: eigenerAdmin ? this.admin.password : '',
                        admin_name: eigenerAdmin ? this.admin.name : '',
                        admin_email: eigenerAdmin ? this.admin.email : '',
                        company_create: this.company.create, company_name: this.company.name, company_db: this.company.dbname, company_skr: this.company.skr, company_default: true
                    }
                });
                if (data.success) {
                    this.installResult = data.payload;
                } else {
                    // Bekannte Codes uebersetzt anzeigen, sonst die Meldung des Servers
                    const key = 'setup.errors.' + data.text;
                    this.installError = this.$te(key)
                        ? this.$t(key)
                        : ((typeof data.payload === 'string' ? data.payload : data.payload?.message) || data.text || this.$t('setup.errors.install'));
                }
            } catch (e) {
                this.installError = this.$t('setup.errors.saveNetworkError');
            } finally {
                this.installing = false;
            }
        },

        finishSetup() {
            // Harter Reload: settings.ini existiert jetzt, der Router muss neu entscheiden
            window.location.href = '/';
        }
    }
};
</script>

<style scoped>
.font-mono :deep(input) { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.opacity-80 { opacity: 0.85; }
.setup-stepper :deep(.v-stepper-window) { margin: 0; }
/* Fuenf Schritte passen nur mit kurzen Beschriftungen in eine Zeile. In Sprachen mit
   laengeren Woertern (es, fi, ru ...) wurde der letzte Schritt abgeschnitten. Deshalb:
   Verbindungslinien weg (sie haben flex-grow und erzwingen sonst je Schritt eine eigene
   Zeile) und die Schritte duerfen umbrechen. */
.setup-stepper :deep(.v-stepper-header) {
    flex-wrap: wrap;
    justify-content: flex-start;
    row-gap: 2px;
    box-shadow: none;
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.setup-stepper :deep(.v-stepper-header .v-divider) { display: none; }
.setup-stepper :deep(.v-stepper-item) {
    flex: 0 1 auto;
    padding: 12px 10px;
    font-size: 0.9rem;
}
/* v-radio wächst standardmäßig (flex: 1 0) — in den Auswahlkarten soll der Text direkt daneben stehen */
.mode-card :deep(.v-selection-control) { flex: 0 0 auto; }
.mode-card { cursor: pointer; }
</style>
