<template>
    <v-dialog v-model="dialog" max-width="800" @keydown.esc="close" @click:outside="close">
        <v-card :title="t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.title')" :loading="loading">
            <v-card-text>
                <p class="d-block mb-4">{{ t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.texts.select_template_and_click_send') }}</p>

                <v-autocomplete v-model="selectedTemplate" :label="t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.fields.select_template')" :disabled="loading" :items="templates" item-title="name" item-value="id"></v-autocomplete>

                <v-alert v-if="selectedTemplate && data.ids.length > 1" type="info" class="mt-4">
                    {{ t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.texts.hint_prefix') }} {{ data.ids.length }} {{ typeLabel }} {{ t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.texts.hint_suffix') }}
                    <br/>
                    <br/>
                    {{ t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.texts.hint_cannot_cancel') }}
                </v-alert>
            </v-card-text>

            <v-card-actions>
                <v-spacer></v-spacer>

                <v-btn variant="text" prepend-icon="mdi-close" @click="close">{{ t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.buttons.cancel') }}</v-btn>
                <v-btn variant="elevated" color="primary" prepend-icon="mdi-email-fast" @click="submit" :disabled="!selectedTemplate">{{ submitBtnText }}</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    data: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['submit', 'close']);

const dialog = ref(true);
const selectedTemplate = ref(null);
const loading = ref(false);
const templates = ref([]);

const typeLabel = computed(() => {
    switch (props.data.type) {
        case 'customer': return t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.labels.customer');
        case 'vendor': return t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.labels.vendor');
        case 'contacts': return t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.labels.person');
    }
});

const submitBtnText = computed(() => {
    return props.data.ids.length > 1 
        ? t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.buttons.mails_to') + ' ' + props.data.ids.length + ' ' + typeLabel.value + ' ' + t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.buttons.send') 
        : t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.buttons.mail_to') + ' ' + typeLabel.value + ' ' + t('CustomerVendorSearchView.dialogs.brevo_marketing_mail.buttons.send');
});

const loadBrevoTemplates = async () => {
    loading.value = true;
    
    const response = await axios.post('/api/brevo/', {action: 'listTemplates',});
    if (response.data.success) {
        templates.value = response.data.payload.templates ?? [];
    } else {
        templates.value = [];
    }

    loading.value = false;
};

const getSelectedTemplate = () => {
    return templates.value.find(template => template.id === selectedTemplate.value);
};

const submit = () => {
    dialog.value = false;
    emit('submit', {
        template: getSelectedTemplate(),
        type: props.data.type,
        ids: props.data.ids
    });
};

const close = () => {
    dialog.value = false;
    emit('close');
};

onMounted(() => {
    loadBrevoTemplates();
});
</script>