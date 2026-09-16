# Shop: Betrieb

Was im Alltag mit der Erweiterung `shop` zu tun ist und wo es steht. Wie die
Teile entstanden sind, beschreiben `shop-migration.md` (Fachlogik),
`shop-veroeffentlichung.md` (Produktseiten) und `shop-bridge-abloesung.md`
(Ablösung der Kivitendo-Bridge); den Widerruf `shop-widerruf.md`.

## Die Teile

| Teil | Ort | Aufgabe |
| --- | --- | --- |
| Admin-Panel | `src/features/shop/` | Mitarbeiter: Übersicht, Bestellungen, Zahlungen, Widerrufe, Veröffentlichung |
| Mitarbeiter-API | `backend/api/shop/` | normale OpensourceERP-Sitzung, Rechte über `permit()` |
| Öffentlicher Zugang | `backend/shop/index.php` | Kunden des Betreibers; Ausweis über den Kopf `X-Shop-Key`, zugelassene Aktionen in `public/actions.php` |
| Fachschicht | `backend/api/shop/lib/` | Warenkorb, Konto, Suche, Rechnung, Zahlung, Mail, Widerruf, Umleitungen, Veröffentlichung, Kategorien |
| Webseiten-Paket | `backend/templates-default/shop/standard/kit/` | Shortcodes, Partials, Proxy, 404-Seite, Widget-Bundle |
| Shop-UI | `shop-ui/` | Widgets der Käuferoberfläche (Lit), eigener Build ins Paket |
| Läufer | `tools/shop-publish.php` | Aufträge abarbeiten, Paket abgleichen, Kategorien, Webseite bauen |
| Übernahme | `tools/shop-bridge-settings.php` | Einstellungen einer Bridge-Instanz als SQL ausgeben |

Die Webseite selbst — Hugo-Projekt, Theme, Inhalte — gehört dem Betreiber.
OpensourceERP schreibt dort nur in das Verzeichnis der Produktseiten und nach
`oserp-shop/`.

## Eine Webseite einrichten

Der Ablauf steht ausführlich in `shop-bridge-abloesung.md` unter Stufe D. Kurz:

1. Erweiterung `shop` beim Mandanten aktivieren, Schema-Update laufen lassen.
2. `settings.ini`: `shop_sites_dir` (Verzeichnis über den Webseiten) und
   `shop_publish_command` (Bau-Befehl, etwa der Hugo-Aufruf).
3. Einstellungen im Admin-Panel: Shop-Schlüssel, `shop_backend_url`, Pfade,
   Vorlagensatz. Die Shop-Übersicht zeigt, was noch fehlt.
4. `php tools/shop-publish.php --client=<id>` einmal von Hand — legt
   `<webseite>/oserp-shop/` samt `config.php` an.
5. In der `config.json` der Instanz die Mounts auf `oserp-shop/` setzen.
6. Cron-Einträge für Läufer und Zahlungsabgleich.

## Der Läufer

```
php tools/shop-publish.php [--client=<id>] [--db=<name>] [--limit=500]
                           [--no-build] [--quiet] [--reconcile-payments]
php tools/shop-publish.php --list-clients
```

Ein Lauf tut der Reihe nach:

1. **Aufträge** aus `batchjob_hugoshop` abarbeiten, älteste zuerst.
2. **Paket abgleichen** — bei jedem Lauf; er vergleicht Prüfsummen und kopiert
   nur Geändertes nach `<webseite>/oserp-shop/`.
3. **Kategorieübersicht** erneuern, wenn Seiten geschrieben oder entfernt
   wurden.
4. **Bauen**, wenn sich etwas geändert hat und `shop_publish_command` gesetzt
   ist.

Je Mandant läuft nur einer: eine Sperrdatei in `backend/tmp/`. Ein zweiter Lauf
meldet das und endet ohne Fehler. Der Rückgabewert ist 1, sobald ein Auftrag
fehlgeschlagen ist — so meldet sich der Cron. Beispiel:

```
*/5 * * * *  php /pfad/tools/shop-publish.php --client=1 --quiet
17 * * * *   php /pfad/tools/shop-publish.php --client=1 --quiet --reconcile-payments
```

## Aufträge

Die Anwendung schreibt nur Aufträge; geschrieben und gebaut wird auf der
Kommandozeile. Sie stehen in `batchjob_hugoshop`, offen ist, was kein Ergebnis
hat. Die Shop-Übersicht zeigt die offenen und die zuletzt erledigten.

| Auftrag | Angelegt von | Wirkung |
| --- | --- | --- |
| `publish_part` | Artikelkarte „Veröffentlichen" | Eine Produktseite schreiben |
| `publish_all` | Shop-Übersicht | Alle Seiten der Artikel im Shop |
| `remove_part` | Artikel aus dem Shop nehmen | Inhaltsdatei entfernen |
| `sync_kit` | von Hand | Paket abgleichen |
| `reconcile_payments` | Läufer mit `--reconcile-payments` | Schwebende PayPal-Zahlungen nachfragen |

Ein Ergebnis beginnt mit `ok:` oder `Fehler:`; Fehler stehen rot in der Liste.

## Vorlagensätze

Gesucht wird wie beim Druck: erst `<templates_dir>/shop/<name>/`, dann
`backend/templates-default/shop/<name>/`. Gewählt wird über
`shop_template_set` — nur ein Name, kein Pfad.

Was ein Satz nicht mitbringt, kommt aus `standard`: Vorlagen (`product.md.php`),
die Regeln der Kategorieübersicht (`category_groups.php`) und jede Datei des
Pakets (`kit/`). Ein eigener Satz besteht deshalb nur aus dem, was er ändert;
`theme.json` gehört immer dazu und nennt unter `data.category_groups` die
Datendatei der Kategorieübersicht.

## Einstellungen

40 Zeilen in `defaults_oserp`, zu ändern unter Einstellungen → Erweiterungen →
Shop. Fehlt eine nötige, nennt die Fehlermeldung den Feldnamen aus der
Oberfläche, nicht den Schlüssel.

| Gruppe | Einstellungen |
| --- | --- |
| Zugang | `shop_public_key`, `shop_allowed_origins`, `shop_backend_url` |
| Sitzung | `shop_cart_lifetime_hours`, `shop_context_lifetime_hours` |
| Verkauf | `shop_contact_login`, `shop_target_account`, `shop_incoming_account`, `shop_standard_taxzone`, `shop_standard_currency`, `shop_tax_included`, `shop_active_price_source`, `shop_shipping_partnumber`, `shop_free_shipping_from` |
| Zahlung | Bankverbindung (`shop_payment_*`), PayPal (`shop_paypal_*`) |
| Adressen der Webseite | `shop_base_url`, `shop_products_link`, `shop_category_link`, `shop_images_link`, `shop_thumbnails_link`, `shop_downloads_link` |
| Veröffentlichung | `shop_template_set`, `shop_site_dir`, `shop_content_dir`, `shop_images_dir`, `shop_thumbnails_dir`, `shop_thumbnail_size` |
| Sonstiges | `shop_search_weighting`, `shop_invoice_mail_subject`, `shop_withdrawal_mail_to` |

Die Pfade sind relativ und müssen unterhalb von `shop_sites_dir` liegen; diese
Wurzel steht in der `settings.ini` und ist im ERP nicht änderbar.

## Wiederkehrende Aufgaben

- **Artikel veröffentlichen:** In der Artikelkarte „Im Shop anbieten" und
  „Veröffentlichen"; die Seite entsteht beim nächsten Lauf.
- **Alles neu schreiben:** „Alle veröffentlichen" in der Shop-Übersicht.
- **Artikel aus dem Shop nehmen:** Die Shop-Angaben löschen; der Auftrag
  `remove_part` entfernt die Seite. Für die alte Adresse gehört eine Zeile in
  `redirect_pages_hugoshop` — die 404-Seite fragt sie über `resolveRedirect`
  ab.
- **Zahlungen abgleichen:** Knopf in der Bestellansicht oder der Auftrag aus
  dem Cron. Gebucht wird nie automatisch.
- **Widerrufe:** Ansicht „Widerrufe" im Admin-Panel, siehe `shop-widerruf.md`.
- **Shop-UI ändern:** `npm run build:shop-ui`, das Bundle mit committen; der
  Läufer verteilt es beim nächsten Lauf.

## Wenn etwas klemmt

| Zeichen | Ursache |
| --- | --- |
| Hugo: `template for shortcode "shop-…" not found` | `oserp-shop/` fehlt — Läufer laufen lassen |
| Proxy antwortet 503 `SHOP_PROXY_NOT_CONFIGURED` | `oserp-shop/config.php` fehlt oder ist für den Webserver nicht lesbar |
| 403 `SHOP_NOT_AUTHORIZED` | Der Schlüssel im Proxy passt nicht zu `shop_public_key` |
| Warenkorb bleibt leer | Der Aufruf kommt nicht von derselben Adresse — Proxy einrichten oder `shop_allowed_origins` setzen |
| Seiten entstehen nicht | `shop_sites_dir` und `shop_site_dir` prüfen, Schreibrechte, Ausgabe des Läufers lesen |
| „Ein Lauf ist noch unterwegs" | Sperrdatei in `backend/tmp/` — ein vorheriger Lauf hängt |
| Neue Einstellungen fehlen nach einem Update | Das Schema-Update beim Login prüft Prüfsummen, siehe `shop-veroeffentlichung.md` |
