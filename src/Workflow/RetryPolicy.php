<?php
/**
 * Wiederholungsregeln.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Entscheidet, ob und wann ein fehlgeschlagener Schritt wiederholt wird.
 *
 * Vorschlag aus Spezifikation v0.3, Abschnitt 7.4: höchstens 3 Versuche,
 * Wartezeiten 1, 5 und 30 Minuten.
 */
final class RetryPolicy {

	/**
	 * Konstruktor.
	 *
	 * @param int[] $delays       Wartezeiten in Sekunden nach dem 1., 2., 3. Fehlversuch.
	 * @param int   $max_attempts Höchstzahl der Versuche je Schritt.
	 */
	public function __construct(
		private readonly array $delays = array( 60, 300, 1800 ),
		private readonly int $max_attempts = 3
	) {}

	/**
	 * Höchstzahl der Versuche.
	 */
	public function max_attempts(): int {
		return $this->max_attempts;
	}

	/**
	 * Darf nach diesem Versuch noch einmal versucht werden?
	 *
	 * @param int $attempt Nummer des gerade gescheiterten Versuchs (ab 1).
	 */
	public function can_retry( int $attempt ): bool {
		return $attempt < $this->max_attempts;
	}

	/**
	 * Wartezeit bis zum nächsten Versuch.
	 *
	 * @param int $attempt Nummer des gerade gescheiterten Versuchs (ab 1).
	 */
	public function delay_after( int $attempt ): int {
		if ( array() === $this->delays ) {
			return 0;
		}
		$index = max( 0, min( $attempt - 1, count( $this->delays ) - 1 ) );
		return $this->delays[ $index ];
	}

	/**
	 * Ordnet einen HTTP-Status ein (für Adapter externer Dienste).
	 *
	 * @param int $status HTTP-Statuscode, 0 bei Netzwerkfehler oder Zeitüberschreitung.
	 * @return string StepResult::RETRY oder StepResult::FAIL.
	 */
	public static function classify_http_status( int $status ): string {
		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			return StepResult::RETRY;
		}
		return StepResult::FAIL;
	}
}
