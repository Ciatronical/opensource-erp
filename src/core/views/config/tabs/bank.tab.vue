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
        </v-row>
    </div>
</template>

<script setup>
import { defineProps } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    defaults: {
        type: Object,
        required: true
    }
});

const yesNoOptions = [
    { title: t('yes'), value: true },
    { title: t('no'), value: false }
];
</script>
