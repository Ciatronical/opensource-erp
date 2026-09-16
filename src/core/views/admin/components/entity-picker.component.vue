<!-- src/core/views/admin/components/entity-picker.component.vue -->
<!--
    Auswahlliste mit Kästchen: Firmen, Gruppen oder Benutzer zuordnen.
    v-model = Array der gewählten IDs. Bei vielen Einträgen mit Suchfeld,
    immer mit "Alle/Keine". Einträge können eine Warnung tragen (z. B. Gruppe
    wirkt in keiner gewählten Firma) und gesperrt sein (z. B. man selbst).
-->
<template>
    <div class="entity-picker">
        <div class="d-flex align-center flex-wrap ga-2 mb-2">
            <span class="text-subtitle-2">{{ label }}</span>
            <v-chip size="x-small" variant="tonal" color="primary">{{ modelValue.length }} / {{ items.length }}</v-chip>
            <v-spacer />
            <v-btn size="x-small" variant="text" :disabled="modelValue.length === items.length" @click="selectAll">
                {{ t('AdminView.common.all') }}
            </v-btn>
            <v-btn size="x-small" variant="text" :disabled="modelValue.length === 0" @click="selectNone">
                {{ t('AdminView.common.none') }}
            </v-btn>
        </div>
        <p v-if="hint" class="text-caption text-medium-emphasis mb-2">{{ hint }}</p>

        <v-text-field
            v-if="items.length > 8"
            v-model="search"
            :placeholder="t('AdminView.common.search')"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="compact"
            hide-details
            clearable
            class="mb-2"
        />

        <v-sheet border rounded class="picker-list">
            <v-list density="compact" select-strategy="leaf" class="py-0">
                <v-list-item
                    v-for="item in filteredItems"
                    :key="item.id"
                    :disabled="lockedIds.includes(item.id)"
                    @click="toggle(item.id)"
                >
                    <template #prepend>
                        <v-checkbox-btn
                            :model-value="modelValue.includes(item.id)"
                            :disabled="lockedIds.includes(item.id)"
                            color="primary"
                            density="compact"
                            @click.stop="toggle(item.id)"
                        />
                    </template>
                    <v-list-item-title>
                        {{ item.title }}
                        <v-chip v-if="item.badge" size="x-small" variant="tonal" class="ms-1" :color="item.badgeColor || 'grey'">{{ item.badge }}</v-chip>
                    </v-list-item-title>
                    <v-list-item-subtitle v-if="item.subtitle">{{ item.subtitle }}</v-list-item-subtitle>
                    <template v-if="item.warning && modelValue.includes(item.id)" #append>
                        <v-tooltip :text="item.warning" location="top">
                            <template #activator="{ props: tip }">
                                <v-icon v-bind="tip" color="warning" size="small">mdi-alert-outline</v-icon>
                            </template>
                        </v-tooltip>
                    </template>
                </v-list-item>
                <v-list-item v-if="filteredItems.length === 0" disabled>
                    <v-list-item-title class="text-caption text-medium-emphasis">
                        {{ items.length === 0 ? emptyText : t('AdminView.common.noResults') }}
                    </v-list-item-title>
                </v-list-item>
            </v-list>
        </v-sheet>
        <div v-if="warning && modelValue.length === 0" class="d-flex align-center ga-1 text-warning text-caption mt-1">
            <v-icon size="small">mdi-alert-outline</v-icon>{{ warning }}
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    // [{ id, title, subtitle?, badge?, badgeColor?, warning? }]
    items: { type: Array, default: () => [] },
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    warning: { type: String, default: '' },
    emptyText: { type: String, default: '' },
    lockedIds: { type: Array, default: () => [] }
});
const emit = defineEmits(['update:modelValue']);

const search = ref('');

const filteredItems = computed(() => {
    const q = (search.value || '').trim().toLowerCase();
    if (!q) return props.items;
    return props.items.filter(i => (i.title + ' ' + (i.subtitle || '')).toLowerCase().includes(q));
});

function toggle(id) {
    if (props.lockedIds.includes(id)) return;
    const next = props.modelValue.includes(id)
        ? props.modelValue.filter(x => x !== id)
        : [...props.modelValue, id];
    emit('update:modelValue', next);
}
function selectAll() {
    emit('update:modelValue', props.items.map(i => i.id));
}
function selectNone() {
    emit('update:modelValue', props.items.filter(i => props.lockedIds.includes(i.id)).map(i => i.id));
}
</script>

<style scoped>
.picker-list {
    max-height: 260px;
    overflow-y: auto;
}
</style>
