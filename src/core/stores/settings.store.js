// Beispielhafte Implementierung der fetchUserPreferences-Aktion in settings.store.js und Sprachwechsel in main.js

import { defineStore } from 'pinia';
import { i18n } from '../main';

// Todo: Implementiere den tatsächlichen DB-Abruf der Benutzereinstellungen
export const useSettingsStore = defineStore('settings', {
  actions: {
    async fetchUserPreferences() {
      // ... (DB-Abruf wie oben)
      const userLanguage = userData.language;

      if (!i18n.global.availableLocales.includes(userLanguage)) {
        try {
          // Importiere die neue Sprachdatei dynamisch
          const messages = await import(`../locales/${userLanguage}.json`);
          i18n.global.setLocaleMessage(userLanguage, messages.default);
        } catch (e) {
          console.error(`Fehler beim Laden der Sprache ${userLanguage}:`, e);
          i18n.global.locale.value = i18n.global.fallbackLocale.value;
          return;
        }
      }

      i18n.global.locale.value = userLanguage;
    },
  },
});
