<?php
/**
 * Liest den Text eines Beitrags für den Inhaltsindex.
 *
 * @package Lsec
 */

namespace Lsec\Content;

/**
 * Elementor-Seiten über _elementor_data, sonst post_content (Blöcke oder klassischer Editor).
 */
final class ContentReader {

	/**
	 * Text, Überschriften und Quelle eines Beitrags.
	 *
	 * @param \WP_Post $post Beitrag.
	 * @return array{text: string, headings: array<int, array{level: int, text: string}>, source: string, words: int}
	 */
	public static function read( \WP_Post $post ): array {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$raw  = get_post_meta( $post->ID, '_elementor_data', true );
			$data = is_string( $raw ) ? ElementorReader::read( $raw ) : ( is_array( $raw ) ? ElementorReader::read( (string) wp_json_encode( $raw ) ) : null );
			if ( null !== $data && '' !== $data['text'] ) {
				return array(
					'text'     => $data['text'],
					'headings' => $data['headings'],
					'source'   => 'elementor',
					'words'    => self::words( $data['text'] ),
				);
			}
		}
		$html     = has_blocks( $post->post_content ) ? (string) do_blocks( $post->post_content ) : (string) wpautop( $post->post_content );
		$headings = array();
		if ( preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$headings[] = array(
					'level' => (int) $match[1],
					'text'  => ElementorReader::plain( $match[2] ),
				);
			}
		}
		$text = ElementorReader::plain( strip_shortcodes( $html ) );
		return array(
			'text'     => $text,
			'headings' => $headings,
			'source'   => has_blocks( $post->post_content ) ? 'blocks' : 'classic',
			'words'    => self::words( $text ),
		);
	}

	/**
	 * Wortzahl für deutschen Text (str_word_count zählt Umlaute nicht).
	 *
	 * @param string $text Text.
	 */
	public static function words( string $text ): int {
		return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\-\']*/u', $text );
	}
}
