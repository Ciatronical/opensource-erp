// Reparaturzeit (docs/features/lxcars.md → Reparaturzeit): Minuten ↔ Text
//
// Gespeichert werden Minuten (oe_ext/ar_ext.reparaturzeit_minuten). Angezeigt
// wird „5 Tage“, „2 Tage 8 Std“, „4 Std 30 Min“ oder „45 Min“. Die Eingabe
// versteht Tage/Stunden/Minuten in den Schreibweisen der 21 Oberflächensprachen,
// „1:30“ als Stunden:Minuten und eine nackte Zahl als Stunden.

const MINUTES_PER_DAY = 1440

const UNIT_DAY  = /^(d|t|tag|tage|day|days|dag|dage|dagen|dagar|jour|jours|d[ií]a|d[ií]as|giorno|giorni|dzie[nń]|dni|den|dny|dn[íi]|zi|zile|p[äa]iv[äa]+|g[üu]n|diena|dienas|dienos|dien[ųu]|p[aä]ev|p[aä]eva|дн|дні|день|дней|дня|днів|天)\.?$/i
const UNIT_HOUR = /^(h|std|stunde|stunden|hour|hours|hr|hrs|heure|heures|hora|horas|ora|ore|uur|timer|time|timmar|timme|tim|godz|godzin|godziny|hod|hodin|hodina|hodiny|or[ăa]|tunti|tuntia|saat|val|valanda|valandos|stund|stundas|tund|tundi|ч|час|часа|часов|год|годин|години|小时|時間)\.?$/i
const UNIT_MIN  = /^(m|min|mins|minute|minuten|minutes|minuto|minutos|minuti|minuut|minuter|minutter|minut|minuty|minuta|minuutti|minuuttia|dakika|dk|minut[ėe]s|minūtes|minutit|мин|хв|分|分钟)\.?$/i

/**
 * Minuten als lesbaren Text
 * @param {number|string|null} minutes
 * @param {Function} t vue-i18n
 * @returns {string} leer, wenn keine Zeit vorliegt
 */
export function formatRepairTime(minutes, t) {
    const total = Math.round(Number(minutes))
    if (!total || total <= 0) return ''
    const days  = Math.floor(total / MINUTES_PER_DAY)
    const hours = Math.floor((total % MINUTES_PER_DAY) / 60)
    const mins  = total % 60
    const parts = []
    if (days)  parts.push(`${days} ${t(days === 1 ? 'FakturaView.faktura.repairTime.units.day' : 'FakturaView.faktura.repairTime.units.days')}`)
    if (hours) parts.push(`${hours} ${t('FakturaView.faktura.repairTime.units.hours')}`)
    if (mins && !days) parts.push(`${mins} ${t('FakturaView.faktura.repairTime.units.min')}`)
    return parts.join(' ')
}

/**
 * Eingabetext in Minuten
 * @param {string} text  z. B. „3 Tage“, „4,5 Std“, „1 Tag 2 Std“, „1:30“, „90 min“, „5“ (= Stunden)
 * @returns {number|null|NaN} null bei leerer Eingabe, NaN wenn nicht verstanden
 */
export function parseRepairTime(text) {
    const raw = String(text ?? '').trim().toLowerCase()
    if (raw === '') return null

    const clock = /^(\d{1,3}):([0-5]?\d)$/.exec(raw)
    if (clock) return Number(clock[1]) * 60 + Number(clock[2])

    const tokenRe = /(\d+(?:[.,]\d+)?)\s*([^\d\s.,]*)\.?/g
    let minutes = 0
    let matched = 0
    let consumed = ''
    let m
    while ((m = tokenRe.exec(raw)) !== null) {
        const value = parseFloat(m[1].replace(',', '.'))
        const unit  = m[2]
        if (unit === '') {
            // nackte Zahl: nur als einziges Element erlaubt → Stunden
            if (raw.replace(/[.,]/g, '').trim() !== m[1].replace(/[.,]/g, '')) return NaN
            return Math.round(value * 60)
        }
        if (UNIT_DAY.test(unit))       minutes += value * MINUTES_PER_DAY
        else if (UNIT_HOUR.test(unit)) minutes += value * 60
        else if (UNIT_MIN.test(unit))  minutes += value
        else return NaN
        matched++
        consumed += m[0]
    }
    if (!matched) return NaN
    // Reste außer Leerzeichen und Trennern sind kein Zeitausdruck
    if (raw.replace(/\s+/g, '') !== consumed.replace(/\s+/g, '')) return NaN
    return Math.round(minutes)
}

/** Tage laut Gutachten/Kalkulation → Minuten */
export function repairDaysToMinutes(days) {
    const d = Number(days)
    return d > 0 ? Math.round(d * MINUTES_PER_DAY) : null
}
