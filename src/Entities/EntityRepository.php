<?php
/**
 * Speichert Entitäten.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Infrastructure\Database\Schema;
use Lsec\Research\Keywords\KeywordNormalizer;

/**
 * Zugriff auf lsec_entities. Entscheidungen des Administrators (confirmed, rejected) werden nie automatisch überschrieben.
 */
final class EntityRepository {

	/**
	 * Status, die nur der Administrator setzt.
	 */
	private const DECIDED = array( 'confirmed', 'rejected' );

	/**
	 * Speichert das Ergebnis des Ortsabgleichs als Entität vom Typ place.
	 *
	 * @param array{status: string, name: string, chosen: array<string, mixed>|null, candidates: array<int, array<string, mixed>>, reason: string} $result Ergebnis aus PlaceResolver.
	 * @param string                                                                                                                               $origin Herkunft.
	 * @return array{id: int, status: string, kept_decision: bool}
	 */
	public function save_place( array $result, string $origin = 'manual' ): array {
		global $wpdb;
		$table      = Schema::table( $wpdb->prefix, 'entities' );
		$normalized = KeywordNormalizer::normalize( $result['name'] );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$existing   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, status FROM {$table} WHERE type = 'place' AND normalized_name = %s LIMIT 1", $normalized ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$chosen     = $result['chosen'];
		$properties = array(
			'candidates'  => array_map(
				static fn( array $c ): array => array(
					'wikidata'    => $c['id'],
					'label'       => $c['label'],
					'description' => $c['description'],
					'coord'       => $c['coord'],
					'distance_km' => $c['distance_km'],
					'in_area'     => $c['in_area'],
				),
				$result['candidates']
			),
			'reason'      => $result['reason'],
			'resolved_at' => $now,
		);
		if ( null !== $chosen && null !== $chosen['coord'] ) {
			$properties['coord'] = $chosen['coord'];
		}
		$data = array(
			'description'      => null === $chosen ? null : (string) $chosen['description'],
			'properties'       => wp_json_encode( $properties, JSON_UNESCAPED_UNICODE ),
			'external_ids'     => null === $chosen ? null : wp_json_encode( array( 'wikidata' => $chosen['id'] ) ),
			'status'           => $result['status'],
			'confidence'       => 'probable' === $result['status'] ? 0.8 : null,
			'last_verified_at' => $now,
			'updated_at'       => $now,
		);

		if ( is_array( $existing ) ) {
			$id = (int) $existing['id'];
			if ( in_array( (string) $existing['status'], self::DECIDED, true ) ) {
				$wpdb->update( $table, array( 'last_verified_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return array(
					'id'            => $id,
					'status'        => (string) $existing['status'],
					'kept_decision' => true,
				);
			}
			$wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'id'            => $id,
				'status'        => $result['status'],
				'kept_decision' => false,
			);
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			$data + array(
				'uuid'            => wp_generate_uuid4(),
				'type'            => 'place',
				'canonical_name'  => mb_substr( $result['name'], 0, 255 ),
				'normalized_name' => mb_substr( $normalized, 0, 255 ),
				'origin'          => $origin,
				'created_at'      => $now,
			)
		);
		return array(
			'id'            => (int) $wpdb->insert_id,
			'status'        => $result['status'],
			'kept_decision' => false,
		);
	}

	/**
	 * Speichert erkannte Entitäten eines Beitrags als Kandidaten mit Fundstellen (M11).
	 * Vorhandene Entitäten (gleicher Typ und Name oder Alias) werden wiederverwendet. Fundstellen
	 * des Beitrags werden bei jedem Lauf ersetzt, damit eine Wiederholung keine Dubletten erzeugt.
	 *
	 * @param int                                                                                                                                                                      $post_id  Beitrag.
	 * @param array<int, array{type: string, name: string, aliases: string[], description: string, confidence: float, evidence: array<int, array{excerpt: string, location: string}>}> $entities Geprüfte Vorschläge.
	 * @return array{created: int, reused: int, mentions: int}
	 */
	public function save_extraction( int $post_id, array $entities ): array {
		global $wpdb;
		$table    = Schema::table( $wpdb->prefix, 'entities' );
		$aliases  = Schema::table( $wpdb->prefix, 'entity_aliases' );
		$mentions = Schema::table( $wpdb->prefix, 'mentions' );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$out      = array(
			'created'  => 0,
			'reused'   => 0,
			'mentions' => 0,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete(
			$mentions,
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			)
		);
		foreach ( $entities as $entity ) {
			$normalized = mb_substr( KeywordNormalizer::normalize( $entity['name'] ), 0, 255 );
			$id         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND normalized_name = %s LIMIT 1", $entity['type'], $normalized ) );
			if ( 0 === $id ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$table} e JOIN {$aliases} a ON a.entity_id = e.id WHERE e.type = %s AND a.normalized_alias = %s LIMIT 1", $entity['type'], $normalized ) );
			}
			if ( 0 === $id ) {
				$wpdb->insert(
					$table,
					array(
						'uuid'            => wp_generate_uuid4(),
						'type'            => $entity['type'],
						'canonical_name'  => mb_substr( $entity['name'], 0, 255 ),
						'normalized_name' => $normalized,
						'description'     => '' !== $entity['description'] ? $entity['description'] : null,
						'status'          => 'candidate',
						'confidence'      => round( $entity['confidence'], 3 ),
						'origin'          => 'ai_extraction',
						'created_at'      => $now,
						'updated_at'      => $now,
					)
				);
				$id = (int) $wpdb->insert_id;
				++$out['created'];
			} else {
				++$out['reused'];
			}
			foreach ( $entity['aliases'] as $alias ) {
				$normalized_alias = mb_substr( KeywordNormalizer::normalize( $alias ), 0, 255 );
				if ( '' === $normalized_alias || $normalized_alias === $normalized ) {
					continue;
				}
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aliases} WHERE entity_id = %d AND normalized_alias = %s", $id, $normalized_alias ) );
				if ( 0 === $exists ) {
					$wpdb->insert(
						$aliases,
						array(
							'entity_id'        => $id,
							'alias'            => mb_substr( $alias, 0, 255 ),
							'normalized_alias' => $normalized_alias,
							'created_at'       => $now,
						)
					);
				}
			}
			foreach ( $entity['evidence'] as $evidence ) {
				$wpdb->insert(
					$mentions,
					array(
						'entity_id'   => $id,
						'object_type' => 'post',
						'object_id'   => $post_id,
						'location'    => $evidence['location'],
						'excerpt'     => $evidence['excerpt'],
						'created_at'  => $now,
					)
				);
				++$out['mentions'];
			}
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Fundstellen einer Entität.
	 *
	 * @param int $entity_id Entität.
	 * @return array<int, array<string, mixed>>
	 */
	public function mentions( int $entity_id ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'mentions' );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT object_type, object_id, location, excerpt, created_at FROM {$table} WHERE entity_id = %d ORDER BY id", $entity_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Namen aller nicht abgelehnten Entitäten, für die Schreibweise in Prompts.
	 *
	 * @return string[]
	 */
	public function known_names(): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entities' );
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT canonical_name FROM {$table} WHERE status <> 'rejected' ORDER BY status = 'confirmed' DESC, updated_at DESC LIMIT 200" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Entscheidung des Administrators: bestätigen oder ablehnen.
	 *
	 * @param int    $id       Entität.
	 * @param string $decision confirmed oder rejected.
	 * @param int    $user_id  Benutzer.
	 * @return bool Ob die Entität existiert.
	 * @throws \InvalidArgumentException Bei unbekannter Entscheidung.
	 */
	public function decide( int $id, string $decision, int $user_id ): bool {
		global $wpdb;
		if ( ! in_array( $decision, self::DECIDED, true ) ) {
			throw new \InvalidArgumentException( 'Entscheidung: confirmed oder rejected.' );
		}
		$table  = Schema::table( $wpdb->prefix, 'entities' );
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $exists ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'status'     => $decision,
				'decided_by' => $user_id,
				'decided_at' => $now,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);
		return true;
	}

	/**
	 * Entitäten für die Anzeige.
	 *
	 * @param string $type   Typ oder leer.
	 * @param string $status Status oder leer.
	 * @param int    $limit  Höchstzahl.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( string $type = '', string $status = '', int $limit = 50 ): array {
		global $wpdb;
		$table  = Schema::table( $wpdb->prefix, 'entities' );
		$mtable = Schema::table( $wpdb->prefix, 'mentions' );
		$where  = 'WHERE 1=1';
		$params = array();
		if ( '' !== $type ) {
			$where   .= ' AND type = %s';
			$params[] = $type;
		}
		if ( '' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		$params[] = $limit;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, type, canonical_name, status, confidence, external_ids, origin, decided_by, decided_at, updated_at, (SELECT COUNT(*) FROM {$mtable} m WHERE m.entity_id = {$table}.id) AS mentions FROM {$table} {$where} ORDER BY updated_at DESC LIMIT %d", $params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}
}
