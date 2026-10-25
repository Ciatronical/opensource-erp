<!-- src/core/views/faktura/dialogs/order.confirm.dialog.vue -->
<!--
    Auftrag bestätigen: wie hat der Kunde zugesagt (Telefon, E-Mail, WhatsApp,
    vor Ort, Unterschrift, Kundenportal, Sonstiges), wann, und optional eine
    Notiz. Kommt der Dialog von einem Vorschlag der KI, sind Kanal und Zeitpunkt
    vorbelegt und das Zitat steht dabei. Bestätigt wird der aktuelle Betrag —
    die Auftragsmaske meldet später, wenn er steigt.

    Der zuletzt gewählte Kanal wird im Browser gemerkt (Vorbelegung beim
    nächsten Mal); ohne Speicher gilt Telefon.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="600" @update:model-value="emit('update:modelValue', $event)">
        <section-card :title="t('FakturaView.faktura.confirmation.dialogTitle')" icon="mdi-handshake-outline" :subtitle="subtitle">
            <!-- Vorschlag der KI: Zitat, Kanal und Zeitpunkt stehen fest -->
            <v-alert v-if="suggestion" type="info" variant="tonal" density="compact" icon="mdi-message-question-outline" class="mb-2">
                <div v-if="suggestion.quote" class="font-italic">„{{ suggestion.quote }}“</div>
                <div class="text-body-2 mt-1">
                    <v-icon size="16" class="me-1">{{ confirmationChannelIcon(suggestion.channel) }}</v-icon>{{ t('FakturaView.faktura.confirmation.channel.' + suggestion.channel) }}
                    · {{ formatWhen(suggestion.agreed_at) }}
                </div>
                <div v-if="suggestion.conditions" class="text-body-2 mt-1">
                    <v-icon size="16" class="me-1">mdi-alert-outline</v-icon>{{ t('FakturaView.faktura.confirmation.condition', { condition: suggestion.conditions }) }}
                </div>
            </v-alert>

            <template v-if="!suggestion">
            <div class="text-body-2 mb-2">{{ t('FakturaView.faktura.confirmation.howQuestion') }}</div>
            <v-chip-group v-model="channel" mandatory selected-class="text-primary" column>
                <v-chip
                    v-for="ch in CONFIRMATION_CHANNELS"
                    :key="ch.value"
                    :value="ch.value"
                    :prepend-icon="ch.icon"
                    variant="outlined"
                    filter
                >
                    {{ t('FakturaView.faktura.confirmation.channel.' + ch.value) }}
                </v-chip>
            </v-chip-group>
            </template>

            <v-row density="compact" class="mt-2">
                <v-col v-if="!suggestion" cols="12" sm="6">
                    <v-text-field
                        v-model="when"
                        type="datetime-local"
                        :label="t('FakturaView.faktura.confirmation.when')"
                    />
                </v-col>
                <v-col cols="12">
                    <v-textarea
                        v-model="note"
                        :label="t('FakturaView.faktura.confirmation.note')"
                        :placeholder="t('FakturaView.faktura.confirmation.notePlaceholder')"
                        rows="2"
                        auto-grow
                        autofocus
                        @keydown.ctrl.enter="submit"
                        @keydown.meta.enter="submit"
                    />
                </v-col>
            </v-row>

            <div class="text-caption text-medium-emphasis mt-2">
                {{ t('FakturaView.faktura.confirmation.amountHint', { amount: formatAmount(amount) }) }}
            </div>

            <div class="d-flex mt-4">
                <v-spacer />
                <v-btn variant="text" @click="emit('update:modelValue', false)">{{ t('FakturaView.faktura.confirmation.cancel') }}</v-btn>
                <v-btn color="success" variant="flat" prepend-icon="mdi-check" :loading="busy" @click="submit">
                    {{ t('FakturaView.faktura.confirmation.confirm') }}
                </v-btn>
            </div>
        </section-card>
    </v-dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import { CONFIRMATION_CHANNELS, confirmationChannelIcon } from '@/core/utils/orderConfirmation.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** Vorschlag der KI, der übernommen wird (null = von Hand bestätigen) */
    suggestion: { type: Object, default: null },
    /** Aktueller Bruttobetrag des Auftrags */
    amount: { type: [Number, String], default: 0 },
    /** Auftragsnummer und Kunde für die Kopfzeile */
    subtitle: { type: String, default: '' },
    busy: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'submit'])

const { t, locale } = useI18n()

const STORAGE_KEY = 'oserp.orderConfirmChannel'
const channel = ref('phone')
const when = ref('')
const note = ref('')

// datetime-local erwartet Ortszeit ohne Zone: YYYY-MM-DDTHH:mm
function toLocalInput(value) {
    const d = value ? new Date(value) : new Date()
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
}

function lastChannel() {
    try {
        const v = localStorage.getItem(STORAGE_KEY)
        return CONFIRMATION_CHANNELS.some(c => c.value === v) ? v : 'phone'
    } catch {
        return 'phone'
    }
}

watch(() => props.modelValue, (open) => {
    if (!open) return
    channel.value = props.suggestion?.channel || lastChannel()
    when.value = toLocalInput(props.suggestion?.agreed_at)
    note.value = ''
}, { immediate: true })

function submit() {
    if (props.busy) return
    if (!props.suggestion) {
        try { localStorage.setItem(STORAGE_KEY, channel.value) } catch { /* ohne Speicher keine Vorbelegung */ }
    }
    emit('submit', {
        channel: channel.value,
        agreed_at: when.value ? new Date(when.value).getTime() : Date.now(),
        note: note.value.trim(),
        suggestion_id: props.suggestion?.id || null,
    })
}

function formatWhen(value) {
    const d = new Date(value)
    if (isNaN(d.getTime())) return ''
    return d.toLocaleString(locale.value, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function formatAmount(value) {
    return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(Number(value || 0))
}
</script>
