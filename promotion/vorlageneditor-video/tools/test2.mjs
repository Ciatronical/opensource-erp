// Test: Quelltext-Modus und freies Platzieren von Abschnitten (echtes System, headless Chrome)
import { Browser, sleep } from './cdp.mjs';
import { readFileSync } from 'node:fs';

const DIR = new URL('.', import.meta.url).pathname;
const SID = readFileSync(DIR + 'session.txt', 'utf8').trim();
const b = new Browser({ port: 9336, profile: '/tmp/claude-1000/cdp-test2' });
const results = [];
const ok = (name, cond, info = '') => { results.push({ name, ok: !!cond, info }); console.log((cond ? 'OK   ' : 'FAIL ') + name + (info ? ' — ' + info : '')); };
const clickText = async (sel, text, idx = 0) => {
    await b.eval(`(() => { const els = [...document.querySelectorAll(${JSON.stringify(sel)})].filter(e => (e.innerText || '').includes(${JSON.stringify(text)})); els[${idx}]?.scrollIntoView({ inline: 'center', block: 'nearest' }); })()`);
    await sleep(150);
    const r = await b.rectByText(sel, text, idx); await b.click(r.cx, r.cy);
};
const waitPreview = async () => { const t0 = Date.now(); while (Date.now() - t0 < 60000) { if (await b.eval(`!!document.querySelector('iframe.tpl-preview-frame, .tpl-preview-page, .tpl-preview-error')`)) break; await sleep(400); } return b.eval(`document.querySelector('.tpl-preview-error')?.innerText || ''`); };

try {
    await b.launch('about:blank');
    await b.setCookie('opensource_erp', SID, 'localhost');
    await b.goto('https://localhost/'); await sleep(2500);
    await b.goto('https://localhost/system/vorlageneditor');
    await b.waitFor('.tpl-toolbar', 20000); await sleep(1500);
    await b.showCursor();
    await b.key('Escape'); await sleep(400);
    const sel = await b.rect('.tpl-set-select'); await b.click(sel.cx, sel.cy); await sleep(600);
    await clickText('.v-list-item', 'demo-editor'); await sleep(2500);
    await b.key('Escape'); await sleep(400);

    // ── Ohne Design: "Quelltext bearbeiten" führt in den Quelltext-Modus mit invoice.tex ──
    ok('Knopf Quelltext bearbeiten vorhanden', await b.eval(`[...document.querySelectorAll('button')].some(b => b.innerText.includes('Quelltext bearbeiten'))`));
    await clickText('button', 'Quelltext bearbeiten'); await sleep(2500);
    await b.showCursor();
    ok('Quelltext-Modus geöffnet', await b.eval(`!!document.querySelector('.tpl-source')`));
    ok('invoice.tex geladen', await b.eval(`document.querySelector('.tpl-source-bar')?.innerText.includes('invoice.tex')`));
    ok('Editor zeigt LaTeX', await b.eval(`(document.querySelector('.cm-content')?.innerText || '').includes('\\\\begin{document}')`));
    await b.screenshot(DIR + 'u01-source-invoice.png');

    // Vorschau der handgeschriebenen Rechnung
    await clickText('.tpl-source-bar button', 'PDF-Vorschau');
    const perr = await waitPreview();
    ok('Vorschau handgeschriebene Vorlage', !perr && await b.eval(`!!document.querySelector('iframe.tpl-preview-frame, .tpl-preview-page')`), perr.slice(0, 200));
    await sleep(1500);
    await b.screenshot(DIR + 'u02-source-preview.png');

    // Firmendaten-Datei: Werte-Formular, Vorschlag, Speichern
    await clickText('.tpl-source-file', 'ident.tex'); await sleep(1500);
    const vars = await b.eval(`[...document.querySelectorAll('.tpl-source-var input')].map(i => i.value)`);
    ok('Werte-Formular aus \\\\newcommand', vars.length >= 5, vars.slice(0, 3).join(' | '));
    const firmaIdx = await b.eval(`[...document.querySelectorAll('.tpl-source-var')].findIndex(e => (e.querySelector('.v-label')?.textContent || '').trim().endsWith('firma'))`);
    const labelsDbg = await b.eval(`JSON.stringify([...document.querySelectorAll('.tpl-source-var')].slice(0,6).map(e => [e.querySelector('label')?.innerText, e.querySelector('.v-label')?.textContent, e.innerText.slice(0,30)]))`);
    ok('Feld \\firma gefunden', firmaIdx >= 0, 'index ' + firmaIdx + ' ' + labelsDbg);
    const firmaField = await b.rect('.tpl-source-var input', firmaIdx);
    await b.click(firmaField.cx, firmaField.cy); await b.selectAll(); await b.type('Testfirma & Co', 8); await sleep(600);
    ok('Formularwert landet im Quelltext (escaped)', await b.eval(`(document.querySelector('.cm-content')?.innerText || '').includes('{Testfirma \\\\& Co}')`));
    ok('Vorschlag aus Firmenkonfiguration angeboten', await b.eval(`!!document.querySelector('.tpl-source-var .v-chip')`));
    await sleep(2500);
    const perr2 = await waitPreview();
    ok('Vorschau aktualisiert nach Wertänderung', !perr2, perr2.slice(0, 200));
    await b.screenshot(DIR + 'u03-source-ident.png');
    await clickText('.tpl-source-bar button', 'Speichern'); await sleep(2500);
    const toast = await b.eval(`document.querySelector('.swal2-toast')?.innerText || ''`);
    ok('Datei gespeichert mit Sicherung', /gespeichert/.test(toast), toast.slice(0, 120));
    ok('Datei auf Platte geändert', readFileSync('/home/work/opensource-erp/backend/templates/demo-editor/schoenert/ident.tex', 'utf8').includes('Testfirma \\& Co'));

    // Zurück in den Design-Modus, Startvorlage anlegen
    await b.waitGone('.swal2-toast');
    { const r = await b.rect('.tpl-toolbar .mdi-drawing-box'); await b.click(r.cx, r.cy); } await sleep(2500);
    await b.showCursor();
    if (await b.eval(`!!document.querySelector('.tpl-preset')`)) { await clickText('.tpl-preset', 'DIN 5008'); await sleep(1500); }
    ok('Design-Modus mit Bausteinen', (await b.eval(`document.querySelectorAll('.tpl-block').length`)) > 3);

    // ── Notizen-Abschnitt aus dem Fließbereich auf die Seite ziehen ──
    const notesIdx = await b.eval(`[...document.querySelectorAll('.tpl-section')].findIndex(e => (e.innerText || '').includes('[notes]'))`);
    const sec = await b.rect('.tpl-section', notesIdx);
    const page = await b.rect('.tpl-page');
    const scale = page.w / 210;
    await b.drag(sec.x + 50, sec.cy, page.x + 125 * scale, page.y + 92 * scale, { steps: 40, stepDelay: 30, holdBefore: 350 });
    await sleep(800);
    const asBlock = await b.eval(`[...document.querySelectorAll('.tpl-block')].findIndex(e => (e.innerText || '').includes('[notes]'))`);
    ok('Abschnitt per Ziehen aus dem Fließbereich zum freien Baustein', asBlock >= 0);
    const bx = await b.eval(`(() => { const t = document.querySelector('.tpl-inspector'); return t ? t.innerText : ''; })()`);
    const blk = await b.rect('.tpl-block', asBlock);
    const mmX = Math.round((blk.x - page.x) / scale), mmY = Math.round((blk.y - page.y) / scale);
    ok('… an der Mausposition abgelegt', Math.abs(mmX - 125) < 6 && Math.abs(mmY - 92) < 6, `${mmX}/${mmY} mm`);
    await b.screenshot(DIR + 'u04-notes-detached.png');

    // Überall platzieren: erst herauszoomen, damit die ganze Seite sichtbar ist
    for (let i = 0; i < 8; i++) { const z = await b.rect('.tpl-toolbar .mdi-magnify-minus-outline'); await b.click(z.cx, z.cy); await sleep(150); }
    await sleep(400);
    const page2 = await b.rect('.tpl-page'); const scale2 = page2.w / 210;
    for (const [tx, ty] of [[120, 250], [10, 5], [60, 150]]) {
        const r = await b.rect('.tpl-block', asBlock);
        await b.drag(r.x + 6, r.y + 4, page2.x + tx * scale2 + 6, page2.y + ty * scale2 + 4, { steps: 30, stepDelay: 25 });
        await sleep(400);
        const r2 = await b.rect('.tpl-block', asBlock);
        const gx = Math.round((r2.x - page2.x) / scale2), gy = Math.round((r2.y - page2.y) / scale2);
        ok(`Notizen frei platziert bei ${tx}/${ty} mm`, Math.abs(gx - tx) < 4 && Math.abs(gy - ty) < 4, `${gx}/${gy} mm`);
    }
    await b.screenshot(DIR + 'u05-notes-anywhere.png');

    // Baustein zurück in den Fließbereich (Knopf)
    const blkNow = await b.rect('.tpl-block', asBlock);
    await b.click(blkNow.cx, blkNow.cy); await sleep(400);
    await clickText('.tpl-inspector button', 'Fließbereich übernehmen'); await sleep(500);
    ok('Zurück in den Fließbereich', await b.eval(`[...document.querySelectorAll('.tpl-section')].some(e => (e.innerText || '').includes('[notes]'))`));

    // Quelltext-Modus bei ungespeichertem Design: Umschalten, dann Design-Datei (erzeugt) prüfen
    await clickText('.tpl-toolbar button', 'Speichern'); await sleep(1500);
    if (await b.eval(`!!document.querySelector('.v-dialog .v-card-title')`)) { await clickText('.v-dialog button', 'Speichern'); await sleep(3000); }
    await b.waitGone('.swal2-toast');
    { const r = await b.rect('.tpl-toolbar .mdi-code-tags'); await b.click(r.cx, r.cy); } await sleep(2500);
    ok('Erzeugte Vorlage als Quelltext mit Warnung', await b.eval(`!!document.querySelector('.tpl-source .v-alert') && (document.querySelector('.cm-content')?.innerText || '').includes('OSERP-Vorlageneditor')`));
    await b.screenshot(DIR + 'u06-source-generated.png');
} catch (e) {
    console.error('FEHLER', e);
    await b.screenshot(DIR + 'u-error.png').catch(() => {});
} finally {
    await b.close();
}
console.log('\n' + results.filter(r => r.ok).length + '/' + results.length + ' Prüfungen bestanden');
