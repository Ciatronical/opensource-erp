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

  // Tausendertrennzeichen nur entfernen wenn danach exakt 3 Ziffern folgen (z.B. 1.000, 10.500)
  // Damit wird 1.19 nicht fälschlich zu 119
  if (group) {
    const groupEscaped = group.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    normalized = normalized.replace(new RegExp(groupEscaped + '(?=\\d{3}(?!\\d))', 'g'), '')
  }

  // Alle Dezimaltrennzeichen ersetzen (wichtig für Ausdrücke wie 1,5+2,3)
  if (decimal !== '.') {
    normalized = normalized.split(decimal).join('.')
  }

  // Rechenausdruck erkennen (z.B. "55+44", "100/1.19", "100*1.19-5")
  if (/^[\d.]+\s*[+\-*/]\s*[\d.+\-*/\s]+$/.test(normalized)) {
    try {
      const result = Function('"use strict"; return (' + normalized + ')')()
      return Number.isFinite(result) ? result : null
    } catch { return null }
  }

  const n = Number(normalized)
  return Number.isFinite(n) ? n : null
}
