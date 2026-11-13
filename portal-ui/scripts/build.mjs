/**
 * Baut die Widgets des Kundenportals in das Paket.
 *
 * Eine Datei je Sache, keine automatische Aufteilung:
 *   chunk-<hash>.js     der Kern (src/core/index.js: Lit, Basisklassen, API,
 *                       Bus, deutsche Texte, Kopfknöpfe) — eine Datei, auf jeder Seite
 *   chunk-<hash>.js     je Widget eine (src/components/), geladen erst, wenn
 *                       das Element auf der Seite steht (core/lazy.js)
 *   chunk-<hash>.js     je weiterer Sprache eine (src/i18n/), der Kern lädt
 *                       die der Seite; Deutsch ist der Rückfall und liegt im Kern
 *   portal-widgets.js   der Einstieg (src/portal.js): welches Stück zu welchem
 *                       Element gehört; der Partial portal-ui-assets.html
 *                       schreibt ihn in die Seite
 *   manifest.json       welche Dateien ein Widget, der Einstieg oder eine
 *                       Sprache braucht — der Partial portal-ui-preload.html
 *                       setzt daraus <link rel="modulepreload">
 *
 * esbuilds eigene Aufteilung (splitting) ordnet jedes Modul danach, welche
 * Einstiege es erreichen: der Kern zerfiel so in sieben Stücke (API, Bus,
 * Features, ein Helfer von 111 Byte, Lit, …), auf jeder Seite sieben
 * Requests. Deshalb vier Läufe nacheinander, und was ein Lauf schon gebaut
 * hat, bleibt im nächsten ein äußerer Import auf die fertige Datei:
 * Sprachen → Kern (lädt die Sprachen, trägt Deutsch) → Widgets (importieren den Kern, die
 * Kasse auch Warenkorb und Registrierung) → Einstieg (importiert Kern und
 * Widgets). Der Name trägt den Hash des Inhalts; ändert sich der Kern,
 * ändern sich alle Widgets mit, weil ihr Import den Namen enthält.
 *
 *   node scripts/build.mjs            Paket (minifiziert, eingecheckt)
 *   node scripts/build.mjs --dev      dist/ für die Testseite, mit Sourcemaps
 *   node scripts/build.mjs --dev --watch
 *
 * Das Paket wird eingecheckt und läuft dabei durch tools/fix-ws.sh, das
 * Leerzeichen am Zeilenende entfernt und Tabulatoren ersetzt. Ein
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

import { build } from 'esbuild';
import { mkdirSync, readdirSync, readFileSync, rmSync, watch, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const dev = process.argv.includes('--dev');
const beobachten = process.argv.includes('--watch');

const WURZEL = fileURLToPath(new URL('../', import.meta.url));
const SRC = join(WURZEL, 'src');
export const KIT_DIR = fileURLToPath(new URL(
  '../../backend/templates-default/portal/standard/kit/assets/portal-ui/',
  import.meta.url,
));
const OUTDIR = dev ? join(WURZEL, 'dist') : KIT_DIR;

const quelle = (datei) => join(SRC, datei);
const dateien = (ordner) => readdirSync(join(SRC, ordner))
  .filter((d) => d.endsWith('.js')).map((d) => quelle(join(ordner, d)));

/**
 * Importe auf schon gebaute Dateien bleiben außen vor (external) und zeigen
 * auf die fertige Datei: zuordnung Quellpfad bzw. 'lit' → './chunk-<hash>.js'.
 */
function aussen(zuordnung) {
  return {
    name: 'aussen',
    setup(b) {
      b.onResolve({ filter: /^lit(\/|$)/ }, () => (
        zuordnung.has('lit') ? { path: zuordnung.get('lit'), external: true } : undefined
      ));
      b.onResolve({ filter: /^\.\.?\// }, (args) => {
        const ziel = zuordnung.get(resolve(args.resolveDir, args.path));
        return ziel ? { path: ziel, external: true } : undefined;
      });
    },
  };
}

const statisch = new Map(); // Ausgabedatei → statisch importierte Dateien (manifest)

/**
 * Ein Lauf: baut die Einstiege und schreibt die Dateien.
 * @returns {Map<string, string>} Quellpfad → Name der Ausgabedatei
 */
async function lauf(entryPoints, zuordnung, entryNames = 'chunk-[hash]') {
  const ergebnis = await build({
    entryPoints,
    absWorkingDir: WURZEL,
    bundle: true,
    format: 'esm',
    outdir: OUTDIR,
    entryNames,
    minify: !dev,
    sourcemap: dev,
    metafile: true,
    write: false,
    supported: { 'template-literal': false },
    logLevel: 'warning',
    plugins: [aussen(zuordnung)],
  });
  for (const ausgabe of ergebnis.outputFiles) {
    const code = ausgabe.path.endsWith('.js') ? ausgabe.text.replace(/\t/g, '\\t') : ausgabe.text;
    writeFileSync(ausgabe.path, code);
    if (ausgabe.path.endsWith('.js')) {
      console.log(`portal-ui: ${basename(ausgabe.path)} (${(Buffer.byteLength(code) / 1024).toFixed(1)} kB)`);
    }
  }
  const namen = new Map();
  for (const [pfad, ausgabe] of Object.entries(ergebnis.metafile.outputs)) {
    if (!pfad.endsWith('.js')) continue;
    statisch.set(basename(pfad), ausgabe.imports
      .filter((i) => i.kind === 'import-statement').map((i) => basename(i.path)));
    if (ausgabe.entryPoint) namen.set(resolve(WURZEL, ausgabe.entryPoint), basename(pfad));
  }
  return namen;
}

/** Welche anderen Widgets ein Widget statisch importiert (import './shop-cart.js'). */
function widgetImporte(pfad) {
  const code = readFileSync(pfad, 'utf8');
  return [...code.matchAll(/^import\s+(?:[^'"]*?from\s+)?['"](\.\/[a-z-]+\.js)['"]/gm)]
    .map((m) => resolve(pfad, '..', m[1]));
}

function manifest(schluessel) {
  const abschluss = (start) => {
    const gesehen = new Set();
    const offen = [start];
    while (offen.length) {
      const datei = offen.pop();
      if (gesehen.has(datei)) continue;
      gesehen.add(datei);
      offen.push(...(statisch.get(datei) || []));
    }
    return [...gesehen].sort();
  };
  const eintraege = {};
  for (const [key, datei] of schluessel) {
    // Der Einstieg selbst steht bereits als <script>; vorzuladen sind nur seine Importe
    eintraege[key] = abschluss(datei).filter((d) => d !== datei || !datei.endsWith('-widgets.js'));
  }
  return JSON.stringify({
    _hinweis: 'Von portal-ui/scripts/build.mjs geschrieben — Dateien je Widget, Einstieg und Sprache für modulepreload (partials/portal-ui-preload.html).',
    ...Object.fromEntries(Object.entries(eintraege).sort(([a], [b]) => a.localeCompare(b))),
  }, null, 2) + '\n';
}

async function bauen() {
  mkdirSync(OUTDIR, { recursive: true });
  // Alte Dateien verschwinden: ihr Name trägt den Hash des Inhalts. Ebenso
  // Einstiege, die es nicht mehr gibt (shop-widgets.js, lxcars-widgets.js).
  for (const datei of readdirSync(OUTDIR)) {
    if (/^chunk-[A-Z0-9]+\.js(\.map)?$/i.test(datei) || /-widgets\.js(\.map)?$/.test(datei)) {
      rmSync(join(OUTDIR, datei));
    }
  }
  statisch.clear();
  const zuordnung = new Map();
  const schluessel = new Map();

  // 1. Sprachen — außer Deutsch, das als Rückfall im Kern liegt (core/i18n.js)
  const sprachen = await lauf(dateien('i18n').filter((p) => basename(p) !== 'de.js'), zuordnung);
  for (const [pfad, name] of sprachen) {
    zuordnung.set(pfad, './' + name);
    schluessel.set('i18n-' + basename(pfad, '.js'), name);
  }
  // 2. Kern: alles unter src/core/ samt Lit und Deutsch in einer Datei
  const kern = (await lauf([quelle('core/index.js')], zuordnung)).get(quelle('core/index.js'));
  for (const pfad of dateien('core')) zuordnung.set(pfad, './' + kern);
  zuordnung.set('lit', './' + kern);
  schluessel.set('i18n-de', kern);
  // Die Kopfknöpfe liegen im Kern (core/index.js)
  const imKern = quelle('components/portal-account-buttons.js');
  zuordnung.set(imKern, './' + kern);
  schluessel.set('portal-account-buttons', kern);
  // 3. Widgets, in Wellen: erst die, die kein anderes Widget importieren
  let offen = dateien('components').filter((p) => p !== imKern);
  while (offen.length) {
    const welle = offen.filter((pfad) => widgetImporte(pfad).every((w) => zuordnung.has(w)));
    if (!welle.length) throw new Error('portal-ui: Widgets importieren sich im Kreis: ' + offen.map((p) => basename(p)).join(', '));
    for (const [pfad, name] of await lauf(welle, zuordnung)) {
      zuordnung.set(pfad, './' + name);
      schluessel.set(basename(pfad, '.js'), name);
    }
    offen = offen.filter((pfad) => !welle.includes(pfad));
  }
  // 4. Einstieg
  const einstieg = (await lauf({ 'portal-widgets': quelle('portal.js') }, zuordnung, '[name]')).get(quelle('portal.js'));
  schluessel.set(basename(einstieg, '.js'), einstieg);

  writeFileSync(join(OUTDIR, 'manifest.json'), manifest(schluessel));
  console.log(`portal-ui: ${join(OUTDIR, 'manifest.json')}${dev ? ' (Entwicklung, mit Sourcemaps)' : ''}`);
}

await bauen();

if (beobachten) {
  console.log(`portal-ui: baue nach ${OUTDIR} bei jeder Änderung neu …`);
  let timer = null;
  watch(SRC, { recursive: true }, () => {
    clearTimeout(timer);
    timer = setTimeout(() => bauen().catch((fehler) => console.error(fehler.message)), 150);
  });
}
