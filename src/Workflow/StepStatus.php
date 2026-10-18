<?php
/**
 * Status eines Schritts.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Statuswerte eines Schritts.
 */
enum StepStatus: string {
	case Pending = 'pending';
	case Running = 'running';
	case Done    = 'done';
	case Failed  = 'failed';
	case Skipped = 'skipped';

	/**
	 * Abgeschlossene Schritte werden bei der Wiederaufnahme übersprungen.
	 */
	public function is_complete(): bool {
		return self::Done === $this || self::Skipped === $this;
	}
}
