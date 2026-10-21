# Shop: Verkaufskanal Kleinanzeigen

Stand 2026-10-16. Status: **umgesetzt**, gegen `ap_dev` geprüft (Prüflauf
unten), nicht committet. Offen: Übersetzungen in den 19 weiteren Sprachen,
Prüfung der Mail-Erkennung gegen echte Benachrichtigungen von Kleinanzeigen.

Verwandte Dokumente: `dev/shop-verkaufskanaele.md` (Kanäle, Preise),
`dev/shop-mehrere-kanaele.md` (Instanzen je Art), `dev/shop-betrieb.md`
(Läufer), `docs/features/kleinanzeigen.md` (Anleitung).

## Ausgangslage

Kleinanzeigen.de (ehemals eBay Kleinanzeigen) hat **keine Schnittstelle** zum
Einstellen von Anzeigen. Die alte XML-Schnittstelle `api.ebay-kleinanzeigen.de`
ist abgeschaltet; die API der Mobil-App ist inoffiziell, Scraping und
Automatisierung über die Webseite verstoßen gegen die Nutzungsbedingungen und
führen zur Kontosperre. Das gilt auch für die PRO-Konten (seit 2026 modular:
Basic, Power, Premium). Drittwerkzeuge (Kleinanzeigen-Enhanced, Kleinanzeigen-
Agent) pflegen Anzeigen aus Feeds, tun das aber ebenfalls ohne offizielle API.

## Entscheidungen

| Nr. | Entscheidung |
|---|---|
| K1 | **Assistierter Kanal** statt Anbindung: OSERP bereitet die Anzeige vollständig vor, der Benutzer stellt sie mit Kopieren und Einfügen ein und meldet zurück. Keine inoffizielle API, kein Scraping. |
| K2 | Kanalart `kleinanzeigen` als dritte Art in `sales_channel_shop` (Modul `channels/kleinanzeigen.php`), damit Aufschlag, Rundung, Artikelzuordnung, Bilder je Kanal, Trigger und Aufträge unverändert gelten. |
| K3 | **Keine neuen Spalten** an `parts_channel_shop`: Stand in `sync_status` (draft, active, reserved, ended), Anzeigennummer in `external_id`, alles Übrige in `sync_data` (url, online_since, refreshed_at, posted_hash, sold_out) und `settings` (price, price_type, shipping, condition, category). |
| K4 | **Aufgaben werden live abgeleitet** (`kleinanzeigen_board`), nicht über Aufträge: `posted_hash` beim Einstellen gemerkt, Abweichung = aktualisieren; nicht verkäuflich oder ausverkauft = löschen; älter als `refresh_days` = auffrischen. Der Läufer pflegt nur Entwurf/beendet (publish_part/remove_part) und holt Anfragen (import_leads). |
| K5 | Preis in **ganzen Euro brutto** (Kleinanzeigen kennt keine Cent): Kanalpreis gerundet, oder `settings.price` des Artikels. |
| K6 | Verkäufe in eigener Tabelle `kleinanzeigen_sale_shop` (verhindert das Löschen des Kanals wie `ebay_orders`, liefert den Umsatz, erscheint in den Bestellungen über `ar_id`). Rechnung brutto über `createFakturaCore`, Buchung `postArInvoiceToLedger`, Lager `shopBookStock`. Die **Zahlung** bucht Kasse oder Bank — nur die Zahlart wird gemerkt. |
| K7 | Anfragen aus den **Benachrichtigungs-Mails** (Postfach des E-Mail-Moduls, IMAP mit PEEK), Tabelle `kleinanzeigen_lead_shop`, je Mail einmal (`mail_uid` = Ordner:UID). Geantwortet wird bei Kleinanzeigen; OSERP hält Stand und Antwortvorschlag. |
| K8 | Der Mail-Parser arbeitet **tolerant**: Anzeigennummer aus dem Link, Absender aus dem Betreff, Zuordnung über Nummer oder Titel; was sich nicht zuordnen lässt, bleibt als Anfrage ohne Artikel sichtbar. |
| K9 | **Feed** (CSV/JSON) für Drittwerkzeuge unter `backend/webhook/kleinanzeigen-feed.php`, geschützt durch `feed_key` (Geheimnis des Kanals). Nur Entwürfe und Online-Anzeigen, Bilder als absolute Adressen über `part-image.php`. |
| K10 | Grundausstattung: einmal je Mandant ein abgeschalteter Kanal „Kleinanzeigen“ (Merker `shop_kleinanzeigen_seeded`), damit er in bestehenden Datenbanken erscheint und ein gelöschter nicht wiederkommt. |
| K11 | KI-Assistent `kleinanzeigen` (`kleinanzeigen_ai_model`, Vorgabe Haiku 4.5) für Anzeigentexte und Antwortvorschläge; am Dialog umschaltbar. Antwort als JSON (`title`, `text`), bei Abweichung erste Zeile = Titel. |

## Bausteine

| Teil | Ort |
|---|---|
| Schema: Typprüfung, Vorgaben, Einstellungsschlüssel, Grundausstattung | `backend/upstall/shop/company_schema.sql` (Abschnitte bei `sales_channel_shop`, `shop_channel_default_settings`, `shop_channel_setting_keys`) |
| Tabellen `kleinanzeigen_sale_shop`, `kleinanzeigen_lead_shop`; Funktionen `kleinanzeigen_plain_text`, `kleinanzeigen_ad`, `kleinanzeigen_suggestions`, `kleinanzeigen_board` | Abschnitt „Verkaufskanal Kleinanzeigen“ am Ende der Schemadatei |
| Kanalmodul (Aufträge, Anfragen-Import, Mail-Parser) | `backend/api/shop/channels/kleinanzeigen.php` |
| Verwaltung (Board, Anzeige, Stand, Verkauf, Anfragen, KI, ZIP, Feed-Adresse) | `backend/api/shop/kleinanzeigen.php` |
| Feed | `backend/webhook/kleinanzeigen-feed.php` |
| Board | `src/features/shop/views/shop.kleinanzeigen.vue`, Route `shop-kleinanzeigen` |
| Anzeige-Dialog, Verkaufs-Dialog, Kanalkarten-Panel | `src/features/shop/components/kleinanzeigen-ad.dialog.vue`, `kleinanzeigen-sell.dialog.vue`, `shop-kleinanzeigen-status.vue` |
| Kanaleinstellungen, Artikelkarte | `shopChannelSettingsConfig.js` (Block `kleinanzeigen`), `part-shop.card.vue` (`KANAL_FELDER.kleinanzeigen`) |
| Registrierung | `channels/channels.php` (`SHOP_CHANNEL_TYPES`), `lib/channel_images.php` (`shopChannelParam`), `admin.php` (Löschhindernis, Bestellungen), `tools/shop-publish.php` (import_leads), `lib/ai_model.php`, `aiModels.js` |

## Einstellungen des Kanals (`sales_channel_shop.settings`)

| Schlüssel | Bedeutung |
|---|---|
| `account_email` | E-Mail des Kleinanzeigen-Kontos (Information) |
| `location_zip`, `location_city` | Standort der Anzeigen (Pflicht bei Kleinanzeigen; fehlt er, nennt `kleinanzeigen_ad.missing` ihn) |
| `price_type` | fixed, negotiable, giveaway |
| `shipping` | pickup, shipping, both |
| `default_condition` | new, like_new, very_good, good, ok, defective |
| `default_category` | Gedächtnisstütze, wird nicht übertragen |
| `text_style` | sachlich, werblich (KI) |
| `text_footer` | unter jedem Anzeigentext |
| `refresh_days` | Frist fürs Auffrischen (Vorgabe 30) |
| `lead_mail_folder` | IMAP-Ordner der Benachrichtigungen (INBOX) |
| `lead_last_check` | schreibt der Import |
| `default_customer_id` | Barverkaufskunde |
| `employee_login` | Mitarbeiter der Rechnungen; leer = angemeldeter Benutzer, sonst der erste |
| `feed_key` (Geheimnis) | schützt den Feed |

## Ablauf je Anzeige

```
Vorschlag ──Anbieten──▶ Entwurf (draft) ──„ist online“ + Adresse──▶ Online (active)
                                                                   │  ├─ posted_hash ≠ hash      → todo update   → „Aktualisiert“
                                                                   │  ├─ nicht verkäuflich/sold_out → todo delete → „Gelöscht“ → Entwurf (angeboten) / beendet (abgewählt)
                                                                   │  └─ älter als refresh_days  → todo refresh  → „Aufgefrischt“
                                                                   ├─ „Reserviert“ ⇄ „Wieder frei“
                                                                   └─ „Verkauft“ → Rechnung, Lager, kleinanzeigen_sale_shop; Bestand weg → sold_out
```

Entwurf verwerfen: `state = removed` (Kanalzeile aus, beendet) — wie das
Abwählen in der Artikelkarte.

## Schreiben und lesen in einer Abfrage

Die Hauptabfrage sieht die Änderung einer schreibenden CTE nicht (PostgreSQL
liest den Schnappschuss vor der CTE). `saveKleinanzeigenAd` und
`setKleinanzeigenAdState` schreiben deshalb erst und lesen die Anzeige in
einer zweiten Abfrage (`shopKleinanzeigenAdRow`). Ein `jsonb_build_object`
mit nacktem Parameter braucht `CAST(:x AS text)` — sonst „could not determine
data type“.

## Prüflauf (2026-10-16, ap_dev)

Skript im Scratchpad (`ka-test.php`), Kommandozeile ohne Sitzung
(`permit()` als Stub), 5 Testartikel mit Bestand 3, zwei mit GD-Bild:

- Vorschläge mit Kapitalbindung, Liegezeit und Bildkennzeichen
- Anbieten → drei Entwürfe, fehlende Angaben (`image`) erkannt
- Anzeige speichern: eigener Preis, VB, Fußtext im Text, Hash
- Online melden mit Kleinanzeigen-Adresse → Nummer 2345678901, Stand active
- Preisänderung → todo update → „Aktualisiert“ → nichts zu tun;
  40 Tage zurück → todo refresh
- Verkauf mit Rechnung: brutto 45, netto über Steuersatz, gebucht, Käufer neu;
  zweiter Verkauf erkennt den Käufer über die E-Mail; Verkauf ohne Rechnung;
  ausverkauft → todo delete → „Gelöscht“ → Entwurf ohne Nummer
- Bestellungen zeigen die Kleinanzeigen-Rechnung; Löschhindernis zählt 3 Verkäufe
- ZIP mit einem Bild; Verwerfen; Trigger legt publish_part an, der Läufer
  setzt Entwurf; Mail-Parser erkennt Absender, Nummer, Nachricht und
  ignoriert Newsletter; Anfrage beantwortet; ohne Feed-Schlüssel keine Adresse

## Teil 2 (2026-10-17): Kleinanzeigen-Dienst — Pseudo-API über einen Headless-Browser

Der Nutzer wollte keine Kopierschritte: „maximal den Login bestätigen, der
Rest muss automatisch laufen“. Da Kleinanzeigen keine API hat, gibt es jetzt
einen eigenen Dienst, der einen echten Chrome steuert.

| Nr. | Entscheidung |
|---|---|
| K12 | **Eigener Dienst** `backend/services/kleinanzeigen/kleinanzeigen-worker.py` (Python 3.12, `nodriver` 0.50.6 über das DevTools-Protokoll ohne WebDriver-Spuren, `aiohttp`), lokal auf 127.0.0.1:3007, Shared-Secret `KA_TOKEN`. Muster wie der Whisper-Dienst (venv, systemd-Unit `install/oserp-kleinanzeigen.service`). |
| K13 | **Keine App-API, kein Scraping fremder Konten**: Der Dienst arbeitet nur mit dem eigenen Konto des Kanals über die normale Webseite — derselbe Weg wie kleinanzeigen-bot (AGPL, als Referenz für Abläufe und Selektoren gelesen, kein Code übernommen). |
| K14 | **Ein Browserprofil je Kanal** (`~/.local/share/oserp-kleinanzeigen/<db>-<kanal>`), Sitzung bleibt erhalten; Browser schließt nach Leerlauf und startet beim nächsten Aufruf. Je Profil ein Lock — Aufrufe laufen nacheinander. |
| K15 | **Login-Code aus dem Postfach**: antwortet der Dienst `code_required (email)`, sucht OSERP bis zu zwei Minuten die Mail von Kleinanzeigen mit sechsstelligem Code (IMAP, Ordner `lead_mail_folder`) und reicht ihn nach. SMS-Code fragt die Kanalkarte ab. Captcha: Dienst einmal sichtbar starten (`KA_HEADLESS=0`). |
| K16 | **Zwei Betriebsarten je Mandant** (`shopKleinanzeigenAutomatic`): Dienst eingeschaltet (`kleinanzeigen_worker_enabled`) und `login_email` im Kanal → automatisch; sonst von Hand wie in Teil 1. Dieselben Kanalzeilen, dasselbe Board. |
| K17 | **Aufträge**: automatisch stellen `publish_part`/`remove_part` über den Dienst ein bzw. löschen; `sync_ads` gleicht mit `m-meine-anzeigen-verwalten.json` ab; `import_leads` holt die Unterhaltungen (Rückfall IMAP). Der Cron (`--reconcile-payments`) legt `sync_ads` und `import_leads` an. Fehler stehen an der Kanalzeile (`sync_error`), der Auftrag gilt als fehlgeschlagen. |
| K18 | **Einstellen ab Knopfdruck**: Aufnehmen aus den Vorschlägen erzeugt einen Entwurf; eingestellt wird erst über „Jetzt bei Kleinanzeigen einstellen“ (Board/Anzeige) — damit Titel, Preis und Bilder vorher geprüft sind. Danach halten die Aufträge die Anzeige nach. |
| K19 | **Kostenloses Basis-Paket nur**: Verlangt die Paketwahl Geld oder erscheint ein Zahlungsformular, bricht der Dienst ab (`PACKAGE`, `PAYMENT_REQUIRED`). |
| K20 | **Kategorie** als Beschriftungspfad („Auto, Rad & Boot > Autoteile & Reifen“) für den Dialog der Webseite oder als Zahlenpfad („210/223“) für die alte Auswahl; leer = automatische Erkennung von Kleinanzeigen. |
| K21 | **Nachrichten** sind best effort: React-Seite ohne dokumentierte API; der Dienst liest `data-initialprops`/JSON-Skripte und Links, antwortet über das Textfeld. `/debug/page` liefert die Rohdaten zum Nachziehen. |

Bausteine: `backend/services/kleinanzeigen/` (Dienst, README, install.sh,
requirements.txt), `backend/api/shop/lib/kleinanzeigen_worker.php`
(HTTP-Client, Nutzdaten aus `kleinanzeigen_ad()`, Publish/Delete/Sync/Leads,
Login-Code aus dem Postfach), API in `backend/api/shop/kleinanzeigen.php`
(`getKleinanzeigenWorkerStatus`, `loginKleinanzeigen`, `confirmKleinanzeigenLogin`,
`publishKleinanzeigenAd`, `deleteKleinanzeigenAd`, `extendKleinanzeigenAd`,
`syncKleinanzeigenAds`, `replyKleinanzeigenLead`), Einstellungen
`kleinanzeigen_worker_enabled/url/token` (Shop-Tab) und `login_email`,
`login_password` (Geheimnis), `contact_name` je Kanal; Oberfläche: Kanalkarte
(Status, Anmelden, Code, Abgleich), Board und Anzeige-Dialog mit den
Sofort-Knöpfen, Antwort aus dem Board.

**Prüfstand 2026-10-17:** Die Umgebung dieser Sitzung ließ keine Aufrufe gegen
kleinanzeigen.de und keine Testläufe des Dienstes zu (Sandbox-Regeln). Der
Dienst wurde gegen die installierte nodriver-API geschrieben, aber **noch
nicht gegen die echte Webseite ausgeführt**. Erster Lauf: Dienst sichtbar
starten, in der Kanalkarte anmelden, einen Testartikel einstellen, danach
`/debug/page` für die Nachrichtenseite auswerten und die Selektoren in
`do_messages_list`/`do_messages_read`/`do_messages_reply` nachziehen.

## Offen

- Erster Lauf gegen die echte Webseite (siehe Prüfstand), Captcha-Verhalten
  im Headless-Betrieb, Selektoren der Nachrichtenseite
- Übersetzungen (19 Sprachen) für `ShopView.kleinanzeigen.*`,
  `crm_fields.kleinanzeigen*`, `aiModels.assistants.kleinanzeigen`
- Mail-Parser gegen echte Benachrichtigungen prüfen (Betreff- und
  Link-Muster von Kleinanzeigen können sich ändern); unzugeordnete Anfragen
  bleiben sichtbar und lassen sich von Hand zuordnen
- Zahlung bei Barverkauf direkt buchen (heute über Kasse/Bank)
- „Sicher bezahlen“-Auszahlungen von Kleinanzeigen im Bankmodul zuordnen
  (wie die Kartenabrechnungen, `dev/`-Projekt Settlements)
- Kleinanzeigen-Kategorienbaum als Auswahlliste statt Freitext
