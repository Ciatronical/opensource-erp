<!-- src/core/views/faktura/dialogs/weroni.review.dialog.vue -->
<!--
    Weroni – Rechtsprüfung eines importierten Gutachtens.

    Zeigt Entscheidung (Ampel), Prüfpunkte, Ansprüche des Kunden, nächste
    Schritte und die von Weroni formulierte Kommunikation an Kunde und
    Versicherung – editierbar, mit Versand per E-Mail/WhatsApp und Protokoll.
-->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="1100"
        persistent
        scrollable
    >
        <v-card>
            <v-card-title class="weroni-review__header">
                <img :src="weroniIcon36" width="36" height="36" alt="Weroni" class="weroni-review__avatar" />
                <div class="ml-3">
                    <div class="text-subtitle-1 font-weight-bold">{{ t('FakturaView.faktura.weroni.title') }}</div>
                    <div class="text-caption text-medium-emphasis">
                        {{ createdAt ? t('FakturaView.faktura.weroni.checkedAt', { at: createdAt }) : t('FakturaView.faktura.weroni.subtitle') }}
                    </div>
                </div>
                <v-spacer />
                <ai-model-button assistant="gutachten_review" size="x-small" />
                <v-btn
                    v-if="review && !loading"
                    variant="text"
                    size="small"
                    prepend-icon="mdi-refresh"
                    @click="$emit('rerun')"
                >
                    {{ t('FakturaView.faktura.weroni.rerun') }}
                </v-btn>
                <v-btn icon="mdi-close" variant="text" density="compact" size="small" @click="onClose" />
            </v-card-title>

            <v-divider />

            <v-card-text class="pt-4">
                <!-- Weroni prüft -->
                <div v-if="loading" class="d-flex flex-column align-center py-10">
                    <img :src="weroniIcon56" width="56" height="56" alt="Weroni" class="weroni-review__thinking mb-4" />
                    <div class="text-body-1">{{ t('FakturaView.faktura.weroni.loading') }}</div>
                    <div class="text-caption text-medium-emphasis mt-1">{{ t('FakturaView.faktura.weroni.loadingHint') }}</div>
                </div>

                <template v-else>
                    <v-alert v-if="error" type="error" variant="tonal" class="mb-4" closable @click:close="$emit('clear-error')">
                        {{ error }}
                    </v-alert>

                    <!-- Gutachten importiert, Prüfung startet erst auf Knopfdruck -->
                    <div v-if="!review && pending" class="d-flex flex-column align-center py-10 text-center">
                        <v-icon size="48" color="deep-purple" class="mb-3">mdi-scale-balance</v-icon>
                        <div class="text-body-1 mb-1">{{ t('FakturaView.faktura.weroni.pendingTitle') }}</div>
                        <div class="text-body-2 text-medium-emphasis mb-4" style="max-width: 520px">
                            {{ t('FakturaView.faktura.weroni.pendingHint') }}
                        </div>
                        <div class="d-flex flex-wrap justify-center ga-2">
                            <v-btn color="deep-purple" variant="elevated" prepend-icon="mdi-scale-balance" @click="$emit('rerun')">
                                {{ t('FakturaView.faktura.weroni.start') }}
                            </v-btn>
                            <v-btn variant="text" prepend-icon="mdi-file-pdf-box" @click="$emit('import-gutachten')">
                                {{ t('FakturaView.faktura.importGutachten') }}
                            </v-btn>
                        </div>
                    </div>

                    <!-- Noch kein Gutachten -->
                    <div v-else-if="!review && !error" class="d-flex flex-column align-center py-10 text-center">
                        <v-icon size="48" color="deep-purple" class="mb-3">mdi-scale-balance</v-icon>
                        <div class="text-body-1 mb-1">{{ t('FakturaView.faktura.weroni.emptyTitle') }}</div>
                        <div class="text-body-2 text-medium-emphasis mb-4" style="max-width: 520px">
                            {{ t('FakturaView.faktura.weroni.emptyHint') }}
                        </div>
                        <v-btn color="deep-orange-darken-2" variant="elevated" prepend-icon="mdi-file-pdf-box" @click="$emit('import-gutachten')">
                            {{ t('FakturaView.faktura.importGutachten') }}
                        </v-btn>
                    </div>

                    <template v-if="review">
                        <!-- Entscheidung -->
                        <v-alert
                            :type="ampelType"
                            variant="tonal"
                            prominent
                            icon="mdi-scale-balance"
                            class="mb-4"
                        >
                            <div class="d-flex align-center flex-wrap ga-2 mb-1">
                                <span class="text-subtitle-1 font-weight-bold">{{ review.headline }}</span>
                                <v-chip size="small" :color="ampelColor" variant="flat" label>{{ verdictLabel }}</v-chip>
                            </div>
                            <div class="text-body-2 weroni-review__prose">{{ review.decision }}</div>
                        </v-alert>

                        <v-row dense class="mb-2">
                            <!-- Prüfpunkte -->
                            <v-col cols="12" md="7">
                                <v-card variant="outlined" class="pa-3 h-100">
                                    <div class="text-subtitle-2 mb-2">
                                        <v-icon size="small" class="mr-1">mdi-clipboard-check-outline</v-icon>
                                        {{ t('FakturaView.faktura.weroni.checks') }}
                                    </div>
                                    <div v-for="(c, i) in review.checks" :key="i" class="d-flex align-start mb-2">
                                        <v-icon size="small" :color="checkColor(c.status)" class="mr-2 mt-1">{{ checkIcon(c.status) }}</v-icon>
                                        <div class="text-body-2">
                                            <strong>{{ c.topic }}</strong>
                                            <span v-if="c.basis" class="text-caption text-medium-emphasis ml-1">({{ c.basis }})</span>
                                            <div>{{ c.finding }}</div>
                                        </div>
                                    </div>
                                </v-card>
                            </v-col>

                            <!-- Ansprüche -->
                            <v-col cols="12" md="5">
                                <v-card variant="outlined" class="pa-3 h-100">
                                    <div class="text-subtitle-2 mb-2">
                                        <v-icon size="small" class="mr-1">mdi-cash-multiple</v-icon>
                                        {{ t('FakturaView.faktura.weroni.claims') }}
                                    </div>
                                    <table class="weroni-review__claims">
                                        <tbody>
                                            <tr v-for="(cl, i) in review.claims" :key="i">
                                                <td>
                                                    <div>{{ cl.title }}</div>
                                                    <div v-if="cl.basis || cl.note" class="text-caption text-medium-emphasis">
                                                        {{ [cl.basis, cl.note].filter(Boolean).join(' – ') }}
                                                    </div>
                                                </td>
                                                <td class="text-right text-no-wrap">{{ cl.amount != null ? formatCurrency(cl.amount) : '–' }}</td>
                                            </tr>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td class="font-weight-bold">{{ t('FakturaView.faktura.weroni.claimsTotal') }}</td>
                                                <td class="text-right font-weight-bold text-no-wrap">{{ formatCurrency(review.claims_total) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    <v-alert
                                        v-if="review.lawyer_recommended"
                                        type="info"
                                        variant="tonal"
                                        density="compact"
                                        icon="mdi-gavel"
                                        class="mt-3"
                                    >
                                        <strong>{{ t('FakturaView.faktura.weroni.lawyer') }}</strong>
                                        <span v-if="review.lawyer_reason"> – {{ review.lawyer_reason }}</span>
                                    </v-alert>
                                </v-card>
                            </v-col>
                        </v-row>

                        <v-row dense class="mb-2">
                            <v-col cols="12" md="4">
                                <v-card variant="outlined" class="pa-3 h-100">
                                    <div class="text-subtitle-2 mb-1">
                                        <v-icon size="small" class="mr-1">mdi-format-list-numbered</v-icon>
                                        {{ t('FakturaView.faktura.weroni.nextSteps') }}
                                    </div>
                                    <ol class="text-body-2 pl-4">
                                        <li v-for="(s, i) in review.next_steps" :key="i">{{ s }}</li>
                                    </ol>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-card variant="outlined" class="pa-3 h-100">
                                    <div class="text-subtitle-2 mb-1">
                                        <v-icon size="small" class="mr-1">mdi-alert-outline</v-icon>
                                        {{ t('FakturaView.faktura.weroni.risks') }}
                                    </div>
                                    <ul class="text-body-2 pl-4">
                                        <li v-for="(r, i) in review.risks" :key="i">{{ r }}</li>
                                        <li v-if="!review.risks.length" class="text-medium-emphasis">{{ t('FakturaView.faktura.weroni.none') }}</li>
                                    </ul>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-card variant="outlined" class="pa-3 h-100">
                                    <div class="text-subtitle-2 mb-1">
                                        <v-icon size="small" class="mr-1">mdi-help-circle-outline</v-icon>
                                        {{ t('FakturaView.faktura.weroni.questions') }}
                                    </div>
                                    <ul class="text-body-2 pl-4">
                                        <li v-for="(q, i) in review.questions" :key="i">{{ q }}</li>
                                        <li v-if="!review.questions.length" class="text-medium-emphasis">{{ t('FakturaView.faktura.weroni.none') }}</li>
                                    </ul>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Kommunikation -->
                        <v-card variant="outlined" class="pa-3">
                            <div class="d-flex align-center flex-wrap mb-1">
                                <div class="text-subtitle-2">
                                    <v-icon size="small" class="mr-1">mdi-send-outline</v-icon>
                                    {{ t('FakturaView.faktura.weroni.communication') }}
                                </div>
                                <v-spacer />
                                <v-chip
                                    v-for="(c, i) in communicated"
                                    :key="i"
                                    size="x-small"
                                    variant="tonal"
                                    color="deep-purple"
                                    class="ml-1"
                                    :prepend-icon="channelIcon(c.channel)"
                                >
                                    {{ channelLabel(c.channel) }} {{ c.at }}
                                </v-chip>
                            </div>
                            <v-tabs v-model="tab" density="compact" color="deep-purple">
                                <v-tab value="customer" size="small">
                                    <v-icon start size="small">mdi-account</v-icon>
                                    {{ t('FakturaView.faktura.weroni.toCustomer', { name: customer?.name || '' }) }}
                                </v-tab>
                                <v-tab value="insurer" size="small">
                                    <v-icon start size="small">mdi-shield-account-outline</v-icon>
                                    {{ t('FakturaView.faktura.weroni.toInsurer') }}
                                </v-tab>
                            </v-tabs>
                            <v-window v-model="tab" class="mt-2">
                                <v-window-item value="customer">
                                    <v-textarea
                                        v-model="customerText"
                                        variant="outlined"
                                        density="compact"
                                        auto-grow
                                        rows="8"
                                        hide-details
                                    />
                                    <div class="d-flex flex-wrap ga-2 mt-2">
                                        <v-btn
                                            size="small"
                                            variant="tonal"
                                            color="primary"
                                            prepend-icon="mdi-email-outline"
                                            :disabled="!contactEmail || sending"
                                            :loading="sending === 'email_customer'"
                                            @click="$emit('send-email', { channel: 'email_customer', to: contactEmail, subject: customerSubject, body: customerText })"
                                        >
                                            {{ t('FakturaView.faktura.weroni.sendEmail', { to: contactEmail || '–' }) }}
                                        </v-btn>
                                        <v-btn
                                            size="small"
                                            variant="tonal"
                                            color="green"
                                            prepend-icon="mdi-whatsapp"
                                            :disabled="!contactPhone || sending"
                                            :loading="sending === 'whatsapp_customer'"
                                            @click="$emit('send-whatsapp', { channel: 'whatsapp_customer', to: contactPhone, body: customerText })"
                                        >
                                            {{ t('FakturaView.faktura.weroni.sendWhatsApp', { to: contactPhone || '–' }) }}
                                        </v-btn>
                                        <v-btn size="small" variant="text" prepend-icon="mdi-content-copy" @click="copy(customerText, 'copied')">
                                            {{ t('FakturaView.faktura.weroni.copy') }}
                                        </v-btn>
                                    </div>
                                </v-window-item>
                                <v-window-item value="insurer">
                                    <v-text-field
                                        v-model="insurerSubject"
                                        :label="t('FakturaView.faktura.weroni.subject')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details
                                        class="mb-2"
                                    />
                                    <v-textarea
                                        v-model="insurerText"
                                        variant="outlined"
                                        density="compact"
                                        auto-grow
                                        rows="10"
                                        hide-details
                                    />
                                    <div class="d-flex flex-wrap ga-2 mt-2">
                                        <v-btn
                                            size="small"
                                            variant="tonal"
                                            color="primary"
                                            prepend-icon="mdi-email-outline"
                                            :disabled="sending"
                                            :loading="sending === 'email_insurer'"
                                            @click="$emit('send-email', { channel: 'email_insurer', to: '', subject: insurerSubject, body: insurerText })"
                                        >
                                            {{ t('FakturaView.faktura.weroni.sendEmailInsurer') }}
                                        </v-btn>
                                        <v-btn size="small" variant="text" prepend-icon="mdi-content-copy" @click="copy(insurerSubject + '\n\n' + insurerText, 'copied')">
                                            {{ t('FakturaView.faktura.weroni.copy') }}
                                        </v-btn>
                                    </div>
                                </v-window-item>
                            </v-window>
                        </v-card>

                        <div class="text-caption text-medium-emphasis mt-3">
                            <v-icon size="x-small" class="mr-1">mdi-information-outline</v-icon>{{ t('FakturaView.faktura.weroni.disclaimer') }}
                        </div>
                    </template>
                </template>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="onClose">{{ t('FakturaView.common.close') }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { defineComponent, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AiModelButton from '@/core/components/ai-model-button.vue'
import weroniIcon36 from '@/assets/weroni/weroni-36.png'
import weroniIcon56 from '@/assets/weroni/weroni-56.png'
import * as toasts from '@/core/utils/toasts.js'

export default defineComponent({
    name: 'WeroniReviewDialog',

    components: { AiModelButton },

    props: {
        modelValue: { type: Boolean, default: false },
        loading: { type: Boolean, default: false },
        sending: { type: String, default: '' },
        error: { type: String, default: '' },
        review: { type: Object, default: null },
        // Analyse liegt vor, aber noch keine Prüfung
        pending: { type: Boolean, default: false },
        createdAt: { type: String, default: '' },
        communicated: { type: Array, default: () => [] },
        customer: { type: Object, default: null },
        contactEmail: { type: String, default: '' },
        contactPhone: { type: String, default: '' },
        orderNumber: { type: String, default: '' }
    },

    emits: ['update:modelValue', 'close', 'rerun', 'import-gutachten', 'send-email', 'send-whatsapp', 'copied', 'clear-error'],

    setup(props, { emit }) {
        const { t } = useI18n()

        const tab = ref('customer')
        const customerText = ref('')
        const insurerSubject = ref('')
        const insurerText = ref('')

        // Texte von Weroni in editierbare Felder übernehmen, sobald eine (neue) Prüfung da ist
        watch(() => props.review, (r) => {
            customerText.value = r?.customer_message || ''
            insurerSubject.value = r?.insurer_letter?.subject || ''
            insurerText.value = r?.insurer_letter?.body || ''
        }, { immediate: true })

        const customerSubject = computed(() => t('FakturaView.faktura.weroni.customerSubject', { number: props.orderNumber }))

        const ampelType = computed(() => ({ gruen: 'success', gelb: 'warning', rot: 'error' })[props.review?.ampel] || 'info')
        const ampelColor = computed(() => ({ gruen: 'success', gelb: 'warning', rot: 'error' })[props.review?.ampel] || 'grey')
        const verdictLabel = computed(() => {
            const keys = {
                reparatur: 'FakturaView.faktura.weroni.verdictRepair',
                reparatur_130: 'FakturaView.faktura.weroni.verdictRepair130',
                totalschaden: 'FakturaView.faktura.weroni.verdictTotalLoss',
                rueckfrage: 'FakturaView.faktura.weroni.verdictQuestion'
            }
            return t(keys[props.review?.verdict] || keys.rueckfrage)
        })

        function checkIcon(status) {
            return { ok: 'mdi-check-circle', warn: 'mdi-alert-circle', fail: 'mdi-close-circle', info: 'mdi-information' }[status] || 'mdi-information'
        }
        function checkColor(status) {
            return { ok: 'success', warn: 'warning', fail: 'error', info: 'info' }[status] || 'grey'
        }
        function channelIcon(channel) {
            return { email_customer: 'mdi-email-outline', whatsapp_customer: 'mdi-whatsapp', email_insurer: 'mdi-shield-account-outline', copied: 'mdi-content-copy' }[channel] || 'mdi-send'
        }
        function channelLabel(channel) {
            return t('FakturaView.faktura.weroni.channels.' + (channel || 'copied'))
        }

        function formatCurrency(value) {
            return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(value || 0)
        }

        async function copy(text, channel) {
            try {
                await navigator.clipboard.writeText(text || '')
                toasts.success(t('FakturaView.faktura.weroni.copied'))
                emit('copied', channel)
            } catch (e) {
                toasts.error(t('FakturaView.faktura.weroni.copyError'))
            }
        }

        function onClose() {
            emit('close')
            emit('update:modelValue', false)
        }

        return {
            t,
            weroniIcon36,
            weroniIcon56,
            tab,
            customerText,
            customerSubject,
            insurerSubject,
            insurerText,
            ampelType,
            ampelColor,
            verdictLabel,
            checkIcon,
            checkColor,
            channelIcon,
            channelLabel,
            formatCurrency,
            copy,
            onClose
        }
    }
})
</script>

<style scoped>
.weroni-review__header {
    display: flex;
    align-items: center;
    padding: var(--oserp-gap) var(--oserp-gap-lg);
    background: var(--oserp-section-head-bg);
}

.weroni-review__avatar {
    border-radius: 50%;
}

.weroni-review__thinking {
    animation: weroni-pulse 1.6s ease-in-out infinite;
}

@keyframes weroni-pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.08); opacity: 0.75; }
}

.weroni-review__prose {
    white-space: pre-line;
}

.weroni-review__claims {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.weroni-review__claims td {
    padding: var(--oserp-gap-xs) 0;
    border-bottom: 1px solid var(--oserp-border-soft);
    vertical-align: top;
}

.weroni-review__claims tfoot td {
    border-bottom: none;
    padding-top: var(--oserp-gap-sm);
}
</style>
