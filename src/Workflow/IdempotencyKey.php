<?php
/**
 * Idempotenz-Schlüssel für Abläufe.
 *
 * @package Lsec
 */

namespace Lsec\Workflow;

/**
 * Bildet aus Typ, Gegenstand und Zusatzangaben einen stabilen SHA-256-Schlüssel.
 */
final class IdempotencyKey {

	/**
	 * Erzeugt den Schlüssel.
	 *
	 * @param string               $type         Ablauftyp, z. B. content_production.
	 * @param string|null          $subject_type Gegenstand, z. B. topic.
	 * @param int|null             $subject_id   ID des Gegenstands.
	 * @param array<string, mixed> $extra        Weitere Merkmale, z. B. Briefing-Version.
	 */
	public static function for_run( string $type, ?string $subject_type, ?int $subject_id, array $extra = array() ): string {
		ksort( $extra );
		$payload = array(
			'type'    => $type,
			'subject' => array( $subject_type, $subject_id ),
			'extra'   => $extra,
		);
		// Reines PHP, damit der Schlüssel ohne WordPress testbar bleibt.
		return hash( 'sha256', json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
