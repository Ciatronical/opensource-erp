// src/core/theme/palettes.js

/**
 * Farbschemata der Oberfläche.
 *
 * Eine Stelle für alle Paletten. Die Firma legt in der Firmenkonfiguration
 * (CRM → Darstellung, Schlüssel theme_palette in defaults_oserp) den Standard
 * fest, jeder Benutzer kann ihn in seiner Benutzerkonfiguration überschreiben
 * (theme_palette in employee_config_oserp, leer = Firmenstandard).
 *
 * Es gibt weiterhin genau zwei Vuetify-Themes, light und dark: Die Palette
 * schreibt nur deren Farben um. So bleiben .v-theme--dark in tokens.css und
 * theme.global.current.value.dark in den Views unverändert gültig.
 *
 * Jede helle Primärfarbe trägt weiße Schrift mit mindestens 4,5:1 (WCAG AA),
 * damit „Speichern“ auf jedem Schema lesbar bleibt. Die dunklen Primärfarben
 * sind helle Töne, auf denen Vuetify automatisch dunkle Schrift wählt.
 *
 * Die Flächen (Menüleiste, Abschnittsköpfe, ruhige Flächen) werden aus der
 * Primärfarbe gemischt. Blau hält sie neutral grau — so sieht der Standard
 * genauso aus wie vor der Themeauswahl.
 */

export const DEFAULT_PALETTE = 'blau'

export const THEME_PALETTES = {
    blau:     { light: '#1976D2', dark: '#90CAF9', neutral: true },
    indigo:   { light: '#3949AB', dark: '#9FA8DA' },
    petrol:   { light: '#00796B', dark: '#80CBC4' },
    gruen:    { light: '#2E7D32', dark: '#A5D6A7' },
    violett:  { light: '#5E35B1', dark: '#B39DDB' },
    rosa:     { light: '#D81B60', dark: '#F48FB1' },
    bordeaux: { light: '#8B1E3F', dark: '#CF8A9E' },
    orange:   { light: '#C2410C', dark: '#FFB74D' },
    graphit:  { light: '#455A64', dark: '#B0BEC5', neutral: true },
}

export const THEME_MODES = ['light', 'dark', 'system']

const LIGHT_SURFACE = '#FFFFFF'
const DARK_SURFACE = '#1E1E1E'

/** Mischt zwei Hex-Farben; anteil = Gewicht von a (0..1) */
function mix(a, b, anteil) {
    const pa = parseInt(a.slice(1), 16)
    const pb = parseInt(b.slice(1), 16)
    const kanal = shift => Math.round(((pa >> shift) & 255) * anteil + ((pb >> shift) & 255) * (1 - anteil))
    return '#' + [16, 8, 0].map(s => kanal(s).toString(16).padStart(2, '0')).join('').toUpperCase()
}

/** Gültiger Palettenschlüssel oder null */
export function paletteKey(value) {
    return value && Object.hasOwn(THEME_PALETTES, value) ? value : null
}

/**
 * Farben, die eine Palette in das helle und das dunkle Vuetify-Theme schreibt.
 * appbar, section-head und surface-soft sind eigene Theme-Farben; tokens.css
 * und die Menüleiste greifen über --v-theme-… darauf zu.
 */
export function paletteColors(key) {
    const p = THEME_PALETTES[paletteKey(key) || DEFAULT_PALETTE]
    const neutralLight = '#F5F6F8'

    return {
        light: {
            primary: p.light,
            appbar: p.neutral ? '#F5F5F5' : mix(p.light, LIGHT_SURFACE, 0.10),
            'section-head': p.neutral ? neutralLight : mix(p.light, LIGHT_SURFACE, 0.06),
            'surface-soft': p.neutral ? neutralLight : mix(p.light, LIGHT_SURFACE, 0.04),
        },
        dark: {
            primary: p.dark,
            appbar: mix(p.dark, DARK_SURFACE, p.neutral ? 0.06 : 0.12),
            'section-head': mix(p.dark, DARK_SURFACE, p.neutral ? 0.04 : 0.08),
            'surface-soft': mix(p.dark, DARK_SURFACE, p.neutral ? 0.05 : 0.07),
        },
    }
}

/** Schreibt die Palette in die beiden Vuetify-Themes (theme = useTheme()) */
export function applyPalette(theme, key) {
    const farben = paletteColors(key)
    for (const modus of ['light', 'dark']) {
        Object.assign(theme.themes.value[modus].colors, farben[modus])
    }
}
