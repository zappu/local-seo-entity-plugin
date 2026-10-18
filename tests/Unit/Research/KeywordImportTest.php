<?php
/**
 * Tests für den Keyword-Import (M10).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Research;

use Lsec\Research\Import\ImportProfile;
use Lsec\Research\Import\KeywordImporter;
use Lsec\Research\Import\NumberParser;
use Lsec\Research\Import\TableReader;
use Lsec\Research\Keywords\KeywordNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Importregeln mit den echten Testdateien vom 17.10.2026.
 */
final class KeywordImportTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/import/';

	public function test_numbers_in_german_and_english_notation(): void {
		$this->assertSame( 1200, NumberParser::int( '1.200' ) );
		$this->assertSame( 1200, NumberParser::int( '1,200' ) );
		$this->assertSame( 12000, NumberParser::int( '12.000' ) );
		$this->assertSame( 90, NumberParser::int( '90' ) );
		$this->assertNull( NumberParser::int( '' ) );
		$this->assertNull( NumberParser::int( '-' ) );
		$this->assertSame( 2.35, NumberParser::float( '2,35' ) );
		$this->assertSame( 4.36, NumberParser::float( '4.36' ) );
		$this->assertSame( 1234.5, NumberParser::float( '1.234,5' ) );
		$this->assertSame( 1234.5, NumberParser::float( '1,234.5' ) );
		$this->assertSame( 2.35, NumberParser::float( '2,35 €' ) );
	}

	public function test_normalizer(): void {
		$this->assertSame( 'flächenreinigung hannover', KeywordNormalizer::normalize( '  Flächenreinigung   Hannover ' ) );
		$this->assertSame( KeywordNormalizer::fold( 'Flächenreinigung' ), KeywordNormalizer::fold( 'flaechenreinigung' ) );
	}

	public function test_keyword_revealer_with_trend_without_months(): void {
		$result = KeywordImporter::from_file( self::FIXTURES . 'keyword-revealer-flaechenreinigung.csv' );

		$this->assertSame( 'keyword_revealer', $result->profile->id );
		$this->assertSame( 'csv:keyword_revealer', $result->profile->source );
		$this->assertSame( 'UTF-8 (BOM)', $result->encoding );
		$this->assertSame( ',', $result->delimiter );
		$this->assertCount( 3, $result->keywords );

		$first = $result->keywords[0];
		$this->assertSame( 'flächenreinigung', $first['keyword'] );
		$this->assertSame( 110, $first['volume'] );
		$this->assertSame( 35.0, $first['difficulty'] );
		$this->assertSame( 4.36, $first['cpc'] );
		$this->assertSame( 'navigational', $first['intent'] );
		$this->assertNotNull( $first['trend'] );
		$this->assertFalse( $first['trend']['labeled'] );
		$this->assertCount( 12, $first['trend']['values'] );
		$this->assertSame( 70, $first['trend']['min'] );
		$this->assertSame( 170, $first['trend']['max'] );

		$second = $result->keywords[1];
		$this->assertSame( 'flaechenreinigung', $second['keyword'] );
		$this->assertNull( $second['difficulty'] );
		$this->assertNull( $second['cpc'] );
		$this->assertSame( 'thermo flächenreinigung', $result->keywords[2]['keyword'] );
		$this->assertSame( 20, $result->keywords[2]['volume'] );
		$this->assertFalse( $result->volumes_unknown );
	}

	public function test_keyword_chef_all_zero_volumes_become_unknown(): void {
		$result = KeywordImporter::from_file( self::FIXTURES . 'keyword-chef-flaechenreinigung.csv' );

		$this->assertSame( 'keyword_chef', $result->profile->id );
		$this->assertSame( 'csv:keyword_chef', $result->profile->source );
		$this->assertSame( 41, $result->rows_total );
		$this->assertCount( 41, $result->keywords );
		$this->assertTrue( $result->volumes_unknown );
		foreach ( $result->keywords as $entry ) {
			$this->assertNull( $entry['volume'] );
		}
		$this->assertSame( 'hamburger flächenreinigung', $result->keywords[0]['keyword'] );
		$this->assertStringContainsString( 'unbekannt', implode( ' ', $result->notes ) );
	}

	public function test_windows_1252_semicolon_export(): void {
		$result = KeywordImporter::from_file( self::FIXTURES . 'excel-export-windows1252.csv' );

		$this->assertSame( 'generic', $result->profile->id );
		$this->assertSame( 'csv:generic', $result->profile->source );
		$this->assertSame( 'Windows-1252', $result->encoding );
		$this->assertSame( ';', $result->delimiter );
		$this->assertSame( 'CPC (EUR)', $result->profile->mapping['cpc'] );
		$this->assertCount( 3, $result->keywords );

		$this->assertSame( 'Flächenreinigung Hannover', $result->keywords[0]['keyword'] );
		$this->assertSame( 1200, $result->keywords[0]['volume'] );
		$this->assertSame( 2.35, $result->keywords[0]['cpc'] );
		$this->assertSame( 41.0, $result->keywords[0]['difficulty'] );
		$this->assertNull( $result->keywords[1]['difficulty'] );
		$this->assertSame( 'Pflaster, reinigen', $result->keywords[2]['keyword'] );
		// Eine einzelne 0 bleibt 0; nur wenn alle 0 sind, gilt „unbekannt“.
		$this->assertSame( 0, $result->keywords[2]['volume'] );
	}

	public function test_duplicates_and_empty_rows_are_skipped(): void {
		$table  = TableReader::parse_csv( "Keyword;Volume\nFlächenreinigung;10\n;5\nflächenreinigung ;20\n" );
		$result = KeywordImporter::apply( $table['header'], $table['rows'], ImportProfile::detect( $table['header'] ) );

		$this->assertCount( 1, $result->keywords );
		$this->assertSame( 2, $result->rows_skipped );
		$this->assertSame( 10, $result->keywords[0]['volume'] );
	}

	public function test_manual_mapping(): void {
		$table   = TableReader::parse_csv( "Begriff\tSV\tKD\nterrassenreinigung\t480\t22\n" );
		$profile = ImportProfile::detect( $table['header'] )->with_map( 'keyword=Begriff,volume=SV,difficulty=KD', $table['header'] );
		$result  = KeywordImporter::apply( $table['header'], $table['rows'], $profile );

		$this->assertSame( 'Tab', $table['delimiter'] );
		$this->assertSame( 480, $result->keywords[0]['volume'] );
		$this->assertSame( 22.0, $result->keywords[0]['difficulty'] );
	}

	public function test_missing_keyword_column_is_reported(): void {
		$this->expectException( \InvalidArgumentException::class );
		$table = TableReader::parse_csv( "Spalte A;SV\nx;1\n" );
		KeywordImporter::apply( $table['header'], $table['rows'], ImportProfile::detect( $table['header'] ) );
	}

	public function test_unknown_map_field_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		ImportProfile::detect( array( 'Keyword' ) )->with_map( 'farbe=Keyword', array( 'Keyword' ) );
	}

	public function test_intent_mapping(): void {
		$this->assertSame( 'informational', KeywordImporter::intent( 'Informativ' ) );
		$this->assertSame( 'local', KeywordImporter::intent( 'lokal' ) );
		$this->assertNull( KeywordImporter::intent( 'irgendwas' ) );
	}

	public function test_xlsx_with_shared_and_inline_strings(): void {
		if ( ! class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'zip fehlt.' );
		}
		$path = sys_get_temp_dir() . '/lsec-test-' . uniqid() . '.xlsx';
		$this->write_xlsx( $path );
		try {
			$result = KeywordImporter::from_file( $path, 'eigene-liste.xlsx' );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		// Excel ohne Textfocus-Spalten: allgemeine Vorlage.
		$this->assertSame( 'generic', $result->profile->id );
		$this->assertSame( 'xlsx:generic', $result->profile->source );
		$this->assertCount( 2, $result->keywords );
		$this->assertSame( 'Hochdruckreiniger für Pflaster', $result->keywords[0]['keyword'] );
		$this->assertSame( 320, $result->keywords[0]['volume'] );
		$this->assertSame( 'Terrassenreinigung Lehrte', $result->keywords[1]['keyword'] );
		$this->assertNull( $result->keywords[1]['volume'] );
	}

	/**
	 * Schreibt eine kleine Excel-Datei mit gemeinsamen und eingebetteten Texten und einer leeren Zelle.
	 *
	 * @param string $path Ziel.
	 */
	private function write_xlsx( string $path ): void {
		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>' );
		$zip->addFromString(
			'xl/sharedStrings.xml',
			'<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<si><t>Keyword</t></si><si><t>Volume</t></si><si><r><t>Hochdruckreiniger </t></r><r><t>für Pflaster</t></r></si></sst>'
		);
		$zip->addFromString(
			'xl/worksheets/sheet1.xml',
			'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
			. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
			. '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>320</v></c></row>'
			. '<row r="3"><c r="A3" t="inlineStr"><is><t>Terrassenreinigung Lehrte</t></is></c></row>'
			. '</sheetData></worksheet>'
		);
		$zip->close();
	}

	public function test_real_textfocus_keyword_volume_export(): void {
		$result = KeywordImporter::from_file( self::FIXTURES . 'textfocus-keyword-volume.xlsx' );

		$this->assertSame( 'textfocus', $result->profile->id );
		$this->assertSame( 'xlsx:textfocus', $result->profile->source );
		$this->assertSame( 'Expression', $result->profile->mapping['keyword'] );
		$this->assertSame( 'Monthly volume', $result->profile->mapping['volume'] );
		$this->assertCount( 6, $result->keywords );

		$this->assertSame( 'dachreinigung hannover', $result->keywords[0]['keyword'] );
		$this->assertSame( 90, $result->keywords[0]['volume'] );
		$this->assertSame( 5.13, $result->keywords[0]['cpc'] );
		$this->assertNull( $result->keywords[0]['difficulty'] );
		$this->assertSame( 10, $result->keywords[1]['volume'] );
		$this->assertNull( $result->keywords[1]['cpc'] );
		$this->assertNull( $result->keywords[2]['volume'] );
		$this->assertStringContainsString( 'Nullwerte', implode( ' ', $result->notes ) );
	}

	public function test_textfocus_entity_table_is_redirected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'wp lsec entities import' );
		KeywordImporter::from_file( self::FIXTURES . 'textfocus-entities.xlsx' );
	}
}
