<?php
/**
 * Datenbank-Protokoll externer Datenanbieter.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\Textfocus;

use Lsec\Infrastructure\Database\Schema;

/**
 * Liest und schreibt lsec_provider_calls.
 */
final class ProviderCallRepository implements ProviderCallLog {

	/**
	 * Status, die einen echten Aufruf beim Anbieter bedeuten.
	 */
	private const REAL_CALLS = array( 'ok', 'error', 'rate_limited' );

	/**
	 * {@inheritDoc}
	 *
	 * @param string $provider     Anbieter.
	 * @param string $request_hash Fingerabdruck.
	 * @return array{id: int, body: string}|null
	 */
	public function cached( string $provider, string $request_hash ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'provider_calls' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, response_body FROM {$table} WHERE provider = %s AND request_hash = %s AND status = 'ok' AND response_body IS NOT NULL AND cached_until > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$provider,
				$request_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'   => (int) $row['id'],
			'body' => (string) $row['response_body'],
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array{provider: string, endpoint: string, request_hash: string, status: string, credits_used?: float|null, credits_remaining?: float|null, response_body?: string|null, cached_until?: string|null, response_ref?: string|null, run_id?: int|null} $call Aufruf.
	 */
	public function record( array $call ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( $wpdb->prefix, 'provider_calls' ),
			array(
				'provider'          => $call['provider'],
				'endpoint'          => mb_substr( $call['endpoint'], 0, 60 ),
				'request_hash'      => $call['request_hash'],
				'credits_used'      => $call['credits_used'] ?? null,
				'credits_remaining' => $call['credits_remaining'] ?? null,
				'status'            => $call['status'],
				'response_ref'      => $call['response_ref'] ?? null,
				'response_body'     => $call['response_body'] ?? null,
				'cached_until'      => $call['cached_until'] ?? null,
				'run_id'            => $call['run_id'] ?? null,
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $provider Anbieter.
	 */
	public function credits_today( string $provider ): float {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'provider_calls' );
		$start = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		return (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COALESCE(SUM(COALESCE(credits_used, 1)), 0) FROM {$table} WHERE provider = %s AND status = 'ok' AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$provider,
				$start
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $provider Anbieter.
	 */
	public function last_call_at( string $provider ): ?int {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'provider_calls' );
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT MAX(created_at) FROM {$table} WHERE provider = %s AND status IN (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$provider,
				self::REAL_CALLS[0],
				self::REAL_CALLS[1],
				self::REAL_CALLS[2]
			)
		);
		if ( null === $value ) {
			return null;
		}
		$time = strtotime( $value . ' UTC' );
		return false === $time ? null : $time;
	}

	/**
	 * Letzte Aufrufe für die Anzeige.
	 *
	 * @param string $provider Anbieter.
	 * @param int    $limit    Höchstzahl.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( string $provider, int $limit = 20 ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'provider_calls' );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, endpoint, status, credits_used, credits_remaining, response_ref, cached_until, created_at FROM {$table} WHERE provider = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$provider,
				$limit
			),
			ARRAY_A
		);
	}
}
