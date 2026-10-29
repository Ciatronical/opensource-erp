<!-- src/features/lxcars/views/gutmann/gutmann.view.vue -->
<!--
    Hella Gutmann mega macs X als Seite in LxCars (docs/features/gutmann.md).

    Die Oberfläche des Diagnosegeräts kommt über die Apache-Weiterleitung
    /gutmann/ (install/gutmann-proxy.sh) und läuft hier in einem Rahmen unter
    der normalen Navigation. public/gutmann-bridge.js|css werden vom Proxy in
    die Seite des Geräts gehängt: Browser-Kennung, WebSocket über HTTPS und
    OSERP-Farben. Der Hell/Dunkel-Modus wird beim Laden (?oserp=…) und bei
    jedem Wechsel (postMessage) mitgegeben.

    Zugang: Apache lässt /gutmann/ nur mit dem Cookie oserp_gutmann durch; das
    setzt getGutmannAccess (nur mit OSERP-Sitzung) bei jedem Öffnen der Seite.
    Danach ein kurzer HEAD auf das Manifest des Geräts: Gerät aus
    (502/503), Apache verweigert (403, veraltete Weiterleitung) oder
    Weiterleitung fehlt (dann liefert Apache per FallbackResource die
    OSERP-Startseite als text/html) — jeweils ein Hinweis statt eines leeren
    Rahmens. Apache gibt die Geräteadresse als Header X-Gutmann-Device mit;
    daraus entsteht der Notfallknopf, der das Gerät direkt öffnet, wenn die
    Weiterleitung nicht geht. Der Rahmen nutzt die volle Fensterbreite. Fahrzeuge kommen nicht über diese Seite, sondern
    über asanetwork: offene Aufträge erscheinen im Gerät als Auftragsliste.
-->
<template>
    <NavbarView />
    <div class="gutmann">
        <page-header :title="t('GutmannView.title')" :subtitle="t('GutmannView.subtitle')" class="gutmann__header">
            <v-chip v-if="state === 'online'" size="small" color="success" variant="tonal">
                <v-icon start size="small">mdi-lan-connect</v-icon>{{ t('GutmannView.connected') }}
            </v-chip>
            <template #actions>
                <v-btn variant="tonal" size="small" :loading="state === 'checking'" @click="check">
                    <v-icon start size="small">mdi-refresh</v-icon>{{ t('GutmannView.reload') }}
                </v-btn>
                <v-btn variant="tonal" size="small" :href="frameUrl" target="_blank" rel="noopener">
                    <v-icon start size="small">mdi-open-in-new</v-icon>{{ t('GutmannView.openWindow') }}
                </v-btn>
            </template>
        </page-header>

        <v-alert v-if="state === 'offline'" type="warning" variant="tonal" class="gutmann__alert">
            <div class="font-weight-medium">{{ t('GutmannView.offline.title') }}</div>
            <div>{{ t('GutmannView.offline.text') }}</div>
        </v-alert>
        <v-alert v-else-if="state === 'denied'" type="error" variant="tonal" class="gutmann__alert">
            <div class="font-weight-medium">{{ t('GutmannView.denied.title') }}</div>
            <div>{{ t('GutmannView.denied.text') }}</div>
            <code class="gutmann__code">sudo ./install/gutmann-proxy.sh &lt;adresse:port&gt;</code>
        </v-alert>
        <v-alert v-else-if="state === 'missing'" type="error" variant="tonal" class="gutmann__alert">
            <div class="font-weight-medium">{{ t('GutmannView.missing.title') }}</div>
            <div>{{ t('GutmannView.missing.text') }}</div>
            <code class="gutmann__code">sudo ./install/gutmann-proxy.sh &lt;adresse:port&gt;</code>
        </v-alert>

        <!-- Notfall: Gerät direkt ansprechen (nur im Werkstattnetz erreichbar,
             Warnseite des Geräts außerhalb von Chrome) -->
        <div v-if="state !== 'online' && state !== 'checking' && deviceUrl" class="gutmann__emergency">
            <v-btn color="warning" variant="flat" :href="deviceUrl" target="_blank" rel="noopener">
                <v-icon start>mdi-lifebuoy</v-icon>{{ t('GutmannView.emergency.button') }}
            </v-btn>
            <div class="gutmann__muted">{{ t('GutmannView.emergency.hint', { url: deviceUrl }) }}</div>
        </div>

        <iframe
            v-if="state === 'online'"
            ref="frame"
            :src="frameUrl"
            class="gutmann__frame"
            :title="t('GutmannView.title')"
            allow="clipboard-read; clipboard-write; fullscreen"
        />
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTheme } from 'vuetify'
import axios from 'axios'
import NavbarView from '@/core/components/navbar/navbar.view.vue'
import PageHeader from '@/core/components/layout/page-header.vue'

const { t } = useI18n()
const theme = useTheme()

const frame = ref(null)
// checking | online | offline | denied | missing
const state = ref('checking')
// Direktadresse des Geräts aus dem Apache-Header (install/oserp-gutmann.conf)
const deviceUrl = ref('')

const themeName = computed(() => (theme.global.current.value.dark ? 'dark' : 'light'))
// Der Proxy-Pfad ist fest (install/oserp-gutmann.conf); der Modus geht nur beim
// ersten Laden über die URL mit, danach per postMessage ohne Neuladen.
const frameUrl = computed(() => `/gutmann/?oserp=${themeName.value}`)

async function check() {
    state.value = 'checking'
    // Zugangs-Cookie holen (12 h, HttpOnly); ohne Weiterleitung antwortet die
    // Aktion mit configured=false — der HEAD darunter liefert dann den Hinweis.
    try {
        await axios.post('/api/lxcars/', { action: 'getGutmannAccess' })
    } catch (e) {
        console.error('getGutmannAccess:', e)
    }
    const abort = new AbortController()
    const timer = setTimeout(() => abort.abort(), 6000)
    try {
        const res = await fetch('/gutmann/manifest.webmanifest', { method: 'HEAD', cache: 'no-store', signal: abort.signal })
        const type = res.headers.get('content-type') || ''
        const device = res.headers.get('x-gutmann-device') || ''
        deviceUrl.value = device ? `http://${device}/` : ''
        if (res.ok && /manifest|json/.test(type)) state.value = 'online'
        else if (res.ok || res.status === 404) state.value = 'missing'
        else if (res.status === 403) state.value = 'denied'
        else state.value = 'offline'
    } catch {
        state.value = 'offline'
    } finally {
        clearTimeout(timer)
    }
}

watch(themeName, (name) => {
    frame.value?.contentWindow?.postMessage({ type: 'oserp-theme', theme: name }, location.origin)
})

onMounted(check)
</script>

<style scoped>
.gutmann {
    display: flex;
    flex-direction: column;
    /* Rahmen füllt alles unter der Navigationsleiste, über die volle Breite */
    height: calc(100vh - 64px);
}
.gutmann__header {
    flex: 0 0 auto;
    padding: 0 var(--oserp-page-pad);
}
.gutmann__alert {
    max-width: var(--oserp-content-max);
    margin: 0 var(--oserp-page-pad);
}
.gutmann__code {
    display: block;
    margin-top: var(--oserp-gap-sm);
    font-size: 0.85em;
}
.gutmann__emergency {
    margin: var(--oserp-gap-lg) var(--oserp-page-pad);
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--oserp-gap-sm);
}
.gutmann__muted {
    color: var(--oserp-text-muted);
    font-size: 0.9em;
}
.gutmann__frame {
    flex: 1 1 auto;
    width: 100%;
    min-height: 0;
    border: 0;
    border-top: 1px solid var(--oserp-border);
    background: var(--oserp-surface);
}
</style>
