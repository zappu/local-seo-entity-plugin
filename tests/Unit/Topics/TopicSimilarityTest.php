<?php
/**
 * Tests der Dublettenerkennung (Nachtrag 22.10., 0.7.1).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Topics;

use Lsec\Topics\TopicSimilarity;
use PHPUnit\Framework\TestCase;

/**
 * Fall aus der Testwebsite: Themen 39 und 43 aus zwei Recherchen.
 */
final class TopicSimilarityTest extends TestCase {

	private const SERVICES = array( 'Terrassenreinigung', 'Pflasterreinigung', 'Parkplatzreinigung', 'Industriebodenreinigung', 'Fassadenreinigung' );

	/**
	 * Thema.
	 *
	 * @param string $title  Titel.
	 * @param string $region Ort.
	 * @param string $format Format.
	 * @return array<string, mixed>
	 */
	private static function topic( string $title, string $region, string $format = 'local_landing' ): array {
		return array(
			'title'            => $title,
			'region'           => $region,
			'format'           => $format,
			'primary_keywords' => '',
		);
	}

	public function test_same_service_and_place_is_duplicate(): void {
		$this->assertTrue(
			TopicSimilarity::same(
				self::topic( 'Pflasterreinigung in Uetze: Gehwege und Pflasterflächen vom Profi reinigen lassen', 'Uetze' ),
				self::topic( 'Pflasterreinigung in Uetze: Firma in der Nähe für Einfahrt, Hof und Wege', 'Uetze' ),
				self::SERVICES
			)
		);
		$this->assertTrue( TopicSimilarity::same( self::topic( 'Terrassenreinigung in Uetze', 'Gemeinde Uetze' ), self::topic( 'Terrasse reinigen lassen: Terrassenreinigung vor Ort', 'Uetze' ), self::SERVICES ) );
	}

	public function test_other_place_service_or_kind_is_not_duplicate(): void {
		$this->assertFalse( TopicSimilarity::same( self::topic( 'Pflasterreinigung in Uetze', 'Uetze' ), self::topic( 'Pflasterreinigung in Lehrte', 'Lehrte' ), self::SERVICES ) );
		$this->assertFalse( TopicSimilarity::same( self::topic( 'Pflasterreinigung in Uetze', 'Uetze' ), self::topic( 'Terrassenreinigung in Uetze', 'Uetze' ), self::SERVICES ) );
		$this->assertFalse( TopicSimilarity::same( self::topic( 'Pflasterreinigung in Uetze', 'Uetze' ), self::topic( 'Pflasterreinigung in Uetze: was kostet sie?', 'Uetze', 'guide' ), self::SERVICES ) );
	}

	public function test_guides_without_place_need_similar_titles(): void {
		$this->assertFalse( TopicSimilarity::same( self::topic( 'Lackfarbe von Pflastersteinen entfernen', '', 'guide' ), self::topic( 'Moos aus Pflasterfugen entfernen', '', 'guide' ), self::SERVICES ) );
		$this->assertTrue( TopicSimilarity::same( self::topic( 'Lackfarbe von Pflastersteinen entfernen', '', 'guide' ), self::topic( 'Lackfarbe auf Pflastersteinen entfernen: so geht es', '', 'guide' ), self::SERVICES ) );
	}

	public function test_article_and_local_page_are_the_same_kind(): void {
		$this->assertTrue(
			TopicSimilarity::same(
				self::topic( 'Pflasterreinigung in Uetze: Firma in der Nähe für Einfahrt, Hof und Wege', 'Uetze', 'article' ),
				self::topic( 'Pflasterreinigung in Uetze: Gehwege und Pflasterflächen vom Profi reinigen lassen', 'Uetze' ),
				self::SERVICES
			)
		);
	}

	public function test_compound_service_names_from_the_real_profile(): void {
		$services = array( 'Terrassenreinigung', 'Gehweg- und Pflasterreinigung', 'Garagen- und Parkplatzreinigung', 'Industriebodenreinigung', 'Fassadenreinigung', 'Regelmäßige Reinigungspakete und Wartungsverträge', 'Reinigung von Wänden und Böden in öffentlichen Bereichen' );
		$a        = self::topic( 'Pflasterreinigung in Uetze: Firma in der Nähe für Einfahrt, Hof und Wege', 'Uetze', 'article' );
		$b        = self::topic( 'Pflasterreinigung in Uetze: Gehwege und Pflasterflächen vom Profi reinigen lassen', 'Uetze' );
		$this->assertTrue( TopicSimilarity::same( $a, $b, $services ) );
		$this->assertFalse( TopicSimilarity::same( $a, self::topic( 'Terrassenreinigung in Uetze: Terrasse professionell reinigen lassen', 'Uetze' ), $services ) );
		$this->assertFalse( TopicSimilarity::same( self::topic( 'Reinigung von Gewerbeflächen in Uetze', 'Uetze' ), self::topic( 'Reinigung von Eingangsbereichen in Uetze', 'Uetze' ), $services ) );
		$this->assertNotContains( 'reinigun', TopicSimilarity::vocabulary( $services ) );
	}
}
