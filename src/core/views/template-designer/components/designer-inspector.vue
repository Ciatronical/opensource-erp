<!-- src/core/views/template-designer/components/designer-inspector.vue -->
<!--
    Rechte Spalte: Eigenschaften der Auswahl. Ohne Auswahl die Seite (Ränder,
    Schrift, Farben, Marken, Hintergrund), sonst der Baustein oder Abschnitt.
    Texte bekommen ein Feld-Menü mit Beispielwerten und Vorschläge aus der
    Firmenkonfiguration — ein Klick fügt den Wert an der Cursorposition ein.

    Die Komponente ändert die übergebenen Objekte direkt und meldet jede
    abgeschlossene Eingabe mit 'commit' an den Verlauf.
-->
<template>
    <div class="tpl-inspector">
        <!-- ───────── Seite ───────── -->
        <template v-if="!selected">
            <div class="tpl-inspector-head">
                <v-icon size="small" class="me-2">mdi-file-outline</v-icon>{{ t('TemplateDesigner.inspector.page') }}
            </div>
            <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.bodyArea') }}</div>
            <div class="tpl-grid-2">
                <v-text-field v-model.number="page.marginLeft" type="number" step="1" :label="t('TemplateDesigner.inspector.marginLeft')" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="page.marginRight" type="number" step="1" :label="t('TemplateDesigner.inspector.marginRight')" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="page.bodyTop" type="number" step="1" :label="t('TemplateDesigner.inspector.bodyTop')" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="page.bodyTopFollowing" type="number" step="1" :label="t('TemplateDesigner.inspector.bodyTopFollowing')" suffix="mm" v-bind="field" :error="page.bodyTopFollowing > page.bodyTop" @change="commit" />
                <v-text-field v-model.number="page.bodyBottom" type="number" step="1" :label="t('TemplateDesigner.inspector.bodyBottom')" suffix="mm" v-bind="field" @change="commit" />
            </div>
            <div v-if="page.bodyTopFollowing > page.bodyTop" class="text-caption text-error mb-2">{{ t('TemplateDesigner.inspector.followingTooLow') }}</div>

            <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.typography') }}</div>
            <div class="tpl-grid-2">
                <v-select v-model="page.font" :items="fontItems" :label="t('TemplateDesigner.inspector.font')" v-bind="field" @update:model-value="commit" />
                <v-text-field v-model.number="page.fontSize" type="number" step="0.5" min="6" max="14" :label="t('TemplateDesigner.inspector.fontSize')" suffix="pt" v-bind="field" @change="commit" />
                <color-field v-model="page.textColor" :label="t('TemplateDesigner.inspector.textColor')" @change="commit" />
                <color-field v-model="page.accentColor" :label="t('TemplateDesigner.inspector.accentColor')" @change="commit" />
                <v-select v-model="page.currency" :items="currencyItems" :label="t('TemplateDesigner.inspector.currency')" v-bind="field" @update:model-value="commit" />
            </div>

            <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.marks') }}</div>
            <div class="tpl-grid-2">
                <v-select v-model="page.foldMarks" :items="foldItems" :label="t('TemplateDesigner.inspector.foldMarks')" v-bind="field" @update:model-value="commit" />
                <v-switch v-model="page.punchMark" :label="t('TemplateDesigner.inspector.punchMark')" color="primary" density="compact" hide-details @update:model-value="commit" />
            </div>

            <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.background') }}</div>
            <v-select v-model="page.background" :items="imageItems" :label="t('TemplateDesigner.inspector.backgroundImage')" v-bind="field" clearable @update:model-value="commit" />
            <v-select v-if="page.background" v-model="page.backgroundPages" :items="backgroundPagesItems" :label="t('TemplateDesigner.inspector.backgroundPages')" v-bind="field" @update:model-value="commit" />
            <div class="text-caption text-medium-emphasis">{{ t('TemplateDesigner.inspector.backgroundHint') }}</div>
        </template>

        <!-- ───────── Fließbereich ───────── -->
        <template v-else-if="selected === 'body'">
            <div class="tpl-inspector-head">
                <v-icon size="small" class="me-2">mdi-view-sequential-outline</v-icon>{{ t('TemplateDesigner.inspector.bodyArea') }}
            </div>
            <div class="text-body-2 mb-3">{{ t('TemplateDesigner.inspector.bodyHint') }}</div>
            <div class="tpl-grid-2">
                <v-text-field v-model.number="page.bodyTop" type="number" step="1" :label="t('TemplateDesigner.inspector.bodyTop')" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="page.bodyTopFollowing" type="number" step="1" :label="t('TemplateDesigner.inspector.bodyTopFollowing')" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="page.bodyBottom" type="number" step="1" :label="t('TemplateDesigner.inspector.bodyBottom')" suffix="mm" v-bind="field" @change="commit" />
            </div>
            <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.sectionsOrder') }}</div>
            <VueDraggable :model-value="design.body.sections" handle=".tpl-order-grip" :animation="150" @update:model-value="$emit('reorder', $event)">
                <div v-for="s in design.body.sections" :key="s.id" class="tpl-order-row" @click="$emit('select', s.id)">
                    <v-icon size="small" class="tpl-order-grip me-1">mdi-drag-vertical</v-icon>
                    <span class="tpl-order-type">{{ t(`TemplateDesigner.sections.${s.type}`) }}</span>
                    <span class="tpl-order-text text-truncate">{{ sectionSummary(s) }}</span>
                </div>
            </VueDraggable>
        </template>

        <!-- ───────── Baustein ───────── -->
        <template v-else-if="block">
            <div class="tpl-inspector-head">
                <v-icon size="small" class="me-2">{{ BLOCK_TYPES[block.type]?.icon }}</v-icon>{{ t(`TemplateDesigner.blocks.${block.type}`) }}
                <v-spacer />
                <v-btn icon="mdi-content-copy" size="x-small" variant="text" :title="t('TemplateDesigner.inspector.duplicate')" @click="$emit('duplicate')" />
                <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" :title="t('TemplateDesigner.inspector.delete')" @click="$emit('delete')" />
            </div>

            <div class="tpl-grid-2">
                <v-text-field v-model.number="block.x" type="number" step="0.5" label="X" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="block.y" type="number" step="0.5" label="Y" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="block.w" type="number" step="0.5" :label="t('TemplateDesigner.inspector.width')" suffix="mm" v-bind="field" @change="commit" />
                <v-text-field v-model.number="block.h" type="number" step="0.5" :label="t('TemplateDesigner.inspector.height')" suffix="mm" v-bind="field" @change="commit" />
            </div>
            <v-btn-toggle v-model="block.pages" mandatory density="compact" variant="outlined" color="primary" class="mb-3 tpl-toggle" @update:model-value="commit">
                <v-btn value="all" size="small">{{ t('TemplateDesigner.pages.all') }}</v-btn>
                <v-btn value="first" size="small">{{ t('TemplateDesigner.pages.first') }}</v-btn>
                <v-btn value="following" size="small">{{ t('TemplateDesigner.pages.following') }}</v-btn>
            </v-btn-toggle>

            <!-- Text / Seitenzahl -->
            <template v-if="block.type === 'text' || block.type === 'pagenumber'">
                <text-editor
                    v-model="block.props.text"
                    :field-groups="fieldGroups"
                    :suggestions="suggestions"
                    :label="t('TemplateDesigner.inspector.text')"
                    :hint="block.type === 'pagenumber' ? t('TemplateDesigner.inspector.pageTokens') : t('TemplateDesigner.inspector.markupHint')"
                    @change="commit"
                />
                <div class="tpl-grid-2">
                    <v-text-field v-model.number="block.props.fontSize" type="number" step="0.5" min="5" max="40" :label="t('TemplateDesigner.inspector.fontSize')" suffix="pt" :placeholder="String(page.fontSize)" v-bind="field" @change="commit" />
                    <v-text-field v-model.number="block.props.lineHeight" type="number" step="0.05" min="0.9" max="2.5" :label="t('TemplateDesigner.inspector.lineHeight')" placeholder="1.25" v-bind="field" @change="commit" />
                    <color-field v-model="block.props.color" :label="t('TemplateDesigner.inspector.color')" clearable @change="commit" />
                    <color-field v-model="block.props.fill" :label="t('TemplateDesigner.inspector.fill')" clearable @change="commit" />
                </div>
                <div class="d-flex align-center flex-wrap ga-1 mb-2">
                    <v-btn-toggle v-model="block.props.align" density="compact" variant="outlined" color="primary" class="tpl-toggle" @update:model-value="commit">
                        <v-btn value="left" size="small" icon="mdi-format-align-left" />
                        <v-btn value="center" size="small" icon="mdi-format-align-center" />
                        <v-btn value="right" size="small" icon="mdi-format-align-right" />
                    </v-btn-toggle>
                    <v-btn size="small" variant="outlined" :color="block.props.bold ? 'primary' : undefined" icon="mdi-format-bold" @click="toggle(block.props, 'bold')" />
                    <v-btn size="small" variant="outlined" :color="block.props.italic ? 'primary' : undefined" icon="mdi-format-italic" @click="toggle(block.props, 'italic')" />
                    <v-btn size="small" variant="outlined" :color="block.props.underline ? 'primary' : undefined" icon="mdi-format-underline" :title="t('TemplateDesigner.inspector.underlineRule')" @click="toggle(block.props, 'underline')" />
                </div>
            </template>

            <!-- Infobox -->
            <template v-else-if="block.type === 'infobox'">
                <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.rows') }}</div>
                <VueDraggable v-model="block.props.rows" handle=".tpl-order-grip" :animation="150" @end="commit">
                    <div v-for="(row, i) in block.props.rows" :key="i" class="tpl-row-card">
                        <div class="tpl-row-edit">
                            <v-icon size="small" class="tpl-order-grip">mdi-drag-vertical</v-icon>
                            <v-text-field v-model="row.label" :placeholder="t('TemplateDesigner.inspector.label')" v-bind="fieldInline" class="tpl-row-label" @change="commit" />
                            <v-btn :icon="row.hideEmpty ? 'mdi-eye-off-outline' : 'mdi-eye-outline'" size="x-small" variant="text" :title="t('TemplateDesigner.inspector.hideEmpty')" :color="row.hideEmpty ? 'primary' : undefined" @click="row.hideEmpty = !row.hideEmpty; commit()" />
                            <v-btn icon="mdi-close" size="x-small" variant="text" @click="block.props.rows.splice(i, 1); commit()" />
                        </div>
                        <field-input v-model="row.value" :field-groups="fieldGroups" :placeholder="t('TemplateDesigner.inspector.value')" class="tpl-row-value" @change="commit" />
                    </div>
                </VueDraggable>
                <v-btn size="small" variant="text" prepend-icon="mdi-plus" class="mb-2" @click="block.props.rows.push({ label: '', value: '', hideEmpty: true }); commit()">{{ t('TemplateDesigner.inspector.addRow') }}</v-btn>
                <div class="tpl-grid-2">
                    <v-text-field v-model.number="block.props.fontSize" type="number" step="0.5" :label="t('TemplateDesigner.inspector.fontSize')" suffix="pt" v-bind="field" @change="commit" />
                    <v-text-field v-model.number="block.props.labelWidth" type="number" step="1" :label="t('TemplateDesigner.inspector.labelWidth')" suffix="mm" v-bind="field" @change="commit" />
                    <v-switch v-model="block.props.labelBold" :label="t('TemplateDesigner.inspector.labelBold')" color="primary" density="compact" hide-details @update:model-value="commit" />
                    <v-select v-model="block.props.valueAlign" :items="[{ title: t('TemplateDesigner.inspector.alignRight'), value: 'right' }, { title: t('TemplateDesigner.inspector.alignLeft'), value: 'left' }]" :label="t('TemplateDesigner.inspector.valueAlign')" v-bind="field" @update:model-value="commit" />
                </div>
            </template>

            <!-- Bild -->
            <template v-else-if="block.type === 'image'">
                <v-select v-model="block.props.image" :items="imageItems" :label="t('TemplateDesigner.inspector.image')" v-bind="field" @update:model-value="commit" />
                <v-btn-toggle v-model="block.props.align" density="compact" variant="outlined" color="primary" class="mb-2 tpl-toggle" @update:model-value="commit">
                    <v-btn value="left" size="small" icon="mdi-format-align-left" />
                    <v-btn value="center" size="small" icon="mdi-format-align-center" />
                    <v-btn value="right" size="small" icon="mdi-format-align-right" />
                </v-btn-toggle>
                <div class="text-caption text-medium-emphasis">{{ t('TemplateDesigner.inspector.imageHint') }}</div>
            </template>

            <!-- Linie -->
            <template v-else-if="block.type === 'line'">
                <div class="tpl-grid-2">
                    <v-text-field v-model.number="block.props.thickness" type="number" step="0.1" min="0.1" max="5" :label="t('TemplateDesigner.inspector.thickness')" suffix="pt" v-bind="field" @change="commit" />
                    <color-field v-model="block.props.color" :label="t('TemplateDesigner.inspector.color')" clearable @change="commit" />
                </div>
                <div class="text-caption text-medium-emphasis">{{ t('TemplateDesigner.inspector.lineHint') }}</div>
            </template>

            <!-- Fläche -->
            <template v-else-if="block.type === 'rect'">
                <div class="tpl-grid-2">
                    <color-field v-model="block.props.fill" :label="t('TemplateDesigner.inspector.fill')" clearable @change="commit" />
                    <color-field v-model="block.props.borderColor" :label="t('TemplateDesigner.inspector.borderColor')" clearable @change="commit" />
                    <v-text-field v-model.number="block.props.borderWidth" type="number" step="0.1" min="0.1" max="5" :label="t('TemplateDesigner.inspector.borderWidth')" suffix="pt" v-bind="field" @change="commit" />
                </div>
            </template>

            <!-- GiroCode -->
            <template v-else-if="block.type === 'qrcode'">
                <v-alert type="info" variant="tonal" density="compact" class="text-body-2">{{ t('TemplateDesigner.inspector.qrHint') }}</v-alert>
            </template>

            <!-- Bedingung -->
            <template v-if="block.type !== 'qrcode'">
                <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.condition') }}</div>
                <v-combobox v-model="block.props.condition" :items="conditionItems" :label="t('TemplateDesigner.inspector.conditionLabel')" :hint="t('TemplateDesigner.inspector.conditionHint')" persistent-hint clearable v-bind="field" @update:model-value="commit" />
            </template>
            <v-btn v-if="block.type === 'text'" size="small" variant="tonal" prepend-icon="mdi-arrow-bottom-left-bold-box-outline" class="mt-2" @click="$emit('to-section')">
                {{ t('TemplateDesigner.inspector.toSection') }}
            </v-btn>
        </template>

        <!-- ───────── Abschnitt ───────── -->
        <template v-else-if="section">
            <div class="tpl-inspector-head">
                <v-icon size="small" class="me-2">mdi-view-sequential-outline</v-icon>{{ t(`TemplateDesigner.sections.${section.type}`) }}
                <v-spacer />
                <v-btn icon="mdi-arrow-up" size="x-small" variant="text" :disabled="sectionIndex === 0" @click="$emit('move-section', -1)" />
                <v-btn icon="mdi-arrow-down" size="x-small" variant="text" :disabled="sectionIndex === design.body.sections.length - 1" @click="$emit('move-section', 1)" />
                <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" @click="$emit('delete')" />
            </div>

            <!-- Betreff / Text -->
            <template v-if="section.type === 'subject' || section.type === 'text'">
                <text-editor v-model="section.text" :field-groups="fieldGroups" :suggestions="suggestions" :label="t('TemplateDesigner.inspector.text')" :hint="t('TemplateDesigner.inspector.markupHint')" @change="commit" />
                <div class="tpl-grid-2">
                    <v-text-field v-model.number="section.fontSize" type="number" step="0.5" min="5" max="40" :label="t('TemplateDesigner.inspector.fontSize')" suffix="pt" :placeholder="String(page.fontSize)" v-bind="field" @change="commit" />
                    <v-text-field v-model.number="section.spaceAfter" type="number" step="0.5" min="0" :label="t('TemplateDesigner.inspector.spaceAfter')" suffix="mm" v-bind="field" @change="commit" />
                    <color-field v-model="section.color" :label="t('TemplateDesigner.inspector.color')" clearable @change="commit" />
                </div>
                <div class="d-flex align-center flex-wrap ga-1 mb-2">
                    <v-btn-toggle v-model="section.align" density="compact" variant="outlined" color="primary" class="tpl-toggle" @update:model-value="commit">
                        <v-btn value="left" size="small" icon="mdi-format-align-left" />
                        <v-btn value="center" size="small" icon="mdi-format-align-center" />
                        <v-btn value="right" size="small" icon="mdi-format-align-right" />
                    </v-btn-toggle>
                    <v-btn size="small" variant="outlined" :color="section.bold ? 'primary' : undefined" icon="mdi-format-bold" @click="toggle(section, 'bold')" />
                    <v-btn size="small" variant="outlined" :color="section.italic ? 'primary' : undefined" icon="mdi-format-italic" @click="toggle(section, 'italic')" />
                </div>
                <v-combobox v-model="section.condition" :items="conditionItems" :label="t('TemplateDesigner.inspector.conditionLabel')" :hint="t('TemplateDesigner.inspector.conditionHint')" persistent-hint clearable v-bind="field" @update:model-value="commit" />
                <v-btn size="small" variant="tonal" prepend-icon="mdi-arrow-top-right-bold-box-outline" class="mt-3" @click="$emit('to-block')">
                    {{ t('TemplateDesigner.inspector.toBlock') }}
                </v-btn>
                <div class="text-caption text-medium-emphasis mt-1">{{ t('TemplateDesigner.inspector.toBlockHint') }}</div>
            </template>

            <!-- Positionstabelle -->
            <template v-else-if="section.type === 'table'">
                <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.columns') }}</div>
                <VueDraggable v-model="section.columns" handle=".tpl-order-grip" :animation="150" @end="commit">
                    <div v-for="col in section.columns" :key="col.key" class="tpl-row-card" :class="{ 'tpl-col-edit--hidden': col.visible === false }">
                        <div class="tpl-row-edit">
                            <v-icon size="small" class="tpl-order-grip">mdi-drag-vertical</v-icon>
                            <v-checkbox-btn v-model="col.visible" :true-value="true" :false-value="false" density="compact" class="tpl-col-check" @update:model-value="commit" />
                            <v-text-field v-model="col.label" :placeholder="t(`TemplateDesigner.fields.${col.key}`)" v-bind="fieldInline" class="tpl-col-label" @change="commit" />
                            <v-text-field v-model.number="col.width" type="number" step="1" :placeholder="t('TemplateDesigner.inspector.auto')" suffix="mm" v-bind="fieldInline" class="tpl-col-width" :disabled="col.key === 'description'" @change="commit" />
                            <v-btn-toggle v-model="col.align" density="compact" variant="text" color="primary" class="tpl-toggle" @update:model-value="commit">
                                <v-btn value="left" size="x-small" icon="mdi-format-align-left" />
                                <v-btn value="right" size="x-small" icon="mdi-format-align-right" />
                            </v-btn-toggle>
                        </div>
                        <div class="tpl-col-key">{{ t(`TemplateDesigner.fields.${col.key}`) }}</div>
                    </div>
                </VueDraggable>
                <div class="text-caption text-medium-emphasis mb-2">{{ t('TemplateDesigner.inspector.columnsHint') }}</div>
                <div class="tpl-grid-2">
                    <v-text-field v-model.number="section.fontSize" type="number" step="0.5" :label="t('TemplateDesigner.inspector.fontSize')" suffix="pt" v-bind="field" @change="commit" />
                    <v-select v-model="section.rules" :items="rulesItems" :label="t('TemplateDesigner.inspector.rules')" v-bind="field" @update:model-value="commit" />
                    <color-field v-model="section.headerFill" :label="t('TemplateDesigner.inspector.headerFill')" clearable @change="commit" />
                    <color-field v-model="section.zebraFill" :label="t('TemplateDesigner.inspector.zebraFill')" :disabled="!section.zebra" @change="commit" />
                </div>
                <v-switch v-model="section.zebra" :label="t('TemplateDesigner.inspector.zebra')" color="primary" density="compact" hide-details @update:model-value="commit" />
                <v-switch v-model="section.headerBold" :label="t('TemplateDesigner.inspector.headerBold')" color="primary" density="compact" hide-details @update:model-value="commit" />
                <v-switch v-model="section.descriptionBold" :label="t('TemplateDesigner.inspector.descriptionBold')" color="primary" density="compact" hide-details @update:model-value="commit" />
                <v-switch v-model="section.longdescription" :label="t('TemplateDesigner.inspector.longdescription')" color="primary" density="compact" hide-details @update:model-value="commit" />
                <v-switch v-model="section.serialnumber" :label="t('TemplateDesigner.inspector.serialnumber')" color="primary" density="compact" hide-details class="mb-2" @update:model-value="commit" />
                <v-text-field v-model="section.continuedText" :label="t('TemplateDesigner.inspector.continuedText')" v-bind="field" @change="commit" />
                <v-text-field v-model.number="section.spaceAfter" type="number" step="0.5" min="0" :label="t('TemplateDesigner.inspector.spaceAfter')" suffix="mm" v-bind="field" @change="commit" />
            </template>

            <!-- Summen -->
            <template v-else-if="section.type === 'totals'">
                <div class="tpl-group-title">{{ t('TemplateDesigner.inspector.rows') }}</div>
                <VueDraggable v-model="section.rows" handle=".tpl-order-grip" :animation="150" @end="commit">
                    <div v-for="(row, i) in section.rows" :key="i" class="tpl-row-card">
                        <div class="tpl-row-edit">
                            <v-icon size="small" class="tpl-order-grip">mdi-drag-vertical</v-icon>
                            <field-input v-model="row.label" :field-groups="fieldGroups" :placeholder="t('TemplateDesigner.inspector.label')" class="tpl-row-label" @change="commit" />
                            <v-btn icon="mdi-format-bold" size="x-small" variant="text" :color="row.bold ? 'primary' : undefined" @click="row.bold = !row.bold; commit()" />
                            <v-btn icon="mdi-border-top-variant" size="x-small" variant="text" :color="row.ruleAbove ? 'primary' : undefined" :title="t('TemplateDesigner.inspector.ruleAbove')" @click="row.ruleAbove = !row.ruleAbove; commit()" />
                            <v-btn icon="mdi-close" size="x-small" variant="text" @click="section.rows.splice(i, 1); commit()" />
                        </div>
                        <field-input v-if="row.type !== 'tax'" v-model="row.value" :field-groups="fieldGroups" :placeholder="t('TemplateDesigner.inspector.value')" class="tpl-row-value" @change="commit" />
                        <v-chip v-else size="x-small" variant="tonal">{{ t('TemplateDesigner.inspector.taxRows') }}</v-chip>
                    </div>
                </VueDraggable>
                <div class="d-flex ga-1 mb-2">
                    <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="section.rows.push({ label: '', value: '' }); commit()">{{ t('TemplateDesigner.inspector.addRow') }}</v-btn>
                    <v-btn v-if="!section.rows.some(r => r.type === 'tax')" size="small" variant="text" prepend-icon="mdi-percent-outline" @click="section.rows.push({ type: 'tax', label: '<%taxdescription%>' }); commit()">{{ t('TemplateDesigner.inspector.addTaxRow') }}</v-btn>
                </div>
                <div class="tpl-grid-2">
                    <v-text-field v-model.number="section.width" type="number" step="1" min="40" :label="t('TemplateDesigner.inspector.width')" suffix="mm" v-bind="field" @change="commit" />
                    <v-text-field v-model.number="section.fontSize" type="number" step="0.5" :label="t('TemplateDesigner.inspector.fontSize')" suffix="pt" :placeholder="String(page.fontSize)" v-bind="field" @change="commit" />
                    <v-text-field v-model.number="section.spaceAfter" type="number" step="0.5" min="0" :label="t('TemplateDesigner.inspector.spaceAfter')" suffix="mm" v-bind="field" @change="commit" />
                </div>
            </template>

            <!-- Unterschrift -->
            <template v-else-if="section.type === 'signature'">
                <v-text-field v-model="section.left" :label="t('TemplateDesigner.inspector.leftLabel')" v-bind="field" @change="commit" />
                <v-text-field v-model="section.right" :label="t('TemplateDesigner.inspector.rightLabel')" v-bind="field" clearable @change="commit" />
                <v-text-field v-model.number="section.width" type="number" step="1" min="30" :label="t('TemplateDesigner.inspector.width')" suffix="mm" v-bind="field" @change="commit" />
            </template>

            <!-- Abstand -->
            <template v-else-if="section.type === 'spacer'">
                <v-text-field v-model.number="section.height" type="number" step="1" min="0" :label="t('TemplateDesigner.inspector.height')" suffix="mm" v-bind="field" @change="commit" />
            </template>
        </template>
    </div>
</template>

<script setup>
import { computed, h, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { VueDraggable } from 'vue-draggable-plus';
import { VTextField, VTextarea, VMenu, VList, VListItem, VListSubheader, VBtn, VChip } from 'vuetify/components';
import { BLOCK_TYPES, CONDITION_FIELDS, renderSample } from '../designer.blocks.js';

const props = defineProps({
    design: { type: Object, required: true },
    selected: { type: String, default: null },   // null = Seite, 'body', Baustein-ID oder Abschnitts-ID
    fieldGroups: { type: Array, default: () => [] },
    suggestions: { type: Array, default: () => [] },
    images: { type: Array, default: () => [] },
    sample: { type: Object, default: null },
});
const emit = defineEmits(['commit', 'delete', 'duplicate', 'move-section', 'reorder', 'select', 'to-block', 'to-section']);

const { t } = useI18n();
const field = { density: 'compact', variant: 'outlined', hideDetails: 'auto', class: 'mb-2' };
const fieldInline = { density: 'compact', variant: 'outlined', hideDetails: true };

const page = computed(() => props.design.page);
const block = computed(() => props.design.blocks.find(b => b.id === props.selected) || null);
const sectionIndex = computed(() => props.design.body.sections.findIndex(s => s.id === props.selected));
const section = computed(() => sectionIndex.value >= 0 ? props.design.body.sections[sectionIndex.value] : null);

const fontItems = computed(() => [
    { title: t('TemplateDesigner.inspector.fontSans'), value: 'sans' },
    { title: t('TemplateDesigner.inspector.fontSerif'), value: 'serif' },
]);
const currencyItems = computed(() => [
    { title: '€', value: 'euro' }, { title: 'EUR', value: 'EUR' }, { title: t('TemplateDesigner.inspector.currencyNone'), value: 'none' },
]);
const foldItems = computed(() => [
    { title: t('TemplateDesigner.inspector.foldNone'), value: '' },
    { title: t('TemplateDesigner.inspector.foldA'), value: 'A' },
    { title: t('TemplateDesigner.inspector.foldB'), value: 'B' },
]);
const backgroundPagesItems = computed(() => [
    { title: t('TemplateDesigner.pages.all'), value: 'all' }, { title: t('TemplateDesigner.pages.first'), value: 'first' },
]);
const rulesItems = computed(() => [
    { title: t('TemplateDesigner.inspector.rulesHeader'), value: 'header' },
    { title: t('TemplateDesigner.inspector.rulesRows'), value: 'rows' },
    { title: t('TemplateDesigner.inspector.rulesNone'), value: 'none' },
]);
const imageItems = computed(() => props.images.map(i => ({ title: i.path, value: i.path })));
const conditionItems = computed(() => {
    const known = new Set(CONDITION_FIELDS);
    for (const g of props.fieldGroups) for (const f of g.fields) known.add(f.key);
    return [...known].sort();
});

function commit() {
    emit('commit');
}

function toggle(obj, key) {
    obj[key] = !obj[key];
    commit();
}

function sectionSummary(s) {
    if (s.type === 'subject' || s.type === 'text') return renderSample(s.text || '', props.sample).split('\n')[0];
    if (s.type === 'table') return (s.columns || []).filter(c => c.visible !== false).map(c => c.label).join(' · ');
    if (s.type === 'totals') return (s.rows || []).map(r => r.label).join(' · ');
    return '';
}

// ───────────────────────── Kleine Hilfskomponenten ─────────────────────────

/** Farbfeld: Hex-Eingabe mit nativem Farbwähler */
const ColorField = {
    props: { modelValue: { type: String, default: '' }, label: String, clearable: Boolean, disabled: Boolean },
    emits: ['update:modelValue', 'change'],
    setup(p, { emit: e }) {
        return () => h(VTextField, {
            modelValue: p.modelValue || '',
            label: p.label,
            density: 'compact', variant: 'outlined', hideDetails: 'auto', class: 'mb-2',
            clearable: p.clearable, disabled: p.disabled,
            placeholder: '#RRGGBB',
            'onUpdate:modelValue': (v) => e('update:modelValue', v || ''),
            onChange: () => e('change'),
            'onClick:clear': () => { e('update:modelValue', ''); e('change'); },
        }, {
            'append-inner': () => h('input', {
                type: 'color',
                value: /^#[0-9a-fA-F]{6}$/.test(p.modelValue || '') ? p.modelValue : '#000000',
                class: 'tpl-color-swatch',
                disabled: p.disabled,
                onInput: (ev) => e('update:modelValue', ev.target.value.toUpperCase()),
                onChange: () => e('change'),
            }),
        });
    },
};

/** Einzeiliges Feld mit Feld-Menü (fügt <%feld%> an der Cursorposition ein) */
const FieldInput = {
    props: { modelValue: { type: String, default: '' }, fieldGroups: { type: Array, default: () => [] }, placeholder: String },
    emits: ['update:modelValue', 'change'],
    setup(p, { emit: e }) {
        const inputRef = ref(null);
        const insert = (key) => {
            const el = inputRef.value?.$el?.querySelector('input');
            const value = p.modelValue || '';
            const start = el?.selectionStart ?? value.length;
            const end = el?.selectionEnd ?? value.length;
            e('update:modelValue', value.slice(0, start) + `<%${key}%>` + value.slice(end));
            e('change');
        };
        return () => h(VTextField, {
            ref: inputRef,
            modelValue: p.modelValue,
            placeholder: p.placeholder,
            density: 'compact', variant: 'outlined', hideDetails: true,
            'onUpdate:modelValue': (v) => e('update:modelValue', v),
            onChange: () => e('change'),
        }, {
            'append-inner': () => h(VMenu, { closeOnContentClick: true, maxHeight: 360 }, {
                activator: ({ props: a }) => h(VBtn, { ...a, icon: 'mdi-code-braces', size: 'x-small', variant: 'text', title: t('TemplateDesigner.inspector.insertField') }),
                default: () => h(VList, { density: 'compact' }, () => p.fieldGroups.flatMap(g => [
                    h(VListSubheader, {}, () => g.title),
                    ...g.fields.map(f => h(VListItem, { title: f.label, subtitle: f.sample, onClick: () => insert(f.key) })),
                ])),
            }),
        });
    },
};

/** Mehrzeiliger Text mit Feld-Menü und Vorschlägen aus der Firmenkonfiguration */
const TextEditor = {
    props: { modelValue: { type: String, default: '' }, fieldGroups: { type: Array, default: () => [] }, suggestions: { type: Array, default: () => [] }, label: String, hint: String },
    emits: ['update:modelValue', 'change'],
    setup(p, { emit: e }) {
        const areaRef = ref(null);
        const local = ref(p.modelValue || '');
        watch(() => p.modelValue, v => { if (v !== local.value) local.value = v || ''; });
        const insert = (text) => {
            const el = areaRef.value?.$el?.querySelector('textarea');
            const value = local.value || '';
            const start = el?.selectionStart ?? value.length;
            const end = el?.selectionEnd ?? value.length;
            local.value = value.slice(0, start) + text + value.slice(end);
            e('update:modelValue', local.value);
            e('change');
            requestAnimationFrame(() => { if (el) { el.focus(); el.selectionStart = el.selectionEnd = start + text.length; } });
        };
        return () => h('div', { class: 'mb-2' }, [
            h(VTextarea, {
                ref: areaRef,
                modelValue: local.value,
                label: p.label,
                hint: p.hint, persistentHint: true,
                rows: 4, autoGrow: true, maxRows: 14,
                density: 'compact', variant: 'outlined', class: 'tpl-textarea',
                'onUpdate:modelValue': (v) => { local.value = v; e('update:modelValue', v); },
                onChange: () => e('change'),
            }, {
                'append-inner': () => h(VMenu, { closeOnContentClick: true, maxHeight: 360 }, {
                    activator: ({ props: a }) => h(VBtn, { ...a, icon: 'mdi-code-braces', size: 'x-small', variant: 'text', title: t('TemplateDesigner.inspector.insertField') }),
                    default: () => h(VList, { density: 'compact' }, () => p.fieldGroups.flatMap(g => [
                        h(VListSubheader, {}, () => g.title),
                        ...g.fields.map(f => h(VListItem, { title: f.label, subtitle: f.sample, onClick: () => insert(`<%${f.key}%>`) })),
                    ])),
                }),
            }),
            p.suggestions.length ? h('div', { class: 'tpl-suggestions' }, [
                h('div', { class: 'tpl-suggestions-title' }, t('TemplateDesigner.inspector.suggestions')),
                h('div', { class: 'd-flex flex-wrap ga-1' }, p.suggestions.map(s => h(VChip, {
                    size: 'x-small', variant: 'tonal', color: 'primary', title: s.value,
                    onClick: () => insert(s.value),
                }, () => s.label))),
            ]) : null,
        ]);
    },
};
</script>

<style scoped>
.tpl-inspector {
    font-size: 13px;
}
.tpl-inspector-head {
    display: flex;
    align-items: center;
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 10px;
}
.tpl-group-title {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgba(var(--v-theme-on-surface), 0.55);
    margin: 10px 0 6px;
}
.tpl-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 8px;
}
.tpl-toggle {
    height: 32px;
}
.tpl-row-card {
    padding: 4px 4px 6px;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 6px;
    margin-bottom: 6px;
}
.tpl-row-edit {
    display: flex;
    align-items: center;
    gap: 2px;
    margin-bottom: 4px;
}
.tpl-col-edit--hidden {
    opacity: 0.5;
}
.tpl-row-label,
.tpl-col-label {
    flex: 1 1 auto;
    min-width: 0;
}
.tpl-row-value {
    margin-left: 22px;
}
.tpl-col-width {
    flex: 0 0 92px;
}
.tpl-col-check {
    flex: 0 0 auto;
}
.tpl-col-key {
    font-size: 10.5px;
    color: rgba(var(--v-theme-on-surface), 0.5);
    padding-left: 26px;
}
.tpl-order-grip {
    cursor: grab;
    color: rgba(var(--v-theme-on-surface), 0.45);
}
.tpl-order-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 6px;
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 4px;
    margin-bottom: 4px;
    cursor: pointer;
    font-size: 12px;
}
.tpl-order-row:hover {
    background: rgba(var(--v-theme-primary), 0.06);
}
.tpl-order-type {
    font-weight: 600;
    white-space: nowrap;
}
.tpl-order-text {
    color: rgba(var(--v-theme-on-surface), 0.6);
    min-width: 0;
}
:deep(.tpl-color-swatch) {
    width: 22px;
    height: 22px;
    padding: 0;
    border: 1px solid rgba(0, 0, 0, 0.2);
    border-radius: 4px;
    background: none;
    cursor: pointer;
}
:deep(.tpl-suggestions) {
    margin-top: 4px;
}
:deep(.tpl-suggestions-title) {
    font-size: 11px;
    color: rgba(var(--v-theme-on-surface), 0.55);
    margin-bottom: 3px;
}
:deep(.tpl-textarea textarea) {
    font-family: ui-monospace, "Roboto Mono", monospace;
    font-size: 12.5px;
    line-height: 1.4;
}
</style>
