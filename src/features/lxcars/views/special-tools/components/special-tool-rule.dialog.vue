<!-- src/features/lxcars/views/special-tools/components/special-tool-rule.dialog.vue -->
<!--
    Regel-Editor mit Live-Vorschau: Während der Benutzer Kriterien setzt,
    zeigt die rechte Seite, wie viele und welche Fahrzeuge die Regel trifft.
    So sieht man sofort, ob "VW 1.9 Diesel" zu breit oder zu eng ist.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="1100" :fullscreen="mobile" scrollable @update:model-value="close">
        <v-card rounded="lg">
            <v-card-title class="d-flex align-center pa-4 pb-2">
                <v-icon color="primary" class="mr-2">mdi-filter-variant</v-icon>
                <span class="text-subtitle-1 font-weight-bold">
                    {{ rule?.id ? t('SpecialToolsView.rules.edit') : t('SpecialToolsView.rules.add') }}
                </span>
                <v-spacer />
                <v-btn icon variant="text" size="small" @click="close(false)"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-divider />

            <v-card-text class="pt-4">
                <v-row>
                    <!-- ── Kriterien ─────────────────────────────────────── -->
                    <v-col cols="12" md="7">
                        <v-text-field
                            v-model="form.label"
                            :label="t('SpecialToolsView.rules.label')"
                            variant="outlined" density="compact" autofocus class="mb-3"
                            :error-messages="labelError"
                        />

                        <v-btn-toggle v-model="form.mode" mandatory divided variant="outlined" density="comfortable" class="mb-4 w-100">
                            <v-btn value="include" class="flex-grow-1" color="primary">
                                <v-icon start size="small">mdi-plus-circle-outline</v-icon>
                                {{ t('SpecialToolsView.rules.modeInclude') }}
                            </v-btn>
                            <v-btn value="exclude" class="flex-grow-1" color="error">
                                <v-icon start size="small">mdi-minus-circle-outline</v-icon>
                                {{ t('SpecialToolsView.rules.modeExclude') }}
                            </v-btn>
                        </v-btn-toggle>

                        <v-combobox
                            v-model="form.criteria.makes"
                            :items="makeItems"
                            :label="t('SpecialToolsView.rules.criteria.makes')"
                            :hint="t('SpecialToolsView.rules.criteria.makesHint')" persistent-hint
                            multiple chips closable-chips variant="outlined" density="compact" class="mb-3"
                        />
                        <v-combobox
                            v-model="form.criteria.hsn"
                            :items="hsnItems" item-title="title" item-value="value" :return-object="false"
                            :label="t('SpecialToolsView.rules.criteria.hsn')"
                            multiple chips closable-chips variant="outlined" density="compact" class="mb-3"
                        />
                        <v-combobox
                            v-model="form.criteria.models"
                            :label="t('SpecialToolsView.rules.criteria.models')"
                            :hint="t('SpecialToolsView.rules.criteria.modelsHint')" persistent-hint
                            multiple chips closable-chips variant="outlined" density="compact" class="mb-3"
                        />
                        <v-combobox
                            v-model="form.criteria.engine_codes"
                            :items="engineItems"
                            :label="t('SpecialToolsView.rules.criteria.engine_codes')"
                            :hint="t('SpecialToolsView.rules.criteria.engineHint')" persistent-hint
                            multiple chips closable-chips variant="outlined" density="compact" class="mb-3"
                        />
                        <v-row dense>
                            <v-col cols="12" sm="6">
                                <v-select
                                    v-model="form.criteria.fuel"
                                    :items="options.fuels || []"
                                    :label="t('SpecialToolsView.rules.criteria.fuel')"
                                    multiple chips variant="outlined" density="compact"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-select
                                    v-model="form.criteria.vehicle_types"
                                    :items="vehicleTypeItems" item-title="title" item-value="value"
                                    :label="t('SpecialToolsView.rules.criteria.vehicle_types')"
                                    multiple chips variant="outlined" density="compact"
                                />
                            </v-col>
                        </v-row>

                        <v-row dense class="mt-1">
                            <v-col v-for="f in rangeFields" :key="f.key" cols="6" sm="4">
                                <v-text-field
                                    v-model="form.criteria[f.key]"
                                    :label="f.label" :suffix="f.suffix"
                                    type="number" min="0" variant="outlined" density="compact" hide-details
                                />
                            </v-col>
                        </v-row>

                        <v-textarea
                            v-model="form.reason"
                            :label="t('SpecialToolsView.rules.reason')"
                            variant="outlined" density="compact" rows="2" auto-grow class="mt-4"
                        />
                    </v-col>

                    <!-- ── Vorschau ──────────────────────────────────────── -->
                    <v-col cols="12" md="5">
                        <v-card variant="tonal" :color="previewColor" rounded="lg" class="preview-card">
                            <v-card-text>
                                <div class="d-flex align-center ga-2 mb-2">
                                    <v-icon size="small">mdi-eye-outline</v-icon>
                                    <span class="text-caption font-weight-bold text-uppercase">{{ t('SpecialToolsView.rules.preview.title') }}</span>
                                    <v-spacer />
                                    <v-progress-circular v-if="previewLoading" indeterminate size="16" width="2" />
                                </div>

                                <div v-if="criteriaEmpty" class="text-body-2">
                                    <v-icon size="small" class="mr-1">mdi-alert-outline</v-icon>
                                    {{ t('SpecialToolsView.rules.preview.all') }}
                                </div>
                                <template v-else-if="preview">
                                    <div class="text-h5 font-weight-bold mb-1">
                                        {{ preview.count > 0
                                            ? t('SpecialToolsView.rules.preview.count', { n: preview.count })
                                            : t('SpecialToolsView.rules.preview.none') }}
                                    </div>
                                    <div v-if="preview.makes?.length" class="d-flex flex-wrap ga-1 mb-3">
                                        <v-chip v-for="m in preview.makes" :key="m.make" size="x-small" variant="flat">
                                            {{ m.make }} · {{ m.n }}
                                        </v-chip>
                                    </div>
                                    <div v-if="preview.sample?.length" class="text-caption font-weight-bold mb-1">
                                        {{ t('SpecialToolsView.rules.preview.sample') }}
                                    </div>
                                    <v-list v-if="preview.sample?.length" density="compact" bg-color="transparent" class="py-0">
                                        <v-list-item v-for="v in preview.sample" :key="v.c_id" class="px-0" min-height="36">
                                            <v-list-item-title class="text-body-2">
                                                <span class="font-weight-medium">{{ v.c_ln }}</span>
                                                — {{ v.make }} {{ v.model }}
                                            </v-list-item-title>
                                            <v-list-item-subtitle class="text-caption">
                                                {{ vehicleMeta(v) }}
                                            </v-list-item-subtitle>
                                        </v-list-item>
                                    </v-list>
                                </template>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="close(false)">{{ t('SpecialToolsView.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" :loading="saving" @click="save">
                    <v-icon start size="small">mdi-check</v-icon>
                    {{ t('SpecialToolsView.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import { useSpecialTools, emptyCriteria, compactCriteria } from '@/features/lxcars/composables/useSpecialTools.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    toolId: { type: Number, required: true },
    /** Bestehende Regel zum Bearbeiten, null = neue Regel */
    rule: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const { t } = useI18n()
const { mobile } = useDisplay()
const api = useSpecialTools()

const form = reactive({ label: '', mode: 'include', reason: '', criteria: emptyCriteria() })
const labelError = ref('')
const saving = ref(false)
const preview = ref(null)
const previewLoading = ref(false)

const makeItems = computed(() => [...new Set((props.options.makes || []).map(m => m.make).filter(Boolean))])
const hsnItems = computed(() => {
    const seen = new Map()
    for (const m of props.options.makes || []) {
        if (m.hsn && !seen.has(m.hsn)) seen.set(m.hsn, { title: `${m.hsn} · ${m.make}`, value: m.hsn })
    }
    return [...seen.values()]
})
const engineItems = computed(() => (props.options.engine_codes || []).map(c => c.code))
const vehicleTypeItems = computed(() => (props.options.vehicle_types || []).map(v => ({
    title: t(`SpecialToolsView.vehicleTypes.${v}`, v), value: v,
})))
const rangeFields = computed(() => [
    { key: 'ccm_from',  label: `${t('SpecialToolsView.rules.criteria.ccm')} ${t('SpecialToolsView.rules.criteria.from')}`, suffix: 'ccm' },
    { key: 'ccm_to',    label: t('SpecialToolsView.rules.criteria.to'), suffix: 'ccm' },
    { key: 'kw_from',   label: `${t('SpecialToolsView.rules.criteria.kw')} ${t('SpecialToolsView.rules.criteria.from')}`, suffix: 'kW' },
    { key: 'kw_to',     label: t('SpecialToolsView.rules.criteria.to'), suffix: 'kW' },
    { key: 'year_from', label: `${t('SpecialToolsView.rules.criteria.year')} ${t('SpecialToolsView.rules.criteria.from')}`, suffix: '' },
    { key: 'year_to',   label: t('SpecialToolsView.rules.criteria.to'), suffix: '' },
])

const criteriaEmpty = computed(() => Object.keys(compactCriteria(form.criteria)).length === 0)
const previewColor = computed(() => {
    if (criteriaEmpty.value) return 'warning'
    if (!preview.value) return 'primary'
    return preview.value.count > 0 ? (form.mode === 'exclude' ? 'error' : 'primary') : 'grey'
})

function vehicleMeta(v) {
    return [v.engine_code, v.fuel, v.ccm ? `${v.ccm} ccm` : null, v.kw ? `${v.kw} kW` : null, v.year]
        .filter(Boolean).join(' · ')
}

function reset() {
    const r = props.rule
    form.label = r?.label || ''
    form.mode = r?.mode === 'exclude' ? 'exclude' : 'include'
    form.reason = r?.reason || ''
    form.criteria = { ...emptyCriteria(), ...(r?.criteria || {}) }
    labelError.value = ''
    preview.value = null
}

watch(() => props.modelValue, (open) => { if (open) { reset(); runPreview() } }, { immediate: true })

// Live-Vorschau mit kurzer Verzögerung, damit nicht jeder Tastendruck lädt
let timer = null
watch(() => form.criteria, () => {
    if (timer) clearTimeout(timer)
    timer = setTimeout(runPreview, 400)
}, { deep: true })

async function runPreview() {
    if (!props.modelValue || criteriaEmpty.value) { preview.value = null; return }
    previewLoading.value = true
    try { preview.value = await api.previewCriteria(compactCriteria(form.criteria), 8) }
    catch (e) { preview.value = null }
    finally { previewLoading.value = false }
}

async function save() {
    if (!form.label.trim()) { labelError.value = t('SpecialToolsView.rules.labelRequired'); return }
    saving.value = true
    try {
        await api.saveRule({
            id: props.rule?.id || 0,
            tool_id: props.toolId,
            label: form.label.trim(),
            mode: form.mode,
            reason: form.reason,
            active: props.rule?.active ?? true,
            criteria: compactCriteria(form.criteria),
        })
        alerts.success(t('SpecialToolsView.rules.saved'))
        emit('saved')
        close(false)
    } catch (e) {
        alerts.error(e.message)
    } finally {
        saving.value = false
    }
}

function close(v) { emit('update:modelValue', !!v) }
</script>

<style scoped>
.preview-card { position: sticky; top: 0; }
</style>
