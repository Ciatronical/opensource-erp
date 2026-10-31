// Fehlertext einer API-Antwort: Detail aus payload (z. B. WHATSAPP_WINDOW_CLOSED), sonst der Code.
// Bekannte Codes werden übersetzt, alles andere erscheint im Klartext.
export function apiError(data, t) {
    const detail = typeof data?.payload === 'string' && data.payload ? data.payload : ''
    const code = detail || data?.text || 'UNKNOWN'
    const key = 'MailingErrors.' + code
    return t && t(key) !== key ? t(key) : (detail ? `${data.text}: ${detail}` : code)
}
