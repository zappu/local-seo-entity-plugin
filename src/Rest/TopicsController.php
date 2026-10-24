<?php
/**
 * REST-Routen der Themen-Seite (Stufe D).
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\BusinessProfile;
use Lsec\Entities\ServiceArea;
use Lsec\Integrations\AI\AiCallRepository;
use Lsec\Topics\ClaimRepository;
use Lsec\Topics\PriorityScorer;
use Lsec\Topics\TopicRepository;
use Lsec\Topics\TopicResearchWorkflow;
use Lsec\Topics\TwinAdvice;

/**
 * Themen, Recherche starten, Freigabe und Entscheidungen über Tatsachen. Alle Routen verlangen
 * lsec_manage; Pflichtfelder werden im Callback geprüft (M15).
 */
final class TopicsController {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Registriert die Routen.
	 *
	 * @param callable $permission Zugriffsprüfung.
	 */
	public function register( callable $permission ): void {
		$route = static function ( string $path, string $method, callable $callback ) use ( $permission ): void {
			register_rest_route(
				RestController::NAMESPACE,
				$path,
				array(
					'methods'             => $method,
					'callback'            => $callback,
					'permission_callback' => $permission,
				)
			);
		};
		$route( '/topics', 'GET', array( $this, 'index' ) );
		$route( '/topics/(?P<id>\d+)', 'GET', array( $this, 'detail' ) );
		$route( '/topics/(?P<id>\d+)/decision', 'POST', array( $this, 'decide' ) );
		$route( '/topics/research', 'POST', array( $this, 'research' ) );
		$route( '/topics/compare', 'GET', array( $this, 'compare' ) );
		$route( '/topics/resolve', 'POST', array( $this, 'resolve' ) );
		$route( '/claims/(?P<id>\d+)/decision', 'POST', array( $this, 'decide_claim' ) );
	}

	/**
	 * Themen, Zählung, letzte Recherche und Auswahl für den Schwerpunkt.
	 *
	 * @param \WP_REST_Request $request Anfrage mit status.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$repo    = new TopicRepository();
		$run     = $this->plugin->runs->latest_of_type( TopicResearchWorkflow::TYPE );
		$latest  = null;
		$profile = ( new BusinessProfile() )->get();
		if ( null !== $run ) {
			$context = json_decode( (string) $run['context'], true );
			$latest  = array(
				'id'            => (int) $run['id'],
				'status'        => (string) $run['status'],
				'step'          => (string) $run['current_step'],
				'focus'         => is_array( $context ) ? (array) ( $context['focus'] ?? array() ) : array(),
				'error_code'    => (string) $run['error_code'],
				'error_message' => $this->plugin->logger->redact( (string) $run['error_message'] ),
				'created_at'    => gmdate( 'c', (int) strtotime( (string) $run['created_at'] . ' UTC' ) ),
				'cost'          => round( ( new AiCallRepository() )->cost_of_run( (int) $run['id'] ), 4 ),
			);
		}
		$area  = ServiceArea::from_settings();
		$twins = array();
		$kept  = array();
		foreach ( $repo->duplicate_groups( TopicResearchWorkflow::profile_services() ) as $group ) {
			$keep = array_shift( $group );
			foreach ( $group as $twin ) {
				$twins[ (int) $twin['id'] ] = array(
					'id'     => (int) $keep['id'],
					'title'  => (string) $keep['title'],
					'status' => (string) $keep['status'],
				);
				// Nachtrag 24.10. (0.9.2): Auch das behaltene Thema nennt seine Doppel, damit beide Seiten sichtbar sind.
				$kept[ (int) $keep['id'] ][] = array(
					'id'     => (int) $twin['id'],
					'title'  => (string) $twin['title'],
					'status' => (string) $twin['status'],
				);
			}
		}
		return new \WP_REST_Response(
			array(
				'topics'     => array_map(
					static fn( array $t ): array => self::with_quality( $t ) + array(
						'duplicate_of' => $twins[ (int) $t['id'] ] ?? null,
						'duplicates'   => $kept[ (int) $t['id'] ] ?? array(),
					),
					$repo->list( (string) $request->get_param( 'status' ), 200 )
				),
				'counts'     => $repo->counts(),
				'latest'     => $latest,
				'services'   => array_values( array_map( static fn( array $s ): string => (string) $s['name'], (array) ( $profile['services'] ?? array() ) ) ),
				'area'       => null === $area ? '' : $area->label,
				'settings'   => array(
					'count'       => (int) Settings::get( 'topic_count' ),
					'web_results' => (int) Settings::get( 'ai_web_max_results' ),
					'model'       => (string) Settings::get( 'ai_model' ),
					'research'    => (string) Settings::get( 'ai_research_model' ),
				),
				'labels'     => PriorityScorer::LABELS,
				'production' => array(
					'auto'    => (bool) Settings::get( 'production_auto' ),
					'per_day' => (int) Settings::get( 'drafts_per_day' ),
					'today'   => \Lsec\Production\ContentProductionWorkflow::started_today(),
				),
			)
		);
	}

	/**
	 * Ergänzt Beitrag und Prüfergebnis.
	 *
	 * @param array<string, mixed> $topic Thema.
	 * @return array<string, mixed>
	 */
	private static function with_quality( array $topic ): array {
		$post_id          = (int) ( $topic['post_id'] ?? 0 );
		$report           = $post_id > 0 ? ( new \Lsec\Production\QualityReportRepository() )->latest_for_post( $post_id ) : null;
		$topic['post_id'] = $post_id;
		$topic['quality'] = null === $report ? null : array(
			'passed' => $report['passed'],
			'score'  => $report['score'],
		);
		return $topic;
	}

	/**
	 * Ein Thema.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$topic = ( new TopicRepository() )->detail( (int) $request['id'] );
		if ( null === $topic ) {
			return new \WP_Error( 'lsec_not_found', 'Thema nicht gefunden.', array( 'status' => 404 ) );
		}
		$run                 = \Lsec\Production\ProductionReport::latest_run_id( (int) $topic['id'] );
		$topic['production'] = $run > 0 ? \Lsec\Production\ProductionReport::for_run( $this->plugin, $run ) : null;
		return new \WP_REST_Response( $topic );
	}

	/**
	 * Zwei Themen mit Empfehlung (Nachtrag 24.10.2026, 0.10.2).
	 *
	 * @param \WP_REST_Request $request Anfrage mit a und b.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function compare( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo = new TopicRepository();
		$a    = $repo->detail( (int) $request->get_param( 'a' ) );
		$b    = $repo->detail( (int) $request->get_param( 'b' ) );
		if ( null === $a || null === $b || (int) $a['id'] === (int) $b['id'] ) {
			return new \WP_Error( 'lsec_not_found', 'Zwei verschiedene Themen angeben.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response(
			array(
				'topics' => array( $a, $b ),
				'advice' => TwinAdvice::compare( $a, $b ),
			)
		);
	}

	/**
	 * Dublette auflösen: ein Thema behalten, das andere ablehnen; dessen fehlende Keywords (als weitere Keywords),
	 * Tatsachen und Quellen gehen an das behaltene (Nachtrag 24.10.2026, 0.10.2).
	 *
	 * @param \WP_REST_Request $request Anfrage mit keep und reject.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resolve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$repo   = new TopicRepository();
		$keep   = $repo->detail( (int) $request->get_param( 'keep' ) );
		$reject = $repo->detail( (int) $request->get_param( 'reject' ) );
		if ( null === $keep || null === $reject || (int) $keep['id'] === (int) $reject['id'] ) {
			return new \WP_Error( 'lsec_not_found', 'Zwei verschiedene Themen angeben.', array( 'status' => 404 ) );
		}
		if ( in_array( (string) $reject['status'], array( 'in_production', 'produced' ), true ) ) {
			return new \WP_Error( 'lsec_invalid', sprintf( 'Zu Thema #%d gibt es schon einen Entwurf; es wird nicht abgelehnt.', (int) $reject['id'] ), array( 'status' => 400 ) );
		}
		$adopt = array_map( static fn( array $k ): int => $k['id'], TwinAdvice::adoptable( $keep, $reject ) );
		$basis = (array) ( $reject['research_basis'] ?? array() );
		$repo->merge_basis( (int) $keep['id'], array_map( 'intval', (array) ( $basis['claim_ids'] ?? array() ) ), array_map( 'intval', (array) ( $basis['source_ids'] ?? array() ) ), array(), $adopt );
		$repo->decide( (int) $reject['id'], 'rejected', get_current_user_id() );
		\Lsec\Production\ContentProductionWorkflow::cancel_for_topic( $this->plugin, (int) $reject['id'] );
		$this->plugin->logger->audit(
			'Doppeltes Thema aufgelöst',
			array(
				'keep'     => (int) $keep['id'],
				'reject'   => (int) $reject['id'],
				'keywords' => $adopt,
				'user_id'  => get_current_user_id(),
			)
		);
		return new \WP_REST_Response(
			array(
				'keep'     => (int) $keep['id'],
				'reject'   => (int) $reject['id'],
				'keywords' => count( $adopt ),
			)
		);
	}

	/**
	 * Freigeben, ablehnen oder zurücksetzen.
	 *
	 * @param \WP_REST_Request $request Anfrage mit decision.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function decide( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$decision = (string) $request->get_param( 'decision' );
		try {
			$ok = ( new TopicRepository() )->decide( (int) $request['id'], $decision, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( ! $ok ) {
			return new \WP_Error( 'lsec_not_found', 'Thema nicht gefunden.', array( 'status' => 404 ) );
		}
		if ( 'approved' !== $decision ) {
			\Lsec\Production\ContentProductionWorkflow::cancel_for_topic( $this->plugin, (int) $request['id'] );
		}
		$this->plugin->logger->audit(
			'Thema entschieden',
			array(
				'topic_id' => (int) $request['id'],
				'decision' => $decision,
				'user_id'  => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Startet eine Recherche.
	 *
	 * @param \WP_REST_Request $request Anfrage mit service, region, count, force.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function research( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$options = array(
			'service' => sanitize_text_field( (string) $request->get_param( 'service' ) ),
			'region'  => sanitize_text_field( (string) $request->get_param( 'region' ) ),
			'force'   => (bool) $request->get_param( 'force' ),
		);
		if ( null !== $request->get_param( 'count' ) ) {
			$options['count'] = (int) $request->get_param( 'count' );
		}
		try {
			$run = TopicResearchWorkflow::start( $this->plugin, $options );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'lsec_recent', $e->getMessage(), array( 'status' => 409 ) );
		}
		$this->plugin->logger->audit(
			'Themenrecherche gestartet',
			array(
				'run_id'  => $run['run_id'],
				'service' => $options['service'],
				'region'  => $options['region'],
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $run );
	}

	/**
	 * Tatsache bestätigen oder verwerfen.
	 *
	 * @param \WP_REST_Request $request Anfrage mit decision.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function decide_claim( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$decision = (string) $request->get_param( 'decision' );
		try {
			$ok = ( new ClaimRepository() )->decide( (int) $request['id'], $decision );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( ! $ok ) {
			return new \WP_Error( 'lsec_not_found', 'Aussage nicht gefunden.', array( 'status' => 404 ) );
		}
		$this->plugin->logger->audit(
			'Aussage entschieden',
			array(
				'claim_id' => (int) $request['id'],
				'decision' => $decision,
				'user_id'  => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( array( 'ok' => true ) );
	}
}
