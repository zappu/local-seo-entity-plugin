<?php
/**
 * WP-CLI: Grundbefehle.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Infrastructure\Database\Migrator;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Infrastructure\Queue\ActionSchedulerQueue;
use Lsec\Infrastructure\Security\Secrets;
use Lsec\Integrations\AI\AiBudget;
use Lsec\Integrations\AI\AiClientFactory;
use WP_CLI;

/**
 * Verwaltet das Plugin Local SEO & Entity Content.
 */
final class LsecCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Zeigt den Systemstatus.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Ausgabeformat.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec status
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = self::collect_status( $this->plugin );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$rows = array();
		foreach ( $status as $key => $value ) {
			$rows[] = array(
				'Punkt' => $key,
				'Wert'  => is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'Punkt', 'Wert' ) );
	}

	/**
	 * Einstieg für den Server-Cronjob: Watchdog, dann die Warteschlange der Gruppe lsec.
	 *
	 * Solange Action Scheduler seine einmalige Datenmigration (alte Beitrags-Tabellen →
	 * eigene Tabellen) nicht abgeschlossen hat, schlagen auf Gruppen gefilterte Läufe fehl.
	 * Dann wird zuerst die Migration ausgeführt und die Warteschlange einmal ungefiltert
	 * abgearbeitet. Ab dem nächsten Lauf gilt wieder der Filter auf die Gruppe lsec.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Aufträge je Durchgang.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--batches=<n>]
	 * : Anzahl Durchgänge.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--stale-after=<seconds>]
	 * : Aufträge eines abgestürzten Durchgangs gelten nach dieser Zeit als verwaist und
	 * werden freigegeben. Ohne diese Freigabe blockiert ein abgestürzter Durchgang die
	 * Warteschlange, bis Action Scheduler selbst aufräumt.
	 * ---
	 * default: 600
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # In Plesk unter "Geplante Aufgaben" alle 5 Minuten:
	 *     wp --path=/var/www/vhosts/example.de/httpdocs lsec cron
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function cron( array $args, array $assoc_args ): void {
		$batch_size = max( 1, (int) ( $assoc_args['batch-size'] ?? 3 ) );
		$batches    = max( 1, (int) ( $assoc_args['batches'] ?? 1 ) );

		$stale_after = max( 1, (int) ( $assoc_args['stale-after'] ?? 600 ) );

		$this->plugin->mark_cli_queue_run();

		// Verwaiste Aufträge eines abgestürzten Durchgangs freigeben bzw. als fehlgeschlagen markieren.
		$cleaner = new \ActionScheduler_QueueCleaner( \ActionScheduler::store() );
		$cleaner->reset_timeouts( $stale_after );
		$cleaner->mark_failures( $stale_after );

		$resumed = $this->plugin->watchdog->run( min( 60, $stale_after ) );
		if ( array() !== $resumed ) {
			WP_CLI::log( 'Wieder eingeplant: ' . implode( ', ', $resumed ) );
		}

		// Abläufe, die auf das KI-Budget warten, laufen weiter, sobald es wieder reicht (neuer Monat, höhere Grenze).
		$budget   = AiClientFactory::budget();
		$released = $this->plugin->engine->resume_waiting( AiBudget::WAIT_CODE, static fn( int $run_id ): bool => $budget->allows( $run_id ) );
		if ( array() !== $released ) {
			WP_CLI::log( 'Budget reicht wieder, fortgesetzt: ' . implode( ', ', $released ) );
		}

		$group = ' --group=' . ActionSchedulerQueue::GROUP;
		if ( ! \ActionScheduler_DataController::is_migration_complete() && class_exists( '\Action_Scheduler\Migration\Scheduler' ) ) {
			WP_CLI::log( 'Action Scheduler: Datenmigration wird abgeschlossen.' );
			( new \Action_Scheduler\Migration\Scheduler() )->run_migration();
			$group = '';
		}

		WP_CLI::runcommand(
			sprintf( 'action-scheduler run%s --batch-size=%d --batches=%d', $group, $batch_size, $batches ),
			array(
				'launch'     => false,
				'exit_error' => true,
			)
		);
	}

	/**
	 * Führt die Datenbankmigration aus.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Auch ausführen, wenn die Schemaversion aktuell ist.
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$ran     = Migrator::maybe_migrate( ! empty( $assoc_args['force'] ) );
		$missing = Migrator::missing_tables();
		if ( array() !== $missing ) {
			WP_CLI::error( 'Tabellen fehlen nach der Migration: ' . implode( ', ', $missing ) );
		}
		WP_CLI::success( $ran ? 'Migration ausgeführt, Schemaversion ' . Schema::VERSION . '.' : 'Schema ist aktuell (Version ' . Schema::VERSION . ').' );
	}

	/**
	 * Nimmt hängengebliebene Abläufe wieder auf.
	 *
	 * ## OPTIONS
	 *
	 * [--idle=<seconds>]
	 * : Mindestdauer ohne Änderung.
	 * ---
	 * default: 60
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function watchdog( array $args, array $assoc_args ): void {
		$resumed = $this->plugin->watchdog->run( max( 0, (int) ( $assoc_args['idle'] ?? 60 ) ) );
		WP_CLI::success( array() === $resumed ? 'Keine hängengebliebenen Abläufe.' : 'Wieder eingeplant: ' . implode( ', ', $resumed ) );
	}

	/**
	 * Sammelt den Status für CLI und Admin-Seite.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array<string, mixed>
	 */
	public static function collect_status( Plugin $plugin ): array {
		$last_cli       = (int) get_option( Plugin::OPT_LAST_CLI_RUN, 0 );
		$last_heartbeat = (int) get_option( Plugin::OPT_LAST_HEARTBEAT, 0 );
		$secrets        = array();
		foreach ( Secrets::NAMES as $name ) {
			$secrets[ $name ] = $plugin->secrets->source( $name );
		}

		return array(
			'plugin_version'           => LSEC_VERSION,
			'php_version'              => PHP_VERSION,
			'wp_version'               => get_bloginfo( 'version' ),
			'schema_version_installed' => (int) get_option( Schema::VERSION_OPTION, 0 ),
			'schema_version_code'      => Schema::VERSION,
			'missing_tables'           => Migrator::missing_tables(),
			'action_scheduler_version' => class_exists( '\ActionScheduler_Versions' ) ? \ActionScheduler_Versions::instance()->latest_version() : null,
			'as_migration_complete'    => class_exists( '\ActionScheduler_DataController' ) && \ActionScheduler_DataController::is_migration_complete(),
			'last_cli_queue_run_utc'   => $last_cli ? gmdate( 'Y-m-d H:i:s', $last_cli ) : null,
			'cli_queue_run_age_min'    => $last_cli ? (int) floor( ( time() - $last_cli ) / 60 ) : null,
			'cron_ok'                  => $last_cli > 0 && ( time() - $last_cli ) <= 15 * MINUTE_IN_SECONDS,
			'last_heartbeat_utc'       => $last_heartbeat ? gmdate( 'Y-m-d H:i:s', $last_heartbeat ) : null,
			'pending_actions'          => count(
				as_get_scheduled_actions(
					array(
						'group'    => ActionSchedulerQueue::GROUP,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => 1000,
					),
					'ids'
				)
			),
			'runs_by_status'           => $plugin->runs->count_by_status(),
			'secrets'                  => $secrets,
			'rank_math_active'         => \Lsec\Content\DraftWriter::rank_math_active(),
			'elementor_active'         => defined( 'ELEMENTOR_VERSION' ),
		);
	}
}
