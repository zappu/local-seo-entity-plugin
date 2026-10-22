<?php
/**
 * WP-CLI: Beiträge zu freigegebenen Themen (Stufe E).
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Production\ContentProductionWorkflow;
use Lsec\Production\ProductionReport;
use Lsec\Production\QualityReportRepository;
use Lsec\Topics\TopicRepository;
use WP_CLI;

/**
 * Startet content_production und zeigt Bericht, Kosten und Prüfergebnis.
 */
final class ProductionCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Schreibt einen Entwurf zu einem freigegebenen Thema (Briefing, Entwurf, Prüfung; etwa 0,05–0,30 USD).
	 *
	 * ## OPTIONS
	 *
	 * <topic>
	 * : Thema (freigegeben).
	 *
	 * [--model=<modell>]
	 * : Hauptmodell nur für diesen Entwurf (Modellvergleich), z. B. anthropic/claude-sonnet-5.5.
	 *
	 * [--again]
	 * : Neuen Entwurf schreiben, auch wenn das Thema schon einen hat.
	 *
	 * [--allow-duplicate]
	 * : Auch schreiben, wenn ein sehr ähnliches Thema schon einen Entwurf hat.
	 *
	 * [--now]
	 * : Hier abarbeiten statt über den Cronjob.
	 *
	 * [--stub=<file>]
	 * : JSON mit brief, draft und review statt KI (Prüfskript, ohne Kosten).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec production start 48 --now
	 *     wp lsec production start 48 --model=anthropic/claude-sonnet-5.5 --again --now
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function start( array $args, array $assoc_args ): void {
		$options = array(
			'again'           => ! empty( $assoc_args['again'] ),
			'allow_duplicate' => ! empty( $assoc_args['allow-duplicate'] ),
			'reason'          => 'cli',
		);
		if ( isset( $assoc_args['model'] ) ) {
			$options['model'] = (string) $assoc_args['model'];
		}
		if ( ! empty( $assoc_args['stub'] ) ) {
			$stub = (string) file_get_contents( (string) $assoc_args['stub'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( null === json_decode( $stub, true ) ) {
				WP_CLI::error( 'Die Datei enthält kein gültiges JSON.' );
			}
			$options['stub'] = $stub;
		}
		try {
			$run = ContentProductionWorkflow::start( $this->plugin, (int) ( $args[0] ?? 0 ), $options );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->plugin->logger->audit(
			'Beitrag gestartet',
			array(
				'run_id'   => $run['run_id'],
				'topic_id' => (int) ( $args[0] ?? 0 ),
				'model'    => $options['model'] ?? null,
				'via'      => 'cli',
			)
		);
		if ( ! $run['created'] ) {
			WP_CLI::log( 'Für dieses Thema läuft schon Ablauf ' . $run['run_id'] . '. Neuer Entwurf: --again' );
		}
		if ( empty( $assoc_args['now'] ) ) {
			WP_CLI::success( 'Ablauf ' . $run['run_id'] . ' eingeplant; der Cronjob arbeitet ihn ab. Ergebnis: wp lsec production report ' . $run['run_id'] );
			return;
		}
		for ( $i = 0; $i < 100; $i++ ) {
			$this->plugin->engine->process( $run['run_id'] );
			$status = (string) ( $this->plugin->runs->find( $run['run_id'] )['status'] ?? '' );
			if ( ! in_array( $status, array( 'queued', 'running' ), true ) ) {
				break;
			}
		}
		$this->report( array( (string) $run['run_id'] ), array() );
	}

	/**
	 * Bericht eines Entwurfs: Beitrag, Kosten, Prüfergebnis.
	 *
	 * ## OPTIONS
	 *
	 * <run>
	 * : Ablauf-ID.
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function report( array $args, array $assoc_args ): void {
		$id   = (int) ( $args[0] ?? 0 );
		$data = ProductionReport::for_run( $this->plugin, $id );
		if ( null === $data ) {
			WP_CLI::error( 'Kein Ablauf content_production: ' . $id );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		WP_CLI::log( sprintf( 'Ablauf %d · %s · Thema %d · Modell %s · Kosten %s USD', $id, $data['status'], $data['topic'], '' !== $data['model'] ? $data['model'] : '–', number_format( (float) $data['cost_usd'], 4, ',', '' ) ) );
		if ( $data['post_id'] > 0 ) {
			WP_CLI::log( sprintf( 'Entwurf %d: %s · %s Wörter · %s', $data['post_id'], $data['title'], (string) $data['words'], (string) get_edit_post_link( $data['post_id'], 'raw' ) ) );
		}
		if ( null !== $data['score'] ) {
			WP_CLI::log( sprintf( 'Prüfung: %s · Bewertung %s/100', $data['passed'] ? 'bestanden' : 'blockiert', number_format( (float) $data['score'], 1, ',', '' ) ) );
			foreach ( (array) $data['scores'] as $key => $value ) {
				WP_CLI::log( sprintf( '  %-20s %s', \Lsec\Production\QualityReviewer::SCORES[ $key ] ?? $key, number_format( (float) $value, 2, ',', '' ) ) );
			}
		}
		foreach ( (array) $data['blocking'] as $issue ) {
			WP_CLI::log( '  blockiert: ' . $issue['code'] . ' – ' . $issue['detail'] );
		}
		foreach ( (array) $data['warnings'] as $issue ) {
			WP_CLI::log( '  Warnung:   ' . $issue['code'] . ' – ' . $issue['detail'] );
		}
		foreach ( $data['dropped'] as $line ) {
			WP_CLI::log( '  verworfen: ' . $line );
		}
		if ( in_array( $data['status'], array( 'queued', 'running', 'waiting' ), true ) ) {
			WP_CLI::warning( 'Der Ablauf ist noch nicht fertig (' . $data['status'] . ', Schritt ' . $data['step'] . '). Stand: wp lsec runs show ' . $id );
		} elseif ( 'failed' === $data['status'] ) {
			WP_CLI::warning( 'Fehlgeschlagen: ' . $data['error'] . ' – fortsetzen mit wp lsec runs resume ' . $id );
		}
	}

	/**
	 * Startet Entwürfe zu freigegebenen Themen wie der Cronjob (Einstellungen production_auto, drafts_per_day).
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function schedule( array $args, array $assoc_args ): void {
		$runs = ContentProductionWorkflow::maybe_start_scheduled( $this->plugin, $skipped );
		WP_CLI::log( array() === $runs ? 'Kein Entwurf gestartet.' : 'Gestartet: Abläufe ' . implode( ', ', $runs ) );
		foreach ( (array) $skipped as $reason ) {
			WP_CLI::log( '  übersprungen: ' . $reason );
		}
		WP_CLI::log( sprintf( 'Heute gestartet: %d von %d.', ContentProductionWorkflow::started_today(), (int) \Lsec\Core\Settings::get( 'drafts_per_day' ) ) );
	}

	/**
	 * Freigegebene und produzierte Themen mit Beitrag und Prüfergebnis.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows    = array();
		$reports = new QualityReportRepository();
		foreach ( array( 'approved', 'in_production', 'produced' ) as $status ) {
			foreach ( ( new TopicRepository() )->list( $status, 100 ) as $topic ) {
				$post_id = (int) ( $topic['post_id'] ?? 0 );
				$report  = $post_id > 0 ? $reports->latest_for_post( $post_id ) : null;
				$rows[]  = array(
					'thema'     => (int) $topic['id'],
					'titel'     => mb_substr( (string) $topic['title'], 0, 70 ),
					'status'    => (string) $topic['status'],
					'beitrag'   => $post_id > 0 ? $post_id : '',
					'prüfung'   => null === $report ? '' : ( $report['passed'] ? 'bestanden' : 'blockiert' ),
					'bewertung' => null === $report || null === $report['score'] ? '' : $report['score'],
				);
			}
		}
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'thema', 'titel', 'status', 'beitrag', 'prüfung', 'bewertung' ) );
		WP_CLI::log( sprintf( 'Heute gestartet: %d von %d (Einstellung drafts_per_day).', ContentProductionWorkflow::started_today(), (int) \Lsec\Core\Settings::get( 'drafts_per_day' ) ) );
	}
}
