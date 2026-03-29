// src/core/utils/numberFormat.js

export function formatNumber(value, locale, digits = 2) {
  const n = typeof value === 'string' ? Number(value) : value
  if (!Number.isFinite(n)) return ''

  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(n)
}

export function parseNumber(text, locale) {
  if (!text) return null

  // Ermittelt Dezimal- und Tausendertrennzeichen für die Locale
  const example = new Intl.NumberFormat(locale).format(1111.1) // z.B. "1.111,1"
  const decimal = example.match(/1(.?)1$/)?.[1] ?? '.'
  const group = example.match(/1(.?)111/)?.[1]

  let normalized = String(text).trim()
  if (group) normalized = normalized.split(group).join('')
  normalized = normalized.replace(decimal, '.')

  // Rechenausdruck erkennen (z.B. "55+44", "119/1.19", "100*1.19-5")
  if (/^[\d.]+\s*[+\-*/]\s*[\d.+\-*/\s]+$/.test(normalized)) {
    try {
      const result = Function('"use strict"; return (' + normalized + ')')()
      return Number.isFinite(result) ? result : null
    } catch { return null }
  }

  const n = Number(normalized)
  return Number.isFinite(n) ? n : null
}
