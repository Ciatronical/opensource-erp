// src/core/utils/smsText.js
//
// SMS-Länge wie beim Netzbetreiber: GSM-7 (160 Zeichen, mehrteilig 153) oder –
// sobald ein Zeichen außerhalb des GSM-Zeichensatzes vorkommt – UCS-2 (70 bzw. 67).
// Zeichen der GSM-Erweiterung (€, [, ], {, }, \, ~, ^, |) zählen doppelt.
// Typografische Zeichen wie „“ ‚‘ – … sind kein GSM und verteuern eine SMS
// schnell auf das Dreifache; replaceSmsSpecials() ersetzt sie durch GSM-Zeichen.

const GSM_BASIC = new Set([...'@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà'])
const GSM_EXTENDED = new Set([...'^{}\\[~]|€\f'])

const SPECIALS = {
    '„': '"', '“': '"', '”': '"', '‟': '"', '«': '"', '»': '"',
    '‚': "'", '‘': "'", '’': "'", '‛': "'", '´': "'", '`': "'",
    '–': '-', '—': '-', '‒': '-', '−': '-',
    '…': '...', ' ': ' ', ' ': ' ', ' ': ' ', '\t': ' ',
    '•': '-', '·': '-', '×': 'x', '÷': ':',
    'á': 'a', 'â': 'a', 'ã': 'a', 'ó': 'o', 'ô': 'o', 'õ': 'o', 'ú': 'u', 'û': 'u', 'í': 'i', 'î': 'i', 'ê': 'e', 'ë': 'e', 'ç': 'c',
    'Á': 'A', 'Â': 'A', 'À': 'A', 'Ó': 'O', 'Ô': 'O', 'Ú': 'U', 'Í': 'I', 'È': 'E', 'Ê': 'E',
}

/**
 * Länge, Kodierung und Anzahl SMS-Teile eines Textes
 *
 * @param {string} text
 * @returns {{ encoding: 'gsm'|'ucs2', length: number, parts: number, perPart: number, left: number, nonGsm: string[] }}
 */
export function smsInfo(text) {
    const chars = [...String(text || '')]
    const nonGsm = [...new Set(chars.filter(c => !GSM_BASIC.has(c) && !GSM_EXTENDED.has(c)))]
    if (nonGsm.length) {
        // UCS-2: Zeichen außerhalb der Basisebene (Emojis) belegen zwei Einheiten
        const length = chars.reduce((n, c) => n + (c.codePointAt(0) > 0xffff ? 2 : 1), 0)
        const perPart = length > 70 ? 67 : 70
        const parts = length === 0 ? 0 : Math.ceil(length / perPart)
        return { encoding: 'ucs2', length, parts, perPart, left: parts * perPart - length, nonGsm }
    }
    const length = chars.reduce((n, c) => n + (GSM_EXTENDED.has(c) ? 2 : 1), 0)
    const perPart = length > 160 ? 153 : 160
    const parts = length === 0 ? 0 : Math.ceil(length / perPart)
    return { encoding: 'gsm', length, parts, perPart, left: parts * perPart - length, nonGsm: [] }
}

/**
 * Typografische Sonderzeichen durch GSM-Zeichen ersetzen (Emojis bleiben)
 *
 * @param {string} text
 * @returns {string}
 */
export function replaceSmsSpecials(text) {
    return [...String(text || '')].map(c => SPECIALS[c] ?? c).join('')
}

/**
 * Ein Link im Text wird beim Versand durch eine Adresse dieser Länge ersetzt
 * (https://host/webhook/beleg.php?k=<db>.<16 Zeichen>) – für die Vorschau der Länge.
 *
 * @param {string} text Text mit {link}
 * @param {string} sample Beispieladresse
 * @returns {string}
 */
export function withSampleLink(text, sample) {
    return String(text || '').replaceAll('{link}', sample)
}

/**
 * Antwort-Hinweis so anhängen, wie es das Backend beim Versand tut (smsDeliver) –
 * für die Vorschau der Länge.
 *
 * @param {string} text Nachricht
 * @param {string} hint Antwort-Hinweis aus getSmsConfig (reply_hint)
 * @returns {string}
 */
export function withReplyHint(text, hint) {
    const s = String(text || '').trim()
    return hint && s && !s.includes(hint) ? s + '\n' + hint : s
}
