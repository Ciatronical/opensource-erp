// Bildschirmaufnahme des Vorlageneditors, szenenweise passend zum Sprechertext.
// Jede Szene: Aktionen im Editor, Frames als JPEG; die Dauer richtet sich nach
// der Länge des zugehörigen Audio-Abschnitts (audio/NN.wav), mindestens aber
// nach der Zeit, die die Aktionen brauchen.
import { Browser, sleep } from './cdp.mjs';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const DIR = new URL('.', import.meta.url).pathname;
const OUT = DIR + 'rec/';
const AUDIO = process.env.AUDIO_DIR || DIR + 'audio/';
const SID = readFileSync(DIR + 'session.txt', 'utf8').trim();
const FPS = 12;
mkdirSync(OUT, { recursive: true });

const b = new Browser({ port: 9335, profile: '/tmp/claude-1000/cdp-rec', width: 1920, height: 1080 });

/** Dauer einer Audiodatei in Sekunden (0 wenn es sie nicht gibt) */
function audioSeconds(n) {
    const f = `${AUDIO}${String(n).padStart(2, '0')}.wav`;
    if (existsSync(f)) {
        return parseFloat(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f]).toString());
    }
    // Ohne Tonspur: Dauer aus dem Sprechertext schätzen (ca. 2,4 Wörter je Sekunde)
    const text = readFileSync('/home/work/opensource-erp/promotion/vorlageneditor-video/sprechertext.md', 'utf8');
    const m = text.split(/^## /m).find(part => part.startsWith(String(n).padStart(2, '0') + ' '));
    if (!m) return 0;
    const words = m.split('\n').slice(1).join(' ').trim().split(/\s+/).length;
    return words / 2.4 + 1.5;
}

const scenes = [];
let stopRec = null;
let sceneStart = 0;

async function scene(n, fn, { minSeconds = 4 } = {}) {
    const dir = `${OUT}s${String(n).padStart(2, '0')}`;
    sceneStart = Date.now();
    stopRec = await b.record(dir, FPS);
    try { await fn(); } catch (e) { console.error(`Szene ${n}:`, e.message); await b.screenshot(`${OUT}error-${n}.png`).catch(() => {}); }
    // Szene mindestens so lang wie der Sprechertext halten
    const target = Math.max(minSeconds, audioSeconds(n)) * 1000;
    const rest = target - (Date.now() - sceneStart);
    if (rest > 0) await sleep(rest);
    const frames = await stopRec();
    scenes.push({ n, dir, frames: frames.length, seconds: (Date.now() - sceneStart) / 1000 });
    console.log(`Szene ${n}: ${frames.length} Frames, ${((Date.now() - sceneStart) / 1000).toFixed(1)} s`);
}

const clickText = async (sel, text, idx = 0) => {
    // In seitlich scrollenden Leisten (Belegarten-Chips) das Element erst sichtbar machen
    await b.eval(`(() => { const els = [...document.querySelectorAll(${JSON.stringify(sel)})].filter(e => (e.innerText || '').includes(${JSON.stringify(text)})); els[${idx}]?.scrollIntoView({ inline: 'center', block: 'nearest' }); })()`);
    await sleep(150);
    const r = await b.rectByText(sel, text, idx); await b.click(r.cx, r.cy);
};
const hover = async (x, y) => b.mouseMove(x, y, 20, 18);
const blockRect = async (pred) => { const i = await b.eval(`[...document.querySelectorAll('.tpl-block')].findIndex(e => ${pred})`); return b.rect('.tpl-block', i); };

try {
    await b.launch('about:blank');
    await b.setCookie('opensource_erp', SID, 'localhost');
    await b.goto('https://localhost/');
    await sleep(3000);

    // ── 01 Intro: Startseite, Systemmenü öffnen ──
    await scene(1, async () => {
        await b.showCursor();
        await sleep(1500);
        const logo = await b.rect('.navbar-right img, .navbar-right .company-logo');
        await hover(logo.cx, logo.cy);
        await sleep(1200);
    });

    // ── 02 Öffnen: Menüpunkt Vorlageneditor ──
    await scene(2, async () => {
        const item = await b.rectByText('.v-list-item', 'Vorlageneditor');
        await hover(item.cx, item.cy);
        await sleep(600);
        await b.click(item.cx, item.cy);
        await b.waitFor('.tpl-toolbar', 20000);
        await sleep(1500);
        await b.showCursor();
        await b.key('Escape');
        await sleep(400);
        // Vorlagensatz demo-editor wählen
        const sel = await b.rect('.tpl-set-select');
        await b.click(sel.cx, sel.cy);
        await sleep(700);
        await clickText('.v-list-item', 'demo-editor');
        await sleep(2000);
        await b.key('Escape');
        await sleep(300);
        await b.showCursor();
    });

    // ── 03 Aufbau: über Bereiche fahren ──
    await scene(3, async () => {
        const chips = await b.rect('.tpl-doctypes');
        await hover(chips.x + 120, chips.cy); await sleep(1500);
        const pal = await b.rect('.tpl-side--left');
        await hover(pal.cx, pal.y + 120); await sleep(1500);
        const fields = await b.rectByText('.tpl-palette-title', 'Felder');
        await hover(fields.cx, fields.cy); await sleep(1200);
        const canvas = await b.rect('.tpl-canvas-scroll');
        await hover(canvas.cx, canvas.cy); await sleep(1500);
        const insp = await b.rect('.tpl-side--right');
        await hover(insp.cx, insp.y + 150); await sleep(1200);
    });

    // ── 04 Startvorlage ──
    await scene(4, async () => {
        await clickText('button', 'Startvorlage wählen');
        await sleep(1200);
        const cards = ['DIN 5008', 'Modern', 'Kompakt', 'Leer'];
        for (const c of cards) { const r = await b.rectByText('.tpl-preset', c); await hover(r.cx, r.cy); await sleep(900); }
        await clickText('.tpl-preset', 'DIN 5008');
        await sleep(2500);
        await b.showCursor();
        const canvas = await b.rect('.tpl-page');
        await hover(canvas.x + 200, canvas.y + 200); await sleep(800);
        await hover(canvas.x + 500, canvas.y + 650); await sleep(800);
    });

    // ── 05 Firmendaten: Fußzeile wählen, Vorschläge zeigen ──
    await scene(5, async () => {
        const footer = await blockRect(`(e.innerText || '').includes('IBAN')`);
        await b.click(footer.cx, footer.cy);
        await sleep(1200);
        const sug = await b.rect('.tpl-suggestions');
        await hover(sug.x + 40, sug.y + 30); await sleep(1500);
        const chip = await b.rectByText('.tpl-suggestions .v-chip', 'Steuernummer');
        await hover(chip.cx, chip.cy); await sleep(800);
        await b.click(chip.cx, chip.cy);
        await sleep(1500);
        await b.key('z', { ctrl: true });
        await sleep(600);
    });

    // ── 06 Verschieben, Größe, Pfeiltasten, Rückgängig ──
    await scene(6, async () => {
        const info = await blockRect(`e.querySelector('.tpl-infobox')`);
        await b.drag(info.cx, info.cy, info.cx - 40, info.cy + 60, { steps: 40, stepDelay: 30, holdBefore: 300 });
        await sleep(900);
        const info2 = await blockRect(`e.querySelector('.tpl-infobox')`);
        await b.drag(info2.cx, info2.cy, info.cx, info.cy, { steps: 40, stepDelay: 30, holdBefore: 300 });
        await sleep(900);
        const h = await b.rect('.tpl-handle--se');
        await b.drag(h.cx, h.cy, h.cx + 30, h.cy + 25, { steps: 30, stepDelay: 30 });
        await sleep(900);
        for (let i = 0; i < 4; i++) { await b.key('ArrowRight', { shift: true }); await sleep(250); }
        await sleep(600);
        await b.key('z', { ctrl: true }); await sleep(500);
        await b.key('z', { ctrl: true }); await sleep(500);
        await b.key('z', { ctrl: true }); await sleep(500);
        await b.key('z', { ctrl: true }); await sleep(500);
        await b.key('z', { ctrl: true }); await sleep(800);
    });

    // ── 07 Felder: Liste zeigen, Feld in Text einfügen ──
    await scene(7, async () => {
        const fields = await b.rect('.tpl-field', 0);
        await hover(fields.cx, fields.cy); await sleep(800);
        await hover(fields.cx, fields.cy + 120); await sleep(800);
        const addr = await blockRect(`(e.innerText || '').includes('Weißgerber') || (e.innerText || '').includes('[name]')`);
        await b.click(addr.cx, addr.cy);
        await sleep(800);
        const f = await b.rectByText('.tpl-field', 'Telefon');
        await hover(f.cx, f.cy); await sleep(600);
        await b.click(f.cx, f.cy);
        await sleep(1500);
        await b.key('z', { ctrl: true });
        await sleep(500);
    });

    // ── 08 Infobox ──
    await scene(8, async () => {
        const info = await blockRect(`e.querySelector('.tpl-infobox')`);
        await b.click(info.cx, info.cy);
        await sleep(1000);
        const rows = await b.rect('.tpl-row-card', 0);
        await hover(rows.cx, rows.cy); await sleep(800);
        const eye = await b.rect('.tpl-row-card .mdi-eye-off-outline', 0);
        await hover(eye.cx, eye.cy); await sleep(1200);
        const add = await b.rectByText('.tpl-inspector button', 'Zeile hinzufügen');
        await hover(add.cx, add.cy); await sleep(800);
    });

    // ── 09 Fließbereich: Abschnitt sortieren, Notizen frei legen, Kante ziehen ──
    await scene(9, async () => {
        const body = await b.rect('.tpl-body-area');
        await hover(body.x + 300, body.y + 40); await sleep(800);
        const notesIdx = await b.eval(`[...document.querySelectorAll('.tpl-section')].findIndex(e => (e.innerText || '').includes('[notes]'))`);
        const sec = await b.rect('.tpl-section', notesIdx);
        const first = await b.rect('.tpl-section', 0);
        await b.drag(sec.x + 60, sec.cy, first.x + 60, first.y + 4, { steps: 35, stepDelay: 30, holdBefore: 350 });
        await sleep(1000);
        const idx2 = await b.eval(`[...document.querySelectorAll('.tpl-section')].findIndex(e => (e.innerText || '').includes('[notes]'))`);
        const sec2 = await b.rect('.tpl-section', idx2);
        await b.click(sec2.x + 60, sec2.y + 6);
        await sleep(900);
        await clickText('.tpl-inspector button', 'freien Baustein');
        await sleep(1000);
        const blk = await b.rectByText('.tpl-block', '[notes]');
        const page = await b.rect('.tpl-page');
        const scale = page.w / 210; // px je mm
        // unter die Infobox, rechts neben die Anschrift (ca. x=125mm, y=92mm)
        await b.drag(blk.x + 40, blk.cy, page.x + 125 * scale + 40, page.y + 92 * scale, { steps: 35, stepDelay: 30, holdBefore: 300 });
        await sleep(900);
        await b.eval("document.querySelector('.tpl-canvas-scroll').scrollTop = 0");
        await sleep(300);
        const edge = await b.rect('.tpl-body-edge--n');
        await b.drag(edge.x + 250, edge.cy, edge.x + 250, edge.cy + 35, { steps: 25, stepDelay: 30, holdBefore: 300 });
        await sleep(600);
        await b.key('z', { ctrl: true }); await sleep(500);
    });

    // ── 10 Tabelle ──
    await scene(10, async () => {
        const tbl = await b.eval(`[...document.querySelectorAll('.tpl-section')].findIndex(e => e.querySelector('.tpl-table'))`);
        const sec = await b.rect('.tpl-section', tbl);
        await b.click(sec.x + 60, sec.y + 8);
        await sleep(1200);
        const col = await b.rect('.tpl-row-card', 1);
        await hover(col.cx, col.cy); await sleep(800);
        const chk = await b.rect('.tpl-row-card .v-selection-control__input', 5);
        await b.click(chk.cx, chk.cy); await sleep(1200);
        await b.click(chk.cx, chk.cy); await sleep(800);
        const zebra = await b.rectByText('.tpl-inspector .v-switch', 'abwechselnd');
        await b.click(zebra.cx, zebra.cy); await sleep(1500);
    });

    // ── 11 Folgeseiten ──
    await scene(11, async () => {
        const info = await blockRect(`e.querySelector('.tpl-infobox')`);
        await b.click(info.cx, info.cy); await sleep(600);
        const tog = await b.rectByText('.tpl-inspector .v-btn-toggle button', 'Nur erste');
        await hover(tog.cx, tog.cy); await sleep(1200);
        const pv = await b.rectByText('.tpl-pageview button', '2+');
        await b.click(pv.cx, pv.cy);
        await sleep(2000);
        const page = await b.rect('.tpl-page');
        await hover(page.x + 300, page.y + 90); await sleep(1500);
        const pv1 = await b.rect('.tpl-pageview button', 0);
        await b.click(pv1.cx, pv1.cy);
        await sleep(600);
    });

    // ── 12 Vorschau ──
    await scene(12, async () => {
        await clickText('.tpl-toolbar button', 'PDF-Vorschau');
        const t0 = Date.now();
        while (Date.now() - t0 < 40000) { if (await b.eval(`!!document.querySelector('iframe.tpl-preview-frame, .tpl-preview-page, .tpl-preview-error')`)) break; await sleep(400); }
        await sleep(2500);
        const doc = await b.rect('.tpl-preview-doc');
        await hover(doc.cx, doc.cy); await sleep(1000);
        const auto = await b.rectByText('.tpl-preview-bar .v-switch', 'automatisch');
        await hover(auto.cx, auto.cy); await sleep(1200);
    });

    // ── 13 Speichern ──
    await scene(13, async () => {
        await clickText('.tpl-toolbar button', 'Speichern');
        await sleep(1500);
        if (await b.eval(`!!document.querySelector('.v-dialog .v-card-title')`)) {
            await sleep(2500);
            await clickText('.v-dialog button', 'Speichern');
        }
        await sleep(2500);
        await b.waitGone('.swal2-toast');
        await sleep(500);
        const menu = await b.rect('.tpl-toolbar .mdi-dots-vertical');
        await b.click(menu.cx, menu.cy); await sleep(900);
        await clickText('.v-list-item', 'Frühere Versionen');
        await sleep(2000);
        await b.key('Escape'); await sleep(500);
    });

    // ── 14 Übertragen ──
    await scene(14, async () => {
        await b.waitGone('.swal2-toast');
        const menu = await b.rect('.tpl-toolbar .mdi-dots-vertical');
        await b.click(menu.cx, menu.cy); await sleep(800);
        await clickText('.v-list-item', 'Grundlayout');
        await sleep(1200);
        await clickText('.v-dialog button', 'Alle auswählen');
        await sleep(1000);
        await clickText('.v-dialog button', 'Übertragen');
        await sleep(1500);
        await clickText('.tpl-doctype-chip', 'Angebot');
        await sleep(3000);
        await b.showCursor();
        await clickText('.tpl-doctype-chip', 'Auftragsbestätigung');
        await sleep(3000);
        await b.showCursor();
    });

    // ── 15 Schluss ──
    await scene(15, async () => {
        await clickText('.tpl-doctype-chip', 'Rechnung');
        await sleep(2500);
        await b.showCursor();
        const page = await b.rect('.tpl-page');
        await hover(page.cx, page.y + 300); await sleep(1500);
    });

    writeFileSync(OUT + 'scenes.json', JSON.stringify(scenes, null, 2));
} catch (e) {
    console.error('FEHLER', e);
} finally {
    await b.close();
}
