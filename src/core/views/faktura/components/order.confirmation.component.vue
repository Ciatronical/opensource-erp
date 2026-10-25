<!-- src/core/views/faktura/components/order.confirmation.component.vue -->
<!--
    Bestätigung eines Verkaufsauftrags, neben Versand- und Rechnungsstatus.
    Daten: confirmation_status aus getFakturaData (SQL-Funktion
    oe_confirmation_status) — bestätigt ja/nein, wie/wann/von wem, Betrag
    damals und die offenen Vorschläge der KI aus Anruf, WhatsApp und E-Mail.

    Ein schlichter Vuetify-Alert (tonal), je nach Stand:
      - Vorschlag „Zusage erkannt“ (info) mit Zitat → Bestätigen / Verwerfen
      - Vorschlag „Absage erkannt“ (error) mit Zitat → Zur Kenntnis
      - Auftragseingang (warning) → Bestätigen
      - Bestätigt (success) mit Kanal, Zeitpunkt, Mitarbeiter; ist der Auftrag
        seitdem teurer geworden, steht das dabei → neu bestätigen
-->
<template>
    <v-alert
        :type="state.type"
        variant="tonal"
        density="compact"
        :icon="state.icon"
        class="h-100 order-confirmation"
    >
        <template #title>
            <span class="d-flex align-center flex-wrap ga-2">
                {{ t(state.title) }}
                <v-chip v-if="moreSuggestions > 0" size="x-small" variant="flat" :color="state.type">+{{ moreSuggestions }}</v-chip>
            </span>
        </template>

        <!-- Vorschlag der KI: Zitat, Kanal, Zeitpunkt, Bedingung -->
        <template v-if="suggestion">
            <div v-if="suggestion.quote" class="order-confirmation__quote">„{{ suggestion.quote }}“</div>
            <div class="d-flex flex-wrap align-center ga-1 mt-1">
                <v-chip size="small" variant="tonal" :color="state.type" class="order-confirmation__chip">
                    <v-icon start size="16">{{ channelIcon(suggestion.channel) }}</v-icon>
                    <span class="font-weight-medium">{{ t('FakturaView.faktura.confirmation.channel.' + suggestion.channel) }}</span>
                    <span class="order-confirmation__sep">·</span>
                    <span>{{ formatWhen(suggestion.agreed_at) }}</span>
                </v-chip>
                <v-btn
                    v-if="suggestion.channel === 'phone' || suggestion.channel === 'whatsapp'"
                    size="small"
                    variant="text"
                    :color="state.type"
                    :prepend-icon="suggestion.channel === 'phone' ? 'mdi-play-circle' : 'mdi-whatsapp'"
                    @click="emit('open-source', suggestion)"
                >
                    {{ t(suggestion.channel === 'phone' ? 'FakturaView.faktura.confirmation.playCall' : 'FakturaView.faktura.confirmation.openChat') }}
                </v-btn>
            </div>
            <div v-if="suggestion.conditions" class="text-body-2 mt-1">
                <v-icon size="16" class="me-1">mdi-alert-outline</v-icon>{{ t('FakturaView.faktura.confirmation.condition', { condition: suggestion.conditions }) }}
            </div>
            <div v-if="uncertain" class="text-caption text-medium-emphasis mt-1">
                {{ t('FakturaView.faktura.confirmation.uncertain') }}
            </div>
        </template>

        <!-- Bestätigt -->
        <template v-else-if="confirmed">
            <div v-if="confirmation" class="d-flex flex-wrap align-center ga-1 mt-1">
                <v-chip size="small" variant="tonal" color="success" class="order-confirmation__chip">
                    <v-icon start size="16">{{ channelIcon(confirmation.channel) }}</v-icon>
                    <span class="font-weight-medium">{{ t('FakturaView.faktura.confirmation.channel.' + confirmation.channel) }}</span>
                    <span class="order-confirmation__sep">·</span>
                    <span>{{ formatWhen(confirmation.agreed_at) }}</span>
                    <template v-if="confirmedBy">
                        <span class="order-confirmation__sep">·</span>
                        <span>{{ confirmedBy }}</span>
                    </template>
                </v-chip>
                <!-- Von der KI erkannt: Quelle (Aufnahme bzw. Chat) bleibt erreichbar -->
                <v-btn
                    v-if="confirmation.source_ref && (confirmation.channel === 'phone' || confirmation.channel === 'whatsapp')"
                    size="small"
                    variant="text"
                    color="success"
                    :prepend-icon="confirmation.channel === 'phone' ? 'mdi-play-circle' : 'mdi-whatsapp'"
                    @click="emit('open-source', confirmation)"
                >
                    {{ t(confirmation.channel === 'phone' ? 'FakturaView.faktura.confirmation.playCall' : 'FakturaView.faktura.confirmation.openChat') }}
                </v-btn>
            </div>
            <div v-else class="text-body-2">{{ t('FakturaView.faktura.confirmation.notRecorded') }}</div>
            <div v-if="confirmation?.quote" class="order-confirmation__quote mt-1">„{{ confirmation.quote }}“</div>
            <div v-if="confirmation?.note" class="text-body-2 mt-1">
                <v-icon size="16" class="me-1">mdi-note-text-outline</v-icon>{{ confirmation.note }}
            </div>
            <div v-if="confirmation?.conditions" class="text-body-2 mt-1">
                <v-icon size="16" class="me-1">mdi-alert-outline</v-icon>{{ t('FakturaView.faktura.confirmation.condition', { condition: confirmation.conditions }) }}
            </div>
            <div v-if="amountIncrease > 0" class="text-body-2 text-warning font-weight-medium mt-1">
                <v-icon size="16" class="me-1">mdi-trending-up</v-icon>{{ t('FakturaView.faktura.confirmation.amountIncreased', {
                    diff: formatAmount(amountIncrease), amount: formatAmount(confirmation.amount) }) }}
            </div>
        </template>

        <!-- Auftragseingang -->
        <div v-else class="text-body-2">{{ t('FakturaView.faktura.confirmation.openHint') }}</div>

        <!-- Aktionen unter dem Inhalt — rechts daneben würden sie Zitat und Chips zusammendrücken -->
        <div class="d-flex flex-wrap align-center ga-2 mt-2">
            <template v-if="suggestion && suggestion.intent === 'accept'">
                <v-btn variant="flat" size="small" color="info" prepend-icon="mdi-check" :disabled="busy" @click="emit('confirm', suggestion)">
                    {{ t('FakturaView.faktura.confirmation.confirm') }}
                </v-btn>
                <v-btn variant="text" size="small" color="info" :disabled="busy" @click="emit('dismiss', suggestion)">
                    {{ t('FakturaView.faktura.confirmation.dismiss') }}
                </v-btn>
            </template>
            <v-btn v-else-if="suggestion" variant="flat" size="small" color="error" prepend-icon="mdi-check" :disabled="busy" @click="emit('dismiss', suggestion)">
                {{ t('FakturaView.faktura.confirmation.acknowledge') }}
            </v-btn>
            <v-btn
                v-else-if="confirmed && (amountIncrease > 0 || !confirmation)"
                variant="tonal"
                size="small"
                :color="amountIncrease > 0 ? 'warning' : 'success'"
                prepend-icon="mdi-handshake-outline"
                :disabled="busy"
                @click="emit('confirm', null)"
            >
                {{ t(confirmation ? 'FakturaView.faktura.confirmation.reconfirm' : 'FakturaView.faktura.confirmation.record') }}
            </v-btn>
            <v-btn v-else-if="!confirmed" variant="flat" size="small" color="warning" prepend-icon="mdi-handshake-outline" :disabled="busy" @click="emit('confirm', null)">
                {{ t('FakturaView.faktura.confirmation.confirm') }}
            </v-btn>
        </div>

        <!-- Bestätigt: neu bestätigen oder zurücknehmen -->
        <template v-if="confirmed" #append>
            <v-menu location="bottom end">
                <template #activator="{ props: menu }">
                    <v-btn v-bind="menu" icon="mdi-dots-vertical" variant="text" size="small" density="comfortable" color="success" :disabled="busy" :title="t('FakturaView.faktura.confirmation.more')" />
                </template>
                <v-list density="compact">
                    <v-list-item prepend-icon="mdi-handshake-outline" :title="t('FakturaView.faktura.confirmation.reconfirm')" @click="emit('confirm', null)" />
                    <v-list-item prepend-icon="mdi-undo-variant" :title="t('FakturaView.faktura.confirmation.revoke')" @click="emit('revoke')" />
                </v-list>
            </v-menu>
        </template>
    </v-alert>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { confirmationChannelIcon as channelIcon } from '@/core/utils/orderConfirmation.js'

const props = defineProps({
    /** confirmation_status aus getFakturaData: {confirmed, amount, confirmation, suggestions[]} */
    status: { type: Object, required: true },
    /** Aktion läuft — Knöpfe sperren */
    busy: { type: Boolean, default: false },
})

const emit = defineEmits(['confirm', 'dismiss', 'revoke', 'open-source'])

const { t, locale } = useI18n()

const confirmed = computed(() => !!props.status.confirmed)
const confirmation = computed(() => props.status.confirmation || null)
// Vorschläge zählen nur, solange der Auftrag offen ist — der neueste steht oben
const suggestions = computed(() => confirmed.value ? [] : (props.status.suggestions || []))
const suggestion = computed(() => suggestions.value[0] || null)
const moreSuggestions = computed(() => Math.max(0, suggestions.value.length - 1))
const uncertain = computed(() => suggestion.value && Number(suggestion.value.confidence) < 0.7)

const state = computed(() => {
    if (suggestion.value?.intent === 'accept')  return { type: 'info',    icon: 'mdi-message-question-outline', title: 'FakturaView.faktura.confirmation.suggestedAccept' }
    if (suggestion.value?.intent === 'decline') return { type: 'error',   icon: 'mdi-close-octagon-outline',    title: 'FakturaView.faktura.confirmation.suggestedDecline' }
    if (confirmed.value)                        return { type: 'success', icon: 'mdi-handshake-outline',        title: 'FakturaView.faktura.confirmation.confirmed' }
    return { type: 'warning', icon: 'mdi-clock-outline', title: 'FakturaView.faktura.confirmation.open' }
})

const confirmedBy = computed(() => {
    if (!confirmation.value) return ''
    if (confirmation.value.auto) return t('FakturaView.faktura.confirmation.auto')
    return confirmation.value.employee_name || ''
})

// Ist der Auftrag seit der Bestätigung teurer geworden? (Brutto, auf den Cent)
const amountIncrease = computed(() => {
    const then = confirmation.value?.amount
    if (then === null || then === undefined) return 0
    const diff = Math.round((Number(props.status.amount || 0) - Number(then)) * 100) / 100
    return diff > 0 ? diff : 0
})

function formatAmount(value) {
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(Number(value || 0))
}

function formatWhen(value) {
    if (!value) return ''
    const d = new Date(value)
    if (isNaN(d.getTime())) return String(value)
    return d.toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
.order-confirmation :deep(.v-alert-title) {
    font-size: 1rem;
    font-weight: 600;
}

.order-confirmation :deep(.v-alert__content) {
    align-self: flex-start;
    min-width: 0;
}

.order-confirmation__quote {
    font-style: italic;
    overflow-wrap: anywhere;
}

.order-confirmation__chip {
    max-width: 100%;
}

.order-confirmation__chip :deep(.v-chip__content) {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

.order-confirmation__sep {
    margin: 0 6px;
    opacity: 0.5;
}
</style>
