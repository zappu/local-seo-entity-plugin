<?php
/**
 * Inhaltsindex bestehender Beiträge und Seiten.
 *
 * @package Lsec
 */

namespace Lsec\Content;

/**
 * Spezifikation 5: Je veröffentlichtem Beitrag werden Titel, Überschriften, Wortzahl, Kategorien,
 * Rank-Math-Fokus-Keyword und ein Inhalts-Hash als Post-Meta gespeichert. Den Text selbst liest
 * ContentReader bei Bedarf neu; er wird nicht doppelt abgelegt.
 *
 * Geänderte Beiträge werden über save_post vorgemerkt. Ein unveränderter Hash löst keine neue
 * Entity-Erkennung aus.
 */
final class ContentIndex {

	public const META  = '_lsec_index';
	public const DIRTY = '_lsec_index_dirty';

	/**
	 * Hash des Inhalts, für den die Entity-Erkennung zuletzt gelaufen ist.
	 */
	public const EXTRACTED = '_lsec_extracted_hash';

	/**
	 * Option mit dem Zeitpunkt des letzten abgeschlossenen Indexlaufs.
	 */
	public const LAST_RUN = 'lsec_index_last_run';

	/**
	 * Beiträge je Durchgang (Spezifikation 5: 20 je Schritt).
	 */
	public const BATCH = 20;

	/**
	 * Indizierte Inhaltstypen.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		/**
		 * Inhaltstypen für den Inhaltsindex.
		 *
		 * @param string[] $types Standard: post, page.
		 */
		$types = apply_filters( 'lsec_index_post_types', array( 'post', 'page' ) );
		return array_values( array_filter( array_map( 'strval', (array) $types ) ) );
	}

	/**
	 * IDs, die (neu) indiziert werden müssen: ohne Index oder als geändert vorgemerkt.
	 *
	 * @param bool $all Alle veröffentlichten Inhalte.
	 * @return int[]
	 */
	public static function pending_ids( bool $all = false ): array {
		$base = array(
			'post_type'        => self::post_types(),
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);
		if ( $all ) {
			return array_map( 'intval', get_posts( $base ) );
		}
		$missing = get_posts(
			$base + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
					array(
						'key'     => self::META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$dirty   = get_posts( $base + array( 'meta_key' => self::DIRTY ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
		$ids     = array_unique( array_map( 'intval', array_merge( $missing, $dirty ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Indiziert einen Beitrag.
	 *
	 * @param \WP_Post $post Beitrag.
	 * @return array{changed: bool, hash: string, words: int}
	 */
	public static function index_post( \WP_Post $post ): array {
		$content  = ContentReader::read( $post );
		$title    = ContentReader::title( $post );
		$hash     = self::hash( $title, $content['text'] );
		$previous = get_post_meta( $post->ID, self::META, true );
		$changed  = ! is_array( $previous ) || ( $previous['hash'] ?? '' ) !== $hash;

		$categories = 'post' === $post->post_type ? wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ) : array();
		update_post_meta(
			$post->ID,
			self::META,
			array(
				'hash'          => $hash,
				'title'         => $title,
				'words'         => $content['words'],
				'source'        => $content['source'],
				'headings'      => array_slice( array_map( static fn( array $h ): string => $h['text'], $content['headings'] ), 0, 40 ),
				'categories'    => is_array( $categories ) ? array_values( array_map( 'strval', $categories ) ) : array(),
				'focus_keyword' => (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
				'indexed_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		delete_post_meta( $post->ID, self::DIRTY );
		return array(
			'changed' => $changed,
			'hash'    => $hash,
			'words'   => $content['words'],
		);
	}

	/**
	 * Fingerabdruck des Inhalts. Leerraum zählt nicht als Änderung.
	 *
	 * @param string $title Titel.
	 * @param string $text  Text.
	 */
	public static function hash( string $title, string $text ): string {
		return hash( 'sha256', trim( (string) preg_replace( '/\s+/u', ' ', $title . "\n" . $text ) ) );
	}

	/**
	 * Merkt einen gespeicherten Beitrag zur Neuindizierung vor (save_post).
	 *
	 * @param int      $post_id Beitrag.
	 * @param \WP_Post $post    Beitrag.
	 */
	public static function mark_dirty( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		update_post_meta( $post_id, self::DIRTY, 1 );
	}

	/**
	 * Stand für Dashboard und WP-CLI.
	 *
	 * @return array{published: int, indexed: int, pending: int, last_indexed_at: string|null}
	 */
	public static function stats(): array {
		global $wpdb;
		$types        = self::post_types();
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$published = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})", $types ) );
		$indexed   = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})
				AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = %s)",
				array_merge( array( self::META ), $types, array( self::DIRTY ) )
			)
		);
		// phpcs:enable
		$last = (int) get_option( self::LAST_RUN, 0 );
		return array(
			'published'       => $published,
			'indexed'         => $indexed,
			'pending'         => max( 0, $published - $indexed ),
			'last_indexed_at' => $last > 0 ? gmdate( 'Y-m-d H:i:s', $last ) : null,
		);
	}
}
