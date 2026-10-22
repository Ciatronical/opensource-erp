/**
 * Ziele aus dem Backend auf den eigenen Ursprung zurechtstutzen.
 *
 * fastSearch, moreSearchResults und fullSearch liefern `hyperlink` als
 * vollstaendige Adresse mit Domain (https://sonic24.de/produkt/…). Auf einer
 * anderen Instanz — der Entwicklungsseite etwa — fuehrt jeder Treffer damit
 * aus dem Shop heraus. Gleiche Herkunft bleibt unveraendert, fremde wird auf
 * Pfad, Query und Fragment gekuerzt.
 */
export function sameOriginHref(raw) {
  const value = String(raw || '');
  if (!value) return '';
  try {
    const url = new URL(value, window.location.origin);
    if (url.origin === window.location.origin) return url.href;
    return url.pathname + url.search + url.hash;
  } catch {
    return value;
  }
}

/** Suchbegriff aus der Adresszeile (?terms=…). */
export function searchTerms(search) {
  const value = new URLSearchParams(search || '').get('terms');
  return value ? value.trim() : '';
}
