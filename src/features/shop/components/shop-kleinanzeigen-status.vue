<!-- src/features/shop/components/shop-kleinanzeigen-status.vue -->
<!--
    Stand des Kleinanzeigen-Kanals in der Kanalkarte (dev/shop-kleinanzeigen.md):
    der Kleinanzeigen-Dienst (Headless-Browser) mit Sitzung und Abgleich,
    der Weg zum Board, Anfragen holen, Feed-Adressen für Sync-Werkzeuge.

    Anmeldung: Der Dienst meldet sich nie selbst an. Der Benutzer meldet
    sich in seinem eigenen Browser bei Kleinanzeigen an und übergibt hier
    die Anmelde-IDs (die Cookies der Seite, mindestens refresh_token). Die
    Karte nimmt jede gängige Form an — JSON-Export einer Cookie-Erweiterung,
    cookies.txt, „name=wert“-Zeilen oder die kopierte Tabelle der
    Entwicklerwerkzeuge — und baut daraus die Liste für den Dienst.
-->
<template>
    <v-card variant="outlined" class="pa-4" style="max-width: 760px">
        <div class="d-flex align-center mb-3">
            <v-icon size="32" color="primary" class="mr-3">mdi-bullhorn-outline</v-icon>
            <div>
                <div class="text-subtitle-1 font-weight-medium">{{ t('crm_fields.kleinanzeigenPanelUi.title') }}</div>
                <div class="text-caption text-grey">{{ t('crm_fields.kleinanzeigenPanelUi.description') }}</div>
            </div>
        </div>

        <!-- Dienst -->
        <div class="text-subtitle-2 mb-1">{{ t('crm_fields.kleinanzeigenPanelUi.workerTitle') }}</div>
        <v-alert v-if="status && !status.enabled" type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('crm_fields.kleinanzeigenPanelUi.workerOff') }}
        </v-alert>
        <v-alert v-else-if="status && !status.reachable" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('crm_fields.kleinanzeigenPanelUi.workerDown') }}
            <div v-if="status.message" class="text-caption">{{ status.message }}</div>
        </v-alert>
        <template v-else-if="status">
            <v-alert :type="sitzungTyp" variant="tonal" density="compact" class="mb-3">
                <div>{{ t('crm_fields.kleinanzeigenPanelUi.workerUp', { version: status.version || '?' }) }}</div>
                <div v-if="!status.has_login" class="text-caption">{{ t('crm_fields.kleinanzeigenPanelUi.noLogin') }}</div>
                <div v-else-if="sitzung" class="text-caption">
                    {{ t(`crm_fields.kleinanzeigenPanelUi.session.${sitzung.state}`, { email: status.login_email }) }}
                    <span v-if="sitzung.message"> — {{ sitzung.message }}</span>
                </div>
                <div v-else class="text-caption">
                    {{ t(status.has_cookies ? 'crm_fields.kleinanzeigenPanelUi.sessionStored' : 'crm_fields.kleinanzeigenPanelUi.sessionNone', { email: status.login_email }) }}
                </div>
                <!-- Die übernommenen IDs: Name und Ablauf, nie der Wert -->
                <div v-if="ids.length" class="d-flex flex-wrap ga-1 mt-1">
                    <v-chip v-for="id in ids" :key="id.name + id.domain" size="x-small" variant="outlined" :title="id.expires ? t('crm_fields.kleinanzeigenPanelUi.idExpires', { zeit: id.expires }) : ''">
                        {{ id.name }}
                    </v-chip>
                </div>
                <div v-if="status.ads_last_sync" class="text-caption">
                    {{ t('crm_fields.kleinanzeigenPanelUi.lastSync', { zeit: status.ads_last_sync, anzahl: status.ads_online_count ?? '–' }) }}
                </div>
            </v-alert>

            <div class="d-flex flex-wrap ga-2 mb-3">
                <v-btn variant="outlined" prepend-icon="mdi-account-check-outline" :loading="prueft" :disabled="!status.has_login" @click="pruefen">
                    {{ t('crm_fields.kleinanzeigenPanelUi.checkSession') }}
                </v-btn>
                <v-btn color="primary" :variant="idsOffen ? 'tonal' : 'flat'" prepend-icon="mdi-key-variant" :disabled="!status.has_login" @click="idsOffen = !idsOffen">
                    {{ t('crm_fields.kleinanzeigenPanelUi.takeOver') }}
                </v-btn>
                <v-btn variant="outlined" prepend-icon="mdi-sync" :loading="gleichtAb" :disabled="!status.has_login" @click="abgleichen">
                    {{ t('crm_fields.kleinanzeigenPanelUi.sync') }}
                </v-btn>
            </div>

            <!-- Anmelde-IDs aus dem eigenen Browser übernehmen -->
            <v-card v-if="idsOffen" variant="outlined" class="pa-3 mb-3">
                <div class="text-subtitle-2 mb-1">{{ t('crm_fields.kleinanzeigenPanelUi.takeOverTitle') }}</div>
                <ol class="text-body-2 pl-5 mb-2">
                    <li v-for="n in 4" :key="n">{{ t(`crm_fields.kleinanzeigenPanelUi.takeOverSteps.${n}`) }}</li>
                </ol>
                <v-textarea
                    v-model="idsText"
                    :label="t('crm_fields.kleinanzeigenPanelUi.takeOverField')"
                    :placeholder="t('crm_fields.kleinanzeigenPanelUi.takeOverPlaceholder')"
                    variant="outlined"
                    density="compact"
                    rows="4"
                    auto-grow
                    hide-details
                    autocomplete="off"
                    spellcheck="false"
                    class="mb-2 ids-feld"
                />
                <div class="d-flex flex-wrap align-center ga-2">
                    <v-btn color="primary" variant="flat" prepend-icon="mdi-login-variant" :loading="uebernimmt" :disabled="!erkannt.length" @click="idsUebernehmen">
                        {{ t('crm_fields.kleinanzeigenPanelUi.takeOverBtn') }}
                    </v-btn>
                    <span class="text-caption text-medium-emphasis">
                        {{ erkannt.length ? t('crm_fields.kleinanzeigenPanelUi.takeOverFound', { namen: erkannt.map(c => c.name).join(', ') }) : t('crm_fields.kleinanzeigenPanelUi.takeOverNone') }}
                    </span>
                </div>
            </v-card>

            <!-- Kategoriebaum von Kleinanzeigen (K22): vom Dienst aus dem Formular gelesen, Grundlage der Kategorieauswahl -->
            <div class="d-flex flex-wrap align-center ga-2 mb-3">
                <v-btn variant="outlined" prepend-icon="mdi-file-tree-outline" :loading="laedtKategorien" :disabled="!status.has_login" @click="kategorienLaden">
                    {{ t('crm_fields.kleinanzeigenPanelUi.categories.load') }}
                </v-btn>
                <span class="text-caption text-medium-emphasis">
                    {{ kategorien.count
                        ? t('crm_fields.kleinanzeigenPanelUi.categories.state', { anzahl: kategorien.count, zeit: kategorien.mtime || '' })
                        : t('crm_fields.kleinanzeigenPanelUi.categories.none') }}
                </span>
            </div>

            <!-- Captcha oder unklarer Stand: Bildschirmfoto des Browsers -->
            <div v-if="foto" class="mb-3">
                <div class="text-caption text-medium-emphasis mb-1">{{ t('crm_fields.kleinanzeigenPanelUi.screenshot') }}</div>
                <v-img :src="'data:image/png;base64,' + foto" max-width="720" class="rounded border" />
            </div>
        </template>
        <div v-else class="d-flex align-center pa-2"><v-progress-circular indeterminate size="20" width="2" class="mr-2" /></div>

        <v-alert v-if="meldung.text" :type="meldung.type" variant="tonal" density="compact" class="mb-3">{{ meldung.text }}</v-alert>

        <v-divider class="my-3" />
        <div class="d-flex flex-wrap ga-2 mb-3">
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-view-dashboard-outline" :to="{ name: 'shop-kleinanzeigen', query: { channel: channelId } }">
                {{ t('crm_fields.kleinanzeigenPanelUi.openBoard') }}
            </v-btn>
            <v-btn variant="outlined" prepend-icon="mdi-email-sync-outline" :loading="holt" @click="anfragenHolen">
                {{ t('crm_fields.kleinanzeigenPanelUi.importLeads') }}
            </v-btn>
        </div>

        <!-- Feed für Sync-Werkzeuge: nur mit hinterlegtem Schlüssel -->
        <div class="text-subtitle-2 mt-2">{{ t('crm_fields.kleinanzeigenPanelUi.feedTitle') }}</div>
        <div class="text-caption text-medium-emphasis mb-2">{{ t('crm_fields.kleinanzeigenPanelUi.feedHint') }}</div>
        <template v-if="feed.url_csv">
            <v-text-field
                v-for="art in ['csv', 'json']"
                :key="art"
                :model-value="feed['url_' + art]"
                :label="art.toUpperCase()"
                readonly
                variant="outlined"
                density="compact"
                hide-details
                class="mb-2"
                @focus="$event.target.select()"
            >
                <template #append-inner>
                    <v-btn icon="mdi-content-copy" variant="text" size="small" @click="kopieren(feed['url_' + art])" />
                </template>
            </v-text-field>
        </template>
        <div v-else class="text-caption">{{ t('crm_fields.kleinanzeigenPanelUi.feedNoKey') }}</div>
    </v-card>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShop } from '@/features/shop/composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    channelId: { type: Number, required: true },
})
const emit = defineEmits(['categories'])

const { t, te } = useI18n()
const shop = useShop()

const status = ref(null)
const sitzung = ref(null)
const foto = ref('')
const prueft = ref(false)
const gleichtAb = ref(false)
const holt = ref(false)
const meldung = reactive({ type: 'info', text: '' })
const feed = ref({ url_csv: '', url_json: '' })
const idsOffen = ref(false)
const kategorien = ref({ count: 0, mtime: null })
const laedtKategorien = ref(false)
const idsText = ref('')
const uebernimmt = ref(false)

const ids = computed(() => sitzung.value?.ids || [])

const sitzungTyp = computed(() => {
    if (!status.value?.has_login) return 'warning'
    if (!sitzung.value) return status.value.has_cookies ? 'info' : 'warning'
    return sitzung.value.state === 'ok' ? 'success' : sitzung.value.state === 'error' ? 'error' : 'warning'
})

function fehlerText(code, fallback) {
    const schluessel = `ShopView.errors.${code}`
    return te(schluessel) ? t(schluessel) : (fallback || shop.error.value || t('ShopView.errors.API_ERROR'))
}

async function laden(check = false) {
    const antwort = await shop.fetchKleinanzeigenWorkerStatus(props.channelId, check)
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = shop.error.value || t('ShopView.errors.API_ERROR')
        status.value = status.value || { enabled: false, reachable: false }
        return
    }
    status.value = antwort
    if (antwort.session) {
        sitzung.value = antwort.session
        foto.value = antwort.session.screenshot || ''
    }
}

async function pruefen() {
    prueft.value = true
    meldung.text = ''
    await laden(true)
    prueft.value = false
}

// ── Anmelde-IDs aus dem Browser des Benutzers ──
//
// Erkannt werden: JSON-Liste oder -Objekt (Cookie-Erweiterungen, auch mit
// expirationDate), cookies.txt (Netscape, 7 Spalten mit Tab), die kopierte
// Tabelle der Entwicklerwerkzeuge (Name, Wert, Domain, Pfad, Ablauf …),
// „name=wert“-Zeilen und die Cookie-Kopfzeile „a=b; c=d“.

const COOKIE_NAME = /^[A-Za-z0-9_.!#$%&'*+^`|~-]+$/

function cookieAusObjekt(o) {
    if (!o || typeof o !== 'object' || !o.name) return null
    const ablauf = Number(o.expires ?? o.expirationDate ?? o.expiry ?? 0)
    return {
        name: String(o.name).trim(),
        value: String(o.value ?? ''),
        domain: String(o.domain || '.kleinanzeigen.de'),
        path: String(o.path || '/'),
        expires: ablauf > 0 ? ablauf : undefined,
        httpOnly: o.httpOnly ?? true,
        secure: o.secure ?? true,
        sameSite: ['Strict', 'Lax', 'None'].find(s => s.toLowerCase() === String(o.sameSite || '').toLowerCase().replace('no_restriction', 'none')),
        session: !!o.session,
    }
}

function ablaufAusText(text) {
    const zeit = Date.parse(text)
    return Number.isFinite(zeit) ? Math.round(zeit / 1000) : undefined
}

function cookiesParsen(text) {
    const roh = String(text || '').trim()
    if (!roh) return []
    if (roh.startsWith('[') || roh.startsWith('{')) {
        try {
            const daten = JSON.parse(roh)
            const liste = Array.isArray(daten) ? daten : (Array.isArray(daten.cookies) ? daten.cookies : Object.entries(daten).map(([name, value]) => ({ name, value })))
            return liste.map(cookieAusObjekt).filter(Boolean)
        } catch {
            // kein JSON — unten zeilenweise
        }
    }
    const cookies = []
    const zeilen = roh.split(/\r?\n/).flatMap(z => (z.includes('\t') || z.includes('=')) && !z.includes('; ') ? [z] : z.split(/;\s*/))
    for (const zeile of zeilen) {
        const z = zeile.trim()
        if (!z || z.startsWith('#')) continue
        const spalten = z.split('\t')
        if (spalten.length >= 7 && /^(TRUE|FALSE)$/i.test(spalten[1])) {
            // Netscape cookies.txt: domain, flag, path, secure, expiry, name, value
            cookies.push(cookieAusObjekt({ domain: spalten[0], path: spalten[2], secure: /^TRUE$/i.test(spalten[3]), expires: Number(spalten[4]), name: spalten[5], value: spalten[6] }))
            continue
        }
        if (spalten.length >= 2 && COOKIE_NAME.test(spalten[0].trim())) {
            // Tabelle der Entwicklerwerkzeuge: Name, Wert, Domain, Pfad, Ablauf, Größe, HttpOnly, Secure, SameSite
            cookies.push(cookieAusObjekt({
                name: spalten[0], value: spalten[1], domain: spalten[2], path: spalten[3],
                expires: ablaufAusText(spalten[4] || ''), session: /^session$/i.test(spalten[4] || ''),
                httpOnly: spalten.length > 6 ? /✓|true|yes/i.test(spalten[6]) : true, secure: spalten.length > 7 ? /✓|true|yes/i.test(spalten[7]) : true, sameSite: spalten[8],
            }))
            continue
        }
        const paar = z.match(/^([^=:\s]+)\s*[=:]\s*(.*)$/)
        if (paar && COOKIE_NAME.test(paar[1])) cookies.push(cookieAusObjekt({ name: paar[1], value: paar[2].trim().replace(/^"|"$/g, '') }))
    }
    return cookies.filter(Boolean)
}

const erkannt = computed(() => cookiesParsen(idsText.value))

async function idsUebernehmen() {
    uebernimmt.value = true
    meldung.text = ''
    const antwort = await shop.saveKleinanzeigenSession(props.channelId, erkannt.value)
    uebernimmt.value = false
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = fehlerText(shop.errorCode.value)
        return
    }
    sitzung.value = antwort
    foto.value = antwort.screenshot || ''
    if (antwort.logged_in) {
        idsText.value = ''
        idsOffen.value = false
        meldung.type = 'success'
        meldung.text = t('crm_fields.kleinanzeigenPanelUi.takeOverOk')
        laden()
    } else {
        meldung.type = 'warning'
        meldung.text = t('crm_fields.kleinanzeigenPanelUi.takeOverFail')
    }
}

async function abgleichen() {
    gleichtAb.value = true
    meldung.text = ''
    const antwort = await shop.syncKleinanzeigenAds(props.channelId)
    gleichtAb.value = false
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = fehlerText(shop.errorCode.value)
        return
    }
    meldung.type = 'success'
    meldung.text = t('crm_fields.kleinanzeigenPanelUi.syncOk', antwort)
    laden()
}

async function kategorienStand() {
    const antwort = await shop.fetchKleinanzeigenCategories()
    if (antwort) kategorien.value = { count: antwort.count || 0, mtime: antwort.mtime || null }
}

/** Liest den Baum über den Dienst neu ein — der Dialog wird komplett abgelaufen, das dauert einige Minuten */
async function kategorienLaden() {
    laedtKategorien.value = true
    meldung.text = ''
    const antwort = await shop.syncKleinanzeigenCategories(props.channelId)
    laedtKategorien.value = false
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = fehlerText(shop.errorCode.value)
        return
    }
    meldung.type = 'success'
    meldung.text = t('crm_fields.kleinanzeigenPanelUi.categories.ok', antwort)
    await kategorienStand()
    emit('categories')
}

async function feedLaden() {
    const antwort = await shop.fetchKleinanzeigenFeedUrl(props.channelId)
    if (antwort) feed.value = antwort
}

async function anfragenHolen() {
    holt.value = true
    meldung.text = ''
    const antwort = await shop.importKleinanzeigenLeads(props.channelId)
    holt.value = false
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = fehlerText(shop.errorCode.value, t('crm_fields.kleinanzeigenPanelUi.importFail'))
        return
    }
    meldung.type = 'success'
    meldung.text = t('crm_fields.kleinanzeigenPanelUi.importOk', antwort)
}

async function kopieren(text) {
    try {
        await navigator.clipboard.writeText(text)
        toasts.success(t('crm_fields.kleinanzeigenPanelUi.copied'), { timer: 1500 })
    } catch {
        // Zwischenablage nicht verfügbar — das Feld lässt sich markieren
    }
}

onMounted(() => {
    laden()
    feedLaden()
    kategorienStand()
})
</script>

<style scoped>
/* Cookie-Werte sind lang und ohne Leerzeichen — Festbreitenschrift, umbrechend */
.ids-feld :deep(textarea) { font-family: ui-monospace, monospace; font-size: 0.8rem; word-break: break-all; }
</style>
