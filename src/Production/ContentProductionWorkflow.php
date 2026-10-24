<?php
/**
 * Ablauf content_production (Spezifikation 7.3, Stufe E).
 *
 * @package Lsec
 */

namespace Lsec\Production;

use Lsec\Content\BlockBuilder;
use Lsec\Content\ContentIndex;
use Lsec\Content\ContentReader;
use Lsec\Content\DraftWriter;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\BusinessProfile;
use Lsec\Entities\EntityHome;
use Lsec\Entities\ServiceArea;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiService;
use Lsec\Research\Keywords\KeywordFilter;
use Lsec\Entities\EntityRepository;
use Lsec\Entities\PlaceName;
use Lsec\Research\SourceRepository;
use Lsec\Topics\ClaimRepository;
use Lsec\Topics\FactChecker;
use Lsec\Topics\SourceFetcher;
use Lsec\Topics\TopicRepository;
use Lsec\Workflow\RunContext;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\WorkflowDefinition;

/**
 * Schritte: load_topic → gather_context → create_brief → generate_draft → enrich → quality_check → create_post → finalize.
 *
 * Bei blockierenden Mängeln entsteht trotzdem ein Entwurf mit Prüfbericht; der Ablauf endet dann mit
 * `blocked`, und der Beitrag kommt für eine spätere automatische Veröffentlichung nie in Frage.
 */
final class ContentProductionWorkflow {

	public const TYPE = 'content_production';

	/**
	 * Längen je Beitragstyp in Wörtern (Einstellung draft_lengths überschreibt sie).
	 */
	public const LENGTHS = array(
		'local_landing' => array( 700, 1300 ),
		'article'       => array( 900, 1600 ),
		'guide'         => array( 1200, 2200 ),
		'seasonal'      => array( 700, 1300 ),
		'other'         => array( 700, 1500 ),
	);

	/**
	 * Definition.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function definition( Plugin $plugin ): WorkflowDefinition {
		return new WorkflowDefinition(
			self::TYPE,
			array(
				'load_topic'     => self::step( $plugin, 'load_topic' ),
				'gather_context' => self::step( $plugin, 'gather_context' ),
				'place_facts'    => self::step( $plugin, 'place_facts' ),
				'create_brief'   => self::step( $plugin, 'create_brief' ),
				'generate_draft' => self::step( $plugin, 'generate_draft' ),
				'enrich'         => self::step( $plugin, 'enrich' ),
				'quality_check'  => self::step( $plugin, 'quality_check' ),
				'revise'         => self::step( $plugin, 'revise' ),
				'create_post'    => self::step( $plugin, 'create_post' ),
				'featured_image' => self::step( $plugin, 'featured_image' ),
				'finalize'       => self::step( $plugin, 'finalize' ),
			)
		);
	}

	/**
	 * Schritt, der eine statische Methode dieser Klasse aufruft.
	 *
	 * @param Plugin $plugin Plugin.
	 * @param string $method Methode.
	 */
	private static function step( Plugin $plugin, string $method ): StepHandler {
		return new class( $plugin, $method ) implements StepHandler {
			/**
			 * Konstruktor.
			 *
			 * @param Plugin $plugin Plugin.
			 * @param string $method Methode von ContentProductionWorkflow.
			 */
			public function __construct( private readonly Plugin $plugin, private readonly string $method ) {}

			/**
			 * Schritt ausführen.
			 *
			 * @param RunContext $context Kontext.
			 */
			public function handle( RunContext $context ): StepResult {
				$callback = array( ContentProductionWorkflow::class, $this->method );
				return is_callable( $callback ) ? call_user_func( $callback, $this->plugin, $context ) : StepResult::fail( 'unknown_step', $this->method );
			}
		};
	}

	/**
	 * Startet die Produktion eines freigegebenen Themas.
	 *
	 * @param Plugin               $plugin   Plugin.
	 * @param int                  $topic_id Thema.
	 * @param array<string, mixed> $options  model (Vergleich), again (neuer Entwurf), reason; für Tests stub.
	 * @return array{run_id: int, created: bool}
	 * @throws DuplicateTopicException Wenn es zu einem sehr ähnlichen Thema schon einen Entwurf gibt.
	 * @throws \RuntimeException Wenn das Thema nicht freigegeben ist.
	 */
	public static function start( Plugin $plugin, int $topic_id, array $options = array() ): array {
		$topic = ( new TopicRepository() )->detail( $topic_id );
		if ( null === $topic ) {
			throw new \RuntimeException( 'Thema nicht gefunden: ' . $topic_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$again = ! empty( $options['again'] );
		if ( 'approved' !== $topic['status'] && ! ( $again && in_array( $topic['status'], array( 'produced', 'in_production' ), true ) ) ) {
			throw new \RuntimeException( sprintf( 'Thema %d ist nicht freigegeben (Status %s).', $topic_id, $topic['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		// Nachtrag 22.10. (0.7.1): Kein zweiter Beitrag zu einer Dublette eines schon geschriebenen Themas.
		if ( empty( $options['allow_duplicate'] ) ) {
			$written = array_values( array_filter( ( new TopicRepository() )->list( '', 500 ), static fn( array $t ): bool => in_array( $t['status'], array( 'in_production', 'produced' ), true ) && (int) $t['id'] !== $topic_id ) );
			$probe   = $topic + array( 'primary_keywords' => array_column( array_filter( (array) $topic['keywords'], static fn( array $k ): bool => 'primary' === $k['role'] ), 'keyword' ) );
			$twin    = \Lsec\Topics\TopicSimilarity::find( $probe, $written, \Lsec\Topics\TopicResearchWorkflow::profile_services() );
			if ( null !== $twin ) {
				throw new DuplicateTopicException( sprintf( 'Thema %d ähnelt Thema %d („%s“), zu dem es schon einen Entwurf gibt.', $topic_id, $twin['id'], $twin['title'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		if ( $again ) {
			( new TopicRepository() )->set_production( $topic_id, 'approved' );
		}
		$input = array(
			'topic_id' => $topic_id,
			'model'    => isset( $options['model'] ) && '' !== (string) $options['model'] ? (string) $options['model'] : null,
			'writer'   => isset( $options['writer'] ) && '' !== (string) $options['writer'] ? (string) $options['writer'] : null,
			'reason'   => (string) ( $options['reason'] ?? 'manual' ),
			'author'   => self::author( $topic ),
		);
		if ( ! empty( $options['stub'] ) ) {
			$input['stub'] = (string) $options['stub'];
		}
		$extra = $again || null !== $input['model'] || null !== $input['writer'] || isset( $input['stub'] ) ? array( 'at' => microtime( true ) ) : array();
		return $plugin->engine->start( self::TYPE, 'topic', $topic_id, $input, $extra );
	}

	/**
	 * Höchstzahl der Themen je Sammelauftrag.
	 */
	public const BATCH_MAX = 20;

	/**
	 * Sammelauftrag (Stufe F): mehrere freigegebene Themen auf einmal schreiben. Ohne $start nur die Schätzung
	 * (Spezifikation 8.1b: Schätzung vor dem Start eines Sammelauftrags). Die Abläufe arbeitet der Cronjob ab,
	 * höchstens max_parallel_runs gleichzeitig; das Tageslimit drafts_per_day gilt nur für den Cronjob.
	 *
	 * @param Plugin $plugin    Plugin.
	 * @param int[]  $topic_ids Themen.
	 * @param bool   $start     Starten statt nur schätzen.
	 * @return array{items: list<array{topic_id: int, title: string, ok: bool, note: string, run_id: int}>, count: int, per_draft: float, estimate: float, budget_left: float|null, started: list<int>}
	 */
	public static function batch( Plugin $plugin, array $topic_ids, bool $start ): array {
		$repo    = new TopicRepository();
		$items   = array();
		$started = array();
		$ids     = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $topic_ids ) ) ) ), 0, self::BATCH_MAX );
		foreach ( $ids as $id ) {
			$topic = $repo->detail( $id );
			$item  = array(
				'topic_id' => $id,
				'title'    => null === $topic ? '' : (string) $topic['title'],
				'ok'       => false,
				'note'     => '',
				'run_id'   => 0,
			);
			if ( null === $topic ) {
				$item['note'] = 'nicht gefunden';
			} elseif ( 'approved' !== (string) $topic['status'] ) {
				$item['note'] = 'nicht freigegeben (' . $topic['status'] . ')';
			} elseif ( ! $start ) {
				$item['ok']   = true;
				$item['note'] = 'wird geschrieben';
			} else {
				try {
					$run            = self::start(
						$plugin,
						$id,
						array( 'reason' => 'batch' )
					);
					$item['run_id'] = (int) $run['run_id'];
					$item['ok']     = (bool) $run['created'];
					$item['note']   = $run['created'] ? 'eingeplant' : 'läuft schon';
					if ( $run['created'] ) {
						$started[] = (int) $run['run_id'];
					}
				} catch ( \RuntimeException $e ) {
					$item['note'] = $e->getMessage();
				}
			}
			$items[] = $item;
		}
		$count  = count( array_filter( $items, static fn( array $i ): bool => $i['ok'] ) );
		$per    = self::cost_per_draft();
		$budget = \Lsec\Integrations\AI\AiClientFactory::budget()->status();
		$limit  = (float) $budget['month_limit'];
		return array(
			'items'       => $items,
			'count'       => $count,
			'per_draft'   => $per,
			'estimate'    => round( $count * $per, 2 ),
			'budget_left' => $limit > 0 ? round( max( 0.0, $limit - (float) $budget['month_spent'] ), 2 ) : null,
			'started'     => $started,
		);
	}

	/**
	 * Durchschnittliche Kosten der letzten zehn fertigen Entwürfe; ohne solche 0,20 USD (Messung in Stufe E).
	 */
	public static function cost_per_draft(): float {
		global $wpdb;
		$runs  = Schema::table( $wpdb->prefix, 'runs' );
		$calls = Schema::table( $wpdb->prefix, 'ai_calls' );
		$costs = (array) $wpdb->get_col( $wpdb->prepare( "SELECT (SELECT COALESCE(SUM(c.cost_estimate),0) FROM {$calls} c WHERE c.run_id = r.id) AS cost FROM {$runs} r WHERE r.type = %s AND r.status IN ('succeeded', 'blocked') ORDER BY r.id DESC LIMIT 10", self::TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$costs = array_values( array_filter( array_map( 'floatval', $costs ), static fn( float $c ): bool => $c > 0 ) );
		return array() === $costs ? 0.20 : round( array_sum( $costs ) / count( $costs ), 3 );
	}

	/**
	 * Cronjob: freigegebene Themen schreiben, höchstens drafts_per_day je Tag (Spezifikation 7.4).
	 *
	 * @param Plugin        $plugin  Plugin.
	 * @param string[]|null $skipped Ausgabe: übersprungene Themen mit Grund.
	 * @return int[] Gestartete Abläufe.
	 */
	public static function maybe_start_scheduled( Plugin $plugin, ?array &$skipped = null ): array {
		$skipped = array();
		if ( ! Settings::get( 'production_auto' ) ) {
			return array();
		}
		$left = (int) Settings::get( 'drafts_per_day' ) - self::started_today();
		if ( $left <= 0 ) {
			return array();
		}
		$started = array();
		foreach ( ( new TopicRepository() )->approved_ids( $left + 20 ) as $topic_id ) {
			if ( count( $started ) >= $left ) {
				break;
			}
			try {
				$run = self::start( $plugin, $topic_id, array( 'reason' => 'cron' ) );
			} catch ( \RuntimeException $e ) {
				$skipped[] = $e->getMessage();
				continue;
			}
			if ( $run['created'] ) {
				$started[] = $run['run_id'];
			} else {
				$skipped[] = sprintf( 'Thema %d: läuft schon (Ablauf %d).', $topic_id, $run['run_id'] );
			}
		}
		return $started;
	}

	/**
	 * Bricht offene Entwürfe eines Themas ab (Nachtrag 23.10., 0.7.8): Wird ein Thema abgelehnt oder zurückgesetzt,
	 * soll kein laufender Ablauf noch einen Entwurf schreiben (Lauf 131 zu Thema 43).
	 *
	 * @param Plugin $plugin   Plugin.
	 * @param int    $topic_id Thema.
	 * @return int[] Abgebrochene Abläufe.
	 */
	public static function cancel_for_topic( Plugin $plugin, int $topic_id ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'runs' );
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND subject_type = 'topic' AND subject_id = %d AND status IN ('queued', 'running', 'waiting')", self::TYPE, $topic_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $ids as $id ) {
			$plugin->runs->update_run(
				$id,
				array(
					'status'      => 'cancelled',
					'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
		return $ids;
	}

	/**
	 * Ist das Thema noch in Arbeit? Sonst (abgelehnt, zurückgesetzt) endet der Ablauf ohne Entwurf.
	 *
	 * @param RunContext $context Kontext.
	 */
	private static function topic_withdrawn( RunContext $context ): ?StepResult {
		$topic = ( new TopicRepository() )->detail( (int) $context->input['topic_id'] );
		if ( null === $topic || ! in_array( (string) $topic['status'], array( 'approved', 'in_production', 'produced' ), true ) ) {
			return StepResult::fail( 'topic_withdrawn', 'Das Thema ist nicht mehr freigegeben (Status ' . ( $topic['status'] ?? 'gelöscht' ) . ').' );
		}
		return null;
	}

	/**
	 * Heute (Zeitzone der Website) gestartete Produktionen.
	 */
	public static function started_today(): int {
		global $wpdb;
		$zone  = wp_timezone();
		$start = ( new \DateTimeImmutable( 'today', $zone ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$table = Schema::table( $wpdb->prefix, 'runs' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND created_at >= %s", self::TYPE, $start ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Schritt load_topic: Thema laden, Freigabe prüfen, auf in_production setzen.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function load_topic( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$id    = (int) $context->input['topic_id'];
		$repo  = new TopicRepository();
		$topic = $repo->detail( $id );
		if ( null === $topic ) {
			return StepResult::fail( 'topic_missing', 'Thema nicht gefunden: ' . $id );
		}
		if ( ! in_array( $topic['status'], array( 'approved', 'in_production' ), true ) ) {
			return StepResult::fail( 'topic_not_approved', sprintf( 'Thema %d ist nicht freigegeben (Status %s).', $id, $topic['status'] ) );
		}
		$repo->set_production( $id, 'in_production' );
		return StepResult::done(
			array(
				'topic_id' => $id,
				'title'    => (string) $topic['title'],
			)
		);
	}

	/**
	 * Schritt gather_context: Profil, Entitäten, geprüfte Aussagen, Keywords, Linkziele, Kategorien, Stil.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function gather_context( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$topic   = (array) ( new TopicRepository() )->detail( (int) $context->input['topic_id'] );
		$profile = ( new BusinessProfile() )->get();
		$area    = ServiceArea::from_settings();

		$entities = array();
		foreach ( (array) $topic['entities'] as $entity ) {
			$entities[ (int) $entity['id'] ] = array(
				'id'     => (int) $entity['id'],
				'name'   => (string) $entity['name'],
				'type'   => (string) $entity['type'],
				'status' => (string) $entity['status'],
				'role'   => (string) $entity['role'],
			);
		}
		foreach ( (array) ( $profile['services'] ?? array() ) as $service ) {
			$entities[ (int) $service['id'] ] ??= array(
				'id'     => (int) $service['id'],
				'name'   => (string) $service['name'],
				'type'   => 'service',
				'status' => 'confirmed',
				'role'   => 'profile',
			);
		}
		foreach ( '' === (string) $topic['region'] ? array() : self::place_ids( (string) $topic['region'] ) as $region_id => $place ) {
			$entities[ $region_id ] ??= array(
				'id'     => $region_id,
				'name'   => (string) $place['name'],
				'type'   => (string) $place['type'],
				'status' => (string) $place['status'],
				'role'   => 'place',
			);
		}

		$claims = ( new ClaimRepository() )->usable( (array) ( $topic['research_basis']['claim_ids'] ?? array() ), array_keys( $entities ), 40 );

		$keywords = array();
		foreach ( (array) $topic['keywords'] as $keyword ) {
			$keywords[] = array(
				'keyword' => (string) $keyword['keyword'],
				'role'    => (string) $keyword['role'],
				'volume'  => null === $keyword['volume'] ? null : (int) $keyword['volume'],
			);
		}

		$categories = array();
		foreach ( get_categories(
			array(
				'hide_empty' => false,
				'number'     => 60,
				'exclude'    => array( (int) get_option( 'default_category' ) ),
			)
		) as $term ) {
			$categories[] = array(
				'id'   => (int) $term->term_id,
				'name' => (string) $term->name,
			);
		}

		$style  = ( new StyleProfileRepository() )->active();
		$format = (string) $topic['format'];
		return StepResult::done(
			array(
				'topic'         => array(
					'id'            => (int) $topic['id'],
					'title'         => (string) $topic['title'],
					'intent'        => (string) $topic['intent'],
					'format'        => $format,
					'region'        => (string) $topic['region'],
					'rationale'     => (string) $topic['rationale'],
					'overlap_level' => (string) ( $topic['overlap']['level'] ?? 'none' ),
				),
				'company'       => array(
					'name'            => (string) ( $profile['organization']['name'] ?? get_bloginfo( 'name' ) ),
					'phone'           => (string) ( $profile['organization']['phone'] ?? '' ),
					'email'           => (string) ( $profile['organization']['email'] ?? '' ),
					'website'         => (string) ( $profile['organization']['website'] ?? home_url( '/' ) ),
					'description'     => trim( (string) ( $profile['organization']['description'] ?? '' ) ),
					'services'        => array_values( array_map( static fn( array $s ): string => (string) $s['name'], (array) ( $profile['services'] ?? array() ) ) ),
					'service_details' => array_values(
						array_filter(
							array_map(
								static fn( array $s ): array => array(
									'name'        => (string) $s['name'],
									'description' => trim( (string) $s['description'] ),
								),
								(array) ( $profile['services'] ?? array() )
							),
							static fn( array $s ): bool => '' !== $s['description']
						)
					),
					'locations'       => array_values(
						array_map(
							static fn( array $l ): array => array(
								'name'        => (string) $l['name'],
								'street'      => (string) $l['street'],
								'postal_code' => (string) $l['postal_code'],
								'city'        => (string) $l['city'],
								'phone'       => (string) $l['phone'],
							),
							(array) ( $profile['locations'] ?? array() )
						)
					),
				),
				'area'          => null === $area ? '' : $area->label . ( $area->radius_km > 0 ? ' und ' . $area->radius_km . ' km Umkreis' : '' ),
				'entities'      => array_values( $entities ),
				'claims'        => $claims,
				'keywords'      => $keywords,
				'link_targets'  => self::with_homes( self::link_targets( $topic ), $entities ),
				'own_pages'     => self::own_pages( $topic ),
				'categories'    => $categories,
				'style'         => StyleProfiler::prompt_text( null === $style ? null : (array) $style['profile'], false ),
				'style_full'    => StyleProfiler::prompt_text( null === $style ? null : (array) $style['profile'] ),
				'style_version' => null === $style ? null : (int) $style['version'],
				'length'        => self::length( $format ),
			)
		);
	}

	/**
	 * Schritt place_facts: Tatsachen zum Ort recherchieren, wenn eine lokale Seite weniger als zwei hat (0.7.13).
	 *
	 * Mehrere Durchgänge: Websuche, Strukturierung, Quellen abrufen (höchstens vier je Durchgang), prüfen und
	 * speichern. Ein Ort wird höchstens alle 30 Tage recherchiert (Option lsec_place_research).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function place_facts( Plugin $plugin, RunContext $context ): StepResult {
		$ctx    = $context->output_of( 'gather_context' );
		$topic  = (array) $ctx['topic'];
		$region = trim( (string) $topic['region'] );
		$local  = count( array_filter( (array) $ctx['claims'], static fn( array $c ): bool => PlaceName::about( $c, $region ) ) );
		$state  = $context->state;
		if ( array() === $state ) {
			$skip = null;
			if ( 'local_landing' !== (string) $topic['format'] || '' === $region ) {
				$skip = 'keine lokale Seite';
			} elseif ( $local >= 2 ) {
				$skip = $local . ' Tatsachen zu ' . $region . ' vorhanden';
			} elseif ( ! Settings::get( 'place_research' ) ) {
				$skip = 'ausgeschaltet (Einstellung place_research)';
			} elseif ( isset( $context->input['stub'] ) && array() === self::stub( $context, 'place' ) ) {
				$skip = 'Prüfskript ohne Ortsrecherche';
			} else {
				$done = (array) get_option( 'lsec_place_research', array() );
				$last = (int) ( $done[ PlaceName::key( $region ) ] ?? 0 );
				if ( $last > time() - 30 * DAY_IN_SECONDS ) {
					$skip = 'zuletzt recherchiert am ' . wp_date( 'd.m.Y', $last );
				}
			}
			if ( null !== $skip ) {
				return StepResult::done(
					array(
						'researched' => false,
						'reason'     => $skip,
						'claims'     => array(),
					)
				);
			}
		}

		$stub = self::stub( $context, 'place' );
		if ( ! isset( $state['research'] ) ) {
			if ( null !== $stub ) {
				$state['research'] = (string) ( $stub['research'] ?? '' );
				$state['sources']  = (array) ( $stub['sources'] ?? array() );
			} else {
				$response          = $plugin->ai->service()->search( 'place_facts_search', PlaceFacts::system(), PlaceFacts::question( $region, (string) $ctx['area'], (string) $topic['title'] ), $context->run_id );
				$state['research'] = $response->text;
				$state['sources']  = array() !== $response->sources ? $response->sources : \Lsec\Topics\TopicResearcher::sources_from_text( $response->text );
			}
			( new SourceRepository() )->save_all( (array) $state['sources'], 'ai_search' );
			return StepResult::more( $state );
		}

		if ( ! isset( $state['facts'] ) ) {
			if ( null !== $stub ) {
				$data = (array) ( $stub['result'] ?? array() );
			} else {
				$response = $plugin->ai->service()->structured( new AiRequest( 'place_facts', PlaceFacts::system() . "\nNutze ausschließlich das Rechercheergebnis. Erfinde keine Fakten und keine Quellen.", PlaceFacts::structure_prompt( $region, (string) $state['research'], (array) $state['sources'] ), PlaceFacts::schema(), 'facts', false, 3000, 120 ), $context->run_id );
				$data     = (array) $response->data;
			}
			$valid = PlaceFacts::validate( $data, $region, (array) $state['sources'] );
			$state = array(
				'research' => mb_substr( (string) $state['research'], 0, 4000 ),
				'sources'  => (array) $state['sources'],
				'facts'    => $valid['facts'],
				'dropped'  => $valid['dropped'],
				'texts'    => array(),
				'next'     => 0,
			);
			return StepResult::more( $state );
		}

		$sources = new SourceRepository();
		$fetcher = new SourceFetcher( $sources );
		$fetched = 0;
		$total   = count( $state['facts'] );
		while ( $state['next'] < $total ) {
			$url = (string) $state['facts'][ $state['next'] ]['source_url'];
			if ( ! isset( $state['texts'][ $url ] ) ) {
				if ( $fetched >= 4 ) {
					return StepResult::more( $state );
				}
				$source_id              = $sources->upsert( $url, '', 'ai_search' );
				$text                   = 0 === $source_id ? array(
					'text'   => null,
					'error'  => 'ungültige Adresse',
					'cached' => false,
				) : $fetcher->text( $source_id );
				$state['texts'][ $url ] = array(
					'source_id' => $source_id,
					'error'     => $text['error'],
				);
				if ( ! $text['cached'] ) {
					++$fetched;
				}
			}
			$state['facts'][ $state['next'] ]['source_id'] = (int) $state['texts'][ $url ]['source_id'];
			++$state['next'];
		}

		// Entität des Ortes: vorhandene (bestätigte zuerst) oder ein neuer Kandidat.
		$entities = new EntityRepository();
		$places   = self::place_ids( $region );
		$place_id = array() === $places ? (int) $entities->add( 'place', $region, 'candidate', 'ai_research' )['id'] : (int) array_key_first( $places );
		$names    = array( $region );
		foreach ( array_merge( array( $place_id ), array_keys( $places ) ) as $id ) {
			$detail = $entities->detail( (int) $id );
			if ( null !== $detail ) {
				$names = array_merge( $names, array( (string) $detail['canonical_name'] ), (array) $detail['aliases'] );
			}
		}
		$names   = array_values( array_unique( $names ) );
		$checked = array();
		foreach ( $state['facts'] as $fact ) {
			$source_id = (int) ( $fact['source_id'] ?? 0 );
			$text      = $source_id > 0 ? ( $sources->cached_content( $source_id )['text'] ?? null ) : null;
			$row       = $source_id > 0 ? $sources->find( $source_id ) : null;
			$page_ref  = null === $row ? (string) $fact['source_url'] : (string) $row['title'] . ' ' . (string) $row['url'];
			$checked[] = $fact + FactChecker::check( $fact, $names, $text, $state['texts'][ $fact['source_url'] ]['error'] ?? null, $page_ref, array( $region ) ) + array( 'entity_id' => $place_id );
		}
		$checked = FactChecker::contradictions( $checked );
		$claims  = new ClaimRepository();
		$counts  = array(
			'verified' => 0,
			'disputed' => 0,
			'rejected' => 0,
		);
		$lines   = array();
		foreach ( $checked as $fact ) {
			$claims->save( $place_id, (string) $fact['property'], (string) $fact['value'], (string) $fact['status'], (string) $fact['note'], (int) ( $fact['source_id'] ?? 0 ), (string) $fact['excerpt'], $fact['quality'] );
			$counts[ $fact['status'] ] = ( $counts[ $fact['status'] ] ?? 0 ) + 1;
			$lines[]                   = sprintf( '%s = %s: %s%s', $fact['property'], $fact['value'], $fact['status'], '' !== (string) $fact['note'] ? ' (' . $fact['note'] . ')' : '' );
		}
		$done                              = (array) get_option( 'lsec_place_research', array() );
		$done[ PlaceName::key( $region ) ] = time();
		update_option( 'lsec_place_research', $done, false );

		$ids    = array_values( array_unique( array_merge( array( $place_id ), array_keys( $places ) ) ) );
		$usable = array_values( array_filter( $claims->usable( array(), $ids, 20 ), static fn( array $c ): bool => PlaceName::about( $c, $region ) ) );
		return StepResult::done(
			array(
				'researched' => true,
				'place_id'   => $place_id,
				'counts'     => $counts,
				'facts'      => $lines,
				'dropped'    => (array) $state['dropped'],
				'claims'     => $usable,
			)
		);
	}

	/**
	 * Kontext für Briefing, Entwurf und Prüfung: gather_context mit den Ortsfakten aus place_facts.
	 *
	 * @param RunContext $context Kontext.
	 * @return array<string, mixed>
	 */
	private static function ctx( RunContext $context ): array {
		$ctx   = $context->output_of( 'gather_context' );
		$place = $context->output_of( 'place_facts' );
		// Recherchiert (jetzt oder in den letzten 30 Tagen): zu wenige Tatsachen sind dann nur eine Warnung.
		$ctx['place_searched'] = ! empty( $place['researched'] ) || str_starts_with( (string) ( $place['reason'] ?? '' ), 'zuletzt recherchiert' );
		// Steckbrief aus dem Briefing für die Prüfung (1.1.0); vor create_brief leer.
		$ctx['entity_brief'] = (array) ( ( (array) $context->output_of( 'create_brief' ) )['brief']['entity_brief'] ?? array() );
		$known               = array_map( static fn( array $c ): int => (int) $c['id'], (array) ( $ctx['claims'] ?? array() ) );
		foreach ( (array) ( $context->output_of( 'place_facts' )['claims'] ?? array() ) as $claim ) {
			if ( ! in_array( (int) $claim['id'], $known, true ) ) {
				$ctx['claims'][] = $claim;
				$known[]         = (int) $claim['id'];
			}
		}
		return $ctx;
	}

	/**
	 * Schritt create_brief (8.5).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function create_brief( Plugin $plugin, RunContext $context ): StepResult {
		$ctx  = self::ctx( $context );
		$stub = self::stub( $context, 'brief' );
		if ( null !== $stub ) {
			$data = $stub;
		} else {
			$response = self::ai( $plugin, $context->input )->structured( new AiRequest( 'create_brief', BriefWriter::system(), BriefWriter::prompt( $ctx ), BriefWriter::schema(), 'brief', false, 6000, 180 ), $context->run_id );
			$data     = (array) $response->data;
		}
		$valid = BriefWriter::validate( $data, $ctx );
		global $wpdb;
		$table   = Schema::table( $wpdb->prefix, 'briefs' );
		$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE topic_id = %d", (int) $ctx['topic']['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'topic_id'   => (int) $ctx['topic']['id'],
				'version'    => $version,
				'payload'    => (string) wp_json_encode( $valid['brief'], JSON_UNESCAPED_UNICODE ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return StepResult::done(
			array(
				'brief'    => $valid['brief'],
				'brief_id' => (int) $wpdb->insert_id,
				'version'  => $version,
				'dropped'  => $valid['dropped'],
			)
		);
	}

	/**
	 * Schritt generate_draft (8.6).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function generate_draft( Plugin $plugin, RunContext $context ): StepResult {
		$ctx   = self::ctx( $context );
		$brief = (array) $context->output_of( 'create_brief' )['brief'];
		$stub  = self::stub( $context, 'draft' );
		if ( null !== $stub ) {
			$data = $stub;
		} else {
			$response = self::ai( $plugin, $context->input, 'draft' )->structured( new AiRequest( 'generate_draft', DraftGenerator::system(), DraftGenerator::prompt( $ctx, $brief ), DraftGenerator::schema(), 'draft', false, 14000, 300 ), $context->run_id );
			$data     = (array) $response->data;
		}
		$normalized = DraftGenerator::normalize( $data, $ctx );
		if ( array() === $normalized['draft']['content'] ) {
			return StepResult::fail( 'empty_draft', 'Der Entwurf enthält keine Blöcke.' );
		}
		return StepResult::done( $normalized );
	}

	/**
	 * Schritt enrich: SEO-Angaben ergänzen, Schlagwörter, Handlungsaufforderung.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function enrich( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$ctx   = self::ctx( $context );
		$brief = (array) $context->output_of( 'create_brief' )['brief'];
		$draft = (array) $context->output_of( 'generate_draft' )['draft'];
		$notes = array();

		$primary = array_values( array_filter( (array) $ctx['keywords'], static fn( array $k ): bool => 'primary' === $k['role'] ) );
		if ( '' === (string) $draft['seo']['focus_keyword'] && array() !== $primary ) {
			$draft['seo']['focus_keyword'] = (string) $primary[0]['keyword'];
			$notes[]                       = 'Fokus-Keyword aus dem Thema';
		}
		if ( array() === (array) $draft['seo']['secondary_keywords'] ) {
			$draft['seo']['secondary_keywords'] = array_slice( array_values( array_map( static fn( array $k ): string => (string) $k['keyword'], array_filter( (array) $ctx['keywords'], static fn( array $k ): bool => 'secondary' === $k['role'] ) ) ), 0, 4 );
		}
		if ( '' === (string) $draft['seo']['title'] ) {
			$draft['seo']['title'] = mb_substr( (string) $draft['title'], 0, 60 );
			$notes[]               = 'SEO-Titel aus dem Titel';
		}
		if ( '' === (string) $draft['seo']['description'] ) {
			$draft['seo']['description'] = mb_substr( (string) $draft['excerpt'], 0, 160 );
			$notes[]                     = 'Meta-Beschreibung aus dem Auszug';
		}
		$draft['seo']['slug'] = sanitize_title( '' !== (string) $draft['seo']['slug'] ? (string) $draft['seo']['slug'] : (string) $draft['title'] );

		// Handlungsaufforderung: Fehlt ein Kontaktweg im Text, kommt die aus dem Briefing ans Ende.
		$text    = KeywordFilter::fold( DraftGenerator::plain_text( (array) $draft['content'] ) );
		$company = (array) $ctx['company'];
		$contact = false;
		foreach ( array( (string) $company['phone'], (string) $company['email'], 'kontakt' ) as $needle ) {
			$key = KeywordFilter::fold( $needle );
			if ( '' !== $key && str_contains( ' ' . $text . ' ', ' ' . $key ) ) {
				$contact = true;
			}
		}
		if ( ! $contact && '' !== trim( (string) $brief['cta'] ) ) {
			$draft['content'][] = array(
				'type' => 'paragraph',
				'text' => (string) $brief['cta'],
			);
			$notes[]            = 'Handlungsaufforderung ergänzt';
		}
		return StepResult::done(
			array(
				'draft' => $draft,
				'notes' => $notes,
			)
		);
	}

	/**
	 * Schritt quality_check: Regelprüfungen (10.1) und KI-Bewertung (10.2).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function quality_check( Plugin $plugin, RunContext $context ): StepResult {
		$ctx       = self::ctx( $context );
		$result    = self::assess( $plugin, $ctx, (array) $context->output_of( 'enrich' )['draft'], $context->input, self::stub( $context, 'review' ), $context->run_id );
		$report    = $result['report'];
		$report_id = ( new QualityReportRepository() )->save( null, $context->run_id, (string) $ctx['topic']['format'], $report );
		return StepResult::done(
			array(
				'report_id'      => $report_id,
				'passed'         => $report['passed'],
				'score'          => $report['score'],
				'blocking'       => $report['blocking'],
				'warnings'       => $report['warnings'],
				'used_claim_ids' => $result['used_claim_ids'],
			)
		);
	}

	/**
	 * Regelprüfungen und KI-Bewertung eines Entwurfs; auch für wp lsec production review.
	 *
	 * @param Plugin                    $plugin Plugin.
	 * @param array<string, mixed>      $ctx    Ergebnis von gather_context.
	 * @param array<string, mixed>      $draft  Entwurf (Ergebnis von enrich).
	 * @param array<string, mixed>      $input  Startkontext (model, reviewer).
	 * @param array<string, mixed>|null $stub   Bewertung statt KI (Prüfskript).
	 * @param int|null                  $run_id Ablauf für das KI-Protokoll; null = ohne Ablauf.
	 * @return array{report: array<string, mixed>, used_claim_ids: int[], model: string, cost: float|null}
	 */
	public static function assess( Plugin $plugin, array $ctx, array $draft, array $input, ?array $stub, ?int $run_id ): array {
		$markup = BlockBuilder::for_site()->build( (array) $draft['content'] );
		$rules  = QualityChecker::check( $draft, $ctx, self::site_data( (int) $ctx['topic']['id'], (string) $ctx['topic']['format'], (string) $ctx['topic']['region'], $markup ) );
		$model  = 'stub';
		$cost   = 0.0;
		if ( null !== $stub ) {
			$data = $stub;
		} else {
			$response = self::ai( $plugin, $input, 'review' )->structured( new AiRequest( 'quality_review', QualityReviewer::system(), QualityReviewer::prompt( $ctx, $draft ), QualityReviewer::schema(), 'review', false, 4000, 180 ), $run_id );
			$data     = (array) $response->data;
			$model    = $response->model;
			$cost     = $response->cost;
		}
		$review   = QualityReviewer::evaluate( $data, (float) Settings::get( 'quality_min_score' ), (float) Settings::get( 'quality_min_average' ) );
		$blocking = array_merge( $rules['blocking'], $review['blocking'] );
		return array(
			'report'         => array(
				'score'    => round( 100 * $review['average'], 1 ),
				'passed'   => array() === $blocking,
				'blocking' => $blocking,
				'warnings' => array_merge( $rules['warnings'], $review['warnings'] ),
				'checks'   => $rules['checks'],
				'review'   => $review['scores'],
				'words'    => $rules['words'],
			),
			'used_claim_ids' => array_map( 'intval', (array) $rules['used_claim_ids'] ),
			'model'          => $model,
			'cost'           => $cost,
		);
	}

	/**
	 * Schritt revise: einzelne beanstandete Stellen ersetzen und einmal neu prüfen (0.7.13, DraftReviser).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function revise( Plugin $plugin, RunContext $context ): StepResult {
		$quality = $context->output_of( 'quality_check' );
		$reason  = null;
		if ( ! empty( $quality['passed'] ) ) {
			$reason = 'bestanden';
		} elseif ( ! Settings::get( 'production_revise' ) ) {
			$reason = 'ausgeschaltet (Einstellung production_revise)';
		} elseif ( ! DraftReviser::fixable( (array) $quality['blocking'] ) ) {
			$reason = 'nicht automatisch behebbar: ' . implode( ', ', array_values( array_unique( array_diff( array_map( static fn( array $i ): string => (string) $i['code'], (array) $quality['blocking'] ), DraftReviser::FIXABLE ) ) ) );
		} elseif ( isset( $context->input['stub'] ) && array() === (array) self::stub( $context, 'revision' ) ) {
			$reason = 'Prüfskript ohne Nachbesserung';
		}
		if ( null !== $reason ) {
			return StepResult::done(
				array(
					'revised' => false,
					'reason'  => $reason,
				)
			);
		}
		$ctx   = self::ctx( $context );
		$draft = (array) $context->output_of( 'enrich' )['draft'];
		$stub  = self::stub( $context, 'revision' );
		if ( null !== $stub ) {
			$changes = (array) ( $stub['changes'] ?? array() );
		} else {
			$response = self::ai( $plugin, $context->input, 'draft' )->structured( new AiRequest( 'revise_draft', DraftReviser::system(), DraftReviser::prompt( $ctx, $draft, (array) $quality['blocking'] ), DraftReviser::schema(), 'revision', false, 3000, 180 ), $context->run_id );
			$data     = (array) $response->data;
			$changes  = (array) ( $data['changes'] ?? array() );
		}
		$fixed = DraftReviser::apply( $draft, $changes );
		if ( array() === $fixed['applied'] ) {
			return StepResult::done(
				array(
					'revised' => false,
					'reason'  => 'keine beanstandete Stelle im Text gefunden',
					'missed'  => $fixed['missed'],
				)
			);
		}
		$result    = self::assess( $plugin, $ctx, $fixed['draft'], $context->input, self::stub( $context, 'review' ), $context->run_id );
		$report    = $result['report'];
		$report_id = ( new QualityReportRepository() )->save( null, $context->run_id, (string) $ctx['topic']['format'], $report );
		return StepResult::done(
			array(
				'revised'        => true,
				'draft'          => $fixed['draft'],
				'applied'        => $fixed['applied'],
				'missed'         => $fixed['missed'],
				'before'         => array_values( array_map( static fn( array $i ): string => (string) $i['code'], (array) $quality['blocking'] ) ),
				'report_id'      => $report_id,
				'passed'         => $report['passed'],
				'score'          => $report['score'],
				'blocking'       => $report['blocking'],
				'warnings'       => $report['warnings'],
				'used_claim_ids' => $result['used_claim_ids'],
			)
		);
	}

	/**
	 * Entwurf für den Beitrag: nachgebessert oder aus enrich.
	 *
	 * @param RunContext $context Kontext.
	 * @return array<string, mixed>
	 */
	private static function final_draft( RunContext $context ): array {
		$revise = $context->output_of( 'revise' );
		return ! empty( $revise['revised'] ) ? (array) $revise['draft'] : (array) $context->output_of( 'enrich' )['draft'];
	}

	/**
	 * Prüfergebnis für den Beitrag: nach der Nachbesserung oder aus quality_check.
	 *
	 * @param RunContext $context Kontext.
	 * @return array<string, mixed>
	 */
	private static function final_quality( RunContext $context ): array {
		$revise = $context->output_of( 'revise' );
		return ! empty( $revise['revised'] ) ? $revise : $context->output_of( 'quality_check' );
	}

	/**
	 * Schritt create_post: Entwurf anlegen (einmal je Ablauf, 7.4).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function create_post( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$ctx     = self::ctx( $context );
		$draft   = self::final_draft( $context );
		$quality = self::final_quality( $context );
		$stop    = self::topic_withdrawn( $context );
		if ( null !== $stop ) {
			return $stop;
		}

		$existing = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_key'       => '_lsec_run_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => (string) $context->run_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		if ( array() !== $existing ) {
			$post_id = (int) $existing[0];
			$result  = array(
				'post_id' => $post_id,
				'reused'  => true,
			);
		} else {
			// Nachtrag 24.10.2026 (1.1.3): Beim Neuschreiben bekam der neue Entwurf die Titelform mit „-2“, weil der
			// alte Entwurf sie noch belegte. Unveröffentlichte ältere Entwürfe desselben Themas kommen deshalb in den
			// Papierkorb (wiederherstellbar; WordPress gibt die Titelform dabei frei). Veröffentlichte bleiben
			// unangetastet und werden im Bericht genannt.
			$previous = self::previous_posts( (int) $ctx['topic']['id'], $context->run_id );
			try {
				$result = ( new DraftWriter( BlockBuilder::for_site() ) )->create( $draft, (int) $context->input['author'], $context->run_id );
			} catch ( \RuntimeException $e ) {
				return StepResult::fail( 'post_failed', $e->getMessage() );
			}
			$post_id             = (int) $result['post_id'];
			$result['trashed']   = $previous['trashed'];
			$result['published'] = $previous['published'];
		}
		$primary = array_values( array_map( static fn( array $e ): int => (int) $e['id'], array_filter( (array) $ctx['entities'], static fn( array $e ): bool => 'primary' === $e['role'] ) ) );
		update_post_meta( $post_id, '_lsec_topic_id', (int) $ctx['topic']['id'] );
		update_post_meta( $post_id, '_lsec_quality_report_id', (int) $quality['report_id'] );
		update_post_meta( $post_id, '_lsec_quality_passed', $quality['passed'] ? 1 : 0 );
		update_post_meta( $post_id, '_lsec_primary_entities', wp_slash( (string) wp_json_encode( $primary ) ) );
		update_post_meta( $post_id, '_lsec_used_claims', wp_slash( (string) wp_json_encode( array_values( (array) $quality['used_claim_ids'] ) ) ) );
		update_post_meta( $post_id, '_lsec_brief_id', (int) $context->output_of( 'create_brief' )['brief_id'] );
		( new QualityReportRepository() )->attach( (int) $quality['report_id'], $post_id );
		if ( 'local_landing' === (string) $ctx['topic']['format'] && \Lsec\Content\LocalCategory::assign( $post_id ) ) {
			$result['local_category'] = \Lsec\Content\LocalCategory::term_id( false );
		}
		return StepResult::done( $result );
	}

	/**
	 * Ältere Beiträge desselben Themas: unveröffentlichte kommen in den Papierkorb, veröffentlichte werden nur
	 * gemeldet (1.1.3).
	 *
	 * @param int $topic_id Thema.
	 * @param int $run_id   Aktueller Ablauf.
	 * @return array{trashed: int[], published: int[]}
	 */
	public static function previous_posts( int $topic_id, int $run_id ): array {
		$out = array(
			'trashed'   => array(),
			'published' => array(),
		);
		if ( $topic_id <= 0 ) {
			return $out;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'publish', 'future', 'private' ),
				'meta_key'       => '_lsec_topic_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => (string) $topic_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'fields'         => 'ids',
				'posts_per_page' => 20,
			)
		);
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( (int) get_post_meta( $id, '_lsec_run_id', true ) === $run_id ) {
				continue;
			}
			if ( in_array( get_post_status( $id ), array( 'draft', 'pending' ), true ) ) {
				if ( false !== wp_trash_post( $id ) ) {
					$out['trashed'][] = $id;
				}
			} else {
				$out['published'][] = $id;
			}
		}
		return $out;
	}

	/**
	 * Schritt featured_image (Nachtrag 24.10.2026, 0.10.0): Beitragsbild aus der Mediathek, sonst KI-Bild.
	 *
	 * Erst werden neue Bilder ohne Beschreibung beschrieben (höchstens drei Durchgänge zu zehn), damit gerade
	 * hochgeladene Bilder mitzählen. Ein Fehler beim Bild hält den Entwurf nie auf: Der Schritt endet dann
	 * ohne Bild und nennt den Grund.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function featured_image( Plugin $plugin, RunContext $context ): StepResult {
		$post_id = (int) ( $context->output_of( 'create_post' )['post_id'] ?? 0 );
		$stop    = self::topic_withdrawn( $context );
		if ( null !== $stop ) {
			return $stop;
		}
		if ( $post_id <= 0 || 'off' === (string) Settings::get( 'image_mode' ) ) {
			return StepResult::done( array( 'source' => 'off' ) );
		}
		$options = array();
		if ( isset( $context->input['stub'] ) ) {
			// Prüfskript: ohne KI; nur mit Angabe image in der Testdatei.
			$stub = json_decode( (string) $context->input['stub'], true );
			if ( ! is_array( $stub ) || ! isset( $stub['image'] ) ) {
				return StepResult::done(
					array(
						'source' => 'none',
						'note'   => 'Testlauf ohne Bild.',
					)
				);
			}
			$options['stub'] = (array) $stub['image'];
		} else {
			$rounds  = (int) ( $context->state['rounds'] ?? 0 );
			$pending = $rounds < 3 && ! has_post_thumbnail( $post_id ) ? \Lsec\Media\ImageLibrary::pending_ids( \Lsec\Media\ImageLibrary::BATCH ) : array();
			if ( array() !== $pending ) {
				try {
					$ai       = \Lsec\Media\ImageLibrary::lazy( static fn() => \Lsec\Media\ImageDescriptionWorkflow::vision( $plugin ) );
					$business = \Lsec\Media\ImageLibrary::business();
					foreach ( $pending as $id ) {
						\Lsec\Media\ImageLibrary::describe( $id, $ai, $business, $context->run_id );
					}
				} catch ( \Throwable $e ) {
					$plugin->logger->warning(
						'Bildbeschreibung vor der Bildwahl fehlgeschlagen',
						array(
							'run_id' => $context->run_id,
							'error'  => $e->getMessage(),
						)
					);
					return StepResult::more( array( 'rounds' => 3 ) );
				}
				return StepResult::more( array( 'rounds' => $rounds + 1 ) );
			}
		}
		try {
			$result = \Lsec\Media\FeaturedImage::assign( $plugin, $post_id, $context->run_id, $options );
		} catch ( \Throwable $e ) {
			$note = $e instanceof \Lsec\Workflow\WaitException ? 'KI-Budget erreicht, kein Beitragsbild.' : 'Kein Beitragsbild: ' . $plugin->logger->redact( mb_substr( $e->getMessage(), 0, 300 ) );
			update_post_meta( $post_id, \Lsec\Media\FeaturedImage::SOURCE, 'none' );
			update_post_meta( $post_id, \Lsec\Media\FeaturedImage::NOTE, wp_slash( $note ) );
			$result = array(
				'source'   => 'none',
				'image_id' => 0,
				'note'     => $note,
				'cost'     => null,
			);
		}
		return StepResult::done( $result );
	}

	/**
	 * Schritt finalize: Thema auf produced, Bericht; bei Mängeln Status blocked.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function finalize( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$ctx     = self::ctx( $context );
		$post    = $context->output_of( 'create_post' );
		$quality = self::final_quality( $context );
		$post_id = (int) $post['post_id'];
		$stop    = self::topic_withdrawn( $context );
		if ( null !== $stop ) {
			return $stop;
		}
		( new TopicRepository() )->set_production( (int) $ctx['topic']['id'], 'produced', $post_id );
		$output = array(
			'post_id'  => $post_id,
			'passed'   => (bool) $quality['passed'],
			'score'    => $quality['score'],
			'cost_usd' => round( ( new AiCallRepository() )->cost_of_run( $context->run_id ), 4 ),
		);
		if ( ! $quality['passed'] ) {
			$codes = array_values( array_unique( array_map( static fn( array $i ): string => (string) $i['code'], (array) $quality['blocking'] ) ) );
			return StepResult::block( 'quality_blocked', 'Entwurf ' . $post_id . ' mit Mängeln: ' . implode( ', ', $codes ), $output );
		}
		return StepResult::done( $output );
	}

	/**
	 * Daten der Website für die Regelprüfung.
	 *
	 * @param int    $topic_id Thema.
	 * @param string $format   Beitragstyp.
	 * @param string $region   Ort.
	 * @param string $markup   Block-Markup des Entwurfs.
	 * @return array<string, mixed>
	 */
	private static function site_data( int $topic_id, string $format, string $region, string $markup ): array {
		global $wpdb;
		$titles = array_map( static fn( array $e ): string => (string) $e['title'], ContentIndex::entries( 500 ) );
		$local  = array();
		$focus  = array();
		$own    = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'publish', 'future' ),
				'meta_key'       => '_lsec_topic_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			)
		);
		$repo   = new TopicRepository();
		foreach ( $own as $post ) {
			if ( ! $post instanceof \WP_Post || (int) get_post_meta( $post->ID, '_lsec_topic_id', true ) === $topic_id ) {
				continue;
			}
			$titles[] = (string) $post->post_title;
			$other    = $repo->detail( (int) get_post_meta( $post->ID, '_lsec_topic_id', true ) );
			if ( '' !== $region && null !== $other && $format === (string) $other['format'] && KeywordFilter::fold( (string) $other['region'] ) !== KeywordFilter::fold( $region ) ) {
				$local[] = ContentReader::read( $post )['text'];
			}
		}
		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = 'rank_math_focus_keyword' AND p.post_status IN ('publish', 'draft', 'future', 'pending') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} x WHERE x.post_id = p.ID AND x.meta_key = '_lsec_topic_id' AND x.meta_value = %s) LIMIT 1000", (string) $topic_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $meta as $value ) {
			$first = trim( explode( ',', (string) $value )[0] );
			if ( '' !== $first ) {
				$focus[] = $first;
			}
		}
		$e       = Schema::table( $wpdb->prefix, 'entities' );
		$foreign = (array) $wpdb->get_col( "SELECT canonical_name FROM {$e} WHERE type = 'place' AND status = 'rejected' LIMIT 500" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'titles'          => array_values( array_unique( array_filter( $titles ) ) ),
			'local_texts'     => $local,
			'foreign_places'  => array_map( 'strval', $foreign ),
			'focus_keywords'  => $focus,
			'blocks_valid'    => self::blocks_valid( $markup ),
			'home_host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'seo_title'       => array( 30, 60 ),
			'seo_description' => array( 120, 160 ),
		);
	}

	/**
	 * Ist das Markup gültig? Jeder Inhalt steht in einem benannten Block, und serialize_blocks() ergibt dasselbe.
	 *
	 * @param string $markup Markup.
	 */
	public static function blocks_valid( string $markup ): bool {
		if ( '' === trim( $markup ) ) {
			return false;
		}
		$blocks = parse_blocks( $markup );
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] && '' !== trim( (string) $block['innerHTML'] ) ) {
				return false;
			}
		}
		return trim( serialize_blocks( $blocks ) ) === trim( $markup );
	}

	/**
	 * Linkziele: veröffentlichte Inhalte, nach gemeinsamen Wörtern mit Thema, Keywords und Ort.
	 *
	 * @param array<string, mixed> $topic Thema.
	 * @return array<int, array{post_id: int, title: string, url: string}>
	 */
	private static function link_targets( array $topic ): array {
		$words = array();
		$text  = (string) $topic['title'] . ' ' . (string) $topic['region'] . ' ' . implode( ' ', array_map( static fn( array $k ): string => (string) $k['keyword'], (array) $topic['keywords'] ) );
		foreach ( explode( ' ', KeywordFilter::fold( $text ) ) as $word ) {
			if ( mb_strlen( $word ) >= 5 ) {
				$words[ mb_substr( $word, 0, 7 ) ] = true;
			}
		}
		$scored = array();
		foreach ( ContentIndex::entries( 500 ) as $entry ) {
			if ( (int) $entry['post_id'] <= 0 ) {
				continue;
			}
			$hay   = ' ' . KeywordFilter::fold( $entry['title'] . ' ' . str_replace( array( '-', '/' ), ' ', (string) wp_parse_url( $entry['url'], PHP_URL_PATH ) ) );
			$score = 0;
			foreach ( array_keys( $words ) as $word ) {
				if ( str_contains( $hay, ' ' . $word ) ) {
					++$score;
				}
			}
			if ( $score > 0 ) {
				$scored[] = array(
					'post_id' => (int) $entry['post_id'],
					'title'   => (string) $entry['title'],
					'url'     => (string) $entry['url'],
					'score'   => $score,
				);
			}
		}
		usort( $scored, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );
		return array_map(
			static fn( array $s ): array => array(
				'post_id' => $s['post_id'],
				'title'   => $s['title'],
				'url'     => $s['url'],
			),
			array_slice( $scored, 0, 12 )
		);
	}

	/**
	 * Linkziele mit den Hauptseiten der Entitäten des Themas (Nachtrag 24.10.2026, 1.2.0): vorn, mit home_for
	 * (Name) und home_entity_id. Ist die Seite schon Linkziel, wird sie nur gekennzeichnet.
	 *
	 * @param array<int, array<string, mixed>> $targets  Linkziele.
	 * @param array<int, array<string, mixed>> $entities Entitäten des Kontexts (ID → Entität).
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_homes( array $targets, array $entities ): array {
		$homes = EntityHome::homes( array_keys( $entities ) );
		$front = array();
		foreach ( $homes as $id => $home ) {
			$mark = array(
				'home_for'       => (string) $entities[ $id ]['name'],
				'home_entity_id' => (int) $id,
			);
			foreach ( $targets as $i => $target ) {
				if ( (int) $target['post_id'] === $home['post_id'] ) {
					// Eine Seite kann Hauptseite mehrerer Entitäten sein; die Haupt-Entität geht vor.
					if ( ! isset( $target['home_entity_id'] ) || 'primary' === ( $entities[ $id ]['role'] ?? '' ) ) {
						$targets[ $i ] = array_merge( $target, $mark );
					}
					continue 2;
				}
			}
			$front[ $home['post_id'] ] = $home + $mark;
		}
		return array_slice( array_merge( array_values( $front ), $targets ), 0, 16 );
	}

	/**
	 * Eigene Seiten zur Leistung des Themas mit ihrem Text (Nachtrag 22.10., 0.7.6): Was der Betrieb tut und wie er
	 * arbeitet, steht auf seiner Website; die KI erhält es als belegte Angabe des Unternehmens, ohne dass jemand
	 * Beschreibungen pflegen muss. Gewählt werden höchstens zwei veröffentlichte Seiten oder Produkte (vor
	 * Blogbeiträgen), deren Titel oder Pfad die meisten Wörter aus Titel und Keywords des Themas enthält
	 * (ohne den Ort), je bis 3000 Zeichen.
	 *
	 * Nachtrag 23.10. (0.8.2): Viele Websites beschreiben ihre Leistungen gemeinsam auf einer Seite „Leistungen“
	 * (Zahnarztpraxis: Prophylaxe, Parodontologie und Implantologie auf einer Seite). Finden sich so nicht zwei
	 * Seiten, werden Seiten und Produkte auch nach ihrem Text gewählt; der Ausschnitt beginnt dann kurz vor der
	 * ersten passenden Stelle. Lange zusammengesetzte Wörter zählen auch mit ihrem Ende („Zahnprophylaxe“ findet
	 * „Prophylaxe“).
	 *
	 * @param array<string, mixed> $topic Thema.
	 * @return array<int, array{post_id: int, title: string, url: string, text: string}>
	 */
	private static function own_pages( array $topic ): array {
		$words  = self::topic_words( $topic );
		$scored = array();
		$rest   = array();
		foreach ( ContentIndex::entries( 500 ) as $entry ) {
			if ( (int) $entry['post_id'] <= 0 ) {
				continue;
			}
			$page  = in_array( (string) $entry['kind'], array( 'page', 'product' ), true ) ? 1 : 0;
			$hay   = ' ' . KeywordFilter::fold( $entry['title'] . ' ' . str_replace( array( '-', '/' ), ' ', (string) wp_parse_url( $entry['url'], PHP_URL_PATH ) ) );
			$score = self::word_score( $hay, $words, false );
			if ( $score > 0 ) {
				$scored[] = $entry + array(
					'score' => $score,
					'page'  => $page,
					'body'  => 2,
				);
			} elseif ( 1 === $page ) {
				$rest[] = $entry;
			}
		}
		$pages = count( array_filter( $scored, static fn( array $e ): bool => 1 === $e['page'] ) );
		if ( $pages < 2 && array() !== $words ) {
			foreach ( array_slice( $rest, 0, 60 ) as $entry ) {
				$post = get_post( (int) $entry['post_id'] );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				$score = self::word_score( ' ' . KeywordFilter::fold( ContentReader::read( $post )['text'] ), $words, true );
				if ( $score >= min( 2, count( $words ) ) ) {
					$scored[] = $entry + array(
						'score' => $score,
						'page'  => 1,
						'body'  => 1,
					);
				}
			}
		}
		usort( $scored, static fn( array $a, array $b ): int => array( $b['page'], $b['body'], $b['score'] ) <=> array( $a['page'], $a['body'], $a['score'] ) );
		$out = array();
		foreach ( array_slice( $scored, 0, 2 ) as $entry ) {
			$post = get_post( (int) $entry['post_id'] );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$body = trim( ContentReader::read( $post )['text'] );
			if ( '' === $body ) {
				continue;
			}
			$out[] = array(
				'post_id' => (int) $entry['post_id'],
				'title'   => (string) $entry['title'],
				'url'     => (string) $entry['url'],
				'text'    => self::excerpt_around( $body, $words, 3000 ),
			);
		}
		return $out;
	}

	/**
	 * Suchwörter eines Themas: Wörter ab fünf Buchstaben aus Titel und Keywords ohne den Ort, gekürzt auf sieben
	 * Buchstaben; bei Wörtern ab zehn Buchstaben zusätzlich die letzten acht (Wortende zusammengesetzter Wörter).
	 *
	 * @param array<string, mixed> $topic Thema.
	 * @return array<int, string[]> Je Wort die Varianten.
	 */
	public static function topic_words( array $topic ): array {
		$region = explode( ' ', KeywordFilter::fold( (string) ( $topic['region'] ?? '' ) ) );
		$text   = (string) ( $topic['title'] ?? '' ) . ' ' . implode( ' ', array_map( static fn( array $k ): string => (string) $k['keyword'], (array) ( $topic['keywords'] ?? array() ) ) );
		$words  = array();
		foreach ( explode( ' ', (string) preg_replace( '/[^a-z0-9 ]+/', ' ', KeywordFilter::fold( $text ) ) ) as $word ) {
			if ( mb_strlen( $word ) < 5 || in_array( $word, $region, true ) ) {
				continue;
			}
			$variants = array( mb_substr( $word, 0, 7 ) );
			if ( mb_strlen( $word ) >= 10 ) {
				$variants[] = mb_substr( $word, -8 );
			}
			$words[ $variants[0] ] = $variants;
		}
		return array_values( $words );
	}

	/**
	 * Wie viele Suchwörter kommen vor? Wortanfang, mit $tails auch Wortende zusammengesetzter Wörter (nur im Text,
	 * nicht in Titeln: „…reinigung“ träfe dort jede Reinigungsseite).
	 *
	 * @param string               $hay   Gefalteter Text mit führendem Leerzeichen.
	 * @param array<int, string[]> $words Suchwörter mit Varianten.
	 * @param bool                 $tails Wortenden mitzählen.
	 */
	public static function word_score( string $hay, array $words, bool $tails ): int {
		$score = 0;
		foreach ( $words as $variants ) {
			if ( str_contains( $hay, ' ' . $variants[0] ) || ( $tails && isset( $variants[1] ) && str_contains( $hay, $variants[1] ) ) ) {
				++$score;
			}
		}
		return $score;
	}

	/**
	 * Ausschnitt ab kurz vor der ersten passenden Stelle.
	 *
	 * @param string               $body   Text.
	 * @param array<int, string[]> $words  Suchwörter.
	 * @param int                  $length Länge.
	 */
	public static function excerpt_around( string $body, array $words, int $length ): string {
		if ( mb_strlen( $body ) <= $length ) {
			return $body;
		}
		$folded = KeywordFilter::fold( $body );
		$first  = null;
		foreach ( $words as $variants ) {
			foreach ( $variants as $variant ) {
				$pos = mb_strpos( $folded, $variant );
				if ( false !== $pos && ( null === $first || $pos < $first ) ) {
					$first = $pos;
				}
			}
		}
		// Das Falten verlängert Umlaute (ä → ae); die Stelle liegt im Original höchstens etwas weiter vorn.
		$start = null === $first ? 0 : max( 0, (int) ( $first * mb_strlen( $body ) / max( 1, mb_strlen( $folded ) ) ) - 300 );
		return mb_substr( $body, $start, $length );
	}

	/**
	 * Entitäten eines Ortes über Name oder Schreibweise, auch „Gemeinde …“ und Servicegebiet (PlaceName).
	 *
	 * @param string $name Ort.
	 * @return array<int, array{name: string, type: string, status: string}> Nach ID, bestätigte zuerst.
	 */
	private static function place_ids( string $name ): array {
		global $wpdb;
		$e    = Schema::table( $wpdb->prefix, 'entities' );
		$a    = Schema::table( $wpdb->prefix, 'entity_aliases' );
		$last = \Lsec\Entities\PlaceName::search_word( $name );
		if ( '' === $last ) {
			return array();
		}
		// Vorauswahl über das letzte Wort des Namens, die Entscheidung trifft PlaceName::same.
		$like = '%' . $wpdb->esc_like( $last ) . '%';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.canonical_name, e.type, e.status, a.alias FROM {$e} e LEFT JOIN {$a} a ON a.entity_id = e.id WHERE e.type IN ('place', 'service_area') AND e.status IN ('confirmed', 'probable', 'candidate') AND ( e.normalized_name LIKE %s OR a.normalized_alias LIKE %s ) ORDER BY FIELD(e.status, 'confirmed', 'probable', 'candidate'), e.id LIMIT 200", $like, $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $row ) {
			if ( \Lsec\Entities\PlaceName::same( $name, (string) $row['canonical_name'] ) || \Lsec\Entities\PlaceName::same( $name, (string) ( $row['alias'] ?? '' ) ) ) {
				$out[ (int) $row['id'] ] ??= array(
					'name'   => (string) $row['canonical_name'],
					'type'   => (string) $row['type'],
					'status' => (string) $row['status'],
				);
			}
		}
		return $out;
	}

	/**
	 * Länge des Beitragstyps (Einstellung draft_lengths oder Standard).
	 *
	 * @param string $format Beitragstyp.
	 * @return array{min: int, max: int}
	 */
	public static function length( string $format ): array {
		$stored = Settings::get( 'draft_lengths' );
		$pair   = is_array( $stored ) && isset( $stored[ $format ] ) && is_array( $stored[ $format ] ) ? $stored[ $format ] : ( self::LENGTHS[ $format ] ?? self::LENGTHS['other'] );
		$min    = max( 200, (int) ( $pair[0] ?? 700 ) );
		return array(
			'min' => $min,
			'max' => max( $min, (int) ( $pair[1] ?? 1500 ) ),
		);
	}

	/**
	 * Autor: Einstellung draft_author, sonst wer das Thema freigegeben hat, sonst der erste Administrator.
	 *
	 * @param array<string, mixed> $topic Thema.
	 */
	private static function author( array $topic ): int {
		$id = (int) Settings::get( 'draft_author' );
		if ( $id > 0 && false !== get_userdata( $id ) ) {
			return $id;
		}
		$decided = (int) ( $topic['decided_by'] ?? 0 );
		if ( $decided > 0 && false !== get_userdata( $decided ) ) {
			return $decided;
		}
		$current = get_current_user_id();
		if ( $current > 0 ) {
			return $current;
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		return array() === $admins ? 0 : (int) $admins[0];
	}

	/**
	 * Antwort aus der Testdatei statt KI (Prüfskript).
	 *
	 * @param RunContext $context Kontext.
	 * @param string     $key     brief, draft oder review.
	 * @return array<string, mixed>|null
	 */
	private static function stub( RunContext $context, string $key ): ?array {
		if ( ! isset( $context->input['stub'] ) ) {
			return null;
		}
		$stub = json_decode( (string) $context->input['stub'], true );
		return is_array( $stub ) && is_array( $stub[ $key ] ?? null ) ? $stub[ $key ] : array();
	}

	/**
	 * KI-Dienst; mit model aus dem Start (Modellvergleich) gilt dieses Modell.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param array<string, mixed> $input  Startkontext.
	 * @param string               $task   brief, draft oder review.
	 */
	private static function ai( Plugin $plugin, array $input, string $task = 'brief' ): AiService {
		$settings = array(
			'draft'  => (string) Settings::get( 'ai_writing_model' ),
			'review' => (string) Settings::get( 'ai_review_model' ),
		);
		return $plugin->ai->service( null, self::model_for( $task, $input, $settings ) );
	}

	/**
	 * Modell eines Aufrufs; null = Hauptmodell.
	 *
	 * Nachtrag 23.10. (0.7.9): Der Text macht etwa die Hälfte der Kosten aus (Ablauf 127: Briefing 0,062,
	 * Text 0,117, Bewertung 0,044 USD) und entscheidet, wie natürlich der Beitrag klingt. Text (draft) und
	 * Bewertung (review) haben deshalb eigene Modelle; das Briefing läuft mit dem Hauptmodell.
	 * Reihenfolge: --writer bzw. Prüfmodell des Befehls review, dann --model, dann die Einstellung
	 * (ai_writing_model, ai_review_model), dann das Hauptmodell.
	 *
	 * @param string                $task     brief, draft oder review.
	 * @param array<string, mixed>  $input    Startkontext mit model, writer und reviewer.
	 * @param array<string, string> $settings Einstellungen je Aufgabe (draft, review).
	 */
	public static function model_for( string $task, array $input, array $settings ): ?string {
		$own = array(
			'draft'  => 'writer',
			'review' => 'reviewer',
		);
		$key = $own[ $task ] ?? '';
		if ( '' !== $key && '' !== trim( (string) ( $input[ $key ] ?? '' ) ) ) {
			return trim( (string) $input[ $key ] );
		}
		if ( '' !== trim( (string) ( $input['model'] ?? '' ) ) ) {
			return trim( (string) $input['model'] );
		}
		$setting = trim( (string) ( $settings[ $task ] ?? '' ) );
		return '' !== $setting ? $setting : null;
	}
}
