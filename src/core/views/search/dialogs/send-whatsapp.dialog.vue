<!-- src/core/views/search/dialogs/send-whatsapp.dialog.vue -->
<!--
    WhatsApp-Versand eines PDF mit genehmigter Vorlage (Meta Business API).
    Lädt die Vorlagen selbst, wählt die Faktura-Vorlage aus der Config vor,
    belegt die Platzhalter {{1}} Anrede, {{2}} Beleg, {{3}} Betrag und zeigt
    die fertige Nachricht als Vorschau. Handynummern stehen in der Auswahl vorn.
-->
<template>
    <v-dialog
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
        max-width="600"
        persistent
    >
        <v-card>
            <v-card-title class="d-flex align-center bg-green-darken-1">
                <v-icon class="mr-2">mdi-whatsapp</v-icon>
                {{ t('SearchView.send.whatsapp_title') }}
                <v-spacer />
                <v-btn icon="mdi-close" variant="text" density="compact" size="x-small" @click="onCancel" />
            </v-card-title>

            <v-card-text class="pt-4">
                <v-row dense>
                    <v-col cols="12">
                        <v-combobox
                            v-model="phone"
                            :items="phoneOptions"
                            item-title="title"
                            item-value="value"
                            :return-object="false"
                            :label="t('SearchView.send.phone')"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-cellphone"
                            :hint="phoneOptions.length === 0 ? t('SearchView.send.no_phone') : ''"
                            persistent-hint
                        />
                    </v-col>

                    <v-col v-if="templates.length > 1" cols="12">
                        <v-select
                            v-model="selectedTemplate"
                            :items="templates"
                            item-title="display_name"
                            return-object
                            :label="t('SearchView.send.template')"
                            variant="outlined"
                            density="compact"
                            prepend-inner-icon="mdi-text-box-outline"
                        />
                    </v-col>

                    <v-col v-if="!templatesLoading && templates.length === 0" cols="12">
                        <v-alert type="warning" variant="tonal" density="compact">
                            {{ t('SearchView.send.no_templates') }}
                        </v-alert>
                    </v-col>

                    <v-col v-if="selectedTemplate" cols="12">
                        <v-text-field
                            v-for="(param, index) in templateParams"
                            :key="index"
                            v-model="param.value"
                            :label="param.label"
                            variant="outlined"
                            density="compact"
                            class="mb-1"
                        />
                    </v-col>

                    <v-col v-if="selectedTemplate" cols="12">
                        <v-alert type="info" variant="tonal" density="compact" :title="t('SearchView.send.preview')">
                            <div class="text-body-2" style="white-space: pre-wrap;">{{ renderedPreview }}</div>
                        </v-alert>
                    </v-col>

                    <v-col cols="12">
                        <v-chip color="green" variant="tonal" prepend-icon="mdi-paperclip">
                            {{ attachmentName }}
                        </v-chip>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="onCancel">
                    {{ t('SearchView.send.cancel') }}
                </v-btn>
                <v-btn
                    color="green-darken-1"
                    variant="elevated"
                    prepend-icon="mdi-whatsapp"
                    :disabled="!isValid"
                    :loading="sending || templatesLoading"
                    @click="onSend"
                >
                    {{ t('SearchView.send.send') }}
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';
import { oserpStore } from '@/core/stores/oserp.store.js';

const { t } = useI18n();
const oserp = oserpStore();

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    /** Hauptnummer des Kunden */
    initialPhone: { type: String, default: '' },
    /** Weitere Nummern: Strings oder {number, label} */
    phoneList: { type: Array, default: () => [] },
    /** Dateiname des Anhangs (Anzeige) */
    attachmentName: { type: String, default: '' },
    /** Vorbelegung {{1}}: Anrede + Name */
    salutation: { type: String, default: '' },
    /** Vorbelegung {{2}}: z. B. "Ihre Rechnung Nr. 123" */
    docRef: { type: String, default: '' },
    /** Vorbelegung {{3}}: formatierter Betrag */
    amount: { type: String, default: '' },
    /** Versand läuft (vom Aufrufer gesteuert) */
    sending: { type: Boolean, default: false }
});

const emit = defineEmits(['update:modelValue', 'send', 'cancel']);

const phone = ref('');
const templates = ref([]);
const templatesLoading = ref(false);
const selectedTemplate = ref(null);
const templateParams = ref([]);

/**
 * Handynummern erkennen, damit sie in der Auswahl vorn stehen (kein WhatsApp auf Festnetz)
 */
const isMobile = (num) => {
    const n = (num || '').replace(/[\s\-().]/g, '');
    return /^(\+4915|\+4916|\+4917|\+436|\+417|015|016|017|06[^4]|07[^2])/.test(n);
};

const phoneOptions = computed(() => {
    const all = [];
    const add = (num, label) => {
        if (!num || all.find(a => a.value === num)) return;
        all.push({ title: label ? `${num} (${label})` : num, value: num, mobile: isMobile(num) });
    };
    add(props.initialPhone, '');
    for (const entry of props.phoneList) {
        if (typeof entry === 'string') add(entry, '');
        else add(entry?.number, entry?.label);
    }
    all.sort((a, b) => (b.mobile ? 1 : 0) - (a.mobile ? 1 : 0));
    return all;
});

const isValid = computed(() => !!(phone.value && String(phone.value).trim()) && !!selectedTemplate.value);

const renderedPreview = computed(() => {
    if (!selectedTemplate.value?.body_text) return '';
    let body = selectedTemplate.value.body_text;
    for (const param of templateParams.value) {
        body = body.replace(param.placeholder, param.value || param.placeholder);
    }
    return body;
});

/**
 * Genehmigte Vorlagen laden, Faktura-Vorlage aus der Config vorwählen
 */
async function loadTemplates() {
    templatesLoading.value = true;
    try {
        const response = await axios.post('/api/whatsapp/', { action: 'getWhatsAppTemplates' });
        const list = response.data.success ? (response.data.payload?.templates || []) : [];
        templates.value = list;
        const configTplId = Number(oserp.session?.company_config?.defaults_oserp?.whatsapp_tpl_faktura || 0);
        const matched = configTplId > 0 ? list.find(tpl => tpl.id === configTplId) : null;
        selectedTemplate.value = matched || list.find(tpl => tpl.template_type === 'document') || list[0] || null;
    } catch (e) {
        console.error('Fehler beim Laden der WhatsApp-Templates:', e);
        templates.value = [];
        selectedTemplate.value = null;
    } finally {
        templatesLoading.value = false;
    }
}

/**
 * Platzhalter der Vorlage ermitteln und bekannte Parameter vorbelegen
 */
function initTemplateParams(template) {
    if (!template?.body_text) {
        templateParams.value = [];
        return;
    }
    const placeholders = [...new Set(template.body_text.match(/\{\{\d+\}\}/g) || [])];
    const autoFill = { '{{1}}': props.salutation, '{{2}}': props.docRef, '{{3}}': props.amount };
    const labels = {
        '{{1}}': t('SearchView.send.param_salutation'),
        '{{2}}': t('SearchView.send.param_doc_ref'),
        '{{3}}': t('SearchView.send.param_amount')
    };
    templateParams.value = placeholders.map(ph => ({
        placeholder: ph,
        label: labels[ph] || `${t('SearchView.send.param_generic')} ${ph}`,
        value: autoFill[ph] || ''
    }));
}

watch(selectedTemplate, initTemplateParams);

watch(() => props.modelValue, (open) => {
    if (!open) return;
    const firstMobile = phoneOptions.value.find(p => p.mobile);
    phone.value = firstMobile?.value || phoneOptions.value[0]?.value || props.initialPhone || '';
    selectedTemplate.value = null;
    templateParams.value = [];
    loadTemplates();
});

function onSend() {
    if (!isValid.value || props.sending) return;
    emit('send', {
        phone: String(phone.value).trim(),
        templateId: selectedTemplate.value.id,
        parameters: templateParams.value.map(p => p.value)
    });
}

function onCancel() {
    emit('cancel');
    emit('update:modelValue', false);
}
</script>
