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
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Production\QualityReportRepository;
use Lsec\Production\QualityReviewer;
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
	 * : Modell für alle Aufrufe dieses Entwurfs (Modellvergleich), z. B. openai/gpt-6-luna.
	 *
	 * [--writer=<modell>]
	 * : Modell nur für den Text dieses Entwurfs, z. B. anthropic/claude-sonnet-5.5 (sonst Einstellung ai_writing_model).
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
	 *     wp lsec production start 48 --writer=anthropic/claude-sonnet-5.5 --again --now
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
		if ( isset( $assoc_args['writer'] ) ) {
			$options['writer'] = (string) $assoc_args['writer'];
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
				'writer'   => $options['writer'] ?? null,
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
	 * : Ablauf-ID oder Nummer des Entwurfs.
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
		$id   = $this->run_of( (int) ( $args[0] ?? 0 ) );
		$data = ProductionReport::for_run( $this->plugin, $id );
		if ( null === $data ) {
			WP_CLI::error( 'Kein Ablauf content_production: ' . $id );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		WP_CLI::log( sprintf( 'Ablauf %d · %s · Thema %d · Modell %s · Kosten %s USD', $id, $data['status'], $data['topic'], '' !== $data['model'] ? $data['model'] : '–', number_format( (float) $data['cost_usd'], 4, ',', '' ) ) );
		WP_CLI::log( 'Eigene Seiten als Angaben des Betriebs: ' . ( array() === $data['own_pages'] ? 'keine passende gefunden' : implode( ', ', $data['own_pages'] ) ) );
		WP_CLI::log( 'Unternehmensbeschreibung im Profil: ' . ( $data['company_description'] ? 'ja' : 'nein' ) );
		WP_CLI::log( array() === $data['services'] ? 'Leistungsbeschreibungen im Profil: keine – Abläufe des Betriebs darf der Entwurf nicht beschreiben (Seite „Unternehmen“, Leistungen, Kurzbeschreibung).' : 'Leistungsbeschreibungen im Profil: ' . implode( ', ', $data['services'] ) );
		$facts = (array) $data['facts'];
		WP_CLI::log( sprintf( 'Format %s · Ort %s · Geprüfte Tatsachen: %d im Kontext (%d zu Orten), im Text verwendet %d (%d zu Orten)', '' !== $facts['format'] ? $facts['format'] : '–', '' !== $facts['region'] ? $facts['region'] : '–', $facts['context'], $facts['local'], $facts['used'], $facts['used_local'] ) );
		$place = (array) $data['place_research'];
		if ( $place['researched'] ) {
			WP_CLI::log( sprintf( 'Ortsfakten recherchiert: %d geprüft, %d strittig, %d verworfen', (int) ( $place['counts']['verified'] ?? 0 ), (int) ( $place['counts']['disputed'] ?? 0 ), (int) ( $place['counts']['rejected'] ?? 0 ) ) );
			foreach ( (array) $place['facts'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
		} elseif ( '' !== $place['reason'] && 'keine lokale Seite' !== $place['reason'] ) {
			WP_CLI::log( 'Ortsrecherche: ' . $place['reason'] );
		}
		if ( (int) $data['unpriced'] > 0 ) {
			WP_CLI::warning( sprintf( '%d KI-Aufrufe ohne Kostenangabe; die Summe ist unvollständig. Einzeln: wp lsec production report %d --format=json', $data['unpriced'], $id ) );
		}
		if ( $data['post_id'] > 0 ) {
			WP_CLI::log( sprintf( 'Entwurf %d: %s · %s Wörter · %s', $data['post_id'], $data['title'], (string) $data['words'], (string) get_edit_post_link( $data['post_id'], 'raw' ) ) );
		}
		$revision = (array) $data['revision'];
		if ( $revision['revised'] ) {
			WP_CLI::log( sprintf( 'Nachgebessert (vorher blockiert: %s), %d Stellen:', implode( ', ', array_unique( (array) $revision['before'] ) ), count( (array) $revision['applied'] ) ) );
			foreach ( (array) $revision['applied'] as $line ) {
				WP_CLI::log( '  ' . $line );
			}
			foreach ( (array) $revision['missed'] as $line ) {
				WP_CLI::log( '  nicht gefunden: ' . $line );
			}
		} elseif ( '' !== $revision['reason'] && 'bestanden' !== $revision['reason'] ) {
			WP_CLI::log( 'Nachbesserung: ' . $revision['reason'] );
		}
		if ( null !== $data['score'] ) {
			self::quality( (bool) $data['passed'], (float) $data['score'], (array) $data['scores'], (array) $data['blocking'], (array) $data['warnings'] );
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
	 * Prüft einen vorhandenen Entwurf noch einmal: Regeln und KI-Bewertung, ohne ihn neu zu schreiben
	 * (Vergleich der Prüfmodelle; mit openai/gpt-6-luna unter 1 Cent). Das gespeicherte Prüfergebnis und der
	 * Status des Entwurfs bleiben unverändert. Geprüft wird der Text, den die KI geschrieben hat, nicht
	 * spätere Änderungen in WordPress.
	 *
	 * ## OPTIONS
	 *
	 * <draft>
	 * : Nummer des Entwurfs oder Ablauf-ID.
	 *
	 * [--model=<modell>]
	 * : Prüfmodell für diese Prüfung (sonst Einstellung ai_review_model, dann Hauptmodell).
	 *
	 * [--stub=<file>]
	 * : JSON mit review statt KI (Prüfskript, ohne Kosten).
	 *
	 * [--format=<format>]
	 * : table oder json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec production review 2760 --model=openai/gpt-6-luna
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function review( array $args, array $assoc_args ): void {
		$id   = $this->run_of( (int) ( $args[0] ?? 0 ) );
		$run  = $this->plugin->runs->find( $id );
		$prev = ProductionReport::for_run( $this->plugin, $id );
		if ( null === $run || null === $prev ) {
			WP_CLI::error( 'Kein Ablauf content_production: ' . $id );
		}
		$outputs = array();
		foreach ( $this->plugin->runs->steps( $id ) as $step ) {
			$decoded                           = json_decode( (string) $step['output'], true );
			$outputs[ (string) $step['step'] ] = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! isset( $outputs['gather_context']['topic'], $outputs['enrich']['draft'] ) ) {
			WP_CLI::error( 'Ablauf ' . $id . ' hat noch keinen fertigen Entwurf.' );
		}
		$input = isset( $assoc_args['model'] ) && '' !== (string) $assoc_args['model'] ? array( 'reviewer' => (string) $assoc_args['model'] ) : array();
		$stub  = null;
		if ( ! empty( $assoc_args['stub'] ) ) {
			$decoded = json_decode( (string) file_get_contents( (string) $assoc_args['stub'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $decoded ) ) {
				WP_CLI::error( 'Die Datei enthält kein gültiges JSON.' );
			}
			$stub = is_array( $decoded['review'] ?? null ) ? $decoded['review'] : array();
		}
		try {
			$ctx           = $outputs['gather_context'];
			$ctx['claims'] = array_merge( (array) $ctx['claims'], (array) ( $outputs['place_facts']['claims'] ?? array() ) );
			$draft         = ! empty( $outputs['revise']['revised'] ) ? (array) $outputs['revise']['draft'] : (array) $outputs['enrich']['draft'];
			$result        = ContentProductionWorkflow::assess( $this->plugin, $ctx, $draft, $input, $stub, null );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $this->plugin->logger->redact( $e->getMessage() ) );
		}
		$before = '';
		foreach ( ( new AiCallRepository() )->for_run( $id ) as $call ) {
			if ( 'quality_review' === $call['task'] && 'ok' === $call['status'] ) {
				$before = (string) $call['model'];
			}
		}
		$this->plugin->logger->audit(
			'Entwurf neu geprüft',
			array(
				'run_id' => $id,
				'model'  => $result['model'],
				'score'  => $result['report']['score'],
				'passed' => $result['report']['passed'],
			)
		);
		$report = $result['report'];
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'run'      => $id,
						'post_id'  => $prev['post_id'],
						'model'    => $result['model'],
						'cost_usd' => $result['cost'],
						'before'   => array(
							'model'  => $before,
							'score'  => $prev['score'],
							'passed' => $prev['passed'],
						),
					) + $report,
					JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
				)
			);
			return;
		}
		WP_CLI::log( sprintf( 'Entwurf %d (Ablauf %d) neu geprüft · Prüfmodell %s · Kosten %s USD', (int) $prev['post_id'], $id, $result['model'], null === $result['cost'] ? '–' : number_format( (float) $result['cost'], 4, ',', '' ) ) );
		if ( null !== $prev['score'] ) {
			WP_CLI::log( sprintf( 'Bisher: %s · Bewertung %s/100 · Prüfmodell %s', $prev['passed'] ? 'bestanden' : 'blockiert', number_format( (float) $prev['score'], 1, ',', '' ), '' !== $before ? $before : '–' ) );
		}
		self::quality( (bool) $report['passed'], (float) $report['score'], (array) $report['review'], (array) $report['blocking'], (array) $report['warnings'] );
		WP_CLI::log( 'Gespeichertes Prüfergebnis und Status des Entwurfs bleiben unverändert.' );
	}

	/**
	 * Ablauf zu einer Ablauf-ID oder der Nummer eines Entwurfs (Beitrags-ID, Post-Meta _lsec_run_id).
	 *
	 * @param int $id Ablauf oder Entwurf.
	 */
	private function run_of( int $id ): int {
		$run = $this->plugin->runs->find( $id );
		if ( null !== $run && ContentProductionWorkflow::TYPE === $run['type'] ) {
			return $id;
		}
		$from_post = (int) get_post_meta( $id, '_lsec_run_id', true );
		return $from_post > 0 ? $from_post : $id;
	}

	/**
	 * Prüfergebnis ausgeben.
	 *
	 * @param bool                              $passed   Bestanden.
	 * @param float                             $score    Bewertung 0–100.
	 * @param array<string, float>              $scores   Einzelwerte.
	 * @param array<int, array<string, string>> $blocking Blockierende Mängel.
	 * @param array<int, array<string, string>> $warnings Warnungen.
	 */
	private static function quality( bool $passed, float $score, array $scores, array $blocking, array $warnings ): void {
		WP_CLI::log( sprintf( 'Prüfung: %s · Bewertung %s/100', $passed ? 'bestanden' : 'blockiert', number_format( $score, 1, ',', '' ) ) );
		foreach ( $scores as $key => $value ) {
			WP_CLI::log( sprintf( '  %-20s %s', QualityReviewer::SCORES[ $key ] ?? $key, number_format( (float) $value, 2, ',', '' ) ) );
		}
		foreach ( $blocking as $issue ) {
			WP_CLI::log( '  blockiert: ' . $issue['code'] . ' – ' . $issue['detail'] );
		}
		foreach ( $warnings as $issue ) {
			WP_CLI::log( '  Warnung:   ' . $issue['code'] . ' – ' . $issue['detail'] );
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
