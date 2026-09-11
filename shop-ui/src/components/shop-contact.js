import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { apiRequest } from '../core/api.js';
import { t } from '../core/i18n.js';

const EMAIL_PATTERN = /\S+@\S+\.\S+/;

/**
 * <shop-contact redirect-url="/kontakt-danke/"></shop-contact>
 *
 * Das Kontaktformular. Ersetzt shortcodes/contact-form.html und
 * js/shopwindow/contact.js.
 *
 * Angemeldete Kunden bekommen ihre Stammdaten vorbelegt (Aktion
 * `contactInit`). Steht ?pid=… in der Adresse, beginnt das Anliegen mit einer
 * Zeile zum Produkt — wie bisher.
 *
 * DER NACHRICHTENTEXT wird weiterhin im Browser zusammengesetzt und als
 * `term` an `sendContactMail` geschickt; das Backend uebernimmt ihn
 * unveraendert als Mailtext. Reihenfolge und Beschriftungen sind dieselben
 * wie vorher, damit die Mails im Postfach gleich aussehen.
 *
 * ZWEI FEHLER, DIE NICHT MITGEWANDERT SIND
 *  - `sendContactMail` antwortet mit dem *String* 'true' bzw. 'false'
 *    (shop.mail.php:95,104). Das alte `if(data.success)` war deshalb auch bei
 *    'false' wahr — eine fehlgeschlagene Mail leitete auf die Dankesseite.
 *    Hier wird auf 'true' geprueft.
 *  - Die E-Mail-Pruefung lief ueber `email.validity.typeMismatch`, das Feld
 *    war aber `type="text"` — die Bedingung konnte nie zutreffen. Jetzt
 *    type="email" und dieselbe Regel wie in <shop-register>.
 *
 * Der Knopf trug ausserdem data-redirect="/abgesendet/", was niemand las;
 * contact.js sprang fest auf /kontakt-danke/. /abgesendet/ gibt es nicht.
 */
export class ShopContact extends ShopElement {
  static properties = {
    redirectUrl: { type: String, attribute: 'redirect-url' },
    heading: { type: String },
    _salutations: { state: true },
    _values: { state: true },
    _state: { state: true },
    _error: { state: true },
    _busy: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    css`
      textarea {
        width: 100%;
        font: inherit;
      }
      .shop-message {
        margin-top: 1.5rem;
        max-width: var(--shop-form-width, 32rem);
      }
    `,
  ];

  /** Reihenfolge und Beschriftung bestimmen auch den Mailtext. */
  get #fields() {
    return [
      { id: 'company-name', label: t('contact.companyName'), autocomplete: 'organization' },
      { id: 'salutation', label: t('contact.salutation'), select: true },
      { id: 'name', label: t('contact.name'), autocomplete: 'name', required: true },
      { id: 'email', label: t('contact.email'), autocomplete: 'email', type: 'email', required: true },
      { id: 'phone', label: t('contact.phone'), autocomplete: 'tel', type: 'tel' },
      { id: 'issue', label: t('contact.issue'), textarea: true, required: true },
    ];
  }

  constructor() {
    super();
    this.redirectUrl = '/kontakt-danke/';
    this.heading = '';
    this._salutations = [];
    this._values = { 'company-name': '', salutation: '', name: '', email: '', phone: '', issue: '' };
    this._state = 'loading';
    this._error = '';
    this._busy = false;
  }

  #loaded = false;

  async connectedCallback() {
    await super.connectedCallback();
    if (this.#loaded) return;
    this.#loaded = true;

    const params = new URLSearchParams(window.location.search);
    const pid = params.get('pid');
    const issue = pid ? `${t('contact.productRequest')} ${pid}:\n` : '';

    try {
      const data = await apiRequest('contactInit', { lang: document.documentElement.lang });
      this._salutations = (data && data.salutations) || [];
      this._values = {
        'company-name': (data && data.company_name) || '',
        salutation: (data && data.salutation) || '',
        name: (data && data.name) || '',
        email: (data && data.email) || '',
        phone: (data && data.phone) || '',
        issue,
      };
    } catch (error) {
      // Das Formular bleibt bedienbar — es ist auch ohne Stammdaten nutzbar.
      this._values = { ...this._values, issue };
      this._error = t(error.code || 'SHOP_API_ERROR');
    } finally {
      this._state = 'ready';
      await this.updateComplete;
      // Wie bisher: fehlt der Name, steht der Fokus dort, sonst im Anliegen.
      const target = this.$(this._values.name ? 'issue' : 'name');
      if (target) target.focus();
    }
  }

  #set(id, value) {
    this._values = { ...this._values, [id]: value };
  }

  /** Erstes fehlendes Pflichtfeld, sonst ''. */
  #missing() {
    for (const field of this.#fields) {
      if (!field.required) continue;
      if (!String(this._values[field.id] || '').trim()) return field.label;
    }
    return '';
  }

  /** Der Mailtext, Zeile fuer Zeile wie im alten formatMessage(). */
  #message() {
    const lines = [t('contact.subject')];
    for (const field of this.#fields) {
      lines.push(`${field.label}: ${this._values[field.id] || ''}`);
    }
    return lines.join('\n') + '\n';
  }

  async #submit(event) {
    event.preventDefault();
    if (this._busy) return;
    this._error = '';

    const missing = this.#missing();
    if (missing) {
      this._error = t('contact.required') + missing + t('contact.missing');
      return;
    }
    if (!EMAIL_PATTERN.test(this._values.email.trim())) {
      this._error = t('contact.emailInvalid');
      const email = this.$('email');
      if (email) email.focus();
      return;
    }

    this._busy = true;
    try {
      const data = await apiRequest('sendContactMail', {
        email: this._values.email.trim(),
        term: this.#message(),
      });
      // Das Backend schickt den String 'true' — siehe Klassenkommentar.
      const sent = data && (data.success === true || data.success === 'true');
      if (!sent) {
        this._busy = false;
        this._error = t('contact.sendFailed');
        return;
      }
      window.location.href = this.redirectUrl;
    } catch {
      this._busy = false;
      this._error = t('contact.sendFailed');
    }
  }

  render() {
    if (this._state === 'loading') {
      return html`<p class=${this.cls('muted')} role="status">${t('contact.loading')}</p>`;
    }
    return html`
      ${this.heading ? html`<h2 class=${this.cls('heading')}>${this.heading}</h2>` : nothing}
      <form class=${this.cls('form')} @submit=${this.#submit} novalidate>
        <div class="fields">${this.#fields.map((field) => this.#renderField(field))}</div>
        ${this._error
          ? html`<div class="shop-message ${this.cls('alertError')}" role="alert">${this._error}</div>`
          : nothing}
        <div class="actions">
          <button type="submit" class=${this.cls('buttonSecondary')} ?disabled=${this._busy}>
            ${this._busy ? t('contact.sending') : t('contact.submit')}
          </button>
        </div>
      </form>
    `;
  }

  #renderField(field) {
    const value = this._values[field.id] || '';
    const label = html`
      <label class=${this.cls('label')} part="label" for=${field.id}>
        ${field.label}${field.required ? '*' : ''}
      </label>
    `;

    if (field.select) {
      return html`
        <div class="field" part="field">
          ${label}
          <select
            class=${this.cls('select')}
            part="select"
            id=${field.id}
            name=${field.id}
            @change=${(event) => this.#set(field.id, event.target.value)}
          >
            <option value="" .selected=${!value}></option>
            ${this._salutations.map(
              (item) => html`
                <option value=${item.translation} .selected=${value === item.translation}>
                  ${item.translation}
                </option>
              `,
            )}
          </select>
        </div>
      `;
    }

    if (field.textarea) {
      return html`
        <div class="field" part="field">
          ${label}
          <textarea
            class=${this.cls('input')}
            part="input"
            id=${field.id}
            name=${field.id}
            rows="5"
            .value=${value}
            @input=${(event) => this.#set(field.id, event.target.value)}
          ></textarea>
        </div>
      `;
    }

    return html`
      <div class="field" part="field">
        ${label}
        <input
          class=${this.cls('input')}
          part="input"
          id=${field.id}
          name=${field.id}
          type=${field.type || 'text'}
          autocomplete=${field.autocomplete || 'off'}
          .value=${value}
          @input=${(event) => this.#set(field.id, event.target.value)}
        >
      </div>
    `;
  }
}

customElements.define('shop-contact', ShopContact);
