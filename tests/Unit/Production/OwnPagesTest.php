<?php
/**
 * Eigene Seiten über ihren Text finden (0.8.2).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Production;

use Lsec\Production\ContentProductionWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * Suchwörter, Wortenden zusammengesetzter Wörter und Ausschnitt um die passende Stelle.
 */
final class OwnPagesTest extends TestCase {

	public function test_words_without_place_and_with_compound_endings(): void {
		$words = ContentProductionWorkflow::topic_words(
			array(
				'title'    => 'Zahnprophylaxe in Wees: Regelmäßige Kontrollen',
				'region'   => 'Wees',
				'keywords' => array(),
			)
		);
		$this->assertContains( array( 'zahnpro', 'ophylaxe' ), $words );
		$this->assertContains( array( 'kontrol', 'ntrollen' ), $words );
		$this->assertNotContains( array( 'wees' ), $words );
	}

	public function test_score_uses_endings_only_in_text(): void {
		$words = array( array( 'zahnpro', 'ophylaxe' ), array( 'profess' ) );
		$this->assertSame( 0, ContentProductionWorkflow::word_score( ' leistungen prophylaxe', $words, false ) );
		$this->assertSame( 2, ContentProductionWorkflow::word_score( ' unsere leistungen prophylaxe und professionelle zahnreinigung', $words, true ) );
	}

	public function test_excerpt_starts_near_first_match(): void {
		$body    = str_repeat( 'Implantologie und Zahnersatz. ', 200 ) . 'Prophylaxe: Wir beraten zu Zahnpflegeprodukten.';
		$excerpt = ContentProductionWorkflow::excerpt_around( $body, array( array( 'zahnpro', 'ophylaxe' ) ), 1000 );
		$this->assertStringContainsString( 'Prophylaxe: Wir beraten', $excerpt );
		$this->assertSame( 'kurz', ContentProductionWorkflow::excerpt_around( 'kurz', array(), 1000 ) );
	}
}
