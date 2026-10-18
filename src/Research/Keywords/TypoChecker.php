<?php
/**
 * Prüfung auf Tippfehler vor bezahlten Abfragen.
 *
 * @package Lsec
 */

namespace Lsec\Research\Keywords;

/**
 * Spezifikation 6.7: Ein neues Keyword wird mit bekannten Wörtern verglichen (Keywords, Leistungen, Entitäten).
 * Gemeldet wird, wenn ein Wort einer deutlich häufigeren bekannten Schreibweise sehr ähnlich ist:
 * - Umlaut-Variante: „flaechenreinigung“ ↔ „flächenreinigung“
 * - Editierabstand: „flächenreingung“ ↔ „flächenreinigung“
 *
 * Der Abstand wird auf der umlautfreien Form berechnet (levenshtein() arbeitet bytebasiert).
 */
final class TypoChecker {

	/**
	 * Wortschatz: umlautfreie Form => [Schreibweise => Häufigkeit].
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $vocabulary = array();

	/**
	 * Konstruktor.
	 *
	 * @param string[] $known       Bekannte Begriffe (Keywords, Leistungen, Entitäten).
	 * @param int      $min_length  Kürzere Wörter werden nicht geprüft.
	 * @param int      $dominance   Wie viel häufiger die bekannte Schreibweise sein muss.
	 */
	public function __construct( array $known, private readonly int $min_length = 5, private readonly int $dominance = 3 ) {
		foreach ( $known as $term ) {
			foreach ( self::tokens( (string) $term ) as $token ) {
				$folded                                = self::fold_token( $token );
				$this->vocabulary[ $folded ][ $token ] = ( $this->vocabulary[ $folded ][ $token ] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * Prüft ein Keyword.
	 *
	 * @param string $keyword Keyword.
	 * @return array<int, array{word: string, suggestion: string, reason: string, distance: int, known_count: int}> Leere Liste, wenn unauffällig.
	 */
	public function check( string $keyword ): array {
		$findings = array();
		foreach ( self::tokens( $keyword ) as $token ) {
			if ( mb_strlen( $token, 'UTF-8' ) < $this->min_length || preg_match( '/\d/', $token ) ) {
				continue;
			}
			$finding = $this->check_token( $token );
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}
		return $findings;
	}

	/**
	 * Schlägt die korrigierte Schreibweise des ganzen Keywords vor.
	 *
	 * @param string $keyword Keyword.
	 */
	public function suggest( string $keyword ): ?string {
		$findings = $this->check( $keyword );
		if ( array() === $findings ) {
			return null;
		}
		$out = KeywordNormalizer::normalize( $keyword );
		foreach ( $findings as $finding ) {
			$out = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $finding['word'], '/' ) . '(?![\p{L}\p{N}])/u', $finding['suggestion'], $out );
		}
		return $out;
	}

	/**
	 * Prüft ein Wort.
	 *
	 * @param string $token Wort.
	 * @return array{word: string, suggestion: string, reason: string, distance: int, known_count: int}|null
	 */
	private function check_token( string $token ): ?array {
		$folded = self::fold_token( $token );
		$own    = $this->vocabulary[ $folded ][ $token ] ?? 0;
		$limit  = self::max_distance( $folded );
		$best   = null;

		foreach ( $this->vocabulary as $other_folded => $spellings ) {
			if ( abs( strlen( $other_folded ) - strlen( $folded ) ) > $limit ) {
				continue;
			}
			$distance = $other_folded === $folded ? 0 : levenshtein( $folded, $other_folded );
			if ( $distance > $limit ) {
				continue;
			}
			foreach ( $spellings as $spelling => $count ) {
				if ( $spelling === $token || $count < $this->dominance * max( 1, $own ) ) {
					continue;
				}
				if ( null === $best || $distance < $best['distance'] || ( $distance === $best['distance'] && $count > $best['known_count'] ) ) {
					$best = array(
						'word'        => $token,
						'suggestion'  => (string) $spelling,
						'reason'      => 0 === $distance ? 'umlaut' : 'edit_distance',
						'distance'    => $distance,
						'known_count' => $count,
					);
				}
			}
		}
		return $best;
	}

	/**
	 * Zulässiger Abstand: kurze Wörter nur Umlaut-Varianten, mittlere 1, lange 2.
	 *
	 * @param string $folded Umlautfreie Form.
	 */
	private static function max_distance( string $folded ): int {
		$length = strlen( $folded );
		if ( $length <= 6 ) {
			return 0;
		}
		return $length <= 10 ? 1 : 2;
	}

	/**
	 * Wörter eines Begriffs, normalisiert.
	 *
	 * @param string $term Begriff.
	 * @return string[]
	 */
	public static function tokens( string $term ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', KeywordNormalizer::normalize( $term ), -1, PREG_SPLIT_NO_EMPTY );
		return false === $parts ? array() : $parts;
	}

	/**
	 * Umlautfreie Form eines Wortes.
	 *
	 * @param string $token Wort.
	 */
	private static function fold_token( string $token ): string {
		return KeywordNormalizer::fold( $token );
	}
}
