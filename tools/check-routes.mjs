#!/usr/bin/env node
// Laedt den ECHTEN Router (inkl. echter i18n-Tabelle) ueber Vites SSR-Loader
// und prueft jede im Code verwendete Navigation gegen die reale Routen-Tabelle.
import { createServer } from 'vite'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

// View-Komponenten ausstubben: geprueft wird die Routen-Tabelle (Pfade, Namen,
// Parameter), nicht das Rendering. Dass die Komponenten uebersetzen, deckt
// bereits `npm run build` ab. Ohne das muesste hier der halbe Browser
// nachgebaut werden, weil Vuetify & Co. schon beim Import DOM-Globals anfassen.
const stubPlugin = {
    name: 'oserp-route-check-stubs',
    enforce: 'pre',
    resolveId(id) {
        if (id.endsWith('.vue')) return '\0stub-vue:' + id
        return null
    },
    load(id) {
        if (id.startsWith('\0stub-vue:')) {
            return `export default { name: ${JSON.stringify(id.slice(10))} }`
        }
        return null
    },
}

const server = await createServer({
    root: ROOT,
    configFile: path.join(ROOT, 'vite.config.js'),
    server: { middlewareMode: true },
    logLevel: 'error',
    plugins: [stubPlugin],
    // Vuetify durch Vite transformieren lassen — sonst laedt Node dessen
    // CSS-Importe direkt und bricht ab.
    ssr: { noExternal: [/vuetify/] },
})

// Minimale Browser-Globals: createWebHistory() und einzelne Pakete greifen
// beim Import direkt darauf zu. Kein jsdom noetig — geprueft wird nur Routing.
globalThis.indexedDB ??= { open: () => ({ addEventListener() {} }) }
const loc = { pathname: '/', search: '', hash: '', href: 'http://localhost/', origin: 'http://localhost', protocol: 'http:', host: 'localhost' }
const hist = { state: null, scrollRestoration: 'auto', replaceState() {}, pushState() {}, go() {} }
globalThis.window ??= {
    location: loc,
    history: hist,
    addEventListener() {}, removeEventListener() {}, scrollTo() {},
    matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
}
globalThis.location ??= loc
globalThis.history ??= hist
const makeEl = () => ({
    style: {}, dataset: {}, classList: { add() {}, remove() {}, contains: () => false },
    setAttribute() {}, getAttribute: () => null, appendChild: c => c, insertBefore: c => c,
    removeChild: c => c, addEventListener() {}, removeEventListener() {}, remove() {},
    querySelector: () => null, querySelectorAll: () => [], children: [], childNodes: [],
    firstChild: null, textContent: '', innerHTML: '',
})
globalThis.document ??= {
    createElement: makeEl, createTextNode: () => makeEl(), createComment: () => makeEl(),
    querySelector: () => null, querySelectorAll: () => [],
    getElementsByTagName: () => [makeEl()], getElementById: () => null,
    addEventListener() {}, removeEventListener() {},
    head: makeEl(), body: makeEl(), documentElement: makeEl(),
    baseURI: 'http://localhost/',
}
globalThis.window.document ??= globalThis.document
globalThis.navigator ??= { userAgent: 'node', language: 'de-DE', maxTouchPoints: 0 }
globalThis.window.navigator ??= globalThis.navigator
// Node bringt ein unvollstaendiges localStorage mit — vollstaendig ersetzen,
// sonst stolpert das Vue-Devtools-Kit von Pinia darueber.
const memStore = new Map()
Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
        getItem: k => (memStore.has(k) ? memStore.get(k) : null),
        setItem: (k, v) => memStore.set(k, String(v)),
        removeItem: k => memStore.delete(k),
        clear: () => memStore.clear(),
        key: () => null,
        get length() { return memStore.size },
    },
})
globalThis.window.localStorage = globalThis.localStorage

// Pinia bereitstellen, da Guards/Komponenten den Store nutzen
const { createPinia, setActivePinia } = await import('pinia')
setActivePinia(createPinia())

const routerMod = await server.ssrLoadModule('/src/core/router/index.js')
const router = routerMod.default

const names = router.getRoutes().map(r => r.name).filter(Boolean)
console.log(`Routen geladen: ${names.length}`)

// --- alle im Code verwendeten Navigationsziele einsammeln ---
function walk(dir, out = []) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name)
        if (e.isDirectory()) walk(p, out)
        else if (/\.(vue|js)$/.test(e.name)) out.push(p)
    }
    return out
}

const used = new Map() // name -> [orte]
for (const file of walk(path.join(ROOT, 'src'))) {
    const rel = path.relative(ROOT, file)
    if (rel.endsWith('core/router/index.js')) continue
    const src = fs.readFileSync(file, 'utf8')
    for (const m of src.matchAll(/name:\s*'([a-z0-9-]+)'/g)) {
        const line = src.slice(0, m.index).split('\n').length
        if (!used.has(m[1])) used.set(m[1], [])
        used.get(m[1]).push(`${rel}:${line}`)
    }
}

// --- jeden Namen tatsaechlich aufloesen ---
const failures = []
const resolvedUrls = []
for (const [name, places] of [...used.entries()].sort()) {
    const route = router.getRoutes().find(r => r.name === name)
    if (!route) {
        failures.push(`Route '${name}' existiert nicht  (${places[0]}${places.length > 1 ? ` +${places.length - 1}` : ''})`)
        continue
    }
    // Pflichtparameter mit Dummy-Werten fuellen
    const params = {}
    for (const p of route.keys || []) if (!p.optional) params[p.name] = '1'
    for (const m of route.path.matchAll(/:([A-Za-z_]+)(\([^)]*\))?(\?)?/g)) {
        if (!m[3] && m[1] !== 'pathMatch') params[m[1]] = '1'
    }
    try {
        const r = router.resolve({ name, params })
        if (r.matched.length === 0 || r.name === 'not-found') {
            failures.push(`Route '${name}' loest auf NotFound auf  (${places[0]})`)
        } else {
            resolvedUrls.push(`  ${name.padEnd(32)} ${r.href}`)
        }
    } catch (e) {
        failures.push(`Route '${name}' wirft: ${e.message}  (${places[0]})`)
    }
}

console.log(`Verwendete Route-Namen: ${used.size}\n`)
console.log('=== Aufgeloeste URLs ===')
console.log(resolvedUrls.join('\n'))

console.log('')
if (failures.length) {
    console.log('=== FEHLER ===')
    failures.forEach(f => console.log('  ✗ ' + f))
} else {
    console.log('✔ Jedes Navigationsziel im Code loest auf eine echte Route auf.')
}

// --- Gegenprobe: fuehren die frueheren Pfade weiterhin ans gleiche Ziel? ---
console.log('\n=== Gegenprobe: alte URLs (Lesezeichen) ===')
const bookmarks = [
    '/', '/kunde', '/kunde/5', '/kunde/bearbeiten', '/kunde/bearbeiten/5', '/kunde/neu',
    '/lieferant/5', '/lieferant/bearbeiten/5', '/lieferant/neu',
    '/rechnung/5', '/rechnung/neu', '/angebot/5', '/auftrag/5', '/auftrag/suche',
    '/gutschrift', '/gutschrift/5', '/lieferschein', '/lieferschein/5',
    '/artikel/5', '/suche', '/wiedervorlage', '/kalender', '/anrufliste',
    '/system/firmenconfig', '/system/aktualisierung', '/system/developer-tools',
    '/system/mandantenkonfiguration',
    '/buchhaltung', '/buchhaltung/buchungen', '/buchhaltung/offene-posten',
    '/banking', '/bankabstimmung', '/kasse', '/personal', '/personal/lohn',
    '/wiki', '/wiki/5', '/wiki/neu', '/wiki/kategorien',
    '/emails', '/whatsapp', '/mechaniker', '/anschlagtafel', '/tafel', '/tafel-verwaltung',
    '/hauptmen%C3%BC', '/login', '/datenschutz', '/datenloeschung', '/kameras',
    '/aufgaben', '/rechnung/anzeigen/5',
]
let broken = 0
for (const b of bookmarks) {
    const r = router.resolve(b)
    // Redirect-Eintraege tragen keinen Namen — Ziel des Redirects ausweisen.
    const redirect = r.matched[r.matched.length - 1]?.redirect
    const label = r.name
        || (redirect ? `(Redirect -> ${typeof redirect === 'function' ? redirect(r).name : redirect.name || redirect})` : null)
    const ok = Boolean(label) && r.name !== 'not-found'
    if (!ok) broken++
    console.log(`  ${ok ? '✔' : '✗'} ${b.padEnd(34)} -> ${label || 'KEIN TREFFER'}`)
}

// --- Sprachunabhaengigkeit: nach einem Sprachwechsel muss jedes Ziel
// ─────────────────────── Sprachumschaltung der URLs ───────────────────────
// Erwartung nach dem Umbau:
//   1. Beim Sprachwechsel aendert sich die kanonische URL.
//   2. Die Route-NAMEN bleiben identisch — davon haengt der gesamte Code ab.
//   3. Die URLs der jeweils anderen Sprache bleiben als Alias gueltig.
const i18nMod = await server.ssrLoadModule('/src/i18n/index.js')
const i18n = i18nMod.default
const tick = () => new Promise(r => setTimeout(r, 50))

const paramsOf = (route) => {
    const p = {}
    for (const m of route.path.matchAll(/:([A-Za-z_]+)(\([^)]*\))?(\?)?/g)) {
        if (!m[3] && m[1] !== 'pathMatch') p[m[1]] = '1'
    }
    return p
}
const snapshot = async (locale) => {
    i18n.global.locale.value = locale
    await tick()
    const out = new Map()
    for (const r of router.getRoutes()) {
        if (!r.name || r.name === 'not-found') continue
        if (out.has(r.name)) continue          // Alias-Eintraege ueberspringen
        out.set(r.name, router.resolve({ name: r.name, params: paramsOf(r) }).href)
    }
    return out
}

const de = await snapshot('de')
const en = await snapshot('en')

console.log('\n=== Sprachumschaltung der URLs ===')
const changed = [...de].filter(([n, h]) => en.get(n) !== h)
const unchanged = [...de].filter(([n, h]) => en.get(n) === h)
console.log(`  Routen gesamt: ${de.size}`)
console.log(`  URL geaendert: ${changed.length}, unveraendert: ${unchanged.length}`)
changed.slice(0, 12).forEach(([n, h]) => console.log(`    ${n.padEnd(30)} ${h.padEnd(34)} -> ${en.get(n)}`))
console.log(`    ... (${Math.max(0, changed.length - 12)} weitere)`)
console.log('  unveraendert (in beiden Sprachen gleich geschrieben):')
unchanged.forEach(([n, h]) => console.log(`    ${n.padEnd(30)} ${h}`))

// Namen muessen deckungsgleich sein
const nameDrift = [...de.keys()].filter(n => !en.has(n)).concat([...en.keys()].filter(n => !de.has(n)))

// Kreuzprobe: jede URL muss in BEIDER Sprache auf dieselbe Route zeigen
const crossFails = []
i18n.global.locale.value = 'en'; await tick()
for (const [name, deHref] of de) {
    const r = router.resolve(deHref)
    if (r.name !== name) crossFails.push(`unter EN: ${deHref} -> ${r.name || 'KEIN TREFFER'} (erwartet ${name})`)
}
i18n.global.locale.value = 'de'; await tick()
for (const [name, enHref] of en) {
    const r = router.resolve(enHref)
    if (r.name !== name) crossFails.push(`unter DE: ${enHref} -> ${r.name || 'KEIN TREFFER'} (erwartet ${name})`)
}

console.log('')
console.log(`  ${changed.length ? '✔' : '✗'} ${changed.length} URLs werden uebersetzt`)
console.log(`  ${nameDrift.length ? '✗' : '✔'} Route-Namen bleiben identisch${nameDrift.length ? ': ' + nameDrift.join(', ') : ''}`)
if (crossFails.length) {
    console.log(`  ✗ ${crossFails.length} Kreuzproben fehlgeschlagen:`)
    crossFails.slice(0, 15).forEach(f => console.log('      ' + f))
} else {
    console.log(`  ✔ Alle ${de.size * 2} URLs (DE und EN) loesen in beiden Sprachen auf dieselbe Route auf`)
}

// Sprache ohne eigene URL-Vokabeln: muss auf die deutschen Pfade zurueckfallen
i18n.global.locale.value = 'en'; await tick()
i18n.global.locale.value = 'pl'; await tick()
const plHref = router.resolve({ name: 'customer-vendor' }).href
const plOk = plHref === de.get('customer-vendor')
console.log(`  ${plOk ? '✔' : '✗'} Sprache ohne eigene URLs (pl) faellt auf die deutschen Pfade zurueck — ${plHref}`)

// Zurueck auf Deutsch fuer die Schlussmeldung
i18n.global.locale.value = 'de'; await tick()

await server.close()
console.log(`\n${broken} von ${bookmarks.length} alten URLs kaputt`)
const failed = failures.length || broken || nameDrift.length || crossFails.length || !changed.length || !plOk
console.log(failed ? '\n✗ PRUEFUNG FEHLGESCHLAGEN' : '\n✔ ALLE PRUEFUNGEN BESTANDEN')
process.exit(failed ? 1 : 0)
