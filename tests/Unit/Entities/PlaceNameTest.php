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

	public function test_different_places(): void {
		$this->assertFalse( PlaceName::same( 'Region Hannover', 'Hannover' ) );
		$this->assertFalse( PlaceName::same( 'Gehwege in der Gemeinde Uetze', 'Uetze' ) );
		$this->assertFalse( PlaceName::same( 'Uetze', 'Eltze' ) );
		$this->assertFalse( PlaceName::same( '', '' ) );
		$this->assertSame( 'gemeinde', PlaceName::key( 'Gemeinde' ) );
	}
}
