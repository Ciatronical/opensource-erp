---
title: Kalender
summary: Terminplanung mit Echtzeit-Updates und Wall-Display
group: core
category: Organisation
order: 70
status: stable
---

# Kalender — Terminverwaltung

Zentrale Terminplanung mit Tages-, Wochen- und Monatsansicht.

## Funktionen

- **Termine erstellen**: Titel, Datum, Uhrzeit, Ganztags-Option
- **Kategorien**: Farbliche Zuordnung nach Kategorie
- **Suche**: Termine nach Text filtern
- **Echtzeit-Updates**: Änderungen anderer Benutzer werden per SSE sofort angezeigt
- **Wall-Display**: Kalender kann auf einem Wandbildschirm im Büro angezeigt werden (Tages- oder Wochenansicht, automatisch aktualisierend)

## Wall-Display

Das Wall-Display zeigt den Kalender im Vollbildmodus — ideal für einen Monitor im Eingangsbereich oder Besprechungsraum. Der Bildschirm aktualisiert sich automatisch per Server-Sent Events.

Zugriff: `/wall-display` im Browser öffnen.

### Beleg auf der Wandanzeige zeigen

In jeder Faktura-Maske (Angebot, Auftrag, Rechnung) gibt es in der Aktionsleiste den Knopf **Auf Display zeigen** (sichtbar, wenn die Wandanzeige in der Firmenkonfiguration eingeschaltet ist). Ein Klick schaltet die Wandanzeige vom Kalender auf den Beleg um: Kundenname, Belegnummer, Fahrzeug (LxCars) und alle Positionen mit Gesamtbetrag — als Kundenpräsentation am Tresen. Änderungen am Beleg erscheinen dort sofort, die Anzeige lädt per SSE nach.

- **Lange Belege** bricht die Wandanzeige selbst in Seiten um, so dass nichts gescrollt werden muss; oben steht „Seite 2 / 3“. Geblättert wird vom PC aus.
- **Steuerleiste am PC**: Solange der Beleg gezeigt wird, steht unten in der Faktura eine Leiste mit *Vorherige Seite*, *Nächste Seite* und *Anzeige beenden*. Die Faktura bleibt dabei bearbeitbar.
- **Zurück zum Kalender**: beim Beenden über die Leiste oder den Knopf, beim Verlassen der Faktura-Seite (auch Tab schließen) und beim Wechsel auf einen anderen Beleg — die Anzeige zeigt dann wieder den Kalender und holt eine zwischenzeitlich vom PC-Kalender gesendete Ansicht nach.

Technik: `setWallDisplayFaktura` (Kalender-API) sendet `pg_notify('wall_display_command')` mit `mode: faktura|calendar`; die Beschränkung auf *Wandanzeige-Steuerung* (`wall_display_controllers`) gilt nur für das Mitblättern des Kalenders, nicht für diesen ausdrücklichen Knopf.
