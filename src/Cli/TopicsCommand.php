<?php
/**
 * WP-CLI: Themenrecherche und Themen.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Topics\ClaimRepository;
use Lsec\Topics\PriorityScorer;
use Lsec\Topics\TopicRepository;
use Lsec\Topics\TopicResearchWorkflow;
use WP_CLI;

/**
 * Themen recherchieren, ansehen, freigeben (Spezifikation 8.4, 8.4a, 6.4; Stufe D).
 */
final class TopicsCommand {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Startet eine Themenrecherche mit Websuche (KI-Kosten etwa 0,05–0,20 USD).
	 *
	 * ## OPTIONS
	 *
	 * [--service=<name>]
	 * : Schwerpunkt Leistung, z. B. Pflasterreinigung.
	 *
	 * [--region=<ort>]
	 * : Schwerpunkt Ort, z. B. Lehrte.
	 *
	 * [--count=<n>]
	 * : Zahl der Themen (1–10). Standard: Einstellung topic_count.
	 *
	 * [--web-results=<n>]
	 * : Treffer der Websuche (1–10) statt ai_web_max_results, z. B. für den Vergleich 3 gegen 5.
	 *
	 * [--web-engine=<engine>]
	 * : Suchmaschine bei OpenRouter statt ai_web_engine: standard, native, exa, parallel oder perplexity.
	 *
	 * [--research-model=<modell>]
	 * : Recherchemodell statt ai_research_model (Vergleich der Modelle).
	 *
	 * [--force]
	 * : Auch wenn derselbe Schwerpunkt in den letzten 14 Tagen recherchiert wurde.
	 *
	 * [--now]
	 * : Hier abarbeiten statt über den Cronjob.
	 *
	 * [--stub=<file>]
	 * : JSON mit research, sources und result statt KI (Prüfskript, ohne Kosten).
	 *
	 * [--offline]
	 * : Ortsbezug nur mit bekannten Orten, ohne Wikidata (Prüfskript).
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec topics research --service=Pflasterreinigung --region=Lehrte --now
	 *     wp lsec topics research --region=Uetze --web-results=5 --force --now
	 *     wp lsec topics research --region=Uetze --web-engine=exa --force --now
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function research( array $args, array $assoc_args ): void {
		$options = array(
			'service' => (string) ( $assoc_args['service'] ?? '' ),
			'region'  => (string) ( $assoc_args['region'] ?? '' ),
			'force'   => ! empty( $assoc_args['force'] ),
		);
		if ( isset( $assoc_args['count'] ) ) {
			$options['count'] = (int) $assoc_args['count'];
		}
		if ( isset( $assoc_args['web-results'] ) ) {
			$options['web_max_results'] = (int) $assoc_args['web-results'];
		}
		if ( isset( $assoc_args['research-model'] ) ) {
			$options['research_model'] = (string) $assoc_args['research-model'];
		}
		if ( isset( $assoc_args['web-engine'] ) ) {
			$engine = strtolower( (string) $assoc_args['web-engine'] );
			if ( ! in_array( $engine, Settings::CHOICES['ai_web_engine'], true ) ) {
				WP_CLI::error( '--web-engine erlaubt: ' . implode( ', ', Settings::CHOICES['ai_web_engine'] ) );
			}
			$options['web_engine'] = $engine;
		}
		if ( ! empty( $assoc_args['stub'] ) ) {
			$stub = (string) file_get_contents( (string) $assoc_args['stub'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( null === json_decode( $stub, true ) ) {
				WP_CLI::error( 'Die Datei enthält kein gültiges JSON.' );
			}
			$options['stub_research'] = $stub;
		}
		if ( ! empty( $assoc_args['offline'] ) ) {
			$options['offline'] = true;
		}
		try {
			$run = TopicResearchWorkflow::start( $this->plugin, $options );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$this->plugin->logger->audit(
			'Themenrecherche gestartet',
			array(
				'run_id'  => $run['run_id'],
				'service' => $options['service'],
				'region'  => $options['region'],
				'via'     => 'cli',
			)
		);
		if ( empty( $assoc_args['now'] ) ) {
			WP_CLI::success( 'Ablauf ' . $run['run_id'] . ' eingeplant; der Cronjob arbeitet ihn ab. Ergebnis: wp lsec topics report ' . $run['run_id'] );
			return;
		}
		for ( $i = 0; $i < 200; $i++ ) {
			$this->plugin->engine->process( $run['run_id'] );
			$status = (string) ( $this->plugin->runs->find( $run['run_id'] )['status'] ?? '' );
			if ( ! in_array( $status, array( 'queued', 'running' ), true ) ) {
				break;
			}
		}
		$this->report( array( (string) $run['run_id'] ), array() );
	}

	/**
	 * Bericht einer Recherche: Kosten, Quellen, geprüfte Tatsachen, Themen. Grundlage für den Vergleich
	 * der Trefferzahl und der Recherchemodelle.
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
	 * [--research]
	 * : Zeigt zusätzlich den Text der Websuche (zur Fehlersuche, wenn Quellen oder Tatsachen fehlen).
	 *
	 * [--facts]
	 * : Zeigt jede Tatsache mit Prüfergebnis, Quelle, Zitat der KI und Beleg aus der Quelle.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function report( array $args, array $assoc_args ): void {
		$id  = (int) ( $args[0] ?? 0 );
		$run = $this->plugin->runs->find( $id );
		if ( null === $run || TopicResearchWorkflow::TYPE !== $run['type'] ) {
			WP_CLI::error( 'Keine Themenrecherche: ' . $id );
		}
		$input   = json_decode( (string) $run['context'], true );
		$outputs = array();
		foreach ( $this->plugin->runs->steps( $id ) as $step ) {
			$decoded                           = json_decode( (string) $step['output'], true );
			$outputs[ (string) $step['step'] ] = is_array( $decoded ) ? $decoded : array();
		}
		$research = $outputs['research'] ?? array();
		$verify   = $outputs['verify_facts'] ?? array();
		$counts   = (array) ( $verify['counts'] ?? array() );
		$facts    = array_sum( $counts );
		$report   = array(
			'run'          => $id,
			'status'       => (string) $run['status'],
			'focus'        => (array) ( $input['focus'] ?? array() ),
			'web_results'  => $input['web_max_results'] ?? null,
			'engine'       => (string) ( $research['engine'] ?? $input['web_engine'] ?? '' ),
			'sources_from' => (string) ( $research['sources_from'] ?? '' ),
			'research_len' => mb_strlen( (string) ( $research['research'] ?? '' ) ),
			'truncated'    => ! empty( $research['truncated'] ),
			'fact_details' => (array) ( $verify['facts'] ?? array() ),
			'model'        => (string) ( $research['model'] ?? '' ),
			'cost_usd'     => round( ( new AiCallRepository() )->cost_of_run( $id ), 4 ),
			'sources'      => count( (array) ( $research['sources'] ?? array() ) ),
			'facts'        => $facts,
			'verified'     => (int) ( $counts['verified'] ?? 0 ),
			'disputed'     => (int) ( $counts['disputed'] ?? 0 ),
			'rejected'     => (int) ( $counts['rejected'] ?? 0 ),
			'verified_pct' => $facts > 0 ? round( 100 * (int) ( $counts['verified'] ?? 0 ) / $facts ) : null,
			'pages_failed' => (int) ( $verify['failed'] ?? 0 ),
			'dropped'      => (array) ( $research['dropped'] ?? array() ),
			'topics'       => (array) ( $outputs['propose_topics']['topics'] ?? array() ),
			'off_topic'    => (int) ( $outputs['propose_topics']['off_topic'] ?? 0 ),
		);
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		$focus = trim( implode( ' / ', array_filter( $report['focus'] ) ) );
		WP_CLI::log( sprintf( 'Ablauf %d · %s · Schwerpunkt: %s · Modell %s · Websuche %s Treffer, Suchmaschine %s', $id, $report['status'], '' !== $focus ? $focus : 'keiner', '' !== $report['model'] ? $report['model'] : '–', null === $report['web_results'] ? 'Standard' : (string) $report['web_results'], '' !== $report['engine'] ? $report['engine'] : 'standard' ) );
		WP_CLI::log( sprintf( 'Kosten %s USD · %d Quellen · %d Tatsachen: %d geprüft, %d unklar, %d verworfen%s · %d Seiten nicht abrufbar', number_format( $report['cost_usd'], 4, ',', '' ), $report['sources'], $facts, $report['verified'], $report['disputed'], $report['rejected'], null === $report['verified_pct'] ? '' : ' (' . $report['verified_pct'] . ' % geprüft)', $report['pages_failed'] ) );
		foreach ( $report['dropped'] as $line ) {
			WP_CLI::log( '  verworfen: ' . $line );
		}
		if ( $report['truncated'] ) {
			WP_CLI::warning( 'Die Antwort der Websuche wurde wegen der Längenbegrenzung abgeschnitten.' );
		}
		if ( 'succeeded' === $report['status'] && 0 === $report['sources'] ) {
			WP_CLI::warning( sprintf( 'Die Websuche lieferte keine Quellen (Recherchetext %d Zeichen, ohne Adressen). Ohne Quellen gibt es keine Tatsachen. Text ansehen: wp lsec topics report %d --research; anderer Versuch: --web-engine=exa', $report['research_len'], $id ) );
		} elseif ( 'text' === $report['sources_from'] ) {
			WP_CLI::log( '  Quellen aus dem Recherchetext gelesen (das Modell lieferte keine Quellenangaben).' );
		}
		if ( ! empty( $assoc_args['facts'] ) ) {
			$unique = array();
			foreach ( $report['fact_details'] as $fact ) {
				$key = implode( '|', array( (string) $fact['subject'], (string) $fact['property'], (string) $fact['value'], (string) ( $fact['source_url'] ?? '' ) ) );
				if ( isset( $unique[ $key ] ) ) {
					++$unique[ $key ]['times'];
					continue;
				}
				$unique[ $key ] = $fact + array( 'times' => 1 );
			}
			foreach ( $unique as $fact ) {
				WP_CLI::log( sprintf( '[%s%s] %s: %s = %s%s', (string) $fact['status'], empty( $fact['quality'] ) ? '' : '/' . $fact['quality'], (string) $fact['subject'], (string) $fact['property'], (string) $fact['value'], $fact['times'] > 1 ? ' (in ' . $fact['times'] . ' Themen)' : '' ) );
				WP_CLI::log( '    Prüfung: ' . (string) ( $fact['note'] ?? '' ) );
				WP_CLI::log( '    Quelle:  ' . (string) ( $fact['source_url'] ?? '' ) );
				WP_CLI::log( '    Zitat KI: ' . mb_substr( (string) ( $fact['quote'] ?? '' ), 0, 200 ) );
				if ( (string) ( $fact['excerpt'] ?? '' ) !== (string) ( $fact['quote'] ?? '' ) ) {
					WP_CLI::log( '    Beleg:   ' . mb_substr( (string) ( $fact['excerpt'] ?? '' ), 0, 200 ) );
				}
			}
		}
		if ( ! empty( $assoc_args['research'] ) ) {
			WP_CLI::log( "--- Recherchetext ---\n" . $this->plugin->logger->redact( (string) ( $research['research'] ?? '' ) ) . "\n---" );
		}
		if ( array() !== $report['topics'] ) {
			WP_CLI\Utils\format_items( 'table', $report['topics'], array( 'id', 'title', 'score', 'status' ) );
		}
		if ( $report['off_topic'] > 0 ) {
			WP_CLI::log( $report['off_topic'] . ' Keywords als „ohne Bezug“ ausgeschlossen.' );
		}
		if ( 'succeeded' !== $report['status'] ) {
			WP_CLI::warning( 'Der Ablauf ist noch nicht fertig (' . $report['status'] . '). Stand: wp lsec runs show ' . $id );
		}
	}

	/**
	 * Themen.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : proposed, approved, rejected, discarded. Standard: alle außer discarded.
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
		$rows = array();
		foreach ( ( new TopicRepository() )->list( (string) ( $assoc_args['status'] ?? '' ) ) as $row ) {
			$rows[] = array(
				'id'        => $row['id'],
				'thema'     => $row['title'],
				'format'    => $row['format'],
				'ort'       => (string) $row['region'],
				'priorität' => $row['priority_score'],
				'status'    => $row['status'],
				'keywords'  => (string) $row['primary_keywords'],
			);
		}
		Output::items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'thema', 'format', 'ort', 'priorität', 'status', 'keywords' ) );
	}

	/**
	 * Ein Thema mit Priorität, Keywords, Entitäten, Tatsachen und Überschneidung.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Thema.
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
	public function show( array $args, array $assoc_args ): void {
		$topic = ( new TopicRepository() )->detail( (int) ( $args[0] ?? 0 ) );
		if ( null === $topic ) {
			WP_CLI::error( 'Thema nicht gefunden.' );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $topic, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}
		WP_CLI::log( sprintf( '%s (%s, %s) · Priorität %s · %s', $topic['title'], $topic['format'], (string) $topic['region'], (string) $topic['priority_score'], $topic['status'] ) );
		WP_CLI::log( 'Begründung: ' . $topic['rationale'] );
		foreach ( (array) ( $topic['score_breakdown']['breakdown'] ?? array() ) as $name => $part ) {
			WP_CLI::log( sprintf( '  %-18s %s', PriorityScorer::LABELS[ $name ] ?? $name, null === $part['value'] ? 'fehlt' : number_format( (float) $part['points'], 1, ',', '' ) . ' Punkte' ) );
		}
		WP_CLI::log( 'Keywords: ' . implode( ', ', array_map( static fn( array $k ): string => $k['keyword'] . ( 'primary' === $k['role'] ? ' (Haupt)' : '' ), $topic['keywords'] ) ) );
		WP_CLI::log( 'Entitäten: ' . implode( ', ', array_map( static fn( array $e ): string => $e['name'], $topic['entities'] ) ) );
		foreach ( $topic['facts'] as $fact ) {
			WP_CLI::log( sprintf( '  [%s] #%d %s: %s = %s – %s', $fact['status'], $fact['id'], (string) $fact['entity'], $fact['property'], $fact['value'], (string) $fact['source_url'] ) );
			if ( '' !== (string) ( $fact['note'] ?? '' ) ) {
				WP_CLI::log( '      ' . $fact['note'] );
			}
		}
		foreach ( (array) ( $topic['overlap']['items'] ?? array() ) as $item ) {
			WP_CLI::log( '  Überschneidung: ' . $item['reason'] . ' – ' . $item['url'] );
		}
	}

	/**
	 * Gibt ein Thema frei, lehnt es ab oder setzt es zurück.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Thema.
	 *
	 * <decision>
	 * : approved, rejected oder proposed.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function decide( array $args, array $assoc_args ): void {
		try {
			$ok = ( new TopicRepository() )->decide( (int) ( $args[0] ?? 0 ), (string) ( $args[1] ?? '' ), get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( ! $ok ) {
			WP_CLI::error( 'Thema nicht gefunden.' );
		}
		$this->plugin->logger->audit(
			'Thema entschieden',
			array(
				'topic_id' => (int) $args[0],
				'decision' => (string) $args[1],
				'user_id'  => get_current_user_id(),
				'via'      => 'cli',
			)
		);
		WP_CLI::success( 'Thema ' . (int) $args[0] . ': ' . $args[1] );
	}

	/**
	 * Bestätigt oder verwirft eine Tatsache (Aussage) von Hand.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Aussage (Nummer aus wp lsec topics show).
	 *
	 * <decision>
	 * : confirmed oder rejected.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function fact( array $args, array $assoc_args ): void {
		try {
			$ok = ( new ClaimRepository() )->decide( (int) ( $args[0] ?? 0 ), (string) ( $args[1] ?? '' ) );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( ! $ok ) {
			WP_CLI::error( 'Aussage nicht gefunden.' );
		}
		$this->plugin->logger->audit(
			'Aussage entschieden',
			array(
				'claim_id' => (int) $args[0],
				'decision' => (string) $args[1],
				'user_id'  => get_current_user_id(),
				'via'      => 'cli',
			)
		);
		WP_CLI::success( 'Aussage ' . (int) $args[0] . ': ' . $args[1] );
	}

	/**
	 * Zeigt doppelte Themen; mit --apply bleibt je Gruppe das erste Thema, die übrigen werden abgelehnt und
	 * geben ihre Tatsachen und Keywords an das erste ab.
	 *
	 * Reihenfolge je Gruppe: geschrieben, in Arbeit, freigegeben, Vorschlag, dann nach Priorität.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Dubletten ablehnen und zusammenführen.
	 *
	 * @param string[]                   $args       Argumente.
	 * @param array<string, string|bool> $assoc_args Optionen.
	 */
	public function duplicates( array $args, array $assoc_args ): void {
		$repo   = new TopicRepository();
		$groups = $repo->duplicate_groups( TopicResearchWorkflow::profile_services() );
		if ( array() === $groups ) {
			WP_CLI::success( 'Keine doppelten Themen.' );
			return;
		}
		foreach ( $groups as $group ) {
			$keep = array_shift( $group );
			WP_CLI::log( sprintf( 'bleibt   #%d %s (%s)', $keep['id'], $keep['title'], $keep['status'] ) );
			foreach ( $group as $twin ) {
				WP_CLI::log( sprintf( '  doppelt #%d %s (%s)', $twin['id'], $twin['title'], $twin['status'] ) );
				if ( empty( $assoc_args['apply'] ) || in_array( $twin['status'], array( 'in_production', 'produced' ), true ) ) {
					continue;
				}
				$detail = $repo->detail( (int) $twin['id'] );
				$basis  = (array) ( $detail['research_basis'] ?? array() );
				$roles  = array(
					'primary'   => array(),
					'secondary' => array(),
				);
				foreach ( (array) ( $detail['keywords'] ?? array() ) as $k ) {
					$roles[ 'primary' === $k['role'] ? 'primary' : 'secondary' ][] = (int) $k['id'];
				}
				$repo->merge_basis( (int) $keep['id'], (array) ( $basis['claim_ids'] ?? array() ), (array) ( $basis['source_ids'] ?? array() ), $roles['primary'], $roles['secondary'] );
				$repo->decide( (int) $twin['id'], 'rejected', get_current_user_id() );
				$this->plugin->logger->audit(
					'Dublette abgelehnt',
					array(
						'topic_id' => (int) $twin['id'],
						'keep'     => (int) $keep['id'],
						'via'      => 'cli',
					)
				);
			}
		}
		WP_CLI::log( empty( $assoc_args['apply'] ) ? 'Zusammenführen: wp lsec topics duplicates --apply' : 'Dubletten abgelehnt, Tatsachen und Keywords übernommen.' );
	}
}
