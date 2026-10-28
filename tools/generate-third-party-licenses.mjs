#!/usr/bin/env node
/**
 * Erzeugt THIRD_PARTY_LICENSES.md aus den installierten Abhängigkeiten.
 *
 * Quellen:
 *   - package-lock.json + node_modules des Hauptprojekts (ohne devDependencies,
 *     denn nur Produktionspakete landen im ausgelieferten Bündel)
 *   - portal-ui, backend/sse, backend/portal-runner (alle Pakete, sofern installiert)
 *   - backend/vendor/composer/installed.json (Composer)
 *   - backend/lib/fpdf und backend/lib/fpdi (eingebettete Kopien)
 *
 * Die Datei enthält für jedes Paket Name, Version, Lizenzkürzel und den
 * vollständigen Lizenztext aus dem Paket. Vite entfernt beim Minifizieren
 * alle Lizenzkommentare aus dem Bündel; diese Datei ersetzt sie.
 *
 * Aufruf: node tools/generate-third-party-licenses.mjs
 *         (oder: npm run licenses)
 *
 * Voraussetzung: npm ci im Hauptprojekt, in portal-ui und backend/sse sowie
 * composer install in backend/ wurden ausgeführt. Fehlende Verzeichnisse
 * werden mit Warnung übersprungen.
 */

import { readFileSync, readdirSync, existsSync, writeFileSync, statSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = join(ROOT, 'THIRD_PARTY_LICENSES.md');

const warnings = [];
const warn = (msg) => { warnings.push(msg); console.warn('WARNUNG: ' + msg); };

/** Lizenzdatei eines Paketverzeichnisses lesen (LICENSE, LICENSE.md, COPYING …) */
function readLicenseFile(dir) {
    if (!existsSync(dir)) return null;
    const names = readdirSync(dir).filter((n) => /^(licen[sc]e|copying)/i.test(n));
    names.sort((a, b) => a.length - b.length); // "LICENSE" vor "LICENSE-MIT.txt"
    for (const n of names) {
        const p = join(dir, n);
        if (statSync(p).isFile()) return readFileSync(p, 'utf8').replace(/\r\n/g, '\n').trim();
    }
    return null;
}

function licenseString(pkg) {
    let lic = pkg.license;
    if (!lic && Array.isArray(pkg.licenses)) lic = pkg.licenses.map((l) => l.type || l).join(' OR ');
    if (lic && typeof lic === 'object') lic = lic.type;
    return lic || 'UNBEKANNT';
}

function authorString(pkg) {
    const a = pkg.author || pkg.authors;
    if (!a) return '';
    if (typeof a === 'string') return a;
    if (Array.isArray(a)) return a.map((x) => (typeof x === 'string' ? x : x.name)).filter(Boolean).join(', ');
    return a.name || '';
}

/** Alle Pakete eines npm-Projekts anhand des Lockfiles (v2/v3) sammeln */
function collectNpm(relDir, { skipDev }) {
    const dir = join(ROOT, relDir);
    const lockPath = join(dir, 'package-lock.json');
    if (!existsSync(lockPath)) { warn(`${relDir}: package-lock.json fehlt, übersprungen`); return []; }
    if (!existsSync(join(dir, 'node_modules'))) { warn(`${relDir}: node_modules fehlt, übersprungen (npm ci ausführen)`); return []; }
    const lock = JSON.parse(readFileSync(lockPath, 'utf8'));
    if (!lock.packages) { warn(`${relDir}: Lockfile ohne "packages" (lockfileVersion ${lock.lockfileVersion}), übersprungen`); return []; }

    const seen = new Map();
    for (const [key, entry] of Object.entries(lock.packages)) {
        if (key === '' || entry.link) continue;
        if (skipDev && entry.dev) continue;
        const pkgDir = join(dir, key);
        const pkgJson = join(pkgDir, 'package.json');
        if (!existsSync(pkgJson)) {
            // Optionale Plattformpakete (z. B. @esbuild/win32-x64) sind auf anderen Systemen nicht installiert
            if (!entry.optional) warn(`${relDir}/${key}: nicht installiert`);
            continue;
        }
        const pkg = JSON.parse(readFileSync(pkgJson, 'utf8'));
        const id = `${pkg.name}@${pkg.version}`;
        if (seen.has(id)) continue;
        seen.set(id, {
            name: pkg.name,
            version: pkg.version,
            license: licenseString(pkg),
            author: authorString(pkg),
            homepage: pkg.homepage || (typeof pkg.repository === 'string' ? pkg.repository : pkg.repository?.url) || '',
            text: readLicenseFile(pkgDir),
        });
    }
    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name) || a.version.localeCompare(b.version));
}

/** Composer-Pakete aus vendor/composer/installed.json */
function collectComposer(relDir) {
    const vendor = join(ROOT, relDir, 'vendor');
    const installed = join(vendor, 'composer', 'installed.json');
    if (!existsSync(installed)) { warn(`${relDir}: vendor/composer/installed.json fehlt, übersprungen (composer install ausführen)`); return []; }
    const data = JSON.parse(readFileSync(installed, 'utf8'));
    const list = Array.isArray(data) ? data : data.packages || [];
    const out = [];
    for (const p of list) {
        const pkgDir = p['install-path'] ? resolve(join(vendor, 'composer'), p['install-path']) : join(vendor, p.name);
        out.push({
            name: p.name,
            version: p.version,
            license: (p.license || []).join(' OR ') || 'UNBEKANNT',
            author: (p.authors || []).map((a) => a.name).filter(Boolean).join(', '),
            homepage: p.homepage || p.source?.url || '',
            text: readLicenseFile(pkgDir),
        });
    }
    return out.sort((a, b) => a.name.localeCompare(b.name));
}

/** Eingebettete Kopien unter backend/lib */
function collectEmbedded() {
    const out = [];
    const specs = [
        { name: 'fpdf (eingebettete Kopie)', dir: 'backend/lib/fpdf', file: 'fpdf.php', re: /FPDF_VERSION'\s*,\s*'([\d.]+)'/, license: 'MIT', author: 'Olivier Plathey' },
        { name: 'setasign/fpdi (eingebettete Kopie)', dir: 'backend/lib/fpdi', file: 'src/Fpdi.php', re: /VERSION\s*=\s*'([\d.]+)'/, license: 'MIT', author: 'Setasign GmbH & Co. KG' },
    ];
    for (const s of specs) {
        const dir = join(ROOT, s.dir);
        if (!existsSync(dir)) { warn(`${s.dir} fehlt, übersprungen`); continue; }
        let version = '?';
        const src = join(dir, s.file);
        if (existsSync(src)) version = (readFileSync(src, 'utf8').match(s.re) || [])[1] || '?';
        out.push({ name: s.name, version, license: s.license, author: s.author, homepage: '', text: readLicenseFile(dir) });
    }
    return out;
}

const groups = [
    { title: 'Frontend (npm, Hauptprojekt, Produktionsabhängigkeiten)', items: collectNpm('.', { skipDev: true }) },
    { title: 'Portal-Widgets (npm, portal-ui)', items: collectNpm('portal-ui', { skipDev: false }) },
    { title: 'SSE-Server (npm, backend/sse)', items: collectNpm('backend/sse', { skipDev: false }) },
    { title: 'Portal-Runner (npm, backend/portal-runner)', items: collectNpm('backend/portal-runner', { skipDev: false }) },
    { title: 'Backend PHP (Composer, backend/vendor)', items: collectComposer('backend') },
    { title: 'Eingebettete PHP-Bibliotheken (backend/lib)', items: collectEmbedded() },
];

// Zusammenfassung je Lizenz
const counts = {};
let total = 0;
for (const g of groups) for (const it of g.items) { counts[it.license] = (counts[it.license] || 0) + 1; total++; }

const today = new Date().toISOString().slice(0, 10);
const lines = [];
lines.push('# Lizenzen der Drittkomponenten');
lines.push('');
lines.push(`Erzeugt am ${today} mit \`node tools/generate-third-party-licenses.mjs\`. Nicht von Hand bearbeiten.`);
lines.push('');
lines.push('OSERP steht unter der GNU Affero General Public License v3 oder später (siehe `LICENSE`).');
lines.push('Diese Datei enthält die Lizenztexte und Urheberrechtshinweise der mitgelieferten');
lines.push('Komponenten Dritter. Eine kuratierte Übersicht mit Einordnung, einschließlich der');
lines.push('Python-Dienste und der Systemkomponenten, die nicht Teil dieses Repositorys sind,');
lines.push('steht in `docs/drittkomponenten.md`.');
lines.push('');
lines.push('Die Python-Dienste (`backend/whisper`, `backend/fahrzeugschein-scanner`, `backend/services/*`)');
lines.push('werden beim Einrichten per pip installiert; ihre Lizenztexte liegen den jeweiligen');
lines.push('Paketen im `dist-info`-Verzeichnis der virtuellen Umgebung bei.');
lines.push('');
lines.push('## Zusammenfassung');
lines.push('');
lines.push('| Lizenz | Pakete |');
lines.push('|---|---|');
for (const [lic, n] of Object.entries(counts).sort((a, b) => b[1] - a[1])) lines.push(`| ${lic} | ${n} |`);
lines.push(`| **Gesamt** | **${total}** |`);
lines.push('');
lines.push('## Inhalt');
lines.push('');
for (const g of groups) lines.push(`- ${g.title} (${g.items.length})`);
lines.push('');

for (const g of groups) {
    lines.push(`## ${g.title}`);
    lines.push('');
    if (!g.items.length) { lines.push('_Keine Pakete erfasst._'); lines.push(''); continue; }
    for (const it of g.items) {
        lines.push(`### ${it.name} ${it.version}`);
        lines.push('');
        lines.push(`- Lizenz: ${it.license}`);
        if (it.author) lines.push(`- Urheber: ${it.author}`);
        if (it.homepage) lines.push(`- Quelle: ${it.homepage.replace(/^git\+/, '')}`);
        lines.push('');
        if (it.text) {
            lines.push('```text');
            lines.push(it.text.replace(/```/g, "'''"));
            lines.push('```');
        } else {
            lines.push(`_Dem Paket liegt kein Lizenztext bei. Lizenzangabe laut Paketmetadaten: ${it.license}._`);
            warn(`${it.name}@${it.version}: kein Lizenztext im Paket (${it.license})`);
        }
        lines.push('');
    }
}

writeFileSync(OUT, lines.join('\n') + '\n');
console.log(`THIRD_PARTY_LICENSES.md geschrieben: ${total} Pakete, ${warnings.length} Warnungen`);
