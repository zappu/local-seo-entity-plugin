<?php
/**
 * Liest Zahlen aus Exporten.
 *
 * @package Lsec
 */

namespace Lsec\Research\Import;

/**
 * Versteht deutsche und englische Schreibweisen: „1.200“, „1,200“, „4,36“, „4.36“, „1.234,5“.
 */
final class NumberParser {

	/**
	 * Ganze Zahl oder null bei leerem/ungültigem Wert.
	 *
	 * @param string|null $value Wert.
	 */
	public static function int( ?string $value ): ?int {
		$value = self::clean( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^\d{1,3}([.,\s]\d{3})+$/', $value ) ) {
			return (int) preg_replace( '/[.,\s]/', '', $value );
		}
		$float = self::float( $value );
		return null === $float ? null : (int) round( $float );
	}

	/**
	 * Dezimalzahl oder null.
	 *
	 * @param string|null $value Wert.
	 */
	public static function float( ?string $value ): ?float {
		$value = self::clean( $value );
		if ( '' === $value ) {
			return null;
		}
		$has_dot   = str_contains( $value, '.' );
		$has_comma = str_contains( $value, ',' );
		if ( $has_dot && $has_comma ) {
			// Das zuletzt stehende Zeichen ist das Dezimaltrennzeichen.
			if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
				$value = str_replace( array( '.', ',' ), array( '', '.' ), $value );
			} else {
				$value = str_replace( ',', '', $value );
			}
		} elseif ( $has_comma ) {
			$value = str_replace( ',', '.', $value );
		}
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Entfernt Währungszeichen, Prozentzeichen und Leerraum.
	 *
	 * @param string|null $value Wert.
	 */
	private static function clean( ?string $value ): string {
		$value = trim( (string) $value );
		$value = str_replace( array( '€', '$', '%', "\xc2\xa0", 'EUR', 'USD' ), '', $value );
		$value = trim( $value );
		return in_array( $value, array( '-', '–', 'n/a', 'N/A' ), true ) ? '' : $value;
	}
}
