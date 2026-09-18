<!-- src/core/components/ai-model-button.vue -->
<!--
    Wiederverwendbare Modellwahl für einen KI-Assistenten.

    Steht am Prompt und schaltet das Modell nur für die Sitzung des Benutzers
    um — die dauerhafte Einstellung je Mandant steht in den Firmeneinstellungen
    unter "KI und Gesundheit". Die Wahl liegt im ai-model-Store und reist als
    Parameter `ai_model` mit dem Aufruf mit.

    Beispiel:
        <AiModelButton assistant="car_chat" />

    Der aufrufende Kontext schickt den Wert selbst mit:
        const aiModels = aiModelStore()
        axios.post('/api/lxcars/', {
            action: 'sendCarChatMessage',
            ai_model: aiModels.requestModel('car_chat'),
            ...
        })
-->
<template>
    <v-menu location="bottom end" :close-on-content-click="false" v-model="open">
        <template #activator="{ props: menuProps }">
            <v-tooltip location="top" :text="t('aiModels.tooltip')">
                <template #activator="{ props: tipProps }">
                    <v-btn
                        v-bind="{ ...menuProps, ...tipProps }"
                        :size="size"
                        :color="overridden ? 'primary' : 'grey-darken-1'"
                        variant="text"
                        class="text-none px-2"
                    >
                        <v-icon size="small" start>mdi-brain</v-icon>
                        <span class="text-caption">{{ currentLabel }}</span>
                    </v-btn>
                </template>
            </v-tooltip>
        </template>

        <v-list density="compact" min-width="280">
            <v-list-subheader>{{ t('aiModels.selectTitle') }}</v-list-subheader>

            <v-list-item
                v-for="model in models"
                :key="model.value"
                :active="model.value === current"
                @click="choose(model.value)"
            >
                <v-list-item-title>{{ model.label }}</v-list-item-title>
                <v-list-item-subtitle>{{ t(model.hintKey) }}</v-list-item-subtitle>
                <template #append>
                    <v-icon
                        v-if="model.value === clientModel"
                        size="x-small"
                        color="grey"
                        :title="t('aiModels.clientDefault')"
                    >
                        mdi-domain
                    </v-icon>
                </template>
            </v-list-item>

            <template v-if="overridden">
                <v-divider />
                <v-list-item @click="reset">
                    <template #prepend>
                        <v-icon size="small">mdi-restore</v-icon>
                    </template>
                    <v-list-item-title>{{ t('aiModels.useClientDefault') }}</v-list-item-title>
                </v-list-item>
            </template>

            <v-divider />
            <div class="px-4 py-2 text-caption text-medium-emphasis">
                {{ t('aiModels.sessionHint') }}
            </div>
        </v-list>
    </v-menu>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { aiModelStore } from '@/core/stores/ai-model.store.js';
import { aiModelsFor, aiModelLabel } from '@/core/constants/aiModels.js';

const { t } = useI18n();

const props = defineProps({
    /** Kennung des Assistenten aus AI_ASSISTANTS */
    assistant: {
        type: String,
        required: true,
    },
    size: {
        type: String,
        default: 'small',
    },
});

const store = aiModelStore();
const open = ref(false);

/** Modelle, die dieser Assistent verträgt */
const models = computed(() => aiModelsFor(props.assistant));

/** Aktuell gültiges Modell — Wahl der Sitzung oder Mandanteneinstellung */
const current = computed(() => store.model(props.assistant));

/** Vom Mandanten eingestelltes Modell */
const clientModel = computed(() => store.clientModel(props.assistant));

/** Weicht die Sitzung von der Mandanteneinstellung ab? */
const overridden = computed(() => store.isOverridden(props.assistant));

/**
 * Beschriftung des Buttons; ohne jede Einstellung gilt die Vorgabe des Backends
 */
const currentLabel = computed(() => (current.value ? aiModelLabel(current.value) : t('aiModels.serverDefault')));

/**
 * Übernimmt ein Modell für die Sitzung
 */
function choose(value) {
    store.setModel(props.assistant, value);
    open.value = false;
}

/**
 * Stellt die Mandanteneinstellung wieder her
 */
function reset() {
    store.resetModel(props.assistant);
    open.value = false;
}
</script>
