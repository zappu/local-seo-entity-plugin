# Local SEO & Entity Content

WordPress-Plugin, das lokale SEO-Themen recherchiert, Entitäten erkennt und daraus Beitragsentwürfe erstellt. Aktueller Stand: **Machbarkeitstest, Etappe 3** (KI-Adapter, Keyword-Import, Textfocus, Tippfehlerprüfung, Entitäten mit Ortsabgleich, Entwürfe mit Rank Math, Elementor-Text, REST-Schutz).

Spezifikationen und Plan: [`docs/spezifikation/`](docs/spezifikation/), [`PROJEKTPLAN.md`](PROJEKTPLAN.md), [`GLOSSAR.md`](GLOSSAR.md).

## Installation auf der Staging-Website

1. ZIP bauen: `bash bin/build-zip.sh` (oder fertige ZIP verwenden) und in WordPress hochladen.
2. Plugin aktivieren. Die Tabellen werden dabei angelegt.
3. In `wp-config.php` den WordPress-eigenen Cron abschalten, wenn der Server-Cronjob läuft:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
4. In Plesk unter **Geplante Aufgaben** im Abonnement alle 5 Minuten ausführen (Pfad anpassen):
   ```bash
   wp --path=/var/www/vhosts/<domain>/httpdocs lsec cron
   ```
   Ohne Shell-Zugang für den Benutzer des Abonnements: Aufgabentyp „PHP-Skript ausführen“ mit `wp-cli.phar` als Skript und `--path=… lsec cron` als Argumenten.
   Die Aufgabe des WP Toolkits für `wp-cron.php` bleibt bestehen; sie bedient die übrigen Plugins.
5. Unter **Local SEO → Status** prüfen: „Cronjob in Ordnung: ja“.

## Machbarkeitstest

Auf der Staging-Website (nicht auf einer Live-Website mit echten Daten):

```bash
cd /var/www/vhosts/<domain>
U=$(stat -c %U httpdocs)
[ -f wp-cli.phar ] || { curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chown "$U":psacln wp-cli.phar; }
sudo -u "$U" env PATH=/opt/plesk/php/8.4/bin:/usr/bin:/bin WP="php $PWD/wp-cli.phar" \
  bash httpdocs/wp-content/plugins/local-seo-entity-plugin/bin/machbarkeitstest.sh --path="$PWD/httpdocs"
```

Als Benutzer des Abonnements ausführen, nicht als root.

Das Skript prüft M2 (Tabellen und Migration), M3 (Cronjob über WP-CLI), M4 (Wiederaufnahme nach simuliertem Absturz) und M5 (Doppelschutz) und räumt danach auf.

Etappe 3 (gleicher Aufruf, anderes Skript):

```bash
sudo -u "$U" env PATH=/opt/plesk/php/8.4/bin:/usr/bin:/bin WP="php $PWD/wp-cli.phar" \
  bash httpdocs/wp-content/plugins/local-seo-entity-plugin/bin/machbarkeitstest-etappe3.sh --path="$PWD/httpdocs" --online
```

Es prüft ohne Kosten M10 (Import), M12/M13 (Entwurf, Rank Math), M14 (Elementor), M15 (REST), M17 (Tippfehler) und mit `--online` M18 (Wikidata). Mit `--keep-draft` bleibt der Testentwurf für die Sichtprüfung im Block-Editor stehen. Am Ende listet es die Befehle für die Prüfpunkte mit Kosten (KI, Textfocus).

## Entwicklung

```bash
composer install
composer lint        # PHPCS
composer analyse     # PHPStan
composer test:unit   # PHPUnit
```

GitHub Actions führt dieselben Prüfungen und beide Machbarkeitstest-Skripte mit PHP 8.1/WordPress 6.4 und PHP 8.3/8.4 mit aktuellem WordPress, Rank Math und Elementor aus.
