<?php
/**
 * Schnittstelle zur Warteschlange.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Queue;

/**
 * Stößt die Bearbeitung eines Ablaufs an. Die fachliche Steuerung liegt im Plugin;
 * die Warteschlange ist nur der Auslöser und kann ausgetauscht werden.
 */
interface Queue {

	/**
	 * Bearbeitung so bald wie möglich anstoßen. Doppelte Aufträge werden ignoriert.
	 *
	 * @param int $run_id Ablauf-ID.
	 */
	public function enqueue( int $run_id ): void;

	/**
	 * Bearbeitung nach einer Wartezeit anstoßen.
	 *
	 * @param int $run_id  Ablauf-ID.
	 * @param int $seconds Wartezeit.
	 */
	public function enqueue_in( int $run_id, int $seconds ): void;

	/**
	 * Liegt für den Ablauf schon ein offener Auftrag vor?
	 *
	 * @param int $run_id Ablauf-ID.
	 */
	public function has_pending( int $run_id ): bool;
}
