<?php
/**
 * Plugin Name:       Local SEO & Entity Content
 * Description:       Recherchiert lokale SEO-Themen, erkennt Entitäten und erstellt daraus Beitragsentwürfe.
 * Version:           0.7.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ThoKa
 * Text Domain:       local-seo-entity-plugin
 * License:           Proprietär
 *
 * @package Lsec
 */

defined( 'ABSPATH' ) || exit;

define( 'LSEC_VERSION', '0.7.4' );
define( 'LSEC_FILE', __FILE__ );
define( 'LSEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LSEC_URL', plugin_dir_url( __FILE__ ) );
define( 'LSEC_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, LSEC_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'Local SEO & Entity Content benötigt PHP %1$s oder neuer. Aktuell: %2$s.', 'local-seo-entity-plugin' ),
						LSEC_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

$lsec_autoload         = LSEC_DIR . 'vendor/autoload.php';
$lsec_action_scheduler = LSEC_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

if ( ! file_exists( $lsec_autoload ) || ! file_exists( $lsec_action_scheduler ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Local SEO & Entity Content: Abhängigkeiten fehlen. Bitte die fertige Plugin-ZIP installieren oder im Plugin-Ordner "composer install --no-dev" ausführen.', 'local-seo-entity-plugin' )
			);
		}
	);
	return;
}

require_once $lsec_autoload;
// Action Scheduler registriert sich selbst; bei mehreren Kopien lädt er die neueste Version.
require_once $lsec_action_scheduler;

register_activation_hook( __FILE__, array( \Lsec\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Lsec\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\Lsec\Core\Plugin::instance()->boot();
	}
);
