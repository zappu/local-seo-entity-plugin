<?php
/**
 * Endgültiger Fehler bei Textfocus.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\Textfocus;

/**
 * Ungültiger Schlüssel, kein API-Zugang, Budget erreicht oder fehlerhafte Antwort.
 * Vorübergehende Fehler werden als RetryableException geworfen.
 */
class TextfocusException extends \RuntimeException {

	/**
	 * Konstruktor.
	 *
	 * @param string $error_code Kurzer Code, z. B. not_configured, budget_exceeded, no_api_access.
	 * @param string $message    Meldung ohne Geheimnisse.
	 */
	public function __construct( public readonly string $error_code, string $message ) {
		parent::__construct( $message );
	}
}
