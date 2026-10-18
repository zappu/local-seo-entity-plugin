<?php
/**
 * Tests der Suchmuster für die Geheimnis-Prüfung.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit;

use Lsec\Infrastructure\Security\SecretScan;
use PHPUnit\Framework\TestCase;

/**
 * Restprüfung M8.
 */
final class SecretScanTest extends TestCase {

	public function test_needles_include_a_middle_fragment(): void {
		$key     = 'sk-or-v1-0123456789abcdef0123456789abcdef';
		$needles = SecretScan::needles( $key );
		$this->assertSame( $key, $needles[0] );
		$this->assertSame( 16, strlen( $needles[1] ) );
		$this->assertStringContainsString( $needles[1], $key );
		$this->assertStringNotContainsString( 'sk-or', $needles[1], 'Ein Präfix wie sk-or- träfe auch maskierte Anzeigen.' );
	}

	public function test_short_values_are_not_searched(): void {
		$this->assertSame( array(), SecretScan::needles( 'abc' ) );
		$this->assertSame( array( 'abcdefghij' ), SecretScan::needles( 'abcdefghij' ) );
	}

	public function test_pem_needles_are_body_lines(): void {
		$pem     = "-----BEGIN PRIVATE KEY-----\nAAAA1111\nBBBB2222\nCCCC3333\nDDDD4444\nEEEE5555\n-----END PRIVATE KEY-----\n";
		$needles = SecretScan::pem_needles( $pem );
		$this->assertSame( array( 'BBBB2222', 'CCCC3333' ), $needles );
	}

	public function test_file_scan_counts_lines(): void {
		$file = tempnam( sys_get_temp_dir(), 'lsec' );
		file_put_contents( $file, "ok\n[lsec] key=geheim1234567890\nok\ngeheim1234567890 again\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( 2, SecretScan::scan_file( (string) $file, array( 'geheim1234567890' ) ) );
		$this->assertNull( SecretScan::scan_file( '/nicht/da', array( 'x' ) ) );
		unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
