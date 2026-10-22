<?php
/**
 * Inhaltsindex bestehender Beiträge, Seiten, Produkte und Kategorieseiten.
 *
 * @package Lsec
 */

namespace Lsec\Content;

use Lsec\Core\Settings;

/**
 * Spezifikation 5: Je veröffentlichtem Inhalt werden Titel, Überschriften, Wortzahl, Kategorien,
 * Rank-Math-Fokus-Keyword und ein Inhalts-Hash als Post- oder Term-Meta gespeichert (Nachtrag 22.10.:
 * auch Produkte und Kategorieseiten). Den Text selbst liest
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
	 * Feste Bezeichnungen; WordPress nennt Beitrags- und Produktkategorien beide „Kategorien“.
	 */
	private const LABELS = array(
		'post'        => 'Blogbeiträge',
		'page'        => 'Seiten',
		'product'     => 'Produkte',
		'category'    => 'Beitragskategorien',
		'product_cat' => 'Produktkategorien',
	);

	/**
	 * Indizierte Inhaltstypen: Beiträge, Seiten und, wenn WooCommerce aktiv ist, Produkte.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$defaults = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$defaults[] = 'product';
		}
		/**
		 * Inhaltstypen für den Inhaltsindex.
		 *
		 * @param string[] $types Standard: post, page und product (WooCommerce).
		 */
		$types = apply_filters( 'lsec_index_post_types', $defaults );
		return array_values( array_filter( array_map( 'strval', (array) $types ), 'post_type_exists' ) );
	}

	/**
	 * Indizierte Taxonomien: Kategorieseiten der Beiträge und, mit WooCommerce, der Produkte.
	 *
	 * @return string[]
	 */
	public static function taxonomies(): array {
		/**
		 * Taxonomien für den Inhaltsindex (Kategorieseiten).
		 *
		 * @param string[] $taxonomies Standard: category und product_cat (WooCommerce).
		 */
		$taxonomies = apply_filters( 'lsec_index_taxonomies', array( 'category', 'product_cat' ) );
		return array_values( array_filter( array_map( 'strval', (array) $taxonomies ), 'taxonomy_exists' ) );
	}

	/**
	 * Alle indizierten Arten mit Bezeichnung, für Einstellungen und Dashboard.
	 *
	 * @return array<string, array{label: string, object: string}>
	 */
	public static function kinds(): array {
		$out = array();
		foreach ( self::post_types() as $type ) {
			$object       = get_post_type_object( $type );
			$out[ $type ] = array(
				'label'  => self::LABELS[ $type ] ?? ( null !== $object ? (string) $object->labels->name : $type ),
				'object' => 'post',
			);
		}
		foreach ( self::taxonomies() as $taxonomy ) {
			$object           = get_taxonomy( $taxonomy );
			$out[ $taxonomy ] = array(
				'label'  => self::LABELS[ $taxonomy ] ?? ( false !== $object ? (string) $object->labels->name : $taxonomy ),
				'object' => 'term',
			);
		}
		return $out;
	}

	/**
	 * Für welche Arten die automatische Entity-Erkennung läuft (Einstellung entity_extract_types).
	 *
	 * @return string[]
	 */
	public static function extract_kinds(): array {
		return array_values( array_intersect( array_map( 'strval', (array) Settings::get( 'entity_extract_types' ) ), array_keys( self::kinds() ) ) );
	}

	/**
	 * Standardkategorien („Allgemein“, „Unkategorisiert“) haben keine eigene Seite, die etwas aussagt.
	 *
	 * @return int[]
	 */
	public static function excluded_terms(): array {
		return array_values( array_filter( array( (int) get_option( 'default_category', 0 ), (int) get_option( 'default_product_cat', 0 ) ) ) );
	}

	/**
	 * WooCommerce-Systemseiten (Warenkorb, Kasse, Kundenkonto) sind keine Inhalte zum Verlinken.
	 *
	 * @return int[]
	 */
	public static function excluded_posts(): array {
		$ids = array();
		foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $option ) {
			$ids[] = (int) get_option( $option, 0 );
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Kennungen („post:12“, „term:34“), die (neu) indiziert werden müssen: ohne Index oder als geändert vorgemerkt.
	 *
	 * @param bool $all Alle veröffentlichten Inhalte.
	 * @return string[]
	 */
	public static function pending_ids( bool $all = false ): array {
		$base    = array(
			'post_type'        => self::post_types(),
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'post__not_in'     => self::excluded_posts(),
		);
		$missing = array(
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				array(
					'key'     => self::META,
					'compare' => 'NOT EXISTS',
				),
			),
		);
		$dirty   = array( 'meta_key' => self::DIRTY ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key

		$posts = array() === $base['post_type'] ? array() : ( $all ? get_posts( $base ) : array_merge( get_posts( $base + $missing ), get_posts( $base + $dirty ) ) );
		$posts = array_unique( array_map( 'intval', $posts ) );
		sort( $posts );

		$terms = array();
		if ( array() !== self::taxonomies() ) {
			$term_base = array(
				'taxonomy'   => self::taxonomies(),
				'hide_empty' => false,
				'fields'     => 'ids',
				'exclude'    => self::excluded_terms(),
				'orderby'    => 'term_id',
			);
			$found     = $all ? get_terms( $term_base ) : array_merge( (array) get_terms( $term_base + $missing ), (array) get_terms( $term_base + $dirty ) );
			$terms     = is_array( $found ) ? array_unique( array_map( 'intval', $found ) ) : array();
			sort( $terms );
		}

		return array_merge(
			array_map( static fn( int $id ): string => 'post:' . $id, $posts ),
			array_map( static fn( int $id ): string => 'term:' . $id, $terms )
		);
	}

	/**
	 * Indiziert einen Beitrag.
	 *
	 * @param \WP_Post $post Beitrag.
	 * @return array{changed: bool, hash: string, words: int}
	 */
	public static function index_post( \WP_Post $post ): array {
		return self::index_item( IndexItem::from_post( $post ) );
	}

	/**
	 * Indiziert einen Inhalt (Beitrag, Seite, Produkt oder Kategorie).
	 *
	 * @param IndexItem $item Inhalt.
	 * @return array{changed: bool, hash: string, words: int}
	 */
	public static function index_item( IndexItem $item ): array {
		$content  = $item->read();
		$title    = $item->title();
		$hash     = self::hash( $title, $content['text'] );
		$previous = $item->meta( self::META );
		$changed  = ! is_array( $previous ) || ( $previous['hash'] ?? '' ) !== $hash;

		$item->set_meta(
			self::META,
			array(
				'hash'          => $hash,
				'kind'          => $item->kind,
				'title'         => $title,
				'words'         => $content['words'],
				'source'        => $content['source'],
				'headings'      => array_slice( array_map( static fn( array $h ): string => $h['text'], $content['headings'] ), 0, 40 ),
				'categories'    => $item->categories(),
				'focus_keyword' => (string) $item->meta( 'rank_math_focus_keyword' ),
				'indexed_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$item->delete_meta( self::DIRTY );
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
		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) || in_array( $post_id, self::excluded_posts(), true ) ) {
			return;
		}
		update_post_meta( $post_id, self::DIRTY, 1 );
	}

	/**
	 * Merkt eine gespeicherte Kategorie zur Neuindizierung vor (created_term, edited_term).
	 *
	 * @param int    $term_id  Kategorie.
	 * @param int    $tt_id    Taxonomie-Eintrag.
	 * @param string $taxonomy Taxonomie.
	 */
	public static function mark_term_dirty( int $term_id, int $tt_id = 0, string $taxonomy = '' ): void {
		unset( $tt_id );
		if ( in_array( $taxonomy, self::taxonomies(), true ) && ! in_array( $term_id, self::excluded_terms(), true ) ) {
			update_term_meta( $term_id, self::DIRTY, 1 );
		}
	}

	/**
	 * Stand für Dashboard und WP-CLI, gesamt und je Art.
	 *
	 * @return array{published: int, indexed: int, pending: int, last_indexed_at: string|null, kinds: array<string, array{label: string, published: int, indexed: int, extract: bool}>}
	 */
	public static function stats(): array {
		global $wpdb;
		$kinds   = self::kinds();
		$extract = self::extract_kinds();
		$counts  = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$types = self::post_types();
		if ( array() !== $types ) {
			$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
			$excluded     = array_merge( array( 0 ), self::excluded_posts() );
			$ex_holders   = implode( ', ', array_fill( 0, count( $excluded ), '%d' ) );
			$rows         = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.post_type AS kind, COUNT(*) AS published,
					SUM( EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s)
						AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} d WHERE d.post_id = p.ID AND d.meta_key = %s) ) AS indexed
					FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders}) AND p.ID NOT IN ({$ex_holders}) GROUP BY p.post_type",
					array_merge( array( self::META, self::DIRTY ), $types, $excluded )
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$counts[ (string) $row['kind'] ] = array( (int) $row['published'], (int) $row['indexed'] );
			}
		}
		$taxonomies = self::taxonomies();
		if ( array() !== $taxonomies ) {
			$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
			$excluded     = array_merge( array( 0 ), self::excluded_terms() );
			$ex_holders   = implode( ', ', array_fill( 0, count( $excluded ), '%d' ) );
			$rows         = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tt.taxonomy AS kind, COUNT(*) AS published,
					SUM( EXISTS (SELECT 1 FROM {$wpdb->termmeta} m WHERE m.term_id = tt.term_id AND m.meta_key = %s)
						AND NOT EXISTS (SELECT 1 FROM {$wpdb->termmeta} d WHERE d.term_id = tt.term_id AND d.meta_key = %s) ) AS indexed
					FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy IN ({$placeholders}) AND tt.term_id NOT IN ({$ex_holders}) GROUP BY tt.taxonomy",
					array_merge( array( self::META, self::DIRTY ), $taxonomies, $excluded )
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$counts[ (string) $row['kind'] ] = array( (int) $row['published'], (int) $row['indexed'] );
			}
		}
		// phpcs:enable

		$by_kind   = array();
		$published = 0;
		$indexed   = 0;
		foreach ( $kinds as $kind => $info ) {
			[ $p, $i ]        = $counts[ $kind ] ?? array( 0, 0 );
			$published       += $p;
			$indexed         += $i;
			$by_kind[ $kind ] = array(
				'label'     => $info['label'],
				'published' => $p,
				'indexed'   => $i,
				'extract'   => in_array( $kind, $extract, true ),
			);
		}
		$last = (int) get_option( self::LAST_RUN, 0 );
		return array(
			'published'       => $published,
			'indexed'         => $indexed,
			'pending'         => max( 0, $published - $indexed ),
			'last_indexed_at' => $last > 0 ? gmdate( 'Y-m-d H:i:s', $last ) : null,
			'kinds'           => $by_kind,
		);
	}

	/**
	 * Wie viele Inhalte der ausgewählten Arten noch eine Entity-Erkennung brauchen (für die Kostenschätzung
	 * vor dem Start). Ungelesene Inhalte sind noch nicht im Index; ihre Wortzahl ist unbekannt.
	 *
	 * @return array{due: int, unread: int}
	 */
	public static function extraction_due(): array {
		global $wpdb;
		$out   = array(
			'due'    => 0,
			'unread' => 0,
		);
		$kinds = self::kinds();
		$posts = array();
		$terms = array();
		foreach ( self::extract_kinds() as $kind ) {
			if ( 'term' === $kinds[ $kind ]['object'] ) {
				$terms[] = $kind;
			} else {
				$posts[] = $kind;
			}
		}
		$rows = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( array() !== $posts ) {
			$placeholders = implode( ', ', array_fill( 0, count( $posts ), '%s' ) );
			$excluded     = array_merge( array( 0 ), self::excluded_posts() );
			$ex_holders   = implode( ', ', array_fill( 0, count( $excluded ), '%d' ) );
			$rows         = array_merge(
				$rows,
				(array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT m.meta_value AS entry, x.meta_value AS extracted FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
						LEFT JOIN {$wpdb->postmeta} x ON x.post_id = p.ID AND x.meta_key = %s
						WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders}) AND p.ID NOT IN ({$ex_holders})",
						array_merge( array( self::META, self::EXTRACTED ), $posts, $excluded )
					),
					ARRAY_A
				)
			);
		}
		if ( array() !== $terms ) {
			$placeholders = implode( ', ', array_fill( 0, count( $terms ), '%s' ) );
			$excluded     = array_merge( array( 0 ), self::excluded_terms() );
			$ex_holders   = implode( ', ', array_fill( 0, count( $excluded ), '%d' ) );
			$rows         = array_merge(
				$rows,
				(array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT m.meta_value AS entry, x.meta_value AS extracted FROM {$wpdb->term_taxonomy} tt
						LEFT JOIN {$wpdb->termmeta} m ON m.term_id = tt.term_id AND m.meta_key = %s
						LEFT JOIN {$wpdb->termmeta} x ON x.term_id = tt.term_id AND x.meta_key = %s
						WHERE tt.taxonomy IN ({$placeholders}) AND tt.term_id NOT IN ({$ex_holders})",
						array_merge( array( self::META, self::EXTRACTED ), $terms, $excluded )
					),
					ARRAY_A
				)
			);
		}
		// phpcs:enable
		foreach ( $rows as $row ) {
			$entry = null === $row['entry'] ? null : maybe_unserialize( (string) $row['entry'] );
			if ( ! is_array( $entry ) ) {
				++$out['unread'];
				continue;
			}
			if ( (int) ( $entry['words'] ?? 0 ) >= ContentIndexWorkflow::MIN_WORDS && (string) ( $entry['hash'] ?? '' ) !== (string) $row['extracted'] ) {
				++$out['due'];
			}
		}
		return $out;
	}

	/**
	 * Indizierte Inhalte mit Titel und Adresse, z. B. für die Überschneidungsprüfung der Themen.
	 *
	 * @param int $limit Höchstzahl je Objektart.
	 * @return array<int, array{ref: string, post_id: int, title: string, url: string, kind: string}>
	 */
	public static function entries( int $limit = 500 ): array {
		$out   = array();
		$posts = array() === self::post_types() ? array() : get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'post__not_in'     => self::excluded_posts(),
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$item  = IndexItem::from_post( $post );
				$out[] = array(
					'ref'     => $item->ref(),
					'post_id' => (int) $post->ID,
					'title'   => $item->title(),
					'url'     => $item->url(),
					'kind'    => $item->kind,
				);
			}
		}
		if ( array() !== self::taxonomies() ) {
			$terms = get_terms(
				array(
					'taxonomy'   => self::taxonomies(),
					'hide_empty' => false,
					'number'     => $limit,
					'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'exclude'    => self::excluded_terms(),
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( $term instanceof \WP_Term ) {
					$item  = IndexItem::from_term( $term );
					$out[] = array(
						'ref'     => $item->ref(),
						'post_id' => 0,
						'title'   => $item->title(),
						'url'     => $item->url(),
						'kind'    => $item->kind,
					);
				}
			}
		}
		return $out;
	}
}
