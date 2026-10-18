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
	 * @param string                                                                                           $source  Quelle, z. B. textfocus.
	 * @param array<string, array{keyword: string, volume: int|null, cpc: float|null, difficulty: float|null}> $entries Kennzahlen je normalisiertem Keyword.
	 * @param string                                                                                           $locale  Sprache.
	 * @param string                                                                                           $region  Region.
	 * @return int Anzahl gespeicherter Kennzahlen.
	 */
	public function save_metrics( string $source, array $entries, string $locale, string $region = '' ): int {
		global $wpdb;
		$now   = gmdate( 'Y-m-d H:i:s' );
		$saved = 0;
		foreach ( $entries as $normalized => $entry ) {
			$found = $this->upsert( $entry['keyword'], (string) $normalized, $locale, $region, null, $now );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				Schema::table( $wpdb->prefix, 'keyword_metrics' ),
				array(
					'keyword_id'    => $found['id'],
					'source'        => $source,
					'search_volume' => $entry['volume'],
					'difficulty'    => $entry['difficulty'],
					'cpc'           => $entry['cpc'],
					'created_at'    => $now,
				)
			);
			++$saved;
		}
		return $saved;
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
		$sql = "SELECT k.id, k.keyword, k.locale, k.region, k.intent, m.source, m.search_volume, m.difficulty, m.cpc, m.trend, m.created_at
			FROM {$k} k
			JOIN {$m} m ON m.keyword_id = k.id
			JOIN ( SELECT keyword_id, source, MAX(id) AS id FROM {$m} GROUP BY keyword_id, source ) latest ON latest.id = m.id
			{$where}
			ORDER BY m.search_volume IS NULL, m.search_volume DESC, k.normalized
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
	 * Anzahl der Keywords.
	 */
	public function count(): int {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'keywords' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
