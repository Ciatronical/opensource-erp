---
title: Shop
summary: Webshop-Anbindung: HugoShop-Webseite, Verkaufskanäle, Versand, PayPal, Widerruf, eBay
group: extension
extension: shop
category: Verkauf
order: 200
status: stable
---

# Shop — Webshop-Anbindung

Die Erweiterung **Shop** macht aus OpensourceERP das Backend eines Webshops: Artikel werden über Verkaufskanäle angeboten, die Shop-Webseite (ein Hugo-Projekt) bekommt ihre Produktseiten und Widgets von OpensourceERP, und Warenkorb, Kundenkonto, Bestellung, Rechnung und Zahlung laufen über die öffentliche Shop-API. Mehrere Erweiterungen können zugleich aktiv sein; der Shop ist unabhängig von LxCars.

Aktivieren unter **Einstellungen → Features → Erweiterungen → Shop**; danach erscheint das Menü **Shop** und der Reiter „Shop" in der Firmenkonfiguration.

## Verkaufskanäle

Ein Artikel mit „Im Shop anbieten" wird über einen oder mehrere **Verkaufskanäle** angeboten. Kanalarten sind **HugoShop** (eigene Webseite) und **eBay**; je Firma sind beliebig viele Kanäle je Art möglich, jeder mit eigenen Einstellungen.

- Je Kanal: Preisaufschlag (Prozent oder Betrag, wahlweise Rundung auf ,99), eigener Titel und Beschreibung je Artikel, eigene Bilderverwaltung, „Momentan nicht verfügbar"
- Grundpreis ist der Verkaufspreis des Artikels; brutto oder netto je nach Einstellung
- Ein gemeinsamer Lagerbestand für alle Kanäle; Verkäufe buchen vom Shop-Lagerplatz aus
- Mindestens ein Kanal bleibt eingeschaltet; abgeschaltete Kanäle bieten nichts an, ihre Angaben bleiben erhalten

### HugoShop

Die Webseite selbst — Hugo-Projekt, Theme, Inhalte — gehört dem Betreiber. OpensourceERP schreibt nur die Produktseiten und ein Paket mit Shortcodes, Partials und dem Widget-Bundle.

- **Produktseiten** entstehen aus Vorlagensätzen (mitgeliefert oder eigene Kopie) als Markdown mit Front Matter; Preis, Bezeichnung, Beschreibung, technische Daten, Eigenschaften, Downloads und Bilder kommen aus dem Artikel und seinen Shop-Angaben
- **Aufträge** (Seite schreiben, alle schreiben, entfernen, Paket abgleichen) arbeitet ein Läufer per Cron oder auf Knopfdruck ab; Preis- und Textänderungen stellen Aufträge automatisch ein
- **Bau** der Webseite lokal mit Hugo oder über HugoCMS auf einem eigenen Webserver
- **Widgets** (Lit-Web-Components): Suche, Trefferliste, Warenkorb, In-den-Warenkorb, Login, Registrierung, Kundenkonto (Übersicht, Profil, Adressen, Zahlung, Bestellungen), Kasse, Rechnung, Kontaktformular, Werkzeugsuche (LxCars)
- **Öffentliche Shop-API** mit Shop-Schlüssel und Aktions-Whitelist; Kundenkonten, Warenkorb, Bestellung mit Rechnung, PayPal-Zahlung, Rechnungsdownload, Kategorieübersicht, Weiterleitungen alter Adressen

### eBay

Angebote, Bilder, Bestellimport samt Kundenzuordnung, Rechnung und Buchung, Cron-Abruf, Verbindungstest und Statusanzeige. Menge = Lagerbestand; bei 0 bleibt das Angebot als „ausverkauft" stehen.

## Versand

- **Versandarten** mit Zonen, Ländern und Staffeln nach Gewicht oder Warenwert, versandkostenfrei ab Betrag
- Je Artikel: Maße, Mindestabnahme, Lieferbedingung; die Prüfung vor dem Veröffentlichen verhindert Seiten ohne passende Versandart
- Länderzuordnung mit Aliasnamen für Eingaben der Kunden

## Bestellung, Zahlung, Widerruf

- Kauf auf Rechnung oder **PayPal** (Sandbox und Echtbetrieb), Abgleich schwebender Zahlungen per Cron
- Rechnung als PDF, Versand per E-Mail aus Vorlagen
- **Widerruf** nach § 356a BGB: Formular auf der Webseite, Nachweis in der Datenbank, Mails an Kunde und Betreiber, Bearbeitung in der Shop-Übersicht
- Shop-Übersicht im ERP: Kennzahlen, Bestellungen, offene Zahlungen, Widerrufe, Aufträge des Läufers, Einrichtungsprüfung mit Hinweisen auf fehlende Einstellungen

## Einrichtung

Siehe `dev/shop-betrieb.md` (Läufer, Cron, Paket), `dev/shop-verkaufskanaele.md` (Kanäle und Preise), `dev/shop-versand.md`, `dev/shop-widerruf.md`.
