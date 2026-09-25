/**
 * Baut das Bündel für die Webseiten in das Site-Kit des Vorlagensatzes.
 *
 * Das Bündel wird eingecheckt und läuft dabei durch tools/fix-ws.sh, das
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
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const OUTFILE = fileURLToPath(new URL(
  '../../backend/templates-default/shop/standard/kit/assets/shop-ui/shop-widgets.js',
  import.meta.url,
));

const result = await build({
  entryPoints: [fileURLToPath(new URL('../src/index.js', import.meta.url))],
  bundle: true,
  format: 'esm',
  minify: true,
  supported: { 'template-literal': false },
  write: false,
  outfile: OUTFILE,
  logLevel: 'warning',
});

const code = result.outputFiles[0].text.replace(/\t/g, '\\t');
writeFileSync(OUTFILE, code);
console.log(`shop-ui: ${OUTFILE} (${(Buffer.byteLength(code) / 1024).toFixed(1)} kB)`);
