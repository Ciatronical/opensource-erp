<?php
// backend/shop/index.php
//
// Oeffentlicher Einstiegspunkt der Shop-Erweiterung — der Zugang der
// Shop-Webseite und damit der Kunden des Betreibers. Liegt bewusst neben
// backend/webhook/ und nicht unter backend/api/: alles unter api/ laedt
// ueber inc.php auch auth.php, und ueber den action-Mechanismus waeren dann
// login, logout, getClients, restoreSession und switchClient von hier aus
// erreichbar.
//
// Der Mitarbeiter-Zugang derselben Erweiterung liegt unter
// backend/api/shop/index.php und arbeitet mit der normalen Anmeldung.
//
// EINRICHTUNG
// Die Shop-Webseite weist sich mit dem Kopf X-Shop-Key aus. Den setzt
// sinnvollerweise der Reverse-Proxy im Docroot des Shops, damit der Browser
// den Schluessel nie sieht:
//
//   location /shop-api/ {
//       proxy_pass         https://erp.example/shop/;
//       proxy_set_header   X-Shop-Key <Wert aus den Shop-Einstellungen>;
//       proxy_set_header   Cookie $http_cookie;
//   }
//
// Laeuft die Shop-Webseite stattdessen unter einer eigenen Adresse direkt
// gegen diesen Einstiegspunkt, muss sie in den Shop-Einstellungen unter
// "Erlaubte Herkunftsadressen" stehen.

require_once __DIR__.'/../api/shop/public/bootstrap.php';
require_once __DIR__.'/../api/shop/public/actions.php';

shopPublicDispatch(shopPublicActions());
