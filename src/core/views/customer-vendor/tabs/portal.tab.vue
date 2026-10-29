<!-- src/core/views/customer-vendor/tabs/portal.tab.vue -->
<!--
    Reiter „Kundenkonto“ der Kundenakte (CRM-Ansicht): Zugang des Kunden zum
    Kundenkonto auf der Webseite (Kundenportal, dev/shop-konto-freischaltung.md F3).

    Hat der Kunde noch keinen Zugang, schickt der Mitarbeiter ihm von hier den
    Link zum Einrichten — per WhatsApp an eine seiner Nummern oder per Mail.
    Hat er einen, lässt sich der Zugang sperren (setPortalAccountLock, ohne
    den Kunden auf „veraltet“ zu setzen) und zurücksetzen (resetPortalAccount:
    Kennwort weg, der Kunde richtet den Zugang über die Freischaltung neu ein).
    Der Reiter erscheint nur, wenn eine Webseite des Kundenportals eingeschaltet
    ist (oserpStore.hasPortalWebsite); zum Versand angeboten werden die
    Webseiten, die die Konto-Freischaltung anbieten (getPortalActivationStatus).
-->

<template>
    <div class="pa-2 pa-sm-3 portal-tab">
        <section-card :title="t('CustomerVendorEditView.portal.title')" icon="mdi-account-key-outline">
            <div v-if="laedt" class="text-center py-4">
                <v-progress-circular indeterminate size="32" width="3" />
            </div>
            <v-alert v-else-if="!status" type="warning" variant="tonal" density="compact">
                {{ t('CustomerVendorEditView.portal.loadError') }}
            </v-alert>
            <template v-else-if="status.account">
                <div class="text-body-2">
                    <v-icon size="small" :color="status.locked ? 'warning' : 'success'" class="mr-1">
                        {{ status.locked ? 'mdi-lock' : 'mdi-check-circle' }}
                    </v-icon>
                    {{ t('CustomerVendorEditView.portal.hasAccount', { email: status.email }) }}
                </div>
                <v-alert v-if="status.locked" type="warning" variant="tonal" density="compact" class="mt-2">
                    {{ t('CustomerVendorEditView.portal.locked', { date: zeitpunkt(status.locked_at) }) }}
                </v-alert>
                <div class="d-flex flex-wrap ga-2 mt-3">
                    <v-btn
                        variant="tonal"
                        :color="status.locked ? 'success' : 'warning'"
                        :prepend-icon="status.locked ? 'mdi-lock-open-variant-outline' : 'mdi-lock-outline'"
                        :loading="sperrt"
                        @click="sperren(!status.locked)"
                    >
                        {{ t(status.locked ? 'CustomerVendorEditView.portal.unlock' : 'CustomerVendorEditView.portal.lock') }}
                    </v-btn>
                    <v-btn
                        variant="tonal" color="error" prepend-icon="mdi-lock-reset"
                        :loading="setztZurueck"
                        @click="zuruecksetzen"
                    >
                        {{ t('CustomerVendorEditView.portal.reset') }}
                    </v-btn>
                </div>
                <div class="text-caption text-medium-emphasis mt-2">
                    {{ t('CustomerVendorEditView.portal.resetHint') }}
                </div>
            </template>
            <template v-else>
                <div class="text-body-2 mb-2">{{ t('CustomerVendorEditView.portal.noAccount') }}</div>
                <v-alert v-if="status.locked" type="warning" variant="tonal" density="compact">
                    {{ t('CustomerVendorEditView.portal.lockedNoAccount', { date: zeitpunkt(status.locked_at) }) }}
                    <div class="mt-2">
                        <v-btn
                            size="small" variant="tonal" color="success" prepend-icon="mdi-lock-open-variant-outline"
                            :loading="sperrt"
                            @click="sperren(false)"
                        >
                            {{ t('CustomerVendorEditView.portal.unlock') }}
                        </v-btn>
                    </div>
                </v-alert>
                <template v-else>
                    <div v-if="status.open" class="text-caption text-medium-emphasis mb-2">
                        {{ t('CustomerVendorEditView.portal.open', {
                            date: zeitpunkt(status.open.created),
                            via: status.open.phone ? 'WhatsApp' : t('CustomerVendorEditView.portal.viaEmail'),
                            expires: zeitpunkt(status.open.expires)
                        }) }}
                        ({{ t(status.open.source === 'staff' ? 'CustomerVendorEditView.portal.sourceStaff' : 'CustomerVendorEditView.portal.sourcePortal') }})
                    </div>
                    <v-alert v-if="!websites.length" type="info" variant="tonal" density="compact">
                        {{ t('CustomerVendorEditView.portal.noWebsite') }}
                    </v-alert>
                    <div v-else class="d-flex flex-wrap align-center ga-2">
                        <v-select
                            v-model="websiteId"
                            :items="websites.map(w => ({ value: Number(w.id), title: w.name }))"
                            :label="t('CustomerVendorEditView.portal.website')"
                            style="max-width: 260px"
                        />
                        <v-select
                            v-model="via"
                            :items="[{ value: 'whatsapp', title: 'WhatsApp' }, { value: 'email', title: t('CustomerVendorEditView.portal.viaEmail') }]"
                            :label="t('CustomerVendorEditView.portal.via')"
                            style="max-width: 180px"
                        />
                        <v-select
                            v-if="via === 'whatsapp' && phoneNumbers.length > 1"
                            v-model="phone"
                            :items="phoneNumbers"
                            :label="t('CustomerVendorEditView.portal.phone')"
                            style="max-width: 220px"
                        />
                        <v-btn
                            color="primary" variant="tonal" prepend-icon="mdi-send-lock"
                            :loading="sendet"
                            :disabled="!websiteId || (via === 'whatsapp' && (!whatsapp || !template)) || (via === 'email' && !status.email)"
                            @click="senden"
                        >
                            {{ t('CustomerVendorEditView.portal.send') }}
                        </v-btn>
                    </div>
                    <div v-if="via === 'whatsapp' && websites.length && !template" class="text-caption text-warning mt-2">
                        {{ t('CustomerVendorEditView.portal.noTemplate') }}
                    </div>
                    <div v-if="via === 'email' && !status.email" class="text-caption text-warning mt-2">
                        {{ t('CustomerVendorEditView.portal.noEmail') }}
                    </div>
                </template>
            </template>
        </section-card>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'
import SectionCard from '@/core/components/layout/section-card.vue'

const props = defineProps({
    cvId: { type: [Number, String], required: true },
    phoneNumbers: { type: Array, default: () => [] },
})

const { t, te, locale } = useI18n()

const laedt = ref(false)
const status = ref(null)       // activationStatus: account, locked, email, open …
const websites = ref([])       // Webseiten mit eingeschalteter Freischaltung
const whatsapp = ref(false)    // WhatsApp eingerichtet
const template = ref(false)    // genehmigte Vorlage „Kundenkonto freischalten“
const websiteId = ref(0)
const via = ref('whatsapp')
const phone = ref('')
const sendet = ref(false)
const sperrt = ref(false)
const setztZurueck = ref(false)

async function laden() {
    if (!props.cvId) { status.value = null; return }
    laedt.value = true
    try {
        const resp = await axios.post('/api/portal/', { action: 'getPortalActivationStatus', customer_id: props.cvId })
        if (!resp.data?.success) { status.value = null; return }
        const daten = resp.data.payload || {}
        status.value = daten.status || null
        websites.value = daten.websites || []
        whatsapp.value = !!daten.whatsapp
        template.value = !!daten.template
        if (!websiteId.value || !websites.value.some(w => Number(w.id) === websiteId.value)) {
            websiteId.value = websites.value.length ? Number(websites.value[0].id) : 0
        }
        if (!phone.value) phone.value = props.phoneNumbers[0] || ''
        if (!whatsapp.value && status.value?.email) via.value = 'email'
    } catch {
        status.value = null
    } finally {
        laedt.value = false
    }
}

/**
 * Lesbarer Satz zur Fehlerantwort: der Fehlercode steht in text und wird
 * übersetzt (errors.*); ohne Übersetzung der Wortlaut des Backends (debug,
 * bei geworfenen ApiErrors payload), sonst der allgemeine Satz
 */
function fehlerText(data, allgemein = 'CustomerVendorEditView.portal.sendError') {
    const code = String(data?.text || '')
    const key = `CustomerVendorEditView.portal.errors.${code}`
    if (code && te(key)) return t(key)
    const wortlaut = data?.debug || (typeof data?.payload === 'string' ? data.payload : '')
    return wortlaut || t(allgemein)
}

async function senden() {
    sendet.value = true
    try {
        const resp = await axios.post('/api/portal/', {
            action: 'sendPortalActivation',
            customer_id: props.cvId,
            website_id: websiteId.value,
            via: via.value,
            phone: via.value === 'whatsapp' ? (phone.value || props.phoneNumbers[0] || '') : '',
        })
        if (resp.data?.success) {
            toast.success(t('CustomerVendorEditView.portal.sent'))
            status.value = resp.data.payload?.status || status.value
        } else {
            toast.error(fehlerText(resp.data))
        }
    } catch (e) {
        toast.error(fehlerText(e?.response?.data))
    } finally {
        sendet.value = false
    }
}

/**
 * Verwaltungsaktion am Zugang (sperren, aufheben, zurücksetzen): die Antwort
 * bringt den neuen Stand mit; ein Fehler wird gemeldet
 */
async function verwalten(aktion, felder, meldung) {
    try {
        const resp = await axios.post('/api/portal/', { action: aktion, customer_id: props.cvId, ...felder })
        if (resp.data?.success) {
            status.value = resp.data.payload?.status || status.value
            toast.success(t(meldung))
        } else {
            toast.error(fehlerText(resp.data, 'CustomerVendorEditView.portal.actionError'))
        }
    } catch (e) {
        toast.error(fehlerText(e?.response?.data, 'CustomerVendorEditView.portal.actionError'))
    }
}

/** Zugang sperren (true) oder die Sperre aufheben (false) — umkehrbar, deshalb ohne Rückfrage */
async function sperren(locked) {
    sperrt.value = true
    try {
        await verwalten('setPortalAccountLock', { locked }, locked ? 'CustomerVendorEditView.portal.lockDone' : 'CustomerVendorEditView.portal.unlockDone')
    } finally {
        sperrt.value = false
    }
}

/** Zugang zurücksetzen: löscht das Kennwort, deshalb mit Rückfrage */
async function zuruecksetzen() {
    const antwort = await alerts.question(
        t('CustomerVendorEditView.portal.resetText', { email: status.value?.email || '' }),
        t('CustomerVendorEditView.portal.resetTitle'),
        t('CustomerVendorEditView.portal.resetConfirm'),
        t('CustomerVendorEditView.portal.cancel')
    )
    if (!antwort.isConfirmed) return
    setztZurueck.value = true
    try {
        await verwalten('resetPortalAccount', {}, 'CustomerVendorEditView.portal.resetDone')
    } finally {
        setztZurueck.value = false
    }
}

/** Zeitstempel der Datenbank für die Anzeige */
function zeitpunkt(wert) {
    if (!wert) return ''
    const datum = new Date(String(wert).replace(' ', 'T'))
    return isNaN(datum) ? String(wert) : datum.toLocaleString(locale.value)
}

onMounted(laden)

// Anderer Kunde: Stand neu holen, die Nummer gehört zum alten
watch(() => props.cvId, () => {
    phone.value = ''
    laden()
})
</script>
