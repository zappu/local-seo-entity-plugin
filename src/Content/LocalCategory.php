<?php
/**
 * Eigene Kategorie für Ortsseiten.
 *
 * @package Lsec
 */

namespace Lsec\Content;

use Lsec\Core\Settings;
use Lsec\Topics\TopicRepository;

/**
 * Nachtrag 24.10.2026 (0.10.1, Wunsch von Thomas): Ortsseiten (Beitragstyp local_landing) bekommen zusätzlich eine
 * feste Kategorie, z. B. „Regional Service“. Theme und Elementor-Beitragslisten können sie dann ausschließen, und
 * das Archiv der Kategorie verlinkt alle Ortsseiten, so dass sie für Google erreichbar bleiben. Ratgeber und Artikel
 * bleiben in ihren fachlichen Kategorien.
 *
 * Einstellung local_category: Name oder Titelform (Slug) einer vorhandenen Kategorie; fehlt sie, wird sie mit dem
 * Namen angelegt. Leer = aus. Mit local_category_hide blendet das Plugin die Ortsseiten zusätzlich auf der
 * Blogseite (Hauptabfrage) aus.
 */
final class LocalCategory {

	/**
	 * Kategorie nach Einstellung.
	 *
	 * @param bool $create Anlegen, wenn sie fehlt.
	 * @return int Term-ID oder 0.
	 */
	public static function term_id( bool $create = true ): int {
		$value = trim( (string) Settings::get( 'local_category' ) );
		if ( '' === $value ) {
			return 0;
		}
		foreach ( array(
			'slug' => sanitize_title( $value ),
			'name' => $value,
		) as $field => $search ) {
			$term = get_term_by( $field, $search, 'category' );
			if ( $term instanceof \WP_Term ) {
				return (int) $term->term_id;
			}
		}
		if ( ! $create ) {
			return 0;
		}
		$created = wp_insert_term( $value, 'category' );
		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	/**
	 * Ordnet einen Beitrag der Kategorie zu (zusätzlich). Die Standardkategorie fällt weg, wenn sie die einzige war.
	 *
	 * @param int $post_id Beitrag.
	 * @return bool Ob der Beitrag neu zugeordnet wurde.
	 */
	public static function assign( int $post_id ): bool {
		$term = self::term_id();
		if ( $term <= 0 ) {
			return false;
		}
		$current = array_map( 'intval', (array) wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) ) );
		if ( in_array( $term, $current, true ) ) {
			return false;
		}
		$default = (int) get_option( 'default_category' );
		$keep    = array_values( array_filter( $current, static fn( int $id ): bool => $id !== $default ) );
		wp_set_post_categories( $post_id, array_merge( $keep, array( $term ) ), false );
		return true;
	}

	/**
	 * Vorhandene Ortsseiten des Plugins.
	 *
	 * @return int[]
	 */
	public static function local_posts(): array {
		$ids    = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'meta_key'       => '_lsec_topic_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'fields'         => 'ids',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Befehl, einmalig.
			)
		);
		$topics = new TopicRepository();
		$out    = array();
		foreach ( $ids as $id ) {
			$topic = $topics->detail( (int) get_post_meta( (int) $id, '_lsec_topic_id', true ) );
			if ( null !== $topic && 'local_landing' === (string) $topic['format'] ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Blendet Ortsseiten auf der Blogseite aus (nur Hauptabfrage, nur wenn eingeschaltet).
	 *
	 * @param \WP_Query $query Abfrage.
	 */
	public static function hide_on_blog( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_home() || ! Settings::get( 'local_category_hide' ) ) {
			return;
		}
		$term = self::term_id( false );
		if ( $term > 0 ) {
			$query->set( 'category__not_in', array_merge( (array) $query->get( 'category__not_in' ), array( $term ) ) );
		}
	}
}
