/**
 * Prüft die eingecheckten Bündel darauf, ob sie tools/fix-ws.sh unbeschadet
 * überstehen und ob Lits Attribut-Regex vollständig ist.
 *
 *   node scripts/check-bundle.mjs [datei …]   (ohne Angabe: alle Bündel im Paket)
 *
 * Läuft nach jedem npm run build; nach einem Commit lässt sich mit
 * npm run check nachsehen, ob ein Bündel dabei verändert wurde.
 * Beendet sich mit Status 1, wenn etwas nicht stimmt.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const kit = fileURLToPath(new URL(
  '../../backend/templates-default/portal/standard/kit/assets/portal-ui/',
  import.meta.url,
));
const dateien = process.argv.length > 2
  ? process.argv.slice(2)
  : readdirSync(kit).filter((d) => d.endsWith('.js')).map((d) => join(kit, d));

if (!dateien.length) {
  console.error(`portal-ui: keine Bündel unter ${kit} — bauen mit npm run build`);
  process.exit(1);
}

let litRegex = false;
const fehler = [];
for (const datei of dateien) {
  const code = readFileSync(datei, 'utf8');
  const name = datei.slice(datei.lastIndexOf('/') + 1);

  // Das würde fix-ws ändern: Tabulatoren und Leerraum am Zeilenende
  // (sed [[:space:]] umfasst auch \r, \v und \f).
  const tabs = (code.match(/\t/g) || []).length;
  if (tabs) fehler.push(`${name}: ${tabs} Tabulator(en) — fix-ws ersetzt sie durch Leerzeichen`);
  code.split('\n').forEach((zeile, i) => {
    if (/[ \t\r\v\f]$/.test(zeile)) {
      fehler.push(`${name}: Zeile ${i + 1} endet mit Leerraum — fix-ws entfernt ihn: …${JSON.stringify(zeile.slice(-40))}`);
    }
  });
  // Lit trennt Attribute mit diesem Zeichensatz. Fehlen Leerzeichen oder
  // \t, erkennt Lit keine Attribut-Bindungen mehr. Lit liegt im geteilten
  // Stück — eines der Bündel muss die Regex tragen.
  if (code.includes('"[ \\t\\n\\f\\r]"')) litRegex = true;
}
if (!litRegex) {
  fehler.push('Lits Attribut-Regex "[ \\t\\n\\f\\r]" fehlt in allen Bündeln oder ist verändert — Widgets zeigen dann lit$…$ als Text');
}

if (fehler.length) {
  console.error('portal-ui: die Bündel sind nicht in Ordnung:');
  for (const f of fehler.slice(0, 20)) console.error(`  - ${f}`);
  if (fehler.length > 20) console.error(`  … und ${fehler.length - 20} weitere`);
  console.error('Neu bauen mit npm run build (in portal-ui/) bzw. npm run build:portal-ui.');
  process.exit(1);
}
console.log(`portal-ui: ${dateien.length} Bündel geprüft — überstehen fix-ws, Lit-Regex vollständig.`);
