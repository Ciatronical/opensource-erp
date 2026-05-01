<!-- src/core/views/customer-vendor/tabs/files.tab.vue -->
<template>
    <div class="vuefinder-wrapper pa-2 pa-sm-3">
        <VueFinder
            id="cv-files"
            :driver="driver"
            locale="de"
            :features="features"
            :config="vfConfig"
            :context-menu-items="contextMenuItems"
            :custom-uploader="customUploader"
            @notify="onNotify"
        />
        <DocChatDialog
            v-model="docChatOpen"
            :file-path="docChatPath"
            :cv-id="cvId"
            :src="src"
        />
    </div>
</template>

<script>
import { provide, ref } from 'vue'
import { VueFinder, contextMenuItems as defaultMenuItems } from 'vuefinder'
import 'vuefinder/dist/vuefinder.css'
import * as toast from '@/core/utils/toasts.js'
import deLocale from 'vuefinder/dist/locales/de.js'
import { oserpStore } from '@/core/stores/oserp.store.js'
import DocChatDialog from './doc-chat.dialog.vue'

const API_URL = '/api/customer_vendor/'

/**
 * CRM-Dateimanager-Driver fuer Vuefinder.
 * Verwendet das Standard-API-Muster (action-basiertes Routing ueber api.call.php).
 * Kein RemoteDriver — alle Methoden rufen direkt /api/customer_vendor/ auf.
 */
class CrmFileDriver {
    constructor(cvId, src) {
        this.cvId = cvId
        this.cvSrc = src
    }

    /** JSON-POST an die Standard-API */
    async _post(action, body = {}) {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, cv_id: this.cvId, src: this.cvSrc, ...body }),
        })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const result = await response.json()
        if (!result.success) throw new Error(result.text || 'Server error')
        return result.payload
    }

    /** GET-URL fuer binaere Endpoints (Preview, Download) */
    _buildGetUrl(action, extraParams = {}) {
        const params = new URLSearchParams({
            action,
            cv_id: this.cvId,
            src: this.cvSrc,
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
        formData.append('cv_id', this.cvId)
        formData.append('src', this.cvSrc)
        if (params.path) formData.append('path', params.path)
        formData.append('file', params.file)

        const response = await fetch(API_URL, { method: 'POST', body: formData })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const result = await response.json()
        if (!result.success) throw new Error(result.text || 'Upload failed')
        return result.payload
    }

    async delete(params) {
        return this._post('vfDelete', { path: params.path, items: params.items })
    }

    async rename(params) {
        return this._post('vfRename', { path: params.path, item: params.item, name: params.name })
    }

    async createFolder(params) {
        return this._post('vfCreateFolder', { path: params.path, name: params.name })
    }

    async createFile(params) {
        return this._post('vfCreateFile', { path: params.path, name: params.name })
    }

    getPreviewUrl(params) {
        return this._buildGetUrl('vfPreview', { path: params.path })
    }

    getDownloadUrl(params) {
        return this._buildGetUrl('vfDownload', { path: params.path })
    }

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

    async move(params) {
        return this._post('vfMove', { path: params.path, sources: params.sources, destination: params.destination })
    }

    async copy(params) {
        return this._post('vfCopy', { path: params.path, sources: params.sources, destination: params.destination })
    }

    async save(params) {
        return this._post('vfSave', { path: params.path, content: params.content })
    }

}

export default {
    name: 'FilesTab',
    components: { VueFinder, DocChatDialog },
    props: {
        cvId: { type: [String, Number], required: true },
        src: { type: String, default: 'C' },
        cvName: { type: String, default: '' },
    },
    setup(props) {
        const store = oserpStore()
        const docChatOpen = ref(false)
        const docChatPath = ref('')
        const fmDefaultView = store.getClientDefaultValue('fm_default_view', 'list')
        const fmMaxUploadSize = parseInt(store.getClientDefaultValue('fm_max_upload_size', '0'), 10) || 0
        const fmAllowedExtensions = store.getClientDefaultValue('fm_allowed_extensions', '')

        provide('VueFinderOptions', {
            i18n: { de: deLocale },
            locale: 'de',
        })

        const driver = new CrmFileDriver(props.cvId, props.src)

        const features = {
            search: true,
            preview: true,
            edit: true,
            rename: true,
            upload: true,
            delete: true,
            newfile: true,
            newfolder: true,
            download: true,
            move: true,
            copy: true,
            fullscreen: false,
            archive: false,
            unarchive: false,
            language: false,
            theme: false,
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

        // "Oeffnen" — Datei im neuen Tab oeffnen (Browser/System-App)
        const openItem = {
            id: 'fileOpen',
            title: () => 'Öffnen',
            action: (vfStore, items) => {
                const item = items[0]
                if (!item) return
                const ext = (item.extension || item.basename || '').split('.').pop().toLowerCase()
                // Browser-faehige Dateien: Preview-URL (inline Anzeige)
                // Alles andere: Download-URL (mit korrektem Dateinamen → System-App oeffnet)
                const url = INLINE_EXTS.has(ext)
                    ? driver.getPreviewUrl({ path: item.path })
                    : driver.getDownloadUrl({ path: item.path })
                window.open(url, '_blank')
            },
            show: (vfStore, { target }) => target?.type === 'file',
            order: 5,
        }

        // Custom "Duplizieren" Context Menu Item
        const duplicateItem = {
            id: 'duplicate',
            title: () => 'Duplizieren',
            action: async (vfStore, items) => {
                const item = items[0]
                if (!item) return
                try {
                    const payload = await driver._post('vfDuplicate', { path: item.path })
                    if (payload.files) {
                        vfStore.fs.setFiles(payload.files)
                    }
                    toast.success('Datei dupliziert')
                } catch (err) {
                    toast.error('Duplizieren fehlgeschlagen')
                }
            },
            show: (vfStore, { target }) => target?.type === 'file',
            order: 95,
        }

        // KI-Analyse: öffnet den Dokument-Chat-Dialog
        const kiItem = {
            id: 'docChat',
            title: () => 'KI',
            icon: () => 'mdi-robot-outline',
            action: (vfStore, items) => {
                const item = items[0]
                if (!item) return
                docChatPath.value = item.path
                docChatOpen.value = true
            },
            show: (vfStore, { target }) => {
                if (target?.type !== 'file') return false
                const ext = (target.extension || target.basename || '').split('.').pop().toLowerCase()
                const supported = ['pdf', 'txt', 'md', 'csv', 'json', 'xml', 'html', 'htm']
                return supported.includes(ext)
            },
            order: 6,
        }

        // "Oeffnen" VOR den Default-Items, damit Doppelklick die Datei im neuen Tab oeffnet
        const contextMenuItems = [openItem, kiItem, ...defaultMenuItems, duplicateItem]

        /**
         * Custom Uploader fuer VueFinder/Uppy.
         * Verwendet uppy.addUploader() statt @uppy/xhr-upload,
         * da das XHR-Plugin als verschachtelte VueFinder-Abhaengigkeit
         * nicht direkt importiert werden kann.
         */
        const customUploader = (uppy, ctx) => {
            uppy.addUploader(async (fileIDs) => {
                const targetPath = ctx.getTargetPath()

                for (const fileID of fileIDs) {
                    const file = uppy.getFile(fileID)
                    if (!file) continue

                    uppy.emit('upload-start', [file])

                    const formData = new FormData()
                    formData.append('action', 'vfUpload')
                    formData.append('cv_id', props.cvId)
                    formData.append('src', props.src)
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

        return { driver, features, vfConfig, contextMenuItems, customUploader, onNotify, docChatOpen, docChatPath }
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
/* "Einstellungen" und "Ueber" im VueFinder Help-Menue ausblenden —
   VueFinder bietet keine Feature-Flags dafuer, daher per CSS.
   Help-Menue (letztes Item): 1. Einstellungen, 2. Shortcuts, 3. Ueber */
.vuefinder-wrapper .vuefinder__menubar__item:last-child .vuefinder__menubar__dropdown__item:first-child,
.vuefinder-wrapper .vuefinder__menubar__item:last-child .vuefinder__menubar__dropdown__item:last-child {
    display: none !important;
}
</style>
