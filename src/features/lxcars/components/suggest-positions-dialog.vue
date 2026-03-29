<!-- src/features/lxcars/components/suggest-positions-dialog.vue -->

<template>
    <v-dialog :model-value="modelValue" max-width="900" persistent @update:model-value="$emit('update:modelValue', $event)">
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon class="mr-2" color="purple">mdi-creation</v-icon>
                {{ t('FakturaView.faktura.aiDialogTitle') }}
                <v-spacer />
                <v-btn icon="mdi-close" size="x-small" variant="text" @click="onSkip" />
            </v-card-title>
            <v-divider />
            <v-card-text class="pa-4">
                <p class="text-body-2 text-medium-emphasis mb-3">
                    {{ t('FakturaView.faktura.aiDialogSubtitle') }}
                </p>

                <v-checkbox
                    v-model="allSelected"
                    :label="t('FakturaView.faktura.aiSelectAll')"
                    density="compact"
                    hide-details
                    class="mb-2"
                    @update:model-value="toggleAll"
                />

                <v-table density="compact" class="suggest-table">
                    <thead>
                        <tr>
                            <th style="width: 50px"></th>
                            <th style="width: 120px">{{ t('FakturaView.faktura.aiPartnumber') }}</th>
                            <th style="min-width: 200px">{{ t('FakturaView.faktura.description') }}</th>
                            <th style="width: 90px; text-align: right">{{ t('FakturaView.faktura.quantity') }}</th>
                            <th style="width: 110px; text-align: right">{{ t('FakturaView.faktura.price') }}</th>
                            <th style="width: 80px">{{ t('FakturaView.faktura.unit') }}</th>
                            <th style="width: 100px; text-align: right">{{ t('FakturaView.faktura.aiTotal') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in localItems" :key="index" :class="{ 'bg-grey-lighten-4': !item.selected }">
                            <td class="text-center">
                                <v-checkbox-btn v-model="item.selected" density="compact" @update:model-value="updateAllSelected" />
                            </td>
                            <td class="text-no-wrap">{{ item.partnumber }}</td>
                            <td>
                                <div>{{ item.description }}</div>
                                <div v-if="item.reason" class="text-caption text-medium-emphasis">
                                    {{ item.reason }}
                                </div>
                            </td>
                            <td class="text-right">
                                <v-text-field
                                    v-model.number="item.qty"
                                    type="number"
                                    variant="plain"
                                    density="compact"
                                    hide-details
                                    :min="0.01"
                                    :step="1"
                                    style="max-width: 70px; margin-left: auto"
                                    class="text-right"
                                />
                            </td>
                            <td class="text-right">
                                <v-text-field
                                    v-model.number="item.sellprice"
                                    type="number"
                                    variant="plain"
                                    density="compact"
                                    hide-details
                                    :min="0"
                                    :step="0.01"
                                    style="max-width: 90px; margin-left: auto"
                                    class="text-right"
                                />
                            </td>
                            <td>{{ item.unit }}</td>
                            <td class="text-right text-no-wrap">
                                {{ formatCurrency(item.qty * item.sellprice) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" class="text-right font-weight-bold">
                                {{ t('FakturaView.faktura.aiTotal') }}:
                            </td>
                            <td class="text-right font-weight-bold text-no-wrap">
                                {{ formatCurrency(selectedTotal) }}
                            </td>
                        </tr>
                    </tfoot>
                </v-table>
            </v-card-text>
            <v-divider />
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="onSkip">
                    {{ t('FakturaView.faktura.aiSkip') }}
                </v-btn>
                <v-btn variant="flat" color="primary" :loading="confirming" :disabled="!hasSelection" @click="onConfirm">
                    {{ t('FakturaView.faktura.aiConfirm') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

export default {
    name: 'SuggestPositionsDialog',
    props: {
        modelValue: { type: Boolean, default: false },
        items: { type: Array, default: () => [] }
    },
    emits: ['update:modelValue', 'confirmed', 'skipped'],
    setup(props, { emit }) {
        const { t } = useI18n()

        const localItems = ref([])
        const allSelected = ref(true)
        const confirming = ref(false)

        // Items mit selected-Flag initialisieren wenn Dialog öffnet
        watch(() => props.modelValue, (visible) => {
            if (visible && props.items.length) {
                localItems.value = props.items.map(item => ({
                    ...item,
                    qty: parseFloat(item.qty) || 1,
                    sellprice: parseFloat(item.sellprice) || 0,
                    selected: true
                }))
                allSelected.value = true
                confirming.value = false
            }
        })

        const hasSelection = computed(() => localItems.value.some(i => i.selected))

        const selectedTotal = computed(() => {
            return localItems.value
                .filter(i => i.selected)
                .reduce((sum, i) => sum + (i.qty * i.sellprice), 0)
        })

        function toggleAll(val) {
            localItems.value.forEach(i => { i.selected = val })
        }

        function updateAllSelected() {
            allSelected.value = localItems.value.every(i => i.selected)
        }

        function formatCurrency(val) {
            return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(val)
        }

        function onSkip() {
            emit('update:modelValue', false)
            emit('skipped')
        }

        async function onConfirm() {
            const selected = localItems.value
                .filter(i => i.selected)
                .map(({ parts_id, description, qty, sellprice, unit }) => ({
                    parts_id, description, qty, sellprice, unit
                }))
            if (!selected.length) return
            confirming.value = true
            emit('confirmed', selected)
        }

        return {
            t, localItems, allSelected, confirming, hasSelection, selectedTotal,
            toggleAll, updateAllSelected, formatCurrency, onSkip, onConfirm
        }
    }
}
</script>

<style scoped>
.suggest-table :deep(th),
.suggest-table :deep(td) {
    padding: 4px 8px !important;
}
.suggest-table :deep(input[type="number"]) {
    text-align: right;
}
</style>
