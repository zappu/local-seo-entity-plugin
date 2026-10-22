<?php
/**
 * Tests der Faktenprüfung (Spezifikation 8.4a).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Topics;

use Lsec\Topics\FactChecker;
use PHPUnit\Framework\TestCase;

/**
 * Der Eltze/Dollbergen-Fall aus dem Machbarkeitstest: dieselbe Jahreszahl für zwei Ortsteile.
 */
final class FactCheckerTest extends TestCase {

	private const PAGE = "Ortsteile der Gemeinde Uetze\nEltze wurde im Jahr 1226 erstmals urkundlich erwähnt. Der Ort liegt an der Fuhse.\nDollbergen ist für seine Raffinerie bekannt.";

	public function test_matching_entity_is_verified(): void {
		$result = FactChecker::check(
			array(
				'quote' => 'Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt',
				'value' => '1226',
			),
			array( 'Eltze' ),
			self::PAGE
		);
		$this->assertSame( 'verified', $result['status'] );
		$this->assertSame( 'strong', $result['quality'] );
	}

	public function test_wrong_entity_with_same_year_is_not_verified(): void {
		$result = FactChecker::check(
			array(
				'quote' => 'Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt',
				'value' => '1226',
			),
			array( 'Dollbergen' ),
			self::PAGE
		);
		$this->assertSame( 'disputed', $result['status'] );
		$this->assertSame( 'weak', $result['quality'] );
		$this->assertStringContainsString( 'Bezug unklar', $result['note'] );
	}

	public function test_paraphrased_quote_is_checked_by_value_and_entity(): void {
		$eltze = FactChecker::check(
			array(
				'quote' => 'Eltze wurde 1226 zum ersten Mal genannt',
				'value' => '1226',
			),
			array( 'Eltze' ),
			self::PAGE
		);
		$this->assertSame( 'verified', $eltze['status'] );
		$this->assertSame( 'medium', $eltze['quality'] );
		$this->assertStringContainsString( 'sinngemäß', $eltze['note'] );
		$this->assertSame( 'Eltze wurde im Jahr 1226 erstmals urkundlich erwähnt.', $eltze['excerpt'] );

		// Dollbergen bleibt trotz derselben Jahreszahl unklar.
		$dollbergen = FactChecker::check(
			array(
				'quote' => 'Dollbergen wurde 1226 erstmals erwähnt',
				'value' => '1226',
			),
			array( 'Dollbergen' ),
			self::PAGE
		);
		$this->assertSame( 'disputed', $dollbergen['status'] );
		$this->assertSame( 'weak', $dollbergen['quality'] );
	}

	public function test_value_not_on_page_or_page_missing_is_rejected(): void {
		$fact = array(
			'quote' => 'Dollbergen wurde 1312 erstmals erwähnt',
			'value' => '1312',
		);
		$this->assertSame( 'rejected', FactChecker::check( $fact, array( 'Dollbergen' ), self::PAGE )['status'] );
		$this->assertSame( 'rejected', FactChecker::check( $fact, array( 'Dollbergen' ), null, 'HTTP 403' )['status'] );
	}

	public function test_typographic_quotes_and_spacing_do_not_matter(): void {
		$page   = 'Die „Fuhse“ fließt  durch Eltze – seit 1226 belegt.';
		$result = FactChecker::check(
			array(
				'quote' => 'Die "Fuhse" fließt durch Eltze - seit 1226 belegt',
				'value' => '1226',
			),
			array( 'Eltze' ),
			$page
		);
		$this->assertSame( 'verified', $result['status'] );
	}

	public function test_number_must_match_whole(): void {
		$this->assertFalse( FactChecker::contains_value( 'im jahr 12260', '1226' ) );
		$this->assertTrue( FactChecker::contains_value( 'im jahr 1226.', '1226' ) );
	}

	public function test_contradicting_verified_facts_become_disputed(): void {
		$facts = FactChecker::contradictions(
			array(
				array(
					'subject'  => 'Eltze',
					'property' => 'first_mentioned',
					'value'    => '1226',
					'status'   => 'verified',
					'note'     => '',
				),
				array(
					'subject'  => 'eltze',
					'property' => 'first_mentioned',
					'value'    => '1228',
					'status'   => 'verified',
					'note'     => '',
				),
				array(
					'subject'  => 'Uetze',
					'property' => 'first_mentioned',
					'value'    => '1226',
					'status'   => 'verified',
					'note'     => '',
				),
			)
		);
		$this->assertSame( array( 'disputed', 'disputed', 'verified' ), array_column( $facts, 'status' ) );
	}

	public function test_several_rules_are_no_contradiction(): void {
		$facts = FactChecker::contradictions(
			array(
				array(
					'subject'  => 'Uetze',
					'property' => 'regulation',
					'value'    => 'mindestens einmal wöchentlich',
					'status'   => 'verified',
					'note'     => '',
				),
				array(
					'subject'  => 'Uetze',
					'property' => 'regulation',
					'value'    => 'Reinigungspflicht auch für Parkbuchten',
					'status'   => 'verified',
					'note'     => '',
				),
				array(
					'subject'  => 'Uetze',
					'property' => 'landmark',
					'value'    => 'Junkernhof von 1635',
					'status'   => 'verified',
					'note'     => '',
				),
				array(
					'subject'  => 'Uetze',
					'property' => 'landmark',
					'value'    => 'Kübbungshaus von 1596',
					'status'   => 'verified',
					'note'     => '',
				),
			)
		);
		$this->assertSame( array( 'verified', 'verified', 'verified', 'verified' ), array_column( $facts, 'status' ) );
	}
}
