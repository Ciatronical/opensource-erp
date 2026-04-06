<template>
    <NavbarView />
    <v-container fluid>
        <v-row align="center" class="mb-4">
            <v-col>
                <h1 class="text-h5">{{ t('BankingView.transfers.title') }}</h1>
            </v-col>
            <v-col cols="auto">
                <v-btn color="primary" variant="tonal" @click="openNewTransfer">
                    <v-icon start>mdi-plus</v-icon>
                    {{ t('BankingView.transfers.newTransfer') }}
                </v-btn>
            </v-col>
        </v-row>

        <!-- Ueberweisungsliste -->
        <v-card>
            <v-data-table
                :headers="headers"
                :items="transfers.transferOrders.value"
                :loading="transfers.loading.value"
                :items-per-page="50"
                density="compact"
                hover
            >
                <template #item.amount="{ item }">
                    <span class="font-weight-medium">{{ formatCurrency(item.amount) }}</span>
                </template>

                <template #item.remote_name="{ item }">
                    <div>
                        <div class="font-weight-medium">{{ item.remote_name }}</div>
                        <div class="text-caption text-medium-emphasis">{{ formatIban(item.remote_iban) }}</div>
                    </div>
                </template>

                <template #item.account_name="{ item }">
                    <div class="text-body-2">{{ item.account_name }}</div>
                </template>

                <template #item.status="{ item }">
                    <v-chip :color="transferStatusColor(item.status)" size="small" variant="tonal">
                        {{ t('BankingView.transfers.status' + capitalize(item.status)) }}
                    </v-chip>
                </template>

                <template #item.source_invnumber="{ item }">
                    <span v-if="item.source_invnumber" class="text-caption">
                        {{ item.source_invnumber }}
                    </span>
                </template>

                <template #item.itime="{ item }">
                    {{ formatDateShort(item.itime) }}
                </template>

                <template #item.actions="{ item }">
                    <div class="d-flex ga-1">
                        <v-btn
                            v-if="item.status === 'draft'"
                            icon="mdi-pencil"
                            size="x-small"
                            variant="text"
                            @click="editTransfer(item)"
                        />
                        <v-btn
                            v-if="item.status === 'draft'"
                            icon="mdi-send"
                            size="x-small"
                            variant="text"
                            color="primary"
                            :title="t('BankingView.transfers.submit')"
                            @click="confirmSubmitTransfer(item)"
                        />
                        <v-btn
                            v-if="item.status === 'draft'"
                            icon="mdi-delete"
                            size="x-small"
                            variant="text"
                            color="error"
                            @click="confirmDeleteTransfer(item)"
                        />
                    </div>
                </template>

                <template #no-data>
                    <div class="text-center pa-4 text-medium-emphasis">
                        {{ t('BankingView.transfers.noOrders') }}
                    </div>
                </template>
            </v-data-table>
        </v-card>

        <!-- Neue/Bearbeiten Ueberweisung Dialog -->
        <v-dialog v-model="showTransferDialog" max-width="600" persistent>
            <v-card>
                <v-card-title>{{ t('BankingView.transfers.newTransfer') }}</v-card-title>
                <v-card-text>
                    <v-select
                        v-model="transferForm.bank_account_id"
                        :items="bankingComposable.accounts.value"
                        item-title="name"
                        item-value="id"
                        :label="t('BankingView.transfers.fromAccount')"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="transferForm.remote_name"
                        :label="t('BankingView.transfers.recipientName')"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="transferForm.remote_iban"
                        :label="t('BankingView.transfers.recipientIban')"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="transferForm.remote_bic"
                        :label="t('BankingView.transfers.recipientBic')"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model.number="transferForm.amount"
                        :label="t('BankingView.transfers.amount')"
                        type="number"
                        min="0.01"
                        step="0.01"
                        prefix="EUR"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="transferForm.purpose"
                        :label="t('BankingView.transfers.purpose')"
                        :hint="t('BankingView.transfers.purposeHint')"
                        persistent-hint
                        counter="140"
                        maxlength="140"
                        class="mb-3"
                    />
                    <v-text-field
                        v-model="transferForm.execution_date"
                        :label="t('BankingView.transfers.executionDate')"
                        type="date"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showTransferDialog = false">
                        {{ t('BankingView.sync.cancel') }}
                    </v-btn>
                    <v-btn color="primary" variant="tonal" @click="saveTransfer">
                        {{ t('BankingView.transfers.save') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- PIN-Eingabe fuer Ueberweisung -->
        <v-dialog v-model="showPinDialog" max-width="450" persistent>
            <v-card>
                <v-card-title>{{ t('BankingView.transfers.submit') }}</v-card-title>
                <v-card-text>
                    <div class="mb-3 text-body-2">
                        <strong>{{ submitTarget?.remote_name }}</strong> — {{ formatCurrency(submitTarget?.amount) }}
                    </div>
                    <v-text-field
                        v-model="submitPin"
                        :label="t('BankingView.sync.pin')"
                        :hint="t('BankingView.sync.pinHint')"
                        persistent-hint
                        type="password"
                        autocomplete="off"
                        @keyup.enter="executeSubmitTransfer"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="closePinDialog">
                        {{ t('BankingView.sync.cancel') }}
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="tonal"
                        :loading="transfers.loading.value"
                        @click="executeSubmitTransfer"
                    >
                        {{ t('BankingView.transfers.submit') }}
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
                        <div>{{ transfers.tanChallenge.tanMedium }}</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-caption text-medium-emphasis">{{ t('BankingView.tan.challenge') }}</div>
                        <div>{{ transfers.tanChallenge.challenge }}</div>
                    </div>
                    <v-text-field
                        v-model="tanInput"
                        :label="t('BankingView.tan.tanInput')"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        @keyup.enter="submitTanForTransfer"
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
                        :loading="transfers.loading.value"
                        @click="submitTanForTransfer"
                    >
                        {{ t('BankingView.tan.submit') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBanking } from '../composables/useBanking.js'
import { useTransfers } from '../composables/useTransfers.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'
import Swal from 'sweetalert2'

const { t } = useI18n()
const bankingComposable = useBanking()
const transfers = useTransfers()

// Transfer form
const showTransferDialog = ref(false)
const editingId = ref(null)
const transferForm = reactive({
    bank_account_id: null,
    remote_name: '',
    remote_iban: '',
    remote_bic: '',
    amount: null,
    purpose: '',
    execution_date: ''
})

// Submit
const showPinDialog = ref(false)
const submitTarget = ref(null)
const submitPin = ref('')

// TAN
const showTanDialog = ref(false)
const tanInput = ref('')

const headers = computed(() => [
    { title: t('BankingView.transfers.status'), key: 'status', width: '120px' },
    { title: t('BankingView.transfers.recipient'), key: 'remote_name' },
    { title: t('BankingView.transfers.amount'), key: 'amount', width: '120px', align: 'end' },
    { title: t('BankingView.transfers.purpose'), key: 'purpose' },
    { title: t('BankingView.transfers.fromAccount'), key: 'account_name', width: '150px' },
    { title: t('BankingView.transfers.sourceInvoice'), key: 'source_invnumber', width: '120px' },
    { title: '', key: 'itime', width: '100px' },
    { title: '', key: 'actions', width: '120px', sortable: false }
])

onMounted(async () => {
    await Promise.all([
        transfers.fetchTransferOrders(),
        bankingComposable.fetchAccounts()
    ])
})

function openNewTransfer() {
    editingId.value = null
    transferForm.bank_account_id = bankingComposable.accounts.value[0]?.id || null
    transferForm.remote_name = ''
    transferForm.remote_iban = ''
    transferForm.remote_bic = ''
    transferForm.amount = null
    transferForm.purpose = ''
    transferForm.execution_date = ''
    showTransferDialog.value = true
}

function editTransfer(item) {
    editingId.value = item.id
    transferForm.bank_account_id = item.bank_account_id
    transferForm.remote_name = item.remote_name
    transferForm.remote_iban = item.remote_iban
    transferForm.remote_bic = item.remote_bic || ''
    transferForm.amount = parseFloat(item.amount)
    transferForm.purpose = item.purpose
    transferForm.execution_date = item.execution_date || ''
    showTransferDialog.value = true
}

async function saveTransfer() {
    try {
        if (editingId.value) {
            await transfers.updateTransfer({ id: editingId.value, ...transferForm })
        } else {
            await transfers.createTransfer(transferForm)
        }
        showTransferDialog.value = false
        alerts.success(t('BankingView.alerts.transferCreated'))
        await transfers.fetchTransferOrders()
    } catch (e) {
        alerts.error(e.message)
    }
}

async function confirmDeleteTransfer(item) {
    const result = await Swal.fire({
        title: t('BankingView.transfers.confirmDelete'),
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33'
    })
    if (result.isConfirmed) {
        try {
            await transfers.deleteTransfer(item.id)
            alerts.success(t('BankingView.alerts.transferDeleted'))
            await transfers.fetchTransferOrders()
        } catch (e) {
            alerts.error(e.message)
        }
    }
}

function confirmSubmitTransfer(item) {
    submitTarget.value = item
    submitPin.value = ''
    showPinDialog.value = true
}

function closePinDialog() {
    submitPin.value = ''
    showPinDialog.value = false
}

async function executeSubmitTransfer() {
    if (!submitPin.value) return

    try {
        const result = await transfers.submitTransfer(submitTarget.value.id, submitPin.value)

        if (result.tanRequired) {
            showPinDialog.value = false
            tanInput.value = ''
            showTanDialog.value = true
        } else {
            submitPin.value = ''
            showPinDialog.value = false
            alerts.success(t('BankingView.alerts.transferSubmitted'))
            await transfers.fetchTransferOrders()
        }
    } catch (e) {
        alerts.error(e.message)
    }
}

async function submitTanForTransfer() {
    if (!tanInput.value) return

    try {
        await transfers.submitTransferTan(submitTarget.value.id, tanInput.value, submitPin.value)
        showTanDialog.value = false
        submitPin.value = ''
        tanInput.value = ''
        alerts.success(t('BankingView.alerts.transferSubmitted'))
        await transfers.fetchTransferOrders()
    } catch (e) {
        alerts.error(e.message)
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
}

function formatIban(iban) {
    if (!iban) return ''
    return iban.replace(/(.{4})/g, '$1 ').trim()
}

function formatDateShort(dateStr) {
    if (!dateStr) return '—'
    return new Date(dateStr).toLocaleDateString('de-DE')
}

function capitalize(str) {
    if (!str) return ''
    // snake_case -> PascalCase
    return str.split('_').map(s => s.charAt(0).toUpperCase() + s.slice(1)).join('')
}

function transferStatusColor(status) {
    const colors = {
        draft: 'default', pending_tan: 'warning', submitted: 'info',
        executed: 'success', rejected: 'error', cancelled: 'default'
    }
    return colors[status] || 'default'
}
</script>
