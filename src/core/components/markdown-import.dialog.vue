<!-- src/core/components/markdown-import.dialog.vue -->
<!--
    Markdown übernehmen: Text, der schon in Markdown formatiert ist (etwa aus
    einer Produktbeschreibung des Herstellers, einer KI-Antwort oder einer
    Hugo-Seite), in HTML für den visuellen Editor wandeln.

    Der Dialog meldet den Text zurück; was damit geschieht, entscheidet der
    Aufrufer: „Einfügen“ setzt ihn an die Cursorposition, „Ersetzen“ tauscht
    den ganzen Text aus. Geliefert wird HTML (format 'html') oder das
    Markdown selbst (format 'markdown', für einen Editor im Markdown-Modus).
    Die Vorschau zeigt das Ergebnis so, wie es der Editor anzeigen wird.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="960" scrollable @update:model-value="close">
        <v-card rounded="lg">
            <v-card-title class="d-flex align-center pa-4 pb-2">
                <v-icon color="primary" class="mr-2">mdi-language-markdown-outline</v-icon>
                <span class="text-subtitle-1 font-weight-bold">{{ t('MarkdownImportDialog.title') }}</span>
                <v-spacer />
                <v-btn icon variant="text" size="small" @click="close(false)">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <v-card-text class="pt-4">
                <p class="text-body-2 markdown-import__hint mb-3">{{ t('MarkdownImportDialog.hint') }}</p>
                <v-row density="compact">
                    <v-col cols="12" md="6">
                        <v-textarea
                            ref="eingabeFeld"
                            v-model="markdown"
                            :label="t('MarkdownImportDialog.input')"
                            variant="outlined"
                            density="compact"
                            hide-details
                            rows="16"
                            no-resize
                            autofocus
                            spellcheck="false"
                            class="markdown-import__input"
                        />
                    </v-col>
                    <v-col cols="12" md="6">
                        <div class="markdown-import__preview">
                            <div class="markdown-import__preview-title">{{ t('MarkdownImportDialog.preview') }}</div>
                            <div v-if="html" class="markdown-import__preview-body" v-html="html" />
                            <div v-else class="markdown-import__preview-body markdown-import__empty">
                                {{ t('MarkdownImportDialog.empty') }}
                            </div>
                        </div>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-3">
                <v-spacer />
                <v-btn variant="text" @click="close(false)">{{ t('MarkdownImportDialog.cancel') }}</v-btn>
                <v-btn color="primary" variant="tonal" :disabled="!html" prepend-icon="mdi-text-box-plus-outline" @click="uebernehmen('insert')">
                    {{ t('MarkdownImportDialog.insert') }}
                </v-btn>
                <v-btn color="primary" variant="flat" :disabled="!html" prepend-icon="mdi-find-replace" @click="uebernehmen('replace')">
                    {{ t('MarkdownImportDialog.replace') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { marked } from 'marked'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** Was der Aufrufer bekommt: 'html' oder das Markdown selbst */
    format: { type: String, default: 'html', validator: v => ['html', 'markdown'].includes(v) },
})
// insert: an der Cursorposition einfügen; replace: ganzen Text ersetzen
const emit = defineEmits(['update:modelValue', 'insert', 'replace'])

const { t } = useI18n()
const markdown = ref('')
const eingabeFeld = ref(null)

// GitHub-Markdown mit Tabellen und Durchstreichen; breaks: ein einzelner
// Zeilenumbruch bleibt ein Umbruch, wie es Laien erwarten. Den HTML-Umfang
// begrenzt der Editor selbst: nur, was sein Schema kennt, bleibt erhalten.
const html = computed(() => {
    const quelle = markdown.value.trim()
    if (quelle === '') return ''
    return String(marked.parse(quelle, { gfm: true, breaks: true, async: false })).trim()
})

watch(() => props.modelValue, open => {
    if (open) markdown.value = ''
})

function uebernehmen(art) {
    if (!html.value) return
    emit(art, props.format === 'markdown' ? markdown.value.trim() : html.value)
    close(false)
}

function close() {
    emit('update:modelValue', false)
}
</script>

<style scoped>
.markdown-import__hint,
.markdown-import__empty,
.markdown-import__preview-title {
    color: var(--oserp-text-muted);
}

.markdown-import__input :deep(textarea) {
    font-family: monospace;
    font-size: 0.85rem;
    line-height: 1.5;
}

.markdown-import__preview {
    height: 100%;
    min-height: 200px;
    display: flex;
    flex-direction: column;
    border: 1px solid var(--oserp-border);
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-surface);
}

.markdown-import__preview-title {
    font-size: 0.75rem;
    padding: var(--oserp-gap-xs) var(--oserp-gap);
    border-bottom: 1px solid var(--oserp-border-soft);
}

.markdown-import__preview-body {
    flex: 1 1 auto;
    overflow: auto;
    padding: var(--oserp-gap);
    font-size: 0.95rem;
    line-height: 1.6;
    max-height: 60vh;
}

.markdown-import__preview-body :deep(p) { margin: 0 0 0.5em; }
.markdown-import__preview-body :deep(h1),
.markdown-import__preview-body :deep(h2),
.markdown-import__preview-body :deep(h3) { margin: 1em 0 0.5em; line-height: 1.3; }
.markdown-import__preview-body :deep(h1:first-child),
.markdown-import__preview-body :deep(h2:first-child),
.markdown-import__preview-body :deep(h3:first-child) { margin-top: 0; }
.markdown-import__preview-body :deep(h1) { font-size: 1.6em; }
.markdown-import__preview-body :deep(h2) { font-size: 1.35em; }
.markdown-import__preview-body :deep(h3) { font-size: 1.15em; }
.markdown-import__preview-body :deep(ul),
.markdown-import__preview-body :deep(ol) { padding-left: 1.5em; margin: 0.5em 0; }
.markdown-import__preview-body :deep(blockquote) {
    border-left: 3px solid rgb(var(--v-theme-primary));
    padding-left: 1em;
    margin: 1em 0;
    color: var(--oserp-text-muted);
}
.markdown-import__preview-body :deep(code) {
    background: rgba(var(--v-theme-on-surface), 0.07);
    padding: 0.15em 0.4em;
    border-radius: 3px;
    font-size: 0.9em;
}
.markdown-import__preview-body :deep(pre) {
    background: rgba(var(--v-theme-on-surface), 0.07);
    padding: 1em;
    border-radius: var(--oserp-radius-sm);
    overflow-x: auto;
}
.markdown-import__preview-body :deep(pre code) { background: none; padding: 0; }
.markdown-import__preview-body :deep(table) { border-collapse: collapse; width: 100%; margin: 0.75em 0; }
.markdown-import__preview-body :deep(th),
.markdown-import__preview-body :deep(td) {
    border: 1px solid var(--oserp-border);
    padding: 0.4em 0.6em;
    text-align: left;
    vertical-align: top;
}
.markdown-import__preview-body :deep(th) { background: rgba(var(--v-theme-on-surface), 0.05); font-weight: 600; }
.markdown-import__preview-body :deep(hr) { border: none; border-top: 1px solid var(--oserp-border); margin: 1.5em 0; }
</style>
