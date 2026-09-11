/**
 * Tailwind-Build fuer die Demo-Seite.
 *
 * Wichtig ist hier vor allem `content`: Tailwind erzeugt CSS nur fuer
 * Klassen, die es beim Bauen findet. Die Klassen der Widgets stehen in
 * src/core/presets.js und landen unverändert im Bundle. Ein Shop trägt in
 * seiner eigenen tailwind.config.js deshalb das ausgelieferte Bundle ein
 * (./oserp-shop/assets/shop-ui/*.js).
 *
 * Aufruf aus dem shop-ui-Verzeichnis: npm run demo:css
 */
export default {
  content: ['./src/**/*.js', './demo/index.html'],
  theme: { extend: {} },
  plugins: [],
};
