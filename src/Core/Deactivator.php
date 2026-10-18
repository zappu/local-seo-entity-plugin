<?php
/**
 * Deaktivierung.
 *
 * @package Lsec
 */

namespace Lsec\Core;

/**
 * Entfernt nur den Heartbeat. Daten, Tabellen und offene Abläufe bleiben erhalten.
 */
final class Deactivator {

	/**
	 * Wird von register_deactivation_hook aufgerufen.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Plugin::HEARTBEAT_HOOK );
		}
	}
}
