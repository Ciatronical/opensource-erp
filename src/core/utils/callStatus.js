// src/core/utils/callStatus.js
//
// Einheitliche Auswertung des Anruf-Status (crmti_status). Der Wert kommt aus
// Asterisk (DIALSTATUS) und wird nach dem Dial() via callstatus() in die
// crmti-Zeile geschrieben. Mögliche Werte: ANSWER, NOANSWER, BUSY, CANCEL,
// CONGESTION, CHANUNAVAIL ... (ANSWERED ist die CDR-Schreibweise und zählt
// ebenfalls als angenommen).
//
// Ein Anruf gilt als "nicht angenommen", wenn ein Status gesetzt ist und dieser
// weder ANSWER noch ANSWERED ist. Ist kein Status gesetzt (NULL, z. B. Altdaten
// oder Dialplan noch nicht angepasst), gilt der Anruf NICHT als verpasst.

/**
 * Wurde der Anruf nicht angenommen (eingehend verpasst bzw. ausgehend nicht
 * erreicht)?
 * @param {string|null|undefined} status crmti_status
 * @returns {boolean}
 */
export function isCallMissed(status) {
    if (!status) return false
    return !['ANSWER', 'ANSWERED'].includes(String(status).trim().toUpperCase())
}
