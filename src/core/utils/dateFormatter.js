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