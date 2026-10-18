# Local SEO & Entity Content

WordPress-Plugin, das lokale SEO-Themen recherchiert, Entitäten erkennt und daraus Beitragsentwürfe erstellt. Aktueller Stand: **Machbarkeitstest, Etappe 1** (Grundgerüst, Datenbank, Warteschlange, Cronjob, Wiederaufnahme, Doppelschutz).

Spezifikationen und Plan: [`docs/spezifikation/`](docs/spezifikation/), [`PROJEKTPLAN.md`](PROJEKTPLAN.md), [`GLOSSAR.md`](GLOSSAR.md).

## Installation auf der Staging-Website

1. ZIP bauen: `bash bin/build-zip.sh` (oder fertige ZIP verwenden) und in WordPress hochladen.
2. Plugin aktivieren. Die Tabellen werden dabei angelegt.
3. In `wp-config.php` den WordPress-eigenen Cron abschalten, wenn der Server-Cronjob läuft:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
4. In Plesk unter **Geplante Aufgaben** alle 5 Minuten ausführen (Pfad anpassen):
   ```bash
   wp --path=/var/www/vhosts/<domain>/httpdocs lsec cron
   ```
   Läuft daneben noch anderes über WP-Cron (z. B. WooCommerce), zusätzlich:
   ```bash
   wp --path=/var/www/vhosts/<domain>/httpdocs cron event run --due-now
   ```
5. Unter **Local SEO → Status** prüfen: „Cronjob in Ordnung: ja“.

## Machbarkeitstest

Auf der Staging-Website (nicht auf einer Live-Website mit echten Daten):

```bash
cd /var/www/vhosts/<domain>/httpdocs/wp-content/plugins/local-seo-entity-plugin
bash bin/machbarkeitstest.sh --path=/var/www/vhosts/<domain>/httpdocs
```

Das Skript prüft M2 (Tabellen und Migration), M3 (Cronjob über WP-CLI), M4 (Wiederaufnahme nach simuliertem Absturz) und M5 (Doppelschutz) und räumt danach auf.

## Entwicklung

```bash
composer install
composer lint        # PHPCS
composer analyse     # PHPStan
composer test:unit   # PHPUnit
```

GitHub Actions führt dieselben Prüfungen und den Machbarkeitstest mit PHP 8.1/WordPress 6.4 und PHP 8.3/aktuelles WordPress aus.
