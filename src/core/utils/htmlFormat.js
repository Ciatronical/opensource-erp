// src/core/utils/htmlFormat.js
/**
 * HTML lesbar einrücken, für den Quelltext-Dialog des visuellen Editors.
 *
 * Der Editor liefert sein HTML in einer einzigen Zeile. Hier bekommt jedes
 * Blockelement eine eigene Zeile und je Verschachtelungsstufe einen Einzug;
 * Inline-Auszeichnungen (strong, em, a, br …) bleiben im Textfluss. Der
 * Inhalt von <pre> wird unverändert übernommen, weil dort jedes Zeichen
 * zählt. Die eingefügten Zeilenumbrüche stehen nur zwischen Blöcken und
 * verschwinden beim Zurücklesen wieder.
 */

const BLOCK = new Set([
    'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'blockquote', 'pre', 'hr', 'img', 'figure', 'figcaption',
    'div', 'section', 'article',
])

const LEER = new Set(['hr', 'img', 'br'])

/**
 * @param {string} html   Quelltext in einer Zeile
 * @param {string} indent Einzug je Stufe
 * @returns {string} eingerückter Quelltext
 */
export function formatHtml(html, indent = '    ') {
    const tokens = String(html ?? '').split(/(<\/?[a-zA-Z][^>]*>)/)
    let out = ''
    let line = ''       // Zeile ohne Einzug
    let lineDepth = 0   // Einzug der aktuellen Zeile
    let depth = 0
    let inPre = false

    const endLine = (trim = true) => {
        if (line === '') return
        out += indent.repeat(lineDepth) + (trim ? line.trimEnd() : line) + '\n'
        line = ''
    }

    for (const token of tokens) {
        if (token === '') continue

        if (inPre) {
            line += token
            if (/^<\/pre\s*>$/i.test(token)) {
                inPre = false
                depth = Math.max(0, depth - 1)
                endLine(false)
            }
            continue
        }

        const m = token.match(/^<(\/?)([a-zA-Z][\w-]*)/)
        const name = m ? m[2].toLowerCase() : ''

        if (m && BLOCK.has(name)) {
            const closing = m[1] === '/'
            if (closing) {
                depth = Math.max(0, depth - 1)
                if (line === '') lineDepth = depth
                line += token
                endLine()
            } else if (LEER.has(name) || token.endsWith('/>')) {
                endLine()
                lineDepth = depth
                line = token
                endLine()
            } else {
                endLine()
                lineDepth = depth
                line = token
                depth++
                if (name === 'pre') inPre = true
            }
            continue
        }

        // Inline-Tag oder Text
        if (line === '') {
            const text = token.replace(/^\s+/, '')
            if (text === '') continue
            lineDepth = depth
            line = text
        } else {
            line += token
        }
    }
    endLine()
    return out.trimEnd()
}
