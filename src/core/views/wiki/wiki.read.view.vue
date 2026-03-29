<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Skeleton beim Laden -->
        <template v-if="loading">
            <div class="d-flex align-center mb-3 ga-2">
                <v-skeleton-loader type="button" width="32" />
                <v-skeleton-loader type="text" width="300" />
            </div>
            <v-card variant="outlined" class="mb-3">
                <v-card-text>
                    <v-skeleton-loader type="article, article" />
                </v-card-text>
            </v-card>
            <v-skeleton-loader type="chip, chip, chip" class="d-flex ga-4" />
        </template>

        <!-- Artikel geladen -->
        <template v-else-if="page">

            <!-- Breadcrumb -->
            <div class="d-flex align-center mb-3 text-body-2 text-medium-emphasis flex-wrap ga-1">
                <router-link :to="{ name: 'wiki-list' }" class="text-decoration-none text-medium-emphasis breadcrumb-link">
                    Wiki
                </router-link>
                <v-icon size="16">mdi-chevron-right</v-icon>
                <span v-if="page.category_name">
                    {{ page.category_name }}
                    <v-icon size="16" class="mx-0">mdi-chevron-right</v-icon>
                </span>
                <span class="text-high-emphasis font-weight-medium">{{ page.title }}</span>
            </div>

            <!-- Header -->
            <div class="d-flex align-center mb-4 flex-wrap ga-2">
                <v-btn icon="mdi-arrow-left" size="small" variant="text" :to="{ name: 'wiki-list' }" />
                <h1 class="text-h5 mb-0 font-weight-bold">{{ page.title }}</h1>
                <v-chip v-if="page.category_name" size="small" variant="tonal">{{ page.category_name }}</v-chip>
                <v-chip v-if="page.kba_hsn" size="small" variant="tonal" color="amber">
                    KBA {{ page.kba_hsn }}/{{ page.kba_tsn }}
                </v-chip>
                <v-spacer />
                <v-btn color="primary" size="small" prepend-icon="mdi-pencil" :to="{ name: 'wiki-edit', params: { id: page.id } }">
                    {{ t('WikiReadView.edit') }}
                </v-btn>
            </div>

            <!-- Artikel-Inhalt -->
            <v-card variant="outlined" elevation="1" class="mb-4">
                <v-card-text class="wiki-content pa-6" v-html="page.content" />
            </v-card>

            <!-- Metadata -->
            <v-card variant="outlined" class="bg-grey-lighten-4">
                <v-card-text class="d-flex flex-wrap ga-6 align-center py-3">
                    <div v-if="page.created_by_name" class="d-flex align-center ga-2 text-body-2 text-medium-emphasis">
                        <v-icon size="18" color="grey">mdi-account-plus</v-icon>
                        <div>
                            <div class="text-caption text-medium-emphasis">{{ t('WikiReadView.createdBy') }}</div>
                            <div class="text-body-2">
                                {{ page.created_by_name }}
                                <span v-if="page.itime" class="text-caption ml-1">({{ formatDate(page.itime) }})</span>
                            </div>
                        </div>
                    </div>
                    <div v-if="page.updated_by_name && page.mtime" class="d-flex align-center ga-2 text-body-2 text-medium-emphasis">
                        <v-icon size="18" color="grey">mdi-pencil</v-icon>
                        <div>
                            <div class="text-caption text-medium-emphasis">{{ t('WikiReadView.updatedBy') }}</div>
                            <div class="text-body-2">
                                {{ page.updated_by_name }}
                                <span class="text-caption ml-1">({{ formatDate(page.mtime) }})</span>
                            </div>
                        </div>
                    </div>
                    <div v-if="page.revision_count > 0" class="d-flex align-center ga-2 text-body-2 text-medium-emphasis">
                        <v-icon size="18" color="grey">mdi-history</v-icon>
                        <div>
                            <div class="text-caption text-medium-emphasis">{{ t('WikiReadView.revisions') }}</div>
                            <div class="text-body-2">{{ page.revision_count }}</div>
                        </div>
                    </div>
                </v-card-text>
            </v-card>
        </template>

        <!-- Nicht gefunden -->
        <v-card v-else variant="outlined" class="text-center pa-8">
            <v-icon size="64" color="grey-lighten-1" class="mb-4">mdi-book-open-page-variant-outline</v-icon>
            <h3 class="text-h6 text-medium-emphasis mb-2">{{ t('WikiReadView.notFound') }}</h3>
            <v-btn class="mt-2" variant="text" prepend-icon="mdi-arrow-left" :to="{ name: 'wiki-list' }">
                {{ t('WikiReadView.back') }}
            </v-btn>
        </v-card>
    </v-container>
</template>

<script>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { wikiStore } from '@/core/stores/wiki.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as toasts from '@/core/utils/toasts.js'

export default {
    name: 'WikiReadView',
    components: { NavbarView },
    props: {
        id: { type: [Number, String], required: true }
    },
    setup(props) {
        const { t } = useI18n()
        const route = useRoute()
        const wiki = wikiStore()

        const page = ref(null)
        const loading = ref(false)

        function formatDate(dateStr) {
            if (!dateStr) return ''
            const d = new Date(dateStr)
            return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
        }

        async function loadPage() {
            loading.value = true
            try {
                page.value = await wiki.fetchPage(Number(props.id || route.params.id))
            } catch (e) {
                console.error('Wiki loadPage error:', e)
                toasts.error(t('WikiReadView.loadError'))
            } finally {
                loading.value = false
            }
        }

        onMounted(loadPage)

        return { t, page, loading, formatDate }
    }
}
</script>

<style scoped>
.breadcrumb-link:hover {
    text-decoration: underline !important;
}

.wiki-content {
    line-height: 1.7;
    font-size: 0.95rem;
}
.wiki-content :deep(p) {
    margin-bottom: 0.75em;
}
.wiki-content :deep(ul),
.wiki-content :deep(ol) {
    padding-left: 1.5em;
    margin: 0.5em 0;
}
.wiki-content :deep(a) {
    color: rgb(var(--v-theme-primary));
    text-decoration: none;
}
.wiki-content :deep(a:hover) {
    text-decoration: underline;
}
.wiki-content :deep(h1),
.wiki-content :deep(h2),
.wiki-content :deep(h3) {
    margin-top: 1em;
    margin-bottom: 0.5em;
}
.wiki-content :deep(blockquote) {
    border-left: 3px solid rgb(var(--v-theme-primary));
    padding-left: 1em;
    margin: 1em 0;
    color: rgba(var(--v-theme-on-surface), 0.7);
}
.wiki-content :deep(pre) {
    background: #f5f5f5;
    padding: 1em;
    border-radius: 4px;
    overflow-x: auto;
    margin: 0.75em 0;
}
.wiki-content :deep(code) {
    background: #f5f5f5;
    padding: 0.15em 0.4em;
    border-radius: 3px;
    font-size: 0.9em;
}
.wiki-content :deep(pre code) {
    background: none;
    padding: 0;
}
.wiki-content :deep(img) {
    max-width: 100%;
    height: auto;
}
.wiki-content :deep(table) {
    border-collapse: collapse;
    width: 100%;
    margin: 0.75em 0;
}
.wiki-content :deep(th),
.wiki-content :deep(td) {
    border: 1px solid #ddd;
    padding: 0.5em 0.75em;
    text-align: left;
}
.wiki-content :deep(th) {
    background: #f5f5f5;
    font-weight: 600;
}
</style>
