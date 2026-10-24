# Ideen für Ausbau 2

Gesammelt nach MVP 1, noch nicht beschlossen. Grundlage für die Planung von Ausbau 2 nach der Abnahme von 1.0.

## Quellen: Casey Keith (Hinweise von Thomas, 24.10.2026)

| Nr. | Beitrag | Datum | Kern |
|---|---|---|---|
| 1 | Video „Day 10 – RankBrain: Build Topical Depth“ (Facebook, Grafik) | – | Thema = Geflecht aus Entitäten, Bauteilen, Problemen, Kundenfragen; je Seite nur, was ihre Suchabsicht braucht; Hauptseite plus Unterseiten |
| 2 | Advice for Content Creators | 04.09.2025 | Keine Wortzahl als Ziel, keine Google-Formel; Antwort zuerst; Einzigartigkeit ist das Wichtigste; Bilder und Video ergänzen |
| 3 | Conversion Optimization with Persuasive Language | 09.09.2025 | Handlungsaufforderungen mit Verb und Ergebnis statt „Absenden“; konkrete statt leere Wörter; Nutzen in Überschriften; Kundenstimmen nahe der Handlungsaufforderung |
| 4 | The Straight-Talk Match Type Throwdown | 20.09.2025 | Google Ads (Übereinstimmungstypen); für SEO übertragbar: Suchanfragen zeigen die Sprache der Kunden, Ortsverwechslung, Kannibalisierung |
| 5 | SEO Keyword Placement | 02.10.2025 | Vier Stellen: Title-Tag (Keyword vorn, 50–60 Zeichen, passend zur Absicht), H1 (Keyword oder Variante, nicht wortgleich mit dem Title), URL (kurz, unter fünf Wörtern, nur das Haupt-Keyword, kein Jahr), erste 100 Wörter (Keyword natürlich, mit Varianten, Nutzen sofort) |
| 6 | The No-Nonsense Checklist for SEO QA | 01.10.2025 | Prüfliste vor und nach Veröffentlichungen: robots, noindex, Canonical, Statuscodes, echte Links, Titel/H1, Schema, Mobil |
| 7 | Rank and Rent HVAC Websites | 05.11.2025 | Leistung × Ort mit eigener lokaler Information, ein Cluster je Leistung, NAP überall gleich, LocalBusiness-Schema, saisonale Auffrischung, Anruf-Knopf und kurzes Formular je Leistungsseite |
| 9 | Step-by-Step: How to Implement Entity SEO (Hinweis von Thomas: „ganz wichtig“) | 23.09.2025 | Zwölf Schritte: eine Hauptseite (Entity Home) je Entität, klare Beschreibung in den ersten 100–150 Wörtern, Schema nur mit dem, was auf der Seite steht (`mainEntityOfPage`, `about`, `mentions`, `sameAs`), gleiche Fakten überall, 5–10 verwandte Entitäten je Säulenthema, Säule und Cluster, interne Links mit sprechendem Ankertext, echte Fragen beantworten, Bilder beschriften, nach dem Veröffentlichen Profile angleichen, vierteljährlich Markensuche prüfen, Tiefe vor Breite; Vorlage „Steckbrief“ je Seite; typische Fehler. Nennt weder Wikidata noch Knowledge Graph und keine Messgrößen |
| 8 | The Semantic Lie | 30.07.2025 | Eine klare Absicht je Seite, Ziel im ersten Satz, benannte Entitäten und ausdrückliche Beziehungen, Schema; die Thesen zu Common Crawl, C4 und Updates sind unbelegt und keine Grundlage für Funktionen |

Umgesetzt: **Antwort zuerst** (0.10.3) aus 2 und 8, **Keyword-Platzierung** (0.10.4) aus 5, **Steckbrief, Schema über Rank Math, Ankertext** (Abschnitt 8, Bausteine 1–3) und **Handlungsaufforderung** (Idee 3) in 1.1.0.

Beitrag 9 passt am genauesten zum Kern des Plugins (Entitäten) und bündelt mehrere Ideen unten; deshalb steht er als Abschnitt 8 mit eigener Reihenfolge.

## Ideen

### 0. Knopf „Seite verbessern“ im Bericht (Wunsch von Thomas, 24.10.2026)

- Live-Seiten nie direkt überschreiben; der Knopf erzeugt einen Vorschlag zur Prüfung.
- SEO-Titel und Meta-Beschreibung für die Suchanfragen der Seite, mit einem Klick in Rank Math übernehmbar.
- Neue oder erweiterte Abschnitte zu den Suchanfragen, nach denselben Regeln wie neue Entwürfe, mit Prüfbericht.
- Elementor-Seiten: Abschnitte zum Einfügen von Hand. Normale Beiträge und Seiten: überarbeitete Fassung als Entwurf zum Vergleichen und Übernehmen.
- Fehlende interne Links von und zu der Seite.
- Bei Markensuchen keine Textänderung, sondern eine Prüfliste: Unternehmensprofil, Schema für Organisation und Personen, Seitentitel.

### 1. Leistungs-Landkarte (aus 1, 7, 8) – Vorschlag als erster Baustein von Ausbau 2

- Je Leistung aus dem Profil recherchiert die KI mit Websuche: Materialien und Bauteile, Probleme und Ursachen, Verfahren, verwandte Leistungen, typische Kundenfragen. Ablage als Entitäten vom Typ „Begriff“ mit Beziehungen (`lsec_relations`), Bestätigen und Ablehnen wie bei den Entitäten.
- Die Leistungsseite ist die Hauptseite. Die Themenrecherche schlägt Unterseiten zu Lücken der Landkarte vor, z. B. „Ölflecken auf Pflaster entfernen“.
- Das Briefing bekommt nur die Begriffe, die die Suchabsicht der Seite braucht, nicht alle.
- Verlinkung: Unterseiten verlinken die Hauptseite. Der Bericht nennt fehlende Links von der Hauptseite zu den Unterseiten.
- Der Bericht zeigt je Leistung, welche Teile der Landkarte Inhalt haben.
- Entspricht „Dynamische Content-Cluster“ aus der Spezifikation (Ausbau 2).

### 2a. Referenzen nutzen (Gespräch mit Thomas, 24.10.2026) – ersetzt Idee 2 als Hauptweg

Thomas: Praxisnotizen sind „fast nie möglich“ und meist dünn. Neue Referenzen erscheinen aber ohnehin als Blogbeiträge (Beispiel GaLaBau: Klärwerk Hänigsen, rund 300 Wörter, 90 m² Verbundpflaster, Problem und Lösung, fünf Fotos).
- Referenzbeiträge erkennen (Kategorie in den Einstellungen oder automatisch) und Eckdaten ziehen: Ort, Objekt, Leistung, Problem, Lösung, Menge, Material, Datum, Fotos. Sie gelten als Angaben des Betriebs.
- Entwürfe bauen passende Referenzen (gleiche Leistung, Ort in der Nähe) als kurzes Beispiel mit Link ein, nur mit Angaben aus der Referenz.
- Bericht: Leistungen und Orte ohne Referenz.
- Optional: aus Fotos und drei Zeilen einen Referenzbeitrag als Entwurf schreiben (Bildbeschreibung wie bei Beitragsbildern).
- Nur Referenzen der eigenen Website: Projekte einer Schwesterfirma (GaLaBau) sind für die Flächenreinigung keine eigene Erfahrung.

### 2b. Google-Rezensionen – zurückgestellt (24.10.2026)

Nur Staats GaLaBau hat Rezensionen; vorerst zurückgestellt. Wenn später: Import (Einfügen oder Datei) vor der Business-Profile-API (Freigabe durch Google nötig, Anmeldung mit dem Konto des Profils); Places-API ungeeignet (höchstens fünf Rezensionen, Speichern nicht erlaubt). Nutzen: Sprache und Themen der Kunden, Ortsbelege, kurze unveränderte Zitate mit Vorname/Initiale, Monat und Link. Keine Bewertungssterne über eigenes Schema (Google zeigt seit 2019 keine Sterne für Eigenbewertungen von Unternehmen).

### 2. Praxisnotizen je Leistung (aus 2) – nur noch optional, siehe 2a

- Ein Feld je Leistung im Profil für echte Erfahrungen des Betriebs, z. B. typische Schäden in der Gegend, bewährte Verfahren, häufige Kundenfragen.
- Die Notizen gelten als Angaben des Betriebs und dürfen in den Text. Sie sind der Hebel für Einzigartigkeit, den die KI allein nicht liefern kann.
- Mit der Leistungs-Landkarte zusammen planen; beide hängen an den Leistungen.

### 3. Handlungsaufforderung und Nutzen (aus 3, 7)

- Handlungsaufforderung mit Verb und Ergebnis („Besichtigungstermin anfragen“, „Angebot für Ihre Einfahrt anfordern“) statt „Kontakt“. Regel für Briefing und Text, Prüfung in der Bewertung.
- Überschriften nennen den Nutzen für den Leser, wo es passt.
- Keine künstliche Dringlichkeit oder Knappheit („nur heute“): Das wären unbelegte Zusagen und verstößt gegen die Regeln der Prüfung.
- Kundenstimmen nur aus echten Quellen, z. B. Bewertungen, die der Betrieb einträgt. Nie erfunden.

### 4. Veröffentlichungsprüfung (aus 6) – passt zur automatischen Veröffentlichung

- Nach dem Veröffentlichen ruft das Plugin die Seite auf und prüft:
  - Status 200, nicht `noindex`, Canonical auf sich selbst,
  - Titel, H1 und Meta-Beschreibung vorhanden,
  - Beitragsbild und Alternativtext,
  - interne Links als echte `<a href>`, Schema von Rank Math vorhanden.
- Täglich für neue Beiträge, Ergebnis im Bericht; Warnung bei Abweichungen.
- Optional: robots.txt der Website prüfen (Staging blockiert oft alles).

### 5. Suchanfragen als Sprache der Kunden (aus 4)

- Fragen aus der Search Console („wie“, „was kostet“, „warum“) als Vorschläge für FAQ der passenden Seite.
- Ortsverwechslung erkennen: Suchanfragen mit anderem Ort, die auf eine Ortsseite fallen, als Hinweis „eigene Seite für Ort X?“.
- Kannibalisierung: Keywords, für die zwei eigene Seiten Impressionen bekommen, im Bericht nennen (ergänzt den Dublettenschutz bei Themen).

### 6. Keyword an den vier Stellen (aus 5) – umgesetzt in 0.10.4

Heute gilt: Haupt-Keyword im Titel, in der Einleitung und in einer Zwischenüberschrift; SEO-Titel 30 bis 60 Zeichen. Ergänzungen:
- SEO-Titel: Haupt-Keyword möglichst am Anfang.
- Titelform (Slug): höchstens fünf Wörter, Haupt-Keyword (bei Ortsseiten mit Ort), keine Füllwörter, kein Jahr.
- Erste 100 Wörter: Regelprüfung, ob das Haupt-Keyword (oder seine Wortstämme) vorkommt.
- Alle drei als Hinweis, nicht als Blocker. Rank Math prüft Ähnliches; die Regeln sorgen dafür, dass der Entwurf es von Anfang an erfüllt.

### 7. Lokale Grundlagen (aus 7)

- NAP-Abgleich: Name, Adresse und Telefon im Profil mit Impressum, Kontaktseite und Schema vergleichen; Abweichungen melden.
- LocalBusiness-Schema über Rank Math aus dem Profil (steht als „Strukturierte Daten über Rank Math“ schon in Ausbau 2).
- Saisonale Beiträge jährlich zur Auffrischung vorschlagen (zu „Überarbeitung bestehender Beiträge“).
- Später: Beiträge für das Google-Unternehmensprofil aus neuen Entwürfen.

### 8. Entity SEO nach Casey Keith (aus 9, bündelt Teile von 1, 5 und 7)

Abgleich der zwölf Schritte mit dem Plugin in 1.0:

| Schritt | Heute im Plugin | Lücke |
|---|---|---|
| 1 Entity Home | Profil mit Leistungen; Einrichtung meldet „Leistungen ohne eigene Seite“ | keine feste Zuordnung Entität → Hauptseite |
| 2 Klare Beschreibung | Antwort zuerst (0.10.3), Kurzbeschreibung im Profil | Prüfung der Hauptseiten selbst fehlt |
| 3 Schema wie die Seite | Rank Math setzt Article-Schema | kein `about`/`mentions`/`sameAs` aus den Entitäten; kein Organization-/LocalBusiness-Schema aus dem Profil |
| 4 Gleiche Fakten | Unternehmensprofil als Quelle | kein Faktenblatt, kein NAP-Abgleich (Idee 7) |
| 5 Verwandte Entitäten | Entitäten je Thema, Briefing nutzt sie | keine 5–10 gezielt recherchierten Begriffe je Leistung (Idee 1) |
| 6 Säule und Cluster | Leistungsseiten, Themen je Leistung | keine Cluster-Struktur (Idee 1) |
| 7 Interne Links | Entwürfe verlinken passende Seiten | Ankertext nicht geprüft; Link zur Hauptseite der Entität nicht Pflicht |
| 8 Echte Fragen | Briefing nennt Fragen | Fragen aus der Search Console fehlen (Idee 5) |
| 9 Bilder beschriften | Alternativtext deutsch, KI-Bilder mit sprechendem Dateinamen (0.10.0) | Bildunterschrift fehlt |
| 10 Profile angleichen | – | Liste der eigenen Profile (`sameAs`) und Prüfliste nach dem Veröffentlichen |
| 11 Beobachten | Markensuchen im Bericht (0.10.5) | „neue Suchanfragen seit …“ je Seite, Erinnerung vierteljährlich |
| 12 Tiefe vor Breite | Priorität mit Relevanz und Lücke | keine Bevorzugung des angefangenen Clusters |

Bausteine, in dieser Reihenfolge vorgeschlagen:

1. **Steckbrief je Entwurf** (Vorlage aus 9): Das Briefing legt fest: Haupt-Entität, Ein-Satz-Definition, Zielgruppe, fünf verwandte Entitäten, drei Fragen, interne Links (mit der Hauptseite der Entität), Schema-Typ und Eigenschaften. Der Steckbrief steht beim Thema und im Prüfbericht. Klein, gleich wirksam.
2. **Schema aus den Entitäten über Rank Math** (Filter `rank_math/json_ld`): Für Entwürfe des Plugins `about` (Haupt-Entität) und `mentions` (genannte Entitäten), jeweils mit `sameAs` auf Wikidata, wo bestätigt. Nur Entitäten, die im Text stehen (Regel aus 9: Schema sagt nichts, was die Seite nicht sagt). Für die Startseite Organization bzw. LocalBusiness aus dem Profil mit `knowsAbout` (Leistungen), `areaServed` (Servicegebiet mit Wikidata) und `sameAs` (eigene Profile).
3. **Ankertext-Prüfung**: Hinweis im Prüfbericht bei Links mit „hier“, „mehr“, „klicken Sie hier“ usw.; Regel im Text: Ankertext nennt die Sache.
4. **Entity Home**: Je bestätigter Leistung, Unternehmen und Person eine Hauptseite (Vorschlag aus dem Index, Bestätigen wie bei Entitäten). Entwürfe verlinken die Hauptseite; der Bericht nennt Entitäten ohne Hauptseite und solche, bei denen zwei eigene Seiten um dieselben Suchanfragen konkurrieren.
5. **Faktenblatt und eigene Profile**: Seite „Unternehmen“ bekommt die Liste der eigenen Profile (Google-Unternehmensprofil, Facebook, LinkedIn, Branchenbücher) und erzeugt ein Faktenblatt (Name, Adresse, Telefon, Beschreibung, Leistungen, Gründung) zum Abgleich. NAP-Abgleich mit Impressum und Kontaktseite (Idee 7).
6. **Leistungs-Landkarte und Cluster** (Idee 1) mit Praxisnotizen (Idee 2); Themenrecherche bevorzugt Lücken im angefangenen Cluster (Tiefe vor Breite).
7. **Fragen aus der Search Console** (Idee 5) als FAQ-Vorschläge; **Beobachten**: neue Suchanfragen je Seite seit dem Veröffentlichen, vierteljährliche Erinnerung an die Markensuche.

Nicht übernommen: Der Beitrag nennt keine Belege und keine Messgrößen; die Schritte decken sich aber mit den Richtlinien von Google zu strukturierten Daten (Markup muss sichtbaren Inhalt wiedergeben).

