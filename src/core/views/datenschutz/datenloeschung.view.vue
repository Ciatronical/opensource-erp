<template>
  <v-app-bar color="grey-lighten-4" elevation="1" density="comfortable">
    <v-toolbar-title class="text-h6">
      <router-link to="/" class="text-decoration-none text-primary">OpensourceERP</router-link>
    </v-toolbar-title>
  </v-app-bar>

  <v-container class="py-8" style="max-width: 900px;">
    <v-card variant="outlined" class="border-md pa-6">
      <h1 class="text-h4 mb-6">Anleitung zur Datenlöschung</h1>

      <h2 class="text-h6 mt-4 mb-2">1. Welche Daten werden gespeichert?</h2>
      <p class="text-body-1 mb-2">
        Im Rahmen der WhatsApp Business API werden folgende Daten verarbeitet:
      </p>
      <ul class="text-body-1 mb-4 ml-4">
        <li>Telefonnummer</li>
        <li>Name (sofern im WhatsApp-Profil hinterlegt)</li>
        <li>Nachrichteninhalte (Text, Bilder, Dokumente)</li>
        <li>Zeitstempel und Zustellstatus</li>
      </ul>

      <h2 class="text-h6 mt-4 mb-2">2. Löschung beantragen</h2>
      <p class="text-body-1 mb-4">
        Sie haben das Recht, die Löschung Ihrer personenbezogenen Daten zu verlangen
        (Art. 17 DSGVO). Bitte kontaktieren Sie uns dafür über die im Impressum
        angegebenen Kontaktdaten. Geben Sie dabei Ihre Telefonnummer an, damit wir
        die zugehörigen Daten identifizieren können.
      </p>

      <h2 class="text-h6 mt-4 mb-2">3. Umfang der Löschung</h2>
      <p class="text-body-1 mb-2">Nach Eingang Ihres Löschantrags werden folgende Daten entfernt:</p>
      <ul class="text-body-1 mb-4 ml-4">
        <li>Alle gespeicherten WhatsApp-Nachrichten und Medien</li>
        <li>Ihre Telefonnummer und Profilinformationen</li>
        <li>Zugehörige Kommunikationsverläufe</li>
      </ul>

      <h2 class="text-h6 mt-4 mb-2">4. Bearbeitungszeitraum</h2>
      <p class="text-body-1 mb-4">
        Wir bearbeiten Ihren Löschantrag innerhalb von 30 Tagen. Gesetzliche
        Aufbewahrungsfristen (z.B. steuerrechtliche Pflichten) bleiben hiervon unberührt.
      </p>

      <h2 class="text-h6 mt-4 mb-2">5. Daten bei Meta</h2>
      <p class="text-body-1 mb-4">
        Bitte beachten Sie, dass die Löschung nur die in unserer Anwendung gespeicherten
        Daten betrifft. Daten, die Meta (WhatsApp) eigenständig verarbeitet, unterliegen
        den
        <a href="https://www.whatsapp.com/legal/privacy-policy" target="_blank" class="text-primary">
          Datenschutzbestimmungen von Meta</a>.
      </p>

      <v-divider class="my-4" />

      <div class="d-flex align-center justify-space-between">
        <p class="text-body-2 text-grey mb-0">Stand: März 2026</p>
        <router-link to="/datenschutz" class="text-caption text-grey text-decoration-none">
          Datenschutzerklärung
        </router-link>
      </div>
    </v-card>
  </v-container>
</template>

<script>
export default {
  name: 'DatenloeschungView',
}
</script>
