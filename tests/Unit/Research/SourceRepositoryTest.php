<?php
/**
 * Tests für die Vereinheitlichung von Quellen-URLs.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Research;

use Lsec\Research\SourceRepository;
use PHPUnit\Framework\TestCase;

/**
 * Gleiche Seite, eine URL.
 */
final class SourceRepositoryTest extends TestCase {

	public function test_tracking_parameters_are_removed(): void {
		$this->assertSame(
			'https://www.uetze.de/portal/seiten/unsere-gemeinde-917000361-21300.html',
			SourceRepository::canonical( 'https://www.uetze.de/portal/seiten/unsere-gemeinde-917000361-21300.html?utm_source=openai' )
		);
		$this->assertSame(
			'https://www.uetze.de/regional/touren/die-muehlentour-917000004-21300.html?titel=Die+M%C3%BChlentour',
			SourceRepository::canonical( 'https://www.uetze.de/regional/touren/die-muehlentour-917000004-21300.html?titel=Die+M%C3%BChlentour&utm_source=openai' )
		);
		$this->assertSame( 'https://example.org/a?b=1', SourceRepository::canonical( 'https://example.org/a?gclid=x&b=1#top' ) );
		$this->assertSame( 'https://example.org/', SourceRepository::canonical( 'https://example.org/' ) );
	}
}
