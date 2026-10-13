<!-- src/core/views/config/tabs/cv-easymode.config.vue -->
<!--
    Firmenkonfiguration → CRM → Einfacher Modus → Kunde anlegen / bearbeiten

    Legt fest, was die Kunden-/Lieferantenmaske im einfachen Modus zeigt:
    Felder je Karte und Reiter. Gespeichert wird in defaults_oserp
    (cv_easymode_default, cv_easymode_fields, cv_easymode_tabs) über den
    Deep-Watcher der Elternansicht — hier wird nur crmDefaults beschrieben.
-->
<template>
    <section-card :title="t('crm_fields.easymodeCv')" icon="mdi-account-edit-outline">
        <template #actions>
            <v-chip size="small" variant="tonal" color="primary">
                {{ t('crm_fields.easymodeCvCount', { visible: selectedFields.size, total: allFields.length }) }}
            </v-chip>
        </template>
            <p class="text-body-2 text-medium-emphasis mb-3">{{ t('crm_fields.easymodeCvIntro') }}</p>

            <v-switch
                :model-value="defaultOn"
                :label="t('crm_fields.easymodeCvDefault')"
                :hint="t('crm_fields.easymodeCvDefault_help')"
                persistent-hint
                color="primary"
                density="compact"
                class="mb-4"
                @update:model-value="val => crmDefaults[DEFAULT_KEY] = !!val"
            />

            <!-- Schnellauswahl -->
            <div class="d-flex align-center flex-wrap ga-2 mb-4">
                <span class="text-caption text-medium-emphasis">{{ t('crm_fields.easymodeCvPresets') }}</span>
                <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-star-outline" @click="applyPreset('recommended')">
                    {{ t('crm_fields.easymodeCvPresetRecommended') }}
                </v-btn>
                <v-btn size="small" variant="tonal" prepend-icon="mdi-lock-outline" @click="applyPreset('minimal')">
                    {{ t('crm_fields.easymodeCvPresetMinimal') }}
                </v-btn>
                <v-btn size="small" variant="tonal" prepend-icon="mdi-select-all" @click="applyPreset('all')">
                    {{ t('crm_fields.easymodeCvPresetAll') }}
                </v-btn>
            </div>

            <!-- Felder je Karte -->
            <div class="text-subtitle-2 mb-1">{{ t('crm_fields.easymodeCvFields') }}</div>
            <div v-for="group in CV_FIELD_GROUPS" :key="group.key" class="easy-group">
                <div class="d-flex align-center ga-2 mb-1">
                    <v-icon size="small" color="primary">{{ group.icon }}</v-icon>
                    <span class="text-body-2 font-weight-medium">{{ t(group.title) }}</span>
                    <span class="text-caption text-medium-emphasis">{{ groupCount(group) }}</span>
                    <v-spacer />
                    <v-btn size="x-small" variant="text" @click="toggleGroup(group)">
                        {{ groupAllSelected(group) ? t('crm_fields.easymodeCvNone') : t('crm_fields.easymodeCvAllOfGroup') }}
                    </v-btn>
                </div>
                <div class="d-flex flex-wrap ga-2 mb-3">
                    <v-chip
                        v-for="field in group.fields"
                        :key="field.key"
                        :color="selectedFields.has(field.key) ? 'primary' : undefined"
                        :variant="selectedFields.has(field.key) ? 'flat' : 'outlined'"
                        :disabled="field.required"
                        size="small"
                        label
                        :title="field.required ? t('crm_fields.easymodeCvRequired') : ''"
                        @click="toggleField(field)"
                    >
                        <v-icon start size="x-small">
                            {{ field.required ? 'mdi-lock' : (selectedFields.has(field.key) ? 'mdi-check' : 'mdi-plus') }}
                        </v-icon>
                        {{ t(field.label) }}
                        <span v-if="field.src" class="ms-1 text-caption">({{ field.src === 'C' ? t('crm_fields.easymodeCvOnlyCustomer') : t('crm_fields.easymodeCvOnlyVendor') }})</span>
                    </v-chip>
                </div>
            </div>

            <!-- Reiter -->
            <div class="text-subtitle-2 mt-2 mb-1">{{ t('crm_fields.easymodeCvTabs') }}</div>
            <p class="text-caption text-medium-emphasis mb-2">{{ t('crm_fields.easymodeCvTabs_help') }}</p>
            <div class="d-flex flex-wrap ga-2">
                <v-chip
                    v-for="tab in CV_TABS"
                    :key="tab.key"
                    :color="selectedTabs.has(tab.key) ? 'primary' : undefined"
                    :variant="selectedTabs.has(tab.key) ? 'flat' : 'outlined'"
                    :disabled="tab.fixed"
                    size="small"
                    label
                    @click="toggleTab(tab)"
                >
                    <v-icon start size="x-small">{{ tab.fixed ? 'mdi-lock' : tab.icon }}</v-icon>
                    {{ t(tab.label) }}
                </v-chip>
            </div>
    </section-card>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import {
    CV_FIELD_GROUPS,
    CV_TABS,
    CV_ALL_FIELDS,
    CV_REQUIRED_FIELD_KEYS,
    CV_EASYMODE_DEFAULT_FIELDS,
    CV_EASYMODE_DEFAULT_TABS,
    parseFieldList,
} from '@/core/views/customer-vendor/cvFieldCatalog.js'
import {
    CV_EASYMODE_DEFAULT_KEY,
    CV_EASYMODE_FIELDS_KEY,
    CV_EASYMODE_TABS_KEY,
} from '@/core/views/customer-vendor/composables/useCvEasymode.js'

const props = defineProps({
    crmDefaults: { type: Object, required: true },
})

const { t } = useI18n()
const DEFAULT_KEY = CV_EASYMODE_DEFAULT_KEY
const allFields = CV_ALL_FIELDS
const fixedTabs = CV_TABS.filter(tab => tab.fixed).map(tab => tab.key)

// Ohne Eintrag ist der einfache Modus an — wie in der Maske selbst
const defaultOn = computed(() => {
    const v = props.crmDefaults[DEFAULT_KEY]
    if (v === null || v === undefined || v === '') return true
    return v === true || v === 'true' || v === 't' || v === '1' || v === 1
})

const selectedFields = computed(() => new Set([
    ...parseFieldList(props.crmDefaults[CV_EASYMODE_FIELDS_KEY], CV_EASYMODE_DEFAULT_FIELDS),
    ...CV_REQUIRED_FIELD_KEYS,
]))

const selectedTabs = computed(() => new Set([
    ...parseFieldList(props.crmDefaults[CV_EASYMODE_TABS_KEY], CV_EASYMODE_DEFAULT_TABS),
    ...fixedTabs,
]))

// Gespeichert wird in Katalogreihenfolge — so bleibt der Wert lesbar und stabil
function writeFields(keys) {
    const set = new Set([...keys, ...CV_REQUIRED_FIELD_KEYS])
    props.crmDefaults[CV_EASYMODE_FIELDS_KEY] = JSON.stringify(allFields.filter(f => set.has(f.key)).map(f => f.key))
}

function writeTabs(keys) {
    const set = new Set([...keys, ...fixedTabs])
    props.crmDefaults[CV_EASYMODE_TABS_KEY] = JSON.stringify(CV_TABS.filter(tab => set.has(tab.key)).map(tab => tab.key))
}

function toggleField(field) {
    if (field.required) return
    const next = new Set(selectedFields.value)
    next.has(field.key) ? next.delete(field.key) : next.add(field.key)
    writeFields(next)
}

function toggleTab(tab) {
    if (tab.fixed) return
    const next = new Set(selectedTabs.value)
    next.has(tab.key) ? next.delete(tab.key) : next.add(tab.key)
    writeTabs(next)
}

function groupAllSelected(group) {
    return group.fields.every(f => selectedFields.value.has(f.key))
}

function groupCount(group) {
    const n = group.fields.filter(f => selectedFields.value.has(f.key)).length
    return `${n}/${group.fields.length}`
}

function toggleGroup(group) {
    const next = new Set(selectedFields.value)
    if (groupAllSelected(group)) {
        group.fields.forEach(f => { if (!f.required) next.delete(f.key) })
    } else {
        group.fields.forEach(f => next.add(f.key))
    }
    writeFields(next)
}

function applyPreset(name) {
    if (name === 'all') writeFields(allFields.map(f => f.key))
    else if (name === 'minimal') writeFields([])
    else writeFields(CV_EASYMODE_DEFAULT_FIELDS)
}
</script>

<style scoped>
.easy-group + .easy-group {
    border-top: 1px solid var(--oserp-border-soft);
    padding-top: 8px;
}
</style>
