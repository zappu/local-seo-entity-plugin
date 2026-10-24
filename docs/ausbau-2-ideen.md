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
| 8 | The Semantic Lie | 30.07.2025 | Eine klare Absicht je Seite, Ziel im ersten Satz, benannte Entitäten und ausdrückliche Beziehungen, Schema; die Thesen zu Common Crawl, C4 und Updates sind unbelegt und keine Grundlage für Funktionen |

Umgesetzt: **Antwort zuerst** (0.10.3) aus 2 und 8, **Keyword-Platzierung** (0.10.4) aus 5.

## Ideen

### 1. Leistungs-Landkarte (aus 1, 7, 8) – Vorschlag als erster Baustein von Ausbau 2

- Je Leistung aus dem Profil recherchiert die KI mit Websuche: Materialien und Bauteile, Probleme und Ursachen, Verfahren, verwandte Leistungen, typische Kundenfragen. Ablage als Entitäten vom Typ „Begriff“ mit Beziehungen (`lsec_relations`), Bestätigen und Ablehnen wie bei den Entitäten.
- Die Leistungsseite ist die Hauptseite. Die Themenrecherche schlägt Unterseiten zu Lücken der Landkarte vor, z. B. „Ölflecken auf Pflaster entfernen“.
- Das Briefing bekommt nur die Begriffe, die die Suchabsicht der Seite braucht, nicht alle.
- Verlinkung: Unterseiten verlinken die Hauptseite. Der Bericht nennt fehlende Links von der Hauptseite zu den Unterseiten.
- Der Bericht zeigt je Leistung, welche Teile der Landkarte Inhalt haben.
- Entspricht „Dynamische Content-Cluster“ aus der Spezifikation (Ausbau 2).

### 2. Praxisnotizen je Leistung (aus 2)

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
