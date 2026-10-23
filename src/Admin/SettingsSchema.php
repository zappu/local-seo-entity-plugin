<?php
/**
 * Beschreibung der Einstellungen für die Oberfläche.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Content\ContentIndex;
use Lsec\Core\Settings;

/**
 * Welche Einstellungen das Backend zeigt, in welcher Gruppe und mit welchem Hinweis.
 * Geprüft wird beim Speichern immer von Settings::set(); die Oberfläche prüft nur vor.
 * Schlüssel gehören nicht hierher: Sie stehen in wp-config.php (Spezifikation 11).
 */
final class SettingsSchema {

	/**
	 * Gruppen mit Feldern. Typen: text, number, select, toggle, json, checkboxes, readonly.
	 *
	 * @var array<string, array{label: string, fields: array<string, array<string, mixed>>}>
	 */
	private const GROUPS = array(
		'ai'         => array(
			'label'  => 'KI',
			'fields' => array(
				'ai_provider'        => array(
					'label' => 'Anbieter',
					'type'  => 'select',
				),
				'ai_model'           => array(
					'label' => 'Hauptmodell',
					'type'  => 'text',
					'help'  => 'Gilt für alle Aufgaben, für die unten kein eigenes Modell eingetragen ist. Bei OpenRouter z. B. openai/gpt-6-luna; Liste mit wp lsec ai models.',
				),
				'ai_writing_model'   => array(
					'label' => 'Schreibmodell',
					'type'  => 'text',
					'help'  => 'Nur für den Text der Beiträge, beim selben Anbieter; Briefing und Bewertung laufen mit dem Hauptmodell. Leer = Hauptmodell. Empfehlung: anthropic/claude-sonnet-5.5 (liest sich natürlicher, etwa 0,12 USD je Text).',
				),
				'ai_review_model'    => array(
					'label' => 'Prüfmodell',
					'type'  => 'text',
					'help'  => 'Nur für die KI-Bewertung der Entwürfe, beim selben Anbieter. Leer = Hauptmodell. Vergleich an einem vorhandenen Entwurf: wp lsec production review <entwurf> --model=<modell>.',
				),
				'ai_research_model'  => array(
					'label' => 'Recherchemodell',
					'type'  => 'text',
					'help'  => 'Nur für die Recherche mit Websuche, beim selben Anbieter. Leer = Hauptmodell. Empfehlung bei OpenRouter: perplexity/sonar-pro (Suchmaschine dann „standard“).',
				),
				'ai_web_max_results' => array(
					'label' => 'Treffer der Websuche',
					'type'  => 'number',
					'step'  => 1,
					'help'  => 'Weniger Treffer senken die Kosten der Recherche deutlich. OpenAI: bis 3 = geringer Suchumfang.',
				),
				'ai_web_engine'      => array(
					'label' => 'Suchmaschine (OpenRouter)',
					'type'  => 'select',
					'help'  => 'standard = Websuche des Modells, wenn es eine hat, sonst Exa. Liefert die Recherche keine Quellen, exa oder parallel wählen: Diese suchen bei jeder Anfrage und geben immer Quellen zurück.',
				),
				'ai_monthly_budget'  => array(
					'label' => 'Monatsbudget',
					'type'  => 'number',
					'step'  => 0.5,
					'unit'  => 'USD',
					'help'  => 'Zusätzliche Bremse neben dem Limit beim Anbieter. Erreicht: Abläufe warten. 0 = ohne Grenze.',
				),
				'ai_run_budget'      => array(
					'label' => 'Budget je Ablauf',
					'type'  => 'number',
					'step'  => 0.1,
					'unit'  => 'USD',
					'help'  => 'Höchstbetrag für einen Ablauf, z. B. einen Beitrag. 0 = ohne Grenze.',
				),
				'ai_prices'          => array(
					'label' => 'Preise (USD je Million Token)',
					'type'  => 'json',
					'help'  => 'Nur für Anthropic und OpenAI direkt nötig; OpenRouter meldet Kosten selbst. Beispiel: {"openai":{"gpt-6.1-sol":{"in":2,"out":10}}}',
				),
				'openai_endpoint'    => array(
					'label' => 'OpenAI-Endpunkt',
					'type'  => 'text',
					'help'  => 'Leer = Standard. Für Projekte mit Datenhaltung in Europa den Endpunkt der EU-Region eintragen.',
				),
			),
		),
		'textfocus'  => array(
			'label'  => 'Textfocus',
			'fields' => array(
				'textfocus_lang'         => array(
					'label' => 'Sprachcode',
					'type'  => 'readonly',
					'help'  => 'Festlegen mit wp lsec textfocus langs --search=german --save=de.',
				),
				'textfocus_daily_budget' => array(
					'label' => 'Tagesbudget',
					'type'  => 'number',
					'step'  => 1,
					'unit'  => 'Credits',
				),
				'textfocus_min_interval' => array(
					'label' => 'Mindestabstand',
					'type'  => 'number',
					'step'  => 1,
					'unit'  => 'Sekunden',
				),
				'textfocus_batch_size'   => array(
					'label' => 'Keywords je Aufruf',
					'type'  => 'number',
					'step'  => 1,
				),
			),
		),
		'keywords'   => array(
			'label'  => 'Keywords',
			'fields' => array(
				'keyword_analysis'     => array(
					'label' => 'Orte und Namen in Keywords erkennen',
					'type'  => 'toggle',
					'help'  => 'Grundlage für die Ausschlüsse fremder Orte und Firmen. Ein KI-Aufruf je 100 neue Keywords (wenige Cent, im Rahmen des KI-Budgets).',
				),
				'keyword_auto_volumes' => array(
					'label' => 'Suchvolumen bei Textfocus nachholen',
					'type'  => 'toggle',
					'help'  => 'Der tägliche Abgleich fragt für Keywords ohne Volumen Textfocus ab (10 Keywords je Credit) und lässt 5 Credits des Tagesbudgets für Abfragen von Hand frei. Mögliche Tippfehler werden übersprungen.',
				),
			),
		),
		'topics'     => array(
			'label'  => 'Themen',
			'fields' => array(
				'topic_count'   => array(
					'label' => 'Themen je Recherche',
					'type'  => 'number',
					'step'  => 1,
					'help'  => 'Wie viele Vorschläge eine Recherche liefert (1 bis 10). Eine Recherche mit Websuche kostet etwa 0,05 bis 0,20 USD.',
				),
				'topic_weights' => array(
					'label' => 'Gewichte der Priorität (Prozent)',
					'type'  => 'json',
					'help'  => 'Leer = Standard: {"relevance":30,"demand":25,"gap":20,"local":15,"season":10} – Geschäftsrelevanz, Nachfrage, Inhaltslücke, lokaler Bezug, Saisonalität.',
				),
			),
		),
		'production' => array(
			'label'  => 'Beiträge',
			'fields' => array(
				'production_auto'     => array(
					'label' => 'Freigegebene Themen automatisch schreiben',
					'type'  => 'toggle',
					'help'  => 'Der Cronjob erstellt Entwürfe zu freigegebenen Themen, höchstens so viele am Tag wie unten eingestellt. Ein Entwurf kostet etwa 0,05 bis 0,30 USD.',
				),
				'drafts_per_day'      => array(
					'label' => 'Entwürfe je Tag',
					'type'  => 'number',
					'step'  => 1,
					'help'  => 'Obergrenze für automatisch gestartete Entwürfe (0 bis 20). Von Hand gestartete zählen mit.',
				),
				'draft_author'        => array(
					'label' => 'Autor der Entwürfe (Benutzer-ID)',
					'type'  => 'number',
					'step'  => 1,
					'help'  => '0 = wer das Thema freigegeben hat, sonst der erste Administrator.',
				),
				'draft_lengths'       => array(
					'label' => 'Länge je Beitragstyp (Wörter)',
					'type'  => 'json',
					'help'  => 'Leer = Standard: {"local_landing":[700,1300],"article":[900,1600],"guide":[1200,2200],"seasonal":[700,1300],"other":[700,1500]}',
				),
				'quality_min_score'   => array(
					'label' => 'Mindestwert je Merkmal der KI-Bewertung',
					'type'  => 'number',
					'step'  => 0.05,
					'help'  => 'Darunter ist der Entwurf blockiert (Spezifikation 10.2, Vorschlag 0,6).',
				),
				'quality_min_average' => array(
					'label' => 'Mindestdurchschnitt der KI-Bewertung',
					'type'  => 'number',
					'step'  => 0.05,
					'help'  => 'Vorschlag 0,75. Wird in der Testphase anhand Ihrer Korrekturen angepasst.',
				),
			),
		),
		'gsc'        => array(
			'label'  => 'Search Console',
			'fields' => array(
				'gsc_property'         => array(
					'label' => 'Property',
					'type'  => 'text',
					'help'  => 'z. B. https://example.de/ oder sc-domain:example.de. Freigegebene Properties: wp lsec gsc sites.',
				),
				'gsc_country'          => array(
					'label' => 'Land',
					'type'  => 'text',
					'help'  => 'ISO-Code mit drei Buchstaben: deu, aut, che.',
				),
				'gsc_credentials_file' => array(
					'label' => 'Schlüsseldatei',
					'type'  => 'text',
					'help'  => 'Leer = Ordner private neben dem Webroot. Die Konstante LSEC_GSC_CREDENTIALS_FILE hat Vorrang.',
				),
			),
		),
		'entities'   => array(
			'label'  => 'Entitäten',
			'fields' => array(
				'entity_auto_extract'   => array(
					'label' => 'Entitäten automatisch erkennen',
					'type'  => 'toggle',
					'help'  => 'Der Inhaltsindex startet für neue und geänderte Inhalte die Entity-Erkennung (KI, etwa 0,04 USD je Inhalt, im Rahmen des KI-Budgets).',
				),
				'entity_extract_types'  => array(
					'label' => 'Entitäten erkennen für',
					'type'  => 'checkboxes',
					'help'  => 'Gelesen werden immer alle Inhalte (kostenlos, für Dublettenprüfung und interne Links). Die KI-Erkennung lohnt sich vor allem für Seiten, Produkte und Kategorien. Einzelne Blogbeiträge: wp lsec entities extract <id> --queue.',
				),
				'entity_min_confidence' => array(
					'label' => 'Mindest-Sicherheit für die Anzeige',
					'type'  => 'number',
					'step'  => 0.05,
					'help'  => 'Schwächere Vorschläge werden gespeichert, in der Übersicht aber ausgeblendet (0 bis 1).',
				),
			),
		),
		'general'    => array(
			'label'  => 'Allgemein',
			'fields' => array(
				'max_parallel_runs'        => array(
					'label' => 'Gleichzeitige Abläufe',
					'type'  => 'number',
					'step'  => 1,
				),
				'delete_data_on_uninstall' => array(
					'label' => 'Daten bei Deinstallation löschen',
					'type'  => 'toggle',
					'help'  => 'Löscht Tabellen und Einstellungen beim Löschen des Plugins. Beiträge bleiben immer erhalten.',
				),
			),
		),
	);

	/**
	 * Gruppen mit aktuellen Werten, Auswahl und Bereichen.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function describe(): array {
		$values = Settings::all();
		$out    = array();
		foreach ( self::GROUPS as $id => $group ) {
			$fields = array();
			foreach ( $group['fields'] as $key => $field ) {
				$fields[] = $field + array(
					'key'     => $key,
					'value'   => $values[ $key ] ?? null,
					'default' => Settings::DEFAULTS[ $key ],
					'choices' => 'checkboxes' === $field['type'] ? self::checkbox_choices( $key ) : ( Settings::CHOICES[ $key ] ?? null ),
					'min'     => Settings::RANGES[ $key ][0] ?? 0,
					'max'     => Settings::RANGES[ $key ][1] ?? null,
				);
			}
			$out[] = array(
				'id'     => $id,
				'label'  => $group['label'],
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * Auswahl für Listenfelder.
	 *
	 * @param string $key Schlüssel.
	 * @return list<array{value: string, label: string}>
	 */
	private static function checkbox_choices( string $key ): array {
		$out = array();
		if ( 'entity_extract_types' === $key ) {
			foreach ( ContentIndex::kinds() as $kind => $info ) {
				$out[] = array(
					'value' => $kind,
					'label' => $info['label'],
				);
			}
		}
		return $out;
	}

	/**
	 * Darf die Oberfläche diesen Schlüssel ändern?
	 *
	 * @param string $key Schlüssel.
	 */
	public static function editable( string $key ): bool {
		foreach ( self::GROUPS as $group ) {
			if ( isset( $group['fields'][ $key ] ) ) {
				return 'readonly' !== $group['fields'][ $key ]['type'];
			}
		}
		return false;
	}
}
