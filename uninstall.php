<?php
/**
 * Deinstallation.
 *
 * Löscht Tabellen und Optionen nur, wenn der Administrator die Einstellung
 * "Daten bei Deinstallation entfernen" aktiviert hat. Erzeugte Beiträge bleiben immer.
 *
 * @package Lsec
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lsec_settings = get_option( 'lsec_settings', array() );

if ( ! is_array( $lsec_settings ) || empty( $lsec_settings['delete_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\Lsec\Infrastructure\Database\Migrator::drop_all();
\Lsec\Infrastructure\Security\Capabilities::remove();

foreach ( array( 'lsec_settings', 'lsec_last_heartbeat', 'lsec_last_cli_queue_run', 'lsec_selftest_counters' ) as $lsec_option ) {
	delete_option( $lsec_option );
}
foreach ( \Lsec\Infrastructure\Security\Secrets::NAMES as $lsec_secret ) {
	delete_option( 'lsec_secret_' . $lsec_secret );
}
