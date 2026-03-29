# Nummernkreise — Bestandsaufnahme

Alle Nummernkreise werden in der `defaults`-Tabelle der Mandantendatenbank verwaltet.
Die Konfiguration erfolgt über die Firmenkonfiguration (Tab „Nummernkreise").

## Zwei Gruppen von Nummernkreisen

Nummernkreise sind in zwei Gruppen aufgeteilt, die sich in Schutz, Vergabe und
Konfigurierbarkeit unterscheiden (`backend/api/oserp_config/defaults.php`):

### Gruppe 1: Geschützt (Faktura-Dokumente)

Gesetzlich fortlaufend — dürfen nicht manuell geändert und in der Firmenkonfiguration
nicht auf einen niedrigeren Wert zurückgesetzt werden.

| Eigenschaft | Verhalten |
|---|---|
| **Nummernvergabe** | Atomares CTE-Increment (`COALESCE(col::INT, 0) + 1`) |
| **Kollisionsschutz** | Keiner nötig — Nummern sind nicht manuell änderbar |
| **Firmenkonfiguration** | `GREATEST()` — Wert kann nur erhöht werden |
| **Felder** | `invnumber`, `sonumber`, `ponumber`, `sqnumber`, `rfqnumber`, `donumber`, `cnnumber`, `soinumber`, `pqinumber`, `pocnumber`, `sdonumber`, `pdonumber`, `sudonumber`, `rdonumber`, `s_reclamation_record_number`, `p_reclamation_record_number` |

### Gruppe 2: Frei (Stammdaten)

Nummern können vom Benutzer manuell vergeben/geändert werden. Der Zähler in der
Firmenkonfiguration ist frei setzbar (auch auf niedrigere Werte).

| Eigenschaft | Verhalten |
|---|---|
| **Nummernvergabe** | `nextFreeNumber()` — zählt ab defaults-Wert hoch bis eine freie Nummer gefunden wird |
| **Kollisionsschutz** | Ja — `nextFreeNumber()` prüft gegen die Zieltabelle |
| **Firmenkonfiguration** | Direktes UPDATE — Wert frei wählbar |
| **Felder** | `customernumber`, `vendornumber`, `articlenumber`, `servicenumber`, `assemblynumber`, `assortmentnumber` |

---

## Aktive Nummernkreise

### Rechnungen (geschützt)

| defaults-Spalte | Zieltabelle | Zielspalte | Manuell änderbar | Mechanismus | Dateien |
|---|---|---|---|---|---|
| `invnumber` | `ar` | `invnumber` | Nein | CTE + COALESCE | `faktura.php` (createFaktura, convertFaktura) |
| `invnumber` | `ap` | `invnumber` | Nein | CTE + COALESCE | `faktura.php` (createFaktura, convertFaktura) |

> **Hinweis**: Verkaufs- und Eingangsrechnungen teilen sich denselben Zähler (`invnumber`).

### Aufträge (geschützt)

| defaults-Spalte | Zieltabelle | Zielspalte | record_type | Manuell änderbar | Mechanismus | Dateien |
|---|---|---|---|---|---|---|
| `sonumber` | `oe` | `ordnumber` | `sales_order` | Nein | CTE + COALESCE | `faktura.php`, `call_transcription.php` |
| `ponumber` | `oe` | `ordnumber` | `purchase_order` | Nein | CTE + COALESCE | `faktura.php` |

> **Hinweis**: Verkaufs- und Einkaufsaufträge nutzen beide `oe.ordnumber`, aber getrennte Zähler.

### Angebote / Anfragen (geschützt)

| defaults-Spalte | Zieltabelle | Zielspalte | record_type | Manuell änderbar | Mechanismus | Dateien |
|---|---|---|---|---|---|---|
| `sqnumber` | `oe` | `quonumber` | `sales_quotation` | Nein | CTE + COALESCE | `faktura.php` |
| `rfqnumber` | `oe` | `quonumber` | `request_quotation` | Nein | CTE + COALESCE | `faktura.php` |

### Lieferscheine (geschützt)

| defaults-Spalte | Zieltabelle | Zielspalte | Manuell änderbar | Mechanismus | Dateien |
|---|---|---|---|---|---|
| `donumber` | `delivery_orders` | `donumber` | Nein | CTE + COALESCE | `faktura.php` (convertFaktura) |

> **Hinweis**: Alle Lieferschein-Typen (Verkauf, Einkauf, Beistell, Retoure) nutzen denselben Zähler `donumber`.

### Kunden / Lieferanten (frei)

| defaults-Spalte | Zieltabelle | Zielspalte | Manuell änderbar | Mechanismus | Dateien |
|---|---|---|---|---|---|
| `customernumber` | `customer` | `customernumber` | Ja | `nextFreeNumber()` | `customer_vendor.php` |
| `vendornumber` | `vendor` | `vendornumber` | Ja | `nextFreeNumber()` | `customer_vendor.php` |

> **Sonderfall Kundentyp**: Wenn ein Kundentyp (Business Type) zugewiesen ist, wird
> `business.customernumberinit` statt `defaults.customernumber` als Zähler verwendet.
> Der Kollisionsschutz greift auch hier (Prüfung gegen `customer.customernumber`).

### Artikel / Dienstleistungen (frei)

| defaults-Spalte | Zieltabelle | Zielspalte | part_type | Manuell änderbar | Mechanismus | Dateien |
|---|---|---|---|---|---|---|
| `articlenumber` | `parts` | `partnumber` | `part` | Ja | `nextFreeNumber()` | `parts.php` |
| `servicenumber` | `parts` | `partnumber` | `service` | Ja | `nextFreeNumber()` | `parts.php` |

---

## Reservierte Nummernkreise (noch nicht implementiert)

Diese Spalten existieren in der `defaults`-Tabelle und sind in der Firmenkonfiguration
editierbar, werden aber noch nicht im Code zur Nummernvergabe verwendet.

### Belegtypen

| defaults-Spalte | Geplante Verwendung | Status |
|---|---|---|
| `cnnumber` | Gutschriften (Credit Notes) | Schema vorhanden, keine Vergabelogik |
| `soinumber` | Auftragseingang (Sales Order Intake) | Schema vorhanden, keine Vergabelogik |
| `pqinumber` | Angebotseingang (Purchase Quotation Intake) | Schema vorhanden, keine Vergabelogik |
| `pocnumber` | Auftragsbestätigung (Purchase Order Confirmation) | Schema vorhanden, keine Vergabelogik |

### Lieferschein-Untertypen

| defaults-Spalte | Geplante Verwendung | Status |
|---|---|---|
| `sdonumber` | Verkaufs-Lieferschein | Reserviert — aktuell nutzen alle Typen `donumber` |
| `pdonumber` | Einkaufs-Lieferschein | Reserviert — aktuell nutzen alle Typen `donumber` |
| `sudonumber` | Beistell-Lieferschein | Reserviert — aktuell nutzen alle Typen `donumber` |
| `rdonumber` | Retouren-Lieferschein | Reserviert — aktuell nutzen alle Typen `donumber` |

### Reklamationen

| defaults-Spalte | Geplante Verwendung | Status |
|---|---|---|
| `s_reclamation_record_number` | Verkaufsreklamation | Schema vorhanden, keine Vergabelogik |
| `p_reclamation_record_number` | Einkaufsreklamation | Schema vorhanden, keine Vergabelogik |

### Artikel-Untertypen

| defaults-Spalte | Geplante Verwendung | Status |
|---|---|---|
| `assemblynumber` | Erzeugnisse | Schema vorhanden, `parts.php` unterstützt nur `part`/`service` |
| `assortmentnumber` | Sortimente | Schema vorhanden, `parts.php` unterstützt nur `part`/`service` |

---

## Dateien

| Datei | Verantwortung |
|---|---|
| `backend/api/database.php` | `nextFreeNumber()` — zentrale Kollisionsschutz-Funktion |
| `backend/api/faktura/faktura.php` | Rechnungen, Aufträge, Angebote, Lieferscheine |
| `backend/api/customer_vendor/customer_vendor.php` | Kunden- und Lieferantennummern |
| `backend/api/customer_vendor/call_transcription.php` | Aufträge aus WhatsApp-Transkription |
| `backend/api/parts/parts.php` | Artikel- und Dienstleistungsnummern |
| `backend/api/oserp_config/defaults.php` | Konfiguration speichern (GREATEST-Schutz) |
| `src/core/views/config/tabs/ranges.of.numbers.tab.vue` | UI für Nummernkreis-Konfiguration |
