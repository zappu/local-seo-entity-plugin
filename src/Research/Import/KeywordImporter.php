<?php
/**
 * Wandelt eine Tabelle in Keyword-Datensätze.
 *
 * @package Lsec
 */

namespace Lsec\Research\Import;

use Lsec\Research\Keywords\KeywordNormalizer;

/**
 * Wendet Vorlage und Importregeln an (Spezifikation 6.2 und 6.6). Schreibt nichts in die Datenbank.
 */
final class KeywordImporter {

	/**
	 * Erlaubte Werte für die Suchintention und deutsche Entsprechungen.
	 */
	private const INTENTS = array(
		'informational' => 'informational',
		'informativ'    => 'informational',
		'information'   => 'informational',
		'commercial'    => 'commercial',
		'kommerziell'   => 'commercial',
		'transactional' => 'transactional',
		'transaktional' => 'transactional',
		'navigational'  => 'navigational',
		'navigativ'     => 'navigational',
		'navigation'    => 'navigational',
		'local'         => 'local',
		'lokal'         => 'local',
	);

	/**
	 * Liest eine Datei und wendet die Vorlage an.
	 *
	 * @param string $path     Pfad.
	 * @param string $filename Originaler Dateiname.
	 * @param string $profile  Vorlage oder leer.
	 * @param string $map      Zusätzliche Spaltenzuordnung.
	 * @param string $source   Eigene Quellkennung oder leer.
	 * @throws \RuntimeException Wenn die Datei nicht lesbar ist.
	 * @throws \InvalidArgumentException Bei falscher Vorlage oder Zuordnung.
	 */
	public static function from_file( string $path, string $filename = '', string $profile = '', string $map = '', string $source = '' ): ImportResult {
		$name   = '' !== $filename ? $filename : basename( $path );
		$format = str_ends_with( strtolower( $name ), '.xlsx' ) ? 'xlsx' : 'csv';
		$table  = TableReader::read( $path, $name );
		$chosen = ImportProfile::detect( $table['header'], $format, $profile )->with_map( $map, $table['header'] );
		if ( '' !== $source ) {
			if ( ! preg_match( '/^[a-z0-9_]+:[a-z0-9_]+$/', $source ) ) {
				throw new \InvalidArgumentException( 'Quelle im Format art:name angeben, z. B. csv:sistrix.' );
			}
			$chosen = $chosen->with_source( $source );
		}
		$result = self::apply( $table['header'], $table['rows'], $chosen );
		$result->filename  = $name;
		$result->encoding  = $table['encoding'];
		$result->delimiter = $table['delimiter'];
		return $result;
	}

	/**
	 * Wendet Vorlage und Regeln auf Zeilen an.
	 *
	 * @param string[]        $header  Kopfzeile.
	 * @param array<string[]> $rows    Zeilen.
	 * @param ImportProfile   $profile Vorlage.
	 * @throws \InvalidArgumentException Wenn keine Keyword-Spalte zugeordnet ist.
	 */
	public static function apply( array $header, array $rows, ImportProfile $profile ): ImportResult {
		if ( ! isset( $profile->mapping['keyword'] ) ) {
			throw new \InvalidArgumentException( 'Keine Keyword-Spalte erkannt. Spalten: ' . implode( ', ', $header ) . '. Zuordnung mit --map=keyword=<Spalte> angeben.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$index = array();
		foreach ( $profile->mapping as $field => $column ) {
			$position = array_search( $column, $header, true );
			if ( false !== $position ) {
				$index[ $field ] = (int) $position;
			}
		}

		$result             = new ImportResult( $profile );
		$result->rows_total = count( $rows );
		$seen               = array();
		$duplicates         = 0;

		foreach ( $rows as $row ) {
			$keyword = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $row[ $index['keyword'] ] ?? '' ) ) );
			if ( '' === $keyword ) {
				++$result->rows_skipped;
				continue;
			}
			$normalized = KeywordNormalizer::normalize( $keyword );
			if ( isset( $seen[ $normalized ] ) ) {
				++$result->rows_skipped;
				++$duplicates;
				continue;
			}
			$seen[ $normalized ] = true;

			$cell  = static fn( string $field ): ?string => isset( $index[ $field ] ) ? ( $row[ $index[ $field ] ] ?? null ) : null;
			$trend = self::trend( $cell( 'trend' ) );

			$result->keywords[] = array(
				'keyword'    => $keyword,
				'normalized' => $normalized,
				'intent'     => self::intent( $cell( 'intent' ) ),
				'volume'     => NumberParser::int( $cell( 'volume' ) ),
				'difficulty' => NumberParser::float( $cell( 'difficulty' ) ),
				'cpc'        => NumberParser::float( $cell( 'cpc' ) ),
				'trend'      => $trend,
			);
		}

		if ( $duplicates > 0 ) {
			$result->notes[] = sprintf( '%d doppelte Keywords übersprungen (gleiche Schreibweise nach Normalisierung).', $duplicates );
		}

		if ( isset( $index['volume'] ) && $profile->all_zero_is_unknown && array() !== $result->keywords ) {
			$volumes = array_column( $result->keywords, 'volume' );
			$known   = array_filter( $volumes, static fn( $v ): bool => null !== $v );
			if ( array() !== $known && array() === array_filter( $known, static fn( $v ): bool => 0 !== $v ) ) {
				foreach ( $result->keywords as &$entry ) {
					$entry['volume'] = null;
				}
				unset( $entry );
				$result->volumes_unknown = true;
				$result->notes[]         = sprintf( 'Alle Suchvolumina der Datei sind 0. %s liefert 0, wenn keine Daten vorliegen; gespeichert als „unbekannt“.', $profile->label );
			}
		}

		if ( ! isset( $index['volume'] ) ) {
			$result->notes[] = 'Keine Spalte für das Suchvolumen erkannt.';
		}
		if ( isset( $index['trend'] ) ) {
			$result->notes[] = 'Verlaufswerte ohne Monatsangabe als Trend mit labeled=false gespeichert (nur Spannweite, keine Saisonbewertung).';
		}
		return $result;
	}

	/**
	 * Suchintention vereinheitlichen.
	 *
	 * @param string|null $value Wert.
	 */
	public static function intent( ?string $value ): ?string {
		$key = mb_strtolower( trim( (string) $value ), 'UTF-8' );
		return self::INTENTS[ $key ] ?? null;
	}

	/**
	 * Verlaufswerte „140,90,90,…“ als Trend ohne Monatszuordnung.
	 *
	 * @param string|null $value Feldinhalt.
	 * @return array{labeled: bool, values: int[], min: int, max: int}|null
	 */
	public static function trend( ?string $value ): ?array {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$values = array();
		foreach ( preg_split( '/[,;|]/', $value ) ?: array() as $part ) {
			$number = NumberParser::int( $part );
			if ( null !== $number ) {
				$values[] = $number;
			}
		}
		if ( array() === $values ) {
			return null;
		}
		return array(
			'labeled' => false,
			'values'  => $values,
			'min'     => min( $values ),
			'max'     => max( $values ),
		);
	}
}
