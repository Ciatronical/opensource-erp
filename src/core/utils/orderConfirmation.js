// src/core/utils/orderConfirmation.js

/**
 * Auftragsbestätigung: Wege, auf denen ein Kunde zusagt
 *
 * Gleiche Liste wie die CHECK-Bedingung von oe_confirmation_oserp.channel
 * (backend/upstall/crm/company_schema.sql). Beschriftungen stehen unter
 * FakturaView.faktura.confirmation.channel.<value>.
 */
export const CONFIRMATION_CHANNELS = [
    { value: 'phone', icon: 'mdi-phone' },
    { value: 'email', icon: 'mdi-email-outline' },
    { value: 'whatsapp', icon: 'mdi-whatsapp' },
    { value: 'visit', icon: 'mdi-account-voice' },
    { value: 'signature', icon: 'mdi-draw-pen' },
    { value: 'portal', icon: 'mdi-web' },
    { value: 'other', icon: 'mdi-dots-horizontal-circle-outline' },
]

/**
 * Symbol eines Kanals
 *
 * @param {string} channel Wert aus CONFIRMATION_CHANNELS
 * @returns {string} mdi-Symbol
 */
export function confirmationChannelIcon(channel) {
    return CONFIRMATION_CHANNELS.find(c => c.value === channel)?.icon || 'mdi-dots-horizontal-circle-outline'
}
