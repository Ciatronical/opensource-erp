<!-- src/core/views/calendar/components/calendar-event-detail.vue -->
<template>
    <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="420">
        <v-card v-if="event">
            <!-- Header -->
            <div
                class="event-detail-header pa-4"
                :style="{ background: headerGradient }"
            >
                <div class="d-flex align-center" style="padding-right: 48px;">
                    <v-avatar size="40" color="white" class="mr-3">
                        <v-icon :color="event.color || '#1976D2'">mdi-calendar-clock</v-icon>
                    </v-avatar>
                    <div style="flex: 1; min-width: 0;">
                        <div class="text-white text-h6 font-weight-medium" style="word-break: break-word;">
                            {{ event.title }}
                        </div>
                        <div class="text-white-darken-1 text-caption">
                            {{ formatDateRange }}
                        </div>
                    </div>
                </div>
                <v-btn
                    icon
                    variant="text"
                    size="small"
                    class="close-btn"
                    @click="$emit('update:modelValue', false)"
                >
                    <v-icon color="white">mdi-close</v-icon>
                </v-btn>
            </div>

            <v-card-text class="pa-4">
                <!-- Location -->
                <div v-if="event.location" class="d-flex align-center mb-3">
                    <v-icon size="20" color="grey-darken-1" class="mr-2">mdi-map-marker</v-icon>
                    <span class="text-body-2">{{ event.location }}</span>
                </div>

                <!-- Description -->
                <div v-if="event.description" class="mb-3">
                    <div class="d-flex align-start pa-3 bg-grey-lighten-4 rounded-lg">
                        <v-icon size="20" color="grey-darken-1" class="mr-3 mt-1">mdi-text</v-icon>
                        <p class="text-body-2 text-grey-darken-2 mb-0" style="white-space: pre-wrap;">{{ event.description }}</p>
                    </div>
                </div>

                <!-- Category -->
                <div v-if="event.category_label" class="d-flex align-center mb-2">
                    <v-icon size="20" color="grey-darken-1" class="mr-2">mdi-tag</v-icon>
                    <v-chip
                        size="small"
                        :color="event.category_color"
                        variant="tonal"
                    >
                        {{ event.category_label }}
                    </v-chip>
                </div>

                <!-- Priority -->
                <div class="d-flex align-center mb-2">
                    <v-icon size="20" color="grey-darken-1" class="mr-2">mdi-flag</v-icon>
                    <v-chip size="small" :color="priorityColor" variant="tonal">
                        {{ priorityLabel }}
                    </v-chip>
                </div>

                <!-- Owner -->
                <div v-if="event.owner_name" class="d-flex align-center mb-2">
                    <v-icon size="20" color="grey-darken-1" class="mr-2">mdi-account</v-icon>
                    <span class="text-body-2">{{ event.owner_name }}</span>
                </div>

                <!-- Customer/Vendor -->
                <div v-if="event.cvp_name" class="d-flex align-center mb-2">
                    <v-icon size="20" color="grey-darken-1" class="mr-2">
                        {{ event.cvp_type === 'V' ? 'mdi-truck' : 'mdi-account-tie' }}
                    </v-icon>
                    <v-chip
                        size="small"
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-link"
                    >
                        {{ event.cvp_name }}
                    </v-chip>
                </div>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-btn
                    color="error"
                    variant="tonal"
                    rounded="lg"
                    @click="$emit('delete', event)"
                >
                    <v-icon class="mr-2">mdi-delete</v-icon>
                    {{ t('CalendarEventDetail.delete') }}
                </v-btn>

                <v-spacer />

                <v-btn
                    color="primary"
                    variant="flat"
                    rounded="lg"
                    @click="$emit('edit', event)"
                >
                    <v-icon class="mr-2">mdi-pencil</v-icon>
                    {{ t('CalendarEventDetail.edit') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    event: { type: Object, default: null }
})

defineEmits(['update:modelValue', 'edit', 'delete'])

const { t, locale } = useI18n()

const headerGradient = computed(() => {
    const color = props.event?.color || props.event?.category_color || '#1976D2'
    return `linear-gradient(135deg, ${color} 0%, ${darken(color)} 100%)`
})

const priorityColor = computed(() => {
    const map = { 0: 'green', 1: 'blue', 2: 'red' }
    return map[props.event?.prio] || 'blue'
})

const priorityLabel = computed(() => {
    const map = {
        0: t('CalendarEventDetail.priorityLow'),
        1: t('CalendarEventDetail.priorityNormal'),
        2: t('CalendarEventDetail.priorityHigh')
    }
    return map[props.event?.prio] || map[1]
})

const formatDateRange = computed(() => {
    if (!props.event) return ''
    const opts = locale.value === 'de'
        ? { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }
        : { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }
    const loc = locale.value === 'de' ? 'de-DE' : 'en-US'

    if (props.event.allDay) {
        const dateOpts = { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' }
        const start = new Date(props.event.dtstart).toLocaleDateString(loc, dateOpts)
        if (!props.event.dtend || props.event.dtstart === props.event.dtend) return start
        const end = new Date(props.event.dtend).toLocaleDateString(loc, dateOpts)
        return `${start} – ${end}`
    }

    const start = new Date(props.event.dtstart).toLocaleString(loc, opts)
    if (!props.event.dtend) return start
    const end = new Date(props.event.dtend).toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' })
    return `${start} – ${end}`
})

function darken(hex) {
    if (!hex || hex.length < 7) return '#000000'
    const r = Math.max(0, parseInt(hex.slice(1, 3), 16) - 40)
    const g = Math.max(0, parseInt(hex.slice(3, 5), 16) - 40)
    const b = Math.max(0, parseInt(hex.slice(5, 7), 16) - 40)
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`
}
</script>

<style scoped>
.event-detail-header {
    position: relative;
}

.event-detail-header .close-btn {
    position: absolute;
    top: 12px;
    right: 12px;
}
</style>
