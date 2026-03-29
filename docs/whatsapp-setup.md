# WhatsApp Business API - Einrichtung

## Voraussetzungen

- Meta Business Account (https://business.facebook.com)
- Meta Developer Account (https://developers.facebook.com)
- Öffentlich erreichbare Domain mit HTTPS (für Webhook)

---

## 1. Meta App erstellen

1. Gehe zu https://developers.facebook.com/apps
2. **App erstellen** → Typ: **Business**
3. WhatsApp als Produkt hinzufügen

---

## 2. API-Zugangsdaten beschaffen

### Phone Number ID & Business Account ID

- developers.facebook.com → Deine App → **WhatsApp** → **API Setup**
- **Phone Number ID** steht unter der Testnummer (z.B. `101234567890123`)
- **WhatsApp Business Account ID** steht oben auf der Seite

### Permanenten Access Token erstellen

Der temporäre Token auf der API-Setup-Seite ist nur 24h gültig. Für einen permanenten Token:

1. Gehe zu https://business.facebook.com/settings/system-users
2. **System User erstellen** (z.B. "WhatsApp API Bot"), Rolle: **Admin**
3. **Assets zuweisen** → Deine App auswählen → Volle Kontrolle
4. **Token generieren** mit diesen Berechtigungen:
   - `whatsapp_business_management`
   - `whatsapp_business_messaging`
5. Token kopieren und sicher aufbewahren

### Eigene Geschäftsnummer einrichten (optional)

Für Produktion statt Testnummer:
- WhatsApp → API Setup → **Add phone number**
- Nummer per SMS/Anruf verifizieren
- Display Name wird von Meta geprüft (kann 1-2 Werktage dauern)

---

## 3. CRM konfigurieren

In der Anwendung unter **Mandantenkonfiguration → CRM → WhatsApp Business API**:

| Feld | Wert |
|------|------|
| Access Token | Permanenter Token aus Schritt 2 |
| Phone Number ID | Phone Number ID aus dem API Setup |
| Business Account ID | WhatsApp Business Account ID |
| Webhook Verify Token | Selbst gewähltes Passwort (z.B. `mein_geheimer_token_2024`) |

Unter **Mandantenkonfiguration → CRM → WhatsApp**:

| Feld | Wert |
|------|------|
| Landesvorwahl | `+49` (für Deutschland) |

Speichern.

---

## 4. Webhook einrichten

### Apache-Konfiguration

Die Apache-Config (`install/apacheOpensourceErp.conf`) enthält bereits den Webhook-Alias:

```apache
Alias /webhook /pfad/zu/opensource-erp/backend/webhook
```

Nach Änderungen: `sudo systemctl restart apache2`

### Meta Dashboard

1. developers.facebook.com → Deine App → WhatsApp → **Configuration**
2. **Edit** bei Callback URL:
   - **Callback URL:** `https://deine-domain.de/webhook/whatsapp.php`
   - **Verify Token:** Gleicher Wert wie in der CRM-Config
3. **Verify and Save**
4. **Manage** → Webhook fields abonnieren:
   - `messages` (eingehende Nachrichten)

### Webhook testen

```bash
# Verification testen (muss den Challenge-Wert zurückgeben)
curl "https://deine-domain.de/webhook/whatsapp.php?hub.mode=subscribe&hub.verify_token=DEIN_TOKEN&hub.challenge=test123"
# Erwartete Antwort: test123
```

---

## 5. SSE-Server neu starten

Der SSE-Server muss neu gestartet werden, damit er auf WhatsApp-Benachrichtigungen lauscht:

```bash
# Im Projektverzeichnis
./scripts/run-dev.sh
# oder bei Production: den SSE-Service neu starten
```

---

## 6. Datenbank aktualisieren

Einfach **ausloggen und wieder einloggen**. Das Schema (`whatsapp_messages`-Tabelle) wird automatisch angelegt.

---

## Funktionsübersicht

### Kundenansicht → WhatsApp-Tab
- Chatverlauf mit dem Kunden anzeigen (alle Telefonnummern)
- Nachrichten senden (Text)
- Status-Anzeige: gesendet ✓, zugestellt ✓✓, gelesen ✓✓ (blau)

### Infoleiste
- Neue eingehende WhatsApp-Nachrichten erscheinen als grüne Chips
- Klick öffnet den Kunden im WhatsApp-Tab
- Echtzeit-Updates via SSE + Backup-Polling alle 120 Sekunden

### Config
- WhatsApp-Einstellungen unter CRM-Tab (unabhängig von LxCars)
- Max. Anzahl WhatsApp-Nachrichten in Infoleiste konfigurierbar

---

## Fehlerbehebung

| Problem | Lösung |
|---------|--------|
| Webhook-Verification schlägt fehl | Verify Token in CRM-Config und Meta Dashboard vergleichen |
| Nachrichten werden nicht empfangen | Webhook-URL prüfen (muss HTTPS sein), `messages` abonniert? |
| Senden schlägt fehl | Access Token gültig? Phone Number ID korrekt? |
| Kein WhatsApp-Tab sichtbar | Ausloggen/einloggen (DB-Schema), Kunde muss Telefonnummer haben |
| InfoBar zeigt keine WhatsApp-Chips | SSE-Server neu starten, Browser-Konsole auf Fehler prüfen |
