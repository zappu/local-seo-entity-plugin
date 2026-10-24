<?php
/**
 * Tests: Marken- und Navigationssuchen, Hinweise je Seite (0.10.5).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Report;

use Lsec\Report\ContentReport;
use Lsec\Report\QueryKind;
use PHPUnit\Framework\TestCase;

/**
 * Fälle aus dem Bericht der Zahnarzt-Website vom 24.10.
 */
final class QueryKindTest extends TestCase {

	public function test_brand_terms_from_names(): void {
		$terms = QueryKind::brand_terms( array( 'Zahnarztpraxis Dr. med. dent. Kai-Uwe Rust, MSc', 'Dr. Rust Zahnarztpraxis Wees' ), array( 'Wees', 'Professionelle Zahnreinigung' ) );
		$this->assertSame( array( 'kai', 'uwe', 'rust' ), $terms );
		$staats = QueryKind::brand_terms( array( 'Staats Flächenreinigung & Wassertechnik' ), array( 'Pflasterreinigung' ) );
		$this->assertSame( array( 'staats' ), $staats, 'lange Gattungswörter sind keine Marke' );
		$this->assertSame( array( 'rust', 'staats' ), QueryKind::parse_terms( ' Rust, staats ,,' ) );
	}

	public function test_classify(): void {
		$brand = array( 'kai', 'uwe', 'rust' );
		foreach ( array( 'dr rust', 'dr. rust', 'dr.rust', 'kai rust', 'zahnarzt rust', 'doctor rust' ) as $query ) {
			$this->assertSame( QueryKind::BRAND, QueryKind::classify( $query, $brand ), $query );
		}
		$this->assertSame( QueryKind::NAVIGATIONAL, QueryKind::classify( 'dr rust öffnungszeiten', $brand ) );
		$this->assertSame( QueryKind::NAVIGATIONAL, QueryKind::classify( 'zahnarzt wees telefonnummer', $brand ) );
		$this->assertSame( QueryKind::CONTENT, QueryKind::classify( 'cmd kribbeln gesicht', $brand ) );
		$this->assertSame( QueryKind::CONTENT, QueryKind::classify( 'trust zahnarzt', $brand ), 'nur ganze Wörter' );
		$this->assertSame( QueryKind::CONTENT, QueryKind::classify( 'sportzahnschiene', array() ) );
	}

	public function test_pages_group_by_page_and_kind(): void {
		$item  = static fn( string $url, string $keyword, float $pos, int $imp, string $kind ): array => array(
			'source'      => 'gsc',
			'page_url'    => $url,
			'post_id'     => 1,
			'title'       => 'T',
			'keyword'     => $keyword,
			'position'    => $pos,
			'impressions' => $imp,
			'clicks'      => 0,
			'reason'      => '',
			'topic_id'    => 0,
			'kind'        => $kind,
			'type'        => 'Seite',
			'elementor'   => true,
		);
		$pages = ContentReport::pages(
			array(
				$item( '/', 'dr rust', 8.3, 316, QueryKind::BRAND ),
				$item( '/', 'kai rust', 4.1, 21, QueryKind::BRAND ),
				$item( '/cmd/', 'cmd kribbeln gesicht', 10.8, 48, QueryKind::CONTENT ),
				$item( '/cmd/', 'kiefer kribbeln gesicht', 8.5, 13, QueryKind::CONTENT ),
			)
		);
		$this->assertCount( 2, $pages );
		$this->assertSame( '/cmd/', $pages[0]['page_url'], 'Inhalt zuerst' );
		$this->assertSame( 61, $pages[0]['impressions'] );
		$this->assertSame( 8.5, $pages[0]['position'] );
		$this->assertStringContainsString( 'Elementor-Editor', $pages[0]['advice'] );
		$this->assertSame( QueryKind::BRAND, $pages[1]['kind'] );
		$this->assertCount( 2, $pages[1]['queries'] );
		$this->assertStringContainsString( 'eigenen Namen', $pages[1]['advice'] );
		$this->assertStringNotContainsString( 'Elementor', $pages[1]['advice'] );
	}
}
