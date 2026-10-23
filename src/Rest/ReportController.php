<?php
/**
 * REST: Content- und Entity-Bericht.
 *
 * @package Lsec
 */

namespace Lsec\Rest;

use Lsec\Report\ContentReport;

/**
 * GET lsec/v1/report.
 */
final class ReportController {

	/**
	 * Routen.
	 *
	 * @param callable $permission Berechtigung.
	 */
	public function register( callable $permission ): void {
		register_rest_route(
			RestController::NAMESPACE,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => static fn(): \WP_REST_Response => new \WP_REST_Response( ContentReport::build() ),
				'permission_callback' => $permission,
			)
		);
	}
}
