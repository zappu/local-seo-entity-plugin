<?php
/**
 * Versionen des Style Mimic.
 *
 * @package Lsec
 */

namespace Lsec\Production;

use Lsec\Infrastructure\Database\Schema;

/**
 * Zugriff auf lsec_style_profiles (Spezifikation 4.4 und 8.7). Jede Änderung erzeugt eine neue Version;
 * genau eine Version ist aktiv.
 */
final class StyleProfileRepository {

	/**
	 * Aktives Profil.
	 *
	 * @return array<string, mixed>|null
	 */
	public function active(): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'style_profiles' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_active = 1 ORDER BY version DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Alle Versionen, neueste zuerst.
	 *
	 * @param int $limit Höchstzahl.
	 * @return array<int, array<string, mixed>>
	 */
	public function versions( int $limit = 50 ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'style_profiles' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY version DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'decode' ), $rows );
	}

	/**
	 * Eine Version.
	 *
	 * @param int $version Version.
	 * @return array<string, mixed>|null
	 */
	public function find( int $version ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'style_profiles' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE version = %d", $version ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Speichert eine neue Version und macht sie aktiv.
	 *
	 * @param array<string, mixed> $profile Profil (8.7), wird bereinigt.
	 * @param array<string, mixed> $inputs  Eingaben (Beiträge, Beispieltexte, Regeln, Herkunft).
	 * @param int                  $user_id Benutzer.
	 * @return int Version.
	 */
	public function save( array $profile, array $inputs, int $user_id ): int {
		global $wpdb;
		$table   = Schema::table( $wpdb->prefix, 'style_profiles' );
		$version = 1 + (int) $wpdb->get_var( "SELECT MAX(version) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$table} SET is_active = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert(
			$table,
			array(
				'version'    => $version,
				'profile'    => (string) wp_json_encode( self::clean( $profile ), JSON_UNESCAPED_UNICODE ),
				'inputs'     => (string) wp_json_encode( $inputs, JSON_UNESCAPED_UNICODE ),
				'is_active'  => 1,
				'created_by' => $user_id > 0 ? $user_id : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		// phpcs:enable
		return $version;
	}

	/**
	 * Macht eine ältere Version wieder aktiv.
	 *
	 * @param int $version Version.
	 * @return bool Gefunden.
	 */
	public function activate( int $version ): bool {
		global $wpdb;
		if ( null === $this->find( $version ) ) {
			return false;
		}
		$table = Schema::table( $wpdb->prefix, 'style_profiles' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET is_active = 0" );
		$wpdb->update( $table, array( 'is_active' => 1 ), array( 'version' => $version ) );
		// phpcs:enable
		return true;
	}

	/**
	 * Bereinigt ein Profil auf die Felder aus 8.7.
	 *
	 * @param array<string, mixed> $profile Profil.
	 * @return array<string, mixed>
	 */
	public static function clean( array $profile ): array {
		$text  = static fn( $v, int $max = 300 ): string => mb_substr( trim( wp_strip_all_tags( (string) $v ) ), 0, $max );
		$list  = static fn( $v, int $max = 20 ): array => array_values( array_slice( array_filter( array_map( static fn( $x ): string => mb_substr( trim( wp_strip_all_tags( (string) $x ) ), 0, 300 ), is_array( $v ) ? $v : array() ) ), 0, $max ) );
		$vocab = is_array( $profile['vocabulary'] ?? null ) ? $profile['vocabulary'] : array();
		$ex    = array();
		foreach ( is_array( $profile['examples'] ?? null ) ? $profile['examples'] : array() as $example ) {
			if ( is_array( $example ) && ( '' !== trim( (string) ( $example['do'] ?? '' ) ) || '' !== trim( (string) ( $example['dont'] ?? '' ) ) ) ) {
				$ex[] = array(
					'do'   => $text( $example['do'] ?? '', 500 ),
					'dont' => $text( $example['dont'] ?? '', 500 ),
				);
			}
		}
		return array(
			'tone'             => $text( $profile['tone'] ?? '' ),
			'address'          => in_array( (string) ( $profile['address'] ?? '' ), array( 'Sie', 'du', 'ihr', 'neutral' ), true ) ? (string) $profile['address'] : 'Sie',
			'sentence_length'  => $text( $profile['sentence_length'] ?? '', 100 ),
			'vocabulary'       => array(
				'preferred' => $list( $vocab['preferred'] ?? array(), 40 ),
				'avoid'     => $list( $vocab['avoid'] ?? array(), 40 ),
			),
			'structure_habits' => $list( $profile['structure_habits'] ?? array() ),
			'rules'            => $list( $profile['rules'] ?? array(), 30 ),
			'examples'         => array_slice( $ex, 0, 8 ),
		);
	}

	/**
	 * JSON-Felder entpacken.
	 *
	 * @param array<string, mixed> $row Zeile.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$profile = json_decode( (string) $row['profile'], true );
		$inputs  = json_decode( (string) $row['inputs'], true );
		return array(
			'id'         => (int) $row['id'],
			'version'    => (int) $row['version'],
			'profile'    => is_array( $profile ) ? $profile : array(),
			'inputs'     => is_array( $inputs ) ? $inputs : array(),
			'is_active'  => 1 === (int) $row['is_active'],
			'created_at' => gmdate( 'c', (int) strtotime( (string) $row['created_at'] . ' UTC' ) ),
		);
	}
}
