# WordPress Local SEO & Entity Content Plugin
## Technische Spezifikation v0.2

**Status:** Architekturentwurf konsolidiert  
**Entwicklung:** Noch nicht begonnen  
**Architekturansatz:** Modulares WordPress-Plugin mit eigener Entity-Datenhaltung und serverseitiger Aufgabenverarbeitung

### 1. Produktkern

Das Plugin recherchiert lokale SEO-Themen, erkennt und verknüpft relevante Entitäten und erstellt daraus hochwertige WordPress-Beiträge. Es ist für eine einzelne WordPress-Installation ausgelegt und enthält keine zentrale Multi-Site-Verwaltung.

Die wichtigsten Module sind:

1. Konfiguration und Website-Profil
2. Entity-Erkennung und Entity-Modell
3. Recherche und Themenplanung
4. Content-Erstellung und globaler Style Mimic
5. Qualitäts- und Faktenprüfung
6. Medien und Bildgenerierung
7. WordPress- und Rank-Math-Integration
8. Aufgabensteuerung und Zeitplanung
9. Google- und weitere SEO-Datenintegrationen
10. Dashboard, Berichte und Protokollierung

### 2. Datenhaltung

Reguläre WordPress-Beiträge bleiben das führende System für erzeugte Inhalte. Die Plugin-Konfiguration wird über WordPress verwaltet. Für Entitäten, Beziehungen, Quellen, Belege, Fundstellen, Themenvorschläge und Workflow-Informationen werden eigene Datenbanktabellen als bevorzugter Ansatz geprüft.

Entitäten erhalten interne IDs. Beziehungen werden strukturiert gespeichert. Neue Entitäten und Beziehungen werden zunächst als Kandidaten erfasst und bei Bedarf durch den Administrator bestätigt. Quellen und widersprüchliche Angaben bleiben nachvollziehbar.

### 3. Verarbeitung

Ein selbst eingerichteter Server-Cronjob stößt die Hintergrundverarbeitung an. Die bevorzugte Architektur verwendet eine Aufgabenwarteschlange, während das Plugin den fachlichen Workflow selbst steuert.

Jede Aufgabe erhält einen dauerhaften Status, nachvollziehbare Zwischenergebnisse und definierte Wiederaufnahmebedingungen. Fehlversuche werden begrenzt wiederholt. Doppelte Beitragserstellung und doppelte Veröffentlichung müssen verhindert werden.

### 4. KI-Anbindung

Zunächst wird ein festes KI-Modell für sprachliche Aufgaben verwendet. Eine interne KI-Schnittstelle kapselt Anbieterzugriff, Eingaben, Ergebnisvalidierung und Verbrauchserfassung.

Die Bildgenerierung wird über eine geeignete Schnittstelle ergänzt. Ob dafür derselbe Anbieter oder ein zusätzlicher Bilddienst verwendet wird, bleibt offen.

### 5. Content-Workflow

1. Neue lokale Themen recherchieren.
2. Relevante Entitäten und ihre Beziehungen ermitteln.
3. Bestehende Inhalte auf Themenüberschneidungen prüfen.
4. Ein Beitragsbriefing erstellen.
5. Den Beitrag mit dem globalen Style Mimic generieren.
6. Interne Links, Kategorien, SEO-Angaben und gegebenenfalls Bilder vorbereiten.
7. Qualität, Faktenbasis und Entity-Integration prüfen.
8. Den regulären WordPress-Beitrag als Entwurf bereitstellen.
9. Nach einer späteren ausdrücklichen Aktivierung gegebenenfalls automatisch und zeitgesteuert veröffentlichen.

Bestehende Beiträge werden analysiert, aber nicht automatisch umgeschrieben.

### 6. Veröffentlichungsregeln

Die erste Ausbaustufe arbeitet mit Entwürfen und manueller Prüfung. Nach einer definierten Testphase bewertet das Plugin die Qualität einer festgelegten Anzahl geprüfter Beiträge innerhalb eines festgelegten Zeitraums.

Erst wenn die vereinbarten Qualitätskriterien erfüllt sind, empfiehlt das Plugin den Wechsel in den Automatikbetrieb. Der Administrator aktiviert diesen ausdrücklich. Beiträge, die Pflichtprüfungen nicht bestehen, bleiben unveröffentlicht.

### 7. Technische Prüfpunkte

Vor der endgültigen Architekturfreigabe müssen folgende Punkte praktisch validiert werden:

- Kompatibilität der Aufgabenwarteschlange mit der vorgesehenen WordPress- und PHP-Version
- zuverlässige Ausführung über den eigenen Server-Cronjob
- persistenter Workflow-Status und sichere Wiederaufnahme
- Schutz vor doppelten Aufgaben und Beiträgen
- Datenbankmigrationen für eigene Plugin-Tabellen
- sichere Anbindung des festgelegten Text-KI-Modells
- Validierung strukturierter KI-Ergebnisse
- Erstellung eines regulären WordPress-Entwurfs
- Zugriffsschutz und sichere Speicherung von API-Schlüsseln

### 8. Noch offene technische Entscheidungen

Die tatsächliche Datenbankstruktur, die genaue Aufgabenwarteschlange, die Klassen- und Dateiaufteilung, der KI-Anbieter, der Bilddienst und die konkreten externen SEO-Datenanbieter sind noch nicht endgültig ausgewählt.

Diese Entscheidungen werden anhand der Ergebnisse des Machbarkeitstests und der geprüften Anforderungen getroffen.