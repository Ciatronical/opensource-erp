<!-- src/core/views/template-designer/components/designer-code.vue -->
<!--
    Quelltext-Editor für LaTeX-Vorlagen (CodeMirror 6): Zeilennummern,
    Hervorhebung von Befehlen, Kommentaren und kivitendo-Platzhaltern
    <%feld%>, Suchen/Ersetzen, Rückgängig. Der Inhalt läuft über v-model;
    'save' meldet Strg+S nach oben.
-->
<template>
    <div ref="host" class="tpl-code" />
</template>

<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { EditorState, Compartment } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { StreamLanguage, syntaxHighlighting, HighlightStyle, bracketMatching } from '@codemirror/language';
import { tags } from '@lezer/highlight';

const props = defineProps({
    modelValue: { type: String, default: '' },
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'save', 'cursor']);

const host = ref(null);
let view = null;
const readonlyCompartment = new Compartment();

/**
 * Kleiner Tokenizer für LaTeX mit kivitendo-Tags — reicht für Vorlagen:
 * Kommentare (%), Befehle (\foo), Klammern, Mathematik ($...$) und <%...%>.
 */
const latexTags = StreamLanguage.define({
    token(stream) {
        if (stream.match(/^<%.*?%>/)) return 'atom';
        if (stream.match(/^<%/)) { stream.skipToEnd(); return 'atom'; }
        if (stream.peek() === '%') { stream.skipToEnd(); return 'comment'; }
        if (stream.match(/^\\[a-zA-Z@]+\*?/)) return 'keyword';
        if (stream.match(/^\\./)) return 'keyword';
        if (stream.match(/^[{}]/)) return 'bracket';
        if (stream.match(/^[\[\]]/)) return 'squareBracket';
        if (stream.match(/^\$[^$]*\$/)) return 'string';
        if (stream.match(/^[&~^_]/)) return 'operator';
        if (stream.match(/^\d+(\.\d+)?(pt|mm|cm|em|ex)?/)) return 'number';
        stream.next();
        return null;
    },
    languageData: { commentTokens: { line: '%' } },
});

const highlight = HighlightStyle.define([
    { tag: tags.comment, color: '#8a8f98', fontStyle: 'italic' },
    { tag: tags.keyword, color: '#1565c0' },
    { tag: tags.atom, color: '#b26a00', fontWeight: '600', backgroundColor: 'rgba(255, 193, 7, 0.14)' },
    { tag: tags.string, color: '#2e7d32' },
    { tag: tags.number, color: '#6a1b9a' },
    { tag: tags.bracket, color: '#424242', fontWeight: '600' },
    { tag: tags.squareBracket, color: '#616161' },
    { tag: tags.operator, color: '#c62828' },
]);

const theme = EditorView.theme({
    '&': { height: '100%', fontSize: '12.5px', backgroundColor: '#fff' },
    '.cm-scroller': { fontFamily: 'ui-monospace, "Roboto Mono", Menlo, monospace', lineHeight: '1.45' },
    '.cm-gutters': { backgroundColor: '#f6f7f9', color: '#9aa0a6', borderRight: '1px solid #e3e6ea' },
    '.cm-activeLine': { backgroundColor: 'rgba(25, 118, 210, 0.05)' },
    '.cm-activeLineGutter': { backgroundColor: 'rgba(25, 118, 210, 0.1)' },
    '&.cm-focused': { outline: 'none' },
});

onMounted(() => {
    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: props.modelValue,
            extensions: [
                lineNumbers(), highlightActiveLineGutter(), highlightActiveLine(), drawSelection(), history(), bracketMatching(),
                latexTags, syntaxHighlighting(highlight), theme,
                EditorView.lineWrapping,
                keymap.of([
                    { key: 'Mod-s', run: () => { emit('save'); return true; } },
                    indentWithTab, ...defaultKeymap, ...historyKeymap,
                ]),
                readonlyCompartment.of(EditorState.readOnly.of(props.readonly)),
                EditorView.updateListener.of(update => {
                    if (update.docChanged) emit('update:modelValue', update.state.doc.toString());
                    if (update.selectionSet || update.docChanged) {
                        const line = update.state.doc.lineAt(update.state.selection.main.head);
                        emit('cursor', { line: line.number });
                    }
                }),
            ],
        }),
    });
});

onBeforeUnmount(() => { view?.destroy(); view = null; });

// Inhalt von außen (anderer Datei) übernehmen, ohne Cursor-Sprünge bei eigenen Änderungen
watch(() => props.modelValue, v => {
    if (!view || v === view.state.doc.toString()) return;
    view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: v } });
});
watch(() => props.readonly, v => view?.dispatch({ effects: readonlyCompartment.reconfigure(EditorState.readOnly.of(v)) }));

/** Zeigt eine Zeile (1-basiert) an und markiert sie — ohne den Fokus zu holen, damit ein Formularfeld bedienbar bleibt */
function goToLine(n, focus = false) {
    if (!view) return;
    const line = view.state.doc.line(Math.max(1, Math.min(view.state.doc.lines, n)));
    view.dispatch({ selection: { anchor: line.from, head: line.to }, scrollIntoView: true });
    if (focus) view.focus();
}

defineExpose({ goToLine, focus: () => view?.focus() });
</script>

<style scoped>
.tpl-code {
    height: 100%;
    min-height: 0;
    overflow: hidden;
    border: 1px solid rgba(0, 0, 0, 0.12);
    border-radius: 4px;
    background: #fff;
}
.tpl-code :deep(.cm-editor) {
    height: 100%;
}
</style>
