<?php
/**
 * Tests des Namensvergleichs für Vorschläge zum Zusammenführen.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Entities;

use Lsec\Entities\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Spezifikation 8.3: Schreibweisen der eigenen Firma (Testauswertung 18.10.2026, Textfocus-Entitäten).
 */
final class MergeSuggestionTest extends TestCase {

	private const FIRMA = 'Staats Flächenreinigungs-Service & Wassertechnik';

	/**
	 * Die vier Schreibweisen aus dem Textfocus-Export der Startseite.
	 *
	 * @return array<string, array{string}>
	 */
	public static function variants(): array {
		return array(
			'nur Nachname'      => array( 'Staats' ),
			'kurz'              => array( 'Staats Flächenreinigung' ),
			'mit Wassertechnik' => array( 'Staats Flächenreinigung & Wassertechnik' ),
			'voll'              => array( 'Staats Flächenreinigungs-Service & Wassertechnik' ),
			'mit Rechtsform'    => array( 'Staats Flächenreinigungs-Service und Wassertechnik GmbH' ),
			'Umlaut aufgelöst'  => array( 'Staats Flaechenreinigung' ),
		);
	}

	/**
	 * @dataProvider variants
	 */
	public function test_variants_of_the_company_name_match( string $name ): void {
		$this->assertTrue( EntityRepository::same_name( self::FIRMA, $name ) );
	}

	public function test_other_names_do_not_match(): void {
		$this->assertFalse( EntityRepository::same_name( self::FIRMA, 'Staatsoper Hannover' ) );
		$this->assertFalse( EntityRepository::same_name( self::FIRMA, 'Flächenreinigung Hannover' ) );
		$this->assertFalse( EntityRepository::same_name( self::FIRMA, 'Staats Gartenbau Berlin' ) );
		$this->assertFalse( EntityRepository::same_name( self::FIRMA, '' ) );
	}

	public function test_group_key_joins_area_phrases_with_the_place(): void {
		$this->assertSame( EntityRepository::group_key( 'Gehrden', 'place' )['key'], EntityRepository::group_key( 'Region Gehrden', 'service_area' )['key'] );
		$this->assertTrue( EntityRepository::group_key( 'Raum Gehrden', 'place' )['area'] );
		$this->assertSame( 'hochdruckreinigung', EntityRepository::group_key( 'Hochdruckreinigung', 'concept' )['key'] );
		// Echte Verwaltungsgebiete bleiben eigenständig.
		$this->assertSame( 'region hannover', EntityRepository::group_key( 'Region Hannover', 'service_area', true )['key'] );
		// Bei anderen Typen wird nichts gekürzt.
		$this->assertSame( 'region hannover marketing', EntityRepository::group_key( 'Region Hannover Marketing', 'organization' )['key'] );
	}
}
