<?php
/**
 * Tests für die Verschlüsselung gespeicherter Schlüssel.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit;

use Lsec\Infrastructure\Security\Secrets;
use PHPUnit\Framework\TestCase;

/**
 * Verschlüsseln, Entschlüsseln, Maskieren.
 */
final class SecretsCryptoTest extends TestCase {

	public function test_roundtrip(): void {
		$key    = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$stored = Secrets::encrypt( 'sk-or-v1-testwert', $key );
		$this->assertStringStartsWith( 'v1:', $stored );
		$this->assertStringNotContainsString( 'testwert', $stored );
		$this->assertSame( 'sk-or-v1-testwert', Secrets::decrypt( $stored, $key ) );
	}

	public function test_wrong_key_or_garbage_returns_null(): void {
		$stored = Secrets::encrypt( 'geheim', random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
		$this->assertNull( Secrets::decrypt( $stored, random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$this->assertNull( Secrets::decrypt( 'kaputt', random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$this->assertNull( Secrets::decrypt( 'v1:!!!', random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
	}

	public function test_mask(): void {
		$this->assertSame( '', Secrets::mask( null ) );
		$this->assertSame( '••••••••', Secrets::mask( 'kurz' ) );
		$this->assertSame( 'sk-or-…cdef', Secrets::mask( 'sk-or-v1-0123456789abcdef' ) );
	}

	public function test_constant_names(): void {
		$this->assertSame( 'LSEC_TEXTFOCUS_API_KEY', Secrets::constant_name( 'textfocus_api_key' ) );
		$this->assertSame( 'LSEC_OPENROUTER_API_KEY', Secrets::constant_name( 'openrouter_api_key' ) );
	}
}
