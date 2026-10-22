<?php
/**
 * REST-Routen für Beiträge und Style Mimic (Stufe E).
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Content\ContentIndex;
use Lsec\Core\Plugin;
use Lsec\Production\ContentProductionWorkflow;
use Lsec\Production\ProductionReport;
use Lsec\Production\StyleProfiler;
use Lsec\Production\StyleProfileRepository;

/**
 * Entwurf starten, Bericht, Style Mimic. Alle Routen verlangen lsec_manage; Pflichtfelder werden im
 * Callback geprüft (M15).
 */
final class ProductionController {

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
		$route( '/topics/(?P<id>\d+)/produce', 'POST', array( $this, 'produce' ) );
		$route( '/production/(?P<id>\d+)', 'GET', array( $this, 'report' ) );
		$route( '/style', 'GET', array( $this, 'style' ) );
		$route( '/style', 'POST', array( $this, 'save_style' ) );
		$route( '/style/build', 'POST', array( $this, 'build_style' ) );
		$route( '/style/(?P<version>\d+)/activate', 'POST', array( $this, 'activate_style' ) );
	}

	/**
	 * Startet einen Entwurf.
	 *
	 * @param \WP_REST_Request $request Anfrage mit again.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function produce( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$run = ContentProductionWorkflow::start(
				$this->plugin,
				(int) $request['id'],
				array(
					'again'  => (bool) $request->get_param( 'again' ),
					'reason' => 'backend',
				)
			);
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'lsec_not_approved', $e->getMessage(), array( 'status' => 409 ) );
		}
		$this->plugin->logger->audit(
			'Beitrag gestartet',
			array(
				'run_id'   => $run['run_id'],
				'topic_id' => (int) $request['id'],
				'user_id'  => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $run );
	}

	/**
	 * Bericht eines Ablaufs.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function report( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$report = ProductionReport::for_run( $this->plugin, (int) $request['id'] );
		return null === $report ? new \WP_Error( 'lsec_not_found', 'Ablauf nicht gefunden.', array( 'status' => 404 ) ) : new \WP_REST_Response( $report );
	}

	/**
	 * Aktives Profil, Versionen und Beiträge zur Auswahl.
	 */
	public function style(): \WP_REST_Response {
		$repo  = new StyleProfileRepository();
		$posts = array();
		foreach ( ContentIndex::entries( 300 ) as $entry ) {
			if ( (int) $entry['post_id'] > 0 && in_array( (string) $entry['kind'], array( 'post', 'page' ), true ) ) {
				$posts[] = array(
					'id'    => (int) $entry['post_id'],
					'title' => (string) $entry['title'],
					'kind'  => (string) $entry['kind'],
				);
			}
		}
		$active = $repo->active();
		return new \WP_REST_Response(
			array(
				'active'   => $active,
				'text'     => null === $active ? '' : StyleProfiler::prompt_text( (array) $active['profile'] ),
				'versions' => array_map(
					static fn( array $v ): array => array(
						'version'    => $v['version'],
						'is_active'  => $v['is_active'],
						'origin'     => (string) ( $v['inputs']['origin'] ?? '' ),
						'post_ids'   => (array) ( $v['inputs']['post_ids'] ?? array() ),
						'created_at' => $v['created_at'],
					),
					$repo->versions()
				),
				'posts'    => $posts,
			)
		);
	}

	/**
	 * Erzeugt ein Profil mit der KI.
	 *
	 * @param \WP_REST_Request $request Anfrage mit post_ids, samples, rules.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function build_style( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$posts   = array_map( 'intval', (array) $request->get_param( 'post_ids' ) );
		$samples = array_map( 'strval', (array) $request->get_param( 'samples' ) );
		$rules   = array_map( 'sanitize_text_field', array_map( 'strval', (array) $request->get_param( 'rules' ) ) );
		try {
			$built = ( new StyleProfiler( $this->plugin->ai->service() ) )->build( $posts, $samples, $rules );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'lsec_ai', $this->plugin->logger->redact( $e->getMessage() ), array( 'status' => 502 ) );
		}
		$version = ( new StyleProfileRepository() )->save( $built['profile'], $built['inputs'], get_current_user_id() );
		$this->plugin->logger->audit(
			'Style Mimic gespeichert',
			array(
				'version' => $version,
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response(
			array(
				'version' => $version,
				'cost'    => $built['cost'],
			)
		);
	}

	/**
	 * Speichert ein bearbeitetes Profil als neue Version.
	 *
	 * @param \WP_REST_Request $request Anfrage mit profile.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_style( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$profile = $request->get_param( 'profile' );
		if ( ! is_array( $profile ) ) {
			return new \WP_Error( 'lsec_invalid', 'Profil fehlt.', array( 'status' => 400 ) );
		}
		$repo    = new StyleProfileRepository();
		$active  = $repo->active();
		$inputs  = array( 'origin' => 'manual' ) + ( null === $active ? array() : array( 'post_ids' => (array) ( $active['inputs']['post_ids'] ?? array() ) ) );
		$version = $repo->save( $profile, $inputs, get_current_user_id() );
		$this->plugin->logger->audit(
			'Style Mimic bearbeitet',
			array(
				'version' => $version,
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( array( 'version' => $version ) );
	}

	/**
	 * Macht eine Version aktiv.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate_style( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! ( new StyleProfileRepository() )->activate( (int) $request['version'] ) ) {
			return new \WP_Error( 'lsec_not_found', 'Version nicht gefunden.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( array( 'ok' => true ) );
	}
}
