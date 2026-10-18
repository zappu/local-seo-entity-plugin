<?php
/**
 * Einstellungen.
 *
 * @package Lsec
 */

namespace Lsec\Core;

/**
 * Liest und schreibt die Option lsec_settings mit Standardwerten.
 */
final class Settings {

	public const OPTION = 'lsec_settings';

	/**
	 * Standardwerte.
	 *
	 * @var array<string, mixed>
	 */
	public const DEFAULTS = array(
		'delete_data_on_uninstall' => false,
		'max_parallel_runs'        => 3,
		'ai_provider'              => 'openrouter',
		'ai_model'                 => '',
		'ai_prices'                => array(),
		'openai_endpoint'          => '',
		'textfocus_lang'           => '',
		'textfocus_daily_budget'   => 25,
		'textfocus_min_interval'   => 60,
		'textfocus_batch_size'     => 10,
		'service_area'             => array(),
	);

	/**
	 * Erlaubte Werte für Auswahlfelder.
	 *
	 * @var array<string, list<string>>
	 */
	public const CHOICES = array(
		'ai_provider' => array( 'openrouter', 'anthropic', 'openai' ),
	);

	/**
	 * Alle Einstellungen mit Standardwerten ergänzt.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Ein Wert.
	 *
	 * @param string $key Schlüssel.
	 */
	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Setzt einen Wert. Prüft Schlüssel, Typ und erlaubte Werte.
	 *
	 * @param string $key   Schlüssel.
	 * @param mixed  $value Wert.
	 * @throws \InvalidArgumentException Bei unbekanntem Schlüssel oder ungültigem Wert.
	 */
	public static function set( string $key, mixed $value ): void {
		if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
			throw new \InvalidArgumentException( 'Unbekannte Einstellung: ' . $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$default = self::DEFAULTS[ $key ];
		if ( is_bool( $default ) ) {
			$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
		} elseif ( is_int( $default ) ) {
			$value = max( 0, (int) $value );
		} elseif ( is_array( $default ) ) {
			$value = is_string( $value ) ? json_decode( $value, true ) : $value;
			if ( ! is_array( $value ) ) {
				throw new \InvalidArgumentException( $key . ' erwartet JSON.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		} else {
			$value = sanitize_text_field( (string) $value );
		}
		if ( isset( self::CHOICES[ $key ] ) && ! in_array( $value, self::CHOICES[ $key ], true ) ) {
			throw new \InvalidArgumentException( $key . ' erlaubt: ' . implode( ', ', self::CHOICES[ $key ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$all         = self::all();
		$all[ $key ] = $value;
		update_option( self::OPTION, $all, false );
	}
}
