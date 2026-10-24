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

foreach ( array( 'lsec_settings', 'lsec_last_heartbeat', 'lsec_last_cli_queue_run', 'lsec_selftest_counters', 'lsec_textfocus_account', 'lsec_index_last_run', 'lsec_index_checked', 'lsec_keyword_sync_last', 'lsec_gsc_last_90', 'lsec_entity_homes' ) as $lsec_option ) {
	delete_option( $lsec_option );
}
// Hochgeladene Importdateien (werden sonst nach einem Tag entfernt).
$lsec_uploads = wp_upload_dir();
$lsec_import  = trailingslashit( (string) $lsec_uploads['basedir'] ) . 'lsec-import';
if ( is_dir( $lsec_import ) ) {
	foreach ( (array) glob( $lsec_import . '/{*,.htaccess}', GLOB_BRACE ) as $lsec_file ) {
		if ( is_string( $lsec_file ) && is_file( $lsec_file ) ) {
			wp_delete_file( $lsec_file );
		}
	}
	rmdir( $lsec_import ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
// Inhaltsindex an Beiträgen und Kategorien; erzeugte Beiträge selbst bleiben erhalten.
foreach ( array( '_lsec_index', '_lsec_index_dirty', '_lsec_extracted_hash' ) as $lsec_meta ) {
	delete_post_meta_by_key( $lsec_meta );
	delete_metadata( 'term', 0, $lsec_meta, '', true );
}
foreach ( \Lsec\Infrastructure\Security\Secrets::NAMES as $lsec_secret ) {
	delete_option( 'lsec_secret_' . $lsec_secret );
}
