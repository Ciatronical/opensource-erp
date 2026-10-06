<!-- src/features/lxcars/components/special-tools.section.card.vue -->
<!--
    Karte "Spezialwerkzeug" in der Fahrzeugansicht und im Werkstattauftrag:
    Welche Werkzeuge passen zu diesem Fahrzeug und wo liegen sie? Klick auf
    ein Werkzeug öffnet es in der Spezialwerkzeug-Übersicht.
-->
<template>
    <v-card v-if="cId" variant="outlined" elevation="1">
        <v-card-title class="py-2 px-3 bg-amber-lighten-5 d-flex align-center">
            <v-icon class="mr-2" size="small" color="amber-darken-3">mdi-tools</v-icon>
            <span class="text-subtitle-1 font-weight-medium text-amber-darken-4">{{ t('SpecialToolsView.card.title') }}</span>
            <v-chip v-if="tools.length" size="x-small" variant="tonal" color="amber-darken-3" class="ml-2">{{ tools.length }}</v-chip>
            <v-spacer />
            <v-btn size="x-small" variant="text" color="amber-darken-3" :title="t('SpecialToolsView.card.manage')"
                   icon="mdi-open-in-new" @click="router.push({ name: 'special-tools' })" />
        </v-card-title>
        <v-divider />
        <v-card-text class="pa-0">
            <div v-if="loading && !tools.length" class="text-center py-4">
                <v-progress-circular indeterminate size="24" width="2" color="amber-darken-3" />
            </div>
            <div v-else-if="!tools.length" class="text-center py-5 text-medium-emphasis">
                <v-icon size="28" color="grey-lighten-1" class="mb-1">mdi-toolbox-outline</v-icon>
                <div class="text-body-2">{{ t('SpecialToolsView.card.empty') }}</div>
            </div>
            <v-list v-else density="compact" class="py-0">
                <v-list-item v-for="tool in tools" :key="tool.id" class="px-3" @click="open(tool)">
                    <template #prepend>
                        <v-icon size="small" :color="toolColor(tool)">{{ toolIcon(tool) }}</v-icon>
                    </template>
                    <v-list-item-title class="text-body-2">
                        <span class="font-weight-medium">{{ tool.name }}</span>
                        <v-chip v-if="tool.category" size="x-small" variant="tonal" class="ml-2">{{ tool.category }}</v-chip>
                    </v-list-item-title>
                    <v-list-item-subtitle class="text-caption">
                        <span v-if="locationText(tool)">
                            <v-icon size="x-small">mdi-map-marker-outline</v-icon> {{ locationText(tool) }}
                        </span>
                        <span v-if="tool.status === 'lent'" class="text-warning ml-2">
                            · {{ t('SpecialToolsView.card.lent', { name: tool.lent_to || '?' }) }}
                        </span>
                        <span v-else-if="tool.status === 'defective'" class="text-error ml-2">· {{ t('SpecialToolsView.card.defective') }}</span>
                        <span v-if="tool.pinned" class="ml-2">· {{ t('SpecialToolsView.card.pinned') }}</span>
                        <span v-else-if="tool.rule_labels?.length" class="ml-2">· {{ tool.rule_labels.join(', ') }}</span>
                    </v-list-item-subtitle>
                </v-list-item>
            </v-list>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useSpecialTools } from '@/features/lxcars/composables/useSpecialTools.js'

const props = defineProps({
    cId: { type: [Number, String], default: null },
})

const { t } = useI18n()
const router = useRouter()
const api = useSpecialTools()

const tools = ref([])
const loading = ref(false)

async function load() {
    const id = Number(props.cId)
    if (!id) { tools.value = []; return }
    loading.value = true
    try { tools.value = await api.fetchForCar(id) }
    catch (e) { tools.value = [] }
    finally { loading.value = false }
}

watch(() => props.cId, load, { immediate: true })

function locationText(tool) {
    return [tool.location, tool.warehouse && tool.bin ? `${tool.warehouse} / ${tool.bin}` : null].filter(Boolean).join(' · ')
}
function toolColor(tool) { return { lent: 'warning', defective: 'error' }[tool.status] || 'amber-darken-3' }
function toolIcon(tool) {
    if (tool.status === 'lent') return 'mdi-account-arrow-right-outline'
    if (tool.status === 'defective') return 'mdi-alert-circle-outline'
    return 'mdi-wrench-outline'
}
function open(tool) { router.push({ name: 'special-tools', query: { tool: tool.id } }) }

defineExpose({ reload: load })
</script>
