<?php
/**
 * REST-Routen des Plugins.
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Admin\DashboardData;
use Lsec\Admin\SettingsSchema;
use Lsec\Cli\LsecCommand;
use Lsec\Content\ContentReader;
use Lsec\Core\Plugin;
use Lsec\Core\Settings;
use Lsec\Entities\EntityRepository;
use Lsec\Infrastructure\Security\Capabilities;
use Lsec\Integrations\Textfocus\AccountSnapshot;
use Lsec\Integrations\Textfocus\ProviderCallRepository;
use Lsec\Integrations\Textfocus\TextfocusException;
use Lsec\Integrations\Textfocus\TextfocusService;
use Lsec\Research\Keywords\KeywordRepository;
use Lsec\Workflow\RetryableException;
use Lsec\Workflow\RunStatus;

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
					( new EntityRepository() )->list(
						(string) $r->get_param( 'type' ),
						(string) $r->get_param( 'status' ),
						(int) $r->get_param( 'limit' ),
						(float) $r->get_param( 'min_confidence' ) > 0 ? (float) $r->get_param( 'min_confidence' ) : null,
						(string) $r->get_param( 'search' )
					)
				),
				'permission_callback' => $permission,
				'args'                => array(
					'type'           => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'search'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'min_confidence' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'limit'          => array(
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

		$this->register_admin_routes( $permission );
		( new EntitiesController( $this->plugin ) )->register( $permission );
		( new KeywordsController( $this->plugin ) )->register( $permission );
		( new TopicsController( $this->plugin ) )->register( $permission );
		( new ProductionController( $this->plugin ) )->register( $permission );
		( new SetupController( $this->plugin ) )->register( $permission );
		( new RunsController( $this->plugin ) )->register( $permission );
	}

	/**
	 * Routen für Dashboard, Einstellungen und Abläufe (Stufe A).
	 *
	 * @param callable $permission Zugriffsprüfung.
	 */
	private function register_admin_routes( callable $permission ): void {
		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => fn(): \WP_REST_Response => new \WP_REST_Response( DashboardData::collect( $this->plugin ) ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( SettingsSchema::describe() ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $permission,
					// Nicht als Pflicht markiert: Fehlende Pflichtfelder prüft WordPress vor der Berechtigung,
					// dann bekämen Besucher 400 statt 403 (M15).
					'args'                => array(
						'values' => array(
							'type'    => 'object',
							'default' => array(),
						),
					),
				),
			)
		);

		foreach ( array( 'resume', 'cancel' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/runs/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => fn( \WP_REST_Request $r ) => $this->run_action( $r, $action ),
					'permission_callback' => $permission,
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/textfocus/account',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'textfocus_account' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Speichert Einstellungen. Jeder Wert wird einzeln geprüft; ungültige werden mit Grund gemeldet.
	 *
	 * @param \WP_REST_Request $request Anfrage mit values = {Schlüssel: Wert}.
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$values = $request->get_param( 'values' );
		$saved  = array();
		$errors = array();
		foreach ( is_array( $values ) ? $values : array() as $key => $value ) {
			$key = (string) $key;
			if ( ! SettingsSchema::editable( $key ) ) {
				$errors[ $key ] = 'Diese Einstellung lässt sich hier nicht ändern.';
				continue;
			}
			try {
				if ( is_array( Settings::DEFAULTS[ $key ] ) && is_array( $value ) ) {
					$value = (string) wp_json_encode( $value );
				}
				Settings::set( $key, $value );
				$saved[] = $key;
			} catch ( \InvalidArgumentException $e ) {
				$errors[ $key ] = $e->getMessage();
			}
		}
		return new \WP_REST_Response(
			array(
				'saved'    => $saved,
				'errors'   => (object) $errors,
				'settings' => SettingsSchema::describe(),
			),
			array() === $errors ? 200 : 422
		);
	}

	/**
	 * Ablauf fortsetzen oder abbrechen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @param string           $action  resume oder cancel.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_action( \WP_REST_Request $request, string $action ): \WP_REST_Response|\WP_Error {
		$id  = (int) $request['id'];
		$run = $this->plugin->runs->find( $id );
		if ( null === $run ) {
			return new \WP_Error( 'lsec_not_found', 'Ablauf nicht gefunden.', array( 'status' => 404 ) );
		}
		if ( 'resume' === $action ) {
			if ( ! $this->plugin->engine->resume( $id ) ) {
				return new \WP_Error( 'lsec_not_waiting', 'Der Ablauf wartet nicht.', array( 'status' => 409 ) );
			}
			$this->plugin->logger->audit(
				'Ablauf von Hand fortgesetzt',
				array(
					'run_id'  => $id,
					'user_id' => get_current_user_id(),
				)
			);
		} else {
			$status = RunStatus::tryFrom( (string) $run['status'] );
			if ( null !== $status && $status->is_terminal() ) {
				return new \WP_Error( 'lsec_finished', 'Der Ablauf ist schon beendet.', array( 'status' => 409 ) );
			}
			$this->plugin->runs->update_run(
				$id,
				array(
					'status'      => RunStatus::Cancelled->value,
					'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$this->plugin->logger->audit(
				'Ablauf abgebrochen',
				array(
					'run_id'  => $id,
					'user_id' => get_current_user_id(),
				)
			);
		}
		$run = $this->plugin->runs->find( $id );
		return new \WP_REST_Response(
			array(
				'id'     => $id,
				'status' => null === $run ? null : $run['status'],
			)
		);
	}

	/**
	 * Fragt Tarif und Restguthaben bei Textfocus ab (kostet keinen Credit).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function textfocus_account(): \WP_REST_Response|\WP_Error {
		try {
			$tf   = TextfocusService::from_settings( $this->plugin->http, $this->plugin->secrets, new ProviderCallRepository() );
			$info = $tf->account( false );
			AccountSnapshot::save( $info );
			return new \WP_REST_Response( AccountSnapshot::get() );
		} catch ( TextfocusException | RetryableException $e ) {
			return new \WP_Error( 'lsec_textfocus', $this->plugin->logger->redact( $e->getMessage() ), array( 'status' => 502 ) );
		}
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
		$this->plugin->logger->audit(
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
