<!-- src/components/settings/tabs/record.links.tab.vue -->
<template>
    <div>
        <h3 class="text-h6 mb-4">{{ $t('recordLinks') }}</h3>

        <v-row>
            <v-col cols="12">
                <v-select
                    v-model="defaults.always_record_links_from_order"
                    :label="$t('alwaysRecordLinksFromOrder')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('alwaysRecordLinksFromOrderHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.record_links_from_order_with_myself"
                    :label="$t('recordLinksFromOrderWithMyself')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('recordLinksFromOrderWithMyselfHelp') }}
                        </v-tooltip>
                    </template>
                </v-select>
            </v-col>

            <v-col cols="12">
                <v-select
                    v-model="defaults.record_links_from_order_with_quotations"
                    :label="$t('recordLinksFromOrderWithQuotations')"
                    :items="yesNoOptions"
                    variant="outlined"
                    density="compact"
                >
                    <template #append-inner>
                        <v-tooltip location="top" max-width="400">
                            <template #activator="{ props }">
                                <v-icon v-bind="props" size="small">mdi-help-circle-outline</v-icon>
                            </template>
                            {{ $t('recordLinksFromOrderWithQuotationsHelp') }}
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
