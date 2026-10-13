<!-- src/core/views/config/tabs/master-lists.tab.vue -->
<!--
    Stammdaten-Listen: Einheiten, Kundentypen, Warengruppen, Preisgruppen,
    Preisfaktoren, Abteilungen, Zahlungs-/Lieferbedingungen, Sprachen,
    Projekttypen, Projektstatus, Anreden. Darstellung aus masterListsConfig.js,
    Daten aus getMasterLists (eine Abfrage für alle Listen).
-->
<template>
    <div class="master-lists">
        <p class="master-lists__intro">{{ t('masterLists.description') }}</p>

        <div class="master-lists__toolbar">
            <v-text-field
                v-model="filter"
                :placeholder="t('masterLists.filter')"
                prepend-inner-icon="mdi-magnify"
                variant="outlined"
                density="compact"
                clearable
                hide-details
                class="master-lists__filter"
            />
            <v-btn
                variant="text"
                size="small"
                prepend-icon="mdi-refresh"
                :loading="loading"
                @click="load"
            >
                {{ t('masterLists.reload') }}
            </v-btn>
        </div>

        <v-skeleton-loader v-if="loading && !loaded" type="list-item-two-line@6" />

        <v-expansion-panels
            v-else
            v-model="openPanels"
            multiple
            variant="accordion"
        >
            <v-expansion-panel
                v-for="def in visibleLists"
                :key="def.key"
                :value="def.key"
                :data-panel="def.key"
            >
                <v-expansion-panel-title>
                    <div class="master-lists__head">
                        <v-icon color="primary" size="large">{{ def.icon }}</v-icon>
                        <div>
                            <div class="master-lists__head-title">{{ t(`masterLists.${def.key}.title`) }}</div>
                            <div class="master-lists__head-sub">
                                {{ t('masterLists.entries', { n: (lists[def.key] || []).length }) }}
                                <span v-if="obsoleteCount(def.key)"> · {{ t('masterLists.obsoleteCount', { n: obsoleteCount(def.key) }) }}</span>
                            </div>
                        </div>
                    </div>
                </v-expansion-panel-title>

                <v-expansion-panel-text>
                    <v-alert type="info" variant="tonal" density="compact" class="master-lists__hint">
                        {{ t(`masterLists.${def.key}.description`) }}
                    </v-alert>

                    <div v-if="(lists[def.key] || []).length" class="master-lists__list">
                        <v-list density="compact" class="master-lists__items">
                            <VueDraggable
                                v-model="lists[def.key]"
                                handle=".drag-handle"
                                :disabled="!def.sortable"
                                @end="saveOrder(def)"
                            >
                                <v-list-item
                                    v-for="item in lists[def.key]"
                                    :key="item.id"
                                    :class="{ 'master-lists__item--obsolete': item.obsolete }"
                                    class="master-lists__item"
                                    @click="openEdit(def, item)"
                                >
                                    <template #prepend>
                                        <v-icon
                                            v-if="def.sortable"
                                            class="drag-handle master-lists__drag"
                                            size="small"
                                        >mdi-drag</v-icon>
                                        <v-icon size="small" class="master-lists__item-icon">{{ def.icon }}</v-icon>
                                    </template>

                                    <v-list-item-title class="master-lists__item-title">
                                        {{ item[def.label] }}
                                        <v-chip
                                            v-for="(chip, i) in chipsFor(def, item)"
                                            :key="i"
                                            size="x-small"
                                            variant="tonal"
                                            :prepend-icon="chip.icon"
                                            class="master-lists__chip"
                                        >{{ chip.text }}</v-chip>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="item.obsolete" class="master-lists__obsolete">
                                        {{ t('masterLists.obsolete') }}
                                    </v-list-item-subtitle>

                                    <template #append>
                                        <v-tooltip v-if="item.used" :text="t('masterLists.inUse')" location="top">
                                            <template #activator="{ props: tip }">
                                                <v-icon v-bind="tip" size="small" class="master-lists__lock">mdi-link-variant</v-icon>
                                            </template>
                                        </v-tooltip>
                                        <v-btn icon variant="text" size="small" @click.stop="openEdit(def, item)">
                                            <v-icon size="small">mdi-pencil</v-icon>
                                        </v-btn>
                                        <v-btn
                                            icon
                                            variant="text"
                                            size="small"
                                            :disabled="item.used"
                                            @click.stop="remove(def, item)"
                                        >
                                            <v-icon size="small">mdi-delete-outline</v-icon>
                                        </v-btn>
                                    </template>
                                </v-list-item>
                            </VueDraggable>
                        </v-list>
                    </div>
                    <p v-else class="master-lists__empty">{{ t('masterLists.empty') }}</p>

                    <v-btn color="primary" variant="elevated" prepend-icon="mdi-plus" @click="openEdit(def, null)">
                        {{ t(`masterLists.${def.key}.create_new`) }}
                    </v-btn>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>

        <p v-if="loaded && !visibleLists.length" class="master-lists__empty">{{ t('masterLists.noMatch') }}</p>

        <!-- Bearbeiten / Anlegen -->
        <v-dialog v-model="dialog" max-width="760px" persistent scrollable>
            <v-card v-if="editDef">
                <v-card-title class="master-lists__dialog-title">
                    <v-icon class="master-lists__dialog-icon">{{ editDef.icon }}</v-icon>
                    {{ editItem?.id ? t(`masterLists.${editDef.key}.edit_title`) : t(`masterLists.${editDef.key}.create_title`) }}
                </v-card-title>
                <v-card-text>
                    <master-list-edit
                        :definition="editDef"
                        :item="editItem"
                        :siblings="lists[editDef.key] || []"
                        :saving="saving"
                        @save="save"
                        @cancel="dialog = false"
                        @delete="remove(editDef, editItem)"
                    />
                </v-card-text>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { VueDraggable } from 'vue-draggable-plus';
import { oserpStore } from '@/core/stores/oserp.store.js';
import * as toasts from '@/core/utils/toasts.js';
import * as alerts from '@/core/utils/alerts.js';
import masterLists from './masterListsConfig.js';
import MasterListEdit from './edit/master-list-edit.vue';

const { t } = useI18n();
const store = oserpStore();

// openPanel: von der Einstellungssuche gesetzt, um direkt in eine Liste zu springen
const props = defineProps({
    openPanel: { type: String, default: '' },
});

const lists = ref({});
const loading = ref(false);
const loaded = ref(false);
const saving = ref(false);
const filter = ref('');
const openPanels = ref([]);

const dialog = ref(false);
const editDef = ref(null);
const editItem = ref(null);

const visibleLists = computed(() => {
    const q = (filter.value || '').trim().toLowerCase();
    if (!q) return masterLists;
    return masterLists.filter(def =>
        t(`masterLists.${def.key}.title`).toLowerCase().includes(q)
        || def.keywords.some(k => k.includes(q))
        || (lists.value[def.key] || []).some(item => String(item[def.label] || '').toLowerCase().includes(q))
    );
});

watch(() => props.openPanel, (panel) => {
    if (!panel) return;
    if (!openPanels.value.includes(panel)) openPanels.value.push(panel);
    nextTick(() => {
        const el = document.querySelector(`[data-panel="${panel}"]`);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}, { immediate: true });

onMounted(load);

function obsoleteCount(key) {
    return (lists.value[key] || []).filter(i => i.obsolete).length;
}

function chipsFor(def, item) {
    return (def.chips(item, t) || []).filter(Boolean);
}

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.post('/api/oserp_config/', { action: 'getMasterLists' });
        if (!data.success) throw new Error(data.payload || data.text);
        lists.value = data.payload.lists || {};
        loaded.value = true;
    } catch (e) {
        toasts.error(t('masterLists.loadFailed'), { text: e.message });
    } finally {
        loading.value = false;
    }
}

function openEdit(def, item) {
    editDef.value = def;
    editItem.value = item ? { ...item } : { ...(def.defaults || {}) };
    dialog.value = true;
}

async function save(item) {
    saving.value = true;
    try {
        const { data } = await axios.post('/api/oserp_config/', {
            action: 'saveMasterListItem',
            list: editDef.value.key,
            item,
        });
        if (!data.success) {
            if (data.text === 'DUPLICATE') {
                toasts.warning(t('masterLists.duplicate'));
            } else {
                toasts.error(t('masterLists.saveFailed'), { text: data.payload || data.text });
            }
            return;
        }
        dialog.value = false;
        toasts.success(t('masterLists.saved'));
        await afterChange();
    } catch (e) {
        toasts.error(t('masterLists.saveFailed'), { text: e.message });
    } finally {
        saving.value = false;
    }
}

async function remove(def, item) {
    if (!item?.id || item.used) return;
    const answer = await alerts.question(
        t('masterLists.deleteConfirm', { name: item[def.label] }),
        t(`masterLists.${def.key}.title`),
        t('delete'),
        t('cancel')
    );
    if (!answer.isConfirmed) return;

    try {
        const { data } = await axios.post('/api/oserp_config/', {
            action: 'deleteMasterListItem',
            list: def.key,
            id: item.id,
        });
        if (!data.success) {
            toasts.warning(data.text === 'IN_USE' ? t('masterLists.inUse') : (data.payload || data.text));
            return;
        }
        dialog.value = false;
        toasts.success(t('masterLists.deleted'));
        await afterChange();
    } catch (e) {
        toasts.error(t('masterLists.deleteFailed'), { text: e.message });
    }
}

async function saveOrder(def) {
    if (!def.sortable) return;
    try {
        const { data } = await axios.post('/api/oserp_config/', {
            action: 'reorderMasterList',
            list: def.key,
            ids: (lists.value[def.key] || []).map(i => i.id),
        });
        if (!data.success) throw new Error(data.payload || data.text);
        await store.loadCompanyConfig();
    } catch (e) {
        toasts.error(t('masterLists.saveFailed'), { text: e.message });
        await load();
    }
}

/** Listen neu laden und den Store auffrischen, damit Auswahlfelder anderswo die Änderung sehen. */
async function afterChange() {
    await Promise.all([load(), store.loadCompanyConfig()]);
}
</script>

<style scoped>
.master-lists__intro {
    margin: 0 0 var(--oserp-gap-lg);
    color: var(--oserp-text-muted);
}
.master-lists__head-sub,
.master-lists__empty {
    color: var(--oserp-text-muted);
    margin: 0;
}
.master-lists__toolbar {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap);
    margin-bottom: var(--oserp-gap);
}
.master-lists__filter {
    max-width: 360px;
}
.master-lists__head {
    display: flex;
    align-items: center;
    gap: var(--oserp-gap);
}
.master-lists__head-title {
    font-size: var(--oserp-section-title-size);
    font-weight: var(--oserp-section-title-weight);
}
.master-lists__head-sub {
    font-size: 0.8rem;
}
.master-lists__hint,
.master-lists__list {
    margin-bottom: var(--oserp-gap);
}
.master-lists__items {
    background: var(--oserp-surface-soft);
    border: 1px solid var(--oserp-border-soft);
    border-radius: var(--oserp-radius);
    padding: 0;
}
.master-lists__item {
    cursor: pointer;
}
.master-lists__item + .master-lists__item {
    border-top: 1px solid var(--oserp-border-soft);
}
.master-lists__item--obsolete .master-lists__item-title {
    color: var(--oserp-text-muted);
    text-decoration: line-through;
}
.master-lists__drag {
    cursor: move;
    margin-inline-end: var(--oserp-gap-xs);
}
.master-lists__item-icon {
    margin-inline-end: var(--oserp-gap-sm);
}
.master-lists__item-title {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-xs);
    white-space: normal;
}
.master-lists__chip {
    text-decoration: none;
}
.master-lists__obsolete {
    color: rgb(var(--v-theme-error));
}
.master-lists__lock {
    color: var(--oserp-text-muted);
    margin-inline-end: var(--oserp-gap-xs);
}
.master-lists__empty {
    margin-bottom: var(--oserp-gap);
}
.master-lists__dialog-title {
    display: flex;
    align-items: center;
}
.master-lists__dialog-icon {
    margin-inline-end: var(--oserp-gap-sm);
}
</style>
