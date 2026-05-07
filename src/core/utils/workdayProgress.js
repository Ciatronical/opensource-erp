// Baut einen SVG-Fortschrittsbalken für den Arbeitstag.
// Segmente: Arbeit vor Pause (blau), Pause (orange), Arbeit nach Pause (blau).
// Vergangene Zeit ist gefärbt, zukünftige Zeit ist grün.
export function buildWorkdayProgressSVG(config, svgWidth = 160, svgHeight = 36) {
    const { dayStart, dayEnd, breakStart, breakEnd } = config
    if (!dayStart || !dayEnd || dayEnd <= dayStart) return ''

    const now = new Date()
    const nowMin = now.getHours() * 60 + now.getMinutes()
    const total = dayEnd - dayStart
    const hasBreak = breakStart !== null && breakEnd !== null
        && breakEnd > breakStart && breakStart >= dayStart && breakEnd <= dayEnd

    const px = min => Math.round(
        ((Math.min(Math.max(min, dayStart), dayEnd) - dayStart) / total) * svgWidth
    )

    const parts = [
        `<svg xmlns="http://www.w3.org/2000/svg" width="${svgWidth}" height="${svgHeight}">`,
        // Hintergrund: grün = verbleibende Arbeitszeit
        `<rect width="${svgWidth}" height="${svgHeight}" rx="0" fill="#81c784"/>`,
        `<g>`
    ]

    // Pause-Hintergrund (zeigt Lage der Pause — orange hinterliegt)
    if (hasBreak) {
        const bx = px(breakStart)
        const bw = px(breakEnd) - bx
        if (bw > 0) parts.push(`<rect x="${bx}" y="0" width="${bw}" height="${svgHeight}" fill="#ffa726"/>`)
    }

    // Vergangene Arbeitszeit vor der Pause (oder ohne Pause)
    if (nowMin > dayStart) {
        const end = hasBreak ? Math.min(nowMin, breakStart) : Math.min(nowMin, dayEnd)
        const w = px(end)
        if (w > 0) parts.push(`<rect x="0" y="0" width="${w}" height="${svgHeight}" fill="#1976d2"/>`)
    }

    // Vergangene Pausenzeit
    if (hasBreak && nowMin > breakStart) {
        const bx = px(breakStart)
        const bw = px(Math.min(nowMin, breakEnd)) - bx
        if (bw > 0) parts.push(`<rect x="${bx}" y="0" width="${bw}" height="${svgHeight}" fill="#f57c00"/>`)
    }

    // Vergangene Arbeitszeit nach der Pause
    if (hasBreak && nowMin > breakEnd) {
        const ax = px(breakEnd)
        const aw = px(Math.min(nowMin, dayEnd)) - ax
        if (aw > 0) parts.push(`<rect x="${ax}" y="0" width="${aw}" height="${svgHeight}" fill="#1976d2"/>`)
    }

    parts.push(`</g></svg>`)
    return parts.join('')
}

export function parseWorkdayConfig(getClientDefaultValue) {
    const parse = str => {
        const m = (str || '').trim().match(/^(\d{1,2}):(\d{2})/)
        return m ? parseInt(m[1]) * 60 + parseInt(m[2]) : null
    }
    return {
        dayStart:   parse(getClientDefaultValue('calendar_day_start') || '07:00'),
        dayEnd:     parse(getClientDefaultValue('calendar_day_end')   || '19:00'),
        breakStart: parse(getClientDefaultValue('calendar_break_start')),
        breakEnd:   parse(getClientDefaultValue('calendar_break_end'))
    }
}
