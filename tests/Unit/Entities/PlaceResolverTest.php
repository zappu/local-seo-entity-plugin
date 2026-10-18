<?php
/**
 * Tests für den Ortsabgleich (M18).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Entities;

use Lsec\Entities\PlaceResolver;
use Lsec\Entities\ServiceArea;
use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Tests\Support\FakeTransport;
use Lsec\Tests\Support\MemoryProviderCallLog;
use Lsec\Workflow\RetryableException;
use PHPUnit\Framework\TestCase;

/**
 * Burgdorf gibt es in Niedersachsen zweimal: Region Hannover (Q555618) und Landkreis Wolfenbüttel (Q634167).
 * Antworten von Wikidata vom 18.10.2026, siehe tests/fixtures/wikidata/README.txt.
 */
final class PlaceResolverTest extends TestCase {

	/**
	 * Antworten in der Reihenfolge der Abfragen: Suche, Kandidaten, übergeordnete Einheiten, Land.
	 *
	 * @return FakeTransport
	 */
	private function transport(): FakeTransport {
		$dir       = __DIR__ . '/../../fixtures/wikidata/';
		$responses = array();
		foreach ( array( 'search-burgdorf.json', 'entities-burgdorf.json', 'entities-parents.json', 'entities-niedersachsen.json' ) as $file ) {
			$responses[] = array(
				'status' => 200,
				'body'   => (string) file_get_contents( $dir . $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}
		return new FakeTransport( $responses );
	}

	public function test_without_context_two_candidates_stay_unresolved(): void {
		$result = ( new PlaceResolver( new WikidataClient( $this->transport() ) ) )->resolve( 'Burgdorf', null );

		$this->assertSame( 'unresolved', $result['status'] );
		$this->assertNull( $result['chosen'] );
		$this->assertSame( array( 'Q555618', 'Q634167' ), array_column( $result['candidates'], 'id' ) );
	}

	public function test_with_service_area_region_hannover_the_right_one_is_probable(): void {
		$area   = new ServiceArea( 'Region Hannover', array( 'Q5963' ) );
		$result = ( new PlaceResolver( new WikidataClient( $this->transport() ) ) )->resolve( 'Burgdorf', $area );

		$this->assertSame( 'probable', $result['status'] );
		$this->assertSame( 'Q555618', $result['chosen']['id'] );
		$this->assertSame( 'Stadt in der Region Hannover', $result['chosen']['description'] );
		$this->assertContains( 'Q5963', $result['chosen']['located_in'] );
		$other = $result['candidates'][1];
		$this->assertSame( 'Q634167', $other['id'] );
		$this->assertFalse( $other['in_area'] );
		$this->assertContains( 'Q6041', $other['located_in'] );
		$this->assertContains( 'Q1197', $other['located_in'] );
	}

	public function test_radius_around_hannover(): void {
		$hannover = array( 52.374, 9.738 );

		$near   = new ServiceArea( 'Hannover 25 km', array(), $hannover, 25 );
		$result = ( new PlaceResolver( new WikidataClient( $this->transport() ) ) )->resolve( 'Burgdorf', $near );
		$this->assertSame( 'probable', $result['status'] );
		$this->assertSame( 'Q555618', $result['chosen']['id'] );
		$this->assertEqualsWithDelta( 19.9, $result['chosen']['distance_km'], 0.2 );

		// Burgdorf (Wolfenbüttel) liegt 41 km entfernt. Bei 50 km liegen beide im Umkreis: dann entscheidet der Administrator.
		$wide   = new ServiceArea( 'Hannover 50 km', array(), $hannover, 50 );
		$result = ( new PlaceResolver( new WikidataClient( $this->transport() ) ) )->resolve( 'Burgdorf', $wide );
		$this->assertSame( 'unresolved', $result['status'] );
	}

	public function test_switzerland_locale_picks_bern(): void {
		$empty  = array(
			'status' => 200,
			'body'   => '{"entities":{},"success":1}',
		);
		$http   = new FakeTransport( array_merge( array_slice( $this->responses(), 0, 2 ), array( $empty, $empty ) ) );
		$result = ( new PlaceResolver( new WikidataClient( $http ) ) )->resolve( 'Burgdorf', null, 'de_CH' );

		// Q68311 (Kanton Bern); Schloss und Hasle haben einen anderen Namen bzw. liegen in Q68311.
		$this->assertSame( 'probable', $result['status'] );
		$this->assertSame( 'Q68311', $result['chosen']['id'] );
	}

	public function test_cache_avoids_second_request_and_429_is_retryable(): void {
		$log    = new MemoryProviderCallLog();
		$http   = new FakeTransport(
			array(
				$this->responses()[0],
				array(
					'status' => 429,
					'body'   => 'You are making too many requests to the API.',
				),
			)
		);
		$client = new WikidataClient( $http, $log );
		$client->search( 'Burgdorf' );
		$client->search( 'Burgdorf' );
		$this->assertCount( 1, $http->requests );
		$this->assertStringContainsString( 'LocalSEOEntityPlugin', $http->requests[0]['headers']['User-Agent'] );

		$this->expectException( RetryableException::class );
		$client->entities( array( 'Q555618' ) );
	}

	public function test_ended_memberships_are_ignored(): void {
		$entity = WikidataClient::simplify(
			'Q68311',
			array(
				'claims' => array(
					'P131' => array(
						array( 'mainsnak' => array( 'datavalue' => array( 'value' => array( 'id' => 'Q666253' ) ) ) ),
						array(
							'mainsnak'   => array( 'datavalue' => array( 'value' => array( 'id' => 'Q661227' ) ) ),
							'qualifiers' => array( 'P582' => array() ),
						),
					),
				),
			)
		);
		$this->assertSame( array( 'Q666253' ), $entity['located_in'] );
	}

	public function test_service_area_from_settings_array(): void {
		$this->assertNull( ServiceArea::from_array( array() ) );
		$this->assertNull( ServiceArea::from_array( array( 'center' => array( 52.3, 9.7 ) ) ) );
		$area = ServiceArea::from_array(
			array(
				'label'    => 'Region Hannover',
				'wikidata' => array( 'Q5963', 'kein-id' ),
			)
		);
		$this->assertNotNull( $area );
		$this->assertSame( array( 'Q5963' ), $area->wikidata_ids );
	}

	/**
	 * Rohantworten.
	 *
	 * @return array<int, array{status: int, body: string}>
	 */
	private function responses(): array {
		$dir = __DIR__ . '/../../fixtures/wikidata/';
		return array_map(
			static fn( string $f ): array => array(
				'status' => 200,
				'body'   => (string) file_get_contents( $dir . $f ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			),
			array( 'search-burgdorf.json', 'entities-burgdorf.json', 'entities-parents.json', 'entities-niedersachsen.json' )
		);
	}
}
