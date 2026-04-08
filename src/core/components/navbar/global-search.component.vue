<!-- src/core/components/navbar/global-search.component.vue -->

<template>
    <v-autocomplete
        ref="searchRef"
        v-model="selected"
        v-model:search="searchQuery"
        :items="searchResults"
        :loading="loading"
        :placeholder="t('GlobalSearch.placeholder')"
        :hide-no-data="!searchQuery || searchQuery.length < 2 || loading"
        variant="outlined"
        density="compact"
        hide-details
        autocomplete="off"
        item-value="_key"
        :item-title="formatItem"
        return-object
        no-filter
        clearable
        prepend-inner-icon="mdi-magnify"
        @update:search="onSearch"
        @update:model-value="onSelect"
        @focus="onFocus"
        @keydown.enter="onEnter"
    >
        <template #item="{ props: itemProps, item }">
            <!-- Gruppen-Header -->
            <v-list-subheader
                v-if="item.raw._groupHeader"
                class="text-caption font-weight-bold"
            >
                {{ item.raw._groupLabel }}
            </v-list-subheader>

            <!-- Verlauf löschen -->
            <v-list-item
                v-else-if="item.raw._clearHistory"
                class="text-center"
                @click.stop="clearHistory"
            >
                <v-list-item-title class="text-caption text-grey">
                    {{ t('GlobalSearch.clearHistory') }}
                </v-list-item-title>
            </v-list-item>

            <!-- Ergebnis-Item -->
            <v-list-item
                v-else
                v-bind="itemProps"
                :title="null"
                :prepend-icon="typeConfig[item.raw.type]?.icon"
            >
                <v-list-item-title class="text-body-2">
                    {{ item.raw.title }}
                </v-list-item-title>
                <v-list-item-subtitle v-if="item.raw.subtitle" class="text-caption">
                    {{ item.raw.subtitle }}
                </v-list-item-subtitle>

                <template #prepend>
                    <v-icon :color="typeConfig[item.raw.type]?.color" size="small" class="mr-3">
                        {{ typeConfig[item.raw.type]?.icon }}
                    </v-icon>
                </template>
            </v-list-item>
        </template>

        <template #no-data>
            <v-list-item v-if="searchQuery && searchQuery.length >= 2 && !loading">
                <v-list-item-title class="text-body-2 text-grey">
                    {{ t('GlobalSearch.noResults') }}
                </v-list-item-title>
            </v-list-item>
        </template>
    </v-autocomplete>
</template>

<script>
import { defineComponent, ref, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useViewHistory } from '@/core/composables/useViewHistory.js'
import axios from 'axios'

export default defineComponent({
    name: 'GlobalSearchComponent',

    setup() {
        const { t } = useI18n()
        const router = useRouter()
        const oserp = oserpStore()
        const { saveToHistory, clearHistory: clearHistoryData, getHistoryItems } = useViewHistory()

        const searchRef = ref(null)
        const selected = ref(null)
        const searchQuery = ref('')
        const searchResults = ref([])
        const loading = ref(false)
        let searchTimeout = null
        let abortController = null

        const typeConfig = {
            customer: { icon: 'mdi-account', color: 'primary' },
            vendor: { icon: 'mdi-truck', color: 'orange' },
            invoice: { icon: 'mdi-file-document', color: 'success' },
            order: { icon: 'mdi-clipboard-text', color: 'info' },
            quotation: { icon: 'mdi-file-edit', color: 'purple' },
            article: { icon: 'mdi-package-variant', color: 'brown' },
            vehicle: { icon: 'mdi-car', color: 'teal' },
            wiki: { icon: 'mdi-book-open-page-variant', color: 'deep-purple' }
        }

        const typeLabels = {
            customer: 'GlobalSearch.customers',
            vendor: 'GlobalSearch.vendors',
            invoice: 'GlobalSearch.invoices',
            order: 'GlobalSearch.orders',
            quotation: 'GlobalSearch.quotations',
            article: 'GlobalSearch.articles',
            vehicle: 'GlobalSearch.vehicles',
            wiki: 'GlobalSearch.wiki'
        }

        function clearHistory() {
            clearHistoryData()
            searchResults.value = []
            selected.value = null
        }

        function showHistory() {
            searchResults.value = getHistoryItems(t)
        }

        // === Suche ===

        function onFocus() {
            if (!searchQuery.value || searchQuery.value.length < 2) {
                showHistory()
            }
        }

        function onSearch(val) {
            if (searchTimeout) clearTimeout(searchTimeout)
            if (!val || val.length < 2) {
                showHistory()
                loading.value = false
                return
            }

            loading.value = true
            searchTimeout = setTimeout(async () => {
                // Vorherigen Request abbrechen damit kein alter Response das Dropdown neu rendert
                if (abortController) abortController.abort()
                abortController = new AbortController()

                try {
                    const features = []
                    if (oserp.isLxCars()) features.push('lxcars')

                    const response = await axios.post('/api/search/', {
                        action: 'globalSearch',
                        query: val,
                        features: features
                    }, { signal: abortController.signal })

                    abortController = null
                    if (response.data.success) {
                        searchResults.value = buildGroupedResults(response.data.payload || [])
                    } else {
                        searchResults.value = []
                    }
                } catch (e) {
                    if (axios.isCancel(e) || e.name === 'CanceledError') return
                    console.error('Global search error:', e)
                    searchResults.value = []
                } finally {
                    loading.value = false
                }
            }, 300)
        }

        function buildGroupedResults(items) {
            const grouped = []
            let lastType = null

            for (const item of items) {
                if (item.type !== lastType) {
                    grouped.push({
                        _groupHeader: true,
                        _groupLabel: t(typeLabels[item.type] || item.type),
                        _key: '_header_' + item.type,
                        id: '_header_' + item.type,
                        type: item.type
                    })
                    lastType = item.type
                }
                grouped.push({ ...item, _key: item.type + '_' + item.id })
            }

            return grouped
        }

        function formatItem(item) {
            if (!item || typeof item === 'string') return item
            return item.title || ''
        }

        function onEnter() {
            const selectable = searchResults.value.filter(i => !i._groupHeader && !i._clearHistory)
            if (selectable.length === 1) {
                onSelect(selectable[0])
            }
        }

        function onSelect(item) {
            if (!item || item._groupHeader || item._clearHistory) {
                selected.value = null
                return
            }

            saveToHistory(item)

            if (item.route) {
                router.push(item.route)
            }

            // Laufenden Request und Timer abbrechen
            if (abortController) { abortController.abort(); abortController = null }
            if (searchTimeout) clearTimeout(searchTimeout)
            searchResults.value = []
            selected.value = null
            nextTick(() => {
                searchQuery.value = ''
                // Blur damit das Dropdown nicht erneut aufgeht
                searchRef.value?.blur()
            })
        }

        return {
            t,
            searchRef,
            selected,
            searchQuery,
            searchResults,
            loading,
            typeConfig,
            onSearch,
            onSelect,
            onFocus,
            onEnter,
            formatItem,
            clearHistory
        }
    }
})
</script>

<style scoped>
:deep(.v-field) {
    background: white;
    border-radius: 8px;
}

:deep(.v-field--focused) {
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
}
</style>

<style>
.v-autocomplete__content {
    max-height: none !important;
}

.v-autocomplete__content .v-list {
    max-height: none !important;
    overflow: visible !important;
}
</style>
