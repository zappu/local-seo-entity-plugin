<?php
/**
 * WP-CLI: Einstellungen.
 *
 * @package Lsec
 */

namespace Lsec\Cli;

use Lsec\Core\Settings;
use WP_CLI;

/**
 * Liest und setzt Einstellungen des Plugins.
 */
final class SettingsCommand {

	/**
	 * Zeigt alle Einstellungen.
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function list( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( Settings::all() as $key => $value ) {
			$rows[] = array(
				'Einstellung' => $key,
				'Wert'        => is_scalar( $value ) ? var_export( $value, true ) : (string) wp_json_encode( $value ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'Einstellung', 'Wert' ) );
	}

	/**
	 * Setzt eine Einstellung.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Name der Einstellung.
	 *
	 * <value>
	 * : Wert. Für Listen und Tabellen JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lsec settings set ai_provider openrouter
	 *     wp lsec settings set ai_model anthropic/claude-sonnet-4.5
	 *
	 * @param string[]              $args       Argumente.
	 * @param array<string, string> $assoc_args Optionen.
	 */
	public function set( array $args, array $assoc_args ): void {
		try {
			Settings::set( (string) ( $args[0] ?? '' ), $args[1] ?? '' );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( $args[0] . ' gespeichert.' );
	}
}
