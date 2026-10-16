<!-- src/features/lxcars/components/scan-inbox-status.component.vue -->
<!--
    Statusblock „Eingangsverzeichnis für Fahrzeugscheine“ (Firmenkonfiguration → LxCars).

    Pflegt das überwachte Verzeichnis (vorbelegt mit dem Standardpfad) und zeigt
    den Zustand des Dienstes oserp-fahrzeugschein-eingang (Herzschlag im
    Verzeichnis), wartende Dateien, letzte Verarbeitung und die jüngsten Fehler mit
    Begründung. Der Status folgt dem Feld, auch bevor es gespeichert ist — so sieht
    man sofort, ob ein eigener Pfad passt.
-->
<template>
    <section-card :title="t('scanInbox.title')" icon="mdi-folder-download-outline" quiet>
        <template #actions>
            <v-btn size="small" variant="tonal" prepend-icon="mdi-refresh" :loading="loading" @click="load">
                {{ t('scanInbox.refresh') }}
            </v-btn>
        </template>

        <p class="scan-inbox__text">{{ t('scanInbox.intro') }}</p>

        <v-alert v-if="error" type="error" variant="tonal" density="compact">{{ error }}</v-alert>

        <template v-else-if="status">
            <div class="scan-inbox__path">
                <v-text-field
                    v-model="werte.lxcars_scan_inbox_dir"
                    :label="t('scanInbox.dirLabel')"
                    :placeholder="status.default_dir"
                    persistent-placeholder
                    class="scan-inbox__field"
                >
                    <template #append-inner>
                        <v-tooltip location="top">
                            <template #activator="{ props: tip }">
                                <v-icon v-bind="tip" size="small" color="grey">mdi-information-outline</v-icon>
                            </template>
                            {{ t('scanInbox.dirHelp', { default: status.default_dir }) }}
                        </v-tooltip>
                    </template>
                </v-text-field>
                <v-btn icon="mdi-content-copy" size="small" variant="text" :title="t('scanInbox.copyPath')" @click="copyPath" />
                <v-btn v-if="status.custom" size="small" variant="text" @click="werte.lxcars_scan_inbox_dir = status.default_dir">
                    {{ t('scanInbox.useDefault') }}
                </v-btn>
                <v-chip v-else size="x-small" variant="tonal">{{ t('scanInbox.defaultPath') }}</v-chip>
            </div>

            <div class="scan-inbox__chips">
                <v-chip size="small" variant="tonal" :color="status.service.alive ? 'success' : 'error'"
                        :prepend-icon="status.service.alive ? 'mdi-check-circle' : 'mdi-alert-circle'">
                    {{ status.service.alive ? t('scanInbox.serviceAlive', { method: status.service.method }) : t('scanInbox.serviceDown') }}
                </v-chip>
                <v-chip size="small" variant="tonal" :color="status.writable ? 'success' : 'warning'"
                        :prepend-icon="status.writable ? 'mdi-folder-check-outline' : 'mdi-folder-alert-outline'">
                    {{ !status.exists ? t('scanInbox.dirMissing') : (status.writable ? t('scanInbox.dirWritable') : t('scanInbox.dirReadonly')) }}
                </v-chip>
            </div>
            <p v-if="!status.service.alive" class="scan-inbox__text scan-inbox__text--warn">{{ t('scanInbox.serviceHint') }}</p>

            <div class="scan-inbox__counts">
                <div class="scan-inbox__count">
                    <div class="scan-inbox__num">{{ status.pending }}</div>
                    <div class="scan-inbox__label">{{ t('scanInbox.pending') }}</div>
                </div>
                <div class="scan-inbox__count">
                    <div class="scan-inbox__num">{{ status.done_count }}</div>
                    <div class="scan-inbox__label">{{ t('scanInbox.done', { days: status.keep_days }) }}</div>
                </div>
                <div class="scan-inbox__count">
                    <div class="scan-inbox__num">{{ status.failed.length }}</div>
                    <div class="scan-inbox__label">{{ t('scanInbox.failed') }}</div>
                </div>
            </div>

            <div v-if="status.last" class="scan-inbox__last">
                <v-icon size="small" :color="status.last.ok ? 'success' : 'error'">
                    {{ status.last.ok ? 'mdi-check' : 'mdi-close' }}
                </v-icon>
                <span>
                    {{ t('scanInbox.last') }}: <strong>{{ status.last.file }}</strong>
                    · {{ formatDateTime(status.last.time, locale) }}
                    · {{ status.last.ok ? (status.last.plate || t('scanInbox.noPlate')) : status.last.message }}
                </span>
            </div>

            <v-list v-if="status.failed.length" density="compact" class="scan-inbox__errors">
                <v-list-item v-for="f in status.failed" :key="f.file + f.time"
                             prepend-icon="mdi-file-alert-outline" :title="f.file"
                             :subtitle="formatDateTime(f.time, locale) + ' · ' + f.message" />
            </v-list>

            <p class="scan-inbox__text scan-inbox__text--muted">
                {{ t('scanInbox.hint', { types: status.extensions.join(', ') }) }}
            </p>
        </template>
    </section-card>
</template>

<script setup>
import { ref, watch, inject, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import SectionCard from '@/core/components/layout/section-card.vue'
import { lxcarsStore } from '@/features/lxcars/stores/lxcars.store.js'
import { formatDateTime } from '@/core/utils/dateFormatter.js'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    /** Einstellungen des Mandanten (defaults_oserp); das Pfadfeld lxcars_scan_inbox_dir wird hier gepflegt */
    werte: { type: Object, required: true },
})

// Vorbelegung des Pfades, ohne dass die Firmenkonfiguration daraus eine Nutzeränderung macht
const ohneSpeichern = inject('ohneSpeichern', fn => fn())

const { t, locale } = useI18n()
const store = lxcarsStore()

const status = ref(null)
const loading = ref(false)
const error = ref('')

async function load() {
    loading.value = true
    error.value = ''
    try {
        status.value = await store.getScanInboxStatus(props.werte.lxcars_scan_inbox_dir || '')
        // Leeres Feld → Standardpfad eintragen, damit sichtbar ist, was überwacht wird
        if (!props.werte.lxcars_scan_inbox_dir && status.value.default_dir) {
            ohneSpeichern(() => { props.werte.lxcars_scan_inbox_dir = status.value.default_dir })
        }
    } catch (e) {
        error.value = t('scanInbox.loadFailed') + (e?.message ? ': ' + e.message : '')
    } finally {
        loading.value = false
    }
}

async function copyPath() {
    try {
        await navigator.clipboard.writeText(status.value.dir)
        toasts.success(t('scanInbox.copied'))
    } catch { /* Zwischenablage nicht verfügbar (http ohne TLS) — Pfad bleibt lesbar */ }
}

// Pfadfeld geändert → Status für den neuen Pfad zeigen, bevor gespeichert wird
let timer = null
watch(() => props.werte.lxcars_scan_inbox_dir, (neu, alt) => {
    if ((neu || '') === (alt || '')) return
    clearTimeout(timer)
    timer = setTimeout(load, 600)
})

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped>
.scan-inbox__text {
    margin: 0 0 var(--oserp-gap) 0;
}
.scan-inbox__text--muted {
    color: var(--oserp-text-muted);
    margin-bottom: 0;
}
.scan-inbox__text--warn {
    color: rgb(var(--v-theme-error));
}
.scan-inbox__path {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--oserp-gap-xs);
    margin-bottom: var(--oserp-gap-sm);
}
.scan-inbox__field {
    flex: 1 1 36ch;
    max-width: 60ch;
}
.scan-inbox__field :deep(input) {
    font-family: monospace;
}
.scan-inbox__chips {
    display: flex;
    flex-wrap: wrap;
    gap: var(--oserp-gap-xs);
    margin-bottom: var(--oserp-gap);
}
.scan-inbox__counts {
    display: flex;
    gap: var(--oserp-gap-lg);
    margin-bottom: var(--oserp-gap);
}
.scan-inbox__num {
    font-size: var(--oserp-title-size);
    font-weight: var(--oserp-title-weight);
    line-height: 1.1;
}
.scan-inbox__label {
    color: var(--oserp-text-muted);
    font-size: 0.8em;
}
.scan-inbox__last {
    display: flex;
    align-items: flex-start;
    gap: var(--oserp-gap-xs);
    margin-bottom: var(--oserp-gap);
}
.scan-inbox__errors {
    margin-bottom: var(--oserp-gap);
    background: transparent;
}
/* Begründung vollständig zeigen, nicht mit … abschneiden */
.scan-inbox__errors :deep(.v-list-item-subtitle) {
    white-space: normal;
    -webkit-line-clamp: unset;
}
</style>
