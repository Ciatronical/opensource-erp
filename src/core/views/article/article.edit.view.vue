<!-- src/core/views/article/article.edit.view.vue -->

<template>
    <NavbarView />
    <v-container class="pt-2 pb-6" fluid>

        <!-- Titel-Zeile -->
        <div class="d-flex align-center mb-3 flex-wrap ga-2">
            <v-icon color="primary" class="mr-1">mdi-package-variant</v-icon>
            <h1 class="text-h6 mb-0">{{ isNewMode ? t('ArticleEditView.titleNew') : t('ArticleEditView.titleEdit') }}</h1>
            <v-chip v-if="article.partnumber" size="small" variant="tonal" color="primary" class="font-weight-bold">
                {{ article.partnumber }}
            </v-chip>
            <v-spacer />
            <!-- Als neu verwenden: öffnet die Neuanlage mit allen Angaben dieses
                 Artikels (kivitendo: „use as new“); angelegt wird erst mit „Anlegen“ -->
            <v-btn
                v-if="!isNewMode && !loading && !error"
                variant="tonal"
                size="small"
                color="primary"
                prepend-icon="mdi-content-copy"
                class="mr-2"
                @click="useAsNew"
            >
                {{ t('ArticleEditView.useAsNew') }}
                <v-tooltip activator="parent" location="bottom" max-width="320">
                    {{ t('ArticleEditView.useAsNewHint') }}
                </v-tooltip>
            </v-btn>
            <v-chip v-if="!isNewMode && saving" size="x-small" color="warning" variant="tonal">
                <v-progress-circular indeterminate size="12" width="2" class="mr-1" />
                {{ t('ArticleEditView.saving') }}
            </v-chip>
            <v-chip v-else-if="!isNewMode && !loading && !error" size="x-small" color="success" variant="tonal">
                <v-icon start size="x-small">mdi-check</v-icon>
                {{ t('ArticleEditView.saved') }}
            </v-chip>
        </div>

        <!-- Sprungmarken zu den Abschnitten — mit der Shop-Erweiterung wird die
             Maske lang (Shop-Angaben, Bilder, technische Daten, Versand, Kanäle) -->
        <div v-if="shopEnabled && !loading && !error" class="d-flex flex-wrap ga-2 mb-3">
            <v-chip
                v-for="abschnitt in abschnitte"
                :key="abschnitt.id"
                size="small"
                variant="tonal"
                :prepend-icon="abschnitt.icon"
                @click="zuAbschnitt(abschnitt.id)"
            >
                {{ abschnitt.titel }}
            </v-chip>
        </div>

        <!-- Alerts -->
        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('ArticleEditView.messages.loading') }}...
        </v-alert>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
            {{ error }}
        </v-alert>
        <!-- Neuanlage aus einer Vorlage: was übernommen wurde und was nicht -->
        <v-alert
            v-if="isNewMode && vorlage && !loading"
            type="info"
            variant="tonal"
            density="compact"
            icon="mdi-content-copy"
            class="mb-3"
        >
            <div class="d-flex align-center flex-wrap ga-2">
                <span>{{ t('ArticleEditView.template.info', { partnumber: vorlage.partnumber, description: vorlage.description }) }}</span>
                <v-btn
                    variant="text"
                    size="small"
                    density="comfortable"
                    prepend-icon="mdi-arrow-left"
                    :to="{ name: 'article-edit', params: { id: vorlage.id } }"
                >
                    {{ t('ArticleEditView.template.back') }}
                </v-btn>
            </div>
        </v-alert>

        <div
            v-if="!loading && !error"
            @focusin.capture="onFocusIn"
            @focusout.capture="onFocusOut"
        >
            <v-row>
                <v-col cols="12" lg="8">

                    <!-- Stammdaten Card -->
                    <v-card id="abschnitt-stammdaten" variant="outlined" elevation="1" class="sprungziel">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-card-text-outline</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('ArticleEditView.sections.masterData') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="py-2 px-2 px-sm-3">
                            <v-row dense>
                                <!-- Artikelnummer: beim Anlegen leer = nächste freie Nummer aus
                                     dem Nummernkreis (Platzhalter). Beim Bearbeiten änderbar;
                                     die Datenbank verlangt Eindeutigkeit, eine vergebene Nummer
                                     meldet das Speichern am Feld. -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-text-field
                                        v-if="isNewMode"
                                        v-model="article.partnumber"
                                        :label="t('ArticleEditView.fields.partnumber')"
                                        :placeholder="nextPartnumber"
                                        persistent-placeholder
                                        :hint="t('ArticleEditView.fields.partnumberHint')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                    <v-text-field
                                        v-else
                                        v-model="article.partnumber"
                                        :label="t('ArticleEditView.fields.partnumber')"
                                        :error-messages="partnumberError"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Artikeltyp -->
                                <v-col cols="12" sm="8" class="py-1 d-flex align-center">
                                    <v-radio-group
                                        v-model="article.part_type"
                                        inline
                                        hide-details
                                        class="mt-0"
                                    >
                                        <v-radio value="service" color="primary">
                                            <template #label>
                                                <div class="d-flex align-center">
                                                    <v-icon class="mr-1" size="small">mdi-account-wrench</v-icon>
                                                    {{ t('ArticleEditView.fields.typeService') }}
                                                </div>
                                            </template>
                                        </v-radio>
                                        <v-radio value="part" color="primary">
                                            <template #label>
                                                <div class="d-flex align-center">
                                                    <v-icon class="mr-1" size="small">mdi-package-variant</v-icon>
                                                    {{ t('ArticleEditView.fields.typePart') }}
                                                </div>
                                            </template>
                                        </v-radio>
                                    </v-radio-group>
                                </v-col>

                                <!-- Beschreibung -->
                                <v-col cols="12" class="py-1">
                                    <v-text-field
                                        ref="beschreibungFeld"
                                        v-model="article.description"
                                        :label="t('ArticleEditView.fields.description')"
                                        :rules="isNewMode ? [requiredRule] : []"
                                        :autofocus="isNewMode"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Einheit -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-combobox
                                        v-model="article.unit"
                                        :items="unitOptions"
                                        :label="t('ArticleEditView.fields.unit')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Verkaufspreis -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-text-field
                                        v-model.number="article.sellprice"
                                        :label="t('ArticleEditView.fields.sellprice')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        prefix="€"
                                        @focus="$event.target.select()"
                                    />
                                </v-col>

                                <!-- Gewicht (parts.weight) — Grundlage der Versandkosten im Shop -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-text-field
                                        persistent-placeholder
                                        v-model="article.weight"
                                        :label="t('ArticleEditView.fields.weight')"
                                        :suffix="weightunit"
                                        :rules="[weightRule]"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                        type="number"
                                        step="0.001"
                                        min="0"
                                    />
                                </v-col>

                                <!-- Buchungsgruppe -->
                                <v-col cols="12" sm="4" class="py-1">
                                    <v-autocomplete
                                        v-model="article.buchungsgruppen_id"
                                        :items="buchungsgruppen"
                                        item-title="description"
                                        item-value="id"
                                        :label="t('ArticleEditView.fields.buchungsgruppe')"
                                        :rules="isNewMode ? [requiredRule] : []"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                    />
                                </v-col>

                                <!-- Langbeschreibung (parts.notes, HTML): visueller Editor wie
                                     im Wiki und in den Belegbemerkungen. Der Text landet
                                     unverändert auf der Shop-Produktseite und als Langtext
                                     der Belegpositionen. -->
                                <v-col cols="12" class="py-1 mt-2">
                                    <HtmlEditorComponent
                                        ref="notesEditor"
                                        v-model="article.notes"
                                        :label="t('ArticleEditView.fields.notes')"
                                        :placeholder="t('ArticleEditView.fields.notesPlaceholder')"
                                        toolbar="full"
                                        format="markdown"
                                        :min-height="180"
                                        :max-height="0"
                                        @focus="onEditorFocus"
                                        @blur="onEditorBlur"
                                    >
                                        <!-- Fertig formatierten Markdown-Text (Herstellertext, KI-Antwort,
                                             Hugo-Seite) in den Editor holen: letzter Eintrag der Werkzeugleiste -->
                                        <template #toolbar-end>
                                            <v-btn
                                                icon="mdi-language-markdown-outline"
                                                size="small"
                                                variant="text"
                                                :title="t('ArticleEditView.fields.importMarkdown')"
                                                @click="markdownDialog = true"
                                            />
                                            <!-- HTML-Quelltext direkt bearbeiten (CodeMirror) -->
                                            <v-btn
                                                icon="mdi-code-tags"
                                                size="small"
                                                variant="text"
                                                :title="t('ArticleEditView.fields.editSource')"
                                                @click="sourceDialog = true"
                                            />
                                        </template>
                                    </HtmlEditorComponent>
                                    <MarkdownImportDialog
                                        v-model="markdownDialog"
                                        format="markdown"
                                        @insert="markdownEinfuegen"
                                        @replace="notesErsetzen"
                                    />
                                    <SourceDialog
                                        v-model="sourceDialog"
                                        :source="article.notes || ''"
                                        language="markdown"
                                        @apply="notesErsetzen"
                                    />
                                </v-col>

                                <!-- Veraltet (erst für einen angelegten Artikel sinnvoll) -->
                                <v-col v-if="!isNewMode" cols="12" class="py-1">
                                    <v-checkbox
                                        v-model="article.obsolete"
                                        :label="t('ArticleEditView.fields.obsolete')"
                                        density="compact"
                                        hide-details
                                        color="error"
                                    />
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <!-- Lager: nur für eine angelegte Ware. Lagerfähig, Meldebestand und
                         Standardplatz sind Stammdaten (parts) und speichern wie die
                         übrigen Felder. Der Bestand selbst ist die Summe der
                         Lagerbewegungen — er wird gebucht, nicht getippt. -->
                    <section-card
                        v-if="lagerSichtbar"
                        id="abschnitt-lager"
                        :title="t('ArticleEditView.sections.warehouse')"
                        icon="mdi-warehouse"
                        class="sprungziel mt-4"
                    >
                        <v-row density="compact">
                            <v-col cols="12" sm="6">
                                <v-checkbox
                                    v-model="article.stockable"
                                    :label="t('ArticleEditView.fields.stockable')"
                                    :hint="t('ArticleEditView.fields.stockableHint')"
                                    persistent-hint
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="article.rop"
                                    :label="t('ArticleEditView.fields.rop')"
                                    :hint="t('ArticleEditView.fields.ropHint')"
                                    persistent-hint
                                    :suffix="article.unit"
                                    :rules="[ropRule]"
                                    type="number"
                                    step="any"
                                    min="0"
                                    autocomplete="off"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-select
                                    v-model="article.warehouse_id"
                                    :items="warehouses"
                                    item-title="description"
                                    item-value="id"
                                    :label="t('ArticleEditView.fields.defaultWarehouse')"
                                    :hint="t('ArticleEditView.fields.defaultLocationHint')"
                                    persistent-hint
                                    clearable
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-select
                                    v-model="article.bin_id"
                                    :items="binsOf(article.warehouse_id)"
                                    item-title="description"
                                    item-value="id"
                                    :label="t('ArticleEditView.fields.defaultBin')"
                                    :disabled="!article.warehouse_id"
                                    clearable
                                />
                            </v-col>
                            <v-col cols="12">
                                <p class="text-body-2 hinweis mb-2">{{ t('ArticleEditView.stock.hint') }}</p>
                                <PartStockPanel
                                    :part="{ id: Number(id), partnumber: article.partnumber, description: article.description, unit: article.unit }"
                                    :warehouses="warehouses"
                                    :transfer-types="transferTypes"
                                    :show-bestbefore="showBestbefore"
                                />
                            </v-col>
                        </v-row>
                    </section-card>

                    <!-- Shop-Angaben: Komponente der Shop-Erweiterung, nur geladen,
                         wenn sie aktiv ist -->
                    <PartShopCard
                        v-if="shopEnabled"
                        ref="shopCard"
                        :parts-id="isNewMode ? null : id"
                        :template-parts-id="vorlage ? vorlage.id : null"
                        :suggested-link="shopLinkSuggestion"
                        :partnumber="shopPartnumber"
                        :sellprice="article.sellprice"
                        :buchungsgruppen-id="article.buchungsgruppen_id"
                        :description="article.description"
                        :notes="notesPlain"
                        :part-type="article.part_type"
                        :obsolete="!!article.obsolete"
                    />

                    <!-- eBay: früher eine eigene Karte hier, jetzt ein Verkaufskanal in
                         der Shop-Karte (dev/shop-verkaufskanaele.md, V15) -->

                    <!-- Anlegen: unter allen Karten, damit auch die Shop-Angaben davor
                         stehen. Erst danach gibt es den Artikel, und die Maske
                         speichert wie gewohnt selbst. -->
                    <div v-if="isNewMode" class="mt-4">
                        <v-alert v-if="createError" type="error" variant="tonal" density="compact" class="mb-2">
                            {{ createError }}
                        </v-alert>
                        <div class="d-flex justify-end ga-2">
                            <v-btn
                                variant="text"
                                :to="vorlage ? { name: 'article-edit', params: { id: vorlage.id } } : { name: 'article-list' }"
                            >
                                {{ t('ArticleEditView.cancel') }}
                            </v-btn>
                            <v-btn
                                color="primary"
                                variant="elevated"
                                prepend-icon="mdi-content-save"
                                :disabled="!canCreate"
                                :loading="creating"
                                @click="createArticle"
                            >
                                {{ t('ArticleEditView.create') }}
                            </v-btn>
                        </div>
                    </div>

                </v-col>
            </v-row>
        </div>

    </v-container>
</template>

<script>
import { defineComponent, defineAsyncComponent, ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { useHourlyRate } from '@/core/composables/useHourlyRate.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import * as toasts from '@/core/utils/toasts.js'
import axios from 'axios'
import { shopSlug } from '@/features/shop/utils/slug.js'
import SectionCard from '@/core/components/layout/section-card.vue'
import HtmlEditorComponent from '@/core/components/html.editor.component.vue'
import { useWarehouse } from '@/features/warehouse/composables/useWarehouse.js'

// Markdown-Übernahme samt marked: erst laden, wenn der Dialog geöffnet wird
const MarkdownImportDialog = defineAsyncComponent(() => import('@/core/components/markdown-import.dialog.vue'))
const SourceDialog = defineAsyncComponent(() => import('@/core/components/source.dialog.vue'))

// Die Shop-Karte gehört zur Shop-Erweiterung: erst laden, wenn sie gebraucht wird
const PartShopCard = defineAsyncComponent(() => import('@/features/shop/components/part-shop.card.vue'))
// Bestand samt Buchungsdialog: nur für Waren, deshalb ebenfalls erst bei Bedarf
const PartStockPanel = defineAsyncComponent(() => import('@/features/warehouse/components/part-stock.panel.vue'))

export default defineComponent({
    name: 'ArticleEditView',
    components: { NavbarView, PartShopCard, PartStockPanel, SectionCard, HtmlEditorComponent, MarkdownImportDialog, SourceDialog },
    props: {
        // Ohne id: Neuanlage (Route article-new)
        id: { type: [String, Number], default: null }
    },
    setup(props) {
        const { t } = useI18n()
        const oserp = oserpStore()
        const router = useRouter()
        const route = useRoute()

        const article = ref({
            partnumber: '',
            description: '',
            part_type: 'service',
            unit: 'Stck',
            sellprice: 0,
            buchungsgruppen_id: null,
            notes: '',
            obsolete: false,
            weight: '',
            ...leereLagerfelder()
        })
        /** Gewichtseinheit des Mandanten (defaults.weightunit), kommt mit getPart */
        const weightunit = ref('')

        /** Lagerstammdaten in parts: lagerfähig, Meldebestand, Standardlager und -platz */
        function leereLagerfelder() {
            return { stockable: false, rop: '', warehouse_id: null, bin_id: null }
        }

        const saving = ref(false)
        const loading = ref(false)
        const error = ref('')
        const initialLoaded = ref(false)

        let textInputFocused = false
        let hasPendingChanges = false
        let saveTimeout = null

        // Einheiten kommen aus der DB (units-Tabelle via company_config), NICHT hardcodiert.
        // Sonst FK-Verletzung parts_unit_fkey, sobald eine angebotene Einheit (z. B. 'l')
        // nicht in units existiert (dort z. B. 'L'). units.name ist der FK-Wert.
        const unitOptions = computed(() =>
            (oserp.session?.company_config?.units || []).map(u => u.name)
        )

        const buchungsgruppen = computed(() => {
            return oserp.session?.company_config?.buchungsgruppen || []
        })

        // ── Focus-Tracking ──

        function isTextInput(el) {
            if (!el) return false
            const tag = el.tagName?.toLowerCase()
            if (tag === 'textarea') return true
            if (tag === 'input') {
                const type = (el.type || 'text').toLowerCase()
                return ['text', 'number', 'password', 'email', 'url', 'search', 'tel', 'date'].includes(type)
            }
            return false
        }

        function onFocusIn(event) {
            if (isTextInput(event.target)) {
                textInputFocused = true
            }
        }

        function onFocusOut(event) {
            if (isTextInput(event.target)) {
                textInputFocused = false
                if (hasPendingChanges) {
                    hasPendingChanges = false
                    triggerSave()
                }
            }
        }

        // Der visuelle Editor ist kein <input>/<textarea>: er meldet Fokus und
        // Verlassen selbst, damit auch dort erst beim Verlassen gespeichert wird
        // statt nach jedem Tastendruck.
        function onEditorFocus() {
            textInputFocused = true
        }

        function onEditorBlur() {
            textInputFocused = false
            if (hasPendingChanges) {
                hasPendingChanges = false
                triggerSave()
            }
        }

        // ── Langbeschreibung (HTML) ──

        // Die Langbeschreibung ist Markdown: der Hugo-Shop setzt sie als
        // Seitenrumpf, Belege übernehmen sie als Langtext. Der Editor arbeitet
        // im Markdown-Modus; Reintext-Altbestand ist gültiges Markdown, ein
        // HTML-Altbestand wird beim Laden erkannt und ab der ersten Änderung
        // als Markdown gespeichert.

        /** Langbeschreibung ohne Randleerraum — gespeichert wird sonst leer */
        function notesValue() {
            return String(article.value.notes || '').trim()
        }

        // Markdown übernehmen und Quelltext bearbeiten: beide Dialoge liefern
        // Markdown. Einfügen geht über den Editor (Cursorposition, Rückgängig
        // möglich), Ersetzen über das Modell; beides löst das gewohnte
        // Speichern aus.
        const notesEditor = ref(null)
        const markdownDialog = ref(false)
        const sourceDialog = ref(false)

        function markdownEinfuegen(markdown) {
            if (notesEditor.value?.insertText) {
                notesEditor.value.insertText(markdown)
            } else {
                article.value.notes = [notesValue(), markdown].filter(Boolean).join('\n\n')
            }
        }

        function notesErsetzen(markdown) {
            article.value.notes = markdown
        }

        /** Nur der Text, als Platzhalter für die Kanalbeschreibung der Shop-Karte */
        const notesPlain = computed(() => notesValue()
            .replace(/```[\s\S]*?```/g, '')                    // Codeblöcke
            .replace(/!\[[^\]]*\]\([^)]*\)/g, '')               // Bilder
            .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')            // Links: nur der Text
            .replace(/^\s{0,3}#{1,6}\s+/gm, '')                 // Überschriften
            .replace(/^\s{0,3}>\s?/gm, '')                      // Zitate
            .replace(/^\s*[-*+]\s+\[[ xX]\]\s+/gm, '- ')         // Aufgabenlisten
            .replace(/^\s*(?:[-*_]\s*){3,}$/gm, '')             // Trennlinien
            .replace(/^\|?[\s:|-]+\|[\s:|-]*$/gm, '')           // Tabellen: Trennzeile
            .replace(/(\*\*|__|~~|`)(.*?)\1/g, '$2')             // fett, durchgestrichen, Code
            .replace(/(^|[^*\w])[*_](.+?)[*_]/g, '$1$2')         // kursiv
            .replace(/\n{3,}/g, '\n\n')
            .trim())

        // ── Save-Logik ──

        function onDataChange() {
            if (!initialLoaded.value) return
            if (textInputFocused) {
                hasPendingChanges = true
                return
            }
            triggerSave()
        }

        function triggerSave() {
            if (saveTimeout) clearTimeout(saveTimeout)
            saveTimeout = setTimeout(() => {
                saveArticle()
            }, 500)
        }

        watch(article, onDataChange, { deep: true })

        /** Meldung am Feld der Artikelnummer, wenn sie vergeben ist */
        const partnumberError = ref('')

        /** Die Artikelnummer geht nur mit, wenn sie nicht leer ist — leer hieße „unverändert“ */
        function partnumberPayload() {
            const nummer = String(article.value.partnumber || '').trim()
            return nummer ? { partnumber: nummer } : {}
        }

        async function saveArticle(partsId = props.id) {
            if (saving.value || !partsId) return

            saving.value = true
            error.value = ''

            try {
                const response = await axios.post('/api/parts/', {
                    action: 'updatePart',
                    parts_id: Number(partsId),
                    ...partnumberPayload(),
                    description: article.value.description,
                    part_type: article.value.part_type,
                    unit: article.value.unit,
                    sellprice: article.value.sellprice,
                    buchungsgruppen_id: article.value.buchungsgruppen_id,
                    notes: notesValue(),
                    obsolete: article.value.obsolete,
                    weight: weightValue(),
                    ...lagerPayload()
                })
                // Eine vergebene Artikelnummer meldet das Backend; die übrigen
                // Felder sind dann ebenfalls nicht gespeichert, bis sie stimmt
                const code = response.data?.success ? '' : (response.data?.text || '')
                partnumberError.value = code === 'PARTNUMBER_EXISTS' ? t('ArticleEditView.messages.partnumberExists') : ''
                if (code && code !== 'PARTNUMBER_EXISTS') error.value = t('ArticleEditView.messages.loadError')
            } catch (e) {
                console.error('Save article error:', e)
                error.value = t('ArticleEditView.messages.loadError')
            } finally {
                saving.value = false
            }
        }

        // ── Load ──

        async function fetchArticle(articleId) {
            loading.value = true
            error.value = ''

            try {
                const response = await axios.post('/api/parts/', {
                    action: 'getPart',
                    parts_id: Number(articleId)
                })

                if (response.data.success) {
                    const data = response.data.payload
                    article.value = {
                        partnumber: data.partnumber || '',
                        description: data.description || '',
                        part_type: data.part_type || 'service',
                        unit: data.unit || 'Stck',
                        sellprice: parseFloat(data.sellprice) || 0,
                        buchungsgruppen_id: data.buchungsgruppen_id ? parseInt(data.buchungsgruppen_id) : null,
                        notes: data.notes || '',
                        obsolete: data.obsolete === true || data.obsolete === 't',
                        weight: data.weight === null || data.weight === undefined ? '' : String(Number(data.weight)),
                        stockable: data.stockable === true || data.stockable === 't',
                        rop: Number(data.rop) > 0 ? String(Number(data.rop)) : '',
                        warehouse_id: data.warehouse_id ? parseInt(data.warehouse_id) : null,
                        bin_id: data.bin_id ? parseInt(data.bin_id) : null
                    }
                    weightunit.value = data.weightunit || ''
                } else {
                    error.value = t('ArticleEditView.messages.notFound')
                }
            } catch (e) {
                console.error('Load article error:', e)
                error.value = t('ArticleEditView.messages.loadError')
            } finally {
                loading.value = false
            }
        }

        // ── sendBeacon bei Seitennavigation ──

        function flushPendingChanges() {
            if (!initialLoaded.value) return
            if (!hasPendingChanges && !saveTimeout) return

            if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null }
            hasPendingChanges = false

            const payload = {
                action: 'updatePart',
                parts_id: Number(props.id),
                ...partnumberPayload(),
                description: article.value.description,
                part_type: article.value.part_type,
                unit: article.value.unit,
                sellprice: article.value.sellprice,
                buchungsgruppen_id: article.value.buchungsgruppen_id,
                notes: notesValue(),
                obsolete: article.value.obsolete,
                weight: weightValue(),
                ...lagerPayload()
            }
            navigator.sendBeacon('/api/parts/', new Blob([JSON.stringify(payload)], { type: 'application/json' }))
        }

        // ── Neuanlage ──
        //
        // Ohne id legt die Maske einen Artikel an. Bis dahin speichert sie nichts
        // selbst (initialLoaded bleibt false); nach dem Anlegen wechselt die Route
        // auf article-edit, und ab da gilt das gewohnte automatische Speichern.

        const isNewMode = computed(() => !props.id)
        const creating = ref(false)
        const createError = ref('')
        const nextPartnumber = ref('')

        // ── Als neu verwenden ──
        //
        // Die Neuanlage kann aus einem vorhandenen Artikel vorbelegt werden
        // (?vorlage=<id>, damit die Adresse teilbar bleibt). Die Maske zeigt
        // dessen Angaben ohne Nummer; sichtbare Felder gehen über das Formular,
        // alles Übrige (Warengruppe, Einkaufspreis, Übersetzungen, Shop-Bilder …)
        // übernimmt das Backend anhand der Vorlage — erst beim Anlegen.

        /** Vorlage der Neuanlage: { id, partnumber, description }, sonst null */
        const vorlage = ref(null)
        const vorlageId = computed(() => isNewMode.value ? (Number(route.query.vorlage) || 0) : 0)
        /** Während die Vorlage in die Felder kommt, dürfen die Typ-Watcher nichts umstellen */
        let vorlageWirdGeladen = false
        /** Zuletzt vorbelegte Vorlage (0 = leere Maske, -1 = keine Neuanlage) — Routen- und
         *  Vorlagen-Watcher reagieren beide auf den Wechsel in die Neuanlage; wer zuerst
         *  kommt, belegt vor, der andere sieht es daran und lässt es */
        let vorlageAngewendet = -1
        const beschreibungFeld = ref(null)

        function useAsNew() {
            router.push({ name: 'article-new', query: { vorlage: String(props.id) } })
        }

        /** Beschreibung markieren: das Erste, was bei einer Kopie meist anders heißt */
        function beschreibungMarkieren() {
            const input = beschreibungFeld.value?.$el?.querySelector('input')
            if (!input) return
            input.focus()
            input.select()
        }

        // ── Shop (nur bei aktiver Erweiterung) ──

        const shopEnabled = computed(() => oserp.isExtensionEnabled('shop'))

        /** Sprungmarken: Stammdaten, dazu die Abschnitte der Shop-Karte */
        const abschnitte = computed(() => [
            { id: 'abschnitt-stammdaten', titel: t('ArticleEditView.sections.masterData'), icon: 'mdi-card-text-outline' },
            ...(lagerSichtbar.value ? [{ id: 'abschnitt-lager', titel: t('ArticleEditView.sections.warehouse'), icon: 'mdi-warehouse' }] : []),
            ...(shopCard.value?.abschnitte || []),
        ])

        /** Springt zu einem Abschnitt; den Abstand zur Kopfleiste hält .sprungziel */
        function zuAbschnitt(id) {
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
        }
        const shopCard = ref(null)

        // Vorschlag für die Produktseite: Nummer und Beschreibung als Pfad aus
        // Kleinbuchstaben, Ziffern und Bindestrichen — so wie die Produktseiten
        // der bisherigen Shops heißen
        // Vorschlag für die Produktseite: nur die Beschreibung, ohne Artikelnummer.
        // Die Karte trägt ihn bei der Neuanlage ein und hängt die Nummer nur an,
        // wenn ein anderer Artikel die Seite schon belegt
        const shopLinkSuggestion = computed(() => shopSlug(article.value.description))
        const shopPartnumber = computed(() => String(article.value.partnumber || nextPartnumber.value || ''))

        const requiredRule = v =>
            (v !== null && v !== undefined && String(v).trim() !== '') || t('ArticleEditView.messages.required')

        /** Gewicht: leer = nicht gepflegt, sonst eine Zahl ab 0 */
        const weightRule = v =>
            v === null || v === undefined || String(v).trim() === '' || Number(v) >= 0 || t('ArticleEditView.messages.weightInvalid')
        function weightValue() {
            const wert = article.value.weight
            if (wert === null || wert === undefined || String(wert).trim() === '') return null
            const zahl = Number(wert)
            // Ungültiges nicht senden — das Backend lehnte die ganze Speicherung ab
            return Number.isFinite(zahl) && zahl >= 0 ? zahl : undefined
        }

        // ── Lager ──
        //
        // Die Karte gibt es nur für eine angelegte Ware: Dienstleistungen haben
        // keinen Bestand, und vor dem Anlegen gibt es nichts, woran er hängen
        // könnte. Lager und Plätze kommen einmal aus getWarehouseOptions und
        // versorgen die Auswahlfelder wie den Buchungsdialog.

        const { fetchOptions: fetchWarehouseOptions } = useWarehouse()
        const warehouseOptions = ref({ warehouses: [], transfer_types: [], defaults: {} })
        const warehouses = computed(() => warehouseOptions.value.warehouses || [])
        const transferTypes = computed(() => warehouseOptions.value.transfer_types || [])
        const showBestbefore = computed(() => !!warehouseOptions.value.defaults?.show_bestbefore)
        let lagerOptionenGeladen = false

        const lagerSichtbar = computed(() => !isNewMode.value && !loading.value && !error.value && article.value.part_type === 'part')

        function binsOf(warehouseId) {
            return warehouses.value.find(w => w.id === warehouseId)?.bins || []
        }

        watch(lagerSichtbar, async sichtbar => {
            if (!sichtbar || lagerOptionenGeladen) return
            lagerOptionenGeladen = true
            try {
                warehouseOptions.value = await fetchWarehouseOptions()
            } catch (e) {
                console.error('Load warehouse options error:', e)
                lagerOptionenGeladen = false
            }
        }, { immediate: true })

        // Ein Platz gehört zu seinem Lager: beim Lagerwechsel den ersten Platz des
        // neuen Lagers vorbelegen; ohne Lager keinen Platz. Solange die Lagerliste
        // fehlt, bleibt der geladene Wert unangetastet.
        watch(() => article.value.warehouse_id, lagerId => {
            if (!initialLoaded.value) return
            if (!lagerId) { article.value.bin_id = null; return }
            const plaetze = binsOf(lagerId)
            if (!warehouses.value.some(w => w.id === lagerId)) return
            if (!plaetze.some(b => b.id === article.value.bin_id)) {
                article.value.bin_id = plaetze[0]?.id ?? null
            }
        })

        /** Meldebestand: leer = keine Überwachung, sonst eine Zahl ab 0 */
        const ropRule = v =>
            v === null || v === undefined || String(v).trim() === '' || Number(v) >= 0 || t('ArticleEditView.messages.ropInvalid')

        /** Lagerfelder für updatePart; ungültiger Meldebestand wird nicht gesendet */
        function lagerPayload() {
            const rohwert = article.value.rop
            const leer = rohwert === null || rohwert === undefined || String(rohwert).trim() === ''
            const zahl = Number(rohwert)
            const rop = leer ? 0 : (Number.isFinite(zahl) && zahl >= 0 ? zahl : undefined)
            return {
                stockable: !!article.value.stockable,
                ...(rop === undefined ? {} : { rop }),
                warehouse_id: article.value.warehouse_id || null,
                bin_id: article.value.bin_id || null
            }
        }

        const canCreate = computed(() =>
            String(article.value.description || '').trim() !== '' &&
            !!article.value.buchungsgruppen_id &&
            !!article.value.unit &&
            ['part', 'service'].includes(article.value.part_type) &&
            // Shop-Karte: bei mehreren Verkaufskanälen muss der Benutzer einen wählen
            !shopCard.value?.kanalFehlt
        )

        /** Standard-Stundensatz und seine Bezugseinheit aus der Mandantenkonfiguration */
        const { hourlyRate: defaultHourlyRate, hourlyRateUnit } = useHourlyRate()

        /** Bevorzugte Einheit je Typ — nur wenn es sie in units gibt (FK parts_unit_fkey) */
        function defaultUnit(partType) {
            const wunsch = partType === 'service' ? hourlyRateUnit.value : 'Stck'
            return unitOptions.value.includes(wunsch) ? wunsch : (unitOptions.value[0] || wunsch)
        }

        async function initNew() {
            vorlageAngewendet = vorlageId.value
            vorlage.value = null
            createError.value = ''
            partnumberError.value = ''

            if (vorlageId.value) {
                vorlageWirdGeladen = true
                await fetchArticle(vorlageId.value)
                if (!error.value) {
                    vorlage.value = {
                        id: vorlageId.value,
                        partnumber: article.value.partnumber,
                        description: article.value.description
                    }
                    // Die Nummer wird neu vergeben, „Veraltet“ gilt nicht für eine Kopie
                    article.value.partnumber = ''
                    article.value.obsolete = false
                    await nextTick()
                    vorlageWirdGeladen = false
                    peekPartnumber()
                    beschreibungMarkieren()
                    return
                }
                // Vorlage weg: leere Maske, der Hinweis sagt warum
                error.value = ''
                vorlageWirdGeladen = false
                toasts.warning(t('ArticleEditView.template.notFound'))
            }

            const gruppe = buchungsgruppen.value.find(bg => !bg.obsolete) || buchungsgruppen.value[0]
            article.value = {
                partnumber: '',
                description: '',
                part_type: 'part',
                unit: defaultUnit('part'),
                sellprice: 0,
                buchungsgruppen_id: gruppe ? gruppe.id : null,
                notes: '',
                obsolete: false,
                weight: '',
                ...leereLagerfelder()
            }
            loading.value = false
            error.value = ''
            peekPartnumber()
        }

        /** Nächste freie Nummer als Platzhalter — verbraucht wird sie erst beim Anlegen */
        async function peekPartnumber() {
            try {
                const response = await axios.post('/api/parts/', {
                    action: 'peekNextPartnumber',
                    part_type: article.value.part_type
                })
                nextPartnumber.value = response.data?.success ? (response.data.payload?.partnumber || '') : ''
                if (response.data?.payload?.weightunit) weightunit.value = response.data.payload.weightunit
            } catch (e) {
                nextPartnumber.value = ''
            }
        }

        /** Neue Dienstleistung in der Stundensatz-Einheit ohne Preis → Standard-Stundensatz vorbelegen */
        function prefillHourlyRate() {
            if (!isNewMode.value || vorlageWirdGeladen) return
            if (article.value.part_type !== 'service' || article.value.unit !== hourlyRateUnit.value) return
            if (Number(article.value.sellprice) > 0 || !defaultHourlyRate.value) return
            article.value.sellprice = defaultHourlyRate.value
        }

        // Ware und Dienstleistung haben getrennte Nummernkreise und Standard-Einheiten
        watch(() => article.value.part_type, (neu, alt) => {
            if (!isNewMode.value || vorlageWirdGeladen) return
            if (alt !== undefined && article.value.unit === defaultUnit(alt)) {
                article.value.unit = defaultUnit(neu)
            }
            prefillHourlyRate()
            peekPartnumber()
        })

        watch(() => article.value.unit, prefillHourlyRate)

        function createErrorText(code) {
            if (code === 'PARTNUMBER_EXISTS') return t('ArticleEditView.messages.partnumberExists')
            if (code === 'NO_PERMISSION') return t('ArticleEditView.messages.noPermission')
            return t('ArticleEditView.messages.createError')
        }

        async function createArticle() {
            if (!canCreate.value || creating.value) return

            creating.value = true
            createError.value = ''

            try {
                const response = await axios.post('/api/parts/', {
                    action: 'createPart',
                    partnumber: String(article.value.partnumber || '').trim(),
                    description: String(article.value.description).trim(),
                    part_type: article.value.part_type,
                    unit: article.value.unit,
                    sellprice: article.value.sellprice,
                    buchungsgruppen_id: article.value.buchungsgruppen_id,
                    notes: notesValue(),
                    weight: weightValue(),
                    ...(vorlage.value ? { template_parts_id: vorlage.value.id } : {})
                })

                if (response.data?.success) {
                    const partsId = response.data.payload.parts_id
                    // Shop-Angaben erst jetzt: vorher gab es keinen Artikel, an dem sie hängen
                    if (shopCard.value?.saveFor) await shopCard.value.saveFor(partsId, vorlage.value?.id || null)
                    router.replace({ name: 'article-edit', params: { id: partsId } })
                } else {
                    createError.value = createErrorText(response.data?.text)
                }
            } catch (e) {
                console.error('Create article error:', e)
                createError.value = createErrorText()
            } finally {
                creating.value = false
            }
        }

        // ── Lifecycle ──

        onMounted(async () => {
            window.addEventListener('beforeunload', flushPendingChanges)
            if (isNewMode.value) {
                initNew()
                return
            }
            await fetchArticle(props.id)
            await nextTick()
            initialLoaded.value = true
        })

        onBeforeUnmount(() => {
            window.removeEventListener('beforeunload', flushPendingChanges)
            if (saveTimeout) clearTimeout(saveTimeout)
            flushPendingChanges()
        })

        // Andere Vorlage bei gleicher Route (article-new bleibt): neu vorbelegen
        watch(vorlageId, neu => {
            if (isNewMode.value && neu !== vorlageAngewendet) initNew()
        })

        // Die Route wechselt zwischen article-new und article-edit, ohne dass die
        // Ansicht neu entsteht — deshalb hier beide Richtungen.
        watch(() => props.id, async (newId, oldId) => {
            // Ausstehende Änderungen gehören noch zum bisherigen Artikel
            if (oldId && (saveTimeout || hasPendingChanges)) {
                if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null }
                hasPendingChanges = false
                saveArticle(oldId)
            }
            initialLoaded.value = false
            if (!newId) {
                if (vorlageId.value !== vorlageAngewendet) initNew()
                return
            }
            vorlageAngewendet = -1
            vorlage.value = null
            await fetchArticle(newId)
            await nextTick()
            initialLoaded.value = true
        })

        return {
            t,
            article,
            saving,
            loading,
            error,
            partnumberError,
            unitOptions,
            buchungsgruppen,
            onFocusIn,
            onFocusOut,
            onEditorFocus,
            onEditorBlur,
            notesPlain,
            notesEditor,
            markdownDialog,
            sourceDialog,
            markdownEinfuegen,
            notesErsetzen,
            // Neuanlage
            isNewMode,
            nextPartnumber,
            creating,
            createError,
            canCreate,
            createArticle,
            requiredRule,
            weightRule,
            weightunit,
            // Lager
            lagerSichtbar,
            warehouses,
            transferTypes,
            showBestbefore,
            binsOf,
            ropRule,
            // Als neu verwenden
            vorlage,
            useAsNew,
            beschreibungFeld,
            // Shop
            shopEnabled,
            abschnitte,
            zuAbschnitt,
            shopCard,
            shopLinkSuggestion,
            shopPartnumber
        }
    }
})
</script>

<style scoped>
/* Sprungmarken: Abstand zur festen Kopfleiste */
.sprungziel {
    scroll-margin-top: 72px;
}

.hinweis {
    color: var(--oserp-text-muted);
}
</style>
