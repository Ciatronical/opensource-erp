// src/core/views/mailings/composables/mailingPlan.js
//
// Kanalplanung eines Rundschreibens — dieselbe Regel wie im Backend
// (mailingResolveChannels in backend/api/mailings/mailings.php), damit die
// Oberfläche schon vor dem Start zeigt, wer worüber erreicht wird:
//
//   * Messenger in der gewählten Reihenfolge, je Empfänger der erste verfügbare
//   * Telegram: nur mit bekannter Chat-ID (Kunde hat dem Bot seine Nummer geteilt)
//   * WhatsApp: kostenlos im offenen 24-Stunden-Fenster; sonst per Vorlage
//     (kostenpflichtig), falls eine gewählt ist
//   * E-Mail je nach email_mode: always = zusätzlich an alle mit Adresse,
//     fallback = nur ohne erreichbaren Messenger, off = nie
//   * Brief (E-Brief über den Postdienst) je nach letter_mode: off = nie,
//     fallback = nur wer sonst gar nicht erreichbar ist, always = zusätzlich
//     an alle mit vollständiger Postanschrift
//   * Gleiches Ziel bei zwei Empfängern → nur einmal (auch bei der Zusatz-E-Mail)

export const CHANNELS = ['whatsapp', 'telegram', 'sms']
export const EMAIL_MODES = ['always', 'fallback', 'off']
export const LETTER_MODES = ['off', 'fallback', 'always']

export const CHANNEL_META = {
    telegram:          { icon: 'mdi-send-circle',      color: 'light-blue-darken-1', key: 'telegram' },
    sms:               { icon: 'mdi-message-text-outline', color: 'indigo-darken-1',  key: 'sms' },
    whatsapp:          { icon: 'mdi-whatsapp',         color: 'green-darken-1',      key: 'whatsapp' },
    whatsapp_template: { icon: 'mdi-whatsapp',         color: 'orange-darken-2',     key: 'whatsapp_template' },
    email:             { icon: 'mdi-email-outline',    color: 'blue-grey-darken-1',  key: 'email' },
    email_extra:       { icon: 'mdi-email-plus-outline', color: 'blue-grey-darken-1', key: 'email_extra' },
    letter:            { icon: 'mdi-email-newsletter',  color: 'brown-darken-1',      key: 'letter' },
    letter_extra:      { icon: 'mdi-email-newsletter',  color: 'brown-darken-1',      key: 'letter_extra' },
    none:              { icon: 'mdi-account-off-outline', color: 'grey',             key: 'none' },
}

/**
 * Verfügbarkeit der einzelnen Kanäle für einen Empfänger
 *
 * @param {Object} r        Empfänger aus getMailing/searchMailingRecipients
 * @param {Object} settings { channels, whatsapp_template_id, email_mode }
 * @param {Object} config   Firmenweite Verfügbarkeit { telegram, whatsapp, email }
 */
export function availability(r, settings, config) {
    const whatsappPhone = !!r.whatsapp_phone && !!config?.whatsapp
    return {
        telegram: !!r.telegram_chat_id && !!config?.telegram,
        whatsapp: whatsappPhone && !!r.whatsapp_window,
        whatsapp_template: whatsappPhone && !r.whatsapp_window && !!settings?.whatsapp_template_id,
        sms: !!r.whatsapp_phone && !!config?.sms,
        email: !!r.email && !!config?.email,
        letter: !!r.address_ok && !!config?.letter,
    }
}

/**
 * Geplanter Kanal eines Empfängers (ohne Dublettenprüfung)
 *
 * @return {{channel: string|null, target: string|null, email: string|null, letter: string|null}}
 *         channel/target = Hauptkanal (Messenger, E-Mail oder Brief), email/letter = zusätzliche Wege daneben
 */
export function planChannel(r, settings, config) {
    const a = availability(r, settings, config)
    const mode = settings?.email_mode || 'always'
    const letterMode = settings?.letter_mode || 'off'
    const emailOk = a.email && mode !== 'off' ? r.email : null
    const letterOk = a.letter && letterMode !== 'off' ? r.address : null
    // Kandidaten in der gewählten Reihenfolge; dann wie im Backend umsortiert: gelernter
    // Kanal zuerst, zuletzt gescheiterter nachrangig, unbestätigte WhatsApp-Vorlage zuletzt
    const candidates = []
    ;(settings?.channels || []).forEach((ch, n) => {
        if (ch === 'telegram' && a.telegram) candidates.push({ channel: 'telegram', target: r.telegram_chat_id, n })
        else if (ch === 'whatsapp' && a.whatsapp) candidates.push({ channel: 'whatsapp', target: r.whatsapp_phone, n })
        else if (ch === 'whatsapp' && a.whatsapp_template) candidates.push({ channel: 'whatsapp_template', target: r.whatsapp_phone, n })
        else if (ch === 'sms' && a.sms) candidates.push({ channel: 'sms', target: r.whatsapp_phone, n })
    })
    const base = c => c.channel.replace('_template', '')
    candidates.sort((x, y) =>
        ((base(x) === r.reach_channel ? 0 : 1) - (base(y) === r.reach_channel ? 0 : 1)) ||
        ((base(x) === r.reach_failed ? 1 : 0) - (base(y) === r.reach_failed ? 1 : 0)) ||
        ((x.channel === 'whatsapp_template' && !r.whatsapp_known ? 1 : 0) - (y.channel === 'whatsapp_template' && !r.whatsapp_known ? 1 : 0)) ||
        (x.n - y.n))
    const messenger = candidates.length ? { channel: candidates[0].channel, target: candidates[0].target } : null
    const letterExtra = letterMode === 'always' ? letterOk : null
    if (messenger) return { ...messenger, email: mode === 'always' ? emailOk : null, letter: letterExtra }
    if (emailOk) return { channel: 'email', target: emailOk, email: null, letter: letterExtra }
    if (letterOk) return { channel: 'letter', target: letterOk, email: null, letter: null }
    return { channel: null, target: null, email: null, letter: null }
}

/**
 * Plan für alle Empfänger inklusive Dubletten und Zusammenfassung
 *
 * @return {{ byKey: Map<string, {channel, target, duplicate}>, counts: Object, planned: number, none: number, duplicates: number }}
 */
export function planAll(recipients, settings, config) {
    const byKey = new Map()
    const seen = new Set()
    const counts = { telegram: 0, whatsapp: 0, whatsapp_template: 0, sms: 0, email: 0, email_extra: 0, letter: 0, letter_extra: 0 }
    let none = 0
    let duplicates = 0
    const plans = []
    for (const r of recipients) {
        const p = planChannel(r, settings, config)
        let duplicate = false
        if (p.channel) {
            const sig = p.channel.replace('_template', '') + ':' + String(p.target).toLowerCase()
            if (seen.has(sig)) { duplicate = true; duplicates++ }
            else { seen.add(sig); counts[p.channel]++ }
        } else {
            none++
        }
        plans.push([r, { ...p, duplicate }])
    }
    // Zusatz-E-Mail je Adresse nur einmal – und nicht, wenn sie schon Hauptkanal eines anderen ist (wie im Backend)
    const emailSeen = new Set(plans.filter(([, p]) => p.channel === 'email' && !p.duplicate).map(([, p]) => String(p.target).toLowerCase()))
    const letterSeen = new Set(plans.filter(([, p]) => p.channel === 'letter' && !p.duplicate).map(([, p]) => String(p.target).toLowerCase()))
    for (const [r, p] of plans) {
        if (p.email && !p.duplicate) {
            const sig = String(p.email).toLowerCase()
            if (emailSeen.has(sig)) p.email = null
            else { emailSeen.add(sig); counts.email_extra++ }
        } else {
            p.email = null
        }
        if (p.letter && !p.duplicate) {
            const sig = String(p.letter).toLowerCase()
            if (letterSeen.has(sig)) p.letter = null
            else { letterSeen.add(sig); counts.letter_extra++ }
        } else {
            p.letter = null
        }
        byKey.set(recipientKey(r), p)
    }
    const planned = counts.telegram + counts.whatsapp + counts.whatsapp_template + counts.sms + counts.email + counts.letter
    return { byKey, counts, planned, none, duplicates }
}

export function recipientKey(r) {
    return `${r.cv_src}${r.cv_id}`
}

/**
 * Voraussichtliche Dauer des Versands in Minuten (ohne Zeitfenster)
 */
export function estimateMinutes(planned, batchSize, pauseMinutes) {
    if (!planned) return 0
    const size = Math.max(1, Number(batchSize) || 1)
    const batches = Math.ceil(planned / size)
    // ~3 s je Nachricht innerhalb einer Etappe plus Pausen zwischen den Etappen
    return Math.round((planned * 3) / 60 + (batches - 1) * (Number(pauseMinutes) || 0))
}

/**
 * Platzhalter mit den Werten eines Empfängers füllen (Vorschau)
 */
export function fillPlaceholders(text, r, ctx = {}) {
    return String(text || '')
        .replaceAll('{{anrede}}', r?.salutation || '')
        .replaceAll('{{name}}', r?.name || '')
        .replaceAll('{{kundennummer}}', r?.number || '')
        .replaceAll('{{firma}}', ctx.company || '')
        .replaceAll('{{mitarbeiter}}', ctx.employee || '')
}
