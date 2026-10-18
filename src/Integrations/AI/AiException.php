<?php
/**
 * Fehler eines KI-Aufrufs.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

use Lsec\Workflow\RetryableException;

/**
 * Nicht wiederholbarer Fehler (z. B. ungültiger Schlüssel, unbekanntes Modell, ungültige Antwort).
 * Wiederholbare Fehler werden als RetryableException geworfen.
 */
class AiException extends \RuntimeException {

	/**
	 * Konstruktor.
	 *
	 * @param string $error_code Kurzer Code, z. B. http_401 oder invalid_output.
	 * @param string $message    Meldung ohne Geheimnisse.
	 */
	public function __construct( public readonly string $error_code, string $message ) {
		parent::__construct( $message );
	}

	/**
	 * Wirft je nach HTTP-Status eine wiederholbare oder eine endgültige Ausnahme.
	 *
	 * @param string $provider Anbieter.
	 * @param int    $status   HTTP-Status, 0 bei Netzwerkfehler.
	 * @param string $detail   Fehlertext des Anbieters.
	 * @throws RetryableException Bei 0, 408, 429, 5xx und 529.
	 * @throws AiException        Sonst.
	 */
	public static function from_http( string $provider, int $status, string $detail ): never {
		$detail = mb_substr( trim( $detail ), 0, 500 );
		$code   = 0 === $status ? 'network' : 'http_' . $status;
		$msg    = $provider . ': ' . ( 0 === $status ? 'Netzwerkfehler' : 'HTTP ' . $status ) . ( '' !== $detail ? ' – ' . $detail : '' );
		if ( 0 === $status || 408 === $status || 429 === $status || $status >= 500 ) {
			throw new RetryableException( $code, $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		throw new AiException( $code, $msg ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}
