# Local SEO & Entity Content – Handbuch

Stand: Version 1.0 · Oktober 2026

Dieses Handbuch beschreibt Installation, Einrichtung und Betrieb des Plugins. Im Plugin steht es unter **Local SEO → Handbuch**; jede Seite des Plugins verlinkt es oben neben dem Titel, und in der Plugin-Liste steht der Link „Handbuch“. Technische Einzelheiten und Begründungen stehen in der Spezifikation im Repository (`docs/spezifikation/technische-spezifikation-v1.0.md`), Begriffe im `GLOSSAR.md`.

## Inhalt

1. [Was das Plugin macht](#1-was-das-plugin-macht)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Einrichtung mit dem Assistenten](#4-einrichtung-mit-dem-assistenten)
5. [Der laufende Betrieb](#5-der-laufende-betrieb)
6. [Die Seiten im Backend](#6-die-seiten-im-backend)
7. [Der Prüfbericht eines Entwurfs](#7-der-prüfbericht-eines-entwurfs)
8. [Einstellungen](#8-einstellungen)
9. [Befehle (WP-CLI)](#9-befehle-wp-cli)
10. [Kosten](#10-kosten)
11. [Fehlerbehebung](#11-fehlerbehebung)
12. [Sicherheit und Datenschutz](#12-sicherheit-und-datenschutz)

---

## 1. Was das Plugin macht

Das Plugin plant und schreibt Beiträge für die Website eines lokalen Unternehmens, zum Beispiel eines Handwerksbetriebs, einer Praxis oder einer Kanzlei. Es arbeitet in dieser Reihenfolge:

1. **Website verstehen:** Es liest die eigenen Seiten, Beiträge, Produkte und Kategorien. Daraus erkennt es Leistungen, Orte, Marken und Personen (Entitäten). Im Unternehmensprofil stehen Name, Standorte, Leistungen und Servicegebiet.
2. **Nachfrage kennen:** Es holt Suchanfragen aus der Google Search Console, ergänzt Suchvolumina von Textfocus und sortiert Keywords mit fremden Orten oder Firmennamen aus.
3. **Themen vorschlagen:** Eine Recherche mit Websuche schlägt Themen vor, mit Priorität, Begründung und geprüften Tatsachen (zum Beispiel Fakten zu einem Ort, jeweils mit Quelle). Themen, die eine bestehende Seite schon abdeckt, landen bei „bestehende Seite verbessern“.
4. **Du entscheidest:** Du gibst Themen frei oder lehnst sie ab.
5. **Entwurf schreiben:** Zu freigegebenen Themen schreibt das Plugin einen Entwurf: Briefing, Text im Schreibstil der Website, interne Links, Rank-Math-Angaben und Beitragsbild. Danach prüft es den Entwurf mit Regeln und einer KI-Bewertung und bessert einzelne Stellen bei Bedarf nach.
6. **Du veröffentlichst:** Jeder Beitrag bleibt ein Entwurf, bis du ihn veröffentlichst. Das Plugin veröffentlicht in Version 1.0 nie selbst.
7. **Bericht:** Der Bericht zeigt, welche bestehenden Seiten sich zu verbessern lohnen, was das Plugin geschrieben hat und wo Leistungen oder Orte noch ohne Inhalt sind.

**Grundsätze:**
- Zahlen, Daten, Preise, Zusagen und Ortsangaben stehen im Text nur, wenn sie belegt sind: durch eine geprüfte Tatsache oder das Unternehmensprofil.
- Allgemeines Fachwissen darf der Text erklären, aber nicht als Zusage des Unternehmens formulieren.
- Jeder KI-Aufruf zählt gegen ein Monatsbudget und ein Budget je Ablauf. Ist es erreicht, warten die Abläufe.
- Alles läuft im Hintergrund über einen Cronjob auf dem Server. Der Stand ist jederzeit auf der Seite „Abläufe“ zu sehen.

## 2. Voraussetzungen

| Was | Pflicht | Hinweis |
|---|---|---|
| PHP 8.1 oder neuer | ja | getestet bis PHP 8.4 |
| WordPress 6.4 oder neuer | ja | |
| WP-CLI auf dem Server | ja | für den Cronjob; bei Plesk als Datei `private/wp-cli.phar` |
| Cronjob alle 5 Minuten | ja | `wp lsec cron` |
| KI-Anbieter mit API-Schlüssel | ja | empfohlen: OpenRouter (alle Modelle, Kosten werden genau gemeldet, Bilder nur dort) |
| Rank Math | empfohlen | Ohne Rank Math speichert das Plugin Titel, Beschreibung und Fokus-Keyword als Vorschlag am Entwurf. |
| Google Search Console | empfohlen | über ein Dienstkonto; ohne sie fehlen Suchanfragen, Positionen und der Teil „bestehende Seiten verbessern“ |
| Textfocus | optional | Suchvolumina, CPC, verwandte Keywords |
| Elementor | nein | Elementor-Seiten werden gelesen; Entwürfe sind Gutenberg-Blöcke |

## 3. Installation

### 3.1 Plugin hochladen

1. In WordPress unter **Plugins → Installieren → Plugin hochladen** die ZIP-Datei `local-seo-entity-plugin-x.y.z.zip` hochladen.
2. Aktivieren. Die Tabellen werden dabei angelegt; bei Updates zieht das Plugin das Datenbankschema selbst nach.
3. Im Menü erscheint **Local SEO**.

### 3.2 Schlüssel in `wp-config.php`

Schlüssel gehören in die `wp-config.php`, nicht in die Datenbank. Am besten direkt nach `<?php` eintragen:

```php
define( 'LSEC_OPENROUTER_API_KEY', 'sk-or-…' );   // KI über OpenRouter (empfohlen)
define( 'LSEC_TEXTFOCUS_API_KEY', '…' );          // optional
// Nur bei anderem Anbieter:
// define( 'LSEC_ANTHROPIC_API_KEY', '…' );
// define( 'LSEC_OPENAI_API_KEY', '…' );
```

Das Plugin gibt Schlüssel nie aus, auch nicht in Fehlermeldungen oder Protokollen.

### 3.3 WP-CLI bereitstellen (Plesk)

Hat das Abonnement keinen Shell-Zugang, liegt WP-CLI als Datei neben dem Webroot. Als root auf dem Server (Pfad und Domain anpassen):

```bash
cd /var/www/vhosts/example.de
mkdir -p private
curl -sSo private/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chown "$(stat -c %U httpdocs)":psacln private/wp-cli.phar
```

Der Einrichtungsassistent zeigt diese Befehle mit den richtigen Pfaden der Website an.

### 3.4 Cronjob anlegen (Plesk)

In Plesk im Abonnement unter **Geplante Aufgaben → Aufgabe hinzufügen**:

| Feld | Wert |
|---|---|
| Aufgabentyp | **PHP-Skript ausführen** |
| Skriptpfad | `/var/www/vhosts/example.de/private/wp-cli.phar` (voller Pfad, **ohne Leerzeichen davor**) |
| Argumente | `--path=/var/www/vhosts/example.de/httpdocs lsec cron` |
| PHP-Version | die der Website, mindestens 8.1 |
| Ausführen | Cron-Stil `*/5 * * * *` |
| Benachrichtigen | Nur Fehler |

Die Aufgabe des WP Toolkits für `wp-cron.php` bleibt bestehen; sie bedient die übrigen Plugins. Sie allein reicht für dieses Plugin aber nicht.

Kontrolle: **Local SEO → Dashboard**, Karte „Cronjob“ zeigt „läuft“. Von Hand: `wp lsec cron` muss ohne Fehler enden („Success: … geplante Aufgaben abgeschlossen“).

Mit Shell-Zugang geht auch ein gewöhnlicher Cronjob: `*/5 * * * * wp --path=/pfad/zu/httpdocs lsec cron`.

### 3.5 Google Search Console (empfohlen)

1. In der Google Cloud Console ein **Dienstkonto** anlegen, die Search Console API aktivieren und einen **JSON-Schlüssel** herunterladen.
2. Die Datei als `private/gsc-service-account.json` neben den Webroot legen. Ein anderer Dateiname in `private` geht auch, wenn es die einzige JSON-Datei dort ist. Rechte setzen: `chmod 600`.
3. In der Search Console unter **Einstellungen → Nutzer und Berechtigungen** die E-Mail-Adresse des Dienstkontos als Nutzer eintragen. Der Assistent zeigt diese Adresse an.
4. Property einstellen: `wp lsec gsc sites` zeigt die freigegebenen Properties, dann im Assistenten oder unter **Einstellungen → Search Console** übernehmen.

Die Schlüsseldatei nie per E-Mail oder Chat weitergeben.

### 3.6 Textfocus (optional)

Schlüssel als Konstante (3.2), dann den Sprachcode festlegen:

```bash
wp lsec textfocus langs --search=german --save=de
```

## 4. Einrichtung mit dem Assistenten

**Local SEO → Einrichtung** führt in sechs Schritten durch die Einrichtung. Erledigte Schritte sind abgehakt. Fällt später etwas weg (zum Beispiel der Cronjob), ist der Schritt wieder offen. Dasselbe per Befehl: `wp lsec setup` zeigt den Stand, `wp lsec setup website|ki|stil|start` führt einen Schritt aus.

1. **Technik prüfen:** Schlüssel, Cronjob, Search Console, Rank Math, Textfocus. Was fehlt, steht mit dem passenden Befehl oder der Plesk-Anleitung da. Schlüssel, Cronjob und Freigabe in der Search Console bleiben Handarbeit.
2. **KI-Einstellungen:** übernimmt erprobte Werte. Hauptmodell `openai/gpt-6-luna`, Schreib- und Prüfmodell `anthropic/claude-sonnet-5.5`, Recherche `perplexity/sonar-pro`.
3. **Website lesen:** liest Startseite, Impressum, Kontakt- und Leistungsseiten und schlägt das Unternehmensprofil vor (Name, Standorte, Leistungen, Marken, Wettbewerber, Servicegebiet). Du übernimmst per Häkchen. Kosten: ein KI-Aufruf, wenige Cent.
4. **Schreibstil:** leitet Ton, Anrede und Regeln aus den eigenen Seiten ab (Style Mimic). Ein KI-Aufruf, wenige Cent; später auf der Seite „Style Mimic“ änderbar.
5. **Alles starten:** startet Inhaltsindex, Entity-Erkennung, Keyword-Abgleich und die erste Themenrecherche. Den Rest erledigt der Cronjob.
6. **Entscheidungen:** zeigt, was auf dich wartet: Entitäten bestätigen, Themen freigeben.

## 5. Der laufende Betrieb

### 5.1 Was der Cronjob von selbst erledigt

| Aufgabe | Wann |
|---|---|
| Hängengebliebene Abläufe wieder aufnehmen | bei jedem Lauf |
| Abläufe fortsetzen, die auf das Budget gewartet haben | sobald das Budget wieder reicht |
| Keyword-Abgleich: Search Console (28 Tage, wöchentlich 90 Tage), Orte und Namen erkennen, Ausschlüsse, fehlende Suchvolumina | etwa einmal am Tag |
| Inhaltsindex für neue und geänderte Inhalte, danach Entity-Erkennung | höchstens stündlich, nur bei Änderungen |
| Neue Bilder der Mediathek beschreiben | höchstens alle 15 Minuten, wenn neue Bilder da sind |
| Entwürfe zu freigegebenen Themen | bis zum Tageslimit (Einstellung „Entwürfe je Tag“) |

### 5.2 Deine Aufgaben

- **Themen prüfen** (Seite „Themen“): freigeben, ablehnen oder zurücksetzen.
  - Doppelte Themen sind markiert. „Vergleichen“ zeigt beide nebeneinander mit einer Empfehlung, „Empfehlung übernehmen“ lehnt das schwächere ab und übernimmt dessen Keywords.
  - Zu jedem Thema gibt es die geprüften Tatsachen mit Quelle; einzelne lassen sich bestätigen oder verwerfen.
- **Entwürfe prüfen und veröffentlichen** (WordPress → Beiträge, Status Entwurf):
  - Der Prüfbericht steht auf der Themen-Seite beim Thema (Abschnitt 7).
  - Blockierte Entwürfe nicht veröffentlichen, ohne die Mängel zu beheben.
- **Entitäten pflegen** (Seite „Entitäten“): Vorschläge bestätigen oder ablehnen, auch mehrere auf einmal. Schreibweisen derselben Firma zusammenführen.
- **Keywords pflegen** (Seite „Keywords“): fremde Firmen, fremde Orte oder Themen ohne Bezug ausschließen, auch mehrere auf einmal.
- **Bericht lesen** (Seite „Bericht“): Seiten, die sich zu verbessern lohnen, und Leistungen oder Orte ohne Inhalt als Anregung für die nächste Recherche.

### 5.3 Eine neue Themenrecherche

Auf der Seite **Themen** unter „Neue Recherche“: Leistung (oder alle), Ort und Anzahl wählen, dann „Recherche starten“. Kosten etwa 0,05 bis 0,20 USD. Derselbe Schwerpunkt (Leistung und Ort) wird frühestens nach 14 Tagen erneut recherchiert, außer mit „trotzdem“. Per Befehl: `wp lsec topics research --service=<leistung> [--region=<ort>] [--force]`.

### 5.4 Mehrere Entwürfe auf einmal

Auf der Seite **Themen** unter „freigegeben“ die gewünschten Themen anhaken und „Ausgewählte schreiben“ wählen, höchstens 20 auf einmal. Vor dem Start zeigt das Plugin eine Kostenschätzung. Die Abläufe arbeitet der Cronjob nacheinander ab.

### 5.5 Beitragsbilder

- Jeder neue Entwurf bekommt das passendste Bild aus der Mediathek; ungenutzte Bilder haben Vorrang.
- Passt keines, erzeugt die KI ein Bild (Einstellung „Beitragsbilder“). Es wird als WebP mit deutschem Dateinamen und Alternativtext gespeichert und in der Mediathek als KI-Bild vermerkt.
- Neue Bilder beschreibt das Plugin einmal auf Deutsch (Alternativtext und Stichworte), damit auch Stockfotos mit englischen Dateinamen gefunden werden. Einen vorhandenen Alternativtext überschreibt es nicht.
- Nicht als Beitragsbild infrage kommen Logos, Symbole, Grafiken mit viel Schrift, Bilder mit Wasserzeichen und Bilder unter 800 Pixel Breite.
- Für vorhandene Beiträge: `wp lsec images assign <beitrag>`.

Stockfotos (zum Beispiel Envato Elements) für die jeweilige Website lizenzieren. Bei Envato wird jeder Download einem Projekt zugeordnet.

### 5.6 Ortsseiten in einer eigenen Kategorie

Mit **Einstellungen → Beiträge → Kategorie für Ortsseiten** (zum Beispiel `regional-service`) bekommen lokale Seiten zusätzlich diese Kategorie. So lassen sie sich in Theme- und Elementor-Listen ausschließen, und das Kategorie-Archiv verlinkt alle Ortsseiten. Wenn die Ortsseiten in keiner Blogansicht mehr stehen, das Archiv verlinken (Menü oder Leistungsseite), damit Google sie findet. Vorhandene Ortsseiten nachträglich zuordnen: `wp lsec production local-category`.

## 6. Die Seiten im Backend

| Seite | Inhalt |
|---|---|
| **Dashboard** | KI-Kosten und Budget, Textfocus-Credits, Cronjob, Hinweise mit Befehlen, Inhalte und Entitäten auf einen Blick |
| **Handbuch** | dieses Handbuch |
| **Einrichtung** | Assistent (Abschnitt 4) |
| **Unternehmen** | Profil: Name, Beschreibung, Standorte, Leistungen mit Beschreibung, Marken, Wettbewerber, Servicegebiet (mit Wikidata-Abgleich), Adressen für „Angaben lesen“ |
| **Entitäten** | erkannte Leistungen, Orte, Marken, Personen und Begriffe mit Status, Sicherheit und Fundstellen; Bestätigen, Ablehnen, Bearbeiten, Zusammenführen, auch für mehrere |
| **Keywords** | Keywords aus Search Console, Textfocus und Importen mit Volumen, Wettbewerb, CPC, Impressionen, Klicks, Position und Seite; Ausschließen und Aufnehmen, auch für mehrere; täglicher Abgleich, verwandte Keywords, Import von CSV oder Excel |
| **Themen** | Recherche, Vorschläge mit Priorität und Begründung, Dubletten-Vergleich, Freigabe, Entwürfe schreiben, Prüfbericht je Entwurf |
| **Style Mimic** | Schreibstil: Ton, Anrede, Regeln, Beispiele; Versionen |
| **Abläufe** | alle Hintergrund-Abläufe mit Status, Kosten und Fehlern; Schritte und KI-Aufrufe aufklappbar; Fortsetzen und Abbrechen |
| **Bericht** | bestehende Seiten verbessern (je Seite, mit Marken- und Navigationssuchen getrennt, CSV), Inhalte des Plugins mit Prüfung, Kosten und Beitragsbild, Entitäten mit Lücken, Bilder |
| **Einstellungen** | Abschnitt 8 |
| **Technik** | Systemstatus, Schemaversion, Warteschlange, Protokoll |

## 7. Der Prüfbericht eines Entwurfs

Jeder Entwurf wird mit festen Regeln und einer KI-Bewertung geprüft. **Blockierende Mängel** verhindern die Freigabe zum Veröffentlichen: Der Entwurf bleibt erhalten, ist aber als „blockiert“ markiert. **Hinweise** sind Verbesserungsvorschläge.

Scheitert ein Entwurf nur an einzelnen Stellen (unbelegte Zahl, unbelegte Aussage, fremder Ort, Platzhalter, Satz über Quellen), ersetzt das Schreibmodell genau diese Stellen, und der Entwurf wird einmal neu geprüft (Einstellung „Beanstandete Stellen automatisch nachbessern“).

**Regeln, die blockieren:**

| Code | Bedeutung |
|---|---|
| `UNSUPPORTED_FACT` | Eine verwendete Tatsache ist nicht geprüft. |
| `UNSUPPORTED_STATEMENT` | Der Entwurf meldet selbst eine unbelegte Aussage. |
| `UNSUPPORTED_NUMBER` | Eine Zahl stammt weder aus einer Tatsache noch aus dem Profil. |
| `PRIMARY_ENTITY` | Die Haupt-Entität des Themas ist nicht bestätigt. |
| `OVERLAP` | Eine bestehende Seite deckt das Thema schon ab. |
| `SIMILAR_TITLE` | Es gibt einen Beitrag mit sehr ähnlichem Titel. |
| `LOCAL_DUPLICATE` | Der Text gleicht zu sehr einer Ortsseite für einen anderen Ort. |
| `LOCAL_FACTS` | Eine Ortsseite nutzt zu wenige geprüfte Tatsachen zum Ort (nur wenn die Recherche genug gefunden hat). |
| `FOREIGN_PLACE` | Ein Ort außerhalb des Servicegebiets wird genannt. |
| `PLACEHOLDER` | Platzhalter wie „[Ort]“ stehen im Text. |
| `PROMPT_LEAK` | Ein Satz spricht über Quellen oder Vorgaben („laut Profil“, „allgemeines Fachwissen“). |
| `BLOCKS` | Das Block-Markup ist ungültig. |

**KI-Bewertung:** Suchintention, lokale Relevanz, Einbindung der Entitäten, Mehrwert, Lesbarkeit und Stil, je 0 bis 1. Blockiert, wenn ein Wert unter dem Mindestwert liegt (Standard 0,6) oder der Durchschnitt unter dem Mindestdurchschnitt (Standard 0,75). Dazu meldet die KI Mängel als `AI_…`, zum Beispiel `AI_UNSUPPORTED_FACT`, `AI_WRONG_PLACE` oder `AI_MISLEADING`.

**Hinweise (blockieren nicht):**

| Code | Bedeutung |
|---|---|
| `LENGTH` | Länge außerhalb der Spanne des Beitragstyps |
| `ANSWER_FIRST` | Der Einstieg fehlt oder beginnt mit einer Ankündigung („In diesem Beitrag erfahren Sie …“). |
| `SEO_META` | SEO-Titel (30–60 Zeichen) oder Meta-Beschreibung (120–160) außerhalb der Länge |
| `KEYWORD_TITLE` | Das Haupt-Keyword fehlt im SEO-Titel oder steht erst spät. |
| `SLUG` | Die Titelform ist länger als fünf Wörter oder enthält eine Jahreszahl. |
| `KEYWORD_INTRO` | Das Haupt-Keyword fehlt in den ersten 100 Wörtern. |
| `FOCUS_TAKEN` | Das Fokus-Keyword hat schon ein anderer Beitrag. |
| `REPETITION` | Der Text wiederholt Sätze oder Floskeln. |

Einen Entwurf ohne Neuschreiben noch einmal prüfen: `wp lsec production review <entwurf>`. Den ganzen Bericht als Text: `wp lsec production report <entwurf>`.

## 8. Einstellungen

**Local SEO → Einstellungen**, oder per Befehl: `wp lsec settings list` und `wp lsec settings set <schlüssel> <wert>`.

### KI

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Anbieter | `ai_provider` | openrouter | openrouter, anthropic oder openai |
| Hauptmodell | `ai_model` | – | für alle Aufgaben ohne eigenes Modell; empfohlen `openai/gpt-6-luna` |
| Schreibmodell | `ai_writing_model` | Hauptmodell | nur für den Text; empfohlen `anthropic/claude-sonnet-5.5` |
| Prüfmodell | `ai_review_model` | Hauptmodell | nur für die KI-Bewertung; empfohlen `anthropic/claude-sonnet-5.5` |
| Recherchemodell | `ai_research_model` | Hauptmodell | nur für die Websuche; empfohlen `perplexity/sonar-pro` |
| Treffer der Websuche | `ai_web_max_results` | 3 | 1 bis 10; weniger Treffer, geringere Kosten |
| Suchmaschine | `ai_web_engine` | standard | standard, native, exa, parallel, perplexity; ohne Quellen: exa oder parallel |
| Monatsbudget | `ai_monthly_budget` | 20 USD | erreicht: Abläufe warten; 0 = ohne Grenze |
| Budget je Ablauf | `ai_run_budget` | 1 USD | Höchstbetrag zum Beispiel für einen Beitrag |
| Preise | `ai_prices` | – | nur für Anthropic und OpenAI direkt; OpenRouter meldet Kosten selbst |
| OpenAI-Endpunkt | `openai_endpoint` | – | zum Beispiel EU-Region |

### Textfocus

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Sprachcode | `textfocus_lang` | – | per `wp lsec textfocus langs --search=german --save=de` |
| Tagesbudget | `textfocus_daily_budget` | 25 Credits | |
| Mindestabstand | `textfocus_min_interval` | 60 Sekunden | zwischen zwei Abfragen |
| Keywords je Aufruf | `textfocus_batch_size` | 10 | |

### Keywords

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Orte und Namen erkennen | `keyword_analysis` | an | Grundlage für die Ausschlüsse; ein KI-Aufruf je 100 neue Keywords |
| Suchvolumen nachholen | `keyword_auto_volumes` | an | 10 Keywords je Credit, 5 Credits bleiben für Abfragen von Hand |

### Themen

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Themen je Recherche | `topic_count` | 5 | 1 bis 10 |
| Gewichte der Priorität | `topic_weights` | Relevanz 30, Nachfrage 25, Lücke 20, lokal 15, Saison 10 | als JSON in Prozent |

### Beiträge

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Freigegebene Themen automatisch schreiben | `production_auto` | an | der Cronjob schreibt bis zum Tageslimit |
| Beanstandete Stellen nachbessern | `production_revise` | an | etwa 0,05 bis 0,10 USD je Nachbesserung |
| Fehlende Ortsfakten recherchieren | `place_research` | an | einmal je Ort in 30 Tagen |
| Kategorie für Ortsseiten | `local_category` | – | Name oder Titelform; leer = aus |
| Ortsseiten auf der Blogseite ausblenden | `local_category_hide` | aus | |
| Entwürfe je Tag | `drafts_per_day` | 3 | 0 bis 20; von Hand gestartete zählen mit |
| Autor der Entwürfe | `draft_author` | 0 | 0 = wer das Thema freigegeben hat |
| Länge je Beitragstyp | `draft_lengths` | Ortsseite 700–1300, Artikel 900–1600, Ratgeber 1200–2200, saisonal 700–1300 Wörter | als JSON |
| Mindestwert je Merkmal | `quality_min_score` | 0,6 | |
| Mindestdurchschnitt | `quality_min_average` | 0,75 | |

### Bilder

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Beitragsbilder | `image_mode` | library_ai | `library_ai` Mediathek, sonst KI-Bild · `library` nur Mediathek · `off` aus |
| Modell für die Bildbeschreibung | `ai_vision_model` | Hauptmodell | muss Bilder lesen können |
| Bildmodell | `ai_image_model` | black-forest-labs/flux-3-image | über OpenRouter; günstiger: `black-forest-labs/flux-3-image-klein` |
| Auflösung | `image_resolution` | 1K | für Modelle mit Auflösungsstufen |
| Seitenverhältnis | `image_aspect` | 16:9 | 16:9, 3:2, 4:3, 1:1 |

### Search Console

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Property | `gsc_property` | – | zum Beispiel `https://example.de/` oder `sc-domain:example.de` |
| Land | `gsc_country` | deu | `all` für alle Länder |
| Markenbegriffe | `brand_terms` | automatisch | Wörter für Suchen nach dem eigenen Namen, durch Komma getrennt |
| Schlüsseldatei | `gsc_credentials_file` | `private/` neben dem Webroot | die Konstante `LSEC_GSC_CREDENTIALS_FILE` hat Vorrang |

### Entitäten und Allgemein

| Einstellung | Schlüssel | Standard | Erklärung |
|---|---|---|---|
| Entitäten automatisch erkennen | `entity_auto_extract` | an | etwa 0,04 USD je Inhalt |
| Entitäten erkennen für | `entity_extract_types` | Seiten, Produkte, Kategorien | Blogbeiträge einzeln: `wp lsec entities extract <id> --queue` |
| Mindest-Sicherheit für die Anzeige | `entity_min_confidence` | 0,6 | schwächere Vorschläge werden ausgeblendet |
| Gleichzeitige Abläufe | `max_parallel_runs` | 3 | |
| Daten bei Deinstallation löschen | `delete_data_on_uninstall` | aus | Beiträge bleiben immer erhalten |

## 9. Befehle (WP-CLI)

Alle Befehle beginnen mit `wp lsec`. Bei Plesk ohne Shell des Abonnements als root zum Beispiel so:

```bash
cd /var/www/vhosts/example.de
alias lsec='sudo -u "$(stat -c %U httpdocs)" /opt/plesk/php/8.4/bin/php private/wp-cli.phar --path=$PWD/httpdocs lsec'
lsec status
```

PHP-Pfad an die Version der Website anpassen (`/opt/plesk/php/<version>/bin/php`).

Hilfe zu jedem Befehl: `wp help lsec <befehl>`. Die wichtigsten:

| Befehl | Zweck |
|---|---|
| `lsec cron` | Einstieg für den Cronjob |
| `lsec status` | Systemstatus |
| `lsec setup [website\|ki\|stil\|start]` | Stand der Einrichtung, Schritt ausführen |
| `lsec ai budget` | Kosten im Monat und Grenzen |
| `lsec runs list` · `runs show <id>` · `runs resume <id>` · `runs cancel <id>` | Abläufe |
| `lsec index run` · `index status` | Inhaltsindex |
| `lsec profile show` · `profile suggest` | Unternehmensprofil |
| `lsec entities list` · `entities decide 12,13 rejected` · `entities merge` | Entitäten |
| `lsec keywords sync` · `keywords list` · `keywords import <datei>` · `keywords related <keyword>` | Keywords |
| `lsec gsc sites` · `gsc fetch` | Search Console |
| `lsec textfocus account` · `textfocus langs` | Textfocus |
| `lsec topics research --service=<leistung>` · `topics list` · `topics show <id>` · `topics decide <id> approved` · `topics duplicates` | Themen |
| `lsec production start <thema>` · `production batch <ids> --yes` · `production list` · `production report <entwurf>` · `production review <entwurf>` · `production local-category` | Entwürfe |
| `lsec images status` · `images describe` · `images list --free` · `images assign <beitrag>` · `images models` | Bilder |
| `lsec style show` · `style build` | Schreibstil |
| `lsec report [--section=improve] [--format=csv]` | Bericht |
| `lsec settings list` · `settings set <schlüssel> <wert>` | Einstellungen |
| `lsec check rest` · `check secrets` | Prüfungen ohne Kosten |

Prüfskripte für Administratoren (ohne KI-Kosten, räumen hinterher auf): `bin/machbarkeitstest.sh`, `bin/machbarkeitstest-etappe3.sh` und `bin/mvp-pruefung.sh`, jeweils mit `--path=<WordPress>`. Nur auf einer Staging-Website ausführen.

## 10. Kosten

Richtwerte mit den empfohlenen Modellen über OpenRouter (Oktober 2026):

| Vorgang | Kosten |
|---|---|
| Website lesen, Schreibstil ableiten | je wenige Cent, einmalig |
| Entity-Erkennung | etwa 0,04 USD je Inhalt |
| Keyword-Analyse (Orte und Namen) | wenige Cent je 100 neue Keywords |
| Themenrecherche mit Websuche | etwa 0,05 bis 0,20 USD |
| Ortsfakten recherchieren | etwa 0,02 bis 0,05 USD, einmal je Ort in 30 Tagen |
| Entwurf (Briefing, Text, Prüfung) | etwa 0,10 bis 0,30 USD; im Test im Schnitt 0,20 USD |
| Nachbesserung | etwa 0,05 bis 0,10 USD |
| Bildbeschreibung | etwa 0,0002 USD je Bild |
| KI-Bild | etwa 0,05 USD (FLUX 3, 1K) |
| Textfocus | Credits laut Tarif; 10 Suchvolumina je Credit, verwandte Keywords 1 Credit |

Die tatsächlichen Kosten stehen im Dashboard, je Ablauf auf der Seite „Abläufe“ und je Entwurf im Bericht. `wp lsec ai budget` zeigt den Monat. Das Plugin-Budget ist eine zusätzliche Bremse; ein Limit beim Anbieter (OpenRouter) bleibt sinnvoll.

## 11. Fehlerbehebung

**Cronjob läuft nicht.**
- „Could not open input file“: Der Skriptpfad in Plesk stimmt nicht oder hat Leerzeichen am Anfang. Die Datei `private/wp-cli.phar` muss existieren.
- Nur die Aufgabe für `wp-cron.php` angelegt? Die reicht nicht; zusätzlich die Aufgabe aus 3.4 anlegen.
- Von Hand testen: `wp lsec cron`.

**Abläufe stehen auf „wartet“.**
- Grund `ai_budget`: Monats- oder Ablaufbudget erreicht. Budget erhöhen (`wp lsec settings set ai_monthly_budget 30`) oder den nächsten Monat abwarten. Danach setzt der Cronjob von selbst fort, oder `wp lsec runs resume <id>`.
- Textfocus-Tagesbudget erreicht: Es geht am nächsten Tag weiter.

**Entwurf ist blockiert.**
- Den Prüfbericht ansehen (Themen-Seite oder `wp lsec production report <entwurf>`).
- Unbelegte Angaben im Text streichen oder belegen: Tatsache bestätigen, Leistungsbeschreibung im Profil ergänzen.
- Danach `wp lsec production review <entwurf>`, oder neu schreiben lassen („neu schreiben“ auf der Themen-Seite).

**Recherche liefert keine Quellen oder Tatsachen.**
- Suchmaschine auf `exa` oder `parallel` stellen.
- Bei sehr kleinen Orten gibt es oft wenig; dann blockiert `LOCAL_FACTS` nicht.

**Search Console liefert keine Daten.**
- `wp lsec gsc sites` aufrufen.
- Fehlt die Property, ist das Dienstkonto in der Search Console nicht als Nutzer eingetragen.
- Warnung zu Dateirechten: `chmod 600` auf die Schlüsseldatei.
- Es kommen nur Daten zu Suchanfragen mit genug Impressionen; seltene gibt Google nicht heraus.

**Keine Beitragsbilder.**
- `wp lsec images status` zeigt, ob Bilder beschrieben und geeignet sind.
- KI-Bilder brauchen `LSEC_OPENROUTER_API_KEY` und die Einstellung „Beitragsbilder“ auf „Mediathek, sonst KI-Bild“.
- Der Grund steht im Prüfbericht des Entwurfs unter „Beitragsbild“.

**Auswahlfelder oder Knöpfe reagieren nicht.**
- Seite neu laden. Hilft das nicht, in der Browser-Konsole (F12 → Konsole) nach Fehlermeldungen sehen.
- Fehler beim Laden der Daten stehen direkt über der Tabelle.

**Elementor-Seiten im Bericht.** Das Plugin ändert keine bestehenden Seiten. Hinweise zu Elementor-Seiten setzt man im Elementor-Editor um.

**Thema lässt sich nicht schreiben („ähnelt Thema …“).** Zu einem sehr ähnlichen Thema gibt es schon einen Entwurf. Mit `--allow-duplicate` trotzdem schreiben, oder das Thema ablehnen.

## 12. Sicherheit und Datenschutz

- **Schlüssel** stehen als Konstanten in `wp-config.php`. Das Plugin schwärzt sie in Protokollen, Fehlermeldungen und Exporten; `wp lsec check secrets` sucht sie im Klartext in Datenbank und Protokoll.
- **Search-Console-Schlüssel** außerhalb des Webroots in `private/`, mit `chmod 600`.
- **Text-Anfragen** über OpenRouter gehen nur an Anbieter, die keine Daten zum Training speichern (`data_collection: deny`). An die KI gehen Inhalte der eigenen Website, das Unternehmensprofil, Bilder der Mediathek (zur Beschreibung) und öffentliche Rechercheergebnisse, keine Daten von Besuchern.
- **Rechte:** Alle Seiten und Schnittstellen des Plugins sind nur für Benutzer mit der Berechtigung des Plugins zugänglich (Administratoren); `wp lsec check rest` prüft das.
- **Veröffentlichung:** In Version 1.0 erstellt das Plugin nur Entwürfe.
- **Deinstallation:** Mit „Daten bei Deinstallation löschen“ entfernt das Plugin seine Tabellen und Einstellungen; Beiträge und Bilder bleiben.
