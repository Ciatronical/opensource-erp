<!-- src/core/components/html.editor.component.vue -->

<template>
    <div class="html-editor" :class="{ 'html-editor--focused': isFocused }">
        <div v-if="editor" class="html-editor__toolbar">
            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-format-bold"
                    size="small"
                    :color="editor.isActive('bold') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleBold().run()"
                    :title="t('HtmlEditor.bold')"
                />
                <v-btn
                    icon="mdi-format-italic"
                    size="small"
                    :color="editor.isActive('italic') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleItalic().run()"
                    :title="t('HtmlEditor.italic')"
                />
                <v-btn
                    icon="mdi-format-underline"
                    size="small"
                    :color="editor.isActive('underline') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleUnderline().run()"
                    :title="t('HtmlEditor.underline')"
                />
                <v-btn
                    icon="mdi-format-strikethrough"
                    size="small"
                    :color="editor.isActive('strike') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleStrike().run()"
                    :title="t('HtmlEditor.strikethrough')"
                />
            </v-btn-group>

            <v-divider vertical class="mx-1" />

            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-format-list-bulleted"
                    size="small"
                    :color="editor.isActive('bulletList') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleBulletList().run()"
                    :title="t('HtmlEditor.bulletList')"
                />
                <v-btn
                    icon="mdi-format-list-numbered"
                    size="small"
                    :color="editor.isActive('orderedList') ? 'primary' : undefined"
                    @click="editor.chain().focus().toggleOrderedList().run()"
                    :title="t('HtmlEditor.orderedList')"
                />
            </v-btn-group>

            <v-divider vertical class="mx-1" />

            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-link"
                    size="small"
                    :color="editor.isActive('link') ? 'primary' : undefined"
                    @click="openLinkDialog"
                    :title="t('HtmlEditor.link')"
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

            <v-btn-group density="compact" variant="text">
                <v-btn
                    icon="mdi-undo"
                    size="small"
                    :disabled="!editor.can().undo()"
                    @click="editor.chain().focus().undo().run()"
                    :title="t('HtmlEditor.undo')"
                />
                <v-btn
                    icon="mdi-redo"
                    size="small"
                    :disabled="!editor.can().redo()"
                    @click="editor.chain().focus().redo().run()"
                    :title="t('HtmlEditor.redo')"
                />
            </v-btn-group>
        </div>

        <editor-content :editor="editor" class="html-editor__content" />

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

import { defineComponent, ref, watch, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import Link from '@tiptap/extension-link'
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
         * Placeholder-Text
         */
        placeholder: {
            type: String,
            default: ''
        }
    },
    emits: ['update:modelValue', 'blur'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const isFocused = ref(false)
        const linkInputRef = ref(null)

        // Link Dialog State
        const linkDialog = ref({
            show: false,
            url: ''
        })

        const editor = useEditor({
            content: props.modelValue,
            extensions: [
                StarterKit.configure({
                    heading: false,
                    codeBlock: false,
                    code: false,
                    blockquote: false,
                    horizontalRule: false
                }),
                Underline,
                Link.configure({
                    openOnClick: false,
                    HTMLAttributes: {
                        target: '_blank',
                        rel: 'noopener noreferrer'
                    }
                }),
                CtrlClickLink
            ],
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

        // Inhalt aktualisieren wenn sich modelValue ändert
        watch(() => props.modelValue, (newValue) => {
            if (editor.value && newValue !== editor.value.getHTML()) {
                editor.value.commands.setContent(newValue, false)
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
            isFocused,
            linkDialog,
            linkInputRef,
            openLinkDialog,
            confirmLink
        }
    }
})
</script>

<style scoped>
.html-editor {
    position: relative;
    border: 1px solid rgba(0, 0, 0, 0.38);
    border-radius: 4px;
    transition: border-color 0.2s;
}

.html-editor--focused {
    border-color: rgb(var(--v-theme-primary));
    border-width: 2px;
}

.html-editor__toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 2px;
    padding: 4px 8px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.12);
    background-color: rgba(0, 0, 0, 0.02);
}

.html-editor__content {
    padding: 8px 12px;
    min-height: 150px;
    max-height: 400px;
    overflow-y: auto;
}

.html-editor__content :deep(.tiptap) {
    outline: none;
    min-height: 134px;
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

.html-editor__label {
    position: absolute;
    top: -9px;
    left: 10px;
    padding: 0 4px;
    font-size: 12px;
    color: rgba(0, 0, 0, 0.6);
    background-color: white;
}

.html-editor--focused .html-editor__label {
    color: rgb(var(--v-theme-primary));
}
</style>