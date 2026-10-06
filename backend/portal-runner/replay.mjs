#!/usr/bin/env node
// backend/portal-runner/replay.mjs
//
// Spielt eine mit dem Chrome-Recorder aufgezeichnete Portal-Sitzung ab
// (Login → Rechnungsliste → Download) und sammelt die heruntergeladenen
// Dateien ein. Aufruf durch beleg_quellen.php (_bq_runPortal):
//
//   node replay.mjs --flow <aufnahme.json> --out <download-dir> [--all] [--max 50] [--timeout 20000]
//   Zugangsdaten per Umgebung: PORTAL_USER, PORTAL_PASS (ersetzen {{username}} / {{password}})
//
// Ausgabe (stdout, eine JSON-Zeile): {ok, files:[...], steps, downloads, error, failedStep}
//
// "--all": der Klick, der in der Aufnahme den Download ausgeloest hat, wird auf
// alle gleichartigen Elemente der Seite ausgeweitet (z. B. jede Zeile der
// Rechnungsliste) — so kommen alle Rechnungen, nicht nur die vorgemachte.

import fs from 'node:fs'
import path from 'node:path'
import puppeteer from 'puppeteer'
import { createRunner, PuppeteerRunnerExtension, parse } from '@puppeteer/replay'

const args = Object.fromEntries(process.argv.slice(2).reduce((acc, a, i, arr) => {
    if (a.startsWith('--')) acc.push([a.slice(2), arr[i + 1] && !arr[i + 1].startsWith('--') ? arr[i + 1] : true])
    return acc
}, []))
const flowPath = args.flow
const outDir   = args.out
const wantAll  = !!args.all
const maxFiles = parseInt(args.max || '50', 10)
const stepTimeout = parseInt(args.timeout || '20000', 10)

let resultWritten = false
function out(obj) { resultWritten = true; process.stdout.write(JSON.stringify(obj) + '\n') }
// Nach einem Fehler raeumt Puppeteer noch auf und wirft dabei gern verwaiste
// Navigations-Promises (LifecycleWatcher). Das Ergebnis steht dann schon auf
// stdout — nicht mit Stacktrace abstuerzen, sondern still beenden.
process.on('unhandledRejection', (e) => { if (!resultWritten) out({ ok: false, error: String(e && (e.message || e)) }); process.exit(resultWritten ? 0 : 1) })
if (!flowPath || !outDir) { out({ ok: false, error: 'Parameter --flow und --out fehlen' }); process.exit(2) }

const user = process.env.PORTAL_USER || ''
const pass = process.env.PORTAL_PASS || ''

// Platzhalter in der Aufnahme durch Zugangsdaten ersetzen
let flowJson = fs.readFileSync(flowPath, 'utf8')
flowJson = flowJson.replaceAll('{{username}}', JSON.stringify(user).slice(1, -1)).replaceAll('{{password}}', JSON.stringify(pass).slice(1, -1))
const flow = parse(JSON.parse(flowJson))

fs.mkdirSync(outDir, { recursive: true })
const listFiles = () => fs.readdirSync(outDir).filter(f => !f.endsWith('.crdownload') && !f.startsWith('.'))
async function waitDownloadsSettled(ms = 15000) {
    const t0 = Date.now()
    let last = -1
    while (Date.now() - t0 < ms) {
        const pending = fs.readdirSync(outDir).filter(f => f.endsWith('.crdownload')).length
        const done = listFiles().length
        if (pending === 0 && done === last) return
        last = done
        await new Promise(r => setTimeout(r, 700))
    }
}

/** Selektor ohne Zeilen-Index: "tr:nth-child(3) > td > a" → "tr > td > a" */
function generalizeCss(sel) {
    return sel.replace(/:nth-child\(\d+\)/g, '').replace(/:nth-of-type\(\d+\)/g, '')
}

const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--lang=de-DE'],
})
let page, downloadStepIndex = -1, stepsRun = 0, failedStep = null
try {
    page = await browser.newPage()
    await page.setViewport({ width: 1280, height: 900 })
    const cdp = await page.createCDPSession()
    await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: outDir, eventsEnabled: true })

    // Nach jedem Schritt pruefen, ob ein Download begonnen hat → merken, welcher
    // Schritt ihn ausgeloest hat (fuer --all).
    class Ext extends PuppeteerRunnerExtension {
        async runStep(step, flow) {
            const before = fs.readdirSync(outDir).length
            try {
                await super.runStep(step, flow)
            } catch (e) {
                failedStep = { index: stepsRun, type: step.type, selectors: step.selectors, error: String(e.message || e) }
                throw e
            }
            await new Promise(r => setTimeout(r, 400))
            if (downloadStepIndex < 0 && fs.readdirSync(outDir).length > before) downloadStepIndex = stepsRun
            stepsRun++
        }
    }
    const runner = await createRunner(flow, new Ext(browser, page, { timeout: stepTimeout }))
    await runner.run()
    await waitDownloadsSettled()

    // Ausweitung auf alle gleichartigen Download-Elemente
    let expanded = 0
    if (wantAll && downloadStepIndex >= 0) {
        const step = flow.steps[downloadStepIndex]
        const cssSelectors = (step.selectors || []).flat().filter(s => typeof s === 'string' && !s.startsWith('xpath/') && !s.startsWith('aria/') && !s.startsWith('text/') && !s.startsWith('pierce/'))
        const seen = new Set()
        for (const sel of cssSelectors) {
            const gen = generalizeCss(sel)
            if (gen === sel || seen.has(gen)) continue
            seen.add(gen)
            const handles = await page.$$(gen)
            if (handles.length < 2) continue
            for (let i = 0; i < handles.length && listFiles().length < maxFiles; i++) {
                const before = fs.readdirSync(outDir).length
                try {
                    await handles[i].click()
                } catch (e) { continue }
                await new Promise(r => setTimeout(r, 1200))
                // Manche Portale oeffnen das PDF in einem neuen Tab statt es zu laden
                const pages = await browser.pages()
                for (const p of pages) { if (p !== page) { try { await p.close() } catch (e) {} } }
                if (fs.readdirSync(outDir).length > before) expanded++
            }
            break
        }
        await waitDownloadsSettled()
    }

    out({ ok: true, files: listFiles().map(f => path.join(outDir, f)), steps: stepsRun, downloads: listFiles().length, expanded, downloadStep: downloadStepIndex })
} catch (e) {
    out({ ok: false, error: String(e.message || e), steps: stepsRun, failedStep, files: listFiles().map(f => path.join(outDir, f)) })
} finally {
    await browser.close()
}
