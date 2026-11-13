---
title: Shop
summary: Webshop-Anbindung: HugoShop-Webseite, Verkaufskanäle, Versand, PayPal, Widerruf, eBay, Kleinanzeigen
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

Ein Artikel mit „Im Shop anbieten" wird über einen oder mehrere **Verkaufskanäle** angeboten. Kanalarten sind **HugoShop** (eigene Webseite), **eBay** und **Kleinanzeigen**; je Firma sind beliebig viele Kanäle je Art möglich, jeder mit eigenen Einstellungen.

- Je Kanal: Preisaufschlag (Prozent oder Betrag, wahlweise Rundung auf ,99), eigener Titel und Beschreibung je Artikel, eigene Bilderverwaltung, „Momentan nicht verfügbar"
- Grundpreis ist der Verkaufspreis des Artikels; brutto oder netto je nach Einstellung
- Ein gemeinsamer Lagerbestand für alle Kanäle; Verkäufe buchen vom Shop-Lagerplatz aus
- **Dropshipping-Artikel** (Kennzeichen in der Shop-Karte des Artikels, oder „Nur Dropshipping" für alle Artikel in der Firmenkonfiguration → Shop): der Lieferant versendet, eigener Bestand ist nicht nötig. Die Produktseite zeigt „Verfügbar" mit der Lieferzeit, Marktplätze bieten eine feste Menge an, und ein Verkauf bucht nur aus, was am eigenen Lager liegt. Liegt eigener Bestand da, gilt „Auf Lager" mit der Lieferzeit für Lagerware. Ohne Lieferzeit wird ein Dropshipping-Artikel nicht veröffentlicht
- **Lieferzeiten**: Lieferbedingung je Artikel, sonst die Vorgaben des Kanals für Lagerware und für Dropshipping-Artikel (Ansicht „Verkaufskanäle"), für Dropshipping-Artikel zuletzt die Standard-Lieferbedingung des Mandanten (Firmenkonfiguration → Shop); die Tage je Lieferbedingung für die Suchmaschinendaten stehen in der Ansicht „Versandarten"
- Mindestens ein Kanal bleibt eingeschaltet; abgeschaltete Kanäle bieten nichts an, ihre Angaben bleiben erhalten

### HugoShop

Die Webseite selbst — Hugo-Projekt, Theme, Inhalte — gehört dem Betreiber. OpensourceERP schreibt nur die Produktseiten und ein Paket mit Shortcodes, Partials und dem Widget-Bundle.

- **Produktseiten** entstehen aus Vorlagensätzen (mitgeliefert oder eigene Kopie) als Markdown mit Front Matter; Preis, Bezeichnung, Beschreibung, technische Daten, Eigenschaften, Downloads und Bilder kommen aus dem Artikel und seinen Shop-Angaben
- **Medienbibliothek** für Produktbilder und Downloads: Bilder werden beim Hochladen auf Wunsch umgewandelt (AVIF, WebP, JPEG, PNG), das voreingestellte Format steht in der Firmenkonfiguration → Shop → Veröffentlichung; AVIF schreibt GD oder, wenn GD es nicht kann, ffmpeg (libaom), samt Transparenz. Jeder Marktplatz bekommt seine Bilder in einem Format, das er annimmt (eBay: JPEG, PNG, GIF, WebP; Kleinanzeigen: JPEG, PNG) — was nicht passt, legt OSERP für diesen Kanal automatisch als JPEG an, beim Hochladen, beim Übernehmen aus einem anderen Kanal und vor dem Einstellen
- **Aufträge** (Seite schreiben, alle schreiben, entfernen, Paket abgleichen) arbeitet ein Läufer per Cron oder auf Knopfdruck ab; Preis- und Textänderungen stellen Aufträge automatisch ein
- **Bau** der Webseite lokal mit Hugo oder über HugoCMS auf einem eigenen Webserver
- **Widgets** (Lit-Web-Components): Suche, Trefferliste, Warenkorb, In-den-Warenkorb, Login, Registrierung, Kundenkonto (Übersicht, Profil, Adressen, Zahlung, Bestellungen), Kasse, Rechnung, Kontaktformular, Werkzeugsuche (LxCars)
- **Öffentliche Shop-API** mit Shop-Schlüssel und Aktions-Whitelist; Kundenkonten, Warenkorb, Bestellung mit Rechnung, PayPal-Zahlung, Rechnungsdownload, Kategorieübersicht, Weiterleitungen alter Adressen

### eBay

Eigenes eBay-Board: Verbinden per Anmeldung, bestehende Angebote übernehmen und zuordnen, Verkäufe mit Käuferprüfung (eindeutige automatisch abgerechnet), Versand melden, Produktseiten mit KI; dazu Einstellen über die Inventory-API mit Menge = Lagerbestand. Eigene Seite: [eBay](ebay.md).

### Kleinanzeigen

Assistierter Kanal für Kleinanzeigen.de (keine Schnittstelle): Vorschläge aus Ladenhütern, fertig vorbereitete Anzeigen mit KI-Text, Einstellen per Kopieren mit Bilder-ZIP, Wachen über Preis, Bestand und Auffrischen, Anfragen aus dem Postfach, Verkauf mit Rechnung und Lagerbuchung, Feed für Sync-Werkzeuge. Eigene Seite: [Kleinanzeigen](kleinanzeigen.md).

## Versand

- **Versandarten** mit Zonen, Ländern und Staffeln nach Gewicht oder Warenwert, versandkostenfrei ab Betrag; Zonen dürfen sich überlappen (ein Land in mehreren Zonen), es gilt die engere Zone
- Je Artikel erlaubte Versandarten, auf Wunsch „nur zusammen mit anderen Artikeln“: Der Kunde kann eine solche Versandart für den Artikel allein nicht wählen, sie gilt erst, wenn ein anderer Artikel im Warenkorb sie erlaubt
- Je Artikel: Maße, Mindestabnahme, Lieferbedingung; die Prüfung vor dem Veröffentlichen verhindert Seiten ohne passende Versandart
- Länderzuordnung mit Aliasnamen für Eingaben der Kunden

## Bestellung, Zahlung, Widerruf

- Kauf auf Rechnung oder **PayPal** (Sandbox und Echtbetrieb), Abgleich schwebender Zahlungen per Cron
- Rechnung als PDF, Versand per E-Mail aus Vorlagen
- **Widerruf** nach § 356a BGB: Formular auf der Webseite, Nachweis in der Datenbank, Mails an Kunde und Betreiber, Bearbeitung in der Shop-Übersicht
- Shop-Übersicht im ERP: Kennzahlen, Bestellungen, offene Zahlungen, Widerrufe, Aufträge des Läufers, Einrichtungsprüfung mit Hinweisen auf fehlende Einstellungen

## Einrichtung

Siehe `dev/shop-betrieb.md` (Läufer, Cron, Paket), `dev/shop-verkaufskanaele.md` (Kanäle und Preise), `dev/shop-versand.md`, `dev/shop-widerruf.md`.
