<?php
/**
 * Tests der Empfehlung bei doppelten Themen (0.10.2).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Topics;

use Lsec\Topics\TwinAdvice;
use PHPUnit\Framework\TestCase;

/**
 * Fall aus dem Test vom 24.10.: Themen #36 und #32 zur Parkplatzreinigung in Uetze.
 */
final class TwinAdviceTest extends TestCase {

	/**
	 * Keyword.
	 *
	 * @param int      $id          ID.
	 * @param string   $keyword     Keyword.
	 * @param string   $role        primary oder secondary.
	 * @param int|null $volume      Suchvolumen.
	 * @param int|null $impressions Impressionen.
	 * @param string[] $places      Erkannte Orte.
	 * @return array<string, mixed>
	 */
	private static function kw( int $id, string $keyword, string $role, ?int $volume, ?int $impressions, array $places = array() ): array {
		return array(
			'id'          => $id,
			'keyword'     => $keyword,
			'role'        => $role,
			'volume'      => $volume,
			'impressions' => $impressions,
			'exclusion'   => null,
			'places'      => $places,
		);
	}

	/**
	 * Thema #36.
	 *
	 * @return array<string, mixed>
	 */
	private static function t36(): array {
		return array(
			'id'             => 36,
			'status'         => 'proposed',
			'region'         => 'Uetze',
			'priority_score' => 85.0,
			'keywords'       => array(
				self::kw( 1, 'parkplatzreinigung', 'primary', 140, 6 ),
				self::kw( 2, 'parkplatzreinigung hannover', 'primary', null, 3, array( 'Hannover' ) ),
				self::kw( 3, 'außenflächen reinigen lassen', 'secondary', null, 6 ),
				self::kw( 4, 'außenreinigung', 'secondary', 210, 7 ),
				self::kw( 5, 'einfahrt reinigung', 'secondary', 210, 1 ),
				self::kw( 6, 'oberflächenreinigung', 'secondary', 110, 30 ),
			),
		);
	}

	/**
	 * Thema #32.
	 *
	 * @return array<string, mixed>
	 */
	private static function t32(): array {
		return array(
			'id'             => 32,
			'status'         => 'proposed',
			'region'         => 'Uetze',
			'priority_score' => 82.0,
			'keywords'       => array(
				self::kw( 1, 'parkplatzreinigung', 'primary', 140, 6 ),
				self::kw( 3, 'außenflächen reinigen lassen', 'secondary', null, 6 ),
				self::kw( 7, 'gewerbliche reinigung', 'secondary', 170, 3 ),
				self::kw( 2, 'parkplatzreinigung hannover', 'secondary', null, 3, array( 'Hannover' ) ),
			),
		);
	}

	public function test_case_36_and_32(): void {
		$advice = TwinAdvice::compare( self::t36(), self::t32() );
		$this->assertSame( 36, $advice['keep'] );
		$this->assertSame( 32, $advice['reject'] );
		$this->assertContains( 'Priorität 85 statt 82', $advice['reasons'] );
		$this->assertContains( '670 statt 310 Suchen im Monat', $advice['reasons'] );
		$this->assertSame( array( 'Haupt-Keyword „parkplatzreinigung hannover“ nennt einen anderen Ort (Hannover).' ), $advice['warnings'][36] );
		$this->assertArrayNotHasKey( 32, $advice['warnings'], 'Hannover nur als weiteres Keyword' );
		$this->assertSame(
			array(
				array(
					'id'      => 7,
					'keyword' => 'gewerbliche reinigung',
				),
			),
			$advice['adopt']
		);
		$this->assertSame( 36, TwinAdvice::compare( self::t32(), self::t36() )['keep'], 'Reihenfolge egal' );
	}

	public function test_topic_in_progress_wins(): void {
		$approved           = self::t32();
		$approved['status'] = 'approved';
		$advice             = TwinAdvice::compare( self::t36(), $approved );
		$this->assertSame( 32, $advice['keep'] );
		$this->assertSame( array( 'An Thema #32 wird schon gearbeitet (freigegeben).' ), $advice['reasons'] );
	}

	public function test_foreign_place_costs_a_point(): void {
		$a                   = self::t32();
		$a['id']             = 40;
		$a['priority_score'] = 82.0;
		$b                   = self::t32();
		$b['id']             = 41;
		$b['keywords'][0]    = self::kw( 8, 'parkplatzreinigung celle', 'primary', 140, 6, array( 'Celle' ) );
		$advice              = TwinAdvice::compare( $b, $a );
		$this->assertSame( 40, $advice['keep'] );
		$this->assertContains( 'Das andere Thema hat ein Haupt-Keyword mit einem fremden Ort.', $advice['reasons'] );
	}

	public function test_equal_topics_keep_older(): void {
		$a       = self::t32();
		$b       = self::t32();
		$b['id'] = 50;
		$advice  = TwinAdvice::compare( $b, $a );
		$this->assertSame( 32, $advice['keep'] );
		$this->assertSame( array( 'Beide Themen sind etwa gleich stark; behalten wird das ältere.' ), $advice['reasons'] );
	}

	public function test_region_containing_place_is_not_foreign(): void {
		$this->assertSame( array(), TwinAdvice::foreign_places( array( 'places' => array( 'Hannover' ) ), 'Region Hannover' ) );
		$this->assertSame( array( 'Hannover' ), TwinAdvice::foreign_places( array( 'places' => array( 'Hannover' ) ), 'Uetze' ) );
		$this->assertSame( array(), TwinAdvice::foreign_places( array( 'places' => array( 'Hannover' ) ), '' ) );
	}
}
