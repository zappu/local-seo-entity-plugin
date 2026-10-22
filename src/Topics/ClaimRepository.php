<?php
/**
 * Aussagen aus der Recherche mit Belegen.
 *
 * @package Lsec
 */

namespace Lsec\Topics;

use Lsec\Infrastructure\Database\Schema;

/**
 * Zugriff auf lsec_claims und lsec_evidence für geprüfte Tatsachen (Spezifikation 4.1, 8.4a).
 * Eine Aussage ist subject_type = entity, value = JSON {value, note}. Widersprüchliche Aussagen stehen als
 * eigene Zeilen nebeneinander. Eine vom Administrator bestätigte Aussage überschreibt die Prüfung nicht.
 */
final class ClaimRepository {

	/**
	 * Status, die der Administrator setzt.
	 */
	public const DECISIONS = array( 'confirmed', 'rejected' );

	/**
	 * Speichert eine geprüfte Tatsache mit Beleg.
	 *
	 * @param int         $entity_id Gegenstand.
	 * @param string      $property  Eigenschaft.
	 * @param string      $value     Wert.
	 * @param string      $status    verified, disputed oder rejected.
	 * @param string      $note      Hinweis der Prüfung.
	 * @param int         $source_id Quelle (0 = ohne).
	 * @param string      $excerpt   Zitat.
	 * @param string|null $quality   strong, medium oder weak.
	 * @return int Aussage.
	 */
	public function save( int $entity_id, string $property, string $value, string $status, string $note, int $source_id, string $excerpt, ?string $quality ): int {
		global $wpdb;
		$claims   = Schema::table( $wpdb->prefix, 'claims' );
		$evidence = Schema::table( $wpdb->prefix, 'evidence' );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$json     = (string) wp_json_encode(
			array(
				'value' => $value,
				'note'  => $note,
			),
			JSON_UNESCAPED_UNICODE
		);
		$property = mb_substr( sanitize_key( $property ), 0, 100 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, value, status FROM {$claims} WHERE subject_type = 'entity' AND subject_id = %d AND property = %s", $entity_id, $property ), ARRAY_A );
		$id   = 0;
		foreach ( $rows as $row ) {
			$stored = json_decode( (string) $row['value'], true );
			if ( is_array( $stored ) && FactChecker::fold( (string) ( $stored['value'] ?? '' ) ) === FactChecker::fold( $value ) ) {
				$id = (int) $row['id'];
				if ( 'confirmed' !== $row['status'] ) {
					// Ein besserer Befund ersetzt einen schwächeren, nie umgekehrt.
					$rank = array(
						'rejected' => 0,
						'disputed' => 1,
						'verified' => 2,
					);
					if ( ( $rank[ $status ] ?? 0 ) >= ( $rank[ (string) $row['status'] ] ?? 0 ) ) {
						$wpdb->update(
							$claims,
							array(
								'status'     => $status,
								'value'      => $json,
								'updated_at' => $now,
							),
							array( 'id' => $id )
						);
					}
				}
				break;
			}
		}
		if ( 0 === $id ) {
			$wpdb->insert(
				$claims,
				array(
					'subject_type' => 'entity',
					'subject_id'   => $entity_id,
					'property'     => $property,
					'value'        => $json,
					'status'       => $status,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		if ( $source_id > 0 && '' !== $excerpt ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$evidence} WHERE source_id = %d AND target_type = 'claim' AND target_id = %d", $source_id, $id ) );
			if ( 0 === $exists ) {
				$wpdb->insert(
					$evidence,
					array(
						'source_id'   => $source_id,
						'target_type' => 'claim',
						'target_id'   => $id,
						'excerpt'     => $excerpt,
						'quality'     => $quality ?? 'weak',
						'captured_at' => $now,
					)
				);
			}
		}
		// phpcs:enable
		return $id;
	}

	/**
	 * Aussagen mit Gegenstand, Quelle und Zitat.
	 *
	 * @param int[] $ids Aussagen.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_ids( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$t  = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		$in = implode( ',', $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			"SELECT c.id, c.property, c.value, c.status, e.id AS entity_id, e.canonical_name AS entity,
			(SELECT s.url FROM {$t( 'evidence' )} v JOIN {$t( 'sources' )} s ON s.id = v.source_id WHERE v.target_type = 'claim' AND v.target_id = c.id ORDER BY v.id DESC LIMIT 1) AS source_url,
			(SELECT s.title FROM {$t( 'evidence' )} v JOIN {$t( 'sources' )} s ON s.id = v.source_id WHERE v.target_type = 'claim' AND v.target_id = c.id ORDER BY v.id DESC LIMIT 1) AS source_title,
			(SELECT v.excerpt FROM {$t( 'evidence' )} v WHERE v.target_type = 'claim' AND v.target_id = c.id ORDER BY v.id DESC LIMIT 1) AS quote,
			(SELECT v.quality FROM {$t( 'evidence' )} v WHERE v.target_type = 'claim' AND v.target_id = c.id ORDER BY v.id DESC LIMIT 1) AS quality
			FROM {$t( 'claims' )} c LEFT JOIN {$t( 'entities' )} e ON e.id = c.subject_id
			WHERE c.subject_type = 'entity' AND c.id IN ({$in}) ORDER BY FIELD(c.status, 'confirmed', 'verified', 'disputed', 'rejected'), c.id",
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $rows as &$row ) {
			$value        = json_decode( (string) $row['value'], true );
			$row['id']    = (int) $row['id'];
			$row['value'] = is_array( $value ) ? (string) ( $value['value'] ?? '' ) : (string) $row['value'];
			$row['note']  = is_array( $value ) ? (string) ( $value['note'] ?? '' ) : '';
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Verwendbare Aussagen für einen Beitrag (Status verified oder confirmed): die Aussagen des Themas und
	 * weitere zu den genannten Entitäten, mit Typ der Entität, Zitat und Quelle (Spezifikation 7.3, gather_context).
	 *
	 * @param int[] $claim_ids  Aussagen des Themas.
	 * @param int[] $entity_ids Entitäten.
	 * @param int   $limit      Höchstzahl.
	 * @return array<int, array<string, mixed>>
	 */
	public function usable( array $claim_ids, array $entity_ids, int $limit = 40 ): array {
		global $wpdb;
		$claim_ids  = array_values( array_filter( array_map( 'intval', $claim_ids ) ) );
		$entity_ids = array_values( array_filter( array_map( 'intval', $entity_ids ) ) );
		if ( array() === $claim_ids && array() === $entity_ids ) {
			return array();
		}
		$t     = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		$where = array();
		if ( array() !== $claim_ids ) {
			$where[] = 'c.id IN (' . implode( ',', $claim_ids ) . ')';
		}
		if ( array() !== $entity_ids ) {
			$where[] = 'c.subject_id IN (' . implode( ',', $entity_ids ) . ')';
		}
		$first = array() === $claim_ids ? '0' : 'c.id IN (' . implode( ',', $claim_ids ) . ')';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.property, c.value, c.status, e.id AS entity_id, e.canonical_name AS entity, e.type AS entity_type,
				(SELECT s.url FROM {$t( 'evidence' )} v JOIN {$t( 'sources' )} s ON s.id = v.source_id WHERE v.target_type = 'claim' AND v.target_id = c.id ORDER BY v.id DESC LIMIT 1) AS source_url,
				(SELECT v.excerpt FROM {$t( 'evidence' )} v WHERE v.target_type = 'claim' AND v.target_id = c.id ORDER BY v.id DESC LIMIT 1) AS quote,
				(SELECT v.quality FROM {$t( 'evidence' )} v WHERE v.target_type = 'claim' AND v.target_id = c.id ORDER BY v.id DESC LIMIT 1) AS quality
				FROM {$t( 'claims' )} c JOIN {$t( 'entities' )} e ON e.id = c.subject_id
				WHERE c.subject_type = 'entity' AND c.status IN ('verified', 'confirmed') AND ( " . implode( ' OR ', $where ) . " )
				ORDER BY {$first} DESC, FIELD(c.status, 'confirmed', 'verified'), c.id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( $rows as $row ) {
			$value = json_decode( (string) $row['value'], true );
			$out[] = array(
				'id'          => (int) $row['id'],
				'entity_id'   => (int) $row['entity_id'],
				'entity'      => (string) $row['entity'],
				'entity_type' => (string) $row['entity_type'],
				'property'    => (string) $row['property'],
				'value'       => is_array( $value ) ? (string) ( $value['value'] ?? '' ) : (string) $row['value'],
				'status'      => (string) $row['status'],
				'quote'       => (string) $row['quote'],
				'source_url'  => (string) $row['source_url'],
				'quality'     => (string) $row['quality'],
			);
		}
		return $out;
	}

	/**
	 * Entscheidung des Administrators.
	 *
	 * @param int    $id       Aussage.
	 * @param string $decision confirmed oder rejected.
	 * @return bool Gefunden.
	 * @throws \InvalidArgumentException Bei unbekannter Entscheidung.
	 */
	public function decide( int $id, string $decision ): bool {
		global $wpdb;
		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			throw new \InvalidArgumentException( 'Entscheidung: confirmed oder rejected.' );
		}
		$claims = Schema::table( $wpdb->prefix, 'claims' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$claims} WHERE id = %d", $id ) ) ) {
			return false;
		}
		$wpdb->update(
			$claims,
			array(
				'status'     => $decision,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id )
		);
		// phpcs:enable
		return true;
	}
}
