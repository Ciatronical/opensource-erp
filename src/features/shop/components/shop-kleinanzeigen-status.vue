<!-- src/features/shop/components/shop-kleinanzeigen-status.vue -->
<!--
    Stand des Kleinanzeigen-Kanals in der Kanalkarte (dev/shop-kleinanzeigen.md):
    der Kleinanzeigen-Dienst (Headless-Browser) mit Anmeldung und Abgleich,
    der Weg zum Board, Anfragen holen, Feed-Adressen für Sync-Werkzeuge.

    Anmeldung: ein Klick meldet den Browser mit dem Zugang des Kanals an;
    verlangt Kleinanzeigen einen Code, holt OSERP ihn aus dem Postfach. Nur
    bei SMS oder ohne Postfach fragt die Karte den Code ab — mehr muss der
    Benutzer nie bestätigen.
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
                <div v-else class="text-caption">{{ t('crm_fields.kleinanzeigenPanelUi.sessionUnknown', { email: status.login_email }) }}</div>
                <div v-if="status.ads_last_sync" class="text-caption">
                    {{ t('crm_fields.kleinanzeigenPanelUi.lastSync', { zeit: status.ads_last_sync, anzahl: status.ads_online_count ?? '–' }) }}
                </div>
                <div v-if="status.login_blocked_until" class="text-caption text-warning">
                    {{ t('crm_fields.kleinanzeigenPanelUi.blockedUntil', { zeit: status.login_blocked_until }) }}
                </div>
            </v-alert>

            <!-- Fernsteuerung: Bild des Browsers, Klicks und Tasten -->
            <v-card v-if="fern.an" variant="outlined" class="pa-3 mb-3">
                <div class="d-flex align-center flex-wrap ga-2 mb-2">
                    <span class="text-subtitle-2">{{ t('crm_fields.kleinanzeigenPanelUi.remote.title') }}</span>
                    <v-chip v-if="fern.loggedIn" size="x-small" color="success" variant="flat">{{ t('crm_fields.kleinanzeigenPanelUi.remote.loggedIn') }}</v-chip>
                    <v-progress-circular v-if="fern.laeuft" indeterminate size="16" width="2" />
                    <v-spacer />
                    <v-btn size="x-small" variant="text" prepend-icon="mdi-login-variant" @click="fernAktion('open')">{{ t('crm_fields.kleinanzeigenPanelUi.remote.openLogin') }}</v-btn>
                    <v-btn size="x-small" variant="text" prepend-icon="mdi-refresh" @click="fernAktion('view')">{{ t('crm_fields.kleinanzeigenPanelUi.refreshBtn') }}</v-btn>
                    <v-btn size="x-small" variant="text" prepend-icon="mdi-close" @click="fernSchliessen">{{ t('ShopView.common.close') }}</v-btn>
                </div>
                <div class="text-caption text-medium-emphasis mb-2">{{ t('crm_fields.kleinanzeigenPanelUi.remote.hint') }}</div>
                <div class="text-caption mb-1 text-truncate">{{ fern.url }}</div>
                <img
                    v-if="fern.jpeg"
                    ref="fernBild"
                    :src="'data:image/jpeg;base64,' + fern.jpeg"
                    :alt="fern.title"
                    class="rounded border fern-bild"
                    :class="{ 'fern-bild--wartet': fern.laeuft }"
                    @click="fernKlick"
                />
                <div v-else class="text-caption pa-4">{{ t('crm_fields.kleinanzeigenPanelUi.remote.noImage') }}</div>
                <!-- Zugangsdaten tippt der Dienst selbst (menschlicher Rhythmus) — ein Knopf je Schritt -->
                <div class="d-flex flex-wrap align-center ga-2 mt-2">
                    <v-btn size="small" color="primary" variant="tonal" prepend-icon="mdi-email-outline" @click="fernAktion('fill', { field: 'email', enter: 1 })">{{ t('crm_fields.kleinanzeigenPanelUi.remote.fillEmail') }}</v-btn>
                    <v-btn size="small" color="primary" variant="tonal" prepend-icon="mdi-form-textbox-password" @click="fernAktion('fill', { field: 'password', enter: 1 })">{{ t('crm_fields.kleinanzeigenPanelUi.remote.fillPassword') }}</v-btn>
                </div>
                <div class="d-flex flex-wrap align-center ga-2 mt-2">
                    <v-text-field
                        v-model="fern.text"
                        :label="t('crm_fields.kleinanzeigenPanelUi.remote.text')"
                        variant="outlined"
                        density="compact"
                        hide-details
                        style="min-width: 260px; max-width: 420px"
                        autocomplete="off"
                        @keyup.enter="fernTippen(true)"
                    />
                    <v-btn size="small" variant="tonal" prepend-icon="mdi-keyboard" :disabled="!fern.text" @click="fernTippen(false)">{{ t('crm_fields.kleinanzeigenPanelUi.remote.type') }}</v-btn>
                    <v-btn size="small" variant="tonal" :disabled="!fern.text" @click="fernTippen(true)">{{ t('crm_fields.kleinanzeigenPanelUi.remote.typeEnter') }}</v-btn>
                    <v-btn v-for="k in ['Enter', 'Tab', 'Backspace', 'Escape']" :key="k" size="small" variant="text" @click="fernAktion('key', { key: k })">{{ k }}</v-btn>
                    <v-btn size="small" variant="text" icon="mdi-arrow-up" :title="t('crm_fields.kleinanzeigenPanelUi.remote.scrollUp')" @click="fernAktion('scroll', { dy: -500 })" />
                    <v-btn size="small" variant="text" icon="mdi-arrow-down" :title="t('crm_fields.kleinanzeigenPanelUi.remote.scrollDown')" @click="fernAktion('scroll', { dy: 500 })" />
                </div>
            </v-card>

            <div class="d-flex flex-wrap ga-2 mb-3">
                <v-btn variant="outlined" prepend-icon="mdi-account-check-outline" :loading="prueft" :disabled="!status.has_login" @click="pruefen">
                    {{ t('crm_fields.kleinanzeigenPanelUi.checkSession') }}
                </v-btn>
                <v-btn color="primary" variant="flat" prepend-icon="mdi-login" :loading="meldetAn" :disabled="!status.has_login" @click="anmelden">
                    {{ t('crm_fields.kleinanzeigenPanelUi.login') }}
                </v-btn>
                <!-- Von Hand: Fernsteuerung des Dienst-Browsers hier in der Karte (erster Login, Captcha, Sperre) -->
                <v-btn variant="outlined" prepend-icon="mdi-remote-desktop" :loading="fern.laeuft && !fern.an" @click="fernOeffnen">
                    {{ t('crm_fields.kleinanzeigenPanelUi.loginManual') }}
                </v-btn>
                <v-btn variant="outlined" prepend-icon="mdi-sync" :loading="gleichtAb" :disabled="!status.has_login" @click="abgleichen">
                    {{ t('crm_fields.kleinanzeigenPanelUi.sync') }}
                </v-btn>
            </div>

            <!-- Code nachreichen (SMS, oder Postfach ohne Code) -->
            <v-card v-if="codeNoetig" variant="tonal" color="warning" class="pa-3 mb-3">
                <div class="text-body-2 mb-2">{{ t(`crm_fields.kleinanzeigenPanelUi.codeHint.${codeArt}`) }}</div>
                <div class="d-flex ga-2 align-center">
                    <v-text-field v-model="code" :label="t('crm_fields.kleinanzeigenPanelUi.code')" variant="outlined" density="compact" hide-details style="max-width: 20ch" autocomplete="one-time-code" @keyup.enter="codeSenden" />
                    <v-btn color="primary" variant="flat" :loading="sendetCode" :disabled="!code.trim()" @click="codeSenden">{{ t('crm_fields.kleinanzeigenPanelUi.codeSend') }}</v-btn>
                </div>
            </v-card>

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
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShop } from '@/features/shop/composables/useShop.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    channelId: { type: Number, required: true },
})

const { t, te } = useI18n()
const shop = useShop()

const status = ref(null)
const sitzung = ref(null)
const foto = ref('')
const code = ref('')
const codeArt = ref('email')
const codeNoetig = ref(false)
const prueft = ref(false)
const meldetAn = ref(false)
const gleichtAb = ref(false)
const sendetCode = ref(false)
const holt = ref(false)
const meldung = reactive({ type: 'info', text: '' })
const feed = ref({ url_csv: '', url_json: '' })

const sitzungTyp = computed(() => {
    if (!status.value?.has_login) return 'warning'
    if (!sitzung.value) return 'info'
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
        codeNoetig.value = antwort.session.state === 'code_required'
        codeArt.value = antwort.session.kind || 'email'
    }
}

async function pruefen() {
    prueft.value = true
    meldung.text = ''
    await laden(true)
    prueft.value = false
}

function ergebnisUebernehmen(antwort) {
    sitzung.value = antwort
    foto.value = antwort.screenshot || ''
    codeNoetig.value = antwort.state === 'code_required'
    codeArt.value = antwort.kind || 'email'
    if (antwort.state === 'ok') {
        meldung.type = 'success'
        meldung.text = antwort.code_from_mail ? t('crm_fields.kleinanzeigenPanelUi.loginOkMail') : t('crm_fields.kleinanzeigenPanelUi.loginOk')
    } else if (antwort.state === 'code_required') {
        meldung.type = 'warning'
        meldung.text = t('crm_fields.kleinanzeigenPanelUi.codeRequired')
    } else {
        meldung.type = 'error'
        meldung.text = antwort.message || t('crm_fields.kleinanzeigenPanelUi.loginFail')
    }
}

async function anmelden() {
    meldetAn.value = true
    meldung.text = ''
    const antwort = await shop.loginKleinanzeigen(props.channelId)
    meldetAn.value = false
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = fehlerText(shop.errorCode.value)
        return
    }
    ergebnisUebernehmen(antwort)
}

// ── Fernsteuerung des Dienst-Browsers ──
//
// Der Benutzer sitzt nicht am Server: das Bild des Browsers kommt als JPEG,
// Klicks werden auf die echte Größe umgerechnet, Text und Tasten gehen als
// Tastaturereignisse an die Seite. Solange die Karte offen ist, wird das
// Bild alle drei Sekunden erneuert — bis die Anmeldung erkannt ist.
const fern = reactive({ an: false, laeuft: false, jpeg: '', url: '', title: '', width: 1400, height: 1000, loggedIn: false, text: '' })
const fernBild = ref(null)
let fernTimer = null
/** Aktionen laufen nacheinander — ein Klick während der Bildaktualisierung geht nicht verloren */
let fernKette = Promise.resolve()

function fernAktion(op, extra = {}) {
    clearTimeout(fernTimer)
    const auftrag = fernKette.then(() => fernAusfuehren(op, extra))
    fernKette = auftrag.catch(() => {})
    return auftrag
}

async function fernAusfuehren(op, extra) {
    fern.laeuft = true
    const antwort = await shop.remoteKleinanzeigenBrowser(props.channelId, op, extra)
    fern.laeuft = false
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = fehlerText(shop.errorCode.value)
        if (fern.an) fernTimer = setTimeout(() => fernAktion('view'), 5000)
        return null
    }
    fern.jpeg = antwort.jpeg || ''
    fern.url = antwort.url || ''
    fern.title = antwort.title || ''
    fern.width = Number(antwort.width) || 1400
    fern.height = Number(antwort.height) || 1000
    fern.loggedIn = !!antwort.logged_in
    if (fern.loggedIn) {
        meldung.type = 'success'
        meldung.text = t('crm_fields.kleinanzeigenPanelUi.loginOk')
        await laden(true)
    } else if (fern.an) {
        fernTimer = setTimeout(() => fernAktion('view'), 4000)
    }
    return antwort
}

function fernOeffnen() {
    meldung.text = ''
    fern.an = true
    fern.jpeg = ''
    fernAktion('open')
}

function fernSchliessen() {
    fern.an = false
    clearTimeout(fernTimer)
}

/** Klick im Bild: Position auf die echte Fenstergröße umrechnen */
function fernKlick(ereignis) {
    const bild = fernBild.value
    if (!bild || !bild.clientWidth) return
    const x = Math.round(ereignis.offsetX * (fern.width / bild.clientWidth))
    const y = Math.round(ereignis.offsetY * (fern.height / bild.clientHeight))
    fernAktion('click', { x, y })
}

async function fernTippen(mitEnter) {
    const text = fern.text
    if (!text) return
    fern.text = ''
    const antwort = await fernAktion('type', { text })
    if (antwort && mitEnter && !fern.loggedIn) {
        clearTimeout(fernTimer)
        await fernAktion('key', { key: 'Enter' })
    }
}

onBeforeUnmount(() => { fern.an = false; clearTimeout(fernTimer) })

async function codeSenden() {
    sendetCode.value = true
    const antwort = await shop.confirmKleinanzeigenLogin(props.channelId, code.value)
    sendetCode.value = false
    if (!antwort) {
        meldung.type = 'error'
        meldung.text = fehlerText(shop.errorCode.value)
        return
    }
    code.value = ''
    ergebnisUebernehmen(antwort)
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
})
</script>

<style scoped>
.fern-bild { width: 100%; max-width: 720px; cursor: crosshair; display: block; }
.fern-bild--wartet { opacity: 0.6; }
</style>
