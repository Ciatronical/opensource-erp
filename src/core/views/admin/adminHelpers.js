// src/core/views/admin/adminHelpers.js

/**
 * Hilfsfunktionen der Systemadministration (Benutzer, Gruppen, Firmen)
 */

/**
 * PostgreSQL liefert Booleans je nach Weg als true/'t'/'1' — robust normalisieren
 *
 * @param {*} v - Wert
 * @return {boolean}
 */
export function isTrue(v) {
    return v === true || v === 'true' || v === 't' || v === '1' || v === 1;
}

/**
 * Vorschlag für einen Datenbanknamen aus dem Firmennamen (wie suggestDbName() im Backend)
 *
 * "Müller & Söhne GmbH" -> "mueller_soehne_gmbh"
 *
 * @param {string} companyName - Firmenname
 * @return {string}
 */
export function suggestDbName(companyName) {
    const map = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss', 'Ä': 'ae', 'Ö': 'oe', 'Ü': 'ue' };
    let s = String(companyName || '').replace(/[äöüßÄÖÜ]/g, ch => map[ch]);
    s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    s = s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (!s || !/^[a-z]/.test(s)) s = 'firma_' + s;
    return s.slice(0, 63);
}

/**
 * Prüft einen Datenbanknamen (Backend: isValidDbName)
 *
 * @param {string} name - Datenbankname
 * @return {boolean}
 */
export function isValidDbName(name) {
    return /^[a-z][a-z0-9_-]{0,62}$/.test(String(name || ''));
}

/**
 * Prüft einen Anmeldenamen (Backend: saveUser)
 *
 * @param {string} login - Anmeldename
 * @return {boolean}
 */
export function isValidLogin(login) {
    return /^[^\s/\\:*?"<>|]{1,64}$/u.test(String(login || ''));
}

/**
 * Einfache E-Mail-Prüfung
 *
 * @param {string} email - Adresse
 * @return {boolean}
 */
export function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || ''));
}

/**
 * Passwortstärke 0..4 (Länge, Zeichenklassen)
 *
 * @param {string} pw - Passwort
 * @return {number} 0 = leer/zu kurz, 4 = sehr gut
 */
export function passwordStrength(pw) {
    const s = String(pw || '');
    if (s.length < 8) return s.length === 0 ? 0 : 1;
    let classes = 0;
    if (/[a-z]/.test(s)) classes++;
    if (/[A-Z]/.test(s)) classes++;
    if (/[0-9]/.test(s)) classes++;
    if (/[^a-zA-Z0-9]/.test(s)) classes++;
    if (s.length >= 14 && classes >= 3) return 4;
    if (s.length >= 10 && classes >= 3) return 3;
    if (classes >= 2) return 2;
    return 1;
}

/**
 * Erzeugt ein gut lesbares, sicheres Passwort (ohne verwechselbare Zeichen)
 *
 * @param {number} length - Länge
 * @return {string}
 */
export function generatePassword(length = 14) {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!#%+=?';
    const bytes = new Uint32Array(length);
    crypto.getRandomValues(bytes);
    let out = '';
    for (let i = 0; i < length; i++) out += alphabet[bytes[i] % alphabet.length];
    // mindestens eine Ziffer und ein Sonderzeichen sicherstellen
    if (!/[0-9]/.test(out)) out = out.slice(0, -1) + '7';
    if (!/[!#%+=?]/.test(out)) out = out.slice(0, -2) + '!' + out.slice(-1);
    return out;
}

/**
 * Initialen für Avatare
 *
 * @param {string} name - Name oder Login
 * @return {string}
 */
export function initials(name) {
    const src = String(name || '?').trim();
    const parts = src.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return src.slice(0, 2).toUpperCase();
}

/**
 * Relative Zeitangabe über Intl.RelativeTimeFormat
 *
 * @param {string|null} ts - Zeitstempel aus der DB
 * @param {string} locale - Sprache
 * @return {string|null} null wenn kein Zeitstempel
 */
export function relativeTime(ts, locale = 'de') {
    if (!ts) return null;
    const date = new Date(ts.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return null;
    const diff = (date.getTime() - Date.now()) / 1000;
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const abs = Math.abs(diff);
    if (abs < 60) return rtf.format(Math.round(diff), 'second');
    if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
    if (abs < 86400 * 30) return rtf.format(Math.round(diff / 86400), 'day');
    return date.toLocaleDateString(locale);
}

/**
 * Anzeigename eines Benutzers (Name, sonst Login)
 *
 * @param {Object} user - Benutzer aus getAdminOverview
 * @return {string}
 */
export function userDisplayName(user) {
    return user?.config?.name || user?.login || '';
}

/**
 * Gruppiert den Rechtekatalog nach Kategorien (category = true ist eine Überschrift)
 *
 * @param {Array} masterRights - auth.master_rights sortiert nach position
 * @return {Array} [{ name, rights: [{ name, description }] }]
 */
export function groupRightsByCategory(masterRights) {
    const groups = [];
    let current = null;
    for (const r of masterRights || []) {
        if (isTrue(r.category)) {
            current = { name: r.name, description: r.description, rights: [] };
            groups.push(current);
        } else {
            if (!current) {
                // Rechte ohne vorangehende Kategorie landen unter "Sonstiges"
                const fallbackCategory = 'others';
                current = { name: fallbackCategory, description: 'Others', rights: [] };
                groups.push(current);
            }
            current.rights.push(r);
        }
    }
    return groups;
}
