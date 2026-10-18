# Projektplan

**Stand:** 18.10.2026 · Phase: Machbarkeitstest, Etappe 1

## Ziel

Ein WordPress-Plugin, das für eine einzelne Website lokale SEO-Themen recherchiert, Entitäten erkennt und verknüpft und daraus hochwertige Beitragsentwürfe erstellt. Grundlage: Anforderungsspezifikation 1.0 und technische Spezifikation v0.3 in `docs/spezifikation/`.

## Vorgehen

| Phase | Inhalt | Stand |
|---|---|---|
| Anforderungen | Grill-with-Docs, Anforderungsspezifikation 1.0 | erledigt |
| Architektur | Technische Spezifikation v0.1 bis v0.3 | erledigt |
| Testauswertung | Keywords und Entitäten für flaechenreiniger-wassertechnik.de mit echten Exporten | erledigt (17.10.2026) |
| Machbarkeitstest, Etappe 1 | Grundgerüst, Datenbank, Warteschlange, Cronjob, Wiederaufnahme, Doppelschutz (M1–M5) | in Arbeit |
| Machbarkeitstest, Etappe 2 | Vorbereitung durch Thomas: Staging-Website, OpenRouter-Schlüssel, Textfocus-Tarif prüfen, Google-Dienstkonto | offen |
| Machbarkeitstest, Etappe 3 | KI-Adapter, Search Console, Import, Textfocus, Entity-Erkennung, Entwurf mit Rank Math (M6–M18) | offen |
| Spezifikation v1.0 | Offene Entscheidungen anhand der Testergebnisse festschreiben | offen |
| MVP 1 | Umsetzung nach Spezifikation v1.0 | offen |

## Machbarkeitstest: Prüfpunkte

| Nr. | Prüfpunkt | Etappe | Ergebnis |
|---|---|---|---|
| M1 | Kompatibilität (PHP 8.1/8.3, WP 6.4/aktuell, mit Rank Math und Elementor) | 1 und Staging | offen |
| M2 | Datenbank und Migration | 1 | offen |
| M3 | Server-Cronjob über WP-CLI | 1 und Staging | offen |
| M4 | Wiederaufnahme nach Abbruch | 1 | offen |
| M5 | Doppelschutz | 1 | offen |
| M6–M18 | siehe Spezifikation v0.3, Abschnitt 12 | 3 | offen |

## Offene Entscheidungen

- Konkretes KI-Modell (Testläufe mit deutschen Beispielbeiträgen)
- Suchvolumina im vorhandenen Textfocus-Tarif
- KI-Sichtbarkeit als neue Anforderung (Screpy, optional Indexly)
- Mindestwerte der Qualitätsprüfung und Umfang der Testphase
