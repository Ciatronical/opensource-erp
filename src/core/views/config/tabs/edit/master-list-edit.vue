<!-- src/core/views/config/tabs/edit/master-list-edit.vue -->
<!--
    Generisches Formular für einen Eintrag einer Stammdaten-Liste. Die Felder
    kommen aus masterListsConfig.js (definition.fields); Prozentwerte werden
    hier zwischen Bruch (DB) und Prozent (Anzeige) umgerechnet.
-->
<template>
    <v-form ref="formRef" @submit.prevent="submit">
        <v-alert
            v-if="item.used"
            type="info"
            variant="tonal"
            density="compact"
            class="master-list-edit__used"
            icon="mdi-link-variant"
        >
            {{ t('masterLists.inUseHint') }}
        </v-alert>

        <v-row density="compact">
            <v-col v-for="field in definition.fields" :key="field.name" cols="12" :sm="field.cols || 12">
                <!-- Text -->
                <v-text-field
                    v-if="field.type === 'text'"
                    v-model="local[field.name]"
                    :label="label(field)"
                    :hint="hint(field)"
                    :persistent-hint="!!field.help"
                    :rules="rules(field)"
                    :disabled="locked(field)"
                    variant="outlined"
                    density="comfortable"
                />

                <!-- Mehrzeilig -->
                <v-textarea
                    v-else-if="field.type === 'textarea'"
                    v-model="local[field.name]"
                    :label="label(field)"
                    :hint="hint(field)"
                    :persistent-hint="!!field.help"
                    :disabled="locked(field)"
                    variant="outlined"
                    density="comfortable"
                    rows="2"
                    auto-grow
                />

                <!-- Zahlen (int, number, percent) -->
                <v-text-field
                    v-else-if="['int', 'number', 'percent'].includes(field.type)"
                    v-model="local[field.name]"
                    :label="label(field)"
                    :hint="hint(field)"
                    :persistent-hint="!!field.help"
                    :suffix="suffix(field)"
                    :rules="rules(field)"
                    :disabled="locked(field)"
                    type="text"
                    inputmode="decimal"
                    variant="outlined"
                    density="comfortable"
                />

                <!-- Schalter -->
                <v-switch
                    v-else-if="field.type === 'bool'"
                    v-model="local[field.name]"
                    :label="label(field)"
                    :hint="hint(field)"
                    :persistent-hint="!!field.help"
                    :disabled="locked(field)"
                    color="primary"
                    density="comfortable"
                    inset
                />

                <!-- Feste Auswahl -->
                <v-select
                    v-else-if="field.type === 'select'"
                    v-model="local[field.name]"
                    :items="selectItems(field)"
                    :label="label(field)"
                    :hint="hint(field)"
                    :persistent-hint="!!field.help"
                    :disabled="locked(field)"
                    item-title="title"
                    item-value="value"
                    variant="outlined"
                    density="comfortable"
                />

                <!-- Auswahl aus derselben Liste -->
                <v-autocomplete
                    v-else-if="field.type === 'self'"
                    v-model="local[field.name]"
                    :items="selfItems(field)"
                    :label="label(field)"
                    :hint="hint(field)"
                    :persistent-hint="!!field.help"
                    :disabled="locked(field)"
                    :item-title="field.itemValue"
                    :item-value="field.itemValue"
                    variant="outlined"
                    density="comfortable"
                    clearable
                />
            </v-col>
        </v-row>

        <v-card-actions class="master-list-edit__actions">
            <v-btn
                v-if="item.id"
                color="error"
                variant="text"
                :disabled="item.used || saving"
                @click="$emit('delete')"
            >
                {{ t('delete') }}
            </v-btn>
            <v-spacer />
            <v-btn variant="text" :disabled="saving" @click="$emit('cancel')">
                {{ t('cancel') }}
            </v-btn>
            <v-btn color="primary" variant="elevated" type="submit" :loading="saving">
                {{ t('save') }}
            </v-btn>
        </v-card-actions>
    </v-form>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, n } = useI18n();

const props = defineProps({
    definition: { type: Object, required: true },
    item: { type: Object, required: true },
    siblings: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(['save', 'cancel', 'delete']);

const formRef = ref(null);
const local = ref({});

// Anzeigekopie: Prozent als Prozentzahl, Zahlen als Text in Landesschreibweise
watch(() => props.item, (item) => {
    const copy = { ...item };
    for (const field of props.definition.fields) {
        const v = copy[field.name];
        if (field.type === 'percent') {
            copy[field.name] = (v === null || v === undefined || v === '') ? '' : formatNumber(Number(v) * 100);
        } else if (field.type === 'number') {
            copy[field.name] = (v === null || v === undefined || v === '') ? '' : formatNumber(Number(v));
        } else if (field.type === 'int') {
            copy[field.name] = (v === null || v === undefined) ? '' : String(v);
        } else if (field.type === 'bool') {
            copy[field.name] = !!v;
        } else if (field.type === 'select' && (v === null || v === undefined)) {
            copy[field.name] = '';
        }
    }
    local.value = copy;
}, { immediate: true, deep: true });

function label(field) {
    return t(`masterLists.${props.definition.key}.fields.${field.name}`);
}

function hint(field) {
    return field.help ? t(field.help) : '';
}

function suffix(field) {
    if (!field.suffix) return '';
    return field.suffix.includes('.') ? t(field.suffix) : field.suffix;
}

function locked(field) {
    return !!(field.lockedWhenUsed && props.item.used);
}

function rules(field) {
    const list = [];
    if (field.required) list.push(v => (v !== null && v !== undefined && String(v).trim() !== '') || t('masterLists.required'));
    if (['int', 'number', 'percent'].includes(field.type)) {
        list.push(v => v === '' || v === null || v === undefined || Number.isFinite(parseNumber(v)) || t('masterLists.invalidNumber'));
    }
    if (field.type === 'int') {
        list.push(v => v === '' || v === null || Number.isInteger(parseNumber(v)) || t('masterLists.invalidInteger'));
    }
    if (field.type === 'percent') {
        list.push(v => v === '' || v === null || (parseNumber(v) >= 0 && parseNumber(v) < 100) || t('masterLists.invalidPercent'));
    }
    return list;
}

function selectItems(field) {
    return field.options.map(o => ({ value: o.value, title: o.label.includes('.') ? t(o.label) : o.label }));
}

function selfItems(field) {
    return props.siblings.filter(other =>
        other.id !== props.item.id
        && (!field.filterSelf || field.filterSelf(local.value, other))
    );
}

function formatNumber(value) {
    return n(value, { maximumFractionDigits: 5, useGrouping: false });
}

/** "1.000,5" / "1,000.5" / "2,5" → Zahl; leer → null */
function parseNumber(value) {
    if (value === null || value === undefined) return null;
    let s = String(value).trim();
    if (s === '') return null;
    const lastComma = s.lastIndexOf(',');
    const lastDot = s.lastIndexOf('.');
    if (lastComma > lastDot) s = s.replace(/\./g, '').replace(',', '.');
    else s = s.replace(/,/g, '');
    return Number(s);
}

async function submit() {
    const { valid } = await formRef.value.validate();
    if (!valid) return;

    const out = { id: props.item.id };
    for (const field of props.definition.fields) {
        const v = local.value[field.name];
        if (field.type === 'percent') {
            const num = parseNumber(v);
            out[field.name] = num === null ? null : num / 100;
        } else if (field.type === 'number' || field.type === 'int') {
            out[field.name] = parseNumber(v);
        } else if (field.type === 'bool') {
            out[field.name] = !!v;
        } else {
            out[field.name] = (v === undefined) ? null : v;
        }
    }
    emit('save', out);
}
</script>

<style scoped>
.master-list-edit__used {
    margin-bottom: var(--oserp-gap);
}
.master-list-edit__actions {
    padding-inline: 0;
    padding-top: var(--oserp-gap);
}
</style>
