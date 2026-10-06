// Interaktiver Test des Vorlageneditors im echten System (headless Chrome via CDP)
import { Browser, sleep } from './cdp.mjs';
import { readFileSync } from 'node:fs';

const DIR = new URL('.', import.meta.url).pathname;
const SID = readFileSync(DIR + 'session.txt', 'utf8').trim();
const b = new Browser({ port: 9333, profile: '/tmp/claude-1000/cdp-test' });
const results = [];
const ok = (name, cond, info = '') => { results.push({ name, ok: !!cond, info }); console.log((cond ? 'OK   ' : 'FAIL ') + name + (info ? ' — ' + info : '')); };

try {
    await b.launch('about:blank');
    await b.setCookie('opensource_erp', SID, 'localhost');
    await b.goto('https://localhost/');
    await sleep(2500);
    await b.goto('https://localhost/system/vorlageneditor');
    await b.waitFor('.tpl-toolbar', 20000);
    await sleep(2000);
    await b.showCursor();
    await b.screenshot(DIR + 't01-loaded.png');
    const title = await b.eval('document.title');
    ok('Editor geladen', await b.eval('!!document.querySelector(".tpl-toolbar")'), title);

    // Startdialog des aktiven Sets schließen, dann Vorlagensatz demo-editor wählen
    await b.key('Escape'); await sleep(400);
    const sel = await b.rect('.tpl-set-select');
    await b.click(sel.cx, sel.cy);
    await sleep(600);
    const opt = await b.rectByText('.v-list-item', 'demo-editor');
    await b.click(opt.cx, opt.cy);
    await sleep(2500);
    await b.screenshot(DIR + 't02-set.png');
    const hasStart = await b.eval('!!document.querySelector(".tpl-preset")');
    ok('Startdialog für Belegart ohne Design', hasStart);
    if (hasStart) {
        const preset = await b.rectByText('.tpl-preset', 'DIN 5008');
        await b.click(preset.cx, preset.cy);
        await sleep(1500);
    }
    await b.showCursor();
    ok('Design angelegt (Bausteine sichtbar)', (await b.eval('document.querySelectorAll(".tpl-block").length')) > 3);
    await b.screenshot(DIR + 't03-preset.png');

    // Baustein verschieben (Infobox)
    const infoIdx = await b.eval(`[...document.querySelectorAll('.tpl-block')].findIndex(e => e.querySelector('.tpl-infobox'))`);
    const before = await b.rect('.tpl-block', infoIdx);
    await b.drag(before.cx, before.cy, before.cx - 60, before.cy + 45);
    await sleep(400);
    const after = await b.rect('.tpl-block', infoIdx);
    ok('Baustein per Maus verschoben', Math.abs(after.x - before.x) > 30 && Math.abs(after.y - before.y) > 30, `${Math.round(before.x)},${Math.round(before.y)} -> ${Math.round(after.x)},${Math.round(after.y)}`);
    ok('Inspector zeigt Baustein', await b.eval(`document.querySelector('.tpl-inspector-head')?.innerText.includes('Infobox')`));

    // Größe ändern (Griff unten rechts)
    const h = await b.rect('.tpl-handle--se');
    await b.drag(h.cx, h.cy, h.cx + 40, h.cy + 30);
    await sleep(300);
    const resized = await b.rect('.tpl-block', infoIdx);
    ok('Baustein per Griff vergrößert', resized.w > after.w + 20 && resized.h > after.h + 15, `${Math.round(after.w)}x${Math.round(after.h)} -> ${Math.round(resized.w)}x${Math.round(resized.h)}`);

    // Rückgängig
    await b.key('z', { ctrl: true });
    await sleep(300);
    const undone = await b.rect('.tpl-block', infoIdx);
    ok('Strg+Z macht Größenänderung rückgängig', Math.abs(undone.w - after.w) < 2);

    // Pfeiltaste
    await b.key('ArrowRight');
    await sleep(200);
    const nudged = await b.rect('.tpl-block', infoIdx);
    ok('Pfeiltaste verschiebt um 1 mm', nudged.x > undone.x + 1, `${Math.round(undone.x)} -> ${Math.round(nudged.x)}`);

    // Abschnitt (Fließbereich) umsortieren: Bemerkungen-Abschnitt nach oben ziehen
    const order0 = await b.eval(`[...document.querySelectorAll('.tpl-section')].map(e => e.querySelector('.tpl-section-tag')?.innerText.trim())`);
    const notesIdx = await b.eval(`[...document.querySelectorAll('.tpl-section')].findIndex(e => (e.innerText || '').includes('[notes]'))`);
    ok('Notizen-Abschnitt vorhanden', notesIdx >= 0, 'index ' + notesIdx);
    const sec = await b.rect('.tpl-section', notesIdx);
    const first = await b.rect('.tpl-section', 0);
    await b.drag(sec.cx, sec.cy, first.cx, first.y + 4, { steps: 30, stepDelay: 30, holdBefore: 300 });
    await sleep(600);
    const notesIdx2 = await b.eval(`[...document.querySelectorAll('.tpl-section')].findIndex(e => (e.innerText || '').includes('[notes]'))`);
    ok('Abschnitt per Maus nach oben sortiert', notesIdx2 < notesIdx, `${notesIdx} -> ${notesIdx2}`);
    await b.screenshot(DIR + 't04-reorder.png');

    // Abschnitt anklicken -> Inspector, in Baustein umwandeln
    const sec2 = await b.rect('.tpl-section', notesIdx2);
    await b.click(sec2.x + 20, sec2.y + 6);
    await sleep(400);
    ok('Abschnitt ausgewählt', await b.eval(`!!document.querySelector('.tpl-section--selected')`));
    const btn = await b.rectByText('.tpl-inspector button', 'freien Baustein');
    await b.click(btn.cx, btn.cy);
    await sleep(500);
    const asBlock = await b.eval(`[...document.querySelectorAll('.tpl-block')].some(e => (e.innerText || '').includes('[notes]'))`);
    ok('Abschnitt zu freiem Baustein umgewandelt', asBlock);
    const blk = await b.rectByText('.tpl-block', '[notes]');
    await b.drag(blk.cx, blk.cy, blk.cx + 120, blk.cy - 200);
    await sleep(300);
    const blk2 = await b.rectByText('.tpl-block', '[notes]');
    ok('Notizen-Baustein frei verschoben', blk2.y < blk.y - 100, `${Math.round(blk.y)} -> ${Math.round(blk2.y)}`);
    await b.screenshot(DIR + 't05-notes-block.png');

    // Text im Inspector ändern
    const ta = await b.rect('.tpl-inspector textarea');
    await b.click(ta.cx, ta.cy);
    await b.selectAll();
    await b.type('Hinweis: <%notes%>', 10);
    await sleep(500);
    ok('Textänderung erscheint auf der Seite', await b.eval(`[...document.querySelectorAll('.tpl-block')].some(e => (e.innerText || '').includes('Hinweis:'))`));

    // Fließbereich-Oberkante ziehen
    await b.eval("document.querySelector('.tpl-canvas-scroll').scrollTop = 0");
    await sleep(300);
    const edge = await b.rect('.tpl-body-edge--n');
    const bodyBefore = await b.rect('.tpl-body-area');
    await b.screenshot(DIR + 't-edge-before.png');
    console.log('edge', JSON.stringify(edge), 'body', JSON.stringify(bodyBefore));
    await b.drag(edge.cx - 200, edge.cy, edge.cx - 200, edge.cy + 40);
    await sleep(300);
    const bodyAfter = await b.rect('.tpl-body-area');
    ok('Fließbereich-Kante gezogen', bodyAfter.y > bodyBefore.y + 20, `${Math.round(bodyBefore.y)} -> ${Math.round(bodyAfter.y)}`);

    // Palette: Baustein per Klick hinzufügen
    const n0 = await b.eval(`document.querySelectorAll('.tpl-block').length`);
    const pal = await b.rectByText('.tpl-palette-item', 'Linie');
    await b.click(pal.cx, pal.cy);
    await sleep(300);
    ok('Palette-Klick legt Baustein an', (await b.eval(`document.querySelectorAll('.tpl-block').length`)) === n0 + 1);
    await b.key('Delete');
    await sleep(200);
    ok('Entf löscht Baustein', (await b.eval(`document.querySelectorAll('.tpl-block').length`)) === n0);

    // Feld einfügen per Klick (in gewählten Textbaustein)
    const nb = await b.rectByText('.tpl-block', 'Hinweis:');
    await b.click(nb.cx, nb.cy);
    await sleep(200);
    const fld = await b.rectByText('.tpl-field', 'Rechnungsnummer');
    await b.click(fld.cx, fld.cy);
    await sleep(300);
    ok('Feld aus Palette eingefügt', await b.eval(`[...document.querySelectorAll('.tpl-block')].some(e => (e.innerText || '').includes('252923') || (e.innerText || '').includes('[invnumber]'))`));

    // Folgeseiten-Ansicht
    const pv = await b.rectByText('.tpl-pageview button', '2+');
    await b.click(pv.cx, pv.cy);
    await sleep(400);
    ok('Folgeseiten-Ansicht', await b.eval(`document.querySelector('.tpl-body-label')?.innerText.includes('Folgeseiten')`));
    await b.screenshot(DIR + 't06-following.png');
    const pv1 = await b.rectByText('.tpl-pageview button', '1');
    await b.click(pv1.cx, pv1.cy);

    // PDF-Vorschau (headless: gerasterte Seiten)
    const prev = await b.rectByText('.tpl-toolbar button', 'PDF-Vorschau');
    await b.click(prev.cx, prev.cy);
    const t0 = Date.now();
    let pages = 0;
    while (Date.now() - t0 < 40000) { pages = await b.eval(`document.querySelectorAll('.tpl-preview-page, iframe.tpl-preview-frame').length`); if (pages) break; if (await b.eval(`!!document.querySelector('.tpl-preview-error')`)) break; await sleep(500); }
    // Bild-Modus ebenfalls prüfen
    const sw = await b.rect('.tpl-preview-bar .mdi-image-multiple-outline').catch(() => null);
    if (sw) { await b.click(sw.cx, sw.cy); const t1 = Date.now(); let img = 0; while (Date.now() - t1 < 40000) { img = await b.eval(`document.querySelectorAll('.tpl-preview-page').length`); if (img) break; await sleep(500); } ok('Vorschau als Bilder', img > 0, img + ' Seite(n)'); }
    const perr = await b.eval(`document.querySelector('.tpl-preview-error')?.innerText || ''`);
    ok('PDF-Vorschau gerendert', pages > 0, pages ? `${pages} Seite(n) in ${Date.now() - t0} ms` : perr.slice(0, 300));
    await sleep(500);
    await b.screenshot(DIR + 't07-preview.png');

    // Speichern
    const save = await b.rectByText('.tpl-toolbar button', 'Speichern');
    await b.click(save.cx, save.cy);
    await sleep(1500);
    const dlg = await b.eval(`!!document.querySelector('.v-dialog .v-card-title')`);
    if (dlg) {
        const confirm = await b.rectByText('.v-dialog button', 'Speichern');
        await b.click(confirm.cx, confirm.cy);
        await sleep(3000);
    }
    await b.screenshot(DIR + 't08-saved.png');
    const toast = await b.eval(`document.querySelector('.swal2-toast')?.innerText || ''`);
    ok('Speichern', /Gespeichert/.test(toast), toast.slice(0, 160));

    // Versionen-Dialog
    await sleep(2500);
    const menu = await b.rect('.tpl-toolbar .mdi-dots-vertical');
    await b.click(menu.cx, menu.cy);
    await sleep(800);
    const vers = await b.rectByText('.v-list-item', 'Frühere Versionen');
    await b.click(vers.cx, vers.cy);
    await sleep(500);
    ok('Versionen-Dialog', await b.eval(`[...document.querySelectorAll('.v-dialog .v-list-item')].length >= 1`));
    await b.screenshot(DIR + 't09-versions.png');
    await b.key('Escape');
} catch (e) {
    console.error('FEHLER', e);
    await b.screenshot(DIR + 't-error.png').catch(() => {});
} finally {
    await b.close();
}
console.log('\n' + results.filter(r => r.ok).length + '/' + results.length + ' Prüfungen bestanden');
