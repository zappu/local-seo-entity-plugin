<?php
/**
 * Tests: passt eine bestehende Seite zum Thema, und Quellen aus dem Recherchetext (Nachtrag 22.10.).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Topics;

use Lsec\Topics\TopicResearcher;
use Lsec\Topics\TopicResearchWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * Im Echtbetrieb wurde „Pflasterreinigung in Lehrte“ verworfen, weil die allgemeine Seite
 * /pflasterreinigung/ für „pflasterreinigung“ rankt.
 */
final class TopicFitTest extends TestCase {

	public function test_general_page_does_not_fit_local_topic(): void {
		$this->assertFalse( TopicResearchWorkflow::fits( 'Pflasterreinigung /pflasterreinigung', 'Lehrte', 'pflasterreinigung' ) );
	}

	public function test_local_page_fits_local_topic(): void {
		$this->assertTrue( TopicResearchWorkflow::fits( 'Pflasterreinigung in Lehrte /pflasterreinigung-lehrte', 'Lehrte', 'pflasterreinigung lehrte' ) );
		$this->assertTrue( TopicResearchWorkflow::fits( ' /leistungen/pflasterreinigung-lehrte/', 'Lehrte', 'pflasterreinigung' ) );
	}

	public function test_page_must_name_the_service(): void {
		$this->assertFalse( TopicResearchWorkflow::fits( 'Kontakt Lehrte /kontakt-lehrte', 'Lehrte', 'pflasterreinigung lehrte' ) );
		$this->assertTrue( TopicResearchWorkflow::fits( 'Pflastersteine reinigen', '', 'pflastersteinreinigung' ) );
	}

	public function test_generic_area_words_are_ignored(): void {
		$this->assertTrue( TopicResearchWorkflow::fits( 'Pflasterreinigung Hannover', 'Region Hannover', 'pflasterreinigung hannover' ) );
	}

	public function test_sources_from_text(): void {
		$text    = "Laut [Gemeinde Uetze](https://www.uetze.de/ortsteile/eltze) wurde Eltze 1226 erwähnt.\nSiehe auch https://de.wikipedia.org/wiki/Eltze. Und nochmal https://uetze.de/ortsteile/eltze/";
		$sources = TopicResearcher::sources_from_text( $text );
		$this->assertSame(
			array(
				array(
					'url'   => 'https://www.uetze.de/ortsteile/eltze',
					'title' => 'Gemeinde Uetze',
				),
				array(
					'url'   => 'https://de.wikipedia.org/wiki/Eltze',
					'title' => '',
				),
			),
			$sources
		);
	}
}
