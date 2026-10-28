---
title: eBay
summary: Verkaufskanal eBay — bestehende Angebote übernehmen, Käufer ohne Dubletten erkennen, unklare kurz prüfen, Rechnungen automatisch, Versand melden, Produktseiten mit KI
group: extension
extension: shop
category: Verkauf
order: 205
status: beta
since: 2026-10-26
external: true
---

# eBay — Verkaufskanal mit Käuferprüfung und KI-Produktseiten

Der Kanal **eBay** verbindet OpensourceERP mit einem eBay-Verkäuferkonto. Er ist für Betriebe gebaut, die **schon viel bei eBay verkaufen**: Die laufenden Angebote werden übernommen statt neu eingestellt, jeder Verkauf wird zur Rechnung — und nur dort, wo der Käufer nicht eindeutig ist, schaut ein Mensch kurz drüber.

Der Kanal gehört zur Erweiterung **Shop**. Im Shop-Menü erscheint das **eBay-Board**; Zugang und Vorgaben stehen unter **Shop → Verkaufskanäle**. Für Tests lässt sich jeder Kanal auf die **eBay-Sandbox** schalten.

## Einrichten — Schritt für Schritt

Erst in der **Sandbox** testen, dann auf das echte Konto umstellen. Die Schritte 1–3 macht man einmal, Schritt 4–9 je Kanal.

### 1. OpensourceERP vorbereiten

- **Schema aktualisieren**: Der Kanal braucht neue Tabellen und Spalten. Das Update läuft beim nächsten Anmelden in der Firma von selbst, oder auf dem Server: `sudo -u www-data php tools/oserp-upstall.php`.
- **Oberfläche bauen** (wo `dist/` live ausgeliefert wird, ist das der Deploy). Danach steht im Shop-Menü **eBay**.
- **Firmenkonfiguration → Shop**:
  - **Öffentliche Adresse für Bilder** (`ebay_public_host`): die Adresse, unter der der Server aus dem Internet per https erreichbar ist, etwa `erp.autoprofis24.de`. Darüber holt eBay die Bilder, und darunter liegt die Rücksprungadresse der Anmeldung.
  - **Lagerplatz für Verkäufe**: von hier bucht OSERP verkaufte Ware aus.
  - **Anthropic-API-Schlüssel** (Firmenkonfiguration → KI) für die Produktseiten mit KI.
- **Cronjob**: Der Abruf `backend/cli/ebay-orders.php` steht nach der Installation in `/etc/cron.d/oserp` (stündlich, Minute 7). Wer Verkäufe schneller abgerechnet haben will, stellt ihn auf alle 15 Minuten.

> Zum Testen mit der Sandbox einen **Testmandanten** nehmen (etwa die Kopie ap_dev): Auch Sandbox-Bestellungen werden zu echten Rechnungen in der Datenbank, in der der Kanal steht.

### 2. eBay-Entwicklerkonto und Schlüssel

1. Unter [developer.ebay.com](https://developer.ebay.com) ein Entwicklerkonto anlegen (kostenlos) und anmelden.
2. **Application Keys** → einen Schlüsselsatz für **Sandbox** erzeugen (später einen für **Production**). Gebraucht werden:
   - **App ID (Client ID)** → in OSERP „Client-ID“
   - **Cert ID (Client Secret)** → in OSERP „Client-Secret“
3. Für die Sandbox unter **Sandbox → User Registration** einen Testverkäufer anlegen — mit ihm meldet man sich in Schritt 6 an. Testangebote und Testkäufe entstehen auf [sandbox.ebay.com](https://sandbox.ebay.com).
4. Beim Produktions-Schlüsselsatz verlangt eBay, dass man sich für die Benachrichtigung über gelöschte eBay-Konten („Marketplace Account Deletion“) anmeldet oder eine Befreiung beantragt. Einen Empfänger dafür hat OSERP noch nicht — bis dahin die Befreiung beantragen.

### 3. Rücksprungadresse (RuName) bei eBay

1. Im Entwicklerportal **User Tokens** → **Get a Token from eBay via Your Application** → **Add eBay Redirect URL**.
2. Ausfüllen:
   - **Your auth accepted URL**: `https://<öffentliche Adresse>/webhook/ebay-oauth.php` — die genaue Adresse zeigt das eBay-Board mit Kopierknopf
   - **Your auth declined URL**: dieselbe Adresse
   - **Your privacy policy URL**: die Datenschutzseite der Firmenwebseite
   - **OAuth** auswählen (nicht „Auth'n'Auth“)
3. Speichern. eBay vergibt den **RuName** (eine lange Kennung wie `Firma-Firma-OSERP-abcde`) → in OSERP „RuName (Rücksprung)“.

Für Sandbox und Produktion gibt es je einen eigenen RuName.

### 4. Kanal anlegen

**Shop → Verkaufskanäle** → Kanal der Art **eBay** anlegen (oder den vorhandenen nehmen) und in der Kanalkarte unter **eBay-Zugang** eintragen:

| Feld | Wert |
|------|------|
| Umgebung | **Sandbox** zum Testen, später **Produktion** |
| Marktplatz | `EBAY_DE` |
| Client-ID, Client-Secret, RuName | aus Schritt 2 und 3 |

Den Kanal **einschalten**. Das stellt noch nichts bei eBay ein — im Kanal stehen noch keine Artikel.

### 5. Verkäufe und Angebote einstellen

In der Kanalkarte unter **Verkäufe und Angebote**:

- **Eindeutige Käufer automatisch abrechnen**: an lassen. Aus = jeder Verkauf wartet auf Bestätigung.
- **Rechnungen ab**: bleibt leer — beim Verbinden setzt OSERP „jetzt“. Ältere Verkäufe sind schon abgerechnet und bekommen keine Rechnung.
- **Übernommene Angebote abgleichen**: zunächst **Nichts ändern**. Erst umstellen, wenn der Lagerbestand in OSERP stimmt (siehe Schritt 8).

Unter **eBay-Zugang** außerdem:

- **Sammelartikel**: ein Artikel für Verkäufe, die keinem Artikel zugeordnet sind (sonst warten sie in der Prüfung, bis das Angebot zugeordnet ist) und für Versandkosten, wenn keine Versandart eingerichtet ist.
- **Mitarbeiter**: Login, unter dem die Rechnungen entstehen.

Unter **Produktseiten** Vorlage, Farbe und die Fußtexte Versand, Rückgabe, Über uns.

### 6. Mit eBay verbinden

**Shop → eBay** → **Mit eBay verbinden**. Es öffnet sich ein kleines eBay-Fenster: mit dem eBay-Verkäuferkonto anmelden (in der Sandbox mit dem Testverkäufer) und zustimmen. Das Fenster schließt sich, im Kopf steht der Kontoname. Token kopiert man nicht — OSERP holt und erneuert sie selbst.

Gleich danach liest OSERP alle laufenden Angebote (Reiter **Angebote**). Blockiert der Browser das Fenster, öffnet sich eBay im selben Tab; danach das eBay-Board wieder öffnen.

### 7. Angebote zuordnen

Reiter **Angebote**, Filter **Zu tun**:

- Was über SKU, EAN oder Herstellernummer passt, ist schon zugeordnet.
- **Mit Vorschlag**: mit ✓ übernehmen oder ✗ ablehnen; mehrere auswählen → **Vorschläge übernehmen**.
- **Ohne Artikel**: in der Zeile einen Artikel suchen, oder auswählen → **Als Artikel anlegen** (auf Wunsch mit der eBay-Menge als Lagerbestand).

Wichtig dafür, dass Verkäufe die richtigen Artikel bekommen: jedes Angebot, das sich verkauft, sollte zugeordnet sein.

### 8. Bestand abgleichen (wenn der Lagerbestand stimmt)

Filter **Überverkauf** zeigt Angebote, bei denen eBay mehr anbietet als im Lager liegt. Stimmt der Bestand in OSERP, in der Kanalkarte **Übernommene Angebote abgleichen** auf **Menge = Lagerbestand** (oder mit Preis) stellen. Ab dann senkt jeder Verkauf — eBay, Laden, Webshop — die Menge bei eBay.

Im eBay-Konto unter den Verkaufseinstellungen **„Nicht vorrätige Artikel weiter anzeigen“** (Out-of-stock control) einschalten: Sonst beendet eBay ein Angebot bei Menge 0, statt es als ausverkauft stehen zu lassen. Das Board weist darauf hin.

### 9. Im Alltag

- **Prüfen**: Verkäufe mit unklarem Käufer — Kandidaten ansehen, wählen, Enter. Die Rechnung entsteht sofort.
- **Verkäufe**: Stand jeder Bestellung, Rechnung, **Versand melden** (bei DHL-Etikett an der Rechnung meldet OSERP von selbst).
- **Im Kanal**: Produktseiten gestalten, **Lohnt sich bei eBay** für neue Angebote.
- **Jetzt abgleichen** im Kopf holt Verkäufe und Angebote sofort, statt auf den Cron zu warten.

### Von der Sandbox ins echte Konto

Produktions-Schlüsselsatz und Produktions-RuName anlegen (Schritt 2 und 3), in der Kanalkarte **Umgebung = Produktion** und die neuen Werte eintragen, dann erneut **Mit eBay verbinden**. Am besten einen eigenen Kanal für das echte Konto anlegen und den Sandbox-Kanal abschalten — so mischen sich Test- und echte Angebote nicht.

### Nur für neue Angebote aus OSERP

Übernehmen, Abrechnen, Versand und Produktseiten übernommener Angebote brauchen nichts weiter. Wer Artikel **neu aus OSERP** bei eBay einstellen will (Inventory-API), trägt zusätzlich in der Kanalkarte ein: Kategorie (eBay-Kategorienummer), Zustand, die Kennungen der **Geschäftsrichtlinien** für Zahlung, Rücknahme und Versand (Seller Hub → Konto → Geschäftsrichtlinien, die Nummer steht in der Adresse der Richtlinie) und den **Lagerort-Schlüssel** (merchantLocationKey). Den Lagerort legt eBay nur über die Schnittstelle an — eine Schaltfläche dafür hat OSERP noch nicht.

## Mit eBay verbinden — was dahinter passiert

Die Anmeldung läuft über OAuth: eBay schickt nach der Zustimmung einen Code an die Rücksprungadresse, die Seite reicht ihn an das OSERP-Fenster weiter, und OSERP tauscht ihn mit dem Client-Secret gegen ein Refresh-Token (gilt 18 Monate) und kurzlebige Zugriffstoken. Danach gilt der Kanal als verbunden; „Mit eBay verbinden“ lässt sich jederzeit wiederholen.

## Prüfen — nur, was unklar ist

Jeder Verkauf läuft durch einen **Käuferabgleich** gegen den Kundenstamm. Er vergibt Punkte und nennt die Gründe:

| Merkmal | Punkte |
|---------|--------|
| eBay-Käufer schon bekannt (frühere Bestellung, auch eine frühere Entscheidung) | 60 |
| gleiche E-Mail | 50 |
| gleiche Telefonnummer | 40 |
| Name gleich / ähnlich | 40 / 25 |
| Straße gleich / ähnlich (bei gleicher Hausnummer) | 30 / 15 |
| PLZ gleich | 15 |

Namen werden normalisiert verglichen („Müller, Hans“ = „Hans Mueller“), Straßen ebenso („Karl-Frank-Str. 30“ = „Karl Frank Straße 30“). Ist der beste Kunde **sicher** (≥ 85 Punkte, kein zweiter in Reichweite) oder gibt es **sicher keinen** (< 40), legt der Abruf Kunde und Rechnung gleich an. Alles dazwischen landet in **Prüfen**:

- Links der Käufer, wie eBay ihn meldet (Name, Anschrift, eBay-Name, E-Mail, Telefon, abweichende Lieferanschrift)
- Rechts die möglichen Kunden mit Punktebalken, farbig markierten Übereinstimmungen und Gründen als Chips; „Neuer Kunde“ und eine Suche nach einem anderen Kunden
- **Umgezogen?** Erkennt OSERP einen Stammkunden mit neuer Rechnungsanschrift, schlägt es vor, die Anschrift im Kundenstamm zu ändern
- Ein Klick (oder **Enter**) legt die Rechnung an; Tasten **1–5** wählen den Kandidaten, **N** einen neuen Kunden, **I** stellt zurück — die nächste Karte hat sofort den Fokus
- **Alle Vorschläge übernehmen** rechnet alle offenen Fälle mit dem jeweils besten Kandidaten ab
- Entscheidungen werden gelernt: Der nächste Kauf desselben eBay-Käufers ist eindeutig

Positionen ohne Artikel (Angebot noch keinem Artikel zugeordnet, kein Sammelartikel) halten die Rechnung an; die Karte zeigt das und führt mit **Artikel zuordnen** direkt zum Angebot.

## Rechnung

- Brutto wie bei eBay, eine Position je Kaufposition (Menge × Los), Versandkosten als eigene Position (Sammelartikel des Kanals oder Versandartikel der ersten Versandart)
- Lieferanschrift der Bestellung an der Rechnung, eBay-Bestellnummer als Bestellnummer des Kunden
- Buchung ins Hauptbuch, Nettobetrag aus den Erlöszeilen, Lager vom Shop-Lagerplatz ausgebucht
- Nicht bezahlte Bestellungen warten (**Wartet auf Zahlung**) und werden abgerechnet, sobald eBay die Zahlung meldet; stornierte oder erstattete bekommen keine Rechnung. Wird eine schon abgerechnete Bestellung storniert, zeigt das Board **Gutschrift nötig**
- Abgerufen wird nach Änderungsdatum: Zahlung, Storno und Erstattung kommen von selbst an

## Versand melden

Hat die Rechnung ein **DHL-Etikett**, meldet OSERP Sendungsnummer und Versanddienst beim nächsten Abruf an eBay — die Bestellung steht dort auf „Versendet“, der Käufer bekommt die Sendungsverfolgung. Andere Versanddienste (Deutsche Post, Hermes, DPD, GLS, UPS …) trägt man im Reiter **Verkäufe** mit **Versand melden** ein.

## Angebote — was schon bei eBay steht

Der Reiter **Angebote** zeigt alles, was im eBay-Konto läuft — auch im Seller Hub eingestellte Angebote und Varianten. OSERP ordnet sie den Artikeln zu:

- **automatisch** über SKU = Artikelnummer, EAN oder Herstellernummer = Artikelnummer
- **als Vorschlag** über einen ähnlichen Titel — mit ✓ übernehmen oder mit ✗ ablehnen (wird nicht wieder vorgeschlagen), auch gesammelt
- **von Hand** über die Artikelsuche in der Zeile
- **Als Artikel anlegen**: Ware, die es bisher nur bei eBay gab, wird Artikel — Nummer = SKU, Bezeichnung, Preis, EAN, Hauptbild, auf Wunsch die eBay-Menge als Lagerbestand

Filter zeigen, was zu tun ist: ohne Artikel, mit Vorschlag, **Überverkauf** (bei eBay mehr verfügbar als im Lager), Menge oder Preis weichen ab, beendet.

**Abgleich** zugeordneter Angebote, je Kanal einstellbar: nichts ändern (Vorgabe — erst einschalten, wenn der Bestand in OSERP stimmt), Menge = Lagerbestand, oder Menge und Preis. Ein Verkauf im Laden oder im Webshop senkt damit sofort die Menge bei eBay. Beenden und Abwählen in OSERP beenden ein übernommenes Angebot nie — es wird nur nicht mehr abgeglichen.

## Produktseiten mit KI

Für jeden Artikel im Kanal gibt es einen **Seiten-Editor** mit Live-Vorschau (Rechner und Telefon):

- **Mit KI entwerfen**: Claude schreibt eBay-Titel (80 Zeichen, Suchbegriffe zuerst) mit zwei Alternativen, Überschrift, Unterzeile, Stichpunkte, Beschreibung, technische Daten, eBay-Artikelmerkmale (Marke, Herstellernummer, Produktart …), Passform, Lieferumfang und Hinweise — aus Artikelstamm, Shop-Angaben und dem bestehenden eBay-Angebot, mit Tonfall (sachlich, werblich, technisch) und einem freien Hinweis. Erfundene Angaben sind ausgeschlossen.
- **Vorlagen** Klar, Modern, Kompakt; Akzentfarbe; Bilder in der Beschreibung
- Fuß jeder Seite aus der Kanalkarte: Versand, Rückgabe, Über uns
- eBay-taugliches HTML: nur Inline-Stile, keine Skripte, auf dem Telefon lesbar; Überschriften in der Sprache des Marktplatzes
- Speichern ändert das Angebot beim nächsten Lauf (übernommene Angebote: Titel, Beschreibung und Merkmale per Revise); ein Artikel, der noch nicht bei eBay steht, wird mit **Speichern und bei eBay einstellen** aufgenommen

Der Assistent heißt **eBay** und hat eine eigene Modellwahl (Vorgabe Claude Sonnet) in den KI-Einstellungen; am Prompt lässt sie sich für die Sitzung umschalten.

## Im Kanal und Vorschläge

**Im Kanal** listet die eingestellten und übernommenen Artikel mit Stand des Abgleichs, Fehler und Produktseite. Darunter **Lohnt sich bei eBay**: Ware mit Bestand, die Kapital bindet und noch nicht bei eBay steht — auswählen und anbieten.

## Automatik

Der Cronjob `backend/cli/ebay-orders.php` (`/etc/cron.d/oserp`, stündlich) holt die geänderten Bestellungen jedes eingeschalteten eBay-Kanals, rechnet eindeutige ab, meldet Versand und liest alle sechs Stunden die Angebote neu.

## Einstellungen (Kanalkarte)

| Gruppe | Felder |
|--------|--------|
| eBay-Zugang | Umgebung (Produktion/Sandbox), Marktplatz, Client-ID, Client-Secret, RuName, Refresh-Token (setzt „Mit eBay verbinden“), Kategorie, Zustand, Richtlinien, Lagerort, Sammelartikel, Mitarbeiter |
| Verkäufe und Angebote | eindeutige Käufer automatisch abrechnen, Rechnungen ab, Abgleich übernommener Angebote, Buchungsgruppe für neue Artikel |
| Produktseiten | Vorlage, Akzentfarbe, Texte Versand / Rückgabe / Über uns |

Technik: `dev/shop-ebay.md`.
