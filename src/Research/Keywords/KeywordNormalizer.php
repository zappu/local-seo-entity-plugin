<?php
/**
 * Normalisiert Keywords.
 *
 * @package Lsec
 */

namespace Lsec\Research\Keywords;

/**
 * Einheitliche Schreibweise für Dublettenprüfung und Abgleich.
 */
final class KeywordNormalizer {

	/**
	 * Kleinschreibung, Leerraum zusammengefasst, Umlaute bleiben erhalten.
	 *
	 * @param string $keyword Keyword.
	 */
	public static function normalize( string $keyword ): string {
		$keyword = trim( (string) preg_replace( '/\s+/u', ' ', $keyword ) );
		return mb_strtolower( $keyword, 'UTF-8' );
	}

	/**
	 * Schreibweise ohne Umlaute und ß, für den Vergleich von „flaechen“ und „flächen“.
	 *
	 * @param string $keyword Keyword.
	 */
	public static function fold( string $keyword ): string {
		return strtr(
			self::normalize( $keyword ),
			array(
				'ä' => 'ae',
				'ö' => 'oe',
				'ü' => 'ue',
				'ß' => 'ss',
			)
		);
	}
}
