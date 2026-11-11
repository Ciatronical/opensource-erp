/**
 * Zugriff auf /portal-api/ (same-origin, Session ueber Cookie PORTALCLIENTID).
 *
 * ANTWORTFORMAT
 * Die Shop-Erweiterung von OpensourceERP antwortet im Format des übrigen
 * Backends: {success:true, payload:{...}}, im Fehlerfall
 * {success:false, text:"<CODE>"}.
 *
 * SITZUNGSKONTEXT
 * Fast jede Aktion setzt voraus, dass das Kontext-Cookie schon existiert;
 * angelegt wird es ausschliesslich von `getContext`. Frueher stiess
 * shopwindow.js das im window-load-Handler an — jede Aktion, die davor lief,
 * bekam PORTAL_CONTEXT_ERROR. Beim ersten Besuch direkt auf /warenkorb/ war das
 * ein echtes Wettrennen, weil das Modul-Script des Shortcodes vor `load`
 * ausgefuehrt wird. Deshalb sorgt apiRequest() selbst dafuer: bei
 * PORTAL_CONTEXT_ERROR wird der Kontext einmal geholt und die Aktion wiederholt.
 */

import { apiUrl } from './paths.js';

export class ApiError extends Error {
  constructor(code, data) {
    super(code);
    this.name = 'ApiError';
    this.code = code || 'PORTAL_API_ERROR';
    this.data = data || null;
  }
}

async function send(action, payload) {
  let response;
  try {
    response = await fetch(apiUrl(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...payload }),
    });
  } catch (cause) {
    throw new ApiError('PORTAL_NETWORK_ERROR', { cause: String(cause) });
  }

  let data = null;
  try {
    data = await response.json();
  } catch {
    // Leere oder kaputte Antwort — passiert, wenn das Backend in einen
    // Fatal Error laeuft, bevor es JSON schreibt.
    throw new ApiError('PORTAL_API_ERROR', { status: response.status });
  }

  if (!response.ok || (data && data.success === false)) {
    throw new ApiError(data && data.text ? data.text : 'PORTAL_API_ERROR', data);
  }

  // Eine Antwort ohne payload (etwa nach updateAddress) trägt keine
  // Nutzdaten — dann bleibt das Ergebnis leer.
  return data?.payload ?? {};
}

let contextPromise = null;

/**
 * MERKER "SITZUNG MIT INHALT"
 *
 * Das Kontext-Cookie ist HttpOnly, ein Widget kann also nicht nachsehen, ob
 * der Besucher ueberhaupt eine Sitzung hat. Deshalb merkt sich getContext()
 * im localStorage, ob die letzte Antwort etwas zu melden hatte (angemeldet
 * oder Artikel im Warenkorb). Die Knoepfe im Seitenkopf fragen den Kontext
 * nur noch, wenn dieser Merker steht — fuer den anonymen Erstbesucher, also
 * den Normalfall auf Blog- und Infoseiten, entfaellt die Anfrage an
 * /portal-api/ damit ganz. Sie war das Ende der kritischen Anfragekette
 * (Lighthouse: rund 800 ms, weil der Weiterleiter die Anfrage an
 * OpensourceERP ueber die Anbindung der Werkstatt durchreicht).
 *
 * Widgets, die den Kontext brauchen (Warenkorb, Kasse, Konto), rufen
 * getContext() weiter direkt auf; das Cookie entsteht dann dort. Gibt es
 * keinen Merker und doch eine gefuellte Sitzung (localStorage geloescht),
 * zeigt der Kopf bis zur naechsten Kontextanfrage "Warenkorb (0)" — die
 * kommt spaetestens auf der Warenkorbseite oder beim naechsten Einlegen.
 */
const CONTEXT_KNOWN_KEY = 'oserp-portal-context';

function storage() {
  try {
    return window.localStorage;
  } catch {
    // Zugriff verweigert (Datenschutzeinstellung, eingebettete Seite): dann
    // gibt es keinen Merker, und die Knoepfe fragen wie frueher immer.
    return null;
  }
}

/** true, wenn die letzte Kontextantwort angemeldet oder mit Warenkorb war. */
export function hasKnownContext() {
  const store = storage();
  return store ? store.getItem(CONTEXT_KNOWN_KEY) === '1' : true;
}

function rememberContext(context) {
  const store = storage();
  if (!store) return;
  if (context && (context.account || Number(context.cart_pos_count) > 0)) {
    store.setItem(CONTEXT_KNOWN_KEY, '1');
  } else {
    store.removeItem(CONTEXT_KNOWN_KEY);
  }
}

/**
 * Sitzungskontext (Warenkorbzaehler, Login-Status) — legt beim ersten Aufruf
 * das Cookie an. Das Ergebnis wird gehalten, damit nicht jedes Widget auf der
 * Seite eine eigene Anfrage stellt.
 */
export function getContext() {
  if (!contextPromise) {
    contextPromise = send('getContext', {})
      .then((context) => {
        rememberContext(context);
        return context;
      })
      .catch((error) => {
        // Einen Fehlschlag nicht dauerhaft festhalten, sonst scheitert jeder
        // spaetere Aufruf sofort an der zwischengespeicherten Ablehnung.
        contextPromise = null;
        throw error;
      });
  }
  return contextPromise;
}

/** Nach An-/Abmeldung: der gehaltene Kontext ist dann veraltet. */
export function refreshContext() {
  contextPromise = null;
  return getContext();
}

/**
 * Anmeldung und Abmeldung
 *
 * Die Aktionen heißen portalLogin und portalLogout (das Kundenportal von
 * OpensourceERP; shopLogin und shopLogout gelten weiter): OpensourceERP hat
 * bereits ein login und ein logout für die Mitarbeiter-Anmeldung, und zwei
 * Funktionen gleichen Namens kann PHP nicht laden.
 */

/** Meldet den Kunden an. */
export function login(email, password) {
  return apiRequest('portalLogin', { email, password });
}

/** Meldet den Kunden ab. */
export function logout() {
  return apiRequest('portalLogout', {});
}

export async function apiRequest(action, payload = {}, retryContext = true) {
  try {
    return await send(action, payload);
  } catch (error) {
    const noContext =
      error instanceof ApiError &&
      error.code === 'PORTAL_CONTEXT_ERROR' &&
      retryContext &&
      action !== 'getContext';
    if (!noContext) throw error;

    contextPromise = null;
    try {
      await getContext();
    } catch {
      // Scheitert auch das, meldet der Wiederholungsversuch gleich wieder
      // PORTAL_CONTEXT_ERROR — das ist die Fehlermeldung, die zaehlt.
    }
    return apiRequest(action, payload, false);
  }
}
