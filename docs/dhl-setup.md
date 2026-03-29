# DHL Versand - Einrichtung

## Voraussetzungen

- DHL Geschäftskundenvertrag ("Geschäftskundenversand")
- DHL Developer Portal Account

---

## 1. Geschäftskundenvertrag abschließen

Ohne Vertrag gibt es keine API-Zugangsdaten. Es gibt zwei Wege:

### Direkt bei DHL

1. https://www.dhl.de/de/geschaeftskunden/paket/kunde-werden.html
2. **Geschäftskundenvertrag** beantragen
3. Nach Vertragsabschluss erhältst du per Post/E-Mail:
   - **EKP-Nummer** (10-stellig) — deine Kundennummer bei DHL
   - **GKV-Benutzername** und **GKV-Passwort** (für die API-Authentifizierung)

### Über einen DHL-Vertriebspartner

Viele Versandplattformen (z.B. Shipcloud, Sendcloud) vermitteln DHL-Verträge mit vergünstigten Konditionen.

---

## 2. Abrechnungsnummer zusammensetzen

Die 14-stellige Abrechnungsnummer (Billing Number) setzt sich zusammen aus:

```
[EKP 10-stellig][Produkt 2-stellig][Teilnahme 2-stellig]
```

| Produkt | Code | Beispiel (EKP: 1234567890) |
|---------|------|----------------------------|
| DHL Paket | 01 | 12345678900101 |
| DHL Paket International | 53 | 12345678905301 |
| DHL Warenpost | 62 | 12345678906201 |
| DHL Warenpost International | 66 | 12345678906601 |

Die Teilnahmenummer ist in der Regel `01`. Bei mehreren Standorten kann sie abweichen — steht in deinen Vertragsunterlagen.

---

## 3. DHL Developer Portal einrichten

1. Gehe zu https://developer.dhl.com
2. **Account erstellen** (kostenlos)
3. Nach dem Login: **Apps** → **Neue App erstellen**
4. Produkt auswählen: **Parcel DE Shipping** (Post & Paket Deutschland)
5. App anlegen → du erhältst deinen **API-Key**

### Sandbox-Zugangsdaten (zum Testen)

Die Sandbox funktioniert sofort — ohne echten Geschäftskundenvertrag:

| Feld | Sandbox-Wert |
|------|-------------|
| API-Key | Dein API-Key aus dem Developer Portal |
| GKV-Benutzername | Auf dem Developer Portal unter der App einsehbar |
| GKV-Passwort | Auf dem Developer Portal unter der App einsehbar |
| Abrechnungsnummer | `22222222220101` (Paket) oder `22222222225301` (International) |
| Absender-PLZ | `53113` (Bonn) |

> Die Sandbox erstellt gültig aussehende Labels und Sendungsnummern, verursacht aber keine Kosten und keine echten Sendungen.

---

## 4. Produktiv-Freischaltung

Nach erfolgreichem Sandbox-Test:

1. Im Developer Portal bei deiner App: **Go Live** beantragen
2. DHL prüft ggf. die Anbindung (kann 1-2 Werktage dauern)
3. Nach Freigabe: Sandbox-Modus in OpensourceERP deaktivieren

---

## 5. In OpensourceERP konfigurieren

1. **Firmenkonfiguration** öffnen (Zahnrad-Icon)
2. Runterscrollen zum Abschnitt **DHL Versand**
3. Folgende Felder ausfüllen:

| Feld | Beschreibung |
|------|-------------|
| DHL-Versand aktivieren | Checkbox anhaken |
| Sandbox-Modus | Zum Testen aktivieren, für Echtbetrieb deaktivieren |
| DHL API-Key | API-Key aus dem Developer Portal |
| GKV-Benutzername | Benutzername aus dem Geschäftskundenvertrag |
| GKV-Passwort | Passwort aus dem Geschäftskundenvertrag |
| Abrechnungsnummer | 14-stellige Nummer (siehe Schritt 2) |
| Standard-Produkt | DHL Paket (für die meisten Fälle) |
| Etikettenformat | 103x199mm für Labeldrucker, A4 für normalen Drucker |

4. **Absenderdaten** ausfüllen (Firma, Straße, Hausnummer, PLZ, Ort, Land)
5. Ausloggen und wieder einloggen (damit die DB-Tabelle angelegt wird)

---

## 6. Versandetikett erstellen

1. Beleg öffnen (Rechnung, Auftrag oder Lieferschein)
2. In der Aktionsleiste auf das **Paket-Icon** klicken
3. **Gewicht** eingeben, ggf. Produkt und Maße anpassen
4. **"Etikett erstellen"** klicken
5. Das PDF-Label wird automatisch heruntergeladen
6. Die Sendungsnummer wird am Beleg gespeichert und ist als DHL-Tracking-Link klickbar

### Bestehende Sendungen

Im DHL-Dialog werden alle bereits erstellten Sendungen zum Beleg angezeigt. Von dort aus kann man:

- Labels erneut herunterladen
- Sendungen stornieren (solange sie noch nicht beim Paketshop abgegeben wurden)

---

## Troubleshooting

**"DHL API Key ist nicht konfiguriert"**
→ API-Key in der Firmenkonfiguration eintragen

**"DHL GKV-Zugangsdaten sind nicht konfiguriert"**
→ GKV-Benutzername und Passwort eintragen

**HTTP 401 (Unauthorized)**
→ GKV-Zugangsdaten oder API-Key falsch. Im Sandbox-Modus die Test-Credentials vom Developer Portal verwenden.

**HTTP 400 (Bad Request)**
→ Meistens fehlerhafte Adressdaten. Häufige Ursachen:
- PLZ und Ort passen nicht zusammen
- Hausnummer fehlt oder ist ungültig
- Abrechnungsnummer falsch (muss 14-stellig sein)

**Kein Paket-Button in der Faktura sichtbar**
→ "DHL-Versand aktivieren" in der Firmenkonfiguration anhaken

---

## Links

| Ressource | URL |
|-----------|-----|
| DHL Developer Portal | https://developer.dhl.com |
| API-Dokumentation | https://developer.dhl.com/api-reference/parcel-de-shipping-post-parcel-germany-v2 |
| Geschäftskunde werden | https://www.dhl.de/de/geschaeftskunden/paket/kunde-werden.html |
| Sendungsverfolgung | https://www.dhl.de/de/privatkunden/pakete-empfangen/verfolgen.html |
