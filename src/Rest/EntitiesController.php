<?php
/**
 * REST-Routen für Entitäten, Unternehmensprofil und Inhaltsindex (Stufe B).
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Content\ContentIndex;
use Lsec\Content\ContentIndexWorkflow;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\BusinessProfile;
use Lsec\Entities\EntityRepository;
use Lsec\Entities\ProfileAssistant;
use Lsec\Integrations\AI\AiException;
use Lsec\Entities\ServiceArea;
use Lsec\Integrations\Wikidata\WikidataClient;
use Lsec\Workflow\RetryableException;

/**
 * Alle Routen verlangen lsec_manage (Zugriffsprüfung aus RestController). Pflichtfelder werden im
 * Callback geprüft, damit Besucher 403 statt 400 erhalten (M15).
 */
final class EntitiesController {

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

		$route( '/entities/(?P<id>\d+)', 'GET', array( $this, 'detail' ) );
		$route( '/entities/merge', 'POST', array( $this, 'merge' ) );
		$route( '/entities/merge-suggestions', 'GET', static fn(): \WP_REST_Response => new \WP_REST_Response( ( new EntityRepository() )->merge_suggestions() ) );
		$route( '/profile', 'GET', static fn(): \WP_REST_Response => new \WP_REST_Response( ( new BusinessProfile() )->get() ) );
		$route( '/profile', 'POST', array( $this, 'save_profile' ) );
		$route( '/profile/analyze', 'POST', array( $this, 'analyze_profile' ) );
		$route( '/wikidata/search', 'GET', array( $this, 'search_wikidata' ) );
		$route( '/service-area', 'POST', array( $this, 'service_area' ) );
		$route(
			'/index',
			'GET',
			static fn(): \WP_REST_Response => new \WP_REST_Response(
				ContentIndex::stats() + array(
					'auto_extract' => (bool) Settings::get( 'entity_auto_extract' ),
					'due'          => ContentIndex::extraction_due(),
				)
			)
		);
		$route( '/index/run', 'POST', array( $this, 'run_index' ) );
		$route( '/index/stop', 'POST', array( $this, 'stop_index' ) );
	}

	/**
	 * Eine Entität mit Aliassen, Fundstellen, Beziehungen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$detail = ( new EntityRepository() )->detail( (int) $request['id'] );
		return null === $detail ? new \WP_Error( 'lsec_not_found', 'Entität nicht gefunden.', array( 'status' => 404 ) ) : new \WP_REST_Response( $detail );
	}

	/**
	 * Zusammenführen: {target, sources[]}.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function merge( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$target  = (int) $request->get_param( 'target' );
		$sources = array_map( 'intval', (array) $request->get_param( 'sources' ) );
		if ( $target <= 0 || array() === $sources ) {
			return new \WP_Error( 'lsec_invalid', 'Ziel und mindestens eine Quelle angeben.', array( 'status' => 400 ) );
		}
		try {
			$out = ( new EntityRepository() )->merge( $target, $sources, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 400 ) );
		}
		$this->plugin->logger->audit(
			'Entitäten zusammengeführt',
			array(
				'target'  => $target,
				'sources' => $out['merged'],
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $out );
	}

	/**
	 * Speichert das Unternehmensprofil.
	 *
	 * @param \WP_REST_Request $request Anfrage mit dem Profil als JSON-Rumpf.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_profile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$profile = $request->get_json_params();
		if ( ! is_array( $profile ) ) {
			return new \WP_Error( 'lsec_invalid', 'Profil fehlt.', array( 'status' => 400 ) );
		}
		try {
			$out = ( new BusinessProfile() )->save( $profile, get_current_user_id() );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 422 ) );
		}
		$this->plugin->logger->audit( 'Unternehmensprofil gespeichert', $out + array( 'user_id' => get_current_user_id() ) );
		return new \WP_REST_Response( array( 'result' => $out ) + array( 'profile' => ( new BusinessProfile() )->get() ) );
	}

	/**
	 * Liest die angegebenen Seiten und schlägt Profilangaben vor (ein KI-Aufruf). Gespeichert wird nur die
	 * Liste der Adressen; den Vorschlag übernimmt der Administrator im Formular.
	 *
	 * @param \WP_REST_Request $request Anfrage mit urls (Text, eine Adresse pro Zeile).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function analyze_profile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$urls = ProfileAssistant::parse_urls( (string) $request->get_param( 'urls' ), home_url( '/' ) );
		Settings::set( 'profile_source_urls', $urls );
		$assistant = new ProfileAssistant( $this->plugin->http );
		$read      = $assistant->read( $urls );
		if ( '' === $read['text'] ) {
			return new \WP_Error( 'lsec_no_text', 'Keine der Seiten ließ sich lesen: ' . implode( ', ', $read['failed'] ), array( 'status' => 422 ) );
		}
		try {
			$site   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) . ' (' . home_url( '/' ) . ')';
			$result = ProfileAssistant::analyze( $this->plugin->ai->service(), $read['text'], $site );
		} catch ( AiException $e ) {
			return new \WP_Error( 'lsec_ai', $this->plugin->logger->redact( $e->getMessage() ), array( 'status' => 400 ) );
		} catch ( RetryableException $e ) {
			return new \WP_Error( 'lsec_ai', 'Vorübergehender Fehler, bitte gleich noch einmal: ' . $this->plugin->logger->redact( $e->getMessage() ), array( 'status' => 502 ) );
		}
		return new \WP_REST_Response(
			$result + array(
				'pages'  => $read['pages'],
				'failed' => $read['failed'],
				'urls'   => $urls,
			)
		);
	}

	/**
	 * Sucht Verwaltungseinheiten bei Wikidata für das Servicegebiet.
	 *
	 * @param \WP_REST_Request $request Anfrage mit q.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search_wikidata( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$query = trim( (string) $request->get_param( 'q' ) );
		if ( mb_strlen( $query ) < 2 ) {
			return new \WP_REST_Response( array() );
		}
		try {
			$hits = WikidataClient::for_site( $this->plugin->http )->search( $query, substr( get_locale(), 0, 2 ), 8 );
		} catch ( RetryableException | \RuntimeException $e ) {
			return new \WP_Error( 'lsec_wikidata', $e->getMessage(), array( 'status' => 502 ) );
		}
		return new \WP_REST_Response(
			array_map(
				static fn( array $h ): array => array(
					'id'          => $h['id'],
					'label'       => $h['label'],
					'description' => $h['description'],
				),
				$hits
			)
		);
	}

	/**
	 * Setzt das Servicegebiet: {id, radius_km} oder {clear: true}.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function service_area( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( $request->get_param( 'clear' ) ) {
			Settings::set( 'service_area', array() );
			return new \WP_REST_Response( array( 'service_area' => array() ) );
		}
		try {
			$found = ServiceArea::from_wikidata( WikidataClient::for_site( $this->plugin->http ), (string) $request->get_param( 'id' ), (float) $request->get_param( 'radius_km' ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'lsec_invalid', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( RetryableException | \RuntimeException $e ) {
			return new \WP_Error( 'lsec_wikidata', $e->getMessage(), array( 'status' => 502 ) );
		}
		Settings::set( 'service_area', $found['area']->to_array() );
		return new \WP_REST_Response(
			array(
				'service_area' => $found['area']->to_array(),
				'description'  => $found['description'],
			)
		);
	}

	/**
	 * Startet den Inhaltsindex.
	 *
	 * @param \WP_REST_Request $request Anfrage mit all.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$run = ContentIndexWorkflow::start( $this->plugin, (bool) $request->get_param( 'all' ) );
		if ( null === $run ) {
			return new \WP_Error( 'lsec_running', 'Es läuft schon ein Indexlauf.', array( 'status' => 409 ) );
		}
		$this->plugin->logger->audit(
			'Inhaltsindex gestartet',
			array(
				'run_id'  => $run['run_id'],
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $run );
	}

	/**
	 * Hält den Index an: bricht Indexlauf und offene Entity-Erkennungen ab.
	 *
	 * @return \WP_REST_Response
	 */
	public function stop_index(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'cancelled' => ContentIndexWorkflow::stop( $this->plugin ) ) );
	}
}
