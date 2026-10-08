/**
 * Splitbuchung — Positionen eines Bruttobetrags auf mehrere Konten/Steuersätze.
 *
 * Reine Rechenhilfen ohne Zustand, geteilt von der Komponente
 * split-lines.component.vue und den Dialogen, die sie einbinden (Kasse,
 * Eingangsrechnung aus Bankumsatz). Eine Position ist
 *   { account: Objekt aus der Kontenliste | null, gross: Zahl | '', tax: Wert aus taxItems }
 * — `tax` ist je Dialog ein Steuersatz (19/7/0) oder eine tax.id, die
 * Komponente kennt nur den zugehörigen `rate` aus den taxItems.
 */

export function newLine(account = null, tax = null, gross = '') {
    return { account, gross, tax }
}

export function round2(v) {
    return Math.round((Number(v) || 0) * 100) / 100
}

/** Summe der Positionsbeträge (brutto) */
export function linesSum(lines) {
    return round2(lines.reduce((acc, l) => acc + (Number(l.gross) || 0), 0))
}

/** Was vom Gesamtbetrag noch nicht verteilt ist (0 = Aufteilung geht auf) */
export function remainder(total, lines) {
    return round2((Number(total) || 0) - linesSum(lines))
}

/** Netto und Steuer aus einem Bruttobetrag und einem Satz als Bruchteil (0.19) */
export function netTax(gross, rate) {
    const g   = Number(gross) || 0
    const r   = Number(rate)  || 0
    const net = r > 0 ? round2(g / (1 + r)) : round2(g)
    return { net, tax: round2(g - net) }
}

/**
 * Prüfung vor dem Speichern. Liefert null, wenn alles stimmt, sonst einen
 * Fehlerschlüssel (BankingView.split.*), den der Dialog übersetzt anzeigt.
 */
export function linesError(total, lines) {
    if (!lines.length) return 'errorNoLines'
    if (lines.some(l => !l.account)) return 'errorAccount'
    if (lines.length === 1) return null
    if (lines.some(l => !(Number(l.gross) > 0))) return 'errorAmount'
    if (remainder(total, lines) !== 0) return 'errorRemainder'
    return null
}
