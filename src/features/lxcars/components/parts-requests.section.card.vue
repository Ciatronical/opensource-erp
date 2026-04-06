<!-- src/features/lxcars/components/parts-requests.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-cart</v-icon>
            {{ t('PartsRequests.title') }}
            <v-chip v-if="pendingCount > 0" size="x-small" color="orange" variant="tonal" class="ml-2">{{ pendingCount }}</v-chip>
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-3">
            <!-- Neues Ersatzteil anfordern -->
            <div class="d-flex ga-2 mb-3 align-center">
                <v-autocomplete
                    v-model:search="partSearch"
                    v-model="selectedPart"
                    :items="partSearchResults"
                    item-title="description"
                    item-value="id"
                    :label="t('PartsRequests.search')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    :no-filter="true"
                    clearable
                    return-object
                    class="flex-grow-1"
                >
                    <template #item="{ item, props: itemProps }">
                        <v-list-item v-bind="itemProps" :subtitle="item.raw.partnumber" />
                    </template>
                </v-autocomplete>
                <v-text-field
                    v-model="freetext"
                    :label="t('PartsRequests.freetext')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    style="max-width: 200px"
                    @keydown.enter="addFromFreetext"
                />
                <v-text-field
                    v-model.number="qty"
                    :label="t('PartsRequests.qty')"
                    type="number"
                    variant="outlined"
                    density="compact"
                    hide-details
                    style="max-width: 80px"
                />
                <v-text-field
                    v-model="note"
                    :label="t('PartsRequests.note')"
                    variant="outlined"
                    density="compact"
                    hide-details
                    style="max-width: 180px"
                />
                <v-btn color="orange" variant="tonal" :disabled="!canAdd" @click="addRequest">
                    <v-icon start size="small">mdi-cart-plus</v-icon>
                    {{ t('PartsRequests.add') }}
                </v-btn>
            </div>

            <!-- Liste -->
            <div v-if="!requests.length" class="text-center text-medium-emphasis py-4">
                {{ t('PartsRequests.empty') }}
            </div>
            <v-table v-else density="compact" class="parts-table">
                <thead>
                    <tr>
                        <th>{{ t('PartsRequests.description') }}</th>
                        <th class="text-right" style="width: 80px">{{ t('PartsRequests.qty') }}</th>
                        <th style="width: 140px">{{ t('PartsRequests.note') }}</th>
                        <th style="width: 120px">{{ t('PartsRequests.status') }}</th>
                        <th style="width: 130px">{{ t('PartsRequests.requestedBy') }}</th>
                        <th style="width: 60px"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="pr in requests" :key="pr.id">
                        <td>
                            <span class="font-weight-medium">{{ pr.description }}</span>
                            <span v-if="pr.partnumber" class="text-caption text-medium-emphasis ml-1">({{ pr.partnumber }})</span>
                        </td>
                        <td class="text-right">{{ pr.qty }} {{ pr.unit }}</td>
                        <td class="text-caption">{{ pr.note }}</td>
                        <td>
                            <v-chip
                                size="x-small"
                                :color="pr.status === 'ordered' ? 'success' : 'orange'"
                                variant="tonal"
                            >
                                {{ pr.status === 'ordered' ? t('PartsRequests.ordered') : t('PartsRequests.pending') }}
                            </v-chip>
                            <div v-if="pr.vendor_name" class="text-caption text-medium-emphasis">{{ pr.vendor_name }}</div>
                        </td>
                        <td class="text-caption">{{ pr.requested_by_name }}</td>
                        <td class="text-no-wrap">
                            <v-btn
                                v-if="pr.status === 'ordered'"
                                icon
                                size="x-small"
                                variant="text"
                                color="warning"
                                :title="t('PartsRequests.revertToPending')"
                                @click="revertRequest(pr)"
                            >
                                <v-icon size="small">mdi-undo</v-icon>
                            </v-btn>
                            <v-btn
                                icon
                                size="x-small"
                                variant="text"
                                color="error"
                                :title="t('PartsRequests.delete')"
                                @click="deleteRequest(pr)"
                            >
                                <v-icon size="small">mdi-delete</v-icon>
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-card-text>
    </v-card>
</template>

<script>
import { defineComponent, ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import axios from 'axios'

export default defineComponent({
    name: 'PartsRequestsSectionCard',
    props: {
        oeId: { type: [Number, String], default: null },
        ensureOeId: { type: Function, default: null }
    },
    setup(props) {
        const { t } = useI18n()
        const carsStore = lxcarsStore()

        const requests = ref([])
        const partSearch = ref('')
        const selectedPart = ref(null)
        const freetext = ref('')
        const qty = ref(1)
        const note = ref('')
        const partSearchResults = ref([])
        let searchTimer = null

        const pendingCount = computed(() => requests.value.filter(r => r.status === 'pending').length)
        const canAdd = computed(() => !!selectedPart.value || !!freetext.value.trim())

        async function loadRequests() {
            if (!props.oeId) return
            try {
                const detail = await carsStore.loadMechanicOrderDetail(props.oeId)
                requests.value = detail.parts_requests || []
            } catch { requests.value = [] }
        }

        watch(partSearch, (val) => {
            if (searchTimer) clearTimeout(searchTimer)
            if (!val || val.length < 2) { partSearchResults.value = []; return }
            searchTimer = setTimeout(async () => {
                try {
                    const resp = await axios.post('/api/parts/', {
                        action: 'searchParts',
                        where: [{ column: 'all', value: val }],
                        limit: 15
                    })
                    partSearchResults.value = resp.data?.results || []
                } catch { partSearchResults.value = [] }
            }, 300)
        })

        async function addRequest() {
            let oeId = props.oeId
            if (!oeId && props.ensureOeId) {
                oeId = await props.ensureOeId()
            }
            if (!oeId) return

            const data = { qty: qty.value || 1, unit: selectedPart.value?.unit || 'Stck', note: note.value }
            if (selectedPart.value) {
                data.parts_id = selectedPart.value.id
                data.partnumber = selectedPart.value.partnumber
                data.description = selectedPart.value.description
            } else if (freetext.value.trim()) {
                data.description = freetext.value.trim()
            } else {
                return
            }

            try {
                await carsStore.addPartsRequest(oeId, data)
                selectedPart.value = null
                freetext.value = ''
                qty.value = 1
                note.value = ''
                partSearch.value = ''
                loadRequests()
            } catch (e) {
                console.error('Error adding parts request:', e)
            }
        }

        function addFromFreetext() {
            if (freetext.value.trim()) addRequest()
        }

        async function revertRequest(pr) {
            try {
                await carsStore.revertPartsRequestToPending(pr.id)
                loadRequests()
            } catch (e) {
                console.error('Error reverting parts request:', e)
            }
        }

        async function deleteRequest(pr) {
            try {
                await carsStore.deletePartsRequest(pr.id)
                loadRequests()
            } catch (e) {
                console.error('Error deleting parts request:', e)
            }
        }

        onMounted(() => { if (props.oeId) loadRequests() })
        watch(() => props.oeId, (id) => { if (id) loadRequests() })

        return {
            t, requests, pendingCount,
            partSearch, selectedPart, freetext, qty, note, partSearchResults, canAdd,
            addRequest, addFromFreetext, revertRequest, deleteRequest, loadRequests
        }
    }
})
</script>

<style scoped>
.parts-table th {
    font-size: 0.8rem !important;
    font-weight: 700 !important;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
</style>
