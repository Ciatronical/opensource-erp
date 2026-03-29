<!-- src/core/views/faktura/dialogs/create.part.dialog.vue -->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="600"
        persistent
    >
        <v-card>
            <v-card-title class="d-flex align-center bg-primary">
                <v-icon class="mr-2">mdi-plus-box</v-icon>
                {{ t('FakturaView.dialogs.createPart.title') }}
                <v-spacer />
                <v-btn
                    icon="mdi-close"
                    variant="text"
                    density="compact"
                    size="small"
                    @click="onCancel"
                />
            </v-card-title>

            <v-card-text class="pt-4">
                <v-row dense>
                    <!-- Beschreibung -->
                    <v-col cols="12">
                        <v-text-field
                            v-model="localItem.description"
                            :label="t('FakturaView.dialogs.createPart.description')"
                            variant="outlined"
                            density="compact"
                            autofocus
                            autocomplete="off"
                            :rules="[v => !!v || t('FakturaView.dialogs.createPart.descriptionRequired')]"
                            @blur="onDescriptionBlur"
                        />
                    </v-col>

                    <!-- Artikeltyp -->
                    <v-col cols="12">
                        <v-radio-group
                            v-model="localItem.part_type"
                            inline
                            hide-details
                            class="mt-0"
                        >
                            <v-radio
                                value="service"
                                color="primary"
                            >
                                <template #label>
                                    <div class="d-flex align-center">
                                        <v-icon class="mr-1" size="small">mdi-account-wrench</v-icon>
                                        {{ t('FakturaView.dialogs.createPart.typeService') }}
                                    </div>
                                </template>
                            </v-radio>
                            <v-radio
                                value="part"
                                color="primary"
                            >
                                <template #label>
                                    <div class="d-flex align-center">
                                        <v-icon class="mr-1" size="small">mdi-package-variant</v-icon>
                                        {{ t('FakturaView.dialogs.createPart.typePart') }}
                                    </div>
                                </template>
                            </v-radio>
                        </v-radio-group>
                        <div v-if="suggestionInfo" class="text-caption text-grey mt-1">
                            <v-icon size="x-small" class="mr-1">mdi-lightbulb-outline</v-icon>
                            {{ suggestionInfo }}
                        </div>
                    </v-col>

                    <!-- Artikelnummer (optional) -->
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="localItem.partnumber"
                            :label="t('FakturaView.dialogs.createPart.partnumber')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            :placeholder="t('FakturaView.dialogs.createPart.partnumberPlaceholder')"
                        />
                    </v-col>

                    <!-- Einheit -->
                    <v-col cols="12" sm="6">
                        <v-combobox
                            v-model="localItem.unit"
                            :items="unitOptions"
                            :label="t('FakturaView.dialogs.createPart.unit')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            @update:model-value="onUnitChange"
                        />
                    </v-col>

                    <!-- Buchungsgruppe -->
                    <v-col cols="12">
                        <v-autocomplete
                            v-model="localItem.buchungsgruppen_id"
                            :items="buchungsgruppen"
                            item-title="description"
                            item-value="id"
                            :label="t('FakturaView.dialogs.createPart.buchungsgruppe')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            :rules="[v => !!v || t('FakturaView.dialogs.createPart.buchungsgruppeRequired')]"
                            @update:model-value="onBuchungsgruppeChange"
                        >
                            <template #item="{ props, item }">
                                <v-list-item
                                    v-bind="props"
                                    :title="item.raw.description"
                                />
                            </template>
                        </v-autocomplete>
                    </v-col>

                    <!-- Verkaufspreis -->
                    <v-col cols="12" sm="6">
                        <v-text-field
                            :model-value="createPriceDisplay"
                            @update:model-value="createPriceRaw = $event"
                            @focus="onCreatePriceFocus($event)"
                            @blur="onCreatePriceBlur"
                            @keydown.enter="onCreatePriceBlur"
                            :label="t('FakturaView.dialogs.createPart.sellprice')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                        />
                        <div v-if="priceInfo" class="text-caption text-grey mt-n2 mb-2">
                            <v-icon size="x-small" class="mr-1">mdi-chart-line</v-icon>
                            {{ t('FakturaView.dialogs.createPart.priceRange', {
                                min: priceInfo.min.toFixed(2),
                                max: priceInfo.max.toFixed(2),
                                count: priceInfo.sample_size
                            }) }}
                        </div>
                    </v-col>

                    <!-- Menge -->
                    <v-col cols="12" sm="6">
                        <v-text-field
                            v-model="localItem.qty"
                            :label="t('FakturaView.dialogs.createPart.qty')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            type="number"
                            step="1"
                            min="1"
                            @focus="$event.target.select()"
                        />
                    </v-col>

                    <!-- Langbeschreibung -->
                    <v-col cols="12">
                        <v-textarea
                            v-model="localItem.longdescription"
                            :label="t('FakturaView.dialogs.createPart.longdescription')"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                            rows="3"
                            auto-grow
                        />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn
                    variant="text"
                    @click="onCancel"
                >
                    {{ t('FakturaView.dialogs.createPart.cancel') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="elevated"
                    :disabled="!isValid"
                    :loading="saving"
                    @click="onSave"
                >
                    {{ t('FakturaView.dialogs.createPart.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
// src/core/views/faktura/dialogs/create.part.dialog.vue

import { defineComponent, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { parseNumber, formatNumber } from '@/core/utils/numberFormat.js'
import axios from 'axios'

export default defineComponent({
    name: 'CreatePartDialog',
    props: {
        /**
         * Dialog sichtbar
         */
        modelValue: {
            type: Boolean,
            default: false
        },
        /**
         * Vorausgefüllter Suchtext
         */
        searchText: {
            type: String,
            default: ''
        },
        /**
         * Index des Items in der Liste
         */
        itemIndex: {
            type: Number,
            default: -1
        }
    },
    emits: ['update:modelValue', 'save', 'cancel'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const oserp = oserpStore()

        // Lokale Kopie des Items
        const localItem = ref(createEmptyItem())

        // Saving-Status
        const saving = ref(false)

        // Suggestion Info
        const suggestionInfo = ref('')

        // Preis-Info (Min/Max/Median)
        const priceInfo = ref(null)

        // Flag ob Einheit manuell geändert wurde
        const unitManuallyChanged = ref(false)

        // Einheiten-Optionen
        const unitOptions = ['Stck', 'Std', 'kg', 'm', 'm²', 'm³', 'l', 'Psch', 'Tag']

        // Buchungsgruppen aus dem Store
        const buchungsgruppen = computed(() => {
            return oserp.session?.company_config?.buchungsgruppen || []
        })

        // Default Buchungsgruppe (erste nicht-obsolete)
        const defaultBuchungsgruppe = computed(() => {
            const bgs = buchungsgruppen.value
            if (bgs.length > 0) {
                const nonObsolete = bgs.find(bg => !bg.obsolete)
                return nonObsolete ? nonObsolete.id : bgs[0].id
            }
            return null
        })

        /**
         * Erstellt ein leeres Item mit intelligenten Defaults
         */
        function createEmptyItem() {
            return {
                description: '',
                partnumber: '',
                part_type: 'service',
                unit: 'Std',
                buchungsgruppen_id: null,
                sellprice: 0,
                qty: 1,
                longdescription: ''
            }
        }

        /**
         * Validierung
         */
        const isValid = computed(() => {
            return localItem.value.description &&
                   localItem.value.description.trim() !== '' &&
                   localItem.value.buchungsgruppen_id &&
                   localItem.value.part_type
        })

        /**
         * Holt Vorschlag für Artikeltyp, Einheit, Preis vom Backend
         */
        async function fetchSuggestion() {
            if (!localItem.value.description || localItem.value.description.length < 2) {
                return
            }

            try {
                const response = await axios.post('/api/parts/', {
                    action: 'suggestPartType',
                    description: localItem.value.description,
                    buchungsgruppen_id: localItem.value.buchungsgruppen_id || 0
                })

                if (response.data.success && response.data.payload) {
                    const suggestion = response.data.payload

                    // Typ übernehmen wenn Confidence > 20%
                    if (suggestion.confidence > 20) {
                        localItem.value.part_type = suggestion.suggested_type
                    }

                    // Einheit übernehmen (nur wenn nicht manuell geändert)
                    if (suggestion.suggested_unit && !unitManuallyChanged.value) {
                        localItem.value.unit = suggestion.suggested_unit
                    }

                    // Preis übernehmen wenn vorhanden und noch nicht gesetzt
                    if (suggestion.suggested_price > 0 && (!localItem.value.sellprice || localItem.value.sellprice === 0)) {
                        localItem.value.sellprice = suggestion.suggested_price
                    }

                    // Info-Text erstellen
                    const typeLabel = suggestion.suggested_type === 'service'
                        ? t('FakturaView.dialogs.createPart.typeService')
                        : t('FakturaView.dialogs.createPart.typePart')

                    if (suggestion.reason === 'similar_articles' && suggestion.similar_count > 0) {
                        suggestionInfo.value = t('FakturaView.dialogs.createPart.suggestionSimilar', {
                            type: typeLabel,
                            count: suggestion.similar_count
                        })
                    } else {
                        suggestionInfo.value = ''
                    }

                    // Zusätzliche Preis-Info wenn vorhanden
                    if (suggestion.price_info && suggestion.price_info.sample_size > 1) {
                        priceInfo.value = suggestion.price_info
                    } else {
                        priceInfo.value = null
                    }
                }
            } catch (e) {
                console.warn('Fehler beim Abrufen des Artikelvorschlags:', e)
            }
        }

        /**
         * Watch für modelValue - initialisiere localItem wenn Dialog geöffnet wird
         */
        watch(() => props.modelValue, async (newValue) => {
            if (newValue) {
                // Reset
                localItem.value = createEmptyItem()
                localItem.value.description = props.searchText || ''
                suggestionInfo.value = ''
                priceInfo.value = null
                unitManuallyChanged.value = false

                // Default Buchungsgruppe setzen
                if (defaultBuchungsgruppe.value) {
                    localItem.value.buchungsgruppen_id = defaultBuchungsgruppe.value
                }

                // Artikelvorschlag holen (inkl. Einheit und Preis)
                if (localItem.value.description.length >= 2) {
                    await fetchSuggestion()
                }
            }
        })

        /**
         * Wenn Beschreibung geändert wird, neuen Vorschlag holen
         */
        async function onDescriptionBlur() {
            await fetchSuggestion()
        }

        /**
         * Wenn Buchungsgruppe geändert wird, Vorschlag aktualisieren
         */
        async function onBuchungsgruppeChange() {
            await fetchSuggestion()
        }

        /**
         * Wenn Einheit manuell geändert wird
         */
        function onUnitChange() {
            unitManuallyChanged.value = true
        }

        /**
         * Speichern
         */
        async function onSave() {
            if (!isValid.value || saving.value) {
                return
            }

            saving.value = true

            emit('save', {
                item: { ...localItem.value },
                index: props.itemIndex
            })

            saving.value = false
            emit('update:modelValue', false)
        }

        /**
         * Abbrechen
         */
        function onCancel() {
            emit('cancel')
            emit('update:modelValue', false)
        }

        // Preis-Feld mit Rechenunterstützung
        const createPriceRaw = ref(null)
        const createPriceEditing = ref(false)
        const currentLocale = computed(() => useI18n().locale.value)

        const createPriceDisplay = computed(() => {
            if (createPriceEditing.value && createPriceRaw.value !== null) return createPriceRaw.value
            return formatNumber(localItem.value?.sellprice || 0, currentLocale.value, 2)
        })

        function onCreatePriceFocus(e) {
            createPriceEditing.value = true
            createPriceRaw.value = formatNumber(localItem.value?.sellprice || 0, currentLocale.value, 2)
            e.target.select()
        }

        function onCreatePriceBlur() {
            if (createPriceRaw.value !== null) {
                const parsed = parseNumber(createPriceRaw.value, currentLocale.value)
                if (parsed !== null && Number.isFinite(parsed)) {
                    localItem.value.sellprice = parsed
                }
            }
            createPriceEditing.value = false
            createPriceRaw.value = null
        }

        return {
            t,
            localItem,
            saving,
            suggestionInfo,
            priceInfo,
            unitOptions,
            buchungsgruppen,
            isValid,
            onSave,
            onCancel,
            onDescriptionBlur,
            onBuchungsgruppeChange,
            onUnitChange,
            createPriceDisplay,
            createPriceRaw,
            onCreatePriceFocus,
            onCreatePriceBlur
        }
    }
})
</script>

<style scoped>
/* Keine zusätzlichen Styles benötigt */
</style>