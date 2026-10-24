<?php
/**
 * Daten für das Dashboard.
 *
 * @package Lsec
 */

namespace Lsec\Admin;

use Lsec\Cli\GscCommand;
use Lsec\Cli\LsecCommand;
use Lsec\Content\ContentIndex;
use Lsec\Content\ContentIndexWorkflow;
use Lsec\Entities\EntityExtractionWorkflow;
use Lsec\Content\DraftWriter;
use Lsec\Entities\BusinessProfile;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiClientFactory;
use Lsec\Integrations\Textfocus\AccountSnapshot;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Textfocus\TextfocusService;
use Lsec\Workflow\RunStatus;

/**
 * Stellt Status, Kosten, Credits, Cronjob und Einrichtungshinweise zusammen (Spezifikation 9.3).
 * Enthält nie Schlüssel, nur deren Quelle.
 */
final class DashboardData {

	/**
	 * Alle Angaben für GET lsec/v1/dashboard.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array<string, mixed>
	 */
	public static function collect( Plugin $plugin ): array {
		$status   = LsecCommand::collect_status( $plugin );
		$budget   = AiClientFactory::budget();
		$calls    = new AiCallRepository();
		$provider = (string) Settings::get( 'ai_provider' );

		$recent = array();
		foreach ( $plugin->runs->recent( 10 ) as $run ) {
			$recent[] = array(
				'id'            => (int) $run['id'],
				'type'          => (string) $run['type'],
				'status'        => (string) $run['status'],
				'step'          => (string) $run['current_step'],
				'error_code'    => (string) $run['error_code'],
				'error_message' => $plugin->logger->redact( (string) $run['error_message'] ),
				'updated_at'    => self::iso( (string) $run['updated_at'] ),
				'cost'          => round( $calls->cost_of_run( (int) $run['id'] ), 4 ),
			);
		}

		$tf_calls = new ProviderCallRepository();
		$gsc_file = GscCommand::credentials_file();

		$data = array(
			'plugin'       => array(
				'version'   => LSEC_VERSION,
				'wp'        => $status['wp_version'],
				'php'       => $status['php_version'],
				'schema_ok' => $status['schema_version_installed'] === $status['schema_version_code'] && array() === $status['missing_tables'],
			),
			'cron'         => array(
				'ok'       => (bool) $status['cron_ok'],
				'last_run' => null === $status['last_cli_queue_run_utc'] ? null : self::iso( (string) $status['last_cli_queue_run_utc'] ),
				'age_min'  => $status['cli_queue_run_age_min'],
				'script'   => \Lsec\Setup\SetupWizard::cron_paths()['phar'],
				'args'     => '--path=' . untrailingslashit( ABSPATH ) . ' lsec cron',
			),
			'runs'         => array(
				'by_status' => $status['runs_by_status'],
				'recent'    => $recent,
			),
			'ai'           => array(
				'provider'       => $provider,
				'model'          => (string) Settings::get( 'ai_model' ),
				'research_model' => (string) Settings::get( 'ai_research_model' ),
				'web_results'    => (int) Settings::get( 'ai_web_max_results' ),
				'key_source'     => $status['secrets'][ $provider . '_api_key' ] ?? 'none',
				'budget'         => $budget->status() + array( 'month_start' => self::iso( $budget->month_start ) ),
				'last_30_days'   => AiCallRepository::totals( 30 ),
			),
			'textfocus'    => array(
				'key_source'   => $status['secrets']['textfocus_api_key'] ?? 'none',
				'lang'         => (string) Settings::get( 'textfocus_lang' ),
				'daily_budget' => (int) Settings::get( 'textfocus_daily_budget' ),
				'used_today'   => $tf_calls->credits_today( TextfocusService::PROVIDER ),
				'account'      => self::account(),
			),
			'gsc'          => array(
				'credentials' => is_readable( $gsc_file ),
				'property'    => (string) Settings::get( 'gsc_property' ),
				'last_import' => self::last_gsc_import(),
			),
			'integrations' => array(
				'rank_math'        => DraftWriter::rank_math_active(),
				'rank_math_editor' => self::rank_math_editor_ready(),
				'elementor'        => (bool) $status['elementor_active'],
			),
			'content'      => array(
				'index'        => ContentIndex::stats(),
				'auto_extract' => (bool) Settings::get( 'entity_auto_extract' ),
				'due'          => ContentIndex::extraction_due(),
				'open_runs'    => $plugin->runs->count_open( array( ContentIndexWorkflow::TYPE, EntityExtractionWorkflow::TYPE ) ),
				'entities'     => self::entity_counts(),
				'profile'      => null !== ( new BusinessProfile() )->organization_row(),
			),
		);

		$data['hints'] = self::hints( $data, $status );
		return $data;
	}

	/**
	 * Einrichtungshinweise mit einem Knopf oder einem Link (seit 0.11.0 ohne Befehle für die Shell; nur
	 * Einträge für wp-config.php und die Felder der geplanten Aufgabe in Plesk stehen als Text zum Kopieren da).
	 *
	 * @param array<string, mixed> $data   Gesammelte Daten.
	 * @param array<string, mixed> $status Systemstatus.
	 * @return list<array{level: string, text: string, command: string, link: string, link_label: string, action: array{tool: string, label: string, kind: string}|null}>
	 */
	private static function hints( array $data, array $status ): array {
		$hints    = array();
		$add      = static function ( string $level, string $text, string $command = '', string $link = '', string $link_label = '', ?array $action = null ) use ( &$hints ): void {
			$hints[] = array(
				'level'      => $level,
				'text'       => $text,
				'command'    => $command,
				'link'       => $link,
				'link_label' => '' !== $link_label ? $link_label : 'Öffnen',
				'action'     => $action,
			);
		};
		$settings = admin_url( 'admin.php?page=' . AppPage::SETTINGS_SLUG );
		$setup    = admin_url( 'admin.php?page=' . AppPage::SETUP_SLUG );

		if ( ! $data['plugin']['schema_ok'] ) {
			$add( 'error', 'Die Datenbank ist nicht auf dem Stand des Plugins.', '', '', '', self::action( 'migrate', 'Datenbank aktualisieren' ) );
		}
		if ( ! $data['cron']['ok'] ) {
			$add( 'warning', 'Der Cronjob des Plugins ist seit über 15 Minuten nicht gelaufen oder noch nicht eingerichtet. Wie er in Plesk angelegt wird, steht auf der Seite „Einrichtung“ unter „Technik“.', '', $setup, 'Zur Einrichtung' );
		}
		if ( '' === $data['ai']['model'] ) {
			$add( 'warning', 'Kein KI-Modell eingestellt. Am einfachsten im Assistenten die empfohlenen Werte übernehmen.', '', $setup, 'Zur Einrichtung' );
		}
		if ( 'none' === $data['ai']['key_source'] ) {
			$add( 'warning', 'Kein API-Schlüssel für ' . $data['ai']['provider'] . '. Diese Zeile in wp-config.php eintragen (in Plesk im Dateimanager):', "define( 'LSEC_" . strtoupper( $data['ai']['provider'] ) . "_API_KEY', '…' );" );
		}
		if ( 'month' === $data['ai']['budget']['exceeded'] ) {
			$add( 'warning', 'Das KI-Monatsbudget ist erreicht. Abläufe warten bis zum nächsten Monat oder bis das Budget erhöht wird (Einstellungen → KI).', '', $settings, 'Einstellungen' );
		}
		if ( $data['ai']['budget']['unpriced_calls'] > 0 ) {
			$add( 'info', sprintf( '%d KI-Aufruf(e) in diesem Monat ohne Preis. Sie zählen nicht zum Budget, bis unter Einstellungen → KI bei „Preise“ ein Preis für das Modell hinterlegt ist.', $data['ai']['budget']['unpriced_calls'] ), '', $settings, 'Einstellungen' );
		}
		$waiting = (int) ( $status['runs_by_status'][ RunStatus::Waiting->value ] ?? 0 );
		if ( $waiting > 0 ) {
			$add( 'info', 1 === $waiting ? 'Ein Ablauf wartet (Budget oder Freigabe).' : sprintf( '%d Abläufe warten (Budget oder Freigabe).', $waiting ), '', admin_url( 'admin.php?page=' . AppPage::RUNS_SLUG ), 'Abläufe' );
		}
		if ( 'none' !== $data['textfocus']['key_source'] && '' === $data['textfocus']['lang'] ) {
			$add( 'info', 'Textfocus: Sprache noch nicht festgelegt.', '', '', '', self::action( 'textfocus-lang', 'Sprache der Website übernehmen' ) );
		}
		if ( ! $data['gsc']['credentials'] ) {
			$add( 'info', 'Search Console: Schlüsseldatei des Dienstkontos fehlt (Ordner private neben dem Webroot). Anleitung im Handbuch, Abschnitt 3.5.', '', AppPage::manual_url( '35-google-search-console-empfohlen' ), 'Handbuch' );
		} elseif ( '' === $data['gsc']['property'] ) {
			$add( 'info', 'Search Console: Property noch nicht gewählt.', '', '', '', self::action( 'gsc-property', 'Property wählen', 'choose' ) );
		}
		$profile_url = admin_url( 'admin.php?page=' . AppPage::PROFILE_SLUG );
		if ( ! $data['content']['profile'] ) {
			$add( 'info', 'Das Unternehmensprofil fehlt. Es ist die Grundlage für Entitäten und Themen.', '', $profile_url );
		}
		if ( array() === Settings::get( 'service_area' ) ) {
			$add( 'info', 'Kein Servicegebiet hinterlegt. Ohne Servicegebiet bleiben gleichnamige Orte ungeklärt.', '', $profile_url );
		}
		if ( null === $data['content']['index']['last_indexed_at'] ) {
			$add( 'info', 'Der Inhaltsindex wurde noch nicht gestartet: auf der Karte „Inhalte und Entitäten“ unten „Index starten“ wählen.' );
		}
		if ( $data['integrations']['rank_math'] && ! $data['integrations']['rank_math_editor'] ) {
			$add( 'info', 'Rank Math zeigt seine Editor-Funktionen erst nach Verbindung oder übersprungener Registrierung im Einrichtungsassistenten.' );
		}
		return $hints;
	}

	/**
	 * Knopf zu einem Werkzeug (ToolsController).
	 *
	 * @param string $tool  Werkzeug.
	 * @param string $label Beschriftung.
	 * @param string $kind  run (ausführen) oder choose (Liste laden, wählen, speichern).
	 * @return array{tool: string, label: string, kind: string}
	 */
	public static function action( string $tool, string $label, string $kind = 'run' ): array {
		return array(
			'tool'  => $tool,
			'label' => $label,
			'kind'  => $kind,
		);
	}

	/**
	 * Entitäten je Status, ohne zusammengeführte.
	 *
	 * @return array<string, int>
	 */
	private static function entity_counts(): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entities' );
		$rows  = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE status <> 'merged' GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Letzter bekannter Stand des Textfocus-Kontos.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function account(): ?array {
		$snapshot = AccountSnapshot::get();
		if ( null === $snapshot ) {
			return null;
		}
		$snapshot['checked_at'] = gmdate( 'c', (int) $snapshot['checked_at'] );
		return $snapshot;
	}

	/**
	 * Letzter Abruf aus der Search Console.
	 *
	 * @return array{at: string, rows: int}|null
	 */
	public static function last_gsc_import(): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'imports' );
		$row   = $wpdb->get_row( "SELECT created_at, rows_imported FROM {$table} WHERE source = 'gsc' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'at'   => self::iso( (string) $row['created_at'] ),
			'rows' => (int) $row['rows_imported'],
		);
	}

	/**
	 * Sind die Editor-Funktionen von Rank Math verfügbar (verbunden oder Registrierung übersprungen)?
	 */
	private static function rank_math_editor_ready(): bool {
		if ( ! DraftWriter::rank_math_active() ) {
			return false;
		}
		if ( ( defined( 'RANK_MATH_REGISTRATION_SKIP' ) && RANK_MATH_REGISTRATION_SKIP ) || get_option( 'rank_math_registration_skip' ) ) {
			return true;
		}
		$check = array( 'RankMath\\Helper', 'is_site_connected' );
		return is_callable( $check ) && (bool) call_user_func( $check );
	}

	/**
	 * UTC-Zeit aus der Datenbank als ISO 8601.
	 *
	 * @param string $utc Y-m-d H:i:s.
	 */
	private static function iso( string $utc ): string {
		return '' === $utc ? '' : str_replace( ' ', 'T', $utc ) . 'Z';
	}
}
