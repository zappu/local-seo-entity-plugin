<?php
/**
 * Tests für den Import der Textfocus-Entitäten.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Entities;

use Lsec\Entities\TextfocusEntityImport;
use PHPUnit\Framework\TestCase;

/**
 * Echter Export der Seitenanalyse flaechenreiniger-wassertechnik.de vom 17.10.2026.
 */
final class TextfocusEntityImportTest extends TestCase {

	public function test_real_entity_export(): void {
		$rows = TextfocusEntityImport::from_file( __DIR__ . '/../../fixtures/import/textfocus-entities.xlsx' );

		$this->assertCount( 19, $rows );
		$by_name = array_column( $rows, null, 'name' );

		// Begriff von der Seite als Name, Textfocus-Zuordnung als Hinweis.
		$this->assertSame( 'Boden (Bodenkunde)', $by_name['Böden']['entity'] );
		$this->assertSame( 'Q36133', $by_name['Böden']['wikidata'] );
		$this->assertSame( 'concept', $by_name['Böden']['type'] );
		$this->assertSame( 'https://de.wikipedia.org/wiki/Boden_(Bodenkunde)', $by_name['Böden']['wikipedia'] );

		$this->assertSame( 'place', $by_name['Hannover']['type'] );
		$this->assertSame( 'Q1715', $by_name['Hannover']['wikidata'] );
		$this->assertEqualsWithDelta( 0.2954, (float) $by_name['Hannover']['relevance'], 0.0001 );

		$this->assertSame( 'organization', $by_name['Staats Flächenreinigung & Wassertechnik']['type'] );
		$this->assertSame( '', $by_name['Staats Flächenreinigung & Wassertechnik']['wikidata'] );

		$types = array_count_values( array_column( $rows, 'type' ) );
		$this->assertSame( 5, $types['place'] );
		$this->assertSame( 4, $types['organization'] );
		$this->assertSame( 10, $types['concept'] );
	}

	public function test_other_tables_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		TextfocusEntityImport::parse( array( 'Expression', 'Monthly volume' ), array() );
	}
}
