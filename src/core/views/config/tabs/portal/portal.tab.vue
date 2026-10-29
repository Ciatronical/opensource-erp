<!-- src/core/views/config/tabs/portal/portal.tab.vue -->
<!--
    Reiter „Kundenportal“ der Firmenkonfiguration (Gruppe Funktionen).
    Webseiten des Kundenportals (dev/kundenportal.md): je Webseite in HugoCMS,
    über die Kunden ihr Konto erreichen, eine Karte mit Name, Schalter,
    Kennzahlen und den Einstellungen (website-settings.vue, dort auch „Paket
    installieren“ unter „Verbindung prüfen“). Dazu Webseiten anlegen und löschen.

    Recht: edit_shop_config — wie die Kanäle des Shops. Aufruf mit
    ?tab=portal&website=<id> klappt die Einstellungen dieser Webseite auf.
-->
<template>
    <div>
        <h3 class="text-h6 mb-1">{{ t('WebsitesView.title') }}</h3>
        <div class="text-caption text-medium-emphasis mb-4">{{ t('WebsitesView.subtitle') }}</div>

        <v-alert v-if="!darf" type="warning" variant="tonal" density="compact">
            {{ t('WebsitesView.errors.NO_PERMISSION') }}
        </v-alert>

        <template v-else>
            <div class="text-body-2 text-medium-emphasis mb-3">{{ t('WebsitesView.intro') }}</div>

            <v-alert v-if="fehler" type="error" variant="tonal" density="compact" class="mb-3">{{ fehler }}</v-alert>
            <div v-else-if="laedt" class="d-flex align-center pa-2">
                <v-progress-circular indeterminate size="20" width="2" class="mr-2" />
            </div>

            <section-card v-if="!laedt && !fehler" icon="mdi-web-plus" :title="t('WebsitesView.newWebsite')">
                <div class="d-flex flex-wrap align-center ga-2">
                    <v-text-field
                        v-model="neu.name"
                        :label="t('WebsitesView.name')"
                        autocomplete="off"
                        style="max-width: 40ch"
                        @keyup.enter="anlegen"
                    />
                    <v-btn
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-plus"
                        :disabled="!neu.name.trim()"
                        :loading="legtAn"
                        @click="anlegen"
                    >
                        {{ t('WebsitesView.create') }}
                    </v-btn>
                </div>
            </section-card>

            <section-card
                v-for="seite in webseiten"
                :id="`webseite-${seite.id}`"
                :key="seite.id"
                icon="mdi-web"
                class="mt-4 webseite-karte"
            >
                <template #title>
                    {{ seite.name }}
                    <v-chip size="x-small" variant="flat" class="ml-2" :color="seite.active ? 'success' : 'grey'">
                        {{ t(seite.active ? 'WebsitesView.active' : 'WebsitesView.activeHint').split('.')[0] }}
                    </v-chip>
                </template>
                <template #actions>
                    <v-progress-circular v-if="speichert[seite.id]" indeterminate size="16" width="2" class="mr-2" />
                    <v-btn
                        v-if="!seite.active && !seite.channels.length"
                        icon="mdi-delete-outline"
                        variant="text"
                        size="small"
                        color="error"
                        :title="t('WebsitesView.delete')"
                        @click="loeschenFragen(seite)"
                    />
                </template>

                <v-row density="compact">
                    <v-col cols="12" sm="6" md="4">
                        <v-text-field
                            v-model="seite.name"
                            :label="t('WebsitesView.name')"
                            autocomplete="off"
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="8" class="d-flex align-center">
                        <v-switch
                            v-model="seite.active"
                            :label="t('WebsitesView.active')"
                            :hint="t('WebsitesView.activeHint')"
                            persistent-hint
                            color="primary"
                        />
                    </v-col>
                </v-row>

                <!-- Was auf der Webseite läuft, und die Kennzahlen -->
                <div class="d-flex flex-wrap align-center ga-2 mt-3">
                    <span class="text-body-2 text-medium-emphasis">{{ t('WebsitesView.features') }}</span>
                    <v-chip size="small" variant="tonal" prepend-icon="mdi-account-circle-outline">{{ t('WebsitesView.feature.portal') }}</v-chip>
                    <v-chip
                        v-for="kanal in seite.channels"
                        :key="kanal.id"
                        size="small"
                        variant="tonal"
                        :color="kanal.active ? 'primary' : 'grey'"
                        prepend-icon="mdi-storefront"
                        @click="router.push({ name: 'shop-channels', query: { channel: kanal.id } })"
                    >
                        {{ kanal.name }}
                    </v-chip>
                    <v-chip v-if="extensions.includes('lxcars')" size="small" variant="tonal" prepend-icon="mdi-car">{{ t('WebsitesView.feature.lxcars') }}</v-chip>
                    <v-spacer />
                    <span class="text-caption text-medium-emphasis">
                        {{ t('WebsitesView.logins', { anzahl: seite.logins }) }} ·
                        {{ t('WebsitesView.invoices', { anzahl: seite.invoices }) }} ·
                        {{ t('WebsitesView.activationsOpen', { anzahl: seite.activations_open }) }}
                    </span>
                </div>
                <div v-if="!seite.channels.length" class="text-caption text-medium-emphasis mt-1">
                    {{ t('WebsitesView.noChannels') }}
                </div>

                <!-- Einstellungen: erst beim Aufklappen geladen -->
                <v-expansion-panels v-model="einstellungenOffen[seite.id]" variant="accordion" color="grey-lighten-3" class="mt-4">
                    <v-expansion-panel>
                        <v-expansion-panel-title>
                            <v-icon start size="small">mdi-cog-outline</v-icon>
                            {{ t('WebsitesView.settings', { name: seite.name }) }}
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                            <WebsiteSettings
                                :website-id="seite.id"
                                :website-name="seite.name"
                                :settings="seite.settings"
                                :secrets-set="seite.secrets_set"
                                :secrets="secrets[seite.id] || {}"
                                :template-sets="templateSets"
                                :signing-key="signingKey"
                                :backend-url-vorschlag="backendUrlVorschlag"
                            />
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </section-card>
        </template>

        <v-dialog v-model="loeschDialog" max-width="480">
            <v-card>
                <v-card-title>{{ t('WebsitesView.delete') }}</v-card-title>
                <v-card-text>{{ t('WebsitesView.deleteConfirm', { name: zuLoeschen?.name || '' }) }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="loeschDialog = false">{{ t('WebsitesView.cancel') }}</v-btn>
                    <v-btn color="error" variant="tonal" :loading="loescht" @click="loeschen">
                        {{ t('WebsitesView.delete') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Hilfsanzeige für Felder mit Langfassung der Hilfe (field.help) — zunächst
             nur hier, zum Ausprobieren -->
        <help-panel />
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import SectionCard from '@/core/components/layout/section-card.vue'
import HelpPanel from '@/core/components/layout/help-panel.vue'
import WebsiteSettings from './website-settings.vue'
import { oserpStore } from '@/core/stores/oserp.store.js'
import * as toasts from '@/core/utils/toasts.js'

const { t, te } = useI18n()
const router = useRouter()
const route = useRoute()
const oserp = oserpStore()

const darf = computed(() => oserp.checkPermission('edit_shop_config'))

const webseiten = ref([])
/** Geheimnisse je Webseite (getWebsiteSecrets): Schlüssel -> Wert, zum Vergleichen im Fehlerfall */
const secrets = ref({})
const templateSets = ref([])
const signingKey = ref('')
const backendUrlVorschlag = ref('')
const extensions = ref([])
const laedt = ref(false)
const fehler = ref('')
const speichert = ref({})
const einstellungenOffen = ref({})
const neu = reactive({ name: '' })
const legtAn = ref(false)

const WAHR = [true, 't', 'true', '1', 1]

function alsObjekt(wert) {
    if (wert && typeof wert === 'object') return wert
    try { return JSON.parse(wert || '{}') || {} } catch { return {} }
}
function alsListe(wert) {
    if (Array.isArray(wert)) return wert
    try { const liste = JSON.parse(wert || '[]'); return Array.isArray(liste) ? liste : [] } catch { return [] }
}

function alsWebseite(zeile) {
    return {
        id: Number(zeile.id),
        name: String(zeile.name || ''),
        active: WAHR.includes(zeile.active),
        settings: alsObjekt(zeile.settings),
        secrets_set: alsObjekt(zeile.secrets_set),
        channels: alsListe(zeile.channels).map(k => ({ ...k, id: Number(k.id), active: WAHR.includes(k.active) })),
        logins: Number(zeile.logins) || 0,
        invoices: Number(zeile.invoices) || 0,
        activations_open: Number(zeile.activations_open) || 0,
    }
}

/** Lesbarer Text zu einer Fehlerantwort */
function fehlerText(antwort, ersatz) {
    const code = String(antwort?.text || '').split(':')[0].trim()
    const schluessel = `WebsitesView.errors.${code}`
    if (code && te(schluessel)) return t(schluessel)
    return antwort?.debug || t(ersatz)
}

// ── Laden und Speichern ──

let bereit = false
const timer = {}
const zuletzt = {}

function nutzdaten(seite) {
    return { website_id: seite.id, name: seite.name.trim(), active: seite.active }
}

async function laden() {
    bereit = false
    laedt.value = true
    fehler.value = ''
    try {
        const antwort = await axios.post('/api/portal/', { action: 'getWebsites' })
        if (!antwort.data?.success) {
            fehler.value = fehlerText(antwort.data, 'WebsitesView.loadError')
            return
        }
        const daten = antwort.data.payload || {}
        webseiten.value = (daten.websites || []).map(alsWebseite)
        templateSets.value = daten.template_sets || []
        signingKey.value = daten.signing_key || ''
        backendUrlVorschlag.value = daten.backend_url_suggestion || ''
        extensions.value = daten.extensions || []
        webseiten.value.forEach(s => { zuletzt[s.id] = JSON.stringify(nutzdaten(s)) })
        await geheimnisseLaden()
    } catch (e) {
        fehler.value = fehlerText(e?.response?.data, 'WebsitesView.loadError')
    } finally {
        laedt.value = false
    }
    await nextTick()
    bereit = true
    const fokus = Number(route.query.website) || 0
    if (fokus) {
        einstellungenOffen.value[fokus] = 0
        await nextTick()
        document.getElementById(`webseite-${fokus}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    } else if (1 === webseiten.value.length) {
        // Eine einzige Webseite: ihre Einstellungen gleich aufklappen — man
        // kommt hierher, um genau sie einzurichten
        einstellungenOffen.value[webseiten.value[0].id] = 0
    }
}

/** Schlüssel aller Webseiten — eigener Aufruf, getWebsites liefert nur, ob sie hinterlegt sind */
async function geheimnisseLaden() {
    const antwort = await axios.post('/api/portal/', { action: 'getWebsiteSecrets', website_id: 0 })
    if (!antwort.data?.success) {
        toasts.warning(fehlerText(antwort.data, 'WebsitesView.secretsLoadError'))
        return
    }
    const geladen = alsObjekt(antwort.data.payload?.secrets)
    const naechste = {}
    Object.keys(geladen).forEach(id => { naechste[Number(id)] = alsObjekt(geladen[id]) })
    secrets.value = naechste
}

async function speichern(seite) {
    const daten = nutzdaten(seite)
    const stand = JSON.stringify(daten)
    if (stand === zuletzt[seite.id]) return
    if (!daten.name) return
    speichert.value[seite.id] = true
    try {
        const antwort = await axios.post('/api/portal/', { action: 'saveWebsite', ...daten })
        if (!antwort.data?.success) {
            toasts.error(fehlerText(antwort.data, 'WebsitesView.saveError'))
            return
        }
        zuletzt[seite.id] = stand
        seite.active = WAHR.includes(antwort.data.payload?.active)
        // Zähler der eingeschalteten Webseiten in der Sitzung nachziehen (Reiter „Kundenkonto“ der Kundenakte)
        oserp.loadCompanyConfig().catch(() => {})
    } catch (e) {
        toasts.error(fehlerText(e?.response?.data, 'WebsitesView.saveError'))
    } finally {
        speichert.value[seite.id] = false
    }
}

watch(webseiten, (liste) => {
    if (!bereit) return
    liste.forEach(seite => {
        if (JSON.stringify(nutzdaten(seite)) === zuletzt[seite.id]) return
        clearTimeout(timer[seite.id])
        timer[seite.id] = setTimeout(() => speichern(seite), 800)
    })
}, { deep: true })

async function anlegen() {
    const name = neu.name.trim()
    if (!name) return
    legtAn.value = true
    try {
        const antwort = await axios.post('/api/portal/', { action: 'createWebsite', name })
        if (!antwort.data?.success) {
            toasts.error(fehlerText(antwort.data, 'WebsitesView.saveError'))
            return
        }
        toasts.success(t('WebsitesView.created'))
        neu.name = ''
        await laden()
        const id = Number(antwort.data.payload?.website_id) || 0
        if (id) einstellungenOffen.value[id] = 0
    } catch (e) {
        toasts.error(fehlerText(e?.response?.data, 'WebsitesView.saveError'))
    } finally {
        legtAn.value = false
    }
}

// ── Löschen ──

const loeschDialog = ref(false)
const zuLoeschen = ref(null)
const loescht = ref(false)

function loeschenFragen(seite) {
    zuLoeschen.value = seite
    loeschDialog.value = true
}

async function loeschen() {
    if (!zuLoeschen.value) return
    loescht.value = true
    try {
        const antwort = await axios.post('/api/portal/', { action: 'deleteWebsite', website_id: zuLoeschen.value.id })
        if (!antwort.data?.success) {
            toasts.error(fehlerText(antwort.data, 'WebsitesView.saveError'))
            return
        }
        toasts.success(t('WebsitesView.deleted'))
        loeschDialog.value = false
        await laden()
        oserp.loadCompanyConfig().catch(() => {})
    } catch (e) {
        toasts.error(fehlerText(e?.response?.data, 'WebsitesView.saveError'))
    } finally {
        loescht.value = false
    }
}

onMounted(() => { if (darf.value) laden() })
</script>

<style scoped>
.webseite-karte {
    scroll-margin-top: var(--oserp-gap-xl, 96px);
}
</style>
