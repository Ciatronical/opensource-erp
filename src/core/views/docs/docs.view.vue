<!-- src/core/views/docs/docs.view.vue -->
<template>
  <NavbarView />
  <v-container fluid class="pa-4">
    <v-row>
      <!-- Seitenleiste: Dokument-Liste -->
      <v-col cols="12" md="3" lg="2">
        <v-card variant="outlined">
          <v-card-title class="text-subtitle-1 pb-0">
            <v-icon start size="20">mdi-book-open-variant</v-icon>
            {{ t('DocsView.title') }}
          </v-card-title>
          <v-list density="compact" nav>
            <v-list-item
              v-for="doc in docsList"
              :key="doc.slug"
              :active="doc.slug === currentSlug"
              :title="doc.title"
              @click="loadDoc(doc.slug)"
              rounded
            />
          </v-list>
        </v-card>
      </v-col>

      <!-- Inhalt -->
      <v-col cols="12" md="9" lg="10">
        <v-card variant="outlined" class="pa-6">
          <div v-if="loading" class="text-center pa-8">
            <v-progress-circular indeterminate color="primary" />
          </div>
          <div v-else-if="docContent" class="markdown-body" v-html="renderedContent" />
          <div v-else class="text-center text-medium-emphasis pa-8">
            {{ t('DocsView.selectDoc') }}
          </div>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { marked } from 'marked'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'

export default {
  name: 'DocsView',
  components: { NavbarView },
  setup() {
    const { t } = useI18n()
    const route = useRoute()
    const router = useRouter()

    const docsList = ref([])
    const docContent = ref('')
    const currentSlug = ref('')
    const loading = ref(false)

    const renderedContent = computed(() => {
      if (!docContent.value) return ''
      return marked.parse(docContent.value)
    })

    async function fetchDocsList() {
      try {
        const res = await axios.post('/api/docs/', { action: 'getDocsList' })
        if (res.data.success) {
          docsList.value = res.data.payload?.docs || []
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
        }
      } catch {
        docContent.value = ''
      }
      loading.value = false

      // URL aktualisieren ohne Neuladen
      if (route.params.slug !== slug) {
        router.replace({ name: 'docs', params: { slug } })
      }
    }

    onMounted(async () => {
      await fetchDocsList()
      // Slug aus URL laden falls vorhanden
      const slug = route.params.slug
      if (slug) {
        loadDoc(slug)
      } else if (docsList.value.length) {
        loadDoc(docsList.value[0].slug)
      }
    })

    return { t, docsList, docContent, currentSlug, renderedContent, loading, loadDoc }
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
.markdown-body :deep(hr) {
  border: none;
  border-top: 1px solid #e0e0e0;
  margin: 1.5rem 0;
}
</style>
