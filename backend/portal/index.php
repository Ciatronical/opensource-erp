<?php
// backend/portal/index.php
//
// Öffentlicher Einstiegspunkt des Kundenportals — der Zugang der Webseite
// und damit der Kunden des Betreibers. Liegt bewusst neben backend/webhook/
// und nicht unter backend/api/: alles unter api/ lädt über inc.php auch
// auth.php, und über den action-Mechanismus wären dann login, logout,
// getClients, restoreSession und switchClient von hier aus erreichbar.
//
// Der Mitarbeiter-Zugang liegt unter backend/api/portal/index.php und
// arbeitet mit der normalen Anmeldung.
//
// Der frühere Einstieg backend/shop/ ist seit 2026-10-22 entfernt; die
// Adresse des Portal-Zugangs jeder Webseite zeigt hierher.
//
// EINRICHTUNG
// Die Webseite weist sich mit dem Kopf X-Portal-Key aus. Den setzt
// sinnvollerweise der Reverse-Proxy im Docroot der Webseite, damit der
// Browser den Schlüssel nie sieht:
//
//   location /portal-api/ {
//       proxy_pass         https://erp.example/portal/;
//       proxy_set_header   X-Portal-Key <Wert aus den Webseiten-Einstellungen>;
//       proxy_set_header   Cookie $http_cookie;
//   }
//
// Läuft die Webseite stattdessen unter einer eigenen Adresse direkt gegen
// diesen Einstiegspunkt, muss sie in den Webseiten-Einstellungen unter
// „Erlaubte Herkunftsadressen“ stehen.

require_once __DIR__.'/../api/portal/public/bootstrap.php';

portalPublicDispatch();
