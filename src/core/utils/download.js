// src/core/utils/download.js
//
// Dateien, die das Backend als base64 liefert, im Browser verfügbar machen.

/**
 * Speichert eine base64-kodierte Datei über den Download-Dialog des Browsers.
 *
 * @param {string} base64   Inhalt der Datei
 * @param {string} filename Vorgeschlagener Dateiname
 * @param {string} mime     MIME-Typ
 */
export function downloadBase64File(base64, filename, mime) {
    const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0))
    const blob  = new Blob([bytes], { type: mime })
    const url   = URL.createObjectURL(blob)
    const link  = document.createElement('a')
    link.href     = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
}

/**
 * Öffnet ein PDF in einem neuen Tab — von dort führt ein Tastendruck zum
 * Drucker. Ein Druckdialog direkt aus einem versteckten Rahmen heraus wird von
 * zu vielen Browsern blockiert, deshalb bewusst der sichtbare Weg.
 *
 * Blockiert der Browser das neue Fenster (Popup-Blocker), fällt die Funktion
 * auf den Download zurück — der Benutzer bekommt die Datei also in jedem Fall.
 *
 * @param {string} base64   Inhalt der PDF-Datei
 * @param {string} filename Dateiname für den Rückfall auf den Download
 */
export function openBase64Pdf(base64, filename) {
    const bytes = Uint8Array.from(atob(base64), c => c.charCodeAt(0))
    const blob  = new Blob([bytes], { type: 'application/pdf' })
    const url   = URL.createObjectURL(blob)
    const win   = window.open(url, '_blank')

    if (!win) {
        URL.revokeObjectURL(url)
        downloadBase64File(base64, filename, 'application/pdf')
        return
    }

    // Erst freigeben, wenn der Tab die Datei gelesen hat.
    setTimeout(() => URL.revokeObjectURL(url), 60000)
}
