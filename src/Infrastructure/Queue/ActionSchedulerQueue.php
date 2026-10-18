<?php
/**
 * Warteschlange über Action Scheduler.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Queue;

/**
 * Jeder Auftrag ist eine Aktion "lsec_process_run" mit der Ablauf-ID als Argument.
 *
 * Hinweis: Das "unique"-Flag von Action Scheduler gilt pro Hook und Gruppe, nicht pro
 * Argument, und würde nur einen einzigen Ablauf gleichzeitig erlauben. Deshalb prüfen
 * wir offene Aufträge selbst. Ein doppelter Auftrag ist unschädlich: Die Ablaufsperre
 * und der gespeicherte Schrittstatus verhindern doppelte Arbeit.
 */
final class ActionSchedulerQueue implements Queue {

	public const HOOK  = 'lsec_process_run';
	public const GROUP = 'lsec';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $run_id Ablauf-ID.
	 */
	public function enqueue( int $run_id ): void {
		if ( $this->has_pending( $run_id ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, array( $run_id ), self::GROUP );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $run_id  Ablauf-ID.
	 * @param int $seconds Wartezeit.
	 */
	public function enqueue_in( int $run_id, int $seconds ): void {
		if ( $this->has_pending( $run_id ) ) {
			return;
		}
		as_schedule_single_action( time() + max( 0, $seconds ), self::HOOK, array( $run_id ), self::GROUP );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Zählt nur wartende Aufträge, nicht den gerade laufenden. Sonst könnte ein
	 * Schritt den nächsten nicht einplanen.
	 *
	 * @param int $run_id Ablauf-ID.
	 */
	public function has_pending( int $run_id ): bool {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => array( $run_id ),
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);
		return array() !== $ids;
	}
}
