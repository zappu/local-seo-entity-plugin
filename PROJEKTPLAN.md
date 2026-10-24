# Projektplan

**Stand:** 22.10.2026 · Phase: MVP 1, Stufe B auf Staging geprüft (0.4.0), Nachbesserungen 0.4.1–0.4.3, Abnahme von Hand offen

## Ziel

Ein WordPress-Plugin, das für eine einzelne Website lokale SEO-Themen recherchiert, Entitäten erkennt und verknüpft und daraus hochwertige Beitragsentwürfe erstellt. Grundlage: Anforderungsspezifikation 1.0 (mit Nachtrag vom 18.10.2026) und technische Spezifikation v1.0 in `docs/spezifikation/`.

## Vorgehen

| Phase | Inhalt | Stand |
|---|---|---|
| Anforderungen | Grill-with-Docs, Anforderungsspezifikation 1.0 | erledigt |
| Architektur | Technische Spezifikation v0.1 bis v0.3 | erledigt |
| Testauswertung | Keywords und Entitäten für flaechenreiniger-wassertechnik.de mit echten Exporten | erledigt (17.10.2026) |
| Machbarkeitstest, Etappe 1 | Grundgerüst, Datenbank, Warteschlange, Cronjob, Wiederaufnahme, Doppelschutz (M1–M5) | erledigt (18.10.2026), auf Staging bestätigt |
| Machbarkeitstest, Etappe 2 | Vorbereitung durch Thomas: Staging-Website, OpenRouter-Schlüssel, Textfocus-Tarif prüfen, Google-Dienstkonto | Staging-Website und Cronjob erledigt, Schlüssel eingetragen; Textfocus-Tarif und Google-Dienstkonto offen |
| Machbarkeitstest, Etappe 3 | KI-Adapter, Search Console, Import, Textfocus, Entity-Erkennung, Entwurf mit Rank Math (M6–M18) | **vollständig bestanden** am 18.10.2026 auf Staging (0.2.0–0.2.3, PHP 8.4.26, WP 7.1.3, Rank Math, Elementor) |
| Spezifikation v1.0 | Offene Entscheidungen anhand der Testergebnisse festschreiben | erledigt (18.10.2026): KI-Budget 20 $/Monat und 1 $/Ablauf, 3 Treffer der Websuche, eigenes Recherchemodell, Testphase 20 Beiträge in 60 Tagen |
| MVP 1 | Umsetzung nach Spezifikation v1.0 in den Stufen A–F (0.3.0 bis 1.0.0) | Stufe A bestanden (0.3.0/0.3.1); Stufe B umgesetzt (0.4.0), Staging offen |

## Machbarkeitstest: Prüfpunkte

| Nr. | Prüfpunkt | Etappe | Ergebnis |
|---|---|---|---|
| M1 | Kompatibilität (PHP 8.1/8.3/8.4, WP 6.4/aktuell, mit Rank Math und Elementor) | 1 und Staging | bestanden in CI (PHP 8.1 + WP 6.4, PHP 8.3/8.4 + WP aktuell mit Rank Math und Elementor) und auf Staging (Plesk, PHP 8.4.26, WP 7.1.3, Rank Math, Elementor) |
| M2 | Datenbank und Migration | 1 | bestanden |
| M3 | Server-Cronjob über WP-CLI | 1 und Staging | bestanden auf Staging: Plesk-Aufgabe alle 5 Minuten mit `wp lsec cron`, daneben WP-Toolkit-Aufgabe für wp-cron.php |
| M4 | Wiederaufnahme nach Abbruch | 1 | bestanden lokal, in CI und auf Staging (Abbruch in Schritt 2 und nach Beitragsanlage, verzögerte Wiederholung) |
| M5 | Doppelschutz | 1 | bestanden lokal, in CI und auf Staging (5 gleichzeitige Starts, 24 gleichzeitige Bearbeitungsversuche) |
| M6 | Wiederholungen (429 verzögert, 401 sofort `failed`) | 3 | bestanden als Schnittstellentest (KI-Adapter, Textfocus, Wikidata); echter 429 von Wikidata wurde korrekt als wiederholbar behandelt; echter Fall: Anthropic-Ausgabenlimit (HTTP 400) sofort endgültig mit verständlicher Meldung |
| M7 | KI-Anbindung mit JSON-Schema, Korrekturversuch, Kosten | 3 | bestanden auf Staging am 18.10.2026 (OpenRouter, anthropic/claude-sonnet-5.5: gültiges JSON, 401/283 Token, $0,0036, 4,9 s); Korrekturversuch als Schnittstellentest |
| M7a | Recherche mit Websuche, Quellen in `lsec_sources` | 3 | bestanden auf Staging am 18.10.2026: 5 Quellen mit URL gespeichert, Strukturierung gültig; Kosten $0,148, davon $0,142 für die Recherche (44.229 Eingabe-Token durch die eingefügten Suchergebnisse) |
| M7b | Adapterwechsel | 3 | bestanden auf Staging am 18.10.2026: derselbe Aufruf über den OpenAI-Adapter (gpt-6.1-sol) mit gültigem JSON, auch zweistufig mit Websuche (4 Quellen); Anthropic direkt: Konto bis 01.11.2026 am Ausgabenlimit, vom Plugin richtig als endgültiger Fehler (HTTP 400) mit Klartext gemeldet |
| M8 | Geheimnisse | 3 | bestanden: Schlüssel nie in Protokoll, Zwischenspeicher oder Fehlermeldung (Textfocus wiederholt ihn in `params`, wird nicht gespeichert); auf Staging am 18.10.2026 mit `wp lsec check secrets` (0.3.1) ohne Fund in Datenbank und den Plesk-Fehlerprotokollen `error_log` und `proxy_error_log` |
| M9 | Search Console | 3 | bestanden auf Staging am 18.10.2026: Dienstkonto `lsec-staging` sieht die Property `https://flaechenreiniger-wassertechnik.de/` (siteRestrictedUser), 96 Zeilen (Suchanfrage × Seite) für 18.09.–15.10.2026, Deutschland, als 79 Suchanfragen gespeichert |
| M10 | CSV-/Excel-Import | 3 | bestanden mit den echten Dateien vom 17.10.2026 (Keyword Revealer, Keyword Chef) und echten Textfocus-Exporten vom 18.10.2026 (Keyword-Volumen, Entitäten-Tabelle); auf Staging bestätigt (CSV) |
| M11 | Entity-Erkennung mit Fundstellen, Entscheidung im Backend | 3 | bestanden auf Staging am 18.10.2026 (Seite 516, 1.333 Wörter): 9 Kandidaten mit 11 wörtlichen Fundstellen, $0,04 je Lauf; Bestätigen und Ablehnen im Backend mit Benutzer und Zeit gespeichert |
| M12 | Entwurf mit gültigen Blöcken | 3 | bestanden im Block-Editor (WordPress 6.4.5 und 7.1.3: 16 Blöcke gültig, keine Warnung); auf Staging bestätigt |
| M13 | Rank-Math-Seitenleiste | 3 | bestanden (Rank Math 1.0.280, WordPress 7.1.3: Titel, Beschreibung, drei Keywords in der Seitenleiste); auf Staging bestätigt |
| M14 | Elementor-Text | 3 | bestanden; auf Staging bestätigt |
| M15 | REST 403 | 3 | bestanden (5 Routen, Besucher und Abonnent 403); auf Staging bestätigt |
| M16 | Textfocus-API | 3 | bestanden auf Staging am 18.10.2026: `tf_account` (API-Zugang, AppSumo, 1.490 Credits, 60 Aufrufe/Tag), `tf_langs` (Code `de`), `tf_keyword` mit 2 Keywords in 1 Aufruf für 1 Credit liefert Volumina (flächenreinigung 110, wie bei Keyword Revealer), `tf_related` wiederholt aus dem Zwischenspeicher ohne Credit, Tagesbudget zählt mit; Warten bei erreichtem Budget als Schnittstellentest |
| M17 | Tippfehlerprüfung | 3 | bestanden mit den echten Keywords; auf Staging bestätigt |
| M18 | Ortsabgleich Burgdorf | 3 | bestanden mit echten Wikidata-Daten: ohne Kontext `unresolved`, mit Region Hannover `probable` (Q555618); auf Staging bestätigt; Praxistest Hemmingen aus der Entity-Erkennung: mit Servicegebiet Region Hannover `probable` (Q534259, 10,7 km), zweiter Kandidat 398 km entfernt |

## Offene Entscheidungen

- Recherchemodell (Auswahl in Stufe D) und Hauptmodell (Vergleich mit drei Beispielbeiträgen in Stufe E)
- KI-Sichtbarkeit als neue Anforderung (Screpy, optional Indexly), vor Ausbau 3
- Mindestwerte der Qualitätsprüfung (Kalibrierung in der Testphase)

## Erkenntnisse aus Etappe 1

- Auf einer Website, auf der Action Scheduler neu ist, schlagen auf eine Gruppe gefilterte Durchgänge fehl, bis die einmalige Datenmigration von Action Scheduler gelaufen ist. `wp lsec cron` schließt die Migration deshalb selbst ab.
- Ein abgestürzter Durchgang blockiert Action Scheduler, bis dessen Aufräumroutine greift. `wp lsec cron` gibt verwaiste Aufträge nach 10 Minuten frei.
- Rank Math bringt selbst Action Scheduler mit. Beide Kopien vertragen sich; geladen wird die neueste Version.
- Der Selbsttest (`bin/machbarkeitstest.sh`) läuft bei jedem Push in der CI und kann auf der Staging-Website wiederholt werden.
- Auf dem Staging-Server schreiben andere Plugins (Elementor-Logger) unter PHP 8.4 Hinweise in die WP-CLI-Ausgabe, auch mit `WP_DEBUG` aus. Alles, was WP-CLI-Ausgaben maschinell liest, muss solche Zeilen überspringen (ab 0.1.1 im Prüfskript umgesetzt).
- Auf dem Server WP-CLI nicht als root für WordPress ausführen, sondern als Benutzer des Abonnements, sonst entstehen Dateien mit falschem Besitzer. Prüfskript-Aufruf: siehe README.
- Plesk-WP-Toolkit legt eine eigene Aufgabe für `wp-cron.php` an. Sie bleibt für Rank Math und andere Plugins bestehen; `wp lsec cron` läuft zusätzlich.

## Erkenntnisse aus Etappe 3

- Rank Math 1.0.280 verlangt WordPress 6.7. Auf älteren Versionen definiert es seine Konstanten trotzdem und bleibt inaktiv. Das Plugin erkennt Rank Math deshalb erst an der Aktion `rank_math/loaded` und speichert sonst nur einen Vorschlag.
- Rank Math blendet seine Editor-Funktionen erst ein, wenn die Website verbunden oder die Registrierung übersprungen ist (Einrichtungsassistent).
- Das Block-Markup der Liste unterscheidet sich zwischen WordPress-Versionen (`wp-block-list` ab 6.6). Der BlockBuilder berücksichtigt das; geprüft im echten Block-Editor mit 6.4.5 und 7.1.3.
- Textfocus antwortet auf einen ungültigen Schlüssel mit HTTP 200 und `"response":404`. Fehler werden am Feld `response` erkannt. Die Antwort wiederholt den Schlüssel in `params`; gespeichert wird nur `result`.
- Wikidata begrenzt Anfragen je Adresse (429). Ergebnisse werden 30 Tage zwischengespeichert; ein 429 gilt als wiederholbar.
- Wikidata kennt mehr Orte namens Burgdorf als erwartet: neben Region Hannover und Landkreis Wolfenbüttel einen weiteren Eintrag im Landkreis Wolfenbüttel, dazu Ortskern, Bahnhof und Orte in der Schweiz. Ortsteile und Treffer über Aliasse anderer Sprachen werden ausgefiltert; ohne Kontext bleibt der Status `unresolved`.
- Die KI-Erkennung von Entitäten übernimmt nur Vorschläge mit wörtlicher Fundstelle im Text. Erfundene Zitate fallen dadurch heraus.
- Eine lokale Test-Installation ohne `AUTOMATIC_UPDATER_DISABLED` hat sich während eines Testlaufs selbst auf eine neue Hauptversion aktualisiert. Test-Installationen für feste Versionen brauchen diese Konstante.

- Die Websuche über OpenRouter ist der Kostentreiber: Die Suchergebnisse landen als rund 44.000 Eingabe-Token im Prompt (Recherche $0,142 gegenüber $0,005 für die Strukturierung). Zu prüfen für v1.0: weniger Treffer (`max_results`), ein günstigeres Modell nur für den Rechercheschritt oder ein Tagesbudget für KI-Kosten.
- Das Modell hat die Frage richtiggestellt (Uetze hat Ortsteile, keine Stadtteile) und nur belegte Tatsachen übernommen.

- Die Entity-Erkennung liefert bei zwei Läufen auf derselben Seite unterschiedliche Listen (7 bzw. 9 Vorschläge, leicht andere Namen). Neben echten Entitäten (Unternehmen, Ort, Hauptleistung) kommen allgemeine Formulierungen wie „Regelmäßige Wartung“. Die Prüfung im Backend bleibt deshalb Pflicht; für v1.0 prüfen: schärfere Anweisung, niedrige Temperatur (nicht jedes Modell erlaubt sie), Mindest-Sicherheit für die Anzeige.
- WordPress speichert „&“ im Titel als `&amp;`; der Titel wird vor der Erkennung in Klartext umgewandelt (ab 0.2.1).

- Staging-Server (Alfahosting-VPS): Die Resolver des Hosters (cnr1/cnr2.alfahosting.info) liefern für www.textfocus.net zeitweise 81.88.34.218 (host243.alfahosting-server.de) statt 217.70.189.36. Folge: cURL-Fehler 60, etwa jeder zweite Aufruf. Vermutlich eine veraltete Zone beim Hoster. Das trifft jeden Server mit diesen Resolvern, also auch Live-Websites beim selben Hoster. Ab 0.2.1 nennt die Fehlermeldung diese mögliche Ursache.

- Textfocus `tf_keyword`: Volumen und CPC kommen, `difficulty` war bei beiden Test-Keywords 0, `concurrency` (Wettbewerb 0–100) 74 bzw. 23. Ob 0 „unbekannt“ bedeutet, ist offen; für die Priorisierung vorerst `concurrency` nutzen. `force_refetch` kostet ebenfalls einen Credit. Das Restguthaben steht nicht im erwarteten Feld der Antwort; `tf_account` zeigt es zuverlässig.
- Textfocus-Sprachcodes sind frei vergebene Kürzel mit Sprache und Land in eigenen Feldern: Deutsch gibt es als `de` (Deutschland), `at` (Österreich), `ch` (Schweiz) und `bm` (Belgien). Aus dem Kürzel lässt sich das Land also nicht ableiten. Ab 0.2.1 wird die Locale beim Speichern mit abgelegt; auf Staging bestätigt (`de` → `de_DE`).

- Textfocus `tf_related` liefert für ein allgemeines Keyword vor allem Firmennamen (Hamburger Flächenreinigung, Helmut Günther, Aweka …) und andere Regionen (Hamburg, Seevetal), kaum Begriffe für die Region Hannover. Für die Themenrecherche braucht es einen Filter für Marken/Wettbewerber und fremde Orte (Abgleich mit Servicegebiet und Entitäten); regionale Abfragen besser mit Ortsnamen im Keyword.
- Textfocus `difficulty` war bei allen 17 Keywords 0, `cost` (CPC) außer beim Hauptkeyword ebenfalls 0. Vorschlag für v1.0: Bei Textfocus 0 als „unbekannt“ (NULL) speichern, wie bei Keyword Chef (Regel 6.6.1).

- Zwei Modelle, zwei Antworten: Claude (über OpenRouter) nennt 1226 als Ersterwähnung von **Eltze**, GPT (OpenAI) dieselbe Jahreszahl für **Dollbergen**, beide mit Quellenangabe. Mindestens eine Angabe ist falsch zugeordnet. Tatsachen aus der Recherche müssen daher gegen den Text der Quelle geprüft werden, bevor sie in einen Entwurf gehen (Qualitätsprüfung `UNSUPPORTED_FACT`, Abschnitt 8.7) – nicht nur gegen das Vorhandensein einer URL.
- OpenAI-Websuche: 28.839 Eingabe-Token (OpenRouter: 44.229), Quellen mit `?utm_source=openai`. Ab 0.2.2 werden Tracking-Parameter vor dem Speichern entfernt, sonst steht dieselbe Seite doppelt in `lsec_sources`. Für OpenAI direkt fehlt ein Preis in `ai_prices`, daher „Kosten unbekannt“.

- Textfocus-Entitäten der Startseite: 19 Einträge, darunter vier Schreibweisen des eigenen Firmennamens („Staats“, „Staats Flächenreinigung“, „Staats Flächenreinigung & Wassertechnik“, „Staats Flächenreinigungs-Service & Wassertechnik“), Orte außerhalb der Region (Braunschweig, Peine, Gifhorn, Celle) und sachfremde Begriffe (Kaugummi, Tartan). Für v1.0: Zusammenführen von Entitäten als Aliasse im Backend.
- Die Semantik-Analyse von Textfocus lieferte für „flächenreinigung hannover“ keine Ergebnisse (zu wenig Wettbewerber für das regionale Keyword).

- Search Console der Testwebsite (28 Tage): keine Klicks, Impressionen vor allem auf Positionen 20–90. Viele Anfragen mit „in der Nähe“ und Umlaut-Varianten („naehe“) laufen auf die Seite `/flaechenreinigung-hannover`; lokale Landingpages wie Wolfsburg (Position 7,3) und Laatzen ranken für eng passende Anfragen. Für die Themenrecherche: Search Console zeigt, wo Seiten schon sichtbar sind, aber nicht klicken (Titel/Beschreibung, Inhalt), und welche Orte gesucht werden.

## MVP 1: Stufen

| Stufe | Inhalt | Version | Stand |
|---|---|---|---|
| A | Schemaversion 3, KI-Budget, Recherchemodell, Restprüfung M8, Dashboard und Einstellungen | 0.3.0 | bestanden: lokal mit WordPress 6.4.5 und 7.1.3, CI, Staging am 18.10.2026 (`mvp-pruefung.sh` 25/25, `check secrets` ohne Fund für alle vier Schlüssel und den Dienstkonto-Schlüssel) |
| B | Entitäten: Profil, Inhaltsindex, Erkennung als Ablauf, Zusammenführen | 0.4.0 | Prüfskript auf Staging am 22.10.2026 bestanden (`mvp-pruefung.sh --online` 44/44, Hemmingen `probable` mit Region Hannover); offen von Hand: Profil eintragen, Staats-Schreibweisen zusammenführen, Index mit Erkennung. 0.4.1: Profil aus Webseiten lesen; 0.4.2: Produkte und Kategorieseiten im Index, Erkennung standardmäßig ohne Blogbeiträge, Anhalten; 0.4.3: Entitäten bearbeiten und löschen, Erkennung nach erster Auswertung geschärft |
| C | Keywords: täglicher Abgleich, Import im Backend, Filter | 0.5.0 | abgenommen am 22.10.2026 auf Staging: `tf_related` für „flächenreinigung“ schließt fremde Firmen und Hamburg aus; Servicegebiet Uetze mit 60 km Umkreis; offen nur der Nachweis des täglichen Laufs (C4 mit `--online`) |
| D | Themen: Recherche mit Websuche, Faktenprüfung, Priorität, Überschneidung, Freigabe | 0.6.0–0.6.5 | umgesetzt am 22.10.2026; lokal `mvp-pruefung.sh` 93/93; auf Staging nach den Korrekturen 0.6.1–0.6.5 mit `perplexity/sonar-pro` 70 % geprüfte Tatsachen (Lauf 120); offen: Abnahme durch Thomas, PDF-Quellen (Ausbau 2) |
| E | Produktion: Briefing, Style Mimic, Entwurf, Qualitätsprüfung, `content_production` über den Cronjob | 0.7.0–0.7.9 | abgenommen am 23.10.2026: erste bestandene Entwürfe (Bewertung 87–90), drei Entwürfe über den Cronjob, unbelegte Zahl blockiert, Kosten je Entwurf 0,005–0,22 USD; Modelle je Aufgabe (0.7.9): Text mit `anthropic/claude-sonnet-5.5` (Thomas: „menschlicher“), Briefing mit `openai/gpt-6-luna`, Prüfmodell nach Test |
| F | Einrichtungsassistent (Wunsch 22.10.), Abläufe-Seite, Berichte, Sammelauftrag, Dokumentation, Abnahme | 0.8.0–1.0.0 | in Arbeit: Einrichtungsassistent (0.8.0–0.8.3; getestet auf der Zahnarzt-Website: Einrichtung über den Assistenten, zwei Entwürfe von Thomas als sehr gut bewertet), Abläufe-Seite und Sammelauftrag (0.9.0), Content- und Entity-Bericht mit Hinweisen „bestehende Seite verbessern“ (0.9.1), Sammelentscheidung über Entitäten und Vergleich doppelter Themen (0.9.2), Beitragsbilder aus der Mediathek oder per KI und Sammelausschluss von Keywords (0.10.0, Wunsch von Thomas, Teil von Anforderung 8 vorgezogen), Kategorie für Ortsseiten (0.10.1), Empfehlung bei doppelten Themen (0.10.2), Antwort zuerst (0.10.3), Keyword-Platzierung (0.10.4), Bericht je Seite mit Marken- und Navigationssuchen (0.10.5); Ideen für Ausbau 2 in `docs/ausbau-2-ideen.md`; Dokumentation (Handbuch `docs/handbuch.md`, Abnahme-Checkliste `docs/abnahme-1.0.md`); offen: Abnahme |

## Erkenntnisse aus Stufe A

- `@wordpress/scripts` übersetzt JSX standardmäßig für `react-jsx-runtime`; dieses Skript gibt es erst ab WordPress 6.6. Die Quellen nutzen deshalb die klassische Übersetzung je Datei und keinen JSX-Spread.
- WordPress prüft Pflichtfelder einer REST-Route vor der Berechtigung. Ein als `required` markiertes Feld ließe Besucher 400 statt 403 sehen (M15); Pflichtfelder werden deshalb im Callback geprüft.

## Erkenntnisse aus Stufe B

- Ein vollständiger Indexlauf mit ausgeschalteter Erkennung markiert alle Inhalte als gelesen. Deshalb merkt sich jeder Beitrag zusätzlich, für welchen Inhalt die Erkennung gelaufen ist; das Prüfskript liest nur seine eigenen Testbeiträge (`--ids`).
- Der Selbsttest M4a in `machbarkeitstest.sh` geht davon aus, dass sonst nichts in der Warteschlange steht. Startet der Cronjob gleichzeitig einen geplanten Indexlauf, kann er einmal fehlschlagen; ein zweiter Lauf ist dann grün.

## Nächste Schritte

1. ~~0.2.0 auf Staging installieren und `bin/machbarkeitstest-etappe3.sh --online` ausführen~~ erledigt am 18.10.2026 (38/38).
2. ~~Prüfpunkte mit Kosten von Hand: M7, M7a, M7b, M11, M16~~ erledigt am 18.10.2026.
3. ~~Textfocus: Tarif, Sprachcode, Excel-Exporte~~ erledigt (AppSumo, API-Zugang, Code `de`; Exporte Keyword-Volumen und Entitäten in 0.2.2 eingebaut).
4. Ticket bei Alfahosting wegen der falschen DNS-Antwort für textfocus.net: gestellt, in Bearbeitung (Stand 18.10.2026). Bis zur Lösung bleibt der Eintrag in `/etc/hosts` auf Staging.
5. Tags v0.2.0 bis v0.2.4 liegen auf GitHub (18.10.2026).
6. ~~Google-Dienstkonto für M9~~ erledigt, Abruf auf Staging bestanden.
7. ~~Spezifikation v1.0~~ festgelegt am 18.10.2026. Noch offen: Recherchemodell (Stufe D), Hauptmodell (Stufe E), KI-Sichtbarkeit (vor Ausbau 3).
8. ~~MVP 1, Stufe A~~ auf Staging bestanden (0.3.0). Offen: Preis für das OpenAI-Modell in `ai_prices` (3 Aufrufe ohne Preis), Sichtprüfung von Dashboard und Einstellungen.
9. Danach Stufe B: Unternehmensprofil mit Servicegebiet, Inhaltsindex, Entity-Erkennung als Ablauf, Zusammenführen.
