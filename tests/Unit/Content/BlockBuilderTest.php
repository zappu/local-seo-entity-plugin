<?php
/**
 * Tests für das Block-Markup (M12).
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\Content;

use Lsec\Content\BlockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Markup wie die save()-Ausgabe der Core-Blöcke. Im Editor geprüft mit WordPress 6.4.5 und 7.1.3.
 */
final class BlockBuilderTest extends TestCase {

	public function test_list_class_depends_on_wordpress_version(): void {
		$node = array(
			array(
				'type'  => 'list',
				'items' => array( 'a' ),
			),
		);
		$this->assertStringContainsString( "<!-- wp:list -->\n<ul><!-- wp:list-item -->\n<li>a</li>\n<!-- /wp:list-item --></ul>", ( new BlockBuilder( '6.4.5' ) )->build( $node ) );
		$this->assertStringContainsString( '<ul class="wp-block-list">', ( new BlockBuilder( '6.6' ) )->build( $node ) );
		$this->assertStringContainsString( '<ul class="wp-block-list">', ( new BlockBuilder( '7.1.3' ) )->build( $node ) );
	}

	public function test_heading_levels(): void {
		$builder = new BlockBuilder( '7.1' );
		$this->assertSame(
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Ablauf</h2>\n<!-- /wp:heading -->",
			$builder->build(
				array(
					array(
						'type' => 'heading',
						'text' => 'Ablauf',
					),
				)
			)
		);
		$this->assertStringStartsWith(
			"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">",
			$builder->build(
				array(
					array(
						'type'  => 'heading',
						'level' => 3,
						'text'  => 'Kosten',
					),
				)
			)
		);
	}

	public function test_ordered_list_table_and_details(): void {
		$html = ( new BlockBuilder( '7.1' ) )->build(
			array(
				array(
					'type'    => 'list',
					'ordered' => true,
					'items'   => array( 'eins', '' ),
				),
				array(
					'type' => 'table',
					'head' => array( 'A', 'B' ),
					'rows' => array( array( '1', '2' ) ),
				),
				array(
					'type'    => 'details',
					'summary' => 'Frage?',
					'text'    => 'Antwort.',
				),
			)
		);
		$this->assertStringContainsString( "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">", $html );
		$this->assertSame( 1, substr_count( $html, '<li>' ) );
		$this->assertStringContainsString( '<!-- wp:table {"hasFixedLayout":false} -->', $html );
		$this->assertStringContainsString( '<thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody>', $html );
		$this->assertStringContainsString( '<details class="wp-block-details"><summary>Frage?</summary><!-- wp:paragraph -->', $html );
	}

	public function test_inline_text_is_cleaned(): void {
		$html = ( new BlockBuilder( '7.1' ) )->build(
			array(
				array(
					'type' => 'paragraph',
					'text' => 'Gut <strong>sauber</strong><script>alert(1)</script> <!-- wp:html --> <div>x</div>',
				),
				array(
					'type' => 'paragraph',
					'text' => '   ',
				),
			)
		);
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<div', $html );
		$this->assertSame( 2, substr_count( $html, '<!-- ' ) );
		$this->assertStringContainsString( '<strong>sauber</strong>', $html );
	}

	public function test_unknown_type_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new BlockBuilder( '7.1' ) )->build( array( array( 'type' => 'html' ) ) );
	}
}
