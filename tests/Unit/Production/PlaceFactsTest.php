<?php
/**
 * Recherche der Ortsfakten (0.7.13).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Production;

use Lsec\Production\PlaceFacts;
use PHPUnit\Framework\TestCase;

/**
 * Nur Tatsachen mit Quelle und Zitat, höchstens acht, ohne Dubletten.
 */
final class PlaceFactsTest extends TestCase {

	public function test_validate(): void {
		$sources = array(
			array(
				'url'   => 'https://de.wikipedia.org/wiki/Lehrte',
				'title' => 'Lehrte',
			),
		);
		$data    = array(
			'facts' => array(
				array(
					'property'   => 'autobahn',
					'value'      => 'A 2',
					'source_url' => 'https://de.wikipedia.org/wiki/Lehrte/',
					'quote'      => 'Lehrte liegt an der A 2.',
				),
				array(
					'property'   => 'autobahn',
					'value'      => 'A 2',
					'source_url' => 'https://de.wikipedia.org/wiki/Lehrte',
					'quote'      => 'doppelt',
				),
				array(
					'property'   => 'einwohner',
					'value'      => '45.000',
					'source_url' => '',
					'quote'      => 'ohne Quelle',
				),
				array(
					'property'   => 'fluss',
					'value'      => 'Fuhse',
					'source_url' => 'keine adresse',
					'quote'      => 'x',
				),
			),
		);
		$out     = PlaceFacts::validate( $data, 'Lehrte', $sources );
		$this->assertCount( 1, $out['facts'] );
		$this->assertSame( 'https://de.wikipedia.org/wiki/Lehrte', $out['facts'][0]['source_url'] );
		$this->assertSame( 'place', $out['facts'][0]['subject_type'] );
		$this->assertSame( 'Lehrte', $out['facts'][0]['subject'] );
		$this->assertCount( 2, $out['dropped'] );
	}

	public function test_question_names_place_and_topic(): void {
		$question = PlaceFacts::question( 'Lehrte', 'Uetze und 25 km Umkreis', 'Pflaster schützen in Lehrte' );
		$this->assertStringContainsString( 'Lehrte', $question );
		$this->assertStringContainsString( 'Pflaster schützen', $question );
	}
}
