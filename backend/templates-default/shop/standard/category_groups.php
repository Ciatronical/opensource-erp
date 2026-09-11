<?php
/**
 * Regeln für die Kategorieübersicht
 *
 * Aus ihnen erzeugt der Läufer die Datendatei, die theme.json unter
 * data.category_groups nennt (lib/categories.php). Die Seiten der einzelnen
 * Kategorien erzeugt Hugo selbst aus der Taxonomie.
 *
 * Ein eigener Vorlagensatz legt eine Datei gleichen Namens an und nennt nur
 * die Angaben, die er ändert; die übrigen kommen von hier.
 *
 * taxonomy                Name der Taxonomie im Front Matter der Produktseite
 *                         und Anfang der Kategorie-Adresse
 * labels                  Wortteil => Gruppe. Wortteile klein geschrieben, mit
 *                         aufgelösten Umlauten (ä → ae, ß → ss). Mehrere
 *                         Wortteile dürfen auf dieselbe Gruppe zeigen, z.B.
 *                         'zange' => 'Zangen', 'schneider' => 'Zangen'.
 *                         Bei zusammengesetzten Wörtern gewinnt der Wortteil,
 *                         der am weitesten vorn steht, über mehrere Wörter der
 *                         längste.
 * topseller               Kategorien, die hervorgehoben oben stehen, in dieser
 *                         Reihenfolge
 * stop_words              Wörter ohne Aussage, die nie eine Gruppe bilden
 * min_cluster_size        kleinere Gruppen wandern in die Restgruppe
 * fallback_min_frequency  ohne passenden Wortteil: ein Wort bildet eine Gruppe,
 *                         wenn es in so vielen Kategorien vorkommt
 * fallback_max_clusters   höchstens so viele solcher Gruppen
 * rest_name               Name der Restgruppe
 */
return [
    'taxonomy'  => 'kategorien',
    'labels'    => [],
    'topseller' => [],
    'stop_words' => [
        'und', 'oder', 'mit', 'fuer', 'von', 'zur', 'zum',
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'eines',
        'auf', 'aus', 'bei', 'bis', 'nach', 'vor', 'ueber', 'unter',
        'als', 'wie', 'nicht', 'nur', 'auch', 'noch', 'schon',
        'typ', 'art', 'set', 'pro',
    ],
    'min_cluster_size'       => 3,
    'fallback_min_frequency' => 5,
    'fallback_max_clusters'  => 15,
    'rest_name'              => 'Sonstiges',
];
