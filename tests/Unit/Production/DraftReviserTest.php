<?php
/**
 * Automatische Nachbesserung (0.7.13).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Production;

use Lsec\Production\DraftReviser;
use PHPUnit\Framework\TestCase;

/**
 * Ersetzt nur gefundene Stellen, behält Links, entfernt leere Absätze und Listenpunkte.
 */
final class DraftReviserTest extends TestCase {

	/**
	 * Knoten.
	 *
	 * @param string   $type  Typ.
	 * @param string   $text  Text.
	 * @param string[] $items Listenpunkte.
	 * @return array<string, mixed>
	 */
	private static function node( string $type, string $text, array $items = array() ): array {
		return array(
			'type'    => $type,
			'level'   => 0,
			'text'    => $text,
			'ordered' => false,
			'items'   => $items,
			'head'    => array(),
			'rows'    => array(),
			'cite'    => '',
			'summary' => '',
		);
	}

	/**
	 * Entwurf.
	 *
	 * @return array<string, mixed>
	 */
	private static function draft(): array {
		return array(
			'title'                  => 'Pflaster in Lehrte',
			'excerpt'                => 'Kurz.',
			'content'                => array(
				self::node( 'paragraph', 'Viele Höfe haben altes <strong>Pflaster</strong>. In Lehrte sind das häufig Einfahrten aus Beton.' ),
				self::node( 'paragraph', 'Mehr dazu im <a href="https://example.de/ratgeber">Ratgeber</a>. Wir reinigen seit 25 Jahren.' ),
				self::node( 'list', '', array( 'Moos entfernen', 'Alles aus einer Hand' ) ),
				self::node( 'paragraph', 'Wir sind seit Jahren der beste Betrieb.' ),
			),
			'unsupported_statements' => array( 'Wir sind seit Jahren der beste Betrieb.' ),
		);
	}

	public function test_fixable_only_with_fixable_codes(): void {
		$this->assertTrue( DraftReviser::fixable( array( array( 'code' => 'AI_UNSUPPORTED_FACT' ), array( 'code' => 'UNSUPPORTED_NUMBER' ) ) ) );
		$this->assertFalse( DraftReviser::fixable( array( array( 'code' => 'AI_UNSUPPORTED_FACT' ), array( 'code' => 'LOCAL_FACTS' ) ) ) );
		$this->assertFalse( DraftReviser::fixable( array() ) );
	}

	public function test_apply_replaces_deletes_and_keeps_links(): void {
		$out     = DraftReviser::apply(
			self::draft(),
			array(
				array(
					'find'    => 'In Lehrte sind das häufig Einfahrten aus Beton.',
					'replace' => 'Oft sind es Einfahrten aus Beton.',
				),
				array(
					'find'    => 'Wir reinigen seit 25 Jahren.',
					'replace' => '',
				),
				array(
					'find'    => 'Alles aus einer Hand',
					'replace' => '',
				),
				array(
					'find'    => 'Wir sind seit Jahren der beste Betrieb.',
					'replace' => '',
				),
				array(
					'find'    => 'Dieser Satz steht nirgends im Text.',
					'replace' => 'x',
				),
			)
		);
		$content = $out['draft']['content'];
		$this->assertSame( 'Viele Höfe haben altes <strong>Pflaster</strong>. Oft sind es Einfahrten aus Beton.', $content[0]['text'] );
		$this->assertSame( 'Mehr dazu im <a href="https://example.de/ratgeber">Ratgeber</a>.', $content[1]['text'] );
		$this->assertSame( array( 'Moos entfernen' ), $content[2]['items'] );
		$this->assertCount( 3, $content, 'leerer Absatz entfernt' );
		$this->assertSame( array(), $out['draft']['unsupported_statements'] );
		$this->assertCount( 4, $out['applied'] );
		$this->assertSame( array( 'Dieser Satz steht nirgends im Text.' ), $out['missed'] );
	}

	public function test_match_without_html_only_in_fields_without_links(): void {
		$out = DraftReviser::apply(
			self::draft(),
			array(
				array(
					'find'    => 'Viele Höfe haben altes Pflaster.',
					'replace' => 'Viele Höfe haben Pflaster.',
				),
				array(
					'find'    => 'Mehr dazu im Ratgeber.',
					'replace' => '',
				),
			)
		);
		$this->assertStringStartsWith( 'Viele Höfe haben Pflaster.', $out['draft']['content'][0]['text'] );
		$this->assertSame( array( 'Mehr dazu im Ratgeber.' ), $out['missed'], 'Feld mit Link wird ohne HTML nicht geändert' );
	}

	public function test_fields_list_texts(): void {
		$fields = DraftReviser::fields( self::draft() );
		$this->assertContains( 'Alles aus einer Hand', $fields );
		$this->assertContains( 'Kurz.', $fields );
	}
}
