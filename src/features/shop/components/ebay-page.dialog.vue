<!-- src/features/shop/components/ebay-page.dialog.vue -->
<!--
    Produktseite für eBay gestalten (dev/shop-ebay.md, Teil Seiten).

    Links der Aufbau — Vorlage, Farbe, eBay-Titel, Kopf, Stichpunkte, Text,
    technische Daten, Artikelmerkmale, Passform, Lieferumfang, Hinweise —,
    rechts die Vorschau, so wie eBay sie zeigt (Rechner oder Telefon). Gerendert
    wird im Backend (lib/ebay_page.php), dieselbe Stelle für Vorschau und
    Speichern; die Vorschau folgt jeder Eingabe mit kurzer Verzögerung.

    „Mit KI entwerfen“ füllt alle Inhalte aus Artikel, Shop-Angaben und dem
    bestehenden eBay-Angebot; die Gestaltung bleibt. Der Benutzer prüft und
    speichert — vorher geht nichts zu eBay. Zwei weitere Titelvorschläge
    stehen als Chips unter dem Titel.
-->
<template>
    <v-dialog :model-value="modelValue" fullscreen transition="dialog-bottom-transition" @update:model-value="emit('update:modelValue', $event)">
        <v-card class="seite">
            <v-toolbar density="comfortable" color="surface" class="seite__kopf">
                <v-btn icon="mdi-close" variant="text" @click="schliessen" />
                <v-toolbar-title>
                    <span>{{ t('ShopView.ebay.page.title') }}</span>
                    <span v-if="daten?.part" class="text-medium-emphasis text-body-2 ml-2">
                        {{ daten.part.partnumber }} · {{ daten.part.description }}
                    </span>
                </v-toolbar-title>
                <v-chip v-if="daten && !daten.saved" size="small" variant="tonal" color="info" class="mr-2">{{ t('ShopView.ebay.page.draftFromPart') }}</v-chip>
                <v-btn
                    v-if="daten?.listing?.view_url"
                    variant="text"
                    size="small"
                    prepend-icon="mdi-open-in-new"
                    :href="daten.listing.view_url"
                    target="_blank"
                    rel="noopener"
                >
                    {{ t('ShopView.ebay.page.openListing') }}
                </v-btn>
                <v-btn variant="text" :disabled="speichert" @click="schliessen">{{ t('ShopView.common.cancel') }}</v-btn>
                <v-btn
                    v-if="!imKanal"
                    variant="tonal"
                    color="primary"
                    class="mr-2"
                    prepend-icon="mdi-upload"
                    :loading="speichert === 'publish'"
                    :disabled="!darfSpeichern"
                    @click="speichern(true)"
                >
                    {{ t('ShopView.ebay.page.saveAndPublish') }}
                </v-btn>
                <v-btn
                    color="primary"
                    variant="flat"
                    class="mr-2"
                    prepend-icon="mdi-content-save"
                    :loading="speichert === 'save'"
                    :disabled="!darfSpeichern || !imKanal"
                    @click="speichern(false)"
                >
                    {{ t('ShopView.common.save') }}
                </v-btn>
            </v-toolbar>

            <v-progress-linear v-if="laedt" indeterminate color="primary" />
            <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="ma-3" closable @click:close="fehler = ''">{{ fehler }}</v-alert>

            <div v-if="seite" class="seite__inhalt">
                <!-- Aufbau -->
                <div class="seite__formular">
                    <!-- KI-Entwurf -->
                    <section-card :title="t('ShopView.ebay.page.ai.title')" :subtitle="aiReady ? t('ShopView.ebay.page.ai.subtitle') : t('ShopView.ebay.page.ai.notReady')" icon="mdi-creation">
                        <template #actions>
                            <AiModelButton v-if="aiReady" assistant="ebay" />
                        </template>
                        <v-row dense>
                            <v-col cols="12" sm="4">
                                <v-select v-model="ton" :items="toene" :label="t('ShopView.ebay.page.ai.tone')" :disabled="!aiReady" />
                            </v-col>
                            <v-col cols="12" sm="8">
                                <v-text-field
                                    v-model="hinweis"
                                    :label="t('ShopView.ebay.page.ai.hint')"
                                    :placeholder="t('ShopView.ebay.page.ai.hintPlaceholder')"
                                    :disabled="!aiReady"
                                    @keydown.enter="entwerfen"
                                />
                            </v-col>
                        </v-row>
                        <v-btn
                            class="mt-3"
                            color="primary"
                            variant="tonal"
                            prepend-icon="mdi-creation"
                            :loading="entwirft"
                            :disabled="!aiReady"
                            @click="entwerfen"
                        >
                            {{ t(seiteHatInhalt ? 'ShopView.ebay.page.ai.improve' : 'ShopView.ebay.page.ai.generate') }}
                        </v-btn>
                        <span v-if="entwirft" class="text-caption text-medium-emphasis ml-3">{{ t('ShopView.ebay.page.ai.working') }}</span>
                    </section-card>

                    <!-- Gestaltung -->
                    <section-card :title="t('ShopView.ebay.page.design')" icon="mdi-palette-outline" class="mt-3">
                        <div class="d-flex flex-wrap align-center ga-4">
                            <v-btn-toggle v-model="seite.template" mandatory density="comfortable" variant="outlined" divided color="primary">
                                <v-btn v-for="vorlage in vorlagen" :key="vorlage" :value="vorlage">{{ t(`ShopView.ebay.page.templates.${vorlage}`) }}</v-btn>
                            </v-btn-toggle>
                            <div class="d-flex align-center ga-2">
                                <span class="text-body-2 text-medium-emphasis">{{ t('ShopView.ebay.page.color') }}</span>
                                <button
                                    v-for="farbe in FARBEN"
                                    :key="farbe"
                                    type="button"
                                    class="seite__farbe"
                                    :class="{ 'seite__farbe--aktiv': seite.color === farbe }"
                                    :style="{ background: farbe }"
                                    :aria-label="farbe"
                                    @click="seite.color = farbe"
                                />
                                <input v-model="seite.color" type="color" class="seite__farbwahl" :title="t('ShopView.ebay.page.ownColor')">
                            </div>
                            <v-switch v-model="seite.show_images" :label="t('ShopView.ebay.page.showImages')" hide-details />
                        </div>
                    </section-card>

                    <!-- Titel und Kopf -->
                    <section-card :title="t('ShopView.ebay.page.head')" icon="mdi-format-title" class="mt-3">
                        <v-text-field
                            v-model="seite.title"
                            :label="t('ShopView.ebay.page.ebayTitle')"
                            :counter="80"
                            maxlength="80"
                            :hint="t('ShopView.ebay.page.ebayTitleHint')"
                            persistent-hint
                        />
                        <div v-if="titelVorschlaege.length > 1" class="d-flex flex-wrap ga-1 mt-2">
                            <span class="text-caption text-medium-emphasis mr-1 align-self-center">{{ t('ShopView.ebay.page.titleAlternatives') }}</span>
                            <v-chip
                                v-for="vorschlag in titelVorschlaege"
                                :key="vorschlag"
                                size="small"
                                :variant="vorschlag === seite.title ? 'flat' : 'tonal'"
                                :color="vorschlag === seite.title ? 'primary' : undefined"
                                @click="seite.title = vorschlag"
                            >
                                {{ vorschlag }}
                            </v-chip>
                        </div>
                        <v-text-field v-model="seite.headline" :label="t('ShopView.ebay.page.headline')" :placeholder="seite.title" persistent-placeholder class="mt-4" />
                        <v-text-field v-model="seite.subtitle" :label="t('ShopView.ebay.page.subtitle')" class="mt-3" />
                        <v-textarea
                            v-model="stichpunkte"
                            :label="t('ShopView.ebay.page.highlights')"
                            :hint="t('ShopView.ebay.page.onePerLine')"
                            persistent-hint
                            rows="3"
                            auto-grow
                            class="mt-3"
                        />
                    </section-card>

                    <!-- Inhalt -->
                    <section-card :title="t('ShopView.ebay.page.content')" icon="mdi-text-box-outline" class="mt-3">
                        <v-textarea
                            v-model="seite.description"
                            :label="t('ShopView.ebay.page.description')"
                            :hint="t('ShopView.ebay.page.descriptionHint')"
                            persistent-hint
                            rows="6"
                            auto-grow
                        />
                        <ShopKeyValueEditor
                            v-model="technik"
                            class="mt-4"
                            :title="t('ShopView.ebay.page.specs')"
                            :key-label="t('ShopView.ebay.page.name')"
                            :value-label="t('ShopView.ebay.page.value')"
                        />
                        <v-textarea v-model="seite.compatibility" :label="t('ShopView.ebay.page.compatibility')" rows="2" auto-grow class="mt-4" />
                        <v-textarea v-model="seite.scope" :label="t('ShopView.ebay.page.scope')" rows="2" auto-grow class="mt-3" />
                        <v-textarea v-model="seite.notes" :label="t('ShopView.ebay.page.notes')" rows="2" auto-grow class="mt-3" />
                    </section-card>

                    <!-- Artikelmerkmale: bei eBay Filter in der Suche -->
                    <section-card :title="t('ShopView.ebay.page.aspects')" :subtitle="t('ShopView.ebay.page.aspectsHint')" icon="mdi-tag-multiple-outline" class="mt-3">
                        <ShopKeyValueEditor
                            v-model="merkmale"
                            :key-label="t('ShopView.ebay.page.name')"
                            :value-label="t('ShopView.ebay.page.value')"
                        />
                    </section-card>
                </div>

                <!-- Vorschau -->
                <div class="seite__vorschau">
                    <div class="d-flex align-center mb-2">
                        <span class="text-subtitle-2">{{ t('ShopView.ebay.page.preview') }}</span>
                        <v-progress-circular v-if="rendert" indeterminate size="16" width="2" class="ml-2" />
                        <v-spacer />
                        <v-btn-toggle v-model="geraet" mandatory density="compact" variant="outlined" divided>
                            <v-btn value="desktop" icon="mdi-monitor" :title="t('ShopView.ebay.page.desktop')" />
                            <v-btn value="mobile" icon="mdi-cellphone" :title="t('ShopView.ebay.page.mobile')" />
                        </v-btn-toggle>
                    </div>
                    <v-alert v-if="seite.show_images && !(daten?.context?.images || []).length" type="info" variant="tonal" density="compact" class="mb-2">
                        {{ t('ShopView.ebay.page.noImages') }}
                    </v-alert>
                    <div class="seite__rahmen" :class="`seite__rahmen--${geraet}`">
                        <iframe :srcdoc="vorschauDokument" sandbox="" class="seite__iframe" :title="t('ShopView.ebay.page.preview')" />
                    </div>
                </div>
            </div>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import AiModelButton from '@/core/components/ai-model-button.vue'
import ShopKeyValueEditor from './shop-key-value.editor.vue'
import { useShop } from '@/features/shop/composables/useShop.js'
import { aiModelStore } from '@/core/stores/ai-model.store.js'
import * as toasts from '@/core/utils/toasts.js'
import * as alerts from '@/core/utils/alerts.js'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    partsId: { type: Number, default: 0 },
    channelId: { type: Number, default: 0 },
    aiReady: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'gespeichert'])

const { t } = useI18n()
const shop = useShop()
const aiModels = aiModelStore()

/** Akzentfarben zur schnellen Wahl; eigene über das Farbfeld */
const FARBEN = ['#1f6feb', '#0f766e', '#b45309', '#b91c1c', '#7c3aed', '#1f2328']

const daten = ref(null)
const seite = ref(null)
const vorlagen = ref(['klar', 'modern', 'kompakt'])
const html = ref('')
const laedt = ref(false)
const rendert = ref(false)
const entwirft = ref(false)
const speichert = ref('')
const fehler = ref('')
const geraet = ref('desktop')
const ton = ref('sachlich')
const hinweis = ref('')
const titelVorschlaege = ref([])
/** Stand beim Öffnen/Speichern — Schließen fragt nur bei Änderungen nach */
const gespeicherterStand = ref('')

const toene = computed(() => ['sachlich', 'werblich', 'technisch'].map(wert => ({ value: wert, title: t(`ShopView.ebay.page.ai.tones.${wert}`) })))
const imKanal = computed(() => !!daten.value?.in_channel)
const darfSpeichern = computed(() => !!seite.value?.title?.trim() && !entwirft.value)
const seiteHatInhalt = computed(() => !!(seite.value?.description || '').trim() || (seite.value?.highlights || []).length > 0)

// Die Textfelder arbeiten auf Zeilen und Paaren, die Seite auf Listen
const stichpunkte = computed({
    get: () => (seite.value?.highlights || []).join('\n'),
    set: wert => { seite.value.highlights = wert.split('\n').map(z => z.trim()).filter(Boolean) },
})
const paare = schluessel => computed({
    get: () => (seite.value?.[schluessel] || []).map(p => ({ key: p.name, value: p.value })),
    set: liste => { seite.value[schluessel] = liste.map(p => ({ name: p.key, value: p.value })) },
})
const technik = paare('specs')
const merkmale = paare('aspects')

/** Die Vorschau als eigenes Dokument; eBay zeigt die Beschreibung auf weißem Grund */
const vorschauDokument = computed(() => `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:16px;background:#fff">${html.value}</body></html>`)

async function laden() {
    if (!props.partsId || !props.channelId) return
    laedt.value = true
    fehler.value = ''
    titelVorschlaege.value = []
    const antwort = await shop.fetchEbayPage(props.partsId, props.channelId)
    laedt.value = false
    if (!antwort) {
        fehler.value = shop.error.value
        return
    }
    daten.value = antwort
    vorlagen.value = antwort.templates || vorlagen.value
    seite.value = antwort.page
    html.value = antwort.html
    gespeicherterStand.value = JSON.stringify(antwort.page)
}

let zeitgeber = null
watch(seite, () => {
    if (!seite.value) return
    clearTimeout(zeitgeber)
    zeitgeber = setTimeout(vorschau, 350)
}, { deep: true })

async function vorschau() {
    rendert.value = true
    const antwort = await shop.previewEbayPage(props.partsId, props.channelId, seite.value)
    rendert.value = false
    if (antwort) html.value = antwort.html
}

async function entwerfen() {
    if (!props.aiReady || entwirft.value) return
    entwirft.value = true
    fehler.value = ''
    const antwort = await shop.generateEbayPage(props.partsId, props.channelId, {
        page: seite.value, hint: hinweis.value, tone: ton.value, ai_model: aiModels.requestModel('ebay'),
    })
    entwirft.value = false
    if (!antwort) {
        fehler.value = shop.error.value
        return
    }
    seite.value = antwort.page
    html.value = antwort.html
    titelVorschlaege.value = antwort.titles || []
    toasts.success(t('ShopView.ebay.page.ai.done'))
}

async function speichern(einstellen) {
    speichert.value = einstellen ? 'publish' : 'save'
    const antwort = await shop.saveEbayPage(props.partsId, props.channelId, seite.value, einstellen)
    speichert.value = ''
    if (!antwort) {
        fehler.value = shop.error.value
        return
    }
    gespeicherterStand.value = JSON.stringify(seite.value)
    toasts.success(t(einstellen ? 'ShopView.ebay.page.publishedQueued' : (antwort.offered ? 'ShopView.ebay.page.savedQueued' : 'ShopView.ebay.page.saved')))
    emit('gespeichert')
    emit('update:modelValue', false)
}

async function schliessen() {
    if (seite.value && JSON.stringify(seite.value) !== gespeicherterStand.value) {
        const antwort = await alerts.question(t('ShopView.ebay.page.discardQuestion'), '', t('ShopView.ebay.page.discard'), t('ShopView.common.cancel'))
        if (!antwort?.isConfirmed) return
    }
    emit('update:modelValue', false)
}

watch(() => props.modelValue, offen => {
    if (offen) {
        seite.value = null
        laden()
    }
})
</script>

<style scoped>
.seite { background: var(--oserp-surface-1, rgb(var(--v-theme-background))); }
.seite__kopf { border-bottom: 1px solid var(--oserp-border, rgba(0, 0, 0, 0.12)); }
.seite__inhalt {
    display: grid;
    grid-template-columns: minmax(340px, 1fr) minmax(380px, 1.15fr);
    gap: var(--oserp-gap, 16px);
    padding: var(--oserp-gap, 16px);
    align-items: start;
}
.seite__vorschau { position: sticky; top: 76px; }
.seite__rahmen {
    margin: 0 auto;
    border: 1px solid var(--oserp-border, rgba(0, 0, 0, 0.12));
    border-radius: var(--oserp-radius, 8px);
    overflow: hidden;
    background: #fff;
    transition: max-width 0.2s;
}
.seite__rahmen--desktop { max-width: 100%; }
.seite__rahmen--mobile { max-width: 390px; }
.seite__iframe { width: 100%; height: calc(100vh - 170px); border: 0; display: block; }
.seite__farbe {
    width: 22px; height: 22px; border-radius: 50%; border: 2px solid transparent; cursor: pointer;
    outline: 1px solid var(--oserp-border, rgba(0, 0, 0, 0.12));
}
.seite__farbe--aktiv { border-color: rgb(var(--v-theme-surface)); outline: 2px solid rgb(var(--v-theme-primary)); }
.seite__farbwahl { width: 28px; height: 24px; border: 0; padding: 0; background: none; cursor: pointer; }
@media (max-width: 900px) {
    .seite__inhalt { grid-template-columns: 1fr; }
    .seite__vorschau { position: static; }
}
</style>
