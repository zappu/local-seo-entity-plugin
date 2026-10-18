<?php
/**
 * Nimmt hängengebliebene Abläufe wieder auf.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

use Lsec\Infrastructure\Logging\Logger;
use Lsec\Infrastructure\Queue\Queue;

/**
 * Sucht Abläufe ohne gültige Sperre und ohne offenen Auftrag und plant sie neu ein.
 * Läuft mit dem Heartbeat alle 5 Minuten und ist per WP-CLI aufrufbar.
 */
final class Watchdog {

	/**
	 * Konstruktor.
	 *
	 * @param RunRepository $runs   Datenzugriff.
	 * @param Queue         $queue  Warteschlange.
	 * @param Logger        $logger Protokoll.
	 */
	public function __construct(
		private readonly RunRepository $runs,
		private readonly Queue $queue,
		private readonly Logger $logger
	) {}

	/**
	 * Plant hängengebliebene Abläufe neu ein.
	 *
	 * @param int $idle_seconds Mindestdauer ohne Änderung, bevor ein Ablauf als hängend gilt.
	 * @return list<int> Neu eingeplante Ablauf-IDs.
	 */
	public function run( int $idle_seconds = 60 ): array {
		$resumed = array();
		foreach ( $this->runs->stale_run_ids( $idle_seconds ) as $run_id ) {
			if ( $this->queue->has_pending( $run_id ) ) {
				continue;
			}
			$this->queue->enqueue( $run_id );
			$resumed[] = $run_id;
		}
		if ( array() !== $resumed ) {
			$this->logger->warning( 'Abläufe wieder aufgenommen', array( 'run_ids' => $resumed ) );
		}
		return $resumed;
	}
}
