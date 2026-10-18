<?php
/**
 * Status eines Ablaufs.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Statuswerte aus Spezifikation v0.3, Abschnitt 7.2.
 */
enum RunStatus: string {
	case Queued    = 'queued';
	case Running   = 'running';
	case Waiting   = 'waiting';
	case Blocked   = 'blocked';
	case Succeeded = 'succeeded';
	case Failed    = 'failed';
	case Cancelled = 'cancelled';

	/**
	 * Endzustände werden nicht mehr automatisch bearbeitet.
	 */
	public function is_terminal(): bool {
		return in_array( $this, array( self::Succeeded, self::Failed, self::Cancelled, self::Blocked ), true );
	}

	/**
	 * Zustände, die der Watchdog wieder anstoßen darf.
	 */
	public function is_resumable(): bool {
		return in_array( $this, array( self::Queued, self::Running ), true );
	}
}
