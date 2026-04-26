<template>
    <NavbarView />
    <v-container fluid>
        <v-row>
            <v-col cols="12">
                <h1 class="text-h5 mb-4">{{ t('BankingView.overview.title') }}</h1>
            </v-col>
        </v-row>

        <!-- Keine Konten -->
        <v-row v-if="!banking.loading.value && accounts.length === 0">
            <v-col cols="12">
                <v-alert type="info" variant="tonal">
                    {{ t('BankingView.overview.noAccounts') }}
                </v-alert>
            </v-col>
        </v-row>

        <!-- Kontenkarten -->
        <v-row>
            <v-col
                v-for="account in accounts"
                :key="account.id"
                cols="12"
                md="6"
                lg="4"
            >
                <v-card elevation="2" class="banking-account-card">
                    <v-card-title class="d-flex align-center">
                        <v-icon class="mr-2" color="primary">mdi-bank</v-icon>
                        {{ account.name }}
                        <v-spacer />
                        <v-chip
                            v-if="account.fints_id"
                            size="x-small"
                            color="success"
                            variant="tonal"
                        >
                            {{ t('BankingView.overview.fintsConfigured') }}
                        </v-chip>
                    </v-card-title>

                    <v-card-text>
                        <!-- IBAN -->
                        <div class="text-body-2 text-medium-emphasis mb-2">
                            {{ formatIban(account.iban) }}
                        </div>

                        <!-- Kontostand -->
                        <div class="text-h4 font-weight-bold mb-3" :class="balanceColor(account.balance)">
                            {{ formatCurrency(account.balance) }}
                        </div>

                        <!-- Statistiken -->
                        <v-row dense>
                            <v-col cols="6">
                                <div class="text-caption text-medium-emphasis">
                                    {{ t('BankingView.overview.unmatched') }}
                                </div>
                                <div class="text-body-1">
                                    <v-chip
                                        v-if="account.unmatched_count > 0"
                                        size="small"
                                        color="warning"
                                        variant="tonal"
                                    >
                                        {{ account.unmatched_count }}
                                    </v-chip>
                                    <span v-else class="text-success">0</span>
                                </div>
                            </v-col>
                            <v-col cols="6">
                                <div class="text-caption text-medium-emphasis">
                                    {{ t('BankingView.overview.lastSync') }}
                                </div>
                                <div class="text-body-2">
                                    {{ account.last_sync ? formatDate(account.last_sync) : '—' }}
                                </div>
                            </v-col>
                        </v-row>
                    </v-card-text>

                    <v-divider />

                    <v-card-actions>
                        <v-btn
                            variant="text"
                            color="primary"
                            size="small"
                            @click="viewTransactions(account.id)"
                        >
                            <v-icon start>mdi-format-list-bulleted</v-icon>
                            {{ t('BankingView.overview.viewTransactions') }}
                        </v-btn>

                        <v-btn
                            variant="text"
                            color="primary"
                            size="small"
                            @click="newTransferFor(account.id)"
                        >
                            <v-icon start>mdi-bank-transfer-out</v-icon>
                            {{ t('BankingView.transfers.newTransfer') }}
                        </v-btn>

                        <v-spacer />

                        <v-btn
                            variant="tonal"
                            color="secondary"
                            size="small"
                            @click="openFintsSetup(account)"
                        >
                            <v-icon start>mdi-cog</v-icon>
                            {{ account.fints_id ? t('BankingView.overview.editFints') : t('BankingView.overview.setupFints') }}
                        </v-btn>

                        <v-btn
                            v-if="account.fints_id"
                            variant="tonal"
                            color="primary"
                            size="small"
                            @click="openSyncDialog(account)"
                        >
                            <v-icon start>mdi-sync</v-icon>
                            {{ t('BankingView.overview.syncButton') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>

        <!-- FinTS Setup Dialog -->
        <v-dialog v-model="showFintsDialog" max-width="600" persistent>
            <v-card>
                <v-card-title>{{ t('BankingView.fints.title') }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="fintsForm.fints_bank_code"
                        :label="t('BankingView.fints.bankCode')"
                        class="mb-3"
                        @update:model-value="onBankCodeChange"
                    />
                    <v-text-field
                        v-model="fintsForm.fints_url"
                        :label="t('BankingView.fints.url')"
                        :hint="fintsBankName ? t('BankingView.fints.urlAutodetected', { bank: fintsBankName }) : t('BankingView.fints.urlHint')"
                        persistent-hint
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="fintsForm.fints_username"
                        :label="t('BankingView.fints.username')"
                        class="mb-3"
                    />
                    <v-select
                        v-model="fintsForm.fints_tan_mode"
                        :label="t('BankingView.fints.tanMode')"
                        :items="tanModeOptions"
                        :hint="t('BankingView.fints.tanModeHint')"
                        persistent-hint
                        clearable
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="fintsForm.sync_from_date"
                        :label="t('BankingView.fints.syncFromDate')"
                        type="date"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-btn
                        v-if="fintsForm.existing"
                        color="error"
                        variant="text"
                        @click="removeFintsConfig"
                    >
                        {{ t('BankingView.fints.delete') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn variant="text" @click="showFintsDialog = false">
                        {{ t('BankingView.sync.cancel') }}
                    </v-btn>
                    <v-btn color="primary" variant="tonal" @click="saveFintsSetup">
                        {{ t('BankingView.fints.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Sync Dialog (PIN-Eingabe) -->
        <v-dialog v-model="showSyncDialog" max-width="450" persistent>
            <v-card>
                <v-card-title>{{ t('BankingView.sync.title') }}</v-card-title>
                <v-card-text>
                    <div class="text-body-2 mb-3 text-medium-emphasis">
                        {{ syncAccount?.name }} — {{ formatIban(syncAccount?.iban) }}
                    </div>
                    <v-text-field
                        v-model="syncPin"
                        :label="t('BankingView.sync.pin')"
                        :hint="t('BankingView.sync.pinHint')"
                        persistent-hint
                        type="password"
                        autocomplete="off"
                        class="mb-3"
                        @keyup.enter="startSync"
                    />
                    <div class="d-flex align-center">
                        <v-checkbox
                            v-model="rememberPin"
                            :label="t('BankingView.sync.rememberPin')"
                            density="compact"
                            hide-details
                        />
                        <v-btn
                            v-if="syncAccount?.has_saved_pin"
                            size="x-small"
                            variant="text"
                            color="error"
                            class="ml-2"
                            @click="banking.deleteBankingPin(syncAccount.id).then(() => { banking.fetchAccounts(); alerts.info(t('BankingView.sync.pinDeleted')) }).catch(e => alerts.error(e.message))"
                        >{{ t('BankingView.sync.deletePin') }}</v-btn>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="closeSyncDialog">
                        {{ t('BankingView.sync.cancel') }}
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="tonal"
                        :loading="banking.loading.value"
                        @click="startSync"
                    >
                        {{ t('BankingView.sync.start') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- TAN Dialog -->
        <v-dialog v-model="showTanDialog" max-width="450" persistent>
            <v-card>
                <v-card-title>{{ t('BankingView.tan.title') }}</v-card-title>
                <v-card-text>
                    <div class="mb-3">
                        <div class="text-caption text-medium-emphasis">{{ t('BankingView.tan.tanMedium') }}</div>
                        <div class="text-body-1">{{ banking.tanChallenge.tanMedium }}</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-caption text-medium-emphasis">{{ t('BankingView.tan.challenge') }}</div>
                        <div class="text-body-1">{{ banking.tanChallenge.challenge }}</div>
                    </div>
                    <!-- Decoupled (pushTAN 2.0 etc.): Bank sendet Freigabe-Request in die App, keine TAN einzugeben -->
                    <v-alert v-if="banking.tanChallenge.decoupled" type="info" variant="tonal" density="compact" class="mb-0">
                        {{ banking.tanChallenge.message || t('BankingView.tan.decoupledHint') }}
                    </v-alert>
                    <v-text-field
                        v-else
                        v-model="tanInput"
                        :label="t('BankingView.tan.tanInput')"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        @keyup.enter="submitTanAction"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showTanDialog = false">
                        {{ t('BankingView.tan.cancel') }}
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="tonal"
                        :loading="banking.loading.value"
                        @click="submitTanAction"
                    >
                        {{ banking.tanChallenge.decoupled ? t('BankingView.tan.continue') : t('BankingView.tan.submit') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Loading -->
        <v-overlay :model-value="banking.loading.value && accounts.length === 0" class="align-center justify-center">
            <v-progress-circular indeterminate size="64" />
        </v-overlay>
    </v-container>
</template>

<script setup>
import { ref, onMounted, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useBanking } from '../composables/useBanking.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const router = useRouter()
const banking = useBanking()

const accounts = banking.accounts

// FinTS Setup
const showFintsDialog = ref(false)
const fintsForm = reactive({
    bank_account_id: null,
    fints_url: '',
    fints_bank_code: '',
    fints_username: '',
    fints_tan_mode: '',
    sync_from_date: '',
    existing: false
})
const fintsBankName = ref('')

// Standard-FinTS-TAN-Verfahren (siehe HITANS-Segment in der FinTS-Spezifikation)
const tanModeOptions = [
    { value: '900', title: '900 — iTAN' },
    { value: '910', title: '910 — chipTAN manuell' },
    { value: '911', title: '911 — chipTAN optisch (Flicker)' },
    { value: '912', title: '912 — chipTAN USB' },
    { value: '913', title: '913 — chipTAN QR' },
    { value: '920', title: '920 — smsTAN' },
    { value: '921', title: '921 — pushTAN' },
    { value: '942', title: '942 — mobileTAN / smartTAN' }
]

// Sync
const showSyncDialog = ref(false)
const syncAccount = ref(null)
const syncPin = ref('')
const rememberPin = ref(true)


// TAN
const showTanDialog = ref(false)
const tanInput = ref('')

onMounted(async () => {
    await banking.fetchAccounts()
})

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function formatIban(iban) {
    if (!iban) return '—'
    return iban.replace(/(.{4})/g, '$1 ').trim()
}

function formatDate(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function balanceColor(balance) {
    if (parseFloat(balance) >= 0) return 'text-success'
    return 'text-error'
}

function viewTransactions(accountId) {
    router.push({ name: 'banking-transactions', params: { id: accountId } })
}

function newTransferFor(accountId) {
    router.push({ name: 'banking-transfers', query: { new_for: accountId } })
}

async function openFintsSetup(account) {
    fintsForm.bank_account_id = account.id
    fintsForm.fints_bank_code = account.bank_code || ''
    fintsForm.fints_url = ''
    fintsForm.fints_username = ''
    fintsForm.fints_tan_mode = ''
    fintsForm.sync_from_date = ''
    fintsForm.existing = false
    fintsBankName.value = ''

    // Versuche bestehende Config zu laden
    const config = await banking.fetchFintsConfig(account.id)
    if (config) {
        fintsForm.fints_url = config.fints_url || ''
        fintsForm.fints_bank_code = config.fints_bank_code || ''
        fintsForm.fints_username = config.fints_username || ''
        fintsForm.fints_tan_mode = config.fints_tan_mode || ''
        fintsForm.sync_from_date = config.sync_from_date || ''
        fintsForm.existing = true
    }

    // URL automatisch aus BLZ vorbefuellen, wenn noch nicht gesetzt
    if (!fintsForm.fints_url && fintsForm.fints_bank_code) {
        await autofillFintsUrl(fintsForm.fints_bank_code)
    }

    showFintsDialog.value = true
}

async function autofillFintsUrl(bankCode) {
    const info = await banking.fetchFintsUrlByBlz(bankCode)
    if (info?.url) {
        fintsForm.fints_url = info.url
        fintsBankName.value = info.name || ''
    } else {
        fintsBankName.value = ''
    }
}

function onBankCodeChange(newBlz) {
    // Nur befuellen wenn URL-Feld leer ist — User-Eingaben nicht ueberschreiben
    if (!fintsForm.fints_url && newBlz) {
        autofillFintsUrl(newBlz)
    }
}

async function saveFintsSetup() {
    try {
        await banking.saveFintsConfig(fintsForm)
        showFintsDialog.value = false
        alerts.success(t('BankingView.alerts.fintsConfigSaved'))
        await banking.fetchAccounts()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function removeFintsConfig() {
    try {
        await banking.deleteFintsConfig(fintsForm.bank_account_id)
        showFintsDialog.value = false
        alerts.success(t('BankingView.alerts.fintsConfigDeleted'))
        await banking.fetchAccounts()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function openSyncDialog(account) {
    syncAccount.value = account
    if (account.has_saved_pin) {
        try {
            const pin = await banking.loadBankingPin(account.id)
            if (pin) {
                syncPin.value = pin
                rememberPin.value = true
                runSync()
                return
            }
        } catch { /* Fallback auf Dialog */ }
    }
    syncPin.value = ''
    rememberPin.value = true
    showSyncDialog.value = true
}

function closeSyncDialog() {
    syncPin.value = ''
    showSyncDialog.value = false
}

// Eigentliche Sync-Logik — nutzt den aktuellen syncPin.value
async function runSync() {
    if (!syncPin.value) return
    try {
        const result = await banking.syncTransactions(
            syncAccount.value.id,
            syncPin.value,
            null, // from_date: Backend nutzt letzter Sync / -30 Tage
            null  // to_date:   Backend nutzt heute
        )

        if (result.tanRequired) {
            showSyncDialog.value = false
            tanInput.value = ''
            showTanDialog.value = true
            if (banking.tanChallenge.decoupled) {
                startDecoupledPolling()
            }
        } else {
            if (rememberPin.value) {
                banking.saveBankingPin(syncAccount.value.id, syncPin.value).catch(() => {})
            }
            syncPin.value = ''
            showSyncDialog.value = false
            if (result.importedCount > 0) {
                alerts.success(t('BankingView.alerts.syncSuccess', { count: result.importedCount }))
            } else {
                alerts.info(t('BankingView.alerts.syncNoNew'))
            }
            await banking.fetchAccounts()
        }
    } catch (e) {
        alerts.error(e.message)
    }
}

function startSync() {
    return runSync()
}

let decoupledPollTimer = null

function stopDecoupledPolling() {
    if (decoupledPollTimer) {
        clearTimeout(decoupledPollTimer)
        decoupledPollTimer = null
    }
}

// Pollt alle 2 Sekunden ob die Bank-App die Freigabe schon bestaetigt hat.
// Stoppt bei Erfolg, Fehler oder wenn der Dialog geschlossen wird.
function startDecoupledPolling() {
    stopDecoupledPolling()
    const tick = async () => {
        if (!showTanDialog.value) { stopDecoupledPolling(); return }
        try {
            const result = await banking.submitTan(syncAccount.value.id, '', syncPin.value)
            if (result.tanRequired) {
                // Noch nicht bestaetigt — in 2s erneut probieren
                decoupledPollTimer = setTimeout(tick, 2000)
                return
            }
            stopDecoupledPolling()
            if (rememberPin.value) {
                banking.saveBankingPin(syncAccount.value.id, syncPin.value).catch(() => {})
            }
            showTanDialog.value = false
            syncPin.value = ''
            tanInput.value = ''
            if (result.importedCount > 0) {
                alerts.success(t('BankingView.alerts.syncSuccess', { count: result.importedCount }))
            } else {
                alerts.info(t('BankingView.alerts.syncNoNew'))
            }
            await banking.fetchAccounts()
        } catch (e) {
            stopDecoupledPolling()
            alerts.error(e.message)
        }
    }
    decoupledPollTimer = setTimeout(tick, 2000)
}

// Dialog-Close stoppt das Polling
watch(showTanDialog, (v) => { if (!v) stopDecoupledPolling() })

async function submitTanAction() {
    // Bei decoupled (pushTAN 2.0) wird kein TAN eingegeben, sondern die
    // Freigabe in der Banking-App gemacht — hier nur "Fortfahren"
    const isDecoupled = banking.tanChallenge.decoupled
    if (!isDecoupled && !tanInput.value) return
    stopDecoupledPolling()

    try {
        const result = await banking.submitTan(
            syncAccount.value.id,
            isDecoupled ? '' : tanInput.value,
            syncPin.value
        )

        // Decoupled: wenn Bank "noch nicht bestaetigt" meldet, Polling neu starten
        if (result.tanRequired) {
            if (isDecoupled) startDecoupledPolling()
            return
        }

        if (rememberPin.value) {
            banking.saveBankingPin(syncAccount.value.id, syncPin.value).catch(() => {})
        }
        showTanDialog.value = false
        syncPin.value = ''
        tanInput.value = ''

        if (result.importedCount > 0) {
            alerts.success(t('BankingView.alerts.syncSuccess', { count: result.importedCount }))
        } else {
            alerts.info(t('BankingView.alerts.syncNoNew'))
        }
        await banking.fetchAccounts()
    } catch (e) {
        alerts.error(e.message)
    }
}
</script>

<style scoped>
.banking-account-card {
    transition: transform 0.15s ease;
}
.banking-account-card:hover {
    transform: translateY(-2px);
}
</style>
