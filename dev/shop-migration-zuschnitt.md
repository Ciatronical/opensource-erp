# Erweiterung Shop — Zuschnitt der Fachlogik

Grundlage: `/home/worker/Projekte/dev.hugoshop.dev/kivitendo_bridge/framework/`
(4.055 Zeilen, 55 Funktionen). Ziel ist ein Backend in der Erweiterung `shop`,
auf das zwei Zugänge zugreifen — das Admin-Panel im OSERP-Frontend für die
Mitarbeiter des Betreibers und die Webshop-Webseite (`shop-ui`) für dessen
Kunden.

## 1. Drei Schichten

Die Bridge kennt heute zwei Schichten: eine Aktionsfunktion, die `$_POST` und
`$_COOKIE` liest und selbst `echo json_encode(...)` schreibt, und darunter
Klassen, die den Kontext ebenfalls aus dem Cookie holen. Beides muss auf drei
Schichten auseinandergezogen werden, sonst ist keine einzige Funktion vom
Admin-Panel aus aufrufbar.

    Aktionsschicht      pro Zugang getrennt
      backend/api/shop/*.php     Mitarbeiter: permit() + DbhCompany::begin()
      backend/shop/index.php     Kunde: Shop-Schlüssel + Kontext-Cookie

    Fachschicht         backend/api/shop/lib/*.php
      kennt weder Cookie noch Sitzung noch Zugang
      bekommt $db und Skalare, gibt Arrays zurück, gibt nie aus

    Datenschicht        SQL, ein Vorgang je Aufruf

Die Aktionsschicht ist dünn: Parameter lesen, Fachfunktion rufen, Antwort
formatieren. Sie ist der einzige Ort, an dem `$_POST`, `$_COOKIE`, `header()`
und `resultInfo()` vorkommen.

## 2. Signaturregel

Jede Fachfunktion:

    funktionsname($db, <fachliche Parameter>) : array

- `$db` steht immer zuerst. Beim Kunden kommt sie aus der Auflösung über den
  Shop-Schlüssel, beim Mitarbeiter aus `DbhCompany::begin()`.
- `customer_id` ist ein Parameter, nie ein Cookie-Zugriff.
- Rückgabe ist ein Array. Kein `echo`, kein `resultInfo()`, kein `header()`.
- Kein Zugriff auf `$_POST`, `$_COOKIE`, `getVar()`, `getCookie()`.
- Funktionskommentar mit `@param` und `@testdata` (Pflicht, API-Tester).

Damit ist jede Funktion von beiden Zugängen aufrufbar und einzeln prüfbar.

## 3. Kontextauflösung

Der zehnzeilige Vorspann

    $hugoshop_context = getCookie(CONTEXT_COOKIE, null);
    if(null == $hugoshop_context){ resultInfo(false, "SHOP_CONTEXT_ERROR", …); return; }
    $pdo = connectPDO();
    $context = shopContextRow($pdo, $hugoshop_context);
    if(!$context) { resultInfo(false, "SHOP_CONTEXT_ERROR", …); return; }

steht heute wortgleich in siebzehn Funktionen von `shop.account.php`. Er
gehört genau einmal in die Aktionsschicht des Kundenzugangs:

    shopContext($db, $uuid) : array{uuid, cart_uuid, customer_id, guest}

Die Fachschicht sieht davon nichts — sie bekommt `customer_id` und
`cart_uuid` als Werte.

**Der PayPal-Rückweg ist der Beweis, dass das trägt.** `endPayment()` läuft
ohne Kontext-Cookie, weil der Kunde von der PayPal-Domain zurückkommt; die
Kontext-UUID reicht PayPal als `reference_id` durch. Genau dafür gibt es
heute den Parameter `$referenceId`, der durch `getCart()`, `inCart()`,
`addShippingCosts()`, `deleteCart()`, `getCustomerId()`, `isGuest()`,
`invoicing()` und `registerAccount()` gereicht wird. Dieser Parameter ist die
halbfertige Entkopplung — beim Portieren wird er vom Sonderfall zum Regelfall.

Das Admin-Panel hat keinen Kontext. Der Mitarbeiter nennt `customer_id` bzw.
`ar_id` als Parameter der Aktion.

## 4. Aktionen des Kundenzugangs

Die Spalte „Fachfunktion" nennt den Namen nach dem Umbau. Aktionsnamen bleiben,
wo sie stehen — sie sind die Schnittstelle zum `shop-ui`.

### Sitzung und Kontext (`shop.session.php`)

| Aktion | Fachfunktion | Anmerkung |
| --- | --- | --- |
| `getContext` | `shopContextStatus($db, $uuid)` | legt das Cookie an; einziger Ort, der `setcookie()` ruft |
| `shopLogin` | `shopLogin($db, $uuid, $email, $password)` | **umbenannt**, siehe 7. |
| `shopLogout` | `shopLogout($db, $uuid)` | **umbenannt**, siehe 7. |
| `getProductLink` | `productLink($db, $parts_id)` | reine Nachschlagefunktion, braucht keinen Kontext |

`shopLogin` enthält die Warenkorbzusammenführung (Gastkorb in Kundenkorb,
Positionen addieren). Die bleibt fachlich unverändert, wandert aber nach
`cartMerge($db, $von_uuid, $nach_uuid)` — sie ist auch vom Admin-Panel her
sinnvoll, wenn zwei Konten zusammengelegt werden.

### Warenkorb (`shop.cart.php`)

| Aktion | Fachfunktion |
| --- | --- |
| `getCart` | `cartRead($db, $cart_uuid, $customer_id, $simple, $ohneVersand)` |
| `inCart` | `cartAdd($db, $context, $parts_id, $menge)` |
| `deleteCartPos` | `cartRemovePos($db, $cart_uuid, $customer_id, $pos_id)` |
| `changeQuantity` | `cartSetQuantity($db, $cart_uuid, $customer_id, $pos_id, $menge)` |
| — | `cartTotals($db, $cart_uuid, $precision, $taxzone_id)` |
| — | `cartApplyShipping($db, $cart_uuid, $customer_id)` |
| — | `cartClear($db, $cart_uuid)` |

`cartAdd` braucht den ganzen Kontext, weil sie den Warenkorb anlegt und an den
Kontext hängt, wenn noch keiner existiert. Alle anderen kommen mit
`cart_uuid` aus.

`cartRemovePos` und `cartSetQuantity` führen `cart_uuid` in der Bedingung mit —
ohne sie trifft eine fremde `pos_id` einen anderen Warenkorb. Das steht in der
Bridge bereits richtig und darf beim Umschreiben nicht verloren gehen.

### Konto (`shop.account.php`)

| Aktion | Fachfunktion |
| --- | --- |
| `registerAccount` | `customerRegister($db, $uuid, array $daten) : array{customer_id, shipto_id}` |
| `accountAddresses` | `customerAddresses($db, $customer_id)` |
| `updateAddress` | `customerUpdateBillingAddress($db, $customer_id, $adresse)` |
| `newDeliveryAddress` | `shiptoCreate($db, $customer_id, $adresse)` |
| `getDeliveryAddress` | `shiptoRead($db, $customer_id, $shipto_id)` |
| `updateDeliveryAddress` | `shiptoUpdate($db, $customer_id, $shipto_id, $adresse)` |
| `removeDeliveryAddress` | `shiptoDelete($db, $customer_id, $shipto_id)` |
| `standardDeliveryAddress` | `shiptoSetDefault($db, $customer_id, $shipto_id)` |
| `takeBillAddress` | `shiptoClearDefault($db, $customer_id)` |
| `personalOverview` | `customerOverview($db, $customer_id, $sprache)` |
| `personalProfil` | `customerProfile($db, $customer_id, $sprache)` |
| `updatePersonalProfil` | `customerUpdateProfile($db, $customer_id, $daten)` |
| `personalPayment` | `customerPaymentTerms($db, $customer_id, $sprache)` |
| `changePaymentMethod` | `customerSetPaymentTerm($db, $customer_id, $payment_id)` |
| `updateEmail` | `customerUpdateEmail($db, $customer_id, $email, $password)` |
| `updatePassword` | `customerUpdatePassword($db, $customer_id, $alt, $neu)` |
| `getAccountSelections` | `salutations($db, $sprache)` |
| `billingAndShipping` | `checkoutAddresses($db, $context, $sprache)` |
| `contactInit` | `contactFormDefaults($db, $customer_id, $sprache)` |

Jede Funktion, die eine `shipto_id` entgegennimmt, prüft die Eigentümerschaft
über `shipto.trans_id` — die Bridge tut das bereits (`shiptoBelongsToCustomer`,
`shop.account.php:1105`) und begründet in den Kommentaren, warum. Beim
Portieren gehört diese Prüfung in die Fachfunktion, nicht in die
Aktionsschicht: sonst hängt sie am Zugang statt am Vorgang.

`customerRegister` verliert den Parameter `$echoResult`. Es gibt ihn nur, weil
die Funktion mitten in `endPayment()` gerufen wird und dort nichts ausgeben
darf; eine Fachfunktion, die grundsätzlich nichts ausgibt, braucht ihn nicht.

`salutations()` braucht weder Kontext noch Kunde und ist deshalb auch für das
Admin-Panel brauchbar.

### Bestellung und Rechnung (`shop.account.php`)

| Aktion | Fachfunktion | Anmerkung |
| --- | --- | --- |
| `invoicing` | `createShopInvoice($db, $context, $guest, $adressen, $paypal)` | Kern, siehe 5. |
| `personalOrders` | `customerInvoices($db, $customer_id)` | |
| `personalOrder` | `customerInvoice($db, $customer_id, $ar_id)` | |
| `getInvoiceSummary` | `invoiceSummaryByLink($db, $ar_link)` | der Link ist das Geheimnis, kein Kontext nötig |
| `downloadInvoice` | `invoicePdf($db, $ar_id, $customer_id)` | |
| `downloadInvoiceLink` | `invoicePdfByLink($db, $ar_link)` | |

`customerInvoice()` und `invoicePdf()` bekommen `customer_id` als Parameter und
prüfen damit die Zugehörigkeit — heute erledigt das `getInvoice($id,
$customerId)`, und `createInvoicePDF()` verlässt sich darauf, dass diese
Prüfung vorher gelaufen ist. Ein Kommentar in `shop.account.php:537` beschreibt
genau diese Abhängigkeit von der Reihenfolge zweier Anweisungen. Nach dem Umbau
trägt jede Funktion die Prüfung selbst.

### Zahlung (`shop.payment.php`, `payment.paypal.php`)

| Aktion | Fachfunktion | Anmerkung |
| --- | --- | --- |
| `checkout` | — | Alias auf `getCart`, entfällt |
| `beginPayment` | `paymentBegin($db, $context, $bill, $canceled) : string` | gibt den Weiterleitungsziel zurück, leitet nicht selbst weiter |
| `endPayment` | `paymentEnd($db, $token) : array{ar_link, …}` | ohne Cookie, Kontext über `reference_id` |
| `paymentCanceled` | — | reine Weiterleitung, bleibt Aktionsschicht |

`PayPalConnector` ist eigenständig und wandert weitgehend unverändert; nur die
Zugangsdaten kommen künftig aus `defaults_oserp` statt aus `passwd.php`.

Die `header('Location: …')`-Aufrufe gehören in die Aktionsschicht. Die
Fachfunktion gibt das Ziel zurück — sonst ist der Ablauf vom Admin-Panel aus
nicht nachvollziehbar und nicht prüfbar.

### Suche (`shop.search.php`)

`fastSearch`, `moreSearchResults` und `fullSearch` unterscheiden sich
ausschließlich in Limit und Offset (5/0, 11/0, 30/11). Eine Fachfunktion:

    shopSearch($db, $begriffe, $limit, $offset) : array

Die drei Aktionsnamen bleiben und setzen nur die Zahlen. Die Schnittstelle
`KiviShopSearchInterface` samt `HUGOSHOP_SEARCH_MODULE` erlaubt heute, die
Suche gegen eine andere Umsetzung zu tauschen; das ist beibehaltenswert, gehört
aber an den Erweiterungsmechanismus statt an eine Konstante aus `config.php`.

### Auswertung (`shop.googletag.php`)

| Aktion | Fachfunktion |
| --- | --- |
| `gtmGetProductInfo` | `analyticsProduct($db, $parts_id)` |
| `gtmGetPurchased` | `analyticsPurchase($db, $ar_link)` |
| `gtmGetPurchasedProducts` | `analyticsPurchaseItems($db, $ar_link)` |

Alle drei arbeiten über den Rechnungslink, nicht über den Kontext.
`gtmGetPurchasedProducts` ruft heute zusätzlich `getPurchased()` und filtert
den Versandartikel aus der Positionsliste — das gehört in eine Abfrage.

### Mail und Widerruf (`shop.mail.php`, `shop.widerruf.php`)

| Aktion | Fachfunktion | Anmerkung |
| --- | --- | --- |
| `sendContactMail` | `sendContactMail($db, $daten)` | über OSERP-SMTP |
| `submitWiderruf` | `submitWithdrawal($db, $daten)` | Protokoll gehört in eine Tabelle, nicht in eine Logdatei |

`sendInvoiceMail()` ist keine Aktion, sondern wird aus der Rechnungserstellung
gerufen.

Der Widerruf schreibt heute eine JSON-Zeile nach `KIVI_WIDERRUF_LOG_FILE`
(`shop.widerruf.php:16`) und verschickt zwei Mails. Ein Vorgang, der aus
gesetzlichen Gründen nachweisbar sein muss, gehört in die Datenbank —
`withdrawals_shop` mit denselben Feldern, plus Anzeige im Admin-Panel. Die
Mailvorlagen unter `mail-templates/` wandern mit.

## 5. `createShopInvoice` — der Kern

`invoicing()` (`shop.account.php:95`, rund 200 Zeilen) macht heute fünf Dinge
in einer Transaktion: Versandkosten ergänzen, Lieferadresse anlegen, `ar`
schreiben, `invoice` je Position in einer PHP-Schleife schreiben,
Buchungssätze in `acc_trans` aufbauen und schreiben, `ar_link_hugoshop`
schreiben.

Nach dem Umbau bleibt davon:

| Schritt | künftig |
| --- | --- |
| Versandkosten | `cartApplyShipping()` |
| Lieferadresse | `shiptoCreate()` — dieselbe Funktion wie im Konto |
| `ar` + `invoice` | `createFakturaCore()` / `createFakturaItemCore()` (`backend/api/faktura/faktura.php:431`, `:656`) |
| `acc_trans` | `postArInvoiceToLedger()` (`backend/api/faktura/faktura.php:1227`) |
| `ar_link_hugoshop` | bleibt, gehört zur Erweiterung |
| Warenkorb leeren | `cartClear()` |

Damit schrumpft die Funktion auf einen Ablauf, der vorhandene Bausteine
aneinanderreiht. Drei Punkte sind dabei zu klären:

1. **Rechnungsnummer.** Die Bridge zählt `defaults.invnumber` in einem CTE
   selbst hoch. OSERP hat dafür `getNumberConfig()` und `nextFreeNumber()`
   (`backend/api/database.php:796`). Der OSERP-Weg gilt.
2. **Nettobetrag.** `netamount = totalSum / 1.19` ist fest verdrahtet und
   falsch, sobald eine Position mit 7 % im Korb liegt. `postArInvoiceToLedger()`
   rechnet ohnehin über die Steuerschlüssel der Artikel — der feste Teiler
   entfällt ersatzlos.
3. **Positionen einzeln.** Die Schleife über `INSERT INTO invoice` widerspricht
   „ein Ajax-Call, eine DB-Abfrage". `createFakturaItemCore()` legt heute
   ebenfalls je Position an; für den Shop ist ein `INSERT … SELECT` aus
   `cart_parts_hugoshop` der richtige Weg, weil die Positionen bereits in der
   Datenbank stehen und gar nicht durch PHP müssen.

## 6. Aktionen des Admin-Panels

Neu, ohne Vorlage in der Bridge — bis auf die beiden Zahlungsfunktionen, die
vorhanden und geprüft sind, aber nie aufgerufen werden.

| Aktion | Fachfunktion | Herkunft |
| --- | --- | --- |
| `getShopOrders` | `shopOrders($db, $filter)` | neu |
| `getShopOrder` | `shopOrderDetail($db, $ar_id)` | neu |
| `getPendingPayments` | `paymentsPending($db, $limit)` | vorhanden: `pendingPayments()` |
| `applyPaymentState` | `paymentApplyState($db, $uuid, $payment)` | vorhanden: `applyPaymentState()` |
| `reconcilePayments` | ruft beide über `PayPalConnector` | heute `payment/reconcile.php` (CLI) |
| `getPartShopData` | `partsExtRead($db, $parts_id)` | neu |
| `savePartShopData` | `partsExtSave($db, $parts_id, $daten)` | neu |
| `getShopRedirects` | `redirectsList($db)` | neu |
| `saveShopRedirect` | `redirectSave($db, $daten)` | neu |
| `deleteShopRedirect` | `redirectDelete($db, $id)` | neu |
| `getShopBatchjobs` | `batchjobsList($db)` | neu |
| `enqueueShopBatchjob` | `batchjobEnqueue($db, $funktion, $partnumber, $param)` | neu |
| `getWithdrawals` | `withdrawalsList($db, $filter)` | neu (siehe 4., Widerruf) |

Alle mit `permit()` abgesichert. Vorschlag für die Rechte: `shop_view`
(lesen), `shop_edit` (Artikeldaten, Weiterleitungen, Batchjobs),
`shop_payment` (Zahlungsstände nachtragen). Die Shop-Einstellungen laufen über
den bestehenden `defaults_oserp`-Mechanismus und dessen Rechte.

Die Kundenverwaltung des Shops braucht keine eigenen Aktionen: ein Shop-Kunde
ist ein `customer`, also zuständig ist `backend/api/customer_vendor/`.

## 7. Namenskollision

Von 55 Bridge-Funktionen kollidieren genau zwei mit OSERP:

| Bridge | OSERP | Auflösung |
| --- | --- | --- |
| `login` (`shop.session.php:219`) | `login` (`backend/api/auth.php:84`) | `shopLogin` |
| `logout` (`shop.session.php:227`) | `logout` (`backend/api/auth.php:369`) | `shopLogout` |

Ohne Umbenennung bricht PHP beim Laden ab („Cannot redeclare"). Die
Aktionsnamen im `shop-ui` ziehen mit (`shop-ui/src/core/api.js` und die beiden
Aufrufstellen).

## 8. Der öffentliche Einstiegspunkt

`backend/api/api.call.php:16` prüft nur `function_exists($action)`. Über
`backend/api/inc.php` ist `auth.php` immer geladen — damit wären `login`,
`logout`, `getClients`, `restoreSession` und `switchClient` in jedem Modul
aufrufbar.

Der Kundenzugang darf `backend/api/inc.php` deshalb nicht einbinden. Er
bekommt einen eigenen Vorspann (Konfiguration, Protokollierung, Datenbank) und
eine Allowlist:

    $ERLAUBT = ['getContext', 'shopLogin', 'shopLogout', 'getProductLink', …];
    if(!in_array($action, $ERLAUBT, true)) { … }

Die Liste ist die Zugangsgrenze, nicht `function_exists`. Sie steht an einer
Stelle und ist damit prüfbar — eine neue Fachfunktion wird nicht dadurch
öffentlich, dass jemand sie einbindet.

Die Mandantenauflösung folgt `backend/webhook/telegram.php`: Shop-Schlüssel
aus `defaults_oserp`, `hash_equals`, dann Verbindung zur Company-Datenbank.
Dafür muss `DbhCompany::begin($pdo)` den übergebenen Parameter auch verwenden
(`backend/api/database.php:735` ignoriert ihn heute).

## 9. Antwortformat

Der Kundenzugang liefert heute keinen einheitlichen Rahmen: im Fehlerfall
`{success:false, text:"<CODE>"}`, sonst die Nutzdaten direkt. So steht es im
Kopfkommentar von `shop-ui/src/core/api.js`, und `send()` gibt entsprechend
`data` zurück.

OSERP verwendet `resultInfo($success, $text, $data, $debug)` mit den Nutzdaten
unter `payload`. Zwei Formate im selben Modul wären eine dauerhafte
Fehlerquelle. Empfehlung: auch der Kundenzugang antwortet im OSERP-Format; im
`shop-ui` ändert sich dafür eine Zeile:

    return data.payload ?? data;

Die Fehlerauswertung (`data.success === false`, `data.text` als Code) passt
bereits.

Achtung beim Portieren: die Bridge-Signatur ist
`resultInfo($success, $text, $debug)` — das dritte Argument ist dort die
Debug-Angabe, in OSERP sind es die Nutzdaten. Jeder der rund 30 Aufrufe muss
einzeln angesehen werden.

## 10. Was beim Portieren nicht mitwandern sollte

1. **`registerAccount`, Passwortzuweisung** (`shop.account.php:896`):

       $guest = isset($_POST['guest'])? $_POST['guest'] : 'false';
       $hashedPassword = ('false' == $guest)? null : password_hash($_POST['password'], PASSWORD_DEFAULT);

   Sieht vertauscht aus: ein registriertes Konto (`guest = 'false'`) bekommt
   `user_password = NULL`, eine Gastbestellung einen Hash über ein Feld, das
   bei Gastbestellungen nicht gesetzt ist. Ein Konto mit `NULL` kommt durch
   `shopLogin` nicht mehr hindurch. Vor dem Portieren gegen das laufende
   System prüfen.

2. **`getTotalSum`** (`shop.cart.php:159`): in zwei Zeilen steht `'0.01000'`
   fest statt `:precision`, während dieselbe Abfrage den Parameter an anderer
   Stelle verwendet.

3. **`personalOrders`** (`shop.account.php:638`) bindet `:kivi_data_format`,
   obwohl der Platzhalter in der Abfrage nicht vorkommt.

4. **`newDeliveryAddress`** (`shop.account.php:1067`): `$module` wird nach
   `bindParam()` gesetzt (funktioniert über die Referenz, ist aber nicht
   lesbar), und `lastInsertId()` steht ohne Sequenznamen — an anderer Stelle
   ist dasselbe Problem bereits durch `RETURNING` gelöst.

5. **Versandartikel `partnumber = '8'`** und
   **`KIVI_ZERO_SIPPING_COSTS_FROM`**: gehören nach `defaults_oserp`, nicht in
   Schema und Konstante. (Der Tippfehler „SIPPING" verschwindet dabei.)

6. **`KiviBridgeStatement::buildQuery()`** (`framework/inc.php:218`) setzt
   Werte per `pdo->quote()` in den SQL-Text ein. Ersatz durchgängig:
   `$db->getOne()`, `$db->getAll()`, `$db->execute()`. Nicht `get()` — das
   verträgt keine benannten Parameter.

## 11. Reihenfolge

1. `backend/upstall/shop/` — Schema (nur `ADD COLUMN IF NOT EXISTS` für
   `customer_ext`, kein `DROP TABLE`) und `extension.json`
2. Einstellungen nach `defaults_oserp`, `DbhCompany::begin($pdo)` reparieren
3. Fachschicht: Kontext, Warenkorb, Konto, Suche — Signaturregel aus 2.
4. Beide Einstiegspunkte, Allowlist, `shopLogin`/`shopLogout`
5. Rechnung und Zahlung auf Faktura, Print und E-Mail von OSERP
6. `src/features/shop/` — Admin-Panel, Routen in allen 21 Sprachen
7. `shop-ui` auf den Rahmen aus 9. umstellen, Proxy einrichten

Nach Schritt 5 ist der Shop lauffähig, nach Schritt 6 verwaltbar.
