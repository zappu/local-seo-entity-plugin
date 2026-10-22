<?php
/**
 * Tests für die Kennungen des Inhaltsindex.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Content;

use Lsec\Content\IndexItem;
use PHPUnit\Framework\TestCase;

/**
 * Kennungen „post:12“ und „term:34“; reine Zahlen sind Beiträge (ältere Abläufe, --ids).
 */
final class IndexItemTest extends TestCase {

	/**
	 * Schreibweisen.
	 */
	public function test_parse_ref(): void {
		$this->assertSame( array( 'post', 12 ), IndexItem::parse_ref( 12 ) );
		$this->assertSame( array( 'post', 12 ), IndexItem::parse_ref( '12' ) );
		$this->assertSame( array( 'post', 12 ), IndexItem::parse_ref( 'post:12' ) );
		$this->assertSame( array( 'term', 34 ), IndexItem::parse_ref( 'term:34' ) );
		$this->assertSame( array( 'term', 34 ), IndexItem::parse_ref( ' t34 ' ) );
		$this->assertSame( array( 'post', 0 ), IndexItem::parse_ref( 'quatsch' ) );
	}

	/**
	 * Einheitliche Kennung.
	 */
	public function test_normalize_ref(): void {
		$this->assertSame( 'post:7', IndexItem::normalize_ref( 7 ) );
		$this->assertSame( 'term:9', IndexItem::normalize_ref( 't:9' ) );
	}
}
