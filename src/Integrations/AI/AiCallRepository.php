<?php
/**
 * Datenbank-Protokoll der KI-Aufrufe.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Infrastructure\Database\Schema;

/**
 * Schreibt in lsec_ai_calls. Inhalte von Anfrage und Antwort werden nicht gespeichert
 * (Datenminimierung), nur Verbrauch, Dauer, Status und ein Fingerabdruck.
 */
final class AiCallRepository implements AiCallLog, AiSpend {

	/**
	 * {@inheritDoc}
	 *
	 * @param string          $task         Aufgabe.
	 * @param string          $provider     Anbieter.
	 * @param string          $model        Modell.
	 * @param string          $status       Status.
	 * @param AiResponse|null $response     Antwort.
	 * @param string          $request_hash Fingerabdruck.
	 * @param int|null        $run_id       Ablauf.
	 * @param int|null        $step_id      Schritt.
	 */
	public function record( string $task, string $provider, string $model, string $status, ?AiResponse $response, string $request_hash, ?int $run_id = null, ?int $step_id = null ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( $wpdb->prefix, 'ai_calls' ),
			array(
				'run_id'        => $run_id,
				'step_id'       => $step_id,
				'task'          => mb_substr( $task, 0, 40 ),
				'provider'      => $provider,
				'model'         => mb_substr( null !== $response ? $response->model : $model, 0, 100 ),
				'input_tokens'  => $response?->input_tokens,
				'output_tokens' => $response?->output_tokens,
				'cost_estimate' => $response?->cost,
				'duration_ms'   => $response?->duration_ms,
				'status'        => $status,
				'request_hash'  => $request_hash,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $since_utc Zeitpunkt.
	 */
	public function cost_since( string $since_utc ): float {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'ai_calls' );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost_estimate),0) FROM {$table} WHERE created_at >= %s", $since_utc ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $run_id Ablauf.
	 */
	public function cost_of_run( int $run_id ): float {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'ai_calls' );
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cost_estimate),0) FROM {$table} WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $since_utc Zeitpunkt.
	 */
	public function unpriced_since( string $since_utc ): int {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'ai_calls' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status = 'ok' AND cost_estimate IS NULL", $since_utc ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Summen für die Statusseite: Aufrufe, Token und Kosten der letzten Tage.
	 *
	 * @param int $days Zeitraum.
	 * @return array{calls: int, input_tokens: int, output_tokens: int, cost: float}
	 */
	public static function totals( int $days = 30 ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'ai_calls' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens, COALESCE(SUM(cost_estimate),0) AS cost FROM {$table} WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			),
			ARRAY_A
		);
		return array(
			'calls'         => (int) ( $row['calls'] ?? 0 ),
			'input_tokens'  => (int) ( $row['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $row['output_tokens'] ?? 0 ),
			'cost'          => (float) ( $row['cost'] ?? 0 ),
		);
	}
}
