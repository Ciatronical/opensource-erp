<!-- src/core/views/docs/docs.view.vue -->
<!--
    Dokumentation im System. Die Seiten liegen als Markdown in docs/features/;
    ihr Front Matter (Gruppe, Erweiterung, Rubrik, Reihenfolge) gliedert die
    Navigation: Kernsystem nach Rubriken, darunter die Erweiterungen (LxCars,
    Shop). Systemadministratoren veröffentlichen den Feature-Katalog von hier
    aus auf der Website (docs/website-schnittstelle.md).
-->
<template>
  <NavbarView />
  <v-container fluid class="pa-4">
    <v-row>
      <!-- Seitenleiste: Navigation -->
      <v-col cols="12" md="3" lg="2">
        <v-card variant="outlined">
          <v-card-title class="text-subtitle-1 pb-1 d-flex align-center">
            <v-icon start size="20">mdi-book-open-variant</v-icon>
            {{ t('DocsView.title') }}
          </v-card-title>
          <div class="px-3 pb-2">
            <v-text-field
              v-model="search"
              :placeholder="t('DocsView.search')"
              prepend-inner-icon="mdi-magnify"
              variant="outlined" density="compact" hide-details clearable
            />
          </div>
          <v-list density="compact" nav class="pt-0">
            <template v-for="section in navigation" :key="section.key">
              <v-list-subheader class="text-uppercase font-weight-bold">
                <v-icon v-if="section.icon" size="small" class="mr-1">{{ section.icon }}</v-icon>
                {{ section.title }}
              </v-list-subheader>
              <template v-for="cat in section.categories" :key="cat.name">
                <div v-if="cat.name && section.categories.length > 1" class="text-caption text-medium-emphasis px-3 pt-1">
                  {{ cat.name }}
                </div>
                <v-list-item
                  v-for="doc in cat.docs"
                  :key="doc.slug"
                  :active="doc.slug === currentSlug"
                  :title="doc.title"
                  min-height="32"
                  class="py-0 mb-0"
                  rounded
                  @click="loadDoc(doc.slug)"
                >
                  <template v-if="doc.external || doc.flag" #append>
                    <v-icon v-if="doc.external" size="x-small" :title="t('DocsView.external')">mdi-cloud-outline</v-icon>
                    <v-icon v-if="doc.flag" size="x-small" :title="t('DocsView.flag', { flag: doc.flag })">mdi-toggle-switch-outline</v-icon>
                  </template>
                </v-list-item>
              </template>
            </template>
            <v-list-item v-if="!navigation.length" :title="t('DocsView.noMatch')" disabled />
          </v-list>
          <v-divider v-if="isAdmin" />
          <div v-if="isAdmin" class="pa-3">
            <v-btn block color="primary" variant="tonal" size="small" prepend-icon="mdi-web" @click="openPublish">
              {{ t('DocsView.publish.button') }}
            </v-btn>
          </div>
        </v-card>
      </v-col>

      <!-- Inhalt -->
      <v-col cols="12" md="9" lg="10">
        <v-card variant="outlined" class="pa-6">
          <div v-if="loading" class="text-center pa-8">
            <v-progress-circular indeterminate color="primary" />
          </div>
          <template v-else-if="docContent">
            <div v-if="currentMeta" class="d-flex align-center flex-wrap ga-2 mb-3">
              <v-chip v-if="currentMeta.group === 'extension'" size="small" color="primary" variant="tonal">
                <v-icon start size="small">mdi-puzzle-outline</v-icon>
                {{ t('DocsView.extensionChip', { name: extensionTitle(currentMeta.extension) }) }}
              </v-chip>
              <v-chip v-else size="small" variant="tonal">{{ t('DocsView.core') }}</v-chip>
              <v-chip v-if="currentMeta.category" size="small" variant="outlined">{{ currentMeta.category }}</v-chip>
              <v-chip v-if="currentMeta.external" size="small" variant="outlined"><v-icon start size="small">mdi-cloud-outline</v-icon>{{ t('DocsView.external') }}</v-chip>
              <v-chip v-if="currentMeta.flag" size="small" variant="outlined"><v-icon start size="small">mdi-toggle-switch-outline</v-icon>{{ currentMeta.flag }}</v-chip>
              <v-chip v-if="currentMeta.status && currentMeta.status !== 'stable'" size="small" color="warning" variant="tonal">{{ currentMeta.status }}</v-chip>
              <v-spacer />
              <span class="text-caption text-medium-emphasis">{{ currentMeta.mtime }}</span>
            </div>
            <div class="markdown-body" v-html="renderedContent" />
          </template>
          <div v-else class="text-center text-medium-emphasis pa-8">
            {{ t('DocsView.selectDoc') }}
          </div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Veröffentlichen -->
    <v-dialog v-model="publishOpen" max-width="640">
      <v-card rounded="lg">
        <v-card-title class="d-flex align-center pa-4 pb-2">
          <v-icon color="primary" class="mr-2">mdi-web</v-icon>
          <span class="text-subtitle-1 font-weight-bold">{{ t('DocsView.publish.title') }}</span>
          <v-spacer />
          <v-btn icon variant="text" size="small" @click="publishOpen = false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text class="pt-4">
          <p class="text-body-2 mb-4">{{ t('DocsView.publish.intro') }}</p>

          <v-row dense class="mb-2">
            <v-col v-for="k in publishStats" :key="k.label" cols="4">
              <v-card variant="tonal" rounded="lg">
                <v-card-text class="py-2 text-center">
                  <div class="text-h6 font-weight-bold">{{ k.value }}</div>
                  <div class="text-caption">{{ k.label }}</div>
                </v-card-text>
              </v-card>
            </v-col>
          </v-row>

          <v-text-field v-model="publish.url" :label="t('DocsView.publish.url')" placeholder="https://opensource-erp.dev/api/features"
                        prepend-inner-icon="mdi-link-variant" variant="outlined" density="compact" class="mb-2" />
          <v-text-field v-model="publish.token" :label="t('DocsView.publish.token')"
                        :hint="publish.has_token ? t('DocsView.publish.tokenKept') : ''" persistent-hint
                        type="password" prepend-inner-icon="mdi-key-outline" variant="outlined" density="compact" class="mb-2" />

          <v-alert v-if="publish.last" :type="publish.last.ok ? 'success' : 'error'" variant="tonal" density="compact" class="mt-2">
            {{ t('DocsView.publish.last', { at: dt(publish.last.at), pages: publish.last.pages, version: publish.last.version }) }}
            <div v-if="publish.last.message" class="text-caption">{{ publish.last.message }}</div>
          </v-alert>
        </v-card-text>
        <v-divider />
        <v-card-actions class="pa-4">
          <v-btn variant="text" prepend-icon="mdi-download-outline" :loading="exporting" @click="exportCatalog">
            {{ t('DocsView.publish.export') }}
          </v-btn>
          <v-spacer />
          <v-btn variant="text" @click="publishOpen = false">{{ t('DocsView.publish.cancel') }}</v-btn>
          <v-btn color="primary" variant="flat" prepend-icon="mdi-cloud-upload-outline" :loading="publishing" @click="doPublish">
            {{ t('DocsView.publish.send') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script>
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { marked } from 'marked'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { formatDateTime } from '@/core/utils/dateFormatter.js'
import * as alerts from '@/core/utils/alerts.js'

export default {
  name: 'DocsView',
  components: { NavbarView },
  setup() {
    const { t, locale } = useI18n()
    const route = useRoute()
    const router = useRouter()
    const oserp = oserpStore()

    const docsList = ref([])
    const extensions = ref([])
    const docContent = ref('')
    const currentSlug = ref('')
    const currentMeta = ref(null)
    const loading = ref(false)
    const search = ref('')

    const publishOpen = ref(false)
    const publishing = ref(false)
    const exporting = ref(false)
    const publish = reactive({ url: '', token: '', has_token: false, last: null })

    const isAdmin = computed(() => !!oserp.session.is_admin)

    const renderedContent = computed(() => docContent.value ? marked.parse(docContent.value) : '')

    function extensionTitle(name) {
      return extensions.value.find(e => e.name === name)?.title || name || ''
    }

    /** Navigation: Kernsystem nach Rubrik, dann je Erweiterung ein Block */
    const navigation = computed(() => {
      const term = (search.value || '').trim().toLowerCase()
      const docs = docsList.value.filter(d => !term
        || d.title.toLowerCase().includes(term)
        || (d.summary || '').toLowerCase().includes(term)
        || (d.category || '').toLowerCase().includes(term))

      const sections = []
      const core = docs.filter(d => d.group !== 'extension')
      if (core.length) {
        sections.push({ key: 'core', title: t('DocsView.core'), icon: 'mdi-home-outline', categories: groupByCategory(core) })
      }
      const extNames = [...new Set(docs.filter(d => d.group === 'extension').map(d => d.extension || ''))]
      for (const name of extNames) {
        const ext = docs.filter(d => d.group === 'extension' && (d.extension || '') === name)
        sections.push({
          key: 'ext-' + name,
          title: t('DocsView.extensionChip', { name: extensionTitle(name) }),
          icon: extensions.value.find(e => e.name === name)?.icon || 'mdi-puzzle-outline',
          categories: [{ name: '', docs: ext }],
        })
      }
      return sections
    })

    function groupByCategory(docs) {
      const map = new Map()
      for (const d of docs) {
        const key = d.category || ''
        if (!map.has(key)) map.set(key, [])
        map.get(key).push(d)
      }
      return [...map.entries()].map(([name, list]) => ({ name, docs: list }))
    }

    const publishStats = computed(() => [
      { label: t('DocsView.publish.pages'), value: docsList.value.length },
      { label: t('DocsView.publish.extensions'), value: extensions.value.length },
      { label: t('DocsView.publish.core'), value: docsList.value.filter(d => d.group !== 'extension').length },
    ])

    function dt(v) { return v ? formatDateTime(v, locale.value) : '' }

    async function fetchDocsList() {
      try {
        const res = await axios.post('/api/docs/', { action: 'getDocsList' })
        if (res.data.success) {
          docsList.value = res.data.payload?.docs || []
          extensions.value = res.data.payload?.extensions || []
        }
      } catch { /* ignore */ }
    }

    async function loadDoc(slug) {
      loading.value = true
      currentSlug.value = slug
      try {
        const res = await axios.post('/api/docs/', { action: 'getDoc', slug })
        if (res.data.success) {
          docContent.value = res.data.payload?.content || ''
          currentMeta.value = res.data.payload?.meta || null
        }
      } catch {
        docContent.value = ''
        currentMeta.value = null
      }
      loading.value = false
      if (route.params.slug !== slug) {
        router.replace({ name: 'docs', params: { slug } })
      }
    }

    // Relative Links zwischen den Seiten (lxcars.md) bleiben im System
    function onContentClick(event) {
      const a = event.target.closest('a[href]')
      if (!a) return
      const href = a.getAttribute('href') || ''
      const m = href.match(/^([a-z0-9_-]+)\.md(#.*)?$/i)
      if (m) { event.preventDefault(); loadDoc(m[1]) }
    }

    async function openPublish() {
      publishOpen.value = true
      try {
        const res = await axios.post('/api/docs/', { action: 'getDocsPublishSettings' })
        if (res.data.success) {
          const r = res.data.payload?.results || {}
          publish.url = r.url || ''
          publish.has_token = !!r.has_token
          publish.last = r.last || null
          publish.token = ''
        }
      } catch { /* ignore */ }
    }

    async function doPublish() {
      publishing.value = true
      try {
        const res = await axios.post('/api/docs/', { action: 'publishDocsToWebsite', url: publish.url, token: publish.token })
        if (!res.data.success) throw new Error(res.data.payload || res.data.text)
        publish.last = res.data.payload?.results || null
        publish.has_token = publish.has_token || !!publish.token
        publish.token = ''
        alerts.success(t('DocsView.publish.done', { pages: publish.last?.pages ?? 0 }))
      } catch (e) {
        alerts.error(String(e.message || e))
      } finally {
        publishing.value = false
      }
    }

    async function exportCatalog() {
      exporting.value = true
      try {
        const res = await axios.post('/api/docs/', { action: 'getDocsCatalog' })
        const blob = new Blob([JSON.stringify(res.data.payload?.catalog || {}, null, 2)], { type: 'application/json' })
        const a = document.createElement('a')
        a.href = URL.createObjectURL(blob)
        a.download = 'opensource-erp-features.json'
        a.click()
        URL.revokeObjectURL(a.href)
      } catch (e) {
        alerts.error(String(e.message || e))
      } finally {
        exporting.value = false
      }
    }

    onMounted(async () => {
      await fetchDocsList()
      document.addEventListener('click', onContentClick)
      const slug = route.params.slug
      if (slug) loadDoc(slug)
      else if (docsList.value.length) loadDoc(docsList.value[0].slug)
    })

    return {
      t, docsList, docContent, currentSlug, currentMeta, renderedContent, loading, loadDoc,
      search, navigation, extensionTitle, isAdmin,
      publishOpen, publishing, exporting, publish, publishStats, openPublish, doPublish, exportCatalog, dt,
    }
  }
}
</script>

<style scoped>
.markdown-body :deep(h1) {
  font-size: 1.8rem;
  font-weight: 600;
  margin-bottom: 1rem;
  padding-bottom: 0.3rem;
  border-bottom: 1px solid #e0e0e0;
}
.markdown-body :deep(h2) {
  font-size: 1.4rem;
  font-weight: 600;
  margin-top: 1.5rem;
  margin-bottom: 0.8rem;
}
.markdown-body :deep(h3) {
  font-size: 1.15rem;
  font-weight: 600;
  margin-top: 1.2rem;
  margin-bottom: 0.5rem;
}
.markdown-body :deep(table) {
  border-collapse: collapse;
  width: 100%;
  margin: 1rem 0;
}
.markdown-body :deep(th),
.markdown-body :deep(td) {
  border: 1px solid #e0e0e0;
  padding: 8px 12px;
  text-align: left;
}
.markdown-body :deep(th) {
  background-color: #f5f5f5;
  font-weight: 600;
}
.markdown-body :deep(code) {
  background-color: #f5f5f5;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 0.9em;
}
.markdown-body :deep(pre) {
  background-color: #263238;
  color: #eeffff;
  padding: 16px;
  border-radius: 6px;
  overflow-x: auto;
  margin: 1rem 0;
}
.markdown-body :deep(pre code) {
  background: none;
  padding: 0;
  color: inherit;
}
.markdown-body :deep(blockquote) {
  border-left: 4px solid #1976d2;
  padding: 0.5rem 1rem;
  margin: 1rem 0;
  background-color: #e3f2fd;
}
.markdown-body :deep(ul),
.markdown-body :deep(ol) {
  padding-left: 1.5rem;
  margin: 0.5rem 0;
}
.markdown-body :deep(li) {
  margin-bottom: 0.3rem;
}
/* Die Konzeptseiten arbeiten mit eingebetteten SVG-Grafiken. Sie zeichnen mit
   currentColor, damit sie in hellem wie dunklem Thema lesbar bleiben. */
.markdown-body :deep(figure) {
  margin: 1.5rem 0;
}
.markdown-body :deep(svg) {
  display: block;
  width: 100%;
  max-width: 860px;
  height: auto;
  margin: 0 auto;
}
.markdown-body :deep(figcaption) {
  margin-top: 0.5rem;
  font-size: 0.85rem;
  opacity: 0.7;
  text-align: center;
}
.markdown-body :deep(img) {
  max-width: 100%;
  height: auto;
}
.markdown-body :deep(hr) {
  border: none;
  border-top: 1px solid #e0e0e0;
  margin: 1.5rem 0;
}
</style>
