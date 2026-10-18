<?php
/**
 * Tests für RetryPolicy.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit;

use Lsec\Workflow\RetryPolicy;
use Lsec\Workflow\StepResult;
use PHPUnit\Framework\TestCase;

/**
 * Wiederholungsregeln aus Spezifikation v0.3, Abschnitt 7.4.
 */
final class RetryPolicyTest extends TestCase {

	public function test_default_delays_are_1_5_30_minutes(): void {
		$policy = new RetryPolicy();
		$this->assertSame( 60, $policy->delay_after( 1 ) );
		$this->assertSame( 300, $policy->delay_after( 2 ) );
		$this->assertSame( 1800, $policy->delay_after( 3 ) );
		$this->assertSame( 1800, $policy->delay_after( 9 ), 'Nach der letzten Stufe bleibt die längste Wartezeit.' );
	}

	public function test_three_attempts_at_most(): void {
		$policy = new RetryPolicy();
		$this->assertSame( 3, $policy->max_attempts() );
		$this->assertTrue( $policy->can_retry( 1 ) );
		$this->assertTrue( $policy->can_retry( 2 ) );
		$this->assertFalse( $policy->can_retry( 3 ) );
	}

	/**
	 * @dataProvider http_status_provider
	 */
	public function test_http_status_classification( int $status, string $expected ): void {
		$this->assertSame( $expected, RetryPolicy::classify_http_status( $status ) );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function http_status_provider(): array {
		return array(
			'Netzwerkfehler'   => array( 0, StepResult::RETRY ),
			'Ratenlimit'       => array( 429, StepResult::RETRY ),
			'Serverfehler'     => array( 503, StepResult::RETRY ),
			'Ungültig'         => array( 400, StepResult::FAIL ),
			'Nicht berechtigt' => array( 401, StepResult::FAIL ),
			'Verboten'         => array( 403, StepResult::FAIL ),
		);
	}
}
