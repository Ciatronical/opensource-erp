// src/core/utils/cvContactAddresses.js
//
// Sammelt die E-Mail-Adressen eines Kunden/Lieferanten aus Profil und
// Ansprechpersonen (Store-Daten, kein Backend-Call). Wird von der
// Kontaktdaten-Karte (E-Mail-Tab) und der Kontakthistorie gemeinsam genutzt,
// damit beide dieselben Adressen an getEmails schicken.

/**
 * @param {object|null|undefined} profile  customer_vendor.profile (Feld email, ggf. mit ; oder , getrennt)
 * @param {Array<object>} contacts         customer_vendor.contacts (Feld cp_email)
 * @returns {string[]} eindeutige, getrimmte Adressen in Reihenfolge des Auftretens
 */
export function collectEmailAddresses(profile, contacts = []) {
    const addrs = []
    const add = (raw) => {
        if (!raw) return
        String(raw).split(/[;,]/).forEach(e => {
            const trimmed = e.trim()
            if (trimmed && !addrs.includes(trimmed)) addrs.push(trimmed)
        })
    }
    add(profile?.email)
    contacts.forEach(c => add(c.cp_email))
    return addrs
}
