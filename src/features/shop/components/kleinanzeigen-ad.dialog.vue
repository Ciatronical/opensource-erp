<!-- src/features/shop/components/kleinanzeigen-ad.dialog.vue -->
<!--
    Die Anzeige eines Artikels für Kleinanzeigen (dev/shop-kleinanzeigen.md):
    links der Inhalt (Titel, Text, Preis, Vorgaben, Bilder), rechts der Weg
    zum Einstellen — Kleinanzeigen hat keine Schnittstelle, also Kopieren und
    Einfügen mit so wenig Klicks wie möglich, dazu die Rückmeldung „ist online“.

    Nach dem Einstellen zeigt der Dialog den Stand (online seit, Aufgaben) und
    nimmt die Rückmeldungen entgegen: aktualisiert, aufgefrischt, reserviert,
    gelöscht.
-->
<template>
    <v-dialog v-model="offen" max-width="1180" scrollable>
        <v-card v-if="ad">
            <v-card-title class="d-flex align-center flex-wrap ga-2">
                <v-icon start>mdi-bullhorn-outline</v-icon>
                <span>{{ t('ShopView.kleinanzeigen.ad.title') }}</span>
                <v-chip size="small" variant="outlined">{{ ad.partnumber }}</v-chip>
                <v-chip size="small" variant="flat" :color="standFarbe(ad.state)">{{ t(`ShopView.kleinanzeigen.state.${ad.state}`) }}</v-chip>
                <v-chip v-if="ad.todo" size="small" variant="flat" :color="todoFarbe(ad.todo)" prepend-icon="mdi-alert-circle-outline">
                    {{ t(`ShopView.kleinanzeigen.todo.${ad.todo}`) }}
                </v-chip>
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" size="small" @click="offen = false" />
            </v-card-title>

            <v-divider />

            <v-card-text>
                <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-3">{{ fehler }}</v-alert>
                <v-alert v-if="ad.missing?.length" type="warning" variant="tonal" density="compact" class="mb-3">
                    {{ t('ShopView.kleinanzeigen.ad.missing') }}
                    {{ ad.missing.map(m => t(`ShopView.kleinanzeigen.missing.${m}`)).join(', ') }}
                </v-alert>

                <v-row>
                    <!-- Inhalt der Anzeige -->
                    <v-col cols="12" md="7">
                        <v-text-field
                            v-model="form.title"
                            :label="t('ShopView.kleinanzeigen.ad.adTitle')"
                            :counter="65"
                            maxlength="65"
                            variant="outlined"
                            density="compact"
                            autocomplete="off"
                        >
                            <template #append-inner>
                                <v-btn icon="mdi-content-copy" variant="text" size="small" :title="t('ShopView.kleinanzeigen.ad.copy')" @click="kopieren(form.title, 'title')" />
                            </template>
                        </v-text-field>

                        <v-textarea
                            v-model="form.text"
                            :label="t('ShopView.kleinanzeigen.ad.adText')"
                            :counter="4000"
                            maxlength="4000"
                            rows="12"
                            auto-grow
                            variant="outlined"
                            density="compact"
                        >
                            <template #append-inner>
                                <v-btn icon="mdi-content-copy" variant="text" size="small" :title="t('ShopView.kleinanzeigen.ad.copy')" @click="kopieren(form.text, 'text')" />
                            </template>
                        </v-textarea>

                        <!-- KI: Titel und Text schreiben lassen -->
                        <div v-if="aiReady" class="d-flex flex-wrap align-center ga-2 mb-4">
                            <v-text-field
                                v-model="kiHinweis"
                                :label="t('ShopView.kleinanzeigen.ad.aiHint')"
                                variant="outlined"
                                density="compact"
                                hide-details
                                class="flex-grow-1"
                                style="min-width: 240px"
                                @keyup.enter="kiText"
                            />
                            <v-btn color="primary" variant="tonal" prepend-icon="mdi-creation" :loading="kiLaeuft" @click="kiText">
                                {{ t('ShopView.kleinanzeigen.ad.aiWrite') }}
                            </v-btn>
                            <AiModelButton assistant="kleinanzeigen" />
                        </div>

                        <v-row dense>
                            <v-col cols="6" sm="3">
                                <v-text-field
                                    v-model.number="form.price"
                                    type="number"
                                    min="0"
                                    step="1"
                                    :label="t('ShopView.kleinanzeigen.ad.price')"
                                    :placeholder="String(ad.price)"
                                    persistent-placeholder
                                    suffix="€"
                                    variant="outlined"
                                    density="compact"
                                    hide-details="auto"
                                >
                                    <template #append-inner>
                                        <v-btn icon="mdi-content-copy" variant="text" size="x-small" :title="t('ShopView.kleinanzeigen.ad.copy')" @click="kopieren(String(preisWert), 'price')" />
                                    </template>
                                </v-text-field>
                            </v-col>
                            <v-col cols="6" sm="3">
                                <v-select
                                    v-model="form.price_type"
                                    :items="preisarten"
                                    :label="t('ShopView.kleinanzeigen.ad.priceType')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="6" sm="3">
                                <v-select
                                    v-model="form.shipping"
                                    :items="versandarten"
                                    :label="t('ShopView.kleinanzeigen.ad.shipping')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="6" sm="3">
                                <v-select
                                    v-model="form.condition"
                                    :items="zustaende"
                                    :label="t('ShopView.kleinanzeigen.ad.condition')"
                                    variant="outlined"
                                    density="compact"
                                    hide-details="auto"
                                />
                            </v-col>
                            <v-col cols="12" sm="8">
                                <v-text-field
                                    v-model="form.category"
                                    :label="t('ShopView.kleinanzeigen.ad.category')"
                                    :hint="t('ShopView.kleinanzeigen.ad.categoryHint')"
                                    persistent-hint
                                    variant="outlined"
                                    density="compact"
                                    autocomplete="off"
                                />
                            </v-col>
                            <v-col cols="12" sm="4">
                                <v-text-field
                                    :model-value="ad.location"
                                    :label="t('ShopView.kleinanzeigen.ad.location')"
                                    readonly
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                />
                            </v-col>
                        </v-row>

                        <!-- Bilder: die des Kanals; Pflege in der Artikelkarte -->
                        <div class="text-caption text-medium-emphasis mt-4 mb-1">
                            {{ t('ShopView.kleinanzeigen.ad.images', { anzahl: ad.images.length }) }}
                        </div>
                        <div class="d-flex flex-wrap ga-2 align-center">
                            <v-img
                                v-for="bild in ad.images"
                                :key="bild.id"
                                :src="bild.url"
                                width="72"
                                height="72"
                                cover
                                class="rounded border"
                            />
                            <v-btn
                                v-if="ad.images.length"
                                size="small"
                                variant="tonal"
                                prepend-icon="mdi-folder-zip-outline"
                                :loading="zipLaedt"
                                @click="zipLaden"
                            >
                                {{ t('ShopView.kleinanzeigen.ad.imagesZip') }}
                            </v-btn>
                            <v-btn size="small" variant="text" prepend-icon="mdi-image-edit-outline" :to="entityRoute('article', ad.parts_id)">
                                {{ t('ShopView.kleinanzeigen.ad.imagesEdit') }}
                            </v-btn>
                        </div>
                    </v-col>

                    <!-- Der Weg zu Kleinanzeigen -->
                    <v-col cols="12" md="5">
                        <v-card variant="tonal" class="pa-4">
                            <!-- Automatisch (Kleinanzeigen-Dienst): ein Knopf stellt ein -->
                            <template v-if="ad.state === 'draft' && automatic">
                                <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('ShopView.kleinanzeigen.auto.title') }}</div>
                                <div class="text-body-2 mb-3">{{ t('ShopView.kleinanzeigen.auto.postHint') }}</div>
                                <v-btn color="primary" variant="flat" prepend-icon="mdi-rocket-launch-outline" :loading="dienstLaeuft" :disabled="ad.missing?.length > 0" @click="dienst('publish')">
                                    {{ t('ShopView.kleinanzeigen.auto.postNow') }}
                                </v-btn>
                                <div v-if="ad.missing?.length" class="text-caption text-warning mt-2">{{ t('ShopView.kleinanzeigen.auto.postBlocked') }}</div>
                                <div v-if="ad.sync_error" class="text-caption text-error mt-2">{{ ad.sync_error }}</div>
                                <div v-if="dienstFoto" class="mt-3">
                                    <div class="text-caption text-medium-emphasis mb-1">{{ t('ShopView.kleinanzeigen.auto.screenshot') }}</div>
                                    <v-img :src="'data:image/png;base64,' + dienstFoto" max-width="420" class="rounded border" />
                                </div>
                            </template>
                            <template v-else-if="ad.state === 'draft'">
                                <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('ShopView.kleinanzeigen.ad.howTo') }}</div>
                                <ol class="pl-4 text-body-2 schritte">
                                    <li>
                                        {{ t('ShopView.kleinanzeigen.ad.step1') }}
                                        <div class="mt-1">
                                            <v-btn color="primary" size="small" prepend-icon="mdi-open-in-new" href="https://www.kleinanzeigen.de/p-anzeige-aufgeben.html" target="_blank" rel="noopener" @click="speichern(false)">
                                                {{ t('ShopView.kleinanzeigen.ad.openPost') }}
                                            </v-btn>
                                        </div>
                                    </li>
                                    <li>
                                        {{ t('ShopView.kleinanzeigen.ad.step2') }}
                                        <div class="d-flex flex-wrap ga-1 mt-1">
                                            <v-btn size="small" variant="outlined" :prepend-icon="kopiert === 'title' ? 'mdi-check' : 'mdi-content-copy'" @click="kopieren(form.title, 'title')">{{ t('ShopView.kleinanzeigen.ad.copyTitle') }}</v-btn>
                                            <v-btn size="small" variant="outlined" :prepend-icon="kopiert === 'text' ? 'mdi-check' : 'mdi-content-copy'" @click="kopieren(form.text, 'text')">{{ t('ShopView.kleinanzeigen.ad.copyText') }}</v-btn>
                                            <v-btn size="small" variant="outlined" :prepend-icon="kopiert === 'price' ? 'mdi-check' : 'mdi-content-copy'" @click="kopieren(String(preisWert), 'price')">{{ preisWert }} €</v-btn>
                                        </div>
                                    </li>
                                    <li>{{ t('ShopView.kleinanzeigen.ad.step3') }}</li>
                                    <li>
                                        {{ t('ShopView.kleinanzeigen.ad.step4') }}
                                        <v-text-field
                                            v-model="url"
                                            :label="t('ShopView.kleinanzeigen.ad.url')"
                                            :placeholder="t('ShopView.kleinanzeigen.ad.urlPlaceholder')"
                                            persistent-placeholder
                                            variant="outlined"
                                            density="compact"
                                            hide-details="auto"
                                            class="mt-2"
                                            autocomplete="off"
                                            @keyup.enter="online"
                                        />
                                        <v-btn color="success" class="mt-2" prepend-icon="mdi-check-bold" :disabled="!url.trim()" :loading="speichert" @click="online">
                                            {{ t('ShopView.kleinanzeigen.ad.isOnline') }}
                                        </v-btn>
                                    </li>
                                </ol>
                            </template>

                            <template v-else>
                                <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('ShopView.kleinanzeigen.ad.onlineTitle') }}</div>
                                <div class="text-body-2 mb-2">
                                    <div v-if="ad.online_since">{{ t('ShopView.kleinanzeigen.ad.onlineSince', { datum: datum(ad.online_since), tage: ad.days_online ?? 0 }) }}</div>
                                    <div v-if="ad.external_id">{{ t('ShopView.kleinanzeigen.ad.adId', { id: ad.external_id }) }}</div>
                                    <div v-if="ad.leads">{{ t('ShopView.kleinanzeigen.ad.openLeads', { anzahl: ad.leads }) }}</div>
                                </div>
                                <v-btn v-if="ad.url" size="small" variant="outlined" prepend-icon="mdi-open-in-new" :href="ad.url" target="_blank" rel="noopener" class="mb-3">
                                    {{ t('ShopView.kleinanzeigen.ad.openAd') }}
                                </v-btn>

                                <v-alert v-if="ad.todo" :type="ad.todo === 'delete' ? 'error' : 'warning'" variant="tonal" density="compact" class="mb-3">
                                    <div class="font-weight-medium">{{ t(`ShopView.kleinanzeigen.todo.${ad.todo}`) }}</div>
                                    <div class="text-caption">{{ t(`ShopView.kleinanzeigen.todoHint.${ad.todo}`) }}</div>
                                </v-alert>

                                <!-- Automatisch: Aufgaben gleich vom Dienst erledigen lassen -->
                                <div v-if="automatic" class="d-flex flex-wrap ga-1 mb-3">
                                    <v-btn size="small" color="primary" variant="flat" prepend-icon="mdi-update" :loading="dienstLaeuft" @click="dienst('publish')">{{ t('ShopView.kleinanzeigen.auto.updateNow') }}</v-btn>
                                    <v-btn size="small" color="info" variant="tonal" prepend-icon="mdi-refresh" :loading="dienstLaeuft" @click="dienst('extend')">{{ t('ShopView.kleinanzeigen.auto.refreshNow') }}</v-btn>
                                    <v-btn size="small" color="error" variant="tonal" prepend-icon="mdi-delete-outline" :loading="dienstLaeuft" @click="dienst('delete')">{{ t('ShopView.kleinanzeigen.auto.deleteNow') }}</v-btn>
                                </div>
                                <div v-if="automatic && dienstFoto" class="mb-3">
                                    <v-img :src="'data:image/png;base64,' + dienstFoto" max-width="420" class="rounded border" />
                                </div>
                                <div class="text-caption text-medium-emphasis mb-1">{{ automatic ? t('ShopView.kleinanzeigen.auto.manualFeedback') : t('ShopView.kleinanzeigen.ad.feedback') }}</div>
                                <div class="d-flex flex-wrap ga-1">
                                    <v-btn v-if="ad.todo === 'update'" size="small" color="primary" variant="tonal" prepend-icon="mdi-update" :loading="speichert" @click="stand('updated')">{{ t('ShopView.kleinanzeigen.action.updated') }}</v-btn>
                                    <v-btn size="small" variant="tonal" prepend-icon="mdi-refresh" :loading="speichert" @click="stand('refreshed')">{{ t('ShopView.kleinanzeigen.action.refreshed') }}</v-btn>
                                    <v-btn v-if="ad.state === 'active'" size="small" variant="tonal" prepend-icon="mdi-bookmark-outline" :loading="speichert" @click="stand('reserved')">{{ t('ShopView.kleinanzeigen.action.reserved') }}</v-btn>
                                    <v-btn v-else size="small" variant="tonal" prepend-icon="mdi-bookmark-off-outline" :loading="speichert" @click="stand('unreserved')">{{ t('ShopView.kleinanzeigen.action.unreserved') }}</v-btn>
                                    <v-btn size="small" color="success" variant="flat" prepend-icon="mdi-cash-check" @click="$emit('verkaufen', ad)">{{ t('ShopView.kleinanzeigen.action.sold') }}</v-btn>
                                    <v-btn size="small" color="error" variant="tonal" prepend-icon="mdi-delete-outline" :loading="speichert" @click="stand('ended')">{{ t('ShopView.kleinanzeigen.action.ended') }}</v-btn>
                                </div>
                                <div class="text-caption text-medium-emphasis mt-3">{{ t('ShopView.kleinanzeigen.ad.copyHint') }}</div>
                                <div class="d-flex flex-wrap ga-1 mt-1">
                                    <v-btn size="small" variant="outlined" :prepend-icon="kopiert === 'title' ? 'mdi-check' : 'mdi-content-copy'" @click="kopieren(form.title, 'title')">{{ t('ShopView.kleinanzeigen.ad.copyTitle') }}</v-btn>
                                    <v-btn size="small" variant="outlined" :prepend-icon="kopiert === 'text' ? 'mdi-check' : 'mdi-content-copy'" @click="kopieren(form.text, 'text')">{{ t('ShopView.kleinanzeigen.ad.copyText') }}</v-btn>
                                    <v-btn size="small" variant="outlined" :prepend-icon="kopiert === 'price' ? 'mdi-check' : 'mdi-content-copy'" @click="kopieren(String(preisWert), 'price')">{{ preisWert }} €</v-btn>
                                </div>
                            </template>
                        </v-card>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />
            <v-card-actions>
                <span v-if="geaendert" class="text-caption text-medium-emphasis ml-2">{{ t('ShopView.kleinanzeigen.ad.unsaved') }}</span>
                <v-spacer />
                <v-btn variant="text" @click="offen = false">{{ t('ShopView.common.close') }}</v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-content-save-outline" :disabled="!geaendert" :loading="speichert" @click="speichern(true)">
                    {{ t('ShopView.kleinanzeigen.ad.save') }}
                </v-btn>
            </v-card-actions>
        </v-card>
        <v-card v-else class="pa-6 d-flex justify-center">
            <v-progress-circular indeterminate />
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShop } from '@/features/shop/composables/useShop.js'
import { entityRoute } from '@/core/constants/routes.js'
import { downloadBase64File } from '@/core/utils/download.js'
import { aiModelStore } from '@/core/stores/ai-model.store.js'
import AiModelButton from '@/core/components/ai-model-button.vue'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    partsId: { type: Number, default: 0 },
    channelId: { type: Number, default: 0 },
    /** Anthropic-Schlüssel hinterlegt — sonst kein KI-Knopf */
    aiReady: { type: Boolean, default: false },
    /** Kleinanzeigen-Dienst stellt selbst ein (dev/shop-kleinanzeigen.md, Teil 2) */
    automatic: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'geaendert', 'verkaufen'])

const { t, te, locale } = useI18n()
const shop = useShop()
const aiModels = aiModelStore()

const offen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })

const ad = ref(null)
const fehler = ref('')
const form = reactive({ title: '', text: '', price: null, price_type: 'fixed', shipping: 'both', condition: '', category: '' })
const gespeichert = ref('')
const url = ref('')
const kopiert = ref('')
const speichert = ref(false)
const zipLaedt = ref(false)
const kiLaeuft = ref(false)
const kiHinweis = ref('')
const dienstLaeuft = ref(false)
const dienstFoto = ref('')

const preisarten = computed(() => ['fixed', 'negotiable', 'giveaway'].map(w => ({ value: w, title: t(`ShopView.kleinanzeigen.priceType.${w}`) })))
const versandarten = computed(() => ['pickup', 'shipping', 'both'].map(w => ({ value: w, title: t(`ShopView.kleinanzeigen.shipping.${w}`) })))
const zustaende = computed(() => [
    { value: '', title: t('ShopView.partCard.channelDefault') },
    ...['new', 'like_new', 'very_good', 'good', 'ok', 'defective'].map(w => ({ value: w, title: t(`ShopView.kleinanzeigen.condition.${w}`) })),
])

const preisWert = computed(() => (form.price === null || form.price === '' ? ad.value?.price : Math.max(0, Math.round(Number(form.price)))))
const geaendert = computed(() => JSON.stringify(form) !== gespeichert.value)

const standFarbe = s => ({ draft: 'grey', active: 'success', reserved: 'info', ended: 'grey' }[s] || 'grey')
const todoFarbe = s => ({ update: 'warning', delete: 'error', refresh: 'info' }[s] || 'grey')
const datum = w => (w ? new Date(String(w).replace(' ', 'T')).toLocaleDateString(locale.value) : '')

function uebernehmen(neu) {
    ad.value = neu
    form.title = neu.title || ''
    form.text = neu.text || ''
    // Preis: nur ein eigener Wert steht im Feld, sonst der Kanalpreis als Platzhalter
    form.price = neu.own_price ?? null
    form.price_type = neu.price_type || 'fixed'
    form.shipping = neu.shipping || 'both'
    form.condition = neu.condition || ''
    form.category = neu.category || ''
    gespeichert.value = JSON.stringify(form)
}

async function laden() {
    ad.value = null
    fehler.value = ''
    url.value = ''
    const antwort = await shop.fetchKleinanzeigenAd(props.partsId, props.channelId)
    if (!antwort?.ad) {
        fehler.value = shop.error.value || t('ShopView.errors.API_ERROR')
        ad.value = { partnumber: '', images: [], missing: [], state: 'draft' }
        return
    }
    uebernehmen(mitStand(antwort.ad))
}

/** Die Antwort von kleinanzeigen_ad um die Felder des Boards ergänzen */
function mitStand(a) {
    const d = a.sync_data || {}
    return {
        ...a,
        state: a.sync_status || 'draft',
        url: d.url || '',
        online_since: d.online_since || null,
        days_online: d.online_since ? Math.floor((Date.now() - new Date(String(d.online_since).replace(' ', 'T')).getTime()) / 86400000) : null,
        todo: a.todo ?? (['active', 'reserved'].includes(a.sync_status) && d.posted_hash && d.posted_hash !== a.hash ? 'update' : null),
        leads: a.leads ?? ad.value?.leads ?? 0,
    }
}

watch(() => [props.modelValue, props.partsId, props.channelId], ([auf]) => { if (auf && props.partsId) laden() }, { immediate: true })

async function speichern(melden = true) {
    if (!geaendert.value) return true
    speichert.value = true
    const antwort = await shop.saveKleinanzeigenAd({
        parts_id: props.partsId, channel_id: props.channelId,
        title: form.title, text: form.text, price: form.price === '' ? null : form.price,
        price_type: form.price_type, shipping: form.shipping, condition: form.condition, category: form.category,
    })
    speichert.value = false
    if (!antwort?.ad) {
        fehler.value = shop.error.value || t('ShopView.errors.API_ERROR')
        return false
    }
    uebernehmen(mitStand(antwort.ad))
    if (melden) toasts.success(t('ShopView.kleinanzeigen.ad.saved'))
    emit('geaendert')
    return true
}

async function stand(neu, adresse = '') {
    if (!(await speichern(false))) return
    speichert.value = true
    const antwort = await shop.setKleinanzeigenAdState(props.partsId, props.channelId, neu, adresse)
    speichert.value = false
    if (!antwort?.ad) {
        fehler.value = shop.error.value || t('ShopView.errors.API_ERROR')
        return
    }
    uebernehmen(mitStand(antwort.ad))
    toasts.success(t(`ShopView.kleinanzeigen.stateSaved.${neu}`))
    emit('geaendert')
    if (neu === 'ended') offen.value = false
}

const online = () => stand('online', url.value)

/**
 * Über den Kleinanzeigen-Dienst: einstellen/aktualisieren, auffrischen, löschen
 *
 * Erst speichern, dann den Dienst rufen. Ein Fehler des Dienstes kommt mit
 * Bildschirmfoto zurück — das zeigt, wo der Browser hängen blieb.
 */
async function dienst(was) {
    if (!(await speichern(false))) return
    dienstLaeuft.value = true
    dienstFoto.value = ''
    const aufruf = { publish: shop.publishKleinanzeigenAd, extend: shop.extendKleinanzeigenAd, delete: shop.deleteKleinanzeigenAd }[was]
    const antwort = await aufruf(props.partsId, props.channelId)
    dienstLaeuft.value = false
    if (!antwort) {
        const code = shop.errorCode.value || ''
        fehler.value = (te(`ShopView.errors.${code}`) ? t(`ShopView.errors.${code}`) : '') || shop.error.value || t('ShopView.errors.API_ERROR')
        // Bildschirmfoto des Dienstes, falls mitgeliefert (payload.screenshot)
        dienstFoto.value = shop.errorPayload?.value?.screenshot || ''
        await laden()
        return
    }
    fehler.value = ''
    if (antwort.ad) uebernehmen(mitStand(antwort.ad))
    const text = was === 'publish'
        ? t(antwort.updated ? 'ShopView.kleinanzeigen.auto.updated' : 'ShopView.kleinanzeigen.auto.posted', { id: antwort.id })
        : was === 'extend'
            ? t(antwort.method === 'extended' ? 'ShopView.kleinanzeigen.auto.extended' : 'ShopView.kleinanzeigen.auto.republished')
            : t('ShopView.kleinanzeigen.auto.deleted')
    toasts.success(text)
    emit('geaendert')
}

async function kopieren(text, was) {
    try {
        await navigator.clipboard.writeText(text || '')
        kopiert.value = was
        toasts.success(t('ShopView.kleinanzeigen.ad.copied'), { timer: 1500 })
        setTimeout(() => { if (kopiert.value === was) kopiert.value = '' }, 2500)
    } catch {
        toasts.error(t('ShopView.kleinanzeigen.ad.copyFailed'))
    }
}

async function zipLaden() {
    zipLaedt.value = true
    const antwort = await shop.fetchKleinanzeigenImagesZip(props.partsId, props.channelId)
    zipLaedt.value = false
    if (!antwort?.data) {
        toasts.error(shop.error.value || t('ShopView.errors.API_ERROR'))
        return
    }
    downloadBase64File(antwort.data, antwort.filename, antwort.mime)
}

async function kiText() {
    kiLaeuft.value = true
    const antwort = await shop.generateKleinanzeigenText(props.partsId, props.channelId, kiHinweis.value, aiModels.requestModel('kleinanzeigen'))
    kiLaeuft.value = false
    if (!antwort?.title) {
        toasts.error(shop.error.value || t('ShopView.errors.API_ERROR'))
        return
    }
    form.title = antwort.title
    form.text = antwort.text
    toasts.success(t('ShopView.kleinanzeigen.ad.aiDone'))
}
</script>

<style scoped>
.schritte li { margin-bottom: var(--oserp-gap, 12px); }
</style>
