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
				'error'  => self::explain( $response->get_error_message(), $url ),
			);
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
			'error'  => null,
		);
	}

	/**
	 * Ergänzt bei Zertifikatsfehlern einen Hinweis. cURL-Fehler 60 bzw. 51 bei einem Dienst mit gültigem
	 * Zertifikat bedeuten meist, dass der DNS-Resolver des Servers eine falsche Adresse liefert
	 * (beobachtet am 18.10.2026: Resolver eines Hosters mit veraltetem Eintrag für textfocus.net).
	 *
	 * @param string $message Fehlermeldung.
	 * @param string $url     Adresse.
	 */
	public static function explain( string $message, string $url ): string {
		if ( ! preg_match( '/cURL error (60|51)\b|certificate subject name|no alternative certificate/i', $message ) ) {
			return $message;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return $message . sprintf( ' – Hinweis: Das Zertifikat passt nicht zum Namen. Häufige Ursache ist ein DNS-Resolver des Servers, der für %1$s eine falsche Adresse liefert. Prüfen mit: getent ahosts %1$s', $host );
	}
}
