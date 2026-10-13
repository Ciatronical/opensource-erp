<!-- src/core/views/customer-vendor/tabs/billing.tab.vue -->
<!--
    Stammdaten des Kunden/Lieferanten.

    Zwei Darstellungen derselben Karten:
    - Einfacher Modus: eine ruhige Spalte in Lesebreite, nur die Karten mit
      mindestens einem freigegebenen Feld (Firmenkonfiguration → CRM →
      Einfacher Modus). Trägt ein ausgeblendetes Feld einen Wert, steht das
      unten als Hinweis — nichts geht unbemerkt verloren.
    - Alle Felder: die gewohnte zweispaltige Ansicht.
-->
<template>
    <!-- Einfacher Modus -->
    <div v-if="easymode" class="cv-easy pa-2 pa-sm-4">
        <div class="cv-easy__column">
            <name-address-card v-if="cardVisible.nameAddress" v-model="localData" :phone-numbers="phoneNumbers" />
            <communication-card v-if="cardVisible.communication" v-model="localData" />
            <info-status-card
                v-if="cardVisible.infoStatus"
                v-model="localData"
                :business-types="businessTypes"
                :salesmen="salesmen"
                :languages="languages"
                :entity-src="entitySrc"
            />
            <numbers-ids-card v-if="cardVisible.numbersIds" v-model="localData" :entity-src="entitySrc" />
            <access-data-card v-if="cardVisible.accessData" v-model="localData" />
            <currency-prices-tax-card
                v-if="cardVisible.currencyPricesTax"
                v-model="localData"
                :currencies="currencies"
                :pricegroups="pricegroups"
                :taxzones="taxzones"
                :tax-included-options="taxIncludedOptions"
                :zugferd-options="zugferdOptions"
            />
            <conditions-card
                v-if="cardVisible.conditions"
                v-model="localData"
                :payment-terms="paymentTerms"
                :delivery-terms="deliveryTerms"
                :entity-src="entitySrc"
            />

            <!-- Ausgeblendete Felder mit Inhalt -->
            <v-alert
                v-if="hiddenFilled.length"
                type="info"
                variant="tonal"
                density="compact"
                icon="mdi-eye-off-outline"
                class="cv-easy__hidden"
            >
                <div class="d-flex align-center flex-wrap ga-2">
                    <span class="text-body-2">{{ t('CustomerVendorEditView.easymode.hiddenFilled', { count: hiddenFilled.length }) }}</span>
                    <v-chip v-for="field in hiddenFilled" :key="field.key" size="x-small" variant="outlined" label>
                        {{ t(field.label) }}
                    </v-chip>
                    <v-spacer />
                    <v-btn size="small" variant="text" color="primary" prepend-icon="mdi-tune-variant" @click="easymode = false">
                        {{ t('CustomerVendorEditView.easymode.showAll') }}
                    </v-btn>
                </div>
            </v-alert>
        </div>
    </div>

    <!-- Alle Felder -->
    <v-row v-else class="pa-2 pa-sm-3">
        <!-- Linke Spalte: Stammdaten -->
        <v-col cols="12" lg="6">
            <name-address-card v-model="localData" :phone-numbers="phoneNumbers" />
            <communication-card v-model="localData" class="mt-3" />
            <info-status-card
                v-model="localData"
                :business-types="businessTypes"
                :salesmen="salesmen"
                :languages="languages"
                :entity-src="entitySrc"
                class="mt-3"
            />
        </v-col>
        <!-- Rechte Spalte: IDs, Finanzen, Bedingungen -->
        <v-col cols="12" lg="6">
            <numbers-ids-card v-model="localData" :entity-src="entitySrc" />
            <access-data-card v-model="localData" class="mt-3" />
            <currency-prices-tax-card
                v-model="localData"
                :currencies="currencies"
                :pricegroups="pricegroups"
                :taxzones="taxzones"
                :tax-included-options="taxIncludedOptions"
                :zugferd-options="zugferdOptions"
                class="mt-3"
            />
            <conditions-card
                v-model="localData"
                :payment-terms="paymentTerms"
                :delivery-terms="deliveryTerms"
                :entity-src="entitySrc"
                class="mt-3"
            />
        </v-col>
    </v-row>
</template>

<script>
import { computed, provide } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCvEasymode } from '../composables/useCvEasymode.js'
import { CV_FIELD_GROUPS } from '../cvFieldCatalog.js'
import NameAddressCard from '../cards/name.address.card.vue'
import CommunicationCard from '../cards/communication.card.vue'
import InfoStatusCard from '../cards/info.status.card.vue'
import NumbersIdsCard from '../cards/numbers.ids.card.vue'
import AccessDataCard from '../cards/access.data.card.vue'
import CurrencyPricesTaxCard from '../cards/currency.prices.tax.card.vue'
import ConditionsCard from '../cards/conditions.card.vue'

export default {
    name: 'BillingTab',
    components: {
        NameAddressCard,
        CommunicationCard,
        InfoStatusCard,
        NumbersIdsCard,
        AccessDataCard,
        CurrencyPricesTaxCard,
        ConditionsCard,
    },
    props: {
        modelValue: { type: Object, required: true },
        entitySrc: { type: String, default: 'C' },
        businessTypes: Array,
        salesmen: Array,
        languages: Array,
        currencies: Array,
        pricegroups: Array,
        taxzones: Array,
        taxIncludedOptions: Array,
        zugferdOptions: Array,
        paymentTerms: Array,
        deliveryTerms: Array,
        phoneNumbers: { type: Array, default: () => [] },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const { easymode, showAny, hiddenFilledFields } = useCvEasymode()
        // Im einfachen Modus zeigen die Abschnittskarten ihre ruhige Darstellung
        provide('sectionQuiet', easymode)

        const localData = computed({
            get: () => props.modelValue,
            set: (value) => emit('update:modelValue', value)
        })

        // Karte nur zeigen, wenn mindestens ein Feld darin freigegeben ist.
        // Felder nur für Kunden bzw. Lieferanten zählen für die andere Seite nicht.
        const cardVisible = computed(() => {
            const result = {}
            for (const group of CV_FIELD_GROUPS) {
                const keys = group.fields
                    .filter(f => !f.src || f.src === props.entitySrc)
                    .map(f => f.key)
                result[group.key] = showAny(keys)
            }
            return result
        })

        const hiddenFilled = computed(() => hiddenFilledFields(props.modelValue))

        return {
            t,
            easymode,
            localData,
            cardVisible,
            hiddenFilled,
            phoneNumbers: computed(() => props.phoneNumbers),
        }
    }
}
</script>

<style scoped>
.cv-easy__column {
    max-width: var(--oserp-content-max);
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: var(--oserp-gap-lg);
}

.cv-easy__hidden :deep(.v-alert__content) {
    width: 100%;
}
</style>
