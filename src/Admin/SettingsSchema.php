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
		'ai'        => array(
			'label'  => 'KI',
			'fields' => array(
				'ai_provider'        => array(
					'label' => 'Anbieter',
					'type'  => 'select',
				),
				'ai_model'           => array(
					'label' => 'Hauptmodell',
					'type'  => 'text',
					'help'  => 'Gilt für alle Aufgaben. Bei OpenRouter z. B. anthropic/claude-sonnet-5.5; Liste mit wp lsec ai models.',
				),
				'ai_research_model'  => array(
					'label' => 'Recherchemodell',
					'type'  => 'text',
					'help'  => 'Nur für die Recherche mit Websuche, beim selben Anbieter. Leer = Hauptmodell.',
				),
				'ai_web_max_results' => array(
					'label' => 'Treffer der Websuche',
					'type'  => 'number',
					'step'  => 1,
					'help'  => 'Weniger Treffer senken die Kosten der Recherche deutlich. OpenAI: bis 3 = geringer Suchumfang.',
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
		'textfocus' => array(
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
		'gsc'       => array(
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
		'entities'  => array(
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
		'general'   => array(
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
