<?php
/**
 * Tests für IdempotencyKey.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit;

use Lsec\Workflow\IdempotencyKey;
use PHPUnit\Framework\TestCase;

/**
 * Gleiche Merkmale ergeben denselben Schlüssel, andere Merkmale einen anderen.
 */
final class IdempotencyKeyTest extends TestCase {

	public function test_same_input_same_key(): void {
		$a = IdempotencyKey::for_run( 'content_production', 'topic', 42, array( 'brief_version' => 1 ) );
		$b = IdempotencyKey::for_run( 'content_production', 'topic', 42, array( 'brief_version' => 1 ) );
		$this->assertSame( $a, $b );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $a );
	}

	public function test_order_of_extra_keys_does_not_matter(): void {
		$a = IdempotencyKey::for_run( 'x', 'topic', 1, array( 'a' => 1, 'b' => 2 ) );
		$b = IdempotencyKey::for_run( 'x', 'topic', 1, array( 'b' => 2, 'a' => 1 ) );
		$this->assertSame( $a, $b );
	}

	public function test_different_subject_or_version_gives_different_key(): void {
		$base = IdempotencyKey::for_run( 'content_production', 'topic', 42, array( 'brief_version' => 1 ) );
		$this->assertNotSame( $base, IdempotencyKey::for_run( 'content_production', 'topic', 43, array( 'brief_version' => 1 ) ) );
		$this->assertNotSame( $base, IdempotencyKey::for_run( 'content_production', 'topic', 42, array( 'brief_version' => 2 ) ) );
		$this->assertNotSame( $base, IdempotencyKey::for_run( 'topic_research', 'topic', 42, array( 'brief_version' => 1 ) ) );
	}

	public function test_umlauts_are_stable(): void {
		$this->assertSame(
			IdempotencyKey::for_run( 'x', null, null, array( 'kw' => 'flächenreinigung' ) ),
			IdempotencyKey::for_run( 'x', null, null, array( 'kw' => 'flächenreinigung' ) )
		);
	}
}
