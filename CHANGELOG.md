# Änderungsprotokoll

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
