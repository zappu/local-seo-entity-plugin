<?php
/**
 * JSON-Hilfen für die KI-Adapter.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\AI;

/**
 * Reines PHP, damit die Adapter ohne WordPress testbar bleiben.
 */
final class Json {

	/**
	 * Kodiert ein Array als JSON.
	 *
	 * @param array<mixed> $data Daten.
	 */
	public static function encode( array $data ): string {
		return json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Dekodiert JSON zu einem Array oder null.
	 *
	 * @param string $json JSON.
	 * @return array<mixed>|null
	 */
	public static function decode( string $json ): ?array {
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Holt ein JSON-Objekt aus einer Modellantwort. Entfernt Markdown-Codezäune und
	 * Text vor oder nach dem eigentlichen Objekt.
	 *
	 * @param string $text Antworttext.
	 * @return array<mixed>|null
	 */
	public static function extract( string $text ): ?array {
		$text = trim( $text );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
			$text = $m[1];
		}
		$data = self::decode( $text );
		if ( null !== $data ) {
			return $data;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			return self::decode( substr( $text, $start, $end - $start + 1 ) );
		}
		return null;
	}
}
