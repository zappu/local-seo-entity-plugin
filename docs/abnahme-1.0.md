# Abnahme Version 1.0

Prüfliste für die Abnahme von MVP 1 auf beiden Testwebsites. Je Punkt: Was tun, was soll herauskommen. Abhaken je Website; Abweichungen unten notieren.

- **S** = Staging Flächenreinigung (Staats Flächenreinigung & Wassertechnik)
- **Z** = Zahnarztpraxis Dr. Rust (Wees)

Grundlage: Anforderungsspezifikation 1.0 und technische Spezifikation 1.0, Abschnitt 14 (Stufen A–F). Die automatischen Prüfungen (Unit-Tests, `bin/mvp-pruefung.sh`, CI) laufen bei jeder Version grün; diese Liste prüft das Zusammenspiel im echten Betrieb.

## 1. Installation und Betrieb

| | Prüfen | Erwartet | S | Z |
|---|---|---|---|---|
| 1.1 | Neueste ZIP installieren (Update über die bestehende Version) | Plugin aktiv, keine Fehlermeldung, Seite **Technik** zeigt die neue Version und das aktuelle Schema | ✓ | ✓ |
| 1.2 | Dashboard, Karte „Cronjob“ | „läuft“, letzter Lauf vor höchstens 5 Minuten | ✓ | ✓ |
| 1.3 | Einrichtung | alle sechs Schritte erledigt oder nur bewusst Offenes (z. B. Textfocus) | ✓ | ✓ |
| 1.4 | **Technik → „Sicherheit prüfen“** | keine Schlüssel im Klartext | ✓ | ✓ |
| 1.5 | dieselbe Prüfung | alle Schnittstellen nur für Administratoren erreichbar | ✓ | ✓ |
| 1.6 | Dashboard, Karte „KI-Kosten“ | Kosten des Monats plausibel, unter dem Budget | ✓ | ✓ |
| 1.7 | Einrichtung, Dashboard und Einstellungen durchsehen | nirgends ein Befehl für die Shell; nur Zeilen für wp-config.php und die Felder der Aufgabe in Plesk zum Kopieren; Knöpfe (z. B. „Property wählen“, „Sprache der Website übernehmen“, Modellvorschläge beim Tippen) funktionieren | ✓ | ✓ |

## 2. Unternehmen, Entitäten, Keywords

| | Prüfen | Erwartet | S | Z |
|---|---|---|---|---|
| 2.1 | Seite „Unternehmen“ | Name, Standort, Leistungen mit Beschreibung, Servicegebiet stimmen | ✓ | ✓ |
| 2.2 | Seite „Entitäten“: mehrere Kandidaten markieren, „Bestätigen“ bzw. „Ablehnen“ | Status geändert, Meldung nennt übersprungene Profileinträge | ✓ | ✓ |
| 2.3 | Seite „Keywords“: Quelle, Status und Sortierung umstellen | Tabelle folgt jeder Auswahl | ✓ | ✓ |
| 2.4 | Seite „Keywords“: mehrere markieren, „Ausschließen“, dann unter dem Status „ausgeschlossen“ wieder „Aufnehmen“ | beides wirkt, Zähler oben stimmen | ✓ | ✓ |
| 2.5 | Täglicher Abgleich (Karte links oben) | „fertig“ mit heutigem oder gestrigem Datum; Search Console mit Anfragen | ✓ | ✓ |

## 3. Themen

| | Prüfen | Erwartet | S | Z |
|---|---|---|---|---|
| 3.1 | Neue Recherche für eine Leistung | Ablauf startet; nach einigen Minuten neue Vorschläge mit Priorität, Begründung und Tatsachen mit Quelle | ✓ | ✓ |
| 3.2 | Thema aufklappen | Priorität mit Aufschlüsselung, Keywords, Entitäten, Tatsachen, Überschneidung | ✓ | ✓ |
| 3.3 | Doppeltes Thema: „Vergleichen“ | beide nebeneinander, Empfehlung mit Gründen nachvollziehbar | ✓ | ✓ |
| 3.4 | „Empfehlung übernehmen“ | schwächeres Thema abgelehnt, zusätzliche Keywords beim behaltenen | ✓ | ✓ |
| 3.5 | Thema freigeben | erscheint unter „freigegeben“ | ✓ | ✓ |

## 4. Entwürfe

| | Prüfen | Erwartet | S | Z |
|---|---|---|---|---|
| 4.1 | Freigegebenes Thema schreiben lassen (einzeln oder Sammelauftrag mit Kostenschätzung) | Entwurf entsteht über den Cronjob, Ablauf „fertig“ oder „blockiert“ mit Grund | ✓ | ✓ |
| 4.2 | Prüfbericht beim Thema | Bewertung, Mängel und Hinweise verständlich; Kosten je Entwurf etwa 0,10–0,30 USD | ✓ | ✓ |
| 4.3 | Entwurf im Editor lesen | Erster Absatz beantwortet die Suchanfrage direkt; keine erfundenen Zahlen, Preise oder Zusagen; Ortsbezug mit geprüften Tatsachen; interne Links sinnvoll; Ton passt | ✓ | ✓ |
| 4.4 | Rank Math im Entwurf | SEO-Titel mit Keyword vorn, Beschreibung, Fokus-Keyword, kurze Titelform | ✓ | ✓ |
| 4.5 | Beitragsbild | Bild aus der Mediathek passend zum Thema, sonst KI-Bild ohne Schrift, Logos und erkennbaren Ort; Alternativtext auf Deutsch | ✓ | ✓ |
| 4.6 | Ortsseite (Kategorie für Ortsseiten eingestellt) | Entwurf zusätzlich in der Kategorie, „Allgemein“ weg | ✓ | ✓ |
| 4.7 | Einen fertigen Entwurf veröffentlichen und auf der Website ansehen | erscheint normal: mit Beitragsbild, Titel und Meta-Beschreibung aus Rank Math und funktionierenden internen Links | ✓ | ✓ |
| 4.8 | Nur mit Kategorie für Ortsseiten: unter Einstellungen → Beiträge „Ortsseiten auf der Blogseite ausblenden“ einschalten, dann die Beitragsübersicht (Seite mit den neuesten Beiträgen) aufrufen | veröffentlichte Ortsseiten stehen dort nicht mehr, im Kategorie-Archiv aber weiterhin; ohne Kategorie für Ortsseiten: abhaken | ✓ | ✓ |

## 5. Bilder

| | Prüfen | Erwartet | S | Z |
|---|---|---|---|---|
| 5.1 | Ein paar Stockfotos hochladen, im **Bericht** (Karte „Bilder“) „Offene Bilder jetzt beschreiben“ oder 15–20 Minuten warten | Karte zeigt sie als beschrieben; Alternativtext auf Deutsch, wo keiner war | ✓ | ✓ |
| 5.2 | **Beiträge → Alle Beiträge**: bei einem Beitrag ohne Bild unter dem Titel „Beitragsbild suchen“ | passendes Bild gesetzt, Meldung oben nennt die Quelle | ✓ | ✓ |

## 6. Bericht und Abläufe

| | Prüfen | Erwartet | S | Z |
|---|---|---|---|---|
| 6.1 | Bericht, „Bestehende Seiten verbessern“ | eine Zeile je Seite; Elementor-Seiten markiert; Suchen nach dem eigenen Namen und nach Öffnungszeiten im eigenen Abschnitt | ✓ | ✓ |
| 6.2 | „Als CSV herunterladen“ | Datei öffnet sich in Excel mit Umlauten und Spalten Art und Elementor | ✓ | ✓ |
| 6.3 | Bericht, Inhalte und Entitäten | Zahlen plausibel; Leistungen oder Orte ohne Inhalt als Anregung | ✓ | ✓ |
| 6.4 | Seite „Abläufe“ | Filter, Kosten, Schritte und KI-Aufrufe je Ablauf; Fortsetzen und Abbrechen | ✓ | ✓ |

## 7. Dokumentation

| | Prüfen | Erwartet | S | Z |
|---|---|---|---|---|
| 7.1 | **Local SEO → Handbuch** öffnen (auch über den Link „Handbuch“ neben jedem Seitentitel und in der Plugin-Liste) und lesen | Installation, Cronjob und Einrichtung ließen sich danach auf einer neuen Website nachvollziehen | ✓ | ✓ |

## Abweichungen und Entscheidungen

| Nr. | Website | Beobachtung | Entscheidung (beheben vor 1.0 / später / so lassen) |
|---|---|---|---|
| – | – | keine | – |

Hinweise aus der Abnahme: Bei Staats fehlte im Profil die Kurzbeschreibung (2.1; Daten, über „Angaben lesen“ zu ergänzen). Die Prüfung von 4.6 hatte zuerst keine Ortsseite, weil die Kategorie erst nach den Ortsseiten eingestellt war; seit 0.11.0 ordnet das Speichern der Einstellung vorhandene Ortsseiten zu. 7.1 führte zur Seite „Handbuch“ im Plugin (0.10.7), 1.7 zum Betrieb ohne Shell (0.11.0).

## Abnahme

Version: 0.11.0 (veröffentlicht als 1.0.0) · Datum: 24.10.2026 · Abgenommen von: Thomas Wulff · Ergebnis: alle 32 Punkte auf beiden Websites in Ordnung
