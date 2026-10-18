<?php
/**
 * Statusseite im Backend.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Cli\LsecCommand;
use Lsec\Core\Plugin;
use Lsec\Infrastructure\Security\Capabilities;

/**
 * Technische Statusseite unter „Local SEO“: Versionen, Tabellen, Cronjob, Warteschlange
 * und letzte Abläufe. Das Dashboard (AppPage) zeigt dasselbe aufbereitet.
 */
final class StatusPage {

	public const SLUG = 'lsec-status';

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Registriert Menü und Hinweise.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 40 );
		add_action( 'admin_notices', array( $this, 'cron_notice' ) );
	}

	/**
	 * Menüeintrag.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AppPage::SLUG,
			__( 'Local SEO – Technik', 'local-seo-entity-plugin' ),
			__( 'Technik', 'local-seo-entity-plugin' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Warnt, wenn der Server-Cronjob länger als 15 Minuten nicht gelaufen ist.
	 */
	public function cron_notice(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			return;
		}
		$last = (int) get_option( Plugin::OPT_LAST_CLI_RUN, 0 );
		if ( $last > 0 && ( time() - $last ) <= 15 * MINUTE_IN_SECONDS ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || false === strpos( (string) $screen->id, AppPage::SLUG ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p><p><code>%s</code></p></div>',
			esc_html__( 'Der Server-Cronjob für Local SEO ist seit über 15 Minuten nicht gelaufen oder noch nicht eingerichtet. Bitte in Plesk unter "Geplante Aufgaben" alle 5 Minuten ausführen:', 'local-seo-entity-plugin' ),
			esc_html( 'wp --path=' . untrailingslashit( ABSPATH ) . ' lsec cron' )
		);
	}

	/**
	 * Seiteninhalt.
	 */
	public function render(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'local-seo-entity-plugin' ), 403 );
		}

		$status = LsecCommand::collect_status( $this->plugin );
		$labels = array(
			'plugin_version'           => __( 'Plugin-Version', 'local-seo-entity-plugin' ),
			'php_version'              => __( 'PHP', 'local-seo-entity-plugin' ),
			'wp_version'               => __( 'WordPress', 'local-seo-entity-plugin' ),
			'schema_version_installed' => __( 'Schemaversion (installiert)', 'local-seo-entity-plugin' ),
			'schema_version_code'      => __( 'Schemaversion (Code)', 'local-seo-entity-plugin' ),
			'missing_tables'           => __( 'Fehlende Tabellen', 'local-seo-entity-plugin' ),
			'action_scheduler_version' => __( 'Action Scheduler', 'local-seo-entity-plugin' ),
			'as_migration_complete'    => __( 'Action-Scheduler-Migration abgeschlossen', 'local-seo-entity-plugin' ),
			'last_cli_queue_run_utc'   => __( 'Letzter Cronjob-Lauf (UTC)', 'local-seo-entity-plugin' ),
			'cli_queue_run_age_min'    => __( 'Minuten seit Cronjob', 'local-seo-entity-plugin' ),
			'cron_ok'                  => __( 'Cronjob in Ordnung', 'local-seo-entity-plugin' ),
			'last_heartbeat_utc'       => __( 'Letzter Heartbeat (UTC)', 'local-seo-entity-plugin' ),
			'pending_actions'          => __( 'Wartende Aufträge', 'local-seo-entity-plugin' ),
			'runs_by_status'           => __( 'Abläufe nach Status', 'local-seo-entity-plugin' ),
			'secrets'                  => __( 'API-Schlüssel (Quelle)', 'local-seo-entity-plugin' ),
			'rank_math_active'         => __( 'Rank Math aktiv', 'local-seo-entity-plugin' ),
			'elementor_active'         => __( 'Elementor aktiv', 'local-seo-entity-plugin' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Local SEO – Technik', 'local-seo-entity-plugin' ) . '</h1>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $status as $key => $value ) {
			if ( is_bool( $value ) ) {
				$display = $value ? __( 'ja', 'local-seo-entity-plugin' ) : __( 'nein', 'local-seo-entity-plugin' );
			} elseif ( is_array( $value ) ) {
				$display = array() === $value ? '–' : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			} else {
				$display = null === $value ? '–' : (string) $value;
			}
			printf( '<tr><th style="width:280px">%s</th><td>%s</td></tr>', esc_html( $labels[ $key ] ?? $key ), esc_html( $display ) );
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Letzte Abläufe', 'local-seo-entity-plugin' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( 'ID', 'Typ', 'Status', 'Schritt', 'Fehler', 'Aktualisiert (UTC)' ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$recent = $this->plugin->runs->recent( 20 );
		if ( array() === $recent ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Noch keine Abläufe.', 'local-seo-entity-plugin' ) . '</td></tr>';
		}
		foreach ( $recent as $run ) {
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				(int) $run['id'],
				esc_html( (string) $run['type'] ),
				esc_html( (string) $run['status'] ),
				esc_html( (string) $run['current_step'] ),
				esc_html( (string) $run['error_code'] ),
				esc_html( (string) $run['updated_at'] )
			);
		}
		echo '</tbody></table></div>';
	}
}
