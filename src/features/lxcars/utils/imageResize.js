// src/features/lxcars/utils/imageResize.js
//
// Verkleinert ein Foto im Browser, bevor es zum Server geht: Handyfotos haben
// 3 bis 12 MB, php-fpm erlaubt oft nur 2 MB. Die EXIF-Drehung wird dabei
// übernommen (createImageBitmap mit imageOrientation), das Ergebnis ist immer
// ein JPEG. Kann der Browser die Datei nicht lesen (z. B. HEIC in Chrome),
// kommt die Originaldatei zurück und der Server entscheidet.

const MAX_EDGE = 2000
const JPEG_QUALITY = 0.85

/**
 * Bild laden, EXIF-Drehung anwenden
 *
 * @param {File} file
 * @return {Promise<ImageBitmap|HTMLImageElement>}
 */
async function loadImage(file) {
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' })
        } catch { /* Browser kennt das Format oder die Option nicht → Fallback */ }
    }
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file)
        const img = new Image()
        img.onload = () => { URL.revokeObjectURL(url); resolve(img) }
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('IMAGE_DECODE_FAILED')) }
        img.src = url
    })
}

/**
 * Verkleinert ein Bild auf höchstens MAX_EDGE Pixel Kantenlänge
 *
 * @param {File} file Datei aus Kamera oder Galerie
 * @return {Promise<{ blob: Blob, filename: string }>} JPEG oder, wenn nicht lesbar, das Original
 */
export async function resizeImageForUpload(file) {
    let image
    try {
        image = await loadImage(file)
    } catch {
        return { blob: file, filename: file.name || 'bild' }
    }

    const width = image.width || image.naturalWidth
    const height = image.height || image.naturalHeight
    const scale = Math.min(1, MAX_EDGE / Math.max(width, height))
    const targetW = Math.max(1, Math.round(width * scale))
    const targetH = Math.max(1, Math.round(height * scale))

    const canvas = document.createElement('canvas')
    canvas.width = targetW
    canvas.height = targetH
    const ctx = canvas.getContext('2d')
    // Transparente Flächen (PNG) werden im JPEG weiß statt schwarz
    ctx.fillStyle = '#ffffff'
    ctx.fillRect(0, 0, targetW, targetH)
    ctx.drawImage(image, 0, 0, targetW, targetH)
    if (typeof image.close === 'function') image.close()

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY))
    if (!blob) {
        return { blob: file, filename: file.name || 'bild' }
    }
    const base = (file.name || 'bild').replace(/\.[^.]+$/, '')
    return { blob, filename: base + '.jpg' }
}
