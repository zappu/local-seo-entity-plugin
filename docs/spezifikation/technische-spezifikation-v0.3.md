# WordPress Local SEO & Entity Content Plugin
## Technische Spezifikation v0.3

**Status:** Architekturentwurf mit konkretem Datenmodell, Aufgabenmodell und Machbarkeitstest (überarbeitet am 18.10.2026)  
**Entwicklung:** Noch nicht begonnen – nächster Schritt ist der Machbarkeitstest (Abschnitt 12)  
**Grundlage:** Anforderungsspezifikation 1.0, Technische Spezifikation v0.1 und v0.2  
**Arbeitstitel / Präfix:** `local-seo-entity-content` / `lsec`

---

## 0. Änderungen gegenüber v0.2

| Bereich | Änderung |
|---|---|
| Keyword-Daten | Festgelegt: Search Console, CSV-Import und KI-Websuche im MVP; Google Trends und DataForSEO als spätere Quellen über eine gemeinsame Schnittstelle (Abschnitt 6) |
| MVP-Zuschnitt | Search Console aus Ausbau 3 in den MVP vorgezogen; einfacher Entity-Prüfstatus gehört in den MVP (Abschnitt 2) |
| Aufgabenwarteschlange | Vorschlag: Action Scheduler, angestoßen per WP-CLI über den Server-Cronjob (Abschnitt 7) |
| Editor-Unterstützung | Neue Beiträge werden als Gutenberg-Blöcke erzeugt; Elementor wird nur gelesen (Abschnitt 9) |
| Rank Math | Integration für neue Beiträge über die Rank-Math-Metafelder; Content Analysis API erst für Editor-Empfehlungen (Abschnitt 9) |
| Datenbank | Konkretes Tabellenschema (Abschnitt 4) |
| KI | Ergebnisverträge je Aufgabe (Abschnitt 8); Anbieter OpenRouter, Anthropic und OpenAI über je einen Adapter, Recherche und strukturierte Ausgabe in getrennten Aufrufen (Abschnitt 8.2) |
| Machbarkeitstest | Prüfpunkte ergänzt und mit Abnahmekriterien versehen (Abschnitt 12) |
| Textfocus (18.10.) | Textfocus-API als vierte Keyword-Quelle im MVP, mit Credit-Budget und Zwischenspeicher (Abschnitte 4.4, 6.5) |
| Screpy, Indexly (18.10.) | Eingeordnet für Ausbau 3: Ranking-Verlauf, Backlinks, Site-Audit, KI-Sichtbarkeit (Abschnitt 6.3) |
| Erkenntnisse aus der Testauswertung (18.10.) | Importregeln je Quelle, Tippfehlerprüfung, Entity-Abgleich über Kontext, Prüfungen auf Ortsbezug und Titeldubletten (Abschnitte 6.6, 6.7, 8.3, 10.1, 12) |

Werte, die als **Vorschlag** gekennzeichnet sind, gelten als Ausgangspunkt und werden im Machbarkeitstest bestätigt oder angepasst.

---

## 1. Technische Rahmenbedingungen

| Punkt | Festlegung / Vorschlag |
|---|---|
| PHP | **Vorschlag:** mindestens 8.1 |
| WordPress | **Vorschlag:** mindestens 6.4 |
| Abhängigkeiten | Composer mit PSR-4-Autoloading (`Lsec\` → `src/`) |
| Konfliktschutz | Gebündelte Fremdbibliotheken werden mit eigenem Namespace-Präfix versehen (z. B. mit Strauss). Ausnahme: Action Scheduler, der für das parallele Mitliefern durch mehrere Plugins ausgelegt ist und automatisch die neueste vorhandene Version lädt. |
| Code-Standard | WordPress Coding Standards (PHPCS), statische Analyse mit PHPStan |
| Tests | PHPUnit mit WordPress-Testumgebung für Integrationstests, reine Unit-Tests für die fachliche Kernschicht |
| Versionsverwaltung | GitHub-Repository ab dem ersten Commit, CI über GitHub Actions (PHPCS, PHPStan, PHPUnit) |
| Testumgebung | Eigene Staging-WordPress-Installation auf dem Plesk-VPS mit Rank Math, Elementor und Server-Cronjob |

---

## 2. Bereinigter MVP-Zuschnitt

### MVP 1: Entity-basierte Content-Produktion

- Grundeinrichtung: Erkennung von Website, Branche, Rank Math, Elementor und WooCommerce
- Unternehmens-, Leistungs- und Standortprofil (gespeichert als bestätigte Entitäten, siehe 4.2)
- KI-gestützte Entity-Erkennung aus eigenen Inhalten und Unternehmensdaten
- **Einfacher Entity-Prüfstatus** (Kandidat, wahrscheinlich, bestätigt, ungeklärt, abgelehnt) mit Bestätigung durch den Administrator
- Tabellarische Entity-Übersicht mit Beziehungen; einfache Graphdarstellung
- **Keyword-Quellen:** Google Search Console, CSV-/Excel-Import, Textfocus-API, KI-Websuche
- Themenvorschläge mit Priorität, Begründung und Überschneidungsprüfung
- Grundlegender Wettbewerbsvergleich über die KI-Websuche
- Globaler Style Mimic
- Beitragserstellung mit Entity-Integration, internen Links, Kategorien, Schlagwörtern und Rank-Math-Angaben
- Qualitätsprüfung mit blockierenden Mängeln
- Bereitstellung als WordPress-Entwurf
- Grundlegender Content- und Entity-Bericht, KI-Verbrauchsübersicht

### Ausbau 2

- Externe Entity-Verifizierung (Wikidata, Register, offizielle Websites) mit Belegbewertung
- Dynamische Content-Cluster und kontinuierliche Themenproduktion
- Bildanalyse und KI-Bildgenerierung
- Testphase und regelgesteuerte automatische Veröffentlichung
- Google Trends (sobald Alpha-Zugang vorliegt) und DataForSEO als zusätzliche Keyword-Quellen
- Strukturierte Daten über Rank Math

### Ausbau 3

- Google Analytics 4 und Google Unternehmensprofil
- Lokale Ranking-Messung mit räumlicher Darstellung
- Bewertungen, Branchenverzeichnisse, Backlinks und Erwähnungen
- Screpy als Quelle für Ranking-Verlauf, Backlinks und Site-Audit (Abschnitt 6.3)
- Sichtbarkeit in KI-Antworten (Screpy, optional Indexly) – **neue Anforderung, Entscheidung offen**
- Erweiterte WooCommerce-Unterstützung

---

## 3. Modul- und Verzeichnisstruktur

```text
local-seo-entity-content/
├── local-seo-entity-content.php      Bootstrap, Konstanten, Autoloader
├── uninstall.php                      Datenentfernung nur bei aktivierter Option
├── composer.json
├── src/
│   ├── Core/                          Plugin, Container, Hooks, Aktivierung
│   ├── Admin/                         Menüs, Seiten, REST-Controller
│   ├── Configuration/                 Einstellungen, Geheimnisse, Website-Profil
│   ├── Entity/                        Entitäten, Beziehungen, Aussagen, Belege, Fundstellen
│   ├── Research/                      Keyword-Quellen, Import, Wettbewerb, Inhaltsanalyse
│   ├── TopicPlanning/                 Themenvorschläge, Priorisierung, Überschneidung
│   ├── Content/                       Briefing, Generierung, Style Mimic, Blockausgabe
│   ├── Quality/                       Qualitätsprofile, Prüfungen, Berichte
│   ├── Media/                         (Ausbau 2)
│   ├── Publishing/                    Entwurfserstellung, später Veröffentlichungsregeln
│   ├── Workflow/                      Abläufe, Schritte, Status, Wiederaufnahme
│   ├── Integrations/
│   │   ├── AI/                        AiClient-Schnittstelle, Anbieteradapter
│   │   ├── RankMath/
│   │   ├── Elementor/                 nur Lesezugriff
│   │   ├── SearchConsole/
│   │   └── KeywordProviders/          CSV/Excel, GSC, Textfocus, KI-Websuche, später Trends/DataForSEO/Screpy
│   └── Infrastructure/
│       ├── Database/                  Schema, Migrationen, Repositories
│       ├── Queue/                     Action-Scheduler-Adapter
│       ├── Logging/                   Protokoll mit Schwärzung von Geheimnissen
│       └── Security/                  Berechtigungen, Verschlüsselung
├── assets/                            Admin-Oberfläche (React über @wordpress/scripts)
├── languages/
└── tests/
    ├── Unit/
    └── Integration/
```

Die fachliche Kernschicht (`Entity`, `TopicPlanning`, `Content`, `Quality`) greift nur über Schnittstellen auf WordPress, Datenbank und KI zu. Dadurch bleibt sie ohne WordPress testbar.

---

## 4. Datenbankschema

Alle Tabellen tragen das Präfix `{$wpdb->prefix}lsec_`. Sie werden über `dbDelta()` angelegt und über eine gespeicherte Schemaversion (`lsec_db_version`) migriert. Zeitangaben werden in UTC gespeichert. Statuswerte werden als `VARCHAR` gespeichert und im Code gegen Konstanten geprüft, damit neue Werte ohne Schemaänderung möglich sind. JSON-Felder werden als `LONGTEXT` gespeichert, weil nicht jede MySQL-/MariaDB-Version einen JSON-Typ zuverlässig unterstützt.

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
| properties | LONGTEXT (JSON) | typabhängige Eigenschaften, z. B. Adresse oder Leistungsumfang |
| external_ids | LONGTEXT (JSON) | z. B. `{"wikidata":"Q…"}` |
| status | VARCHAR(20) INDEX | `candidate`, `probable`, `confirmed`, `unresolved`, `rejected` |
| confidence | DECIMAL(4,3) | 0–1, von der KI oder aus Belegen |
| origin | VARCHAR(30) | `business_profile`, `ai_extraction`, `manual`, `import` |
| decided_by | BIGINT UNSIGNED NULL | WordPress-Benutzer |
| decided_at, last_verified_at, created_at, updated_at | DATETIME | |

**`lsec_entity_aliases`**: id, entity_id (INDEX), alias, normalized_alias (INDEX), created_at

**`lsec_relations`**

| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| source_entity_id, target_entity_id | BIGINT UNSIGNED INDEX | gerichtet |
| relation_type | VARCHAR(50) | z. B. `offers`, `owns_brand`, `located_in`, `serves_area`, `belongs_to_topic`, `member_of`, `competes_with` |
| status, confidence, origin | wie bei Entitäten | |
| created_at, updated_at | DATETIME | |
| | UNIQUE (source_entity_id, relation_type, target_entity_id) | verhindert doppelte Kanten |

**`lsec_sources`**: id, url, url_hash (CHAR(64) UNIQUE), title, source_type (`own_post`, `website`, `register`, `wikidata`, `gsc`, `csv_import`, `ai_search`, `manual`), fetched_at, content_hash, reliability (`high`, `medium`, `low`, `unknown`)

**`lsec_claims`** – Aussagen über Entitäten oder Beziehungen (Fakten ≠ Entitäten)

| Spalte | Hinweis |
|---|---|
| id | PK |
| subject_type, subject_id | `entity` oder `relation` |
| property | z. B. `opening_hours`, `founding_year`, `address` |
| value | LONGTEXT (JSON) |
| valid_from, valid_to | optionaler Gültigkeitszeitraum |
| status | `candidate`, `confirmed`, `disputed`, `rejected` |
| created_at, updated_at | |

Widersprüchliche Aussagen werden als mehrere Zeilen mit Status `disputed` geführt und nicht überschrieben.

**`lsec_evidence`** – Belege

| Spalte | Hinweis |
|---|---|
| id | PK |
| source_id | Verweis auf Quelle |
| target_type, target_id | `entity`, `relation` oder `claim` |
| excerpt | kurze Fundstelle aus der Quelle |
| locator | z. B. Abschnitt, Selektor, Seitenzahl |
| quality | `strong`, `medium`, `weak` |
| captured_at | |

**`lsec_mentions`** – Fundstellen von Entitäten

| Spalte | Hinweis |
|---|---|
| id | PK |
| entity_id | INDEX |
| object_type, object_id | `post`, `term`, `attachment`, `external` (dann `source_id`) |
| location | `title`, `heading`, `body`, `meta`, `schema`, `alt_text` |
| excerpt | |
| created_at | |

### 4.2 Unternehmensprofil

Das Unternehmensprofil wird nicht doppelt gespeichert. Unternehmen, Standorte, Leistungen und Servicegebiete sind Entitäten mit `origin = business_profile` und `status = confirmed`. Die Eingabemaske im Backend schreibt direkt in das Entity-Modell. Öffnungszeiten und Adressen sind Aussagen (`lsec_claims`) mit der Quelle `manual`.

### 4.3 Keywords und Themen

**`lsec_keywords`**: id, keyword, normalized (INDEX), locale (z. B. `de_DE`, `de_AT`, `de_CH`), region (NULL oder Ort/PLZ), intent (`informational`, `commercial`, `transactional`, `navigational`, `local`), first_seen_at, updated_at; UNIQUE (normalized, locale, region)

**`lsec_keyword_metrics`** – Kennzahlen je Quelle und Zeitraum

| Spalte | Hinweis |
|---|---|
| id | PK |
| keyword_id | INDEX |
| source | `gsc`, `textfocus`, `csv:keyword_revealer`, `csv:keyword_chef`, `xlsx:textfocus`, `csv:other`, `trends`, `dataforseo` |
| period_start, period_end | |
| search_volume | NULL = unbekannt (nie 0 als Ersatz) |
| difficulty, cpc | NULL erlaubt |
| impressions, clicks, ctr, position | aus der Search Console |
| page_url | rankende Seite (GSC) |
| trend | LONGTEXT (JSON), Saisonverlauf; `{"labeled": false, "values": [...]}`, wenn die Quelle keine Monate nennt (6.6) |
| import_batch_id | Verweis auf den Import |
| created_at | |

**`lsec_imports`**: id, source, filename, rows_total, rows_imported, rows_skipped, column_mapping (JSON), created_by, created_at

**`lsec_topics`**

| Spalte | Hinweis |
|---|---|
| id | PK |
| title, intent, format | `format`: `local_landing`, `article`, `guide`, `seasonal`, `other` |
| region | lokaler Bezug |
| priority_score | 0–100 |
| score_breakdown | JSON mit den Einzelwerten (Abschnitt 6.4) |
| rationale | Begründung im Klartext |
| research_basis | JSON mit Quellen-IDs und Keyword-IDs |
| overlap | JSON: überschneidende Beiträge mit Ähnlichkeitswert |
| status | `proposed`, `approved`, `rejected`, `in_production`, `produced`, `discarded` |
| post_id | erzeugter Beitrag |
| created_at, updated_at | |

**`lsec_topic_entities`**: topic_id, entity_id, role (`primary`, `secondary`) – PK (topic_id, entity_id)  
**`lsec_topic_keywords`**: topic_id, keyword_id, role (`primary`, `secondary`) – PK (topic_id, keyword_id)

**`lsec_briefs`**: id, topic_id, version, payload (JSON, Abschnitt 8.4), created_at

### 4.4 Workflow, KI und Qualität

**`lsec_runs`** – ein Ablauf (z. B. „Beitrag zu Thema 42 erstellen“)

| Spalte | Hinweis |
|---|---|
| id, uuid | |
| type | `content_production`, `entity_extraction`, `topic_research`, `keyword_sync`, `content_index` |
| subject_type, subject_id | z. B. `topic`, 42 |
| status | Abschnitt 7.2 |
| current_step | Name des aktuellen Schritts |
| idempotency_key | CHAR(64) UNIQUE – verhindert doppelte Abläufe |
| context | JSON mit Zwischenergebnissen (oder Verweise darauf) |
| locked_until, lock_token | Sperre gegen parallele Bearbeitung |
| error_code, error_message | geschwärzt, ohne Geheimnisse |
| created_by, created_at, updated_at, finished_at | |

**`lsec_run_steps`**: id, run_id (INDEX), step, status (`pending`, `running`, `done`, `failed`, `skipped`), attempt, max_attempts, input_hash, output (JSON), error_code, error_message, started_at, finished_at; UNIQUE (run_id, step)

**`lsec_ai_calls`**: id, run_id, step_id, task, provider, model, input_tokens, output_tokens, cost_estimate, duration_ms, status (`ok`, `retryable_error`, `fatal_error`, `invalid_output`), request_hash, created_at. Vollständige Eingaben und Antworten werden nur im Debug-Modus und zeitlich begrenzt gespeichert (Datenminimierung).

**`lsec_provider_calls`** – Aufrufe externer Datenanbieter (Textfocus, später DataForSEO, Screpy)

| Spalte | Hinweis |
|---|---|
| id | PK |
| provider, endpoint | z. B. `textfocus`, `tf_keyword` |
| request_hash | CHAR(64) INDEX – Anfrage aus Endpunkt, Sprache und normalisierten Parametern; Grundlage für den Zwischenspeicher |
| credits_used | laut Antwort des Anbieters |
| credits_remaining | laut Antwort, für die Anzeige im Backend |
| status | `ok`, `cached`, `budget_exceeded`, `rate_limited`, `error` |
| response_ref | Verweis auf die gespeicherten Ergebnisse (z. B. Import-ID) |
| cached_until | Ablauf des Zwischenspeichers |
| run_id | auslösender Ablauf |
| created_at | |

**`lsec_quality_reports`**: id, post_id, run_id, profile, score, passed (BOOL), blocking_issues (JSON), warnings (JSON), checks (JSON), created_at

**`lsec_style_profiles`**: id, version, profile (JSON, Abschnitt 8.6), inputs (JSON: Beitrags-IDs, Beispieltexte, Regeln), is_active, created_by, created_at. Es gibt genau ein aktives Profil (globaler Style Mimic); ältere Versionen bleiben für die Nachvollziehbarkeit erhalten.

### 4.5 WordPress-Daten

| Datenart | Speicherort |
|---|---|
| Einstellungen | Options API, ein Array `lsec_settings` (autoload nur für kleine, häufig gelesene Werte) |
| Geheimnisse | `wp-config.php`-Konstante oder verschlüsselte Option (Abschnitt 11) |
| Beitragsbezug | Post-Meta: `_lsec_run_id`, `_lsec_topic_id`, `_lsec_quality_report_id`, `_lsec_generated_at`, `_lsec_primary_entities` |
| Kategorien, Schlagwörter | WordPress-Taxonomien; neue Kategorien nur nach Freigabe |

### 4.6 Deinstallation

Deaktivieren entfernt keine Daten. `uninstall.php` löscht Tabellen und Optionen nur, wenn der Administrator die Option „Daten bei Deinstallation entfernen“ aktiviert hat. Erzeugte Beiträge werden nie gelöscht.

---

## 5. Inhaltsindex bestehender Beiträge

Für Überschneidungsprüfung, interne Links und Entity-Fundstellen braucht das Plugin einen Index der vorhandenen Inhalte.

- Ein Ablauf `content_index` liest veröffentlichte Beiträge und Seiten in Stapeln (Vorschlag: 20 pro Schritt).
- Je Beitrag werden Titel, Überschriften, bereinigter Fließtext, Kategorien, Rank-Math-Fokus-Keyword und ein Inhalts-Hash erfasst.
- **Elementor:** Ist eine Seite mit Elementor gebaut, wird der Text aus dem Post-Meta `_elementor_data` (JSON) extrahiert. Elementor-Inhalte werden nie geschrieben.
- Geänderte Beiträge werden über `save_post` zur Neuindizierung vorgemerkt.
- Die Ähnlichkeit wird zunächst über Keyword- und Entity-Überschneidung sowie die Search-Console-Zuordnung (welche Seite rankt für welche Anfrage) bestimmt. Ein Vektorindex mit Embeddings ist nicht Teil des MVP und wird erst geprüft, wenn diese Methode nicht ausreicht.

---

## 6. Keyword-Datenquellen

### 6.1 Gemeinsame Schnittstelle

```php
interface KeywordSource {
    public function id(): string;              // z. B. 'gsc', 'csv', 'ai_search'
    public function capabilities(): array;     // 'volume', 'queries', 'trend', 'serp', 'difficulty'
    public function isAvailable(): bool;       // verbunden bzw. konfiguriert?
    public function fetch(KeywordQuery $q): KeywordResultSet;
}
```

Das Recherchemodul fragt alle verfügbaren Quellen ab und führt die Ergebnisse in `lsec_keywords` und `lsec_keyword_metrics` zusammen. Jede Kennzahl behält ihre Quelle. Fehlt eine Kennzahl, wird sie in Auswertungen als „unbekannt“ angezeigt.

### 6.2 Quellen im MVP

| Quelle | Daten | Umsetzung |
|---|---|---|
| **Google Search Console** | Suchanfragen, Seiten, Impressionen, Klicks, CTR, Position; filterbar nach Land | Search Analytics API, Dimensionen `query`, `page`, `country`. **Vorschlag für den MVP:** Anbindung über ein Google-Dienstkonto, das in der Search Console als Nutzer der Property eingetragen wird. Das spart einen eigenen OAuth-Ablauf. Ein OAuth-Ablauf folgt mit Ausbau 3 (GA4, Unternehmensprofil). Abgleich: täglich, rollierend 28 und 90 Tage. |
| **CSV-/Excel-Import** | Keywords mit Suchvolumen, Schwierigkeit, CPC – je nach Tool | Upload im Backend mit Spaltenzuordnung. Vorlagen für Keyword Revealer (CSV), Keyword Chef (CSV) und die Tabellen-Exporte von Textfocus (Excel: Entitäten, Semantik, Wettbewerber). Erkennung von Trennzeichen (`;` und `,`), Kodierung (UTF-8 mit/ohne BOM, Windows-1252) und deutschen Zahlenformaten (`1.200`). Importregeln je Quelle in 6.6. |
| **Textfocus-API** | Suchvolumen, CPC, Schwierigkeit, verwandte Keywords, Wettbewerber, Semantik, Entitäten einer Seite | Eigener Adapter, Abschnitt 6.5. Ob Suchvolumina im vorhandenen Tarif enthalten sind, wird im Machbarkeitstest geprüft (M16). |
| **KI-Websuche** | Suchergebnisse, Wettbewerber, Suchintention, regionale Besonderheiten | Über das Websuche-Werkzeug des KI-Anbieters. Liefert keine Suchvolumina. Ergebnisse werden als Quellen (`ai_search`) mit URL gespeichert. |

### 6.3 Spätere Quellen

- **Google Trends API (Alpha):** relatives Suchinteresse, Regionen, Saisonalität. Zugang wird beantragt; Umsetzung erst bei Freigabe.
- **DataForSEO:** Suchvolumen, Keyword-Ideen, SERP-Daten. Abrechnung pro Abfrage; nur nach ausdrücklicher Aktivierung und mit Kostenanzeige je Abfrage.
- **Screpy (Ausbau 3):** REST-API (`https://api.screpy.com/v1`, Bearer-Token) mit Rank Tracker (Länder, Sprachen, Geräte, Verlauf), Backlinks und Backlink-Lücken, Site-Audit per Crawl, Core Web Vitals und KI-Sichtbarkeit. **Keine** Keyword-Recherche oder Suchvolumina. Die Website muss in Screpy als Projekt angelegt sein; neue Projekte und Keywords verbrauchen Tarifkontingent. Search-Console-Daten liefert Screpy nur per MCP, nicht über REST. Offen: ortsgenaues Ranking (die Doku nennt nur Länder) und die genauen Endpunkte aus dem OpenAPI-Schema.
- **Indexly (Ausbau 3, optional):** API (`X-API-Key`) nur für Sichtbarkeit in KI-Antworten: Verlauf und Wettbewerbervergleich. Überschneidet sich mit Screpy; es wird höchstens einer der beiden für KI-Sichtbarkeit angebunden, bevorzugt Screpy.

| Quelle | Rolle | Ausbaustufe |
|---|---|---|
| Search Console | eigene Suchanfragen, Impressionen, Positionen | MVP |
| CSV-/Excel-Import | Volumina und Tabellen aus Tool-Exporten | MVP |
| Textfocus-API | Volumina (Tarif prüfen), verwandte Keywords, Wettbewerber, Semantik, Entitäten | MVP |
| KI-Websuche | Suchergebnisse, Suchintention, Quellen | MVP |
| Google Trends | Saisonalität, Regionen | Ausbau 2 (bei Zugang) |
| DataForSEO | Volumina, SERP-Daten | Ausbau 2, optional |
| Screpy | Ranking-Verlauf, Backlinks, Site-Audit, KI-Sichtbarkeit | Ausbau 3 |
| Indexly | KI-Sichtbarkeit | Ausbau 3, optional |

### 6.4 Priorisierung ohne verlässliches Suchvolumen

Da Suchvolumina im MVP nur aus CSV-Importen kommen, wird die Priorität aus mehreren Einzelwerten berechnet. Jeder Einzelwert wird in `score_breakdown` gespeichert und im Backend angezeigt.

| Faktor | Herkunft | Gewicht (Vorschlag) |
|---|---|---|
| Geschäftsrelevanz | Bezug zu bestätigten Leistungen und Standorten | 30 % |
| Nachfragesignal | Suchvolumen (CSV) oder Impressionen (GSC), normalisiert | 25 % |
| Inhaltslücke | Thema bzw. Entität bisher nicht abgedeckt | 20 % |
| Lokaler Bezug | Servicegebiet, Ort, regionale Besonderheit | 15 % |
| Saisonalität | Zeitpunkt passend zur Saison | 10 % |

Fehlt ein Faktor, wird er nicht als 0 gewertet. Die übrigen Gewichte werden anteilig hochgerechnet, und das Thema erhält den Hinweis „eingeschränkte Datenbasis“. Die Gewichte sind in den Einstellungen anpassbar.

Ein Thema wird **nicht** vorgeschlagen, wenn die Überschneidung mit einem bestehenden Beitrag über einem Schwellenwert liegt (Vorschlag: gleiche Haupt-Entität und gleiches Haupt-Keyword) oder wenn keine Quelle das Thema stützt.

### 6.5 Textfocus-Adapter

| Punkt | Festlegung |
|---|---|
| Zugang | `POST https://www.textfocus.net/apis/<endpoint>/`, Parameter `key`; Antworten als JSON mit `creditUsed` und `credits` |
| Schlüssel | Konstante `LSEC_TEXTFOCUS_API_KEY` in `wp-config.php` mit Vorrang, sonst verschlüsselte Option (Abschnitt 11) |
| Einrichtung | `tf_account` (0 Credits): API-Zugang (`pack.apiAccess`), Tarif und Restguthaben anzeigen. `tf_langs`: Sprachcode für Deutschland, Österreich und Schweiz ermitteln und speichern |
| Endpunkte im MVP | `tf_keyword` (Volumen, CPC, Schwierigkeit; mehrere Keywords pro Aufruf), `tf_related` (bis zu 100 verwandte Begriffe), `tf_competition` (Wettbewerber), `tf_semantic` (Begriffe der Wettbewerber), `tf_seo` (On-Page-Analyse und Entitäten einer URL, abhängig vom Tarif) |
| Später | `tf_structured_data`, `tf_perf`, `tf_links`, `tf_geo`, `tf_seo_bulk` für Berichte |
| Credits | 1 Credit pro Aufruf (außer `tf_account`, `tf_endpoints`, `tf_history`). Tagesbudget einstellbar; **Vorschlag:** 25 bei 30 Aufrufen pro Tag im Classic-Tarif, damit Platz für manuelle Abfragen bleibt. Bei erreichtem Budget: Status `budget_exceeded`, Ablauf wartet auf den nächsten Tag |
| Ratenlimit | Laut Doku 30 Aufrufe pro Tag (Classic) bzw. 1 Aufruf pro Minute (Agency). Der Adapter hält mindestens 60 Sekunden Abstand zwischen Aufrufen, unabhängig vom Tarif |
| Bündelung | `tf_keyword` wird mit bis zu N Keywords pro Aufruf gefüllt (N im Machbarkeitstest ermitteln), statt einen Aufruf pro Keyword zu verbrauchen |
| Zwischenspeicher | Jede Anfrage wird über `request_hash` in `lsec_provider_calls` erkannt. **Vorschlag:** Volumina 30 Tage, Wettbewerber und Semantik 14 Tage, On-Page-Analysen 7 Tage. `force_refetch` nur auf ausdrücklichen Wunsch des Administrators |
| Callbacks | `tf_competition` und `tf_semantic` bieten eine Callback-URL. Im MVP nicht genutzt: Abfrage direkt mit Zeitlimit 60 Sekunden. Ein Callback bräuchte einen öffentlichen REST-Endpunkt mit geheimem Token und folgt erst bei Bedarf |
| Entitäten | Von Textfocus erkannte Entitäten (mit Wikidata-ID) werden immer als Kandidaten gespeichert, nie als bestätigt (siehe 8.3) |
| Fehler | Ratenlimit → Wiederholung nach Wartezeit; fehlender API-Zugang oder ungültiger Schlüssel → sofort `failed` mit Hinweis im Backend |

### 6.6 Importregeln je Quelle

Erkenntnisse aus der Testauswertung mit echten Exporten (17.10.2026):

1. **Nullwerte je Quelle bewerten.** Keyword Chef liefert 0, wenn keine Daten vorliegen. Sind alle Volumina einer Datei 0, werden sie als „unbekannt“ (`NULL`) gespeichert und der Import erhält einen Hinweis. Die Regel ist je Quelle einstellbar.
2. **Verlaufswerte ohne Monate.** Keyword Revealer schreibt 12 Monatswerte kommagetrennt in ein Feld, ohne Monatsangabe. Der Import speichert sie als Trend mit `labeled: false`. Das Plugin ordnet ihnen keine Monate zu und nutzt sie nur für die Spannweite (Minimum, Maximum), nicht für die Saisonbewertung.
3. **Textfocus-Berichte als PDF werden nicht importiert.** Der Gesamtbericht ist ein Bild-PDF. Importiert werden nur die Excel-Exporte einzelner Tabellen oder die Daten über die API.

### 6.7 Prüfung auf Tippfehler

Ein falsch geschriebenes Ausgangs-Keyword („flächenreingung“) hat in der Testauswertung eine ganze Textfocus-Analyse verzerrt. Bevor ein neues Keyword an einen bezahlten Anbieter geht, vergleicht das Plugin es mit vorhandenen Keywords, Leistungsnamen und Entitäten (Editierabstand, Umlaut-Varianten wie „ae“/„ä“). Bei einem naheliegenden Treffer fragt es nach, statt Credits zu verbrauchen.

---

## 7. Aufgabensteuerung

### 7.1 Architektur

- **Warteschlange:** Action Scheduler, eingebunden über Composer. Jeder Ablaufschritt ist eine eigene Aktion in der Gruppe `lsec`.
- **Ausführung:** Der Server-Cronjob ruft WP-CLI auf. Über die Kommandozeile gelten die Laufzeitgrenzen des Webservers nicht, was lange KI-Aufrufe absichert.
- **Fachliche Steuerung:** Das Plugin hält Status, Zwischenergebnisse und Reihenfolge selbst in `lsec_runs` und `lsec_run_steps`. Der Action Scheduler ist nur der Auslöser. Damit lässt sich die Warteschlange später austauschen, ohne den Workflow zu ändern.

**Cronjob (Plesk „Geplante Aufgaben“), alle 5 Minuten** – festgelegt nach Etappe 1 des Machbarkeitstests:

```bash
wp --path=/var/www/vhosts/<domain>/httpdocs lsec cron
```

`wp lsec cron` ruft intern `action-scheduler run --group=lsec --batch-size=3 --batches=1` auf und erledigt vorher zwei Dinge, die sich im Test als nötig erwiesen haben (18.10.2026):

- **Datenmigration von Action Scheduler:** Auf einer Website, auf der Action Scheduler neu ist, laufen auf eine Gruppe gefilterte Durchgänge so lange mit Fehler („group does not exist“), bis Action Scheduler seine einmalige Migration abgeschlossen hat. Diese Migration läuft selbst als Aktion einer anderen Gruppe und würde mit dem Gruppenfilter nie ausgeführt. `wp lsec cron` führt sie deshalb beim ersten Lauf direkt aus und arbeitet die Warteschlange dieses eine Mal ungefiltert ab.
- **Verwaiste Durchgänge:** Stürzt ein Durchgang ab, bleiben seine Aufträge beansprucht. Action Scheduler erlaubt standardmäßig nur einen Durchgang gleichzeitig und verweigert dann jeden weiteren, bis seine eigene Aufräumroutine greift. `wp lsec cron` gibt Aufträge, die länger als `--stale-after` (Standard 600 Sekunden, gleich der Sperrdauer) beansprucht sind, vorher frei.

Zusätzlich `wp cron event run --due-now`, wenn `DISABLE_WP_CRON` gesetzt ist und andere Plugins WP-Cron nutzen. Das Plugin zeigt im Backend den Zeitpunkt der letzten Cron-Ausführung und warnt, wenn sie länger als 15 Minuten zurückliegt. Rank Math bringt selbst Action Scheduler mit; beide Kopien vertragen sich (geprüft mit Rank Math 1.0.280 und Action Scheduler 3.9.3).

### 7.2 Status eines Ablaufs

```text
queued ──► running ──► succeeded
              │  ▲
              │  └── (nächster Schritt geplant)
              ├──► waiting     (wartet auf Administrator, z. B. Themenfreigabe)
              ├──► blocked     (Qualitätsprüfung nicht bestanden – kein Fehler, sondern Ergebnis)
              ├──► failed      (Wiederholungen ausgeschöpft oder nicht wiederholbarer Fehler)
              └──► cancelled   (durch Administrator)
```

`failed` und `blocked` lassen sich vom Administrator gezielt ab dem betroffenen Schritt fortsetzen. Bereits abgeschlossene Schritte (`done`) werden nicht erneut ausgeführt.

### 7.3 Ablauf `content_production`

| Nr. | Schritt | Inhalt | KI |
|---|---|---|---|
| 1 | `load_topic` | Thema laden, Status `approved` prüfen, auf `in_production` setzen | – |
| 2 | `gather_context` | Entitäten, Beziehungen, Aussagen mit Belegen, Keywords, überschneidende Beiträge, Kandidaten für interne Links | – |
| 3 | `create_brief` | Beitragsbriefing (8.4) | ja |
| 4 | `generate_draft` | Beitragstext als Gutenberg-Blöcke mit Style Mimic (8.5) | ja |
| 5 | `enrich` | interne Links prüfen und einsetzen, Kategorien und Schlagwörter, Rank-Math-Angaben, CTA | teilweise |
| 6 | `quality_check` | regelbasierte Prüfungen und KI-Bewertung (Abschnitt 10) | ja |
| 7 | `create_post` | WordPress-Entwurf anlegen bzw. aktualisieren | – |
| 8 | `finalize` | Thema auf `produced`, Fundstellen eintragen, Bericht | – |

Bei `quality_check` mit blockierenden Mängeln wird trotzdem ein Entwurf angelegt, aber mit sichtbarem Prüfbericht und dem Status `blocked`. Der Administrator kann ihn bearbeiten oder verwerfen. Für die spätere automatische Veröffentlichung kommt ein solcher Beitrag nie in Frage.

### 7.4 Sperren, Wiederholungen und Doppelschutz

- **Ablaufsperre:** Ein Schritt startet nur, wenn ein atomares Update gelingt:  
  `UPDATE … SET lock_token = ?, locked_until = NOW() + INTERVAL 10 MINUTE WHERE id = ? AND (locked_until IS NULL OR locked_until < NOW())`.  
  Eine abgelaufene Sperre gilt als abgebrochener Lauf; der Schritt wird wiederholt.
- **Idempotenz:** `idempotency_key = sha256(type + subject + Briefing-Version)`. Ein zweiter Start desselben Ablaufs findet den vorhandenen Lauf, statt einen neuen anzulegen.
- **Kein doppelter Beitrag:** `create_post` sucht vor dem Anlegen nach einem Beitrag mit `_lsec_run_id` dieses Laufs und aktualisiert ihn gegebenenfalls.
- **Wiederholungen (Vorschlag):** maximal 3 Versuche je Schritt mit Wartezeiten von 1, 5 und 30 Minuten.

| Fehlerart | Verhalten |
|---|---|
| Netzwerkfehler, Zeitüberschreitung, HTTP 429/5xx | wiederholen |
| Ungültige KI-Antwort (Struktur) | einmal mit Korrekturhinweis wiederholen, dann `failed` |
| Authentifizierung, fehlender Schlüssel, HTTP 400/401/403 | sofort `failed`, Hinweis im Backend |
| Fachlicher Abbruch (z. B. zu wenig Belege) | `blocked` bzw. Thema `discarded` mit Begründung |

- **Mengengrenzen (Vorschlag):** höchstens 3 gleichzeitige Abläufe, höchstens N Beiträge pro Tag (einstellbar), Tagesgrenze für KI-Aufrufe als zusätzliche Bremse.

---

## 8. KI-Schnittstelle und Ergebnisverträge

### 8.1 Schnittstelle

```php
interface AiClient {
    public function run(AiRequest $request): AiResponse;
}

final class AiRequest {
    public string  $task;          // z. B. 'entity_extraction'
    public string  $system;
    public array   $messages;
    public ?array  $jsonSchema;    // erwartete Struktur
    public array   $tools;         // z. B. ['web_search']
    public int     $maxOutputTokens;
    public int     $timeoutSeconds; // Vorschlag: 120
}
```

`AiResponse` enthält das geprüfte Ergebnis, Token-Verbrauch, Dauer und gegebenenfalls Quellen aus der Websuche. Jeder Aufruf wird in `lsec_ai_calls` protokolliert.

**Anforderungen an den KI-Anbieter (Auswahlkriterien):**

- strukturierte Ausgabe nach JSON-Schema
- Websuche als Werkzeug mit Quellenangaben
- gute deutsche Textqualität
- abrufbare Verbrauchs- und Kostendaten
- Vertrag zur Auftragsverarbeitung und Angaben zum Datenstandort (DSGVO, Zielmarkt D-A-CH)

### 8.1a Unterstützte Anbieter

Festgelegt sind **OpenRouter, Anthropic und OpenAI**. Für jeden Anbieter gibt es einen eigenen Adapter hinter der Schnittstelle `AiClient`. Die Anforderung „ein festes KI-Modell“ bleibt erhalten: Der Administrator wählt in den Einstellungen genau einen Anbieter und ein Modell, das dann für alle Aufgaben gilt.

| Punkt | OpenRouter | Anthropic (direkt) | OpenAI (direkt) |
|---|---|---|---|
| Schnittstelle | Chat Completions, weitgehend OpenAI-kompatibel | Messages API | Responses API |
| Strukturierte Ausgabe | `response_format` mit `json_schema`; abhängig vom gewählten Modell | `output_config.format` mit `json_schema` | `json_schema` mit `strict` |
| Websuche | Plugin `web` | Werkzeug `web_search` | Werkzeug `web_search` |
| Kosten je Aufruf | Feld `cost` in `usage` der Antwort | aus Token-Verbrauch und hinterlegter Preistabelle berechnet | aus Token-Verbrauch und hinterlegter Preistabelle berechnet |
| Ausgabenbegrenzung | Kreditlimit je API-Schlüssel | Ausgabenlimit in der Konsole | Budget/Limits im Projekt |
| Datenschutz | zusätzlicher Vermittler; Weiterleitung an Modellanbieter je nach Routing | AV-Vertrag des Anbieters | AV-Vertrag; Datenhaltung in Europa für neu angelegte Projekte mit EU-Region möglich |

**Vorschlag Reihenfolge:** OpenRouter als erster Adapter. Ein Schlüssel deckt Modelle von Anthropic und OpenAI ab, die Kosten kommen direkt mit jeder Antwort, und das Kreditlimit je Schlüssel erfüllt die Anforderung, dass die Ausgabenbegrenzung beim Anbieter liegt. Die direkten Adapter für Anthropic und OpenAI folgen für Websites, bei denen Datenschutz oder Datenstandort einen direkten Vertrag mit dem Modellanbieter verlangen.

Für OpenRouter wird im Adapter festgelegt, dass nur Modelle mit Unterstützung für strukturierte Ausgabe auswählbar sind. Die Routing-Einstellungen (`provider`) werden so gesetzt, dass nur Anbieter ohne Datenspeicherung zum Training genutzt werden; die genauen Optionen werden im Machbarkeitstest geprüft.

Preistabellen für die Kostenberechnung bei Anthropic und OpenAI werden als bearbeitbare Einstellung gepflegt, da sich Preise ändern.

### 8.2 Validierung

**Recherche und Struktur getrennt:** Aufgaben mit Websuche laufen in zwei Aufrufen. Der erste Aufruf recherchiert mit Websuche und liefert Text mit Quellenangaben; die Quellen werden in `lsec_sources` gespeichert. Der zweite Aufruf wandelt das Ergebnis ohne Werkzeuge in die geforderte JSON-Struktur um. Grund: Bei Anthropic sind strukturierte Ausgabe und Quellenangaben (Citations) im selben Aufruf nicht kombinierbar, und auch bei den anderen Anbietern ist die Trennung robuster und für alle Adapter gleich.

1. JSON-Struktur gegen das Schema prüfen (Pflichtfelder, Typen, erlaubte Werte).
2. Referenzen prüfen: Jede genannte Entity-ID, Keyword-ID und Beitrags-ID muss existieren.
3. Fachlich prüfen: Erst die Qualitätsprüfung (Abschnitt 10) entscheidet über die inhaltliche Eignung. Eine strukturell gültige Antwort ist nicht automatisch korrekt.

### 8.3 Entity-Erkennung (`entity_extraction`)

```json
{
  "entities": [
    {
      "ref": "e1",
      "existing_entity_id": null,
      "type": "service",
      "name": "Dachsanierung",
      "aliases": ["Dach sanieren"],
      "description": "…",
      "confidence": 0.82,
      "evidence": [{ "source_ref": "post:123", "excerpt": "…", "location": "heading" }]
    }
  ],
  "relations": [
    { "source": "org:1", "type": "offers", "target": "e1", "confidence": 0.9,
      "evidence": [{ "source_ref": "post:123", "excerpt": "…" }] }
  ],
  "ambiguities": [
    { "ref": "e2", "reason": "Gleichnamige Firma in anderem Ort", "candidates": ["…"] }
  ]
}
```

Neue Entitäten werden immer mit `status = candidate` gespeichert. Mehrdeutige Entitäten erhalten `unresolved`.

**Abgleich über Kontext:** Externe Zuordnungen (Wikidata-IDs aus Textfocus oder eigener Abfrage) werden gegen den Kontext geprüft, bevor sie den Status `probable` erhalten. Beispiele aus der Testauswertung: Textfocus ordnete „Böden“ (Bodenbeläge) der Bodenkunde zu, und „Burgdorf“ gibt es in Niedersachsen zweimal (Region Hannover und Landkreis Wolfenbüttel). Geprüft werden Typ (Ort, Fachbegriff, Unternehmen), übergeordnete Verwaltungseinheit und Nähe zum Servicegebiet. Bei mehreren passenden Kandidaten bleibt der Status `unresolved`, bis der Administrator entscheidet.

### 8.4 Themenrecherche (`topic_research`) und Briefing (`create_brief`)

Themenvorschlag:

```json
{
  "topics": [
    {
      "title": "…", "intent": "local", "format": "local_landing", "region": "Lüneburg",
      "primary_entity_ids": [12], "secondary_entity_ids": [4, 19],
      "primary_keyword_ids": [301], "secondary_keyword_ids": [302, 305],
      "rationale": "…",
      "evidence_source_ids": [55, 60],
      "overlap_post_ids": [],
      "insufficient_data": false
    }
  ]
}
```

Briefing:

```json
{
  "working_title": "…",
  "search_intent": "…",
  "audience": "…",
  "outline": [{ "heading": "…", "level": 2, "purpose": "…", "entity_ids": [12] }],
  "required_facts": [{ "claim_id": 88, "use": "…" }],
  "facts_to_avoid": ["unbelegte Preisangaben"],
  "internal_link_targets": [{ "post_id": 210, "anchor_hint": "…" }],
  "elements": ["faq", "table"],
  "length": { "min_words": 900, "max_words": 1500 },
  "cta": "…"
}
```

### 8.5 Beitragserstellung (`generate_draft`)

```json
{
  "title": "…",
  "blocks": "<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->",
  "excerpt": "…",
  "used_entity_ids": [12, 4],
  "used_claim_ids": [88],
  "unsupported_statements": [],
  "internal_links": [{ "post_id": 210, "anchor": "…" }],
  "seo": {
    "title": "…", "description": "…", "focus_keyword": "…",
    "secondary_keywords": ["…"], "slug": "…"
  },
  "taxonomy": { "category_ids": [5], "new_category_suggestions": [], "tags": ["…"] }
}
```

Erlaubte Blöcke im MVP (Vorschlag): `paragraph`, `heading`, `list`, `list-item`, `table`, `quote`, `separator`, `details` (für FAQ). Das Plugin baut das Block-Markup aus einer Zwischenstruktur selbst zusammen, statt rohes HTML der KI zu übernehmen. So entsteht immer gültiges Markup, und der Inhalt wird mit `wp_kses_post()` bereinigt.

### 8.6 Style Mimic (`style_profile`)

```json
{
  "tone": "…", "address": "Sie", "sentence_length": "kurz bis mittel",
  "vocabulary": { "preferred": ["…"], "avoid": ["…"] },
  "structure_habits": ["…"],
  "rules": ["keine Ausrufezeichen in Überschriften"],
  "examples": [{ "do": "…", "dont": "…" }]
}
```

Das Profil ist im Backend bearbeitbar. Jede Änderung erzeugt eine neue Version.

### 8.7 Qualitätsbewertung (`quality_review`)

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

- Anlage über `wp_insert_post()` mit `post_status = draft`, Autor = auslösender Administrator bzw. konfigurierter Redaktionsbenutzer.
- Inhalt als Gutenberg-Blöcke. Diese werden auch im klassischen Editor korrekt dargestellt.
- Elementor wird für neue Beiträge nicht verwendet, sondern nur für den Inhaltsindex gelesen (Abschnitt 5).
- Neue Kategorien werden nur als Vorschlag gespeichert und erst nach Freigabe angelegt.

### 9.2 Rank Math

- Erkennung über die Rank-Math-Hauptklasse bzw. die Plugin-Konstante.
- Für neue Beiträge werden die Rank-Math-Metafelder gesetzt: `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword` (Haupt-Keyword zuerst, weitere kommagetrennt).
- Die genauen Schlüssel und Formate werden im Machbarkeitstest gegen die aktuelle Rank-Math-Version geprüft, auch die Anzeige in der Rank-Math-Seitenleiste.
- Strukturierte Daten über Rank Math folgen in Ausbau 2.
- Ohne Rank Math werden die SEO-Angaben nur als Vorschlag im Plugin gespeichert und nicht selbst ausgegeben. So entstehen keine doppelten Meta-Tags.
- Die Content Analysis API wird erst genutzt, wenn Plugin-Empfehlungen im Editor erscheinen sollen.

### 9.3 Backend-Oberfläche (MVP)

| Bereich | Inhalt |
|---|---|
| Dashboard | Status, offene Freigaben, letzte Abläufe, KI-Verbrauch, Cron-Zustand |
| Unternehmensprofil | Unternehmen, Standorte, Leistungen, Servicegebiete |
| Entitäten | Tabelle mit Filter nach Typ und Status, Bestätigen/Ablehnen/Zusammenführen, einfache Graphansicht |
| Keywords | Search-Console-Abgleich, CSV-/Excel-Import mit Spaltenzuordnung, Textfocus-Abfragen mit Credit-Anzeige, Übersicht mit Quelle je Kennzahl |
| Themen | Vorschläge mit Priorität und Begründung, Freigabe, Einzel- und Sammelauftrag |
| Abläufe | Status, Schritte, Fehler, Fortsetzen/Abbrechen |
| Style Mimic | Eingaben, generiertes Profil, Versionen |
| Einstellungen | KI, Search Console, Grenzen, Längen je Beitragstyp, Gewichte der Priorisierung |

Die Oberfläche wird mit React über `@wordpress/scripts` und `@wordpress/components` umgesetzt und kommuniziert über den eigenen REST-Namespace `lsec/v1`.

---

## 10. Qualitätsprüfung

### 10.1 Regelbasierte Prüfungen (ohne KI)

| Prüfung | Blockierend |
|---|---|
| Länge innerhalb der Grenzen des Beitragstyps | nein (Warnung) |
| Gültiges Block-Markup | ja |
| Jede verwendete Aussage (`used_claim_ids`) existiert und ist nicht `rejected` | ja |
| `unsupported_statements` ist leer | ja |
| Haupt-Entität ist `confirmed` oder `probable` | ja |
| Interne Links zeigen auf existierende, veröffentlichte Beiträge | ja |
| Keine Überschneidung über dem Schwellenwert mit bestehendem Beitrag | ja |
| Kein bestehender Beitrag mit gleichem oder sehr ähnlichem Titel (Vorschlag: Ähnlichkeit ab 0,85) | ja |
| Lokale Beiträge: Ort wird im Text genannt und mit mindestens zwei belegten lokalen Aussagen verbunden (z. B. Stadtteil, Objektart vor Ort, regionale Besonderheit) | ja |
| Lokale Beiträge: Text unterscheidet sich ausreichend von anderen Beiträgen desselben Typs für andere Orte, nicht nur im Ortsnamen | ja |
| Keine Platzhalter (`{…}`) oder Übersetzungsfehler aus einer Fachbegriffsliste (z. B. „Zahnfleisch“ für Kaugummi) | ja |
| SEO-Titel und Meta-Beschreibung vorhanden und innerhalb der Längen (Vorschlag: 30–60 bzw. 120–160 Zeichen) | nein (Warnung) |
| Fokus-Keyword wird nicht in mehreren Beiträgen als Haupt-Keyword verwendet | nein (Warnung) |
| Wiederholte Absätze oder Floskeln über einem Schwellenwert | nein (Warnung) |

### 10.2 KI-Bewertung

Ergänzend bewertet die KI Suchintention, lokale Relevanz, Entity-Integration, Mehrwert, Lesbarkeit und Übereinstimmung mit dem Style Mimic (8.7).

**Vorschlag Mindestwerte:** keiner der Einzelwerte unter 0,6 und Durchschnitt mindestens 0,75. Die Werte werden in der Testphase anhand der tatsächlichen Korrekturen des Administrators kalibriert.

---

## 11. Sicherheit

- **Berechtigung:** eigene Capability `lsec_manage`, standardmäßig der Rolle Administrator zugewiesen. Alle REST-Routen prüfen sie in `permission_callback`; Admin-Formulare zusätzlich mit Nonces.
- **API-Schlüssel:** Je Anbieter eine Konstante in `wp-config.php` (`LSEC_OPENROUTER_API_KEY`, `LSEC_ANTHROPIC_API_KEY`, `LSEC_OPENAI_API_KEY`); sie hat Vorrang vor der Eingabe im Backend. Ein über das Backend eingegebener Schlüssel wird mit libsodium (`sodium_crypto_secretbox`) verschlüsselt; der Schlüssel dafür wird aus den WordPress-Salts abgeleitet. Das schützt vor dem Auslesen bei einem reinen Datenbank-Leck, nicht bei vollem Serverzugriff. Im Backend wird ein gespeicherter Schlüssel nur maskiert angezeigt.
- **Dienstkonto-Schlüssel der Search Console:** Ablage wie der API-Schlüssel (Konstante mit Dateipfad außerhalb des Webroots bevorzugt).
- **Protokolle:** Ein Schwärzungsfilter entfernt bekannte Geheimnisse und typische Schlüsselmuster aus Protokollen, Fehlermeldungen und Exporten.
- **Externe Daten:** KI-Antworten, CSV-Inhalte und Websuchergebnisse gelten als nicht vertrauenswürdig. Sie werden validiert, bereinigt (`sanitize_*`, `wp_kses_post`) und nie ungeprüft als Code oder SQL verwendet. Alle Datenbankzugriffe laufen über `$wpdb->prepare()`.
- **Prompt-Injection:** Texte aus fremden Websites werden in KI-Anfragen als Daten gekennzeichnet; Anweisungen darin werden nicht befolgt. Aktionen mit Außenwirkung (Veröffentlichung, später Bewertungsantworten) hängen nie allein von einer KI-Antwort ab.
- **Datenschutz:** An den KI-Anbieter gehen nur Inhalte, die für die Aufgabe nötig sind; keine Benutzer- oder Kommentardaten.

---

## 12. Machbarkeitstest

Der Machbarkeitstest ist ein schmaler, lauffähiger Durchstich durch alle Schichten. Er entsteht im späteren Plugin-Repository, damit tragfähiger Code weiterverwendet werden kann. Er wird auf der Staging-Installation durchgeführt.

**Umfang:** ein Thema manuell anlegen → Ablauf `content_production` mit vereinfachten Schritten → Entwurf mit Rank-Math-Angaben. Dazu kommen Search-Console-Abgleich, CSV-Import und Entity-Erkennung für einen Beitrag.

### Prüfpunkte und Abnahmekriterien

| Nr. | Prüfpunkt | Abnahmekriterium |
|---|---|---|
| M1 | Kompatibilität | Plugin aktiviert ohne Fehler auf PHP 8.1 und 8.3, WordPress 6.4 und aktueller Version, zusammen mit Rank Math und Elementor |
| M2 | Datenbank und Migration | Aktivierung legt alle Tabellen an; ein Update von Schemaversion 1 auf 2 ergänzt eine Spalte ohne Datenverlust; Deaktivieren löscht nichts |
| M3 | Server-Cronjob | Eine eingeplante Aktion wird über den WP-CLI-Cronjob innerhalb von 5 Minuten ausgeführt, ohne Websitebesuch |
| M4 | Wiederaufnahme | Wird der Prozess während Schritt 4 abgebrochen, setzt der nächste Lauf bei Schritt 4 fort; Schritte 1–3 werden nicht wiederholt |
| M5 | Doppelschutz | Zwei gleichzeitige Starts für dasselbe Thema ergeben genau einen Ablauf und genau einen Beitrag |
| M6 | Wiederholungen | Ein simulierter HTTP-429-Fehler führt zu einer verzögerten Wiederholung; ein 401-Fehler führt sofort zu `failed` mit verständlicher Meldung |
| M7 | KI-Anbindung | Mit dem OpenRouter-Adapter liefert ein Aufruf mit JSON-Schema ein gültiges Ergebnis; eine absichtlich ungültige Antwort wird einmal korrigiert und sonst sauber abgelehnt; Token-Verbrauch und Kosten stehen in `lsec_ai_calls` |
| M7a | Recherche mit Websuche | Der zweistufige Ablauf (Recherche mit Quellen, danach Strukturierung) liefert ein gültiges Ergebnis; jede Quelle ist mit URL in `lsec_sources` gespeichert |
| M7b | Adapterwechsel | Derselbe Aufruf aus M7 läuft nach Umstellen der Einstellung über den Anthropic- und den OpenAI-Adapter, ohne Änderung am fachlichen Code (sofern die Adapter im Machbarkeitstest schon umgesetzt sind; sonst mindestens als Schnittstellentest mit simulierter Antwort) |
| M8 | Geheimnisse | Der Schlüssel aus `wp-config.php` hat Vorrang; eine Suche nach dem Schlüssel in Datenbank-Protokollen, Fehlermeldungen und Exporten findet nichts |
| M9 | Search Console | Über das Dienstkonto werden die Suchanfragen der letzten 28 Tage für Deutschland abgerufen und gespeichert |
| M10 | CSV-/Excel-Import | Die Testdateien vom 17.10.2026 (Keyword Revealer, Keyword Chef) und ein Excel-Export einer Textfocus-Tabelle werden mit Umlauten korrekt importiert; die Nullwerte von Keyword Chef erscheinen als „unbekannt“; die Monatswerte von Keyword Revealer werden als Trend ohne Monatszuordnung gespeichert |
| M11 | Entity-Erkennung | Für einen bestehenden Beitrag werden Kandidaten mit Fundstellen gespeichert; Bestätigen und Ablehnen im Backend wird protokolliert |
| M12 | Entwurf | Ein Entwurf entsteht mit gültigen Blöcken (keine Warnung „unerwarteter Inhalt“ im Block-Editor), Kategorie, Schlagwörtern und internen Links |
| M13 | Rank Math | SEO-Titel, Meta-Beschreibung und Fokus-Keyword erscheinen in der Rank-Math-Seitenleiste des Entwurfs |
| M14 | Elementor | Der Text einer Elementor-Seite wird für den Inhaltsindex korrekt ausgelesen |
| M15 | Zugriffsschutz | Alle REST-Routen antworten Nicht-Administratoren mit 403 |
| M16 | Textfocus-API | `tf_account` zeigt API-Zugang und Restguthaben; `tf_langs` liefert den Code für Deutschland; `tf_keyword` mit mehreren Keywords liefert Volumina (oder es ist dokumentiert, dass der Tarif sie nicht enthält); eine wiederholte Anfrage wird aus dem Zwischenspeicher bedient und verbraucht kein Credit; bei erreichtem Tagesbudget wartet der Ablauf |
| M17 | Tippfehlerprüfung | „flächenreingung“ wird vor dem Aufruf eines Anbieters als mögliche Schreibvariante von „Flächenreinigung“ erkannt |
| M18 | Entity-Abgleich | „Burgdorf“ erhält ohne weiteren Kontext den Status `unresolved` mit zwei Kandidaten; mit Servicegebiet Region Hannover wird der passende Kandidat `probable` |

Nach dem Machbarkeitstest werden die offenen Entscheidungen (Abschnitt 13) getroffen und als v1.0 der technischen Spezifikation festgeschrieben. Erst danach beginnt die eigentliche MVP-Umsetzung.

---

## 13. Entscheidungsstand

| Entscheidung | Status |
|---|---|
| Eigenständiges, modulares WordPress-Plugin | festgelegt |
| Generierte Inhalte als reguläre WordPress-Beiträge, Gutenberg-Blöcke | festgelegt |
| Eigene Tabellen für Entity-Modell, Themen, Workflow | festgelegt (Schema in Abschnitt 4, Feinschliff im Machbarkeitstest) |
| Keyword-Quellen im MVP: Search Console, CSV-/Excel-Import, Textfocus-API, KI-Websuche | festgelegt |
| Keyword-Quellen später: Google Trends, DataForSEO | vorgesehen |
| Screpy für Ranking-Verlauf, Backlinks, Site-Audit | vorgesehen für Ausbau 3 |
| KI-Sichtbarkeit (Screpy, optional Indexly) als neue Anforderung | offen – Entscheidung des Administrators |
| Suchvolumina im vorhandenen Textfocus-Tarif | offen – Prüfung in M16 |
| Action Scheduler mit WP-CLI-Cronjob (`wp lsec cron`) | bestätigt in Etappe 1 (M3–M5 bestanden, 18.10.2026) |
| Search Console über Dienstkonto im MVP | Vorschlag |
| Rank Math über Metafelder | Vorschlag, Prüfung in M13 |
| KI-Anbieter | festgelegt: OpenRouter, Anthropic, OpenAI (Abschnitt 8.1a) |
| Reihenfolge der Adapter | Vorschlag: zuerst OpenRouter |
| Konkretes Modell | offen – Auswahl nach Testläufen mit deutschen Beispielbeiträgen |
| Bilddienst | offen (Ausbau 2) |
| Mindestwerte der Qualitätsprüfung | Vorschlag, Kalibrierung in der Testphase |
| Umfang und Dauer der Testphase vor Automatikbetrieb | offen (Vorschlag: 20 geprüfte Beiträge innerhalb von 60 Tagen) |

## 14. Nächste Schritte

1. OpenRouter-Schlüssel mit Kreditlimit für die Testumgebung anlegen; Kandidaten-Modelle für den Vergleich festlegen.
2. Zugang zur Google Trends API (Alpha) beantragen.
3. Google-Cloud-Projekt mit Dienstkonto anlegen und in der Search Console der Testwebsite eintragen.
4. Beispiel-Exporte aus Keyword Revealer und Keyword Chef liegen vor (17.10.2026); zusätzlich einen Excel-Export einer Textfocus-Tabelle bereitstellen und `tf_account` für den vorhandenen Tarif aufrufen.
5. Staging-Installation mit Rank Math, Elementor und Cronjob einrichten.
6. GitHub-Repository anlegen und Grundgerüst (Composer, CI, Aktivierung, Schema) erstellen.
7. Machbarkeitstest M1–M18 (einschließlich M7a und M7b) durchführen und Ergebnisse dokumentieren.
