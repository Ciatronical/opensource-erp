<!-- src/features/lxcars/views/car/components/car-files.dialog.vue -->

<template>
    <v-dialog v-model="open" max-width="1100" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center py-2 px-3 bg-grey-lighten-4">
                <v-icon class="mr-2" size="small">mdi-folder-open-outline</v-icon>
                <span class="text-subtitle-1 font-weight-medium">
                    {{ t('CarEditView.files.dialogTitle') }}
                    <template v-if="plate"> — {{ plate }}</template>
                </span>
                <v-spacer />
                <v-btn icon="mdi-close" size="small" variant="text" @click="open = false" />
            </v-card-title>
            <v-divider />
            <v-card-text class="pa-0">
                <div v-if="open && cId" class="vuefinder-wrapper pa-2 pa-sm-3">
                    <VueFinder
                        :id="'car-files-' + cId"
                        :driver="driver"
                        locale="de"
                        :features="features"
                        :config="vfConfig"
                        :context-menu-items="contextMenuItems"
                        :custom-uploader="customUploader"
                        @notify="onNotify"
                    />
                </div>
            </v-card-text>
        </v-card>
    </v-dialog>
</template>

<script>
import { computed, provide, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { VueFinder, contextMenuItems as defaultMenuItems } from 'vuefinder'
import 'vuefinder/dist/vuefinder.css'
import deLocale from 'vuefinder/dist/locales/de.js'
import * as toast from '@/core/utils/toasts.js'
import { oserpStore } from '@/core/stores/oserp.store.js'

const API_URL = '/api/customer_vendor/'

/**
 * Vuefinder-Driver für Fahrzeug-Ordner.
 * Identisch zum CrmFileDriver in files.tab.vue, nutzt aber c_id statt cv_id/src.
 * Backend (filemanager.php → vfResolveRootDir) routet darauf hin auf
 * fmDataDir()/fahrzeuge/{c_id}/.
 */
class CarFileDriver {
    constructor(cId) {
        this.cId = cId
    }

    async _post(action, body = {}) {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, c_id: this.cId, ...body }),
        })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const result = await response.json()
        if (!result.success) throw new Error(result.text || 'Server error')
        return result.payload
    }

    _buildGetUrl(action, extraParams = {}) {
        const params = new URLSearchParams({
            action,
            c_id: this.cId,
            ...extraParams,
        })
        return `${API_URL}?${params.toString()}`
    }

    async list(params = {}) {
        return this._post('vfIndex', params.path ? { path: params.path } : {})
    }

    async upload(params) {
        const formData = new FormData()
        formData.append('action', 'vfUpload')
        formData.append('c_id', this.cId)
        if (params.path) formData.append('path', params.path)
        formData.append('file', params.file)

        const response = await fetch(API_URL, { method: 'POST', body: formData })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const result = await response.json()
        if (!result.success) throw new Error(result.text || 'Upload failed')
        return result.payload
    }

    async delete(params)        { return this._post('vfDelete', { path: params.path, items: params.items }) }
    async rename(params)        { return this._post('vfRename', { path: params.path, item: params.item, name: params.name }) }
    async createFolder(params)  { return this._post('vfCreateFolder', { path: params.path, name: params.name }) }
    async createFile(params)    { return this._post('vfCreateFile', { path: params.path, name: params.name }) }
    async move(params)          { return this._post('vfMove', { path: params.path, sources: params.sources, destination: params.destination }) }
    async copy(params)          { return this._post('vfCopy', { path: params.path, sources: params.sources, destination: params.destination }) }
    async save(params)          { return this._post('vfSave', { path: params.path, content: params.content }) }

    getPreviewUrl(params)  { return this._buildGetUrl('vfPreview', { path: params.path }) }
    getDownloadUrl(params) { return this._buildGetUrl('vfDownload', { path: params.path }) }

    async getContent(params) {
        const url = this._buildGetUrl('vfPreview', { path: params.path })
        const response = await fetch(url)
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        return {
            content: await response.text(),
            mimeType: response.headers.get('Content-Type') || undefined,
        }
    }

    async search(params) {
        const body = {}
        if (params.path) body.path = params.path
        if (params.filter) body.filter = params.filter
        if (params.deep) body.deep = '1'
        if (params.size && params.size !== 'all') body.size = params.size
        const payload = await this._post('vfSearch', body)
        return payload.files || []
    }
}

export default {
    name: 'CarFilesDialog',
    components: { VueFinder },
    props: {
        modelValue: { type: Boolean, default: false },
        cId:        { type: [String, Number], default: null },
        plate:      { type: String, default: '' },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const { t } = useI18n()
        const store = oserpStore()

        const open = computed({
            get: () => props.modelValue,
            set: (v) => emit('update:modelValue', v),
        })

        const fmDefaultView = store.getClientDefaultValue('fm_default_view', 'list')
        const fmMaxUploadSize = parseInt(store.getClientDefaultValue('fm_max_upload_size', '0'), 10) || 0
        const fmAllowedExtensions = store.getClientDefaultValue('fm_allowed_extensions', '')

        provide('VueFinderOptions', {
            i18n: { de: deLocale },
            locale: 'de',
        })

        // Driver pro c_id neu erstellen, damit Wechsel zwischen Fahrzeugen sauber funktioniert
        const driver = ref(props.cId ? new CarFileDriver(props.cId) : null)
        watch(() => props.cId, (id) => {
            driver.value = id ? new CarFileDriver(id) : null
        })

        const features = {
            search: true, preview: true, edit: true, rename: true,
            upload: true, delete: true, newfile: true, newfolder: true,
            download: true, move: true, copy: true,
            fullscreen: false, archive: false, unarchive: false,
            language: false, theme: false,
        }

        const vfConfig = {
            metricUnits: true,
            persist: false,
            fullScreen: false,
            notificationsEnabled: false,
            view: fmDefaultView,
            maxFileSize: fmMaxUploadSize > 0 ? fmMaxUploadSize * 1024 * 1024 : 0,
            accept: fmAllowedExtensions
                ? fmAllowedExtensions.split(',').map(ext => '.' + ext.trim()).join(',')
                : '',
        }

        const onNotify = (event) => {
            const { type, message } = event || {}
            if (!message) return
            if (type === 'success') toast.success(message)
            else if (type === 'error') toast.error(message)
            else if (type === 'warning') toast.warning(message)
            else toast.info(message)
        }

        // Dateitypen die der Browser inline anzeigen kann
        const INLINE_EXTS = new Set([
            'pdf', 'csv', 'json', 'xml', 'html', 'htm', 'svg',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
            'mp4', 'mp3',
        ])

        const openItem = {
            id: 'fileOpen',
            title: () => t('CarEditView.files.openInTab'),
            action: (vfStore, items) => {
                const item = items[0]
                if (!item || !driver.value) return
                const ext = (item.extension || item.basename || '').split('.').pop().toLowerCase()
                const url = INLINE_EXTS.has(ext)
                    ? driver.value.getPreviewUrl({ path: item.path })
                    : driver.value.getDownloadUrl({ path: item.path })
                window.open(url, '_blank')
            },
            show: (vfStore, { target }) => target?.type === 'file',
            order: 5,
        }

        const contextMenuItems = [openItem, ...defaultMenuItems]

        // Custom Uploader (Uppy) — analog zu files.tab.vue, nur c_id statt cv_id/src
        const customUploader = (uppy, ctx) => {
            uppy.addUploader(async (fileIDs) => {
                const targetPath = ctx.getTargetPath()

                for (const fileID of fileIDs) {
                    const file = uppy.getFile(fileID)
                    if (!file) continue

                    uppy.emit('upload-start', [file])

                    const formData = new FormData()
                    formData.append('action', 'vfUpload')
                    formData.append('c_id', props.cId)
                    if (targetPath) formData.append('path', targetPath)
                    formData.append('file', file.data, file.name)

                    try {
                        await new Promise((resolve, reject) => {
                            const xhr = new XMLHttpRequest()
                            xhr.upload.addEventListener('progress', (ev) => {
                                if (ev.lengthComputable) {
                                    uppy.emit('upload-progress', file, {
                                        bytesUploaded: ev.loaded,
                                        bytesTotal: ev.total,
                                    })
                                }
                            })
                            xhr.addEventListener('load', () => {
                                if (xhr.status >= 200 && xhr.status < 300) {
                                    try {
                                        const result = JSON.parse(xhr.responseText)
                                        if (result.success) {
                                            uppy.emit('upload-success', file, { uploadURL: null, status: xhr.status, body: result })
                                            resolve()
                                        } else {
                                            const err = new Error(result.text || 'Upload failed')
                                            uppy.emit('upload-error', file, err)
                                            reject(err)
                                        }
                                    } catch (e) {
                                        uppy.emit('upload-error', file, e)
                                        reject(e)
                                    }
                                } else {
                                    const err = new Error(`HTTP ${xhr.status}`)
                                    uppy.emit('upload-error', file, err)
                                    reject(err)
                                }
                            })
                            xhr.addEventListener('error', () => {
                                const err = new Error('Network error')
                                err.isNetworkError = true
                                uppy.emit('upload-error', file, err)
                                reject(err)
                            })
                            xhr.open('POST', API_URL)
                            xhr.send(formData)
                        })
                    } catch {
                        // Fehler bereits ueber uppy.emit('upload-error') gemeldet
                    }
                }
            })
        }

        return { t, open, driver, features, vfConfig, contextMenuItems, customUploader, onNotify }
    },
}
</script>

<style>
.vuefinder-wrapper {
    min-height: 500px;
}
.vuefinder-wrapper .vuefinder {
    height: 60vh;
    min-height: 400px;
}
/* "Einstellungen" und "Über" im VueFinder Help-Menü ausblenden — analog zu files.tab.vue */
.vuefinder-wrapper .vuefinder__menubar__item:last-child .vuefinder__menubar__dropdown__item:first-child,
.vuefinder-wrapper .vuefinder__menubar__item:last-child .vuefinder__menubar__dropdown__item:last-child {
    display: none !important;
}
/* VueFinder benutzt <Teleport to="body"> für seine Modale (Neuer Ordner, Umbenennen, Verschieben, ...).
   Vuetifys v-dialog liegt auf z-index 2400 und würde die VF-Modale sonst verdecken. */
body > .vuefinder__modal-layout {
    z-index: 2500;
}
</style>
