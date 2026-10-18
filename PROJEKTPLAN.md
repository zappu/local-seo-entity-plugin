# Projektplan

**Stand:** 18.10.2026 · Phase: Machbarkeitstest, Etappe 3 umgesetzt (0.2.0), Bestätigung auf Staging offen

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
| Machbarkeitstest, Etappe 3 | KI-Adapter, Search Console, Import, Textfocus, Entity-Erkennung, Entwurf mit Rank Math (M6–M18) | umgesetzt in 0.2.0 (ohne Search Console), lokal mit WordPress 6.4.5 und 7.1.3 und in der CI geprüft; Staging und Prüfpunkte mit Kosten offen |
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
| M7 | KI-Anbindung mit JSON-Schema, Korrekturversuch, Kosten | 3 | Schnittstellentest bestanden; echter Aufruf auf Staging offen (`wp lsec ai test`) |
| M7a | Recherche mit Websuche, Quellen in `lsec_sources` | 3 | Schnittstellentest bestanden, Speicherung lokal geprüft; echter Aufruf offen (`wp lsec ai test --web`) |
| M7b | Adapterwechsel | 3 | bestanden als Schnittstellentest mit allen drei Adaptern; echte Aufrufe offen |
| M8 | Geheimnisse | 3 | Schlüssel nie in Protokoll, Zwischenspeicher oder Fehlermeldung (Textfocus wiederholt ihn in `params`, wird nicht gespeichert); Suche auf Staging offen |
| M9 | Search Console | 3 | offen, wartet auf Google-Dienstkonto |
| M10 | CSV-/Excel-Import | 3 | bestanden mit den echten Dateien vom 17.10.2026; Excel-Export von Textfocus nur mit Testdatei, echte Datei offen |
| M11 | Entity-Erkennung mit Fundstellen, Entscheidung im Backend | 3 | Speicherung, Prüfung der Fundstellen und Backend-Entscheidung bestanden; KI-Aufruf mit echtem Beitrag offen (`wp lsec entities extract <ID>`) |
| M12 | Entwurf mit gültigen Blöcken | 3 | bestanden im Block-Editor (WordPress 6.4.5 und 7.1.3: 16 Blöcke gültig, keine Warnung) |
| M13 | Rank-Math-Seitenleiste | 3 | bestanden (Rank Math 1.0.280, WordPress 7.1.3: Titel, Beschreibung, drei Keywords in der Seitenleiste) |
| M14 | Elementor-Text | 3 | bestanden |
| M15 | REST 403 | 3 | bestanden (5 Routen, Besucher und Abonnent 403) |
| M16 | Textfocus-API | 3 | Schnittstellentest bestanden, echter Endpunkt erreicht (ungültiger Schlüssel richtig erkannt); Tarif, Sprachcode und Volumina auf Staging offen |
| M17 | Tippfehlerprüfung | 3 | bestanden mit den echten Keywords |
| M18 | Ortsabgleich Burgdorf | 3 | bestanden mit echten Wikidata-Daten: ohne Kontext `unresolved`, mit Region Hannover `probable` (Q555618) |

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

## Nächste Schritte

1. 0.2.0 auf Staging installieren und `bin/machbarkeitstest-etappe3.sh --online` ausführen.
2. Prüfpunkte mit Kosten von Hand: M7, M7a, M7b, M11, M16 (Befehle am Ende des Prüfskripts).
3. Textfocus: Tarif und Sprachcode prüfen, einen Excel-Export einer Textfocus-Tabelle für M10 bereitstellen.
4. Google-Dienstkonto für M9 (Search Console).
5. Danach Spezifikation v1.0 mit den offenen Entscheidungen.
