<?php
/**
 * Letzter Stand des Textfocus-Kontos.
 *
 * @package Lsec
 */

namespace Lsec\Integrations\Textfocus;

/**
 * Merkt sich Tarif und Restguthaben aus tf_account für das Dashboard.
 * Gespeichert werden nur diese Angaben, keine Adressdaten aus der Antwort.
 */
final class AccountSnapshot {

	public const OPTION = 'lsec_textfocus_account';

	/**
	 * Speichert den Stand.
	 *
	 * @param array{api_access: bool|null, pack: string, credits_remaining: float|null, consumed_30_days: float|null, rate_interval: mixed, rate_max: mixed, nb_compete_max: mixed, entities_access: bool|null} $info Konto.
	 */
	public static function save( array $info ): void {
		update_option(
			self::OPTION,
			array(
				'api_access'        => $info['api_access'],
				'pack'              => mb_substr( $info['pack'], 0, 100 ),
				'credits_remaining' => $info['credits_remaining'],
				'rate_max'          => is_scalar( $info['rate_max'] ) ? $info['rate_max'] : null,
				'checked_at'        => time(),
			),
			false
		);
	}

	/**
	 * Letzter Stand oder null.
	 *
	 * @return array{api_access: bool|null, pack: string, credits_remaining: float|null, rate_max: mixed, checked_at: int}|null
	 */
	public static function get(): ?array {
		$value = get_option( self::OPTION, null );
		return is_array( $value ) && isset( $value['checked_at'] ) ? $value : null;
	}
}
