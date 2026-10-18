<?php
/**
 * WP-CLI: Inhaltsindex.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Content\ContentIndex;
use Lsec\Content\ContentIndexWorkflow;
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
	 * Startet den Inhaltsindex für neue und geänderte Beiträge und Seiten.
	 *
	 * Ist die automatische Entity-Erkennung eingeschaltet (entity_auto_extract), startet der Index für jeden
	 * neuen oder geänderten Inhalt ab 50 Wörtern eine Erkennung. Sie kostet KI-Guthaben (etwa 0,04 USD je
	 * Beitrag) und läuft über den Cronjob.
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
	 * : Nur diese Beiträge, mit Komma getrennt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec index run --now
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function run( array $args, array $assoc_args ): void {
		$ids     = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $assoc_args['ids'] ?? '' ) ) ) ) );
		$pending = array() !== $ids ? count( $ids ) : count( ContentIndex::pending_ids( ! empty( $assoc_args['all'] ) ) );
		WP_CLI::log( sprintf( '%d Inhalte zu lesen. Automatische Entity-Erkennung: %s.', $pending, Settings::get( 'entity_auto_extract' ) ? 'ein' : 'aus' ) );
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
	 * Stand des Index.
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
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $stats ) );
			return;
		}
		WP_CLI::log( sprintf( 'Veröffentlicht: %d · indiziert: %d · offen: %d · letzter Lauf: %s', $stats['published'], $stats['indexed'], $stats['pending'], $stats['last_indexed_at'] ?? 'noch keiner' ) );
	}

	/**
	 * Indexeintrag eines Beitrags.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Beitrag oder Seite.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function show( array $args, array $assoc_args ): void {
		$entry = get_post_meta( (int) ( $args[0] ?? 0 ), ContentIndex::META, true );
		if ( ! is_array( $entry ) ) {
			WP_CLI::error( 'Nicht im Index.' );
		}
		WP_CLI::line( (string) wp_json_encode( $entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}
}
