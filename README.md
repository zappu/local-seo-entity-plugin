# Local SEO & Entity Content

WordPress-Plugin, das lokale SEO-Themen recherchiert, Entitäten erkennt und daraus Beitragsentwürfe erstellt. Aktueller Stand: **MVP 1, Stufe B** (Unternehmensprofil, Inhaltsindex, Entity-Erkennung als Ablauf, Zusammenführen von Entitäten; davor Stufe A mit KI-Budget, Dashboard und Einstellungen) auf Grundlage des bestandenen Machbarkeitstests. Plan in Spezifikation v1.0, Abschnitt 14.

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
5. Unter **Local SEO → Dashboard** prüfen: Karte „Cronjob“ zeigt „läuft“. Hinweise oben auf der Seite nennen, was noch fehlt, jeweils mit dem passenden Befehl.

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

## MVP 1: Prüfung je Stufe

```bash
sudo -u "$U" env PATH=/opt/plesk/php/8.4/bin:/usr/bin:/bin WP="php $PWD/wp-cli.phar" \
  bash httpdocs/wp-content/plugins/local-seo-entity-plugin/bin/mvp-pruefung.sh --path="$PWD/httpdocs"
```

Stufe A: Schemaversion 3 mit Migration 2 → 3, KI-Budget (ein Ablauf wartet und läuft nach Erhöhung weiter, über eine KI-Attrappe ohne Kosten), REST-Schutz der neuen Routen, Dashboard-Daten, Suche nach Schlüsseln im Klartext (M8) und gebaute Oberfläche. Einstellungen werden danach wiederhergestellt.

Nützliche Befehle:

```bash
wp lsec ai budget              # Kosten im Monat, Grenzen
wp lsec runs list --status=waiting
wp lsec runs resume <id>       # wartenden Ablauf fortsetzen
wp lsec check secrets          # Schlüssel im Klartext in Datenbank oder Protokoll?
wp lsec settings set ai_research_model <modell>
wp lsec profile show --format=json > profil.json   # Unternehmensprofil sichern / bearbeiten
wp lsec profile suggest / /impressum /kontakt      # Vorschlag aus Webseiten (ein KI-Aufruf)
wp lsec index run --now        # Inhaltsindex; startet bei entity_auto_extract die Erkennung (KI-Kosten) für entity_extract_types
wp lsec index stop             # Index und offene Entity-Erkennungen abbrechen
wp lsec entities suggest-merges
wp lsec entities merge <ziel> <quelle>…
```

Stufe B (gleiches Skript): Inhaltsindex mit den eigenen Testbeiträgen, Entity-Erkennung als Ablauf über eine KI-Attrappe, Zusammenführen mit Vorschlägen, Profil unverändert speichern, REST-Schutz; mit `--online` zusätzlich der Ortsabgleich für Hemmingen. Testdaten heißen `LSECTEST …` und werden danach entfernt.

## Entwicklung

```bash
composer install
composer lint        # PHPCS
composer analyse     # PHPStan
composer test:unit   # PHPUnit
npm ci && npm run build   # Backend-Oberfläche nach assets/dist (nicht im Repo, kommt in die ZIP)
```

Die Oberfläche (`src-js/`) ist React über `@wordpress/scripts`. JSX wird je Datei klassisch übersetzt (`/** @jsxRuntime classic */`), weil die automatische Übersetzung das Skript `react-jsx-runtime` verlangt, das erst WordPress 6.6 mitbringt. Kein JSX-Spread (`{ ...props }`), sonst braucht der Build `@babel/runtime`.

GitHub Actions baut die Oberfläche und führt dieselben Prüfungen, beide Machbarkeitstest-Skripte und `mvp-pruefung.sh` mit PHP 8.1/WordPress 6.4 und PHP 8.3/8.4 mit aktuellem WordPress, Rank Math und Elementor aus.
