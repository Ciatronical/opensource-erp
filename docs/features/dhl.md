# DHL — Versandintegration

Automatisierte Paketerstellung und Labeldruck über die DHL Geschäftskunden-API.

## Voraussetzungen

- **DHL Geschäftskundenvertrag** (nicht Privatkunden-API)
- **API-Zugangsdaten** von DHL (Benutzername, Passwort, Abrechnungsnummer)

Die komplette Einrichtung ist in [DHL-Setup](../dhl-setup.md) beschrieben.

## Funktionen

- **Sendungen erstellen**: Paketlabel direkt aus dem System generieren
- **Label drucken**: Versandetiketten als PDF
- **Sendungen stornieren**: Erstellte Sendungen vor Abholung löschen
- **Abrechnungsnummer**: Zusammengesetzt aus EKP + Verfahren + Teilnahme (wird in der Einrichtung erklärt)

## Konfiguration

Die DHL-Zugangsdaten werden unter **Einstellungen** konfiguriert. Details zur Zusammensetzung der Abrechnungsnummer siehe [DHL-Setup](../dhl-setup.md).
