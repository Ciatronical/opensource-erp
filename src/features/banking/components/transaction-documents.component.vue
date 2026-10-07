<template>
    <div>
        <div class="d-flex align-center mb-2">
            <div class="text-overline text-medium-emphasis">{{ t('BankingView.documents.title') }}</div>
            <v-chip v-if="documents.length" size="x-small" variant="tonal" color="success" class="ml-2">
                {{ documents.length }}
            </v-chip>
            <v-spacer />
            <v-btn
                size="small"
                color="primary"
                variant="tonal"
                prepend-icon="mdi-paperclip-plus"
                :loading="uploading"
                @click="fileInput.click()"
            >
                {{ t('BankingView.documents.add') }}
            </v-btn>
            <input
                ref="fileInput"
                type="file"
                multiple
                :accept="ACCEPT"
                class="d-none"
                @change="onFilesPicked"
            >
        </div>

        <v-card
            variant="outlined"
            rounded="lg"
            class="mb-4 docs-drop"
            :class="{ 'docs-drop--over': dragOver }"
            :loading="loading"
            @dragover.prevent="dragOver = true"
            @dragleave.prevent="dragOver = false"
            @drop.prevent="onDrop"
        >
            <v-list v-if="documents.length" density="compact" class="py-0">
                <v-list-item
                    v-for="(doc, i) in documents"
                    :key="doc.id"
                    :divider="i < documents.length - 1"
                    @click="preview(doc)"
                >
                    <template #prepend>
                        <v-icon :icon="fileIcon(doc.mime_type)" :color="fileColor(doc.mime_type)" />
                    </template>
                    <v-list-item-title class="text-body-2 font-weight-medium">{{ doc.original_name }}</v-list-item-title>
                    <v-list-item-subtitle class="d-flex align-center flex-wrap ga-2 mt-1">
                        <span>{{ formatSize(doc.file_size) }} · {{ formatDate(doc.itime) }}<template v-if="doc.employee_name"> · {{ doc.employee_name }}</template></span>
                        <v-chip v-if="doc.source !== 'direct'" size="x-small" variant="tonal" color="info">
                            {{ sourceLabel(doc) }}
                        </v-chip>
                    </v-list-item-subtitle>
                    <template #append>
                        <div class="d-flex ga-1" @click.stop>
                            <v-btn
                                icon="mdi-eye-outline"
                                size="x-small"
                                variant="text"
                                :title="t('BankingView.documents.preview')"
                                @click="preview(doc)"
                            />
                            <v-btn
                                v-if="doc.source === 'direct'"
                                icon="mdi-link-off"
                                size="x-small"
                                variant="text"
                                color="warning"
                                :title="t('BankingView.documents.unlink')"
                                @click="unlink(doc)"
                            />
                        </div>
                    </template>
                </v-list-item>
            </v-list>
            <div v-else class="text-center pa-4 text-body-2 text-medium-emphasis">
                <v-icon size="small" class="mr-1">mdi-paperclip-off</v-icon>
                {{ dragOver ? t('BankingView.documents.dropHere') : t('BankingView.documents.empty') }}
            </div>
        </v-card>

        <!-- Vorschau -->
        <v-dialog v-model="showPreview" max-width="860">
            <v-card>
                <v-card-title class="d-flex align-center">
                    <span class="text-truncate">{{ previewName }}</span>
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" @click="showPreview = false" />
                </v-card-title>
                <v-card-text class="text-center pa-2">
                    <v-progress-circular v-if="previewLoading" indeterminate class="my-8" />
                    <img
                        v-else-if="previewMime && previewMime.startsWith('image/')"
                        :src="`data:${previewMime};base64,${previewData}`"
                        style="max-width:100%; max-height:640px"
                    >
                    <iframe
                        v-else-if="previewMime === 'application/pdf'"
                        :src="`data:application/pdf;base64,${previewData}`"
                        style="width:100%; height:640px; border:none"
                    />
                    <div v-else class="pa-6 text-medium-emphasis">{{ t('BankingView.documents.noPreview') }}</div>
                </v-card-text>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBankDocuments } from '../composables/useBankDocuments.js'
import * as alerts from '@/core/utils/alerts.js'

const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff'

const props = defineProps({
    transactionId: { type: Number, required: true }
})

// changed: Anzahl der Belege nach jeder Änderung — die Liste im Hub hält damit
// ihr Büroklammer-Symbol aktuell, ohne alle Umsätze neu zu laden.
const emit = defineEmits(['changed'])

const { t } = useI18n()
const api = useBankDocuments()

const documents  = ref([])
const loading    = ref(false)
const uploading  = ref(false)
const dragOver   = ref(false)
const fileInput  = ref(null)

const showPreview    = ref(false)
const previewLoading = ref(false)
const previewData    = ref('')
const previewMime    = ref('')
const previewName    = ref('')

onMounted(load)
watch(() => props.transactionId, load)

async function load() {
    if (!props.transactionId) return
    loading.value = true
    try {
        documents.value = await api.fetchDocuments(props.transactionId)
    } catch (e) {
        alerts.error(e.message)
    } finally {
        loading.value = false
    }
}

function onFilesPicked(e) {
    const files = Array.from(e.target.files || [])
    e.target.value = ''
    upload(files)
}

function onDrop(e) {
    dragOver.value = false
    upload(Array.from(e.dataTransfer?.files || []))
}

async function upload(files) {
    if (!files.length) return
    uploading.value = true
    try {
        const res = await api.uploadDocuments(props.transactionId, files)
        documents.value = res.documents || []
        if (res.stored > 0) {
            alerts.success(res.stored === 1
                ? t('BankingView.documents.uploadedOne')
                : t('BankingView.documents.uploadedMany', { count: res.stored }))
        }
        if (res.errors?.length) alerts.error(t('BankingView.documents.uploadFailed', { reason: res.errors.join('; ') }))
        emit('changed', documents.value.length)
    } catch (e) {
        alerts.error(e.message)
    } finally {
        uploading.value = false
    }
}

async function unlink(doc) {
    const res = await alerts.question(
        t('BankingView.documents.unlinkConfirm', { name: doc.original_name }),
        t('BankingView.documents.unlinkTitle'),
        t('BankingView.documents.unlinkDo'),
        t('BankingView.booking.cancel')
    )
    if (!res.isConfirmed) return
    try {
        documents.value = await api.unlinkDocument(props.transactionId, doc.id)
        emit('changed', documents.value.length)
    } catch (e) {
        alerts.error(e.message)
    }
}

async function preview(doc) {
    showPreview.value    = true
    previewLoading.value = true
    previewData.value    = ''
    previewMime.value    = ''
    previewName.value    = doc.original_name
    try {
        const p = await api.fetchContent(doc.id)
        previewData.value = p.content_base64
        previewMime.value = p.mime_type
    } catch (e) {
        showPreview.value = false
        alerts.error(e.message)
    } finally {
        previewLoading.value = false
    }
}

function sourceLabel(doc) {
    return t(doc.source === 'ar' ? 'BankingView.documents.sourceAr' : 'BankingView.documents.sourceAp', { invnumber: doc.invnumber || '' })
}

function fileIcon(mime) {
    if (mime === 'application/pdf') return 'mdi-file-pdf-box'
    if (mime && mime.startsWith('image/')) return 'mdi-file-image'
    return 'mdi-file-document-outline'
}

function fileColor(mime) {
    if (mime === 'application/pdf') return 'error'
    if (mime && mime.startsWith('image/')) return 'info'
    return undefined
}

function formatSize(bytes) {
    const n = Number(bytes) || 0
    if (n < 1024) return `${n} B`
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`
    return `${(n / 1024 / 1024).toFixed(1)} MB`
}

function formatDate(d) {
    return d ? new Date(d).toLocaleDateString('de-DE') : ''
}
</script>

<style scoped>
.docs-drop {
    transition: border-color .15s, background-color .15s;
}
.docs-drop--over {
    border-color: rgb(var(--v-theme-primary));
    background-color: rgba(var(--v-theme-primary), 0.06);
}
</style>
