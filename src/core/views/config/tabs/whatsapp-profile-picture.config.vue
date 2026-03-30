<!-- src/core/views/config/tabs/whatsapp-profile-picture.config.vue -->
<template>
    <v-card variant="outlined" class="pa-4" style="max-width: 500px">
        <div class="d-flex align-center mb-4">
            <v-avatar size="80" color="grey-lighten-3" class="mr-4">
                <v-img
                    v-if="currentPictureUrl"
                    :src="currentPictureUrl"
                    cover
                />
                <v-icon v-else size="40" color="grey">mdi-whatsapp</v-icon>
            </v-avatar>
            <div>
                <div class="text-subtitle-1 font-weight-medium">
                    {{ t('crm_fields.whatsappProfilePicture.title') }}
                </div>
                <div class="text-caption text-grey">
                    {{ t('crm_fields.whatsappProfilePicture.requirements') }}
                </div>
            </div>
        </div>

        <v-file-input
            v-model="selectedFile"
            :label="t('crm_fields.whatsappProfilePicture.selectFile')"
            accept="image/jpeg,image/png"
            prepend-icon="mdi-camera"
            variant="outlined"
            density="compact"
            :error-messages="errorMessage"
            @update:model-value="onFileSelected"
        />

        <!-- Vorschau -->
        <div v-if="previewUrl" class="text-center mb-3">
            <v-avatar size="120" class="elevation-2">
                <v-img :src="previewUrl" cover />
            </v-avatar>
            <div v-if="imageDimensions" class="text-caption text-grey mt-1">
                {{ imageDimensions.width }}x{{ imageDimensions.height }} px
            </div>
        </div>

        <!-- Validierungsfehler -->
        <v-alert
            v-if="validationError"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-3"
        >
            {{ validationError }}
        </v-alert>

        <div class="d-flex gap-2">
            <v-btn
                color="primary"
                variant="flat"
                :loading="uploading"
                :disabled="!canUpload"
                prepend-icon="mdi-upload"
                @click="uploadPicture"
            >
                {{ t('crm_fields.whatsappProfilePicture.upload') }}
            </v-btn>
            <v-btn
                v-if="currentPictureUrl"
                variant="outlined"
                prepend-icon="mdi-refresh"
                :loading="loadingPicture"
                @click="loadCurrentPicture"
            >
                {{ t('crm_fields.whatsappProfilePicture.refresh') }}
            </v-btn>
        </div>

        <!-- Erfolgsmeldung -->
        <v-alert
            v-if="successMessage"
            type="success"
            variant="tonal"
            density="compact"
            class="mt-3"
        >
            {{ successMessage }}
        </v-alert>
    </v-card>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const { t } = useI18n();

const selectedFile = ref(null);
const previewUrl = ref('');
const imageDimensions = ref(null);
const currentPictureUrl = ref('');
const uploading = ref(false);
const loadingPicture = ref(false);
const validationError = ref('');
const errorMessage = ref('');
const successMessage = ref('');

const canUpload = computed(() => {
    return selectedFile.value && !validationError.value && !uploading.value;
});

function onFileSelected(file) {
    previewUrl.value = '';
    imageDimensions.value = null;
    validationError.value = '';
    errorMessage.value = '';
    successMessage.value = '';

    if (!file) return;

    // Format prüfen
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        validationError.value = t('crm_fields.whatsappProfilePicture.errFormat');
        return;
    }

    // Größe prüfen
    if (file.size > 5 * 1024 * 1024) {
        validationError.value = t('crm_fields.whatsappProfilePicture.errMaxSize');
        return;
    }

    // Vorschau + Dimensionen prüfen
    const reader = new FileReader();
    reader.onload = (e) => {
        const img = new Image();
        img.onload = () => {
            imageDimensions.value = { width: img.width, height: img.height };

            if (img.width < 192 || img.height < 192) {
                validationError.value = t('crm_fields.whatsappProfilePicture.errTooSmall', { w: img.width, h: img.height });
                return;
            }
            if (img.width > 640 || img.height > 640) {
                validationError.value = t('crm_fields.whatsappProfilePicture.errTooBig', { w: img.width, h: img.height });
                return;
            }
            if (img.width !== img.height) {
                validationError.value = t('crm_fields.whatsappProfilePicture.errNotSquare', { w: img.width, h: img.height });
                return;
            }

            previewUrl.value = e.target.result;
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

async function uploadPicture() {
    if (!selectedFile.value) return;

    uploading.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const base64 = await fileToBase64(selectedFile.value);

        const resp = await axios.post('/api/whatsapp/', {
            action: 'updateWhatsAppProfilePicture',
            image_base64: base64,
            filename: selectedFile.value.name
        });

        if (resp.data.success) {
            successMessage.value = t('crm_fields.whatsappProfilePicture.uploadSuccess');
            selectedFile.value = null;
            previewUrl.value = '';
            imageDimensions.value = null;
            // Aktuelles Bild neu laden (mit Verzögerung, da Meta etwas Zeit braucht)
            setTimeout(() => loadCurrentPicture(), 2000);
        } else {
            errorMessage.value = resp.data.message || t('crm_fields.whatsappProfilePicture.uploadError');
        }
    } catch (err) {
        errorMessage.value = err.response?.data?.message || t('crm_fields.whatsappProfilePicture.uploadError');
    } finally {
        uploading.value = false;
    }
}

function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            // data:image/jpeg;base64,.... -> nur den Base64-Teil
            const base64 = reader.result.split(',')[1];
            resolve(base64);
        };
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

async function loadCurrentPicture() {
    loadingPicture.value = true;
    try {
        const resp = await axios.post('/api/whatsapp/', {
            action: 'getWhatsAppProfilePicture'
        });
        if (resp.data.success) {
            currentPictureUrl.value = resp.data.payload?.profile_picture_url || '';
        }
    } catch {
        // Kein Profilbild vorhanden
    } finally {
        loadingPicture.value = false;
    }
}

onMounted(() => {
    loadCurrentPicture();
});
</script>
