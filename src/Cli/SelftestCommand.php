<?php
/**
 * WP-CLI: Selbsttest für den Machbarkeitstest.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Workflow\Selftest\SelftestWorkflow;
use WP_CLI;

/**
 * Startet und prüft Selbsttest-Abläufe (Prüfpunkte M4 und M5).
 */
final class SelftestCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Startet einen Selbsttest-Ablauf.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<key>]
	 * : Merkmal für den Idempotenz-Schlüssel. Gleicher Schlüssel = gleicher Ablauf.
	 *
	 * [--crash-at=<step>]
	 * : Simulierter Abbruch beim ersten Versuch dieses Schritts (compute oder create_post).
	 *
	 * [--retry-once]
	 * : compute meldet beim ersten Versuch einen vorübergehenden Fehler.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec selftest start --key=test1 --crash-at=compute
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function start( array $args, array $assoc_args ): void {
		$key   = (string) ( $assoc_args['key'] ?? wp_generate_uuid4() );
		$input = array(
			'crash_at'   => (string) ( $assoc_args['crash-at'] ?? '' ),
			'retry_once' => ! empty( $assoc_args['retry-once'] ),
		);

		$result = $this->plugin->engine->start( SelftestWorkflow::TYPE, 'selftest', null, $input, array( 'key' => $key ) );
		WP_CLI::line( (string) wp_json_encode( $result ) );
	}

	/**
	 * Bericht zu einem Selbsttest-Ablauf.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Ablauf-ID.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function report( array $args, array $assoc_args ): void {
		$run_id = (int) ( $args[0] ?? 0 );
		$run    = $this->plugin->runs->find( $run_id );
		if ( null === $run ) {
			WP_CLI::error( 'Ablauf nicht gefunden: ' . $run_id );
		}

		$counters = get_option( SelftestWorkflow::COUNTER_OPT, array() );
		$steps    = array();
		foreach ( $this->plugin->runs->steps( $run_id ) as $step ) {
			$steps[ (string) $step['step'] ] = array(
				'status'     => $step['status'],
				'attempt'    => (int) $step['attempt'],
				'executions' => (int) ( $counters[ $run_id ][ $step['step'] ] ?? 0 ),
				'error_code' => $step['error_code'],
			);
		}

		$report = array(
			'run_id'       => $run_id,
			'status'       => $run['status'],
			'current_step' => $run['current_step'],
			'error_code'   => $run['error_code'],
			'locked'       => null !== $run['locked_until'],
			'steps'        => $steps,
			'posts'        => count( SelftestWorkflow::all_posts( (string) $run['uuid'] ) ),
		);
		WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Zählt Selbsttest-Abläufe zu einem Schlüssel.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Merkmal, das bei start angegeben wurde.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function count( array $args, array $assoc_args ): void {
		global $wpdb;
		$key   = \Lsec\Workflow\IdempotencyKey::for_run( SelftestWorkflow::TYPE, 'selftest', null, array( 'key' => (string) ( $args[0] ?? '' ) ) );
		$table = Schema::table( $wpdb->prefix, 'runs' );
		$n     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE idempotency_key = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		WP_CLI::line( (string) $n );
	}

	/**
	 * Entfernt alle Selbsttest-Abläufe, ihre Beiträge und Zähler.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		global $wpdb;
		$runs  = Schema::table( $wpdb->prefix, 'runs' );
		$steps = Schema::table( $wpdb->prefix, 'run_steps' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid FROM {$runs} WHERE type = %s", SelftestWorkflow::TYPE ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			foreach ( SelftestWorkflow::all_posts( (string) $row['uuid'] ) as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			$wpdb->delete( $steps, array( 'run_id' => (int) $row['id'] ) );
			$wpdb->delete( $runs, array( 'id' => (int) $row['id'] ) );
			as_unschedule_all_actions( \Lsec\Infrastructure\Queue\ActionSchedulerQueue::HOOK, array( (int) $row['id'] ), \Lsec\Infrastructure\Queue\ActionSchedulerQueue::GROUP );
		}
		// phpcs:enable
		delete_option( SelftestWorkflow::COUNTER_OPT );
		WP_CLI::success( 'Selbsttest-Daten entfernt: ' . count( is_array( $rows ) ? $rows : array() ) . ' Abläufe.' );
	}
}
