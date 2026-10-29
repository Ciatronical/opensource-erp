<!-- src/core/views/customer-vendor/tabs/portal.tab.vue -->
<!--
    Reiter „Kundenkonto“ der Kundenakte (CRM-Ansicht): Zugang des Kunden zum
    Kundenkonto auf der Webseite (Kundenportal, dev/shop-konto-freischaltung.md F3).

    Hat der Kunde noch keinen Zugang, schickt der Mitarbeiter ihm von hier den
    Link zum Einrichten — per WhatsApp an eine seiner Nummern oder per Mail.
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
            <div v-else-if="status.account" class="text-body-2">
                <v-icon size="small" color="success" class="mr-1">mdi-check-circle</v-icon>
                {{ t('CustomerVendorEditView.portal.hasAccount', { email: status.email }) }}
            </div>
            <template v-else>
                <div class="text-body-2 mb-2">{{ t('CustomerVendorEditView.portal.noAccount') }}</div>
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
        </section-card>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as toast from '@/core/utils/toasts.js'
import SectionCard from '@/core/components/layout/section-card.vue'

const props = defineProps({
    cvId: { type: [Number, String], required: true },
    phoneNumbers: { type: Array, default: () => [] },
})

const { t, te, locale } = useI18n()

const laedt = ref(false)
const status = ref(null)       // activationStatus: account, email, open …
const websites = ref([])       // Webseiten mit eingeschalteter Freischaltung
const whatsapp = ref(false)    // WhatsApp eingerichtet
const template = ref(false)    // genehmigte Vorlage „Kundenkonto freischalten“
const websiteId = ref(0)
const via = ref('whatsapp')
const phone = ref('')
const sendet = ref(false)

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
 * Lesbarer Satz zur Fehlerantwort des Versands: der Fehlercode steht in
 * text und wird übersetzt (errors.*); ohne Übersetzung der Wortlaut des
 * Backends (debug, bei geworfenen ApiErrors payload), sonst der allgemeine Satz
 */
function fehlerText(data) {
    const code = String(data?.text || '')
    const key = `CustomerVendorEditView.portal.errors.${code}`
    if (code && te(key)) return t(key)
    const wortlaut = data?.debug || (typeof data?.payload === 'string' ? data.payload : '')
    return wortlaut || t('CustomerVendorEditView.portal.sendError')
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
