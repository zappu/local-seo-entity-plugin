<?php
/**
 * HTTP über die WordPress-HTTP-API.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Http;

/**
 * Nutzt wp_remote_request(), damit Proxy- und SSL-Einstellungen von WordPress gelten.
 */
final class WpHttpTransport implements HttpTransport {

	/**
	 * {@inheritDoc}
	 *
	 * @param string                $method  GET oder POST.
	 * @param string                $url     Adresse.
	 * @param array<string, string> $headers Kopfzeilen.
	 * @param string|null           $body    Rumpf.
	 * @param int                   $timeout Zeitlimit in Sekunden.
	 * @return array{status: int, body: string, error: string|null}
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): array {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 2,
			'user-agent'  => 'local-seo-entity-plugin/' . ( defined( 'LSEC_VERSION' ) ? LSEC_VERSION : 'dev' ),
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => '',
				'error'  => $response->get_error_message(),
			);
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'error'  => null,
		);
	}
}
