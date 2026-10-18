<?php
/**
 * Tests für die Tippfehlerprüfung (M17).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Research;

use Lsec\Research\Import\KeywordImporter;
use Lsec\Research\Keywords\TypoChecker;
use PHPUnit\Framework\TestCase;

/**
 * Wortschatz aus den echten Testdateien.
 */
final class TypoCheckerTest extends TestCase {

	/**
	 * Prüfer mit den Keywords aus Keyword Chef und Keyword Revealer.
	 */
	private function checker(): TypoChecker {
		$known = array();
		foreach ( array( 'keyword-chef-flaechenreinigung.csv', 'keyword-revealer-flaechenreinigung.csv' ) as $file ) {
			foreach ( KeywordImporter::from_file( __DIR__ . '/../../fixtures/import/' . $file )->keywords as $entry ) {
				$known[] = $entry['keyword'];
			}
		}
		$known[] = 'Terrassenreinigung';
		$known[] = 'Burgdorf';
		return new TypoChecker( $known );
	}

	public function test_m17_typo_is_detected_before_provider_call(): void {
		$findings = $this->checker()->check( 'flächenreingung' );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'flächenreinigung', $findings[0]['suggestion'] );
		$this->assertSame( 'edit_distance', $findings[0]['reason'] );
		$this->assertSame( 1, $findings[0]['distance'] );
		$this->assertSame( 'flächenreinigung hannover', $this->checker()->suggest( 'Flächenreingung Hannover' ) );
	}

	public function test_umlaut_variant_is_detected(): void {
		$findings = $this->checker()->check( 'flaechenreinigung terrasse' );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'umlaut', $findings[0]['reason'] );
		$this->assertSame( 'flächenreinigung', $findings[0]['suggestion'] );
	}

	public function test_correct_and_unknown_words_pass(): void {
		$checker = $this->checker();
		$this->assertSame( array(), $checker->check( 'Flächenreinigung Burgdorf' ) );
		$this->assertSame( array(), $checker->check( 'Dachrinnenreinigung Lehrte' ) );
		$this->assertSame( array(), $checker->check( 'Thermo Flächenreinigung 2026' ) );
	}

	public function test_short_words_need_exact_umlaut_variant(): void {
		$checker = new TypoChecker( array( 'Böden reinigen', 'Böden versiegeln', 'Böden ölen', 'Boden' ) );
		$this->assertSame( 'böden', $checker->check( 'boeden' )[0]['suggestion'] );
		// „boden“ ist selbst bekannt und nicht dreimal seltener: keine Meldung.
		$this->assertSame( array(), ( new TypoChecker( array( 'Boden', 'Böden' ) ) )->check( 'boden' ) );
	}
}
