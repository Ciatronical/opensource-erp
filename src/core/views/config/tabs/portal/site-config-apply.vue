<!--
    Hugo-Konfiguration der Webseite ergänzen lassen (shopsiteconfigapply)

    Steht unter den Befunden der Konfigurationsprüfung: ein Knopf für alle
    automatisch behebbaren Befunde (befund.apply), mit Vorschau vor dem
    Schreiben. Geschrieben wird von HugoCMS, nicht von hier — der Server
    ermittelt die Korrekturen aus einer frischen Prüfung, die Vorschau zeigt
    nur, was gemeint ist. Danach meldet der Baustein „applied“, und die
    aufrufende Karte prüft erneut.

    Kein Knopf, wenn HugoCMS den Befehl noch nicht kennt (stand.available);
    ein Hinweis, wenn er dort abgeschaltet ist oder die Webseite keine
    einzelne hugo.json/config.json hat.
-->
<template>
    <div v-if="behebbar.length && stand && stand.available" class="mt-3">
        <div v-if="!stand.allowed" class="text-caption text-medium-emphasis">
            {{ t('crm_fields.shopSiteApplyOff') }}
        </div>
        <div v-else-if="!stand.supported" class="text-caption text-medium-emphasis">
            {{ t('crm_fields.shopSiteApplyUnsupported', { file: stand.file || '—' }) }}
        </div>
        <v-btn
            v-else
            color="primary"
            variant="tonal"
            prepend-icon="mdi-file-cog-outline"
            :disabled="disabled"
            @click="offen = true"
        >
            {{ t('crm_fields.shopSiteApply', { count: behebbar.length }) }}
        </v-btn>

        <v-dialog v-model="offen" max-width="720" scrollable :persistent="laeuft">
            <v-card>
                <v-card-title>{{ t('crm_fields.shopSiteApplyTitle') }}</v-card-title>
                <v-card-text>
                    <div class="mb-3">{{ t('crm_fields.shopSiteApplyIntro', { file: stand.file }) }}</div>
                    <div v-for="(befund, nr) in behebbar" :key="nr" class="mb-3">
                        <div class="text-body-2">{{ t(`crm_fields.shopSiteCheck_${befund.code}`, befund.params || {}) }}</div>
                        <pre v-if="befund.fix" class="text-body-2 mt-1 vorschau">{{ befund.fix }}</pre>
                    </div>
                    <v-alert v-if="fehler" type="error" variant="tonal" density="compact">{{ fehler }}</v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" :disabled="laeuft" @click="offen = false">{{ t('crm_fields.shopSiteApplyCancel') }}</v-btn>
                    <v-btn color="primary" variant="flat" :loading="laeuft" @click="uebernehmen">{{ t('crm_fields.shopSiteApplyConfirm') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as toasts from '@/core/utils/toasts.js'

const props = defineProps({
    /** Befunde der Konfigurationsprüfung (site_checks) */
    befunde: { type: Array, default: null },
    /** Stand der Übernahme aus der Prüfung (site_apply): available, allowed, supported, file */
    stand: { type: Object, default: null },
    /** Endpunkt und Aktion: /api/portal/ mit applyWebsiteSiteConfig oder /api/shop/ mit applyShopSiteConfig */
    api: { type: String, required: true },
    action: { type: String, required: true },
    /** Felder der Anfrage: website_id oder channel_id, dazu url und key aus dem Formular */
    payload: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['applied'])

const { t } = useI18n()

const behebbar = computed(() => (props.befunde || []).filter(befund => befund.apply))
const offen = ref(false)
const laeuft = ref(false)
const fehler = ref('')

watch(offen, an => { if (an) fehler.value = '' })

async function uebernehmen() {
    laeuft.value = true
    fehler.value = ''
    try {
        const antwort = await axios.post(props.api, { action: props.action, ...props.payload })
        if (!antwort.data?.success) {
            fehler.value = antwort.data?.debug || antwort.data?.text || t('crm_fields.shopSiteApplyFailed')
            return
        }
        offen.value = false
        toasts.success(t('crm_fields.shopSiteApplyDone'))
        emit('applied')
    } catch (e) {
        fehler.value = e?.message || t('crm_fields.shopSiteApplyFailed')
    } finally {
        laeuft.value = false
    }
}
</script>

<style scoped>
.vorschau {
    white-space: pre-wrap;
    padding: var(--oserp-gap-sm);
    border-radius: var(--oserp-radius-sm);
    background: var(--oserp-surface-soft);
}
</style>
