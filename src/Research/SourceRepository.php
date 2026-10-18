<?php
/**
 * Speichert Quellen.
 *
 * @package Lsec
 */

namespace Lsec\Research;

use Lsec\Infrastructure\Database\Schema;

/**
 * Zugriff auf lsec_sources. Jede URL gibt es einmal (url_hash); ein erneuter Fund aktualisiert Titel und Zeitpunkt.
 */
final class SourceRepository {

	/**
	 * Speichert Quellen, z. B. aus der KI-Websuche (M7a).
	 *
	 * @param array<int, array{url: string, title: string}> $sources Quellen.
	 * @param string                                        $type    source_type, z. B. ai_search.
	 * @return int[] IDs in derselben Reihenfolge (ungültige URLs fehlen).
	 */
	public function save_all( array $sources, string $type ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'sources' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$ids   = array();
		foreach ( $sources as $source ) {
			$url = esc_url_raw( self::canonical( trim( $source['url'] ) ) );
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$hash  = hash( 'sha256', $url );
			$title = mb_substr( sanitize_text_field( $source['title'] ), 0, 255 );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE url_hash = %s", $hash ) );
			if ( $id > 0 ) {
				$wpdb->update(
					$table,
					array(
						'title'      => '' !== $title ? $title : null,
						'fetched_at' => $now,
					),
					array( 'id' => $id )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'url'         => $url,
						'url_hash'    => $hash,
						'title'       => '' !== $title ? $title : null,
						'source_type' => $type,
						'fetched_at'  => $now,
						'reliability' => 'unknown',
						'created_at'  => $now,
					)
				);
				$id = (int) $wpdb->insert_id;
			}
			// phpcs:enable
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Entfernt Tracking-Parameter, damit dieselbe Seite nur einmal gespeichert wird.
	 * OpenAI hängt an Quellen der Websuche ?utm_source=openai an (beobachtet am 18.10.2026).
	 *
	 * @param string $url Adresse.
	 */
	public static function canonical( string $url ): string {
		$parts = explode( '#', $url, 2 );
		$base  = $parts[0];
		$query = '';
		if ( str_contains( $base, '?' ) ) {
			list( $base, $query ) = explode( '?', $base, 2 );
		}
		if ( '' === $query ) {
			return $base;
		}
		$kept = array();
		foreach ( explode( '&', $query ) as $pair ) {
			$name = strtolower( (string) strtok( $pair, '=' ) );
			if ( '' === $name || str_starts_with( $name, 'utm_' ) || in_array( $name, array( 'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'srsltid' ), true ) ) {
				continue;
			}
			$kept[] = $pair;
		}
		return array() === $kept ? $base : $base . '?' . implode( '&', $kept );
	}
}
