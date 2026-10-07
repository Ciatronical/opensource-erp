<!-- document-period-nav.component.vue -->
<!--
    Monatsnavigation für die Belegsuche: Vormonat/Folgemonat blättern,
    Monat aus einem Raster wählen, "Dieser Monat" und "Alle" als Schnellzugriff.
    modelValue: { year, month } (month 1-12) oder null = kein Zeitraum.
-->
<template>
    <div class="d-flex align-center flex-wrap ga-1">
        <v-btn
            icon="mdi-chevron-left"
            variant="text"
            density="comfortable"
            :title="t('SearchView.period.prev_month')"
            @click="shift(-1)"
        />

        <v-menu v-model="pickerOpen" :close-on-content-click="false">
            <template #activator="{ props: menu }">
                <v-btn
                    v-bind="menu"
                    :variant="modelValue ? 'tonal' : 'outlined'"
                    :color="modelValue ? 'primary' : undefined"
                    prepend-icon="mdi-calendar-month"
                    append-icon="mdi-menu-down"
                    class="text-none px-3"
                    min-width="190"
                >
                    {{ label }}
                </v-btn>
            </template>

            <v-card min-width="300">
                <v-card-text class="pb-2">
                    <div class="d-flex align-center justify-space-between mb-2">
                        <v-btn icon="mdi-chevron-left" variant="text" density="comfortable" @click="pickerYear--" />
                        <span class="text-subtitle-1 font-weight-medium">{{ pickerYear }}</span>
                        <v-btn icon="mdi-chevron-right" variant="text" density="comfortable" @click="pickerYear++" />
                    </div>
                    <div class="month-grid">
                        <v-btn
                            v-for="m in 12"
                            :key="m"
                            size="small"
                            class="text-none"
                            :variant="isSelected(pickerYear, m) ? 'flat' : (isCurrent(pickerYear, m) ? 'outlined' : 'text')"
                            :color="isSelected(pickerYear, m) || isCurrent(pickerYear, m) ? 'primary' : undefined"
                            :disabled="isFuture(pickerYear, m)"
                            @click="pick(pickerYear, m)"
                        >
                            {{ shortMonthName(m) }}
                        </v-btn>
                    </div>
                </v-card-text>
                <v-divider />
                <v-card-actions class="px-3">
                    <v-btn size="small" variant="text" prepend-icon="mdi-calendar-today" @click="pickToday">
                        {{ t('SearchView.period.this_month') }}
                    </v-btn>
                    <v-spacer />
                    <v-btn size="small" variant="text" prepend-icon="mdi-calendar-remove" @click="pickAll">
                        {{ t('SearchView.period.all') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-menu>

        <v-btn
            icon="mdi-chevron-right"
            variant="text"
            density="comfortable"
            :title="t('SearchView.period.next_month')"
            :disabled="!modelValue || isFuture(nextOf(modelValue).year, nextOf(modelValue).month)"
            @click="shift(1)"
        />

        <v-btn
            v-if="!isThisMonth"
            size="small"
            variant="text"
            prepend-icon="mdi-calendar-today"
            class="text-none"
            @click="pickToday"
        >
            {{ t('SearchView.period.this_month') }}
        </v-btn>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();

const props = defineProps({
    /** { year, month } oder null */
    modelValue: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['update:modelValue', 'clear']);

const now = new Date();
const pickerOpen = ref(false);
const pickerYear = ref(props.modelValue?.year ?? now.getFullYear());

/**
 * Monatsname in der aktiven Sprache
 */
const monthName = (month, style = 'long') =>
    new Date(2000, month - 1, 1).toLocaleDateString(locale.value, { month: style });
const shortMonthName = (month) => monthName(month, 'short').replace('.', '');

const label = computed(() => {
    if (!props.modelValue) return t('SearchView.period.all');
    return `${monthName(props.modelValue.month)} ${props.modelValue.year}`;
});

const isSelected = (y, m) => props.modelValue?.year === y && props.modelValue?.month === m;
const isCurrent = (y, m) => y === now.getFullYear() && m === now.getMonth() + 1;
const isFuture = (y, m) => y > now.getFullYear() || (y === now.getFullYear() && m > now.getMonth() + 1);
const isThisMonth = computed(() => !!props.modelValue && isCurrent(props.modelValue.year, props.modelValue.month));

const nextOf = ({ year, month }) => month === 12 ? { year: year + 1, month: 1 } : { year, month: month + 1 };
const prevOf = ({ year, month }) => month === 1 ? { year: year - 1, month: 12 } : { year, month: month - 1 };

/**
 * Blättert um einen Monat; ohne Zeitraum startet das Blättern beim aktuellen Monat
 */
function shift(delta) {
    const base = props.modelValue ?? { year: now.getFullYear(), month: now.getMonth() + 1 };
    const next = delta > 0 ? nextOf(base) : (props.modelValue ? prevOf(base) : base);
    if (isFuture(next.year, next.month)) return;
    pick(next.year, next.month);
}

function pick(year, month) {
    pickerYear.value = year;
    pickerOpen.value = false;
    emit('update:modelValue', { year, month });
}

function pickToday() {
    pick(now.getFullYear(), now.getMonth() + 1);
}

/**
 * Zeitraum aufheben. 'clear' zusätzlich, damit der Aufrufer auch dann reagieren
 * kann, wenn bereits kein Monat gewählt war (v-model löst dann nichts aus).
 */
function pickAll() {
    pickerOpen.value = false;
    emit('update:modelValue', null);
    emit('clear');
}
</script>

<style scoped>
.month-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
}
</style>
