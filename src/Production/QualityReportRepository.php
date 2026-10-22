<?php
/**
 * Prüfberichte der Entwürfe.
 *
 * @package Lsec
 */

namespace Lsec\Production;

use Lsec\Infrastructure\Database\Schema;

/**
 * Zugriff auf lsec_quality_reports (Spezifikation 4.4 und 10).
 */
final class QualityReportRepository {

	/**
	 * Speichert einen Bericht.
	 *
	 * @param int|null             $post_id Beitrag (wird nach dem Anlegen ergänzt).
	 * @param int                  $run_id  Ablauf.
	 * @param string               $profile Beitragstyp.
	 * @param array<string, mixed> $report  Bericht: score, passed, blocking, warnings, checks, review.
	 * @return int ID.
	 */
	public function save( ?int $post_id, int $run_id, string $profile, array $report ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'quality_reports' ),
			array(
				'post_id'         => $post_id,
				'run_id'          => $run_id,
				'profile'         => mb_substr( $profile, 0, 40 ),
				'score'           => null === ( $report['score'] ?? null ) ? null : (float) $report['score'],
				'passed'          => empty( $report['passed'] ) ? 0 : 1,
				'blocking_issues' => (string) wp_json_encode( $report['blocking'] ?? array(), JSON_UNESCAPED_UNICODE ),
				'warnings'        => (string) wp_json_encode( $report['warnings'] ?? array(), JSON_UNESCAPED_UNICODE ),
				'checks'          => (string) wp_json_encode(
					array(
						'rules'  => $report['checks'] ?? array(),
						'review' => $report['review'] ?? null,
						'words'  => $report['words'] ?? null,
					),
					JSON_UNESCAPED_UNICODE
				),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Ordnet einen Bericht dem Beitrag zu.
	 *
	 * @param int $id      Bericht.
	 * @param int $post_id Beitrag.
	 */
	public function attach( int $id, int $post_id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( $wpdb->prefix, 'quality_reports' ), array( 'post_id' => $post_id ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Ein Bericht.
	 *
	 * @param int $id Bericht.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'quality_reports' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Neuester Bericht eines Beitrags.
	 *
	 * @param int $post_id Beitrag.
	 * @return array<string, mixed>|null
	 */
	public function latest_for_post( int $post_id ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'quality_reports' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * JSON-Felder entpacken.
	 *
	 * @param array<string, mixed> $row Zeile.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$checks = json_decode( (string) $row['checks'], true );
		return array(
			'id'         => (int) $row['id'],
			'post_id'    => null === $row['post_id'] ? null : (int) $row['post_id'],
			'run_id'     => (int) $row['run_id'],
			'profile'    => (string) $row['profile'],
			'score'      => null === $row['score'] ? null : (float) $row['score'],
			'passed'     => 1 === (int) $row['passed'],
			'blocking'   => (array) json_decode( (string) $row['blocking_issues'], true ),
			'warnings'   => (array) json_decode( (string) $row['warnings'], true ),
			'checks'     => is_array( $checks ) ? (array) ( $checks['rules'] ?? array() ) : array(),
			'review'     => is_array( $checks ) ? ( $checks['review'] ?? null ) : null,
			'words'      => is_array( $checks ) ? ( $checks['words'] ?? null ) : null,
			'created_at' => gmdate( 'c', (int) strtotime( (string) $row['created_at'] . ' UTC' ) ),
		);
	}
}
