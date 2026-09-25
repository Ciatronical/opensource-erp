# E-Rechnung: bekannte Fehler

Stand: 2026-09-25. Beide Fehler betreffen die E-Rechnung (ZUGFeRD / Factur-X / XRechnung).

## Status

Beide Fehler sind behoben (2026-09-25):

1. `loadEInvoiceData()` liefert jetzt `seller_email` (Adresse aus
   `defaults.email_sender_invoice`, auch bei der Schreibweise `Name <adresse>`) und
   `seller_contact_name` (Bearbeiter der Rechnung, `ar.employee_id`). Der Builder setzt
   daraus die elektronische Adresse (BT-34) und den Verkäufer-Kontakt.
   Telefon und E-Mail des Bearbeiters ergänzt `mergeEmployeeContact()`
   ([backend/api/print/print.php](../backend/api/print/print.php)) aus `auth.user_config`;
   dieselbe Funktion nutzt der Rechnungsdruck. Ohne eigene E-Mail des Bearbeiters dient die
   Absenderadresse als Kontakt-E-Mail.
   **Hinweis:** BR-DE-6 (Telefon) ist nur erfüllt, wenn der Bearbeiter in der
   Benutzerverwaltung eine Telefonnummer hinterlegt hat.
2. Die Belegerfassung nimmt XML-Dateien an. Liefert `extractEInvoiceData()` für eine
   XML-Datei `null`, bricht der Upload mit `EINVOICE_UNREADABLE` ab, statt die Datei als Bild
   an die KI zu senden.

## 1. Kontakt-E-Mail des Verkäufers fehlt in jeder E-Rechnung

### Befund

[backend/api/faktura/einvoice_builder.php](../backend/api/faktura/einvoice_builder.php) (Zeile 150)
liest die E-Mail-Adresse des Verkäufers aus zwei Feldern:

```php
$email = $company['seller_contact_email'] ?? $company['co_ustid_email'] ?? null;
```

Das Array `$company` stammt aus der Abfrage in
[backend/api/faktura/einvoice.php](../backend/api/faktura/einvoice.php) (Zeilen 229–231):

```sql
SELECT company, taxnumber, co_ustid, gln,
       address_street1, address_street2, address_zipcode,
       address_city, address_country, templates
FROM defaults LIMIT 1
```

Die Abfrage liefert keines der beiden Felder. Die Tabelle `defaults` besitzt auch keine
Spalten `seller_contact_email` oder `co_ustid_email`
(`backend/upstall/*/company_schema.sql`). `$email` ist daher immer `null`, und
`setDocumentSellerCommunication()` wird nie aufgerufen.

### Auswirkung

- Die elektronische Adresse des Verkäufers (BT-34) fehlt in jeder erzeugten E-Rechnung.
- XRechnung verlangt nach den deutschen Geschäftsregeln (BR-DE-2 bis BR-DE-7) einen
  Verkäufer-Kontakt mit Name, Telefon und E-Mail. Die interne Prüfung
  (`ZugferdDocumentValidator`) prüft kein Schematron und meldet das nicht. Empfänger,
  die mit dem KoSIT-Validator prüfen (z. B. Rechnungseingangsplattformen des Bundes und
  der Länder), weisen solche Rechnungen voraussichtlich ab. Nicht mit einem echten
  Empfänger getestet.

### Lösungsvorschlag

1. Quelle für die Adresse festlegen. Naheliegend ist die vorhandene Spalte
   `defaults.email_sender_invoice`; alternativ ein eigenes Feld im Konfigurations-Tab
   „E-Rechnung“.
2. Die Spalte in die Abfrage in `einvoice.php` aufnehmen.
3. Zeile 150 in `einvoice_builder.php` auf diesen Schlüssel umstellen und die beiden nicht
   existierenden Schlüssel entfernen.
4. Für XRechnung zusätzlich den Verkäufer-Kontakt (Name, Telefon, E-Mail) über
   `setDocumentSellerContact()` setzen.

## 2. XRechnung-XML lässt sich in der Belegerfassung nicht hochladen

### Befund

Die Upload-Oberfläche
[src/features/accounting/views/accounting.invoice-upload.vue](../src/features/accounting/views/accounting.invoice-upload.vue)
beschränkt die Dateitypen an zwei Stellen:

- Zeile 26: `accept=".pdf,.jpg,.jpeg,.png"`
- Zeile 298: `const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png']`

Das Backend kann XML dagegen lesen:
[backend/api/faktura/einvoice_reader.php](../backend/api/faktura/einvoice_reader.php)
(Zeile 23) verarbeitet MIME-Typen mit `xml`, und
[backend/api/accounting/invoice_upload.php](../backend/api/accounting/invoice_upload.php)
(Zeilen 75–78) ruft `extractEInvoiceData()` vor der KI-Extraktion auf.

### Auswirkung

Eingehende E-Rechnungen im reinen XML-Format (XRechnung, CII ohne PDF) können nicht
erfasst werden. Nur ZUGFeRD / Factur-X als PDF funktioniert. Seit 2025 müssen Unternehmen
E-Rechnungen empfangen können – XRechnung ist dabei ein gängiges Format.

### Lösungsvorschlag

1. In `accounting.invoice-upload.vue` `.xml` sowie `application/xml` und `text/xml`
   zulassen (Zeilen 26 und 298).
2. In `invoice_upload.php` den Fall absichern, dass `extractEInvoiceData()` bei einer
   XML-Datei `null` liefert (ungültiges oder nicht unterstütztes XML, z. B. UBL). Der
   bisherige Rückfallpfad zur KI (ab Zeile 257) behandelt alles, was kein PDF ist, als
   Bild und würde die XML-Datei als Bild an die API senden. Stattdessen eine
   verständliche Fehlermeldung zurückgeben.
3. Für eingehende XML-Rechnungen eine lesbare Darstellung vorsehen (z. B. über die
   XSLT-Visualisierung der KoSIT), da die Datei selbst nicht menschenlesbar ist.
