<?php
/**
 * Aktivierung.
 *
 * @package Lsec
 */

namespace Lsec\Core;

use Lsec\Infrastructure\Database\Migrator;
use Lsec\Infrastructure\Security\Capabilities;

/**
 * Legt Tabellen, Capability und Standardeinstellungen an.
 */
final class Activator {

	public const SETTINGS_OPTION = 'lsec_settings';

	/**
	 * Wird von register_activation_hook aufgerufen.
	 */
	public static function activate(): void {
		Migrator::migrate();
		Capabilities::add();

		if ( false === get_option( self::SETTINGS_OPTION ) ) {
			add_option(
				self::SETTINGS_OPTION,
				array(
					'delete_data_on_uninstall' => false,
					'max_parallel_runs'        => 3,
				),
				'',
				false
			);
		}
	}
}
