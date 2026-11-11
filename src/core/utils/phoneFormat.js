import { parsePhoneNumberFromString } from 'libphonenumber-js'

/**
 * Formats a phone number with visual separation between country code,
 * area code and subscriber number using libphonenumber-js.
 * Falls back to the raw input if parsing fails.
 *
 * @param {string} phone - Raw phone number string
 * @param {string} [defaultCountry='DE'] - Default country code for parsing
 * @returns {string} Formatted phone number or original input
 */
export function formatPhone(phone, defaultCountry = 'DE') {
  if (!phone) return ''
  const parsed = parsePhoneNumberFromString(String(phone), defaultCountry)
  if (!parsed) return phone
  return parsed.formatInternational()
}

// Mobilfunk-Vorwahlen DE (015x/016x/017x, auch ohne Ländervorwahl), AT (+436…), CH (+417…).
// AT/CH nur mit Ländervorwahl – national geschrieben kollidieren sie mit deutschem Festnetz (069, 0711 …).
const MOBILE_RE = /^(\+491[5-7]|01[5-7]|\+436[^4]|\+417[^2])/

/**
 * Prüft, ob eine Telefonnummer eine Handynummer ist (WhatsApp/SMS gehen nur dorthin).
 *
 * @param {string} phone - Telefonnummer in beliebiger Schreibweise
 * @returns {boolean}
 */
export function isMobilePhone(phone) {
  const n = String(phone || '')
    .replace(/[\s\-/().]/g, '')
    .replace(/^00/, '+')                 // 0049… → +49…
    .replace(/^\+(49|43|41)0/, '+$1')    // +49 (0)171… → +49171…
  return MOBILE_RE.test(n)
}

/**
 * Liefert die erste Handynummer der Liste, sonst die erste Nummer überhaupt.
 *
 * @param {string[]} phones - Telefonnummern
 * @returns {string}
 */
export function pickMobilePhone(phones) {
  const list = (phones || []).filter(Boolean)
  return list.find(isMobilePhone) || list[0] || ''
}
