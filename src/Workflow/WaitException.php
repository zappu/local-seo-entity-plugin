<?php
/**
 * Ablauf muss warten.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Ein Schritt kann gerade nicht laufen, ohne dass etwas kaputt ist: Budget erreicht,
 * Tageskontingent verbraucht. Der Ablauf geht auf „waiting“, der Versuch zählt nicht.
 * Fortgesetzt wird er vom Cronjob, sobald die Bedingung entfällt, oder von Hand.
 */
final class WaitException extends \RuntimeException {

	/**
	 * Konstruktor.
	 *
	 * @param string $wait_code Grund, z. B. ai_budget. Der Cronjob prüft je Grund, ob es weitergehen kann.
	 * @param string $message   Lesbare Meldung ohne Geheimnisse.
	 */
	public function __construct( public readonly string $wait_code, string $message ) {
		parent::__construct( $message );
	}
}
