# WordPress Local SEO & Entity Content Plugin
## Technische Spezifikation v1.0

**Status:** Entwurf vom 18.10.2026. Wird verbindliche Grundlage für MVP 1, sobald die mit **Entscheidung offen** markierten Punkte (Abschnitt 13) entschieden sind.  
**Entwicklung:** Machbarkeitstest abgeschlossen, M1–M18 bestanden (Version 0.2.4, Ergebnisse in `PROJEKTPLAN.md`)  
**Grundlage:** Anforderungsspezifikation 1.0, Technische Spezifikation v0.1 bis v0.3, Ergebnisse des Machbarkeitstests  
**Plugin / Präfix:** `local-seo-entity-plugin` / `lsec`

---

## 0. Änderungen gegenüber v0.3

| Bereich | Änderung | Abschnitt |
|---|---|---|
| Rahmen | PHP 8.1 und WordPress 6.4 festgelegt (geprüft mit PHP 8.1, 8.3, 8.4 und WordPress 6.4.5, 7.1.3); Rank-Math-Funktionen nur ab der WordPress-Version, die Rank Math verlangt | 1 |
| MVP-Zuschnitt | Ortsabgleich über Wikidata und Import der Textfocus-Entitäten gehören zu MVP 1; vollständige externe Verifizierung bleibt in Ausbau 2 | 2 |
| Verzeichnisstruktur | an den tatsächlichen Stand angepasst, neue Module für Themen, Qualität und Style Mimic | 3 |
| Datenbank | Schemaversion 2 ist Stand; Schemaversion 3 für MVP 1: Wettbewerbswert, Ausschlussgrund bei Keywords, Zusammenführen von Entitäten | 4 |
| Keyword-Quellen | Nachträge aus dem Test in den Haupttext übernommen; Filter für Wettbewerber und fremde Orte; Search Console als Signal für Sichtbarkeit ohne Klicks | 6 |
| KI | Kostensteuerung (Budget, Einstellungen der Websuche), Faktenprüfung gegen den Quelltext, Mindest-Sicherheit und Zusammenführen bei der Entity-Erkennung | 8 |
| Qualitätsprüfung | `UNSUPPORTED_FACT` stützt sich auf die Faktenprüfung | 10 |
| Sicherheit | Dienstkonto-Schlüssel, Prüfprotokoll, Abruf fremder Seiten | 11 |
| Machbarkeitstest | Ergebnisse zusammengefasst | 12 |
| Umsetzung | Plan für MVP 1 in Stufen mit Abnahmekriterien | 14 |

Werte mit **Vorschlag** sind Ausgangswerte und werden während MVP 1 oder in der Testphase bestätigt. Punkte mit **Entscheidung offen** entscheidet Thomas vor Beginn der betroffenen Stufe.

---

## 1. Technische Rahmenbedingungen

| Punkt | Festlegung |
|---|---|
| PHP | mindestens 8.1 (geprüft: 8.1, 8.3, 8.4) |
| WordPress | mindestens 6.4 (geprüft: 6.4.5 und 7.1.3) |
| Rank Math | optional; erkannt über die Aktion `rank_math/loaded`. Rank Math 1.0.280 verlangt WordPress 6.7 und bleibt darunter inaktiv. Ohne aktives Rank Math werden SEO-Angaben nur als Vorschlag gespeichert (9.2) |
| Elementor | optional, nur Lesezugriff (5) |
| Abhängigkeiten | Composer mit PSR-4-Autoloading (`Lsec\` → `src/`); im ZIP nur `woocommerce/action-scheduler` und der Composer-Autoloader |
| Konfliktschutz | Weitere gebündelte Fremdbibliotheken erhalten ein eigenes Namespace-Präfix (z. B. mit Strauss). Ausnahme: Action Scheduler, der für das parallele Mitliefern ausgelegt ist (geprüft neben der Kopie von Rank Math) |
| Code-Standard | WordPress Coding Standards (PHPCS), PHPStan |
| Tests | PHPUnit ohne WordPress für die fachliche Kernschicht, PHPUnit mit WordPress-Testumgebung, Prüfskripte `bin/machbarkeitstest.sh` und `bin/machbarkeitstest-etappe3.sh` gegen eine echte Installation |
| CI | GitHub Actions: PHPCS, PHPStan, Unit-Tests, Prüfskripte mit PHP 8.1 + WordPress 6.4 sowie PHP 8.3/8.4 + aktuelles WordPress mit Rank Math und Elementor |
| Testumgebung | Staging-Installation auf dem Plesk-VPS (PHP 8.4, WordPress 7.1, Rank Math, Elementor, Cronjob). WP-CLI läuft als Benutzer des Abonnements, nie als root |
| Test-Installationen mit fester Version | `AUTOMATIC_UPDATER_DISABLED` setzen; sonst aktualisiert sich WordPress während eines Testlaufs |

---

## 2. MVP-Zuschnitt

### MVP 1: Entity-basierte Content-Produktion

- Grundeinrichtung: Erkennung von Rank Math, Elementor und WooCommerce; Prüfung von Cronjob und Schlüsseln
- Unternehmens-, Leistungs- und Standortprofil als bestätigte Entitäten (4.2), Servicegebiet als Verwaltungseinheiten und/oder Umkreis
- Inhaltsindex bestehender Beiträge und Seiten, auch mit Elementor gebaute
- KI-gestützte Entity-Erkennung mit wörtlichen Fundstellen
- Entity-Prüfstatus (Kandidat, wahrscheinlich, bestätigt, ungeklärt, abgelehnt), Bestätigen, Ablehnen und **Zusammenführen** im Backend
- **Ortsabgleich über Wikidata** gegen das Servicegebiet (vorgezogen aus Ausbau 2)
- **Import der Entitäten-Tabelle von Textfocus** als Kandidaten
- Tabellarische Entity-Übersicht mit Beziehungen; einfache Graphdarstellung
- Keyword-Quellen: Google Search Console, CSV-/Excel-Import, Textfocus-API, KI-Websuche
- Themenvorschläge mit Priorität, Begründung und Überschneidungsprüfung
- Recherche mit Websuche und **Prüfung jeder Tatsache gegen den Text der Quelle**
- Grundlegender Wettbewerbsvergleich über KI-Websuche und Textfocus
- Globaler Style Mimic
- Beitragserstellung mit Entity-Integration, internen Links, Kategorien, Schlagwörtern und Rank-Math-Angaben
- Qualitätsprüfung mit blockierenden Mängeln
- Bereitstellung als WordPress-Entwurf
- Content- und Entity-Bericht, Übersicht über KI-Kosten und Textfocus-Credits

### Ausbau 2

- Externe Entity-Verifizierung über Orte hinaus (Wikidata für Unternehmen und Fachbegriffe, Register, offizielle Websites) mit Belegbewertung
- Dynamische Content-Cluster und kontinuierliche Themenproduktion
- Überarbeitung bestehender Beiträge (Vorschläge aus der Search Console, 6.4)
- Bildanalyse und KI-Bildgenerierung
- Testphase und regelgesteuerte automatische Veröffentlichung
- Google Trends (bei Zugang) und DataForSEO als zusätzliche Keyword-Quellen
- Strukturierte Daten über Rank Math

### Ausbau 3

- Google Analytics 4 und Google Unternehmensprofil (OAuth)
- Lokale Ranking-Messung mit räumlicher Darstellung
- Bewertungen, Branchenverzeichnisse, Backlinks und Erwähnungen
- Screpy für Ranking-Verlauf, Backlinks und Site-Audit (6.3)
- Sichtbarkeit in KI-Antworten (Screpy, optional Indexly) – **Entscheidung offen**, betrifft MVP 1 nicht
- Erweiterte WooCommerce-Unterstützung

---

## 3. Modul- und Verzeichnisstruktur

Stand nach dem Machbarkeitstest; mit **neu** markierte Module entstehen in MVP 1.

```text
local-seo-entity-plugin/
├── local-seo-entity-plugin.php        Bootstrap, Konstanten, Prüfung von PHP und Abhängigkeiten
├── uninstall.php                      Datenentfernung nur bei aktivierter Option
├── composer.json
├── bin/                               build-zip.sh, Prüfskripte
├── src/
│   ├── Core/                          Plugin, Einstellungen, Aktivierung, Deaktivierung
│   ├── Admin/                         Backend-Seiten (Status, Entitäten; später React-Oberfläche)
│   ├── Rest/                          REST-Namensraum lsec/v1
│   ├── Cli/                           WP-CLI-Befehle (wp lsec …)
│   ├── Entities/                      Entitäten, Fundstellen, Ortsabgleich, Servicegebiet, Textfocus-Import
│   ├── Research/
│   │   ├── Import/                    CSV/Excel, Importprofile, Zahlen
│   │   ├── Keywords/                  Keywords, Normalisierung, Tippfehlerprüfung
│   │   └── SourceRepository.php       Quellen mit kanonischer URL
│   ├── Facts/                         neu: Aussagen, Belege, Faktenprüfung gegen Quelltext (8.4a)
│   ├── Topics/                        neu: Themenvorschläge, Priorisierung, Überschneidung, Filter (6.4)
│   ├── Content/                       Inhaltsindex, Elementor-Text, Blockausgabe, Entwurf; neu: Briefing, Generierung
│   ├── Style/                         neu: Style Mimic
│   ├── Quality/                       neu: Regelprüfungen, KI-Bewertung, Berichte
│   ├── Workflow/                      Abläufe, Schritte, Status, Wiederaufnahme, Watchdog
│   ├── Integrations/
│   │   ├── AI/                        AiClient, Adapter OpenRouter, Anthropic, OpenAI, Preise, Protokoll; neu: Budget
│   │   ├── Textfocus/
│   │   ├── Wikidata/
│   │   └── SearchConsole/
│   └── Infrastructure/
│       ├── Database/                  Schema, Migration
│       ├── Http/                      HTTP-Transport mit Fehlerdeutung
│       ├── Logging/                   Protokoll mit Schwärzung, Stufe audit
│       ├── Queue/                     Action-Scheduler-Adapter
│       └── Security/                  Capability, Geheimnisse
├── assets/                            neu: Backend-Oberfläche (React über @wordpress/scripts)
├── languages/
└── tests/
    ├── Unit/
    ├── Integration/
    ├── Support/                       FakeTransport, Speicher-Doubles
    └── fixtures/                      echte, gekürzte Exporte und API-Antworten
```

Die fachlichen Module (`Entities`, `Facts`, `Topics`, `Content`, `Style`, `Quality`) greifen über Schnittstellen auf Datenbank, HTTP und KI zu (`HttpTransport`, `AiClient`, `ProviderCallLog`). So bleiben sie ohne WordPress testbar. Jede neue Fähigkeit bekommt einen WP-CLI-Befehl zum Prüfen, bevor die Backend-Oberfläche folgt.

---

## 4. Datenbankschema

Alle Tabellen tragen das Präfix `{$wpdb->prefix}lsec_`. Sie werden über `dbDelta()` angelegt und über `lsec_db_version` migriert. Zeitangaben in UTC. Statuswerte als `VARCHAR`, im Code gegen Konstanten geprüft. JSON-Felder als `LONGTEXT`.

**Stand:** Schemaversion 2 mit 20 Tabellen (seit 0.2.0). **MVP 1:** Schemaversion 3 (4.7).

### 4.1 Entity-Modell

**`lsec_entities`**

| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT UNSIGNED PK | interne ID |
| uuid | CHAR(36) UNIQUE | stabile ID für Export und Import |
| type | VARCHAR(50) | z. B. `organization`, `brand`, `place`, `service_area`, `service`, `product`, `person`, `competitor`, `concept` |
| canonical_name | VARCHAR(255) | |
| normalized_name | VARCHAR(255) INDEX | klein, ohne Sonderzeichen, für Dublettenprüfung |
| description | TEXT | |
| properties | LONGTEXT (JSON) | typabhängig; Hinweise externer Quellen, z. B. `properties.textfocus` |
| external_ids | LONGTEXT (JSON) | z. B. `{"wikidata":"Q…"}`; nur nach Abgleich (8.3) |
| status | VARCHAR(20) INDEX | `candidate`, `probable`, `confirmed`, `unresolved`, `rejected`; ab v3 zusätzlich `merged` |
| confidence | DECIMAL(4,3) | 0–1 |
| origin | VARCHAR(30) | `business_profile`, `ai_extraction`, `manual`, `import` |
| merged_into | BIGINT UNSIGNED NULL INDEX | ab v3: Ziel beim Zusammenführen |
| decided_by | BIGINT UNSIGNED NULL | WordPress-Benutzer |
| decided_at, last_verified_at, created_at, updated_at | DATETIME | |

**`lsec_entity_aliases`**: id, entity_id (INDEX), alias, normalized_alias (INDEX), created_at

**`lsec_relations`**: id, source_entity_id, target_entity_id (INDEX, gerichtet), relation_type (`offers`, `owns_brand`, `located_in`, `serves_area`, `belongs_to_topic`, `member_of`, `competes_with`), status, confidence, origin, created_at, updated_at; UNIQUE (source_entity_id, relation_type, target_entity_id)

**`lsec_sources`**: id, url, url_hash (CHAR(64) UNIQUE), title, source_type (`own_post`, `website`, `register`, `wikidata`, `gsc`, `csv_import`, `ai_search`, `manual`), fetched_at, content_hash, reliability (`high`, `medium`, `low`, `unknown`). Die URL wird vor dem Speichern vereinheitlicht: Tracking-Parameter (`utm_*`, `gclid`, `fbclid`, `msclkid`, `mc_cid`, `mc_eid`, `srsltid`) und Fragment entfallen. OpenAI hängt `utm_source=openai` an; ohne diese Regel stünde dieselbe Seite doppelt in der Tabelle.

**`lsec_claims`** – Aussagen über Entitäten oder Beziehungen

| Spalte | Hinweis |
|---|---|
| id | PK |
| subject_type, subject_id | `entity` oder `relation` |
| property | z. B. `opening_hours`, `founding_year`, `first_mentioned`, `address` |
| value | LONGTEXT (JSON) |
| valid_from, valid_to | optional |
| status | `candidate`, `verified`, `confirmed`, `disputed`, `rejected` |
| created_at, updated_at | |

`verified` (neu, keine Schemaänderung) bedeutet: Die Faktenprüfung hat die Aussage im Text der Quelle gefunden (8.4a). `confirmed` setzt nur der Administrator. Widersprüchliche Aussagen stehen als mehrere Zeilen mit `disputed` nebeneinander und werden nicht überschrieben.

**`lsec_evidence`**: id, source_id, target_type, target_id (`entity`, `relation`, `claim`), excerpt (wörtliches Zitat), locator, quality (`strong`, `medium`, `weak`), captured_at

**`lsec_mentions`**: id, entity_id (INDEX), object_type, object_id (`post`, `term`, `attachment`, `external`), location (`title`, `heading`, `body`, `meta`, `schema`, `alt_text`), excerpt, created_at. Bei erneuter Erkennung desselben Beitrags werden seine Fundstellen ersetzt.

### 4.2 Unternehmensprofil

Unternehmen, Standorte, Leistungen und Servicegebiete sind Entitäten mit `origin = business_profile` und `status = confirmed`. Die Eingabemaske schreibt direkt in das Entity-Modell. Öffnungszeiten und Adressen sind Aussagen mit der Quelle `manual`. Das Servicegebiet steht zusätzlich als Einstellung `service_area` (Wikidata-IDs der Verwaltungseinheiten, optional Mittelpunkt und Radius), weil der Ortsabgleich es bei jeder Abfrage braucht.

### 4.3 Keywords und Themen

**`lsec_keywords`**: id, keyword, normalized (INDEX), locale (`de_DE`, `de_AT`, `de_CH`), region (NULL oder Ort/PLZ), intent (`informational`, `commercial`, `transactional`, `navigational`, `local`), ab v3 `exclusion` (NULL, `competitor`, `brand`, `foreign_place`, `off_topic`, `manual`), first_seen_at, updated_at; UNIQUE (normalized, locale, region)

**`lsec_keyword_metrics`** – Kennzahlen je Quelle und Zeitraum

| Spalte | Hinweis |
|---|---|
| id | PK |
| keyword_id | INDEX |
| source | `gsc`, `textfocus`, `csv:keyword_revealer`, `csv:keyword_chef`, `xlsx:textfocus`, `csv:other`; später `trends`, `dataforseo` |
| period_start, period_end | |
| search_volume | NULL = unbekannt (nie 0 als Ersatz) |
| difficulty, cpc | NULL = unbekannt |
| competition | ab v3: Wettbewerb 0–100 (Textfocus `concurrency` bzw. Spalte „Competition“), NULL = unbekannt |
| impressions, clicks, ctr, position | Search Console |
| page_url | rankende Seite (Search Console: Seite mit den meisten Klicks, bei Gleichstand die mit den meisten Impressionen) |
| trend | JSON; `{"labeled": false, "values": [...]}`, wenn die Quelle keine Monate nennt |
| import_batch_id | Verweis auf den Import |
| created_at | |

**`lsec_imports`**: id, source, filename, rows_total, rows_imported, rows_skipped, column_mapping (JSON), created_by, created_at. Auch Abrufe aus der Search Console und von Textfocus erhalten einen Import-Eintrag.

**`lsec_topics`**: id, title, intent, format (`local_landing`, `article`, `guide`, `seasonal`, `other`), region, priority_score (0–100), score_breakdown (JSON, 6.4), rationale, research_basis (JSON: Quellen-, Keyword- und Aussagen-IDs), overlap (JSON), status (`proposed`, `approved`, `rejected`, `in_production`, `produced`, `discarded`), post_id, created_at, updated_at

**`lsec_topic_entities`**: topic_id, entity_id, role (`primary`, `secondary`) – PK (topic_id, entity_id)  
**`lsec_topic_keywords`**: topic_id, keyword_id, role (`primary`, `secondary`) – PK (topic_id, keyword_id)  
**`lsec_briefs`**: id, topic_id, version, payload (JSON, 8.5), created_at

### 4.4 Workflow, KI und Qualität

**`lsec_runs`**: id, uuid, type (`content_production`, `entity_extraction`, `topic_research`, `keyword_sync`, `content_index`), subject_type, subject_id, status (7.2), current_step, idempotency_key (CHAR(64) UNIQUE), context (JSON), locked_until, lock_token, error_code, error_message (geschwärzt), created_by, created_at, updated_at, finished_at

**`lsec_run_steps`**: id, run_id (INDEX), step, status (`pending`, `running`, `done`, `failed`, `skipped`), attempt, max_attempts, input_hash, output (JSON), error_code, error_message, started_at, finished_at; UNIQUE (run_id, step)

**`lsec_ai_calls`**: id, run_id, step_id, task, provider, model, input_tokens, output_tokens, cost_estimate, duration_ms, status (`ok`, `retryable_error`, `fatal_error`, `invalid_output`), request_hash, created_at. `cost_estimate` stammt bei OpenRouter aus der Antwort, sonst aus der Preistabelle `ai_prices`; ohne Preis bleibt es NULL und die Anzeige sagt „Kosten unbekannt“. Grundlage für das KI-Budget (8.1b).

**`lsec_provider_calls`** – Aufrufe externer Datenanbieter (Textfocus, Wikidata; später DataForSEO, Screpy)

| Spalte | Hinweis |
|---|---|
| id | PK |
| provider, endpoint | z. B. `textfocus`/`tf_keyword`, `wikidata`/`wbsearchentities` |
| request_hash | CHAR(64) INDEX – Endpunkt, Sprache und normalisierte Parameter |
| credits_used, credits_remaining | laut Anbieter |
| status | `ok`, `cached`, `budget_exceeded`, `rate_limited`, `error` |
| response_ref | Import-ID oder bei `cached` der Ursprungsaufruf (`call:<id>`) |
| response_body | seit v2: nur das Feld `result` der Antwort; Textfocus wiederholt den API-Schlüssel in `params`. Für `tf_account` leer (Adressdaten) |
| cached_until | Ablauf des Zwischenspeichers |
| run_id | auslösender Ablauf |
| created_at | |

**`lsec_quality_reports`**: id, post_id, run_id, profile, score, passed, blocking_issues (JSON), warnings (JSON), checks (JSON), created_at

**`lsec_style_profiles`**: id, version, profile (JSON, 8.7), inputs (JSON), is_active, created_by, created_at. Genau ein aktives Profil.

### 4.5 WordPress-Daten

| Datenart | Speicherort |
|---|---|
| Einstellungen | Option `lsec_settings` (Array), änderbar über `wp lsec settings` und später das Backend |
| Geheimnisse | `wp-config.php`-Konstante oder verschlüsselte Option (11) |
| Beitragsbezug | Post-Meta `_lsec_run_id`, `_lsec_topic_id`, `_lsec_quality_report_id`, `_lsec_generated_at`, `_lsec_primary_entities` |
| Kategorien, Schlagwörter | WordPress-Taxonomien; neue Kategorien nur nach Freigabe |

### 4.6 Deinstallation

Deaktivieren entfernt nichts. `uninstall.php` löscht Tabellen und Optionen nur, wenn „Daten bei Deinstallation entfernen“ aktiviert ist. Erzeugte Beiträge werden nie gelöscht.

### 4.7 Schemaversion 3 (MVP 1, Stufe A)

| Tabelle | Änderung | Grund |
|---|---|---|
| `lsec_keyword_metrics` | Spalte `competition` DECIMAL(5,2) NULL | Textfocus liefert Wettbewerb (`concurrency` 74 bzw. 23 im Test), während `difficulty` immer 0 war |
| `lsec_keywords` | Spalte `exclusion` VARCHAR(20) NULL, INDEX | Filter für Wettbewerber, Marken und fremde Orte (6.4) |
| `lsec_entities` | Spalte `merged_into` BIGINT UNSIGNED NULL, INDEX; Status `merged` | Zusammenführen (8.3) |

Die Migration ergänzt nur Spalten; vorhandene Daten bleiben. Bereits importierte Textfocus-Daten werden nicht automatisch ergänzt. Ein erneutes Einlesen aus dem Zwischenspeicher füllt `competition` ohne Credit, weil `response_body` das vollständige Feld `result` enthält.

---

## 5. Inhaltsindex bestehender Beiträge

- Ein Ablauf `content_index` liest veröffentlichte Beiträge und Seiten in Stapeln (**Vorschlag:** 20 pro Schritt).
- Je Beitrag: Titel als Klartext (WordPress speichert `&` als `&amp;`), Überschriften, bereinigter Fließtext, Kategorien, Rank-Math-Fokus-Keyword, Inhalts-Hash.
- **Elementor:** Text aus `_elementor_data` (geprüft in M14). Elementor-Inhalte werden nie geschrieben.
- Geänderte Beiträge werden über `save_post` zur Neuindizierung vorgemerkt; unveränderter Hash → keine neue Entity-Erkennung.
- Ähnlichkeit über Keyword- und Entity-Überschneidung sowie die Search-Console-Zuordnung (welche Seite erscheint für welche Anfrage). Kein Vektorindex im MVP.

Prüfbefehl: `wp lsec check content <id>` (vorhanden), neu `wp lsec index run|status`.

---

## 6. Keyword-Datenquellen

### 6.1 Gemeinsame Schnittstelle

```php
interface KeywordSource {
    public function id(): string;              // 'gsc', 'textfocus', 'import', 'ai_search'
    public function capabilities(): array;     // 'volume', 'queries', 'trend', 'serp', 'difficulty', 'competition'
    public function isAvailable(): bool;
    public function fetch(KeywordQuery $q): KeywordResultSet;
}
```

Alle Quellen schreiben in `lsec_keywords` und `lsec_keyword_metrics`. Jede Kennzahl behält ihre Quelle. Fehlende Werte erscheinen als „unbekannt“.

### 6.2 Quellen im MVP

**Google Search Console** (umgesetzt, M9)

- Dienstkonto, in der Search Console als Nutzer der Property eingetragen; eingeschränkte Berechtigung genügt. Kein OAuth im MVP.
- Search Analytics API, Dimensionen `query` und `page`, Filter Land (Einstellung `gsc_country`, Standard `deu`).
- Zeitraum endet drei Tage vor heute (endgültige Daten); Standard 28 Tage.
- Zeilen je Suchanfrage und Seite werden je Suchanfrage zusammengefasst: Summen, CTR, nach Impressionen gewichtete Position, Seite mit den meisten Klicks.
- Ein erneuter Abruf desselben Zeitraums ersetzt die Werte.
- **MVP 1:** Ablauf `keyword_sync` täglich für 28 Tage, wöchentlich zusätzlich für 90 Tage.
- Befehle: `wp lsec gsc sites [--save]`, `wp lsec gsc fetch [--days] [--country] [--dry-run]`, `wp lsec keywords list --source=gsc`.

**CSV-/Excel-Import** (umgesetzt, M10)

- Profile: Keyword Revealer (CSV), Keyword Chef (CSV), Textfocus „Keyword volume search“ (Excel), allgemeine Spaltenerkennung.
- Erkennung von Trennzeichen, Kodierung (UTF-8 mit/ohne BOM, Windows-1252) und deutschen Zahlen (`1.200`, `0,45`).
- Die Entitäten-Tabelle einer Textfocus-Seitenanalyse wird erkannt und an den Entitäten-Import verwiesen (8.3).
- **MVP 1:** Upload im Backend mit Vorschau und änderbarer Spaltenzuordnung.

**Textfocus-API** (umgesetzt, M16): Abschnitt 6.5.

**KI-Websuche** (umgesetzt, M7a): Suchergebnisse, Wettbewerber, Suchintention, regionale Besonderheiten. Keine Volumina. Quellen als `ai_search` mit URL. Kosten und Einstellungen in 8.1b.

### 6.3 Spätere Quellen

| Quelle | Rolle | Ausbaustufe |
|---|---|---|
| Google Trends API (Alpha) | Saisonalität, Regionen | Ausbau 2, sobald Zugang vorliegt |
| DataForSEO | Volumina, SERP-Daten; Abrechnung je Abfrage, nur nach Aktivierung mit Kostenanzeige | Ausbau 2, optional |
| Screpy | REST-API: Ranking-Verlauf, Backlinks, Site-Audit, Core Web Vitals, KI-Sichtbarkeit. Keine Keyword-Recherche. Offen: ortsgenaues Ranking, genaue Endpunkte | Ausbau 3 |
| Indexly | nur KI-Sichtbarkeit; höchstens einer von Screpy und Indexly | Ausbau 3, optional |

### 6.4 Priorisierung und Filter

**Filter vor der Priorisierung.** Der Test hat gezeigt, dass `tf_related` für ein allgemeines Keyword vor allem Firmennamen anderer Anbieter und fremde Regionen liefert (Hamburg, Seevetal). Bevor ein Keyword in die Themenplanung geht, setzt das Plugin `exclusion`:

| Grund | Regel |
|---|---|
| `competitor` | enthält den Namen oder einen Alias einer Entität vom Typ `competitor` |
| `brand` | enthält eine fremde Marke oder einen Firmennamen, der nicht zur eigenen Organisation gehört (Abgleich mit Entitäten; unbekannte Firmennamen erkennt die KI in der Themenrecherche) |
| `foreign_place` | enthält einen Ort, der laut Ortsabgleich (8.3) außerhalb des Servicegebiets liegt |
| `off_topic` | ohne Bezug zu bestätigten Leistungen (KI-Einschätzung in der Themenrecherche, mit Begründung) |
| `manual` | vom Administrator ausgeschlossen |

Ausgeschlossene Keywords bleiben gespeichert und sichtbar, gehen aber nicht in Themenvorschläge ein. Der Ausschluss lässt sich aufheben. Eigene Marken (z. B. Mosmatic als vertriebenes Produkt) werden als Entität `brand` mit Beziehung `owns_brand` oder `offers` gepflegt und nicht ausgeschlossen. Für regionale Abfragen bei Textfocus wird der Ortsname ins Keyword genommen.

**Priorität.** Jeder Einzelwert steht in `score_breakdown` und wird im Backend angezeigt.

| Faktor | Herkunft | Gewicht (Vorschlag) |
|---|---|---|
| Geschäftsrelevanz | Bezug zu bestätigten Leistungen und Standorten | 30 % |
| Nachfragesignal | Suchvolumen (Textfocus, Import) oder Impressionen (Search Console), normalisiert | 25 % |
| Inhaltslücke | Thema bzw. Entität bisher nicht abgedeckt | 20 % |
| Lokaler Bezug | Ort im Servicegebiet, regionale Besonderheit, Anfragen mit „in der Nähe“ | 15 % |
| Saisonalität | Zeitpunkt passend zur Saison (nur aus Quellen mit Monatsangabe) | 10 % |

Fehlt ein Faktor, zählt er nicht als 0; die übrigen Gewichte werden hochgerechnet, und das Thema erhält den Hinweis „eingeschränkte Datenbasis“. Gewichte sind einstellbar. Wettbewerb (`competition`) fließt nicht in die Priorität ein, sondern wird angezeigt.

**Search Console als Signal.** Im Test hatte die Website keine Klicks, aber Impressionen auf Positionen 20–90, und lokale Seiten wie Wolfsburg rankten für eng passende Anfragen auf Position 7. Daraus folgen zwei Regeln:

1. Eine Anfrage, für die schon eine eigene Seite erscheint, ist **kein neues Thema**, wenn die Seite zum Thema passt (gleiche Haupt-Entität). Sie wird als Überschneidung mit dieser Seite angezeigt und in MVP 1 als Hinweis „bestehende Seite verbessern“ im Bericht geführt. Die Überarbeitung selbst folgt in Ausbau 2.
2. Anfragen mit Ortsnamen im Servicegebiet, zu denen keine eigene Seite passt, sind Kandidaten für lokale Beiträge (`format = local_landing`).

Ein Thema wird nicht vorgeschlagen, wenn die Überschneidung über dem Schwellenwert liegt (**Vorschlag:** gleiche Haupt-Entität und gleiches Haupt-Keyword) oder keine Quelle es stützt.

### 6.5 Textfocus-Adapter

| Punkt | Festlegung |
|---|---|
| Zugang | `POST https://www.textfocus.net/apis/<endpoint>/`, Parameter `key` |
| Schlüssel | Konstante `LSEC_TEXTFOCUS_API_KEY` mit Vorrang, sonst verschlüsselte Option |
| Fehler | Ein ungültiger Schlüssel liefert HTTP 200 mit `"response":404`. Fehler werden am Feld `response` und an der Meldung erkannt, nicht nur am HTTP-Status. Ratenlimit → Wiederholung; fehlender API-Zugang oder ungültiger Schlüssel → sofort `failed` |
| Sprache | Die Sprachcodes sind frei vergebene Kürzel (`de` Deutschland, `at` Österreich, `ch` Schweiz, `bm` Belgien). `wp lsec textfocus langs --save=<id>` speichert Code und daraus abgeleitete Locale (`textfocus_lang`, `textfocus_locale`) |
| Endpunkte im MVP | `tf_account` (0 Credits), `tf_langs`, `tf_keyword` (mehrere Keywords je Aufruf als `keyword[0]=…`), `tf_related`, `tf_competition`, `tf_semantic`, `tf_seo` |
| Später | `tf_structured_data`, `tf_perf`, `tf_links`, `tf_geo`, `tf_seo_bulk` |
| Tarif der Testwebsite | AppSumo-Paket mit API-Zugang, 60 Aufrufe pro Tag; Volumina sind enthalten |
| Credits | 1 Credit je Aufruf, auch mit `force_refetch`. Tagesbudget `textfocus_daily_budget` (Standard 25); Tag nach Zeitzone der Website. Bei erreichtem Budget wartet der Ablauf auf den nächsten Tag. Das Restguthaben zeigt `tf_account` zuverlässig, nicht die einzelne Antwort |
| Abstand | mindestens `textfocus_min_interval` (60 s) zwischen Aufrufen. WP-CLI wartet und meldet das; ein Ablaufschritt wirft stattdessen `rate_limited` |
| Bündelung | `tf_keyword` mit bis zu `textfocus_batch_size` (10) Keywords je Aufruf; geprüft: 2 Keywords für 1 Credit |
| Zwischenspeicher | über `request_hash`; Volumina 30 Tage, Wettbewerber und Semantik 14 Tage, On-Page 7 Tage. `--force` nur auf ausdrücklichen Wunsch |
| Callbacks | nicht genutzt; Abfrage direkt mit 60 s Zeitlimit |
| Entitäten | immer Kandidaten (8.3) |
| Befehle | `wp lsec textfocus account`, `langs [--search] [--save]`, `keyword <kw>… [--dry-run] [--force]`, `query related\|competition\|semantic\|seo <kw> [--url]`, `calls` |

**Server-Hinweis:** Auf dem Staging-Server lieferten die Resolver des Hosters für www.textfocus.net zeitweise eine falsche Adresse (cURL-Fehler 60). Die Fehlermeldung nennt diese mögliche Ursache. Das betrifft jede Website mit diesen Resolvern und ist kein Fehler des Plugins.

### 6.6 Importregeln je Quelle

1. **Nullwerte je Quelle.** Keyword Chef liefert 0 ohne Daten: Sind alle Volumina einer Datei 0, werden sie als NULL gespeichert, und der Import erhält einen Hinweis.
2. **Textfocus-Nullwerte.** Bei Textfocus (API und Excel) gelten Volumen, CPC, Schwierigkeit und Wettbewerb mit dem Wert 0 je Zeile als unbekannt.
3. **Verlauf ohne Monate.** Die 12 Monatswerte von Keyword Revealer werden als Trend mit `labeled: false` gespeichert und nur für die Spannweite genutzt, nicht für die Saisonbewertung.
4. **Keine PDF-Berichte.** Der Textfocus-Gesamtbericht ist ein Bild-PDF; importiert werden Excel-Tabellen oder API-Daten.
5. **Textfocus-Entitäten** gehen in den Entitäten-Import, nicht in den Keyword-Import (8.3).
6. **Search Console** liefert keine Volumina. Ihre Werte stehen in eigenen Spalten und werden nicht als Suchvolumen angezeigt.

### 6.7 Prüfung auf Tippfehler

Bevor ein neues Keyword an einen bezahlten Anbieter geht, vergleicht das Plugin es mit vorhandenen Keywords, Leistungen und Entitäten (Editierabstand, Umlaut-Varianten). Bei einem naheliegenden Treffer fragt es nach (`wp lsec keywords check`, M17). Umlaut-Varianten aus der Search Console („naehe“/„nähe“) werden bei der Themenplanung als eine Anfrage behandelt, aber getrennt gespeichert.

---

## 7. Aufgabensteuerung

### 7.1 Architektur

- **Warteschlange:** Action Scheduler (Composer), Gruppe `lsec`. Jeder Ablaufschritt ist eine Aktion.
- **Ausführung:** Server-Cronjob mit WP-CLI; keine Laufzeitgrenzen des Webservers.
- **Fachliche Steuerung:** Status, Zwischenergebnisse und Reihenfolge in `lsec_runs` und `lsec_run_steps`. Der Action Scheduler stößt nur an.

**Cronjob alle 5 Minuten** (Plesk „Geplante Aufgaben“, als Benutzer des Abonnements):

```bash
wp --path=/var/www/vhosts/<domain>/httpdocs lsec cron
```

`wp lsec cron` schließt bei Bedarf die einmalige Datenmigration von Action Scheduler ab, gibt verwaiste Aufträge nach `--stale-after` (600 s) frei, startet den Watchdog und ruft dann `action-scheduler run --group=lsec --batch-size=3 --batches=1` auf. Die WP-Toolkit-Aufgabe für `wp-cron.php` bleibt für andere Plugins bestehen. Das Backend warnt, wenn die letzte Ausführung mehr als 15 Minuten zurückliegt.

### 7.2 Status eines Ablaufs

```text
queued ──► running ──► succeeded
              │  ▲
              │  └── (nächster Schritt geplant)
              ├──► waiting     (wartet auf Administrator oder auf Budget/Tageswechsel)
              ├──► blocked     (Qualitätsprüfung nicht bestanden – Ergebnis, kein Fehler)
              ├──► failed      (Wiederholungen ausgeschöpft oder endgültiger Fehler)
              └──► cancelled   (durch Administrator)
```

`failed` und `blocked` lassen sich ab dem betroffenen Schritt fortsetzen; erledigte Schritte laufen nicht erneut. **Neu:** `waiting` gilt auch, wenn das Textfocus-Tagesbudget oder das KI-Budget (8.1b) erreicht ist; der Ablauf läuft nach dem Tages- bzw. Monatswechsel oder nach Freigabe weiter.

### 7.3 Abläufe in MVP 1

**`content_production`**

| Nr. | Schritt | Inhalt | KI |
|---|---|---|---|
| 1 | `load_topic` | Thema laden, `approved` prüfen, auf `in_production` setzen | – |
| 2 | `gather_context` | Entitäten (`confirmed`, `probable`), Beziehungen, Aussagen mit Status `verified` oder `confirmed` und Belegen, Keywords, überschneidende Beiträge, Linkziele | – |
| 3 | `create_brief` | Briefing (8.5) | ja |
| 4 | `generate_draft` | Text als Zwischenstruktur mit Style Mimic (8.6) | ja |
| 5 | `enrich` | interne Links prüfen, Kategorien, Schlagwörter, Rank-Math-Angaben, CTA | teilweise |
| 6 | `quality_check` | Regelprüfungen und KI-Bewertung (10) | ja |
| 7 | `create_post` | Entwurf anlegen bzw. aktualisieren | – |
| 8 | `finalize` | Thema auf `produced`, Fundstellen, Bericht | – |

Bei blockierenden Mängeln entsteht trotzdem ein Entwurf mit sichtbarem Prüfbericht und Status `blocked`. Er kommt für eine spätere automatische Veröffentlichung nie in Frage.

**`topic_research`**: `collect_keywords` (Quellen abgleichen, Filter 6.4) → `research` (Websuche, 8.4) → `verify_facts` (8.4a) → `propose_topics` (Priorität, Überschneidung) → Status `waiting` bis zur Freigabe.

**`entity_extraction`**: je Beitrag `extract` (8.3) → `match_places` (Wikidata) → `save`. Wird vom Inhaltsindex für neue oder geänderte Beiträge angestoßen.

**`keyword_sync`**: Search Console abrufen, Textfocus-Volumina für neue Keywords ohne Volumen nachholen (innerhalb des Budgets, Tippfehlerprüfung vorher).

**`content_index`**: 5.

### 7.4 Sperren, Wiederholungen und Doppelschutz

Umgesetzt und geprüft in M4–M6:

- **Ablaufsperre** über ein atomares Update mit `lock_token` und `locked_until` (10 Minuten). Eine abgelaufene Sperre gilt als abgebrochener Lauf.
- **Idempotenz:** `idempotency_key = sha256(type + subject + Briefing-Version)`.
- **Kein doppelter Beitrag:** `create_post` sucht zuerst nach `_lsec_run_id`.
- **Wiederholungen:** höchstens 3 Versuche je Schritt nach 1, 5 und 30 Minuten.

| Fehlerart | Verhalten |
|---|---|
| Netzwerkfehler, Zeitüberschreitung, HTTP 429/5xx | wiederholen |
| Ungültige KI-Antwort (Struktur) | einmal mit Korrekturhinweis wiederholen, dann `failed` |
| Authentifizierung, fehlender Schlüssel, HTTP 400/401/403, Ausgabenlimit des Anbieters | sofort `failed` mit Klartext (im Test: Anthropic-Ausgabenlimit als HTTP 400) |
| Budget erreicht (Textfocus, KI) | `waiting` |
| Fachlicher Abbruch (z. B. zu wenig Belege) | `blocked` bzw. Thema `discarded` mit Begründung |

**Mengengrenzen:** höchstens `max_parallel_runs` (3) gleichzeitige Abläufe, höchstens N Entwürfe pro Tag (**Vorschlag:** 3), KI-Budget (8.1b).

---

## 8. KI-Schnittstelle und Ergebnisverträge

### 8.1 Schnittstelle

```php
interface AiClient {
    public function run(AiRequest $request): AiResponse;
}
```

`AiRequest` enthält Aufgabe, Systemanweisung, Nachrichten, JSON-Schema, ob Websuche erlaubt ist, maximale Ausgabe-Token und Zeitlimit (120 s). `AiResponse` enthält das geprüfte Ergebnis, Token, Kosten, Dauer und Quellen. Jeder Aufruf steht in `lsec_ai_calls`. Prüfbefehle: `wp lsec ai test [--web]`, `wp lsec ai models`.

### 8.1a Anbieter

Umgesetzt sind Adapter für **OpenRouter** (Chat Completions, `response_format` mit `json_schema`, Plugin `web`, Kosten aus der Antwort), **Anthropic** (Messages API) und **OpenAI** (Responses API, Werkzeug `web_search`). Alle drei wurden im Machbarkeitstest mit echten Aufrufen bzw. bei Anthropic bis zur Fehlermeldung des Kontos geprüft (M7, M7a, M7b).

Der Administrator wählt einen Anbieter und ein Modell (`ai_provider`, `ai_model`), das für alle Aufgaben gilt (Anforderung 14). Preise für Anthropic und OpenAI stehen in der Einstellung `ai_prices` (USD je Million Token). Fehlt ein Preis, zeigt das Plugin „Kosten unbekannt“; das KI-Budget zählt solche Aufrufe dann mit einer Warnung nicht mit.

**Modell (Vorschlag – Entscheidung offen):** OpenRouter mit `anthropic/claude-sonnet-5.5` als Startwert, weil es im Test gültige, sachlich vorsichtige Ergebnisse geliefert hat. Festgelegt wird nach dem Vergleich in Stufe E (14) mit drei deutschen Beispielbeiträgen der Testwebsite; verglichen werden Textqualität, Faktentreue nach 8.4a und Kosten je Beitrag.

### 8.1b Kostensteuerung

Der Test hat die Websuche als Kostentreiber gezeigt: Die Suchergebnisse werden als Eingabe-Token in den Prompt eingefügt (OpenRouter 44.229 Token und $0,142 für die Recherche, Strukturierung $0,005; OpenAI 28.839 Token). Eine Entity-Erkennung kostete rund $0,04 je Seite.

| Maßnahme | Festlegung |
|---|---|
| Ausgabenlimit beim Anbieter | bleibt die eigentliche Begrenzung (Anforderung 14); im Backend als Einrichtungshinweis |
| KI-Budget im Plugin | zusätzliche Bremse: Monatsbudget `ai_monthly_budget` und Höchstbetrag je Ablauf `ai_run_budget` in USD, berechnet aus `lsec_ai_calls.cost_estimate`. Bei Erreichen gehen Abläufe auf `waiting`; manuelle Prüfbefehle laufen mit Warnung weiter. **Vorschlag:** 20 USD im Monat, 1 USD je Ablauf – **Entscheidung offen** |
| Treffer der Websuche | einstellbar `ai_web_max_results`; **Vorschlag:** 3 statt bisher 5. Bei OpenAI zusätzlich `search_context_size: low`. Wirkung auf Kosten und Quellenqualität wird in Stufe D gemessen |
| Websuche nur wo nötig | nur im Schritt `research` der Themenrecherche; Briefing, Entwurf, Prüfung und Entity-Erkennung ohne Websuche |
| Zwischenspeicher | keine neue Recherche, wenn für dieselbe Entität und Region in den letzten 14 Tagen (**Vorschlag**) schon geprüfte Aussagen vorliegen; die Ergebnisse stehen in `lsec_sources`, `lsec_claims` und `lsec_evidence` |
| Anzeige | Kosten je Ablauf, je Beitrag und je Monat im Dashboard; Schätzung vor dem Start eines Sammelauftrags |
| Eigenes Modell für die Recherche | würde die Anforderung „ein Modell für alle Aufgaben“ ändern. **Entscheidung offen**; Vorschlag: nicht in MVP 1, erst wenn die Messung in Stufe D zeigt, dass weniger Treffer nicht reichen |

**Grobe Schätzung je Beitrag (zu messen):** Recherche $0,05–0,15, Faktenprüfung bis $0,02, Briefing, Entwurf und Bewertung zusammen $0,05–0,15, also unter $0,50.

### 8.2 Validierung

**Recherche und Struktur getrennt:** Aufgaben mit Websuche laufen in zwei Aufrufen: Recherche mit Quellen, dann Strukturierung ohne Werkzeuge (bestätigt in M7a für OpenRouter und OpenAI).

1. JSON-Struktur gegen das Schema prüfen; bei Fehlern ein Korrekturversuch.
2. Referenzen prüfen: jede genannte ID existiert.
3. Fachlich prüfen: Faktenprüfung (8.4a) und Qualitätsprüfung (10). Eine strukturell gültige Antwort ist nicht automatisch richtig.

### 8.3 Entity-Erkennung (`entity_extraction`)

Ergebnisvertrag:

```json
{
  "entities": [
    {
      "ref": "e1", "existing_entity_id": null, "type": "service",
      "name": "Dachsanierung", "aliases": ["Dach sanieren"], "description": "…",
      "confidence": 0.82,
      "evidence": [{ "source_ref": "post:123", "excerpt": "…", "location": "heading" }]
    }
  ],
  "relations": [
    { "source": "org:1", "type": "offers", "target": "e1", "confidence": 0.9,
      "evidence": [{ "source_ref": "post:123", "excerpt": "…" }] }
  ],
  "ambiguities": [{ "ref": "e2", "reason": "…", "candidates": ["…"] }]
}
```

**Regeln (umgesetzt in M11):**

- Neue Entitäten sind immer `candidate`, mehrdeutige `unresolved`.
- Ein Vorschlag wird nur übernommen, wenn eine Fundstelle wörtlich im Text steht (ohne Groß-/Kleinschreibung, vereinheitlichte Anführungszeichen und Leerraum). Ohne gültiges Zitat genügt der Name, wenn er im Text steht; dann wird seine Satzumgebung gespeichert. Alles andere wird verworfen und angezeigt.
- Entscheidungen im Backend setzen `decided_by` und `decided_at` und werden mit der Stufe `audit` protokolliert.

**Neu in MVP 1:**

- **Schärfere Anweisung:** nur benannte Dinge (Unternehmen, Marken, Orte, konkrete Leistungen und Produkte, Personen, Fachbegriffe der Branche), keine allgemeinen Tätigkeiten wie „regelmäßige Wartung“. Im Test schwankten zwei Läufe auf derselben Seite zwischen 7 und 9 Vorschlägen.
- **Mindest-Sicherheit:** Vorschläge unter `entity_min_confidence` (**Vorschlag:** 0,6) werden gespeichert, aber in der Übersicht standardmäßig ausgeblendet.
- **Abgleich mit Vorhandenem:** Vor dem Speichern werden Name und Aliasse gegen `normalized_name` und `normalized_alias` geprüft; ein Treffer ergänzt Fundstellen statt eine neue Entität anzulegen. Bekannte Entitäten gehen als Liste in die Anfrage (`existing_entity_id`).
- **Zusammenführen:** Im Backend wählt der Administrator Ziel und Quellen. Die Namen der Quellen werden Aliasse des Ziels, Fundstellen, Beziehungen, Aussagen und Belege wandern zum Ziel, die Quellen erhalten `merged` und `merged_into`. Das Plugin schlägt Zusammenführungen vor, wenn ein Name den Namen einer bestätigten Organisation enthält oder mit ihm beginnt (Test: vier Schreibweisen des Firmennamens unter den Textfocus-Entitäten). `wp lsec entities merge <ziel> <quelle>…`.

**Ortsabgleich über Wikidata (umgesetzt in M18):**

- Suche nach dem Namen, nur Treffer in der Sprache der Website; Treffer über Aliasse anderer Sprachen entfallen.
- Nur Einträge mit Koordinaten (P625) im Land der Website (P17).
- Einträge, die in einem anderen Kandidaten liegen (P131, z. B. Ortskern, Bahnhof), entfallen.
- Servicegebiet: Verwaltungseinheiten (Prüfung der P131-Kette bis Tiefe 3) und/oder Umkreis um einen Mittelpunkt.
- Genau ein Kandidat im Gebiet → `probable` mit Wikidata-ID; mehrere oder keiner → `unresolved` mit Kandidaten.
- Antworten 30 Tage im Zwischenspeicher; HTTP 429 ist wiederholbar.
- Befehle: `wp lsec entities service-area`, `resolve-place <name>`.

**Abgleich über Kontext:** Externe Zuordnungen (Textfocus, Wikidata) gelten erst nach Prüfung von Typ, übergeordneter Verwaltungseinheit und Nähe zum Servicegebiet als `probable`. Für Nicht-Orte (z. B. „Böden“ → Bodenkunde bei Textfocus) bleibt die Zuordnung in MVP 1 ein Hinweis; die Prüfung folgt in Ausbau 2.

**Textfocus-Entitäten (umgesetzt):** `wp lsec entities import <datei>` legt Kandidaten mit `origin = import` an; Name = Begriff auf der Seite, Wikidata-Vorschlag nur in `properties.textfocus`. „&“ und „und“ gelten beim Abgleich als gleich.

### 8.4 Themenrecherche (`topic_research`)

Ergebnisvertrag der Strukturierung:

```json
{
  "topics": [
    {
      "title": "…", "intent": "local", "format": "local_landing", "region": "Lehrte",
      "primary_entity_ids": [12], "secondary_entity_ids": [4, 19],
      "primary_keyword_ids": [301], "secondary_keyword_ids": [302, 305],
      "rationale": "…",
      "facts": [
        { "subject_entity_id": 19, "property": "first_mentioned", "value": "1226",
          "source_url": "https://…", "quote": "wörtliches Zitat aus der Quelle" }
      ],
      "excluded_keywords": [{ "keyword_id": 410, "reason": "competitor" }],
      "overlap_post_ids": [],
      "insufficient_data": false
    }
  ]
}
```

Jede Tatsache braucht eine Quelle und ein wörtliches Zitat. Tatsachen ohne Zitat werden nicht gespeichert.

### 8.4a Faktenprüfung (`verify_facts`, neu)

Im Test nannten zwei Modelle mit Quellenangabe dieselbe Jahreszahl für zwei verschiedene Ortsteile (1226 für Eltze bzw. Dollbergen). Eine URL allein belegt also nichts. Jede Tatsache aus der Recherche wird deshalb geprüft, bevor sie als Aussage gilt:

1. **Quelle abrufen** mit `wp_safe_remote_get` (Zeitlimit 20 s, höchstens 2 MB, nur `http`/`https`, 3 Weiterleitungen), HTML in Text umwandeln, Inhalt und `content_hash` in `lsec_sources` speichern. Zwischenspeicher 30 Tage.
2. **Zitat suchen** mit derselben Vereinheitlichung wie bei den Fundstellen (8.3).
3. **Bezug prüfen:** Name oder Alias der Entität und der Wert stehen im Zitat oder im selben Absatz.
4. **Ergebnis:**

| Befund | Status der Aussage | Beleg |
|---|---|---|
| Zitat gefunden, Entität und Wert im Zitat oder Absatz | `verified` | `strong` |
| Zitat gefunden, Wert ja, Entität nicht | `disputed` mit Hinweis „Bezug unklar“ | `weak` |
| Zitat nicht gefunden oder Seite nicht abrufbar | `rejected` (nicht verwendbar), sichtbar im Bericht | – |
| zwei geprüfte Aussagen widersprechen sich | beide `disputed` | wie gefunden |

5. **Optional, je Fall:** Bleibt ein Bezug unklar, prüft ein kurzer KI-Aufruf ohne Websuche nur das Zitat gegen die Aussage. **Vorschlag:** in MVP 1 ausgeschaltet, Einstellung `fact_check_ai`.

Seiten, die keinen Abruf erlauben (z. B. Anmeldung, Skript-Inhalte), führen zu `rejected`, nicht zu einem Fehler des Ablaufs. Der Administrator kann eine Aussage im Backend bestätigen (`confirmed`); dann gilt sie auch ohne automatische Prüfung.

### 8.5 Briefing (`create_brief`)

```json
{
  "working_title": "…", "search_intent": "…", "audience": "…",
  "outline": [{ "heading": "…", "level": 2, "purpose": "…", "entity_ids": [12] }],
  "required_facts": [{ "claim_id": 88, "use": "…" }],
  "facts_to_avoid": ["unbelegte Preisangaben"],
  "internal_link_targets": [{ "post_id": 210, "anchor_hint": "…" }],
  "elements": ["faq", "table"],
  "length": { "min_words": 900, "max_words": 1500 },
  "cta": "…"
}
```

`required_facts` dürfen nur Aussagen mit Status `verified` oder `confirmed` nennen.

### 8.6 Beitragserstellung (`generate_draft`)

```json
{
  "title": "…",
  "content": [
    { "type": "paragraph", "text": "…" },
    { "type": "heading", "level": 2, "text": "…" },
    { "type": "list", "ordered": false, "items": ["…"] },
    { "type": "table", "head": ["…"], "rows": [["…"]] },
    { "type": "quote", "text": "…", "cite": "…" },
    { "type": "separator" },
    { "type": "details", "summary": "Frage", "text": "Antwort" }
  ],
  "excerpt": "…",
  "used_entity_ids": [12, 4],
  "used_claim_ids": [88],
  "unsupported_statements": [],
  "internal_links": [{ "post_id": 210, "anchor": "…" }],
  "seo": { "title": "…", "description": "…", "focus_keyword": "…", "secondary_keywords": ["…"], "slug": "…" },
  "taxonomy": { "category_ids": [5], "new_category_suggestions": [], "tags": ["…"] }
}
```

Das Plugin baut das Block-Markup selbst (umgesetzt, M12):

- Texte dürfen `<strong>`, `<em>`, `<a href>` und `<br>` enthalten; alles wird mit `wp_kses_post()` bereinigt.
- Markup nach der `save()`-Ausgabe der Core-Blöcke der laufenden WordPress-Version: Liste mit Klasse `wp-block-list` erst ab 6.6, Tabelle mit `{"hasFixedLayout":false}`, Zitat mit innerem Absatz, FAQ als `core/details`.
- Interne Links bleiben nur, wenn sie auf einen veröffentlichten Inhalt der Website zeigen; sonst bleibt der Ankertext.
- Jede Zahl, jedes Datum und jede Ortsangabe im Text muss aus `used_claim_ids` oder dem Unternehmensprofil stammen; sonst gehört sie in `unsupported_statements`.

### 8.7 Style Mimic (`style_profile`)

```json
{
  "tone": "…", "address": "Sie", "sentence_length": "kurz bis mittel",
  "vocabulary": { "preferred": ["…"], "avoid": ["…"] },
  "structure_habits": ["…"],
  "rules": ["keine Ausrufezeichen in Überschriften"],
  "examples": [{ "do": "…", "dont": "…" }]
}
```

Eingaben: ausgewählte eigene Beiträge (Text aus dem Inhaltsindex), Beispieltexte, feste Regeln. Das Profil ist im Backend bearbeitbar; jede Änderung erzeugt eine Version.

### 8.8 Qualitätsbewertung (`quality_review`)

```json
{
  "scores": { "intent": 0.9, "local_relevance": 0.8, "entity_integration": 0.85,
              "value": 0.8, "readability": 0.9, "style_match": 0.85 },
  "blocking_issues": [{ "code": "UNSUPPORTED_FACT", "detail": "…", "location": "…" }],
  "warnings": [{ "code": "THIN_SECTION", "detail": "…" }]
}
```

---

## 9. WordPress-, Editor- und Rank-Math-Integration

### 9.1 Beiträge

- `wp_insert_post()` mit `post_status = draft`, Autor = auslösender Administrator bzw. eingestellter Redaktionsbenutzer.
- Inhalt als Gutenberg-Blöcke (geprüft im Block-Editor mit 6.4.5 und 7.1.3, keine Warnung „unerwarteter Inhalt“).
- Elementor nur lesend.
- Neue Kategorien nur als Vorschlag; Anlage nach Freigabe.
- Befehle: `wp lsec draft sample|create|show`.

### 9.2 Rank Math

- Erkennung über `did_action('rank_math/loaded')`; die Konstante allein reicht nicht.
- Gesetzt werden `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` (Haupt-Keyword zuerst, weitere kommagetrennt). Geprüft mit Rank Math 1.0.280 und WordPress 7.1.3; Variablen wie `%sep%` und `%sitename%` werden aufgelöst.
- Die Editor-Funktionen von Rank Math erscheinen erst nach Verbindung oder übersprungener Registrierung; das Plugin weist im Dashboard darauf hin.
- Ohne aktives Rank Math: SEO-Angaben nur als Vorschlag im Plugin, keine eigenen Meta-Tags.
- Strukturierte Daten folgen in Ausbau 2; die Content Analysis API erst bei Editor-Empfehlungen.

### 9.3 Backend-Oberfläche

| Bereich | Inhalt | Stand |
|---|---|---|
| Dashboard | Status, offene Freigaben, letzte Abläufe, KI-Kosten und Budget, Textfocus-Credits, Cron-Zustand, Einrichtungshinweise | Statusseite vorhanden |
| Unternehmensprofil | Unternehmen, Standorte, Leistungen, Marken, Wettbewerber, Servicegebiet mit Ortssuche | neu |
| Entitäten | Filter nach Typ, Status und Mindest-Sicherheit; Bestätigen, Ablehnen, Zusammenführen; Fundstellen; einfache Graphansicht | Bestätigen/Ablehnen vorhanden (PHP-Seite) |
| Keywords | Search Console, Import mit Spaltenzuordnung, Textfocus mit Credit-Anzeige, Quelle je Kennzahl, Ausschlüsse | neu (WP-CLI vorhanden) |
| Themen | Vorschläge mit Priorität, Begründung, geprüften Tatsachen; Freigabe; Einzel- und Sammelauftrag | neu |
| Abläufe | Status, Schritte, Kosten, Fehler, Fortsetzen/Abbrechen | neu |
| Style Mimic | Eingaben, Profil, Versionen | neu |
| Einstellungen | KI, Budget, Search Console, Textfocus, Grenzen, Längen je Beitragstyp, Gewichte | neu (WP-CLI vorhanden) |

React über `@wordpress/scripts` und `@wordpress/components`, Daten über `lsec/v1`. Die vorhandene PHP-Seite „Entitäten“ wird in Stufe B ersetzt.

---

## 10. Qualitätsprüfung

### 10.1 Regelbasierte Prüfungen (ohne KI)

| Prüfung | Blockierend |
|---|---|
| Länge innerhalb der Grenzen des Beitragstyps | nein (Warnung) |
| Gültiges Block-Markup | ja |
| Jede verwendete Aussage (`used_claim_ids`) hat Status `verified` oder `confirmed` | ja (`UNSUPPORTED_FACT`) |
| `unsupported_statements` ist leer | ja (`UNSUPPORTED_FACT`) |
| Zahlen, Jahreszahlen und Preise im Text kommen in einer verwendeten Aussage oder im Unternehmensprofil vor | ja (`UNSUPPORTED_FACT`) |
| Haupt-Entität ist `confirmed` oder `probable` | ja |
| Interne Links zeigen auf veröffentlichte Inhalte | ja |
| Keine Überschneidung über dem Schwellenwert mit bestehendem Beitrag | ja |
| Kein bestehender Beitrag mit sehr ähnlichem Titel (**Vorschlag:** ab 0,85) | ja |
| Lokale Beiträge: Ort genannt und mit mindestens zwei geprüften lokalen Aussagen verbunden | ja |
| Lokale Beiträge: Text unterscheidet sich ausreichend von Beiträgen desselben Typs für andere Orte | ja |
| Keine Orte außerhalb des Servicegebiets als Einsatzort | ja |
| Keine Platzhalter oder Übersetzungsfehler aus einer Fachbegriffsliste | ja |
| SEO-Titel und Meta-Beschreibung vorhanden und in der Länge (**Vorschlag:** 30–60 bzw. 120–160 Zeichen) | nein (Warnung) |
| Fokus-Keyword nicht schon Haupt-Keyword eines anderen Beitrags | nein (Warnung) |
| Wiederholte Absätze oder Floskeln über einem Schwellenwert | nein (Warnung) |

Die Zahlenprüfung vergleicht Ziffernfolgen und ausgeschriebene Jahreszahlen; Telefonnummer, Hausnummer und Postleitzahl aus dem Unternehmensprofil gelten als belegt.

### 10.2 KI-Bewertung

Ergänzend bewertet die KI Suchintention, lokale Relevanz, Entity-Integration, Mehrwert, Lesbarkeit und Übereinstimmung mit dem Style Mimic (8.8). Sie meldet zusätzlich Aussagen, die wie Tatsachen klingen, aber keiner Aussage zugeordnet sind.

**Vorschlag Mindestwerte:** kein Einzelwert unter 0,6, Durchschnitt mindestens 0,75. Kalibrierung in der Testphase anhand der Korrekturen des Administrators.

---

## 11. Sicherheit

- **Berechtigung:** Capability `lsec_manage` (Rolle Administrator). Alle REST-Routen prüfen sie in `permission_callback` (geprüft in M15: 403 für Besucher und Abonnenten); Formulare zusätzlich mit Nonces.
- **API-Schlüssel:** Konstanten in `wp-config.php` (`LSEC_OPENROUTER_API_KEY`, `LSEC_ANTHROPIC_API_KEY`, `LSEC_OPENAI_API_KEY`, `LSEC_TEXTFOCUS_API_KEY`) haben Vorrang. Im Backend eingegebene Schlüssel werden mit libsodium verschlüsselt (Schlüssel aus den WordPress-Salts) und nur maskiert angezeigt. `wp lsec status` nennt nur die Quelle eines Schlüssels.
- **Dienstkonto der Search Console:** Schlüsseldatei außerhalb des Webroots. Reihenfolge: Konstante `LSEC_GSC_CREDENTIALS_FILE`, Einstellung `gsc_credentials_file`, sonst `private/gsc-service-account.json` neben dem Webroot (Plesk-Ordner `private`, Rechte 700 für den Ordner und 600 für die Datei, Besitzer = Benutzer des Abonnements). Anmeldung über ein mit RS256 signiertes JWT, Bereich `webmasters.readonly`; das Zugriffstoken bleibt im Speicher. Die Schlüsseldatei wird nie über das Backend hochgeladen oder angezeigt.
- **Schwärzung:** Protokolle, Fehlermeldungen und Exporte schwärzen bekannte Geheimnisse und Muster: private Schlüssel, Google-Token (`ya29.…`), `key=`-Parameter, `X-API-Key`, Bearer-Token. Antworten von Textfocus werden ohne `params` gespeichert.
- **Prüfprotokoll:** Entscheidungen über Entitäten, Aussagen, Themen und Ausschlüsse sowie Änderungen an Budget und Schlüsselquellen werden mit der Stufe `audit` protokolliert, unabhängig von `WP_DEBUG`.
- **Externe Daten:** KI-Antworten, Importe, Suchergebnisse und abgerufene Seiten gelten als nicht vertrauenswürdig: validieren, bereinigen (`sanitize_*`, `wp_kses_post`), Datenbankzugriffe nur über `$wpdb->prepare()`.
- **Abruf fremder Seiten (8.4a):** nur `wp_safe_remote_get` (keine internen Adressen), Größen- und Zeitlimit, keine Cookies, keine Ausführung von Skripten.
- **Prompt-Injection:** Fremde Texte werden in KI-Anfragen als Daten gekennzeichnet. Aktionen mit Außenwirkung hängen nie allein von einer KI-Antwort ab.
- **Datenschutz:** An KI-Anbieter gehen nur aufgabennötige Inhalte, keine Benutzer- oder Kommentardaten. Die E-Mail-Adresse des Administrators geht an keinen Dienst.
- **Server:** WP-CLI als Benutzer des Abonnements, nicht als root.

---

## 12. Machbarkeitstest: Ergebnis

Durchgeführt vom 18.10.2026 in CI und auf Staging (Plesk, PHP 8.4.26, WordPress 7.1.3, Rank Math 1.0.280, Elementor), Versionen 0.1.0 bis 0.2.4. Einzelheiten und Erkenntnisse in `PROJEKTPLAN.md`.

| Nr. | Prüfpunkt | Ergebnis |
|---|---|---|
| M1 | Kompatibilität | bestanden (PHP 8.1/8.3/8.4, WordPress 6.4.5/7.1.3, mit Rank Math und Elementor) |
| M2–M5 | Datenbank, Cronjob, Wiederaufnahme, Doppelschutz | bestanden, auch auf Staging |
| M6 | Wiederholungen | bestanden; echter 429 von Wikidata, echtes Ausgabenlimit von Anthropic |
| M7 | KI-Anbindung | bestanden (OpenRouter, $0,0036 je Aufruf) |
| M7a | Recherche mit Websuche | bestanden (5 Quellen), Kosten $0,148 → 8.1b |
| M7b | Adapterwechsel | bestanden mit OpenAI; Anthropic bis zur Kontogrenze |
| M8 | Geheimnisse | bestanden im Code und in Tests; **Restprüfung:** Suche nach den Schlüsseln in der Staging-Datenbank (Stufe A) |
| M9 | Search Console | bestanden (96 Zeilen, 79 Suchanfragen) |
| M10 | CSV-/Excel-Import | bestanden mit echten Exporten |
| M11 | Entity-Erkennung | bestanden (9 Kandidaten, 11 Fundstellen, $0,04) |
| M12–M15 | Entwurf, Rank Math, Elementor, REST | bestanden |
| M16 | Textfocus-API | bestanden (Volumina im Tarif enthalten, Zwischenspeicher ohne Credit) |
| M17 | Tippfehlerprüfung | bestanden |
| M18 | Ortsabgleich | bestanden (Burgdorf, Praxistest Hemmingen) |

Die Prüfskripte bleiben Teil der CI und werden in MVP 1 um die neuen Abläufe erweitert.

---

## 13. Entscheidungsstand

| Entscheidung | Status |
|---|---|
| Eigenständiges, modulares WordPress-Plugin | festgelegt |
| Inhalte als reguläre Beiträge mit Gutenberg-Blöcken | festgelegt, geprüft (M12) |
| Eigene Tabellen, Schema in Abschnitt 4 | festgelegt; Schemaversion 3 für MVP 1 |
| PHP ≥ 8.1, WordPress ≥ 6.4 | festgelegt |
| Action Scheduler mit `wp lsec cron` | festgelegt, geprüft (M3–M5) |
| Keyword-Quellen im MVP: Search Console, Import, Textfocus, KI-Websuche | festgelegt, geprüft |
| Search Console über Dienstkonto | festgelegt, geprüft (M9) |
| Textfocus-Volumina im Tarif | geklärt: enthalten (M16) |
| Rank Math über Metafelder, Erkennung über `rank_math/loaded` | festgelegt, geprüft (M13) |
| KI-Anbieter OpenRouter, Anthropic, OpenAI | festgelegt, alle drei Adapter umgesetzt |
| Ortsabgleich über Wikidata und Textfocus-Entitäten im MVP | festgelegt (vorgezogen) |
| Faktenprüfung gegen den Quelltext (8.4a) | festgelegt |
| Filter für Wettbewerber und fremde Orte (6.4) | festgelegt |
| Zusammenführen von Entitäten, Mindest-Sicherheit | festgelegt; Schwellenwert Vorschlag 0,6 |
| Konkretes Modell | **Entscheidung offen** – Vorschlag: Start mit `anthropic/claude-sonnet-5.5` über OpenRouter, Festlegung nach Vergleich in Stufe E |
| KI-Budget im Plugin | **Entscheidung offen** – Vorschlag: 20 USD/Monat, 1 USD je Ablauf |
| Treffer der Websuche | **Entscheidung offen** – Vorschlag: 3, Messung in Stufe D |
| Eigenes Modell für die Recherche | **Entscheidung offen** – Vorschlag: nicht in MVP 1 (würde Anforderung 14 ändern) |
| KI-Sichtbarkeit (Screpy, optional Indexly) | **Entscheidung offen** – betrifft erst Ausbau 3 |
| Umfang und Dauer der Testphase | **Entscheidung offen** – Vorschlag: 20 geprüfte Beiträge in 60 Tagen |
| Mindestwerte der Qualitätsprüfung | Vorschlag, Kalibrierung in der Testphase |
| Bilddienst | offen (Ausbau 2) |
| Google Trends, DataForSEO | vorgesehen für Ausbau 2 |
| Screpy | vorgesehen für Ausbau 3 |

---

## 14. Umsetzungsplan MVP 1

Jede Stufe endet mit einer Version, einem Lauf der Prüfskripte in CI und auf Staging und einem Eintrag in `PROJEKTPLAN.md`. Jede Fähigkeit bekommt zuerst einen WP-CLI-Befehl, dann die Oberfläche.

| Stufe | Inhalt | Abnahme | Version |
|---|---|---|---|
| A – Grundlage | Schemaversion 3; Einstellungen für Budget, Websuche, Mindest-Sicherheit; KI-Budget mit `waiting`; Restprüfung M8; React-Grundgerüst mit Dashboard und Einstellungen | Migration 2 → 3 ohne Datenverlust; Budget hält einen Ablauf an und gibt ihn nach Erhöhung frei; Dashboard zeigt Kosten, Credits, Cron | 0.3.0 |
| B – Entitäten | Unternehmensprofil mit Servicegebiet; Inhaltsindex als Ablauf; Entity-Erkennung als Ablauf mit Ortsabgleich; schärfere Anweisung; Zusammenführen mit Vorschlägen; Entitäten-Seite in React | Index der Testwebsite vollständig; vier Schreibweisen des Firmennamens werden als Zusammenführung vorgeschlagen und zusammengeführt; Hemmingen wird `probable` | 0.4.0 |
| C – Keywords | `keyword_sync` täglich; Import im Backend mit Spaltenzuordnung; Textfocus-Volumina im Ablauf; `competition`; Filter (6.4); Keywords-Seite | Search Console läuft täglich ohne Eingriff; `tf_related`-Ergebnisse für „flächenreinigung“ erhalten Ausschlussgründe für Wettbewerber und Hamburg | 0.5.0 |
| D – Themen | `topic_research` mit Websuche, Faktenprüfung, Priorisierung und Überschneidung; Themen-Seite mit Freigabe; Messung der Websuche mit 3 und 5 Treffern | Eltze/Dollbergen-Fall: die falsch zugeordnete Jahreszahl wird nicht `verified`; eine Anfrage, für die eine passende Seite rankt, erscheint als Überschneidung | 0.6.0 |
| E – Produktion | Briefing, Style Mimic, Entwurf, Qualitätsprüfung, `content_production` vollständig; Modellvergleich mit drei Beispielbeiträgen | Drei Entwürfe zu freigegebenen Themen entstehen über den Cronjob; ein Entwurf mit unbelegter Zahl wird `blocked`; Kosten je Beitrag gemessen | 0.7.0 |
| F – Abschluss | Abläufe-Seite, Content- und Entity-Bericht, Sammelauftrag, Hinweise „bestehende Seite verbessern“; Integrationstests; Dokumentation | Abnahme MVP 1 durch Thomas auf Staging; danach Beginn der Testphase | 1.0.0 |

**Vor Stufe A zu entscheiden:** KI-Budget. **Vor Stufe D:** Treffer der Websuche und eigenes Recherchemodell. **Vor Stufe E:** Modell. **Vor der Testphase:** Umfang und Dauer. KI-Sichtbarkeit erst vor Ausbau 3.
