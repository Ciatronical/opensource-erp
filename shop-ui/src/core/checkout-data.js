/**
 * Aufbereitung der Kassen-Nutzlasten.
 *
 * Zwei Richtungen:
 *   billingAndShipping  -> checkoutAccount()   (was die Seite anzeigt)
 *   invoicing           -> shippingPayload()/guestPayload()  (was sie sendet)
 *
 * Liegt neben der Komponente, damit sich die Nutzlast ohne Browser pruefen
 * laesst — bei einer Aktion, die eine echte Rechnung schreibt, ist das mehr
 * wert als bei jeder anderen.
 *
 * WAS DAS BACKEND MIT `adresses` MACHT (shop.account.php:75-101)
 * -------------------------------------------------------------
 * Ausgewertet wird ausschliesslich `adresses.shipping`:
 *   default = true            -> ar.shipto_id = shipping.id (darf null sein)
 *   default = false, id gesetzt -> ar.shipto_id = id
 *   default = false, id null  -> neue Zeile in shipto, deren Id an die Rechnung
 * `adresses.billing` wird nie gelesen; ar.billing_address_id ist immer NULL.
 * Es wird trotzdem mitgeschickt — unveraendert zum alten Stand, damit ein
 * spaeterer Ausbau im Backend nichts vermisst.
 */
import { shiptoAddress, byName } from './account-data.js';

function str(value) {
  return value === null || value === undefined ? '' : String(value);
}

/**
 * Antwort von billingAndShipping.
 *
 * NICHT uebernommen: der registrierte Zweig von billingAndShipping() in
 * account.js schrieb data.name und data.company_name in die Felder des
 * Gast-Formulars (account.js:515-525). Beide Schluessel liefert das Backend
 * dort gar nicht, und das Formular war in dem Moment ausgeblendet — der Code
 * hat nichts getan, ausser bei fehlendem Markup zu werfen.
 */
export function checkoutAccount(data) {
  const account = {
    registered: !!(data && data.registered),
    salutations: (data && data.salutations) || [],
    billing: null,
    defaultShipping: null,
    addresses: [],
  };
  if (!account.registered) return account;

  account.billing = {
    name: str(data.address && data.address.name),
    street: str(data.address && data.address.street),
    zipcode: str(data.address && data.address.zipcode),
    city: str(data.address && data.address.city),
    country: str(data.address && data.address.country),
  };

  // `shipping` fehlt, wenn der Kunde keine abweichende Standard-Lieferadresse
  // hat. Dann gilt die Rechnungsadresse — das Backend bekommt id = null.
  if (data.shipping && data.shipping.name) {
    account.defaultShipping = {
      id: str(data.shipping.id),
      name: str(data.shipping.name),
      street: str(data.shipping.street),
      zipcode: str(data.shipping.zipcode),
      city: str(data.shipping.city),
      country: str(data.shipping.country),
      email: '',
      phone: '',
      used: false,
    };
  }

  // Hier eine Liste (accountAddresses liefert ein Objekt), aber dieselben
  // shipto*-Spalten. Das SELECT hat kein ORDER BY, die Reihenfolge waere
  // sonst die der Einfuegung.
  account.addresses = ((data.shipping_addresses || []).map(shiptoAddress)).sort(byName);
  return account;
}

/**
 * Lieferadresse eines angemeldeten Kunden.
 *
 * @param {{mode: 'saved'|'new', addressId?: string, address?: object}} choice
 */
export function shippingPayload(choice) {
  if (choice.mode === 'new') {
    const address = choice.address || {};
    return {
      default: false,
      id: null,
      name: str(address.name),
      street: str(address.street),
      city: str(address.city),
      zipcode: str(address.zipcode),
      country: str(address.country),
      email: str(address.email),
      phone: str(address.phone),
    };
  }
  // Gespeicherte oder Standardadresse. Leere Id heisst "keine" und muss als
  // echtes null gehen: ar.shipto_id ist dann NULL, die Rechnung laeuft auf
  // die Rechnungsadresse.
  return { default: true, id: choice.addressId ? String(choice.addressId) : null };
}

/**
 * Adressen einer Gastbestellung, gebaut aus <shop-register>.values().
 *
 * `id: null` steht ueberall ausdruecklich drin. Das alte invoicing() liess
 * den Schluessel im Gast-Zweig weg, worauf PHP beim Lesen von
 * $adresses['shipping']['id'] eine Warnung ins Log schrieb (account.js:693).
 */
export function guestPayload(values) {
  const addresses = {
    billing: {
      default: false,
      salutation: str(values.salutation),
      account_type: str(values['account-type']),
      name: str(values.name),
      street: str(values.street),
      city: str(values.city),
      zipcode: str(values.postcode),
      country: str(values.country),
      phone: str(values.phone),
      email: str(values.email),
    },
  };

  if (values['add-delivery-address'] === 'true') {
    addresses.shipping = {
      default: false,
      id: null,
      name: str(values['shipping-name']),
      street: str(values['shipping-street']),
      city: str(values['shipping-city']),
      zipcode: str(values['shipping-postcode']),
      country: str(values['shipping-country']),
      email: str(values['shipping-email']),
      phone: str(values['shipping-phone']),
    };
  } else {
    addresses.shipping = { default: true, id: null };
  }
  return addresses;
}

/** Leeres Formular fuer eine abweichende Lieferadresse an der Kasse. */
export function emptyShipping() {
  return { id: '', name: '', street: '', zipcode: '', city: '', country: '', email: '', phone: '' };
}

/**
 * Rechnungslink aus der Adresszeile.
 *
 * getInvoiceSummary() in account.js las window.location.search.split('link=')[1]
 * — mit einem weiteren Parameter dahinter ("?link=abc&x=1") landete dessen
 * Wert mit im Link, und ein vorangestellter Parameter passte auch nicht.
 */
export function invoiceLink(search) {
  const value = new URLSearchParams(search || '').get('link');
  return value ? value.trim() : '';
}
