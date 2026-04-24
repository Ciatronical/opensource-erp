<!-- src/components/settings/tabs/bank.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('sepa') }}</h3>

        <v-row>
            <v-col cols="12">
                <v-select
                    v-model="defaults.sepa_export_xml"
                    :label="$t('sepaExportXml')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('sepaExportXmlHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-4">{{ $t('bank') }}</h3>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.no_bank_proposals"
                    :label="$t('noBankProposals')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('noBankProposalsHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-divider class="my-4" />
                <h3 class="text-h6 mb-2">{{ $t('fintsProductIdHeadline') }}</h3>
                <v-alert
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                    icon="mdi-information-outline"
                >
                    <span>{{ $t('fintsProductIdIntro') }}</span>
                    <br>
                    <a
                        href="https://www.fints.org/de/hersteller/produktregistrierung"
                        target="_blank"
                        rel="noopener"
                    >https://www.fints.org/de/hersteller/produktregistrierung</a>
                </v-alert>
            </v-col>

            <v-col cols="12" md="8">
                <v-text-field
                    v-if="crmDefaults"
                    v-model="crmDefaults.fints_product_id"
                    :label="$t('fintsProductIdLabel')"
                    :placeholder="$t('fintsProductIdPlaceholder')"
                    :counter="25"
                    :maxlength="25"
                    variant="outlined"
                    density="compact"
                    data-field-name="fints_product_id"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="420">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            <div>
                                <div>{{ $t('fintsProductIdHelp') }}</div>
                                <div class="mt-2">
                                    {{ $t('fintsProductIdForm') }}:
                                    <code>docs/features/FinTS-Produktregistrierung_V1.0.4.pdf</code>
                                </div>
                                <div class="mt-1">
                                    {{ $t('fintsProductIdRegister') }}:
                                    https://www.fints.org/de/hersteller/produktregistrierung
                                </div>
                            </div>
                        </v-tooltip>
                    </template>
                </v-text-field>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    defaults: {
        type: Object,
        required: true
    },
    crmDefaults: {
        type: Object,
        default: null
    }
});

const yesNoOptions = [
    { title: t('yes'), value: true },
    { title: t('no'), value: false }
];
</script>
