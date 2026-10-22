<?php
/**
 * WP-CLI: Inhaltsindex.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Content\ContentIndex;
use Lsec\Content\ContentIndexWorkflow;
use Lsec\Content\IndexItem;
use Lsec\Entities\EntityExtractionWorkflow;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use WP_CLI;

/**
 * Inhaltsindex starten und ansehen (Spezifikation 5).
 */
final class IndexCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Startet den Inhaltsindex für neue und geänderte Beiträge, Seiten, Produkte und Kategorieseiten.
	 *
	 * Ist die automatische Entity-Erkennung eingeschaltet (entity_auto_extract), startet der Index für jeden
	 * neuen oder geänderten Inhalt ab 50 Wörtern eine Erkennung, aber nur für die Arten aus
	 * entity_extract_types (Standard: Seiten, Produkte, Kategorien; ohne Blogbeiträge). Sie kostet
	 * KI-Guthaben (etwa 0,04 USD je Inhalt) und läuft über den Cronjob.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Alle veröffentlichten Inhalte neu lesen. Unveränderte lösen trotzdem keine neue Erkennung aus.
	 *
	 * [--now]
	 * : Den Index sofort hier abarbeiten statt über den Cronjob. Die Erkennung läuft weiter über den Cronjob.
	 *
	 * [--ids=<ids>]
	 * : Nur diese Inhalte, mit Komma getrennt: Beitrags-IDs oder term:<id> für Kategorien.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec index run --now
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function run( array $args, array $assoc_args ): void {
		$ids     = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['ids'] ?? '' ) ) ) ) );
		$pending = array() !== $ids ? count( $ids ) : count( ContentIndex::pending_ids( ! empty( $assoc_args['all'] ) ) );
		WP_CLI::log( sprintf( '%d Inhalte zu lesen. Automatische Entity-Erkennung: %s.', $pending, self::extract_label() ) );
		$run = ContentIndexWorkflow::start( $this->plugin, ! empty( $assoc_args['all'] ), $ids );
		if ( null === $run ) {
			WP_CLI::error( 'Es läuft schon ein Indexlauf. Stand: wp lsec runs list' );
		}
		if ( empty( $assoc_args['now'] ) ) {
			WP_CLI::success( 'Ablauf ' . $run['run_id'] . ' eingeplant; der Cronjob arbeitet ihn ab.' );
			return;
		}
		for ( $i = 0; $i < 1000; $i++ ) {
			$this->plugin->engine->process( $run['run_id'] );
			$status = (string) ( $this->plugin->runs->find( $run['run_id'] )['status'] ?? '' );
			if ( ! in_array( $status, array( 'queued', 'running' ), true ) ) {
				break;
			}
		}
		$step   = $this->plugin->runs->steps( $run['run_id'] )[0] ?? array();
		$output = json_decode( (string) ( $step['output'] ?? '' ), true );
		$output = is_array( $output ) ? $output : array();
		WP_CLI::success(
			sprintf(
				'Ablauf %d: %s. Gelesen %d, neu oder geändert %d, Entity-Erkennungen gestartet %d.',
				$run['run_id'],
				$status,
				(int) ( $output['indexed'] ?? 0 ),
				(int) ( $output['changed'] ?? 0 ),
				(int) ( $output['extractions'] ?? 0 )
			)
		);
	}

	/**
	 * Hält den Index an: bricht den Indexlauf und alle offenen Entity-Erkennungen ab.
	 *
	 * Bereits erkannte Entitäten bleiben erhalten. Der nächste Indexlauf startet die fehlenden Erkennungen
	 * neu, solange die automatische Erkennung eingeschaltet ist.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function stop( array $args, array $assoc_args ): void {
		$count = ContentIndexWorkflow::stop( $this->plugin );
		WP_CLI::success( sprintf( '%d Abläufe abgebrochen (Index und Entity-Erkennung).', $count ) );
	}

	/**
	 * Stand des Index, gesamt und je Art.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function status( array $args, array $assoc_args ): void {
		$stats = ContentIndex::stats();
		$open  = $this->plugin->runs->count_open( array( ContentIndexWorkflow::TYPE, EntityExtractionWorkflow::TYPE ) );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats + array( 'open_runs' => $open ) ) );
			return;
		}
		WP_CLI::log( sprintf( 'Veröffentlicht: %d · indiziert: %d · offen: %d · letzter Lauf: %s', $stats['published'], $stats['indexed'], $stats['pending'], $stats['last_indexed_at'] ?? 'noch keiner' ) );
		$rows = array();
		foreach ( $stats['kinds'] as $kind => $row ) {
			$rows[] = array(
				'art'       => $row['label'] . ' (' . $kind . ')',
				'vorhanden' => $row['published'],
				'indiziert' => $row['indexed'],
				'erkennung' => $row['extract'] ? 'ja' : 'nein',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'art', 'vorhanden', 'indiziert', 'erkennung' ) );
		WP_CLI::log( sprintf( 'Automatische Erkennung: %s. Offene Abläufe: Index %d, Erkennung %d.', self::extract_label(), $open[ ContentIndexWorkflow::TYPE ], $open[ EntityExtractionWorkflow::TYPE ] ) );
	}

	/**
	 * Indexeintrag eines Inhalts.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Beitrags-ID oder term:<id> für eine Kategorie.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function show( array $args, array $assoc_args ): void {
		$item  = IndexItem::from_ref( (string) ( $args[0] ?? '' ) );
		$entry = null === $item ? null : $item->meta( ContentIndex::META );
		if ( ! is_array( $entry ) ) {
			WP_CLI::error( 'Nicht im Index.' );
		}
		WP_CLI::line( (string) wp_json_encode( $entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * „aus“ oder die Arten, für die die Erkennung läuft.
	 */
	private static function extract_label(): string {
		if ( ! Settings::get( 'entity_auto_extract' ) ) {
			return 'aus';
		}
		$kinds  = ContentIndex::kinds();
		$labels = array_map( static fn( string $k ): string => $kinds[ $k ]['label'], ContentIndex::extract_kinds() );
		return array() === $labels ? 'ein, aber keine Art ausgewählt' : 'ein für ' . implode( ', ', $labels );
	}
}
