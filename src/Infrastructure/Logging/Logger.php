<?php
/**
 * Protokoll.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Logging;

/**
 * Schreibt geschwärzte Meldungen ins PHP-Fehlerprotokoll und, wenn verfügbar,
 * ins Protokoll von WP-CLI. Debug-Meldungen nur bei WP_DEBUG.
 */
final class Logger {

	/**
	 * Konstruktor.
	 *
	 * @param Redactor $redactor Schwärzung.
	 */
	public function __construct( private readonly Redactor $redactor ) {}

	/**
	 * Schwärzt einen Text mit den angemeldeten Geheimnissen.
	 *
	 * @param string $text Text.
	 */
	public function redact( string $text ): string {
		return $this->redactor->redact( $text );
	}

	/**
	 * Information.
	 *
	 * @param string               $message Meldung.
	 * @param array<string, mixed> $context Zusatzangaben.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/**
	 * Warnung.
	 *
	 * @param string               $message Meldung.
	 * @param array<string, mixed> $context Zusatzangaben.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	/**
	 * Fehler.
	 *
	 * @param string               $message Meldung.
	 * @param array<string, mixed> $context Zusatzangaben.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Schreibt eine Zeile.
	 *
	 * @param string               $level   Stufe.
	 * @param string               $message Meldung.
	 * @param array<string, mixed> $context Zusatzangaben.
	 */
	private function write( string $level, string $message, array $context ): void {
		if ( 'info' === $level && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$line = '[lsec][' . $level . '] ' . $message;
		if ( array() !== $context ) {
			$line .= ' ' . (string) wp_json_encode( $this->redactor->redact_array( $context ) );
		}
		$line = $this->redactor->redact( $line );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::debug( $line, 'lsec' );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
