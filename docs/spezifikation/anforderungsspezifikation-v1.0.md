# Anforderungsspezifikation
## WordPress Local SEO & Entity Content Plugin

**Dokumentversion:** 1.0  
**Status:** Fachliche Anforderungsaufnahme abgeschlossen; Nachtrag vom 18.10.2026 zu Abschnitt 2 und 14  
**Vorgehensmodell:** Grill-with-Docs  
**Ziel:** Vorbereitung des Architekturvergleichs und Definition des MVP

---

## 1. Projektziel

Entwickelt werden soll ein WordPress-Plugin, das die lokale Suchmaschinenoptimierung einer einzelnen WordPress-Website durch KI-gestützte Recherche, Entity-Erkennung und automatisierte Content-Erstellung unterstützt.

Die Hauptaufgabe besteht darin, selbstständig relevante Themen für die lokale Suche zu finden, passende Entitäten zu identifizieren und daraus hochwertige WordPress-Beiträge zu erstellen. Im späteren Betriebsmodus soll das Plugin geeignete Beiträge nach festgelegten Regeln auch automatisch veröffentlichen können.

**Der Kern des Produkts ist die Verbindung von Local SEO, Entity-Optimierung und automatisierter Content-Produktion.** Es handelt sich nicht um eine zentrale Anwendung zur Verwaltung mehrerer Websites.

## 2. Grundlegende Produktentscheidungen

| Bereich | Verbindliche Anforderung |
|---|---|
| Produktform | WordPress-Plugin innerhalb der jeweiligen WordPress-Installation |
| Hauptfunktion | Recherche, Planung und Erstellung neuer lokaler SEO-Beiträge |
| Entity-Strategie | KI-gestützte Entity-Erkennung, Beziehungsanalyse und Entity-Integration in Inhalte |
| KI | Ein festes Hauptmodell für alle KI-Aufgaben; optional ein eigenes Modell für die Recherche mit Websuche (Nachtrag 18.10.2026) |
| SEO-Integration | Rank Math hat bei der Integration Vorrang |
| Zielmarkt | Deutschland, Österreich und deutschsprachige Schweiz |
| Website-Sprache | Zunächst einsprachige Websites |
| WordPress-Inhalte | Alle generierten Inhalte werden als reguläre WordPress-Beiträge angelegt |
| Redaktionsstil | Ein globaler, vom Administrator definierter Style Mimic |
| Bestehende Inhalte | Analyse zur Themenfindung und Erkennung von Überschneidungen, keine aktive Optimierung |
| Veröffentlichung | Zunächst kontrolliert, später optional automatisiert |
| Benutzer | Administrator der jeweiligen WordPress-Installation |

## 3. Kernfunktion: Automatisierte Content-Produktion

### 3.1 Recherche neuer Themen

Das Plugin soll kontinuierlich neue Themen für lokale SEO-Beiträge identifizieren. Dafür kombiniert es:

- lokale Keyword-Recherche und verfügbare Suchdaten
- Suchintentionen und regionale Nachfrage
- identifizierte Entitäten und ihre Beziehungen
- Wettbewerbsanalysen
- bereits vorhandene Website-Inhalte
- saisonale Themen und regionale Besonderheiten
- Unternehmensdaten und angebotene Leistungen

Aus diesen Informationen erstellt das Plugin eine priorisierte Themenliste. Zu jedem geeigneten Thema sollen die Relevanz und die wesentlichen Recherchegrundlagen nachvollziehbar sein.

Ungeeignete, redundante oder nicht ausreichend belegbare Themen sollen nicht zwangsläufig zu Beiträgen führen. Bei unzureichender Datenbasis wird die Recherche erweitert. Bleibt sie erfolglos, wird kein Beitrag erstellt.

### 3.2 Unterstützte Inhaltsformate

Das Plugin soll je nach Suchintention unterschiedliche Formate erstellen können:

- lokale Landingpage-Inhalte
- Fachartikel
- Ratgeber
- saisonale Beiträge
- weitere sinnvolle lokale SEO-Inhalte

Alle diese Inhalte werden entsprechend der getroffenen Entscheidung als reguläre WordPress-Beiträge angelegt. Die konkrete Themenauswahl berücksichtigt das vorhandene Content-Angebot, damit keine unnötigen Dopplungen entstehen.

### 3.3 Unterschiedliche Erstellungsabläufe

Das Plugin soll drei Arbeitsweisen unterstützen:

1. Einzelne Beiträge zu manuell ausgewählten Themen erstellen.
2. Mehrere ausgewählte Themen gesammelt bearbeiten lassen.
3. Kontinuierlich neue Themen recherchieren und daraus nach konfigurierbaren Regeln Beitragsentwürfe erzeugen.

Der Administrator kann zusätzlich Zeitpläne, gewünschte Veröffentlichungsintervalle und Obergrenzen festlegen.

### 3.4 Beitragsaufbau und Textqualität

Die KI bestimmt den Aufbau eines Beitrags nach Thema und Suchintention. Je nach Bedarf können Zwischenüberschriften, Listen, Tabellen, FAQ-Abschnitte, Beispiele und andere passende Elemente verwendet werden.

Der Administrator kann Mindest- und Höchstlängen für verschiedene Beitragstypen definieren. Innerhalb dieser Grenzen entscheidet die KI über die notwendige inhaltliche Tiefe. Unnötige Textlänge und schematisch wiederholte Abschnitte sollen vermieden werden.

## 4. Entity-Optimierung als Kernbestandteil

Die Entity-Funktionalität ist keine optionale Ergänzung, sondern Grundlage der Themenrecherche und Beitragserstellung.

### 4.1 KI-gestützte Entity-Erkennung

Die KI soll relevante Entitäten aus verfügbaren internen und externen Quellen identifizieren. Dazu zählen insbesondere:

- Unternehmen, Marken und Standorte
- Personen, Organisationen und Partner
- Dienstleistungen, Produkte und fachliche Leistungen
- Städte, Gemeinden, Regionen und geografische Orte
- Wettbewerber und relevante externe Organisationen
- fachliche Begriffe, Themen und semantische Konzepte

Die Erkennung soll vorhandene WordPress-Inhalte, Unternehmensdaten, Meta-Daten, Medieninformationen, strukturierte Daten sowie geeignete externe Quellen berücksichtigen.

### 4.2 Entity-Beziehungen

Das Plugin soll nicht nur einzelne Entitäten auflisten, sondern auch ihre Beziehungen abbilden. Beispiele sind Unternehmen zu Marke, Standort zu Servicegebiet, Leistung zu Fachthema oder Unternehmen zu relevanten Organisationen und geografischen Orten.

Die KI darf neue Entitäten und Beziehungen erkennen und als Kandidaten vorschlagen. Zuordnungen müssen nachvollziehbar bleiben, insbesondere wenn mehrere Unternehmen oder Orte ähnliche Namen haben.

### 4.3 Entity-Verifizierung

Das Plugin soll erkannte Entitäten mit verfügbaren externen Quellen abgleichen. Geeignete Quellen können offizielle Unternehmenswebsites, Unternehmensprofile, öffentliche Register, Wikidata, Wikipedia, verfügbare Google-Entity-Daten und branchenspezifische Wissensbestände sein.

Quellen, Fundstellen, Belege und Unsicherheiten sollen dokumentiert werden. Das Plugin unterscheidet zwischen bestätigten, wahrscheinlichen und ungeklärten Identitäten. Unklare Zuordnungen werden nicht ungeprüft als Tatsachen übernommen.

### 4.4 Entity-Modell und Visualisierung

Die Plattform innerhalb des Plugins soll ein visuelles Entity-Modell bereitstellen. Darin werden Entitäten, Eigenschaften, Beziehungen und Fundstellen dargestellt.

Neue Entitäten werden zunächst als Kandidaten aufgenommen und mit Konfidenz, Quellen und Belegen versehen. Der Administrator kann Kandidaten und Beziehungen bestätigen, korrigieren oder ablehnen. Diese Entscheidungen werden dokumentiert und bei späteren Analysen berücksichtigt.

Das Entity-Modell soll erweiterbar sein und branchenspezifische Entitäten unterstützen.

### 4.5 Entity-Integration in Beiträge

Bei jeder Beitragserstellung wählt die KI relevante Haupt- und Nebenentitäten aus und berücksichtigt deren Beziehungen zur lokalen Suchintention.

Die Entitäten werden sinnvoll in den Beitrag integriert, beispielsweise in Überschriften, Fließtext und thematische Zusammenhänge. Passende interne Links und geeignete strukturierte Daten werden ebenfalls berücksichtigt.

Künstliche Entity-Erwähnungen und Keyword-Stuffing sind zu vermeiden. Die bloße Häufigkeit eines Begriffs gilt nicht als ausreichendes Qualitätskriterium.

Das Entity-Modell wird durch neue Beiträge und weitere Recherche kontinuierlich erweitert. Neue Entitäten und Beziehungen werden bei Unsicherheit als Kandidaten gekennzeichnet.

## 5. Globaler Style Mimic

Der Administrator soll einen globalen Style Mimic anlegen können, der für sämtliche neu erzeugten Beiträge verwendet wird.

Die Erstellung soll verschiedene Eingaben kombinieren können:

- Beispieltexte des Administrators
- ausgewählte bestehende Beiträge der Website
- manuell definierte Stilvorgaben

Die KI erstellt daraus ein bearbeitbares Stilprofil. Dieses berücksichtigt unter anderem Tonalität, Satzbau, Wortwahl, Ansprache und redaktionelle Regeln.

Der Style Mimic wird bei der Beitragserstellung mit den Anforderungen an Suchintention, Entity-Integration, Faktenbasis und Qualität kombiniert.

## 6. SEO-Ausstattung neuer Beiträge

Das Plugin soll für neu erstellte Beiträge eine vollständige, konfigurierbare SEO-Ausstattung bereitstellen.

Dazu zählen:

- SEO-Titel und Meta-Beschreibung
- URL-Slug als Vorschlag
- relevante Keywords und Suchintention
- passende Überschriftenstruktur
- Empfehlungen zu strukturierten Daten
- Kategorien und Schlagwörter
- geeignete interne Links
- Bild-SEO-Angaben
- passende Call-to-Actions

Die Integration von Rank Math soll vorhandene SEO-Ausgaben berücksichtigen und doppelte oder widersprüchliche Angaben vermeiden. Konkrete Rank-Math-Werte sollen mit Begründung vorgeschlagen beziehungsweise für neu generierte Beiträge bereitgestellt werden.

Bestehende Beiträge und globale SEO-Einstellungen werden nicht im Rahmen der Content-Produktion automatisch verändert.

## 7. Interne Verlinkung, Kategorien und Content-Cluster

Neue Beiträge sollen passende interne Links zu bestehenden Seiten und Beiträgen enthalten. Die Zielseiten und Linktexte werden anhand ihrer tatsächlichen thematischen und semantischen Relevanz ausgewählt.

Das Plugin soll die vorhandene Kategorienstruktur analysieren und neue Beiträge passend einordnen. Bei Bedarf kann es eine verbesserte Taxonomie vorschlagen. Neue Kategorien werden nur nach Freigabe angelegt.

Thematische Content-Cluster verbinden Beiträge zu gemeinsamen Themen, Entitäten, Leistungen und lokalen Suchintentionen. Das Plugin erkennt fehlende Themen, plant zusammenhängende Inhalte und vermeidet unnötige Wiederholungen oder künstliche Verlinkungen.

## 8. Bilder und Medien

Das Plugin soll vorhandene Bilder analysieren, Bildprompts erstellen und bei Bedarf neue Bilder über einen geeigneten KI-Bildgenerierungsdienst erzeugen können.

Die Bildanalyse berücksichtigt:

- thematische und lokale Relevanz
- Kontext des Beitrags
- Alternativtexte und Bildbeschreibungen
- Dateinamen
- Bildabmessungen und Dateigröße
- mögliche Auswirkungen auf Performance und Barrierefreiheit

Im normalen Prüfmodus entscheidet der Administrator über die Verwendung generierter Bilder. Im später freigegebenen Automatikbetrieb darf das Plugin geeignete KI-Bilder verwenden, sofern sie die festgelegten Prüfungen bestehen.

## 9. Veröffentlichung und redaktionelle Kontrolle

Die Veröffentlichung soll stufenweise eingeführt werden.

### Phase 1: Kontrollierte Erstellung

Das Plugin erstellt Beiträge als WordPress-Entwürfe oder stellt sie zur redaktionellen Prüfung bereit. Der Administrator prüft und veröffentlicht die Inhalte selbst.

### Phase 2: Bewertung der Testphase

Das Plugin bewertet eine definierte Anzahl manuell geprüfter Beiträge innerhalb eines festgelegten Zeitraums. Qualitäts-, Fakten- und Entity-Prüfungen müssen die vereinbarten Mindestkriterien erfüllen.

Nach erfolgreicher Bewertung empfiehlt das Plugin, die automatische Veröffentlichung zu aktivieren. Die Entscheidung trifft ausdrücklich der Administrator.

### Phase 3: Regelgesteuerte automatische Veröffentlichung

Nach Freigabe kann das Plugin geeignete Beiträge zu konfigurierten Zeitpunkten automatisch veröffentlichen. Der Administrator legt Zeitfenster, Mindestabstände und Höchstzahlen fest.

Das Plugin berücksichtigt Themenpriorität, Saisonalität und tatsächlichen Content-Bedarf. Beiträge mit kritischen Fehlern, unzureichender Faktenbasis oder nicht bestandenen Qualitätsprüfungen bleiben unveröffentlicht.

## 10. Qualitäts- und Faktenprüfung

Das Plugin soll Inhalte vor der Bereitstellung beziehungsweise Veröffentlichung nach konfigurierbaren Qualitätsprofilen prüfen.

Die Kriterien umfassen insbesondere:

- Suchintention und lokale Relevanz
- sinnvolle Entity-Integration und nachvollziehbare Beziehungen
- inhaltlichen Mehrwert und fachliche Vollständigkeit
- Rechtschreibung und Lesbarkeit
- belegbare Tatsachenbehauptungen
- Vermeidung redundanter oder bereits abgedeckter Themen
- passende interne Links und SEO-Angaben
- Übereinstimmung mit dem Style Mimic

Kritische Mängel sollen die Veröffentlichung blockieren. Unsichere Fakten und ungeklärte Entity-Zuordnungen werden gekennzeichnet.

Quellen werden intern dokumentiert. Ob Quellenlinks im veröffentlichten Beitrag erscheinen, richtet sich nach dem konfigurierten Beitragstyp.

## 11. Zentrale Local-SEO-Funktionen

Zusätzlich zur Content-Produktion soll das Plugin weitere lokale SEO-Bereiche unterstützen.

### Unternehmens- und Standortdaten

Eine eigene Verwaltung soll Unternehmensdaten, Standorte, Leistungen, Adressen, Öffnungszeiten und Servicegebiete erfassen können. Vorhandene WordPress-Strukturen können ergänzend angebunden werden.

Das Plugin unterstützt Unternehmen mit öffentlicher Geschäftsadresse ebenso wie Dienstleister, die ihre Kunden in einem Servicegebiet bedienen.

### Keyword-Recherche und Suchsichtbarkeit

Das Plugin soll lokale Keywords recherchieren, nach Suchintention, Leistung und Region gruppieren und geeigneten Seiten zuordnen. Es erkennt Keyword-Lücken und mögliche Kannibalisierung.

Lokale Rankings sollen abhängig von den Datenquellen für Städte, Regionen, Postleitzahlen und räumliche Raster untersucht werden können. Ergebnisse werden über Tabellen, Diagramme und gegebenenfalls interaktive Karten dargestellt.

### Google-Integrationen

Vorgesehen sind Verbindungen über offizielle Zugriffs- und Berechtigungsprozesse zu:

- Google Search Console
- Google Analytics 4
- Google Unternehmensprofil

Das Plugin muss auch ohne verbundene Google-Dienste für grundlegende Analysen nutzbar bleiben. Fehlende Daten werden in den jeweiligen Auswertungen kenntlich gemacht.

### Wettbewerber und externe Quellen

Das Plugin soll Wettbewerber manuell oder automatisch identifizieren und öffentlich verfügbare Inhalte, Leistungen, lokale Suchsichtbarkeit und weitere passende Informationen vergleichen.

Geeignete externe SEO-Datenanbieter, Branchenverzeichnisse und Bewertungsplattformen werden abhängig von Verfügbarkeit, Nutzen und Kosten berücksichtigt.

### Bewertungen und externe Unternehmensprofile

Das Plugin soll vorhandene Bewertungen aus relevanten Plattformen analysieren, wiederkehrende Themen und Kritikpunkte identifizieren und passende Antwortentwürfe erstellen. Veröffentlichungen von Bewertungsantworten erfolgen nur nach ausdrücklicher Freigabe über unterstützte Schnittstellen.

Google-Unternehmensprofile sollen analysiert und Optimierungsvorschläge vorbereitet werden. Unterstützte Änderungen und Beiträge werden nur nach ausdrücklicher Freigabe übertragen oder veröffentlicht.

Eine Funktion zur Gewinnung neuer Bewertungen ist nicht vorgesehen.

### Backlinks und lokale Erwähnungen

Das Plugin soll Backlinks und öffentliche Erwähnungen analysieren, unverlinkte Erwähnungen erkennen und geeignete lokale Kontaktmöglichkeiten recherchieren. Individuelle Outreach-Entwürfe können vorbereitet werden. Die externe Kontaktaufnahme erfolgt nur nach ausdrücklicher Freigabe.

## 12. Berichte und WordPress-Oberfläche

Das WordPress-Backend erhält ein zentrales Dashboard mit Local-SEO-Gesamtbewertung, priorisierten Maßnahmen, wichtigen Ergebnissen und Entwicklungstrends. Ergänzend gibt es getrennte Funktionsbereiche für detaillierte Analysen.

Die Local-SEO-Empfehlungen sollen im Dashboard, in unterstützten Editoren und in den Seiten- und Beitragsübersichten zugänglich sein.

Das Plugin soll Ergebnisse als PDF-Berichte sowie strukturierte Daten und Maßnahmenlisten als CSV- oder Excel-kompatible Dateien exportieren können.

Relevante neue Probleme und Potenziale werden im WordPress-Administrationsbereich angezeigt. E-Mail-Benachrichtigungen sind zunächst nicht vorgesehen.

## 13. Unterstützte WordPress-Umgebung

Für die erste Ausbaustufe werden Gutenberg, der klassische WordPress-Editor und Elementor berücksichtigt.

WooCommerce soll optional erkannt und bei Bedarf für Local-SEO-Prüfungen von Produkten, Kategorien und lokalen Shop-Informationen unterstützt werden.

Das Plugin erkennt die Branche anhand vorhandener Website-Inhalte und Unternehmensdaten und schlägt ein passendes Profil vor. Der Administrator kann das Ergebnis bestätigen oder korrigieren.

Die erste Ausbaustufe konzentriert sich auf einsprachige Websites im deutschsprachigen Raum.

## 14. KI-Konfiguration und Kosten

Das Plugin verwendet zunächst ein einziges KI-Modell für alle KI-Aufgaben.

**Nachtrag 18.10.2026 (Entscheidung nach dem Machbarkeitstest):** Für den Rechercheschritt mit Websuche kann ein eigenes Modell desselben Anbieters eingestellt werden. Grund: Die Suchergebnisse machen den Großteil der Kosten aus (Recherche rund 0,14 US-Dollar gegenüber 0,005 US-Dollar für die Strukturierung). Alle übrigen Aufgaben verwenden weiterhin das Hauptmodell. Ohne eigene Einstellung gilt das Hauptmodell auch für die Recherche.

Der API-Schlüssel kann über die Plugin-Einstellungen oder über die WordPress-Konfiguration in `wp-config.php` bereitgestellt werden. Die Konfiguration hat Vorrang, wenn beide Varianten befüllt sind. Geheimnisse dürfen nicht in Protokollen oder Fehlermeldungen offengelegt werden.

Das Plugin zeigt verfügbare Nutzungs- und Kostendaten des KI-Dienstes an. Die eigentliche Begrenzung der Ausgaben erfolgt beim KI-Anbieter. Zusätzlich hält das Plugin Abläufe an, wenn ein eingestelltes Monats- oder Ablaufbudget erreicht ist (Nachtrag 18.10.2026). Vor der Auswahl des konkreten Dienstes muss geprüft werden, welche Nutzungsdaten verfügbar sind und welche Kostenkontrollen der Anbieter tatsächlich ermöglicht.

Der konkrete KI-Anbieter, die Bildgenerierung und zusätzliche SEO-Datenanbieter bleiben technische Auswahlentscheidungen.

## 15. Bewusst nicht Bestandteil des aktuellen Kerns

Folgende Funktionen gehören nicht zum primären Funktionsumfang:

- zentrale Verwaltung und Optimierung mehrerer Websites
- aktive Optimierung bestehender Beiträge
- automatische Änderungen an vorhandenen Website-Inhalten
- automatische Anpassung globaler SEO-Einstellungen
- automatische Veröffentlichung ohne vorherige Freigabe des Automatikbetriebs
- Funktion zur Gewinnung neuer Bewertungen
- mehrsprachige Content-Produktion in der ersten Ausbaustufe
- eigenständiger, umfassender Richtlinien-Audit als separates Funktionsmodul

Die Analyse bestehender Inhalte bleibt erlaubt und dient der Themenfindung, der Entity-Analyse sowie der Vermeidung unnötiger Überschneidungen.

## 16. Empfohlene MVP-Abgrenzung

Der folgende Vorschlag priorisiert die Funktionen anhand des erklärten Projektziels. Er ist eine Grundlage für den Architekturvergleich und keine vorweggenommene technische Entscheidung.

### MVP 1: Entity-basierte Content-Produktion

- Grundeinrichtung mit Erkennung von Website, Unternehmen, Branche und Rank Math
- Unternehmens-, Leistungs- und Standortprofil
- KI-gestützte Entity-Erkennung und erste Entity-Beziehungsdarstellung
- lokale Themen- und Keyword-Recherche
- grundlegender Wettbewerbsvergleich und Analyse vorhandener Inhalte
- Erstellung neuer Beiträge in den vereinbarten Formaten
- globaler Style Mimic
- Entity-Integration, interne Links und Rank-Math-SEO-Angaben
- Kategorienzuordnung und Qualitätsprüfung
- Bereitstellung als WordPress-Entwurf
- grundlegender Content- und Entity-Bericht

### Ausbau 2: Automatisierung und erweiterte Entity-Analyse

- detaillierteres Entity-Modell mit Kandidatenprüfung und externer Verifizierung
- dynamische Content-Cluster und kontinuierliche Themenproduktion
- Bildanalyse und KI-Bildgenerierung
- geplanter Übergang zur kontrollierten automatischen Veröffentlichung
- ausführlichere Berichte und historische Auswertung
- erweiterte SEO- und Faktenprüfungen

### Ausbau 3: Erweiterte Local-SEO-Daten und Plattformen

- Google Search Console, Google Analytics 4 und Google Unternehmensprofil
- detaillierte lokale Ranking-Messung einschließlich räumlicher Darstellung
- umfassendere Wettbewerbs- und Bewertungsanalysen
- Branchenverzeichnisse, externe Erwähnungen und Backlink-Recherche
- erweiterte branchenspezifische Funktionen und optionale WooCommerce-Unterstützung

Die Aufteilung ist vor dem Architekturvergleich auf Abhängigkeiten, Kosten und technischen Aufwand zu prüfen. Das Gesamtziel bleibt umfassendes Local SEO mit entity-basierter Content-Produktion.

## 17. Noch offene technische Entscheidungen

Die fachlichen Ziele sind definiert. Noch nicht festgelegt sind insbesondere:

- die technische Grundarchitektur und der Aufbau der Plugin-Komponenten
- die Verwaltung und Speicherung des Entity-Modells
- die konkreten KI- und Bildgenerierungsanbieter
- externe SEO-Datenanbieter und verfügbare Schnittstellen
- die technische Umsetzung der Google-Integrationen
- Aufgabenverarbeitung, Planung und Wiederaufnahme
- konkrete Qualitätsgrenzen und Abnahmekriterien
- Versionierung, Datensicherung und Wiederherstellungsverfahren
- technische und wirtschaftliche Grenzen der automatischen Veröffentlichung

Diese Punkte werden nicht durch weitere allgemeine Anforderungsfragen vorweggenommen. Sie werden in der Architekturphase anhand der vorliegenden Anforderungen und geeigneter Alternativen beurteilt.

## 18. Verbindliche Vorgehensregel

Die Implementierung beginnt erst, wenn die Architekturentscheidung auf einer ausreichenden Grundlage getroffen wurde.

Zuerst werden geeignete Architekturvarianten verglichen und hinsichtlich Wartbarkeit, Sicherheit, Kosten, Erweiterbarkeit und Kompatibilität mit WordPress bewertet. Anschließend werden die technischen Spezifikationen erstellt und die Umsetzung anhand definierter Abnahmekriterien geplant.

**Abschließender Status:** Die fachliche Anforderungsaufnahme ist abgeschlossen. Das Hauptziel ist klar definiert: ein WordPress-Plugin, das durch KI-gestützte Entity-Erkennung und lokale Themenrecherche hochwertige SEO-Beiträge erzeugt und perspektivisch kontrolliert automatisch veröffentlicht.