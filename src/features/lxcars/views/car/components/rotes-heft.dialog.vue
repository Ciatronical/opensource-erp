<!-- src/features/lxcars/views/car/components/rotes-heft.dialog.vue -->

<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="560"
        @keydown.esc="$emit('update:modelValue', false)"
    >
        <v-card class="rotes-heft" rounded="lg">
            <!-- Header -->
            <v-card-title class="rotes-heft__header pa-3 d-flex align-center">
                <v-icon class="mr-2" size="small">mdi-book-open-variant</v-icon>
                <div>
                    <div class="text-subtitle-1 font-weight-bold">{{ t('CarEditView.rotesHeft.title') }}</div>
                    <div class="text-caption">{{ t('CarEditView.rotesHeft.subtitle') }}</div>
                </div>
                <v-spacer />
                <v-btn icon variant="text" size="small" @click="$emit('update:modelValue', false)">
                    <v-icon>mdi-close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider color="black" />

            <!-- Fahrzeugdaten-Tabelle -->
            <v-card-text class="rotes-heft__body pa-0">
                <table class="rotes-heft__table">
                    <!-- 1. Fahrzeugklasse und Aufbauart -->
                    <tr>
                        <td class="rotes-heft__nr">1</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field1') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.klasse && !kbaData?.aufbau }]">{{ formatField(kbaData?.klasse, kbaData?.aufbau) }}</td>
                    </tr>

                    <!-- 2. Herstellerbezeichnung / Marke -->
                    <tr>
                        <td class="rotes-heft__nr">2</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field2') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.hersteller && !kbaData?.d1 }]">{{ kbaData?.hersteller || kbaData?.d1 || noData }}</td>
                    </tr>

                    <!-- 3. Fahrzeug-Identifizierungsnummer -->
                    <tr>
                        <td class="rotes-heft__nr">3</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field3') }}</td>
                        <td :class="['rotes-heft__value', 'rotes-heft__value--fin', { 'rotes-heft__value--missing': !car?.c_fin }]">{{ car?.c_fin || noData }}</td>
                    </tr>

                    <!-- 4a. Hubraum -->
                    <tr>
                        <td class="rotes-heft__nr">4a</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field4a') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.hubraum }]">{{ kbaData?.hubraum || noData }}</td>
                    </tr>

                    <!-- 4b. Leistung -->
                    <tr>
                        <td class="rotes-heft__nr">4b</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field4b') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.leistung }]">{{ kbaData?.leistung || noData }}</td>
                    </tr>

                    <!-- 4c. Leermasse bei Krafträdern -->
                    <tr>
                        <td class="rotes-heft__nr">4c</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field4c') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.masse }]">{{ kbaData?.masse || noData }}</td>
                    </tr>

                    <!-- 5. Datum der Erstzulassung -->
                    <tr>
                        <td class="rotes-heft__nr">5</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field5') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !car?.c_d }]">{{ formattedErstzulassung }}</td>
                    </tr>

                    <!-- 6. Zulässige Gesamtmasse -->
                    <tr>
                        <td class="rotes-heft__nr">6</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field6') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.f2 }]">{{ kbaData?.f2 || noData }}</td>
                    </tr>

                    <!-- 7. Höchstzulässige Achslasten -->
                    <tr>
                        <td class="rotes-heft__nr" rowspan="3">7</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field7') }} – {{ t('CarEditView.rotesHeft.field7Axle') }} 1</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.field_7_1 }]">{{ kbaData?.field_7_1 || noData }}</td>
                    </tr>
                    <tr>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field7Axle') }} 2</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.field_7_2 }]">{{ kbaData?.field_7_2 || noData }}</td>
                    </tr>
                    <tr>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field7Axle') }} 3</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.field_7_3 }]">{{ kbaData?.field_7_3 || noData }}</td>
                    </tr>

                    <!-- 8. Bauartbedingte Höchstgeschwindigkeit -->
                    <tr>
                        <td class="rotes-heft__nr">8</td>
                        <td class="rotes-heft__label">{{ t('CarEditView.rotesHeft.field8') }}</td>
                        <td :class="['rotes-heft__value', { 'rotes-heft__value--missing': !kbaData?.t }]">{{ kbaData?.t || noData }}</td>
                    </tr>

                    <!-- 9. Unterschrift -->
                    <tr>
                        <td class="rotes-heft__nr">9</td>
                        <td class="rotes-heft__label" colspan="2">
                            {{ t('CarEditView.rotesHeft.field9') }}
                            <div class="rotes-heft__hint">{{ t('CarEditView.rotesHeft.field9Hint') }}</div>
                        </td>
                    </tr>
                </table>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

export default {
    name: 'RotesHeftDialog',

    props: {
        modelValue: { type: Boolean, default: false },
        car: { type: Object, default: () => ({}) },
        kbaData: { type: Object, default: null }
    },

    emits: ['update:modelValue'],

    setup(props) {
        const { t } = useI18n()

        const noData = computed(() => t('CarEditView.rotesHeft.noData'))

        const formattedErstzulassung = computed(() => {
            if (!props.car?.c_d) return noData.value
            const d = new Date(props.car.c_d)
            if (isNaN(d.getTime())) return props.car.c_d
            return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
        })

        function formatField(...parts) {
            const filled = parts.filter(Boolean)
            return filled.length ? filled.join(' / ') : noData.value
        }

        return { t, noData, formattedErstzulassung, formatField }
    }
}
</script>

<style scoped>
.rotes-heft {
    border: 2px solid #333;
}

.rotes-heft__header {
    background-color: #e8a0a0;
    color: #333;
}

.rotes-heft__body {
    background-color: #f5d0d0;
}

.rotes-heft__table {
    width: 100%;
    border-collapse: collapse;
}

.rotes-heft__table tr {
    border-bottom: 1px solid #c09090;
}

.rotes-heft__table tr:last-child {
    border-bottom: none;
}

.rotes-heft__nr {
    width: 40px;
    padding: 10px 8px 10px 12px;
    font-weight: 700;
    font-size: 0.85rem;
    color: #555;
    vertical-align: top;
    border-right: 1px solid #c09090;
}

.rotes-heft__label {
    padding: 10px 12px;
    font-size: 0.85rem;
    color: #444;
    vertical-align: top;
}

.rotes-heft__value {
    padding: 10px 12px;
    font-size: 1.15rem;
    font-weight: 700;
    color: #111;
    text-align: right;
    vertical-align: top;
    letter-spacing: 0.5px;
}

.rotes-heft__value--fin {
    font-family: monospace;
    font-size: 1.1rem;
    letter-spacing: 1.5px;
}

.rotes-heft__value--missing {
    color: #b71c1c;
    font-style: italic;
    font-size: 0.9rem;
}

.rotes-heft__hint {
    margin-top: 6px;
    padding: 4px 8px;
    font-size: 0.75rem;
    font-weight: 700;
    color: #b71c1c;
    background-color: rgba(183, 28, 28, 0.08);
    border-radius: 4px;
    display: inline-block;
}
</style>
