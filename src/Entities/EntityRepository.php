<?php
/**
 * Speichert Entitäten.
 *
 * @package Lsec
 */

namespace Lsec\Entities;

use Lsec\Content\IndexItem;
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
	 * Status, die der Ortsabgleich nicht überschreibt.
	 */
	private const KEEP = array( 'confirmed', 'rejected', 'merged' );

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
			if ( in_array( (string) $existing['status'], self::KEEP, true ) ) {
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
	 * Speichert erkannte Entitäten eines Beitrags oder einer Kategorie als Kandidaten mit Fundstellen (M11).
	 * Vorhandene Entitäten (gleicher Typ und Name oder Alias) werden wiederverwendet. Fundstellen
	 * des Beitrags werden bei jedem Lauf ersetzt, damit eine Wiederholung keine Dubletten erzeugt.
	 *
	 * @param int                                                                                                                                                                      $post_id     Beitrag oder Kategorie.
	 * @param array<int, array{type: string, name: string, aliases: string[], description: string, confidence: float, evidence: array<int, array{excerpt: string, location: string}>}> $entities    Geprüfte Vorschläge.
	 * @param string                                                                                                                                                                   $object_type post oder term.
	 * @return array{created: int, reused: int, mentions: int}
	 */
	public function save_extraction( int $post_id, array $entities, string $object_type = 'post' ): array {
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
				'object_type' => $object_type,
				'object_id'   => $post_id,
			)
		);
		foreach ( $entities as $entity ) {
			$normalized = mb_substr( KeywordNormalizer::normalize( $entity['name'] ), 0, 255 );
			$id         = $this->find( $entity['type'], $normalized );
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
						'object_type' => $object_type,
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
	 * Sucht eine vorhandene Entität für einen Vorschlag.
	 *
	 * Reihenfolge: gleicher Typ und Name, gleicher Typ und Alias, bestätigte Entität gleichen Namens mit
	 * anderem Typ (die KI ordnet die eigene Firma mal als organization, mal als brand ein). Eine
	 * zusammengeführte Entität verweist auf ihr Ziel.
	 *
	 * @param string $type       Typ.
	 * @param string $normalized Normalisierter Name.
	 * @return int ID oder 0.
	 */
	public function find( string $type, string $normalized ): int {
		global $wpdb;
		$table   = Schema::table( $wpdb->prefix, 'entities' );
		$aliases = Schema::table( $wpdb->prefix, 'entity_aliases' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND normalized_name = %s ORDER BY status = 'merged' LIMIT 1", $type, $normalized ) );
		if ( 0 === $id ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$table} e JOIN {$aliases} a ON a.entity_id = e.id WHERE e.type = %s AND a.normalized_alias = %s ORDER BY e.status = 'merged' LIMIT 1", $type, $normalized ) );
		}
		if ( 0 === $id ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$table} e LEFT JOIN {$aliases} a ON a.entity_id = e.id WHERE e.status = 'confirmed' AND ( e.normalized_name = %s OR a.normalized_alias = %s ) LIMIT 1", $normalized, $normalized ) );
		}
		for ( $hop = 0; $id > 0 && $hop < 5; $hop++ ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, merged_into FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			if ( ! is_array( $row ) || 'merged' !== $row['status'] || empty( $row['merged_into'] ) ) {
				break;
			}
			$id = (int) $row['merged_into'];
		}
		// phpcs:enable
		return $id;
	}

	/**
	 * Übernimmt Entitäten aus einem Textfocus-Export als Kandidaten (origin import).
	 * Vorhandene Entitäten (gleicher Typ und Name oder Alias, „&“ wie „und“) werden nur ergänzt.
	 * Der Wikidata-Vorschlag von Textfocus landet in properties.textfocus, nicht in external_ids.
	 *
	 * @param array<int, array{name: string, entity: string, type: string, source_type: string, relevance: float|null, wikidata: string, wikipedia: string}> $rows Zeilen.
	 * @return array{created: int, reused: int}
	 */
	public function save_textfocus( array $rows ): array {
		global $wpdb;
		$table   = Schema::table( $wpdb->prefix, 'entities' );
		$aliases = Schema::table( $wpdb->prefix, 'entity_aliases' );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$out     = array(
			'created' => 0,
			'reused'  => 0,
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$normalized = mb_substr( KeywordNormalizer::normalize( $row['name'] ), 0, 255 );
			$variants   = array_unique( array( $normalized, str_replace( ' & ', ' und ', $normalized ), str_replace( ' und ', ' & ', $normalized ) ) );
			$id         = 0;
			foreach ( $variants as $variant ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s AND normalized_name = %s LIMIT 1", $row['type'], $variant ) );
				if ( 0 === $id ) {
					$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$table} e JOIN {$aliases} a ON a.entity_id = e.id WHERE e.type = %s AND a.normalized_alias = %s LIMIT 1", $row['type'], $variant ) );
				}
				if ( $id > 0 ) {
					break;
				}
			}
			$hint = array(
				'entity'    => $row['entity'],
				'type'      => $row['source_type'],
				'relevance' => $row['relevance'],
				'wikidata'  => $row['wikidata'],
				'wikipedia' => $row['wikipedia'],
				'imported'  => $now,
			);
			if ( $id > 0 ) {
				$properties              = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT properties FROM {$table} WHERE id = %d", $id ) ), true );
				$properties              = is_array( $properties ) ? $properties : array();
				$properties['textfocus'] = $hint;
				$wpdb->update(
					$table,
					array(
						'properties' => wp_json_encode( $properties, JSON_UNESCAPED_UNICODE ),
						'updated_at' => $now,
					),
					array( 'id' => $id )
				);
				++$out['reused'];
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'uuid'            => wp_generate_uuid4(),
					'type'            => $row['type'],
					'canonical_name'  => mb_substr( $row['name'], 0, 255 ),
					'normalized_name' => $normalized,
					'description'     => $row['entity'] !== $row['name'] ? 'Textfocus: ' . $row['entity'] : null,
					'properties'      => wp_json_encode( array( 'textfocus' => $hint ), JSON_UNESCAPED_UNICODE ),
					'status'          => 'candidate',
					'confidence'      => null === $row['relevance'] ? null : round( max( 0.0, min( 1.0, $row['relevance'] ) ), 3 ),
					'origin'          => 'import',
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			++$out['created'];
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Führt Entitäten zusammen (Spezifikation 8.3): Die Namen der Quellen werden Aliasse des Ziels,
	 * Fundstellen, Beziehungen, Aussagen, Belege und Themenbezüge wandern zum Ziel. Die Quellen bleiben
	 * mit Status merged und Verweis merged_into erhalten, damit spätere Erkennungen zum Ziel finden.
	 *
	 * @param int   $target  Ziel.
	 * @param int[] $sources Quellen.
	 * @param int   $user_id Benutzer.
	 * @return array{merged: int[], aliases: int, mentions: int}
	 * @throws \InvalidArgumentException Bei ungültigen IDs.
	 */
	public function merge( int $target, array $sources, int $user_id ): array {
		global $wpdb;
		$t   = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$goal = $wpdb->get_row( $wpdb->prepare( "SELECT id, normalized_name, status, external_ids FROM {$t( 'entities' )} WHERE id = %d", $target ), ARRAY_A );
		if ( ! is_array( $goal ) || in_array( $goal['status'], array( 'merged', 'rejected' ), true ) ) {
			throw new \InvalidArgumentException( 'Ziel nicht gefunden, abgelehnt oder selbst zusammengeführt.' );
		}
		$out = array(
			'merged'   => array(),
			'aliases'  => 0,
			'mentions' => 0,
		);
		foreach ( array_unique( array_map( 'intval', $sources ) ) as $source ) {
			if ( $source === $target ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, canonical_name, normalized_name, status, external_ids FROM {$t( 'entities' )} WHERE id = %d", $source ), ARRAY_A );
			if ( ! is_array( $row ) || 'merged' === $row['status'] ) {
				throw new \InvalidArgumentException( 'Quelle ' . $source . ' nicht gefunden oder schon zusammengeführt.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			// Name und Aliasse der Quelle werden Aliasse des Ziels.
			$names   = array( (string) $row['canonical_name'] => (string) $row['normalized_name'] );
			$aliases = (array) $wpdb->get_results( $wpdb->prepare( "SELECT alias, normalized_alias FROM {$t( 'entity_aliases' )} WHERE entity_id = %d", $source ), ARRAY_A );
			foreach ( $aliases as $alias ) {
				$names[ (string) $alias['alias'] ] = (string) $alias['normalized_alias'];
			}
			foreach ( $names as $alias => $normalized ) {
				if ( $normalized === $goal['normalized_name'] ) {
					continue;
				}
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t( 'entity_aliases' )} WHERE entity_id = %d AND normalized_alias = %s", $target, $normalized ) );
				if ( 0 === $exists ) {
					$wpdb->insert(
						$t( 'entity_aliases' ),
						array(
							'entity_id'        => $target,
							'alias'            => mb_substr( $alias, 0, 255 ),
							'normalized_alias' => $normalized,
							'created_at'       => $now,
						)
					);
					++$out['aliases'];
				}
			}

			$out['mentions'] += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t( 'mentions' )} SET entity_id = %d WHERE entity_id = %d", $target, $source ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t( 'claims' )} SET subject_id = %d WHERE subject_type = 'entity' AND subject_id = %d", $target, $source ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t( 'evidence' )} SET target_id = %d WHERE target_type = 'entity' AND target_id = %d", $target, $source ) );
			// Doppelte Kanten und Themenbezüge entfallen (UNIQUE bzw. Primärschlüssel).
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$t( 'relations' )} SET source_entity_id = %d WHERE source_entity_id = %d", $target, $source ) );
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$t( 'relations' )} SET target_entity_id = %d WHERE target_entity_id = %d", $target, $source ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'relations' )} WHERE source_entity_id = %d OR target_entity_id = %d OR source_entity_id = target_entity_id", $source, $source ) );
			$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$t( 'topic_entities' )} SET entity_id = %d WHERE entity_id = %d", $target, $source ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t( 'topic_entities' )} WHERE entity_id = %d", $source ) );

			if ( empty( json_decode( (string) $goal['external_ids'], true ) ) && ! empty( json_decode( (string) $row['external_ids'], true ) ) ) {
				$wpdb->update( $t( 'entities' ), array( 'external_ids' => $row['external_ids'] ), array( 'id' => $target ) );
				$goal['external_ids'] = $row['external_ids'];
			}
			$wpdb->update(
				$t( 'entities' ),
				array(
					'status'      => 'merged',
					'merged_into' => $target,
					'decided_by'  => $user_id,
					'decided_at'  => $now,
					'updated_at'  => $now,
				),
				array( 'id' => $source )
			);
			// Was schon in die Quelle zusammengeführt war, zeigt jetzt direkt auf das Ziel.
			$wpdb->query( $wpdb->prepare( "UPDATE {$t( 'entities' )} SET merged_into = %d WHERE merged_into = %d", $target, $source ) );
			$out['merged'][] = $source;
		}
		$wpdb->update( $t( 'entities' ), array( 'updated_at' => $now ), array( 'id' => $target ) );
		// phpcs:enable
		EntityHome::merged( $target, array_map( 'intval', (array) $out['merged'] ) );
		return $out;
	}

	/**
	 * Vorschläge zum Zusammenführen: Schreibweisen einer bestätigten Organisation oder Marke.
	 *
	 * Ein Kandidat passt, wenn er mit demselben Wort beginnt und jedes seiner Wörter der Anfang eines Worts
	 * im Namen des Ziels ist oder umgekehrt („Staats Flächenreinigung“ zu „Staats Flächenreinigungs-Service
	 * & Wassertechnik“). Abgelehnte und zusammengeführte Entitäten bleiben außen vor.
	 *
	 * @return array<int, array{target: array{id: int, name: string, type: string}, candidates: array<int, array{id: int, name: string, type: string, status: string, mentions: int}>}>
	 */
	public function merge_suggestions(): array {
		global $wpdb;
		$table  = Schema::table( $wpdb->prefix, 'entities' );
		$mtable = Schema::table( $wpdb->prefix, 'mentions' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$targets = (array) $wpdb->get_results( "SELECT id, canonical_name, type FROM {$table} WHERE status = 'confirmed' AND type IN ('organization', 'brand') ORDER BY origin = 'business_profile' DESC, id", ARRAY_A );
		$others  = (array) $wpdb->get_results( "SELECT id, canonical_name, type, status, (SELECT COUNT(*) FROM {$mtable} m WHERE m.entity_id = e.id) AS mentions FROM {$table} e WHERE status IN ('candidate', 'probable', 'unresolved', 'confirmed') AND type IN ('organization', 'brand', 'concept', 'service')", ARRAY_A );
		// phpcs:enable
		$out  = array();
		$used = array();
		foreach ( $targets as $target ) {
			$candidates = array();
			foreach ( $others as $other ) {
				$id = (int) $other['id'];
				if ( $id === (int) $target['id'] || isset( $used[ $id ] ) ) {
					continue;
				}
				if ( 'confirmed' === $other['status'] && $other['type'] === $target['type'] ) {
					continue;
				}
				if ( self::same_name( (string) $target['canonical_name'], (string) $other['canonical_name'] ) ) {
					$candidates[] = array(
						'id'       => $id,
						'name'     => (string) $other['canonical_name'],
						'type'     => (string) $other['type'],
						'status'   => (string) $other['status'],
						'mentions' => (int) $other['mentions'],
					);
					$used[ $id ]  = true;
				}
			}
			if ( array() !== $candidates ) {
				$out[] = array(
					'target'     => array(
						'id'   => (int) $target['id'],
						'name' => (string) $target['canonical_name'],
						'type' => (string) $target['type'],
					),
					'candidates' => $candidates,
					'reason'     => 'Schreibweisen',
				);
			}
		}
		return array_merge( $out, $this->same_key_suggestions( $used ) );
	}

	/**
	 * Weitere Vorschläge: gleicher Name mit anderem Typ („Hochdruckreinigung“ als Leistung und als Begriff)
	 * und Gebietsangaben zu einem Ort („Region Gehrden“ zu „Gehrden“). Ziel ist der Eintrag aus dem Profil,
	 * sonst der Ort selbst statt der Gebietsangabe, sonst ein bestätigter, sonst der mit den meisten Fundstellen. Echte Verwaltungsgebiete (mit Wikidata-ID
	 * oder aus dem Profil, z. B. „Region Hannover“) werden nie einem gleichnamigen Ort zugeschlagen.
	 *
	 * @param array<int, bool> $used Schon vorgeschlagene Entitäten.
	 * @return array<int, array<string, mixed>>
	 */
	private function same_key_suggestions( array $used ): array {
		global $wpdb;
		$table  = Schema::table( $wpdb->prefix, 'entities' );
		$mtable = Schema::table( $wpdb->prefix, 'mentions' );
		$rows   = (array) $wpdb->get_results( "SELECT id, canonical_name, normalized_name, type, status, origin, external_ids, (SELECT COUNT(*) FROM {$mtable} m WHERE m.entity_id = e.id) AS mentions FROM {$table} e WHERE status IN ('candidate', 'probable', 'unresolved', 'confirmed')", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups = array();
		foreach ( $rows as $row ) {
			if ( isset( $used[ (int) $row['id'] ] ) ) {
				continue;
			}
			$key                     = self::group_key( (string) $row['canonical_name'], (string) $row['type'], BusinessProfile::ORIGIN === $row['origin'] || ! empty( json_decode( (string) $row['external_ids'], true ) ) );
			$groups[ $key['key'] ][] = $row + array( 'area_phrase' => $key['area'] );
		}
		$out = array();
		foreach ( $groups as $members ) {
			if ( count( $members ) < 2 ) {
				continue;
			}
			usort(
				$members,
				static fn( array $a, array $b ): int => array( BusinessProfile::ORIGIN !== $a['origin'], $a['area_phrase'], 'confirmed' !== $a['status'], - (int) $a['mentions'], (int) $a['id'] )
					<=> array( BusinessProfile::ORIGIN !== $b['origin'], $b['area_phrase'], 'confirmed' !== $b['status'], - (int) $b['mentions'], (int) $b['id'] )
			);
			$target     = array_shift( $members );
			$candidates = array();
			foreach ( $members as $member ) {
				if ( BusinessProfile::ORIGIN === $member['origin'] ) {
					continue;
				}
				$candidates[] = array(
					'id'       => (int) $member['id'],
					'name'     => (string) $member['canonical_name'],
					'type'     => (string) $member['type'],
					'status'   => (string) $member['status'],
					'mentions' => (int) $member['mentions'],
				);
			}
			if ( array() === $candidates ) {
				continue;
			}
			$area  = array_filter( $candidates, static fn( array $c ): bool => in_array( $c['type'], array( 'place', 'service_area' ), true ) && $c['name'] !== $target['canonical_name'] );
			$out[] = array(
				'target'     => array(
					'id'   => (int) $target['id'],
					'name' => (string) $target['canonical_name'],
					'type' => (string) $target['type'],
				),
				'candidates' => $candidates,
				'reason'     => array() !== $area ? 'Gebietsangabe zum Ort' : 'gleicher Name, anderer Typ',
			);
		}
		return $out;
	}

	/**
	 * Schlüssel für die Gruppierung: normalisierter Name; bei Orten und Gebieten ohne „Region“, „Raum“,
	 * „Großraum“, „Umgebung“ oder „Umkreis“ davor, außer es ist ein echtes Verwaltungsgebiet.
	 *
	 * @param string $name  Name.
	 * @param string $type  Typ.
	 * @param bool   $fixed Echtes Gebiet (Profil oder Wikidata-ID): nicht kürzen.
	 * @return array{key: string, area: bool}
	 */
	public static function group_key( string $name, string $type, bool $fixed = false ): array {
		$key = KeywordNormalizer::fold( KeywordNormalizer::normalize( $name ) );
		if ( ! $fixed && in_array( $type, array( 'place', 'service_area' ), true ) && preg_match( '/^(?:region|raum|grossraum|umgebung|umkreis)\s+(.+)$/u', $key, $m ) ) {
			return array(
				'key'  => $m[1],
				'area' => true,
			);
		}
		return array(
			'key'  => $key,
			'area' => false,
		);
	}

	/**
	 * Sind zwei Namen Schreibweisen desselben Namens?
	 *
	 * @param string $a Name.
	 * @param string $b Name.
	 */
	public static function same_name( string $a, string $b ): bool {
		$ta = self::tokens( $a );
		$tb = self::tokens( $b );
		if ( array() === $ta || array() === $tb || $ta[0] !== $tb[0] ) {
			return false;
		}
		return $ta === $tb || self::covered( $tb, $ta ) || self::covered( $ta, $tb );
	}

	/**
	 * Wörter eines Namens ohne Füllwörter und Rechtsformen, Umlaute vereinheitlicht.
	 *
	 * @param string $name Name.
	 * @return string[]
	 */
	private static function tokens( string $name ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', KeywordNormalizer::fold( $name ) );
		$skip  = array( 'und', 'and', 'gmbh', 'ug', 'kg', 'ohg', 'ag', 'gbr', 'ek', 'co', 'inh', 'die', 'der', 'das' );
		return array_values( array_filter( (array) $words, static fn( string $w ): bool => mb_strlen( $w ) > 1 && ! in_array( $w, $skip, true ) ) );
	}

	/**
	 * Ist jedes Wort aus $short der Anfang eines Worts aus $long?
	 *
	 * @param string[] $short Wörter.
	 * @param string[] $long  Wörter.
	 */
	private static function covered( array $short, array $long ): bool {
		foreach ( $short as $word ) {
			$hit = false;
			foreach ( $long as $candidate ) {
				if ( str_starts_with( $candidate, $word ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Entfernt die Fundstellen eines gelöschten Beitrags (deleted_post).
	 *
	 * @param int $post_id Beitrag.
	 */
	public static function forget_post( int $post_id ): void {
		self::forget( 'post', $post_id );
	}

	/**
	 * Entfernt die Fundstellen einer gelöschten Kategorie (delete_term).
	 *
	 * @param int $term_id Kategorie.
	 */
	public static function forget_term( int $term_id ): void {
		self::forget( 'term', $term_id );
	}

	/**
	 * Entfernt die Fundstellen eines Objekts.
	 *
	 * @param string $object_type post oder term.
	 * @param int    $object_id   ID.
	 */
	private static function forget( string $object_type, int $object_id ): void {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( $wpdb->prefix, 'mentions' ),
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			)
		);
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
	 * Namen der Leistungen aus dem Unternehmensprofil, für die Zuordnung in der Entity-Erkennung.
	 *
	 * @return string[]
	 */
	public function profile_services(): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entities' );
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT canonical_name FROM {$table} WHERE type = 'service' AND status = 'confirmed' AND origin = %s ORDER BY canonical_name", BusinessProfile::ORIGIN ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Namen aller nicht abgelehnten Entitäten, für die Schreibweise in Prompts.
	 *
	 * @return string[]
	 */
	public function known_names(): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entities' );
		$rows  = (array) $wpdb->get_results( "SELECT canonical_name, type FROM {$table} WHERE status NOT IN ('rejected', 'merged') ORDER BY status = 'confirmed' DESC, updated_at DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( static fn( array $r ): string => $r['canonical_name'] . ' (' . $r['type'] . ')', $rows );
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
	 * Höchstzahl Entitäten je Sammelentscheidung.
	 */
	public const DECIDE_MAX = 200;

	/**
	 * Sammelentscheidung über mehrere Entitäten (Nachtrag 24.10., 0.9.2).
	 *
	 * Übersprungen werden unbekannte und zusammengeführte Entitäten sowie Einträge aus dem
	 * Unternehmensprofil (die werden im Profil gepflegt).
	 *
	 * @param int[]  $ids      Entitäten.
	 * @param string $decision confirmed oder rejected.
	 * @param int    $user_id  Benutzer.
	 * @return array{changed: int[], unchanged: int[], skipped: int[]} unchanged: schon so entschieden.
	 * @throws \InvalidArgumentException Bei ungültiger Entscheidung oder zu vielen Entitäten.
	 */
	public function decide_many( array $ids, string $decision, int $user_id ): array {
		global $wpdb;
		if ( ! in_array( $decision, self::DECIDED, true ) ) {
			throw new \InvalidArgumentException( 'Entscheidung: confirmed oder rejected.' );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( count( $ids ) > self::DECIDE_MAX ) {
			throw new \InvalidArgumentException( sprintf( 'Höchstens %d Entitäten auf einmal.', (int) self::DECIDE_MAX ) );
		}
		if ( array() === $ids ) {
			return array(
				'changed'   => array(),
				'unchanged' => array(),
				'skipped'   => array(),
			);
		}
		$table = Schema::table( $wpdb->prefix, 'entities' );
		$in    = implode( ', ', $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, status FROM {$table} WHERE id IN ({$in}) AND status <> 'merged' AND origin <> %s", BusinessProfile::ORIGIN ),
			ARRAY_A
		);
		$open = array();
		$same = array();
		foreach ( $rows as $row ) {
			if ( $decision === $row['status'] ) {
				$same[] = (int) $row['id'];
			} else {
				$open[] = (int) $row['id'];
			}
		}
		if ( array() !== $open ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, decided_by = %d, decided_at = %s, updated_at = %s WHERE id IN (" . implode( ', ', $open ) . ')',
					$decision,
					$user_id,
					$now,
					$now
				)
			);
		}
		// phpcs:enable
		return array(
			'changed'   => $open,
			'unchanged' => $same,
			'skipped'   => array_values( array_diff( $ids, $open, $same ) ),
		);
	}

	/**
	 * Status, die der Administrator von Hand setzen kann (nicht merged; dafür gibt es merge()).
	 */
	public const EDITABLE_STATUS = array( 'candidate', 'probable', 'unresolved', 'confirmed', 'rejected' );

	/**
	 * Ändert eine Entität: Name, Typ, Beschreibung, Status und weitere Schreibweisen.
	 *
	 * Einträge aus dem Unternehmensprofil werden im Profil gepflegt; hier lassen sich bei ihnen nur
	 * Beschreibung und Schreibweisen ändern. Ein neuer Name darf nicht schon einer anderen Entität desselben
	 * Typs gehören; dann ist Zusammenführen der richtige Weg.
	 *
	 * @param int                  $id      Entität.
	 * @param array<string, mixed> $fields  name, type, description, status, aliases (vollständige Liste).
	 * @param int                  $user_id Benutzer.
	 * @return array<string, mixed> Geänderte Felder mit altem und neuem Wert.
	 * @throws \InvalidArgumentException Bei ungültigen Werten.
	 */
	public function update( int $id, array $fields, int $user_id ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entities' );
		$atab  = Schema::table( $wpdb->prefix, 'entity_aliases' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new \InvalidArgumentException( 'Entität nicht gefunden.' );
		}
		if ( 'merged' === $row['status'] ) {
			throw new \InvalidArgumentException( 'Diese Entität ist zusammengeführt; bitte das Ziel bearbeiten.' );
		}
		$profile = BusinessProfile::ORIGIN === $row['origin'];
		$now     = gmdate( 'Y-m-d H:i:s' );
		$data    = array();
		$changes = array();

		$name = isset( $fields['name'] ) ? trim( (string) $fields['name'] ) : (string) $row['canonical_name'];
		$type = isset( $fields['type'] ) ? (string) $fields['type'] : (string) $row['type'];
		if ( $profile && ( $name !== $row['canonical_name'] || $type !== $row['type'] ) ) {
			throw new \InvalidArgumentException( 'Name und Typ von Einträgen aus dem Unternehmensprofil bitte unter „Unternehmen“ ändern.' );
		}
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Der Name darf nicht leer sein.' );
		}
		if ( ! in_array( $type, EntityExtractor::TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Unbekannter Typ. Typen: ' . implode( ', ', EntityExtractor::TYPES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$normalized = mb_substr( KeywordNormalizer::normalize( $name ), 0, 255 );
		if ( $name !== $row['canonical_name'] || $type !== $row['type'] ) {
			$other = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id <> %d AND type = %s AND normalized_name = %s AND status NOT IN ('merged') LIMIT 1", $id, $type, $normalized ) );
			if ( $other > 0 ) {
				throw new \InvalidArgumentException( sprintf( 'Es gibt schon eine Entität „%s“ dieses Typs (ID %d). Bitte zusammenführen.', $name, $other ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		if ( $name !== $row['canonical_name'] ) {
			$data['canonical_name']  = mb_substr( $name, 0, 255 );
			$data['normalized_name'] = $normalized;
			$changes['name']         = array( $row['canonical_name'], $name );
			// Der alte Name bleibt als Schreibweise, damit spätere Erkennungen ihn wiederfinden.
			$fields['aliases'] = array_merge( isset( $fields['aliases'] ) ? (array) $fields['aliases'] : $this->aliases( $id ), array( (string) $row['canonical_name'] ) );
		}
		if ( $type !== $row['type'] ) {
			$data['type']    = $type;
			$changes['type'] = array( $row['type'], $type );
		}
		if ( array_key_exists( 'description', $fields ) ) {
			$description = trim( (string) $fields['description'] );
			if ( $description !== (string) $row['description'] ) {
				$data['description']    = '' === $description ? null : $description;
				$changes['description'] = array( (string) $row['description'], $description );
			}
		}
		if ( isset( $fields['status'] ) && (string) $fields['status'] !== $row['status'] ) {
			$status = (string) $fields['status'];
			if ( ! in_array( $status, self::EDITABLE_STATUS, true ) ) {
				throw new \InvalidArgumentException( 'Status erlaubt: ' . implode( ', ', self::EDITABLE_STATUS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( $profile && 'confirmed' !== $status ) {
				throw new \InvalidArgumentException( 'Einträge aus dem Unternehmensprofil bleiben bestätigt; entfernen lassen sie sich unter „Unternehmen“.' );
			}
			$decided            = in_array( $status, self::DECIDED, true );
			$data['status']     = $status;
			$data['decided_by'] = $decided ? $user_id : null;
			$data['decided_at'] = $decided ? $now : null;
			$changes['status']  = array( $row['status'], $status );
		}
		if ( array() !== $data ) {
			$data['updated_at'] = $now;
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		}

		if ( isset( $fields['aliases'] ) ) {
			$wanted = array();
			foreach ( (array) $fields['aliases'] as $alias ) {
				$alias = trim( (string) $alias );
				$key   = mb_substr( KeywordNormalizer::normalize( $alias ), 0, 255 );
				if ( '' !== $key && $key !== $normalized ) {
					$wanted[ $key ] = mb_substr( $alias, 0, 255 );
				}
			}
			$current = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT alias, normalized_alias FROM {$atab} WHERE entity_id = %d", $id ), ARRAY_A ) as $a ) {
				$current[ (string) $a['normalized_alias'] ] = (string) $a['alias'];
			}
			foreach ( array_diff_key( $current, $wanted ) as $key => $alias ) {
				$wpdb->delete(
					$atab,
					array(
						'entity_id'        => $id,
						'normalized_alias' => $key,
					)
				);
			}
			foreach ( array_diff_key( $wanted, $current ) as $key => $alias ) {
				$wpdb->insert(
					$atab,
					array(
						'entity_id'        => $id,
						'alias'            => $alias,
						'normalized_alias' => $key,
						'created_at'       => $now,
					)
				);
			}
			$removed = array_values( array_diff_key( $current, $wanted ) );
			$added   = array_values( array_diff_key( $wanted, $current ) );
			if ( array() !== $removed || array() !== $added ) {
				$changes['aliases'] = array(
					'removed' => $removed,
					'added'   => $added,
				);
			}
		}
		// phpcs:enable
		return $changes;
	}

	/**
	 * Löscht eine Entität mit Schreibweisen, Fundstellen, Beziehungen, Aussagen und Themenbezügen. Was in
	 * sie zusammengeführt war, wird mitgelöscht. Anders als „abgelehnt“ merkt sich das Plugin den Namen nicht;
	 * eine spätere Erkennung kann ihn wieder vorschlagen.
	 *
	 * @param int $id Entität.
	 * @return array{deleted: int, mentions: int} Anzahl gelöschter Entitäten (mit zusammengeführten) und Fundstellen.
	 * @throws \InvalidArgumentException Wenn die Entität fehlt oder aus dem Unternehmensprofil stammt.
	 */
	public function delete( int $id ): array {
		global $wpdb;
		$t = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, origin FROM {$t( 'entities' )} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new \InvalidArgumentException( 'Entität nicht gefunden.' );
		}
		if ( BusinessProfile::ORIGIN === $row['origin'] ) {
			throw new \InvalidArgumentException( 'Einträge aus dem Unternehmensprofil bitte unter „Unternehmen“ entfernen.' );
		}
		$ids      = array_merge( array( $id ), array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t( 'entities' )} WHERE merged_into = %d", $id ) ) ) );
		$in       = implode( ', ', array_map( 'intval', $ids ) );
		$mentions = (int) $wpdb->query( "DELETE FROM {$t( 'mentions' )} WHERE entity_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$t( 'entity_aliases' )} WHERE entity_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$t( 'relations' )} WHERE source_entity_id IN ({$in}) OR target_entity_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$t( 'claims' )} WHERE subject_type = 'entity' AND subject_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$t( 'evidence' )} WHERE target_type = 'entity' AND target_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$t( 'topic_entities' )} WHERE entity_id IN ({$in})" );
		$deleted = (int) $wpdb->query( "DELETE FROM {$t( 'entities' )} WHERE id IN ({$in})" );
		// phpcs:enable
		EntityHome::forget( array_map( 'intval', explode( ',', $in ) ) );
		return array(
			'deleted'  => $deleted,
			'mentions' => $mentions,
		);
	}

	/**
	 * Schreibweisen einer Entität.
	 *
	 * @param int $id Entität.
	 * @return string[]
	 */
	private function aliases( int $id ): array {
		global $wpdb;
		$atab = Schema::table( $wpdb->prefix, 'entity_aliases' );
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT alias FROM {$atab} WHERE entity_id = %d ORDER BY alias", $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Entitäten für die Anzeige. Zusammengeführte nur, wenn ausdrücklich nach diesem Status gefiltert wird.
	 *
	 * @param string     $type           Typ oder leer.
	 * @param string     $status         Status oder leer.
	 * @param int        $limit          Höchstzahl.
	 * @param float|null $min_confidence Schwächere Kandidaten ausblenden; bestätigte und solche ohne Wert bleiben.
	 * @param string     $search         Teil des Namens oder eines Alias.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( string $type = '', string $status = '', int $limit = 50, ?float $min_confidence = null, string $search = '' ): array {
		global $wpdb;
		$table  = Schema::table( $wpdb->prefix, 'entities' );
		$mtable = Schema::table( $wpdb->prefix, 'mentions' );
		$atable = Schema::table( $wpdb->prefix, 'entity_aliases' );
		$where  = 'WHERE 1=1';
		$params = array();
		if ( '' !== $type ) {
			$where   .= ' AND e.type = %s';
			$params[] = $type;
		}
		if ( '' !== $status ) {
			$where   .= ' AND e.status = %s';
			$params[] = $status;
		} else {
			$where .= " AND e.status <> 'merged'";
		}
		if ( null !== $min_confidence ) {
			$where   .= " AND ( e.status = 'confirmed' OR e.origin = 'business_profile' OR e.confidence IS NULL OR e.confidence >= %f )";
			$params[] = $min_confidence;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( KeywordNormalizer::normalize( $search ) ) . '%';
			$where   .= " AND ( e.normalized_name LIKE %s OR EXISTS ( SELECT 1 FROM {$atable} a WHERE a.entity_id = e.id AND a.normalized_alias LIKE %s ) )";
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = $limit;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.type, e.canonical_name, e.description, e.status, e.confidence, e.external_ids, e.origin, e.merged_into, e.decided_by, e.decided_at, e.updated_at,
				(SELECT COUNT(*) FROM {$mtable} m WHERE m.entity_id = e.id) AS mentions,
				(SELECT COUNT(DISTINCT m.object_type, m.object_id) FROM {$mtable} m WHERE m.entity_id = e.id) AS posts,
				(SELECT COUNT(*) FROM {$atable} a WHERE a.entity_id = e.id) AS aliases
				FROM {$table} e {$where}
				ORDER BY FIELD(e.status, 'unresolved', 'candidate', 'probable', 'confirmed', 'rejected', 'merged'), posts DESC, e.updated_at DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable
		return $rows;
	}

	/**
	 * Eine Entität mit Aliassen, Fundstellen (mit Beitragstitel), Beziehungen und zusammengeführten Namen.
	 *
	 * @param int $id Entität.
	 * @return array<string, mixed>|null
	 */
	public function detail( int $id ): ?array {
		global $wpdb;
		$t = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t( 'entities' )} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['properties']   = json_decode( (string) $row['properties'], true );
		$row['external_ids'] = json_decode( (string) $row['external_ids'], true );
		$row['aliases']      = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT alias FROM {$t( 'entity_aliases' )} WHERE entity_id = %d ORDER BY alias", $id ) ) );
		$mentions            = (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_type, object_id, location, excerpt FROM {$t( 'mentions' )} WHERE entity_id = %d ORDER BY object_id, id LIMIT 200", $id ), ARRAY_A );
		foreach ( $mentions as &$mention ) {
			$item             = in_array( $mention['object_type'], array( 'post', 'term' ), true ) ? IndexItem::load( (string) $mention['object_type'], (int) $mention['object_id'] ) : null;
			$mention['title'] = null !== $item ? $item->title() : '';
			$mention['url']   = null !== $item ? $item->url() : '';
		}
		unset( $mention );
		$row['mentions']    = $mentions;
		$row['relations']   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.relation_type, r.status, r.source_entity_id, s.canonical_name AS source_name, r.target_entity_id, g.canonical_name AS target_name
				FROM {$t( 'relations' )} r JOIN {$t( 'entities' )} s ON s.id = r.source_entity_id JOIN {$t( 'entities' )} g ON g.id = r.target_entity_id
				WHERE r.source_entity_id = %d OR r.target_entity_id = %d",
				$id,
				$id
			),
			ARRAY_A
		);
		$row['merged_from'] = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, canonical_name, type FROM {$t( 'entities' )} WHERE merged_into = %d", $id ), ARRAY_A );
		$row['claims']      = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, property, value, status FROM {$t( 'claims' )} WHERE subject_type = 'entity' AND subject_id = %d", $id ), ARRAY_A );
		// phpcs:enable
		// Hauptseite (1.2.0); stale: festgelegt, aber nicht mehr veröffentlicht.
		$home              = EntityHome::all()[ $id ] ?? null;
		$row['home']       = null === $home ? null : EntityHome::page( $home['post_id'] );
		$row['home_stale'] = null !== $home && null === $row['home'];
		return $row;
	}

	/**
	 * Legt eine Entität von Hand an oder findet die vorhandene.
	 *
	 * @param string $type   Typ.
	 * @param string $name   Name.
	 * @param string $status candidate oder confirmed.
	 * @param string $origin Herkunft.
	 * @param int    $user_id Benutzer (bei confirmed).
	 * @return array{id: int, created: bool}
	 * @throws \InvalidArgumentException Bei unbekanntem Typ oder leerem Namen.
	 */
	public function add( string $type, string $name, string $status = 'candidate', string $origin = 'manual', int $user_id = 0 ): array {
		global $wpdb;
		$name = trim( $name );
		if ( '' === $name || ! in_array( $type, EntityExtractor::TYPES, true ) ) {
			throw new \InvalidArgumentException( 'Name fehlt oder unbekannter Typ. Typen: ' . implode( ', ', EntityExtractor::TYPES ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$normalized = mb_substr( KeywordNormalizer::normalize( $name ), 0, 255 );
		$id         = $this->find( $type, $normalized );
		if ( $id > 0 ) {
			return array(
				'id'      => $id,
				'created' => false,
			);
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( $wpdb->prefix, 'entities' ),
			array(
				'uuid'            => wp_generate_uuid4(),
				'type'            => $type,
				'canonical_name'  => mb_substr( $name, 0, 255 ),
				'normalized_name' => $normalized,
				'status'          => 'confirmed' === $status ? 'confirmed' : 'candidate',
				'origin'          => $origin,
				'decided_by'      => 'confirmed' === $status ? $user_id : null,
				'decided_at'      => 'confirmed' === $status ? $now : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return array(
			'id'      => (int) $wpdb->insert_id,
			'created' => true,
		);
	}
}
