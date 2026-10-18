<?php
/**
 * Tests für das Auslesen von Elementor-Seiten (M14).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Content;

use Lsec\Content\ContentReader;
use Lsec\Content\ElementorReader;
use PHPUnit\Framework\TestCase;

/**
 * Text, Überschriften und Reihenfolge aus _elementor_data.
 */
final class ElementorReaderTest extends TestCase {

	public function test_reads_visible_text_in_order(): void {
		$data = ElementorReader::read( (string) file_get_contents( __DIR__ . '/../../fixtures/elementor/leistungsseite.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertNotNull( $data );
		$this->assertSame( 7, $data['widgets'] );
		$this->assertSame(
			array(
				array(
					'level' => 1,
					'text'  => 'Flächenreinigung in Burgdorf',
				),
				array(
					'level' => 2,
					'text'  => 'Häufige Fragen',
				),
			),
			$data['headings']
		);
		$text = $data['text'];
		$this->assertStringStartsWith( 'Flächenreinigung in Burgdorf', $text );
		$this->assertStringContainsString( 'Wir reinigen Pflaster, Terrassen und Einfahrten in der Region Hannover.', $text );
		$this->assertStringContainsString( 'Schonend mit Heißwasser & ohne Chemie.', $text );
		$this->assertStringContainsString( 'Wie lange dauert die Reinigung?', $text );
		$this->assertStringContainsString( 'Ab 4 € pro m².', $text );
		$this->assertStringContainsString( 'Angebot anfordern', $text );
		$this->assertStringContainsString( 'Entfernt Moos und Algen.', $text );
		$this->assertStringNotContainsString( 'tracking', $text );
		$this->assertStringNotContainsString( '#222222', $text );
		$this->assertStringNotContainsString( 'example.org', $text );
		$this->assertStringNotContainsString( 'fa-star', $text );
		$this->assertLessThan( strpos( $text, 'Häufige Fragen' ), strpos( $text, 'Heißwasser' ) );
	}

	public function test_invalid_json(): void {
		$this->assertNull( ElementorReader::read( 'kein json' ) );
	}

	public function test_word_count_with_umlauts(): void {
		$this->assertSame( 5, ContentReader::words( 'Flächenreinigung für Böden in Höver' ) );
	}
}
