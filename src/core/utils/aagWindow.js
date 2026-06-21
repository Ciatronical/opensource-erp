// src/core/utils/aagWindow.js
//
// Öffnet AAG-Online als eigenes App-Fenster (Popup ohne Tab-Leiste/Lesezeichen)
// statt als zusätzlichen Browser-Tab. Wird beim Klick synchron aufgerufen
// (leeres Fenster sofort öffnen → vermeidet Popup-Blocker), die URL wird
// danach gesetzt, sobald die Portal-URL vorliegt.
//
// Hinweis: Eine schmale, schreibgeschützte Herkunftsanzeige zeigen moderne
// Browser bei Popups aus Sicherheitsgründen weiterhin an – sie lässt sich per
// JavaScript nicht entfernen. Tab-Leiste, Lesezeichen und Menü entfallen aber.

// Genau EIN AAG-Fenster für die ganze App. Wir halten das Window-Handle modulweit
// (überlebt SPA-Navigation), statt uns auf den Fensternamen zu verlassen — die
// AAG-Seite überschreibt `window.name` teils selbst, dann fände der Browser das
// bestehende Fenster nicht mehr und öffnete ein zweites.
let sharedAagWindow = null
let sharedAagCarId = null

/** Ist das gemeinsame AAG-Fenster aktuell offen? */
export function aagWindowOpen() {
    return !!(sharedAagWindow && !sharedAagWindow.closed)
}

/** Fahrzeug-ID, die das AAG-Fenster aktuell zeigt (oder null, wenn geschlossen). */
export function aagWindowCarId() {
    return aagWindowOpen() ? sharedAagCarId : null
}

/** Merkt, welches Fahrzeug das AAG-Fenster gerade zeigt. */
export function setAagWindowCarId(id) {
    sharedAagCarId = id
}

/**
 * Liefert das gemeinsame AAG-Fenster (bringt es in den Vordergrund) oder öffnet
 * ein neues, falls keines offen ist. Es gibt immer höchstens EIN AAG-Fenster.
 *
 * Das Fenster wird auf die nutzbare Bildschirmgröße maximiert (ohne Taskleiste)
 * und oben links positioniert. Echtes OS-Vollbild (F11) ist nicht möglich, da
 * die geöffnete Seite cross-origin ist.
 *
 * Wird ein Titel übergeben (nur bei NEU geöffnetem Fenster), zeigt das Fenster
 * bis zum Laden der cross-origin-Seite einen Lade-Splash mit diesem Titel.
 *
 * @param {string} name Fenstername (Fallback-Wiederverwendung nach Full-Reload)
 * @param {string} [title] Optionaler Titel/Überschrift für den Lade-Splash
 * @returns {Window|null}
 */
export function openAppWindow(name = 'aag-online', title = '') {
    // Bereits offenes AAG-Fenster wiederverwenden → genau ein Fenster.
    if (aagWindowOpen()) {
        sharedAagWindow.focus()
        return sharedAagWindow
    }
    const w = window.screen?.availWidth || 1920
    const h = window.screen?.availHeight || 1080
    const left = 0
    const top = 0
    // popup=yes + die klassischen "chromeless"-Flags: Erstere ist der moderne
    // Hinweis, Letztere erzwingen plattformübergreifend (v.a. Chrome/Edge unter
    // Windows) zuverlässig ein eigenes Fenster statt eines Tabs. Ein Browser
    // KANN ein Fenster nicht als Tab darstellen, wenn toolbar/menubar/location
    // ausgeblendet sind – daher fällt es auf ein Popup-Fenster zurück.
    const features = [
        'popup=yes',
        'menubar=no',
        'toolbar=no',
        'location=no',
        'status=no',
        'scrollbars=yes',
        'resizable=yes',
        `width=${w}`,
        `height=${h}`,
        `left=${left}`,
        `top=${top}`,
    ].join(',')
    const win = window.open('', name, features)
    sharedAagWindow = win
    sharedAagCarId = null
    if (win && title) renderSplash(win, title)
    return win
}

/**
 * Schreibt einen zentrierten Lade-Splash mit Titel in das frisch geöffnete
 * (noch leere, daher same-origin) Fenster und setzt `document.title`. Wird per
 * DOM aufgebaut statt per HTML-String – so ist kein Escaping nötig.
 *
 * @param {Window} win Ziel-Fenster
 * @param {string} title Anzuzeigender Titel
 */
function renderSplash(win, title) {
    try {
        const doc = win.document
        doc.open()
        doc.write('<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"></head><body></body></html>')
        doc.close()
        doc.title = title

        const body = doc.body
        body.style.cssText =
            'margin:0;height:100vh;display:flex;flex-direction:column;align-items:center;' +
            'justify-content:center;gap:1.25rem;background:#1e293b;color:#e2e8f0;' +
            "font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;"

        const spinner = doc.createElement('div')
        spinner.style.cssText =
            'width:42px;height:42px;border:4px solid rgba(148,163,184,.35);' +
            'border-top-color:#818cf8;border-radius:50%;animation:aagspin 1s linear infinite;'

        const heading = doc.createElement('div')
        heading.textContent = title
        heading.style.cssText = 'font-size:1.35rem;font-weight:600;text-align:center;padding:0 1rem;'

        const style = doc.createElement('style')
        style.textContent = '@keyframes aagspin{to{transform:rotate(360deg)}}'
        doc.head.appendChild(style)

        body.appendChild(spinner)
        body.appendChild(heading)
    } catch {
        // Fenster bereits cross-origin o. Ä. – Splash ist nur Beiwerk, ignorieren.
    }
}
