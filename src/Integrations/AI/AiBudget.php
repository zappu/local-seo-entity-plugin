<?php
/**
 * KI-Budget des Plugins.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Workflow\WaitException;

/**
 * Zusätzliche Bremse neben dem Ausgabenlimit beim Anbieter (Spezifikation 8.1b):
 * ein Monatsbudget und ein Höchstbetrag je Ablauf, beide in US-Dollar. 0 heißt ohne Grenze.
 *
 * Abläufe gehen bei erreichtem Budget auf „waiting“. Aufrufe ohne Ablauf (Prüfbefehle)
 * laufen weiter; der Befehl zeigt eine Warnung.
 */
final class AiBudget {

	public const WAIT_CODE = 'ai_budget';

	/**
	 * Konstruktor.
	 *
	 * @param AiSpend $spend       Bisherige Kosten.
	 * @param float   $monthly     Monatsbudget in USD, 0 = ohne Grenze.
	 * @param float   $per_run     Höchstbetrag je Ablauf in USD, 0 = ohne Grenze.
	 * @param string  $month_start Beginn des laufenden Monats in UTC (Y-m-d H:i:s), nach Zeitzone der Website.
	 */
	public function __construct(
		private readonly AiSpend $spend,
		public readonly float $monthly,
		public readonly float $per_run,
		public readonly string $month_start
	) {}

	/**
	 * Beginn des Monats eines Zeitpunkts in einer Zeitzone, umgerechnet nach UTC.
	 *
	 * @param \DateTimeZone $zone Zeitzone der Website.
	 * @param int           $now  Zeitpunkt (Unix).
	 */
	public static function month_start( \DateTimeZone $zone, int $now ): string {
		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone )->modify( 'first day of this month' )->setTime( 0, 0 );
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Stand für Anzeige und Prüfung.
	 *
	 * @param int|null $run_id Ablauf oder null.
	 * @return array{month_spent: float, month_limit: float, run_spent: float|null, run_limit: float, unpriced_calls: int, exceeded: string|null}
	 */
	public function status( ?int $run_id = null ): array {
		$month    = $this->spend->cost_since( $this->month_start );
		$run      = null === $run_id ? null : $this->spend->cost_of_run( $run_id );
		$exceeded = null;
		if ( $this->monthly > 0 && $month >= $this->monthly ) {
			$exceeded = 'month';
		} elseif ( null !== $run && $this->per_run > 0 && $run >= $this->per_run ) {
			$exceeded = 'run';
		}
		return array(
			'month_spent'    => round( $month, 4 ),
			'month_limit'    => $this->monthly,
			'run_spent'      => null === $run ? null : round( $run, 4 ),
			'run_limit'      => $this->per_run,
			'unpriced_calls' => $this->spend->unpriced_since( $this->month_start ),
			'exceeded'       => $exceeded,
		);
	}

	/**
	 * Darf ein Ablauf weiter KI aufrufen?
	 *
	 * @param int $run_id Ablauf.
	 */
	public function allows( int $run_id ): bool {
		return null === $this->status( $run_id )['exceeded'];
	}

	/**
	 * Prüft vor einem Aufruf. Ohne Ablauf wird nie angehalten.
	 *
	 * @param int|null $run_id Ablauf.
	 * @throws WaitException Wenn ein Budget erreicht ist.
	 */
	public function assert_available( ?int $run_id ): void {
		if ( null === $run_id ) {
			return;
		}
		$status = $this->status( $run_id );
		if ( 'month' === $status['exceeded'] ) {
			$message = sprintf( 'KI-Monatsbudget erreicht: %s von %s USD. Der Ablauf läuft im nächsten Monat oder nach Erhöhung von ai_monthly_budget weiter.', self::usd( $status['month_spent'] ), self::usd( $this->monthly ) );
			throw new WaitException( self::WAIT_CODE, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 'run' === $status['exceeded'] ) {
			$message = sprintf( 'KI-Budget je Ablauf erreicht: %s von %s USD. Fortsetzen auf der Seite „Abläufe“, gegebenenfalls nach Erhöhung von „Budget je Ablauf“ (Einstellungen → KI und Kosten).', self::usd( (float) $status['run_spent'] ), self::usd( $this->per_run ) );
			throw new WaitException( self::WAIT_CODE, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Betrag für Meldungen.
	 *
	 * @param float $amount USD.
	 */
	public static function usd( float $amount ): string {
		return number_format( $amount, 2, ',', '.' );
	}
}
