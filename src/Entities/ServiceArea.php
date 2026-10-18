<?php
/**
 * Servicegebiet als Kontext für den Ortsabgleich.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Core\Settings;

/**
 * Verwaltungseinheiten (Wikidata) und/oder Mittelpunkt mit Radius.
 * Gespeichert in der Einstellung service_area, z. B.
 * {"label":"Region Hannover","wikidata":["Q…"],"center":[52.37,9.73],"radius_km":40}
 */
final class ServiceArea {

	/**
	 * Konstruktor.
	 *
	 * @param string                         $label        Anzeigename.
	 * @param string[]                       $wikidata_ids Verwaltungseinheiten.
	 * @param array{0: float, 1: float}|null $center       Mittelpunkt.
	 * @param float                          $radius_km    Radius, 0 = nur Verwaltungseinheiten.
	 */
	public function __construct(
		public readonly string $label,
		public readonly array $wikidata_ids,
		public readonly ?array $center = null,
		public readonly float $radius_km = 0.0
	) {}

	/**
	 * Aus einem Array (Einstellung).
	 *
	 * @param array<string, mixed> $data Daten.
	 */
	public static function from_array( array $data ): ?self {
		$ids    = array_values( array_filter( array_map( 'strval', (array) ( $data['wikidata'] ?? array() ) ), static fn( string $id ): bool => (bool) preg_match( '/^Q\d+$/', $id ) ) );
		$center = null;
		if ( isset( $data['center'][0], $data['center'][1] ) && is_numeric( $data['center'][0] ) && is_numeric( $data['center'][1] ) ) {
			$center = array( (float) $data['center'][0], (float) $data['center'][1] );
		}
		$radius = is_numeric( $data['radius_km'] ?? null ) ? (float) $data['radius_km'] : 0.0;
		if ( array() === $ids && ( null === $center || $radius <= 0 ) ) {
			return null;
		}
		return new self( (string) ( $data['label'] ?? implode( ', ', $ids ) ), $ids, $center, $radius );
	}

	/**
	 * Aus der Einstellung service_area.
	 */
	public static function from_settings(): ?self {
		$value = Settings::get( 'service_area' );
		return is_array( $value ) ? self::from_array( $value ) : null;
	}

	/**
	 * Als Array für die Einstellung.
	 *
	 * @return array{label: string, wikidata: string[], center: array{0: float, 1: float}|null, radius_km: float}
	 */
	public function to_array(): array {
		return array(
			'label'     => $this->label,
			'wikidata'  => $this->wikidata_ids,
			'center'    => $this->center,
			'radius_km' => $this->radius_km,
		);
	}
}
