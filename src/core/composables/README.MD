Vue
Composables sind Funktionen, die die Composition API von Vue nutzen, um zustandsbezogene (stateful) Logik zu kapseln und wiederverwendbar zu machen. Sie ermöglichen es, Logik, die nicht direkt an eine einzelne Komponente gebunden ist, in separaten, wiederverwendbaren Funktionen auszulagern, was zu saubererem und modularerem Code führt. 
Wichtige Merkmale

    Wiederverwendbarkeit: Sie sind der Schlüssel zur Wiederverwendung von Logik in verschiedenen Komponenten, ohne die Logik wiederholen zu müssen.
    Zustandsbezogen: Im Gegensatz zu reinen Hilfsfunktionen enthalten Composables reaktive Daten, die ihren Zustand ändern und verfolgen können.
    Funktionsbasiert: Die Wiederverwendung erfolgt über Funktionen, die die Composition API von Vue nutzen, wie z. B. ref und computed.
    Komposition: Sie sind eine zentrale Säule des Kompositionspatterns in Vue 3 und ermöglichen es, Funktionalitäten flexibel zu kombinieren.
    Flexibilität: Composables können Argumente entgegennehmen, um sie anpassbarer zu machen, und können sowohl einzelne Werte als auch Objekte zurückgeben. 

Beispiele für Anwendungsfälle

    Eine Funktion, die die Mausposition verfolgt (useMouse).
    Ein Zähler (useCounter), der einen Zählerstand verwaltet und Funktionen zum Erhöhen und Verringern bereitstellt.
    Eine Funktion zum Verwalten von Benutzeranmeldedaten oder anderen Daten aus einem API-Aufruf. 