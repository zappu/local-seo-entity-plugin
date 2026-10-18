# Projektplan

**Stand:** 18.10.2026 · Phase: Machbarkeitstest, Etappe 1 abgeschlossen, Etappe 2 offen

## Ziel

Ein WordPress-Plugin, das für eine einzelne Website lokale SEO-Themen recherchiert, Entitäten erkennt und verknüpft und daraus hochwertige Beitragsentwürfe erstellt. Grundlage: Anforderungsspezifikation 1.0 und technische Spezifikation v0.3 in `docs/spezifikation/`.

## Vorgehen

| Phase | Inhalt | Stand |
|---|---|---|
| Anforderungen | Grill-with-Docs, Anforderungsspezifikation 1.0 | erledigt |
| Architektur | Technische Spezifikation v0.1 bis v0.3 | erledigt |
| Testauswertung | Keywords und Entitäten für flaechenreiniger-wassertechnik.de mit echten Exporten | erledigt (17.10.2026) |
| Machbarkeitstest, Etappe 1 | Grundgerüst, Datenbank, Warteschlange, Cronjob, Wiederaufnahme, Doppelschutz (M1–M5) | erledigt (18.10.2026), Bestätigung auf Staging offen |
| Machbarkeitstest, Etappe 2 | Vorbereitung durch Thomas: Staging-Website, OpenRouter-Schlüssel, Textfocus-Tarif prüfen, Google-Dienstkonto | offen |
| Machbarkeitstest, Etappe 3 | KI-Adapter, Search Console, Import, Textfocus, Entity-Erkennung, Entwurf mit Rank Math (M6–M18) | offen |
| Spezifikation v1.0 | Offene Entscheidungen anhand der Testergebnisse festschreiben | offen |
| MVP 1 | Umsetzung nach Spezifikation v1.0 | offen |

## Machbarkeitstest: Prüfpunkte

| Nr. | Prüfpunkt | Etappe | Ergebnis |
|---|---|---|---|
| M1 | Kompatibilität (PHP 8.1/8.3, WP 6.4/aktuell, mit Rank Math und Elementor) | 1 und Staging | bestanden in CI: PHP 8.1 + WP 6.4, PHP 8.3 + WP aktuell mit Rank Math 1.0.280 und Elementor; Staging offen |
| M2 | Datenbank und Migration | 1 | bestanden |
| M3 | Server-Cronjob über WP-CLI | 1 und Staging | bestanden mit `wp lsec cron`; Plesk-Cronjob offen |
| M4 | Wiederaufnahme nach Abbruch | 1 | bestanden (Abbruch in Schritt 2 und nach Beitragsanlage, verzögerte Wiederholung) |
| M5 | Doppelschutz | 1 | bestanden (5 gleichzeitige Starts, 24 gleichzeitige Bearbeitungsversuche) |
| M6–M18 | siehe Spezifikation v0.3, Abschnitt 12 | 3 | offen |

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
