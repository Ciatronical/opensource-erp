<!-- src/core/components/source.dialog.vue -->
<!--
    Quelltext bearbeiten: den Inhalt des visuellen Editors in CodeMirror
    (Zeilennummern, Hervorhebung, Suchen/Ersetzen, Falten) ändern und
    zurückgeben, wie der Quelltext-Modus in HugoCMS. Die Sprache folgt dem
    Speicherformat des Editors: Markdown oder HTML.

    Der Dialog arbeitet auf einer Kopie: „Übernehmen“ meldet den geänderten
    Quelltext an den Aufrufer, „Abbrechen“ verwirft alles. HTML wird beim
    Öffnen lesbar eingerückt, Markdown bleibt, wie es ist. Was der visuelle
    Editor nicht kennt, fällt beim Zurücklesen weg — das sieht man sofort.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="1100" scrollable @update:model-value="close">
        <v-card rounded="lg" class="source-dialog">
            <v-card-title class="d-flex align-center pa-4 pb-2">
                <v-icon color="primary" class="mr-2">mdi-code-tags</v-icon>
                <span class="text-subtitle-1 font-weight-bold">{{ t('SourceDialog.title') }}</span>
                <v-spacer />
                <v-btn icon variant="text" size="small" @click="close(false)">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <!-- Werkzeugleiste des Quelltext-Editors -->
            <div class="source-dialog__toolbar">
                <template v-for="(tool, i) in tools" :key="i">
                    <v-divider v-if="tool.divider" vertical class="mx-1 align-self-stretch" />
                    <v-btn
                        v-else
                        :icon="tool.icon"
                        size="small"
                        variant="text"
                        :disabled="tool.disabled"
                        :title="tool.label"
                        @click="tool.action ? tool.action() : exec(tool.name)"
                    />
                </template>
            </div>

            <v-card-text class="pa-0 source-dialog__body">
                <div ref="host" class="source-dialog__host" />
            </v-card-text>

            <div class="source-dialog__status">
                <span>{{ t('SourceDialog.position', { line: cursor.line, column: cursor.column }) }}</span>
                <v-spacer />
                <span>{{ language === 'markdown' ? 'Markdown' : 'HTML' }}</span>
            </div>

            <v-divider />

            <v-card-actions class="pa-3">
                <v-spacer />
                <v-btn variant="text" @click="close(false)">{{ t('SourceDialog.cancel') }}</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-check" @click="uebernehmen">
                    {{ t('SourceDialog.apply') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { EditorState } from '@codemirror/state'
import {
    EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter,
    drawSelection, dropCursor, highlightSpecialChars, rectangularSelection, crosshairCursor,
} from '@codemirror/view'
import {
    defaultKeymap, history, historyKeymap, indentWithTab,
    undo, redo, undoDepth, redoDepth, indentMore, indentLess, toggleComment,
} from '@codemirror/commands'
import {
    syntaxHighlighting, defaultHighlightStyle, bracketMatching, indentOnInput,
    foldGutter, foldKeymap, foldAll, unfoldAll,
} from '@codemirror/language'
import { search, searchKeymap, highlightSelectionMatches, openSearchPanel, gotoLine } from '@codemirror/search'
import { html as htmlLanguage } from '@codemirror/lang-html'
import { markdown as markdownLanguage } from '@codemirror/lang-markdown'
import { formatHtml } from '@/core/utils/htmlFormat.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** Inhalt des visuellen Editors im Speicherformat */
    source: { type: String, default: '' },
    /** Sprache des Quelltextes: 'markdown' oder 'html' */
    language: { type: String, default: 'markdown', validator: v => ['markdown', 'html'].includes(v) },
})
// apply: geänderter Quelltext
const emit = defineEmits(['update:modelValue', 'apply'])

const { t, locale } = useI18n()
const host = ref(null)
const cursor = ref({ line: 1, column: 1 })
const verlauf = ref({ undo: false, redo: false })
let view = null

// Deutsche Texte für die eingebauten CodeMirror-Panels (Suche, Gehe zu
// Zeile). Die Schlüssel sind von CodeMirror vorgegeben.
const PHRASES_DE = {
    'Find': 'Suchen',
    'Replace': 'Ersetzen',
    'next': 'weiter',
    'previous': 'zurück',
    'all': 'alle',
    'match case': 'Groß-/Kleinschreibung',
    'by word': 'ganzes Wort',
    'regexp': 'regulärer Ausdruck',
    'replace': 'ersetzen',
    'replace all': 'alle ersetzen',
    'close': 'schließen',
    'current match': 'aktueller Treffer',
    'replaced $ matches': '$ Treffer ersetzt',
    'replaced match on line $': 'Treffer in Zeile $ ersetzt',
    'on line': 'in Zeile',
    'Go to line': 'Gehe zu Zeile',
    'go': 'los',
}

const theme = EditorView.theme({
    '&': { height: '100%', fontSize: '12.5px' },
    '.cm-scroller': { fontFamily: 'ui-monospace, "Roboto Mono", Menlo, monospace', lineHeight: '1.45' },
    '.cm-gutters': { backgroundColor: 'var(--oserp-surface-soft)', color: 'var(--oserp-text-muted)', borderRight: '1px solid var(--oserp-border-soft)' },
    '.cm-activeLine': { backgroundColor: 'rgba(var(--v-theme-primary), 0.05)' },
    '.cm-activeLineGutter': { backgroundColor: 'rgba(var(--v-theme-primary), 0.1)' },
    '&.cm-focused': { outline: 'none' },
})

// Von der Werkzeugleiste aufrufbare CodeMirror-Befehle
const commands = { undo, redo, indentMore, indentLess, toggleComment, openSearchPanel, gotoLine, foldAll, unfoldAll }
// Befehle, die ein Eingabe-Panel öffnen — der Fokus gehört danach dem Panel
const panelCommands = new Set(['openSearchPanel', 'gotoLine'])

const tools = computed(() => [
    { name: 'undo', icon: 'mdi-undo', label: t('SourceDialog.undo'), disabled: !verlauf.value.undo },
    { name: 'redo', icon: 'mdi-redo', label: t('SourceDialog.redo'), disabled: !verlauf.value.redo },
    { divider: true },
    { name: 'openSearchPanel', icon: 'mdi-magnify', label: t('SourceDialog.search') },
    { name: 'gotoLine', icon: 'mdi-format-list-numbered', label: t('SourceDialog.gotoLine') },
    { divider: true },
    { name: 'indentLess', icon: 'mdi-format-indent-decrease', label: t('SourceDialog.indentLess') },
    { name: 'indentMore', icon: 'mdi-format-indent-increase', label: t('SourceDialog.indentMore') },
    { name: 'toggleComment', icon: 'mdi-comment-text-outline', label: t('SourceDialog.toggleComment') },
    { divider: true },
    { name: 'foldAll', icon: 'mdi-unfold-less-horizontal', label: t('SourceDialog.foldAll') },
    { name: 'unfoldAll', icon: 'mdi-unfold-more-horizontal', label: t('SourceDialog.unfoldAll') },
    // Neu einrücken gibt es nur für HTML; Markdown trägt seine Form selbst
    ...(props.language === 'html'
        ? [{ divider: true }, { name: 'format', icon: 'mdi-format-align-left', label: t('SourceDialog.format'), action: formatieren }]
        : []),
])

function exec(name) {
    const cmd = commands[name]
    if (!view || !cmd) return
    cmd(view)
    if (!panelCommands.has(name)) view.focus()
}

/** HTML neu einrücken — als Transaktion, damit Rückgängig möglich bleibt */
function formatieren() {
    if (!view) return
    const alt = view.state.doc.toString()
    const neu = formatHtml(alt)
    if (neu !== alt) view.dispatch({ changes: { from: 0, to: alt.length, insert: neu } })
    view.focus()
}

function emitCursor(state) {
    const head = state.selection.main.head
    const line = state.doc.lineAt(head)
    cursor.value = { line: line.number, column: head - line.from + 1 }
}

function erzeugen() {
    if (!host.value || view) return
    const markdown = props.language === 'markdown'
    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: markdown ? props.source : formatHtml(props.source),
            extensions: [
                ...(locale.value === 'de' ? [EditorState.phrases.of(PHRASES_DE)] : []),
                lineNumbers(), highlightActiveLineGutter(), highlightSpecialChars(), history(),
                foldGutter(), drawSelection(), dropCursor(), indentOnInput(), bracketMatching(),
                rectangularSelection(), crosshairCursor(), highlightActiveLine(), highlightSelectionMatches(),
                search({ top: true }),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                markdown ? markdownLanguage() : htmlLanguage(),
                theme,
                EditorView.lineWrapping,
                keymap.of([indentWithTab, ...defaultKeymap, ...historyKeymap, ...searchKeymap, ...foldKeymap]),
                EditorView.updateListener.of(update => {
                    if (update.docChanged) {
                        verlauf.value = { undo: undoDepth(update.state) > 0, redo: redoDepth(update.state) > 0 }
                    }
                    if (update.docChanged || update.selectionSet) emitCursor(update.state)
                }),
            ],
        }),
    })
    verlauf.value = { undo: false, redo: false }
    emitCursor(view.state)
    view.focus()
}

function zerstoeren() {
    view?.destroy()
    view = null
}

// Der Dialoginhalt existiert nur im geöffneten Zustand: Editor beim Öffnen
// anlegen (nach dem Rendern des Dialogs), beim Schließen wieder abbauen.
watch(() => props.modelValue, open => {
    if (open) nextTick(erzeugen)
    else zerstoeren()
})

function uebernehmen() {
    if (!view) return
    emit('apply', view.state.doc.toString())
    close(false)
}

function close() {
    emit('update:modelValue', false)
}

onBeforeUnmount(zerstoeren)
</script>

<style scoped>
.source-dialog__toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    padding: var(--oserp-gap-xs) var(--oserp-gap-sm);
    border-bottom: 1px solid var(--oserp-border-soft);
    background: var(--oserp-surface-soft);
}

.source-dialog__body {
    height: 60vh;
    min-height: 240px;
}

.source-dialog__host {
    height: 100%;
    overflow: hidden;
    background: var(--oserp-surface);
}

.source-dialog__host :deep(.cm-editor) {
    height: 100%;
}

.source-dialog__status {
    display: flex;
    align-items: center;
    padding: var(--oserp-gap-xs) var(--oserp-gap);
    border-top: 1px solid var(--oserp-border-soft);
    font-size: 0.75rem;
    color: var(--oserp-text-muted);
}
</style>
