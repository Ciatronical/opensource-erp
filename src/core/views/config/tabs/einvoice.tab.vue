<!-- src/core/views/config/tabs/einvoice.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ t('einvoice.headline') }}</h3>

        <v-row>
            <v-col cols="12">
                <v-alert
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                    icon="mdi-information-outline"
                >
                    {{ t('einvoice.intro') }}
                </v-alert>
            </v-col>

            <v-col cols="12" md="8">
                <v-select
                    v-model.number="defaults.create_zugferd_invoices"
                    :label="t('einvoice.mode_label')"
                    :items="modeOptions"
                    variant="outlined"
                    density="compact"
                    data-field-name="create_zugferd_invoices"
                />
            </v-col>

            <v-col cols="12" md="4" class="d-flex align-center">
                <v-chip
                    v-if="isTest"
                    color="warning"
                    size="small"
                    prepend-icon="mdi-flask-outline"
                >
                    {{ t('einvoice.test_badge') }}
                </v-chip>
            </v-col>

            <v-col cols="12">
                <v-card variant="outlined" class="pa-4">
                    <div class="d-flex align-center mb-2">
                        <v-icon class="me-2" :color="selectedMode.color">{{ selectedMode.icon }}</v-icon>
                        <strong>{{ selectedMode.title }}</strong>
                    </div>
                    <p class="text-body-2 mb-0" style="white-space: pre-line;">{{ selectedMode.help }}</p>
                </v-card>
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h4 class="text-subtitle-1 mb-2">{{ t('einvoice.requirements_title') }}</h4>
            </v-col>

            <v-col cols="12" md="6">
                <v-card variant="tonal" color="info" class="pa-3">
                    <div class="d-flex align-center">
                        <v-icon class="me-2">mdi-bank-transfer</v-icon>
                        <div>
                            <div class="text-subtitle-2">{{ t('einvoice.bank_title') }}</div>
                            <div class="text-caption">{{ t('einvoice.bank_hint') }}</div>
                        </div>
                    </div>
                </v-card>
            </v-col>

            <v-col cols="12" md="6">
                <v-card variant="tonal" :color="hasVatId ? 'success' : 'warning'" class="pa-3">
                    <div class="d-flex align-center">
                        <v-icon class="me-2">
                            {{ hasVatId ? 'mdi-check-circle' : 'mdi-alert-circle' }}
                        </v-icon>
                        <div>
                            <div class="text-subtitle-2">{{ t('einvoice.vatid_title') }}</div>
                            <div class="text-caption">
                                {{ hasVatId ? t('einvoice.vatid_ok') : t('einvoice.vatid_missing') }}
                            </div>
                        </div>
                    </div>
                </v-card>
            </v-col>

            <v-col cols="12">
                <v-alert
                    v-if="isXRechnung"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mt-2"
                    icon="mdi-identifier"
                >
                    {{ t('einvoice.leitweg_hint') }}
                </v-alert>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    defaults: { type: Object, required: true },
});

const modeOptions = computed(() => [
    { title: t('einvoice.option_off'),          value: 0 },
    { title: t('einvoice.option_zugferd'),      value: 1 },
    { title: t('einvoice.option_zugferd_test'), value: 2 },
    { title: t('einvoice.option_xrechnung'),    value: 3 },
    { title: t('einvoice.option_xrechnung_test'),value: 4 },
]);

const modeDetails = computed(() => ({
    0: { title: t('einvoice.option_off'),           help: t('einvoice.help_off'),            icon: 'mdi-cancel',               color: 'grey' },
    1: { title: t('einvoice.option_zugferd'),       help: t('einvoice.help_zugferd'),        icon: 'mdi-file-document-check',  color: 'primary' },
    2: { title: t('einvoice.option_zugferd_test'),  help: t('einvoice.help_zugferd_test'),   icon: 'mdi-flask-outline',        color: 'warning' },
    3: { title: t('einvoice.option_xrechnung'),     help: t('einvoice.help_xrechnung'),      icon: 'mdi-file-xml-box',         color: 'primary' },
    4: { title: t('einvoice.option_xrechnung_test'),help: t('einvoice.help_xrechnung_test'), icon: 'mdi-flask-outline',        color: 'warning' },
}));

const selectedMode = computed(() => {
    const value = Number(props.defaults.create_zugferd_invoices ?? 0);
    return modeDetails.value[value] ?? modeDetails.value[0];
});

const isTest = computed(() => [2, 4].includes(Number(props.defaults.create_zugferd_invoices)));
const isXRechnung = computed(() => [3, 4].includes(Number(props.defaults.create_zugferd_invoices)));

const hasVatId = computed(() => !!(props.defaults.co_ustid || '').trim());
</script>
