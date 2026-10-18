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
		'ai_research_model'        => '',
		'ai_prices'                => array(),
		'ai_monthly_budget'        => 20.0,
		'ai_run_budget'            => 1.0,
		'ai_web_max_results'       => 3,
		'entity_min_confidence'    => 0.6,
		'openai_endpoint'          => '',
		'textfocus_lang'           => '',
		'textfocus_locale'         => '',
		'textfocus_daily_budget'   => 25,
		'textfocus_min_interval'   => 60,
		'textfocus_batch_size'     => 10,
		'service_area'             => array(),
		'gsc_credentials_file'     => '',
		'gsc_property'             => '',
		'gsc_country'              => 'deu',
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
	 * Erlaubte Bereiche für Zahlen (einschließlich).
	 *
	 * @var array<string, array{0: int|float, 1: int|float}>
	 */
	public const RANGES = array(
		'ai_web_max_results'    => array( 1, 10 ),
		'entity_min_confidence' => array( 0.0, 1.0 ),
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
		} elseif ( is_float( $default ) ) {
			$value = max( 0.0, (float) str_replace( ',', '.', (string) $value ) );
		} elseif ( is_array( $default ) ) {
			$value = is_string( $value ) ? json_decode( $value, true ) : $value;
			if ( ! is_array( $value ) ) {
				throw new \InvalidArgumentException( $key . ' erwartet JSON.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		} else {
			$value = sanitize_text_field( (string) $value );
		}
		if ( isset( self::RANGES[ $key ] ) && ( is_int( $value ) || is_float( $value ) ) && ( $value < self::RANGES[ $key ][0] || $value > self::RANGES[ $key ][1] ) ) {
			throw new \InvalidArgumentException( sprintf( '%s erlaubt %s bis %s.', $key, self::RANGES[ $key ][0], self::RANGES[ $key ][1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( isset( self::CHOICES[ $key ] ) && ! in_array( $value, self::CHOICES[ $key ], true ) ) {
			throw new \InvalidArgumentException( $key . ' erlaubt: ' . implode( ', ', self::CHOICES[ $key ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$all         = self::all();
		$old         = $all[ $key ] ?? null;
		$all[ $key ] = $value;
		update_option( self::OPTION, $all, false );
		if ( $old !== $value ) {
			/**
			 * Eine Einstellung wurde geändert (für das Prüfprotokoll). Einstellungen enthalten keine Geheimnisse.
			 *
			 * @param string $key   Schlüssel.
			 * @param mixed  $old   Alter Wert.
			 * @param mixed  $value Neuer Wert.
			 */
			do_action( 'lsec_setting_changed', $key, $old, $value );
		}
	}
}
