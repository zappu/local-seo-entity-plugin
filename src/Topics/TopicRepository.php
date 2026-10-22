<?php
/**
 * Speichert Themenvorschläge.
 *
 * @package Lsec
 */

namespace Lsec\Topics;

use Lsec\Infrastructure\Database\Schema;

/**
 * Zugriff auf lsec_topics, lsec_topic_keywords und lsec_topic_entities (Spezifikation 4.3).
 * Status: proposed (Vorschlag), approved (freigegeben), rejected (abgelehnt), discarded (überschneidet sich
 * mit einer bestehenden Seite: „bestehende Seite verbessern“), später in_production und produced.
 */
final class TopicRepository {

	/**
	 * Status, die der Administrator setzt.
	 */
	public const DECISIONS = array( 'approved', 'rejected', 'proposed' );

	/**
	 * Speichert ein Thema mit Keywords und Entitäten.
	 *
	 * @param array<string, mixed> $topic  Thema (Felder wie in TopicResearchWorkflow::propose).
	 * @param int                  $run_id Ablauf.
	 * @return int ID.
	 */
	public function save( array $topic, int $run_id ): int {
		global $wpdb;
		$t   = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$t( 'topics' ),
			array(
				'title'           => mb_substr( (string) $topic['title'], 0, 255 ),
				'intent'          => (string) $topic['intent'],
				'format'          => (string) $topic['format'],
				'region'          => '' !== (string) $topic['region'] ? (string) $topic['region'] : null,
				'priority_score'  => (float) $topic['score'],
				'score_breakdown' => wp_json_encode( $topic['score_breakdown'], JSON_UNESCAPED_UNICODE ),
				'rationale'       => (string) $topic['rationale'],
				'research_basis'  => wp_json_encode( $topic['research_basis'], JSON_UNESCAPED_UNICODE ),
				'overlap'         => wp_json_encode( $topic['overlap'], JSON_UNESCAPED_UNICODE ),
				'status'          => (string) $topic['status'],
				'run_id'          => $run_id,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		foreach ( array( 'primary', 'secondary' ) as $role ) {
			foreach ( (array) $topic[ $role . '_keyword_ids' ] as $keyword_id ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t( 'topic_keywords' )} (topic_id, keyword_id, role) VALUES (%d, %d, %s)", $id, (int) $keyword_id, $role ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			foreach ( (array) $topic[ $role . '_entity_ids' ] as $entity_id ) {
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t( 'topic_entities' )} (topic_id, entity_id, role) VALUES (%d, %d, %s)", $id, (int) $entity_id, $role ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		// phpcs:enable
		return $id;
	}

	/**
	 * Themen für die Übersicht.
	 *
	 * @param string $status Status, „open“ (proposed) oder leer für alle außer discarded.
	 * @param int    $limit  Höchstzahl.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( string $status = '', int $limit = 100 ): array {
		global $wpdb;
		$t      = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		$where  = '' === $status ? "WHERE status <> 'discarded'" : 'WHERE status = %s';
		$params = '' === $status ? array( $limit ) : array( $status, $limit );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, (SELECT GROUP_CONCAT(k.keyword ORDER BY tk.role, k.keyword SEPARATOR ' | ') FROM {$t( 'topic_keywords' )} tk JOIN {$t( 'keywords' )} k ON k.id = tk.keyword_id WHERE tk.topic_id = p.id AND tk.role = 'primary') AS primary_keywords
				FROM {$t( 'topics' )} p {$where} ORDER BY FIELD(p.status, 'proposed', 'approved', 'in_production', 'produced', 'rejected', 'discarded'), p.priority_score DESC, p.id DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map( array( self::class, 'decode' ), $rows );
	}

	/**
	 * Ein Thema mit Keywords, Entitäten und geprüften Tatsachen.
	 *
	 * @param int $id Thema.
	 * @return array<string, mixed>|null
	 */
	public function detail( int $id ): ?array {
		global $wpdb;
		$t = static fn( string $name ): string => Schema::table( $wpdb->prefix, $name );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t( 'topics' )} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row             = self::decode( $row );
		$row['keywords'] = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.id, k.keyword, k.exclusion, tk.role,
				(SELECT m.search_volume FROM {$t( 'keyword_metrics' )} m WHERE m.keyword_id = k.id AND m.search_volume IS NOT NULL ORDER BY m.id DESC LIMIT 1) AS volume,
				(SELECT m.impressions FROM {$t( 'keyword_metrics' )} m WHERE m.keyword_id = k.id AND m.source = 'gsc' ORDER BY m.period_end DESC, DATEDIFF(m.period_end, m.period_start) ASC LIMIT 1) AS impressions
				FROM {$t( 'topic_keywords' )} tk JOIN {$t( 'keywords' )} k ON k.id = tk.keyword_id WHERE tk.topic_id = %d ORDER BY tk.role, k.keyword",
				$id
			),
			ARRAY_A
		);
		$row['entities'] = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT e.id, e.canonical_name AS name, e.type, e.status, te.role FROM {$t( 'topic_entities' )} te JOIN {$t( 'entities' )} e ON e.id = te.entity_id WHERE te.topic_id = %d ORDER BY te.role, e.canonical_name", $id ),
			ARRAY_A
		);
		// phpcs:enable
		$row['facts'] = ( new ClaimRepository() )->for_ids( (array) ( $row['research_basis']['claim_ids'] ?? array() ) );
		return $row;
	}

	/**
	 * Entscheidung des Administrators.
	 *
	 * @param int    $id       Thema.
	 * @param string $decision approved, rejected oder proposed (zurücksetzen).
	 * @param int    $user_id  Benutzer.
	 * @return bool Gefunden.
	 * @throws \InvalidArgumentException Bei unbekannter Entscheidung.
	 */
	public function decide( int $id, string $decision, int $user_id ): bool {
		global $wpdb;
		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			throw new \InvalidArgumentException( 'Entscheidung: approved, rejected oder proposed.' );
		}
		$table = Schema::table( $wpdb->prefix, 'topics' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) ) ) {
			return false;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update(
			$table,
			array(
				'status'     => $decision,
				'decided_by' => 'proposed' === $decision ? null : $user_id,
				'decided_at' => 'proposed' === $decision ? null : $now,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);
		// phpcs:enable
		return true;
	}

	/**
	 * Status der Produktion setzen (in_production, produced, zurück auf approved) und den Beitrag merken.
	 *
	 * @param int      $id      Thema.
	 * @param string   $status  Status.
	 * @param int|null $post_id Beitrag oder null (unverändert).
	 */
	public function set_production( int $id, string $status, ?int $post_id = null ): void {
		global $wpdb;
		$fields = array(
			'status'     => $status,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( null !== $post_id ) {
			$fields['post_id'] = $post_id;
		}
		$wpdb->update( Schema::table( $wpdb->prefix, 'topics' ), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Freigegebene Themen ohne Beitrag, nach Priorität.
	 *
	 * @param int $limit Höchstzahl.
	 * @return int[]
	 */
	public function approved_ids( int $limit = 10 ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'topics' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'approved' ORDER BY priority_score DESC, id ASC LIMIT %d", $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Anzahl je Status.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'topics' );
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * JSON-Felder entpacken und Zahlen wandeln.
	 *
	 * @param array<string, mixed> $row Zeile.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		foreach ( array( 'score_breakdown', 'research_basis', 'overlap' ) as $field ) {
			$value         = json_decode( (string) ( $row[ $field ] ?? '' ), true );
			$row[ $field ] = is_array( $value ) ? $value : array();
		}
		$row['id']             = (int) $row['id'];
		$row['priority_score'] = null === $row['priority_score'] ? null : (float) $row['priority_score'];
		return $row;
	}
}
