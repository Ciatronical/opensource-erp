<!-- src/core/components/html.editor.component.vue -->

<template>
    <div class="html-editor" :class="{ 'html-editor--focused': isFocused, 'html-editor--full': isFull }">
        <div v-if="editor" class="html-editor__toolbar">

            <!-- Blocktyp (Absatz / Überschrift) -->
            <template v-if="isFull">
                <v-menu>
                    <template #activator="{ props: menuProps }">
                        <v-btn
                            v-bind="menuProps"
                            size="small"
                            variant="text"
                            class="html-editor__block-btn text-none"
                            append-icon="mdi-menu-down"
                            :title="t('HtmlEditor.textStyle')"
                        >
                            {{ currentBlockLabel }}
                        </v-btn>
                    </template>
                    <v-list density="compact">
                        <v-list-item
                            v-for="item in blockTypes"
                            :key="item.key"
                            :active="item.isActive()"
                            :prepend-icon="item.icon"
                            :title="item.label"
                            :subtitle="item.shortcut"
                            @click="item.run()"
                        />
                    </v-list>
                </v-menu>

                <v-divider vertical class="mx-1" />
            </template>

            <!-- Zeichenformate -->
            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-format-bold"
                    size="small"
                    :color="editor.isActive('bold') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleBold().run()"
                    :title="withShortcut(t('HtmlEditor.bold'), 'Ctrl+B')"
                />
                <v-btn
                    icon="mdi-format-italic"
                    size="small"
                    :color="editor.isActive('italic') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleItalic().run()"
                    :title="withShortcut(t('HtmlEditor.italic'), 'Ctrl+I')"
                />
                <v-btn
                    icon="mdi-format-underline"
                    size="small"
                    :color="editor.isActive('underline') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleUnderline().run()"
                    :title="withShortcut(t('HtmlEditor.underline'), 'Ctrl+U')"
                />
                <v-btn
                    icon="mdi-format-strikethrough"
                    size="small"
                    :color="editor.isActive('strike') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleStrike().run()"
                    :title="withShortcut(t('HtmlEditor.strikethrough'), 'Ctrl+Shift+S')"
                />
                <v-btn
                    v-if="isFull"
                    icon="mdi-code-tags"
                    size="small"
                    :color="editor.isActive('code') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleCode().run()"
                    :title="withShortcut(t('HtmlEditor.code'), 'Ctrl+E')"
                />
            </v-btn-group>

            <v-divider vertical class="mx-1" />

            <!-- Listen -->
            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-format-list-bulleted"
                    size="small"
                    :color="editor.isActive('bulletList') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleBulletList().run()"
                    :title="withShortcut(t('HtmlEditor.bulletList'), 'Ctrl+Shift+8')"
                />
                <v-btn
                    icon="mdi-format-list-numbered"
                    size="small"
                    :color="editor.isActive('orderedList') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleOrderedList().run()"
                    :title="withShortcut(t('HtmlEditor.orderedList'), 'Ctrl+Shift+7')"
                />
                <v-btn
                    v-if="isFull"
                    icon="mdi-format-list-checks"
                    size="small"
                    :color="editor.isActive('taskList') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleTaskList().run()"
                    :title="withShortcut(t('HtmlEditor.taskList'), 'Ctrl+Shift+9')"
                />
            </v-btn-group>

            <!-- Blöcke -->
            <template v-if="isFull">
                <v-divider vertical class="mx-1" />

                <v-btn-group density="compact" variant="text">
                    <v-btn
                        icon="mdi-format-quote-close"
                        size="small"
                        :color="editor.isActive('blockquote') ? 'primary' : undefined"
                        @click="editor.chain().focus().toggleBlockquote().run()"
                        :title="withShortcut(t('HtmlEditor.blockquote'), 'Ctrl+Shift+B')"
                    />
                    <v-btn
                        icon="mdi-code-braces-box"
                        size="small"
                        :color="editor.isActive('codeBlock') ? 'primary' : undefined"
                        @click="editor.chain().focus().toggleCodeBlock().run()"
                        :title="withShortcut(t('HtmlEditor.codeBlock'), 'Ctrl+Alt+C')"
                    />
                    <v-btn
                        icon="mdi-minus"
                        size="small"
                        @click="editor.chain().focus().setHorizontalRule().run()"
                        :title="t('HtmlEditor.horizontalRule')"
                    />
                </v-btn-group>

                <v-divider vertical class="mx-1" />

                <!-- Tabelle -->
                <v-menu>
                    <template #activator="{ props: menuProps }">
                        <v-btn
                            v-bind="menuProps"
                            icon="mdi-table"
                            size="small"
                            variant="text"
                            :color="editor.isActive('table') ? 'primary' : undefined"
                            :title="t('HtmlEditor.table')"
                        />
                    </template>
                    <v-list density="compact">
                        <v-list-item
                            prepend-icon="mdi-table-plus"
                            :title="t('HtmlEditor.insertTable')"
                            @click="editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()"
                        />
                        <template v-if="editor.isActive('table')">
                            <v-divider />
                            <v-list-item prepend-icon="mdi-table-row-plus-before" :title="t('HtmlEditor.addRowBefore')" @click="editor.chain().focus().addRowBefore().run()" />
                            <v-list-item prepend-icon="mdi-table-row-plus-after" :title="t('HtmlEditor.addRowAfter')" @click="editor.chain().focus().addRowAfter().run()" />
                            <v-list-item prepend-icon="mdi-table-row-remove" :title="t('HtmlEditor.deleteRow')" @click="editor.chain().focus().deleteRow().run()" />
                            <v-divider />
                            <v-list-item prepend-icon="mdi-table-column-plus-before" :title="t('HtmlEditor.addColumnBefore')" @click="editor.chain().focus().addColumnBefore().run()" />
                            <v-list-item prepend-icon="mdi-table-column-plus-after" :title="t('HtmlEditor.addColumnAfter')" @click="editor.chain().focus().addColumnAfter().run()" />
                            <v-list-item prepend-icon="mdi-table-column-remove" :title="t('HtmlEditor.deleteColumn')" @click="editor.chain().focus().deleteColumn().run()" />
                            <v-divider />
                            <v-list-item prepend-icon="mdi-table-headers-eye" :title="t('HtmlEditor.toggleHeaderRow')" @click="editor.chain().focus().toggleHeaderRow().run()" />
                            <v-list-item prepend-icon="mdi-table-remove" :title="t('HtmlEditor.deleteTable')" class="text-error" @click="editor.chain().focus().deleteTable().run()" />
                        </template>
                    </v-list>
                </v-menu>
            </template>

            <v-divider vertical class="mx-1" />

            <!-- Link -->
            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-link"
                    size="small"
                    :color="editor.isActive('link') ? 'primary' : undefined"
                    @click="openLinkDialog"
                    :title="withShortcut(t('HtmlEditor.link'), 'Ctrl+K')"
                />
                <v-btn
                    v-if="editor.isActive('link')"
                    icon="mdi-link-off"
                    size="small"
                    @click="editor.chain().focus().unsetLink().run()"
                    :title="t('HtmlEditor.removeLink')"
                />
            </v-btn-group>

            <v-divider vertical class="mx-1" />

            <!-- Rückgängig / Formatierung löschen -->
            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-undo"
                    size="small"
                    :disabled="!editor.can().undo()"
                    @click="editor.chain().focus().undo().run()"
                    :title="withShortcut(t('HtmlEditor.undo'), 'Ctrl+Z')"
                />
                <v-btn
                    icon="mdi-redo"
                    size="small"
                    :disabled="!editor.can().redo()"
                    @click="editor.chain().focus().redo().run()"
                    :title="withShortcut(t('HtmlEditor.redo'), 'Ctrl+Y')"
                />
                <v-btn
                    v-if="isFull"
                    icon="mdi-format-clear"
                    size="small"
                    @click="editor.chain().focus().clearNodes().unsetAllMarks().run()"
                    :title="t('HtmlEditor.clearFormatting')"
                />
            </v-btn-group>
        </div>

        <editor-content :editor="editor" class="html-editor__content" :style="contentStyle" />

        <div v-if="label" class="html-editor__label">{{ label }}</div>

        <!-- Link Dialog -->
        <v-dialog v-model="linkDialog.show" max-width="450" @keydown.enter="confirmLink">
            <v-card>
                <v-card-title class="d-flex align-center py-3 px-4 bg-primary text-white">
                    <v-icon class="mr-2">mdi-link</v-icon>
                    {{ t('HtmlEditor.linkDialogTitle') }}
                </v-card-title>
                <v-card-text class="pt-4">
                    <v-text-field
                        ref="linkInputRef"
                        v-model="linkDialog.url"
                        :label="t('HtmlEditor.urlLabel')"
                        placeholder="https://"
                        variant="outlined"
                        density="compact"
                        prepend-inner-icon="mdi-web"
                        autofocus
                        :hint="t('HtmlEditor.urlHint')"
                        persistent-hint
                    />
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer />
                    <v-btn variant="text" @click="linkDialog.show = false">
                        {{ t('HtmlEditor.cancel') }}
                    </v-btn>
                    <v-btn color="primary" variant="elevated" @click="confirmLink">
                        {{ t('HtmlEditor.confirm') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
// src/core/components/html.editor.component.vue

import { defineComponent, ref, computed, watch, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import Link from '@tiptap/extension-link'
import { TaskList, TaskItem } from '@tiptap/extension-list'
import { TableKit } from '@tiptap/extension-table'
import { Placeholder } from '@tiptap/extensions'
import { Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'

/**
 * Extension für Ctrl+Click zum Öffnen von Links
 */
const CtrlClickLink = Extension.create({
    name: 'ctrlClickLink',

    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: new PluginKey('ctrlClickLink'),
                props: {
                    handleClick(view, pos, event) {
                        // Prüfen ob Ctrl/Cmd gedrückt ist
                        if (event.ctrlKey || event.metaKey) {
                            // Link-Element finden
                            const linkElement = event.target.closest('a')
                            if (linkElement) {
                                const href = linkElement.getAttribute('href')
                                if (href) {
                                    event.preventDefault()
                                    window.open(href, '_blank', 'noopener,noreferrer')
                                    return true
                                }
                            }
                        }
                        return false
                    }
                }
            })
        ]
    }
})

export default defineComponent({
    name: 'HtmlEditorComponent',
    components: {
        EditorContent
    },
    props: {
        /**
         * HTML-Inhalt (v-model)
         */
        modelValue: {
            type: String,
            default: ''
        },
        /**
         * Label für das Feld
         */
        label: {
            type: String,
            default: ''
        },
        /**
         * Placeholder-Text (wird im leeren Editor angezeigt)
         */
        placeholder: {
            type: String,
            default: ''
        },
        /**
         * Toolbar-Umfang: 'basic' (Zeichenformate, Listen, Link) für kurze Texte
         * wie E-Mails oder Belegtexte, 'full' (zusätzlich Überschriften, Zitate,
         * Code, Tabellen, Checklisten) für längere Dokumente wie Wiki-Artikel.
         */
        toolbar: {
            type: String,
            default: 'basic',
            validator: (v) => ['basic', 'full'].includes(v)
        },
        /**
         * Mindesthöhe des Textbereichs in Pixeln
         */
        minHeight: {
            type: Number,
            default: 150
        },
        /**
         * Maximale Höhe des Textbereichs in Pixeln, danach scrollt der Editor
         * intern. 0 = unbegrenzt, der Editor wächst mit dem Inhalt und die
         * Toolbar bleibt beim Scrollen der Seite oben sichtbar.
         */
        maxHeight: {
            type: Number,
            default: 400
        }
    },
    emits: ['update:modelValue', 'blur'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const isFocused = ref(false)
        const linkInputRef = ref(null)
        const isFull = computed(() => props.toolbar === 'full')

        const contentStyle = computed(() => ({
            minHeight: props.minHeight + 'px',
            maxHeight: props.maxHeight > 0 ? props.maxHeight + 'px' : 'none',
            overflowY: props.maxHeight > 0 ? 'auto' : 'visible'
        }))

        // Link Dialog State
        const linkDialog = ref({
            show: false,
            url: ''
        })

        /**
         * Tastenkürzel an einen Tooltip anhängen
         */
        function withShortcut(label, shortcut) {
            return `${label} (${shortcut})`
        }

        /**
         * Extension für Ctrl+K (Link-Dialog öffnen)
         */
        const LinkShortcut = Extension.create({
            name: 'linkShortcut',
            addKeyboardShortcuts() {
                return {
                    'Mod-k': () => {
                        openLinkDialog()
                        return true
                    }
                }
            }
        })

        const extensions = [
            StarterKit.configure({
                heading: isFull.value ? { levels: [1, 2, 3] } : false,
                codeBlock: isFull.value ? {} : false,
                code: isFull.value ? {} : false,
                blockquote: isFull.value ? {} : false,
                horizontalRule: isFull.value ? {} : false,
                // TipTap 3: StarterKit enthaelt jetzt link + underline. Hier deaktivieren,
                // da beide unten separat (mit eigener Konfiguration) eingebunden werden —
                // sonst "Duplicate extension names found: ['link','underline']".
                link: false,
                underline: false
            }),
            Underline,
            Link.configure({
                openOnClick: false,
                HTMLAttributes: {
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }
            }),
            Placeholder.configure({
                placeholder: () => props.placeholder
            }),
            CtrlClickLink,
            LinkShortcut
        ]

        if (isFull.value) {
            extensions.push(
                TaskList,
                TaskItem.configure({ nested: true }),
                TableKit.configure({
                    table: { resizable: false }
                })
            )
        }

        const editor = useEditor({
            content: props.modelValue,
            extensions,
            onUpdate: ({ editor }) => {
                emit('update:modelValue', editor.getHTML())
            },
            onFocus: () => {
                isFocused.value = true
            },
            onBlur: () => {
                isFocused.value = false
                emit('blur')
            }
        })

        /**
         * Blocktypen für das Absatz/Überschrift-Menü
         */
        const blockTypes = computed(() => {
            if (!editor.value) return []
            const e = editor.value
            return [
                {
                    key: 'paragraph',
                    label: t('HtmlEditor.paragraph'),
                    icon: 'mdi-format-paragraph',
                    shortcut: 'Ctrl+Alt+0',
                    isActive: () => e.isActive('paragraph') && !e.isActive('heading'),
                    run: () => e.chain().focus().setParagraph().run()
                },
                ...[1, 2, 3].map(level => ({
                    key: 'heading' + level,
                    label: t('HtmlEditor.heading', { level }),
                    icon: 'mdi-format-header-' + level,
                    shortcut: 'Ctrl+Alt+' + level,
                    isActive: () => e.isActive('heading', { level }),
                    run: () => e.chain().focus().toggleHeading({ level }).run()
                }))
            ]
        })

        const currentBlockLabel = computed(() => {
            const active = blockTypes.value.find(b => b.isActive())
            return active ? active.label : t('HtmlEditor.paragraph')
        })

        // Inhalt aktualisieren wenn sich modelValue ändert
        watch(() => props.modelValue, (newValue) => {
            if (editor.value && newValue !== editor.value.getHTML()) {
                editor.value.commands.setContent(newValue, { emitUpdate: false })
            }
        })

        /**
         * Öffnet den Link-Dialog
         */
        function openLinkDialog() {
            const previousUrl = editor.value.getAttributes('link').href || ''
            linkDialog.value.url = previousUrl || 'https://'
            linkDialog.value.show = true

            // Fokus auf Input setzen
            nextTick(() => {
                if (linkInputRef.value) {
                    linkInputRef.value.focus()
                }
            })
        }

        /**
         * Bestätigt den Link
         */
        function confirmLink() {
            const url = linkDialog.value.url.trim()

            if (!url || url === 'https://') {
                // Leere URL -> Link entfernen
                editor.value.chain().focus().extendMarkRange('link').unsetLink().run()
            }
            else {
                // URL setzen
                editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
            }

            linkDialog.value.show = false
        }

        onBeforeUnmount(() => {
            if (editor.value) {
                editor.value.destroy()
            }
        })

        return {
            t,
            editor,
            isFull,
            isFocused,
            contentStyle,
            linkDialog,
            linkInputRef,
            blockTypes,
            currentBlockLabel,
            withShortcut,
            openLinkDialog,
            confirmLink
        }
    }
})
</script>

<style scoped>
.html-editor {
    position: relative;
    border: 1px solid rgba(var(--v-border-color), 0.38);
    border-radius: 4px;
    transition: border-color 0.2s;
    background-color: rgb(var(--v-theme-surface));
}

.html-editor--focused {
    border-color: rgb(var(--v-theme-primary));
    box-shadow: inset 0 0 0 1px rgb(var(--v-theme-primary));
}

.html-editor__toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 2px;
    padding: 4px 8px;
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    background-color: rgba(var(--v-theme-on-surface), 0.03);
    border-radius: 4px 4px 0 0;
}

/* Bei unbegrenzter Höhe bleibt die Toolbar beim Scrollen der Seite sichtbar */
.html-editor--full .html-editor__toolbar {
    position: sticky;
    top: var(--v-layout-top, 0px);
    z-index: 2;
    background-color: rgb(var(--v-theme-surface));
}

.html-editor__block-btn {
    min-width: 130px;
    justify-content: space-between;
}

.html-editor__content {
    padding: 8px 12px;
}

.html-editor--full .html-editor__content {
    padding: 16px 20px;
    font-size: 0.95rem;
    line-height: 1.7;
}

.html-editor__content :deep(.tiptap) {
    outline: none;
    min-height: inherit;
}

/* Placeholder im leeren Editor */
.html-editor__content :deep(.tiptap p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
    color: rgba(var(--v-theme-on-surface), 0.42);
}

.html-editor__content :deep(.tiptap p) {
    margin: 0 0 0.5em 0;
}

.html-editor__content :deep(.tiptap p:last-child) {
    margin-bottom: 0;
}

.html-editor__content :deep(.tiptap ul),
.html-editor__content :deep(.tiptap ol) {
    padding-left: 1.5em;
    margin: 0.5em 0;
}

.html-editor__content :deep(.tiptap a) {
    color: rgb(var(--v-theme-primary));
    text-decoration: underline;
    cursor: pointer;
}

.html-editor__content :deep(.tiptap a:hover) {
    text-decoration: underline;
    opacity: 0.8;
}

/* Erweiterte Blöcke (nur toolbar="full") */
.html-editor__content :deep(.tiptap h1),
.html-editor__content :deep(.tiptap h2),
.html-editor__content :deep(.tiptap h3) {
    margin: 1em 0 0.5em 0;
    line-height: 1.3;
}
.html-editor__content :deep(.tiptap h1:first-child),
.html-editor__content :deep(.tiptap h2:first-child),
.html-editor__content :deep(.tiptap h3:first-child) {
    margin-top: 0;
}
.html-editor__content :deep(.tiptap h1) { font-size: 1.6em; }
.html-editor__content :deep(.tiptap h2) { font-size: 1.35em; }
.html-editor__content :deep(.tiptap h3) { font-size: 1.15em; }

.html-editor__content :deep(.tiptap blockquote) {
    border-left: 3px solid rgb(var(--v-theme-primary));
    padding-left: 1em;
    margin: 1em 0;
    color: rgba(var(--v-theme-on-surface), 0.7);
}

.html-editor__content :deep(.tiptap code) {
    background: rgba(var(--v-theme-on-surface), 0.07);
    padding: 0.15em 0.4em;
    border-radius: 3px;
    font-size: 0.9em;
}

.html-editor__content :deep(.tiptap pre) {
    background: rgba(var(--v-theme-on-surface), 0.07);
    padding: 1em;
    border-radius: 4px;
    overflow-x: auto;
    margin: 0.75em 0;
    font-family: monospace;
}
.html-editor__content :deep(.tiptap pre code) {
    background: none;
    padding: 0;
}

.html-editor__content :deep(.tiptap hr) {
    border: none;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    margin: 1.5em 0;
}

/* Checklisten */
.html-editor__content :deep(.tiptap ul[data-type="taskList"]) {
    list-style: none;
    padding-left: 0.25em;
}
.html-editor__content :deep(.tiptap ul[data-type="taskList"] li) {
    display: flex;
    align-items: flex-start;
    gap: 0.5em;
}
.html-editor__content :deep(.tiptap ul[data-type="taskList"] li > label) {
    flex: 0 0 auto;
    margin-top: 0.25em;
    user-select: none;
}
.html-editor__content :deep(.tiptap ul[data-type="taskList"] li > div) {
    flex: 1 1 auto;
}
.html-editor__content :deep(.tiptap ul[data-type="taskList"] li[data-checked="true"] > div) {
    text-decoration: line-through;
    opacity: 0.6;
}

/* Tabellen */
.html-editor__content :deep(.tiptap table) {
    border-collapse: collapse;
    width: 100%;
    margin: 0.75em 0;
    table-layout: fixed;
}
.html-editor__content :deep(.tiptap th),
.html-editor__content :deep(.tiptap td) {
    border: 1px solid rgba(var(--v-border-color), 0.4);
    padding: 0.4em 0.6em;
    text-align: left;
    vertical-align: top;
    position: relative;
}
.html-editor__content :deep(.tiptap th) {
    background: rgba(var(--v-theme-on-surface), 0.05);
    font-weight: 600;
}
.html-editor__content :deep(.tiptap td p),
.html-editor__content :deep(.tiptap th p) {
    margin: 0;
}
.html-editor__content :deep(.tiptap .selectedCell::after) {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(var(--v-theme-primary), 0.12);
    pointer-events: none;
}

.html-editor__label {
    position: absolute;
    top: -9px;
    left: 10px;
    padding: 0 4px;
    font-size: 12px;
    color: rgba(var(--v-theme-on-surface), 0.6);
    background-color: rgb(var(--v-theme-surface));
    z-index: 3;
}

.html-editor--focused .html-editor__label {
    color: rgb(var(--v-theme-primary));
}
</style>
