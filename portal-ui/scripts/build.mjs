/**
 * Baut die Bündel der Widgets in das Paket des Kundenportals.
 *
 * Drei Einstiege, ein geteiltes Stück (esbuild splitting):
 *   portal-widgets.js   Kern: Anmeldung, Registrierung, Konto, Kontakt, Freischaltung
 *   shop-widgets.js     Shop: Warenkorb, Kasse, Suche, Rechnung, Werkzeugsuche
 *   lxcars-widgets.js   LxCars: Fahrzeuge des Kunden
 *   chunk-<hash>.js     was sich die Einstiege teilen (Lit, Basisklassen, API)
 * Welche Bündel eine Webseite lädt, sagt ihr features.json (lib/kit.php in
 * OpensourceERP); der Partial portal-ui-assets.html liest es.
 *
 *   node scripts/build.mjs            Paket (minifiziert, eingecheckt)
 *   node scripts/build.mjs --dev      dist/ für die Testseite, mit Sourcemaps
 *   node scripts/build.mjs --dev --watch
 *
 * Das Paket-Bündel wird eingecheckt und läuft dabei durch tools/fix-ws.sh,
 * das Leerzeichen am Zeilenende entfernt und Tabulatoren ersetzt. Ein
 * gewöhnlicher esbuild-Build übersteht das nicht: esbuild schreibt die
 * Escapes \t und \n als echte Zeichen, Lits Attribut-Regex "[ \t\n\f\r]"
 * wird zu `[ <Tab><Zeilenumbruch>\f\r]`, fix-ws macht daraus `[\n\f\r]` —
 * und die Widgets zeigen lit$…$-Platzhalter und Klassennamen als Text.
 *
 * Deshalb:
 *  - keine Template-Literale in der Ausgabe (auch Lits html`…` wird zu
 *    Zeichenketten), damit kein echter Zeilenumbruch in einem Literal steht;
 *  - die verbliebenen echten Tabulatoren als \t schreiben. In minifiziertem
 *    Code stehen sie nur noch in Zeichenketten, Regex-Literalen oder
 *    Kommentaren — überall dort bedeutet \t dasselbe.
 *
 * Danach prüft scripts/check-bundle.mjs das Ergebnis (npm run build).
 */

import { build, context } from 'esbuild';
import { mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const dev = process.argv.includes('--dev');
const watch = process.argv.includes('--watch');

export const KIT_DIR = fileURLToPath(new URL(
  '../../backend/templates-default/portal/standard/kit/assets/portal-ui/',
  import.meta.url,
));
const OUTDIR = dev ? fileURLToPath(new URL('../dist/', import.meta.url)) : KIT_DIR;

const quelle = (datei) => fileURLToPath(new URL(`../src/${datei}`, import.meta.url));

const optionen = {
  entryPoints: {
    'portal-widgets': quelle('portal.js'),
    'shop-widgets': quelle('shop.js'),
    'lxcars-widgets': quelle('lxcars.js'),
  },
  bundle: true,
  format: 'esm',
  splitting: true,
  outdir: OUTDIR,
  entryNames: '[name]',
  chunkNames: 'chunk-[hash]',
  minify: !dev,
  sourcemap: dev,
  supported: { 'template-literal': false },
  logLevel: 'warning',
};

mkdirSync(OUTDIR, { recursive: true });

if (watch) {
  const ctx = await context(optionen);
  await ctx.watch();
  console.log(`portal-ui: baue nach ${OUTDIR} bei jeder Änderung neu …`);
} else if (dev) {
  await build(optionen);
  console.log(`portal-ui: ${OUTDIR} (Entwicklung, mit Sourcemaps)`);
} else {
  // Alte geteilte Stücke verschwinden: ihr Name trägt den Hash des Inhalts
  for (const datei of readdirSync(OUTDIR)) {
    if (/^chunk-[A-Z0-9]+\.js$/i.test(datei)) rmSync(join(OUTDIR, datei));
  }
  const ergebnis = await build({ ...optionen, write: false });
  for (const ausgabe of ergebnis.outputFiles) {
    const code = ausgabe.text.replace(/\t/g, '\\t');
    writeFileSync(ausgabe.path, code);
    console.log(`portal-ui: ${ausgabe.path} (${(Buffer.byteLength(code) / 1024).toFixed(1)} kB)`);
  }
}
