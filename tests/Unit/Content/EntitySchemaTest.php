<?php
/**
 * Tests für die strukturierten Daten aus den Entitäten.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Content;

use Lsec\Content\EntitySchema;
use PHPUnit\Framework\TestCase;

/**
 * Angaben about, mentions, knowsAbout und areaServed.
 */
final class EntitySchemaTest extends TestCase {

	private const ENTITIES = array(
		array(
			'id'       => 1,
			'type'     => 'service',
			'name'     => 'Terrassenreinigung',
			'aliases'  => array( 'Terrassen-Reinigung' ),
			'wikidata' => null,
		),
		array(
			'id'       => 2,
			'type'     => 'service_area',
			'name'     => 'Region Hannover',
			'aliases'  => array(),
			'wikidata' => 'Q5963',
		),
		array(
			'id'       => 3,
			'type'     => 'brand',
			'name'     => 'Mosmatic',
			'aliases'  => array(),
			'wikidata' => null,
		),
		array(
			'id'       => 4,
			'type'     => 'place',
			'name'     => 'Uetze',
			'aliases'  => array(),
			'wikidata' => 'Q519647',
		),
		array(
			'id'       => 5,
			'type'     => 'place',
			'name'     => 'Region Hannover',
			'aliases'  => array(),
			'wikidata' => 'Q5963',
		),
	);

	public function test_nur_entitaeten_im_text_haupt_in_about(): void {
		$found = EntitySchema::select( 'Die Terrassen-Reinigung mit Mosmatic-Düsen in der Region Hannover.', self::ENTITIES, array( 1 ) );
		$this->assertSame(
			array(
				array(
					'@type' => 'Service',
					'name'  => 'Terrassenreinigung',
				),
			),
			$found['about']
		);
		$this->assertSame( array( 'Mosmatic', 'Region Hannover' ), array_column( $found['mentions'], 'name' ), 'Reihenfolge im Text, Uetze fehlt, Region nur einmal' );
		$this->assertSame( 'https://www.wikidata.org/wiki/Q5963', $found['mentions'][1]['sameAs'] );
	}

	public function test_ganzes_wort(): void {
		$this->assertNull( EntitySchema::position( 'Uetzer Straße', array( 'Uetze' ) ) );
		$this->assertSame( 4, EntitySchema::position( 'Aus Uetze.', array( 'Uetze' ) ) );
		$this->assertNull( EntitySchema::position( 'ab', array( 'ab' ) ), 'zu kurz' );
	}

	public function test_vorhandenes_bleibt(): void {
		$node = EntitySchema::article(
			array(
				'@type' => 'BlogPosting',
				'about' => array( 'name' => 'von Hand' ),
			),
			array( array( 'name' => 'neu' ) ),
			array( array( 'name' => 'm' ) )
		);
		$this->assertSame( array( 'name' => 'von Hand' ), $node['about'] );
		$this->assertSame( array( array( 'name' => 'm' ) ), $node['mentions'] );
	}

	public function test_organisation_nicht_bei_person(): void {
		$services = array(
			array(
				'@type' => 'Thing',
				'name'  => 'Terrassenreinigung',
			),
		);
		$area     = array(
			array(
				'@type' => 'Place',
				'name'  => 'Region Hannover',
			),
		);
		$org      = EntitySchema::organization( array( '@type' => 'Organization' ), $services, $area );
		$this->assertSame( $services, $org['knowsAbout'] );
		$this->assertSame( $area[0], $org['areaServed'] );
		$this->assertArrayNotHasKey( 'knowsAbout', EntitySchema::organization( array( '@type' => array( 'Person', 'Organization' ) ), $services, $area ) );
		$this->assertSame(
			'alt',
			EntitySchema::organization(
				array(
					'@type'      => 'LocalBusiness',
					'areaServed' => 'alt',
				),
				$services,
				$area
			)['areaServed']
		);
	}
}
