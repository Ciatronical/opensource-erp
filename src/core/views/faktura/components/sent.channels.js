// src/core/views/faktura/components/sent.channels.js
//
// Darstellung der Versandkanäle eines Belegs (Symbol, Farbe, Sprachschlüssel).
// Eine Stelle für den Versandstatus der Belegmaske (sent.status.component.vue)
// und die Spalte „Status“ der Belegkarte im CRM (occurrence.sent.component.vue).
// Die Kanäle selbst liefert die Datenbank (record_sent_log / sent_log).

export const channelMeta = {
    email:    { icon: 'mdi-email',          color: 'info',           label: 'FakturaView.faktura.sent.channelEmail' },
    whatsapp: { icon: 'mdi-whatsapp',       color: 'green-darken-1', label: 'FakturaView.faktura.sent.channelWhatsapp' },
    sms:      { icon: 'mdi-message-text-outline', color: 'indigo', label: 'FakturaView.faktura.sent.channelSms' },
    dhl:      { icon: 'mdi-truck-delivery', color: 'amber-darken-2', label: 'FakturaView.faktura.sent.channelDhl' },
    post:     { icon: 'mdi-mailbox-up-outline', color: 'deep-purple', label: 'FakturaView.faktura.sent.channelPost' },
    print:    { icon: 'mdi-printer-check',  color: 'blue-grey',      label: 'FakturaView.faktura.sent.channelPrint' },
}

/** Status einer Posteinlieferung (postal_dispatches.status) */
export function postStatusMeta(status) {
    switch (status) {
        case 'done':     return { icon: 'mdi-check-all',      color: 'success', label: 'FakturaView.faktura.sent.postDone' }
        case 'manual':   return { icon: 'mdi-printer-check',  color: 'grey',    label: 'FakturaView.faktura.sent.postManual' }
        case 'canceled': return { icon: 'mdi-cancel',         color: 'warning', label: 'FakturaView.faktura.sent.postCanceled' }
        default:         return { icon: 'mdi-mailbox-up-outline', color: 'grey', label: 'FakturaView.faktura.sent.postSent' }
    }
}

/** Zustellstatus einer WhatsApp-Nachricht oder SMS */
export function waStatusMeta(status) {
    switch (status) {
        case 'read':      return { icon: 'mdi-check-all',    color: 'blue',   label: 'FakturaView.faktura.sent.statusRead' }
        case 'delivered': return { icon: 'mdi-check-all',    color: 'grey',   label: 'FakturaView.faktura.sent.statusDelivered' }
        case 'failed':    return { icon: 'mdi-alert-circle', color: 'error',  label: 'FakturaView.faktura.sent.statusFailed' }
        default:          return { icon: 'mdi-check',        color: 'grey',   label: 'FakturaView.faktura.sent.statusSent' }
    }
}
