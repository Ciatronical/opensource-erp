# Steuerzonen (taxzone_id) in der Faktura

Die Steuerzone steuert **nicht direkt einen Steuersatz**, sondern wählt über eine
Kontenzuordnung das passende Erlöskonto — und über dieses Konto erst den
Steuerschlüssel und damit den Steuersatz (`rate`). Sie ist die Brücke zwischen der
Buchungsgruppe eines Artikels und der tatsächlich angewandten Umsatzsteuer.

## Datenmodell

| Tabelle          | Bedeutung                                                              |
|------------------|------------------------------------------------------------------------|
| `tax_zones`      | Definition der Steuerzonen                                             |
| `taxzone_charts` | Zuordnung Steuerzone × Buchungsgruppe → Erlös-/Aufwandskonto          |
| `chart`          | Konto (hier: Erlöskonto `income_accno_id`)                            |
| `taxkeys`        | Verknüpft Konto → Steuerschlüssel, gültig ab `startdate`             |
| `tax`            | Enthält den eigentlichen Steuersatz `rate` (numeric, z. B. `0.19000`) |

### Steuerzonen (Standard SKR04)

| ID | Beschreibung           | Sortierung |
|----|------------------------|------------|
| 4  | Inland                 | 1 (Standard) |
| 1  | EU mit USt-ID Nummer   | 2          |
| 2  | EU ohne USt-ID Nummer  | 3          |
| 3  | Außerhalb EU           | 4          |

### Auflösungskette der `rate`

```
parts.buchungsgruppen_id
   → taxzone_charts (taxzone_id × buchungsgruppen_id)
      → taxzone_charts.income_accno_id  (Erlöskonto)
         → taxkeys.chart_id  (neuester startdate gewinnt)
            → tax.rate       (Steuersatz)
```

Diese Kette wird im Backend in
[backend/api/parts/parts.php](../backend/api/parts/parts.php) (Funktion `findParts`)
und in [backend/api/faktura/faktura.php](../backend/api/faktura/faktura.php) verwendet.

## Wirkung in der Anwendung

- **Neue Rechnung** (`/rechnung/neu`): `taxzone_id` wird aus dem Kunden-/
  Lieferantenstamm (`customer.taxzone_id`) übernommen, sonst erste Zone,
  Fallback `4` (Inland).
- **Position hinzufügen**: Die aktuelle `taxzone_id` der Rechnung wird an die
  Artikelsuche übergeben; zurück kommt je Position ein `buchungsziel` mit `rate`.
  Damit rechnet das Frontend die MwSt-Aufteilung und die Summen.
- **Erwartete Logik**: Inland = 19 % / 7 %, EU mit USt-ID bzw. Außerhalb EU = 0 %
  (steuerfrei bzw. Reverse Charge).

### Bekannte Einschränkungen im aktuellen Code

1. **Wechsel der Steuerzone berechnet bestehende Positionen nicht neu.** Nur neu
   hinzugefügte Positionen erhalten den Satz der neuen Zone; bereits erfasste
   Positionen behalten ihre alte `rate`.
2. **Beim Laden einer bestehenden Rechnung ist die Zone hartkodiert auf `4`**
   ([faktura.php](../backend/api/faktura/faktura.php), `WHERE tc.taxzone_id = '4'`).
   Eine gespeicherte Auslandsrechnung wird beim Wiederöffnen mit Inlands-Sätzen
   angezeigt — abweichend vom gespeicherten Wert.

## SQL-Abfragen zur Prüfung

### 1. Übersicht: Welcher Satz gilt je Steuerzone × Buchungsgruppe?

```sql
SELECT
    tz.id                         AS taxzone_id,
    tz.description                AS steuerzone,
    bg.id                         AS buchungsgruppen_id,
    bg.description                AS buchungsgruppe,
    c.accno                       AS erloeskonto,
    tx.rate                       AS rate,
    (tx.rate * 100)::numeric(6,2) AS prozent,
    tk.startdate
FROM taxzone_charts tc
JOIN tax_zones      tz ON tz.id = tc.taxzone_id
JOIN buchungsgruppen bg ON bg.id = tc.buchungsgruppen_id
JOIN chart          c  ON c.id  = tc.income_accno_id
LEFT JOIN LATERAL (
    SELECT tax_id, startdate
    FROM taxkeys
    WHERE chart_id = tc.income_accno_id
    ORDER BY startdate DESC
    LIMIT 1
) tk ON true
LEFT JOIN tax tx ON tx.id = tk.tax_id
ORDER BY tz.sortkey, bg.id;
```

### 2. Prüfung für einen konkreten Artikel und eine konkrete Zone

Bildet exakt die Backend-Abfrage aus `findParts` nach. `taxzone_id` und `parts.id`
ersetzen.

```sql
SELECT
    p.partnumber,
    p.description,
    tz.description AS steuerzone,
    c2.accno       AS erloeskonto,
    tx.rate,
    tk.startdate
FROM parts p
JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
JOIN taxzone_charts  tc ON bg.id = tc.buchungsgruppen_id
JOIN tax_zones       tz ON tz.id = tc.taxzone_id
JOIN chart           c2 ON tc.income_accno_id = c2.id
LEFT JOIN taxkeys    tk ON tk.chart_id = c2.id
LEFT JOIN tax        tx ON tx.id = tk.tax_id
WHERE tc.taxzone_id = 4          -- gewünschte Steuerzone
  AND p.id = 123                 -- Artikel-ID
ORDER BY tk.startdate DESC
LIMIT 1;
```

### 3. Prüfung für eine bestehende Rechnung (alle Positionen, mit echter Zone)

Berücksichtigt — anders als das Backend beim Laden — die **tatsächliche**
`taxzone_id` der Rechnung. `invnumber` ersetzen.

```sql
SELECT
    ar.invnumber,
    ar.taxzone_id,
    tz.description AS steuerzone,
    p.partnumber,
    i.description,
    tx.rate,
    (tx.rate * 100)::numeric(6,2) AS prozent
FROM ar
JOIN invoice i  ON i.trans_id = ar.id
JOIN parts   p  ON p.id = i.parts_id
JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
JOIN taxzone_charts  tc ON bg.id = tc.buchungsgruppen_id AND tc.taxzone_id = ar.taxzone_id
JOIN tax_zones       tz ON tz.id = ar.taxzone_id
JOIN chart           c2 ON tc.income_accno_id = c2.id
LEFT JOIN LATERAL (
    SELECT tax_id FROM taxkeys WHERE chart_id = c2.id ORDER BY startdate DESC LIMIT 1
) tk ON true
LEFT JOIN tax tx ON tx.id = tk.tax_id
WHERE ar.invnumber = 'RE-2026-0001';   -- Rechnungsnummer
```

## Worauf beim Ergebnis zu achten ist

- **`rate` ist NULL** → für diese Zone/Buchungsgruppe fehlt ein
  `taxzone_charts`-Eintrag, oder das Erlöskonto hat keinen `taxkeys`-Eintrag. Das
  Frontend setzt dann `rate ?? 0`, also 0 % — meist ein Konfigurationsfehler.
- **Mehrere `taxkeys` pro Konto** → es gewinnt der mit dem neuesten `startdate`.
  Ein versehentlich zukünftig datierter Eintrag würde fälschlich greifen.
- **Plausibilität**: Inland = 19 % / 7 %, EU mit USt-ID bzw. Außerhalb EU = 0 %.
