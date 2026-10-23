<?php
/**
 * Vergleich von Ortsnamen.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Research\Keywords\KeywordNormalizer;

/**
 * Nachtrag 23.10. (0.7.11): Für Uetze gab es drei Entitäten – das Servicegebiet „Uetze“ aus dem Profil und
 * die Kandidaten „Uetze“ und „Gemeinde Uetze“ aus der Recherche. Die geprüften Tatsachen hingen an den
 * Kandidaten, gesucht wurde nur das Servicegebiet; Ablauf 130 hatte deshalb keine Ortsfakten.
 * Gleich sind Namen, die sich nur in Schreibweise (Umlaute, Groß- und Kleinschreibung) oder einer
 * vorangestellten Verwaltungsbezeichnung („Gemeinde“, „Stadt“) unterscheiden. „Region Hannover“ bleibt
 * verschieden von „Hannover“. Die Klasse kennt WordPress nicht.
 */
final class PlaceName {

	/**
	 * Vorangestellte Bezeichnungen ohne Unterscheidungskraft.
	 */
	private const PREFIXES = array( 'gemeinde', 'stadt', 'samtgemeinde', 'einheitsgemeinde', 'flecken', 'hansestadt', 'kreisstadt', 'ortsteil', 'ortschaft', 'dorf', 'raum', 'grossraum' );

	/**
	 * Angehängte Wörter ohne Unterscheidungskraft („Uetze und Umgebung“).
	 */
	private const SUFFIXES = array( 'umgebung', 'umkreis', 'umland', 'und' );

	/**
	 * Vergleichsschlüssel eines Ortsnamens.
	 *
	 * @param string $name Ortsname.
	 */
	public static function key( string $name ): string {
		return implode( ' ', self::words( KeywordNormalizer::fold( $name ) ) );
	}

	/**
	 * Wort für die Vorauswahl in der Datenbank: letztes Wort ohne Umlaute zu falten („Lüneburg“ = „lüneburg“).
	 *
	 * @param string $name Ortsname.
	 */
	public static function search_word( string $name ): string {
		$words = self::words( KeywordNormalizer::normalize( $name ) );
		return (string) end( $words );
	}

	/**
	 * Wörter ohne vorangestellte und angehängte Bezeichnungen.
	 *
	 * @param string $name Kleingeschriebener Name.
	 * @return string[]
	 */
	private static function words( string $name ): array {
		$words = array_values( array_filter( explode( ' ', str_replace( array( ',', '(', ')' ), ' ', $name ) ), static fn( string $w ): bool => '' !== $w ) );
		$first = 0;
		$end   = count( $words ) - 1;
		while ( $first < $end && in_array( KeywordNormalizer::fold( $words[ $first ] ), self::PREFIXES, true ) ) {
			++$first;
		}
		while ( $end > $first && in_array( $words[ $end ], self::SUFFIXES, true ) ) {
			--$end;
		}
		return array_slice( $words, $first, $end - $first + 1 );
	}

	/**
	 * Derselbe Ort?
	 *
	 * @param string $a Ortsname.
	 * @param string $b Ortsname.
	 */
	public static function same( string $a, string $b ): bool {
		$key = self::key( $a );
		return '' !== $key && self::key( $b ) === $key;
	}
}
