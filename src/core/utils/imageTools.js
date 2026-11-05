// src/core/utils/imageTools.js
//
// Bilder im Browser aufbereiten, bevor sie gespeichert werden: verkleinern
// (Fotos vom Handy haben schnell 5 MB) und Unterschriften freistellen.

/** Datei oder Data-URL als Bild laden */
function loadImage(source) {
    return new Promise((resolve, reject) => {
        const url = typeof source === 'string' ? source : URL.createObjectURL(source)
        const img = new Image()
        img.onload = () => { if (typeof source !== 'string') URL.revokeObjectURL(url); resolve(img) }
        img.onerror = () => { if (typeof source !== 'string') URL.revokeObjectURL(url); reject(new Error('image')) }
        img.src = url
    })
}

function readAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(reader.result)
        reader.onerror = () => reject(reader.error)
        reader.readAsDataURL(file)
    })
}

/** Pixelbreite eines Bilds (Data-URL) */
export async function imageWidth(dataUrl) {
    return (await loadImage(dataUrl)).naturalWidth
}

/**
 * Bilddatei als Data-URL zum Einbetten in HTML. Kleine PNGs (Screenshots,
 * Grafiken mit Transparenz) bleiben unverändert, alles andere wird auf
 * höchstens maxSize Pixel Kantenlänge verkleinert und als JPEG gespeichert.
 *
 * @param {File} file
 * @param {number} maxSize längste Kante in Pixeln
 * @returns {Promise<string>} data:image/…;base64,…
 */
export async function imageFileToDataUrl(file, maxSize = 1600) {
    if (file.type === 'image/png' && file.size < 400 * 1024) return readAsDataUrl(file)
    const img = await loadImage(file)
    const scale = Math.min(1, maxSize / Math.max(img.naturalWidth, img.naturalHeight))
    const canvas = document.createElement('canvas')
    canvas.width = Math.round(img.naturalWidth * scale)
    canvas.height = Math.round(img.naturalHeight * scale)
    const ctx = canvas.getContext('2d')
    // Transparenz würde in JPEG schwarz: auf Weiß legen
    ctx.fillStyle = '#fff'
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
    return canvas.toDataURL('image/jpeg', 0.85)
}

/**
 * Unterschrift freistellen: heller Hintergrund (Scan, Foto vom Blatt) wird
 * transparent, der Rand bis zur Schrift abgeschnitten, das Ergebnis auf
 * höchstens 900 × 300 Pixel gebracht. Liefert PNG, damit die Unterschrift
 * auf jedem Briefpapier ohne weißen Kasten sitzt.
 *
 * @param {File|string} source Bilddatei oder Data-URL
 * @returns {Promise<string>} data:image/png;base64,…
 */
export async function prepareSignature(source) {
    const img = await loadImage(source)
    const pre = Math.min(1, 1800 / Math.max(img.naturalWidth, img.naturalHeight))
    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.round(img.naturalWidth * pre))
    canvas.height = Math.max(1, Math.round(img.naturalHeight * pre))
    const ctx = canvas.getContext('2d', { willReadFrequently: true })
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
    const data = ctx.getImageData(0, 0, canvas.width, canvas.height)
    const px = data.data

    // Papier ist hell, Tinte dunkel. Die Papierhelligkeit kommt aus dem Bild
    // selbst (85. Perzentil, deckende Pixel) — ein Handyfoto vom Blatt ist eher
    // grau als weiß. Was nahe am Papier liegt, wird durchsichtig, dazwischen
    // weicher Übergang, damit die Striche glatt bleiben.
    const hist = new Uint32Array(256)
    let opaque = 0
    for (let i = 0; i < px.length; i += 4) {
        if (px[i + 3] < 128) continue
        hist[Math.round(0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2])]++
        opaque++
    }
    let paper = 255
    for (let l = 0, sum = 0; l < 256; l++) {
        sum += hist[l]
        if (sum >= opaque * 0.85) { paper = l; break }
    }
    const hi = Math.max(60, paper - 25)
    const lo = Math.max(10, paper - 100)
    // Überwiegend durchsichtig (gezeichnet, schon freigestellt): nur zuschneiden
    const transparent = opaque < (px.length / 4) * 0.5

    let minX = canvas.width, minY = canvas.height, maxX = -1, maxY = -1
    for (let y = 0; y < canvas.height; y++) {
        for (let x = 0; x < canvas.width; x++) {
            const i = (y * canvas.width + x) * 4
            const lum = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2]
            const ink = transparent ? 1 : Math.max(0, Math.min(1, (hi - lum) / (hi - lo)))
            const a = Math.round(px[i + 3] * ink)
            px[i + 3] = a
            if (a > 40) {
                if (x < minX) minX = x
                if (x > maxX) maxX = x
                if (y < minY) minY = y
                if (y > maxY) maxY = y
            }
        }
    }
    if (maxX < 0) throw new Error('empty')
    ctx.putImageData(data, 0, 0)

    const pad = Math.round(Math.max(maxX - minX, maxY - minY) * 0.02) + 2
    minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad)
    maxX = Math.min(canvas.width - 1, maxX + pad); maxY = Math.min(canvas.height - 1, maxY + pad)
    const w = maxX - minX + 1, h = maxY - minY + 1
    const scale = Math.min(1, 900 / w, 300 / h)
    const out = document.createElement('canvas')
    out.width = Math.max(1, Math.round(w * scale))
    out.height = Math.max(1, Math.round(h * scale))
    out.getContext('2d').drawImage(canvas, minX, minY, w, h, 0, 0, out.width, out.height)
    return out.toDataURL('image/png')
}
