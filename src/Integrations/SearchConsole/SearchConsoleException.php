<?php
/**
 * Endgültiger Fehler bei der Search Console.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\SearchConsole;

/**
 * Fehlende oder ungültige Schlüsseldatei, kein Zugriff auf die Property, ungültige Anfrage.
 * Vorübergehende Fehler (429, 5xx, Netzwerk) werden als RetryableException geworfen.
 */
class SearchConsoleException extends \RuntimeException {

	/**
	 * Konstruktor.
	 *
	 * @param string $error_code Kurzer Code, z. B. not_configured, forbidden, invalid_key.
	 * @param string $message    Meldung ohne Geheimnisse.
	 */
	public function __construct( public readonly string $error_code, string $message ) {
		parent::__construct( $message );
	}
}
