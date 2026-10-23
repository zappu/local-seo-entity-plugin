# WordPress Local SEO & Entity Content Plugin
## Technische Spezifikation v1.0

**Status:** Verbindliche Grundlage für MVP 1, Stand 18.10.2026. Noch offen: konkrete Modelle (vor Stufe D bzw. E) und KI-Sichtbarkeit (vor Ausbau 3), siehe Abschnitt 13.  
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
| Datenbank | Schemaversion 3 für MVP 1: Wettbewerbswert, Ausschlussgrund bei Keywords, Zusammenführen von Entitäten; Schemaversion 4 (Stufe C): Ausschlussdetail, Entscheidung von Hand und Analyse je Keyword; Schemaversion 5 (Stufe D): Text abgerufener Quellen, Ablauf und Entscheidung je Thema | 4 |
| Keyword-Quellen | Nachträge aus dem Test in den Haupttext übernommen; Filter für Wettbewerber und fremde Orte; Search Console als Signal für Sichtbarkeit ohne Klicks | 6 |
| KI | Kostensteuerung (Budget 20 $/Monat und 1 $/Ablauf, 3 Treffer der Websuche, eigenes Recherchemodell), Faktenprüfung gegen den Quelltext, Mindest-Sicherheit und Zusammenführen bei der Entity-Erkennung | 8 |
| Qualitätsprüfung | `UNSUPPORTED_FACT` stützt sich auf die Faktenprüfung | 10 |
| Sicherheit | Dienstkonto-Schlüssel, Prüfprotokoll, Abruf fremder Seiten | 11 |
| Machbarkeitstest | Ergebnisse zusammengefasst | 12 |
| Umsetzung | Plan für MVP 1 in Stufen mit Abnahmekriterien | 14 |

Werte mit **Vorschlag** sind Ausgangswerte und werden während MVP 1 oder in der Testphase bestätigt. Punkte mit **Entscheidung offen** entscheidet Thomas vor Beginn der betroffenen Stufe. Am 18.10.2026 entschieden: KI-Budget, Treffer der Websuche, eigenes Recherchemodell, Umfang der Testphase.

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
| Backend-Oberfläche | React über `@wordpress/scripts` (36.0.0), Quellen in `src-js/`, gebaut nach `assets/dist/` (nicht im Repo, kommt in die ZIP). Nachtrag 18.10.: JSX je Datei klassisch übersetzt (`/** @jsxRuntime classic */`, `createElement` aus `@wordpress/element`), weil die automatische Übersetzung das Skript `react-jsx-runtime` verlangt, das erst WordPress 6.6 mitbringt. Geprüft mit WordPress 6.4.5 und 7.1.3 |
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
├── src-js/                            Backend-Oberfläche (React), Quellen
├── assets/dist/                       gebaute Oberfläche (nur in der ZIP)
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

**Umsetzung Stufe B (Nachtrag 18.10.):** Seite „Local SEO → Unternehmen“, REST `GET|POST lsec/v1/profile`, `wp lsec profile show|save`. Standorte sind Entitäten vom Typ `place` mit `properties.kind = location`; Straße, PLZ, Ort, Telefon und Öffnungszeiten sind bestätigte Aussagen. Beziehungen vom Unternehmen aus: `located_in` (Standort), `offers` (Leistung, fremde Marke), `owns_brand` (eigene Marke), `competes_with` (Wettbewerber), `serves_area` (Servicegebiet als Entität vom Typ `service_area` mit Wikidata-ID). Gibt es schon eine Entität gleichen Namens (z. B. von der KI erkannt), wird sie übernommen und bestätigt. Einträge, die aus dem Profil verschwinden, werden abgelehnt, nicht gelöscht. Das Servicegebiet wird über eine Wikidata-Suche gewählt (`GET lsec/v1/wikidata/search`, `POST lsec/v1/service-area`).

**Angaben aus Webseiten (Nachtrag 22.10., 0.4.1, Wunsch von Thomas nach dem Vorbild des Einrichtungsassistenten im KI News Publisher):** Auf der Seite „Unternehmen“ ein Textfeld für bis zu acht Adressen (eine pro Zeile, auch Pfade wie `/impressum`; leer = Startseite), gespeichert in `profile_source_urls`. „Angaben lesen“ liest die Seiten (eigene Inhalte aus der Datenbank, auch Elementor und ohne sprechende Permalinks über den Pfad; sonst Abruf mit Browser-Kennung; zusammen höchstens 24.000 Zeichen, je Seite 3.000 bis 10.000) und lässt die KI in einem Aufruf (Aufgabe `profile_extraction`, JSON-Schema) Unternehmen, Standorte, Leistungen, Marken, Wettbewerber und Servicegebiet vorschlagen. Prüfung gegen den Text wie bei den Fundstellen: Firmenname, E-Mail, Straße, Ort, Leistungs- und Markennamen müssen wörtlich vorkommen, Telefonnummern nach Ziffern (auch +49 statt 0); ein Standort braucht eine Straße aus dem Text. Verworfenes wird angezeigt. Der Vorschlag wird nicht gespeichert: Der Administrator wählt per Häkchen, was ins Formular übernommen wird (leere Felder und neue Einträge sind vorausgewählt, vorhandene Werte werden nur auf Wunsch ersetzt), und speichert dann das Profil. Das vorgeschlagene Servicegebiet lässt sich direkt bei Wikidata suchen. REST `POST lsec/v1/profile/analyze`, WP-CLI `wp lsec profile suggest [<url>…]`.

### 4.3 Keywords und Themen

**`lsec_keywords`**: id, keyword, normalized (INDEX), locale (`de_DE`, `de_AT`, `de_CH`), region (NULL oder Ort/PLZ), intent (`informational`, `commercial`, `transactional`, `navigational`, `local`), ab v3 `exclusion` (NULL, `competitor`, `brand`, `foreign_place`, `off_topic`, `manual`), first_seen_at, updated_at; UNIQUE (normalized, locale, region)

**Schemaversion 4 (Nachtrag 22.10., Stufe C)** in `lsec_keywords`:
- `exclusion_detail`: der Name oder Ort, der zum Ausschluss führte.
- `exclusion_by`: der Benutzer, wenn der Administrator entschieden hat. Der automatische Filter lässt solche Keywords dann unberührt.
- `analysis`: von der KI erkannte Orte und Namen als JSON.
- `analyzed_at`.

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

**Umsetzung Stufe B (Nachtrag 18.10.):** Post-Meta `_lsec_index` (Hash, Titel, Wörter, Quelle, Überschriften, Kategorien, Fokus-Keyword, Zeitpunkt), `_lsec_index_dirty` (von `save_post` gesetzt) und `_lsec_extracted_hash` (Inhalt, für den die Entity-Erkennung zuletzt lief). Der Ablauf `content_index` läuft in Durchgängen zu 20 Beiträgen (`StepResult::more`, 7.4). Die Erkennung startet für Inhalte ab 50 Wörtern, deren `_lsec_extracted_hash` nicht zum aktuellen Hash passt, wenn `entity_auto_extract` an ist (Standard). Der Cronjob prüft höchstens stündlich auf neue oder geänderte Inhalte, aber erst nachdem der Administrator den Index einmal selbst gestartet hat; so beginnt die erste Erkennung mit KI-Kosten nicht unbemerkt. `wp lsec index run [--all] [--now] [--ids=…]`, `status`, `show <id>`; im Dashboard Karte „Inhalte und Entitäten“ mit „Index starten“. Beim Löschen eines Beitrags werden seine Fundstellen entfernt.

**Nachtrag 22.10. (0.4.2): Produkte, Kategorieseiten, Auswahl der Erkennung.**
- **Was gelesen wird:** Beiträge, Seiten, mit WooCommerce auch Produkte (`product`, Kurzbeschreibung vor der Beschreibung) und Kategorieseiten (`category`, `product_cat`: Name als Titel, Kategoriebeschreibung als Text, Rank-Math-Fokus-Keyword aus Term-Meta). Filter `lsec_index_post_types` und neu `lsec_index_taxonomies`.
- **Ausnahmen:** Ausgenommen sind die Standardkategorien (`default_category`, `default_product_cat`) und die WooCommerce-Seiten Warenkorb, Kasse und Kundenkonto. Nach diesen Seiten fragt niemand, und auf sie soll nicht verlinkt werden.
- **Speicherort:** Kategorien tragen dieselben Meta-Schlüssel als Term-Meta. Sie werden über `created_term` und `edited_term` vorgemerkt; `delete_term` entfernt ihre Fundstellen (`lsec_mentions.object_type = term`).
- **Kennungen:** In Warteschlangen und Befehlen heißen Inhalte `post:12` und `term:34`; eine reine Zahl ist ein Beitrag (`Lsec\Content\IndexItem`).
- **Gelesen werden immer alle Inhalte.** Das kostet nichts und ist die Grundlage für die Dublettenprüfung, die internen Links und die Keyword-Zuordnung.
- **Erkennung nur für ausgewählte Arten:** Die KI-Erkennung läuft automatisch nur für die Arten aus der neuen Einstellung `entity_extract_types`. Standard: Seiten, Produkte, Beitrags- und Produktkategorien; Blogbeiträge sind nicht dabei. Begründung: Für das Unternehmen bringen vor allem Leistungsseiten, Produkte und Kategorien etwas. Blogbeiträge liefern meist Randbegriffe und würden auf der Testwebsite etwa die Hälfte des Monatsbudgets kosten. Einzelne Beiträge lassen sich von Hand erkennen (`wp lsec entities extract <id|term:id> [--queue]`); das setzt jetzt auch `_lsec_extracted_hash`.
- **Dashboard:**
  - Die Karte zeigt den Stand je Art, welche Arten erkannt werden und wie viele Erkennungen fällig sind.
  - „Index starten“ liest immer alle Inhalte, damit angehaltene oder durch eine geänderte Auswahl fehlende Erkennungen nachgeholt werden. Vorher fragt es mit einer Kostenschätzung nach (`ContentIndex::extraction_due()`).
  - „Anhalten“ bricht den Indexlauf und alle offenen Erkennungen ab.
- **Abbrechen und Wiederaufnehmen:**
  - Neu: `POST lsec/v1/index/stop`, `wp lsec index stop` und `wp lsec runs cancel --open [--type=…]`. `wp lsec runs list` zeigt das Objekt (`post:12`, `term:34`).
  - Ein abgebrochener Ablauf wird wieder aufgenommen, wenn er mit gleichem Idempotenz-Schlüssel erneut gestartet wird (`RunRepository::reopen_cancelled`, 7.2). Erledigte Schritte bleiben dabei erledigt.
  - Endet ein Schritt erst nach dem Abbruch, speichert die Engine sein Ergebnis, setzt den Ablauf aber nicht fort.

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
- **Umsetzung (Nachtrag 22.10., Stufe C):**
  - Upload über `POST lsec/v1/keywords/import/preview` (CSV, TXT oder XLSX, höchstens 10 MB).
  - Die Datei liegt bis zum Import unter `uploads/lsec-import` mit zufälligem Namen und einem Sperrvermerk für Apache. Nach dem Import wird sie gelöscht, spätestens nach einem Tag.
  - Die Vorschau zeigt Kopfzeile, fünf Zeilen, die erkannte Vorlage und die Zuordnung. Jedes Feld lässt sich einer Spalte zuordnen oder weglassen (`ImportProfile::with_mapping`).
  - Der Import selbst läuft über `POST lsec/v1/keywords/import` und startet danach einen Abgleich ohne Search Console, damit Orte, Namen und Ausschlüsse geprüft werden.

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

**Umsetzung (Nachtrag 22.10., Stufe C):**
- **Erkennung von Orten und Namen:** Firmen- und Ortsnamen in Keywords lassen sich ohne Ortsverzeichnis nicht zuverlässig finden. Deshalb prüft die KI neue Keywords in Stapeln zu 100 (Aufgabe `keyword_analysis`, `KeywordAnalyzer`, Einstellung `keyword_analysis`, Standard an). Sie nennt Orte und Eigennamen. Erhalten bleibt nur, was wörtlich im Keyword steht.
- **Regeln** (`KeywordFilter`, ganze Wörter, Umlaute vereinheitlicht), in dieser Reihenfolge:
  - **`competitor`:** Name oder Schreibweise einer Entität vom Typ `competitor`.
  - **`brand`:**
    - ein von der KI erkannter Name, der nicht zur eigenen Firma gehört. Eigen sind Unternehmen und Marken aus dem Profil mit ihren Schreibweisen, also auch angebotene Marken.
    - oder eine bestätigte fremde Firma oder Marke unter den Entitäten.
  - **`foreign_place`:** ein erkannter Ort außerhalb des Servicegebiets. Zuerst gelten bekannte Orts-Entitäten: abgelehnt heißt außerhalb, bestätigt oder wahrscheinlich heißt im Gebiet. Sonst entscheidet der Ortsabgleich über Wikidata (8.3, mit übergeordneten Gebieten). Ohne Treffer bei Wikidata wird nichts ausgeschlossen.
  - `off_topic` folgt mit der Themenrecherche (Stufe D).
- **Entscheidungen von Hand** (`exclusion_by`):
  - „Ausschließen“ setzt `manual`.
  - „Aufnehmen“ hebt jeden Ausschluss auf, auch gegen den Filter.
  - „Filter entscheiden lassen“ gibt das Keyword an den Filter zurück.
  - Zugang: REST `POST lsec/v1/keywords/<id>/exclusion`, `wp lsec keywords decide manual|include|auto <id>…`.

**Priorität.** Jeder Einzelwert steht in `score_breakdown` und wird im Backend angezeigt.

| Faktor | Herkunft | Gewicht (Vorschlag) |
|---|---|---|
| Geschäftsrelevanz | Bezug zu bestätigten Leistungen und Standorten | 30 % |
| Nachfragesignal | Suchvolumen (Textfocus, Import) oder Impressionen (Search Console), normalisiert | 25 % |
| Inhaltslücke | Thema bzw. Entität bisher nicht abgedeckt | 20 % |
| Lokaler Bezug | Ort im Servicegebiet, regionale Besonderheit, Anfragen mit „in der Nähe“ | 15 % |
| Saisonalität | Zeitpunkt passend zur Saison (nur aus Quellen mit Monatsangabe) | 10 % |

Fehlt ein Faktor, zählt er nicht als 0; die übrigen Gewichte werden hochgerechnet, und das Thema erhält den Hinweis „eingeschränkte Datenbasis“. Gewichte sind einstellbar. Wettbewerb (`competition`) fließt nicht in die Priorität ein, sondern wird angezeigt.

**Umsetzung (Nachtrag 22.10., Stufe D, `PriorityScorer`):** Jeder Faktor liegt zwischen 0 und 1.
- **Geschäftsrelevanz:**
  - 1, wenn eine Haupt-Entität eine Leistung aus dem Profil ist;
  - 0,8, wenn Titel oder Haupt-Keyword eine Profil-Leistung nennen;
  - 0,6 bei einer Profil-Leistung unter den weiteren Entitäten;
  - sonst 0,3.
- **Nachfrage:** Summe der Suchvolumina der Keywords des Themas, logarithmisch auf das größte Thema derselben Recherche bezogen. Ohne Volumen zählen stattdessen die Impressionen.
- **Inhaltslücke:**
  - 1 ohne Überschneidung;
  - 0,5, wenn nur ein weiteres Keyword mit eigener Seite rankt;
  - 0 bei Überschneidung mit dem Haupt-Keyword.
- **Lokaler Bezug:**
  - Ort im Servicegebiet: 1. Ort ungeklärt: 0,5. Ort außerhalb: 0.
  - Ohne Ort: „in der Nähe“-Anfragen 0,7, geprüfte Tatsachen zu Orten 0,4, sonst 0.
- **Saisonalität:** fehlt, bis eine Quelle Monatswerte liefert.
- **Gewichte:** Einstellung `topic_weights` (JSON). Leer gilt der Vorschlag oben.
- **Hinweis „eingeschränkte Datenbasis“:** erscheint nur, wenn neben der Saisonalität ein weiterer Faktor fehlt. Die Saisonalität fehlt fast immer, der Hinweis stünde sonst bei jedem Thema.
- **Überschneidung** (`TopicResearchWorkflow::overlap`):
  - Gezählt werden eigene Seiten, die laut Search Console für ein Keyword des Themas ranken. Eigen sind Seiten auf dem Host der Website oder der Property; so funktioniert das auch auf einer Staging-Kopie.
  - Ebenso zählen Inhalte, deren Titel alle Wörter eines Haupt-Keywords enthält.
  - Trifft das auf ein Haupt-Keyword zu, gilt die Überschneidung als hoch: Das Thema erhält den Status `discarded` und erscheint unter „bestehende Seite verbessern“ mit der Seite und dem Grund.
  - **Nachtrag 22.10. (0.6.1), nach den ersten echten Recherchen auf der Testwebsite:** Fast alle Themen wurden verworfen, weil eine allgemeine Seite (z. B. `/pflasterreinigung/`) für ein allgemeines Haupt-Keyword rankt und damit auch „Pflasterreinigung in Lehrte“ als abgedeckt galt. Das widerspricht Regel 1 unten („wenn die Seite zum Thema passt“). Deshalb zählt eine Seite nur noch als hohe Überschneidung, wenn sie **zum Thema passt** (`TopicResearchWorkflow::fits`):
    - Titel oder Pfad der Seite nennen die Leistung des Keywords (ein Wort ab fünf Buchstaben, verglichen über die ersten acht Buchstaben, Bindestriche im Pfad als Leerzeichen).
    - Hat das Thema einen Ort, nennen Titel oder Pfad auch diesen Ort. Allgemeine Wörter wie „Region“, „Stadt“, „Landkreis“ zählen nicht.
    - Den Titel einer rankenden Seite liefert der Inhaltsindex über den Pfad.
  - Rankt nur eine allgemeinere Seite, ist die Überschneidung teilweise (`partial`, Inhaltslücke 0,5); das Thema bleibt ein Vorschlag, und die Seite steht als „Allgemeinere Seite rankt für …“ dabei (Kandidat für einen internen Link).
  - Die Titel-Regel verlangt bei einem Thema mit Ort ebenfalls den Ort im Titel.

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
7. **Wettbewerb (Nachtrag 18.10., Stufe A).** Spalten „Competition“, „Konkurrenz“, „Wettbewerb“ gehen in `competition`, nicht mehr in `difficulty`.

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

**Umsetzung (Nachtrag 18.10., Stufe A):** Ein Schritt wirft `WaitException` mit einem Grund (`ai_budget`). Der Ablauf geht auf `waiting`, `error_code` ist der Grund, der Schritt bleibt `pending`, und der Versuch zählt nicht. `wp lsec cron` prüft je Grund, ob es weitergehen kann (KI-Budget: `AiBudget::allows()`), und plant den Ablauf dann neu ein. Von Hand: `wp lsec runs resume <id>` oder „Fortsetzen“ im Dashboard; `wp lsec runs cancel <id>` bzw. „Abbrechen“. Das Textfocus-Budget wird in Stufe C auf denselben Weg umgestellt.

**Nachtrag 22.10. (0.4.2):** `cancelled` ist nicht mehr endgültig, wenn derselbe Ablauf (gleicher Idempotenz-Schlüssel) erneut gestartet wird. Er geht dann zurück auf `queued`, übersprungene Schritte laufen erneut, erledigte nicht; so wird eine bezahlte Erkennung nicht doppelt bezahlt. Beim Abbruch während eines Schritts speichert die Engine das Ergebnis des Schritts, belebt den Ablauf aber nicht wieder.

### 7.3 Abläufe in MVP 1

**`content_production`**

| Nr. | Schritt | Inhalt | KI |
|---|---|---|---|
| 1 | `load_topic` | Thema laden, `approved` prüfen, auf `in_production` setzen | – |
| 2 | `gather_context` | Entitäten (`confirmed`, `probable`), Beziehungen, Aussagen mit Status `verified` oder `confirmed` und Belegen, Keywords, überschneidende Beiträge, Linkziele | – |
| 2a | `place_facts` | nur lokale Seiten mit weniger als zwei Ortsfakten: Tatsachen zum Ort recherchieren und prüfen (Nachtrag 23.10.2026, 0.7.13) | ja |
| 3 | `create_brief` | Briefing (8.5) | ja |
| 4 | `generate_draft` | Text als Zwischenstruktur mit Style Mimic (8.6) | ja |
| 5 | `enrich` | interne Links prüfen, Kategorien, Schlagwörter, Rank-Math-Angaben, CTA | teilweise |
| 6 | `quality_check` | Regelprüfungen und KI-Bewertung (10) | ja |
| 6a | `revise` | nur bei ausschließlich behebbaren Mängeln: beanstandete Stellen ersetzen, einmal neu prüfen (Nachtrag 23.10.2026, 0.7.13) | ja |
| 7 | `create_post` | Entwurf anlegen bzw. aktualisieren | – |
| 8 | `finalize` | Thema auf `produced`, Fundstellen, Bericht | – |

Bei blockierenden Mängeln entsteht trotzdem ein Entwurf mit sichtbarem Prüfbericht und Status `blocked`. Er kommt für eine spätere automatische Veröffentlichung nie in Frage.

**Umsetzung (Nachtrag 22.10., Stufe E, `ContentProductionWorkflow`, 0.7.0):**
- **Start:**
  - Von Hand: „Beitrag schreiben“ auf der Themen-Seite, `POST lsec/v1/topics/<id>/produce` oder `wp lsec production start <thema> [--model=…] [--again] [--now]`.
  - Cronjob: `wp lsec cron` startet Entwürfe zu freigegebenen Themen nach Priorität, wenn `production_auto` an ist (Standard), höchstens `drafts_per_day` am Tag (Standard 3, Tag in der Zeitzone der Website; von Hand gestartete zählen mit). Ohne Cronjob: `wp lsec production schedule`.
  - Idempotenz: ein Ablauf je Thema. `--again` bzw. „neu schreiben“ und `--model` erzeugen einen neuen Ablauf und einen neuen Entwurf; der alte Entwurf bleibt.
  - Autor: Einstellung `draft_author`, sonst wer das Thema freigegeben hat, sonst der erste Administrator.
- **`load_topic`:** nur `approved` (oder `in_production` beim Fortsetzen), setzt `in_production`.
- **`gather_context`:**
  - Profil mit Standorten und Telefon, Servicegebiet.
  - Entitäten des Themas (mit Status und Rolle), Leistungen aus dem Profil, die Orts-Entität des Themas.
  - Aussagen mit Status `verified` oder `confirmed`: die des Themas zuerst, dann weitere zu diesen Entitäten (höchstens 40), mit Typ der Entität, Zitat und Quelle (`ClaimRepository::usable`).
  - Keywords des Themas, bis zu 12 Linkziele aus dem Inhaltsindex (veröffentlichte Inhalte nach gemeinsamen Wörtern mit Titel, Ort und Keywords), bis zu 60 Kategorien, aktiver Style Mimic, Länge des Beitragstyps (`draft_lengths`, Standard: lokale Seite 700–1300, Beitrag 900–1600, Ratgeber 1200–2200, saisonal 700–1300, sonst 700–1500 Wörter).
- **`create_brief`** (8.5): KI mit JSON-Schema; unbekannte Aussagen, Entitäten und Linkziele werden entfernt und im Bericht genannt; die Länge wird auf den Rahmen des Beitragstyps begrenzt. Gespeichert in `lsec_briefs` mit Version je Thema.
- **`generate_draft`** (8.6): KI mit JSON-Schema (bis 14.000 Token, 300 s). Jeder Block hat alle Felder (striktes Schema), das Plugin behält je Typ nur die passenden. Unbekannte Aussagen in `used_claim_ids` bleiben stehen, damit die Prüfung sie meldet.
- **`enrich`:** ohne KI. Fehlende SEO-Angaben aus Thema, Titel und Auszug; Slug mit `sanitize_title`; fehlt im Text ein Kontaktweg (Telefon, E-Mail oder „Kontakt“), kommt die Handlungsaufforderung des Briefings ans Ende.
- **`quality_check`:** Regeln (10.1) und KI-Bewertung (10.2); der Bericht steht in `lsec_quality_reports` und am Beitrag (`_lsec_quality_report_id`, `_lsec_quality_passed`).
- **`create_post`:** `DraftWriter` (9.1, 9.2). Gibt es zum Ablauf schon einen Beitrag (`_lsec_run_id`), wird er verwendet. Weitere Metadaten: `_lsec_topic_id`, `_lsec_primary_entities`, `_lsec_used_claims`, `_lsec_brief_id`.
- **`finalize`:** Thema auf `produced` mit `post_id`; Kosten des Ablaufs. Bei blockierenden Mängeln endet der Ablauf mit `blocked` (Code `quality_blocked`), der Entwurf bleibt.
- **Nach dem ersten echten Entwurf (Lauf 121, Nachtrag 22.10., 0.7.1):** `anthropic/claude-sonnet-5.5`, 891 Wörter, 0,172 USD, Bewertung 85,5/100, blockiert mit „ohne Beleg: 60, 89, 22, 101, 72“. Die Zahlen waren der 60-km-Umkreis und eine anders gruppierte Telefonnummer. Geändert:
  - Angaben zum Servicegebiet gelten als belegt; Telefonnummern aus dem Profil werden auch anders gruppiert oder mit +49 erkannt (Ziffernfolge, ohne führende 0).
  - Die KI-Bewertung erhält den Entwurf gegliedert (Überschriften, Listen, Tabellen, FAQ, Links als [Text](Adresse)) und die Linkziele mit dem Hinweis, dass interne Links darauf geprüft sind. Vorher meldete sie interne Ratgeber-Links als unbelegt und erkannte Tabellen und Listen nicht.
  - Auftrag an die KI beim Entwurf: erster Absatz wiederholt nicht den Titel; Kontaktdaten nur einmal in der Handlungsaufforderung; Tatsachen nur, wo sie nützen, keine bloßen Ortslisten; Stilbeispiele nicht wörtlich übernehmen; Abläufe ohne Versprechen zu Dauer, Ergebnis oder Garantie.
- **Nach dem zweiten Entwurf (Lauf 122, Nachtrag 22.10., 0.7.2):** 905 Wörter, 0,185 USD, Bewertung 86,2/100, blockiert mit „ohne Beleg: 89, 22, 101, 72“ und `AI_UNSUPPORTED_FACT` für einen festen Ablauf des Betriebs (Einschätzung vor Ort, Nachkontrolle). Geändert:
  - **Leistungsbeschreibungen:** Die Beschreibungen der Leistungen aus dem Unternehmensprofil gehen als belegte Angaben des Unternehmens in Briefing, Entwurf und Bewertung; Zahlen darin gelten als belegt. Ohne Beschreibung beschreibt der Entwurf Verfahren nur allgemein als Fachwissen, nicht als Ablauf oder Zusage des Unternehmens.
  - **KI-Bewertung:** Allgemeines Fachwissen ist kein Mangel; `UNSUPPORTED_FACT` nur für konkrete unbelegte Angaben (Zahlen, Preise, Dauern, Garantien, Zertifikate, eigene Geräte, Ablaufzusagen, Tatsachen über Orte).
  - **Entwurf:** einheitliche Wir-Form des Unternehmens, jeder interne Link höchstens einmal, keine Zusagen zu Dauer, Ergebnis, Schonung oder Garantie.
  - **Zahlenprüfung:** Telefonnummern auch mit geschützten Leerzeichen und Gedankenstrichen; der Bericht zeigt zu jeder unbelegten Zahl die Textstelle.
  - **Cronjob:** Themen, die übersprungen werden (z. B. Dublette eines geschriebenen Themas), verbrauchen keinen Platz im Tageslimit; `wp lsec production schedule` nennt den Grund.
- **Modellvergleich auf Thema 39 (Läufe 123 und 124, Nachtrag 22.10., 0.7.3):**

  | Lauf | Hauptmodell | Kosten | Wörter | Bewertung | Mehrwert | Lokale Relevanz | Blockiert |
  |---|---|---|---|---|---|---|---|
  | 123 | `anthropic/claude-sonnet-5.5` | 0,195 USD | 852 | 86,5 | 0,80 | 0,85 | nur Zahlen (Prüfungsfehler) |
  | 124 | `openai/gpt-6-luna` | 0,0047 USD | 974 | 86,8 | 0,73 | 0,95 | Zahlen (Prüfungsfehler) und unbelegter Ablauf |

  Beide blockierten wegen „89, 22, 101, 72“: Die Ziffern stehen im Hostnamen der E-Mail-Adresse der Staging-Website. Ziffern in E-Mail-Adressen, Internetadressen und Domainnamen prüft die Zahlenprüfung seit 0.7.3 nicht mehr. Die KI-Bewertung erhält einen Hinweis, dass die Textfassung vereinfacht ist (sie bemängelte die Tabellendarstellung), und Tabellen mit Trennzeile. Der Bericht warnt, wenn KI-Aufrufe ohne Kostenangabe sind. Die Entscheidung über das Hauptmodell folgt, wenn Thomas beide Texte gelesen hat.
- **Lauf 125 (Nachtrag 22.10., 0.7.4):** `anthropic/claude-sonnet-5.5`, 850 Wörter, 0,197 USD, Bewertung 82,5, blockiert mit `AI_UNSUPPORTED_FACT` für einen Ablauf, der dem Leser zugesagt wird („Sie schildern uns …“, „Gemeinsam sehen Sie sich …“), ohne Leistungsbeschreibung im Profil. Die Beispielsätze des Style Mimic standen zum dritten Mal fast wörtlich im Text. Geändert:
  - Briefing plant einen Abschnitt „So arbeiten wir“ nur mit Angabe des Unternehmens zur Leistung, sonst einen Abschnitt zum Verfahren allgemein.
  - Entwurf: keine Ablaufzusagen an den Leser und keine Behauptungen über Erfahrung oder Ortskenntnis ohne Beleg; Keywords natürlich (Titel, Einleitung, eine Zwischenüberschrift); Servicegebiet und Standort höchstens einmal.
  - Briefing und Entwurf erhalten den Style Mimic ohne Beispielsätze; die Bewertung erhält ihn mit Beispielen und prüft, dass sie nicht wörtlich übernommen sind.
  - Der Bericht nennt, zu welchen Leistungen eine Beschreibung im Profil vorlag.
- **0.7.5:** Die Beschreibungen der Leistungen im Profil waren nur Aufzählungen („Gehwege, Plätze und Pflasterflächen …“), das Feld war einzeilig und schmal; Thomas trug den Ablauf deshalb in die Unternehmensbeschreibung ein, die bisher nicht an die KI ging. Geändert: Die Unternehmensbeschreibung geht als Angabe des Unternehmens in Briefing, Entwurf, Bewertung und Zahlenprüfung. Auf der Seite „Unternehmen“ ist die Beschreibung je Leistung ein breites mehrzeiliges Feld mit Hinweis, was hineingehört. Der Bericht nennt, ob eine Unternehmensbeschreibung vorlag.
- **0.7.6, Angaben des Betriebs aus der eigenen Website (Wunsch von Thomas: „muss aus der Webseite selbst hervorgehen, kann später nicht immer angepasst werden“):** `gather_context` wählt höchstens zwei eigene veröffentlichte Seiten oder Produkte (vor Blogbeiträgen), deren Titel oder Pfad die meisten Wörter aus Titel und Keywords des Themas enthält (ohne Ort), und gibt ihren Text (je bis 3000 Zeichen, Elementor eingeschlossen) als belegte Angaben des Unternehmens an Briefing, Entwurf, Bewertung und Zahlenprüfung. Ändert sich die Website, ändern sich die Angaben mit; Beschreibungen im Profil sind nur noch Ergänzung. Der Bericht nennt die verwendeten Seiten.
- **Erster bestandener Entwurf (Lauf 126, 22.10., 0.7.6):** Thema 39, `anthropic/claude-sonnet-5.5`, 875 Wörter, 0,217 USD, Bewertung 90,3 (Mehrwert 0,88, Lesbarkeit 0,92), keine blockierenden Mängel; eigene Seiten „Gehweg- und Pflasterreinigung“ und „Baustellenbodenreinigung“ als Angaben des Betriebs. Offen für die Abnahme von Stufe E: Urteil von Thomas über den Text, Vergleichslauf mit `openai/gpt-6-luna` unter gleichen Bedingungen, drei Entwürfe über den Cronjob.
- **Modellvergleich unter gleichen Bedingungen (23.10., 0.7.6, Thema 39):**

  | Ablauf | Hauptmodell | Entwurf | Kosten | Bewertung | Lokale Relevanz | Mehrwert |
  |---|---|---|---|---|---|---|
  | 127 | `anthropic/claude-sonnet-5.5` | 2760 | 0,223 USD | 87,3 | 0,80 | 0,86 |
  | 128 | `openai/gpt-6-luna` | 2759 | 0,005 USD | 90,5 | 0,90 | 0,85 |

  **Entscheidung von Thomas (23.10.):** „Menschlicher ist die Version 2760“ – Hauptmodell `anthropic/claude-sonnet-5.5`. Die KI-Bewertung hatte Luna höher eingestuft; sie misst Natürlichkeit des Textes offenbar nicht gut. Das ist bei der Kalibrierung der Mindestwerte in der Testphase zu berücksichtigen. Kosten je Entwurf mit Sonnet rund 0,20–0,22 USD (20 Beiträge in der Testphase etwa 4,40 USD, im Monatsbudget von 20 USD).
- **Nachtrag 23.10. (0.7.9), Modelle je Aufgabe:** Thomas will das Modell wegen des Preisunterschieds flexibel halten. Kosten in Ablauf 127 (Sonnet): Briefing 0,062 USD (9 108 Token Eingabe, 4 420 Ausgabe), Text 0,117 USD (13 931 / 8 881), Bewertung 0,044 USD (13 410 / 1 671). Der Text entscheidet, wie natürlich der Beitrag klingt; deshalb gibt es neben dem Hauptmodell `ai_writing_model` (Schreibmodell, nur `generate_draft`) und `ai_review_model` (Prüfmodell, nur `quality_review`); leer bedeutet jeweils Hauptmodell. Für einen einzelnen Entwurf gilt `--writer=<modell>`, `--model=<modell>` gilt weiter für alle Aufrufe. Empfohlene Aufteilung: Hauptmodell `openai/gpt-6-luna`, Schreibmodell `anthropic/claude-sonnet-5.5` (etwa 0,12 USD je Entwurf). Ob Luna zuverlässig prüft, wird mit `wp lsec production review <entwurf> --model=<modell>` an vorhandenen Entwürfen geprüft (Entwurf 2760 und ein blockierter Entwurf aus den Läufen 121–124); prüft Luna zu lax, wird `ai_review_model` auf Sonnet gesetzt (etwa 0,16 USD je Entwurf). `production review` prüft den Text, den die KI geschrieben hat, mit den Regeln und der KI-Bewertung neu; gespeichertes Prüfergebnis und Status des Entwurfs bleiben unverändert, die Kosten stehen ohne Ablauf im KI-Protokoll.
- **Test der Prüfmodelle (23.10., `production review`):**

  | Entwurf | Prüfmodell | Bewertung | Ergebnis | Kosten |
  |---|---|---|---|---|
  | 2760 (Sonnet, bestanden mit 87,3) | Luna | 92,8 | blockiert: „Preis und Dauer hängen vom Einzelfall ab“ als unbelegte Preispraxis (Fehlalarm) | 0,0014 USD |
  | 2752 (Ablauf 125, erfundener Ablauf) | Luna | 90,8 | blockiert: Ablauf, „kurze Wege, wir kennen die Region“ | 0,0011 USD |
  | 2752 (Ablauf 125) | Sonnet | 79,7 | blockiert: Ablauf; Warnungen: Beispielsätze des Stils übernommen, eingeschobene Keywords, übertriebener Nutzen, Wiederholungen | 0,0441 USD |

  Ergebnis: Luna erkennt unbelegte Tatsachen zuverlässig, eher strenger als Sonnet. Ihre Punkte liegen aber fast immer über 0,84 (Stil 0,91 bei übernommenen Beispielsätzen), sodass die Mindestwerte praktisch nicht greifen; Sonnet streut 0,65–0,90 und findet die Schwächen im Text. Empfehlung: Hauptmodell `openai/gpt-6-luna`, Schreib- und Prüfmodell `anthropic/claude-sonnet-5.5` (etwa 0,16 USD je Entwurf); günstigste Variante mit Luna als Prüfmodell etwa 0,12 USD. Seit 0.7.10 nennt der Prüfauftrag den Hinweis, dass Preis und Dauer vom Einzelfall abhängen und auf Anfrage genannt werden, ausdrücklich als zulässig.
- **Nachtrag 23.10. (0.7.10), lokale Tatsachen:** Die Abläufe 129 (Thema 4, Lehrte) und 130 (Thema 22, Uetze) über den Cronjob wurden mit `LOCAL_FACTS – Ort genannt, 0 lokale Tatsachen` blockiert. Die Regel galt bisher für jedes Thema mit Ort; 10.1 verlangt sie für lokale Beiträge. Sie blockiert jetzt nur bei `local_landing` und ist sonst eine Warnung. Die Einzelheiten nennen, wie viele geprüfte Tatsachen zu Orten vorhanden waren; bei keiner verweisen sie auf die Recherche. Der Bericht (`production report`) zeigt Format, Ort und die Zahl der geprüften Tatsachen im Kontext und im Text, jeweils mit dem Anteil zu Orten.
- **Nachtrag 23.10. (0.7.11), Ortsfakten und Ablaufzusagen:** Neuprüfung mit 0.7.10: Thema 4 (Lehrte) und 22 (Uetze) sind lokale Seiten; in beiden Kontexten war keine geprüfte Tatsache zu einem Ort. Lehrte hat keine Entität. Zu Uetze gab es drei: das Servicegebiet „Uetze“ (83, Profil) und die Kandidaten „Uetze“ (73) und „Gemeinde Uetze“ (68) aus der Recherche; gesucht wurde nur die erste passende, das Servicegebiet ohne Tatsachen. `gather_context` nimmt jetzt alle Orte und Servicegebiete gleichen Namens (`PlaceName`: Schreibweise, vorangestellte Verwaltungsbezeichnung wie „Gemeinde“ oder „Stadt“, angehängtes „und Umgebung“; „Region Hannover“ bleibt von „Hannover“ verschieden). Die Abläufe 129 und 130 enthielten trotz der Regeln seit 0.7.6 Ablaufzusagen („nach Absprache“, „aus einer Hand“), eine Kundenbehauptung und eine Ortsverallgemeinerung („In Lehrte sind das häufig“). Thomas: Das Profil um Abläufe zu ergänzen ist keine Lösung, weil das Plugin für beliebige Branchen (z. B. Anwälte, Friseure) ohne Nacharbeit funktionieren soll. Die Regeln für Briefing und Text sind deshalb branchenneutral formuliert und um diese Muster ergänzt.
- **Nachtrag 23.10. (0.7.12), übliche Geschäftspraxis:** Thomas hält Aussagen wie „nach Absprache“ oder „vorherige Besichtigung bzw. Inspektion“ für legitim. Briefing, Text und KI-Bewertung erlauben deshalb ohne Angabe des Unternehmens: Anfrage oder Kontakt, Besichtigung, Inspektion oder Vorgespräch vor dem Angebot, Preis und Dauer je nach Einzelfall und auf Anfrage, Termine, Umfang und Turnus nach Absprache. Nicht erlaubt bleiben ohne Beleg: feste Arbeitsschritte, Geräte, Garantien, Dauern, Ergebnisversprechen, Kunden, Erfahrung und Ortskenntnis des Unternehmens sowie Verallgemeinerungen über den Ort.
- **Nachtrag 23.10. (0.7.13), Nachbesserung, Ortsfakten und Fehler bei den Tatsachen:** Thomas hat beides freigegeben.
  - **Fehler seit 0.7.0:** `ClaimRepository::usable` sortierte ohne Tatsachen aus der Recherche des Themas mit `ORDER BY 0`. MySQL und MariaDB lehnen das ab, die Abfrage lieferte keine Tatsachen. Themen ohne eigene Tatsachen (wahrscheinlich 4 und 22) bekamen deshalb weder Orts- noch andere Fakten. Behoben.
  - **`place_facts`** (nach `gather_context`): nur für `local_landing` mit Ort und weniger als zwei geprüften Tatsachen zu Orten, wenn `place_research` an ist (Standard). Websuche mit dem Recherchemodell (`place_facts_search`), Strukturierung mit dem Hauptmodell (`place_facts`, höchstens acht Tatsachen mit Quelle und wörtlichem Zitat), Abruf der Quellen (höchstens vier je Durchgang) und Faktenprüfung wie in Stufe D. Gespeichert an der Entität des Ortes (vorhandene, bestätigte zuerst; sonst neuer Kandidat `ai_research`). Danach stehen die Tatsachen allen Themen zu diesem Ort zur Verfügung. Ein Ort wird höchstens alle 30 Tage recherchiert (Option `lsec_place_research`). Briefing, Text und Prüfung nutzen den Kontext mit diesen Tatsachen.
  - **`revise`** (nach `quality_check`): nur wenn der Entwurf blockiert ist, `production_revise` an ist (Standard) und alle blockierenden Mängel behebbar sind (`UNSUPPORTED_NUMBER`, `UNSUPPORTED_STATEMENT`, `PLACEHOLDER`, `FOREIGN_PLACE`, `AI_UNSUPPORTED_FACT`, `AI_WRONG_PLACE`, `AI_MISLEADING`). Das Schreibmodell bekommt die Befunde und die Texte des Beitrags und liefert Paare aus beanstandeter Stelle und neuer Fassung (leer = streichen). Das Plugin ersetzt nur Stellen, die wörtlich vorkommen. Ohne HTML gefundene Stellen ersetzt es nur in Feldern ohne Link. Leere Absätze und Listenpunkte entfallen. Danach folgen einmal Regelprüfung und KI-Bewertung mit neuem Prüfbericht. `create_post` und `finalize` verwenden den nachgebesserten Entwurf und dessen Ergebnis. Der Bericht nennt die Änderungen und nicht gefundene Stellen.
  - Kosten: Nachbesserung etwa 0,05–0,10 USD mit Sonnet als Schreib- und Prüfmodell, Ortsrecherche etwa 0,02–0,05 USD je Ort.
  - Prüfskript E8 (Nachbesserung einer unbelegten Zahl) und E9 (Ortsrecherche, zweiter Entwurf nutzt die gespeicherten Tatsachen).
- **Abnahme Stufe E (23.10.):** Drei Entwürfe entstanden über den Cronjob (Abläufe 129–131); ein Entwurf mit unbelegter Zahl wird `blocked` (Prüfskript E2 und echte Läufe 121–124); Kosten je Beitrag gemessen (siehe oben); Modellvergleich mit Entscheidung.
- **0.7.8:** Ablehnen oder Zurücksetzen eines Themas bricht dessen laufende Entwürfe ab (Ablauf 131 schrieb Thema 43 nach der Ablehnung zu Ende); `create_post` und `finalize` enden ohne Entwurf, wenn das Thema nicht mehr freigegeben ist (`topic_withdrawn`). `wp lsec production report` nimmt auch die Nummer des Entwurfs.
- **Bericht:** `wp lsec production report <ablauf>` bzw. `GET lsec/v1/production/<ablauf>` mit Modell, Kosten je KI-Aufruf, Wörtern, Bewertung, Mängeln und Hinweisen; `wp lsec production list` zeigt freigegebene und erstellte Themen mit Prüfergebnis. Grundlage für die Messung „Kosten je Beitrag“ und den Modellvergleich (`--model`).

**`topic_research`**: `collect_keywords` (Quellen abgleichen, Filter 6.4) → `research` (Websuche, 8.4) → `verify_facts` (8.4a) → `propose_topics` (Priorität, Überschneidung) → Status `waiting` bis zur Freigabe.

**Umsetzung (Nachtrag 22.10., Stufe D, `TopicResearchWorkflow`):**
- **`collect_keywords`:** sammelt Profil, Servicegebiet und bis zu 80 Keywords ohne Ausschluss. Mit Schwerpunkt kommen zuerst die Keywords mit dessen Wörtern dran, sonst die nach Volumen und Impressionen.
  - Dazu kommen Entitäten, also Profil, Orte und bestätigte Begriffe, sowie bis zu 150 bestehende Inhalte aus dem Inhaltsindex.
- **`research`:**
  - Erster Durchgang: Websuche mit dem Recherchemodell (`AiService::search`, neu). Die Quellen landen in `lsec_sources`.
  - Zweiter Durchgang: Strukturierung ohne Werkzeuge, mit Kontext und dem Ergebnis der Websuche (bis 8000 Token).
  - Ein Fehler in der Strukturierung wiederholt die bezahlte Websuche nicht (`StepResult::more`).
  - **Nachtrag 22.10. (0.6.1):** Auf der Testwebsite lieferte das Recherchemodell (`openai/gpt-6-luna` über OpenRouter) trotz bezahlter Websuche keine Quellenangaben (Annotationen); die Recherche endete mit 0 Quellen. Fehlen die Annotationen, liest das Plugin die Adressen aus dem Text der Recherche (Markdown-Links mit ihrem Text als Titel, sonst nackte Adressen; doppelte einmal, höchstens 10). Im Zustand des Schritts steht `sources_from` (`annotations` oder `text`). OpenRouter-Antworten mit einer Liste `citations` (z. B. Perplexity) werden ebenfalls als Quellen gelesen. Der Auftrag an das Recherchemodell verlangt die vollständige Adresse je Tatsache.
  - **Nachtrag 22.10. (0.6.2), Suchmaschine wählbar:** Auch mit 0.6.1 endeten drei weitere Läufe (113–115) mit 0 Quellen; der Recherchetext enthielt keine einzige Adresse. Nur Lauf 112 hatte Quellen. Bei OpenAI-Modellen wählt OpenRouter ohne Angabe die Websuche des Modells (`native`); ob dabei jedes Mal gesucht und zitiert wird, ist nicht dokumentiert. Exa und Parallel suchen dagegen bei jeder Anfrage und hängen die Treffer als `url_citation` an. Deshalb:
    - Neue Einstellung `ai_web_engine` (`standard`, `native`, `exa`, `parallel`, `perplexity`; Standard `standard` = Wahl von OpenRouter). Sie wird als `engine` im Plugin `web` gesendet und gilt nur für OpenRouter.
    - `wp lsec topics research --web-engine=<engine>` für den Vergleich, gespeichert im Ablauf.
    - `wp lsec topics report` nennt die Suchmaschine, warnt bei 0 Quellen und zeigt mit `--research` den Text der Websuche.
    - Kosten laut OpenRouter (Oktober 2026): Exa 7 USD je 1000 Anfragen, Parallel (basic) 5 USD je 1000, also unter einem Cent je Recherche zusätzlich zu den Token.
    - **Ergebnis auf der Testwebsite:** Mit `exa` lieferten beide Läufe Quellen (116: 3, 117: 5) und waren billiger als mit `standard` (0,111 und 0,106 USD statt 0,129 bis 0,149 USD). **Empfehlung:** `ai_web_engine = exa` für OpenAI-Modelle über OpenRouter. Der Standard des Plugins bleibt `standard`, weil er für Anthropic-Modelle die eigene Websuche nutzt.
  - **Nachtrag 22.10. (0.6.3), abgeschnittene Websuche:** Der Recherchetext von Lauf 114 brach nach 843 Zeichen mitten im Satz ab. Bei Modellen mit Denkschritten zählen diese zur Obergrenze der Antwort; 3000 Token reichten nicht. Die Websuche erhält jetzt 8000 Token (`AiService::SEARCH_TOKENS`) und 180 s. Bricht die Antwort trotzdem ab (`finish_reason = length`), steht `truncated` im Ergebnis des Schritts und der Bericht warnt.
  - **Bericht mit `--facts`:** jede Tatsache mit Status, Prüfhinweis, Quelle, Zitat der KI und Beleg aus der Quelle. `wp lsec topics show` nennt den Prüfhinweis je Tatsache.
- **`verify_facts`:** 8.4a, vier Quellen je Durchgang.
- **`propose_topics`:** setzt `off_topic`, berechnet Überschneidung und Priorität und speichert die Themen.
- **Freigabe:**
  - Der Ablauf endet mit `succeeded`; die Freigabe geschieht je Thema (Status `approved`). Das Warten auf die Freigabe übernimmt `content_production` in Stufe E.
  - Freigabe und Ablehnung gehen über die Seite „Themen“, `POST lsec/v1/topics/<id>/decision` oder `wp lsec topics decide <id> approved|rejected|proposed`.
- **Start:** über „Recherche starten“ (Leistung, Ort, Anzahl), `POST lsec/v1/topics/research` oder `wp lsec topics research [--service] [--region] [--count] [--web-results] [--research-model] [--force] [--now]`.
- **Zwischenspeicher (8.1b):** Ein gleicher Schwerpunkt aus Leistung und Ort wird innerhalb von 14 Tagen nur mit „trotzdem“ bzw. `--force` neu recherchiert.
- **Vergleich der Trefferzahl und der Recherchemodelle:** `wp lsec topics report <ablauf>` zeigt Kosten, Modell, Treffer, Quellen und den Anteil geprüfter Tatsachen. Vergleichen lassen sich so z. B. zwei Recherchen mit `--web-results=3` und `--web-results=5` oder mit `--research-model=…` und `--force`. Das Ergebnis wird hier nachgetragen, wenn es auf der Testwebsite vorliegt.
  - **Zwischenstand 22.10.:** 3 Treffer (Lauf 114) und 5 Treffer (Lauf 115) kosteten 0,149 bzw. 0,129 USD. Beide lieferten 0 Quellen, ein Vergleich der geprüften Tatsachen ist so nicht möglich. Er wird mit `--web-engine=exa` wiederholt.
  - **Vergleich der Recherchemodelle (22.10., 5 Treffer, Prüfung noch nach 0.6.3):**

    | Lauf | Recherche | Kosten | Quellen | Tatsachen | Art der Quellen |
    |---|---|---|---|---|---|
    | 118 | `perplexity/sonar-pro`, Suche des Modells | 0,234 USD | 20 | 11, alle verworfen | Seiten der Gemeinde, Zitate wörtlich richtig |
    | 119 | `openai/gpt-6-luna`, Exa | 0,098 USD | 5 | 11: 1 geprüft, 5 unklar, 5 verworfen | überwiegend die eigene Website |

    Die Verwerfungen in Lauf 118 lagen an der Prüfung (Nachtrag 0.6.4 in 8.4a). Die Entscheidung über den Standard folgt nach einem Lauf mit 0.6.4.
  - **Ergebnis mit 0.6.4 (Lauf 120, `perplexity/sonar-pro`):** 0,235 USD, 20 Quellen, 10 Tatsachen: 7 geprüft, 2 unklar, 1 verworfen (70 %). Die zwei unklaren waren zwei verschiedene Regeln der Straßenreinigung, die als Widerspruch galten (behoben in 0.6.5, siehe 8.4a); verworfen wurde eine Tatsache aus einer PDF-Datei.
  - **Entscheidung (22.10.):** Bei OpenRouter ist `perplexity/sonar-pro` das empfohlene Recherchemodell (`ai_research_model`), mit `ai_web_engine = standard`, weil Perplexity selbst sucht. Die Strukturierung bleibt beim Hauptmodell. Die Trefferzahl wirkt bei Perplexity nicht (20 Quellen bei 5 Treffern); `ai_web_max_results` bleibt 3 für die anderen Anbieter. Für OpenAI-Modelle ohne Perplexity ist `exa` die Alternative, liefert aber oft Seiten der eigenen Website. Im Plugin bleibt `ai_research_model` ohne Vorgabe, weil das Modell vom Anbieter abhängt; der Einrichtungshinweis nennt die Empfehlung.
  - **Offen:** Text aus PDF-Quellen (z. B. Satzungen der Gemeinde) wird nicht gelesen; solche Tatsachen werden „nicht abrufbar“ verworfen. Kandidat für Ausbau 2.
- **Doppelte Themen (Nachtrag 22.10., 0.7.1):** Zwei Recherchen schlugen dasselbe Thema vor („Pflasterreinigung in Uetze: Gehwege und Pflasterflächen …“ und „Pflasterreinigung in Uetze: Firma in der Nähe …“, Themen 43 und 39); beide wurden freigegeben und hätten als zwei Seiten gegeneinander gerankt. Deshalb (`TopicSimilarity`):
  - **Nachtrag 23.10. (0.7.7):** Der Cronjob startete Thema 43, obwohl 39 schon einen Entwurf hatte. Die Leistung heißt im Profil „Gehweg- und Pflasterreinigung“; verlangt waren alle Wörter des Namens, und 39 enthält kein „Gehweg“. Seitdem ist die Hauptleistung das erste **Leistungswort** aus den Namen der Leistungen (Wörter ab sechs Buchstaben, gekürzt auf acht, ohne „Reinigung…“, „Entfernung…“, „Bereichen“ usw.), das im Titel oder Haupt-Keyword vorkommt; für 39 und 43 „pflaster“. Unit-Test mit den echten Leistungsnamen des Profils.
  - **Dublette:** gleiche Art (0.7.2: Seite – lokale Seite, Beitrag oder sonstiges –, Ratgeber oder saisonal; Thema 39 war als Beitrag, 43 als lokale Seite angelegt und fiel deshalb durch) und gleicher Ort (ohne „Region“, „Gemeinde“ usw.) und entweder dieselbe Hauptleistung – die erste Leistung aus dem Profil, die im Titel oder Haupt-Keyword steht – oder, auch ohne Ort, mindestens 50 % gleiche Titelwörter (ohne Ort und Füllwörter wie „Profi“, „Firma“, „Nähe“, „lassen“; Wörter ab vier Buchstaben, verglichen über die ersten zehn).
  - **Recherche:** Die KI erhält die vorhandenen Themen („nicht erneut vorschlagen, anderen Blickwinkel wählen“). Ein vorgeschlagenes Thema, das trotzdem eine Dublette ist, wird nicht angelegt. Ist das vorhandene Thema Vorschlag oder freigegeben, bekommt es die neuen Tatsachen, Quellen und Keywords („zusammengeführt mit #…“); sonst steht im Bericht „Dublette von #…“.
  - **Themen-Seite:** Hinweis „Doppelt zu #…“ am schwächeren Thema einer Gruppe (Reihenfolge: geschrieben, in Arbeit, freigegeben, Vorschlag, dann Priorität).
  - **Vorhandene Dubletten:** `wp lsec topics duplicates [--apply]`; mit `--apply` werden die schwächeren abgelehnt und geben Tatsachen und Keywords an das erste ab. Themen in Arbeit oder mit Entwurf bleiben unverändert.
  - **Produktion:** kein Entwurf zu einem Thema, dessen Dublette schon einen Entwurf hat oder in Arbeit ist (auch nicht über den Cronjob); von Hand mit `--allow-duplicate`.
- **`off_topic`:** Der Keyword-Abgleich hebt diesen Grund nicht wieder auf. Er setzt nur einen anderen Grund davor, z. B. `competitor`.

**`entity_extraction`**: je Beitrag `extract` (8.3) → `match_places` (Wikidata) → `save`. Wird vom Inhaltsindex für neue oder geänderte Beiträge angestoßen. Umgesetzt in Stufe B: Idempotenz-Schlüssel aus Beitrag und Inhalts-Hash, derselbe Inhalt wird also nie zweimal bezahlt erkannt; von Hand `wp lsec entities extract <id> --queue`.

**`keyword_sync`**: Search Console abrufen, Textfocus-Volumina für neue Keywords ohne Volumen nachholen (innerhalb des Budgets, Tippfehlerprüfung vorher).

**Umsetzung (Nachtrag 22.10., Stufe C):** Schritte `gsc` → `analyze` → `filter` → `volumes` (`KeywordSyncWorkflow`).
- **`gsc`:** 28 Tage, zusätzlich 90 Tage, wenn der letzte 90-Tage-Abruf eine Woche zurückliegt. Ohne Property oder Schlüsseldatei wird der Schritt übersprungen, ohne Fehler.
- **`analyze`:** je Durchgang ein KI-Aufruf für 100 Keywords, höchstens 10 Durchgänge je Ablauf. Ohne eingerichtete KI wird der Schritt übersprungen.
- **`filter`:** in Durchgängen zu 200 Keywords.
- **`volumes`:**
  - je Durchgang ein `tf_keyword`-Aufruf. Der Mindestabstand wird als Verzögerung eingeplant (`StepResult::more( $state, $sekunden )`, neu), nicht als Fehlversuch gezählt.
  - Keywords ohne Antwort erhalten eine leere Kennzahl, damit sie 30 Tage nicht erneut abgefragt werden.
  - Mögliche Tippfehler werden übersprungen.
  - Fünf Credits des Tagesbudgets bleiben für Abfragen von Hand frei (`TEXTFOCUS_RESERVE`). Ist der Rest verbraucht, wartet der Ablauf mit dem Grund `textfocus_budget`. `wp lsec cron` setzt ihn fort, sobald wieder Credits frei sind, also am nächsten Tag (7.2). Damit ist das Textfocus-Budget wie angekündigt auf diesen Weg umgestellt.
- **Start:**
  - `wp lsec cron` startet den Abgleich höchstens alle 20 Stunden, sobald eine Property eingetragen ist oder Keywords vorhanden sind.
  - Von Hand: „Jetzt abgleichen“ oder `wp lsec keywords sync [--now] [--no-gsc] [--no-volumes]`.
  - Nach einem Import und nach verwandten Keywords läuft der Abgleich ohne Search Console.
- **Verwandte Keywords:** `wp lsec keywords related <keyword>` bzw. REST `POST lsec/v1/keywords/related` speichert die `tf_related`-Ergebnisse als Quelle `textfocus` mit Volumen und Intention, nach Tippfehlerprüfung. Das kostet 1 Credit, der Zwischenspeicher gilt 14 Tage.

**`content_index`**: 5.

### 7.4 Sperren, Wiederholungen und Doppelschutz

Umgesetzt und geprüft in M4–M6:

- **Ablaufsperre** über ein atomares Update mit `lock_token` und `locked_until` (10 Minuten). Eine abgelaufene Sperre gilt als abgebrochener Lauf.
- **Idempotenz:** `idempotency_key = sha256(type + subject + Briefing-Version)`.
- **Kein doppelter Beitrag:** `create_post` sucht zuerst nach `_lsec_run_id`.
- **Wiederholungen:** höchstens 3 Versuche je Schritt nach 1, 5 und 30 Minuten.
- **Schritte in Durchgängen (Nachtrag 18.10., Stufe B):** Ein Schritt kann `StepResult::more( $zwischenstand )` zurückgeben. Er bleibt `pending`, der Zwischenstand steht beim nächsten Durchgang in `RunContext::$state`, der Versuch zählt nicht. Genutzt vom Inhaltsindex.

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

Der Administrator wählt einen Anbieter und ein Hauptmodell (`ai_provider`, `ai_model`), das für alle Aufgaben gilt. Ausnahme (entschieden am 18.10.2026, Nachtrag zu Anforderung 14): Für den Schritt `research` mit Websuche kann `ai_research_model` ein eigenes Modell desselben Anbieters festlegen; leer bedeutet Hauptmodell. Die Strukturierung danach läuft mit dem Hauptmodell. Nachtrag 23.10.2026 (0.7.9): Für Beiträge gibt es zusätzlich `ai_writing_model` (Text) und `ai_review_model` (KI-Bewertung), siehe 7.3. `lsec_ai_calls.model` zeigt, welches Modell ein Aufruf verwendet hat. Preise für Anthropic und OpenAI stehen in der Einstellung `ai_prices` (USD je Million Token). Fehlt ein Preis, zeigt das Plugin „Kosten unbekannt“; das KI-Budget zählt solche Aufrufe dann mit einer Warnung nicht mit.

**Hauptmodell (Vorschlag vom 18.10., entschieden am 23.10. mit Modellen je Aufgabe, siehe 7.3 und 13):** OpenRouter mit `anthropic/claude-sonnet-5.5` als Startwert, weil es im Test gültige, sachlich vorsichtige Ergebnisse geliefert hat. Festgelegt wird nach dem Vergleich in Stufe E (14) mit drei deutschen Beispielbeiträgen der Testwebsite; verglichen werden Textqualität, Faktentreue nach 8.4a und Kosten je Beitrag.

**Recherchemodell (Entscheidung offen):** Auswahl in Stufe D. Kandidaten sind günstigere Modelle mit Websuche beim selben Anbieter; Maßstab ist der Anteil der Tatsachen, die die Faktenprüfung (8.4a) als `verified` einstuft, im Verhältnis zu den Kosten je Recherche.

### 8.1b Kostensteuerung

Der Test hat die Websuche als Kostentreiber gezeigt: Die Suchergebnisse werden als Eingabe-Token in den Prompt eingefügt (OpenRouter 44.229 Token und $0,142 für die Recherche, Strukturierung $0,005; OpenAI 28.839 Token). Eine Entity-Erkennung kostete rund $0,04 je Seite.

| Maßnahme | Festlegung |
|---|---|
| Ausgabenlimit beim Anbieter | bleibt die eigentliche Begrenzung (Anforderung 14); im Backend als Einrichtungshinweis |
| KI-Budget im Plugin | zusätzliche Bremse: Monatsbudget `ai_monthly_budget` und Höchstbetrag je Ablauf `ai_run_budget` in USD, berechnet aus `lsec_ai_calls.cost_estimate`. Bei Erreichen gehen Abläufe auf `waiting`; manuelle Prüfbefehle laufen mit Warnung weiter. **Festgelegt:** 20 USD im Monat, 1 USD je Ablauf (Standardwerte, einstellbar). Der Monat richtet sich nach der Zeitzone der Website |
| Treffer der Websuche | einstellbar `ai_web_max_results`; **festgelegt:** Standard 3 statt bisher 5. Bei OpenAI zusätzlich `search_context_size: low`. In Stufe D werden 3 und 5 Treffer verglichen (Kosten, Anteil geprüfter Tatsachen); bei klarem Ergebnis wird der Standard angepasst |
| Websuche nur wo nötig | nur im Schritt `research` der Themenrecherche; Briefing, Entwurf, Prüfung und Entity-Erkennung ohne Websuche |
| Zwischenspeicher | keine neue Recherche, wenn für dieselbe Entität und Region in den letzten 14 Tagen (**Vorschlag**) schon geprüfte Aussagen vorliegen; die Ergebnisse stehen in `lsec_sources`, `lsec_claims` und `lsec_evidence`. Umgesetzt (Stufe D): gleicher Schwerpunkt aus Leistung und Ort innerhalb von 14 Tagen nur mit „trotzdem“; abgerufene Seiten 30 Tage im Zwischenspeicher |
| Anzeige | Kosten je Ablauf, je Beitrag und je Monat im Dashboard; Schätzung vor dem Start eines Sammelauftrags |
| Umsetzung (Nachtrag 18.10., Stufe A) | Einstellungen `ai_monthly_budget` (20), `ai_run_budget` (1), `ai_web_max_results` (3, erlaubt 1–10), `ai_research_model` (leer). Geprüft wird vor jedem KI-Aufruf eines Ablaufs, auch vor dem Korrekturversuch. Der Monat beginnt am Ersten um 0 Uhr in der Zeitzone der Website. Aufrufe ohne Preis zählen nicht und werden als Hinweis gemeldet. Weitergabe der Trefferzahl: OpenRouter `max_results`, Anthropic `max_uses`, OpenAI `search_context_size` (bis 3 `low`, bis 6 `medium`, sonst `high`). Ein mit `--model` angegebenes Modell gilt auch für die Recherche. `wp lsec ai budget [--run=<id>]` |
| Eigenes Modell für die Recherche | **festgelegt:** Einstellung `ai_research_model` (8.1a), umgesetzt in Stufe A; Auswahl des Modells in Stufe D. Die Anforderungsspezifikation ist um einen Nachtrag zu Abschnitt 14 ergänzt |

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

**Umsetzung Stufe B (Nachtrag 18.10.):**

- Abgleich mit Vorhandenem (`EntityRepository::find`): gleicher Typ und Name, gleicher Typ und Alias, dann eine *bestätigte* Entität gleichen Namens oder Alias mit anderem Typ (die KI ordnet die eigene Firma mal als Unternehmen, mal als Marke ein). Eine zusammengeführte Entität verweist über `merged_into` auf ihr Ziel. Bekannte Entitäten gehen mit Typ in die Anfrage.
- Zusammenführen: Name und Aliasse der Quellen werden Aliasse des Ziels; Fundstellen, Aussagen, Belege, Beziehungen und Themenbezüge wandern mit (doppelte Kanten entfallen); fehlt dem Ziel eine Wikidata-ID, übernimmt es die der Quelle. Quellen erhalten `merged`. `wp lsec entities merge <ziel> <quelle>…`, REST `POST lsec/v1/entities/merge`.
- Vorschläge (`wp lsec entities suggest-merges [--apply]`, REST `GET lsec/v1/entities/merge-suggestions`): Ziel ist eine bestätigte Organisation oder Marke. Ein Kandidat passt, wenn er mit demselben Wort beginnt und jedes seiner Wörter der Anfang eines Worts des Ziels ist oder umgekehrt; Umlaute vereinheitlicht, Füllwörter und Rechtsformen (und, GmbH, KG …) zählen nicht. Geprüft mit den vier Schreibweisen aus dem Textfocus-Export.
- Anweisung an die KI geschärft: nur Benanntes, ausdrücklich keine allgemeinen Tätigkeiten, Zielgruppen und Werbeaussagen; Stufen für `confidence`.
- Oberfläche „Local SEO → Entitäten“ (React, ersetzt die PHP-Seite): Vorschläge zum Zusammenführen, Filter nach Typ, Status und Name, unsichere Vorschläge unter `entity_min_confidence` standardmäßig ausgeblendet, Bestätigen, Ablehnen, Zusammenführen mehrerer ausgewählter Einträge, Details mit Schreibweisen, Ortsabgleich, Beziehungen und Fundstellen je Beitrag. Die Graphansicht folgt in Stufe F.

**Nachtrag 22.10. (0.4.3), nach der Auswertung der ersten Erkennung auf der Testwebsite:**

- **Bearbeiten:**
  - Name, Typ, Beschreibung, Status und Schreibweisen lassen sich ändern (`EntityRepository::update`). Der Status geht in jede Richtung: Kandidat, wahrscheinlich, ungeklärt, bestätigt, abgelehnt. „Zusammengeführt“ ist nur über das Zusammenführen erreichbar.
  - Ein geänderter Name bleibt als Schreibweise erhalten.
  - Gehört ein neuer Name bei gleichem Typ schon einer anderen Entität, wird die Änderung abgelehnt; dafür ist das Zusammenführen da.
  - Einträge aus dem Unternehmensprofil: hier nur Beschreibung und Schreibweisen. Name, Typ und Status werden im Profil gepflegt.
  - Zugang: REST `POST lsec/v1/entities/<id>`, `wp lsec entities update <id> [--name] [--type] [--description] [--status] [--add-alias] [--remove-alias]`.
- **Löschen:**
  - Entfernt die Entität mit Schreibweisen, Fundstellen, Beziehungen, Aussagen, Belegen, Themenbezügen und allem, was in sie zusammengeführt war (`EntityRepository::delete`).
  - Anders als „abgelehnt“ merkt sich das Plugin den Namen nicht; eine spätere Erkennung kann ihn wieder vorschlagen. Die Oberfläche sagt das vor dem Löschen.
  - Einträge aus dem Unternehmensprofil lassen sich hier nicht löschen.
  - Zugang: REST `DELETE lsec/v1/entities/<id>`, `wp lsec entities delete <id> [--yes]`.
  - Beides wird im Prüfprotokoll festgehalten.
- **Oberfläche:**
  - Die Tabelle zeigt je nach Status „Bestätigen“, „Ablehnen“ und „Bearbeiten“, auch für schon entschiedene Einträge.
  - Die Details haben „Bearbeiten“ und „Löschen“.
  - Fundstellen sind nach Beitrag oder Kategorie gruppiert.
- **Weitere Vorschläge zum Zusammenführen** (`same_key_suggestions`), jeweils mit Grund:
  - **„gleicher Name, anderer Typ“:** zum Beispiel „Hochdruckreinigung“ als Leistung und als Begriff.
  - **„Gebietsangabe zum Ort“:** „Region Gehrden“ oder „Raum Gehrden“ zu „Gehrden“. Echte Verwaltungsgebiete werden nie einem gleichnamigen Ort zugeschlagen; das sind Gebiete aus dem Profil oder mit Wikidata-ID, etwa „Region Hannover“.
  - **Ziel des Zusammenführens:** der Profileintrag, sonst der Ort statt der Gebietsangabe, sonst ein bestätigter Eintrag, sonst der mit den meisten Fundstellen.
- **Schärfere Anweisung:**
  - Die Leistungen aus dem Profil gehen mit in die Anfrage. Erwähnte Leistungen werden ihnen zugeordnet, die Formulierung aus dem Text wird zur Schreibweise. Neue Leistungen gibt es nur, wenn keine passt.
  - Keine allgemeinen Materialien und Oberflächen (Beton, Fliesen, Epoxidharz).
  - `service_area` nur für echte Verwaltungs- oder Regionsnamen.
  - „Raum“, „Großraum“, „Umgebung“ und „Umkreis“ vor einem Ortsnamen werden auch nach der Antwort entfernt; die Formulierung bleibt als Schreibweise.
- **Ortsabgleich:**
  - Das Servicegebiet selbst zählt als „im Gebiet“.
  - Passt kein Kandidat, aber genau einer ist dem Servicegebiet übergeordnet, gilt er als `probable` („Übergeordnetes Gebiet“, z. B. Niedersachsen zur Region Hannover). Die übergeordneten Einheiten werden eine Woche zwischengespeichert.
  - Neu abgleichen: `wp lsec entities resolve-place <name> --save`.

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

**Umsetzung (Nachtrag 22.10., Stufe D, `TopicResearcher`):**
- **Kontext für die KI:** Keywords und Entitäten erhält die KI mit Nummern (#301). Unbekannte Nummern werden verworfen und im Bericht genannt.
- **Gegenstand einer Tatsache:** Statt `subject_entity_id` steht der Name mit Typ (`subject`, `subject_type`). Das Plugin ordnet ihn einer vorhandenen Entität zu oder legt einen Kandidaten mit Herkunft `ai_research` an. Begründung: Ortsteile wie Eltze sind oft noch keine Entität.
- **Ausschlüsse:** `excluded_keywords` nennt nur `off_topic` mit Begründung. Die anderen Gründe setzt der Keyword-Filter (Stufe C).

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

**Umsetzung (Nachtrag 22.10., Stufe D):**
- **Abruf** (`SourceFetcher`): Text oder Fehler stehen in `lsec_sources` (Schemaversion 5: `content`, `fetch_error`), Zwischenspeicher 30 Tage ab `fetched_at`. Ein erneuter Fund derselben Adresse ändert nur den Titel.
- **Prüfung** (`FactChecker`, mit Unit-Test zum Eltze/Dollbergen-Fall):
  - Gesucht wird nach Vereinheitlichung von Anführungszeichen, Strichen, Leerraum und Groß- und Kleinschreibung.
  - Absatz ist die Zeile des Textes. Bei sehr langen Blöcken gilt ein Fenster von 400 Zeichen um das Zitat.
  - Zahlen müssen als ganze Zahl vorkommen.
  - Fehlt der Wert im Zitat und im Absatz, wird die Aussage `rejected`.
  - **Nachtrag 22.10. (0.6.1), sinngemäße Zitate:** In der ersten echten Recherche wurden alle sechs Tatsachen verworfen („Zitat nicht in der Quelle gefunden“). Die Strukturierung sieht nur die Zusammenfassung der Websuche und gibt Zitate deshalb oft sinngemäß wieder. Steht das Zitat nicht wörtlich in der Quelle, sucht die Prüfung Absätze mit dem Wert:

    | Befund | Status | Beleg |
    |---|---|---|
    | Absatz mit Wert und Entität | `verified`, Hinweis „Zitat sinngemäß, Beleg im Text gefunden“ | `medium`, Auszug = Satz der Quelle mit dem Wert |
    | nur Absätze mit Wert, ohne Entität | `disputed`, „Bezug unklar“ | `weak` |
    | Wert nicht in der Quelle | `rejected`, „Zitat und Wert nicht in der Quelle gefunden“ | – |

    Der Eltze/Dollbergen-Fall bleibt abgedeckt: Dollbergen 1226 wird `disputed`, weil im Absatz mit 1226 nur Eltze steht (Unit-Test und Prüfskript D1).
  - **Nachtrag 22.10. (0.6.4), nach den Läufen 118 und 119:** Perplexity Sonar Pro fand die passenden Seiten der Gemeinde (uetze.de) mit wörtlich richtigen Zitaten, trotzdem wurden alle 11 Tatsachen verworfen („Wert steht nicht im Zitat oder Absatz“). Ursachen lagen in der Prüfung: Die KI gibt den Wert als eigenen Satz wieder („20.723 Einwohner (Stand Oktober 2022)“ zum Zitat „Einwohner: 20.723 (Stand: 10/2022)“) und den Gegenstand als Beschreibung („Sommerreinigung in Uetze“). Die Seite nennt den Ort oft nur im Titel und in der Adresse. Neue Regeln:
    - **Wert:** wörtlich → `strong`; sonst alle Zahlen des Werts → `strong`; ein Wert ohne Zahlen gilt, wenn mindestens 60 % seiner Wörter ab vier Buchstaben vorkommen (Wortanfang, fünf Buchstaben) → `medium`. Steht der Wert nicht im Absatz des Zitats, zählen bis zu zwei Absätze davor und danach → `medium`.
    - **Bezug:** Name, Name ohne allgemeine Wörter („Gemeinde“, „Stadt“, „Büro“ …) oder ein bekannter Ort im Namen („Sommerreinigung in Uetze“ → Uetze) im Zitat oder Absatz → wie beim Wert. Sonst genügt der Name im Titel oder in der Adresse der Seite → `medium`, aber nur, wenn der Absatz keinen anderen bekannten Ort nennt. Bekannte Orte sind Orte und Servicegebiet aus dem Kontext, der Ort des Schwerpunkts und die Orte der Tatsachen dieser Recherche. Damit bleibt Eltze/Dollbergen abgedeckt: „Uetze 1226“ auf der Seite „Ortsteile der Gemeinde Uetze“ wird `disputed`, weil der Absatz Eltze nennt.
    - Ohne wörtliches Zitat reichen Zahlen allein nicht, wenn der Wert auch Wörter hat; mindestens eines davon muss im Absatz stehen.
    - **Eigene Website:** Tatsachen mit einer Quelle auf dem Host der Website oder der Search-Console-Property werden nicht geprüft, sondern als „Quelle ist die eigene Website“ verworfen; diese Angaben stehen im Profil. Mit Exa kamen zwei von fünf Quellen von der eigenen Website.
    - **Auftrag an die KI:** `value` ist nur die Angabe selbst, kurz und in den Worten der Quelle. Keine Tatsachen über das eigene Unternehmen.
    - Unit-Test `FactCheckerRealTest` mit den Fällen aus Lauf 118; Prüfskript D1 mit einer Tatsache von der eigenen Website.
  - **Nachtrag 22.10. (0.6.5), Widersprüche:** Nur Eigenschaften mit einem Wert können sich widersprechen: Der Wert enthält eine Zahl, und die Eigenschaft ist keine Liste (Regel, Sehenswürdigkeit, Veranstaltung, Merkmal, Ortsteil …). In Lauf 120 galten zwei verschiedene Regeln der Straßenreinigung als Widerspruch.
- **Speicherung:** Aussagen landen in `lsec_claims` (`value` = JSON aus Wert und Hinweis), Belege mit Zitat und Qualität in `lsec_evidence`. Eine bessere Prüfung ersetzt eine schwächere; eine bestätigte Aussage bleibt.
- **Entscheidung von Hand:** auf der Themen-Seite, über `POST lsec/v1/claims/<id>/decision` oder `wp lsec topics fact <id> confirmed|rejected`.
- **`fact_check_ai`** ist nicht umgesetzt; der Vorschlag lautete „in MVP 1 ausgeschaltet“.

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

**Umsetzung (Nachtrag 22.10., Stufe E):** Seite „Local SEO → Style Mimic“ mit Profil zum Bearbeiten (speichert eine neue Version), „Aus eigenen Texten erzeugen“ (bis zu zehn veröffentlichte Beiträge oder Seiten, je höchstens 4000 Zeichen, zusammen 20.000; Beispieltexte; feste Regeln) und Versionen mit „aktivieren“. Die festen Regeln stehen wörtlich vorn im Profil. Ohne Profil schreiben Entwürfe sachlich und freundlich mit „Sie“. WP-CLI: `wp lsec style build --posts=… --rules="…|…"`, `show`, `list`, `activate <version>`, `set --file=…`. REST: `GET|POST lsec/v1/style`, `POST lsec/v1/style/build`, `POST lsec/v1/style/<version>/activate`.

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
| Keywords | Search Console, Import mit Spaltenzuordnung, Textfocus mit Credit-Anzeige, Quelle je Kennzahl, Ausschlüsse | umgesetzt in Stufe C (0.5.0): Karten „Täglicher Abgleich“, „Verwandte Keywords“, „Import“; Tabelle mit Volumen und Quelle, Wettbewerb, CPC, Search-Console-Werten, rankender Seite und Ausschluss; Filter nach Suche, Quelle, Status und Grund; Sortierung |
| Themen | Vorschläge mit Priorität, Begründung, geprüften Tatsachen; Freigabe; Einzel- und Sammelauftrag | umgesetzt in Stufe D (0.6.0): Recherche starten (Leistung, Ort, Anzahl), Vorschläge mit Prioritätskreis, Aufschlüsselung, Keywords, Entitäten, Tatsachen mit Status, Quelle und Zitat, Überschneidung; Freigeben, Ablehnen, Zurücksetzen; Tatsachen bestätigen oder verwerfen. Stufe E (0.7.0): Einzelauftrag „Beitrag schreiben“ und „neu schreiben“, Filter „in Arbeit“ und „Entwurf erstellt“, je Thema Beitrag mit Prüfergebnis, im Detail Ablauf, Kosten, Bewertung, Mängel und Hinweise mit Link zum Entwurf. Der Sammelauftrag folgt in Stufe F |
| Style Mimic | Eingaben, Profil, Versionen | umgesetzt in Stufe E (0.7.0), siehe 8.7 |
| Einstellungen, Gruppe „Beiträge“ | | Stufe E: `production_auto`, `drafts_per_day`, `draft_author`, `draft_lengths`, `quality_min_score`, `quality_min_average` |
| Abläufe | Status, Schritte, Kosten, Fehler, Fortsetzen/Abbrechen | neu |
| Einstellungen | KI, Budget, Search Console, Textfocus, Grenzen, Längen je Beitragstyp, Gewichte | neu (WP-CLI vorhanden) |

React über `@wordpress/scripts` und `@wordpress/components`, Daten über `lsec/v1`. Die vorhandene PHP-Seite „Entitäten“ wird in Stufe B ersetzt.

**Umsetzung Stufe A (Nachtrag 18.10.):** Menü „Local SEO“ mit Dashboard, Entitäten, Einstellungen und Technik (bisherige Statusseite). Dashboard: Einrichtungshinweise mit Befehl, KI-Kosten mit Monatsbudget, Textfocus-Credits (letzter Stand aus `tf_account`, Knopf „Guthaben abfragen“, kostet keinen Credit), Cronjob, Website (Search Console, Rank Math, Elementor, Versionen) und die letzten zehn Abläufe mit Kosten, Fortsetzen und Abbrechen; lädt jede Minute neu. Einstellungen in Gruppen (KI, Textfocus, Search Console, Entitäten, Allgemein); gespeichert werden nur geänderte Werte, der Server prüft jeden einzeln und meldet Ablehnungen je Feld (HTTP 422). Schlüssel gehören nicht in die Oberfläche. Routen: `GET lsec/v1/dashboard`, `GET|POST lsec/v1/settings`, `POST lsec/v1/runs/<id>/resume|cancel`, `POST lsec/v1/textfocus/account`. Pflichtfelder werden im Callback geprüft, nicht als `required`, weil WordPress fehlende Pflichtfelder vor der Berechtigung prüft (Besucher bekämen 400 statt 403).

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
| Lokale Beiträge: Ort genannt und mit mindestens zwei geprüften lokalen Aussagen verbunden | ja bei `local_landing`, sonst Warnung (Nachtrag 23.10.2026) |
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

**Umsetzung (Nachtrag 22.10., Stufe E, `QualityChecker`, `QualityReviewer`):**
- Alle Prüfungen aus 10.1 laufen; der Bericht nennt je Prüfung Ergebnis und Einzelheiten. Codes: `LENGTH`, `BLOCKS`, `UNSUPPORTED_FACT`, `UNSUPPORTED_STATEMENT`, `UNSUPPORTED_NUMBER`, `PRIMARY_ENTITY`, `INTERNAL_LINKS`, `OVERLAP`, `SIMILAR_TITLE`, `LOCAL_FACTS`, `LOCAL_DUPLICATE`, `FOREIGN_PLACE`, `PLACEHOLDER`, `SEO_META`, `FOCUS_TAKEN`, `REPETITION`.
- **Abweichungen und Festlegungen:**
  - Zahlenprüfung: Zahlen werden ohne Tausenderpunkt verglichen („20.723“ = „20723“). Belegt sind Zahlen aus verwendeten Aussagen (Wert und Zitat) und aus dem Profil (Telefon auch als Ganzes, Hausnummer, Postleitzahl). Steht eine Zahl in einer geprüften Aussage des Kontexts, die nicht in `used_claim_ids` steht, wird die Aussage ergänzt statt blockiert. **Einstellige Zahlen ohne Einheit** („in 3 Schritten“) gelten nicht als Tatsache; mit €, %, Euro, m², km oder Jahr(en) werden sie immer geprüft. Ausgeschriebene Jahreszahlen prüft nur die KI-Bewertung.
  - Interne Links: Links, die nicht auf ein Linkziel zeigen, entfernt `DraftWriter` beim Anlegen (Ankertext bleibt); die Prüfung nennt sie, blockiert aber nicht.
  - Ähnlicher Titel: `similar_text` auf vereinheitlichten Titeln, ab 85 % blockierend; verglichen mit dem Inhaltsindex und anderen Entwürfen des Plugins.
  - Lokale Beiträge: Ort im Text und mindestens zwei verwendete geprüfte Aussagen zu Orten; Übereinstimmung mit Plugin-Beiträgen desselben Typs für andere Orte über Folgen von fünf Wörtern, ab 50 % blockierend.
  - Orte außerhalb: abgelehnte Orts-Entitäten, die im Text vorkommen.
  - Platzhalter: eckige Klammern, `{{ }}`, „Lorem ipsum“, „Platzhalter“, TODO, XXX, TBD. Eine Fachbegriffsliste für Übersetzungsfehler gibt es noch nicht.
  - Fokus-Keyword: erstes Keyword in `rank_math_focus_keyword` anderer Beiträge (Warnung).
- **KI-Bewertung:** sechs Werte 0–1, blockierende Mängel (`AI_UNSUPPORTED_FACT`, `AI_WRONG_PLACE`, `AI_MISLEADING`, `AI_OFF_TOPIC`, `AI_COMPETITOR`, `AI_OTHER`) und Hinweise. Unter `quality_min_score` (0,6) bzw. `quality_min_average` (0,75) blockiert `QUALITY_SCORE` bzw. `QUALITY_AVERAGE`. Bewertung im Bericht = Durchschnitt × 100.

---

## 11. Sicherheit

- **Berechtigung:** Capability `lsec_manage` (Rolle Administrator). Alle REST-Routen prüfen sie in `permission_callback` (geprüft in M15: 403 für Besucher und Abonnenten); Formulare zusätzlich mit Nonces.
- **API-Schlüssel:** Konstanten in `wp-config.php` (`LSEC_OPENROUTER_API_KEY`, `LSEC_ANTHROPIC_API_KEY`, `LSEC_OPENAI_API_KEY`, `LSEC_TEXTFOCUS_API_KEY`) haben Vorrang. Im Backend eingegebene Schlüssel werden mit libsodium verschlüsselt (Schlüssel aus den WordPress-Salts) und nur maskiert angezeigt. `wp lsec status` nennt nur die Quelle eines Schlüssels.
- **Dienstkonto der Search Console:** Schlüsseldatei außerhalb des Webroots. Reihenfolge: Konstante `LSEC_GSC_CREDENTIALS_FILE`, Einstellung `gsc_credentials_file`, sonst `private/gsc-service-account.json` neben dem Webroot (Plesk-Ordner `private`, Rechte 700 für den Ordner und 600 für die Datei, Besitzer = Benutzer des Abonnements). Anmeldung über ein mit RS256 signiertes JWT, Bereich `webmasters.readonly`; das Zugriffstoken bleibt im Speicher. Die Schlüsseldatei wird nie über das Backend hochgeladen oder angezeigt.
- **Schwärzung:** Protokolle, Fehlermeldungen und Exporte schwärzen bekannte Geheimnisse und Muster: private Schlüssel, Google-Token (`ya29.…`), `key=`-Parameter, `X-API-Key`, Bearer-Token. Antworten von Textfocus werden ohne `params` gespeichert.
- **Prüfprotokoll:** Entscheidungen über Entitäten, Aussagen, Themen und Ausschlüsse sowie Änderungen an Budget und Schlüsselquellen werden mit der Stufe `audit` protokolliert, unabhängig von `WP_DEBUG`. Umsetzung (Nachtrag 18.10.): jede geänderte Einstellung über die Aktion `lsec_setting_changed` mit altem und neuem Wert und Benutzer; Fortsetzen und Abbrechen von Abläufen.
- **Restprüfung M8:** `wp lsec check secrets` sucht jeden eingestellten Schlüssel (ganzer Wert und ein Ausschnitt aus der Mitte) und zwei Zeilen des privaten Schlüssels der Search Console in allen Plugin-Tabellen, `wp_options` (ohne die verschlüsselte Ablage), `wp_postmeta`, `wp_usermeta`, den Tabellen von Action Scheduler, `debug.log`, dem PHP-Fehlerprotokoll und unter Plesk den Fehlerprotokollen im Ordner `logs` neben dem Webroot (ab 0.3.1; weitere Dateien mit `--log`). Ausgegeben werden nur Fundorte und Anzahl.
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
| M8 | Geheimnisse | bestanden im Code und in Tests; Restprüfung auf Staging mit `wp lsec check secrets` (Stufe A) |
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
| Hauptmodell | entschieden (23.10.2026): Modelle je Aufgabe einstellbar (7.3, 0.7.9). Text mit `anthropic/claude-sonnet-5.5` (las sich im Vergleich mit `openai/gpt-6-luna` menschlicher, Thomas); Briefing mit `openai/gpt-6-luna`; Prüfmodell nach Test mit `production review` |
| Recherchemodell | festgelegt (22.10.2026): `perplexity/sonar-pro` bei OpenRouter, Suchmaschine `standard` (7.3) |
| KI-Budget im Plugin | festgelegt: 20 USD/Monat, 1 USD je Ablauf (18.10.2026) |
| Treffer der Websuche | festgelegt: 3, Vergleich mit 5 in Stufe D (18.10.2026) |
| Eigenes Modell für die Recherche | festgelegt: ja, `ai_research_model` (18.10.2026; Nachtrag zu Anforderung 14); **Modell offen** bis Stufe D |
| KI-Sichtbarkeit (Screpy, optional Indexly) | **Entscheidung offen** – betrifft erst Ausbau 3 |
| Umfang und Dauer der Testphase | festgelegt: 20 geprüfte Beiträge in 60 Tagen (18.10.2026) |
| Mindestwerte der Qualitätsprüfung | Vorschlag, Kalibrierung in der Testphase |
| Bilddienst | offen (Ausbau 2) |
| Google Trends, DataForSEO | vorgesehen für Ausbau 2 |
| Screpy | vorgesehen für Ausbau 3 |

---

## 14. Umsetzungsplan MVP 1

Jede Stufe endet mit einer Version, einem Lauf der Prüfskripte in CI und auf Staging und einem Eintrag in `PROJEKTPLAN.md`. Jede Fähigkeit bekommt zuerst einen WP-CLI-Befehl, dann die Oberfläche.

| Stufe | Inhalt | Abnahme | Version |
|---|---|---|---|
| A – Grundlage | Schemaversion 3; Einstellungen für Budget, Websuche, Recherchemodell, Mindest-Sicherheit; KI-Budget mit `waiting`; Restprüfung M8; React-Grundgerüst mit Dashboard und Einstellungen | Migration 2 → 3 ohne Datenverlust; Budget hält einen Ablauf an und gibt ihn nach Erhöhung frei; Dashboard zeigt Kosten, Credits, Cron | 0.3.0 |
| B – Entitäten | Unternehmensprofil mit Servicegebiet; Inhaltsindex als Ablauf; Entity-Erkennung als Ablauf mit Ortsabgleich; schärfere Anweisung; Zusammenführen mit Vorschlägen; Entitäten-Seite in React | Index der Testwebsite vollständig; vier Schreibweisen des Firmennamens werden als Zusammenführung vorgeschlagen und zusammengeführt; Hemmingen wird `probable` | 0.4.0 |
| C – Keywords | `keyword_sync` täglich; Import im Backend mit Spaltenzuordnung; Textfocus-Volumina im Ablauf; `competition`; Filter (6.4); Keywords-Seite | Search Console läuft täglich ohne Eingriff; `tf_related`-Ergebnisse für „flächenreinigung“ erhalten Ausschlussgründe für Wettbewerber und Hamburg | 0.5.0 |
| D – Themen | `topic_research` mit Websuche, Faktenprüfung, Priorisierung und Überschneidung; Themen-Seite mit Freigabe; Vergleich der Websuche mit 3 und 5 Treffern und Auswahl des Recherchemodells | Eltze/Dollbergen-Fall: die falsch zugeordnete Jahreszahl wird nicht `verified`; eine Anfrage, für die eine passende Seite rankt, erscheint als Überschneidung | 0.6.0 |
| E – Produktion | Briefing, Style Mimic, Entwurf, Qualitätsprüfung, `content_production` vollständig; Modellvergleich mit drei Beispielbeiträgen | Drei Entwürfe zu freigegebenen Themen entstehen über den Cronjob; ein Entwurf mit unbelegter Zahl wird `blocked`; Kosten je Beitrag gemessen | 0.7.0 |
| F – Abschluss | Einrichtungsassistent (Nachtrag 22.10.); Abläufe-Seite, Content- und Entity-Bericht, Sammelauftrag, Hinweise „bestehende Seite verbessern“; Integrationstests; Dokumentation | Einrichtung einer frischen Website mit dem Assistenten ohne weitere Befehle außer Schlüsseln und Cronjob; Abnahme MVP 1 durch Thomas auf Staging; danach Beginn der Testphase | 1.0.0 |

**Nachtrag 22.10.2026 – Einrichtungsassistent (Wunsch von Thomas, Stufe F, nach der Abnahme von Stufe E):** Die Ersteinrichtung ist auf rund zehn Stellen verteilt. Ein Assistent (Seite „Einrichtung“ und `wp lsec setup`) führt Schritt für Schritt und hakt jeden Schritt ab; fehlt später etwas (z. B. Cronjob), zeigt das Dashboard den Schritt wieder.

1. **Technik prüfen:** Schlüssel, Cronjob, Search Console, Rank Math; je Lücke der Text zum Einfügen. Von Hand bleiben Schlüssel in `wp-config.php`, Cronjob in Plesk und die Freigabe des Dienstkontos in der Search Console.
2. **Website lesen:** Profil aus Startseite, Impressum, Kontakt und Leistungsseiten (vorhandener Profilvorschlag), neu: Servicegebiet aus der Firmenadresse mit Radius. Beschreibungen von Vorgehen und Leistungen werden nicht von Hand gepflegt: Entwürfe lesen sie aus den eigenen Leistungsseiten (seit 0.7.6); der Assistent zeigt, zu welchen Leistungen keine passende Seite gefunden wird.
3. **KI-Einstellungen mit einem Klick:** erprobte Werte (Recherchemodell, Suchmaschine, Budget; Hauptmodell nach dem Vergleich in Stufe E).
4. **Style Mimic:** neu aus einem Freitext (z. B. ZimmWriter-Prompt), den die KI in ein Profil überträgt und auf Konflikte mit den Regeln prüft (frei gewählte externe Links, feste Städtelisten, unbelegte Zahlen); oder automatisch aus den eigenen Seiten mit den meisten Impressionen und genug Text.
5. **Alles starten:** Index, Entity-Erkennung, Keyword-Abgleich und eine erste Themenrecherche mit Kostenschätzung.
6. **Entscheidungen:** unsichere Entitäten, Ausschlüsse, Freigabe der Themen.

**Entschieden am 18.10.2026:** KI-Budget, Treffer der Websuche, eigenes Recherchemodell, Testphase (20 Beiträge in 60 Tagen). **Noch offen:** Recherchemodell (Auswahl in Stufe D), Hauptmodell (Stufe E), KI-Sichtbarkeit (vor Ausbau 3).
