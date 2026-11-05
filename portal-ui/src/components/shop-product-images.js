import { html, css, nothing } from 'lit';
import { PortalElement } from '../core/base.js';
import { t } from '../core/i18n.js';

/**
 * <shop-product-images>
 *   <a href="/images/shop/artikel/a.png"><img src="/images/shop/artikel/a.png" alt="Artikel - Ansicht 1"></a>
 *   <a href="/images/shop/artikel/b.png"><img src="/images/shop/artikel/b.png" alt="Artikel - Ansicht 2" loading="lazy"></a>
 * </shop-product-images>
 *
 * Produktbilder mit Vergrößerung (Lightbox). Ersetzt das Bootstrap-Karussell
 * der erzeugten Produktseiten (product.md.php), dessen Klassen jedes Theme
 * nachbauen musste; der Link öffnete das Bild in einem neuen Tab.
 *
 * Die Bilder stehen als gewöhnliche Links im Light DOM. Ohne Bündel — oder
 * bevor es geladen ist — sieht der Besucher sie trotzdem, Suchmaschinen lesen
 * die alt-Texte, und der Link öffnet das Bild wie bisher. Bis zum ersten
 * Rendern bleibt das Light DOM über einen Slot sichtbar: das Hauptbild ist
 * das größte Bild der Seite und soll nicht erst mit dem Theme erscheinen.
 *
 * Die Vergrößerung ist ein natives <dialog> mit showModal(): es liegt in der
 * obersten Ebene des Browsers, unabhängig von z-index und overflow der Seite
 * und von der Shadow-Grenze. Escape schließt (Browser), dazu jeder Klick auf
 * das Bild oder daneben — nur die Knöpfe nicht; eine Fußzeile sagt das.
 * Pfeiltasten und Wischen blättern.
 *
 * Maße von außen über Custom Properties: --shop-product-image-height
 * (Hauptbild, Vorgabe 30rem), --shop-product-thumb-size (Vorschau, 4rem),
 * --shop-lightbox-bg (Hintergrund der Vergrößerung).
 */
export class ShopProductImages extends PortalElement {
  static properties = {
    _images: { state: true },
    _current: { state: true },
    _open: { state: true },
  };

  static styles = [
    PortalElement.baseStyles,
    css`
      /* Feste Höhen: das Hauptbild ist immer gleich hoch, egal welches
         Seitenverhältnis das Bild hat, Hinweis und Blätterknöpfe ebenso.
         So bleibt alles darunter stehen — beim ersten Rendern wie beim
         Blättern. Dieselben Maße reserviert placeholder.css im Paket, bevor
         das Bündel geladen ist; beide Stellen gehören zusammen geändert. */
      .main {
        display: block;
        width: 100%;
        height: var(--shop-product-image-height, 30rem);
        padding: 0;
        border: var(--shop-border-width, 1px) solid var(--shop-border-color, #dee2e6);
        border-radius: var(--shop-radius, 0.375rem);
        background: var(--shop-image-bg, #fff);
        cursor: zoom-in;
        overflow: hidden;
      }
      .main img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
      .hint {
        height: 1.5rem;
        margin: 0.5rem 0;
        line-height: 1.5rem;
        overflow: hidden;
      }
      .nav {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        height: 2.5rem;
      }
      .nav button {
        display: inline-flex;
        align-items: center;
        cursor: pointer;
      }
      .thumbs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0.75rem 0 0;
        padding: 0;
        list-style: none;
      }
      .thumbs button {
        padding: 0;
        border: 2px solid transparent;
        border-radius: var(--shop-radius, 0.375rem);
        background: var(--shop-image-bg, #fff);
        cursor: pointer;
      }
      .thumbs button[aria-current='true'] {
        border-color: var(--shop-accent, #0d6efd);
      }
      .thumbs img {
        display: block;
        width: var(--shop-product-thumb-size, 4rem);
        height: var(--shop-product-thumb-size, 4rem);
        object-fit: contain;
      }
      svg {
        flex: none;
      }

      /* Die Vergrößerung: der Dialog selbst ist die dunkle Fläche */
      dialog {
        inset: 0;
        width: 100%;
        height: 100%;
        max-width: none;
        max-height: none;
        margin: 0;
        padding: 0;
        border: 0;
        background: var(--shop-lightbox-bg, rgba(0, 0, 0, 0.92));
        color: #fff;
      }
      dialog::backdrop {
        background: transparent;
      }
      .stage {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        padding: 3.5rem 1rem;
        touch-action: none;
      }
      .stage img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        user-select: none;
        -webkit-user-drag: none;
      }
      .lb-button {
        position: absolute;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.75rem;
        height: 2.75rem;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
        cursor: pointer;
      }
      .lb-button:hover,
      .lb-button:focus-visible {
        background: rgba(255, 255, 255, 0.35);
        outline: 2px solid #fff;
      }
      .lb-close {
        top: 0.75rem;
        right: 0.75rem;
      }
      .lb-prev,
      .lb-next {
        top: 50%;
        transform: translateY(-50%);
      }
      .lb-prev {
        left: 0.75rem;
      }
      .lb-next {
        right: 0.75rem;
      }
      .lb-footer {
        position: absolute;
        right: 0;
        bottom: 0.75rem;
        left: 0;
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 0.25rem 1.5rem;
        padding: 0 1rem;
        font-size: 0.875rem;
        text-align: center;
        opacity: 0.8;
      }
    `,
  ];

  #slot = null;
  #pointerX = null;
  #swiped = false;

  constructor() {
    super();
    this._images = [];
    this._current = 0;
    this._open = false;
  }

  // Der Slot zeigt das Light DOM, bis das erste Rendern es ersetzt. Lit hängt
  // seinen Marker hinter vorhandene Kinder, der Slot bleibt also bis
  // firstUpdated() stehen.
  createRenderRoot() {
    const root = super.createRenderRoot();
    this.#slot = document.createElement('slot');
    root.append(this.#slot);
    return root;
  }

  connectedCallback() {
    super.connectedCallback();
    this.#readImages();
  }

  firstUpdated() {
    if (this.#slot) this.#slot.remove();
    this.#slot = null;
  }

  /** Bilder aus den Links im Light DOM — Reihenfolge wie dort */
  #readImages() {
    this._images = [...this.querySelectorAll('img')]
      .map((img) => {
        const link = img.closest('a');
        const src = img.getAttribute('src') || '';
        return {
          src,
          full: (link && link.getAttribute('href')) || src,
          alt: img.getAttribute('alt') || '',
        };
      })
      .filter((bild) => bild.src);
    this._current = 0;
  }

  get #count() {
    return this._images.length;
  }

  #go(delta) {
    if (this.#count < 2) return;
    this._current = (this._current + delta + this.#count) % this.#count;
  }

  async open(index = this._current) {
    if (!this.#count) return;
    this._current = index;
    this._open = true;
    await this.updateComplete;
    const dialog = this.$('lightbox');
    if (dialog && !dialog.open) dialog.showModal();
  }

  close() {
    const dialog = this.$('lightbox');
    if (dialog && dialog.open) dialog.close();
  }

  #onKeydown(event) {
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      this.#go(-1);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      this.#go(1);
    }
  }

  // Wischen: Pointer-Ereignisse statt Touch-Ereignisse, damit Maus und
  // Finger gleich behandelt werden. touch-action: none auf der Bühne: der
  // Browser scrollt die Seite dahinter nicht, die Bewegung kommt hier an.
  // Nach einem Wischen folgt noch ein click — der darf nicht schließen.
  // Sonst schließt jeder Klick, auf das Bild wie daneben; nur die Knöpfe
  // (Blättern, Schließen) behalten ihre eigene Wirkung.
  #onPointerDown(event) {
    this.#pointerX = event.clientX;
  }

  #onPointerUp(event) {
    if (this.#pointerX === null) return;
    const delta = event.clientX - this.#pointerX;
    this.#pointerX = null;
    if (Math.abs(delta) > 40) {
      this.#swiped = true;
      this.#go(delta < 0 ? 1 : -1);
    }
  }

  #onStageClick(event) {
    if (this.#swiped) {
      this.#swiped = false;
      return;
    }
    if (!event.target.closest('button')) this.close();
  }

  #counterText() {
    return t('productImages.counter')
      .replace('{current}', String(this._current + 1))
      .replace('{total}', String(this.#count));
  }

  render() {
    if (!this.#count) return nothing;
    const bild = this._images[this._current];
    return html`
      <button type="button" class="main" aria-label=${t('productImages.enlarge')} @click=${() => this.open()}>
        <img src=${bild.src} alt=${bild.alt}>
      </button>
      <div class="hint ${this.cls('muted')}">${t('productImages.hint')}</div>
      ${this.#count > 1
        ? html`
            <div class="nav">
              <button type="button" class=${this.cls('button')} aria-label=${t('productImages.previous')} @click=${() => this.#go(-1)}>
                ${this.#chevron(-1)}
              </button>
              <button type="button" class=${this.cls('button')} aria-label=${t('productImages.next')} @click=${() => this.#go(1)}>
                ${this.#chevron(1)}
              </button>
            </div>
            <ul class="thumbs">
              ${this._images.map(
                (vorschau, i) => html`
                  <li>
                    <button
                      type="button"
                      aria-current=${i === this._current ? 'true' : 'false'}
                      aria-label=${t('productImages.show').replace('{n}', String(i + 1))}
                      @click=${() => {
                        this._current = i;
                      }}
                    >
                      <img src=${vorschau.src} alt="" loading="lazy">
                    </button>
                  </li>
                `
              )}
            </ul>
          `
        : nothing}

      <dialog
        id="lightbox"
        aria-label=${t('productImages.enlarge')}
        @close=${() => {
          this._open = false;
        }}
        @keydown=${(event) => this.#onKeydown(event)}
      >
        <div
          class="stage"
          @click=${(event) => this.#onStageClick(event)}
          @pointerdown=${(event) => this.#onPointerDown(event)}
          @pointerup=${(event) => this.#onPointerUp(event)}
          @pointercancel=${() => {
            this.#pointerX = null;
          }}
        >
          ${this._open ? html`<img src=${bild.full} alt=${bild.alt}>` : nothing}
          <button type="button" class="lb-button lb-close" aria-label=${t('productImages.close')} @click=${() => this.close()}>
            ${this.#cross()}
          </button>
          ${this.#count > 1
            ? html`
                <button type="button" class="lb-button lb-prev" aria-label=${t('productImages.previous')} @click=${() => this.#go(-1)}>
                  ${this.#chevron(-1)}
                </button>
                <button type="button" class="lb-button lb-next" aria-label=${t('productImages.next')} @click=${() => this.#go(1)}>
                  ${this.#chevron(1)}
                </button>
              `
            : nothing}
          <div class="lb-footer">
            ${this.#count > 1 ? html`<span aria-live="polite">${this.#counterText()}</span>` : nothing}
            <span>${t('productImages.closeHint')}</span>
          </div>
        </div>
      </dialog>
    `;
  }

  // Inline-SVG wie in shop-add-to-cart: kein Icon-Font, keine Framework-Abhängigkeit.
  #chevron(richtung) {
    const pfad = richtung < 0
      ? 'M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0'
      : 'M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708';
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
           viewBox="0 0 16 16" aria-hidden="true">
        <path d=${pfad}/>
      </svg>
    `;
  }

  #cross() {
    return html`
      <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor"
           viewBox="0 0 16 16" aria-hidden="true">
        <path d="M2.146 2.854a.5.5 0 1 1 .708-.708L8 7.293l5.146-5.147a.5.5 0 0 1 .708.708L8.707 8l5.147 5.146a.5.5 0 0 1-.708.708L8 8.707l-5.146 5.147a.5.5 0 0 1-.708-.708L7.293 8z"/>
      </svg>
    `;
  }
}

customElements.define('shop-product-images', ShopProductImages);
