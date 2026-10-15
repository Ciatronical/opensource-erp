// src/features/shop/composables/shopMediaApi.js
//
// Zugriff auf die Medienbibliothek (dev/shop-medienbibliothek.md) für die
// Ansicht „Medien“, die Auswahl aus der Bibliothek und die Artikelkarte.
// Spricht backend/api/shop/media.php an. Die drei Bereiche heißen
// images, thumbnails und downloads; ein Pfad ist bereich://name. Ohne
// Unterverzeichnisse, ohne Verschieben — die Artikel nennen nur Dateinamen.

export const SHOP_API_URL = '/api/shop/'

export const MEDIA_STORAGES = ['images', 'thumbnails', 'downloads']

/** Pfad eines Bereichs oder einer Datei darin */
export function mediaPath(storage, name = '') {
    return `${storage}://${name}`
}

/** Bereich und Name aus einem Pfad */
export function splitMediaPath(path) {
    const [storage, name = ''] = String(path || '').split('://')
    return { storage: storage || 'images', name }
}

function getUrl(action, params) {
    return `${SHOP_API_URL}?${new URLSearchParams({ action, ...params }).toString()}`
}

/** Adresse, unter der eine Datei der Bibliothek im Browser angezeigt wird */
export function mediaPreviewUrl(path) {
    return getUrl('shopMediaPreview', { path })
}

/** Adresse zum Herunterladen (Content-Disposition: attachment) */
export function mediaDownloadUrl(path) {
    return getUrl('shopMediaDownload', { path })
}

/** Vorschaubild zu einem Dateinamen der Produktbilder; fehlt es, liefert das Backend das Bild selbst */
export function mediaThumbnailUrl(name) {
    return mediaPreviewUrl(mediaPath('thumbnails', name))
}

async function post(action, body = {}) {
    const response = await fetch(SHOP_API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...body }),
    })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const result = await response.json()
    if (!result.success) {
        // Meldung aus dem Backend; der Code bleibt für die Übersetzung erhalten
        const error = new Error(result.debug || result.text || 'Server error')
        error.code = result.text || ''
        throw error
    }
    return result.payload
}

/**
 * Lädt eine Datei in einen Bereich der Bibliothek (images oder downloads)
 *
 * Jeden Namen gibt es nur einmal (Fehler MEDIA_EXISTS, nichts wird ersetzt).
 *
 * @param {string} storage images oder downloads
 * @param {File} file
 * @param {(percent: number) => void} [onProgress] Fortschritt 0–100
 * @param {{name?: string, format?: string}} [options] gewünschter Name; Zielformat für Bilder (webp, jpg, png)
 * @returns {Promise<{filename: string, replaced: boolean}>} Name, unter dem die Datei liegt
 */
export function uploadMedia(storage, file, onProgress, options = {}) {
    return new Promise((resolve, reject) => {
        const formData = new FormData()
        formData.append('action', 'shopMediaUpload')
        formData.append('path', mediaPath(storage))
        if (options.name) formData.append('name', options.name)
        if (options.format) formData.append('format', options.format)
        formData.append('file', file, file.name)

        const xhr = new XMLHttpRequest()
        xhr.upload.addEventListener('progress', (ev) => {
            if (ev.lengthComputable && onProgress) onProgress(Math.round((ev.loaded / ev.total) * 100))
        })
        xhr.addEventListener('load', () => {
            let result = null
            try { result = JSON.parse(xhr.responseText) } catch { result = null }
            if (xhr.status >= 200 && xhr.status < 300 && result?.success) {
                resolve(result.payload)
                return
            }
            const error = new Error(result?.debug || result?.text || `HTTP ${xhr.status}`)
            error.code = result?.text || ''
            reject(error)
        })
        xhr.addEventListener('error', () => reject(new Error('Network error')))
        xhr.open('POST', SHOP_API_URL)
        xhr.send(formData)
    })
}

export const shopMediaApi = {
    /** Alle Dateien eines Bereichs (type, basename, path, file_size, extension, mime_type, last_modified) */
    async list(storage) {
        const payload = await post('shopMediaIndex', { path: mediaPath(storage) })
        return payload.files || []
    },

    /** Dateien eines Bereichs mit Namensteil, höchstens 200 */
    async search(storage, filter) {
        const payload = await post('shopMediaSearch', { path: mediaPath(storage), filter: filter || '' })
        return payload.files || []
    },

    /** Auswahl für die Artikelkarte: files, total */
    pick(kind, filter = '', limit = 120) {
        return post('shopMediaPick', { kind, filter, limit })
    },

    /** Dateien eines Bereichs zu den Namen eines Artikels; fehlende mit missing = true */
    async items(kind, names) {
        const payload = await post('shopMediaItems', { kind, names })
        return payload.files || []
    },

    /**
     * Löscht Dateien — nur, was kein Artikel nennt (sonst MEDIA_IN_USE).
     * Mit partsId geht der Verweis dieses Artikels vorher mit.
     */
    remove(storage, paths, partsId = 0) {
        return post('shopMediaDelete', { path: mediaPath(storage), items: paths, parts_id: partsId || 0 })
    },

    /** Benennt um, die Verweise der Artikel folgen; Antwort: name, parts */
    rename(storage, path, name) {
        return post('shopMediaRename', { path: mediaPath(storage), item: path, name })
    },

    /** Artikel, die eine Datei nennen */
    async usage(path) {
        const payload = await post('shopMediaUsage', { path })
        return payload.parts || []
    },

    /** media_shop mit den Verzeichnissen abgleichen: neu, geaendert, entfernt, vorschau, unbrauchbar */
    scan() {
        return post('shopMediaScan')
    },

    /** Produktbild in ein anderes Format umwandeln: name, parts, size_before, size_after */
    convert(path, format) {
        return post('shopMediaConvert', { path, format })
    },
}
