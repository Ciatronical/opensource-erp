/**
 * Zugriff auf /shop-api/ (same-origin, Session ueber Cookie HUGOSHOPCLIENTID).
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
 * bekam SHOP_CONTEXT_ERROR. Beim ersten Besuch direkt auf /warenkorb/ war das
 * ein echtes Wettrennen, weil das Modul-Script des Shortcodes vor `load`
 * ausgefuehrt wird. Deshalb sorgt apiRequest() selbst dafuer: bei
 * SHOP_CONTEXT_ERROR wird der Kontext einmal geholt und die Aktion wiederholt.
 */

export const API_URL = '/shop-api/';

export class ApiError extends Error {
  constructor(code, data) {
    super(code);
    this.name = 'ApiError';
    this.code = code || 'SHOP_API_ERROR';
    this.data = data || null;
  }
}

async function send(action, payload) {
  let response;
  try {
    response = await fetch(API_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...payload }),
    });
  } catch (cause) {
    throw new ApiError('SHOP_NETWORK_ERROR', { cause: String(cause) });
  }

  let data = null;
  try {
    data = await response.json();
  } catch {
    // Leere oder kaputte Antwort — passiert, wenn das Backend in einen
    // Fatal Error laeuft, bevor es JSON schreibt.
    throw new ApiError('SHOP_API_ERROR', { status: response.status });
  }

  if (!response.ok || (data && data.success === false)) {
    throw new ApiError(data && data.text ? data.text : 'SHOP_API_ERROR', data);
  }

  // Eine Antwort ohne payload (etwa nach updateAddress) trägt keine
  // Nutzdaten — dann bleibt das Ergebnis leer.
  return data?.payload ?? {};
}

let contextPromise = null;

/**
 * Sitzungskontext (Warenkorbzaehler, Login-Status) — legt beim ersten Aufruf
 * das Cookie an. Das Ergebnis wird gehalten, damit nicht jedes Widget auf der
 * Seite eine eigene Anfrage stellt.
 */
export function getContext() {
  if (!contextPromise) {
    contextPromise = send('getContext', {}).catch((error) => {
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
 * Die Aktionen heißen shopLogin und shopLogout: OpensourceERP hat bereits ein
 * login und ein logout für die Mitarbeiter-Anmeldung, und zwei Funktionen
 * gleichen Namens kann PHP nicht laden.
 */

/** Meldet den Kunden an. */
export function login(email, password) {
  return apiRequest('shopLogin', { email, password });
}

/** Meldet den Kunden ab. */
export function logout() {
  return apiRequest('shopLogout', {});
}

export async function apiRequest(action, payload = {}, retryContext = true) {
  try {
    return await send(action, payload);
  } catch (error) {
    const noContext =
      error instanceof ApiError &&
      error.code === 'SHOP_CONTEXT_ERROR' &&
      retryContext &&
      action !== 'getContext';
    if (!noContext) throw error;

    contextPromise = null;
    try {
      await getContext();
    } catch {
      // Scheitert auch das, meldet der Wiederholungsversuch gleich wieder
      // SHOP_CONTEXT_ERROR — das ist die Fehlermeldung, die zaehlt.
    }
    return apiRequest(action, payload, false);
  }
}
