<!-- src/features/lxcars/components/maintenance.section.card.vue -->

<template>
    <v-card variant="outlined" class="faktura-card" :class="{ 'is-disabled': !hasCar }">
        <v-card-title class="faktura-card__header">
            <v-icon class="mr-2" size="small">mdi-wrench</v-icon>
            {{ t('MaintenanceSectionCard.title') }}
            <v-spacer />
            <v-chip
                :variant="oeExtData.c_sk ? 'flat' : 'outlined'"
                :color="oeExtData.c_sk ? 'primary' : undefined"
                :disabled="!hasCar"
                size="small"
                class="cursor-pointer"
                @click="onToggleSk"
            >
                <v-icon start size="x-small">{{ oeExtData.c_sk ? 'mdi-link-variant' : 'mdi-link-variant-off' }}</v-icon>
                {{ t('MaintenanceSectionCard.fields.c_sk') }}
            </v-chip>
        </v-card-title>
        <v-divider />
        <v-card-text class="faktura-card__body">
            <v-row dense>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayZrd"
                        :label="t('MaintenanceSectionCard.fields.c_zrd')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        placeholder="MM/JJ"
                        :disabled="!hasCar || oeExtData.c_sk"
                        @blur="onBlurMonthYear('c_zrd')"
                    />
                </v-col>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayZrk"
                        :label="t('MaintenanceSectionCard.fields.c_zrk')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        suffix="km"
                        placeholder="z.B. 180"
                        :disabled="!hasCar || oeExtData.c_sk"
                        @blur="onBlurKm"
                    />
                </v-col>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayBf"
                        :label="t('MaintenanceSectionCard.fields.c_bf')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        placeholder="MM/JJ"
                        :disabled="!hasCar"
                        @blur="onBlurMonthYear('c_bf')"
                    />
                </v-col>
                <v-col cols="12" sm="6" md="3" class="py-1">
                    <v-text-field
                        v-model="displayWd"
                        :label="t('MaintenanceSectionCard.fields.c_wd')"
                        variant="outlined"
                        density="compact"
                        hide-details="auto"
                        placeholder="MM/JJ"
                        :disabled="!hasCar"
                        @blur="onBlurMonthYear('c_wd')"
                    />
                </v-col>
            </v-row>
        </v-card-text>
    </v-card>
</template>

<script>
import { defineComponent, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { parseMonthYear, formatMonthYear } from '@/features/lxcars/utils/validation.js'

export default defineComponent({
    name: 'MaintenanceSectionCard',
    props: {
        oeExtData: { type: Object, required: true },
        hasCar: { type: Boolean, default: false }
    },
    emits: ['oe-ext-field-change'],
    setup(props, { emit }) {
        const { t } = useI18n()

        const displayZrd = ref(formatMonthYear(props.oeExtData.c_zrd))
        const displayBf = ref(formatMonthYear(props.oeExtData.c_bf))
        const displayWd = ref(formatMonthYear(props.oeExtData.c_wd))
        const displayZrk = ref(formatKm(props.oeExtData.c_zrk))

        watch(() => props.oeExtData.c_zrd, v => { displayZrd.value = formatMonthYear(v) })
        watch(() => props.oeExtData.c_bf,  v => { displayBf.value  = formatMonthYear(v) })
        watch(() => props.oeExtData.c_wd,  v => { displayWd.value  = formatMonthYear(v) })
        watch(() => props.oeExtData.c_zrk, v => { displayZrk.value = formatKm(v) })

        function formatKm(value) {
            const num = Number(value)
            if (!num) return ''
            return num.toLocaleString('de-DE')
        }

        const displayRefs = { c_zrd: displayZrd, c_bf: displayBf, c_wd: displayWd }

        function onBlurMonthYear(field) {
            const raw = (displayRefs[field].value || '').trim()
            const parsed = raw ? parseMonthYear(raw) : null
            const current = props.oeExtData[field] || null
            if (parsed === current) {
                displayRefs[field].value = formatMonthYear(parsed)
                return
            }
            displayRefs[field].value = formatMonthYear(parsed)
            emit('oe-ext-field-change', field, parsed)
        }

        function onBlurKm() {
            const raw = String(displayZrk.value).replace(/[.\s]/g, '').trim()
            let km = null
            if (raw) {
                const num = parseInt(raw, 10)
                if (!isNaN(num) && num > 0) km = num < 1000 ? num * 1000 : num
            }
            const current = props.oeExtData.c_zrk || null
            if (km === current) {
                displayZrk.value = formatKm(km)
                return
            }
            displayZrk.value = formatKm(km)
            emit('oe-ext-field-change', 'c_zrk', km)
        }

        function onToggleSk() {
            if (!props.hasCar) return
            emit('oe-ext-field-change', 'c_sk', !props.oeExtData.c_sk)
        }

        return { t, displayZrd, displayBf, displayWd, displayZrk, onBlurMonthYear, onBlurKm, onToggleSk }
    }
})
</script>

<style scoped>
.faktura-card { border-radius: 8px; }
.faktura-card__header {
    padding: 14px 16px !important;
    background-color: #f5f5f5;
    font-size: 14px;
    font-weight: 600;
    color: #333;
    display: flex;
    align-items: center;
}
.faktura-card__body { padding: 16px !important; }
.faktura-card__body :deep(.v-input) { flex: unset; grid-template-rows: auto !important; }
.faktura-card__body :deep(.v-input__details) { display: none !important; }
.faktura-card__body :deep(.v-field--variant-outlined) {
    --v-field-padding-start: 12px;
    --v-field-padding-end: 12px;
}
.is-disabled { opacity: 0.75; }
.cursor-pointer { cursor: pointer; }
</style>
