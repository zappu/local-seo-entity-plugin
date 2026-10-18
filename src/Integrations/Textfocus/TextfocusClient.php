<?php
/**
 * HTTP-Zugang zur Textfocus-API.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\Textfocus;

use Lsec\Infrastructure\Http\HttpTransport;
use Lsec\Workflow\RetryableException;

/**
 * POST https://www.textfocus.net/apis/<endpoint>/ mit Parameter key (Spezifikation 6.5).
 * Kennt weder Budget noch Zwischenspeicher, das übernimmt TextfocusService.
 */
final class TextfocusClient {

	public const BASE = 'https://www.textfocus.net/apis/';

	/**
	 * Konstruktor.
	 *
	 * @param HttpTransport $http    HTTP.
	 * @param string        $api_key Schlüssel.
	 */
	public function __construct(
		private readonly HttpTransport $http,
		#[\SensitiveParameter]
		private readonly string $api_key
	) {}

	/**
	 * Ruft einen Endpunkt auf.
	 *
	 * @param string               $endpoint Endpunkt, z. B. tf_keyword.
	 * @param array<string, mixed> $params   Parameter ohne key.
	 * @param int                  $timeout  Zeitlimit in Sekunden.
	 * @return array{result: mixed, credits_used: float|null, credits_remaining: float|null, message: string}
	 * @throws RetryableException Bei Netzwerkfehler, Zeitüberschreitung, Ratenlimit und Serverfehler.
	 * @throws TextfocusException Bei ungültigem Schlüssel, fehlendem Zugang oder unlesbarer Antwort.
	 */
	public function call( string $endpoint, array $params, int $timeout = 30 ): array {
		if ( ! preg_match( '/^tf_[a-z_]+$/', $endpoint ) ) {
			throw new TextfocusException( 'invalid_endpoint', 'Ungültiger Endpunkt: ' . $endpoint ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$body     = http_build_query( array( 'key' => $this->api_key ) + $params, '', '&', PHP_QUERY_RFC3986 );
		$response = $this->http->request(
			'POST',
			self::BASE . $endpoint . '/',
			array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			$body,
			$timeout
		);

		$status = $response['status'];
		$data   = json_decode( $response['body'], true );
		$api    = is_array( $data ) && isset( $data['response'] ) ? (int) $data['response'] : $status;
		$msg    = is_array( $data ) ? trim( (string) ( $data['message'] ?? '' ) ) : '';

		if ( 0 === $status ) {
			throw new RetryableException( 'network', 'Textfocus: Netzwerkfehler – ' . (string) $response['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 408 === $status || 429 === $status || 429 === $api || $status >= 500 || self::looks_rate_limited( $msg ) ) {
			throw new RetryableException( 429 === $status || 429 === $api || self::looks_rate_limited( $msg ) ? 'rate_limited' : 'http_' . $status, 'Textfocus: HTTP ' . $status . ( '' !== $msg ? ' – ' . mb_substr( $msg, 0, 300 ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		// Ungültiger Schlüssel kommt als HTTP 200 mit response 404 und message „api key invalid“.
		if ( 401 === $status || 403 === $status || 401 === $api || 403 === $api || self::looks_unauthorized( $msg ) ) {
			throw new TextfocusException( 'unauthorized', 'Textfocus lehnt den Schlüssel ab oder der Tarif hat keinen API-Zugang' . ( '' !== $msg ? ': ' . mb_substr( $msg, 0, 300 ) : '.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ! is_array( $data ) ) {
			throw new TextfocusException( 'invalid_response', 'Textfocus: keine JSON-Antwort (HTTP ' . $status . ').' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		// 202 bedeutet „callback is queued“ bzw. „processing started“; im MVP werden keine Callbacks genutzt.
		if ( $status >= 400 || ( 200 !== $api && 202 !== $api ) ) {
			throw new TextfocusException( 'api_error', 'Textfocus: ' . ( '' !== $msg ? mb_substr( $msg, 0, 300 ) : 'Fehler ' . $api ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return array(
			'result'            => $data['result'] ?? null,
			'credits_used'      => isset( $data['creditUsed'] ) && is_numeric( $data['creditUsed'] ) ? (float) $data['creditUsed'] : null,
			'credits_remaining' => self::remaining( $data['credits'] ?? null ),
			'message'           => $msg,
		);
	}

	/**
	 * Restguthaben aus dem Feld credits (Zahl oder Objekt mit remaining).
	 *
	 * @param mixed $credits Feldinhalt.
	 */
	public static function remaining( mixed $credits ): ?float {
		if ( is_numeric( $credits ) ) {
			return (float) $credits;
		}
		if ( is_array( $credits ) && isset( $credits['remaining'] ) && is_numeric( $credits['remaining'] ) ) {
			return (float) $credits['remaining'];
		}
		return null;
	}

	/**
	 * Erkennt Meldungen zu Schlüssel oder API-Zugang.
	 *
	 * @param string $message Meldung.
	 */
	private static function looks_unauthorized( string $message ): bool {
		return (bool) preg_match( '/api.?key|invalid key|api access|no access/i', $message );
	}

	/**
	 * Erkennt Ratenlimit-Meldungen, falls die API dafür keinen eigenen Status liefert.
	 *
	 * @param string $message Meldung.
	 */
	private static function looks_rate_limited( string $message ): bool {
		return (bool) preg_match( '/rate.?limit|too many requests|quota/i', $message );
	}
}
