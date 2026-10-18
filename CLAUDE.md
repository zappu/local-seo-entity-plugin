# Local SEO & Entity Content Plugin

## Repository

- **Repo:** https://github.com/zappu/local-seo-entity-plugin (privat)
- **Branch:** `main`
- **Tags:** `v<Version>`, z. B. `v0.1.0`
- **Lokaler Ordner:** `D:\!claude_projekte\WP Plugins\Local-SEO-Plugin\` ist ein Git-Klon dieses Repos.
- **Nicht ins Repo:** `.env` und alle Zugangsdaten, `vendor/`, `node_modules/`, `build/`, ZIP-Dateien, Logs, Datenbank-Dumps.
- **Abgleich:** Vor und nach jeder Änderung nach dem Skill `github-abgleich`. Im Chat geht Code nur über GitHub, nie direkt in den Laptop-Ordner.
- **Commits:** deutsch, mit Präfix `Init:`, `feat:`, `fix:`, `docs:`, `chore:`, `refactor:`, `test:`.

## Release-Ablauf

1. Version erhöhen in `local-seo-entity-plugin.php` (Plugin-Header und `LSEC_VERSION`) und im Änderungsprotokoll `CHANGELOG.md`.
2. ZIP bauen: `bin/build-zip.sh` (installiert Composer-Abhängigkeiten ohne Entwicklungspakete in `build/` und packt das Plugin).
3. `git add`, `git commit -m "chore: Version x.y.z"`, `git tag vx.y.z`, `git push && git push --tags`.

## Projekt in Kürze

WordPress-Plugin für eine einzelne Website: recherchiert lokale SEO-Themen, erkennt und verknüpft Entitäten und erstellt daraus WordPress-Beitragsentwürfe mit Rank-Math-Angaben. Später optional automatische Veröffentlichung nach Freigabe.

- Anforderungen: `docs/spezifikation/anforderungsspezifikation-v1.0.md`
- Aktuelle technische Spezifikation: `docs/spezifikation/technische-spezifikation-v1.0.md` (v0.3 bleibt als Stand des Machbarkeitstests)
- Plan und Stand: `PROJEKTPLAN.md`, Begriffe: `GLOSSAR.md`

Der Machbarkeitstest ist bestanden (M1–M18). MVP 1 wird in den Stufen A–F nach Spezifikation v1.0, Abschnitt 14 umgesetzt. Die Spezifikation ist bindend; Abweichungen werden dort nachgetragen.

## Technik

- PHP ≥ 8.1, WordPress ≥ 6.4, Namespace `Lsec\` in `src/`, Präfix `lsec` für Tabellen, Optionen, Hooks und Capabilities.
- Warteschlange: Action Scheduler (Composer-Paket `woocommerce/action-scheduler`), Gruppe `lsec`. Der fachliche Ablauf liegt in `lsec_runs` und `lsec_run_steps`; der Action Scheduler stößt nur Schritte an.
- Cronjob auf dem Server: `wp lsec cron` alle 5 Minuten. Der Befehl schließt bei Bedarf die Datenmigration von Action Scheduler ab, gibt verwaiste Aufträge frei, startet den Watchdog und ruft dann `action-scheduler run --group=lsec` auf (Begründung: Spezifikation v1.0, Abschnitt 7.1).
- Prüfskript: `bin/machbarkeitstest.sh --path=<WordPress>` prüft M2–M5 auf einer echten Installation; läuft auch in der CI.
- Datenbank: Schema in `src/Infrastructure/Database/Schema.php`, Migration über `dbDelta()` und Option `lsec_db_version`. Jede Schemaänderung erhöht `Schema::VERSION`.
- Geheimnisse: Konstanten in `wp-config.php` haben Vorrang vor Optionen. Geheimnisse nie in Logs, Fehlermeldungen oder Exporte.

## Prüfungen vor jedem Commit

```bash
composer lint            # PHPCS, WordPress Coding Standards
composer analyse         # PHPStan
composer test:unit       # PHPUnit ohne WordPress
composer test:integration # PHPUnit mit WordPress-Testumgebung (siehe tests/README.md)
```
