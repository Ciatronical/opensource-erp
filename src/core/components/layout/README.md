# Layout-Bausteine

Eine dünne Schicht über Vuetify, damit alle Masken gleich aufgebaut sind und
Optik an einer Stelle geändert werden kann. Kein neues Framework: die Bausteine
rendern Vuetify-Komponenten und lesen ihre Werte aus den Design-Tokens.

## Tokens

`src/core/styles/tokens.css` — Abstände (`--oserp-gap*`), Radien
(`--oserp-radius*`), Flächen und Ränder (`--oserp-surface*`, `--oserp-border*`,
`--oserp-section-head-bg`), Text (`--oserp-text-muted`, `--oserp-title-size`),
Lesebreite (`--oserp-content-max`). Dunkelmodus überschreibt nur Flächen und
Ränder über `.v-theme--dark`.

In Views **keine** Vuetify-Farbklassen wie `bg-grey-lighten-4` und keine festen
Pixelwerte für Abstände mehr verwenden — immer `var(--oserp-…)`.

## Bausteine

| Baustein | Zweck |
|----------|-------|
| `page-header` | Seitenkopf: Rückweg, Titel, Untertitel, Status-Slot neben dem Titel, `actions`-Slot rechts |
| `section-card` | Abschnittskarte mit Symbol, Titel, `actions`-Slot und Inhalt; bringt die Feld-Konventionen (outlined, compact, hide-details auto) per `v-defaults-provider` mit |
| `help-panel` | Hilfsanzeige: schmale, nicht modale Leiste am rechten Rand mit der Langfassung eines Hilfetexts; geöffnet über `useHelpPanel().zeigen(titel, text)`, ein weiterer Aufruf tauscht den Inhalt. Einmal je Ansicht einbinden. Felder von `shop-config-field` nutzen sie über `help: '<Sprachschlüssel>'` neben `tooltip` (Kurzform); das Info-Symbol bekommt dann den Fragezeichen-Zeiger. Erster Abnehmer: Firmenkonfiguration → Kundenportal |

### page-header

```vue
<page-header :title="t('XView.title')" :subtitle="record.number + ' ' + record.name" :back-to="{ name: 'overview' }">
    <v-chip v-if="saving" size="small">…</v-chip>          <!-- Status neben dem Titel -->
    <template #actions><cv-easymode-switch /></template>   <!-- rechts -->
</page-header>
```

### section-card

```vue
<section-card :title="t('XView.address')" icon="mdi-map-marker-outline">
    <template #actions><v-btn size="small" variant="tonal">…</v-btn></template>
    <v-row density="compact">
        <v-col cols="12" sm="6"><v-text-field v-model="data.name" :label="…" /></v-col>
    </v-row>
</section-card>
```

- `quiet` — ruhige Darstellung ohne Kopffläche (Lesebreite, einfacher Modus).
  Eine Elternansicht kann `provide('sectionQuiet', ref)` setzen, dann gilt es
  für alle Karten darunter, ohne dass die Karten es wissen müssen.
- `plain` — Inhalt ohne Innenabstand (Tabellen).
- Felder brauchen `variant`, `density`, `hide-details` nicht mehr; explizite
  Angaben gewinnen weiterhin.

## Konventionen

- Eine Maske = `page-header` + Aktionsleiste + Abschnitte als `section-card`.
- Formulare in `v-row density="compact"`; Spalten `cols="12" sm="6"` als Regel.
- Formulare mit Fokus (Neuanlage, einfacher Modus) in einer Spalte mit
  `max-width: var(--oserp-content-max)`.
- Erster Abnehmer: Kunden-/Lieferantenmaske (`src/core/views/customer-vendor/`).
  Weitere Views beim nächsten Anfassen umstellen, nicht auf einmal.
