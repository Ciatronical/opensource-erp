<!-- src/components/contactview/contact.view.vue -->

<template>
    <v-list density="compact" class="pa-0">
        <v-list-subheader class="text-uppercase font-weight-bold">{{ t('CrmView.address') }}</v-list-subheader>
        <v-list-item v-if="cvProfile?.greeting || cvProfile?.name">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-account</v-icon>
            </template>
            <v-list-item-title class="font-weight-medium">{{ [cvProfile.greeting, cvProfile.name].filter(Boolean).join(' ') }}</v-list-item-title>
        </v-list-item>
        <v-list-item v-if="oserpData.customer_vendor?.profile?.street">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-road-variant</v-icon>
            </template>
            <v-list-item-title class="font-weight-medium">{{ oserpData.customer_vendor.profile.street }}</v-list-item-title>
        </v-list-item>
        <v-list-item v-if="oserpData.customer_vendor?.profile?.zipcode || oserpData.customer_vendor?.profile?.city">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-city</v-icon>
            </template>
            <v-list-item-title>{{ oserpData.customer_vendor.profile.zipcode }} {{ oserpData.customer_vendor.profile.city }}</v-list-item-title>
        </v-list-item>
        <v-list-item v-if="oserpData.customer_vendor?.profile?.country">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-flag</v-icon>
            </template>
            <v-list-item-title>{{ oserpData.customer_vendor.profile.country }}</v-list-item-title>
        </v-list-item>
        <v-list-item v-if="hasAddress">
            <v-btn
                size="small"
                variant="tonal"
                color="green"
                :title="t('CrmView.sendAddressWhatsApp')"
                @click="showRecipientDialog = true"
            >
                <v-icon start size="small">mdi-whatsapp</v-icon>
                {{ t('CrmView.sendAddressWhatsApp') }}
            </v-btn>
        </v-list-item>
    </v-list>

    <!-- Empfänger-Auswahl Dialog -->
    <v-dialog v-model="showRecipientDialog" max-width="500" persistent>
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon start color="green">mdi-whatsapp</v-icon>
                {{ t('CrmView.selectRecipient') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="x-small" variant="text" @click="closeRecipientDialog" />
            </v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <!-- Adresse die gesendet wird -->
                <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                    <div class="text-caption font-weight-bold">{{ t('CrmView.addressToSend') }}</div>
                    <div>{{ addressDisplay }}</div>
                </v-alert>

                <!-- Typ-Auswahl -->
                <v-btn-toggle v-model="recipientType" mandatory density="compact" class="mb-3">
                    <v-btn value="customer" size="small">{{ t('CrmView.customer') }}</v-btn>
                    <v-btn value="vendor" size="small">{{ t('CrmView.vendor') }}</v-btn>
                </v-btn-toggle>

                <!-- Empfänger suchen -->
                <v-autocomplete
                    v-model="selectedRecipient"
                    v-model:search="recipientSearch"
                    :items="recipientItems"
                    :loading="recipientLoading"
                    item-title="name"
                    item-value="id"
                    return-object
                    :no-filter="true"
                    :label="t('CrmView.searchRecipient')"
                    :placeholder="t('CrmView.searchRecipientHint')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    autocomplete="off"
                    prepend-inner-icon="mdi-magnify"
                >
                    <template #item="{ item, props: itemProps }">
                        <v-list-item v-bind="itemProps">
                            <template #subtitle>
                                <span v-if="item.raw.phone" class="text-caption">
                                    <v-icon size="x-small">mdi-phone</v-icon> {{ item.raw.phone }}
                                </span>
                                <span v-else class="text-caption text-error">
                                    {{ t('CrmView.noPhone') }}
                                </span>
                            </template>
                        </v-list-item>
                    </template>
                </v-autocomplete>

                <!-- Gewählter Empfänger Info -->
                <v-alert
                    v-if="selectedRecipient && !selectedRecipient.phone"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                >
                    {{ t('CrmView.recipientNoPhone') }}
                </v-alert>
            </v-card-text>
            <v-divider />
            <v-card-actions class="pa-3">
                <v-btn variant="text" @click="closeRecipientDialog">
                    {{ t('CrmView.cancel') }}
                </v-btn>
                <v-spacer />
                <v-btn
                    color="green"
                    variant="flat"
                    :loading="sendingAddress"
                    :disabled="!selectedRecipient || !selectedRecipient.phone"
                    prepend-icon="mdi-whatsapp"
                    @click="sendAddressWhatsApp"
                >
                    {{ t('CrmView.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>

    <v-divider class="my-2" />

    <v-list density="compact" class="pa-0">
        <v-list-subheader class="text-uppercase font-weight-bold">{{ t('CrmView.reachability') }}</v-list-subheader>
        <v-list-item v-if="cvProfile?.phone">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-phone</v-icon>
            </template>
            <v-list-item-title>
                <PhoneActionBar
                    :phone="cvProfile.phone"
                    :name="cvProfile.name || ''"
                    whatsapp-tab
                    @whatsapp="switchToWhatsAppTab"
                />
            </v-list-item-title>
            <v-list-item-subtitle>{{ t('CrmView.phone') }}</v-list-item-subtitle>
        </v-list-item>
        <v-list-item v-for="(entry, idx) in phoneNumbers" :key="'pn-' + idx">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-phone</v-icon>
            </template>
            <v-list-item-title>
                <PhoneActionBar
                    :phone="entry.number"
                    :name="cvProfile?.name || ''"
                    whatsapp-tab
                    @whatsapp="switchToWhatsAppTab"
                />
            </v-list-item-title>
            <v-list-item-subtitle>{{ entry.label || t('CrmView.phone') }}</v-list-item-subtitle>
        </v-list-item>
        <v-list-item v-if="cvProfile?.fax">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-fax</v-icon>
            </template>
            <v-list-item-title>{{ formatPhone(cvProfile.fax) }}</v-list-item-title>
            <v-list-item-subtitle>{{ t('CrmView.fax') }}</v-list-item-subtitle>
        </v-list-item>
        <v-list-item v-if="cvProfile?.email">
            <template #prepend>
                <v-icon size="small" color="grey-darken-1">mdi-email</v-icon>
            </template>
            <v-list-item-title>
                <a :href="'mailto:' + cvProfile.email" class="text-decoration-none text-inherit">
                    {{ cvProfile.email }}
                </a>
            </v-list-item-title>
            <v-list-item-subtitle>{{ t('CrmView.email') }}</v-list-item-subtitle>
        </v-list-item>
    </v-list>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import PhoneActionBar from '@/core/components/phone-action-bar.vue'
import { formatPhone } from '@/core/utils/phoneFormat.js'
import { usePhoneActions } from '@/core/composables/usePhoneActions.js'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'

const { t } = useI18n();
const router = useRouter()
const route = useRoute()
const oserpData = oserpStore();
const cvProfile = computed(() => oserpData.customer_vendor?.profile);

function switchToWhatsAppTab(phone) {
    router.replace({ query: { ...route.query, tab: 'whatsapp', whatsappPhone: phone } })
}
const phoneNumbers = computed(() => cvProfile.value?.phone_numbers ?? []);
const { formatPhoneForWhatsApp } = usePhoneActions()

// Adress-Anzeige
const hasAddress = computed(() => {
    const p = cvProfile.value
    return p?.street || p?.zipcode || p?.city
})

const addressDisplay = computed(() => {
    const p = cvProfile.value
    return [p?.street, [p?.zipcode, p?.city].filter(Boolean).join(' '), p?.country].filter(Boolean).join(', ')
})

const countryMap = {
    'D': 'Deutschland', 'DE': 'Deutschland', 'DEU': 'Deutschland',
    'A': 'Österreich', 'AT': 'Österreich', 'AUT': 'Österreich',
    'CH': 'Schweiz', 'CHE': 'Schweiz',
    'NL': 'Niederlande', 'NLD': 'Niederlande',
    'BE': 'Belgien', 'BEL': 'Belgien',
    'FR': 'Frankreich', 'FRA': 'Frankreich',
    'IT': 'Italien', 'ITA': 'Italien',
    'PL': 'Polen', 'POL': 'Polen',
    'CZ': 'Tschechien', 'CZE': 'Tschechien',
    'DK': 'Dänemark', 'DNK': 'Dänemark',
    'LU': 'Luxemburg', 'LUX': 'Luxemburg',
}

function buildGoogleMapsUrl() {
    const p = cvProfile.value
    const country = p?.country ? (countryMap[p.country.toUpperCase()] || p.country) : ''
    const parts = [p?.street, p?.zipcode, p?.city, country].filter(Boolean)
    return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(parts.join(', '))
}

// Empfänger-Dialog
const showRecipientDialog = ref(false)
const recipientType = ref('customer')
const recipientSearch = ref('')
const recipientItems = ref([])
const recipientLoading = ref(false)
const selectedRecipient = ref(null)
const sendingAddress = ref(false)
let debounceTimer = null

function closeRecipientDialog() {
    showRecipientDialog.value = false
    recipientSearch.value = ''
    recipientItems.value = []
    selectedRecipient.value = null
}

watch(recipientSearch, (val) => {
    clearTimeout(debounceTimer)
    const search = (val || '').trim()
    if (search.length < 3) {
        recipientItems.value = []
        return
    }
    recipientLoading.value = true
    debounceTimer = setTimeout(async () => {
        try {
            const { data } = await axios.post('/api/faktura/', {
                action: 'searchFakturaCustomers',
                search,
                type: recipientType.value
            })
            recipientItems.value = (data?.results || []).map(r => ({
                id: r.id,
                name: r.name,
                phone: r.phone || r.cp_phone1 || ''
            }))
        } catch {
            recipientItems.value = []
        } finally {
            recipientLoading.value = false
        }
    }, 300)
})

// Typ-Wechsel: Suche zurücksetzen
watch(recipientType, () => {
    recipientSearch.value = ''
    recipientItems.value = []
    selectedRecipient.value = null
})

function buildPhoneList() {
    const p = cvProfile.value
    const parts = []

    if (p?.phone) {
        const note = p?.contact ? ` (${p.contact})` : ''
        parts.push(`tel:${p.phone}${note}`)
    }
    for (const entry of (phoneNumbers.value || [])) {
        const label = entry.label ? ` (${entry.label})` : ''
        parts.push(`tel:${entry.number}${label}`)
    }

    return parts.join(' | ')
}

async function sendAddressWhatsApp() {
    if (!selectedRecipient.value?.phone) return

    const phone = selectedRecipient.value.phone
    const recipientName = selectedRecipient.value.name || ''
    const mapsUrl = buildGoogleMapsUrl()
    const phoneList = buildPhoneList()
    const employeeName = oserpData.session?.logged_in_employee?.name || ''

    sendingAddress.value = true
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'sendWhatsAppAddressMessage',
            to: phone,
            customer_id: selectedRecipient.value.id || 0,
            customer_name: recipientName,
            maps_url: mapsUrl,
            phone_list: phoneList,
            employee_name: employeeName
        })
        if (resp.data?.success) {
            toast.success(t('CrmView.sendAddressWhatsApp') + ' — OK')
            closeRecipientDialog()
        } else {
            toast.error(resp.data?.payload || resp.data?.text || 'Fehler beim Senden')
        }
    } catch {
        // Fallback: WhatsApp Web
        const formatted = formatPhoneForWhatsApp(phone)
        if (!formatted) return
        const greeting = recipientName ? t('PhoneActions.whatsappGreeting', { name: recipientName }) + '\n\n' : ''
        const text = greeting + mapsUrl + (phoneList ? '\n\n' + phoneList : '')
        const url = 'https://web.whatsapp.com/send?phone=' + formatted
            + '&text=' + encodeURIComponent(text)
            + '&type=custom_url&app_absent=0'
        window.open(url, '_blank')
        closeRecipientDialog()
    } finally {
        sendingAddress.value = false
    }
}
</script>

<style scoped>
.text-inherit {
    color: inherit;
}
</style>
