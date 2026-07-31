<!-- src/core/views/config/tabs/ai-health.tab.vue -->
<!--
    Config-Tab "KI und Gesundheit"

    Bündelt alles rund um Prozesse & KI: Spracheingabe (lokaler Whisper-Dienst
    inkl. Fachbegriffe-Glossar), lokale KI (Ollama) und die Gewichtung der
    KI-Positionsvorschläge. "Gesundheit" im Sinne von ergonomischem, tippen-armem
    Arbeiten — die Spracheingabe entlastet Kollegen, die schlecht tippen können.

    Alle Werte landen als key/value in defaults_oserp (Prop `crmDefaults`), das
    der Parent per Deep-Watcher automatisch speichert.
-->
<template>
    <div>
        <!-- ============ Spracheingabe (Whisper) ============ -->
        <h3 class="text-h6 mb-1">{{ t('aiHealth.voiceTitle') }}</h3>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('aiHealth.voiceIntro') }}</p>

        <v-row>
            <v-col cols="12" md="8">
                <v-text-field
                    v-model="crmDefaults.whisper_url"
                    :label="t('aiHealth.whisperUrl')"
                    :hint="t('aiHealth.whisperUrl_help')"
                    persistent-hint
                    placeholder="http://127.0.0.1:3002"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
            <v-col cols="12" md="4">
                <v-text-field
                    v-model="crmDefaults.whisper_token"
                    :label="t('aiHealth.whisperToken')"
                    :hint="t('aiHealth.whisperToken_help')"
                    persistent-hint
                    type="password"
                    autocomplete="new-password"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
        </v-row>

        <!-- Fachbegriffe-Glossar -->
        <v-divider class="my-4" />
        <h3 class="text-subtitle-1 font-weight-medium mb-1">{{ t('aiHealth.glossaryTitle') }}</h3>
        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('aiHealth.glossaryIntro') }}</p>

        <div class="d-flex align-center ga-3 mb-3 flex-wrap">
            <v-btn
                color="primary"
                variant="tonal"
                prepend-icon="mdi-school-outline"
                :loading="learning"
                @click="learnTerms"
            >
                {{ t('aiHealth.learnButton') }}
            </v-btn>
            <span v-if="learnInfo" class="text-body-2 text-success">{{ learnInfo }}</span>
        </div>

        <v-textarea
            v-model="crmDefaults.whisper_glossary"
            :label="t('aiHealth.glossary')"
            :hint="t('aiHealth.glossary_help')"
            persistent-hint
            rows="4"
            auto-grow
            variant="outlined"
            density="compact"
        />

        <!-- ============ Lokale KI (Ollama) ============ -->
        <v-divider class="my-6" />
        <h3 class="text-h6 mb-1">{{ t('crm_fields.localAi') }}</h3>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('aiHealth.localAiIntro') }}</p>

        <v-row>
            <v-col cols="12" md="8">
                <v-text-field
                    v-model="crmDefaults.llm_url"
                    :label="t('crm_fields.llmUrl')"
                    :hint="t('crm_fields.llmUrl_help')"
                    persistent-hint
                    placeholder="http://127.0.0.1:11434"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
            <v-col cols="12" md="4">
                <v-text-field
                    v-model="crmDefaults.llm_model"
                    :label="t('crm_fields.llmModel')"
                    :hint="t('crm_fields.llmModel_help')"
                    persistent-hint
                    variant="outlined"
                    density="compact"
                />
            </v-col>
        </v-row>

        <!-- ============ KI-Positionsvorschläge ============ -->
        <v-divider class="my-6" />
        <h3 class="text-h6 mb-1">{{ t('aiHealth.weightsTitle') }}</h3>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('aiHealth.weightsIntro') }}</p>

        <v-row>
            <v-col cols="12" md="4">
                <v-text-field
                    v-model="crmDefaults.ai_gewicht_rechnungen"
                    :label="t('crm_fields.aiGewichtRechnungen')"
                    :hint="t('crm_fields.aiGewichtRechnungen_help')"
                    persistent-hint
                    type="number"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
            <v-col cols="12" md="4">
                <v-text-field
                    v-model="crmDefaults.ai_gewicht_auftraege"
                    :label="t('crm_fields.aiGewichtAuftraege')"
                    :hint="t('crm_fields.aiGewichtAuftraege_help')"
                    persistent-hint
                    type="number"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
            <v-col cols="12" md="4">
                <v-text-field
                    v-model="crmDefaults.ai_gewicht_angebote"
                    :label="t('crm_fields.aiGewichtAngebote')"
                    :hint="t('crm_fields.aiGewichtAngebote_help')"
                    persistent-hint
                    type="number"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
        </v-row>

        <!-- ============ Cloud-KI-Schlüssel ============ -->
        <v-divider class="my-6" />
        <h3 class="text-h6 mb-1">{{ t('crm_fields.ai') }}</h3>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ t('aiHealth.cloudKeysIntro') }}</p>

        <v-row>
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="crmDefaults.openai_api_key"
                    :label="t('crm_fields.openaiApiKey')"
                    :hint="t('crm_fields.openaiApiKey_help')"
                    persistent-hint
                    type="password"
                    autocomplete="new-password"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
            <v-col cols="12" md="6">
                <v-text-field
                    v-model="crmDefaults.anthropic_api_key"
                    :label="t('crm_fields.anthropicApiKey')"
                    :hint="t('crm_fields.anthropicApiKey_help')"
                    persistent-hint
                    type="password"
                    autocomplete="new-password"
                    variant="outlined"
                    density="compact"
                />
            </v-col>
            <v-col cols="12">
                <v-btn
                    variant="text"
                    color="primary"
                    size="small"
                    prepend-icon="mdi-credit-card-outline"
                    href="https://console.anthropic.com/settings/billing"
                    target="_blank"
                    rel="noopener"
                >
                    {{ t('crm_fields.anthropicBilling') }}
                </v-btn>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import * as toasts from '@/core/utils/toasts.js'

const { t } = useI18n()

// crmDefaults = defaults_oserp (key/value), zwei-Wege-gebunden; Parent speichert
// per Deep-Watcher automatisch.
const props = defineProps({
    crmDefaults: {
        type: Object,
        required: true
    }
})

const learning = ref(false)
const learnInfo = ref('')

async function learnTerms() {
    learning.value = true
    learnInfo.value = ''
    try {
        const { data } = await axios.post('/api/voice/', { action: 'learnWhisperTerms' })
        if (data?.success && data?.payload) {
            // Ergebnis ins Feld übernehmen — der Deep-Watcher des Parents speichert.
            props.crmDefaults.whisper_glossary = data.payload.glossary || ''
            learnInfo.value = t('aiHealth.learnDone', {
                count: data.payload.term_count || 0,
                articles: data.payload.from_articles || 0,
                instructions: data.payload.from_instructions || 0
            })
            toasts.success(t('aiHealth.learnDone', {
                count: data.payload.term_count || 0,
                articles: data.payload.from_articles || 0,
                instructions: data.payload.from_instructions || 0
            }))
        } else {
            toasts.error(data?.text || t('aiHealth.learnFailed'))
        }
    } catch (e) {
        toasts.error(t('aiHealth.learnFailed'))
    } finally {
        learning.value = false
    }
}
</script>
