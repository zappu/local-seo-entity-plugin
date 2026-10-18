<?php
/**
 * WP-CLI: Entitäten und Ortsabgleich.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\EntityRepository;
use Lsec\Entities\PlaceResolver;
use Lsec\Entities\ServiceArea;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Workflow\RetryableException;
use WP_CLI;

/**
 * Ortsabgleich über Wikidata (Prüfpunkt M18) und Servicegebiet.
 */
final class EntitiesCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin           $plugin   Plugin.
	 * @param EntityRepository $entities Speicher.
	 */
	public function __construct( private readonly Plugin $plugin, private readonly EntityRepository $entities ) {}

	/**
	 * Gleicht einen Ortsnamen mit Wikidata ab.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Ortsname, z. B. Burgdorf.
	 *
	 * [--[no-]area]
	 * : Servicegebiet als Kontext verwenden. Mit --no-area ohne Kontext abgleichen.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--locale=<locale>]
	 * : Locale.
	 * ---
	 * default: de_DE
	 * ---
	 *
	 * [--save]
	 * : Ergebnis als Entität speichern.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities resolve-place Burgdorf --no-area
	 *     wp lsec entities resolve-place Burgdorf --save
	 *
	 * @subcommand resolve-place
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function resolve_place( array $args, array $assoc_args ): void {
		$area = ( isset( $assoc_args['area'] ) && false === $assoc_args['area'] ) ? null : ServiceArea::from_settings();
		WP_CLI::log( null === $area ? 'Servicegebiet: keins' : 'Servicegebiet: ' . $area->label . ' (' . implode( ', ', $area->wikidata_ids ) . ( $area->radius_km > 0 ? ', ' . $area->radius_km . ' km' : '' ) . ')' );
		try {
			$result = ( new PlaceResolver( $this->wikidata() ) )->resolve( (string) $args[0], $area, (string) ( $assoc_args['locale'] ?? 'de_DE' ) );
		} catch ( RetryableException $e ) {
			WP_CLI::error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$rows = array();
		foreach ( $result['candidates'] as $c ) {
			$rows[] = array(
				'wikidata'     => $c['id'],
				'beschreibung' => $c['description'],
				'liegt_in'     => implode( ', ', array_slice( (array) $c['located_in'], 0, 4 ) ),
				'entfernung'   => null === $c['distance_km'] ? '' : $c['distance_km'] . ' km',
				'im_gebiet'    => null === $c['in_area'] ? '' : ( $c['in_area'] ? 'ja' : 'nein' ),
				'gewählt'      => null !== $result['chosen'] && $result['chosen']['id'] === $c['id'] ? '✓' : '',
			);
		}
		if ( array() !== $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'wikidata', 'beschreibung', 'liegt_in', 'entfernung', 'im_gebiet', 'gewählt' ) );
		}
		WP_CLI::log( sprintf( 'Status: %s – %s', $result['status'], $result['reason'] ) );
		if ( ! empty( $assoc_args['save'] ) ) {
			$saved = $this->entities->save_place( $result );
			WP_CLI::success(
				$saved['kept_decision']
					? sprintf( 'Entität %d hat bereits eine Entscheidung (%s), nicht überschrieben.', $saved['id'], $saved['status'] )
					: sprintf( 'Entität %d gespeichert (%s).', $saved['id'], $saved['status'] )
			);
		}
	}

	/**
	 * Legt das Servicegebiet fest, z. B. „Region Hannover“.
	 *
	 * Sucht die Verwaltungseinheit bei Wikidata und speichert Q-ID und Mittelpunkt.
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : Name der Verwaltungseinheit. Ohne Angabe wird das aktuelle Gebiet angezeigt.
	 *
	 * [--id=<qid>]
	 * : Q-ID direkt angeben, wenn die Suche mehrdeutig ist.
	 *
	 * [--radius=<km>]
	 * : Zusätzlich alle Orte im Umkreis um den Mittelpunkt zählen.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--clear]
	 * : Servicegebiet entfernen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec entities service-area "Region Hannover"
	 *     wp lsec entities service-area "Region Hannover" --radius=40
	 *
	 * @subcommand service-area
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function service_area( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['clear'] ) ) {
			Settings::set( 'service_area', array() );
			WP_CLI::success( 'Servicegebiet entfernt.' );
			return;
		}
		if ( array() === $args && empty( $assoc_args['id'] ) ) {
			$area = ServiceArea::from_settings();
			WP_CLI::log( null === $area ? 'Kein Servicegebiet festgelegt.' : (string) wp_json_encode( $area->to_array(), JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$wikidata = $this->wikidata();
		try {
			$id = (string) ( $assoc_args['id'] ?? '' );
			if ( '' === $id ) {
				$hits = $wikidata->search( (string) $args[0], 'de', 5 );
				foreach ( $hits as $hit ) {
					WP_CLI::log( sprintf( '  %s  %s – %s', $hit['id'], $hit['label'], $hit['description'] ) );
				}
				if ( array() === $hits ) {
					WP_CLI::error( 'Nichts gefunden.' );
				}
				$id = $hits[0]['id'];
			}
			$entity = $wikidata->entities( array( $id ) )[ $id ] ?? null;
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( null === $entity ) {
			WP_CLI::error( 'Eintrag ' . $id . ' nicht gefunden.' );
		}
		$area = new ServiceArea( '' !== $entity['label'] ? $entity['label'] : (string) ( $args[0] ?? $id ), array( $id ), $entity['coord'], (float) ( $assoc_args['radius'] ?? 0 ) );
		Settings::set( 'service_area', $area->to_array() );
		WP_CLI::success( sprintf( 'Servicegebiet: %s (%s, %s)%s', $area->label, $id, $entity['description'], $area->radius_km > 0 ? ', Umkreis ' . $area->radius_km . ' km' : '' ) );
	}

	/**
	 * Zeigt Entitäten.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Typ, z. B. place.
	 *
	 * [--status=<status>]
	 * : Status, z. B. unresolved.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows = $this->entities->list( (string) ( $assoc_args['type'] ?? '' ), (string) ( $assoc_args['status'] ?? '' ) );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'type', 'canonical_name', 'status', 'confidence', 'external_ids', 'origin', 'updated_at' ) );
	}

	/**
	 * Wikidata-Zugang mit Zwischenspeicher.
	 */
	private function wikidata(): WikidataClient {
		return new WikidataClient(
			$this->plugin->http,
			new ProviderCallRepository(),
			sprintf( 'LocalSEOEntityPlugin/%s (WordPress; %s)', LSEC_VERSION, home_url( '/' ) )
		);
	}
}
