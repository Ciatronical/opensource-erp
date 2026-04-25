<template>
    <div>
        <v-autocomplete
            :model-value="modelValue"
            :items="displayedItems"
            :loading="loading"
            :search="searchInput"
            :label="t('BankingView.transfers.recipientLookup')"
            :placeholder="t('BankingView.transfers.recipientLookupHint')"
            :no-filter="true"
            :return-object="true"
            item-title="name"
            item-value="autocomplete_key"
            density="comfortable"
            variant="outlined"
            clearable
            hide-no-data
            auto-select-first
            @update:search="onSearch"
            @update:model-value="onPick"
        >
            <template #item="{ props: itemProps, item }">
                <v-list-item v-if="item.raw.is_create_action" v-bind="itemProps" :title="undefined" prepend-icon="mdi-plus-circle">
                    <v-list-item-title>
                        {{ t('BankingView.transfers.createRecipient', { name: item.raw.create_name }) }}
                    </v-list-item-title>
                </v-list-item>
                <v-list-item v-else v-bind="itemProps" :title="undefined">
                    <template #prepend>
                        <v-chip
                            size="x-small"
                            :color="item.raw.recipient_type === 'customer' ? 'primary' : 'secondary'"
                            variant="tonal"
                            class="mr-2"
                        >
                            {{ item.raw.recipient_type === 'customer' ? t('BankingView.transfers.customerShort') : t('BankingView.transfers.vendorShort') }}
                        </v-chip>
                    </template>
                    <v-list-item-title>
                        {{ item.raw.name }}
                        <span class="text-caption text-medium-emphasis ml-2">{{ item.raw.number }}</span>
                    </v-list-item-title>
                    <v-list-item-subtitle v-if="item.raw.iban">
                        {{ formatIban(item.raw.iban) }}
                    </v-list-item-subtitle>
                    <v-list-item-subtitle v-else class="text-warning">
                        {{ t('BankingView.transfers.noIbanOnFile') }}
                    </v-list-item-subtitle>
                </v-list-item>
            </template>
        </v-autocomplete>

        <!-- Schnell-Anlage neuer Kunde/Lieferant -->
        <v-dialog v-model="showCreateDialog" max-width="500" persistent>
            <v-card>
                <v-card-title>{{ t('BankingView.transfers.createRecipientTitle') }}</v-card-title>
                <v-card-text>
                    <v-radio-group
                        v-model="createForm.type"
                        inline
                        :label="t('BankingView.transfers.recipientType')"
                        density="compact"
                    >
                        <v-radio :label="t('BankingView.transfers.typeCustomer')" value="customer" />
                        <v-radio :label="t('BankingView.transfers.typeVendor')" value="vendor" />
                    </v-radio-group>
                    <v-text-field
                        v-model="createForm.name"
                        :label="t('BankingView.transfers.recipientName')"
                        autofocus
                        class="mb-2"
                    />
                    <v-row dense>
                        <v-col cols="8">
                            <v-text-field v-model="createForm.street" :label="t('BankingView.transfers.street')" density="compact" />
                        </v-col>
                        <v-col cols="4">
                            <v-text-field v-model="createForm.zipcode" :label="t('BankingView.transfers.zipcode')" density="compact" />
                        </v-col>
                    </v-row>
                    <v-row dense>
                        <v-col cols="8">
                            <v-text-field v-model="createForm.city" :label="t('BankingView.transfers.city')" density="compact" />
                        </v-col>
                        <v-col cols="4">
                            <v-text-field v-model="createForm.country" :label="t('BankingView.transfers.country')" density="compact" />
                        </v-col>
                    </v-row>
                    <v-text-field v-model="createForm.email" :label="t('BankingView.transfers.email')" density="compact" />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="showCreateDialog = false">
                        {{ t('BankingView.sync.cancel') }}
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="tonal"
                        :loading="creating"
                        :disabled="!createForm.name"
                        @click="confirmCreate"
                    >
                        {{ t('BankingView.transfers.create') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTransfers } from '../composables/useTransfers.js'
import { formatIban as formatIbanUtil } from '@/core/utils/iban.js'
import * as alerts from '@/core/utils/alerts.js'

const { t } = useI18n()
const transfers = useTransfers()

const props = defineProps({
    modelValue: { type: Object, default: null }
})
const emit = defineEmits(['update:modelValue'])

const loading = ref(false)
const items = ref([])
const searchInput = ref('')
let searchTimer = null

// Wenn der User tippt aber noch nichts ausgewaehlt ist UND kein Treffer in der
// Liste ist, fuegen wir einen "Neu anlegen"-Eintrag hinzu — aber nur wenn der
// Suchstring keine reine IBAN/Nummer ist (mind. 2 Zeichen, mind. 1 Buchstabe).
const displayedItems = computed(() => {
    const list = items.value.map(r => ({ ...r, autocomplete_key: `${r.recipient_type}-${r.id}` }))
    const q = (searchInput.value || '').trim()
    if (q.length >= 2 && /\p{L}/u.test(q)) {
        list.push({
            autocomplete_key: '__create__',
            is_create_action: true,
            create_name: q,
            name: q
        })
    }
    return list
})

function formatIban(value) {
    return formatIbanUtil(value)
}

function onSearch(text) {
    searchInput.value = text || ''
    if (searchTimer) clearTimeout(searchTimer)
    const q = (text || '').trim()
    if (q.length < 2) {
        items.value = []
        return
    }
    searchTimer = setTimeout(async () => {
        loading.value = true
        try {
            items.value = await transfers.searchRecipient(q)
        } catch (e) {
            alerts.error(e.message)
        } finally {
            loading.value = false
        }
    }, 200)
}

function onPick(picked) {
    if (!picked) {
        emit('update:modelValue', null)
        return
    }
    if (picked.is_create_action) {
        // Auswahl darf nicht "kleben" bleiben — wir oeffnen den Anlage-Dialog
        emit('update:modelValue', null)
        openCreateDialog(picked.create_name)
        return
    }
    emit('update:modelValue', picked)
}

// ── Schnell-Anlage ──────────────────────────────────────────────────────────

const showCreateDialog = ref(false)
const creating = ref(false)
const createForm = reactive({
    type: 'vendor',
    name: '',
    street: '',
    zipcode: '',
    city: '',
    country: 'DE',
    email: ''
})

function openCreateDialog(prefilledName) {
    createForm.type = 'vendor'
    createForm.name = prefilledName || ''
    createForm.street = ''
    createForm.zipcode = ''
    createForm.city = ''
    createForm.country = 'DE'
    createForm.email = ''
    showCreateDialog.value = true
}

async function confirmCreate() {
    creating.value = true
    try {
        const created = await transfers.createRecipient({
            type: createForm.type,
            name: createForm.name,
            street: createForm.street,
            zipcode: createForm.zipcode,
            city: createForm.city,
            country: createForm.country,
            email: createForm.email
        })
        showCreateDialog.value = false
        const picked = {
            recipient_type: created.recipient_type,
            id: created.id,
            name: created.name,
            number: created.number,
            iban: created.iban || '',
            bic: created.bic || '',
            bank: '',
            bank_code: '',
            autocomplete_key: `${created.recipient_type}-${created.id}`
        }
        items.value = [picked]
        searchInput.value = ''
        emit('update:modelValue', picked)
    } catch (e) {
        alerts.error(e.message)
    } finally {
        creating.value = false
    }
}

// Modell-Reset von außen mitbekommen
watch(() => props.modelValue, (v) => {
    if (!v) {
        items.value = []
        searchInput.value = ''
    }
})
</script>
