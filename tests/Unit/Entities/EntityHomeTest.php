<?php
/**
 * Tests für die Hauptseite je Entität.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Entities;

use Lsec\Entities\EntityHome;
use PHPUnit\Framework\TestCase;

/**
 * Vorschläge, Treffer in Suchanfragen und Konkurrenz eigener Seiten.
 */
final class EntityHomeTest extends TestCase {

	/**
	 * Seite für Tests.
	 *
	 * @param int    $id    ID.
	 * @param string $title Titel.
	 * @param string $path  Pfad.
	 * @param string $kind  Inhaltstyp.
	 * @param bool   $front Startseite.
	 * @return array<string, mixed>
	 */
	private static function page( int $id, string $title, string $path, string $kind = 'page', bool $front = false ): array {
		return array(
			'post_id' => $id,
			'title'   => $title,
			'url'     => 'https://example.test' . $path,
			'path'    => $path,
			'kind'    => $kind,
			'front'   => $front,
		);
	}

	/**
	 * Entität für Tests.
	 *
	 * @param string   $name    Name.
	 * @param string   $type    Typ.
	 * @param string[] $aliases Schreibweisen.
	 * @return array<string, mixed>
	 */
	private static function entity( string $name, string $type = 'service', array $aliases = array() ): array {
		return array(
			'id'       => 7,
			'name'     => $name,
			'type'     => $type,
			'aliases'  => $aliases,
			'location' => 'place' === $type,
		);
	}

	public function test_service_page_with_name_in_title_beats_blog_post(): void {
		$pages = array(
			self::page( 1, 'Tipps zur Fassadenreinigung im Herbst', '/tipps-fassadenreinigung-herbst/', 'post' ),
			self::page( 2, 'Fassadenreinigung', '/leistungen/fassadenreinigung/' ),
			self::page( 3, 'Kontakt', '/kontakt/' ),
		);
		$out   = EntityHome::suggest( self::entity( 'Fassadenreinigung' ), $pages, array( 1 => 4 ), array() );
		$this->assertSame( 2, $out[0]['post_id'] );
		$this->assertContains( 'Name im Titel', $out[0]['reasons'] );
		$this->assertSame( array( 2, 1 ), array_column( $out, 'post_id' ) );
	}

	public function test_page_that_only_mentions_the_entity_is_not_suggested(): void {
		$pages = array( self::page( 3, 'Leistungen', '/leistungen/' ) );
		$this->assertSame( array(), EntityHome::suggest( self::entity( 'Implantologie' ), $pages, array( 3 => 5 ), array( 3 => 400 ) ) );
	}

	public function test_alias_and_words_of_the_name_count(): void {
		$rate = EntityHome::score( self::entity( 'Professionelle Zahnreinigung', 'service', array( 'PZR' ) ), self::page( 4, 'PZR in Uetze', '/pzr/' ) );
		$this->assertTrue( $rate['named'] );
		$this->assertContains( 'Name im Titel', $rate['reasons'] );
		$rate = EntityHome::score( self::entity( 'Pflaster Reinigung' ), self::page( 5, 'Reinigung von Pflaster und Wegen', '/pflaster/' ) );
		$this->assertTrue( $rate['named'] );
	}

	public function test_organization_prefers_front_page_and_about_page(): void {
		$pages = array(
			self::page( 1, 'Willkommen', '/', 'page', true ),
			self::page( 2, 'Über uns', '/ueber-uns/' ),
			self::page( 3, 'Pflasterarbeiten', '/pflasterarbeiten/' ),
		);
		$out   = EntityHome::suggest( self::entity( 'A. Staats GaLaBau', 'organization' ), $pages, array(), array() );
		$this->assertSame( array( 1, 2 ), array_column( $out, 'post_id' ) );
	}

	public function test_location_gets_contact_page(): void {
		$out = EntityHome::suggest( self::entity( 'Praxis Uetze', 'place' ), array( self::page( 9, 'Kontakt und Anfahrt', '/kontakt/' ) ), array( 9 => 1 ), array() );
		$this->assertSame( 9, $out[0]['post_id'] ?? 0 );
	}

	public function test_matches_name_alias_and_stems(): void {
		$entity = self::entity( 'Fassadenreinigung', 'service', array( 'Fassadenwäsche' ) );
		$this->assertTrue( EntityHome::matches( 'fassadenreinigung celle', $entity ) );
		$this->assertTrue( EntityHome::matches( 'Fassadenwäsche Preis', $entity ) );
		$this->assertTrue( EntityHome::matches( 'fassade reinigen lassen', $entity ) );
		$this->assertFalse( EntityHome::matches( 'dachreinigung celle', $entity ) );
		$this->assertTrue( EntityHome::matches( 'zahnprophylaxe uetze', self::entity( 'Prophylaxe' ) ) );
	}

	public function test_competition_lists_queries_with_two_own_pages(): void {
		$rows = array(
			array(
				'keyword'     => 'fassadenreinigung celle',
				'url'         => 'https://example.test/fassadenreinigung/',
				'post_id'     => 2,
				'impressions' => 120,
				'position'    => 8.2,
			),
			array(
				'keyword'     => 'fassadenreinigung celle',
				'url'         => 'https://example.test/fassadenreinigung-celle/',
				'post_id'     => 5,
				'impressions' => 40,
				'position'    => 15.0,
			),
			array(
				'keyword'     => 'fassadenreinigung kosten',
				'url'         => 'https://example.test/fassadenreinigung/',
				'post_id'     => 2,
				'impressions' => 60,
				'position'    => 6.0,
			),
			array(
				'keyword'     => 'fassadenreinigung kosten',
				'url'         => 'https://example.test/blog/',
				'post_id'     => 8,
				'impressions' => 3,
				'position'    => 40.0,
			),
		);
		$out  = EntityHome::competition( $rows, array( self::entity( 'Fassadenreinigung' ) ), array( 7 => 2 ) );
		$this->assertCount( 1, $out );
		$this->assertSame( 1, $out[0]['query_count'] );
		$this->assertSame( 'fassadenreinigung celle', $out[0]['queries'][0]['keyword'] );
		$this->assertTrue( $out[0]['queries'][0]['pages'][0]['home'] );
		$this->assertFalse( $out[0]['queries'][0]['pages'][1]['home'] );
		$this->assertStringContainsString( '1 weitere eigene Seite', $out[0]['advice'] );
		$none = EntityHome::competition( $rows, array( self::entity( 'Fassadenreinigung' ) ), array() );
		$this->assertStringContainsString( 'als Hauptseite festlegen', $none[0]['advice'] );
	}
}
