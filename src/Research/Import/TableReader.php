<?php
/**
 * Liest Tabellen aus CSV- und Excel-Dateien.
 *
 * @package Lsec
 */

namespace Lsec\Research\Import;

/**
 * Liefert Kopfzeile und Zeilen als Arrays. Erkennt:
 * - Kodierung: UTF-8 mit/ohne BOM, sonst Windows-1252
 * - Trennzeichen: Semikolon, Komma oder Tabulator
 * - Excel (.xlsx): erstes Tabellenblatt
 */
final class TableReader {

	/**
	 * Liest eine Datei.
	 *
	 * @param string $path     Pfad.
	 * @param string $filename Originaler Dateiname (für die Endung bei Uploads).
	 * @return array{header: list<string>, rows: list<list<string>>, delimiter: string, encoding: string}
	 * @throws \RuntimeException Wenn die Datei nicht lesbar ist.
	 */
	public static function read( string $path, string $filename = '' ): array {
		$name = '' !== $filename ? $filename : $path;
		if ( str_ends_with( strtolower( $name ), '.xlsx' ) ) {
			return self::read_xlsx( $path );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			throw new \RuntimeException( 'Datei nicht lesbar: ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return self::parse_csv( $raw );
	}

	/**
	 * Zerlegt CSV-Text.
	 *
	 * @param string $raw Inhalt.
	 * @return array{header: list<string>, rows: list<list<string>>, delimiter: string, encoding: string}
	 */
	public static function parse_csv( string $raw ): array {
		$encoding = 'UTF-8';
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw      = substr( $raw, 3 );
			$encoding = 'UTF-8 (BOM)';
		} elseif ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw      = (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
			$encoding = 'Windows-1252';
		}

		$first_line = strtok( $raw, "\r\n" );
		$first_line = false === $first_line ? '' : $first_line;
		$delimiter  = ',';
		$best       = -1;
		foreach ( array( ';', ',', "\t" ) as $candidate ) {
			$count = substr_count( $first_line, $candidate );
			if ( $count > $best ) {
				$best      = $count;
				$delimiter = $candidate;
			}
		}

		$handle = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $row ) {
				continue;
			}
			$rows[] = array_map( static fn( $cell ): string => trim( (string) $cell ), $row );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$header = array_shift( $rows ) ?? array();
		return array(
			'header'    => $header,
			'rows'      => array_values( array_filter( $rows, static fn( array $r ): bool => '' !== implode( '', $r ) ) ),
			'delimiter' => "\t" === $delimiter ? 'Tab' : $delimiter,
			'encoding'  => $encoding,
		);
	}

	/**
	 * Liest das erste Blatt einer Excel-Datei ohne externe Bibliothek.
	 *
	 * @param string $path Pfad.
	 * @return array{header: list<string>, rows: list<list<string>>, delimiter: string, encoding: string}
	 * @throws \RuntimeException Wenn die Datei kein gültiges .xlsx ist.
	 */
	public static function read_xlsx( string $path ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'Für Excel-Dateien fehlt die PHP-Erweiterung zip. Bitte als CSV exportieren.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \RuntimeException( 'Excel-Datei nicht lesbar.' );
		}

		$shared = array();
		$xml    = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $xml ) {
			$doc = simplexml_load_string( $xml );
			if ( false !== $doc ) {
				foreach ( $doc->si as $si ) {
					$text = isset( $si->t ) ? (string) $si->t : '';
					foreach ( $si->r as $run ) {
						$text .= (string) $run->t;
					}
					$shared[] = $text;
				}
			}
		}

		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		if ( false === $sheet ) {
			throw new \RuntimeException( 'Excel-Datei ohne erstes Tabellenblatt.' );
		}
		$doc = simplexml_load_string( $sheet );
		if ( false === $doc ) {
			throw new \RuntimeException( 'Tabellenblatt nicht lesbar.' );
		}

		$rows = array();
		foreach ( $doc->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $cell ) {
				$ref   = (string) $cell['r'];
				$index = self::column_index( (string) preg_replace( '/\d+/', '', $ref ) );
				$type  = (string) $cell['t'];
				if ( 's' === $type ) {
					$value = $shared[ (int) $cell->v ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$value = (string) $cell->is->t;
				} else {
					$value = (string) $cell->v;
				}
				$cells[ $index ] = trim( $value );
			}
			if ( array() === $cells ) {
				continue;
			}
			$max  = max( array_keys( $cells ) );
			$line = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$line[] = $cells[ $i ] ?? '';
			}
			$rows[] = $line;
		}

		$header = array_shift( $rows ) ?? array();
		return array(
			'header'    => $header,
			'rows'      => $rows,
			'delimiter' => 'xlsx',
			'encoding'  => 'UTF-8',
		);
	}

	/**
	 * Spaltenbuchstaben zu Index (A = 0).
	 *
	 * @param string $letters Buchstaben.
	 */
	private static function column_index( string $letters ): int {
		$index = 0;
		foreach ( str_split( strtoupper( $letters ) ) as $char ) {
			$index = $index * 26 + ( ord( $char ) - 64 );
		}
		return max( 0, $index - 1 );
	}
}
