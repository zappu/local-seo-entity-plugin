<?php
/**
 * HTTP-Attrappe für Tests.
 *
 * @package Lsec
 */

namespace Lsec\Tests\Support;

use Lsec\Infrastructure\Http\HttpTransport;

/**
 * Gibt vorbereitete Antworten der Reihe nach zurück und merkt sich die Anfragen.
 */
final class FakeTransport implements HttpTransport {

	/**
	 * Gesendete Anfragen.
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null, raw: string|null}>
	 */
	public array $requests = array();

	/**
	 * Konstruktor.
	 *
	 * @param list<array{status: int, body: string, error?: string|null}> $responses Antworten.
	 */
	public function __construct( private array $responses ) {}

	/**
	 * Antwort für JSON-Daten.
	 *
	 * @param array<mixed> $data   Daten.
	 * @param int          $status Status.
	 * @return array{status: int, body: string, error: null}
	 */
	public static function json( array $data, int $status = 200 ): array {
		return array(
			'status' => $status,
			'body'   => (string) json_encode( $data ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'error'  => null,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                $method  Methode.
	 * @param string                $url     Adresse.
	 * @param array<string, string> $headers Kopfzeilen.
	 * @param string|null           $body    Rumpf.
	 * @param int                   $timeout Zeitlimit.
	 * @return array{status: int, body: string, error: string|null}
	 */
	public function request( string $method, string $url, array $headers, ?string $body, int $timeout ): array {
		$decoded          = null === $body ? null : json_decode( $body, true );
		$this->requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => is_array( $decoded ) ? $decoded : null,
			'raw'     => $body,
		);
		$next             = array_shift( $this->responses );
		if ( null === $next ) {
			return array(
				'status' => 500,
				'body'   => '',
				'error'  => 'keine vorbereitete Antwort',
			);
		}
		return array(
			'status' => $next['status'],
			'body'   => $next['body'],
			'error'  => $next['error'] ?? null,
		);
	}
}
