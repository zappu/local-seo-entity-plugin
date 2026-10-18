<?php
/**
 * REST-Routen des Plugins.
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Cli\LsecCommand;
use Lsec\Content\ContentReader;
use Lsec\Core\Plugin;
use Lsec\Entities\EntityRepository;
use Lsec\Infrastructure\Security\Capabilities;
use Lsec\Research\Keywords\KeywordRepository;

/**
 * Namensraum lsec/v1. Jede Route verlangt die Capability lsec_manage; alle anderen erhalten 403 (Prüfpunkt M15),
 * auch nicht angemeldete Besucher, damit sich geschützte Routen nicht von fehlenden unterscheiden lassen.
 */
final class RestController {

	public const NAMESPACE = 'lsec/v1';

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Registriert die Routen (rest_api_init).
	 */
	public function register(): void {
		$permission = array( self::class, 'permission' );

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => fn(): \WP_REST_Response => new \WP_REST_Response( LsecCommand::collect_status( $this->plugin ) ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/keywords',
			array(
				'methods'             => 'GET',
				'callback'            => static fn( \WP_REST_Request $r ): \WP_REST_Response => new \WP_REST_Response(
					( new KeywordRepository() )->list_with_metrics( (string) $r->get_param( 'search' ), (string) $r->get_param( 'source' ), (int) $r->get_param( 'limit' ) )
				),
				'permission_callback' => $permission,
				'args'                => array(
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
					'source' => array(
						'type'    => 'string',
						'default' => '',
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 100,
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/entities',
			array(
				'methods'             => 'GET',
				'callback'            => static fn( \WP_REST_Request $r ): \WP_REST_Response => new \WP_REST_Response(
					( new EntityRepository() )->list( (string) $r->get_param( 'type' ), (string) $r->get_param( 'status' ), (int) $r->get_param( 'limit' ) )
				),
				'permission_callback' => $permission,
				'args'                => array(
					'type'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'status' => array(
						'type'    => 'string',
						'default' => '',
					),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 500,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/entities/(?P<id>\d+)/decision',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decide' ),
				'permission_callback' => $permission,
				'args'                => array(
					'decision' => array(
						'type'     => 'string',
						'enum'     => array( 'confirmed', 'rejected' ),
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/content/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => static function ( \WP_REST_Request $r ) {
					$post = get_post( (int) $r['id'] );
					if ( ! $post instanceof \WP_Post ) {
						return new \WP_Error( 'lsec_not_found', 'Beitrag nicht gefunden.', array( 'status' => 404 ) );
					}
					return new \WP_REST_Response( ContentReader::read( $post ) );
				},
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Zugriffsprüfung für alle Routen.
	 *
	 * @return true|\WP_Error
	 */
	public static function permission(): bool|\WP_Error {
		if ( Capabilities::current_user_can_manage() ) {
			return true;
		}
		return new \WP_Error( 'lsec_forbidden', 'Keine Berechtigung.', array( 'status' => 403 ) );
	}

	/**
	 * Entscheidung über eine Entität, protokolliert mit Benutzer und Zeit (M11).
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function decide( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = (int) $request['id'];
		$decision = (string) $request->get_param( 'decision' );
		$ok       = ( new EntityRepository() )->decide( $id, $decision, get_current_user_id() );
		if ( ! $ok ) {
			return new \WP_Error( 'lsec_not_found', 'Entität nicht gefunden.', array( 'status' => 404 ) );
		}
		$this->plugin->logger->info(
			'Entität entschieden',
			array(
				'entity_id' => $id,
				'decision'  => $decision,
				'user_id'   => get_current_user_id(),
			)
		);
		return new \WP_REST_Response(
			array(
				'id'     => $id,
				'status' => $decision,
			)
		);
	}
}
