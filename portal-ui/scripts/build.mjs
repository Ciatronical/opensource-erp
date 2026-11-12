/**
 * Baut die Bündel der Widgets in das Paket des Kundenportals.
 *
 * Drei Einstiege, viele Stücke (esbuild splitting):
 *   portal-widgets.js   Kern: Anmeldung, Registrierung, Konto, Kontakt, Freischaltung
 *   shop-widgets.js     Shop: Warenkorb, Kasse, Suche, Rechnung, Werkzeugsuche
 *   lxcars-widgets.js   LxCars: Fahrzeuge des Kunden
 *   chunk-<hash>.js     je Widget eines (dynamischer Import in den Einstiegen,
 *                       core/lazy.js lädt es erst, wenn das Element auf der
 *                       Seite steht), je Sprache eines (src/i18n/) und was
 *                       sich alle teilen (Lit, Basisklassen, API)
 *   manifest.json       welche Stücke ein Widget bzw. ein Einstieg braucht —
 *                       der Partial portal-ui-preload.html setzt daraus
 *                       <link rel="modulepreload"> für die Widgets der Seite,
 *                       damit kein Stück erst nach dem vorigen entdeckt wird
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
import { basename, join } from 'node:path';
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
  metafile: true,
  supported: { 'template-literal': false },
  logLevel: 'warning',
};

/**
 * manifest.json aus der Metadatei: Schlüssel → Dateinamen, die vorzuladen
 * sind. Schlüssel sind die Einstiege (portal-widgets: ihre statischen
 * Stücke), die Elemente (portal-login: das Stück des Widgets samt allem, was
 * es statisch importiert) und die Sprachen (i18n-de). Ein Stück, das zwei
 * Elemente enthält, steht unter beiden.
 */
function manifest(meta) {
  const ausgaben = Object.entries(meta.outputs).filter(([pfad]) => pfad.endsWith('.js'));
  const statisch = new Map(ausgaben.map(([pfad, ausgabe]) => [
    pfad, ausgabe.imports.filter((i) => i.kind === 'import-statement').map((i) => i.path),
  ]));
  const abschluss = (start) => {
    const gesehen = new Set();
    const offen = [start];
    while (offen.length) {
      const pfad = offen.pop();
      if (gesehen.has(pfad)) continue;
      gesehen.add(pfad);
      offen.push(...(statisch.get(pfad) || []));
    }
    return [...gesehen].map((pfad) => basename(pfad)).sort();
  };
  // entryPoint trägt esbuild auch an dynamisch importierten Stücken (die
  // Quelldatei des Widgets bzw. der Sprache); inputs können dort leer sein,
  // wenn der Code in einem geteilten Stück liegt, weil ein anderes Widget
  // dasselbe statisch importiert.
  const eintraege = {};
  for (const [pfad, ausgabe] of ausgaben) {
    const quelle = ausgabe.entryPoint || '';
    const widget = quelle.match(/src\/components\/([a-z-]+)\.js$/);
    const sprache = quelle.match(/src\/i18n\/([a-z]+)\.js$/);
    const einstieg = quelle.match(/src\/[a-z]+\.js$/);
    const key = widget ? widget[1] : sprache ? 'i18n-' + sprache[1] : einstieg ? basename(pfad, '.js') : null;
    if (!key) continue;
    // Der Einstieg selbst steht bereits als <script>; vorzuladen sind nur seine Stücke
    eintraege[key] = abschluss(pfad).filter((d) => !einstieg || d !== basename(pfad));
  }
  return JSON.stringify({
    _hinweis: 'Von portal-ui/scripts/build.mjs geschrieben — Stücke je Widget, Einstieg und Sprache für modulepreload (partials/portal-ui-preload.html).',
    ...Object.fromEntries(Object.entries(eintraege).sort(([a], [b]) => a.localeCompare(b))),
  }, null, 2) + '\n';
}

mkdirSync(OUTDIR, { recursive: true });

if (watch) {
  const ctx = await context(optionen);
  await ctx.watch();
  console.log(`portal-ui: baue nach ${OUTDIR} bei jeder Änderung neu …`);
} else if (dev) {
  const ergebnis = await build(optionen);
  writeFileSync(join(OUTDIR, 'manifest.json'), manifest(ergebnis.metafile));
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
  writeFileSync(join(OUTDIR, 'manifest.json'), manifest(ergebnis.metafile));
  console.log(`portal-ui: ${join(OUTDIR, 'manifest.json')}`);
}
