<?php
/**
 * Zentrale Plugin-Klasse.
 *
 * @package Lsec
 */

namespace Lsec\Core;

use Lsec\Admin\AppPage;
use Lsec\Admin\StatusPage;
use Lsec\Entities\EntityRepository;
use Lsec\Cli\AiCommand;
use Lsec\Cli\CheckCommand;
use Lsec\Cli\DraftCommand;
use Lsec\Cli\GscCommand;
use Lsec\Cli\RunsCommand;
use Lsec\Cli\IndexCommand;
use Lsec\Cli\ProfileCommand;
use Lsec\Cli\EntitiesCommand;
use Lsec\Cli\KeywordsCommand;
use Lsec\Cli\LsecCommand;
use Lsec\Cli\SelftestCommand;
use Lsec\Cli\SettingsCommand;
use Lsec\Cli\TextfocusCommand;
use Lsec\Infrastructure\Http\HttpTransport;
use Lsec\Infrastructure\Http\WpHttpTransport;
use Lsec\Integrations\AI\AiClientFactory;
use Lsec\Infrastructure\Database\Migrator;
use Lsec\Infrastructure\Logging\Logger;
use Lsec\Infrastructure\Logging\Redactor;
use Lsec\Infrastructure\Queue\ActionSchedulerQueue;
use Lsec\Infrastructure\Queue\Queue;
use Lsec\Infrastructure\Security\Secrets;
use Lsec\Rest\RestController;
use Lsec\Research\Keywords\KeywordRepository;
use Lsec\Workflow\RetryPolicy;
use Lsec\Workflow\RunRepository;
use Lsec\Workflow\Selftest\SelftestWorkflow;
use Lsec\Content\ContentIndex;
use Lsec\Content\ContentIndexWorkflow;
use Lsec\Entities\EntityExtractionWorkflow;
use Lsec\Research\Keywords\KeywordSyncWorkflow;
use Lsec\Topics\TopicResearchWorkflow;
use Lsec\Workflow\Watchdog;
use Lsec\Workflow\WorkflowEngine;
use Lsec\Workflow\WorkflowRegistry;

/**
 * Baut die Dienste zusammen und registriert die Hooks.
 */
final class Plugin {

	public const HEARTBEAT_HOOK     = 'lsec_heartbeat';
	public const HEARTBEAT_INTERVAL = 300;
	public const OPT_LAST_HEARTBEAT = 'lsec_last_heartbeat';
	public const OPT_LAST_CLI_RUN   = 'lsec_last_cli_queue_run';

	/**
	 * Einzige Instanz.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Schon gestartet?
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Schwärzung von Geheimnissen.
	 *
	 * @var Redactor
	 */
	public readonly Redactor $redactor;

	/**
	 * Protokoll.
	 *
	 * @var Logger
	 */
	public readonly Logger $logger;

	/**
	 * API-Schlüssel.
	 *
	 * @var Secrets
	 */
	public readonly Secrets $secrets;

	/**
	 * Datenzugriff für Abläufe.
	 *
	 * @var RunRepository
	 */
	public readonly RunRepository $runs;

	/**
	 * Warteschlange.
	 *
	 * @var Queue
	 */
	public readonly Queue $queue;

	/**
	 * Ablaufdefinitionen.
	 *
	 * @var WorkflowRegistry
	 */
	public readonly WorkflowRegistry $workflows;

	/**
	 * Ablaufsteuerung.
	 *
	 * @var WorkflowEngine
	 */
	public readonly WorkflowEngine $engine;

	/**
	 * Wiederaufnahme hängengebliebener Abläufe.
	 *
	 * @var Watchdog
	 */
	public readonly Watchdog $watchdog;
	/**
	 * HTTP für externe Dienste.
	 *
	 * @var HttpTransport
	 */
	public readonly HttpTransport $http;

	/**
	 * Erzeugt den konfigurierten KI-Adapter.
	 *
	 * @var AiClientFactory
	 */
	public readonly AiClientFactory $ai;

	/**
	 * Konstruktor: einfache, handgeschriebene Verdrahtung ohne Container-Bibliothek.
	 */
	private function __construct() {
		$this->redactor  = new Redactor();
		$this->logger    = new Logger( $this->redactor );
		$this->secrets   = new Secrets( $this->redactor );
		$this->runs      = new RunRepository();
		$this->queue     = new ActionSchedulerQueue();
		$this->workflows = new WorkflowRegistry();
		$this->engine    = new WorkflowEngine( $this->workflows, $this->runs, $this->queue, new RetryPolicy(), $this->logger );
		$this->watchdog  = new Watchdog( $this->runs, $this->queue, $this->logger );
		$this->http      = new WpHttpTransport();
		$this->ai        = new AiClientFactory( $this->secrets, $this->http );
	}

	/**
	 * Zugriff auf die Instanz.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registriert Hooks. Wird auf plugins_loaded aufgerufen.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Schema nach Updates nachziehen, ohne erneute Aktivierung.
		Migrator::maybe_migrate();

		$this->workflows->register( SelftestWorkflow::definition( (int) apply_filters( 'lsec_selftest_lock_ttl', 10 ) ) );
		$this->workflows->register( ContentIndexWorkflow::definition( $this ) );
		$this->workflows->register( EntityExtractionWorkflow::definition( $this ) );
		$this->workflows->register( KeywordSyncWorkflow::definition( $this ) );
		$this->workflows->register( TopicResearchWorkflow::definition( $this ) );

		/**
		 * Weitere Ablauftypen registrieren.
		 *
		 * @param WorkflowRegistry $registry Verzeichnis.
		 */
		do_action( 'lsec_register_workflows', $this->workflows );

		add_action( ActionSchedulerQueue::HOOK, array( $this->engine, 'process' ), 10, 1 );
		add_action(
			'lsec_setting_changed',
			function ( string $key, mixed $old, mixed $value ): void {
				$this->logger->audit(
					'Einstellung geändert',
					array(
						'key'  => $key,
						'old'  => $old,
						'new'  => $value,
						'user' => get_current_user_id(),
					)
				);
			},
			10,
			3
		);
		add_action( self::HEARTBEAT_HOOK, array( $this, 'heartbeat' ) );
		add_action( 'save_post', array( ContentIndex::class, 'mark_dirty' ), 10, 2 );
		add_action( 'deleted_post', array( EntityRepository::class, 'forget_post' ) );
		add_action( 'created_term', array( ContentIndex::class, 'mark_term_dirty' ), 10, 3 );
		add_action( 'edited_term', array( ContentIndex::class, 'mark_term_dirty' ), 10, 3 );
		add_action( 'delete_term', array( EntityRepository::class, 'forget_term' ) );
		add_action( 'rest_api_init', array( new RestController( $this ), 'register' ) );
		add_action( 'action_scheduler_init', array( $this, 'schedule_heartbeat' ) );
		add_action( 'action_scheduler_before_process_queue', array( $this, 'mark_cli_queue_run' ) );

		if ( is_admin() ) {
			( new AppPage() )->register();
			( new StatusPage( $this ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'lsec', new LsecCommand( $this ) );
			\WP_CLI::add_command( 'lsec selftest', new SelftestCommand( $this ) );
			\WP_CLI::add_command( 'lsec ai', new AiCommand( $this ) );
			\WP_CLI::add_command( 'lsec settings', new SettingsCommand() );
			\WP_CLI::add_command( 'lsec keywords', new KeywordsCommand( new KeywordRepository(), $this ) );
			\WP_CLI::add_command( 'lsec topics', new \Lsec\Cli\TopicsCommand( $this ) );
			\WP_CLI::add_command( 'lsec textfocus', new TextfocusCommand( $this, new KeywordRepository() ) );
			\WP_CLI::add_command( 'lsec entities', new EntitiesCommand( $this, new EntityRepository() ) );
			\WP_CLI::add_command( 'lsec check', new CheckCommand( $this ) );
			\WP_CLI::add_command( 'lsec draft', new DraftCommand() );
			\WP_CLI::add_command( 'lsec gsc', new GscCommand( $this, new KeywordRepository() ) );
			\WP_CLI::add_command( 'lsec runs', new RunsCommand( $this ) );
			\WP_CLI::add_command( 'lsec index', new IndexCommand( $this ) );
			\WP_CLI::add_command( 'lsec profile', new ProfileCommand( $this ) );
		}
	}

	/**
	 * Plant den wiederkehrenden Heartbeat ein, falls er fehlt.
	 */
	public function schedule_heartbeat(): void {
		if ( ! as_has_scheduled_action( self::HEARTBEAT_HOOK, array(), ActionSchedulerQueue::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, self::HEARTBEAT_INTERVAL, self::HEARTBEAT_HOOK, array(), ActionSchedulerQueue::GROUP );
		}
	}

	/**
	 * Heartbeat: Zeitstempel setzen und hängengebliebene Abläufe wieder aufnehmen.
	 */
	public function heartbeat(): void {
		update_option( self::OPT_LAST_HEARTBEAT, time(), false );
		$this->watchdog->run();
	}

	/**
	 * Merkt sich, wann die Warteschlange zuletzt über WP-CLI (Server-Cronjob) lief.
	 */
	public function mark_cli_queue_run(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			update_option( self::OPT_LAST_CLI_RUN, time(), false );
		}
	}
}
