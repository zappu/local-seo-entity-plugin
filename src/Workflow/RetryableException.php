<?php
/**
 * Vorübergehender Fehler.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Ein StepHandler wirft diese Ausnahme, wenn ein späterer Versuch Erfolg haben kann
 * (Netzwerkfehler, Zeitüberschreitung, HTTP 429 oder 5xx).
 */
class RetryableException extends \RuntimeException {

	/**
	 * Konstruktor.
	 *
	 * @param string $error_code Kurzer Fehlercode, z. B. http_429.
	 * @param string $message    Meldung ohne Geheimnisse.
	 */
	public function __construct( public readonly string $error_code, string $message ) {
		parent::__construct( $message );
	}
}
