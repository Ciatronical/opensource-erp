<template>
    <v-dialog :model-value="modelValue" max-width="640" persistent scrollable @update:model-value="v => emit('update:modelValue', v)">
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon class="me-2">mdi-email-send-outline</v-icon>
                {{ t('KasseView.send.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="close" />
            </v-card-title>

            <v-card-text class="pt-2">
                <!-- Was geht raus: Kasse, Zeitraum, Umfang -->
                <v-sheet rounded="lg" border class="pa-3 mb-4 d-flex flex-wrap ga-4 text-body-2">
                    <div>
                        <div class="text-caption text-medium-emphasis">{{ t('KasseView.send.period') }}</div>
                        <div class="font-weight-medium">{{ periodLabel || t('KasseView.send.allPeriods') }}</div>
                    </div>
                    <div v-if="info">
                        <div class="text-caption text-medium-emphasis">{{ t('KasseView.send.scope') }}</div>
                        <div class="font-weight-medium">{{ t('KasseView.send.scopeText', { rows: info.row_count, docs: info.document_count }) }}</div>
                    </div>
                    <div v-if="info">
                        <div class="text-caption text-medium-emphasis">{{ t('KasseView.send.sender') }}</div>
                        <div class="font-weight-medium">{{ info.sender || '–' }}</div>
                    </div>
                </v-sheet>

                <v-alert v-if="info && !info.email_configured" type="warning" variant="tonal" density="compact" class="mb-4">
                    {{ t('KasseView.send.notConfigured') }}
                </v-alert>

                <v-text-field
                    v-model="form.to"
                    :label="t('KasseView.send.to')"
                    :hint="t('KasseView.send.toHint')"
                    persistent-hint
                    density="compact"
                    variant="outlined"
                    prepend-inner-icon="mdi-account-tie-outline"
                    class="mb-3"
                    autofocus
                />
                <v-text-field
                    v-model="form.cc"
                    :label="t('KasseView.send.cc')"
                    density="compact"
                    variant="outlined"
                    prepend-inner-icon="mdi-account-multiple-outline"
                    class="mb-3"
                />
                <v-text-field
                    v-model="form.subject"
                    :label="t('KasseView.send.subject')"
                    density="compact"
                    variant="outlined"
                    class="mb-3"
                />
                <v-textarea
                    v-model="form.body"
                    :label="t('KasseView.send.body')"
                    density="compact"
                    variant="outlined"
                    rows="4"
                    auto-grow
                    class="mb-2"
                />

                <div class="text-caption text-medium-emphasis mb-1">{{ t('KasseView.send.attachments') }}</div>
                <v-checkbox v-model="form.pdf" :label="t('KasseView.send.attachPdf')" density="compact" hide-details />
                <v-checkbox v-model="form.csv" :label="t('KasseView.send.attachCsv')" density="compact" hide-details />
                <v-checkbox v-model="form.datev" density="compact" hide-details>
                    <template #label>
                        <span>{{ t('KasseView.send.attachDatev') }}</span>
                        <span v-if="info && info.document_count > 0" class="text-medium-emphasis ms-1">
                            ({{ t('KasseView.send.withDocs', { docs: info.document_count, size: fmtBytes(info.document_bytes) }) }})
                        </span>
                    </template>
                </v-checkbox>

                <!-- Zu groß: der Mailserver würde die Mail wahrscheinlich ablehnen -->
                <v-alert v-if="tooLarge" type="warning" variant="tonal" density="compact" class="mt-3">
                    {{ t('KasseView.send.tooLarge', { size: fmtBytes(tooLarge.bytes), max: fmtBytes(tooLarge.max_bytes) }) }}
                </v-alert>
                <v-alert v-else-if="info && !info.datev_configured && form.datev" type="info" variant="tonal" density="compact" class="mt-3">
                    {{ t('KasseView.send.datevNotConfigured') }}
                </v-alert>
            </v-card-text>

            <v-card-actions class="px-4 pb-4">
                <v-btn variant="text" @click="close">{{ t('KasseView.cancel') }}</v-btn>
                <v-spacer />
                <v-btn v-if="tooLarge" variant="tonal" color="primary" :loading="sending" @click="emit('download-package')">
                    <v-icon start>mdi-download-outline</v-icon>
                    {{ t('KasseView.send.downloadInstead') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    :loading="sending"
                    :disabled="!canSend"
                    @click="send(!!tooLarge)"
                >
                    <v-icon start>mdi-send</v-icon>
                    {{ tooLarge ? t('KasseView.send.sendAnyway') : t('KasseView.send.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
/**
 * Kassenbuch per E-Mail versenden — an den Steuerberater, mit PDF, CSV und
 * DATEV-Paket (Buchungsstapel + Belege) als Anhang.
 *
 * Beim Öffnen holt der Dialog Umfang, Absender und den zuletzt verwendeten
 * Empfänger; Betreff und Text sind mit Firma und Zeitraum vorbelegt. Zu große
 * Anhänge meldet das Backend statt zu senden — der Dialog bietet dann den
 * Download oder „trotzdem senden" an.
 */
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue:  { type: Boolean, default: false },
    registerId:  { type: Number,  default: null },
    fromDate:    { type: String,  default: undefined },
    toDate:      { type: String,  default: undefined },
    periodLabel: { type: String,  default: '' },
})
const emit = defineEmits(['update:modelValue', 'sent', 'download-package'])

const { t } = useI18n()
const API_URL = '/api/banking/'

const info     = ref(null)
const sending  = ref(false)
const tooLarge = ref(null)
const form     = reactive({ to: '', cc: '', subject: '', body: '', pdf: true, csv: true, datev: true })

const canSend = computed(() =>
    !sending.value && form.to.trim() !== '' && (form.pdf || form.csv || form.datev) && (!info.value || info.value.email_configured)
)

function fmtBytes(b) {
    const n = Number(b) || 0
    if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`
    if (n >= 1024)        return `${Math.round(n / 1024)} kB`
    return `${n} B`
}

function close() {
    emit('update:modelValue', false)
}

async function load() {
    info.value = null
    tooLarge.value = null
    try {
        const res = await axios.post(API_URL, {
            action:           'getCashbookExportInfo',
            cash_register_id: props.registerId,
            from_date:        props.fromDate,
            to_date:          props.toDate,
        })
        if (!res.data?.success) throw new Error(res.data?.payload || res.data?.text)
        info.value = res.data.payload
        const zeitraum = props.periodLabel || t('KasseView.send.allPeriods')
        form.to      = info.value.recipient || ''
        form.cc      = info.value.cc || ''
        form.subject = t('KasseView.send.subjectDefault', { company: info.value.company, period: zeitraum })
        form.body    = t('KasseView.send.bodyDefault', {
            period:  zeitraum,
            opening: fmtMoney(info.value.opening),
            closing: fmtMoney(info.value.closing),
            rows:    info.value.row_count,
            company: info.value.company,
        })
    } catch (e) {
        alerts.error(e.message)
        close()
    }
}

function fmtMoney(v) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(v) || 0)
}

async function send(force) {
    sending.value = true
    try {
        const res = await axios.post(API_URL, {
            action:           'sendCashbookEmail',
            cash_register_id: props.registerId,
            from_date:        props.fromDate,
            to_date:          props.toDate,
            period_label:     props.periodLabel,
            to:               form.to,
            cc:               form.cc,
            subject:          form.subject,
            body:             form.body,
            attachments:      { pdf: form.pdf, csv: form.csv, datev: form.datev },
            force,
        })
        if (res.data?.success) {
            alerts.success(t('KasseView.send.sent', { to: (res.data.payload?.recipients || []).join(', ') }))
            emit('sent', res.data.payload)
            close()
            return
        }
        if (res.data?.text === 'ATTACHMENTS_TOO_LARGE') {
            tooLarge.value = res.data.payload
            return
        }
        alerts.error(typeof res.data?.payload === 'string' ? res.data.payload : (res.data?.text || t('KasseView.send.failed')))
    } catch (e) {
        alerts.error(e.message)
    } finally {
        sending.value = false
    }
}

watch(() => props.modelValue, (open) => { if (open) load() })
</script>
