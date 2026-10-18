<?php
/**
 * Tests für Redactor.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit;

use Lsec\Infrastructure\Logging\Redactor;
use PHPUnit\Framework\TestCase;

/**
 * Geheimnisse dürfen nicht in Protokollen und Fehlermeldungen landen (Prüfpunkt M8).
 */
final class RedactorTest extends TestCase {

	public function test_registered_secret_is_masked(): void {
		$r = new Redactor();
		$r->add_secret( 'mein-geheimer-textfocus-key-123' );
		$this->assertSame( 'Fehler mit [GESCHWÄRZT]', $r->redact( 'Fehler mit mein-geheimer-textfocus-key-123' ) );
	}

	public function test_short_values_are_not_registered(): void {
		$r = new Redactor();
		$r->add_secret( 'abc' );
		$this->assertSame( 'abc def', $r->redact( 'abc def' ) );
	}

	/**
	 * @dataProvider pattern_provider
	 */
	public function test_typical_key_patterns_are_masked( string $input, string $must_not_contain ): void {
		$out = ( new Redactor() )->redact( $input );
		$this->assertStringNotContainsString( $must_not_contain, $out );
		$this->assertStringContainsString( Redactor::MASK, $out );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function pattern_provider(): array {
		return array(
			'Anthropic'   => array( 'key=sk-ant-api03-abcdefghijklmnop', 'abcdefghijklmnop' ),
			'OpenRouter'  => array( 'sk-or-v1-0123456789abcdef0123', '0123456789abcdef' ),
			'OpenAI'      => array( 'sk-proj-ABCDEFGHIJKLMNOPQRSTUVWX', 'ABCDEFGHIJKLMNOPQRSTUVWX' ),
			'Bearer'      => array( 'Authorization: Bearer abcdef1234567890xyz', 'abcdef1234567890xyz' ),
			'URL-Param'   => array( 'https://www.textfocus.net/apis/tf_keyword/?key=Zz9988776655&lang=de', 'Zz9988776655' ),
			'JSON-Feld'   => array( '{"api_key":"a1b2c3d4e5f6g7"}', 'a1b2c3d4e5f6g7' ),
			'GitHub'      => array( 'gh' . 'p_abcdefghijklmnopqrstuvwxyz0123', 'abcdefghijklmnopqrstuvwxyz0123' ),
		);
	}

	public function test_nested_arrays_are_redacted(): void {
		$r = new Redactor();
		$r->add_secret( 'geheim-geheim-geheim' );
		$out = $r->redact_array( array( 'a' => array( 'b' => 'x geheim-geheim-geheim y' ), 'n' => 5 ) );
		$this->assertSame( 'x [GESCHWÄRZT] y', $out['a']['b'] );
		$this->assertSame( 5, $out['n'] );
	}
}
