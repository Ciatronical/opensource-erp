// src/core/constants/auth.js

/**
 * Authentifizierungs-Status Konstanten
 *
 * Verwendet von restoreSession() und Router-Guards
 */
export const AuthStatus = Object.freeze({
    /** Session erfolgreich wiederhergestellt */
    AUTHENTICATED: 'AUTHENTICATED',
    /** Keine gültige Session vorhanden */
    NOT_AUTHENTICATED: 'NOT_AUTHENTICATED',
    /** Initiales Setup erforderlich */
    SETUP_REQUIRED: 'SETUP_REQUIRED'
});
