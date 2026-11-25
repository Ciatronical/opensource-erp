# KI-Trading (Erweiterung `trading`)

Autonomes Handelssystem innerhalb des ERP: Forschungslabor mit virtuellem
Kapital, Backtests, Papierhandel, Risk-Engine und Broker-Anbindung. Echtgeld
gibt es nur nach ausdrücklicher, dauerhafter Freigabe durch einen
Systemadministrator.

**Gewinne sind nicht garantiert.** Das System sucht Strategien und prüft sie
streng; ob es welche findet, hängt vom Markt ab (siehe „Stand der Forschung“).

## Einschalten

1. Firmenkonfiguration → Funktionen → Erweiterungen → „KI-Trading“ aktivieren.
   Das Schema-Update für diese Firma läuft wie bei jeder Erweiterung.
2. Cron einrichten (ein Eintrag für alle Mandanten; ohne aktive Erweiterung tut
   er nichts):

       0,10,20,30,40,50 * * * * cd /home/work/opensource-erp && php backend/cli/trading-engine.php >> log/trading-engine.log 2>&1

3. Menü „KI-Trading“. Die Kachel auf der Startseite zeigt die Kennzahlen.

Mehr ist für die Simulation nicht nötig: Kursdaten kommen ohne Zugang von der
öffentlichen Binance-Schnittstelle, gehandelt wird auf virtuellen Konten.

## Aufbau

| Baustein | Ort | Aufgabe |
|---|---|---|
| Schema | `backend/upstall/trading/company_schema.sql` | Tabellen `*_trading`, Schutz-Trigger, Grundausstattung |
| Marktdaten | `lib/marketdata.php` | Abruf (Binance, Kraken, Alpaca), Prüfung, Kurspanel |
| Indikatoren, Statistik | `lib/indicators.php`, `lib/stats.php` | SMA/EMA/RSI/ATR …, Deflated Sharpe, Timing-Test |
| Strategie-Engine | `lib/strategies.php` | 8 Familien + Aufsätze, Parameterraum, Abwandlung/Kreuzung |
| Backtesting | `lib/backtest.php` | Simulation mit Gebühren, Slippage, Handelsschwelle |
| Forschung | `lib/research.php` | Zyklus, Endprüfung, Lebenszyklus, Marktphasen, KI-Vorschläge |
| Broker-Adapter | `lib/brokers.php` | Schnittstelle `TradingBroker`; Simulator, Kraken, Alpaca |
| Risk-Engine | `lib/risk.php` | Prüfung jeder Order, Sperren, Überwachung |
| Ordermanagement | `lib/orders.php` | Anlegen, Übermitteln, Abgleich, Verbuchen |
| Portfolio | `lib/portfolio.php` | Konten, Bewertung, Kapitalverteilung |
| Engine | `lib/engine.php`, `backend/cli/trading-engine.php` | Hauptschleife, Not-Aus, Schutzstopps |
| Auswertung | `lib/report.php` | Kennzahlen, Tagesbericht |
| API | `backend/api/trading/trading.php` | Aktionen für das Frontend |
| Frontend | `src/features/trading/` | Ansicht mit Reitern, Diagramme, Startseiten-Kachel |
| Tests | `backend/tests/trading/run.php` | 285 Prüfungen, eigene Wegwerf-Datenbank |

(`lib/` = `backend/api/trading/lib/`)

Alles ausser `trading.php` besteht aus Klassen. Das ist Absicht: der
API-Dispatcher ruft jede benutzerdefinierte *Funktion* auf, deren Name in
`action` steht. Interne Bausteine sind so von aussen nicht erreichbar — es gibt
keine Aktion, die eine einzelne Order aufgibt.

Die Simulation rechnet in PHP, nicht in SQL: Backtests sind Schleifen über
Zeitreihen mit Zustand, das ist in SQL weder lesbar noch schnell. Alle
Lesezugriffe der API bleiben bei „ein Aufruf, eine Abfrage, ein JSON“.

## Konten und Handelsmodus

| Konto | Zweck |
|---|---|
| `strategy_paper` | virtuelles Testkonto je Strategie, gleiches Startkapital (Einstellung „Virtuelles Startkapital“, Vorgabe 10.000) |
| `portfolio_paper` | virtuelles Gesamtportfolio, mischt die laufenden Strategien — das zeigt das Dashboard |
| `broker_paper` | Konto in der Testumgebung eines Brokers |
| `live` | Brokerkonto mit echtem Geld |

Handelsmodus: **Simulation** (Vorgabe), **Echtgeld** (Freigabe erteilt, aktive
Live-Verbindung, keine Sperre) oder **Angehalten** (Sperre über alles).

## Lebenszyklus einer Strategie

    candidate ─► rejected                (Training/Validierung/Endprüfung nicht bestanden)
        │
        ▼
      paper  ◄── queued                  (alle Prüfungen bestanden; Vorwärtstest auf virtuellem Konto)
        │
        ▼
    live_eligible ◄─► live               (Vorwärtstest bestanden; „live“ = handelt gerade mit echtem Geld)
        │
        ▼
     retired                             (Verlust im Vorwärtstest über der Grenze)

Jede Strategie hat eine Kennung (`S-0001`), eine Herkunft (Erkundung,
Abwandlung, Kreuzung, KI-Vorschlag), eine Begründung für ihren Zustand und eine
Versionshistorie. Eine Abwandlung, die auf den Testdaten besser ist als ihre
laufende Ausgangsstrategie, wird deren nächste Version — die Bewährungsfrist
beginnt dann neu.

## Schutz vor Zufallstreffern

Die Daten sind zeitlich geteilt: Training (60 %) | Validierung (20 %) | Test
(20 %, die jüngsten). Ein Zyklus:

1. Kandidaten erzeugen. Welche Familien bevorzugt werden, lernt das System aus
   seiner Bilanz (Thompson-Sampling).
2. Jeden Kandidaten auf Training und Validierung simulieren. Rangfolge nach dem
   *schlechteren* der beiden Sharpe-Werte.
3. Nur die drei Besten kommen in die Endprüfung:
   - **Stabilität** — die Nachbarn im Parameterraum müssen auch tragen. Wer hier
     scheitert, berührt die Testdaten gar nicht.
   - **Testdaten** — Sharpe und Verlust vom Höchststand auf dem unberührten Abschnitt.
   - **Timing-Test** — die Positionen werden ringförmig gegen den Markt
     verschoben. Schlägt die echte Zuordnung die verschobenen nicht deutlich,
     steckt hinter dem Ergebnis Marktrichtung, kein Können.
   - **Deflated Sharpe** — korrigiert um die Zahl *aller* je auf den Testdaten
     geprüften Varianten. Die Testdaten werden mit jedem Zyklus ein Stück
     verbraucht; die Hürde steigt entsprechend.
4. Wer besteht, handelt auf einem virtuellen Konto mit neuen Kursen weiter und
   wird erst nach Mindestdauer, Mindestzahl Trades und begrenztem Verlust für
   Echtgeld zugelassen.

Jede geprüfte Variante wird gespeichert, auch die verworfene: nur so stimmt die
Zahl der Versuche, und nichts wird zweimal getestet. Varianten mit anderem
Parametersatz, aber identischem Verhalten zählen nicht als neuer Versuch.

Im Backtest wird das Signal vom Schluss eines Tages erst zur Eröffnung des
nächsten gehandelt; jeder Handel kostet Gebühr (Vorgabe 40 Basispunkte) und
Slippage (10 Basispunkte). Ein Test prüft für jede Familie, dass die Gewichte
bis zum Tag t nicht von Daten nach t abhängen.

Alle Grenzwerte stehen in `settings_trading` (Reiter „Sicherheit und
Einstellungen“), änderbar nur durch Administratoren.

## Sicherheit

**Was die KI nicht kann — technisch, nicht nur per Vereinbarung:**

- Risikogrenzen, Übernahmekriterien, Broker-Zugänge und die Echtgeldfreigabe
  ändern. Auf `risk_limits_trading`, `settings_trading`,
  `broker_connections_trading` und `live_authorizations_trading` liegt ein
  Trigger, der Schreibzugriffe nur zulässt, wenn die Transaktion
  `oserp.trading_actor = 'user'` gesetzt hat. Das tun ausschliesslich die
  API-Aktionen nach bestandener Administratorprüfung (`TradingGuard::asUser`).
- Sperren aufheben. Setzen darf die Engine, `UPDATE`/`DELETE` auf
  `locks_trading` nur der Benutzer.
- An der Risk-Engine vorbei handeln. `TradingOrders::submit()` ist die einzige
  Stelle, die einen Adapter anspricht, und ruft die Prüfung selbst.

**Risk-Engine** (jede Order, Papier wie Echtgeld): Handelssperre und Freigabe,
maximales Ordervolumen (Echtgeld), Position je Instrument, Investitionsgrad,
Tagesverlust (keine Käufe mehr, Verkäufe bleiben möglich), offene Orders,
Orders je Tag, Kursalter, Abweichung vom letzten Schlusskurs, Guthaben, Bestand.
Laufend überwacht: Portfolioverlust vom Höchststand (sperrt den Echtgeldhandel)
und Fehlerserien (sperren alles).

**Doppelte Orders** sind auf zwei Ebenen ausgeschlossen: die `client_order_id`
entsteht aus Konto, Instrument, Signaltag und Richtung und ist in der Datenbank
eindeutig; und der Zustand `submitting` wird vor dem Broker-Aufruf gespeichert —
reisst die Verbindung ab, wird über unsere Kennung beim Broker nachgesehen statt
wiederholt.

**Not-Aus** (jeder Mitarbeiter): setzt eine Sperre über alles und storniert alle
offenen Orders, auch beim Broker. Bestände werden nicht verkauft. Aufheben kann
nur ein Administrator.

**Echtgeldfreigabe** (nur Administratoren): drei Bestätigungen plus Eintippen
von `ECHTGELD`; setzt eine aktive Live-Verbindung und keine Sperre voraus.
Danach dauerhaft — der letzte Eintrag in `live_authorizations_trading` gilt,
über Neustarts und Sitzungen hinweg. Deaktivieren darf jeder Mitarbeiter: neue
Echtgeldorders unterbleiben, offene werden storniert, Bestände und Schutzstopps
bleiben.

**Schutzstopp**: für jeden Bestand eines Brokerkontos liegt ein Stop beim Broker
(Vorgabe 25 % unter Einstand, 0 = aus). Er greift auch, wenn die Engine ausfällt.

**Eigene Bestände**: die Engine verkauft nur, was sie selbst gekauft hat, und
setzt nie mehr ein als den Höchstbetrag der Verbindung. Meldet der Broker
weniger Bestand als verbucht, wird gesperrt statt gehandelt.

**Zugangsdaten**: AES-256-GCM (`lib/secrets.php`), Schlüssel aus dem
Auth-DB-Passwort; die API gibt sie nie zurück. Beim Broker dem Schlüssel nur
Handel und Abfrage erlauben, keine Auszahlung.

## Broker

Verwendet werden nur dokumentierte Endpunkte.

| Broker | Stand | Schnittstelle |
|---|---|---|
| Simulator | umgesetzt | intern; Market, Limit, Stop, Stop-Limit, Trailing-Stop |
| Kraken | umgesetzt | Spot REST: `Balance`, `AddOrder`, `QueryOrders`, `OpenOrders`, `ClosedOrders`, `CancelOrder`, `CancelAll`, `Ticker`, `AssetPairs` |
| Alpaca | umgesetzt | Trading API v2: `/v2/account`, `/v2/positions`, `/v2/orders`, `/v2/orders:by_client_order_id`; Daten-API für Kurse |
| Interactive Brokers | geprüft, nicht umgesetzt | Die Client Portal Web API braucht ein lokal laufendes Gateway mit regelmässiger interaktiver Anmeldung — unvereinbar mit unbeaufsichtigtem Betrieb. Die TWS-API braucht eine laufende TWS/IB-Gateway-Anwendung und ein Socket-Protokoll, für das es keine PHP-Bibliothek gibt |
| Coinbase | geprüft, nicht umgesetzt | Advanced Trade API ist machbar (JWT mit ES256); ohne Testzugang nicht prüfbar, deshalb nicht blind gebaut |

Orderarten je Adapter: Kraken — market, limit, stop-loss, stop-loss-limit,
trailing-stop. Alpaca — Aktien: market, limit, stop, stop_limit, trailing_stop;
Krypto nur market, limit, stop_limit (so von Alpaca vorgegeben, der Adapter
lehnt alles andere ab).

Kraken hat für den Kassamarkt keine Testumgebung. In der Umgebung
„Testumgebung“ gehen Orders mit `validate=true` hinaus: Kraken prüft sie
vollständig, führt sie aber nicht aus. Alpaca hat ein echtes Papierkonto.

**Nicht gegen echte Konten getestet.** Die Adapter sind gegen die Dokumentation
gebaut und mit nachgestelltem HTTP getestet; die Kraken-Signatur stimmt mit dem
Beispiel aus der Kraken-Dokumentation überein. Vor Echtgeld: Verbindung in der
Testumgebung anlegen, „Prüfen“ drücken, einige Tage laufen lassen.

Ein weiterer Broker = eine Klasse, die `TradingBroker` erfüllt, plus ein Eintrag
in `TradingBrokers::ADAPTERS`.

## Marktdaten

Tagesbalken, nur abgeschlossene. Quelle je Einstellung: `binance` (lange
Historie, ohne Zugang — Vorgabe), `kraken` (höchstens 720 Balken je Abruf),
`alpaca` (braucht eine eingerichtete Alpaca-Verbindung; damit auch Aktien/ETFs).
Fehlerhafte Balken (Preis ≤ 0, Hoch < Tief, Sprung über 80 % zum Vortag) werden
verworfen und protokolliert. Vor jeder Order prüft die Risk-Engine Kursalter und
Abweichung vom letzten Schluss.

Grundausstattung: acht Krypto-Paare in EUR. Weitere Instrumente: Zeile in
`instruments_trading` und je Quelle/Broker eine in `instrument_symbols_trading`.
Es wird nur gekauft und verkauft, nicht leerverkauft.

## Eigene Ideen und ihr gemessener Nutzen

Gemessen am 24.11.2026 auf echten Tagesdaten (Binance, 8 Paare, 03.01.2020 bis
23.11.2026; Training bis 21.02.2024, Validierung bis 08.07.2025, Test bis
23.11.2026), 20 Forschungszyklen, 357 geprüfte Varianten.

| Idee | Messung |
|---|---|
| Testdaten nur für die drei Besten je Zyklus, Deflated Sharpe über alle Versuche | 57 Finalisten: Sharpe im Mittel 1,18 (Training), 1,60 (Validierung), **0,00 (Test)**; 23 davon im Test negativ. Ohne diese Stufe wären 57 scheinbar gute Strategien in den Handel gegangen |
| Stabilitätsprüfung vor den Testdaten | 3 Varianten als Einzelspitze erkannt und verworfen, ohne die Testdaten zu verbrauchen |
| Verhaltens-Duplikate nicht zählen | Im ersten Probelauf (9 Zyklen, vor dem Einbau) standen unter den 25 besten Testergebnissen zwei Paare mit identischem Ergebnis bei verschiedenen Parametern; seitdem zählt jede Verhaltensweise einmal |
| Schwankungsskalierung (`vol_target`) | Varianten mit / ohne: grösster Verlust im Training 22,7 % / 40,3 %, Sharpe 1,04 / 0,95 |
| Marktphasenfilter (`regime_sma`) | mit / ohne: grösster Verlust im Training 27,6 % / 34,9 % |
| Nachgezogener Stopp (`atr_stop`) | mit / ohne: grösster Verlust im Training 26,8 % / 38,1 % |
| Abwandlung und Kreuzung statt nur Zufall | Validierung bestanden: Erkundung 69 %, Abwandlung 84 %, Kreuzung 95 % — **aber** der Vorsprung hielt auf den Testdaten nicht (siehe erste Zeile): die Suche passt sich der Validierung an. Genau dafür gibt es die Endprüfung |
| Kapitalverteilung nach Schwankung und Korrelation, Schutzstopp beim Broker, Trigger-Schutz | Durch Tests belegt, nicht am Markt gemessen — es gibt noch keine Strategie im Vorwärtstest |

Die Vergleiche der Aufsätze sind Beobachtungen über die geprüften Varianten,
kein kontrolliertes Experiment: die Suche bevorzugt, was funktioniert, die
Gruppen sind also nicht zufällig zusammengesetzt.

## Stand der Forschung

Auf den Daten vom 24.11.2026 hat **keine Strategie alle Prüfungen bestanden**.
Der Testabschnitt (Juli 2025 bis November 2026) ist ein Abwärtsmarkt: Bitcoin
−39 %. Die beste Variante (Keltner-Ausbruch) kam dort auf +6,9 % bei 11,6 %
Verlust vom Höchststand, Sharpe 0,47 — knapp unter der Grenze von 0,5 und unter
der Deflated-Sharpe-Grenze. Ohne Leerverkauf ist in einem solchen Markt Bargeld
das beste erreichbare Ergebnis.

Das System bleibt deshalb vorerst in Geld und forscht täglich weiter. Die
Grenzen wurden nicht gelockert, um Gewinner zu erzeugen. Wer sie ändern will:
Reiter „Sicherheit und Einstellungen“ → Übernahmekriterien.

## Tests

    php backend/tests/trading/run.php            # alles
    php backend/tests/trading/run.php Orders     # nur passende Tests

Die erste Anweisung setzt `OSERP_TRADING_TEST=1`: kein Netzzugriff ohne
Ersatztransport, kein echter Broker-Adapter. Die Tests laufen in der eigenen
Datenbank `oserp_trading_test` und können keine Echtgeldorder auslösen.

Abgedeckt: Indikatoren, Statistik, kein Blick in die Zukunft (alle Familien),
Buchführung des Backtests, Schema-Update (wiederholbar, überschreibt nichts),
Schutz-Trigger, fehlerhafte Marktdaten, Risikoprüfung, doppelte Orders,
Teilausführungen, Verbindungsabbruch, Serverneustart, automatische Sperre nach
Fehlerserie, alle Orderarten des Simulators, Freigabe/Sperren/Not-Aus,
Papierhandel von Ende zu Ende, Lebenszyklus, Schutzstopp, Kraken- und
Alpaca-Adapter, Forschungszyklus, KI-Vorschläge, API samt Rechten.

## Offen

- Oberfläche nur in Deutsch und Englisch; die übrigen Sprachen fallen auf Englisch zurück.
- Die Oberfläche ist gebaut, aber nicht im Browser durchgeklickt.
- Benachrichtigungen stehen im Dashboard (Meldungen) und im Audit-Protokoll; kein Versand per E-Mail oder Telegram.
- Nur Tagesbalken; kein Leerverkauf, kein Hebel.
- Instrumente lassen sich in der Oberfläche ein- und ausschalten, aber nicht anlegen.
