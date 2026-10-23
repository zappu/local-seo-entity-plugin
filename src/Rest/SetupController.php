<?php
/**
 * REST: Einrichtungsassistent.
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Core\Plugin;
use Lsec\Setup\SetupWizard;

/**
 * GET lsec/v1/setup (Stand), POST lsec/v1/setup/<schritt> (Aktion).
 */
final class SetupController {

	/**
	 * Konstruktor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Routen.
	 *
	 * @param callable $permission Berechtigung.
	 */
	public function register( callable $permission ): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/setup',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/setup/(?P<step>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Stand.
	 */
	public function status(): \WP_REST_Response {
		return new \WP_REST_Response( SetupWizard::status( $this->plugin ) );
	}

	/**
	 * Schritt ausführen.
	 *
	 * @param \WP_REST_Request $request Anfrage mit radius, text, force.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$step = (string) $request['step'];
		try {
			$result = SetupWizard::run(
				$this->plugin,
				$step,
				array(
					'radius' => null === $request->get_param( 'radius' ) ? SetupWizard::DEFAULT_RADIUS_KM : (float) $request->get_param( 'radius' ),
					'text'   => (string) $request->get_param( 'text' ),
					'force'  => (bool) $request->get_param( 'force' ),
				)
			);
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			return new \WP_Error( 'lsec_setup', $this->plugin->logger->redact( $e->getMessage() ), array( 'status' => 422 ) );
		}
		$this->plugin->logger->audit(
			'Einrichtung: Schritt ausgeführt',
			array(
				'step'    => $step,
				'cost'    => $result['cost'],
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $result + array( 'status' => SetupWizard::status( $this->plugin ) ) );
	}
}
