---
title: Kleinanzeigen
summary: Verkaufskanal Kleinanzeigen.de — Ladenhüter finden, Anzeigen fertig vorbereiten, mit wenigen Klicks einstellen, Anfragen und Verkäufe im Griff
group: extension
extension: shop
category: Verkauf
order: 210
status: stable
since: 2026-10-16
---

# Kleinanzeigen — Verkaufskanal mit eigenem Browser-Dienst

Kleinanzeigen.de ist der größte deutsche Marktplatz für private und gewerbliche Kleinanzeigen — und hat **keine Schnittstelle** zum Einstellen von Anzeigen. OpensourceERP bringt deshalb seine eigene mit: den **Kleinanzeigen-Dienst**, einen unsichtbaren Chrome auf dem eigenen Server, der sich mit dem Konto des Betriebs anmeldet und alles erledigt, was sonst ein Mensch am Browser tut — Anzeigen einstellen, ändern, löschen, verlängern, die eigene Anzeigenliste lesen, Nachrichten holen und beantworten. Der Betreiber bestätigt höchstens einmal die Anmeldung; den Code per E-Mail holt OSERP selbst aus dem Postfach.

Ohne den Dienst läuft der Kanal **von Hand**: OSERP bereitet jede Anzeige fertig vor, Sie stellen sie per Kopieren ein und melden zurück.

Der Kanal gehört zur Erweiterung **Shop** und erscheint im Shop-Menü als **Kleinanzeigen**. Eingeschaltet wird er unter **Shop → Verkaufskanäle**, dort stehen auch seine Einstellungen.

## Automatisch: der Kleinanzeigen-Dienst

| Schritt | Was der Dienst tut |
|---------|--------------------|
| **Anmelden** | Sie melden sich in Ihrem eigenen Browser bei Kleinanzeigen an, wie immer. Dann übernehmen Sie in der Kanalkarte die Anmelde-IDs (die Cookies der Seite, aus den Entwicklerwerkzeugen kopiert): der Dienst setzt sie in sein Browserprofil und ist damit angemeldet. OSERP hebt die IDs auf und spielt sie nach einem Neustart oder Profilverlust von selbst wieder ein. Kein Passwort im ERP, keine automatische Anmeldung. |
| **Kategorien** | Kleinanzeigen hat eigene Kategorien. Die Kanalkarte liest den Baum einmal über den Dienst aus dem Anzeigenformular; danach wählen Sie die Kategorie je Artikel (Artikelkarte, Reiter Shop) oder in der Anzeige aus einer Suchliste, die Kanalvorgabe gilt für alle ohne eigene. Nur die letzte Ebene lässt sich einstellen. |
| **Einstellen** | „Jetzt bei Kleinanzeigen einstellen“ im Board oder in der Anzeige: Kategorie (Artikel oder Vorgabe des Kanals, Pfad wie „Auto, Rad & Boot > Autoteile & Reifen > Weitere Autoteile“), Zustand, Versand, Preis, Text, PLZ, Bilder, Titel; kostenloses Basis-Paket; Hinweisdialoge werden weggeklickt. Die Anzeigennummer kommt zurück, das Board wacht. |
| **Nachführen** | Ändert sich Preis, Titel oder Text, legt OSERP wie bei eBay einen Auftrag an; der Shop-Läufer aktualisiert die Anzeige. Abgewählt oder ausverkauft → der Läufer löscht sie. Im Board lassen sich die Aufgaben auch sofort erledigen („Jetzt aktualisieren“, „Jetzt löschen“, „Auffrischen“). |
| **Auffrischen** | „Verlängern“ bei Kleinanzeigen (in den letzten Tagen vor Ablauf), sonst löschen und neu einstellen — die Anzeige steht wieder oben. |
| **Abgleich** | Der Läufer (`--reconcile-payments`) liest „Meine Anzeigen“: gelöschte oder abgelaufene Anzeigen werden beendet, reservierte erkannt. |
| **Nachrichten** | Der Läufer holt die Unterhaltungen; im Board stehen Absender, Anzeige und letzte Nachricht. Antworten gehen direkt aus dem Board an Kleinanzeigen — mit KI-Vorschlag auf Knopfdruck. |

Einrichtung: `backend/services/kleinanzeigen/README.md` (Installer, Dienst `oserp-kleinanzeigen`, Token), dann **Firmenkonfiguration → Shop → Kleinanzeigen-Dienst** und in der Kanalkarte die E-Mail des Kleinanzeigen-Kontos und die Anmelde-IDs.

Hinweis: Automatisiertes Einstellen widerspricht den Nutzungsbedingungen von Kleinanzeigen. Der Dienst arbeitet wie ein Mensch am Browser, mit einem Konto und Pausen zwischen den Schritten — das Risiko einer Kontosperre trägt der Betreiber.

## Was der Kanal tut

| Schritt | Was OpensourceERP übernimmt |
|---------|-----------------------------|
| **Vorschläge** | Zeigt, welche Ware Geld bindet und sich nicht bewegt: Bestand mal Einkaufspreis, gewichtet mit den Monaten ohne Verwendung in Belegen. Artikel mit Bildern zuerst — eine Anzeige ohne Bild bringt nichts. |
| **Anzeige vorbereiten** | Titel (65 Zeichen), Text (reiner Text aus der Langbeschreibung plus Fußtext des Kanals), Preis in ganzen Euro brutto mit Kanalaufschlag, Preisart, Versand, Zustand, Kategorie, Standort und die Bilder des Kanals. Fehlt etwas, sagt das Board es. |
| **KI-Text** | Auf Knopfdruck schreibt Claude Titel und Text aus Artikelstamm, technischen Daten und Eigenschaften — ohne erfundene Angaben, mit den Suchbegriffen vorn. Tonfall sachlich oder werblich, Hinweis an die KI möglich. |
| **Einstellen** | Ein Klick öffnet „Anzeige aufgeben“ bei Kleinanzeigen, je ein Klick kopiert Titel, Text und Preis, die Bilder kommen als ZIP (nummeriert, damit die Reihenfolge stimmt). Danach die Adresse der Anzeige einfügen: **Anzeige ist online**. |
| **Wachen** | Das Board merkt sich den Stand beim Einstellen und sagt, wenn etwas zu tun ist: **aktualisieren** (Preis, Titel oder Text haben sich geändert), **löschen** (Bestand 0, Artikel abgewählt, Kanal aus) oder **auffrischen** (älter als die eingestellte Frist — neue Anzeigen stehen weiter oben). |
| **Anfragen** | Die Benachrichtigungen von Kleinanzeigen landen im Postfach des E-Mail-Moduls; der Kanal liest sie, ordnet sie der Anzeige zu und zeigt Absender und Nachricht. Claude schlägt eine Antwort vor, die Sie bei Kleinanzeigen einfügen. |
| **Verkaufen** | „Verkauft“ hält Menge, erzielten Preis und Zahlart fest, legt die Rechnung brutto an, bucht sie ins Hauptbuch und — mit Shop-Lagerplatz — vom Lager. Käufer: bestehender Kunde, neuer Kunde aus Name und E-Mail oder der Barverkaufskunde des Kanals. Ist der Bestand weg, zeigt das Board „Anzeige löschen“. |
| **Zahlen** | Anzeigen online, Warenwert online, offene Aufgaben, offene Anfragen, Umsatz der letzten 30 Tage, gebundenes Kapital in den Vorschlägen. |

## Einrichten

1. **Shop → Verkaufskanäle**: den Kanal „Kleinanzeigen“ einschalten und aufklappen.
2. **Standort** (PLZ und Ort) eintragen — Kleinanzeigen verlangt ihn für jede Anzeige.
3. **Vorgaben**: Preisart (Festpreis oder VB), Versand, Zustand, Kategorie als Gedächtnisstütze, Tonfall der KI-Texte, Fußtext (gewerblicher Verkauf, Gewährleistung, Rechnung, Öffnungszeiten), Frist fürs Auffrischen.
4. **Verkauf**: Barverkaufskunde für Verkäufe ohne Käuferangabe, Mitarbeiter für die Rechnungen.
5. **Anfragen**: Die E-Mail-Adresse des Kleinanzeigen-Kontos muss das Postfach des E-Mail-Moduls sein (Firmenkonfiguration → E-Mail); Ordner, in dem die Benachrichtigungen liegen (Vorgabe INBOX).
6. **KI-Texte** brauchen den Anthropic-API-Schlüssel (Firmenkonfiguration → KI); das Modell ist je Firma einstellbar (Vorgabe Claude Haiku 4.5) und am Dialog umschaltbar.

Hinweis für gewerbliche Verkäufer: Impressum, Widerrufsbelehrung und AGB gehören ins Kleinanzeigen-Konto (PRO-Konto), nicht in den Anzeigentext. Der Fußtext des Kanals ergänzt nur den Text.

## Arbeiten mit dem Board

**Vorschläge** — Artikel markieren und „Anbieten“: Sie werden Entwürfe, Bilder aus anderen Kanälen (erst eBay, dann HugoShop) werden übernommen. Ein einzelner Artikel öffnet gleich die Anzeige.

**Entwürfe** — Karte öffnen, Titel und Text prüfen oder von der KI schreiben lassen, Preis und Vorgaben anpassen, speichern. Rechts der Weg zu Kleinanzeigen in vier Schritten; am Ende die Adresse der Anzeige einfügen.

**Online** — Karten mit Aufgaben-Chip. Rückmeldungen: Aktualisiert, Aufgefrischt, Reserviert, Wieder frei, Verkauft, Gelöscht. Die Anzeige bei Kleinanzeigen öffnet der Pfeil. Ein Artikel bleibt online, solange Bestand da ist.

**Anfragen** — „Postfach abrufen“ holt neue Benachrichtigungen (regelmäßig auch über den Shop-Läufer). Je Anfrage: Antwort vorschlagen (KI), Beantwortet, Verkauft (öffnet den Verkauf mit dem Absender als Käufer), Erledigt. Nicht zugeordnete Anfragen lassen sich einer Online-Anzeige zuordnen.

**Verkauft** — Alle Verkäufe mit Käufer, Zahlart und Rechnung; Gesamtumsatz in der Überschrift.

Auch in der **Artikelkarte** (Reiter Shop) lässt sich der Kanal je Artikel wählen; dort stehen Preis, Preisart, Versand, Zustand und Kategorie je Artikel, und ein Knopf führt ins Board.

## Feed für Sync-Werkzeuge

Wer ein Drittwerkzeug nutzt, das Kleinanzeigen-Anzeigen aus einem Feed pflegt, bekommt vom Kanal einen **CSV- oder JSON-Feed** aller Entwürfe und Online-Anzeigen (Titel, Text, Preis, Preisart, Versand, Zustand, Kategorie, Standort, Bestand, Anzeigennummer, Bilder als Adressen). Dazu in der Kanalkarte einen Feed-Schlüssel erzeugen; die Adressen zeigt der Bereich „Kleinanzeigen-Board und Anfragen“.

## Automatik

Der Shop-Läufer (`tools/shop-publish.php --reconcile-payments`, siehe `dev/shop-betrieb.md`) holt bei jedem Lauf die Anfragen aller eingeschalteten Kleinanzeigen-Kanäle aus dem Postfach. Preis-, Text- und Bestandsänderungen braucht niemand anzustoßen: Das Board leitet die Aufgaben live aus dem Stand beim Einstellen ab.

## Technik

- Kanalart `kleinanzeigen` in `sales_channel_shop`; Modul `backend/api/shop/channels/kleinanzeigen.php`, Verwaltung `backend/api/shop/kleinanzeigen.php`
- Stand je Artikel in `parts_channel_shop` (`sync_status` draft/active/reserved/ended, `external_id` Anzeigennummer, `sync_data` Adresse, Zeitpunkte, Stand beim Einstellen)
- Verkäufe in `kleinanzeigen_sale_shop`, Anfragen in `kleinanzeigen_lead_shop`
- SQL: `kleinanzeigen_ad()` (die fertige Anzeige), `kleinanzeigen_suggestions()` (Vorschläge), `kleinanzeigen_board()` (das Board), `kleinanzeigen_plain_text()`
- Feed: `backend/webhook/kleinanzeigen-feed.php`
- Kleinanzeigen-Dienst: `backend/services/kleinanzeigen/kleinanzeigen-worker.py` (Python, nodriver, aiohttp), Anbindung `backend/api/shop/lib/kleinanzeigen_worker.php`, Unit `install/oserp-kleinanzeigen.service`
- Entwurf und Entscheidungen: `dev/shop-kleinanzeigen.md`
