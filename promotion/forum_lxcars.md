# LxCars — Das Autohaus- & Werkstattmodul für OpensourceERP

LxCars verwandelt OpensourceERP in eine vollständige Autohaus- und Werkstattverwaltung. Vom Fahrzeugscheinscanner über KI-gestützte Arbeitsplanung bis hin zur TÜV-Erinnerung per WhatsApp oder Telegram — alles in einem System, ohne Medienbrüche.

**Live-Demo:** [https://demo.lxcars.de/](https://demo.lxcars.de/)
**GitHub:** [https://github.com/Ciatronical/opensource-erp](https://github.com/Ciatronical/opensource-erp)
**Aktivierung:** LxCars wird in der Mandantenkonfiguration als Feature aktiviert — keine separate Installation nötig.

---

## Die Innovationen zuerst

### Fahrzeugscheinscanner (OCR)

Fahrzeugschein fotografieren oder PDF hochladen — fertig. Über die Integration mit fahrzeugschein-scanner.de werden über 50 Felder automatisch erkannt:

- Kennzeichen, FIN, HSN/TSN
- Erstzulassung, Emissionsklasse
- Motor-Spezifikationen, Leistung, Hubraum
- Halterinformationen

Das System gleicht die erkannten HSN/TSN-Daten automatisch mit der KBA-Datenbank ab, erkennt Duplikate und verknüpft Fahrzeuge mit bestehenden Kundendatensätzen. Original-Dokumente und Feld-Ausschnitte werden zur Nachkontrolle gespeichert. Kein manuelles Abtippen mehr.

### KI-gestützte Positionsvorschläge

Arbeitsanweisungen eingeben — die KI (Anthropic Claude) schlägt passende Artikel und Positionen vor. Dabei werden keine Fantasie-Artikel erfunden, sondern ausschließlich aus dem eigenen Artikelstamm und der Auftragshistorie vorgeschlagen:

- **Rechnungen** fließen mit dem höchsten Gewicht ein (tatsächlich fakturiert)
- **Aufträge** mit mittlerem Gewicht (bestätigte Arbeit)
- **Angebote** als historische Referenz

Die Gewichtung ist in der Konfiguration frei einstellbar. Das System berücksichtigt Fahrzeugtyp, Hersteller und Modell für kontextgenaue Vorschläge.

### KI-gestützte Zeitplanung

Die KI schätzt die voraussichtliche Arbeitsdauer pro Anweisung — basierend auf:

- Historische Ist-Zeiten vergleichbarer Arbeiten
- Fahrzeugtyp-Matching (gleiche HSN/TSN)
- Fahrzeugalter (ältere Fahrzeuge = oft längere Arbeitszeiten)
- Aktualitätsgewichtung (neuere Daten bevorzugt)

Gerundet auf 15-Minuten-Intervalle, mit nachvollziehbarer Begründung für jede Schätzung.

### TÜV/HU-Erinnerungen — Mehrkanalig

Automatisierte Hauptuntersuchungs-Benachrichtigungen über drei Kanäle:

- **PDF-Serienbriefe** mit SEPA-Mandaten — direkt druckfertig
- **SFTP-Übertragung** an eLetter-Dienstleister
- **WhatsApp** über die Meta Business API mit freigegebenen Vorlagen
- **Telegram** über die kostenlose Bot-API — ohne Gebühren und ohne Template-Genehmigung

Konfigurierbarer Vorlaufzeitraum, Kunden-Opt-out, personalisierte Anrede und Fahrzeugauflistung. Eine Kampagne, alle Kanäle.

---

## Fahrzeugverwaltung

- Vollständige Fahrzeugdatenbank mit KBA-Daten (50+ Felder pro Fahrzeugtyp)
- Kennzeichen, FIN, HSN/TSN/D2 mit Validierung und Duplikaterkennung
- Automatischer KBA-Abgleich: Einzeltreffer werden direkt verknüpft, bei Mehrfachtreffern erscheint ein Auswahldialog
- Sonder-KBA für nicht standardmäßig erfasste Fahrzeuge (manuelle Hersteller-/Markenerfassung)
- Kilometerstand-Tracking über die gesamte Fahrzeughistorie
- Kundenordner mit automatischer Verzeichnisstruktur und Symlinks nach Kennzeichen
- Auto-Save mit 500ms Debounce — kein Datenverlust bei Seitenwechsel

---

## Mechaniker-Modus

Eine dedizierte Oberfläche für die Werkstatt — reduziert auf das Wesentliche:

### Arbeitsanweisungen
- Übersicht aller zugewiesenen Aufträge mit Status (erledigt/gesamt)
- Anweisungen abhaken und dokumentieren
- Atomare Anweisungsnummerierung mit optionalem Präfix (z.B. "AW-")

### Zeiterfassung
- Start/Stopp-Timer pro Anweisung
- Automatische Pausenabzüge (konfigurierbare Pausenzeiten)
- Vorherigen Timer automatisch stoppen beim Start eines neuen
- Netto-Minuten-Berechnung ohne Pausen
- Automatische Mitarbeiterzuordnung

### Ersatzteil-Anforderung
Der Mechaniker bestellt nicht selbst — er fordert an:

- Anforderung mit Beschreibung, Menge und Dringlichkeit
- Foto-Dokumentation (mehrere Fotos pro Anforderung)
- Status-Workflow: **Angefordert** → **Bestellt** → **Eingetroffen**
- Automatische Auftragsposition wird parallel erstellt
- Werkstattleiter sieht globale Warteschlange aller offenen Anforderungen
- Lieferantenhistorie nach Häufigkeit sortiert

---

## TÜV-konforme Mängelverwaltung

- Kompletter TÜV-Mängelkatalog integriert
- Klassifizierung nach Schweregrad: GM (geringer Mangel), EM (erheblicher Mangel), AB (auflagenbedingt)
- Suche nach Mängelcode oder Beschreibung
- Eigene Mängel anlegen (E-xxx-Nummerierung)
- Notizfeld pro Mangel für Kontext
- Funktioniert auf Aufträgen und Rechnungen

---

## KFZ-Zulassungsdokumente (PDF)

Vollständige Unterstützung der deutschen Zulassungsprozesse:

- **Zulassung** (Neuanmeldung)
- **Umschreibung** (Halterwechsel)
- **Abmeldung**
- **Änderung**
- **Ersatz** (mit eidesstattlicher Versicherung)

Automatische Befüllung mit Personen-, Fahrzeug- und Versicherungsdaten (eVB-Nummer). SEPA-Mandat-Integration. Mehrseitige Dokumente, direkt als PDF generiert.

---

## Etikettendruck (ZPL)

Direkter Druck auf Zebra-kompatible Etikettendrucker:

- **Grüne Plakette**: Großes Kennzeichen, Branding des Kfz-Betriebs (autoprofis24.de)
- **Reifenetiketten**: 4er-Set (VR, VL, HR, HL) mit Kennzeichen, Halter, Reifengröße, Lagerort, Hersteller, Fahrzeugtyp und Positionsmarkierung

---

## Reports & Auswertungen

### Mechaniker-Report (individuell)
- Tages-, Wochen- oder Monatsansicht
- Ist- vs. Soll-Minuten
- Erledigte Aufgaben vs. Gesamtaufgaben
- Top-Aufgaben nach Häufigkeit und Durchschnittszeit
- Persönlicher Bestleistungs-Tag
- Verfügbare Kapazität

### Team-Report (Werkstatt gesamt)
- Gesamtproduktivität der Werkstatt
- Pro-Mechaniker-Statistiken
- Tagesmatrix: Mechaniker × Datum
- Fahrzeug-Durchlaufzeiten
- Team-Kapazitätsauslastung

---

## Weitere Integrationen

- **SilverDAT-Import**: VXS-Format für Teile-Import aus dem SilverDAT-Katalog mit automatischer Buchungsgruppen-Zuordnung
- **WhatsApp/Telegram-Terminbestätigungen**: Kunden automatisch an Werkstatttermine erinnern — über WhatsApp oder Telegram, je nach Kundenpräferenz
- **Kalender-Synchronisation**: Werkstatttermine direkt im ERP-Kalender

---

## Datenbank-Erweiterung

LxCars erweitert die Kivitendo-Datenbank um spezialisierte Tabellen — das bestehende Schema bleibt vollständig kompatibel:

| Tabelle | Funktion |
|---|---|
| `cars_lxcars` | Fahrzeug-Stammdaten |
| `kba_lxcars` | KBA-Datenbank (50+ Felder) |
| `special_kba_lxcars` | Sonder-Fahrzeugtypen |
| `oe_instructions_lxcars` | Arbeitsanweisungen mit Zeiterfassung |
| `oe_parts_requests_lxcars` | Ersatzteil-Anforderungen |
| `oe_defects` / `ar_defects` | Mängeldokumentation |
| `tuev_defect_catalog` | TÜV-Mängelkatalog |
| `instructions_lxcars` | Anweisungs-Stammdaten mit Autocomplete |

---

*LxCars ist Teil von [OpensourceERP](https://github.com/Ciatronical/opensource-erp) und wird als Feature-Modul aktiviert — keine separate Installation, kein separates Repo.*
