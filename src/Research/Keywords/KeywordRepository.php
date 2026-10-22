<?php
/**
 * Speichert Keywords und Kennzahlen.
 *
 * @package Lsec
 */

namespace Lsec\Research\Keywords;

use Lsec\Infrastructure\Database\Schema;
use Lsec\Research\Import\ImportResult;

/**
 * Zugriff auf lsec_keywords, lsec_keyword_metrics und lsec_imports.
 * Kennzahlen werden je Quelle und Import als eigener Datensatz gespeichert, nie überschrieben.
 */
final class KeywordRepository {

	/**
	 * Speichert ein Importergebnis.
	 *
	 * @param ImportResult $result  Ergebnis.
	 * @param string       $locale  Sprache und Land, z. B. de_DE.
	 * @param string       $region  Ort oder leer.
	 * @param int|null     $user_id Ausgeführt von.
	 * @return array{import_id: int, created: int, updated: int}
	 * @throws \RuntimeException Wenn der Import nicht angelegt werden kann.
	 */
	public function save_import( ImportResult $result, string $locale, string $region = '', ?int $user_id = null ): array {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( $wpdb->prefix, 'imports' ),
			array(
				'source'         => $result->profile->source,
				'filename'       => mb_substr( $result->filename, 0, 255 ),
				'rows_total'     => $result->rows_total,
				'rows_imported'  => 0,
				'rows_skipped'   => $result->rows_skipped,
				'column_mapping' => wp_json_encode(
					array(
						'profile'   => $result->profile->id,
						'mapping'   => $result->profile->mapping,
						'encoding'  => $result->encoding,
						'delimiter' => $result->delimiter,
					),
					JSON_UNESCAPED_UNICODE
				),
				'notes'          => wp_json_encode( $result->notes, JSON_UNESCAPED_UNICODE ),
				'created_by'     => $user_id,
				'created_at'     => $now,
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Import konnte nicht angelegt werden: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$import_id = (int) $wpdb->insert_id;
		$created   = 0;
		$updated   = 0;
		$imported  = 0;

		foreach ( $result->keywords as $entry ) {
			$found = $this->upsert( $entry['keyword'], $entry['normalized'], $locale, $region, $entry['intent'], $now );
			$found['created'] ? ++$created : ++$updated;

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				Schema::table( $wpdb->prefix, 'keyword_metrics' ),
				array(
					'keyword_id'      => $found['id'],
					'source'          => $result->profile->source,
					'search_volume'   => $entry['volume'],
					'difficulty'      => $entry['difficulty'],
					'cpc'             => $entry['cpc'],
					'competition'     => $entry['competition'] ?? null,
					'trend'           => null === $entry['trend'] ? null : wp_json_encode( $entry['trend'] ),
					'import_batch_id' => $import_id,
					'created_at'      => $now,
				)
			);
			++$imported;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( $wpdb->prefix, 'imports' ),
			array( 'rows_imported' => $imported ),
			array( 'id' => $import_id )
		);

		return array(
			'import_id' => $import_id,
			'created'   => $created,
			'updated'   => $updated,
		);
	}

	/**
	 * Speichert Kennzahlen eines Datenanbieters, z. B. aus tf_keyword.
	 *
	 * @param string                                                                                                                                           $source  Quelle, z. B. textfocus.
	 * @param array<string, array{keyword: string, volume: int|null, cpc: float|null, difficulty: float|null, competition?: float|null, intent?: string|null}> $entries Kennzahlen je normalisiertem Keyword.
	 * @param string                                                                                                                                           $locale  Sprache.
	 * @param string                                                                                                                                           $region  Region.
	 * @return int Anzahl gespeicherter Kennzahlen.
	 */
	public function save_metrics( string $source, array $entries, string $locale, string $region = '' ): int {
		global $wpdb;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$saved = 0;
		foreach ( $entries as $normalized => $entry ) {
			$found = $this->upsert( $entry['keyword'], (string) $normalized, $locale, $region, $entry['intent'] ?? null, $now );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				Schema::table( $wpdb->prefix, 'keyword_metrics' ),
				array(
					'keyword_id'    => $found['id'],
					'source'        => $source,
					'search_volume' => $entry['volume'],
					'difficulty'    => $entry['difficulty'],
					'cpc'           => $entry['cpc'],
					'competition'   => $entry['competition'] ?? null,
					'created_at'    => $now,
				)
			);
			++$saved;
		}
		return $saved;
	}

	/**
	 * Speichert Suchanfragen aus der Search Console als Quelle gsc (M9).
	 * Ein erneuter Abruf für denselben Zeitraum ersetzt die vorherigen Werte.
	 *
	 * @param string                                                                                                                      $property Property.
	 * @param array{start: string, end: string}                                                                                           $period   Zeitraum.
	 * @param array<string, array{keyword: string, clicks: int, impressions: int, ctr: float, position: float, page: string, pages: int}> $rows     Zusammengefasste Zeilen je normalisierter Suchanfrage.
	 * @param string                                                                                                                      $locale   Sprache.
	 * @param int|null                                                                                                                    $user_id  Ausgeführt von.
	 * @return array{import_id: int, created: int, updated: int}
	 */
	public function save_gsc( string $property, array $period, array $rows, string $locale, ?int $user_id = null ): array {
		global $wpdb;
		$now     = gmdate( 'Y-m-d H:i:s' );
		$metrics = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		$k       = Schema::table( $wpdb->prefix, 'keywords' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM {$metrics} m JOIN {$k} k ON k.id = m.keyword_id WHERE m.source = 'gsc' AND m.period_start = %s AND m.period_end = %s AND k.locale = %s",
				$period['start'],
				$period['end'],
				$locale
			)
		);
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'imports' ),
			array(
				'source'         => 'gsc',
				'filename'       => mb_substr( $property, 0, 255 ),
				'rows_total'     => count( $rows ),
				'rows_imported'  => count( $rows ),
				'rows_skipped'   => 0,
				'column_mapping' => wp_json_encode( $period ),
				'created_by'     => $user_id,
				'created_at'     => $now,
			)
		);
		$import_id = (int) $wpdb->insert_id;
		$created   = 0;
		$updated   = 0;
		foreach ( $rows as $normalized => $row ) {
			$found = $this->upsert( $row['keyword'], (string) $normalized, $locale, '', null, $now );
			$found['created'] ? ++$created : ++$updated;
			$wpdb->insert(
				$metrics,
				array(
					'keyword_id'      => $found['id'],
					'source'          => 'gsc',
					'period_start'    => $period['start'],
					'period_end'      => $period['end'],
					'impressions'     => $row['impressions'],
					'clicks'          => $row['clicks'],
					'ctr'             => $row['ctr'],
					'position'        => $row['position'],
					'page_url'        => '' !== $row['page'] ? $row['page'] : null,
					'import_batch_id' => $import_id,
					'created_at'      => $now,
				)
			);
		}
		// phpcs:enable
		return array(
			'import_id' => $import_id,
			'created'   => $created,
			'updated'   => $updated,
		);
	}

	/**
	 * Normalisierte Keywords, für die eine Quelle in den letzten Tagen schon Kennzahlen geliefert hat.
	 *
	 * @param string   $source Quelle.
	 * @param string[] $keywords Keywords.
	 * @param string   $locale Sprache.
	 * @param int      $days   Zeitraum.
	 * @return string[]
	 */
	public function fresh( string $source, array $keywords, string $locale, int $days ): array {
		global $wpdb;
		if ( array() === $keywords ) {
			return array();
		}
		$k            = Schema::table( $wpdb->prefix, 'keywords' );
		$m            = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		$normalized   = array_map( array( KeywordNormalizer::class, 'normalize' ), $keywords );
		$placeholders = implode( ',', array_fill( 0, count( $normalized ), '%s' ) );
		$sql          = "SELECT DISTINCT k.normalized FROM {$k} k JOIN {$m} m ON m.keyword_id = k.id WHERE m.source = %s AND k.locale = %s AND m.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND k.normalized IN ({$placeholders})";
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $source, $locale, $days ), $normalized ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Legt ein Keyword an oder aktualisiert Zeitstempel und Intention.
	 *
	 * @param string      $keyword    Schreibweise aus der Quelle.
	 * @param string      $normalized Normalisierte Form.
	 * @param string      $locale     Sprache.
	 * @param string      $region     Region.
	 * @param string|null $intent     Intention.
	 * @param string      $now        Zeitpunkt.
	 * @return array{id: int, created: bool}
	 */
	public function upsert( string $keyword, string $normalized, string $locale, string $region, ?string $intent, string $now ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'keywords' );
		$id    = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE normalized = %s AND locale = %s AND region = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				mb_substr( $normalized, 0, 191 ),
				$locale,
				$region
			)
		);
		if ( null !== $id ) {
			$data = array( 'updated_at' => $now );
			if ( null !== $intent ) {
				$data['intent'] = $intent;
			}
			$wpdb->update( $table, $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array(
				'id'      => (int) $id,
				'created' => false,
			);
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'keyword'       => mb_substr( $keyword, 0, 255 ),
				'normalized'    => mb_substr( $normalized, 0, 191 ),
				'locale'        => $locale,
				'region'        => $region,
				'intent'        => $intent,
				'first_seen_at' => $now,
				'updated_at'    => $now,
			)
		);
		return array(
			'id'      => (int) $wpdb->insert_id,
			'created' => true,
		);
	}

	/**
	 * Keywords mit der jeweils neuesten Kennzahl je Quelle.
	 *
	 * @param string $search Teilwort oder leer.
	 * @param string $source Quelle oder leer.
	 * @param int    $limit  Höchstzahl.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_with_metrics( string $search = '', string $source = '', int $limit = 100 ): array {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		$m = Schema::table( $wpdb->prefix, 'keyword_metrics' );

		$where  = 'WHERE 1=1';
		$params = array();
		if ( '' !== $search ) {
			$where   .= ' AND k.normalized LIKE %s';
			$params[] = '%' . $wpdb->esc_like( KeywordNormalizer::normalize( $search ) ) . '%';
		}
		if ( '' !== $source ) {
			$where   .= ' AND m.source = %s';
			$params[] = $source;
		}
		$params[] = $limit;

		// Neueste Kennzahl je Keyword und Quelle.
		$sql = "SELECT k.id, k.keyword, k.locale, k.region, k.intent, m.source, m.search_volume, m.difficulty, m.cpc, m.competition, m.trend, m.impressions, m.clicks, m.ctr, m.position, m.page_url, m.period_start, m.period_end, m.created_at
			FROM {$k} k
			JOIN {$m} m ON m.keyword_id = k.id
			JOIN ( SELECT keyword_id, source, MAX(id) AS id FROM {$m} GROUP BY keyword_id, source ) latest ON latest.id = m.id
			{$where}
			ORDER BY COALESCE(m.search_volume, m.impressions) IS NULL, COALESCE(m.search_volume, m.impressions) DESC, m.clicks DESC, k.normalized
			LIMIT %d";

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Letzte Importe.
	 *
	 * @param int $limit Höchstzahl.
	 * @return array<int, array<string, mixed>>
	 */
	public function imports( int $limit = 20 ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'imports' );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Bekannte Begriffe für die Tippfehlerprüfung: Keywords, Entitäten (außer abgelehnten) und deren Aliasse.
	 *
	 * @return string[]
	 */
	public function known_terms(): array {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		$e = Schema::table( $wpdb->prefix, 'entities' );
		$a = Schema::table( $wpdb->prefix, 'entity_aliases' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$terms = array_merge(
			(array) $wpdb->get_col( "SELECT keyword FROM {$k}" ),
			(array) $wpdb->get_col( "SELECT canonical_name FROM {$e} WHERE status <> 'rejected'" ),
			(array) $wpdb->get_col( "SELECT a.alias FROM {$a} a JOIN {$e} e ON e.id = a.entity_id WHERE e.status <> 'rejected'" )
		);
		// phpcs:enable
		return array_map( 'strval', $terms );
	}

	/**
	 * Anzahl der Keywords.
	 */
	public function count(): int {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'keywords' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Ausschlussgründe (Spezifikation 6.4).
	 */
	public const EXCLUSIONS = array( 'competitor', 'brand', 'foreign_place', 'off_topic', 'manual' );

	/**
	 * Keywords, die noch nicht auf Orte und Namen geprüft wurden (ohne Entscheidung des Administrators).
	 *
	 * @param int   $limit Höchstzahl.
	 * @param int[] $only  Nur diese IDs (Prüfskript); leer = alle.
	 * @return array<int, array{id: int, keyword: string, normalized: string}>
	 */
	public function pending_analysis( int $limit, array $only = array() ): array {
		global $wpdb;
		$k    = Schema::table( $wpdb->prefix, 'keywords' );
		$and  = self::only( $only );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, keyword, normalized FROM {$k} WHERE analyzed_at IS NULL AND exclusion_by IS NULL {$and} ORDER BY id LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static fn( array $r ): array => array(
				'id'         => (int) $r['id'],
				'keyword'    => (string) $r['keyword'],
				'normalized' => (string) $r['normalized'],
			),
			$rows
		);
	}

	/**
	 * Einschränkung auf IDs als SQL (nur Ganzzahlen).
	 *
	 * @param int[] $ids IDs.
	 */
	private static function only( array $ids ): string {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		return array() === $ids ? '' : 'AND id IN (' . implode( ',', $ids ) . ')';
	}

	/**
	 * Anzahl ungeprüfter Keywords.
	 */
	public function count_pending_analysis(): int {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$k} WHERE analyzed_at IS NULL AND exclusion_by IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Speichert das Ergebnis der Prüfung; Keywords ohne Treffer erhalten eine leere Analyse.
	 *
	 * @param array<int, string>                                      $ids   Keyword-ID → normalisiertes Keyword.
	 * @param array<string, array{places: string[], names: string[]}> $found Treffer je normalisiertem Keyword.
	 */
	public function save_analysis( array $ids, array $found ): void {
		global $wpdb;
		$k   = Schema::table( $wpdb->prefix, 'keywords' );
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $ids as $id => $normalized ) {
			$data = $found[ $normalized ] ?? array(
				'places' => array(),
				'names'  => array(),
			);
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$k,
				array(
					'analysis'    => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
					'analyzed_at' => $now,
				),
				array( 'id' => (int) $id )
			);
		}
	}

	/**
	 * Keywords für den Filter, seitenweise nach ID (ohne Entscheidung des Administrators).
	 *
	 * @param int   $after_id Letzte bearbeitete ID.
	 * @param int   $limit    Höchstzahl.
	 * @param int[] $only     Nur diese IDs (Prüfskript); leer = alle.
	 * @return array<int, array{id: int, keyword: string, analysis: array<string, mixed>, exclusion: string|null, detail: string|null}>
	 */
	public function for_filter( int $after_id, int $limit, array $only = array() ): array {
		global $wpdb;
		$k    = Schema::table( $wpdb->prefix, 'keywords' );
		$and  = self::only( $only );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, keyword, analysis, exclusion, exclusion_detail FROM {$k} WHERE id > %d AND exclusion_by IS NULL {$and} ORDER BY id LIMIT %d", $after_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $row ) {
			$analysis = json_decode( (string) $row['analysis'], true );
			$out[]    = array(
				'id'        => (int) $row['id'],
				'keyword'   => (string) $row['keyword'],
				'analysis'  => is_array( $analysis ) ? $analysis : array(),
				'exclusion' => null === $row['exclusion'] ? null : (string) $row['exclusion'],
				'detail'    => null === $row['exclusion_detail'] ? null : (string) $row['exclusion_detail'],
			);
		}
		return $out;
	}

	/**
	 * Setzt einen automatisch ermittelten Ausschluss; Entscheidungen des Administrators bleiben unberührt.
	 *
	 * @param int         $id        Keyword.
	 * @param string|null $exclusion Grund oder null.
	 * @param string|null $detail    Name oder Ort.
	 */
	public function set_auto_exclusion( int $id, ?string $exclusion, ?string $detail ): void {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $exclusion ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$k} SET exclusion = NULL, exclusion_detail = NULL WHERE id = %d AND exclusion_by IS NULL", $id ) );
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$k} SET exclusion = %s, exclusion_detail = %s WHERE id = %d AND exclusion_by IS NULL", $exclusion, mb_substr( (string) $detail, 0, 255 ), $id ) );
		// phpcs:enable
	}

	/**
	 * Entscheidung des Administrators: ausschließen (manual), aufnehmen (null) oder zurück an den Filter (auto).
	 *
	 * @param int    $id       Keyword.
	 * @param string $decision manual, include oder auto.
	 * @param int    $user_id  Benutzer.
	 * @return bool Gefunden.
	 * @throws \InvalidArgumentException Bei unbekannter Entscheidung.
	 */
	public function decide_exclusion( int $id, string $decision, int $user_id ): bool {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$k} WHERE id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false;
		}
		$data = match ( $decision ) {
			'manual'  => array(
				'exclusion'        => 'manual',
				'exclusion_detail' => null,
				'exclusion_by'     => max( 1, $user_id ),
			),
			'include' => array(
				'exclusion'        => null,
				'exclusion_detail' => null,
				'exclusion_by'     => max( 1, $user_id ),
			),
			'auto'    => array(
				'exclusion'        => null,
				'exclusion_detail' => null,
				'exclusion_by'     => null,
			),
			default   => throw new \InvalidArgumentException( 'Entscheidung: manual, include oder auto.' ),
		};
		$wpdb->update( $k, $data + array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return true;
	}

	/**
	 * Keywords ohne Suchvolumen, die Textfocus in den letzten Tagen noch nicht abgefragt hat, ohne Ausschluss.
	 * Zuerst die mit den meisten Impressionen.
	 *
	 * @param int $limit Höchstzahl.
	 * @param int $days  Textfocus-Abfragen jünger als diese Tage zählen als erledigt.
	 * @return array<int, string> ID → Keyword.
	 */
	public function without_volume( int $limit, int $days = 30 ): array {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		$m = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.id, k.keyword FROM {$k} k
				WHERE k.exclusion IS NULL
				AND NOT EXISTS ( SELECT 1 FROM {$m} v WHERE v.keyword_id = k.id AND v.search_volume IS NOT NULL )
				AND NOT EXISTS ( SELECT 1 FROM {$m} t WHERE t.keyword_id = k.id AND t.source = 'textfocus' AND t.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) )
				ORDER BY ( SELECT MAX(g.impressions) FROM {$m} g WHERE g.keyword_id = k.id AND g.source = 'gsc' ) DESC, k.id
				LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['keyword'];
		}
		return $out;
	}

	/**
	 * Speichert Kennzahlen direkt zu Keyword-IDs (z. B. Textfocus-Volumina im Ablauf). Ein Eintrag ohne
	 * Werte hält fest, dass die Quelle gefragt wurde und nichts wusste.
	 *
	 * @param string                                                                                                 $source  Quelle.
	 * @param array<int, array{volume: int|null, cpc: float|null, difficulty: float|null, competition?: float|null}> $entries Kennzahlen je Keyword-ID.
	 * @return int Gespeichert.
	 */
	public function save_metrics_for_ids( string $source, array $entries ): int {
		global $wpdb;
		$m     = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$saved = 0;
		foreach ( $entries as $id => $entry ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$m,
				array(
					'keyword_id'    => (int) $id,
					'source'        => $source,
					'search_volume' => $entry['volume'],
					'difficulty'    => $entry['difficulty'],
					'cpc'           => $entry['cpc'],
					'competition'   => $entry['competition'] ?? null,
					'created_at'    => $now,
				)
			);
			++$saved;
		}
		return $saved;
	}

	/**
	 * Tabelle für die Keywords-Seite: je Keyword das neueste Suchvolumen (außer Search Console) mit
	 * Quelle, Wettbewerb und CPC sowie die neuesten Werte der Search Console (kürzester Zeitraum zuerst).
	 *
	 * @param array{search?: string, source?: string, status?: string, sort?: string, limit?: int, offset?: int} $args Filter.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function table( array $args ): array {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		$m = Schema::table( $wpdb->prefix, 'keyword_metrics' );

		$where  = 'WHERE 1=1';
		$params = array();
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$where   .= ' AND k.normalized LIKE %s';
			$params[] = '%' . $wpdb->esc_like( KeywordNormalizer::normalize( $search ) ) . '%';
		}
		$source = (string) ( $args['source'] ?? '' );
		if ( 'import' === $source ) {
			$where .= " AND EXISTS ( SELECT 1 FROM {$m} s WHERE s.keyword_id = k.id AND ( s.source LIKE 'csv:%' OR s.source LIKE 'xlsx:%' ) )";
		} elseif ( '' !== $source ) {
			$where   .= " AND EXISTS ( SELECT 1 FROM {$m} s WHERE s.keyword_id = k.id AND s.source = %s )";
			$params[] = $source;
		}
		$status = (string) ( $args['status'] ?? 'active' );
		if ( 'active' === $status ) {
			$where .= ' AND k.exclusion IS NULL';
		} elseif ( 'excluded' === $status ) {
			$where .= ' AND k.exclusion IS NOT NULL';
		} elseif ( in_array( $status, self::EXCLUSIONS, true ) ) {
			$where   .= ' AND k.exclusion = %s';
			$params[] = $status;
		}

		$order = match ( (string) ( $args['sort'] ?? 'volume' ) ) {
			'impressions' => 'g.impressions IS NULL, g.impressions DESC',
			'clicks'      => 'g.clicks IS NULL, g.clicks DESC, g.impressions DESC',
			'position'    => 'g.position IS NULL, g.position ASC',
			'keyword'     => 'k.normalized ASC',
			'new'         => 'k.id DESC',
			default       => 'v.search_volume IS NULL, v.search_volume DESC, g.impressions DESC',
		};
		$limit  = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$joins = "LEFT JOIN {$m} v ON v.id = ( SELECT x.id FROM {$m} x WHERE x.keyword_id = k.id AND x.source <> 'gsc' AND x.search_volume IS NOT NULL ORDER BY x.id DESC LIMIT 1 )
			LEFT JOIN {$m} g ON g.id = ( SELECT y.id FROM {$m} y WHERE y.keyword_id = k.id AND y.source = 'gsc' ORDER BY y.period_end DESC, DATEDIFF( y.period_end, y.period_start ) ASC, y.id DESC LIMIT 1 )";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total_sql = "SELECT COUNT(*) FROM {$k} k {$where}";
		$total     = (int) ( array() === $params ? $wpdb->get_var( $total_sql ) : $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) );
		$rows      = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.id, k.keyword, k.intent, k.exclusion, k.exclusion_detail, k.exclusion_by, k.analysis, k.first_seen_at,
				v.search_volume, v.source AS volume_source, v.competition, v.cpc, v.difficulty,
				g.impressions, g.clicks, g.ctr, g.position, g.page_url, g.period_start, g.period_end
				FROM {$k} k {$joins} {$where}
				ORDER BY {$order}, k.id
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( $rows as &$row ) {
			$analysis        = json_decode( (string) $row['analysis'], true );
			$row['analysis'] = is_array( $analysis ) ? $analysis : null;
			foreach ( array( 'id', 'search_volume', 'impressions', 'clicks', 'exclusion_by' ) as $int ) {
				$row[ $int ] = null === $row[ $int ] ? null : (int) $row[ $int ];
			}
			foreach ( array( 'competition', 'cpc', 'difficulty', 'ctr', 'position' ) as $float ) {
				$row[ $float ] = null === $row[ $float ] ? null : (float) $row[ $float ];
			}
		}
		unset( $row );
		return array(
			'rows'  => array_values( $rows ),
			'total' => $total,
		);
	}

	/**
	 * Überblick: Anzahl gesamt, aktiv, je Ausschlussgrund, mit Volumen, aus der Search Console, ungeprüft.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		global $wpdb;
		$k = Schema::table( $wpdb->prefix, 'keywords' );
		$m = Schema::table( $wpdb->prefix, 'keyword_metrics' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by = array();
		foreach ( (array) $wpdb->get_results( "SELECT COALESCE(exclusion, 'active') AS s, COUNT(*) AS n FROM {$k} GROUP BY s", ARRAY_A ) as $row ) {
			$by[ (string) $row['s'] ] = (int) $row['n'];
		}
		$out = array(
			'total'       => array_sum( $by ),
			'by_status'   => $by,
			'with_volume' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT keyword_id) FROM {$m} WHERE search_volume IS NOT NULL" ),
			'from_gsc'    => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT keyword_id) FROM {$m} WHERE source = 'gsc'" ),
			'unanalyzed'  => $this->count_pending_analysis(),
			'sources'     => array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT source FROM {$m} ORDER BY source" ) ),
		);
		// phpcs:enable
		return $out;
	}
}
