<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Header -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-icon color="primary" class="mr-1">mdi-book-open-page-variant</v-icon>
            <h1 class="text-h6 mb-0">{{ t('WikiListView.title') }}</h1>
            <v-spacer />
            <v-btn color="primary" size="small" prepend-icon="mdi-plus" :to="{ name: 'wiki-new' }">
                {{ t('WikiListView.newPage') }}
            </v-btn>
        </div>

        <!-- Suche -->
        <v-text-field
            v-model="search"
            :label="t('WikiListView.search')"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="comfortable"
            hide-details
            clearable
            class="mb-3"
        />

        <!-- Kategorie-Filter als Chips -->
        <div v-if="categories.length" class="mb-3">
            <v-chip
                class="mr-2 mb-1"
                :color="selectedCategory === null ? 'primary' : undefined"
                :variant="selectedCategory === null ? 'elevated' : 'outlined'"
                size="small"
                @click="selectedCategory = null"
            >
                {{ t('WikiListView.allCategories') }}
            </v-chip>
            <v-chip
                v-for="cat in categories"
                :key="cat.id"
                class="mr-2 mb-1"
                :color="selectedCategory === cat.id ? 'primary' : undefined"
                :variant="selectedCategory === cat.id ? 'elevated' : 'outlined'"
                size="small"
                @click="selectedCategory = cat.id"
            >
                {{ cat.name }}
                <span class="text-caption ml-1">({{ cat.page_count }})</span>
            </v-chip>
        </div>

        <!-- Skeleton beim Laden -->
        <v-row v-if="loading">
            <v-col v-for="n in 6" :key="n" cols="12" sm="6" md="4">
                <v-skeleton-loader type="article" />
            </v-col>
        </v-row>

        <!-- Leerer Zustand -->
        <v-card v-else-if="filteredPages.length === 0" variant="outlined" class="text-center pa-8">
            <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-book-open-page-variant-outline</v-icon>
            <h3 class="text-h6 text-medium-emphasis mb-2">{{ t('WikiListView.noPages') }}</h3>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('WikiListView.noPagesHint') }}</p>
            <v-btn color="primary" prepend-icon="mdi-plus" :to="{ name: 'wiki-new' }">
                {{ t('WikiListView.newPage') }}
            </v-btn>
        </v-card>

        <!-- Artikel-Karten -->
        <v-row v-else>
            <v-col v-for="page in filteredPages" :key="page.id" cols="12" sm="6" md="4">
                <v-card
                    variant="outlined"
                    elevation="1"
                    hover
                    class="cursor-pointer h-100 d-flex flex-column"
                    @click="openPage(page)"
                >
                    <v-card-title class="text-subtitle-1 font-weight-medium pb-1">
                        {{ page.title }}
                    </v-card-title>
                    <v-card-subtitle class="pb-2">
                        <v-chip v-if="page.category_name" size="x-small" variant="tonal" class="mr-1">
                            {{ page.category_name }}
                        </v-chip>
                        <v-chip v-if="page.kba_hsn" size="x-small" variant="tonal" color="amber" class="mr-1">
                            {{ page.kba_hsn }}/{{ page.kba_tsn }}
                        </v-chip>
                    </v-card-subtitle>
                    <v-card-text class="text-body-2 text-medium-emphasis pt-0 flex-grow-1" style="min-height: 48px;">
                        {{ getSnippet(page.content_preview) }}
                    </v-card-text>
                    <v-divider />
                    <v-card-actions class="px-4 py-2">
                        <span class="text-caption text-medium-emphasis">
                            {{ page.updated_by_name || page.created_by_name }}
                        </span>
                        <v-spacer />
                        <span class="text-caption text-medium-emphasis mr-2">
                            {{ formatDate(page.mtime || page.itime) }}
                        </span>
                        <v-btn icon="mdi-pencil" size="x-small" variant="text" @click.stop="editPage(page)" />
                        <v-btn icon="mdi-delete" size="x-small" variant="text" color="red" @click.stop="confirmDelete(page)" />
                    </v-card-actions>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { wikiStore } from '@/core/stores/wiki.store.js'
import { useViewHistory } from '@/core/composables/useViewHistory.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as alerts from '@/core/utils/alerts.js'
import * as toasts from '@/core/utils/toasts.js'

export default {
    name: 'WikiListView',
    components: { NavbarView },
    setup() {
        const { t } = useI18n()
        const router = useRouter()
        const wiki = wikiStore()
        const { saveToHistory } = useViewHistory()

        const pages = ref([])
        const categories = ref([])
        const loading = ref(false)
        const search = ref('')
        const selectedCategory = ref(null)

        const filteredPages = computed(() => {
            let result = pages.value
            if (selectedCategory.value !== null) {
                result = result.filter(p => p.category_id === selectedCategory.value)
            }
            if (search.value) {
                const s = search.value.toLowerCase()
                result = result.filter(p =>
                    p.title.toLowerCase().includes(s) ||
                    (p.category_name || '').toLowerCase().includes(s) ||
                    (p.content_preview || '').replace(/<[^>]*>/g, '').toLowerCase().includes(s)
                )
            }
            return result
        })

        function getSnippet(html, maxLen = 120) {
            if (!html) return ''
            const tmp = document.createElement('div')
            tmp.innerHTML = html
            const text = tmp.textContent || tmp.innerText || ''
            return text.length > maxLen ? text.substring(0, maxLen) + '...' : text
        }

        function formatDate(dateStr) {
            if (!dateStr) return ''
            const d = new Date(dateStr)
            return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        }

        async function loadData() {
            loading.value = true
            try {
                const [p, c] = await Promise.all([
                    wiki.fetchPages(),
                    wiki.fetchCategories()
                ])
                pages.value = p
                categories.value = c
            } catch (e) {
                console.error('Wiki loadData error:', e)
            } finally {
                loading.value = false
            }
        }

        function openPage(item) {
            saveToHistory({
                type: 'wiki',
                id: item.id,
                title: item.title,
                subtitle: item.category_name || '',
                route: { name: 'wiki-read', params: { id: item.id } }
            })
            router.push({ name: 'wiki-read', params: { id: item.id } })
        }

        function editPage(item) {
            router.push({ name: 'wiki-edit', params: { id: item.id } })
        }

        async function confirmDelete(item) {
            const result = await alerts.question(
                t('WikiListView.confirmDelete', { title: item.title })
            )
            if (result.isConfirmed) {
                try {
                    await wiki.deletePage(item.id)
                    pages.value = pages.value.filter(p => p.id !== item.id)
                    toasts.success(t('WikiListView.deleted'))
                } catch (e) {
                    console.error('Wiki delete error:', e)
                    toasts.error(t('WikiListView.deleteError'))
                }
            }
        }

        onMounted(loadData)

        return {
            t, pages, categories, loading, search, selectedCategory,
            filteredPages, getSnippet, formatDate,
            openPage, editPage, confirmDelete
        }
    }
}
</script>

<style scoped>
.cursor-pointer {
    cursor: pointer;
}
</style>
