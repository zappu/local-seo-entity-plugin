<?php
/**
 * Handbuch im Backend.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

/**
 * Zeigt docs/handbuch.md als Seite „Handbuch“ im Backend. Das Handbuch bleibt eine Datei
 * (Quelle für Repository und Plugin); dieser Umwandler kennt nur das Markdown, das es nutzt:
 * Überschriften, Absätze, Listen (auch verschachtelt), Tabellen, Codeblöcke, Trennlinien,
 * `Code`, **fett** und Links.
 *
 * Nachtrag 24.10.2026 (0.10.7).
 */
final class Manual {

	public const FILE = 'docs/handbuch.md';

	/**
	 * Abschnitt des Handbuchs je Seite des Plugins (Anker), für den Link neben dem Titel.
	 */
	public const SECTIONS = array(
		'setup'    => '4-einrichtung-mit-dem-assistenten',
		'settings' => '8-einstellungen',
		'runs'     => '5-der-laufende-betrieb',
	);

	/**
	 * Pfad der Datei oder null, wenn sie fehlt.
	 */
	public static function path(): ?string {
		$file = LSEC_DIR . self::FILE;
		return is_readable( $file ) ? $file : null;
	}

	/**
	 * Handbuch als HTML oder null, wenn die Datei fehlt.
	 */
	public static function html(): ?string {
		$file = self::path();
		if ( null === $file ) {
			return null;
		}
		$text = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Datei des Plugins.
		return false === $text ? null : self::to_html( $text );
	}

	/**
	 * Wandelt das Markdown des Handbuchs in HTML. Die erste Überschrift (Titel) entfällt,
	 * weil die Seite ihren eigenen Titel hat.
	 *
	 * @param string $markdown Markdown.
	 */
	public static function to_html( string $markdown ): string {
		$lines = preg_split( '/\R/u', $markdown );
		$lines = array_map( 'rtrim', false === $lines ? array() : $lines );
		$count = count( $lines );
		$out   = array();
		$title = false;
		$i     = 0;
		while ( $i < $count ) {
			$line = $lines[ $i ];
			if ( '' === trim( $line ) ) {
				++$i;
			} elseif ( str_starts_with( ltrim( $line ), '```' ) ) {
				++$i;
				$code  = self::take( $lines, $i, static fn( string $l ): bool => ! str_starts_with( ltrim( $l ), '```' ) );
				$out[] = '<pre><code>' . self::esc( implode( "\n", $code ) ) . '</code></pre>';
				++$i;
			} elseif ( preg_match( '/^(#{1,6})\s+(.+)$/u', $line, $m ) ) {
				++$i;
				$level = strlen( $m[1] );
				if ( 1 === $level && ! $title ) {
					$title = true;
					continue;
				}
				$out[] = sprintf( '<h%1$d id="%2$s">%3$s</h%1$d>', $level, self::esc( self::anchor( $m[2] ) ), self::inline( $m[2] ) );
			} elseif ( preg_match( '/^-{3,}$/', trim( $line ) ) ) {
				++$i;
				$out[] = '<hr>';
			} elseif ( self::table_start( $lines, $i ) ) {
				$head  = $line;
				$i    += 2;
				$rows  = self::take( $lines, $i, static fn( string $l ): bool => str_starts_with( trim( $l ), '|' ) );
				$out[] = self::table( array_merge( array( $head ), $rows ) );
			} elseif ( null !== self::list_item( $line ) ) {
				$block = self::take( $lines, $i, static fn( string $l ): bool => '' !== trim( $l ) && ( null !== self::list_item( $l ) || 1 === preg_match( '/^\s+\S/', $l ) ) );
				$out[] = self::list_html( $block );
			} else {
				$para = self::take( $lines, $i, static fn( string $l ): bool => '' !== trim( $l ) && ! self::starts_block( $l ) );
				if ( array() === $para ) {
					// Zeile sieht wie ein Block aus, ist aber keiner (z. B. „|“ ohne Trennzeile).
					$para = array( $line );
					++$i;
				}
				$out[] = '<p>' . self::inline( implode( ' ', array_map( 'trim', $para ) ) ) . '</p>';
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Nimmt Zeilen ab $i, solange die Bedingung gilt, und rückt $i weiter.
	 *
	 * @param string[]               $lines Zeilen.
	 * @param int                    $i     Position (wird verändert).
	 * @param callable(string): bool $keep  Bedingung.
	 * @return string[]
	 */
	private static function take( array $lines, int &$i, callable $keep ): array {
		$taken = array();
		$count = count( $lines );
		while ( $i < $count && $keep( $lines[ $i ] ) ) {
			$taken[] = $lines[ $i ];
			++$i;
		}
		return $taken;
	}

	/**
	 * Ob an $i eine Tabelle beginnt (Kopfzeile und Trennzeile).
	 *
	 * @param string[] $lines Zeilen.
	 * @param int      $i     Position.
	 */
	private static function table_start( array $lines, int $i ): bool {
		return str_starts_with( $lines[ $i ], '|' ) && isset( $lines[ $i + 1 ] ) && 1 === preg_match( '/^\|[\s:|-]+\|$/', trim( $lines[ $i + 1 ] ) );
	}

	/**
	 * Ob die Zeile einen neuen Block beginnt (beendet einen Absatz).
	 *
	 * @param string $line Zeile.
	 */
	private static function starts_block( string $line ): bool {
		return str_starts_with( ltrim( $line ), '```' ) || str_starts_with( $line, '#' ) || str_starts_with( $line, '|' ) || 1 === preg_match( '/^-{3,}$/', trim( $line ) ) || null !== self::list_item( $line );
	}

	/**
	 * Anker einer Überschrift wie bei GitHub: klein, ohne Satzzeichen, Leerzeichen als Bindestrich.
	 *
	 * @param string $heading Überschrift (Markdown).
	 */
	public static function anchor( string $heading ): string {
		$text = mb_strtolower( str_replace( array( '`', '*' ), '', $heading ), 'UTF-8' );
		$text = (string) preg_replace( '/[^\p{L}\p{N}\s-]/u', '', $text );
		return (string) preg_replace( '/\s/u', '-', trim( $text ) );
	}

	/**
	 * Listenpunkt: Einrückung, Art (ul/ol), Nummer und Text, sonst null.
	 *
	 * @param string $line Zeile.
	 * @return array{indent: int, tag: string, start: int, text: string}|null
	 */
	private static function list_item( string $line ): ?array {
		if ( ! preg_match( '/^(\s*)([-*]|\d+\.)\s+(.*)$/u', $line, $m ) ) {
			return null;
		}
		$ordered = ctype_digit( rtrim( $m[2], '.' ) );
		return array(
			'indent' => strlen( $m[1] ),
			'tag'    => $ordered ? 'ol' : 'ul',
			'start'  => $ordered ? (int) $m[2] : 1,
			'text'   => $m[3],
		);
	}

	/**
	 * Liste mit Unterlisten nach Einrückung.
	 *
	 * @param string[] $lines Zeilen der Liste.
	 */
	private static function list_html( array $lines ): string {
		$html  = '';
		$stack = array();
		foreach ( $lines as $line ) {
			$item = self::list_item( $line );
			if ( null === $item ) {
				$html .= ' ' . self::inline( trim( $line ) );
				continue;
			}
			while ( array() !== $stack && end( $stack )['indent'] > $item['indent'] ) {
				$html .= '</li></' . array_pop( $stack )['tag'] . '>';
			}
			$top = end( $stack );
			if ( false !== $top && $top['indent'] === $item['indent'] && $top['tag'] !== $item['tag'] ) {
				$html .= '</li></' . array_pop( $stack )['tag'] . '>';
				$top   = end( $stack );
			}
			if ( false !== $top && $top['indent'] === $item['indent'] ) {
				$html .= '</li><li>';
			} else {
				$html   .= '<' . $item['tag'] . ( 'ol' === $item['tag'] && 1 !== $item['start'] ? ' start="' . $item['start'] . '"' : '' ) . '><li>';
				$stack[] = array(
					'indent' => $item['indent'],
					'tag'    => $item['tag'],
				);
			}
			$html .= self::inline( $item['text'] );
		}
		while ( array() !== $stack ) {
			$html .= '</li></' . array_pop( $stack )['tag'] . '>';
		}
		return $html;
	}

	/**
	 * Tabelle; die erste Zeile ist der Kopf.
	 *
	 * @param string[] $rows Zeilen ohne Trennzeile.
	 */
	private static function table( array $rows ): string {
		$html = '<table class="widefat striped lsec-manual__table">';
		foreach ( $rows as $index => $row ) {
			$cells = array_map( 'trim', explode( '|', trim( trim( $row ), '|' ) ) );
			$tag   = 0 === $index ? 'th' : 'td';
			$html .= ( 0 === $index ? '<thead>' : ( 1 === $index ? '<tbody>' : '' ) ) . '<tr>';
			foreach ( $cells as $cell ) {
				$html .= '<' . $tag . '>' . self::inline( $cell ) . '</' . $tag . '>';
			}
			$html .= '</tr>' . ( 0 === $index ? '</thead>' : '' );
		}
		return $html . ( count( $rows ) > 1 ? '</tbody>' : '' ) . '</table>';
	}

	/**
	 * Zeile mit `Code`, **fett** und Links. Code bleibt unverändert; Links auf Dateien des
	 * Repositorys werden zu Text, weil sie im Plugin nicht liegen.
	 *
	 * @param string $text Markdown einer Zeile.
	 */
	private static function inline( string $text ): string {
		$codes = array();
		$text  = (string) preg_replace_callback(
			'/`([^`]+)`/u',
			static function ( array $m ) use ( &$codes ): string {
				$codes[] = '<code>' . self::esc( $m[1] ) . '</code>';
				return "\x01" . ( count( $codes ) - 1 ) . "\x01";
			},
			$text
		);
		$text  = self::esc( $text );
		$text  = (string) preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text );
		$text  = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/u',
			static function ( array $m ): string {
				$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				if ( str_starts_with( $url, '#' ) ) {
					return '<a href="#' . self::esc( self::anchor( substr( $url, 1 ) ) ) . '">' . $m[1] . '</a>';
				}
				if ( preg_match( '#^https?://#', $url ) ) {
					return '<a href="' . self::esc( $url ) . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
				}
				return $m[1];
			},
			$text
		);
		return (string) preg_replace_callback( "/\x01(\d+)\x01/", static fn( array $m ): string => $codes[ (int) $m[1] ] ?? '', $text );
	}

	/**
	 * HTML-Zeichen schützen (ohne WordPress, für Unit-Tests).
	 *
	 * @param string $text Text.
	 */
	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
