# Shop: Medienbibliothek in OpensourceERP

Stand 2026-10-15. Status: **in Umsetzung** — Entscheidungen B1–B7 wie
vorgeschlagen bestätigt; Schritt 1 (Bibliothek) und Schritt 2 (Verteilung an
HugoCMS) umgesetzt, Schritte 3–4 offen. Löst E6 aus `dev/shop-hugocms-trennung.md` ab („die Medien
bleiben auf der Webseite“): Produktbilder, Vorschaubilder und Downloads werden
künftig in OpensourceERP verwaltet, zentral für alle Verkaufskanäle, mit
einem Dateimanager in der Shop-Erweiterung.

## Ergebnis

Realisierbar. Die Bausteine für Ablage und Oberfläche gibt es schon; der
eigentliche Aufwand liegt in der Verteilung der Dateien an die Webseiten und
in der Umstellung von eBay.

| Teil | Stand | Aufwand |
| --- | --- | --- |
| Ablage je Mandant unter `backend/data/<db>/` | vorhanden (`fmDataDirForDb`, Kunden- und Fahrzeugakten) | klein |
| Dateimanager mit Hochladen, Vorschau (Bild, PDF), Umbenennen, Löschen, Suche | vorhanden: VueFinder (`vuefinder` 4.7) mit der `vf*`-API in `customer_vendor/filemanager.php` | klein: drei Wurzeln, Verwendungsprüfung |
| Öffentliche Auslieferung für eBay | vorhanden (`webhook/part-image.php`) | klein: auf die Bibliothek umstellen |
| Vorschaubilder erzeugen | in HugoCMS vorhanden (`ShopThumbnails`, GD); kam ursprünglich aus OpensourceERP | klein: zurück nach OSERP |
| Verteilung an die Webseiten | **fehlt**: die Übertragung kennt nur Text bis 5 MB | **groß** |
| Übernahme vorhandener Medien von den Webseiten | fehlt | mittel: Importwerkzeug |

Zahlen der Testwebseite sonic24: 7 486 Produktbilder (476 MB), Vorschaubilder
19 MB, 954 Downloads (2,5 GB).

## Entwurf

### Ablage und Datenmodell

- Je Mandant unter `backend/data/<db>/shop/media/` die Verzeichnisse
  `images/`, `thumbnails/`, `downloads/` — genau die drei Medienarten, mit
  denen HugoCMS und die Vorlagen heute arbeiten. Vorschaubilder erzeugt
  OpensourceERP selbst beim Hochladen und bei geänderter Größe
  (`shop_thumbnail_size`), sie sind nicht von Hand zu pflegen.
- Neue Tabelle `media_shop` (Schemaänderung, Freigabe nötig): `id`, `kind`
  (image, download), `filename`, `sha256`, `size`, `mime`, `width`, `height`,
  `itime`, `mtime`. Sie trägt, was die Übertragung und der Dateimanager
  brauchen, ohne 3 GB bei jedem Lauf neu zu lesen.
- **Der Dateiname bleibt der Schlüssel** (B2): `parts_ext.hugoshop_images`
  und `hugoshop_downloads` nennen weiter Dateinamen, je Medienart eindeutig.
  Vorlagen, Suche, Warenkorb und die Adressmuster bleiben so unverändert;
  die Namen sind zugleich die Adressen auf der Webseite. Umbenennen im
  Dateimanager schreibt die Verweise um, Löschen ist nur ohne Verwendung
  möglich („Wo verwendet“ über eine jsonb-Abfrage).
- eBay (B3): `parts_channel_image_shop` hält heute je Kanal eine Kopie der
  Datei unter `data/<db>/parts/<id>/`. Künftig verweist die Zeile auf die
  Bibliothek (`media_id`, Reihenfolge je Kanal bleibt); `webhook/part-image.php`
  liefert aus der Bibliothek. Die Kopien entfallen nach einer Übernahme.

### Oberfläche

- Medien in der Artikelkarte (seit 2026-10-15, `shop-part-media.vue`): die
  Ansicht „Artikel bearbeiten“ zeigt im Shop-Abschnitt nur die Produktbilder
  und Downloads, die der Artikel nennt — Bereiche in einer Schiene links wie
  die Werkzeugleiste des HugoCMS-Dateimanagers, Kacheln oder Liste, Suche,
  Mehrfachauswahl, Kontextmenü (Ansehen, Herunterladen, Verwendung, Als
  Vorschaubild verwenden, Anzeigename eines Downloads, Löschen; mit
  `edit_shop_config` Umwandeln und Umbenennen), Vorschau für Bilder, PDF
  und Text. Eine eigenständige Ansicht „Medien“ und eine Auswahl aus der
  Bibliothek gibt es nicht mehr; die Bibliothek bleibt zentral, der Zugang
  läuft über den Artikel.
- Hochladen (Knopf oder Ablegen) öffnet einen Dialog je Datei mit Name und,
  bei Bildern, Zielformat (Beibehalten, WebP, JPEG, PNG). **Jeden Namen gibt
  es in der Bibliothek nur einmal**: Belegt ist er, wenn die Datei vorhanden
  ist und ein Artikel sie nennt — dann antwortet das Backend `MEDIA_EXISTS`
  (mit den Artikelnummern), nichts wird ersetzt, die Zeile bleibt zur
  Korrektur im Dialog. Liegt die Datei nur in der Bibliothek, ohne dass ein
  Artikel sie nennt (Rest eines Fehlers), wird sie beim Hochladen ersetzt
  (`shopMediaImport`). Die Endung folgt dem Inhalt bzw. dem Zielformat,
  Downloads behalten die Endung der Datei.
- Löschen heißt: aus dem Artikel **und** aus der Bibliothek, auch für wer
  nur Artikel pflegt (`shop_part_edit`). Vorher geht der Verweis dieses
  Artikels aus `parts_ext` (`shopMediaUnlinkFromPart`); nennt ein anderer
  Artikel die Datei, bleibt sie dort (`MEDIA_IN_USE`), die Karte zieht nach
  und sagt es. Dateien, die der Artikel nennt, die aber in der Bibliothek
  fehlen, erscheinen als fehlend und lassen sich nur vom Artikel entfernen.
  Umbenennen und Umwandeln ziehen den Namen in der ungespeicherten Karte
  sofort nach. Der Abgleich mit den Verzeichnissen (`shopMediaScan`) hat
  keinen Knopf mehr; er läuft über das Importwerkzeug und die API.
- Die Vorschau in der Karte kommt aus der Bibliothek (`shopMediaPreview`),
  nicht von der Webseite — sie zeigt das Bild sofort, nicht erst nach dem
  nächsten Lauf.
- Rechte wie heute: `shop_part_edit` für Bilder am Artikel, `edit_shop_config`
  für die Bibliothek.

### Verteilung an die Webseiten (B1)

Zwei Wege:

| | A: Übertragung an HugoCMS (wie die Seiten) | B: Webseite lädt von OpensourceERP |
| --- | --- | --- |
| Webseite ohne OpensourceERP lauffähig | ja | nein — fällt OSERP aus, fehlen Bilder und Downloads |
| Last | einmalige Übertragung, danach nur Änderungen | jeder Seitenaufruf holt Bilder vom ERP-Server, 2,5 GB Downloads über ihn |
| Aufwand | groß (Binärübertragung in HugoCMS und OSERP) | klein (Adressmuster auf OSERP zeigen, öffentlicher Endpunkt) |
| Hosting | HugoCMS muss größere Anfragen annehmen | OSERP muss öffentlich und schnell erreichbar sein |

**Vorschlag A.** Die Webseite bleibt in sich geschlossen, wie heute. Dafür:

- HugoCMS: neuer Befehl `shopmediaupload` — eine Datei je Anfrage als
  Binärdaten (kein Base64, keine JSON-Portionen), bei großen Dateien in
  Stücken (etwa 4 MB) mit Prüfsumme, damit `post_max_size` auf einem
  Hosting reicht. Die Freigaben „Produktbilder“, „Vorschaubilder“ und
  „Downloads“ werden damit Schreibziele der Anbindung, jede mit eigener
  Endungsliste (Bilder: jpg, png, gif, webp; Downloads: pdf und was B5
  festlegt). Kein PHP, keine Unterverzeichnisse — die Regeln von
  `ShopSync::allowedPath()` gelten weiter.
- Abgleich: Der Lauf meldet die Medien im Verzeichnis der Lieferung mit den
  Prüfsummen aus `media_shop`, HugoCMS merkt sich je Datei Prüfsumme, Größe
  und Zeit (`last-manifest.json`) und liest nur Dateien neu, die sich daran
  erkennbar geändert haben — sonst kostete jeder Lauf das Lesen von 3 GB.
- Die Bereitstellung (`backend/tmp/…-staging/`) bekommt **keine** Kopie der
  Medien; die Übertragung liest sie aus der Bibliothek. Sonst läge alles
  doppelt auf dem OSERP-Server.
- Löschen wie bei den Seiten: HugoCMS entfernt, was die vorige Lieferung
  enthielt und diese nicht mehr — ein aus der Bibliothek gelöschtes Bild
  verschwindet so von der Webseite.
- `shopthumbnails` in HugoCMS entfällt (B4): Die Vorschaubilder kommen fertig
  aus OpensourceERP. Die Prüfung der Downloads (`shopdownloads`, 2026-10-14)
  entfällt ebenfalls — was fehlt, meldet der Abgleich.
- Mehrere HugoShops (B7): eine Bibliothek, jede Shop-Webseite bekommt alle
  Medien — wie die Bildnamen in `parts_ext` heute für alle HugoShops gelten
  (M6).

### Übernahme (B6)

Werkzeug `tools/shop-media-import.php`: liest ein Verzeichnis (Kopie der
Webseite, etwa per rsync), legt die Dateien in der Bibliothek ab, trägt sie in
`media_shop` ein und gleicht mit den Namen in `parts_ext` ab — Bericht über
fehlende und unbenutzte Dateien. Beim ersten Lauf danach findet der Abgleich
die Dateien auf der Webseite mit gleicher Prüfsumme vor und überträgt nichts;
nur die Bestandsliste in HugoCMS wird aufgebaut. Für eBay übernimmt das
Werkzeug die Kanalkopien aus `data/<db>/parts/`.

## Grenzen und Risiken

- **Speicher**: Die Medien liegen künftig auf dem OSERP-Server (sonic24:
  3 GB) und gehören in dessen Sicherung.
- **Hochladen**: `upload_max_filesize`/`post_max_size` von OpensourceERP
  (lokal 32 MB) begrenzen die Einzeldatei; größere Downloads brauchen auch im
  Dateimanager einen Stück-Upload.
- **Erste Übertragung** an eine neue Webseite dauert bei 3 GB Stunden; ein
  Lauf muss unterbrechbar sein (die Stücke je Datei machen das möglich), und
  der Cron darf dabei nicht parallel starten (Sperre vorhanden).
- **Übergang**: Bis zur Umstellung bleiben die Medien auf der Webseite
  maßgeblich; die Umstellung je Mandant braucht die Übernahme vor dem ersten
  Lauf mit Bibliothek — sonst räumte der Abgleich nichts weg, lieferte aber
  auch nichts.

## Entscheidungen (offen)

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| B1 | Verteilung | A: Übertragung an HugoCMS, Webseite bleibt eigenständig |
| B2 | Identität einer Datei | Dateiname bleibt Schlüssel, je Medienart eindeutig; `media_shop` nur für Metadaten (Schemaänderung) |
| B3 | eBay | auf die Bibliothek umstellen, Kanalkopien entfallen nach der Übernahme |
| B4 | Vorschaubilder | OpensourceERP erzeugt sie; `shopthumbnails` und `shopdownloads` in HugoCMS entfallen |
| B5 | Downloads | Endungen pdf, zip, txt und Bilder (sonic24 führt Anleitungen als JPG); Einzeldatei bis 100 MB, in Stücken übertragen |
| B6 | Übernahme | Importwerkzeug aus einer Kopie der Webseite, mit Bericht |
| B7 | Mehrere HugoShops | eine Bibliothek, alle Webseiten bekommen alle Medien |

## Umsetzung

### Schritt 1 (2026-10-15): Bibliothek

| Teil | Wo |
| --- | --- |
| Tabelle `media_shop`, `shop_media_usage()`, `shop_media_rename()` | `backend/upstall/shop/company_schema.sql`, Abschnitt Medienbibliothek |
| Ablage, Prüfung, Vorschaubilder (GD), Umbenennen, Löschen, Abgleich | `backend/api/shop/lib/media.php` |
| API für Dateimanager und Artikelkarte (`shopMedia*`) | `backend/api/shop/media.php` |
| Medien in der Artikelkarte (Kacheln, Liste, Kontextmenü, Hochladen) | `src/features/shop/components/shop-part-media.vue`, Zugriff `composables/shopMediaApi.js` |
| Auswahl aus der Bibliothek | `components/shop-media-picker.dialog.vue` |
| Artikelkarte bindet die Medien ein (`v-model:images`, `v-model:downloads`) | `components/part-shop.card.vue` |
| Übernahme vorhandener Medien | `tools/shop-media-import.php` |

Einzelheiten:

- Die Ablage liegt je Mandant unter `backend/data/<db>/shop/media/`
  (`images/`, `thumbnails/`, `downloads/`), die Verzeichnisrechte wie bei den
  Kunden- und Fahrzeugakten (`fmMkdir`, `dir_group`/`dir_mode`).
- Bilder werden nach dem Inhalt geprüft und bekommen die passende Endung;
  beim Hochladen aus der Artikelkarte ist ein belegter Name ein Fehler, das
  Importwerkzeug ersetzt eine Datei gleichen Namens (neue Fassung). Das Vorschaubild
  entsteht beim Ablegen; die Größe ist `shop_thumbnail_size`, der Abgleich
  („Abgleichen“ in der Ansicht, `shopMediaSync`) erzeugt fehlende und bei
  geänderter Größe neue Vorschaubilder.
- Umwandeln (Kontextmenü, nur Produktbilder, `shopMediaConvertFile`): WebP,
  JPEG oder PNG über GD, Qualität 85; die neue Datei trägt den alten Namen mit
  neuer Endung und ersetzt die alte samt Vorschaubild und Eintrag, die
  Verweise der Artikel werden wie beim Umbenennen umgeschrieben. JPEG legt
  Transparenz auf Weiß, ein animiertes GIF verliert die Animation.
- Umbenennen schreibt die Verweise in `parts_ext` um; die Trigger legen dabei
  die Aufträge „Produktseite schreiben“ an. Löschen nur ohne Verwendung.
- Die Vorschau in der Artikelkarte kommt aus der Bibliothek
  (`shopMediaPreview`, `thumbnails://` mit Rückfall auf das Bild). Die
  Adressmuster des Kanals gelten weiter für die Webseite.
- Gegen ein HugoCMS ohne Medienübernahme (Version vor 2026-10-15) liefert die
  Übertragung keine Medien aus: Die Webseite zeigt weiter, was dort liegt,
  HugoCMS erzeugt die Vorschaubilder selbst und prüft die Downloads; der Lauf
  und die Kanalkarte weisen darauf hin.

Geprüft mit einem Wegwerf-Skript gegen die Testdatenbank (Ablegen mit
Endung nach Inhalt, Ersetzen, Vorschaubild ≤ Größe, Abweisen falscher Arten,
Verwendung, Umbenennen samt Verweisen in Bildern und Downloads, Löschen
verwendeter Dateien abgewiesen, Abgleich, Löschen) und mit dem
Importwerkzeug im Probelauf gegen sonic24 (7 486 Bilder, 954 Downloads).

### Schritt 2 (2026-10-15): Verteilung an HugoCMS

| Teil | Wo |
| --- | --- |
| HugoCMS: Medienfreigaben als Schreibziele, Stück-Upload, Prüfsummen-Gedächtnis | `backend/core/Shop/ShopSync.php` (`uploadMedia()`, `mediaArea()`, `chunkBytes()`), `FileService::putFile()` |
| HugoCMS: Befehl `shopmediaupload`, Fähigkeit `media` in `shopbuildstatus` | `backend/core/Connector.php` |
| OSERP: Medienliste aus `media_shop`, Stück-Upload, Meldungen | `backend/api/shop/lib/hugocms.php` (`shopHugoCmsMediaList`, `shopHugoCmsMediaUpload`, `shopMediaMissingInLibrary`, `shopHugoCmsMediaReport`) |
| OSERP: Vorschaubilder vor der Lieferung erneuern | `lib/media.php` (`shopMediaThumbnailsRefresh`) |
| Kanalkarte: Hinweis, ob HugoCMS Medien annimmt | `shop-channel-settings.vue`, `crm_fields.shopHugoCmsMedia*` |

Ablauf in HugoCMS (`ShopSync`):

- Die Freigaben „Produktbilder“, „Vorschaubilder“ und „Downloads“ sind
  Schreibziele: nur direkt im Verzeichnis (keine Unterverzeichnisse), Bilder
  mit `jpg, jpeg, png, gif, webp`, Downloads zusätzlich `pdf, zip, txt`, nie
  PHP. Eine Medienfreigabe darf im Paket oder unter den Produktseiten liegen —
  die Medienregel geht vor. Im Paket bleibt es bei `md, json, html, js, css`.
- `shopmanifest`: Medien tragen `size`. Ob eines schon da ist, entscheidet
  nicht jedes Mal eine neue Prüfsumme über Gigabytes: `last-manifest.json`
  merkt sich je Medium Größe und Zeit (`stat`), und solange beide stimmen,
  gilt die bei der Übernahme gemerkte Prüfsumme. Höchstzahl der Dateien je
  Abgleich 60 000 (Seiten, Paket, Medien zusammen).
- `shopmediaupload` (multipart, Felder `syncId`, `path`, `offset`, Datei
  `chunk`): Stücke sammeln sich unter `var/shop/…/sync/<syncId>/media/`; mit
  dem letzten Stück prüft HugoCMS die Prüfsumme und legt die Datei **sofort**
  in ihre Freigabe (`FileService::putFile`, Ersetzen atomar). So macht eine
  abgebrochene erste Übertragung (Gigabytes) beim nächsten Abgleich dort
  weiter, wo sie stand. Ein Stück mit `offset` 0 beginnt die Datei neu; passt
  der Versatz nicht, antwortet HugoCMS mit `SHOP-MEDIA-OFFSET` (409), und
  OpensourceERP beginnt die Datei einmal von vorn. Größtes Stück: 8 MiB,
  begrenzt durch `upload_max_filesize`/`post_max_size` der Installation —
  `shopbuildstatus` nennt die Grenze (`media.chunkBytes`), OpensourceERP
  richtet sich danach (Vorgabe 4 MiB). Einzeldatei bis 100 MB.
- `shopcommit`: prüft, dass jedes angeforderte Medium vollständig liegt
  (Größe), schreibt die Textdateien, löscht aus der vorigen Lieferung
  Verschwundenes — jetzt auch Medien (ein aus der Bibliothek gelöschtes Bild
  verschwindet von der Webseite) — und merkt sich den Stand der Medien.
  Vorschaubilder, die HugoCMS früher selbst erzeugt hat, stehen in keiner
  Lieferung und bleiben liegen; die Lieferung ersetzt die der genannten Bilder.
- `shopbuildstatus` meldet `media` (`accepted`, `chunkBytes`, `maxBytes`,
  Endungen). Ein älteres OpensourceERP kennt das Feld nicht und arbeitet wie
  bisher (`shopthumbnails`, `shopdownloads` bleiben bis Schritt 4).

Ablauf in OpensourceERP (`shopHugoCmsSync`):

- Vor der Lieferung erneuert `shopMediaThumbnailsRefresh` Vorschaubilder,
  die fehlen oder nicht die eingestellte Größe haben — eine geänderte
  `shop_thumbnail_size` wirkt so mit dem nächsten Lauf.
- `shopHugoCmsMediaList` baut aus `media_shop` die Einträge der Lieferung:
  Produktbild und Vorschaubild unter die Freigaben „Produktbilder“ und
  „Vorschaubilder“, Downloads unter „Downloads“ (fehlt die Freigabe in HugoCMS,
  bleiben die Downloads aus, der Lauf sagt es). Prüfsummen aus der Tabelle,
  keine Kopie in der Bereitstellung; die Übertragung liest aus der
  Bibliothek. Einträge ohne Datei meldet der Lauf („in der Ansicht „Medien“
  abgleichen“). Jede Shop-Webseite bekommt alle Medien (B7).
- Der Fingerabdruck der Lieferung enthält die Medien: Ein neues oder
  ersetztes Bild in der Bibliothek löst beim nächsten Lauf die Übertragung
  aus, auch ohne Auftrag.
- Nach einer Übertragung meldet der Lauf die Medien („Medien an HugoCMS: …
  in der Lieferung, … übertragen“), neu erzeugte Vorschaubilder und die in
  den Artikeln genannten Dateien, die in der Bibliothek fehlen (alle Bilder
  eines Artikels, nicht nur das erste) — statt der früheren Meldungen
  „fehlen auf dem Webserver“. `shopthumbnails` und `shopdownloads` ruft er
  nur noch gegen ein HugoCMS ohne Medienübernahme auf.
- Scheitert die Übertragung einer Mediendatei (Prüfsumme, Größe), endet der
  Lauf mit einem Fehler — die Übernahme wäre ohnehin unvollständig. Meist
  passt dann `media_shop` nicht zur Datei: in der Ansicht „Medien“ abgleichen.

Geprüft mit zwei Wegwerf-Skripten: `ShopSync` allein (30 Prüfungen: Pfad- und
Endungsregeln, Stücke, Versatz, Prüfsumme, direkte Ablage, Gedächtnis aus
Größe und Zeit, Übernahme, Löschen) und die ganze Kette OpensourceERP → cURL
multipart → nachgebildetes HugoCMS mit der echten `ShopSync`-Klasse hinter dem
PHP-Einbauserver (Testdatenbank in zurückgerollter Transaktion: erste
Lieferung mit 3 739 Seiten und drei Medien, zweite ohne Übertragung,
Vorschaubild bei abweichender Größe erneuert, Übertragung in Stücken von
1 000 Bytes, Löschen aus der Bibliothek entfernt die Datei von der Webseite,
Ausgabe des Laufs ohne `shopthumbnails`/`shopdownloads`).

## Reihenfolge der Umsetzung

1. Bibliothek: Tabelle, Ablage, Dateimanager mit drei Wurzeln, Verwendung,
   Artikelkarte, Vorschaubilder in OpensourceERP, Importwerkzeug. — umgesetzt
2. Verteilung: Binärübertragung in HugoCMS, Freigaben als Schreibziele,
   Abgleich mit gemerkten Prüfsummen, Lauf in OpensourceERP. — umgesetzt
3. eBay auf die Bibliothek, Kanalkopien übernehmen.
4. Ablösung in HugoCMS (`shopthumbnails`, `shopdownloads`), Dokumentation
   (E6 zurücknehmen, `shop-betrieb.md`, HugoCMS-README).
