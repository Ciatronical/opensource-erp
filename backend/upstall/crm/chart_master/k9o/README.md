# Vollständiger DATEV-Kontenrahmen für k9o (SKR03 / SKR04)

Diese SQL-Dateien bringen den Kontenrahmen einer **k9o**-Firmendatenbank auf den
vollen DATEV-Standard (SKR03 ≈ 1592, SKR04 ≈ 1619 Sachkonten) — ganz ohne
OpensourceERP, nur mit `psql`.

- `k9o-skr03-full-chart.sql`
- `k9o-skr04-full-chart.sql`

## Eigenschaften

- **Additiv & idempotent:** Es werden nur **fehlende** Konten und deren
  Steuerschlüssel ergänzt. Bestehende Konten, Steuerschlüssel und **Buchungen
  bleiben unverändert**. Mehrfaches Ausführen ist gefahrlos.
- **Keine geratenen Steuerschlüssel:** Jedes neue Steuer-Konto bekommt seine
  zeitabhängigen `taxkeys`, indem ein bereits vorhandenes Konto desselben
  Steuerschlüssels als Vorlage geklont wird — `tax_id`, UStVA-Position und die
  Gültigkeitsperioden stammen also aus **deiner** Datenbank.
- **Abgesichert:** Die Datei prüft `defaults.coa` und bricht ab, wenn der falsche
  Kontenrahmen läuft (SKR03-Datei gegen SKR04-DB o. ä.).

## Anwendung

> **Immer zuerst ein Backup anlegen!**

```bash
# 1. Backup der Firmendatenbank
pg_dump -U <db-user> <firmendb> > backup_vor_kontenrahmen.sql

# 2. Passende Datei einspielen (nach deinem Kontenrahmen)
psql -U <db-user> -d <firmendb> -f k9o-skr03-full-chart.sql
#   bzw.
psql -U <db-user> -d <firmendb> -f k9o-skr04-full-chart.sql
```

Danach unter **System → Kontenrahmen anzeigen** prüfen.

## Hinweise

- **Encoding:** Die Dateien sind UTF-8. Bei sehr alten Installationen mit
  LATIN9/ISO-8859-15-Datenbank vorher nach UTF-8 migrieren oder `client_encoding`
  passend setzen.
- **Seltene EU-Erwerb-Konten** (innergemeinschaftlicher Erwerb, Steuerschlüssel
  18/19, z. B. SKR04 5420/5915): Wenn deine DB noch **kein** Konto mit diesem
  Steuerschlüssel hat, fehlt die Klon-Vorlage — diese wenigen Konten werden dann
  angelegt, bleiben aber ohne Steuerautomatik (in der NOTICE am Ende als „Rest"
  gemeldet). Sie lassen sich manuell mit dem passenden Schlüssel versehen. Alle
  gängigen Konten (USt/VSt 7 %/19 % usw.) sind vollständig.
- Getestet gegen frische SKR03/SKR04-Datenbanken: korrekte Konten­zahl, keine
  Dubletten, keine FK-Fehler, periodengenaue Steuerhistorie (16 %/19 %).

Erzeugt aus `chart_master/skrNN.csv` via `scripts/build-k9o-sql.php`.
