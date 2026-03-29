/**
 * Parst Konfig-Strings im Format "Wert:Farbe, Wert2:Farbe2, Wert3"
 * Farbe ist optional - fehlt sie, wird null zurückgegeben.
 */
export function parseConfigWithColors(raw) {
    if (!raw) return []
    return raw.split(',').map(s => {
        const colonIndex = s.lastIndexOf(':')
        if (colonIndex === -1) {
            return { value: s.trim(), color: null }
        }
        const value = s.substring(0, colonIndex).trim()
        const color = s.substring(colonIndex + 1).trim()
        return { value, color: color || null }
    }).filter(item => item.value)
}

/**
 * Gibt ein Objekt { Wert: Farbe } zurück.
 */
export function getColorMap(raw) {
    return Object.fromEntries(
        parseConfigWithColors(raw).map(i => [i.value, i.color])
    )
}

/**
 * Gibt nur die Werte als Array zurück (ohne Farben).
 */
export function getValues(raw) {
    return parseConfigWithColors(raw).map(i => i.value)
}
