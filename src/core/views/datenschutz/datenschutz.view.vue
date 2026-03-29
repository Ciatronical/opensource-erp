<template>
  <v-app-bar color="grey-lighten-4" elevation="1" density="comfortable">
    <v-toolbar-title class="text-h6">
      <router-link to="/" class="text-decoration-none text-primary">OpensourceERP</router-link>
    </v-toolbar-title>
  </v-app-bar>

  <v-container class="py-8" style="max-width: 900px;">
    <v-card variant="outlined" class="border-md pa-6">
      <h1 class="text-h4 mb-6">Datenschutzerklärung</h1>

      <h2 class="text-h6 mt-4 mb-2">1. Verantwortlicher</h2>
      <p class="text-body-1 mb-4">
        Der Verantwortliche im Sinne der Datenschutz-Grundverordnung (DSGVO) ist der Betreiber
        dieser OpensourceERP-Installation. Kontaktdaten entnehmen Sie bitte dem Impressum.
      </p>

      <h2 class="text-h6 mt-4 mb-2">2. Erhebung und Verarbeitung personenbezogener Daten</h2>
      <p class="text-body-1 mb-2">
        Wir verarbeiten personenbezogene Daten nur, soweit dies zur Bereitstellung unserer
        Dienste erforderlich ist. Folgende Daten werden verarbeitet:
      </p>
      <ul class="text-body-1 mb-4 ml-4">
        <li>Kontaktdaten (Name, Adresse, E-Mail, Telefonnummer)</li>
        <li>Kommunikationsdaten (E-Mail-Verkehr, WhatsApp-Nachrichten, Anrufprotokolle)</li>
        <li>Geschäftsdaten (Angebote, Aufträge, Rechnungen)</li>
        <li>Zugangsdaten (Benutzername, verschlüsseltes Passwort)</li>
      </ul>

      <h2 class="text-h6 mt-4 mb-2">3. WhatsApp Business API</h2>
      <p class="text-body-1 mb-2">
        Diese Anwendung nutzt die WhatsApp Business Cloud API von Meta Platforms, Inc.
        zur geschäftlichen Kommunikation. Dabei werden folgende Daten verarbeitet:
      </p>
      <ul class="text-body-1 mb-2 ml-4">
        <li>Telefonnummern der Kommunikationspartner</li>
        <li>Nachrichteninhalte (Text, Medien)</li>
        <li>Zustellstatus und Zeitstempel</li>
        <li>Kontaktnamen</li>
      </ul>
      <p class="text-body-1 mb-4">
        Die Verarbeitung erfolgt auf Grundlage von Art. 6 Abs. 1 lit. b DSGVO (Vertragserfüllung)
        bzw. Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an effizienter Kundenkommunikation).
        Nachrichten werden über die Server von Meta (USA) übermittelt. Es gelten die
        Datenschutzbestimmungen von Meta:
        <a href="https://www.whatsapp.com/legal/privacy-policy" target="_blank" class="text-primary">
          WhatsApp-Datenschutzrichtlinie
        </a>.
      </p>

      <h2 class="text-h6 mt-4 mb-2">4. E-Mail-Kommunikation</h2>
      <p class="text-body-1 mb-4">
        E-Mails werden über IMAP/SMTP verarbeitet und in der Anwendung gespeichert,
        um eine effiziente Kundenkommunikation zu gewährleisten. Die Rechtsgrundlage ist
        Art. 6 Abs. 1 lit. b und f DSGVO.
      </p>

      <h2 class="text-h6 mt-4 mb-2">5. Datenspeicherung und -sicherheit</h2>
      <p class="text-body-1 mb-4">
        Alle Daten werden auf eigenen Servern des Verantwortlichen gespeichert. Die Übertragung
        erfolgt TLS-verschlüsselt (HTTPS). Der Zugang zur Anwendung ist durch Benutzername
        und Passwort geschützt. Daten werden nur so lange gespeichert, wie es für den jeweiligen
        Verarbeitungszweck oder gesetzliche Aufbewahrungsfristen erforderlich ist.
      </p>

      <h2 class="text-h6 mt-4 mb-2">6. Rechte der Betroffenen</h2>
      <p class="text-body-1 mb-2">Sie haben das Recht auf:</p>
      <ul class="text-body-1 mb-4 ml-4">
        <li>Auskunft über Ihre gespeicherten Daten (Art. 15 DSGVO)</li>
        <li>Berichtigung unrichtiger Daten (Art. 16 DSGVO)</li>
        <li>Löschung Ihrer Daten (Art. 17 DSGVO)</li>
        <li>Einschränkung der Verarbeitung (Art. 18 DSGVO)</li>
        <li>Datenübertragbarkeit (Art. 20 DSGVO)</li>
        <li>Widerspruch gegen die Verarbeitung (Art. 21 DSGVO)</li>
      </ul>

      <h2 class="text-h6 mt-4 mb-2">7. Kontakt</h2>
      <p class="text-body-1 mb-4">
        Für Fragen zum Datenschutz wenden Sie sich bitte an den Verantwortlichen
        über die im Impressum angegebenen Kontaktdaten.
      </p>

      <v-divider class="my-4" />
      <div class="d-flex align-center justify-space-between">
        <p class="text-body-2 text-grey mb-0">Stand: März 2026</p>
        <router-link to="/datenloeschung" class="text-caption text-grey text-decoration-none">
          Anleitung zur Datenlöschung
        </router-link>
      </div>
    </v-card>
  </v-container>
</template>

<script>
export default {
  name: 'DatenschutzView',
}
</script>
