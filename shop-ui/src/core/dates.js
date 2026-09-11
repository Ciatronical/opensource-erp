/**
 * Datumsangaben des Backends einheitlich ausgeben.
 *
 * Die Shop-Erweiterung liefert rohe Daten ('2026-03-15'); formatiert wird in
 * der Oberfläche, in der Sprache des Dokuments.
 */

const LOCALES = { de: 'de-DE', en: 'en-GB' };

/** Reines Datum ohne Uhrzeit */
const NUR_DATUM = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Backend-Datum -> Anzeigetext in der Sprache des Dokuments.
 *
 * Ein reines Datum wird als Ortszeit gelesen: new Date('2026-03-15') hieße
 * Mitternacht UTC und zeigte westlich von Greenwich den Vortag.
 *
 * Ein unlesbarer Wert wird durchgereicht statt verschluckt: eine falsche
 * Anzeige fällt auf, eine leere nicht.
 */
export function formatDate(value) {
  if (!value) return '';

  const raw = String(value).trim();
  const tag = NUR_DATUM.exec(raw);
  const parsed = tag ? new Date(Number(tag[1]), Number(tag[2]) - 1, Number(tag[3])) : new Date(raw);
  if (Number.isNaN(parsed.getTime())) return raw;

  const lang = (document.documentElement.lang || 'de').slice(0, 2).toLowerCase();
  return parsed.toLocaleDateString(LOCALES[lang] || LOCALES.de);
}
