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
		'ai_writing_model'         => '',
		'ai_prices'                => array(),
		'ai_monthly_budget'        => 20.0,
		'ai_run_budget'            => 1.0,
		'ai_web_max_results'       => 3,
		'ai_web_engine'            => 'standard',
		'entity_min_confidence'    => 0.6,
		'entity_auto_extract'      => true,
		'entity_extract_types'     => array( 'page', 'product', 'category', 'product_cat' ),
		'openai_endpoint'          => '',
		'textfocus_lang'           => '',
		'textfocus_locale'         => '',
		'textfocus_daily_budget'   => 25,
		'textfocus_min_interval'   => 60,
		'textfocus_batch_size'     => 10,
		'keyword_analysis'         => true,
		'keyword_auto_volumes'     => true,
		'topic_count'              => 5,
		'topic_weights'            => array(),
		'production_auto'          => true,
		'drafts_per_day'           => 3,
		'draft_author'             => 0,
		'draft_lengths'            => array(),
		'quality_min_score'        => 0.6,
		'quality_min_average'      => 0.75,
		'service_area'             => array(),
		'profile_source_urls'      => array(),
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
		'ai_provider'   => array( 'openrouter', 'anthropic', 'openai' ),
		'ai_web_engine' => array( 'standard', 'native', 'exa', 'parallel', 'perplexity' ),
	);

	/**
	 * Erlaubte Bereiche für Zahlen (einschließlich).
	 *
	 * @var array<string, array{0: int|float, 1: int|float}>
	 */
	public const RANGES = array(
		'ai_web_max_results'    => array( 1, 10 ),
		'entity_min_confidence' => array( 0.0, 1.0 ),
		'topic_count'           => array( 1, 10 ),
		'drafts_per_day'        => array( 0, 20 ),
		'quality_min_score'     => array( 0.0, 1.0 ),
		'quality_min_average'   => array( 0.0, 1.0 ),
	);

	/**
	 * Listen aus Kennungen (Kleinbuchstaben, Ziffern, _ und -). Auch als „a,b,c“ setzbar.
	 *
	 * @var list<string>
	 */
	public const LISTS = array( 'entity_extract_types' );

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
		} elseif ( in_array( $key, self::LISTS, true ) ) {
			$value = self::parse_list( $key, $value );
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

	/**
	 * Liste aus JSON oder „a,b,c“. Bei der Erkennungsauswahl nur Arten, die der Index kennt.
	 *
	 * @param string $key   Schlüssel.
	 * @param mixed  $value Eingabe.
	 * @return list<string>
	 * @throws \InvalidArgumentException Bei unbekannten Werten.
	 */
	private static function parse_list( string $key, mixed $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : ( '' === trim( $value ) ? array() : explode( ',', $value ) );
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( $key . ' erwartet eine Liste, z. B. page,product.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$list = array_values( array_unique( array_filter( array_map( static fn( $v ): string => sanitize_key( (string) $v ), $value ) ) ) );
		if ( 'entity_extract_types' === $key ) {
			$known   = array_keys( \Lsec\Content\ContentIndex::kinds() );
			$unknown = array_diff( $list, $known );
			if ( array() !== $unknown ) {
				throw new \InvalidArgumentException( sprintf( 'Unbekannt: %s. Möglich: %s.', implode( ', ', $unknown ), implode( ', ', $known ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		return $list;
	}
}
