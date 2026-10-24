<?php
/**
 * REST: Werkzeuge für die Knöpfe im Backend.
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Admin\Tools;
use Lsec\Core\Plugin;

/**
 * POST lsec/v1/tools/<werkzeug>, GET/POST lsec/v1/tools/gsc-property, GET lsec/v1/tools/models.
 * Nachtrag 24.10.2026 (0.11.0).
 */
final class ToolsController {

	/**
	 * Werkzeuge ohne Eingabe.
	 */
	public const ACTIONS = array( 'migrate', 'wp-cli', 'gsc-protect', 'textfocus-lang', 'checks', 'watchdog', 'images-describe', 'local-category' );

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
			'/tools/gsc-property',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => fn(): \WP_REST_Response => new \WP_REST_Response( Tools::gsc_sites( $this->plugin ) ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_property' ),
					'permission_callback' => $permission,
				),
			)
		);
		register_rest_route(
			RestController::NAMESPACE,
			'/tools/models',
			array(
				'methods'             => 'GET',
				'callback'            => fn( \WP_REST_Request $r ): \WP_REST_Response => new \WP_REST_Response( Tools::models( $this->plugin, 'image' === $r->get_param( 'kind' ) ? 'image' : 'text' ) ),
				'permission_callback' => $permission,
			)
		);
		// Zuletzt: Die besonderen Routen oben gehen vor (WordPress nimmt die erste passende).
		register_rest_route(
			RestController::NAMESPACE,
			'/tools/(?P<tool>[a-z0-9-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Werkzeug ausführen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$tool = (string) $request['tool'];
		if ( ! in_array( $tool, self::ACTIONS, true ) ) {
			return new \WP_Error( 'rest_no_route', 'Unbekanntes Werkzeug.', array( 'status' => 404 ) );
		}
		// Laden von WP-CLI und die Prüfungen dürfen länger dauern als die übliche Grenze.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$result = match ( $tool ) {
				'migrate'         => Tools::migrate(),
				'wp-cli'          => Tools::install_wp_cli( $this->plugin ),
				'gsc-protect'     => Tools::protect_gsc_key(),
				'textfocus-lang'  => Tools::textfocus_lang( $this->plugin ),
				'checks'          => Tools::checks( $this->plugin ),
				'watchdog'        => Tools::watchdog( $this->plugin ),
				'images-describe' => Tools::describe_images( $this->plugin ),
				default           => Tools::local_category(),
			};
		} catch ( \Throwable $e ) {
			$result = array(
				'ok'      => false,
				'message' => $this->plugin->logger->redact( $e->getMessage() ),
				'details' => array(),
			);
		}
		$this->plugin->logger->audit(
			'Werkzeug im Backend',
			array(
				'tool'    => $tool,
				'ok'      => $result['ok'],
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $result );
	}

	/**
	 * Property speichern.
	 *
	 * @param \WP_REST_Request $request Anfrage mit value.
	 */
	public function save_property( \WP_REST_Request $request ): \WP_REST_Response {
		$result = Tools::save_gsc_property( $this->plugin, sanitize_text_field( (string) $request->get_param( 'value' ) ) );
		$this->plugin->logger->audit(
			'Search-Console-Property gewählt',
			array(
				'ok'      => $result['ok'],
				'user_id' => get_current_user_id(),
			)
		);
		return new \WP_REST_Response( $result );
	}
}
