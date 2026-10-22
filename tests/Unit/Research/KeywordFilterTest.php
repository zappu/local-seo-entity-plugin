<?php
/**
 * Tests der Ausschlussregeln für Keywords.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Research;

use Lsec\Research\Keywords\KeywordAnalyzer;
use Lsec\Research\Keywords\KeywordFilter;
use PHPUnit\Framework\TestCase;

/**
 * Spezifikation 6.4: Für „flächenreinigung“ lieferte tf_related vor allem fremde Firmen und Hamburg.
 */
final class KeywordFilterTest extends TestCase {

	/**
	 * Filter wie auf der Testwebsite: ein Wettbewerber, die eigene Firma mit Kurzform, Mosmatic als angebotene
	 * Marke, Kärcher als bestätigte fremde Marke, Orte: Hannover und Gehrden im Gebiet, Hamburg außerhalb.
	 */
	private function filter(): KeywordFilter {
		return new KeywordFilter(
			array( 'Musterputz GmbH' => array( 'Musterputz' ) ),
			array( 'Staats Flächenreinigung & Wassertechnik', 'Staats', 'Mosmatic' ),
			array( 'Kärcher' => array() ),
			static fn( string $place ): ?bool => match ( mb_strtolower( $place ) ) {
				'hannover', 'gehrden' => true,
				'hamburg', 'seevetal' => false,
				default => null,
			}
		);
	}

	public function test_competitor_by_alias(): void {
		$result = $this->filter()->evaluate( 'musterputz flächenreinigung' );
		$this->assertSame( 'competitor', $result['exclusion'] );
		$this->assertSame( 'Musterputz GmbH', $result['detail'] );
	}

	public function test_foreign_place(): void {
		$result = $this->filter()->evaluate( 'flächenreinigung hamburg', array( 'places' => array( 'hamburg' ) ) );
		$this->assertSame( 'foreign_place', $result['exclusion'] );
		$this->assertSame( 'hamburg', $result['detail'] );
	}

	public function test_place_in_area_or_unknown_stays(): void {
		$this->assertNull( $this->filter()->evaluate( 'flächenreinigung gehrden', array( 'places' => array( 'gehrden' ) ) )['exclusion'] );
		$this->assertNull( $this->filter()->evaluate( 'flächenreinigung irgendwo', array( 'places' => array( 'irgendwo' ) ) )['exclusion'] );
	}

	public function test_foreign_brand_from_analysis_and_known_brand(): void {
		$this->assertSame( 'brand', $this->filter()->evaluate( 'putzblitz terrassenreinigung', array( 'names' => array( 'Putzblitz' ) ) )['exclusion'] );
		$this->assertSame( 'Kärcher', $this->filter()->evaluate( 'kaercher hochdruckreiniger' )['detail'] );
	}

	public function test_own_names_and_offered_brands_are_kept(): void {
		$this->assertNull( $this->filter()->evaluate( 'staats flächenreinigung', array( 'names' => array( 'Staats' ) ) )['exclusion'] );
		$this->assertNull( $this->filter()->evaluate( 'mosmatic flächenreiniger', array( 'names' => array( 'Mosmatic' ) ) )['exclusion'] );
	}

	public function test_competitor_wins_over_place(): void {
		$analysis = array(
			'places' => array( 'hamburg' ),
			'names'  => array( 'musterputz' ),
		);
		$result   = $this->filter()->evaluate( 'musterputz hamburg', $analysis );
		$this->assertSame( 'competitor', $result['exclusion'] );
	}

	public function test_names_match_whole_words_only(): void {
		// „Staats“ steckt in „staatsoper“, ist dort aber kein eigenes Wort.
		$this->assertSame( 'brand', $this->filter()->evaluate( 'staatsoper reinigung', array( 'names' => array( 'Staatsoper' ) ) )['exclusion'] );
		$this->assertNull( $this->filter()->evaluate( 'kärchers erben' )['exclusion'] );
	}

	public function test_analyzer_keeps_only_literal_terms(): void {
		$data  = array(
			'keywords' => array(
				array(
					'keyword' => 'Flächenreinigung Hamburg',
					'places'  => array( 'Hamburg', 'Niedersachsen' ),
					'names'   => array(),
				),
				array(
					'keyword' => 'nicht gefragt',
					'places'  => array( 'Berlin' ),
					'names'   => array(),
				),
			),
		);
		$found = KeywordAnalyzer::verify( $data, array( 'flächenreinigung hamburg', 'terrassenreinigung' ) );
		$this->assertSame( array( 'flächenreinigung hamburg' ), array_keys( $found ) );
		$this->assertSame( array( 'Hamburg' ), $found['flächenreinigung hamburg']['places'] );
	}
}
