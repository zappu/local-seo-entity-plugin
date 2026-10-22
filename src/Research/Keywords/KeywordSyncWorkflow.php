<?php
/**
 * Ablauf keyword_sync.
 *
 * @package Lsec
 */

namespace Lsec\Research\Keywords;

use Lsec\Cli\GscCommand;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\PlaceResolver;
use Lsec\Entities\ServiceArea;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiClientFactory;
use Lsec\Integrations\AI\AiException;
use Lsec\Integrations\AI\AiService;
use Lsec\Integrations\AI\PriceTable;
use Lsec\Integrations\SearchConsole\SearchConsoleClient;
use Lsec\Integrations\SearchConsole\SearchConsoleException;
use Lsec\Integrations\SearchConsole\ServiceAccount;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Textfocus\TextfocusException;
use Lsec\Integrations\Textfocus\TextfocusService;
use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Workflow\RetryableException;
use Lsec\Workflow\RunContext;
use Lsec\Workflow\Selftest\SelftestAiClient;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\WaitException;
use Lsec\Workflow\WorkflowDefinition;

/**
 * Spezifikation 7.3 und 6.2 (Stufe C): Search Console abrufen (täglich 28 Tage, wöchentlich zusätzlich
 * 90 Tage), neue Keywords auf Orte und Namen prüfen (KI), Ausschlussgründe setzen (6.4), Textfocus-Volumina
 * für Keywords ohne Volumen nachholen (Tippfehlerprüfung vorher, im Rahmen des Tagesbudgets).
 */
final class KeywordSyncWorkflow {

	public const TYPE = 'keyword_sync';

	/**
	 * Grund für das Warten auf das Textfocus-Tagesbudget.
	 */
	public const WAIT_TEXTFOCUS = 'textfocus_budget';

	/**
	 * Option mit dem Zeitpunkt des letzten geplanten Starts.
	 */
	public const LAST_START = 'lsec_keyword_sync_last';

	/**
	 * Option mit dem Zeitpunkt des letzten 90-Tage-Abrufs.
	 */
	public const LAST_90 = 'lsec_gsc_last_90';

	/**
	 * KI-Stapel je Ablauf (je 100 Keywords).
	 */
	private const MAX_ANALYSIS_PASSES = 10;

	/**
	 * Credits, die der automatische Abgleich für Abfragen von Hand übrig lässt (z. B. verwandte Keywords).
	 */
	public const TEXTFOCUS_RESERVE = 5;

	/**
	 * Keywords je Filterdurchgang.
	 */
	private const FILTER_BATCH = 200;

	/**
	 * Definition.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function definition( Plugin $plugin ): WorkflowDefinition {
		return new WorkflowDefinition(
			self::TYPE,
			array(
				'gsc'     => new class( $plugin ) implements StepHandler {
					/**
					 * Konstruktor.
					 *
					 * @param Plugin $plugin Plugin.
					 */
					public function __construct( private readonly Plugin $plugin ) {}

					/**
					 * Search Console abrufen.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						return KeywordSyncWorkflow::gsc( $this->plugin, $context );
					}
				},
				'analyze' => new class( $plugin ) implements StepHandler {
					/**
					 * Konstruktor.
					 *
					 * @param Plugin $plugin Plugin.
					 */
					public function __construct( private readonly Plugin $plugin ) {}

					/**
					 * Orte und Namen erkennen.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						return KeywordSyncWorkflow::analyze( $this->plugin, $context );
					}
				},
				'filter'  => new class( $plugin ) implements StepHandler {
					/**
					 * Konstruktor.
					 *
					 * @param Plugin $plugin Plugin.
					 */
					public function __construct( private readonly Plugin $plugin ) {}

					/**
					 * Ausschlussgründe setzen.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						return KeywordSyncWorkflow::filter( $this->plugin, $context );
					}
				},
				'volumes' => new class( $plugin ) implements StepHandler {
					/**
					 * Konstruktor.
					 *
					 * @param Plugin $plugin Plugin.
					 */
					public function __construct( private readonly Plugin $plugin ) {}

					/**
					 * Textfocus-Volumina nachholen.
					 *
					 * @param RunContext $context Kontext.
					 */
					public function handle( RunContext $context ): StepResult {
						return KeywordSyncWorkflow::volumes( $this->plugin, $context );
					}
				},
			)
		);
	}

	/**
	 * Startet einen Abgleich, wenn keiner läuft.
	 *
	 * @param Plugin               $plugin  Plugin.
	 * @param array<string, mixed> $options gsc (bool), volumes (bool), reason (cron, manual, import, related);
	 *                                      für das Prüfskript keyword_ids, offline, stub_analysis.
	 * @return array{run_id: int, created: bool}|null Null, wenn schon einer aktiv ist.
	 */
	public static function start( Plugin $plugin, array $options = array() ): ?array {
		if ( $plugin->runs->has_active( self::TYPE ) ) {
			return null;
		}
		$input = $options + array(
			'gsc'     => true,
			'volumes' => true,
			'reason'  => 'manual',
		);
		return $plugin->engine->start( self::TYPE, null, null, $input, array( 'at' => microtime( true ) ) );
	}

	/**
	 * Für den Cronjob: einmal am Tag, wenn die Search Console eingerichtet ist oder Keywords vorhanden sind.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return int|null Gestarteter Ablauf.
	 */
	public static function maybe_start_scheduled( Plugin $plugin ): ?int {
		if ( time() - (int) get_option( self::LAST_START, 0 ) < 20 * HOUR_IN_SECONDS ) {
			return null;
		}
		if ( '' === (string) Settings::get( 'gsc_property' ) && 0 === ( new KeywordRepository() )->count() ) {
			return null;
		}
		$run = self::start( $plugin, array( 'reason' => 'cron' ) );
		if ( null === $run ) {
			return null;
		}
		update_option( self::LAST_START, time(), false );
		return $run['run_id'];
	}

	/**
	 * Darf ein auf das Textfocus-Budget wartender Ablauf weiter? (für wp lsec cron) Fünf Credits bleiben für
	 * Abfragen von Hand.
	 */
	public static function textfocus_budget_left(): bool {
		return (int) Settings::get( 'textfocus_daily_budget' ) - ( new ProviderCallRepository() )->credits_today( TextfocusService::PROVIDER ) >= 1 + self::TEXTFOCUS_RESERVE;
	}

	/**
	 * Schritt gsc.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function gsc( Plugin $plugin, RunContext $context ): StepResult {
		$property = (string) Settings::get( 'gsc_property' );
		if ( empty( $context->input['gsc'] ) ) {
			return StepResult::done( array( 'skipped' => 'nicht angefordert' ) );
		}
		if ( '' === $property || ! is_readable( GscCommand::credentials_file() ) ) {
			return StepResult::done( array( 'skipped' => 'Search Console nicht eingerichtet' ) );
		}
		$country = (string) Settings::get( 'gsc_country' );
		$days    = array( 28 );
		if ( time() - (int) get_option( self::LAST_90, 0 ) >= 7 * DAY_IN_SECONDS ) {
			$days[] = 90;
		}
		$out = array();
		try {
			$account = ServiceAccount::from_file( GscCommand::credentials_file(), $plugin->http );
			$client  = new SearchConsoleClient( $account, $plugin->http );
			foreach ( $days as $span ) {
				$period = SearchConsoleClient::period( $span, time() );
				$rows   = SearchConsoleClient::aggregate( $client->search_analytics( $property, $period['start'], $period['end'], 'all' === $country ? '' : $country ) );
				$saved  = ( new KeywordRepository() )->save_gsc( $property, $period, $rows, 'de_DE', null );
				$out[]  = array(
					'days'    => $span,
					'period'  => $period,
					'queries' => count( $rows ),
					'created' => $saved['created'],
				);
				if ( 90 === $span ) {
					update_option( self::LAST_90, time(), false );
				}
			}
		} catch ( SearchConsoleException $e ) {
			return StepResult::done(
				array(
					'skipped' => $e->error_code,
					'message' => $plugin->logger->redact( $e->getMessage() ),
				)
			);
		}
		return StepResult::done( array( 'fetched' => $out ) );
	}

	/**
	 * Schritt analyze: je Durchgang ein KI-Aufruf für bis zu 100 Keywords.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function analyze( Plugin $plugin, RunContext $context ): StepResult {
		if ( ! Settings::get( 'keyword_analysis' ) ) {
			return StepResult::done( array( 'skipped' => 'ausgeschaltet' ) );
		}
		$state = $context->state + array(
			'passes'   => 0,
			'analyzed' => 0,
			'found'    => 0,
		);
		$repo  = new KeywordRepository();
		$batch = $repo->pending_analysis( KeywordAnalyzer::BATCH, (array) ( $context->input['keyword_ids'] ?? array() ) );
		if ( array() === $batch ) {
			return StepResult::done( $state );
		}
		$area = ServiceArea::from_settings();
		try {
			$result = ( new KeywordAnalyzer( self::ai( $plugin, $context->input ) ) )->analyze( array_column( $batch, 'keyword' ), null === $area ? '' : $area->label, $context->run_id );
		} catch ( AiException $e ) {
			return StepResult::done( $state + array( 'skipped' => 'KI nicht verfügbar: ' . $plugin->logger->redact( $e->getMessage() ) ) );
		}
		$repo->save_analysis( array_column( $batch, 'normalized', 'id' ), $result['found'] );
		++$state['passes'];
		$state['analyzed'] += count( $batch );
		$state['found']    += count( $result['found'] );
		if ( count( $batch ) === KeywordAnalyzer::BATCH && $state['passes'] < self::MAX_ANALYSIS_PASSES ) {
			return StepResult::more( $state );
		}
		return StepResult::done( $state );
	}

	/**
	 * Schritt filter: Ausschlussgründe neu bestimmen, seitenweise.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 * @throws RetryableException Wenn Wikidata gerade zu viele Anfragen meldet.
	 */
	public static function filter( Plugin $plugin, RunContext $context ): StepResult {
		$state  = $context->state + array(
			'after'   => 0,
			'checked' => 0,
			'changed' => 0,
		);
		$repo   = new KeywordRepository();
		$rows   = $repo->for_filter( (int) $state['after'], self::FILTER_BATCH, (array) ( $context->input['keyword_ids'] ?? array() ) );
		$filter = self::exclusions( $plugin, $context->input )->filter();
		foreach ( $rows as $row ) {
			$verdict = $filter->evaluate( $row['keyword'], $row['analysis'] );
			if ( $verdict['exclusion'] !== $row['exclusion'] || $verdict['detail'] !== $row['detail'] ) {
				$repo->set_auto_exclusion( $row['id'], $verdict['exclusion'], $verdict['detail'] );
				++$state['changed'];
			}
			++$state['checked'];
			$state['after'] = $row['id'];
		}
		return count( $rows ) === self::FILTER_BATCH ? StepResult::more( $state ) : StepResult::done( $state );
	}

	/**
	 * Schritt volumes: ein tf_keyword-Aufruf je Durchgang, mit Mindestabstand als Verzögerung.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 * @throws WaitException Wenn das Textfocus-Tagesbudget erreicht ist.
	 * @throws RetryableException Bei vorübergehenden Fehlern außer dem Mindestabstand.
	 */
	public static function volumes( Plugin $plugin, RunContext $context ): StepResult {
		$state = $context->state + array(
			'calls'    => 0,
			'saved'    => 0,
			'typos'    => array(),
			'typo_ids' => array(),
		);
		if ( empty( $context->input['volumes'] ) || ! Settings::get( 'keyword_auto_volumes' ) || ! empty( $context->input['keyword_ids'] ) ) {
			return StepResult::done( $state + array( 'note' => 'nicht angefordert oder ausgeschaltet' ) );
		}
		try {
			$tf = TextfocusService::from_settings( $plugin->http, $plugin->secrets, new ProviderCallRepository() );
		} catch ( TextfocusException $e ) {
			return StepResult::done( $state + array( 'note' => 'Textfocus nicht eingerichtet' ) );
		}
		$repo    = new KeywordRepository();
		$skip    = array_map( 'intval', (array) $state['typo_ids'] );
		$size    = max( 1, (int) Settings::get( 'textfocus_batch_size' ) );
		$pending = array_diff_key( $repo->without_volume( $size + count( $skip ) ), array_flip( $skip ) );
		if ( array() === $pending ) {
			return StepResult::done( $state );
		}

		// Tippfehlerprüfung (6.7): mögliche Schreibvarianten gehen nicht an den bezahlten Anbieter.
		$checker = new TypoChecker( $repo->known_terms() );
		$ask     = array();
		foreach ( $pending as $id => $keyword ) {
			$suggestion = $checker->suggest( $keyword );
			if ( null !== $suggestion && KeywordNormalizer::normalize( $suggestion ) !== KeywordNormalizer::normalize( $keyword ) ) {
				$state['typos'][ $keyword ] = $suggestion;
				$skip[]                     = $id;
				continue;
			}
			$ask[ $id ] = $keyword;
			if ( count( $ask ) >= $size ) {
				break;
			}
		}
		$state['typo_ids'] = array_values( array_unique( $skip ) );
		if ( array() === $ask ) {
			return StepResult::more( $state );
		}

		if ( $tf->budget_left() < 1 + self::TEXTFOCUS_RESERVE ) {
			throw new WaitException( self::WAIT_TEXTFOCUS, 'Textfocus-Tagesbudget erreicht; der Abgleich holt die übrigen Volumina morgen nach.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		try {
			$result = $tf->keywords( array_values( $ask ), TextfocusService::default_lang(), false, false );
		} catch ( RetryableException $e ) {
			if ( 'rate_limited' === $e->error_code ) {
				return StepResult::more( $state, max( 1, (int) Settings::get( 'textfocus_min_interval' ) ) );
			}
			throw $e;
		} catch ( TextfocusException $e ) {
			if ( 'budget_exceeded' === $e->error_code ) {
				throw new WaitException( self::WAIT_TEXTFOCUS, $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			return StepResult::done( $state + array( 'note' => 'Textfocus: ' . $e->error_code ) );
		}
		$entries = array();
		foreach ( $ask as $id => $keyword ) {
			$entries[ $id ] = $result['keywords'][ KeywordNormalizer::normalize( $keyword ) ] ?? array(
				'volume'      => null,
				'cpc'         => null,
				'difficulty'  => null,
				'competition' => null,
			);
		}
		$state['saved'] += $repo->save_metrics_for_ids( TextfocusService::PROVIDER, $entries );
		$state['calls'] += $result['calls'];
		return StepResult::more( $state, $result['calls'] > 0 ? max( 1, (int) Settings::get( 'textfocus_min_interval' ) ) : 0 );
	}

	/**
	 * Filter-Kontext. Ortsabgleich nur mit Servicegebiet; offline (Prüfskript) nur bekannte Orte.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param array<string, mixed> $input  Startkontext.
	 */
	public static function exclusions( Plugin $plugin, array $input = array() ): KeywordExclusions {
		$area     = ServiceArea::from_settings();
		$resolver = empty( $input['offline'] ) ? new PlaceResolver( WikidataClient::for_site( $plugin->http ) ) : null;
		return new KeywordExclusions( $resolver, $area, get_locale() );
	}

	/**
	 * KI-Dienst; im Prüfskript eine Attrappe mit fester Antwort.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param array<string, mixed> $input  Startkontext.
	 */
	private static function ai( Plugin $plugin, array $input ): AiService {
		if ( isset( $input['stub_analysis'] ) ) {
			return new AiService( new SelftestAiClient( 0.0, (string) $input['stub_analysis'] ), new AiCallRepository(), new PriceTable(), AiClientFactory::budget() );
		}
		return $plugin->ai->service();
	}
}
