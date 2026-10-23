<?php
/**
 * Vergleich von Ortsnamen (0.7.11).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Entities;

use Lsec\Entities\PlaceName;
use PHPUnit\Framework\TestCase;

/**
 * Servicegebiet „Uetze“ und Kandidat „Gemeinde Uetze“ sind derselbe Ort.
 */
final class PlaceNameTest extends TestCase {

	public function test_same_place(): void {
		$this->assertTrue( PlaceName::same( 'Uetze', 'Gemeinde Uetze' ) );
		$this->assertTrue( PlaceName::same( 'uetze', 'Uetze' ) );
		$this->assertTrue( PlaceName::same( 'Stadt Lehrte', 'Lehrte' ) );
		$this->assertTrue( PlaceName::same( 'Burgdorf', 'Burgdorf' ) );
		$this->assertTrue( PlaceName::same( 'Uetze und Umgebung', 'Uetze' ) );
		$this->assertTrue( PlaceName::same( 'Lüneburg', 'Lueneburg' ) );
		$this->assertSame( 'lüneburg', PlaceName::search_word( 'Hansestadt Lüneburg und Umgebung' ) );
	}

	public function test_claim_about_place(): void {
		$uetze = array(
			'entity'      => 'Gemeinde Uetze',
			'entity_type' => 'place',
			'quote'       => 'x',
		);
		$eltze = array(
			'entity'      => 'Eltze',
			'entity_type' => 'place',
			'quote'       => 'Eltze ist ein Ortsteil der Gemeinde Uetze.',
		);
		$this->assertTrue( PlaceName::about( $uetze, 'Uetze' ) );
		$this->assertTrue( PlaceName::about( $eltze, 'Uetze' ) );
		$this->assertFalse( PlaceName::about( $uetze, 'Lehrte' ) );
		$this->assertFalse( PlaceName::about( array_merge( $eltze, array( 'entity_type' => 'concept' ) ), 'Uetze' ), 'nur Orte' );
		$this->assertFalse(
			PlaceName::about(
				array(
					'entity'      => 'Uetze',
					'entity_type' => 'concept',
				),
				'Uetze'
			)
		);
	}

	public function test_different_places(): void {
		$this->assertFalse( PlaceName::same( 'Region Hannover', 'Hannover' ) );
		$this->assertFalse( PlaceName::same( 'Gehwege in der Gemeinde Uetze', 'Uetze' ) );
		$this->assertFalse( PlaceName::same( 'Uetze', 'Eltze' ) );
		$this->assertFalse( PlaceName::same( '', '' ) );
		$this->assertSame( 'gemeinde', PlaceName::key( 'Gemeinde' ) );
	}
}
