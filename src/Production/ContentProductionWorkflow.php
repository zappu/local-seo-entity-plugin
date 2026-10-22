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
use Lsec\Entities\ServiceArea;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiRequest;
use Lsec\Integrations\AI\AiService;
use Lsec\Research\Keywords\KeywordFilter;
use Lsec\Research\Keywords\KeywordNormalizer;
use Lsec\Topics\ClaimRepository;
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
				'create_brief'   => self::step( $plugin, 'create_brief' ),
				'generate_draft' => self::step( $plugin, 'generate_draft' ),
				'enrich'         => self::step( $plugin, 'enrich' ),
				'quality_check'  => self::step( $plugin, 'quality_check' ),
				'create_post'    => self::step( $plugin, 'create_post' ),
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
				throw new \RuntimeException( sprintf( 'Thema %d ähnelt Thema %d („%s“), zu dem es schon einen Entwurf gibt. Trotzdem schreiben: --allow-duplicate.', $topic_id, $twin['id'], $twin['title'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		if ( $again ) {
			( new TopicRepository() )->set_production( $topic_id, 'approved' );
		}
		$input = array(
			'topic_id' => $topic_id,
			'model'    => isset( $options['model'] ) && '' !== (string) $options['model'] ? (string) $options['model'] : null,
			'reason'   => (string) ( $options['reason'] ?? 'manual' ),
			'author'   => self::author( $topic ),
		);
		if ( ! empty( $options['stub'] ) ) {
			$input['stub'] = (string) $options['stub'];
		}
		$extra = $again || null !== $input['model'] || isset( $input['stub'] ) ? array( 'at' => microtime( true ) ) : array();
		return $plugin->engine->start( self::TYPE, 'topic', $topic_id, $input, $extra );
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
		$region_id = '' === (string) $topic['region'] ? 0 : self::place_id( (string) $topic['region'] );
		if ( $region_id > 0 && ! isset( $entities[ $region_id ] ) ) {
			$detail = ( new \Lsec\Entities\EntityRepository() )->detail( $region_id );
			if ( null !== $detail ) {
				$entities[ $region_id ] = array(
					'id'     => $region_id,
					'name'   => (string) $detail['canonical_name'],
					'type'   => (string) $detail['type'],
					'status' => (string) $detail['status'],
					'role'   => 'place',
				);
			}
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
				'link_targets'  => self::link_targets( $topic ),
				'categories'    => $categories,
				'style'         => StyleProfiler::prompt_text( null === $style ? null : (array) $style['profile'], false ),
				'style_full'    => StyleProfiler::prompt_text( null === $style ? null : (array) $style['profile'] ),
				'style_version' => null === $style ? null : (int) $style['version'],
				'length'        => self::length( $format ),
			)
		);
	}

	/**
	 * Schritt create_brief (8.5).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function create_brief( Plugin $plugin, RunContext $context ): StepResult {
		$ctx  = $context->output_of( 'gather_context' );
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
		$ctx   = $context->output_of( 'gather_context' );
		$brief = (array) $context->output_of( 'create_brief' )['brief'];
		$stub  = self::stub( $context, 'draft' );
		if ( null !== $stub ) {
			$data = $stub;
		} else {
			$response = self::ai( $plugin, $context->input )->structured( new AiRequest( 'generate_draft', DraftGenerator::system(), DraftGenerator::prompt( $ctx, $brief ), DraftGenerator::schema(), 'draft', false, 14000, 300 ), $context->run_id );
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
		$ctx   = $context->output_of( 'gather_context' );
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
		$ctx   = $context->output_of( 'gather_context' );
		$draft = (array) $context->output_of( 'enrich' )['draft'];

		$markup = BlockBuilder::for_site()->build( (array) $draft['content'] );
		$rules  = QualityChecker::check( $draft, $ctx, self::site_data( (int) $ctx['topic']['id'], (string) $ctx['topic']['format'], (string) $ctx['topic']['region'], $markup ) );

		$stub = self::stub( $context, 'review' );
		if ( null !== $stub ) {
			$data = $stub;
		} else {
			$response = self::ai( $plugin, $context->input )->structured( new AiRequest( 'quality_review', QualityReviewer::system(), QualityReviewer::prompt( $ctx, $draft ), QualityReviewer::schema(), 'review', false, 4000, 180 ), $context->run_id );
			$data     = (array) $response->data;
		}
		$review    = QualityReviewer::evaluate( $data, (float) Settings::get( 'quality_min_score' ), (float) Settings::get( 'quality_min_average' ) );
		$blocking  = array_merge( $rules['blocking'], $review['blocking'] );
		$warnings  = array_merge( $rules['warnings'], $review['warnings'] );
		$report    = array(
			'score'    => round( 100 * $review['average'], 1 ),
			'passed'   => array() === $blocking,
			'blocking' => $blocking,
			'warnings' => $warnings,
			'checks'   => $rules['checks'],
			'review'   => $review['scores'],
			'words'    => $rules['words'],
		);
		$report_id = ( new QualityReportRepository() )->save( null, $context->run_id, (string) $ctx['topic']['format'], $report );
		return StepResult::done(
			array(
				'report_id'      => $report_id,
				'passed'         => $report['passed'],
				'score'          => $report['score'],
				'blocking'       => $blocking,
				'warnings'       => $warnings,
				'used_claim_ids' => $rules['used_claim_ids'],
			)
		);
	}

	/**
	 * Schritt create_post: Entwurf anlegen (einmal je Ablauf, 7.4).
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function create_post( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$ctx     = $context->output_of( 'gather_context' );
		$draft   = (array) $context->output_of( 'enrich' )['draft'];
		$quality = $context->output_of( 'quality_check' );

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
			try {
				$result = ( new DraftWriter( BlockBuilder::for_site() ) )->create( $draft, (int) $context->input['author'], $context->run_id );
			} catch ( \RuntimeException $e ) {
				return StepResult::fail( 'post_failed', $e->getMessage() );
			}
			$post_id = (int) $result['post_id'];
		}
		$primary = array_values( array_map( static fn( array $e ): int => (int) $e['id'], array_filter( (array) $ctx['entities'], static fn( array $e ): bool => 'primary' === $e['role'] ) ) );
		update_post_meta( $post_id, '_lsec_topic_id', (int) $ctx['topic']['id'] );
		update_post_meta( $post_id, '_lsec_quality_report_id', (int) $quality['report_id'] );
		update_post_meta( $post_id, '_lsec_quality_passed', $quality['passed'] ? 1 : 0 );
		update_post_meta( $post_id, '_lsec_primary_entities', wp_slash( (string) wp_json_encode( $primary ) ) );
		update_post_meta( $post_id, '_lsec_used_claims', wp_slash( (string) wp_json_encode( array_values( (array) $quality['used_claim_ids'] ) ) ) );
		update_post_meta( $post_id, '_lsec_brief_id', (int) $context->output_of( 'create_brief' )['brief_id'] );
		( new QualityReportRepository() )->attach( (int) $quality['report_id'], $post_id );
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
		$ctx     = $context->output_of( 'gather_context' );
		$post    = $context->output_of( 'create_post' );
		$quality = $context->output_of( 'quality_check' );
		$post_id = (int) $post['post_id'];
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
	 * Entität eines Ortes über Name oder Schreibweise.
	 *
	 * @param string $name Ort.
	 */
	private static function place_id( string $name ): int {
		global $wpdb;
		$e          = Schema::table( $wpdb->prefix, 'entities' );
		$a          = Schema::table( $wpdb->prefix, 'entity_aliases' );
		$normalized = KeywordNormalizer::normalize( $name );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$e} e LEFT JOIN {$a} a ON a.entity_id = e.id WHERE e.type IN ('place', 'service_area') AND e.status IN ('confirmed', 'probable', 'candidate') AND ( e.normalized_name = %s OR a.normalized_alias = %s ) ORDER BY FIELD(e.status, 'confirmed', 'probable', 'candidate') LIMIT 1", $normalized, $normalized ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 */
	private static function ai( Plugin $plugin, array $input ): AiService {
		return $plugin->ai->service( null, $input['model'] ?? null );
	}
}
