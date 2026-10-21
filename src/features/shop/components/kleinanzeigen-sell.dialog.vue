<!-- src/features/shop/components/kleinanzeigen-sell.dialog.vue -->
<!--
    Verkauf über Kleinanzeigen festhalten (dev/shop-kleinanzeigen.md): Menge,
    erzielter Preis, Zahlart, Käufer (bestehender Kunde, neuer Kunde aus Name
    und Kontakt, oder der Barverkaufskunde des Kanals) — mit Rechnung und
    Lagerbuchung oder nur als Vermerk. Aus einer Anfrage heraus geöffnet,
    ist der Absender als Käufer vorbelegt und die Anfrage gilt als gewonnen.
-->
<template>
    <v-dialog v-model="offen" max-width="640">
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon start color="success">mdi-cash-check</v-icon>
                {{ t('ShopView.kleinanzeigen.sell.title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" size="small" @click="offen = false" />
            </v-card-title>
            <v-card-subtitle v-if="ad" class="text-wrap">{{ ad.partnumber }} · {{ ad.title }}</v-card-subtitle>
            <v-divider class="mt-2" />

            <v-card-text>
                <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-3">{{ fehler }}</v-alert>

                <v-row dense>
                    <v-col cols="4">
                        <v-text-field
                            v-model.number="form.qty"
                            type="number"
                            min="0.01"
                            step="1"
                            :label="t('ShopView.kleinanzeigen.sell.qty')"
                            :suffix="ad?.unit || ''"
                            :hint="ad ? t('ShopView.kleinanzeigen.sell.onhand', { bestand: ad.onhand ?? 0 }) : ''"
                            persistent-hint
                            variant="outlined"
                            density="compact"
                        />
                    </v-col>
                    <v-col cols="4">
                        <v-text-field
                            v-model.number="form.price"
                            type="number"
                            min="0"
                            step="0.01"
                            :label="t('ShopView.kleinanzeigen.sell.price')"
                            suffix="€"
                            :hint="t('ShopView.kleinanzeigen.sell.priceHint')"
                            persistent-hint
                            variant="outlined"
                            density="compact"
                        />
                    </v-col>
                    <v-col cols="4">
                        <v-select
                            v-model="form.payment"
                            :items="zahlarten"
                            :label="t('ShopView.kleinanzeigen.sell.payment')"
                            variant="outlined"
                            density="compact"
                            hide-details="auto"
                        />
                    </v-col>
                </v-row>

                <div class="text-h6 mt-2 mb-3">{{ t('ShopView.kleinanzeigen.sell.total') }}: {{ betrag }} €</div>

                <v-checkbox
                    v-model="form.with_invoice"
                    :label="t('ShopView.kleinanzeigen.sell.withInvoice')"
                    :hint="t('ShopView.kleinanzeigen.sell.withInvoiceHint')"
                    persistent-hint
                    color="primary"
                    density="compact"
                    class="mb-3"
                />

                <div class="text-subtitle-2 mb-1">{{ t('ShopView.kleinanzeigen.sell.buyer') }}</div>
                <v-autocomplete
                    v-model="form.customer_id"
                    v-model:search="kundenSuche"
                    :items="kunden"
                    item-title="name"
                    item-value="id"
                    :label="t('ShopView.kleinanzeigen.sell.existingCustomer')"
                    :loading="kundenLaden"
                    :no-data-text="kundenSuche.length < 3 ? t('ShopView.kleinanzeigen.sell.typeToSearch') : t('ShopView.kleinanzeigen.sell.noCustomer')"
                    no-filter
                    clearable
                    variant="outlined"
                    density="compact"
                    hide-details="auto"
                    class="mb-2"
                >
                    <template #item="{ props: itemProps, item }">
                        <v-list-item v-bind="itemProps" :subtitle="[item.raw.customernumber, item.raw.zipcode, item.raw.city].filter(Boolean).join(' · ')" />
                    </template>
                </v-autocomplete>

                <v-row v-if="!form.customer_id" dense>
                    <v-col cols="12" sm="6">
                        <v-text-field v-model="form.name" :label="t('ShopView.kleinanzeigen.sell.newName')" variant="outlined" density="compact" hide-details="auto" autocomplete="off" />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-text-field v-model="form.email" :label="t('ShopView.kleinanzeigen.sell.email')" variant="outlined" density="compact" hide-details="auto" autocomplete="off" />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-text-field v-model="form.phone" :label="t('ShopView.kleinanzeigen.sell.phone')" variant="outlined" density="compact" hide-details="auto" autocomplete="off" />
                    </v-col>
                    <v-col cols="12" sm="6">
                        <v-text-field v-model="form.city" :label="t('ShopView.kleinanzeigen.sell.city')" variant="outlined" density="compact" hide-details="auto" autocomplete="off" />
                    </v-col>
                    <v-col cols="12">
                        <div class="text-caption text-medium-emphasis">{{ t('ShopView.kleinanzeigen.sell.buyerHint') }}</div>
                    </v-col>
                </v-row>

                <v-textarea v-model="form.note" :label="t('ShopView.kleinanzeigen.sell.note')" rows="2" auto-grow variant="outlined" density="compact" hide-details class="mt-3" />
            </v-card-text>

            <v-divider />
            <v-card-actions>
                <v-spacer />
                <v-btn variant="text" @click="offen = false">{{ t('ShopView.common.cancel') }}</v-btn>
                <v-btn color="success" variant="flat" prepend-icon="mdi-cash-check" :disabled="!(form.qty > 0)" :loading="laeuft" @click="verkaufen">
                    {{ t('ShopView.kleinanzeigen.sell.confirm') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import { useShop } from '@/features/shop/composables/useShop.js'
import { formatNumber } from '@/core/utils/numberFormat.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    channelId: { type: Number, default: 0 },
    /** Anzeige aus dem Board (parts_id, partnumber, title, price, onhand, unit) */
    ad: { type: Object, default: null },
    /** Anfrage, aus der der Verkauf entsteht */
    leadId: { type: Number, default: 0 },
    /** Vorbelegter Käufername (Absender der Anfrage) */
    buyerName: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'verkauft'])

const { t, locale } = useI18n()
const shop = useShop()

const offen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const fehler = ref('')
const laeuft = ref(false)
const form = reactive({ qty: 1, price: 0, payment: 'cash', with_invoice: true, customer_id: null, name: '', email: '', phone: '', city: '', note: '' })

// Kundensuche: vor dem Öffnen-Watcher deklariert, der sie beim Start leert
const kunden = ref([])
const kundenSuche = ref('')
const kundenLaden = ref(false)

const zahlarten = computed(() => ['cash', 'transfer', 'paypal', 'secure'].map(w => ({ value: w, title: t(`ShopView.kleinanzeigen.payment.${w}`) })))
const betrag = computed(() => formatNumber((Number(form.qty) || 0) * (Number(form.price) || 0), locale.value))

watch(() => props.modelValue, auf => {
    if (!auf) return
    fehler.value = ''
    form.qty = 1
    form.price = Number(props.ad?.price ?? 0)
    form.payment = 'cash'
    form.with_invoice = true
    form.customer_id = null
    form.name = props.buyerName || ''
    form.email = ''
    form.phone = ''
    form.city = ''
    form.note = ''
    kunden.value = []
    kundenSuche.value = ''
}, { immediate: true })

// Kundensuche wie in der Faktura (searchFakturaCustomers), verzögert
let suchTimer = null
watch(kundenSuche, suche => {
    clearTimeout(suchTimer)
    const begriff = (suche || '').trim()
    if (begriff.length < 3) return
    suchTimer = setTimeout(async () => {
        kundenLaden.value = true
        try {
            const { data } = await axios.post('/api/faktura/', { action: 'searchFakturaCustomers', search: begriff, type: 'customer' })
            kunden.value = data?.results || []
        } catch {
            kunden.value = []
        } finally {
            kundenLaden.value = false
        }
    }, 300)
})

async function verkaufen() {
    fehler.value = ''
    laeuft.value = true
    const antwort = await shop.sellKleinanzeigenAd({
        parts_id: props.ad?.parts_id, channel_id: props.channelId,
        qty: form.qty, price: form.price, payment: form.payment, with_invoice: form.with_invoice,
        buyer: form.customer_id
            ? { customer_id: form.customer_id }
            : { name: form.name, email: form.email, phone: form.phone, city: form.city },
        lead_id: props.leadId || undefined,
        note: form.note,
    })
    laeuft.value = false
    if (!antwort) {
        fehler.value = shop.error.value || t('ShopView.errors.API_ERROR')
        return
    }
    toasts.success(antwort.invnumber
        ? t('ShopView.kleinanzeigen.sell.doneInvoice', { nummer: antwort.invnumber })
        : t('ShopView.kleinanzeigen.sell.done'))
    if (antwort.ar_id && !antwort.posted) {
        toasts.warning(t('ShopView.kleinanzeigen.sell.notPosted', { grund: antwort.post_reason || '' }))
    }
    emit('verkauft', antwort)
    offen.value = false
}
</script>
