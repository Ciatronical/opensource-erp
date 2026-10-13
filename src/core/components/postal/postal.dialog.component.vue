<!-- src/core/components/postal/postal.dialog.component.vue -->
<!--
    Postversand (Hybridbrief) für ein Dokument — Brief oder Beleg.

    Beim Öffnen wird das PDF im Backend erzeugt (preparePostal): daraus kommen
    Seitenzahl, Empfängeradresse, Inland/Ausland und beim LetterXpress-Konto
    der Preis. Der Benutzer sieht, was es kostet, bevor er einliefert.
    Bisherige Einlieferungen stehen mit Status, Sendungsverfolgung und
    Versandexemplar darunter.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="640" persistent scrollable @update:model-value="close">
        <v-card>
            <v-card-title class="d-flex align-center bg-deep-purple text-white">
                <v-icon class="mr-2">mdi-mailbox-up-outline</v-icon>
                {{ t('PostalDialog.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="close" />
            </v-card-title>

            <v-card-text class="pt-4">
                <div v-if="loading" class="text-center py-8">
                    <v-progress-circular indeterminate size="36" width="3" color="deep-purple" />
                    <div class="text-body-2 text-medium-emphasis mt-3">{{ t('PostalDialog.preparing') }}</div>
                </div>

                <template v-else-if="info">
                    <!-- Dokument und Empfänger -->
                    <div class="d-flex align-center flex-wrap ga-2 mb-3">
                        <v-chip size="small" variant="tonal" color="deep-purple" prepend-icon="mdi-file-document-outline">
                            {{ info.label }}
                        </v-chip>
                        <v-chip v-if="info.pages" size="small" variant="tonal" prepend-icon="mdi-file-multiple-outline">
                            {{ t('PostalDialog.pages', { n: info.pages }) }}
                        </v-chip>
                        <v-chip size="small" variant="tonal" :prepend-icon="info.shipping === 'international' ? 'mdi-earth' : 'mdi-home-map-marker'">
                            {{ info.shipping === 'international' ? t('PostalDialog.international') : t('PostalDialog.national') }}
                        </v-chip>
                    </div>

                    <v-alert
                        :type="info.address_ok ? 'info' : 'warning'"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                        :icon="info.address_ok ? 'mdi-account-arrow-right-outline' : 'mdi-map-marker-alert-outline'"
                    >
                        <div class="font-weight-medium">{{ info.recipient_name }}</div>
                        <div class="text-body-2">{{ info.recipient_address }}</div>
                        <div v-if="!info.address_ok" class="text-caption mt-1">{{ t('PostalDialog.addressIncomplete') }}</div>
                    </v-alert>

                    <!-- Anbieter -->
                    <v-alert
                        v-if="!info.provider || info.config_problem"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                        icon="mdi-cog-outline"
                    >
                        {{ info.provider ? t('PostalDialog.providerProblem', { problem: info.config_problem }) : t('PostalDialog.noProvider') }}
                        <a href="#" class="ms-1" @click.prevent="goToConfig">{{ t('PostalDialog.openConfig') }}</a>
                    </v-alert>
                    <div v-else class="d-flex align-center flex-wrap ga-2 mb-3">
                        <v-chip size="small" color="success" variant="tonal" prepend-icon="mdi-check-circle-outline">
                            {{ providerName }}
                        </v-chip>
                        <v-chip v-if="info.mode === 'test'" size="small" color="warning" variant="tonal" prepend-icon="mdi-flask-outline">
                            {{ t('PostalDialog.testMode') }}
                        </v-chip>
                        <v-chip v-if="info.balance" size="small" variant="tonal" prepend-icon="mdi-wallet-outline">
                            {{ t('PostalDialog.balance', { amount: formatMoney(info.balance.balance) }) }}
                        </v-chip>
                    </div>

                    <!-- Druckoptionen -->
                    <v-row dense class="mb-1">
                        <v-col cols="6" sm="3">
                            <v-switch v-model="color" color="deep-purple" density="compact" hide-details :label="t('PostalDialog.color')" />
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-switch v-model="duplex" color="deep-purple" density="compact" hide-details :label="t('PostalDialog.duplex')" />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-select
                                v-model="registered"
                                :items="registeredItems"
                                :label="t('PostalDialog.registered')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                :disabled="info.shipping === 'international'"
                            />
                        </v-col>
                    </v-row>
                    <div v-if="info.provider === 'pinag'" class="text-caption text-medium-emphasis mb-3">
                        {{ t('PostalDialog.pinagOptionsHint') }}
                    </div>

                    <!-- Preis -->
                    <div v-if="info.provider === 'letterxpress' && !info.config_problem" class="d-flex align-center mb-3">
                        <v-icon size="18" class="mr-1" color="deep-purple">mdi-cash</v-icon>
                        <span v-if="priceLoading" class="text-body-2 text-medium-emphasis">{{ t('PostalDialog.priceLoading') }}</span>
                        <span v-else-if="price !== null" class="text-body-2">
                            <span class="font-weight-medium">{{ t('PostalDialog.price', { amount: formatMoney(price) }) }}</span>
                            <span v-if="info.balance && price > info.balance.balance" class="text-error ms-2">{{ t('PostalDialog.balanceLow') }}</span>
                        </span>
                        <span v-else class="text-body-2 text-medium-emphasis">{{ t('PostalDialog.priceUnknown') }}</span>
                    </div>

                    <!-- Bisherige Einlieferungen -->
                    <template v-if="dispatches.length">
                        <v-divider class="mb-2" />
                        <div class="text-subtitle-2 mb-1">{{ t('PostalDialog.existing') }}</div>
                        <v-list density="compact" class="pa-0">
                            <v-list-item v-for="d in dispatches" :key="d.id" class="px-0">
                                <template #prepend>
                                    <v-icon :color="statusMeta(d.status).color" size="20">{{ statusMeta(d.status).icon }}</v-icon>
                                </template>
                                <v-list-item-title class="text-body-2">
                                    <span class="font-weight-medium">{{ t(statusMeta(d.status).label) }}</span>
                                    <span class="text-medium-emphasis"> · {{ formatWhen(d.sent_at) }}</span>
                                    <span v-if="d.employee_name" class="text-medium-emphasis"> · {{ d.employee_name }}</span>
                                </v-list-item-title>
                                <v-list-item-subtitle class="text-caption">
                                    {{ providerLabel(d.provider) }}
                                    <span v-if="d.registered"> · {{ d.registered === 'r2' ? t('PostalDialog.registeredR2') : t('PostalDialog.registeredR1') }}</span>
                                    <span v-if="d.price"> · {{ formatMoney(d.price) }}</span>
                                    <span v-if="d.tracking_code"> · {{ d.tracking_code }}</span>
                                    <span v-if="d.tracking_status"> · {{ d.tracking_status }}</span>
                                    <span v-if="d.error" class="text-error"> · {{ d.error }}</span>
                                </v-list-item-subtitle>
                                <template #append>
                                    <v-btn v-if="d.has_pdf" icon="mdi-file-pdf-box" size="x-small" variant="text" :title="t('PostalDialog.openPdf')" @click="openPdf(d)" />
                                    <v-btn v-if="d.provider === 'letterxpress' && d.status === 'sent'" icon="mdi-refresh" size="x-small" variant="text" :title="t('PostalDialog.refresh')" :loading="busyId === d.id" @click="refresh(d)" />
                                    <v-btn v-if="d.cancelable || ['error', 'manual', 'queued', 'canceled'].includes(d.status)" icon="mdi-delete-outline" size="x-small" variant="text" color="error" :title="d.cancelable ? t('PostalDialog.cancel') : t('PostalDialog.remove')" :loading="busyId === d.id" @click="cancel(d)" />
                                </template>
                            </v-list-item>
                        </v-list>
                    </template>
                </template>
            </v-card-text>

            <v-divider />
            <v-card-actions class="px-4 py-3 flex-wrap">
                <v-btn variant="text" @click="close">{{ t('PostalDialog.close') }}</v-btn>
                <v-spacer />
                <v-btn
                    variant="outlined"
                    color="deep-purple"
                    prepend-icon="mdi-printer"
                    :disabled="loading || !info"
                    :loading="sending === 'manual'"
                    @click="send('manual')"
                >
                    {{ t('PostalDialog.sendManual') }}
                </v-btn>
                <v-btn
                    color="deep-purple"
                    variant="elevated"
                    prepend-icon="mdi-mailbox-up-outline"
                    :disabled="loading || !info || !info.provider || !!info.config_problem || !info.address_ok"
                    :loading="sending === 'provider'"
                    @click="send('provider')"
                >
                    {{ t('PostalDialog.sendProvider', { provider: providerName }) }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import { openBase64Pdf } from '@/core/utils/download.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** Belegart: letter, invoice, order, quotation, delivery_order … */
    recordType: { type: String, required: true },
    recordId: { type: [Number, String], default: null },
})
const emit = defineEmits(['update:modelValue', 'sent', 'changed'])

const { t, locale } = useI18n()
const router = useRouter()

const loading = ref(false)
const info = ref(null)
const dispatches = ref([])
const color = ref(false)
const duplex = ref(false)
const registered = ref('')
const price = ref(null)
const priceLoading = ref(false)
const sending = ref('')
const busyId = ref(null)

const registeredItems = computed(() => [
    { value: '', title: t('PostalDialog.registeredNone') },
    { value: 'r1', title: t('PostalDialog.registeredR1') },
    { value: 'r2', title: t('PostalDialog.registeredR2') },
])

const providerNames = { pinag: 'PIN AG', letterxpress: 'LetterXpress', manual: '' }
const providerName = computed(() => providerNames[info.value?.provider] || t('PostalDialog.providerGeneric'))
function providerLabel(p) {
    return p === 'manual' ? t('PostalDialog.providerManual') : (providerNames[p] || p)
}

/** Statusanzeige (gleiche Farben wie der Versandstatus der Faktura) */
function postalStatusMeta(status) {
    switch (status) {
        case 'done':     return { icon: 'mdi-check-all',           color: 'success', label: 'PostalDialog.statusDone' }
        case 'sent':     return { icon: 'mdi-mailbox-up-outline',  color: 'info',    label: 'PostalDialog.statusSent' }
        case 'manual':   return { icon: 'mdi-printer-check',       color: 'grey',    label: 'PostalDialog.statusManual' }
        case 'canceled': return { icon: 'mdi-cancel',              color: 'warning', label: 'PostalDialog.statusCanceled' }
        case 'error':    return { icon: 'mdi-alert-circle',        color: 'error',   label: 'PostalDialog.statusError' }
        default:         return { icon: 'mdi-clock-outline',       color: 'grey',    label: 'PostalDialog.statusQueued' }
    }
}
const statusMeta = postalStatusMeta

function formatMoney(v) {
    return Number(v || 0).toLocaleString(locale.value, { style: 'currency', currency: 'EUR' })
}
function formatWhen(value) {
    if (!value) return ''
    const d = new Date(value)
    if (isNaN(d.getTime())) return String(value)
    return d.toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function close() {
    emit('update:modelValue', false)
}

function goToConfig() {
    close()
    router.push({ name: 'client-defaults', query: { tab: 'crm', panel: 'eletter' } })
}

async function prepare() {
    if (!props.recordId) return
    loading.value = true
    info.value = null
    try {
        const { data } = await axios.post('/api/letters/', {
            action: 'preparePostal',
            record_type: props.recordType,
            record_id: props.recordId,
        })
        if (!data.success) throw new Error(data.text || 'preparePostal')
        const r = data.payload.results
        info.value = r
        dispatches.value = r.dispatches || []
        color.value = !!r.color
        duplex.value = !!r.duplex
        registered.value = ''
        price.value = r.price ?? null
    } catch (e) {
        toast.error(t('PostalDialog.prepareError', { error: e.message }))
        close()
    } finally {
        loading.value = false
    }
}

let priceTimer = null
async function loadPrice() {
    if (!info.value || info.value.provider !== 'letterxpress' || info.value.config_problem || !info.value.pages) return
    priceLoading.value = true
    clearTimeout(priceTimer)
    priceTimer = setTimeout(async () => {
        try {
            const { data } = await axios.post('/api/letters/', {
                action: 'getPostalPrice',
                pages: info.value.pages,
                color: color.value,
                duplex: duplex.value,
                shipping: info.value.shipping,
                registered: registered.value,
            })
            price.value = data.success ? (data.payload.results.price ?? null) : null
        } catch {
            price.value = null
        } finally {
            priceLoading.value = false
        }
    }, 250)
}
watch([color, duplex, registered], loadPrice)

async function send(channel) {
    if (channel === 'manual') {
        const ok = await alerts.question(t('PostalDialog.confirmManual'), t('PostalDialog.sendManual'))
        if (!ok.isConfirmed) return
    } else {
        const text = price.value !== null
            ? t('PostalDialog.confirmProviderPrice', { provider: providerName.value, amount: formatMoney(price.value) })
            : t('PostalDialog.confirmProvider', { provider: providerName.value })
        const ok = await alerts.question(text, t('PostalDialog.title'))
        if (!ok.isConfirmed) return
    }
    sending.value = channel
    try {
        const { data } = await axios.post('/api/letters/', {
            action: 'sendPostal',
            record_type: props.recordType,
            record_id: props.recordId,
            channel,
            color: color.value,
            duplex: duplex.value,
            registered: registered.value,
        })
        if (!data.success) throw new Error(data.text || 'sendPostal')
        const r = data.payload.results
        if (r.entry) {
            dispatches.value.unshift(r.entry)
            emit('sent', r.entry)
        }
        if (channel === 'manual') {
            toast.success(t('PostalDialog.sentManual'))
            if (r.pdf) openBase64Pdf(r.pdf, r.filename)
        } else {
            toast.success(t('PostalDialog.sentProvider', { provider: providerName.value }))
        }
    } catch (e) {
        alerts.error(e.message, t('PostalDialog.sendError'))
        // Fehlgeschlagene Einlieferung erscheint mit Fehlertext in der Liste
        reloadDispatches()
    } finally {
        sending.value = ''
    }
}

async function reloadDispatches() {
    try {
        const { data } = await axios.post('/api/letters/', {
            action: 'getPostalDispatches',
            record_type: props.recordType,
            record_id: props.recordId,
        })
        if (data.success) {
            dispatches.value = data.payload.results || []
            emit('changed', dispatches.value)
        }
    } catch { /* Liste bleibt wie sie ist */ }
}

async function refresh(d) {
    busyId.value = d.id
    try {
        const { data } = await axios.post('/api/letters/', { action: 'refreshPostalStatus', id: d.id })
        if (!data.success) throw new Error(data.text)
        Object.assign(d, data.payload.results.entry)
        emit('changed', dispatches.value)
    } catch (e) {
        toast.error(e.message)
    } finally {
        busyId.value = null
    }
}

async function cancel(d) {
    const question = d.cancelable ? t('PostalDialog.confirmCancel') : t('PostalDialog.confirmRemove')
    const ok = await alerts.question(question, d.cancelable ? t('PostalDialog.cancel') : t('PostalDialog.remove'))
    if (!ok.isConfirmed) return
    busyId.value = d.id
    try {
        const { data } = await axios.post('/api/letters/', { action: 'cancelPostalDispatch', id: d.id })
        if (!data.success) throw new Error(data.text)
        if (data.payload.results.deleted) {
            dispatches.value = dispatches.value.filter(x => x.id !== d.id)
        } else {
            Object.assign(d, data.payload.results.entry)
        }
        emit('changed', dispatches.value)
        toast.success(d.cancelable ? t('PostalDialog.canceled') : t('PostalDialog.removed'))
    } catch (e) {
        toast.error(e.message)
    } finally {
        busyId.value = null
    }
}

async function openPdf(d) {
    try {
        const { data } = await axios.post('/api/letters/', { action: 'getPostalPdf', id: d.id })
        if (!data.success) throw new Error(data.text)
        openBase64Pdf(data.payload.results.pdf, data.payload.results.filename)
    } catch (e) {
        toast.error(e.message)
    }
}

watch(() => props.modelValue, (open) => { if (open) prepare() })
</script>
