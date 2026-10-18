# Projektplan

**Stand:** 18.10.2026 · Phase: Machbarkeitstest, Etappe 3 auf Staging bestätigt (0.2.0, 38/38 ohne Kosten); Prüfpunkte mit Kosten offen

## Ziel

Ein WordPress-Plugin, das für eine einzelne Website lokale SEO-Themen recherchiert, Entitäten erkennt und verknüpft und daraus hochwertige Beitragsentwürfe erstellt. Grundlage: Anforderungsspezifikation 1.0 und technische Spezifikation v0.3 in `docs/spezifikation/`.

## Vorgehen

| Phase | Inhalt | Stand |
|---|---|---|
| Anforderungen | Grill-with-Docs, Anforderungsspezifikation 1.0 | erledigt |
| Architektur | Technische Spezifikation v0.1 bis v0.3 | erledigt |
| Testauswertung | Keywords und Entitäten für flaechenreiniger-wassertechnik.de mit echten Exporten | erledigt (17.10.2026) |
| Machbarkeitstest, Etappe 1 | Grundgerüst, Datenbank, Warteschlange, Cronjob, Wiederaufnahme, Doppelschutz (M1–M5) | erledigt (18.10.2026), auf Staging bestätigt |
| Machbarkeitstest, Etappe 2 | Vorbereitung durch Thomas: Staging-Website, OpenRouter-Schlüssel, Textfocus-Tarif prüfen, Google-Dienstkonto | Staging-Website und Cronjob erledigt, Schlüssel eingetragen; Textfocus-Tarif und Google-Dienstkonto offen |
| Machbarkeitstest, Etappe 3 | KI-Adapter, Search Console, Import, Textfocus, Entity-Erkennung, Entwurf mit Rank Math (M6–M18) | umgesetzt in 0.2.0 (ohne Search Console), lokal mit WordPress 6.4.5 und 7.1.3, in der CI und auf Staging geprüft (PHP 8.4.26, WP 7.1.3, Rank Math, Elementor: 38/38 am 18.10.2026); Prüfpunkte mit Kosten offen |
| Spezifikation v1.0 | Offene Entscheidungen anhand der Testergebnisse festschreiben | offen |
| MVP 1 | Umsetzung nach Spezifikation v1.0 | offen |

## Machbarkeitstest: Prüfpunkte

| Nr. | Prüfpunkt | Etappe | Ergebnis |
|---|---|---|---|
| M1 | Kompatibilität (PHP 8.1/8.3/8.4, WP 6.4/aktuell, mit Rank Math und Elementor) | 1 und Staging | bestanden in CI (PHP 8.1 + WP 6.4, PHP 8.3/8.4 + WP aktuell mit Rank Math und Elementor) und auf Staging (Plesk, PHP 8.4.26, WP 7.1.3, Rank Math, Elementor) |
| M2 | Datenbank und Migration | 1 | bestanden |
| M3 | Server-Cronjob über WP-CLI | 1 und Staging | bestanden auf Staging: Plesk-Aufgabe alle 5 Minuten mit `wp lsec cron`, daneben WP-Toolkit-Aufgabe für wp-cron.php |
| M4 | Wiederaufnahme nach Abbruch | 1 | bestanden lokal, in CI und auf Staging (Abbruch in Schritt 2 und nach Beitragsanlage, verzögerte Wiederholung) |
| M5 | Doppelschutz | 1 | bestanden lokal, in CI und auf Staging (5 gleichzeitige Starts, 24 gleichzeitige Bearbeitungsversuche) |
| M6 | Wiederholungen (429 verzögert, 401 sofort `failed`) | 3 | bestanden als Schnittstellentest (KI-Adapter, Textfocus, Wikidata); echter 429 von Wikidata wurde korrekt als wiederholbar behandelt |
| M7 | KI-Anbindung mit JSON-Schema, Korrekturversuch, Kosten | 3 | bestanden auf Staging am 18.10.2026 (OpenRouter, anthropic/claude-sonnet-5.5: gültiges JSON, 401/283 Token, $0,0036, 4,9 s); Korrekturversuch als Schnittstellentest |
| M7a | Recherche mit Websuche, Quellen in `lsec_sources` | 3 | bestanden auf Staging am 18.10.2026: 5 Quellen mit URL gespeichert, Strukturierung gültig; Kosten $0,148, davon $0,142 für die Recherche (44.229 Eingabe-Token durch die eingefügten Suchergebnisse) |
| M7b | Adapterwechsel | 3 | bestanden als Schnittstellentest mit allen drei Adaptern; echte Aufrufe offen |
| M8 | Geheimnisse | 3 | Schlüssel nie in Protokoll, Zwischenspeicher oder Fehlermeldung (Textfocus wiederholt ihn in `params`, wird nicht gespeichert); Suche auf Staging offen |
| M9 | Search Console | 3 | offen, wartet auf Google-Dienstkonto |
| M10 | CSV-/Excel-Import | 3 | bestanden mit den echten Dateien vom 17.10.2026; Excel-Export von Textfocus nur mit Testdatei, echte Datei offen; auf Staging bestätigt |
| M11 | Entity-Erkennung mit Fundstellen, Entscheidung im Backend | 3 | bestanden auf Staging am 18.10.2026 (Seite 516, 1.333 Wörter): 9 Kandidaten mit 11 wörtlichen Fundstellen, $0,04 je Lauf; Bestätigen und Ablehnen im Backend mit Benutzer und Zeit gespeichert |
| M12 | Entwurf mit gültigen Blöcken | 3 | bestanden im Block-Editor (WordPress 6.4.5 und 7.1.3: 16 Blöcke gültig, keine Warnung); auf Staging bestätigt |
| M13 | Rank-Math-Seitenleiste | 3 | bestanden (Rank Math 1.0.280, WordPress 7.1.3: Titel, Beschreibung, drei Keywords in der Seitenleiste); auf Staging bestätigt |
| M14 | Elementor-Text | 3 | bestanden; auf Staging bestätigt |
| M15 | REST 403 | 3 | bestanden (5 Routen, Besucher und Abonnent 403); auf Staging bestätigt |
| M16 | Textfocus-API | 3 | bestanden auf Staging am 18.10.2026: `tf_account` (API-Zugang, AppSumo, 1.490 Credits, 60 Aufrufe/Tag), `tf_langs` (Code `de`), `tf_keyword` mit 2 Keywords in 1 Aufruf für 1 Credit liefert Volumina (flächenreinigung 110, wie bei Keyword Revealer), Tagesbudget zählt mit; Zwischenspeicher bei `tf_langs` bestätigt, bei einem kostenpflichtigen Endpunkt noch offen |
| M17 | Tippfehlerprüfung | 3 | bestanden mit den echten Keywords; auf Staging bestätigt |
| M18 | Ortsabgleich Burgdorf | 3 | bestanden mit echten Wikidata-Daten: ohne Kontext `unresolved`, mit Region Hannover `probable` (Q555618); auf Staging bestätigt; Praxistest Hemmingen aus der Entity-Erkennung: mit Servicegebiet Region Hannover `probable` (Q534259, 10,7 km), zweiter Kandidat 398 km entfernt |

## Offene Entscheidungen

- Konkretes KI-Modell (Testläufe mit deutschen Beispielbeiträgen)
- Suchvolumina im vorhandenen Textfocus-Tarif
- KI-Sichtbarkeit als neue Anforderung (Screpy, optional Indexly)
- Mindestwerte der Qualitätsprüfung und Umfang der Testphase

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

## Nächste Schritte

1. ~~0.2.0 auf Staging installieren und `bin/machbarkeitstest-etappe3.sh --online` ausführen~~ erledigt am 18.10.2026 (38/38).
2. Prüfpunkte mit Kosten von Hand: M7, M7a, M7b, M11, M16 (Befehle am Ende des Prüfskripts).
3. Textfocus: Tarif und Sprachcode prüfen, einen Excel-Export einer Textfocus-Tabelle für M10 bereitstellen.
4. Google-Dienstkonto für M9 (Search Console).
5. Danach Spezifikation v1.0 mit den offenen Entscheidungen.
