/**
 * Prüft das eingecheckte Bündel darauf, ob es tools/fix-ws.sh unbeschadet
 * übersteht und ob Lits Attribut-Regex vollständig ist.
 *
 *   node scripts/check-bundle.mjs [datei]    (ohne Angabe: das Bündel im Site-Kit)
 *
 * Läuft nach jedem npm run build; nach einem Commit lässt sich mit
 * npm run check nachsehen, ob das Bündel dabei verändert wurde.
 * Beendet sich mit Status 1, wenn etwas nicht stimmt.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const datei = process.argv[2] ?? fileURLToPath(new URL(
  '../../backend/templates-default/shop/standard/kit/assets/shop-ui/shop-widgets.js',
  import.meta.url,
));
const code = readFileSync(datei, 'utf8');
const fehler = [];

// Das würde fix-ws ändern: Tabulatoren und Leerraum am Zeilenende
// (sed [[:space:]] umfasst auch \r, \v und \f).
const tabs = (code.match(/\t/g) || []).length;
if (tabs) fehler.push(`${tabs} Tabulator(en) — fix-ws ersetzt sie durch Leerzeichen`);

const zeilen = code.split('\n');
zeilen.forEach((zeile, i) => {
  if (/[ \t\r\v\f]$/.test(zeile)) {
    fehler.push(`Zeile ${i + 1} endet mit Leerraum — fix-ws entfernt ihn: …${JSON.stringify(zeile.slice(-40))}`);
  }
});

// Zeigt, ob das Bündel schon beschädigt ist: Lit trennt Attribute mit
// diesem Zeichensatz. Fehlen Leerzeichen oder \t, erkennt Lit keine
// Attribut-Bindungen mehr.
if (!code.includes('"[ \\t\\n\\f\\r]"')) {
  fehler.push('Lits Attribut-Regex "[ \\t\\n\\f\\r]" fehlt oder ist verändert — Widgets zeigen dann lit$…$ als Text');
}

if (fehler.length) {
  console.error(`shop-ui: ${datei} ist nicht in Ordnung:`);
  for (const f of fehler.slice(0, 20)) console.error(`  - ${f}`);
  if (fehler.length > 20) console.error(`  … und ${fehler.length - 20} weitere`);
  console.error('Neu bauen mit npm run build (in shop-ui/) bzw. npm run build:shop-ui.');
  process.exit(1);
}
console.log(`shop-ui: ${datei} geprüft — übersteht fix-ws, Lit-Regex vollständig.`);
