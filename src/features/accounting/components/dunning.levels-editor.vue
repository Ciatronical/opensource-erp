<!-- src/features/accounting/components/dunning.levels-editor.vue -->
<!--
    Mahnstufen, Konten und Mindestbetrag — die Konfiguration des Mahnwesens.

    Gibt es noch keine Stufen, steht ein Dreistufen-Vorschlag schon ausgefüllt
    da (Zahlungserinnerung, 1. und 2. Mahnung); ein Klick auf Speichern reicht.
    Brieftext und E-Mail-Text jeder Stufe werden hier gepflegt — niemand muss
    dafür eine Druckvorlage anfassen.
-->
<template>
    <div>
        <v-alert v-if="!hadLevels" type="info" variant="tonal" density="comfortable" icon="mdi-lightbulb-on-outline"
                 class="mb-4" :title="t('AccountingView.dunning.config.defaultsTitle')"
                 :text="t('AccountingView.dunning.config.defaultsText')" />

        <v-expansion-panels v-model="openPanel" variant="accordion" class="mb-4">
            <v-expansion-panel v-for="(level, idx) in form.levels" :key="idx" :value="idx">
                <v-expansion-panel-title>
                    <div class="d-flex align-center ga-3 flex-wrap w-100 pr-2">
                        <v-avatar size="28" :color="level.active ? 'primary' : 'grey'" class="text-caption font-weight-bold">
                            {{ level.dunning_level }}
                        </v-avatar>
                        <span class="font-weight-medium">{{ level.dunning_description || t('AccountingView.dunning.config.level', { n: level.dunning_level }) }}</span>
                        <v-chip size="x-small" variant="tonal">{{ t('AccountingView.dunning.config.termsChip', { days: level.terms }) }}</v-chip>
                        <v-chip v-if="Number(level.fee) > 0" size="x-small" variant="tonal" color="warning">{{ money(level.fee) }}</v-chip>
                        <v-chip v-if="Number(level.interest_rate) > 0" size="x-small" variant="tonal" color="warning">{{ level.interest_rate }} %</v-chip>
                        <v-chip v-if="level.email" size="x-small" variant="tonal" color="info" prepend-icon="mdi-email-outline">{{ t('AccountingView.dunning.run.channelEmail') }}</v-chip>
                        <v-chip v-if="!level.active" size="x-small" variant="tonal">{{ t('AccountingView.dunning.config.inactive') }}</v-chip>
                        <v-spacer />
                        <v-chip v-if="level.in_use" size="x-small" variant="text" prepend-icon="mdi-lock-outline">{{ t('AccountingView.dunning.config.inUse') }}</v-chip>
                    </div>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <v-row dense>
                        <v-col cols="12" md="5">
                            <v-text-field v-model="level.dunning_description" :label="t('AccountingView.dunning.config.description')"
                                          variant="outlined" density="compact" hide-details="auto" :rules="[v => !!v || t('AccountingView.dunning.config.required')]" />
                        </v-col>
                        <v-col cols="6" md="2">
                            <v-text-field v-model.number="level.terms" type="number" min="0" :label="t('AccountingView.dunning.config.terms')"
                                          variant="outlined" density="compact" :hint="t('AccountingView.dunning.config.termsHint')" persistent-hint suffix="Tage" />
                        </v-col>
                        <v-col cols="6" md="2">
                            <v-text-field v-model.number="level.payment_terms" type="number" min="0" :label="t('AccountingView.dunning.config.paymentTerms')"
                                          variant="outlined" density="compact" :hint="t('AccountingView.dunning.config.paymentTermsHint')" persistent-hint suffix="Tage" />
                        </v-col>
                        <v-col cols="6" md="3" class="d-flex flex-column ga-1 pt-1">
                            <v-switch v-model="level.active" color="primary" density="compact" hide-details inset :label="t('AccountingView.dunning.config.active')" />
                            <v-switch v-model="level.email" color="primary" density="compact" hide-details inset :label="t('AccountingView.dunning.config.email')" />
                        </v-col>

                        <v-col cols="6" md="2">
                            <v-text-field v-model.number="level.fee" type="number" min="0" step="0.5" :label="t('AccountingView.dunning.config.fee')"
                                          variant="outlined" density="compact" hide-details="auto" suffix="€" />
                        </v-col>
                        <v-col cols="6" md="2">
                            <v-text-field v-model.number="level.interest_rate" type="number" min="0" step="0.5" :label="t('AccountingView.dunning.config.interestRate')"
                                          variant="outlined" density="compact" :hint="t('AccountingView.dunning.config.interestHint')" persistent-hint suffix="% p. a." />
                        </v-col>
                        <v-col cols="12" md="5">
                            <v-switch v-model="level.create_invoices_for_fees" color="primary" density="compact" inset
                                      :label="t('AccountingView.dunning.config.createFeeInvoice')"
                                      :hint="t('AccountingView.dunning.config.createFeeInvoiceHint')" persistent-hint
                                      :disabled="Number(level.fee) <= 0 && Number(level.interest_rate) <= 0" />
                        </v-col>
                        <v-col cols="12" md="3">
                            <v-combobox v-model="level.template" :items="templates" :label="t('AccountingView.dunning.config.template')"
                                        variant="outlined" density="compact" clearable :hint="t('AccountingView.dunning.config.templateHint')" persistent-hint />
                        </v-col>

                        <v-col cols="12">
                            <v-textarea v-model="level.letter_text" :label="t('AccountingView.dunning.config.letterText')" variant="outlined"
                                        density="compact" rows="2" auto-grow :hint="t('AccountingView.dunning.config.letterTextHint')" persistent-hint />
                        </v-col>

                        <template v-if="level.email">
                            <v-col cols="12" md="5">
                                <v-text-field v-model="level.email_subject" :label="t('AccountingView.dunning.config.emailSubject')"
                                              variant="outlined" density="compact" hide-details="auto" />
                            </v-col>
                            <v-col cols="12" md="7">
                                <v-textarea v-model="level.email_body" :label="t('AccountingView.dunning.config.emailBody')" variant="outlined"
                                            density="compact" rows="3" auto-grow hide-details="auto" />
                            </v-col>
                            <v-col cols="12" class="d-flex align-center flex-wrap ga-1">
                                <span class="text-caption text-medium-emphasis mr-1">{{ t('AccountingView.dunning.config.placeholders') }}</span>
                                <v-chip v-for="ph in placeholders" :key="ph" size="x-small" variant="outlined" class="font-mono"
                                        @click="level.email_body = (level.email_body || '') + ' <%' + ph + '%>'">
                                    &lt;%{{ ph }}%&gt;
                                </v-chip>
                                <v-switch v-model="level.email_attachment" color="primary" density="compact" hide-details inset class="ml-auto"
                                          :label="t('AccountingView.dunning.config.emailAttachment')" />
                            </v-col>
                        </template>

                        <v-col cols="12" class="d-flex justify-end">
                            <v-btn size="small" variant="text" color="error" class="text-none" :disabled="level.in_use"
                                   @click="removeLevel(idx)">
                                <v-icon start size="small">mdi-delete-outline</v-icon>
                                {{ t('AccountingView.dunning.config.removeLevel') }}
                            </v-btn>
                        </v-col>
                    </v-row>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>

        <v-btn variant="tonal" size="small" class="text-none mb-6" @click="addLevel">
            <v-icon start size="small">mdi-plus</v-icon>
            {{ t('AccountingView.dunning.config.addLevel') }}
        </v-btn>

        <!-- Konten und Allgemeines -->
        <h3 class="text-subtitle-1 font-weight-medium mb-1">{{ t('AccountingView.dunning.config.accountsTitle') }}</h3>
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('AccountingView.dunning.config.accountsInfo') }}</p>

        <v-row dense>
            <v-col cols="12" md="4">
                <v-autocomplete v-model="form.fee_chart_id" :items="config.income_charts" item-title="title" item-value="id"
                                :label="t('AccountingView.dunning.config.feeAccount')" variant="outlined" density="compact" clearable hide-details="auto" />
            </v-col>
            <v-col cols="12" md="4">
                <v-autocomplete v-model="form.interest_chart_id" :items="config.income_charts" item-title="title" item-value="id"
                                :label="t('AccountingView.dunning.config.interestAccount')" variant="outlined" density="compact" clearable hide-details="auto" />
            </v-col>
            <v-col cols="12" md="4">
                <v-autocomplete v-model="form.ar_chart_id" :items="config.ar_charts" item-title="title" item-value="id"
                                :label="t('AccountingView.dunning.config.arAccount')" variant="outlined" density="compact" clearable hide-details="auto" />
            </v-col>
            <v-col v-if="hasSuggestion" cols="12">
                <v-btn size="small" variant="text" class="text-none" @click="applySuggestion">
                    <v-icon start size="small">mdi-auto-fix</v-icon>
                    {{ t('AccountingView.dunning.config.suggest') }}
                </v-btn>
            </v-col>

            <v-col cols="12" md="3">
                <v-text-field v-model.number="form.min_amount" type="number" min="0" step="1" :label="t('AccountingView.dunning.config.minAmount')"
                              variant="outlined" density="compact" suffix="€" :hint="t('AccountingView.dunning.config.minAmountHint')" persistent-hint />
            </v-col>
            <v-col cols="12" md="4">
                <v-text-field v-model="form.sender_name" :label="t('AccountingView.dunning.config.senderName')"
                              variant="outlined" density="compact" :hint="t('AccountingView.dunning.config.senderNameHint')" persistent-hint />
            </v-col>
            <v-col cols="12" md="5">
                <v-select v-model="form.creator" :items="creatorItems" :label="t('AccountingView.dunning.config.creator')"
                          variant="outlined" density="compact" hide-details="auto" />
            </v-col>
        </v-row>

        <v-alert v-if="!config.email_configured" type="warning" variant="tonal" density="compact" class="mt-3"
                 icon="mdi-email-off-outline" :text="t('AccountingView.dunning.config.emailNotConfigured')" />

        <div class="d-flex align-center ga-3 mt-4">
            <v-btn color="primary" class="text-none" :loading="saving" :disabled="!valid" @click="$emit('save', payload())">
                <v-icon start>mdi-content-save-outline</v-icon>
                {{ t('AccountingView.dunning.config.save') }}
            </v-btn>
            <span v-if="!valid" class="text-caption text-error">{{ t('AccountingView.dunning.config.required') }}</span>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    config: { type: Object, required: true },
    saving: { type: Boolean, default: false }
})
defineEmits(['save'])

const { t } = useI18n()

const placeholders = ['name', 'customernumber', 'dunning', 'dunning_id', 'dunning_date', 'dunning_duedate', 'invnumbers', 'open_total', 'fee', 'interest', 'total', 'company']

const openPanel = ref(0)
const hadLevels = computed(() => (props.config.levels || []).length > 0)
const templates = computed(() => props.config.templates || [])

const creatorItems = computed(() => [
    { value: 'current_employee', title: t('AccountingView.dunning.config.creatorCurrent') },
    { value: 'invoice_employee', title: t('AccountingView.dunning.config.creatorInvoice') }
])

/**
 * Der Dreistufen-Vorschlag für den Erststart. Fristen und Beträge sind die
 * gängigen Werte im deutschen Mahnwesen; Texte kommen aus den Sprachdateien.
 */
function defaultLevels() {
    const preset = [
        { terms: 7,  payment_terms: 10, fee: 0,  interest_rate: 0, email: true,  fees: false },
        { terms: 14, payment_terms: 10, fee: 5,  interest_rate: 9, email: false, fees: true },
        { terms: 14, payment_terms: 7,  fee: 10, interest_rate: 9, email: false, fees: true }
    ]
    return preset.map((p, i) => ({
        id: 0,
        dunning_level: i + 1,
        dunning_description: t(`AccountingView.dunning.config.defaults.level${i + 1}.description`),
        active: true,
        email: p.email,
        terms: p.terms,
        payment_terms: p.payment_terms,
        fee: p.fee,
        interest_rate: p.interest_rate,
        email_subject: t(`AccountingView.dunning.config.defaults.level${i + 1}.emailSubject`),
        email_body: t(`AccountingView.dunning.config.defaults.level${i + 1}.emailBody`),
        email_attachment: true,
        create_invoices_for_fees: p.fees,
        template: '',
        letter_text: t(`AccountingView.dunning.config.defaults.level${i + 1}.letterText`),
        in_use: false
    }))
}

const form = ref(buildForm())

function buildForm() {
    const c = props.config
    const levels = (c.levels || []).length
        ? c.levels.map(l => ({ ...l, fee: Number(l.fee), interest_rate: Number(l.interest_rate) }))
        : defaultLevels()
    return {
        levels,
        fee_chart_id:      c.accounts?.fee_chart_id || null,
        interest_chart_id: c.accounts?.interest_chart_id || null,
        ar_chart_id:       c.accounts?.ar_chart_id || c.suggested?.ar_chart_id || null,
        sender_name:       c.accounts?.sender_name || '',
        creator:           c.accounts?.creator || 'current_employee',
        min_amount:        Number(c.min_amount || 0)
    }
}

watch(() => props.config, () => { form.value = buildForm() }, { deep: true })

const hasSuggestion = computed(() => !!(props.config.suggested?.fee_chart_id || props.config.suggested?.interest_chart_id))

function applySuggestion() {
    const s = props.config.suggested || {}
    if (s.fee_chart_id)      form.value.fee_chart_id = s.fee_chart_id
    if (s.interest_chart_id) form.value.interest_chart_id = s.interest_chart_id
    if (s.ar_chart_id)       form.value.ar_chart_id = s.ar_chart_id
}

function addLevel() {
    const last = form.value.levels[form.value.levels.length - 1]
    form.value.levels.push({
        id: 0,
        dunning_level: (last?.dunning_level || 0) + 1,
        dunning_description: '',
        active: true,
        email: false,
        terms: 14,
        payment_terms: last?.payment_terms ?? 10,
        fee: last?.fee ?? 0,
        interest_rate: last?.interest_rate ?? 0,
        email_subject: '',
        email_body: '',
        email_attachment: true,
        create_invoices_for_fees: !!last?.create_invoices_for_fees,
        template: '',
        letter_text: '',
        in_use: false
    })
    openPanel.value = form.value.levels.length - 1
}

function removeLevel(idx) {
    form.value.levels.splice(idx, 1)
    form.value.levels.forEach((l, i) => { l.dunning_level = i + 1 })
}

const valid = computed(() => form.value.levels.every(l => (l.dunning_description || '').trim() !== ''))

function payload() {
    return JSON.parse(JSON.stringify(form.value))
}

function money(value) {
    return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
}
</script>

<style scoped>
.font-mono { font-family: ui-monospace, monospace; }
</style>
