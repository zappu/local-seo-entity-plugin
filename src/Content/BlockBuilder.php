<?php
/**
 * Baut gültiges Block-Markup aus einer Zwischenstruktur.
 *
 * @package Lsec
 */

namespace Lsec\Content;

/**
 * Spezifikation 8.5: Die KI liefert eine Zwischenstruktur, das Plugin erzeugt daraus das Markup.
 * Das Markup entspricht der save()-Ausgabe der Core-Blöcke, damit der Editor keine Warnung
 * „unerwarteter Inhalt“ zeigt (M12). Unterschiede zwischen WordPress-Versionen:
 * - Liste: Klasse wp-block-list ab WordPress 6.6
 *
 * Knoten:
 *   {type: paragraph, text}
 *   {type: heading, level 2–4, text}
 *   {type: list, ordered?, items: [text]}
 *   {type: table, head: [text], rows: [[text]]}
 *   {type: quote, text, cite?}
 *   {type: separator}
 *   {type: details, summary, text}   (FAQ)
 *
 * Text darf <strong>, <em>, <a href> und <br> enthalten; alles andere wird entfernt.
 */
final class BlockBuilder {

	/**
	 * Erlaubtes Inline-HTML in Texten.
	 */
	private const INLINE = array(
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
		'a'      => array( 'href' => true ),
	);

	/**
	 * Konstruktor.
	 *
	 * @param string $wp_version WordPress-Version, bestimmt Details des Markups.
	 */
	public function __construct( private readonly string $wp_version ) {}

	/**
	 * Für die laufende Installation.
	 */
	public static function for_site(): self {
		return new self( (string) get_bloginfo( 'version' ) );
	}

	/**
	 * Baut alle Knoten.
	 *
	 * @param array<int, array<string, mixed>> $nodes Knoten.
	 * @return string Markup.
	 * @throws \InvalidArgumentException Bei unbekanntem Knotentyp.
	 */
	public function build( array $nodes ): string {
		$out = array();
		foreach ( $nodes as $node ) {
			$block = $this->node( $node );
			if ( '' !== $block ) {
				$out[] = $block;
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Ein Knoten.
	 *
	 * @param array<string, mixed> $node Knoten.
	 * @throws \InvalidArgumentException Bei unbekanntem Typ.
	 */
	private function node( array $node ): string {
		$type = (string) ( $node['type'] ?? '' );
		switch ( $type ) {
			case 'paragraph':
				return $this->paragraph( (string) ( $node['text'] ?? '' ) );
			case 'heading':
				return $this->heading( (string) ( $node['text'] ?? '' ), (int) ( $node['level'] ?? 2 ) );
			case 'list':
				return $this->list_block( array_map( 'strval', (array) ( $node['items'] ?? array() ) ), ! empty( $node['ordered'] ) );
			case 'table':
				return $this->table( array_map( 'strval', (array) ( $node['head'] ?? array() ) ), (array) ( $node['rows'] ?? array() ) );
			case 'quote':
				return $this->quote( (string) ( $node['text'] ?? '' ), (string) ( $node['cite'] ?? '' ) );
			case 'separator':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
			case 'details':
				return $this->details( (string) ( $node['summary'] ?? '' ), (string) ( $node['text'] ?? '' ) );
			default:
				throw new \InvalidArgumentException( 'Unbekannter Blocktyp: ' . $type ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Absatz.
	 *
	 * @param string $text Text.
	 */
	private function paragraph( string $text ): string {
		$text = self::inline( $text );
		return '' === $text ? '' : "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Überschrift; Ebene 2 ist der Standard und steht nicht in den Attributen.
	 *
	 * @param string $text  Text.
	 * @param int    $level Ebene.
	 */
	private function heading( string $text, int $level ): string {
		$text  = self::inline( $text, false );
		$level = min( 6, max( 2, $level ) );
		if ( '' === $text ) {
			return '';
		}
		$attrs = 2 === $level ? '' : ' ' . self::attrs( array( 'level' => $level ) );
		return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>\n<!-- /wp:heading -->";
	}

	/**
	 * Liste mit Listenpunkten als innere Blöcke.
	 *
	 * @param string[] $items   Punkte.
	 * @param bool     $ordered Nummeriert.
	 */
	private function list_block( array $items, bool $ordered ): string {
		$inner = '';
		foreach ( $items as $item ) {
			$item = self::inline( $item );
			if ( '' !== $item ) {
				$inner .= "<!-- wp:list-item -->\n<li>{$item}</li>\n<!-- /wp:list-item -->";
			}
		}
		if ( '' === $inner ) {
			return '';
		}
		$tag   = $ordered ? 'ol' : 'ul';
		$class = version_compare( $this->wp_version, '6.6', '>=' ) ? ' class="wp-block-list"' : '';
		$attrs = $ordered ? ' ' . self::attrs( array( 'ordered' => true ) ) : '';
		return "<!-- wp:list{$attrs} -->\n<{$tag}{$class}>{$inner}</{$tag}>\n<!-- /wp:list -->";
	}

	/**
	 * Tabelle. hasFixedLayout wird ausdrücklich gesetzt, weil sich der Standardwert geändert hat.
	 *
	 * @param string[]     $head Kopfzeile.
	 * @param array<mixed> $rows Zeilen.
	 */
	private function table( array $head, array $rows ): string {
		$thead = '';
		if ( array() !== $head ) {
			$cells = '';
			foreach ( $head as $cell ) {
				$cells .= '<th>' . self::inline( $cell, false ) . '</th>';
			}
			$thead = "<thead><tr>{$cells}</tr></thead>";
		}
		$tbody = '';
		foreach ( $rows as $row ) {
			$cells = '';
			foreach ( (array) $row as $cell ) {
				$cells .= '<td>' . self::inline( (string) $cell ) . '</td>';
			}
			if ( '' !== $cells ) {
				$tbody .= "<tr>{$cells}</tr>";
			}
		}
		if ( '' === $tbody ) {
			return '';
		}
		$attrs = self::attrs( array( 'hasFixedLayout' => false ) );
		return "<!-- wp:table {$attrs} -->\n<figure class=\"wp-block-table\"><table>{$thead}<tbody>{$tbody}</tbody></table></figure>\n<!-- /wp:table -->";
	}

	/**
	 * Zitat mit Absatz als innerem Block.
	 *
	 * @param string $text Text.
	 * @param string $cite Quelle.
	 */
	private function quote( string $text, string $cite ): string {
		$inner = $this->paragraph( $text );
		if ( '' === $inner ) {
			return '';
		}
		$cite = self::inline( $cite );
		$cite = '' === $cite ? '' : "<cite>{$cite}</cite>";
		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$inner}{$cite}</blockquote>\n<!-- /wp:quote -->";
	}

	/**
	 * Aufklappbarer Abschnitt (core/details, ab WordPress 6.3), z. B. für FAQ.
	 *
	 * @param string $summary Frage.
	 * @param string $text    Antwort.
	 */
	private function details( string $summary, string $text ): string {
		$summary = self::inline( $summary, false );
		$inner   = $this->paragraph( $text );
		if ( '' === $summary || '' === $inner ) {
			return '';
		}
		return "<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>{$summary}</summary>{$inner}</details>\n<!-- /wp:details -->";
	}

	/**
	 * Bereinigt Inline-Text.
	 *
	 * @param string $text  Text.
	 * @param bool   $links Links erlauben.
	 */
	public static function inline( string $text, bool $links = true ): string {
		$allowed = $links ? self::INLINE : array_diff_key( self::INLINE, array( 'a' => true ) );
		$text    = function_exists( 'wp_kses' ) ? wp_kses( $text, $allowed ) : strip_tags( $text, '<strong><em><br>' . ( $links ? '<a>' : '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		// Block-Kommentare im Text würden den Parser stören.
		$text = str_replace( array( '<!--', '-->' ), array( '&lt;!--', '--&gt;' ), $text );
		$text = (string) preg_replace( '#<br\s*/?>#i', '<br>', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Attribute als JSON wie in serialize_block_attributes().
	 *
	 * @param array<string, mixed> $attrs Attribute.
	 */
	private static function attrs( array $attrs ): string {
		$json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return str_replace( array( '--', '<', '>', '&', '\\"' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ), $json );
	}
}
