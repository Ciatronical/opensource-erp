// src/core/views/mailings/composables/listCriteria.js
//
// Lesbare Kurzfassung der Kriterien eines dynamischen Verteilers
// ({src, business_id, months, city, channel}) – für Chips, Tooltips und Listen.
export function criteriaSummary(c, t, businesses = []) {
    if (!c) return ''
    const parts = []
    parts.push(c.src === 'C' ? t('MailingRecipients.customers') : c.src === 'V' ? t('MailingRecipients.vendors') : t('MailingRecipients.all'))
    if (c.business_id) {
        const b = businesses.find(x => Number(x.id) === Number(c.business_id))
        if (b) parts.push(b.description)
    }
    if (c.months) parts.push(t('MailingRecipients.criteriaMonths', { n: c.months }))
    if (c.city) parts.push(t('MailingRecipients.criteriaCity', { city: c.city }))
    if (c.channel && c.channel !== 'any') parts.push(t('MailingRecipients.suggestChannels.' + c.channel))
    return parts.join(' · ')
}
