import { html, css, nothing } from 'lit';
import { ShopElement } from '../core/base.js';
import { ShopAccountElement } from '../core/account-base.js';
import { apiRequest } from '../core/api.js';
import { t } from '../core/i18n.js';

/**
 * <shop-account-profile></shop-account-profile>
 *
 * Ersetzt shortcodes/personal-profile.html + personalProfil() aus account.js.
 *
 * Drei getrennte Formulare, weil das Backend drei getrennte Aktionen kennt
 * (updatePersonalProfil, updateEmail, updatePassword). Vorher waren es drei
 * Knoepfe in einem <div>; Absenden mit der Eingabetaste war damit nicht
 * moeglich, und Passwortmanager hatten nichts, woran sie sich haetten
 * festmachen koennen.
 *
 * Die Felder sind kontrolliert (.value + @input) statt nur einmal befuellt:
 * sobald ein Zustandswechsel ein Neuzeichnen ausloest — etwa der Wechsel
 * Privat/Gewerblich — wuerde Lit sonst die vom Server gesetzten Werte
 * ueberschreiben.
 */
export class ShopAccountProfile extends ShopAccountElement {
  static properties = {
    // Die Uebersicht verlinkt "Passwort aendern" mit einem Fragment. Da der
    // Abschnitt jetzt im ShadowRoot liegt, findet der Browser das Ziel nicht
    // mehr von allein — deshalb wird hier selbst gescrollt.
    passwordHash: { type: String, attribute: 'password-hash' },
    _profile: { state: true },
    _salutations: { state: true },
    _busy: { state: true },
    _messages: { state: true },
  };

  static styles = [
    ShopElement.baseStyles,
    ShopAccountElement.accountStyles,
    css`
      form + .section {
        margin-top: 3rem;
      }
      .hint {
        margin: 0 0 1rem;
        max-width: var(--shop-form-width, 32rem);
      }
    `,
  ];

  #scrolled = false;

  constructor() {
    super();
    this.passwordHash = '#Passwort';
    this._profile = null;
    this._salutations = [];
    this._busy = '';
    this._messages = {};
  }

  async load() {
    const data = await apiRequest('personalProfil');
    this._salutations = (data && data.salutations) || [];
    this._profile = {
      // Das Backend dreht die Bedeutung: bei einer Firma steht der
      // Firmenname in `name` und der Ansprechpartner in `contact` — und
      // liefert beides bereits getauscht aus.
      naturalPerson: data.natural_person ? 'true' : 'false',
      companyName: data.company_name || '',
      name: data.name || '',
      phone: data.phone || '',
      email: data.email || '',
      salutation: data.salutation || '',
    };
    this.announce(this._profile.salutation, this._profile.name);
  }

  updated(changed) {
    super.updated(changed);
    if (this.#scrolled || this._state !== 'ready') return;
    if (!this.passwordHash || window.location.hash !== this.passwordHash) return;
    this.#scrolled = true;
    const section = this.$('password-section');
    if (section) section.scrollIntoView({ block: 'start' });
  }

  // ----------------------------------------------------------- Bausteine

  #set(key, value) {
    this._profile = { ...this._profile, [key]: value };
  }

  #message(form, key, ok) {
    this._messages = { ...this._messages, [form]: { text: t(key), ok } };
  }

  #clearMessage(form) {
    const next = { ...this._messages };
    delete next[form];
    this._messages = next;
  }

  #renderMessage(form) {
    const message = this._messages[form];
    if (!message) return nothing;
    return html`
      <div class="shop-message" role="alert" aria-live="polite">
        <div
          class=${message.ok ? this.cls('alertSuccess') : this.cls('alertError')}
          part=${message.ok ? 'success' : 'error'}
        >
          ${message.text}
        </div>
      </div>
    `;
  }

  #input(id, label, options = {}) {
    return html`
      <div class="field" part="field">
        <label class=${this.cls('label')} part="label" for=${id}>
          ${label}${options.required ? '*' : ''}
        </label>
        <input
          class=${this.cls('input')}
          part="input"
          id=${id}
          name=${id}
          type=${options.type || 'text'}
          autocomplete=${options.autocomplete || 'off'}
          .value=${options.value === undefined ? '' : options.value}
          @input=${options.onInput || undefined}
          ?required=${!!options.required}
        />
      </div>
    `;
  }

  /** Passwortfelder bleiben unkontrolliert — kein Passwort im Zustand. */
  #password(id, label, autocomplete) {
    return html`
      <div class="field" part="field">
        <label class=${this.cls('label')} part="label" for=${id}>${label}*</label>
        <input
          class=${this.cls('input')}
          part="input"
          id=${id}
          name=${id}
          type="password"
          autocomplete=${autocomplete}
          required
        />
      </div>
    `;
  }

  #submitButton(form) {
    const busy = this._busy === form;
    return html`
      <div class="actions">
        <button
          class=${this.cls('buttonSecondary')}
          part="submit"
          type="submit"
          ?disabled=${busy}
        >
          ${busy ? t('account.saving') : t('account.save')}
        </button>
      </div>
    `;
  }

  // ------------------------------------------------------------ Aktionen

  async #send(form, action, payload, successKey, after) {
    this._busy = form;
    this.#clearMessage(form);
    try {
      await apiRequest(action, payload);
      this.#message(form, successKey, true);
      if (after) after();
    } catch (error) {
      this.#message(form, (error && error.code) || 'SHOP_API_ERROR', false);
    } finally {
      this._busy = '';
    }
  }

  #saveProfile(event) {
    event.preventDefault();
    if (this._busy) return;
    const profile = this._profile;
    if (profile.naturalPerson === 'false' && !profile.companyName.trim()) {
      this.#message('profile', 'profile.companyMissing', false);
      return;
    }
    if (!profile.name.trim()) {
      this.#message('profile', 'profile.nameMissing', false);
      return;
    }
    this.#send('profile', 'updatePersonalProfil', {
      name: profile.name,
      phone: profile.phone,
      salutation: profile.salutation,
      // Als Zeichenkette, nicht als Boolean: das Backend vergleicht
      // 'false' == $_POST['natural_person'].
      natural_person: profile.naturalPerson,
      company_name: profile.companyName,
    }, 'account.saved', () => this.announce(profile.salutation, profile.name));
  }

  #saveEmail(event) {
    event.preventDefault();
    if (this._busy) return;
    const password = this.$('confirm-password-email');
    if (!this._profile.email.trim()) {
      this.#message('email', 'profile.emailMissing', false);
      return;
    }
    if (!password.value) {
      this.#message('email', 'profile.passwordMissing', false);
      return;
    }
    this.#send(
      'email',
      'updateEmail',
      { email: this._profile.email, password: password.value },
      'profile.emailSaved',
      () => {
        password.value = '';
      }
    );
  }

  #savePassword(event) {
    event.preventDefault();
    if (this._busy) return;
    const oldPassword = this.$('old-password');
    const newPassword = this.$('new-password');
    const confirmPassword = this.$('confirm-password');

    if (!newPassword.value) {
      this.#message('password', 'profile.newPasswordMissing', false);
      return;
    }
    if (!confirmPassword.value) {
      this.#message('password', 'profile.repeatMissing', false);
      return;
    }
    if (newPassword.value !== confirmPassword.value) {
      this.#message('password', 'register.passwordMismatch', false);
      return;
    }
    if (!oldPassword.value) {
      this.#message('password', 'profile.passwordMissing', false);
      return;
    }
    this.#send(
      'password',
      'updatePassword',
      { old_password: oldPassword.value, new_password: newPassword.value },
      'profile.passwordSaved',
      () => {
        oldPassword.value = '';
        newPassword.value = '';
        confirmPassword.value = '';
      }
    );
  }

  // -------------------------------------------------------------- Anzeige

  renderAccount() {
    const profile = this._profile;
    if (!profile) return nothing;
    const business = profile.naturalPerson === 'false';

    return html`
      <form class=${this.cls('form')} part="form" @submit=${this.#saveProfile} novalidate>
        <div class="section" part="section">${t('profile.personal')}</div>
        <div class="fields">
          <div class="field" part="field">
            <label class=${this.cls('label')} part="label" for="account-type">
              ${t('register.accountType')}*
            </label>
            <select
              class=${this.cls('select')}
              part="select"
              id="account-type"
              name="account-type"
              @change=${(e) => this.#set('naturalPerson', e.target.value)}
            >
              <option value="true" .selected=${profile.naturalPerson === 'true'}>
                ${t('register.private')}
              </option>
              <option value="false" .selected=${profile.naturalPerson === 'false'}>
                ${t('register.business')}
              </option>
            </select>
          </div>

          ${business
            ? this.#input('company-name', t('register.companyName'), {
                required: true,
                autocomplete: 'organization',
                value: profile.companyName,
                onInput: (e) => this.#set('companyName', e.target.value),
              })
            : nothing}

          <div class="field" part="field">
            <label class=${this.cls('label')} part="label" for="salutation">
              ${t('register.salutation')}
            </label>
            <select
              class=${this.cls('select')}
              part="select"
              id="salutation"
              name="salutation"
              autocomplete="honorific-prefix"
              @change=${(e) => this.#set('salutation', e.target.value)}
            >
              <option value="" .selected=${!profile.salutation}></option>
              ${this._salutations.map(
                (item) => html`<option
                  value=${item.translation}
                  .selected=${item.translation === profile.salutation}
                >
                  ${item.translation}
                </option>`
              )}
            </select>
          </div>

          ${this.#input(
            'name',
            business ? t('register.contactName') : t('register.name'),
            {
              required: true,
              autocomplete: 'name',
              value: profile.name,
              onInput: (e) => this.#set('name', e.target.value),
            }
          )}
          ${this.#input('phone', t('register.phone'), {
            type: 'tel',
            autocomplete: 'tel',
            value: profile.phone,
            onInput: (e) => this.#set('phone', e.target.value),
          })}
        </div>
        ${this.#submitButton('profile')} ${this.#renderMessage('profile')}
      </form>

      <form class=${this.cls('form')} part="form" @submit=${this.#saveEmail} novalidate>
        <div class="section" part="section">${t('profile.credentials')}</div>
        <div class="fields">
          ${this.#input('email', t('register.email'), {
            type: 'email',
            autocomplete: 'email',
            required: true,
            value: profile.email,
            onInput: (e) => this.#set('email', e.target.value),
          })}
        </div>
        <p class="hint ${this.cls('muted')}">${t('profile.confirmHint')}</p>
        <div class="fields">
          ${this.#password(
            'confirm-password-email',
            t('profile.currentPassword'),
            'current-password'
          )}
        </div>
        ${this.#submitButton('email')} ${this.#renderMessage('email')}
      </form>

      <form class=${this.cls('form')} part="form" @submit=${this.#savePassword} novalidate>
        <div class="section" part="section" id="password-section">${t('profile.password')}</div>
        <p class="hint ${this.cls('muted')}">${t('profile.confirmHint')}</p>
        <div class="fields">
          ${this.#password('old-password', t('profile.currentPassword'), 'current-password')}
          ${this.#password('new-password', t('profile.newPassword'), 'new-password')}
          ${this.#password('confirm-password', t('profile.repeatPassword'), 'new-password')}
        </div>
        ${this.#submitButton('password')} ${this.#renderMessage('password')}
      </form>
    `;
  }
}

customElements.define('shop-account-profile', ShopAccountProfile);
