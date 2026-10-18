<?php
/**
 * Tests des KI-Budgets.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Unit\AI;

use Lsec\Integrations\AI\AiBudget;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiService;
use Lsec\Integrations\AI\AiSpend;
use Lsec\Integrations\AI\PriceTable;
use Lsec\Tests\Support\MemoryAiCallLog;
use Lsec\Workflow\Selftest\SelftestAiClient;
use Lsec\Workflow\WaitException;
use PHPUnit\Framework\TestCase;

/**
 * Monatsbudget und Budget je Ablauf (Spezifikation 8.1b).
 */
final class AiBudgetTest extends TestCase {

	/**
	 * Kosten aus einem Protokoll im Speicher.
	 *
	 * @param MemoryAiCallLog $log Protokoll.
	 */
	private static function spend( MemoryAiCallLog $log, float $earlier = 0.0 ): AiSpend {
		return new class( $log, $earlier ) implements AiSpend {
			/**
			 * Konstruktor.
			 *
			 * @param MemoryAiCallLog $log     Protokoll.
			 * @param float           $earlier Kosten anderer Abläufe im Monat.
			 */
			public function __construct( private readonly MemoryAiCallLog $log, private readonly float $earlier ) {}

			/**
			 * {@inheritDoc}
			 *
			 * @param string $since_utc Zeitpunkt.
			 */
			public function cost_since( string $since_utc ): float {
				return $this->earlier + array_sum( array_map( static fn( array $e ): float => (float) $e['cost'], $this->log->entries ) );
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param int $run_id Ablauf.
			 */
			public function cost_of_run( int $run_id ): float {
				$sum = 0.0;
				foreach ( $this->log->entries as $entry ) {
					$sum += $run_id === $entry['run_id'] ? (float) $entry['cost'] : 0.0;
				}
				return $sum;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param string $since_utc Zeitpunkt.
			 */
			public function unpriced_since( string $since_utc ): int {
				return count( array_filter( $this->log->entries, static fn( array $e ): bool => null === $e['cost'] ) );
			}
		};
	}

	public function test_run_waits_when_its_budget_is_reached(): void {
		$log     = new MemoryAiCallLog();
		$service = new AiService( new SelftestAiClient( 0.4 ), $log, new PriceTable(), new AiBudget( self::spend( $log ), 20.0, 1.0, '2026-10-01 00:00:00' ) );

		$service->text( new AiRequest( 't', 's', 'u' ), 7 );
		$service->text( new AiRequest( 't', 's', 'u' ), 7 );
		$service->text( new AiRequest( 't', 's', 'u' ), 7 );
		try {
			$service->text( new AiRequest( 't', 's', 'u' ), 7 );
			$this->fail( 'Vierter Aufruf hätte warten müssen.' );
		} catch ( WaitException $e ) {
			$this->assertSame( AiBudget::WAIT_CODE, $e->wait_code );
			$this->assertStringContainsString( 'je Ablauf', $e->getMessage() );
		}
		$this->assertCount( 3, $log->entries, 'Der angehaltene Aufruf geht nicht an den Anbieter.' );
		// Ein anderer Ablauf ist davon nicht betroffen.
		$service->text( new AiRequest( 't', 's', 'u' ), 8 );
		$this->assertCount( 4, $log->entries );
	}

	public function test_monthly_budget_stops_every_run_but_not_manual_calls(): void {
		$log    = new MemoryAiCallLog();
		$budget = new AiBudget( self::spend( $log, 19.9 ), 20.0, 0.0, '2026-10-01 00:00:00' );
		$ai     = new AiService( new SelftestAiClient( 0.2 ), $log, new PriceTable(), $budget );

		$ai->text( new AiRequest( 't', 's', 'u' ), 1 );
		$this->assertSame( 'month', $budget->status()['exceeded'] );
		$this->expectException( WaitException::class );
		$this->expectExceptionMessage( 'Monatsbudget' );
		try {
			$ai->text( new AiRequest( 't', 's', 'u' ) );
		} catch ( WaitException $e ) {
			$this->fail( 'Prüfbefehle ohne Ablauf werden nicht angehalten.' );
		}
		$ai->text( new AiRequest( 't', 's', 'u' ), 2 );
	}

	public function test_zero_means_no_limit_and_raising_releases(): void {
		$log = new MemoryAiCallLog();
		$ai  = new AiService( new SelftestAiClient( 5.0 ), $log, new PriceTable() );
		$ai->text( new AiRequest( 't', 's', 'u' ), 3 );

		$this->assertTrue( ( new AiBudget( self::spend( $log ), 0.0, 0.0, '' ) )->allows( 3 ) );
		$this->assertFalse( ( new AiBudget( self::spend( $log ), 0.0, 1.0, '' ) )->allows( 3 ) );
		$this->assertTrue( ( new AiBudget( self::spend( $log ), 0.0, 10.0, '' ) )->allows( 3 ) );
	}

	public function test_month_starts_in_site_timezone(): void {
		// 1. November 2026, 00:30 in Berlin = 31. Oktober 23:30 UTC.
		$now = ( new \DateTimeImmutable( '2026-11-01 00:30:00', new \DateTimeZone( 'Europe/Berlin' ) ) )->getTimestamp();
		$this->assertSame( '2026-10-31 23:00:00', AiBudget::month_start( new \DateTimeZone( 'Europe/Berlin' ), $now ) );
		$this->assertSame( '2026-10-01 00:00:00', AiBudget::month_start( new \DateTimeZone( 'UTC' ), $now - 3600 ) );
	}
}
