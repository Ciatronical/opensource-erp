<!-- src/core/views/admin/components/password-field.component.vue -->
<!--
    Passwortfeld mit Anzeigen/Verbergen, Generator, Kopieren und Stärkeanzeige.
    v-model = Passwort im Klartext (wird nur zum Backend geschickt, nie gespeichert).
-->
<template>
    <div>
        <v-text-field
            :model-value="modelValue"
            :label="label"
            :type="visible ? 'text' : 'password'"
            :autocomplete="autocomplete"
            :hint="hint"
            :persistent-hint="!!hint"
            :error-messages="errorMessages"
            :rules="rules"
            variant="outlined"
            density="comfortable"
            prepend-inner-icon="mdi-lock-outline"
            @update:model-value="$emit('update:modelValue', $event)"
        >
            <template #append-inner>
                <v-tooltip :text="visible ? t('AdminView.common.hidePassword') : t('AdminView.common.showPassword')" location="top">
                    <template #activator="{ props: tip }">
                        <v-icon v-bind="tip" class="cursor-pointer" @click="visible = !visible">
                            {{ visible ? 'mdi-eye-off-outline' : 'mdi-eye-outline' }}
                        </v-icon>
                    </template>
                </v-tooltip>
                <v-tooltip v-if="generator" :text="t('AdminView.common.generatePassword')" location="top">
                    <template #activator="{ props: tip }">
                        <v-icon v-bind="tip" class="cursor-pointer ms-2" @click="generate">mdi-dice-multiple-outline</v-icon>
                    </template>
                </v-tooltip>
                <v-tooltip v-if="generator && modelValue" :text="t('AdminView.common.copyPassword')" location="top">
                    <template #activator="{ props: tip }">
                        <v-icon v-bind="tip" class="cursor-pointer ms-2" @click="copy">mdi-content-copy</v-icon>
                    </template>
                </v-tooltip>
            </template>
        </v-text-field>

        <!-- Stärkeanzeige: nur wenn etwas eingegeben wurde -->
        <div v-if="strengthMeter && modelValue" class="d-flex align-center ga-2 mt-n2 mb-2 px-1">
            <v-progress-linear
                :model-value="strength * 25"
                :color="strengthColor"
                height="6"
                rounded
                class="flex-grow-1"
            />
            <span class="text-caption text-medium-emphasis" style="min-width: 5em">{{ strengthLabel }}</span>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { generatePassword, passwordStrength } from '../adminHelpers.js';
import * as toasts from '@/core/utils/toasts.js';

const { t } = useI18n();

const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    errorMessages: { type: [String, Array], default: () => [] },
    rules: { type: Array, default: () => [] },
    generator: { type: Boolean, default: true },
    strengthMeter: { type: Boolean, default: true },
    autocomplete: { type: String, default: 'new-password' }
});
const emit = defineEmits(['update:modelValue', 'generated']);

const visible = ref(false);

const strength = computed(() => passwordStrength(props.modelValue));
const strengthColor = computed(() => ['grey', 'error', 'warning', 'success', 'success'][strength.value]);
const strengthLabel = computed(() => {
    const keys = ['weak', 'weak', 'fair', 'good', 'strong'];
    return t('AdminView.userDialog.passwordStrength.' + keys[strength.value]);
});

function generate() {
    const pw = generatePassword(14);
    visible.value = true; // erzeugtes Passwort muss man sehen, um es weiterzugeben
    emit('update:modelValue', pw);
    emit('generated', pw);
}

async function copy() {
    try {
        await navigator.clipboard.writeText(props.modelValue);
        toasts.success(t('AdminView.common.copied'));
    } catch (e) {
        // ohne Clipboard-Zugriff (http) bleibt nur das sichtbare Feld
        visible.value = true;
    }
}
</script>
