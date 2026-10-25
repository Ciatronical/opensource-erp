export const formatDate = (dateString, locale = 'de') => {
  if (!dateString) return ''

  const localeMap = {
    'de': 'de-DE',
    'en': 'en-US',
    'fr': 'fr-FR',
    'es': 'es-ES',
    // weitere Sprachen nach Bedarf
  }

  return new Date(dateString).toLocaleDateString(localeMap[locale] || 'de-DE', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  })
}

export const formatDateTime = (dateTimeString, locale = 'de') => {
  if (!dateTimeString) return ''

  const localeMap = {
    'de': 'de-DE',
    'en': 'en-US',
    'fr': 'fr-FR',
    'es': 'es-ES',
    // weitere Sprachen nach Bedarf
  }

  return new Date(dateTimeString).toLocaleString(localeMap[locale] || 'de-DE', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  })
}
/**
 * Relative Zeitangabe („vor 5 Minuten“, „gestern“) in der Sprache der Oberfläche;
 * ab einer Woche Datum und Uhrzeit
 *
 * @param {string|Date} value Zeitpunkt
 * @param {string} locale Sprache (vue-i18n locale)
 * @return {string}
 */
export const formatRelativeTime = (value, locale = 'de') => {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  const diff = (d.getTime() - Date.now()) / 1000
  const abs = Math.abs(diff)
  if (abs >= 7 * 86400) {
    return d.toLocaleString(locale, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  }
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
  if (abs < 60) return rtf.format(Math.round(diff), 'second')
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
  return rtf.format(Math.round(diff / 86400), 'day')
}
