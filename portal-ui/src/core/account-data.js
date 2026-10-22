/**
 * Aufbereitung der Konto-Nutzlasten.
 *
 * Ausserhalb der Komponenten, weil dieselben Adressen an drei Stellen
 * gebraucht werden (Uebersicht, Adressverwaltung, spaeter die Kasse) — und
 * weil sich das hier ohne Browser pruefen laesst.
 *
 * ZWEI SCHREIBWEISEN DERSELBEN ADRESSE
 * ------------------------------------
 * Das Backend liefert die Standard-Lieferadresse je nach Aktion anders:
 *   personalOverview   -> shipto_name, shipto_street, shipto_zipcode, ...
 *   accountAddresses   -> shiptoname,  shiptostreet,  shiptozipcode,  ...
 * Beide landen hier auf derselben Form.
 */

function str(value) {
  return value === null || value === undefined ? '' : String(value);
}

/** Adresse aus der shipto-Tabelle (accountAddresses, shipping_addresses). */
export function shiptoAddress(raw) {
  return {
    id: str(raw.shipto_id),
    name: str(raw.shiptoname),
    street: str(raw.shiptostreet),
    zipcode: str(raw.shiptozipcode),
    city: str(raw.shiptocity),
    country: str(raw.shiptocountry),
    email: str(raw.shiptoemail),
    phone: str(raw.shiptophone),
    // `used` kommt aus einem CASE (1/0). Ob PDO daraus eine Zahl oder einen
    // String macht, haengt am Treiber — als String waere "0" wahr, und der
    // Loeschen-Knopf verschwaende bei jeder Adresse. Deshalb Number().
    used: raw.used === true || Number(raw.used) > 0,
  };
}

/** Dieselbe Adresse in der Unterstrich-Schreibweise von personalOverview. */
export function shiptoAddressUnderscore(raw) {
  return {
    id: str(raw.shipto_default),
    name: str(raw.shipto_name),
    street: str(raw.shipto_street),
    zipcode: str(raw.shipto_zipcode),
    city: str(raw.shipto_city),
    country: str(raw.shipto_country),
    email: str(raw.shipto_email),
    phone: str(raw.shipto_phone),
    used: false,
  };
}

/** Rechnungsadresse — steht in beiden Nutzlasten flach im Kundendatensatz. */
export function billingAddress(raw) {
  return {
    street: str(raw.street),
    zipcode: str(raw.zipcode),
    city: str(raw.city),
    country: str(raw.country),
  };
}

/**
 * shipping_addresses ist ein nach shipto_id geschluesseltes Objekt. Als Liste
 * ist es sortierbar und laesst sich mit repeat() keyed rendern.
 */
export function byName(a, b) {
  return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
}

export function addressList(raw) {
  if (!raw) return [];
  return Object.values(raw).map(shiptoAddress).sort(byName);
}

/** Leere Adresse fuer das Neuanlegen-Formular. */
export function emptyAddress() {
  return {
    id: '',
    name: '',
    street: '',
    zipcode: '',
    city: '',
    country: '',
    email: '',
    phone: '',
    used: false,
  };
}

/**
 * Nutzlast fuer newDeliveryAddress/updateDeliveryAddress. Das Backend nimmt
 * dort die kurzen Namen entgegen, nicht die shipto*-Spalten.
 */
export function addressPayload(address) {
  return {
    name: address.name,
    street: address.street,
    city: address.city,
    zipcode: address.zipcode,
    country: address.country,
    email: address.email,
    phone: address.phone,
  };
}

/**
 * "Hallo, Herr Mustermann". Das alte Markup hat Anrede und Name ungeprueft
 * aneinandergehaengt und dabei ohne Anrede ein doppeltes Leerzeichen erzeugt.
 */
export function greeting(salutation, name) {
  return [str(salutation), str(name)].filter(Boolean).join(' ');
}

/** "12345 Musterstadt, Deutschland" — leere Teile fallen weg. */
export function cityLine(address) {
  const place = [address.zipcode, address.city].filter(Boolean).join(' ');
  return [place, address.country].filter(Boolean).join(', ');
}
