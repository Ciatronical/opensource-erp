---
title: Gutmann-Diagnose
summary: Hella Gutmann mega macs als Seite in LxCars — in den Farben von OSERP, in jedem Browser
group: extension
extension: lxcars
category: Werkstatt
order: 104
status: beta
flag: feature_gutmann
---

# Gutmann-Diagnose — mega macs in LxCars

Das Diagnosegerät **Hella Gutmann mega macs X** hat keine eigene Anzeige, sondern eine Weboberfläche („SDI"), die das Gerät selbst ausliefert. Mit diesem Feature erscheint sie als Seite in LxCars unter der normalen Navigation — ohne eigenes Fenster, ohne Gerätename oder Port, und eingefärbt wie OSERP (Hell und Dunkel). Sie läuft dort auch in Firefox und Safari, die das Gerät sonst mit einer Warnseite abweist.

## Kern

- **Menüpunkt Fahrzeuge → Gutmann**, Knopf **Gutmann** in der Fahrzeugmaske und in der Fahrzeugkarte des Auftrags (sobald ein Fahrzeug gewählt ist); ohne Schalter erscheint nichts davon
- Oberfläche des Geräts in OSERP-Farben (Primärfarbe, Flächen, Ränder, Radien, Schrift), Hell/Dunkel folgt dem OSERP-Modus live
- Läuft in jedem Browser — die Chrome-Sperre des Geräts greift nicht mehr
- Klare Hinweise statt leerem Rahmen: Gerät aus, Server verweigert, Weiterleitung fehlt — dazu ein **Notfallknopf**, der das Gerät direkt (`http://<adresse>:8889/`) in einem neuen Tab öffnet; die Adresse liefert Apache als Header `X-Gutmann-Device`
- „In neuem Fenster" für einen zweiten Bildschirm am Arbeitsplatz
- Von überall erreichbar, wo auch OSERP erreichbar ist (Tablet in der Werkstatt, Handy, Homeoffice) — aber **nur nach OSERP-Anmeldung**; der Rahmen nutzt die volle Fensterbreite
- Fahrzeuge kommen wie bisher über **asanetwork/NETMAN** ins Gerät: offene Aufträge stehen dort als Auftragsliste, das Fahrzeug ist vorgewählt

## Einrichtung

1. mega macs mit dem Werkstattnetz verbinden und in der Fritz!Box (oder dem Router) eine **feste IP-Adresse** vergeben, damit die Weiterleitung stabil bleibt. Die Oberfläche meldet sich unter `http://<adresse>:8889/`.
2. Einmalig auf dem Server:
   ```
   sudo ./install/gutmann-proxy.sh 192.168.178.217:8889
   ```
   Das Skript schaltet die Apache-Module frei, legt `/etc/apache2/oserp-gutmann.conf` an, trägt die Adresse in `oserp-defines.conf` ein und bindet alles in den HTTPS-vHost ein. Bei neuer Adresse einfach erneut ausführen.
3. In OSERP unter **Firmenkonfiguration → Features → Werkstatt** die **Gutmann-Diagnose** einschalten.

## Wie es funktioniert

Apache reicht `/gutmann/` an das Gerät durch (HTTP und WebSocket) und hängt zwei Dateien in dessen Startseite: `public/gutmann-bridge.js` und `public/gutmann-bridge.css`.

- **Browser-Kennung**: Das Gerät prüft den User-Agent (nur Chrome/Chromium ≥ 69, Edge ≥ 80). Die Brücke spielt eine Chrome-Kennung vor — die Oberfläche ist normales React und läuft überall.
- **WebSocket**: Das Gerät spricht fest `ws://<host>:<port>`. Aus einer HTTPS-Seite ist das verboten; die Brücke biegt die Verbindung auf `wss://<server>/gutmann/ws` um, Apache reicht sie weiter.
- **Farben**: Die Oberfläche ist komplett über CSS-Variablen gefärbt (`--hgs-sys-color-*`). Die Brücke setzt sie auf die Werte aus `src/main.js` und `tokens.css`. LxCars gibt den Modus beim Laden (`?oserp=dark`) und bei jedem Wechsel per `postMessage` mit.

- **Zugang**: Das Gerät hat keine eigene Anmeldung. Apache lässt `/gutmann/` nur mit dem Cookie `oserp_gutmann` durch, dessen Wert das Geheimnis `OSERP_GUTMANN_TOKEN` aus `oserp-gutmann-defines.conf` ist (erzeugt das Skript einmalig). Gesetzt wird das Cookie von der API-Aktion `getGutmannAccess` — und die läuft wie jede Aktion nur mit gültiger OSERP-Sitzung. HttpOnly, 12 Stunden, bei jedem Öffnen der Seite erneuert. Wer `https://<server>/gutmann/` ohne Anmeldung aufruft, bekommt 403.

Aufbau, Symbole und Texte bleiben die des Geräts — es wird nur eingefärbt. Ein Nachbau der Oberfläche in Vuetify ist nicht sinnvoll: Das Gerät steuert jede Ansicht über ein eigenes Diff-Protokoll auf dem WebSocket.

## Grenzen

- Das Zugangs-Cookie gilt 12 Stunden auch nach einem OSERP-Abmelden; ein neues Geheimnis (alle aussperren) gibt es durch Löschen der Zeile `OSERP_GUTMANN_TOKEN` in `oserp-gutmann-defines.conf` und erneuten Skriptlauf.
- Eine direkte Fahrzeugübergabe aus der Fahrzeugmaske gibt es nicht; dafür ist asanetwork da.
- Firmware-Updates des Geräts können Variablennamen ändern; dann passt `gutmann-bridge.css` nachziehen (Stand: SDI v79.209).

## Dateien

- `install/gutmann-proxy.sh`, `install/oserp-gutmann.conf` — Apache-Weiterleitung und Zugangsprüfung
- `backend/api/lxcars/gutmann.php` — `getGutmannAccess` setzt das Zugangs-Cookie
- `public/gutmann-bridge.js`, `public/gutmann-bridge.css` — Brücke im Dokument des Geräts
- `src/features/lxcars/views/gutmann/` — Seite in LxCars
- Schalter `feature_gutmann` (Store: `isGutmannEnabled()`), Route `gutmann`
