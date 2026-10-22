<?php
/**
 * Unternehmensprofil.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Core\Settings;
use Lsec\Infrastructure\Database\Schema;
use Lsec\Research\Keywords\KeywordNormalizer;

/**
 * Spezifikation 4.2: Das Profil wird nicht doppelt gespeichert. Unternehmen, Standorte, Leistungen,
 * Marken und Wettbewerber sind Entitäten mit origin = business_profile und status = confirmed, verbunden
 * mit dem Unternehmen über Beziehungen. Adressen, Öffnungszeiten und Telefonnummern der Standorte sind
 * Aussagen (lsec_claims). Das Servicegebiet steht zusätzlich in der Einstellung service_area.
 *
 * Erkennt die KI später eine Schreibweise, findet EntityRepository::find() die Profil-Entität.
 */
final class BusinessProfile {

	public const ORIGIN = 'business_profile';

	/**
	 * Listen des Profils: Schlüssel → Entitätstyp und Beziehung vom Unternehmen aus.
	 */
	private const LISTS = array(
		'locations'   => array(
			'type'     => 'place',
			'relation' => 'located_in',
		),
		'services'    => array(
			'type'     => 'service',
			'relation' => 'offers',
		),
		'brands'      => array(
			'type'     => 'brand',
			'relation' => 'offers',
		),
		'competitors' => array(
			'type'     => 'competitor',
			'relation' => 'competes_with',
		),
	);

	/**
	 * Aussagen eines Standorts.
	 */
	private const LOCATION_CLAIMS = array( 'street', 'postal_code', 'city', 'phone', 'opening_hours' );

	/**
	 * Konstruktor.
	 *
	 * @param EntityRepository $entities Entitäten.
	 */
	public function __construct( private readonly EntityRepository $entities = new EntityRepository() ) {}

	/**
	 * Das Profil für Oberfläche und WP-CLI.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$org     = $this->organization_row();
		$profile = array(
			'organization' => null === $org ? array(
				'id'          => null,
				'name'        => '',
				'description' => '',
				'website'     => home_url( '/' ),
				'phone'       => '',
				'email'       => '',
			) : array(
				'id'          => (int) $org['id'],
				'name'        => (string) $org['canonical_name'],
				'description' => (string) $org['description'],
				'website'     => (string) ( $org['properties']['website'] ?? '' ),
				'phone'       => (string) ( $org['properties']['phone'] ?? '' ),
				'email'       => (string) ( $org['properties']['email'] ?? '' ),
			),
			'service_area' => Settings::get( 'service_area' ),
			'source_urls'  => array_values( array_map( 'strval', (array) Settings::get( 'profile_source_urls' ) ) ),
		);
		foreach ( self::LISTS as $key => $list ) {
			$items = array();
			foreach ( $this->rows( $list['type'] ) as $row ) {
				$item = array(
					'id'          => (int) $row['id'],
					'name'        => (string) $row['canonical_name'],
					'description' => (string) $row['description'],
				);
				if ( 'locations' === $key ) {
					$claims = $this->claims( (int) $row['id'] );
					foreach ( self::LOCATION_CLAIMS as $property ) {
						$item[ $property ] = (string) ( $claims[ $property ] ?? '' );
					}
				}
				if ( 'competitors' === $key || 'brands' === $key ) {
					$item['website'] = (string) ( $row['properties']['website'] ?? '' );
				}
				if ( 'brands' === $key ) {
					$item['own'] = ! empty( $row['properties']['own'] );
				}
				$items[] = $item;
			}
			$profile[ $key ] = $items;
		}
		return $profile;
	}

	/**
	 * Speichert das Profil. Einträge, die fehlen, werden abgelehnt (nicht gelöscht), damit Fundstellen bleiben.
	 *
	 * @param array<string, mixed> $profile Profil wie aus get().
	 * @param int                  $user_id Benutzer.
	 * @return array{organization: int, saved: int, removed: int}
	 * @throws \InvalidArgumentException Ohne Firmennamen.
	 */
	public function save( array $profile, int $user_id ): array {
		$org_data = is_array( $profile['organization'] ?? null ) ? $profile['organization'] : array();
		$name     = self::text( $org_data['name'] ?? '' );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Der Name des Unternehmens fehlt.' );
		}
		$org_id = $this->upsert(
			'organization',
			isset( $org_data['id'] ) ? (int) $org_data['id'] : 0,
			$name,
			self::text( $org_data['description'] ?? '', 2000 ),
			array(
				'website' => esc_url_raw( (string) ( $org_data['website'] ?? '' ) ),
				'phone'   => self::text( $org_data['phone'] ?? '', 50 ),
				'email'   => sanitize_email( (string) ( $org_data['email'] ?? '' ) ),
			),
			$user_id
		);
		$out    = array(
			'organization' => $org_id,
			'saved'        => 1,
			'removed'      => 0,
		);

		foreach ( self::LISTS as $key => $list ) {
			$keep = array();
			foreach ( (array) ( $profile[ $key ] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || '' === self::text( $item['name'] ?? '' ) ) {
					continue;
				}
				$properties = array();
				if ( 'competitors' === $key || 'brands' === $key ) {
					$properties['website'] = esc_url_raw( (string) ( $item['website'] ?? '' ) );
				}
				if ( 'brands' === $key ) {
					$properties['own'] = ! empty( $item['own'] );
				}
				if ( 'locations' === $key ) {
					$properties['kind'] = 'location';
				}
				$id     = $this->upsert( $list['type'], isset( $item['id'] ) ? (int) $item['id'] : 0, self::text( $item['name'] ), self::text( $item['description'] ?? '', 2000 ), $properties, $user_id );
				$keep[] = $id;
				$type   = 'brands' === $key && ! empty( $item['own'] ) ? 'owns_brand' : $list['relation'];
				$this->relate( $org_id, $type, $id );
				if ( 'locations' === $key ) {
					$values = array();
					foreach ( self::LOCATION_CLAIMS as $property ) {
						$values[ $property ] = self::text( $item[ $property ] ?? '', 500 );
					}
					$this->set_claims( $id, $values );
				}
				++$out['saved'];
			}
			$out['removed'] += $this->retire( $list['type'], $keep, $org_id, $user_id );
		}

		$area = ServiceArea::from_settings();
		if ( null !== $area ) {
			$area_id = $this->upsert( 'service_area', 0, $area->label, '', array(), $user_id, array( 'wikidata' => $area->wikidata_ids[0] ?? null ) );
			$this->relate( $org_id, 'serves_area', $area_id );
		}
		return $out;
	}

	/**
	 * Unternehmen aus dem Profil.
	 *
	 * @return array<string, mixed>|null
	 */
	public function organization_row(): ?array {
		$rows = $this->rows( 'organization' );
		return $rows[0] ?? null;
	}

	/**
	 * Profil-Entitäten eines Typs.
	 *
	 * @param string $type Typ.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $type ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entities' );
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, canonical_name, description, properties FROM {$table} WHERE origin = %s AND type = %s AND status = 'confirmed' ORDER BY id", self::ORIGIN, $type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$properties        = json_decode( (string) $row['properties'], true );
			$row['properties'] = is_array( $properties ) ? $properties : array();
		}
		return $rows;
	}

	/**
	 * Legt eine Profil-Entität an oder aktualisiert sie. Gibt es schon eine Entität gleichen Namens
	 * (z. B. von der KI erkannt), wird sie übernommen und bestätigt.
	 *
	 * @param string               $type         Typ.
	 * @param int                  $id           Bekannte ID oder 0.
	 * @param string               $name         Name.
	 * @param string               $description  Beschreibung.
	 * @param array<string, mixed> $properties   Eigenschaften (werden ergänzt).
	 * @param int                  $user_id      Benutzer.
	 * @param array<string, mixed> $external_ids Externe IDs.
	 */
	private function upsert( string $type, int $id, string $name, string $description, array $properties, int $user_id, array $external_ids = array() ): int {
		global $wpdb;
		$table      = Schema::table( $wpdb->prefix, 'entities' );
		$normalized = mb_substr( KeywordNormalizer::normalize( $name ), 0, 255 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id > 0 ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND type = %s AND status <> 'merged'", $id, $type ) );
		}
		if ( 0 === $id ) {
			$id = $this->entities->find( $type, $normalized );
		}
		if ( 0 === $id ) {
			$id = $this->entities->add( $type, $name, 'confirmed', self::ORIGIN, $user_id )['id'];
		}
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT properties, external_ids FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		$current = json_decode( (string) ( $row['properties'] ?? '' ), true );
		$ids     = json_decode( (string) ( $row['external_ids'] ?? '' ), true );
		$ids     = array_filter( array_merge( is_array( $ids ) ? $ids : array(), $external_ids ) );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update(
			$table,
			array(
				'canonical_name'  => mb_substr( $name, 0, 255 ),
				'normalized_name' => $normalized,
				'description'     => '' !== $description ? $description : null,
				'properties'      => wp_json_encode( array_merge( is_array( $current ) ? $current : array(), $properties ), JSON_UNESCAPED_UNICODE ),
				'external_ids'    => array() === $ids ? null : wp_json_encode( $ids ),
				'status'          => 'confirmed',
				'origin'          => self::ORIGIN,
				'decided_by'      => $user_id,
				'decided_at'      => $now,
				'updated_at'      => $now,
			),
			array( 'id' => $id )
		);
		// phpcs:enable
		return $id;
	}

	/**
	 * Beziehung vom Unternehmen aus, bestätigt.
	 *
	 * @param int    $source Quelle.
	 * @param string $type   Beziehung.
	 * @param int    $target Ziel.
	 */
	private function relate( int $source, string $type, int $target ): void {
		global $wpdb;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = Schema::table( $wpdb->prefix, 'relations' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source_entity_id, relation_type, target_entity_id, status, confidence, origin, created_at, updated_at)
				VALUES (%d, %s, %d, 'confirmed', 1, %s, %s, %s)
				ON DUPLICATE KEY UPDATE status = 'confirmed', origin = VALUES(origin), updated_at = VALUES(updated_at)",
				$source,
				$type,
				$target,
				self::ORIGIN,
				$now,
				$now
			)
		);
		// phpcs:enable
	}

	/**
	 * Lehnt Profil-Einträge ab, die nicht mehr im Profil stehen, und entfernt ihre Profil-Beziehungen.
	 *
	 * @param string $type    Typ.
	 * @param int[]  $keep    Behaltene IDs.
	 * @param int    $org_id  Unternehmen.
	 * @param int    $user_id Benutzer.
	 * @return int Anzahl.
	 */
	private function retire( string $type, array $keep, int $org_id, int $user_id ): int {
		global $wpdb;
		$removed = 0;
		$now     = gmdate( 'Y-m-d H:i:s' );
		foreach ( $this->rows( $type ) as $row ) {
			$id = (int) $row['id'];
			if ( in_array( $id, $keep, true ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				Schema::table( $wpdb->prefix, 'entities' ),
				array(
					'status'     => 'rejected',
					'decided_by' => $user_id,
					'decided_at' => $now,
					'updated_at' => $now,
				),
				array( 'id' => $id )
			);
			$wpdb->delete(
				Schema::table( $wpdb->prefix, 'relations' ),
				array(
					'source_entity_id' => $org_id,
					'target_entity_id' => $id,
					'origin'           => self::ORIGIN,
				)
			);
			// phpcs:enable
			++$removed;
		}
		return $removed;
	}

	/**
	 * Aussagen eines Standorts als Schlüssel → Wert.
	 *
	 * @param int $entity_id Entität.
	 * @return array<string, string>
	 */
	private function claims( int $entity_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT property, value FROM ' . Schema::table( $wpdb->prefix, 'claims' ) . " WHERE subject_type = 'entity' AND subject_id = %d AND status = 'confirmed'", $entity_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$value                            = json_decode( (string) $row['value'], true );
			$out[ (string) $row['property'] ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $out;
	}

	/**
	 * Ersetzt die Profil-Aussagen eines Standorts (Quelle: Eingabe des Administrators).
	 *
	 * @param int                   $entity_id Entität.
	 * @param array<string, string> $values    Werte; leere werden entfernt.
	 */
	private function set_claims( int $entity_id, array $values ): void {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'claims' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		foreach ( $values as $property => $value ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$table,
				array(
					'subject_type' => 'entity',
					'subject_id'   => $entity_id,
					'property'     => $property,
					'status'       => 'confirmed',
				)
			);
			if ( '' !== $value ) {
				$wpdb->insert(
					$table,
					array(
						'subject_type' => 'entity',
						'subject_id'   => $entity_id,
						'property'     => $property,
						'value'        => (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ),
						'status'       => 'confirmed',
						'created_at'   => $now,
						'updated_at'   => $now,
					)
				);
			}
			// phpcs:enable
		}
	}

	/**
	 * Bereinigter einzeiliger oder mehrzeiliger Text.
	 *
	 * @param mixed $value Wert.
	 * @param int   $max   Höchstlänge.
	 */
	private static function text( mixed $value, int $max = 255 ): string {
		return mb_substr( trim( sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' ) ), 0, $max );
	}
}
