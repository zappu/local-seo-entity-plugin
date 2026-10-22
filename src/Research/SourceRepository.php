<?php
/**
 * Speichert Quellen.
 *
 * @package Lsec
 */

namespace Lsec\Research;

use Lsec\Infrastructure\Database\Schema;

/**
 * Zugriff auf lsec_sources. Jede URL gibt es einmal (url_hash); ein erneuter Fund aktualisiert den Titel.
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
				// fetched_at bleibt: Er gilt für den gespeicherten Text (Zwischenspeicher der Faktenprüfung).
				if ( '' !== $title ) {
					$wpdb->update( $table, array( 'title' => $title ), array( 'id' => $id ) );
				}
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

	/**
	 * Zwischenspeicher für abgerufene Seiten (Spezifikation 8.4a).
	 */
	public const CACHE_DAYS = 30;

	/**
	 * Eine Quelle mit gespeichertem Text.
	 *
	 * @param int $id Quelle.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'sources' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Quelle zu einer Adresse anlegen oder finden.
	 *
	 * @param string $url   Adresse.
	 * @param string $title Titel.
	 * @param string $type  source_type.
	 * @return int ID oder 0 bei ungültiger Adresse.
	 */
	public function upsert( string $url, string $title, string $type ): int {
		return $this->save_all(
			array(
				array(
					'url'   => $url,
					'title' => $title,
				),
			),
			$type
		)[0] ?? 0;
	}

	/**
	 * Gespeicherter Text, wenn er jünger als CACHE_DAYS ist.
	 *
	 * @param int $id Quelle.
	 * @return array{text: string|null, error: string|null}|null Null, wenn neu abgerufen werden muss.
	 */
	public function cached_content( int $id ): ?array {
		$row = $this->find( $id );
		if ( null === $row || ( null === $row['content_hash'] && null === $row['fetch_error'] ) ) {
			return null;
		}
		$fetched = strtotime( (string) $row['fetched_at'] . ' UTC' );
		if ( false === $fetched || $fetched < time() - self::CACHE_DAYS * DAY_IN_SECONDS ) {
			return null;
		}
		return array(
			'text'  => null === $row['content'] ? null : (string) $row['content'],
			'error' => null === $row['fetch_error'] ? null : (string) $row['fetch_error'],
		);
	}

	/**
	 * Speichert den abgerufenen Text oder den Fehler.
	 *
	 * @param int         $id    Quelle.
	 * @param string|null $text  Text.
	 * @param string|null $error Fehler.
	 * @param string      $title Titel aus der Seite.
	 */
	public function save_content( int $id, ?string $text, ?string $error, string $title = '' ): void {
		global $wpdb;
		$data = array(
			'content'      => $text,
			'content_hash' => null === $text ? null : hash( 'sha256', $text ),
			'fetch_error'  => null === $error ? null : mb_substr( $error, 0, 100 ),
			'fetched_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( '' !== $title ) {
			$data['title'] = mb_substr( sanitize_text_field( $title ), 0, 255 );
		}
		$wpdb->update( Schema::table( $wpdb->prefix, 'sources' ), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
