<?php
/**
 * Bisherige KI-Kosten.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Liefert die Summen, an denen das KI-Budget gemessen wird (Spezifikation 8.1b).
 */
interface AiSpend {

	/**
	 * Kosten aller Aufrufe seit einem Zeitpunkt.
	 *
	 * @param string $since_utc Zeitpunkt Y-m-d H:i:s in UTC.
	 */
	public function cost_since( string $since_utc ): float;

	/**
	 * Kosten aller Aufrufe eines Ablaufs.
	 *
	 * @param int $run_id Ablauf.
	 */
	public function cost_of_run( int $run_id ): float;

	/**
	 * Anzahl der Aufrufe seit einem Zeitpunkt, für die kein Preis bekannt war.
	 *
	 * @param string $since_utc Zeitpunkt Y-m-d H:i:s in UTC.
	 */
	public function unpriced_since( string $since_utc ): int;
}
