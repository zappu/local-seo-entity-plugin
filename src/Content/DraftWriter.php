<?php
/**
 * Legt Beitragsentwürfe an.
 *
 * @package Lsec
 */

namespace Lsec\Content;

/**
 * Spezifikation 9.1 und 9.2 (Prüfpunkte M12, M13):
 * - immer post_status draft
 * - Inhalt als Blöcke aus BlockBuilder
 * - interne Links nur auf veröffentlichte Beiträge dieser Website, sonst bleibt nur der Ankertext
 * - Kategorien nur bestehende; neue Vorschläge werden als Meta gespeichert
 * - Rank Math: rank_math_title, rank_math_description, rank_math_focus_keyword (Haupt-Keyword zuerst)
 * - ohne Rank Math nur Vorschlag in _lsec_seo_suggestion, keine eigene Ausgabe
 */
final class DraftWriter {

	/**
	 * Konstruktor.
	 *
	 * @param BlockBuilder $blocks Block-Erzeugung.
	 */
	public function __construct( private readonly BlockBuilder $blocks ) {}

	/**
	 * Läuft Rank Math tatsächlich?
	 *
	 * Rank Math definiert RANK_MATH_VERSION schon vor der eigenen Versionsprüfung und bleibt bei zu alter
	 * WordPress-Version inaktiv (Rank Math 1.0.280 verlangt WordPress 6.7). Verlässlich ist erst die Aktion
	 * rank_math/loaded, die nur nach bestandener Prüfung ausgelöst wird.
	 */
	public static function rank_math_active(): bool {
		return did_action( 'rank_math/loaded' ) > 0;
	}

	/**
	 * Legt den Entwurf an.
	 *
	 * @param array<string, mixed> $draft   Entwurf: title, excerpt, content (Knoten), seo{title, description, focus_keyword, secondary_keywords[], slug}, taxonomy{category_ids[], new_category_suggestions[], tags[]}.
	 * @param int                  $author  Autor.
	 * @param int|null             $run_id  Auslösender Ablauf.
	 * @return array{post_id: int, links_kept: int, links_removed: string[], categories: int[], suggested_categories: string[], rank_math: bool}
	 * @throws \RuntimeException Wenn der Beitrag nicht angelegt werden kann.
	 */
	public function create( array $draft, int $author, ?int $run_id = null ): array {
		$nodes   = (array) ( $draft['content'] ?? array() );
		$checked = $this->check_links( $nodes );
		$content = $this->blocks->build( $checked['nodes'] );

		$seo      = is_array( $draft['seo'] ?? null ) ? $draft['seo'] : array();
		$taxonomy = is_array( $draft['taxonomy'] ?? null ) ? $draft['taxonomy'] : array();

		$categories = array_values(
			array_filter(
				array_map( 'intval', (array) ( $taxonomy['category_ids'] ?? array() ) ),
				static fn( int $id ): bool => $id > 0 && term_exists( $id, 'category' )
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'draft',
				'post_author'   => $author,
				'post_title'    => sanitize_text_field( (string) ( $draft['title'] ?? '' ) ),
				'post_excerpt'  => sanitize_textarea_field( (string) ( $draft['excerpt'] ?? '' ) ),
				'post_name'     => sanitize_title( (string) ( $seo['slug'] ?? '' ) ),
				'post_content'  => wp_slash( $content ),
				'post_category' => $categories,
				'tags_input'    => array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $taxonomy['tags'] ?? array() ) ) ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Entwurf nicht angelegt: ' . $post_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$keywords = array_values(
			array_filter(
				array_map(
					static fn( $k ): string => trim( str_replace( ',', ' ', sanitize_text_field( (string) $k ) ) ),
					array_merge( array( $seo['focus_keyword'] ?? '' ), (array) ( $seo['secondary_keywords'] ?? array() ) )
				)
			)
		);
		$seo_meta = array(
			'title'       => sanitize_text_field( (string) ( $seo['title'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $seo['description'] ?? '' ) ),
			'keywords'    => array_values( array_unique( $keywords ) ),
		);

		$rank_math = self::rank_math_active();
		if ( $rank_math ) {
			update_post_meta( $post_id, 'rank_math_title', $seo_meta['title'] );
			update_post_meta( $post_id, 'rank_math_description', $seo_meta['description'] );
			update_post_meta( $post_id, 'rank_math_focus_keyword', implode( ',', $seo_meta['keywords'] ) );
		} else {
			update_post_meta( $post_id, '_lsec_seo_suggestion', wp_slash( (string) wp_json_encode( $seo_meta, JSON_UNESCAPED_UNICODE ) ) );
		}

		$suggested = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $taxonomy['new_category_suggestions'] ?? array() ) ) ) ) );
		if ( array() !== $suggested ) {
			update_post_meta( $post_id, '_lsec_category_suggestions', wp_slash( (string) wp_json_encode( $suggested, JSON_UNESCAPED_UNICODE ) ) );
		}
		update_post_meta( $post_id, '_lsec_generated_at', gmdate( 'Y-m-d H:i:s' ) );
		if ( null !== $run_id ) {
			update_post_meta( $post_id, '_lsec_run_id', $run_id );
		}

		return array(
			'post_id'              => (int) $post_id,
			'links_kept'           => $checked['kept'],
			'links_removed'        => $checked['removed'],
			'categories'           => $categories,
			'suggested_categories' => $suggested,
			'rank_math'            => $rank_math,
		);
	}

	/**
	 * Prüft Links in allen Texten: intern nur auf veröffentlichte Inhalte, sonst Link entfernen.
	 * Externe Links bleiben erhalten und erhalten keine weiteren Attribute.
	 *
	 * @param array<int, mixed> $nodes Knoten.
	 * @return array{nodes: array<int, array<string, mixed>>, kept: int, removed: string[]}
	 */
	private function check_links( array $nodes ): array {
		$kept    = 0;
		$removed = array();
		$home    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$fix = function ( string $text ) use ( &$kept, &$removed, $home ): string {
			return (string) preg_replace_callback(
				'#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
				static function ( array $m ) use ( &$kept, &$removed, $home ): string {
					$url  = html_entity_decode( $m[2], ENT_QUOTES );
					$host = (string) wp_parse_url( $url, PHP_URL_HOST );
					if ( '' !== $host && strcasecmp( $host, $home ) !== 0 ) {
						return '<a href="' . esc_url( $url ) . '">' . $m[3] . '</a>';
					}
					$post_id = url_to_postid( $url );
					if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) ) {
						++$kept;
						return '<a href="' . esc_url( (string) get_permalink( $post_id ) ) . '">' . $m[3] . '</a>';
					}
					$removed[] = $url;
					return $m[3];
				},
				$text
			);
		};

		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			foreach ( array( 'text', 'cite' ) as $field ) {
				if ( isset( $node[ $field ] ) && is_string( $node[ $field ] ) ) {
					$node[ $field ] = $fix( $node[ $field ] );
				}
			}
			if ( isset( $node['items'] ) && is_array( $node['items'] ) ) {
				$node['items'] = array_map( static fn( $i ): string => $fix( (string) $i ), $node['items'] );
			}
			if ( isset( $node['rows'] ) && is_array( $node['rows'] ) ) {
				$node['rows'] = array_map( static fn( $row ): array => array_map( static fn( $c ): string => $fix( (string) $c ), (array) $row ), $node['rows'] );
			}
			$out[] = $node;
		}
		return array(
			'nodes'   => $out,
			'kept'    => $kept,
			'removed' => $removed,
		);
	}
}
