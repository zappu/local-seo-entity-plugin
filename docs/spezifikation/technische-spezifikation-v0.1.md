# Technische Spezifikation v0.1
## WordPress Local SEO & Entity Content Plugin

**Projektstatus:** Architekturentwurf  
**Vorgehensmodell:** Grill-with-Docs  
**Implementierung:** Noch nicht begonnen  
**Architekturansatz:** Modulares, eigenständiges WordPress-Plugin

---

## 1. Architekturziele

Das Plugin soll die Recherche lokaler SEO-Themen, die KI-gestützte Identifikation und Verknüpfung von Entitäten sowie die Erstellung hochwertiger WordPress-Beiträge in einem durchgängigen Prozess verbinden.

Die Architektur muss folgende Anforderungen erfüllen:

- klare Trennung zwischen Entity-Management, Recherche, Content-Erstellung und Qualitätsprüfung
- strukturierte und nachvollziehbare Speicherung von Entitäten, Beziehungen, Belegen und Themen
- Verarbeitung länger laufender Aufgaben über einen selbst eingerichteten Server-Cronjob
- kontrollierte Wiederaufnahme unterbrochener Aufgaben
- zentrale Anbindung eines festgelegten Text-KI-Modells
- optionale Anbindung eines Bildgenerierungsdienstes
- Integration in WordPress und Rank Math
- zunächst manuelle Freigabe, später konfigurierbare automatische Veröffentlichung
- Erweiterbarkeit ohne grundlegende Neuentwicklung der gesamten Architektur

Es gibt keine zentrale Multi-Site-Anwendung. Jede WordPress-Installation verwaltet ihre eigenen Plugin-Daten und Inhalte.

## 2. Schichtenmodell

Die Anwendung wird logisch in vier Schichten aufgeteilt.

### 2.1 WordPress-Integrationsschicht

Diese Schicht stellt die Verbindung zu WordPress her. Sie ist verantwortlich für Beiträge, Kategorien, Medien, Einstellungen, Berechtigungen, Administrationsoberfläche und Rank-Math-Integration.

Sie enthält möglichst keine eigenständige Themen- oder Entity-Logik.

### 2.2 Anwendungsschicht

Diese Schicht koordiniert die Arbeitsabläufe. Sie verbindet Recherche, Entity-Erkennung, Themenplanung, Content-Erstellung und Qualitätsprüfung zu einer nachvollziehbaren Prozesskette.

Sie bestimmt, welcher Arbeitsschritt als Nächstes ausgeführt wird und welche Voraussetzungen dafür erfüllt sein müssen.

### 2.3 Fachliche Kernschicht

Die fachliche Kernschicht umfasst:

- Entity-Modell und Entity-Beziehungen
- Quellen und Faktenbelege
- lokale Themen- und Keyword-Recherche
- Themenplanung und Content-Cluster
- Style Mimic
- Beitragsgenerierung
- Qualitäts- und Faktenprüfung

Die Kernlogik soll möglichst unabhängig von konkreten KI-Anbietern und WordPress-Darstellungsdetails bleiben.

### 2.4 Infrastruktur und externe Schnittstellen

Diese Schicht verwaltet Datenbankzugriffe, KI-Aufrufe, externe Datenquellen, Aufgabenwarteschlange, Zeitplanung, Protokollierung und Fehlerbehandlung.

Die einzelnen Anbieter werden über klar definierte Schnittstellen angebunden. Die fachlichen Module sollen keine eigenen, voneinander unabhängigen Implementierungen derselben externen API enthalten.

## 3. Vorgesehene Modulstruktur

```text
local-seo-entity-content/
├── local-seo-entity-content.php
├── uninstall.php
├── src/
│   ├── Core/
│   ├── Admin/
│   ├── Configuration/
│   ├── Entity/
│   ├── Research/
│   ├── TopicPlanning/
│   ├── Content/
│   ├── Quality/
│   ├── Media/
│   ├── Publishing/
│   ├── Jobs/
│   ├── Integrations/
│   │   ├── AI/
│   │   ├── RankMath/
│   │   ├── SearchConsole/
│   │   ├── Analytics/
│   │   ├── BusinessProfile/
│   │   └── SEODataProviders/
│   └── Infrastructure/
│       ├── Database/
│       ├── Logging/
│       └── Security/
├── templates/
├── assets/
├── languages/
└── tests/
```

Dies ist eine vorgeschlagene logische Struktur. Die endgültige Ordner- und Klassenaufteilung wird bei der technischen Spezifikation anhand der gewählten Programmiersprache, Abhängigkeiten und Teststrategie präzisiert.

Die modulare Organisation entspricht den Empfehlungen des [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/plugin-basics/best-practices/) für größere Plugins.

## 4. Entity-Datenmodell

Das Entity-Modell ist die zentrale fachliche Datenbasis. Es wird für Themenfindung, Content-Planung und die Integration relevanter Entitäten in neue Beiträge verwendet.

### 4.1 Entitäten

Jede Entität benötigt eine interne eindeutige Identität. Wesentliche Angaben sind:

- interne ID
- kanonischer Name und Entitätstyp
- Beschreibung und relevante Eigenschaften
- alternative Namen und Schreibweisen
- gegebenenfalls externe Kennungen
- Bestätigungs- beziehungsweise Prüfstatus
- Zeitpunkt der Erfassung und letzten Überprüfung

Die Identität muss unabhängig von einem bestimmten externen Wissensbestand sein. Eine Entität kann also intern verwaltet werden, auch wenn kein externer Datenbankeintrag existiert.

### 4.2 Beziehungen

Beziehungen werden als eigenständige, gerichtete Verbindungen modelliert.

Beispiele:

- Unternehmen bietet Leistung an
- Unternehmen besitzt oder verwendet eine Marke
- Unternehmen befindet sich an einem Standort
- Standort bedient ein Servicegebiet
- Leistung gehört zu einem Fachthema
- Unternehmen steht in einer belegbaren Beziehung zu einer Organisation

Eine Beziehung enthält mindestens Ausgangsentität, Beziehungstyp, Zielentität und Status. Quellen und Belege müssen einer Beziehung zugeordnet werden können.

### 4.3 Fakten und Belege

Fakten und Entitäten dürfen nicht gleichgesetzt werden.

Eine Entität kann mehrere Aussagen über ihre Eigenschaften besitzen. Beispielsweise können unterschiedliche Quellen verschiedene Öffnungszeiten oder alternative Firmennamen nennen.

Jede relevante Tatsachenbehauptung benötigt deshalb nach Möglichkeit einen Beleg mit:

- Quelle und Fundstelle
- Zeitpunkt der Erfassung
- gegebenenfalls Gültigkeitszeitraum
- Prüfstatus
- Einschätzung der Belegqualität
- Verweis auf die zugehörige Entität oder Beziehung

Widersprüchliche Angaben werden nicht stillschweigend überschrieben. Sie bleiben nachvollziehbar, bis eine belastbare Entscheidung möglich ist.

### 4.4 Entitätsfundstellen

Fundstellen verbinden eine Entität mit ihrem tatsächlichen Auftreten auf der Website oder in externen Quellen.

Eine Fundstelle kann beispielsweise auf einen WordPress-Beitrag, eine Überschrift, einen Textabschnitt, Metadaten, strukturierte Daten oder eine externe Webseite verweisen.

Dadurch kann das Plugin erkennen, welche Entitäten bereits durch welche Inhalte abgedeckt sind und wo semantische Lücken bestehen.

### 4.5 Kandidaten und Bestätigungsstatus

Neue Entitäten und Beziehungen werden zunächst als Kandidaten erfasst. Das Modell unterscheidet zwischen bestätigten, wahrscheinlichen, ungeklärten und abgelehnten Zuordnungen.

Die KI darf Kandidaten vorschlagen und Belege zusammentragen. Unsichere Identitäten werden nicht automatisch zu bestätigten Tatsachen.

## 5. Datenhaltung

Der bevorzugte Architekturansatz ist eine Kombination aus regulären WordPress-Daten und einer strukturierten Plugin-Datenhaltung.

| Datenart | Vorgesehener Speicheransatz |
|---|---|
| Generierte Beiträge | Reguläre WordPress-Beiträge |
| Kategorien und Schlagwörter | WordPress-Taxonomien |
| Plugin-Konfiguration | WordPress Options API |
| Beitragsbezogene Zusatzinformationen | WordPress-Metadaten, sofern geeignet |
| Entitäten und Beziehungen | Eigene Datenbanktabellen als bevorzugter Ansatz |
| Quellen und Faktenbelege | Strukturierte Plugin-Datenhaltung |
| Themenvorschläge und Briefings | Persistente Plugin-Datenhaltung |
| Aufgabenstatus und Laufhistorie | Aufgabenverwaltung mit persistenter Speicherung |
| KI-Nutzungsdaten | Protokollierung der verfügbaren Verbrauchsinformationen |

WordPress empfiehlt, Post-Metadaten zu verwenden, wenn diese für den jeweiligen Anwendungsfall praktisch geeignet sind. Für komplexere Daten können eigene Tabellen erforderlich sein. Das Plugin Handbook beschreibt auch die Erstellung und Aktualisierung solcher Tabellen über `dbDelta()`.

Quelle: [WordPress: Creating Tables with Plugins](https://developer.wordpress.org/plugins/creating-tables-with-plugins/)

Die konkrete Anzahl und Struktur der Tabellen bleibt offen. Eine separate Graphdatenbank ist derzeit nicht vorausgesetzt.

## 6. Themenrecherche und Planung

Das Recherchemodul kombiniert die verfügbaren Informationen zu lokalen Keywords, Suchintentionen, Unternehmensleistungen, Zielgebieten, Wettbewerbern, vorhandenen Beiträgen und relevanten Entitäten.

Ein Themenvorschlag soll mindestens enthalten:

- Thema und Suchintention
- vorgesehenes Inhaltsformat
- lokalen Bezug und gegebenenfalls Zielgebiet
- relevante Entitäten
- vorhandene oder fehlende Content-Abdeckung
- verfügbare Recherchegrundlagen
- Priorität und Begründung
- mögliche inhaltliche Überschneidungen

Themen können einzeln, gesammelt oder im Rahmen der kontinuierlichen Recherche verarbeitet werden.

Das Plugin erstellt keinen Beitrag allein deshalb, weil ein Zeitplan oder eine bestimmte Veröffentlichungsquote dies nahelegt. Wenn die Recherche kein ausreichend relevantes Thema und keine ausreichende Faktenbasis ergibt, wird kein Beitrag erzeugt.

## 7. Content-Erstellung

Die Content-Engine erhält einen validierten Themenvorschlag, das zugehörige Entity-Wissen, Quellen und den globalen Style Mimic.

Sie erstellt reguläre WordPress-Beiträge und berücksichtigt dabei:

- Suchintention und Zielgruppe
- Haupt- und Nebenentitäten
- relevante fachliche, geschäftliche und lokale Beziehungen
- natürlich integrierte Begriffe und Zusammenhänge
- geeignete interne Links
- passende Kategorien und Schlagwörter
- SEO-Titel, Meta-Beschreibung und weitere Rank-Math-Angaben
- sinnvolle Call-to-Actions
- bei Bedarf passende Bilder

Mindest- und Höchstlängen können je Beitragstyp vorgegeben werden. Innerhalb dieser Grenzen entscheidet die KI über die notwendige inhaltliche Tiefe.

Der globale Style Mimic wird aus Beispieltexten, ausgewählten bestehenden Beiträgen und manuellen Schreibregeln gebildet. Er gilt für alle generierten Beiträge.

Bestehende Beiträge dürfen zur Themenfindung und Erkennung von Überschneidungen analysiert werden. Sie werden nicht automatisch umgeschrieben.

## 8. KI-Schnittstelle

Das Plugin verwendet zunächst ein festgelegtes Text-KI-Modell. Die Anbindung wird über eine interne Schnittstelle gekapselt.

Die fachlichen Module übergeben klar definierte Eingaben und erhalten strukturierte Ergebnisse. Zu den unterstützten Aufgaben gehören Entity-Erkennung, Themenrecherche, Briefing-Erstellung, Textgenerierung und Qualitätsbewertung.

KI-Ergebnisse werden vor der weiteren Verarbeitung auf Struktur und Pflichtfelder geprüft. Eine technisch valide Antwort gilt nicht automatisch als sachlich korrekt. Faktenbasis, Entity-Zuordnungen und redaktionelle Mindestqualität benötigen separate fachliche Prüfungen.

Die Zugangsdaten können über die Plugin-Einstellungen oder die WordPress-Konfiguration bereitgestellt werden. Ein Eintrag in `wp-config.php` hat Vorrang, wenn beide Möglichkeiten genutzt werden. Geheimnisse dürfen nicht in Protokollen, Fehlermeldungen oder Exportdateien offengelegt werden.

Für die Bildgenerierung wird eine separate Schnittstelle vorgesehen. Ob dafür derselbe Anbieter oder ein zusätzlicher Dienst verwendet wird, bleibt eine spätere Anbieterentscheidung.

## 9. Aufgabensteuerung und Server-Cronjob

Der vom Administrator selbst eingerichtete Server-Cronjob ist die vorgesehene Möglichkeit, Hintergrundaufgaben regelmäßig anzustoßen.

Der Cronjob soll nicht selbst sämtliche fachlichen Schritte ausführen. Er startet die Aufgabensteuerung, die fällige Arbeitsschritte identifiziert und sie kontrolliert verarbeitet.

Wesentliche Anforderungen:

- persistente Aufgabenstatus
- nachvollziehbarer Fortschritt
- gespeicherte Zwischenergebnisse
- begrenzte Wiederholungsversuche
- gezielte Wiederaufnahme unterbrochener Aufgaben
- Schutz vor paralleler Bearbeitung derselben Aufgabe
- Vermeidung doppelter Beiträge und Aktionen
- zeitliche und mengenbezogene Verarbeitungsgrenzen
- dokumentierte Fehlerursachen und Abschlussstatus

Die konkrete Lösung für Warteschlange, Sperren und Wiederaufnahme muss technisch ausgewählt werden. Die Ausführung soll nicht allein von Websitebesuchen abhängen.

WordPress dokumentiert das Anstoßen von WP-Cron über einen System-Zeitplaner: [Hooking WP-Cron Into the System Task Scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/). Ob die erste Implementierung WP-CLI, WP-Cron oder eine Kombination verwendet, wird anhand der Betriebsanforderungen entschieden.

## 10. Qualitätsprüfung und Veröffentlichungsprozess

Der Qualitätsprozess ist von der eigentlichen Textgenerierung getrennt.

Vor der Bereitstellung werden mindestens Suchintention, Faktenbasis, Entity-Integration, Redundanz, inhaltlicher Mehrwert und redaktionelle Qualität überprüft.

Kritische Mängel verhindern die Freigabe. Unsichere Fakten oder ungeklärte Identitäten müssen gekennzeichnet oder durch weitere Recherche geklärt werden.

Die Veröffentlichung wird in drei Phasen aufgeteilt:

1. Beiträge werden als Entwürfe oder zur redaktionellen Prüfung bereitgestellt.
2. Eine definierte Testphase bewertet eine Mindestanzahl geprüfter Beiträge innerhalb eines festgelegten Zeitraums.
3. Nach erfüllten Mindestkriterien empfiehlt das Plugin die Aktivierung der automatischen Veröffentlichung. Die Entscheidung trifft ausdrücklich der Administrator.

Nach Aktivierung kann das Plugin fällige Beiträge innerhalb konfigurierter Zeitfenster, Mindestabstände und Höchstzahlen veröffentlichen. Beiträge, die die festgelegten Qualitätskriterien nicht bestehen, bleiben unveröffentlicht.

## 11. WordPress- und Rank-Math-Integration

Alle generierten Inhalte werden als reguläre WordPress-Beiträge angelegt. Kategorien, Schlagwörter, Medien und geeignete SEO-Angaben werden über die vorgesehenen WordPress-Schnittstellen bereitgestellt.

Rank Math hat bei der SEO-Integration Vorrang. Das Plugin soll vorhandene Ausgaben berücksichtigen und keine doppelten oder widersprüchlichen SEO-Angaben erzeugen.

Rank Math stellt unter anderem eine Content Analysis API für die Integration von Plugin- oder Theme-Feldern in seine Inhaltsanalyse bereit. Die konkrete Integrationsmethode muss anhand der gewünschten Funktionen geprüft werden.

Quelle: [Rank Math: Content Analysis API](https://rankmath.com/kb/content-analysis-api/)

Die Integration ist mindestens mit Gutenberg, dem klassischen WordPress-Editor und Elementor zu prüfen. Bestehende Inhalte werden nicht automatisch geändert.

## 12. Google- und externe SEO-Schnittstellen

Die Architektur berücksichtigt folgende Integrationen:

- Google Search Console
- Google Analytics 4
- Google Unternehmensprofil
- geeignete externe Keyword- und Rankingdatenanbieter
- relevante Bewertungsplattformen und Branchenverzeichnisse

Die Google-Dienste sollen über die vorgesehenen offiziellen Zugriffs- und Berechtigungsprozesse verbunden werden. Das Plugin muss auch ohne verbundene Google-Dienste für grundlegende Analysen nutzbar bleiben.

Google Search Console stellt unter anderem Suchverkehrsdaten über die Search Analytics API bereit. Google Analytics 4 unterstützt die programmgesteuerte Erstellung von Berichten über die Analytics Data API.

Quellen:

- [Google Search Console API](https://developers.google.com/webmaster-tools/v1/api_reference_index)
- [Google Analytics Data API](https://developers.google.com/analytics/devguides/reporting/data/v1/basics)

Konkrete externe Anbieter, Datenabdeckungen, Kosten und API-Berechtigungen müssen vor ihrer Implementierung geprüft werden.

## 13. Sicherheit und Berechtigungen

Alle administrativen Funktionen benötigen geeignete Berechtigungsprüfungen. Interne REST-Endpunkte müssen die jeweiligen Zugriffsrechte kontrollieren.

Das WordPress REST API Handbook verlangt bei eigenen Routen eine Berechtigungsprüfung über `permission_callback`. Diese Prüfung muss an die jeweilige Funktion angepasst sein.

Quelle: [WordPress REST API: Routes and Endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/routes-and-endpoints/)

Weitere verbindliche Anforderungen sind:

- sichere Behandlung externer API-Ergebnisse
- Validierung und Bereinigung von Eingaben
- keine Offenlegung von Zugangsdaten
- nachvollziehbare Fehlerprotokollierung
- Datenminimierung
- kontrollierte Wiederherstellung nach Fehlern
- Prüfung möglicher Konflikte mit bestehenden WordPress-Plugins

## 14. Technische Entscheidungen und ihr Status

| Entscheidung | Status |
|---|---|
| Eigenständiges, modulares WordPress-Plugin | Fachlich festgelegt |
| Generierte Inhalte als reguläre WordPress-Beiträge | Festgelegt |
| Ein Text-KI-Modell als Ausgangspunkt | Festgelegt |
| Rank Math als bevorzugte SEO-Integration | Festgelegt |
| Server-Cronjob zur Ansteuerung der Verarbeitung | Vorgesehen |
| Kombination aus WordPress-Daten und strukturierter Entity-Datenhaltung | Bevorzugter Architekturansatz |
| Eigene Datenbanktabellen für das Entity-Modell | Zu prüfen und zu spezifizieren |
| Konkrete Aufgabenwarteschlange | Offen |
| WP-CLI oder anderer Einstiegspunkt für Cron-Verarbeitung | Offen |
| Konkreter KI- und Bildanbieter | Offen |
| Konkrete Struktur der Datenbanktabellen | Offen |
| Finale Liste externer SEO-Datenanbieter | Offen |
| Exakte Qualitätsgrenzen und Dauer der Testphase | Offen |

## 15. Nächste technische Arbeitsschritte

1. Das Datenmodell für Entitäten, Beziehungen, Quellen und Belege präzisieren.
2. Die Aufgaben- und Statusmodelle für Recherche und Content-Erstellung spezifizieren.
3. Die Aufgabenwarteschlange und serverseitige Ausführung anhand von Wiederaufnahme, Parallelität und Protokollierung vergleichen.
4. Die KI-Schnittstelle und ihre Ergebnisverträge präzisieren.
5. Die Rank-Math- und Google-Integrationen anhand der Anforderungen spezifizieren.
6. Den Abnahmekatalog und die Testfälle für das MVP erstellen.

**Status:** Dieser Architekturentwurf beschreibt die fachlichen Module, ihre Aufgaben und die wesentlichen technischen Leitplanken. Er ist noch keine vollständige Implementierungsspezifikation und legt keine nicht bestätigten technischen Details endgültig fest.