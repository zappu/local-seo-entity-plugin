<?php
/**
 * WP-CLI: Abläufe.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Workflow\RunStatus;
use WP_CLI;

/**
 * Abläufe anzeigen, fortsetzen und abbrechen (Spezifikation 7.2).
 */
final class RunsCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Listet die letzten Abläufe.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : queued, running, waiting, blocked, succeeded, failed oder cancelled.
	 *
	 * [--limit=<n>]
	 * : Anzahl.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec runs list --status=waiting
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function list( array $args, array $assoc_args ): void {
		$status = (string) ( $assoc_args['status'] ?? '' );
		if ( '' !== $status && null === RunStatus::tryFrom( $status ) ) {
			WP_CLI::error( 'Unbekannter Status: ' . $status );
		}
		$rows  = array();
		$calls = new AiCallRepository();
		foreach ( $this->plugin->runs->recent( max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ), $status ) as $run ) {
			$rows[] = array(
				'id'      => $run['id'],
				'typ'     => $run['type'],
				'objekt'  => '' === (string) $run['subject_type'] ? '' : $run['subject_type'] . ':' . $run['subject_id'],
				'status'  => $run['status'],
				'schritt' => (string) $run['current_step'],
				'kosten'  => number_format( $calls->cost_of_run( (int) $run['id'] ), 4, ',', '' ),
				'grund'   => trim( (string) $run['error_code'] . ' ' . mb_substr( (string) $run['error_message'], 0, 80 ) ),
				'stand'   => $run['updated_at'],
			);
		}
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'typ', 'objekt', 'status', 'schritt', 'kosten', 'grund', 'stand' ) );
	}

	/**
	 * Zeigt einen Ablauf mit seinen Schritten und deren Ergebnissen.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Ablauf-ID.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function show( array $args, array $assoc_args ): void {
		$id  = (int) ( $args[0] ?? 0 );
		$run = $this->plugin->runs->find( $id );
		if ( null === $run ) {
			WP_CLI::error( 'Ablauf nicht gefunden: ' . $id );
		}
		WP_CLI::log( sprintf( 'Ablauf %d · %s · %s · erstellt %s · Kosten %s USD', $id, $run['type'], $run['status'], $run['created_at'], number_format( ( new AiCallRepository() )->cost_of_run( $id ), 4, ',', '' ) ) );
		if ( '' !== (string) $run['error_code'] ) {
			WP_CLI::log( 'Grund: ' . $run['error_code'] . ' ' . $this->plugin->logger->redact( (string) $run['error_message'] ) );
		}
		foreach ( $this->plugin->runs->steps( $id ) as $step ) {
			WP_CLI::log( sprintf( '%-10s %-8s Versuch %d  %s', $step['step'], $step['status'], (int) $step['attempt'], mb_substr( $this->plugin->logger->redact( (string) $step['output'] ), 0, 400 ) ) );
		}
	}

	/**
	 * Setzt einen wartenden Ablauf fort. Reicht das Budget noch nicht, wartet er beim nächsten KI-Aufruf erneut.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Ablauf-ID.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function resume( array $args, array $assoc_args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( ! $this->plugin->engine->resume( $id ) ) {
			WP_CLI::error( 'Ablauf ' . $id . ' wartet nicht.' );
		}
		$this->plugin->logger->audit( 'Ablauf von Hand fortgesetzt', array( 'run_id' => $id ) );
		WP_CLI::success( 'Ablauf ' . $id . ' eingeplant; der nächste Cronjob führt ihn aus.' );
	}

	/**
	 * Bricht einen Ablauf ab, der noch nicht beendet ist, oder mit --open alle offenen.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Ablauf-ID.
	 *
	 * [--open]
	 * : Alle wartenden, eingeplanten und laufenden Abläufe abbrechen.
	 *
	 * [--type=<type>]
	 * : Mit --open nur diesen Typ, z. B. entity_extraction oder content_index (mit Komma mehrere).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec runs cancel 42
	 *     wp lsec runs cancel --open --type=content_index,entity_extraction
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function cancel( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['open'] ) ) {
			$types = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc_args['type'] ?? '' ) ) ) ) );
			$count = $this->plugin->runs->cancel_open( $types );
			$this->plugin->logger->audit(
				'Offene Abläufe abgebrochen',
				array(
					'types' => array() === $types ? 'alle' : implode( ',', $types ),
					'count' => $count,
				)
			);
			WP_CLI::success( sprintf( '%d Abläufe abgebrochen.', $count ) );
			return;
		}
		$id  = (int) ( $args[0] ?? 0 );
		$run = $this->plugin->runs->find( $id );
		if ( null === $run ) {
			WP_CLI::error( 0 === $id ? 'Ablauf-ID oder --open angeben.' : 'Ablauf nicht gefunden: ' . $id );
		}
		$status = RunStatus::tryFrom( (string) $run['status'] );
		if ( null !== $status && $status->is_terminal() ) {
			WP_CLI::error( 'Ablauf ' . $id . ' ist schon beendet (' . $status->value . ').' );
		}
		$this->plugin->runs->update_run(
			$id,
			array(
				'status'      => RunStatus::Cancelled->value,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->plugin->logger->audit( 'Ablauf abgebrochen', array( 'run_id' => $id ) );
		WP_CLI::success( 'Ablauf ' . $id . ' abgebrochen.' );
	}
}
