/**
 * Warenkorbzaehler im Header des mitgelieferten Themes aktuell halten.
 *
 * WARUM DAS HIER STEHT UND NICHT IM THEME
 * Der Zaehler sitzt in `partials/header/account-buttons.html` — im Theme
 * `hugoshop`, das sich mehrere Shops teilen (solar-spar.de, auto-spar.de ...),
 * von denen die meisten die shop-ui gar nicht eingebunden haben. Das Theme
 * darf deshalb nicht angefasst werden. Also uebersetzt dieses Modul in die
 * Gegenrichtung: es hoert auf `shop:cart-changed` und schreibt in das Markup,
 * das ohnehin schon da ist.
 *
 * Das ist bewusst der einzige Ort im Bundle, der fremdes Markup anfasst.
 * Frueher tat das jedes Widget selbst — `inCart()` und `viewCart()` schrieben
 * direkt in `#cart-count`, `#cart-button` und `#empty-cart-button`, und wenn
 * eines davon fehlte, brach die Funktion mit einem TypeError ab. Hier ist
 * jedes fehlende Element schlicht ein no-op, das Modul laeuft also auch unter
 * einem fremden Theme ohne Schaden.
 *
 * UEBERGANGSLOESUNG. Sobald der Header selbst wandert, wird daraus eine
 * richtige Komponente (`<shop-cart-badge>`) und diese Datei entfaellt.
 */
import { on, SHOP_CART_CHANGED } from '../core/bus.js';

let known = null;

function apply(count) {
  const label = document.getElementById('cart-count');
  const cartButton = document.getElementById('cart-button');
  const emptyButton = document.getElementById('empty-cart-button');
  if (!label && !cartButton && !emptyButton) return; // fremdes Theme

  if (label) label.textContent = String(count);
  // Genau die Umschaltung des alten shopwindow.js: gefuellt zeigt den
  // primaeren Link, leer den sekundaeren Knopf (der das Popover traegt).
  if (cartButton) cartButton.style.display = count > 0 ? 'block' : 'none';
  if (emptyButton) emptyButton.style.display = count > 0 ? 'none' : 'block';
}

on(SHOP_CART_CHANGED, (event) => {
  const count = event.detail && event.detail.count;
  if (typeof count !== 'number') return;
  known = count;
  apply(count);
});

// shopwindow.js fragt den Kontext im window-load-Handler ab und schreibt das
// Ergebnis in dieselben Elemente. Legt jemand etwas in den Warenkorb, bevor
// diese Antwort eintrifft, wuerde sie den Zaehler wieder auf den alten Stand
// zuruecksetzen. Deshalb hier das letzte Wort behalten.
window.addEventListener('load', () => {
  if (known !== null) setTimeout(() => apply(known), 0);
});
