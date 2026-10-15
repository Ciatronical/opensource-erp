<!-- src/core/views/config/tabs/parts-search.config.vue -->
<!--
    Artikelsuche in Belegen: Probesuche mit den Gewichten, die gerade in den
    Feldern darüber stehen (auch ungespeichert) — so sieht man sofort, wie sich
    eine Änderung auf die Vorschlagsliste auswirkt. Dazu der Neuaufbau der
    Verwendungsstatistik (parts_usage_oserp), die sonst Trigger pflegen.
-->
<template>
    <v-card variant="outlined" class="pa-4" style="max-width: 720px">
        <div class="d-flex align-center mb-3">
            <v-icon size="32" color="primary" class="mr-3">mdi-magnify-scan</v-icon>
            <div>
                <div class="text-subtitle-1 font-weight-medium">{{ t('crm_fields.partsSearchPreview.title') }}</div>
                <div class="text-caption text-grey">{{ t('crm_fields.partsSearchPreview.description') }}</div>
            </div>
        </div>

        <v-text-field
            id="parts-search-preview-term"
            v-model="term"
            :label="t('crm_fields.partsSearchPreview.placeholder')"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="compact"
            hide-details
            clearable
            :loading="loading"
            autocomplete="off"
            @update:model-value="onTerm"
        />

        <v-list v-if="results.length" density="compact" class="mt-2 ps-preview">
            <template v-for="(r, i) in results" :key="r.id">
                <v-divider v-if="i > 0 && results[i - 1].match_tier !== r.match_tier" class="ps-preview__tier" />
                <v-list-item>
                    <template #prepend>
                        <v-chip size="x-small" label variant="tonal" :color="tierColor(r.match_tier)" class="ps-preview__chip">
                            {{ t('crm_fields.partsSearchPreview.tier' + r.match_tier) }}
                        </v-chip>
                    </template>
                    <v-list-item-title>{{ r.description }}</v-list-item-title>
                    <v-list-item-subtitle>
                        {{ r.partnumber }} · {{ t('crm_fields.partsSearchPreview.' + (r.part_type === 'service' ? 'service' : 'part')) }}
                    </v-list-item-subtitle>
                    <template #append>
                        <span class="text-caption text-grey text-no-wrap">
                            {{ t('crm_fields.partsSearchPreview.uses', { n: r.total_uses, recent: r.recent_uses }) }}
                            · {{ r.score }} {{ t('crm_fields.partsSearchPreview.score') }}
                        </span>
                    </template>
                </v-list-item>
            </template>
        </v-list>
        <div v-else-if="searched && !loading" class="text-caption text-grey mt-3">
            {{ t('crm_fields.partsSearchPreview.noHits') }}
        </div>

        <v-divider class="my-4" />

        <div class="d-flex align-center flex-wrap" style="gap: 12px">
            <div class="flex-grow-1">
                <div class="text-subtitle-2">{{ t('crm_fields.partsSearchPreview.rebuildTitle') }}</div>
                <div class="text-caption text-grey">{{ t('crm_fields.partsSearchPreview.rebuildDescription') }}</div>
            </div>
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-counter" :loading="rebuilding" @click="rebuild">
                {{ t('crm_fields.partsSearchPreview.rebuildBtn') }}
            </v-btn>
        </div>
        <v-alert v-if="rebuilt" type="success" variant="tonal" density="compact" class="mt-3">
            {{ t('crm_fields.partsSearchPreview.rebuildDone', {
                parts: fmt(rebuilt.parts_with_usage), uses: fmt(rebuilt.total_uses), rows: fmt(rebuilt.rows_built)
            }) }}
        </v-alert>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mt-3">{{ error }}</v-alert>
    </v-card>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { partsSearchWeights } from '@/core/utils/partsSearch.js';

const props = defineProps({
    crmDefaults: { type: Object, required: true }
});

const { t, locale } = useI18n();
const term = ref('');
const results = ref([]);
const loading = ref(false);
const searched = ref(false);
const rebuilding = ref(false);
const rebuilt = ref(null);
const error = ref('');
let timer = null;
let lastTerm = '';

const fmt = n => Number(n || 0).toLocaleString(locale.value);

function tierColor(tier) {
    return { 3000: 'primary', 2000: 'teal', 1000: 'orange', 0: 'grey' }[tier] || 'grey';
}

function onTerm(value) {
    clearTimeout(timer);
    const q = String(value || '').trim();
    if (q.length < 2) {
        results.value = [];
        searched.value = false;
        return;
    }
    timer = setTimeout(() => search(q), 200);
}

async function search(q) {
    lastTerm = q;
    loading.value = true;
    error.value = '';
    try {
        const resp = await axios.post('/api/parts/', {
            action: 'findParts',
            term: q,
            taxzone: 0,
            ...partsSearchWeights(key => props.crmDefaults[key])
        });
        if (lastTerm !== q) return; // inzwischen weitergetippt
        results.value = resp.data.success ? (resp.data.payload.parts || []) : [];
        if (!resp.data.success) error.value = resp.data.text;
    } catch (e) {
        error.value = e.message;
    } finally {
        if (lastTerm === q) {
            loading.value = false;
            searched.value = true;
        }
    }
}

async function rebuild() {
    rebuilding.value = true;
    rebuilt.value = null;
    error.value = '';
    try {
        const resp = await axios.post('/api/parts/', { action: 'rebuildPartsUsage' });
        if (resp.data.success) {
            rebuilt.value = resp.data.payload.results;
            if (lastTerm) search(lastTerm);
        } else {
            error.value = resp.data.text;
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        rebuilding.value = false;
    }
}
</script>

<style scoped>
.ps-preview {
    border: 1px solid rgba(var(--v-border-color), 0.2);
    border-radius: 4px;
    max-height: 360px;
    overflow-y: auto;
}
.ps-preview__chip {
    min-width: 92px;
    justify-content: center;
    margin-right: 12px;
}
.ps-preview__tier {
    border-style: dashed;
}
</style>
