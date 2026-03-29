# Übersetzungen Integration

## Dateien

Die Übersetzungsdateien befinden sich im Ordner `locales/`:
- `de.json` - Deutsche Übersetzungen
- `en.json` - Englische Übersetzungen

## Integration in Vue i18n

### Option 1: Direkt in die bestehende i18n Konfiguration

Wenn du bereits eine i18n Konfiguration hast, füge die Übersetzungen hinzu:

```javascript
// src/i18n/index.js oder main.js
import { createI18n } from 'vue-i18n';
import deClientConfig from '@/components/settings/locales/de.json';
import enClientConfig from '@/components/settings/locales/en.json';

// Bestehende Übersetzungen
import de from './locales/de.json';
import en from './locales/en.json';

const i18n = createI18n({
    legacy: false,
    locale: 'de',
    fallbackLocale: 'en',
    messages: {
        de: {
            ...de,
            ...deClientConfig  // Client Config Übersetzungen hinzufügen
        },
        en: {
            ...en,
            ...enClientConfig  // Client Config Übersetzungen hinzufügen
        }
    }
});

export default i18n;
```

### Option 2: Separate Namespaces (empfohlen für größere Projekte)

Verwende Namespaces um die Übersetzungen zu gruppieren:

```javascript
// src/i18n/index.js
import { createI18n } from 'vue-i18n';

const i18n = createI18n({
    legacy: false,
    locale: 'de',
    fallbackLocale: 'en',
    messages: {
        de: {
            common: require('./locales/de.json'),
            clientConfig: require('@/components/settings/locales/de.json')
        },
        en: {
            common: require('./locales/en.json'),
            clientConfig: require('@/components/settings/locales/en.json')
        }
    }
});
```

Verwendung in Komponenten:
```vue
<template>
    <h1>{{ $t('clientConfig.miscellaneous') }}</h1>
</template>
```

### Option 3: Lazy Loading (für bessere Performance)

Lade die Übersetzungen nur wenn sie benötigt werden:

```javascript
// src/i18n/index.js
import { createI18n } from 'vue-i18n';

const i18n = createI18n({
    legacy: false,
    locale: 'de',
    fallbackLocale: 'en',
    messages: {}
});

// Funktion zum Laden von Übersetzungen
export async function loadLocaleMessages(locale) {
    // Lade Client Config Übersetzungen
    const messages = await import(`@/components/settings/locales/${locale}.json`);
    i18n.global.setLocaleMessage(locale, messages.default);
    return i18n;
}

export default i18n;
```

Verwendung in der Route:
```javascript
// router/index.js
{
    path: '/settings/client-config',
    name: 'ClientConfig',
    component: () => import('@/components/settings/client.config.component.vue'),
    beforeEnter: async (to, from, next) => {
        await loadLocaleMessages('de');
        next();
    }
}
```

## Vollständiges Setup-Beispiel

```javascript
// main.js
import { createApp } from 'vue';
import { createI18n } from 'vue-i18n';
import App from './App.vue';

// Importiere Übersetzungen
import de from './i18n/locales/de.json';
import en from './i18n/locales/en.json';
import deClientConfig from './components/settings/locales/de.json';
import enClientConfig from './components/settings/locales/en.json';

// Merge Übersetzungen
const messages = {
    de: { ...de, ...deClientConfig },
    en: { ...en, ...enClientConfig }
};

// Erstelle i18n Instanz
const i18n = createI18n({
    legacy: false,
    locale: localStorage.getItem('locale') || 'de',
    fallbackLocale: 'en',
    messages
});

const app = createApp(App);
app.use(i18n);
app.mount('#app');
```

## Eigene Übersetzungen hinzufügen

Falls du weitere Übersetzungen benötigst, füge sie einfach zu den JSON-Dateien hinzu:

```json
{
    "search": "Suchen",
    "meinNeuerKey": "Meine neue Übersetzung"
}
```

## Verwendung in Komponenten

### Composition API

```vue
<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const title = t('miscellaneous');
</script>
```

### Options API

```vue
<script>
export default {
    computed: {
        title() {
            return this.$t('miscellaneous');
        }
    }
}
</script>
```

### Template

```vue
<template>
    <h1>{{ $t('miscellaneous') }}</h1>
    <p>{{ $t('companyNameAndAddress') }}</p>
</template>
```

## Sprache wechseln

```javascript
// In einer Komponente
const { locale } = useI18n();

function changeLanguage(newLocale) {
    locale.value = newLocale;
    localStorage.setItem('locale', newLocale);
}
```

## Fehlende Übersetzungen finden

Verwende dieses Script um fehlende Übersetzungen zu finden:

```javascript
// scripts/check-translations.js
const de = require('../src/components/settings/locales/de.json');
const en = require('../src/components/settings/locales/en.json');

const deKeys = Object.keys(de);
const enKeys = Object.keys(en);

console.log('Fehlende deutsche Übersetzungen:');
enKeys.forEach(key => {
    if (!deKeys.includes(key)) {
        console.log(`  - ${key}`);
    }
});

console.log('\nFehlende englische Übersetzungen:');
deKeys.forEach(key => {
    if (!enKeys.includes(key)) {
        console.log(`  - ${key}`);
    }
});
```

Führe aus mit:
```bash
node scripts/check-translations.js
```

## Übersetzungen testen

```vue
<template>
    <div>
        <v-btn @click="locale = 'de'">Deutsch</v-btn>
        <v-btn @click="locale = 'en'">English</v-btn>
        
        <h1>{{ $t('miscellaneous') }}</h1>
        <p>{{ $t('companyName') }}</p>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { locale } = useI18n();
</script>
```
