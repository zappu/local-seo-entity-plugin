<?php
/**
 * Ergebnis eines Schritts.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Unveränderliches Ergebnis, das ein StepHandler zurückgibt.
 */
final class StepResult {

	public const DONE  = 'done';
	public const RETRY = 'retry';
	public const FAIL  = 'fail';
	public const BLOCK = 'block';
	public const WAIT  = 'wait';
	public const MORE  = 'more';

	/**
	 * Konstruktor.
	 *
	 * @param string               $outcome Eine der Konstanten.
	 * @param array<string, mixed> $output  Zwischenergebnis des Schritts.
	 * @param string|null          $code    Fehler- oder Blockadecode.
	 * @param string|null          $message Lesbare Meldung ohne Geheimnisse.
	 */
	private function __construct(
		public readonly string $outcome,
		public readonly array $output = array(),
		public readonly ?string $code = null,
		public readonly ?string $message = null
	) {}

	/**
	 * Schritt erfolgreich abgeschlossen.
	 *
	 * @param array<string, mixed> $output Zwischenergebnis.
	 */
	public static function done( array $output = array() ): self {
		return new self( self::DONE, $output );
	}

	/**
	 * Vorübergehender Fehler, Schritt später wiederholen.
	 *
	 * @param string $code    Fehlercode.
	 * @param string $message Meldung.
	 */
	public static function retry( string $code, string $message ): self {
		return new self( self::RETRY, array(), $code, $message );
	}

	/**
	 * Nicht wiederholbarer Fehler.
	 *
	 * @param string $code    Fehlercode.
	 * @param string $message Meldung.
	 */
	public static function fail( string $code, string $message ): self {
		return new self( self::FAIL, array(), $code, $message );
	}

	/**
	 * Der Schritt ist noch nicht fertig und läuft im nächsten Durchgang weiter, z. B. für Stapel.
	 * Der Zwischenstand steht beim nächsten Mal in RunContext::$state. Der Versuch zählt nicht.
	 *
	 * @param array<string, mixed> $state Zwischenstand.
	 */
	public static function more( array $state ): self {
		return new self( self::MORE, $state );
	}

	/**
	 * Warten, bis eine Bedingung entfällt (z. B. Budget). Der Versuch zählt nicht.
	 *
	 * @param string $code    Grund.
	 * @param string $message Meldung.
	 */
	public static function wait( string $code, string $message ): self {
		return new self( self::WAIT, array(), $code, $message );
	}

	/**
	 * Fachlicher Abbruch, z. B. Qualitätsprüfung nicht bestanden.
	 *
	 * @param string               $code    Code.
	 * @param string               $message Meldung.
	 * @param array<string, mixed> $output  Zwischenergebnis, z. B. Prüfbericht.
	 */
	public static function block( string $code, string $message, array $output = array() ): self {
		return new self( self::BLOCK, $output, $code, $message );
	}
}
