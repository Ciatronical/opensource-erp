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
            <v-chip v-if="!isNewMode && saving" size="x-small" color="warning" variant="tonal">
                <v-progress-circular indeterminate size="12" width="2" class="mr-1" />
                {{ t('ArticleEditView.saving') }}
            </v-chip>
            <v-chip v-else-if="!isNewMode && !loading && !error" size="x-small" color="success" variant="tonal">
                <v-icon start size="x-small">mdi-check</v-icon>
                {{ t('ArticleEditView.saved') }}
            </v-chip>
        </div>

        <!-- Alerts -->
        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('ArticleEditView.messages.loading') }}...
        </v-alert>
        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-3">
            {{ error }}
        </v-alert>

        <div
            v-if="!loading && !error"
            @focusin.capture="onFocusIn"
            @focusout.capture="onFocusOut"
        >
            <v-row>
                <v-col cols="12" lg="8">

                    <!-- Stammdaten Card -->
                    <v-card variant="outlined" elevation="1">
                        <v-card-title class="py-2 px-3 bg-grey-lighten-4 d-flex align-center">
                            <v-icon class="mr-2" size="small">mdi-card-text-outline</v-icon>
                            <span class="text-subtitle-1 font-weight-medium">{{ t('ArticleEditView.sections.masterData') }}</span>
                        </v-card-title>
                        <v-divider />
                        <v-card-text class="py-2 px-2 px-sm-3">
                            <v-row dense>
                                <!-- Artikelnummer: beim Bearbeiten fest, beim Anlegen frei.
                                     Leer = nächste freie Nummer aus dem Nummernkreis,
                                     die als Platzhalter angezeigt wird. -->
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
                                        :model-value="article.partnumber"
                                        :label="t('ArticleEditView.fields.partnumber')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        readonly
                                        bg-color="grey-lighten-4"
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

                                <!-- Langbeschreibung -->
                                <v-col cols="12" class="py-1">
                                    <v-textarea
                                        v-model="article.notes"
                                        :label="t('ArticleEditView.fields.notes')"
                                        variant="outlined"
                                        density="compact"
                                        hide-details="auto"
                                        autocomplete="off"
                                        rows="3"
                                        auto-grow
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

                    <!-- Shop-Angaben: Komponente der Shop-Erweiterung, nur geladen,
                         wenn sie aktiv ist -->
                    <PartShopCard
                        v-if="shopEnabled"
                        ref="shopCard"
                        :parts-id="isNewMode ? null : id"
                        :suggested-link="shopLinkSuggestion"
                        :sellprice="article.sellprice"
                        :buchungsgruppen-id="article.buchungsgruppen_id"
                        :description="article.description"
                        :notes="article.notes"
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
                            <v-btn variant="text" :to="{ name: 'article-list' }">
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
import { useRouter } from 'vue-router'
import { oserpStore } from '@/core/stores/oserp.store.js'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import axios from 'axios'

// Die Shop-Karte gehört zur Shop-Erweiterung: erst laden, wenn sie gebraucht wird
const PartShopCard = defineAsyncComponent(() => import('@/features/shop/components/part-shop.card.vue'))

export default defineComponent({
    name: 'ArticleEditView',
    components: { NavbarView, PartShopCard },
    props: {
        // Ohne id: Neuanlage (Route article-new)
        id: { type: [String, Number], default: null }
    },
    setup(props) {
        const { t } = useI18n()
        const oserp = oserpStore()
        const router = useRouter()

        const article = ref({
            partnumber: '',
            description: '',
            part_type: 'service',
            unit: 'Stck',
            sellprice: 0,
            buchungsgruppen_id: null,
            notes: '',
            obsolete: false
        })

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

        async function saveArticle(partsId = props.id) {
            if (saving.value || !partsId) return

            saving.value = true
            error.value = ''

            try {
                await axios.post('/api/parts/', {
                    action: 'updatePart',
                    parts_id: Number(partsId),
                    description: article.value.description,
                    part_type: article.value.part_type,
                    unit: article.value.unit,
                    sellprice: article.value.sellprice,
                    buchungsgruppen_id: article.value.buchungsgruppen_id,
                    notes: article.value.notes,
                    obsolete: article.value.obsolete
                })
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
                        obsolete: data.obsolete === true || data.obsolete === 't'
                    }
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
                description: article.value.description,
                part_type: article.value.part_type,
                unit: article.value.unit,
                sellprice: article.value.sellprice,
                buchungsgruppen_id: article.value.buchungsgruppen_id,
                notes: article.value.notes,
                obsolete: article.value.obsolete
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

        // ── Shop (nur bei aktiver Erweiterung) ──

        const shopEnabled = computed(() => oserp.isExtensionEnabled('shop'))
        const shopCard = ref(null)

        // Vorschlag für die Produktseite: Nummer und Beschreibung als Pfad aus
        // Kleinbuchstaben, Ziffern und Bindestrichen — so wie die Produktseiten
        // der bisherigen Shops heißen
        const shopLinkSuggestion = computed(() => {
            const nummer = String(article.value.partnumber || nextPartnumber.value || '')
            return (nummer + ' ' + (article.value.description || ''))
                .toLowerCase()
                .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
        })

        const requiredRule = v =>
            (v !== null && v !== undefined && String(v).trim() !== '') || t('ArticleEditView.messages.required')

        const canCreate = computed(() =>
            String(article.value.description || '').trim() !== '' &&
            !!article.value.buchungsgruppen_id &&
            !!article.value.unit &&
            ['part', 'service'].includes(article.value.part_type)
        )

        /** Bevorzugte Einheit je Typ — nur wenn es sie in units gibt (FK parts_unit_fkey) */
        function defaultUnit(partType) {
            const wunsch = partType === 'service' ? 'Std' : 'Stck'
            return unitOptions.value.includes(wunsch) ? wunsch : (unitOptions.value[0] || wunsch)
        }

        function initNew() {
            const gruppe = buchungsgruppen.value.find(bg => !bg.obsolete) || buchungsgruppen.value[0]
            article.value = {
                partnumber: '',
                description: '',
                part_type: 'part',
                unit: defaultUnit('part'),
                sellprice: 0,
                buchungsgruppen_id: gruppe ? gruppe.id : null,
                notes: '',
                obsolete: false
            }
            loading.value = false
            error.value = ''
            createError.value = ''
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
            } catch (e) {
                nextPartnumber.value = ''
            }
        }

        // Ware und Dienstleistung haben getrennte Nummernkreise
        watch(() => article.value.part_type, () => {
            if (isNewMode.value) peekPartnumber()
        })

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
                    notes: article.value.notes
                })

                if (response.data?.success) {
                    const partsId = response.data.payload.parts_id
                    // Shop-Angaben erst jetzt: vorher gab es keinen Artikel, an dem sie hängen
                    if (shopCard.value?.saveFor) await shopCard.value.saveFor(partsId)
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
                initNew()
                return
            }
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
            unitOptions,
            buchungsgruppen,
            onFocusIn,
            onFocusOut,
            // Neuanlage
            isNewMode,
            nextPartnumber,
            creating,
            createError,
            canCreate,
            createArticle,
            requiredRule,
            // Shop
            shopEnabled,
            shopCard,
            shopLinkSuggestion
        }
    }
})
</script>
