# Änderungsprotokoll

## 0.8.1 – 23.10.2026

- **Einrichtungsassistent:** KI-Einstellungen kommen vor „Website lesen“ (das Lesen braucht ein Modell).
- **Cronjob-Anleitung für Plesk:** WP-CLI in `private` und eine Aufgabe „PHP-Skript ausführen“ mit den Pfaden der Website; die Aufgabe für `wp-cron.php` allein reicht nicht.
- **Search Console:** Schlüsseldatei auch unter anderem Namen, Adresse des Dienstkontos zum Eintragen, Warnung bei zu offenen Dateirechten.
- **Textfocus** als empfohlener Punkt; **Leistungen ohne Seite** werden auch im Seiteninhalt gesucht; laufende Abläufe heißen „läuft“ statt „gelaufen“.

## 0.8.0 – 23.10.2026

- **Einrichtungsassistent** (Stufe F): neue Seite „Einrichtung“ und `wp lsec setup` mit sechs Schritten – Technik prüfen, Website lesen (Profil und Servicegebiet automatisch), erprobte KI-Einstellungen mit einem Klick, Schreibstil aus den eigenen Seiten oder aus einem Freitext (z. B. ZimmWriter-Prompt, mit Konfliktprüfung), alles starten, Entscheidungen. Das Dashboard zeigt offene Schritte.
- **Style Mimic aus Freitext:** `StyleProfiler::from_text`.
- **Behoben:** fehlender Seitentitel auf der Seite „Style Mimic“.

## 0.7.16 – 23.10.2026

- **Ortsfakten im Text:** Ortsfakten werden mit dem Thema verbunden (z. B. Ortsteile als Einsatzorte) statt in einem eigenen Abschnitt aufgezählt; keine Sätze, dass ein Fakt keine Rolle spielt, keine Überschriften über den Text selbst.

## 0.7.15 – 23.10.2026

- **Fachlich notwendige Teilschritte:** Arbeitsschritte, die zwingend zu einer Leistung aus dem Profil gehören (z. B. Einsanden der Fugen nach der Pflasterreinigung), gelten beim Schreiben und in der Prüfung als Teil dieser Leistung.

## 0.7.14 – 23.10.2026

- **Ortsfakten:** Es zählen nur Tatsachen zum Ort des Themas (bisher alle Orte, deshalb startete die Recherche für Lehrte nicht).
- **Kleine Orte:** Findet die Ortsrecherche weniger als zwei Tatsachen, blockiert die Regel nicht mehr, sondern warnt.
- **Mehr Fachwissen, weniger Füllstoff:** Briefing und Text erklären Fachbegriffe und Verfahren des Themas gründlich; keine Abschnitte mit allgemeinen Tipps zur Anbieterwahl.

## 0.7.13 – 23.10.2026

- **Automatische Nachbesserung:** Scheitert ein Entwurf nur an einzelnen unbelegten Sätzen oder Zahlen, ersetzt das Schreibmodell genau diese Stellen, und der Entwurf wird einmal neu geprüft (Einstellung „Beanstandete Stellen automatisch nachbessern“).
- **Ortsfakten recherchieren:** Hat eine lokale Seite weniger als zwei geprüfte Tatsachen zum Ort, recherchiert das Plugin sie vor dem Briefing und prüft sie an den Quellen, einmal je Ort in 30 Tagen (Einstellung „Fehlende Ortsfakten recherchieren“).
- **Fehler behoben:** Themen ohne eigene Tatsachen aus der Recherche bekamen keine Tatsachen in den Entwurf (fehlerhafte Datenbankabfrage seit 0.7.0).
- **Bericht:** zeigt Ortsrecherche und Nachbesserung.

## 0.7.12 – 23.10.2026

- **Übliche Geschäftspraxis erlaubt:** „nach Absprache“, Besichtigung oder Vorgespräch vor dem Angebot, Preis je nach Einzelfall und Ähnliches gelten beim Schreiben und in der KI-Bewertung nicht mehr als unbelegt.

## 0.7.11 – 23.10.2026

- **Ortsfakten:** Der Entwurf nutzt Tatsachen aller Entitäten desselben Ortes („Uetze“, „Gemeinde Uetze“, Servicegebiet Uetze), nicht nur der ersten gefundenen.
- **Schreibregeln branchenneutral:** keine Absprachen, Abläufe, Kunden oder Ortsverallgemeinerungen ohne Beleg; stattdessen Fachwissen der Branche und Hinweise für die Auswahl eines Anbieters.

## 0.7.10 – 23.10.2026

- **Lokale Tatsachen:** Die Regel „mindestens zwei geprüfte Tatsachen zum Ort“ blockiert nur noch lokale Seiten (`local_landing`); bei Ratgebern und Beiträgen mit Ort ist sie eine Warnung. Die Meldung nennt, wie viele Tatsachen vorhanden waren.
- **Bericht:** zeigt Format, Ort und die geprüften Tatsachen im Kontext und im Text.
- **KI-Bewertung:** Der Hinweis, dass Preis und Dauer vom Einzelfall abhängen, ist kein Mangel mehr.

## 0.7.9 – 23.10.2026

- **Modelle je Aufgabe:** neue Einstellungen „Schreibmodell“ (`ai_writing_model`, nur für den Beitragstext) und „Prüfmodell“ (`ai_review_model`, nur für die KI-Bewertung); leer = Hauptmodell. Für einen einzelnen Entwurf: `wp lsec production start <thema> --writer=<modell>`.
- **Entwurf neu prüfen:** `wp lsec production review <entwurf> --model=<modell>` prüft einen vorhandenen Entwurf mit Regeln und KI-Bewertung neu, ohne ihn neu zu schreiben; zeigt das bisherige Ergebnis zum Vergleich. Gespeichertes Ergebnis und Status bleiben unverändert.

## 0.7.8 – 23.10.2026

- **Ablehnen bricht laufende Entwürfe ab:** Wird ein Thema abgelehnt oder zurückgesetzt, endet sein laufender Entwurf, und es entsteht kein Beitrag mehr.
- **Bericht:** `wp lsec production report` nimmt auch die Nummer des Entwurfs.
- **Spezifikation:** Hauptmodell `anthropic/claude-sonnet-5.5` festgelegt, Stufe E abgenommen.

## 0.7.7 – 23.10.2026

- **Doppelte Themen bei zusammengesetzten Leistungen:** „Gehweg- und Pflasterreinigung“ verhinderte die Erkennung von 39 und 43 als Dublette, weil Thema 39 kein „Gehweg“ enthält; der Cronjob schrieb deshalb einen zweiten Entwurf. Verglichen wird jetzt über einzelne Leistungswörter (hier „Pflaster“).

## 0.7.6 – 22.10.2026

- **Angaben des Betriebs aus der eigenen Website:** Zu jedem Entwurf liest das Plugin die passenden eigenen Leistungsseiten (höchstens zwei) und gibt ihren Text der KI als belegte Angaben. Vorgehen, Verfahren und Leistungen kommen so von der Website und bleiben aktuell, ohne Beschreibungen im Profil zu pflegen.
- **Bericht:** nennt die verwendeten eigenen Seiten.

## 0.7.5 – 22.10.2026

- **Unternehmensbeschreibung** aus dem Profil gilt als Angabe des Betriebs und geht an Briefing, Entwurf und Prüfung (bisher nicht).
- **Seite „Unternehmen“:** Die Beschreibung je Leistung ist ein breites, mehrzeiliges Feld mit Hinweis, was hineingehört (Vorgehen, Verfahren, Nachbehandlung).
- **Bericht:** nennt, ob eine Unternehmensbeschreibung vorlag.

## 0.7.4 – 22.10.2026

- **Kein erfundener Ablauf mehr:** Ohne Leistungsbeschreibung im Profil plant das Briefing keinen Abschnitt „So arbeiten wir“, und der Entwurf sagt dem Leser keine Schritte zu („Sie schildern uns …“). Stattdessen erklärt er das Verfahren allgemein.
- **Style Mimic:** Die Beispielsätze gehen nicht mehr an den Entwurf (sie wurden wörtlich übernommen), nur an die Bewertung.
- **Entwurf:** Keywords natürlicher, Servicegebiet und Standort nur einmal, keine unbelegte Ortskenntnis.
- **Bericht:** zeigt, zu welchen Leistungen eine Beschreibung im Profil vorliegt.

## 0.7.3 – 22.10.2026

- **Zahlenprüfung:** Ziffern in E-Mail-Adressen, Internetadressen und Domainnamen gelten nicht als Zahlen (auf Staging stehen sie im Hostnamen, z. B. „89-22-101-72“).
- **KI-Bewertung:** bewertet nicht die Formatierung der vereinfachten Textfassung; Tabellen mit Trennzeile.
- **Bericht:** Warnung, wenn KI-Aufrufe ohne Kostenangabe die Summe unvollständig machen.

## 0.7.2 – 22.10.2026

- **Leistungsbeschreibungen aus dem Unternehmensprofil** gelten als Angaben des Unternehmens: Der Entwurf darf Abläufe und Verfahren daraus übernehmen, die Prüfung akzeptiert sie. Ohne Beschreibung schreibt die KI Verfahren nur allgemein, nicht als Zusage des Betriebs.
- **KI-Bewertung** blockiert nur noch konkrete unbelegte Angaben (Zahlen, Preise, Dauern, Garantien, Zertifikate, Geräte, Ablaufzusagen, Tatsachen über Orte), nicht allgemeines Fachwissen.
- **Doppelte Themen:** Ortsseite und Beitrag zur selben Leistung am selben Ort gelten als Dublette (39 und 43).
- **Zahlenprüfung:** Telefonnummern auch mit geschützten Leerzeichen; der Bericht zeigt die Textstelle jeder unbelegten Zahl.
- **Entwurf:** einheitliche Wir-Form, jeder interne Link nur einmal, keine Zusagen zu Dauer oder Ergebnis.
- **Cronjob:** übersprungene Themen blockieren das Tageslimit nicht mehr; `wp lsec production schedule` nennt den Grund.

## 0.7.1 – 22.10.2026

- **Doppelte Themen:** Die Recherche kennt die vorhandenen Themen und schlägt dieselbe Leistung am selben Ort nicht noch einmal vor. Tut die KI es trotzdem, wird kein neues Thema angelegt; die neuen Tatsachen und Keywords gehen an das vorhandene. Die Themen-Seite markiert Dubletten, `wp lsec topics duplicates --apply` räumt vorhandene auf, und zu einer Dublette eines schon geschriebenen Themas entsteht kein zweiter Entwurf.
- **Zahlenprüfung:** Der Umkreis des Servicegebiets und anders gruppierte Telefonnummern aus dem Profil gelten als belegt (im ersten echten Entwurf fälschlich blockiert).
- **KI-Bewertung:** sieht den Entwurf mit Überschriften, Listen, Tabellen und Links und weiß, welche internen Links geprüft sind.
- **Entwurf:** Kontaktdaten nur einmal am Schluss, kein Einstieg mit dem Titel, keine bloßen Ortslisten, Stilbeispiele nicht wörtlich.

## 0.7.0 – 22.10.2026

MVP 1, Stufe E (Spezifikation v1.0, Abschnitt 14): Aus freigegebenen Themen entstehen Beitragsentwürfe.

- **Ablauf `content_production`:** Thema laden → Kontext (Profil, Entitäten, geprüfte Tatsachen, Keywords, Linkziele, Kategorien, Stil) → Briefing → Entwurf → Ergänzungen (SEO, Handlungsaufforderung) → Qualitätsprüfung → Entwurf in WordPress → Abschluss.
  - Entwürfe als Gutenberg-Blöcke mit Rank-Math-Titel, -Beschreibung und -Fokus-Keyword, immer als Entwurf.
  - Bei Mängeln entsteht der Entwurf trotzdem, mit Prüfbericht; der Ablauf endet dann mit „blockiert“.
- **Automatisch über den Cronjob:** freigegebene Themen nach Priorität, höchstens 3 Entwürfe am Tag (Einstellungen → Beiträge). Von Hand: „Beitrag schreiben“ auf der Themen-Seite.
- **Qualitätsprüfung:** 16 Regelprüfungen (u. a. jede Zahl mit Beleg, geprüfte Tatsachen, zwei lokale Tatsachen bei Ortsseiten, keine fremden Orte, kein ähnlicher Titel, keine Platzhalter, SEO-Längen) und eine KI-Bewertung (Suchintention, lokale Relevanz, Entitäten, Mehrwert, Lesbarkeit, Stil).
- **Neue Seite „Style Mimic“:** Schreibstil aus eigenen Beiträgen erzeugen, von Hand bearbeiten, Versionen.
- **Themen-Seite:** „Beitrag schreiben“, „neu schreiben“, Filter „in Arbeit“ und „Entwurf erstellt“, Prüfbericht mit Kosten, Bewertung und Mängeln, Link zum Entwurf.
- **WP-CLI:** `wp lsec production start|report|list|schedule`, `wp lsec style build|show|list|activate|set`. Modellvergleich mit `--model`.
- **Einstellungen:** neue Gruppe „Beiträge“ (`production_auto`, `drafts_per_day`, `draft_author`, `draft_lengths`, `quality_min_score`, `quality_min_average`).
- **Prüfskript:** E1–E4 (Entwurf bestanden, unbelegte Zahl blockiert, Tageslimit, Style Mimic); Unit-Tests der Regelprüfung.

## 0.6.5 – 22.10.2026

- **Widersprüche:** Nur Angaben mit einem Wert (Zahlen und Jahre wie Einwohner oder Ersterwähnung) können sich widersprechen. Zwei verschiedene Regeln oder Sehenswürdigkeiten zum selben Ort gelten nicht mehr als „unklar“.
- **Spezifikation:** Ergebnis des Vergleichs eingetragen; empfohlen ist `perplexity/sonar-pro` als Recherchemodell bei OpenRouter (70 % geprüfte Tatsachen).

## 0.6.4 – 22.10.2026

- **Faktenprüfung praxistauglich** (nach der Recherche mit Perplexity, bei der alle 11 richtig zitierten Tatsachen verworfen wurden):
  - Ein Wert gilt auch, wenn die KI ihn als eigenen Satz formuliert: Alle Zahlen müssen stimmen, bei Werten ohne Zahlen mindestens 60 % der Wörter.
  - Steht der Wert nicht im Absatz des Zitats, zählen die zwei Absätze davor und danach.
  - Der Ort darf im Titel oder in der Adresse der Seite stehen (z. B. uetze.de), solange der Absatz keinen anderen bekannten Ort nennt. Eltze/Dollbergen bleibt „unklar“.
  - Gegenstände wie „Sommerreinigung in Uetze“ oder „Gemeinde Uetze“ werden über den enthaltenen Ort zugeordnet.
- **Eigene Website** zählt nicht als Quelle für Tatsachen; diese Angaben stehen im Profil.
- **Auftrag an die KI:** Werte kurz und in den Worten der Quelle, keine Tatsachen über das eigene Unternehmen.
- **Bericht** `--facts` fasst gleiche Tatsachen aus mehreren Themen zusammen.

## 0.6.3 – 22.10.2026

- **Websuche nicht mehr abgeschnitten:** Die Antwort der Websuche darf 8000 statt 3000 Token lang sein. Bei Modellen mit Denkschritten brach sie vorher nach wenigen Absätzen ab. Ein Abbruch steht jetzt im Bericht.
- **Fehlersuche bei Tatsachen:** `wp lsec topics report <ablauf> --facts` zeigt jede Tatsache mit Prüfergebnis, Quelle, Zitat der KI und Beleg aus der Quelle. `wp lsec topics show` nennt den Prüfhinweis.

## 0.6.2 – 22.10.2026

- **Suchmaschine der Websuche wählbar** (nur OpenRouter): Einstellung „Suchmaschine“ (`ai_web_engine`: standard, native, exa, parallel, perplexity) und `wp lsec topics research --web-engine=…`. Hintergrund: Die Websuche des Modells lieferte in drei von vier echten Recherchen keine Quellen und damit keine Tatsachen.
- **Bericht** `wp lsec topics report`: nennt die Suchmaschine, warnt bei 0 Quellen und zeigt mit `--research` den Text der Websuche.

## 0.6.1 – 22.10.2026

Korrekturen nach den ersten echten Themenrecherchen auf der Testwebsite.

- **Überschneidung:** Eine allgemeine Seite (z. B. „Pflasterreinigung“) verdrängt kein Ortsthema mehr („Pflasterreinigung in Lehrte“). Hoch ist die Überschneidung nur, wenn Titel oder Pfad der Seite die Leistung und den Ort des Themas nennen. Sonst bleibt das Thema ein Vorschlag, und die allgemeinere Seite steht als teilweise Überschneidung dabei.
- **Faktenprüfung:** Sinngemäß wiedergegebene Zitate werden nicht mehr pauschal verworfen. Steht der Wert mit dem Ort oder Gegenstand im selben Absatz der Quelle, gilt die Tatsache als geprüft (Beleg „mittel“, Auszug ist der Satz der Quelle). Der Eltze/Dollbergen-Fall bleibt „unklar“.
- **Quellen:** Liefert das Recherchemodell keine Quellenangaben, liest das Plugin die Adressen aus dem Recherchetext. OpenRouter-Antworten mit `citations` (z. B. Perplexity) werden ebenfalls gelesen.
- **Prüfskript:** D1 prüft zusätzlich ein sinngemäßes Zitat und die allgemeine Seite (92 Prüfungen).

## 0.6.0 – 22.10.2026

MVP 1, Stufe D (Spezifikation v1.0, Abschnitt 14).

- **Neue Seite „Local SEO → Themen“:**
  - Recherche starten mit optionalem Schwerpunkt Leistung und Ort.
  - Vorschläge mit Priorität (0–100) und Aufschlüsselung, Begründung, Keywords, Entitäten und geprüften Tatsachen mit Quelle und Zitat.
  - Freigeben, Ablehnen, Zurücksetzen; einzelne Tatsachen bestätigen oder verwerfen.
- **Ablauf `topic_research`:** Websuche mit dem Recherchemodell, danach Strukturierung mit Profil, Keywords, Entitäten und bestehenden Inhalten.
- **Faktenprüfung:**
  - Jede Quelle wird abgerufen, das Zitat gesucht und der Bezug geprüft.
  - Eine Jahreszahl, die zu einem anderen Ortsteil gehört, gilt als „unklar“ statt als geprüft (Eltze/Dollbergen).
  - Widersprüche werden markiert.
  - Abgerufene Seiten bleiben 30 Tage im Zwischenspeicher.
- **Überschneidung:** Rankt für das Haupt-Keyword schon eine eigene Seite, oder trägt eine Seite es im Titel, erscheint das Thema unter „bestehende Seite verbessern“ statt als neuer Vorschlag. Das funktioniert auch auf Staging mit der Search Console der Live-Website.
- **Keywords ohne Bezug** zu den Leistungen schließt die Recherche als `off_topic` aus.
- **WP-CLI:** `wp lsec topics research|report|list|show|decide|fact`.
- **Vergleich von Trefferzahl und Recherchemodell:** `--web-results` und `--research-model` mit `topics report`.
- **Einstellungen:** neue Gruppe „Themen“ (`topic_count`, `topic_weights`).
- **Datenbank** auf Schemaversion 5.
- **Prüfskript:** D1 und D2.

## 0.5.0 – 22.10.2026

MVP 1, Stufe C (Spezifikation v1.0, Abschnitt 14).

- **Neue Seite „Local SEO → Keywords“:**
  - Tabelle mit Suchvolumen und Quelle, Wettbewerb, CPC, Impressionen, Klicks, Position und rankender Seite.
  - Filter nach Suche, Quelle und Status, Sortierung.
  - „Ausschließen“, „Aufnehmen“ und „Filter entscheiden lassen“.
- **Täglicher Keyword-Abgleich** über `wp lsec cron`:
  - Search Console, 28 Tage täglich und 90 Tage wöchentlich.
  - Orte und Namen in neuen Keywords erkennen (KI, wenige Cent je 100 Keywords).
  - Ausschlüsse setzen und fehlende Suchvolumina bei Textfocus nachholen, im Rahmen des Tagesbudgets und mit Tippfehlerprüfung.
  - Wird das Textfocus-Budget erreicht, wartet der Abgleich bis zum nächsten Tag.
- **Ausschlussgründe:** Wettbewerber, fremde Firma oder Marke, Ort außerhalb des Servicegebiets (über Ortsabgleich und abgelehnte Orte), von Hand.
- **Verwandte Keywords von Textfocus** (`tf_related`, 1 Credit) direkt auf der Seite oder mit `wp lsec keywords related`.
- **Import im Backend:** Vorschau, änderbare Spaltenzuordnung und eigene Quellkennung.
- **WP-CLI:** `wp lsec keywords table|sync|decide|related` und `wp lsec runs show`.
- **Einstellungen** in der neuen Gruppe „Keywords“: `keyword_analysis`, `keyword_auto_volumes`.
- **Datenbank** auf Schemaversion 4.
- **Prüfskript:** C1 bis C4.

## 0.4.3 – 22.10.2026

- Entitäten bearbeiten: Name, Typ, Beschreibung, Status (auch zurück auf Kandidat) und Schreibweisen; ein geänderter Name bleibt als Schreibweise (`wp lsec entities update`)
- Entitäten löschen, mit Fundstellen und Beziehungen; Einträge aus dem Unternehmensprofil bleiben geschützt (`wp lsec entities delete`)
- Entitäten-Tabelle: Bestätigen, Ablehnen und Bearbeiten auch für schon entschiedene Einträge
- Weitere Vorschläge zum Zusammenführen: gleicher Name mit anderem Typ, „Region/Raum Gehrden“ zu „Gehrden“
- Entity-Erkennung ordnet Leistungen denen aus dem Unternehmensprofil zu und lässt allgemeine Materialien weg; „Raum X“ wird zum Ort X
- Ortsabgleich: Gebiete über dem Servicegebiet (z. B. Niedersachsen) und das Servicegebiet selbst gelten als wahrscheinlich
- Prüfskript: B8

## 0.4.2 – 22.10.2026

- Inhaltsindex liest jetzt auch WooCommerce-Produkte und Kategorieseiten (Beitrags- und Produktkategorien); Warenkorb, Kasse, Kundenkonto und Standardkategorien bleiben draußen
- Neue Einstellung „Entitäten erkennen für“ (`entity_extract_types`): Standard Seiten, Produkte und Kategorien, Blogbeiträge aus. Gelesen werden weiterhin alle Inhalte, das kostet nichts
- Dashboard-Karte „Inhalte und Entitäten“: Stand je Art, fällige Erkennungen mit Kostenschätzung vor dem Start, Knopf „Anhalten“ für Index und Erkennungen
- `wp lsec index stop`, `wp lsec runs cancel --open [--type=…]`; `wp lsec runs list` zeigt das Objekt; `wp lsec index show|run` und `wp lsec entities extract` verstehen `term:<id>`
- Ein abgebrochener Ablauf wird beim erneuten Start wieder aufgenommen, erledigte Schritte werden nicht noch einmal bezahlt
- Prüfskript: B7 (Kategorieseiten, Auswahl der Erkennung, Abbrechen und Wiederaufnehmen)

## 0.4.1 – 22.10.2026

- Unternehmen: Textfeld „Seiten, aus denen die Angaben gelesen werden“ (bis zu acht Adressen, auch `/impressum`). „Angaben lesen“ lässt die KI Unternehmen, Standorte, Leistungen, Marken und Servicegebiet vorschlagen; übernommen wird nur, was wörtlich auf den Seiten steht, und nur, was angekreuzt ist (`wp lsec profile suggest`)

## 0.4.0 – 18.10.2026

MVP 1, Stufe B (Spezifikation v1.0, Abschnitt 14).

- Unternehmensprofil unter „Local SEO → Unternehmen“: Unternehmen, Servicegebiet mit Wikidata-Suche, Standorte mit Adresse und Öffnungszeiten, Leistungen, Marken, Wettbewerber; alles als bestätigte Entitäten mit Beziehungen (`wp lsec profile show|save`)
- Inhaltsindex aller veröffentlichten Beiträge und Seiten als Ablauf in Durchgängen zu 20; geänderte Beiträge werden vorgemerkt (`wp lsec index run|status|show`, Dashboard-Karte „Inhalte und Entitäten“)
- Entity-Erkennung als Ablauf mit Ortsabgleich über Wikidata; startet für neue und geänderte Inhalte automatisch (Einstellung `entity_auto_extract`), nie zweimal für denselben Inhalt
- Zusammenführen von Entitäten mit Vorschlägen für Schreibweisen der eigenen Firma (`wp lsec entities merge`, `suggest-merges`); spätere Erkennungen finden das Ziel
- Neue Seite „Local SEO → Entitäten“ mit Filtern, Vorschlägen, Zusammenführen und Details; ersetzt die bisherige Seite
- Schärfere Anweisung für die Entity-Erkennung; unsichere Vorschläge standardmäßig ausgeblendet
- `wp lsec entities add`, `list --search --min-confidence`, `show` mit Aliassen und Beziehungen
- Prüfskript `bin/mvp-pruefung.sh` um Stufe B erweitert (B6 Hemmingen mit `--online`)

## 0.3.1 – 18.10.2026

- `wp lsec check secrets` durchsucht unter Plesk auch die Fehlerprotokolle im Ordner `logs` neben dem Webroot (`error_log`, `proxy_error_log`), die der Benutzer des Abonnements lesen darf; weitere Dateien mit `--log=<datei>[,<datei>]`

## 0.3.0 – 18.10.2026

MVP 1, Stufe A (Spezifikation v1.0, Abschnitt 14).

- Backend-Oberfläche: Menü „Local SEO“ mit Dashboard (Einrichtungshinweise, KI-Kosten und Budget, Textfocus-Credits, Cronjob, Website, letzte Abläufe mit Fortsetzen und Abbrechen) und Einstellungen; die bisherige Statusseite heißt jetzt „Technik“
- KI-Budget: Monatsbudget (20 USD) und Budget je Ablauf (1 USD). Ist es erreicht, wartet der Ablauf und läuft nach Erhöhung oder im nächsten Monat weiter (`wp lsec ai budget`)
- Eigenes Modell für die Recherche mit Websuche (`ai_research_model`); Treffer der Websuche einstellbar (`ai_web_max_results`, Standard 3 statt 5)
- `wp lsec runs list|resume|cancel`
- `wp lsec check secrets`: sucht Schlüssel im Klartext in Datenbank und Protokoll (Restprüfung M8)
- Datenbank: Schemaversion 3 mit `competition` (Wettbewerb) bei Keyword-Kennzahlen, `exclusion` bei Keywords und `merged_into` bei Entitäten. Textfocus-Importe und `tf_keyword` speichern den Wettbewerb
- Änderungen an Einstellungen stehen im Prüfprotokoll
- Prüfskript `bin/mvp-pruefung.sh` für Stufe A, auch in der CI

## 0.2.4 – 18.10.2026

- `wp lsec keywords list` zeigt Klicks, Impressionen, CTR, Position und Seite aus der Search Console; mit `--source=gsc` eigene Ansicht, sortiert nach Impressionen

## 0.2.3 – 18.10.2026

- Google Search Console über ein Dienstkonto: `wp lsec gsc sites [--save]` und `wp lsec gsc fetch [--days] [--country] [--dry-run]`; Suchanfragen der letzten 28 Tage für Deutschland als Quelle `gsc` (Klicks, Impressionen, CTR, Position, Seite)
- Schlüsseldatei standardmäßig in `private/gsc-service-account.json` neben dem Webroot
- Protokolle schwärzen Google-Zugriffstoken

## 0.2.2 – 18.10.2026

- Textfocus-Excel „Keyword volume search“ wird erkannt (Expression, Monthly volume, CPC, Difficulty); Nullwerte gelten als unbekannt, auch bei der API
- Neuer Befehl `wp lsec entities import`: Entitäten-Tabelle einer Textfocus-Analyse als Kandidaten; Wikidata-Vorschlag nur als Hinweis
- Quellen: Tracking-Parameter (`utm_*`, `gclid`, `fbclid` …) werden vor dem Speichern entfernt; OpenAI hängt `utm_source=openai` an

## 0.2.1 – 18.10.2026

Erkenntnisse aus dem Test auf Staging.

- Textfocus: Sprachliste mit Sprache, Land und Locale; `langs --save` legt die Locale mit ab (Standardcode jetzt `de`)
- Entity-Erkennung: Beitragstitel wird als Klartext übergeben (vorher `&amp;` statt `&` in Fundstellen aus dem Titel)
- Textfocus in WP-CLI: Hinweis, wenn wegen des Mindestabstands gewartet wird
- HTTP: Bei Zertifikatsfehlern (cURL 60/51) Hinweis auf eine mögliche falsche DNS-Antwort des Servers

## 0.2.0 – 18.10.2026

Machbarkeitstest, Etappe 3: Bausteine für Recherche, Entitäten und Entwürfe.

- KI-Schnittstelle mit Adaptern für OpenRouter, Anthropic und OpenAI: strukturierte Antworten nach JSON-Schema mit einem Korrekturversuch, zweistufige Recherche mit Websuche, Verbrauch und Kosten in `lsec_ai_calls`, Quellen der Websuche in `lsec_sources` (`wp lsec ai test [--web]`, `wp lsec ai models`)
- Einstellungen über `wp lsec settings list|set`
- CSV-/Excel-Import für Keywords mit Vorlagen für Keyword Revealer, Keyword Chef, Textfocus (Excel) und allgemeiner Spaltenerkennung; BOM, Windows-1252, Trennzeichen und deutsche Zahlen werden erkannt; alle Volumina 0 = „unbekannt“, Monatswerte als Trend ohne Monatszuordnung (`wp lsec keywords import|list|imports`)
- Textfocus-Adapter mit Tagesbudget, 60 Sekunden Mindestabstand, Bündelung von `tf_keyword` und Zwischenspeicher (`wp lsec textfocus account|langs|keyword|query|calls`)
- Tippfehlerprüfung vor bezahlten Abfragen, z. B. „flächenreingung“ → „flächenreinigung“ (`wp lsec keywords check`)
- Ortsabgleich über Wikidata mit Servicegebiet (Verwaltungseinheit oder Umkreis) (`wp lsec entities resolve-place|service-area`)
- Entity-Erkennung per KI; übernommen wird nur, was eine wörtliche Fundstelle im Text hat; Backend-Seite „Local SEO → Entitäten“ zum Bestätigen und Ablehnen (`wp lsec entities extract|decide|show|list`)
- Text von Elementor-Seiten für den Inhaltsindex (`wp lsec check content`)
- Entwürfe mit gültigem Block-Markup (WordPress 6.4 bis 7.1), geprüften internen Links, Kategorie, Schlagwörtern und Rank-Math-Angaben (`wp lsec draft sample|create|show`)
- REST-Namensraum `lsec/v1`, 403 für alle ohne `lsec_manage` (`wp lsec check rest`)
- Datenbank: Schemaversion 2 (Spalte `response_body` in `lsec_provider_calls`)
- Prüfskript `bin/machbarkeitstest-etappe3.sh` für M10, M12–M15, M17 und mit `--online` M18
- JSON-Ausgaben der WP-CLI-Befehle enden mit Zeilenumbruch; vorher hängte der Elementor-Logger unter PHP 8.4 seine Meldung an das JSON. Beide Prüfskripte lesen JSON auch mit Text dahinter

## 0.1.1 – 18.10.2026

- Machbarkeitstest: bricht mit klarer Meldung ab, wenn PHP, WP-CLI oder das Plugin nicht erreichbar sind
- Machbarkeitstest: verträgt PHP-Hinweise anderer Plugins in der WP-CLI-Ausgabe (z. B. „Deprecated“ unter PHP 8.4)
- CI zusätzlich mit PHP 8.4

## 0.1.0 – 18.10.2026

Grundgerüst für den Machbarkeitstest, Etappe 1.

- Plugin-Bootstrap mit Prüfung auf PHP 8.1 und vorhandene Abhängigkeiten
- 20 Plugin-Tabellen nach Spezifikation v0.3, Migration über `dbDelta()` und `lsec_db_version`
- Ablaufsteuerung: Abläufe und Schritte mit dauerhaftem Status, Idempotenz-Schlüssel, Sperre mit Ablaufzeit, Wiederholungen (1, 5, 30 Minuten), Watchdog für abgebrochene Abläufe
- Action Scheduler als Auslöser, Heartbeat alle 5 Minuten
- WP-CLI: `wp lsec status`, `wp lsec cron`, `wp lsec migrate`, `wp lsec watchdog`, `wp lsec selftest …`
- Statusseite im Backend (Menü „Local SEO“), Hinweis bei fehlendem Cronjob
- Capability `lsec_manage`, verschlüsselte Ablage von API-Schlüsseln, Schwärzung von Geheimnissen in Protokollen
- Prüfskript `bin/machbarkeitstest.sh` für die Prüfpunkte M2 bis M5
