<!-- src/features/lxcars/views/special-tools/components/special-tool.dialog.vue -->
<!--
    Werkzeug anlegen, bearbeiten und zuordnen — alles in einem Dialog mit drei
    Reitern: Stammdaten, Zuordnung (KI-Einschätzung + Regeln) und die
    passenden Fahrzeuge. Ein neues Werkzeug wird nach dem Speichern sofort
    von der KI bewertet, damit der Benutzer direkt die Fahrzeuge sieht.
-->
<template>
    <v-dialog :model-value="modelValue" max-width="1200" :fullscreen="mobile" scrollable persistent
              @update:model-value="close" @keydown.esc="close(false)">
        <v-card rounded="lg" class="tool-dialog">
            <!-- ── Kopf ──────────────────────────────────────────────────── -->
            <v-card-title class="d-flex align-center pa-4 pb-2">
                <v-icon color="primary" class="mr-2">mdi-tools</v-icon>
                <div class="min-w-0">
                    <div class="text-subtitle-1 font-weight-bold text-truncate">
                        {{ isNew ? t('SpecialToolsView.newTool') : (detail?.tool?.name || form.name) }}
                    </div>
                    <div v-if="!isNew && detail?.tool" class="text-caption text-medium-emphasis">
                        <v-chip size="x-small" :color="statusColor(detail.tool.status)" variant="flat" class="mr-1">
                            {{ t(`SpecialToolsView.status.${detail.tool.status}`) }}
                            <template v-if="detail.tool.status === 'lent' && detail.tool.lent_to">
                                · {{ t('SpecialToolsView.status.lentTo', { name: detail.tool.lent_to }) }}
                            </template>
                        </v-chip>
                        <span v-if="locationText">
                            <v-icon size="x-small">mdi-map-marker-outline</v-icon> {{ locationText }}
                        </span>
                    </div>
                </div>
                <v-spacer />
                <ai-model-button assistant="special_tools" size="x-small" />
                <v-btn icon variant="text" size="small" @click="close(false)"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>

            <v-tabs v-model="tab" density="compact" color="primary" class="px-2">
                <v-tab value="tool"><v-icon start size="small">mdi-card-text-outline</v-icon>{{ t('SpecialToolsView.tabs.tool') }}</v-tab>
                <v-tab value="rules" :disabled="isNew">
                    <v-icon start size="small">mdi-robot-outline</v-icon>{{ t('SpecialToolsView.tabs.rules') }}
                    <v-chip v-if="detail?.rules?.length" size="x-small" variant="tonal" class="ml-2">{{ detail.rules.length }}</v-chip>
                </v-tab>
                <v-tab value="vehicles" :disabled="isNew">
                    <v-icon start size="small">mdi-car-multiple</v-icon>{{ t('SpecialToolsView.tabs.vehicles') }}
                    <v-chip v-if="detail" size="x-small" variant="tonal" class="ml-2">{{ detail.vehicles_total }}</v-chip>
                </v-tab>
            </v-tabs>
            <v-divider />

            <!-- KI arbeitet -->
            <v-alert v-if="analyzing" type="info" variant="tonal" density="compact" rounded="0" icon="mdi-robot-outline">
                <div class="d-flex align-center ga-3">
                    <span class="text-body-2">{{ t('SpecialToolsView.assignment.analyzing') }}</span>
                    <v-progress-linear indeterminate color="primary" height="4" rounded style="max-width: 200px;" />
                </div>
            </v-alert>

            <v-card-text class="pt-4 tool-dialog__body">
                <v-window v-model="tab">
                    <!-- ── Werkzeug ──────────────────────────────────────── -->
                    <v-window-item value="tool">
                        <v-row dense>
                            <v-col cols="12" md="8">
                                <v-text-field
                                    v-model="form.name"
                                    :label="t('SpecialToolsView.form.name')"
                                    :hint="t('SpecialToolsView.form.nameHint')" persistent-hint
                                    :error-messages="nameError"
                                    variant="outlined" density="compact" autofocus class="mb-2"
                                    @keyup.enter="save"
                                />
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-text-field v-model="form.tool_number" :label="t('SpecialToolsView.form.toolNumber')"
                                              variant="outlined" density="compact" class="mb-2" />
                            </v-col>
                            <v-col cols="12" sm="6" md="4">
                                <v-text-field v-model="form.manufacturer" :label="t('SpecialToolsView.form.manufacturer')"
                                              variant="outlined" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="4">
                                <v-combobox v-model="form.category" :items="options.categories || []"
                                            :label="t('SpecialToolsView.form.category')"
                                            variant="outlined" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" md="4">
                                <v-combobox v-model="form.location" :items="options.locations || []"
                                            :label="t('SpecialToolsView.form.location')"
                                            :hint="t('SpecialToolsView.form.locationHint')" persistent-hint
                                            prepend-inner-icon="mdi-map-marker-outline"
                                            variant="outlined" density="compact" />
                            </v-col>
                            <v-col v-if="options.bins?.length" cols="12" sm="6" md="4">
                                <v-select v-model="form.bin_id" :items="options.bins" item-title="label" item-value="id"
                                          :label="t('SpecialToolsView.form.bin')" clearable
                                          variant="outlined" density="compact" />
                            </v-col>
                            <v-col cols="12" sm="6" :md="options.bins?.length ? 4 : 6">
                                <v-btn-toggle v-model="form.status" mandatory divided variant="outlined" density="comfortable" class="w-100">
                                    <v-btn value="available" class="flex-grow-1" color="success">
                                        <v-icon start size="small">mdi-check-circle-outline</v-icon>{{ t('SpecialToolsView.status.available') }}
                                    </v-btn>
                                    <v-btn value="lent" class="flex-grow-1" color="warning">
                                        <v-icon start size="small">mdi-account-arrow-right-outline</v-icon>{{ t('SpecialToolsView.status.lent') }}
                                    </v-btn>
                                    <v-btn value="defective" class="flex-grow-1" color="error">
                                        <v-icon start size="small">mdi-alert-circle-outline</v-icon>{{ t('SpecialToolsView.status.defective') }}
                                    </v-btn>
                                </v-btn-toggle>
                            </v-col>
                            <v-col v-if="form.status === 'lent'" cols="12" sm="6" :md="options.bins?.length ? 4 : 6">
                                <v-text-field v-model="form.lent_to" :label="t('SpecialToolsView.form.lentTo')"
                                              prepend-inner-icon="mdi-account-outline"
                                              variant="outlined" density="compact" autofocus />
                            </v-col>
                            <v-col cols="12">
                                <v-textarea v-model="form.description" :label="t('SpecialToolsView.form.description')"
                                            variant="outlined" density="compact" rows="2" auto-grow />
                            </v-col>
                            <v-col cols="12">
                                <v-textarea v-model="form.ai_hint" :label="t('SpecialToolsView.form.aiHint')"
                                            :placeholder="t('SpecialToolsView.form.aiHintPlaceholder')"
                                            prepend-inner-icon="mdi-robot-outline"
                                            variant="outlined" density="compact" rows="2" auto-grow />
                            </v-col>
                            <v-col v-if="isNew" cols="12">
                                <v-checkbox v-model="analyzeAfterSave" :label="t('SpecialToolsView.form.analyzeAfterSave')"
                                            density="compact" hide-details color="primary" />
                            </v-col>
                        </v-row>

                        <!-- ── Shop: Verleih und Verkauf ─────────────────── -->
                        <v-card v-if="shopEnabled && !isNew" variant="tonal" color="teal" rounded="lg" class="mt-4">
                            <v-card-text>
                                <div class="d-flex align-center ga-2 mb-1">
                                    <v-icon size="small">mdi-storefront-outline</v-icon>
                                    <span class="text-subtitle-2 font-weight-bold">{{ t('SpecialToolsView.shop.title') }}</span>
                                </div>
                                <div class="text-caption text-medium-emphasis mb-3">{{ t('SpecialToolsView.shop.intro') }}</div>
                                <v-row dense>
                                    <v-col cols="12" sm="3">
                                        <v-text-field v-model="shop.purchase_price" :label="t('SpecialToolsView.shop.purchasePrice')"
                                                      type="number" min="0" step="0.01" suffix="€"
                                                      variant="outlined" density="compact" bg-color="surface"
                                                      @update:model-value="suggestPrices" />
                                    </v-col>
                                    <v-col cols="12" sm="3">
                                        <v-text-field v-model="shop.sale_price" :label="t('SpecialToolsView.shop.salePrice')"
                                                      :hint="t('SpecialToolsView.shop.salePriceHint')" persistent-hint
                                                      type="number" min="0" step="0.01" suffix="€"
                                                      variant="outlined" density="compact" bg-color="surface"
                                                      @update:model-value="shopTouched.sale = true" />
                                    </v-col>
                                    <v-col cols="8" sm="3">
                                        <v-text-field v-model="shop.rental_price" :label="t('SpecialToolsView.shop.rentalPrice')"
                                                      :hint="t('SpecialToolsView.shop.rentalPriceHint')" persistent-hint
                                                      type="number" min="0" step="0.01" suffix="€"
                                                      variant="outlined" density="compact" bg-color="surface"
                                                      @update:model-value="shopTouched.rental = true" />
                                    </v-col>
                                    <v-col cols="4" sm="3">
                                        <v-text-field v-model="shop.rental_days" :label="t('SpecialToolsView.shop.rentalDays')"
                                                      type="number" min="1" :suffix="t('SpecialToolsView.shop.days')"
                                                      variant="outlined" density="compact" bg-color="surface" />
                                    </v-col>
                                </v-row>
                                <div class="d-flex align-center flex-wrap ga-4 mt-1">
                                    <v-switch v-model="shop.shop_rent" :label="t('SpecialToolsView.shop.rent')"
                                              color="teal" density="compact" hide-details inset />
                                    <span class="text-caption text-medium-emphasis">
                                        {{ detail?.tool?.rental_partnumber ? t('SpecialToolsView.shop.article', { number: detail.tool.rental_partnumber }) : t('SpecialToolsView.shop.noArticle') }}
                                    </span>
                                    <v-switch v-model="shop.shop_sell" :label="t('SpecialToolsView.shop.sell')"
                                              color="teal" density="compact" hide-details inset />
                                    <span class="text-caption text-medium-emphasis">
                                        {{ detail?.tool?.sale_partnumber ? t('SpecialToolsView.shop.article', { number: detail.tool.sale_partnumber }) : t('SpecialToolsView.shop.noArticle') }}
                                    </span>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-window-item>

                    <!-- ── Zuordnung ─────────────────────────────────────── -->
                    <v-window-item value="rules">
                        <!-- Einschätzung der KI -->
                        <v-card v-if="detail?.tool?.ai_summary" variant="tonal" color="deep-purple" rounded="lg" class="mb-4">
                            <v-card-text>
                                <div class="d-flex align-center ga-2 mb-1">
                                    <v-icon size="small">mdi-robot-outline</v-icon>
                                    <span class="text-caption font-weight-bold text-uppercase">{{ t('SpecialToolsView.assignment.aiSummary') }}</span>
                                    <span class="text-caption text-medium-emphasis">
                                        {{ t('SpecialToolsView.assignment.aiMeta', { model: aiModelLabel(detail.tool.ai_model), date: dt(detail.tool.ai_analyzed_at) }) }}
                                    </span>
                                    <v-spacer />
                                    <v-btn size="x-small" variant="flat" color="deep-purple" :loading="analyzing" @click="analyze">
                                        <v-icon start size="x-small">mdi-refresh</v-icon>{{ t('SpecialToolsView.assignment.reanalyze') }}
                                    </v-btn>
                                </div>
                                <div class="text-body-2">{{ detail.tool.ai_summary }}</div>
                            </v-card-text>
                        </v-card>
                        <v-alert v-else type="info" variant="tonal" density="compact" class="mb-4" icon="mdi-robot-outline">
                            <div class="d-flex align-center flex-wrap ga-3">
                                <span class="text-body-2">{{ t('SpecialToolsView.assignment.notAnalyzed') }}</span>
                                <v-btn size="small" variant="flat" color="primary" :loading="analyzing" @click="analyze">
                                    <v-icon start size="small">mdi-brain</v-icon>{{ t('SpecialToolsView.assignment.analyze') }}
                                </v-btn>
                            </div>
                        </v-alert>

                        <v-alert v-if="warnings.length" type="warning" variant="tonal" density="compact" class="mb-4"
                                 :title="t('SpecialToolsView.assignment.warnings')">
                            <ul class="pl-4 text-body-2">
                                <li v-for="(w, i) in warnings" :key="i">{{ w }}</li>
                            </ul>
                        </v-alert>

                        <div class="d-flex align-center flex-wrap ga-2 mb-2">
                            <span class="text-subtitle-2 font-weight-bold">{{ t('SpecialToolsView.rules.title') }}</span>
                            <span class="text-caption text-medium-emphasis">{{ t('SpecialToolsView.rules.intro') }}</span>
                            <v-spacer />
                            <v-btn size="small" variant="tonal" color="primary" @click="openRule(null)">
                                <v-icon start size="small">mdi-plus</v-icon>{{ t('SpecialToolsView.rules.add') }}
                            </v-btn>
                        </div>

                        <p v-if="!detail?.rules?.length" class="text-body-2 text-medium-emphasis">
                            {{ t('SpecialToolsView.rules.empty') }}
                        </p>

                        <v-card v-for="r in detail?.rules || []" :key="r.id" variant="outlined" rounded="lg" class="mb-2"
                                :class="{ 'rule--inactive': !r.active }">
                            <v-card-text class="py-2">
                                <div class="d-flex align-center flex-wrap ga-2">
                                    <v-switch :model-value="r.active" density="compact" hide-details color="primary" inset
                                              :title="r.active ? t('SpecialToolsView.rules.active') : t('SpecialToolsView.rules.inactive')"
                                              @update:model-value="v => toggleRule(r, v)" />
                                    <span class="font-weight-medium">{{ r.label }}</span>
                                    <v-chip v-if="r.mode === 'exclude'" size="x-small" color="error" variant="flat">
                                        <v-icon start size="x-small">mdi-minus-circle-outline</v-icon>{{ t('SpecialToolsView.rules.modeExclude') }}
                                    </v-chip>
                                    <v-chip size="x-small" variant="tonal" :color="r.source === 'ai' ? 'deep-purple' : 'grey'">
                                        <v-icon start size="x-small">{{ r.source === 'ai' ? 'mdi-robot-outline' : 'mdi-account-edit-outline' }}</v-icon>
                                        {{ r.source === 'ai' ? t('SpecialToolsView.rules.sourceAi') : t('SpecialToolsView.rules.sourceManual') }}
                                    </v-chip>
                                    <v-tooltip v-if="r.confidence !== null && r.confidence !== undefined" location="top"
                                               :text="`${t('SpecialToolsView.rules.confidence')}: ${Math.round(r.confidence * 100)} %`">
                                        <template #activator="{ props: p }">
                                            <v-progress-linear v-bind="p" :model-value="r.confidence * 100" color="deep-purple"
                                                               height="6" rounded style="width: 60px;" />
                                        </template>
                                    </v-tooltip>
                                    <v-spacer />
                                    <v-chip size="small" variant="tonal" :color="r.mode === 'exclude' ? 'error' : (r.matches > 0 ? 'primary' : 'grey')">
                                        <v-icon start size="small">mdi-car-multiple</v-icon>
                                        {{ r.mode === 'exclude' ? '−' : '' }}{{ r.matches > 0 ? t('SpecialToolsView.rules.matches', { n: r.matches }) : t('SpecialToolsView.rules.noMatches') }}
                                    </v-chip>
                                    <v-btn icon variant="text" size="x-small" :title="t('SpecialToolsView.edit')" @click="openRule(r)">
                                        <v-icon size="small">mdi-pencil-outline</v-icon>
                                    </v-btn>
                                    <v-btn icon variant="text" size="x-small" :title="t('SpecialToolsView.delete')" @click="removeRule(r)">
                                        <v-icon size="small">mdi-delete-outline</v-icon>
                                    </v-btn>
                                </div>
                                <div class="mt-2 ml-1">
                                    <criteria-chips :criteria="r.criteria" :color="r.mode === 'exclude' ? 'error' : 'primary'" />
                                    <div v-if="r.reason" class="text-caption text-medium-emphasis mt-1">{{ r.reason }}</div>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-window-item>

                    <!-- ── Fahrzeuge ─────────────────────────────────────── -->
                    <v-window-item value="vehicles">
                        <div class="d-flex align-center flex-wrap ga-2 mb-2">
                            <v-text-field
                                v-model="vehicleSearch"
                                :placeholder="t('SpecialToolsView.vehicles.search')"
                                prepend-inner-icon="mdi-magnify"
                                variant="outlined" density="compact" hide-details clearable
                                style="max-width: 380px;"
                            />
                            <span class="text-caption text-medium-emphasis">
                                {{ t('SpecialToolsView.vehicles.count', { shown: detail?.vehicles?.length || 0, total: detail?.vehicles_total || 0 }) }}
                            </span>
                            <v-spacer />
                            <v-btn size="small" variant="tonal" color="primary" @click="pinOpen = !pinOpen">
                                <v-icon start size="small">mdi-pin-outline</v-icon>{{ t('SpecialToolsView.vehicles.addTitle') }}
                            </v-btn>
                        </div>

                        <!-- Fahrzeug fest zuordnen -->
                        <v-expand-transition>
                            <v-card v-if="pinOpen" variant="tonal" color="primary" rounded="lg" class="mb-3">
                                <v-card-text class="py-2">
                                    <v-autocomplete
                                        v-model="pinSelection"
                                        v-model:search="pinSearch"
                                        :items="pinItems"
                                        :loading="pinLoading"
                                        item-title="label" item-value="c_id" return-object
                                        :label="t('SpecialToolsView.vehicles.addTitle')"
                                        :hint="t('SpecialToolsView.vehicles.addHint')" persistent-hint
                                        variant="outlined" density="compact" no-filter hide-no-data autofocus
                                        @update:model-value="pinVehicle"
                                    >
                                        <template #item="{ props: p, item }">
                                            <v-list-item v-bind="p" :title="item.raw.label" :subtitle="vehicleMeta(item.raw, true)">
                                                <template #append>
                                                    <v-chip v-if="item.raw.matched" size="x-small" variant="tonal" color="success">
                                                        {{ t('SpecialToolsView.vehicles.alreadyMatched') }}
                                                    </v-chip>
                                                </template>
                                            </v-list-item>
                                        </template>
                                    </v-autocomplete>
                                </v-card-text>
                            </v-card>
                        </v-expand-transition>

                        <v-data-table
                            :headers="vehicleHeaders"
                            :items="detail?.vehicles || []"
                            :loading="vehiclesLoading"
                            density="compact"
                            item-value="c_id"
                            :items-per-page="25"
                            hover
                            :no-data-text="t('SpecialToolsView.vehicles.empty')"
                        >
                            <template #item.c_ln="{ item }">
                                <v-btn size="small" variant="text" color="primary" class="px-1 font-weight-bold"
                                       :title="t('SpecialToolsView.vehicles.openCar')" @click="openCar(item.c_id)">
                                    {{ item.c_ln }}
                                </v-btn>
                            </template>
                            <template #item.vehicle="{ item }">
                                <div class="font-weight-medium">{{ item.make }} {{ item.model }}</div>
                                <div class="text-caption text-medium-emphasis">{{ vehicleMeta(item) }}</div>
                            </template>
                            <template #item.engine_code="{ item }">
                                <span class="font-weight-medium">{{ item.engine_code || '—' }}</span>
                            </template>
                            <template #item.why="{ item }">
                                <v-chip v-if="item.pinned" size="x-small" color="primary" variant="flat" class="mr-1">
                                    <v-icon start size="x-small">mdi-pin</v-icon>{{ t('SpecialToolsView.vehicles.pinned') }}
                                </v-chip>
                                <v-chip v-for="l in item.rule_labels || []" :key="l" size="x-small" variant="tonal" class="mr-1">{{ l }}</v-chip>
                            </template>
                            <template #item.actions="{ item }">
                                <v-btn v-if="item.pinned" icon variant="text" size="x-small"
                                       :title="t('SpecialToolsView.vehicles.unpin')" @click="setVehicle(item.c_id, '')">
                                    <v-icon size="small">mdi-pin-off-outline</v-icon>
                                </v-btn>
                                <v-btn v-else icon variant="text" size="x-small" color="error"
                                       :title="t('SpecialToolsView.vehicles.exclude')" @click="setVehicle(item.c_id, 'exclude')">
                                    <v-icon size="small">mdi-close-circle-outline</v-icon>
                                </v-btn>
                            </template>
                        </v-data-table>

                        <!-- Ausgeschlossene Fahrzeuge -->
                        <template v-if="detail?.excluded?.length">
                            <div class="text-subtitle-2 font-weight-bold mt-4 mb-1">
                                <v-icon size="small" color="error" class="mr-1">mdi-close-circle-outline</v-icon>
                                {{ t('SpecialToolsView.vehicles.excludedTitle') }}
                            </div>
                            <v-list density="compact" class="py-0">
                                <v-list-item v-for="e in detail.excluded" :key="e.c_id" class="px-1">
                                    <v-list-item-title class="text-body-2">
                                        <span class="font-weight-medium">{{ e.c_ln }}</span> — {{ e.make }} {{ e.model }}
                                        <span v-if="e.engine_code" class="text-medium-emphasis">· {{ e.engine_code }}</span>
                                        <span v-if="e.note" class="text-caption text-medium-emphasis ml-2">{{ e.note }}</span>
                                    </v-list-item-title>
                                    <template #append>
                                        <v-btn icon variant="text" size="x-small" :title="t('SpecialToolsView.vehicles.restore')"
                                               @click="setVehicle(e.c_id, '')">
                                            <v-icon size="small">mdi-restore</v-icon>
                                        </v-btn>
                                    </template>
                                </v-list-item>
                            </v-list>
                        </template>
                    </v-window-item>
                </v-window>
            </v-card-text>

            <v-divider />
            <v-card-actions class="pa-4">
                <v-btn v-if="!isNew" variant="text" color="error" @click="removeTool">
                    <v-icon start size="small">mdi-delete-outline</v-icon>{{ t('SpecialToolsView.delete') }}
                </v-btn>
                <v-spacer />
                <v-btn variant="text" @click="close(false)">{{ t('SpecialToolsView.close') }}</v-btn>
                <v-btn v-if="tab === 'tool'" color="primary" variant="flat" :loading="saving" :disabled="analyzing" @click="save">
                    <v-icon start size="small">{{ isNew && analyzeAfterSave ? 'mdi-brain' : 'mdi-check' }}</v-icon>
                    {{ isNew && analyzeAfterSave ? t('SpecialToolsView.form.saveAndAnalyze') : t('SpecialToolsView.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>

        <special-tool-rule-dialog
            v-model="ruleOpen"
            :tool-id="toolIdValue"
            :rule="ruleEdit"
            :options="options"
            @saved="reload"
        />
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useSpecialTools } from '@/features/lxcars/composables/useSpecialTools.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import { aiModelStore } from '@/core/stores/ai-model.store.js'
import { aiModelLabel } from '@/core/constants/aiModels.js'
import { formatDateTime } from '@/core/utils/dateFormatter.js'
import * as alerts from '@/core/utils/alerts.js'
import AiModelButton from '@/core/components/ai-model-button.vue'
import CriteriaChips from './criteria-chips.vue'
import SpecialToolRuleDialog from './special-tool-rule.dialog.vue'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** null = neues Werkzeug */
    toolId: { type: Number, default: null },
    options: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue', 'changed'])

const { t, locale } = useI18n()
const router = useRouter()
const { mobile } = useDisplay()
const api = useSpecialTools()
const aiModels = aiModelStore()
const oserp = oserpStore()

const shopEnabled = computed(() => oserp.isExtensionEnabled('shop'))
const shop = reactive(emptyShop())

const tab = ref('tool')
const detail = ref(null)
const savedId = ref(null)
const saving = ref(false)
const analyzing = ref(false)
const vehiclesLoading = ref(false)
const warnings = ref([])
const nameError = ref('')
const analyzeAfterSave = ref(true)
const vehicleSearch = ref('')

const ruleOpen = ref(false)
const ruleEdit = ref(null)

const pinOpen = ref(false)
const pinSearch = ref('')
const pinItems = ref([])
const pinLoading = ref(false)
const pinSelection = ref(null)

const form = reactive(emptyForm())

const toolIdValue = computed(() => props.toolId || savedId.value || 0)
const isNew = computed(() => !toolIdValue.value)
const locationText = computed(() => {
    const tl = detail.value?.tool
    if (!tl) return ''
    return [tl.location, tl.warehouse && tl.bin ? `${tl.warehouse} / ${tl.bin}` : null].filter(Boolean).join(' · ')
})

const vehicleHeaders = computed(() => [
    { title: t('SpecialToolsView.vehicles.plate'),   key: 'c_ln',        width: '130px' },
    { title: t('SpecialToolsView.vehicles.vehicle'), key: 'vehicle',     sortable: false },
    { title: t('SpecialToolsView.vehicles.engine'),  key: 'engine_code', width: '120px' },
    { title: t('SpecialToolsView.vehicles.owner'),   key: 'owner' },
    { title: t('SpecialToolsView.vehicles.why'),     key: 'why',         sortable: false },
    { title: '',                                     key: 'actions',     width: '60px', sortable: false },
])

function emptyForm() {
    return { name: '', tool_number: '', manufacturer: '', category: '', description: '', location: '',
             bin_id: null, status: 'available', lent_to: '', ai_hint: '' }
}
function emptyShop() {
    return { shop_sell: false, shop_rent: false, purchase_price: '', sale_price: '', rental_price: '', rental_days: 7 }
}
function fillShop(tl) {
    Object.assign(shop, emptyShop(), {
        shop_sell: !!tl.shop_sell, shop_rent: !!tl.shop_rent,
        purchase_price: tl.purchase_price ?? '', sale_price: tl.sale_price ?? '',
        rental_price: tl.rental_price ?? '', rental_days: tl.rental_days || 7,
    })
}
/** Einkaufspreis getippt: Verkauf = Einkauf, Miete = ein Drittel — nur solange nichts Eigenes drinsteht */
function suggestPrices() {
    const p = Number(shop.purchase_price)
    if (!Number.isFinite(p) || p <= 0) return
    if (!shopTouched.sale) shop.sale_price = p.toFixed(2)
    if (!shopTouched.rental) shop.rental_price = (Math.round(p / 3 * 100) / 100).toFixed(2)
}
// Vom Benutzer selbst eingetragene Preise werden nicht mehr überschrieben
const shopTouched = reactive({ sale: false, rental: false })

function shopChanged() {
    const tl = detail.value?.tool
    if (!tl) return false
    const num = v => (v === '' || v === null || v === undefined) ? null : Number(v)
    return !!tl.shop_sell !== shop.shop_sell || !!tl.shop_rent !== shop.shop_rent
        || num(tl.purchase_price) !== num(shop.purchase_price) || num(tl.sale_price) !== num(shop.sale_price)
        || num(tl.rental_price) !== num(shop.rental_price) || Number(tl.rental_days || 7) !== Number(shop.rental_days)
}
function fillForm(tl) {
    Object.assign(form, emptyForm(), {
        name: tl.name || '', tool_number: tl.tool_number || '', manufacturer: tl.manufacturer || '',
        category: tl.category || '', description: tl.description || '', location: tl.location || '',
        bin_id: tl.bin_id || null, status: tl.status || 'available', lent_to: tl.lent_to || '', ai_hint: tl.ai_hint || '',
    })
}
function statusColor(s) { return { available: 'success', lent: 'warning', defective: 'error' }[s] || 'grey' }
function dt(v) { return v ? formatDateTime(v, locale.value) : '' }
function vehicleMeta(v, withOwner = false) {
    return [v.fuel, v.ccm ? `${v.ccm} ccm` : null, v.kw ? `${v.kw} kW` : null, v.year, withOwner ? v.owner : null]
        .filter(Boolean).join(' · ')
}

// ── Laden ───────────────────────────────────────────────────────────────────
async function reload(opts = {}) {
    if (!toolIdValue.value) return
    vehiclesLoading.value = true
    try {
        detail.value = await api.fetchTool(toolIdValue.value, vehicleSearch.value || '')
        if (!opts.keepForm) { fillForm(detail.value.tool); fillShop(detail.value.tool) }
    } catch (e) {
        alerts.error(e.message)
    } finally {
        vehiclesLoading.value = false
    }
}

watch(() => props.modelValue, async (open) => {
    if (!open) return
    tab.value = 'tool'
    detail.value = null
    savedId.value = null
    warnings.value = []
    nameError.value = ''
    vehicleSearch.value = ''
    pinOpen.value = false
    pinItems.value = []
    Object.assign(form, emptyForm())
    Object.assign(shop, emptyShop())
    shopTouched.sale = false; shopTouched.rental = false
    if (props.toolId) {
        await reload()
        // Werkzeug ohne Zuordnung: direkt den Zuordnungsreiter zeigen
        if (detail.value && !detail.value.rules.length) tab.value = 'rules'
    }
})

let searchTimer = null
watch(vehicleSearch, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => reload({ keepForm: true }), 300)
})

let pinTimer = null
watch(pinSearch, (term) => {
    if (pinTimer) clearTimeout(pinTimer)
    if (!term || term.length < 2) { pinItems.value = []; return }
    pinTimer = setTimeout(async () => {
        pinLoading.value = true
        try {
            const rows = await api.searchVehicles(toolIdValue.value, term, 20)
            pinItems.value = rows.map(v => ({ ...v, label: `${v.c_ln} — ${v.make || ''} ${v.model || ''}`.trim() }))
        } finally { pinLoading.value = false }
    }, 300)
})

// ── Aktionen ────────────────────────────────────────────────────────────────
async function save() {
    if (!form.name.trim()) { nameError.value = t('SpecialToolsView.form.nameRequired'); tab.value = 'tool'; return }
    nameError.value = ''
    saving.value = true
    try {
        const wasNew = isNew.value
        const res = await api.saveTool({ id: toolIdValue.value, ...form })
        if (wasNew) savedId.value = res.id
        alerts.success(t('SpecialToolsView.form.saved'))
        // Shop-Angebot nur speichern, wenn sich daran etwas geändert hat
        if (!wasNew && shopEnabled.value && shopChanged()) {
            try {
                const r = await api.saveShopOffer({ id: toolIdValue.value, ...shop })
                const jobs = r?.shop_sync?.jobs || 0
                alerts.success(t('SpecialToolsView.shop.saved') + (jobs ? ' · ' + t('SpecialToolsView.shop.jobs', { n: jobs }, jobs) : ''))
            } catch (e) {
                if (e.code === 'SHOP_NOT_ACTIVE') alerts.error(t('SpecialToolsView.shop.notActive'))
                else alerts.error(e.message)
            }
        }
        emit('changed')
        await reload({ keepForm: true })
        if (!wasNew) fillShop(detail.value.tool)
        if (wasNew && analyzeAfterSave.value) {
            tab.value = 'rules'
            await analyze()
        }
    } catch (e) {
        alerts.error(e.message)
    } finally {
        saving.value = false
    }
}

async function analyze() {
    if (!toolIdValue.value || analyzing.value) return
    // Bestehende KI-Regeln werden ersetzt — manuelle bleiben, trotzdem kurz fragen
    if (detail.value?.rules?.some(r => r.source === 'ai')) {
        const ok = await alerts.question(t('SpecialToolsView.assignment.reanalyzeConfirm'))
        if (!ok.isConfirmed) return
    }
    analyzing.value = true
    warnings.value = []
    try {
        const res = await api.analyzeTool(toolIdValue.value, aiModels.requestModel('special_tools'))
        detail.value = res
        warnings.value = res.warnings || []
        fillForm(res.tool)
        tab.value = 'rules'
        alerts.success(t('SpecialToolsView.assignment.analyzeDone', { rules: res.rules.length, vehicles: res.vehicles_total }))
        emit('changed')
    } catch (e) {
        if (e.code === 'MISSING_API_KEYS') alerts.error(t('SpecialToolsView.assignment.missingKey'))
        else alerts.error(e.message)
    } finally {
        analyzing.value = false
    }
}

function openRule(rule) { ruleEdit.value = rule; ruleOpen.value = true }

async function toggleRule(rule, active) {
    try {
        await api.setRuleActive(rule.id, !!active)
        await reload({ keepForm: true })
        emit('changed')
    } catch (e) { alerts.error(e.message) }
}

async function removeRule(rule) {
    const ok = await alerts.question(t('SpecialToolsView.rules.deleteConfirm', { label: rule.label }))
    if (!ok.isConfirmed) return
    try {
        await api.deleteRule(rule.id)
        alerts.success(t('SpecialToolsView.rules.deleted'))
        await reload({ keepForm: true })
        emit('changed')
    } catch (e) { alerts.error(e.message) }
}

async function setVehicle(cId, mode) {
    try {
        await api.setVehicle(toolIdValue.value, cId, mode)
        alerts.success(t('SpecialToolsView.vehicles.updated'))
        await reload({ keepForm: true })
        emit('changed')
    } catch (e) { alerts.error(e.message) }
}

async function pinVehicle(v) {
    if (!v) return
    pinSelection.value = null
    pinSearch.value = ''
    pinItems.value = []
    await setVehicle(v.c_id, 'include')
}

function openCar(cId) {
    close(false)
    router.push({ name: 'car', params: { id: cId } })
}

async function removeTool() {
    const ok = await alerts.question(t('SpecialToolsView.form.deleteConfirm', { name: detail.value?.tool?.name || form.name }))
    if (!ok.isConfirmed) return
    try {
        await api.deleteTool(toolIdValue.value)
        alerts.success(t('SpecialToolsView.form.deleted'))
        emit('changed')
        close(false)
    } catch (e) { alerts.error(e.message) }
}

function close(v) {
    if (analyzing.value) return
    emit('update:modelValue', !!v)
}
</script>

<style scoped>
.tool-dialog__body { min-height: 420px; }
.rule--inactive { opacity: .55; }
.min-w-0 { min-width: 0; }
</style>
