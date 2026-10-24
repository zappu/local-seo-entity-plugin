<?php
/**
 * Art einer Suchanfrage: Inhalt, Marke oder Navigation.
 *
 * @package Lsec
 */

namespace Lsec\Report;

use Lsec\Research\Keywords\KeywordFilter;

/**
 * Nachtrag 24.10.2026 (0.10.5): Im Bericht „Bestehende Seiten verbessern“ standen auf der Zahnarzt-Website 13 von
 * 19 Zeilen mit Suchen nach dem eigenen Namen („dr rust“, „kai rust“) und eine nach Öffnungszeiten. Mehr Inhalt
 * hilft dort nicht; solche Anfragen bekommen einen eigenen Abschnitt mit passendem Hinweis.
 *
 * - **Markensuche:** Die Anfrage enthält einen Markenbegriff. Markenbegriffe sind die Wörter aus dem Namen des
 *   Unternehmens und aus Personen des Betriebs, ohne Titel und Rechtsformen, ohne Wörter aus Leistungen und Orten
 *   und ohne lange zusammengesetzte Wörter (ab 12 Buchstaben, z. B. „Flächenreinigung“). Die Einstellung
 *   brand_terms ersetzt die automatische Liste.
 * - **Navigationssuche:** Öffnungszeiten, Telefon, Adresse, Anfahrt, Kontakt, Impressum, Bewertungen, Jobs.
 *
 * Die Klasse kennt WordPress nicht.
 */
final class QueryKind {

	public const CONTENT = 'content';

	public const BRAND = 'brand';

	public const NAVIGATIONAL = 'navigational';

	/**
	 * Titel, Rechtsformen und allgemeine Wörter in Firmennamen.
	 */
	private const STOP = array( 'dr', 'med', 'dent', 'msc', 'prof', 'dipl', 'ing', 'phd', 'mba', 'bsc', 'gmbh', 'mbh', 'ug', 'kg', 'ag', 'ohg', 'ek', 'gbr', 'inh', 'inhaber', 'co', 'und', 'the', 'of', 'fuer', 'for', 'von', 'van', 'der', 'die', 'das', 'de', 'la', 'praxis', 'kanzlei', 'salon', 'studio', 'service', 'team', 'buero', 'firma', 'betrieb', 'zentrum', 'center', 'haus', 'shop' );

	/**
	 * Wortanfänge von Navigationssuchen (gefaltet).
	 */
	private const NAVIGATION = array( 'oeffnungszeit', 'sprechzeit', 'telefon', 'nummer', 'adresse', 'anfahrt', 'kontakt', 'email', 'mail', 'impressum', 'bewertung', 'erfahrung', 'login', 'job', 'karriere', 'stellenangebot', 'wegbeschreibung' );

	/**
	 * Markenbegriffe aus Namen.
	 *
	 * @param string[] $names    Name des Unternehmens und Personen.
	 * @param string[] $excluded Leistungen und Orte, deren Wörter keine Markenbegriffe sind.
	 * @return string[] Gefaltete Begriffe.
	 */
	public static function brand_terms( array $names, array $excluded ): array {
		$skip = array();
		foreach ( $excluded as $name ) {
			foreach ( explode( ' ', KeywordFilter::fold( (string) $name ) ) as $word ) {
				$skip[ $word ] = true;
			}
		}
		$out = array();
		foreach ( $names as $name ) {
			foreach ( explode( ' ', KeywordFilter::fold( (string) $name ) ) as $word ) {
				if ( mb_strlen( $word ) < 3 || mb_strlen( $word ) >= 12 || ctype_digit( $word ) || in_array( $word, self::STOP, true ) || isset( $skip[ $word ] ) ) {
					continue;
				}
				$out[ $word ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Begriffe aus der Einstellung (durch Komma getrennt).
	 *
	 * @param string $setting Einstellung.
	 * @return string[]
	 */
	public static function parse_terms( string $setting ): array {
		$out = array();
		foreach ( explode( ',', $setting ) as $term ) {
			$term = KeywordFilter::fold( $term );
			if ( '' !== $term ) {
				$out[] = $term;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Art der Anfrage.
	 *
	 * @param string   $query Suchanfrage.
	 * @param string[] $brand Markenbegriffe (gefaltet).
	 */
	public static function classify( string $query, array $brand ): string {
		$text = ' ' . KeywordFilter::fold( $query ) . ' ';
		foreach ( self::NAVIGATION as $start ) {
			if ( str_contains( $text, ' ' . $start ) ) {
				return self::NAVIGATIONAL;
			}
		}
		foreach ( $brand as $term ) {
			if ( '' !== $term && str_contains( $text, ' ' . $term . ' ' ) ) {
				return self::BRAND;
			}
		}
		return self::CONTENT;
	}
}
