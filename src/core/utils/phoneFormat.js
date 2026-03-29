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
