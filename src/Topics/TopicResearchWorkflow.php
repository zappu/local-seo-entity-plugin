<?php
/**
 * Ablauf topic_research.
 *
 * @package Lsec
 */

namespace Lsec\Topics;

use Lsec\Content\ContentIndex;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\BusinessProfile;
use Lsec\Entities\EntityRepository;
use Lsec\Entities\ServiceArea;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Integrations\AI\AiClientFactory;
use Lsec\Integrations\AI\AiService;
use Lsec\Integrations\AI\PriceTable;
use Lsec\Research\Keywords\KeywordFilter;
use Lsec\Research\Keywords\KeywordNormalizer;
use Lsec\Research\Keywords\KeywordRepository;
use Lsec\Research\Keywords\KeywordSyncWorkflow;
use Lsec\Research\SourceRepository;
use Lsec\Workflow\RunContext;
use Lsec\Workflow\Selftest\SelftestAiClient;
use Lsec\Workflow\StepHandler;
use Lsec\Workflow\StepResult;
use Lsec\Workflow\WorkflowDefinition;

/**
 * Spezifikation 7.3 und 8.4 (Stufe D): collect_keywords → research (Websuche, dann Strukturierung) →
 * verify_facts (8.4a) → propose_topics (Priorität 6.4, Überschneidung). Die Themen stehen danach als
 * Vorschläge zur Freigabe auf der Themen-Seite; Themen, die eine bestehende Seite schon abdeckt, als
 * „bestehende Seite verbessern“ (discarded).
 */
final class TopicResearchWorkflow {

	public const TYPE = 'topic_research';

	/**
	 * Quellen je Durchgang der Faktenprüfung (je bis zu 20 s Abruf).
	 */
	private const SOURCES_PER_PASS = 4;

	/**
	 * Keine neue Recherche für denselben Schwerpunkt innerhalb dieser Tage (8.1b, ohne --force).
	 */
	public const CACHE_DAYS = 14;

	/**
	 * Definition.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function definition( Plugin $plugin ): WorkflowDefinition {
		return new WorkflowDefinition(
			self::TYPE,
			array(
				'collect_keywords' => self::step( $plugin, 'collect' ),
				'research'         => self::step( $plugin, 'research' ),
				'verify_facts'     => self::step( $plugin, 'verify' ),
				'propose_topics'   => self::step( $plugin, 'propose' ),
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
			 * @param string $method Methode von TopicResearchWorkflow.
			 */
			public function __construct( private readonly Plugin $plugin, private readonly string $method ) {}

			/**
			 * Schritt ausführen.
			 *
			 * @param RunContext $context Kontext.
			 */
			public function handle( RunContext $context ): StepResult {
				$callback = array( TopicResearchWorkflow::class, $this->method );
				return is_callable( $callback ) ? call_user_func( $callback, $this->plugin, $context ) : StepResult::fail( 'unknown_step', $this->method );
			}
		};
	}

	/**
	 * Startet eine Recherche.
	 *
	 * @param Plugin               $plugin  Plugin.
	 * @param array<string, mixed> $options service, region, count, web_max_results, research_model, force; für Tests stub_research, offline.
	 * @return array{run_id: int, created: bool}
	 * @throws \RuntimeException Wenn derselbe Schwerpunkt vor kurzem recherchiert wurde (ohne force).
	 */
	public static function start( Plugin $plugin, array $options ): array {
		$focus = array(
			'service' => trim( (string) ( $options['service'] ?? '' ) ),
			'region'  => trim( (string) ( $options['region'] ?? '' ) ),
		);
		if ( empty( $options['force'] ) && empty( $options['stub_research'] ) ) {
			$recent = self::recent_run( $focus );
			if ( null !== $recent ) {
				throw new \RuntimeException( sprintf( 'Dieser Schwerpunkt wurde am %s recherchiert (Ablauf %d). Neue Recherche erst nach %d Tagen oder mit „trotzdem“ (--force).', $recent['date'], $recent['id'], self::CACHE_DAYS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		$input = array(
			'focus'           => $focus,
			'count'           => max( 1, min( 10, (int) ( $options['count'] ?? Settings::get( 'topic_count' ) ) ) ),
			'web_max_results' => isset( $options['web_max_results'] ) ? max( 1, min( 10, (int) $options['web_max_results'] ) ) : null,
			'research_model'  => isset( $options['research_model'] ) && '' !== (string) $options['research_model'] ? (string) $options['research_model'] : null,
		);
		foreach ( array( 'stub_research', 'offline' ) as $key ) {
			if ( ! empty( $options[ $key ] ) ) {
				$input[ $key ] = $options[ $key ];
			}
		}
		return $plugin->engine->start( self::TYPE, null, null, $input, array( 'at' => microtime( true ) ) );
	}

	/**
	 * Letzte erfolgreiche Recherche mit demselben Schwerpunkt in den letzten CACHE_DAYS Tagen.
	 *
	 * @param array{service: string, region: string} $focus Schwerpunkt.
	 * @return array{id: int, date: string}|null
	 */
	private static function recent_run( array $focus ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'runs' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, context, created_at FROM {$table} WHERE type = %s AND status = 'succeeded' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) ORDER BY id DESC", self::TYPE, self::CACHE_DAYS ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$key   = KeywordFilter::fold( $focus['service'] ) . '|' . KeywordFilter::fold( $focus['region'] );
		foreach ( $rows as $row ) {
			$context = json_decode( (string) $row['context'], true );
			if ( ! is_array( $context ) || ! empty( $context['stub_research'] ) ) {
				continue;
			}
			$other = (array) ( $context['focus'] ?? array() );
			if ( KeywordFilter::fold( (string) ( $other['service'] ?? '' ) ) . '|' . KeywordFilter::fold( (string) ( $other['region'] ?? '' ) ) === $key ) {
				return array(
					'id'   => (int) $row['id'],
					'date' => wp_date( 'd.m.Y', (int) strtotime( (string) $row['created_at'] . ' UTC' ) ),
				);
			}
		}
		return null;
	}

	/**
	 * Schritt collect_keywords: Profil, Keywords ohne Ausschluss, Entitäten und bestehende Inhalte.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function collect( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$focus   = (array) ( $context->input['focus'] ?? array() );
		$profile = ( new BusinessProfile() )->get();
		$area    = ServiceArea::from_settings();
		$repo    = new KeywordRepository();

		$rows = array();
		foreach ( array_filter( array( (string) ( $focus['service'] ?? '' ), (string) ( $focus['region'] ?? '' ) ) ) as $term ) {
			// Mit Schwerpunkt: zuerst Keywords mit einem seiner Wörter (ab 4 Buchstaben).
			$words = preg_split( '/\s+/u', KeywordNormalizer::normalize( $term ) );
			foreach ( false === $words ? array() : $words as $word ) {
				if ( mb_strlen( $word ) >= 4 ) {
					$rows = array_merge(
						$rows,
						$repo->table(
							array(
								'status' => 'active',
								'search' => mb_substr( $word, 0, 8 ),
								'limit'  => 60,
							)
						)['rows']
					);
				}
			}
		}
		if ( count( $rows ) < 40 ) {
			$rows = array_merge(
				$rows,
				$repo->table(
					array(
						'status' => 'active',
						'limit'  => 60,
					)
				)['rows'],
				$repo->table(
					array(
						'status' => 'active',
						'sort'   => 'impressions',
						'limit'  => 40,
					)
				)['rows']
			);
		}
		$keywords = array();
		foreach ( $rows as $row ) {
			if ( isset( $keywords[ $row['id'] ] ) || count( $keywords ) >= 80 ) {
				continue;
			}
			$keywords[ $row['id'] ] = array(
				'id'          => (int) $row['id'],
				'keyword'     => (string) $row['keyword'],
				'volume'      => $row['search_volume'],
				'impressions' => $row['impressions'],
				'position'    => null === $row['position'] ? null : round( (float) $row['position'], 1 ),
				'page'        => null === $row['page_url'] ? '' : (string) $row['page_url'],
			);
		}

		$entities = self::entities();
		$content  = array();
		foreach ( ContentIndex::entries( 300 ) as $entry ) {
			$content[] = $entry['title'] . ' – ' . (string) wp_parse_url( $entry['url'], PHP_URL_PATH );
		}

		return StepResult::done(
			array(
				'focus'    => array(
					'service' => (string) ( $focus['service'] ?? '' ),
					'region'  => (string) ( $focus['region'] ?? '' ),
				),
				'company'  => (string) ( $profile['organization']['name'] ?? get_bloginfo( 'name' ) ),
				'services' => array_values( array_map( static fn( array $s ): string => (string) $s['name'], (array) ( $profile['services'] ?? array() ) ) ),
				'area'     => null === $area ? '' : $area->label . ( $area->radius_km > 0 ? ' und ' . $area->radius_km . ' km Umkreis' : '' ),
				'keywords' => array_values( $keywords ),
				'entities' => $entities,
				'content'  => array_slice( $content, 0, 150 ),
			)
		);
	}

	/**
	 * Schritt research: erster Durchgang Websuche, zweiter Durchgang Strukturierung mit dem Kontext.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function research( Plugin $plugin, RunContext $context ): StepResult {
		$collected = $context->output_of( 'collect_keywords' );
		$stub      = isset( $context->input['stub_research'] ) ? json_decode( (string) $context->input['stub_research'], true ) : null;
		$state     = $context->state;

		if ( ! isset( $state['research'] ) ) {
			if ( is_array( $stub ) ) {
				$state['research'] = (string) ( $stub['research'] ?? '' );
				$state['sources']  = (array) ( $stub['sources'] ?? array() );
				$state['cost']     = 0.0;
			} else {
				$response          = self::ai( $plugin, $context->input )->search( 'topic_research_search', TopicResearcher::system(), TopicResearcher::question( $collected ), $context->run_id, $context->input['web_max_results'] ?? null );
				$state['research'] = $response->text;
				$state['sources']  = $response->sources;
				$state['cost']     = $response->cost;
				$state['model']    = $response->model;
			}
			$state['source_ids'] = ( new SourceRepository() )->save_all( (array) $state['sources'], 'ai_search' );
			return StepResult::more( $state );
		}

		$count = (int) ( $context->input['count'] ?? 5 );
		if ( is_array( $stub ) ) {
			$data = (array) ( $stub['result'] ?? array() );
		} else {
			$response = self::ai( $plugin, $context->input )->structured(
				new \Lsec\Integrations\AI\AiRequest( 'topic_research', TopicResearcher::system() . "\nNutze für Tatsachen ausschließlich das Rechercheergebnis. Erfinde keine Fakten und keine Quellen.", TopicResearcher::structure_prompt( $collected, (string) $state['research'], (array) $state['sources'], $count ), TopicResearcher::schema(), 'topics', false, 8000, 180 ),
				$context->run_id
			);
			$data     = $response->data;
		}
		$valid = TopicResearcher::validate( $data, $collected, (array) $state['sources'] );
		return StepResult::done(
			array(
				'research'   => mb_substr( (string) $state['research'], 0, 20000 ),
				'sources'    => (array) $state['sources'],
				'source_ids' => (array) ( $state['source_ids'] ?? array() ),
				'model'      => (string) ( $state['model'] ?? '' ),
			) + $valid
		);
	}

	/**
	 * Schritt verify_facts: Quellen abrufen, Zitat und Bezug prüfen, Aussagen speichern.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function verify( Plugin $plugin, RunContext $context ): StepResult {
		unset( $plugin );
		$research = $context->output_of( 'research' );
		$state    = $context->state;
		if ( ! isset( $state['facts'] ) ) {
			$facts = array();
			foreach ( (array) ( $research['topics'] ?? array() ) as $index => $topic ) {
				foreach ( (array) $topic['facts'] as $fact ) {
					$facts[] = $fact + array( 'topic' => $index );
				}
			}
			$state = array(
				'facts' => $facts,
				'texts' => array(),
				'next'  => 0,
			);
		}

		$sources = new SourceRepository();
		$fetcher = new SourceFetcher( $sources );
		$fetched = 0;
		$total   = count( $state['facts'] );
		while ( $state['next'] < $total ) {
			$fact = $state['facts'][ $state['next'] ];
			$url  = (string) $fact['source_url'];
			if ( ! isset( $state['texts'][ $url ] ) ) {
				if ( $fetched >= self::SOURCES_PER_PASS ) {
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
					'ok'        => null !== $text['text'],
				);
				if ( ! $text['cached'] ) {
					++$fetched;
				}
			}
			$state['facts'][ $state['next'] ]['source_id'] = $state['texts'][ $url ]['source_id'];
			++$state['next'];
		}

		// Prüfen, Widersprüche erkennen, speichern.
		$entities = new EntityRepository();
		$checked  = array();
		foreach ( $state['facts'] as $index => $fact ) {
			$entity_id = self::subject_entity( $entities, (string) $fact['subject'], (string) $fact['subject_type'] );
			$names     = array_merge( array( (string) $fact['subject'] ), self::names( $entity_id ) );
			$text      = 0 < (int) $fact['source_id'] ? ( $sources->cached_content( (int) $fact['source_id'] )['text'] ?? null ) : null;
			$result    = FactChecker::check( $fact, $names, $text, $state['texts'][ $fact['source_url'] ]['error'] ?? null );
			$checked[] = $fact + $result + array( 'entity_id' => $entity_id );
		}
		$checked = FactChecker::contradictions( $checked );
		$claims  = new ClaimRepository();
		$counts  = array(
			'verified' => 0,
			'disputed' => 0,
			'rejected' => 0,
		);
		foreach ( $checked as $index => $fact ) {
			$checked[ $index ]['claim_id'] = $claims->save( (int) $fact['entity_id'], (string) $fact['property'], (string) $fact['value'], (string) $fact['status'], (string) $fact['note'], (int) $fact['source_id'], (string) $fact['excerpt'], $fact['quality'] );
			++$counts[ $fact['status'] ];
			unset( $checked[ $index ]['quote'] );
		}
		return StepResult::done(
			array(
				'facts'   => $checked,
				'counts'  => $counts,
				'sources' => count( $state['texts'] ),
				'failed'  => count( array_filter( $state['texts'], static fn( array $t ): bool => ! $t['ok'] ) ),
			)
		);
	}

	/**
	 * Schritt propose_topics: off_topic setzen, Überschneidung und Priorität bestimmen, Themen speichern.
	 *
	 * @param Plugin     $plugin  Plugin.
	 * @param RunContext $context Kontext.
	 */
	public static function propose( Plugin $plugin, RunContext $context ): StepResult {
		$collected = $context->output_of( 'collect_keywords' );
		$research  = $context->output_of( 'research' );
		$verified  = $context->output_of( 'verify_facts' );
		$keywords  = array();
		foreach ( (array) $collected['keywords'] as $k ) {
			$keywords[ (int) $k['id'] ] = $k;
		}
		$repo = new KeywordRepository();
		foreach ( (array) ( $research['excluded'] ?? array() ) as $item ) {
			$repo->set_auto_exclusion( (int) $item['keyword_id'], 'off_topic', '' !== $item['note'] ? $item['note'] : null );
		}

		$services = array();
		foreach ( (array) $collected['entities'] as $e ) {
			if ( 'service' === $e['type'] && ! empty( $e['profile'] ) ) {
				$services[ (int) $e['id'] ] = (string) $e['name'];
			}
		}
		$content    = ContentIndex::entries( 500 );
		$exclusions = empty( $context->input['offline'] ) ? KeywordSyncWorkflow::exclusions( $plugin ) : KeywordSyncWorkflow::exclusions( $plugin, array( 'offline' => true ) );
		$scorer     = new PriorityScorer( self::weights() );

		$topics = (array) ( $research['topics'] ?? array() );
		$sums   = array();
		foreach ( $topics as $index => $topic ) {
			$sums[ $index ] = self::demand_sum( array_merge( $topic['primary_keyword_ids'], $topic['secondary_keyword_ids'] ), $keywords );
		}
		$max_volume      = max( array_merge( array( 0 ), array_map( static fn( array $s ): int => (int) $s['volume'], $sums ) ) );
		$max_impressions = max( array_merge( array( 0 ), array_map( static fn( array $s ): int => (int) $s['impressions'], $sums ) ) );

		$saved = array();
		foreach ( $topics as $index => $topic ) {
			$facts   = array_values( array_filter( (array) ( $verified['facts'] ?? array() ), static fn( array $f ): bool => (int) $f['topic'] === (int) $index ) );
			$overlap = self::overlap( $topic, $keywords, $content );
			$demand  = null !== $sums[ $index ]['volume'] ? PriorityScorer::demand( $sums[ $index ]['volume'], $max_volume ) : PriorityScorer::demand( $sums[ $index ]['impressions'], $max_impressions );
			$local   = self::local( $topic, $keywords, $facts, $exclusions );
			$score   = $scorer->score(
				array(
					'relevance' => self::relevance( $topic, $keywords, $services ),
					'demand'    => $demand,
					'gap'       => 'high' === $overlap['level'] ? 0.0 : ( 'partial' === $overlap['level'] ? 0.5 : 1.0 ),
					'local'     => $local['value'],
					'season'    => null,
				)
			);
			$status  = 'high' === $overlap['level'] ? 'discarded' : 'proposed';
			$id      = ( new TopicRepository() )->save(
				$topic + array(
					'score'           => $score['score'],
					'score_breakdown' => $score + array(
						'insufficient_data' => $topic['insufficient_data'],
						'local_note'        => $local['note'],
					),
					'research_basis'  => array(
						'keyword_ids' => array_merge( $topic['primary_keyword_ids'], $topic['secondary_keyword_ids'] ),
						'claim_ids'   => array_values( array_filter( array_map( static fn( array $f ): int => (int) ( $f['claim_id'] ?? 0 ), $facts ) ) ),
						'source_ids'  => array_values( array_unique( array_filter( array_map( static fn( array $f ): int => (int) ( $f['source_id'] ?? 0 ), $facts ) ) ) ),
						'focus'       => $collected['focus'],
					),
					'overlap'         => $overlap,
					'status'          => $status,
				),
				$context->run_id
			);
			$saved[] = array(
				'id'     => $id,
				'title'  => $topic['title'],
				'score'  => $score['score'],
				'status' => $status,
			);
		}
		return StepResult::done(
			array(
				'topics'    => $saved,
				'off_topic' => count( (array) ( $research['excluded'] ?? array() ) ),
			)
		);
	}

	/**
	 * Überschneidung mit bestehenden Seiten (6.4): eigene Seiten, die für ein Keyword des Themas ranken, und
	 * Inhalte, deren Titel ein Haupt-Keyword vollständig enthält. „high“, wenn ein Haupt-Keyword so abgedeckt ist.
	 *
	 * @param array<string, mixed>             $topic    Thema.
	 * @param array<int, array<string, mixed>> $keywords Keywords aus dem Kontext.
	 * @param array<int, array<string, mixed>> $content  Inhalte aus dem Index.
	 * @return array{level: string, items: array<int, array<string, mixed>>}
	 */
	public static function overlap( array $topic, array $keywords, array $content ): array {
		$items = array();
		$level = 'none';
		foreach ( array( 'primary', 'secondary' ) as $role ) {
			foreach ( (array) $topic[ $role . '_keyword_ids' ] as $id ) {
				$k = $keywords[ (int) $id ] ?? null;
				if ( null === $k ) {
					continue;
				}
				$local = '' === (string) $k['page'] ? null : self::own_url( (string) $k['page'] );
				if ( null !== $local ) {
					$items[] = array(
						'url'     => $local,
						'post_id' => url_to_postid( $local ),
						'keyword' => (string) $k['keyword'],
						'reason'  => sprintf( 'rankt für „%s“ (Position %s)', $k['keyword'], null === $k['position'] ? '–' : number_format( (float) $k['position'], 1, ',', '' ) ),
						'primary' => 'primary' === $role,
					);
					$level   = 'primary' === $role ? 'high' : ( 'none' === $level ? 'partial' : $level );
				}
				if ( 'primary' !== $role ) {
					continue;
				}
				$words = array_filter( explode( ' ', KeywordFilter::fold( (string) $k['keyword'] ) ), static fn( string $w ): bool => mb_strlen( $w ) >= 3 );
				if ( count( $words ) < 2 ) {
					continue;
				}
				foreach ( $content as $entry ) {
					$title = ' ' . KeywordFilter::fold( (string) $entry['title'] ) . ' ';
					$all   = array() === array_filter( $words, static fn( string $w ): bool => ! str_contains( $title, ' ' . $w ) );
					if ( $all ) {
						$items[] = array(
							'url'     => (string) $entry['url'],
							'post_id' => (int) $entry['post_id'],
							'keyword' => (string) $k['keyword'],
							'reason'  => sprintf( 'Titel „%s“ enthält „%s“', $entry['title'], $k['keyword'] ),
							'primary' => true,
						);
						$level   = 'high';
					}
				}
			}
		}
		$unique = array();
		foreach ( $items as $item ) {
			$unique[ $item['url'] . '|' . $item['keyword'] ] = $item;
		}
		return array(
			'level' => $level,
			'items' => array_values( $unique ),
		);
	}

	/**
	 * Eigene Seite? Die Search Console kann für die Live-Website gelten, während das Plugin auf einer Kopie
	 * (Staging) läuft. Darum zählen der eigene Host und der Host der Property; zurück kommt die Adresse auf
	 * dieser Website.
	 *
	 * @param string $url Adresse aus der Search Console.
	 * @return string|null Adresse auf dieser Website oder null für fremde Seiten.
	 */
	public static function own_url( string $url ): ?string {
		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$hosts   = array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$prop    = (string) Settings::get( 'gsc_property' );
		$hosts[] = str_starts_with( $prop, 'sc-domain:' ) ? strtolower( substr( $prop, 10 ) ) : strtolower( (string) wp_parse_url( $prop, PHP_URL_HOST ) );
		$bare    = static fn( string $h ): string => (string) preg_replace( '/^www\./', '', $h );
		if ( '' === $host || ! in_array( $bare( $host ), array_map( $bare, array_filter( $hosts ) ), true ) ) {
			return null;
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return home_url( '' !== $path ? $path : '/' ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Geschäftsrelevanz: Bezug zu Leistungen aus dem Unternehmensprofil.
	 *
	 * @param array<string, mixed>             $topic    Thema.
	 * @param array<int, array<string, mixed>> $keywords Keywords.
	 * @param array<int, string>               $services Profil-Leistungen (ID → Name).
	 */
	private static function relevance( array $topic, array $keywords, array $services ): float {
		if ( array() !== array_intersect( (array) $topic['primary_entity_ids'], array_keys( $services ) ) ) {
			return 1.0;
		}
		$text = ' ' . KeywordFilter::fold( $topic['title'] . ' ' . implode( ' ', array_map( static fn( $id ): string => (string) ( $keywords[ (int) $id ]['keyword'] ?? '' ), (array) $topic['primary_keyword_ids'] ) ) ) . ' ';
		foreach ( $services as $name ) {
			$first = explode( ' ', KeywordFilter::fold( $name ) )[0] ?? '';
			if ( mb_strlen( $first ) >= 5 && str_contains( $text, ' ' . mb_substr( $first, 0, 8 ) ) ) {
				return 0.8;
			}
		}
		if ( array() !== array_intersect( (array) $topic['secondary_entity_ids'], array_keys( $services ) ) ) {
			return 0.6;
		}
		return 0.3;
	}

	/**
	 * Lokaler Bezug: Ort im Servicegebiet, „in der Nähe“-Anfragen, geprüfte Tatsachen zu Orten.
	 *
	 * @param array<string, mixed>                      $topic      Thema.
	 * @param array<int, array<string, mixed>>          $keywords   Keywords.
	 * @param array<int, array<string, mixed>>          $facts      Geprüfte Tatsachen des Themas.
	 * @param \Lsec\Research\Keywords\KeywordExclusions $exclusions Ortsabgleich.
	 * @return array{value: float, note: string}
	 */
	private static function local( array $topic, array $keywords, array $facts, $exclusions ): array {
		$region = trim( (string) $topic['region'] );
		if ( '' !== $region ) {
			try {
				$in = $exclusions->place_in_area( $region );
			} catch ( \Lsec\Workflow\RetryableException $e ) {
				$in = null;
			}
			if ( true === $in ) {
				return array(
					'value' => 1.0,
					'note'  => $region . ' liegt im Servicegebiet',
				);
			}
			if ( false === $in ) {
				return array(
					'value' => 0.0,
					'note'  => $region . ' liegt außerhalb des Servicegebiets',
				);
			}
			return array(
				'value' => 0.5,
				'note'  => $region . ': Lage nicht geklärt',
			);
		}
		foreach ( array_merge( (array) $topic['primary_keyword_ids'], (array) $topic['secondary_keyword_ids'] ) as $id ) {
			if ( str_contains( KeywordFilter::fold( (string) ( $keywords[ (int) $id ]['keyword'] ?? '' ) ), 'nahe' ) || str_contains( KeywordFilter::fold( (string) ( $keywords[ (int) $id ]['keyword'] ?? '' ) ), 'naehe' ) ) {
				return array(
					'value' => 0.7,
					'note'  => 'Anfragen mit „in der Nähe“',
				);
			}
		}
		$places = array_filter( $facts, static fn( array $f ): bool => 'place' === ( $f['subject_type'] ?? '' ) && 'verified' === ( $f['status'] ?? '' ) );
		return array(
			'value' => array() !== $places ? 0.4 : 0.0,
			'note'  => array() !== $places ? 'geprüfte Tatsachen zu Orten, aber kein Ort als Schwerpunkt' : 'ohne Ortsbezug',
		);
	}

	/**
	 * Summe von Volumen und Impressionen der Keywords eines Themas.
	 *
	 * @param int[]                            $ids      Keyword-IDs.
	 * @param array<int, array<string, mixed>> $keywords Keywords.
	 * @return array{volume: int|null, impressions: int|null}
	 */
	private static function demand_sum( array $ids, array $keywords ): array {
		$volume      = null;
		$impressions = null;
		foreach ( $ids as $id ) {
			$k = $keywords[ (int) $id ] ?? null;
			if ( null === $k ) {
				continue;
			}
			if ( null !== $k['volume'] ) {
				$volume = (int) $volume + (int) $k['volume'];
			}
			if ( null !== $k['impressions'] ) {
				$impressions = (int) $impressions + (int) $k['impressions'];
			}
		}
		return array(
			'volume'      => $volume,
			'impressions' => $impressions,
		);
	}

	/**
	 * Entitäten für den Kontext: Leistungen und Unternehmen aus dem Profil, Orte im Servicegebiet,
	 * bestätigte Begriffe und Produkte, nach Fundstellen.
	 *
	 * @return array<int, array{id: int, name: string, type: string, profile: bool}>
	 */
	private static function entities(): array {
		global $wpdb;
		$e = Schema::table( $wpdb->prefix, 'entities' );
		$m = Schema::table( $wpdb->prefix, 'mentions' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.canonical_name AS name, e.type, e.origin = %s AS profile, (SELECT COUNT(*) FROM {$m} x WHERE x.entity_id = e.id) AS n
				FROM {$e} e WHERE e.status IN ('confirmed', 'probable') AND e.type IN ('organization', 'brand', 'place', 'service_area', 'service', 'product', 'concept')
				ORDER BY profile DESC, FIELD(e.type, 'service', 'place', 'service_area', 'organization', 'brand', 'product', 'concept'), n DESC LIMIT 80",
				BusinessProfile::ORIGIN
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map(
			static fn( array $r ): array => array(
				'id'      => (int) $r['id'],
				'name'    => (string) $r['name'],
				'type'    => (string) $r['type'],
				'profile' => (bool) $r['profile'],
			),
			$rows
		);
	}

	/**
	 * Entität einer Tatsache: vorhandene (gleicher Typ, Name oder Schreibweise, sonst bestätigte gleichen Namens)
	 * oder ein neuer Kandidat mit Herkunft ai_research.
	 *
	 * @param EntityRepository $entities Entitäten.
	 * @param string           $name     Name.
	 * @param string           $type     Typ.
	 */
	private static function subject_entity( EntityRepository $entities, string $name, string $type ): int {
		$id = $entities->find( $type, mb_substr( KeywordNormalizer::normalize( $name ), 0, 255 ) );
		if ( $id > 0 ) {
			return $id;
		}
		return $entities->add( $type, $name, 'candidate', 'ai_research' )['id'];
	}

	/**
	 * Name und Schreibweisen einer Entität.
	 *
	 * @param int $entity_id Entität.
	 * @return string[]
	 */
	private static function names( int $entity_id ): array {
		$detail = ( new EntityRepository() )->detail( $entity_id );
		return null === $detail ? array() : array_merge( array( (string) $detail['canonical_name'] ), (array) $detail['aliases'] );
	}

	/**
	 * Gewichte aus der Einstellung topic_weights.
	 *
	 * @return array<string, int|float>
	 */
	private static function weights(): array {
		$stored = Settings::get( 'topic_weights' );
		$out    = PriorityScorer::WEIGHTS;
		foreach ( is_array( $stored ) ? $stored : array() as $key => $value ) {
			if ( isset( $out[ $key ] ) && is_numeric( $value ) ) {
				$out[ $key ] = max( 0, (float) $value );
			}
		}
		return $out;
	}

	/**
	 * KI-Dienst mit Recherchemodell aus dem Startkontext; im Prüfskript eine Attrappe.
	 *
	 * @param Plugin               $plugin Plugin.
	 * @param array<string, mixed> $input  Startkontext.
	 */
	private static function ai( Plugin $plugin, array $input ): AiService {
		if ( isset( $input['stub_research'] ) ) {
			return new AiService( new SelftestAiClient( 0.0, '{}' ), new AiCallRepository(), new PriceTable(), AiClientFactory::budget() );
		}
		return $plugin->ai->service( null, null, $input['research_model'] ?? null );
	}
}
