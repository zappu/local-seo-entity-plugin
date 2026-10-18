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
				);
			}
		}
		return $out;
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
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( $wpdb->prefix, 'mentions' ),
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
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
				(SELECT COUNT(DISTINCT m.object_id) FROM {$mtable} m WHERE m.entity_id = e.id AND m.object_type = 'post') AS posts,
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
			$post             = 'post' === $mention['object_type'] ? get_post( (int) $mention['object_id'] ) : null;
			$mention['title'] = $post instanceof \WP_Post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) : '';
			$mention['url']   = $post instanceof \WP_Post ? (string) get_permalink( $post ) : '';
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
