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
